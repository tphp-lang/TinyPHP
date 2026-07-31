#pragma once
// ============================================================
// try.h — COS 风格 setjmp/longjmp 异常处理
//
//   用法 1（旧风格，字符串消息）：
//     TP_TRY {
//         risky_code();
//     }
//     TP_CATCH(msg) {
//         printf("caught: %s\n", msg);
//     }
//     TP_FINALLY {
//         cleanup();
//     }
//     TP_END_TRY
//
//   用法 2（按类型捕获，推荐）：
//     TP_TRY {
//         risky_code();
//     }
//     TP_CATCH_EX(e, MyException) {
//         // e 为 tphp_class_MyException*，已通过 tp_obj_is_a 校验
//     }
//     TP_CATCH_EX(e, Exception) {
//         // fallback
//     }
//     TP_CATCH_ANY(msg) {
//         // 字符串消息兜底（tp_throw 抛出的非对象异常）
//     }
//     TP_END_TRY
//
//     tp_throw_ex(new_tphp_class_Exception(STR_LIT("msg")));
//     tp_throw("plain string message");
//
//   注意：msg 字段使用 malloc 动态分配（非 str_pool_alloc），
//   以便跨 longjmp 安全传递（str_pool 是 bump allocator，不单独释放）。
//   caught 路径不调 tphp_rt_free_all()，避免释放 catch 后仍需访问的资源；
//   仅 uncaught 路径（exit 前）调 tphp_rt_free_all() 做最终清理。
//
//   兼容性设计（跨编译器 setjmp/longjmp 安全）：
//   C11 7.13.2.1 规定：setjmp 所在函数的自动存储期变量，若在 setjmp 与 longjmp
//   之间被修改且非 volatile，则 longjmp 后值不确定。_tp_f 是局部变量，其字段通过
//   _tp_ex_top 指针间接修改。即使字段声明为 volatile，部分编译器 -O2 仍可能
//   缓存局部变量值。
//
//   解决方案：所有 catch 宏通过全局指针 _tp_ex_top（volatile）读取异常状态，
//   而非局部变量 _tp_f。全局变量不受 C11 7.13.2.1 约束，值在 longjmp 后确定。
// ============================================================

#include <setjmp.h>

// ── 跨编译器 setjmp 兼容层 ──────────────────────────────────
// MinGW x86_64 SEH 模式下，<setjmp.h> 将 setjmp 宏展开为
//   _setjmp(buf, __builtin_frame_address(0))
// _setjmp 将 frame 地址存入 jmp_buf.Frame 字段，longjmp 时据此调用
// RtlUnwindEx 做 SEH 栈展开。但 GCC -O2 内联优化可能导致部分函数的
// SEH unwind 表与实际栈布局不匹配，RtlUnwindEx 展开时触发 ACCESS_VIOLATION。
//
// 兼容方案：在 MinGW x86_64 SEH 下使用 _setjmp(buf, NULL)，frame=NULL
// 使 longjmp 跳过 RtlUnwindEx，仅恢复寄存器并跳转（C 代码无需调用析构函数，
// 跳过栈展开是安全的）。其他平台继续使用标准 setjmp 宏。
#if defined(__MINGW32__) && defined(__x86_64__) && defined(__SEH__) && !defined(__clang__)
  #define TP_SETJMP(buf) _setjmp((buf), NULL)
#else
  #define TP_SETJMP(buf) setjmp(buf)
#endif

// Exception frame — linked list on C stack
//   thrown / msg / ex_obj 字段 volatile：通过 _tp_ex_top 指针间接修改，
//   volatile 确保编译器不缓存到寄存器。
typedef struct _tp_ex_frame {
    jmp_buf                     jmp_buf;
    volatile int32_t            thrown;
    char * volatile             msg;      // 动态分配的消息（malloc，非 str_pool），NULL 表示无消息
    void * volatile             ex_obj;   // 抛出的 Exception 对象指针（tp_throw 时为 NULL）
    struct _tp_ex_frame        *prev;
} tp_ex_frame;

