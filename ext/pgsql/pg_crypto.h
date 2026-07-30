#pragma once
// ============================================================
// pg_crypto.h — PostgreSQL 扩展密码学原语
//
// 实现：
//   - SHA-256（FIPS 180-4）：SCRAM-SHA-256 和 HMAC-SHA-256 需要
//   - HMAC-SHA-256（RFC 2104）：SCRAM-SHA-256 认证需要
//   - PBKDF2-HMAC-SHA-256（RFC 2898）：SCRAM-SHA-256 密钥派生需要
//   - Base64（RFC 4648）：SCRAM nonce 和 salt 传输需要
//   - MD5（RFC 1321）：PostgreSQL MD5 密码认证需要
//
// 设计说明：
//   - 所有函数 static 限定，避免符号冲突
//   - 命名前缀 _pg_，与 include/hash.h 中的 _sha256_/_md5_ 区分
//   - 宏前缀 _PG_，避免与 hash.h 的 _SHR/_ROTR/_CH/_MAJ 等冲突
//   - 自包含实现，不依赖 include/hash.h（hash.h 通过 common.h 已引入，
//     但其函数名为 _sha256_*/_md5_*，与本文件的 _pg_* 无冲突）
//
// 测试向量：
//   SHA-256("abc")  == ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad
//   SHA-256("")     == e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855
//   MD5("")         == d41d8cd98f00b204e9800998ecf8427e
//   MD5("abc")      == 900150983cd24fb0d6963f7d28e17f72
// ============================================================

#include <stdint.h>
#include <stdlib.h>
#include <string.h>

// ============================================================
// SHA-256（FIPS 180-4）
// ============================================================

// SHA-256 宏（使用 _PG_ 前缀避免与 hash.h 的 _SHR/_ROTR 等冲突）
#define _PG_SHR(x,n)  ((x) >> (n))
#define _PG_ROTR(x,n) (((x) >> (n)) | ((x) << (32 - (n))))
#define _PG_CH(x,y,z) (((x) & (y)) ^ (~(x) & (z)))
#define _PG_MAJ(x,y,z) (((x) & (y)) ^ ((x) & (z)) ^ ((y) & (z)))
#define _PG_BSIG0(x) (_PG_ROTR(x,2) ^ _PG_ROTR(x,13) ^ _PG_ROTR(x,22))
#define _PG_BSIG1(x) (_PG_ROTR(x,6) ^ _PG_ROTR(x,11) ^ _PG_ROTR(x,25))
#define _PG_SSIG0(x) (_PG_ROTR(x,7) ^ _PG_ROTR(x,18) ^ _PG_SHR(x,3))
#define _PG_SSIG1(x) (_PG_ROTR(x,17) ^ _PG_ROTR(x,19) ^ _PG_SHR(x,10))

// SHA-256 常量表 K[64]
static const uint32_t _pg_sha256_k[64] = {
    0x428a2f98, 0x71374491, 0xb5c0fbcf, 0xe9b5dba5,
    0x3956c25b, 0x59f111f1, 0x923f82a4, 0xab1c5ed5,
    0xd807aa98, 0x12835b01, 0x243185be, 0x550c7dc3,
    0x72be5d74, 0x80deb1fe, 0x9bdc06a7, 0xc19bf174,
    0xe49b69c1, 0xefbe4786, 0x0fc19dc6, 0x240ca1cc,
    0x2de92c6f, 0x4a7484aa, 0x5cb0a9dc, 0x76f988da,
    0x983e5152, 0xa831c66d, 0xb00327c8, 0xbf597fc7,
    0xc6e00bf3, 0xd5a79147, 0x06ca6351, 0x14292967,
    0x27b70a85, 0x2e1b2138, 0x4d2c6dfc, 0x53380d13,
    0x650a7354, 0x766a0abb, 0x81c2c92e, 0x92722c85,
    0xa2bfe8a1, 0xa81a664b, 0xc24b8b70, 0xc76c51a3,
    0xd192e819, 0xd6990624, 0xf40e3585, 0x106aa070,
    0x19a4c116, 0x1e376c08, 0x2748774c, 0x34b0bcb5,
    0x391c0cb3, 0x4ed8aa4a, 0x5b9cca4f, 0x682e6ff3,
    0x748f82ee, 0x78a5636f, 0x84c87814, 0x8cc70208,
    0x90befffa, 0xa4506ceb, 0xbef9a3f7, 0xc67178f2
};

