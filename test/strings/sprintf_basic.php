<?php
// 对应 PHP ext/standard/tests/strings/sprintf_basic1.phpt
// 对应 PHP ext/standard/tests/strings/sprintf_basic2.phpt
// 测试 sprintf 基本格式符： %d %s %f %x %o %c %% %5d %-5d %05d %.2f
// 注： %b（二进制）和 %'.9f（自定义填充字符）为 PHP 特有格式，
//      C 的 snprintf 不支持，tphp 不实现（见末尾 @skip 段落）。
#debug string(6) "format"
#debug string(13) "arg1 argument"
#debug string(27) "arg1 argument arg2 argument"
#debug string(3) "111"
#debug string(7) "111 222"
#debug string(11) "111 222 333"
#debug string(1) "A"
#debug string(3) "100"
#debug string(2) "ff"
#debug string(3) "144"
#debug string(4) "3.14"
#debug string(4) "100%"
#debug string(5) "  100"
#debug string(5) "100  "
#debug string(5) "00100"
#debug string(4) "3.14"

class Main
{
    public function main(): void
    {
        // 字符串格式（参考 sprintf_basic1.phpt）
        var_dump(sprintf("format"));
        var_dump(sprintf("%s", "arg1 argument"));
        var_dump(sprintf("%s %s", "arg1 argument", "arg2 argument"));

        // 整数格式（参考 sprintf_basic2.phpt）
        var_dump(sprintf("%d", 111));
        var_dump(sprintf("%d %d", 111, 222));
        var_dump(sprintf("%d %d %d", 111, 222, 333));

        // %c — 字符
        var_dump(sprintf("%c", 65));

        // %x / %o — 十六进制 / 八进制
        var_dump(sprintf("%d", 100));
        var_dump(sprintf("%x", 255));
        var_dump(sprintf("%o", 100));

        // %f / %.2f — 浮点
        var_dump(sprintf("%.2f", 3.14159));

        // %% — 字面百分号
        var_dump(sprintf("100%%"));

        // %5d — 右对齐宽度 5
        var_dump(sprintf("%5d", 100));

        // %-5d — 左对齐宽度 5
        var_dump(sprintf("%-5d", 100));

        // %05d — 零填充宽度 5
        var_dump(sprintf("%05d", 100));

        // %.2f — 两位小数
        var_dump(sprintf("%.2f", 3.14159));
    }
}
