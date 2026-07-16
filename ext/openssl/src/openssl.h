#pragma once
// ============================================================
// openssl.h — OpenSSL 扩展（TLS/SSL 加密支持）
//
// 设计说明（AOT 机制适配）：
//   - 依赖预编译 OpenSSL 3.0.21 静态库（libssl.a / libcrypto.a）
//     由 CI workflow 构建，放在 ext/openssl/prebuilt/<platform>/lib/
//     头文件放在 ext/openssl/prebuilt/<platform>/include/
//   - 所有函数接收 tphp 类型（t_int/t_string/t_bool），通过 $simpleFnMap 映射
//   - SSL*/SSL_CTX* 指针以 t_int 流转（phpc_ptr_to_int / phpc_int_to_ptr 模式）
//   - 错误统一 tp_throw_ex（可被 try-catch 捕获）
//   - 包含 stream_socket_enable_crypto 的真实 TLS 实现（覆盖 stream.h 的 stub）
//
// 跨平台：
//   Windows: 需额外链接 ws2_32（OpenSSL 依赖 winsock）
//   Linux/macOS: 需链接 pthread/dl（OpenSSL 依赖）
//   编译器: TCC 需 no-asm 构建（OpenSSL 内联 ASM 不兼容 TCC）
//
// 包含顺序：openssl.h 必须在 stream.h 之前 include（由 CodeGenerator 保证）
//   这样 TPHP_STREAM_TLS_IMPLEMENTED 先定义，stream.h 中的 stub 被跳过
// ============================================================

#include "types.h"
#include "object/exception.h"
#include "object/try.h"
#include "val.h"
#include <stdio.h>
#include <stdlib.h>
#include <string.h>

// ── OpenSSL 头文件（通过 -I ext/openssl/prebuilt/<platform>/include 查找）──
#include <openssl/ssl.h>
#include <openssl/err.h>
#include <openssl/crypto.h>
#include <openssl/x509.h>
#include <openssl/pem.h>
#include <openssl/bio.h>
#include <openssl/evp.h>
#include <openssl/rand.h>

// ── 常量（CodeGenerator 需要 TPHP_CONST_ 前缀） ────────────
#define TPHP_CONST_SSL_OP_NO_COMPRESSION              0x00020000L
#define TPHP_CONST_SSL_OP_NO_SSLv2                    0x01000000L
#define TPHP_CONST_SSL_OP_NO_SSLv3                    0x02000000L
#define TPHP_CONST_SSL_OP_NO_TLSv1                    0x04000000L
#define TPHP_CONST_SSL_OP_NO_TLSv1_1                  0x10000000L
#define TPHP_CONST_SSL_OP_NO_TLSv1_2                  0x08000000L
#define TPHP_CONST_SSL_OP_NO_TLSv1_3                  0x20000000L
#define TPHP_CONST_SSL_OP_NO_RENEGOTIATION            0x40000000L
#define TPHP_CONST_SSL_VERIFY_NONE                    0x00
#define TPHP_CONST_SSL_VERIFY_PEER                    0x01
#define TPHP_CONST_SSL_VERIFY_FAIL_IF_NO_PEER_CERT    0x02
#define TPHP_CONST_SSL_FILETYPE_PEM                   1
#define TPHP_CONST_SSL_FILETYPE_ASN1                  2
#define TPHP_CONST_X509_FILETYPE_PEM                  1
#define TPHP_CONST_X509_FILETYPE_ASN1                 2
#define TPHP_CONST_OPENSSL_KEYTYPE_RSA                0
#define TPHP_CONST_OPENSSL_KEYTYPE_DSA                1
#define TPHP_CONST_OPENSSL_KEYTYPE_DH                 2
#define TPHP_CONST_OPENSSL_KEYTYPE_EC                 3
#define TPHP_CONST_OPENSSL_ALGO_MD5                   2
#define TPHP_CONST_OPENSSL_ALGO_SHA1                  1
#define TPHP_CONST_OPENSSL_ALGO_SHA256                7
#define TPHP_CONST_OPENSSL_ALGO_SHA384                8
#define TPHP_CONST_OPENSSL_ALGO_SHA512                9
#define TPHP_CONST_OPENSSL_CIPHER_AES_128_CBC         5
#define TPHP_CONST_OPENSSL_CIPHER_AES_192_CBC         6
#define TPHP_CONST_OPENSSL_CIPHER_AES_256_CBC         7
#define TPHP_CONST_OPENSSL_RAW_DATA                   1
#define TPHP_CONST_OPENSSL_ZERO_PADDING               2
#define TPHP_CONST_OPENSSL_DONT_ZERO_PAD_KEY          4
#define TPHP_CONST_OPENSSL_NO_PADDING                 3
#define TPHP_CONST_OPENSSL_PKCS1_PADDING              1
#define TPHP_CONST_OPENSSL_PKCS1_OAEP_PADDING         4
#define TPHP_CONST_OPENSSL_SSLV23_PADDING             2
#define TPHP_CONST_OPENSSL_DEFAULT_STREAM_CIPHERS    "ECDHE-RSA-AES128-GCM-SHA256:ECDHE-ECDSA-AES128-GCM-SHA256"
#define TPHP_CONST_OPENSSL_PURPOSE_ANY                       0
#define TPHP_CONST_OPENSSL_PURPOSE_SSL_SERVER                 1
#define TPHP_CONST_OPENSSL_PURPOSE_SSL_CLIENT                 2