// SHA-256 上下文
typedef struct {
    uint32_t h[8];       // 哈希状态
    uint64_t bitlen;      // 已处理的比特数
    uint8_t  buf[64];     // 当前块缓冲
    int      buf_pos;     // 缓冲区已用字节数
} _pg_sha256_ctx;

// SHA-256 处理一个 512-bit 块
static void _pg_sha256_transform(uint32_t h[8], const uint8_t block[64]) {
    uint32_t w[64];
    uint32_t a, b, c, d, e, f, g, hh;
    int i;

    // 将 64 字节拆分为 16 个 32-bit 大端字
    for (i = 0; i < 16; i++) {
        w[i] = ((uint32_t)block[i*4] << 24)
             | ((uint32_t)block[i*4+1] << 16)
             | ((uint32_t)block[i*4+2] << 8)
             | ((uint32_t)block[i*4+3]);
    }
    // 扩展为 64 个字
    for (i = 16; i < 64; i++) {
        w[i] = _PG_SSIG1(w[i-2]) + w[i-7] + _PG_SSIG0(w[i-15]) + w[i-16];
    }

    a = h[0]; b = h[1]; c = h[2]; d = h[3];
    e = h[4]; f = h[5]; g = h[6]; hh = h[7];

    for (i = 0; i < 64; i++) {
        uint32_t t1 = hh + _PG_BSIG1(e) + _PG_CH(e, f, g) + _pg_sha256_k[i] + w[i];
        uint32_t t2 = _PG_BSIG0(a) + _PG_MAJ(a, b, c);
        hh = g; g = f; f = e; e = d + t1;
        d = c; c = b; b = a; a = t1 + t2;
    }

    h[0] += a; h[1] += b; h[2] += c; h[3] += d;
    h[4] += e; h[5] += f; h[6] += g; h[7] += hh;
}

// SHA-256 初始化
static void _pg_sha256_init(_pg_sha256_ctx *ctx) {
    ctx->h[0] = 0x6a09e667;
    ctx->h[1] = 0xbb67ae85;
    ctx->h[2] = 0x3c6ef372;
    ctx->h[3] = 0xa54ff53a;
    ctx->h[4] = 0x510e527f;
    ctx->h[5] = 0x9b05688c;
    ctx->h[6] = 0x1f83d9ab;
    ctx->h[7] = 0x5be0cd19;
    ctx->bitlen = 0;
    ctx->buf_pos = 0;
}

// SHA-256 更新数据
static void _pg_sha256_update(_pg_sha256_ctx *ctx, const uint8_t *data, size_t len) {
    size_t i;
    ctx->bitlen += (uint64_t)len * 8;
    for (i = 0; i < len; i++) {
        ctx->buf[ctx->buf_pos++] = data[i];
        if (ctx->buf_pos == 64) {
            _pg_sha256_transform(ctx->h, ctx->buf);
            ctx->buf_pos = 0;
        }
    }
}

