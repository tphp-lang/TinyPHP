#pragma once
// ============================================================
// ui_cpu.h — CPU 软件渲染后端（无 GPU 回退方案）
//
// 当 sokol/GPU 不可用时（如 RDP/无 GPU/软件渲染环境），
// 自动回退到此后端。使用 Win32 窗口 + DIB 帧缓冲 + GDI 显示。
//
// 设计要点：
//   - CreateDIBSection 创建帧缓冲：像素数据可通过指针直接操作
//     （fill_rect/draw_line 等），也可用 GDI 函数绘制（draw_text）
//   - 事件构造 sapp_event 结构，保持与 sokol 后端兼容
//     （PHP 侧 Event::fromPtr 无需改动）
//   - 事件循环：PeekMessage + frame 回调 + BitBlt 显示
// ============================================================

// ── CPU 后端状态 ──
typedef struct {
    bool quit;              // 退出标志
    int width;              // 窗口客户区宽度
    int height;             // 窗口客户区高度
} _cpu_state_t;

static _cpu_state_t _cpu_state;

#if defined(_WIN32) || defined(_WIN64)

// ── Win32 资源 ──
static HWND _cpu_hwnd = NULL;
static HDC _cpu_memdc = NULL;       // 兼容 DC，关联 DIB section
static HBITMAP _cpu_dib = NULL;     // DIB section（像素数据 = _cpu_fb）
static uint32_t* _cpu_fb = NULL;    // 帧缓冲指针（指向 DIB section 像素数据）
static int _cpu_fb_w = 0;
static int _cpu_fb_h = 0;
static BITMAPINFO _cpu_bmi;
static HFONT _cpu_font = NULL;      // 默认字体

// ── 绘图原语 ──

// 0xAABBGGRR → 0x00BBGGRR（Win32 DIB 兼容，去 alpha 保留 BGR）
static inline uint32_t _cpu_rgba_to_pixel(t_int rgba) {
    return (uint32_t)(rgba & 0x00FFFFFF);
}

static inline void _cpu_set_pixel(int x, int y, uint32_t color) {
    if (x >= 0 && x < _cpu_fb_w && y >= 0 && y < _cpu_fb_h) {
        _cpu_fb[y * _cpu_fb_w + x] = color;
    }
}

// fill_rect：填充矩形（直接操作帧缓冲，零 GDI 开销）
static void _cpu_fill_rect(t_int x, t_int y, t_int w, t_int h, t_int rgba) {
    uint32_t color = _cpu_rgba_to_pixel(rgba);
    int x0 = (int)x, y0 = (int)y;
    int x1 = x0 + (int)w, y1 = y0 + (int)h;
    if (x0 < 0) x0 = 0;
    if (y0 < 0) y0 = 0;
    if (x1 > _cpu_fb_w) x1 = _cpu_fb_w;
    if (y1 > _cpu_fb_h) y1 = _cpu_fb_h;
    for (int py = y0; py < y1; py++) {
        uint32_t* row = _cpu_fb + (size_t)py * _cpu_fb_w;
        for (int px = x0; px < x1; px++) {
            row[px] = color;
        }
    }
}

// draw_rect：矩形边框
static void _cpu_draw_rect(t_int x, t_int y, t_int w, t_int h, t_int rgba) {
    uint32_t color = _cpu_rgba_to_pixel(rgba);
    int x0 = (int)x, y0 = (int)y;
    int x1 = x0 + (int)w - 1, y1 = y0 + (int)h - 1;
    for (int px = x0; px <= x1; px++) {
        _cpu_set_pixel(px, y0, color);
        _cpu_set_pixel(px, y1, color);
    }
    for (int py = y0; py <= y1; py++) {
        _cpu_set_pixel(x0, py, color);
        _cpu_set_pixel(x1, py, color);
    }
}

// draw_line：Bresenham 直线算法
static void _cpu_draw_line(t_int x1, t_int y1, t_int x2, t_int y2, t_int rgba) {
    uint32_t color = _cpu_rgba_to_pixel(rgba);
    int x = (int)x1, y = (int)y1;
    int dx = abs((int)x2 - x), sx = x < (int)x2 ? 1 : -1;
    int dy = -abs((int)y2 - y), sy = y < (int)y2 ? 1 : -1;
    int err = dx + dy;
    for (;;) {
        _cpu_set_pixel(x, y, color);
        if (x == (int)x2 && y == (int)y2) break;
        int e2 = 2 * err;
        if (e2 >= dy) { err += dy; x += sx; }
        if (e2 <= dx) { err += dx; y += sy; }
    }
}

