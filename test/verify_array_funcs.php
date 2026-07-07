<?php
#debug 1. map: 2 4 6 8 10
#debug 2. filter: 2 4
#debug 3. reduce: 15
#debug 4. map_str: A B C
#debug 5. filter_str: hello world
#debug 6. reduce_str: abc
#debug === array funcs done ===

class Main
{
    public function main(): void
    {
        $nums = [1, 2, 3, 4, 5];

        // 1. array_map: double each
        $doubled = array_map(fn(int $x): int => $x * 2, $nums);
        echo "1. map:";
        for ($i = 0; $i < count($doubled); $i++) {
            echo " " . $doubled[$i];
        }
        echo "\n";

        // 2. array_filter: keep even
        $even = array_filter($nums, fn(int $x): bool => $x % 2 === 0);
        echo "2. filter:";
        for ($i = 0; $i < count($even); $i++) {
            echo " " . $even[$i];
        }
        echo "\n";

        // 3. array_reduce: sum
        $sum = array_reduce($nums, fn(int $acc, int $x): int => $acc + $x, 0);
        echo "3. reduce: " . $sum . "\n";

        // 4. array_map with strings
        $letters = ['a', 'b', 'c'];
        $upper = array_map(fn(string $s): string => strtoupper($s), $letters);
        echo "4. map_str:";
        for ($i = 0; $i < count($upper); $i++) {
            echo " " . $upper[$i];
        }
        echo "\n";

        // 5. array_filter with strings (keep length > 3)
        $words = ['hi', 'hello', 'yo', 'world'];
        $longw = array_filter($words, fn(string $s): bool => strlen($s) > 3);
        echo "5. filter_str:";
        for ($i = 0; $i < count($longw); $i++) {
            echo " " . $longw[$i];
        }
        echo "\n";

        // 6. array_reduce with strings: concat
        $parts = ['a', 'b', 'c'];
        $joined = array_reduce($parts, fn(string $acc, string $s): string => $acc . $s, '');
        echo "6. reduce_str: " . $joined . "\n";

        echo "=== array funcs done ===\n";
    }
}
