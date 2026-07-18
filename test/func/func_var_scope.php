<?php
// 对应 PHP tests/func/006.phpt — variable scope (function-level)
// tphp 变量作用域与 PHP 一致: 函数级作用域, 无 global 关键字
#debug int(1)
#debug int(0)
#debug int(6)
#debug int(5)
#debug int(1042)
#debug int(42)

// 函数内局部变量不影响外部
function setLocal(): void {
    $local = 100;
}

// 函数内对外部同名变量的赋值是局部副本
function shadow(): int {
    $x = 0;
    return $x;
}

// 函数参数是局部副本 — 修改不影响调用者
function incParam(int $n): int {
    $n = $n + 1;
    return $n;
}

// 函数内修改参数不影响调用者
function modifyArg(int $v): int {
    $v = $v + 1000;
    return $v;
}

class Main
{
    public function main(): void
    {
        // 1. 函数内局部赋值不泄漏到外部
        $x = 1;
        setLocal();
        var_dump($x);            // int(1)

        // 2. 函数内同名变量是局部副本
        var_dump(shadow());      // int(0)

        // 3. 参数是局部副本 — 返回值反映修改
        $n = 5;
        var_dump(incParam($n));  // int(6)

        // 4. 调用者的 $n 不受影响
        var_dump($n);            // int(5)

        // 5. 函数内修改参数不影响调用者
        $v = 42;
        var_dump(modifyArg($v)); // int(1042)

        // 6. 调用者的 $v 不受影响
        var_dump($v);            // int(42)
    }
}
