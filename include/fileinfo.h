#pragma once
// ============================================================
// fileinfo.h — finfo_open / finfo_file / finfo_buffer / finfo_close
//             finfo_set_flags / mime_content_type
// 对应 PHP ext/fileinfo，纯 C 实现轻量级 MIME 检测，零外部依赖
//
// 设计说明：
//   - 不依赖 libmagic（无需 magic.mgc 数据库文件分发）
//   - 内置静态魔数表，覆盖 60+ 常见文件类型
//   - 使用 Resource 对象包装 finfo 状态（flags）
//   - 错误处理统一 tp_throw_ex(new_tphp_class_Exception(...))（不返回 false，符合 AOT 单返回类型契约）
//   - 字符串输出走 str_pool_alloc，作用域结束自动释放
//   - 文件检测只读前 512 字节（足够覆盖所有魔数偏移）
//   - 文本检测：无魔数匹配时检查是否为可打印 ASCII/UTF-8
//   - RIFF 格式二次检查（WAV/AVI/WebP 共享 RIFF 头）
// ============================================================

#include <stdio.h>
#include <string.h>
#include <stdlib.h>
#include "types.h"
#include "object/resource.h"

// 前向声明
static inline char* str_pool_alloc(int len);
static inline void tphp_rt_register(void* p, int is_array);

// ── fileinfo 常量（与 libmagic MAGIC_* 同值）─────────────
#define TPHP_CONST_FILEINFO_NONE              0
#define TPHP_CONST_FILEINFO_SYMLINK           2
#define TPHP_CONST_FILEINFO_DEVICES           8
#define TPHP_CONST_FILEINFO_MIME_TYPE         16
#define TPHP_CONST_FILEINFO_CONTINUE          32
#define TPHP_CONST_FILEINFO_PRESERVE_ATIME    128
#define TPHP_CONST_FILEINFO_RAW               256
#define TPHP_CONST_FILEINFO_MIME_ENCODING     1024
#define TPHP_CONST_FILEINFO_MIME              1040   // MIME_TYPE | MIME_ENCODING
#define TPHP_CONST_FILEINFO_EXTENSION         16777216  // 0x1000000

// ══════════════════════════════════════════════════════════
// 内部数据结构
// ══════════════════════════════════════════════════════════

// finfo 内部状态（通过 Resource->ptr 指向）
typedef struct {
    t_int flags;
} _tphp_finfo_data;

// 魔数表条目
typedef struct {
    const char* mime;            // MIME 类型（FILEINFO_MIME_TYPE）
    const char* desc;            // 描述文字（FILEINFO_NONE）
    int         offset;          // 主魔数偏移
    int         magic_len;       // 主魔数长度
    const char* magic;           // 主魔数字节
    int         sec_offset;      // 二次检查偏移（-1=不需要）
    int         sec_len;         // 二次检查长度
    const char* sec_magic;       // 二次检查字节（NULL=通配 RIFF）
    const char* encoding;        // 字符集（NULL=binary）
    const char* extension;       // 文件扩展名（FILEINFO_EXTENSION）
} _finfo_magic_entry;

