<?php
// 对应 PHP 原生 tests/lang/025.phpt — Mean recursion test
// 递归深度从 10 改为 4 以减少输出体积
#debug  0  a  1  a  2  a  3
#debug  b 4
#debug  b 3  a  3
#debug  b 4
#debug  b 2  a  2  a  3
#debug  b 4
#debug  b 3  a  3
#debug  b 4

function RekTest(int $nr): void {
    echo " $nr ";
    $j = $nr + 1;
    while ($j < 4) {
        echo " a ";
        RekTest($j);
        $j++;
        echo " b $j ";
    }
    echo "\n";
}

class Main {
    public function main(): void {
        RekTest(0);
    }
}
