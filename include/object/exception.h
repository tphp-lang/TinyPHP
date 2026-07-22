#pragma once
// ============================================================
// exception.h — 内置 Exception 类
//   throw new Exception("message");
//   catch (Exception $e) { echo $e->getMessage(); }
// ============================================================

// Exception struct (COS object header + message)
typedef struct {
    t_object _obj;
    t_string message;
} tphp_class_Exception;

// Forward declarations (static inline: 每个 TU 独立副本，避免链接时重复定义)
static inline void tphp_class_Exception___construct(tphp_class_Exception* self, t_string msg);
static inline void tphp_class_Exception___destruct(tphp_class_Exception* self);
static inline t_string tphp_class_Exception_getMessage(tphp_class_Exception* self);

// Class descriptor
static void* _vtable_tphp_class_Exception[1] = { NULL };
static const t_class _class_tphp_class_Exception = {
    .name          = "Exception",
    .parent        = NULL,
    .instance_size = sizeof(tphp_class_Exception),
    .exception_offset = 0,
    .dtor          = (void*)tphp_class_Exception___destruct,
    .vtable        = _vtable_tphp_class_Exception,
    .vtable_len    = 0,
};

// Constructor: new Exception("msg")
//   注意：exception.h 在 try.h 之前 include（try.h 的 tp_throw_ex 需要
//   tphp_class_Exception 定义），因此此处不能使用 tp_throw 宏。
//   OOM 是极端情况，tp_obj_alloc 内部已做处理，此处仅返回 NULL。
static inline tphp_class_Exception* new_tphp_class_Exception(t_string msg) {
    tphp_class_Exception* self = (tphp_class_Exception*)tp_obj_alloc(&_class_tphp_class_Exception);
    if (self == NULL) return NULL;
    self->message = tphp_rt_str_dup(msg);
    tphp_rt_register((void*)self, 0);
    return self;
}

// Methods
static inline void tphp_class_Exception___construct(tphp_class_Exception* self, t_string msg) {
    (void)self; (void)msg; // handled by allocator
}
static inline void tphp_class_Exception___destruct(tphp_class_Exception* self) {
    if (self) self->message = (t_string){NULL, 0};
}
static inline t_string tphp_class_Exception_getMessage(tphp_class_Exception* self) {
    // self == NULL 是编程错误（对 null 变量调方法），不应在正常运行中出现。
    // 此处不能用 tp_throw（exception.h 在 try.h 之前 include），
    // 也不能用 fprintf(stderr)（exception.h 在 <stdio.h> 之前 include）。
    // 返回空字符串，由调用方处理。
    if (self == NULL) return (t_string){NULL, 0, false};
    return self->message;
}
