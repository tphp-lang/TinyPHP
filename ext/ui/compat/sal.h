// ext/ui/compat/sal.h — Minimal sal.h compatibility header for TCC
//
// TCC 不含 sal.h（Microsoft Source Annotation Language），
// sokol_app.h 的 WinMain 使用 _In_ / _In_opt_ 等 SAL 标注。
// 将所有 SAL 标注定义为空，不影响编译。
#pragma once

#ifndef _UI_COMPAT_SAL_H
#define _UI_COMPAT_SAL_H

#define _In_
#define _In_opt_
#define _Out_
#define _Out_opt_
#define _Inout_
#define _Inout_opt_
#define _Ret_
#define _Ret_opt_
#define _Pre_
#define _Post_
#define _Pre_opt_
#define _Post_opt_

#endif // _UI_COMPAT_SAL_H