// ── 内部：抛异常辅助 ────────────────────────────────────────
static inline void _openssl_throw(const char* msg) {
    t_string s;
    s.data = (char*)msg;
    s.length = (int)strlen(msg);
    s.is_local = false;
    s.is_lit = false;
    tp_throw_ex(new_tphp_class_Exception(s));
}

// ── 内部：获取 OpenSSL 错误字符串并清空错误队列 ────────────────
static inline t_string _openssl_get_error(void) {
    unsigned long err = ERR_get_error();
    if (err == 0) {
        return tphp_rt_str_dup((t_string){.data = (char*)"no OpenSSL error", .length = 15, .is_local = false, .is_lit = false});
    }
    char buf[256];
    ERR_error_string_n(err, buf, sizeof(buf));
    return tphp_rt_str_dup((t_string){.data = buf, .length = (int)strlen(buf), .is_local = false, .is_lit = false});
}

// ════════════════════════════════════════════════════════════
// SSL Context API
// ════════════════════════════════════════════════════════════

// ── SSL_CTX_new：创建 TLS 上下文 ───────────────────────────
//   $method: 0=TLS_client, 1=TLS_server, 2=TLS, 3=SSLv23(已弃用)
//   返回 ctx 指针值（t_int），失败抛异常
static inline t_int tphp_fn_openssl_ctx_new(t_int method) {
    SSL_CTX* ctx = NULL;
    switch (method) {
        case 0: ctx = SSL_CTX_new(TLS_client_method()); break;
        case 1: ctx = SSL_CTX_new(TLS_server_method()); break;
        case 2: ctx = SSL_CTX_new(TLS_method()); break;
        default: ctx = SSL_CTX_new(TLS_method()); break;
    }
    if (ctx == NULL) {
        _openssl_throw("openssl_ctx_new: SSL_CTX_new() failed");
        return 0;
    }
    return (t_int)(intptr_t)ctx;
}

// ── SSL_CTX_free：释放上下文 ──────────────────────────────
static inline void tphp_fn_openssl_ctx_free(t_int ctx) {
    if (ctx != 0) {
        SSL_CTX_free((SSL_CTX*)(intptr_t)ctx);
    }
}

