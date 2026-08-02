<?php
// @skip — requires graphics environment
// test/ui/ui_full_integration.php — UI 全面集成测试（带窗口）
//
// 单窗口集成验证所有 UI 控件的渲染、事件处理和布局系统：
//   - Button: onClick 回调，点击计数 / 重置 / 底部三按钮
//   - Label: 动态文本更新（标题 + 实时状态栏 + 回显）
//   - TextBox: 焦点管理、字符输入、Backspace、Left/Right/Home/End 光标移动
//   - CheckBox: toggle 切换、onChange 回调
//   - Slider: 拖动改变值、onChange 回调、值夹紧
//   - Stack::row: 水平 flex 布局演示（底部三按钮自动排列）
//   - Graphics: clear / fillRect / drawRect / drawText / drawLine 综合使用
//
// 重要：TinyPHP 使用静态分派（无 vtable），数组元素方法调用
//   $this->children[$i]->onMouseDown() 会调用 Widget 基类方法（空实现），
//   而非子类 Button/CheckBox/Slider 的覆盖方法。
//   因此 WidgetContainer::dispatch* 和 Stack::draw（遍历子数组调用 draw）
//   在当前编译器下无法正确分派。
//   本测试通过直接引用各控件变量（编译期已知类型）绕过此限制：
//   - 事件分派：onEvent 中逐一检查 pointInside，直接调用类型化变量的方法
//   - 底部按钮绘制：直接调用 $btnA->draw() 等，不通过 Stack::draw()
//   - Stack 仅用于布局计算（updateLayout 读属性，不受静态分派影响）
//
// CI 无图形环境，标记 @skip。本地运行：
//   php tphp.php test/ui/ui_full_integration.php -o build/ui_full_integration.exe
//   ./build/ui_full_integration.exe
//
// 期望：
//   - 窗口显示标题、状态栏、两个按钮、复选框+标签、滑块、文本框、回显、底部三按钮
//   - 点击 "Click Me" 按钮计数 +1，状态栏实时更新
//   - 点击 "Reset" 按钮计数归零、复选框关闭、滑块归 50
//   - 点击复选框切换选中状态，状态栏显示 on/off
//   - 拖动滑块改变值，状态栏显示当前值
//   - 点击文本框获取焦点，键盘输入字符、退格、左右键移动光标
//   - 点击底部 [A]/[B]/[C] 按钮打印对应日志
//   - 每次交互在 stdout 输出对应信息，便于核对

#import ui

use UI\App;
use UI\Color;
use UI\Rect;
use UI\Graphics;
use UI\Event;
use UI\EventType;
use UI\Key;
use UI\Stack;
use UI\Button;
use UI\Label;
use UI\TextBox;
use UI\CheckBox;
use UI\Slider;

// 共享状态：TinyPHP 不支持 use(&$ref) 引用捕获，
// 用对象持有可变状态，闭包通过 use($state) 捕获对象句柄，修改属性影响外部。
class State
{
    public int $clickCount = 0;
    public bool $enabled = false;
    public int $sliderValue = 50;
    public string $lastAction = "(none)";
    public bool $textBoxFocused = false;  // TextBox 焦点状态（手动跟踪）
}

