<?php
// 对应 PHP 原生 tests/lang/008.phpt — Testing recursive function
// 原生使用替代语法 if: endif; TinyPHP 暂不支持，改用普通 if {}
#debug 1 2 3 4 5 6 7 8 9

function Test(): void {
    static $a = 1;
    echo "$a ";
    $a++;
    if ($a < 10) {
        Test();
    }
}

class Main {
    public function main(): void {
        Test();
    }
}
