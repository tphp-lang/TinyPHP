<?php
// 对应 PHP 原生 tests/lang/012.phpt — Testing stack after early function return
#debug HelloHello

function F(): string {
    if (true) {
        return "Hello";
    }
    return "";
}

class Main {
    public function main(): void {
        $i = 0;
        while ($i < 2) {
            echo F();
            $i++;
        }
    }
}