// SHA-256 最终输出（32 字节原始摘要）
static void _pg_sha256_final(_pg_sha256_ctx *ctx, uint8_t digest[32]) {
    uint64_t bitlen = ctx->bitlen;
    int i;

    // 添加 0x80
    ctx->buf[ctx->buf_pos++] = 0x80;

    // 如果剩余不足 8 字节存放长度，先填满一块处理
    if (ctx->buf_pos > 56) {
        while (ctx->buf_pos < 64) ctx->buf[ctx->buf_pos++] = 0;
        _pg_sha256_transform(ctx->h, ctx->buf);
        ctx->buf_pos = 0;
    }
    // 填 0 到第 56 字节
    while (ctx->buf_pos < 56) ctx->buf[ctx->buf_pos++] = 0;

    // 写入 64-bit 长度（大端）
    for (i = 7; i >= 0; i--) {
        ctx->buf[ctx->buf_pos++] = (uint8_t)((bitlen >> (i * 8)) & 0xFF);
    }
    _pg_sha256_transform(ctx->h, ctx->buf);

    // 输出摘要（大端）
    for (i = 0; i < 8; i++) {
        digest[i*4]   = (uint8_t)((ctx->h[i] >> 24) & 0xFF);
        digest[i*4+1] = (uint8_t)((ctx->h[i] >> 16) & 0xFF);
        digest[i*4+2] = (uint8_t)((ctx->h[i] >> 8) & 0xFF);
        digest[i*4+3] = (uint8_t)(ctx->h[i] & 0xFF);
    }
}

// SHA-256 便捷函数：一次性计算
static void _pg_sha256_hash(const uint8_t *data, size_t len, uint8_t digest[32]) {
    _pg_sha256_ctx ctx;
    _pg_sha256_init(&ctx);
    _pg_sha256_update(&ctx, data, len);
    _pg_sha256_final(&ctx, digest);
}

// SHA-256 便捷函数：计算并输出 64 字符 hex 字符串
static void _pg_sha256_hex(const uint8_t *data, size_t len, char hex_out[65]) {
    uint8_t digest[32];
    static const char hx[] = "0123456789abcdef";
    int i;
    _pg_sha256_hash(data, len, digest);
    for (i = 0; i < 32; i++) {
        hex_out[i*2]   = hx[digest[i] >> 4];
        hex_out[i*2+1] = hx[digest[i] & 0x0F];
    }
    hex_out[64] = '\0';
}

// ============================================================
// HMAC-SHA-256（RFC 2104）
//   H(K XOR opad, H(K XOR ipad, text))
//   block_size = 64, output_size = 32
// ============================================================

// HMAC-SHA-256：输出 32 字节原始摘要
static void _pg_hmac_sha256(const uint8_t *key, size_t key_len,
                             const uint8_t *data, size_t data_len,
                             uint8_t out[32]) {
    uint8_t k0[64];      // 填充后的 key（block_size 字节）
    uint8_t ipad[64];    // K XOR ipad
    uint8_t opad[64];    // K XOR opad
    uint8_t inner[32];   // 内层哈希结果
    _pg_sha256_ctx ctx;
    int i;

    // Step 1: 准备 K0（如果 key 过长则先哈希）
    memset(k0, 0, 64);
    if (key_len > 64) {
        _pg_sha256_hash(key, key_len, k0);
    } else {
        memcpy(k0, key, key_len);
    }

    // Step 2: K XOR ipad / K XOR opad
    for (i = 0; i < 64; i++) {
        ipad[i] = (uint8_t)(k0[i] ^ 0x36);
        opad[i] = (uint8_t)(k0[i] ^ 0x5C);
    }

    // Step 3: inner = H(K XOR ipad || data)
    _pg_sha256_init(&ctx);
    _pg_sha256_update(&ctx, ipad, 64);
    _pg_sha256_update(&ctx, data, data_len);
    _pg_sha256_final(&ctx, inner);

    // Step 4: out = H(K XOR opad || inner)
    _pg_sha256_init(&ctx);
    _pg_sha256_update(&ctx, opad, 64);
    _pg_sha256_update(&ctx, inner, 32);
    _pg_sha256_final(&ctx, out);
}

// ============================================================
// PBKDF2-HMAC-SHA-256（RFC 2898 / RFC 7914）
//   DK = PBKDF2(PRF, Password, Salt, c, dkLen)
//   每个 block：T_i = F(Password, Salt, c, i)
//   F(Password, Salt, c, i) = U_1 XOR U_2 XOR ... XOR U_c
//   U_1 = PRF(Password, Salt || INT_32_BE(i))
//   U_j = PRF(Password, U_{j-1})
// ============================================================

