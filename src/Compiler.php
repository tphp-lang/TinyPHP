<?php

declare(strict_types=1);

/**
 * Compiler — 编排整个转译流程
 *   PHP 源文件 → Lexer → Parser → CodeGenerator → .c 文件 → (可选) TCC 编译
 */
class Compiler
{
    private string $tccPath;

    public function __construct(string $tccPath = '')
    {
        $this->tccPath = $tccPath;
    }

    /**
     * 编译单个 PHP 文件 → C 文件
     * @return string 生成的 .c 文件路径
     */
    public function compile(string $phpFile, string $outputDir): string
    {
        if (!file_exists($phpFile)) {
            throw new RuntimeException("File not found: {$phpFile}");
        }

        // 确保输出目录存在
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0777, true);
        }

        $source = file_get_contents($phpFile);
        if ($source === false || trim($source) === '') {
            throw new RuntimeException("文件为空: {$phpFile}");
        }

        // Phase 1: Lex
        $lexer = new Lexer($source);
        $tokens = $lexer->tokenize();

        // Phase 2: Parse
        $parser = new Parser($tokens);
        $ast = $parser->parse();

        // Phase 3: Generate C code
        $gen = new CodeGenerator();
        $cFile = $gen->generate($ast, $phpFile, $outputDir);

        return $cFile;
    }

    /**
     * 编译 PHP → .exe (调用 TCC)
     * @return string 生成的 .exe 路径
     */
    public function compileToExe(string $phpFile, string $outputDir, string $includeDir = ''): string
    {
        $cFile = $this->compile($phpFile, $outputDir);

        if (empty($this->tccPath)) {
            throw new RuntimeException('TCC 路径未配置，无法生成可执行文件');
        }

        $exeFile = $outputDir . '/' . pathinfo($phpFile, PATHINFO_FILENAME) . '.exe';
        $includeDir = $includeDir ?: dirname(__DIR__) . '/include';

        // 调用 TCC 编译
        $cmd = sprintf(
            '%s -I"%s" -o "%s" "%s"',
            escapeshellarg($this->tccPath),
            $includeDir,
            $exeFile,
            $cFile
        );

        $output = [];
        $retval = 0;
        exec($cmd . ' 2>&1', $output, $retval);

        if ($retval !== 0) {
            throw new RuntimeException(
                "TCC compilation failed:\n" . implode("\n", $output)
            );
        }

        return $exeFile;
    }
}
