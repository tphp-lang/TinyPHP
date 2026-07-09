<?php
#debug === Performance Optimizations Test ===
#debug
#debug -- 1. Trim zero-alloc --
#debug no-trim: hello
#debug trim-spaces: hi
#debug ltrim: left
#debug rtrim: right
#debug
#debug -- 2. Case zero-alloc --
#debug already lower: abc123
#debug to upper: HELLO
#debug to lower: hello
#debug
#debug -- 3. Substr zero-alloc --
#debug full: hello world
#debug partial: world
#debug
#debug -- 4. Array Unique --
#debug unique count=5
#debug
#debug -- 5. Regression --
#debug sort: 10,20,30
#debug sqrt: 5
#debug abs: 99
#debug file_put+get: Hello TinyPHP!
#debug
#debug === All Optimizations OK ===

class Main
{
    public function main(): void
    {
        echo "=== Performance Optimizations Test ===\n\n";

        // ═══ 1. trim zero-alloc ═══
        echo "-- 1. Trim zero-alloc --\n";
        $s = 'hello';
        echo 'no-trim: ' . trim($s) . "\n";
        echo 'trim-spaces: ' . trim('  hi  ') . "\n";
        echo 'ltrim: ' . ltrim('  left') . "\n";
        echo 'rtrim: ' . rtrim('right  ') . "\n";

        // ═══ 2. strtolower/upper zero-alloc ═══
        echo "\n-- 2. Case zero-alloc --\n";
        echo 'already lower: ' . strtolower('abc123') . "\n";
        echo 'to upper: ' . strtoupper('hello') . "\n";
        echo 'to lower: ' . strtolower('HELLO') . "\n";

        // ═══ 3. substr zero-alloc ═══
        echo "\n-- 3. Substr zero-alloc --\n";
        $s2 = 'hello world';
        echo 'full: ' . substr($s2, 0, strlen($s2)) . "\n";
        echo 'partial: ' . substr($s2, 6, 0) . "\n";

        // ═══ 4. array_unique O(n) — small test ═══
        echo "\n-- 4. Array Unique --\n";
        $arr = [1, 2, 2, 3, 3, 3, 4, 4, 4, 4, 5, 5, 5, 5, 5];
        $uniq = array_unique($arr);
        echo 'unique count=' . count($uniq) . "\n";

        // ═══ 5. Regression check — existing functions ═══
        echo "\n-- 5. Regression --\n";
        $a = [30, 10, 20];
        sort($a);
        echo 'sort: ' . $a[0] . ',' . $a[1] . ',' . $a[2] . "\n";

        echo 'sqrt: ' . sqrt(25) . "\n";
        echo 'abs: ' . abs(-99) . "\n";
        echo 'file_put+get: ' . file_get_contents('_test_fio.txt') . "\n";

        echo "\n=== All Optimizations OK ===\n";
    }
}
