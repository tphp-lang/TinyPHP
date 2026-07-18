<?php
// 对应 PHP tests/func/001.phpt
#debug int(6)
#debug int(0)
#debug int(11)
#debug int(1)
#debug int(6)
#debug int(13)

class Main
{
    public function main(): void
    {
        // 基本 ASCII 字符串长度
        var_dump(strlen("abcdef"));       // int(6)
        // 空字符串
        var_dump(strlen(""));             // int(0)
        // 含空格的字符串
        var_dump(strlen("hello world"));  // int(11)
        // 单字符
        var_dump(strlen("a"));            // int(1)
        // Unicode 多字节: strlen 返回字节数 (UTF-8)
        // "你好" = 2 个字符 × 3 字节 = 6 字节
        var_dump(strlen("你好"));         // int(6)
        // 混合 ASCII + 多字节
        // "hello, 你好" = 7 字节 ("hello, ") + 6 字节 ("你好") = 13 字节
        var_dump(strlen("hello, 你好"));  // int(13)
    }
}
