<?php
// Borrower view: read-only page for one loan, opened with a private link.
define('LC_PUBLIC', true);
require __DIR__ . '/../admin/lib/bootstrap.php';
const A = '../admin/';

$k = $_GET['k'] ?? '';
if (!is_string($k) || !preg_match('/^[a-f0-9]{48}$/', $k)) {
    http_response_code(404);
    lc_fail('This link is not valid. Please ask Mark for a new one.');
}
if ($wait = lc_throttle_check('throttle-borrower.json')) {
    http_response_code(429);
    lc_fail("Too many tries. Please wait $wait minutes.");
}
$st = db()->prepare('SELECT * FROM loans WHERE share_token = ?');
$st->execute([$k]);
$loan = $st->fetch();
if (!$loan) {
    lc_throttle_fail('throttle-borrower.json', 10, 100);
    http_response_code(404);
    lc_fail('This link is not valid or has been turned off. Please ask Mark for a new one.');
}

$entries = load_entries($loan['id']);
$closed = $loan['status'] === 'paid_off';
$asOf = loan_as_of($loan);
$c = lc_compute($loan, $entries, $asOf);
$today = today();
$qd = $_GET['d'] ?? '';
$quote = (!$closed && is_string($qd) && lc_date($qd) && $qd >= $today && $qd <= date('Y-m-d', strtotime('+1 year')))
    ? lc_compute($loan, $entries, $qd) : null;
$rate = function ($r) { return rtrim(rtrim(number_format((float)$r, 3), '0'), '.') . '%'; };

page_head('Your loan · ' . $loan['borrower'], false, A);
?>
<main class="wrap">
  <div class="portal-head">
    <p class="muted"><span class="brand__mark">MB</span>&nbsp; Loan statement from Mark Bitton</p>
    <h1><?= h($loan['borrower']) ?>
      <?php if ($closed): ?><span class="tag tag--done">Paid off <?= h(nice_date($loan['closed_date'])) ?></span>
      <?php elseif ($loan['maturity_date'] && $loan['maturity_date'] < $today): ?><span class="tag tag--late">Past maturity</span><?php endif; ?>
    </h1>
    <p class="muted"><?= money($loan['principal_cents']) ?> funded <?= h(nice_date($loan['start_date'])) ?>
      <?= $loan['maturity_date'] ? ' · due ' . h(nice_date($loan['maturity_date'])) : '' ?>
      · <?= h($rate($loan['rate'])) ?> per year, <?= h(strtolower(LC_METHODS[$loan['method']])) ?> (<?= h(LC_DAYCOUNTS[$loan['daycount']]) ?>)
      <?= $loan['default_rate'] !== null ? ' · ' . h($rate($loan['default_rate'])) . ' after the due date' : '' ?>
      <?= $loan['collateral'] ? '<br>' . h($loan['collateral']) : '' ?></p>
  </div>

  <section class="stats">
    <div><span>Principal owed</span><strong><?= money($c['principal']) ?></strong></div>
    <div><span>Interest owed</span><strong><?= money($c['interest']) ?></strong></div>
    <?php if ($c['fees']): ?><div><span>Fees owed</span><strong><?= money($c['fees']) ?></strong></div><?php endif; ?>
    <div class="hl"><span><?= $closed ? 'Balance' : 'Payoff today' ?></span><strong><?= money($c['payoff']) ?></strong></div>
    <?php if (!$closed): ?><div><span>Interest per day</span><strong><?= money($c['per_diem']) ?></strong></div><?php endif; ?>
    <div><span>Paid so far</span><strong><?= money($c['total_paid']) ?></strong></div>
  </section>

  <?php if (!$closed): ?>
  <form method="get" class="card stack">
    <h3>Payoff on a future date</h3>
    <input type="hidden" name="k" value="<?= h($k) ?>">
    <div class="sharerow">
      <input type="date" name="d" required min="<?= h($today) ?>" value="<?= h($quote ? $qd : $today) ?>">
      <button class="btn btn--sm btn--ghost">Show payoff</button>
    </div>
    <?php if ($quote): ?>
      <p>Payoff on <?= h(nice_date($qd)) ?>: <strong><?= money($quote['payoff']) ?></strong>
        <span class="muted">(principal <?= money($quote['principal']) ?> + interest <?= money($quote['interest']) ?><?= $quote['fees'] ? ' + fees ' . money($quote['fees']) : '' ?>), assuming no other payments before then.</span></p>
    <?php endif; ?>
  </form>
  <?php endif; ?>

  <h2 class="h3">Payment history</h2>
  <div class="tablewrap">
  <table class="ledger">
    <thead><tr><th>Date</th><th>Item</th><th class="r">Amount</th><th class="r hide-sm">To interest</th><th class="r hide-sm">To principal</th><th class="r">Principal left</th></tr></thead>
    <tbody>
    <?php foreach ($c['rows'] as $r): ?>
      <tr>
        <td><?= h(nice_date($r['date'])) ?></td>
        <td><?= $r['type'] === 'start' ? 'Loan funded' : h(LC_ENTRY_TYPES[$r['type']] ?? $r['type']) ?></td>
        <td class="r"><?= money($r['amount']) ?></td>
        <td class="r hide-sm"><?= ($r['to_interest'] || $r['to_fees']) ? money($r['to_interest'] + $r['to_fees']) : '' ?></td>
        <td class="r hide-sm"><?= $r['to_principal'] ? money($r['to_principal']) : '' ?></td>
        <td class="r"><?= money($r['principal']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <p class="muted portal-foot">Figures as of <?= h(nice_date($asOf)) ?>. Questions about this loan? Contact Mark directly. Please keep this link private.</p>
</main>
<?php page_foot(A);
