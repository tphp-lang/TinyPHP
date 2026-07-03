#ifndef TINYPHP_OS_FILE_OBJ_H
#define TINYPHP_OS_FILE_OBJ_H
#pragma once
// ============================================================
// file_obj.h — File 类（模拟 PHP fopen/fclose resource，性能优先）
//
//   PHP 原生设计参考：
//   - PHP: fopen() → resource(1) of type (stream)
//   - PHP: fclose($fp) → 关闭资源
//
//   性能优化：
//   - read/write/eof 直接内联，无间接调用
//   - close 幂等，fp==NULL 快速返回
//   - _ensure_file_rsrc_type 使用 static 局部变量，零开销初始化
//   - 资源 ID 复用池，消除 ID 耗尽问题
//
//   内存安全：
//   - fp==NULL 时所有方法返回安全默认值
//   - close() 幂等，重复调用安全
//   - __destruct 清空 fp + ptr，防双重释放
// ============================================================

#include <stdio.h>
#include <string.h>
#include "../types.h"
#include "../runtime.h"
#include "../object/object.h"
#include "../object/resource.h"

// ── File struct（模拟 PHP stream resource）───────────────
typedef struct {
    t_object _obj;             // cos_object 头
    tphp_class_Resource _parent;  // Resource 继承
    FILE *fp;                  // 文件句柄
} tphp_class_File;

// ── 前向声明 ──────────────────────────────────────────────
void tphp_class_File___construct(tphp_class_File* self, t_string path, t_string mode);
void tphp_class_File___destruct(tphp_class_File* self);
static inline t_int    tphp_class_File_getType(tphp_class_File* self);
static inline t_string tphp_class_File_read(tphp_class_File* self, t_int len);
static inline t_int    tphp_class_File_write(tphp_class_File* self, t_string data);
static inline t_bool   tphp_class_File_eof(tphp_class_File* self);
static inline void     tphp_class_File_close(tphp_class_File* self);
static inline t_bool   tphp_class_File_isOpen(tphp_class_File* self);
tphp_class_File* new_tphp_class_File(t_string path, t_string mode);

// ── 类描述符 ──────────────────────────────────────────────
static void* _vtable_tphp_class_File[1] = { NULL };
static const t_class _class_tphp_class_File = {
    .name          = "File",
    .parent        = &_class_tphp_class_Resource,
    .instance_size = sizeof(tphp_class_File),
    .dtor          = (void*)tphp_class_File___destruct,
    .vtable        = _vtable_tphp_class_File,
    .vtable_len    = 0,
};

// ── File 析构回调（fclose FILE*）─────────────────────────
//   仅由 tphp_rt_free_all_resources 调用（异常路径）
//   正常路径由 __destruct 处理（RAII）
static void _file_dtor(void *ptr) {
    if (ptr != NULL) fclose((FILE*)ptr);
}

// ── 资源类型 ID（static 局部，零开销初始化）──────────────
static inline t_int _file_rsrc_type_id(void) {
    static t_int id = -1;
    if (unlikely(id < 0)) {
        id = tphp_rt_register_resource_type(_file_dtor, "stream");
    }
    return id;
}

// ══════════════════════════════════════════════════════════
// File 方法实现（热路径全部内联）
// ══════════════════════════════════════════════════════════

// ── getType — 返回资源类型 ID ────────────────────────────
static inline t_int tphp_class_File_getType(tphp_class_File* self) {
    if (unlikely(self == NULL)) return RSRC_TYPE_UNKNOWN;
    return self->_parent.type;
}

// ── new_tphp_class_File — fopen + 注册资源 ────────────────
tphp_class_File* new_tphp_class_File(t_string path, t_string mode) {
    tphp_class_File* self = (tphp_class_File*)tp_obj_alloc(&_class_tphp_class_File);
    if (unlikely(self == NULL)) return NULL;
    tphp_class_File___construct(self, path, mode);
    return self;
}

