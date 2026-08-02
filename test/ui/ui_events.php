<?php
// @skip — requires graphics environment
// test/ui/ui_events.php — UI 事件系统集成测试
//
// 验证事件系统：Event::fromPtr 解析 sapp_event 指针，区分 MouseMove / KeyDown /
// Char 事件并打印对应信息。
//
// CI 无图形环境，标记 @skip。本地运行：
//   php tphp.php test/ui/ui_events.php -o build/ui_events.exe
//   ./build/ui_events.exe
//
// 期望：移动鼠标时输出 "mouse: x,y"，按键时输出 "key: <keycode>"，
//       按 Escape 退出。

#import ui

use UI\App;
use UI\Color;
use UI\Graphics;
use UI\Event;
use UI\EventType;
use UI\Key;

class Main
{
    public function main(): void
    {
        $app = new App(800, 600, "TinyPHP UI Events");

        $app->onFrame(function(): void {
            Graphics::clear(Color::black());
            Graphics::drawText(10, 10, "Move mouse / press keys. ESC to quit.", Color::white());
        });

        $app->onEvent(function(int $evPtr): void {
            // 通过 Event::fromPtr 从 C 事件指针解析出 PHP 侧事件对象
            $ev = Event::fromPtr($evPtr);

            if ($ev->type === EventType::MouseMove->value) {
                // 鼠标移动：打印坐标
                echo "mouse: " . $ev->x . "," . $ev->y . "\n";
            } elseif ($ev->type === EventType::KeyDown->value) {
                // 按键按下：打印键码
                echo "key: " . $ev->key . "\n";

                // Escape 键退出（sapp_request_quit 由窗口主循环处理）
                if ($ev->key === Key::Escape->value) {
                    echo "escape pressed\n";
                }
            } elseif ($ev->type === EventType::Char->value) {
                // 字符输入：打印 codepoint
                echo "char: " . $ev->codepoint . "\n";
            }
        });

        $app->run();
    }
}
