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
//   因为 tp_throw/tp_throw_ex 会在 longjmp 前调用 tphp_rt_free_all()，
//   str_pool 内存会被释放。malloc 分配的 msg 在 catch 宏中显式 free。
// ============================================================

#include <setjmp.h>

// Exception frame — linked list on C stack
typedef struct _tp_ex_frame {
    jmp_buf                     jmp_buf;
    int32_t                     thrown;
    char                       *msg;      // 动态分配的消息（malloc，非 str_pool），NULL 表示无消息
    void                       *ex_obj;   // 抛出的 Exception 对象指针（tp_throw 时为 NULL）
    struct _tp_ex_frame        *prev;
} tp_ex_frame;

static tp_ex_frame *_tp_ex_top = NULL;

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

#define TP_TRY \
    do { \
        tp_ex_frame _tp_f; \
        _tp_f.thrown  = 0; \
        _tp_f.msg     = NULL; \
        _tp_f.ex_obj  = NULL; \
        _tp_f.prev    = _tp_ex_top; \
        _tp_ex_top    = &_tp_f; \
        if (setjmp(_tp_f.jmp_buf) == 0) {

// 旧风格：仅取字符串消息（兼容现有代码）
#define TP_CATCH(msg_var) \
        } \
        _tp_ex_top = _tp_f.prev; \
        if (_tp_f.thrown) { \
            _tp_f.thrown = 0; \
            t_string msg_var = tphp_rt_str_dup((t_string){_tp_f.msg ? _tp_f.msg : "", _tp_f.msg ? (int)strlen(_tp_f.msg) : 0}); \
            free(_tp_f.msg); _tp_f.msg = NULL;

// 新风格：按类型捕获，ex_var 统一为 tphp_class_Exception*
// tp_obj_is_a 在原始对象指针上运行（cls 链有效）；getMessage 等通过 _tp_ex_as_exception 取 Exception*
#define TP_CATCH_EX(ex_var, cls) \
        } \
        if (_tp_f.thrown && _tp_f.ex_obj != NULL \
            && tp_obj_is_a(_tp_f.ex_obj, &_class_tphp_class_##cls)) { \
            _tp_ex_top = _tp_f.prev; \
            _tp_f.thrown = 0; \
            free(_tp_f.msg); _tp_f.msg = NULL; \
            tphp_class_Exception *ex_var = _tp_ex_as_exception(_tp_f.ex_obj);

// 兜底：捕获任何异常（对象或字符串消息）
// ex_var 为 t_string 类型，存储消息（对象取 message，否则取 msg）
#define TP_CATCH_ANY(msg_var) \
        } \
        if (_tp_f.thrown) { \
            _tp_ex_top = _tp_f.prev; \
            _tp_f.thrown = 0; \
            t_string msg_var; \
            if (_tp_f.ex_obj != NULL) { \
                tphp_class_Exception *_te = _tp_ex_as_exception(_tp_f.ex_obj); \
                msg_var = tphp_rt_str_dup(_te->message); \
            } else { \
                msg_var = tphp_rt_str_dup((t_string){_tp_f.msg ? _tp_f.msg : "", _tp_f.msg ? (int)strlen(_tp_f.msg) : 0}); \
            } \
            free(_tp_f.msg); _tp_f.msg = NULL;

#define TP_FINALLY \
        } \
        _tp_ex_top = _tp_f.prev; \
        {

// 结束：若本帧捕获了异常但未被任何 catch 处理，则向上重新抛出
#define TP_END_TRY \
        } \
        if (_tp_f.thrown && _tp_ex_top != NULL) { \
            _tp_ex_top->thrown  = 1; \
            _tp_ex_top->ex_obj  = _tp_f.ex_obj; \
            if (_tp_f.ex_obj == NULL && _tp_f.msg != NULL) { \
                free(_tp_ex_top->msg); \
                _tp_ex_top->msg = _tp_dup_msg(_tp_f.msg); \
            } \
            free(_tp_f.msg); _tp_f.msg = NULL; \
            longjmp(_tp_ex_top->jmp_buf, 1); \
        } \
        free(_tp_f.msg); \
    } while(0);

// tp_throw_ex 接收原始对象指针（Exception 子类实例），内部通过 cls->exception_offset 计算 Exception*
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
            /* 把 Exception 从全局注册列表移除，避免被 tphp_rt_free_all 释放（catch 块还需访问它） */ \
            if (_orig != NULL) tphp_rt_unregister(_orig); \
            tphp_rt_free_all(); \
            longjmp(_tp_ex_top->jmp_buf, 1); \
        } else { \
            tphp_rt_free_all(); \
            fprintf(stderr, "\nFatal error: Uncaught exception: %s\n\n", \
                _e && STR_PTR_V(_e->message) ? STR_PTR_V(_e->message) : "(null)"); \
            exit(1); \
        } \
    } while(0)

// 注意：参数名用 _tp_msg 而非 msg，避免与结构体字段 _tp_ex_top->msg 冲突
// （宏参数 msg 会被展开进 _tp_ex_top->msg 中，导致 "_tp_ex_top->STR_PTR_V(x)" 般的错误）
#define tp_throw(_tp_msg) \
    do { \
        if (_tp_ex_top != NULL) { \
            free(_tp_ex_top->msg); \
            _tp_ex_top->msg = _tp_dup_msg(_tp_msg); \
            _tp_ex_top->ex_obj  = NULL; \
            _tp_ex_top->thrown  = 1; \
            tphp_rt_free_all(); \
            longjmp(_tp_ex_top->jmp_buf, 1); \
        } else { \
            tphp_rt_free_all(); \
            fprintf(stderr, "\nFatal error: Uncaught exception: %s\n\n", (_tp_msg)); \
            exit(1); \
        } \
    } while(0)
