<?php
// 对应 PHP ext/standard/tests/math/base_convert.phpt
#debug ===== dechex / hexdec =====
#debug string(2) "ff"
#debug int(255)
#debug string(8) "7fffffff"
#debug int(2147483647)
#debug ===== decbin / bindec =====
#debug string(4) "1010"
#debug int(10)
#debug string(8) "11111111"
#debug int(255)
#debug ===== decoct / octdec =====
#debug string(3) "100"
#debug int(64)
#debug string(3) "777"
#debug int(511)

class Main
{
    public function main(): void
    {
        echo "===== dechex / hexdec =====\n";
        var_dump(dechex(255));
        var_dump(hexdec("ff"));
        var_dump(dechex(2147483647));
        var_dump(hexdec("7fffffff"));
        echo "===== decbin / bindec =====\n";
        var_dump(decbin(10));
        var_dump(bindec("1010"));
        var_dump(decbin(255));
        var_dump(bindec("11111111"));
        echo "===== decoct / octdec =====\n";
        var_dump(decoct(64));
        var_dump(octdec("100"));
        var_dump(decoct(511));
        var_dump(octdec("777"));
    }
}