// _tp_ex_top 声明为 volatile 指针：确保编译器不缓存其值，
//   每次 read/write 都访问内存。全局变量不受 C11 7.13.2.1 约束。
static tp_ex_frame *volatile _tp_ex_top = NULL;

// noinline 辅助函数：执行 longjmp 跳转。
//   必须作为独立函数（noinline），防止 GCC -O2 将其内联到调用者后，
//   破坏 SEH unwind 信息导致 longjmp 跨函数跳转时崩溃。
//   问题根因：MinGW x64 的 _setjmp/longjmp 基于 SEH 表进行栈展开，
//   当 throw 逻辑（含 longjmp）被内联到含 setjmp 的函数时，SEH 表与
//   实际栈布局不匹配，longjmp 恢复寄存器/栈指针时触发 ACCESS_VIOLATION。
//   将 longjmp 隔离到 noinline 函数中，SEH 展开止于此函数边界，
//   不依赖调用者的 SEH 表正确性。
#if defined(__GNUC__) && !defined(__clang__)
__attribute__((noinline, noreturn))
#endif
static void _tp_do_longjmp(volatile tp_ex_frame *frame) {
    longjmp(*(jmp_buf*)frame, 1);
}

// 内部辅助：从 frame 提取异常对象（无则返回 NULL）
static inline void* _tp_ex_obj(void) {
    return (_tp_ex_top && _tp_ex_top->thrown) ? _tp_ex_top->ex_obj : NULL;
}

// 内部辅助：从原始对象指针计算 Exception*（通过类描述符的 exception_offset）
static inline tphp_class_Exception* _tp_ex_as_exception(void *obj) {
    if (obj == NULL) return NULL;
    const t_class *cls = ((t_object*)obj)->cls;
    uint32_t off = cls ? cls->exception_offset : 0;
    return (tphp_class_Exception*)((char*)obj + off);
}

// 内部辅助：复制 C 字符串消息（malloc 分配，survives tphp_rt_free_all）
static inline char* _tp_dup_msg(const char* s) {
    if (s == NULL) return NULL;
    size_t len = strlen(s);
    char* p = (char*)malloc(len + 1);
    if (p) { memcpy(p, s, len); p[len] = '\0'; }
    return p;
}

// 内部辅助：从带长度的数据复制消息（用于 t_string.message）
static inline char* _tp_dup_msg_n(const char* s, int len) {
    if (s == NULL || len <= 0) return NULL;
    char* p = (char*)malloc((size_t)len + 1);
    if (p) { memcpy(p, s, (size_t)len); p[len] = '\0'; }
    return p;
}

