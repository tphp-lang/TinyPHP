<?php
#debug ===== 1. array_push =====
#debug array(1) {
#debug   [0]=>
#debug   int(99)
#debug }
#debug int(1)
#debug array(3) {
#debug   [0]=>
#debug   int(1)
#debug   [1]=>
#debug   int(2)
#debug   [2]=>
#debug   int(3)
#debug }
#debug int(3)
#debug int(5)
#debug int(4)
#debug int(5)
#debug int(10)
#debug int(1)
#debug int(10)
#debug int(30)
#debug
#debug ===== 2. array_pop =====
#debug int(30)
#debug int(20)
#debug int(1)
#debug int(10)
#debug int(10)
#debug int(0)
#debug int(42)
#debug int(0)
#debug int(4)
#debug int(3)
#debug int(99)
#debug int(4)
#debug
#debug ===== 3. in_array =====
#debug bool(true)
#debug bool(false)
#debug bool(true)
#debug bool(true)
#debug bool(true)
#debug bool(false)
#debug int(3)
#debug
#debug ===== 4. array_key_exists =====
#debug bool(true)
#debug bool(true)
#debug bool(true)
#debug bool(false)
#debug bool(false)
#debug key 2 ok
#debug key 5 missing
#debug
#debug ===== 5. array_keys & array_values =====
#debug int(3)
#debug int(0)
#debug int(2)
#debug int(3)
#debug int(100)
#debug int(200)
#debug bool(true)
#debug bool(true)
#debug
#debug ===== 6. array_merge =====
#debug int(5)
#debug int(1)
#debug int(4)
#debug int(5)
#debug int(1)
#debug int(4)
#debug int(15)
#debug
#debug ===== 7. implode =====
#debug 1,2,3
#debug 10-20
#debug 1|2|3
#debug
#debug ===== 8. explode =====
#debug int(3)
#debug string(1) "a"
#debug string(1) "c"
#debug int(2)
#debug string(5) "hello"
#debug string(5) "world"
#debug x:y:z
#debug
#debug ===== 9. count =====
#debug int(0)
#debug int(1)
#debug int(3)
#debug int(2)
#debug int(3)
#debug
#debug ===== 10. combined =====
#debug int(3)
#debug int(3)
#debug int(2)
#debug int(99)
#debug int(2)
#debug int(4)
#debug int(150)
#debug
#debug ===== 11. nested int =====
#debug int(1)
#debug int(2)
#debug int(4)
#debug int(9)
#debug int(7)
#debug 6
#debug
#debug ===== 12. nested string =====
#debug string(5) "hello"
#debug string(5) "world"
#debug string(3) "bar"
#debug hello world
#debug
#debug ===== 13. nested float =====
#debug float(1.5)
#debug float(2.5)
#debug float(3.5)
#debug
#debug ===== 14. nested bool =====
#debug bool(true)
#debug bool(false)
#debug bool(true)
#debug flags[0][0]=true
#debug flags[1][0]=false
#debug
#debug ===== 15. type propagation =====
#debug int(10)
#debug int(20)
#debug int(30)
#debug int(40)
#debug int(100)
#debug alpha/beta
#debug
#debug ===== 16. foreach nested =====
#debug int(21)
#debug
#debug ===== 17. is_array checks =====
#debug bool(true)
#debug bool(false)
#debug bool(false)
#debug arr17 is array
#debug int17 is not array
#debug
#debug ===== 18. while + array =====
#debug int(60)
#debug
#debug === ALL array tests done ===

