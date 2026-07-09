#pragma once
// ============================================================
// hash.h — md5 / sha1 / crc32 哈希函数
// 纯 C 实现，零外部依赖，零堆分配
// ============================================================

#include <stdint.h>
#include <string.h>
#include "types.h"

// 前向声明
static inline char* str_pool_alloc(int len);

/* ─── MD5 (RFC 1321) ─────────────────────────────────── */

typedef struct {
    uint32_t v[4]; uint64_t len; uint8_t buf[64]; int pos;
} _md5_ctx;

static inline void _md5_init(_md5_ctx *c) {
    c->v[0]=0x67452301; c->v[1]=0xEFCDAB89; c->v[2]=0x98BADCFE; c->v[3]=0x10325476;
    c->len=0; c->pos=0;
}

#define _MD5_F(x,y,z) ((z)^((x)&((y)^(z))))
#define _MD5_G(x,y,z) ((y)^((z)&((x)^(y))))
#define _MD5_H(x,y,z) ((x)^(y)^(z))
#define _MD5_I(x,y,z) ((y)^((x)|~(z)))
#define _MD5_ROTL(v,s) (((v)<<(s))|((v)>>(32-(s))))
#define _MD5_STEP(f,a,b,c,d,x,s,t) do{a+=f(b,c,d)+x+t;a=_MD5_ROTL(a,s);a+=b;}while(0)

