<?php
// 对应 PHP ext/standard/tests/math/abs_basic.phpt
#debug ===== abs int =====
#debug int(42)
#debug int(42)
#debug int(0)
#debug ===== abs float =====
#debug float(3.14)
#debug float(3.14)

class Main
{
    public function main(): void
    {
        echo "===== abs int =====\n";
        var_dump(abs(42));
        var_dump(abs(-42));
        var_dump(abs(0));
        echo "===== abs float =====\n";
        var_dump(abs(3.14));
        var_dump(abs(-3.14));
    }
}
