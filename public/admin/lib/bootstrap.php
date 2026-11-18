<?php
require __DIR__ . '/config.php';
require __DIR__ . '/interest.php';

// ---------- Security headers ----------
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store, no-cache, must-revalidate, private');
header("Content-Security-Policy: default-src 'self'; style-src 'self' https://fonts.googleapis.com; font-src https://fonts.gstatic.com; img-src 'self' data:; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");

function lc_is_https() {
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') return true;
    if (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') return true;
    return ($_SERVER['SERVER_PORT'] ?? '') == 443;
}
function lc_is_local() {
    $h = strtolower(preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? ''));
    return in_array($h, ['localhost', '127.0.0.1'], true);
}

if (FORCE_HTTPS && !lc_is_https() && !lc_is_local() && PHP_SAPI !== 'cli') {
    header('Location: https://' . ($_SERVER['HTTP_HOST'] ?? '') . ($_SERVER['REQUEST_URI'] ?? '/admin/'), true, 301);
    exit;
}

// ---------- Storage ----------
function lc_dir() {
    static $dir = null;
    if ($dir === null) {
        $dir = lc_data_dir();
        if (!is_dir($dir)) @mkdir($dir, 0700, true);
    }
    return $dir;
}

function db() {
    static $pdo = null;
    if ($pdo) return $pdo;
    if (!class_exists('PDO') || !in_array('sqlite', PDO::getAvailableDrivers(), true)) {
        lc_fail('This server\'s PHP is missing SQLite (pdo_sqlite). In cPanel, open "Select PHP Version" and turn on pdo_sqlite.');
    }
    $pdo = new PDO('sqlite:' . lc_dir() . '/loans.sqlite');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('CREATE TABLE IF NOT EXISTS loans (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        borrower TEXT NOT NULL,
        contact TEXT NOT NULL DEFAULT \'\',
        collateral TEXT NOT NULL DEFAULT \'\',
        principal_cents INTEGER NOT NULL,
        rate REAL NOT NULL,
        default_rate REAL,
        method TEXT NOT NULL DEFAULT \'simple\',
        daycount TEXT NOT NULL DEFAULT \'act365\',
        start_date TEXT NOT NULL,
        maturity_date TEXT,
        status TEXT NOT NULL DEFAULT \'active\',
        closed_date TEXT,
        notes TEXT NOT NULL DEFAULT \'\',
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL
    )');
    $pdo->exec('CREATE TABLE IF NOT EXISTS entries (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        loan_id INTEGER NOT NULL REFERENCES loans(id) ON DELETE CASCADE,
        entry_date TEXT NOT NULL,
        type TEXT NOT NULL,
        amount_cents INTEGER NOT NULL,
        note TEXT NOT NULL DEFAULT \'\',
        created_at TEXT NOT NULL
    )');
    $pdo->exec('CREATE INDEX IF NOT EXISTS entries_loan ON entries(loan_id, entry_date)');
    $cols = array_column($pdo->query('PRAGMA table_info(loans)')->fetchAll(), 'name');
    if (!in_array('share_token', $cols, true)) {
        $pdo->exec('ALTER TABLE loans ADD COLUMN share_token TEXT');
        $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS loans_share ON loans(share_token)');
    }
    if (!in_array('share_code', $cols, true)) {
        $pdo->exec('ALTER TABLE loans ADD COLUMN share_code TEXT');
        $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS loans_share_code ON loans(share_code)');
    }
    return $pdo;
}

function lc_json_read($name) {
    $f = lc_dir() . '/' . $name;
    if (!is_file($f)) return null;
    $d = json_decode((string)file_get_contents($f), true);
    return is_array($d) ? $d : null;
}
function lc_json_write($name, $data) {
    $f = lc_dir() . '/' . $name;
    $tmp = $f . '.' . bin2hex(random_bytes(4)) . '.tmp';
    if (file_put_contents($tmp, json_encode($data), LOCK_EX) === false) lc_fail('Could not write to the data folder. Check that admin/data is writable.');
    @chmod($tmp, 0600);
    rename($tmp, $f);
}