// ── __construct — fopen + 资源注册 ───────────────────────
void tphp_class_File___construct(tphp_class_File* self, t_string path, t_string mode) {
    if (self == NULL) return;
    self->fp = NULL;
    self->_parent.handle = -1;
    self->_parent.type = _file_rsrc_type_id();
    self->_parent.ptr  = NULL;

    if (unlikely(STR_PTR(path) == NULL || path.length <= 0)) return;

    // fopen 需要 null-terminated 字符串
    char pbuf[4096], mbuf[8];
    int plen = path.length < (int)sizeof(pbuf) - 1 ? path.length : (int)sizeof(pbuf) - 1;
    memcpy(pbuf, STR_PTR(path), (size_t)plen);
    pbuf[plen] = '\0';
    int mlen = mode.length < (int)sizeof(mbuf) - 1 ? mode.length : (int)sizeof(mbuf) - 1;
    memcpy(mbuf, STR_PTR(mode), (size_t)mlen);
    mbuf[mlen] = '\0';

    self->fp = fopen(pbuf, mbuf);
    self->_parent.ptr = self->fp;

    // 成功打开才注册到资源列表
    if (likely(self->fp != NULL)) {
        self->_parent.handle = tphp_rt_resource_insert((tphp_class_Resource*)self);
    }
}

// ── __destruct — RAII fclose + 资源列表清理 ──────────────
void tphp_class_File___destruct(tphp_class_File* self) {
    if (self == NULL) return;
    // 1. 关闭文件句柄
    if (self->fp != NULL) {
        fclose(self->fp);
        self->fp = NULL;
    }
    // 2. 从资源列表移除（防悬空指针）
    t_int h = self->_parent.handle;
    self->_parent.handle = -1;
    self->_parent.ptr = NULL;
    if (h >= 0 && h < RSRC_LIST_MAX && _rsrc_list[h] == (tphp_class_Resource*)self) {
        _rsrc_list[h] = NULL;
        if (_rsrc_free_top < RSRC_LIST_MAX) {
            _rsrc_free_stack[_rsrc_free_top++] = h;
        }
    }
}

// ── read — 读 len 字节 ───────────────────────────────────
static inline t_string tphp_class_File_read(tphp_class_File* self, t_int len) {
    if (unlikely(self == NULL || self->fp == NULL || len <= 0)) {
        return (t_string){NULL, 0};
    }
    char *buf = str_pool_alloc((int)len);
    if (unlikely(buf == NULL)) return (t_string){NULL, 0};
    size_t n = fread(buf, 1, (size_t)len, self->fp);
    buf[n] = '\0';
    return (t_string){buf, (int)n};
}

// ── write — 写入字符串 ──────────────────────────────────
static inline t_int tphp_class_File_write(tphp_class_File* self, t_string data) {
    if (unlikely(self == NULL || self->fp == NULL)) return 0;
    if (unlikely(STR_PTR(data) == NULL || data.length <= 0)) return 0;
    return (t_int)fwrite(STR_PTR(data), 1, (size_t)data.length, self->fp);
}

// ── eof — 是否到达文件尾 ────────────────────────────────
static inline t_bool tphp_class_File_eof(tphp_class_File* self) {
    if (unlikely(self == NULL || self->fp == NULL)) return true;
    return feof(self->fp) != 0;
}

// ── close — 幂等关闭 ────────────────────────────────────
static inline void tphp_class_File_close(tphp_class_File* self) {
    if (self == NULL || self->fp == NULL) return;
    fclose(self->fp);
    self->fp = NULL;
    self->_parent.ptr = NULL;
}

// ── isOpen — 文件是否打开 ───────────────────────────────
static inline t_bool tphp_class_File_isOpen(tphp_class_File* self) {
    if (self == NULL) return false;
    return self->fp != NULL;
}

#endif /* TINYPHP_OS_FILE_OBJ_H */
