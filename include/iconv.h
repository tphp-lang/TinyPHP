#pragma once
// ============================================================
// iconv.h — 字符集转换 (PHP ext/iconv 等价实现)
// 作为内置函数直接集成，失败统一抛 tp_throw (不返回 false)
//
// 跨平台策略：
//   POSIX (Linux/macOS/BSD)  → 系统原生 <iconv.h>
//   Windows                  → Win32 MultiByteToWideChar / WideCharToMultiByte
//
// AOT 单返回类型契约：
//   iconv_strlen / iconv_strpos → t_int   (失败 tp_throw)
//   iconv_substr / iconv        → t_string(失败 tp_throw)
//   iconv_get_encoding          → t_array*(始终返回 3 元素关联数组)
//   iconv_set_encoding          → t_bool
//   iconv_mime_encode / decode  → t_string(失败 tp_throw)
// ============================================================
#include "types.h"
#include "array.h"
#include "runtime.h"

// POSIX 使用系统 iconv；Windows 使用 Win32 API
#ifndef _WIN32
    // 本文件名为 iconv.h，与系统 <iconv.h> 同名。
    // 如果用 #include <iconv.h>，GCC/Clang 会找到当前文件而非系统头文件（影子化），
    // 导致 iconv_t 未定义。统一对所有非 Windows 编译器使用手动前向声明。
    //   - iconv_t 在 glibc/musl/macOS 上均为 void* 的 typedef，兼容
    //   - 仅需 iconv_open/iconv/iconv_close 三个符号
    typedef void* iconv_t;
    extern iconv_t iconv_open(const char*, const char*);
    extern size_t  iconv(iconv_t, char**, size_t*, char**, size_t*);
    extern int     iconv_close(iconv_t);
#else
    #include <windows.h>
#endif

// ── 常量 (STR_LIT → t_string，与用户字符串常量一致) ──────────
#define TPHP_CONST_ICONV_IMPL    STR_LIT("iconv")
#define TPHP_CONST_ICONV_VERSION STR_LIT("1.0")

// ── 自包含 UTF-8 / hex 辅助 (避免依赖 std/utf8.h 的按需引入) ──
static inline int _tphp_iconv_utf8_is_cont(unsigned char c) { return (c & 0xC0) == 0x80; }
static inline int _tphp_iconv_utf8_clen(unsigned char c) {
    if (c < 0x80) return 1;
    if ((c & 0xE0) == 0xC0) return 2;
    if ((c & 0xF0) == 0xE0) return 3;
    if ((c & 0xF8) == 0xF0) return 4;
    return 1;
}
static inline t_int _tphp_iconv_utf8_strlen(t_string s) {
    if (STR_PTR(s) == NULL || s.length <= 0) return 0;
    int count = 0;
    const unsigned char *p = (const unsigned char*)STR_PTR(s);
    for (int i = 0; i < s.length; ) {
        int cl = _tphp_iconv_utf8_clen(p[i]);
        if (cl > 1) { for (int j = 1; j < cl && i + j < s.length; j++)
            if (!_tphp_iconv_utf8_is_cont(p[i+j])) { cl = 1; break; } }
        count++; i += cl;
    }
    return (t_int)count;
}
static inline t_string _tphp_iconv_utf8_substr(t_string s, t_int start, t_int length) {
    if (STR_PTR(s) == NULL || s.length <= 0) return (t_string){NULL,0,false};
    const unsigned char *p = (const unsigned char*)STR_PTR(s);
    int byte_start = 0, ch_count = 0, n = s.length;
    if (start >= 0) {
        for (int i = 0; i < n && ch_count < (int)start; ) {
            int cl = _tphp_iconv_utf8_clen(p[i]); ch_count++; i += cl; byte_start = i;
        }
    } else {
        int total = (int)_tphp_iconv_utf8_strlen(s);
        int target = total + (int)start; if (target < 0) target = 0;
        for (int i = 0; i < n && ch_count < target; ) {
            int cl = _tphp_iconv_utf8_clen(p[i]); ch_count++; i += cl; byte_start = i;
        }
    }
    if (byte_start >= n) return (t_string){NULL,0,false};
    int byte_end = n;
    if (length > 0) {
        ch_count = 0;
        for (int i = byte_start; i < n && ch_count < (int)length; ) {
            int cl = _tphp_iconv_utf8_clen(p[i]); ch_count++; i += cl; byte_end = i;
        }
    }
    int len = byte_end - byte_start;
    if (len <= 0) return (t_string){NULL,0,false};
    if (byte_start == 0 && len == n) return s;
    char *buf = str_pool_alloc(len); if (!buf) return (t_string){NULL,0,false};
    memcpy(buf, p + byte_start, (size_t)len); buf[len] = '\0';
    return (t_string){buf, len, false};
}
static inline int _tphp_iconv_hexval(char x) {
    if (x >= '0' && x <= '9') return x - '0';
    if (x >= 'A' && x <= 'F') return x - 'A' + 10;
    if (x >= 'a' && x <= 'f') return x - 'a' + 10;
    return -1;
}

