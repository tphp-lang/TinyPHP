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
