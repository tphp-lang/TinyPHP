#pragma once
// std/html.h — HTML安全 + Base64 + HTTP Build Query
//   对应 PHP ext/standard html + base64 + http functions

// ── htmlspecialchars: 转义 & < > " ' → 实体 ──────────────────
static t_string tphp_fn_htmlspecialchars(t_string s) {
    if (STR_PTR(s) == NULL || s.length <= 0) return tphp_rt_str_dup(s);
    int extra = 0;
    const char *p = STR_PTR(s);
    for (int i = 0; i < s.length; i++) {
        switch (p[i]) {
        case '&': extra += 4; break;
        case '"': extra += 5; break;
        case '\'': extra += 5; break;
        case '<': case '>': extra += 3; break;
        }
    }
    if (extra == 0) return tphp_rt_str_dup(s);
    int total = s.length + extra;
    char *buf = str_pool_alloc(total);
    if (!buf) return tphp_rt_str_dup(STR_LIT(""));
    int pos = 0;
    for (int i = 0; i < s.length; i++) {
        switch (p[i]) {
        case '&': memcpy(buf+pos, "&amp;", 5); pos+=5; break;
        case '"': memcpy(buf+pos, "&quot;", 6); pos+=6; break;
        case '\'': memcpy(buf+pos, "&#039;", 6); pos+=6; break;
        case '<': memcpy(buf+pos, "&lt;", 4); pos+=4; break;
        case '>': memcpy(buf+pos, "&gt;", 4); pos+=4; break;
        default: buf[pos++] = p[i];
        }
    }
    return (t_string){.data = buf, .length = pos, .is_local = false};
}

// ── nl2br: \n → <br>\n ────────────────────────────────────
static t_string tphp_fn_nl2br(t_string s) {
    if (STR_PTR(s) == NULL || s.length <= 0) return tphp_rt_str_dup(s);
    int count = 0;
    const char *p = STR_PTR(s);
    for (int i = 0; i < s.length; i++) { if (p[i] == '\n') count++; }
    if (count == 0) return tphp_rt_str_dup(s);
    int total = s.length + count * 4;
    char *buf = str_pool_alloc(total);
    if (!buf) return tphp_rt_str_dup(STR_LIT(""));
    int pos = 0;
    for (int i = 0; i < s.length; i++) {
        if (p[i] == '\n') { memcpy(buf+pos, "<br>\n", 5); pos += 5; }
        else buf[pos++] = p[i];
    }
    return (t_string){.data = buf, .length = pos, .is_local = false};
}

// ── base64 编码表 ────────────────────────────────────────────
static const char b64e_tab[64] = "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/";

// ── base64_encode ────────────────────────────────────────────
static t_string tphp_fn_base64_encode(t_string s) {
    if (STR_PTR(s) == NULL || s.length <= 0) return tphp_rt_str_dup(STR_LIT(""));
    const unsigned char *p = (const unsigned char*)STR_PTR(s);
    int outlen = ((s.length + 2) / 3) * 4;
    char *buf = str_pool_alloc(outlen);
    if (!buf) return tphp_rt_str_dup(STR_LIT(""));
    int pos = 0, i = 0;
    while (i + 3 <= s.length) {
        buf[pos++] = b64e_tab[p[i] >> 2];
        buf[pos++] = b64e_tab[((p[i] & 0x03) << 4) | (p[i+1] >> 4)];
        buf[pos++] = b64e_tab[((p[i+1] & 0x0f) << 2) | (p[i+2] >> 6)];
        buf[pos++] = b64e_tab[p[i+2] & 0x3f];
        i += 3;
    }
    if (i < s.length) {
        buf[pos++] = b64e_tab[p[i] >> 2];
        if (i + 1 < s.length) {
            buf[pos++] = b64e_tab[((p[i] & 0x03) << 4) | (p[i+1] >> 4)];
            buf[pos++] = b64e_tab[(p[i+1] & 0x0f) << 2];
        } else { buf[pos++] = b64e_tab[(p[i] & 0x03) << 4]; buf[pos++] = '='; }
        buf[pos++] = '=';
    }
    return (t_string){.data = buf, .length = pos, .is_local = false};
}

