#pragma once
// ============================================================
// zip.h — ZIP 归档读写（内置功能，依赖系统 zlib）
// 对应 PHP ext/zip，手写 ZIP 容器格式，DEFLATE 压缩复用 zlib
//
// 设计说明：
//   - 不依赖 libzip，手写 ZIP 本地文件头/中央目录/EOCD
//   - 压缩/解压复用 zlib 的 deflate/inflate（raw DEFLATE, windowBits=-15）
//   - CRC32 复用 zlib 的 crc32()
//   - ZipArchive 作为 Resource 子类，内部状态通过 ptr 指向
//   - 读取模式：整文件载入内存，解析中央目录
//   - 写入模式：内存缓冲区构建，zip_close 时写入文件
//   - 错误处理统一 tp_throw_ex（不返回 false，符合 AOT 单返回类型契约）
// ============================================================

#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <time.h>
#include "zlib.h"   // zlib 接口（TCC 手动声明 / 非 TCC #include <zlib.h>）
#include "types.h"
#include "val.h"
#include "array.h"
#include "object/resource.h"
#include "object/exception.h"

// 前向声明
static inline char* str_pool_alloc(int len);

// ── ZIP 常量（CodeGenerator 需要 TPHP_CONST_ 前缀）──
#define TPHP_CONST_ZIP_CREATE          1    // 创建(若存在则失败)
#define TPHP_CONST_ZIP_EXCL            2    // 排他创建
#define TPHP_CONST_ZIP_CHECKCONS       4    // 检查一致性
#define TPHP_CONST_ZIP_TRUNCATE        8    // 截断(若存在则覆盖)
#define TPHP_CONST_ZIP_RDONLY          16   // 只读
#define TPHP_CONST_ZIP_FL_OVERWRITE    1    // 覆盖现有文件
#define TPHP_CONST_ZIP_FL_NOCASE       2    // 不区分大小写
#define TPHP_CONST_ZIP_FL_NODIR        4    // 不为目录创建条目
#define TPHP_CONST_ZIP_FL_COMPRESSED   8    // 读取压缩数据
#define TPHP_CONST_ZIP_FL_UNCHANGED    16   // 使用原始数据
#define TPHP_CONST_ZIP_CM_DEFAULT      (-1) // 默认压缩方法
#define TPHP_CONST_ZIP_CM_STORE        0    // 不压缩
#define TPHP_CONST_ZIP_CM_DEFLATE      8    // DEFLATE 压缩

// ── ZIP 签名 ──
#define _ZIP_LOCAL_SIG      0x04034b50u   // PK\x03\x04
#define _ZIP_CENTRAL_SIG    0x02014b50u   // PK\x01\x02
#define _ZIP_EOCD_SIG       0x06054b50u   // PK\x05\x06

// ══════════════════════════════════════════════════════════
// 内部数据结构
// ══════════════════════════════════════════════════════════

// ZIP 条目信息（读取模式解析中央目录得到）
typedef struct {
    char  *name;          // 文件名（malloc，zip_close 释放）
    int    name_len;
    t_int  index;
    long   uncomp_size;   // 原始大小
    long   comp_size;     // 压缩后大小
    short  comp_method;   // 0=store, 8=deflate
    long   local_offset;  // 本地文件头偏移
    long   mtime;         // 修改时间 (Unix timestamp)
} _zip_entry;

// 写入模式的中央目录条目
typedef struct {
    char  *name;          // 文件名（malloc）
    int    name_len;
    long   uncomp_size;
    long   comp_size;
    short  comp_method;
    unsigned long crc;
    long   local_offset;
    long   mtime;
} _zip_central_entry;

// ZIP 内部状态（通过 Resource->ptr 指向）
typedef struct {
    int   mode;           // 0=read, 1=write
    char *filepath;       // 文件路径（malloc）

    // ── 读取模式 ──
    char       *filedata;  // 整个文件内容（malloc）
    long        filelen;
    _zip_entry *entries;   // 条目数组（malloc）
    int         entry_count;
    int         current_entry; // 当前打开的条目索引（-1=未打开）

    // ── 写入模式 ──
    char              *outbuf;     // 输出缓冲区（malloc）
    long               outlen;
    long               outcap;
    _zip_central_entry *central;   // 中央目录条目（malloc）
    int                central_count;
    int                central_cap;

    // ── 错误信息 ──
    char  errmsg[256];
} _tphp_zip_data;

// ── 资源类型 ID（懒初始化）──
static t_int _tphp_zip_rsrc_type = -1;

// ── ZIP 资源析构回调 ──
static void _tphp_zip_dtor(void* ptr) {
    if (ptr == NULL) return;
    _tphp_zip_data* z = (_tphp_zip_data*)ptr;

    // 读取模式：释放条目
    if (z->entries != NULL) {
        for (int i = 0; i < z->entry_count; i++) {
            if (z->entries[i].name != NULL) free(z->entries[i].name);
        }
        free(z->entries);
    }
    if (z->filedata != NULL) free(z->filedata);

    // 写入模式：释放缓冲区和中央目录
    if (z->outbuf != NULL) free(z->outbuf);
    if (z->central != NULL) {
        for (int i = 0; i < z->central_count; i++) {
            if (z->central[i].name != NULL) free(z->central[i].name);
        }
        free(z->central);
    }
    if (z->filepath != NULL) free(z->filepath);

    free(z);
}

// 懒初始化资源类型
static inline void _tphp_zip_init(void) {
    if (_tphp_zip_rsrc_type < 0) {
        _tphp_zip_rsrc_type = tphp_rt_register_resource_type(_tphp_zip_dtor, "zip");
    }
}

