#pragma once
// ============================================================
// filter.h — filter_var / filter_var_array / filter_list / filter_id
// 对应 PHP ext/filter，纯 C 实现，零外部依赖
//
// 设计说明：
//   - filter_var 的 mixed 参数由 CodeGenerator 用 wrapVar 包成 t_var
//   - 第三参数 array|int 联合类型，由 CodeGenerator 分发到
//     tphp_fn_filter_var (int options) 或 tphp_fn_filter_var_opt (array options)
//   - 验证失败返回 VAR_NULL()，净化失败返回处理后的 t_var
//   - 所有字符串输出走 str_pool_alloc，作用域结束自动释放
// ============================================================

#include <stdint.h>
#include <string.h>
#include <stdlib.h>
#include <ctype.h>
#include "types.h"
#include "array.h"

// 前向声明
static inline char* str_pool_alloc(int len);
static inline void tphp_rt_register(void* p, int is_array);

// ── 过滤器常量 ─────────────────────────────────────────────
// 验证过滤器
#define TPHP_CONST_FILTER_VALIDATE_INT       257
#define TPHP_CONST_FILTER_VALIDATE_BOOL      258
#define TPHP_CONST_FILTER_VALIDATE_FLOAT     259
#define TPHP_CONST_FILTER_VALIDATE_REGEXP    272
#define TPHP_CONST_FILTER_VALIDATE_URL       273
#define TPHP_CONST_FILTER_VALIDATE_EMAIL     274
#define TPHP_CONST_FILTER_VALIDATE_IP        275
#define TPHP_CONST_FILTER_VALIDATE_MAC       276
#define TPHP_CONST_FILTER_VALIDATE_DOMAIN    277

// 净化过滤器
#define TPHP_CONST_FILTER_SANITIZE_STRING            513
#define TPHP_CONST_FILTER_SANITIZE_ENCODED           514
#define TPHP_CONST_FILTER_SANITIZE_SPECIAL_CHARS     515
#define TPHP_CONST_FILTER_SANITIZE_EMAIL             517
#define TPHP_CONST_FILTER_SANITIZE_URL               518
#define TPHP_CONST_FILTER_SANITIZE_NUMBER_INT        519
#define TPHP_CONST_FILTER_SANITIZE_NUMBER_FLOAT      520
#define TPHP_CONST_FILTER_SANITIZE_ADD_SLASHES       523
#define TPHP_CONST_FILTER_SANITIZE_FULL_SPECIAL_CHARS 522

// 标志位
#define TPHP_CONST_FILTER_FLAG_NONE                 0
#define TPHP_CONST_FILTER_FLAG_ALLOW_OCTAL          1
#define TPHP_CONST_FILTER_FLAG_ALLOW_HEX            2
#define TPHP_CONST_FILTER_FLAG_STRIP_LOW            4
#define TPHP_CONST_FILTER_FLAG_STRIP_HIGH           8
#define TPHP_CONST_FILTER_FLAG_ENCODE_LOW           16
#define TPHP_CONST_FILTER_FLAG_ENCODE_HIGH          32
#define TPHP_CONST_FILTER_FLAG_ENCODE_AMP           64
#define TPHP_CONST_FILTER_FLAG_NO_ENCODE_QUOTES     128
#define TPHP_CONST_FILTER_FLAG_EMPTY_STRING_NULL    256
#define TPHP_CONST_FILTER_FLAG_ALLOW_FRACTION       4096
#define TPHP_CONST_FILTER_FLAG_ALLOW_THOUSAND       8192
#define TPHP_CONST_FILTER_FLAG_ALLOW_SCIENTIFIC     16384
#define TPHP_CONST_FILTER_FLAG_PATH_REQUIRED        0x100000
#define TPHP_CONST_FILTER_FLAG_QUERY_REQUIRED       0x200000
#define TPHP_CONST_FILTER_FLAG_IPV4                 0x100000
#define TPHP_CONST_FILTER_FLAG_IPV6                 0x200000
#define TPHP_CONST_FILTER_FLAG_NO_RES_RANGE         0x400000
#define TPHP_CONST_FILTER_FLAG_NO_PRIV_RANGE        0x800000
#define TPHP_CONST_FILTER_FLAG_EMAIL_UNICODE        0x100000
#define TPHP_CONST_FILTER_FLAG_HOSTNAME             0x100000

