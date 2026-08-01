<?php
// @skip:darwin+tcc — TCC on macOS 不支持 -framework 链接选项（gcc/clang 正常）
// test/ui/ui_slider_test.php — UI\Slider 控件单元测试
//
// 验证 Slider 构造、值夹紧、setValue、onChange 回调、拖动模拟、范围为 0 处理。
//
// 已知 TinyPHP 语义差异：int / int 执行 C 式整除（截断），而非 PHP 的浮点除法。
// Slider 源码 updateValueFromX 中 ratio = $relX / $w（两 int）在非满比例拖动时
// 得 0，故 beginDrag(50,0) 实际 value=0（规范预期 ≈50）。端点拖动（0 与 100）
// 不受影响。本测试端点/回调/range0 各节能通过；中点拖动断言按规范预期声明，
// 因上述整除语义失败（待 Slider 源码改用浮点除法或 CodeGenerator 调整后通过）。
//
// 不打开窗口、不调用 sokol、不依赖 GPU。

#import ui

#debug === UI Slider Test ===
#debug
#debug -- Construct --
#debug 1. min=0 max=100 value=50
#debug 2. clamp high value=100
#debug 3. clamp low value=0
#debug
#debug -- setValue --
#debug 4. setValue(200)=100
#debug 5. setValue(-5)=0
#debug
#debug -- onChange (endpoint drag) --
#debug 6. onChange fired=1 with=100
#debug
#debug -- Drag endpoints (bounds width=100) --
#debug 7. beginDrag(0,0)=0
#debug 8. beginDrag(100,0)=100
#debug
#debug -- Range 0 --
#debug 9. range0 value=50
#debug 10. range0 after drag value=50
#debug
#debug -- Drag midpoint (int-division affected) --
#debug 11. beginDrag(50,0)=50
#debug
#debug === OK ===

use UI\Slider;

// 回调状态跟踪器（PHP 对象语义：use($obj) 捕获句柄，闭包内可修改对象属性）
class SliderTracker
{
    public bool $fired = false;
    public int $intVal = -1;
}

class Main
{
    public function main(): void
    {
        echo "=== UI Slider Test ===\n\n";

        // ═══ 1. 构造 + 值夹紧 ═══
        echo "-- Construct --\n";
        $s1 = new Slider(0, 100, 50);
        echo "1. min=" . $s1->min . " max=" . $s1->max . " value=" . $s1->value . "\n";
        $s2 = new Slider(0, 100, 150);  // 夹紧到 max
        echo "2. clamp high value=" . $s2->value . "\n";
        $s3 = new Slider(0, 100, -10);  // 夹紧到 min
        echo "3. clamp low value=" . $s3->value . "\n";

        // ═══ 2. setValue（夹紧）═══
        echo "\n-- setValue --\n";
        $s4 = new Slider(0, 100, 50);
        $s4->setValue(200);  // → 100
        echo "4. setValue(200)=" . $s4->value . "\n";
        $s4->setValue(-5);  // → 0
        echo "5. setValue(-5)=" . $s4->value . "\n";

        // ═══ 3. onChange 回调（端点拖动 beginDrag(100,0)，值不受整除影响）═══
        echo "\n-- onChange (endpoint drag) --\n";
        $s5 = new Slider(0, 100, 0);
        $tracker = new SliderTracker();
        $s5->onChange = function(int $value) use ($tracker): void {
            $tracker->fired = true;
            $tracker->intVal = $value;
        };
        $s5->proposeSize(100, 16);  // width=100
        $s5->setPos(0, 0);  // x=0
        $s5->beginDrag(100, 0);  // relX=100, ratio=1, value=100
        echo "6. onChange fired=" . ($tracker->fired ? 1 : 0) . " with=" . $tracker->intVal . "\n";

        // ═══ 4. 拖动端点（不受整除影响）═══
        echo "\n-- Drag endpoints (bounds width=100) --\n";
        $s6 = new Slider(0, 100, 0);
        $s6->proposeSize(100, 16);
        $s6->setPos(0, 0);
        $s6->beginDrag(0, 0);  // relX=0, ratio=0, value=0
        echo "7. beginDrag(0,0)=" . $s6->value . "\n";
        $s6->beginDrag(100, 0);  // relX=100, ratio=1, value=100
        echo "8. beginDrag(100,0)=" . $s6->value . "\n";

        // ═══ 5. 范围为 0（min==max）═══
        echo "\n-- Range 0 --\n";
        $s7 = new Slider(50, 50, 50);  // min==max==50
        echo "9. range0 value=" . $s7->value . "\n";
        $s7->proposeSize(100, 16);
        $s7->setPos(0, 0);
        $s7->beginDrag(99, 0);  // range=0 → value=min=50，不触发 onChange
        echo "10. range0 after drag value=" . $s7->value . "\n";

        // ═══ 6. 拖动中点（受 int/int 整除影响：50/100=0）═══
        echo "\n-- Drag midpoint (int-division affected) --\n";
        $s8 = new Slider(0, 100, 0);
        $s8->proposeSize(100, 16);
        $s8->setPos(0, 0);
        $s8->beginDrag(50, 0);  // 规范预期 ≈50；TinyPHP int 整除 → ratio=0 → value=0
        echo "11. beginDrag(50,0)=" . $s8->value . "\n";

        echo "\n=== OK ===\n";
    }
}
