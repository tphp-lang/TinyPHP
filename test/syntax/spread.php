<?php
#debug ===== 1. 基本 spread =====
#debug array(3) {
#debug   [0]=>
#debug   int(1)
#debug   [1]=>
#debug   int(2)
#debug   [2]=>
#debug   int(3)
#debug }
#debug
#debug ===== 2. 合并两数组 =====
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
#debug
#debug ===== 3. spread + 字面量混合 =====
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
#debug
#debug ===== 4. 字符串键 spread =====
#debug array(2) {
#debug   ["x"]=>
#debug   int(1)
#debug   ["y"]=>
#debug   int(2)
#debug }
#debug
#debug ===== 5. 字符串键覆盖 =====
#debug array(1) {
#debug   ["x"]=>
#debug   int(2)
#debug }
#debug
#debug ===== 6. int 键重新索引 =====
#debug array(4) {
#debug   [0]=>
#debug   int(10)
#debug   [1]=>
#debug   int(20)
#debug   [2]=>
#debug   int(30)
#debug   [3]=>
#debug   int(40)
#debug }
#debug
#debug ===== 7. 字符串数组 spread =====
#debug array(2) {
#debug   [0]=>
#debug   string(5) "hello"
#debug   [1]=>
#debug   string(5) "world"
#debug }
#debug
#debug ===== 8. 嵌套 spread =====
#debug array(2) {
#debug   [0]=>
#debug   array(2) {
#debug     [0]=>
#debug     int(1)
#debug     [1]=>
#debug     int(2)
#debug   }
#debug   [1]=>
#debug   array(2) {
#debug     [0]=>
#debug     int(3)
#debug     [1]=>
#debug     int(4)
#debug   }
#debug }
#debug
#debug ===== 9. 空数组 spread =====
#debug array(2) {
#debug   [0]=>
#debug   int(1)
#debug   [1]=>
#debug   int(2)
#debug }
#debug
#debug ===== 10. 内联 spread (var_dump 参数) =====
#debug array(3) {
#debug   [0]=>
#debug   int(1)
#debug   [1]=>
#debug   int(2)
#debug   [2]=>
#debug   int(3)
#debug }
#debug
#debug ===== All passed =====

class Main {
    public function main(): void {
        // ===== 1. 基本 spread =====
        echo "===== 1. 基本 spread =====\n";
        $a = [1, 2, 3];
        $result = [...$a];
        var_dump($result);
        echo "\n";

        // ===== 2. 合并两数组 =====
        echo "===== 2. 合并两数组 =====\n";
        $a1 = [1, 2];
        $a2 = [3, 4];
        $merged = [...$a1, ...$a2];
        var_dump($merged);
        echo "\n";

        // ===== 3. spread + 字面量混合 =====
        echo "===== 3. spread + 字面量混合 =====\n";
        $b = [2, 3];
        $mixed = [1, ...$b, 4];
        var_dump($mixed);
        echo "\n";

        // ===== 4. 字符串键 spread =====
        echo "===== 4. 字符串键 spread =====\n";
        $c = ['x' => 1, 'y' => 2];
        $strSpread = [...$c];
        var_dump($strSpread);
        echo "\n";

        // ===== 5. 字符串键覆盖 =====
        echo "===== 5. 字符串键覆盖 =====\n";
        $d1 = ['x' => 1];
        $d2 = ['x' => 2];
        $override = [...$d1, ...$d2];
        var_dump($override);
        echo "\n";

        // ===== 6. int 键重新索引 =====
        echo "===== 6. int 键重新索引 =====\n";
        $e1 = [10, 20];
        $e2 = [30, 40];
        $reindexed = [...$e1, ...$e2];
        var_dump($reindexed);
        echo "\n";

        // ===== 7. 字符串数组 spread =====
        echo "===== 7. 字符串数组 spread =====\n";
        $f = ['hello', 'world'];
        $strArr = [...$f];
        var_dump($strArr);
        echo "\n";

        // ===== 8. 嵌套 spread =====
        echo "===== 8. 嵌套 spread =====\n";
        $g1 = [1, 2];
        $g2 = [3, 4];
        $nested = [[...$g1], [...$g2]];
        var_dump($nested);
        echo "\n";

        // ===== 9. 空数组 spread =====
        echo "===== 9. 空数组 spread =====\n";
        $withEmpty = [...[], 1, ...[2]];
        var_dump($withEmpty);
        echo "\n";

        // ===== 10. 内联 spread (var_dump 参数) =====
        echo "===== 10. 内联 spread (var_dump 参数) =====\n";
        $h = [1, 2, 3];
        var_dump([...$h]);
        echo "\n";

        echo "===== All passed =====\n";
    }
}
