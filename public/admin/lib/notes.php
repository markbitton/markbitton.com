<?php
// Promissory notes and electronic signatures.

function lc_notes_schema(PDO $pdo) {
    $pdo->exec('CREATE TABLE IF NOT EXISTS notes (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        loan_id INTEGER NOT NULL REFERENCES loans(id) ON DELETE CASCADE,
        status TEXT NOT NULL DEFAULT \'draft\',
        fields TEXT NOT NULL DEFAULT \'{}\',
        body TEXT NOT NULL DEFAULT \'\',
        body_hash TEXT,
        created_by INTEGER,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL,
        sent_at TEXT,
        completed_at TEXT,
        voided_at TEXT
    )');
    $pdo->exec('CREATE TABLE IF NOT EXISTS signers (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        note_id INTEGER NOT NULL REFERENCES notes(id) ON DELETE CASCADE,
        role TEXT NOT NULL,
        name TEXT NOT NULL,
        email TEXT,
        token TEXT,
        user_id INTEGER,
        signed_name TEXT,
        signature_png TEXT,
        signed_at TEXT,
        ip TEXT,
        user_agent TEXT,
        viewed_at TEXT
    )');
    $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS signers_token ON signers(token)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS note_events (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        note_id INTEGER NOT NULL REFERENCES notes(id) ON DELETE CASCADE,
        at TEXT NOT NULL,
        event TEXT NOT NULL,
        ip TEXT,
        detail TEXT NOT NULL DEFAULT \'\'
    )');
}

const NOTE_SCHEDULES = [
    'maturity' => 'All principal and interest due at maturity',
    'monthly_io' => 'Monthly interest-only payments, principal due at maturity',
    'custom' => 'Custom payment terms (write them below)',
];

function note_event($noteId, $event, $detail = '') {
    db()->prepare('INSERT INTO note_events (note_id, at, event, ip, detail) VALUES (?,?,?,?,?)')
        ->execute([$noteId, date('c'), $event, lc_client_ip(), $detail]);
}

function note_default_fields($loan, $user) {
    return [
        'lender_name' => $user && $user['is_owner'] ? 'Mark Bitton' : ($user['name'] ?? 'Mark Bitton'),
        'lender_address' => '',
        'borrower_name' => $loan['borrower'],
        'borrower_address' => '',
        'borrower_email' => $loan['borrower_email'] ?: (lc_valid_email(trim($loan['contact'])) ? trim($loan['contact']) : ''),
        'note_date' => $loan['start_date'],
        'city_state' => 'St. George, Utah',
        'schedule' => 'monthly_io',
        'due_day' => '1',
        'custom_terms' => '',
        'late_days' => '10',
        'late_fee' => '5% of the late payment',
        'security' => $loan['collateral'] ? 'a Deed of Trust on the real property commonly known as ' . $loan['collateral'] : '',
        'governing_state' => 'Utah',
        'extra' => '',
        'future_advances' => '1',
        'lender_signs' => '0',
        'max_advances' => '',
    ];
}

// The loan's money out: the original principal plus every "Additional advance"
// entry, oldest first. Each row: [date, cents, label].
function note_advances($loan) {
    $rows = [[$loan['start_date'], (int)$loan['principal_cents'], 'Initial advance']];
    $st = db()->prepare("SELECT entry_date, amount_cents, note FROM entries WHERE loan_id = ? AND type = 'advance' ORDER BY entry_date, id");
    $st->execute([$loan['id']]);
    foreach ($st->fetchAll() as $e) $rows[] = [$e['entry_date'], (int)$e['amount_cents'], 'Additional advance'];
    return $rows;
}
function note_loan_total($loan) { return array_sum(array_column(note_advances($loan), 1)); }

// True when the loan's advances changed after this note's text was built.
function note_outdated($note, $loan) {
    $f = json_decode($note['fields'], true) ?: [];
    if (!isset($f['total_cents'])) return note_loan_total($loan) !== (int)$loan['principal_cents'];
    return (int)$f['total_cents'] !== note_loan_total($loan) || ($f['advance_count'] ?? 1) !== count(note_advances($loan));
}

function note_num($cents) { return number_format($cents / 100, 2); }

