<?php

class Main
{
    public function main(): void
    {
        // ========== 基本四则运算 ==========
        echo "===== basic =====\n";
        $a = 10 + 5;
        var_dump($a);          // int(15)

        $b = 20 - 7;
        var_dump($b);          // int(13)

        $c = 6 * 8;
        var_dump($c);          // int(48)

        $d = 100 / 4;
        var_dump($d);          // int(25)

        // ========== 运算符优先级 ==========
        echo "===== precedence =====\n";
        $e = 2 + 3 * 4;
        var_dump($e);          // int(14) 乘法优先

        $f = (2 + 3) * 4;
        var_dump($f);          // int(20) 括号优先

        $g = 10 - 3 - 2;
        var_dump($g);          // int(5) 左结合

        $h = 100 / 10 / 2;
        var_dump($h);          // int(5) 左结合

        // ========== 负数 ==========
        echo "===== negative =====\n";
        $i = -10;
        var_dump($i);          // int(-10)

        $j = 5 + -3;
        var_dump($j);          // int(2)

        // ========== 浮点数运算 ==========
        echo "===== float =====\n";
        $k = 10.5 + 2.3;
        var_dump($k);          // float(12.8)

        $l = 5.0 * 2.0;
        var_dump($l);          // float(10)

        $m = 10.0 / 4.0;
        var_dump($m);          // float(2.5)

        // ========== 字符串拼接 ==========
        echo "===== string concat =====\n";
        $s1 = "hello" . " world";
        var_dump($s1);         // string(11) "hello world"

        $s2 = "num: " . $a;
        var_dump($s2);         // string(7) "num: 15"

        // ========== 复杂表达式 ==========
        echo "===== complex =====\n";
        $n = (10 + 5) * 2 - 8 / 4;
        var_dump($n);          // int(28)  → (15)*2 - 2 = 28

        $o = 100 / (2 + 3);
        var_dump($o);          // int(20)

        // ========== 大数 ==========
        echo "===== large numbers =====\n";
        $big = 1000000 * 2000;
        var_dump($big);        // int(2000000000)

        // ========== 比较运算符 ==========
        echo "===== comparison =====\n";
        $c1 = 10 > 5;
        var_dump($c1);         // bool(true)

        $c2 = 10 < 5;
        var_dump($c2);         // bool(false)

        $c3 = 10 >= 10;
        var_dump($c3);         // bool(true)

        $c4 = 10 <= 5;
        var_dump($c4);         // bool(false)

        $c5 = 10 == 10;
        var_dump($c5);         // bool(true)

        $c6 = 10 != 5;
        var_dump($c6);         // bool(true)

        $c7 = 5 == 10;
        var_dump($c7);         // bool(false)

        // ========== 逻辑运算符 ==========
        echo "===== logical =====\n";
        $l1 = true && true;
        var_dump($l1);         // bool(true)

        $l2 = true && false;
        var_dump($l2);         // bool(false)

        $l3 = false || true;
        var_dump($l3);         // bool(true)

        $l4 = false || false;
        var_dump($l4);         // bool(false)

        $l5 = !true;
        var_dump($l5);         // bool(false)

        $l6 = !false;
        var_dump($l6);         // bool(true)

        $l7 = !!true;
        var_dump($l7);         // bool(true)

        // ========== 比较 + 逻辑 组合 ==========
        echo "===== comparison + logic =====\n";
        $cl1 = (10 > 5) && (3 < 8);
        var_dump($cl1);        // bool(true)

        $cl2 = (10 < 5) || (3 > 8);
        var_dump($cl2);        // bool(false)

        $cl3 = 5 < 10 && 20 > 15;
        var_dump($cl3);        // bool(true)  && 优先级低于 < >

        $cl4 = 5 > 10 || 3 < 8;
        var_dump($cl4);        // bool(true)

        // ========== 比较 + 算术混合 ==========
        echo "===== compare + arithmetic =====\n";
        $ca1 = 5 + 3 > 2 * 3;  // 8 > 6
        var_dump($ca1);        // bool(true)

        $ca2 = 10 / 2 == 5;    // 5 == 5
        var_dump($ca2);        // bool(true)

        $ca3 = 100 / 4 != 20 + 5;  // 25 != 25
        var_dump($ca3);        // bool(false)

        $ca4 = -5 < 0 && 10 > -10;
        var_dump($ca4);        // bool(true)

        // ========== 浮点数比较 ==========
        echo "===== float compare =====\n";
        $fc1 = 10.5 > 5.2;
        var_dump($fc1);        // bool(true)

        $fc2 = 3.14 < 3.15;
        var_dump($fc2);        // bool(true)

        $fc3 = 1.0 + 2.0 == 3.0;
        var_dump($fc3);        // bool(true)

        // ========== 运算符完整优先级验证 ==========
        echo "===== full precedence =====\n";
        // || < && < ==/!= < </> <=/>= < +/-/. < *//
        $p1 = 1 + 2 * 3 == 7 && 4 < 5 || false;
        // 解析: ((1 + (2*3)) == 7) && (4 < 5) || false
        // 计算: (7 == 7) && true || false → true && true || false → true
        var_dump($p1);         // bool(true)

        $p2 = 2 * 3 + 4 > 2 + 3 * 2 && 10 / 2 == 5;
        // 10 > 8 && 5 == 5 → true && true → true
        var_dump($p2);         // bool(true)

        // ========== 字符串比较 ==========
        echo "===== string compare =====\n";
        $sc1 = "abc" == "abc";
        var_dump($sc1);        // bool(true)

        $sc2 = "abc" == "xyz";
        var_dump($sc2);        // bool(false)

        $sc3 = "abc" != "xyz";
        var_dump($sc3);        // bool(true)

        $sc4 = "abc" < "xyz";
        var_dump($sc4);        // bool(true)  字典序: a < x

        $sc5 = "abc" > "xyz";
        var_dump($sc5);        // bool(false)

        $sc6 = "abc" <= "abc";
        var_dump($sc6);        // bool(true)

        $sc7 = "abc" >= "abd";
        var_dump($sc7);        // bool(false)

        // 字符串变量比较
        $sx = "hello";
        $sy = "hello";
        $sc8 = $sx == $sy;
        var_dump($sc8);        // bool(true)

        $sz = "world";
        $sc9 = $sx != $sz;
        var_dump($sc9);        // bool(true)

        $sc10 = $sx < $sz;
        var_dump($sc10);       // bool(true) "hello" < "world"

        // 字符串与拼接比较
        $sc11 = ($sx . "!") == "hello!";
        var_dump($sc11);       // bool(true)

        // ========== 跨类型比较 ==========
        echo "===== cross-type compare =====\n";

        // int vs float (C 原生比较)
        $x1 = 10 == 10.0;
        var_dump($x1);         // bool(true)

        $x2 = 10 != 10.5;
        var_dump($x2);         // bool(true)

        // float vs int
        $x3 = 10.5 > 5;
        var_dump($x3);         // bool(true)

        // int vs string（两端转字符串比较）
        $x4 = 10 == "10";
        var_dump($x4);         // bool(true)

        $x5 = 10 == "5";
        var_dump($x5);         // bool(false)

        // float vs string
        $x6 = 3.14 == "3.14";
        var_dump($x6);         // bool(true)

        // bool vs int（C 原生: true=1, false=0）
        $x7 = true == 1;
        var_dump($x7);         // bool(true)

        $x8 = false == 0;
        var_dump($x8);         // bool(true)

        $x9 = true == 10;
        var_dump($x9);         // bool(false)

        // null 比较
        $xn1 = null == null;
        var_dump($xn1);        // bool(true)

        $xn2 = null != null;
        var_dump($xn2);        // bool(false)

        $xn3 = 10 == null;
        var_dump($xn3);        // bool(false)  int 永远不等于 null

        $xn4 = 10 != null;
        var_dump($xn4);        // bool(true)

        $xn5 = "str" == null;
        var_dump($xn5);        // bool(false)

        $xn6 = true == null;
        var_dump($xn6);        // bool(false)

        // 复合：变量跨类型比较
        $v1 = 42;
        $v2 = "42";
        $x10 = $v1 == $v2;
        var_dump($x10);        // bool(true)  int 转 string 后比较

        // ========== tphp不接受任意类型自由变换 ==========
        // $x = 10;
        // $x = "10";  // ← 这行会报错：类型不能随意改变
    }
}
