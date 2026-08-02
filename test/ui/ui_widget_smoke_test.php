<?php
// @skip:darwin+tcc — TCC 不支持 -x objective-c 和 -framework 链接（clang/gcc 正常）
// test/ui/ui_widget_smoke_test.php — UI Widget/Layout 冒烟测试
//
// 验证 Widget/Layout 体系能编译且基本逻辑正确。
// 纯逻辑测试，不打开窗口、不调用 sokol、不依赖 GPU、不调用 draw（避免 Graphics API）。
//
// 覆盖：
//   - Button/Label/CheckBox/Slider/TextBox 构造与属性
//   - Widget::setPos / pointInside / proposeSize / size
//   - WidgetContainer::addChild / hitTestIndex / dispatchMouseDown
//   - CheckBox::toggle 状态切换 + onChange 回调
//   - Slider::setValue 值夹紧 + 拖动 onChange 回调
//   - Stack Row/Column 布局排列（compact 模式）
//   - CanvasLayout 绝对定位
//   - 枚举值完整性
//
// 注意：TinyPHP Parser 不支持 use(&$ref) 引用捕获，使用 Tracker 类持有回调状态
// （PHP 对象语义：use($obj) 捕获对象句柄，闭包内修改 $obj->prop 影响外部）

#import ui

#debug === UI Widget Smoke Test ===
#debug
#debug -- Button --
#debug 1. btn text=OK
#debug 2. btn bounds=0,0,0,0
#debug 3. btn proposeSize=40,24
#debug 4. btn setPos(10,20) bounds=10,20,40,24
#debug 5. btn pointInside(15,25)=1
#debug 6. btn pointInside(100,100)=0
#debug
#debug -- Label --
#debug 7. label text=Hello
#debug 8. label fontSize=14
#debug
#debug -- CheckBox --
#debug 9. cb initial checked=0
#debug 10. cb toggled checked=1
#debug 11. cb onChange fired=1 with=1
#debug
#debug -- Slider --
#debug 12. slider initial value=50
#debug 13. slider clamped value=100
#debug 14. slider onChange fired=1 with=100
#debug
#debug -- TextBox --
#debug 15. tb initial text=abc
#debug 16. tb cursorPos=3
#debug
#debug -- WidgetContainer --
#debug 17. container childCount=2
#debug 18. container hitTest(50,50)=-1
#debug 19. container hitTest(15,25)=0
#debug
#debug -- Stack Row --
#debug 20. row childCount=2
#debug 21. row child0 x=10 w=40
#debug 22. row child1 x=50 w=40
#debug
#debug -- Stack Column --
#debug 23. col child0 y=10
#debug 24. col child1 y=34
#debug
#debug -- CanvasLayout --
#debug 25. canvas child0 x=100
#debug 26. canvas child1 x=200
#debug
#debug -- Enum --
#debug 27. EventType::MouseDown=4
#debug 28. Key::Enter=13
#debug 29. Cursor::Hand=3
#debug 30. WidgetState::Pressed=2
#debug
#debug === OK ===

use UI\Button;
use UI\Label;
use UI\CheckBox;
use UI\Slider;
use UI\TextBox;
use UI\WidgetContainer;
use UI\Stack;
use UI\CanvasLayout;
use UI\Rect;
use UI\Color;
use UI\EventType;
use UI\Key;
use UI\Cursor;
use UI\WidgetState;
use UI\ChildSize;
use UI\Direction;

// 回调状态跟踪器（PHP 对象语义：use($obj) 捕获句柄，闭包内可修改对象属性）
class Tracker
{
    public bool $fired = false;
    public int $intVal = -1;
    public bool $boolVal = false;
}

