<?php
// @skip:darwin — sokol_app.h #import <AppKit/AppKit.h> 需 ObjC 模式,与 types.h 冲突
// test/ui/ui_button_test.php — UI\Button 控件单元测试
//
// 验证 Button 构造、默认/自定义颜色、onClick 回调、pointInside 命中、
// init 状态、未 init 时 draw() 抛 Exception。
// 不打开窗口、不调用 sokol、不依赖 GPU。

#import ui

#debug === UI Button Test ===
#debug
#debug -- Construct --
#debug 1. text=Click
#debug 2. default bgColor=60,120,200,255
#debug 3. default textColor=255,255,255,255
#debug 4. custom bgColor=255,0,0,255
#debug
#debug -- onClick callback --
#debug 5. count after press+release+click=2
#debug
#debug -- pointInside --
#debug 6. bounds=10,10,56,24
#debug 7. pointInside(15,15)=1
#debug 8. pointInside(5,5)=0
#debug
#debug -- init / draw --
#debug 9. initialized after init=1
#debug 10. draw before init throws=1
#debug
#debug === OK ===

use UI\Button;
use UI\Color;

// 回调计数跟踪器（PHP 对象语义：use($obj) 捕获句柄，闭包内可修改对象属性）
class BtnTracker
{
    public int $count = 0;
}

class Main
{
    public function main(): void
    {
        echo "=== UI Button Test ===\n\n";

        // ═══ 1. 构造 + 默认颜色 ═══
        echo "-- Construct --\n";
        $btn = new Button("Click");
        echo "1. text=" . $btn->text . "\n";
        echo "2. default bgColor=" . $btn->bgColor->r . "," . $btn->bgColor->g . "," . $btn->bgColor->b . "," . $btn->bgColor->a . "\n";
        echo "3. default textColor=" . $btn->textColor->r . "," . $btn->textColor->g . "," . $btn->textColor->b . "," . $btn->textColor->a . "\n";
        // 自定义颜色
        $btn->bgColor = Color::red();
        echo "4. custom bgColor=" . $btn->bgColor->r . "," . $btn->bgColor->g . "," . $btn->bgColor->b . "," . $btn->bgColor->a . "\n";

        // ═══ 2. onClick 回调 ═══
        echo "\n-- onClick callback --\n";
        $btn2 = new Button("OK");
        $tracker = new BtnTracker();
        $btn2->onClick = function() use ($tracker): void {
            $tracker->count = $tracker->count + 1;
        };
        // press() → state=Pressed；release() → 检测到 Pressed → Normal + click()（count=1）；
        // click() 直接调用 → count=2
        $btn2->press();
        $btn2->release();
        $btn2->click();
        echo "5. count after press+release+click=" . $tracker->count . "\n";

        // ═══ 3. pointInside 命中 ═══
        echo "\n-- pointInside --\n";
        // "Click"=5 字符：w=5*8+16=56，h=24
        $btn3 = new Button("Click");
        $btn3->setPos(10, 10);
        $btn3->proposeSize(100, 100);
        echo "6. bounds=" . $btn3->bounds->x . "," . $btn3->bounds->y . "," . $btn3->bounds->width . "," . $btn3->bounds->height . "\n";
        echo "7. pointInside(15,15)=" . ($btn3->pointInside(15, 15) ? 1 : 0) . "\n";
        echo "8. pointInside(5,5)=" . ($btn3->pointInside(5, 5) ? 1 : 0) . "\n";

        // ═══ 4. init / draw ═══
        echo "\n-- init / draw --\n";
        $btn4 = new Button("X");
        $btn4->init();
        echo "9. initialized after init=" . ($btn4->initialized ? 1 : 0) . "\n";
        // 未 init 的 Button 调用 draw() 抛 Exception
        $btn5 = new Button("Y");
        $threw = 0;
        try {
            $btn5->draw();
        } catch (Exception $e) {
            $threw = 1;
        }
        echo "10. draw before init throws=" . $threw . "\n";

        echo "\n=== OK ===\n";
    }
}
