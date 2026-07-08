<?php
#debug ===== Arrow fn single expr (existing) =====
#debug int(11)
#debug
#debug ===== Arrow fn block body =====
#debug int(6)
#debug
#debug ===== Arrow fn block with multiple stmts =====
#debug int(14)
#debug
#debug ===== Arrow fn void block =====
#debug called
#debug
#debug ===== Arrow fn as callable =====
#debug int(100)
#debug
#debug === done ===

class Main
{
    public function main(): void
    {
        // 单表达式（现有语法）
        echo "===== Arrow fn single expr (existing) =====\n";
        callable $inc = fn(int $a): int => $a + 1;
        var_dump($inc(10));
        echo "\n";

        // 块体语法 - 简单 return
        echo "===== Arrow fn block body =====\n";
        callable $dbl = fn(int $a): int => { return $a * 2; };
        var_dump($dbl(3));
        echo "\n";

        // 块体语法 - 多语句 + return
        echo "===== Arrow fn block with multiple stmts =====\n";
        callable $compute = fn(int $x, int $y): int => {
            int $s = $x + $y;
            int $p = $x * $y;
            return $s + $p;
        };
        var_dump($compute(2, 4));
        echo "\n";

        // void 返回类型的块体箭头函数
        echo "===== Arrow fn void block =====\n";
        callable $log = fn(int $x): void => {
            echo "called\n";
        };
        $log(42);
        echo "\n";

        // 作为回调传递
        echo "===== Arrow fn as callable =====\n";
        callable $square = fn(int $n): int => { return $n * $n; };
        var_dump($square(10));
        echo "\n";

        echo "=== done ===\n";
    }
}
