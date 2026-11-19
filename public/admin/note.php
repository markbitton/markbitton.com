<?php
require __DIR__ . '/lib/bootstrap.php';
require_login();
$me = current_user();
$errors = [];

// note.php?loan=ID opens the loan's current note, or starts a new draft.
if (isset($_GET['loan'])) {
    $loan = load_loan($_GET['loan']);
    $st = db()->prepare("SELECT id FROM notes WHERE loan_id = ? AND status NOT IN ('void', 'superseded') ORDER BY id DESC LIMIT 1");
    $st->execute([$loan['id']]);
    if ($id = $st->fetchColumn()) redirect('note.php?id=' . $id);
    $f = note_default_fields($loan, $me);
    db()->prepare('INSERT INTO notes (loan_id, status, fields, body, created_by, created_at, updated_at) VALUES (?, \'draft\', ?, ?, ?, ?, ?)')
        ->execute([$loan['id'], json_encode(note_snapshot($loan, $f)), note_build($loan, $f), $me['id'], now_stamp(), now_stamp()]);
    $nid = db()->lastInsertId();
    note_event($nid, 'created', 'Draft created by ' . ($me['name'] ?: 'admin'));
    redirect('note.php?id=' . $nid);
}

$note = load_note($_GET['id'] ?? 0);
if (!$note) { http_response_code(404); lc_fail('Promissory note not found.'); }
$loan = load_loan($note['loan_id']);
$self = 'note.php?id=' . (int)$note['id'];
$f = array_merge(note_default_fields($loan, $me), json_decode($note['fields'], true) ?: []);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $a = post('action');
    if (in_array($a, ['build', 'save', 'send'], true) && $note['status'] === 'draft') {
        foreach (array_keys($f) as $k) if (isset($_POST[$k]) && !in_array($k, ['amends', 'total_cents', 'advance_count'], true)) $f[$k] = post($k);
        if ($a === 'build') $f = note_snapshot($loan, $f);
        if (!isset(NOTE_SCHEDULES[$f['schedule']])) $f['schedule'] = 'maturity';
        $body = $a === 'build' ? note_build($loan, $f) : str_replace("\r\n", "\n", (string)($_POST['body'] ?? ''));
        db()->prepare('UPDATE notes SET fields = ?, body = ?, updated_at = ? WHERE id = ?')->execute([json_encode($f), $body, now_stamp(), $note['id']]);
        if ($a === 'send') {
            if (!lc_valid_email($f['borrower_email'])) $errors[] = "Enter the borrower's email so they can be sent the signing link.";
            if (trim($f['borrower_name']) === '' || trim($f['lender_name']) === '') $errors[] = 'Enter both the borrower and lender names.';
            if (note_outdated(['fields' => json_encode($f)] + $note, $loan)) $errors[] = 'Advances on this loan changed since the text was built. Click "Rebuild text" so the note covers all of them, then send.';
            if (preg_match('/\[[A-Z ]+\]/', $body)) $errors[] = 'The note still has a blank in [BRACKETS]. Fill it in first.';
            if (!$errors) {
                $db = db();
                $db->beginTransaction();
                $db->prepare("UPDATE notes SET status = 'out_for_signature', body_hash = ?, sent_at = ?, updated_at = ? WHERE id = ?")
                   ->execute([note_hash($body), now_stamp(), now_stamp(), $note['id']]);
                $tok = bin2hex(random_bytes(24));
                $db->prepare("INSERT INTO signers (note_id, role, name, email, token) VALUES (?, 'borrower', ?, ?, ?)")
                   ->execute([$note['id'], $f['borrower_name'], $f['borrower_email'], $tok]);
                $db->prepare("INSERT INTO signers (note_id, role, name, email, user_id) VALUES (?, 'lender', ?, ?, ?)")
                   ->execute([$note['id'], $f['lender_name'], $me['email'], $me['id']]);
                $db->prepare('UPDATE loans SET borrower_email = COALESCE(borrower_email, ?) WHERE id = ?')->execute([$f['borrower_email'], $loan['id']]);
                $db->commit();
                note_event($note['id'], 'sent', 'Locked for signing (fingerprint ' . substr(note_hash($body), 0, 12) . '…) and sent to ' . $f['borrower_email'] . ' by ' . ($me['name'] ?: 'admin'));
                $link = site_url('/loan/sign.php?t=' . $tok);
                $ok = lc_mail($f['borrower_email'], 'Please sign: Promissory Note for $' . note_num(note_loan_total($loan)),
                    "Hi {$f['borrower_name']},\n\n{$f['lender_name']} has sent you a" . (!empty($f['amends']) ? 'n amended' : '') . " promissory note for \$" . note_num(note_loan_total($loan)) . " to review and sign electronically.\n\n"
                    . "Review and sign here:\n$link\n\nPlease read the whole note before signing. Questions? Just reply to this email.\n", $me['email'] ?? '');
                flash($ok ? "Sent to {$f['borrower_email']} for signature. Sign as lender below whenever you're ready."
                          : "The note is ready for signing, but the email couldn't be sent. Copy the borrower's signing link below and send it yourself.", $ok ? 'ok' : 'warn');
                redirect($self);
            }
            $note = load_note($note['id']);
        } else {
            flash($a === 'build' ? 'Note text rebuilt from the fields.' : 'Draft saved.');
            redirect($self);
        }
    } elseif ($a === 'sign_lender' && $note['status'] === 'out_for_signature') {
        $lender = null;
        foreach (note_signers($note['id']) as $s) if ($s['role'] === 'lender') $lender = $s;
        [$name, $png, $err] = note_take_signature();
        if ($err) $errors[] = $err;
        elseif ($lender && !$lender['signed_at']) {
            $done = note_record_signature($lender, $name, $png, ($me['name'] ?: '') . ($me['email'] ? " <{$me['email']}>" : ''));
            flash($done ? 'Signed. Everyone has signed, and both parties were emailed a copy.' : 'Signed. Waiting on the borrower.');
            redirect($self);
        }
    } elseif ($a === 'amend' && $note['status'] === 'signed') {
        $st = db()->prepare("SELECT id FROM notes WHERE loan_id = ? AND status IN ('draft', 'out_for_signature') AND id > ? ORDER BY id DESC LIMIT 1");
        $st->execute([$loan['id'], $note['id']]);
        if ($open = $st->fetchColumn()) redirect('note.php?id=' . $open);
        $nf = $f;
        unset($nf['total_cents'], $nf['advance_count']);
        $nf['amends'] = ['id' => (int)$note['id'], 'date' => $f['note_date']];
        $nf['note_date'] = date('Y-m-d');
        $nf['borrower_email'] = $loan['borrower_email'] ?: $f['borrower_email'];
        db()->prepare('INSERT INTO notes (loan_id, status, fields, body, created_by, created_at, updated_at) VALUES (?, \'draft\', ?, ?, ?, ?, ?)')
            ->execute([$loan['id'], json_encode(note_snapshot($loan, $nf)), note_build($loan, $nf), $me['id'], now_stamp(), now_stamp()]);
        $nid = db()->lastInsertId();
        note_event($nid, 'created', 'Amended draft created by ' . ($me['name'] ?: 'admin') . ' to replace MB-PN-' . (int)$note['id']);
        flash('Amended note started. It lists every advance on the loan. Review it, then send it to the borrower. The current note stays in force until this one is fully signed.');
        redirect('note.php?id=' . $nid);
    } elseif ($a === 'resend' && $note['status'] === 'out_for_signature') {
        foreach (note_signers($note['id']) as $s) {
            if ($s['role'] === 'borrower' && !$s['signed_at']) {
                $ok = lc_mail($s['email'], 'Reminder: please sign the Promissory Note',
                    "Hi {$s['name']},\n\nA reminder to review and sign the promissory note:\n\n" . site_url('/loan/sign.php?t=' . $s['token']) . "\n", $me['email'] ?? '');
                note_event($note['id'], 'reminder', 'Reminder emailed to ' . $s['email']);
                flash($ok ? 'Reminder sent to ' . $s['email'] . '.' : "Couldn't send the email. Copy the link below.", $ok ? 'ok' : 'warn');
            }
        }
        redirect($self);
    } elseif ($a === 'void' && $note['status'] !== 'void') {
        db()->prepare("UPDATE notes SET status = 'void', voided_at = ?, updated_at = ? WHERE id = ?")->execute([now_stamp(), now_stamp(), $note['id']]);
        db()->prepare('UPDATE signers SET token = NULL WHERE note_id = ? AND signed_at IS NULL')->execute([$note['id']]);
        note_event($note['id'], 'void', 'Voided by ' . ($me['name'] ?: 'admin'));
        flash('Note voided. You can create a new one from the loan page.');
        redirect('loan.php?id=' . $loan['id']);
    }
}

