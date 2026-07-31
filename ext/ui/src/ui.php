<?php
// ext/ui/src/ui.php — UI 扩展主入口
// 纯 phpc 模式：C 函数在 ui.h，PHP 类在本文件
//
// 编译器标志通过 #flag 声明，C 头文件通过 #include 引入。
// 所有 UI 类使用 namespace UI，用户通过 use UI\App; 引入。
//
// C 函数对接模式（参考 ext/gd）：
//   - function C.xxx(...): C.ret;  声明 C 函数签名（带类型，无需类型推导）
//   - C->xxx(...)                  直接调用 C 函数（CodeGenerator 生成 xxx(...)）
//   - 参数/返回类型使用 TinyPHP 内部类型（t_int/t_string/t_callback/t_float）
//     避免 C 标准类型（int/char*）的转换开销和信息丢失
//
// 注意：namespace 必须在 #flag/#include 之前（TinyPHP 解析器要求）

namespace UI;

// ── 编译器标志 ──
// sokol 头文件路径
#flag -I__EXT__ . "ui/sokol"
// 兼容头文件路径（TCC 缺失的 windowsx.h 等）
#flag -I__EXT__ . "ui/compat"
// 平台链接库
#flag windows -lgdi32 -luser32 -lopengl32 -lshell32
#flag linux -lX11 -lGL -lXi -lXcursor -ldl -lpthread
#flag darwin -framework Cocoa -framework MetalKit -framework Metal

#include __EXT__ . "ui/src/ui.h"

// ════════════════════════════════════════════════════════════
// C 函数签名声明（vlang 风格 function C.foo(...): C.ret;）
//
// 声明 ui.h 中所有 C 函数的签名，使用 TinyPHP 内部类型：
//   t_int       — 整数（对应 C 的 t_int）
//   t_float     — 浮点数（对应 C 的 t_float）
//   t_string    — 字符串（对应 C 的 t_string，含 .data/.len 字段）
//   t_callback  — 回调（对应 C 的 t_callback，含 .func/.env 字段）
//   void        — 无返回值
//
// 声明后 CodeGenerator 据此进行类型推导和参数透传（无需 simpleFnMap 注册）
// ════════════════════════════════════════════════════════════

// App 类
function C.ui_app_run(t_int $width, t_int $height, t_string $title): t_int;
function C.ui_app_on_init(t_callback $cb): void;
function C.ui_app_on_frame(t_callback $cb): void;
function C.ui_app_on_event(t_callback $cb): void;

// Window 类
function C.ui_window_width(): t_int;
function C.ui_window_height(): t_int;
function C.ui_window_dpi_scale(): t_float;
function C.ui_window_set_cursor(t_int $cursor): void;

// Graphics 绘图 API
function C.ui_clear(t_int $rgba): void;
function C.ui_end_frame(): void;
function C.ui_fill_rect(t_int $x, t_int $y, t_int $w, t_int $h, t_int $rgba): void;
function C.ui_draw_text(t_int $x, t_int $y, t_string $text, t_int $rgba): void;
function C.ui_draw_line(t_int $x1, t_int $y1, t_int $x2, t_int $y2, t_int $rgba): void;
function C.ui_draw_rect(t_int $x, t_int $y, t_int $w, t_int $h, t_int $rgba): void;
function C.ui_draw_circle(t_int $cx, t_int $cy, t_int $r, t_int $rgba): void;

// 事件查询（从 sapp_event 指针提取字段）
function C.ui_event_type(t_int $ev_ptr): t_int;
function C.ui_event_x(t_int $ev_ptr): t_int;
function C.ui_event_y(t_int $ev_ptr): t_int;
function C.ui_event_button(t_int $ev_ptr): t_int;
function C.ui_event_key(t_int $ev_ptr): t_int;
function C.ui_event_modifiers(t_int $ev_ptr): t_int;
function C.ui_event_codepoint(t_int $ev_ptr): t_int;
function C.ui_event_touch_count(t_int $ev_ptr): t_int;

// SoftInput 软键盘
function C.ui_softinput_show(): void;
function C.ui_softinput_hide(): void;
function C.ui_softinput_on_input(t_callback $cb): void;
function C.ui_softinput_dispatch(t_int $codepoint): void;
function C.ui_softinput_clear_cb(): void;

// ════════════════════════════════════════════════════════════
// App — 应用入口，管理窗口生命周期和回调
//
// 用法：
//   use UI\App;
//   $app = new App(800, 600, "My App");
//   $app->onInit(function() { /* 初始化 */ });
//   $app->onFrame(function() { Graphics::clear(Color::black()); /* 绘图 */ });
//   $app->onEvent(function(int $evPtr) { $ev = Event::fromPtr($evPtr); /* 处理事件 */ });
//   $app->run();
// ════════════════════════════════════════════════════════════

class App
{
    public int $width;
    public int $height;
    public string $title;

    public function __construct(int $width, int $height, string $title)
    {
        $this->width = $width;
        $this->height = $height;
        $this->title = $title;
    }

    public function onInit(callable $cb): void
    {
        C->ui_app_on_init($cb);
    }

    public function onFrame(callable $cb): void
    {
        C->ui_app_on_frame($cb);
    }

