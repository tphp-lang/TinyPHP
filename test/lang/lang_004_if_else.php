<?php
// 对应 PHP 原生 tests/lang/004.phpt — Simple If/Else Test
#debug good

class Main {
    public function main(): void {
        $a = 1;
        if ($a == 0) {
            echo "bad";
        } else {
            echo "good";
        }
    }
}
