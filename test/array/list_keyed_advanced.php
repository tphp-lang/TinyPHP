<?php
// 整数键名解构测试（PHP 7.1+ list(1 => $b, 0 => $a) = [1, 2] 用法）
// 对应 PHP ext/standard/tests/array/005.phpt
// 整数键解构：按键名匹配，与位置无关

#debug ===== 1. basic int-key destructure (list) =====
#debug int(1)
#debug int(2)
#debug
#debug ===== 2. order-independent (list) =====
#debug int(10)
#debug int(20)
#debug int(30)
#debug
#debug ===== 3. short syntax [] with int keys =====
#debug int(10)
#debug int(20)
#debug int(30)
#debug
#debug ===== 4. partial int-key destructure =====
#debug int(10)
#debug int(30)
#debug
#debug ===== 5. skip positions with int keys =====
#debug int(20)
#debug int(40)
#debug
#debug ===== 6. string-key destructure (control) =====
#debug string(5) "Alice"
#debug int(30)
#debug
#debug === done ===

class Main
{
    public function main(): void
    {
        // ============================================================
        // 1. 基本整数键解构：list(1 => $b, 0 => $a) = [1, 2]
        // ============================================================
        echo "===== 1. basic int-key destructure (list) =====\n";
        $arr = [1, 2];
        list(1 => $b, 0 => $a) = $arr;
        var_dump($a);   // int(1)
        var_dump($b);   // int(2)

        // ============================================================
        // 2. 顺序无关：按键名匹配，与声明顺序无关
        //    list(2 => $c, 0 => $a, 1 => $b) = [10, 20, 30]
        // ============================================================
        echo "\n===== 2. order-independent (list) =====\n";
        $arr2 = [10, 20, 30];
        list(2 => $c, 0 => $a, 1 => $b) = $arr2;
        var_dump($a);   // int(10)
        var_dump($b);   // int(20)
        var_dump($c);   // int(30)

        // ============================================================
        // 3. 短语法 [] + 整数键
        // ============================================================
        echo "\n===== 3. short syntax [] with int keys =====\n";
        $arr3 = [10, 20, 30];
        [0 => $a, 1 => $b, 2 => $c] = $arr3;
        var_dump($a);   // int(10)
        var_dump($b);   // int(20)
        var_dump($c);   // int(30)

        // ============================================================
        // 4. 部分整数键解构（只取部分键）
        // ============================================================
        echo "\n===== 4. partial int-key destructure =====\n";
        $arr4 = [10, 20, 30, 40];
        [0 => $first, 2 => $third] = $arr4;
        var_dump($first);   // int(10)
        var_dump($third);   // int(30)

        // ============================================================
        // 5. 跳过位与整数键混合
        //    注意：使用整数键后，位置不再有意义，按键名匹配
        //    这里仅取键 1 和 3
        // ============================================================
        echo "\n===== 5. skip positions with int keys =====\n";
        $arr5 = [10, 20, 30, 40];
        [1 => $b, 3 => $d] = $arr5;
        var_dump($b);   // int(20)
        var_dump($d);   // int(40)

        // ============================================================
        // 6. 字符串键解构（对照）
        // ============================================================
        echo "\n===== 6. string-key destructure (control) =====\n";
        $user = ["age" => 30, "name" => "Alice"];
        ["name" => $name, "age" => $age] = $user;
        var_dump($name);   // string(5) "Alice"
        var_dump($age);    // int(30)

        echo "\n=== done ===\n";
    }
}
