<?php
// 对应 PHP 原生 tests/lang/009.phpt — Testing function parameter passing
#debug 3

function test(int $a, int $b): void {
    echo $a + $b;
}

class Main {
    public function main(): void {
        test(1, 2);
    }
}
