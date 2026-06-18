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
$cc      = null;

// 手动解析 -cc xxx 和 -o xxx（PHP getopt 不完全兼容）
$posArgs = [];
for ($i = 1, $n = count($argv); $i < $n; $i++) {
    if ($argv[$i] === '-cc' && isset($argv[$i + 1])) {
        $cc = $argv[++$i];
    } elseif ($argv[$i] === '-o' && isset($argv[$i + 1])) {
        $outExe = $argv[++$i]; // 覆盖 getopt 解析
    } elseif (!str_starts_with($argv[$i], '-')) {
        $posArgs[] = $argv[$i];
    }
}
$args = $posArgs;

if (isset($options['f'])) {
    $args = array_merge([$options['f']], array_diff($args, [$options['f']]));
}

if ((empty($args) && !isset($options['f'])) || isset($options['h']) || isset($options['help'])) {
    showHelp();
}

$outExe = $outExe ?? $options['o'] ?? '';

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

// 编译器选择：-cc 指定外部编译器，否则用内置 TCC
if ($cc !== null) {
    $ccExe = $cc;
    // 简单检测：如果是纯名称（无路径分隔符），依赖系统 PATH
    if (!str_contains($ccExe, '/') && !str_contains($ccExe, '\\')) {
        // 不做文件检查，交给 exec 处理
    } elseif (!file_exists($ccExe)) {
        die("错误: 指定编译器未找到: {$ccExe}\n");
    }
} else {
    $ccExe = __DIR__ . DIRECTORY_SEPARATOR . 'tcc'
        . (PHP_OS_FAMILY === 'Windows'
            ? DIRECTORY_SEPARATOR . 'win32' . DIRECTORY_SEPARATOR . 'tcc.exe'
            : DIRECTORY_SEPARATOR . 'tcc');
    if (!file_exists($ccExe)) die("错误: 内置 TCC 未找到: {$ccExe}\n请先编译 TCC 或使用 -cc 指定其他编译器\n");
}

if (!is_dir($includeDir))    die("错误: include 目录不存在: {$includeDir}\n");

// --- Phase 1: 转译所有 PHP → C ---
$allFilesStr = implode(', ', array_map(fn($f) => basename($f), $files));
echo "[1/2] 转译 {$allFilesStr} → C 代码...\n";

    try {
    $mainClass = null;
    $extraClasses = [];
    $functions = [];
    $constants = [];
    $enums = [];

    // 两阶段解析：先解析辅助文件（非 Main），收集枚举/类，最后解析 Main 入口
    // 确保 Main 文件解析时已知所有跨文件枚举
    $mainFile  = null;
    $otherFiles = [];
    foreach ($files as $file) {
        // 快速检测文件是否包含 class Main（全局命名空间）
        $src = file_get_contents($file);
        if (preg_match('/^\s*class\s+Main\s*\{/m', (string)$src)) {
            $mainFile = $file;
        } else {
            $otherFiles[] = $file;
        }
    }

    if ($mainFile === null) {
        die("错误: 未找到全局 class Main（入口类必须在全局命名空间且名为 Main）\n");
    }
    $entryFile = $mainFile;

    // 收集所有已知枚举名（用于跨文件引用）
    $knownEnumNames = [];

    $orderedFiles = array_merge($otherFiles, [$mainFile]);
    foreach ($orderedFiles as $file) {
        echo "       + {$file}\n";
        $source = file_get_contents($file);
        if ($source === false || trim($source) === '') {
            die("错误: PHP 文件为空: {$file}\n");
        }

        $lexer  = new Lexer($source);
        $tokens = $lexer->tokenize();
        $parser = new Parser($tokens);
        // 注入其他文件已声明的枚举名（支持跨文件枚举引用）
        $parser->setKnownEnums($knownEnumNames);
        $ast    = $parser->parse();

        // 合并 AST —— 只有全局 class Main 才能作为入口
        if ($ast->mainClass !== null) {
            if ($ast->mainClass->name === 'Main' && $ast->mainClass->namespace === '') {
                if ($mainClass !== null) {
                    die("错误: 发现多个全局 class Main 声明\n");
                }
                $mainClass = $ast->mainClass;
            } else {
                $extraClasses[] = $ast->mainClass;
            }
        }
        $extraClasses = array_merge($extraClasses, $ast->extraClasses);
        $functions    = array_merge($functions, $ast->functions);
        $constants    = array_merge($constants, $ast->constants);
        $enums        = array_merge($enums, $ast->enums);

        // 收集本文件声明的枚举名（完全限定名），供后续文件引用
        foreach ($ast->enums as $e) {
            $fq = ($e->namespace !== '')
                ? $e->namespace . '\\' . $e->name
                : $e->name;
            $knownEnumNames[$fq] = true;
        }
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

    $merged = new ProgramNode($mainClass, $extraClasses, $functions, $constants, $enums);

    if (!is_dir($outDir)) mkdir($outDir, 0777, true);

    $gen   = new CodeGenerator();
    $cFile = $gen->generate($merged, $entryFile, $outDir);

    echo "       ✓ {$cFile}\n";

} catch (\Throwable $e) {
    die("✗ 转译失败: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
}

// --- Phase 2: C 编译 → 产物 ---
echo "[2/2] 编译 → {$outExe}...\n";

$cmd = sprintf(
    '"%s" -I"%s" -o "%s" "%s" 2>&1',
    $ccExe, $includeDir, $outExe, $cFile
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
  php tphp.php <file.php> [<file2.php> ...] [-o <output>] [-cc <compiler>]
  php tphp.php -f <file.php> [-o <output>]
  php tphp.php .                     编译当前目录所有 .php

选项:
  -o <output>       输出文件路径（默认执行目录下以入口文件名命名）
  -cc <compiler>    指定 C 编译器（默认使用内置 TCC）
  -h, --help        显示帮助

示例:
  php tphp.php main.php demo.php
  php tphp.php .
  php tphp.php main.php -o app.exe
  php tphp.php main.php -cc gcc
  php tphp.php main.php -cc "clang -O2"

HELP;
    exit(0);
}