// ── SSL_CTX_use_certificate_file：加载证书 ────────────────
//   $file: 证书文件路径
//   $type: SSL_FILETYPE_PEM(1) / SSL_FILETYPE_ASN1(2)
static inline t_bool tphp_fn_openssl_ctx_use_certificate_file(t_int ctx, t_string file, t_int type) {
    if (ctx == 0) {
        _openssl_throw("openssl_ctx_use_certificate_file: ctx is NULL");
        return false;
    }
    const char* path = STR_PTR(file);
    int ret = SSL_CTX_use_certificate_file((SSL_CTX*)(intptr_t)ctx, path, (int)type);
    if (ret != 1) {
        t_string err = _openssl_get_error();
        char buf[512];
        snprintf(buf, sizeof(buf), "openssl_ctx_use_certificate_file: failed to load '%s': %s", path, STR_PTR(err));
        _openssl_throw(buf);
        return false;
    }
    return true;
}

// ── SSL_CTX_use_private_key_file：加载私钥 ─────────────────
static inline t_bool tphp_fn_openssl_ctx_use_private_key_file(t_int ctx, t_string file, t_int type) {
    if (ctx == 0) {
        _openssl_throw("openssl_ctx_use_private_key_file: ctx is NULL");
        return false;
    }
    const char* path = STR_PTR(file);
    int ret = SSL_CTX_use_PrivateKey_file((SSL_CTX*)(intptr_t)ctx, path, (int)type);
    if (ret != 1) {
        t_string err = _openssl_get_error();
        char buf[512];
        snprintf(buf, sizeof(buf), "openssl_ctx_use_private_key_file: failed to load '%s': %s", path, STR_PTR(err));
        _openssl_throw(buf);
        return false;
    }
    return true;
}

// ── SSL_CTX_set_verify：设置证书验证模式 ───────────────────
static inline void tphp_fn_openssl_ctx_set_verify(t_int ctx, t_int mode) {
    if (ctx == 0) {
        _openssl_throw("openssl_ctx_set_verify: ctx is NULL");
        return;
    }
    SSL_CTX_set_verify((SSL_CTX*)(intptr_t)ctx, (int)mode, NULL);
}

// ── SSL_CTX_set_options：设置上下文选项 ────────────────────
static inline t_int tphp_fn_openssl_ctx_set_options(t_int ctx, t_int options) {
    if (ctx == 0) return 0;
    return (t_int)SSL_CTX_set_options((SSL_CTX*)(intptr_t)ctx, (long)options);
}

// ════════════════════════════════════════════════════════════
// SSL Connection API
// ════════════════════════════════════════════════════════════

// ── SSL_new：从上下文创建 SSL 对象 ─────────────────────────
//   返回 ssl 指针值（t_int），失败抛异常
static inline t_int tphp_fn_openssl_ssl_new(t_int ctx) {
    if (ctx == 0) {
        _openssl_throw("openssl_ssl_new: ctx is NULL");
        return 0;
    }
    SSL* ssl = SSL_new((SSL_CTX*)(intptr_t)ctx);
    if (ssl == NULL) {
        _openssl_throw("openssl_ssl_new: SSL_new() failed");
        return 0;
    }
    return (t_int)(intptr_t)ssl;
}

// ── SSL_free：释放 SSL 对象 ───────────────────────────────
static inline void tphp_fn_openssl_ssl_free(t_int ssl) {
    if (ssl != 0) {
        SSL_free((SSL*)(intptr_t)ssl);
    }
}

// ── SSL_set_fd：关联 socket fd ───────────────────────────
static inline t_bool tphp_fn_openssl_ssl_set_fd(t_int ssl, t_int fd) {
    if (ssl == 0) {
        _openssl_throw("openssl_ssl_set_fd: ssl is NULL");
        return false;
    }
    int ret = SSL_set_fd((SSL*)(intptr_t)ssl, (int)fd);
    if (ret != 1) {
        _openssl_throw("openssl_ssl_set_fd: SSL_set_fd() failed");
        return false;
    }
    return true;
}