static inline void _md5_block(_md5_ctx *c) {
    uint32_t x[16], a=c->v[0], b=c->v[1], cc=c->v[2], d=c->v[3];
    for(int i=0;i<16;i++)x[i]=(uint32_t)c->buf[i*4]|(uint32_t)c->buf[i*4+1]<<8|(uint32_t)c->buf[i*4+2]<<16|(uint32_t)c->buf[i*4+3]<<24;
    _MD5_STEP(_MD5_F,a,b,cc,d,x[0],7,0xD76AA478);_MD5_STEP(_MD5_F,d,a,b,cc,x[1],12,0xE8C7B756);_MD5_STEP(_MD5_F,cc,d,a,b,x[2],17,0x242070DB);_MD5_STEP(_MD5_F,b,cc,d,a,x[3],22,0xC1BDCEEE);
    _MD5_STEP(_MD5_F,a,b,cc,d,x[4],7,0xF57C0FAF);_MD5_STEP(_MD5_F,d,a,b,cc,x[5],12,0x4787C62A);_MD5_STEP(_MD5_F,cc,d,a,b,x[6],17,0xA8304613);_MD5_STEP(_MD5_F,b,cc,d,a,x[7],22,0xFD469501);
    _MD5_STEP(_MD5_F,a,b,cc,d,x[8],7,0x698098D8);_MD5_STEP(_MD5_F,d,a,b,cc,x[9],12,0x8B44F7AF);_MD5_STEP(_MD5_F,cc,d,a,b,x[10],17,0xFFFF5BB1);_MD5_STEP(_MD5_F,b,cc,d,a,x[11],22,0x895CD7BE);
    _MD5_STEP(_MD5_F,a,b,cc,d,x[12],7,0x6B901122);_MD5_STEP(_MD5_F,d,a,b,cc,x[13],12,0xFD987193);_MD5_STEP(_MD5_F,cc,d,a,b,x[14],17,0xA679438E);_MD5_STEP(_MD5_F,b,cc,d,a,x[15],22,0x49B40821);
    _MD5_STEP(_MD5_G,a,b,cc,d,x[1],5,0xF61E2562);_MD5_STEP(_MD5_G,d,a,b,cc,x[6],9,0xC040B340);_MD5_STEP(_MD5_G,cc,d,a,b,x[11],14,0x265E5A51);_MD5_STEP(_MD5_G,b,cc,d,a,x[0],20,0xE9B6C7AA);
    _MD5_STEP(_MD5_G,a,b,cc,d,x[5],5,0xD62F105D);_MD5_STEP(_MD5_G,d,a,b,cc,x[10],9,0x02441453);_MD5_STEP(_MD5_G,cc,d,a,b,x[15],14,0xD8A1E681);_MD5_STEP(_MD5_G,b,cc,d,a,x[4],20,0xE7D3FBC8);
    _MD5_STEP(_MD5_G,a,b,cc,d,x[9],5,0x21E1CDE6);_MD5_STEP(_MD5_G,d,a,b,cc,x[14],9,0xC33707D6);_MD5_STEP(_MD5_G,cc,d,a,b,x[3],14,0xF4D50D87);_MD5_STEP(_MD5_G,b,cc,d,a,x[8],20,0x455A14ED);
    _MD5_STEP(_MD5_G,a,b,cc,d,x[13],5,0xA9E3E905);_MD5_STEP(_MD5_G,d,a,b,cc,x[2],9,0xFCEFA3F8);_MD5_STEP(_MD5_G,cc,d,a,b,x[7],14,0x676F02D9);_MD5_STEP(_MD5_G,b,cc,d,a,x[12],20,0x8D2A4C8A);
    _MD5_STEP(_MD5_H,a,b,cc,d,x[5],4,0xFFFA3942);_MD5_STEP(_MD5_H,d,a,b,cc,x[8],11,0x8771F681);_MD5_STEP(_MD5_H,cc,d,a,b,x[11],16,0x6D9D6122);_MD5_STEP(_MD5_H,b,cc,d,a,x[14],23,0xFDE5380C);
    _MD5_STEP(_MD5_H,a,b,cc,d,x[1],4,0xA4BEEA44);_MD5_STEP(_MD5_H,d,a,b,cc,x[4],11,0x4BDECFA9);_MD5_STEP(_MD5_H,cc,d,a,b,x[7],16,0xF6BB4B60);_MD5_STEP(_MD5_H,b,cc,d,a,x[10],23,0xBEBFBC70);
    _MD5_STEP(_MD5_H,a,b,cc,d,x[13],4,0x289B7EC6);_MD5_STEP(_MD5_H,d,a,b,cc,x[0],11,0xEAA127FA);_MD5_STEP(_MD5_H,cc,d,a,b,x[3],16,0xD4EF3085);_MD5_STEP(_MD5_H,b,cc,d,a,x[6],23,0x04881D05);
    _MD5_STEP(_MD5_H,a,b,cc,d,x[9],4,0xD9D4D039);_MD5_STEP(_MD5_H,d,a,b,cc,x[12],11,0xE6DB99E5);_MD5_STEP(_MD5_H,cc,d,a,b,x[15],16,0x1FA27CF8);_MD5_STEP(_MD5_H,b,cc,d,a,x[2],23,0xC4AC5665);
    _MD5_STEP(_MD5_I,a,b,cc,d,x[0],6,0xF4292244);_MD5_STEP(_MD5_I,d,a,b,cc,x[7],10,0x432AFF97);_MD5_STEP(_MD5_I,cc,d,a,b,x[14],15,0xAB9423A7);_MD5_STEP(_MD5_I,b,cc,d,a,x[5],21,0xFC93A039);
    _MD5_STEP(_MD5_I,a,b,cc,d,x[12],6,0x655B59C3);_MD5_STEP(_MD5_I,d,a,b,cc,x[3],10,0x8F0CCC92);_MD5_STEP(_MD5_I,cc,d,a,b,x[10],15,0xFFEFF47D);_MD5_STEP(_MD5_I,b,cc,d,a,x[1],21,0x85845DD1);
    _MD5_STEP(_MD5_I,a,b,cc,d,x[8],6,0x6FA87E4F);_MD5_STEP(_MD5_I,d,a,b,cc,x[15],10,0xFE2CE6E0);_MD5_STEP(_MD5_I,cc,d,a,b,x[6],15,0xA3014314);_MD5_STEP(_MD5_I,b,cc,d,a,x[13],21,0x4E0811A1);
    _MD5_STEP(_MD5_I,a,b,cc,d,x[4],6,0xF7537E82);_MD5_STEP(_MD5_I,d,a,b,cc,x[11],10,0xBD3AF235);_MD5_STEP(_MD5_I,cc,d,a,b,x[2],15,0x2AD7D2BB);_MD5_STEP(_MD5_I,b,cc,d,a,x[9],21,0xEB86D391);
    c->v[0]+=a; c->v[1]+=b; c->v[2]+=cc; c->v[3]+=d;
}