// ── 内部辅助：字符串转 int（支持八进制/十六进制） ─────────
static inline t_int _filter_parse_int(t_string s, t_int flags) {
    if (STR_PTR(s) == NULL || s.length == 0) return 0;
    int base = 10;
    int start = 0;
    int neg = 0;
    if (STR_PTR(s)[0] == '-') { neg = 1; start = 1; }
    else if (STR_PTR(s)[0] == '+') { start = 1; }
    // 八进制：0 开头且长度 > 1
    if ((flags & TPHP_CONST_FILTER_FLAG_ALLOW_OCTAL) && s.length - start >= 2 &&
        STR_PTR(s)[start] == '0' && STR_PTR(s)[start+1] != 'x') {
        base = 8;
    }
    // 十六进制：0x 开头
    if ((flags & TPHP_CONST_FILTER_FLAG_ALLOW_HEX) && s.length - start >= 2 &&
        STR_PTR(s)[start] == '0' && (STR_PTR(s)[start+1] == 'x' || STR_PTR(s)[start+1] == 'X')) {
        base = 16;
        start += 2;
    }
    char buf[64];
    int len = s.length - start;
    if (len <= 0 || len >= 64) return 0;
    memcpy(buf, STR_PTR(s) + start, (size_t)len);
    buf[len] = '\0';
    // 校验：每位必须合法
    for (int i = 0; i < len; i++) {
        char c = buf[i];
        if (base == 8 && !(c >= '0' && c <= '7')) return 0;
        if (base == 16 && !((c >= '0' && c <= '9') || (c >= 'a' && c <= 'f') || (c >= 'A' && c <= 'F'))) return 0;
        if (base == 10 && !(c >= '0' && c <= '9')) return 0;
    }
    t_int v = (t_int)strtoll(buf, NULL, base);
    return neg ? -v : v;
}

// ── 内部辅助：字符串转 float ─────────────────────────────
static inline t_float _filter_parse_float(t_string s, t_int flags) {
    if (STR_PTR(s) == NULL || s.length == 0) return 0.0;
    char buf[128];
    int len = s.length < 127 ? s.length : 127;
    memcpy(buf, STR_PTR(s), (size_t)len);
    buf[len] = '\0';
    // 校验：允许 [+-]?digits(.digits)?(e[+-]?digits)? 千分位 ',' 仅在 ALLOW_THOUSAND 时
    int i = 0;
    if (buf[i] == '+' || buf[i] == '-') i++;
    int has_digit = 0, has_dot = 0, has_exp = 0;
    for (; i < len; i++) {
        char c = buf[i];
        if (c >= '0' && c <= '9') { has_digit = 1; continue; }
        if (c == '.' && !has_dot && !has_exp) { has_dot = 1; continue; }
        if ((c == 'e' || c == 'E') && has_digit && !has_exp &&
            (flags & TPHP_CONST_FILTER_FLAG_ALLOW_SCIENTIFIC)) {
            has_exp = 1;
            if (i+1 < len && (buf[i+1] == '+' || buf[i+1] == '-')) i++;
            continue;
        }
        if (c == ',' && (flags & TPHP_CONST_FILTER_FLAG_ALLOW_THOUSAND)) {
            // 移除千分位
            memmove(buf + i, buf + i + 1, (size_t)(len - i));
            len--; i--;
            continue;
        }
        return 0.0; // 非法字符
    }
    if (!has_digit) return 0.0;
    return (t_float)strtod(buf, NULL);
}

// ── 内部辅助：校验 email（RFC 5321 简化版，ASCII only） ──────
static inline t_bool _filter_validate_email(t_string s) {
    if (STR_PTR(s) == NULL || s.length < 3 || s.length > 254) return false;
    // 找 @（必须有且仅有一个）
    int at = -1;
    for (int i = 0; i < s.length; i++) {
        if (STR_PTR(s)[i] == '@') {
            if (at >= 0) return false;
            at = i;
        }
    }
    if (at < 1 || at >= s.length - 1) return false;
    int local_len = at;
    int domain_len = s.length - at - 1;
    if (local_len > 64 || domain_len > 253) return false;
    // local part：允许 [a-zA-Z0-9._%+-]
    for (int i = 0; i < local_len; i++) {
        char c = STR_PTR(s)[i];
        if (!((c >= 'a' && c <= 'z') || (c >= 'A' && c <= 'Z') ||
              (c >= '0' && c <= '9') || c == '.' || c == '_' ||
              c == '%' || c == '+' || c == '-')) return false;
    }
    // domain part：必须含至少一个 '.', 每段 [a-zA-Z0-9-]
    int last_dot = -1;
    for (int i = at + 1; i < s.length; i++) {
        char c = STR_PTR(s)[i];
        if (c == '.') {
            if (i == at + 1 || i == s.length - 1) return false; // 点不能在开头/结尾
            if (i - 1 == last_dot + 1 && STR_PTR(s)[i-1] == '-') return false; // -.
            last_dot = i;
            continue;
        }
        if (!((c >= 'a' && c <= 'z') || (c >= 'A' && c <= 'Z') ||
              (c >= '0' && c <= '9') || c == '-')) return false;
        if (i == at + 1 && c == '-') return false; // 开头不能 -
        if (i == s.length - 1 && c == '-') return false; // 结尾不能 -
    }
    return last_dot > at + 1;
}

