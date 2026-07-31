<?php
// test/ui/ui_textbox_test.php — UI\TextBox 控件单元测试
//
// 验证 TextBox 构造、光标移动、Home/End、非打印字符过滤、focus/blur、
// 字符输入、退格/Delete。
//
// 已知 CodeGenerator 问题：在含属性读取的 substr 拼接赋值表达式中
// （如 $this->text = substr($this->text,0,$this->cursorPos) . $c . substr($this->text,$this->cursorPos)），
// 属性参数的 substr 返回空串，导致 handleChar/handleKeyDown(Backspace/Delete) 的文本拼接
// 退化为仅保留新字符。本测试前 5 节（构造/光标移动/Home-End/过滤/blur）不依赖 substr，
// 能通过；第 6 节起的字符插入/退格/Delete 因上述 CodeGenerator 问题失败（#debug 仍按
// 规范预期声明，待 CodeGenerator 修复后通过）。
//
// 不打开窗口、不调用 sokol、不依赖 GPU。

#import ui

#debug === UI TextBox Test ===
#debug
#debug -- Construct --
#debug 1. initial text=initial cursorPos=7
#debug 2. empty text= cursorPos=0
#debug
#debug -- Cursor move (Left/Right) --
#debug 3. after Right cursorPos=2
#debug 4. after Left cursorPos=1
#debug
#debug -- Home/End --
#debug 5. after Home cursorPos=0
#debug 6. after End cursorPos=3
#debug
#debug -- Non-printable filter --
#debug 7. after cp31 text=X
#debug 8. after cp127 text=X
#debug
#debug -- blur --
#debug 9. after blur+cp65 text=X
#debug
#debug -- Char input --
#debug 10. after A text=A cursorPos=1
#debug 11. after BC text=ABC cursorPos=3
#debug
#debug -- Backspace --
#debug 12. after bs text=AB cursorPos=2
#debug
#debug -- Delete --
#debug 13. after del text=B cursorPos=0
#debug
#debug === OK ===

use UI\TextBox;
use UI\Key;

class Main
{
    public function main(): void
    {
        echo "=== UI TextBox Test ===\n\n";

        // ═══ 1. 构造 ═══
        echo "-- Construct --\n";
        $tb1 = new TextBox("initial");
        echo "1. initial text=" . $tb1->text . " cursorPos=" . $tb1->cursorPos . "\n";
        $tb2 = new TextBox();
        echo "2. empty text=" . $tb2->text . " cursorPos=" . $tb2->cursorPos . "\n";

        // ═══ 2. 光标移动（不依赖 substr；构造 "ABC" → cursorPos=3，手动置 1）═══
        echo "\n-- Cursor move (Left/Right) --\n";
        $tb = new TextBox("ABC");
        $tb->focus();
        $tb->cursorPos = 1;
        $tb->handleKeyDown(Key::Right->value);  // 1→2（1<strlen=3）
        echo "3. after Right cursorPos=" . $tb->cursorPos . "\n";
        $tb->handleKeyDown(Key::Left->value);  // 2→1
        echo "4. after Left cursorPos=" . $tb->cursorPos . "\n";

        // ═══ 3. Home/End（不依赖 substr）═══
        echo "\n-- Home/End --\n";
        $tb->cursorPos = 1;
        $tb->handleKeyDown(Key::Home->value);  // →0
        echo "5. after Home cursorPos=" . $tb->cursorPos . "\n";
        $tb->handleKeyDown(Key::End->value);  // →strlen("ABC")=3
        echo "6. after End cursorPos=" . $tb->cursorPos . "\n";

        // ═══ 4. 非打印字符过滤（<32 / >126 提前 return，不触达 substr）═══
        echo "\n-- Non-printable filter --\n";
        $tb3 = new TextBox("X");
        $tb3->focus();  // text="X", cursorPos=1
        $tb3->handleChar(31);  // 31<32 → 过滤
        echo "7. after cp31 text=" . $tb3->text . "\n";
        $tb3->handleChar(127);  // 127>126 → 过滤
        echo "8. after cp127 text=" . $tb3->text . "\n";

        // ═══ 5. blur 后 handleChar 不生效（提前 return）═══
        echo "\n-- blur --\n";
        $tb3->blur();  // focused=false
        $tb3->handleChar(65);  // 未 focus → 不追加
        echo "9. after blur+cp65 text=" . $tb3->text . "\n";

        // ═══ 6. 字符输入（依赖 substr 拼接；见文件头说明，受 CodeGenerator 问题影响）═══
        echo "\n-- Char input --\n";
        $tb4 = new TextBox();
        $tb4->init();
        $tb4->focus();
        $tb4->handleChar(65);  // 'A'（首字符：前缀/后缀均为空，结果正确）
        echo "10. after A text=" . $tb4->text . " cursorPos=" . $tb4->cursorPos . "\n";
        $tb4->handleChar(66);  // 'B'
        $tb4->handleChar(67);  // 'C'
        echo "11. after BC text=" . $tb4->text . " cursorPos=" . $tb4->cursorPos . "\n";

        // ═══ 7. 退格（依赖 substr）═══
        echo "\n-- Backspace --\n";
        $tb4->handleKeyDown(Key::Backspace->value);  // 删除末尾 'C'
        echo "12. after bs text=" . $tb4->text . " cursorPos=" . $tb4->cursorPos . "\n";

        // ═══ 8. Delete（依赖 substr）═══
        echo "\n-- Delete --\n";
        $tb4->cursorPos = 0;  // 光标移到开头
        $tb4->handleKeyDown(Key::Delete->value);  // 删除位置 0 的字符
        echo "13. after del text=" . $tb4->text . " cursorPos=" . $tb4->cursorPos . "\n";

        echo "\n=== OK ===\n";
    }
}
