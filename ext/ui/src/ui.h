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
#elif defined(__linux__)
    #define SOKOL_GLCORE
#endif
#define SOKOL_IMPL
#define SOKOL_NO_ENTRY
// macOS: sokol_app.h #import <AppKit/AppKit.h>, ObjC 头中 +null 方法声明
//   会被 types.h 的 #define null ((void *)0) 破坏（展开为 +((void*)0) 语法错误）。
//   sokol 头中 null 仅出现在注释,无 C 标识符使用,undef 安全。
#if defined(__APPLE__)
#undef null
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

// ── 包含 CPU 软件渲染后端 ──
//   必须在此处包含：ui_cpu.h 依赖上文的 _ui_state、sapp_event、ui_draw_device_t、
//   TP_TRY/tp_throw 等定义；其定义的 _ui_cpu_device / _cpu_app_run 供下文 ui_app_run
//   的后端选择与窗口/绘图分派使用。
#include "ui_cpu.h"

// ── sokol 后端 DrawDevice 实现 ──
//   begin_pass/end_pass 复用 sokol pass 管理（sg_begin_pass/sg_end_pass/sg_commit）；
//   形状绘制（fill_rect 等）在 GPU 后端尚未实现，抛异常提示。
//   无 GPU 环境下 ui_app_run 会自动回退到 CPU 后端，不会走到这些函数。
static void _sokol_begin_pass(t_int rgba) {
    float a = (float)((rgba >> 24) & 0xFF) / 255.0f;
    float r = (float)((rgba >> 16) & 0xFF) / 255.0f;
    float g = (float)((rgba >> 8) & 0xFF) / 255.0f;
    float b = (float)(rgba & 0xFF) / 255.0f;
    sg_pass_action pa = (sg_pass_action){
        .colors[0] = { .load_action = SG_LOADACTION_CLEAR, .clear_value = { r, g, b, a } }
    };
    sg_begin_pass(&(sg_pass){ .action = pa, .swapchain = sglue_swapchain() });
}

static void _sokol_end_pass(void) {
    sg_end_pass();
    sg_commit();
}

static void _sokol_fill_rect(t_int x, t_int y, t_int w, t_int h, t_int rgba) {
    (void)x; (void)y; (void)w; (void)h; (void)rgba;
    tp_throw("fill_rect not yet implemented on GPU backend (auto-fallback to CPU in non-GPU environments)");
}

static void _sokol_draw_text(t_int x, t_int y, const char* text, int len, t_int rgba) {
    (void)x; (void)y; (void)text; (void)len; (void)rgba;
    tp_throw("draw_text not yet implemented on GPU backend (auto-fallback to CPU in non-GPU environments)");
}

static void _sokol_draw_line(t_int x1, t_int y1, t_int x2, t_int y2, t_int rgba) {
    (void)x1; (void)y1; (void)x2; (void)y2; (void)rgba;
    tp_throw("draw_line not yet implemented on GPU backend (auto-fallback to CPU in non-GPU environments)");
}

static void _sokol_draw_rect(t_int x, t_int y, t_int w, t_int h, t_int rgba) {
    (void)x; (void)y; (void)w; (void)h; (void)rgba;
    tp_throw("draw_rect not yet implemented on GPU backend (auto-fallback to CPU in non-GPU environments)");
}

static void _sokol_draw_circle(t_int cx, t_int cy, t_int r, t_int rgba) {
    (void)cx; (void)cy; (void)r; (void)rgba;
    tp_throw("draw_circle not yet implemented on GPU backend (auto-fallback to CPU in non-GPU environments)");
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
#if defined(_WIN32) || defined(_WIN64)
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