// ── 内部辅助：校验 IP 地址（IPv4 / IPv6） ──────────────────
static inline t_bool _filter_validate_ipv4(t_string s) {
    if (STR_PTR(s) == NULL || s.length < 7 || s.length > 15) return false;
    int seg = 0, seg_has_digit = 0, seg_count = 0;
    for (int i = 0; i < s.length; i++) {
        char c = STR_PTR(s)[i];
        if (c == '.') {
            if (!seg_has_digit) return false; // 空段（无数字）
            if (seg > 255) return false;
            seg = 0;
            seg_has_digit = 0;
            seg_count++;
            if (seg_count > 3) return false;
        } else if (c >= '0' && c <= '9') {
            seg = seg * 10 + (c - '0');
            seg_has_digit = 1;
            if (seg > 255) return false;
        } else {
            return false;
        }
    }
    if (!seg_has_digit || seg > 255 || seg_count != 3) return false;
    return true;
}

static inline t_bool _filter_validate_ipv6(t_string s) {
    if (STR_PTR(s) == NULL || s.length < 2 || s.length > 45) return false;
    // 简化校验：统计 ':' 数量（2-7）, 每段 1-4 个十六进制字符, 至少一个 ':'
    int colon = 0, seg_len = 0, has_seg = 0;
    int has_double_colon = 0;
    for (int i = 0; i < s.length; i++) {
        char c = STR_PTR(s)[i];
        if (c == ':') {
            if (i > 0 && STR_PTR(s)[i-1] == ':') {
                if (has_double_colon) return false; // 不能有多个 ::
                has_double_colon = 1;
            }
            colon++;
            seg_len = 0;
        } else if ((c >= '0' && c <= '9') || (c >= 'a' && c <= 'f') || (c >= 'A' && c <= 'F')) {
            seg_len++;
            if (seg_len > 4) return false;
            has_seg = 1;
        } else {
            return false; // IPv6 不含其他字符
        }
    }
    if (!has_seg) return false;
    if (colon < 2 || colon > 7) return false;
    return true;
}

// ── 内部辅助：校验 MAC 地址（xx:xx:xx:xx:xx:xx） ────────────
static inline t_bool _filter_validate_mac(t_string s) {
    if (STR_PTR(s) == NULL || s.length != 17) return false;
    for (int i = 0; i < 17; i++) {
        char c = STR_PTR(s)[i];
        if (i % 3 == 2) {
            if (c != ':' && c != '-') return false;
        } else {
            if (!((c >= '0' && c <= '9') || (c >= 'a' && c <= 'f') || (c >= 'A' && c <= 'F')))
                return false;
        }
    }
    return true;
}

// ── 内部辅助：校验域名 ────────────────────────────────────
static inline t_bool _filter_validate_domain(t_string s) {
    if (STR_PTR(s) == NULL || s.length < 1 || s.length > 253) return false;
    int seg_len = 0, seg_count = 0;
    for (int i = 0; i < s.length; i++) {
        char c = STR_PTR(s)[i];
        if (c == '.') {
            if (seg_len == 0) return false;
            if (seg_len > 63) return false;
            seg_len = 0;
            seg_count++;
        } else if ((c >= 'a' && c <= 'z') || (c >= 'A' && c <= 'Z') ||
                   (c >= '0' && c <= '9') || c == '-') {
            seg_len++;
            if (i == 0 && c == '-') return false;
            if (i == s.length - 1 && c == '-') return false;
        } else {
            return false;
        }
    }
    if (seg_len == 0 || seg_len > 63) return false;
    return seg_count >= 1; // 至少一个点
}

// ── 内部辅助：校验 URL（简化版，要求 scheme://host） ──────────
static inline t_bool _filter_validate_url(t_string s, t_int flags) {
    if (STR_PTR(s) == NULL || s.length < 8) return false;
    // 找 scheme://
    int scheme_end = -1;
    for (int i = 0; i < s.length - 2; i++) {
        if (STR_PTR(s)[i] == ':' && STR_PTR(s)[i+1] == '/' && STR_PTR(s)[i+2] == '/') {
            scheme_end = i;
            break;
        }
    }
    if (scheme_end <= 0) return false;
    // scheme 只能是 [a-zA-Z][a-zA-Z0-9+.-]*
    for (int i = 0; i < scheme_end; i++) {
        char c = STR_PTR(s)[i];
        if (i == 0) {
            if (!((c >= 'a' && c <= 'z') || (c >= 'A' && c <= 'Z'))) return false;
        } else {
            if (!((c >= 'a' && c <= 'z') || (c >= 'A' && c <= 'Z') ||
                  (c >= '0' && c <= '9') || c == '+' || c == '.' || c == '-')) return false;
        }
    }
    int host_start = scheme_end + 3;
    int host_end = s.length;
    int has_path = 0, has_query = 0;
    for (int i = host_start; i < s.length; i++) {
        char c = STR_PTR(s)[i];
        if (c == '/' && !has_path) { host_end = i; has_path = 1; continue; }
        if (c == '?' && !has_query) { if (!has_path) host_end = i; has_query = 1; continue; }
    }
    if (host_end <= host_start) return false; // host 不能为空
    // host 必须是合法域名或 IP
    t_string host = {(char*)STR_PTR(s) + host_start, host_end - host_start};
    if (!_filter_validate_domain(host) && !_filter_validate_ipv4(host)) return false;
    if ((flags & TPHP_CONST_FILTER_FLAG_PATH_REQUIRED) && !has_path) return false;
    if ((flags & TPHP_CONST_FILTER_FLAG_QUERY_REQUIRED) && !has_query) return false;
    return true;
}