static inline t_string tphp_fn_md5(t_string s) {
    _md5_ctx c; _md5_init(&c);
    uint8_t *d = (uint8_t*)(STR_PTR(s) ? STR_PTR(s) : "");
    int len = s.length;
    uint64_t blen = (uint64_t)len * 8;
    while (len > 0) { int r = (len < 64 - c.pos) ? len : 64 - c.pos; memcpy(c.buf+c.pos, d, (size_t)r); c.pos += r; d += r; len -= r; if (c.pos == 64) { _md5_block(&c); c.pos = 0; } }
    c.buf[c.pos++] = 0x80; if (c.pos > 56) { while (c.pos < 64) c.buf[c.pos++]=0; _md5_block(&c); c.pos=0; }
    while (c.pos < 56) c.buf[c.pos++] = 0;
    for (int i=0;i<8;i++) c.buf[56+i] = (uint8_t)(blen >> (i*8));
    _md5_block(&c);
    static const char hx[] = "0123456789abcdef";
    char *out = str_pool_alloc(32);
    if (!out) return (t_string){NULL,0};
    for (int i=0;i<4;i++) { uint32_t v=c.v[i]; for(int j=0;j<4;j++){out[i*8+j*2]=hx[(v>>(j*8+4))&0xF];out[i*8+j*2+1]=hx[(v>>(j*8))&0xF];} }
    return (t_string){out,32};
}

/* ─── SHA1 (NIST FIPS 180-4) ──────────────────────────── */

typedef struct { uint32_t v[5]; uint64_t len; uint8_t buf[64]; int pos; } _sha1_ctx;

static inline void _sha1_init(_sha1_ctx *c) {
    c->v[0]=0x67452301;c->v[1]=0xEFCDAB89;c->v[2]=0x98BADCFE;c->v[3]=0x10325476;c->v[4]=0xC3D2E1F0;
    c->len=0;c->pos=0;
}

#define _SHA1_ROTL(v,s) (((v)<<(s))|((v)>>(32-(s))))

static inline void _sha1_block(_sha1_ctx *c) {
    uint32_t w[80], a=c->v[0], b=c->v[1], cc=c->v[2], d=c->v[3], e=c->v[4];
    for(int i=0;i<16;i++)w[i]=(uint32_t)c->buf[i*4]<<24|(uint32_t)c->buf[i*4+1]<<16|(uint32_t)c->buf[i*4+2]<<8|c->buf[i*4+3];
    for(int i=16;i<80;i++)w[i]=_SHA1_ROTL(w[i-3]^w[i-8]^w[i-14]^w[i-16],1);
    for(int i=0;i<80;i++){
        uint32_t f,k;
        if(i<20){f=(b&cc)|(~b&d);k=0x5A827999;}
        else if(i<40){f=b^cc^d;k=0x6ED9EBA1;}
        else if(i<60){f=(b&cc)|(b&d)|(cc&d);k=0x8F1BBCDC;}
        else{f=b^cc^d;k=0xCA62C1D6;}
        uint32_t t=_SHA1_ROTL(a,5)+f+e+k+w[i];e=d;d=cc;cc=_SHA1_ROTL(b,30);b=a;a=t;
    }
    c->v[0]+=a;c->v[1]+=b;c->v[2]+=cc;c->v[3]+=d;c->v[4]+=e;
}