// ══════════════════════════════════════════════════════════
// 辅助函数
// ══════════════════════════════════════════════════════════

// 从 const char* 创建 t_string（走 str_pool_alloc）
static inline t_string _zip_mkstr(const char* s, int len) {
    if (s == NULL || len <= 0) return (t_string){0};
    char* buf = str_pool_alloc(len);
    if (buf == NULL) return (t_string){0};
    memcpy(buf, s, (size_t)len);
    return (t_string){buf, len};
}

// 设置错误信息
static inline void _zip_seterr(_tphp_zip_data* z, const char* msg) {
    if (z == NULL || msg == NULL) return;
    int len = (int)strlen(msg);
    if (len > (int)sizeof(z->errmsg) - 1) len = (int)sizeof(z->errmsg) - 1;
    memcpy(z->errmsg, msg, (size_t)len);
    z->errmsg[len] = '\0';
}

// Unix 时间戳 → DOS 时间/日期
static inline void _zip_unix_to_dos(long unix_time, unsigned short* dos_time, unsigned short* dos_date) {
    time_t t = (time_t)unix_time;
    struct tm *lt = localtime(&t);
    if (lt == NULL) {
        *dos_time = 0; *dos_date = (1 << 5) | 1;  // 1980-01-01
        return;
    }
    int year = lt->tm_year + 1900;
    if (year < 1980) year = 1980;
    *dos_time = (unsigned short)((lt->tm_hour << 11) | (lt->tm_min << 5) | (lt->tm_sec / 2));
    *dos_date = (unsigned short)(((year - 1980) << 9) | ((lt->tm_mon + 1) << 5) | lt->tm_mday);
}

// DOS 时间/日期 → Unix 时间戳
static inline long _zip_dos_to_unix(unsigned short dos_time, unsigned short dos_date) {
    struct tm t;
    memset(&t, 0, sizeof(t));
    t.tm_year = ((dos_date >> 9) & 0x7F) + 1980 - 1900;
    t.tm_mon  = ((dos_date >> 5) & 0x0F) - 1;
    t.tm_mday = dos_date & 0x1F;
    t.tm_hour = (dos_time >> 11) & 0x1F;
    t.tm_min  = (dos_time >> 5) & 0x3F;
    t.tm_sec  = (dos_time & 0x1F) * 2;
    return (long)mktime(&t);
}

// 小端读取
static inline unsigned int _zip_le32(const unsigned char* p) {
    return (unsigned int)p[0] | ((unsigned int)p[1] << 8) | ((unsigned int)p[2] << 16) | ((unsigned int)p[3] << 24);
}
static inline unsigned short _zip_le16(const unsigned char* p) {
    return (unsigned short)((unsigned short)p[0] | ((unsigned short)p[1] << 8));
}

// 小端写入
static inline void _zip_put_le32(unsigned char* p, unsigned int v) {
    p[0] = (unsigned char)(v & 0xFF);
    p[1] = (unsigned char)((v >> 8) & 0xFF);
    p[2] = (unsigned char)((v >> 16) & 0xFF);
    p[3] = (unsigned char)((v >> 24) & 0xFF);
}
static inline void _zip_put_le16(unsigned char* p, unsigned short v) {
    p[0] = (unsigned char)(v & 0xFF);
    p[1] = (unsigned char)((v >> 8) & 0xFF);
}

// 写入缓冲区确保容量
static inline int _zip_buf_ensure(_tphp_zip_data* z, long need) {
    if (z->outlen + need <= z->outcap) return 1;
    long newcap = z->outcap;
    while (newcap < z->outlen + need) newcap *= 2;
    if (newcap > 256 * 1024 * 1024) newcap = 256 * 1024 * 1024;  // 256MB 上限
    char* newbuf = (char*)realloc(z->outbuf, (size_t)newcap);
    if (newbuf == NULL) return 0;
    z->outbuf = newbuf;
    z->outcap = newcap;
    return 1;
}

// ══════════════════════════════════════════════════════════
// 读取模式：解析 ZIP 文件
// ══════════════════════════════════════════════════════════

// 从文件末尾搜索 EOCD 签名
static inline long _zip_find_eocd(const unsigned char* data, long len) {
    if (len < 22) return -1;
    // EOCD 最小 22 字节，最多有 65535 字节注释
    long start = len - 22;
    long min = len - 65557;
    if (min < 0) min = 0;
    for (long i = start; i >= min; i--) {
        if (data[i] == 'P' && data[i+1] == 'K' &&
            data[i+2] == 0x05 && data[i+3] == 0x06) {
            return i;
        }
    }
    return -1;
}