// ── 内部辅助：HTML 特殊字符转义 ───────────────────────────
static inline t_string _filter_html_escape(t_string s, t_int flags) {
    if (STR_PTR(s) == NULL || s.length == 0) return s;
    // 计算输出长度
    int out_len = 0;
    for (int i = 0; i < s.length; i++) {
        unsigned char c = (unsigned char)STR_PTR(s)[i];
        if (c == '<' || c == '>') out_len += 4;       // &lt; &gt;
        else if (c == '&') out_len += 5;               // &amp;
        else if (c == '"' && !(flags & TPHP_CONST_FILTER_FLAG_NO_ENCODE_QUOTES)) out_len += 6; // &quot;
        else if (c == '\'' && !(flags & TPHP_CONST_FILTER_FLAG_NO_ENCODE_QUOTES)) out_len += 6; // &#039;
        else out_len++;
    }
    if (out_len == s.length) return s; // 无需转义
    char* buf = str_pool_alloc(out_len);
    if (buf == NULL) return s;
    int pos = 0;
    for (int i = 0; i < s.length; i++) {
        unsigned char c = (unsigned char)STR_PTR(s)[i];
        if (c == '<') { memcpy(buf+pos, "&lt;", 4); pos += 4; }
        else if (c == '>') { memcpy(buf+pos, "&gt;", 4); pos += 4; }
        else if (c == '&') { memcpy(buf+pos, "&amp;", 5); pos += 5; }
        else if (c == '"' && !(flags & TPHP_CONST_FILTER_FLAG_NO_ENCODE_QUOTES)) { memcpy(buf+pos, "&quot;", 6); pos += 6; }
        else if (c == '\'' && !(flags & TPHP_CONST_FILTER_FLAG_NO_ENCODE_QUOTES)) { memcpy(buf+pos, "&#039;", 6); pos += 6; }
        else buf[pos++] = (char)c;
    }
    buf[out_len] = '\0';
    return (t_string){buf, out_len};
}

// ── 内部辅助：URL 编码（按 PHP rawurlencode 规则） ──────────
static inline t_string _filter_url_encode(t_string s) {
    if (STR_PTR(s) == NULL || s.length == 0) return s;
    static const char hx[] = "0123456789ABCDEF";
    int extra = 0;
    for (int i = 0; i < s.length; i++) {
        unsigned char c = (unsigned char)STR_PTR(s)[i];
        // 仅 [A-Za-z0-9-_.~] 不编码
        if (!((c >= 'A' && c <= 'Z') || (c >= 'a' && c <= 'z') ||
              (c >= '0' && c <= '9') || c == '-' || c == '_' ||
              c == '.' || c == '~')) extra += 2;
    }
    if (extra == 0) return s;
    char* buf = str_pool_alloc(s.length + extra);
    if (buf == NULL) return s;
    int pos = 0;
    for (int i = 0; i < s.length; i++) {
        unsigned char c = (unsigned char)STR_PTR(s)[i];
        if ((c >= 'A' && c <= 'Z') || (c >= 'a' && c <= 'z') ||
            (c >= '0' && c <= '9') || c == '-' || c == '_' ||
            c == '.' || c == '~') {
            buf[pos++] = (char)c;
        } else {
            buf[pos++] = '%';
            buf[pos++] = hx[c >> 4];
            buf[pos++] = hx[c & 0xF];
        }
    }
    buf[pos] = '\0';
    return (t_string){buf, pos};
}

// ── 内部辅助：净化数字字符串（保留指定字符集） ──────────────
static inline t_string _filter_sanitize_number(t_string s, t_bool is_float) {
    if (STR_PTR(s) == NULL || s.length == 0) return s;
    char* buf = str_pool_alloc(s.length);
    if (buf == NULL) return s;
    int pos = 0;
    for (int i = 0; i < s.length; i++) {
        char c = STR_PTR(s)[i];
        if ((c >= '0' && c <= '9') || c == '+' || c == '-') buf[pos++] = c;
        else if (is_float && (c == '.' || c == ',' || c == 'e' || c == 'E')) buf[pos++] = c;
    }
    buf[pos] = '\0';
    return (t_string){buf, pos};
}