// ── 内部编码状态 (iconv_get/set_encoding 维护) ──────────────
// 使用 is_lit=true 初始化，使 tphp_rt_str_dup 走字面量零拷贝路径
// (避免 SSO 经 t_var 联合体存储时的潜在问题)
static t_string _tphp_iconv_input_enc    = { .data = (char*)"UTF-8", .length = 5,  .is_local = false, .is_lit = true };
static t_string _tphp_iconv_output_enc   = { .data = (char*)"UTF-8", .length = 5,  .is_local = false, .is_lit = true };
static t_string _tphp_iconv_internal_enc = { .data = (char*)"UTF-8", .length = 5,  .is_local = false, .is_lit = true };

// ── 字符串工具：大小写不敏感比较 (仅 ASCII) ─────────────────
static inline int _tphp_iconv_ieq_ch(char a, char b) {
    if (a >= 'A' && a <= 'Z') a += 32;
    if (b >= 'A' && b <= 'Z') b += 32;
    return a == b;
}
static inline int _tphp_iconv_ieqs(const char *a, const char *b) {
    while (*a && *b) { if (!_tphp_iconv_ieq_ch(*a, *b)) return 0; a++; b++; }
    return *a == 0 && *b == 0;
}
// 比较 t_string 与 C 字面量 (大小写不敏感)
static inline int _tphp_str_ieq_cstr(t_string s, const char *lit) {
    if (STR_PTR(s) == NULL) return lit[0] == 0;
    for (int i = 0; i < s.length; i++) {
        if (lit[i] == 0) return 0;
        if (!_tphp_iconv_ieq_ch(STR_PTR(s)[i], lit[i])) return 0;
    }
    return lit[s.length] == 0;
}

// 判断 charset 是否为 UTF-8 的常见写法
static inline int _tphp_iconv_is_utf8(t_string cs) {
    return _tphp_str_ieq_cstr(cs, "UTF-8") || _tphp_str_ieq_cstr(cs, "UTF8");
}

// 将 t_string 拷贝到 NTS 缓冲区 (用于传给 iconv_open / Win32)
static inline void _tphp_iconv_to_cstr(t_string s, char *buf, int bufsize) {
    int n = (s.length < bufsize - 1) ? s.length : bufsize - 1;
    if (STR_PTR(s) != NULL && n > 0) memcpy(buf, STR_PTR(s), (size_t)n);
    buf[n] = '\0';
}