// ── base64 解码表 ────────────────────────────────────────────
static const unsigned char b64d_tab[256] = {
    0xFF,0xFF,0xFF,0xFF,0xFF,0xFF,0xFF,0xFF,0xFF,0xFF,0xFF,0xFF,0xFF,0xFF,0xFF,0xFF,
    0xFF,0xFF,0xFF,0xFF,0xFF,0xFF,0xFF,0xFF,0xFF,0xFF,0xFF,0xFF,0xFF,0xFF,0xFF,0xFF,
    0xFF,0xFF,0xFF,0xFF,0xFF,0xFF,0xFF,0xFF,0xFF,0xFF,0xFF,0x3E,0xFF,0xFF,0xFF,0x3F,
    0x34,0x35,0x36,0x37,0x38,0x39,0x3A,0x3B,0x3C,0x3D,0xFF,0xFF,0xFF,0xFF,0xFF,0xFF,
    0xFF,0x00,0x01,0x02,0x03,0x04,0x05,0x06,0x07,0x08,0x09,0x0A,0x0B,0x0C,0x0D,0x0E,
    0x0F,0x10,0x11,0x12,0x13,0x14,0x15,0x16,0x17,0x18,0x19,0xFF,0xFF,0xFF,0xFF,0xFF,
    0xFF,0x1A,0x1B,0x1C,0x1D,0x1E,0x1F,0x20,0x21,0x22,0x23,0x24,0x25,0x26,0x27,0x28,
    0x29,0x2A,0x2B,0x2C,0x2D,0x2E,0x2F,0x30,0x31,0x32,0x33,0xFF,0xFF,0xFF,0xFF,0xFF,
};

// ── base64_decode ────────────────────────────────────────────
static t_string tphp_fn_base64_decode(t_string s) {
    if (STR_PTR(s) == NULL || s.length <= 0) return tphp_rt_str_dup(STR_LIT(""));
    const char *p = STR_PTR(s);
    int len = s.length;
    while (len > 0 && p[len-1] == '=') len--;
    if (len == 0) return tphp_rt_str_dup(STR_LIT(""));
    int outlen = (len * 3) / 4 + 2;
    char *buf = str_pool_alloc(outlen);
    if (!buf) return tphp_rt_str_dup(STR_LIT(""));
    int pos = 0, i = 0;
    while (i + 4 <= len) {
        unsigned char a = b64d_tab[(unsigned char)p[i]], b = b64d_tab[(unsigned char)p[i+1]];
        unsigned char c = b64d_tab[(unsigned char)p[i+2]], d = b64d_tab[(unsigned char)p[i+3]];
        if (a == 0xFF || b == 0xFF || c == 0xFF || d == 0xFF) break;
        buf[pos++] = (char)((a << 2) | (b >> 4));
        buf[pos++] = (char)((b << 4) | (c >> 2));
        buf[pos++] = (char)((c << 6) | d);
        i += 4;
    }
    if (i + 3 <= len) {
        unsigned char a = b64d_tab[(unsigned char)p[i]], b = b64d_tab[(unsigned char)p[i+1]];
        unsigned char c = b64d_tab[(unsigned char)p[i+2]];
        if (a < 0xFF && b < 0xFF && c < 0xFF) {
            buf[pos++] = (char)((a << 2) | (b >> 4));
            buf[pos++] = (char)((b << 4) | (c >> 2));
        }
    } else if (i + 2 <= len) {
        unsigned char a = b64d_tab[(unsigned char)p[i]], b = b64d_tab[(unsigned char)p[i+1]];
        if (a < 0xFF && b < 0xFF) buf[pos++] = (char)((a << 2) | (b >> 4));
    }
    return (t_string){.data = buf, .length = pos, .is_local = false};
}

// ── http_build_query ───────────────────────────────────────────
static t_string tphp_fn_http_build_query(t_array *arr) {
    if (arr == NULL || arr->length <= 0) return tphp_rt_str_dup(STR_LIT(""));
    t_string r = tphp_rt_str_dup(STR_LIT(""));
    for (int i = 0; i < arr->length; i++) {
        if (i > 0) r = tphp_rt_str_concat(r, STR_LIT("&"));
        if (arr->entries[i].key.type == TYPE_STRING)
            r = tphp_rt_str_concat(r, tphp_fn_urlencode(arr->entries[i].key.value._string));
        else if (arr->entries[i].key.type == TYPE_INT)
            r = tphp_rt_str_concat(r, tphp_rt_str_from_int(arr->entries[i].key.value._int));
        r = tphp_rt_str_concat(r, STR_LIT("="));
        if (arr->entries[i].val.type == TYPE_STRING)
            r = tphp_rt_str_concat(r, tphp_fn_urlencode(arr->entries[i].val.value._string));
        else if (arr->entries[i].val.type == TYPE_INT)
            r = tphp_rt_str_concat(r, tphp_rt_str_from_int(arr->entries[i].val.value._int));
        else if (arr->entries[i].val.type == TYPE_FLOAT)
            r = tphp_rt_str_concat(r, tphp_rt_str_from_float(arr->entries[i].val.value._float));
        else if (arr->entries[i].val.type == TYPE_BOOL)
            r = tphp_rt_str_concat(r, arr->entries[i].val.value._bool ? STR_LIT("1") : STR_LIT("0"));
    }
    return r;
}