// PBKDF2-HMAC-SHA-256
//   password:  密码
//   pass_len:  密码长度
//   salt:      盐值
//   salt_len:  盐值长度
//   iterations: 迭代次数
//   out:       输出缓冲区
//   out_len:   输出长度（dkLen）
static void _pg_pbkdf2_hmac_sha256(const uint8_t *password, size_t pass_len,
                                    const uint8_t *salt, size_t salt_len,
                                    uint32_t iterations,
                                    uint8_t *out, size_t out_len) {
    uint32_t i;
    int j, k;
    size_t pos = 0;
    uint8_t U[32];
    uint8_t T[32];
    uint8_t salt_block[256];  // salt + 4 字节序号

    // 处理每个块（每块 32 字节）
    uint32_t num_blocks = (uint32_t)((out_len + 31) / 32);
    for (i = 1; i <= num_blocks; i++) {
        // 构造 Salt || INT_32_BE(i)
        if (salt_len + 4 <= sizeof(salt_block)) {
            memcpy(salt_block, salt, salt_len);
            salt_block[salt_len]   = (uint8_t)((i >> 24) & 0xFF);
            salt_block[salt_len+1] = (uint8_t)((i >> 16) & 0xFF);
            salt_block[salt_len+2] = (uint8_t)((i >> 8) & 0xFF);
            salt_block[salt_len+3] = (uint8_t)(i & 0xFF);

            // U_1 = HMAC-SHA-256(Password, Salt || INT_32_BE(i))
            _pg_hmac_sha256(password, pass_len, salt_block, salt_len + 4, U);
            memcpy(T, U, 32);

            // U_2 ~ U_c
            for (j = 1; j < (int)iterations; j++) {
                _pg_hmac_sha256(password, pass_len, U, 32, U);
                for (k = 0; k < 32; k++) {
                    T[k] ^= U[k];
                }
            }
        }

        // 写入输出
        size_t copy_len = (pos + 32 <= out_len) ? 32 : (out_len - pos);
        memcpy(out + pos, T, copy_len);
        pos += copy_len;
    }
}

// ============================================================
// Base64（RFC 4648）
// ============================================================

static const char _pg_b64_table[] = "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/";

// Base64 编码
//   data:     输入数据
//   len:      输入长度
//   out:      输出缓冲区（调用方保证容量 >= 4*((len+2)/3) + 1）
//   返回值：   输出长度（不含 NUL）
static int _pg_base64_encode(const uint8_t *data, int len, char *out) {
    int i, j = 0;
    for (i = 0; i + 2 < len; i += 3) {
        uint32_t v = ((uint32_t)data[i] << 16) | ((uint32_t)data[i+1] << 8) | data[i+2];
        out[j++] = _pg_b64_table[(v >> 18) & 0x3F];
        out[j++] = _pg_b64_table[(v >> 12) & 0x3F];
        out[j++] = _pg_b64_table[(v >> 6) & 0x3F];
        out[j++] = _pg_b64_table[v & 0x3F];
    }
    int rem = len - i;
    if (rem == 1) {
        uint32_t v = (uint32_t)data[i] << 16;
        out[j++] = _pg_b64_table[(v >> 18) & 0x3F];
        out[j++] = _pg_b64_table[(v >> 12) & 0x3F];
        out[j++] = '=';
        out[j++] = '=';
    } else if (rem == 2) {
        uint32_t v = ((uint32_t)data[i] << 16) | ((uint32_t)data[i+1] << 8);
        out[j++] = _pg_b64_table[(v >> 18) & 0x3F];
        out[j++] = _pg_b64_table[(v >> 12) & 0x3F];
        out[j++] = _pg_b64_table[(v >> 6) & 0x3F];
        out[j++] = '=';
    }
    out[j] = '\0';
    return j;
}

