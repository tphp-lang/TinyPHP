<?php
// @skip — requires graphics environment
// test/ui/ui_layout_render.php — UI 布局渲染集成测试
//
// 验证 Stack flex 布局的渲染：用 Stack::row() 水平排列多个 Button，
// setPos + bounds + updateLayout 后，onFrame 中调用 stack->draw() 渲染。
//
// CI 无图形环境，标记 @skip。本地运行：
//   php tphp.php test/ui/ui_layout_render.php -o build/ui_layout_render.exe
//   ./build/ui_layout_render.exe
//
// 期望：窗口顶部 (10,10) 起水平排列三个按钮 "A" / "B" / "C"，
//       间距由 spacing 决定，按 Escape 退出。

#import ui

use UI\App;
use UI\Color;
use UI\Graphics;
use UI\Event;
use UI\EventType;
use UI\Key;
use UI\Stack;
use UI\Button;
use UI\ChildSize;

class Main
{
    public function main(): void
    {
        $app = new App(800, 600, "TinyPHP UI Layout Render");

        // 创建三个按钮并预先 proposeSize（Compact 模式需要预计算的 bounds）
        $btn1 = new Button("A");
        $btn1->init();
        $btn1->proposeSize(1000, 1000);

        $btn2 = new Button("B");
        $btn2->init();
        $btn2->proposeSize(1000, 1000);

        $btn3 = new Button("C");
        $btn3->init();
        $btn3->proposeSize(1000, 1000);

        // Stack::row 静态方法：水平排列（Compact 模式）
        $stack = Stack::row($btn1, $btn2, $btn3);
        $stack->init();
        $stack->spacing = 4;
        $stack->padding = 0;

        // 设置容器位置和尺寸，并触发布局计算
        $stack->bounds->x = 10;
        $stack->bounds->y = 10;
        $stack->bounds->width = 400;
        $stack->bounds->height = 30;
        $stack->updateLayout();

        // 每帧渲染布局（Stack::draw 会遍历子控件调用 draw）
        $app->onFrame(function() use ($stack): void {
            Graphics::clear(Color::black());
            $stack->draw();
        });

        $app->onEvent(function(int $evPtr): void {
            $ev = Event::fromPtr($evPtr);
            if ($ev->type === EventType::KeyDown->value
                && $ev->key === Key::Escape->value) {
                echo "escape pressed\n";
            }
        });

        $app->run();
    }
}
