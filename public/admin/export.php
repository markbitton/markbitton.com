<?php
require __DIR__ . '/lib/bootstrap.php';
require_login();
$loans = db()->query('SELECT * FROM loans ORDER BY start_date, id')->fetchAll();
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="loans-' . today() . '.csv"');
$out = fopen('php://output', 'w');
$cell = function ($v) { // keep spreadsheets from treating text as formulas
    $v = (string)$v;
    return ($v !== '' && strpos('=+-@', $v[0]) !== false && !is_numeric($v)) ? "'" . $v : $v;
};
fputcsv($out, ['Loan #', 'Borrower', 'Status', 'Funded', 'Maturity', 'Amount loaned', 'Rate %', 'Default rate %', 'Method', 'Day count',
               'As of', 'Principal owed', 'Accrued interest', 'Fees owed', 'Payoff', 'Total paid', 'Total interest earned', 'Collateral', 'Notes']);
foreach ($loans as $l) {
    $c = lc_compute($l, load_entries($l['id']), loan_as_of($l));
    fputcsv($out, array_map($cell, [$l['id'], $l['borrower'], $l['status'] === 'paid_off' ? 'Paid off' : 'Active', $l['start_date'], $l['maturity_date'],
        $l['principal_cents'] / 100, $l['rate'], $l['default_rate'], LC_METHODS[$l['method']] ?? $l['method'], LC_DAYCOUNTS[$l['daycount']] ?? $l['daycount'],
        $c['as_of'], $c['principal'] / 100, $c['interest'] / 100, $c['fees'] / 100, $c['payoff'] / 100, $c['total_paid'] / 100, $c['total_interest'] / 100,
        $l['collateral'], $l['notes']]));
}
fputcsv($out, []);
fputcsv($out, ['Loan #', 'Borrower', 'Date', 'Type', 'Amount', 'Note']);
foreach (db()->query('SELECT e.*, l.borrower FROM entries e JOIN loans l ON l.id = e.loan_id ORDER BY e.loan_id, e.entry_date, e.id') as $e) {
    fputcsv($out, array_map($cell, [$e['loan_id'], $e['borrower'], $e['entry_date'], LC_ENTRY_TYPES[$e['type']] ?? $e['type'], $e['amount_cents'] / 100, $e['note']]));
}
