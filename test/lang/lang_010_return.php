<?php
// 对应 PHP 原生 tests/lang/010.phpt — Testing function parameter passing with return value
#debug 2

function test(int $b): int {
    $b++;
    return $b;
}

class Main {
    public function main(): void {
        $a = test(1);
        echo $a;
    }
}
