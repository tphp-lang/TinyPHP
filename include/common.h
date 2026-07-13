#pragma once

// ============================================================
// common.h — 总入口
//     引入 include/ 下全部头文件，生成的 C 代码只需 #include "common.h"
// ============================================================

#include "types.h"
#include "val.h"
#include "compat.h"            // math.h + 跨编译器 math 声明
#include "object/object.h"     // tp_obj_alloc/release — runtime.h 需要

/* 前向声明 runtime.h 中的函数（避免循环 include，统一用 static inline）
 * 提前到 exception.h/try.h 之前，使 tp_throw 宏可在 array.h 中使用 */
static inline void tphp_rt_str_free(t_string* s);
static inline t_string tphp_rt_str_dup(t_string s);
static inline t_bool tphp_rt_str_eq(t_string a, t_string b);
static inline void tphp_rt_register(void *ptr, int type);
static inline void tphp_rt_free_all(void);

#include "object/exception.h"   // tphp_class_Exception — tp_throw 需要 (前置以供 array.h 内函数使用)
#include "object/try.h"         // tp_throw 宏 — 前置以供 array.h / rand.h / builtin.h 内函数使用
#include "array.h"             // arr_freelist/tphp_fn_arr_* — runtime.h 需要（内部用 tp_throw）
#include "runtime.h"           // tphp_thread_cleanup 定义在此
/* 线程库 — 在 runtime.h 之后引入，使 thread wrapper 能调用 tphp_thread_cleanup()。
 * 所有平台均启用 cleanup：TCC+Windows 和 TCC+macOS 通过 tls.h 真正隔离，
 * GCC/Clang 通过原生 _Thread_local 真正隔离。 */
#define TPHP_THREAD_CLEANUP() tphp_thread_cleanup()
#include "compat/tinycthread.h"
#include "object/thread.h"      // Thread/Mutex/CondVar/WaitGroup COS 类
#include "object/parallel.h"    // Parallel::map/for 数据并行 API
#include "rand.h"               // CSPRNG (builtin.h 需要，内部用 tp_throw)
#include "builtin.h"
#include "tphp_math.h"         // TinyPHP math 扩展
#include "conv.h"
#include "hash.h"
#include "object/generator.h"    // Generator 类（基于 minicoro 协程）
#include "object/resource.h"     // Resource 基类（资源对象化根）
#include "object/annotation.h"   // AnnotationEntry 类（注解系统）
#include "os/times.h"
#include "os/json.h"
#include "os/file.h"
#include "os/file_obj.h"         // File 类（Resource 子类，替代 fopen resource）
#include "os/password.h"        // password_hash / password_verify
#include "phpc.h"
#include "filter.h"
#include "iconv.h"