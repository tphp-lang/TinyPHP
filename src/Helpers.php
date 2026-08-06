<?php

declare(strict_types=1);

// ============================================================
// Helpers.php — tphp.php 辅助函数集
// 从主 CLI 文件提取，保持入口文件简洁
// ============================================================

/**
 * 从 ar 归档（.a 静态库）提取成员到指定目录。
 * 处理 BSD 长名表（//  成员）和 GNU 长名表（/N 索引）格式。
 * 仅提取 COFF/ELF 目标文件（.obj/.o），跳过符号表和索引成员。
 */
function extractArMembers(string $aFile, string $outDir): array
{
    if (!is_file($aFile)) return [];
    $bytes = @file_get_contents($aFile);
    if ($bytes === false || strlen($bytes) < 8) return [];
    if (substr($bytes, 0, 8) !== "!<arch>\n") return [];

    $len = strlen($bytes);
    $pos = 8;
    $longNames = null;
    $members = [];

    while ($pos + 60 <= $len) {
        $header = substr($bytes, $pos, 60);
        $nameRaw = rtrim(substr($header, 0, 16));
        $sizeStr = rtrim(substr($header, 48, 10));
        if (!ctype_digit($sizeStr)) break;
        $size = (int)$sizeStr;
        $dataStart = $pos + 60;
        if ($dataStart + $size > $len) break;

        if ($nameRaw === '//') {
            $longNames = substr($bytes, $dataStart, $size);
        } elseif ($nameRaw !== '/' && !str_starts_with($nameRaw, '/')) {
            $members[] = [rtrim($nameRaw, '/'), $dataStart, $size];
        } elseif (preg_match('/^\/(\d+)$/', $nameRaw, $m) && $longNames !== null) {
            $offset = (int)$m[1];
            $end = strpos($longNames, "\0", $offset);
            if ($end === false) $end = strlen($longNames);
            $realName = rtrim(substr($longNames, $offset, $end - $offset), '/');
            $members[] = [$realName, $dataStart, $size];
        }

        $pos = $dataStart + $size;
        if ($size % 2 === 1) $pos++;
    }

    if (!is_dir($outDir)) @mkdir($outDir, 0777, true);
    $extracted = [];
    $usedNames = [];
    foreach ($members as [$name, $dataStart, $size]) {
        if (!preg_match('/\.(obj|o)$/i', $name)) continue;
        $base = basename($name);
        $outName = $base;
        $i = 1;
        while (isset($usedNames[$outName])) {
            $outName = pathinfo($base, PATHINFO_FILENAME) . "_$i." . pathinfo($base, PATHINFO_EXTENSION);
            $i++;
        }
        $usedNames[$outName] = true;
        $outPath = $outDir . DIRECTORY_SEPARATOR . $outName;
        $data = substr($bytes, $dataStart, $size);
        if (@file_put_contents($outPath, $data) !== false) {
            $extracted[] = $outPath;
        }
    }
    return $extracted;
}

/** 递归删除目录 */
function deleteDirectory(string $dir): bool
{
    if (!is_dir($dir)) return false;
    if (PHP_OS_FAMILY === 'Windows') {
        exec('rmdir /s /q "' . $dir . '" 2>nul', $out, $ret);
        return !is_dir($dir);
    }
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($files as $fileinfo) {
        $path = $fileinfo->getPathname();
        if ($fileinfo->isDir()) { @rmdir($path); } else { @unlink($path); }
    }
    return @rmdir($dir);
}

/** 从 CLI args 收集 .php 和 .c 源文件 */
function collectFiles(array $args): array
{
    $files = [];
    $cFiles = [];
    foreach ($args as $arg) {
        if ($arg === '.') {
            $baseDir = getcwd();
            $files = array_merge($files, scanPhpFiles($baseDir));
        } elseif (is_file($arg)) {
            $real = realpath($arg) ?: $arg;
            if (isInBuildDir($real)) die("Error: files inside build/ are not allowed: {$arg}\n");
            if (str_ends_with($arg, '.php')) {
                $files[] = $real;
            } elseif (str_ends_with($arg, '.c')) {
                $cFiles[] = $real;
            } else {
                die("Error: {$arg} is not a valid .php or .c file\n");
            }
        } else {
            die("Error: {$arg} is not a valid file\n");
        }
    }
    return [array_unique($files), array_unique($cFiles)];
}

/** 递归扫描目录下所有 .php 文件，排除 build/ */
function scanPhpFiles(string $dir): array
{
    $files = [];
    $items = glob($dir . DIRECTORY_SEPARATOR . '*') ?: [];
    foreach ($items as $item) {
        $base = basename($item);
        if ($base === 'build' && is_dir($item)) continue;
        if ($base === 'tphp.php') continue;
        if (is_dir($item)) {
            $files = array_merge($files, scanPhpFiles($item));
        } elseif (str_ends_with($base, '.php')) {
            $files[] = $item;
        }
    }
    return $files;
}

/** 路径是否在某个 build/ 目录下 */
function isInBuildDir(string $path): bool
{
    $sep = DIRECTORY_SEPARATOR;
    $norm = str_replace(['/', '\\'], $sep, $path);
    return str_contains($norm, $sep . 'build' . $sep);
}

/** 从 phar:// 路径递归提取目录到硬盘 */
function extractPharDir(string $pharDir, string $destDir): void
{
    if (!is_dir($pharDir)) return;
    $iter = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($pharDir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iter as $file) {
        $relPath = str_replace($pharDir . DIRECTORY_SEPARATOR, '', $file->getPathname());
        $dest = $destDir . DIRECTORY_SEPARATOR . $relPath;
        $parent = dirname($dest);
        if (!is_dir($parent)) mkdir($parent, 0777, true);
        copy($file->getPathname(), $dest);
    }
}

function showHelp(): never
{
    $ver = TPHP_VERSION;
    echo <<<HELP
  _____ _             ____  _   _ ____  
 |_   _(_)_ __  _   _|  _ \| | | |  _ \ 
   | | | | '_ \| | | | |_) | |_| | |_) |
   | | | | | | | |_| |  __/|  _  |  __/ 
   |_| |_|_| |_|\__, |_|   |_| |_|_|    
                |___/                   v{$ver}

Usage:
  tphp <file.php> [<file2.php> ...] [-o <output>] [-cc <compiler>] [-os <target>] [-arch <arch>]
  tphp -f <file.php> [-o <output>]
  tphp .                     compile all .php in current dir

Options:
  -o <output>       output file path (default: named after entry file)
  -cc <compiler>    specify C compiler (default: built-in TCC)
  -os <target>      cross-compile target: windows, linux, macos, android
  -arch <arch>      target architecture: x86_64, aarch64 (default: host)
  TPHP_SYSROOT      env: sysroot path for clang -target cross-compile
  -shared           compile as shared library (.dll/.so/.dylib)
  --debug           print full compile command
  --ssa             enable SSA IR pipeline (FlatAst → SSA → optimize → C)
  -v, --version     show version and exit
  -h, --help        show help

Examples:
  tphp main.php demo.php
  tphp .
  tphp main.php -o app.exe
  tphp main.php -cc gcc
  tphp main.php -cc "clang -O2"
  tphp main.php -os linux
  tphp main.php -os linux -arch aarch64
  tphp main.php -os windows -cc gcc
  tphp lib.php -shared -o mylib.dll
  tphp . -os android

HELP;
    exit(0);
}
