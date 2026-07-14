<?php
// 对应 PHP 原生 tests/lang/022.phpt — Switch test 3 (嵌套 switch + 函数)
// 函数参数加强类型声明 int $i, int $j，返回值 : void
#debug zero
#debug one
#debug 2
#debug 3
#debug 4
#debug 5
#debug 6
#debug 7
#debug 8
#debug 9
#debug zero
#debug one
#debug 2
#debug 3
#debug 4
#debug 5
#debug 6
#debug 7
#debug 8
#debug 9
#debug zero
#debug one
#debug 2
#debug 3
#debug 4
#debug 5
#debug 6
#debug 7
#debug 8
#debug 9

function switchtest(int $i, int $j): void {
    switch ($i) {
        case 0:
            switch ($j) {
                case 0:
                    echo "zero";
                    break;
                case 1:
                    echo "one";
                    break;
                default:
                    echo $j;
                    break;
            }
            echo "\n";
            break;
        default:
            echo "Default taken\n";
    }
}

class Main {
    public function main(): void {
        for ($i = 0; $i < 3; $i++) {
            for ($k = 0; $k < 10; $k++) {
                switchtest(0, $k);
            }
        }
    }
}
