#!/usr/bin/env php
<?php
// CI test runner — 收集全部测试结果
// 用法: php run_tests.php [-j N] [cc_flag]
//   -j N  并行执行 N 个测试（默认 1 = 串行）

// 解析 -j N 参数
$parallel = 1;
$posArgs = [];
for ($i = 1, $n = count($argv); $i < $n; $i++) {
    if ($argv[$i] === '-j' && isset($argv[$i + 1])) {
        $parallel = max(1, (int)$argv[++$i]);
    } else {
        $posArgs[] = $argv[$i];
    }
}

// 合并所有位置参数（shell word splitting 会把 "-cc gcc" 拆成两个参数）
$ccFlag = implode(' ', $posArgs);
$testDir = getenv('GITHUB_WORKSPACE') ?: dirname(__DIR__, 2);
$phpExe  = PHP_OS_FAMILY === 'Windows' ? 'php.exe' : './php';
$phpExeAbs = defined('PHP_BINARY') ? PHP_BINARY : (($testDir ? $testDir . DIRECTORY_SEPARATOR : '') . $phpExe);
$tphp    = $testDir . DIRECTORY_SEPARATOR . 'tphp.php';

// 平台 & 编译器
$platform = PHP_OS_FAMILY . ' ' . php_uname('m');
$compiler = $ccFlag ? ltrim(str_replace('-cc ', '', $ccFlag)) : 'tcc';

// 收集所有含 #debug 的测试文件（排除 @skip）
$testFiles = [];
$iter = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($testDir . DIRECTORY_SEPARATOR . 'test', FilesystemIterator::SKIP_DOTS)
);
foreach ($iter as $file) {
    if ($file->getExtension() !== 'php') continue;
    $content = file_get_contents($file->getPathname());
    if ($content === false) continue;
    if (str_contains($content, '@skip')) {
        // 检查是否为平台+编译器特定 skip（如 @skip:macos+tcc）
        if (preg_match('/@skip:(\w+)\+(\w+)/', $content, $m)) {
            $skipOS = strtolower($m[1]);
            $skipCC = strtolower($m[2]);
            $curOS = strtolower(PHP_OS_FAMILY);
            $curCC = strtolower($compiler);
            if (str_contains($curOS, $skipOS) && $curCC === $skipCC) continue;
        } else {
            continue;
        }
    }
    if (!str_contains($content, '#debug')) continue;
    // 检测 @expect-error 负面测试注解（期望编译失败）
    $expectError = false;
    if (preg_match('/@expect-error(?:\s+(.+))?/', $content, $m)) {
        $expectError = isset($m[1]) ? trim($m[1]) : true;
    }
    $testFiles[] = [$file->getPathname(), $expectError];
}
// sort by file path
usort($testFiles, fn($a, $b) => strcmp($a[0], $b[0]));

// @expect-error 查找表: filepath → errorMessage (true=任意错误)
$expectErrors = [];
foreach ($testFiles as [$p, $e]) {
    if ($e !== false) $expectErrors[$p] = $e;
}
$negTests = count($expectErrors);
$posTests = count($testFiles) - $negTests;

// 运行测试
echo "Platform: $platform | Compiler: $compiler | Parallel: $parallel\n";
echo "Tests: $posTests + $negTests negative\n\n";

$passed   = [];
$failed   = [];
$logDir   = $testDir . DIRECTORY_SEPARATOR . 'build';
if (!is_dir($logDir)) mkdir($logDir, 0777, true);

