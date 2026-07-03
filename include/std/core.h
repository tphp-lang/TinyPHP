#pragma once
// std/output.h — echo, var_dump, exit, isset, empty, unset
//   对应 PHP ext/standard 输出 + var 函数

// tphp_echo — 输出字符串原始字节到 stdout
//   二进制安全，不依赖 \0，不解析格式化占位符
//   PHP: echo "hello";
// ============================================================
static inline void tphp_fn_echo(t_string s) {
    if (STR_PTR(s) != NULL && s.length > 0) {
        fwrite(STR_PTR(s), 1, (size_t)s.length, stdout);
    }
}

// ============================================================
// tphp_var_dump — PHP var_dump 等价实现
//   根据 t_var 的 type 标签，格式化打印到 stdout
//   支持: int, float, bool, string, null, array, callback
//   PHP: var_dump($x);
// ============================================================
static void tphp_fn_var_dump_indent(int depth) {
    for (int i = 0; i < depth; i++) fputs("  ", stdout);
}

static void tphp_fn_var_dump_rec(t_var v, int depth);

static void tphp_fn_var_dump(t_var v) {
    tphp_fn_var_dump_rec(v, 0);
    fputc('\n', stdout);
}

static void tphp_fn_var_dump_rec(t_var v, int depth) {
    switch (v.type) {
    case TYPE_NULL:
        fputs("NULL", stdout);
        break;
    case TYPE_BOOL:
        fputs(v.value._bool ? "bool(true)" : "bool(false)", stdout);
        break;
    case TYPE_INT:
        fprintf(stdout, "int(%lld)", (long long)v.value._int);
        break;
    case TYPE_FLOAT:
        fprintf(stdout, "float(%g)", v.value._float);
        break;
    case TYPE_STRING: {
        int len = (v.value._string.length > 0) ? v.value._string.length : 0;
        fprintf(stdout, "string(%d) \"", len);
        if (len > 0 && STR_PTR_V(v.value._string) != NULL) {
            fwrite(STR_PTR_V(v.value._string), 1, (size_t)len, stdout);
        }
        fputc('"', stdout);
        break;
    }
    case TYPE_OBJECT: {
        const t_object* obj = (const t_object*)v.value._object;
        if (obj == NULL || obj->cls == NULL) {
            fputs("NULL", stdout);
        } else {
            fprintf(stdout, "object(%s)", obj->cls->name ? obj->cls->name : "?");
        }
        break;
    }
    case TYPE_CALLBACK:
        fputs("callable", stdout);
        break;
    case TYPE_ARRAY: {
        t_array* a = v.value._array;
        int count = tphp_fn_arr_count(a);
        fprintf(stdout, "array(%d) {\n", count);
        if (a != NULL) {
            for (int i = 0; i < count; i++) {
                t_var* key = &a->entries[i].key;
                t_var* val = &a->entries[i].val;

                tphp_fn_var_dump_indent(depth + 1);

                if (key->type == TYPE_INT) {
                    fprintf(stdout, "[%lld]=>\n", (long long)key->value._int);
                } else if (key->type == TYPE_STRING) {
                    int klen = (key->value._string.length > 0) ? key->value._string.length : 0;
                    fprintf(stdout, "[\"%.*s\"]=>\n",
                        klen,
                        (STR_PTR_V(key->value._string) != NULL && klen > 0) ? STR_PTR_V(key->value._string) : "");
                } else {
                    fprintf(stdout, "[?]=>\n");
                }

                tphp_fn_var_dump_indent(depth + 1);

                if (val->type == TYPE_NULL) {
                    fputs("NULL\n", stdout);
                } else {
                    tphp_fn_var_dump_rec(*val, depth + 1);
                }
                fputc('\n', stdout);
            }
        }
        tphp_fn_var_dump_indent(depth);
        fputc('}', stdout);
        break;
    }
    default:
        fputs("unknown", stdout);
        break;
    }
}

// ============================================================
// tphp_fn_exit — 终止程序
//   PHP: exit($code);  exit();
// ============================================================
static inline void tphp_fn_exit(t_int code) {
    exit((int)code);
}

// ============================================================
// tphp_fn_isset — 检测变量是否已设置且不为 null
//   PHP: isset($x);
//   非指针类型（int/float/bool/string 栈值）始终为 true
// ============================================================
static inline bool tphp_fn_isset(void* p) {
    return p != NULL;
}

// ============================================================
// tphp_fn_empty — PHP empty() 按类型分发
//   int:     v == 0
//   float:   v == 0.0
//   bool:    !v
//   string:  tphp_rt_str_is_falsy(s)   (空串或 "0")
//   null:    始终 true
// ============================================================
static inline bool tphp_fn_empty_int(t_int v)       { return v == 0; }
static inline bool tphp_fn_empty_float(t_float v)   { return v == 0.0; }
static inline bool tphp_fn_empty_bool(t_bool v)     { return !v; }
static inline bool tphp_fn_empty_str(t_string s)    { return tphp_rt_str_is_falsy(s); }
static inline bool tphp_fn_empty_null(void* p)      { (void)p; return true; }

// ============================================================
// tphp_fn_unset — PHP unset() 按类型释放
//   int/float/bool: 置零
//   string:         释放堆内存
//   array:          引用计数释放
//   object:         引用计数析构
//   null:           无操作
// ============================================================
static inline void tphp_fn_unset_str(t_string* s)    { tphp_rt_str_free(s); }
static inline void tphp_fn_unset_arr(t_array** a)    { if (*a) { tphp_fn_arr_free(*a); *a = NULL; } }
static inline void tphp_fn_unset_obj(void** o)       { tp_obj_release(*o); *o = NULL; }

// std/type.h — 类型检测/转换 (is_*, intval, gettype, getenv)
//   对应 PHP ext/standard type functions

// tphp_fn_is_* — t_var 类型检测（mixed/union 变量用）
//   静态类型变量在编译期直接生成 true/false，不调用这些函数
// ============================================================
static inline bool tphp_fn_is_int(t_var v)    { return v.type == TYPE_INT; }
static inline bool tphp_fn_is_float(t_var v)  { return v.type == TYPE_FLOAT; }
static inline bool tphp_fn_is_string(t_var v) { return v.type == TYPE_STRING; }
static inline bool tphp_fn_is_bool(t_var v)   { return v.type == TYPE_BOOL; }
static inline bool tphp_fn_is_array(t_var v)  { return v.type == TYPE_ARRAY; }
static inline bool tphp_fn_is_null(t_var v)   { return v.type == TYPE_NULL; }
static inline bool tphp_fn_is_object(t_var v) { return v.type == TYPE_OBJECT; }
static inline bool tphp_fn_is_callable(t_var v) { return v.type == TYPE_CALLBACK; }

/* ============================================================
 * Type conversion functions
/* ============================================================
 * ============================================================ */

