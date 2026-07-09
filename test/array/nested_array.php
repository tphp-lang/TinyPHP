<?php
#debug ===== 1. nested int =====
#debug int(1)
#debug int(2)
#debug int(4)
#debug int(9)
#debug 1
#debug 6
#debug ===== 2. sub-array var =====
#debug int(10)
#debug int(20)
#debug int(30)
#debug int(40)
#debug int(100)
#debug ===== 3. nested string =====
#debug string(5) "hello"
#debug string(5) "world"
#debug string(3) "foo"
#debug hello world
#debug foo-bar
#debug ===== 4. nested float =====
#debug float(1.1)
#debug float(2.2)
#debug float(3.3)
#debug ===== 5. nested bool =====
#debug bool(true)
#debug bool(false)
#debug bool(true)
#debug flags[0][0] is true
#debug ===== 6. type propagation =====
#debug int(100)
#debug int(200)
#debug int(300)
#debug int(400)
#debug int(1000)
#debug alpha/beta
#debug ===== 7. foreach nested =====
#debug int(210)
#debug
#debug === all nested array tests done ===

class Main
{
    public function main(): void
    {
        // ============================================================
        // 1. 嵌套 int 数组：$arr[0][0] 链式访问
        // ============================================================
        echo "===== 1. nested int =====\n";

        $a = [[1, 2, 3], [4, 5, 6], [7, 8, 9]];
        var_dump($a[0][0]);   // int(1)
        var_dump($a[0][1]);   // int(2)
        var_dump($a[1][0]);   // int(4)
        var_dump($a[2][2]);   // int(9)

        echo $a[0][0] . "\n";       // 1
        echo $a[1][2] . "\n";       // 6

        // ============================================================
        // 2. 子数组变量 $sub = $arr[0]; $sub[0]
        // ============================================================
        echo "===== 2. sub-array var =====\n";

        $b = [[10, 20], [30, 40]];
        $sub = $b[0];
        var_dump($sub[0]);   // int(10)
        var_dump($sub[1]);   // int(20)

        $sub2 = $b[1];
        var_dump($sub2[0]);  // int(30)
        var_dump($sub2[1]);  // int(40)

        // 运算
        $sum = $sub[0] + $sub[1] + $sub2[0] + $sub2[1];
        var_dump($sum);      // int(100)

        // ============================================================
        // 3. 嵌套字符串数组
        // ============================================================
        echo "===== 3. nested string =====\n";

        $words = [["hello", "world"], ["foo", "bar"]];
        var_dump($words[0][0]);  // string(5) "hello"
        var_dump($words[0][1]);  // string(5) "world"
        var_dump($words[1][0]);  // string(3) "foo"

        echo $words[0][0] . " " . $words[0][1] . "\n";  // hello world

        $row = $words[1];
        echo $row[0] . "-" . $row[1] . "\n";  // foo-bar

        // ============================================================
        // 4. 嵌套浮点数数组
        // ============================================================
        echo "===== 4. nested float =====\n";

        $mat = [[1.1, 2.2], [3.3, 4.4]];
        var_dump($mat[0][0]);   // float(1.1)
        var_dump($mat[0][1]);   // float(2.2)
        var_dump($mat[1][0]);   // float(3.3)

        // ============================================================
        // 5. 嵌套布尔数组
        // ============================================================
        echo "===== 5. nested bool =====\n";

        $flags = [[true, false], [false, true]];
        var_dump($flags[0][0]);  // bool(true)
        var_dump($flags[0][1]);  // bool(false)
        var_dump($flags[1][1]);  // bool(true)

        if ($flags[0][0]) {
            echo "flags[0][0] is true\n";
        }

        // ============================================================
        // 6. 子数组传播验证：$sub = $arr[0]; arrElementTypes 传播
        // ============================================================
        echo "===== 6. type propagation =====\n";

        $grid = [[100, 200], [300, 400]];
        $line1 = $grid[0];
        $line2 = $grid[1];

        // $line1 类型应为 t_array*，元素类型应为 t_int（从 arrNestedTypes 传播）
        var_dump($line1[0]);  // int(100)
        var_dump($line1[1]);  // int(200)
        var_dump($line2[0]);  // int(300)
        var_dump($line2[1]);  // int(400)

        $sumGrid = $line1[0] + $line1[1] + $line2[0] + $line2[1];
        var_dump($sumGrid);   // int(1000)

        // 字符串传播
        $labels = [["alpha", "beta"], ["gamma", "delta"]];
        $rowA = $labels[0];
        echo $rowA[0] . "/" . $rowA[1] . "\n";  // alpha/beta

        // ============================================================
        // 7. foreach 遍历嵌套数组
        // ============================================================
        echo "===== 7. foreach nested =====\n";

        $rows = [[10, 20], [30, 40], [50, 60]];
        $total = 0;
        foreach ($rows as $row) {
            $total = $total + $row[0] + $row[1];
        }
        var_dump($total);  // int(210) = 30+70+110

        echo "\n=== all nested array tests done ===\n";
    }
}