function note_amount_words($cents) {
    $n = intdiv($cents, 100); $c = $cents % 100;
    $ones = ['', 'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine', 'ten', 'eleven', 'twelve',
             'thirteen', 'fourteen', 'fifteen', 'sixteen', 'seventeen', 'eighteen', 'nineteen'];
    $tens = ['', '', 'twenty', 'thirty', 'forty', 'fifty', 'sixty', 'seventy', 'eighty', 'ninety'];
    $chunk = function ($x) use ($ones, $tens) {
        $w = [];
        if ($x >= 100) { $w[] = $ones[intdiv($x, 100)] . ' hundred'; $x %= 100; }
        if ($x >= 20) { $w[] = $tens[intdiv($x, 10)] . ($x % 10 ? '-' . $ones[$x % 10] : ''); }
        elseif ($x > 0) { $w[] = $ones[$x]; }
        return implode(' ', $w);
    };
    if ($n === 0) $words = 'zero';
    else {
        $parts = [];
        foreach ([1000000000 => 'billion', 1000000 => 'million', 1000 => 'thousand', 1 => ''] as $div => $name) {
            if ($n >= $div) { $parts[] = trim($chunk(intdiv($n, $div)) . ' ' . $name); $n %= $div; }
        }
        $words = implode(' ', $parts);
    }
    return ucfirst($words) . ' and ' . str_pad((string)$c, 2, '0', STR_PAD_LEFT) . '/100 Dollars';
}

function note_ordinal($n) {
    $n = (int)$n;
    $s = ['th', 'st', 'nd', 'rd'];
    $v = $n % 100;
    return $n . ($s[($v - 20) % 10] ?? $s[$v] ?? $s[0]);
}

