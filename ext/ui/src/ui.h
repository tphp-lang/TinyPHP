#pragma once
// ============================================================
// ui.h — UI 扩展 C 包装层（基于 sokol C 库）
//
// 设计说明（参考 ext/openssl、ext/gd 的 phpc 模式）：
//   - 底层使用 sokol C 库（sokol_app/sokol_gfx/sokol_glue/sokol_log/sokol_time）
//     源码已下载到 ext/ui/sokol/，通过 #flag -I 指定头文件路径
//   - SOKOL_IMPL 在本文件中定义，sokol 实现编译为生成 C 文件的一部分
//   - 所有 _ui_* 函数接收 TinyPHP 类型（t_int/t_string/t_bool/t_float）
//   - PHP 侧调用 ui_app_run(...) 自动映射到 ui_app_run(...)
//     （CodeGenerator 自动加 tphp_fn_ 前缀，不带命名空间前缀）
//   - 错误统一 tp_throw（可被 try-catch 捕获）
//   - 事件指针以 t_int 流转（intptr_t 转换，PHP 侧用 Event::fromPtr 解析）
//
// 包含顺序约定：
//   TinyPHP 运行时头文件必须在 sokol 之前（确保类型定义可用）
//   sokol 头文件通过 #flag -I__EXT__ . "ui/sokol" 查找
// ============================================================

// ── TinyPHP 运行时头文件（必须在 sokol 之前）──
#include "types.h"
#include "object/object.h"
#include "object/exception.h"
#include "object/try.h"
#include "val.h"
#include "phpc.h"

// ── SAL 标注兼容 ──
// clang on Windows: UCRT 头使用 _In_opt_z_/_Check_return_ 等 SAL 注解,
//   clang 不像 MSVC 自动包含 <sal.h>,需显式包含。
// TCC: <sal.h> 不可用,手动定义常用注解为空（sokol WinMain 需要）。
#if defined(_WIN32) && defined(__clang__)
#include <sal.h>
#endif
#ifndef _In_
#define _In_
#endif
#ifndef _In_opt_
#define _In_opt_
#endif

// ── TCC 兼容：user32.def 缺少 RegisterRawInputDevices / GetRawInputData ──
//   这两个函数仅在鼠标指针锁定（mouse_lock）时调用，UI 单元测试不使用。
//   提供 stub 满足链接器，运行时不会被调用。
//   注意：必须在 sokol_app.h 包含之前定义，#define 重定向才能对 sokol 头文件内的
//   调用点生效（预处理器按顺序展开宏）。
#if defined(_WIN32) && defined(__TINYC__)
static __attribute__((used)) BOOL __stdcall _tphp_RegisterRawInputDevices_stub(
    const void* pRawInputDevices, UINT uiNumDevices, UINT cbSize) {
    (void)pRawInputDevices; (void)uiNumDevices; (void)cbSize;
    return TRUE;
}
static __attribute__((used)) UINT __stdcall _tphp_GetRawInputData_stub(
    void* hRawInput, UINT uiCommand, void* pData, UINT* pcbSize, UINT cbSizeHeader) {
    (void)hRawInput; (void)uiCommand; (void)pData; (void)pcbSize; (void)cbSizeHeader;
    return 0;
}
// 重定向 sokol 的调用到 stub（必须在 #include "sokol_app.h" 之前）
#define RegisterRawInputDevices _tphp_RegisterRawInputDevices_stub
#define GetRawInputData _tphp_GetRawInputData_stub
#endif

// ── sokol 实现（编译为生成 C 文件的一部分）──
// 注意：SOKOL_IMPL 必须在 sokol 头文件包含之前定义
// SOKOL_NO_ENTRY：使用 sapp_run(&desc) 模式而非 sokol_main() 入口点
// 平台后端选择：Windows=GLCORE, macOS=Metal, Linux=GLCORE
// （TCC 不含 windowsx.h，故 Windows 使用 OpenGL 而非 D3D11）
#if defined(_WIN32) || defined(_WIN64)
    #define SOKOL_GLCORE
#elif defined(__APPLE__)
    #define SOKOL_METAL
    // macOS ObjC 模式: AppKit→PrintCore→cups→netinet/ip.h 使用 BSD 遗留类型 u_int/u_char/u_short。
    //   ObjC 编译模式下 _POSIX_C_SOURCE 可能被系统头设置,导致 <sys/types.h> 的 BSD 类型被 guard 排除。
    //   直接定义缺失类型,避免依赖系统头的不确定行为。
    #ifndef u_char
    typedef unsigned char  u_char;
    #endif
    #ifndef u_short
    typedef unsigned short u_short;
    #endif
    #ifndef u_int
    typedef unsigned int   u_int;
    #endif
    #ifndef u_long
    typedef unsigned long  u_long;
    #endif
#elif defined(__ANDROID__)
    #define SOKOL_GLES3
#elif defined(__linux__)
    #define SOKOL_GLCORE
#endif
#define SOKOL_IMPL
#if !defined(__ANDROID__)
    #define SOKOL_NO_ENTRY
#endif
// macOS: sokol_app.h #import <AppKit/AppKit.h>, ObjC 头中 +null 方法声明
//   会被 types.h 的 #define null ((void *)0) 破坏（展开为 +((void*)0) 语法错误）。
//   sokol 头中 null 仅出现在注释,无 C 标识符使用,undef 安全。
#if defined(__APPLE__)
#undef null
#endif
#if defined(__ANDROID__)
    #include <android/native_activity.h>
    #include <jni.h>
#endif
#include "sokol_app.h"
#include "sokol_gfx.h"
#include "sokol_glue.h"
#include "sokol_log.h"
#include "sokol_time.h"
#if defined(__APPLE__)
#define null ((void *)0)
#endif

// ── DrawDevice：绘图设备抽象（函数指针表）──
//   参考 vlang/ui 的 DrawDevice 接口设计，允许 sokol(GPU) 和 CPU(软件) 两种后端。
//   ui_clear/ui_fill_rect 等公共 API 通过 _ui_device 分派到具体后端实现。
typedef struct ui_draw_device {
    void (*begin_pass)(t_int rgba);  // 清屏 + 开始 pass
    void (*end_pass)(void);           // 结束 pass + 准备上屏
    void (*fill_rect)(t_int x, t_int y, t_int w, t_int h, t_int rgba);
    void (*draw_text)(t_int x, t_int y, const char* text, int len, t_int rgba);
    void (*draw_line)(t_int x1, t_int y1, t_int x2, t_int y2, t_int rgba);
    void (*draw_rect)(t_int x, t_int y, t_int w, t_int h, t_int rgba);
    void (*draw_circle)(t_int cx, t_int cy, t_int r, t_int rgba);
} ui_draw_device_t;

// ── 后端类型 ──
typedef enum {
    UI_BACKEND_NONE = 0,   // 未初始化
    UI_BACKEND_SOKOL,      // GPU 加速（sokol_gfx）
    UI_BACKEND_CPU,        // CPU 软件渲染
} ui_backend_t;

// ── UI 全局状态结构 ──
typedef struct {
    bool initialized;       // sg_setup 是否已调用
    bool in_frame;          // 是否在 frame 回调内（绘图 API 前置条件）
    bool pass_active;       // begin_pass 已调用，需匹配 end_pass
    ui_backend_t backend;   // 当前后端类型
    ui_draw_device_t* device; // 当前绘图设备（sokol 或 CPU）
    t_callback on_init_cb;  // PHP 侧 init 回调
    t_callback on_frame_cb; // PHP 侧 frame 回调
    t_callback on_event_cb; // PHP 侧 event 回调
    // sokol 绘图管线状态（仅 GPU 后端使用）
    sg_pipeline quad_pip;   // 矩形绘制管线
    sg_buffer quad_buf;     // 顶点缓冲区
} ui_state_t;

static ui_state_t _ui_state;

#if defined(__ANDROID__)
static sapp_desc _ui_android_desc;
// 前向声明：CodeGenerator 在 Android 模式下生成（替代 main()）
int tphp_android_main(int argc, char* argv[]);
#endif

// ── 自定义 sokol 日志函数 ──
//   替代 sokol_log.h 的 slog_func：
//   - panic 级别（log_level==0）：输出到 stderr 后 tp_throw（可被 try-catch 捕获），
//     而非 slog_func 的 abort()（直接杀死进程，PHP 侧无法处理）
//   - 其他级别：输出到 stderr
static void _ui_slog_func(const char* tag, uint32_t log_level, uint32_t log_item,
                          const char* message, uint32_t line_nr, const char* filename,
                          void* user_data) {
    (void)log_item; (void)line_nr; (void)filename; (void)user_data;
    const char* level_str;
    switch (log_level) {
        case 0:  level_str = "panic";   break;
        case 1:  level_str = "error";   break;
        case 2:  level_str = "warning"; break;
        default: level_str = "info";    break;
    }
    fflush(stdout);
    fprintf(stderr, "[%s][%s] %s\n",
            tag ? tag : "?", level_str,
            message ? message : "(no message)");
    fflush(stderr);
    if (log_level == 0) {
        // panic：抛异常而非 abort()，让 PHP 侧能 try-catch 或至少看到错误信息
        tp_throw(message ? message : "sokol panic");
    }
}

// ── sokol 后端 DrawDevice 实现 ──
//   begin_pass/end_pass 复用 sokol pass 管理（sg_begin_pass/sg_end_pass/sg_commit）；
//   形状和文字都通过即时模式顶点缓冲区实现（每帧收集顶点，end_pass 时统一绘制）。
//   文字使用点阵字体（init 时由 GDI 生成），展开为 1×1 像素矩形顶点。

// ── 形状渲染器（即时模式）──
//   每帧收集顶点到内存缓冲区，end_pass 时上传到 GPU 并绘制
//   文字也通过此缓冲区渲染（点阵字体展开为顶点），与形状一起在 GPU 上绘制，
//   避免 GDI + OpenGL 双缓冲冲突导致的闪屏。
#define _SR_MAX_VERTICES 65536
typedef struct {
    float x, y;    // 屏幕坐标
    float r, g, b, a; // 颜色
} _sr_vertex_t;

static struct {
    sg_pipeline pip;
    sg_buffer vbuf;
    _sr_vertex_t verts[_SR_MAX_VERTICES];
    int count;
    bool initialized;
} _sr;

