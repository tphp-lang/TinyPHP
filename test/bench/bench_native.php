<?php // @skip — native PHP array benchmark
declare(strict_types=1);

$loops = 100000;
echo "PHP Native Array Benchmark ({$loops} iterations)\n============================================================\n\n";

// 1. 数组创建
$t1 = hrtime(true);
for ($r1 = 0; $r1 < $loops / 1000; $r1++) { $a1 = []; for ($j1 = 0; $j1 < 1000; $j1++) $a1[] = $j1; }
echo "1. create (push 1000 x " . ($loops/1000) . "):  " . (hrtime(true)-$t1) . " ns\n";

// 2. int key 读取
$a2 = []; for ($j2 = 0; $j2 < 1000; $j2++) $a2[$j2] = $j2 * 2;
$t1 = hrtime(true); $sum2 = 0;
for ($r2 = 0; $r2 < $loops; $r2++) $sum2 += $a2[($r2 % 1000)];
echo "2. read int key x {$loops}:       " . (hrtime(true)-$t1) . " ns  (sum={$sum2})\n";

// 3. array_push
$a3 = []; $t1 = hrtime(true);
for ($j3 = 0; $j3 < $loops; $j3++) $a3[] = $j3;
echo "3. array_push x {$loops}:         " . (hrtime(true)-$t1) . " ns  (len=" . count($a3) . ")\n";

// 4. array_pop
$t1 = hrtime(true);
while (count($a3) > 0) $dummy = array_pop($a3);
echo "4. array_pop x {$loops}:          " . (hrtime(true)-$t1) . " ns\n";

// 5. foreach 1K
$a5 = []; for ($j5 = 0; $j5 < 1000; $j5++) $a5[$j5] = $j5;
$t1 = hrtime(true); $sum5 = 0;
for ($r5 = 0; $r5 < $loops; $r5++) { foreach ($a5 as $v5) $sum5 += $v5; }
echo "5. foreach 1K x {$loops}:         " . (hrtime(true)-$t1) . " ns  (sum={$sum5})\n";

// 6. in_array
$a6 = []; for ($j6 = 0; $j6 < 1000; $j6++) $a6[$j6] = $j6 * 3;
$needle = 1500; $t1 = hrtime(true); $found = 0;
for ($r6 = 0; $r6 < $loops; $r6++) { if (in_array($needle, $a6)) $found++; }
echo "6. in_array x {$loops}:           " . (hrtime(true)-$t1) . " ns  (found={$found})\n";

// 7. array_merge
$a7a = [1,2,3,4,5]; $a7b = [6,7,8,9,10]; $t1 = hrtime(true);
for ($r7 = 0; $r7 < $loops/10; $r7++) $c7 = array_merge($a7a, $a7b);
echo "7. array_merge x " . ($loops/10) . ":        " . (hrtime(true)-$t1) . " ns\n";

// 8. explode+implode
$s8 = "1,2,3,4,5,6,7,8,9,10"; $t1 = hrtime(true);
for ($r8 = 0; $r8 < $loops/10; $r8++) { $parts = explode(",", $s8); $back = implode("-", $parts); }
echo "8. explode+implode x " . ($loops/10) . ":    " . (hrtime(true)-$t1) . " ns\n";

// 9. 嵌套数组读
$grid = [[0,1,2,3,4,5,6,7,8,9]]; $t1 = hrtime(true); $sum9 = 0;
for ($r9 = 0; $r9 < $loops; $r9++) { $idx = $r9 % 10; $sum9 += $grid[0][$idx]; }
echo "9. nested read x {$loops}: " . (hrtime(true)-$t1) . " ns  (sum={$sum9})\n";

// 10. count+for
$a10 = []; for ($j10 = 0; $j10 < 100; $j10++) $a10[] = $j10;
$t1 = hrtime(true); $sum10 = 0;
for ($r10 = 0; $r10 < $loops; $r10++) { $c10 = count($a10); for ($i10 = 0; $i10 < $c10; $i10++) $sum10 += $a10[$i10]; }
echo "10. count+for x {$loops}:         " . (hrtime(true)-$t1) . " ns  (sum={$sum10})\n";

echo "\nDone.\n";
