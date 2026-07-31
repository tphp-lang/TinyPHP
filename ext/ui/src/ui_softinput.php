<?php
// ext/ui/src/ui_softinput.php — UI 扩展软键盘桥接
//
// 提供软键盘（虚拟键盘）的显示/隐藏和输入回调注册。
// 桌面端实现：show/hide 为 no-op（桌面端有物理键盘，无需软键盘），
//             onInput 注册的回调由 Char 事件触发。
//
// 移动端（未来支持）：show/hide 调用平台原生 API 弹出/收起软键盘。
//
// 回调存储在 C 层（C->_ui_softinput_cb），避免 PHP mixed 属性的 null 赋值问题。

namespace UI;

// ════════════════════════════════════════════════════════════
// SoftInput — 软键盘管理（静态类）
//
// 用法：
//   SoftInput::onInput(function(int $codepoint) {
//       // 处理字符输入
//   });
//   // 在事件回调中：
//   if ($event->type === EventType::Char->value) {
//       SoftInput::dispatch($event->codepoint);
//   }
//
// 桌面端行为：
//   - show()/hide() 为 no-op（$visible 仍更新但不影响实际行为）
//   - onInput() 注册回调，由 Char 事件触发
//   - isVisible() 始终返回 false（桌面端无软键盘）
// ════════════════════════════════════════════════════════════

class SoftInput
{
    // 静态属性存储状态（回调存储在 C 层 C->_ui_softinput_cb）
    public static bool $visible = false;   // 软键盘是否可见

    // ── 软键盘显示/隐藏 ──

    public static function show(): void
    {
        // 桌面端：C 层 no-op；Android 端：C 层 stub 抛 Exception（需 ext/jni）
        C->ui_softinput_show();
        self::$visible = true;
    }

    public static function hide(): void
    {
        // 桌面端：C 层 no-op；Android 端：C 层 stub 抛 Exception（需 ext/jni）
        C->ui_softinput_hide();
        self::$visible = false;
    }

    public static function isVisible(): bool
    {
        // 桌面端始终返回 false（无软键盘）
        return self::$visible;
    }

    // ── 输入回调注册（回调存储在 C 层）──

    public static function onInput(callable $cb): void
    {
        C->ui_softinput_on_input($cb);
    }

    // ── 分发字符输入（由事件回调调用）──
    //   在 App::onEvent 回调中，当收到 Char 事件时调用此方法
    //   将 codepoint 传递给注册的 onInput 回调

    public static function dispatch(int $codepoint): void
    {
        C->ui_softinput_dispatch($codepoint);
    }

    // ── 清理回调（防止内存泄漏）──

    public static function clear(): void
    {
        C->ui_softinput_clear_cb();
        self::$visible = false;
    }
}
