<?php
// 对应 PHP ext/standard/tests/array/005.phpt (array_is_list basics)
// array_is_list: 检查数组是否为 0,1,2,...,n-1 的连续整数键列表

#debug ===== 1. basic list =====
#debug bool(true)
#debug bool(true)
#debug bool(true)
#debug
#debug ===== 2. string key => not list =====
#debug bool(false)
#debug bool(false)
#debug
#debug ===== 3. gapped int key => not list =====
#debug bool(false)
#debug bool(false)
#debug
#debug ===== 4. out-of-order int key => not list =====
#debug bool(false)
#debug
#debug ===== 5. empty array is list =====
#debug bool(true)
#debug
#debug ===== 6. single element is list =====
#debug bool(true)
#debug bool(false)
#debug
#debug ===== 7. starts at non-zero =====
#debug bool(false)
#debug
#debug === done ===

class Main
{
    public function main(): void
    {
        // ============================================================
        // 1. 基本列表 [0,1,2,...,n-1]
        // ============================================================
        echo "===== 1. basic list =====\n";
        var_dump(array_is_list([1, 2, 3]));        // bool(true)
        var_dump(array_is_list([10]));              // bool(true)
        var_dump(array_is_list(["a", "b", "c"]));   // bool(true)

        // ============================================================
        // 2. 字符串键 → 不是 list
        // ============================================================
        echo "\n===== 2. string key => not list =====\n";
        var_dump(array_is_list(["a" => 1, "b" => 2]));  // bool(false)
        var_dump(array_is_list([0 => "x", "k" => "y"]));  // bool(false)

        // ============================================================
        // 3. 间断整数键 → 不是 list
        // ============================================================
        echo "\n===== 3. gapped int key => not list =====\n";
        var_dump(array_is_list([0 => "a", 2 => "b"]));   // bool(false) — 缺 1
        var_dump(array_is_list([0 => "a", 1 => "b", 5 => "c"]));  // bool(false)

        // ============================================================
        // 4. 乱序整数键 → 不是 list
        //    PHP: [1=>1, 0=>2] 不是 list（虽然键集合为 {0,1}，但顺序错）
        // ============================================================
        echo "\n===== 4. out-of-order int key => not list =====\n";
        var_dump(array_is_list([1 => 1, 0 => 2]));   // bool(false)

        // ============================================================
        // 5. 空数组是 list
        // ============================================================
        echo "\n===== 5. empty array is list =====\n";
        var_dump(array_is_list([]));   // bool(true)

        // ============================================================
        // 6. 单元素
        // ============================================================
        echo "\n===== 6. single element is list =====\n";
        var_dump(array_is_list([0 => "only"]));   // bool(true)
        var_dump(array_is_list([1 => "only"]));   // bool(false) — 起始非 0

        // ============================================================
        // 7. 起始非 0 → 不是 list
        // ============================================================
        echo "\n===== 7. starts at non-zero =====\n";
        var_dump(array_is_list([5 => "a", 6 => "b"]));   // bool(false)

        echo "\n=== done ===\n";
    }
}
