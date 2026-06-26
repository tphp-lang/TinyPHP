<?php
/**
 * TinyPHP Test Runner v3 — 直接用 tphp.php 的多文件编译能力
 *
 * Annotations (first 10 lines of entry file):
 *   // @skip          skip
 *   // @exit N        expected exit code (default 0)
 *   // @with a.php,b.php   companion files to compile together
 *
 * Usage: php tester.php                    (all)
 *        php tester.php test/var/string.php  (single)
 */
$base   = __DIR__;
$php    = 'php';
$tphp   = $base . '/tphp.php';
$debug  = in_array('--debug', $argv);
$tmpDir = sys_get_temp_dir() . '/tphp_test';
@mkdir($tmpDir, 0777, true);

// Collect
$targets = array_slice($argv, 1);
$targets = array_filter($targets, fn($a) => !str_starts_with($a, '--'));
if (empty($targets)) {
    $targets = glob($base . '/test/**/*.php');
} else {
    $expanded = [];
    foreach ($targets as $t) {
        $p = $t;
        if (!preg_match('#^[a-zA-Z]:#', $p) && $p[0] !== '/' && $p[0] !== '\\')
            $p = $base . '/' . $p;
        if (is_dir($p)) $expanded = array_merge($expanded, glob($p . '/*.php') ?: []);
        elseif (is_file($p)) $expanded[] = realpath($p);
    }
    $targets = $expanded;
}

$total     = count($targets);
$passed    = 0; $failed = 0; $skipped = 0; $compileF = 0;
$details   = [];
// Multi-file de-dup: only compile once per unique file set
$seen      = [];

echo "TinyPHP Test Runner v3" . ($debug ? " [DEBUG]" : "") . "\n";
echo str_repeat('=', 60) . "\n";
$t0 = microtime(true);

foreach ($targets as $i => $path) {
    if (!$path) continue;
    $name  = basename($path, '.php');
    $dir   = dirname($path);
    $rel   = str_replace('\\', '/', ltrim(substr($path, strlen($base)), '/\\'));
    $exe   = $tmpDir . '/' . $name . '.exe';

    // Read annotations
    $src = @file_get_contents($path);
    if (!$src) { $skipped++; continue; }
    $lines = explode("\n", $src, 12);
    $expectedExit = 0;
    $doSkip = false;
    $withFiles = [];
    foreach ($lines as $line) {
        $line = rtrim($line, "\r\n");
        if (preg_match('/@skip/', $line))      { $doSkip = true; break; }
        if (preg_match('/@exit\s+(\d+)/', $line, $m)) $expectedExit = (int)$m[1];
        if (preg_match('/@with\s+(.+)/', $line, $m)) {
            $withFiles = array_map('trim', explode(',', $m[1]));
        }
    }
    if ($doSkip) { $skipped++; continue; }

    // Build file list: entry + @with companions
    $files = [$path];
    $label = $rel;
    foreach ($withFiles as $wf) {
        $fp = $dir . DIRECTORY_SEPARATOR . $wf;
        if (file_exists($fp) && !in_array($fp, $files)) {
            $files[] = $fp;
            $label .= ' +' . basename($wf, '.php');
        }
    }

    // Dedup multi-file sets
    $key = implode('|', $files);
    if (isset($seen[$key])) continue;
    $seen[$key] = true;

    // Compile: pass ALL files to tphp.php
    $fileArgs = implode(' ', array_map(fn($f) => '"' . $f . '"', $files));
    $cmd = "{$php} \"{$tphp}\" {$fileArgs} -o \"{$exe}\"";
    exec($cmd, $cOut, $cRet);

    if ($debug && !empty($cOut)) {
        echo "  " . implode("\n  ", array_slice($cOut, -2)) . "\n";
    }

    if ($cRet !== 0 || !file_exists($exe) || filesize($exe) < 64) {
        $compileF++;
        $details[] = ['file' => $label, 'status' => 'COMPILE FAIL'];
        // Find the actual error line (with [NO] or Error:)
        $errLine = '';
        foreach ($cOut as $l) {
            if (str_contains($l, '[NO]') || str_contains($l, 'Error:') || str_contains($l, 'error:')) {
                $errLine = trim($l); break;
            }
        }
        echo sprintf("[%3d/%3d] COMPILE FAIL  %s\n", $i + 1, $total, $label);
        if ($errLine) echo "        > {$errLine}\n";
        continue;
    }

    // Run
    $outFile = $tmpDir . '/' . $name . '.out';
    $errFile = $tmpDir . '/' . $name . '.err';
    $start = microtime(true);
    exec('"' . $exe . '" > "' . $outFile . '" 2> "' . $errFile . '"', $dummy, $exitCode);
    $elapsed = round((microtime(true) - $start) * 1000);

    $output = @file_get_contents($outFile) ?: '';
    $errors = @file_get_contents($errFile) ?: '';
    $all    = $output . $errors;
    @unlink($outFile); @unlink($errFile);

    $hasFatal = (stripos($all, 'Fatal error') !== false);
    $hasSegf  = (stripos($all, 'Segmentation fault') !== false);

    if ($hasSegf) {
        $failed++; $status = 'SEGFAULT';
    } elseif ($hasFatal) {
        if ($exitCode === $expectedExit && $expectedExit !== 0) {
            $passed++; $status = 'OK (expected)';
        } else {
            $failed++; $status = "CRASH exit={$exitCode}";
        }
    } elseif ($exitCode !== $expectedExit) {
        $failed++; $status = "WRONG EXIT expected={$expectedExit} got={$exitCode}";
    } else {
        $passed++; $status = 'OK';
    }

    $details[] = ['file' => $label, 'status' => $status, 'ms' => $elapsed];
    echo sprintf("[%3d/%3d] %-14s %s (%dms)\n", $i + 1, $total, $status, $label, $elapsed);
}

$t1 = round((microtime(true) - $t0) * 1000);

echo "\n" . str_repeat('=', 60) . "\n";
printf("  PASS:         %3d\n", $passed);
printf("  FAIL:         %3d\n", $failed);
printf("  COMPILE ERR:  %3d\n", $compileF);
printf("  SKIPPED:      %3d\n", $skipped);
printf("  ───────────────────\n");
printf("  TOTAL:        %3d  (%dms)\n", count($details), $t1);

if ($failed > 0 || $compileF > 0) {
    echo "\nFAILURES:\n";
    foreach ($details as $d)
        if (!str_starts_with($d['status'], 'OK'))
            echo "  {$d['status']}: {$d['file']}\n";
}

// Cleanup
array_map('unlink', glob($tmpDir . '/*') ?: []);
@rmdir($tmpDir);

exit(($failed + $compileF) > 0 ? 1 : 0);
