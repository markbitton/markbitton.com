<?php
require __DIR__ . '/lib/bootstrap.php';
require_login();

$errors = [];
$form = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    [$d, $errors] = loan_from_post();
    if (!$errors) {
        $st = db()->prepare('INSERT INTO loans (borrower, contact, collateral, principal_cents, rate, default_rate, method, daycount,
            start_date, maturity_date, notes, created_at, updated_at, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $st->execute([$d['borrower'], $d['contact'], $d['collateral'], $d['principal_cents'], $d['rate'], $d['default_rate'],
            $d['method'], $d['daycount'], $d['start_date'], $d['maturity_date'], $d['notes'], now_stamp(), now_stamp(), current_user()['id']]);
        flash('Loan to ' . $d['borrower'] . ' added.');
        redirect('loan.php?id=' . db()->lastInsertId());
    }
    $form = $_POST;
    $form['_open_more'] = true;
}

$show = ($_GET['show'] ?? '') === 'all' ? 'all' : (($_GET['show'] ?? '') === 'closed' ? 'closed' : 'active');
$where = $show === 'all' ? '' : ($show === 'closed' ? "WHERE status = 'paid_off'" : "WHERE status = 'active'");
$loans = db()->query("SELECT * FROM loans $where ORDER BY status, COALESCE(maturity_date, '9999'), start_date")->fetchAll();
$counts = db()->query("SELECT status, COUNT(*) n FROM loans GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);

$tot = ['principal' => 0, 'interest' => 0, 'payoff' => 0, 'per_diem' => 0];
$rows = [];
foreach ($loans as $l) {
    $c = lc_compute($l, load_entries($l['id']), loan_as_of($l));
    if ($l['status'] === 'active') foreach ($tot as $k => $_) $tot[$k] += $c[$k];
    $rows[] = [$l, $c];
}
$today = today();

page_head('Loan Center', true);
?>
<main class="wrap">
  <?php $me = current_user(); if (!empty($me['must_change'])): ?>
    <p class="flash flash--warn">You're using the starting password. <a href="password.php">Change it now</a>.</p>
  <?php elseif (empty($me['email'])): ?>
    <p class="flash flash--warn">Add your email on the <a href="password.php">Account</a> page. You'll need it to sign in once there are other admins, and to reset a forgotten password.</p>
  <?php endif; ?>
  <div class="head">
    <h1>Loans</h1>
    <p class="muted">Balances as of today, <?= h(nice_date($today)) ?>.</p>
  </div>

  <?php if (($counts['active'] ?? 0) > 0): ?>
  <section class="stats">
    <div><span>Principal out</span><strong><?= money($tot['principal']) ?></strong></div>
    <div><span>Accrued interest</span><strong><?= money($tot['interest']) ?></strong></div>
    <div><span>Total payoff</span><strong><?= money($tot['payoff']) ?></strong></div>
    <div><span>Earning per day</span><strong><?= money($tot['per_diem']) ?></strong></div>
  </section>
  <?php endif; ?>

  <details class="card newloan" <?= ($errors || !$loans) ? 'open' : '' ?>>
    <summary><span class="btn btn--sm">+ New loan</span></summary>
    <form method="post" class="stack">
      <?= csrf_field() ?>
      <?php foreach ($errors as $e): ?><p class="err"><?= h($e) ?></p><?php endforeach; ?>
      <?php loan_form_fields($form) ?>
      <div><button class="btn">Save loan</button></div>
    </form>
  </details>

  <nav class="tabs">
    <a href="?show=active" class="<?= $show === 'active' ? 'on' : '' ?>">Active (<?= (int)($counts['active'] ?? 0) ?>)</a>
    <a href="?show=closed" class="<?= $show === 'closed' ? 'on' : '' ?>">Paid off (<?= (int)($counts['paid_off'] ?? 0) ?>)</a>
    <a href="?show=all" class="<?= $show === 'all' ? 'on' : '' ?>">All</a>
  </nav>

  <?php if (!$rows): ?>
    <p class="muted empty"><?= $show === 'active' ? 'No active loans yet. Use “New loan” above.' : 'Nothing here yet.' ?></p>
  <?php else: ?>
  <div class="tablewrap">
  <table class="loans">
    <thead><tr><th>Borrower</th><th class="hide-sm">Funded</th><th>Matures</th><th class="r hide-sm">Rate</th><th class="r hide-sm">Principal</th><th class="r hide-sm">Interest</th><th class="r">Payoff</th></tr></thead>
    <tbody>
    <?php foreach ($rows as [$l, $c]):
        $tag = '';
        if ($l['status'] === 'paid_off') {
            $tag = '<span class="tag tag--done">Paid off ' . h(nice_date($l['closed_date'])) . '</span>';
        } elseif ($l['maturity_date'] && $l['maturity_date'] < $today) {
            $tag = '<span class="tag tag--late">' . lc_days($l['maturity_date'], $today, 'act365') . ' days past due</span>';
        } elseif ($l['maturity_date'] && lc_days($today, $l['maturity_date'], 'act365') <= 30) {
            $tag = '<span class="tag tag--soon">Due in ' . lc_days($today, $l['maturity_date'], 'act365') . ' days</span>';
        }
    ?>
      <tr data-href="loan.php?id=<?= (int)$l['id'] ?>">
        <td><a href="loan.php?id=<?= (int)$l['id'] ?>"><strong><?= h($l['borrower']) ?></strong></a><?= $l['share_token'] ? ' <span class="tag tag--share" title="Borrower link is on">shared</span>' : '' ?><?= $l['collateral'] ? '<br><small class="muted">' . h($l['collateral']) . '</small>' : '' ?></td>
        <td class="hide-sm"><?= h(nice_date($l['start_date'])) ?></td>
        <td><?= $l['maturity_date'] ? h(nice_date($l['maturity_date'])) : '<span class="muted">Open</span>' ?><?= $tag ? '<br>' . $tag : '' ?></td>
        <td class="r hide-sm"><?= h(rtrim(rtrim(number_format((float)$l['rate'], 3), '0'), '.')) ?>%</td>
        <td class="r hide-sm"><?= money($c['principal']) ?></td>
        <td class="r hide-sm"><?= money($c['interest']) ?></td>
        <td class="r"><strong><?= money($c['payoff']) ?></strong></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>
</main>
<?php page_foot();
