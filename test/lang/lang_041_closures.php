<?php
// 对应 PHP tests/lang/ — 闭包与 use 捕获
// 无直接对应 .phpt（lang/ 目录无闭包专项测试）
#debug 5
#debug 15
#debug 8
#debug 100

class Main {
    public function main(): void {
        // 基本匿名函数
        $add = function (int $x, int $y): int {
            return $x + $y;
        };
        echo $add(2, 3) . "\n";

        // use 捕获外部变量（按值）
        $base = 10;
        $addBase = function (int $x) use ($base): int {
            return $x + $base;
        };
        echo $addBase(5) . "\n";

        // 闭包返回值赋给变量
        $mul = function (int $x, int $y): int {
            return $x * $y;
        };
        $n = $mul(2, 4);
        echo $n . "\n";

        // use 捕获并读取（不影响外部）
        $counter = 100;
        $get = function () use ($counter): int {
            return $counter;
        };
        echo $get() . "\n";
    }
}