static inline t_int tphp_fn_intval(t_var v) {
    if (v.type == TYPE_INT)   return v.value._int;
    if (v.type == TYPE_FLOAT) return (t_int)v.value._float;
    if (v.type == TYPE_BOOL)  return v.value._bool ? 1 : 0;
    if (v.type == TYPE_STRING) return tphp_rt_parse_int(v.value._string);
    return 0;
}

static inline t_float tphp_fn_floatval(t_var v) {
    if (v.type == TYPE_INT)   return (t_float)v.value._int;
    if (v.type == TYPE_FLOAT) return v.value._float;
    if (v.type == TYPE_BOOL)  return v.value._bool ? 1.0 : 0.0;
    if (v.type == TYPE_STRING) return tphp_rt_parse_float(v.value._string);
    return 0.0;
}

static inline t_string tphp_fn_strval(t_var v) {
    if (v.type == TYPE_INT)   return tphp_rt_str_from_int(v.value._int);
    if (v.type == TYPE_FLOAT) return tphp_rt_str_from_float(v.value._float);
    if (v.type == TYPE_BOOL)  return v.value._bool ? STR_LIT("1") : STR_LIT("");
    if (v.type == TYPE_STRING) return tphp_rt_str_dup(v.value._string);
    if (v.type == TYPE_NULL)  return (t_string){.data = NULL, .length = 0, .is_local = false};
    return STR_LIT("");
}

static inline t_bool tphp_fn_boolval(t_var v) {
    if (v.type == TYPE_INT)   return v.value._int != 0;
    if (v.type == TYPE_FLOAT) return v.value._float != 0.0;
    if (v.type == TYPE_BOOL)  return v.value._bool;
    if (v.type == TYPE_STRING) return !tphp_rt_str_is_falsy(v.value._string);
    return false;
}
static inline t_string tphp_fn_gettype(t_var v) {
    static const char *names[] = {
        [TYPE_NULL]     = "NULL",
        [TYPE_INT]      = "int",
        [TYPE_FLOAT]    = "float",
        [TYPE_BOOL]     = "bool",
        [TYPE_STRING]   = "string",
        [TYPE_ARRAY]    = "array",
        [TYPE_OBJECT]   = "object",
        [TYPE_CALLBACK] = "object",
    };
    const char *nm = (v.type <= TYPE_CALLBACK) ? names[v.type] : "unknown";
    return (t_string){(char*)nm, (int)strlen(nm)};
}

// ── getenv / putenv — 环境变量 ───────────────────────────────
static inline t_string tphp_fn_getenv(t_string key) {
    if (key.data == NULL) return (t_string){.data = NULL, .length = 0, .is_local = false};
    static char _env[4096];
    // 临时复制到可写缓冲区（getenv 返回的指针可能不安全）
    char tmp[256];
    int klen = key.length < 255 ? key.length : 255;
    memcpy(tmp, STR_PTR(key), (size_t)klen);
    tmp[klen] = '\0';
    char *val = getenv(tmp);
    if (val == NULL) return (t_string){.data = NULL, .length = 0, .is_local = false};
    int vlen = (int)strlen(val);
    if (vlen > 4095) vlen = 4095;
    memcpy(_env, val, (size_t)vlen);
    _env[vlen] = '\0';
    return (t_string){_env, vlen};
}

static inline void tphp_fn_putenv(t_string key) {
    if (key.data == NULL) return;
    static char _buf[1024];
    int len = key.length < 1023 ? key.length : 1023;
    memcpy(_buf, STR_PTR(key), (size_t)len);
    _buf[len] = '\0';
    putenv(_buf);
}

// ── 第二梯队字符串函数 ──────────────────────────────────────

// std/string.h — 字符串函数
//   对应 PHP ext/standard string functions

/* ============================================================
 * String functions
/* ============================================================
 * ============================================================ */

static inline t_int tphp_fn_strlen(t_string s) {
    return (STR_PTR(s) != NULL && s.length > 0) ? (t_int)s.length : 0;
}

static inline t_string tphp_fn_trim(t_string s) {
    if (STR_PTR(s) == NULL || s.length <= 0) return (t_string){.data = NULL, .length = 0, .is_local = false};
    int start = 0, end = s.length - 1;
    while (start <= end && (unsigned char)STR_PTR(s)[start] <= ' ') start++;
    while (end >= start && (unsigned char)STR_PTR(s)[end] <= ' ') end--;
    int len = end - start + 1;
    if (len <= 0) return (t_string){.data = NULL, .length = 0, .is_local = false};
    if (start == 0 && len == s.length) return s; // zero-alloc shortcut
    char *buf = str_pool_alloc(len);
    if (buf == NULL) return (t_string){.data = NULL, .length = 0, .is_local = false};
    memcpy(buf, STR_PTR(s) + start, (size_t)len);
    buf[len] = '\0';
    return (t_string){buf, len};
}

static inline t_string tphp_fn_ltrim(t_string s) {
    if (STR_PTR(s) == NULL || s.length <= 0) return (t_string){.data = NULL, .length = 0, .is_local = false};
    int start = 0;
    while (start < s.length && (unsigned char)STR_PTR(s)[start] <= ' ') start++;
    int len = s.length - start;
    if (len <= 0) return (t_string){.data = NULL, .length = 0, .is_local = false};
    if (start == 0) return s; // zero-alloc
    char *buf = str_pool_alloc(len);
    if (buf == NULL) return (t_string){.data = NULL, .length = 0, .is_local = false};
    memcpy(buf, STR_PTR(s) + start, (size_t)len);
    buf[len] = '\0';
    return (t_string){buf, len};
}

static inline t_string tphp_fn_rtrim(t_string s) {
    if (STR_PTR(s) == NULL || s.length <= 0) return (t_string){.data = NULL, .length = 0, .is_local = false};
    int end = s.length - 1;
    while (end >= 0 && (unsigned char)STR_PTR(s)[end] <= ' ') end--;
    int len = end + 1;
    if (len <= 0) return (t_string){.data = NULL, .length = 0, .is_local = false};
    if (len == s.length) return s; // zero-alloc
    char *buf = str_pool_alloc(len);
    if (buf == NULL) return (t_string){.data = NULL, .length = 0, .is_local = false};
    memcpy(buf, STR_PTR(s), (size_t)len);
    buf[len] = '\0';
    return (t_string){buf, len};
}

static inline t_string tphp_fn_substr(t_string s, t_int offset, t_int length) {
    if (STR_PTR(s) == NULL || s.length <= 0) return (t_string){.data = NULL, .length = 0, .is_local = false};
    int slen = s.length;
    int start = (int)offset;
    if (start < 0) start = slen + start;
    if (start < 0) start = 0;
    if (start >= slen) return (t_string){.data = NULL, .length = 0, .is_local = false};
    int len;
    if (length < 0) {
        len = slen - start + (int)length;
        if (len < 0) len = 0;
    } else if (length == 0) {
        len = slen - start;
    } else {
        len = (int)length;
        if (start + len > slen) len = slen - start;
    }
    if (len <= 0) return (t_string){.data = NULL, .length = 0, .is_local = false};
    if (start == 0 && len == slen) return s; // zero-alloc full copy
    char *buf = str_pool_alloc(len);
    if (buf == NULL) return (t_string){.data = NULL, .length = 0, .is_local = false};
    memcpy(buf, STR_PTR(s) + start, (size_t)len);
    buf[len] = '\0';
    return (t_string){buf, len};
}

