<?php
#debug 2000
// 测试 str_replace 不崩溃（超大替换次数）
// 这个测试主要验证不崩溃，不验证具体输出

class Main
{
    public function main(): void
    {
        $s = str_repeat("a", 1000);
        $r = str_replace("a", "bb", $s);
        echo strlen($r) . "\n";  // 2000
    }
}
