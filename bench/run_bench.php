#!/usr/bin/env php
<?php
/**
 * TinyPHP 统一基准测试执行器
 *
 * 用法:
 *   php bench/run_bench.php                  # 默认 TCC，仅 TinyPHP
 *   php bench/run_bench.php gcc              # GCC -O2
 *   php bench/run_bench.php clang            # Clang -O2
 *   php bench/run_bench.php tcc php          # 同时跑 PHP 原生对比
 *   php bench/run_bench.php gcc php json     # 只跑 json 这一项
 *
 * 输出:
 *   - 控制台实时进度
 *   - build/bench/results_<cc>.txt 完整结果
 *   - build/bench/results_<cc>.csv 机器可读格式
 */

declare(strict_types=1);

$base    = dirname(__DIR__);
$tphp    = $base . '/tphp.php';
$cc      = $argv[1] ?? 'tcc';
$doPhp   = in_array('php', $argv, true);
$filter  = array_values(array_filter(array_slice($argv, 2), fn($x) => $x !== 'php'));
$build   = $base . '/build/bench';
$stamp   = date('Y-m-d H:i:s');

if (!is_dir($build)) mkdir($build, 0777, true);

// ── 基准测试注册表 ──
// 字段: name, src, supports_php (是否可用 PHP 原生对比)
$tests = [
    ['name' => 'array',    'src' => 'test/bench/bench_array.php',   'php' => true],
    ['name' => 'tphp_arr', 'src' => 'test/bench/bench_tphp.php',    'php' => true],
    ['name' => 'oop',      'src' => 'test/bench/bench_oop.php',     'php' => true],
    ['name' => 'str_key',  'src' => 'test/bench/bench_str_key.php', 'php' => true],
    ['name' => 'string',   'src' => 'test/bench/bench_string.php',  'php' => true],
    ['name' => 'hash',     'src' => 'test/bench/bench_hash.php',    'php' => true],
    ['name' => 'sqlite',   'src' => 'test/bench/bench_sqlite.php',  'php' => false], // PDO API 不兼容
    ['name' => 'json',     'src' => 'bench/json_bench.php',         'php' => true],
];

if (!empty($filter)) {
    $tests = array_values(array_filter($tests, fn($t) => in_array($t['name'], $filter, true)));
}

$ccLabel = ['tcc' => 'TCC', 'gcc' => 'GCC -O2', 'clang' => 'Clang -O2'][$cc] ?? $cc;

// ── 打印头部 ──
printHeader($ccLabel, $doPhp, $stamp);

// ── Phase 1: 编译 TinyPHP ──
echo "--- Compiling ({$ccLabel}) ---\n";
$bins = [];
foreach ($tests as $t) {
    $src = $base . '/' . $t['src'];
    $out = $build . '/bench_' . $t['name'] . '.exe';
    $args = $cc === 'tcc' ? '' : ' -cc ' . escapeshellarg($cc);
    $cmd = PHP_BINARY . ' ' . escapeshellarg($tphp) . ' '
         . escapeshellarg($src) . $args . ' -o ' . escapeshellarg($out) . ' 2>&1';
    exec($cmd, $o, $r);
    if ($r === 0 && file_exists($out)) {
        echo "  [OK]   {$t['name']}\n";
        $bins[$t['name']] = $out;
    } else {
        echo "  [FAIL] {$t['name']}\n";
        if (!empty($o)) echo '         ' . implode("\n         ", array_slice($o, 0, 3)) . "\n";
    }
}

// ── Phase 2: 运行 TinyPHP 基准 ──
echo "\n--- Running TinyPHP ---\n";
$tpResults = [];
foreach ($bins as $name => $exe) {
    echo "  {$name}... ";
    $start = hrtime(true);
    exec('"' . $exe . '" 2>&1', $out, $r);
    $elapsed = (hrtime(true) - $start) / 1e6;
    printf("%.0f ms (exit=%d)\n", $elapsed, $r);
    $tpResults[$name] = ['output' => $out, 'total_ms' => $elapsed];
}

