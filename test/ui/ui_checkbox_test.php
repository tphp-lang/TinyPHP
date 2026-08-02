<?php
// @skip:darwin — sokol_app.h #import <AppKit/AppKit.h> 需 ObjC 模式,与 types.h 冲突
// @skip:windows+clang — clang 编译 Win32 头时 SAL 注解未定义,需 MSVC 头文件兼容
// test/ui/ui_checkbox_test.php — UI\CheckBox 控件单元测试
//
// 验证 CheckBox 构造（默认/带初始值）、toggle 切换、onChange 回调、
// setChecked、pointInside 命中（16x16）、未 init 时 draw() 抛 Exception。
// 不打开窗口、不调用 sokol、不依赖 GPU。

#import ui

#debug === UI CheckBox Test ===
#debug
#debug -- Construct --
#debug 1. default checked=0
#debug 2. initial true checked=1
#debug
#debug -- toggle --
#debug 3. toggle false->true=1
#debug 4. toggle true->false=0
#debug
#debug -- onChange --
#debug 5. onChange fired=1 with=1
#debug
#debug -- setChecked --
#debug 6. setChecked(false)=0
#debug 7. setChecked(true)=1
#debug
#debug -- pointInside (16x16) --
#debug 8. bounds=10,10,16,16
#debug 9. pointInside(15,15)=1
#debug 10. pointInside(30,30)=0
#debug
#debug -- draw --
#debug 11. draw before init throws=1
#debug
#debug === OK ===

use UI\CheckBox;

// 回调状态跟踪器（PHP 对象语义：use($obj) 捕获句柄，闭包内可修改对象属性）
class CbTracker
{
    public bool $fired = false;
    public bool $boolVal = false;
}

class Main
{
    public function main(): void
    {
        echo "=== UI CheckBox Test ===\n\n";

        // ═══ 1. 构造 ═══
        echo "-- Construct --\n";
        $cb1 = new CheckBox();
        echo "1. default checked=" . ($cb1->checked ? 1 : 0) . "\n";
        $cb2 = new CheckBox(true);
        echo "2. initial true checked=" . ($cb2->checked ? 1 : 0) . "\n";

        // ═══ 2. toggle 切换 ═══
        echo "\n-- toggle --\n";
        $cb3 = new CheckBox();  // false
        $cb3->toggle();  // false→true
        echo "3. toggle false->true=" . ($cb3->checked ? 1 : 0) . "\n";
        $cb3->toggle();  // true→false
        echo "4. toggle true->false=" . ($cb3->checked ? 1 : 0) . "\n";

        // ═══ 3. onChange 回调 ═══
        echo "\n-- onChange --\n";
        $cb4 = new CheckBox();
        $tracker = new CbTracker();
        $cb4->onChange = function(bool $checked) use ($tracker): void {
            $tracker->fired = true;
            $tracker->boolVal = $checked;
        };
        $cb4->toggle();  // false→true，触发 onChange(true)
        echo "5. onChange fired=" . ($tracker->fired ? 1 : 0) . " with=" . ($tracker->boolVal ? 1 : 0) . "\n";

        // ═══ 4. setChecked（直接设置，不触发 onChange）═══
        echo "\n-- setChecked --\n";
        $cb4->setChecked(false);
        echo "6. setChecked(false)=" . ($cb4->checked ? 1 : 0) . "\n";
        $cb4->setChecked(true);
        echo "7. setChecked(true)=" . ($cb4->checked ? 1 : 0) . "\n";

        // ═══ 5. pointInside（proposeSize → 16x16）═══
        echo "\n-- pointInside (16x16) --\n";
        $cb5 = new CheckBox();
        $cb5->proposeSize(100, 100);  // 16x16
        $cb5->setPos(10, 10);  // bounds=(10,10,16,16) → 覆盖 [10,26) x [10,26)
        echo "8. bounds=" . $cb5->bounds->x . "," . $cb5->bounds->y . "," . $cb5->bounds->width . "," . $cb5->bounds->height . "\n";
        echo "9. pointInside(15,15)=" . ($cb5->pointInside(15, 15) ? 1 : 0) . "\n";
        // (30,30) 不在 [10,26) 内 → false
        echo "10. pointInside(30,30)=" . ($cb5->pointInside(30, 30) ? 1 : 0) . "\n";

        // ═══ 6. draw 未 init 抛异常 ═══
        echo "\n-- draw --\n";
        $cb6 = new CheckBox();
        $threw = 0;
        try {
            $cb6->draw();
        } catch (Exception $e) {
            $threw = 1;
        }
        echo "11. draw before init throws=" . $threw . "\n";

        echo "\n=== OK ===\n";
    }
}