// ============================================================
// 核心转换：_tphp_iconv_conv(from, to, str) → t_string
//   失败 tp_throw。支持后缀 "//IGNORE" / "//TRANSLIT" (POSIX 原生)
// ============================================================
static inline t_string _tphp_iconv_conv(t_string from, t_string to, t_string str) {
    if (STR_PTR(str) == NULL || str.length <= 0) {
        return (t_string){ .data = NULL, .length = 0, .is_local = false };
    }

#ifndef _WIN32
    // ── POSIX: 系统 iconv ────────────────────────────────
    char fb[64], tb[64];
    _tphp_iconv_to_cstr(from, fb, sizeof(fb));
    _tphp_iconv_to_cstr(to,   tb, sizeof(tb));
    iconv_t cd = iconv_open(tb, fb);
    if (cd == (iconv_t)-1) {
        tp_throw("iconv: unsupported charset conversion");
    }
    // 输出缓冲：最坏 1 字节 → 4 字节 (UTF-8 最大)，再留余量
    size_t inleft  = (size_t)str.length;
    size_t outcap  = inleft * 4 + 16;
    char  *inbuf   = (char*)STR_PTR(str);
    char  *outbuf  = str_pool_alloc((int)outcap);
    if (outbuf == NULL) { iconv_close(cd); tp_throw("iconv: out of memory"); }
    char  *outp    = outbuf;
    size_t outleft = outcap;
    size_t rc = iconv(cd, &inbuf, &inleft, &outp, &outleft);
    iconv_close(cd);
    if (rc == (size_t)-1) {
        tp_throw("iconv: conversion failed (invalid byte sequence)");
    }
    int written = (int)(outcap - outleft);
    outbuf[written] = '\0';
    return (t_string){ outbuf, written };
#else
    // ── Windows: MultiByteToWideChar / WideCharToMultiByte ──
    // charset 名 → codepage
    int from_cp, to_cp;
    if      (_tphp_str_ieq_cstr(from, "UTF-8") || _tphp_str_ieq_cstr(from, "UTF8")) from_cp = 65001;
    else if (_tphp_str_ieq_cstr(from, "ASCII") || _tphp_str_ieq_cstr(from, "US-ASCII")) from_cp = 20127;
    else if (_tphp_str_ieq_cstr(from, "ISO-8859-1") || _tphp_str_ieq_cstr(from, "LATIN1")) from_cp = 28591;
    else if (_tphp_str_ieq_cstr(from, "WINDOWS-1252") || _tphp_str_ieq_cstr(from, "CP1252")) from_cp = 1252;
    else if (_tphp_str_ieq_cstr(from, "GB2312") || _tphp_str_ieq_cstr(from, "GBK") || _tphp_str_ieq_cstr(from, "GB18030")) from_cp = 936;
    else if (_tphp_str_ieq_cstr(from, "BIG5")) from_cp = 950;
    else if (_tphp_str_ieq_cstr(from, "SHIFT_JIS") || _tphp_str_ieq_cstr(from, "SJIS")) from_cp = 932;
    else if (_tphp_str_ieq_cstr(from, "EUC-JP")) from_cp = 51932;
    else if (_tphp_str_ieq_cstr(from, "EUC-KR")) from_cp = 949;
    else if (_tphp_str_ieq_cstr(from, "KOI8-R")) from_cp = 20866;
    else { tp_throw("iconv: unsupported source charset on Windows"); }
    if      (_tphp_str_ieq_cstr(to, "UTF-8") || _tphp_str_ieq_cstr(to, "UTF8")) to_cp = 65001;
    else if (_tphp_str_ieq_cstr(to, "ASCII") || _tphp_str_ieq_cstr(to, "US-ASCII")) to_cp = 20127;
    else if (_tphp_str_ieq_cstr(to, "ISO-8859-1") || _tphp_str_ieq_cstr(to, "LATIN1")) to_cp = 28591;
    else if (_tphp_str_ieq_cstr(to, "WINDOWS-1252") || _tphp_str_ieq_cstr(to, "CP1252")) to_cp = 1252;
    else if (_tphp_str_ieq_cstr(to, "GB2312") || _tphp_str_ieq_cstr(to, "GBK") || _tphp_str_ieq_cstr(to, "GB18030")) to_cp = 936;
    else if (_tphp_str_ieq_cstr(to, "BIG5")) to_cp = 950;
    else if (_tphp_str_ieq_cstr(to, "SHIFT_JIS") || _tphp_str_ieq_cstr(to, "SJIS")) to_cp = 932;
    else if (_tphp_str_ieq_cstr(to, "EUC-JP")) to_cp = 51932;
    else if (_tphp_str_ieq_cstr(to, "EUC-KR")) to_cp = 949;
    else if (_tphp_str_ieq_cstr(to, "KOI8-R")) to_cp = 20866;
    else { tp_throw("iconv: unsupported target charset on Windows"); }

    // from → UTF-16
    int wlen = MultiByteToWideChar(from_cp, 0, STR_PTR(str), (int)str.length, NULL, 0);
    if (wlen <= 0) { tp_throw("iconv: source conversion failed"); }
    wchar_t *wbuf = (wchar_t*)malloc((size_t)wlen * sizeof(wchar_t));
    if (wbuf == NULL) { tp_throw("iconv: out of memory"); }
    MultiByteToWideChar(from_cp, 0, STR_PTR(str), (int)str.length, wbuf, wlen);
    // UTF-16 → to
    int blen = WideCharToMultiByte(to_cp, 0, wbuf, wlen, NULL, 0, NULL, NULL);
    if (blen <= 0) { free(wbuf); tp_throw("iconv: target conversion failed"); }
    char *outbuf = str_pool_alloc(blen);
    if (outbuf == NULL) { free(wbuf); tp_throw("iconv: out of memory"); }
    WideCharToMultiByte(to_cp, 0, wbuf, wlen, outbuf, blen, NULL, NULL);
    free(wbuf);
    outbuf[blen] = '\0';
    return (t_string){ outbuf, blen };
#endif
}