static inline t_string tphp_fn_sha1(t_string s) {
    _sha1_ctx c; _sha1_init(&c);
    uint8_t *d = (uint8_t*)(STR_PTR(s) ? STR_PTR(s) : "");
    int len = s.length;
    uint64_t blen = (uint64_t)len * 8;
    while (len > 0) { int r = (len < 64 - c.pos) ? len : 64 - c.pos; memcpy(c.buf+c.pos, d, (size_t)r); c.pos += r; d += r; len -= r; if (c.pos == 64) { _sha1_block(&c); c.pos = 0; } }
    c.buf[c.pos++] = 0x80; if (c.pos > 56) { while (c.pos < 64) c.buf[c.pos++]=0; _sha1_block(&c); c.pos=0; }
    while (c.pos < 56) c.buf[c.pos++] = 0;
    for (int i=0;i<8;i++) c.buf[56+i] = (uint8_t)(blen >> (56-i*8));
    _sha1_block(&c);
    static const char hx[] = "0123456789abcdef";
    char *out = str_pool_alloc(40);
    if (!out) return (t_string){NULL,0};
    for (int i=0;i<5;i++) { uint32_t v=c.v[i]; for(int j=0;j<4;j++){out[i*8+j*2]=hx[(v>>(28-j*8))&0xF];out[i*8+j*2+1]=hx[(v>>(28-j*8+4))&0xF];} }
    return (t_string){out,40};
}

/* ─── CRC32 ────────────────────────────────────────────── */

static uint32_t _crc32_tab[256];
static int _crc32_tab_init = 0;

static inline void _crc32_make_tab() {
    for (uint32_t i = 0; i < 256; i++) {
        uint32_t c = i;
        for (int j = 0; j < 8; j++) c = (c >> 1) ^ ((c & 1) ? 0xEDB88320UL : 0);
        _crc32_tab[i] = c;
    }
    _crc32_tab_init = 1;
}

static inline t_int tphp_fn_crc32_str(t_string s) {
    if (!_crc32_tab_init) _crc32_make_tab();
    uint32_t crc = 0xFFFFFFFF;
    for (int i = 0; i < s.length; i++) crc = (crc >> 8) ^ _crc32_tab[(crc ^ (unsigned char)STR_PTR(s)[i]) & 0xFF];
    return (t_int)(crc ^ 0xFFFFFFFF);
}

/* ─── SHA-256 (FIPS 180-4) ─────────────────────────────── */

static const uint32_t _sha256_k[64] = {
    0x428a2f98,0x71374491,0xb5c0fbcf,0xe9b5dba5,0x3956c25b,0x59f111f1,0x923f82a4,0xab1c5ed5,
    0xd807aa98,0x12835b01,0x243185be,0x550c7dc3,0x72be5d74,0x80deb1fe,0x9bdc06a7,0xc19bf174,
    0xe49b69c1,0xefbe4786,0x0fc19dc6,0x240ca1cc,0x2de92c6f,0x4a7484aa,0x5cb0a9dc,0x76f988da,
    0x983e5152,0xa831c66d,0xb00327c8,0xbf597fc7,0xc6e00bf3,0xd5a79147,0x06ca6351,0x14292967,
    0x27b70a85,0x2e1b2138,0x4d2c6dfc,0x53380d13,0x650a7354,0x766a0abb,0x81c2c92e,0x92722c85,
    0xa2bfe8a1,0xa81a664b,0xc24b8b70,0xc76c51a3,0xd192e819,0xd6990624,0xf40e3585,0x106aa070,
    0x19a4c116,0x1e376c08,0x2748774c,0x34b0bcb5,0x391c0cb3,0x4ed8aa4a,0x5b9cca4f,0x682e6ff3,
    0x748f82ee,0x78a5636f,0x84c87814,0x8cc70208,0x90befffa,0xa4506ceb,0xbef9a3f7,0xc67178f2,
};

#define _SHR(x,n) ((x)>>(n))
#define _ROTR(x,n) (((x)>>(n))|((x)<<(32-(n))))
#define _CH(x,y,z) (((x)&(y))^(~(x)&(z)))
#define _MAJ(x,y,z) (((x)&(y))^((x)&(z))^((y)&(z)))
#define _BSIG0(x) (_ROTR(x,2)^_ROTR(x,13)^_ROTR(x,22))
#define _BSIG1(x) (_ROTR(x,6)^_ROTR(x,11)^_ROTR(x,25))
#define _SSIG0(x) (_ROTR(x,7)^_ROTR(x,18)^_SHR(x,3))
#define _SSIG1(x) (_ROTR(x,17)^_ROTR(x,19)^_SHR(x,10))

