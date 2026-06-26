<?php // @exit 1 — §7 tests invalid JSON which triggers error()

class Main
{
    public function main(): void
    {
        // ============================================================
        // 1. 边界值：极限 int / 空字符串 / 零值
        // ============================================================
        echo "===== 1. edge values =====\n";

        var_dump(json_encode(2147483647));       // 大整数
        var_dump(json_encode(-2147483648));      // 负大整数
        var_dump(json_encode(0));                // 零
        var_dump(json_encode(0.0));              // 浮点零
        var_dump(json_encode(""));               // 空字符串

        // ============================================================
        // 2. 字符串转义
        // ============================================================
        echo "\n===== 2. string escaping =====\n";

        echo json_encode("hello world") . "\n";
        echo json_encode('{"x":1}') . "\n";

        // ============================================================
        // 3. 深层嵌套 — 4 层数组
        // ============================================================
        echo "\n===== 3. deep nested =====\n";

        $deep = [[[[1, 2], [3, 4]], [[5, 6], [7, 8]]]];
        $enc_deep = json_encode($deep);
        var_dump($enc_deep);

        $dec_deep = json_decode($enc_deep);
        $enc_deep2 = json_encode($dec_deep);
        var_dump($enc_deep2);

        // ============================================================
        // 4. 混合类型数组
        // ============================================================
        echo "\n===== 4. mixed types =====\n";

        $mixed = [42, -7, 3.14526, "hello", true, false, null, [1, 2]];
        $enc_mix = json_encode($mixed);
        var_dump($enc_mix);

        // ============================================================
        // 5. JSON 对象
        // ============================================================
        echo "\n===== 5. JSON objects =====\n";

        $obj_dec = json_decode('{"status":"ok","code":200,"data":[1,2,3]}');
        var_dump(is_array($obj_dec));

        $obj_enc = json_encode($obj_dec);
        echo $obj_enc . "\n";

        // ============================================================
        // 6. 连续多次往返
        // ============================================================
        echo "\n===== 6. multi round-trip =====\n";

        $data = [10, 20, 30, 40, 50];
        $e1 = json_encode($data);
        $d1 = json_decode($e1);
        $e2 = json_encode($d1);
        $d2 = json_decode($e2);
        $e3 = json_encode($d2);
        var_dump($e1);
        var_dump($e2);
        var_dump($e3);

        // ============================================================
        // 7. decode 错误 JSON（应返回 null，不崩溃）
        // ============================================================
        echo "\n===== 7. decode errors =====\n";

        $bad1 = json_decode("not json");
        var_dump($bad1);        // NULL

        $bad2 = json_decode("[1, 2,");
        var_dump($bad2);        // NULL

        $bad3 = json_decode("");
        var_dump($bad3);        // NULL

        // ============================================================
        // 8. 大数组编码（32 元素）
        // ============================================================
        echo "\n===== 8. large array =====\n";

        $big = [];
        $k = 0;
        while ($k < 32) {
            array_push($big, $k * 10);
            $k = $k + 1;
        }
        $enc_big = json_encode($big);
        $dec_big = json_decode($enc_big);
        var_dump(is_array($dec_big));  // bool(true)
        echo json_encode($dec_big) . "\n";

        // ============================================================
        // 9. decode + 类型检测
        // ============================================================
        echo "\n===== 9. decode + type checks =====\n";

        $r1 = json_decode("42");
        if (is_int($r1)) {
            echo "42 is int\n";
        }

        $r2 = json_decode('"hello"');
        if (is_string($r2)) {
            echo "hello is string\n";
        }

        $r3 = json_decode("true");
        if (is_bool($r3)) {
            echo "true is bool\n";
        }

        // ============================================================
        // 10. 数组中嵌套对象
        // ============================================================
        echo "\n===== 10. array of objects =====\n";

        $users_json = '[{"id":1,"name":"alice"},{"id":2,"name":"bob"},3.14526]';
        $users = json_decode($users_json);
        var_dump(is_array($users));
        var_dump(is_array($users));

        echo json_encode($users) . "\n";

        echo "\n=== ALL complex json tests done ===\n";
        var_dump($users);
    }
}