// ── 内部辅助：净化字符串（去标签 + 按标志位处理） ───────────
static inline t_string _filter_sanitize_string(t_string s, t_int flags) {
    if (STR_PTR(s) == NULL || s.length == 0) return s;
    // 第一阶段：去 HTML 标签（<...>）
    char* tmp = str_pool_alloc(s.length);
    if (tmp == NULL) return s;
    int tpos = 0, in_tag = 0;
    for (int i = 0; i < s.length; i++) {
        char c = STR_PTR(s)[i];
        if (c == '<') { in_tag = 1; continue; }
        if (c == '>') { in_tag = 0; continue; }
        if (!in_tag) tmp[tpos++] = c;
    }
    // 第二阶段：strip_low / strip_high / encode_low / encode_high / encode_amp
    static const char hx[] = "0123456789ABCDEF";
    int need = tpos;
    if (flags & (TPHP_CONST_FILTER_FLAG_ENCODE_LOW | TPHP_CONST_FILTER_FLAG_ENCODE_HIGH | TPHP_CONST_FILTER_FLAG_ENCODE_AMP)) {
        need = 0;
        for (int i = 0; i < tpos; i++) {
            unsigned char c = (unsigned char)tmp[i];
            int enc = 0;
            if ((flags & TPHP_CONST_FILTER_FLAG_ENCODE_LOW) && c < 32) enc = 1;
            if ((flags & TPHP_CONST_FILTER_FLAG_ENCODE_HIGH) && c > 127) enc = 1;
            if ((flags & TPHP_CONST_FILTER_FLAG_ENCODE_AMP) && c == '&') enc = 1;
            need += enc ? 3 : 1;
        }
    }
    char* out = str_pool_alloc(need > 0 ? need : 1);
    if (out == NULL) { return (t_string){tmp, tpos}; }
    int opos = 0;
    for (int i = 0; i < tpos; i++) {
        unsigned char c = (unsigned char)tmp[i];
        // strip 优先于 encode
        if ((flags & TPHP_CONST_FILTER_FLAG_STRIP_LOW) && c < 32) continue;
        if ((flags & TPHP_CONST_FILTER_FLAG_STRIP_HIGH) && c > 127) continue;
        int enc = 0;
        if ((flags & TPHP_CONST_FILTER_FLAG_ENCODE_LOW) && c < 32) enc = 1;
        if ((flags & TPHP_CONST_FILTER_FLAG_ENCODE_HIGH) && c > 127) enc = 1;
        if ((flags & TPHP_CONST_FILTER_FLAG_ENCODE_AMP) && c == '&') enc = 1;
        if (enc) {
            out[opos++] = '%';
            out[opos++] = hx[c >> 4];
            out[opos++] = hx[c & 0xF];
        } else {
            out[opos++] = (char)c;
        }
    }
    out[opos] = '\0';
    // EMPTY_STRING_NULL 标志
    if (opos == 0 && (flags & TPHP_CONST_FILTER_FLAG_EMPTY_STRING_NULL)) {
        return (t_string){NULL, 0};
    }
    return (t_string){out, opos};
}

// ── 内部辅助：净化 email（仅保留合法字符） ──────────────────
static inline t_string _filter_sanitize_email(t_string s) {
    if (STR_PTR(s) == NULL || s.length == 0) return s;
    char* buf = str_pool_alloc(s.length);
    if (buf == NULL) return s;
    int pos = 0;
    for (int i = 0; i < s.length; i++) {
        char c = STR_PTR(s)[i];
        if ((c >= 'a' && c <= 'z') || (c >= 'A' && c <= 'Z') ||
            (c >= '0' && c <= '9') || c == '.' || c == '_' ||
            c == '%' || c == '+' || c == '-' || c == '@') {
            buf[pos++] = c;
        }
    }
    buf[pos] = '\0';
    return (t_string){buf, pos};
}

// ── 内部辅助：净化 URL（仅保留 URL 合法字符） ──────────────
static inline t_string _filter_sanitize_url(t_string s) {
    if (STR_PTR(s) == NULL || s.length == 0) return s;
    char* buf = str_pool_alloc(s.length);
    if (buf == NULL) return s;
    int pos = 0;
    for (int i = 0; i < s.length; i++) {
        char c = STR_PTR(s)[i];
        if ((c >= 'a' && c <= 'z') || (c >= 'A' && c <= 'Z') ||
            (c >= '0' && c <= '9') || c == '-' || c == '_' ||
            c == '.' || c == '~' || c == ':' || c == '/' ||
            c == '?' || c == '#' || c == '[' || c == ']' ||
            c == '@' || c == '!' || c == '$' || c == '&' ||
            c == '\'' || c == '(' || c == ')' || c == '*' ||
            c == '+' || c == ',' || c == ';' || c == '=') {
            buf[pos++] = c;
        }
    }
    buf[pos] = '\0';
    return (t_string){buf, pos};
}