// draw_circle：中点圆算法
static void _cpu_draw_circle(t_int cx, t_int cy, t_int r, t_int rgba) {
    uint32_t color = _cpu_rgba_to_pixel(rgba);
    int x = (int)r, y = 0, err = 0;
    int cx0 = (int)cx, cy0 = (int)cy;
    while (x >= y) {
        _cpu_set_pixel(cx0 + x, cy0 + y, color);
        _cpu_set_pixel(cx0 + y, cy0 + x, color);
        _cpu_set_pixel(cx0 - y, cy0 + x, color);
        _cpu_set_pixel(cx0 - x, cy0 + y, color);
        _cpu_set_pixel(cx0 - x, cy0 - y, color);
        _cpu_set_pixel(cx0 - y, cy0 - x, color);
        _cpu_set_pixel(cx0 + y, cy0 - x, color);
        _cpu_set_pixel(cx0 + x, cy0 - y, color);
        if (err <= 0) { y++; err += 2 * y + 1; }
        if (err > 0)  { x--; err -= 2 * x + 1; }
    }
}

// draw_text：用 GDI TextOut 绘制（利用系统字体，无需内嵌字体）
static void _cpu_draw_text(t_int x, t_int y, const char* text, int len, t_int rgba) {
    if (!_cpu_memdc || !text || len <= 0) return;
    // 0xAABBGGRR → COLORREF (0x00BBGGRR)
    COLORREF color = (COLORREF)(rgba & 0x00FFFFFF);
    SetTextColor(_cpu_memdc, color);
    SetBkMode(_cpu_memdc, TRANSPARENT);
    SelectObject(_cpu_memdc, _cpu_font);
    TextOutA(_cpu_memdc, (int)x, (int)y, text, len);
}

// ── pass 管理 ──

static void _cpu_begin_pass(t_int rgba) {
    // 清屏：用 fill_rect 填充整个帧缓冲
    _cpu_fill_rect(0, 0, _cpu_fb_w, _cpu_fb_h, rgba);
}

static void _cpu_end_pass(void) {
    // 帧缓冲已就绪，在事件循环中 BitBlt 到屏幕
}

// ── 事件分发 ──
//   构造 sapp_event 并调用 PHP 侧 on_event 回调
static void _cpu_dispatch_event(sapp_event* ev) {
    if (_ui_state.on_event_cb.func) {
        TP_TRY {
            ((void(*)(t_int, void*))_ui_state.on_event_cb.func)(
                (t_int)(intptr_t)ev, _ui_state.on_event_cb.env);
        } TP_CATCH_ANY(_tp_msg) {
            fflush(stdout);
            fprintf(stderr, "\nFatal error: Uncaught exception in event callback: %s\n\n",
                    STR_PTR(_tp_msg) ? STR_PTR(_tp_msg) : "(null)");
            fflush(stderr);
        } TP_END_TRY
    }
}

