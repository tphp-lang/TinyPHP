<?php
#debug hello world
#debug hello 你好
#debug hello 你好

class Main
{
    public function main(): void
    {
        // 双引号字符串,单引号只能 . 拼接
        $a = "hello";
        $b = "world";
        $c = $a . " " . $b;
        echo $c . "\n"; // hello world

        $d = "你好";

        echo "hello $d\n"; // hello 你好
        echo "hello {$d}\n"; // 同上
    }
}
