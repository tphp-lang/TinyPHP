#pragma once
// ============================================================
// object.h — COS 风格轻量对象系统
//
//   设计原则：
//   1. 对象头仅 8 字节 (class_id + refcount)，COS Any 风格
//   2. 继承 = struct 嵌套（父类字段在子类头部）
//   3. VTable 直接函数指针调用（AOT 最优）
//   4. 单继承（PHP 语义）
// ============================================================

#include <stdint.h>
#include <stdlib.h>
#include <stddef.h>
#include <string.h>

// ── Object header (16 bytes packed) ───────────────────────
//   cls:      direct pointer to class descriptor (no lookup needed)
//   refcount: -1=immortal, 0=stack/auto, >=1=N references
typedef struct _t_object {
    const struct _t_class *cls;
    int32_t                refcount;
} t_object;

// ── Class descriptor (per-class static data) ──────────────
typedef struct _t_class {
    const char            *name;             // debug
    const struct _t_class *parent;           // NULL for root
    uint32_t               instance_size;    // sizeof(struct)
    uint32_t               exception_offset; // offset of Exception sub-object (for throw/catch); 0 if N/A
    void                  *dtor;             // void (*dtor)(struct*)
    void                 **vtable;           // [N] function pointers
    uint32_t               vtable_len;       // number of slots
} t_class;

// ── Object lifecycle ──────────────────────────────────────

// ── Object freelist pool (LIFO, eliminates calloc/free for hot paths)
// OBJ_FREELIST_MAX 和 _obj_pool_slot 已在 types.h 中定义（供 tls.h 使用）
#include "compat/tls.h"  // TCC+Windows 时定义 _obj_freelist 访问宏

#if !TPHP_USE_WIN_TLS
static _Thread_local _obj_pool_slot _obj_freelist[OBJ_FREELIST_MAX];
static _Thread_local int _obj_freelist_count = 0;
#endif

/** Recycle object into pool instead of free() */
static inline void _obj_pool_put(void *obj, uint32_t sz) {
    if (unlikely(obj == NULL)) return;
    if (_obj_freelist_count >= OBJ_FREELIST_MAX) { free(obj); return; }
    _obj_freelist[_obj_freelist_count].ptr  = obj;
    _obj_freelist[_obj_freelist_count].size = sz;
    _obj_freelist_count++;
}

/** Try to get a recycled object of at least needSize (zeroed) */
static inline void* _obj_pool_get(uint32_t needSize) {
    for (int i = _obj_freelist_count - 1; i >= 0; i--) {
        if (_obj_freelist[i].size >= needSize) {
            void *ptr = _obj_freelist[i].ptr;
            _obj_freelist[i] = _obj_freelist[--_obj_freelist_count];
            memset(ptr, 0, (size_t)needSize);
            return ptr;
        }
    }
    return NULL;
}

/** Allocate raw object memory (zeroed), set class pointer and refcount=1 */
static inline void* tp_obj_alloc(const t_class *cls) {
    if (unlikely(cls == NULL || cls->instance_size == 0)) return NULL;
    uint32_t sz = cls->instance_size;
    // Try freelist first (eliminates calloc for recycled objects)
    t_object *obj = (t_object*)_obj_pool_get(sz);
    if (obj == NULL) {
        obj = (t_object*)calloc(1, (size_t)sz);
        if (unlikely(obj == NULL)) return NULL;
    }
    obj->cls = cls;
    obj->refcount = 1;
    return obj;
}

/** Retain */
static inline void* tp_obj_retain(void *obj) {
    if (obj != NULL) {
        t_object *o = (t_object*)obj;
        if (o->refcount > 0) o->refcount++;
    }
    return obj;
}

/** Release (decref → dtor → pool). Returns NULL. */
static inline void* tp_obj_release(void *obj) {
    if (obj == NULL) return NULL;
    t_object *o = (t_object*)obj;
    if (o->refcount <= 0) return NULL;
    if (--o->refcount > 0) return NULL;
    const t_class *cls = o->cls;
    if (cls != NULL && cls->dtor != NULL) {
        void (*dtor)(void*) = (void (*)(void*))cls->dtor;
        dtor(obj);
    }
    // Recycle into pool instead of free()
    _obj_pool_put(obj, cls ? cls->instance_size : 0);
    return NULL;
}

/** Get class descriptor from object (O(1)) */
static inline const t_class* tp_obj_class(void *obj) {
    return obj ? ((t_object*)obj)->cls : NULL;
}

/** type check with inheritance chain */
static inline int tp_obj_is_a(void *obj, const t_class *cls) {
    if (unlikely(obj == NULL || cls == NULL)) return 0;
    const t_class *oc = ((t_object*)obj)->cls;
    while (oc != NULL) {
        if (oc == cls) return 1;
        oc = oc->parent;
    }
    return 0;
}

/** Upcast via struct nesting offset (compile-time, zero cost) */
#define tp_upcast(obj, child, parent) \
    ((tphp_class_##parent*)((char*)(obj) + offsetof(tphp_class_##child, _parent)))

/** Downcast with runtime check */
#define tp_downcast(obj, child) \
    (tp_obj_is_a((obj), &_class_##child) ? (tphp_class_##child*)(obj) : NULL)