// 解析中央目录
static inline int _zip_parse_central(_tphp_zip_data* z) {
    const unsigned char* data = (const unsigned char*)z->filedata;
    long len = z->filelen;

    long eocd_off = _zip_find_eocd(data, len);
    if (eocd_off < 0) {
        _zip_seterr(z, "zip: EOCD record not found");
        return 0;
    }

    // EOCD: offset 16 = central dir offset, offset 10 = total entries
    int total = _zip_le16(data + eocd_off + 10);
    long cd_off = (long)_zip_le32(data + eocd_off + 16);

    if (total <= 0) {
        z->entry_count = 0;
        return 1;
    }

    z->entries = (_zip_entry*)calloc((size_t)total, sizeof(_zip_entry));
    if (z->entries == NULL) {
        _zip_seterr(z, "zip: out of memory (entries)");
        return 0;
    }

    long pos = cd_off;
    for (int i = 0; i < total; i++) {
        if (pos + 46 > len) {
            _zip_seterr(z, "zip: central directory truncated");
            return 0;
        }
        if (_zip_le32(data + pos) != _ZIP_CENTRAL_SIG) {
            _zip_seterr(z, "zip: bad central directory signature");
            return 0;
        }

        short comp_method = (short)_zip_le16(data + pos + 10);
        unsigned short dos_time = _zip_le16(data + pos + 12);
        unsigned short dos_date = _zip_le16(data + pos + 14);
        unsigned int crc = _zip_le32(data + pos + 16);
        long comp_size = (long)_zip_le32(data + pos + 20);
        long uncomp_size = (long)_zip_le32(data + pos + 24);
        int name_len = _zip_le16(data + pos + 28);
        int extra_len = _zip_le16(data + pos + 30);
        int comment_len = _zip_le16(data + pos + 32);
        long local_off = (long)_zip_le32(data + pos + 42);

        if (pos + 46 + name_len > len) {
            _zip_seterr(z, "zip: filename truncated");
            return 0;
        }

        // 复制文件名
        char* name = (char*)malloc((size_t)name_len + 1);
        if (name == NULL) {
            _zip_seterr(z, "zip: out of memory (name)");
            return 0;
        }
        memcpy(name, data + pos + 46, (size_t)name_len);
        name[name_len] = '\0';

        z->entries[i].name = name;
        z->entries[i].name_len = name_len;
        z->entries[i].index = i;
        z->entries[i].uncomp_size = uncomp_size;
        z->entries[i].comp_size = comp_size;
        z->entries[i].comp_method = comp_method;
        z->entries[i].local_offset = local_off;
        z->entries[i].mtime = _zip_dos_to_unix(dos_time, dos_date);

        pos += 46 + name_len + extra_len + comment_len;
    }

    z->entry_count = total;
    return 1;
}

// ══════════════════════════════════════════════════════════
// 公共 API
// ══════════════════════════════════════════════════════════

/**
 * zip_open(string $filename, int $flags = 0): Resource
 *
 * 打开 ZIP 文件。
 *   无 flags: 打开已有文件（读取模式）
 *   ZIP_CREATE: 创建新文件（写入模式，不存在时创建）
 *   ZIP_TRUNCATE: 截断已有文件（写入模式）
 *   ZIP_RDONLY: 只读
 */
static inline tphp_class_Resource* tphp_fn_zip_open(t_string filename, t_int flags) {
    _tphp_zip_init();

    if (STR_PTR(filename) == NULL || filename.length <= 0) {
        tp_throw_ex(new_tphp_class_Exception(STR_LIT("zip_open(): empty filename")));
        return NULL;
    }

    // 路径转 C 字符串
    char pathbuf[4096];
    int plen = filename.length < (int)sizeof(pathbuf) - 1 ? filename.length : (int)sizeof(pathbuf) - 1;
    memcpy(pathbuf, STR_PTR(filename), (size_t)plen);
    pathbuf[plen] = '\0';

    // 判断模式
    int is_write = (flags & TPHP_CONST_ZIP_CREATE) || (flags & TPHP_CONST_ZIP_TRUNCATE);

    _tphp_zip_data* z = (_tphp_zip_data*)calloc(1, sizeof(_tphp_zip_data));
    if (z == NULL) {
        tp_throw_ex(new_tphp_class_Exception(STR_LIT("zip_open(): out of memory")));
        return NULL;
    }
    z->mode = is_write ? 1 : 0;
    z->current_entry = -1;
    z->errmsg[0] = '\0';

    // 复制文件路径
    z->filepath = (char*)malloc((size_t)plen + 1);
    if (z->filepath == NULL) {
        free(z);
        tp_throw_ex(new_tphp_class_Exception(STR_LIT("zip_open(): out of memory (path)")));
        return NULL;
    }
    memcpy(z->filepath, pathbuf, (size_t)plen + 1);

    if (!is_write) {
        // ── 读取模式：载入整个文件 ──
        FILE* fp = fopen(pathbuf, "rb");
        if (fp == NULL) {
            free(z->filepath);
            free(z);
            tp_throw_ex(new_tphp_class_Exception(STR_LIT("zip_open(): file not found")));
            return NULL;
        }
        fseek(fp, 0, SEEK_END);
        long fsize = ftell(fp);
        fseek(fp, 0, SEEK_SET);
        if (fsize <= 0) {
            fclose(fp);
            free(z->filepath);
            free(z);
            tp_throw_ex(new_tphp_class_Exception(STR_LIT("zip_open(): empty file")));
            return NULL;
        }
        z->filedata = (char*)malloc((size_t)fsize);
        if (z->filedata == NULL) {
            fclose(fp);
            free(z->filepath);
            free(z);
            tp_throw_ex(new_tphp_class_Exception(STR_LIT("zip_open(): out of memory (filedata)")));
            return NULL;
        }
        size_t nread = fread(z->filedata, 1, (size_t)fsize, fp);
        fclose(fp);
        z->filelen = (long)nread;

        if (!_zip_parse_central(z)) {
            _tphp_zip_dtor(z);
            tp_throw_ex(new_tphp_class_Exception(STR_LIT("zip_open(): failed to parse central directory")));
            return NULL;
        }
    } else {
        // ── 写入模式：初始化缓冲区 ──
        z->outcap = 4096;
        z->outbuf = (char*)malloc((size_t)z->outcap);
        z->outlen = 0;
        z->central_cap = 16;
        z->central = (_zip_central_entry*)calloc((size_t)z->central_cap, sizeof(_zip_central_entry));
        if (z->outbuf == NULL || z->central == NULL) {
            if (z->outbuf) free(z->outbuf);
            if (z->central) free(z->central);
            free(z->filepath);
            free(z);
            tp_throw_ex(new_tphp_class_Exception(STR_LIT("zip_open(): out of memory (write buffers)")));
            return NULL;
        }
    }

    tphp_class_Resource* res = new_tphp_class_Resource();
    if (res == NULL) {
        _tphp_zip_dtor(z);
        tp_throw_ex(new_tphp_class_Exception(STR_LIT("zip_open(): failed to allocate resource")));
        return NULL;
    }
    res->type = _tphp_zip_rsrc_type;
    res->ptr = z;
    tphp_rt_resource_insert(res);
    return res;
}

