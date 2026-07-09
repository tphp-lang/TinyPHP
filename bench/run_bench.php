#!/usr/bin/env php
<?php
/**
 * TinyPHP Bench Runner
 * 
 * 编译并运行所有基准测试，输出 CSV 表格。
 * 
 * 用法:
 *   php bench/run_bench.php              # 默认 TCC
 *   php bench/run_bench.php gcc          # GCC -O2
 *   php bench/run_bench.php clang        # Clang -O2
 *   php bench/run_bench.php tcc php      # 同时对比 PHP
 */

declare(strict_types=1);

$base   = dirname(__DIR__);
$tphp   = $base . '/tphp.php';
$cc     = $argv[1] ?? 'tcc';
$doPhp  = in_array('php', $argv, true);
$build  = $base . '/build/bench';
$bins   = [];

if (!is_dir($build)) mkdir($build, 0777, true);

// 基准测试列表
$tests = [
    'bench_array' => 'test/bench/bench_array.php',
    'bench_oop'   => 'test/bench/bench_oop.php',
    'bench_tphp'  => 'test/bench/bench_tphp.php',
];

echo "╔══════════════════════════════════════╗\n";
echo "║  TinyPHP Benchmark (cc={$cc})        ║\n";
echo "╚══════════════════════════════════════╝\n\n";

// Phase 1: Compile all
echo "--- Compiling ---\n";
foreach ($tests as $name => $path) {
    $src  = $base . '/' . $path;
    $out  = $build . '/' . $name . '.exe';
    $args = $cc === 'tcc' ? '' : ' -cc ' . escapeshellarg($cc);
    $cmd  = PHP_BINARY . ' ' . escapeshellarg($tphp) . ' '
          . escapeshellarg($src) . $args . ' -o ' . escapeshellarg($out) . ' 2>&1';
    exec($cmd, $o, $r);
    if ($r === 0 && file_exists($out)) {
        echo "  [OK]  {$name}\n";
        $bins[$name] = $out;
    } else {
        echo "  [FAIL] {$name}\n";
    }
}

echo "\n--- Running ---\n";
$results = [];
foreach ($bins as $name => $exe) {
    echo "  {$name}... ";
    $start = hrtime(true);
    exec('"' . $exe . '" 2>&1', $out, $r);
    $elapsed = (hrtime(true) - $start) / 1e6;
    printf("%.1f ms (exit=%d)\n", $elapsed, $r);
    $results[$name] = ['tphp' => $out, 'total_ms' => $elapsed];
}

if ($doPhp) {
    echo "\n--- PHP comparison ---\n";
    foreach ($tests as $name => $path) {
        $src = $base . '/' . $path;
        echo "  {$name}... ";
        $start = hrtime(true);
        exec(PHP_BINARY . ' ' . escapeshellarg($src) . ' 2>&1', $out, $r);
        $elapsed = (hrtime(true) - $start) / 1e6;
        printf("%.1f ms (exit=%d)\n", $elapsed, $r);
        $results[$name]['php'] = $out;
        $results[$name]['php_ms'] = $elapsed;
    }
}

// Phase 3: Print summary
echo "\n═══════════════════════════════════════\n";
echo "  Results ({$cc})";
if ($doPhp) echo " vs PHP " . PHP_VERSION;
echo "\n═══════════════════════════════════════\n\n";

foreach ($results as $name => $data) {
    echo "=== {$name} ===\n";
    foreach ($data['tphp'] as $line) {
        echo "  {$line}\n";
    }
    if (isset($data['total_ms'])) {
        printf("  [Total: %.1f ms]\n", $data['total_ms']);
    }
    if (isset($data['php_ms'])) {
        printf("  [PHP:   %.1f ms, speedup: %.1fx]\n", 
            $data['php_ms'], $data['php_ms'] / max($data['total_ms'], 0.001));
    }
    echo "\n";
}
