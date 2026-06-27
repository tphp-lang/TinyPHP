<?php

// ============================================================
// 第一梯队新增函数测试
// ============================================================

class Main
{
    public function main(): void
    {
        // ═══ 1. 数学函数 ═══
        echo "-- 1. Math --\n";

        // pi
        echo "pi=" . pi() . "\n";
        var_dump(pi() > 3.14);

        // deg2rad / rad2deg
        var_dump(deg2rad(180.0));     // expect: ~3.14159 (int)
        var_dump(rad2deg(3.141592653589793));  // expect: ~180.0 (int)

        // intdiv
        $id = intdiv(10, 3);
        var_dump($id);               // expect: 3
        $id2 = intdiv(7, 2);
        var_dump($id2);              // expect: 3

        // pow
        $pw = pow(2, 3);
        var_dump($pw);               // expect: 8

        // ═══ 2. 字符函数 ═══
        echo "\n-- 2. Ord/Chr --\n";
        $o = ord("A");
        var_dump($o);                // expect: 65
        $o2 = ord("");
        var_dump($o2);               // expect: 0

        $ch = chr(65);
        var_dump($ch);               // expect: "A" → is_string true

        // ═══ 3. 字符串检查 ═══
        echo "\n-- 3. String checks --\n";
        $st = str_starts_with("hello world", "hello");
        var_dump($st);               // expect: true
        $st2 = str_starts_with("hello", "xxx");
        var_dump($st2);              // expect: false

        $se = str_ends_with("hello world", "world");
        var_dump($se);               // expect: true
        $se2 = str_ends_with("hello", "xxx");
        var_dump($se2);              // expect: false

        // ═══ 4. is_numeric ═══
        echo "\n-- 4. is_numeric --\n";
        $in1 = is_numeric("42");
        var_dump($in1);              // expect: true
        $in2 = is_numeric("3.14");
        var_dump($in2);              // expect: true
        $in3 = is_numeric("hello");
        var_dump($in3);              // expect: false

        // ═══ 5. gettype ═══
        echo "\n-- 5. gettype --\n";
        var_dump(gettype(42));       // expect: "int"
        var_dump(gettype(3.14));     // expect: "float"

        // ═══ 6. 进制转换 ═══
        echo "\n-- 6. Base conversion --\n";
        $bd = bindec("1010");
        var_dump($bd);               // expect: 10
        $hd = hexdec("ff");
        var_dump($hd);               // expect: 255
        $od = octdec("17");
        var_dump($od);               // expect: 15

        $db = decbin(10);
        var_dump($db);               // expect: "1010"
        $dh = dechex(255);
        var_dump($dh);               // expect: "ff"
        $d0 = decoct(15);
        var_dump($d0);               // expect: "17"

        // ═══ 7. number_format ═══
        echo "\n-- 7. number_format --\n";
        $nf1 = number_format(1234567);
        var_dump($nf1);              // expect: "1,234,567"
        $nf2 = number_format(1234.5678, 2);
        var_dump($nf2);              // expect: "1,234.57"

        // ═══ 8. 数组指针 ═══
        echo "\n-- 8. Array pointer --\n";
        $arr = [10, 20, 30, 40, 50];

        $cv = current($arr);
        var_dump($cv);               // expect: 10
        $kv = key($arr);
        var_dump($kv);               // expect: 0

        $nv = next($arr);
        var_dump($nv);               // expect: 20
        $nv2 = next($arr);
        var_dump($nv2);              // expect: 30

        $pv = prev($arr);
        var_dump($pv);               // expect: 20

        $ev = end($arr);
        var_dump($ev);               // expect: 50

        $rv = reset($arr);
        var_dump($rv);               // expect: 10

        // ═══ 9. array_key_first/last ═══
        echo "\n-- 9. array_key_first/last --\n";
        $akf = array_key_first($arr);
        var_dump($akf);              // expect: 0
        $akl = array_key_last($arr);
        var_dump($akl);              // expect: 4

        // ═══ 10. array_is_list ═══
        echo "\n-- 10. array_is_list --\n";
        $l1 = array_is_list([1,2,3]);
        var_dump($l1);               // expect: true
        $l2 = array_is_list([0=>'a', 2=>'b']);
        var_dump($l2);               // expect: false

        // ═══ 11. array_rand ═══
        echo "\n-- 11. array_rand --\n";
        $ar = array_rand([100, 200, 300]);
        var_dump($ar >= 0);          // expect: true

        // ═══ 12. 时间函数 ═══
        echo "\n-- 12. Time --\n";
        $mkt = mktime(12, 0, 0, 6, 27, 2026);
        var_dump($mkt);              // expect: some timestamp

        $stt = strtotime("2024-01-01");
        var_dump($stt);              // expect: 1704067200

        $uid = uniqid();
        var_dump(strlen($uid) > 10); // expect: true

        echo "\n=== Tier1 All OK ===\n";
    }
}
