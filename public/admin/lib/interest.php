<?php
// Interest math. Money is handled in whole cents; each accrual period is
// rounded to the cent once.

const LC_DAYCOUNTS = [
    'act365' => 'Actual/365',
    'act360' => 'Actual/360',
    '30360'  => '30/360',
];
const LC_METHODS = [
    'simple'   => 'Simple interest',
    'compound' => 'Compound daily',
];
const LC_ENTRY_TYPES = [
    'payment' => 'Payment received',
    'payoff'  => 'Payoff',
    'fee'     => 'Fee / charge added',
    'advance' => 'Additional advance',
];

function lc_date($s) {
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', (string)$s);
    return ($d && $d->format('Y-m-d') === $s) ? $d : null;
}

// Days between two Y-m-d dates under a day-count convention.
function lc_days($from, $to, $daycount) {
    $a = lc_date($from); $b = lc_date($to);
    if (!$a || !$b || $b <= $a) return 0;
    if ($daycount === '30360') {
        $d1 = min((int)$a->format('j'), 30);
        $d2 = (int)$b->format('j');
        if ($d2 === 31 && $d1 >= 30) $d2 = 30;
        return 360 * ((int)$b->format('Y') - (int)$a->format('Y'))
             + 30 * ((int)$b->format('n') - (int)$a->format('n'))
             + ($d2 - $d1);
    }
    return (int)$a->diff($b)->days;
}

function lc_year_basis($daycount) {
    return $daycount === 'act365' ? 365 : 360;
}

// Interest in cents (unrounded float) for one stretch at one rate.
function lc_interest_piece($principal, $unpaid, $ratePct, $days, $loan) {
    if ($days <= 0 || $ratePct <= 0) return 0.0;
    $daily = ($ratePct / 100) / lc_year_basis($loan['daycount']);
    if ($loan['method'] === 'compound') {
        $base = $principal + $unpaid;
        return $base * (pow(1 + $daily, $days) - 1);
    }
    return $principal * $daily * $days;
}

// Interest accrued between two dates, splitting at maturity when a
// default rate applies after maturity.
function lc_accrue($loan, $principal, $unpaid, $from, $to) {
    $mat = $loan['maturity_date'];
    $def = $loan['default_rate'];
    if ($def !== null && $def !== '' && $mat && $to > $mat) {
        $total = 0.0;
        if ($from < $mat) {
            $d = lc_days($from, $mat, $loan['daycount']);
            $total += lc_interest_piece($principal, $unpaid, (float)$loan['rate'], $d, $loan);
            $from = $mat;
            if ($loan['method'] === 'compound') $unpaid += $total;
        }
        $d = lc_days($from, $to, $loan['daycount']);
        $total += lc_interest_piece($principal, $unpaid, (float)$def, $d, $loan);
        return (int)round($total);
    }
    $d = lc_days($from, $to, $loan['daycount']);
    return (int)round(lc_interest_piece($principal, $unpaid, (float)$loan['rate'], $d, $loan));
}

// Walks the loan from its start date to $asOf. Returns balances and a ledger.
// Payments go to fees first, then accrued interest, then principal.
function lc_compute($loan, $entries, $asOf) {
    $P = (int)$loan['principal_cents'];
    $I = 0; $F = 0; $credit = 0;
    $last = $loan['start_date'];
    $totInterest = 0; $totPaid = 0;
    $rows = [[
        'id' => null, 'date' => $loan['start_date'], 'type' => 'start', 'amount' => $P,
        'interest' => 0, 'to_fees' => 0, 'to_interest' => 0, 'to_principal' => 0,
        'principal' => $P, 'unpaid' => 0, 'fees' => 0, 'note' => 'Loan funded',
    ]];

    usort($entries, function ($a, $b) {
        return [$a['entry_date'], $a['id']] <=> [$b['entry_date'], $b['id']];
    });

    foreach ($entries as $e) {
        if ($e['entry_date'] > $asOf) continue;
        $from = max($last, $loan['start_date']);
        $acc = $e['entry_date'] > $from ? lc_accrue($loan, $P, $I, $from, $e['entry_date']) : 0;
        $I += $acc; $totInterest += $acc;
        if ($e['entry_date'] > $last) $last = $e['entry_date'];
        $amt = (int)$e['amount_cents'];
        $toF = $toI = $toP = 0;
        if ($e['type'] === 'fee') {
            $F += $amt;
        } elseif ($e['type'] === 'advance') {
            $P += $amt;
        } else {
            $left = $amt;
            $toF = min($left, $F); $F -= $toF; $left -= $toF;
            $toI = min($left, $I); $I -= $toI; $left -= $toI;
            $toP = min($left, $P); $P -= $toP; $left -= $toP;
            $credit += $left;
            $totPaid += $amt;
        }
        $rows[] = [
            'id' => $e['id'], 'date' => $e['entry_date'], 'type' => $e['type'], 'amount' => $amt,
            'interest' => $acc, 'to_fees' => $toF, 'to_interest' => $toI, 'to_principal' => $toP,
            'principal' => $P, 'unpaid' => $I, 'fees' => $F, 'note' => $e['note'],
        ];
    }

    $acc = $asOf > $last ? lc_accrue($loan, $P, $I, $last, $asOf) : 0;
    $I += $acc; $totInterest += $acc;

    // Interest for one more day, as of today's balance (per diem).
    $rateNow = (float)$loan['rate'];
    if ($loan['default_rate'] !== null && $loan['default_rate'] !== ''
        && $loan['maturity_date'] && $asOf >= $loan['maturity_date']) {
        $rateNow = (float)$loan['default_rate'];
    }
    $base = $loan['method'] === 'compound' ? $P + $I : $P;
    $perDiem = (int)round($base * ($rateNow / 100) / lc_year_basis($loan['daycount']));

    return [
        'as_of' => $asOf,
        'principal' => $P,
        'interest' => $I,
        'interest_since_last' => $acc,
        'fees' => $F,
        'payoff' => max(0, $P + $I + $F),
        'credit' => $credit,
        'per_diem' => $perDiem,
        'total_interest' => $totInterest,
        'total_paid' => $totPaid,
        'rows' => $rows,
    ];
}
