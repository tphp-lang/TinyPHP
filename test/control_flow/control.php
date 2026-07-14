<?php
#debug ===== if/else =====
#debug pass
#debug small
#debug B
#debug nested: both true
#debug correct
#debug ===== while =====
#debug 012
#debug int(0)
#debug ===== for =====
#debug 012
#debug 321
#debug ===== foreach =====
#debug int(10)
#debug int(20)
#debug int(30)
#debug 0:10
#debug 1:20
#debug 2:30
#debug ===== else if =====
#debug small
#debug ===== break/continue =====
#debug 012
#debug ===== compound assign =====
#debug int(15)
#debug int(12)
#debug ===== ++ -- =====
#debug int(6)
#debug int(5)
#debug int(6)
#debug ===== string interpolation =====
#debug hello world
#debug ===== associative array =====
#debug array(3) {
#debug   ["a"]=>
#debug   int(1)
#debug   ["b"]=>
#debug   int(2)
#debug   ["c"]=>
#debug   int(3)
#debug }
#debug array(2) {
#debug   ["name"]=>
#debug   string(4) "test"
#debug   ["data"]=>
#debug   array(3) {
#debug     [0]=>
#debug     int(1)
#debug     [1]=>
#debug     int(2)
#debug     [2]=>
#debug     int(3)
#debug   }
#debug }
#debug ===== memory safety =====
#debug int(6)
#debug all done

class Main
{
    public function main(): void
    {
        echo "===== if/else =====\n";

        // 基本 if
        $score = 85;
        if ($score >= 60) {
            echo "pass\n";
        }

        // if/else
        $x = 10;
        if ($x > 20) {
            echo "big\n";
        } else {
            echo "small\n";
        }

        // if/elseif/else
        $y = 75;
        if ($y >= 90) {
            echo "A\n";
        } elseif ($y >= 60) {
            echo "B\n";
        } else {
            echo "C\n";
        }

        // 嵌套 if
        $a = 5;
        $b = 10;
        if ($a > 0) {
            if ($b > 5) {
                echo "nested: both true\n";
            }
        }

        // 条件比较
        $v = 42;
        if ($v == 42 && $v > 0) {
            echo "correct\n";
        }

        // ===== while =====
        echo "===== while =====\n";

        $i = 0;
        while ($i < 3) {
            echo $i;
            $i = $i + 1;
        }
        echo "\n";

        // while 字符串比较
        $w = 5;
        while ($w > 0) {
            $w = $w - 1;
        }
        var_dump($w);

        // ===== for =====
        echo "===== for =====\n";

        for ($j = 0; $j < 3; $j = $j + 1) {
            echo $j;
        }
        echo "\n";

        // 递减 for
        for ($k = 3; $k > 0; $k = $k - 1) {
            echo $k;
        }
        echo "\n";

        // ===== foreach =====
        echo "===== foreach =====\n";

        $arr = [10, 20, 30];
        foreach ($arr as $v) {
            var_dump($v);
        }

        // foreach $key => $val
        foreach ($arr as $key => $val) {
            echo $key;
            echo ":";
            echo $val;
            echo "\n";
        }

        // ===== else if (分开写) =====
        echo "===== else if =====\n";

        $z = 42;
        if ($z > 100) {
            echo "big\n";
        } else if ($z > 50) {
            echo "mid\n";
        } else if ($z > 0) {
            echo "small\n";
        } else {
            echo "zero\n";
        }

        // ===== break/continue =====
        echo "===== break/continue =====\n";

        for ($m = 0; $m < 10; $m = $m + 1) {
            if ($m == 3) {
                break;
            }
            echo $m;
        }
        echo "\n";

        // ===== 复合赋值 =====
        echo "===== compound assign =====\n";

        $c = 10;
        $c += 5;
        var_dump($c);

        $c -= 3;
        var_dump($c);

        // ===== 自增自减 =====
        echo "===== ++ -- =====\n";

        $n = 5;
        $n++;
        var_dump($n);

        $n--;
        var_dump($n);

        // 前缀 ++
        ++$n;
        var_dump($n);

        // ===== 字符串插值 =====
        echo "===== string interpolation =====\n";

        $name = "world";
        echo "hello $name\n";

        // ===== 显式数组键 =====
        echo "===== associative array =====\n";

        $map = ["a" => 1, "b" => 2, "c" => 3];
        var_dump($map);

        // 嵌套 + 显式键
        $cfg = ["name" => "test", "data" => [1, 2, 3]];
        var_dump($cfg);

        // ===== 内存安全验证 =====
        echo "===== memory safety =====\n";
        var_dump($n);
        echo "all done\n";
    }
}
