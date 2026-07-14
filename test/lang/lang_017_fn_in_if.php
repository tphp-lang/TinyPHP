<?php
// 对应 PHP 原生 tests/lang/017.phpt — User-defined function in if condition
#debug 1

function Test(int $a): int {
    if ($a < 3) {
        return 3;
    }
    return 0;
}

class Main {
    public function main(): void {
        $a = 1;
        if ($a < Test($a)) {
            echo "$a\n";
            $a++;
        }
    }
}