/**
 * zip_close(Resource $zip): bool
 *
 * 关闭 ZIP 归档。写入模式下将所有更改写入磁盘。
 */
static inline t_bool tphp_fn_zip_close(tphp_class_Resource* zip) {
    if (zip == NULL || zip->ptr == NULL) return false;
    _tphp_zip_data* z = (_tphp_zip_data*)zip->ptr;

    int success = 1;

    // 写入模式：写中央目录 + EOCD，然后写入文件
    if (z->mode == 1 && z->outbuf != NULL) {
        long cd_start = z->outlen;
        int total = z->central_count;

        // 确保容量
        long cd_size_est = (long)total * 46 + 22;
        for (int i = 0; i < total; i++) cd_size_est += z->central[i].name_len;
        if (!_zip_buf_ensure(z, cd_size_est + 64)) {
            _zip_seterr(z, "zip_close: out of memory");
            success = 0;
        } else {
            unsigned char* buf = (unsigned char*)z->outbuf;

            // 写中央目录条目
            for (int i = 0; i < total; i++) {
                _zip_central_entry* c = &z->central[i];
                unsigned short dos_time, dos_date;
                _zip_unix_to_dos(c->mtime, &dos_time, &dos_date);

                _zip_put_le32(buf + z->outlen, _ZIP_CENTRAL_SIG);
                _zip_put_le16(buf + z->outlen + 4, 20);   // version made by
                _zip_put_le16(buf + z->outlen + 6, 20);   // version needed
                _zip_put_le16(buf + z->outlen + 8, 0);    // flags
                _zip_put_le16(buf + z->outlen + 10, (unsigned short)c->comp_method);
                _zip_put_le16(buf + z->outlen + 12, dos_time);
                _zip_put_le16(buf + z->outlen + 14, dos_date);
                _zip_put_le32(buf + z->outlen + 16, (unsigned int)c->crc);
                _zip_put_le32(buf + z->outlen + 20, (unsigned int)c->comp_size);
                _zip_put_le32(buf + z->outlen + 24, (unsigned int)c->uncomp_size);
                _zip_put_le16(buf + z->outlen + 28, (unsigned short)c->name_len);
                _zip_put_le16(buf + z->outlen + 30, 0);   // extra len
                _zip_put_le16(buf + z->outlen + 32, 0);   // comment len
                _zip_put_le16(buf + z->outlen + 34, 0);   // disk number
                _zip_put_le16(buf + z->outlen + 36, 0);   // internal attrs
                _zip_put_le32(buf + z->outlen + 38, 0);   // external attrs
                _zip_put_le32(buf + z->outlen + 42, (unsigned int)c->local_offset);
                memcpy(buf + z->outlen + 46, c->name, (size_t)c->name_len);
                z->outlen += 46 + c->name_len;
            }

            long cd_size = z->outlen - cd_start;

            // 写 EOCD
            _zip_put_le32(buf + z->outlen, _ZIP_EOCD_SIG);
            _zip_put_le16(buf + z->outlen + 4, 0);    // disk number
            _zip_put_le16(buf + z->outlen + 6, 0);    // disk with CD
            _zip_put_le16(buf + z->outlen + 8, (unsigned short)total);
            _zip_put_le16(buf + z->outlen + 10, (unsigned short)total);
            _zip_put_le32(buf + z->outlen + 12, (unsigned int)cd_size);
            _zip_put_le32(buf + z->outlen + 16, (unsigned int)cd_start);
            _zip_put_le16(buf + z->outlen + 20, 0);   // comment len
            z->outlen += 22;

            // 写入文件
            FILE* fp = fopen(z->filepath, "wb");
            if (fp == NULL) {
                _zip_seterr(z, "zip_close: cannot open output file");
                success = 0;
            } else {
                size_t written = fwrite(z->outbuf, 1, (size_t)z->outlen, fp);
                fclose(fp);
                if ((long)written != z->outlen) {
                    _zip_seterr(z, "zip_close: incomplete write");
                    success = 0;
                }
            }
        }
    }

    // 释放资源（resource_delete 会调用 _tphp_zip_dtor）
    if (zip->handle >= 0) {
        tphp_rt_resource_delete(zip->handle);
    }
    return success;
}

/**
 * zip_read(Resource $zip): array
 *
 * 返回 ZIP 中所有文件的列表。
 * 每个条目是关联数组: name/index/size/comp_size/comp_method/mtime
 */
