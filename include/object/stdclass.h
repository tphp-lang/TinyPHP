#ifndef TINYPHP_OBJECT_STDCLASS_H
#define TINYPHP_OBJECT_STDCLASS_H
#pragma once
// ============================================================
// stdclass.h — PHP 原生 stdClass 类（动态属性容器）
//
//   PHP 原生设计参考：
//   - zend_object { gc, class_type, properties_table }
//   - stdClass 是所有类的默认基类（无预定义属性/方法）
//   - 动态属性存储在 properties HashTable（字符串键 → zval）
//
//   AOT 适配：
//   - 用 t_array 万能数组作为动态属性表（字符串键 → t_var）
//   - 复用 array.h 的哈希索引实现 O(1) 属性查找
//   - static inline 函数，每个 TU 独立副本（与 exception.h 一致）
//
//   依赖顺序：必须在 array.h 和 runtime.h 之后 include
//   （依赖 t_array API 和 tphp_rt_register）
// ============================================================

// ── stdClass struct (COS 对象头 + 动态属性表) ─────────────
typedef struct {
    t_object _obj;       // COS 对象头（cls + refcount + id）
    t_array* props;      // 动态属性表（字符串键，值为 t_var）
} tphp_class_stdClass;

// ── 前向声明（static inline: 每个 TU 独立副本，避免链接时重复定义）──
static inline tphp_class_stdClass* new_stdClass(void);
static inline void tphp_class_stdClass___destruct(tphp_class_stdClass* self);
static inline t_var   tphp_fn_stdclass_get(tphp_class_stdClass* self, t_string name);
static inline void    tphp_fn_stdclass_set(tphp_class_stdClass* self, t_string name, t_var val);
static inline t_bool  tphp_fn_stdclass_isset(tphp_class_stdClass* self, t_string name);
static inline void    tphp_fn_stdclass_unset(tphp_class_stdClass* self, t_string name);
static inline tphp_class_stdClass* tphp_fn_stdclass_clone(tphp_class_stdClass* self);
static inline int     tphp_fn_stdclass_count(tphp_class_stdClass* self);

// ── 类描述符 ──────────────────────────────────────────────
static void* _vtable_tphp_class_stdClass[1] = { NULL };
static const t_class _class_tphp_class_stdClass = {
    .name          = "stdClass",
    .parent        = NULL,
    .instance_size = sizeof(tphp_class_stdClass),
    .exception_offset = 0,
    .dtor          = (void*)tphp_class_stdClass___destruct,
    .vtable        = _vtable_tphp_class_stdClass,
    .vtable_len    = 0,
};

// ══════════════════════════════════════════════════════════
// 构造函数: new stdClass()
//   用 tp_obj_alloc 分配，初始化 props 空表，注册到运行时
// ══════════════════════════════════════════════════════════
static inline tphp_class_stdClass* new_stdClass(void) {
    tphp_class_stdClass* self = (tphp_class_stdClass*)tp_obj_alloc(&_class_tphp_class_stdClass);
    if (self == NULL) return NULL;
    self->props = tphp_fn_arr_create(8);
    tphp_rt_register((void*)self, 0);
    return self;
}

// ══════════════════════════════════════════════════════════
// 析构函数: __destruct
//   释放动态属性表（tphp_fn_arr_free 内部递归释放嵌套数组/对象）
// ══════════════════════════════════════════════════════════
static inline void tphp_class_stdClass___destruct(tphp_class_stdClass* self) {
    if (self == NULL) return;
    if (self->props != NULL) {
        tphp_fn_arr_free(self->props);
        self->props = NULL;
    }
}

// ══════════════════════════════════════════════════════════
// 属性读取: $obj->name
//   用 tphp_fn_arr_get_str 查找；不存在时返回 VAR_NULL()
//   （不触发 warning，warning 由编译器层处理或后续添加）
// ══════════════════════════════════════════════════════════
static inline t_var tphp_fn_stdclass_get(tphp_class_stdClass* self, t_string name) {
    if (self == NULL || self->props == NULL) return VAR_NULL();
    t_var *entry = tphp_fn_arr_get_str(self->props, name);
    if (entry == NULL) return VAR_NULL();
    return *entry;
}

