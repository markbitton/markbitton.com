<?php
require __DIR__ . '/lib/bootstrap.php';
require_login();

$id = (int)($_GET['id'] ?? 0);
$loan = load_loan($id);
$self = 'loan.php?id=' . $id;

// Payoff quote for any date (used by the payment form).
if (isset($_GET['quote'])) {
    header('Content-Type: application/json');
    $d = (string)$_GET['quote'];
    if (!lc_date($d) || $d < $loan['start_date']) { echo json_encode(['error' => 'bad date']); exit; }
    $c = lc_compute($loan, load_entries($id), $d);
    echo json_encode(['payoff' => $c['payoff'], 'payoff_fmt' => money($c['payoff']), 'interest_fmt' => money($c['interest'])]);
    exit;
}

$errors = [];
$editForm = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = post('action');

    if ($action === 'entry') {
        $type = post('type');
        $date = post('entry_date');
        $amtRaw = post('amount');
        if (!isset(LC_ENTRY_TYPES[$type])) $errors[] = 'Pick what kind of entry this is.';
        if (!lc_date($date)) $errors[] = 'Enter a valid date.';
        elseif ($date < $loan['start_date']) $errors[] = 'The date is before the loan was funded.';
        $amt = parse_money($amtRaw);
        if ($type === 'payoff' && $amtRaw === '' && lc_date($date)) {
            $amt = lc_compute($loan, load_entries($id), $date)['payoff'];
        }
        if (!$amt) $errors[] = 'Enter an amount, e.g. 1500 or 1,500.00.';
        if (!$errors) {
            $db = db();
            $db->beginTransaction();
            $db->prepare('INSERT INTO entries (loan_id, entry_date, type, amount_cents, note, created_at) VALUES (?,?,?,?,?,?)')
               ->execute([$id, $date, $type, $amt, post('note'), now_stamp()]);
            if ($type === 'payoff') {
                $db->prepare("UPDATE loans SET status = 'paid_off', closed_date = ?, updated_at = ? WHERE id = ?")
                   ->execute([$date, now_stamp(), $id]);
                $after = lc_compute(load_loan($id), load_entries($id), $date);
                $db->commit();
                if ($after['payoff'] > 0) {
                    flash('Payoff of ' . money($amt) . ' recorded and the loan is closed, but it was ' . money($after['payoff'])
                        . ' short of the full payoff. If that was a mistake, delete the payoff below.', 'warn');
                } elseif ($after['credit'] > 0) {
                    flash('Payoff recorded and the loan is closed. The borrower overpaid by ' . money($after['credit']) . '.', 'warn');
                } else {
                    flash('Paid off in full: ' . money($amt) . '. The loan is closed.');
                }
            } else {
                $db->commit();
                flash(LC_ENTRY_TYPES[$type] . ' of ' . money($amt) . ' recorded.');
            }
            redirect($self);
        }
    } elseif ($action === 'delete_entry') {
        $st = db()->prepare('SELECT * FROM entries WHERE id = ? AND loan_id = ?');
        $st->execute([(int)post('entry_id'), $id]);
        $e = $st->fetch();
        if ($e) {
            db()->prepare('DELETE FROM entries WHERE id = ?')->execute([$e['id']]);
            if ($e['type'] === 'payoff') {
                db()->prepare("UPDATE loans SET status = 'active', closed_date = NULL, updated_at = ? WHERE id = ?")->execute([now_stamp(), $id]);
                flash('Payoff deleted. The loan is open again.');
            } else {
                flash('Entry deleted.');
            }
        }
        redirect($self);
    } elseif ($action === 'edit') {
        [$d, $errors] = loan_from_post();
        if (!$errors) {
            db()->prepare('UPDATE loans SET borrower=?, contact=?, collateral=?, principal_cents=?, rate=?, default_rate=?, method=?, daycount=?,
                start_date=?, maturity_date=?, notes=?, updated_at=? WHERE id=?')
              ->execute([$d['borrower'], $d['contact'], $d['collateral'], $d['principal_cents'], $d['rate'], $d['default_rate'], $d['method'],
                $d['daycount'], $d['start_date'], $d['maturity_date'], $d['notes'], now_stamp(), $id]);
            flash('Loan details saved.');
            redirect($self);
        }
        $editForm = $_POST;
        $editForm['_open_more'] = true;
    } elseif ($action === 'share_on') {
        db()->prepare('UPDATE loans SET share_token = ?, share_code = ?, updated_at = ? WHERE id = ?')->execute([bin2hex(random_bytes(24)), lc_new_code(), now_stamp(), $id]);
        flash($loan['share_token'] ? 'New borrower link made. The old link no longer works.' : 'Borrower link made. Copy it and send it to ' . $loan['borrower'] . '.');
        redirect($self);
    } elseif ($action === 'share_off') {
        db()->prepare('UPDATE loans SET share_token = NULL, share_code = NULL, updated_at = ? WHERE id = ?')->execute([now_stamp(), $id]);
        flash('Borrower link turned off.');
        redirect($self);
    } elseif ($action === 'delete_loan') {
        if (post('confirm') === 'DELETE') {
            db()->prepare('DELETE FROM loans WHERE id = ?')->execute([$id]);
            flash('Loan to ' . $loan['borrower'] . ' deleted.');
            redirect('index.php');
        }
        $errors[] = 'Type DELETE in capitals to delete this loan.';
    }
}