static inline t_array* tphp_fn_zip_read(tphp_class_Resource* zip) {
    if (zip == NULL || zip->ptr == NULL) {
        tp_throw_ex(new_tphp_class_Exception(STR_LIT("zip_read(): invalid zip resource")));
        return NULL;
    }
    _tphp_zip_data* z = (_tphp_zip_data*)zip->ptr;

    if (z->mode != 0) {
        tp_throw_ex(new_tphp_class_Exception(STR_LIT("zip_read(): zip is in write mode")));
        return NULL;
    }

    t_array* result = tphp_fn_arr_create(z->entry_count);
    if (result == NULL) return NULL;

    for (int i = 0; i < z->entry_count; i++) {
        _zip_entry* e = &z->entries[i];
        t_array* entry = tphp_fn_arr_create(6);
        if (entry == NULL) continue;

        tphp_fn_arr_set_str(entry, STR_LIT("name"), VAR_STRING(_zip_mkstr(e->name, e->name_len)));
        tphp_fn_arr_set_str(entry, STR_LIT("index"), VAR_INT(e->index));
        tphp_fn_arr_set_str(entry, STR_LIT("size"), VAR_INT((t_int)e->uncomp_size));
        tphp_fn_arr_set_str(entry, STR_LIT("comp_size"), VAR_INT((t_int)e->comp_size));
        tphp_fn_arr_set_str(entry, STR_LIT("comp_method"), VAR_INT((t_int)e->comp_method));
        tphp_fn_arr_set_str(entry, STR_LIT("mtime"), VAR_INT((t_int)e->mtime));

        tphp_fn_arr_push(result, VAR_ARRAY(entry));
    }

    return result;
}

/**
 * zip_entry_open(Resource $zip, int $index): bool
 */
static inline t_bool tphp_fn_zip_entry_open(tphp_class_Resource* zip, t_int index) {
    if (zip == NULL || zip->ptr == NULL) return false;
    _tphp_zip_data* z = (_tphp_zip_data*)zip->ptr;

    if (index < 0 || index >= z->entry_count) {
        _zip_seterr(z, "zip_entry_open: index out of range");
        return false;
    }
    z->current_entry = (int)index;
    return true;
}

/**
 * zip_entry_read(Resource $zip, int $index, int $length = 0): string
 *
 * 读取 ZIP 中条目的内容。$length=0 → 读取全部。
 */
static inline t_string tphp_fn_zip_entry_read(tphp_class_Resource* zip, t_int index, t_int length) {
    if (zip == NULL || zip->ptr == NULL) {
        tp_throw_ex(new_tphp_class_Exception(STR_LIT("zip_entry_read(): invalid zip resource")));
        return (t_string){0};
    }
    _tphp_zip_data* z = (_tphp_zip_data*)zip->ptr;

    if (z->mode != 0) {
        tp_throw_ex(new_tphp_class_Exception(STR_LIT("zip_entry_read(): zip is in write mode")));
        return (t_string){0};
    }
    if (index < 0 || index >= z->entry_count) {
        tp_throw_ex(new_tphp_class_Exception(STR_LIT("zip_entry_read(): index out of range")));
        return (t_string){0};
    }

    _zip_entry* e = &z->entries[index];
    const unsigned char* data = (const unsigned char*)z->filedata;
    long len = z->filelen;

    // 解析本地文件头获取数据偏移
    if (e->local_offset + 30 > len) {
        tp_throw_ex(new_tphp_class_Exception(STR_LIT("zip_entry_read: local header truncated")));
        return (t_string){0};
    }
    if (_zip_le32(data + e->local_offset) != _ZIP_LOCAL_SIG) {
        tp_throw_ex(new_tphp_class_Exception(STR_LIT("zip_entry_read: bad local header signature")));
        return (t_string){0};
    }
    int name_len = _zip_le16(data + e->local_offset + 26);
    int extra_len = _zip_le16(data + e->local_offset + 28);
    long data_off = e->local_offset + 30 + name_len + extra_len;

    if (data_off + e->comp_size > len) {
        tp_throw_ex(new_tphp_class_Exception(STR_LIT("zip_entry_read: file data truncated")));
        return (t_string){0};
    }

    long read_len = (length > 0 && length < e->uncomp_size) ? (long)length : e->uncomp_size;

    // comp_method 0 = store（无压缩）
    if (e->comp_method == 0) {
        return _zip_mkstr((const char*)(data + data_off), (int)read_len);
    }

    // comp_method 8 = deflate（raw DEFLATE，windowBits=-15）
    if (e->comp_method == 8) {
        z_stream strm;
        memset(&strm, 0, sizeof(strm));
        strm.next_in  = (Bytef*)(data + data_off);
        strm.avail_in = (uInt)e->comp_size;

        if (inflateInit2(&strm, -15) != Z_OK) {
            tp_throw_ex(new_tphp_class_Exception(STR_LIT("zip_entry_read: inflateInit2 failed")));
            return (t_string){0};
        }

        char* tmp = (char*)malloc((size_t)e->uncomp_size + 1);
        if (tmp == NULL) {
            inflateEnd(&strm);
            tp_throw_ex(new_tphp_class_Exception(STR_LIT("zip_entry_read: out of memory")));
            return (t_string){0};
        }
        strm.next_out  = (Bytef*)tmp;
        strm.avail_out = (uInt)e->uncomp_size;

        int ret = inflate(&strm, Z_FINISH);
        inflateEnd(&strm);

        if (ret != Z_STREAM_END) {
            free(tmp);
            tp_throw_ex(new_tphp_class_Exception(STR_LIT("zip_entry_read: inflate failed")));
            return (t_string){0};
        }

        t_string result = _zip_mkstr(tmp, (int)read_len);
        free(tmp);
        return result;
    }

    tp_throw_ex(new_tphp_class_Exception(STR_LIT("zip_entry_read: unsupported compression method")));
    return (t_string){0};
}

