<?php
// 对应 PHP ext/standard/tests/math/max_basic.phpt
#debug ===== max array =====
#debug int(3)
#debug int(5)
#debug float(2.5)
#debug ===== min array =====
#debug int(1)
#debug int(-5)
#debug float(0.5)
#debug ===== max variadic =====
#debug int(3)
#debug int(5)
#debug ===== min variadic =====
#debug int(1)
#debug int(-5)

class Main
{
    public function main(): void
    {
        echo "===== max array =====\n";
        var_dump(max([1, 2, 3]));
        var_dump(max([-5, 0, 5]));
        var_dump(max([1.5, 2.5, 0.5]));
        echo "===== min array =====\n";
        var_dump(min([1, 2, 3]));
        var_dump(min([-5, 0, 5]));
        var_dump(min([1.5, 2.5, 0.5]));
        echo "===== max variadic =====\n";
        var_dump(max(1, 2, 3));
        var_dump(max(-5, 0, 5));
        echo "===== min variadic =====\n";
        var_dump(min(1, 2, 3));
        var_dump(min(-5, 0, 5));
    }
}
