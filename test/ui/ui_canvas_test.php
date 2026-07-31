<?php
// test/ui/ui_canvas_test.php — UI\CanvasLayout 布局单元测试
//
// 验证 CanvasLayout 绝对定位：addWidget 设置子项 bounds、多子项坐标、
// updateLayout 重新应用 childX/Y/W/H、contains 命中判定。
// 不打开窗口、不调用 sokol、不依赖 GPU。

#import ui

#debug === UI CanvasLayout Test ===
#debug
#debug -- Absolute positioning --
#debug 1. btn1 bounds=10,20,80,24
#debug 2. btn2 bounds=100,50,60,24
#debug 3. btn3 bounds=200,80,40,24
#debug
#debug -- updateLayout reapplies childX --
#debug 4. btn1 x after childX change=50
#debug
#debug -- contains --
#debug 5. canvas pointInside(100,100)=1
#debug 6. canvas pointInside(250,100)=0
#debug
#debug === OK ===

use UI\CanvasLayout;
use UI\Button;

class Main
{
    public function main(): void
    {
        echo "=== UI CanvasLayout Test ===\n\n";

        // ═══ 1. 绝对定位（addWidget 直接设置子项 bounds）═══
        echo "-- Absolute positioning --\n";
        $canvas = new CanvasLayout();
        $btn1 = new Button("A");
        $btn2 = new Button("B");
        $btn3 = new Button("C");
        $canvas->addWidget($btn1, 10, 20, 80, 24);
        $canvas->addWidget($btn2, 100, 50, 60, 24);
        $canvas->addWidget($btn3, 200, 80, 40, 24);
        echo "1. btn1 bounds=" . $btn1->bounds->x . "," . $btn1->bounds->y . "," . $btn1->bounds->width . "," . $btn1->bounds->height . "\n";
        echo "2. btn2 bounds=" . $btn2->bounds->x . "," . $btn2->bounds->y . "," . $btn2->bounds->width . "," . $btn2->bounds->height . "\n";
        echo "3. btn3 bounds=" . $btn3->bounds->x . "," . $btn3->bounds->y . "," . $btn3->bounds->width . "," . $btn3->bounds->height . "\n";

        // ═══ 2. updateLayout 重新应用 childX ═══
        echo "\n-- updateLayout reapplies childX --\n";
        // 修改 childX[0] 后调用 updateLayout，子项 x 应更新为 childX[0]
        $canvas->childX[0] = 50;
        $canvas->updateLayout();
        echo "4. btn1 x after childX change=" . $btn1->bounds->x . "\n";

        // ═══ 3. contains 判定（CanvasLayout bounds 200x200）═══
        echo "\n-- contains --\n";
        $canvas2 = new CanvasLayout();
        $canvas2->proposeSize(200, 200);  // width=200, height=200
        $canvas2->setPos(0, 0);  // bounds=(0,0,200,200)
        echo "5. canvas pointInside(100,100)=" . ($canvas2->pointInside(100, 100) ? 1 : 0) . "\n";
        // (250,100) 不在 [0,200) 内 → false
        echo "6. canvas pointInside(250,100)=" . ($canvas2->pointInside(250, 100) ? 1 : 0) . "\n";

        echo "\n=== OK ===\n";
    }
}
