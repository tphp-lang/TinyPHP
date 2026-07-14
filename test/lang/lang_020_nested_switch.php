<?php
// 对应 PHP 原生 tests/lang/020.phpt — Switch test 1 (嵌套 switch)
// 注：PHP 原生 switch(1) + case $i("abc") 依赖松散比较，TinyPHP 强类型 AOT
// 要求 case 值与 switch 条件同类型。这里用 int $k 测试动态 case 值支持。
#debug In branch 1
#debug Inner default...
#debug blah=100

class Main {
    public function main(): void {
        $i = "abc";
        $k = 5;  // 动态 int case 值（不会匹配 switch(1)）
        $j = 0;
        // 只跑 1 次循环（原生 10 次），减少输出体积
        while ($j < 1) {
            switch (1) {
                case 1:
                    echo "In branch 1\n";
                    switch ($i) {
                        case "ab":
                            echo "This doesn't work... :(\n";
                            break;
                        case "abcd":
                            echo "This works!\n";
                            break;
                        case "blah":
                            echo "Hmmm, no worki\n";
                            break;
                        default:
                            echo "Inner default...\n";
                    }
                    for ($blah = 0; $blah < 200; $blah++) {
                        if ($blah == 100) {
                            echo "blah=$blah\n";
                        }
                    }
                    break;
                case 2:
                    echo "In branch 2\n";
                    break;
                case $k:
                    echo "In branch \$k\n";
                    break;
                case 4:
                    echo "In branch 4\n";
                    break;
                default:
                    echo "Hi, I'm default\n";
                    break;
            }
            $j++;
        }
    }
}