// Builds the note text from the loan and the form fields.
function note_build($loan, $f) {
    $rate = rtrim(rtrim(number_format((float)$loan['rate'], 3), '0'), '.');
    $basis = ['act365' => 'a 365-day year for the actual number of days elapsed',
              'act360' => 'a 360-day year for the actual number of days elapsed',
              '30360' => 'a 360-day year of twelve 30-day months'][$loan['daycount']];
    $method = $loan['method'] === 'compound'
        ? 'Interest compounds daily on unpaid principal and unpaid accrued interest'
        : 'Interest is simple interest on the unpaid principal balance and does not compound';
    $adv = note_advances($loan);
    $total = array_sum(array_column($adv, 1));
    $multi = count($adv) > 1;
    $amt = '$' . note_num($total);
    $mat = $loan['maturity_date'] ? note_date($loan['maturity_date']) : '[MATURITY DATE]';
    $lender = $f['lender_name'] ?: '[LENDER]';
    $borrower = $f['borrower_name'] ?: '[BORROWER]';

    if ($f['schedule'] === 'monthly_io') {
        $first = lc_date($loan['start_date']) ? lc_date($loan['start_date'])->modify('first day of next month') : null;
        $pay = "Borrower shall pay all interest accrued and unpaid in monthly installments due on the " . note_ordinal($f['due_day'] ?: 1)
             . " day of each month, beginning " . ($first ? $first->format('F') . ' ' . (int)($f['due_day'] ?: 1) . ', ' . $first->format('Y') : '[FIRST PAYMENT DATE]')
             . ". The entire unpaid principal balance, together with all accrued and unpaid interest, shall be due and payable in full on $mat (the \"Maturity Date\").";
    } elseif ($f['schedule'] === 'custom') {
        $pay = trim($f['custom_terms']) ?: '[PAYMENT TERMS]';
        $pay .= " Any unpaid principal and accrued interest shall be due and payable in full on $mat (the \"Maturity Date\").";
    } else {
        $pay = "The entire unpaid principal balance, together with all accrued and unpaid interest, shall be due and payable in a single payment on $mat (the \"Maturity Date\").";
    }

    $default = $loan['default_rate'] !== null
        ? 'From and after the Maturity Date, or upon any earlier Event of Default, the unpaid principal shall bear interest at ' . rtrim(rtrim(number_format((float)$loan['default_rate'], 3), '0'), '.') . '% per annum (the "Default Rate") until paid in full.'
        : 'From and after the Maturity Date, or upon any earlier Event of Default, interest shall continue to accrue at the rate stated above until paid in full.';

    $s = [];
    $amends = $f['amends'] ?? null;
    $s[] = $amends ? "AMENDED AND RESTATED PROMISSORY NOTE" : "PROMISSORY NOTE";
    $s[] = "Principal Amount: $amt\nDate: " . note_date($f['note_date']) . "\nPlace: " . $f['city_state'];
    $s[] = "FOR VALUE RECEIVED, the undersigned, $borrower" . ($f['borrower_address'] ? ", of {$f['borrower_address']}" : '')
         . " (\"Borrower\"), promises to pay to the order of $lender" . ($f['lender_address'] ? ", of {$f['lender_address']}" : '')
         . " (\"Lender\"), or any later holder of this Note, the principal sum of " . note_amount_words($total) . " ($amt)"
         . ($multi ? ", being the total of the advances listed in the Schedule of Advances below" : '')
         . (($f['future_advances'] ?? '') === '1' ? ", plus any additional advances Lender makes under this Note" : '')
         . ", together with interest as provided below.";
    if ($amends) {
        $s[] = "This Note amends, restates and replaces the Promissory Note dated " . note_date($amends['date']) . " between Borrower and Lender (Document ID MB-PN-" . (int)$amends['id'] . ")"
             . ". It does not pay off or extinguish the debt evidenced by that note; all amounts outstanding under that note are now evidenced by and payable under this Note.";
    }
    $n = 1;
    $s[] = ($n++) . ". INTEREST. Interest accrues on the unpaid principal balance"
         . ($multi || ($f['future_advances'] ?? '') === '1' ? ", on each advance from the date it is made," : " from " . note_date($loan['start_date'])) . " at the rate of $rate% per annum, computed on the basis of $basis. $method.";
    if (($f['future_advances'] ?? '') === '1') {
        $max = parse_money($f['max_advances'] ?? '');
        $s[] = ($n++) . ". ADDITIONAL ADVANCES. Lender may, but is not required to, make additional advances to Borrower under this Note"
             . ($max ? ", provided the total of all advances does not exceed $" . note_num($max) : '')
             . ". Each additional advance accepted by Borrower is added to the principal balance, bears interest from the date it is made, and is payable on the terms of this Note. Lender's records of advances and payments are conclusive evidence of the amounts owed, absent manifest error. Borrower and Lender may sign an amended and restated note to confirm the advances made.";
    }
    $s[] = ($n++) . ". PAYMENT. $pay Payments shall be made in lawful money of the United States to Lender at the address above or such other place as Lender designates in writing.";
    $s[] = ($n++) . ". APPLICATION OF PAYMENTS. Each payment shall be applied first to any late charges and costs owed under this Note, then to accrued and unpaid interest, and then to principal.";
    $s[] = ($n++) . ". PREPAYMENT. Borrower may prepay this Note in whole or in part at any time without penalty. Interest accrues only through the date of payment.";
    if (trim($f['late_days']) !== '' && trim($f['late_fee']) !== '') {
        $s[] = ($n++) . ". LATE CHARGE. If any payment is not received within " . (int)$f['late_days'] . " days after its due date, Borrower shall pay a late charge of " . trim($f['late_fee']) . ".";
    }
    $s[] = ($n++) . ". DEFAULT. Each of the following is an \"Event of Default\": (a) Borrower fails to make any payment when due; (b) Borrower becomes insolvent, makes an assignment for the benefit of creditors, or becomes the subject of any bankruptcy proceeding; or (c) Borrower breaches any other obligation under this Note"
         . ($f['security'] ? ' or any document securing it' : '') . ". Upon an Event of Default, Lender may declare the entire unpaid principal and accrued interest immediately due and payable without further notice. $default";
    if (trim($f['security']) !== '') {
        $s[] = ($n++) . ". SECURITY. This Note is secured by " . trim($f['security']) . ".";
    }
    $s[] = ($n++) . ". COSTS OF COLLECTION. Borrower shall pay all costs of collecting this Note, including reasonable attorney fees, whether or not suit is filed.";
    $s[] = ($n++) . ". WAIVERS. Borrower waives presentment, demand for payment, notice of dishonor, protest, and notice of protest. No delay by Lender in exercising any right is a waiver of that right.";
    $s[] = ($n++) . ". GOVERNING LAW. This Note is governed by the laws of the State of " . ($f['governing_state'] ?: 'Utah') . ".";
    if (trim($f['extra']) !== '') {
        $s[] = ($n++) . ". ADDITIONAL TERMS. " . trim($f['extra']);
    }
    if ($multi) {
        $lines = ["SCHEDULE OF ADVANCES"];
        $run = 0;
        foreach ($adv as $i => [$d, $c, $label]) {
            $run += $c;
            $lines[] = ($i + 1) . ". " . note_date($d) . ": $" . note_num($c) . " ($label). Total advanced: $" . note_num($run);
        }
        $sched = implode("\n", $lines);
    }
    $s[] = ($n++) . ". ELECTRONIC SIGNATURES. " . (($f['lender_signs'] ?? '0') === '1' ? 'Borrower and Lender agree' : 'Borrower agrees') . " that this Note may be signed electronically, that electronic signatures have the same effect as handwritten signatures under the Uniform Electronic Transactions Act and the federal E-SIGN Act, and that an electronic record of this Note is an original.";
    if (!empty($sched)) $s[] = $sched;
    return implode("\n\n", $s);
}