class Main
{
    public function main(): void
    {
        // ============================================================
        // 1. array_push — 基础 + 边界
        // ============================================================
        echo "===== 1. array_push =====\n";

        // 空数组追加
        $empty = [];
        $n0 = array_push($empty, 99);
        var_dump($empty);   // array(1) { 0=>int(99) }
        var_dump($n0);      // int(1)

        // 已有元素追加
        $a = [1, 2];
        $n1 = array_push($a, 3);
        var_dump($a);       // array(3)
        var_dump($n1);      // int(3)

        // 连续追加
        array_push($a, 4);
        array_push($a, 5);
        var_dump(count($a)); // int(5)
        var_dump($a[3]);     // int(4)
        var_dump($a[4]);     // int(5)

        // 大数组追加
        $big = [];
        array_push($big, 1);
        array_push($big, 2);
        array_push($big, 3);
        array_push($big, 4);
        array_push($big, 5);
        array_push($big, 6);
        array_push($big, 7);
        array_push($big, 8);
        array_push($big, 9);
        array_push($big, 10);
        var_dump(count($big));  // int(10)
        var_dump($big[0]);      // int(1)
        var_dump($big[9]);      // int(10)

        // push 后读值
        $r = [10, 20];
        array_push($r, 30);
        var_dump($r[2]);        // int(30)

        // ============================================================
        // 2. array_pop — 基础 + 边界
        // ============================================================
        echo "\n===== 2. array_pop =====\n";

        // 正常弹出
        $a2 = [10, 20, 30];
        var_dump(array_pop($a2));  // int(30)
        var_dump(array_pop($a2));  // int(20)
        var_dump(count($a2));      // int(1)
        var_dump($a2[0]);          // int(10)
        var_dump(array_pop($a2));  // int(10)
        var_dump(count($a2));      // int(0)

        // 单元素弹出
        $s = [42];
        var_dump(array_pop($s));   // int(42)
        var_dump(count($s));       // int(0)

        // 连续 push-pop
        $q = [1, 2, 3];
        array_push($q, 4);
        var_dump(array_pop($q));   // int(4)
        var_dump(count($q));       // int(3)
        array_push($q, 99);
        var_dump($q[3]);           // int(99)
        var_dump(count($q));       // int(4)

        // ============================================================
        // 3. in_array — 值查找
        // ============================================================
        echo "\n===== 3. in_array =====\n";

        $a3 = [10, 20, 30, 40];
        var_dump(in_array(20, $a3));   // bool(true)
        var_dump(in_array(50, $a3));   // bool(false)
        var_dump(in_array(10, $a3));   // bool(true)
        var_dump(in_array(40, $a3));   // bool(true)

        // 单元素数组
        $a3b = [7];
        var_dump(in_array(7, $a3b));   // bool(true)
        var_dump(in_array(8, $a3b));   // bool(false)

        // 条件判断中使用
        $found = 0;
        if (in_array(30, $a3)) {
            $found = $found + 1;
        }
        if (!in_array(99, $a3)) {
            $found = $found + 1;
        }
        if (!in_array(0, $a3)) {
            $found = $found + 1;
        }
        var_dump($found);  // int(3)

        // ============================================================
        // 4. array_key_exists — 键查找
        // ============================================================
        echo "\n===== 4. array_key_exists =====\n";

        $a4 = [10, 20, 30];
        var_dump(array_key_exists(0, $a4));  // bool(true)
        var_dump(array_key_exists(1, $a4));  // bool(true)
        var_dump(array_key_exists(2, $a4));  // bool(true)
        var_dump(array_key_exists(3, $a4));  // bool(false)
        var_dump(array_key_exists(99, $a4)); // bool(false)

        // 条件 + key_exists 组合
        if (array_key_exists(2, $a4)) {
            echo "key 2 ok\n";
        }
        if (!array_key_exists(5, $a4)) {
            echo "key 5 missing\n";
        }

        // ============================================================
        // 5. array_keys / array_values
        // ============================================================
        echo "\n===== 5. array_keys & array_values =====\n";

        // array_keys
        $a5 = [100, 200, 300];
        $keys = array_keys($a5);
        var_dump(count($keys));  // int(3)
        var_dump($keys[0]);      // int(0)
        var_dump($keys[2]);      // int(2)

        // array_values
        $vals = array_values($a5);
        var_dump(count($vals));  // int(3)
        var_dump($vals[0]);      // int(100)
        var_dump($vals[1]);      // int(200)

        // keys + values 同长度
        var_dump(count(array_keys($a5)) == count($a5));   // bool(true)
        var_dump(count(array_values($a5)) == count($a5)); // bool(true)

        // ============================================================
        // 6. array_merge — 数组合并
        // ============================================================
        echo "\n===== 6. array_merge =====\n";

        $a6a = [1, 2, 3];
        $a6b = [4, 5];
        $m = array_merge($a6a, $a6b);
        var_dump(count($m));   // int(5)
        var_dump($m[0]);       // int(1)
        var_dump($m[3]);       // int(4)
        var_dump($m[4]);       // int(5)

        // 原数组不变
        var_dump($a6a[0]);     // int(1)
        var_dump($a6b[0]);     // int(4)

        // 合并后求和
        $s6 = $m[0] + $m[1] + $m[2] + $m[3] + $m[4];
        var_dump($s6);  // int(15)

        // ============================================================
        // 7. implode — 连接为字符串
        // ============================================================
        echo "\n===== 7. implode =====\n";

        $a7 = [1, 2, 3];
        echo implode(",", $a7) . "\n";       // 1,2,3
        echo implode("-", [10, 20]) . "\n";  // 10-20

        // 嵌套使用
        echo implode("|", $a6a) . "\n";      // 1|2|3

        // ============================================================
        // 8. explode — 切分为数组
        // ============================================================
        echo "\n===== 8. explode =====\n";

        $parts = explode(",", "a,b,c");
        var_dump(count($parts));  // int(3)
        var_dump($parts[0]);      // string(1) "a"
        var_dump($parts[2]);      // string(1) "c"

        $parts2 = explode(" ", "hello world");
        var_dump(count($parts2)); // int(2)
        var_dump($parts2[0]);     // string(5) "hello"
        var_dump($parts2[1]);     // string(5) "world"

        // round-trip
        $rt = implode(":", explode(",", "x,y,z"));
        echo $rt . "\n";  // x:y:z

        // ============================================================
        // 9. count — 计数
        // ============================================================
        echo "\n===== 9. count =====\n";

        var_dump(count([]));         // int(0)
        var_dump(count([42]));       // int(1)
        var_dump(count([1, 2, 3])); // int(3)

        // count + push
        $c = [10, 20];
        var_dump(count($c));         // int(2)
        array_push($c, 30);
        var_dump(count($c));         // int(3)

        // ============================================================
        // 10. 组合操作
        // ============================================================
        echo "\n===== 10. combined =====\n";

        // push-pop 栈模式
        $stack = [];
        array_push($stack, 1);
        array_push($stack, 2);
        array_push($stack, 3);
        var_dump(count($stack));        // int(3)
        var_dump(array_pop($stack));    // int(3)
        var_dump(array_pop($stack));    // int(2)
        array_push($stack, 99);
        var_dump($stack[1]);            // int(99)
        var_dump(count($stack));        // int(2)

        // in_array 守护式 push
        $items = [1, 2, 3];
        if (!in_array(4, $items)) {
            array_push($items, 4);
        }
        if (in_array(2, $items)) {
            // 2 已存在，不 push
        }
        var_dump(count($items));  // int(4)

        // key_exists 守护式访问
        $map = [10, 20, 30, 40, 50];
        $acc = 0;
        if (array_key_exists(0, $map)) { $acc = $acc + $map[0]; }
        if (array_key_exists(3, $map)) { $acc = $acc + $map[3]; }
        if (!array_key_exists(99, $map)) { $acc = $acc + 100; }
        var_dump($acc);  // int(150) = 10+40+100

        // ============================================================
        // 11. 嵌套 int 数组
        // ============================================================
        echo "\n===== 11. nested int =====\n";

        $grid = [[1, 2, 3], [4, 5, 6], [7, 8, 9]];
        var_dump($grid[0][0]);  // int(1)
        var_dump($grid[0][1]);  // int(2)
        var_dump($grid[1][0]);  // int(4)
        var_dump($grid[2][2]);  // int(9)

        // 链式 + 运算
        $gsum = $grid[0][0] + $grid[0][1] + $grid[1][0];
        var_dump($gsum);  // int(7) = 1+2+4

        echo $grid[1][2] . "\n";  // 6

        // ============================================================
        // 12. 嵌套字符串数组
        // ============================================================
        echo "\n===== 12. nested string =====\n";

        $tags = [["hello", "world"], ["foo", "bar"]];
        var_dump($tags[0][0]);  // string(5) "hello"
        var_dump($tags[0][1]);  // string(5) "world"
        var_dump($tags[1][1]);  // string(3) "bar"

        echo $tags[0][0] . " " . $tags[0][1] . "\n";  // hello world

        // ============================================================
        // 13. 嵌套浮点数数组
        // ============================================================
        echo "\n===== 13. nested float =====\n";

        $mat = [[1.5, 2.5], [3.5, 4.5]];
        var_dump($mat[0][0]);  // float(1.5)
        var_dump($mat[0][1]);  // float(2.5)
        var_dump($mat[1][0]);  // float(3.5)

        // ============================================================
        // 14. 嵌套布尔数组
        // ============================================================
        echo "\n===== 14. nested bool =====\n";

        $flags = [[true, false], [false, true]];
        var_dump($flags[0][0]);  // bool(true)
        var_dump($flags[0][1]);  // bool(false)
        var_dump($flags[1][1]);  // bool(true)

        if ($flags[0][0]) {
            echo "flags[0][0]=true\n";
        }
        if (!$flags[1][0]) {
            echo "flags[1][0]=false\n";
        }

        // ============================================================
        // 15. 子数组类型传播
        // ============================================================
        echo "\n===== 15. type propagation =====\n";

        $data = [[10, 20], [30, 40]];
        $row0 = $data[0];
        $row1 = $data[1];
        var_dump($row0[0]);  // int(10)
        var_dump($row0[1]);  // int(20)
        var_dump($row1[0]);  // int(30)
        var_dump($row1[1]);  // int(40)

        $tsum = $row0[0] + $row0[1] + $row1[0] + $row1[1];
        var_dump($tsum);  // int(100)

        // 字符串传播
        $lbl = [["alpha", "beta"], ["gamma", "delta"]];
        $r = $lbl[0];
        echo $r[0] . "/" . $r[1] . "\n";  // alpha/beta

        // ============================================================
        // 16. foreach 遍历嵌套数组
        // ============================================================
        echo "\n===== 16. foreach nested =====\n";

        $table = [[1, 2], [3, 4], [5, 6]];
        $ttl = 0;
        foreach ($table as $pair) {
            $ttl = $ttl + $pair[0] + $pair[1];
        }
        var_dump($ttl);  // int(21) = 3+7+11

        // ============================================================
        // 17. is_array 类型检测
        // ============================================================
        echo "\n===== 17. is_array checks =====\n";

        $arr17 = [1, 2, 3];
        $int17 = 42;
        $str17 = "hello";

        var_dump(is_array($arr17));   // bool(true)
        var_dump(is_array($int17));   // bool(false)
        var_dump(is_array($str17));   // bool(false)

        if (is_array($arr17)) {
            echo "arr17 is array\n";
        }
        if (!is_array($int17)) {
            echo "int17 is not array\n";
        }

        // ============================================================
        // 18. while 循环 + 数组
        // ============================================================
        echo "\n===== 18. while + array =====\n";

        $buf = [10, 20, 30];
        $i = 0;
        $wsum = 0;
        while ($i < count($buf)) {
            $wsum = $wsum + $buf[$i];
            $i = $i + 1;
        }
        var_dump($wsum);  // int(60)

        echo "\n=== ALL array tests done ===\n";
    }
}
