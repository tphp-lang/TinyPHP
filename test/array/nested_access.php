<?php
// 对应 PHP ext/standard/tests/array/007.phpt (nested array access)
// 嵌套数组深度访问：$arr[0][1][2]

#debug ===== 1. 3-level int nested access =====
#debug int(6)
#debug int(9)
#debug int(11)
#debug
#debug ===== 2. string-keyed nested =====
#debug string(5) "hello"
#debug string(5) "world"
#debug
#debug ===== 3. mixed int/str keys nested =====
#debug int(42)
#debug string(3) "bar"
#debug
#debug ===== 4. 4-level deep =====
#debug int(1000)
#debug
#debug ===== 5. foreach over nested =====
#debug row0 sum=3
#debug row1 sum=7
#debug row2 sum=11
#debug
#debug ===== 6. nested arithmetic =====
#debug int(21)
#debug
#debug === done ===

class Main
{
    public function main(): void
    {
        // ============================================================
        // 1. 三层嵌套整数数组 $arr[0][1][2]
        // ============================================================
        echo "===== 1. 3-level int nested access =====\n";
        $a = [
            [[1, 2, 3], [4, 5, 6], [7, 8, 9]],
            [[10, 11, 12], [13, 14, 15], [16, 17, 18]],
            [[19, 20, 21], [22, 23, 24], [25, 26, 27]],
        ];
        var_dump($a[0][1][2]);   // int(6)
        var_dump($a[0][2][2]);   // int(9)
        var_dump($a[1][0][1]);   // int(11)

        // ============================================================
        // 2. 字符串键嵌套
        //    注意：tphp 不支持 $a[0][1][2] = val 形式的嵌套赋值（C 编译报 lvalue expected），
        //          故此处仅测试嵌套读取
        // ============================================================
        echo "\n===== 2. string-keyed nested =====\n";
        $h = [
            "greeting" => ["hello", "world"],
            "data" => ["x" => [1, 2, 3]],
        ];
        var_dump($h["greeting"][0]);   // string(5) "hello"
        var_dump($h["greeting"][1]);   // string(5) "world"

        // ============================================================
        // 3. 混合 int/str 键嵌套
        // ============================================================
        echo "\n===== 3. mixed int/str keys nested =====\n";
        $m = [
            "items" => [
                ["id" => 42, "name" => "foo"],
                ["id" => 99, "name" => "bar"],
            ],
        ];
        var_dump($m["items"][0]["id"]);      // int(42)
        var_dump($m["items"][1]["name"]);    // string(3) "bar"

        // ============================================================
        // 4. 四层深度访问
        // ============================================================
        echo "\n===== 4. 4-level deep =====\n";
        $deep = [
            [
                [
                    [1, 2, 1000],
                ],
            ],
        ];
        var_dump($deep[0][0][0][2]);   // int(1000)

        // ============================================================
        // 5. 遍历嵌套数组
        // ============================================================
        echo "\n===== 5. foreach over nested =====\n";
        $grid = [
            [1, 2],
            [3, 4],
            [5, 6],
        ];
        $i = 0;
        foreach ($grid as $row) {
            $sum = $row[0] + $row[1];
            echo "row" . $i . " sum=" . $sum . "\n";
            $i = $i + 1;
        }

        // ============================================================
        // 6. 嵌套数组参与运算
        // ============================================================
        echo "\n===== 6. nested arithmetic =====\n";
        $nums = [[1, 2, 3], [4, 5, 6]];
        $total = $nums[0][0] + $nums[0][1] + $nums[0][2]
               + $nums[1][0] + $nums[1][1] + $nums[1][2];
        var_dump($total);   // int(21)

        echo "\n=== done ===\n";
    }
}