// Links made before access codes existed get a code the first time they're viewed.
if ($loan['share_token'] && empty($loan['share_code'])) {
    $loan['share_code'] = lc_new_code();
    db()->prepare('UPDATE loans SET share_code = ? WHERE id = ?')->execute([$loan['share_code'], $id]);
}

$entries = load_entries($id);
$asOf = loan_as_of($loan);
$c = lc_compute($loan, $entries, $asOf);
$closed = $loan['status'] === 'paid_off';
$today = today();

$quoteDate = $_GET['asof'] ?? '';
$quote = (lc_date($quoteDate) && $quoteDate >= $loan['start_date']) ? lc_compute($loan, $entries, $quoteDate) : null;

$terms = rtrim(rtrim(number_format((float)$loan['rate'], 3), '0'), '.') . '% · ' . LC_METHODS[$loan['method']] . ' · ' . LC_DAYCOUNTS[$loan['daycount']];
if ($loan['default_rate'] !== null) $terms .= ' · ' . rtrim(rtrim(number_format((float)$loan['default_rate'], 3), '0'), '.') . '% after maturity';

page_head($loan['borrower'] . ' · Loan Center', true);
?>
<main class="wrap">
  <p class="crumb"><a href="index.php">← All loans</a></p>
  <div class="head">
    <h1><?= h($loan['borrower']) ?>
      <?php if ($closed): ?><span class="tag tag--done">Paid off <?= h(nice_date($loan['closed_date'])) ?></span>
      <?php elseif ($loan['maturity_date'] && $loan['maturity_date'] < $today): ?><span class="tag tag--late">Past due since <?= h(nice_date($loan['maturity_date'])) ?></span><?php endif; ?>
    </h1>
    <p class="muted"><?= money($loan['principal_cents']) ?> funded <?= h(nice_date($loan['start_date'])) ?>
      <?= $loan['maturity_date'] ? ' · matures ' . h(nice_date($loan['maturity_date'])) : ' · no maturity date' ?> · <?= h($terms) ?>
      <?= $loan['collateral'] ? '<br>' . h($loan['collateral']) : '' ?><?= $loan['contact'] ? ' · ' . h($loan['contact']) : '' ?></p>
  </div>

  <?php foreach ($errors as $e): ?><p class="err"><?= h($e) ?></p><?php endforeach; ?>

  <section class="stats">
    <div><span>Principal owed</span><strong><?= money($c['principal']) ?></strong></div>
    <div><span>Accrued interest</span><strong><?= money($c['interest']) ?></strong></div>
    <?php if ($c['fees']): ?><div><span>Fees owed</span><strong><?= money($c['fees']) ?></strong></div><?php endif; ?>
    <div class="hl"><span><?= $closed ? 'Balance left' : 'Payoff today' ?></span><strong><?= money($c['payoff']) ?></strong></div>
    <?php if (!$closed): ?><div><span>Per day</span><strong><?= money($c['per_diem']) ?></strong></div><?php endif; ?>
    <div><span>Received so far</span><strong><?= money($c['total_paid']) ?></strong></div>
    <div><span>Interest earned</span><strong><?= money($c['total_interest']) ?></strong></div>
  </section>

  <div class="two">
    <form method="post" action="<?= h($self) ?>" class="card stack" id="entryForm" data-quote-url="<?= h($self) ?>&quote=">
      <h3>Record a payment</h3>
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="entry">
      <div class="seg" role="radiogroup">
        <?php foreach (LC_ENTRY_TYPES as $k => $label): ?>
          <label><input type="radio" name="type" value="<?= h($k) ?>" <?= $k === ($_POST['type'] ?? 'payment') ? 'checked' : '' ?>><span><?= h($label) ?></span></label>
        <?php endforeach; ?>
      </div>
      <div class="grid g2">
        <label>Date<input type="date" name="entry_date" required value="<?= h($_POST['entry_date'] ?? $today) ?>"></label>
        <label>Amount ($)<input name="amount" inputmode="decimal" value="<?= h($_POST['amount'] ?? '') ?>" placeholder="0.00"></label>
      </div>
      <p class="hint muted" data-payoff-hint></p>
      <label>Note<input name="note" value="<?= h($_POST['note'] ?? '') ?>" placeholder="check #, wire, etc."></label>
      <div><button class="btn">Save</button></div>
      <p class="muted small">Payments go to fees first, then accrued interest, then principal. A payoff closes the loan.</p>
    </form>

    <form method="get" class="card stack">
      <h3>Payoff quote</h3>
      <input type="hidden" name="id" value="<?= (int)$id ?>">
      <label>Payoff on<input type="date" name="asof" required value="<?= h($quoteDate ?: $today) ?>"></label>
      <div><button class="btn btn--ghost">Get quote</button></div>
      <?php if ($quote): ?>
        <dl class="quote">
          <dt>Principal</dt><dd><?= money($quote['principal']) ?></dd>
          <dt>Interest to <?= h(nice_date($quoteDate)) ?></dt><dd><?= money($quote['interest']) ?></dd>
          <?php if ($quote['fees']): ?><dt>Fees</dt><dd><?= money($quote['fees']) ?></dd><?php endif; ?>
          <dt><strong>Payoff</strong></dt><dd><strong><?= money($quote['payoff']) ?></strong></dd>
          <dt>Per day after that</dt><dd><?= money($quote['per_diem']) ?></dd>
        </dl>
        <?php if ($quoteDate < $asOf || ($closed && $quoteDate > $loan['closed_date'])): ?><p class="muted small">Based on entries up to that date.</p><?php endif; ?>
      <?php endif; ?>
    </form>
  </div>

  <h2 class="h3">History</h2>
  <div class="tablewrap">
  <table class="ledger">
    <thead><tr><th>Date</th><th>Entry</th><th class="r">Amount</th><th class="r">Interest accrued</th><th class="r">To interest</th><th class="r">To principal</th><th class="r">Principal left</th><th class="r">Unpaid interest</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($c['rows'] as $r): ?>
      <tr>
        <td><?= h(nice_date($r['date'])) ?></td>
        <td><?= $r['type'] === 'start' ? 'Loan funded' : h(LC_ENTRY_TYPES[$r['type']] ?? $r['type']) ?><?= ($r['note'] && $r['type'] !== 'start') ? '<br><small class="muted">' . h($r['note']) . '</small>' : '' ?></td>
        <td class="r"><?= money($r['amount']) ?></td>
        <td class="r"><?= $r['interest'] ? money($r['interest']) : '' ?></td>
        <td class="r"><?= ($r['to_interest'] || $r['to_fees']) ? money($r['to_interest'] + $r['to_fees']) : '' ?></td>
        <td class="r"><?= $r['to_principal'] ? money($r['to_principal']) : '' ?></td>
        <td class="r"><?= money($r['principal']) ?></td>
        <td class="r"><?= money($r['unpaid']) ?></td>
        <td class="r"><?php if ($r['id']): ?>
          <form method="post" action="<?= h($self) ?>" class="inline" data-confirm="Delete this entry?">
            <?= csrf_field() ?><input type="hidden" name="action" value="delete_entry"><input type="hidden" name="entry_id" value="<?= (int)$r['id'] ?>">
            <button class="link danger" title="Delete entry">Delete</button>
          </form><?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if ($c['interest_since_last'] > 0): ?>
      <tr class="muted">
        <td><?= h(nice_date($asOf)) ?></td><td><em>Accrued to <?= $closed ? 'payoff' : 'today' ?></em></td><td></td>
        <td class="r"><?= money($c['interest_since_last']) ?></td><td></td><td></td>
        <td class="r"><?= money($c['principal']) ?></td><td class="r"><?= money($c['interest']) ?></td><td></td>
      </tr>
    <?php endif; ?>
    </tbody>
  </table>
  </div>

  <div class="card share">
    <h3>Borrower view</h3>
    <?php if ($loan['share_token']): ?>
      <p class="muted small">Anyone with this private link can see this loan's balance, payoff and payment history (not your notes). They can't change anything.</p>
      <div class="sharerow">
        <input readonly value="<?= h(share_url($loan['share_token'])) ?>" data-copy>
        <button type="button" class="btn btn--sm" data-copy-btn>Copy link</button>
        <a class="btn btn--sm btn--ghost" href="<?= h(share_url($loan['share_token'])) ?>" target="_blank" rel="noopener">Open</a>
      </div>
      <p class="small">Or they can go to <strong>markbitton.com</strong>, click <em>Loan Center</em>, and enter access code <strong class="code"><?= h(lc_code_fmt($loan['share_code'])) ?></strong>.</p>
      <div class="sharerow">
        <form method="post" action="<?= h($self) ?>" class="inline" data-confirm="Make a new link and code? The current ones will stop working.">
          <?= csrf_field() ?><input type="hidden" name="action" value="share_on"><button class="link">Make a new link</button>
        </form>
        <form method="post" action="<?= h($self) ?>" class="inline" data-confirm="Turn off the borrower link?">
          <?= csrf_field() ?><input type="hidden" name="action" value="share_off"><button class="link danger">Turn off link</button>
        </form>
      </div>
    <?php else: ?>
      <p class="muted small">Give <?= h($loan['borrower']) ?> a private link to check their balance, payoff and payments any time. View only.</p>
      <form method="post" action="<?= h($self) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="share_on"><button class="btn btn--sm">Create borrower link</button></form>
    <?php endif; ?>
  </div>

  <?php if ($loan['notes']): ?><div class="card"><h3>Notes</h3><p><?= nl2br(h($loan['notes'])) ?></p></div><?php endif; ?>

  <details class="card" <?= $editForm ? 'open' : '' ?>>
    <summary><strong>Edit loan details</strong></summary>
    <form method="post" action="<?= h($self) ?>" class="stack">
      <?= csrf_field() ?><input type="hidden" name="action" value="edit">
      <?php loan_form_fields($editForm ?: $loan) ?>
      <div><button class="btn">Save changes</button></div>
    </form>
    <hr>
    <form method="post" action="<?= h($self) ?>" class="stack danger-zone">
      <?= csrf_field() ?><input type="hidden" name="action" value="delete_loan">
      <label>To delete this loan and all its history, type DELETE<input name="confirm" autocomplete="off"></label>
      <div><button class="btn btn--danger">Delete loan</button></div>
    </form>
  </details>
</main>
<?php page_foot();
