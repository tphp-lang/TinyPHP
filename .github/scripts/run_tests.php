#!/usr/bin/env php
<?php
// CI test runner — 收集全部测试结果
// 用法: php run_tests.php [cc_flag]

$ccFlag = $argv[1] ?? '';
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
    // 排除需要外部服务的集成测试（由专用工作流 mysql_test.yml 运行）
    $relPath = str_replace('\\', '/', substr($file->getPathname(), strlen($testDir) + 1));
    if (str_starts_with($relPath, 'test/pdo_mysql/')) continue;
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

    $cmd = escapeshellarg($phpExe) . ' ' . escapeshellarg($tphp) . ' '
         . $fileArgs . ' --debug ' . $ccFlag
         . ' -o ' . escapeshellarg($out)
         . ' >' . escapeshellarg($log) . ' 2>&1';
    system($cmd, $ret);

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