// ── 内置 8x8 点阵字体（font8x8，公共域）──
//   跨平台：不依赖 GDI/FreeType，桌面和 Android 通用。
//   每个字符 8x8 像素，每行 1 字节（bit 0 = 最左列）。
//   draw_text 时每个亮点展开为 1×1 像素矩形（6 个顶点），
//   通过 _sr_push_vertex 加入形状缓冲区，与 fill_rect 等一起在 GPU 上绘制。
#define _FONT_W 8
#define _FONT_H 8
// ASCII 32-126 共 95 个可打印字符；索引 0 = space(32)
static const uint8_t _font8x8[95][8] = {
    {0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00}, /*   */
    {0x18,0x3C,0x3C,0x18,0x18,0x00,0x18,0x00}, /* ! */
    {0x36,0x36,0x00,0x00,0x00,0x00,0x00,0x00}, /* " */
    {0x36,0x36,0x7F,0x36,0x7F,0x36,0x36,0x00}, /* # */
    {0x0C,0x3E,0x03,0x1E,0x30,0x1F,0x0C,0x00}, /* $ */
    {0x00,0x63,0x33,0x18,0x0C,0x66,0x63,0x00}, /* % */
    {0x1C,0x36,0x1C,0x6E,0x3B,0x33,0x6E,0x00}, /* & */
    {0x06,0x06,0x03,0x00,0x00,0x00,0x00,0x00}, /* ' */
    {0x18,0x30,0x60,0x60,0x60,0x30,0x18,0x00}, /* ( */
    {0x0C,0x06,0x03,0x03,0x03,0x06,0x0C,0x00}, /* ) */
    {0x00,0x66,0x3C,0xFF,0x3C,0x66,0x00,0x00}, /* * */
    {0x00,0x0C,0x0C,0x3F,0x0C,0x0C,0x00,0x00}, /* + */
    {0x00,0x00,0x00,0x00,0x00,0x0C,0x0C,0x06}, /* , */
    {0x00,0x00,0x00,0x3F,0x00,0x00,0x00,0x00}, /* - */
    {0x00,0x00,0x00,0x00,0x00,0x0C,0x0C,0x00}, /* . */
    {0x00,0x60,0x30,0x18,0x0C,0x06,0x03,0x00}, /* / */
    {0x3E,0x63,0x73,0x7B,0x6F,0x67,0x3E,0x00}, /* 0 */
    {0x0C,0x0E,0x0C,0x0C,0x0C,0x0C,0x3F,0x00}, /* 1 */
    {0x1E,0x33,0x30,0x1C,0x06,0x33,0x3F,0x00}, /* 2 */
    {0x1E,0x33,0x30,0x1C,0x30,0x33,0x1E,0x00}, /* 3 */
    {0x38,0x3C,0x36,0x33,0x7F,0x30,0x78,0x00}, /* 4 */
    {0x3F,0x03,0x1F,0x30,0x30,0x33,0x1E,0x00}, /* 5 */
    {0x1C,0x06,0x03,0x1F,0x33,0x33,0x1E,0x00}, /* 6 */
    {0x3F,0x33,0x30,0x18,0x0C,0x0C,0x0C,0x00}, /* 7 */
    {0x1E,0x33,0x33,0x1E,0x33,0x33,0x1E,0x00}, /* 8 */
    {0x1E,0x33,0x33,0x3E,0x30,0x18,0x0C,0x00}, /* 9 */
    {0x00,0x0C,0x0C,0x00,0x00,0x0C,0x0C,0x00}, /* : */
    {0x00,0x0C,0x0C,0x00,0x00,0x0C,0x0C,0x06}, /* ; */
    {0x18,0x0C,0x06,0x03,0x06,0x0C,0x18,0x00}, /* < */
    {0x00,0x00,0x3F,0x00,0x00,0x3F,0x00,0x00}, /* = */
    {0x06,0x0C,0x18,0x30,0x18,0x0C,0x06,0x00}, /* > */
    {0x1E,0x33,0x30,0x18,0x0C,0x00,0x0C,0x00}, /* ? */
    {0x3E,0x63,0x7B,0x7B,0x7B,0x03,0x1E,0x00}, /* @ */
    {0x0C,0x1E,0x33,0x33,0x3F,0x33,0x33,0x00}, /* A */
    {0x3F,0x66,0x66,0x3E,0x66,0x66,0x3F,0x00}, /* B */
    {0x3C,0x66,0x03,0x03,0x03,0x66,0x3C,0x00}, /* C */
    {0x1F,0x36,0x66,0x66,0x66,0x36,0x1F,0x00}, /* D */
    {0x7F,0x46,0x16,0x06,0x16,0x46,0x7F,0x00}, /* E */
    {0x7F,0x46,0x16,0x06,0x16,0x06,0x0F,0x00}, /* F */
    {0x3C,0x66,0x03,0x03,0x73,0x66,0x7C,0x00}, /* G */
    {0x33,0x33,0x33,0x3F,0x33,0x33,0x33,0x00}, /* H */
    {0x1E,0x0C,0x0C,0x0C,0x0C,0x0C,0x1E,0x00}, /* I */
    {0x78,0x30,0x30,0x30,0x33,0x33,0x1E,0x00}, /* J */
    {0x67,0x66,0x36,0x1E,0x36,0x66,0x67,0x00}, /* K */
    {0x0F,0x06,0x06,0x06,0x46,0x66,0x7F,0x00}, /* L */
    {0x63,0x77,0x7F,0x7F,0x6B,0x63,0x63,0x00}, /* M */
    {0x63,0x67,0x6F,0x7B,0x73,0x63,0x63,0x00}, /* N */
    {0x1C,0x36,0x63,0x63,0x63,0x36,0x1C,0x00}, /* O */
    {0x3F,0x66,0x66,0x3E,0x06,0x06,0x0F,0x00}, /* P */
    {0x1E,0x33,0x33,0x33,0x3B,0x1E,0x38,0x00}, /* Q */
    {0x3F,0x66,0x66,0x3E,0x36,0x66,0x67,0x00}, /* R */
    {0x1E,0x33,0x07,0x0E,0x38,0x33,0x1E,0x00}, /* S */
    {0x3F,0x2D,0x0C,0x0C,0x0C,0x0C,0x1E,0x00}, /* T */
    {0x33,0x33,0x33,0x33,0x33,0x33,0x3F,0x00}, /* U */
    {0x33,0x33,0x33,0x33,0x33,0x1E,0x0C,0x00}, /* V */
    {0x63,0x63,0x6B,0x7F,0x7F,0x77,0x63,0x00}, /* W */
    {0x63,0x36,0x1C,0x1C,0x1C,0x36,0x63,0x00}, /* X */
    {0x33,0x33,0x33,0x1E,0x0C,0x0C,0x1E,0x00}, /* Y */
    {0x7F,0x63,0x31,0x18,0x4C,0x66,0x7F,0x00}, /* Z */
    {0x1E,0x06,0x06,0x06,0x06,0x06,0x1E,0x00}, /* [ */
    {0x03,0x06,0x0C,0x18,0x30,0x60,0x40,0x00}, /* \ */
    {0x1E,0x18,0x18,0x18,0x18,0x18,0x1E,0x00}, /* ] */
    {0x08,0x1C,0x36,0x63,0x00,0x00,0x00,0x00}, /* ^ */
    {0x00,0x00,0x00,0x00,0x00,0x00,0x00,0xFF}, /* _ */
    {0x0C,0x0C,0x18,0x00,0x00,0x00,0x00,0x00}, /* ` */
    {0x00,0x00,0x3E,0x30,0x3E,0x33,0x3E,0x00}, /* a */
    {0x07,0x06,0x06,0x3E,0x66,0x66,0x3B,0x00}, /* b */
    {0x00,0x00,0x3E,0x03,0x03,0x33,0x1E,0x00}, /* c */
    {0x38,0x30,0x30,0x3E,0x33,0x33,0x3E,0x00}, /* d */
    {0x00,0x00,0x1E,0x33,0x3F,0x03,0x1E,0x00}, /* e */
    {0x1C,0x36,0x06,0x0F,0x06,0x06,0x0F,0x00}, /* f */
    {0x00,0x00,0x3E,0x33,0x33,0x3E,0x30,0x1F}, /* g */
    {0x07,0x06,0x06,0x3E,0x66,0x66,0x66,0x00}, /* h */
    {0x0C,0x00,0x0E,0x0C,0x0C,0x0C,0x1E,0x00}, /* i */
    {0x30,0x00,0x30,0x30,0x30,0x33,0x33,0x1E}, /* j */
    {0x07,0x06,0x66,0x36,0x1E,0x36,0x66,0x00}, /* k */
    {0x0E,0x0C,0x0C,0x0C,0x0C,0x0C,0x1E,0x00}, /* l */
    {0x00,0x00,0x33,0x7F,0x7F,0x6B,0x63,0x00}, /* m */
    {0x00,0x00,0x1F,0x33,0x33,0x33,0x33,0x00}, /* n */
    {0x00,0x00,0x1E,0x33,0x33,0x33,0x1E,0x00}, /* o */
    {0x00,0x00,0x3B,0x66,0x66,0x3E,0x06,0x0F}, /* p */
    {0x00,0x00,0x3E,0x33,0x33,0x3E,0x30,0x78}, /* q */
    {0x00,0x00,0x3B,0x66,0x06,0x06,0x0F,0x00}, /* r */
    {0x00,0x00,0x3E,0x03,0x1E,0x30,0x1E,0x00}, /* s */
    {0x06,0x06,0x0F,0x06,0x06,0x36,0x1C,0x00}, /* t */
    {0x00,0x00,0x33,0x33,0x33,0x33,0x3E,0x00}, /* u */
    {0x00,0x00,0x33,0x33,0x33,0x1E,0x0C,0x00}, /* v */
    {0x00,0x00,0x63,0x6B,0x7F,0x7F,0x36,0x00}, /* w */
    {0x00,0x00,0x63,0x36,0x1C,0x36,0x63,0x00}, /* x */
    {0x00,0x00,0x33,0x33,0x33,0x3E,0x30,0x1F}, /* y */
    {0x00,0x00,0x3F,0x19,0x0C,0x26,0x3F,0x00}, /* z */
    {0x38,0x0C,0x0C,0x07,0x0C,0x0C,0x38,0x00}, /* { */
    {0x00,0x0C,0x0C,0x0C,0x0C,0x0C,0x0C,0x00}, /* | */
    {0x07,0x18,0x18,0x70,0x18,0x18,0x07,0x00}, /* } */
    {0x6E,0x3B,0x00,0x00,0x00,0x00,0x00,0x00}, /* ~ */
};