// Base64 解码表（运行时构建，避免静态初始化的符号冲突）
static int _pg_b64_decode_val(char c) {
    if (c >= 'A' && c <= 'Z') return c - 'A';
    if (c >= 'a' && c <= 'z') return c - 'a' + 26;
    if (c >= '0' && c <= '9') return c - '0' + 52;
    if (c == '+') return 62;
    if (c == '/') return 63;
    return -1;  // 包含 '=' 和非法字符
}

// Base64 解码
//   data:     输入的 Base64 字符串
//   len:      输入长度
//   out:      输出缓冲区（调用方保证容量 >= 3*(len/4)）
//   返回值：   输出字节数，<0 表示错误
static int _pg_base64_decode(const char *data, int len, uint8_t *out) {
    int i, j = 0;
    int buf[4];
    int bi = 0;

    for (i = 0; i < len; i++) {
        char c = data[i];
        if (c == '=' || c == '\0' || c == '\n' || c == '\r' || c == ' ') continue;
        int v = _pg_b64_decode_val(c);
        if (v < 0) return -1;
        buf[bi++] = v;
        if (bi == 4) {
            out[j++] = (uint8_t)((buf[0] << 2) | (buf[1] >> 4));
            out[j++] = (uint8_t)((buf[1] << 4) | (buf[2] >> 2));
            out[j++] = (uint8_t)((buf[2] << 6) | buf[3]);
            bi = 0;
        }
    }
    // 处理剩余 2 或 3 个字符
    if (bi == 2) {
        out[j++] = (uint8_t)((buf[0] << 2) | (buf[1] >> 4));
    } else if (bi == 3) {
        out[j++] = (uint8_t)((buf[0] << 2) | (buf[1] >> 4));
        out[j++] = (uint8_t)((buf[1] << 4) | (buf[2] >> 2));
    }
    return j;
}

// ============================================================
// MD5（RFC 1321）
//   用于 PostgreSQL MD5 密码认证
//   输出 16 字节原始摘要，常表示为 32 字符 hex 字符串
// ============================================================

// MD5 宏
#define _PG_MD5_F(x,y,z) ((z) ^ ((x) & ((y) ^ (z))))
#define _PG_MD5_G(x,y,z) ((y) ^ ((z) & ((x) ^ (y))))
#define _PG_MD5_H(x,y,z) ((x) ^ (y) ^ (z))
#define _PG_MD5_I(x,y,z) ((y) ^ ((x) | ~(z)))
#define _PG_MD5_ROTL(v,s) (((v) << (s)) | ((v) >> (32 - (s))))
#define _PG_MD5_STEP(f,a,b,c,d,x,s,t) do { a += f(b,c,d) + x + t; a = _PG_MD5_ROTL(a, s); a += b; } while(0)

// MD5 上下文
typedef struct {
    uint32_t v[4];       // 哈希状态（A, B, C, D）
    uint64_t bitlen;      // 已处理的比特数
    uint8_t  buf[64];     // 当前块缓冲
    int      buf_pos;     // 缓冲区已用字节数
} _pg_md5_ctx;