static inline t_int tphp_fn_strpos(t_string haystack, t_string needle) {
    if (STR_PTR(haystack) == NULL || STR_PTR(needle) == NULL) return -1;
    if (needle.length <= 0) return 0;
    if (needle.length > haystack.length) return -1;
    for (int i = 0; i <= haystack.length - needle.length; i++) {
        if (memcmp(STR_PTR(haystack) + i, STR_PTR(needle), (size_t)needle.length) == 0)
            return (t_int)i;
    }
    return -1;
}

static inline t_bool tphp_fn_str_contains(t_string haystack, t_string needle) {
    return tphp_fn_strpos(haystack, needle) >= 0;
}

static inline t_string tphp_fn_sprintf(t_string fmt) {
    // No args — just copy format string
    return tphp_rt_str_dup(fmt);
}

static inline t_string tphp_fn_str_replace(t_string search, t_string replace, t_string subject) {
    if (STR_PTR(subject) == NULL || subject.length <= 0) return (t_string){.data = NULL, .length = 0, .is_local = false};
    if (STR_PTR(search) == NULL || search.length <= 0 || search.length > subject.length)
        return tphp_rt_str_dup(subject);
    // Count occurrences
    int count = 0;
    for (int i = 0; i <= subject.length - search.length; i++) {
        if (memcmp(STR_PTR(subject) + i, STR_PTR(search), (size_t)search.length) == 0) {
            count++; i += search.length - 1;
        }
    }
    if (count == 0) return tphp_rt_str_dup(subject);
    // Calculate new length and build result
    int new_len = subject.length + count * (replace.length - search.length);
    if (new_len <= 0) return (t_string){.data = NULL, .length = 0, .is_local = false};
    char *buf = str_pool_alloc(new_len);
    if (buf == NULL) return (t_string){.data = NULL, .length = 0, .is_local = false};
    int pos = 0, si = 0;
    while (si < subject.length) {
        if (si <= subject.length - search.length &&
            memcmp(STR_PTR(subject) + si, STR_PTR(search), (size_t)search.length) == 0) {
            memcpy(buf + pos, STR_PTR(replace), (size_t)replace.length);
            pos += replace.length;
            si += search.length;
        } else {
            buf[pos++] = STR_PTR(subject)[si++];
        }
    }
    buf[new_len] = '\0';
    return (t_string){buf, new_len};
}

/* ============================================================
 * String (case conversion)
/* ============================================================
 * ============================================================ */

static inline t_string tphp_fn_strtolower(t_string s) {
    if (STR_PTR(s) == NULL || s.length <= 0) return (t_string){.data = NULL, .length = 0, .is_local = false};
    int changed = 0;
    for (int i = 0; i < s.length; i++) {
        unsigned char c = (unsigned char)STR_PTR(s)[i];
        if (c >= 'A' && c <= 'Z') { changed = 1; break; }
    }
    if (!changed) return s; // zero-alloc
    char *buf = str_pool_alloc(s.length);
    if (buf == NULL) return (t_string){.data = NULL, .length = 0, .is_local = false};
    for (int i = 0; i < s.length; i++) {
        unsigned char c = (unsigned char)STR_PTR(s)[i];
        buf[i] = (c >= 'A' && c <= 'Z') ? (char)(c + 32) : (char)c;
    }
    buf[s.length] = '\0';
    return (t_string){buf, s.length};
}

static inline t_string tphp_fn_strtoupper(t_string s) {
    if (STR_PTR(s) == NULL || s.length <= 0) return (t_string){.data = NULL, .length = 0, .is_local = false};
    int changed = 0;
    for (int i = 0; i < s.length; i++) {
        unsigned char c = (unsigned char)STR_PTR(s)[i];
        if (c >= 'a' && c <= 'z') { changed = 1; break; }
    }
    if (!changed) return s; // zero-alloc
    char *buf = str_pool_alloc(s.length);
    if (buf == NULL) return (t_string){.data = NULL, .length = 0, .is_local = false};
    for (int i = 0; i < s.length; i++) {
        unsigned char c = (unsigned char)STR_PTR(s)[i];
        buf[i] = (c >= 'a' && c <= 'z') ? (char)(c - 32) : (char)c;
    }
    buf[s.length] = '\0';
    return (t_string){buf, s.length};
}

static inline t_int tphp_fn_ord(t_string s) {
    if (STR_PTR(s) == NULL || s.length < 1) return 0;
    return (t_int)(unsigned char)STR_PTR(s)[0];
}

static inline t_string tphp_fn_chr(t_int n) {
    static char _chr[2];
    _chr[0] = (char)(n & 0xFF);
    _chr[1] = '\0';
    return (t_string){_chr, 1};
}

// ── str_starts_with / str_ends_with — PHP 8.0+ ──────────────
static inline t_bool tphp_fn_str_starts_with(t_string haystack, t_string needle) {
    if (STR_PTR(haystack) == NULL || STR_PTR(needle) == NULL) return false;
    if (needle.length == 0) return true;
    if (needle.length > haystack.length) return false;
    return memcmp(STR_PTR(haystack), STR_PTR(needle), (size_t)needle.length) == 0;
}

static inline t_bool tphp_fn_str_ends_with(t_string haystack, t_string needle) {
    if (STR_PTR(haystack) == NULL || STR_PTR(needle) == NULL) return false;
    if (needle.length == 0) return true;
    if (needle.length > haystack.length) return false;
    return memcmp(STR_PTR(haystack) + haystack.length - needle.length,
                  STR_PTR(needle), (size_t)needle.length) == 0;
}

// ── is_numeric($str) — 检查是否为数值字符串 ──────────────────
static inline t_bool tphp_fn_is_numeric_str(t_string s) {
    if (STR_PTR(s) == NULL || s.length == 0) return false;
    // 需要 null-terminated 副本给 strto*
    char buf[256];
    int len = s.length < 255 ? s.length : 255;
    memcpy(buf, STR_PTR(s), (size_t)len);
    buf[len] = '\0';
    char *end = NULL;
    strtoll(buf, &end, 10);
    if (end == buf + len) return true;
    strtod(buf, &end);
    if (end == buf + len) return true;
    return false;
}


