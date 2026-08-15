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

/** TinyPHP 版本号（唯一手动维护处，CodeGenerator/编译产物均从此派生） */
const TPHP_VERSION = '0.2.0-beta.11';

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
// SSA 路径相关模块（仅 --ssa 模式使用，但 require_once 开销可忽略）
require_once __DIR__ . '/src/AST/FlatAst.php';
require_once __DIR__ . '/src/AST/FlatAstConverter.php';
require_once __DIR__ . '/src/SSA/SSA.php';
require_once __DIR__ . '/src/SSA/SSABuilder.php';
require_once __DIR__ . '/src/SSA/SSAOptPass.php';
require_once __DIR__ . '/src/SSA/SSAToCGenerator.php';
require_once __DIR__ . '/src/Helpers.php';

// --- Parse arguments ---
$options = getopt('f:o:hv', ['help', 'os:', 'arch:', 'debug', 'version']);
$cc        = null;
$targetOS  = null; // -os windows|linux|macos
$targetArch = null; // -arch x86_64|aarch64
$isShared  = false; // -shared: 生成动态库

// Normalize arch name
$archMap = ['x86_64' => 'x86_64', 'amd64' => 'x86_64', 'x64' => 'x86_64',
            'aarch64' => 'aarch64', 'arm64' => 'aarch64',
            'armv7a' => 'armv7a', 'armeabi-v7a' => 'armv7a',
            'i686' => 'i686', 'x86' => 'i686', 'arm' => 'arm'];

