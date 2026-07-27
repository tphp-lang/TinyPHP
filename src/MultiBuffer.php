<?php

declare(strict_types=1);

// ============================================================
// MultiBuffer — 多缓冲区输出架构
//
// 设计目标：
//   - 解决 C 代码生成中 "先声明后使用" 的顺序约束
//   - 不修改现有 CodeGenerator.php（仍服务于主编译流水线）
//   - FlatCodeGenerator 可选用此架构管理多段输出
//
// 缓冲区分层（按 render() 拼接顺序）：
//   1. cheaders    — C 头文件包含（#include <stdio.h> 等）
//   2. typedefs    — 类型定义（typedef struct {...} Foo;）
//   3. definitions — 顶层声明（函数前向声明、extern 变量）
//   4. auto_funcs  — 自动生成辅助函数（如 _str、_free、_retain）
//   5. out         — 函数体（用户函数实现、main 函数）
//   6. cleanups    — 清理函数（atexit 注册、资源释放）
//
// 运行方式：
//   cd c:\project\php\TinyPHP
//   php tools/AST/multi_buffer_test.php
// ============================================================

class MultiBuffer
{
    // ═══ 缓冲区顺序常量 ═══
    public const BUFFER_ORDER = [
        'cheaders',
        'typedefs',
        'definitions',
        'auto_funcs',
        'out',
        'cleanups',
    ];

    /** @var array<string,string> 缓冲区数组，key=缓冲区名，value=字符串 */
    private array $buffers = [];

    /** 当前活跃缓冲区名 */
    private string $currentBuffer = 'out';

    /** @var array<int,string> 拼接顺序（与 BUFFER_ORDER 一致，实例字段便于扩展） */
    private array $bufferOrder;

    // ─────────────────────────────────────────────────────────────
    // 初始化
    // ─────────────────────────────────────────────────────────────

    public function __construct()
    {
        $this->bufferOrder = self::BUFFER_ORDER;
        foreach ($this->bufferOrder as $name) {
            $this->buffers[$name] = '';
        }
        $this->currentBuffer = 'out';
    }

    // ─────────────────────────────────────────────────────────────
    // 缓冲区选择与基础追加
    // ─────────────────────────────────────────────────────────────

    /**
     * 选择当前活跃缓冲区。
     *
     * @param string $name 缓冲区名（必须存在于 bufferOrder）
     */
    public function select(string $name): void
    {
        if (!isset($this->buffers[$name])) {
            throw new InvalidArgumentException("Unknown buffer: {$name}");
        }
        $this->currentBuffer = $name;
    }

    /**
     * 返回当前活跃缓冲区名。
     */
    public function current(): string
    {
        return $this->currentBuffer;
    }

    /**
     * 向当前缓冲区追加内容。
     */
    public function append(string $content): void
    {
        $this->buffers[$this->currentBuffer] .= $content;
    }

    /**
     * 向当前缓冲区追加一行（自动添加 \n）。
     */
    public function appendLine(string $line = ''): void
    {
        $this->buffers[$this->currentBuffer] .= $line . "\n";
    }

    /**
     * 向指定缓冲区追加内容（不影响 currentBuffer）。
     */
    public function appendTo(string $bufferName, string $content): void
    {
        if (!isset($this->buffers[$bufferName])) {
            throw new InvalidArgumentException("Unknown buffer: {$bufferName}");
        }
        $this->buffers[$bufferName] .= $content;
    }

    /**
     * 向指定缓冲区追加一行（自动添加 \n，不影响 currentBuffer）。
     */
    public function appendLineTo(string $bufferName, string $line = ''): void
    {
        if (!isset($this->buffers[$bufferName])) {
            throw new InvalidArgumentException("Unknown buffer: {$bufferName}");
        }
        $this->buffers[$bufferName] .= $line . "\n";
    }

    // ─────────────────────────────────────────────────────────────
    // 缓冲区读取与设置
    // ─────────────────────────────────────────────────────────────

    /**
     * 获取指定缓冲区内容。
     */
    public function getBuffer(string $name): string
    {
        if (!isset($this->buffers[$name])) {
            throw new InvalidArgumentException("Unknown buffer: {$name}");
        }
        return $this->buffers[$name];
    }

