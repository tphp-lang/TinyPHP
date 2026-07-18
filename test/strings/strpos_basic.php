<?php
// 对应 PHP ext/standard/tests/strings/strpos.phpt
// 测试 strpos() 基本功能
// tphp 差异：
//   1. 未找到返回 int(-1)（非 bool(false)）— 与 PHP 语义不同
//   2. 无 $offset 第三参数
//   3. strrpos / stripos / strripos 均未实现 → @skip
#debug int(0)
#debug int(5)
#debug int(5)
#debug int(3)
#debug int(10)
#debug int(2)
#debug int(-1)
#debug int(-1)
#debug int(0)
#debug int(0)
#debug int(-1)
#debug int(1)

class Main
{
    public function main(): void
    {
        // 基本查找（参考 strpos.phpt）
        var_dump(strpos("test string", "test"));
        var_dump(strpos("test string", "string"));
        var_dump(strpos("test string", "strin"));
        var_dump(strpos("test string", "t s"));
        var_dump(strpos("test string", "g"));
        var_dump(strpos("te" . chr(0) . "st", chr(0)));

        // 大小写敏感：找不到返回 -1（PHP 是 false）
        var_dump(strpos("tEst", "test"));
        var_dump(strpos("teSt", "test"));

        // 空字符串行为
        var_dump(strpos("", ""));
        var_dump(strpos("a", ""));
        var_dump(strpos("", "a"));

        // 反斜杠匹配
        var_dump(strpos("\\\\a", "\\a"));
    }
}
