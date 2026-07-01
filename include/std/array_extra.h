// std/array_extra.h — 补充数组函数 (flip/diff/intersect/column)
//   对应 PHP ext/standard array functions

static t_array* tphp_fn_arr_flip(t_array *s) {
    t_array *d = tphp_fn_arr_create(s? s->length : 8);
    if (!d || !s) return d; tphp_rt_register((void*)d, 1);
    for (int i=0; i<s->length; i++) {
        if (s->entries[i].val.type == TYPE_STRING)
            d = tphp_fn_arr_set_str(d,s->entries[i].val.value._string,s->entries[i].key);
        else if (s->entries[i].val.type == TYPE_INT)
            d = tphp_fn_arr_set_int(d,s->entries[i].val.value._int,s->entries[i].key);
    }
    return d;
}

static t_array* tphp_fn_arr_diff(t_array *a1, t_array *a2) {
    t_array *r = tphp_fn_arr_create(8);
    if (!r || !a1) return r; tphp_rt_register((void*)r,1);
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

static t_array* tphp_fn_arr_intersect(t_array *a1, t_array *a2) {
    t_array *r = tphp_fn_arr_create(8);
    if (!r||!a1||!a2) return r; tphp_rt_register((void*)r,1);
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

static t_array* tphp_fn_arr_column(t_array *a, t_string ck) {
    t_array *r = tphp_fn_arr_create(a?a->length:8);
    if (!r||!a) return r; tphp_rt_register((void*)r,1);
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
    if(sz<1)return tphp_fn_arr_create(0);
    int n=a?a->length:0,chs=(n+(int)sz-1)/(int)sz;if(chs<1)chs=1;
    t_array *r=tphp_fn_arr_create(chs);if(!r)return r;tphp_rt_register((void*)r,1);
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
    if(!ks||!vs||ks->length!=vs->length)return tphp_fn_arr_create(0);
    t_array *r=tphp_fn_arr_create(ks->length);if(!r)return r;tphp_rt_register((void*)r,1);
    for(int i=0;i<ks->length;i++){
        if(ks->entries[i].val.type==TYPE_STRING)r=tphp_fn_arr_set_str(r,ks->entries[i].val.value._string,vs->entries[i].val);
        else if(ks->entries[i].val.type==TYPE_INT)r=tphp_fn_arr_set_int(r,ks->entries[i].val.value._int,vs->entries[i].val);
    }
    return r;
}

// ── array_count_values ──────────────────────────────────────
static t_array* tphp_fn_arr_count_values(t_array *a) {
    t_array *r=tphp_fn_arr_create(a?a->length:8);if(!r||!a)return r;tphp_rt_register((void*)r,1);
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