// ── 单测试执行函数（用于并行模式）──
$runOneTest = function(string $f, int $index) use ($testDir, $phpExe, $tphp, $ccFlag, $logDir) {
    $rel    = str_replace('\\', '/', substr($f, strlen($testDir) + 1));
    $safeName = str_replace(['/', '\\'], '_', preg_replace('/\.php$/', '', $rel));
    $out    = $logDir . DIRECTORY_SEPARATOR . 'test_' . $safeName . (PHP_OS_FAMILY === 'Windows' ? '.exe' : '');
    $log    = $logDir . DIRECTORY_SEPARATOR . 'test_' . $safeName . '.log';

    // @multi @with
    $fileArgs = escapeshellarg($f);
    $srcContent = file_get_contents($f);
    if ($srcContent !== false && preg_match('/@multi\s+@with\s+([^\s*]+)/', $srcContent, $m)) {
        $dir = dirname($f);
        foreach (explode(',', $m[1]) as $extra) {
            $extra = trim($extra);
            if ($extra === '') continue;
            $extraPath = $dir . DIRECTORY_SEPARATOR . $extra;
            if (is_file($extraPath)) $fileArgs .= ' ' . escapeshellarg($extraPath);
        }
    }

    $cmd = escapeshellarg($phpExe) . ' ' . escapeshellarg($tphp) . ' '
         . $fileArgs . ' --debug ' . $ccFlag
         . ' -o ' . escapeshellarg($out);

    $timeout   = 60;
    $startTime = microtime(true);
    $logHandle = @fopen($log, 'wb');
    $pipes     = [];
    $proc      = proc_open($cmd, [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes);
    $timedOut = false;

    if (is_resource($proc)) {
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        while (true) {
            $read = [$pipes[1], $pipes[2]];
            $remaining = $timeout - (microtime(true) - $startTime);
            if ($remaining <= 0) {
                $timedOut = true;
                $status = proc_get_status($proc);
                if (PHP_OS_FAMILY === 'Windows') {
                    exec('taskkill /F /T /PID ' . (int)$status['pid'] . ' 2>NUL');
                } else {
                    proc_terminate($proc, 9);
                }
                break;
            }
            $tvSec = (int)$remaining;
            $tvUsec = (int)(($remaining - $tvSec) * 1000000);
            $n = @stream_select($read, $write, $except, $tvSec, $tvUsec);
            if ($n > 0) {
                foreach ($read as $p) {
                    $chunk = @fread($p, 65536);
                    if ($chunk !== false && $chunk !== '' && $logHandle) fwrite($logHandle, $chunk);
                }
            }
            $status = proc_get_status($proc);
            if (!$status['running']) break;
        }
        stream_set_blocking($pipes[1], true);
        stream_set_blocking($pipes[2], true);
        foreach ([$pipes[1], $pipes[2]] as $p) {
            while (($chunk = @fread($p, 8192)) !== '' && $chunk !== false) {
                if ($logHandle) fwrite($logHandle, $chunk);
            }
        }
        $ret = proc_close($proc);
        if ($timedOut) { $ret = 124; if ($logHandle) fwrite($logHandle, "\n[TIMEOUT] test exceeded {$timeout}s\n"); }
    } else {
        $ret = 1;
    }
    if ($logHandle) fclose($logHandle);

    // 解析错误信息
    $errLines = [];
    // 读取输出（用于错误检测）
    $output = $ret !== 0 ? @file_get_contents($log) : '';
    if ($output === false) $output = '';

    if ($ret !== 0) {
        $logContent = $output;
        if ($logContent) {
            $lines = explode("\n", str_replace("\r", '', $logContent));
            foreach ($lines as $line) {
                if (preg_match('/\[FAIL\]|expected:|got\s+:/', $line)) $errLines[] = rtrim($line);
            }
            if (empty($errLines)) {
                foreach ($lines as $line) {
                    if (preg_match('/\b(error|Error):/', $line) || str_contains($line, '[NO]') || str_contains($line, 'Fatal error'))
                        $errLines[] = rtrim($line);
                }
            }
            if (empty($errLines)) {
                $tail = array_reverse(array_filter($lines, fn($l) => trim($l) !== ''));
                $errLines = array_reverse(array_slice($tail, 0, 8));
            }
        } else {
            $errLines = ['(compilation failed, NO error output — possible silent crash)'];
            // 尝试重跑捕获原始 stderr（proc_open 在某些平台上可能丢失管道数据）
            $cmd2 = escapeshellarg($phpExe) . ' ' . escapeshellarg($tphp) . ' '
                  . $fileArgs . ' --debug ' . $ccFlag
                  . ' -o ' . escapeshellarg($out) . ' 2>&1';
            $tccOut = []; exec($cmd2, $tccOut, $ret2);
            if (!empty($tccOut)) {
                $errLines = array_slice($tccOut, -8);
            } elseif ($ret2 !== 0) {
                $errLines = ["(exit code $ret2, no output from PHP/TCC)"];
            }
        }
        @unlink($log);
    } else {
        @unlink($log);
    }
    return [$rel, $ret, $errLines, $index];
};

if ($parallel <= 1) {
    // ── 串行模式 ──
    $index = 1;
    foreach ($testFiles as [$f, $expectErr]) {
        $tag = $expectErr ? '[NEG]' : '';
        echo "[$index/" . count($testFiles) . "]{$tag} $f ... ";
        [$rel, $ret, $errLines, $_] = $runOneTest($f, $index);
        if ($expectErr) {
            // 负面测试：检查日志输出中是否有编译/解析错误
            // （tphp.php 在错误时可能 exit 0，不能仅凭退出码判断）
            $safeName = str_replace(['/', '\\'], '_', preg_replace('/\.php$/', '', $rel));
            $negLog = $logDir . DIRECTORY_SEPARATOR . 'test_' . $safeName . '.log';
            $negOut = @file_get_contents($negLog);
            $hasError = $ret !== 0
                || ($negOut && (
                    str_contains($negOut, '[NO]')
                    || str_contains($negOut, 'Error:')
                    || str_contains($negOut, 'Transpile failed')
                    || str_contains($negOut, 'Compile failed')
                    || str_contains($negOut, 'Fatal error')
                ));
            if ($hasError) {
                echo "PASS (expected error)\n";
                $passed[] = $rel;
            } else {
                echo "FAIL (should have caught error)\n";
                $failed[$rel] = ['(compiler failed to report expected error)'];
            }
        } else {
            if ($ret === 0) {
                echo "PASS\n";
                $passed[] = $rel;
            } else {
                echo "FAIL\n";
                $failed[$rel] = $errLines;
            }
        }
        $index++;
    }
} else {
    // ── 并行模式 — worker pool ──
    $total    = count($testFiles);
    $nextIdx  = 0;
    $active   = [];  // [index => ['proc' => resource, 'pipes' => array, 'startTime' => float, 'file' => string, 'out' => string, 'log' => string, 'index' => int]]
    $results  = [];  // results collected by test index

    while ($nextIdx < $total || !empty($active)) {
        // 启动新进程（不超过 parallel 上限）
        while ($nextIdx < $total && count($active) < $parallel) {
            [$f, $expectErr] = $testFiles[$nextIdx];
            $rel = str_replace('\\', '/', substr($f, strlen($testDir) + 1));
            $safeName = str_replace(['/', '\\'], '_', preg_replace('/\.php$/', '', $rel));
            // 输出路径包含目录前缀，避免不同目录同名文件冲突（如 zlib/basic.php vs zip/basic.php）
            $out = $logDir . DIRECTORY_SEPARATOR . $safeName . (PHP_OS_FAMILY === 'Windows' ? '.exe' : '');
            $log = $logDir . DIRECTORY_SEPARATOR . $safeName . '.log';

            $fileArgs = escapeshellarg($f);
            $srcContent = file_get_contents($f);
            if ($srcContent !== false && preg_match('/@multi\s+@with\s+([^\s*]+)/', $srcContent, $m)) {
                $dir = dirname($f);
                foreach (explode(',', $m[1]) as $extra) {
                    $extra = trim($extra); if ($extra === '') continue;
                    $ep = $dir . DIRECTORY_SEPARATOR . $extra;
                    if (is_file($ep)) $fileArgs .= ' ' . escapeshellarg($ep);
                }
            }

            $cmd = escapeshellarg($phpExe) . ' ' . escapeshellarg($tphp) . ' '
                 . $fileArgs . ' --debug ' . $ccFlag . ' -o ' . escapeshellarg($out);

            $pipes = [];
            $proc = proc_open($cmd . ' 2>&1', [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
            ], $pipes);  // 保持项目根为 CWD（部分测试依赖相对路径 @multi @with）

            if (is_resource($proc)) {
                fclose($pipes[0]);
                stream_set_blocking($pipes[1], false);
                $active[] = [
                    'proc' => $proc, 'pipe' => $pipes[1], 'file' => $f,
                    'out' => $out, 'log' => $log, 'index' => $nextIdx,
                    'rel' => $rel, 'startTime' => microtime(true),
                ];
            } else {
                $results[$nextIdx] = [$rel, 1, ['(proc_open failed)']];
            }
            $nextIdx++;
        }

        // 轮询所有活跃进程，检查完成状态
        if (empty($active)) break;

        // 收集管道以便 stream_select
        $pipes = [];
        foreach ($active as $a) $pipes[] = $a['pipe'];
        $tvSec = 1; $tvUsec = 0;
        @stream_select($pipes, $write, $except, $tvSec, $tvUsec);

        // 检查并收集已完成的进程
        $stillActive = [];
        foreach ($active as $a) {
            $status = proc_get_status($a['proc']);
            if ($status['running']) {
                // 超时检查
                if (microtime(true) - $a['startTime'] > 60) {
                    if (PHP_OS_FAMILY === 'Windows') {
                        exec('taskkill /F /T /PID ' . (int)$status['pid'] . ' 2>NUL');
                    } else {
                        proc_terminate($a['proc'], 9);
                    }
                    $results[$a['index']] = [$a['rel'], 124, ['[TIMEOUT] test exceeded 60s']];
                    @fclose($a['pipe']);
                    proc_close($a['proc']);
                    continue;
                }
                $stillActive[] = $a;
                continue;
            }

            // 进程已结束，读取输出
            $output = '';
            while (($chunk = @fread($a['pipe'], 8192)) !== '' && $chunk !== false) {
                $output .= $chunk;
            }
            @fclose($a['pipe']);
            $ret = proc_close($a['proc']);

            // 写入日志
            @file_put_contents($a['log'], $output);

            // 解析结果
            $errLines = [];
            if ($ret !== 0) {
                $lines = explode("\n", str_replace("\r", '', $output));
                foreach ($lines as $line) {
                    if (preg_match('/\[FAIL\]|expected:|got\s+:/', $line)) $errLines[] = rtrim($line);
                }
                if (empty($errLines)) {
                    foreach ($lines as $line) {
                        if (preg_match('/\b(error|Error):/', $line) || str_contains($line, '[NO]') || str_contains($line, 'Fatal error'))
                            $errLines[] = rtrim($line);
                    }
                }
                if (empty($errLines)) {
                    $tail = array_reverse(array_filter($lines, fn($l) => trim($l) !== ''));
                    $errLines = array_reverse(array_slice($tail, 0, 8));
                }
            } else {
                @unlink($a['log']);
            }
            $results[$a['index']] = [$a['rel'], $ret, $errLines];
        }
        $active = $stillActive;

        // 每批完成时打印进度
        static $lastPrint = 0;
        $done = count($results);
        if ($done > $lastPrint) {
            $lastPrint = $done;
            echo "\r[$done/$total] tests completed (" . count($active) . " running)";
        }
    }
    echo "\n";

    // 按原始顺序收集结果
    ksort($results);
    foreach ($results as $idx => $r) {
        [$rel, $ret, $errLines] = $r;
        // 查找该文件是否为 @expect-error 测试
        $isNeg = isset($expectErrors[$testFiles[$idx][0]]);
        if ($isNeg) {
            if ($ret !== 0) { $passed[] = $rel; }
            else { $failed[$rel] = ['(compiler failed to report expected error)']; }
        } else {
            if ($ret === 0) { $passed[] = $rel; }
            else { $failed[$rel] = $errLines; }
        }
    }
}

// 输出结果
echo str_repeat('=', 60) . "\n";
echo "PASS: " . count($passed) . " | FAIL: " . count($failed) . "\n";
echo str_repeat('=', 60) . "\n";

if (count($failed) > 0) {
    echo "\n";
    foreach ($failed as $file => $errors) {
        echo "  FAIL: $file\n";
        foreach ($errors as $e) {
            echo "    $e\n";
        }
        echo "\n";
    }
    exit(1);
}

echo "\nAll tests passed.\n";