$signers = note_signers($note['id']);
$statusLabel = ['draft' => 'Draft', 'out_for_signature' => 'Out for signature', 'signed' => 'Signed', 'void' => 'Void', 'superseded' => 'Replaced by amended note'][$note['status']] ?? $note['status'];
$outdated = in_array($note['status'], ['draft', 'out_for_signature', 'signed'], true) && note_outdated($note, $loan);
$st = db()->prepare('SELECT id, status, fields, created_at FROM notes WHERE loan_id = ? AND id <> ? ORDER BY id DESC');
$st->execute([$loan['id'], $note['id']]);
$versions = $st->fetchAll();
$loanTotal = note_loan_total($loan);
page_head('Promissory note · ' . $loan['borrower'], true);
?>
<main class="wrap">
  <p class="crumb noprint"><a href="loan.php?id=<?= (int)$loan['id'] ?>">← <?= h($loan['borrower']) ?></a></p>
  <div class="head noprint">
    <h1>Promissory note <span class="tag <?= $note['status'] === 'signed' ? 'tag--done' : ($note['status'] === 'void' ? 'tag--late' : 'tag--soon') ?>"><?= h($statusLabel) ?></span></h1>
  </div>
  <?php foreach ($errors as $e): ?><p class="err noprint"><?= h($e) ?></p><?php endforeach; ?>
  <?php if ($outdated && $note['status'] === 'signed'): ?>
    <div class="flash flash--warn noprint">This loan now has <?= money($loanTotal) ?> advanced in total, which this signed note doesn't fully list.
      <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="amend"><button class="btn btn--sm">Create amended note with all advances</button></form></div>
  <?php elseif ($outdated && $note['status'] === 'out_for_signature'): ?>
    <p class="flash flash--warn noprint">Advances on this loan changed after this note was sent. Void it below and create a new one so it lists all <?= money($loanTotal) ?>.</p>
  <?php elseif ($outdated && $note['status'] === 'draft'): ?>
    <p class="flash flash--warn noprint">Advances on this loan changed since this text was built. Click "Rebuild text" so the note covers all <?= money($loanTotal) ?>.</p>
  <?php endif; ?>

  <?php if ($note['status'] === 'draft'): ?>
    <p class="flash flash--warn">This template is a starting point, not legal advice. Have your attorney review it once, especially the late charge, default and security terms, before you rely on it.</p>
    <form method="post" class="stack">
      <?= csrf_field() ?>
      <div class="card stack">
        <h3>Parties and terms</h3>
        <div class="grid">
          <label class="span2">Lender legal name<input name="lender_name" value="<?= h($f['lender_name']) ?>"></label>
          <label class="span2">Lender address<input name="lender_address" value="<?= h($f['lender_address']) ?>"></label>
          <label class="span2">Borrower legal name<input name="borrower_name" value="<?= h($f['borrower_name']) ?>"></label>
          <label class="span2">Borrower address<input name="borrower_address" value="<?= h($f['borrower_address']) ?>"></label>
          <label class="span2">Borrower email (for signing)<input type="email" name="borrower_email" value="<?= h($f['borrower_email']) ?>"></label>
          <label>Note date<input type="date" name="note_date" value="<?= h($f['note_date']) ?>"></label>
          <label>Signed at (city, state)<input name="city_state" value="<?= h($f['city_state']) ?>"></label>
          <label class="span2">Payments<select name="schedule"><?php foreach (NOTE_SCHEDULES as $k => $v): ?><option value="<?= h($k) ?>"<?= $f['schedule'] === $k ? ' selected' : '' ?>><?= h($v) ?></option><?php endforeach; ?></select></label>
          <label>Monthly due day<input name="due_day" inputmode="numeric" value="<?= h($f['due_day']) ?>"></label>
          <label>Governing law (state)<input name="governing_state" value="<?= h($f['governing_state']) ?>"></label>
          <label class="span4">Custom payment terms (only if "Custom" above)<textarea name="custom_terms" rows="2"><?= h($f['custom_terms']) ?></textarea></label>
          <label>Late after (days)<input name="late_days" inputmode="numeric" value="<?= h($f['late_days']) ?>" placeholder="blank = no late charge"></label>
          <label class="span3">Late charge<input name="late_fee" value="<?= h($f['late_fee']) ?>" placeholder="e.g. 5% of the late payment, or $100"></label>
          <label class="span4">Secured by (blank = unsecured)<input name="security" value="<?= h($f['security']) ?>" placeholder="e.g. a Deed of Trust on the real property commonly known as 123 Main St, St. George, UT"></label>
          <label class="span2 check"><input type="hidden" name="future_advances" value="0"><input type="checkbox" name="future_advances" value="1"<?= ($f['future_advances'] ?? '') === '1' ? ' checked' : '' ?>> <span>Allow future advances under this note</span></label>
          <label class="span2">Maximum total advances (optional)<input name="max_advances" inputmode="decimal" value="<?= h($f['max_advances'] ?? '') ?>" placeholder="blank = no cap stated"></label>
          <label class="span4">Additional terms (optional)<textarea name="extra" rows="2"><?= h($f['extra']) ?></textarea></label>
        </div>
        <p class="small muted">The principal is the original amount plus every "Additional advance" recorded on the loan (<?= money($loanTotal) ?> now); with more than one advance, the note lists them all in a Schedule of Advances. Amount, rate, interest method, maturity and default rate come from the loan itself. <a href="loan.php?id=<?= (int)$loan['id'] ?>">Edit the loan</a> to change them, then click "Rebuild text".</p>
        <div><button class="btn btn--ghost" name="action" value="build">Rebuild text from these fields</button></div>
      </div>
      <div class="card stack">
        <h3>Note text</h3>
        <p class="small muted">You can edit the wording directly. "Rebuild text" replaces your edits with a fresh copy from the fields.</p>
        <textarea name="body" rows="28" class="mono"><?= h($note['body']) ?></textarea>
        <div class="sharerow">
          <button class="btn btn--ghost" name="action" value="save">Save draft</button>
          <button class="btn" name="action" value="send" data-confirm-click="Lock this note and email it to the borrower for signature? After this the text can't be changed (you can void it and start over).">Send to borrower for signature</button>
        </div>
      </div>
    </form>
  <?php else: ?>
    <div class="sharerow noprint">
      <button type="button" class="btn btn--ghost" data-print>Print / save as PDF</button>
      <?php if ($note['status'] === 'out_for_signature'): ?>
        <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="resend"><button class="link">Email the borrower a reminder</button></form>
      <?php endif; ?>
      <?php if ($note['status'] !== 'void'): ?>
        <form method="post" class="inline" data-confirm="Void this note? Signing links stop working. Signed copies stay on record."><?= csrf_field() ?><input type="hidden" name="action" value="void"><button class="link danger">Void note</button></form>
      <?php endif; ?>
    </div>
    <?php if ($note['status'] === 'out_for_signature'): foreach ($signers as $s): if ($s['role'] === 'borrower' && !$s['signed_at']): ?>
      <div class="card share noprint"><p class="small muted">Borrower's signing link (already emailed to <?= h($s['email']) ?>):</p>
        <div class="sharerow"><input readonly value="<?= h(site_url('/loan/sign.php?t=' . $s['token'])) ?>" data-copy><button type="button" class="btn btn--sm" data-copy-btn>Copy link</button></div></div>
    <?php endif; endforeach; endif; ?>

    <?= note_render($note, $signers) ?>

    <?php if ($note['status'] === 'out_for_signature'): foreach ($signers as $s): if ($s['role'] === 'lender' && !$s['signed_at']): ?>
      <div class="noprint"><?= note_sign_form(csrf_field() . '<input type="hidden" name="action" value="sign_lender">', $f['lender_name']) ?></div>
    <?php endif; endforeach; endif; ?>
  <?php endif; ?>
  <?php if ($versions): ?>
    <div class="card noprint"><h3>Other versions of this note</h3><ul class="small">
      <?php foreach ($versions as $v): $vf = json_decode($v['fields'], true) ?: []; ?>
        <li><a href="note.php?id=<?= (int)$v['id'] ?>">MB-PN-<?= (int)$v['id'] ?></a> · <?= h(['draft' => 'Draft', 'out_for_signature' => 'Out for signature', 'signed' => 'Signed', 'void' => 'Void', 'superseded' => 'Replaced'][$v['status']] ?? $v['status']) ?>
          <?= isset($vf['total_cents']) ? ' · ' . money($vf['total_cents']) : '' ?> · <?= h(nice_date(substr($v['created_at'], 0, 10))) ?></li>
      <?php endforeach; ?></ul></div>
  <?php endif; ?>
</main>
<?php page_foot();
