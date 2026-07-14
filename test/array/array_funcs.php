<?php
#debug ===== 1. array_push =====
#debug array(3) {
#debug   [0]=>
#debug   int(1)
#debug   [1]=>
#debug   int(2)
#debug   [2]=>
#debug   int(3)
#debug }
#debug int(3)
#debug array(5) {
#debug   [0]=>
#debug   int(1)
#debug   [1]=>
#debug   int(2)
#debug   [2]=>
#debug   int(3)
#debug   [3]=>
#debug   int(4)
#debug   [4]=>
#debug   int(5)
#debug }
#debug int(5)
#debug
#debug ===== 2. array_pop =====
#debug int(30)
#debug array(2) {
#debug   [0]=>
#debug   int(10)
#debug   [1]=>
#debug   int(20)
#debug }
#debug int(2)
#debug int(20)
#debug int(10)
#debug array(0) {
#debug }
#debug int(0)
#debug
#debug ===== 3. in_array =====
#debug bool(true)
#debug bool(false)
#debug bool(true)
#debug 30 found
#debug 99 not found
#debug
#debug ===== 4. array_key_exists =====
#debug bool(true)
#debug bool(true)
#debug bool(false)
#debug bool(false)
#debug key 2 exists
#debug
#debug ===== 5. array_keys =====
#debug array(3) {
#debug   [0]=>
#debug   int(0)
#debug   [1]=>
#debug   int(1)
#debug   [2]=>
#debug   int(2)
#debug }
#debug int(3)
#debug
#debug ===== 6. array_values =====
#debug array(4) {
#debug   [0]=>
#debug   int(11)
#debug   [1]=>
#debug   int(22)
#debug   [2]=>
#debug   int(33)
#debug   [3]=>
#debug   int(44)
#debug }
#debug int(4)
#debug
#debug ===== 7. array_merge =====
#debug array(5) {
#debug   [0]=>
#debug   int(1)
#debug   [1]=>
#debug   int(2)
#debug   [2]=>
#debug   int(3)
#debug   [3]=>
#debug   int(4)
#debug   [4]=>
#debug   int(5)
#debug }
#debug int(5)
#debug array(3) {
#debug   [0]=>
#debug   int(1)
#debug   [1]=>
#debug   int(2)
#debug   [2]=>
#debug   int(3)
#debug }
#debug array(2) {
#debug   [0]=>
#debug   int(4)
#debug   [1]=>
#debug   int(5)
#debug }
#debug
#debug ===== 8. implode =====
#debug 1,2,3
#debug 10-20
#debug
#debug ===== 9. explode =====
#debug array(3) {
#debug   [0]=>
#debug   string(1) "a"
#debug   [1]=>
#debug   string(1) "b"
#debug   [2]=>
#debug   string(1) "c"
#debug }
#debug int(3)
#debug array(2) {
#debug   [0]=>
#debug   string(5) "hello"
#debug   [1]=>
#debug   string(5) "world"
#debug }
#debug
#debug ===== 10. combined =====
#debug int(3)
#debug int(3)
#debug int(2)
#debug array(4) {
#debug   [0]=>
#debug   int(1)
#debug   [1]=>
#debug   int(2)
#debug   [2]=>
#debug   int(3)
#debug   [3]=>
#debug   int(4)
#debug }
#debug x|y|z
#debug
#debug === all array tests done ===

