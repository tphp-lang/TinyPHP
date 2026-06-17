#!/usr/bin/env php
<?php

declare(strict_types=1);

// ============================================================
// TinyPHP — PHP → C 转译编译器（支持多文件）
//
// 用法:
//   php tphp.php <file.php> [<file2.php> ...] [-o <output.exe>]
//   php tphp.php .                      编译当前目录所有 .php
//   php tphp.php -f <file.php> [-o <output.exe>]
// ============================================================

spl_autoload_register(function (string $class): void {
    $baseDir = __DIR__ . '/src';
    $parts = explode('\\', $class);
    $file = $baseDir . '/' . implode('/', $parts) . '.php';
    if (file_exists($file)) require_once $file;
});

require_once __DIR__ . '/src/TokenType.php';
require_once __DIR__ . '/src/Token.php';
require_once __DIR__ . '/src/AST/Node.php';
require_once __DIR__ . '/src/Lexer.php';
require_once __DIR__ . '/src/Parser.php';
require_once __DIR__ . '/src/CodeGenerator.php';
require_once __DIR__ . '/src/Compiler.php';

// --- 参数解析 ---
$options = getopt('f:o:h', ['help']);
$args = array_values(array_filter($argv, fn($a) => !str_starts_with($a, '-') && $a !== $argv[0]));

if (isset($options['f'])) {
    $args = [$options['f']];
    // 收集额外位置参数
    $extra = array_slice($args, 1);
    if (!empty($extra)) $args = array_merge($args, $extra);
}

if ((empty($args) && !isset($options['f'])) || isset($options['h']) || isset($options['help'])) {
    showHelp();
}

$outExe = $options['o'] ?? '';

// --- 收集所有 .php 文件 ---
$files = collectFiles($args);

if (empty($files)) {
    die("错误: 没有找到 .php 文件\n");
}

// 入口文件 = 第一个文件，用于命名
$entryFile = $files[0];

// 路径
$cwd        = getcwd();
$includeDir = __DIR__ . DIRECTORY_SEPARATOR . 'include';
$tccExe     = __DIR__ . DIRECTORY_SEPARATOR . 'tcc'
    . (PHP_OS_FAMILY === 'Windows'
        ? DIRECTORY_SEPARATOR . 'win32' . DIRECTORY_SEPARATOR . 'tcc.exe'
        : DIRECTORY_SEPARATOR . 'tcc');

if (!is_dir($includeDir))    die("错误: include 目录不存在: {$includeDir}\n");
if (!file_exists($tccExe))   die("错误: TCC 编译器未找到: {$tccExe}\n");

// --- Phase 1: 转译所有 PHP → C ---
$allFilesStr = implode(', ', array_map(fn($f) => basename($f), $files));
echo "[1/2] 转译 {$allFilesStr} → C 代码...\n";

try {
    $mainClass = null;
    $extraClasses = [];
    $functions = [];

    foreach ($files as $file) {
        echo "       + {$file}\n";
        $source = file_get_contents($file);
        if ($source === false || trim($source) === '') {
            die("错误: PHP 文件为空: {$file}\n");
        }

        $lexer  = new Lexer($source);
        $tokens = $lexer->tokenize();
        $parser = new Parser($tokens);
        $ast    = $parser->parse();

        // 合并 AST —— 只有全局 class Main 才能作为入口
        if ($ast->mainClass !== null) {
            if ($ast->mainClass->name === 'Main' && $ast->mainClass->namespace === '') {
                if ($mainClass !== null) {
                    die("错误: 发现多个全局 class Main 声明\n");
                }
                $mainClass = $ast->mainClass;
                $entryFile = $file;
            } else {
                $extraClasses[] = $ast->mainClass;
            }
        }
        $extraClasses = array_merge($extraClasses, $ast->extraClasses);
        $functions    = array_merge($functions, $ast->functions);
    }

    if ($mainClass === null) {
        die("错误: 未找到全局 class Main（入口类必须在全局命名空间且名为 Main）\n");
    }

    // 最终输出路径（以入口文件名为准）
    if ($outExe === '') {
        $outExe = $cwd . DIRECTORY_SEPARATOR . pathinfo($entryFile, PATHINFO_FILENAME) . '.exe';
    }
    $outDir = $cwd . DIRECTORY_SEPARATOR . 'build';

    // 编译前清理 build 目录
    if (is_dir($outDir)) {
        $contents = glob($outDir . DIRECTORY_SEPARATOR . '*');
        if ($contents !== false) {
            foreach ($contents as $f) { if (is_file($f)) unlink($f); }
        }
        rmdir($outDir);
    }

    $merged = new ProgramNode($mainClass, $extraClasses, $functions);

    if (!is_dir($outDir)) mkdir($outDir, 0777, true);

    $gen   = new CodeGenerator();
    $cFile = $gen->generate($merged, $entryFile, $outDir);

    echo "       ✓ {$cFile}\n";

} catch (RuntimeException $e) {
    die("✗ 转译失败: " . $e->getMessage() . "\n");
}

// --- Phase 2: TCC 编译 C → .exe ---
echo "[2/2] TCC 编译 → {$outExe}...\n";

$cmd = sprintf(
    '"%s" -I"%s" -o "%s" "%s" 2>&1',
    $tccExe, $includeDir, $outExe, $cFile
);

$tccOutput = [];
$retval = 0;
exec($cmd, $tccOutput, $retval);

if ($retval !== 0) {
    echo "✗ TCC 编译失败:\n";
    echo implode("\n", $tccOutput) . "\n";
    exit(1);
}

echo "       ✓ {$outExe}\n";

// ============================================================
/** @param string[] $args
 *  @return string[] */
function collectFiles(array $args): array
{
    $files = [];
    foreach ($args as $arg) {
        if ($arg === '.') {
            // 递归扫描当前目录所有 .php（排除 build/ 和 tphp.php）
            $baseDir = getcwd();
            $files = array_merge($files, scanPhpFiles($baseDir));
        } elseif (is_file($arg) && str_ends_with($arg, '.php')) {
            $real = realpath($arg) ?: $arg;
            // 拒绝 build/ 目录内的文件
            if (isInBuildDir($real)) {
                die("错误: 不允许编译 build/ 目录下的文件: {$arg}\n");
            }
            $files[] = $real;
        } else {
            die("错误: {$arg} 不是有效的 .php 文件\n");
        }
    }
    return array_unique($files);
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

function showHelp(): never
{
    echo <<<HELP
TinyPHP — PHP → C 转译编译器（支持多文件）

用法:
  php tphp.php <file.php> [<file2.php> ...] [-o <output.exe>]
  php tphp.php -f <file.php> [-o <output.exe>]
  php tphp.php .                      编译当前目录所有 .php

选项:
  -o <output.exe>  输出的 .exe 文件路径 (可选，默认入口文件名)
  -h, --help       显示帮助

示例:
  php tphp.php test/files/main.php test/files/demo.php test/files/other/demo.php
  php tphp.php .
  php tphp.php main.php -o app.exe

HELP;
    exit(0);
}
