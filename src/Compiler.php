<?php

declare(strict_types=1);

namespace Tphp;

use Tphp\AST\{ProgramNode, FunctionDeclNode, UseImportNode, ConstDeclNode, ExternFuncNode,
    ClassDeclNode, EnumDeclNode};

/**
 * Main tphp AOT compiler: .php → PE (Windows) or ELF (Linux).
 *
 * No external compilers or linkers required.
 *
 * Supports multi-file compilation with namespaces.
 */
final class Compiler
{
    /** @var string[] */
    private array $inputFiles;
    private string $outputFile;
    private string $target;
    /** @var string[] */
    private array $libPaths;

    /** @var string[] */
    private array $errors = [];

    /**
     * @param string[] $inputFiles
     * @param string[] $libPaths
     */
    public function __construct(
        array $inputFiles,
        string $outputFile = '',
        string $target = 'linux',
        array $libPaths = [],
    ) {
        $this->inputFiles = $inputFiles;
        $this->outputFile = $outputFile !== '' ? $outputFile : $this->defaultOutput($target);
        $this->target     = $target;
        $this->libPaths   = $libPaths;
    }

    private function defaultOutput(string $target): string
    {
        $mainFile = $this->inputFiles[0] ?? 'main';
        $base = pathinfo($mainFile, PATHINFO_FILENAME);
        if ($base === '' || $base === '.') $base = 'main';
        return $base . ($target === 'windows' ? '.exe' : '');
    }

    public function compile(): void
    {
        // 1. Parse all input files
        $allPrograms = [];
        foreach ($this->inputFiles as $file) {
            if (!file_exists($file)) {
                throw new \RuntimeException("File not found: {$file}");
            }
            $source = file_get_contents($file);
            if ($source === false) {
                throw new \RuntimeException("Cannot read file: {$file}");
            }
            if (strlen($source) > 1024 * 1024) {
                throw new \RuntimeException("Source file too large (>1MB): {$file}");
            }

            $lexer = new Lexer($source);
            $lexer->tokenize();

            $parser = new Parser($lexer);
            try {
                $program = $parser->parse();
            } catch (\Throwable $e) {
                throw new \RuntimeException(
                    "{$file}: " . $e->getMessage(),
                    previous: $e
                );
            }
            $this->errors = array_merge($this->errors, $parser->getErrors());
            $allPrograms[] = $program;
        }

        // 2. Collect known function names and imports from all programs
        $knownFqns = [];
        $importMap = [];
        foreach ($allPrograms as $program) {
            $ns = $program->namespace ?? '';
            foreach ($program->functions as $fn) {
                // 空命名空间 → 全局可用（短名）；其余命名空间（含 Main）→ FQN
                $fqn = ($ns === '') ? $fn->name : $ns . '\\' . $fn->name;
                $knownFqns[$fqn] = true;
            }
            foreach ($program->imports as $import) {
                if ($import->importType === 'function') {
                    $importMap[$import->alias] = $import->fullName;
                }
            }
        }

        // 3. Validate each file individually
        foreach ($this->inputFiles as $i => $file) {
            Validator::validateFile($file, $allPrograms[$i], $knownFqns, $importMap, $this->errors);
        }

        // 4. Merge: build merged program with import resolution
        $merged = $this->mergePrograms($allPrograms);

        // 5. Validate entry point (main existence, etc.)
        Validator::validateEntryPoint($merged, $this->errors);

        // Stop if any errors found
        if (!empty($this->errors)) {
            return;
        }

        // 4. Code generation + write (target-specific)
        if ($this->target === 'windows') {
            $this->compileWindows($merged);
        } else {
            $this->compileLinux($merged);
        }

        // Make executable on Linux
        if ($this->target === 'linux' && !str_starts_with(PHP_OS_FAMILY, 'Windows')) {
            chmod($this->outputFile, 0755);
        }
    }

