<?php

declare(strict_types=1);

// ============================================================
// ErrorCollector — 收集-去重-限流式错误收集器
//
// 设计目标：
//   - 不修改现有 Lexer/Parser/TypeChecker 的 fail-fast 错误处理
//     （它们仍服务于主编译流水线）
//   - 提供独立的错误收集器，支持四级错误体系（note/warn/error/fatal）
//   - 去重：同一 file:line:message 只记录一次（递归检查中避免重复）
//   - 限流：超过 maxErrors 后设置 shouldAbort，供调用方检查
//   - 递归深度保护：防止无限递归拖垮检查器
//
// 错误级别：
//   LEVEL_NOTE  = 1  — 提示信息（不影响 hasErrors）
//   LEVEL_WARN  = 2  — 警告（不影响 hasErrors，可升级为 error）
//   LEVEL_ERROR = 3  — 错误
//   LEVEL_FATAL = 4  — 致命错误（立即设置 shouldAbort）
//
// 运行方式：
//   cd c:\project\php\TinyPHP
//   php tools/AST/error_collector_test.php
// ============================================================

class ErrorCollector
{
    // ═══ 错误级别常量 ═══
    public const LEVEL_NOTE  = 1;
    public const LEVEL_WARN  = 2;
    public const LEVEL_ERROR = 3;
    public const LEVEL_FATAL = 4;

    /** @var array<int,array{level:int,file:string,line:int,message:string}> 错误列表（按添加顺序） */
    private array $errors = [];

    /** @var array<string,bool> 去重键集合（file:line:md5(message) => true） */
    private array $errorKeys = [];

    /** 是否应终止（限流触发或 fatal 错误） */
    private bool $shouldAbort = false;

    /** 错误数量上限 */
    private int $maxErrors = 50;

    /** 当前递归深度 */
    private int $recursionDepth = 0;

    /** 递归深度上限 */
    private int $maxRecursionDepth = 40;

    /** 是否将 warn 升级为 error */
    private bool $warningsAsErrors = false;

    // ─────────────────────────────────────────────────────────────
    // 配置
    // ─────────────────────────────────────────────────────────────

    public function setWarningsAsErrors(bool $v): void
    {
        $this->warningsAsErrors = $v;
    }

    public function setMaxErrors(int $n): void
    {
        $this->maxErrors = $n;
    }

    public function setMaxRecursionDepth(int $n): void
    {
        $this->maxRecursionDepth = $n;
    }

    public function getMaxErrors(): int
    {
        return $this->maxErrors;
    }

    public function getMaxRecursionDepth(): int
    {
        return $this->maxRecursionDepth;
    }

    public function getWarningsAsErrors(): bool
    {
        return $this->warningsAsErrors;
    }

    // ─────────────────────────────────────────────────────────────
    // 四级错误体系
    // ─────────────────────────────────────────────────────────────

    /**
     * 添加一条 note 级别提示。
     */
    public function note(string $file, int $line, string $message): void
    {
        $this->add(self::LEVEL_NOTE, $file, $line, $message);
    }

    /**
     * 添加一条 warn 级别警告。
     * 若 warningsAsErrors=true，则升级为 error 级别。
     */
    public function warn(string $file, int $line, string $message): void
    {
        $level = $this->warningsAsErrors ? self::LEVEL_ERROR : self::LEVEL_WARN;
        $this->add($level, $file, $line, $message);
    }

    /**
     * 添加一条 error 级别错误。
     */
    public function error(string $file, int $line, string $message): void
    {
        $this->add(self::LEVEL_ERROR, $file, $line, $message);
    }

    /**
     * 添加一条 fatal 级别致命错误，立即设置 shouldAbort。
     */
    public function fatal(string $file, int $line, string $message): void
    {
        $this->add(self::LEVEL_FATAL, $file, $line, $message);
        $this->shouldAbort = true;
    }

    /**
     * 格式化错误为字符串："file:line: [LEVEL] message"
     */
    public function formatError(int $level, string $file, int $line, string $message): string
    {
        return "{$file}:{$line}: [{$this->levelName($level)}] {$message}";
    }

    /**
     * 返回级别名称。
     */
    private function levelName(int $level): string
    {
        return match ($level) {
            self::LEVEL_NOTE  => 'NOTE',
            self::LEVEL_WARN  => 'WARN',
            self::LEVEL_ERROR => 'ERROR',
            self::LEVEL_FATAL => 'FATAL',
            default           => 'UNKNOWN',
        };
    }

    // ─────────────────────────────────────────────────────────────
    // 去重 + 限流核心
    // ─────────────────────────────────────────────────────────────

    /**
     * 内部添加错误：先去重，再加入列表；error/fatal 触发限流检查。
     * 去重键："{file}:{line}:{md5(message)}"，已存在则跳过。
     */
    private function add(int $level, string $file, int $line, string $message): void
    {
        $key = "{$file}:{$line}:" . md5($message);
        if (isset($this->errorKeys[$key])) {
            return; // 去重：同一 file:line:message 只记录一次
        }
        $this->errorKeys[$key] = true;
        $this->errors[] = [
            'level'   => $level,
            'file'    => $file,
            'line'    => $line,
            'message' => $message,
        ];

        // 限流：仅 error/fatal 触发检查
        if ($level >= self::LEVEL_ERROR && count($this->errors) >= $this->maxErrors) {
            $this->shouldAbort = true;
        }
    }

    // ─────────────────────────────────────────────────────────────
    // 递归深度保护
    // ─────────────────────────────────────────────────────────────

    /**
     * 进入递归：depth++，若超过 maxRecursionDepth 则记录错误。
     * 调用方应在调用后检查 isRecursionTooDeep() 决定是否继续递归。
     */
    public function enterRecursion(): void
    {
        $this->recursionDepth++;
        if ($this->recursionDepth > $this->maxRecursionDepth) {
            $this->error(
                '<recursion>',
                0,
                "Maximum recursion depth ({$this->maxRecursionDepth}) exceeded"
            );
        }
    }

    /**
     * 离开递归：depth--（不低于 0）。
     */
    public function leaveRecursion(): void
    {
        if ($this->recursionDepth > 0) {
            $this->recursionDepth--;
        }
    }

    /**
     * 检查递归是否超限。
     */
    public function isRecursionTooDeep(): bool
    {
        return $this->recursionDepth > $this->maxRecursionDepth;
    }

    // ─────────────────────────────────────────────────────────────
    // 统一输出
    // ─────────────────────────────────────────────────────────────

    /**
     * 返回所有错误（按添加顺序）。
     * @return array<int,array{level:int,file:string,line:int,message:string}>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * 是否存在 error 或 fatal 级别错误（不含 note/warn）。
     */
    public function hasErrors(): bool
    {
        foreach ($this->errors as $e) {
            if ($e['level'] >= self::LEVEL_ERROR) {
                return true;
            }
        }
        return false;
    }

    /**
     * 格式化输出所有错误，每行一条。
     */
    public function render(): string
    {
        $lines = [];
        foreach ($this->errors as $e) {
            $lines[] = $this->formatError($e['level'], $e['file'], $e['line'], $e['message']);
        }
        return implode("\n", $lines);
    }

    /**
     * 是否应终止（限流触发或 fatal 错误）。
     */
    public function shouldAbort(): bool
    {
        return $this->shouldAbort;
    }

    /**
     * 清空所有错误和运行时状态（保留配置：maxErrors/maxRecursionDepth/warningsAsErrors）。
     */
    public function clear(): void
    {
        $this->errors = [];
        $this->errorKeys = [];
        $this->shouldAbort = false;
        $this->recursionDepth = 0;
    }
}