// ── SSL_connect：客户端 TLS 握手 ──────────────────────────
//   返回 1=成功，失败抛异常
static inline t_int tphp_fn_openssl_ssl_connect(t_int ssl) {
    if (ssl == 0) {
        _openssl_throw("openssl_ssl_connect: ssl is NULL");
        return 0;
    }
    int ret = SSL_connect((SSL*)(intptr_t)ssl);
    if (ret != 1) {
        int err = SSL_get_error((SSL*)(intptr_t)ssl, ret);
        t_string errstr = _openssl_get_error();
        char buf[512];
        snprintf(buf, sizeof(buf), "openssl_ssl_connect: handshake failed (ssl_err=%d): %s", err, STR_PTR(errstr));
        _openssl_throw(buf);
        return 0;
    }
    return 1;
}

// ── SSL_accept：服务端 TLS 握手 ───────────────────────────
static inline t_int tphp_fn_openssl_ssl_accept(t_int ssl) {
    if (ssl == 0) {
        _openssl_throw("openssl_ssl_accept: ssl is NULL");
        return 0;
    }
    int ret = SSL_accept((SSL*)(intptr_t)ssl);
    if (ret != 1) {
        int err = SSL_get_error((SSL*)(intptr_t)ssl, ret);
        t_string errstr = _openssl_get_error();
        char buf[512];
        snprintf(buf, sizeof(buf), "openssl_ssl_accept: handshake failed (ssl_err=%d): %s", err, STR_PTR(errstr));
        _openssl_throw(buf);
        return 0;
    }
    return 1;
}

// ── SSL_read：读取加密数据 ────────────────────────────────
//   返回解密后的数据字符串，失败/对端关闭返回空串
static inline t_string tphp_fn_openssl_ssl_read(t_int ssl, t_int length) {
    if (ssl == 0) {
        _openssl_throw("openssl_ssl_read: ssl is NULL");
        return (t_string){0};
    }
    char* buf = (char*)malloc((size_t)length + 1);
    if (buf == NULL) {
        _openssl_throw("openssl_ssl_read: malloc failed");
        return (t_string){0};
    }
    int n = SSL_read((SSL*)(intptr_t)ssl, buf, (int)length);
    if (n <= 0) {
        free(buf);
        int err = SSL_get_error((SSL*)(intptr_t)ssl, n);
        // SSL_ERROR_ZERO_RETURN (6) = 对端正常关闭，返回空串（非异常）
        // SSL_ERROR_SYSCALL (5) with n=0 = 对端关闭连接，返回空串
        if (err == SSL_ERROR_ZERO_RETURN || (err == SSL_ERROR_SYSCALL && n == 0)) {
            return tphp_rt_str_dup((t_string){.data = (char*)"", .length = 0, .is_local = false, .is_lit = false});
        }
        char ebuf[256];
        snprintf(ebuf, sizeof(ebuf), "openssl_ssl_read: SSL_read failed (ssl_err=%d)", err);
        _openssl_throw(ebuf);
        return (t_string){0};
    }
    buf[n] = '\0';
    t_string result = tphp_rt_str_dup((t_string){.data = buf, .length = n, .is_local = false, .is_lit = false});
    free(buf);
    return result;
}

// ── SSL_write：写入加密数据 ───────────────────────────────
//   返回已写入字节数，失败抛异常
static inline t_int tphp_fn_openssl_ssl_write(t_int ssl, t_string data) {
    if (ssl == 0) {
        _openssl_throw("openssl_ssl_write: ssl is NULL");
        return 0;
    }
    int n = SSL_write((SSL*)(intptr_t)ssl, STR_PTR(data), data.length);
    if (n <= 0) {
        int err = SSL_get_error((SSL*)(intptr_t)ssl, n);
        char buf[256];
        snprintf(buf, sizeof(buf), "openssl_ssl_write: SSL_write failed (ssl_err=%d)", err);
        _openssl_throw(buf);
        return 0;
    }
    return (t_int)n;
}

// ── SSL_shutdown：优雅关闭 TLS ────────────────────────────
static inline t_bool tphp_fn_openssl_ssl_shutdown(t_int ssl) {
    if (ssl == 0) return false;
    SSL_shutdown((SSL*)(intptr_t)ssl);
    return true;
}