typedef struct { uint32_t h[8]; uint64_t len; uint8_t buf[64]; int pos; } _sha256_ctx;

static inline void _sha256_init(_sha256_ctx *c) {
    c->h[0]=0x6a09e667;c->h[1]=0xbb67ae85;c->h[2]=0x3c6ef372;c->h[3]=0xa54ff53a;
    c->h[4]=0x510e527f;c->h[5]=0x9b05688c;c->h[6]=0x1f83d9ab;c->h[7]=0x5be0cd19;
    c->len=0;c->pos=0;
}

static void _sha256_transform(uint32_t *h, const uint8_t *data) {
    uint32_t w[64];
    uint32_t a=h[0],b=h[1],c2=h[2],d=h[3],e=h[4],f=h[5],g=h[6],h0=h[7];
    for(int i=0;i<16;i++) w[i]=((uint32_t)data[i*4]<<24)|((uint32_t)data[i*4+1]<<16)|((uint32_t)data[i*4+2]<<8)|(uint32_t)data[i*4+3];
    for(int i=16;i<64;i++) w[i]=_SSIG1(w[i-2])+w[i-7]+_SSIG0(w[i-15])+w[i-16];
    for(int i=0;i<64;i++){uint32_t t1=h0+_BSIG1(e)+_CH(e,f,g)+_sha256_k[i]+w[i],t2=_BSIG0(a)+_MAJ(a,b,c2);h0=g;g=f;f=e;e=d+t1;d=c2;c2=b;b=a;a=t1+t2;}
    h[0]+=a;h[1]+=b;h[2]+=c2;h[3]+=d;h[4]+=e;h[5]+=f;h[6]+=g;h[7]+=h0;
}

static void _sha256_update(_sha256_ctx *c, const uint8_t *data, size_t len) {
    c->len += (uint64_t)len;
    for(size_t i=0;i<len;i++){c->buf[c->pos++]=data[i];if(c->pos==64){_sha256_transform(c->h,c->buf);c->pos=0;}}
}

static void _sha256_final(_sha256_ctx *c, uint8_t *digest) {
    uint64_t bits=c->len*8;c->buf[c->pos++]=0x80;
    if(c->pos>56){while(c->pos<64)c->buf[c->pos++]=0;_sha256_transform(c->h,c->buf);c->pos=0;}
    while(c->pos<56)c->buf[c->pos++]=0;
    for(int i=7;i>=0;i--)c->buf[c->pos++]=(uint8_t)(bits>>(i*8));
    _sha256_transform(c->h,c->buf);
    for(int i=0;i<8;i++){digest[i*4]=(uint8_t)(c->h[i]>>24);digest[i*4+1]=(uint8_t)(c->h[i]>>16);digest[i*4+2]=(uint8_t)(c->h[i]>>8);digest[i*4+3]=(uint8_t)c->h[i];}
}

static t_string tphp_fn_sha256(t_string s) {
    _sha256_ctx c;_sha256_init(&c);
    _sha256_update(&c,(const uint8_t*)STR_PTR(s),(size_t)s.length);
    uint8_t d[32];_sha256_final(&c,d);
    static const char hx[]="0123456789abcdef";
    char *buf=str_pool_alloc(64);if(!buf)return (t_string){NULL,0};
    for(int i=0;i<32;i++){buf[i*2]=hx[d[i]>>4];buf[i*2+1]=hx[d[i]&0xf];}
    return (t_string){buf,64};
}

/* ─── SHA-512 (FIPS 180-4) ─────────────────────────────── */

