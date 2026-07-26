#pragma once
// std/array_extra.h — 补充数组函数 (flip/diff/intersect/column)
//   对应 PHP ext/standard array functions

static t_array* tphp_fn_arr_flip(t_array *s) {
    t_array *d = tphp_fn_arr_create(s? s->length : 8);
    if (!d) { tp_throw("arr_flip(): out of memory"); return NULL; }
    if (!s) return d; tphp_rt_register((void*)d, 1);
    for (int i=0; i<s->length; i++) {
        if (s->entries[i].val.type == TYPE_STRING)
            d = tphp_fn_arr_set_str(d,s->entries[i].val.value._string,s->entries[i].key);
        else if (s->entries[i].val.type == TYPE_INT)
            d = tphp_fn_arr_set_int(d,s->entries[i].val.value._int,s->entries[i].key);
    }
    return d;
}

// arr_diff/arr_intersect: 当 a2 较大时改用哈希集 O(n+m)，小数组保留双循环 O(n×m)
// （小数组双循环因缓存友好且无哈希开销反而更快）
#define ARR_DIFF_HASH_THRESHOLD 16

// 内部辅助：为 a2 构建 INT/STRING 两个哈希集（string-keyed，利用 str_index O(1) 查找）
//   intSet: 键为 INT 值的十进制字符串表示（如 "123"）
//   strSet: 键为 STRING 值本身
//   类型分离保持原有语义（INT 1 ≠ STRING "1"）
static inline void _arr_diff_build_sets(t_array *a2, t_array **intSet, t_array **strSet) {
    *intSet = tphp_fn_arr_create(8);
    *strSet = tphp_fn_arr_create(8);
    if (*intSet) tphp_rt_register((void*)*intSet, 1);
    if (*strSet) tphp_rt_register((void*)*strSet, 1);
    for (int j = 0; j < a2->length; j++) {
        if (a2->entries[j].val.type == TYPE_INT) {
            char buf[32];
            int n = snprintf(buf, sizeof(buf), "%lld", (long long)a2->entries[j].val.value._int);
            *intSet = tphp_fn_arr_set_str(*intSet, (t_string){buf, n}, VAR_INT(1));
        } else if (a2->entries[j].val.type == TYPE_STRING) {
            *strSet = tphp_fn_arr_set_str(*strSet, a2->entries[j].val.value._string, VAR_INT(1));
        }
    }
}

// 内部辅助：在哈希集中查找值是否存在
static inline bool _arr_diff_lookup(t_array *intSet, t_array *strSet, t_var val) {
    if (val.type == TYPE_INT) {
        char buf[32];
        int n = snprintf(buf, sizeof(buf), "%lld", (long long)val.value._int);
        return tphp_fn_arr_get_str(intSet, (t_string){buf, n}) != NULL;
    } else if (val.type == TYPE_STRING) {
        return tphp_fn_arr_get_str(strSet, val.value._string) != NULL;
    }
    return false;
}

static t_array* tphp_fn_arr_diff(t_array *a1, t_array *a2) {
    t_array *r = tphp_fn_arr_create(8);
    if (!r) { tp_throw("arr_diff(): out of memory"); return NULL; }
    if (!a1) return r; tphp_rt_register((void*)r,1);
    // 小数组：双循环（缓存友好，无哈希开销）
    if (!a2 || a2->length < ARR_DIFF_HASH_THRESHOLD) {
        for (int i=0; i<a1->length; i++) {
            bool f=false;
            if (a2) for (int j=0; j<a2->length&&!f; j++) {
                if (a1->entries[i].val.type==TYPE_INT && a2->entries[j].val.type==TYPE_INT)
                    f = a1->entries[i].val.value._int == a2->entries[j].val.value._int;
                else if (a1->entries[i].val.type==TYPE_STRING && a2->entries[j].val.type==TYPE_STRING)
                    f = tphp_rt_str_eq(a1->entries[i].val.value._string,a2->entries[j].val.value._string);
            }
            if (!f) r = tphp_fn_arr_push(r, a1->entries[i].val);
        }
        return r;
    }
    // 大数组：哈希集 O(n+m)
    t_array *intSet, *strSet;
    _arr_diff_build_sets(a2, &intSet, &strSet);
    for (int i=0; i<a1->length; i++) {
        if (!_arr_diff_lookup(intSet, strSet, a1->entries[i].val))
            r = tphp_fn_arr_push(r, a1->entries[i].val);
    }
    return r;
}

