<?php
// 对应 PHP tests/func/010.phpt — callbacks via closure invocation
// 原生使用 call_user_func, tphp 不支持, 改用 $cb(...) 直接调用
#debug int(10)
#debug int(25)
#debug int(100)
#debug int(50)
#debug int(15)
#debug int(15)
#debug int(50)

// 函数接受闭包作为回调, 直接调用 $cb($x)
function apply(int $x, callable $cb): int {
    return $cb($x);
}

// 函数接受闭包, 重复调用 n 次
function repeat(int $x, callable $cb, int $n): int {
    $result = $x;
    for ($i = 0; $i < $n; $i++) {
        $result = $cb($result);
    }
    return $result;
}

class Main
{
    public function main(): void
    {
        // 1. 简单回调: 5 * 2 = 10
        // 注: 避免使用 $double 变量名, 因其转译为 C 关键字 double 会编译失败
        $dbl = function (int $v): int { return $v * 2; };
        var_dump(apply(5, $dbl));

        // 2. 平方回调: 5 * 5 = 25
        $square = function (int $v): int { return $v * $v; };
        var_dump(apply(5, $square));

        // 3. use 捕获的闭包作为回调: 10 + 90 = 100
        $base = 90;
        $addBase = function (int $v) use ($base): int { return $v + $base; };
        var_dump(apply(10, $addBase));

        // 4. 内联闭包作为回调: 10 * 5 = 50
        var_dump(apply(10, function (int $v): int { return $v * 5; }));

        // 5. 重复应用回调: 5 -> 10 -> 15
        $inc = function (int $v): int { return $v + 5; };
        var_dump(repeat(5, $inc, 2));

        // 6. 多个闭包捕获同一变量
        $offset = 5;
        $add = function (int $x) use ($offset): int { return $x + $offset; };
        $mul = function (int $x) use ($offset): int { return $x * $offset; };
        var_dump(apply(10, $add));   // int(15)
        var_dump(apply(10, $mul));   // int(50)
    }
}