/**
 * zip_entry_close(Resource $zip): bool
 */
static inline t_bool tphp_fn_zip_entry_close(tphp_class_Resource* zip) {
    if (zip == NULL || zip->ptr == NULL) return false;
    _tphp_zip_data* z = (_tphp_zip_data*)zip->ptr;
    z->current_entry = -1;
    return true;
}

/**
 * zip_add_file(Resource $zip, string $name, string $data,
 *              int $flags = 0, int $comp_method = ZIP_CM_DEFLATE): bool
 *
 * 向 ZIP 中添加一个文件。
 */
static inline t_bool tphp_fn_zip_add_file(tphp_class_Resource* zip, t_string name, t_string data,
                                          t_int flags, t_int comp_method) {
    (void)flags;
    if (zip == NULL || zip->ptr == NULL) return false;
    _tphp_zip_data* z = (_tphp_zip_data*)zip->ptr;

    if (z->mode != 1) {
        _zip_seterr(z, "zip_add_file: zip is in read mode");
        return false;
    }

    const char* fname = STR_PTR(name);
    int fname_len = name.length;
    const char* fdata = STR_PTR(data);
    int funcomp = data.length;
    if (fname == NULL || fname_len <= 0) {
        _zip_seterr(z, "zip_add_file: empty name");
        return false;
    }

    // 压缩数据
    char* comp_buf = NULL;
    long comp_size = 0;
    short method = 0;  // 默认 store

    if (comp_method == TPHP_CONST_ZIP_CM_DEFLATE && funcomp > 0) {
        // DEFLATE 压缩
        uLongf bound = compressBound((uLong)funcomp);
        comp_buf = (char*)malloc((size_t)bound);
        if (comp_buf == NULL) {
            _zip_seterr(z, "zip_add_file: out of memory (compress)");
            return false;
        }
        z_stream strm;
        memset(&strm, 0, sizeof(strm));
        strm.next_in  = (Bytef*)fdata;
        strm.avail_in = (uInt)funcomp;
        strm.next_out = (Bytef*)comp_buf;
        strm.avail_out = (uInt)bound;

        if (deflateInit2(&strm, Z_DEFAULT_COMPRESSION, Z_DEFLATED, -15, 8, Z_DEFAULT_STRATEGY) != Z_OK) {
            free(comp_buf);
            _zip_seterr(z, "zip_add_file: deflateInit2 failed");
            return false;
        }
        int ret = deflate(&strm, Z_FINISH);
        deflateEnd(&strm);
        if (ret != Z_STREAM_END) {
            free(comp_buf);
            _zip_seterr(z, "zip_add_file: deflate failed");
            return false;
        }
        comp_size = (long)strm.total_out;
        method = 8;
    } else {
        // STORE：不压缩
        comp_buf = NULL;
        comp_size = funcomp;
        method = 0;
    }

    // CRC32
    unsigned long crc = (funcomp > 0) ? crc32(0L, Z_NULL, 0) : 0;
    if (funcomp > 0 && fdata != NULL) {
        crc = crc32(crc, (const Bytef*)fdata, (uInt)funcomp);
    }

    // 记录中央目录条目
    if (z->central_count >= z->central_cap) {
        int newcap = z->central_cap * 2;
        _zip_central_entry* newc = (_zip_central_entry*)realloc(z->central, (size_t)newcap * sizeof(_zip_central_entry));
        if (newc == NULL) {
            if (comp_buf) free(comp_buf);
            _zip_seterr(z, "zip_add_file: out of memory (central)");
            return false;
        }
        z->central = newc;
        z->central_cap = newcap;
    }
    _zip_central_entry* c = &z->central[z->central_count];
    c->name = (char*)malloc((size_t)fname_len + 1);
    if (c->name == NULL) {
        if (comp_buf) free(comp_buf);
        _zip_seterr(z, "zip_add_file: out of memory (name)");
        return false;
    }
    memcpy(c->name, fname, (size_t)fname_len);
    c->name[fname_len] = '\0';
    c->name_len = fname_len;
    c->uncomp_size = funcomp;
    c->comp_size = comp_size;
    c->comp_method = method;
    c->crc = crc;
    c->local_offset = z->outlen;
    c->mtime = (long)time(NULL);

    // 写本地文件头
    long header_size = 30 + fname_len;
    if (!_zip_buf_ensure(z, header_size + comp_size)) {
        free(c->name);
        if (comp_buf) free(comp_buf);
        _zip_seterr(z, "zip_add_file: out of memory (buffer)");
        return false;
    }

    unsigned char* buf = (unsigned char*)z->outbuf;
    unsigned short dos_time, dos_date;
    _zip_unix_to_dos(c->mtime, &dos_time, &dos_date);

    _zip_put_le32(buf + z->outlen, _ZIP_LOCAL_SIG);
    _zip_put_le16(buf + z->outlen + 4, 20);    // version needed
    _zip_put_le16(buf + z->outlen + 6, 0);     // flags
    _zip_put_le16(buf + z->outlen + 8, (unsigned short)method);
    _zip_put_le16(buf + z->outlen + 10, dos_time);
    _zip_put_le16(buf + z->outlen + 12, dos_date);
    _zip_put_le32(buf + z->outlen + 14, (unsigned int)crc);
    _zip_put_le32(buf + z->outlen + 18, (unsigned int)comp_size);
    _zip_put_le32(buf + z->outlen + 22, (unsigned int)funcomp);
    _zip_put_le16(buf + z->outlen + 26, (unsigned short)fname_len);
    _zip_put_le16(buf + z->outlen + 28, 0);    // extra len
    memcpy(buf + z->outlen + 30, fname, (size_t)fname_len);
    z->outlen += header_size;

    // 写压缩数据
    if (method == 8 && comp_buf != NULL) {
        memcpy(buf + z->outlen, comp_buf, (size_t)comp_size);
        free(comp_buf);
    } else if (method == 0 && funcomp > 0 && fdata != NULL) {
        memcpy(buf + z->outlen, fdata, (size_t)funcomp);
    }
    z->outlen += comp_size;

    z->central_count++;
    return true;
}

