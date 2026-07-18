<?php
#debug 1. single: hello php
#debug 2. arr_pair: A C B D
#debug 3. arr_search_only: XX cd
#debug 4. mixed: hi hi
#debug === str_replace done ===

class Main
{
    public function main(): void
    {
        // 1. 单字符串替换
        $r1 = str_replace("world", "php", "hello world");
        echo "1. single: " . $r1 . "\n";

        // 2. 数组配对替换
        $search = ['a', 'b', 'c', 'd'];
        $replace = ['A', 'B', 'C', 'D'];
        $r2 = str_replace($search, $replace, "a c b d");
        echo "2. arr_pair: " . $r2 . "\n";

        // 3. search 数组 + replace 字符串（删除型）
        $r3 = str_replace(['a', 'b'], 'X', "ab cd");
        echo "3. arr_search_only: " . $r3 . "\n";

        // 4. search 字符串 + replace 数组（按字符串处理）
        $r4 = str_replace("yo", "hi", "yo yo");
        echo "4. mixed: " . $r4 . "\n";

        echo "=== str_replace done ===\n";
    }
}