class Main
{
    public function main(): void
    {
        // ============================================================
        // 1. array_push — 尾部追加
        // ============================================================
        echo "===== 1. array_push =====\n";

        $a = [1, 2];
        $n = array_push($a, 3);
        var_dump($a);       // array(3) { 0=>int(1) 1=>int(2) 2=>int(3) }
        var_dump($n);       // int(3)

        array_push($a, 4);
        array_push($a, 5);
        var_dump($a);       // array(5) { 0=>int(1) 1=>int(2) 2=>int(3) 3=>int(4) 4=>int(5) }
        var_dump(count($a)); // int(5)

        // ============================================================
        // 2. array_pop — 尾部弹出
        // ============================================================
        echo "\n===== 2. array_pop =====\n";

        $a2 = [10, 20, 30];
        $pop1 = array_pop($a2);
        var_dump($pop1);     // int(30)
        var_dump($a2);       // array(2) { 0=>int(10) 1=>int(20) }
        var_dump(count($a2)); // int(2)

        $pop2 = array_pop($a2);
        var_dump($pop2);     // int(20)
        $pop3 = array_pop($a2);
        var_dump($pop3);     // int(10)
        var_dump($a2);       // array(0) {}
        var_dump(count($a2)); // int(0)

        // ============================================================
        // 3. in_array — 值是否存在
        // ============================================================
        echo "\n===== 3. in_array =====\n";

        $a3 = [10, 20, 30, 40];
        var_dump(in_array(20, $a3));   // bool(true)
        var_dump(in_array(50, $a3));   // bool(false)
        var_dump(in_array(10, $a3));   // bool(true)

        if (in_array(30, $a3)) {
            echo "30 found\n";
        }
        if (!in_array(99, $a3)) {
            echo "99 not found\n";
        }

        // ============================================================
        // 4. array_key_exists — 键是否存在
        // ============================================================
        echo "\n===== 4. array_key_exists =====\n";

        $a4 = [10, 20, 30];
        var_dump(array_key_exists(0, $a4));  // bool(true)
        var_dump(array_key_exists(1, $a4));  // bool(true)
        var_dump(array_key_exists(3, $a4));  // bool(false)
        var_dump(array_key_exists(5, $a4));  // bool(false)

        if (array_key_exists(2, $a4)) {
            echo "key 2 exists\n";
        }

        // ============================================================
        // 5. array_keys — 取所有键
        // ============================================================
        echo "\n===== 5. array_keys =====\n";

        $a5 = [100, 200, 300];
        $keys = array_keys($a5);
        var_dump($keys);       // array(3) { 0=>int(0) 1=>int(1) 2=>int(2) }
        var_dump(count($keys)); // int(3)

        // ============================================================
        // 6. array_values — 取所有值
        // ============================================================
        echo "\n===== 6. array_values =====\n";

        $a6 = [11, 22, 33, 44];
        $vals = array_values($a6);
        var_dump($vals);       // array(4) { 0=>int(11) 1=>int(22) 2=>int(33) 3=>int(44) }
        var_dump(count($vals)); // int(4)

        // ============================================================
        // 7. array_merge — 合并两个数组
        // ============================================================
        echo "\n===== 7. array_merge =====\n";

        $a7a = [1, 2, 3];
        $a7b = [4, 5];
        $merged = array_merge($a7a, $a7b);
        var_dump($merged);      // array(5) { 0=>int(1) 1=>int(2) 2=>int(3) 3=>int(4) 4=>int(5) }
        var_dump(count($merged)); // int(5)

        // 原数组不变
        var_dump($a7a);          // array(3) { 0=>int(1) 1=>int(2) 2=>int(3) }
        var_dump($a7b);          // array(2) { 0=>int(4) 1=>int(5) }

        // ============================================================
        // 8. implode — 用分隔符连接数组元素为字符串
        // ============================================================
        echo "\n===== 8. implode =====\n";

        $a8 = [1, 2, 3];
        $s8 = implode(",", $a8);
        echo $s8 . "\n";        // "1,2,3"

        $a8b = [10, 20];
        echo implode("-", $a8b) . "\n";   // "10-20"

        // ============================================================
        // 9. explode — 按分隔符切分字符串为数组
        // ============================================================
        echo "\n===== 9. explode =====\n";

        $s9 = "a,b,c";
        $parts = explode(",", $s9);
        var_dump($parts);        // array(3) { 0=>string(1)"a" 1=>string(1)"b" 2=>string(1)"c" }
        var_dump(count($parts)); // int(3)

        $s9b = "hello world";
        $parts2 = explode(" ", $s9b);
        var_dump($parts2);       // array(2) { 0=>string(5)"hello" 1=>string(5)"world" }

        // ============================================================
        // 10. 组合使用
        // ============================================================
        echo "\n===== 10. combined =====\n";

        // array_push + array_pop + count
        $stack = [];
        array_push($stack, 1);
        array_push($stack, 2);
        array_push($stack, 3);
        var_dump(count($stack));           // int(3)
        $top = array_pop($stack);
        var_dump($top);                    // int(3)
        var_dump(count($stack));           // int(2)

        // in_array + array_push
        $items = [1, 2, 3];
        if (!in_array(4, $items)) {
            array_push($items, 4);
        }
        var_dump($items);                  // array(4)

        // implode + explode round-trip
        $original = "x,y,z";
        $arr2 = explode(",", $original);
        $back = implode("|", $arr2);
        echo $back . "\n";                 // "x|y|z"

        echo "\n=== all array tests done ===\n";
    }
}
