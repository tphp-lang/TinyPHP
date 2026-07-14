<?php
// 对应 PHP 原生 tests/lang/011.phpt — Testing nested functions
#debug 4 Hello 4

function F(): string {
    $a = "Hello ";
    return $a;
}

function G(): void {
    static $myvar = 4;
    echo "$myvar ";
    echo F();
    echo "$myvar";
}

class Main {
    public function main(): void {
        G();
    }
}