// ── SSL_get_cipher_name：获取当前使用的加密套件名 ──────────
static inline t_string tphp_fn_openssl_ssl_get_cipher_name(t_int ssl) {
    if (ssl == 0) {
        return tphp_rt_str_dup((t_string){.data = (char*)"", .length = 0, .is_local = false, .is_lit = false});
    }
    const char* name = SSL_get_cipher_name((SSL*)(intptr_t)ssl);
    if (name == NULL) name = "";
    return tphp_rt_str_dup((t_string){.data = (char*)name, .length = (int)strlen(name), .is_local = false, .is_lit = false});
}

// ── SSL_get_version：获取 TLS 协议版本 ────────────────────
static inline t_string tphp_fn_openssl_ssl_get_version(t_int ssl) {
    if (ssl == 0) {
        return tphp_rt_str_dup((t_string){.data = (char*)"", .length = 0, .is_local = false, .is_lit = false});
    }
    const char* ver = SSL_get_version((SSL*)(intptr_t)ssl);
    if (ver == NULL) ver = "";
    return tphp_rt_str_dup((t_string){.data = (char*)ver, .length = (int)strlen(ver), .is_local = false, .is_lit = false});
}

// ════════════════════════════════════════════════════════════
// Error API
// ════════════════════════════════════════════════════════════

// ── openssl_error_string：获取并清空 OpenSSL 错误 ──────────
static inline t_string tphp_fn_openssl_error_string(void) {
    return _openssl_get_error();
}

// ════════════════════════════════════════════════════════════
// 对称加密 API（基于 EVP）
// ════════════════════════════════════════════════════════════

// ── openssl_encrypt：对称加密 ─────────────────────────────
//   $cipher: 加密算法名 "AES-256-CBC" 等
//   $key: 密钥（原始二进制）
//   $iv: 初始向量
//   $data: 待加密数据
//   $options: OPENSSL_RAW_DATA(1) / OPENSSL_ZERO_PADDING(2)
//   返回加密后的数据（含 padding）
static inline t_string tphp_fn_openssl_encrypt(
    t_string cipher, t_string key, t_string iv,
    t_string data, t_int options
) {
    const char* cipher_name = STR_PTR(cipher);
    const EVP_CIPHER* c = EVP_get_cipherbyname(cipher_name);
    if (c == NULL) {
        char buf[256];
        snprintf(buf, sizeof(buf), "openssl_encrypt: unknown cipher '%s'", cipher_name);
        _openssl_throw(buf);
        return (t_string){0};
    }

    EVP_CIPHER_CTX* ctx = EVP_CIPHER_CTX_new();
    if (ctx == NULL) {
        _openssl_throw("openssl_encrypt: EVP_CIPHER_CTX_new() failed");
        return (t_string){0};
    }

    int block_size = EVP_CIPHER_block_size(c);
    int out_len = data.length + block_size;
    unsigned char* out = (unsigned char*)malloc((size_t)out_len + 1);
    if (out == NULL) {
        EVP_CIPHER_CTX_free(ctx);
        _openssl_throw("openssl_encrypt: malloc failed");
        return (t_string){0};
    }

    if (EVP_EncryptInit_ex(ctx, c, NULL,
                           (const unsigned char*)STR_PTR(key),
                           (const unsigned char*)STR_PTR(iv)) != 1) {
        free(out);
        EVP_CIPHER_CTX_free(ctx);
        _openssl_throw("openssl_encrypt: EVP_EncryptInit_ex() failed");
        return (t_string){0};
    }

    // OPENSSL_ZERO_PADDING (2): 禁用 padding（仅对块密码）
    if (options & 2) {
        EVP_CIPHER_CTX_set_padding(ctx, 0);
    }

    int len1 = 0;
    if (EVP_EncryptUpdate(ctx, out, &len1,
                          (const unsigned char*)STR_PTR(data),
                          (int)data.length) != 1) {
        free(out);
        EVP_CIPHER_CTX_free(ctx);
        _openssl_throw("openssl_encrypt: EVP_EncryptUpdate() failed");
        return (t_string){0};
    }

    int len2 = 0;
    if (EVP_EncryptFinal_ex(ctx, out + len1, &len2) != 1) {
        free(out);
        EVP_CIPHER_CTX_free(ctx);
        _openssl_throw("openssl_encrypt: EVP_EncryptFinal_ex() failed");
        return (t_string){0};
    }

    EVP_CIPHER_CTX_free(ctx);

    int total = len1 + len2;
    out[total] = '\0';
    t_string result = tphp_rt_str_dup((t_string){
        .data = (char*)out, .length = total,
        .is_local = false, .is_lit = false
    });
    free(out);
    return result;
}

