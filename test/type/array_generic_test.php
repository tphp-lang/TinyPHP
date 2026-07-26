<?php
// array<T> 泛型数组类型注解测试
// 测试已实现的功能：数组字面量创建、元素访问、foreach
// 注意：array<T> 和 array<mixed> 是不同类型，不能互相传递（无协变）

#debug ===== 1. array<int> 局部变量 + foreach =====
#debug int(10)
#debug int(20)
#debug int(30)
#debug
#debug ===== 2. array<string> 局部变量 + foreach =====
#debug string(3) "foo"
#debug string(3) "bar"
#debug
#debug ===== 3. array<float> 局部变量 + foreach =====
#debug float(1.1)
#debug float(2.2)
#debug float(3.3)
#debug
#debug ===== 4. array<int> 元素访问 =====
#debug int(10)
#debug int(30)
#debug
#debug ===== 5. 嵌套 array<array<int>> =====
#debug int(1)
#debug int(3)
#debug
#debug ===== 6. array<mixed> 混合类型 =====
#debug int(1)
#debug string(3) "foo"
#debug float(2.5)
#debug
#debug ===== 7. array<T> 传给只读内置函数 =====
#debug int(3)
#debug int(2)
#debug bool(true)
#debug bool(false)
#debug int(3)
#debug
#debug ===== 8. array<T> 原地修改用原生语法 =====
#debug int(4)
#debug int(10)
#debug int(40)
#debug
#debug ===== 9. all done =====
#debug OK

class Main
{
    public function main(): void
    {
        // ============================================================
        // 1. array<int> 局部变量 + foreach
        // ============================================================
        echo "===== 1. array<int> 局部变量 + foreach =====\n";
        array<int> $arr = [10, 20, 30];
        foreach ($arr as $v) {
            var_dump($v);
        }

        // ============================================================
        // 2. array<string> 局部变量 + foreach
        // ============================================================
        echo "\n===== 2. array<string> 局部变量 + foreach =====\n";
        array<string> $strs = ["foo", "bar"];
        foreach ($strs as $s) {
            var_dump($s);
        }

        // ============================================================
        // 3. array<float> 局部变量 + foreach
        // ============================================================
        echo "\n===== 3. array<float> 局部变量 + foreach =====\n";
        array<float> $floats = [1.1, 2.2, 3.3];
        foreach ($floats as $f) {
            var_dump($f);
        }

        // ============================================================
        // 4. array<int> 元素访问
        // ============================================================
        echo "\n===== 4. array<int> 元素访问 =====\n";
        var_dump($arr[0]);
        var_dump($arr[2]);

        // ============================================================
        // 5. 嵌套 array<array<int>>
        // ============================================================
        echo "\n===== 5. 嵌套 array<array<int>> =====\n";
        $nested = [[1, 2], [3, 4]];
        var_dump($nested[0][0]);
        var_dump($nested[1][0]);

        // ============================================================
        // 6. array<mixed> 混合类型（默认推导）
        // ============================================================
        echo "\n===== 6. array<mixed> 混合类型 =====\n";
        $mixed = [1, "foo", 2.5];
        foreach ($mixed as $m) {
            var_dump($m);
        }

        // ============================================================
        // 7. array<T> 传给只读内置函数（自动协变转换为 array<mixed>）
        // ============================================================
        echo "\n===== 7. array<T> 传给只读内置函数 =====\n";
        // count() 接收 t_array*，array<int> 自动转换
        var_dump(count($arr));         // int(3)
        var_dump(count($strs));        // int(2)
        // in_array() 第二参数是 t_array*，array<int> 自动转换
        var_dump(in_array(20, $arr));  // bool(true)
        var_dump(in_array(99, $arr));  // bool(false)
        // array_keys() 返回新数组，原数组不变
        $keys = array_keys($arr);
        var_dump(count($keys));        // int(3) — 3 个 key

        // ============================================================
        // 8. array<T> 原地修改用原生语法（$arr[] = $val）
        // ============================================================
        echo "\n===== 8. array<T> 原地修改用原生语法 =====\n";
        $arr[] = 40;  // 直接 push 到 array<int>，走特化快路径
        var_dump(count($arr));         // int(4)
        var_dump($arr[0]);             // int(10)
        var_dump($arr[3]);             // int(40)

        // ============================================================
        // 9. 完成
        // ============================================================
        echo "\n===== 9. all done =====\n";
        echo "OK\n";
    }
}
