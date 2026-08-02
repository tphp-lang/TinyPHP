<?php
// @skip:darwin — sokol_app.h #import <AppKit/AppKit.h> 需 ObjC 模式,与 types.h 冲突
// @skip:windows+clang — clang 编译 Win32 头时 SAL 注解未定义,需 MSVC 头文件兼容
// test/ui/ui_stack_test.php — UI\Stack 布局单元测试
//
// 验证 Stack Row/Column 排列、Compact/Stretch/Fixed 尺寸模式、spacing/padding 应用、
// Stack::row / Stack::column 便捷构造。
//
// 注意：Stack 静态分派，Compact 模式读取子项预先通过 proposeSize 设置的 bounds 主轴尺寸，
// 因此 addWidget 前需对子项调用 proposeSize。
// 不打开窗口、不调用 sokol、不依赖 GPU。

#import ui

#debug === UI Stack Test ===
#debug
#debug -- Row direction --
#debug 1. child0 x=0
#debug 2. child1 x=40
#debug 3. child2 x=80
#debug
#debug -- Column direction --
#debug 4. child0 y=0
#debug 5. child1 y=24
#debug 6. child2 y=48
#debug
#debug -- Stretch mode --
#debug 7. stretch0 x=0 w=100
#debug 8. stretch1 x=100 w=100
#debug 9. stretch2 x=200 w=100
#debug
#debug -- Fixed mode --
#debug 10. fixed0 x=0 w=50
#debug 11. fixed1 x=50 w=50
#debug
#debug -- spacing --
#debug 12. spacing child0 x=0
#debug 13. spacing child1 x=50
#debug
#debug -- padding --
#debug 14. padding child0 x=5
#debug
#debug -- Convenience ctor --
#debug 15. row(a,b) count=2 dir=0
#debug 16. column(a,b) count=2 dir=1
#debug
#debug === OK ===

use UI\Stack;
use UI\Button;
use UI\Direction;
use UI\ChildSize;

