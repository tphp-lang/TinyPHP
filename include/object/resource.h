#ifndef TINYPHP_OBJECT_RESOURCE_H
#define TINYPHP_OBJECT_RESOURCE_H
#pragma once
// ============================================================
// resource.h — PHP 资源类型模拟（AOT 兼容版，性能优先）
//
//   PHP 原生设计参考：
//   - zend_resource { gc, handle, type, ptr }
//   - EG(regular_list): HashTable<int, zend_resource*>
//   - zend_register_list_destructors_ex(): 注册析构回调
//   - zend_list_insert(): 插入资源并返回 ID
//   - zend_list_delete(): 递减引用计数，归零时析构
//
//   AOT 适配 + 性能优化：
//   - 资源类型编译期注册，O(1) 查表
//   - 资源列表使用 LIFO 空闲槽复用池（消除 ID 耗尽问题）
//   - 内联热路径，likely/unlikely 提示分支预测
//   - 析构回调直接调用，无间接查找
//
//   内存安全：
//   - 所有 public API 都有 NULL 检查
//   - 析构前清空 ptr，防双重释放
//   - 异常路径 tphp_rt_free_all_resources() 释放所有
// ============================================================

#include "object.h"

// ── 资源类型常量 ──────────────────────────────────────────
#define IS_RSRC           15   // PHP IS_RESOURCE = 15
#define RSRC_TYPE_UNKNOWN  0
#define RSRC_TYPE_FILE     1
#define RSRC_TYPE_SOCKET   2
#define RSRC_TYPE_DB       3
#define RSRC_TYPE_PROCESS  4
#define RSRC_TYPE_DIR      5

// CodeGenerator 需要 TPHP_CONST_ 前缀
#define TPHP_CONST_IS_RSRC           IS_RSRC
#define TPHP_CONST_RSRC_TYPE_UNKNOWN  RSRC_TYPE_UNKNOWN
#define TPHP_CONST_RSRC_TYPE_FILE     RSRC_TYPE_FILE
#define TPHP_CONST_RSRC_TYPE_SOCKET   RSRC_TYPE_SOCKET
#define TPHP_CONST_RSRC_TYPE_DB       RSRC_TYPE_DB
#define TPHP_CONST_RSRC_TYPE_PROCESS  RSRC_TYPE_PROCESS
#define TPHP_CONST_RSRC_TYPE_DIR      RSRC_TYPE_DIR

// ── 析构回调类型 ──────────────────────────────────────────
typedef void (*tphp_rsrc_dtor_func_t)(void *ptr);

// ── 资源类型注册条目 ──────────────────────────────────────
typedef struct {
    tphp_rsrc_dtor_func_t dtor;
    const char           *name;
} tphp_rsrc_type_entry;

// ── Resource struct（模拟 zend_resource）───────────────────
typedef struct {
    t_object _obj;
    t_int    handle;   // 资源 ID
    t_int    type;     // 资源类型 ID
    void    *ptr;      // 底层资源指针
} tphp_class_Resource;

// ── 前向声明 ──────────────────────────────────────────────
void tphp_class_Resource___construct(tphp_class_Resource* self);
void tphp_class_Resource___destruct(tphp_class_Resource* self);
tphp_class_Resource* new_tphp_class_Resource(void);
static inline t_int tphp_class_Resource_getType(tphp_class_Resource* self);

// ── 资源管理 API ──────────────────────────────────────────
static inline t_int  tphp_rt_resource_insert(tphp_class_Resource* res);
static inline void   tphp_rt_resource_delete(t_int handle);
static inline tphp_class_Resource* tphp_rt_resource_fetch(t_int handle);
static inline t_bool tphp_rt_resource_is_valid(t_int handle);
t_int  tphp_rt_register_resource_type(tphp_rsrc_dtor_func_t dtor, const char *name);
void   tphp_rt_free_all_resources(void);

// ── 类描述符 ──────────────────────────────────────────────
static void* _vtable_tphp_class_Resource[1] = { NULL };
static const t_class _class_tphp_class_Resource = {
    .name          = "Resource",
    .parent        = NULL,
    .instance_size = sizeof(tphp_class_Resource),
    .dtor          = (void*)tphp_class_Resource___destruct,
    .vtable        = _vtable_tphp_class_Resource,
    .vtable_len    = 0,
};

// ══════════════════════════════════════════════════════════
// 资源类型注册表（编译期填充，最多 64 种）
// ══════════════════════════════════════════════════════════
#define RSRC_TYPE_MAX 64
static tphp_rsrc_type_entry _rsrc_types[RSRC_TYPE_MAX];
static int _rsrc_type_count = 0;

t_int tphp_rt_register_resource_type(tphp_rsrc_dtor_func_t dtor, const char *name) {
    if (unlikely(_rsrc_type_count >= RSRC_TYPE_MAX)) return -1;
    int id = _rsrc_type_count++;
    _rsrc_types[id].dtor  = dtor;
    _rsrc_types[id].name  = name;
    return id;
}

// ══════════════════════════════════════════════════════════
// 资源列表（LIFO 空闲槽复用，最多 2048 个活跃资源）
// ══════════════════════════════════════════════════════════
#define RSRC_LIST_MAX 2048
static tphp_class_Resource* _rsrc_list[RSRC_LIST_MAX];

// 空闲槽栈（LIFO，复用已释放的 ID）
static t_int _rsrc_free_stack[RSRC_LIST_MAX];
static int   _rsrc_free_top = 0;
static int   _rsrc_active_count = 0;  // 当前活跃资源数