    public function onEvent(callable $cb): void
    {
        C->ui_app_on_event($cb);
    }

    public function run(): void
    {
        C->ui_app_run($this->width, $this->height, $this->title);
    }
}

// ════════════════════════════════════════════════════════════
// Window — 窗口查询接口（静态类）
//
// 提供窗口尺寸、DPI 缩放、光标设置等查询/控制方法。
// ════════════════════════════════════════════════════════════

class Window
{
    public static function width(): int { return C->ui_window_width(); }
    public static function height(): int { return C->ui_window_height(); }
    public static function dpiScale(): float { return C->ui_window_dpi_scale(); }
    public static function setCursor(int $cursor): void { C->ui_window_set_cursor($cursor); }
}

// ════════════════════════════════════════════════════════════
// Event — 事件对象（从 C 事件指针解析）
//
// 由 Event::fromPtr(int $evPtr) 工厂方法创建，
// 从 sokol sapp_event 指针提取各字段。
// ════════════════════════════════════════════════════════════

class Event
{
    public int $type = 0;
    public int $x = 0;
    public int $y = 0;
    public int $button = 0;
    public int $key = 0;
    public int $modifiers = 0;
    public int $codepoint = 0;
    public int $touchCount = 0;

    public static function fromPtr(int $evPtr): Event
    {
        $e = new Event();
        $e->type = C->ui_event_type($evPtr);
        $e->x = C->ui_event_x($evPtr);
        $e->y = C->ui_event_y($evPtr);
        $e->button = C->ui_event_button($evPtr);
        $e->key = C->ui_event_key($evPtr);
        $e->modifiers = C->ui_event_modifiers($evPtr);
        $e->codepoint = C->ui_event_codepoint($evPtr);
        $e->touchCount = C->ui_event_touch_count($evPtr);
        return $e;
    }
}

// ════════════════════════════════════════════════════════════
// Color — RGBA 颜色（0-255 分量）
//
// 颜色编码：sokol 使用 0xAABBGGRR 格式（little-endian RGBA）
//   toUint() 返回 0xAABBGGRR，供 Graphics 绘图 API 使用
// ════════════════════════════════════════════════════════════

class Color
{
    public int $r = 0;
    public int $g = 0;
    public int $b = 0;
    public int $a = 255;

    public function __construct(int $r = 0, int $g = 0, int $b = 0, int $a = 255)
    {
        $this->r = $r;
        $this->g = $g;
        $this->b = $b;
        $this->a = $a;
    }

    public function toUint(): int
    {
        // sokol 颜色格式：0xAABBGGRR
        return ($this->a << 24) | ($this->b << 16) | ($this->g << 8) | $this->r;
    }

    public static function black(): Color { return new Color(0, 0, 0, 255); }
    public static function white(): Color { return new Color(255, 255, 255, 255); }
    public static function red(): Color { return new Color(255, 0, 0, 255); }
    public static function green(): Color { return new Color(0, 255, 0, 255); }
    public static function blue(): Color { return new Color(0, 0, 255, 255); }
}

// ════════════════════════════════════════════════════════════
// Rect — 矩形区域（x, y, width, height）
//
// 用于控件边界、布局计算、碰撞检测。
// ════════════════════════════════════════════════════════════

class Rect
{
    public int $x = 0;
    public int $y = 0;
    public int $width = 0;
    public int $height = 0;

    public function __construct(int $x = 0, int $y = 0, int $width = 0, int $height = 0)
    {
        $this->x = $x;
        $this->y = $y;
        $this->width = $width;
        $this->height = $height;
    }

    public function contains(int $x, int $y): bool
    {
        return $x >= $this->x && $x < $this->x + $this->width &&
               $y >= $this->y && $y < $this->y + $this->height;
    }
}

// ════════════════════════════════════════════════════════════
// Graphics — 2D 绘图 API（静态类）
//
// 所有绘图方法必须在 frame 回调内调用（onFrame 注册的闭包中），
// 否则抛出 Exception("drawing outside frame callback")。
//
// 颜色格式：0xAABBGGRR（通过 Color::toUint() 转换）
// ════════════════════════════════════════════════════════════

class Graphics
{
    public static function clear(Color $color): void
    {
        C->ui_clear($color->toUint());
    }

    public static function fillRect(Rect $rect, Color $color): void
    {
        C->ui_fill_rect($rect->x, $rect->y, $rect->width, $rect->height, $color->toUint());
    }

    public static function drawText(int $x, int $y, string $text, Color $color): void
    {
        C->ui_draw_text($x, $y, $text, $color->toUint());
    }

    public static function drawLine(int $x1, int $y1, int $x2, int $y2, Color $color): void
    {
        C->ui_draw_line($x1, $y1, $x2, $y2, $color->toUint());
    }

    public static function drawRect(Rect $rect, Color $color): void
    {
        C->ui_draw_rect($rect->x, $rect->y, $rect->width, $rect->height, $color->toUint());
    }

    public static function drawCircle(int $cx, int $cy, int $r, Color $color): void
    {
        C->ui_draw_circle($cx, $cy, $r, $color->toUint());
    }
}