// ── Win32 窗口过程 ──
static LRESULT CALLBACK _cpu_wndproc(HWND hwnd, UINT msg, WPARAM wp, LPARAM lp) {
    switch (msg) {
    case WM_PAINT: {
        PAINTSTRUCT ps;
        HDC dc = BeginPaint(hwnd, &ps);
        if (_cpu_fb) {
            BitBlt(dc, 0, 0, _cpu_fb_w, _cpu_fb_h, _cpu_memdc, 0, 0, SRCCOPY);
        }
        EndPaint(hwnd, &ps);
        return 0;
    }
    case WM_KEYDOWN: {
        sapp_event ev;
        memset(&ev, 0, sizeof(ev));
        ev.type = SAPP_EVENTTYPE_KEY_DOWN;
        ev.key_code = (sapp_keycode)wp;
        _cpu_dispatch_event(&ev);
        return 0;
    }
    case WM_KEYUP: {
        sapp_event ev;
        memset(&ev, 0, sizeof(ev));
        ev.type = SAPP_EVENTTYPE_KEY_UP;
        ev.key_code = (sapp_keycode)wp;
        _cpu_dispatch_event(&ev);
        return 0;
    }
    case WM_CHAR: {
        sapp_event ev;
        memset(&ev, 0, sizeof(ev));
        ev.type = SAPP_EVENTTYPE_CHAR;
        ev.char_code = (uint32_t)wp;
        _cpu_dispatch_event(&ev);
        return 0;
    }
    case WM_LBUTTONDOWN: {
        sapp_event ev;
        memset(&ev, 0, sizeof(ev));
        ev.type = SAPP_EVENTTYPE_MOUSE_DOWN;
        ev.mouse_button = SAPP_MOUSEBUTTON_LEFT;
        ev.mouse_x = (float)(short)LOWORD(lp);
        ev.mouse_y = (float)(short)HIWORD(lp);
        _cpu_dispatch_event(&ev);
        return 0;
    }
    case WM_LBUTTONUP: {
        sapp_event ev;
        memset(&ev, 0, sizeof(ev));
        ev.type = SAPP_EVENTTYPE_MOUSE_UP;
        ev.mouse_button = SAPP_MOUSEBUTTON_LEFT;
        ev.mouse_x = (float)(short)LOWORD(lp);
        ev.mouse_y = (float)(short)HIWORD(lp);
        _cpu_dispatch_event(&ev);
        return 0;
    }
    case WM_RBUTTONDOWN: {
        sapp_event ev;
        memset(&ev, 0, sizeof(ev));
        ev.type = SAPP_EVENTTYPE_MOUSE_DOWN;
        ev.mouse_button = SAPP_MOUSEBUTTON_RIGHT;
        ev.mouse_x = (float)(short)LOWORD(lp);
        ev.mouse_y = (float)(short)HIWORD(lp);
        _cpu_dispatch_event(&ev);
        return 0;
    }
    case WM_RBUTTONUP: {
        sapp_event ev;
        memset(&ev, 0, sizeof(ev));
        ev.type = SAPP_EVENTTYPE_MOUSE_UP;
        ev.mouse_button = SAPP_MOUSEBUTTON_RIGHT;
        ev.mouse_x = (float)(short)LOWORD(lp);
        ev.mouse_y = (float)(short)HIWORD(lp);
        _cpu_dispatch_event(&ev);
        return 0;
    }
    case WM_MOUSEMOVE: {
        sapp_event ev;
        memset(&ev, 0, sizeof(ev));
        ev.type = SAPP_EVENTTYPE_MOUSE_MOVE;
        ev.mouse_x = (float)(short)LOWORD(lp);
        ev.mouse_y = (float)(short)HIWORD(lp);
        _cpu_dispatch_event(&ev);
        return 0;
    }
    case WM_SIZE: {
        // 窗口尺寸变化：重建帧缓冲
        int new_w = LOWORD(lp);
        int new_h = HIWORD(lp);
        if (new_w > 0 && new_h > 0 && (new_w != _cpu_fb_w || new_h != _cpu_fb_h)) {
            _cpu_fb_w = new_w;
            _cpu_fb_h = new_h;
            _cpu_state.width = new_w;
            _cpu_state.height = new_h;
            // 重建 DIB section
            if (_cpu_dib) DeleteObject(_cpu_dib);
            _cpu_bmi.bmiHeader.biWidth = _cpu_fb_w;
            _cpu_bmi.bmiHeader.biHeight = -_cpu_fb_h;
            _cpu_dib = CreateDIBSection(_cpu_memdc, &_cpu_bmi, DIB_RGB_COLORS,
                                        (void**)&_cpu_fb, NULL, 0);
            SelectObject(_cpu_memdc, _cpu_dib);
            // 通知 PHP 侧
            sapp_event ev;
            memset(&ev, 0, sizeof(ev));
            ev.type = SAPP_EVENTTYPE_RESIZED;
            ev.window_width = new_w;
            ev.window_height = new_h;
            _cpu_dispatch_event(&ev);
        }
        return 0;
    }
    case WM_CLOSE:
        _cpu_state.quit = true;
        DestroyWindow(hwnd);
        return 0;
    case WM_DESTROY:
        _cpu_state.quit = true;
        PostQuitMessage(0);
        return 0;
    default:
        return DefWindowProcA(hwnd, msg, wp, lp);
    }
}

