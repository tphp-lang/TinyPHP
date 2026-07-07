#pragma once
// ============================================================
// try.h — COS 风格 setjmp/longjmp 异常处理
//
//   用法：
//     TP_TRY {
//         risky_code();
//     }
//     TP_CATCH(e) {
//         printf("caught: %s\n", e);
//     }
//     TP_FINALLY {
//         cleanup();
//     }
//     TP_END_TRY
//
//     TP_THROW(STR_LIT("something wrong").data);
// ============================================================

#include <setjmp.h>

// Exception frame — linked list on C stack
typedef struct _tp_ex_frame {
    jmp_buf                     jmp_buf;
    int32_t                     thrown;
    char                        msg_buf[256];
    void                       *ex_obj;   // 抛出的 Exception 对象指针（tp_throw 时为 NULL）
    struct _tp_ex_frame        *prev;
} tp_ex_frame;

static tp_ex_frame *_tp_ex_top = NULL;

#define TP_TRY \
    do { \
        tp_ex_frame _tp_f; \
        _tp_f.thrown  = 0; \
        _tp_f.msg_buf[0] = 0; \
        _tp_f.prev    = _tp_ex_top; \
        _tp_ex_top    = &_tp_f; \
        if (setjmp(_tp_f.jmp_buf) == 0) {

#define TP_CATCH(msg_var) \
        } \
        _tp_ex_top = _tp_f.prev; \
        if (_tp_f.thrown) { \
            t_string msg_var = tphp_rt_str_dup((t_string){_tp_f.msg_buf[0] ? _tp_f.msg_buf : "", (int)strlen(_tp_f.msg_buf[0] ? _tp_f.msg_buf : "")});

#define TP_FINALLY \
        } \
        _tp_ex_top = _tp_f.prev; \
        {

#define TP_END_TRY \
        } \
        if (_tp_f.thrown && _tp_ex_top != NULL) { \
            snprintf(_tp_ex_top->msg_buf, 256, "%s", _tp_f.msg_buf); \
            _tp_ex_top->thrown  = 1; \
            longjmp(_tp_ex_top->jmp_buf, 1); \
        } \
    } while(0);

#define tp_throw_ex(ex) \
    do { \
        tphp_class_Exception *_e = (tphp_class_Exception*)(ex); \
        if (_tp_ex_top != NULL) { \
            if (_e && STR_PTR_V(_e->message)) \
                snprintf(_tp_ex_top->msg_buf, 256, "%.*s", _e->message.length, STR_PTR_V(_e->message)); \
            _tp_ex_top->thrown  = 1; \
            tphp_rt_free_all(); \
            longjmp(_tp_ex_top->jmp_buf, 1); \
        } else { \
            tphp_rt_free_all(); \
            fprintf(stderr, "\nFatal error: Uncaught exception: %s\n\n", \
                _e && STR_PTR_V(_e->message) ? STR_PTR_V(_e->message) : "(null)"); \
            exit(1); \
        } \
    } while(0)

#define tp_throw(msg) \
    do { \
        if (_tp_ex_top != NULL) { \
            snprintf(_tp_ex_top->msg_buf, 256, "%s", (msg)); \
            _tp_ex_top->ex_obj  = NULL; \
            _tp_ex_top->thrown  = 1; \
            tphp_rt_free_all(); \
            longjmp(_tp_ex_top->jmp_buf, 1); \
        } else { \
            tphp_rt_free_all(); \
            fprintf(stderr, "\nFatal error: Uncaught exception: %s\n\n", (msg)); \
            exit(1); \
        } \
    } while(0)
