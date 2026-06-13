#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * tphp - A PHP-based AOT compiler
 *
 * Compiles tphp source code (.php) into native executables:
 *   - Linux: ELF format (x86-64)
 *   - Windows: PE format (x86-64)
 *
 * Usage:
 *   php tphp.php xxx.php                          # Build for current OS
 *   php tphp.php xxx.php --target=linux           # Build for Linux ELF
 *   php tphp.php xxx.php --target=windows         # Build for Windows PE
 *   php tphp.php xxx.php -o output.exe            # Custom output name
 *   php tphp.php xxx.php -o output                # Custom output (no ext)
 *
 * -o default: input file basename without .php extension
 *   (e.g. "xxx.php" → "xxx" on Linux, "xxx.exe" on Windows)
 */

// ---- Autoloader ----
spl_autoload_register(function (string $class): void {
    $prefix = 'Tphp\\';
    $baseDir = __DIR__ . '/src/';

    // AST classes split into 4 files by category
    if (str_starts_with($class, 'Tphp\\AST\\')) {
        $shortName = substr($class, strlen('Tphp\\AST\\'));
        $file = match (true) {
            // Node.php: base classes + TphpType
            in_array($shortName, ['ASTNode', 'TphpType', 'ExprNode', 'StmtNode'], true)
                => $baseDir . 'AST/Node.php',
            // Decl.php: declarations (program, function, class, enum, const, extern, use import)
            in_array($shortName, ['ProgramNode', 'UseImportNode', 'ConstDeclNode', 'FunctionDeclNode',
                'ParamNode', 'ClassDeclNode', 'MethodDeclNode', 'EnumDeclNode', 'EnumCaseNode', 'ExternFuncNode'], true)
                => $baseDir . 'AST/Decl.php',
            // Stmt.php: statement nodes
            in_array($shortName, ['VarDeclNode', 'ExprStmtNode', 'PrintStmtNode', 'ReturnStmtNode',
                'IfStmtNode', 'WhileStmtNode', 'ForStmtNode', 'SwitchStmtNode', 'SwitchCaseNode',
                'BreakStmtNode', 'UnsetStmtNode', 'ArrayAppendStmtNode'], true)
                => $baseDir . 'AST/Stmt.php',
            // Expr.php: all expression nodes (catch-all)
            default => $baseDir . 'AST/Expr.php',
        };
        if (file_exists($file)) { require_once $file; }
        return;
    }

    $len = strlen($prefix);
    if (strncmp($class, $prefix, $len) !== 0) {
        return;
    }

    $relativeClass = substr($class, $len);
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

    if (file_exists($file)) {
        require_once $file;
    }
});

use Tphp\Compiler;

// ---- Help ----
function showHelp(): void
{
    echo <<<HELP
tphp - AOT compiler: tphp source → native executables (PE / ELF)

Usage:
  php tphp.php <input.php> [options]

Options:
  --target=<os>   Target OS: linux or windows (default: current OS)
  -o <file>       Output file path (default: input basename without .php)
  -lib <path>     DLL path for FFI extern functions (repeatable)


Examples:
  php tphp.php test/hello.php
  php tphp.php test/hello.php -o myprogram
  php tphp.php test/hello.php --target=windows -o myprogram.exe

HELP;
}

// ---- Parse args ----
$args = $argv ?? [];
array_shift($args); // remove script name

if (empty($args)) {
    showHelp();
    exit(1);
}

/** @var string[] $inputFiles */
$inputFiles = [];
$outputFile = '';
$target     = PHP_OS_FAMILY === 'Windows' ? 'windows' : 'linux';
$libPaths   = [];

while (!empty($args)) {
    $arg = array_shift($args);

    if (str_starts_with($arg, '--target=')) {
        $target = substr($arg, strlen('--target='));
        if (!in_array($target, ['linux', 'windows'], true)) {
            fwrite(STDERR, "Invalid target: $target. Use 'linux' or 'windows'.\n");
            exit(1);
        }
    } elseif ($arg === '-o') {
        $outputFile = array_shift($args) ?? '';
    } elseif ($arg === '-lib') {
        $libPaths[] = array_shift($args) ?? '';
    } elseif ($arg === '-h' || $arg === '--help') {
        showHelp();
        exit(0);
    } elseif (str_starts_with($arg, '-')) {
        fwrite(STDERR, "Unknown option: $arg\n");
        exit(1);
    } else {
        // Collect .php files or expand directories
        if ($arg === '.') {
            // Compile all .php files in current directory + subdirectories
            $files = findPhpFiles(getcwd());
            if (empty($files)) {
                fwrite(STDERR, "Error: No .php files found in current directory.\n");
                exit(1);
            }
            $inputFiles = array_merge($inputFiles, $files);
        } elseif (is_dir($arg)) {
            $files = findPhpFiles($arg);
            if (empty($files)) {
                fwrite(STDERR, "Error: No .php files found in directory: $arg\n");
                exit(1);
            }
            $inputFiles = array_merge($inputFiles, $files);
        } elseif (str_ends_with($arg, '.php')) {
            $inputFiles[] = $arg;
        } else {
            // Treat as a regular file
            $inputFiles[] = $arg;
        }
    }
}

if (empty($inputFiles)) {
    fwrite(STDERR, "Error: No input file specified.\n");
    showHelp();
    exit(1);
}

/**
 * Recursively find all .php files in a directory, with main.php first.
 * @return string[]
 */
function findPhpFiles(string $dir): array
{
    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }
    // Sort: main.php first, then alphabetical
    usort($files, function (string $a, string $b): int {
        $aIsMain = basename($a) === 'main.php';
        $bIsMain = basename($b) === 'main.php';
        if ($aIsMain && !$bIsMain) return -1;
        if (!$aIsMain && $bIsMain) return 1;
        return strcmp($a, $b);
    });
    return $files;
}

// ---- Compile ----
try {
    $compiler = new Compiler(
        inputFiles: $inputFiles,
        outputFile: $outputFile,
        target:     $target,
        libPaths:   $libPaths,
    );

    $compiler->compile();

    $errors = $compiler->getErrors();
    if (!empty($errors)) {
        foreach ($errors as $e) {
            fwrite(STDERR, "$e\n");
        }
        exit(1);
    }

    $out = $outputFile !== '' ? $outputFile : $compiler->getOutputPath();
    $fileList = implode(', ', array_map(fn($f) => basename($f), $inputFiles));
    echo "✓ Compiled: {$fileList} → $out (target: $target)\n";

} catch (\Throwable $e) {
    fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
    exit(1);
}
