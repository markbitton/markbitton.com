<?php
require __DIR__ . '/lib/bootstrap.php';
if (!lc_password_set()) redirect('setup.php');
if (lc_logged_in()) redirect('index.php');
$err = '';
$email = post('email');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if ($wait = lc_throttle_check()) {
        $err = "Too many failed tries. Wait $wait minute" . ($wait === 1 ? '' : 's') . ' and try again.';
    } else {
        if (strpos($email, '@') === false) {
            // Blank or "admin": the owner account (the original single-password login).
            $st = db()->query('SELECT * FROM users WHERE is_owner = 1 AND active = 1 ORDER BY id LIMIT 1');
        } else {
            $st = db()->prepare('SELECT * FROM users WHERE lower(email) = lower(?) AND active = 1');
            $st->execute([$email]);
        }
        $u = $st->fetch();
        // Same work whether or not the account exists.
        $ok = password_verify(post('password'), $u && $u['hash'] ? $u['hash'] : '$2y$10$67Y02DJCoqfxGejJw0TGAeImMEYB7JTrKLGEWoI95DQ4SaHUJy5se');
        if ($u && $u['hash'] && $ok) {
            lc_throttle_clear();
            if (password_needs_rehash($u['hash'], PASSWORD_DEFAULT)) {
                db()->prepare('UPDATE users SET hash = ? WHERE id = ?')->execute([password_hash(post('password'), PASSWORD_DEFAULT), $u['id']]);
            }
            lc_sign_in($u);
            redirect('index.php');
        }
        lc_throttle_fail();
        usleep(300000);
        $err = 'Wrong email or password.';
    }
}
page_head('Sign in · Loan Center');
?>
<main class="wrap narrow">
  <form method="post" class="card stack">
    <h1><span class="brand__mark">MB</span> Loan Center</h1>
    <?php if ($err): ?><p class="err"><?= h($err) ?></p><?php endif; ?>
    <?= csrf_field() ?>
    <label>Email or username<input type="text" name="email" value="<?= h($email) ?>" autocomplete="username" autocapitalize="none" spellcheck="false" autofocus></label>
    <label>Password<input type="password" name="password" required autocomplete="current-password"></label>
    <button class="btn">Sign in</button>
    <p class="small muted"><a href="forgot.php">Forgot your password?</a></p>
  </form>
</main>
<?php page_foot();
