#pragma once
// ============================================================
// tls.h — TCC on Windows 的 TLS（Thread Local Storage）兼容层
//
// 问题：TCC on Windows 不支持 _Thread_local / __declspec(thread)，
//       所有 _Thread_local 变量被所有线程共享（放在 .bss 段）。
//
// 解决：用 Windows TLS API（TlsAlloc/TlsGetValue/TlsSetValue）实现
//       真正的线程隔离。将所有 thread-local 状态打包到 tphp_tls_t
//       结构体中，每个线程通过 TLS slot 获取自己的副本。
//
// 使用：仅在 TCC+Windows 时启用，其他平台保持 _Thread_local 原样。
// ============================================================

#include "types.h"

/* 检测是否需要 Windows TLS 兼容层 */
#if defined(__TINYC__) && defined(_WIN32)
  #define TPHP_USE_WIN_TLS 1
#else
  #define TPHP_USE_WIN_TLS 0
#endif

#if TPHP_USE_WIN_TLS

#ifdef _WIN32
  #ifndef WIN32_LEAN_AND_MEAN
    #define WIN32_LEAN_AND_MEAN
  #endif
  #include <windows.h>
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

/* ── TLS slot 管理 ──
 * 使用 tentative definition（无 extern/static/初始化器的文件作用域声明），
 * 链接器在多 TU 间合并为同一个符号。TCC/GCC/Clang 均遵循 C 标准。 */
int   tphp_tls_initialized;  /* 0 = 未初始化（默认零初始化） */
DWORD tphp_tls_key;

/** 确保 TLS slot 已分配（多 TU 安全） */
static inline void tphp_tls_ensure_init(void) {
    if (!tphp_tls_initialized) {
        tphp_tls_key = TlsAlloc();
        tphp_tls_initialized = 1;
    }
}

/** 获取当前线程的 TLS（不存在则懒分配） */
static inline tphp_tls_t* tphp_tls_get(void) {
    tphp_tls_ensure_init();
    tphp_tls_t *tls = (tphp_tls_t *)TlsGetValue(tphp_tls_key);
    if (tls == NULL) {
        tls = (tphp_tls_t *)calloc(1, sizeof(tphp_tls_t));
        if (tls) TlsSetValue(tphp_tls_key, tls);
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
    tphp_tls_t *tls = (tphp_tls_t *)TlsGetValue(tphp_tls_key);
    if (tls) {
        free(tls);
        TlsSetValue(tphp_tls_key, NULL);
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

#endif /* TPHP_USE_WIN_TLS */
