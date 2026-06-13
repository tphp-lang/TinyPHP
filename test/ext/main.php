<?php

// FFI (Foreign Function Interface) — 直接调用 C 动态库

namespace Main;

/**
 * 类型转换函数说明：
 *
 * tPHP → C (传参用)：
 *   CStr($s)     字符串 → C字符串 (heap alloc + null终止)
 *   CInt($n)     整数 → C整数 (直通)
 *   CFloat($f)   浮点 → C浮点 (直通，IEEE754)
 *   CBool($b)    布尔 → C布尔 (0/1)
 *
 * C → tPHP (返回值用)：
 *   TInt($n)     C整数 → tPHP整数 (直通)
 *   TFloat($f)   C浮点 → tPHP浮点 (直通)
 *   TBool($b)    C布尔 → tPHP布尔 (0/1)
 *   TStr($ptr)   C字符串 → tPHP字符串 (strlen + heap copy)
 */

#extern void say_hello(const char* name);

function main(): void
{
    echo "hello world\n";

    // === 演示: CStr 将 tPHP 字符串转为 C 字符串 ===
    $c_name = CStr("tphp");
    C->say_hello($c_name); // hello tphp

    // === 演示: 其他转换 (编译通过即正确) ===
    // CInt: int 直通
    $n = CInt(42);
    var_dump($n);

    // CFloat: float 直通
    $f = CFloat(3.14);
    var_dump($f);

    // CBool: bool → 0/1
    $b = CBool(true);
    var_dump($b);

    // TBool: 0/1 → int
    $b2 = TBool(0);
    var_dump($b2);
}
