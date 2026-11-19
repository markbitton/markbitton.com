<?php
// Borrower signing page for a promissory note (opened from the emailed link).
define('LC_PUBLIC', true);
require __DIR__ . '/../admin/lib/bootstrap.php';
const A = '../admin/';

$t = $_GET['t'] ?? '';
if (!is_string($t) || !preg_match('/^[a-f0-9]{48}$/', $t)) { http_response_code(404); lc_fail('This signing link is not valid.'); }
if ($wait = lc_throttle_check('throttle-borrower.json')) { http_response_code(429); lc_fail("Too many tries. Please wait $wait minutes."); }
$st = db()->prepare('SELECT * FROM signers WHERE token = ?');
$st->execute([$t]);
$signer = $st->fetch();
if (!$signer) {
    lc_throttle_fail('throttle-borrower.json', 10, 100);
    http_response_code(404);
    lc_fail('This signing link is not valid or was cancelled. Please contact Mark for a new one.');
}
$note = load_note($signer['note_id']);
$loan = load_loan($note['loan_id']);
$err = '';

if ($note['status'] === 'void') lc_fail('This promissory note was cancelled. Please contact Mark if you have questions.');
if (!$signer['viewed_at']) {
    db()->prepare('UPDATE signers SET viewed_at = ? WHERE id = ?')->execute([date('c'), $signer['id']]);
    note_event($note['id'], 'viewed', ucfirst($signer['role']) . ' opened the signing link');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $note['status'] === 'out_for_signature' && !$signer['signed_at']) {
    if (!lc_same_origin()) { http_response_code(400); lc_fail('Please sign from the page itself. Reload it and try again.'); }
    if (note_hash($note['body']) !== $note['body_hash']) {
        note_event($note['id'], 'integrity', 'Signing refused: document text does not match its locked fingerprint');
        lc_fail('This document could not be verified, so it cannot be signed. Please contact Mark.');
    }
    [$name, $png, $err] = note_take_signature();
    if (!$err) {
        note_record_signature($signer, $name, $png);
        redirect('sign.php?t=' . $t . '&signed=1');
    }
}

$signers = note_signers($note['id']);
page_head('Promissory note · ' . $signer['name'], false, A);
?>
<main class="wrap">
  <div class="portal-head noprint">
    <p class="muted"><span class="brand__mark">MB</span>&nbsp; Promissory note from <?= h(json_decode($note['fields'], true)['lender_name'] ?? 'Mark Bitton') ?></p>
    <?php if (isset($_GET['signed'])): ?><p class="flash flash--ok">Thank you, your signature is recorded. <?= $note['status'] === 'signed' ? 'Everyone has signed, and a copy was emailed to you.' : 'You\'ll get an email with the final copy once the lender signs.' ?></p><?php endif; ?>
    <?php if ($note['status'] === 'out_for_signature' && !$signer['signed_at']): ?><p class="muted">Please read the whole note, then sign at the bottom.</p><?php endif; ?>
    <div class="sharerow"><button type="button" class="btn btn--ghost btn--sm" data-print>Print / save as PDF</button>
      <?php if ($loan['share_token']): ?><a class="small" href="./?k=<?= h($loan['share_token']) ?>">View loan balance</a><?php endif; ?></div>
  </div>
  <?php if ($err): ?><p class="err noprint"><?= h($err) ?></p><?php endif; ?>

  <?= note_render($note, $signers) ?>

  <?php if ($note['status'] === 'out_for_signature' && !$signer['signed_at']): ?>
    <div class="noprint"><?= note_sign_form('', '') ?></div>
  <?php endif; ?>
</main>
<?php page_foot(A);