// TP_TRY：建立异常帧，setjmp 等待 longjmp
//   _tp_f 是局部变量（自动存储期），但仅用于：
//     - jmp_buf（setjmp 保存点，不被 longjmp 修改）
//     - prev（TP_TRY 前设置，不被 longjmp 修改）
//     - thrown/msg/ex_obj 的初始值（TP_TRY 前设置，不被 longjmp 修改）
//   catch 宏通过全局 _tp_ex_top 读取 thrown/msg/ex_obj 的 longjmp 后值
//   _tp_need_rethrow / _tp_rethrow_obj / _tp_rethrow_msg：
//     供 TP_FINALLY 保存未处理异常状态，TP_END_TRY 据此重抛。
#define TP_TRY \
    do { \
        tp_ex_frame _tp_f; \
        int _tp_need_rethrow = 0; \
        void *_tp_rethrow_obj = NULL; \
        char *_tp_rethrow_msg = NULL; \
        _tp_f.thrown  = 0; \
        _tp_f.msg     = NULL; \
        _tp_f.ex_obj  = NULL; \
        _tp_f.prev    = _tp_ex_top; \
        _tp_ex_top    = &_tp_f; \
        if (TP_SETJMP(_tp_f.jmp_buf) == 0) {

// 旧风格：仅取字符串消息（兼容现有代码）
//   通过 _tp_ex_top 读取异常状态（全局 volatile 指针，不受 setjmp/longjmp 约束）
//   _tp_ex_top == &_tp_f 检查异常是否已被前一个 catch 块处理
#define TP_CATCH(msg_var) \
        } \
        if (_tp_ex_top == (tp_ex_frame*)&_tp_f && _tp_ex_top->thrown) { \
            char *_tp_c_msg = _tp_ex_top->msg; \
            tp_ex_frame *_tp_c_prev = _tp_ex_top->prev; \
            _tp_ex_top->thrown = 0; \
            _tp_ex_top->msg = NULL; \
            _tp_ex_top = _tp_c_prev; \
            t_string msg_var = tphp_rt_str_dup((t_string){_tp_c_msg ? _tp_c_msg : "", _tp_c_msg ? (int)strlen(_tp_c_msg) : 0}); \
            free(_tp_c_msg);

// 新风格：按类型捕获，ex_var 统一为 tphp_class_Exception*
//   tp_obj_is_a 在原始对象指针上运行（cls 链有效）；getMessage 等通过 _tp_ex_as_exception 取 Exception*
#define TP_CATCH_EX(ex_var, cls) \
        } \
        if (_tp_ex_top == (tp_ex_frame*)&_tp_f && _tp_ex_top->thrown && _tp_ex_top->ex_obj != NULL \
            && tp_obj_is_a(_tp_ex_top->ex_obj, &_class_tphp_class_##cls)) { \
            void *_tp_c_obj = _tp_ex_top->ex_obj; \
            char *_tp_c_msg = _tp_ex_top->msg; \
            tp_ex_frame *_tp_c_prev = _tp_ex_top->prev; \
            _tp_ex_top->thrown = 0; \
            _tp_ex_top->msg = NULL; \
            _tp_ex_top = _tp_c_prev; \
            free(_tp_c_msg); \
            tphp_class_Exception *ex_var = _tp_ex_as_exception(_tp_c_obj);

// 多类型捕获（PHP 8.0+ catch (A | B $e)）：cond 为预构建的 OR 类型检查表达式，
//   形如 (tp_obj_is_a(_tp_ex_top->ex_obj, &_class_tphp_class_A) || tp_obj_is_a(_tp_ex_top->ex_obj, &_class_tphp_class_B))
// 其余行为与 TP_CATCH_EX 一致
#define TP_CATCH_EX_MULTI(ex_var, cond) \
        } \
        if (_tp_ex_top == (tp_ex_frame*)&_tp_f && _tp_ex_top->thrown && _tp_ex_top->ex_obj != NULL && (cond)) { \
            void *_tp_c_obj = _tp_ex_top->ex_obj; \
            char *_tp_c_msg = _tp_ex_top->msg; \
            tp_ex_frame *_tp_c_prev = _tp_ex_top->prev; \
            _tp_ex_top->thrown = 0; \
            _tp_ex_top->msg = NULL; \
            _tp_ex_top = _tp_c_prev; \
            free(_tp_c_msg); \
            tphp_class_Exception *ex_var = _tp_ex_as_exception(_tp_c_obj);

// 兜底：捕获任何异常（对象或字符串消息）
//   msg_var 为 t_string 类型，存储消息（对象取 message，否则取 msg）
#define TP_CATCH_ANY(msg_var) \
        } \
        if (_tp_ex_top == (tp_ex_frame*)&_tp_f && _tp_ex_top->thrown) { \
            void *_tp_c_obj = _tp_ex_top->ex_obj; \
            char *_tp_c_msg = _tp_ex_top->msg; \
            tp_ex_frame *_tp_c_prev = _tp_ex_top->prev; \
            _tp_ex_top->thrown = 0; \
            _tp_ex_top->msg = NULL; \
            _tp_ex_top = _tp_c_prev; \
            t_string msg_var; \
            if (_tp_c_obj != NULL) { \
                tphp_class_Exception *_te = _tp_ex_as_exception(_tp_c_obj); \
                msg_var = tphp_rt_str_dup(_te->message); \
            } else { \
                msg_var = tphp_rt_str_dup((t_string){_tp_c_msg ? _tp_c_msg : "", _tp_c_msg ? (int)strlen(_tp_c_msg) : 0}); \
            } \
            free(_tp_c_msg);

// TP_FINALLY：执行 finally 块。
//   若有未处理异常（thrown=1）：保存异常状态到局部变量，设置 _tp_need_rethrow，
//   清除当前帧并 _tp_ex_top=prev，使 finally 块内 throw 新异常能到父帧。
//   TP_END_TRY 检查 _tp_need_rethrow 决定是否重抛原异常。
//   若无异常（thrown=0 或已被 catch 处理）：_tp_ex_top=prev（仅正常完成时需要）。
#define TP_FINALLY \
        } \
        if (_tp_ex_top == (tp_ex_frame*)&_tp_f && _tp_ex_top->thrown) { \
            _tp_need_rethrow = 1; \
            _tp_rethrow_obj = _tp_ex_top->ex_obj; \
            _tp_rethrow_msg = _tp_ex_top->msg; \
            _tp_ex_top->thrown = 0; \
            _tp_ex_top->msg = NULL; \
            _tp_ex_top->ex_obj = NULL; \
            _tp_ex_top = _tp_ex_top->prev; \
        } else if (_tp_ex_top == (tp_ex_frame*)&_tp_f) { \
            _tp_ex_top = _tp_ex_top->prev; \
        } \
        {

// 结束：重抛未被 catch 处理的异常。
//   两条重抛路径：
//     1. _tp_need_rethrow：TP_FINALLY 保存的异常（finally 块正常完成，无新 throw）
//     2. _tp_ex_top == &_tp_f && thrown：无 finally 时，异常未被 catch 处理
//   若 _tp_ex_top != &_tp_f，说明异常已被 catch 处理（_tp_ex_top 已恢复到父帧）
#define TP_END_TRY \
        } \
        if (_tp_need_rethrow) { \
            void *_tp_r_obj = _tp_rethrow_obj; \
            char *_tp_r_msg = _tp_rethrow_msg; \
            tp_ex_frame *_tp_r_prev = _tp_ex_top; \
            if (_tp_r_prev != NULL) { \
                _tp_r_prev->thrown  = 1; \
                _tp_r_prev->ex_obj  = _tp_r_obj; \
                if (_tp_r_obj == NULL && _tp_r_msg != NULL) { \
                    free(_tp_r_prev->msg); \
                    _tp_r_prev->msg = _tp_dup_msg(_tp_r_msg); \
                } \
                free(_tp_r_msg); \
                _tp_do_longjmp(_tp_r_prev); \
            } else { \
                free(_tp_r_msg); \
            } \
        } else if (_tp_ex_top == (tp_ex_frame*)&_tp_f && _tp_ex_top->thrown) { \
            void *_tp_r_obj = _tp_ex_top->ex_obj; \
            char *_tp_r_msg = _tp_ex_top->msg; \
            tp_ex_frame *_tp_r_prev = _tp_ex_top->prev; \
            _tp_ex_top = _tp_r_prev; \
            if (_tp_r_prev != NULL) { \
                _tp_r_prev->thrown  = 1; \
                _tp_r_prev->ex_obj  = _tp_r_obj; \
                if (_tp_r_obj == NULL && _tp_r_msg != NULL) { \
                    free(_tp_r_prev->msg); \
                    _tp_r_prev->msg = _tp_dup_msg(_tp_r_msg); \
                } \
                free(_tp_r_msg); \
                _tp_do_longjmp(_tp_r_prev); \
            } else { \
                free(_tp_r_msg); \
            } \
        } \
    } while(0);

// tp_throw_ex 接收原始对象指针（Exception 子类实例），内部通过 cls->exception_offset 计算 Exception*
// 注意：caught 路径不调 tphp_rt_free_all() — 那会释放 catch 后仍需访问的已注册数组/对象，
// 导致 unset 时 double-free。str_pool 是 bump allocator 不会单独释放，arena 块在 thread cleanup 回收。
#define tp_throw_ex(ex) \
    do { \
        void *_orig = (void*)(ex); \
        tphp_class_Exception *_e = _tp_ex_as_exception(_orig); \
        if (_tp_ex_top != NULL) { \
            _tp_ex_top->ex_obj  = _orig; \
            free(_tp_ex_top->msg); \
            _tp_ex_top->msg = (_e && STR_PTR_V(_e->message)) \
                ? _tp_dup_msg_n(STR_PTR_V(_e->message), _e->message.length) \
                : NULL; \
            _tp_ex_top->thrown  = 1; \
            /* 把 Exception 从全局注册列表移除，避免被后续 free_all 释放（catch 块还需访问它） */ \
            if (_orig != NULL) tphp_rt_unregister(_orig); \
            _tp_do_longjmp(_tp_ex_top); \
        } else { \
            /* 提取消息到 malloc 缓冲区（survives tphp_rt_free_all），先清理再打印 */ \
            char *_tp_fatal_msg = (_e && STR_PTR_V(_e->message)) \
                ? _tp_dup_msg_n(STR_PTR_V(_e->message), _e->message.length) \
                : NULL; \
            tphp_rt_free_all(); \
            fflush(stdout); \
            fprintf(stderr, "\nFatal error: Uncaught exception: %s\n\n", \
                _tp_fatal_msg ? _tp_fatal_msg : "(null)"); \
            fflush(stderr); \
            free(_tp_fatal_msg); \
            exit(1); \
        } \
    } while(0)

// 注意：参数名用 _tp_msg 而非 msg，避免与结构体字段 _tp_ex_top->msg 冲突
// （宏参数 msg 会被展开进 _tp_ex_top->msg 中，导致 "_tp_ex_top->STR_PTR_V(x)" 般的错误）
//   tp_throw("msg") 等价于 throw new Exception("msg")：
//   有 try/catch 帧时创建 Exception 对象（使 catch(Exception $e) 能捕获），
//   OOM 降级为纯字符串异常（ex_obj=NULL，由 TP_CATCH_ANY 兜底）。
#define tp_throw(_tp_msg) \
    do { \
        if (_tp_ex_top != NULL) { \
            tphp_class_Exception *_te = new_tphp_class_Exception((t_string){(char*)(_tp_msg), (int)strlen(_tp_msg)}); \
            if (_te != NULL) { \
                tphp_rt_unregister((void*)_te); \
                _tp_ex_top->ex_obj = (void*)_te; \
            } else { \
                _tp_ex_top->ex_obj = NULL; \
            } \
            free(_tp_ex_top->msg); \
            _tp_ex_top->msg = _tp_dup_msg(_tp_msg); \
            _tp_ex_top->thrown  = 1; \
            _tp_do_longjmp(_tp_ex_top); \
        } else { \
            /* _tp_msg 可能指向 str_pool/arena，tphp_rt_free_all 后失效，先复制 */ \
            char *_tp_fatal_msg = _tp_dup_msg(_tp_msg); \
            tphp_rt_free_all(); \
            fflush(stdout); \
            fprintf(stderr, "\nFatal error: Uncaught exception: %s\n\n", \
                _tp_fatal_msg ? _tp_fatal_msg : "(null)"); \
            fflush(stderr); \
            free(_tp_fatal_msg); \
            exit(1); \
        } \
    } while(0)