// ── 内部辅助：addslashes 复用 core.h 实现（不引入循环依赖，复制简化版） ──
static inline t_string _filter_addslashes(t_string s) {
    if (STR_PTR(s) == NULL || s.length == 0) return s;
    int extra = 0;
    for (int i = 0; i < s.length; i++) {
        char c = STR_PTR(s)[i];
        if (c == '\'' || c == '"' || c == '\\' || c == '\0') extra++;
    }
    if (extra == 0) return s;
    int newlen = s.length + extra;
    char* d = str_pool_alloc(newlen);
    if (d == NULL) return s;
    int pos = 0;
    for (int i = 0; i < s.length; i++) {
        char c = STR_PTR(s)[i];
        if (c == '\'' || c == '"' || c == '\\') d[pos++] = '\\';
        d[pos++] = c;
    }
    d[newlen] = '\0';
    return (t_string){d, newlen};
}

// ============================================================
// 主函数：filter_var (options 为 int 形式)
// ============================================================
static inline t_var tphp_fn_filter_var(t_var value, t_int filter, t_int flags) {
    switch (filter) {
    // ── 验证过滤器 ──
    case TPHP_CONST_FILTER_VALIDATE_INT: {
        if (value.type != TYPE_STRING && value.type != TYPE_INT) return VAR_NULL();
        t_string s = (value.type == TYPE_INT)
            ? tphp_rt_str_from_int(value.value._int)
            : value.value._string;
        // 空字符串直接失败
        if (STR_PTR(s) == NULL || s.length == 0) return VAR_NULL();
        t_int v = _filter_parse_int(s, flags);
        // 再次校验：原串是否符合（防止 strtoll 的部分解析）
        // 重新走一遍严格校验
        int start = 0;
        if (STR_PTR(s)[0] == '+' || STR_PTR(s)[0] == '-') start = 1;
        int ok = 1;
        int base = 10;
        if ((flags & TPHP_CONST_FILTER_FLAG_ALLOW_OCTAL) && s.length - start >= 2 &&
            STR_PTR(s)[start] == '0' && STR_PTR(s)[start+1] != 'x') base = 8;
        if ((flags & TPHP_CONST_FILTER_FLAG_ALLOW_HEX) && s.length - start >= 2 &&
            STR_PTR(s)[start] == '0' && (STR_PTR(s)[start+1] == 'x' || STR_PTR(s)[start+1] == 'X')) base = 16;
        for (int i = start; i < s.length; i++) {
            char c = STR_PTR(s)[i];
            if (base == 16 && (c == 'x' || c == 'X') && i == start + 1) continue;
            if (base == 8 && !(c >= '0' && c <= '7')) { ok = 0; break; }
            else if (base == 16 && !((c >= '0' && c <= '9') || (c >= 'a' && c <= 'f') || (c >= 'A' && c <= 'F'))) { ok = 0; break; }
            else if (base == 10 && !(c >= '0' && c <= '9')) {
                // 允许小数点（但本身就是非法整数）
                ok = 0; break;
            }
        }
        if (!ok) return VAR_NULL();
        return VAR_INT(v);
    }
    case TPHP_CONST_FILTER_VALIDATE_BOOL: {
        if (value.type == TYPE_BOOL) return VAR_BOOL(value.value._bool);
        if (value.type == TYPE_INT) return VAR_BOOL(value.value._int != 0);
        if (value.type != TYPE_STRING) return VAR_NULL();
        t_string s = value.value._string;
        if (STR_PTR(s) == NULL || s.length == 0) return VAR_NULL();
        // PHP 接受 "1"/"true"/"on"/"yes"（不区分大小写）→ true
        // "0"/"false"/"off"/"no"/"" → false，其他 → false（默认）或 NULL
        char buf[16];
        int len = s.length < 15 ? s.length : 15;
        for (int i = 0; i < len; i++) buf[i] = (char)tolower((unsigned char)STR_PTR(s)[i]);
        buf[len] = '\0';
        if (strcmp(buf, "1") == 0 || strcmp(buf, "true") == 0 ||
            strcmp(buf, "on") == 0 || strcmp(buf, "yes") == 0) return VAR_BOOL(true);
        if (strcmp(buf, "0") == 0 || strcmp(buf, "false") == 0 ||
            strcmp(buf, "off") == 0 || strcmp(buf, "no") == 0 ||
            strcmp(buf, "") == 0) return VAR_BOOL(false);
        return VAR_NULL();
    }
    case TPHP_CONST_FILTER_VALIDATE_FLOAT: {
        if (value.type == TYPE_FLOAT) return VAR_FLOAT(value.value._float);
        if (value.type == TYPE_INT) return VAR_FLOAT((t_float)value.value._int);
        if (value.type != TYPE_STRING) return VAR_NULL();
        t_string s = value.value._string;
        if (STR_PTR(s) == NULL || s.length == 0) return VAR_NULL();
        t_float v = _filter_parse_float(s, flags);
        // 二次校验：解析结果非 0 或原串本身就是 "0"/"0.0" 等
        // 简化：如果解析结果为 0 且原串不是纯 0 系列，认为失败
        if (v == 0.0) {
            // 检查原串是否全 0
            int all_zero = 1;
            for (int i = 0; i < s.length; i++) {
                char c = STR_PTR(s)[i];
                if (c == '+' || c == '-' || c == '.' || c == '0' || c == ',' ||
                    ((flags & TPHP_CONST_FILTER_FLAG_ALLOW_SCIENTIFIC) && (c == 'e' || c == 'E'))) continue;
                all_zero = 0;
                break;
            }
            if (!all_zero) return VAR_NULL();
        }
        return VAR_FLOAT(v);
    }
    case TPHP_CONST_FILTER_VALIDATE_URL: {
        if (value.type != TYPE_STRING) return VAR_NULL();
        t_bool ok = _filter_validate_url(value.value._string, flags);
        return ok ? VAR_STRING(tphp_rt_str_dup(value.value._string)) : VAR_NULL();
    }
    case TPHP_CONST_FILTER_VALIDATE_EMAIL: {
        if (value.type != TYPE_STRING) return VAR_NULL();
        t_bool ok = _filter_validate_email(value.value._string);
        return ok ? VAR_STRING(tphp_rt_str_dup(value.value._string)) : VAR_NULL();
    }
    case TPHP_CONST_FILTER_VALIDATE_IP: {
        if (value.type != TYPE_STRING) return VAR_NULL();
        t_string s = value.value._string;
        t_bool is_v4 = _filter_validate_ipv4(s);
        t_bool is_v6 = _filter_validate_ipv6(s);
        // 标志位过滤
        if ((flags & TPHP_CONST_FILTER_FLAG_IPV4) && !is_v4) return VAR_NULL();
        if ((flags & TPHP_CONST_FILTER_FLAG_IPV6) && !is_v6) return VAR_NULL();
        if (!is_v4 && !is_v6) return VAR_NULL();
        return VAR_STRING(tphp_rt_str_dup(s));
    }
    case TPHP_CONST_FILTER_VALIDATE_MAC: {
        if (value.type != TYPE_STRING) return VAR_NULL();
        t_bool ok = _filter_validate_mac(value.value._string);
        return ok ? VAR_STRING(tphp_rt_str_dup(value.value._string)) : VAR_NULL();
    }
    case TPHP_CONST_FILTER_VALIDATE_DOMAIN: {
        if (value.type != TYPE_STRING) return VAR_NULL();
        t_bool ok = _filter_validate_domain(value.value._string);
        return ok ? VAR_STRING(tphp_rt_str_dup(value.value._string)) : VAR_NULL();
    }
    case TPHP_CONST_FILTER_VALIDATE_REGEXP: {
        // AOT 不内置 PCRE，正则验证过滤器返回原字符串（不支持）
        // 调用方应使用 preg_* 系列函数
        if (value.type != TYPE_STRING) return VAR_NULL();
        return VAR_STRING(tphp_rt_str_dup(value.value._string));
    }

    // ── 净化过滤器 ──
    case TPHP_CONST_FILTER_SANITIZE_STRING:
    case TPHP_CONST_FILTER_SANITIZE_FULL_SPECIAL_CHARS: {
        if (value.type != TYPE_STRING) return VAR_NULL();
        if (filter == TPHP_CONST_FILTER_SANITIZE_FULL_SPECIAL_CHARS) {
            // 完整 HTML 实体（与 SANITIZE_STRING 区别：不剥标签，仅转义）
            return VAR_STRING(_filter_html_escape(value.value._string, flags));
        }
        return VAR_STRING(_filter_sanitize_string(value.value._string, flags));
    }
    case TPHP_CONST_FILTER_SANITIZE_SPECIAL_CHARS: {
        if (value.type != TYPE_STRING) return VAR_NULL();
        return VAR_STRING(_filter_html_escape(value.value._string, flags));
    }
    case TPHP_CONST_FILTER_SANITIZE_ENCODED: {
        if (value.type != TYPE_STRING) return VAR_NULL();
        return VAR_STRING(_filter_url_encode(value.value._string));
    }
    case TPHP_CONST_FILTER_SANITIZE_EMAIL: {
        if (value.type != TYPE_STRING) return VAR_NULL();
        return VAR_STRING(_filter_sanitize_email(value.value._string));
    }
    case TPHP_CONST_FILTER_SANITIZE_URL: {
        if (value.type != TYPE_STRING) return VAR_NULL();
        return VAR_STRING(_filter_sanitize_url(value.value._string));
    }
    case TPHP_CONST_FILTER_SANITIZE_NUMBER_INT: {
        if (value.type != TYPE_STRING) return VAR_NULL();
        return VAR_STRING(_filter_sanitize_number(value.value._string, false));
    }
    case TPHP_CONST_FILTER_SANITIZE_NUMBER_FLOAT: {
        if (value.type != TYPE_STRING) return VAR_NULL();
        return VAR_STRING(_filter_sanitize_number(value.value._string, true));
    }
    case TPHP_CONST_FILTER_SANITIZE_ADD_SLASHES: {
        if (value.type != TYPE_STRING) return VAR_NULL();
        return VAR_STRING(_filter_addslashes(value.value._string));
    }

    default:
        return VAR_NULL();
    }
}

