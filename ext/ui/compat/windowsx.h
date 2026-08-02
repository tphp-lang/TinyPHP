// ext/ui/compat/windowsx.h — Minimal windowsx.h compatibility header for TCC
//
// TCC 不含 windowsx.h，sokol_app.h 仅需要 GET_X_LPARAM / GET_Y_LPARAM 宏
// 用于从 WM_MOUSEMOVE 等 Windows 消息的 lParam 提取鼠标坐标。
//
// 不修改 sokol 头文件，通过 #flag -I 将本目录加入搜索路径。
#pragma once

#ifndef _UI_COMPAT_WINDOWSX_H
#define _UI_COMPAT_WINDOWSX_H

#ifndef GET_X_LPARAM
#define GET_X_LPARAM(lp) ((int)(short)(lp))
#endif

#ifndef GET_Y_LPARAM
#define GET_Y_LPARAM(lp) ((int)(short)((lp) >> 16))
#endif

#endif // _UI_COMPAT_WINDOWSX_H