// ucfirst($s) — 首字符大写，其余不变
static inline t_string tphp_fn_ucfirst(t_string s) {
    if (STR_PTR(s) == NULL || s.length == 0) return s;
    if (STR_PTR(s)[0] < 'a' || STR_PTR(s)[0] > 'z') return s; // 已是零分配
    char *d = str_pool_alloc(s.length);
    if (d == NULL) return s;
    d[0] = (char)(STR_PTR(s)[0] - 32);
    if (s.length > 1) memcpy(d + 1, STR_PTR(s) + 1, (size_t)(s.length - 1));
    d[s.length] = '\0';
    return (t_string){d, s.length};
}

// lcfirst($s) — 首字符小写，其余不变
static inline t_string tphp_fn_lcfirst(t_string s) {
    if (STR_PTR(s) == NULL || s.length == 0) return s;
    if (STR_PTR(s)[0] < 'A' || STR_PTR(s)[0] > 'Z') return s;
    char *d = str_pool_alloc(s.length);
    if (d == NULL) return s;
    d[0] = (char)(STR_PTR(s)[0] + 32);
    if (s.length > 1) memcpy(d + 1, STR_PTR(s) + 1, (size_t)(s.length - 1));
    d[s.length] = '\0';
    return (t_string){d, s.length};
}

// strrev($s) — 反转字符串
static inline t_string tphp_fn_strrev(t_string s) {
    if (STR_PTR(s) == NULL || s.length <= 0) return s;
    char *d = str_pool_alloc(s.length);
    if (d == NULL) return s;
    for (int i = 0; i < s.length; i++) d[i] = STR_PTR(s)[s.length - 1 - i];
    d[s.length] = '\0';
    return (t_string){d, s.length};
}

// str_repeat($s, $n) — 重复字符串
static inline t_string tphp_fn_str_repeat(t_string s, t_int n) {
    if (STR_PTR(s) == NULL || s.length <= 0) return (t_string){.data = NULL, .length = 0, .is_local = false};
    if (n < 0) {
        tphp_fn_error((t_string){"str_repeat(): Argument #2 ($times) must be greater than or equal to 0", 71}, "<php>", 0);
        return (t_string){.data = NULL, .length = 0, .is_local = false};
    }
    if (n == 0) return (t_string){.data = NULL, .length = 0, .is_local = false};
    int total = s.length * (int)n;
    if (total <= 0 || total > 0x3FFFFF) return (t_string){.data = NULL, .length = 0, .is_local = false};
    char *d = str_pool_alloc(total);
    if (d == NULL) return (t_string){.data = NULL, .length = 0, .is_local = false};
    for (int i = 0; i < (int)n; i++)
        memcpy(d + i * s.length, STR_PTR(s), (size_t)s.length);
    d[total] = '\0';
    return (t_string){d, total};
}

// str_split($s, $chunk?) — 分割字符串为数组，默认 chunk=1
static inline t_array* tphp_fn_str_split(t_string s, t_int chunk) {
    if (chunk < 1) {
        tphp_fn_error((t_string){"str_split(): Argument #2 ($length) must be greater than 0", 56}, "<php>", 0);
        return NULL;
    }
    t_array* out = tphp_fn_arr_create(0);
    if (out == NULL) return NULL;
    tphp_rt_register((void*)out, 1);
    if (STR_PTR(s) == NULL || s.length <= 0) return out;
    int pieces = (s.length + (int)chunk - 1) / (int)chunk;
    for (int i = 0; i < pieces; i++) {
        int start = i * (int)chunk;
        int len = (int)chunk;
        if (start + len > s.length) len = s.length - start;
        char *p = str_pool_alloc(len);
        if (p == NULL) break;
        memcpy(p, STR_PTR(s) + start, (size_t)len);
        p[len] = '\0';
        out = tphp_fn_arr_push(out, VAR_STRING(((t_string){p, len})));
    }
    return out;
}

// str_pad($s, $len, $pad?, $type?) — 填充字符串
// type: 0=RIGHT(默认) / 1=LEFT / 2=BOTH
static inline t_string tphp_fn_str_pad(t_string s, t_int len, t_string pad, t_int type) {
    if (STR_PTR(s) == NULL && pad.data == NULL) return (t_string){.data = NULL, .length = 0, .is_local = false};
    int slen = (STR_PTR(s) != NULL) ? s.length : 0;
    int plen = (STR_PTR(pad) != NULL && pad.length > 0) ? pad.length : 1;
    if (len <= slen) return s; // 零分配返回原串
    char *d = str_pool_alloc(len);
    if (d == NULL) return s;
    int gap = len - slen;
    if (type == 1) { // LEFT
        for (int i = 0; i < gap; i++) d[i] = (STR_PTR(pad) != NULL) ? STR_PTR(pad)[i % plen] : ' ';
        if (slen > 0) memcpy(d + gap, STR_PTR(s), (size_t)slen);
    } else if (type == 2) { // BOTH
        int left = gap / 2;
        for (int i = 0; i < left; i++) d[i] = (STR_PTR(pad) != NULL) ? STR_PTR(pad)[i % plen] : ' ';
        if (slen > 0) memcpy(d + left, STR_PTR(s), (size_t)slen);
        int right = gap - left;
        for (int i = 0; i < right; i++) d[left + slen + i] = (STR_PTR(pad) != NULL) ? STR_PTR(pad)[(left + slen + i) % plen] : ' ';
    } else { // RIGHT (default)
        if (slen > 0) memcpy(d, STR_PTR(s), (size_t)slen);
        for (int i = 0; i < gap; i++) d[slen + i] = (STR_PTR(pad) != NULL) ? STR_PTR(pad)[i % plen] : ' ';
    }
    d[len] = '\0';
    return (t_string){d, len};
}

// substr_count($h, $n) — 统计子串出现次数
static inline t_int tphp_fn_substr_count(t_string haystack, t_string needle) {
    if (STR_PTR(haystack) == NULL || STR_PTR(needle) == NULL) return 0;
    if (needle.length == 0 || needle.length > haystack.length) return 0;
    t_int count = 0;
    for (int i = 0; i <= haystack.length - needle.length; i++) {
        if (memcmp(STR_PTR(haystack) + i, STR_PTR(needle), (size_t)needle.length) == 0) {
            count++;
            i += needle.length - 1;
        }
    }
    return count;
}

// str_shuffle($s) — 随机打乱字符串
static inline t_int tphp_fn_rand_int(t_int min, t_int max);

static inline t_string tphp_fn_str_shuffle(t_string s) {
    if (STR_PTR(s) == NULL || s.length <= 0) return s;
    char *d = str_pool_alloc(s.length);
    if (d == NULL) return s;
    memcpy(d, STR_PTR(s), (size_t)s.length);
    // Fisher-Yates
    for (int i = s.length - 1; i > 0; i--) {
        int j = (int)tphp_fn_rand_int(0, i);
        if (j != i) { char t = d[i]; d[i] = d[j]; d[j] = t; }
    }
    d[s.length] = '\0';
    return (t_string){d, s.length};
}