// ── openssl_decrypt：对称解密 ─────────────────────────────
static inline t_string tphp_fn_openssl_decrypt(
    t_string cipher, t_string key, t_string iv,
    t_string data, t_int options
) {
    const char* cipher_name = STR_PTR(cipher);
    const EVP_CIPHER* c = EVP_get_cipherbyname(cipher_name);
    if (c == NULL) {
        char buf[256];
        snprintf(buf, sizeof(buf), "openssl_decrypt: unknown cipher '%s'", cipher_name);
        _openssl_throw(buf);
        return (t_string){0};
    }

    EVP_CIPHER_CTX* ctx = EVP_CIPHER_CTX_new();
    if (ctx == NULL) {
        _openssl_throw("openssl_decrypt: EVP_CIPHER_CTX_new() failed");
        return (t_string){0};
    }

    int out_len = data.length + EVP_CIPHER_block_size(c);
    unsigned char* out = (unsigned char*)malloc((size_t)out_len + 1);
    if (out == NULL) {
        EVP_CIPHER_CTX_free(ctx);
        _openssl_throw("openssl_decrypt: malloc failed");
        return (t_string){0};
    }

    if (EVP_DecryptInit_ex(ctx, c, NULL,
                           (const unsigned char*)STR_PTR(key),
                           (const unsigned char*)STR_PTR(iv)) != 1) {
        free(out);
        EVP_CIPHER_CTX_free(ctx);
        _openssl_throw("openssl_decrypt: EVP_DecryptInit_ex() failed");
        return (t_string){0};
    }

    if (options & 2) {
        EVP_CIPHER_CTX_set_padding(ctx, 0);
    }

    int len1 = 0;
    if (EVP_DecryptUpdate(ctx, out, &len1,
                          (const unsigned char*)STR_PTR(data),
                          (int)data.length) != 1) {
        free(out);
        EVP_CIPHER_CTX_free(ctx);
        _openssl_throw("openssl_decrypt: EVP_DecryptUpdate() failed");
        return (t_string){0};
    }

    int len2 = 0;
    if (EVP_DecryptFinal_ex(ctx, out + len1, &len2) != 1) {
        free(out);
        EVP_CIPHER_CTX_free(ctx);
        _openssl_throw("openssl_decrypt: EVP_DecryptFinal_ex() failed (wrong key/padding)");
        return (t_string){0};
    }

    EVP_CIPHER_CTX_free(ctx);

    int total = len1 + len2;
    out[total] = '\0';
    t_string result = tphp_rt_str_dup((t_string){
        .data = (char*)out, .length = total,
        .is_local = false, .is_lit = false
    });
    free(out);
    return result;
}

// ════════════════════════════════════════════════════════════
// 随机数 API
// ════════════════════════════════════════════════════════════

