<?php
// 对应 PHP 原生 tests/lang/001.phpt — Simple If condition test
#debug Yes

class Main {
    public function main(): void {
        $a = 1;
        if ($a > 0) {
            echo "Yes";
        }
    }
}
