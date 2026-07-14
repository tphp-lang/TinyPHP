<?php
// 对应 PHP 原生 tests/lang/021.phpt — Switch test 2
// 测试 break N; 跳出多层结构（switch + for 循环）
#debug i=0
#debug In branch 0
#debug i=1
#debug In branch 1
#debug i=2
#debug In branch 2
#debug i=3
#debug In branch 3
#debug hi

class Main {
    public function main(): void {
        for ($i = 0; $i <= 5; $i++) {
            echo "i=$i\n";
            switch ($i) {
                case 0:
                    echo "In branch 0\n";
                    break;
                case 1:
                    echo "In branch 1\n";
                    break;
                case 2:
                    echo "In branch 2\n";
                    break;
                case 3:
                    echo "In branch 3\n";
                    break 2;
                case 4:
                    echo "In branch 4\n";
                    break;
                default:
                    echo "In default\n";
                    break;
            }
        }
        echo "hi\n";
    }
}
