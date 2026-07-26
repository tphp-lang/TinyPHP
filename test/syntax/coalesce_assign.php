<?php
// ??= (null 合并赋值) 测试 — Task 4.5
//   场景:
//     - 数组键不存在时设置默认值
//     - 数组键存在时保留原值
//     - 整数键 ??=
//     - mixed (t_var) 通过函数返回获取，??= 赋值
#debug ===== 1. string key ??= =====
#debug array(3) {
#debug   ["a"]=>
#debug   string(5) "exist"
#debug   ["b"]=>
#debug   string(7) "default"
#debug   ["c"]=>
#debug   string(7) "default"
#debug }
#debug
#debug ===== 2. int key ??= =====
#debug array(3) {
#debug   [0]=>
#debug   int(100)
#debug   [1]=>
#debug   int(999)
#debug   [2]=>
#debug   int(999)
#debug }
#debug
#debug ===== 3. mixed ??= (null case) =====
#debug string(5) "hello"
#debug
#debug ===== 4. mixed ??= (non-null case) =====
#debug int(42)
#debug
#debug ===== All ??= tests passed =====

class Main {
    public function main(): void {
        // ── 1. 字符串键 ??= ──
        $arr = ["a" => "exist"];
        $arr["b"] ??= "default";
        $arr["a"] ??= "should_not_override";
        $arr["c"] ??= "default";
        echo "===== 1. string key ??= =====\n";
        var_dump($arr);
        echo "\n";

        // ── 2. 整数键 ??= ──
        $nums = [0 => 100];
        $nums[1] ??= 999;
        $nums[0] ??= 0;
        $nums[2] ??= 999;
        echo "===== 2. int key ??= =====\n";
        var_dump($nums);
        echo "\n";

        // ── 3. mixed (t_var) 类型变量为 null 时赋值 ──
        $x = $this->getNull();
        $x ??= "hello";
        echo "===== 3. mixed ??= (null case) =====\n";
        var_dump($x);
        echo "\n";

        // ── 4. mixed (t_var) 类型变量非 null 时保留 ──
        $y = $this->getInt();
        $y ??= "should_not_override";
        echo "===== 4. mixed ??= (non-null case) =====\n";
        var_dump($y);
        echo "\n";

        echo "===== All ??= tests passed =====\n";
    }

    private function getNull(): mixed {
        return null;
    }

    private function getInt(): mixed {
        return 42;
    }
}