// 纯色 shader：顶点接收屏幕坐标+颜色，片段输出颜色
//   桌面 GL 3.3 和 GLES 3.0 共用同一份 GLSL ES 3.0 源码
#if defined(SOKOL_GLCORE)
static const char* _sr_vs_src =
    "#version 330 core\n"
    "layout(location=0) in vec2 pos;\n"
    "layout(location=1) in vec4 color;\n"
    "uniform vec2 u_resolution;\n"
    "out vec4 v_color;\n"
    "void main() {\n"
    "    vec2 ndc = (pos / u_resolution) * 2.0 - 1.0;\n"
    "    gl_Position = vec4(ndc.x, -ndc.y, 0.0, 1.0);\n"
    "    v_color = color;\n"
    "}\n";
static const char* _sr_fs_src =
    "#version 330 core\n"
    "in vec4 v_color;\n"
    "out vec4 frag_color;\n"
    "void main() { frag_color = v_color; }\n";
#elif defined(SOKOL_GLES3)
static const char* _sr_vs_src =
    "#version 300 es\n"
    "precision highp float;\n"
    "layout(location=0) in vec2 pos;\n"
    "layout(location=1) in vec4 color;\n"
    "uniform vec2 u_resolution;\n"
    "out vec4 v_color;\n"
    "void main() {\n"
    "    vec2 ndc = (pos / u_resolution) * 2.0 - 1.0;\n"
    "    gl_Position = vec4(ndc.x, -ndc.y, 0.0, 1.0);\n"
    "    v_color = color;\n"
    "}\n";
static const char* _sr_fs_src =
    "#version 300 es\n"
    "precision highp float;\n"
    "in vec4 v_color;\n"
    "out vec4 frag_color;\n"
    "void main() { frag_color = v_color; }\n";
#elif defined(SOKOL_METAL)
// Metal 后端需要 MSL（Metal Shading Language）源码，entry 函数名必须显式提供
static const char* _sr_vs_src =
    "#include <metal_stdlib>\n"
    "using namespace metal;\n"
    "struct v_in { float2 pos [[attribute(0)]]; float4 color [[attribute(1)]]; };\n"
    "struct v_out { float4 position [[position]]; float4 v_color; };\n"
    "vertex v_out vs_main(v_in in [[stage_in]], constant float2& u_resolution [[buffer(0)]]) {\n"
    "    v_out out;\n"
    "    float2 ndc = (in.pos / u_resolution) * 2.0 - 1.0;\n"
    "    out.position = float4(ndc.x, -ndc.y, 0.0, 1.0);\n"
    "    out.v_color = in.color;\n"
    "    return out;\n"
    "}\n";
static const char* _sr_fs_src =
    "#include <metal_stdlib>\n"
    "using namespace metal;\n"
    "struct v_out { float4 position [[position]]; float4 v_color; };\n"
    "fragment float4 fs_main(v_out in [[stage_in]]) {\n"
    "    return in.v_color;\n"
    "}\n";
#endif

// Metal 后端需要显式 entry 函数名；GL 后端 entry 为 NULL（按 GLSL main 约定）
#if defined(SOKOL_METAL)
    #define _SR_VS_ENTRY "vs_main"
    #define _SR_FS_ENTRY "fs_main"
#else
    #define _SR_VS_ENTRY NULL
    #define _SR_FS_ENTRY NULL
#endif

static void _sr_init(void) {
    if (_sr.initialized) return;
    _sr.vbuf = sg_make_buffer(&(sg_buffer_desc){
        .size = sizeof(_sr_vertex_t) * _SR_MAX_VERTICES,
        .usage = { .stream_update = true },
    });
    sg_shader shd = sg_make_shader(&(sg_shader_desc){
        .vertex_func = { .source = _sr_vs_src, .entry = _SR_VS_ENTRY },
        .fragment_func = { .source = _sr_fs_src, .entry = _SR_FS_ENTRY },
        .attrs = {
            [0] = { .glsl_name = "pos", .hlsl_sem_name = "POS" },
            [1] = { .glsl_name = "color", .hlsl_sem_name = "COLOR" },
        },
        .uniform_blocks[0] = {
            .stage = SG_SHADERSTAGE_VERTEX,
            .size = sizeof(float) * 2,
            .glsl_uniforms = { [0] = { .glsl_name = "u_resolution", .type = SG_UNIFORMTYPE_FLOAT2 } },
        },
    });
    _sr.pip = sg_make_pipeline(&(sg_pipeline_desc){
        .shader = shd,
        .layout = {
            .attrs = {
                [0] = { .format = SG_VERTEXFORMAT_FLOAT2 },
                [1] = { .format = SG_VERTEXFORMAT_FLOAT4 },
            },
        },
        .colors[0] = { .blend = {
            .enabled = true,
            .src_factor_rgb = SG_BLENDFACTOR_SRC_ALPHA,
            .dst_factor_rgb = SG_BLENDFACTOR_ONE_MINUS_SRC_ALPHA,
        }},
    });
    _sr.initialized = true;
}

static inline void _sr_push_vertex(float x, float y, float r, float g, float b, float a) {
    if (_sr.count >= _SR_MAX_VERTICES) return;
    _sr.verts[_sr.count].x = x;
    _sr.verts[_sr.count].y = y;
    _sr.verts[_sr.count].r = r;
    _sr.verts[_sr.count].g = g;
    _sr.verts[_sr.count].b = b;
    _sr.verts[_sr.count].a = a;
    _sr.count++;
}

static inline void _sr_unpack_color(t_int rgba, float* r, float* g, float* b, float* a) {
    *a = (float)((rgba >> 24) & 0xFF) / 255.0f;
    *r = (float)((rgba >> 16) & 0xFF) / 255.0f;
    *g = (float)((rgba >> 8) & 0xFF) / 255.0f;
    *b = (float)(rgba & 0xFF) / 255.0f;
}

static void _sr_flush(void) {
    if (_sr.count == 0) return;
    sg_update_buffer(_sr.vbuf, &(sg_range){ .ptr = _sr.verts, .size = (size_t)_sr.count * sizeof(_sr_vertex_t) });
    sg_apply_pipeline(_sr.pip);
    sg_apply_bindings(&(sg_bindings){ .vertex_buffers[0] = _sr.vbuf });
    float resolution[2] = { (float)sapp_width(), (float)sapp_height() };
    sg_apply_uniforms(0, &(sg_range){ .ptr = resolution, .size = sizeof(resolution) });
    sg_draw(0, _sr.count, 1);
    _sr.count = 0;
}

static void _sokol_begin_pass(t_int rgba) {
    float a = (float)((rgba >> 24) & 0xFF) / 255.0f;
    float r = (float)((rgba >> 16) & 0xFF) / 255.0f;
    float g = (float)((rgba >> 8) & 0xFF) / 255.0f;
    float b = (float)(rgba & 0xFF) / 255.0f;
    sg_pass_action pa = (sg_pass_action){
        .colors[0] = { .load_action = SG_LOADACTION_CLEAR, .clear_value = { r, g, b, a } }
    };
    sg_begin_pass(&(sg_pass){ .action = pa, .swapchain = sglue_swapchain() });
    _sr.count = 0;
}

static void _sokol_end_pass(void) {
    _sr_flush();
    sg_end_pass();
    sg_commit();
}

static void _sokol_fill_rect(t_int x, t_int y, t_int w, t_int h, t_int rgba) {
    float r, g, b, a;
    _sr_unpack_color(rgba, &r, &g, &b, &a);
    float x0 = (float)x, y0 = (float)y;
    float x1 = (float)(x + w), y1 = (float)(y + h);
    _sr_push_vertex(x0, y0, r, g, b, a);
    _sr_push_vertex(x1, y0, r, g, b, a);
    _sr_push_vertex(x1, y1, r, g, b, a);
    _sr_push_vertex(x0, y0, r, g, b, a);
    _sr_push_vertex(x1, y1, r, g, b, a);
    _sr_push_vertex(x0, y1, r, g, b, a);
}

static void _sokol_draw_text(t_int x, t_int y, const char* text, int len, t_int rgba) {
    if (!text || len <= 0) return;
    float r, g, b, a;
    _sr_unpack_color(rgba, &r, &g, &b, &a);
    for (int i = 0; i < len; i++) {
        uint8_t c = (uint8_t)text[i];
        // 不可打印字符或超出范围用 '?' 替代
        if (c < 32 || c > 126) c = '?';
        const uint8_t* bits = _font8x8[c - 32];
        float fx0 = (float)(x + i * _FONT_W);
        float fy0 = (float)y;
        for (int py = 0; py < _FONT_H; py++) {
            uint8_t row = bits[py];
            if (!row) continue;  // 空行跳过
            for (int px = 0; px < _FONT_W; px++) {
                if (row & (1 << px)) {
                    float vx = fx0 + px;
                    float vy = fy0 + py;
                    // 1×1 像素矩形（2 个三角形 = 6 个顶点）
                    _sr_push_vertex(vx,     vy,     r, g, b, a);
                    _sr_push_vertex(vx + 1, vy,     r, g, b, a);
                    _sr_push_vertex(vx + 1, vy + 1, r, g, b, a);
                    _sr_push_vertex(vx,     vy,     r, g, b, a);
                    _sr_push_vertex(vx + 1, vy + 1, r, g, b, a);
                    _sr_push_vertex(vx,     vy + 1, r, g, b, a);
                }
            }
        }
    }
}

static void _sokol_draw_line(t_int x1, t_int y1, t_int x2, t_int y2, t_int rgba) {
    float r, g, b, a;
    _sr_unpack_color(rgba, &r, &g, &b, &a);
    float dx = (float)(x2 - x1), dy = (float)(y2 - y1);
    float len2 = dx * dx + dy * dy;
    if (len2 < 0.001f) return;
    float inv_len = 1.0f / sqrtf(len2);
    float nx = -dy * inv_len * 0.5f;
    float ny = dx * inv_len * 0.5f;
    float p0x = (float)x1 + nx, p0y = (float)y1 + ny;
    float p1x = (float)x1 - nx, p1y = (float)y1 - ny;
    float p2x = (float)x2 - nx, p2y = (float)y2 - ny;
    float p3x = (float)x2 + nx, p3y = (float)y2 + ny;
    _sr_push_vertex(p0x, p0y, r, g, b, a);
    _sr_push_vertex(p1x, p1y, r, g, b, a);
    _sr_push_vertex(p2x, p2y, r, g, b, a);
    _sr_push_vertex(p0x, p0y, r, g, b, a);
    _sr_push_vertex(p2x, p2y, r, g, b, a);
    _sr_push_vertex(p3x, p3y, r, g, b, a);
}