// ── CPU 后端主入口 ──
static t_int _cpu_app_run(t_int width, t_int height, t_string title) {
    HINSTANCE hinst = GetModuleHandleA(NULL);

    // 注册窗口类
    WNDCLASSA wc;
    memset(&wc, 0, sizeof(wc));
    wc.lpfnWndProc = _cpu_wndproc;
    wc.hInstance = hinst;
    wc.hCursor = LoadCursorA(NULL, IDC_ARROW);
    wc.lpszClassName = "TinyPHP_UI_CPU";
    wc.hbrBackground = (HBRUSH)GetStockObject(BLACK_BRUSH);
    RegisterClassA(&wc);

    // 计算窗口尺寸（客户区 = width x height）
    RECT rc = {0, 0, (int)width, (int)height};
    AdjustWindowRect(&rc, WS_OVERLAPPED | WS_CAPTION | WS_SYSMENU | WS_MINIMIZEBOX, FALSE);

    // 创建窗口
    _cpu_hwnd = CreateWindowExA(0, "TinyPHP_UI_CPU", STR_PTR(title),
        WS_OVERLAPPED | WS_CAPTION | WS_SYSMENU | WS_MINIMIZEBOX | WS_VISIBLE,
        CW_USEDEFAULT, CW_USEDEFAULT,
        rc.right - rc.left, rc.bottom - rc.top,
        NULL, NULL, hinst, NULL);
    if (!_cpu_hwnd) {
        tp_throw("cpu_app_run: CreateWindow failed");
        return -1;
    }

    // 获取客户区尺寸
    RECT client_rc;
    GetClientRect(_cpu_hwnd, &client_rc);
    _cpu_fb_w = client_rc.right;
    _cpu_fb_h = client_rc.bottom;
    _cpu_state.width = _cpu_fb_w;
    _cpu_state.height = _cpu_fb_h;

    // 创建兼容 DC + DIB section（帧缓冲）
    HDC screen_dc = GetDC(_cpu_hwnd);
    _cpu_memdc = CreateCompatibleDC(screen_dc);
    memset(&_cpu_bmi, 0, sizeof(_cpu_bmi));
    _cpu_bmi.bmiHeader.biSize = sizeof(BITMAPINFOHEADER);
    _cpu_bmi.bmiHeader.biWidth = _cpu_fb_w;
    _cpu_bmi.bmiHeader.biHeight = -_cpu_fb_h;  // 负值 = top-down（y 向下递增）
    _cpu_bmi.bmiHeader.biPlanes = 1;
    _cpu_bmi.bmiHeader.biBitCount = 32;
    _cpu_bmi.bmiHeader.biCompression = BI_RGB;
    _cpu_dib = CreateDIBSection(_cpu_memdc, &_cpu_bmi, DIB_RGB_COLORS,
                                (void**)&_cpu_fb, NULL, 0);
    SelectObject(_cpu_memdc, _cpu_dib);
    // 创建默认字体
    _cpu_font = CreateFontA(16, 0, 0, 0, FW_NORMAL, FALSE, FALSE, FALSE,
                            DEFAULT_CHARSET, OUT_DEFAULT_PRECIS,
                            CLIP_DEFAULT_PRECIS, CLEARTYPE_QUALITY,
                            DEFAULT_PITCH | FF_DONTCARE, "Consolas");
    ReleaseDC(_cpu_hwnd, screen_dc);

    // 初始化帧缓冲为黑色
    if (_cpu_fb) {
        memset(_cpu_fb, 0, (size_t)_cpu_fb_w * _cpu_fb_h * sizeof(uint32_t));
    }

    // 调用 init 回调
    if (_ui_state.on_init_cb.func) {
        TP_TRY {
            ((void(*)(void*))_ui_state.on_init_cb.func)(_ui_state.on_init_cb.env);
        } TP_CATCH_ANY(_tp_msg) {
            fflush(stdout);
            fprintf(stderr, "\nFatal error: Uncaught exception in init callback: %s\n\n",
                    STR_PTR(_tp_msg) ? STR_PTR(_tp_msg) : "(null)");
            fflush(stderr);
            _cpu_state.quit = true;
        } TP_END_TRY
    }

    // 事件循环
    MSG msg;
    _cpu_state.quit = false;
    while (!_cpu_state.quit) {
        // 处理所有待处理消息
        while (PeekMessageA(&msg, NULL, 0, 0, PM_REMOVE)) {
            TranslateMessage(&msg);
            DispatchMessageA(&msg);
            if (_cpu_state.quit) break;
        }
        if (_cpu_state.quit) break;

        // 帧回调
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
            _cpu_state.quit = true;
        } TP_END_TRY
        _ui_state.pass_active = false;
        _ui_state.in_frame = false;

        // 刷新屏幕（BitBlt 帧缓冲到窗口）
        if (!_cpu_state.quit) {
            HDC dc = GetDC(_cpu_hwnd);
            BitBlt(dc, 0, 0, _cpu_fb_w, _cpu_fb_h, _cpu_memdc, 0, 0, SRCCOPY);
            ReleaseDC(_cpu_hwnd, dc);
        }

        // 限制帧率（约 60fps，Sleep 16ms）
        Sleep(16);
    }

    // 清理
    if (_cpu_font) { DeleteObject(_cpu_font); _cpu_font = NULL; }
    if (_cpu_dib)  { DeleteObject(_cpu_dib);  _cpu_dib = NULL; }
    if (_cpu_memdc){ DeleteDC(_cpu_memdc);    _cpu_memdc = NULL; }
    _cpu_fb = NULL;

    return 0;
}

