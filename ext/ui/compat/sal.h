// ext/ui/compat/sal.h — SAL (Source Annotation Language) 兼容头
//
// 背景：
//   sokol_app.h 的 WinMain 与 Windows UCRT 头(corecrt.h 等)大量使用 SAL 注解
//   (_In_ / _In_opt_z_ / _Check_return_ / _Pre_maybenull_ 等)。SAL 仅供静态分析，
//   编译期应展开为空。但不同编译器处境不同：
//
//   - TCC：完全不含 sal.h，必须手动定义为空。TCC 用自己的 winapi 头，不会拉取
//           MSVC UCRT 的 corecrt.h，因此只需覆盖 sokol 直接使用的少量注解即可。
//
//   - clang / gcc：项目在 Windows 下使用 MSYS2 的 MinGW 版（gnu/MinGW target），
//           其自带完整 sal.h。若此处用不完整的空定义垫片，会因 -I 路径优先级
//           遮蔽系统 sal.h 而报错，故用 #include_next 穿透到系统真实 sal.h。
//           （本项目不支持 MSVC，故不涉及 MSVC UCRT 的 corecrt.h。）
//
// 关键：#include_next 从当前文件所在目录之后继续搜索包含路径，从而跳过本垫片、
//       命中 MinGW 自带的完整 sal.h。TCC 不支持 #include_next（GNU 扩展），
//   故对 TCC 走空定义分支。
#pragma once

#ifndef _UI_COMPAT_SAL_H
#define _UI_COMPAT_SAL_H

#if defined(__TINYC__)
// ── TCC：无 sal.h，全部 SAL 注解定义为空 ──
// 覆盖 sokol / winapi 直接使用的注解（含 _z_ 后缀变体，防御性补全）。
#define _In_
#define _In_opt_
#define _In_z_
#define _In_opt_z_
#define _Out_
#define _Out_opt_
#define _Out_z_
#define _Out_opt_z_
#define _Inout_
#define _Inout_opt_
#define _Inout_z_
#define _Inout_opt_z_
#define _Ret_
#define _Ret_opt_
#define _Ret_z_
#define _Ret_opt_z_
#define _Pre_
#define _Post_
#define _Pre_opt_
#define _Post_opt_
#define _Pre_notnull_
#define _Pre_maybenull_
#define _Pre_null_
#define _Post_notnull_
#define _Post_maybenull_
#define _Post_null_
#define _Check_return_
#define _Check_return_opt_
#define _Success_(x)
#define _Out_writes_(x)
#define _Out_writes_z_(x)
#define _Out_writes_to_(x, y)
#define _Outptr_
#define _Outptr_opt_
#define _Deref_out_
#define _Deref_in_
#define _Deref_inout_
#define _Deref_out_z_
#define _Deref_inout_z_
#define _Deref_out_opt_
#define _Deref_inout_opt_
#define _Deref_out_opt_z_
#define _Null_terminated_
#define _NullNull_terminated_
#define _Printf_format_string_
#define _Scanf_format_string_

#else
// ── clang / gcc：穿透到系统真实 sal.h（MSVC UCRT 或 MinGW 自带，定义完整）──
// 修复 Windows + clang 编译 sokol→windows.h→corecrt.h 时 SAL 注解未定义的问题。
#include_next <sal.h>

#endif // __TINYC__

#endif // _UI_COMPAT_SAL_H
