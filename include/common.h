#pragma once

// ============================================================
// common.h — 总入口
//     引入 include/ 下全部头文件，生成的 C 代码只需 #include "common.h"
// ============================================================

#include "types.h"
#include "val.h"
#include "compat.h"            // math.h + 跨编译器 math 声明
#include "object/object.h"     // tp_obj_alloc/release — runtime.h 需要
#include "array.h"             // arr_freelist/tphp_fn_arr_* — runtime.h 需要
#include "runtime.h"           // tphp_fn_error + tphp_thread_cleanup 定义在此
/* 线程库 — 在 runtime.h 之后引入，使 thread wrapper 能调用 tphp_thread_cleanup() */
#define TPHP_THREAD_CLEANUP() tphp_thread_cleanup()
#include "compat/tinycthread.h"
#include "object/thread.h"      // Thread/Mutex/CondVar/WaitGroup COS 类
#include "object/exception.h"   // tphp_class_Exception — tp_throw 需要 (前置以供后续内函数使用)
#include "object/try.h"         // tp_throw 宏 — 前置以供 rand.h / builtin.h 内函数使用
#include "rand.h"               // CSPRNG (builtin.h 需要，内部用 tp_throw)
#include "builtin.h"
#include "tphp_math.h"         // TinyPHP math 扩展
#include "conv.h"
#include "hash.h"
#include "object/generator.h"    // Generator 类（基于 minicoro 协程）
#include "object/resource.h"     // Resource 基类（资源对象化根）
#include "os/times.h"
#include "os/json.h"
#include "os/file.h"
#include "os/file_obj.h"         // File 类（Resource 子类，替代 fopen resource）
#include "os/password.h"        // password_hash / password_verify
#include "phpc.h"
#include "filter.h"
#include "iconv.h"