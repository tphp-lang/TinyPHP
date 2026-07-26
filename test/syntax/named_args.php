<?php
// 命名参数测试（PHP 8.0+）
// 覆盖：用户函数、内置函数、方法（实例/静态）、混合位置+命名参数、参数顺序无关
#debug === Named Arguments Tests ===
#debug
#debug [1] user function all-named:
#debug int(40)
#debug int(40)
#debug
#debug [2] user function mixed positional+named:
#debug int(100)
#debug
#debug [3] user function skip default with named:
#debug string(5) "hello"
#debug
#debug [4] builtin substr(string:, offset:, length:):
#debug string(5) "World"
#debug string(3) "rld"
#debug
#debug [5] builtin str_replace(search:, replace:, subject:):
#debug string(11) "Hellp Wprld"
#debug
#debug [6] instance method all-named:
#debug int(42)
#debug
#debug [7] static method all-named:
#debug int(200)
#debug
#debug [8] constructor with named args:
#debug int(99)
#debug
#debug [9] order-independent (reverse):
#debug int(60)
#debug
#debug [10] mixed positional then named:
#debug int(7)
#debug
#debug === All named arguments tests passed! ===

class Point {
    public int $x;
    public int $y;

    public function __construct(int $x = 0, int $y = 0) {
        $this->x = $x;
        $this->y = $y;
    }

    public static function add(int $a, int $b): int {
        return $a + $b;
    }

    public function sum(int $a, int $b): int {
        return $a + $b + $this->x + $this->y;
    }
}

function compute(int $a, int $b, int $c = 10): int {
    return $a + $b + $c;
}

function repeat(string $s, int $times = 1, string $suffix = ''): string {
    $r = '';
    for ($i = 0; $i < $times; $i++) {
        $r = $r . $s;
    }
    return $r . $suffix;
}

class Main {
    public function main(): void {
        echo "=== Named Arguments Tests ===\n\n";

        // [1] 用户函数全命名参数
        echo "[1] user function all-named:\n";
        $r1 = compute(a: 10, b: 20);
        $r2 = compute(b: 20, a: 10);
        var_dump($r1);
        var_dump($r2);
        echo "\n";

        // [2] 混合位置参数和命名参数
        echo "[2] user function mixed positional+named:\n";
        $r3 = compute(10, b: 20, c: 70);
        var_dump($r3);
        echo "\n";

        // [3] 通过命名参数跳过有默认值的中间参数
        echo "[3] user function skip default with named:\n";
        $r4 = repeat(s: 'hello');
        var_dump($r4);
        echo "\n";

        // [4] 内置函数 substr
        echo "[4] builtin substr(string:, offset:, length:):\n";
        $s = "Hello World";
        $r5 = substr(string: $s, offset: 6, length: 5);
        $r6 = substr(string: $s, offset: 8);
        var_dump($r5);
        var_dump($r6);
        echo "\n";

        // [5] 内置函数 str_replace 顺序无关
        echo "[5] builtin str_replace(search:, replace:, subject:):\n";
        $r7 = str_replace(subject: "Hello World", search: 'o', replace: 'p');
        var_dump($r7);
        echo "\n";

        // [6] 实例方法全命名参数
        echo "[6] instance method all-named:\n";
        $p = new Point(5, 5);
        $r8 = $p->sum(a: 10, b: 22);
        var_dump($r8);
        echo "\n";

        // [7] 静态方法全命名参数
        echo "[7] static method all-named:\n";
        $r9 = Point::add(a: 100, b: 100);
        var_dump($r9);
        echo "\n";

        // [8] 构造函数命名参数
        echo "[8] constructor with named args:\n";
        $p2 = new Point(y: 99, x: 0);
        var_dump($p2->y);
        echo "\n";

        // [9] 顺序无关（反向传参）
        echo "[9] order-independent (reverse):\n";
        $r10 = compute(c: 50, b: 5, a: 5);
        var_dump($r10);
        echo "\n";

        // [10] 位置在前 + 命名在后
        echo "[10] mixed positional then named:\n";
        $r11 = compute(2, 3, c: 2);
        var_dump($r11);
        echo "\n";

        echo "=== All named arguments tests passed! ===\n";
    }
}