static const uint64_t _sha512_k[80] = {
    0x428a2f98d728ae22ULL,0x7137449123ef65cdULL,0xb5c0fbcfec4d3b2fULL,0xe9b5dba58189dbbcULL,
    0x3956c25bf348b538ULL,0x59f111f1b605d019ULL,0x923f82a4af194f9bULL,0xab1c5ed5da6d8118ULL,
    0xd807aa98a3030242ULL,0x12835b0145706fbeULL,0x243185be4ee4b28cULL,0x550c7dc3d5ffb4e2ULL,
    0x72be5d74f27b896fULL,0x80deb1fe3b1696b1ULL,0x9bdc06a725c71235ULL,0xc19bf174cf692694ULL,
    0xe49b69c19ef14ad2ULL,0xefbe4786384f25e3ULL,0x0fc19dc68b8cd5b5ULL,0x240ca1cc77ac9c65ULL,
    0x2de92c6f592b0275ULL,0x4a7484aa6ea6e483ULL,0x5cb0a9dcbd41fbd4ULL,0x76f988da831153b5ULL,
    0x983e5152ee66dfabULL,0xa831c66d2db43210ULL,0xb00327c898fb213fULL,0xbf597fc7beef0ee4ULL,
    0xc6e00bf33da88fc2ULL,0xd5a79147930aa725ULL,0x06ca6351e003826fULL,0x142929670a0e6e70ULL,
    0x27b70a8546d22ffcULL,0x2e1b21385c26c926ULL,0x4d2c6dfc5ac42aedULL,0x53380d139d95b3dfULL,
    0x650a73548baf63deULL,0x766a0abb3c77b2a8ULL,0x81c2c92e47edaee6ULL,0x92722c851482353bULL,
    0xa2bfe8a14cf10364ULL,0xa81a664bbc423001ULL,0xc24b8b70d0f89791ULL,0xc76c51a30654be30ULL,
    0xd192e819d6ef5218ULL,0xd69906245565a910ULL,0xf40e35855771202aULL,0x106aa07032bbd1b8ULL,
    0x19a4c116b8d2d0c8ULL,0x1e376c085141ab53ULL,0x2748774cdf8eeb99ULL,0x34b0bcb5e19b48a8ULL,
    0x391c0cb3c5c95a63ULL,0x4ed8aa4ae3418acbULL,0x5b9cca4f7763e373ULL,0x682e6ff3d6b2b8a3ULL,
    0x748f82ee5defb2fcULL,0x78a5636f43172f60ULL,0x84c87814a1f0ab72ULL,0x8cc702081a6439ecULL,
    0x90befffa23631e28ULL,0xa4506cebde82bde9ULL,0xbef9a3f7b2c67915ULL,0xc67178f2e372532bULL,
    0xca273eceea26619cULL,0xd186b8c721c0c207ULL,0xeada7dd6cde0eb1eULL,0xf57d4f7fee6ed178ULL,
    0x06f067aa72176fbaULL,0x0a637dc5a2c898a6ULL,0x113f9804bef90daeULL,0x1b710b35131c471bULL,
    0x28db77f523047d84ULL,0x32caab7b40c72493ULL,0x3c9ebe0a15c9bebcULL,0x431d67c49c100d4cULL,
    0x4cc5d4becb3e42b6ULL,0x597f299cfc657e2aULL,0x5fcb6fab3ad6faecULL,0x6c44198c4a475817ULL,
};

#undef _SHR
#undef _ROTR
#define _SHR64(x,n) ((x)>>(n))
#define _ROTR64(x,n) (((x)>>(n))|((x)<<(64-(n))))
#define _BSIG0_64(x) (_ROTR64(x,28)^_ROTR64(x,34)^_ROTR64(x,39))
#define _BSIG1_64(x) (_ROTR64(x,14)^_ROTR64(x,18)^_ROTR64(x,41))
#define _SSIG0_64(x) (_ROTR64(x,1)^_ROTR64(x,8)^_SHR64(x,7))
#define _SSIG1_64(x) (_ROTR64(x,19)^_ROTR64(x,61)^_SHR64(x,6))

typedef struct { uint64_t h[8]; uint64_t len[2]; uint8_t buf[128]; int pos; } _sha512_ctx;

