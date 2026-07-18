<?php
// 对应 PHP ext/standard/tests/math/ceil_basic.phpt
#debug ===== ceil =====
#debug float(4)
#debug float(-3)
#debug float(0)
#debug ===== floor =====
#debug float(3)
#debug float(-4)
#debug float(0)
#debug ===== round =====
#debug float(4)
#debug float(-4)
#debug float(3)
#debug float(4)

class Main
{
    public function main(): void
    {
        echo "===== ceil =====\n";
        var_dump(ceil(3.2));
        var_dump(ceil(-3.2));
        var_dump(ceil(0.0));
        echo "===== floor =====\n";
        var_dump(floor(3.8));
        var_dump(floor(-3.8));
        var_dump(floor(0.0));
        echo "===== round =====\n";
        // PHP round 半值远离零
        var_dump(round(3.5));
        var_dump(round(-3.5));
        var_dump(round(3.4));
        var_dump(round(3.56789));
    }
}
