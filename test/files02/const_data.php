<?php // @skip — companion file, no class Main


// ============================================================
// 全局常量 — 全局可用
// ============================================================
const G_STR  = "global";
const G_INT  = 999;
const G_BOOL = true;

// ============================================================
// 类常量 — self:: 访问
// ============================================================

class Calculator
{
    public const float PI = 3.14;
    private const int MULTIPLIER = 100;

    // 方法内通过 self:: 访问常量
    public function area(float $r): float
    {
        return self::PI * $r * $r;
    }

    public function scaled(int $x): int
    {
        return self::MULTIPLIER * $x;
    }
}

class Logger
{
    private const string PREFIX = "[LOG]";
    public const string WARN   = "[WARN]";

    public function log(string $msg): void
    {
        echo "  " . self::PREFIX . ": " . $msg . "\n";
    }

    public function warn(string $msg): void
    {
        echo "  " . self::WARN . ": " . $msg . "\n";
    }
}
