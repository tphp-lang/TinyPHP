<?php
// @skip:darwin+tcc — TCC on macOS 不支持 -framework 链接选项（gcc/clang 正常）
// test/ui/ui_color_test.php — UI\Color 值对象单元测试
//
// 验证 Color 构造、toUint 转换（0xAABBGGRR 格式）、预定义颜色、边界值。
// 纯逻辑测试，不打开窗口、不调用 sokol、不依赖 GPU。

#import ui

#debug === UI Color Test ===
#debug
#debug -- Construct --
#debug 1. red r=255 g=0 b=0 a=255
#debug 2. custom r=10 g=20 b=30 a=40
#debug 3. default r=0 g=0 b=0 a=255
#debug
#debug -- toUint (0xAABBGGRR) --
#debug 4. red=4278190335
#debug 5. green=4278255360
#debug 6. blue=4294901760
#debug 7. white=4294967295
#debug 8. black=4278190080
#debug 9. custom(10,20,30,40)=673059850
#debug
#debug -- Predefined --
#debug 10. black r=0 g=0 b=0 a=255
#debug 11. white r=255 g=255 b=255 a=255
#debug 12. red r=255 g=0 b=0 a=255
#debug 13. green r=0 g=255 b=0 a=255
#debug 14. blue r=0 g=0 b=255 a=255
#debug
#debug -- Boundary --
#debug 15. min(0,0,0,0)=0
#debug 16. max(255,255,255,255)=4294967295
#debug
#debug === OK ===

use UI\Color;

class Main
{
    public function main(): void
    {
        echo "=== UI Color Test ===\n\n";

        // ═══ 1. 构造 ═══
        echo "-- Construct --\n";
        $red = new Color(255, 0, 0, 255);
        echo "1. red r=" . $red->r . " g=" . $red->g . " b=" . $red->b . " a=" . $red->a . "\n";

        $custom = new Color(10, 20, 30, 40);
        echo "2. custom r=" . $custom->r . " g=" . $custom->g . " b=" . $custom->b . " a=" . $custom->a . "\n";

        $default = new Color();
        echo "3. default r=" . $default->r . " g=" . $default->g . " b=" . $default->b . " a=" . $default->a . "\n";

        // ═══ 2. toUint (0xAABBGGRR 格式) ═══
        echo "\n-- toUint (0xAABBGGRR) --\n";
        // red: a=255,b=0,g=0,r=255 → 0xFF0000FF = 4278190335
        echo "4. red=" . $red->toUint() . "\n";
        // green: a=255,b=0,g=255,r=0 → 0xFF00FF00 = 4278255360
        echo "5. green=" . Color::green()->toUint() . "\n";
        // blue: a=255,b=255,g=0,r=0 → 0xFFFF0000 = 4278190080
        echo "6. blue=" . Color::blue()->toUint() . "\n";
        // white: a=255,b=255,g=255,r=255 → 0xFFFFFFFF = 4294967295
        echo "7. white=" . Color::white()->toUint() . "\n";
        // black: a=255,b=0,g=0,r=0 → 0xFF000000 = 4278190080
        echo "8. black=" . Color::black()->toUint() . "\n";
        // custom(10,20,30,40): a=40,b=30,g=20,r=10 → 0x281E140A = 673720570
        echo "9. custom(10,20,30,40)=" . $custom->toUint() . "\n";

        // ═══ 3. 预定义颜色 ═══
        echo "\n-- Predefined --\n";
        $black = Color::black();
        echo "10. black r=" . $black->r . " g=" . $black->g . " b=" . $black->b . " a=" . $black->a . "\n";
        $white = Color::white();
        echo "11. white r=" . $white->r . " g=" . $white->g . " b=" . $white->b . " a=" . $white->a . "\n";
        $predRed = Color::red();
        echo "12. red r=" . $predRed->r . " g=" . $predRed->g . " b=" . $predRed->b . " a=" . $predRed->a . "\n";
        $green = Color::green();
        echo "13. green r=" . $green->r . " g=" . $green->g . " b=" . $green->b . " a=" . $green->a . "\n";
        $blue = Color::blue();
        echo "14. blue r=" . $blue->r . " g=" . $blue->g . " b=" . $blue->b . " a=" . $blue->a . "\n";

        // ═══ 4. 边界值 ═══
        echo "\n-- Boundary --\n";
        $min = new Color(0, 0, 0, 0);
        echo "15. min(0,0,0,0)=" . $min->toUint() . "\n";
        $max = new Color(255, 255, 255, 255);
        echo "16. max(255,255,255,255)=" . $max->toUint() . "\n";

        echo "\n=== OK ===\n";
    }
}