// MD5 处理一个 512-bit 块
static void _pg_md5_transform(_pg_md5_ctx *ctx) {
    uint32_t x[16], a, b, cc, d;
    int i;
    for (i = 0; i < 16; i++) {
        x[i] = (uint32_t)ctx->buf[i*4]
             | ((uint32_t)ctx->buf[i*4+1] << 8)
             | ((uint32_t)ctx->buf[i*4+2] << 16)
             | ((uint32_t)ctx->buf[i*4+3] << 24);
    }
    a = ctx->v[0]; b = ctx->v[1]; cc = ctx->v[2]; d = ctx->v[3];

    // Round 1
    _PG_MD5_STEP(_PG_MD5_F, a, b, cc, d, x[ 0], 7, 0xD76AA478);
    _PG_MD5_STEP(_PG_MD5_F, d, a, b, cc, x[ 1],12, 0xE8C7B756);
    _PG_MD5_STEP(_PG_MD5_F, cc, d, a, b, x[ 2],17, 0x242070DB);
    _PG_MD5_STEP(_PG_MD5_F, b, cc, d, a, x[ 3],22, 0xC1BDCEEE);
    _PG_MD5_STEP(_PG_MD5_F, a, b, cc, d, x[ 4], 7, 0xF57C0FAF);
    _PG_MD5_STEP(_PG_MD5_F, d, a, b, cc, x[ 5],12, 0x4787C62A);
    _PG_MD5_STEP(_PG_MD5_F, cc, d, a, b, x[ 6],17, 0xA8304613);
    _PG_MD5_STEP(_PG_MD5_F, b, cc, d, a, x[ 7],22, 0xFD469501);
    _PG_MD5_STEP(_PG_MD5_F, a, b, cc, d, x[ 8], 7, 0x698098D8);
    _PG_MD5_STEP(_PG_MD5_F, d, a, b, cc, x[ 9],12, 0x8B44F7AF);
    _PG_MD5_STEP(_PG_MD5_F, cc, d, a, b, x[10],17, 0xFFFF5BB1);
    _PG_MD5_STEP(_PG_MD5_F, b, cc, d, a, x[11],22, 0x895CD7BE);
    _PG_MD5_STEP(_PG_MD5_F, a, b, cc, d, x[12], 7, 0x6B901122);
    _PG_MD5_STEP(_PG_MD5_F, d, a, b, cc, x[13],12, 0xFD987193);
    _PG_MD5_STEP(_PG_MD5_F, cc, d, a, b, x[14],17, 0xA679438E);
    _PG_MD5_STEP(_PG_MD5_F, b, cc, d, a, x[15],22, 0x49B40821);
    // Round 2
    _PG_MD5_STEP(_PG_MD5_G, a, b, cc, d, x[ 1], 5, 0xF61E2562);
    _PG_MD5_STEP(_PG_MD5_G, d, a, b, cc, x[ 6], 9, 0xC040B340);
    _PG_MD5_STEP(_PG_MD5_G, cc, d, a, b, x[11],14, 0x265E5A51);
    _PG_MD5_STEP(_PG_MD5_G, b, cc, d, a, x[ 0],20, 0xE9B6C7AA);
    _PG_MD5_STEP(_PG_MD5_G, a, b, cc, d, x[ 5], 5, 0xD62F105D);
    _PG_MD5_STEP(_PG_MD5_G, d, a, b, cc, x[10], 9, 0x02441453);
    _PG_MD5_STEP(_PG_MD5_G, cc, d, a, b, x[15],14, 0xD8A1E681);
    _PG_MD5_STEP(_PG_MD5_G, b, cc, d, a, x[ 4],20, 0xE7D3FBC8);
    _PG_MD5_STEP(_PG_MD5_G, a, b, cc, d, x[ 9], 5, 0x21E1CDE6);
    _PG_MD5_STEP(_PG_MD5_G, d, a, b, cc, x[14], 9, 0xC33707D6);
    _PG_MD5_STEP(_PG_MD5_G, cc, d, a, b, x[ 3],14, 0xF4D50D87);
    _PG_MD5_STEP(_PG_MD5_G, b, cc, d, a, x[ 8],20, 0x455A14ED);
    _PG_MD5_STEP(_PG_MD5_G, a, b, cc, d, x[13], 5, 0xA9E3E905);
    _PG_MD5_STEP(_PG_MD5_G, d, a, b, cc, x[ 2], 9, 0xFCEFA3F8);
    _PG_MD5_STEP(_PG_MD5_G, cc, d, a, b, x[ 7],14, 0x676F02D9);
    _PG_MD5_STEP(_PG_MD5_G, b, cc, d, a, x[12],20, 0x8D2A4C8A);
    // Round 3
    _PG_MD5_STEP(_PG_MD5_H, a, b, cc, d, x[ 5], 4, 0xFFFA3942);
    _PG_MD5_STEP(_PG_MD5_H, d, a, b, cc, x[ 8],11, 0x8771F681);
    _PG_MD5_STEP(_PG_MD5_H, cc, d, a, b, x[11],16, 0x6D9D6122);
    _PG_MD5_STEP(_PG_MD5_H, b, cc, d, a, x[14],23, 0xFDE5380C);
    _PG_MD5_STEP(_PG_MD5_H, a, b, cc, d, x[ 1], 4, 0xA4BEEA44);
    _PG_MD5_STEP(_PG_MD5_H, d, a, b, cc, x[ 4],11, 0x4BDECFA9);
    _PG_MD5_STEP(_PG_MD5_H, cc, d, a, b, x[ 7],16, 0xF6BB4B60);
    _PG_MD5_STEP(_PG_MD5_H, b, cc, d, a, x[10],23, 0xBEBFBC70);
    _PG_MD5_STEP(_PG_MD5_H, a, b, cc, d, x[13], 4, 0x289B7EC6);
    _PG_MD5_STEP(_PG_MD5_H, d, a, b, cc, x[ 0],11, 0xEAA127FA);
    _PG_MD5_STEP(_PG_MD5_H, cc, d, a, b, x[ 3],16, 0xD4EF3085);
    _PG_MD5_STEP(_PG_MD5_H, b, cc, d, a, x[ 6],23, 0x04881D05);
    _PG_MD5_STEP(_PG_MD5_H, a, b, cc, d, x[ 9], 4, 0xD9D4D039);
    _PG_MD5_STEP(_PG_MD5_H, d, a, b, cc, x[12],11, 0xE6DB99E5);
    _PG_MD5_STEP(_PG_MD5_H, cc, d, a, b, x[15],16, 0x1FA27CF8);
    _PG_MD5_STEP(_PG_MD5_H, b, cc, d, a, x[ 2],23, 0xC4AC5665);
    // Round 4
    _PG_MD5_STEP(_PG_MD5_I, a, b, cc, d, x[ 0], 6, 0xF4292244);
    _PG_MD5_STEP(_PG_MD5_I, d, a, b, cc, x[ 7],10, 0x432AFF97);
    _PG_MD5_STEP(_PG_MD5_I, cc, d, a, b, x[14],15, 0xAB9423A7);
    _PG_MD5_STEP(_PG_MD5_I, b, cc, d, a, x[ 5],21, 0xFC93A039);
    _PG_MD5_STEP(_PG_MD5_I, a, b, cc, d, x[12], 6, 0x655B59C3);
    _PG_MD5_STEP(_PG_MD5_I, d, a, b, cc, x[ 3],10, 0x8F0CCC92);
    _PG_MD5_STEP(_PG_MD5_I, cc, d, a, b, x[10],15, 0xFFEFF47D);
    _PG_MD5_STEP(_PG_MD5_I, b, cc, d, a, x[ 1],21, 0x85845DD1);
    _PG_MD5_STEP(_PG_MD5_I, a, b, cc, d, x[ 8], 6, 0x6FA87E4F);
    _PG_MD5_STEP(_PG_MD5_I, d, a, b, cc, x[15],10, 0xFE2CE6E0);
    _PG_MD5_STEP(_PG_MD5_I, cc, d, a, b, x[ 6],15, 0xA3014314);
    _PG_MD5_STEP(_PG_MD5_I, b, cc, d, a, x[13],21, 0x4E0811A1);
    _PG_MD5_STEP(_PG_MD5_I, a, b, cc, d, x[ 4], 6, 0xF7537E82);
    _PG_MD5_STEP(_PG_MD5_I, d, a, b, cc, x[11],10, 0xBD3AF235);
    _PG_MD5_STEP(_PG_MD5_I, cc, d, a, b, x[ 2],15, 0x2AD7D2BB);
    _PG_MD5_STEP(_PG_MD5_I, b, cc, d, a, x[ 9],21, 0xEB86D391);

    ctx->v[0] += a; ctx->v[1] += b; ctx->v[2] += cc; ctx->v[3] += d;
}