// ============================================================
// iconv($from, $to, $str) — 核心编码转换
// ============================================================
static inline t_string tphp_fn_iconv(t_string from, t_string to, t_string str) {
    return _tphp_iconv_conv(from, to, str);
}

// ============================================================
// iconv_strlen($str, $charset="UTF-8") — 字符数
//   UTF-8 走快路径；其余先转 UTF-8 再计数
// ============================================================
static inline t_int tphp_fn_iconv_strlen(t_string str, t_string charset) {
    if (STR_PTR(str) == NULL || str.length <= 0) return 0;
    if (_tphp_iconv_is_utf8(charset)) {
        return _tphp_iconv_utf8_strlen(str);
    }
    t_string utf8 = _tphp_iconv_conv(charset, (t_string){(char*)"UTF-8", 5}, str);
    return _tphp_iconv_utf8_strlen(utf8);
}

// ============================================================
// iconv_strpos($h, $n, $offset=0, $charset="UTF-8") — 字符偏移定位
//   返回字符位置 (>=0)；未找到返回 -1 (单类型 t_int，非 false)
// ============================================================
static inline t_int tphp_fn_iconv_strpos(t_string haystack, t_string needle, t_int offset, t_string charset) {
    if (STR_PTR(haystack) == NULL || STR_PTR(needle) == NULL) return -1;
    if (needle.length == 0) return 0;
    t_string h, n;
    if (_tphp_iconv_is_utf8(charset)) {
        h = haystack; n = needle;
    } else {
        t_string u8 = (t_string){(char*)"UTF-8", 5};
        h = _tphp_iconv_conv(charset, u8, haystack);
        n = _tphp_iconv_conv(charset, u8, needle);
    }
    // 按 UTF-8 字符跳过 offset 个字符后做字节级子串搜索
    const unsigned char *p = (const unsigned char*)STR_PTR(h);
    int byte_off = 0, ch = 0, hlen = h.length;
    while (ch < (int)offset && byte_off < hlen) {
        int cl = _tphp_iconv_utf8_clen(p[byte_off]);
        if (cl < 1) cl = 1;
        byte_off += cl; ch++;
    }
    if (byte_off >= hlen) return -1;
    // 在 byte_off 起的字节范围里找 needle
    if (n.length > (hlen - byte_off)) return -1;
    for (int i = byte_off; i <= hlen - n.length; i++) {
        if (memcmp(STR_PTR(h) + i, STR_PTR(n), (size_t)n.length) == 0) {
            // 字节位置 i → 字符位置：从 0 数到 i 的字符数
            int cc = 0;
            for (int j = 0; j < i; ) {
                int cl = _tphp_iconv_utf8_clen(p[j]);
                if (cl < 1) cl = 1;
                j += cl; cc++;
            }
            return (t_int)cc;
        }
    }
    return -1;
}

