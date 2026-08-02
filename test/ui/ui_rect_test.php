<?php
// @skip:darwin — sokol_app.h #import <AppKit/AppKit.h> 需 ObjC 模式,与 types.h 冲突
// test/ui/ui_rect_test.php — UI\Rect 值对象单元测试
//
// 验证 Rect 构造、contains 半开区间边界判定、零尺寸、负坐标。
// contains 实现：x ∈ [x, x+width) 且 y ∈ [y, y+height)（左闭右开）。
// 纯逻辑测试，不打开窗口、不调用 sokol、不依赖 GPU。

#import ui

#debug === UI Rect Test ===
#debug
#debug -- Construct --
#debug 1. rect x=10 y=20 w=100 h=50
#debug
#debug -- contains boundary (half-open [x,x+w) x [y,y+h)) --
#debug 2. contains(10,20)=1
#debug 3. contains(110,70)=0
#debug 4. contains(10,69)=1
#debug 5. contains(109,70)=0
#debug 6. contains(109,69)=1
#debug
#debug -- zero size --
#debug 7. zero(5,5,0,0) contains(5,5)=0
#debug 8. zero(5,5,0,0) contains(0,0)=0
#debug
#debug -- negative origin --
#debug 9. neg(-10,-10,50,50) contains(-10,-10)=1
#debug 10. neg(-10,-10,50,50) contains(39,39)=1
#debug 11. neg(-10,-10,50,50) contains(40,40)=0
#debug 12. neg(-10,-10,50,50) contains(41,40)=0
#debug
#debug === OK ===

use UI\Rect;

class Main
{
    public function main(): void
    {
        echo "=== UI Rect Test ===\n\n";

        // ═══ 1. 构造 ═══
        echo "-- Construct --\n";
        $r = new Rect(10, 20, 100, 50);
        echo "1. rect x=" . $r->x . " y=" . $r->y . " w=" . $r->width . " h=" . $r->height . "\n";

        // ═══ 2. contains 边界（半开区间）═══
        echo "\n-- contains boundary (half-open [x,x+w) x [y,y+h)) --\n";
        // 左上角 (10,20)：x=10>=10 且 <110，y=20>=20 且 <70 → true
        echo "2. contains(10,20)=" . ($r->contains(10, 20) ? 1 : 0) . "\n";
        // 右下角 (110,70)：x=110 不<110 → false
        echo "3. contains(110,70)=" . ($r->contains(110, 70) ? 1 : 0) . "\n";
        // 左下角 (10,69)：x=10 ok，y=69<70 → true
        echo "4. contains(10,69)=" . ($r->contains(10, 69) ? 1 : 0) . "\n";
        // 右上角 (109,70)：y=70 不<70 → false
        echo "5. contains(109,70)=" . ($r->contains(109, 70) ? 1 : 0) . "\n";
        // 内部点 (109,69)：均满足 → true
        echo "6. contains(109,69)=" . ($r->contains(109, 69) ? 1 : 0) . "\n";

        // ═══ 3. 零尺寸矩形 ═══
        echo "\n-- zero size --\n";
        $z = new Rect(5, 5, 0, 0);
        // width=0 → x < x+0 不成立 → 任何点都不包含
        echo "7. zero(5,5,0,0) contains(5,5)=" . ($z->contains(5, 5) ? 1 : 0) . "\n";
        echo "8. zero(5,5,0,0) contains(0,0)=" . ($z->contains(0, 0) ? 1 : 0) . "\n";

        // ═══ 4. 负坐标 ═══
        echo "\n-- negative origin --\n";
        $n = new Rect(-10, -10, 50, 50);  // 覆盖 [-10,40) x [-10,40)
        // 左上角
        echo "9. neg(-10,-10,50,50) contains(-10,-10)=" . ($n->contains(-10, -10) ? 1 : 0) . "\n";
        // 内部接近右下角 (39,39)：39<40 → true
        echo "10. neg(-10,-10,50,50) contains(39,39)=" . ($n->contains(39, 39) ? 1 : 0) . "\n";
        // 右下角边界 (40,40)：40 不<40 → false
        echo "11. neg(-10,-10,50,50) contains(40,40)=" . ($n->contains(40, 40) ? 1 : 0) . "\n";
        // 越界 (41,40)：x=41 不<40 → false
        echo "12. neg(-10,-10,50,50) contains(41,40)=" . ($n->contains(41, 40) ? 1 : 0) . "\n";

        echo "\n=== OK ===\n";
    }
}