// ---------- Session & auth ----------
function lc_session() {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    session_name('mb_admin');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/admin/',
        'secure' => lc_is_https(),
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_start();
}

function lc_password_set() {
    $a = lc_json_read('auth.json');
    return $a && !empty($a['hash']);
}

function lc_logged_in() {
    lc_session();
    if (empty($_SESSION['uid'])) return false;
    $now = time();
    if ($now - ($_SESSION['seen'] ?? 0) > IDLE_MINUTES * 60 || $now - ($_SESSION['born'] ?? 0) > 86400) {
        $_SESSION = [];
        session_regenerate_id(true);
        return false;
    }
    // A password change signs out every other session.
    $a = lc_json_read('auth.json');
    if (!$a || ($a['ver'] ?? 0) !== ($_SESSION['ver'] ?? -1)) {
        $_SESSION = [];
        return false;
    }
    $_SESSION['seen'] = $now;
    return true;
}

function require_login() {
    if (!lc_password_set()) { header('Location: setup.php'); exit; }
    if (!lc_logged_in()) { header('Location: login.php'); exit; }
}

function lc_sign_in() {
    lc_session();
    session_regenerate_id(true);
    $a = lc_json_read('auth.json');
    $_SESSION = ['uid' => 1, 'born' => time(), 'seen' => time(), 'ver' => $a['ver'] ?? 0,
                 'csrf' => bin2hex(random_bytes(32))];
}

// Login throttling: 5 misses from one address locks it for 15 minutes;
// 25 misses from anywhere in an hour locks all logins for 15 minutes.
function lc_client_ip() { return $_SERVER['REMOTE_ADDR'] ?? 'unknown'; }
function lc_throttle_check($file = 'throttle.json') {
    $t = lc_json_read($file) ?: [];
    $now = time();
    $ip = hash('sha256', lc_client_ip());
    if (($t['ip'][$ip]['until'] ?? 0) > $now) return (int)ceil(($t['ip'][$ip]['until'] - $now) / 60);
    if (($t['all']['until'] ?? 0) > $now) return (int)ceil(($t['all']['until'] - $now) / 60);
    return 0;
}
function lc_throttle_fail($file = 'throttle.json', $perIp = 5, $global = 25) {
    $t = lc_json_read($file) ?: [];
    $now = time();
    $ip = hash('sha256', lc_client_ip());
    foreach (($t['ip'] ?? []) as $k => $v) if (($v['last'] ?? 0) < $now - 86400) unset($t['ip'][$k]);
    $r = $t['ip'][$ip] ?? ['n' => 0];
    $r['n'] = ($r['last'] ?? 0) < $now - 900 ? 1 : $r['n'] + 1;
    $r['last'] = $now;
    if ($r['n'] >= $perIp) { $r['until'] = $now + 900; $r['n'] = 0; }
    $t['ip'][$ip] = $r;
    $g = $t['all'] ?? ['n' => 0, 'since' => $now];
    if ($g['since'] < $now - 3600) $g = ['n' => 0, 'since' => $now];
    $g['n']++;
    if ($g['n'] >= $global) { $g['until'] = $now + 900; $g['n'] = 0; $g['since'] = $now; }
    $t['all'] = $g;
    lc_json_write($file, $t);
}
function lc_throttle_clear() {
    $t = lc_json_read('throttle.json') ?: [];
    unset($t['ip'][hash('sha256', lc_client_ip())]);
    lc_json_write('throttle.json', $t);
}

// ---------- CSRF ----------
function csrf_token() {
    lc_session();
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}
function csrf_field() {
    return '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">';
}
function csrf_check() {
    lc_session();
    $t = $_POST['csrf'] ?? '';
    if (!is_string($t) || empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $t)) {
        http_response_code(400);
        lc_fail('Your session expired. Go back, reload the page and try again.');
    }
}

