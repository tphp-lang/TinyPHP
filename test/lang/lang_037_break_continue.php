<?php
// 对应 PHP tests/lang/ — break N / continue N 多层循环控制
// 无直接对应 .phpt：021.phpt 测 switch+break 2（已移植为 lang_021），
// 此测试专注于嵌套循环中的 break 2 / continue 2
#debug === break 2 test ===
#debug i=0 j=0
#debug i=0 j=1
#debug i=0 j=2
#debug i=1 j=0
#debug break!
#debug === continue 2 test ===
#debug i=0 j=0
#debug i=0 j=1
#debug i=0 j=2
#debug i=1 j=0
#debug done

class Main {
    public function main(): void {
        echo "=== break 2 test ===\n";
        for ($i = 0; $i < 3; $i++) {
            for ($j = 0; $j < 3; $j++) {
                if ($i == 1 && $j == 1) {
                    echo "break!\n";
                    break 2;
                }
                echo "i=$i j=$j\n";
            }
        }

        echo "=== continue 2 test ===\n";
        for ($i = 0; $i < 2; $i++) {
            for ($j = 0; $j < 3; $j++) {
                if ($i == 1 && $j == 1) {
                    continue 2;
                }
                echo "i=$i j=$j\n";
            }
        }
        echo "done\n";
    }
}
