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

/** TinyPHP 版本号 */
const TPHP_VERSION = '0.2.0-beta.1';

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
$options = getopt('f:o:hv', ['help', 'os:', 'arch:', 'debug', 'version']);
$cc        = null;
$targetOS  = null; // -os windows|linux|macos
$targetArch = null; // -arch x86_64|aarch64
$isShared  = false; // -shared: 生成动态库

// Normalize arch name
$archMap = ['x86_64' => 'x86_64', 'amd64' => 'x86_64', 'x64' => 'x86_64',
            'aarch64' => 'aarch64', 'arm64' => 'aarch64', 'arm' => 'arm'];

// Manual parse -cc xxx, -os xxx, -arch xxx and -o xxx (PHP getopt not fully compatible)
$posArgs = [];
for ($i = 1, $n = count($argv); $i < $n; $i++) {
    if ($argv[$i] === '-cc' && isset($argv[$i + 1])) {
        $cc = $argv[++$i];
    } elseif ($argv[$i] === '-arch' && isset($argv[$i + 1])) {
        $targetArch = $archMap[strtolower($argv[++$i])] ?? null;
        if ($targetArch === null) die("Error: unknown arch '{$argv[$i-1]}'. Use: x86_64, aarch64\n");
    } elseif ($argv[$i] === '-os' && isset($argv[$i + 1])) {
        $targetOS = strtolower($argv[++$i]);
        // Normalize: macos → darwin
        if ($targetOS === 'macos' || $targetOS === 'mac') $targetOS = 'darwin';
    } elseif ($argv[$i] === '-o' && isset($argv[$i + 1])) {
        $outExe = $argv[++$i]; // 覆盖 getopt 解析
    } elseif ($argv[$i] === '-shared') {
        $isShared = true;
    } elseif (!str_starts_with($argv[$i], '-')) {
        $posArgs[] = $argv[$i];
    }
}
$args = $posArgs;
// Also check --os=xxx, --arch=xxx long form
if (isset($options['os'])) {
    $targetOS = strtolower($options['os']);
    if ($targetOS === 'macos' || $targetOS === 'mac') $targetOS = 'darwin';
}
if (isset($options['arch']) && $targetArch === null) {
    $targetArch = $archMap[strtolower($options['arch'])] ?? null;
    if ($targetArch === null) die("Error: unknown arch '{$options['arch']}'. Use: x86_64, aarch64\n");
}
// Default arch per target OS: Windows/Linux → x86_64, macOS → aarch64
if ($targetOS !== null && $targetArch === null) {
    $targetArch = ($targetOS === 'darwin') ? 'aarch64' : 'x86_64';
}

if (isset($options['f'])) {
    $args = array_merge([$options['f']], array_diff($args, [$options['f']]));
}

if (isset($options['version']) || isset($options['v'])) {
    echo 'TinyPHP ' . TPHP_VERSION . "\n";
    exit(0);
}

if ((empty($args) && !isset($options['f'])) || isset($options['h']) || isset($options['help'])) {
    showHelp();
}

$outExe = $outExe ?? $options['o'] ?? '';

// Convert relative output path to absolute — TCC may chdir() to its binary dir,
// so a relative -o path would land in the wrong place.
if ($outExe !== '' && !str_starts_with($outExe, '/') && !preg_match('#^[A-Za-z]:#', $outExe)) {
    $outExe = getcwd() . DIRECTORY_SEPARATOR . $outExe;
}

// --- Collect all source files ---
[$files, $userCFiles] = collectFiles($args);

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

    // Extract ext/ (first run only)
    $pharExtDir = $pharRoot . '/ext';
    $destExtDir = $pharDir . DIRECTORY_SEPARATOR . 'ext';
    if (!is_dir($destExtDir) && is_dir($pharExtDir)) {
        extractPharDir($pharExtDir, $destExtDir);
    }

    $includeDir = $destIncludeDir;
    $extRootPhar = $destExtDir;  // #import 使用解压后的 ext/
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

// Detect compiler class early — used by Parser for #if 条件编译
//   TCC (built-in), GCC, or Clang
$ccClass = 'TCC';
if ($cc !== null) {
    $ccLower = strtolower($cc);
    if (str_contains($ccLower, 'gcc')) $ccClass = 'GCC';
    elseif (str_contains($ccLower, 'clang')) $ccClass = 'Clang';
} elseif (PHP_OS_FAMILY === 'Darwin') {
    $ccClass = 'Clang';
}
// 目标 OS/Arch（条件编译求值用）：未指定时回退到宿主环境
$ctTargetOS   = $targetOS ?? strtolower(PHP_OS_FAMILY);
$ctTargetArch = $targetArch ?? strtolower(php_uname('m'));