// ── openssl_random_pseudo_bytes：生成加密安全的随机字节 ────
static inline t_string tphp_fn_openssl_random_pseudo_bytes(t_int length) {
    if (length <= 0) {
        return tphp_rt_str_dup((t_string){.data = (char*)"", .length = 0, .is_local = false, .is_lit = false});
    }
    unsigned char* buf = (unsigned char*)malloc((size_t)length);
    if (buf == NULL) {
        _openssl_throw("openssl_random_pseudo_bytes: malloc failed");
        return (t_string){0};
    }
    if (RAND_bytes(buf, (int)length) != 1) {
        free(buf);
        _openssl_throw("openssl_random_pseudo_bytes: RAND_bytes() failed");
        return (t_string){0};
    }
    t_string result = tphp_rt_str_dup((t_string){
        .data = (char*)buf, .length = (int)length,
        .is_local = false, .is_lit = false
    });
    free(buf);
    return result;
}

// ════════════════════════════════════════════════════════════
// 哈希 API（基于 EVP_MD）
// ════════════════════════════════════════════════════════════

// ── openssl_digest：计算哈希 ─────────────────────────────
//   $method: "sha256" / "md5" / "sha512" 等
//   $data: 待哈希数据
//   $raw_output: true=返回原始二进制，false=返回十六进制字符串
static inline t_string tphp_fn_openssl_digest(t_string method, t_string data, t_bool raw_output) {
    const char* method_name = STR_PTR(method);
    const EVP_MD* md = EVP_get_digestbyname(method_name);
    if (md == NULL) {
        char buf[256];
        snprintf(buf, sizeof(buf), "openssl_digest: unknown digest '%s'", method_name);
        _openssl_throw(buf);
        return (t_string){0};
    }

    EVP_MD_CTX* mdctx = EVP_MD_CTX_new();
    if (mdctx == NULL) {
        _openssl_throw("openssl_digest: EVP_MD_CTX_new() failed");
        return (t_string){0};
    }

    if (EVP_DigestInit_ex(mdctx, md, NULL) != 1) {
        EVP_MD_CTX_free(mdctx);
        _openssl_throw("openssl_digest: EVP_DigestInit_ex() failed");
        return (t_string){0};
    }

    if (EVP_DigestUpdate(mdctx, STR_PTR(data), (size_t)data.length) != 1) {
        EVP_MD_CTX_free(mdctx);
        _openssl_throw("openssl_digest: EVP_DigestUpdate() failed");
        return (t_string){0};
    }

    unsigned char hash[EVP_MAX_MD_SIZE];
    unsigned int hash_len = 0;
    if (EVP_DigestFinal_ex(mdctx, hash, &hash_len) != 1) {
        EVP_MD_CTX_free(mdctx);
        _openssl_throw("openssl_digest: EVP_DigestFinal_ex() failed");
        return (t_string){0};
    }

    EVP_MD_CTX_free(mdctx);

    if (raw_output) {
        return tphp_rt_str_dup((t_string){
            .data = (char*)hash, .length = (int)hash_len,
            .is_local = false, .is_lit = false
        });
    }

    // 转十六进制
    char* hex = (char*)malloc((size_t)hash_len * 2 + 1);
    if (hex == NULL) {
        _openssl_throw("openssl_digest: malloc failed");
        return (t_string){0};
    }
    static const char hexchars[] = "0123456789abcdef";
    for (unsigned int i = 0; i < hash_len; i++) {
        hex[i * 2]     = hexchars[(hash[i] >> 4) & 0xF];
        hex[i * 2 + 1] = hexchars[hash[i] & 0xF];
    }
    hex[hash_len * 2] = '\0';
    t_string result = tphp_rt_str_dup((t_string){
        .data = hex, .length = (int)(hash_len * 2),
        .is_local = false, .is_lit = false
    });
    free(hex);
    return result;
}