static void _sokol_draw_rect(t_int x, t_int y, t_int w, t_int h, t_int rgba) {
    _sokol_draw_line(x, y, x + w, y, rgba);
    _sokol_draw_line(x + w, y, x + w, y + h, rgba);
    _sokol_draw_line(x + w, y + h, x, y + h, rgba);
    _sokol_draw_line(x, y + h, x, y, rgba);
}

static void _sokol_draw_circle(t_int cx, t_int cy, t_int r, t_int rgba) {
    float cr, cg, cb, ca;
    _sr_unpack_color(rgba, &cr, &cg, &cb, &ca);
    float fcx = (float)cx, fcy = (float)cy, fr = (float)r;
    int segments = 32;
    for (int i = 0; i < segments; i++) {
        float a0 = (float)i * 2.0f * 3.14159265f / segments;
        float a1 = (float)(i + 1) * 2.0f * 3.14159265f / segments;
        _sr_push_vertex(fcx, fcy, cr, cg, cb, ca);
        _sr_push_vertex(fcx + cosf(a0) * fr, fcy + sinf(a0) * fr, cr, cg, cb, ca);
        _sr_push_vertex(fcx + cosf(a1) * fr, fcy + sinf(a1) * fr, cr, cg, cb, ca);
    }
}

static ui_draw_device_t _ui_sokol_device = {
    .begin_pass  = _sokol_begin_pass,
    .end_pass    = _sokol_end_pass,
    .fill_rect   = _sokol_fill_rect,
    .draw_text   = _sokol_draw_text,
    .draw_line   = _sokol_draw_line,
    .draw_rect   = _sokol_draw_rect,
    .draw_circle = _sokol_draw_circle,
};

// ── sokol 回调桥（C → PHP 回调）──
//   所有回调包裹 TP_TRY/TP_CATCH_ANY：
//   PHP 回调内 tp_throw 触发 longjmp，若不在此捕获会跳过 sokol 事件循环，
//   导致窗口静默崩溃。捕获后输出到 stderr 并 sapp_request_quit() 干净退出。
static void _ui_sokol_init_cb(void) {
    sg_setup(&(sg_desc){
        .environment = sglue_environment(),
        .logger = { .func = _ui_slog_func },
    });
    _sr_init();  // 初始化形状渲染器
    _ui_state.initialized = true;
    _ui_state.in_frame = false;
    _ui_state.pass_active = false;
    if (_ui_state.on_init_cb.func) {
        TP_TRY {
            ((void(*)(void*))_ui_state.on_init_cb.func)(_ui_state.on_init_cb.env);
        } TP_CATCH_ANY(_tp_msg) {
            fflush(stdout);
            fprintf(stderr, "\nFatal error: Uncaught exception in init callback: %s\n\n",
                    STR_PTR(_tp_msg) ? STR_PTR(_tp_msg) : "(null)");
            fflush(stderr);
            sapp_request_quit();
        } TP_END_TRY
    }
}

static void _ui_sokol_frame_cb(void) {
    _ui_state.in_frame = true;
    _ui_state.pass_active = false;
    TP_TRY {
        if (_ui_state.on_frame_cb.func) {
            ((void(*)(void*))_ui_state.on_frame_cb.func)(_ui_state.on_frame_cb.env);
        }
    } TP_CATCH_ANY(_tp_msg) {
        fflush(stdout);
        fprintf(stderr, "\nFatal error: Uncaught exception in frame callback: %s\n\n",
                STR_PTR(_tp_msg) ? STR_PTR(_tp_msg) : "(null)");
        fflush(stderr);
        sapp_request_quit();
    } TP_END_TRY
    // 确保 pass 正确收尾（即使 PHP 回调中途抛异常也不能漏掉 sg_end_pass+sg_commit，
    // 否则 sokol 状态不一致，下一帧渲染崩溃）
    if (_ui_state.pass_active) {
        _sr_flush();  // 绘制所有收集的形状和文字（必须在 sg_end_pass 之前）
        sg_end_pass();
        sg_commit();
        _ui_state.pass_active = false;
    }
    _ui_state.in_frame = false;
}

static void _ui_sokol_event_cb(const sapp_event* ev) {
    // Android: 将触摸事件转换为鼠标事件（PHP 侧统一用 MouseDown/Up/Move 处理）
    // sokol 在 Android 上只生成 TOUCHES_BEGAN/MOVED/ENDED，不设置 mouse_x/mouse_y
    const sapp_event* pass_ev = ev;
    sapp_event converted;
#if defined(__ANDROID__)
    if (ev->num_touches > 0) {
        float tx = ev->touches[0].pos_x;
        float ty = ev->touches[0].pos_y;
        if (ev->type == SAPP_EVENTTYPE_TOUCHES_BEGAN) {
            converted = *ev;
            converted.type = SAPP_EVENTTYPE_MOUSE_DOWN;
            converted.mouse_button = SAPP_MOUSEBUTTON_LEFT;
            converted.mouse_x = tx;
            converted.mouse_y = ty;
            pass_ev = &converted;
        } else if (ev->type == SAPP_EVENTTYPE_TOUCHES_ENDED) {
            converted = *ev;
            converted.type = SAPP_EVENTTYPE_MOUSE_UP;
            converted.mouse_button = SAPP_MOUSEBUTTON_LEFT;
            converted.mouse_x = tx;
            converted.mouse_y = ty;
            pass_ev = &converted;
        } else if (ev->type == SAPP_EVENTTYPE_TOUCHES_MOVED) {
            converted = *ev;
            converted.type = SAPP_EVENTTYPE_MOUSE_MOVE;
            converted.mouse_x = tx;
            converted.mouse_y = ty;
            pass_ev = &converted;
        }
    }
#endif
    if (_ui_state.on_event_cb.func) {
        TP_TRY {
            // 传递 sapp_event 指针给 PHP 侧（以 t_int 形式，PHP 侧用 Event::fromPtr 解析）
            ((void(*)(t_int, void*))_ui_state.on_event_cb.func)((t_int)(intptr_t)pass_ev, _ui_state.on_event_cb.env);
        } TP_CATCH_ANY(_tp_msg) {
            fflush(stdout);
            fprintf(stderr, "\nFatal error: Uncaught exception in event callback: %s\n\n",
                    STR_PTR(_tp_msg) ? STR_PTR(_tp_msg) : "(null)");
            fflush(stderr);
            sapp_request_quit();
        } TP_END_TRY
    }
}

// ── Android 原生按键事件拦截 ──
//   sokol_app.h 的 _sapp_android_key_event 只处理 BACK 键，其他按键直接丢弃，
//   导致 Android 上无 KEY_DOWN/KEY_UP/CHAR 事件，TextBox 无法接收输入。
//   通过 native_event_cb 钩子拦截 AInputEvent，映射 AKEYCODE_* → ASCII/Key 枚举值，
//   构造 sapp_event 并调用 _ui_sokol_event_cb 分发给 PHP 侧。
//   key_code 使用 PHP Key 枚举的 ASCII 值（与桌面端 SAPP_KEYCODE 字母数字段一致，
//   控制键段则与 Key 枚举对齐），确保 PHP 侧 Key::tryFrom($code) 能正确匹配。
#if defined(__ANDROID__)
static int _ui_android_map_keycode(int32_t keycode) {
    // 字母键 AKEYCODE_A(29)..AKEYCODE_Z(54) → 'A'(65)..'Z'(90)
    if (keycode >= 29 /*AKEYCODE_A*/ && keycode <= 54 /*AKEYCODE_Z*/) {
        return 65 + (keycode - 29);
    }
    // 数字键 AKEYCODE_0(7)..AKEYCODE_9(16) → '0'(48)..'9'(57)
    if (keycode >= 7 /*AKEYCODE_0*/ && keycode <= 16 /*AKEYCODE_9*/) {
        return 48 + (keycode - 7);
    }
    switch (keycode) {
        case 62 /*AKEYCODE_SPACE*/:      return 32;   // Key::Space
        case 66 /*AKEYCODE_ENTER*/:      return 13;   // Key::Enter
        case 67 /*AKEYCODE_DEL*/:        return 8;    // Key::Backspace
        case 61 /*AKEYCODE_TAB*/:        return 9;    // Key::Tab
        case 111 /*AKEYCODE_ESCAPE*/:    return 27;   // Key::Escape
        case 21 /*AKEYCODE_DPAD_LEFT*/:  return 37;   // Key::Left
        case 22 /*AKEYCODE_DPAD_RIGHT*/: return 39;   // Key::Right
        case 19 /*AKEYCODE_DPAD_UP*/:    return 38;   // Key::Up
        case 20 /*AKEYCODE_DPAD_DOWN*/:  return 40;   // Key::Down
        case 122 /*AKEYCODE_HOME*/:      return 36;   // Key::Home
        // 注意：AKEYCODE_FORWARD_DEL(112) 不映射，因其会与 AKEYCODE_PERIOD(56) 冲突
        // （两者都对应 ASCII 46 '.'）；Key::Delete=46 本身也与 '.' 冲突，属枚举设计问题
        case 55 /*AKEYCODE_COMMA*/:      return 44;   // ','
        case 56 /*AKEYCODE_PERIOD*/:     return 46;   // '.'
        case 69 /*AKEYCODE_MINUS*/:      return 45;   // '-'
        case 70 /*AKEYCODE_EQUALS*/:     return 61;   // '='
        case 71 /*AKEYCODE_LEFT_BRACKET*/: return 91; // '['
        case 72 /*AKEYCODE_RIGHT_BRACKET*/: return 93;// ']'
        case 73 /*AKEYCODE_BACKSLASH*/:  return 92;   // '\'
        case 74 /*AKEYCODE_SEMICOLON*/:  return 59;   // ';'
        case 75 /*AKEYCODE_APOSTROPHE*/: return 39;   // '\''
        case 76 /*AKEYCODE_SLASH*/:      return 47;   // '/'
        case 68 /*AKEYCODE_GRAVE*/:      return 96;   // '`'
        default: return 0;  // 未映射
    }
}

