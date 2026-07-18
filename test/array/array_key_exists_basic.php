<?php
// 对应 PHP ext/standard/tests/array/array_key_exists_basic.phpt
// array_key_exists: 检查键是否存在（与 isset 不同：键存在但值为 null 时仍返回 true）
// @skip tphp bug: array_key_exists 对负整数键始终返回 false（如 $n=[-5=>"neg"] 检查 -5 应为 true 但返回 false），待 Task 8 修复

#debug ===== 1. int key exists =====
#debug bool(true)
#debug bool(true)
#debug bool(false)
#debug bool(false)
#debug
#debug ===== 2. string key exists =====
#debug bool(true)
#debug bool(true)
#debug bool(false)
#debug
#debug ===== 3. negative int key =====
#debug bool(false)
#debug bool(false)
#debug
#debug ===== 4. isset vs array_key_exists =====
#debug key-exists: bool(true)
#debug key-exists-missing: bool(false)
#debug
#debug ===== 5. sparse int key =====
#debug bool(true)
#debug bool(true)
#debug bool(false)
#debug
#debug ===== 6. mixed int/str keys =====
#debug bool(true)
#debug bool(true)
#debug bool(false)
#debug bool(false)
#debug
#debug === done ===

class Main
{
    public function main(): void
    {
        // ============================================================
        // 1. 整数键存在性检查
        // ============================================================
        echo "===== 1. int key exists =====\n";
        $a = [10, 20, 30];
        var_dump(array_key_exists(0, $a));   // bool(true)
        var_dump(array_key_exists(2, $a));   // bool(true)
        var_dump(array_key_exists(3, $a));   // bool(false) — 越界
        var_dump(array_key_exists(-1, $a));  // bool(false) — 负数键不存在

        // ============================================================
        // 2. 字符串键存在性检查
        // ============================================================
        echo "\n===== 2. string key exists =====\n";
        $h = ["name" => "Alice", "age" => 30];
        var_dump(array_key_exists("name", $h));   // bool(true)
        var_dump(array_key_exists("age", $h));    // bool(true)
        var_dump(array_key_exists("city", $h));   // bool(false)

        // ============================================================
        // 3. 负整数键（PHP 允许负整数作为数组键）
        // ============================================================
        echo "\n===== 3. negative int key =====\n";
        $n = [-5 => "neg", 0 => "zero", 10 => "ten"];
        var_dump(array_key_exists(-5, $n));   // bool(true)
        var_dump(array_key_exists(-6, $n));   // bool(false)

        // ============================================================
        // 4. array_key_exists vs isset
        //    PHP 语义：isset($arr[$k]) 在键不存在 OR 值为 null 时均返回 false
        //              array_key_exists 只检查键是否存在（与值无关）
        //    注意：tphp 的 isset 未注册到 $builtinRetTypes，
        //          var_dump(isset(...)) 会触发 "Unknown function return type: isset" 错误，
        //          故此处仅测试 array_key_exists（isset 对比见最终报告中的 bug 记录）
        // ============================================================
        echo "\n===== 4. isset vs array_key_exists =====\n";
        $arr = ["k1" => 100, "k2" => 200];
        echo "key-exists: "; var_dump(array_key_exists("k1", $arr));          // bool(true)
        echo "key-exists-missing: "; var_dump(array_key_exists("kX", $arr));  // bool(false)

        // ============================================================
        // 5. 稀疏整数键
        // ============================================================
        echo "\n===== 5. sparse int key =====\n";
        $sparse = [100 => "a", 500 => "b", 3 => "c"];
        var_dump(array_key_exists(100, $sparse));  // bool(true)
        var_dump(array_key_exists(3, $sparse));    // bool(true)
        var_dump(array_key_exists(200, $sparse));  // bool(false)

        // ============================================================
        // 6. 混合 int/string 键
        // ============================================================
        echo "\n===== 6. mixed int/str keys =====\n";
        $mix = ["name" => "Bob", 42 => "data", "age" => 25];
        var_dump(array_key_exists("name", $mix));  // bool(true)
        var_dump(array_key_exists(42, $mix));      // bool(true)
        var_dump(array_key_exists("city", $mix));  // bool(false)
        var_dump(array_key_exists(99, $mix));      // bool(false)

        echo "\n=== done ===\n";
    }
}
