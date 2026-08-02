<?php
// @skip — requires graphics environment
// test/ui/ui_widget_render.php — UI Widget 渲染集成测试
//
// 验证 Widget 控件体系的渲染和事件分发：创建 WidgetContainer 添加 Button /
// Label / TextBox，onFrame 中调用 drawAll 渲染，onEvent 中通过 dispatch*
// 将鼠标/键盘/字符事件分发到对应控件。
//
// CI 无图形环境，标记 @skip。本地运行：
//   php tphp.php test/ui/ui_widget_render.php -o build/ui_widget_render.exe
//   ./build/ui_widget_render.exe
//
// 期望：窗口显示一个 "Click" 按钮、"Hello" 文本和一个输入框；
//       点击按钮时 stdout 输出 "clicked"；点击输入框可获取焦点并输入字符。

#import ui

use UI\App;
use UI\Color;
use UI\Rect;
use UI\Graphics;
use UI\Event;
use UI\EventType;
use UI\Key;
use UI\WidgetContainer;
use UI\Button;
use UI\Label;
use UI\TextBox;

class Main
{
    public function main(): void
    {
        $app = new App(800, 600, "TinyPHP UI Widget Render");

        // 创建控件容器并添加子控件
        $container = new WidgetContainer();

        $btn = new Button("Click");
        $btn->init();
        $btn->setPos(10, 10);
        // Button::proposeSize 已设置 bounds->width/height
        $btn->proposeSize(1000, 1000);
        // 重新设置位置（proposeSize 不影响 x/y）
        $btn->setPos(10, 10);
        // 点击回调
        $btn->onClick = function(): void {
            echo "clicked\n";
        };
        $container->addChild($btn);

        $label = new Label("Hello");
        $label->init();
        $label->proposeSize(1000, 1000);
        $label->setPos(120, 10);
        $container->addChild($label);

        $textBox = new TextBox();
        $textBox->init();
        $textBox->proposeSize(200, 1000);
        $textBox->setPos(10, 50);
        $container->addChild($textBox);

        // 每帧渲染所有控件
        $app->onFrame(function() use ($container): void {
            Graphics::clear(Color::black());
            $container->drawAll();
        });

        // 事件分发到控件
        $app->onEvent(function(int $evPtr) use ($container): void {
            $ev = Event::fromPtr($evPtr);

            if ($ev->type === EventType::MouseDown->value) {
                $container->dispatchMouseDown($ev->x, $ev->y);
            } elseif ($ev->type === EventType::MouseUp->value) {
                $container->dispatchMouseUp($ev->x, $ev->y);
            } elseif ($ev->type === EventType::MouseMove->value) {
                $container->dispatchMouseMove($ev->x, $ev->y);
            } elseif ($ev->type === EventType::KeyDown->value) {
                $container->dispatchKeyDown($ev->key);
                // Escape 退出
                if ($ev->key === Key::Escape->value) {
                    echo "escape pressed\n";
                }
            } elseif ($ev->type === EventType::Char->value) {
                $container->dispatchChar($ev->codepoint);
            }
        });

        $app->run();
    }
}