static bool _ui_android_native_event_cb(const void* native_event) {
    const AInputEvent* e = (const AInputEvent*)native_event;
    if (AInputEvent_getType(e) != AINPUT_EVENT_TYPE_KEY /* =1 */) {
        return false;  // 非按键事件（如触摸），交给 sokol 处理
    }
    int32_t keycode = AKeyEvent_getKeyCode(e);
    // BACK 键不拦截，让 sokol 默认处理（触发 shutdown）
    if (keycode == 4 /*AKEYCODE_BACK*/) {
        return false;
    }
    int mapped = _ui_android_map_keycode(keycode);
    if (mapped == 0) {
        return false;  // 未映射的键，交给 sokol
    }
    int32_t action = AKeyEvent_getAction(e);
    int32_t mods = 0;
    int32_t metastate = AKeyEvent_getMetaState(e);
    if (metastate & (0x1 | 0x40 | 0x80)) mods |= 1;  // AMETA_SHIFT_ON|SHIFT_LEFT_ON|SHIFT_RIGHT_ON → SAPP_MODIFIER_SHIFT
    if (metastate & (0x1000 | 0x2000 | 0x4000)) mods |= 2;  // AMETA_CTRL_ON|CTRL_LEFT_ON|CTRL_RIGHT_ON → SAPP_MODIFIER_CTRL
    if (metastate & (0x2 | 0x10 | 0x20)) mods |= 4;  // AMETA_ALT_ON|ALT_LEFT_ON|ALT_RIGHT_ON → SAPP_MODIFIER_ALT

    sapp_event ev;
    memset(&ev, 0, sizeof(ev));
    ev.frame_count = sapp_frame_count();
    ev.modifiers = (uint32_t)mods;

    if (action == 0 /*AKEY_EVENT_ACTION_DOWN*/) {
        ev.type = SAPP_EVENTTYPE_KEY_DOWN;
        ev.key_code = (sapp_keycode)mapped;
        _ui_sokol_event_cb(&ev);
        // 对可打印字符（ASCII 32..126）额外生成 CHAR 事件，驱动 TextBox 文本输入
        if (mapped >= 32 && mapped <= 126) {
            ev.type = SAPP_EVENTTYPE_CHAR;
            ev.char_code = (uint32_t)mapped;
            _ui_sokol_event_cb(&ev);
        }
    } else if (action == 1 /*AKEY_EVENT_ACTION_UP*/) {
        ev.type = SAPP_EVENTTYPE_KEY_UP;
        ev.key_code = (sapp_keycode)mapped;
        _ui_sokol_event_cb(&ev);
    }
    // AKEY_EVENT_ACTION_MULTIPLE（值=2）忽略（重复事件，避免双重输入）

    return true;  // 已处理，阻止 sokol 默认处理
}
#endif

// 前向声明：_ui_softinput_cb 在后面定义，但 cleanup_cb 需要引用
static t_callback _ui_softinput_cb;

static void _ui_sokol_cleanup_cb(void) {
    sg_shutdown();
    _ui_state.initialized = false;
    // unpin PHP 回调 env，防止内存泄漏
    if (_ui_state.on_init_cb.env)  { tphp_fn_phpc_env_unpin(_ui_state.on_init_cb.env);  _ui_state.on_init_cb.env = NULL; }
    if (_ui_state.on_frame_cb.env) { tphp_fn_phpc_env_unpin(_ui_state.on_frame_cb.env); _ui_state.on_frame_cb.env = NULL; }
    if (_ui_state.on_event_cb.env) { tphp_fn_phpc_env_unpin(_ui_state.on_event_cb.env); _ui_state.on_event_cb.env = NULL; }
    if (_ui_softinput_cb.env)      { tphp_fn_phpc_env_unpin(_ui_softinput_cb.env);      _ui_softinput_cb.env = NULL; }
}

// ── 包含 CPU 软件渲染后端 ──
//   必须在此处包含：ui_cpu.h 依赖上文的 _ui_state、sapp_event、ui_draw_device_t、
//   TP_TRY/tp_throw 等定义；其定义的 _ui_cpu_device / _cpu_app_run 供下文 ui_app_run
//   的后端选择与窗口/绘图分派使用。
#include "ui_cpu.h"

// ── ui_* C 函数（通过 function C.xxx(...): C.ret; 声明 + C->xxx() 调用）──

// ── GPU 可用性预探测 ──
//   在调用 sapp_run 之前判断是否有硬件加速的 OpenGL 像素格式。sokol 的 Win32 初始化
//   先 CreateWindowEx + ShowWindow（窗口可见），再 _sapp_wgl_create_context（WGL 像素
//   格式选择）。若 WGL 失败 panic longjmp，跳过 destroy_window 清理，留下可见孤儿窗口。
//   因此尽量在 sapp_run 之前探测：无 GPU 时直接走 CPU 后端，sokol 完全不参与。
//
//   探测方式（与 sokol 用同一套 WGL_ARB_pixel_format 扩展）：
//   1. 创建临时隐藏窗口 + 临时 GL context（加载 WGL 扩展必须有 context）
//   2. 通过 wglGetProcAddress 获取 wglGetPixelFormatAttribivARB
//   3. 遍历所有像素格式，查找 WGL_ACCELERATION_ARB != WGL_NO_ACCELERATION_ARB 的格式
//   4. 有硬件加速格式 → GPU 可用；全部软件渲染 → 无 GPU
//   5. 销毁临时窗口/context（不留痕迹）
//
//   注意：预探测用临时窗口 DC，sokol 用主窗口 DC，某些环境（如 RDP）下两者查询结果
//   可能不同。因此预探测仅作为快速路径优化；若预探测误判（sokol 仍失败），由
//   ui_app_run 的 TP_CATCH_ANY 兜底，通过 _ui_destroy_sokol_orphans 清理孤儿窗口后
//   回退 CPU 后端。
static inline bool _ui_probe_gpu_available(void) {
#if defined(__ANDROID__)
    return true;  // GLES 总是可用
#elif defined(_WIN32) || defined(_WIN64)
    // WGL 扩展常量（避免依赖 wglext.h）
    #define _TPHP_WGL_NUMBER_PIXEL_FORMATS_ARB   0x2000
    #define _TPHP_WGL_DRAW_TO_WINDOW_ARB         0x2001
    #define _TPHP_WGL_ACCELERATION_ARB           0x2003
    #define _TPHP_WGL_SUPPORT_OPENGL_ARB         0x2010
    #define _TPHP_WGL_PIXEL_TYPE_ARB             0x2013
    #define _TPHP_WGL_TYPE_RGBA_ARB              0x202B
    #define _TPHP_WGL_NO_ACCELERATION_ARB        0x2025

    typedef BOOL (WINAPI *_tphp_fn_wglGetPixelFormatAttribivARB)(HDC, int, int, UINT, const int*, int*);
    typedef const char* (WINAPI *_tphp_fn_wglGetExtensionsStringARB)(HDC);

    HINSTANCE hinst = GetModuleHandleW(NULL);
    WNDCLASSW wc;
    memset(&wc, 0, sizeof(wc));
    wc.lpfnWndProc = DefWindowProcW;
    wc.hInstance = hinst;
    wc.lpszClassName = L"TinyPHP_UI_GPUProbe";
    if (!RegisterClassW(&wc)) return true;  // 注册失败保守视为可用

    // 创建隐藏窗口（无 WS_VISIBLE），不留痕迹
    HWND hwnd = CreateWindowExW(0, L"TinyPHP_UI_GPUProbe", L"", WS_POPUP,
                                0, 0, 1, 1, NULL, NULL, hinst, NULL);
    if (!hwnd) {
        UnregisterClassW(L"TinyPHP_UI_GPUProbe", hinst);
        return true;
    }
    HDC dc = GetDC(hwnd);

    // 用旧 API 设置一个基础像素格式（创建 context 必须先 SetPixelFormat）
    PIXELFORMATDESCRIPTOR pfd;
    memset(&pfd, 0, sizeof(pfd));
    pfd.nSize = sizeof(pfd);
    pfd.nVersion = 1;
    pfd.dwFlags = PFD_DRAW_TO_WINDOW | PFD_SUPPORT_OPENGL | PFD_DOUBLEBUFFER;
    pfd.iPixelType = PFD_TYPE_RGBA;
    pfd.cColorBits = 24;
    pfd.iLayerType = PFD_MAIN_PLANE;
    int pf = ChoosePixelFormat(dc, &pfd);
    bool gpu_ok = false;
    if (pf != 0 && SetPixelFormat(dc, pf, &pfd)) {
        HGLRC ctx = wglCreateContext(dc);
        if (ctx && wglMakeCurrent(dc, ctx)) {
            _tphp_fn_wglGetPixelFormatAttribivARB wglGetAttrib =
                (_tphp_fn_wglGetPixelFormatAttribivARB)wglGetProcAddress("wglGetPixelFormatAttribivARB");
            _tphp_fn_wglGetExtensionsStringARB wglGetExtStr =
                (_tphp_fn_wglGetExtensionsStringARB)wglGetProcAddress("wglGetExtensionsStringARB");

            if (wglGetExtStr) {
                const char* exts = wglGetExtStr(dc);
                bool has_arb_pf = exts && strstr(exts, "WGL_ARB_pixel_format");
                if (has_arb_pf && wglGetAttrib) {
                    int num_formats = 0;
                    int attr = _TPHP_WGL_NUMBER_PIXEL_FORMATS_ARB;
                    if (wglGetAttrib(dc, 0, 0, 1, &attr, &num_formats) && num_formats > 0) {
                        // 遍历查找硬件加速格式（与 sokol _sapp_wgl_find_pixel_format 同一标准）
                        int tags[4] = {
                            _TPHP_WGL_SUPPORT_OPENGL_ARB,
                            _TPHP_WGL_DRAW_TO_WINDOW_ARB,
                            _TPHP_WGL_PIXEL_TYPE_ARB,
                            _TPHP_WGL_ACCELERATION_ARB,
                        };
                        int vals[4] = {0};
                        for (int i = 1; i <= num_formats; i++) {
                            if (wglGetAttrib(dc, i, 0, 4, tags, vals)) {
                                if (vals[0] /* support_opengl */
                                    && vals[1] /* draw_to_window */
                                    && vals[2] == _TPHP_WGL_TYPE_RGBA_ARB
                                    && vals[3] != _TPHP_WGL_NO_ACCELERATION_ARB) {
                                    gpu_ok = true;
                                    break;
                                }
                            }
                        }
                    }
                }
            }
            wglMakeCurrent(NULL, NULL);
            wglDeleteContext(ctx);
        }
    }

    ReleaseDC(hwnd, dc);
    DestroyWindow(hwnd);
    UnregisterClassW(L"TinyPHP_UI_GPUProbe", hinst);
    return gpu_ok;
#else
    // macOS Metal 总是可用；Linux 桌面通常有 GPU 或 llvmpipe
    return true;
#endif
}