// --- Phase 1: Transpile all PHP → C ---
$allFilesStr = implode(', ', array_map(fn($f) => basename($f), $files));
echo "[1/2] Transpiling {$allFilesStr} => C...\n";

    try {
    $mainClass = null;
    $extraClasses = [];
    $functions = [];
    $constants = [];
    $enums = [];
    $allIncludes  = [];
    $allFlags     = [];
    $allCallbacks = [];
    $allDebugs    = [];
    $allCstructs  = [];

    // Two-phase parsing: parse auxiliary files (non-Main) first,
    // collect enums/classes, then parse Main entry last.
    // Ensures cross-file enums are known when parsing Main.
    $mainFile  = null;
    $otherFiles = [];
    // ── #import 预扫描：引入 ext/name/src/*.php → $files、*.c → $importCFiles ────
    // 用 for 而非 foreach：扩展文件可能有自己的 #import，需递归扫描
    $extRoot = $inPhar ? ($extRootPhar ?? __DIR__ . DIRECTORY_SEPARATOR . 'ext') : (__DIR__ . DIRECTORY_SEPARATOR . 'ext');
    $importCFiles = [];
    $importedExts = [];  // 已处理的扩展名，避免重复

    // Magic constants for #include / #flag
    // PHAR 模式：__EXT__ 必须指向文件系统解压路径，否则 #include 无法解析
    $magicExt = $inPhar
        ? str_replace('\\', '/', $destExtDir)
        : str_replace('\\', '/', realpath(__DIR__ . '/ext') ?: __DIR__ . '/ext');
    $magicInc = str_replace('\\', '/', realpath($includeDir) ?: $includeDir);
    $magicCmd = str_replace('\\', '/', $cwd);

    for ($fi = 0; $fi < count($files); $fi++) {
        $src = file_get_contents($files[$fi]);
        // Preprocess: expand magic constants in #include directives
        $filePath = realpath($files[$fi]);
        $fileDir = dirname($filePath);
        $src = preg_replace_callback(
            '/^(#include\s+)(?:(Windows|Linux|MacOS|Darwin|GCC|Clang|TCC)\s+)?(.+)$/mi',
            function ($m) use ($fileDir, $magicExt, $magicInc, $magicCmd) {
                $prefix = $m[2] ?? '';
                $inc = $m[3];
                $prefixPart = $prefix !== '' ? $m[2] . ' ' : '';
                // Already quoted or system header → leave as-is
                if (str_starts_with($inc, '"') || str_starts_with($inc, '<')) {
                    return $m[0];
                }
                // Expand magic constants
                $inc = str_replace('__DIR__', $fileDir, $inc);
                $inc = str_replace('__EXT__', $magicExt, $inc);
                $inc = str_replace('__INC__', $magicInc, $inc);
                $inc = str_replace('__CMD__', $magicCmd, $inc);
                $inc = str_replace('DIRECTORY_SEPARATOR', DIRECTORY_SEPARATOR, $inc);
                $inc = str_replace('\\', '/', $inc); // normalize Windows backslashes for PCRE
                $inc = rtrim($inc, "\r\n");           // strip trailing CR from .+ match on Windows
                // Wrap in quotes (simplify: strip . concatenation noise)
                $inc = preg_replace('/\s*\.\s*"/', '/', $inc);
                $inc = preg_replace('/"\s*\.\s*/', '', $inc);
                $inc = trim($inc, '" ');
                return $m[1] . $prefixPart . '"' . $inc . '"';
            },
            (string)$src
        );
        // Preprocess: expand magic constants in #flag directives
        $src = preg_replace_callback(
            '/^(#flag\s+(?:GCC|Clang|TCC|Windows|Linux|MacOS|Darwin)?\s*(?:GCC|Clang|TCC|Windows|Linux|MacOS|Darwin)?\s*)(.+)$/mi',
            function ($m) use ($fileDir, $magicExt, $magicInc, $magicCmd) {
                $prefix = $m[1];
                $flags = $m[2];
                // Expand magic constants
                $flags = str_replace('__DIR__', str_replace('\\', '/', $fileDir), $flags);
                $flags = str_replace('__EXT__', $magicExt, $flags);
                $flags = str_replace('__INC__', $magicInc, $flags);
                $flags = str_replace('__CMD__', $magicCmd, $flags);
                // Handle string concatenation: -I__DIR__ . "include" → -I__DIR__/include
                // Replace . " with / (insert path separator, not empty string)
                $flags = preg_replace('/\s*\.\s*"/', '/', $flags);
                $flags = preg_replace('/"\s*\.\s*/', '/', $flags);
                $flags = str_replace('"', '', $flags);  // remove remaining quotes
                $flags = str_replace('\\', '/', $flags);
                return $prefix . $flags;
            },
            (string)$src
        );
        if (preg_match_all('/^#import\s+(\w+)/m', (string)$src, $m)) {
            foreach ($m[1] as $extName) {
                if (isset($importedExts[$extName])) continue;  // 已导入，跳过
                // Security: #import only accepts plain extension names (no paths)
                if (str_contains($extName, '..') || str_contains($extName, '/') || str_contains($extName, '\\')) {
                    die("Error: #import '{$extName}' contains path traversal — only extension names are allowed\n");
                }
                $importedExts[$extName] = true;
                $extSrc = $extRoot . DIRECTORY_SEPARATOR . $extName . DIRECTORY_SEPARATOR . 'src';
                // Security: resolve via realpath and verify the path stays within ext/
                $extSrcReal = realpath($extSrc);
                if ($extSrcReal === false || !str_starts_with($extSrcReal, realpath($extRoot))) {
                    die("Error: #import '{$extName}' resolves outside the extensions directory\n");
                }
                $extSrc = $extSrcReal;
                if (!is_dir($extSrc)) die("Error: #import {$extName} — ext/{$extName}/src/ not found\n");
                $extPhp = glob($extSrc . DIRECTORY_SEPARATOR . '*.php');
                $extC   = glob($extSrc . DIRECTORY_SEPARATOR . '*.c');
                foreach ($extPhp as $f) { if (!in_array($f, $files)) $files[] = $f; }
                foreach ($extC   as $f) { $rf = realpath($f); if ($rf && !in_array($rf, $importCFiles)) $importCFiles[] = $rf; }
                echo "       #import {$extName} => " . count($extPhp) . " php + " . count($extC) . " c\n";
            }
        }
    }
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

    // --debug: enable #debug directive and print compile command
    // (manual parse, because getopt stops at first positional argument)
    $debugMode = in_array('--debug', $argv, true);

    // Collect known enum names (for cross-file references)
    $knownEnumNames = [];

    $orderedFiles = array_merge($otherFiles, [$mainFile]);
    foreach ($orderedFiles as $file) {
        echo "       + {$file}\n";
        $source = file_get_contents($file);
        if ($source === false || trim($source) === '') {
            die("Error: PHP file is empty: {$file}\n");
        }
        // Preprocess: expand magic constants in #include directives
        $fileDir = dirname(realpath($file));
        $source = preg_replace_callback(
            '/^(#include\s+)(?:(Windows|Linux|MacOS|Darwin|GCC|Clang|TCC)\s+)?(.+)$/mi',
            function ($m) use ($fileDir, $magicExt, $magicInc, $magicCmd) {
                $prefix = $m[2] ?? '';
                $inc = $m[3];
                $prefixPart = $prefix !== '' ? $m[2] . ' ' : '';
                if (str_starts_with($inc, '"') || str_starts_with($inc, '<')) {
                    return $m[0];
                }
                $inc = str_replace('__DIR__', $fileDir, $inc);
                $inc = str_replace('__EXT__', $magicExt, $inc);
                $inc = str_replace('__INC__', $magicInc, $inc);
                $inc = str_replace('__CMD__', $magicCmd, $inc);
                $inc = str_replace('DIRECTORY_SEPARATOR', DIRECTORY_SEPARATOR, $inc);
                $inc = str_replace('\\', '/', $inc); // normalize Windows backslashes for PCRE
                $inc = rtrim($inc, "\r\n");           // strip trailing CR from .+ match on Windows
                $inc = preg_replace('/\s*\.\s*"/', '/', $inc);
                $inc = preg_replace('/"\s*\.\s*/', '', $inc);
                $inc = trim($inc, '" ');
                return $m[1] . $prefixPart . '"' . $inc . '"';
            },
            (string)$source
        );
        // Preprocess: expand magic constants in #flag directives
        $source = preg_replace_callback(
            '/^(#flag\s+(?:GCC|Clang|TCC|Windows|Linux|MacOS|Darwin)?\s*(?:GCC|Clang|TCC|Windows|Linux|MacOS|Darwin)?\s*)(.+)$/mi',
            function ($m) use ($fileDir, $magicExt, $magicInc, $magicCmd) {
                $prefix = $m[1];
                $flags = $m[2];
                $flags = str_replace('__DIR__', str_replace('\\', '/', $fileDir), $flags);
                $flags = str_replace('__EXT__', $magicExt, $flags);
                $flags = str_replace('__INC__', $magicInc, $flags);
                $flags = str_replace('__CMD__', $magicCmd, $flags);
                // Handle string concatenation: -I__DIR__ . "include" → -I__DIR__/include
                // Replace . " with / (insert path separator, not empty string)
                $flags = preg_replace('/\s*\.\s*"/', '/', $flags);
                $flags = preg_replace('/"\s*\.\s*/', '/', $flags);
                $flags = str_replace('"', '', $flags);
                $flags = str_replace('\\', '/', $flags);
                return $prefix . $flags;
            },
            (string)$source
        );

        $lexer  = new Lexer($source, $debugMode);
        $tokens = $lexer->tokenize();
        $parser = new Parser($tokens, $debugMode, $ctTargetOS, $ctTargetArch, $ccClass);
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
        $allIncludes  = array_merge($allIncludes, $ast->includes);
        $allFlags     = array_merge($allFlags, $ast->ccFlags);
        $allCallbacks = array_merge($allCallbacks, $ast->callbacks);
        $allDebugs    = array_merge($allDebugs, $ast->debugs);
        $allCstructs  = array_merge($allCstructs, $ast->cstructs);

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

    // Output path (derived from entry filename, respect -os target)
    if ($outExe === '') {
        $ext = ($targetOS === null)
            ? ((PHP_OS_FAMILY === 'Windows') ? '.exe' : '')
            : (($targetOS === 'windows') ? '.exe' : '');
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

    // Dedup: #include by file, #flag by flags string
    $seenFiles = [];
    $allIncludes = array_values(array_filter($allIncludes, function ($inc) use (&$seenFiles) {
        $f = is_array($inc) ? $inc['file'] : $inc;
        if (isset($seenFiles[$f])) return false;
        $seenFiles[$f] = true;

        // Platform/compiler filtering (#include Linux "x.h" / #include Windows "y.h")
        if (is_array($inc) && !empty($inc['ctx'])) {
            $ctx = $inc['ctx'];
            // Case-insensitive platform matching (accept windows/linux/macos/darwin lowercase)
            $platformMap = ['windows' => 'Windows', 'linux' => 'Linux', 'darwin' => 'Darwin', 'macos' => 'Darwin'];
            $ctxLower = strtolower($ctx);
            $currentOS = PHP_OS_FAMILY;
            // OS filter
            if (isset($platformMap[$ctxLower]) && $platformMap[$ctxLower] !== $currentOS) return false;
            // Compiler filter (TCC/GCC/Clang)
            if (!isset($platformMap[$ctxLower])) {
                $ccLower = strtolower($GLOBALS['cc'] ?? 'tcc');
                $ccClass = 'TCC';
                if (str_contains($ccLower, 'gcc')) $ccClass = 'GCC';
                elseif (str_contains($ccLower, 'clang')) $ccClass = 'Clang';
                if ($ctx !== $ccClass) return false;
            }
        }
        return true;
    }));
    $seenFlags = [];
    $allFlags = array_values(array_filter($allFlags, function ($f) use (&$seenFlags) {
        $s = $f['flags'] ?? '';
        if (isset($seenFlags[$s])) return false;
        $seenFlags[$s] = true;
        return true;
    }));

    $merged = new ProgramNode($mainClass, $extraClasses, $functions, $constants, $enums, $allIncludes, $allFlags, $allCallbacks, $allDebugs, $allCstructs);

    // Resolve #include paths relative to each PHP file's directory
    $extraFlags = '';
    $extraCFiles = [];
    if (!empty($allIncludes)) {
        // Collect unique directories from all PHP source files
        $srcDirs = [];
        foreach ($orderedFiles as $f) {
            $d = realpath(dirname($f));
            if ($d) $srcDirs[$d] = true;
        }
        $srcDirs = array_keys($srcDirs);
        $extraFlags = ' -I"' . implode('" -I"', $srcDirs) . '"';

        // Extract -I paths from #flag directives (for #include search + security check)
        // __DIR__/__EXT__/__INC__/__CMD__ already expanded in prescan/parsing phase
        $flagIncludeDirs = [];
        $_platformMap = ['Windows' => 'Windows', 'Linux' => 'Linux', 'Darwin' => 'Darwin', 'MacOS' => 'Darwin'];
        $_currentOS = PHP_OS_FAMILY;
        $_ccClass = 'TCC';
        if ($cc !== null) {
            $_ccLower = strtolower($cc);
            if (str_contains($_ccLower, 'gcc')) $_ccClass = 'GCC';
            elseif (str_contains($_ccLower, 'clang')) $_ccClass = 'Clang';
        } elseif (PHP_OS_FAMILY === 'Darwin') {
            $_ccClass = 'Clang';
        }
        foreach ($allFlags as $f) {
            $pf = $f['platform'] ?? '';
            $cf = $f['compiler'] ?? '';
            $flagsStr = $f['flags'] ?? '';
            $platformOk = ($pf === '' || ($_platformMap[$pf] ?? '') === $_currentOS);
            $compilerOk = ($cf === '' || $cf === $_ccClass);
            if (!$platformOk || !$compilerOk) continue;
            // Extract -I paths (flagsStr already has __DIR__ expanded)
            $_tokens = preg_split('/\s+/', trim($flagsStr));
            foreach ($_tokens as $tok) {
                if (str_starts_with($tok, '-I') && strlen($tok) > 2) {
                    $path = substr($tok, 2);
                    // Strip surrounding quotes
                    $path = trim($path, '"');
                    $resolved = realpath($path);
                    if ($resolved !== false) {
                        $flagIncludeDirs[$resolved] = true;
                    }
                }
            }
        }
        $flagIncludeDirs = array_keys($flagIncludeDirs);

        // All search directories: srcDirs + -I paths from #flag
        $allSearchDirs = array_merge($srcDirs, $flagIncludeDirs);

        // Find companion .c files for each #include
        $projectRoot = str_replace('\\', '/', __DIR__);
        // PHAR 模式：解压到文件系统的 ext/ 不在 phar:// 路径下，需额外接受 PHAR 外部根
        $fsProjectRoot = $inPhar ? str_replace('\\', '/', $pharDir) : $projectRoot;
        // Allowed roots for security check:
        //   - TinyPHP project root (built-in includes)
        //   - PHAR fs root
        //   - User source directories (where PHP files are)
        //   - -I paths declared via #flag (user explicitly opted in)
        //   - CWD (user's project root)
        $allowedRoots = [$projectRoot, $fsProjectRoot];
        foreach ($allSearchDirs as $dir) {
            $allowedRoots[] = str_replace('\\', '/', $dir);
        }
        $allowedRoots[] = str_replace('\\', '/', realpath($cwd) ?: $cwd);
        foreach ($allIncludes as $inc) {
            $fileName = is_array($inc) ? $inc['file'] : $inc;
            $isQuoted = is_array($inc) ? ($inc['quoted'] ?? true) : true;
            // System headers (#include <math.h>) — 白名单校验
            if (!$isQuoted) {
                // 安全加固: 系统头文件白名单（防止任意引入系统 API）
                // 允许标准 C 库头文件 + 常见系统头
                $allowedSystemHeaders = [
                    // C 标准库
                    'stdio.h','stdlib.h','string.h','math.h','ctype.h','time.h',
                    'stdint.h','stddef.h','stdbool.h','stdarg.h','limits.h','float.h',
                    'errno.h','assert.h','locale.h','setjmp.h','signal.h','wchar.h',
                    'wctype.h','iso646.h','fenv.h','inttypes.h','complex.h','tgmath.h',
                    'iconv.h',
                    // POSIX 常用
                    'unistd.h','fcntl.h','sys/stat.h','sys/types.h','sys/wait.h',
                    'sys/time.h','sys/socket.h','sys/un.h','sys/mman.h','sys/resource.h',
                    'netinet/in.h','netinet/tcp.h','arpa/inet.h','netdb.h','pthread.h',
                    'dlfcn.h','poll.h','select.h','termios.h','pty.h','semaphore.h',
                    'dirent.h','utime.h','sys/utsname.h','sys/file.h','sys/ioctl.h',
                    // Windows 常用
                    'windows.h','winsock2.h','ws2tcpip.h','io.h','process.h','direct.h',
                    'conio.h','shlobj.h','shellapi.h','wincrypt.h','winreg.h',
                    // C++ 兼容
                    'cstring','cstdlib','cstdio','cmath','cstdint','vector','string','map',
                ];
                $cleanName = ltrim($fileName, '/');
                if (!in_array($cleanName, $allowedSystemHeaders, true)) {
                    // 允许 sys/ 和 net/ 和 arpa/ 和 netinet/ 前缀的系统头
                    $isAllowedPrefix = preg_match('/^(sys|net|arpa|netinet|netpacket|protocols)\//', $cleanName);
                    if (!$isAllowedPrefix) {
                        die("Error: #include <{$fileName}> is not in the system header whitelist.\n"
                          . "  Allowed: standard C library headers, common POSIX/Windows headers.\n"
                          . "  If you need this header, add it to the whitelist in tphp.php.\n");
                    }
                }
                continue;
            }
            // Security: resolve via realpath, verify within allowed roots
            $resolvedInclude = null;
            // Helper: check if a candidate path is within any allowed root
            $isAllowed = function (string $candidate) use ($allowedRoots): bool {
                foreach ($allowedRoots as $root) {
                    if (str_starts_with($candidate, $root)) return true;
                }
                return false;
            };
            // Absolute path (from __INC__/__EXT__/__CMD__ expansion): resolve directly
            if (str_starts_with($fileName, '/') || preg_match('/^[A-Za-z]:/', $fileName)) {
                $raw = realpath($fileName);
                if ($raw !== false) {
                    $candidate = str_replace('\\', '/', $raw);
                    if ($isAllowed($candidate)) {
                        $resolvedInclude = $candidate;
                    }
                }
            } else {
                // Relative path: resolve against source dirs + -I paths from #flag
                foreach ($allSearchDirs as $dir) {
                    $raw = realpath($dir . DIRECTORY_SEPARATOR . $fileName);
                    if ($raw === false) continue;
                    $candidate = str_replace('\\', '/', $raw);
                    if ($isAllowed($candidate)) {
                        $resolvedInclude = $candidate;
                        break;
                    }
                }
            }
            if ($resolvedInclude === null) {
                die("Error: #include '{$fileName}' resolves outside the project or does not exist\n"
                  . "  Project root: {$projectRoot}\n"
                  . "  Search dirs: " . implode(', ', $allSearchDirs) . "\n"
                  . "  Hint: use #flag -I__DIR__. \"your/include/path\" to add include search paths\n");
            }
            $baseName = basename($resolvedInclude, '.h');
            foreach ($allSearchDirs as $dir) {
                $cSrc = $dir . DIRECTORY_SEPARATOR . $baseName . '.c';
                if (file_exists($cSrc)) {
                    $extraCFiles[] = $cSrc;
                    break;
                }
            }
        }
        $extraCFiles = array_unique($extraCFiles);
    }

    // Process #flag directives (filter by platform + compiler)
    if (!empty($allFlags)) {
        $platformMap = ['Windows' => 'Windows', 'Linux' => 'Linux', 'Darwin' => 'Darwin', 'MacOS' => 'Darwin'];
        $currentOS = PHP_OS_FAMILY;
        // $ccClass 已在编译器选择阶段计算（条件编译共用）
        // Allowed #flag prefixes (whitelist — blocks arbitrary flag injection)
        $allowedFlagPrefixes = [
            '-I', '-L', '-l', '-D', '-U',
            '-O0', '-O1', '-O2', '-O3', '-Os', '-Og', '-Ofast',
            '-Wall', '-Wextra', '-Wpedantic', '-Werror', '-W', '-w',
            '-std', '-m', '-f', '-g', '-pthread', '-static', '-shared',
            '-B',  // TCC library path
            '-include',  // force-include header before other processing (GCC/Clang/TCC)
        ];
        foreach ($allFlags as $f) {
            $pf = $f['platform'] ?? '';
            $cf = $f['compiler'] ?? '';
            $flagsStr = $f['flags'] ?? '';
            $platformOk = ($pf === '' || ($platformMap[$pf] ?? '') === $currentOS);
            $compilerOk = ($cf === '' || $cf === $ccClass);
            if (!$platformOk || !$compilerOk) continue;

            // Security: block shell metacharacters (prevent command injection)
            if (preg_match('/[`$|;&><\n\r\\\\]/', $flagsStr)) {
                die("Error: #flag '{$flagsStr}' contains unsafe shell characters (backtick, $, |, ;, &, >, <, \\n, \\, newline)\n");
            }

            // Security: blacklist dangerous flag patterns
            // -fplugin=/path → GCC 插件可执行任意代码
            // -specs=/path    → GCC specs 文件可注入任意命令
            // -wrapper       → 包装器可执行任意命令
            // -ld=           → 链接器替换
            if (preg_match('/-fplugin\s*=?|-specs\s*=?|-wrapper\s|-ld\s*=/', $flagsStr)) {
                die("Error: #flag '{$flagsStr}' contains a blacklisted flag (-fplugin/-specs/-wrapper/-ld are not allowed for security)\n");
            }

            // Security: validate each individual flag token against whitelist
            $tokens = preg_split('/\s+/', trim($flagsStr));
            foreach ($tokens as $tok) {
                if ($tok === '' || $tok === '-') continue;
                // .c 文件：加入 extraCFiles（由编译器编译），不混入 extraFlags
                if (str_ends_with($tok, '.c')) {
                    $cPath = realpath($tok);
                    if ($cPath === false) {
                        die("Error: #flag '.c' file not found: {$tok}\n");
                    }
                    $extraCFiles[] = $cPath;
                    continue;
                }
                // Non-flag values (file paths, raw numbers) — always allowed
                if (!str_starts_with($tok, '-')) {
                    $extraFlags .= ' ' . $tok;
                    continue;
                }
                // Check against whitelist
                $allowed = false;
                foreach ($allowedFlagPrefixes as $pfx) {
                    if (str_starts_with($tok, $pfx)) { $allowed = true; break; }
                }
                if (!$allowed) {
                    die("Error: #flag '{$tok}' is not in the allowed list. Allowed prefixes: " . implode(', ', $allowedFlagPrefixes) . "\n");
                }
                // Security: resolve -I and -L paths via realpath (prevents traversal via ..)
                if ((str_starts_with($tok, '-I') || str_starts_with($tok, '-L')) && strlen($tok) > 2) {
                    $path = substr($tok, 2);
                    $resolved = realpath($path);
                    if ($resolved === false) {
                        die("Error: #flag '{$tok}' path does not exist: {$path}\n");
                    }
                    $extraFlags .= ' ' . $tok[0] . $tok[1] . '"' . $resolved . '"';
                    continue;
                }
                $extraFlags .= ' ' . $tok;
            }
        }
    }

    // 默认 -O2：GCC/Clang 自动加，TCC 不加（TCC 无优化级别）
    $ccLower = $cc !== null ? strtolower($cc) : '';
    if ((str_contains($ccLower, 'gcc') || str_contains($ccLower, 'clang'))
        && !str_contains($extraFlags, '-O')) {
        $extraFlags .= ' -O2';
    }
    // MinGW GCC workaround: math.h functions may not be declared
    if (PHP_OS_FAMILY === 'Windows' && str_contains($ccLower, 'gcc')) {
        $extraFlags .= ' -Wno-implicit-function-declaration -Wno-int-conversion -Wno-discarded-qualifiers';
    }

    // 分离 -L/-l 到 linkFlags：链接器单遍扫描，库必须在 .c 文件之后
    // （TCC/Unix 链接器对顺序敏感；-L/-l 放在源文件之前会导致 unresolved reference）
    $lateLinkFlags = '';
    $extraFlagTokens = preg_split('/\s+/', trim($extraFlags));
    $keptFlags = [];
    foreach ($extraFlagTokens as $tok) {
        if ($tok === '') continue;
        if (str_starts_with($tok, '-L') || str_starts_with($tok, '-l')) {
            $lateLinkFlags .= ' ' . $tok;
        } else {
            $keptFlags[] = $tok;
        }
    }
    $extraFlags = !empty($keptFlags) ? ' ' . implode(' ', $keptFlags) : '';

    if (!is_dir($outDir)) mkdir($outDir, 0777, true);

    $gen   = new CodeGenerator();
    $gen->isShared = $isShared;
    $cFile = $gen->generate($merged, $entryFile, $outDir);

    echo "       [YES] {$cFile}\n";

} catch (\Throwable $e) {
    fwrite(STDERR, "[NO] Transpile failed: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
    exit(1);
}

// --- Phase 2: C compile → binary ---
echo "[2/2] Compiling => {$outExe}...\n";

// TCC -B flag: computed after cross-compilation so we know the final compiler
$bFlag = '';
$tccLibDir = '';

// ── Cross-compilation ─────────────────────────────
if ($targetOS !== null) {
    $currentOS = strtolower(PHP_OS_FAMILY); // windows|linux|darwin
    if ($targetOS === $currentOS) {
        echo "[*] -os {$targetOS} -arch {$targetArch} == current, native compile\n";
    } else {
        echo "[*] Cross-compile: {$currentOS} → {$targetOS}/{$targetArch}\n";
        // Platform defines
        $platformDefines = [
            'windows' => '-D_WIN32 -DWIN32',
            'linux'   => '-D__linux__ -D__linux',
            'darwin'  => '-D__APPLE__ -D__MACH__',
        ];
        if (isset($platformDefines[$targetOS])) {
            $extraFlags .= ' ' . $platformDefines[$targetOS];
        }
        // Cross-compiler auto-detection
        // Priority: 1. clang -target (native cross-compile)  2. GCC triplet
        if ($cc === null) {
            $triplets = [
                'windows' . $targetArch => "{$targetArch}-windows-gnu",
                'linux'   . $targetArch => "{$targetArch}-linux-gnu",
                'darwin'  . $targetArch => "{$targetArch}-apple-darwin",
            ];
            $targetTriple = $triplets[$targetOS . $targetArch] ?? '';
            $found = null;

            // 1st: try system clang with -target (works from any platform)
            foreach (['clang', 'clang-19', 'clang-18', 'clang-17'] as $clangBin) {
                exec("\"{$clangBin}\" --version 2>&1", $vOut, $vRet);
                if ($vRet === 0) {
                    $found = "{$clangBin} -target {$targetTriple}";
                    break;
                }
            }
            // 2nd: try GCC cross-compiler triplets
            if ($found === null) {
                $gccTriplets = [
                    'windows' => ["{$targetArch}-w64-mingw32-", 'i686-w64-mingw32-'],
                    'linux'   => ["{$targetArch}-linux-gnu-"],
                    'darwin'  => ["{$targetArch}-apple-darwin-"],
                ];
                $candidates = $gccTriplets[$targetOS] ?? [];
                if ($targetArch === 'x86_64') {
                    $candidates = array_merge($candidates, $gccTriplets[$targetOS] ?? []);
                }
                foreach (array_unique($candidates) as $prefix) {
                    foreach (['gcc', 'clang'] as $suffix) {
                        $testCC = $prefix . $suffix;
                        exec("\"{$testCC}\" --version 2>&1", $vOut, $vRet);
                        if ($vRet === 0) { $found = $testCC; break 2; }
                        exec("where \"{$testCC}\" 2>nul", $wOut, $wRet);
                        if ($wRet === 0) { $found = $testCC; break 2; }
                    }
                }
            }
            if ($found !== null) {
                $cc = $found;
                // Separate binary from flags: "clang -target xxx" → ccExe=clang, extraFlags+=-target xxx
                if (str_contains($found, ' ')) {
                    [$ccBinary, $ccArgs] = explode(' ', $found, 2);
                    $ccExe = $ccBinary;
                    $extraFlags = $ccArgs . ' ' . $extraFlags;
                } else {
                    $ccExe = $found;
                }
                echo "[*] Auto-detected cross-compiler: {$found}\n";
            } else {
                $installHints = [
                    'windows' => [
                        'Linux'   => '  apt install clang mingw-w64',
                        'Darwin'  => '  brew install llvm mingw-w64',
                        'Windows' => '  winget install LLVM.LLVM',
                    ],
                    'linux' => [
                        'Darwin'  => '  brew install llvm',
                        'Windows' => '  winget install LLVM.LLVM',
                        'Linux'   => '',
                    ],
                    'darwin' => [
                        'Linux'   => '  apt install clang lld',
                        'Windows' => '  Unsupported (macOS requires Apple SDK)',
                        'Darwin'  => '',
                    ],
                ];
                $hint = $installHints[$targetOS][PHP_OS_FAMILY] ?? '';
                die("Error: no cross-compiler (clang/gcc) found for '{$targetOS}'.\n\n"
                  . "Install LLVM/clang (recommended) or MinGW-w64:\n"
                  . ($hint ? "{$hint}\n\n" : "\n")
                  . "Or specify manually: -cc <compiler> -os {$targetOS}\n"
                  . "Example: -cc x86_64-w64-mingw32-gcc -os windows\n");
            }
        }
    }
    // Platform-specific output extension
    if ($isShared) {
        // -shared 模式：动态库扩展名
        $shExt = ($targetOS === 'windows' || ($targetOS === null && PHP_OS_FAMILY === 'Windows')) ? '.dll'
               : (($targetOS === 'darwin' || ($targetOS === null && PHP_OS_FAMILY === 'Darwin')) ? '.dylib' : '.so');
        if (str_ends_with($outExe, '.exe')) $outExe = substr($outExe, 0, -4);
        if (!str_ends_with($outExe, $shExt)) $outExe .= $shExt;
    } elseif ($targetOS === 'windows' && !str_ends_with($outExe, '.exe')) {
        $outExe .= '.exe';
    } elseif ($targetOS !== 'windows' && str_ends_with($outExe, '.exe')) {
        $outExe = substr($outExe, 0, -4);
    }
}

// Now compute TCC-specific flags (after cross-compilation may have changed $cc)
$ccLower = $cc !== null ? strtolower($cc) : '';
$isTCC = ($cc === null || str_contains($ccLower, 'tcc'));
if ($isTCC && $inPhar) {
    if (PHP_OS_FAMILY === 'Windows') {
        $tccSysDir = $pharDir . DIRECTORY_SEPARATOR . 'tcc' . DIRECTORY_SEPARATOR . 'win32';
    } elseif (PHP_OS_FAMILY !== 'Darwin') {
        $tccSysDir = $pharDir . DIRECTORY_SEPARATOR . 'tcc';
    }
    if (isset($tccSysDir) && is_dir($tccSysDir)) {
        // build.sh puts libtcc1.a & headers at tcc/lib/tcc/
        $tccLibDir = $tccSysDir . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'tcc';
        $bFlag = ' -B"' . (is_dir($tccLibDir) ? $tccLibDir : $tccSysDir) . '"';
        // -nostdinc: 禁止搜索系统 /usr/include，防止与 PHAR 内打包的 glibc 头文件冲突
        $tccIncDir = $tccLibDir . DIRECTORY_SEPARATOR . 'include';
        if (is_dir($tccIncDir)) {
            $bFlag .= ' -nostdinc -I"' . $tccIncDir . '"';
            // Linux: 追加系统 include 路径作为补充
            // TCC 自带 glibc 替代头文件优先（-I 顺序在前），系统路径只补充
            // TCC 没有的开发库头文件（X11/Wayland/OpenGL/GTK 等）。
            // 这样用户 #include <X11/Xlib.h> 等系统开发库头时可被找到。
            if (PHP_OS_FAMILY !== 'Windows' && PHP_OS_FAMILY !== 'Darwin') {
                foreach (['/usr/local/include', '/usr/include'] as $sysInc) {
                    if (is_dir($sysInc)) {
                        $bFlag .= ' -I"' . $sysInc . '"';
                    }
                }
                // 多架构子目录（Debian/Ubuntu: /usr/include/x86_64-linux-gnu 等）
                // 提供 asm/ioctls.h 等内核 ABI 头文件，TCC 自带 bits/ioctls.h 是
                // 桩文件需 include <asm/ioctls.h>，但 asm/ 在 multiarch 子目录下。
                // Arch/Fedora 的 asm/ 直接在 /usr/include/asm/，已被上面覆盖。
                foreach (glob('/usr/include/*/asm') as $asmDir) {
                    $bFlag .= ' -I"' . dirname($asmDir) . '"';
                }
            }
        }
    }
} elseif ($isTCC) {
    // Dev mode: auto-detect TCC standalone directory
    if (PHP_OS_FAMILY !== 'Darwin') {
        $tccBase = dirname($ccExe);
        // build.sh puts libtcc1.a at tcc/lib/tcc/ — match that path
        $libDir = $tccBase . '/lib/tcc';
        if (is_dir($libDir) && file_exists($libDir . '/libtcc1.a')) {
            $bFlag = ' -B"' . realpath($libDir) . '"';
        } else {
            foreach ([$tccBase . '/tcc-standalone', $tccBase] as $dir) {
                if (is_dir($dir . '/lib') || is_dir($dir . '/include')) {
                    $bFlag = ' -B"' . realpath($dir) . '"';
                    break;
                }
            }
        }
        // Windows: -B 设置 tcc_lib_path（用于 libtcc1.a 等），但 -l 库搜索走 library_paths
        // 必须额外 -L 指向 win32/lib，否则 -lws2_32 找不到 ws2_32.def
        if (PHP_OS_FAMILY === 'Windows' && isset($bFlag)) {
            $winLibDir = $tccBase . '/lib';
            if (is_dir($winLibDir)) {
                $bFlag .= ' -L"' . realpath($winLibDir) . '"';
            }
        }
    }
}
if (PHP_OS_FAMILY === 'Darwin' && $isTCC) {
    $tccRoot = $inPhar ? ($pharDir . DIRECTORY_SEPARATOR . 'tcc') : dirname($ccExe);
    $tccLibDir = $tccRoot . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'tcc';
    if (!is_dir($tccLibDir)) $tccLibDir = $tccRoot;
    $bFlag = ' -B"' . $tccLibDir . '" -L"' . $tccLibDir . '"';
    $bFlag .= ' -I"' . $tccRoot . DIRECTORY_SEPARATOR . 'include' . '"';
}

$allCFiles = array_unique(array_merge($userCFiles, $extraCFiles, $importCFiles));
$extraSrcs = !empty($allCFiles) ? ' "' . implode('" "', $allCFiles) . '"' : '';
// Linux needs -lm for math functions (round, ceil, floor, sqrt, pow, etc.)
$linkFlags = '';
if (PHP_OS_FAMILY !== 'Windows' && ($targetOS === null || $targetOS !== 'windows')) {
    $linkFlags .= ' -lm';
}
// macOS: iconv 在独立 libiconv 中 (TCC 不会自动链接 libSystem 的 iconv 符号)
if ($targetOS === 'darwin' || ($targetOS === null && PHP_OS_FAMILY === 'Darwin')) {
    $linkFlags .= ' -liconv';
}

// Windows 命令行长度限制（cmd.exe 8191, CreateProcess 32767）
// 当源文件众多（如内置 zlib 15 个 .c + 扩展 .c + 用户 .c）时，命令行会超限。
// 使用 response file (@file) 机制：把源文件列表写入临时文件，用 @file 引用。
// TCC/GCC/Clang 均支持 @file 语法。
$responseFile = '';
if (PHP_OS_FAMILY === 'Windows' && strlen($extraSrcs) > 4000) {
    $respDir = $outDir;
    if (!is_dir($respDir)) @mkdir($respDir, 0777, true);
    $responseFile = $respDir . DIRECTORY_SEPARATOR . pathinfo($entryFile, PATHINFO_FILENAME) . '_rsp.txt';
    $respLines = [];
    foreach ($allCFiles as $cf) {
        $respLines[] = '"' . str_replace('/', DIRECTORY_SEPARATOR, $cf) . '"';
    }
    file_put_contents($responseFile, implode("\n", $respLines));
    $extraSrcs = ' @"' . $responseFile . '"';
}
// zlib/zip: 检测生成的 C 代码是否使用了 zlib（CodeGenerator 条件引入 os/zlib.h）
// 策略：统一使用内置 zlib 源码（include/os/zlib_src/）静态编译，无需外部 -lz 或 zlib1.dll。
// 这确保所有平台/编译器组合（包括纯 TCC 环境）都能使用 zlib/zip 扩展，零运行时依赖。
$zlibSrcDir = $includeDir . DIRECTORY_SEPARATOR . 'os' . DIRECTORY_SEPARATOR . 'zlib_src';
if (is_file($cFile) && strpos(file_get_contents($cFile), '#include "os/zlib.h"') !== false
    && is_dir($zlibSrcDir)) {
    // 将 zlib 源码 .c 文件加入编译列表（静态链接）
    $zlibSrcFiles = [
        'adler32.c', 'compress.c', 'crc32.c', 'deflate.c', 'gzclose.c',
        'gzlib.c', 'gzread.c', 'gzwrite.c', 'infback.c', 'inffast.c',
        'inflate.c', 'inftrees.c', 'trees.c', 'uncompr.c', 'zutil.c',
    ];
    foreach ($zlibSrcFiles as $src) {
        $srcPath = $zlibSrcDir . DIRECTORY_SEPARATOR . $src;
        if (is_file($srcPath)) {
            $allCFiles[] = $srcPath;
        }
    }
    // 重建 extraSrcs（包含新增的 zlib 源码）
    $extraSrcs = !empty($allCFiles) ? ' "' . implode('" "', $allCFiles) . '"' : '';
}
// stream: 检测生成的 C 代码是否使用了 stream 扩展（CodeGenerator 条件引入 ext/stream/src/stream.h）
// Windows 需要链接 ws2_32.lib（winsock2）；POSIX socket API 在 libc 中无需额外链接
if (is_file($cFile) && strpos(file_get_contents($cFile), '#include "ext/stream/src/stream.h"') !== false
    && PHP_OS_FAMILY === 'Windows') {
    $lateLinkFlags .= ' -lws2_32';
}
// openssl: 链接 flags 现由 ext/openssl/src/openssl.php 通过 #flag + #if TCC 条件编译处理
// （TCC 用 lib-tcc/，GCC/Clang 用 lib/ 或 lib64/；-I/-L/-l 全部在 openssl.php 中声明）
// tphp.php 不再自动检测和添加 OpenSSL flags，避免重复
// -shared 模式：生成动态库
$sharedFlag = $isShared ? ' -shared' : '';
// 项目根目录作为额外 -I 路径，让 ext/ 下的扩展头文件（如 ext/stream/src/stream.h）可被 #include 查找到
$projectRoot = dirname($includeDir);
$cmd = sprintf(
    '"%s" %s%s%s%s -I"%s" -I"%s" -o "%s" "%s"%s%s 2>&1',
    $ccExe, $bFlag, $extraFlags, $linkFlags, $sharedFlag, $includeDir, $projectRoot, $outExe, $cFile, $extraSrcs, $lateLinkFlags
);

$tccOutput = [];
$retval = 0;
// --debug: print full compile command
if ($debugMode) echo "[DEBUG] {$cmd}\n";
// TCC resolves crtprefix/libpaths from CWD at runtime.
// Must run from the TCC binary's directory so lib/tcc → ./lib/tcc/ = tcc/lib/tcc/
$savedCwd = getcwd();
$execCwd = $savedCwd;
if ($isTCC) {
    $binDir = dirname($ccExe);
    if (is_dir($binDir)) $execCwd = $binDir;
}
if ($execCwd !== false && @chdir($execCwd)) {
    exec($cmd, $tccOutput, $retval);
    @chdir($savedCwd);
} else {
    exec($cmd, $tccOutput, $retval);
}

if ($retval !== 0 || !file_exists($outExe) || filesize($outExe) < 64) {
    // TCC fallback: 当 .a 静态库报 "invalid object file" 时（MinGW ar 长名表格式
    // TCC ar 读取器解析有 bug），自动提取 .a 中的 .obj 文件并直接链接，绕过 .a 读取。
    // 仅 TCC + Windows 触发，其他编译器保持原行为。
    $tccOutputStr = implode("\n", $tccOutput);
    if ($isTCC && str_contains($tccOutputStr, 'invalid object file')) {
        $extractedObjs = [];
        foreach ($allCFiles as $cf) {
            // 仅处理 .a 文件（companion C 文件列表里可能混入 .a 路径，但通常不会）
            // 这里从 $lateLinkFlags 中提取 -L 路径 + -l 名称推导 .a 路径
        }
        // 从 lateLinkFlags 解析 -L 和 -l，推导 .a 文件路径
        preg_match_all('/-L"([^"]+)"/', $lateLinkFlags, $libDirs);
        preg_match_all('/-l(\S+)/', $lateLinkFlags, $libNames);
        $searchDirs = $libDirs[1] ?? [];
        // 也加入 TCC 默认库路径
        if (!empty($bFlag)) {
            preg_match_all('/-B"([^"]+)"/', $bFlag, $bDirs);
            foreach ($bDirs[1] ?? [] as $d) $searchDirs[] = $d;
        }
        $aFilesToExtract = [];
        foreach ($libNames[1] ?? [] as $ln) {
            // 跳过系统库（ws2_32, advapi32, m 等）— 只处理有对应 .a 的
            foreach ($searchDirs as $dir) {
                foreach (["lib{$ln}.a", "{$ln}.a", "lib{$ln}.lib"] as $cand) {
                    $path = $dir . DIRECTORY_SEPARATOR . $cand;
                    if (file_exists($path)) {
                        $aFilesToExtract[] = $path;
                        break 2;
                    }
                }
            }
        }
        if (!empty($aFilesToExtract)) {
            // 提取 .a 中的 .obj 文件到临时目录
            $tmpDir = sys_get_temp_dir() . '/tphp_lib_extract_' . md5(implode('|', $aFilesToExtract));
            if (!is_dir($tmpDir)) @mkdir($tmpDir, 0777, true);
            $allObjs = [];
            foreach ($aFilesToExtract as $aFile) {
                $allObjs = array_merge($allObjs, extractArMembers($aFile, $tmpDir));
            }
            if (!empty($allObjs)) {
                // 检测提取的 .obj 是否为 TCC 可链接格式（ELF）。
                // TCC 在 Windows 上生成 ELF 目标文件，无法链接 MinGW 的 COFF .obj。
                // 如果首个 .obj 不是 ELF 格式，跳过 fallback（避免误导性错误）。
                $firstObj = $allObjs[0];
                $objHead = @file_get_contents($firstObj, false, null, 0, 4);
                $isElf = ($objHead !== false && substr($objHead, 0, 4) === "\x7fELF");
                if (!$isElf) {
                    echo "[NO] Compile failed:\n";
                    if (!empty($tccOutput)) echo implode("\n", $tccOutput) . "\n";
                    echo "[Hint] TCC uses ELF object format but '{$aFile}' contains COFF .obj files.\n";
                    echo "       Rebuild the library with TCC: tcc -c ... && tcc -ar rcs lib<name>.a *.o\n";
                    exit(1);
                }
                // 移除 -l<name> 对应的库（保留系统库如 -lws2_32）
                // 简化：移除所有 -l 和 -L，把 .obj 文件加到 extraSrcs
                $newLateLink = '';
                foreach (preg_split('/\s+/', trim($lateLinkFlags)) as $tok) {
                    if ($tok === '' || str_starts_with($tok, '-l') || str_starts_with($tok, '-L')) continue;
                    $newLateLink .= ' ' . $tok;
                }
                // 构建新的源文件列表（原 extraSrcs + .obj 文件）
                // 注意：如果原 extraSrcs 已是 @responseFile，则保持不变，只追加 .obj
                $newExtraSrcs = $extraSrcs . ' "' . implode('" "', $allObjs) . '"';
                // Windows 命令行长度限制：fallback 路径同样可能超限，使用 response file
                $fallbackRespFile = '';
                if (PHP_OS_FAMILY === 'Windows' && strlen($newExtraSrcs) > 4000) {
                    $fallbackRespFile = $outDir . DIRECTORY_SEPARATOR . pathinfo($entryFile, PATHINFO_FILENAME) . '_rsp2.txt';
                    $respLines = [];
                    // 写入所有源文件（allCFiles + allObjs）
                    foreach ($allCFiles as $cf) {
                        $respLines[] = '"' . str_replace('/', DIRECTORY_SEPARATOR, $cf) . '"';
                    }
                    foreach ($allObjs as $obj) {
                        $respLines[] = '"' . $obj . '"';
                    }
                    file_put_contents($fallbackRespFile, implode("\n", $respLines));
                    $newExtraSrcs = ' @"' . $fallbackRespFile . '"';
                }
                $cmd2 = sprintf(
                    '"%s" %s%s%s -I"%s" -I"%s" -o "%s" "%s"%s%s 2>&1',
                    $ccExe, $bFlag, $extraFlags, $linkFlags, $includeDir, $projectRoot, $outExe, $cFile, $newExtraSrcs, $newLateLink
                );
                if ($debugMode) echo "[DEBUG] TCC .a fallback, extracting .obj files...\n";
                $tccOutput2 = [];
                if ($execCwd !== false && @chdir($execCwd)) {
                    exec($cmd2, $tccOutput2, $retval2);
                    @chdir($savedCwd);
                } else {
                    exec($cmd2, $tccOutput2, $retval2);
                }
                if ($retval2 === 0 && file_exists($outExe) && filesize($outExe) >= 64) {
                    $tccOutput = $tccOutput2;
                    $retval = 0;
                } else {
                    echo "[NO] Compile failed (TCC .a fallback also failed):\n";
                    if (!empty($tccOutput2)) echo implode("\n", $tccOutput2) . "\n";
                    exit(1);
                }
            } else {
                echo "[NO] Compile failed:\n";
                if (!empty($tccOutput)) echo implode("\n", $tccOutput) . "\n";
                exit(1);
            }
        } else {
            echo "[NO] Compile failed:\n";
            if (!empty($tccOutput)) echo implode("\n", $tccOutput) . "\n";
            exit(1);
        }
    } else {
        echo "[NO] Compile failed:\n";
        if (!empty($tccOutput)) echo implode("\n", $tccOutput) . "\n";
        exit(1);
    }
}

echo "       [YES] {$outExe}\n";

// --debug: run binary and compare expected vs actual output
if ($debugMode) {
    $debugLines = !empty($allDebugs) ? $allDebugs : $merged->debugs;
    if (empty($debugLines)) {
        // nothing to compare
    } else {
        exec('"' . $outExe . '" 2>&1', $actualOutput, $runRet);
        echo "\n";
        $count = max(count($debugLines), count($actualOutput));
        $failed = false;
        for ($i = 0; $i < $count; $i++) {
            $expect = $debugLines[$i] ?? '';
            $actual = $actualOutput[$i] ?? '';
            // #debug ~ 前缀表示近似值，跳过严格比对（如时间相关输出）
            if (str_starts_with($expect, '~ ')) {
                echo "[REF] " . substr($expect, 2) . "  (actual: {$actual})\n";
            } elseif ($expect === $actual) {
                echo "[YES] {$expect}\n";
            } else {
                echo "\n[FAIL] --debug mismatch at line " . ($i + 1) . "\n";
                echo "  expected: {$expect}\n";
                echo "  got     : {$actual}\n\n";
                $failed = true;
                break;
            }
        }
        if ($failed) {
            echo "Test FAILED. Run without --debug to see full output.\n";
            exit(1);
        }
        echo "\n[PASS] All assertions matched.\n";
    }
}

// ============================================================
/**
 * 从 ar 归档（.a 静态库）提取成员到指定目录。
 * 处理 BSD 长名表（//  成员）和 GNU 长名表（/N 索引）格式。
 * 仅提取 COFF/ELF 目标文件（.obj/.o），跳过符号表和索引成员。
 *
 * @param string $aFile  .a 文件路径
 * @param string $outDir 提取目录
 * @return string[] 提取的 .obj 文件完整路径列表
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
    $members = []; // [name => [dataStart, size]]

    // 第一遍：收集成员，识别长名表
    while ($pos + 60 <= $len) {
        $header = substr($bytes, $pos, 60);
        $nameRaw = rtrim(substr($header, 0, 16));
        $sizeStr = rtrim(substr($header, 48, 10));
        if (!ctype_digit($sizeStr)) break;
        $size = (int)$sizeStr;
        $dataStart = $pos + 60;
        if ($dataStart + $size > $len) break;

        if ($nameRaw === '//') {
            // BSD/GNU 长名表
            $longNames = substr($bytes, $dataStart, $size);
        } elseif ($nameRaw !== '/' && !str_starts_with($nameRaw, '/')) {
            // 普通成员名（可能带尾部 /）
            $members[] = [rtrim($nameRaw, '/'), $dataStart, $size];
        } elseif (preg_match('/^\/(\d+)$/', $nameRaw, $m) && $longNames !== null) {
            // GNU 长名引用：/N  → N 是 longNames 中的偏移
            $offset = (int)$m[1];
            $end = strpos($longNames, "\0", $offset);
            if ($end === false) $end = strlen($longNames);
            $realName = rtrim(substr($longNames, $offset, $end - $offset), '/');
            $members[] = [$realName, $dataStart, $size];
        }
        // 符号表成员（nameRaw === '/'）跳过

        $pos = $dataStart + $size;
        if ($size % 2 === 1) $pos++; // 2 字节对齐
    }

    // 第二遍：提取目标文件成员
    if (!is_dir($outDir)) @mkdir($outDir, 0777, true);
    $extracted = [];
    $usedNames = [];
    foreach ($members as [$name, $dataStart, $size]) {
        // 仅提取 .obj/.o 文件
        if (!preg_match('/\.(obj|o)$/i', $name)) continue;
        $base = basename($name);
        // 避免重名
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

// ============================================================
/** @param string[] $args
 *  @return array{0: string[], 1: string[]} */
function collectFiles(array $args): array
{
    $files = [];
    $cFiles = [];
    foreach ($args as $arg) {
        if ($arg === '.') {
            $baseDir = getcwd();
            // 点指令只收集 .php 文件，.c 文件需通过 #flag 显式声明
            $files = array_merge($files, scanPhpFiles($baseDir));
        } elseif (is_file($arg)) {
            $real = realpath($arg) ?: $arg;
            if (isInBuildDir($real)) {
                die("Error: files inside build/ are not allowed: {$arg}\n");
            }
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
  -os <target>      cross-compile target: windows, linux, macos
  -arch <arch>      target architecture: x86_64, aarch64 (default: host)
  -shared           compile as shared library (.dll/.so/.dylib)
  --debug           print full compile command
  -v, --version     show version and exit
  -h, --help        show help

Examples:
  tphp main.php demo.php
  tphp .
  tphp main.php -o app.exe
  tphp main.php -cc gcc
  tphp main.php -cc "clang -O2"
  tphp main.php -os linux                          (x86_64 Linux)
  tphp main.php -os linux -arch aarch64             (ARM64 Linux)
  tphp main.php -os windows -cc gcc                 (x86_64 Windows via mingw)
  tphp lib.php -shared -o mylib.dll                 (shared library with #[Export])

HELP;
    exit(0);
}