// Fields saved with the note so later advances can be detected.
function note_snapshot($loan, $f) {
    $f['total_cents'] = note_loan_total($loan);
    $f['advance_count'] = count(note_advances($loan));
    return $f;
}

function note_date($d) { $x = lc_date($d); return $x ? $x->format('F j, Y') : '[DATE]'; }

function note_hash($body) { return hash('sha256', $body); }

function load_note($id) {
    $st = db()->prepare('SELECT * FROM notes WHERE id = ?');
    $st->execute([(int)$id]);
    return $st->fetch() ?: null;
}
function note_signers($noteId) {
    $st = db()->prepare("SELECT * FROM signers WHERE note_id = ? ORDER BY CASE role WHEN 'borrower' THEN 0 ELSE 1 END, id");
    $st->execute([(int)$noteId]);
    return $st->fetchAll();
}
function note_events($noteId) {
    $st = db()->prepare('SELECT * FROM note_events WHERE note_id = ? ORDER BY id');
    $st->execute([(int)$noteId]);
    return $st->fetchAll();
}

// After a signature: mark complete when everyone has signed, email both copies.
function note_maybe_complete($noteId) {
    $signers = note_signers($noteId);
    foreach ($signers as $s) if (!$s['signed_at']) return false;
    db()->prepare("UPDATE notes SET status = 'signed', completed_at = ?, updated_at = ? WHERE id = ? AND status <> 'signed'")
        ->execute([date('c'), date('c'), $noteId]);
    note_event($noteId, 'completed', 'All parties signed');
    $note = load_note($noteId);
    $amends = (json_decode($note['fields'], true) ?: [])['amends'] ?? null;
    if ($amends) {
        db()->prepare("UPDATE notes SET status = 'superseded', updated_at = ? WHERE id = ? AND status = 'signed'")->execute([date('c'), (int)$amends['id']]);
        note_event((int)$amends['id'], 'superseded', 'Replaced by Amended and Restated Promissory Note MB-PN-' . (int)$noteId);
    }
    // When the lender didn't sign, the admin who sent the note still gets the copy.
    if (!in_array('lender', array_column($signers, 'role'), true) && $note['created_by']) {
        $st = db()->prepare('SELECT name, email FROM users WHERE id = ?'); $st->execute([$note['created_by']]);
        if (($u = $st->fetch()) && $u['email']) $signers[] = ['email' => $u['email'], 'name' => $u['name'] ?: 'Mark', 'token' => null, 'role' => 'lender'];
    }
    foreach ($signers as $s) {
        if (!$s['email']) continue;
        $link = $s['token'] ? site_url('/loan/sign.php?t=' . $s['token']) : site_url('/admin/note.php?id=' . $noteId);
        lc_mail($s['email'], 'Signed: Promissory Note',
            "Hi {$s['name']},\n\nThe promissory note has been signed by everyone. You can view and print (or save as PDF) the fully signed copy, with its signature certificate, here:\n\n$link\n\nDocument fingerprint (SHA-256): {$note['body_hash']}\n");
    }
    return true;
}

