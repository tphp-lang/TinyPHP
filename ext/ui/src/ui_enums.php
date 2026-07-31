<?php
// ext/ui/src/ui_enums.php — UI 扩展枚举定义
//
// 所有枚举映射 sokol C 库的枚举值，PHP 侧通过命名空间 UI\ 使用。
// 枚举值与 sokol_app.h 中的 SAPP_EVENTTYPE_*、SAPP_MOUSEBUTTON_* 等对应。
//
// 注意：Key 枚举使用 ASCII/标准虚拟键码（与 Windows VK_* 一致），
//       与 sokol 的 GLFW 键码不同。后续 C 包装层可添加映射表转换。
//       当前骨架阶段，事件回调直接传递 sokol 原始键码，
//       用户通过 Key::tryFrom($code) 进行匹配。

namespace UI;

// ── EventType：事件类型（映射 SAPP_EVENTTYPE_*）──
enum EventType: int
{
    case Invalid = 0;
    case KeyDown = 1;
    case KeyUp = 2;
    case Char = 3;
    case MouseDown = 4;
    case MouseUp = 5;
    case MouseScroll = 6;
    case MouseMove = 7;
    case MouseEnter = 8;
    case MouseLeave = 9;
    case TouchDown = 10;
    case TouchMove = 11;
    case TouchUp = 12;
    case TouchCancel = 13;
    case Resized = 14;
    case Iconified = 15;
    case Restored = 16;
    case Focused = 17;
    case Unfocused = 18;
    case Suspended = 19;
    case Resumed = 20;
    case Quit = 21;
}

// ── MouseButton：鼠标按键（映射 SAPP_MOUSEBUTTON_*）──
enum MouseButton: int
{
    case Left = 0;
    case Right = 1;
    case Middle = 2;
}

// ── Key：常用键子集（ASCII/标准虚拟键码）──
enum Key: int
{
    case Invalid = 0;
    case Backspace = 8;
    case Tab = 9;
    case Enter = 13;
    case Escape = 27;
    case Space = 32;
    case PageUp = 33;
    case PageDown = 34;
    case End = 35;
    case Home = 36;
    case Left = 37;
    case Up = 38;
    case Right = 39;
    case Down = 40;
    case Delete = 46;
    case A = 65;
    case B = 66;
    case C = 67;
    case D = 68;
    case E = 69;
    case F = 70;
    case G = 71;
    case H = 72;
    case I = 73;
    case J = 74;
    case K = 75;
    case L = 76;
    case M = 77;
    case N = 78;
    case O = 79;
    case P = 80;
    case Q = 81;
    case R = 82;
    case S = 83;
    case T = 84;
    case U = 85;
    case V = 86;
    case W = 87;
    case X = 88;
    case Y = 89;
    case Z = 90;
    case Shift = 16;
    case Ctrl = 17;
    case Alt = 18;
    case F1 = 112;
    case F2 = 113;
    case F3 = 114;
    case F4 = 115;
    case F5 = 116;
    case F6 = 117;
    case F7 = 118;
    case F8 = 119;
    case F9 = 120;
    case F10 = 121;
    case F11 = 122;
    case F12 = 123;
}

// ── KeyMod：修饰键位掩码（映射 SAPP_MODIFIER_*）──
enum KeyMod: int
{
    case Shift = 1;
    case Ctrl = 2;
    case Alt = 4;
    case Super = 8;
}

// ── Cursor：鼠标光标类型 ──
enum Cursor: int
{
    case Arrow = 0;
    case IBeam = 1;
    case Cross = 2;
    case Hand = 3;
    case ResizeX = 4;
    case ResizeY = 5;
    case ResizeAll = 6;
    case None = 7;
}

// ── Direction：布局方向 ──
enum Direction: int
{
    case Row = 0;
    case Column = 1;
}

// ── WidgetState：控件状态 ──
enum WidgetState: int
{
    case Normal = 0;
    case Hovered = 1;
    case Pressed = 2;
    case Focused = 3;
    case Disabled = 4;
}

// ── LayoutAlign：布局对齐方式 ──
enum LayoutAlign: int
{
    case Start = 0;
    case Center = 1;
    case End = 2;
    case Stretch = 3;
}

// ── ChildSize：子元素尺寸模式 ──
enum ChildSize: int
{
    case Compact = 0;
    case Stretch = 1;
    case Fixed = 2;
}
