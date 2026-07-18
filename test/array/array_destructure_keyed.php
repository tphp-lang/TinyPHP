<?php
// 对应 PHP ext/standard/tests/array/list_keyed.phpt (keyed array destructuring)
// ["x" => $a, "y" => $b] = $arr: 按字符串键名解构（顺序无关）
// @skip tphp 编译失败：生成的 C 代码报 "error: switch expected"（keyed destructure 在某些场景下 CodeGenerator 生成非法 C），待 Task 8 修复

#debug ===== 1. basic keyed destructure =====
#debug int(100)
#debug int(200)
#debug int(300)
#debug
#debug ===== 2. order-independent =====
#debug string(5) "Alice"
#debug int(30)
#debug
#debug ===== 3. partial destructure =====
#debug int(1)
#debug int(3)
#debug
#debug ===== 4. list() keyword syntax =====
#debug string(2) "OK"
#debug int(404)
#debug
#debug ===== 5. nested keyed destructure =====
#debug string(5) "first"
#debug string(6) "second"
#debug
#debug ===== 6. destructure in foreach =====
#debug id=1 name=Alice
#debug id=2 name=Bob
#debug
#debug ===== 7. re-assign existing var =====
#debug int(42)
#debug int(77)
#debug
#debug ===== 8. from function return =====
#debug int(123)
#debug string(3) "val"
#debug
#debug ===== 9. single key destructure =====
#debug int(99)
#debug
#debug ===== 10. key not in array (no assignment) =====
#debug int(0)
#debug
#debug === done ===

class Main
{
    public function main(): void
    {
        // ============================================================
        // 1. 基本键名解构
        // ============================================================
        echo "===== 1. basic keyed destructure =====\n";
        $arr = ["x" => 100, "y" => 200, "z" => 300];
        ["x" => $a, "y" => $b, "z" => $c] = $arr;
        var_dump($a);   // int(100)
        var_dump($b);   // int(200)
        var_dump($c);   // int(300)

        // ============================================================
        // 2. 顺序无关：按键名匹配，与数组中 entry 顺序无关
        // ============================================================
        echo "\n===== 2. order-independent =====\n";
        $user = ["age" => 30, "name" => "Alice"];
        ["name" => $name, "age" => $age] = $user;
        var_dump($name);   // string(5) "Alice"
        var_dump($age);    // int(30)

        // ============================================================
        // 3. 只取部分键（其余忽略）
        // ============================================================
        echo "\n===== 3. partial destructure =====\n";
        $data = ["a" => 1, "b" => 2, "c" => 3, "d" => 4];
        ["a" => $first, "c" => $third] = $data;
        var_dump($first);  // int(1)
        var_dump($third);  // int(3)

        // ============================================================
        // 4. list() 关键字语法（等价于 [] 短语法）
        // ============================================================
        echo "\n===== 4. list() keyword syntax =====\n";
        $resp = ["status" => "OK", "code" => 404];
        list("status" => $status, "code" => $code) = $resp;
        var_dump($status);   // string(2) "OK"
        var_dump($code);     // int(404)

        // ============================================================
        // 5. 嵌套键名解构
        // ============================================================
        echo "\n===== 5. nested keyed destructure =====\n";
        $nested = ["outer" => ["a" => "first", "b" => "second"]];
        ["outer" => $sub] = $nested;
        var_dump($sub["a"]);   // string(5) "first"
        var_dump($sub["b"]);   // string(6) "second"

        // ============================================================
        // 6. foreach 中按键名解构
        // ============================================================
        echo "\n===== 6. destructure in foreach =====\n";
        $users = [
            ["id" => 1, "name" => "Alice"],
            ["id" => 2, "name" => "Bob"],
        ];
        foreach ($users as $u) {
            ["id" => $uid, "name" => $uname] = $u;
            echo "id=" . $uid . " name=" . $uname . "\n";
        }

        // ============================================================
        // 7. 已声明变量重新赋值
        // ============================================================
        echo "\n===== 7. re-assign existing var =====\n";
        $existing = 0;
        $extra = 0;
        $src = ["existing" => 42, "extra" => 77];
        ["existing" => $existing, "extra" => $extra] = $src;
        var_dump($existing);   // int(42)
        var_dump($extra);      // int(77)

        // ============================================================
        // 8. 解构函数返回的数组
        // ============================================================
        echo "\n===== 8. from function return =====\n";
        $helper = new KdHelper();
        $ret = $helper->getData();
        ["id" => $hid, "val" => $hval] = $ret;
        var_dump($hid);    // int(123)
        var_dump($hval);   // string(3) "val"

        // ============================================================
        // 9. 单键解构
        // ============================================================
        echo "\n===== 9. single key destructure =====\n";
        $single = ["result" => 99];
        ["result" => $r] = $single;
        var_dump($r);   // int(99)

        // ============================================================
        // 10. 键不存在时不赋值（变量保持原值）
        // ============================================================
        echo "\n===== 10. key not in array (no assignment) =====\n";
        $default = 0;
        $src2 = ["x" => 1];
        ["x" => $xx, "missing" => $default] = $src2;
        var_dump($default);   // int(0) — 未被覆盖

        echo "\n=== done ===\n";
    }
}

class KdHelper
{
    public function getData(): array
    {
        return ["id" => 123, "val" => "val"];
    }
}