// ============================================================
// filter_var (options 为 array 形式) — 支持 min_range/max_range
// ============================================================
static inline t_var tphp_fn_filter_var_opt(t_var value, t_int filter, t_array* options) {
    // 从 options["flags"] 提取标志位
    t_int flags = 0;
    if (options != NULL) {
        t_var* f = tphp_fn_arr_get_str(options, (t_string){"flags", 5});
        if (f != NULL && f->type == TYPE_INT) flags = f->value._int;
    }
    t_var result = tphp_fn_filter_var(value, filter, flags);
    // INT 过滤器额外支持 min_range/max_range
    if (filter == TPHP_CONST_FILTER_VALIDATE_INT && result.type == TYPE_INT && options != NULL) {
        t_var* mn = tphp_fn_arr_get_str(options, (t_string){"min_range", 9});
        t_var* mx = tphp_fn_arr_get_str(options, (t_string){"max_range", 9});
        if (mn != NULL && mn->type == TYPE_INT && result.value._int < mn->value._int)
            return VAR_NULL();
        if (mx != NULL && mx->type == TYPE_INT && result.value._int > mx->value._int)
            return VAR_NULL();
    }
    return result;
}

// ============================================================
// filter_list — 返回所有支持的过滤器名称列表
// ============================================================
static inline t_array* tphp_fn_filter_list(void) {
    t_array* out = tphp_fn_arr_create(16);
    if (out == NULL) return NULL;
    tphp_rt_register((void*)out, 1);
    static const char* names[] = {
        "int", "boolean", "float", "validate_regexp",
        "validate_url", "validate_email", "validate_ip",
        "validate_mac", "validate_domain",
        "string", "encoded", "special_chars", "email", "url",
        "number_int", "number_float", "add_slashes", "full_special_chars"
    };
    for (int i = 0; i < 18; i++) {
        int len = (int)strlen(names[i]);
        char* p = str_pool_alloc(len);
        if (p != NULL) { memcpy(p, names[i], (size_t)len); p[len] = '\0';
            out = tphp_fn_arr_push(out, VAR_STRING(((t_string){p, len}))); }
    }
    return out;
}