class Main
{
    public function main(): void
    {
        echo "=== TinyPHP UI Full Integration Test ===\n";
        echo "Window: 800x600\n";
        echo "Controls: Button x4, Label x3, TextBox, CheckBox, Slider, Stack::row\n";
        echo "Interact with the window. Watch this console for event logs.\n";
        echo "Close the window or press ESC to quit.\n\n";

        $app = new App(800, 600, "TinyPHP UI Full Integration");

        $state = new State();

        // ════════════════════════════════════════════════════════════
        // 控件创建（每个控件：构造 → init → proposeSize → setPos → 回调）
        // ════════════════════════════════════════════════════════════

        // ── 标题 ──
        $titleLabel = new Label("TinyPHP UI Full Integration");
        $titleLabel->init();
        $titleLabel->proposeSize(1000, 1000);
        $titleLabel->setPos(10, 10);

        // ── 状态栏（每帧动态更新文本）──
        $statusLabel = new Label("");
        $statusLabel->init();
        $statusLabel->proposeSize(1000, 1000);
        $statusLabel->setPos(10, 35);

        // ── 按钮 1: Click Me ──
        $clickBtn = new Button("Click Me");
        $clickBtn->init();
        $clickBtn->proposeSize(1000, 1000);
        $clickBtn->setPos(10, 70);
        $clickBtn->onClick = function() use ($state): void {
            $state->clickCount = $state->clickCount + 1;
            $state->lastAction = "click button";
            echo "[button] Click Me: count=" . $state->clickCount . "\n";
        };

        // ── 复选框 + 标签 ──（先于 Reset 创建，因 Reset 闭包需捕获 checkBox）
        $checkBox = new CheckBox();
        $checkBox->init();
        $checkBox->proposeSize(1000, 1000);
        $checkBox->setPos(10, 110);
        $checkBox->onChange = function(bool $checked) use ($state): void {
            $state->enabled = $checked;
            $state->lastAction = "checkbox toggle";
            echo "[checkbox] enabled=" . ($checked ? "true" : "false") . "\n";
        };

        $checkLabel = new Label("Enable feature");
        $checkLabel->init();
        $checkLabel->proposeSize(1000, 1000);
        $checkLabel->setPos(35, 110);

        // ── 滑块 ──（先于 Reset 创建，因 Reset 闭包需捕获 slider）
        $slider = new Slider(0, 100, 50);
        $slider->init();
        $slider->proposeSize(300, 1000);
        $slider->setPos(10, 140);
        $slider->onChange = function(int $value) use ($state): void {
            $state->sliderValue = $value;
            $state->lastAction = "slider drag";
            echo "[slider] value=" . $value . "\n";
        };

        // ── 文本框 + 回显标签 ──（先于 Reset 创建，因 Reset 闭包需捕获 textBox）
        $textBox = new TextBox();
        $textBox->init();
        $textBox->proposeSize(300, 1000);
        $textBox->setPos(10, 180);

        $echoLabel = new Label("(click textbox then type)");
        $echoLabel->init();
        $echoLabel->proposeSize(1000, 1000);
        $echoLabel->setPos(10, 210);

        // ── 按钮 2: Reset ──（捕获 checkBox/slider/textBox，必须在三者之后定义）
        $resetBtn = new Button("Reset");
        $resetBtn->init();
        $resetBtn->proposeSize(1000, 1000);
        $resetBtn->setPos(130, 70);
        $resetBtn->onClick = function() use ($state, $checkBox, $slider, $textBox): void {
            $state->clickCount = 0;
            $state->enabled = false;
            $state->sliderValue = 50;
            $state->textBoxFocused = false;
            $state->lastAction = "reset";
            // 同步控件状态
            $checkBox->setChecked(false);
            $slider->setValue(50);
            $textBox->blur();
            echo "[button] Reset: state cleared\n";
        };

        // ── 底部 Stack::row 布局演示（三个按钮自动水平排列）──
        // Stack 仅用于布局计算（updateLayout 读/写 bounds 属性，不受静态分派影响）。
        // 绘制和事件分派不通过 Stack（因 Stack::draw 遍历子数组调用 draw 受静态分派影响）。
        $btnA = new Button("A");
        $btnA->init();
        $btnA->proposeSize(1000, 1000);
        $btnA->onClick = function() use ($state): void {
            $state->lastAction = "click A";
            echo "[button] A pressed\n";
        };

        $btnB = new Button("B");
        $btnB->init();
        $btnB->proposeSize(1000, 1000);
        $btnB->onClick = function() use ($state): void {
            $state->lastAction = "click B";
            echo "[button] B pressed\n";
        };

        $btnC = new Button("C");
        $btnC->init();
        $btnC->proposeSize(1000, 1000);
        $btnC->onClick = function() use ($state): void {
            $state->lastAction = "click C";
            echo "[button] C pressed\n";
        };

        $bottomRow = Stack::row($btnA, $btnB, $btnC);
        $bottomRow->spacing = 8;
        $bottomRow->padding = 0;
        $bottomRow->bounds->x = 10;
        $bottomRow->bounds->y = 260;
        $bottomRow->bounds->width = 400;
        $bottomRow->bounds->height = 24;
        $bottomRow->updateLayout();

        // ════════════════════════════════════════════════════════════
        // 每帧渲染
        //
        // 直接调用各控件的 draw()（类型化变量，编译期已知类型，静态分派正确）。
        // 不使用 WidgetContainer::drawAll / Stack::draw（遍历数组受静态分派影响）。
        // ════════════════════════════════════════════════════════════

        $app->onFrame(function() use ($titleLabel, $statusLabel, $clickBtn, $resetBtn,
                                       $checkBox, $checkLabel, $slider, $textBox, $echoLabel,
                                       $btnA, $btnB, $btnC, $state): void {
            // 深灰背景
            Graphics::clear(new Color(24, 24, 28, 255));

            // 动态更新状态栏文本（每帧根据 State 重建）
            $status = "Clicks: " . $state->clickCount
                    . " | Slider: " . $state->sliderValue
                    . " | Check: " . ($state->enabled ? "on" : "off")
                    . " | Last: " . $state->lastAction;
            $statusLabel->text = $status;

            // 动态更新回显标签（显示文本框内容和光标位置）
            if ($textBox->text !== "") {
                $echoLabel->text = "Echo: " . $textBox->text . " (cursor=" . $textBox->cursorPos . ")";
            } else {
                $echoLabel->text = "(click textbox then type)";
            }

            // 绘制所有控件（直接调用，非数组遍历）
            $titleLabel->draw();
            $statusLabel->draw();
            $clickBtn->draw();
            $resetBtn->draw();
            $checkBox->draw();
            $checkLabel->draw();
            $slider->draw();
            $textBox->draw();
            $echoLabel->draw();
            // 底部三按钮（Stack::row 布局后 bounds 已定位，直接绘制）
            $btnA->draw();
            $btnB->draw();
            $btnC->draw();

            // 分隔线（直接使用 Graphics API，非控件）
            Graphics::drawLine(10, 245, 790, 245, new Color(80, 80, 80, 255));
            Graphics::drawLine(10, 305, 790, 305, new Color(80, 80, 80, 255));

            // 操作说明（直接 drawText，非 Label 控件）
            $white = Color::white();
            $gray = new Color(160, 160, 160, 255);
            Graphics::drawText(10, 325, "Instructions:", $white);
            Graphics::drawText(10, 345, "- Click [Click Me] to increment counter", $gray);
            Graphics::drawText(10, 363, "- Click [Reset] to clear state", $gray);
            Graphics::drawText(10, 381, "- Click checkbox to toggle", $gray);
            Graphics::drawText(10, 399, "- Drag slider to change value", $gray);
            Graphics::drawText(10, 417, "- Click textbox then type (Backspace/Left/Right/Home/End)", $gray);
            Graphics::drawText(10, 435, "- Click [A]/[B]/[C] at bottom (Stack::row layout)", $gray);
            Graphics::drawText(10, 453, "- Press ESC to quit", $gray);

            // 底部 Stack::row 区域标识
            Graphics::drawText(330, 268, "<-- Stack::row demo (auto layout)", $gray);
        });

        // ════════════════════════════════════════════════════════════
        // 事件分发（直接调用控件方法，绕过 WidgetContainer 静态分派限制）
        //
        // TinyPHP 无 vtable，$this->children[$i]->onMouseDown() 调用 Widget 基类（空实现）。
        // 此处通过类型化变量直接调用，编译器生成正确的子类方法调用：
        //   $clickBtn->onMouseDown()  →  Button_onMouseDown()  →  press()
        //   $checkBox->onMouseDown() →  CheckBox_onMouseDown() →  toggle()
        //   $slider->onMouseDown()   →  Slider_onMouseDown()   →  beginDrag()
        //   $textBox->onMouseDown()  →  TextBox_onMouseDown()  →  focus()
        //
        // MouseDown: 从上层到下层逐一检查 pointInside，命中第一个即分派
        //   （pointInside 各子类实现相同，静态分派结果正确）
        // MouseUp:   广播到所有可交互控件（release/endDrag 内部有状态检查，未激活为 no-op）
        // MouseMove: 仅分派到 slider（drag 内部检查 dragging 标志）
        // KeyDown/Char: 仅分派到 textBox（手动跟踪焦点状态）
        // ════════════════════════════════════════════════════════════

        $app->onEvent(function(int $evPtr) use ($clickBtn, $resetBtn, $checkBox, $slider,
                                                  $textBox, $btnA, $btnB, $btnC, $state): void {
            $ev = Event::fromPtr($evPtr);

            if ($ev->type === EventType::MouseDown->value) {
                // 逐一命中测试，分派到第一个命中的控件
                if ($clickBtn->pointInside($ev->x, $ev->y)) {
                    $clickBtn->onMouseDown($ev->x, $ev->y);
                    $state->textBoxFocused = false;
                } elseif ($resetBtn->pointInside($ev->x, $ev->y)) {
                    $resetBtn->onMouseDown($ev->x, $ev->y);
                    $state->textBoxFocused = false;
                } elseif ($checkBox->pointInside($ev->x, $ev->y)) {
                    $checkBox->onMouseDown($ev->x, $ev->y);
                    $state->textBoxFocused = false;
                } elseif ($slider->pointInside($ev->x, $ev->y)) {
                    $slider->onMouseDown($ev->x, $ev->y);
                    $state->textBoxFocused = false;
                } elseif ($textBox->pointInside($ev->x, $ev->y)) {
                    $textBox->onMouseDown($ev->x, $ev->y);
                    $state->textBoxFocused = true;
                } elseif ($btnA->pointInside($ev->x, $ev->y)) {
                    $btnA->onMouseDown($ev->x, $ev->y);
                    $state->textBoxFocused = false;
                } elseif ($btnB->pointInside($ev->x, $ev->y)) {
                    $btnB->onMouseDown($ev->x, $ev->y);
                    $state->textBoxFocused = false;
                } elseif ($btnC->pointInside($ev->x, $ev->y)) {
                    $btnC->onMouseDown($ev->x, $ev->y);
                    $state->textBoxFocused = false;
                } else {
                    $state->textBoxFocused = false;
                }
            } elseif ($ev->type === EventType::MouseUp->value) {
                // 广播到所有可交互控件（release/endDrag 内部有状态检查）
                $clickBtn->onMouseUp($ev->x, $ev->y);
                $resetBtn->onMouseUp($ev->x, $ev->y);
                $checkBox->onMouseUp($ev->x, $ev->y);
                $slider->onMouseUp($ev->x, $ev->y);
                $textBox->onMouseUp($ev->x, $ev->y);
                $btnA->onMouseUp($ev->x, $ev->y);
                $btnB->onMouseUp($ev->x, $ev->y);
                $btnC->onMouseUp($ev->x, $ev->y);
            } elseif ($ev->type === EventType::MouseMove->value) {
                // 仅 slider 需要鼠标移动事件（drag 检查 dragging 标志）
                $slider->onMouseMove($ev->x, $ev->y);
            } elseif ($ev->type === EventType::KeyDown->value) {
                if ($state->textBoxFocused) {
                    $textBox->onKeyDown($ev->key);
                }
                if ($ev->key === Key::Escape->value) {
                    echo "[event] ESC pressed — close window to quit\n";
                }
            } elseif ($ev->type === EventType::Char->value) {
                if ($state->textBoxFocused) {
                    $textBox->onChar($ev->codepoint);
                }
            }
        });

        $app->run();
    }
}
