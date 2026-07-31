// ext/ui/compat/shellapi.h — Minimal shellapi.h compatibility header for TCC
//
// TCC 不含 shellapi.h，sokol_app.h 需要 HDROP 类型、DragQueryFileW /
// DragFinish / CommandLineToArgvW 函数（用于文件拖放和命令行解析）。
//
// 不修改 sokol 头文件，通过 #flag -I 将本目录加入搜索路径。
// 链接 shell32.lib 提供 DragQueryFileW 等实现。
#pragma once

#ifndef _UI_COMPAT_SHELLAPI_H
#define _UI_COMPAT_SHELLAPI_H

#include <windows.h>

// HDROP — 拖放操作句柄（Win32 中为 HANDLE 子类型）
#ifndef HDROP
DECLARE_HANDLE(HDROP);
#endif

// 拖放文件查询
#ifdef __cplusplus
extern "C" {
#endif

void WINAPI DragAcceptFiles(HWND hWnd, BOOL fAccept);
UINT WINAPI DragQueryFileW(HDROP hDrop, UINT iFile, LPWSTR lpszFile, UINT cch);
void WINAPI DragFinish(HDROP hDrop);

// 命令行参数解析
LPWSTR* WINAPI CommandLineToArgvW(LPCWSTR lpCmdLine, int* pNumArgs);

#ifdef __cplusplus
}
#endif

#endif // _UI_COMPAT_SHELLAPI_H
