<?php // @skip — companion file, no class Main

// ns_compat_util_lib.php — 命名空间兼容性测试辅助文件（NSCompat\Util 命名空间）
//   覆盖以下问题的修复：
//   问题1: 命名空间内调用全局函数（strtoupper/strlen/urldecode）的返回类型推断

namespace NSCompat\Util;

class Helper
{
    public string $name;

    public function __construct(string $n)
    {
        $this->name = $n;
    }

    // 问题1: 命名空间内调用全局函数 — 返回类型推断必须 fallback 到全局
    //   修复前: resolveFunctionName() 给函数名加命名空间前缀，
    //           inferCallReturnType() 在内置表中查不到 → "Unknown function return type"
    //   修复后: 命名空间前缀查不到时 fallback 到全局函数
    public function upper(): string
    {
        return strtoupper($this->name);
    }

    public function length(): int
    {
        return strlen($this->name);
    }

    public function decoded(): string
    {
        return urldecode($this->name);
    }
}