// Manual parse -cc xxx, -os xxx, -arch xxx and -o xxx (PHP getopt not fully compatible)
$posArgs = [];
$archExplicit = false;  // 用户是否显式指定 -arch（Android 多 ABI 模式判断用）
$skipAndroidApk = false; // 内部标志：多 ABI 子进程跳过 Gradle 打包
for ($i = 1, $n = count($argv); $i < $n; $i++) {
    if ($argv[$i] === '-cc' && isset($argv[$i + 1])) {
        $cc = $argv[++$i];
    } elseif ($argv[$i] === '-arch' && isset($argv[$i + 1])) {
        $targetArch = $archMap[strtolower($argv[++$i])] ?? null;
        if ($targetArch === null) die("Error: unknown arch '{$argv[$i]}'. Use: x86_64, aarch64, armv7a, i686\n");
        $archExplicit = true;
    } elseif ($argv[$i] === '-os' && isset($argv[$i + 1])) {
        $targetOS = strtolower($argv[++$i]);
        // Normalize: macos → darwin
        if ($targetOS === 'macos' || $targetOS === 'mac') $targetOS = 'darwin';
        // Validate: reject unknown OS early to avoid confusing cross-compile errors
        if (!in_array($targetOS, ['windows', 'linux', 'darwin', 'android'], true)) {
            die("Error: unknown target OS '{$targetOS}'. Use: windows, linux, macos, android\n");
        }
    } elseif ($argv[$i] === '-o' && isset($argv[$i + 1])) {
        $outExe = $argv[++$i]; // 覆盖 getopt 解析
    } elseif ($argv[$i] === '-shared') {
        $isShared = true;
    } elseif ($argv[$i] === '--no-android-apk') {
        // 内部标志：多 ABI 子进程编译 .so 时跳过 Gradle APK 构建（主进程统一打包）
        $skipAndroidApk = true;
    } elseif (!str_starts_with($argv[$i], '-')) {
        $posArgs[] = $argv[$i];
    }
}
$args = $posArgs;
// Also check --os=xxx, --arch=xxx long form
if (isset($options['os'])) {
    $targetOS = strtolower($options['os']);
    if ($targetOS === 'macos' || $targetOS === 'mac') $targetOS = 'darwin';
    if (!in_array($targetOS, ['windows', 'linux', 'darwin', 'android'], true)) {
        die("Error: unknown target OS '{$targetOS}'. Use: windows, linux, macos, android\n");
    }
}
if (isset($options['arch']) && $targetArch === null) {
    $targetArch = $archMap[strtolower($options['arch'])] ?? null;
    if ($targetArch === null) die("Error: unknown arch '{$options['arch']}'. Use: x86_64, aarch64, armv7a, i686\n");
    $archExplicit = true;
}
// Default arch per target OS: Windows/Linux → x86_64, macOS/Android → aarch64
if ($targetOS !== null && $targetArch === null) {
    $targetArch = ($targetOS === 'darwin' || $targetOS === 'android') ? 'aarch64' : 'x86_64';
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
    // If it's a bare name (no path separator), resolve via PATH so that
    // dirname($ccExe) yields the real installation directory.
    // This is critical for TCC: the -B flag (computed from dirname($ccExe))
    // must point to TCC's lib/include directory, otherwise tccdefs.h is not
    // found and compilation fails with "include file 'tccdefs.h' not found".
    if (!str_contains($ccExe, '/') && !str_contains($ccExe, '\\')) {
        $pathDirs = explode(PATH_SEPARATOR, (string)getenv('PATH'));
        $exeExts = (PHP_OS_FAMILY === 'Windows') ? ['.exe', '.bat', '.cmd', ''] : [''];
        foreach ($pathDirs as $dir) {
            if ($dir === '') continue;
            foreach ($exeExts as $ext) {
                $candidate = rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $ccExe . $ext;
                if (file_exists($candidate)) {
                    $ccExe = $candidate;
                    break 2;
                }
            }
        }
        // If not resolved, leave as bare name — let exec handle the error
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
    // Android 目标使用 NDK clang（在后续 Android NDK 探测逻辑中设置），跳过 TCC 检查
    if ($targetOS !== 'android' && !file_exists($ccExe)) die("Error: built-in TCC not found: {$ccExe}\nBuild TCC first or use -cc to specify another compiler\n");
}

if (!is_dir($includeDir))    die("Error: include directory not found: {$includeDir}\n");

// Detect compiler class early — used by Parser for #if 条件编译
//   TCC (built-in), GCC, or Clang
$ccClass = 'TCC';
if ($cc !== null) {
    $ccLower = strtolower($cc);
    if (str_contains($ccLower, 'gcc')) $ccClass = 'GCC';
    elseif (str_contains($ccLower, 'clang')) $ccClass = 'Clang';
} elseif ($targetOS === 'android') {
    // Android 目标使用 NDK clang（Phase 2 才设置 $cc），条件编译求值阶段就需要知道是 Clang
    $ccClass = 'Clang';
} elseif (PHP_OS_FAMILY === 'Darwin') {
    $ccClass = 'Clang';
}
// 目标 OS/Arch（条件编译求值用）：未指定时回退到宿主环境
$ctTargetOS   = $targetOS ?? strtolower(PHP_OS_FAMILY);
$ctTargetArch = $targetArch ?? strtolower(php_uname('m'));
// #flag/#include 平台过滤用：交叉编译时用目标 OS（大写形式，与 PHP_OS_FAMILY 一致）
$_effectiveOS = $targetOS !== null
    ? resolvePlatform($targetOS ?? PHP_OS_FAMILY)
    : PHP_OS_FAMILY;

// --- Phase 1: Transpile all PHP → C ---
$allFilesStr = implode(', ', array_map(fn($f) => basename($f), $files));
// 编译缓存（initialize early to avoid goto skip issues）
$extraCFiles = [];
// 编译缓存：用源文件 SHA256 + TPHP_VERSION 作为 key，跳过未变化的重复转译
$cwd = getcwd();
$outDir = $cwd . DIRECTORY_SEPARATOR . 'build';
$cacheDir = $outDir . DIRECTORY_SEPARATOR . '.tphp_cache';
$srcHash = TPHP_VERSION;
foreach ($files as $f) { $srcHash .= hash_file('sha256', $f); }
foreach ($userCFiles as $f) { $srcHash .= hash_file('sha256', $f); }
// 包含 common.h 哈希——头文件变更时缓存自动失效
$commonH = __DIR__ . DIRECTORY_SEPARATOR . 'include' . DIRECTORY_SEPARATOR . 'common.h';
$srcHash .= is_file($commonH) ? hash_file('sha256', $commonH) : '';
$cacheKey = hash('sha256', $srcHash . ($cc ?? 'tcc') . ($targetOS ?? '') . ($targetArch ?? ''));
$cachedCFile = $cacheDir . DIRECTORY_SEPARATOR . $cacheKey . '.c';
$cachedMetaFile = $cachedCFile . '.json';
$cFile = $outDir . DIRECTORY_SEPARATOR . pathinfo($entryFile, PATHINFO_FILENAME) . '.c';
$cacheHit = false;

// 预初始化：缓存命中时跳过转译阶段，这些变量需在编译阶段使用前定义
$debugMode = in_array('--debug', $argv, true);
$extraFlags = '';
$lateLinkFlags = '';

// 缓存命中判定：.c 与 .json 元数据必须同时存在。
// 元数据记录转译阶段收集的编译上下文（#flag 标志、ext .c 源文件、
// #import 入口重排、默认输出名），命中时恢复——否则编译阶段会丢失
// -I 路径（如 mbedtls 头找不到）和 ext 源文件（链接失败）。
$cacheMeta = null;
if (is_file($cachedCFile) && is_file($cachedMetaFile)) {
    $meta = json_decode((string)file_get_contents($cachedMetaFile), true);
    // 元数据合法性：入口文件仍存在 + TinyPHP 根目录未移动（-I 绝对路径依赖）
    if (is_array($meta)
        && isset($meta['entry'], $meta['tphpRoot'])
        && $meta['tphpRoot'] === __DIR__
        && is_file($meta['entry'])) {
        $cacheMeta = $meta;
    }
}

if ($cacheMeta !== null) {
    // 恢复转译阶段状态（仅 -o 未指定时才用元数据中的默认输出名）
    $entryFile = $cacheMeta['entry'];
    if ($outExe === '' && !empty($cacheMeta['outExe'])) $outExe = $cacheMeta['outExe'];
    $extraFlags    = (string)($cacheMeta['extraFlags'] ?? '');
    $lateLinkFlags = (string)($cacheMeta['lateLinkFlags'] ?? '');
    $extraCFiles   = array_values(array_filter(
        array_map('strval', (array)($cacheMeta['extraCFiles'] ?? [])), 'is_file'));

    if (!is_dir($outDir)) mkdir($outDir, 0777, true);
    $cFile = $outDir . DIRECTORY_SEPARATOR . pathinfo($entryFile, PATHINFO_FILENAME) . '.c';
    if (copy($cachedCFile, $cFile)) {
        // 与非缓存路径保持一致：同步到 -o 输出名前缀（并行测试同名 .php 不冲突）
        if ($outExe !== '') {
            $altCFile = $outDir . DIRECTORY_SEPARATOR . pathinfo($outExe, PATHINFO_FILENAME) . '.c';
            if ($altCFile !== $cFile) { @rename($cFile, $altCFile); $cFile = $altCFile; }
        }
        echo "[1/2] Transpiling {$allFilesStr} => C... [CACHED]\n";
        echo "       [YES] {$cFile}\n";
        $cacheHit = true;
    }
}

if (!$cacheHit) {
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
    // ── #import 预扫描：引入 ext/name/src/*.php → $files ────
    // 用 for 而非 foreach：扩展文件可能有自己的 #import，需递归扫描
    $extRoot = $inPhar ? ($extRootPhar ?? __DIR__ . DIRECTORY_SEPARATOR . 'ext') : (__DIR__ . DIRECTORY_SEPARATOR . 'ext');
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
        $src = preprocessFlags((string)$src, $fileDir, $magicExt, $magicInc, $magicCmd);
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
                // #import 只收集 .php 文件；C 依赖由 ext 的 .php 通过 #flag 显式声明
                // （如 #flag __EXT__ . "name/src/name.c"），符合 phpc 显式模型
                $extPhp = glob($extSrc . DIRECTORY_SEPARATOR . '*.php');
                foreach ($extPhp as $f) { if (!in_array($f, $files)) $files[] = $f; }
                echo "       #import {$extName} => " . count($extPhp) . " php\n";
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

    // --ssa: 启用 SSA 中间表示路径（FlatAst → SSA → 优化 → C）
    $ssaMode = in_array('--ssa', $argv, true);

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

    // Clean build directory before compiling
    //   只清理 build/ 下的直接文件（.c/.o/.exe 等），保留子目录（如 build/bench/）
    //   rmdir 可能因子目录存在而失败，用 @ 抑制 warning
    if (is_dir($outDir)) {
        $contents = glob($outDir . DIRECTORY_SEPARATOR . '*');
        if ($contents !== false) {
            foreach ($contents as $f) { if (is_file($f)) @unlink($f); }
        }
        @rmdir($outDir);
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
            $ctxLower = strtolower($ctx);
            $currentOS = $_effectiveOS;
            $resolved = resolvePlatform($ctxLower);
            // OS filter
            if ($resolved !== $ctxLower && $resolved !== $currentOS) return false;
            // Compiler filter (TCC/GCC/Clang)
            if ($resolved === $ctxLower) {
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
        $_currentOS = $_effectiveOS;
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
            $platformOk = ($pf === '' || resolvePlatform($pf) === $_currentOS);
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
                    // mbedtls（本地源码编译，由 ext/openssl 扩展使用，通过 -I 路径查找）
                    'mbedtls/aes.h','mbedtls/aria.h','mbedtls/asn1.h','mbedtls/asn1write.h',
                    'mbedtls/base64.h','mbedtls/bignum.h','mbedtls/block_cipher.h',
                    'mbedtls/build_info.h','mbedtls/camellia.h','mbedtls/ccm.h','mbedtls/chacha20.h',
                    'mbedtls/chachapoly.h','mbedtls/check_config.h','mbedtls/cipher.h','mbedtls/cmac.h',
                    'mbedtls/compat-2.x.h','mbedtls/constant_time.h','mbedtls/ctr_drbg.h',
                    'mbedtls/debug.h','mbedtls/des.h','mbedtls/dhm.h','mbedtls/ecdh.h',
                    'mbedtls/ecdsa.h','mbedtls/ecjpake.h','mbedtls/ecp.h','mbedtls/entropy.h',
                    'mbedtls/error.h','mbedtls/gcm.h','mbedtls/hkdf.h','mbedtls/hmac_drbg.h',
                    'mbedtls/lms.h','mbedtls/md.h','mbedtls/md5.h','mbedtls/memory_buffer_alloc.h',
                    'mbedtls/net_sockets.h','mbedtls/nist_kw.h','mbedtls/oid.h','mbedtls/pem.h',
                    'mbedtls/pk.h','mbedtls/pkcs12.h','mbedtls/pkcs5.h','mbedtls/pkcs7.h',
                    'mbedtls/platform.h','mbedtls/platform_time.h','mbedtls/platform_util.h',
                    'mbedtls/poly1305.h','mbedtls/private_access.h','mbedtls/psa_util.h',
                    'mbedtls/ripemd160.h','mbedtls/rsa.h','mbedtls/sha1.h','mbedtls/sha256.h',
                    'mbedtls/sha3.h','mbedtls/sha512.h','mbedtls/ssl.h','mbedtls/ssl_cache.h',
                    'mbedtls/ssl_ciphersuites.h','mbedtls/ssl_cookie.h','mbedtls/ssl_ticket.h',
                    'mbedtls/threading.h','mbedtls/timing.h','mbedtls/version.h','mbedtls/x509.h',
                    'mbedtls/x509_crl.h','mbedtls/x509_crt.h','mbedtls/x509_csr.h',
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
            // #include 只负责引入头文件；同名 .c 依赖由 #flag 显式声明
            // （如 #flag __EXT__ . "name/src/name.c"），符合 phpc 显式模型
        }
        $extraCFiles = array_unique($extraCFiles);
    }

    // Process #flag directives (filter by platform + compiler)
    if (!empty($allFlags)) {
        $currentOS = $_effectiveOS;
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
            $platformOk = ($pf === '' || resolvePlatform($pf) === $currentOS);
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
            // macOS framework 链接：-framework X → -Wl,-framework,X
            // TCC 不识别 -framework 语法（会把 X 当作输入文件），需通过 -Wl, 透传给系统 ld
            $fwTokens = [];
            for ($ti = 0; $ti < count($tokens); $ti++) {
                if ($tokens[$ti] === '-framework' && isset($tokens[$ti + 1])) {
                    $fwTokens[] = '-Wl,-framework,' . $tokens[$ti + 1];
                    $ti++;
                } elseif ($tokens[$ti] === '-F' && isset($tokens[$ti + 1])) {
                    // framework 搜索路径同理：-F path → -Wl,-F,path
                    $fwTokens[] = '-Wl,-F,' . $tokens[$ti + 1];
                    $ti++;
                } else {
                    $fwTokens[] = $tokens[$ti];
                }
            }
            $tokens = $fwTokens;
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
    // 支持 TPHP_CFLAGS 环境变量注入额外编译标志（CI 用，如 ASan: -fsanitize=address,undefined）
    $tphpCflagsEnv = getenv('TPHP_CFLAGS');
    if ($tphpCflagsEnv !== false && $tphpCflagsEnv !== '') {
        $extraFlags .= ' ' . $tphpCflagsEnv;
    }
    $ccLower = $cc !== null ? strtolower($cc) : '';
    if ((str_contains($ccLower, 'gcc') || str_contains($ccLower, 'clang'))
        && !str_contains($extraFlags, '-O')) {
        $extraFlags .= ' -O2';
    }
    // MinGW GCC workaround: math.h functions may not be declared
    if (PHP_OS_FAMILY === 'Windows' && str_contains($ccLower, 'gcc')) {
        $extraFlags .= ' -Wno-implicit-function-declaration -Wno-int-conversion -Wno-discarded-qualifiers';
    }

    // 分离 -L/-l/-Wl, 到 linkFlags：链接器单遍扫描，库必须在 .c 文件之后
    // （TCC/Unix 链接器对顺序敏感；-L/-l 放在源文件之前会导致 unresolved reference）
    // -Wl, 透传链接器选项（如 macOS -framework），同样需放在源文件之后
    $lateLinkFlags = '';
    $extraFlagTokens = preg_split('/\s+/', trim($extraFlags));
    $keptFlags = [];
    foreach ($extraFlagTokens as $tok) {
        if ($tok === '') continue;
        if (str_starts_with($tok, '-L') || str_starts_with($tok, '-l') || str_starts_with($tok, '-Wl,')) {
            $lateLinkFlags .= ' ' . $tok;
        } else {
            $keptFlags[] = $tok;
        }
    }
    $extraFlags = !empty($keptFlags) ? ' ' . implode(' ', $keptFlags) : '';

    if (!is_dir($outDir)) mkdir($outDir, 0777, true);

    // Phase 1.5: Type Check — 填充 AST 节点的 inferredType 字段
    //   使 CodeGenerator 能基于类型信息生成泛型数组等优化代码
    try {
        $checker = new TypeChecker(new SymbolTable());
        $checker->check($merged);
    } catch (\Throwable $e) {
        // TypeChecker 错误不阻塞编译（CodeGenerator 有回退逻辑）
        if ($debugMode) {
            fwrite(STDERR, "[WARN] TypeChecker: " . $e->getMessage() . "\n");
        }
    }

    // ── 代码生成阶段 ──
    //   --ssa 模式：FlatAst → SSA → 优化 → C
    //   默认模式：ProgramNode → CodeGenerator → C
    if ($ssaMode) {
        if ($debugMode) echo "[*] SSA mode enabled\n";

        // 1. Node AST → FlatAst
        $converter = new FlatAstConverter();
        $flatAst = $converter->convert($merged);

        // 2. FlatAst → SSAModule（遍历 ProgramNode 子节点，构建每个 FunctionNode 的 SSA）
        $ssaModule = new SSAModule();
        $programIdx = $flatAst->root;
        $childCount = $flatAst->childCount($programIdx);
        for ($i = 0; $i < $childCount; $i++) {
            $childIdx = $flatAst->child($programIdx, $i);
            if ($flatAst->nodes[$childIdx]['kind'] === NodeKind::FunctionNode) {
                $builder = new SSABuilder();
                $ssaFunc = $builder->build($flatAst, $childIdx);
                // 用 newFunction 创建占位项后替换为实际 SSAFunction
                // （SSABuilder.build 内部已设置 entryBlockId / values / blocks）
                $fid = $ssaModule->newFunction($ssaFunc->name, $ssaFunc->paramTypes, $ssaFunc->retType);
                $ssaModule->functions[$fid] = $ssaFunc;
            }
        }

        // 3. SSA 优化（对每个函数运行 fixpoint 优化）
        $optPass = new SSAOptPass();
        foreach ($ssaModule->functions as $ssaFunc) {
            $optPass->runUntilFixpoint($ssaFunc);
        }

        // 4. SSA → C 降低
        $toC = new SSAToCGenerator();
        $cCode = $toC->generate($ssaModule, $entryFile);

        if (!is_dir($outDir)) mkdir($outDir, 0777, true);
        $cFile = $outDir . DIRECTORY_SEPARATOR . pathinfo($entryFile, PATHINFO_FILENAME) . '.c';
        file_put_contents($cFile, $cCode);
    } else {
        $gen   = new CodeGenerator();
        $gen->isShared = $isShared;
        $gen->targetOS = $targetOS;
        $cFile = $gen->generate($merged, $entryFile, $outDir);
    }

    echo "       [YES] {$cFile}\n";

    // 将 .c 文件同步到 -o 输出名前缀（并行测试不同目录同名.php 不冲突）
    $altCFile = $outDir . DIRECTORY_SEPARATOR . pathinfo($outExe, PATHINFO_FILENAME) . '.c';
    if ($altCFile !== $cFile && is_file($cFile)) {
        @rename($cFile, $altCFile);
        $cFile = $altCFile;
    }

    // 保存编译缓存（.c + .json 元数据，命中时恢复编译上下文）
    if (!is_dir($cacheDir)) @mkdir($cacheDir, 0777, true);
    if (@copy($cFile, $cachedCFile)) {
        @file_put_contents($cachedMetaFile, json_encode([
            'tphpRoot'      => __DIR__,
            'entry'         => $entryFile,
            'outExe'        => $outExe,
            'extraFlags'    => $extraFlags,
            'lateLinkFlags' => $lateLinkFlags,
            'extraCFiles'   => array_values($extraCFiles),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

} catch (\Throwable $e) {
    fwrite(STDERR, "[NO] Transpile failed: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
    exit(1);
}

} // if (!$cacheHit)
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
            'android' => '-D__ANDROID__',
        ];
        if (isset($platformDefines[$targetOS])) {
            $extraFlags .= ' ' . $platformDefines[$targetOS];
        }
        // Android NDK 探测（在通用跨编译器探测之前，Android 使用专用 NDK 工具链）
        if ($targetOS === 'android' && $cc === null) {
            $ndkPath = getenv('ANDROID_NDK') ?: getenv('ANDROID_NDK_HOME') ?: getenv('ANDROID_NDK_ROOT');
            if (!$ndkPath || !is_dir($ndkPath)) {
                // 尝试从 ANDROID_HOME 推断
                $androidHome = getenv('ANDROID_HOME');
                if ($androidHome && is_dir($androidHome . '/ndk')) {
                    $ndkDirs = glob($androidHome . '/ndk/*', GLOB_ONLYDIR);
                    if (!empty($ndkDirs)) {
                        sort($ndkDirs);
                        $ndkPath = end($ndkDirs);
                    }
                }
            }
            if (!$ndkPath || !is_dir($ndkPath)) {
                $ndkExample = match(PHP_OS_FAMILY) {
                    'Windows' => "    Example: setx ANDROID_NDK \"C:\\Android\\ndk\\25.2.9519653\"\n",
                    default   => "    Example: export ANDROID_NDK=/opt/android-ndk\n",
                };
                die("[!] Android NDK not found.\n"
                  . "    Android NDK is required for cross-compiling to Android.\n"
                  . "    Install via Android Studio SDK Manager (SDK Tools → NDK Side by side)\n"
                  . "    or download from: https://developer.android.com/ndk/downloads\n"
                  . "    Then set ANDROID_NDK (or ANDROID_NDK_HOME / ANDROID_NDK_ROOT) env var.\n"
                  . $ndkExample);
            }
            $ndkPath = str_replace('\\', '/', $ndkPath);
            // 主机平台标识
            $hostTag = match(PHP_OS_FAMILY) {
                'Windows' => 'windows-x86_64',
                'Linux'   => 'linux-x86_64',
                'Darwin'  => 'darwin-x86_64',
                default   => 'windows-x86_64',
            };
            // API level
            $apiLevel = getenv('TPHP_ANDROID_API') ?: '24';
            // NDK clang 路径
            $ndkTriple = match($targetArch) {
                'aarch64' => 'aarch64-linux-android',
                'x86_64'  => 'x86_64-linux-android',
                'armv7a'  => 'armv7a-linux-androideabi',
                'i686'    => 'i686-linux-android',
                default   => 'aarch64-linux-android',
            };
            $ndkBinDir = "{$ndkPath}/toolchains/llvm/prebuilt/{$hostTag}/bin";
            $ndkClang = "{$ndkBinDir}/{$ndkTriple}{$apiLevel}-clang";
            // Windows 下用 .cmd 后缀
            if (PHP_OS_FAMILY === 'Windows') {
                $ndkClangCmd = $ndkClang . '.cmd';
                if (!file_exists($ndkClangCmd)) {
                    // 某些 NDK 版本用 .bat
                    $ndkClangCmd = $ndkClang . '.bat';
                }
                $ndkClang = file_exists($ndkClangCmd) ? $ndkClangCmd : $ndkClang;
            }
            if (!file_exists($ndkClang)) {
                die("[!] NDK clang not found: {$ndkClang}\n"
                  . "    Check ANDROID_NDK path and TPHP_ANDROID_API level.\n");
            }
            $cc = $ndkClang;
            $ccExe = $ndkClang;
            $ccClass = 'Clang';  // NDK clang
            // sysroot
            $sysroot = "{$ndkPath}/toolchains/llvm/prebuilt/{$hostTag}/sysroot";
            $extraFlags = '--sysroot=' . escapeshellarg($sysroot) . ' ' . $extraFlags;
            echo "[*] Using NDK: {$ndkPath} (API {$apiLevel})\n";
            echo "[*] NDK clang: {$ndkClang}\n";
            // Android 强制 -shared，产物为 .so（在后续编译命令中处理）
        }
        // Cross-compiler auto-detection
        // Priority: 1. clang -target (native cross-compile)  2. GCC triplet
        if ($cc === null) {
            $triplets = [
                'windows' . $targetArch => "{$targetArch}-windows-gnu",
                'linux'   . $targetArch => "{$targetArch}-linux-gnu",
                'darwin'  . $targetArch => "{$targetArch}-apple-darwin",
                'android' . $targetArch => match($targetArch) {
                    'aarch64' => 'aarch64-linux-android',
                    'x86_64'  => 'x86_64-linux-android',
                    'armv7a'  => 'armv7a-linux-androideabi',
                    'i686'    => 'i686-linux-android',
                    default   => 'aarch64-linux-android',
                },
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
            // clang -target 跨编译需要目标平台的 sysroot（glibc 头文件 + crt objects）。
            // Windows 上的 clang 默认只有 MSVC/MinGW 头文件，没有 Linux glibc。
            // 用户可通过 TPHP_SYSROOT 环境变量指定 sysroot 路径（如 WSL rootfs）。
            if ($found !== null && $targetOS !== $currentOS) {
                $sysroot = getenv('TPHP_SYSROOT');
                if ($sysroot && is_dir($sysroot)) {
                    $sysroot = str_replace('\\', '/', $sysroot);
                    $extraFlags = '--sysroot=' . escapeshellarg($sysroot) . ' ' . $extraFlags;
                    echo "[*] Using sysroot: {$sysroot}\n";
                } elseif ($targetOS === 'linux' && PHP_OS_FAMILY === 'Windows') {
                    die("[!] Cross-compile to Linux requires a Linux sysroot (glibc headers + crt).\n"
                      . "    clang -target cannot find glibc headers without --sysroot.\n\n"
                      . "    Option 1: Install WSL and set TPHP_SYSROOT\n"
                      . "      set TPHP_SYSROOT=\\\\wsl$\\Ubuntu\n"
                      . "    Option 2: Install a GCC cross-compiler and specify it\n"
                      . "      -cc x86_64-linux-gnu-gcc -os linux\n"
                      . "    Option 3: Compile natively on the target platform.\n");
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
    if ($targetOS === 'android') {
        // Android 产物强制为 libtphp.so（共享库，需 -shared）
        // NativeActivity 加载的 .so 必须以 lib 前缀开头
        // 所有 Android 产物统一放到 cwd/build/android/ 下，避免污染 ext/ui/android 源码模板：
        //   - libtphp.so → cwd/build/android/jniLibs/<abi>/libtphp.so
        //   - app-debug.apk → cwd/build/android/app-debug.apk（gradle 打包时通过 -PtphpApkOut 重定向）
        $abiMap = [
            'aarch64' => 'arm64-v8a',
            'x86_64'  => 'x86_64',
            'armv7a'  => 'armeabi-v7a',
            'i686'    => 'x86',
        ];
        $abiName = $abiMap[$targetArch] ?? 'arm64-v8a';
        // 查找 Android 工程模板目录（优先用项目根的 ext/ui/android，否则用 cwd 下 android/）
        $tphpRoot = dirname(__FILE__);
        $androidProj = is_dir($tphpRoot . DIRECTORY_SEPARATOR . 'ext' . DIRECTORY_SEPARATOR . 'ui' . DIRECTORY_SEPARATOR . 'android')
            ? $tphpRoot . DIRECTORY_SEPARATOR . 'ext' . DIRECTORY_SEPARATOR . 'ui' . DIRECTORY_SEPARATOR . 'android'
            : $cwd . DIRECTORY_SEPARATOR . 'android';
        // .so 输出到 cwd/build/android/jniLibs/<abi>/，gradle 通过 -PtphpJniLibs 读取
        $androidBuildDir = $cwd . DIRECTORY_SEPARATOR . 'build' . DIRECTORY_SEPARATOR . 'android';
        $jniLibsDir = $androidBuildDir . DIRECTORY_SEPARATOR . 'jniLibs' . DIRECTORY_SEPARATOR . $abiName;
        if (!is_dir($jniLibsDir)) mkdir($jniLibsDir, 0777, true);
        // 保存用户期望的输出基础名（遵循 -o 机制），用于 APK 命名
        // 必须在 outExe 被 .so 路径覆盖前提取
        $apkBaseName = pathinfo($outExe, PATHINFO_FILENAME);
        $outExe = $jniLibsDir . DIRECTORY_SEPARATOR . 'libtphp.so';
    } elseif ($isShared) {
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

$allCFiles = array_unique(array_merge($userCFiles, $extraCFiles));
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

// 统一 Response File 机制在所有参数收集完成后处理（见下方 sprintf 之前）
// zlib/zip: 检测生成的 C 代码是否使用了 zlib（CodeGenerator 条件引入 os/zlib.h）
// 策略：统一使用内置 zlib 源码（include/os/zlib_src/）静态编译，无需外部 -lz 或 zlib1.dll。
// 这确保所有平台/编译器组合（包括纯 TCC 环境）都能使用 zlib/zip 扩展，零运行时依赖。
// 检测方式：匹配 #include "os/zlib.h"（自动检测，相对路径）或 #include ".../os/zlib.h"（显式 #include __INC__，绝对路径）
$zlibSrcDir = $includeDir . DIRECTORY_SEPARATOR . 'os' . DIRECTORY_SEPARATOR . 'zlib_src';
if (is_file($cFile) && preg_match('/#include\s+"[^"]*os\/zlib\.h"/', file_get_contents($cFile))
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
// stream: ws2_32 链接由 ext/stream/src/stream.php 的 #flag windows -lws2_32 声明
// （#import stream 引入 stream.php → #flag 被收集到 lateLinkFlags）
// openssl（基于内置 mbedTLS 源码静态编译）：
//   检测生成的 C 代码是否使用了 openssl 扩展（CodeGenerator 条件引入 ext/openssl/src/openssl.h）
//   策略：统一使用内置 mbedTLS 3.6.6 源码（include/mbedtls_src/）静态编译，
//   无需外部 -lssl/-lcrypto 或系统 OpenSSL，零运行时依赖。
//   这确保所有平台/编译器组合（包括纯 TCC 环境）都能使用 openssl 扩展。
//
//   预编译策略：mbedtls 源码先编译为静态库 libmbedtls.a，再与主程序链接。
//   原因：TCC 一次编译过多 .c 文件时内部符号表溢出，导致 static inline 函数
//   声明丢失（tphp_fn_echo 等变为隐式声明）。预编译分离解决了此问题。
$mbedtlsSrcDir = $includeDir . DIRECTORY_SEPARATOR . 'mbedtls_src';
// 检测生成的 C 代码是否 include 了 openssl.h（由 #import openssl 引入）
// 匹配 "openssl/src/openssl.h" 子串（兼容绝对路径和相对路径两种生成形式）
if (is_file($cFile) && strpos(file_get_contents($cFile), 'openssl/src/openssl.h') !== false
    && is_dir($mbedtlsSrcDir)) {
    $mbedtlsLibDir = $mbedtlsSrcDir . DIRECTORY_SEPARATOR . 'library';
    // 核心库（仅包含 mbedtls_config.h 启用的模块）
    $mbedtlsSrcFiles = [
        'platform.c', 'platform_util.c', 'constant_time.c', 'error.c',
        'memory_buffer_alloc.c', 'version.c',
        'md.c', 'md5.c', 'sha1.c', 'sha256.c', 'sha512.c',
        'aes.c', 'aesni.c', 'cipher.c', 'cipher_wrap.c',
        'gcm.c', 'ccm.c', 'chacha20.c', 'chachapoly.c', 'poly1305.c',
        'cmac.c', 'block_cipher.c',
        'entropy.c', 'entropy_poll.c', 'ctr_drbg.c', 'hmac_drbg.c',
        'bignum.c', 'bignum_core.c', 'bignum_mod.c', 'bignum_mod_raw.c',
        'rsa.c', 'rsa_alt_helpers.c', 'pk.c', 'pk_wrap.c', 'pk_ecc.c',
        'pkparse.c', 'ecp.c', 'ecp_curves.c', 'ecp_curves_new.c',
        'ecdh.c', 'ecdsa.c', 'dhm.c',
        'asn1parse.c', 'asn1write.c', 'oid.c', 'pem.c', 'base64.c',
        'x509.c', 'x509_create.c', 'x509_crl.c', 'x509_crt.c', 'x509_csr.c',
        'ssl_ciphersuites.c', 'ssl_client.c', 'ssl_msg.c', 'ssl_ticket.c',
        'ssl_tls.c', 'ssl_tls12_client.c', 'ssl_tls12_server.c',
        'hkdf.c', 'pkcs5.c', 'pkcs7.c', 'pkcs12.c',
        'net_sockets.c', 'threading.c', 'timing.c',
    ];

    // 预编译为静态库 libmbedtls.a（带缓存）
    $mbedtlsCacheDir = $cwd . DIRECTORY_SEPARATOR . 'build' . DIRECTORY_SEPARATOR . 'mbedtls_cache';
    $mbedtlsLibPath = $mbedtlsCacheDir . DIRECTORY_SEPARATOR . 'libmbedtls.a';
    $mbedtlsConfigFile = $mbedtlsSrcDir . DIRECTORY_SEPARATOR . 'include' . DIRECTORY_SEPARATOR . 'mbedtls' . DIRECTORY_SEPARATOR . 'mbedtls_config.h';

    // 检查是否需要重建（库不存在、或任一源文件/config 比库新）
    $needRebuild = !file_exists($mbedtlsLibPath);
    if (!$needRebuild) {
        $libMtime = filemtime($mbedtlsLibPath);
        if (file_exists($mbedtlsConfigFile) && filemtime($mbedtlsConfigFile) > $libMtime) {
            $needRebuild = true;
        }
        if (!$needRebuild) {
            foreach ($mbedtlsSrcFiles as $src) {
                $srcPath = $mbedtlsLibDir . DIRECTORY_SEPARATOR . $src;
                if (is_file($srcPath) && filemtime($srcPath) > $libMtime) {
                    $needRebuild = true;
                    break;
                }
            }
        }
    }

    if ($needRebuild) {
        if (!is_dir($mbedtlsCacheDir)) @mkdir($mbedtlsCacheDir, 0755, true);
        // 构建 mbedtls -I 路径（与 openssl.php 的 #flag 一致）
        $mbedtlsIncludeFlags = sprintf(
            '-I"%s" -I"%s" -I"%s" -I"%s" -I"%s"',
            str_replace('\\', '/', $mbedtlsSrcDir . '/include'),
            str_replace('\\', '/', $mbedtlsSrcDir . '/library'),
            str_replace('\\', '/', $mbedtlsSrcDir . '/3rdparty/everest/include'),
            str_replace('\\', '/', $mbedtlsSrcDir . '/3rdparty/everest/include/everest'),
            str_replace('\\', '/', $mbedtlsSrcDir . '/3rdparty/everest/include/everest/kremlib')
        );
        // 收集存在的源文件路径
        $mbedtlsSrcPaths = [];
        foreach ($mbedtlsSrcFiles as $src) {
            $srcPath = $mbedtlsLibDir . DIRECTORY_SEPARATOR . $src;
            if (is_file($srcPath)) $mbedtlsSrcPaths[] = $srcPath;
        }
        // 编译所有 .c 为 .o（逐文件编译）
        //   原因：TCC 的 `-c` 模式不支持 `-o` 同时编译多个文件
        //         （报错 "cannot specify output file with -c many files"），
        //         必须逐文件调用 `tcc -c -I... file.c -o file.o`。
        $objDir = $mbedtlsCacheDir . DIRECTORY_SEPARATOR . 'obj';
        if (!is_dir($objDir)) @mkdir($objDir, 0755, true);
        $objFiles = [];
        foreach ($mbedtlsSrcPaths as $srcPath) {
            $base = basename($srcPath, '.c');
            $objFiles[] = $objDir . DIRECTORY_SEPARATOR . $base . '.o';
        }
        echo "       Pre-compiling mbedTLS (static library, " . count($mbedtlsSrcPaths) . " files)...\n";
        $compileOk = true;
        $savedCwd2 = getcwd();
        $execCwd2 = $savedCwd2;
        if ($isTCC) {
            $binDir2 = dirname($ccExe);
            if (is_dir($binDir2)) $execCwd2 = $binDir2;
        }
        foreach ($mbedtlsSrcPaths as $idx => $srcPath) {
            $objPath = $objFiles[$idx];
            $singleCmd = sprintf(
                '"%s" %s -c %s "%s" -o "%s" 2>&1',
                $ccExe, $bFlag, $mbedtlsIncludeFlags,
                str_replace('\\', '/', $srcPath),
                str_replace('\\', '/', $objPath)
            );
            $singleOutput = [];
            $singleRet = 0;
            if ($execCwd2 !== false && @chdir($execCwd2)) {
                exec($singleCmd, $singleOutput, $singleRet);
                @chdir($savedCwd2);
            } else {
                exec($singleCmd, $singleOutput, $singleRet);
            }
            if ($singleRet !== 0) {
                echo "       [ERROR] mbedTLS compile failed: " . basename($srcPath) . "\n"
                   . implode("\n", $singleOutput) . "\n";
                $compileOk = false;
                break;
            }
        }
        if ($compileOk) {
            // 创建静态库 libmbedtls.a
            if ($isTCC) {
                // TCC: use built-in -ar option
                $arCmd = sprintf(
                    '"%s" -ar cr "%s" %s 2>&1',
                    $ccExe,
                    str_replace('\\', '/', $mbedtlsLibPath),
                    implode(' ', array_map(fn($f) => '"' . str_replace('\\', '/', $f) . '"', $objFiles))
                );
            } else {
                // gcc/clang: use system ar (TCC's -ar option is not supported by gcc/clang)
                $arExe = 'ar';
                $ccBinDir = dirname($ccExe);
                if ($ccBinDir !== '' && $ccBinDir !== '.') {
                    $arCandidate = $ccBinDir . DIRECTORY_SEPARATOR . (PHP_OS_FAMILY === 'Windows' ? 'ar.exe' : 'ar');
                    if (is_file($arCandidate)) {
                        $arExe = $arCandidate;
                    }
                }
                $arCmd = sprintf(
                    '"%s" cr "%s" %s 2>&1',
                    str_replace('\\', '/', $arExe),
                    str_replace('\\', '/', $mbedtlsLibPath),
                    implode(' ', array_map(fn($f) => '"' . str_replace('\\', '/', $f) . '"', $objFiles))
                );
            }
            $arOutput = [];
            $arRet = 0;
            $savedCwd3 = getcwd();
            $execCwd3 = $savedCwd3;
            if ($isTCC) {
                $binDir3 = dirname($ccExe);
                if (is_dir($binDir3)) $execCwd3 = $binDir3;
            }
            if ($execCwd3 !== false && @chdir($execCwd3)) {
                exec($arCmd, $arOutput, $arRet);
                @chdir($savedCwd3);
            } else {
                exec($arCmd, $arOutput, $arRet);
            }
            if ($arRet !== 0) {
                echo "       [ERROR] mbedTLS archive failed:\n" . implode("\n", $arOutput) . "\n";
            } else {
                echo "       [OK] libmbedtls.a built (" . count($objFiles) . " objects)\n";
            }
        }
    } else {
        echo "       [CACHED] libmbedtls.a\n";
    }

    // 将 libmbedtls.a 加入链接（不加入 $allCFiles，避免 TCC 符号表溢出）
    if (file_exists($mbedtlsLibPath)) {
        $lateLinkFlags .= ' "' . str_replace('\\', '/', $mbedtlsLibPath) . '"';
    } else {
        // 预编译失败：直接报错退出。
        //   不回退到直接编译 .c 源码（会触发 TCC 符号表溢出，导致 static inline
        //   函数声明丢失），也不覆盖 $extraSrcs（会丢失用户 #flag .c 文件）。
        echo "[ERROR] mbedTLS pre-compile failed — libmbedtls.a not built.\n";
        echo "       Fix the compile errors above and retry.\n";
        exit(1);
    }
    // Windows: mbedtls net_sockets.c 需要 winsock
    if (PHP_OS_FAMILY === 'Windows') {
        $lateLinkFlags .= ' -lws2_32';
    }
}
// openssl 链接 flags 由 ext/openssl/src/openssl.php 通过 #flag 声明（-I 路径）
// 源码已由 tphp.php 自动收集到 $allCFiles，无需额外 -lssl -lcrypto
// macOS + clang/gcc: sokol_app.h #import <AppKit/AppKit.h> 引入 ObjC 系统框架,
//   纯 C 模式下不识别 @class/@protocol 语法,需用 -x objective-c 切换编译模式。
//   types.h 的 #define null 宏冲突已在 ui.h 中用 #undef null 解决。
//   TCC 不支持 -x objective-c,已有 @skip:darwin+tcc 跳过。
//   检测:extraFlags 含 ext/ui/sokol 路径（UI 扩展通过 #flag 声明 -I 路径）。
if (PHP_OS_FAMILY === 'Darwin' && !$isTCC
    && strpos($extraFlags, 'ui/sokol') !== false) {
    $extraFlags .= ' -x objective-c';
}
// -shared 模式：生成动态库（Android 产物强制为 .so 共享库，也需 -shared）
$sharedFlag = ($isShared || $targetOS === 'android') ? ' -shared' : '';
// 共享库在非 Windows 目标上需要 -fPIC（位置无关代码）
// Windows DLL 不需要 PIC；Linux/macOS/Android 的 .so 必须用 PIC
if ($sharedFlag !== '') {
    $isWindowsTarget = ($targetOS === 'windows') || ($targetOS === null && PHP_OS_FAMILY === 'Windows');
    if (!$isWindowsTarget) {
        $extraFlags .= ' -fPIC';
    }
}
// 项目根目录作为额外 -I 路径，让 ext/ 下的扩展头文件（如 ext/stream/src/stream.h）可被 #include 查找到
$projectRoot = dirname($includeDir);
// 注意 -I 顺序：TinyPHP 的 include/ 必须在 $extraFlags（含 mbedtls 的 -I 路径）之前，
//   否则 mbedtls 的 library/common.h 会顶替 TinyPHP 的 include/common.h，
//   导致 tphp_fn_echo 等 builtin 函数声明丢失（implicit declaration）。

// === 统一 Response File 机制 ===
// 当总命令行长度超过阈值时，把可变参数（-I/-D/-O/源文件/-L/-l/.a）全部写入 @file。
// 保留核心参数在命令行：编译器路径、-B、内置 -I、-o（保持可读性）。
// TCC/GCC/Clang 均支持 @file 语法，参数顺序自由排列。
// 阈值 8000：Windows CreateProcess 上限 32767，保守留余量。
//   Linux/macOS 命令行限制很高（ARG_MAX 通常 128KB+），不触发。
$respThreshold = 8000;
$fullCmd = sprintf(
    '"%s" %s -I"%s" -I"%s" %s%s -o "%s" "%s"%s%s%s 2>&1',
    $ccExe, $bFlag, $includeDir, $projectRoot, $extraFlags, $sharedFlag, $outExe, $cFile, $extraSrcs, $linkFlags, $lateLinkFlags
);
$responseFile = '';
if (PHP_OS_FAMILY === 'Windows' && strlen($fullCmd) > $respThreshold) {
    $respDir = $outDir;
    if (!is_dir($respDir)) @mkdir($respDir, 0777, true);
    $responseFile = $respDir . DIRECTORY_SEPARATOR . pathinfo($entryFile, PATHINFO_FILENAME) . '_rsp.txt';
    // 收集可变参数，保持顺序：flags → 主 C 文件 → 附加源文件 → 链接库
    // 链接库必须在源文件之后（链接器单遍扫描）
    $respLines = [];
    if ($extraFlags !== '')  $respLines[] = trim($extraFlags);
    if ($sharedFlag !== '')  $respLines[] = trim($sharedFlag);
    $respLines[] = '"' . str_replace('/', DIRECTORY_SEPARATOR, $cFile) . '"';
    foreach ($allCFiles as $cf) {
        $respLines[] = '"' . str_replace('/', DIRECTORY_SEPARATOR, $cf) . '"';
    }
    if ($linkFlags !== '')   $respLines[] = trim($linkFlags);
    if ($lateLinkFlags !== '') $respLines[] = trim($lateLinkFlags);
    file_put_contents($responseFile, implode("\n", $respLines));
    $cmd = sprintf(
        '"%s" %s -I"%s" -I"%s" -o "%s" @"%s" 2>&1',
        $ccExe, $bFlag, $includeDir, $projectRoot, $outExe, $responseFile
    );
    if ($debugMode) echo "[DEBUG] Using response file (cmdlen=" . strlen($fullCmd) . " > {$respThreshold}): {$responseFile}\n";
} else {
    $cmd = $fullCmd;
}

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
                // 构建新的源文件列表（原 allCFiles + .obj 文件）
                $allFallbackSrcs = array_merge($allCFiles, $allObjs);
                $newExtraSrcs = !empty($allFallbackSrcs) ? ' "' . implode('" "', $allFallbackSrcs) . '"' : '';
                // 统一 Response File 机制（与主路径一致）
                $cmd2Full = sprintf(
                    '"%s" %s -I"%s" -I"%s" %s%s -o "%s" "%s"%s%s%s 2>&1',
                    $ccExe, $bFlag, $includeDir, $projectRoot, $extraFlags, $sharedFlag, $outExe, $cFile, $newExtraSrcs, $linkFlags, $newLateLink
                );
                $fallbackRespFile = '';
                if (PHP_OS_FAMILY === 'Windows' && strlen($cmd2Full) > $respThreshold) {
                    $fallbackRespFile = $outDir . DIRECTORY_SEPARATOR . pathinfo($entryFile, PATHINFO_FILENAME) . '_rsp2.txt';
                    $respLines = [];
                    if ($extraFlags !== '')  $respLines[] = trim($extraFlags);
                    if ($sharedFlag !== '')  $respLines[] = trim($sharedFlag);
                    $respLines[] = '"' . str_replace('/', DIRECTORY_SEPARATOR, $cFile) . '"';
                    foreach ($allFallbackSrcs as $cf) {
                        $respLines[] = '"' . str_replace('/', DIRECTORY_SEPARATOR, $cf) . '"';
                    }
                    if ($linkFlags !== '')   $respLines[] = trim($linkFlags);
                    if ($newLateLink !== '') $respLines[] = trim($newLateLink);
                    file_put_contents($fallbackRespFile, implode("\n", $respLines));
                    $cmd2 = sprintf(
                        '"%s" %s -I"%s" -I"%s" -o "%s" @"%s" 2>&1',
                        $ccExe, $bFlag, $includeDir, $projectRoot, $outExe, $fallbackRespFile
                    );
                    if ($debugMode) echo "[DEBUG] TCC .a fallback, using response file (cmdlen=" . strlen($cmd2Full) . " > {$respThreshold})\n";
                } else {
                    $cmd2 = $cmd2Full;
                }
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

// Android: 编译成功后自动调用 gradle 打包 APK
if ($targetOS === 'android') {
    // ── 多 ABI 编译（用户未显式指定 -arch 时，编译所有 4 个 ABI）──
    // 模拟器多为 x86_64，真机多为 arm64。只编译 aarch64 会导致 x86_64 模拟器
    // 无匹配 .so 而 NativeActivity 加载失败秒退。默认编译全部 ABI 覆盖所有设备。
    // 用户显式 -arch xxx 时只编译指定单一 ABI（减小包体或特定设备）。
    if (!$archExplicit) {
        $allAndroidAbis = ['aarch64', 'x86_64', 'armv7a', 'i686'];
        $extraAbis = array_diff($allAndroidAbis, [$targetArch]);
        if (!empty($extraAbis)) {
            echo "[*] Multi-ABI build: compiling " . implode(', ', $allAndroidAbis) . " (default; use -arch <abi> for single)\n";
            // 递归调用自身编译额外 ABI，复用已转译的 .c 文件
            // 子进程会重新转译（幂等，覆盖同名 .c），但编译产物输出到对应 jniLibs/<abi>/
            $selfScript = __FILE__;
            $phpExe = PHP_BINARY;
            foreach ($extraAbis as $extraAbi) {
                // 构建子进程命令：原参数 + -arch <abi> + --no-android-apk（子进程只编译 .so）
                // 跳过 $argv[0]（脚本自身路径，已用 $selfScript 绝对路径替代）
                $subArgs = [$phpExe, $selfScript];
                $skipNextArch = false;
                foreach (array_slice($argv, 1) as $a) {
                    if ($a === $phpExe || $a === $selfScript) continue;
                    // 跳过已有的 -arch 参数及其值（避免覆盖新的 -arch）
                    if ($skipNextArch) { $skipNextArch = false; continue; }
                    if ($a === '-arch') { $skipNextArch = true; continue; }
                    $subArgs[] = $a;
                }
                $subArgs[] = '-arch';
                $subArgs[] = $extraAbi;
                $subArgs[] = '--no-android-apk';
                $subCmd = escapeshellarg($phpExe);
                foreach (array_slice($subArgs, 1) as $a) $subCmd .= ' ' . escapeshellarg($a);
                echo "       [ABI {$extraAbi}] compiling...\n";
                $subOutput = [];
                $subRet = 0;
                exec($subCmd . ' 2>&1', $subOutput, $subRet);
                // 输出子进程最后几行（关键信息），完整输出太长
                $tail = array_slice($subOutput, -5);
                foreach ($tail as $line) echo "       " . $line . "\n";
                if ($subRet !== 0) {
                    echo "[WARN] ABI {$extraAbi} compile failed (skipped). Single-ABI APK may not run on mismatched devices.\n";
                }
            }
        }
    }
    // 子进程（--no-android-apk）只编译 .so，跳过 Gradle 打包（主进程统一打包）
    if ($skipAndroidApk) {
        echo "       [YES] {$outExe} (skipped APK, --no-android-apk)\n";
    } else {
    echo "[3/3] Building APK via Gradle...\n";
    $gradleDir = $androidProj;
    // .so 在 cwd/build/android/jniLibs/<abi>/（gradle 通过 -PtphpJniLibs 读取）
    // APK 输出到 cwd（与其他二进制产物一致），命名遵循 -o 机制，debug 加 -debug 后缀
    $jniLibsRoot = $androidBuildDir . DIRECTORY_SEPARATOR . 'jniLibs';  // cwd/build/android/jniLibs
    if (!is_dir($jniLibsRoot)) mkdir($jniLibsRoot, 0777, true);
    // 通过 -PtphpJniLibs 让 build.gradle 从 cwd/build/android/jniLibs 读取 libtphp.so
    // （不污染 ext/ui/android 模板）
    // APK 输出由 Gradle 默认路径生成，构建后由 tphp.php 复制到 cwd/
    // （在 Gradle 中重定向 outputDirectory 会触发 AGP ListingFileRedirectTask bug）
    $propArg = '-PtphpJniLibs=' . escapeshellarg($jniLibsRoot);

    if (!is_dir($gradleDir)) {
        echo "[WARN] Android project template not found, skip APK packaging.\n";
        echo "       .so is ready at: {$outExe}\n";
    } else {
        // ── 0. 检测 Java 版本，不兼容时自动搜索并切换到 Java 17/21 LTS ──
        // Gradle 8.9 + AGP 8.7.0 官方支持 Java 8~23，Java 24+ 会导致 AGP 内部
        // XML 解析器（用于扫描 SDK platforms）静默失败，报 "Failed to find target"。
        $javaHome = getenv('JAVA_HOME');
        $javaExe = $javaHome ? $javaHome . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'java' : 'java';
        if ($javaHome && PHP_OS_FAMILY === 'Windows') $javaExe .= '.exe';
        $javaMajor = 0;
        if (file_exists($javaExe) || $javaExe === 'java') {
            exec('"' . $javaExe . '" -version 2>&1', $jOut, $jRet);
            $jLine = implode("\n", $jOut);
            if (preg_match('/version "(\d+)(?:\.(\d+))?(?:\.\d+)?"/', $jLine, $jm)) {
                $javaMajor = (int)$jm[1] === 1 ? (int)($jm[2] ?? 0) : (int)$jm[1];
            }
        }
        // Java 24+ 或未知版本 → 搜索兼容 JDK（17 或 21 LTS）
        if ($javaMajor > 23 || $javaMajor < 8) {
            if ($javaMajor > 0) {
                echo "       [!] Java {$javaMajor} incompatible with Gradle 8.9 (supports 8~23).\n";
            }
            // 搜索 JDK 21 和 17（优先 21 LTS）
            $jdkDirs = [];
            if (PHP_OS_FAMILY === 'Windows') {
                foreach (['C:\\env', 'C:\\Program Files\\Java', 'C:\\Program Files\\Eclipse Adoptium'] as $base) {
                    if (is_dir($base)) {
                        foreach (glob($base . '\\jdk-21*', GLOB_ONLYDIR) ?: [] as $d) $jdkDirs[] = $d;
                        foreach (glob($base . '\\jdk-17*', GLOB_ONLYDIR) ?: [] as $d) $jdkDirs[] = $d;
                    }
                }
            } elseif (PHP_OS_FAMILY === 'Darwin') {
                foreach (glob('/Library/Java/JavaVirtualMachines/*/Contents/Home', GLOB_ONLYDIR) ?: [] as $d) {
                    if (str_contains($d, '-21.') || str_contains($d, '-17.')) $jdkDirs[] = $d;
                }
            } else {
                foreach (glob('/usr/lib/jvm/java-2{1,7}*', GLOB_ONLYDIR | GLOB_BRACE) ?: [] as $d) $jdkDirs[] = $d;
            }
            if (!empty($jdkDirs)) {
                // 优先选 21，其次 17
                usort($jdkDirs, fn($a, $b) => (str_contains($b, '21') ? 1 : 0) - (str_contains($a, '21') ? 1 : 0));
                $javaHome = $jdkDirs[0];
                putenv('JAVA_HOME=' . $javaHome);
                echo "       [YES] Switched to Java: {$javaHome}\n";
            } else {
                echo "           No compatible JDK (17/21) found. Install Java 21 LTS.\n";
                if (PHP_OS_FAMILY === 'Windows') {
                    echo "           Example: setx JAVA_HOME \"C:\\env\\jdk-21\"\n";
                } else {
                    echo "           Example: export JAVA_HOME=/usr/lib/jvm/java-21\n";
                }
            }
        }
        // ── 1. 检测 Android SDK，生成 local.properties ──
        $sdkRoot = getenv('ANDROID_HOME') ?: getenv('ANDROID_SDK_ROOT');
        if (!$sdkRoot) {
            $defaultSdk = match(PHP_OS_FAMILY) {
                'Windows' => getenv('LOCALAPPDATA') . '\\Android\\Sdk',
                'Darwin'  => getenv('HOME') . '/Library/Android/sdk',
                default   => getenv('HOME') . '/Android/Sdk',
            };
            if (is_dir($defaultSdk)) $sdkRoot = $defaultSdk;
        }
        if ($sdkRoot && is_dir($sdkRoot)) {
            $localProps = $gradleDir . DIRECTORY_SEPARATOR . 'local.properties';
            $sdkPath = str_replace('\\', '/', $sdkRoot);
            file_put_contents($localProps, "sdk.dir={$sdkPath}\n");
            echo "       [YES] SDK: {$sdkRoot}\n";
            // 自动接受所有 SDK 许可（避免 Gradle 构建时报 "licenses not accepted"）
            // Android SDK 许可哈希是 Google 公开的固定值，写入 licenses 目录即表示接受
            // 参考：https://developer.android.com/studio/intro/update.html#download-with-gradle
            $licensesDir = $sdkRoot . DIRECTORY_SEPARATOR . 'licenses';
            if (!is_dir($licensesDir)) mkdir($licensesDir, 0777, true);
            // Google 公开的许可哈希（写入这些文件等于接受所有 SDK 许可）
            // 注意：每个哈希前必须有 \n，末尾也必须有 \n，否则 Gradle 解析失败
            // 参考：https://developer.android.com/studio/intro/update.html#download-with-gradle
            //   - 8933...  API < 28 旧许可
            //   - 2433...  API 28~33 许可
            //   - d56f...  API 34+ 新增许可（AGP 8.x 内部依赖 android-34 / build-tools 34.0.0）
            $licenseHashes = [
                'android-sdk-license'          => "\n8933bad161af4178b1185d1a37fbf41ea5269c55\n24333f8a63b6825ea9c5514f83c2829b004d1fee\nd56f5187479451eabf01fb78af698cb\n",
                'android-sdk-preview-license'  => "\n84831b9409646a918e30573bab4c9c91346d8abd\n",
                'android-sdk-arm-dbt-license'  => "\n859f317696f67ef3d7f30a50a5560e7834e282e0\n",
                'android-googletv-license'     => "\n601085b94cd77f0b54ff86406907032723ea9580\n",
                'mips-android-sysimage-license' => "\ne9acab5b5fbb560a72cfaecf8947a6ab\n",
                'google-gdk-license'           => "\n33b6a2b6a071c3892a5825047dd9a31d\n",
                'intel-android-extra-license'  => "\nd975f751698a77b662f1254ddbeed3901e7634aa\n",
            ];
            $needWrite = false;
            foreach ($licenseHashes as $name => $hash) {
                $licFile = $licensesDir . DIRECTORY_SEPARATOR . $name;
                // 若文件不存在或内容不包含所有需要的哈希，则重写
                // （旧版本可能缺少 d56f... 哈希，需要补写）
                if (!file_exists($licFile) || strpos((string)file_get_contents($licFile), 'd56f5187479451eabf01fb78af698cb') === false && $name === 'android-sdk-license') {
                    file_put_contents($licFile, $hash);
                    $needWrite = true;
                }
            }
            // 清理 Gradle 下载中断残留的 -N 后缀目录（如 android-35-3 → android-35）
            // 问题：Gradle 下载时若目标目录已存在（不完整），会装到 android-35-N，
            //   但 -N 目录的 package.xml 中 path 仍是 "platforms;android-35"，
            //   导致多个目录声称是同一个包，AGP 扫描时冲突并拒绝识别 android-35。
            //   另一类残留：目录只有 .installer/.installData（无 package.xml），
            //   是 Gradle 下载中断的空壳，AGP 扫描到会污染 SDK 仓库状态。
            foreach (['platforms', 'build-tools'] as $subDir) {
                $base = $sdkRoot . DIRECTORY_SEPARATOR . $subDir;
                if (!is_dir($base)) continue;
                $items = glob($base . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR);
                if ($items === false) continue;
                // 完整性判断：platforms 需 android.jar + source.properties；build-tools 需 aapt2 + source.properties
                $isComplete = function (string $dir) use ($subDir): bool {
                    if (!file_exists($dir . DIRECTORY_SEPARATOR . 'source.properties')) return false;
                    if ($subDir === 'platforms') {
                        return file_exists($dir . DIRECTORY_SEPARATOR . 'android.jar');
                    }
                    return file_exists($dir . DIRECTORY_SEPARATOR . 'aapt2.exe')
                        || file_exists($dir . DIRECTORY_SEPARATOR . 'aapt2');
                };
                foreach ($items as $item) {
                    $name = basename($item);
                    $pkgXml = $item . DIRECTORY_SEPARATOR . 'package.xml';
                    if (!file_exists($pkgXml)) {
                        // 无 package.xml 但有 .installer/ → 下载中断的空壳目录，直接删除
                        if (is_dir($item . DIRECTORY_SEPARATOR . '.installer')) {
                            if (deleteDirectory($item)) {
                                echo "       [YES] Deleted incomplete {$subDir}/{$name} (no package.xml)\n";
                            }
                        }
                        continue;
                    }
                    $content = (string)file_get_contents($pkgXml);
                    // 解析 path="platforms;android-35" 或 path="build-tools;35.0.0"
                    if (!preg_match('/path="[^;]+;([^"]+)"/', $content, $pm)) continue;
                    $expectedName = $pm[1];
                    if ($expectedName === $name) continue;  // 目录名与 path 一致，正常目录
                    // 目录名与 path 不一致 → 是 -N 重复目录
                    $targetPath = $base . DIRECTORY_SEPARATOR . $expectedName;
                    if (is_dir($targetPath) && $isComplete($targetPath)) {
                        // 目标完整 → 删除 -N 重复目录（消除 path 冲突）
                        if (deleteDirectory($item)) {
                            echo "       [YES] Deleted duplicate {$name} ({$expectedName} is complete)\n";
                        }
                    } elseif ($isComplete($item)) {
                        // 目标不存在/不完整，-N 完整 → 用 -N 替换目标
                        if (is_dir($targetPath)) deleteDirectory($targetPath);
                        if (@rename($item, $targetPath)) {
                            echo "       [YES] Replaced {$expectedName} with {$name}\n";
                        }
                    }
                }
            }
            // 清理 SDK 根下的 .temp 目录（Gradle 下载临时目录，中断后残留）
            $sdkTemp = $sdkRoot . DIRECTORY_SEPARATOR . '.temp';
            if (is_dir($sdkTemp)) {
                if (deleteDirectory($sdkTemp)) {
                    echo "       [YES] Cleaned .temp directory\n";
                }
            }
        } else {
            $sdkExample = match(PHP_OS_FAMILY) {
                'Windows' => "           setx ANDROID_HOME \"%LOCALAPPDATA%\\Android\\Sdk\"\n",
                'Darwin'  => "           export ANDROID_HOME=~/Library/Android/sdk\n",
                default   => "           export ANDROID_HOME=~/Android/Sdk\n",
            };
            echo "       [!] Android SDK not found. APK packaging skipped.\n";
            echo "           Android SDK is required for Gradle to build APK.\n";
            echo "           Install via Android Studio SDK Manager, then set ANDROID_HOME env var.\n";
            echo $sdkExample;
            echo "           .so is ready at: {$outExe}\n";
            $sdkRoot = false;
        }

        // ── 2. 执行 gradle 打包 ──
        if (!empty($sdkRoot)) {
            // 清理项目下的 .gradle/ 配置缓存（不影响 ~/.gradle/caches/ 中的依赖缓存）
            // AGP 会缓存 SDK 状态到此处，若 SDK 曾有不完整/重复目录，缓存会导致
            // "Failed to find target" 持续报错，即使 SDK 已修复
            $configCache = $gradleDir . DIRECTORY_SEPARATOR . '.gradle';
            if (is_dir($configCache)) {
                deleteDirectory($configCache);
            }

            // SDK 根的 source.properties（platform-tools 元数据）会干扰 AGP 的
            // LegacyLocalRepoLoader，导致 SDK 扫描器将根目录当作一个 package，
            // 无法发现 platforms/ 下的平台包，报 "Failed to find target"。
            // 临时重命名，Gradle 构建结束后恢复。
            $rootProps = $sdkRoot . DIRECTORY_SEPARATOR . 'source.properties';
            $rootPropsBak = $rootProps . '.tphp_bak';
            $rootPropsRenamed = false;
            if (file_exists($rootProps) && !file_exists($rootPropsBak)) {
                @rename($rootProps, $rootPropsBak);
                $rootPropsRenamed = true;
            }

            $gradlew = PHP_OS_FAMILY === 'Windows' ? 'gradlew.bat' : './gradlew';
            $useWrapper = file_exists($gradleDir . DIRECTORY_SEPARATOR . $gradlew);
            $apkCmd = '';
            if ($useWrapper) {
                // --no-daemon: 避免 Daemon 缓存旧的 SDK 状态（首次构建时 SDK 可能刚下载完）
                $apkCmd = escapeshellarg($gradlew) . ' assembleDebug --no-daemon ' . $propArg;
            } else {
                exec('gradle --version 2>&1', $gOut, $gRet);
                if ($gRet === 0) {
                    $apkCmd = 'gradle assembleDebug --no-daemon ' . $propArg;
                }
            }
            if ($apkCmd === '') {
                echo "       [WARN] No gradle found. .so is at: {$outExe}\n";
                echo "       Install gradle or run: cd \"{$gradleDir}\" && gradlew assembleDebug {$propArg}\n";
                // 恢复 source.properties
                if ($rootPropsRenamed && file_exists($rootPropsBak)) {
                    @rename($rootPropsBak, $rootProps);
                }
            } else {
                if (PHP_OS_FAMILY === 'Windows') $apkCmd = 'cd /d ' . escapeshellarg($gradleDir) . ' && ' . $apkCmd;
                else $apkCmd = 'cd ' . escapeshellarg($gradleDir) . ' && ' . $apkCmd;
                system($apkCmd, $apkRet);
                // 恢复 source.properties（无论构建成功与否）
                if ($rootPropsRenamed && file_exists($rootPropsBak)) {
                    @rename($rootPropsBak, $rootProps);
                }
                if ($apkRet === 0) {
                    // Gradle 默认输出到 <gradleDir>/app/build/outputs/apk/debug/app-debug.apk
                    $defaultApk = $gradleDir . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'build'
                        . DIRECTORY_SEPARATOR . 'outputs' . DIRECTORY_SEPARATOR . 'apk' . DIRECTORY_SEPARATOR
                        . 'debug' . DIRECTORY_SEPARATOR . 'app-debug.apk';
                    // APK 放到 cwd，命名遵循 -o 机制：debug 加 -debug 后缀
                    $targetApk = $cwd . DIRECTORY_SEPARATOR . $apkBaseName . '-debug.apk';
                    if (file_exists($defaultApk)) {
                        // 复制 APK 到 cwd/（不移动，保留 Gradle 默认产物供增量构建）
                        copy($defaultApk, $targetApk);
                        echo "       [YES] {$targetApk}\n";
                        echo "       Install: adb install \"{$targetApk}\"\n";
                    } else {
                        echo "       [YES] APK built (see {$defaultApk})\n";
                    }
                } else {
                    echo "       [NO] Gradle build failed (exit {$apkRet}).\n";
                    echo "       .so is ready at: {$outExe}\n";
                    exit(1);
                }
            }
        }
    }
    } // end else (not $skipAndroidApk)
}

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

// ═══════════════════════════════════════════════════════
// 辅助函数已提取至 src/Helpers.php（require_once 在第 38 行）
//   extractArMembers / deleteDirectory / collectFiles / scanPhpFiles
//   isInBuildDir / extractPharDir / showHelp
// ═══════════════════════════════════════════════════════

/** 统一平台名映射（消除 3 份重复） */
function resolvePlatform(string $name): string {
    static $map = ['windows' => 'Windows', 'linux' => 'Linux', 'darwin' => 'Darwin',
                    'macos' => 'Darwin', 'android' => 'Android'];
    return $map[strtolower($name)] ?? $name;
}

/** 预处理 #flag 指令：展开 __DIR__/__EXT__/__INC__/__CMD__ 并处理字符串拼接 */
function preprocessFlags(string $src, string $fileDir, string $magicExt, string $magicInc, string $magicCmd): string {
    return preg_replace_callback(
        '/^(#flag\s+(?:GCC|Clang|TCC|Windows|Linux|MacOS|Darwin)?\s*(?:GCC|Clang|TCC|Windows|Linux|MacOS|Darwin)?\s*)(.+)$/mi',
        function ($m) use ($fileDir, $magicExt, $magicInc, $magicCmd) {
            $flags = $m[2];
            $flags = str_replace('__DIR__', str_replace('\\', '/', $fileDir), $flags);
            $flags = str_replace('__EXT__', $magicExt, $flags);
            $flags = str_replace('__INC__', $magicInc, $flags);
            $flags = str_replace('__CMD__', $magicCmd, $flags);
            $flags = preg_replace('/\s*\.\s*"/', '/', $flags);
            $flags = preg_replace('/"\s*\.\s*/', '/', $flags);
            $flags = str_replace('"', '', $flags);
            return $m[1] . str_replace('\\', '/', $flags);
        },
        $src
    );
}
