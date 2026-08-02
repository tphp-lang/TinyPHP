<?php
// @skip — requires graphics environment
// test/ui/ui_basic.php — UI 最小集成示例
//
// 验证最基础的 UI 流程：创建窗口 → onFrame 清屏 + 绘制矩形 + 绘制文本 →
// onEvent 处理 Escape 退出。
//
// CI 无图形环境，标记 @skip。本地有 sokol + GPU 环境时可手动运行：
//   php tphp.php test/ui/ui_basic.php -o build/ui_basic.exe
//   ./build/ui_basic.exe
//
// 期望：弹出 640x480 窗口，黑色背景上有一个红色矩形（10,10,100,50），
//       左上角显示白色 "Hello UI" 文本，按 Escape 退出。

#import ui

use UI\App;
use UI\Color;
use UI\Rect;
use UI\Graphics;
use UI\Event;
use UI\EventType;
use UI\Key;

class Main
{
    public function main(): void
    {
        $app = new App(640, 480, "TinyPHP UI Basic");

        // 每帧绘制：清屏 + 红色矩形 + 文本
        $app->onFrame(function(): void {
            // 清屏为黑色
            Graphics::clear(Color::black());

            // 绘制红色矩形 (10,10,100,50)
            $rect = new Rect(10, 10, 100, 50);
            Graphics::fillRect($rect, Color::red());

            // 左上角绘制白色文本
            Graphics::drawText(10, 80, "Hello UI", Color::white());
        });

        // 事件处理：Escape 退出
        $app->onEvent(function(int $evPtr): void {
            $ev = Event::fromPtr($evPtr);
            if ($ev->type === EventType::KeyDown->value) {
                if ($ev->key === Key::Escape->value) {
                    // sokol 收到 Escape 后由用户决定退出，这里仅打印标记
                    echo "escape pressed\n";
                }
            }
        });

        // 进入主循环（阻塞，直到窗口关闭）
        $app->run();
    }
}
