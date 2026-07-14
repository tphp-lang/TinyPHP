<?php
// 对应 PHP 原生 tests/lang/006.phpt — Nested If/ElseIf/Else Test
#debug good

class Main {
    public function main(): void {
        $a = 1;
        $b = 2;
        if ($a == 0) {
            echo "bad";
        } elseif ($a == 3) {
            echo "bad";
        } else {
            if ($b == 1) {
                echo "bad";
            } elseif ($b == 2) {
                echo "good";
            } else {
                echo "bad";
            }
        }
    }
}