// ---------- Helpers ----------
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function money($cents) {
    $neg = $cents < 0;
    return ($neg ? '-' : '') . '$' . number_format(abs($cents) / 100, 2);
}
function parse_money($s) {
    $s = preg_replace('/[\s$,]/', '', (string)$s);
    if (!preg_match('/^\d+(\.\d{1,2})?$/', $s)) return null;
    return (int)round(((float)$s) * 100);
}
function parse_rate($s) {
    $s = trim(str_replace('%', '', (string)$s));
    if ($s === '' || !is_numeric($s) || (float)$s < 0 || (float)$s > 200) return null;
    return (float)$s;
}
function today() { return date('Y-m-d'); }
function nice_date($d) { $x = lc_date($d); return $x ? $x->format('M j, Y') : ''; }
function now_stamp() { return date('c'); }
function post($k) { $v = $_POST[$k] ?? ''; return is_string($v) ? trim($v) : ''; }

function flash($msg = null, $kind = 'ok') {
    lc_session();
    if ($msg !== null) { $_SESSION['flash'] = [$kind, $msg]; return null; }
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}

function redirect($url) { header('Location: ' . $url, true, 303); exit; }

function lc_fail($msg) {
    $a = defined('LC_PUBLIC') ? '../admin/' : '';
    page_head('Loan Center', false, $a);
    echo '<main class="wrap narrow"><div class="card"><p class="err">' . h($msg) . '</p>' . (defined('LC_PUBLIC') ? '' : '<p><a href="index.php">Back to loans</a></p>') . '</div></main>';
    page_foot($a);
    exit;
}

function page_head($title, $nav = false, $assets = '') {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">'
       . '<meta name="viewport" content="width=device-width, initial-scale=1">'
       . '<meta name="robots" content="noindex, nofollow">'
       . '<title>' . h($title) . '</title>'
       . '<link rel="icon" href="../images/favicon.svg" type="image/svg+xml">'
       . '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500&family=Inter:wght@400;500;600&display=swap">'
       . '<link rel="stylesheet" href="' . $assets . 'app.css?v=2"></head><body>';
    if ($nav) {
        echo '<header class="top"><div class="wrap top__in">'
           . '<a class="brand" href="index.php"><span class="brand__mark">MB</span> Loan Center</a>'
           . '<nav><a href="index.php">Loans</a><a href="export.php">Export CSV</a><a href="password.php">Password</a>'
           . '<form method="post" action="logout.php" class="inline">' . csrf_field() . '<button class="link">Sign out</button></form></nav>'
           . '</div></header>';
        $f = flash();
        if ($f) echo '<div class="wrap"><p class="flash flash--' . h($f[0]) . '">' . h($f[1]) . '</p></div>';
    }
}
function page_foot($assets = '') {
    echo '<script src="' . $assets . 'app.js?v=2"></script></body></html>';
}

function load_loan($id) {
    $st = db()->prepare('SELECT * FROM loans WHERE id = ?');
    $st->execute([(int)$id]);
    $loan = $st->fetch();
    if (!$loan) { http_response_code(404); lc_fail('Loan not found.'); }
    return $loan;
}
function load_entries($id) {
    $st = db()->prepare('SELECT * FROM entries WHERE loan_id = ? ORDER BY entry_date, id');
    $st->execute([(int)$id]);
    return $st->fetchAll();
}
function loan_as_of($loan) {
    return ($loan['status'] === 'paid_off' && $loan['closed_date']) ? $loan['closed_date'] : today();
}

