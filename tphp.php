#!/usr/bin/env php
<?php

declare(strict_types=1);

// ============================================================
// TinyPHP — PHP → C transpiler (multi-file support)
//
// Usage:
//   tphp <file.php> [<file2.php> ...] [-o <output.exe>]
//   tphp .                      compile all .php in current dir
//   tphp -f <file.php> [-o <output.exe>]
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

// --- Parse arguments ---
$options = getopt('f:o:h', ['help']);
$cc      = null;

// Manual parse -cc xxx and -o xxx (PHP getopt not fully compatible)
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

// --- Collect all .php files ---
$files = collectFiles($args);

if (empty($files)) {
    die("Error: no .php files found\n");
}

// First file used for naming
$entryFile = $files[0];

// Paths
$cwd        = getcwd();
$includeDir = __DIR__ . DIRECTORY_SEPARATOR . 'include';

// PHAR mode: extract include/ and tcc/ alongside the PHAR (TCC can't read phar://)
$inPhar = str_starts_with(__DIR__, 'phar://');
$pharDir = '';
if ($inPhar) {
    $pharDir = dirname(Phar::running(false));

    // Extract TinyPHP headers (first run only)
    $pharIncludeDir = $includeDir;
    $destIncludeDir = $pharDir . DIRECTORY_SEPARATOR . 'include';
    if (!is_dir($destIncludeDir)) {
        extractPharDir($pharIncludeDir, $destIncludeDir);
    }

    // Extract TCC compiler (first run only)
    $pharRoot = dirname($includeDir);
    $pharTccDir = $pharRoot . '/tcc';
    $destTccDir = $pharDir . DIRECTORY_SEPARATOR . 'tcc';
    if (!is_dir($destTccDir) && is_dir($pharTccDir)) {
        extractPharDir($pharTccDir, $destTccDir);
    }

    $includeDir = $destIncludeDir;
}

// Compiler selection: -cc for external compiler, otherwise built-in TCC
if ($cc !== null) {
    $ccExe = $cc;
    // If it's a bare name (no path separator), rely on system PATH
    if (!str_contains($ccExe, '/') && !str_contains($ccExe, '\\')) {
        // Don't check file existence, let exec handle it
    } elseif (!file_exists($ccExe)) {
        die("Error: specified compiler not found: {$ccExe}\n");
    }
} elseif ($inPhar) {
    // PHAR mode: use built-in TCC extracted alongside the PHAR
    $tccBase = $pharDir . DIRECTORY_SEPARATOR . 'tcc';
    if (PHP_OS_FAMILY === 'Windows') {
        $ccExe = $tccBase . DIRECTORY_SEPARATOR . 'win32' . DIRECTORY_SEPARATOR . 'tcc.exe';
    } else {
        $ccExe = $tccBase . DIRECTORY_SEPARATOR . 'tcc';
        if (file_exists($ccExe)) chmod($ccExe, 0755);
    }
    if (!file_exists($ccExe)) die("Error: built-in TCC not found in PHAR: {$ccExe}\nMake sure tcc/ exists when building the PHAR\n");
} else {
    // Dev mode: TCC is alongside the project
    $ccExe = __DIR__ . DIRECTORY_SEPARATOR . 'tcc'
        . (PHP_OS_FAMILY === 'Windows'
            ? DIRECTORY_SEPARATOR . 'win32' . DIRECTORY_SEPARATOR . 'tcc.exe'
            : DIRECTORY_SEPARATOR . 'tcc');
    if (!file_exists($ccExe)) die("Error: built-in TCC not found: {$ccExe}\nBuild TCC first or use -cc to specify another compiler\n");
}

if (!is_dir($includeDir))    die("Error: include directory not found: {$includeDir}\n");

