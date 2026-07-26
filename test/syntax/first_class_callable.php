<?php
// First-class callable 语法测试（PHP 8.1+）
// 覆盖：全局函数引用、用户函数引用、静态/实例方法引用
#debug === First-class Callable Tests ===
#debug
#debug [1] builtin strlen(...):
#debug int(5)
#debug int(11)
#debug
#debug [2] user function greet(...):
#debug string(10) "Hello, Amy"
#debug string(12) "Hello, World"
#debug
#debug [3] static method Class::method(...):
#debug int(200)
#debug
#debug [4] instance method $obj->method(...):
#debug int(42)
#debug int(100)
#debug
#debug [5] via array_map(strlen(...), $arr):
#debug array(3) {
#debug   [0]=>
#debug   int(1)
#debug   [1]=>
#debug   int(2)
#debug   [2]=>
#debug   int(3)
#debug }
#debug
#debug [6] closure variable re-binding $f = $cb(...):
#debug int(5)
#debug
#debug === All first-class callable tests passed! ===

class Calc {
    public int $base = 40;

    public static function double(int $x): int {
        return $x * 2;
    }

    public function addBase(int $x): int {
        return $this->base + $x;
    }
}

function greet(string $name): string {
    return "Hello, " . $name;
}

class Main {
    public function main(): void {
        echo "=== First-class Callable Tests ===\n\n";

        // [1] 内置函数 strlen(...)
        echo "[1] builtin strlen(...):\n";
        $cb = strlen(...);
        var_dump($cb("hello"));
        var_dump($cb("Hello World"));

        echo "\n";

        // [2] 用户函数 greet(...)
        echo "[2] user function greet(...):\n";
        $g = greet(...);
        var_dump($g("Amy"));
        var_dump($g("World"));

        echo "\n";

        // [3] 静态方法 Calc::double(...)
        echo "[3] static method Class::method(...):\n";
        $d = Calc::double(...);
        var_dump($d(100));

        echo "\n";

        // [4] 实例方法 $obj->addBase(...)
        echo '[4] instance method $obj->method(...):' . "\n";
        $obj = new Calc();
        $add = $obj->addBase(...);
        var_dump($add(2));
        $obj->base = 90;
        var_dump($add(10));

        echo "\n";

        // [5] array_map(strlen(...), $arr)
        echo '[5] via array_map(strlen(...), $arr):' . "\n";
        $arr = ["a", "bb", "ccc"];
        $lens = array_map(strlen(...), $arr);
        var_dump($lens);

        echo "\n";

        // [6] 闭包变量 first-class callable $f = $cb(...)
        echo '[6] closure variable re-binding $f = $cb(...):' . "\n";
        $f = $cb(...);
        var_dump($f("world"));

        echo "\n";
        echo "=== All first-class callable tests passed! ===\n";
    }
}
