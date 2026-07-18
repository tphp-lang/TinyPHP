<?php
// 对应 PHP ext/standard/tests/math/log_basic.phpt
#debug ===== exp =====
#debug float(1)
#debug float(2.718281828459)
#debug float(7.3890560989307)
#debug ===== log =====
#debug float(0)
#debug float(0.99999999999998)
#debug float(0.99999999999998)
#debug ===== log10 =====
#debug float(2)
#debug float(3)
#debug float(-2)

class Main
{
    public function main(): void
    {
        echo "===== exp =====\n";
        var_dump(exp(0));
        var_dump(exp(1));
        var_dump(exp(2));
        echo "===== log =====\n";
        var_dump(log(1));
        var_dump(log(2.718281828459));
        var_dump(log(2.718281828459));
        echo "===== log10 =====\n";
        var_dump(log10(100));
        var_dump(log10(1000));
        var_dump(log10(0.01));
    }
}
