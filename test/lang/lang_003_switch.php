<?php
// 对应 PHP 原生 tests/lang/003.phpt — Simple Switch Test
#debug good

class Main {
    public function main(): void {
        $a = 1;
        switch ($a) {
            case 0:
                echo "bad";
                break;
            case 1:
                echo "good";
                break;
            default:
                echo "bad";
                break;
        }
    }
}
