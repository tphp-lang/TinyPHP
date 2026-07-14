<?php
// 对应 PHP 原生 tests/lang/002.phpt — Simple While Loop Test
#debug 123456789

class Main {
    public function main(): void {
        $a = 1;
        while ($a < 10) {
            echo $a;
            $a++;
        }
    }
}
