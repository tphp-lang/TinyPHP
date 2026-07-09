<?php
// P3-1: array_diff/array_intersect 哈希集优化验证
// 小数组 (<16) 走双循环 O(n×m)，大数组 (≥16) 走哈希集 O(n+m)
// 验证两条路径结果一致，且类型分离正确（INT 1 ≠ STRING "1"）

#debug === array_diff/intersect Hash Optimization (P3-1) ===
#debug
#debug 1. small-diff: cnt=2
#debug 2. large-diff: cnt=5
#debug 3. small-intersect: cnt=2
#debug 4. large-intersect: cnt=15
#debug 5. type-separation: cnt=3
#debug 6. string-large-diff: cnt=5
#debug
#debug === All passed ===

class Main {
    public function main(): void {
        echo "=== array_diff/intersect Hash Optimization (P3-1) ===\n\n";

        // 1. 小数组 diff (<16)：走双循环路径
        $a = [1, 2, 3, 4, 5];
        $b = [3, 4, 5];
        $d1 = array_diff($a, $b);
        echo "1. small-diff: cnt=" . count($d1) . "\n";

        // 2. 大数组 diff (≥16)：走哈希集路径
        $big1 = [];
        $big2 = [];
        for ($i = 0; $i < 20; $i++) {
            $big1[] = $i;        // 0..19
            if ($i < 15) $big2[] = $i;  // 0..14
        }
        $d2 = array_diff($big1, $big2);  // 应剩 15,16,17,18,19
        echo "2. large-diff: cnt=" . count($d2) . "\n";

        // 3. 小数组 intersect
        $i1 = array_intersect($a, $b);  // 3,4,5
        echo "3. small-intersect: cnt=" . count($i1) . "\n";

        // 4. 大数组 intersect
        $i2 = array_intersect($big1, $big2);  // 0..14 = 15 个
        echo "4. large-intersect: cnt=" . count($i2) . "\n";

        // 5. 类型分离：INT 1 与 STRING "1" 不应匹配
        $mixed1 = [1, 2, 3];
        $mixed2 = ["1", "2"];
        $d5 = array_diff($mixed1, $mixed2);  // INT 1,2,3 均不在 STRING set 中 → 全保留
        echo "5. type-separation: cnt=" . count($d5) . "\n";

        // 6. 大字符串数组 diff (≥16)：走哈希集路径
        $strBig1 = [];
        $strBig2 = [];
        for ($i = 0; $i < 20; $i++) {
            $strBig1[] = "item" . $i;
            if ($i < 15) $strBig2[] = "item" . $i;
        }
        $d6 = array_diff($strBig1, $strBig2);  // 应剩 item15..item19
        echo "6. string-large-diff: cnt=" . count($d6) . "\n";

        echo "\n=== All passed ===\n";
    }
}