// MD5 初始化
static void _pg_md5_init(_pg_md5_ctx *ctx) {
    ctx->v[0] = 0x67452301;
    ctx->v[1] = 0xEFCDAB89;
    ctx->v[2] = 0x98BADCFE;
    ctx->v[3] = 0x10325476;
    ctx->bitlen = 0;
    ctx->buf_pos = 0;
}

// MD5 更新数据
static void _pg_md5_update(_pg_md5_ctx *ctx, const uint8_t *data, size_t len) {
    size_t i;
    ctx->bitlen += (uint64_t)len * 8;
    for (i = 0; i < len; i++) {
        ctx->buf[ctx->buf_pos++] = data[i];
        if (ctx->buf_pos == 64) {
            _pg_md5_transform(ctx);
            ctx->buf_pos = 0;
        }
    }
}

// MD5 最终输出（16 字节原始摘要，小端序）
static void _pg_md5_final(_pg_md5_ctx *ctx, uint8_t digest[16]) {
    uint64_t bitlen = ctx->bitlen;
    int i;

    // 添加 0x80
    ctx->buf[ctx->buf_pos++] = 0x80;

    // 如果剩余不足 8 字节存放长度，先填满一块处理
    if (ctx->buf_pos > 56) {
        while (ctx->buf_pos < 64) ctx->buf[ctx->buf_pos++] = 0;
        _pg_md5_transform(ctx);
        ctx->buf_pos = 0;
    }
    // 填 0 到第 56 字节
    while (ctx->buf_pos < 56) ctx->buf[ctx->buf_pos++] = 0;

    // 写入 64-bit 长度（小端序，MD5 与 SHA-256 不同）
    for (i = 0; i < 8; i++) {
        ctx->buf[ctx->buf_pos++] = (uint8_t)((bitlen >> (i * 8)) & 0xFF);
    }
    _pg_md5_transform(ctx);

    // 输出摘要（小端序）
    for (i = 0; i < 4; i++) {
        digest[i*4]   = (uint8_t)(ctx->v[i] & 0xFF);
        digest[i*4+1] = (uint8_t)((ctx->v[i] >> 8) & 0xFF);
        digest[i*4+2] = (uint8_t)((ctx->v[i] >> 16) & 0xFF);
        digest[i*4+3] = (uint8_t)((ctx->v[i] >> 24) & 0xFF);
    }
}

