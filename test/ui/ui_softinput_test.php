<?php
// @skip:darwin+tcc — TCC on macOS 不支持 -framework 链接选项（gcc/clang 正常）
// test/ui/ui_softinput_test.php — UI\SoftInput 软键盘单元测试（桌面端）
//
// 验证 show/hide/isVisible 状态切换、onInput 回调注册 + dispatch 触发、clear 清理回调。
// 桌面端 show/hide 为 no-op（仅更新 $visible 状态），不实际弹出软键盘。
//
// 注意：SoftInput 使用 static 属性，测试间状态共享，故开头先 clear() 重置。
// 不打开窗口、不调用 sokol、不依赖 GPU。

#import ui

#debug === UI SoftInput Test ===
#debug
#debug -- show / hide --
#debug 1. after show visible=1
#debug 2. after hide visible=0
#debug
#debug -- onInput + dispatch --
#debug 3. after dispatch(65) count=1
#debug 4. after dispatch(66) count=2
#debug
#debug -- clear --
#debug 5. after clear visible=0
#debug 6. after clear dispatch(67) count=2
#debug
#debug === OK ===

use UI\SoftInput;

// 回调计数跟踪器（PHP 对象语义：use($obj) 捕获句柄，闭包内可修改对象属性）
class SiTracker
{
    public int $count = 0;
}

class Main
{
    public function main(): void
    {
        echo "=== UI SoftInput Test ===\n\n";

        // 先重置静态状态（清理回调 + visible=false）
        SoftInput::clear();

        // ═══ 1. show / hide / isVisible ═══
        echo "-- show / hide --\n";
        SoftInput::show();
        echo "1. after show visible=" . (SoftInput::isVisible() ? 1 : 0) . "\n";
        SoftInput::hide();
        echo "2. after hide visible=" . (SoftInput::isVisible() ? 1 : 0) . "\n";

        // ═══ 2. onInput 回调注册 + dispatch 触发 ═══
        echo "\n-- onInput + dispatch --\n";
        $tracker = new SiTracker();
        SoftInput::onInput(function(int $codepoint) use ($tracker): void {
            $tracker->count = $tracker->count + 1;
        });
        SoftInput::dispatch(65);  // 'A'
        echo "3. after dispatch(65) count=" . $tracker->count . "\n";
        SoftInput::dispatch(66);  // 'B'
        echo "4. after dispatch(66) count=" . $tracker->count . "\n";

        // ═══ 3. clear 清理回调 ═══
        echo "\n-- clear --\n";
        SoftInput::clear();  // 清理回调 + visible=false
        echo "5. after clear visible=" . (SoftInput::isVisible() ? 1 : 0) . "\n";
        SoftInput::dispatch(67);  // 回调已清理，不应触发
        echo "6. after clear dispatch(67) count=" . $tracker->count . "\n";

        echo "\n=== OK ===\n";
    }
}
