<?php

class Main
{
    public function main(): void
    {
        echo "START\n";

        $arr = [1,2,3,4,5,6,7,8,9,10];
        $enc = json_encode($arr);
        echo "encode: " . $enc . "\n";

        $dec = json_decode($enc);
        echo "decode ok, is_array: " . is_array($dec) . "\n";

        $i = 0;
        while ($i < 100) {
            json_encode($arr);
            $i = $i + 1;
        }
        echo "100x encode OK\n";

        $i = 0;
        while ($i < 100) {
            json_decode('[1,2,3,4,5,6,7,8,9,10]');
            $i = $i + 1;
        }
        echo "100x decode OK\n";

        echo "DONE\n";
    }
}