// ============================================================
// iconv_substr($str, $offset, $length=0, $charset="UTF-8")
//   length<=0 表示到末尾。UTF-8 走快路径；其余转 UTF-8 截取后转回原编码
// ============================================================
static inline t_string tphp_fn_iconv_substr(t_string str, t_int offset, t_int length, t_string charset) {
    if (STR_PTR(str) == NULL || str.length <= 0) {
        return (t_string){ .data = NULL, .length = 0, .is_local = false };
    }
    if (_tphp_iconv_is_utf8(charset)) {
        return _tphp_iconv_utf8_substr(str, offset, length);
    }
    t_string u8 = (t_string){(char*)"UTF-8", 5};
    t_string utf8 = _tphp_iconv_conv(charset, u8, str);
    t_string sub  = _tphp_iconv_utf8_substr(utf8, offset, length);
    if (STR_PTR(sub) == NULL || sub.length <= 0) {
        return (t_string){ .data = NULL, .length = 0, .is_local = false };
    }
    return _tphp_iconv_conv(u8, charset, sub);
}

// ============================================================
// iconv_get_encoding($type="all") → 始终返回关联数组
//   keys: input_encoding / output_encoding / internal_encoding
//   (AOT 单返回类型：不再 string|array|false)
// ============================================================
static inline t_array* tphp_fn_iconv_get_encoding(t_string type) {
    (void)type; // 始终返回完整数组，忽略 type 区分
    t_array *out = tphp_fn_arr_create(3);
    if (out == NULL) tp_throw("iconv_get_encoding: out of memory");
    tphp_rt_register((void*)out, 1);
    // 使用 STR_LIT 作为 key (is_lit=true → str_dup 零拷贝，避免 SSO 经 t_var 存储问题)
    out = tphp_fn_arr_set_str(out, STR_LIT("input_encoding"),    VAR_STRING(tphp_rt_str_dup(_tphp_iconv_input_enc)));
    out = tphp_fn_arr_set_str(out, STR_LIT("output_encoding"),   VAR_STRING(tphp_rt_str_dup(_tphp_iconv_output_enc)));
    out = tphp_fn_arr_set_str(out, STR_LIT("internal_encoding"), VAR_STRING(tphp_rt_str_dup(_tphp_iconv_internal_enc)));
    return out;
}

// ============================================================
// iconv_set_encoding($type, $encoding) → bool
// ============================================================
static inline t_bool tphp_fn_iconv_set_encoding(t_string type, t_string encoding) {
    if      (_tphp_str_ieq_cstr(type, "input_encoding"))    _tphp_iconv_input_enc = encoding;
    else if (_tphp_str_ieq_cstr(type, "output_encoding"))   _tphp_iconv_output_enc = encoding;
    else if (_tphp_str_ieq_cstr(type, "internal_encoding")) _tphp_iconv_internal_enc = encoding;
    else { tp_throw("iconv_set_encoding: unknown type (expected input_encoding/output_encoding/internal_encoding)"); }
    return true;
}