// Renders the note body and signature blocks (shared by admin and signing pages).
function note_render($note, $signers, $withCert = true) {
    $out = '<article class="doc">';
    $paras = explode("\n\n", $note['body']);
    foreach ($paras as $i => $p) {
        if ($i === 0) { $out .= '<h1 class="doc__title">' . h($p) . '</h1>'; continue; }
        $out .= '<p>' . nl2br(h($p)) . '</p>';
    }
    $out .= '<div class="sigs">';
    foreach ($signers as $s) {
        $out .= '<div class="sig"><div class="sig__line">';
        if ($s['signed_at']) {
            if ($s['signature_png']) $out .= '<img src="' . h($s['signature_png']) . '" alt="Signature of ' . h($s['signed_name']) . '">';
            else $out .= '<span class="sig__typed">' . h($s['signed_name']) . '</span>';
        } else {
            $out .= '<span class="muted">Not signed yet</span>';
        }
        $by = ($s['signed_at'] && strcasecmp(trim($s['signed_name']), trim($s['name'])) !== 0) ? '<br>By: ' . h($s['signed_name']) : '';
        $out .= '</div><div class="sig__who"><strong>' . h($s['role'] === 'borrower' ? 'BORROWER' : 'LENDER') . ':</strong> ' . h($s['name']) . $by
              . ($s['signed_at'] ? '<br><small>Signed electronically ' . h(date('M j, Y g:i A T', strtotime($s['signed_at']))) . '</small>' : '') . '</div></div>';
    }
    $out .= '</div></article>';
    if ($withCert && $note['status'] !== 'draft') {
        $out .= '<section class="doc cert"><h2>Signature certificate</h2>'
              . '<p class="small">Document ID: MB-PN-' . (int)$note['id'] . '<br>Document fingerprint (SHA-256): <code>' . h($note['body_hash']) . '</code><br>Status: '
              . h(ucfirst(str_replace('_', ' ', $note['status']))) . '</p><table class="certtable"><tr><th>When</th><th>What</th><th>IP address</th></tr>';
        foreach (note_events($note['id']) as $e) {
            $out .= '<tr><td>' . h(date('M j, Y g:i:s A T', strtotime($e['at']))) . '</td><td>' . h($e['detail'] ?: $e['event']) . '</td><td>' . h($e['ip']) . '</td></tr>';
        }
        $out .= '</table></section>';
    }
    return $out;
}

const NOTE_CONSENT = 'I agree to sign this document electronically. I understand my electronic signature is legally binding, the same as signing on paper, and that I can print or save a copy.';

function note_sign_form($extraHidden = '', $defaultName = '') {
    return '<form method="post" class="card stack signform" data-sigform>' . $extraHidden
        . '<h3>Sign</h3>'
        . '<label class="check"><input type="checkbox" name="consent" value="1" required> <span>' . h(NOTE_CONSENT) . '</span></label>'
        . '<label>Type your full legal name<input name="signed_name" required autocomplete="name" value="' . h($defaultName) . '"></label>'
        . '<div><span class="small muted">Draw your signature (optional; your typed name is used if you skip this)</span>'
        . '<canvas class="sigpad" width="600" height="160" data-sigpad></canvas>'
        . '<button type="button" class="link" data-sigclear>Clear drawing</button></div>'
        . '<input type="hidden" name="signature_png" data-sigdata>'
        . '<div><button class="btn">Sign electronically</button></div></form>';
}

// Validates a submitted signature. Returns [signed_name, png_or_null, error].
function note_take_signature() {
    $name = trim(preg_replace('/\s+/', ' ', post('signed_name')));
    if (post('consent') !== '1') return [null, null, 'Please check the box agreeing to sign electronically.'];
    if (mb_strlen($name) < 3) return [null, null, 'Type your full legal name.'];
    $png = post('signature_png');
    if ($png !== '') {
        if (strlen($png) > 300000 || strpos($png, 'data:image/png;base64,') !== 0) return [null, null, 'The drawn signature could not be read. Clear it and try again.'];
        $raw = base64_decode(substr($png, 22), true);
        if ($raw === false || substr($raw, 0, 8) !== "\x89PNG\r\n\x1a\n") return [null, null, 'The drawn signature could not be read. Clear it and try again.'];
    } else {
        $png = null;
    }
    return [$name, $png, ''];
}

function note_record_signature($signer, $name, $png, $by = '') {
    db()->prepare('UPDATE signers SET signed_name = ?, signature_png = ?, signed_at = ?, ip = ?, user_agent = ? WHERE id = ? AND signed_at IS NULL')
        ->execute([$name, $png, date('c'), lc_client_ip(), substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 300), $signer['id']]);
    note_event($signer['note_id'], 'signed', ($signer['role'] === 'borrower' ? 'Borrower' : 'Lender') . " signed as \"$name\"" . ($png ? ' (drawn signature)' : ' (typed signature)')
        . ($by ? " via admin account $by" : '') . ' and agreed to sign electronically');
    return note_maybe_complete($signer['note_id']);
}
