<?php
#debug Array
#debug (
#debug     [0] => 1
#debug     [1] => 2
#debug     [2] => hello
#debug )
#debug hello
#debug 42
#debug === print_r done ===

class Main
{
    public function main(): void
    {
        $arr = [1, 2, 'hello'];
        print_r($arr);
        echo "\n";

        // scalar
        print_r("hello");
        echo "\n";
        print_r(42);
        echo "\n";

        echo "=== print_r done ===\n";
    }
}