// ══════════════════════════════════════════════════════════
// 静态魔数表（顺序：精确匹配优先，RIFF 子类型在 RIFF 通配之前）
// ══════════════════════════════════════════════════════════
static const _finfo_magic_entry _finfo_magic_table[] = {
    // ── RIFF 子类型（二次检查，必须放在通用 RIFF 之前）──
    { "image/webp",       "WebP image data",         0, 4, "RIFF", 8, 4, "WEBP", NULL,       "webp" },
    { "audio/wav",        "WAVE audio data",         0, 4, "RIFF", 8, 4, "WAVE", NULL,       "wav"  },
    { "video/x-msvideo",  "AVI video",               0, 4, "RIFF", 8, 4, "AVI ", NULL,       "avi"  },
    { "application/x-riff","RIFF data",              0, 4, "RIFF", -1, 0, NULL,  NULL,       "riff" },

    // ── 图片 ──
    { "image/jpeg",       "JPEG image data",         0, 3, "\xFF\xD8\xFF", -1, 0, NULL, NULL, "jpeg" },
    { "image/png",        "PNG image data",          0, 8, "\x89PNG\r\n\x1A\n", -1, 0, NULL, NULL, "png"  },
    { "image/gif",        "GIF image data",          0, 6, "GIF87a", -1, 0, NULL, NULL,       "gif"  },
    { "image/gif",        "GIF image data",          0, 6, "GIF89a", -1, 0, NULL, NULL,       "gif"  },
    { "image/bmp",        "BMP image data",          0, 2, "BM",     -1, 0, NULL, NULL,       "bmp"  },
    { "image/tiff",       "TIFF image data",         0, 4, "II*\x00", -1, 0, NULL, NULL,      "tiff" },
    { "image/tiff",       "TIFF image data",         0, 4, "MM\x00*", -1, 0, NULL, NULL,      "tiff" },
    { "image/x-icon",     "ICO image data",          0, 4, "\x00\x00\x01\x00", -1, 0, NULL, NULL, "ico" },
    { "image/vnd.adobe.photoshop", "Photoshop image", 0, 4, "8BPS", -1, 0, NULL, NULL,        "psd"  },

    // ── 音频 ──
    { "audio/mpeg",       "Audio file with ID3 tag", 0, 3, "ID3",  -1, 0, NULL, NULL,        "mp3"  },
    { "audio/mpeg",       "MP3 audio data",          0, 2, "\xFF\xFB", -1, 0, NULL, NULL,     "mp3"  },
    { "audio/mpeg",       "MP3 audio data",          0, 2, "\xFF\xF3", -1, 0, NULL, NULL,     "mp3"  },
    { "audio/mpeg",       "MP3 audio data",          0, 2, "\xFF\xF2", -1, 0, NULL, NULL,     "mp3"  },
    { "audio/flac",       "FLAC audio data",         0, 4, "fLaC", -1, 0, NULL, NULL,        "flac" },
    { "audio/ogg",        "Ogg audio data",          0, 4, "OggS", -1, 0, NULL, NULL,        "ogg"  },
    { "audio/midi",       "MIDI audio data",         0, 4, "MThd", -1, 0, NULL, NULL,        "midi" },
    { "audio/aac",        "AAC audio data",          0, 2, "\xFF\xF1", -1, 0, NULL, NULL,     "aac"  },

    // ── 视频 ──
    { "video/mp4",        "MP4 video",               4, 4, "ftyp", -1, 0, NULL, NULL,        "mp4"  },
    { "video/webm",       "WebM video",              0, 4, "\x1A\x45\xDF\xA3", -1, 0, NULL, NULL, "webm" },
    { "video/quicktime",  "QuickTime video",         4, 4, "moov", -1, 0, NULL, NULL,        "mov"  },

    // ── 文档 ──
    { "application/pdf",  "PDF document",            0, 4, "%PDF", -1, 0, NULL, NULL,        "pdf"  },
    { "application/rtf",  "RTF document",            0, 5, "{\\rtf", -1, 0, NULL, NULL,      "rtf"  },
    { "application/postscript", "PostScript document", 0, 4, "%!PS", -1, 0, NULL, NULL,      "ps"   },
    { "application/msword", "Microsoft Word document", 0, 8, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1", -1, 0, NULL, NULL, "doc" },

    // ── 压缩/归档 ──
    { "application/zip",  "Zip archive data",        0, 4, "PK\x03\x04", -1, 0, NULL, NULL,   "zip"  },
    { "application/zip",  "Zip archive data",        0, 4, "PK\x05\x06", -1, 0, NULL, NULL,   "zip"  },
    { "application/zip",  "Zip archive data",        0, 4, "PK\x07\x08", -1, 0, NULL, NULL,   "zip"  },
    { "application/gzip", "gzip compressed data",    0, 2, "\x1F\x8B", -1, 0, NULL, NULL,     "gz"   },
    { "application/x-rar", "RAR archive data",       0, 4, "Rar!\x1A\x07", -1, 0, NULL, NULL, "rar"  },
    { "application/x-7z-compressed", "7z archive data", 0, 6, "7z\xBC\xAF\x27\x1C", -1, 0, NULL, NULL, "7z" },
    { "application/x-bzip2", "bzip2 compressed data", 0, 3, "BZh", -1, 0, NULL, NULL,         "bz2"  },
    { "application/x-xz", "XZ compressed data",      0, 6, "\xFD" "7zXZ\x00", -1, 0, NULL, NULL, "xz"   },
    { "application/x-tar", "POSIX tar archive",      257, 5, "ustar", -1, 0, NULL, NULL,      "tar"  },

    // ── 字体 ──
    { "font/ttf",         "TrueType font data",      0, 4, "\x00\x01\x00\x00", -1, 0, NULL, NULL, "ttf"  },
    { "font/otf",         "OpenType font data",      0, 4, "OTTO", -1, 0, NULL, NULL,        "otf"  },
    { "font/woff",        "WOFF font data",          0, 4, "wOFF", -1, 0, NULL, NULL,        "woff" },
    { "font/woff2",       "WOFF2 font data",         0, 4, "wOF2", -1, 0, NULL, NULL,        "woff2"},

    // ── 可执行/字节码 ──
    { "application/x-executable", "ELF executable",  0, 4, "\x7F" "ELF", -1, 0, NULL, NULL,     "elf"  },
    { "application/x-dosexec", "DOS/Windows executable", 0, 2, "MZ", -1, 0, NULL, NULL,      "exe"  },
    { "application/java-vm", "Java class file",      0, 4, "\xCA\xFE\xBA\xBE", -1, 0, NULL, NULL, "class" },

    // ── 数据库 ──
    { "application/vnd.sqlite3", "SQLite 3 database", 0, 15, "SQLite format 3\x00", -1, 0, NULL, NULL, "sqlite" },

    // ── XML / 脚本 ──
    { "text/xml",         "XML document text",       0, 5, "<?xml", -1, 0, NULL, "utf-8",  "xml"  },
    { "text/x-php",       "PHP script text",         0, 5, "<?php", -1, 0, NULL, "utf-8",    "php"  },
    { "text/x-php",       "PHP script text",         0, 3, "<?=",  -1, 0, NULL, "utf-8",     "php"  },

    // ── 文本 BOM ──
    { "text/plain",       "UTF-8 Unicode text",      0, 3, "\xEF\xBB\xBF", -1, 0, NULL, "utf-8",    "txt" },
    { "text/plain",       "UTF-16 BE text",          0, 2, "\xFE\xFF",     -1, 0, NULL, "utf-16be", "txt" },
    { "text/plain",       "UTF-16 LE text",          0, 2, "\xFF\xFE",     -1, 0, NULL, "utf-16le", "txt" },

    // ── 其他 ──
    { "application/x-shockwave-flash", "SWF flash",  0, 3, "FWS",  -1, 0, NULL, NULL,        "swf"  },
    { "application/x-shockwave-flash", "SWF flash (compressed)", 0, 3, "CWS", -1, 0, NULL, NULL, "swf" },
    { "application/wasm", "WebAssembly module",      0, 4, "\x00asm", -1, 0, NULL, NULL,     "wasm" },
    { "application/x-ns-proxy-autoconfig", "PAC file", 0, 9, "function ", -1, 0, NULL, "utf-8", "pac" },
};

#define _FINFO_MAGIC_COUNT  (sizeof(_finfo_magic_table) / sizeof(_finfo_magic_table[0]))

// 检测缓冲区最大读取量
#define _FINFO_BUF_SIZE  512

// ══════════════════════════════════════════════════════════
// 内部辅助
// ══════════════════════════════════════════════════════════

// 资源类型 ID（懒初始化）
static t_int _tphp_finfo_rsrc_type = -1;

// finfo 资源析构回调
static void _tphp_finfo_dtor(void* ptr) {
    if (ptr != NULL) {
        free(ptr);
    }
}

// 懒初始化资源类型
static inline void _tphp_finfo_init(void) {
    if (_tphp_finfo_rsrc_type < 0) {
        _tphp_finfo_rsrc_type = tphp_rt_register_resource_type(_tphp_finfo_dtor, "fileinfo");
    }
}

// 从 const char* 创建 t_string（走 str_pool_alloc）
static inline t_string _finfo_mkstr(const char* s) {
    if (s == NULL) return (t_string){0};
    int len = (int)strlen(s);
    if (len <= 0) return (t_string){0};
    char* buf = str_pool_alloc(len);
    if (buf == NULL) return (t_string){0};
    memcpy(buf, s, (size_t)len);
    return (t_string){buf, len};
}

// 拼接两段 C 字符串到 str_pool
static inline t_string _finfo_concat(const char* a, const char* sep, const char* b) {
    int la = (int)strlen(a);
    int ls = (int)strlen(sep);
    int lb = (int)strlen(b);
    int total = la + ls + lb;
    char* buf = str_pool_alloc(total);
    if (buf == NULL) return (t_string){0};
    memcpy(buf, a, (size_t)la);
    memcpy(buf + la, sep, (size_t)ls);
    memcpy(buf + la + ls, b, (size_t)lb);
    return (t_string){buf, total};
}

// ══════════════════════════════════════════════════════════
// 内部检测核心
// ══════════════════════════════════════════════════════════

// 在魔数表中查找匹配条目
//   buf/len: 文件头缓冲区
//   返回匹配条目指针，未匹配返回 NULL
static inline const _finfo_magic_entry* _finfo_detect(const unsigned char* buf, int len) {
    if (buf == NULL || len <= 0) return NULL;

    for (int i = 0; i < (int)_FINFO_MAGIC_COUNT; i++) {
        const _finfo_magic_entry* e = &_finfo_magic_table[i];

        // 检查主魔数偏移 + 长度是否在缓冲区范围内
        if (e->offset + e->magic_len > len) continue;

        // 检查主魔数
        if (memcmp(buf + e->offset, e->magic, (size_t)e->magic_len) != 0) continue;

        // 检查二次验证
        if (e->sec_offset >= 0 && e->sec_len > 0) {
            if (e->sec_offset + e->sec_len > len) continue;
            if (e->sec_magic != NULL) {
                if (memcmp(buf + e->sec_offset, e->sec_magic, (size_t)e->sec_len) != 0) continue;
            }
        }

        return e;
    }

    return NULL;
}

// 检测是否为可打印文本（ASCII / UTF-8）
//   返回 1=文本, 0=二进制
static inline int _finfo_is_text(const unsigned char* buf, int len) {
    if (buf == NULL || len <= 0) return 0;

    int printable = 0;
    for (int i = 0; i < len; i++) {
        unsigned char c = buf[i];
        // 允许：可打印 ASCII (32-126)、制表符(9)、换行(10)、回车(13)
        if (c >= 0x20 && c <= 0x7E) { printable++; continue; }
        if (c == '\t' || c == '\n' || c == '\r') { printable++; continue; }
        // 允许 UTF-8 多字节序列
        if ((c & 0xE0) == 0xC0 && i + 1 < len && (buf[i+1] & 0xC0) == 0x80) {
            printable++; i++; continue;
        }
        if ((c & 0xF0) == 0xE0 && i + 2 < len &&
            (buf[i+1] & 0xC0) == 0x80 && (buf[i+2] & 0xC0) == 0x80) {
            printable++; i += 2; continue;
        }
        if ((c & 0xF8) == 0xF0 && i + 3 < len &&
            (buf[i+1] & 0xC0) == 0x80 && (buf[i+2] & 0xC0) == 0x80 && (buf[i+3] & 0xC0) == 0x80) {
            printable++; i += 3; continue;
        }
        // 不可打印且非 UTF-8 → 二进制
        return 0;
    }
    // 至少 80% 可打印才算文本
    return (printable * 5 >= len * 4) ? 1 : 0;
}

// 根据检测结果 + flags 格式化输出字符串
static inline t_string _finfo_format(const _finfo_magic_entry* entry, t_int flags) {
    // 未知类型 fallback（二进制 — 调用方已先尝试文本检测未命中才到这里）
    if (entry == NULL) {
        if (flags & TPHP_CONST_FILEINFO_EXTENSION) {
            return (t_string){0};
        }
        // MIME (TYPE + ENCODING): "application/octet-stream; charset=binary"
        if ((flags & TPHP_CONST_FILEINFO_MIME_TYPE) && (flags & TPHP_CONST_FILEINFO_MIME_ENCODING)) {
            return _finfo_mkstr("application/octet-stream; charset=binary");
        }
        if (flags & TPHP_CONST_FILEINFO_MIME_TYPE) {
            return _finfo_mkstr("application/octet-stream");
        }
        if (flags & TPHP_CONST_FILEINFO_MIME_ENCODING) {
            return _finfo_mkstr("binary");
        }
        return _finfo_mkstr("application/octet-stream");
    }

    // FILEINFO_EXTENSION: 返回扩展名
    if (flags & TPHP_CONST_FILEINFO_EXTENSION) {
        return _finfo_mkstr(entry->extension);
    }

    // FILEINFO_MIME (TYPE + ENCODING): "mime; charset=encoding"
    if ((flags & TPHP_CONST_FILEINFO_MIME_TYPE) && (flags & TPHP_CONST_FILEINFO_MIME_ENCODING)) {
        const char* enc = (entry->encoding != NULL) ? entry->encoding : "binary";
        return _finfo_concat(entry->mime, "; charset=", enc);
    }

    // FILEINFO_MIME_TYPE: 返回 MIME 类型
    if (flags & TPHP_CONST_FILEINFO_MIME_TYPE) {
        return _finfo_mkstr(entry->mime);
    }

    // FILEINFO_MIME_ENCODING: 返回字符集
    if (flags & TPHP_CONST_FILEINFO_MIME_ENCODING) {
        return _finfo_mkstr((entry->encoding != NULL) ? entry->encoding : "binary");
    }

    // FILEINFO_NONE: 返回描述文字
    return _finfo_mkstr(entry->desc);
}

// 检测缓冲区并格式化输出（核心内部函数）
static inline t_string _finfo_detect_buffer(const unsigned char* buf, int len, t_int flags) {
    const _finfo_magic_entry* entry = _finfo_detect(buf, len);

    // 未匹配魔数 → 尝试文本检测
    if (entry == NULL && _finfo_is_text(buf, len)) {
        if (flags & TPHP_CONST_FILEINFO_EXTENSION) {
            return _finfo_mkstr("txt");
        }
        if ((flags & TPHP_CONST_FILEINFO_MIME_TYPE) && (flags & TPHP_CONST_FILEINFO_MIME_ENCODING)) {
            return _finfo_mkstr("text/plain; charset=utf-8");
        }
        if (flags & TPHP_CONST_FILEINFO_MIME_TYPE) {
            return _finfo_mkstr("text/plain");
        }
        if (flags & TPHP_CONST_FILEINFO_MIME_ENCODING) {
            return _finfo_mkstr("utf-8");
        }
        return _finfo_mkstr("ASCII text");
    }

    return _finfo_format(entry, flags);
}

// ══════════════════════════════════════════════════════════
// 公共 API
// ══════════════════════════════════════════════════════════

/**
 * finfo_open(int $flags = FILEINFO_NONE, string $magic_file = ""): Resource
 *
 * 创建 fileinfo 资源。$magic_file 参数被接受但忽略（内置魔数表）。
 * 失败抛 tp_throw（内存不足等）。
 */
static inline tphp_class_Resource* tphp_fn_finfo_open(t_int flags, t_string magic_file) {
    (void)magic_file;  // 内置魔数表，忽略外部 magic 文件

    _tphp_finfo_init();

    _tphp_finfo_data* data = (_tphp_finfo_data*)malloc(sizeof(_tphp_finfo_data));
    if (data == NULL) {
        tp_throw_ex(new_tphp_class_Exception(STR_LIT("finfo_open(): out of memory")));
        return NULL;
    }
    data->flags = flags;

    tphp_class_Resource* res = new_tphp_class_Resource();
    if (res == NULL) {
        free(data);
        tp_throw_ex(new_tphp_class_Exception(STR_LIT("finfo_open(): failed to allocate resource")));
        return NULL;
    }
    res->type = _tphp_finfo_rsrc_type;
    res->ptr = data;

    tphp_rt_resource_insert(res);
    return res;
}

/**
 * finfo_file(Resource $finfo, string $filename, int $flags = FILEINFO_NONE): string
 *
 * 通过文件名检测 MIME 类型。读取文件前 512 字节进行魔数匹配。
 * 文件无法打开 → tp_throw。
 */
static inline t_string tphp_fn_finfo_file(tphp_class_Resource* finfo, t_string filename, t_int flags) {
    if (finfo == NULL || finfo->ptr == NULL) {
        tp_throw_ex(new_tphp_class_Exception(STR_LIT("finfo_file(): invalid fileinfo resource")));
        return (t_string){0};
    }

    // 路径转 C 字符串
    if (STR_PTR(filename) == NULL || filename.length <= 0) {
        tp_throw_ex(new_tphp_class_Exception(STR_LIT("finfo_file(): empty filename")));
        return (t_string){0};
    }
    char pathbuf[4096];
    int plen = filename.length < (int)sizeof(pathbuf) - 1 ? filename.length : (int)sizeof(pathbuf) - 1;
    memcpy(pathbuf, STR_PTR(filename), (size_t)plen);
    pathbuf[plen] = '\0';

    FILE* fp = fopen(pathbuf, "rb");
    if (fp == NULL) {
        tp_throw_ex(new_tphp_class_Exception(STR_LIT("finfo_file(): failed to open file")));
        return (t_string){0};
    }

    unsigned char buf[_FINFO_BUF_SIZE];
    size_t nread = fread(buf, 1, sizeof(buf), fp);
    fclose(fp);

    // 使用传入 flags 或资源默认 flags
    _tphp_finfo_data* data = (_tphp_finfo_data*)finfo->ptr;
    t_int effective_flags = (flags != 0) ? flags : data->flags;
    // 如果都为 0，使用 MIME_TYPE（PHP 兼容行为：默认返回描述）
    // 注意：PHP 默认 FILEINFO_NONE 返回描述文字，这里保持一致

    return _finfo_detect_buffer(buf, (int)nread, effective_flags);
}

/**
 * finfo_buffer(Resource $finfo, string $data, int $flags = FILEINFO_NONE): string
 *
 * 通过内存数据检测 MIME 类型。
 */
static inline t_string tphp_fn_finfo_buffer(tphp_class_Resource* finfo, t_string data, t_int flags) {
    if (finfo == NULL || finfo->ptr == NULL) {
        tp_throw_ex(new_tphp_class_Exception(STR_LIT("finfo_buffer(): invalid fileinfo resource")));
        return (t_string){0};
    }

    _tphp_finfo_data* fdata = (_tphp_finfo_data*)finfo->ptr;
    t_int effective_flags = (flags != 0) ? flags : fdata->flags;

    int len = (data.length < _FINFO_BUF_SIZE) ? data.length : _FINFO_BUF_SIZE;
    return _finfo_detect_buffer((const unsigned char*)STR_PTR(data), len, effective_flags);
}

/**
 * finfo_close(Resource $finfo): void
 *
 * 关闭 fileinfo 资源。释放内部状态。
 */
static inline void tphp_fn_finfo_close(tphp_class_Resource* finfo) {
    if (finfo == NULL) return;
    // resource_delete 会调用析构回调 free(finfo->ptr)
    if (finfo->handle >= 0) {
        tphp_rt_resource_delete(finfo->handle);
    }
}

/**
 * finfo_set_flags(Resource $finfo, int $flags): bool
 *
 * 设置 fileinfo 资源的默认 flags。始终返回 true。
 */
static inline t_bool tphp_fn_finfo_set_flags(tphp_class_Resource* finfo, t_int flags) {
    if (finfo == NULL || finfo->ptr == NULL) {
        tp_throw_ex(new_tphp_class_Exception(STR_LIT("finfo_set_flags(): invalid fileinfo resource")));
        return false;
    }
    _tphp_finfo_data* data = (_tphp_finfo_data*)finfo->ptr;
    data->flags = flags;
    return true;
}

/**
 * mime_content_type(string $filename): string
 *
 * 便捷函数：返回文件的 MIME 类型。
 * 等价于 finfo_open(FILEINFO_MIME_TYPE) + finfo_file + finfo_close。
 * 文件无法打开 → tp_throw。
 */
static inline t_string tphp_fn_mime_content_type(t_string filename) {
    // 路径转 C 字符串
    if (STR_PTR(filename) == NULL || filename.length <= 0) {
        tp_throw_ex(new_tphp_class_Exception(STR_LIT("mime_content_type(): empty filename")));
        return (t_string){0};
    }
    char pathbuf[4096];
    int plen = filename.length < (int)sizeof(pathbuf) - 1 ? filename.length : (int)sizeof(pathbuf) - 1;
    memcpy(pathbuf, STR_PTR(filename), (size_t)plen);
    pathbuf[plen] = '\0';

    FILE* fp = fopen(pathbuf, "rb");
    if (fp == NULL) {
        tp_throw_ex(new_tphp_class_Exception(STR_LIT("mime_content_type(): failed to open file")));
        return (t_string){0};
    }

    unsigned char buf[_FINFO_BUF_SIZE];
    size_t nread = fread(buf, 1, sizeof(buf), fp);
    fclose(fp);

    return _finfo_detect_buffer(buf, (int)nread, TPHP_CONST_FILEINFO_MIME_TYPE);
}
