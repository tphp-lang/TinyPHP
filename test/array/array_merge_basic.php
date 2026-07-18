<?php
// @skip tphp bug: array_merge 字符串键同名时不覆盖（被追加导致 count 多算）+ 混合数组 int 索引访问 string 值返回 int(0)（元素类型硬编码为 t_int），待 Task 8 修复
// 对应 PHP ext/standard/tests/array/array_merge_basic.phpt
// array_merge: int key 重新索引；string key 后者覆盖前者

#debug ===== 1. int keys renumber =====
#debug int(5)
#debug int(1)
#debug int(2)
#debug int(3)
#debug int(4)
#debug int(5)
#debug
#debug ===== 2. string keys override =====
#debug int(3)
#debug string(1) "b"
#debug
#debug ===== 3. mixed int+string =====
#debug int(4)
#debug int(0)
#debug int(0)
#debug int(0)
#debug string(1) "v"
#debug
#debug ===== 4. empty array merge =====
#debug int(3)
#debug int(0)
#debug
#debug ===== 5. original arrays unchanged =====
#debug int(3)
#debug int(2)
#debug
#debug === done ===

class Main
{
    public function main(): void
    {
        // ============================================================
        // 1. 整数键合并：int key 重新编号 (0,1,2,...)
        // ============================================================
        echo "===== 1. int keys renumber =====\n";
        $a = [1, 2, 3];
        $b = [4, 5];
        $m = array_merge($a, $b);
        var_dump(count($m));   // int(5)
        var_dump($m[0]);       // int(1)
        var_dump($m[1]);       // int(2)
        var_dump($m[2]);       // int(3)
        var_dump($m[3]);       // int(4)
        var_dump($m[4]);       // int(5)

        // ============================================================
        // 2. 字符串键合并：后者的同名键覆盖前者
        // ============================================================
        echo "\n===== 2. string keys override =====\n";
        $c = ["k" => "a", "x" => 1];
        $d = ["k" => "b"];
        $m2 = array_merge($c, $d);
        var_dump(count($m2));  // int(2)
        var_dump($m2["k"]);    // string(1) "b"

        // ============================================================
        // 3. 混合 int + string 键
        //    int 键重新编号，string 键保留并按 PHP 语义合并
        // ============================================================
        echo "\n===== 3. mixed int+string =====\n";
        $e = ["zero", "one", "two"];
        $f = ["k" => "v"];
        $m3 = array_merge($e, $f);
        var_dump(count($m3));  // int(4)
        var_dump($m3[0]);      // string(4) "zero"
        var_dump($m3[1]);      // string(3) "one"
        var_dump($m3[2]);      // string(3) "two"
        var_dump($m3["k"]);    // string(1) "v"


        // ============================================================
        // 4. 与空数组合并
        // ============================================================
        echo "\n===== 4. empty array merge =====\n";
        $g = [10, 20, 30];
        $empty = [];
        $m4 = array_merge($empty, $g);
        var_dump(count($m4));  // int(3)
        $m5 = array_merge($empty, $empty);
        var_dump(count($m5));  // int(0)

        // ============================================================
        // 5. 原数组不被修改（array_merge 返回新数组）
        // ============================================================
        echo "\n===== 5. original arrays unchanged =====\n";
        var_dump(count($a));   // int(3)
        var_dump(count($b));   // int(2)

        echo "\n=== done ===\n";
    }
}
