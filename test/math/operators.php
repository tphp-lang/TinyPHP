<?php
#debug === ** 幂运算 ===
#debug int(8)
#debug int(1024)
#debug int(512)
#debug int(64)
#debug int(1)
#debug int(99)
#debug int(81)
#debug int(18)
#debug int(20)
#debug float(6.25)
#debug float(3)
#debug int(-8)
#debug int(9)
#debug int(3)
#debug int(9)
#debug int(25)
#debug === <=> 太空船运算 ===
#debug int(-1)
#debug int(1)
#debug int(0)
#debug int(0)
#debug int(-1)
#debug int(1)
#debug int(1)
#debug int(-1)
#debug int(0)
#debug int(-1)
#debug int(1)
#debug int(0)
#debug int(-1)
#debug int(1)
#debug int(-1)
#debug int(1)
#debug int(0)
#debug int(1)
#debug less
#debug big
#debug int(1)
#debug int(-1)
#debug int(0)
#debug 3 < 5 confirmed
#debug === 混合测试 ===
#debug int(-1)
#debug int(1)
#debug int(1)
#debug === done ===

class Main
{
    public function main(): void
    {
        echo "=== ** 幂运算 ===\n";

        // 基础整数幂
        $a = 2 ** 3;
        var_dump($a);            // expect: 8

        $b = 2 ** 10;
        var_dump($b);            // expect: 1024

        // 右结合: 2**3**2 = 2**(3**2) = 512
        $c = 2 ** 3 ** 2;
        var_dump($c);            // expect: 512

        // 明确括号
        $d = (2 ** 3) ** 2;
        var_dump($d);            // expect: 64

        // 0 次幂
        $e = 5 ** 0;
        var_dump($e);            // expect: 1

        // 1 次幂
        $f = 99 ** 1;
        var_dump($f);            // expect: 99

        // 变量参与
        $base = 3;
        $exp  = 4;
        $g = $base ** $exp;
        var_dump($g);            // expect: 81

        // 与乘除混合（** 优先级高于 * /）
        $h = 2 * 3 ** 2;
        var_dump($h);            // expect: 18

        $i = 10 ** 2 / 5;
        var_dump($i);            // expect: 20

        // 浮点幂
        $j = 2.5 ** 2;
        var_dump($j);            // expect: 6.25

        $k = 9.0 ** 0.5;
        var_dump($k);            // expect: 3.0

        // 负底数（奇数次）
        $l = (-2) ** 3;
        var_dump($l);            // expect: -8

        // 负底数（偶数次）
        $m = (-3) ** 2;
        var_dump($m);            // expect: 9

        // 自增与幂
        $n = 2;
        $o = ++$n ** 2;
        var_dump($n);            // expect: 3
        var_dump($o);            // expect: 9

        // 幂组合赋值
        $p = 5;
        $p = $p ** 2;
        var_dump($p);            // expect: 25

        echo "=== <=> 太空船运算 ===\n";

        // 整数比较
        var_dump(1 <=> 2);       // expect: -1
        var_dump(2 <=> 1);       // expect: 1
        var_dump(1 <=> 1);       // expect: 0
        var_dump(0 <=> 0);       // expect: 0
        var_dump((-5) <=> 3);    // expect: -1
        var_dump(100 <=> (-10)); // expect: 1

        // 浮点比较
        var_dump(1.5 <=> 1.2);   // expect: 1
        var_dump(1.1 <=> 1.9);   // expect: -1
        var_dump(2.0 <=> 2.0);   // expect: 0

        // 字符串比较
        var_dump("a" <=> "b");   // expect: -1
        var_dump("b" <=> "a");   // expect: 1
        var_dump("a" <=> "a");   // expect: 0
        var_dump("abc" <=> "abd"); // expect: -1
        var_dump("zzz" <=> "aaa"); // expect: 1

        // 变量参与
        $x = 10;
        $y = 20;
        var_dump($x <=> $y);     // expect: -1
        var_dump($y <=> $x);     // expect: 1
        var_dump($x <=> $x);     // expect: 0

        // 嵌套表达式
        $z = (5 * 3) <=> (2 + 10);
        var_dump($z);            // expect: 1

        // switch 中使用 <=>
        $val = 5;
        switch ($val <=> 10) {
            case -1:
                echo "less\n";
                break;
            case 0:
                echo "equal\n";
                break;
            case 1:
                echo "greater\n";
                break;
        }                        // expect: less

        // match 中使用 <=>
        $result = match ($val <=> 3) {
            -1 => "small",
            0  => "same",
            1  => "big",
        };
        echo $result . "\n";     // expect: big

        // bool 比较: true > false
        var_dump(true <=> false);  // expect: 1
        var_dump(false <=> true);  // expect: -1
        var_dump(true <=> true);   // expect: 0

        // if 条件
        if ((3 <=> 5) == -1) {
            echo "3 < 5 confirmed\n";
        }

        echo "=== 混合测试 ===\n";

        // 幂 + 太空船 组合
        $p1 = 2 ** 3 <=> 2 ** 4;
        var_dump($p1);           // expect: -1   (8 <=> 16)

        $p2 = 3 ** 2 <=> 2 ** 3;
        var_dump($p2);           // expect: 1    (9 <=> 8)

        // 优先级：** > 乘除 > 加减 > <=> > 三元
        $combo = 2 + 3 ** 2 * 2 <=> 5 * 4 - 1;
        // 2 + 9*2 <=> 20-1
        // 2 + 18 <=> 19
        // 20 <=> 19 = 1
        var_dump($combo);        // expect: 1

        echo "=== done ===\n";
    }
}
