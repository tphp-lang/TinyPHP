<?php
// PHP 原生 JSON 性能基准 (与 TinyPHP 匹配的测试)
// 运行: php bench/bench_php_final.php

$N       = 10000;
$N_SMALL = 20000;
$N_DEC   = 5000;
$N_DEC_L = 2000;

echo "===== PHP Native JSON Bench =====\n";
echo "System: Windows x64 | PHP " . PHP_VERSION . " | OPcache\n\n";

// ── 构建数据 (与 TinyPHP 一致) ──
$smallArr = [1,2,3,4,5,6,7,8,9,10];

$largeArr = [];
for ($i = 0; $i < 1000; $i++) $largeArr[] = $i;

$nestedObj = [];
for ($j = 0; $j < 50; $j++) {
    $nestedObj[$j] = [
        'id' => $j,
        'name' => 'user_' . $j,
        'score' => 95.5,
        'active' => true,
        'tags' => [$j, $j+1, $j+2]
    ];
}

$nestedEnc = json_encode($nestedObj);
$smallEnc  = json_encode($smallArr);
$largeEnc  = json_encode($largeArr);

echo "data ready: small=" . count($smallArr) . " large=" . count($largeArr) . " nest=" . count($nestedObj) . "\n";
echo "nestedEnc=" . strlen($nestedEnc) . "B smallEnc=" . strlen($smallEnc) . "B largeEnc=" . strlen($largeEnc) . "B\n\n";

// ═══ json_encode ═══
echo "----- json_encode -----\n";

$t0 = microtime(true);
for ($i = 0; $i < $N_SMALL; $i++) json_encode($smallArr);
show('encode small(10)    ', microtime(true) - $t0, $N_SMALL);

$t0 = microtime(true);
for ($i = 0; $i < $N; $i++) json_encode($largeArr);
show('encode large(1000)  ', microtime(true) - $t0, $N);

$t0 = microtime(true);
for ($i = 0; $i < $N; $i++) json_encode($nestedObj);
show('encode nested(50x5) ', microtime(true) - $t0, $N);

// ═══ json_decode ═══
echo "\n----- json_decode -----\n";

$t0 = microtime(true);
for ($i = 0; $i < $N_SMALL; $i++) json_decode($smallEnc, true);
show('decode small(10)    ', microtime(true) - $t0, $N_SMALL);

$t0 = microtime(true);
for ($i = 0; $i < $N_DEC_L; $i++) json_decode($largeEnc, true);
show('decode large(1000)  ', microtime(true) - $t0, $N_DEC_L);

$t0 = microtime(true);
for ($i = 0; $i < $N_DEC; $i++) json_decode($nestedEnc, true);
show('decode nested(50x5) ', microtime(true) - $t0, $N_DEC);

// ═══ round-trip ═══
echo "\n----- round-trip -----\n";
$RT = 5000;

$t0 = microtime(true);
for ($i = 0; $i < $RT; $i++) {
    json_decode(json_encode($smallArr), true);
}
show('rtrip small(10)     ', microtime(true) - $t0, $RT);

$t0 = microtime(true);
for ($i = 0; $i < $RT; $i++) {
    json_decode(json_encode($nestedObj), true);
}
show('rtrip nested(50x5)  ', microtime(true) - $t0, $RT);

echo "\n===== DONE =====\n";

function show(string $label, float $secs, int $iters): void {
    $us = $secs * 1_000_000.0 / (float)$iters;
    printf("%s %8.6fs  (%7.2fus/op)\n", $label, $secs, $us);
}
