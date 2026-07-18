<?php
// 对应 PHP ext/standard/tests/math/pow_basic.phpt
#debug ===== pow int =====
#debug int(1024)
#debug int(1)
#debug int(8)
#debug ===== pow float =====
#debug float(1.4142135623731)
#debug float(8)
#debug ===== pow negative exp =====
#debug float(0.5)

class Main
{
    public function main(): void
    {
        echo "===== pow int =====\n";
        var_dump(pow(2, 10));
        var_dump(pow(2, 0));
        var_dump(pow(2, 3));
        echo "===== pow float =====\n";
        var_dump(pow(2.0, 0.5));
        var_dump(pow(2.0, 3.0));
        echo "===== pow negative exp =====\n";
        var_dump(pow(2, -1));
    }
}