// addslashes($s) — 转义 ' " \ \0
static inline t_string tphp_fn_addslashes(t_string s) {
    if (STR_PTR(s) == NULL || s.length == 0) return s;
    // Pass 1: 数需要转义的字符
    int extra = 0;
    for (int i = 0; i < s.length; i++) {
        char c = STR_PTR(s)[i];
        if (c == '\'' || c == '"' || c == '\\' || c == '\0') extra++;
    }
    if (extra == 0) return s; // 零分配
    int newlen = s.length + extra;
    char *d = str_pool_alloc(newlen);
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

// stripslashes($s) — 反转义
static inline t_string tphp_fn_stripslashes(t_string s) {
    if (STR_PTR(s) == NULL || s.length == 0) return s;
    // Pass 1: 数实际上需要的字符数
    int newlen = 0;
    for (int i = 0; i < s.length; i++) {
        if (STR_PTR(s)[i] == '\\' && i + 1 < s.length) { i++; } // 跳转义符
        newlen++;
    }
    if (newlen == s.length) return s; // 零分配
    char *d = str_pool_alloc(newlen);
    if (d == NULL) return s;
    int pos = 0;
    for (int i = 0; i < s.length; i++) {
        if (STR_PTR(s)[i] == '\\' && i + 1 < s.length) { i++; } // 跳过 \\
        d[pos++] = STR_PTR(s)[i];
    }
    d[newlen] = '\0';
    return (t_string){d, newlen};
}

// bin2hex($s) / hex2bin($s) — 二进制 ↔ 十六进制
static inline t_string tphp_fn_bin2hex(t_string s) {
    if (STR_PTR(s) == NULL || s.length == 0) return (t_string){.data = NULL, .length = 0, .is_local = false};
    static const char hexc[] = "0123456789abcdef";
    char *d = str_pool_alloc(s.length * 2);
    if (d == NULL) return (t_string){.data = NULL, .length = 0, .is_local = false};
    for (int i = 0; i < s.length; i++) {
        d[i*2]   = hexc[(unsigned char)STR_PTR(s)[i] >> 4];
        d[i*2+1] = hexc[(unsigned char)STR_PTR(s)[i] & 0xF];
    }
    d[s.length*2] = '\0';
    return (t_string){d, s.length * 2};
}

static inline int _is_hex(char c) {
    return (c >= '0' && c <= '9') || (c >= 'a' && c <= 'f') || (c >= 'A' && c <= 'F');
}
static int _hexval(char x); // 前置声明

static inline t_string tphp_fn_hex2bin(t_string s) {
    if (STR_PTR(s) == NULL || s.length == 0) return (t_string){.data = NULL, .length = 0, .is_local = false};
    if (s.length % 2 != 0) {
        tphp_fn_error((t_string){"hex2bin(): Hexadecimal input string must have an even length", 58}, "<php>", 0);
        return (t_string){.data = NULL, .length = 0, .is_local = false};
    }
    // validate characters
    for (int i = 0; i < s.length; i++) {
        if (!_is_hex(STR_PTR(s)[i])) {
            tphp_fn_error((t_string){"hex2bin(): Input string must be hexadecimal string", 50}, "<php>", 0);
            return (t_string){.data = NULL, .length = 0, .is_local = false};
        }
    }
    int outlen = s.length / 2;
    char *d = str_pool_alloc(outlen);
    if (d == NULL) return (t_string){.data = NULL, .length = 0, .is_local = false};
    for (int i = 0; i < outlen; i++) {
        int hi = _hexval(STR_PTR(s)[i*2]), lo = _hexval(STR_PTR(s)[i*2+1]);
        d[i] = (char)((hi << 4) | lo);
    }
    d[outlen] = '\0';
    return (t_string){d, outlen};
}

// urlencode($s) / urldecode($s)
static inline int _is_url_safe(char c) {
    return (c >= 'A' && c <= 'Z') || (c >= 'a' && c <= 'z') ||
           (c >= '0' && c <= '9') || c == '-' || c == '_' || c == '.' || c == '~';
}

static inline t_string tphp_fn_urlencode(t_string s) {
    if (STR_PTR(s) == NULL || s.length == 0) return s;
    // Pass 1: 计算需要%编码的字符
    int extra = 0;
    for (int i = 0; i < s.length; i++) {
        if (!_is_url_safe(STR_PTR(s)[i])) extra += 2;
    }
    if (extra == 0) return s; // 零分配
    char *d = str_pool_alloc(s.length + extra);
    if (d == NULL) return s;
    static const char hx[] = "0123456789ABCDEF";
    int pos = 0;
    for (int i = 0; i < s.length; i++) {
        unsigned char c = (unsigned char)STR_PTR(s)[i];
        if (_is_url_safe((char)c)) { d[pos++] = (char)c; }
        else { d[pos++] = '%'; d[pos++] = hx[c >> 4]; d[pos++] = hx[c & 0xF]; }
    }
    d[pos] = '\0';
    return (t_string){d, pos};
}

static inline int _hexval(char x) {
    if (x >= '0' && x <= '9') return x - '0';
    if (x >= 'A' && x <= 'F') return x - 'A' + 10;
    if (x >= 'a' && x <= 'f') return x - 'a' + 10;
    return 0;
}

static inline t_string tphp_fn_urldecode(t_string s) {
    if (STR_PTR(s) == NULL || s.length == 0) return s;
    // count transformations
    int extra = 0;
    for (int i = 0; i < s.length; i++) {
        if (STR_PTR(s)[i] == '%' && i + 2 < s.length) { extra++; i += 2; }
        else if (STR_PTR(s)[i] == '+') extra++;
    }
    if (extra == 0) return s; // zero-alloc
    char *d = str_pool_alloc(s.length);
    if (d == NULL) return s;
    int pos = 0;
    for (int i = 0; i < s.length; i++) {
        if (STR_PTR(s)[i] == '%' && i + 2 < s.length) {
            int hi = _hexval(STR_PTR(s)[i+1]), lo = _hexval(STR_PTR(s)[i+2]);
            d[pos++] = (char)((hi << 4) | lo);
            i += 2;
        } else if (STR_PTR(s)[i] == '+') {
            d[pos++] = ' ';
        } else {
            d[pos++] = STR_PTR(s)[i];
        }
    }
    d[pos] = '\0';
    return (t_string){d, pos};
}

// ── 第三梯队 ────────────────────────────────────────────────

// parse_str($s) — 解析 query string → 数组 (写入全局作用域风格的简单版)
// 仅返回 key=val 对构成的数组。不支持嵌套键 (a[b]=c)。
static inline t_array* tphp_fn_parse_str(t_string s) {
    t_array *out = tphp_fn_arr_create(8);
    if (out == NULL) return NULL;
    tphp_rt_register((void*)out, 1);
    if (STR_PTR(s) == NULL || s.length == 0) return out;
    int start = 0;
    for (int i = 0; i <= s.length; i++) {
        if (i == s.length || STR_PTR(s)[i] == '&') {
            int seglen = i - start;
            if (seglen > 0) {
                char seg[256]; int sl = seglen < 255 ? seglen : 255;
                memcpy(seg, STR_PTR(s) + start, (size_t)sl); seg[sl] = '\0';
                // 解码 %XX
                char dk[256]; int dp = 0;
                for (int j = 0; j < sl; j++) {
                    if (seg[j] == '%' && j+2 < sl) { int hi=_hexval(seg[j+1]),lo=_hexval(seg[j+2]); seg[j]=(char)((hi<<4)|lo); memmove(seg+j+1,seg+j+3,(size_t)(sl-j-2)); sl-=2; }
                    if (seg[j] == '+') seg[j] = ' ';
                }
                // 找 =
                int eq = -1;
                for (int j = 0; j < sl; j++) if (seg[j] == '=') { eq = j; break; }
                t_string key, val;
                if (eq >= 0) { key = (t_string){seg, eq}; val = (t_string){seg+eq+1, sl-eq-1}; }
                else { key = (t_string){seg, sl}; val = (t_string){.data = NULL, .length = 0, .is_local = false}; }
                out = tphp_fn_arr_set_str(out, key, VAR_STRING(val));
            }
            start = i + 1;
        }
    }
    return out;
}

// parse_url($u) — 解析 URL → 关联数组 (scheme,host,port,path,query)
static inline t_array* tphp_fn_parse_url(t_string u) {
    t_array *out = tphp_fn_arr_create(8);
    if (out == NULL) return NULL;
    tphp_rt_register((void*)out, 1);
    if (u.data == NULL || u.length == 0) return out;

    int pos = 0, len = u.length;

    // scheme://
    int sch = -1;
    for (int i = 0; i < len-2; i++) { if (STR_PTR(u)[i]==':' && STR_PTR(u)[i+1]=='/' && STR_PTR(u)[i+2]=='/') { sch=i; break; } }
    if (sch > 0) {
        t_string _sc = {STR_PTR(u), sch};
        out = tphp_fn_arr_set_str(out, (t_string){"scheme",6}, VAR_STRING(_sc));
        pos = sch + 3;
    }

    // host[:port][/path][?query]
    int host_end = -1, port_n = -1, path_s = -1, q_s = -1;
    for (int i = pos; i < len; i++) {
        if (STR_PTR(u)[i] == ':') { if (port_n < 0) { if (host_end < 0) host_end = i; port_n = i; } }
        else if (STR_PTR(u)[i] == '/') { if (path_s < 0) { if (host_end < 0) host_end = i; path_s = i; } }
        else if (STR_PTR(u)[i] == '?') { if (q_s < 0) { if (host_end < 0) host_end = i; if (path_s < 0) path_s = i; q_s = i; } }
    }
    if (host_end < 0) host_end = len;
    if (host_end > pos) {
        t_string _h = {STR_PTR(u)+pos, host_end-pos};
        out = tphp_fn_arr_set_str(out, (t_string){"host",4}, VAR_STRING(_h));
    }

    // port: from host_end+1 to next / or ?
    if (port_n >= 0) {
        int pe = (path_s >= 0) ? path_s : ((q_s >= 0) ? q_s : len);
        if (pe > port_n + 1) {
            t_string ps = {STR_PTR(u)+port_n+1, pe-port_n-1};
            out = tphp_fn_arr_set_str(out, (t_string){"port",4}, VAR_STRING(ps));
        }
    }

    if (path_s >= 0 && path_s < len) {
        int pe = (q_s >= 0 && q_s < len) ? q_s : len;
        if (pe > path_s) {
            t_string _pa = {STR_PTR(u)+path_s, pe-path_s};
            out = tphp_fn_arr_set_str(out, (t_string){"path",4}, VAR_STRING(_pa));
        }
    }

    if (q_s >= 0 && q_s < len-1) {
        t_string _q = {STR_PTR(u)+q_s+1, len-q_s-1};
        out = tphp_fn_arr_set_str(out, (t_string){"query",5}, VAR_STRING(_q));
    }

    return out;
}

// strtr($s, $from, $to?) — 字符/字符串翻译
static inline t_string tphp_fn_strtr2(t_string s, t_string from, t_string to) {
    if (STR_PTR(s) == NULL || from.data == NULL) return s;
    // 预建翻译表 (仅 ASCII 0-127)
    char map[128]; for (int i = 0; i < 128; i++) map[i] = (char)i;
    int flen = from.length < to.length ? from.length : to.length;
    for (int i = 0; i < flen; i++) map[(unsigned char)STR_PTR(from)[i]] = to.data[i];

    char *d = str_pool_alloc(s.length);
    if (d == NULL) return s;
    for (int i = 0; i < s.length; i++) d[i] = (unsigned char)STR_PTR(s)[i] < 128 ? map[(unsigned char)STR_PTR(s)[i]] : STR_PTR(s)[i];
    d[s.length] = '\0';
    return (t_string){d, s.length};
}

// std/array_core.h — 核心数组函数 (原 builtin.h 181-400行, 拆分时漏掉)
//   array_push/pop, in_array, array_key_exists, array_keys/values, array_merge,
//   implode, explode, max, min

// ── array_push($arr, $val) → 尾部追加, 返回新长度 ────────────
static inline t_int tphp_fn_array_push(t_array** a, t_var val) {
    if (a == NULL || *a == NULL) return 0;
    *a = tphp_fn_arr_push(*a, val);
    return (*a)->length;
}

// ── array_pop($arr) → 弹出尾部元素, 返回 t_var ────────────────
static inline t_var tphp_fn_array_pop(t_array** a) {
    t_var out = VAR_NULL();
    if (a != NULL && *a != NULL) tphp_fn_arr_pop(*a, &out);
    return out;
}

// ── in_array($needle, $haystack) → 值是否存在 ────────────────
static inline bool tphp_fn_in_array(t_var needle, t_array* a) {
    if (a == NULL) return false;
    for (int i = 0; i < a->length; i++) {
        t_var *v = &a->entries[i].val;
        if (needle.type == TYPE_INT && v->type == TYPE_INT) {
            if (needle.value._int == v->value._int) return true;
        } else if (needle.type == TYPE_STRING && v->type == TYPE_STRING) {
            if (tphp_rt_str_eq(needle.value._string, v->value._string)) return true;
        } else if (needle.type == TYPE_BOOL && v->type == TYPE_BOOL) {
            if (needle.value._bool == v->value._bool) return true;
        } else if (needle.type == TYPE_NULL && v->type == TYPE_NULL) {
            return true;
        }
    }
    return false;
}

// ── array_key_exists($key, $arr) → 键是否存在 ───────────────
static inline bool tphp_fn_array_key_exists_int(t_int key, t_array* a) {
    return tphp_fn_arr_get_int(a, key) != NULL;
}
static inline bool tphp_fn_array_key_exists_str(t_string key, t_array* a) {
    return tphp_fn_arr_get_str(a, key) != NULL;
}

// ── array_keys($arr) → 所有 key 组成新数组 ──────────────────
static inline t_array* tphp_fn_array_keys(t_array* a) {
    t_array* out = tphp_fn_arr_create(a ? a->length : 0);
    if (a == NULL || out == NULL) return out;
    tphp_rt_register((void*)out, 1);
    for (int i = 0; i < a->length; i++) {
        t_var k = a->entries[i].key;
        t_var kcopy = k;
        if (k.type == TYPE_STRING && k.value._string.length > 0) {
            kcopy.value._string = tphp_rt_str_dup(k.value._string);
        }
        out = tphp_fn_arr_push(out, kcopy);
    }
    return out;
}

// ── array_values($arr) → 所有 value 组成新数组 ────────────────
static inline t_array* tphp_fn_array_values(t_array* a) {
    t_array* out = tphp_fn_arr_create(a ? a->length : 0);
    if (a == NULL || out == NULL) return out;
    tphp_rt_register((void*)out, 1);
    for (int i = 0; i < a->length; i++) {
        out = tphp_fn_arr_push(out, a->entries[i].val);
    }
    return out;
}

// ── array_merge($a, $b) → 合并, int key 重新索引 ──────────────
static inline t_array* tphp_fn_array_merge(t_array* a, t_array* b) {
    int total = (a ? a->length : 0) + (b ? b->length : 0);
    t_array* out = tphp_fn_arr_create(total);
    if (out == NULL) return NULL;
    tphp_rt_register((void*)out, 1);
    if (a) { for (int i = 0; i < a->length; i++) out = tphp_fn_arr_push(out, a->entries[i].val); }
    if (b) {
        for (int i = 0; i < b->length; i++) {
            if (b->entries[i].key.type == TYPE_STRING)
                out = tphp_fn_arr_set_str(out, b->entries[i].key.value._string, b->entries[i].val);
            else
                out = tphp_fn_arr_push(out, b->entries[i].val);
        }
    }
    return out;
}

// ── implode($glue, $arr) → 两遍扫描, O(N) memcpy ──────────────
static inline t_string tphp_fn_implode(t_string glue, t_array* a) {
    if (a == NULL || a->length == 0) return (t_string){.data = NULL, .length = 0, .is_local = false};
    int glueLen = (STR_PTR(glue) != NULL && glue.length > 0) ? glue.length : 0;
    int totalLen = 0;
    for (int i = 0; i < a->length; i++) {
        t_var *v = &a->entries[i].val;
        int partLen = 0;
        if (v->type == TYPE_STRING) {
            partLen = (STR_PTR_V(v->value._string) != NULL) ? v->value._string.length : 0;
        } else if (v->type == TYPE_INT) {
            char _ib[32]; partLen = snprintf(_ib, sizeof(_ib), "%lld", (long long)v->value._int);
        } else if (v->type == TYPE_FLOAT) {
            char _fb[64]; partLen = snprintf(_fb, sizeof(_fb), "%g", v->value._float);
        }
        if (partLen < 0) partLen = 0;
        totalLen += partLen;
        if (i > 0 && glueLen > 0) totalLen += glueLen;
        if (unlikely(totalLen > 0x7FFFFF)) return (t_string){.data = NULL, .length = 0, .is_local = false};
    }
    if (totalLen <= 0) return (t_string){.data = NULL, .length = 0, .is_local = false};
    char* buf = str_pool_alloc(totalLen);
    if (unlikely(buf == NULL)) return (t_string){.data = NULL, .length = 0, .is_local = false};
    int pos = 0;
    for (int i = 0; i < a->length; i++) {
        t_var *v = &a->entries[i].val;
        if (i > 0 && glueLen > 0) { memcpy(buf + pos, STR_PTR(glue), (size_t)glueLen); pos += glueLen; }
        if (v->type == TYPE_STRING) {
            t_string s = v->value._string;
            int slen = (STR_PTR(s) != NULL && s.length > 0) ? s.length : 0;
            if (slen > 0) { memcpy(buf + pos, STR_PTR(s), (size_t)slen); pos += slen; }
        } else if (v->type == TYPE_INT) {
            int n = snprintf(buf + pos, (size_t)(totalLen - pos + 1), "%lld", (long long)v->value._int);
            if (n > 0) pos += n;
        } else if (v->type == TYPE_FLOAT) {
            int n = snprintf(buf + pos, (size_t)(totalLen - pos + 1), "%g", v->value._float);
            if (n > 0) pos += n;
        }
    }
    buf[totalLen] = '\0';
    return (t_string){buf, totalLen};
}

// ── explode($delim, $str) → 精确容量, 零 realloc ─────────────
static inline t_array* tphp_fn_explode(t_string delim, t_string s) {
    if (STR_PTR(s) == NULL || s.length == 0) {
        t_array* out = tphp_fn_arr_create(0);
        if (out) tphp_rt_register((void*)out, 1);
        return out;
    }
    if (delim.length == 0 || STR_PTR(delim) == NULL) {
        t_array* out = tphp_fn_arr_create(1);
        if (out == NULL) return NULL;
        tphp_rt_register((void*)out, 1);
        out = tphp_fn_arr_push(out, VAR_STRING(tphp_rt_str_dup(s)));
        return out;
    }
    int pieceCount = 1;
    for (int i = 0; i <= s.length; i++) {
        if (i + delim.length <= s.length && memcmp(STR_PTR(s) + i, STR_PTR(delim), (size_t)delim.length) == 0) {
            pieceCount++; i += delim.length - 1;
        }
    }
    t_array* out = tphp_fn_arr_create(pieceCount);
    if (out == NULL) return NULL;
    tphp_rt_register((void*)out, 1);
    int start = 0;
    for (int i = 0; i <= s.length; i++) {
        if (i + delim.length <= s.length && memcmp(STR_PTR(s) + i, STR_PTR(delim), (size_t)delim.length) == 0) {
            int pieceLen = i - start;
            if (pieceLen > 0) {
                char* piece = str_pool_alloc(pieceLen);
                if (piece) { memcpy(piece, STR_PTR(s) + start, (size_t)pieceLen); piece[pieceLen] = '\0';
                    out = tphp_fn_arr_push(out, VAR_STRING(((t_string){piece, pieceLen}))); }
            }
            start = i + delim.length; i = start - 1;
        }
    }
    int pieceLen = s.length - start;
    if (pieceLen > 0) {
        char* piece = str_pool_alloc(pieceLen);
        if (piece) { memcpy(piece, STR_PTR(s) + start, (size_t)pieceLen); piece[pieceLen] = '\0';
            out = tphp_fn_arr_push(out, VAR_STRING(((t_string){piece, pieceLen}))); }
    }
    return out;
}

// ── max/min ──────────────────────────────────────────────────
static inline t_var tphp_fn_max(t_array *a) {
    if (unlikely(a == NULL || a->length == 0)) {
        tphp_rt_free_all();
        fputs("\nFatal error: max(): Array must contain at least one element\n\n", stderr);
        exit(1);
    }
    t_var result; bool found = false;
    for (int i = 0; i < a->length; i++) {
        t_var *v = &a->entries[i].val;
        if (v->type != TYPE_INT && v->type != TYPE_FLOAT) continue;
        if (!found) { result = *v; found = true; continue; }
        if (v->type == TYPE_INT && result.type == TYPE_INT) {
            if (v->value._int > result.value._int) result = *v;
        } else if (v->type == TYPE_FLOAT && result.type == TYPE_FLOAT) {
            if (v->value._float > result.value._float) result = *v;
        } else {
            t_float a = (v->type == TYPE_INT) ? (t_float)v->value._int : v->value._float;
            t_float b = (result.type == TYPE_INT) ? (t_float)result.value._int : result.value._float;
            if (a > b) result = *v;
        }
    }
    return found ? result : VAR_NULL();
}

static inline t_var tphp_fn_min(t_array *a) {
    if (unlikely(a == NULL || a->length == 0)) {
        tphp_rt_free_all();
        fputs("\nFatal error: min(): Array must contain at least one element\n\n", stderr);
        exit(1);
    }
    t_var result; bool found = false;
    for (int i = 0; i < a->length; i++) {
        t_var *v = &a->entries[i].val;
        if (v->type != TYPE_INT && v->type != TYPE_FLOAT) continue;
        if (!found) { result = *v; found = true; continue; }
        if (v->type == TYPE_INT && result.type == TYPE_INT) {
            if (v->value._int < result.value._int) result = *v;
        } else if (v->type == TYPE_FLOAT && result.type == TYPE_FLOAT) {
            if (v->value._float < result.value._float) result = *v;
        } else {
            t_float a = (v->type == TYPE_INT) ? (t_float)v->value._int : v->value._float;
            t_float b = (result.type == TYPE_INT) ? (t_float)result.value._int : result.value._float;
            if (a < b) result = *v;
        }
    }
    return found ? result : VAR_NULL();
}

// std/ctrl.h — 断言/随机数/ctype
//   对应 PHP ext/standard assertions + ext/random + ext/ctype

static inline void tphp_fn_assert_true(t_bool cond) {
    if (unlikely(!cond)) {
        tphp_rt_free_all();
        fputs("\nASSERT FAIL: assert_true()\n\n", stderr);
        exit(2);
    }
}
static inline void tphp_fn_assert_false(t_bool cond) {
    if (unlikely(cond)) {
        tphp_rt_free_all();
        fputs("\nASSERT FAIL: assert_false()\n\n", stderr);
        exit(2);
    }
}
static inline void tphp_fn_assert_eq_int(t_int a, t_int b) {
    if (unlikely(a != b)) {
        tphp_rt_free_all();
        fprintf(stderr, "\nASSERT FAIL: assert_eq_int(%lld, %lld)\n\n",
            (long long)a, (long long)b);
        exit(2);
    }
}
static inline void tphp_fn_assert_eq_float(t_float a, t_float b) {
    if (unlikely(a != b)) {
        tphp_rt_free_all();
        fprintf(stderr, "\nASSERT FAIL: assert_eq_float(%g, %g)\n\n", a, b);
        exit(2);
    }
}
static inline void tphp_fn_assert_eq_str(t_string a, t_string b) {
    if (unlikely(!tphp_rt_str_eq(a, b))) {
        tphp_rt_free_all();
        fprintf(stderr, "\nASSERT FAIL: assert_eq_str(len=%d vs len=%d)\n\n",
            a.length, b.length);
        exit(2);
    }
}

// ============================================================
// ctype — 字符类型检测（映射 C <ctype.h>，零堆分配）
//   每个函数检查字符串中所有字符是否满足指定类型
//   空字符串返回 false（PHP 行为）
// ============================================================

#define _TPHP_CTYPE_CHECK(fn, s)                         \
    do {                                                  \
        if (unlikely((s).data == NULL || (s).length == 0)) return false; \
        for (int _i = 0; _i < (s).length; _i++) {         \
            if (!fn((unsigned char)(s).data[_i])) return false; \
        }                                                 \
        return true;                                       \
    } while(0)

static inline t_bool tphp_fn_ctype_alnum(t_string s) { _TPHP_CTYPE_CHECK(isalnum, s); }
static inline t_bool tphp_fn_ctype_alpha(t_string s) { _TPHP_CTYPE_CHECK(isalpha, s); }
static inline t_bool tphp_fn_ctype_cntrl(t_string s) { _TPHP_CTYPE_CHECK(iscntrl, s); }
static inline t_bool tphp_fn_ctype_digit(t_string s) { _TPHP_CTYPE_CHECK(isdigit, s); }
static inline t_bool tphp_fn_ctype_graph(t_string s) { _TPHP_CTYPE_CHECK(isgraph, s); }
static inline t_bool tphp_fn_ctype_lower(t_string s) { _TPHP_CTYPE_CHECK(islower, s); }
static inline t_bool tphp_fn_ctype_print(t_string s) { _TPHP_CTYPE_CHECK(isprint, s); }
static inline t_bool tphp_fn_ctype_punct(t_string s) { _TPHP_CTYPE_CHECK(ispunct, s); }
static inline t_bool tphp_fn_ctype_space(t_string s) { _TPHP_CTYPE_CHECK(isspace, s); }
static inline t_bool tphp_fn_ctype_upper(t_string s) { _TPHP_CTYPE_CHECK(isupper, s); }
static inline t_bool tphp_fn_ctype_xdigit(t_string s) { _TPHP_CTYPE_CHECK(isxdigit, s); }

#undef _TPHP_CTYPE_CHECK

// ============================================================
// random_int / random_bytes — CSPRNG 安全随机（委托 rand.h）
// ============================================================

// tphp_fn_random_int 定义在 rand.h (CSPRNG 驱动)

static inline t_string tphp_fn_random_bytes(t_int length) {
    if (length <= 0) return (t_string){NULL, 0};
    if (length > 1048576) {
        tphp_fn_error(STR_LIT("random_bytes(): length must be <= 1048576"), "<php>", 0);
        return (t_string){NULL, 0};
    }
    unsigned char* buf = (unsigned char*)malloc((size_t)length);
    if (!buf) return (t_string){NULL, 0};
    if (_tphp_random_bytes(buf, (size_t)length) != 0) {
        free(buf);
        tphp_fn_error(STR_LIT("random_bytes(): unable to generate random bytes"), "<php>", 0);
        return (t_string){NULL, 0};
    }
    t_string s = tphp_rt_str_dup((t_string){(char*)buf, (int)length});
    free(buf);
    return s;
}