// ── Phase 3: 运行 PHP 原生对比 ──
$phpResults = [];
if ($doPhp) {
    echo "\n--- Running PHP " . PHP_VERSION . " ---\n";
    foreach ($tests as $t) {
        if (!$t['php']) continue;
        $src = $base . '/' . $t['src'];
        echo "  {$t['name']}... ";
        $start = hrtime(true);
        exec(PHP_BINARY . ' ' . escapeshellarg($src) . ' 2>&1', $out, $r);
        $elapsed = (hrtime(true) - $start) / 1e6;
        printf("%.0f ms (exit=%d)\n", $elapsed, $r);
        $phpResults[$t['name']] = ['output' => $out, 'total_ms' => $elapsed];
    }
}

// ── Phase 4: 输出汇总 ──
echo "\n" . str_repeat("═", 60) . "\n";
echo "  Results Summary — {$ccLabel}";
if ($doPhp) echo " vs PHP " . PHP_VERSION;
echo "\n" . str_repeat("═", 60) . "\n\n";

foreach ($tests as $t) {
    $name = $t['name'];
    if (!isset($tpResults[$name])) continue;
    echo "── {$name} ──\n";
    foreach ($tpResults[$name]['output'] as $line) {
        echo "  {$line}\n";
    }
    printf("  [TinyPHP total: %.0f ms]\n", $tpResults[$name]['total_ms']);
    if (isset($phpResults[$name])) {
        $ratio = $phpResults[$name]['total_ms'] / max($tpResults[$name]['total_ms'], 0.001);
        printf("  [PHP total:     %.0f ms, speedup: %.2fx]\n",
            $phpResults[$name]['total_ms'], $ratio);
    }
    echo "\n";
}

// ── Phase 5: 写入结果文件 ──
$txtPath = $build . "/results_{$cc}.txt";
$csvPath = $build . "/results_{$cc}.csv";

$txt = "TinyPHP Benchmark Results\n";
$txt .= "Date: {$stamp}\n";
$txt .= "Compiler: {$ccLabel}\n";
if ($doPhp) $txt .= "PHP: " . PHP_VERSION . "\n";
$txt .= str_repeat("-", 60) . "\n\n";

foreach ($tests as $t) {
    $name = $t['name'];
    if (!isset($tpResults[$name])) continue;
    $txt .= "=== {$name} ===\n";
    $txt .= "[TinyPHP]\n";
    foreach ($tpResults[$name]['output'] as $line) $txt .= "  {$line}\n";
    if (isset($phpResults[$name])) {
        $txt .= "[PHP " . PHP_VERSION . "]\n";
        foreach ($phpResults[$name]['output'] as $line) $txt .= "  {$line}\n";
    }
    $txt .= "\n";
}
file_put_contents($txtPath, $txt);

// CSV: name, compiler, total_ms, php_total_ms, speedup
$csv = "name,compiler,total_ms,php_total_ms,speedup\n";
foreach ($tests as $t) {
    $name = $t['name'];
    if (!isset($tpResults[$name])) continue;
    $tpMs = $tpResults[$name]['total_ms'];
    $phpMs = $phpResults[$name]['total_ms'] ?? '';
    $speedup = isset($phpResults[$name]) ? sprintf("%.2f", $phpResults[$name]['total_ms'] / max($tpMs, 0.001)) : '';
    $csv .= "{$name},{$ccLabel},{$tpMs},{$phpMs},{$speedup}\n";
}
file_put_contents($csvPath, $csv);

echo "结果已保存:\n  {$txtPath}\n  {$csvPath}\n";

// ── 辅助函数 ──
function printHeader(string $ccLabel, bool $doPhp, string $stamp): void
{
    echo "\n";
    echo "╔══════════════════════════════════════════════════╗\n";
    echo "║  TinyPHP Benchmark Suite                         ║\n";
    echo "║  Compiler: {$ccLabel}";
    echo str_repeat(" ", max(0, 31 - strlen($ccLabel)));
    echo "║\n";
    echo "║  PHP Compare: " . ($doPhp ? "YES" : "NO");
    echo str_repeat(" ", 32 - 3);
    echo "║\n";
    echo "║  Time: {$stamp}";
    echo str_repeat(" ", max(0, 36 - strlen($stamp)));
    echo "║\n";
    echo "╚══════════════════════════════════════════════════╝\n\n";
}