    /**
     * 设置指定缓冲区内容（覆盖）。
     */
    public function setBuffer(string $name, string $content): void
    {
        if (!isset($this->buffers[$name])) {
            throw new InvalidArgumentException("Unknown buffer: {$name}");
        }
        $this->buffers[$name] = $content;
    }

    /**
     * 清空指定缓冲区。
     */
    public function clear(string $name): void
    {
        if (!isset($this->buffers[$name])) {
            throw new InvalidArgumentException("Unknown buffer: {$name}");
        }
        $this->buffers[$name] = '';
    }

    /**
     * 清空所有缓冲区。
     */
    public function clearAll(): void
    {
        foreach ($this->bufferOrder as $name) {
            $this->buffers[$name] = '';
        }
        $this->currentBuffer = 'out';
    }

    /**
     * 检查指定缓冲区是否有内容。
     */
    public function hasContent(string $name): bool
    {
        if (!isset($this->buffers[$name])) {
            throw new InvalidArgumentException("Unknown buffer: {$name}");
        }
        return $this->buffers[$name] !== '';
    }

    /**
     * 返回所有缓冲区名（按 bufferOrder 顺序）。
     *
     * @return array<int,string>
     */
    public function bufferNames(): array
    {
        return $this->bufferOrder;
    }

    // ─────────────────────────────────────────────────────────────
    // 高级功能：去重添加
    // ─────────────────────────────────────────────────────────────

    /**
     * 检查 definitions 缓冲区是否已包含声明，没有则添加（去重）。
     *
     * 用于确保 C 函数前向声明存在，例如：
     *   $buf->ensureForwardDecl("t_int tphp_fn_add(t_int, t_int);");
     *
     * @param string $decl 一行声明文本（不含末尾换行）
     */
    public function ensureForwardDecl(string $decl): void
    {
        $buf = $this->buffers['definitions'];
        if (!str_contains($buf, $decl)) {
            $this->buffers['definitions'] .= $decl . "\n";
        }
    }

    /**
     * 添加自动函数到 auto_funcs，去重。
     *
     * 用于自动生成的辅助函数（如 _str、_free、_retain），
     * 同样的函数定义只添加一次。
     *
     * @param string $funcDef 完整函数定义文本
     */
    public function addAutoFunc(string $funcDef): void
    {
        $buf = $this->buffers['auto_funcs'];
        if (!str_contains($buf, $funcDef)) {
            $this->buffers['auto_funcs'] .= $funcDef . "\n";
        }
    }

    /**
     * 添加清理代码到 cleanups。
     *
     * 用于 atexit 注册、资源释放等收尾代码。
     *
     * @param string $cleanupCode 一行清理代码
     */
    public function addCleanup(string $cleanupCode): void
    {
        $this->buffers['cleanups'] .= $cleanupCode . "\n";
    }

    // ─────────────────────────────────────────────────────────────
    // 拼接输出
    // ─────────────────────────────────────────────────────────────

    /**
     * 按 bufferOrder 顺序拼接所有缓冲区，返回完整 C 代码。
     *
     * - 严格按 bufferOrder 顺序拼接
     * - 每个缓冲区之间插入空行分隔
     * - 空缓冲区跳过（不输出空段）
     */
    public function render(): string
    {
        $parts = [];
        foreach ($this->bufferOrder as $name) {
            $content = $this->buffers[$name] ?? '';
            if ($content !== '') {
                $parts[] = $content;
            }
        }
        return implode("\n\n", $parts);
    }

    /**
     * 拼接时插入分区注释（如 `/* === TYPEDEFS === *\/`）。
     *
     * @param bool $withComments true 时插入分区注释，false 时退化为 render()
     */
    public function renderWithSeparators(bool $withComments = true): string
    {
        if (!$withComments) {
            return $this->render();
        }
        $parts = [];
        foreach ($this->bufferOrder as $name) {
            $content = $this->buffers[$name] ?? '';
            if ($content !== '') {
                $label = strtoupper($name);
                $parts[] = "/* === {$label} === */\n" . $content;
            }
        }
        return implode("\n\n", $parts);
    }
}
