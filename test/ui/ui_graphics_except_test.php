<?php
// @skip:darwin — sokol_app.h #import <AppKit/AppKit.h> 需 ObjC 模式,与 types.h 冲突
// test/ui/ui_graphics_except_test.php — UI 异常路径单元测试
//
// 验证：
//   - Graphics::clear/fillRect/drawText 在非 frame 回调内调用抛 Exception
//     （_ui_state.in_frame 初始为 false，C 包装层 tp_throw）
//   - 未初始化 Widget 的 draw() 抛 Exception（Button/Label/CheckBox/Slider）
//
// 用 try/catch 包装，输出 1=抛出异常，0=未抛出。
// 不打开窗口、不调用 sokol、不依赖 GPU（tp_throw 在调用 sokol 绘图前抛出）。

#import ui

#debug === UI Graphics Except Test ===
#debug
#debug -- Graphics outside frame --
#debug 1. clear throws=1
#debug 2. fillRect throws=1
#debug 3. drawText throws=1
#debug
#debug -- Widget draw before init --
#debug 4. Button draw throws=1
#debug 5. Label draw throws=1
#debug 6. CheckBox draw throws=1
#debug 7. Slider draw throws=1
#debug
#debug === OK ===

use UI\Graphics;
use UI\Color;
use UI\Rect;
use UI\Button;
use UI\Label;
use UI\CheckBox;
use UI\Slider;

class Main
{
    public function main(): void
    {
        echo "=== UI Graphics Except Test ===\n\n";

        // ═══ Graphics 在非 frame 回调内调用（_ui_state.in_frame=false）抛异常 ═══
        echo "-- Graphics outside frame --\n";

        $t1 = 0;
        try {
            Graphics::clear(Color::black());
        } catch (Exception $e) {
            $t1 = 1;
        }
        echo "1. clear throws=" . $t1 . "\n";

        $t2 = 0;
        try {
            Graphics::fillRect(new Rect(0, 0, 10, 10), Color::red());
        } catch (Exception $e) {
            $t2 = 1;
        }
        echo "2. fillRect throws=" . $t2 . "\n";

        $t3 = 0;
        try {
            Graphics::drawText(0, 0, "hi", Color::white());
        } catch (Exception $e) {
            $t3 = 1;
        }
        echo "3. drawText throws=" . $t3 . "\n";

        // ═══ 未初始化 Widget 的 draw() 抛异常 ═══
        echo "\n-- Widget draw before init --\n";

        $t4 = 0;
        try {
            $b = new Button("X");
            $b->draw();
        } catch (Exception $e) {
            $t4 = 1;
        }
        echo "4. Button draw throws=" . $t4 . "\n";

        $t5 = 0;
        try {
            $l = new Label("X");
            $l->draw();
        } catch (Exception $e) {
            $t5 = 1;
        }
        echo "5. Label draw throws=" . $t5 . "\n";

        $t6 = 0;
        try {
            $c = new CheckBox();
            $c->draw();
        } catch (Exception $e) {
            $t6 = 1;
        }
        echo "6. CheckBox draw throws=" . $t6 . "\n";

        $t7 = 0;
        try {
            $s = new Slider(0, 100, 50);
            $s->draw();
        } catch (Exception $e) {
            $t7 = 1;
        }
        echo "7. Slider draw throws=" . $t7 . "\n";

        echo "\n=== OK ===\n";
    }
}