static inline void _sha512_init(_sha512_ctx *c) {
    c->h[0]=0x6a09e667f3bcc908ULL;c->h[1]=0xbb67ae8584caa73bULL;c->h[2]=0x3c6ef372fe94f82bULL;c->h[3]=0xa54ff53a5f1d36f1ULL;
    c->h[4]=0x510e527fade682d1ULL;c->h[5]=0x9b05688c2b3e6c1fULL;c->h[6]=0x1f83d9abfb41bd6bULL;c->h[7]=0x5be0cd19137e2179ULL;
    c->len[0]=0;c->len[1]=0;c->pos=0;
}

static void _sha512_transform(uint64_t *h, const uint8_t *data) {
    uint64_t w[80];
    uint64_t a=h[0],b=h[1],c2=h[2],d=h[3],e=h[4],f=h[5],g=h[6],h0=h[7];
    for(int i=0;i<16;i++) w[i]=((uint64_t)data[i*8]<<56)|((uint64_t)data[i*8+1]<<48)|((uint64_t)data[i*8+2]<<40)|((uint64_t)data[i*8+3]<<32)|((uint64_t)data[i*8+4]<<24)|((uint64_t)data[i*8+5]<<16)|((uint64_t)data[i*8+6]<<8)|(uint64_t)data[i*8+7];
    for(int i=16;i<80;i++) w[i]=_SSIG1_64(w[i-2])+w[i-7]+_SSIG0_64(w[i-15])+w[i-16];
    for(int i=0;i<80;i++){uint64_t t1=h0+_BSIG1_64(e)+_CH(e,f,g)+_sha512_k[i]+w[i],t2=_BSIG0_64(a)+_MAJ(a,b,c2);h0=g;g=f;f=e;e=d+t1;d=c2;c2=b;b=a;a=t1+t2;}
    h[0]+=a;h[1]+=b;h[2]+=c2;h[3]+=d;h[4]+=e;h[5]+=f;h[6]+=g;h[7]+=h0;
}

static void _sha512_update(_sha512_ctx *c, const uint8_t *data, size_t len) {
    c->len[0]+=(uint64_t)len;if(c->len[0]<(uint64_t)len)c->len[1]++;
    for(size_t i=0;i<len;i++){c->buf[c->pos++]=data[i];if(c->pos==128){_sha512_transform(c->h,c->buf);c->pos=0;}}
}

static void _sha512_final(_sha512_ctx *c, uint8_t *digest) {
    c->buf[c->pos++]=0x80;
    if(c->pos>112){while(c->pos<128)c->buf[c->pos++]=0;_sha512_transform(c->h,c->buf);c->pos=0;}
    while(c->pos<112)c->buf[c->pos++]=0;
    uint64_t hi=(c->len[0]>>61)|(c->len[1]<<3),lo=c->len[0]<<3;
    for(int i=7;i>=0;i--)c->buf[c->pos++]=(uint8_t)(hi>>(i*8));
    for(int i=7;i>=0;i--)c->buf[c->pos++]=(uint8_t)(lo>>(i*8));
    _sha512_transform(c->h,c->buf);
    for(int i=0;i<8;i++){digest[i*8]=(uint8_t)(c->h[i]>>56);digest[i*8+1]=(uint8_t)(c->h[i]>>48);digest[i*8+2]=(uint8_t)(c->h[i]>>40);digest[i*8+3]=(uint8_t)(c->h[i]>>32);digest[i*8+4]=(uint8_t)(c->h[i]>>24);digest[i*8+5]=(uint8_t)(c->h[i]>>16);digest[i*8+6]=(uint8_t)(c->h[i]>>8);digest[i*8+7]=(uint8_t)c->h[i];}
}

static t_string tphp_fn_sha512(t_string s) {
    _sha512_ctx c;_sha512_init(&c);
    _sha512_update(&c,(const uint8_t*)STR_PTR(s),(size_t)s.length);
    uint8_t d[64];_sha512_final(&c,d);
    static const char hx[]="0123456789abcdef";
    char *buf=str_pool_alloc(128);if(!buf)return (t_string){NULL,0};
    for(int i=0;i<64;i++){buf[i*2]=hx[d[i]>>4];buf[i*2+1]=hx[d[i]&0xf];}
    return (t_string){buf,128};
}