// ── 内部 base64 编码 (自包含，不依赖 html.h) ────────────────
static inline int _tphp_iconv_b64_encode(const unsigned char *src, int slen, char *dst) {
    static const char tbl[] = "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/";
    int o = 0, i = 0;
    for (; i + 2 < slen; i += 3) {
        unsigned v = (src[i] << 16) | (src[i+1] << 8) | src[i+2];
        dst[o++] = tbl[(v >> 18) & 63];
        dst[o++] = tbl[(v >> 12) & 63];
        dst[o++] = tbl[(v >> 6) & 63];
        dst[o++] = tbl[v & 63];
    }
    if (i < slen) {
        unsigned v = src[i] << 16;
        if (i + 1 < slen) v |= src[i+1] << 8;
        dst[o++] = tbl[(v >> 18) & 63];
        dst[o++] = tbl[(v >> 12) & 63];
        dst[o++] = (i + 1 < slen) ? tbl[(v >> 6) & 63] : '=';
        dst[o++] = '=';
    }
    return o;
}
static inline int _tphp_iconv_b64_val(char c) {
    if (c >= 'A' && c <= 'Z') return c - 'A';
    if (c >= 'a' && c <= 'z') return c - 'a' + 26;
    if (c >= '0' && c <= '9') return c - '0' + 52;
    if (c == '+') return 62;
    if (c == '/') return 63;
    return -1;
}
static inline int _tphp_iconv_b64_decode(const char *src, int slen, unsigned char *dst) {
    int o = 0, i = 0;
    for (; i + 3 < slen; i += 4) {
        int a = _tphp_iconv_b64_val(src[i]);
        int b = _tphp_iconv_b64_val(src[i+1]);
        int c = _tphp_iconv_b64_val(src[i+2]);
        int d = _tphp_iconv_b64_val(src[i+3]);
        if (a < 0 || b < 0) return -1;
        unsigned v = (a << 18) | (b << 12);
        dst[o++] = (unsigned char)(v >> 16);
        if (src[i+2] != '=') { if (c < 0) return -1; v |= c << 6; dst[o++] = (unsigned char)(v >> 8); }
        if (src[i+3] != '=') { if (d < 0) return -1; v |= d; dst[o++] = (unsigned char)v; }
    }
    return o;
}

// ============================================================
// iconv_mime_encode($field_name, $field_value, $prefs=[]) → string
//   生成: FieldName: =?<charset>?B?<base64>?=
//   prefs["output-charset"] 控制输出编码 (默认 UTF-8)
// ============================================================
static inline t_string tphp_fn_iconv_mime_encode(t_string field_name, t_string field_value, t_array *prefs) {
    t_string charset = (t_string){(char*)"UTF-8", 5};
    // 解析 prefs["output-charset"]
    if (prefs != NULL) {
        for (int i = 0; i < prefs->length; i++) {
            if (prefs->entries[i].key.type == TYPE_STRING &&
                _tphp_str_ieq_cstr(prefs->entries[i].key.value._string, "output-charset")) {
                charset = prefs->entries[i].val.value._string;
                break;
            }
        }
    }
    // 将 value 转换到目标 charset
    t_string val_enc;
    if (_tphp_iconv_is_utf8(charset)) {
        val_enc = tphp_rt_str_dup(field_value);
    } else {
        t_string u8 = (t_string){(char*)"UTF-8", 5};
        val_enc = _tphp_iconv_conv(u8, charset, field_value);
    }
    // base64 编码
    int b64len = ((val_enc.length + 2) / 3) * 4;
    char csbuf[32]; _tphp_iconv_to_cstr(charset, csbuf, sizeof(csbuf));
    // 总长: name + ": =?" + cs + "?B?" + b64 + "?="
    int total = field_name.length + 6 + (int)strlen(csbuf) + b64len;
    char *buf = str_pool_alloc(total);
    if (buf == NULL) tp_throw("iconv_mime_encode: out of memory");
    int pos = 0;
    if (field_name.length > 0 && STR_PTR(field_name) != NULL) {
        memcpy(buf + pos, STR_PTR(field_name), (size_t)field_name.length);
        pos += field_name.length;
    }
    buf[pos++] = ':'; buf[pos++] = ' ';
    buf[pos++] = '='; buf[pos++] = '?';
    int cslen = (int)strlen(csbuf);
    memcpy(buf + pos, csbuf, (size_t)cslen); pos += cslen;
    buf[pos++] = '?'; buf[pos++] = 'B'; buf[pos++] = '?';
    int n = _tphp_iconv_b64_encode((const unsigned char*)(STR_PTR(val_enc) ? STR_PTR(val_enc) : ""), val_enc.length, buf + pos);
    pos += n;
    buf[pos++] = '?'; buf[pos++] = '=';
    buf[pos] = '\0';
    return (t_string){ buf, pos };
}

