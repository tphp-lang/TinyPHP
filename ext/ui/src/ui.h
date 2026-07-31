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

// ── SAL 标注兼容（TCC 的 windows.h 不含 sal.h，sokol WinMain 需要）──
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
#elif defined(__linux__)
    #define SOKOL_GLCORE
#endif
#define SOKOL_IMPL
#define SOKOL_NO_ENTRY
#include "sokol_app.h"
#include "sokol_gfx.h"
#include "sokol_glue.h"
#include "sokol_log.h"
#include "sokol_time.h"

// ── UI 全局状态结构 ──
typedef struct {
    bool initialized;       // sg_setup 是否已调用
    bool in_frame;          // 是否在 frame 回调内（绘图 API 前置条件）
    bool pass_active;       // sg_begin_pass 已调用，需匹配 sg_end_pass+sg_commit
    t_callback on_init_cb;  // PHP 侧 init 回调
    t_callback on_frame_cb; // PHP 侧 frame 回调
    t_callback on_event_cb; // PHP 侧 event 回调
    // 绘图管线状态
    sg_pipeline quad_pip;   // 矩形绘制管线
    sg_buffer quad_buf;     // 顶点缓冲区
} ui_state_t;

static ui_state_t _ui_state;

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

// ── sokol 回调桥（C → PHP 回调）──
//   所有回调包裹 TP_TRY/TP_CATCH_ANY：
//   PHP 回调内 tp_throw 触发 longjmp，若不在此捕获会跳过 sokol 事件循环，
//   导致窗口静默崩溃。捕获后输出到 stderr 并 sapp_request_quit() 干净退出。
static void _ui_sokol_init_cb(void) {
    sg_setup(&(sg_desc){
        .environment = sglue_environment(),
        .logger = { .func = _ui_slog_func },
    });
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
        sg_end_pass();
        sg_commit();
        _ui_state.pass_active = false;
    }
    _ui_state.in_frame = false;
}

static void _ui_sokol_event_cb(const sapp_event* ev) {
    if (_ui_state.on_event_cb.func) {
        TP_TRY {
            // 传递 sapp_event 指针给 PHP 侧（以 t_int 形式，PHP 侧用 Event::fromPtr 解析）
            ((void(*)(t_int, void*))_ui_state.on_event_cb.func)((t_int)(intptr_t)ev, _ui_state.on_event_cb.env);
        } TP_CATCH_ANY(_tp_msg) {
            fflush(stdout);
            fprintf(stderr, "\nFatal error: Uncaught exception in event callback: %s\n\n",
                    STR_PTR(_tp_msg) ? STR_PTR(_tp_msg) : "(null)");
            fflush(stderr);
            sapp_request_quit();
        } TP_END_TRY
    }
}

static void _ui_sokol_cleanup_cb(void) {
    sg_shutdown();
    _ui_state.initialized = false;
}

// ── ui_* C 函数（通过 function C.xxx(...): C.ret; 声明 + C->xxx() 调用）──

// App 类
static inline t_int ui_app_run(t_int width, t_int height, t_string title) {
    if (width <= 0 || height <= 0) {
        tp_throw("app_run: invalid window dimensions (width and height must be > 0)");
        return -1;
    }
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
    // 包裹 sapp_run：sokol panic（如 WGL 像素格式失败）会通过 _ui_slog_func 抛异常，
    // 在此捕获后返回 -1，而非让 tp_throw 走到无帧分支 exit(1)
    TP_TRY {
        sapp_run(&desc);
    } TP_CATCH_ANY(_tp_msg) {
        return -1;
    } TP_END_TRY
    return 0;
}

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

// Window 类
static inline t_int ui_window_width(void) { return (t_int)sapp_width(); }
static inline t_int ui_window_height(void) { return (t_int)sapp_height(); }
static inline t_float ui_window_dpi_scale(void) { return (t_float)sapp_dpi_scale(); }
static inline void ui_window_set_cursor(t_int cursor) {
    if (cursor < 0 || cursor >= (t_int)_SAPP_MOUSECURSOR_NUM) {
        tp_throw("set_cursor: invalid cursor value (must be 0..SAPP_MOUSECURSOR_NUM-1)");
        return;
    }
    sapp_set_mouse_cursor((sapp_mouse_cursor)(int)cursor);
}

// Graphics 绘图 API
static inline void ui_clear(t_int rgba) {
    if (!_ui_state.in_frame) {
        tp_throw("drawing outside frame callback");
        return;
    }
    // 拆分 0xAABBGGRR → float RGBA
    float a = (float)((rgba >> 24) & 0xFF) / 255.0f;
    float r = (float)((rgba >> 16) & 0xFF) / 255.0f;
    float g = (float)((rgba >> 8) & 0xFF) / 255.0f;
    float b = (float)(rgba & 0xFF) / 255.0f;
    sg_pass_action pa = (sg_pass_action){
        .colors[0] = { .load_action = SG_LOADACTION_CLEAR, .clear_value = { r, g, b, a } }
    };
    sg_begin_pass(&(sg_pass){ .action = pa, .swapchain = sglue_swapchain() });
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
    sg_end_pass();
    sg_commit();
    _ui_state.pass_active = false;
}

static inline void ui_fill_rect(t_int x, t_int y, t_int w, t_int h, t_int rgba) {
    if (!_ui_state.in_frame) {
        tp_throw("drawing outside frame callback");
        return;
    }
    tp_throw("fill_rect not yet implemented");
}

static inline void ui_draw_text(t_int x, t_int y, t_string text, t_int rgba) {
    if (!_ui_state.in_frame) {
        tp_throw("drawing outside frame callback");
        return;
    }
    tp_throw("text rendering not yet implemented");
}

static inline void ui_draw_line(t_int x1, t_int y1, t_int x2, t_int y2, t_int rgba) {
    if (!_ui_state.in_frame) {
        tp_throw("drawing outside frame callback");
        return;
    }
    tp_throw("draw_line not yet implemented");
}

static inline void ui_draw_rect(t_int x, t_int y, t_int w, t_int h, t_int rgba) {
    if (!_ui_state.in_frame) {
        tp_throw("drawing outside frame callback");
        return;
    }
    tp_throw("draw_rect not yet implemented");
}

static inline void ui_draw_circle(t_int cx, t_int cy, t_int r, t_int rgba) {
    if (!_ui_state.in_frame) {
        tp_throw("drawing outside frame callback");
        return;
    }
    tp_throw("draw_circle not yet implemented");
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
static t_callback _ui_softinput_cb = {NULL, NULL};

// show/hide：桌面端 no-op，Android 端 stub 抛异常（需 ext/jni 实现完整 JNI 桥接）
static inline void ui_softinput_show(void) {
#if defined(__ANDROID__)
    tp_throw("Android soft input not yet implemented");
#else
    // 桌面端：no-op（有物理键盘，无需软键盘）
#endif
}

static inline void ui_softinput_hide(void) {
#if defined(__ANDROID__)
    tp_throw("Android soft input not yet implemented");
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