    /**
     * Merge multiple ProgramNodes from different files into one.
     *
     * Strategy:
     * - Collect all functions with their namespace-qualified names (FQNs)
     * - Main namespace functions keep their short names
     * - Non-Main namespace functions get FQN (namespace\name)
     * - Build import map from Main's 'use function' statements
     * - Build import map for class 'use' statements (for step 2)
     */
    private function mergePrograms(array $allPrograms): ProgramNode
    {
        /** @var FunctionDeclNode[] $mergedFunctions */
        $mergedFunctions = [];
        /** @var UseImportNode[] $mergedImports */
        $mergedImports = [];
        /** @var ConstDeclNode[] $mergedConsts */
        $mergedConsts = [];
        /** @var ExternFuncNode[] $mergedExterns */
        $mergedExterns = [];
        /** @var ClassDeclNode[] $mergedClasses */
        $mergedClasses = [];
        /** @var EnumDeclNode[] $mergedEnums */
        $mergedEnums = [];
        $mainProgram = null;

        foreach ($allPrograms as $program) {
            $ns = $program->namespace ?? '';

            // Collect imports from all files
            foreach ($program->imports as $import) {
                $mergedImports[] = $import;
            }

            // Collect extern declarations
            foreach ($program->externs as $ext) {
                $mergedExterns[] = $ext;
            }

            // Collect enum declarations
            foreach ($program->enums as $e) {
                $fqn = ($ns === '') ? $e->name : $ns . '\\' . $e->name;
                $mergedEnums[] = ($fqn === $e->name) ? $e : new EnumDeclNode(
                    $e->line, $fqn, $e->backingType, $e->cases,
                );
            }

            // Collect const declarations
            foreach ($program->consts as $c) {
                if ($ns === '') {
                    // 空命名空间 → 全局可用
                    $mergedConsts[] = $c;
                } else {
                    $mergedConsts[] = new ConstDeclNode(
                        $c->line,
                        $ns . '\\' . $c->name,
                        $c->init,
                    );
                }
            }

            foreach ($program->functions as $fn) {
                if ($ns === 'Main') {
                    $mainProgram = $program;
                }
                // 空命名空间 → 短名（全局）；其余（含 Main）→ FQN
                $fqn = ($ns === '') ? $fn->name : $ns . '\\' . $fn->name;
                $mergedFunctions[] = ($fqn === $fn->name) ? $fn : new FunctionDeclNode(
                    $fn->line,
                    $fqn,
                    $fn->returnType,
                    $fn->params,
                    $fn->body,
                );
            }

            // Merge class declarations
            foreach ($program->classes as $cls) {
                if ($ns === '') {
                    $mergedClasses[] = $cls;
                } else {
                    $mergedClasses[] = new ClassDeclNode(
                        $cls->line,
                        $ns . '\\' . $cls->name,
                        $cls->methods,
                    );
                }
            }
        }

        if ($mainProgram === null) {
            throw new \RuntimeException("No Main namespace found. Entry file must use 'namespace Main;'");
        }

        return new ProgramNode(1, 'Main', $mergedImports, $mergedConsts, $mergedEnums, $mergedExterns, $mergedFunctions, $mergedClasses);
    }

    private function compileLinux(ProgramNode $program): void
    {
        $cg = new CodeGenerator();
        $cg->generate($program);
        $builder = $cg->getBuilder();

        $writer = new ELFWriter($this->outputFile);
        $writer->setCode($builder->getCode());
        $writer->write();
    }

    private function compileWindows(ProgramNode $program): void
    {
        // ---- Phase 1: Generate code ----
        $cg = new CodeGeneratorWindows();
        $cg->setLibPaths($this->libPaths);
        $cg->generate($program);

        $builder  = $cg->getBuilder();
        $strings  = $builder->getStrings();
        $iatSlots = $builder->getIatSlots();

        // Merge required IAT functions (order matters)
        $requiredIat = ['LoadLibraryA', 'GetProcAddress', 'GetStdHandle', 'WriteFile', 'ExitProcess', 'SetConsoleOutputCP', 'HeapAlloc', 'GetProcessHeap'];
        $finalIat = [];
        $seen = [];
        foreach ($requiredIat as $fn) {
            $finalIat[] = $fn;
            $seen[$fn] = true;
        }
        foreach ($iatSlots as $idx => $fn) {
            if (!isset($seen[$fn])) {
                $finalIat[] = $fn;
                $seen[$fn] = true;
            }
        }

        // ---- Phase 2: Prepare PE layout (computes import table, string RVAs) ----
        $code = $builder->getCode();
        $writer = new PEWriter($this->outputFile);
        $writer->setPayload($code, $strings, $finalIat);
        $stringRvas = $writer->prepare();

        // ---- Phase 3: Resolve patches in the builder's code buffer ----
        $writer->resolveIatPatches($builder);
        $writer->resolveStringPatches($builder);

        // ---- Phase 4: Re-set payload with patched code, then write ----
        $patchedCode = $builder->getCode();
        $writer->setPayload($patchedCode, $strings, $finalIat);
        $writer->write();
    }

    public function getOutputPath(): string
    {
        return $this->outputFile;
    }

    /** @return string[] */
    public function getErrors(): array
    {
        return $this->errors;
    }
}
