<?php
#debug ===== Local Variables (typed) =====
#debug int(42)
#debug float(3.14)
#debug string(5) "hello"
#debug bool(true)
#debug int(3)
#debug
#debug ===== Local Variables (untyped, inference) =====
#debug int(100)
#debug string(5) "world"
#debug
#debug ===== Global Constants (typed) =====
#debug string(11) "typed hello"
#debug int(7)
#debug float(2.71)
#debug bool(false)
#debug
#debug ===== Global Constants (untyped) =====
#debug string(13) "untyped hello"
#debug int(99)
#debug
#debug ===== Class Constants (required type) =====
#debug string(5) "typed"
#debug int(255)
#debug float(0.5)
#debug bool(true)
#debug
#debug ===== Typed Object Variable =====
#debug string(5) "Point"
#debug
#debug ===== Reassign (type fixed) =====
#debug int(50)
#debug string(7) "changed"
#debug
#debug === ALL type annotation tests done ===

// 全局常量 - 带类型标记
const string TYPED_STR  = "typed hello";
const int    TYPED_NUM  = 7;
const float  TYPED_PI   = 2.71;
const bool   TYPED_FLAG = false;

// 全局常量 - 无类型标记 (自动推导)
const UNTYPED_STR = "untyped hello";
const UNTYPED_NUM = 99;

class Point
{
    public function __construct(public int $x, public int $y) {}
}

class Config
{
    // 类常量 - 类型必填
    const string NAME  = "typed";
    const int    MAX   = 255;
    const float  RATE  = 0.5;
    const bool   DEBUG = true;
}

class Main
{
    public function main(): void
    {
        echo "===== Local Variables (typed) =====\n";
        int $a = 42;
        float $b = 3.14;
        string $c = "hello";
        bool $d = true;
        array $e = [1, 2, 3];
        var_dump($a);
        var_dump($b);
        var_dump($c);
        var_dump($d);
        var_dump(count($e));
        echo "\n";

        echo "===== Local Variables (untyped, inference) =====\n";
        $x = 100;
        $y = "world";
        var_dump($x);
        var_dump($y);
        echo "\n";

        echo "===== Global Constants (typed) =====\n";
        var_dump(TYPED_STR);
        var_dump(TYPED_NUM);
        var_dump(TYPED_PI);
        var_dump(TYPED_FLAG);
        echo "\n";

        echo "===== Global Constants (untyped) =====\n";
        var_dump(UNTYPED_STR);
        var_dump(UNTYPED_NUM);
        echo "\n";

        echo "===== Class Constants (required type) =====\n";
        var_dump(Config::NAME);
        var_dump(Config::MAX);
        var_dump(Config::RATE);
        var_dump(Config::DEBUG);
        echo "\n";

        echo "===== Typed Object Variable =====\n";
        Point $p = new Point(3, 4);
        var_dump("Point");
        echo "\n";

        echo "===== Reassign (type fixed) =====\n";
        int $r = 10;
        $r = 50;
        var_dump($r);
        string $s = "initial";
        $s = "changed";
        var_dump($s);
        echo "\n";

        echo "=== ALL type annotation tests done ===\n";
    }
}
