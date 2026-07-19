<?php
// 对应 PHP ext/standard/tests/math/trig_basic.phpt
#debug ===== pi =====
#debug float(3.1415926535898)
#debug ===== deg2rad / rad2deg =====
#debug float(0)
#debug float(3.1415926535898)
#debug float(1.5707963267949)
#debug float(0)
#debug float(90)
#debug float(180)
#debug ===== sin =====
#debug float(0)
#debug float(0.5)
#debug float(1)
#debug ===== cos =====
#debug float(1)
#debug float(0.5)
#debug float(-1)
#debug ===== tan =====
#debug float(0)
#debug float(1)

class Main
{
    public function main(): void
    {
        echo "===== pi =====\n";
        var_dump(pi());
        echo "===== deg2rad / rad2deg =====\n";
        var_dump(deg2rad(0.0));
        var_dump(deg2rad(180.0));
        var_dump(deg2rad(90.0));
        var_dump(rad2deg(0.0));
        var_dump(rad2deg(pi() / 2.0));
        var_dump(rad2deg(pi()));
        echo "===== sin =====\n";
        var_dump(sin(0.0));
        var_dump(sin(pi() / 6.0));
        var_dump(sin(pi() / 2.0));
        echo "===== cos =====\n";
        var_dump(cos(0.0));
        var_dump(cos(pi() / 3.0));
        // cos(pi) = -1（避免 cos(pi/2) 浮点精度在不同平台 printf 格式不同：e-017 vs e-17）
        var_dump(cos(pi()));
        echo "===== tan =====\n";
        var_dump(tan(0.0));
        var_dump(tan(pi() / 4.0));
    }
}
