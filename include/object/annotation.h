#pragma once
// ============================================================
// annotation.h — 内置 AnnotationEntry 类（注解系统）
//
//   由 #[Attribute(...)] const NAME = []; 声明，
//   #[NAME(args)] 附着于 class/method/function 时由编译期收集。
//   运行时表示为 t_array*<tphp_class_AnnotationEntry*>。
//
//   字段:
//     data : t_array*  — 位置参数数组
//     type : t_string  — "method" / "static_method" / "class" / "function"
//     name : t_string  — 限定名 (Ns\Class->method / Ns\Class::method / Ns\func / Ns\Class)
//
//   方法 call() / newInstance() 不在此实现 — 由编译期静态索引展开为直接调用。
// ============================================================

#include "object/object.h"
#include "types.h"

typedef struct {
    t_object    _obj;
    t_array*    data;   /* 位置参数数组 */
    t_string    type;   /* "method" / "static_method" / "class" / "function" */
    t_string    name;   /* 限定名 */
} tphp_class_AnnotationEntry;

/* 前置声明 */
static void tphp_class_AnnotationEntry___destruct(tphp_class_AnnotationEntry *self);

static void* _vtable_tphp_class_AnnotationEntry[1] = { NULL };
static const t_class _class_tphp_class_AnnotationEntry = {
    .name          = "AnnotationEntry",
    .parent        = NULL,
    .instance_size = sizeof(tphp_class_AnnotationEntry),
    .exception_offset = 0,
    .dtor          = (void*)tphp_class_AnnotationEntry___destruct,
    .vtable        = _vtable_tphp_class_AnnotationEntry,
    .vtable_len    = 0,
};

static inline tphp_class_AnnotationEntry* new_tphp_class_AnnotationEntry(t_array* data, t_string type, t_string name) {
    tphp_class_AnnotationEntry* self = (tphp_class_AnnotationEntry*)tp_obj_alloc(&_class_tphp_class_AnnotationEntry);
    if (self) {
        self->data = data;
        self->type = type;
        self->name = name;
        tphp_rt_register((void*)self, 0);
    }
    return self;
}

static void tphp_class_AnnotationEntry___construct(tphp_class_AnnotationEntry *self, t_array* data, t_string type, t_string name) {
    (void)self; (void)data; (void)type; (void)name; /* 由 allocator 处理 */
}

static void tphp_class_AnnotationEntry___destruct(tphp_class_AnnotationEntry *self) {
    if (self) {
        self->data = NULL;
        self->type = (t_string){NULL, 0};
        self->name = (t_string){NULL, 0};
    }
}