// ════════════════════════════════════════════════════════════
// stream_socket_enable_crypto 真实实现（覆盖 stream.h stub）
//   此函数在 openssl.h 中定义，当 openssl.h 在 stream.h 之前
//   include 时，TPHP_STREAM_TLS_IMPLEMENTED 已定义，stream.h
//   中的 stub 被跳过。
//
//   注意：本函数需要一个已初始化的 SSL_CTX*（由 openssl_ctx_new 创建）
//   通常的调用流程：
//     $ctx = openssl_ctx_new(1);  // TLS_server
//     openssl_ctx_use_certificate_file($ctx, "cert.pem", SSL_FILETYPE_PEM);
//     openssl_ctx_use_private_key_file($ctx, "key.pem", SSL_FILETYPE_PEM);
//     $ssl = openssl_ssl_new($ctx);
//     openssl_ssl_set_fd($ssl, $fd);
//     openssl_ssl_accept($ssl);   // 服务端握手
//
//   但 stream_socket_enable_crypto 是一个便捷封装：
//   它为单次连接创建临时 SSL_CTX + SSL 对象，握手后只保留 fd
//   （内存由 OpenSSL 内部管理）。如需更细粒度控制，请直接使用
//   openssl_ssl_* 系列函数。
// ════════════════════════════════════════════════════════════

#define TPHP_STREAM_TLS_IMPLEMENTED

// 内部：TLS 会话上下文（绑定到 fd，供后续 read/write 使用）
//   通过 SSL_set_app_data 关联，fd → TLS session
typedef struct {
    SSL* ssl;
    SSL_CTX* ctx;
} _tphp_tls_session_t;

// 简单实现：创建临时 TLS 会话并执行握手
//   crypto_type: 0=自动(TLS), 3=TLS, 6=TLSv1.2, 7=TLSv1.3
//   enable=false: 关闭 TLS（调用 SSL_shutdown）
static inline t_int tphp_fn_stream_socket_enable_crypto(t_int fd, t_bool enable, t_int crypto_type) {
    (void)crypto_type;  // 当前版本统一使用 TLS_method()，忽略版本指定

    if (fd < 0) {
        _openssl_throw("stream_socket_enable_crypto: invalid fd");
        return 0;
    }

    if (!enable) {
        // 关闭 TLS：需要找到关联的 SSL 对象并 shutdown
        // 简化实现：仅返回成功（调用方应在之前直接使用 openssl_ssl_shutdown）
        return 1;
    }

    // 创建临时上下文
    SSL_CTX* ctx = SSL_CTX_new(TLS_method());
    if (ctx == NULL) {
        _openssl_throw("stream_socket_enable_crypto: SSL_CTX_new() failed");
        return 0;
    }

    SSL* ssl = SSL_new(ctx);
    if (ssl == NULL) {
        SSL_CTX_free(ctx);
        _openssl_throw("stream_socket_enable_crypto: SSL_new() failed");
        return 0;
    }

    if (SSL_set_fd(ssl, (int)fd) != 1) {
        SSL_free(ssl);
        SSL_CTX_free(ctx);
        _openssl_throw("stream_socket_enable_crypto: SSL_set_fd() failed");
        return 0;
    }

    // 执行 TLS 握手
    int ret = SSL_connect(ssl);
    if (ret != 1) {
        int err = SSL_get_error(ssl, ret);
        t_string errstr = _openssl_get_error();
        char buf[512];
        snprintf(buf, sizeof(buf),
                 "stream_socket_enable_crypto: TLS handshake failed (ssl_err=%d): %s",
                 err, STR_PTR(errstr));
        SSL_free(ssl);
        SSL_CTX_free(ctx);
        _openssl_throw(buf);
        return 0;
    }

    // 保存会话：简单实现中 SSL 对象生命周期到 SSL_free 为止
    // 完整实现需要全局映射 fd→SSL*，供后续 openssl_ssl_read/write 使用
    // 当前简化版本：调用方应在握手后改用 openssl_ssl_read/write
    // 通过 phpc_ptr_to_int(ssl) 获取 SSL* 引用
    SSL_set_app_data(ssl, ctx);  // 将 ctx 存储在 ssl 中以便后续释放

    // 返回 SSL 指针值，调用方可用于后续 openssl_ssl_read/write
    // 如果调用方不需要后续操作，返回 1（成功）
    return (t_int)(intptr_t)ssl;
}
