<?php
// 对应 PHP ext/standard/tests/strings/substr.phpt
// 测试 substr() 基本功能：正/负 offset、正/负 length、length=0 表示到末尾、越界
// tphp 差异： length 必填（无默认值），length=0 表示到末尾（与 PHP 不同，PHP 中 0 表示空串）
#debug string(5) "world"
#debug string(3) "rld"
#debug string(5) "world"
#debug string(3) "wor"
#debug string(0) ""
#debug string(11) "hello world"
#debug string(5) "world"
#debug string(11) "hello world"
#debug string(0) ""
#debug string(4) "ello"
#debug string(5) "world"
#debug string(5) "hello"

class Main
{
    public function main(): void
    {
        // 基本截取：从位置 6 到末尾（length=0 表示到末尾）
        var_dump(substr("hello world", 6, 0));

        // 负 offset：从末尾第 3 个字符开始
        var_dump(substr("hello world", -3, 0));

        // 负 offset + 正 length
        var_dump(substr("hello world", -5, 5));

        // 负 offset + 负 length（去掉末尾 2 字符）
        var_dump(substr("hello world", -5, -2));

        // start 越界（>= 字符串长度）→ 空串
        var_dump(substr("hello world", 100, 1));

        // start=0, length=0 → 整个字符串
        var_dump(substr("hello world", 0, 0));

        // start=6, length=0 → 从位置 6 到末尾
        var_dump(substr("hello world", 6, 0));

        // start=0, length=11 → 整个字符串
        var_dump(substr("hello world", 0, 11));

        // start=0, length=0 + 字符串本身就是空串 → 空串
        var_dump(substr("", 0, 0));

        // 负 start + 正 length，start 计算后 = 1
        var_dump(substr("hello", -4, 4));

        // 负 start，start 计算后 = 0
        var_dump(substr("hello world", -5, 0));

        // 负 start 超过字符串长度 → 从 0 开始
        var_dump(substr("hello", -100, 0));
    }
}
