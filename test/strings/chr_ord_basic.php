<?php
// 对应 PHP ext/standard/tests/strings/chr_basic.phpt
// 对应 PHP ext/standard/tests/strings/ord_basic.phpt
// 测试 chr() / ord() 基本功能 + 往返（roundtrip）
#debug int(97)
#debug int(122)
#debug int(48)
#debug int(57)
#debug int(33)
#debug int(10)
#debug int(255)
#debug string(1) "A"
#debug string(1) "a"
#debug string(1) "0"
#debug string(1) "!"
#debug roundtrip-ok

class Main
{
    public function main(): void
    {
        // ord — 返回首字节
        var_dump(ord("a"));
        var_dump(ord("z"));
        var_dump(ord("0"));
        var_dump(ord("9"));
        var_dump(ord("!"));
        var_dump(ord("\n"));
        var_dump(ord("\xFF"));

        // chr — 整数 → 单字符字符串
        // 注：chr(10) 的 var_dump 输出为多行（含字面换行），
        // #debug 单行无法匹配，已移除；该用例由下方 roundtrip 覆盖。
        var_dump(chr(65));
        var_dump(chr(97));
        var_dump(chr(48));
        var_dump(chr(33));

        // 往返： ord(chr(n)) == n
        $ok = 1;
        $i = 0;
        while ($i < 255) {
            if (ord(chr($i)) != $i) {
                $ok = 0;
            }
            $i = $i + 1;
        }
        if ($ok == 1) {
            echo "roundtrip-ok\n";
        } else {
            echo "roundtrip-FAIL\n";
        }
    }
}