class Main
{
    public function main(): void
    {
        echo "=== UI Widget Smoke Test ===\n\n";

        // ═══ Button ═══
        echo "-- Button --\n";
        $btn = new Button("OK");
        echo "1. btn text=" . $btn->text . "\n";
        echo "2. btn bounds=" . $btn->bounds->x . "," . $btn->bounds->y . "," . $btn->bounds->width . "," . $btn->bounds->height . "\n";
        $sz = $btn->proposeSize(1000, 1000);
        // Button::proposeSize: w = strlen("OK")*8+16 = 32; min 40 → 40; h = 24
        echo "3. btn proposeSize=" . $sz[0] . "," . $sz[1] . "\n";
        $btn->setPos(10, 20);
        echo "4. btn setPos(10,20) bounds=" . $btn->bounds->x . "," . $btn->bounds->y . "," . $btn->bounds->width . "," . $btn->bounds->height . "\n";
        echo "5. btn pointInside(15,25)=" . ($btn->pointInside(15, 25) ? 1 : 0) . "\n";
        echo "6. btn pointInside(100,100)=" . ($btn->pointInside(100, 100) ? 1 : 0) . "\n";

        // ═══ Label ═══
        echo "\n-- Label --\n";
        $label = new Label("Hello");
        echo "7. label text=" . $label->text . "\n";
        echo "8. label fontSize=" . $label->fontSize . "\n";

        // ═══ CheckBox ═══
        echo "\n-- CheckBox --\n";
        $cb = new CheckBox();
        $cbTracker = new Tracker();
        $cb->onChange = function(bool $checked) use ($cbTracker): void {
            $cbTracker->fired = true;
            $cbTracker->boolVal = $checked;
        };
        echo "9. cb initial checked=" . ($cb->checked ? 1 : 0) . "\n";
        $cb->toggle();
        echo "10. cb toggled checked=" . ($cb->checked ? 1 : 0) . "\n";
        echo "11. cb onChange fired=" . ($cbTracker->fired ? 1 : 0) . " with=" . ($cbTracker->boolVal ? 1 : 0) . "\n";

        // ═══ Slider ═══
        echo "\n-- Slider --\n";
        $slider = new Slider(0, 100, 50);
        $sliderTracker = new Tracker();
        $slider->onChange = function(int $value) use ($sliderTracker): void {
            $sliderTracker->fired = true;
            $sliderTracker->intVal = $value;
        };
        echo "12. slider initial value=" . $slider->value . "\n";
        $slider->setValue(200);  // 夹紧到 max=100
        echo "13. slider clamped value=" . $slider->value . "\n";
        // beginDrag 触发 onChange（拖到 width 最右端 → value=max=100）
        $slider->bounds->x = 0;
        $slider->bounds->width = 100;
        $slider->beginDrag(100, 0);
        echo "14. slider onChange fired=" . ($sliderTracker->fired ? 1 : 0) . " with=" . $sliderTracker->intVal . "\n";

        // ═══ TextBox ═══
        echo "\n-- TextBox --\n";
        $tb = new TextBox("abc");
        echo "15. tb initial text=" . $tb->text . "\n";
        echo "16. tb cursorPos=" . $tb->cursorPos . "\n";

        // ═══ WidgetContainer ═══
        echo "\n-- WidgetContainer --\n";
        $container = new WidgetContainer();
        $container->addChild($btn);
        $container->addChild($label);
        echo "17. container childCount=" . $container->childCount() . "\n";
        echo "18. container hitTest(50,50)=" . $container->hitTestIndex(50, 50) . "\n";
        echo "19. container hitTest(15,25)=" . $container->hitTestIndex(15, 25) . "\n";

        // ═══ Stack Row（水平排列）═══
        echo "\n-- Stack Row --\n";
        $btn1 = new Button("A");
        $btn1->proposeSize(1000, 1000);  // 触发 bounds 设置
        $btn2 = new Button("B");
        $btn2->proposeSize(1000, 1000);
        $row = new Stack(Direction::Row->value);
        $row->spacing = 0;
        $row->padding = 0;
        $row->bounds->x = 10;
        $row->bounds->y = 10;
        $row->bounds->width = 200;
        $row->bounds->height = 24;
        // ChildSize::Compact=0
        $row->addWidget($btn1, ChildSize::Compact->value, 0);
        $row->addWidget($btn2, ChildSize::Compact->value, 0);
        $row->updateLayout();
        // Compact 模式下 ms = proposeSize 返回的 sz[0]（主轴=width for Row）
        // Button "A": w = 1*8+16 = 24, min 40 → 40
        // child0: x = 10+0 = 10, pos = 0+40+0 = 40
        // child1: x = 10+40 = 50
        // 直接使用 btn1/btn2 引用读取 bounds（避免外部访问 array<Widget> 元素）
        echo "20. row childCount=" . count($row->children) . "\n";
        echo "21. row child0 x=" . $btn1->bounds->x . " w=" . $btn1->bounds->width . "\n";
        echo "22. row child1 x=" . $btn2->bounds->x . " w=" . $btn2->bounds->width . "\n";

        // ═══ Stack Column（垂直排列）═══
        echo "\n-- Stack Column --\n";
        $btn3 = new Button("X");
        $btn3->proposeSize(1000, 1000);  // 触发 bounds 设置
        $btn4 = new Button("Y");
        $btn4->proposeSize(1000, 1000);
        $col = new Stack(Direction::Column->value);
        $col->spacing = 0;
        $col->padding = 0;
        $col->bounds->x = 0;
        $col->bounds->y = 10;
        $col->bounds->width = 100;
        $col->bounds->height = 200;
        $col->addWidget($btn3, ChildSize::Compact->value, 0);
        $col->addWidget($btn4, ChildSize::Compact->value, 0);
        $col->updateLayout();
        // Column: ms = proposeSize 返回的 sz[1]（主轴=height for Column）
        // Button "X": proposeSize returns [40, 24], so ms = 24
        // child0: y = 10+0 = 10, pos = 0+24+0 = 24
        // child1: y = 10+24 = 34
        echo "23. col child0 y=" . $btn3->bounds->y . "\n";
        echo "24. col child1 y=" . $btn4->bounds->y . "\n";

        // ═══ CanvasLayout ═══
        echo "\n-- CanvasLayout --\n";
        $canvas = new CanvasLayout();
        $cBtn1 = new Button("C1");
        $cBtn2 = new Button("C2");
        $canvas->addWidget($cBtn1, 100, 100, 60, 24);
        $canvas->addWidget($cBtn2, 200, 100, 60, 24);
        $canvas->updateLayout();
        echo "25. canvas child0 x=" . $cBtn1->bounds->x . "\n";
        echo "26. canvas child1 x=" . $cBtn2->bounds->x . "\n";

        // ═══ 枚举 ═══
        echo "\n-- Enum --\n";
        echo "27. EventType::MouseDown=" . EventType::MouseDown->value . "\n";
        echo "28. Key::Enter=" . Key::Enter->value . "\n";
        echo "29. Cursor::Hand=" . Cursor::Hand->value . "\n";
        echo "30. WidgetState::Pressed=" . WidgetState::Pressed->value . "\n";

        echo "\n=== OK ===\n";
    }
}
