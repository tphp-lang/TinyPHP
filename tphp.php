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
$options = getopt('f:o:h', ['help', 'os:', 'arch:', 'debug']);
$cc        = null;
$targetOS  = null; // -os windows|linux|macos
$targetArch = null; // -arch x86_64|aarch64

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

if ((empty($args) && !isset($options['f'])) || isset($options['h']) || isset($options['help'])) {
    showHelp();
}

$outExe = $outExe ?? $options['o'] ?? '';

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

    // Two-phase parsing: parse auxiliary files (non-Main) first,
    // collect enums/classes, then parse Main entry last.
    // Ensures cross-file enums are known when parsing Main.
    $mainFile  = null;
    $otherFiles = [];
    // ── #import 预扫描：引入 ext/name/src/*.php → $files、*.c → $importCFiles ────
    // 用 for 而非 foreach：扩展文件可能有自己的 #import，需递归扫描
    $extRoot = $inPhar ? ($extRootPhar ?? __DIR__ . DIRECTORY_SEPARATOR . 'ext') : (__DIR__ . DIRECTORY_SEPARATOR . 'ext');
    $importCFiles = [];
    for ($fi = 0; $fi < count($files); $fi++) {
        $src = file_get_contents($files[$fi]);
        if (preg_match_all('/^#import\s+(\w[\w\/\-\.]*)/m', (string)$src, $m)) {
            foreach ($m[1] as $extName) {
                $extSrc = $extRoot . DIRECTORY_SEPARATOR . $extName . DIRECTORY_SEPARATOR . 'src';
                if (!is_dir($extSrc)) die("Error: #import {$extName} — ext/{$extName}/src/ not found\n");
                $extPhp = glob($extSrc . DIRECTORY_SEPARATOR . '*.php');
                $extC   = glob($extSrc . DIRECTORY_SEPARATOR . '*.c');
                foreach ($extPhp as $f) { if (!in_array($f, $files)) $files[] = $f; }
                foreach ($extC   as $f) { $rf = realpath($f); if ($rf && !in_array($rf, $importCFiles)) $importCFiles[] = $rf; }
                echo "       #import {$extName} → " . count($extPhp) . " php + " . count($extC) . " c\n";
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

        $lexer  = new Lexer($source, $debugMode);
        $tokens = $lexer->tokenize();
        $parser = new Parser($tokens, $debugMode);
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
        return true;
    }));
    $seenFlags = [];
    $allFlags = array_values(array_filter($allFlags, function ($f) use (&$seenFlags) {
        $s = $f['flags'] ?? '';
        if (isset($seenFlags[$s])) return false;
        $seenFlags[$s] = true;
        return true;
    }));

    $merged = new ProgramNode($mainClass, $extraClasses, $functions, $constants, $enums, $allIncludes, $allFlags, $allCallbacks, $allDebugs);

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

        // Find companion .c files for each #include
        foreach ($allIncludes as $inc) {
            $fileName = is_array($inc) ? $inc['file'] : $inc;
            $baseName = basename($fileName, '.h');
            foreach ($srcDirs as $dir) {
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
        // Detect which compiler class: 'TCC' (built-in), 'GCC', 'Clang'
        $ccClass = 'TCC';  // default built-in
        if ($cc !== null) {
            $ccLower = strtolower($cc);
            if (str_contains($ccLower, 'gcc')) $ccClass = 'GCC';
            elseif (str_contains($ccLower, 'clang')) $ccClass = 'Clang';
            // else keep 'TCC' for unknown compilers
        } elseif (PHP_OS_FAMILY === 'Darwin') {
            // macOS fallback to cc (which is Clang)
            $ccClass = 'Clang';
        }
        foreach ($allFlags as $f) {
            $pf = $f['platform'] ?? '';
            $cf = $f['compiler'] ?? '';
            $platformOk = ($pf === '' || ($platformMap[$pf] ?? '') === $currentOS);
            $compilerOk = ($cf === '' || $cf === $ccClass);
            if ($platformOk && $compilerOk) {
                $extraFlags .= ' ' . $f['flags'];
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
        $extraFlags .= ' -Wno-implicit-function-declaration';
    }

    if (!is_dir($outDir)) mkdir($outDir, 0777, true);

    $gen   = new CodeGenerator();
    $cFile = $gen->generate($merged, $entryFile, $outDir);

    echo "       [YES] {$cFile}\n";

} catch (\Throwable $e) {
    die("[NO] Transpile failed: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
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
    if ($targetOS === 'windows' && !str_ends_with($outExe, '.exe')) {
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
        $bFlag = ' -B"' . $tccSysDir . '"';
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
$cmd = sprintf(
    '"%s" %s%s%s -I"%s" -o "%s" "%s"%s 2>&1',
    $ccExe, $bFlag, $extraFlags, $linkFlags, $includeDir, $outExe, $cFile, $extraSrcs
);

$tccOutput = [];
$retval = 0;
// --debug: print full compile command
if ($debugMode) echo "[DEBUG] {$cmd}\n";
// TCC on Linux may resolve lib paths relative to CWD
$savedCwd = getcwd();
if ($savedCwd !== false && @chdir(__DIR__)) {
    exec($cmd, $tccOutput, $retval);
    @chdir($savedCwd);
} else {
    exec($cmd, $tccOutput, $retval);
}

if ($retval !== 0 || !file_exists($outExe) || filesize($outExe) < 64) {
    echo "[NO] Compile failed:\n";
    if (!empty($tccOutput)) echo implode("\n", $tccOutput) . "\n";
    exit(1);
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
/** @param string[] $args
 *  @return array{0: string[], 1: string[]} */
function collectFiles(array $args): array
{
    $files = [];
    $cFiles = [];
    foreach ($args as $arg) {
        if ($arg === '.') {
            $baseDir = getcwd();
            $files = array_merge($files, scanPhpFiles($baseDir));
            $cFiles = array_merge($cFiles, scanCFiles($baseDir));
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

/** 递归扫描目录下所有 .c 文件，排除 build/ */
function scanCFiles(string $dir): array
{
    $files = [];
    $items = glob($dir . DIRECTORY_SEPARATOR . '*') ?: [];
    foreach ($items as $item) {
        $base = basename($item);
        if ($base === 'build' && is_dir($item)) continue;
        if (is_dir($item)) {
            $files = array_merge($files, scanCFiles($item));
        } elseif (str_ends_with($base, '.c')) {
            $files[] = $item;
        }
    }
    return $files;
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
TinyPHP — PHP → C transpiler (multi-file support, cross-compile)

Usage:
  tphp <file.php> [<file2.php> ...] [-o <output>] [-cc <compiler>] [-os <target>] [-arch <arch>]
  tphp -f <file.php> [-o <output>]
  tphp .                     compile all .php in current dir

Options:
  -o <output>       output file path (default: named after entry file)
  -cc <compiler>    specify C compiler (default: built-in TCC)
  -os <target>      cross-compile target: windows, linux, macos
  -arch <arch>      target architecture: x86_64, aarch64 (default: host)
  --debug           print full compile command
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

HELP;
    exit(0);
}