/**
 * zip_add_dir(Resource $zip, string $dirname, int $flags = 0): bool
 *
 * 添加目录。$dirname 以 / 结尾。
 */
static inline t_bool tphp_fn_zip_add_dir(tphp_class_Resource* zip, t_string dirname, t_int flags) {
    (void)flags;
    if (zip == NULL || zip->ptr == NULL) return false;
    _tphp_zip_data* z = (_tphp_zip_data*)zip->ptr;

    if (z->mode != 1) {
        _zip_seterr(z, "zip_add_dir: zip is in read mode");
        return false;
    }

    // 确保以 / 结尾
    const char* d = STR_PTR(dirname);
    int dlen = dirname.length;
    int need_slash = (dlen > 0 && d[dlen - 1] != '/') ? 1 : 0;

    char* name = (char*)malloc((size_t)dlen + need_slash + 1);
    if (name == NULL) {
        _zip_seterr(z, "zip_add_dir: out of memory");
        return false;
    }
    memcpy(name, d, (size_t)dlen);
    if (need_slash) name[dlen++] = '/';
    name[dlen] = '\0';

    // 用 store 方法添加空文件（目录条目）
    t_string name_ts = {name, dlen};
    t_string empty_ts = {NULL, 0};
    t_bool ok = tphp_fn_zip_add_file(zip, name_ts, empty_ts, 0, TPHP_CONST_ZIP_CM_STORE);
    free(name);
    return ok;
}

/**
 * zip_delete(Resource $zip, int $index): bool
 *
 * 从 ZIP 中删除指定索引的文件。
 * 注意：当前架构不支持修改已有归档。读取模式下删除无效（close 不回写），
 * 写入模式下归档为空无条目可删。如需删除，请创建新归档复制需要的条目。
 */
static inline t_bool tphp_fn_zip_delete(tphp_class_Resource* zip, t_int index) {
    (void)index;
    if (zip == NULL || zip->ptr == NULL) {
        tp_throw_ex(new_tphp_class_Exception(STR_LIT("zip_delete(): invalid zip resource")));
        return false;
    }
    tp_throw_ex(new_tphp_class_Exception(STR_LIT("zip_delete(): modifying existing archives is not supported. Create a new archive instead.")));
    return false;
}

/**
 * zip_rename(Resource $zip, int $index, string $new_name): bool
 *
 * 重命名 ZIP 中指定索引的文件。
 * 注意：当前架构不支持修改已有归档（同 zip_delete）。
 */
static inline t_bool tphp_fn_zip_rename(tphp_class_Resource* zip, t_int index, t_string new_name) {
    (void)index; (void)new_name;
    if (zip == NULL || zip->ptr == NULL) {
        tp_throw_ex(new_tphp_class_Exception(STR_LIT("zip_rename(): invalid zip resource")));
        return false;
    }
    tp_throw_ex(new_tphp_class_Exception(STR_LIT("zip_rename(): modifying existing archives is not supported. Create a new archive instead.")));
    return false;
}

/**
 * zip_stat(Resource $zip, int $index): array
 *
 * 获取单个条目的详细信息。
 */
static inline t_array* tphp_fn_zip_stat(tphp_class_Resource* zip, t_int index) {
    if (zip == NULL || zip->ptr == NULL) {
        tp_throw_ex(new_tphp_class_Exception(STR_LIT("zip_stat(): invalid zip resource")));
        return NULL;
    }
    _tphp_zip_data* z = (_tphp_zip_data*)zip->ptr;

    if (index < 0 || index >= z->entry_count) {
        tp_throw_ex(new_tphp_class_Exception(STR_LIT("zip_stat(): index out of range")));
        return NULL;
    }

    _zip_entry* e = &z->entries[index];
    t_array* entry = tphp_fn_arr_create(6);
    if (entry == NULL) return NULL;

    tphp_fn_arr_set_str(entry, STR_LIT("name"), VAR_STRING(_zip_mkstr(e->name, e->name_len)));
    tphp_fn_arr_set_str(entry, STR_LIT("index"), VAR_INT(e->index));
    tphp_fn_arr_set_str(entry, STR_LIT("size"), VAR_INT((t_int)e->uncomp_size));
    tphp_fn_arr_set_str(entry, STR_LIT("comp_size"), VAR_INT((t_int)e->comp_size));
    tphp_fn_arr_set_str(entry, STR_LIT("comp_method"), VAR_INT((t_int)e->comp_method));
    tphp_fn_arr_set_str(entry, STR_LIT("mtime"), VAR_INT((t_int)e->mtime));

    return entry;
}

/**
 * zip_num_files(Resource $zip): int
 */
