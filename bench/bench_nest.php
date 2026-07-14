<?php

class Main
{
    public function main(): void
    {
        echo "START\n";

        // 构建嵌套数据
        $nestedObj = [];
        $j = 0;
        while ($j < 10) {
            $inner = [];
            $inner['id'] = $j;
            $inner['name'] = 'user_' . $j;
            $inner['score'] = 95.5;
            $inner['active'] = true;
            $inner['tags'] = [$j, $j+1, $j+2];
            $nestedObj[$j] = $inner;
            $j = $j + 1;
        }
        echo "data built: " . count($nestedObj) . "\n";

        // encode
        $enc = json_encode($nestedObj);
        echo "encode len: " . strlen($enc) . "\n";

        // decode back
        $dec = json_decode($enc);
        echo "decode ok: " . is_array($dec) . "\n";

        // loop encode
        $i = 0;
        while ($i < 100) {
            json_encode($nestedObj);
            $i = $i + 1;
        }
        echo "100x nested encode OK\n";

        // loop decode
        $i = 0;
        while ($i < 100) {
            json_decode($enc);
            $i = $i + 1;
        }
        echo "100x nested decode OK\n";

        echo "DONE\n";
    }
}
