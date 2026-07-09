<?php
// PHP 原生 JSON 基准
define('ITER', 10000);
define('WARM', 1000);

echo "========================================\n";
echo "  PHP 原生 JSON 性能\n";
echo "  PHP " . PHP_VERSION . "  迭代: " . ITER . "\n";
echo "========================================\n\n";

// 小数组
$smallArr = array(1,2,3,4,5,6,7,8,9,10);
// 大数组
$largeArr = array();
for ($i = 0; $i < 1000; $i++) {
    $largeArr[] = $i;
}
// 嵌套数组
$nestedObj = array();
for ($j = 0; $j < 50; $j++) {
    $nestedObj[] = array(
        'id' => $j, 'name' => "user_$j",
        'score' => 95.5, 'active' => true,
        'tags' => array($j, $j+1, $j+2)
    );
}
// 转义字符串
$escapeStr = "hello \"world\"\nline2\tindented\rreturn\\path";

// JSON strings
$smallJson = '[1,2,3,4,5,6,7,8,9,10]';
// Generate large JSON
$largeJson = '[';
for ($k = 0; $k < 1000; $k++) {
    if ($k > 0) { $largeJson .= ','; }
    $largeJson .= (string)$k;
}
$largeJson .= ']';
// Object JSON
$objJson = '{';
for ($m = 0; $m < 50; $m++) {
    if ($m > 0) { $objJson .= ','; }
    $objJson .= '"key_' . $m . '":' . ($m * 10);
}
$objJson .= '}';

// ── json_encode ──
echo "───── json_encode ─────\n";

$s = microtime(true);
for ($w = 0; $w < WARM; $w++) { json_encode($smallArr); }
for ($i = 0; $i < ITER; $i++) { json_encode($smallArr); }
$t = microtime(true) - $s;
printf("  encode(小数组)       %8.4fs  (%7.2fus/op)\n", $t, $t/ITER*1e6);

$s = microtime(true);
for ($w = 0; $w < WARM; $w++) { json_encode($largeArr); }
for ($i = 0; $i < ITER; $i++) { json_encode($largeArr); }
$t = microtime(true) - $s;
printf("  encode(大数组1000)   %8.4fs  (%7.2fus/op)\n", $t, $t/ITER*1e6);

$s = microtime(true);
for ($w = 0; $w < WARM; $w++) { json_encode($nestedObj); }
for ($i = 0; $i < ITER; $i++) { json_encode($nestedObj); }
$t = microtime(true) - $s;
printf("  encode(嵌套对象50)   %8.4fs  (%7.2fus/op)\n", $t, $t/ITER*1e6);

$s = microtime(true);
for ($w = 0; $w < WARM; $w++) { json_encode($escapeStr); }
for ($i = 0; $i < ITER; $i++) { json_encode($escapeStr); }
$t = microtime(true) - $s;
printf("  encode(含转义字符串) %8.4fs  (%7.2fus/op)\n", $t, $t/ITER*1e6);

// ── json_decode ──
echo "\n───── json_decode ─────\n";

$s = microtime(true);
for ($w = 0; $w < WARM; $w++) { json_decode($smallJson, true); }
for ($i = 0; $i < ITER; $i++) { json_decode($smallJson, true); }
$t = microtime(true) - $s;
printf("  decode(小JSON)       %8.4fs  (%7.2fus/op)\n", $t, $t/ITER*1e6);

$s = microtime(true);
for ($w = 0; $w < WARM; $w++) { json_decode($largeJson, true); }
for ($i = 0; $i < ITER; $i++) { json_decode($largeJson, true); }
$t = microtime(true) - $s;
printf("  decode(大JSON1000)   %8.4fs  (%7.2fus/op)\n", $t, $t/ITER*1e6);

$s = microtime(true);
for ($w = 0; $w < WARM; $w++) { json_decode($objJson, true); }
for ($i = 0; $i < ITER; $i++) { json_decode($objJson, true); }
$t = microtime(true) - $s;
printf("  decode(对象JSON50)   %8.4fs  (%7.2fus/op)\n", $t, $t/ITER*1e6);

// ── round-trip ──
echo "\n───── round-trip ─────\n";

$s = microtime(true);
for ($w = 0; $w < WARM/2; $w++) { $e = json_encode($smallArr); json_decode($e, true); }
for ($i = 0; $i < ITER/2; $i++) { $e = json_encode($smallArr); json_decode($e, true); }
$t = microtime(true) - $s;
printf("  round-trip(小数组)   %8.4fs  (%7.2fus/op)\n", $t, $t/ITER*1e6);

$s = microtime(true);
for ($w = 0; $w < WARM/2; $w++) { $e = json_encode($nestedObj); json_decode($e, true); }
for ($i = 0; $i < ITER/2; $i++) { $e = json_encode($nestedObj); json_decode($e, true); }
$t = microtime(true) - $s;
printf("  round-trip(嵌套对象) %8.4fs  (%7.2fus/op)\n", $t, $t/ITER*1e6);

echo "\n=== DONE ===\n";
