#pragma once
// ============================================================
// tls.h — TCC 的 TLS（Thread Local Storage）兼容层
//
// 问题：TCC 不支持 _Thread_local 的 TLS 访问代码生成：
//   - TCC+Windows: __declspec(thread) 不生效（变量放在 .bss 段，所有线程共享）
//   - TCC+macOS aarch64: _Thread_local 生成错误的 TLS 访问代码，启动即 segfault
//
// 解决：用平台原生 TLS API 实现真正的线程隔离。将所有 thread-local 状态
//       打包到 tphp_tls_t 结构体中，每个线程通过 TLS key 获取自己的副本。
//   - TCC+Windows → Windows TLS API（TlsAlloc/TlsGetValue/TlsSetValue）
//   - TCC+macOS   → pthread TLS API（pthread_key_create/getspecific/setspecific）
//
// 使用：仅在 TCC+Windows 或 TCC+macOS 时启用，其他平台保持 _Thread_local 原样。
// ============================================================

#include "types.h"

/* 检测是否需要 TLS 兼容层 */
#if defined(__TINYC__) && defined(_WIN32)
  #define TPHP_USE_WIN_TLS     1
  #define TPHP_USE_PTHREAD_TLS 0
#elif defined(__TINYC__) && defined(__APPLE__)
  #define TPHP_USE_WIN_TLS     0
  #define TPHP_USE_PTHREAD_TLS 1
#else
  #define TPHP_USE_WIN_TLS     0
  #define TPHP_USE_PTHREAD_TLS 0
#endif

#if TPHP_USE_WIN_TLS || TPHP_USE_PTHREAD_TLS

#ifdef _WIN32
  #ifndef WIN32_LEAN_AND_MEAN
    #define WIN32_LEAN_AND_MEAN
  #endif
  #include <windows.h>
#else
  #include <pthread.h>
#endif

/* ── 前向声明（避免循环依赖） ── */
/* str_arena_block 和 tphp_rt_alloc 的完整定义在 runtime.h 中，
 * 但 tls.h 在 runtime.h 之前被 include（通过 object.h/array.h），
 * 所以只能用 void* 指针存储。 */
typedef struct _str_arena_block tphp_str_arena_block;
typedef struct tphp_rt_alloc   tphp_rt_alloc_t;

/* ── 所有需要线程隔离的运行时状态 ── */
typedef struct tphp_tls {
    char                 str_pool_buf[STR_POOL_SIZE];
    char                *str_pool_cur;
    tphp_str_arena_block *str_arena_head;
    tphp_rt_alloc_t      *tphp_alloc_head;
    t_array              *arr_freelist[ARR_POOL_MAX];
    int                   arr_freelist_count;
    _obj_pool_slot        _obj_freelist[OBJ_FREELIST_MAX];
    int                   _obj_freelist_count;
} tphp_tls_t;

/* ── TLS key 管理 ──
 * 使用 tentative definition（无 extern/static/初始化器的文件作用域声明），
 * 链接器在多 TU 间合并为同一个符号。TCC/GCC/Clang 均遵循 C 标准。 */
int   tphp_tls_initialized;  /* 0 = 未初始化（默认零初始化） */

#if TPHP_USE_WIN_TLS
DWORD tphp_tls_key;
#elif TPHP_USE_PTHREAD_TLS
pthread_key_t tphp_tls_key;
#endif

/** 确保 TLS key 已分配（多 TU 安全） */
static inline void tphp_tls_ensure_init(void) {
    if (!tphp_tls_initialized) {
#if TPHP_USE_WIN_TLS
        tphp_tls_key = TlsAlloc();
#elif TPHP_USE_PTHREAD_TLS
        pthread_key_create(&tphp_tls_key, NULL);
#endif
        tphp_tls_initialized = 1;
    }
}

/** 获取当前线程的 TLS（不存在则懒分配） */
static inline tphp_tls_t* tphp_tls_get(void) {
    tphp_tls_ensure_init();
#if TPHP_USE_WIN_TLS
    tphp_tls_t *tls = (tphp_tls_t *)TlsGetValue(tphp_tls_key);
#elif TPHP_USE_PTHREAD_TLS
    tphp_tls_t *tls = (tphp_tls_t *)pthread_getspecific(tphp_tls_key);
#endif
    if (tls == NULL) {
        tls = (tphp_tls_t *)calloc(1, sizeof(tphp_tls_t));
        if (tls) {
#if TPHP_USE_WIN_TLS
            TlsSetValue(tphp_tls_key, tls);
#elif TPHP_USE_PTHREAD_TLS
            pthread_setspecific(tphp_tls_key, tls);
#endif
        }
    }
    return tls;
}

/** 主线程初始化 TLS slot（在 tphp_rt_init 中调用） */
static inline void tphp_tls_init(void) {
    tphp_tls_ensure_init();
    /* 为主线程分配 TLS */
    tphp_tls_get();
}

/** 子线程销毁 TLS（在线程退出前调用） */
static inline void tphp_tls_destroy(void) {
#if TPHP_USE_WIN_TLS
    tphp_tls_t *tls = (tphp_tls_t *)TlsGetValue(tphp_tls_key);
#elif TPHP_USE_PTHREAD_TLS
    tphp_tls_t *tls = (tphp_tls_t *)pthread_getspecific(tphp_tls_key);
#endif
    if (tls) {
        free(tls);
#if TPHP_USE_WIN_TLS
        TlsSetValue(tphp_tls_key, NULL);
#elif TPHP_USE_PTHREAD_TLS
        pthread_setspecific(tphp_tls_key, NULL);
#endif
    }
}

/* ── 变量名映射宏 ──
 * 将原来的 _Thread_local 变量名映射到 TLS 结构体成员，
 * 使原有代码无需修改即可正确访问线程隔离的数据。 */
#define str_pool_buf        (tphp_tls_get()->str_pool_buf)
#define str_pool_cur        (tphp_tls_get()->str_pool_cur)
#define str_arena_head      (tphp_tls_get()->str_arena_head)
#define tphp_alloc_head     (tphp_tls_get()->tphp_alloc_head)
#define arr_freelist        (tphp_tls_get()->arr_freelist)
#define arr_freelist_count  (tphp_tls_get()->arr_freelist_count)
#define _obj_freelist       (tphp_tls_get()->_obj_freelist)
#define _obj_freelist_count (tphp_tls_get()->_obj_freelist_count)

#endif /* TPHP_USE_WIN_TLS || TPHP_USE_PTHREAD_TLS */