// ── 销毁 sokol 孤儿窗口 ──
//   sokol 的 Win32 初始化顺序：先 CreateWindowEx + ShowWindow（主窗口可见），再
//   _sapp_wgl_create_context（WGL 像素格式选择）。若 WGL 失败 _SAPP_PANIC，经
//   _ui_slog_func 转为 tp_throw（longjmp），跳过 sokol 的 _sapp_win32_destroy_window
//   清理，留下可见的孤儿窗口。
//
//   sokol 共创建两个窗口（类名均为 "SOKOLAPP"）：
//   - msg_hwnd：辅助窗口（WS_HIDE，用于加载 WGL 扩展）
//   - 主窗口：WS_SHOW（用户可见）
//   FindWindowW 只返回一个，故用 EnumWindows 枚举所有 "SOKOLAPP" 窗口并逐个销毁。
#if defined(_WIN32) || defined(_WIN64)
static BOOL CALLBACK _ui_destroy_sokol_enumproc(HWND hwnd, LPARAM lparam) {
    (void)lparam;
    wchar_t cls[32];
    if (GetClassNameW(hwnd, cls, 32) && wcscmp(cls, L"SOKOLAPP") == 0) {
        DestroyWindow(hwnd);
    }
    return TRUE;  // 继续枚举
}
#endif

static inline void _ui_destroy_sokol_orphans(void) {
#if defined(_WIN32) || defined(_WIN64)
    EnumWindows(_ui_destroy_sokol_enumproc, 0);
    UnregisterClassW(L"SOKOLAPP", GetModuleHandleW(NULL));
#elif defined(__ANDROID__)
    // Android: EGL 资源由 sokol 内部管理，无需手动清理
#endif
}

// App 类
//   后端自动选择（双层保险，确保任何环境下只有一个窗口）：
//   1. 预探测 GPU：无 GPU 时直接走 CPU 后端，sokol 完全不参与（无孤儿窗口）
//   2. 预探测误判兜底：sapp_run 的 panic 被 TP_CATCH_ANY 捕获后，先 _ui_destroy_sokol_orphans
//      销毁所有 sokol 窗口，再回退 CPU 后端。即使预探测不准确（如 RDP 下临时窗口 DC
//      与主窗口 DC 查询结果不同），也不会留下孤儿窗口。
//   整个过程对 PHP 侧透明，用户代码无需改动。
static inline t_int ui_app_run(t_int width, t_int height, t_string title) {
    if (width <= 0 || height <= 0) {
        tp_throw("app_run: invalid window dimensions (width and height must be > 0)");
        return -1;
    }
    // 第一层：预探测 GPU，无 GPU 直接走 CPU（sokol 不参与，无孤儿窗口）
    if (!_ui_probe_gpu_available()) {
        _ui_state.backend = UI_BACKEND_CPU;
        _ui_state.device = &_ui_cpu_device;
        fflush(stderr);
        fprintf(stderr, "[ui] GPU unavailable, using CPU software renderer\n");
        return _cpu_app_run(width, height, title);
    }
    // GPU 可用（或预探测不确定）：走 sokol 后端
    _ui_state.backend = UI_BACKEND_SOKOL;
    _ui_state.device = &_ui_sokol_device;
    sapp_desc desc = (sapp_desc){
        .init_cb = _ui_sokol_init_cb,
        .frame_cb = _ui_sokol_frame_cb,
        .event_cb = _ui_sokol_event_cb,
        .cleanup_cb = _ui_sokol_cleanup_cb,
        .width = (int)width,
        .height = (int)height,
        .window_title = STR_PTR(title),
        .high_dpi = true,
        .logger = { .func = _ui_slog_func },
        // GL 3.3 Core（比 sokol 默认 4.3 兼容性更好，覆盖绝大多数桌面 GPU）
        .gl.major_version = 3,
        .gl.minor_version = 3,
    };
    // 第二层：sokol panic 兜底。预探测用临时窗口 DC，sokol 用主窗口 DC，某些环境
    // （如 RDP）下两者结果不同，预探测可能误判。sapp_run 的 panic 被 TP_CATCH_ANY
    // 捕获后，先销毁所有 sokol 孤儿窗口（msg_hwnd + 主窗口），再回退 CPU 后端。
#if defined(__ANDROID__)
    // Android: 不调用 sapp_run，填充全局 desc 供 sokol_main() 返回
    // GLES3 后端：版本必须是 3.0（不能用桌面 GL 3.3，否则 eglCreateContext 失败秒退）
    desc.gl.major_version = 3;
    desc.gl.minor_version = 0;
    // 拦截 Android 原生按键事件，生成 KEY_DOWN/KEY_UP/CHAR 事件
    // （sokol 默认只处理 BACK 键，其他按键直接丢弃，导致 TextBox 无法输入）
    desc.android.native_event_cb = _ui_android_native_event_cb;
    _ui_android_desc = desc;
    // Android 上事件循环由 ANativeActivity 驱动
#else
    TP_TRY {
        sapp_run(&desc);
    } TP_CATCH_ANY(_tp_msg) {
        _ui_destroy_sokol_orphans();
        _ui_state.initialized = false;
        _ui_state.in_frame = false;
        _ui_state.pass_active = false;
        _ui_state.backend = UI_BACKEND_CPU;
        _ui_state.device = &_ui_cpu_device;
        fflush(stderr);
        fprintf(stderr, "[ui] GPU backend unavailable, falling back to CPU software renderer\n");
        return _cpu_app_run(width, height, title);
    } TP_END_TRY
#endif
    return 0;
}

#if defined(__ANDROID__)
// ── Android stdout/stderr → logcat 重定向 ──
//   Android NativeActivity 的 stdout/stderr 默认不输出到 logcat，
//   用 pipe + 后台线程将 stdout/stderr 转发到 __android_log_print，
//   使 PHP 的 echo/fprintf(stderr) 可通过 `adb logcat -s tphp` 查看。
#include <android/log.h>
#include <unistd.h>
#include <pthread.h>

static int _android_log_fd = -1;
static pthread_t _log_thread;

static void* _android_log_thread_fn(void* arg) {
    (void)arg;
    char buf[1024];
    ssize_t n;
    while ((n = read(_android_log_fd, buf, sizeof(buf) - 1)) > 0) {
        buf[n] = '\0';
        // 按行分割输出（logcat 每条独立）
        char* line = buf;
        char* next;
        while ((next = memchr(line, '\n', (size_t)(buf + n - line))) != NULL) {
            *next = '\0';
            if (*line) __android_log_print(ANDROID_LOG_INFO, "tphp", "%s", line);
            line = next + 1;
        }
        if (*line) __android_log_print(ANDROID_LOG_INFO, "tphp", "%s", line);
    }
    return NULL;
}

static void _android_redirect_stdout(void) {
    int pipefd[2];
    if (pipe(pipefd) < 0) return;
    _android_log_fd = pipefd[0];
    fflush(stdout);
    fflush(stderr);
    dup2(pipefd[1], STDOUT_FILENO);
    dup2(pipefd[1], STDERR_FILENO);
    close(pipefd[1]);
    setvbuf(stdout, NULL, _IONBF, 0);
    setvbuf(stderr, NULL, _IONBF, 0);
    pthread_create(&_log_thread, NULL, _android_log_thread_fn, NULL);
    pthread_detach(_log_thread);
}

// Android 入口：sokol 调用此函数获取配置，实际事件循环由 ANativeActivity 驱动
// 签名必须与 sokol_app.h 的 extern 声明一致：sokol_main(int argc, char* argv[])
// CodeGenerator 在 Android 模式下生成 tphp_android_main() 而非 main()，
// 此处首次调用时执行用户 main 来填充 _ui_android_desc（设置 callbacks 等）。
// NativeActivity 重建（如屏幕旋转）时 sokol 会再次调用 sokol_main，
// 但 _ui_android_initialized 静态变量保证用户 main 只执行一次；
// 回调通过 tphp_fn_phpc_env_pin pin 住，重建后仍有效。
SOKOL_API_IMPL sapp_desc sokol_main(int argc, char* argv[]) {
    static int _ui_android_initialized = 0;
    if (!_ui_android_initialized) {
        _ui_android_initialized = 1;
        // 在用户代码执行前重定向 stdout/stderr → logcat
        _android_redirect_stdout();
        // Android NativeActivity 模式下 argc/argv 可能为 0/NULL，确保安全
        if (argc < 0) argc = 0;
        if (argv == NULL && argc > 0) argc = 0;
        tphp_android_main(argc, argv);
    }
    return _ui_android_desc;
}
#endif

static inline void ui_app_on_init(t_callback cb) {
    _ui_state.on_init_cb = cb;
    tphp_fn_phpc_env_pin(cb);
}

static inline void ui_app_on_frame(t_callback cb) {
    _ui_state.on_frame_cb = cb;
    tphp_fn_phpc_env_pin(cb);
}

static inline void ui_app_on_event(t_callback cb) {
    _ui_state.on_event_cb = cb;
    tphp_fn_phpc_env_pin(cb);
}

// Window 类（按 backend 分派：sokol 用 sapp_*，CPU 用 _cpu_window_*）
static inline t_int ui_window_width(void) {
    if (_ui_state.backend == UI_BACKEND_CPU) return _cpu_window_width();
    return (t_int)sapp_width();
}
static inline t_int ui_window_height(void) {
    if (_ui_state.backend == UI_BACKEND_CPU) return _cpu_window_height();
    return (t_int)sapp_height();
}
static inline t_float ui_window_dpi_scale(void) {
    if (_ui_state.backend == UI_BACKEND_CPU) return _cpu_window_dpi_scale();
    return (t_float)sapp_dpi_scale();
}
static inline void ui_window_set_cursor(t_int cursor) {
    if (cursor < 0 || cursor >= (t_int)_SAPP_MOUSECURSOR_NUM) {
        tp_throw("set_cursor: invalid cursor value (must be 0..SAPP_MOUSECURSOR_NUM-1)");
        return;
    }
    if (_ui_state.backend == UI_BACKEND_CPU) { _cpu_window_set_cursor(cursor); return; }
    sapp_set_mouse_cursor((sapp_mouse_cursor)(int)cursor);
}

