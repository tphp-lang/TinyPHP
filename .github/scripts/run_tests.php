#!/usr/bin/env php
<?php
// CI test runner — 收集全部测试结果
// 用法: php run_tests.php [cc_flag]

// 合并所有 argv 参数（shell word splitting 会把 "-cc gcc" 拆成两个参数）
$ccFlag = implode(' ', array_slice($argv, 1));
$testDir = getenv('GITHUB_WORKSPACE') ?: dirname(__DIR__, 2);
$phpExe  = PHP_OS_FAMILY === 'Windows' ? 'php.exe' : './php';
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
    $testFiles[] = $file->getPathname();
}
sort($testFiles);

// 运行测试
echo "Platform: $platform | Compiler: $compiler\n";
echo "Tests: " . count($testFiles) . "\n\n";

$passed   = [];
$failed   = [];
$logDir   = $testDir . DIRECTORY_SEPARATOR . 'build';
if (!is_dir($logDir)) mkdir($logDir, 0777, true);

foreach ($testFiles as $i => $f) {
    $rel    = str_replace('\\', '/', substr($f, strlen($testDir) + 1));
    // 使用相对路径生成唯一名称，避免不同目录下同名文件（如 zip/basic.php 和 zlib/basic.php）冲突
    $safeName = str_replace(['/', '\\'], '_', preg_replace('/\.php$/', '', $rel));
    $out    = $logDir . DIRECTORY_SEPARATOR . 'test_' . $safeName . (PHP_OS_FAMILY === 'Windows' ? '.exe' : '');
    $log    = $logDir . DIRECTORY_SEPARATOR . 'test_' . $safeName . '.log';

    // 解析 @multi @with 注解，收集辅助文件
    $fileArgs = escapeshellarg($f);
    $srcContent = file_get_contents($f);
    if ($srcContent !== false && preg_match('/@multi\s+@with\s+([^\s*]+)/', $srcContent, $m)) {
        $dir = dirname($f);
        foreach (explode(',', $m[1]) as $extra) {
            $extra = trim($extra);
            if ($extra === '') continue;
            $extraPath = $dir . DIRECTORY_SEPARATOR . $extra;
            if (is_file($extraPath)) {
                $fileArgs .= ' ' . escapeshellarg($extraPath);
            }
        }
    }

    // 构造命令（输出改由 proc_open 的 pipes 写入 log，便于超时控制）
    $cmd = escapeshellarg($phpExe) . ' ' . escapeshellarg($tphp) . ' '
         . $fileArgs . ' --debug ' . $ccFlag
         . ' -o ' . escapeshellarg($out);

    // proc_open + 超时机制（默认 60 秒），避免被测程序死循环无限期阻塞 CI
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
            // stream_select 带超时等待管道可读，避免忙等浪费 CPU
            $read = [$pipes[1], $pipes[2]];
            $write = null;
            $except = null;
            $remaining = $timeout - (microtime(true) - $startTime);
            if ($remaining <= 0) {
                $timedOut = true;
                $status = proc_get_status($proc);
                $pid = (int)$status['pid'];
                if (PHP_OS_FAMILY === 'Windows') {
                    exec('taskkill /F /T /PID ' . $pid . ' 2>NUL');
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
                    $chunk = fread($p, 65536);
                    if ($chunk !== false && $chunk !== '' && $logHandle) {
                        fwrite($logHandle, $chunk);
                    }
                }
            }
            $status = proc_get_status($proc);
            if (!$status['running']) break;
        }
        // 子进程结束后排空剩余管道数据
        stream_set_blocking($pipes[1], true);
        stream_set_blocking($pipes[2], true);
        foreach ([$pipes[1], $pipes[2]] as $p) {
            while (($chunk = fread($p, 8192)) !== '' && $chunk !== false) {
                if ($logHandle) fwrite($logHandle, $chunk);
            }
        }
        $ret = proc_close($proc);
        if ($timedOut) {
            $ret = 124;
            if ($logHandle) fwrite($logHandle, "\n[TIMEOUT] test exceeded {$timeout}s limit and was killed\n");
        }
    } else {
        $ret = 1;
    }
    if ($logHandle) fclose($logHandle);

    if ($ret === 0) {
        $passed[] = $rel;
        @unlink($log);
    } else {
        $errLines = [];
        $logContent = @file_get_contents($log);
        if ($logContent) {
            $lines = explode("\n", str_replace("\r", '', $logContent));
            // 1. --debug 输出比较失败
            foreach ($lines as $line) {
                if (preg_match('/\[FAIL\]|expected:|got\s+:/', $line)) {
                    $errLines[] = rtrim($line);
                }
            }
            // 2. 编译/解析错误
            if (empty($errLines)) {
                foreach ($lines as $line) {
                    if (preg_match('/\b(error|Error):/', $line) || str_contains($line, '[NO]') || str_contains($line, 'Fatal error')) {
                        $errLines[] = rtrim($line);
                    }
                }
            }
            // 3. 兜底：最后 8 行非空
            if (empty($errLines)) {
                $tail = array_reverse(array_filter($lines, fn($l) => trim($l) !== ''));
                $tail = array_reverse(array_slice($tail, 0, 8));
                $errLines = $tail;
            }
        } else {
            // TCC 静默崩溃 — log 为空, 无法定位原因
            // 直接重跑命令捕获原始 stderr
            $cmd2 = escapeshellarg($phpExe) . ' ' . escapeshellarg($tphp) . ' '
                  . escapeshellarg($f) . ' --debug ' . $ccFlag
                  . ' -o ' . escapeshellarg($out) . ' 2>&1';
            unset($tccOut); exec($cmd2, $tccOut, $ret2);
            $errLines = !empty($tccOut) ? $tccOut : ['(compilation failed, NO error output captured — TCC silent crash)'];
        }
        $failed[$rel] = $errLines;
        @unlink($log);
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