// ============================================================
// iconv_mime_decode($str, $mode=0, $charset="UTF-8") → string
//   解析 =?<cs>?[BQ]?<encoded>?= 并按 $charset 输出
// ============================================================
static inline t_string tphp_fn_iconv_mime_decode(t_string str, t_int mode, t_string charset) {
    (void)mode;
    const char *s = STR_PTR(str);
    if (s == NULL || str.length < 8) return tphp_rt_str_dup(str);
    // 查找 "=?"
    int i = 0, len = str.length;
    while (i + 1 < len && !(s[i] == '=' && s[i+1] == '?')) i++;
    if (i + 7 >= len) return tphp_rt_str_dup(str); // 无 MIME 编码段
    // 复制前缀
    int prefix_len = i;
    // 解析 =?<cs>?<B|Q>?<body>?=
    int p = i + 2;
    int cs_start = p;
    while (p < len && s[p] != '?') p++;
    if (p >= len) return tphp_rt_str_dup(str);
    int cs_end = p;
    p++; // skip '?'
    if (p >= len) return tphp_rt_str_dup(str);
    char enc = s[p];
    p++; // skip B/Q
    if (p < len && s[p] == '?') p++;
    int body_start = p;
    while (p + 1 < len && !(s[p] == '?' && s[p+1] == '=')) p++;
    if (p + 1 >= len) return tphp_rt_str_dup(str);
    int body_end = p;
    p += 2; // skip "?="

    // 提取 charset / body
    char csbuf[32];
    int csl = cs_end - cs_start;
    if (csl >= (int)sizeof(csbuf)) csl = (int)sizeof(csbuf) - 1;
    memcpy(csbuf, s + cs_start, (size_t)csl); csbuf[csl] = '\0';
    t_string src_cs = (t_string){ csbuf, csl };

    int body_len = body_end - body_start;
    // 先解码 B/Q → 原始字节
    unsigned char rawbuf[4096];
    int raw_len = 0;
    if (enc == 'B' || enc == 'b') {
        if (body_len > 4095) body_len = 4095;
        raw_len = _tphp_iconv_b64_decode(s + body_start, body_len, rawbuf);
        if (raw_len < 0) tp_throw("iconv_mime_decode: invalid base64 body");
    } else if (enc == 'Q' || enc == 'q') {
        // Q 编码: =XX 十六进制，_ 表示空格
        for (int k = 0; k < body_len && raw_len < 4095; k++) {
            char c = s[body_start + k];
            if (c == '_') rawbuf[raw_len++] = ' ';
            else if (c == '=' && k + 2 < body_len) {
                int hi = _tphp_iconv_hexval(s[body_start + k + 1]);
                int lo = _tphp_iconv_hexval(s[body_start + k + 2]);
                if (hi < 0 || lo < 0) tp_throw("iconv_mime_decode: invalid Q-encoded byte");
                rawbuf[raw_len++] = (unsigned char)((hi << 4) | lo);
                k += 2;
            } else rawbuf[raw_len++] = (unsigned char)c;
        }
    } else {
        tp_throw("iconv_mime_decode: unknown encoding (expected B or Q)");
    }

    t_string raw_str = (t_string){ (char*)rawbuf, raw_len };
    // raw 字节是 src_cs 编码 → 转换到目标 charset
    t_string decoded;
    if (_tphp_str_ieq_cstr(src_cs, "UTF-8") || _tphp_str_ieq_cstr(src_cs, "UTF8")) {
        if (_tphp_iconv_is_utf8(charset)) {
            decoded = tphp_rt_str_dup(raw_str);
        } else {
            t_string u8 = (t_string){(char*)"UTF-8", 5};
            decoded = _tphp_iconv_conv(u8, charset, raw_str);
        }
    } else {
        decoded = _tphp_iconv_conv(src_cs, charset, raw_str);
    }

    // 拼接 prefix + decoded + 尾部剩余 (简化：丢弃尾部剩余以保持单段)
    if (prefix_len <= 0) return decoded;
    int total = prefix_len + decoded.length;
    char *out = str_pool_alloc(total);
    if (out == NULL) tp_throw("iconv_mime_decode: out of memory");
    memcpy(out, s, (size_t)prefix_len);
    if (decoded.length > 0 && STR_PTR(decoded) != NULL)
        memcpy(out + prefix_len, STR_PTR(decoded), (size_t)decoded.length);
    out[total] = '\0';
    return (t_string){ out, total };
}
