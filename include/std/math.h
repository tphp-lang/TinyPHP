#pragma once
// std/math.h — 数学函数 (abs, round, trig, exp, log)
//   对应 PHP ext/standard math functions

static inline t_int   tphp_fn_abs(t_int v)   { return llabs(v); }
static inline t_float tphp_fn_round(t_float v) { return round(v); }
static inline t_float tphp_fn_ceil(t_float v)  { return ceil(v); }
static inline t_float tphp_fn_floor(t_float v) { return floor(v); }
static inline t_float tphp_fn_sqrt(t_float v)  { return v >= 0.0 ? sqrt(v) : 0.0; }

// ── C99 <math.h> 1:1 映射 ────────────────────────────────────
static inline t_float tphp_fn_sin(t_float x){return sin(x);}
static inline t_float tphp_fn_cos(t_float x){return cos(x);}
static inline t_float tphp_fn_tan(t_float x){return tan(x);}
static inline t_float tphp_fn_asin(t_float x){return asin(x);}
static inline t_float tphp_fn_acos(t_float x){return acos(x);}
static inline t_float tphp_fn_atan(t_float x){return atan(x);}
static inline t_float tphp_fn_sinh(t_float x){return sinh(x);}
static inline t_float tphp_fn_cosh(t_float x){return cosh(x);}
static inline t_float tphp_fn_tanh(t_float x){return tanh(x);}
static inline t_float tphp_fn_exp(t_float x){return exp(x);}
static inline t_float tphp_fn_log(t_float x){return log(x);}
static inline t_float tphp_fn_log10(t_float x){return log10(x);}
static inline t_float tphp_fn_fmod(t_float x,t_float y){return fmod(x,y);}
static inline t_bool tphp_fn_is_finite(t_float x){return isfinite(x);}
static inline t_bool tphp_fn_is_infinite(t_float x){return isinf(x);}
static inline t_bool tphp_fn_is_nan(t_float x){return isnan(x);}

// ── base_convert: 任意进制转换 (2-36进制) ──────────────────
static t_string tphp_fn_base_convert(t_string num, t_int from, t_int to) {
    if(from<2||from>36||to<2||to>36) return tphp_rt_str_dup(STR_LIT(""));
    static const char dc[]="0123456789abcdefghijklmnopqrstuvwxyz";
    const char *p=STR_PTR(num);int nlen=num.length;
    if(nlen<=0)return tphp_rt_str_dup(STR_LIT("0"));
    // 转十进制大整数 (堆分配避开 TCC 栈限制)
    char dtmp[64]={0}; // 20 digits max fits in 64
    int dpos=0,neg=0,start=0;
    if(p[0]=='-'){neg=1;start=1;}
    for(int i=start;i<nlen;i++){
        int v=-1;char c=p[i]|32;
        if(c>='0'&&c<='9')v=c-'0';else if(c>='a'&&c<='z')v=c-'a'+10;
        if(v<0||v>=(int)from)return tphp_rt_str_dup(STR_LIT(""));
        int carry=v;
        int lastJ=0;
        for(int j=0;j<=dpos;j++){carry+=dtmp[j]*(int)from;dtmp[j]=(char)(carry%10);carry/=10;lastJ=j;}
        dpos=lastJ+1;
        while(carry>0){dtmp[dpos++]=(char)(carry%10);carry/=10;}
    }
    // 十进制 → 目标进制
    char otmp[64];int opos=0;
    while(1){int rem=0,allZero=1;
        for(int i=dpos-1;i>=0;i--){int cur=rem*10+dtmp[i];dtmp[i]=(char)(cur/(int)to);rem=cur%(int)to;if(dtmp[i])allZero=0;}
        otmp[opos++]=dc[rem];while(dpos>1&&dtmp[dpos-1]==0)dpos--;
        if(allZero)break;
    }
    int total=opos+neg;char *buf=str_pool_alloc(total);if(!buf)return tphp_rt_str_dup(STR_LIT(""));
    int w=0;if(neg)buf[w++]='-';
    while(opos>0)buf[w++]=otmp[--opos];
    return (t_string){buf,total};
}
