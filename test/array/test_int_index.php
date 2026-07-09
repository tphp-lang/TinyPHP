<?php
// P3-2: 整数键数组可选哈希索引验证
// 三条查找路径：
//   1. 连续整数键 (0,1,2,...,n-1) → 直接下标 O(1)
//   2. 稀疏整数键 + 大数组 (≥8) → 哈希索引 O(1)
//   3. 小数组 (<8) → 线性扫描 O(n)
// 验证：get/set 正确性、索引失效（pop/shift/sort）后重建、混合键

#debug === Int Key Hash Index (P3-2) ===
#debug
#debug 1. contiguous-get: v0=10 v9=19
#debug 2. contiguous-set: v0=100 v5=55
#debug 3. small-sparse-get: k100=X k500=Y
#debug 4. large-sparse-build: cnt=12
#debug 5. large-sparse-get: id100=V100 id2000=V2000 id777=MISS
#debug 6. large-sparse-set-overwrite: id100=NEW id2000=V2000
#debug 7. large-sparse-set-new: cnt=13 id9999=Z
#debug 8. pop-invalidates: v9=gone cnt=9
#debug 9. shift-invalidates: k1=11 cnt=8
#debug 10. sort-invalidates: first=0 second=10
#debug 11. mixed-keys: name=Alice id42=DATA
#debug
#debug === All passed ===

class Main {
    public function main(): void {
        echo "=== Int Key Hash Index (P3-2) ===\n\n";

        // 1. 连续整数键 (0..9) → 快路径 1: 直接下标
        $contiguous = [];
        for (int $i = 0; $i < 10; $i++) {
            $contiguous[$i] = $i + 10;
        }
        echo "1. contiguous-get: v0=" . $contiguous[0] . " v9=" . $contiguous[9] . "\n";

        // 2. 连续键 set 覆盖（快路径 1）
        $contiguous[0] = 100;
        $contiguous[5] = 55;
        echo "2. contiguous-set: v0=" . $contiguous[0] . " v5=" . $contiguous[5] . "\n";

        // 3. 小数组稀疏键 (<8) → 线性扫描
        $small = [100 => "X", 500 => "Y", 3 => "Z"];
        echo "3. small-sparse-get: k100=" . $small[100] . " k500=" . $small[500] . "\n";

        // 4. 大数组稀疏键 (≥8) → 触发哈希索引构建
        $big = [];
        $ids = [100, 200, 300, 500, 800, 1000, 1500, 2000, 2500, 3000, 4000, 5000];
        foreach ($ids as $id) {
            $big[$id] = "V" . $id;
        }
        echo "4. large-sparse-build: cnt=" . count($big) . "\n";

        // 5. 大数组 get（哈希索引查找）— 777 不在列表中 → MISS
        $v777 = array_key_exists(777, $big) ? $big[777] : "MISS";
        echo "5. large-sparse-get: id100=" . $big[100]
           . " id2000=" . $big[2000]
           . " id777=" . $v777 . "\n";

        // 6. 大数组 set 覆盖已存在键（哈希索引命中）
        $big[100] = "NEW";
        echo "6. large-sparse-set-overwrite: id100=" . $big[100]
           . " id2000=" . $big[2000] . "\n";

        // 7. 大数组 set 新键（哈希索引插入）
        $big[9999] = "Z";
        echo "7. large-sparse-set-new: cnt=" . count($big)
           . " id9999=" . $big[9999] . "\n";

        // 8. pop 使索引失效 — 弹出最后一个 entry
        $contiguous2 = [];
        for (int $i = 0; $i < 10; $i++) {
            $contiguous2[$i] = $i + 10;
        }
        array_pop($contiguous2);
        $v9 = array_key_exists(9, $contiguous2) ? (string)$contiguous2[9] : "gone";
        echo "8. pop-invalidates: v9=" . $v9
           . " cnt=" . count($contiguous2) . "\n";

        // 9. shift 使索引失效 — 移除首元素并左移
        //    TinyPHP shift 不 re-key：shift 后 entries[0] 原是 key=1
        //    访问 key=1 → 哈希索引重建后命中 entries[0]
        $shiftArr = [];
        for (int $i = 0; $i < 9; $i++) {
            $shiftArr[$i] = $i + 10;
        }
        array_shift($shiftArr);
        echo "9. shift-invalidates: k1=" . $shiftArr[1]
           . " cnt=" . count($shiftArr) . "\n";

        // 10. sort 使索引失效 — 位置全变 + re-key 为 0..n-1
        $sortArr = [5 => 50, 3 => 30, 8 => 80, 1 => 10, 6 => 60,
                    2 => 20, 9 => 90, 4 => 40, 7 => 70, 0 => 0];
        sort($sortArr);
        echo "10. sort-invalidates: first=" . $sortArr[0]
           . " second=" . $sortArr[1] . "\n";

        // 11. 混合 int/string 键 — 两套索引共存
        $mixed = ["name" => "Alice", 42 => "DATA", "age" => 30, 100 => "X"];
        echo "11. mixed-keys: name=" . $mixed["name"]
           . " id42=" . $mixed[42] . "\n";

        echo "\n=== All passed ===\n";
    }
}
