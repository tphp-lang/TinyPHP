<?php
// 对应 PHP 原生 tests/lang/027.phpt — do-while 循环
// do { ... } while(cond); 至少执行一次循环体
#debug 321

class Main {
    public function main(): void {
        $i = 3;
        do {
            echo $i;
            $i--;
        } while ($i > 0);
    }
}