// MD5 便捷函数：一次性计算 16 字节原始摘要
static void _pg_md5_hash(const uint8_t *data, size_t len, uint8_t digest[16]) {
    _pg_md5_ctx ctx;
    _pg_md5_init(&ctx);
    _pg_md5_update(&ctx, data, len);
    _pg_md5_final(&ctx, digest);
}

// MD5 便捷函数：计算并输出 32 字符 hex 字符串（+ NUL）
static void _pg_md5_hex(const uint8_t *data, size_t len, char hex_out[33]) {
    uint8_t digest[16];
    static const char hx[] = "0123456789abcdef";
    int i;
    _pg_md5_hash(data, len, digest);
    for (i = 0; i < 16; i++) {
        hex_out[i*2]   = hx[digest[i] >> 4];
        hex_out[i*2+1] = hx[digest[i] & 0x0F];
    }
    hex_out[32] = '\0';
}

// 清理宏，避免污染后续代码
#undef _PG_SHR
#undef _PG_ROTR
#undef _PG_CH
#undef _PG_MAJ
#undef _PG_BSIG0
#undef _PG_BSIG1
#undef _PG_SSIG0
#undef _PG_SSIG1
#undef _PG_MD5_F
#undef _PG_MD5_G
#undef _PG_MD5_H
#undef _PG_MD5_I
#undef _PG_MD5_ROTL
#undef _PG_MD5_STEP
