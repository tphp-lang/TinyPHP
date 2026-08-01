<?php
// @skip:darwin+tcc — TCC on macOS 不支持 -framework 链接选项（gcc/clang 正常）
// test/ui/ui_hit_test_test.php — UI 命中测试单元测试
//
// 验证 WidgetContainer.hitTestIndex：多控件分区命中、z-index 倒序（后添加=上层优先）、
// 重叠遮挡判定、不重叠各自接收、空容器返回 -1。
// 不打开窗口、不调用 sokol、不依赖 GPU。

#import ui

#debug === UI HitTest Test ===
#debug
#debug -- Non-overlapping (3 widgets) --
#debug 1. hitTest(20,10)=0
#debug 2. hitTest(70,10)=1
#debug 3. hitTest(120,10)=2
#debug 4. hitTest(500,500)=-1
#debug
#debug -- Empty container --
#debug 5. empty hitTest(0,0)=-1
#debug
#debug -- Overlap (z-index, later wins) --
#debug 6. overlap hitTest(10,10)=1
#debug 7. overlap hitTest(10,10) upper=2
#debug
#debug === OK ===

use UI\WidgetContainer;
use UI\Button;

class Main
{
    public function makeBtn(string $t, int $x, int $y): Button
    {
        $b = new Button($t);
        $b->proposeSize(1000, 1000);  // w=40, h=24
        $b->setPos($x, $y);
        return $b;
    }

    public function main(): void
    {
        echo "=== UI HitTest Test ===\n\n";

        // ═══ 1. 不重叠的 3 个控件（各居其位）═══
        echo "-- Non-overlapping (3 widgets) --\n";
        $w1 = $this->makeBtn("A", 0, 0);     // bounds=(0,0,40,24)
        $w2 = $this->makeBtn("B", 50, 0);    // bounds=(50,0,40,24)
        $w3 = $this->makeBtn("C", 100, 0);   // bounds=(100,0,40,24)
        $container = new WidgetContainer();
        $container->addChild($w1);  // index 0
        $container->addChild($w2);  // index 1
        $container->addChild($w3);  // index 2
        // (20,10) 仅在 w1 内 → 0
        echo "1. hitTest(20,10)=" . $container->hitTestIndex(20, 10) . "\n";
        // (70,10) 仅在 w2 内 → 1
        echo "2. hitTest(70,10)=" . $container->hitTestIndex(70, 10) . "\n";
        // (120,10) 仅在 w3 内 → 2
        echo "3. hitTest(120,10)=" . $container->hitTestIndex(120, 10) . "\n";
        // (500,500) 不在任何控件内 → -1
        echo "4. hitTest(500,500)=" . $container->hitTestIndex(500, 500) . "\n";

        // ═══ 2. 空容器 ═══
        echo "\n-- Empty container --\n";
        $empty = new WidgetContainer();
        echo "5. empty hitTest(0,0)=" . $empty->hitTestIndex(0, 0) . "\n";

        // ═══ 3. 重叠（z-index：后添加=上层，优先接收）═══
        echo "\n-- Overlap (z-index, later wins) --\n";
        $w4 = $this->makeBtn("X", 0, 0);  // bounds=(0,0,40,24)
        $w5 = $this->makeBtn("Y", 0, 0);  // bounds=(0,0,40,24) 与 w4 完全重叠
        $over = new WidgetContainer();
        $over->addChild($w4);  // index 0（下层）
        $over->addChild($w5);  // index 1（上层）
        // (10,10) 同时在 w4/w5 内，倒序遍历先命中 w5（index 1）→ 1
        echo "6. overlap hitTest(10,10)=" . $over->hitTestIndex(10, 10) . "\n";
        // 再加一个 w6，上层变为 index 2
        $w6 = $this->makeBtn("Z", 0, 0);
        $over->addChild($w6);  // index 2（最上层）
        echo "7. overlap hitTest(10,10) upper=" . $over->hitTestIndex(10, 10) . "\n";

        echo "\n=== OK ===\n";
    }
}
