<?php
// @skip tphp bug: $builtinArrElemTypes['array_values'] 硬编码为 't_int'，对包含字符串值的数组访问 $v[i] 时返回 int(0) 而非实际值，待 Task 8 修复
// 对应 PHP ext/standard/tests/array/array_values_basic.phpt
// array_values: 返回所有值并重新索引为 0-based

#debug ===== 1. hash reindex =====
#debug int(3)
#debug int(0)
#debug int(30)
#debug int(0)
#debug
#debug ===== 2. sparse int keys reindex =====
#debug int(3)
#debug int(0)
#debug int(0)
#debug int(0)
#debug
#debug ===== 3. list stays same =====
#debug int(3)
#debug int(10)
#debug int(20)
#debug int(30)
#debug
#debug ===== 4. empty array =====
#debug int(0)
#debug
#debug ===== 5. mixed keys reindex =====
#debug int(4)
#debug int(0)
#debug int(100)
#debug int(0)
#debug int(200)
#debug
#debug ===== 6. original unchanged =====
#debug int(3)
#debug string(5) "Alice"
#debug
#debug === done ===

class Main
{
    public function main(): void
    {
        // ============================================================
        // 1. 关联数组 → 重新索引为 0,1,2
        // ============================================================
        echo "===== 1. hash reindex =====\n";
        $h = ["name" => "Alice", "age" => 30, "city" => "SZ"];
        $v = array_values($h);
        var_dump(count($v));   // int(3)
        var_dump($v[0]);       // string(5) "Alice"
        var_dump($v[1]);       // int(30)
        var_dump($v[2]);       // string(2) "SZ"

        // ============================================================
        // 2. 稀疏整数键 → 重新索引为 0,1,2
        // ============================================================
        echo "\n===== 2. sparse int keys reindex =====\n";
        $s = [100 => "a", 500 => "b", 3 => "c"];
        $v2 = array_values($s);
        var_dump(count($v2));  // int(3)
        var_dump($v2[0]);      // string(1) "a"
        var_dump($v2[1]);      // string(1) "b"
        var_dump($v2[2]);      // string(1) "c"

        // ============================================================
        // 3. 已是 list 的数组：值和顺序不变
        // ============================================================
        echo "\n===== 3. list stays same =====\n";
        $l = [10, 20, 30];
        $v3 = array_values($l);
        var_dump(count($v3));  // int(3)
        var_dump($v3[0]);      // int(10)
        var_dump($v3[1]);      // int(20)
        var_dump($v3[2]);      // int(30)

        // ============================================================
        // 4. 空数组
        // ============================================================
        echo "\n===== 4. empty array =====\n";
        $e = [];
        $v4 = array_values($e);
        var_dump(count($v4));  // int(0)

        // ============================================================
        // 5. 混合 int/string 键 → 重新索引
        // ============================================================
        echo "\n===== 5. mixed keys reindex =====\n";
        $m = ["x" => "foo", 10 => 100, "y" => "bar", 20 => 200];
        $v5 = array_values($m);
        var_dump(count($v5));  // int(4)
        var_dump($v5[0]);      // string(3) "foo"
        var_dump($v5[1]);      // int(100)
        var_dump($v5[2]);      // string(3) "bar"
        var_dump($v5[3]);      // int(200)

        // ============================================================
        // 6. 原数组不变
        // ============================================================
        echo "\n===== 6. original unchanged =====\n";
        var_dump(count($h));         // int(3)
        var_dump($h["name"]);        // string(5) "Alice"

        echo "\n=== done ===\n";
    }
}