// ── 插入资源（O(1)，模拟 zend_list_insert）───────────────
static inline t_int tphp_rt_resource_insert(tphp_class_Resource* res) {
    if (unlikely(res == NULL)) return -1;

    t_int id;
    if (likely(_rsrc_free_top > 0)) {
        // 从空闲栈弹出（复用已释放 ID）
        id = _rsrc_free_stack[--_rsrc_free_top];
    } else {
        // 使用新 ID
        if (unlikely(_rsrc_active_count >= RSRC_LIST_MAX)) return -1;
        id = _rsrc_active_count++;
    }

    _rsrc_list[id] = res;
    res->handle = id;
    return id;
}

// ── 获取资源（O(1)，模拟 zend_list_fetch）────────────────
static inline tphp_class_Resource* tphp_rt_resource_fetch(t_int handle) {
    if (unlikely(handle < 0 || handle >= RSRC_LIST_MAX)) return NULL;
    return _rsrc_list[handle];
}

// ── 资源是否有效 ──────────────────────────────────────────
static inline t_bool tphp_rt_resource_is_valid(t_int handle) {
    if (unlikely(handle < 0 || handle >= RSRC_LIST_MAX)) return false;
    return _rsrc_list[handle] != NULL;
}

// ── 删除资源（O(1)，模拟 zend_list_delete）───────────────
//   析构底层资源，将槽位压入空闲栈复用
static inline void tphp_rt_resource_delete(t_int handle) {
    if (unlikely(handle < 0 || handle >= RSRC_LIST_MAX)) return;
    tphp_class_Resource *res = _rsrc_list[handle];
    if (res == NULL) return;

    // 调用类型析构回调（释放底层资源）
    if (likely(res->type >= 0 && res->type < _rsrc_type_count)) {
        tphp_rsrc_dtor_func_t dtor = _rsrc_types[res->type].dtor;
        if (dtor != NULL && res->ptr != NULL) {
            dtor(res->ptr);
            res->ptr = NULL;
        }
    }

    // 清空槽位，压入空闲栈
    _rsrc_list[handle] = NULL;
    res->handle = -1;

    // 防止空闲栈溢出
    if (likely(_rsrc_free_top < RSRC_LIST_MAX)) {
        _rsrc_free_stack[_rsrc_free_top++] = handle;
    }
}

// ── 释放所有资源（异常路径调用）───────────────────────────
void tphp_rt_free_all_resources(void) {
    for (int i = 0; i < RSRC_LIST_MAX; i++) {
        tphp_class_Resource *res = _rsrc_list[i];
        if (res != NULL) {
            // 调用类型析构回调
            if (res->type >= 0 && res->type < _rsrc_type_count) {
                tphp_rsrc_dtor_func_t dtor = _rsrc_types[res->type].dtor;
                if (dtor != NULL && res->ptr != NULL) {
                    dtor(res->ptr);
                    res->ptr = NULL;
                }
            }
            // 释放对象（不调用 _destruct，避免双重释放）
            // 使用 cls->instance_size 获取正确的对象大小（可能是 File 等子类）
            uint32_t obj_size = (res->_obj.cls != NULL) ? res->_obj.cls->instance_size : (uint32_t)sizeof(tphp_class_Resource);
            _obj_pool_put(res, obj_size);
            _rsrc_list[i] = NULL;
        }
    }
    _rsrc_free_top = 0;
    _rsrc_active_count = 0;
}

// ══════════════════════════════════════════════════════════
// Resource 方法实现
// ══════════════════════════════════════════════════════════

tphp_class_Resource* new_tphp_class_Resource(void) {
    tphp_class_Resource* self = (tphp_class_Resource*)tp_obj_alloc(&_class_tphp_class_Resource);
    if (self != NULL) {
        self->handle = -1;
        self->type = RSRC_TYPE_UNKNOWN;
        self->ptr = NULL;
    }
    return self;
}

void tphp_class_Resource___construct(tphp_class_Resource* self) {
    if (self == NULL) return;
    self->handle = -1;
    self->type = RSRC_TYPE_UNKNOWN;
    self->ptr = NULL;
}

// __destruct — 释放底层资源 + 从列表移除
void tphp_class_Resource___destruct(tphp_class_Resource* self) {
    if (self == NULL) return;

    // 调用类型析构回调
    if (self->type >= 0 && self->type < _rsrc_type_count) {
        tphp_rsrc_dtor_func_t dtor = _rsrc_types[self->type].dtor;
        if (dtor != NULL && self->ptr != NULL) {
            dtor(self->ptr);
            self->ptr = NULL;
        }
    }

    // 从列表移除（防双重释放：先清列表再置 NULL）
    t_int h = self->handle;
    self->handle = -1;
    if (h >= 0 && h < RSRC_LIST_MAX && _rsrc_list[h] == self) {
        _rsrc_list[h] = NULL;
        if (_rsrc_free_top < RSRC_LIST_MAX) {
            _rsrc_free_stack[_rsrc_free_top++] = h;
        }
    }
}

// getType — 返回资源类型 ID
static inline t_int tphp_class_Resource_getType(tphp_class_Resource* self) {
    if (self == NULL) return RSRC_TYPE_UNKNOWN;
    return self->type;
}

// ══════════════════════════════════════════════════════════
// is_resource — 检查变量是否为 Resource 类型
//   模拟 PHP: is_resource($var)
//   检查 t_var 是否为 TYPE_OBJECT 且类继承自 Resource
// ══════════════════════════════════════════════════════════
static inline t_bool tphp_fn_is_resource(t_var v) {
    if (v.type != TYPE_OBJECT) return false;
    return tp_obj_is_a(v.value._object, &_class_tphp_class_Resource);
}

#endif /* TINYPHP_OBJECT_RESOURCE_H */
