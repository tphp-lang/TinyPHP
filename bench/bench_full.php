<?php

class Main
{
    public function main(): void
    {
        echo "START\n";

        $N = 10000;
        $nestedObj = [];
        $j = 0;
        while ($j < 50) {
            $inner = [];
            $inner['id'] = $j;
            $inner['name'] = 'user_' . $j;
            $inner['score'] = 95.5;
            $inner['active'] = true;
            $inner['tags'] = [$j, $j+1, $j+2];
            $nestedObj[$j] = $inner;
            $j = $j + 1;
        }
        echo "data built\n";

        // Test encode
        echo "encode 50x5 nested...\n";
        $t0 = microtime();
        $i = 0; while ($i < $N) { json_encode($nestedObj); $i = $i + 1; }
        echo "encode: " . (microtime() - $t0) . "s\n";

        // Test decode  
        $enc = json_encode($nestedObj);
        echo "decode " . strlen($enc) . " bytes...\n";
        $t0 = microtime();
        $i = 0; while ($i < $N) { json_decode($enc); $i = $i + 1; }
        echo "decode: " . (microtime() - $t0) . "s\n";

        // Test round-trip
        echo "round-trip...\n";
        $t0 = microtime();
        $i = 0; while ($i < 5000) {
            $tmp = json_encode($nestedObj);
            json_decode($tmp);
            $i = $i + 1;
        }
        echo "rtrip: " . (microtime() - $t0) . "s\n";

        echo "DONE\n";
    }
}
