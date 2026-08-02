<?php
// @skip:darwin — sokol_app.h #import <AppKit/AppKit.h> 需 ObjC 模式,与 types.h 冲突
// @skip:windows+clang — clang 编译 Win32 头时 SAL 注解未定义,需 MSVC 头文件兼容
// test/ui/ui_enums_test.php — UI 枚举完整性单元测试
//
// 验证所有枚举值可访问且数值正确（映射 sokol / 标准 VK 键码）。
// 纯逻辑测试，不打开窗口、不调用 sokol、不依赖 GPU。

#import ui

#debug === UI Enums Test ===
#debug
#debug -- EventType --
#debug 1. Invalid=0
#debug 2. KeyDown=1
#debug 3. Char=3
#debug 4. MouseDown=4
#debug 5. Quit=21
#debug
#debug -- MouseButton --
#debug 6. Left=0
#debug 7. Right=1
#debug 8. Middle=2
#debug
#debug -- Key --
#debug 9. Backspace=8
#debug 10. Enter=13
#debug 11. Space=32
#debug 12. A=65
#debug 13. Z=90
#debug 14. F1=112
#debug 15. F12=123
#debug
#debug -- KeyMod --
#debug 16. Shift=1
#debug 17. Ctrl=2
#debug 18. Alt=4
#debug 19. Super=8
#debug
#debug -- Cursor --
#debug 20. Arrow=0
#debug 21. None=7
#debug
#debug -- Direction --
#debug 22. Row=0
#debug 23. Column=1
#debug
#debug -- WidgetState --
#debug 24. Normal=0
#debug 25. Disabled=4
#debug
#debug -- LayoutAlign --
#debug 26. Start=0
#debug 27. Stretch=3
#debug
#debug -- ChildSize --
#debug 28. Compact=0
#debug 29. Stretch=1
#debug 30. Fixed=2
#debug
#debug === OK ===

use UI\EventType;
use UI\MouseButton;
use UI\Key;
use UI\KeyMod;
use UI\Cursor;
use UI\Direction;
use UI\WidgetState;
use UI\LayoutAlign;
use UI\ChildSize;

class Main
{
    public function main(): void
    {
        echo "=== UI Enums Test ===\n\n";

        // ═══ EventType ═══
        echo "-- EventType --\n";
        echo "1. Invalid=" . EventType::Invalid->value . "\n";
        echo "2. KeyDown=" . EventType::KeyDown->value . "\n";
        echo "3. Char=" . EventType::Char->value . "\n";
        echo "4. MouseDown=" . EventType::MouseDown->value . "\n";
        echo "5. Quit=" . EventType::Quit->value . "\n";

        // ═══ MouseButton ═══
        echo "\n-- MouseButton --\n";
        echo "6. Left=" . MouseButton::Left->value . "\n";
        echo "7. Right=" . MouseButton::Right->value . "\n";
        echo "8. Middle=" . MouseButton::Middle->value . "\n";

        // ═══ Key ═══
        echo "\n-- Key --\n";
        echo "9. Backspace=" . Key::Backspace->value . "\n";
        echo "10. Enter=" . Key::Enter->value . "\n";
        echo "11. Space=" . Key::Space->value . "\n";
        echo "12. A=" . Key::A->value . "\n";
        echo "13. Z=" . Key::Z->value . "\n";
        echo "14. F1=" . Key::F1->value . "\n";
        echo "15. F12=" . Key::F12->value . "\n";

        // ═══ KeyMod ═══
        echo "\n-- KeyMod --\n";
        echo "16. Shift=" . KeyMod::Shift->value . "\n";
        echo "17. Ctrl=" . KeyMod::Ctrl->value . "\n";
        echo "18. Alt=" . KeyMod::Alt->value . "\n";
        echo "19. Super=" . KeyMod::Super->value . "\n";

        // ═══ Cursor ═══
        echo "\n-- Cursor --\n";
        echo "20. Arrow=" . Cursor::Arrow->value . "\n";
        echo "21. None=" . Cursor::None->value . "\n";

        // ═══ Direction ═══
        echo "\n-- Direction --\n";
        echo "22. Row=" . Direction::Row->value . "\n";
        echo "23. Column=" . Direction::Column->value . "\n";

        // ═══ WidgetState ═══
        echo "\n-- WidgetState --\n";
        echo "24. Normal=" . WidgetState::Normal->value . "\n";
        echo "25. Disabled=" . WidgetState::Disabled->value . "\n";

        // ═══ LayoutAlign ═══
        echo "\n-- LayoutAlign --\n";
        echo "26. Start=" . LayoutAlign::Start->value . "\n";
        echo "27. Stretch=" . LayoutAlign::Stretch->value . "\n";

        // ═══ ChildSize ═══
        echo "\n-- ChildSize --\n";
        echo "28. Compact=" . ChildSize::Compact->value . "\n";
        echo "29. Stretch=" . ChildSize::Stretch->value . "\n";
        echo "30. Fixed=" . ChildSize::Fixed->value . "\n";

        echo "\n=== OK ===\n";
    }
}
