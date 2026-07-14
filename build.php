<?php

$base = __DIR__;

$phar = new Phar('tphp.phar', 0, 'tphp.phar');
$phar->startBuffering();

// 递归添加 src/*.php
$iter = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($base . '/src', FilesystemIterator::SKIP_DOTS)
);
foreach ($iter as $file) {
    if ($file->getExtension() !== 'php') continue;
    $local = str_replace('\\', '/', substr($file->getPathname(), strlen($base) + 1));
    $phar->addFile($file->getPathname(), $local);
}

// 入口 tphp.php
$phar->addFile($base . '/tphp.php', 'tphp.php');

// 递归添加 include/*.h
$iter = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($base . '/include', FilesystemIterator::SKIP_DOTS)
);
foreach ($iter as $file) {
    if ($file->getExtension() !== 'h') continue;
    $local = str_replace('\\', '/', substr($file->getPathname(), strlen($base) + 1));
    $phar->addFile($file->getPathname(), $local);
}

// 递归添加 ext/（扩展目录下所有文件：源码、头文件、库文件等）
if (is_dir($base . '/ext')) {
    $iter = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($base . '/ext', FilesystemIterator::SKIP_DOTS)
    );
    $count = 0;
    foreach ($iter as $file) {
        if (!$file->isFile()) continue;
        $local = str_replace('\\', '/', substr($file->getPathname(), strlen($base) + 1));
        $phar->addFile($file->getPathname(), $local);
        $count++;
    }
    echo "[*] 已打包 ext/（{$count} 个文件）\n";
}

// 递归添加 tcc/（TCC 编译器 + 头文件 + 库文件）
if (is_dir($base . '/tcc')) {
    $iter = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($base . '/tcc', FilesystemIterator::SKIP_DOTS)
    );
    $count = 0;
    $skipped = 0;
    foreach ($iter as $file) {
        if (!$file->isFile()) continue;  // 跳过目录（Linux TCC 构建会产生 clang/ 子目录）
        if ($file->isLink()) {
            $skipped++;
            continue;  // 跳过符号链接（PHAR 不能打包符号链接）
        }
        $pathname = $file->getPathname();
        // 跳过 .DS_Store 等无用文件
        $basename = $file->getBasename();
        if ($basename === '.DS_Store') {
            $skipped++;
            continue;
        }
        $local = str_replace('\\', '/', substr($pathname, strlen($base) + 1));
        $phar->addFile($pathname, $local);
        $count++;
        // 每 100 个文件输出一次进度
        if ($count % 100 === 0) {
            echo "[*] 已打包 tcc/ ... {$count} 个文件\n";
        }
    }
    echo "[*] 已打包 TCC 编译器（{$count} 个文件，跳过 {$skipped} 个）\n";
} else {
    echo "[!] 未找到 tcc/ 目录，PHAR 将不含内置编译器\n";
}

// 入口
$phar->setStub("#!/usr/bin/env php\n<?php Phar::mapPhar('tphp.phar'); require 'phar://tphp.phar/tphp.php'; __HALT_COMPILER();");

$phar->stopBuffering();
echo "Built tphp.phar (" . number_format(filesize('tphp.phar')) . " bytes)\n";