// Graphics 绘图 API（通过 _ui_state.device 分派到 sokol 或 CPU 后端）
static inline void ui_clear(t_int rgba) {
    if (!_ui_state.in_frame) {
        tp_throw("drawing outside frame callback");
        return;
    }
    if (!_ui_state.device) {
        tp_throw("no draw device initialized");
        return;
    }
    _ui_state.device->begin_pass(rgba);
    _ui_state.pass_active = true;
}

// ui_end_frame：手动结束当前 pass（可选，frame 回调会自动调用）
//   若在 frame 回调外调用或无 active pass，抛异常而非静默返回
static inline void ui_end_frame(void) {
    if (!_ui_state.in_frame) {
        tp_throw("end_frame called outside frame callback");
        return;
    }
    if (!_ui_state.pass_active) {
        tp_throw("end_frame called without active pass (missing clear?)");
        return;
    }
    if (_ui_state.device) _ui_state.device->end_pass();
    _ui_state.pass_active = false;
}

static inline void ui_fill_rect(t_int x, t_int y, t_int w, t_int h, t_int rgba) {
    if (!_ui_state.in_frame) {
        tp_throw("drawing outside frame callback");
        return;
    }
    if (!_ui_state.device) {
        tp_throw("no draw device initialized");
        return;
    }
    _ui_state.device->fill_rect(x, y, w, h, rgba);
}

static inline void ui_draw_text(t_int x, t_int y, t_string text, t_int rgba) {
    if (!_ui_state.in_frame) {
        tp_throw("drawing outside frame callback");
        return;
    }
    if (!_ui_state.device) {
        tp_throw("no draw device initialized");
        return;
    }
    _ui_state.device->draw_text(x, y, STR_PTR(text), text.length, rgba);
}

static inline void ui_draw_line(t_int x1, t_int y1, t_int x2, t_int y2, t_int rgba) {
    if (!_ui_state.in_frame) {
        tp_throw("drawing outside frame callback");
        return;
    }
    if (!_ui_state.device) {
        tp_throw("no draw device initialized");
        return;
    }
    _ui_state.device->draw_line(x1, y1, x2, y2, rgba);
}

static inline void ui_draw_rect(t_int x, t_int y, t_int w, t_int h, t_int rgba) {
    if (!_ui_state.in_frame) {
        tp_throw("drawing outside frame callback");
        return;
    }
    if (!_ui_state.device) {
        tp_throw("no draw device initialized");
        return;
    }
    _ui_state.device->draw_rect(x, y, w, h, rgba);
}

static inline void ui_draw_circle(t_int cx, t_int cy, t_int r, t_int rgba) {
    if (!_ui_state.in_frame) {
        tp_throw("drawing outside frame callback");
        return;
    }
    if (!_ui_state.device) {
        tp_throw("no draw device initialized");
        return;
    }
    _ui_state.device->draw_circle(cx, cy, r, rgba);
}

// 事件查询（从 sapp_event 指针提取字段）
//   NULL 指针抛异常而非静默返回 0，避免调用方误用无效事件
static inline t_int ui_event_type(t_int ev_ptr) {
    const sapp_event* ev = (const sapp_event*)(intptr_t)ev_ptr;
    if (!ev) { tp_throw("event_type: NULL event pointer"); return 0; }
    return (t_int)ev->type;
}
static inline t_int ui_event_x(t_int ev_ptr) {
    const sapp_event* ev = (const sapp_event*)(intptr_t)ev_ptr;
    if (!ev) { tp_throw("event_x: NULL event pointer"); return 0; }
    return (t_int)ev->mouse_x;
}
static inline t_int ui_event_y(t_int ev_ptr) {
    const sapp_event* ev = (const sapp_event*)(intptr_t)ev_ptr;
    if (!ev) { tp_throw("event_y: NULL event pointer"); return 0; }
    return (t_int)ev->mouse_y;
}
static inline t_int ui_event_button(t_int ev_ptr) {
    const sapp_event* ev = (const sapp_event*)(intptr_t)ev_ptr;
    if (!ev) { tp_throw("event_button: NULL event pointer"); return 0; }
    return (t_int)ev->mouse_button;
}
static inline t_int ui_event_key(t_int ev_ptr) {
    const sapp_event* ev = (const sapp_event*)(intptr_t)ev_ptr;
    if (!ev) { tp_throw("event_key: NULL event pointer"); return 0; }
    return (t_int)ev->key_code;
}
static inline t_int ui_event_modifiers(t_int ev_ptr) {
    const sapp_event* ev = (const sapp_event*)(intptr_t)ev_ptr;
    if (!ev) { tp_throw("event_modifiers: NULL event pointer"); return 0; }
    return (t_int)ev->modifiers;
}
static inline t_int ui_event_codepoint(t_int ev_ptr) {
    const sapp_event* ev = (const sapp_event*)(intptr_t)ev_ptr;
    if (!ev) { tp_throw("event_codepoint: NULL event pointer"); return 0; }
    return (t_int)ev->char_code;
}
static inline t_int ui_event_touch_count(t_int ev_ptr) {
    const sapp_event* ev = (const sapp_event*)(intptr_t)ev_ptr;
    if (!ev) { tp_throw("event_touch_count: NULL event pointer"); return 0; }
    return (t_int)ev->num_touches;
}

// ── SoftInput 回调存储（C 层管理，避免 PHP mixed 属性的 null 赋值问题）──
// _ui_softinput_cb 已在前方声明（cleanup_cb 需要），此处不再重复定义