// ══════════════════════════════════════════════════════════
// 属性赋值: $obj->name = $val
//   tphp_fn_arr_set_str 返回 t_array*（可能 realloc），需回写 self->props
// ══════════════════════════════════════════════════════════
static inline void tphp_fn_stdclass_set(tphp_class_stdClass* self, t_string name, t_var val) {
    if (self == NULL) return;
    self->props = tphp_fn_arr_set_str(self->props, name, val);
}

// ══════════════════════════════════════════════════════════
// 属性检查: isset($obj->name)
//   返回 t_bool，PHP 语义：键存在且值非 null 才返回 true
//   （与 PHP isset() 一致：null 值属性视为未设置）
// ══════════════════════════════════════════════════════════
static inline t_bool tphp_fn_stdclass_isset(tphp_class_stdClass* self, t_string name) {
    if (self == NULL || self->props == NULL) return false;
    t_var *entry = tphp_fn_arr_get_str(self->props, name);
    return entry != NULL && entry->type != TYPE_NULL;
}

// ══════════════════════════════════════════════════════════
// 属性删除: unset($obj->name)
//   调用 tphp_fn_arr_unset_str（内部释放值的引用 + 维护哈希索引）
// ══════════════════════════════════════════════════════════
static inline void tphp_fn_stdclass_unset(tphp_class_stdClass* self, t_string name) {
    if (self == NULL || self->props == NULL) return;
    tphp_fn_arr_unset_str(self->props, name);
}

// ══════════════════════════════════════════════════════════
// 克隆: clone $obj
//   new_stdClass() 创建新对象，遍历 self->props 复制每个属性到新对象
//   （tphp_fn_arr_set_str 内部会 retain 引用计数的值）
// ══════════════════════════════════════════════════════════
static inline tphp_class_stdClass* tphp_fn_stdclass_clone(tphp_class_stdClass* self) {
    if (self == NULL) return NULL;
    tphp_class_stdClass* clone = new_stdClass();
    if (clone == NULL) return NULL;
    if (self->props != NULL) {
        for (int i = 0; i < self->props->length; i++) {
            if (self->props->entries[i].key.type == TYPE_STRING) {
                clone->props = tphp_fn_arr_set_str(clone->props,
                    self->props->entries[i].key.value._string,
                    self->props->entries[i].val);
            }
        }
    }
    return clone;
}

// ══════════════════════════════════════════════════════════
// 属性计数: count(get_object_vars($obj))
//   返回 tphp_fn_arr_count(self->props)
// ══════════════════════════════════════════════════════════
static inline int tphp_fn_stdclass_count(tphp_class_stdClass* self) {
    if (self == NULL || self->props == NULL) return 0;
    return tphp_fn_arr_count(self->props);
}

// ══════════════════════════════════════════════════════════
// 从数组创建 stdClass: (object) $array
//   遍历数组所有键值对，复制为 stdClass 动态属性
//   仅字符串键被复制（整数键在 PHP 中会被转为字符串 "0","1"...）
// ══════════════════════════════════════════════════════════
static inline tphp_class_stdClass* tphp_fn_stdclass_from_array(t_array* arr) {
    tphp_class_stdClass* obj = new_stdClass();
    if (obj == NULL || arr == NULL) return obj;
    for (int i = 0; i < arr->length; i++) {
        t_var* key = &arr->entries[i].key;
        t_var* val = &arr->entries[i].val;
        if (key->type == TYPE_STRING) {
            obj->props = tphp_fn_arr_set_str(obj->props, key->value._string, *val);
        } else if (key->type == TYPE_INT) {
            // 整数键转字符串键（PHP 兼容行为）
            t_string ks = tphp_rt_str_from_int(key->value._int);
            obj->props = tphp_fn_arr_set_str(obj->props, ks, *val);
        }
    }
    return obj;
}

// ══════════════════════════════════════════════════════════
// stdClass 转数组: (array) $stdClass / get_object_vars($obj)
//   创建新 t_array，复制 stdClass 所有动态属性
// ══════════════════════════════════════════════════════════
static inline t_array* tphp_fn_stdclass_to_array(tphp_class_stdClass* self) {
    if (self == NULL || self->props == NULL) return tphp_fn_arr_create(0);
    t_array* arr = tphp_fn_arr_create(self->props->length);
    if (arr == NULL) return NULL;
    for (int i = 0; i < self->props->length; i++) {
        arr = tphp_fn_arr_set_str(arr,
            self->props->entries[i].key.value._string,
            self->props->entries[i].val);
    }
    return arr;
}

#endif /* TINYPHP_OBJECT_STDCLASS_H */
