<?php
// 对应 PHP 原生 tests/lang/static_basic_001.phpt — Static keyword basic tests
// 适配 TinyPHP：拆分多变量 static 声明为单独语句，全局 static 移入方法
#debug ------------- Call 0 --------------
#debug a=10, b=20, c=30
#debug ------------- Call 1 --------------
#debug a=11, b=21, c=31
#debug ------------- Call 2 --------------
#debug a=12, b=22, c=32
#debug
#debug Global scope static:
#debug 0 10
#debug 1 11
#debug 2 12

class Main {
    public function manyInits(): void {
        static $counter = 0;
        static $a = 10;
        static $b = 20;
        static $c = 30;
        echo "------------- Call $counter --------------\n";
        echo "a=$a, b=$b, c=$c\n";
        $a++;
        $b++;
        $c++;
        $counter++;
    }

    public function globalStatic(): void {
        for ($i = 0; $i < 3; $i++) {
            static $s = 0;
            static $k = 10;
            echo "$s $k\n";
            $s++;
            $k++;
        }
    }

    public function main(): void {
        $this->manyInits();
        $this->manyInits();
        $this->manyInits();

        echo "\nGlobal scope static:\n";
        $this->globalStatic();
    }
}