// ── CPU 后端 DrawDevice 实现 ──
static ui_draw_device_t _ui_cpu_device = {
    .begin_pass  = _cpu_begin_pass,
    .end_pass    = _cpu_end_pass,
    .fill_rect   = _cpu_fill_rect,
    .draw_text   = _cpu_draw_text,
    .draw_line   = _cpu_draw_line,
    .draw_rect   = _cpu_draw_rect,
    .draw_circle = _cpu_draw_circle,
};

// ── CPU 后端窗口查询 ──
static inline t_int _cpu_window_width(void)  { return (t_int)_cpu_state.width; }
static inline t_int _cpu_window_height(void) { return (t_int)_cpu_state.height; }
static inline t_float _cpu_window_dpi_scale(void) { return (t_float)1.0; }
static inline void _cpu_window_set_cursor(t_int cursor) {
    // 映射到 Win32 标准光标
    static const char* cursors[] = {
        IDC_ARROW, IDC_IBEAM, IDC_CROSS, IDC_HAND,
        IDC_SIZEWE, IDC_SIZENS, IDC_SIZEALL, NULL  // Arrow/IBeam/Cross/Hand/ResizeX/ResizeY/ResizeAll/None
    };
    int idx = (int)cursor;
    if (idx >= 0 && idx < (int)(sizeof(cursors)/sizeof(cursors[0])) && cursors[idx]) {
        SetCursor(LoadCursorA(NULL, cursors[idx]));
    }
}

#elif defined(__ANDROID__)
// ── Android CPU 后端（stub：实际渲染由 sokol GLES3 处理）──
static int _cpu_app_run(t_int width, t_int height, t_string title) {
    // Android 上事件循环由 ANativeActivity 驱动，此函数不应被调用
    // 参数仅为保持签名与其他平台一致，避免调用点类型不匹配
    (void)width; (void)height; (void)title;
    return 0;
}
static int _cpu_window_width(void) {
    // 从 ANativeWindow 获取宽度（如果可用）
    return 0;
}
static int _cpu_window_height(void) {
    return 0;
}
static float _cpu_window_dpi_scale(void) {
    return 1.0f;
}
static void _cpu_window_set_cursor(int cursor) {
    // Android 无硬件鼠标光标
    (void)cursor;
}
static ui_draw_device_t _ui_cpu_device = {0};

#elif defined(__linux__)
// ── Linux X11 CPU 后端（后续实现）──
//   当前 Linux 环境（桌面）通常有 GPU 或 llvmpipe 软件渲染，
//   sokol 后端可直接使用。X11 CPU 后端作为无 X11 GL 时的回退。
//   TODO: 实现 X11 窗口 + XImage 帧缓冲 + XEvent 事件循环
static t_int _cpu_app_run(t_int width, t_int height, t_string title) {
    tp_throw("cpu_app_run: X11 CPU backend not yet implemented (use sokol backend on Linux)");
    return -1;
}
static ui_draw_device_t _ui_cpu_device = {0};
static inline t_int _cpu_window_width(void)  { return 0; }
static inline t_int _cpu_window_height(void) { return 0; }
static inline t_float _cpu_window_dpi_scale(void) { return (t_float)1.0; }
static inline void _cpu_window_set_cursor(t_int cursor) { (void)cursor; }

#elif defined(__APPLE__)
// ── macOS CPU 后端 ──
//   macOS Metal 总是可用（软件回退），无需 CPU 后端
static t_int _cpu_app_run(t_int width, t_int height, t_string title) {
    tp_throw("cpu_app_run: macOS always has Metal, no CPU fallback needed");
    return -1;
}
static ui_draw_device_t _ui_cpu_device = {0};
static inline t_int _cpu_window_width(void)  { return 0; }
static inline t_int _cpu_window_height(void) { return 0; }
static inline t_float _cpu_window_dpi_scale(void) { return (t_float)1.0; }
static inline void _cpu_window_set_cursor(t_int cursor) { (void)cursor; }

#endif