static t_array* tphp_fn_arr_intersect(t_array *a1, t_array *a2) {
    t_array *r = tphp_fn_arr_create(8);
    if (!r) { tp_throw("arr_intersect(): out of memory"); return NULL; }
    if (!a1||!a2) return r; tphp_rt_register((void*)r,1);
    // 小数组：双循环
    if (a2->length < ARR_DIFF_HASH_THRESHOLD) {
        for (int i=0; i<a1->length; i++) {
            bool f=false;
            for (int j=0; j<a2->length&&!f; j++) {
                if (a1->entries[i].val.type==TYPE_INT && a2->entries[j].val.type==TYPE_INT)
                    f = a1->entries[i].val.value._int == a2->entries[j].val.value._int;
                else if (a1->entries[i].val.type==TYPE_STRING && a2->entries[j].val.type==TYPE_STRING)
                    f = tphp_rt_str_eq(a1->entries[i].val.value._string,a2->entries[j].val.value._string);
            }
            if (f) r = tphp_fn_arr_push(r, a1->entries[i].val);
        }
        return r;
    }
    // 大数组：哈希集 O(n+m)
    t_array *intSet, *strSet;
    _arr_diff_build_sets(a2, &intSet, &strSet);
    for (int i=0; i<a1->length; i++) {
        if (_arr_diff_lookup(intSet, strSet, a1->entries[i].val))
            r = tphp_fn_arr_push(r, a1->entries[i].val);
    }
    return r;
}

// 变参版本：array_diff(array $base, array ...$others): array
//   others 是打包后的 t_array*，每个元素是 t_var(TYPE_ARRAY) 包裹的子数组
//   语义：从 base 中排除出现在任意 others 数组中的元素
//   实现：合并所有 others 元素到一个 intSet + strSet，一次遍历 base（O(n + Σm)）
static t_array* tphp_fn_arr_diff_n(t_array *base, t_array *others) {
    t_array *r = tphp_fn_arr_create(base ? base->length : 8);
    if (!r) { tp_throw("arr_diff(): out of memory"); return NULL; }
    if (!base) return r;
    tphp_rt_register((void*)r, 1);
    if (!others || others->length == 0) {
        // 无 others：直接复制 base
        for (int i = 0; i < base->length; i++)
            r = tphp_fn_arr_push(r, base->entries[i].val);
        return r;
    }
    // 合并所有 others 元素到统一集合
    t_array *intSet = tphp_fn_arr_create(8);
    t_array *strSet = tphp_fn_arr_create(8);
    if (intSet) tphp_rt_register((void*)intSet, 1);
    if (strSet) tphp_rt_register((void*)strSet, 1);
    for (int k = 0; k < others->length; k++) {
        if (others->entries[k].val.type != TYPE_ARRAY) continue;
        t_array *oa = others->entries[k].val.value._array;
        if (!oa) continue;
        for (int j = 0; j < oa->length; j++) {
            if (oa->entries[j].val.type == TYPE_INT) {
                char buf[32];
                int n = snprintf(buf, sizeof(buf), "%lld", (long long)oa->entries[j].val.value._int);
                intSet = tphp_fn_arr_set_str(intSet, (t_string){buf, n}, VAR_INT(1));
            } else if (oa->entries[j].val.type == TYPE_STRING) {
                strSet = tphp_fn_arr_set_str(strSet, oa->entries[j].val.value._string, VAR_INT(1));
            }
        }
    }
    // 一次遍历 base，排除集合中的元素
    for (int i = 0; i < base->length; i++) {
        if (!_arr_diff_lookup(intSet, strSet, base->entries[i].val))
            r = tphp_fn_arr_push(r, base->entries[i].val);
    }
    return r;
}

// 变参版本：array_intersect(array $base, array ...$others): array
//   语义：保留 base 中同时出现在所有 others 数组中的元素
//   实现：为每个 other 构建独立集合，base 元素必须在所有集合中存在
static t_array* tphp_fn_arr_intersect_n(t_array *base, t_array *others) {
    t_array *r = tphp_fn_arr_create(base ? base->length : 8);
    if (!r) { tp_throw("arr_intersect(): out of memory"); return NULL; }
    if (!base) return r;
    tphp_rt_register((void*)r, 1);
    if (!others || others->length == 0) {
        // 无 others：直接复制 base（PHP 语义：array_intersect($a) = $a）
        for (int i = 0; i < base->length; i++)
            r = tphp_fn_arr_push(r, base->entries[i].val);
        return r;
    }
    int k = others->length;
    // 为每个 other 构建独立集合（intSet[k], strSet[k]）
    t_array **intSets = (t_array**)malloc(sizeof(t_array*) * k);
    t_array **strSets = (t_array**)malloc(sizeof(t_array*) * k);
    if (!intSets || !strSets) {
        if (intSets) free(intSets);
        if (strSets) free(strSets);
        tp_throw("arr_intersect(): out of memory");
        return r;
    }
    for (int s = 0; s < k; s++) {
        intSets[s] = strSets[s] = NULL;
        if (others->entries[s].val.type != TYPE_ARRAY) continue;
        t_array *oa = others->entries[s].val.value._array;
        if (!oa) continue;
        intSets[s] = tphp_fn_arr_create(8);
        strSets[s] = tphp_fn_arr_create(8);
        if (intSets[s]) tphp_rt_register((void*)intSets[s], 1);
        if (strSets[s]) tphp_rt_register((void*)strSets[s], 1);
        for (int j = 0; j < oa->length; j++) {
            if (oa->entries[j].val.type == TYPE_INT) {
                char buf[32];
                int n = snprintf(buf, sizeof(buf), "%lld", (long long)oa->entries[j].val.value._int);
                intSets[s] = tphp_fn_arr_set_str(intSets[s], (t_string){buf, n}, VAR_INT(1));
            } else if (oa->entries[j].val.type == TYPE_STRING) {
                strSets[s] = tphp_fn_arr_set_str(strSets[s], oa->entries[j].val.value._string, VAR_INT(1));
            }
        }
    }
    // base 元素必须在所有集合中存在
    for (int i = 0; i < base->length; i++) {
        t_var v = base->entries[i].val;
        bool in_all = true;
        for (int s = 0; s < k; s++) {
            if (!_arr_diff_lookup(intSets[s], strSets[s], v)) {
                in_all = false;
                break;
            }
        }
        if (in_all) r = tphp_fn_arr_push(r, v);
    }
    free(intSets);
    free(strSets);
    return r;
}