// show/hide：桌面端 no-op，Android 端通过 JNI 调用 InputMethodManager
#if defined(__ANDROID__)
// JNI 线程 attach 辅助：仅在当前线程未 attach 时才 attach，对应时才 detach
//   直接 AttachCurrentThread 在已 attached 线程上会增加引用计数，
//   后续 DetachCurrentThread 会导致 sokol 主线程 env 失效。
typedef struct { JNIEnv* env; int need_detach; } _ui_jni_env_t;
static inline _ui_jni_env_t _ui_jni_attach(JavaVM* vm) {
    _ui_jni_env_t r = { NULL, 0 };
    if ((*vm)->GetEnv(vm, (void**)&r.env, JNI_VERSION_1_6) == JNI_OK)
        return r;  // 已 attached
    if ((*vm)->AttachCurrentThread(vm, &r.env, NULL) == JNI_OK)
        r.need_detach = 1;
    return r;
}
static inline void _ui_jni_detach(JavaVM* vm, _ui_jni_env_t* r) {
    if (r->need_detach) (*vm)->DetachCurrentThread(vm);
}
// JNI 异常检查：清除异常并输出警告
static inline int _ui_jni_check(JNIEnv* env, const char* tag) {
    if ((*env)->ExceptionCheck(env)) {
        (*env)->ExceptionDescribe(env);
        (*env)->ExceptionClear(env);
        fprintf(stderr, "[ui] JNI exception in %s\n", tag);
        return 1;
    }
    return 0;
}
static inline void _ui_android_show_softinput(void) {
    ANativeActivity* activity = (ANativeActivity*)sapp_android_get_native_activity();
    if (!activity) { tp_throw("ui_softinput_show: NativeActivity not available"); return; }
    _ui_jni_env_t je = _ui_jni_attach(activity->vm);
    if (!je.env) { tp_throw("ui_softinput_show: AttachCurrentThread failed"); return; }
    JNIEnv* env = je.env;
    // activity.getWindow().getDecorView()
    jclass cls_NativeActivity = (*env)->FindClass(env, "android/app/NativeActivity");
    if (!cls_NativeActivity || _ui_jni_check(env, "show:FindClass NativeActivity")) goto show_done;
    jmethodID mid_getWindow = (*env)->GetMethodID(env, cls_NativeActivity, "getWindow", "()Landroid/view/Window;");
    if (!mid_getWindow || _ui_jni_check(env, "show:GetMethodID getWindow")) goto show_cleanup_na;
    jobject window = (*env)->CallObjectMethod(env, activity->clazz, mid_getWindow);
    if (_ui_jni_check(env, "show:getWindow") || !window) goto show_cleanup_na;
    jclass cls_Window = (*env)->FindClass(env, "android/view/Window");
    if (!cls_Window || _ui_jni_check(env, "show:FindClass Window")) { (*env)->DeleteLocalRef(env, window); goto show_cleanup_na; }
    jmethodID mid_getDecorView = (*env)->GetMethodID(env, cls_Window, "getDecorView", "()Landroid/view/View;");
    if (!mid_getDecorView || _ui_jni_check(env, "show:GetMethodID getDecorView")) { (*env)->DeleteLocalRef(env, window); (*env)->DeleteLocalRef(env, cls_Window); goto show_cleanup_na; }
    jobject decorView = (*env)->CallObjectMethod(env, window, mid_getDecorView);
    if (_ui_jni_check(env, "show:getDecorView") || !decorView) { (*env)->DeleteLocalRef(env, window); (*env)->DeleteLocalRef(env, cls_Window); goto show_cleanup_na; }
    // getSystemService(Context.INPUT_METHOD_SERVICE)
    jclass cls_Context = (*env)->FindClass(env, "android/content/Context");
    if (!cls_Context || _ui_jni_check(env, "show:FindClass Context")) { (*env)->DeleteLocalRef(env, window); (*env)->DeleteLocalRef(env, cls_Window); (*env)->DeleteLocalRef(env, decorView); goto show_cleanup_na; }
    jfieldID fid_IMM = (*env)->GetStaticFieldID(env, cls_Context, "INPUT_METHOD_SERVICE", "Ljava/lang/String;");
    if (!fid_IMM || _ui_jni_check(env, "show:GetStaticFieldID IMM")) { (*env)->DeleteLocalRef(env, window); (*env)->DeleteLocalRef(env, cls_Window); (*env)->DeleteLocalRef(env, decorView); (*env)->DeleteLocalRef(env, cls_Context); goto show_cleanup_na; }
    jstring str_imm = (jstring)(*env)->GetStaticObjectField(env, cls_Context, fid_IMM);
    jmethodID mid_getSystemService = (*env)->GetMethodID(env, cls_Context, "getSystemService", "(Ljava/lang/String;)Ljava/lang/Object;");
    if (!mid_getSystemService || _ui_jni_check(env, "show:GetMethodID getSystemService")) { (*env)->DeleteLocalRef(env, window); (*env)->DeleteLocalRef(env, cls_Window); (*env)->DeleteLocalRef(env, decorView); (*env)->DeleteLocalRef(env, cls_Context); (*env)->DeleteLocalRef(env, str_imm); goto show_cleanup_na; }
    jobject imm = (*env)->CallObjectMethod(env, activity->clazz, mid_getSystemService, str_imm);
    if (_ui_jni_check(env, "show:getSystemService") || !imm) { (*env)->DeleteLocalRef(env, window); (*env)->DeleteLocalRef(env, cls_Window); (*env)->DeleteLocalRef(env, decorView); (*env)->DeleteLocalRef(env, cls_Context); (*env)->DeleteLocalRef(env, str_imm); goto show_cleanup_na; }
    // imm.showSoftInput(decorView, 0)
    jclass cls_IMM = (*env)->GetObjectClass(env, imm);
    jmethodID mid_show = (*env)->GetMethodID(env, cls_IMM, "showSoftInput", "(Landroid/view/View;I)Z");
    if (mid_show) {
        (*env)->CallBooleanMethod(env, imm, mid_show, decorView, (jint)0);
        _ui_jni_check(env, "show:showSoftInput");
    }
    (*env)->DeleteLocalRef(env, imm);
    (*env)->DeleteLocalRef(env, cls_IMM);
    (*env)->DeleteLocalRef(env, str_imm);
    (*env)->DeleteLocalRef(env, cls_Context);
    (*env)->DeleteLocalRef(env, decorView);
    (*env)->DeleteLocalRef(env, cls_Window);
    (*env)->DeleteLocalRef(env, window);
show_cleanup_na:
    (*env)->DeleteLocalRef(env, cls_NativeActivity);
show_done:
    _ui_jni_detach(activity->vm, &je);
}
static inline void _ui_android_hide_softinput(void) {
    ANativeActivity* activity = (ANativeActivity*)sapp_android_get_native_activity();
    if (!activity) { tp_throw("ui_softinput_hide: NativeActivity not available"); return; }
    _ui_jni_env_t je = _ui_jni_attach(activity->vm);
    if (!je.env) { tp_throw("ui_softinput_hide: AttachCurrentThread failed"); return; }
    JNIEnv* env = je.env;
    // getSystemService(Context.INPUT_METHOD_SERVICE)
    jclass cls_Context = (*env)->FindClass(env, "android/content/Context");
    if (!cls_Context || _ui_jni_check(env, "hide:FindClass Context")) goto hide_done;
    jfieldID fid_IMM = (*env)->GetStaticFieldID(env, cls_Context, "INPUT_METHOD_SERVICE", "Ljava/lang/String;");
    if (!fid_IMM || _ui_jni_check(env, "hide:GetStaticFieldID IMM")) { (*env)->DeleteLocalRef(env, cls_Context); goto hide_done; }
    jstring str_imm = (jstring)(*env)->GetStaticObjectField(env, cls_Context, fid_IMM);
    jmethodID mid_getSystemService = (*env)->GetMethodID(env, cls_Context, "getSystemService", "(Ljava/lang/String;)Ljava/lang/Object;");
    if (!mid_getSystemService || _ui_jni_check(env, "hide:GetMethodID getSystemService")) { (*env)->DeleteLocalRef(env, cls_Context); (*env)->DeleteLocalRef(env, str_imm); goto hide_done; }
    jobject imm = (*env)->CallObjectMethod(env, activity->clazz, mid_getSystemService, str_imm);
    if (_ui_jni_check(env, "hide:getSystemService") || !imm) { (*env)->DeleteLocalRef(env, cls_Context); (*env)->DeleteLocalRef(env, str_imm); goto hide_done; }
    // activity.getWindow().getDecorView().getWindowToken()
    jclass cls_NativeActivity = (*env)->FindClass(env, "android/app/NativeActivity");
    if (!cls_NativeActivity || _ui_jni_check(env, "hide:FindClass NativeActivity")) { (*env)->DeleteLocalRef(env, imm); (*env)->DeleteLocalRef(env, cls_Context); (*env)->DeleteLocalRef(env, str_imm); goto hide_done; }
    jmethodID mid_getWindow = (*env)->GetMethodID(env, cls_NativeActivity, "getWindow", "()Landroid/view/Window;");
    if (!mid_getWindow || _ui_jni_check(env, "hide:GetMethodID getWindow")) { (*env)->DeleteLocalRef(env, imm); (*env)->DeleteLocalRef(env, cls_Context); (*env)->DeleteLocalRef(env, str_imm); (*env)->DeleteLocalRef(env, cls_NativeActivity); goto hide_done; }
    jobject window = (*env)->CallObjectMethod(env, activity->clazz, mid_getWindow);
    if (_ui_jni_check(env, "hide:getWindow") || !window) { (*env)->DeleteLocalRef(env, imm); (*env)->DeleteLocalRef(env, cls_Context); (*env)->DeleteLocalRef(env, str_imm); (*env)->DeleteLocalRef(env, cls_NativeActivity); goto hide_done; }
    jclass cls_Window = (*env)->FindClass(env, "android/view/Window");
    if (!cls_Window || _ui_jni_check(env, "hide:FindClass Window")) { (*env)->DeleteLocalRef(env, imm); (*env)->DeleteLocalRef(env, cls_Context); (*env)->DeleteLocalRef(env, str_imm); (*env)->DeleteLocalRef(env, cls_NativeActivity); (*env)->DeleteLocalRef(env, window); goto hide_done; }
    jmethodID mid_getDecorView = (*env)->GetMethodID(env, cls_Window, "getDecorView", "()Landroid/view/View;");
    if (!mid_getDecorView || _ui_jni_check(env, "hide:GetMethodID getDecorView")) { (*env)->DeleteLocalRef(env, imm); (*env)->DeleteLocalRef(env, cls_Context); (*env)->DeleteLocalRef(env, str_imm); (*env)->DeleteLocalRef(env, cls_NativeActivity); (*env)->DeleteLocalRef(env, window); (*env)->DeleteLocalRef(env, cls_Window); goto hide_done; }
    jobject decorView = (*env)->CallObjectMethod(env, window, mid_getDecorView);
    if (_ui_jni_check(env, "hide:getDecorView") || !decorView) { (*env)->DeleteLocalRef(env, imm); (*env)->DeleteLocalRef(env, cls_Context); (*env)->DeleteLocalRef(env, str_imm); (*env)->DeleteLocalRef(env, cls_NativeActivity); (*env)->DeleteLocalRef(env, window); (*env)->DeleteLocalRef(env, cls_Window); goto hide_done; }
    jclass cls_View = (*env)->FindClass(env, "android/view/View");
    if (!cls_View || _ui_jni_check(env, "hide:FindClass View")) { (*env)->DeleteLocalRef(env, imm); (*env)->DeleteLocalRef(env, cls_Context); (*env)->DeleteLocalRef(env, str_imm); (*env)->DeleteLocalRef(env, cls_NativeActivity); (*env)->DeleteLocalRef(env, window); (*env)->DeleteLocalRef(env, cls_Window); (*env)->DeleteLocalRef(env, decorView); goto hide_done; }
    jmethodID mid_getWindowToken = (*env)->GetMethodID(env, cls_View, "getWindowToken", "()Landroid/os/IBinder;");
    if (!mid_getWindowToken || _ui_jni_check(env, "hide:GetMethodID getWindowToken")) { (*env)->DeleteLocalRef(env, imm); (*env)->DeleteLocalRef(env, cls_Context); (*env)->DeleteLocalRef(env, str_imm); (*env)->DeleteLocalRef(env, cls_NativeActivity); (*env)->DeleteLocalRef(env, window); (*env)->DeleteLocalRef(env, cls_Window); (*env)->DeleteLocalRef(env, decorView); (*env)->DeleteLocalRef(env, cls_View); goto hide_done; }
    jobject windowToken = (*env)->CallObjectMethod(env, decorView, mid_getWindowToken);
    if (_ui_jni_check(env, "hide:getWindowToken") || !windowToken) { (*env)->DeleteLocalRef(env, imm); (*env)->DeleteLocalRef(env, cls_Context); (*env)->DeleteLocalRef(env, str_imm); (*env)->DeleteLocalRef(env, cls_NativeActivity); (*env)->DeleteLocalRef(env, window); (*env)->DeleteLocalRef(env, cls_Window); (*env)->DeleteLocalRef(env, decorView); (*env)->DeleteLocalRef(env, cls_View); goto hide_done; }
    // imm.hideSoftInputFromWindow(windowToken, 0)
    jclass cls_IMM = (*env)->GetObjectClass(env, imm);
    jmethodID mid_hide = (*env)->GetMethodID(env, cls_IMM, "hideSoftInputFromWindow", "(Landroid/os/IBinder;I)Z");
    if (mid_hide) {
        (*env)->CallBooleanMethod(env, imm, mid_hide, windowToken, (jint)0);
        _ui_jni_check(env, "hide:hideSoftInputFromWindow");
    }
    (*env)->DeleteLocalRef(env, windowToken);
    (*env)->DeleteLocalRef(env, cls_IMM);
    (*env)->DeleteLocalRef(env, cls_View);
    (*env)->DeleteLocalRef(env, decorView);
    (*env)->DeleteLocalRef(env, cls_Window);
    (*env)->DeleteLocalRef(env, window);
    (*env)->DeleteLocalRef(env, cls_NativeActivity);
    (*env)->DeleteLocalRef(env, imm);
    (*env)->DeleteLocalRef(env, str_imm);
    (*env)->DeleteLocalRef(env, cls_Context);
hide_done:
    _ui_jni_detach(activity->vm, &je);
}
#endif

static inline void ui_softinput_show(void) {
#if defined(__ANDROID__)
    _ui_android_show_softinput();
#else
    // 桌面端：no-op
#endif
}

static inline void ui_softinput_hide(void) {
#if defined(__ANDROID__)
    _ui_android_hide_softinput();
#else
    // 桌面端：no-op
#endif
}

static inline void ui_softinput_on_input(t_callback cb) {
    _ui_softinput_cb = cb;
    tphp_fn_phpc_env_pin(cb);
}

static inline void ui_softinput_dispatch(t_int codepoint) {
    if (_ui_softinput_cb.func) {
        ((void(*)(t_int, void*))_ui_softinput_cb.func)(codepoint, _ui_softinput_cb.env);
    }
}

static inline void ui_softinput_clear_cb(void) {
    _ui_softinput_cb = (t_callback){NULL, NULL};
}
