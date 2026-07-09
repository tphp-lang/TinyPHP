<?php
#debug === New Builtins Test ===
#debug
#debug -- 1. Math --
#debug abs(-42)=42
#debug abs(99)=99
#debug round(3.6)=4 round(3.4)=3
#debug ceil(3.1)=4
#debug floor(3.9)=3
#debug sqrt(16)=4 sqrt(2)=1.41421
#debug
#debug -- 2. String --
#debug lower: hello world
#debug upper: HELLO WORLD
#debug
#debug -- 3. Array --
#debug search 30=2
#debug search 99=-1 (-1=not found)
#debug shuffle ok (len=5)
#debug
#debug -- 4. File --
#debug write+read: Hello TinyPHP!
#debug
#debug -- 5. Time --
#debug microtime() > 0: 1
#debug
#debug === All OK ===

class Main
{
    public function main(): void
    {
        echo "=== New Builtins Test ===\n\n";

        // ═══ 1. Math ═══
        echo "-- 1. Math --\n";
        echo 'abs(-42)=' . abs(-42) . "\n";
        echo 'abs(99)=' . abs(99) . "\n";
        echo 'round(3.6)=' . round(3.6) . ' round(3.4)=' . round(3.4) . "\n";
        echo 'ceil(3.1)=' . ceil(3.1) . "\n";
        echo 'floor(3.9)=' . floor(3.9) . "\n";
        echo 'sqrt(16)=' . sqrt(16) . ' sqrt(2)=' . sqrt(2) . "\n";

        // ═══ 2. String ═══
        echo "\n-- 2. String --\n";
        echo 'lower: ' . strtolower('Hello World') . "\n";
        echo 'upper: ' . strtoupper('Hello World') . "\n";

        // ═══ 3. Array ═══
        echo "\n-- 3. Array --\n";
        $arr = [10, 20, 30, 40, 50];
        echo 'search 30=' . array_search(30, $arr) . "\n";
        echo 'search 99=' . array_search(99, $arr) . " (-1=not found)\n";

        $shf = [1, 2, 3, 4, 5];
        shuffle($shf);
        echo 'shuffle ok (len=' . count($shf) . ')\n';

        // ═══ 4. File I/O ═══
        echo "\n-- 4. File --\n";
        file_put_contents('_test_fio.txt', 'Hello TinyPHP!');
        $content = file_get_contents('_test_fio.txt');
        echo 'write+read: ' . $content . "\n";

        // ═══ 5. Time ═══
        echo "\n-- 5. Time --\n";
        $t = microtime();
        echo 'microtime() > 0: ' . ($t > 0.0 ? 1 : 0) . "\n";

        echo "\n=== All OK ===\n";
    }
}