// ============================================================
// filter_id — 根据名称返回过滤器 ID
// ============================================================
static inline t_int tphp_fn_filter_id(t_string name) {
    if (STR_PTR(name) == NULL || name.length == 0) return -1;
    // 转小写比较
    char buf[32];
    int len = name.length < 31 ? name.length : 31;
    for (int i = 0; i < len; i++) buf[i] = (char)tolower((unsigned char)STR_PTR(name)[i]);
    buf[len] = '\0';
    if (strcmp(buf, "int") == 0) return TPHP_CONST_FILTER_VALIDATE_INT;
    if (strcmp(buf, "boolean") == 0) return TPHP_CONST_FILTER_VALIDATE_BOOL;
    if (strcmp(buf, "float") == 0) return TPHP_CONST_FILTER_VALIDATE_FLOAT;
    if (strcmp(buf, "validate_regexp") == 0) return TPHP_CONST_FILTER_VALIDATE_REGEXP;
    if (strcmp(buf, "validate_url") == 0) return TPHP_CONST_FILTER_VALIDATE_URL;
    if (strcmp(buf, "validate_email") == 0) return TPHP_CONST_FILTER_VALIDATE_EMAIL;
    if (strcmp(buf, "validate_ip") == 0) return TPHP_CONST_FILTER_VALIDATE_IP;
    if (strcmp(buf, "validate_mac") == 0) return TPHP_CONST_FILTER_VALIDATE_MAC;
    if (strcmp(buf, "validate_domain") == 0) return TPHP_CONST_FILTER_VALIDATE_DOMAIN;
    if (strcmp(buf, "string") == 0) return TPHP_CONST_FILTER_SANITIZE_STRING;
    if (strcmp(buf, "encoded") == 0) return TPHP_CONST_FILTER_SANITIZE_ENCODED;
    if (strcmp(buf, "special_chars") == 0) return TPHP_CONST_FILTER_SANITIZE_SPECIAL_CHARS;
    if (strcmp(buf, "email") == 0) return TPHP_CONST_FILTER_SANITIZE_EMAIL;
    if (strcmp(buf, "url") == 0) return TPHP_CONST_FILTER_SANITIZE_URL;
    if (strcmp(buf, "number_int") == 0) return TPHP_CONST_FILTER_SANITIZE_NUMBER_INT;
    if (strcmp(buf, "number_float") == 0) return TPHP_CONST_FILTER_SANITIZE_NUMBER_FLOAT;
    if (strcmp(buf, "add_slashes") == 0) return TPHP_CONST_FILTER_SANITIZE_ADD_SLASHES;
    if (strcmp(buf, "full_special_chars") == 0) return TPHP_CONST_FILTER_SANITIZE_FULL_SPECIAL_CHARS;
    return -1;
}
