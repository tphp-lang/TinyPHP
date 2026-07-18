<?php
// 对应 PHP ext/standard/tests/strings/lcfirst.phpt
// 测试 ucfirst() / lcfirst() 基本功能
// tphp 差异： 仅 ASCII 首字节
#debug string(11) "Hello world"
#debug string(11) "hello World"
#debug string(5) "Hello"
#debug string(5) "hELLO"
#debug string(11) "Hello world"
#debug string(11) "hELLO world"
#debug string(4) "Abcd"
#debug string(4) "nULL"
#debug string(0) ""
#debug string(0) ""

class Main
{
    public function main(): void
    {
        // 基本首字符大小写
        var_dump(ucfirst("hello world"));
        var_dump(lcfirst("Hello World"));

        // 单词
        var_dump(ucfirst("hello"));
        var_dump(lcfirst("HELLO"));

        // 已是大写 / 已是小写 → 零分配原串返回
        var_dump(ucfirst("Hello world"));
        var_dump(lcfirst("hELLO world"));

        // 首字符非字母 → 不变
        var_dump(ucfirst("abcd"));
        var_dump(lcfirst("NULL"));

        // 空字符串
        var_dump(ucfirst(""));
        var_dump(lcfirst(""));
    }
}