// Validates the loan form. Returns [data, errors].
function loan_from_post() {
    $e = [];
    $d = [
        'borrower' => post('borrower'),
        'contact' => post('contact'),
        'collateral' => post('collateral'),
        'principal_cents' => parse_money(post('principal')),
        'rate' => parse_rate(post('rate')),
        'default_rate' => post('default_rate') === '' ? null : parse_rate(post('default_rate')),
        'method' => post('method') ?: 'simple',
        'daycount' => post('daycount') ?: 'act365',
        'start_date' => post('start_date'),
        'maturity_date' => post('maturity_date'),
        'notes' => post('notes'),
    ];
    if ($d['borrower'] === '') $e[] = 'Enter the borrower.';
    if (!$d['principal_cents']) $e[] = 'Enter the loan amount, e.g. 50000.';
    if ($d['rate'] === null) $e[] = 'Enter the annual interest rate, e.g. 12.';
    if (post('default_rate') !== '' && $d['default_rate'] === null) $e[] = 'The default rate is not a valid number.';
    if (!isset(LC_METHODS[$d['method']])) $e[] = 'Pick an interest method.';
    if (!isset(LC_DAYCOUNTS[$d['daycount']])) $e[] = 'Pick a day count.';
    if (!lc_date($d['start_date'])) $e[] = 'Enter the date the loan was funded.';
    $term = post('term_months');
    if ($d['maturity_date'] === '' && ctype_digit($term) && (int)$term > 0 && lc_date($d['start_date'])) {
        $d['maturity_date'] = lc_date($d['start_date'])->modify('+' . (int)$term . ' months')->format('Y-m-d');
    }
    if ($d['maturity_date'] === '') {
        $d['maturity_date'] = null;
    } elseif (!lc_date($d['maturity_date'])) {
        $e[] = 'The maturity date is not valid.';
    } elseif (lc_date($d['start_date']) && $d['maturity_date'] <= $d['start_date']) {
        $e[] = 'Maturity must be after the start date.';
    }
    return [$d, $e];
}

function loan_form_fields($v) {
    $sel = function ($opts, $cur) {
        $o = '';
        foreach ($opts as $k => $label) $o .= '<option value="' . h($k) . '"' . ($k === $cur ? ' selected' : '') . '>' . h($label) . '</option>';
        return $o;
    };
    $amt = isset($v['principal_cents']) && $v['principal_cents'] ? number_format($v['principal_cents'] / 100, 2, '.', '') : ($v['principal'] ?? '');
    ?>
    <div class="grid">
      <label class="span2">Borrower<input name="borrower" required value="<?= h($v['borrower'] ?? '') ?>" autocomplete="off"></label>
      <label>Amount loaned ($)<input name="principal" required inputmode="decimal" value="<?= h($amt) ?>" placeholder="50,000"></label>
      <label>Rate (% per year)<input name="rate" required inputmode="decimal" value="<?= h($v['rate'] ?? '') ?>" placeholder="12"></label>
      <label>Funded on<input type="date" name="start_date" required value="<?= h($v['start_date'] ?? today()) ?>" data-start></label>
      <label>Term (months)<input name="term_months" inputmode="numeric" value="<?= h($v['term_months'] ?? '') ?>" placeholder="6" data-term></label>
      <label>Maturity date<input type="date" name="maturity_date" value="<?= h($v['maturity_date'] ?? '') ?>" data-maturity></label>
    </div>
    <details class="more"<?= !empty($v['_open_more']) ? ' open' : '' ?>>
      <summary>More options (interest method, default rate, contact, notes)</summary>
      <div class="grid">
        <label>Interest method<select name="method"><?= $sel(LC_METHODS, $v['method'] ?? 'simple') ?></select></label>
        <label>Day count<select name="daycount"><?= $sel(LC_DAYCOUNTS, $v['daycount'] ?? 'act365') ?></select></label>
        <label>Default rate after maturity (%)<input name="default_rate" inputmode="decimal" value="<?= h($v['default_rate'] ?? '') ?>" placeholder="blank = same rate"></label>
        <label>Borrower contact<input name="contact" value="<?= h($v['contact'] ?? '') ?>" placeholder="phone or email"></label>
        <label class="span2">Collateral / property<input name="collateral" value="<?= h($v['collateral'] ?? '') ?>"></label>
        <label class="span4">Notes<textarea name="notes" rows="2"><?= h($v['notes'] ?? '') ?></textarea></label>
      </div>
    </details>
    <?php
}

function share_url($token) {
    $host = $_SERVER['HTTP_HOST'] ?? 'markbitton.com';
    return (lc_is_local() ? 'http://' : 'https://') . $host . '/loan/?k=' . $token;
}

// Short access code a borrower can type on markbitton.com/loan/ (about 50 bits).
function lc_new_code() {
    $a = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    $c = '';
    for ($i = 0; $i < 10; $i++) $c .= $a[random_int(0, strlen($a) - 1)];
    return $c;
}
function lc_code_fmt($c) { return substr($c, 0, 5) . '-' . substr($c, 5); }
function lc_code_clean($s) {
    return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)$s));
}
