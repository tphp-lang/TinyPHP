<?php
// @skip:darwin+tcc — TCC 不支持 -x objective-c 和 -framework 链接（clang/gcc 正常）
// test/ui/ui_label_test.php — UI\Label 控件单元测试
//
// 验证 Label 构造、默认/自定义属性、setPos + pointInside 命中、
// proposeSize 尺寸、未 init 时 draw() 抛 Exception。
// 不打开窗口、不调用 sokol、不依赖 GPU。

#import ui

#debug === UI Label Test ===
#debug
#debug -- Construct --
#debug 1. text=Hello
#debug 2. default color=255,255,255,255
#debug 3. default fontSize=14
#debug
#debug -- Custom props --
#debug 4. custom color=255,0,0,255
#debug 5. custom fontSize=20
#debug
#debug -- proposeSize --
#debug 6. proposeSize(Hello,14)=35,18
#debug
#debug -- pointInside --
#debug 7. bounds=10,10,35,18
#debug 8. pointInside(15,15)=1
#debug 9. pointInside(50,15)=0
#debug
#debug -- draw --
#debug 10. draw before init throws=1
#debug
#debug === OK ===

use UI\Label;
use UI\Color;

class Main
{
    public function main(): void
    {
        echo "=== UI Label Test ===\n\n";

        // ═══ 1. 构造 + 默认属性 ═══
        echo "-- Construct --\n";
        $label = new Label("Hello");
        echo "1. text=" . $label->text . "\n";
        echo "2. default color=" . $label->color->r . "," . $label->color->g . "," . $label->color->b . "," . $label->color->a . "\n";
        echo "3. default fontSize=" . $label->fontSize . "\n";

        // ═══ 2. 自定义属性 ═══
        echo "\n-- Custom props --\n";
        $label->color = Color::red();
        $label->fontSize = 20;
        echo "4. custom color=" . $label->color->r . "," . $label->color->g . "," . $label->color->b . "," . $label->color->a . "\n";
        echo "5. custom fontSize=" . $label->fontSize . "\n";

        // ═══ 3. proposeSize ═══
        echo "\n-- proposeSize --\n";
        $l2 = new Label("Hello");  // fontSize=14, "Hello"=5 字符
        $sz = $l2->proposeSize(1000, 1000);
        // w = strlen("Hello") * (fontSize/2) = 5 * 7 = 35；h = fontSize+4 = 18
        echo "6. proposeSize(Hello,14)=" . $sz[0] . "," . $sz[1] . "\n";

        // ═══ 4. pointInside 命中 ═══
        echo "\n-- pointInside --\n";
        $l2->setPos(10, 10);  // bounds 已由 proposeSize 设置 width=35,height=18
        echo "7. bounds=" . $l2->bounds->x . "," . $l2->bounds->y . "," . $l2->bounds->width . "," . $l2->bounds->height . "\n";
        echo "8. pointInside(15,15)=" . ($l2->pointInside(15, 15) ? 1 : 0) . "\n";
        // x=50 不在 [10,45) 内 → false
        echo "9. pointInside(50,15)=" . ($l2->pointInside(50, 15) ? 1 : 0) . "\n";

        // ═══ 5. draw 未 init 抛异常 ═══
        echo "\n-- draw --\n";
        $l3 = new Label("Z");
        $threw = 0;
        try {
            $l3->draw();
        } catch (Exception $e) {
            $threw = 1;
        }
        echo "10. draw before init throws=" . $threw . "\n";

        echo "\n=== OK ===\n";
    }
}