static t_array* tphp_fn_arr_column(t_array *a, t_string ck) {
    t_array *r = tphp_fn_arr_create(a?a->length:8);
    if (!r) { tp_throw("arr_column(): out of memory"); return NULL; }
    if (!a) return r; tphp_rt_register((void*)r,1);
    for (int i=0; i<a->length; i++) {
        if (a->entries[i].val.type!=TYPE_ARRAY) continue;
        t_array *rw = a->entries[i].val.value._array;
        if (!rw) continue;
        for (int j=0; j<rw->length; j++)
            if (rw->entries[j].key.type==TYPE_STRING && tphp_rt_str_eq(rw->entries[j].key.value._string,ck))
                { r=tphp_fn_arr_push(r,rw->entries[j].val); break; }
    }
    return r;
}

// ── array_chunk ──────────────────────────────────────────────
static t_array* tphp_fn_arr_chunk(t_array *a, t_int sz) {
    if(sz<1){ tp_throw("arr_chunk(): size must be >= 1"); return NULL; }
    int n=a?a->length:0,chs=(n+(int)sz-1)/(int)sz;if(chs<1)chs=1;
    t_array *r=tphp_fn_arr_create(chs);if(!r){ tp_throw("arr_chunk(): out of memory"); return NULL; }tphp_rt_register((void*)r,1);
    for(int i=0;i<n;i+=(int)sz){
        int e=i+(int)sz;if(e>n)e=n;
        t_array *c=tphp_fn_arr_create(e-i);tphp_rt_register((void*)c,1);
        for(int j=i;j<e;j++)c=tphp_fn_arr_push(c,a->entries[j].val);
        r=tphp_fn_arr_push(r,VAR_ARRAY(c));
    }
    return r;
}

// ── array_combine ───────────────────────────────────────────
static t_array* tphp_fn_arr_combine(t_array *ks, t_array *vs) {
    if(!ks||!vs)return tphp_fn_arr_create(0);
    if(ks->length!=vs->length){ tp_throw("arr_combine(): arrays must have the same length"); return NULL; }
    t_array *r=tphp_fn_arr_create(ks->length);if(!r){ tp_throw("arr_combine(): out of memory"); return NULL; }tphp_rt_register((void*)r,1);
    for(int i=0;i<ks->length;i++){
        if(ks->entries[i].val.type==TYPE_STRING)r=tphp_fn_arr_set_str(r,ks->entries[i].val.value._string,vs->entries[i].val);
        else if(ks->entries[i].val.type==TYPE_INT)r=tphp_fn_arr_set_int(r,ks->entries[i].val.value._int,vs->entries[i].val);
    }
    return r;
}

// ── array_count_values ──────────────────────────────────────
static t_array* tphp_fn_arr_count_values(t_array *a) {
    t_array *r=tphp_fn_arr_create(a?a->length:8);if(!r){ tp_throw("arr_count_values(): out of memory"); return NULL; }if(!a)return r;tphp_rt_register((void*)r,1);
    for(int i=0;i<a->length;i++){
        t_var *v=&a->entries[i].val;t_string key;
        if(v->type==TYPE_INT){char b[32];int n=snprintf(b,sizeof(b),"%lld",(long long)v->value._int);key=(t_string){b,n};}
        else if(v->type==TYPE_STRING)key=v->value._string;
        else continue;
        t_var *ex=tphp_fn_arr_get_str(r,key);
        r=tphp_fn_arr_set_str(r,key,VAR_INT(ex?ex->value._int+1:1));
    }
    return r;
}

// array_rand: 已在 array.h 中定义 (tphp_fn_array_rand, 返回 t_int)
