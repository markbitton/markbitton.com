<?php
require __DIR__ . '/../public/admin/lib/interest.php';
function ok($c,$m){ if(!$c) $GLOBALS["__fail"]=1; echo ($c?"PASS ":"FAIL ").$m."\n"; }
$L = ['principal_cents'=>10000000,'rate'=>12,'default_rate'=>null,'method'=>'simple','daycount'=>'act365','start_date'=>'2026-01-01','maturity_date'=>'2026-07-01'];
// $100k at 12% simple act/365 for 365 days = $12,000
$c = lc_compute($L, [], '2027-01-01'); ok($c['interest']===1200000, "1yr simple ".$c['interest']);
ok($c['per_diem']===3288, "per diem ".$c['per_diem']);
// 31 days
$c = lc_compute($L, [], '2026-02-01'); ok($c['interest']===(int)round(10000000*.12*31/365), "31d ".$c['interest']);
// payment covering interest then principal
$E = [['id'=>1,'entry_date'=>'2026-02-01','type'=>'payment','amount_cents'=>1101918,'note'=>'']];
$c = lc_compute($L, $E, '2026-02-01'); ok($c['interest']===0 && $c['principal']===10000000-(1101918-101918), "pay split P=".$c['principal']." I=".$c['interest']);
// interest-only payment then payoff
$E = [['id'=>1,'entry_date'=>'2026-02-01','type'=>'payment','amount_cents'=>101918,'note'=>'']];
$c = lc_compute($L, $E, '2026-03-01'); ok($c['principal']===10000000 && $c['interest']===(int)round(10000000*.12*28/365), "after IO, Feb accrual ".$c['interest']);
// fee first allocation
$E = [['id'=>1,'entry_date'=>'2026-01-10','type'=>'fee','amount_cents'=>50000,'note'=>''],['id'=>2,'entry_date'=>'2026-01-10','type'=>'payment','amount_cents'=>60000,'note'=>'']];
$c = lc_compute($L, $E, '2026-01-10'); ok($c['fees']===0 && $c['interest']===(int)round(10000000*.12*9/365)-10000, "fee first I=".$c['interest']);
// act360
$L2 = $L; $L2['daycount']='act360'; $c = lc_compute($L2, [], '2026-01-31'); ok($c['interest']===100000, "act360 30d ".$c['interest']);
// 30/360: Jan1->Jul1 = 180 days → 6% of 100k = 6000
$L3 = $L; $L3['daycount']='30360'; $c = lc_compute($L3, [], '2026-07-01'); ok($c['interest']===600000, "30/360 6mo ".$c['interest']);
ok(lc_days('2026-01-30','2026-02-28','30360')===28 && lc_days('2026-01-31','2026-03-31','30360')===60, "30/360 edge");
// default rate after maturity: 181 days at 12, then 10 days at 24
$L4 = $L; $L4['default_rate']=24; $c = lc_compute($L4, [], '2026-07-11');
ok($c['interest']===(int)round(10000000*.12*181/365 + 10000000*.24*10/365), "default rate ".$c['interest']);
ok($c['per_diem']===(int)round(10000000*.24/365), "default per diem");
// compound daily 365d @12%: (1+.12/365)^365-1
$L5 = $L; $L5['method']='compound'; $c = lc_compute($L5, [], '2027-01-01'); ok($c['interest']===(int)round(10000000*(pow(1+.12/365,365)-1)), "compound ".$c['interest']);
// payoff equals full
$c = lc_compute($L, [], '2026-04-15'); $po=$c['payoff'];
$c2 = lc_compute($L, [['id'=>1,'entry_date'=>'2026-04-15','type'=>'payoff','amount_cents'=>$po,'note'=>'']], '2026-04-15'); ok($c2['payoff']===0 && $c2['credit']===0, "payoff zeroes");
// advance
$c = lc_compute($L, [['id'=>1,'entry_date'=>'2026-02-01','type'=>'advance','amount_cents'=>2000000,'note'=>'']], '2026-03-01');
ok($c['principal']===12000000 && $c['interest']===(int)round(10000000*.12*31/365)+(int)round(12000000*.12*28/365), "advance");
// entries after asOf ignored
$c = lc_compute($L, [['id'=>1,'entry_date'=>'2026-05-01','type'=>'payment','amount_cents'=>500000,'note'=>'']], '2026-03-01'); ok($c['total_paid']===0, "future ignored");
exit(isset($GLOBALS['__fail']) ? 1 : 0);
