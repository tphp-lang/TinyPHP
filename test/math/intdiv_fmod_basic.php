<?php
// 对应 PHP ext/standard/tests/math/intdiv_basic.phpt
// PHP intdiv 向零截断
#debug ===== intdiv =====
#debug int(3)
#debug int(-3)
#debug int(-3)
#debug int(3)
#debug ===== fmod =====
#debug float(0.5)

class Main
{
    public function main(): void
    {
        echo "===== intdiv =====\n";
        var_dump(intdiv(7, 2));
        var_dump(intdiv(-7, 2));
        var_dump(intdiv(7, -2));
        var_dump(intdiv(-7, -2));
        echo "===== fmod =====\n";
        var_dump(fmod(5.7, 1.3));
    }
}
