<?php
// 对应 PHP ext/standard/tests/math/sqrt_basic.phpt
#debug ===== sqrt =====
#debug float(3)
#debug float(1.4142135623731)
#debug float(0)
#debug ===== sqrt more =====
#debug float(2)
#debug float(5)
#debug float(10)

class Main
{
    public function main(): void
    {
        echo "===== sqrt =====\n";
        var_dump(sqrt(9));
        var_dump(sqrt(2));
        var_dump(sqrt(0));
        echo "===== sqrt more =====\n";
        var_dump(sqrt(4));
        var_dump(sqrt(25));
        var_dump(sqrt(100));
    }
}