// 便捷构造：生成已 proposeSize 的 Button（"A"→w=40,h=24）
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
        echo "=== UI Stack Test ===\n\n";

        // ═══ 1. Row 方向（3 Button，Compact）═══
        echo "-- Row direction --\n";
        $b1 = $this->makeBtn("A");
        $b2 = $this->makeBtn("B");
        $b3 = $this->makeBtn("C");
        $row = new Stack(Direction::Row->value);
        $row->spacing = 0;
        $row->padding = 0;
        $row->bounds->x = 0;
        $row->bounds->y = 0;
        $row->bounds->width = 300;
        $row->bounds->height = 30;
        $row->addWidget($b1, ChildSize::Compact->value, 0);
        $row->addWidget($b2, ChildSize::Compact->value, 0);
        $row->addWidget($b3, ChildSize::Compact->value, 0);
        $row->updateLayout();
        // ms=40 each: child0 x=0, child1 x=40, child2 x=80
        echo "1. child0 x=" . $b1->bounds->x . "\n";
        echo "2. child1 x=" . $b2->bounds->x . "\n";
        echo "3. child2 x=" . $b3->bounds->x . "\n";

        // ═══ 2. Column 方向（3 Button，Compact）═══
        echo "\n-- Column direction --\n";
        $c1 = $this->makeBtn("A");
        $c2 = $this->makeBtn("B");
        $c3 = $this->makeBtn("C");
        $col = new Stack(Direction::Column->value);
        $col->spacing = 0;
        $col->padding = 0;
        $col->bounds->x = 0;
        $col->bounds->y = 0;
        $col->bounds->width = 100;
        $col->bounds->height = 300;
        $col->addWidget($c1, ChildSize::Compact->value, 0);
        $col->addWidget($c2, ChildSize::Compact->value, 0);
        $col->addWidget($c3, ChildSize::Compact->value, 0);
        $col->updateLayout();
        // ms=24 each (Button height): child0 y=0, child1 y=24, child2 y=48
        echo "4. child0 y=" . $c1->bounds->y . "\n";
        echo "5. child1 y=" . $c2->bounds->y . "\n";
        echo "6. child2 y=" . $c3->bounds->y . "\n";

        // ═══ 3. Stretch 模式（3 子项均分 300）═══
        echo "\n-- Stretch mode --\n";
        $s1 = $this->makeBtn("A");
        $s2 = $this->makeBtn("B");
        $s3 = $this->makeBtn("C");
        $rowS = new Stack(Direction::Row->value);
        $rowS->spacing = 0;
        $rowS->padding = 0;
        $rowS->bounds->width = 300;
        $rowS->bounds->height = 30;
        $rowS->addWidget($s1, ChildSize::Stretch->value, 0);
        $rowS->addWidget($s2, ChildSize::Stretch->value, 0);
        $rowS->addWidget($s3, ChildSize::Stretch->value, 0);
        $rowS->updateLayout();
        // stretchSize = 300/3 = 100: child0 x=0 w=100, child1 x=100 w=100, child2 x=200 w=100
        echo "7. stretch0 x=" . $s1->bounds->x . " w=" . $s1->bounds->width . "\n";
        echo "8. stretch1 x=" . $s2->bounds->x . " w=" . $s2->bounds->width . "\n";
        echo "9. stretch2 x=" . $s3->bounds->x . " w=" . $s3->bounds->width . "\n";

        // ═══ 4. Fixed 模式（指定 50）═══
        echo "\n-- Fixed mode --\n";
        $f1 = $this->makeBtn("A");
        $f2 = $this->makeBtn("B");
        $rowF = new Stack(Direction::Row->value);
        $rowF->spacing = 0;
        $rowF->padding = 0;
        $rowF->bounds->width = 300;
        $rowF->bounds->height = 30;
        $rowF->addWidget($f1, ChildSize::Fixed->value, 50);
        $rowF->addWidget($f2, ChildSize::Fixed->value, 50);
        $rowF->updateLayout();
        // child0 x=0 w=50, child1 x=50 w=50
        echo "10. fixed0 x=" . $f1->bounds->x . " w=" . $f1->bounds->width . "\n";
        echo "11. fixed1 x=" . $f2->bounds->x . " w=" . $f2->bounds->width . "\n";

        // ═══ 5. spacing（间距=10）═══
        echo "\n-- spacing --\n";
        $sp1 = $this->makeBtn("A");
        $sp2 = $this->makeBtn("B");
        $rowSp = new Stack(Direction::Row->value);
        $rowSp->spacing = 10;
        $rowSp->padding = 0;
        $rowSp->bounds->width = 300;
        $rowSp->bounds->height = 30;
        $rowSp->addWidget($sp1, ChildSize::Compact->value, 0);
        $rowSp->addWidget($sp2, ChildSize::Compact->value, 0);
        $rowSp->updateLayout();
        // child0 x=0(w=40), pos=0+40+10=50; child1 x=50
        echo "12. spacing child0 x=" . $sp1->bounds->x . "\n";
        echo "13. spacing child1 x=" . $sp2->bounds->x . "\n";

        // ═══ 6. padding（内边距=5）═══
        echo "\n-- padding --\n";
        $p1 = $this->makeBtn("A");
        $rowP = new Stack(Direction::Row->value);
        $rowP->spacing = 0;
        $rowP->padding = 5;
        $rowP->bounds->x = 0;
        $rowP->bounds->y = 0;
        $rowP->bounds->width = 300;
        $rowP->bounds->height = 30;
        $rowP->addWidget($p1, ChildSize::Compact->value, 0);
        $rowP->updateLayout();
        // startMain=padding=5; child0 x=0+5=5
        echo "14. padding child0 x=" . $p1->bounds->x . "\n";

        // ═══ 7. 便捷构造 ═══
        echo "\n-- Convenience ctor --\n";
        $a = $this->makeBtn("A");
        $b = $this->makeBtn("B");
        $rowC = Stack::row($a, $b);
        echo "15. row(a,b) count=" . count($rowC->children) . " dir=" . $rowC->direction . "\n";
        $colC = Stack::column($a, $b);
        echo "16. column(a,b) count=" . count($colC->children) . " dir=" . $colC->direction . "\n";

        echo "\n=== OK ===\n";
    }
}