#undef _SHR64
#undef _ROTR64
#undef _BSIG0_64
#undef _BSIG1_64
#undef _SSIG0_64
#undef _SSIG1_64

/* ─── HMAC (RFC 2104) ────────────────────────────────── */
/* H(K XOR opad, H(K XOR ipad, text)) — 复用 SHA-256/SHA-512 */
/* 支持 sha256 / sha512，binary=true 返回原始摘要，否则小写 hex */

static t_string tphp_fn_hash_hmac(t_string algo, t_string data, t_string key, t_bool binary) {
    const char *a = STR_PTR(algo) ? STR_PTR(algo) : "";
    int is256 = (strcmp(a, "sha256") == 0);
    int is512 = (strcmp(a, "sha512") == 0);
    if (!is256 && !is512) return (t_string){NULL, 0};

    int bs = is256 ? 64 : 128;   /* block size */
    int ds = is256 ? 32 : 64;    /* digest size */
    const uint8_t *k = (const uint8_t*)(STR_PTR(key) ? STR_PTR(key) : "");
    int klen = key.length;
    const uint8_t *d = (const uint8_t*)(STR_PTR(data) ? STR_PTR(data) : "");
    int dlen = data.length;

    /* Step 1: prepare K0 (block_size bytes; hash first if key too long) */
    uint8_t k0[128];
    memset(k0, 0, (size_t)bs);
    if (klen > bs) {
        if (is256) {
            _sha256_ctx kc; _sha256_init(&kc);
            _sha256_update(&kc, k, (size_t)klen);
            _sha256_final(&kc, k0);
        } else {
            _sha512_ctx kc; _sha512_init(&kc);
            _sha512_update(&kc, k, (size_t)klen);
            _sha512_final(&kc, k0);
        }
    } else {
        memcpy(k0, k, (size_t)klen);
    }

    /* Step 2: K XOR ipad / K XOR opad */
    uint8_t ipad[128], opad[128];
    for (int i = 0; i < bs; i++) {
        ipad[i] = (uint8_t)(k0[i] ^ 0x36);
        opad[i] = (uint8_t)(k0[i] ^ 0x5C);
    }

    /* Step 3: inner = H(K XOR ipad || data) */
    uint8_t inner[64];
    if (is256) {
        _sha256_ctx ic; _sha256_init(&ic);
        _sha256_update(&ic, ipad, (size_t)bs);
        _sha256_update(&ic, d, (size_t)dlen);
        _sha256_final(&ic, inner);
    } else {
        _sha512_ctx ic; _sha512_init(&ic);
        _sha512_update(&ic, ipad, (size_t)bs);
        _sha512_update(&ic, d, (size_t)dlen);
        _sha512_final(&ic, inner);
    }

    /* Step 4: result = H(K XOR opad || inner) */
    uint8_t result[64];
    if (is256) {
        _sha256_ctx oc; _sha256_init(&oc);
        _sha256_update(&oc, opad, (size_t)bs);
        _sha256_update(&oc, inner, 32);
        _sha256_final(&oc, result);
    } else {
        _sha512_ctx oc; _sha512_init(&oc);
        _sha512_update(&oc, opad, (size_t)bs);
        _sha512_update(&oc, inner, 64);
        _sha512_final(&oc, result);
    }

    /* Step 5: output (raw binary or lowercase hex) */
    if (binary) {
        char *buf = str_pool_alloc(ds);
        if (!buf) return (t_string){NULL, 0};
        memcpy(buf, result, (size_t)ds);
        return (t_string){buf, ds};
    }
    static const char hx[] = "0123456789abcdef";
    int hexLen = ds * 2;
    char *buf = str_pool_alloc(hexLen);
    if (!buf) return (t_string){NULL, 0};
    for (int i = 0; i < ds; i++) {
        buf[i*2]   = hx[result[i] >> 4];
        buf[i*2+1] = hx[result[i] & 0xf];
    }
    return (t_string){buf, hexLen};
}
