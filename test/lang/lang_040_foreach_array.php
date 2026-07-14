<?php
// 对应 PHP 原生 tests/lang/040.phpt — foreach into array
// 原生使用 foreach($a as $b[0])，TinyPHP 用简单变量替代
#debug 0
#debug 1

class Main {
    public function main(): void {
        $a = [0, 1];
        foreach ($a as $v) {
            echo $v . "\n";
        }
    }
}