// --- Phase 1: Transpile all PHP → C ---
$allFilesStr = implode(', ', array_map(fn($f) => basename($f), $files));
echo "[1/2] Transpiling {$allFilesStr} → C...\n";

    try {
    $mainClass = null;
    $extraClasses = [];
    $functions = [];
    $constants = [];
    $enums = [];

    // Two-phase parsing: parse auxiliary files (non-Main) first,
    // collect enums/classes, then parse Main entry last.
    // Ensures cross-file enums are known when parsing Main.
    $mainFile  = null;
    $otherFiles = [];
    foreach ($files as $file) {
        // Quick check: does file contain class Main (global namespace)?
        $src = file_get_contents($file);
        if (preg_match('/^\s*class\s+Main\b/m', (string)$src)) {
            $mainFile = $file;
        } else {
            $otherFiles[] = $file;
        }
    }

    if ($mainFile === null) {
        die("Error: no global class Main found (entry class must be named Main in the global namespace)\n");
    }
    $entryFile = $mainFile;

    // Collect known enum names (for cross-file references)
    $knownEnumNames = [];

    $orderedFiles = array_merge($otherFiles, [$mainFile]);
    foreach ($orderedFiles as $file) {
        echo "       + {$file}\n";
        $source = file_get_contents($file);
        if ($source === false || trim($source) === '') {
            die("Error: PHP file is empty: {$file}\n");
        }

        $lexer  = new Lexer($source);
        $tokens = $lexer->tokenize();
        $parser = new Parser($tokens);
        // Inject enum names declared in other files (for cross-file enum references)
        $parser->setKnownEnums($knownEnumNames);
        $ast    = $parser->parse();

        // Merge AST — find global class Main from main + auxiliary classes
        $candidates = array_merge(
            $ast->mainClass ? [$ast->mainClass] : [],
            $ast->extraClasses
        );
        foreach ($candidates as $cls) {
            if ($cls->name === 'Main' && $cls->namespace === '') {
                if ($mainClass !== null) {
                    die("Error: multiple global class Main declarations found\n");
                }
                $mainClass = $cls;
            } else {
                $extraClasses[] = $cls;
            }
        }
        $functions    = array_merge($functions, $ast->functions);
        $constants    = array_merge($constants, $ast->constants);
        $enums        = array_merge($enums, $ast->enums);

        // Collect enum names (FQN) declared in this file for later files
        foreach ($ast->enums as $e) {
            $fq = ($e->namespace !== '')
                ? $e->namespace . '\\' . $e->name
                : $e->name;
            $knownEnumNames[$fq] = true;
        }
    }

    if ($mainClass === null) {
        die("Error: no global class Main found (entry class must be named Main in the global namespace)\n");
    }

    // Output path (derived from entry filename)
    if ($outExe === '') {
        $ext = (PHP_OS_FAMILY === 'Windows') ? '.exe' : '';
        $outExe = $cwd . DIRECTORY_SEPARATOR . pathinfo($entryFile, PATHINFO_FILENAME) . $ext;
    }
    $outDir = $cwd . DIRECTORY_SEPARATOR . 'build';

    // Clean build directory before compiling
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
    die("✗ Transpile failed: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
}

// --- Phase 2: C compile → binary ---
echo "[2/2] Compiling → {$outExe}...\n";

// TCC -B flag: tells TCC where to find its lib/ and include/
$bFlag = '';
if ($inPhar) {
    // PHAR mode: TCC extracted alongside PHAR
    if (PHP_OS_FAMILY === 'Windows') {
        $tccSysDir = $pharDir . DIRECTORY_SEPARATOR . 'tcc' . DIRECTORY_SEPARATOR . 'win32';
    } else {
        $tccSysDir = $pharDir . DIRECTORY_SEPARATOR . 'tcc';
    }
    if (is_dir($tccSysDir)) {
        $bFlag = ' -B"' . $tccSysDir . '"';
    }
} else {
    // Dev mode: auto-detect TCC standalone directory
    $tccBase = dirname($ccExe);
    $standaloneDirs = [
        $tccBase . '/tcc-standalone',
        $tccBase,
    ];
    foreach ($standaloneDirs as $dir) {
        if (is_dir($dir . '/lib') || is_dir($dir . '/include')) {
            $bFlag = ' -B"' . realpath($dir) . '"';
            break;
        }
    }
}

$cmd = sprintf(
    '"%s"%s -I"%s" -o "%s" "%s" 2>&1',
    $ccExe, $bFlag, $includeDir, $outExe, $cFile
);

$tccOutput = [];
$retval = 0;
exec($cmd, $tccOutput, $retval);

if ($retval !== 0) {
    echo "✗ TCC compile failed:\n";
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
                die("Error: files inside build/ are not allowed: {$arg}\n");
            }
            $files[] = $real;
        } else {
            die("Error: {$arg} is not a valid .php file\n");
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
    echo <<<HELP
TinyPHP — PHP → C transpiler (multi-file support)

Usage:
  tphp <file.php> [<file2.php> ...] [-o <output>] [-cc <compiler>]
  tphp -f <file.php> [-o <output>]
  tphp .                     compile all .php in current dir

Options:
  -o <output>       output file path (default: named after entry file)
  -cc <compiler>    specify C compiler (default: built-in TCC)
  -h, --help        show help

Examples:
  tphp main.php demo.php
  tphp .
  tphp main.php -o app.exe
  tphp main.php -cc gcc
  tphp main.php -cc "clang -O2"

HELP;
    exit(0);
}
