<?php
// 对应 PHP 原生 tests/lang/005.phpt — Simple If/ElseIf/Else Test
#debug good

class Main {
    public function main(): void {
        $a = 1;
        if ($a == 0) {
            echo "bad";
        } elseif ($a == 3) {
            echo "bad";
        } else {
            echo "good";
        }
    }
}
