<?php

// ============================================================
// JSON 性能基准: PHP 原生 vs TinyPHP
// 运行: php bench/bench_json.php
// ============================================================

const int ITER = 10000;
const int WARM = 1000;

// ── 测试数据 ──
$smallArr = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10];
$largeArr = [];
for ($i = 0; $i < 1000; $i++) $largeArr[] = $i;

$nestedObj = [];
for ($j = 0; $j < 50; $j++) {
    $nestedObj[$j] = [
        'id' => $j, 'name' => "user_$j",
        'score' => 95.5, 'active' => true,
        'tags' => [$j, $j+1, $j+2]
    ];
}

$escapeStr = "hello \"world\"\nline2\tindented\rreturn\\path";

// JSON strings for decode
$smallJson  = '[1,2,3,4,5,6,7,8,9,10]';
$largeJson  = '[' . implode(',', range(0, 999)) . ']';
$objJson    = '{' . implode(',', array_map(fn($i) => '"key_'.$i.'":'.($i*10), range(0, 49))) . '}';

echo "========================================\n";
echo "  PHP 原生 JSON 性能基准\n";
echo "  迭代: " . ITER . "  预热: " . WARM . "\n";
echo "========================================\n\n";

// ── encode ──
echo "───── json_encode ─────\n";

$t = -microtime(true);
for ($w = 0; $w < WARM; $w++) json_encode($smallArr);
for ($i = 0; $i < ITER; $i++) json_encode($smallArr);
$t += microtime(true);
printf("  encode(小数组 10元素)    %8.4fs  (%7.2f μs/op)\n", $t, $t/ITER*1e6);

$t = -microtime(true);
for ($w = 0; $w < WARM; $w++) json_encode($largeArr);
for ($i = 0; $i < ITER; $i++) json_encode($largeArr);
$t += microtime(true);
printf("  encode(大数组 1000元素)  %8.4fs  (%7.2f μs/op)\n", $t, $t/ITER*1e6);

$t = -microtime(true);
for ($w = 0; $w < WARM; $w++) json_encode($nestedObj);
for ($i = 0; $i < ITER; $i++) json_encode($nestedObj);
$t += microtime(true);
printf("  encode(嵌套对象 50键)    %8.4fs  (%7.2f μs/op)\n", $t, $t/ITER*1e6);

$t = -microtime(true);
for ($w = 0; $w < WARM; $w++) json_encode($escapeStr);
for ($i = 0; $i < ITER; $i++) json_encode($escapeStr);
$t += microtime(true);
printf("  encode(含转义字符串)     %8.4fs  (%7.2f μs/op)\n", $t, $t/ITER*1e6);

// ── decode ──
echo "\n───── json_decode ─────\n";

$t = -microtime(true);
for ($w = 0; $w < WARM; $w++) json_decode($smallJson, true);
for ($i = 0; $i < ITER; $i++) json_decode($smallJson, true);
$t += microtime(true);
printf("  decode(小JSON 10元素)    %8.4fs  (%7.2f μs/op)\n", $t, $t/ITER*1e6);

$t = -microtime(true);
for ($w = 0; $w < WARM; $w++) json_decode($largeJson, true);
for ($i = 0; $i < ITER; $i++) json_decode($largeJson, true);
$t += microtime(true);
printf("  decode(大JSON 1000元素)  %8.4fs  (%7.2f μs/op)\n", $t, $t/ITER*1e6);

$t = -microtime(true);
for ($w = 0; $w < WARM; $w++) json_decode($objJson, true);
for ($i = 0; $i < ITER; $i++) json_decode($objJson, true);
$t += microtime(true);
printf("  decode(对象JSON 50键)    %8.4fs  (%7.2f μs/op)\n", $t, $t/ITER*1e6);

// ── round-trip ──
echo "\n───── round-trip ─────\n";

$t = -microtime(true);
for ($w = 0; $w < WARM/2; $w++) { $e = json_encode($smallArr); json_decode($e, true); }
for ($i = 0; $i < ITER/2; $i++) { $e = json_encode($smallArr); json_decode($e, true); }
$t += microtime(true);
printf("  round-trip(小数组)       %8.4fs  (%7.2f μs/op)\n", $t, $t/ITER*1e6);

$t = -microtime(true);
for ($w = 0; $w < WARM/2; $w++) { $e = json_encode($nestedObj); json_decode($e, true); }
for ($i = 0; $i < ITER/2; $i++) { $e = json_encode($nestedObj); json_decode($e, true); }
$t += microtime(true);
printf("  round-trip(嵌套对象)     %8.4fs  (%7.2f μs/op)\n", $t, $t/ITER*1e6);

echo "\n========================================\n";
echo "  完成\n";
echo "========================================\n";