static inline t_int tphp_fn_zip_num_files(tphp_class_Resource* zip) {
    if (zip == NULL || zip->ptr == NULL) return 0;
    _tphp_zip_data* z = (_tphp_zip_data*)zip->ptr;
    return (t_int)z->entry_count;
}

/**
 * zip_get_error_string(Resource $zip): string
 */
static inline t_string tphp_fn_zip_get_error_string(tphp_class_Resource* zip) {
    if (zip == NULL || zip->ptr == NULL) return STR_LIT("invalid zip resource");
    _tphp_zip_data* z = (_tphp_zip_data*)zip->ptr;
    int len = (int)strlen(z->errmsg);
    if (len <= 0) return STR_LIT("");
    return _zip_mkstr(z->errmsg, len);
}

// ══════════════════════════════════════════════════════════
// PHP 原生 zip_entry_* 系列函数（过程式 API 补全）
//   PHP 原生以 zip_entry resource 为参数，TinyPHP 以 (zip, index) 为参数
// ══════════════════════════════════════════════════════════

/**
 * zip_entry_name(Resource $zip, int $index): string
 *
 * 返回指定索引条目的文件名。
 */
static inline t_string tphp_fn_zip_entry_name(tphp_class_Resource* zip, t_int index) {
    if (zip == NULL || zip->ptr == NULL) {
        tp_throw_ex(new_tphp_class_Exception(STR_LIT("zip_entry_name(): invalid zip resource")));
        return (t_string){0};
    }
    _tphp_zip_data* z = (_tphp_zip_data*)zip->ptr;
    if (index < 0 || index >= z->entry_count) {
        tp_throw_ex(new_tphp_class_Exception(STR_LIT("zip_entry_name(): index out of range")));
        return (t_string){0};
    }
    _zip_entry* e = &z->entries[index];
    return _zip_mkstr(e->name, e->name_len);
}

/**
 * zip_entry_filesize(Resource $zip, int $index): int
 *
 * 返回指定索引条目的未压缩大小。
 */
static inline t_int tphp_fn_zip_entry_filesize(tphp_class_Resource* zip, t_int index) {
    if (zip == NULL || zip->ptr == NULL) {
        tp_throw_ex(new_tphp_class_Exception(STR_LIT("zip_entry_filesize(): invalid zip resource")));
        return 0;
    }
    _tphp_zip_data* z = (_tphp_zip_data*)zip->ptr;
    if (index < 0 || index >= z->entry_count) {
        tp_throw_ex(new_tphp_class_Exception(STR_LIT("zip_entry_filesize(): index out of range")));
        return 0;
    }
    return (t_int)z->entries[index].uncomp_size;
}

/**
 * zip_entry_compressedsize(Resource $zip, int $index): int
 *
 * 返回指定索引条目的压缩后大小。
 */
static inline t_int tphp_fn_zip_entry_compressedsize(tphp_class_Resource* zip, t_int index) {
    if (zip == NULL || zip->ptr == NULL) {
        tp_throw_ex(new_tphp_class_Exception(STR_LIT("zip_entry_compressedsize(): invalid zip resource")));
        return 0;
    }
    _tphp_zip_data* z = (_tphp_zip_data*)zip->ptr;
    if (index < 0 || index >= z->entry_count) {
        tp_throw_ex(new_tphp_class_Exception(STR_LIT("zip_entry_compressedsize(): index out of range")));
        return 0;
    }
    return (t_int)z->entries[index].comp_size;
}

/**
 * zip_entry_compressionmethod(Resource $zip, int $index): string
 *
 * 返回指定索引条目的压缩方法名称。
 *   0 → "Stored", 8 → "Deflated", 其他 → 数字字符串
 */
static inline t_string tphp_fn_zip_entry_compressionmethod(tphp_class_Resource* zip, t_int index) {
    if (zip == NULL || zip->ptr == NULL) {
        tp_throw_ex(new_tphp_class_Exception(STR_LIT("zip_entry_compressionmethod(): invalid zip resource")));
        return (t_string){0};
    }
    _tphp_zip_data* z = (_tphp_zip_data*)zip->ptr;
    if (index < 0 || index >= z->entry_count) {
        tp_throw_ex(new_tphp_class_Exception(STR_LIT("zip_entry_compressionmethod(): index out of range")));
        return (t_string){0};
    }
    short method = z->entries[index].comp_method;
    if (method == 0) return STR_LIT("Stored");
    if (method == 8) return STR_LIT("Deflated");
    // 未知方法：返回数字字符串
    char buf[16];
    int len = snprintf(buf, sizeof(buf), "%d", (int)method);
    return _zip_mkstr(buf, len);
}

/**
 * zip_locate(Resource $zip, string $name): int
 *
 * 按文件名查找条目索引。未找到返回 -1。
 */
static inline t_int tphp_fn_zip_locate(tphp_class_Resource* zip, t_string name) {
    if (zip == NULL || zip->ptr == NULL) {
        tp_throw_ex(new_tphp_class_Exception(STR_LIT("zip_locate(): invalid zip resource")));
        return -1;
    }
    _tphp_zip_data* z = (_tphp_zip_data*)zip->ptr;
    const char* needle = STR_PTR(name);
    int nlen = name.length;
    if (needle == NULL || nlen <= 0) {
        tp_throw_ex(new_tphp_class_Exception(STR_LIT("zip_locate(): empty name")));
        return -1;
    }
    for (int i = 0; i < z->entry_count; i++) {
        if (z->entries[i].name_len == nlen &&
            memcmp(z->entries[i].name, needle, (size_t)nlen) == 0) {
            return (t_int)i;
        }
    }
    return -1;
}
