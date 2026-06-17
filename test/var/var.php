<?php

class Main
{
    public function main(): void
    {
        // 基础类型
        $a = 10;
        var_dump($a); // int(10)
        $b = "hello";
        var_dump($b); // string(5) "hello"
        $c = true;
        var_dump($c); // bool(true)
        $d = 1.01;
        var_dump($d); // float(1.01)
        $e = null;
        var_dump($e); // NULL
        $f = [1, 2, 3];
        var_dump($f); // array(3) { [0]=> int(1) [1]=> int(2) [2]=> int(3) }
        $g = [10, "str", true, [4, 5]];
        var_dump($g); // 嵌套数组

        // 匿名函数 / 闭包 (无参数、无捕获)
        $h = function (): int {
            return 10;
        };
        var_dump($h); // callable
        var_dump($h()); // int(10)

        // 闭包带参数
        $i = function (int $x, int $y): int {
            $z = $x + $y;
            return $z;
        };
        var_dump($i); // callable
        var_dump($i(1, 2)); // int(3)
    }
}
