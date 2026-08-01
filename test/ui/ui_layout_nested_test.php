<?php
// @skip:darwin+tcc — TCC on macOS 不支持 -framework 链接选项（gcc/clang 正常）
// test/ui/ui_layout_nested_test.php — UI 嵌套布局单元测试
//
// 验证 Stack 内 Stack 的递归布局：外层 updateLayout 后需手动调用内层 updateLayout
// 以重新排列内层子项（外层会覆写内层 bounds 的交叉轴尺寸）。
// 另验证 3 层嵌套 updateLayout 不出错。
//
// 注意：Stack 静态分派，Compact 模式读取子项预 proposeSize 设置的 bounds 主轴尺寸。
// 不打开窗口、不调用 sokol、不依赖 GPU。

#import ui

#debug === UI Layout Nested Test ===
#debug
#debug -- Stack in Stack (Row outer, Column inner) --
#debug 1. inner1 btnA bounds=0,0,40,24
#debug 2. inner1 btnB bounds=0,24,40,24
#debug 3. inner2 btnC bounds=40,0,40,24
#debug
#debug -- proposeSize (outer) --
#debug 4. outer proposeSize w>=80 -> 1 (w=80)
#debug
#debug -- Deep nesting (3 levels) --
#debug 5. deep btn bounds=0,0,40,24
#debug 6. deep ok=1
#debug
#debug === OK ===

use UI\Stack;
use UI\Button;
use UI\Direction;
use UI\ChildSize;

class Main
{
    public function makeBtn(string $t): Button
    {
        $b = new Button($t);
        $b->proposeSize(1000, 1000);  // w=40(min), h=24
        return $b;
    }

    public function main(): void
    {
        echo "=== UI Layout Nested Test ===\n\n";

        // ═══ 1. Stack in Stack ═══
        echo "-- Stack in Stack (Row outer, Column inner) --\n";
        $btnA = $this->makeBtn("A");
        $btnB = $this->makeBtn("B");
        $inner1 = new Stack(Direction::Column->value);
        $inner1->spacing = 0;
        $inner1->padding = 0;
        $inner1->addWidget($btnA, ChildSize::Compact->value, 0);
        $inner1->addWidget($btnB, ChildSize::Compact->value, 0);
        $inner1->bounds->x = 0;
        $inner1->bounds->y = 0;
        $inner1->bounds->width = 40;   // 外层 Row Compact 读取此值作为主轴尺寸
        $inner1->bounds->height = 48;  // 2*24

        $btnC = $this->makeBtn("C");
        $btnD = $this->makeBtn("D");
        $inner2 = new Stack(Direction::Column->value);
        $inner2->spacing = 0;
        $inner2->padding = 0;
        $inner2->addWidget($btnC, ChildSize::Compact->value, 0);
        $inner2->addWidget($btnD, ChildSize::Compact->value, 0);
        $inner2->bounds->x = 0;
        $inner2->bounds->y = 0;
        $inner2->bounds->width = 40;
        $inner2->bounds->height = 48;

        $outer = new Stack(Direction::Row->value);
        $outer->spacing = 0;
        $outer->padding = 0;
        $outer->bounds->x = 0;
        $outer->bounds->y = 0;
        $outer->bounds->width = 200;
        $outer->bounds->height = 100;
        $outer->addWidget($inner1, ChildSize::Compact->value, 0);
        $outer->addWidget($inner2, ChildSize::Compact->value, 0);

        // 外层 updateLayout：inner1→(0,0,40,100), inner2→(40,0,40,100)
        $outer->updateLayout();
        // 手动调用内层 updateLayout（外层覆写了内层 bounds 交叉轴高度为 100）
        $inner1->updateLayout();
        $inner2->updateLayout();
        // inner1(Column, 0,0,40,100): btnA→(0,0,40,24), btnB→(0,24,40,24)
        echo "1. inner1 btnA bounds=" . $btnA->bounds->x . "," . $btnA->bounds->y . "," . $btnA->bounds->width . "," . $btnA->bounds->height . "\n";
        echo "2. inner1 btnB bounds=" . $btnB->bounds->x . "," . $btnB->bounds->y . "," . $btnB->bounds->width . "," . $btnB->bounds->height . "\n";
        // inner2(Column, 40,0,40,100): btnC→(40,0,40,24)
        echo "3. inner2 btnC bounds=" . $btnC->bounds->x . "," . $btnC->bounds->y . "," . $btnC->bounds->width . "," . $btnC->bounds->height . "\n";

        // ═══ 2. proposeSize（外层提议尺寸：主轴=两内层宽度之和）═══
        echo "\n-- proposeSize (outer) --\n";
        // 重置 inner bounds 为预提议尺寸以测 proposeSize
        $inner1->bounds->width = 40;
        $inner1->bounds->height = 48;
        $inner2->bounds->width = 40;
        $inner2->bounds->height = 48;
        $ps = $outer->proposeSize(1000, 1000);
        // Row: totalMain = 40+40 + (2-1)*0 + 2*0 = 80; maxCross = max(48,48)=48
        // 断言主轴宽度 >= 80（合理尺寸）
        $ok = ($ps[0] >= 80) ? 1 : 0;
        echo "4. outer proposeSize w>=" . "80" . " -> " . $ok . " (w=" . $ps[0] . ")\n";

        // ═══ 3. 深层嵌套（3 层 Stack）═══
        echo "\n-- Deep nesting (3 levels) --\n";
        $btn = $this->makeBtn("Z");
        $lvl3 = new Stack(Direction::Row->value);
        $lvl3->spacing = 0;
        $lvl3->padding = 0;
        $lvl3->addWidget($btn, ChildSize::Compact->value, 0);
        $lvl3->bounds->width = 40;
        $lvl3->bounds->height = 24;

        $lvl2 = new Stack(Direction::Column->value);
        $lvl2->spacing = 0;
        $lvl2->padding = 0;
        $lvl2->addWidget($lvl3, ChildSize::Compact->value, 0);
        $lvl2->bounds->width = 40;
        $lvl2->bounds->height = 24;

        $lvl1 = new Stack(Direction::Row->value);
        $lvl1->spacing = 0;
        $lvl1->padding = 0;
        $lvl1->bounds->x = 0;
        $lvl1->bounds->y = 0;
        $lvl1->bounds->width = 200;
        $lvl1->bounds->height = 100;
        $lvl1->addWidget($lvl2, ChildSize::Compact->value, 0);

        $deepOk = 1;
        try {
            $lvl1->updateLayout();
            $lvl2->updateLayout();
            $lvl3->updateLayout();
        } catch (Exception $e) {
            $deepOk = 0;
        }
        // lvl1(Row,0,0,200,100)→lvl2(0,0,40,100); lvl2(Column,0,0,40,100)→lvl3(0,0,40,24); lvl3(Row,0,0,40,24)→btn(0,0,40,24)
        echo "5. deep btn bounds=" . $btn->bounds->x . "," . $btn->bounds->y . "," . $btn->bounds->width . "," . $btn->bounds->height . "\n";
        echo "6. deep ok=" . $deepOk . "\n";

        echo "\n=== OK ===\n";
    }
}
