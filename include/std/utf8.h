#pragma once
// std/utf8.h — UTF-8 多字节字符串函数
//   对应 PHP ext/mbstring (仅UTF-8)

// ── 判断是否为 UTF-8 后续字节 (10xxxxxx) ────────────────
static inline bool _utf8_is_cont(unsigned char c) { return (c & 0xC0) == 0x80; }

// ── UTF-8 字符字节数 (首字节) ──────────────────────────
static inline int _utf8_clen(unsigned char c) {
    if (c < 0x80) return 1;
    if ((c & 0xE0) == 0xC0) return 2;
    if ((c & 0xF0) == 0xE0) return 3;
    if ((c & 0xF8) == 0xF0) return 4;
    return 1; // invalid, treat as single byte
}

// ── mb_strlen: UTF-8 字符数 ────────────────────────────
static t_int tphp_fn_mb_strlen(t_string s) {
    if (STR_PTR(s) == NULL || s.length <= 0) return 0;
    int count = 0;
    const unsigned char *p = (const unsigned char*)STR_PTR(s);
    for (int i = 0; i < s.length; ) {
        int cl = _utf8_clen(p[i]);
        if (cl > 1) { for (int j = 1; j < cl && i + j < s.length; j++)
            if (!_utf8_is_cont(p[i+j])) { cl = 1; break; } }
        count++; i += cl;
    }
    return (t_int)count;
}

// ── mb_substr: UTF-8 子串 ──────────────────────────────
static t_string tphp_fn_mb_substr(t_string s, t_int start, t_int length) {
    if (STR_PTR(s) == NULL || s.length <= 0) return (t_string){NULL,0,false};
    const unsigned char *p = (const unsigned char*)STR_PTR(s);
    int byte_start = 0, ch_count = 0, n = s.length;
    // Find byte position of start character
    if (start >= 0) {
        for (int i = 0; i < n && ch_count < (int)start; ) {
            int cl = _utf8_clen(p[i]); ch_count++; i += cl; byte_start = i;
        }
    } else {
        // negative start: count from end
        int total = (int)tphp_fn_mb_strlen(s);
        int target = total + (int)start; if (target < 0) target = 0;
        for (int i = 0; i < n && ch_count < target; ) {
            int cl = _utf8_clen(p[i]); ch_count++; i += cl; byte_start = i;
        }
    }
    if (byte_start >= n) return (t_string){NULL,0,false};
    // Find byte position after length characters
    int byte_end = n;
    if (length > 0) {
        ch_count = 0;
        for (int i = byte_start; i < n && ch_count < (int)length; ) {
            int cl = _utf8_clen(p[i]); ch_count++; i += cl; byte_end = i;
        }
    }
    int len = byte_end - byte_start;
    if (len <= 0) return (t_string){NULL,0,false};
    if (byte_start == 0 && len == n) return s;
    char *buf = str_pool_alloc(len); if (!buf) return (t_string){NULL,0,false};
    memcpy(buf, p + byte_start, (size_t)len); buf[len] = '\0';
    return (t_string){buf, len, false};
}

// ── mb_strpos: UTF-8 定位 ──────────────────────────────
static t_int tphp_fn_mb_strpos(t_string haystack, t_string needle) {
    return tphp_fn_strpos(haystack, needle); // UTF-8 substring search works at byte level
}
