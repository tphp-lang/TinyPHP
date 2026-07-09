<?php

class Main
{
    public function main(): void
    {
        echo "int test\n";

        echo "42: " . json_encode(42) . "\n";
        echo "-7: " . json_encode(-7) . "\n";
        echo "2147483647: " . json_encode(2147483647) . "\n";
        echo "-2147483648: " . json_encode(-2147483648) . "\n";
        echo "0: " . json_encode(0) . "\n";
        echo "100: " . json_encode(100) . "\n";
        echo "1000: " . json_encode(1000) . "\n";
        echo "12345: " . json_encode(12345) . "\n";
        echo "999999: " . json_encode(999999) . "\n";

        echo "\narray test\n";
        $a = [1, 10, 100, 1000, 10000, 100000];
        echo json_encode($a) . "\n";

        echo "\nnested test\n";
        $n = [[1,2],[3,4]];
        echo json_encode($n) . "\n";

        echo "\nobject test\n";
        $o = [];
        $o['key'] = 'val';
        $o['num'] = 42;
        echo json_encode($o) . "\n";

        echo "\nDONE\n";
    }
}
