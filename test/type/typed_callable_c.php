<?php
#debug ===== Callable typed variable =====
#debug int(30)
#debug int(25)
#debug
#debug ===== Callable reassign (type fixed) =====
#debug int(7)
#debug
#debug === done ===

class Main
{
    public function main(): void
    {
        // callable 类型标记的局部变量 - 闭包
        echo "===== Callable typed variable =====\n";
        callable $add = function(int $a, int $b): int {
            return $a + $b;
        };
        var_dump($add(10, 20));

        // callable 类型标记 - 箭头函数
        callable $mul = fn(int $a, int $b): int => $a * $b;
        var_dump($mul(5, 5));
        echo "\n";

        // callable 类型固定后重赋值
        echo "===== Callable reassign (type fixed) =====\n";
        callable $fn = function(int $x): int { return $x + 1; };
        $fn = function(int $x): int { return $x + 2; };
        var_dump($fn(5));
        echo "\n";

        echo "=== done ===\n";
    }
}
