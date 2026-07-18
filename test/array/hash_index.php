<?php
// Hash index verification — covers linear scan (<8 keys) AND hash index (>=8 keys) paths
#debug 0
#debug 10
#debug 50
#debug 190
#debug 999
#debug 350
#debug 0
#debug 100
#debug AFTER_SORT_OK

class Main
{
    public function main(): void
    {
        // Phase 1: Build array with 20 string keys (exercises hash index build + lookup)
        $arr = [];
        for ($i = 0; $i < 20; $i++) {
            $arr["key" . $i] = $i * 10;
        }

        // Lookups via hash index
        echo $arr["key0"] . "\n";
        echo $arr["key1"] . "\n";
        echo $arr["key5"] . "\n";
        echo $arr["key19"] . "\n";

        // Phase 2: Update existing key (must hit index, not append)
        $arr["key5"] = 999;
        echo $arr["key5"] . "\n";

        // Phase 3: Grow past initial index capacity (triggers resize)
        for ($i = 20; $i < 40; $i++) {
            $arr["key" . $i] = $i * 10;
        }
        echo $arr["key35"] . "\n";

        // Phase 4: Re-access old key (ensure index integrity after resize)
        echo $arr["key0"] . "\n";

        // Phase 5: Sort invalidates index; subsequent lookup must rebuild
        ksort($arr);
        echo $arr["key10"] . "\n";
        echo "AFTER_SORT_OK\n";
    }
}
