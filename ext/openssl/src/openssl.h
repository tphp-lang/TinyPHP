#pragma once
// ============================================================
// openssl.h — OpenSSL 扩展（TLS/SSL 加密支持）
//
// 设计说明（参考 vlang 默认 TLS 方案，内置 mbedTLS 源码静态编译）：
//   - 底层使用内置 mbedTLS 3.6.6 源码（include/mbedtls_src/）静态编译
//   - 零运行时依赖：无需系统 OpenSSL 或外部 .dll/.so/.dylib
//   - TCC 兼容：mbedtls_config.h 禁用 ASM + 强制 32 位 bignum limbs
//   - 所有函数接收 tphp 类型（t_int/t_string/t_bool），通过 $simpleFnMap 映射
//   - SSL/SSL_CTX 指针以 t_int 流转（phpc_ptr_to_int / phpc_int_to_ptr 模式）
//   - 错误统一 tp_throw_ex（可被 try-catch 捕获）
//   - 包含 stream_socket_enable_crypto 的真实 TLS 实现（覆盖 stream.h 的 stub）
//
// 与 PHP 原生 openssl 扩展的 API 兼容性：
//   - 函数签名保持一致（openssl_encrypt/digest/random_pseudo_bytes 等）
//   - 常量名保持一致（OPENSSL_RAW_DATA、OPENSSL_ZERO_PADDING 等）
//   - 行为语义保持一致（PKCS7 padding、hex 输出等）
//   - 底层实现不同（mbedTLS 而非 OpenSSL），但用户无感知
//
// 跨平台（源码由 tphp.php 自动收集，链接由 tphp.php 处理）：
//   Windows: -lws2_32（mbedtls net_sockets.c 依赖 winsock）
//   Linux:   -lpthread -ldl（默认已在 tphp.php 链接）
//   macOS:   无额外依赖
//   编译器:  TCC/GCC/Clang 统一使用相同源码和配置
//
// 包含顺序：openssl.h 必须在 stream.h 之前 include（由 CodeGenerator 保证）
//   这样 TPHP_STREAM_TLS_IMPLEMENTED 先定义，stream.h 中的 stub 被跳过
// ============================================================

#include "types.h"
#include "object/object.h"    // t_object 完整定义（exception.h 依赖）
#include "object/exception.h"
#include "object/try.h"
#include "val.h"
#include <stdio.h>
#include <stdlib.h>
#include <string.h>

// ── mbedTLS 头文件（通过 #flag -I 指定的本地源码路径查找）──
#include <mbedtls/ssl.h>
#include <mbedtls/error.h>
#include <mbedtls/entropy.h>
#include <mbedtls/ctr_drbg.h>
#include <mbedtls/x509.h>
#include <mbedtls/x509_crt.h>
#include <mbedtls/pem.h>
#include <mbedtls/base64.h>
#include <mbedtls/md.h>
#include <mbedtls/cipher.h>
#include <mbedtls/aes.h>
#include <mbedtls/sha1.h>
#include <mbedtls/sha256.h>
#include <mbedtls/sha512.h>
#include <mbedtls/md5.h>
#include <mbedtls/rsa.h>
#include <mbedtls/pk.h>
#include <mbedtls/net_sockets.h>
#include <mbedtls/version.h>

// ── 常量（CodeGenerator 需要 TPHP_CONST_ 前缀，与 PHP openssl 扩展兼容） ──
#define TPHP_CONST_SSL_OP_NO_COMPRESSION              0x00020000L
#define TPHP_CONST_SSL_OP_NO_SSLv2                    0x01000000L
#define TPHP_CONST_SSL_OP_NO_SSLv3                    0x02000000L
// CodeGenerator 将 PHP 常量名强制转大写，需提供全大写别名（与 PHP 原生混合大小写共存）
#define TPHP_CONST_SSL_OP_NO_SSLV2                    TPHP_CONST_SSL_OP_NO_SSLv2
#define TPHP_CONST_SSL_OP_NO_SSLV3                    TPHP_CONST_SSL_OP_NO_SSLv3
#define TPHP_CONST_SSL_OP_NO_TLSv1                    0x04000000L
#define TPHP_CONST_SSL_OP_NO_TLSv1_1                  0x10000000L
#define TPHP_CONST_SSL_OP_NO_TLSv1_2                  0x08000000L
#define TPHP_CONST_SSL_OP_NO_TLSv1_3                  0x20000000L
// CodeGenerator 将 PHP 常量名强制转大写，需提供全大写别名
#define TPHP_CONST_SSL_OP_NO_TLSV1                    TPHP_CONST_SSL_OP_NO_TLSv1
#define TPHP_CONST_SSL_OP_NO_TLSV1_1                  TPHP_CONST_SSL_OP_NO_TLSv1_1
#define TPHP_CONST_SSL_OP_NO_TLSV1_2                  TPHP_CONST_SSL_OP_NO_TLSv1_2
#define TPHP_CONST_SSL_OP_NO_TLSV1_3                  TPHP_CONST_SSL_OP_NO_TLSv1_3
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

// ── 内部：获取 mbedTLS 错误字符串 ───────────────────────────
static inline t_string _openssl_get_error(void) {
    char buf[256];
    int err = mbedtls_test_get_last_error();
    mbedtls_strerror(err, buf, sizeof(buf));
    // mbedtls 没有 ERR_get_error() 等价的全局错误队列，
    // 返回一个静态字符串作为占位（PHP 代码应检查返回值而非依赖错误队列）
    return tphp_rt_str_dup((t_string){
        .data = (err == 0 ? "no mbedtls error" : buf),
        .length = (int)strlen(err == 0 ? "no mbedtls error" : buf),
        .is_local = false, .is_lit = false
    });
}

// 兼容性宏：mbedtls_test_get_last_error() 简化为 0（mbedTLS 无全局错误队列）
static inline int mbedtls_test_get_last_error(void) { return 0; }

// ── 内部：mbedtls 帮助函数 ─────────────────────────────────

// 全局 DRBG 上下文（线程不安全，单线程使用）
static mbedtls_entropy_context _mbedtls_entropy;
static mbedtls_ctr_drbg_context _mbedtls_ctr_drbg;
static int _mbedtls_initialized = 0;

static inline void _mbedtls_init_once(void) {
    if (_mbedtls_initialized) return;
    mbedtls_entropy_init(&_mbedtls_entropy);
    mbedtls_ctr_drbg_init(&_mbedtls_ctr_drbg);
    const char* pers = "tphp_openssl";
    mbedtls_ctr_drbg_seed(&_mbedtls_ctr_drbg, mbedtls_entropy_func,
                          &_mbedtls_entropy,
                          (const unsigned char*)pers, strlen(pers));
    _mbedtls_initialized = 1;
}

// 内部 TLS 会话上下文（封装 mbedtls_ssl_context + config + 证书）
typedef struct {
    mbedtls_ssl_context ssl;
    mbedtls_ssl_config conf;
    mbedtls_x509_crt cacert;
    mbedtls_x509_crt clicert;
    mbedtls_pk_context pk;
    int is_server;
} _tphp_ssl_ctx_t;

// ════════════════════════════════════════════════════════════
// SSL Context API
// ════════════════════════════════════════════════════════════

// ── SSL_CTX_new：创建 TLS 上下文 ───────────────────────────
//   $method: 0=TLS_client, 1=TLS_server, 2=TLS, 3=SSLv23(已弃用)
//   返回 ctx 指针值（t_int），失败抛异常
static inline t_int tphp_fn_openssl_ctx_new(t_int method) {
    _mbedtls_init_once();
    _tphp_ssl_ctx_t* ctx = (_tphp_ssl_ctx_t*)calloc(1, sizeof(_tphp_ssl_ctx_t));
    if (ctx == NULL) {
        _openssl_throw("openssl_ctx_new: calloc failed");
        return 0;
    }
    mbedtls_ssl_init(&ctx->ssl);
    mbedtls_ssl_config_init(&ctx->conf);
    mbedtls_x509_crt_init(&ctx->cacert);
    mbedtls_x509_crt_init(&ctx->clicert);
    mbedtls_pk_init(&ctx->pk);

    int endpoint = (method == 1) ? MBEDTLS_SSL_IS_SERVER : MBEDTLS_SSL_IS_CLIENT;
    ctx->is_server = (method == 1) ? 1 : 0;

    int ret = mbedtls_ssl_config_defaults(&ctx->conf, endpoint,
                                          MBEDTLS_SSL_TRANSPORT_STREAM,
                                          MBEDTLS_SSL_PRESET_DEFAULT);
    if (ret != 0) {
        char buf[256];
        mbedtls_strerror(ret, buf, sizeof(buf));
        _openssl_throw(buf);
        free(ctx);
        return 0;
    }
    mbedtls_ssl_conf_rng(&ctx->conf, mbedtls_ctr_drbg_random, &_mbedtls_ctr_drbg);
    // 默认不验证对端证书（与 PHP 默认行为对齐，用户可调用 openssl_ctx_set_verify 启用）
    mbedtls_ssl_conf_authmode(&ctx->conf, MBEDTLS_SSL_VERIFY_NONE);

    return (t_int)(intptr_t)ctx;
}

// ── SSL_CTX_free：释放上下文 ──────────────────────────────
static inline void tphp_fn_openssl_ctx_free(t_int ctx) {
    if (ctx == 0) return;
    _tphp_ssl_ctx_t* c = (_tphp_ssl_ctx_t*)(intptr_t)ctx;
    mbedtls_ssl_free(&c->ssl);
    mbedtls_ssl_config_free(&c->conf);
    mbedtls_x509_crt_free(&c->cacert);
    mbedtls_x509_crt_free(&c->clicert);
    mbedtls_pk_free(&c->pk);
    free(c);
}

// ── SSL_CTX_use_certificate_file：加载证书 ────────────────
//   $type: SSL_FILETYPE_PEM(1) / SSL_FILETYPE_ASN1(2)
static inline t_bool tphp_fn_openssl_ctx_use_certificate_file(t_int ctx, t_string file, t_int type) {
    if (ctx == 0) {
        _openssl_throw("openssl_ctx_use_certificate_file: ctx is NULL");
        return false;
    }
    _tphp_ssl_ctx_t* c = (_tphp_ssl_ctx_t*)(intptr_t)ctx;
    const char* path = STR_PTR(file);
    int ret = mbedtls_x509_crt_parse_file(&c->clicert, path);
    if (ret != 0) {
        char buf[256];
        snprintf(buf, sizeof(buf), "openssl_ctx_use_certificate_file: failed to load '%s': -0x%04x",
                 path, (unsigned int)-ret);
        _openssl_throw(buf);
        return false;
    }
    mbedtls_ssl_conf_own_cert(&c->conf, &c->clicert, &c->pk);
    (void)type;  // mbedtls PEM 解析器自动识别格式
    return true;
}

// ── SSL_CTX_use_private_key_file：加载私钥 ─────────────────
static inline t_bool tphp_fn_openssl_ctx_use_private_key_file(t_int ctx, t_string file, t_int type) {
    if (ctx == 0) {
        _openssl_throw("openssl_ctx_use_private_key_file: ctx is NULL");
        return false;
    }
    _tphp_ssl_ctx_t* c = (_tphp_ssl_ctx_t*)(intptr_t)ctx;
    const char* path = STR_PTR(file);
    int ret = mbedtls_pk_parse_keyfile(&c->pk, path, NULL,
                                       mbedtls_ctr_drbg_random, &_mbedtls_ctr_drbg);
    if (ret != 0) {
        char buf[256];
        snprintf(buf, sizeof(buf), "openssl_ctx_use_private_key_file: failed to load '%s': -0x%04x",
                 path, (unsigned int)-ret);
        _openssl_throw(buf);
        return false;
    }
    mbedtls_ssl_conf_own_cert(&c->conf, &c->clicert, &c->pk);
    (void)type;
    return true;
}

// ── SSL_CTX_set_verify：设置证书验证模式 ───────────────────
static inline void tphp_fn_openssl_ctx_set_verify(t_int ctx, t_int mode) {
    if (ctx == 0) {
        _openssl_throw("openssl_ctx_set_verify: ctx is NULL");
        return;
    }
    _tphp_ssl_ctx_t* c = (_tphp_ssl_ctx_t*)(intptr_t)ctx;
    int authmode;
    switch (mode) {
        case 0:  authmode = MBEDTLS_SSL_VERIFY_NONE; break;
        case 1:  authmode = MBEDTLS_SSL_VERIFY_OPTIONAL; break;
        case 2:  authmode = MBEDTLS_SSL_VERIFY_REQUIRED; break;
        default: authmode = MBEDTLS_SSL_VERIFY_REQUIRED; break;
    }
    mbedtls_ssl_conf_authmode(&c->conf, authmode);
}

// ── SSL_CTX_set_options：设置上下文选项 ────────────────────
//   mbedtls 通过配置 API 设置选项，此处为兼容性桩函数
static inline t_int tphp_fn_openssl_ctx_set_options(t_int ctx, t_int options) {
    (void)ctx;
    (void)options;  // mbedtls 的选项通过独立 API 设置，此函数仅返回成功
    return 0;
}

// ════════════════════════════════════════════════════════════
// SSL Connection API
// ════════════════════════════════════════════════════════════

// ── SSL_new：从上下文创建 SSL 对象 ─────────────────────────
static inline t_int tphp_fn_openssl_ssl_new(t_int ctx) {
    if (ctx == 0) {
        _openssl_throw("openssl_ssl_new: ctx is NULL");
        return 0;
    }
    _tphp_ssl_ctx_t* c = (_tphp_ssl_ctx_t*)(intptr_t)ctx;
    int ret = mbedtls_ssl_setup(&c->ssl, &c->conf);
    if (ret != 0) {
        char buf[256];
        snprintf(buf, sizeof(buf), "openssl_ssl_new: mbedtls_ssl_setup failed: -0x%04x",
                 (unsigned int)-ret);
        _openssl_throw(buf);
        return 0;
    }
    // 返回 ctx 指针（ssl 内嵌在 ctx 中，简化内存管理）
    return ctx;
}

// ── SSL_free：释放 SSL 对象（由 ctx_free 统一管理） ────────
static inline void tphp_fn_openssl_ssl_free(t_int ssl) {
    (void)ssl;  // SSL 对象内嵌在 ctx 中，由 openssl_ctx_free 释放
}

// ── SSL_set_fd：关联 socket fd ───────────────────────────
//   使用 mbedtls_net_sockets.c 的 BIO，但 mbedtls 的 set_bio 需要回调
static inline t_bool tphp_fn_openssl_ssl_set_fd(t_int ssl, t_int fd) {
    if (ssl == 0) {
        _openssl_throw("openssl_ssl_set_fd: ssl is NULL");
        return false;
    }
    _tphp_ssl_ctx_t* c = (_tphp_ssl_ctx_t*)(intptr_t)ssl;
    // 使用 mbedtls 的 net context 包装 fd
    // 注意：mbedtls_net_sockets.c 的 recv/send 回调直接操作 fd
    mbedtls_ssl_set_bio(&c->ssl, (void*)(intptr_t)fd,
                        mbedtls_net_send, mbedtls_net_recv, NULL);
    return true;
}

// ── SSL_connect：客户端 TLS 握手 ──────────────────────────
static inline t_int tphp_fn_openssl_ssl_connect(t_int ssl) {
    if (ssl == 0) {
        _openssl_throw("openssl_ssl_connect: ssl is NULL");
        return 0;
    }
    _tphp_ssl_ctx_t* c = (_tphp_ssl_ctx_t*)(intptr_t)ssl;
    int ret = mbedtls_ssl_handshake(&c->ssl);
    if (ret != 0) {
        char buf[256];
        snprintf(buf, sizeof(buf), "openssl_ssl_connect: handshake failed: -0x%04x",
                 (unsigned int)-ret);
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
    _tphp_ssl_ctx_t* c = (_tphp_ssl_ctx_t*)(intptr_t)ssl;
    int ret = mbedtls_ssl_handshake(&c->ssl);
    if (ret != 0) {
        char buf[256];
        snprintf(buf, sizeof(buf), "openssl_ssl_accept: handshake failed: -0x%04x",
                 (unsigned int)-ret);
        _openssl_throw(buf);
        return 0;
    }
    return 1;
}

// ── SSL_read：读取加密数据 ────────────────────────────────
static inline t_string tphp_fn_openssl_ssl_read(t_int ssl, t_int length) {
    if (ssl == 0) {
        _openssl_throw("openssl_ssl_read: ssl is NULL");
        return (t_string){0};
    }
    _tphp_ssl_ctx_t* c = (_tphp_ssl_ctx_t*)(intptr_t)ssl;
    char* buf = (char*)malloc((size_t)length + 1);
    if (buf == NULL) {
        _openssl_throw("openssl_ssl_read: malloc failed");
        return (t_string){0};
    }
    int n = mbedtls_ssl_read(&c->ssl, (unsigned char*)buf, (size_t)length);
    if (n <= 0) {
        free(buf);
        // MBEDTLS_ERR_SSL_PEER_CLOSE_NOTIFY = 对端正常关闭，返回空串
        if (n == MBEDTLS_ERR_SSL_PEER_CLOSE_NOTIFY) {
            return tphp_rt_str_dup((t_string){.data = (char*)"", .length = 0, .is_local = false, .is_lit = false});
        }
        char ebuf[256];
        snprintf(ebuf, sizeof(ebuf), "openssl_ssl_read: mbedtls_ssl_read failed: -0x%04x",
                 (unsigned int)-n);
        _openssl_throw(ebuf);
        return (t_string){0};
    }
    buf[n] = '\0';
    t_string result = tphp_rt_str_dup((t_string){.data = buf, .length = n, .is_local = false, .is_lit = false});
    free(buf);
    return result;
}

// ── SSL_write：写入加密数据 ───────────────────────────────
static inline t_int tphp_fn_openssl_ssl_write(t_int ssl, t_string data) {
    if (ssl == 0) {
        _openssl_throw("openssl_ssl_write: ssl is NULL");
        return 0;
    }
    _tphp_ssl_ctx_t* c = (_tphp_ssl_ctx_t*)(intptr_t)ssl;
    int n = mbedtls_ssl_write(&c->ssl, (const unsigned char*)STR_PTR(data), (size_t)data.length);
    if (n <= 0) {
        char buf[256];
        snprintf(buf, sizeof(buf), "openssl_ssl_write: mbedtls_ssl_write failed: -0x%04x",
                 (unsigned int)-n);
        _openssl_throw(buf);
        return 0;
    }
    return (t_int)n;
}

// ── SSL_shutdown：优雅关闭 TLS ────────────────────────────
static inline t_bool tphp_fn_openssl_ssl_shutdown(t_int ssl) {
    if (ssl == 0) return false;
    _tphp_ssl_ctx_t* c = (_tphp_ssl_ctx_t*)(intptr_t)ssl;
    mbedtls_ssl_close_notify(&c->ssl);
    return true;
}

// ── SSL_get_cipher_name：获取当前加密套件名 ──────────────────
static inline t_string tphp_fn_openssl_ssl_get_cipher_name(t_int ssl) {
    if (ssl == 0) {
        return tphp_rt_str_dup((t_string){.data = (char*)"", .length = 0, .is_local = false, .is_lit = false});
    }
    _tphp_ssl_ctx_t* c = (_tphp_ssl_ctx_t*)(intptr_t)ssl;
    int suite = mbedtls_ssl_get_ciphersuite_id_from_ssl(&c->ssl);
    const char* name = mbedtls_ssl_get_ciphersuite(suite);
    if (name == NULL) name = "";
    return tphp_rt_str_dup((t_string){.data = (char*)name, .length = (int)strlen(name), .is_local = false, .is_lit = false});
}

// ── SSL_get_version：获取 TLS 协议版本 ────────────────────
//   PHP 语义：握手前返回 "unknown"，握手后返回 "TLSv1.2" 等
//   mbedtls 的 mbedtls_ssl_get_version() 在握手前返回配置的默认版本，
//   需用 mbedtls_ssl_is_handshake_over() 判断握手状态
static inline t_string tphp_fn_openssl_ssl_get_version(t_int ssl) {
    if (ssl == 0) {
        return tphp_rt_str_dup((t_string){.data = (char*)"", .length = 0, .is_local = false, .is_lit = false});
    }
    _tphp_ssl_ctx_t* c = (_tphp_ssl_ctx_t*)(intptr_t)ssl;
    if (!mbedtls_ssl_is_handshake_over(&c->ssl)) {
        return tphp_rt_str_dup((t_string){.data = (char*)"unknown", .length = 7, .is_local = false, .is_lit = false});
    }
    const char* ver = mbedtls_ssl_get_version(&c->ssl);
    if (ver == NULL) ver = "unknown";
    return tphp_rt_str_dup((t_string){.data = (char*)ver, .length = (int)strlen(ver), .is_local = false, .is_lit = false});
}

// ════════════════════════════════════════════════════════════
// Error API
// ════════════════════════════════════════════════════════════

static inline t_string tphp_fn_openssl_error_string(void) {
    return _openssl_get_error();
}

// ════════════════════════════════════════════════════════════
// 对称加密 API（基于 mbedtls_cipher）
// ════════════════════════════════════════════════════════════

// 内部：cipher 名称 → mbedtls_cipher_type_t 映射
static inline mbedtls_cipher_type_t _openssl_cipher_to_mbedtls(const char* name) {
    if (strcmp(name, "AES-128-CBC") == 0) return MBEDTLS_CIPHER_AES_128_CBC;
    if (strcmp(name, "AES-192-CBC") == 0) return MBEDTLS_CIPHER_AES_192_CBC;
    if (strcmp(name, "AES-256-CBC") == 0) return MBEDTLS_CIPHER_AES_256_CBC;
    if (strcmp(name, "AES-128-ECB") == 0) return MBEDTLS_CIPHER_AES_128_ECB;
    if (strcmp(name, "AES-192-ECB") == 0) return MBEDTLS_CIPHER_AES_192_ECB;
    if (strcmp(name, "AES-256-ECB") == 0) return MBEDTLS_CIPHER_AES_256_ECB;
    if (strcmp(name, "AES-128-GCM") == 0) return MBEDTLS_CIPHER_AES_128_GCM;
    if (strcmp(name, "AES-256-GCM") == 0) return MBEDTLS_CIPHER_AES_256_GCM;
    return MBEDTLS_CIPHER_NONE;
}

// ── openssl_encrypt：对称加密 ─────────────────────────────
//   ECB 模式特殊处理：mbedtls cipher_update 对 ECB 要求 ilen == block_size，
//   不支持部分块缓冲。因此 ECB 模式手动应用 PKCS7 padding 后逐块加密。
//   CBC/GCM 等模式使用 mbedtls 原生 update+finish 路径（支持 padding）。
static inline t_string tphp_fn_openssl_encrypt(
    t_string cipher, t_string key, t_string iv,
    t_string data, t_int options
) {
    const char* cipher_name = STR_PTR(cipher);
    mbedtls_cipher_type_t ct = _openssl_cipher_to_mbedtls(cipher_name);
    if (ct == MBEDTLS_CIPHER_NONE) {
        char buf[256];
        snprintf(buf, sizeof(buf), "openssl_encrypt: unknown cipher '%s'", cipher_name);
        _openssl_throw(buf);
        return (t_string){0};
    }

    const mbedtls_cipher_info_t* info = mbedtls_cipher_info_from_type(ct);
    if (info == NULL) {
        _openssl_throw("openssl_encrypt: cipher info not found");
        return (t_string){0};
    }

    mbedtls_cipher_context_t ctx;
    mbedtls_cipher_init(&ctx);
    int ret = mbedtls_cipher_setup(&ctx, info);
    if (ret != 0) {
        mbedtls_cipher_free(&ctx);
        _openssl_throw("openssl_encrypt: mbedtls_cipher_setup failed");
        return (t_string){0};
    }

    ret = mbedtls_cipher_setkey(&ctx, (const unsigned char*)STR_PTR(key),
                                (int)key.length * 8, MBEDTLS_ENCRYPT);
    if (ret != 0) {
        mbedtls_cipher_free(&ctx);
        _openssl_throw("openssl_encrypt: mbedtls_cipher_setkey failed");
        return (t_string){0};
    }

    size_t block_size = mbedtls_cipher_get_block_size(&ctx);
    int is_ecb = (mbedtls_cipher_get_cipher_mode(&ctx) == MBEDTLS_MODE_ECB);

    // ── ECB 模式：手动 PKCS7 padding + 逐块加密 ──
    // mbedtls cipher_update 对 ECB 要求 ilen == block_size，finish 是 no-op
    // mbedtls_cipher_set_padding_mode 对 ECB 返回 BAD_INPUT_DATA（仅 CBC 支持 padding），
    // 因此 ECB 路径完全手动处理 padding，不调用 set_padding_mode
    if (is_ecb) {
        size_t padded_len;
        const unsigned char* src;
        unsigned char* padded = NULL;

        if (options & 2) {
            // ZERO_PADDING：数据必须是块大小倍数
            if (data.length % block_size != 0) {
                mbedtls_cipher_free(&ctx);
                _openssl_throw("openssl_encrypt: ECB with ZERO_PADDING requires block-aligned data");
                return (t_string){0};
            }
            padded_len = (size_t)data.length;
            src = (const unsigned char*)STR_PTR(data);
        } else {
            // PKCS7：填充到下一个块边界（即使已对齐也加一个完整块）
            padded_len = ((size_t)data.length / block_size + 1) * block_size;
            padded = (unsigned char*)malloc(padded_len);
            if (padded == NULL) {
                mbedtls_cipher_free(&ctx);
                _openssl_throw("openssl_encrypt: malloc failed");
                return (t_string){0};
            }
            memcpy(padded, STR_PTR(data), (size_t)data.length);
            unsigned char pad_byte = (unsigned char)(padded_len - (size_t)data.length);
            for (size_t i = (size_t)data.length; i < padded_len; i++) {
                padded[i] = pad_byte;
            }
            src = padded;
        }

        unsigned char* out = (unsigned char*)malloc(padded_len > 0 ? padded_len : 1);
        if (out == NULL) {
            if (padded) free(padded);
            mbedtls_cipher_free(&ctx);
            _openssl_throw("openssl_encrypt: malloc failed");
            return (t_string){0};
        }

        size_t total = 0;
        for (size_t i = 0; i < padded_len; i += block_size) {
            size_t olen = 0;
            ret = mbedtls_cipher_update(&ctx, src + i, block_size, out + total, &olen);
            if (ret != 0) {
                free(out);
                if (padded) free(padded);
                mbedtls_cipher_free(&ctx);
                _openssl_throw("openssl_encrypt: ECB cipher_update failed");
                return (t_string){0};
            }
            total += olen;
        }

        size_t final_len = 0;
        ret = mbedtls_cipher_finish(&ctx, out + total, &final_len);
        if (ret != 0) {
            free(out);
            if (padded) free(padded);
            mbedtls_cipher_free(&ctx);
            _openssl_throw("openssl_encrypt: ECB cipher_finish failed");
            return (t_string){0};
        }

        if (padded) free(padded);
        mbedtls_cipher_free(&ctx);

        t_string result = tphp_rt_str_dup((t_string){
            .data = (char*)out, .length = (int)(total + final_len),
            .is_local = false, .is_lit = false
        });
        free(out);
        return result;
    }

    // ── 非 ECB 模式（CBC 等）：使用 mbedtls 原生 update+finish ──
    // OPENSSL_ZERO_PADDING (2): 禁用 padding
    if (options & 2) {
        ret = mbedtls_cipher_set_padding_mode(&ctx, MBEDTLS_PADDING_NONE);
    } else {
        ret = mbedtls_cipher_set_padding_mode(&ctx, MBEDTLS_PADDING_PKCS7);
    }
    if (ret != 0) {
        mbedtls_cipher_free(&ctx);
        _openssl_throw("openssl_encrypt: mbedtls_cipher_set_padding_mode failed");
        return (t_string){0};
    }

    if (iv.length > 0) {
        ret = mbedtls_cipher_set_iv(&ctx, (const unsigned char*)STR_PTR(iv),
                                    (size_t)iv.length);
        if (ret != 0) {
            mbedtls_cipher_free(&ctx);
            _openssl_throw("openssl_encrypt: mbedtls_cipher_set_iv failed");
            return (t_string){0};
        }
    }

    size_t out_len = 0;
    size_t buf_size = (size_t)data.length + block_size + 1;
    unsigned char* out = (unsigned char*)malloc(buf_size);
    if (out == NULL) {
        mbedtls_cipher_free(&ctx);
        _openssl_throw("openssl_encrypt: malloc failed");
        return (t_string){0};
    }

    ret = mbedtls_cipher_update(&ctx, (const unsigned char*)STR_PTR(data),
                                (size_t)data.length, out, &out_len);
    if (ret != 0) {
        free(out);
        mbedtls_cipher_free(&ctx);
        _openssl_throw("openssl_encrypt: mbedtls_cipher_update failed");
        return (t_string){0};
    }

    size_t final_len = 0;
    ret = mbedtls_cipher_finish(&ctx, out + out_len, &final_len);
    if (ret != 0) {
        free(out);
        mbedtls_cipher_free(&ctx);
        _openssl_throw("openssl_encrypt: mbedtls_cipher_finish failed");
        return (t_string){0};
    }

    mbedtls_cipher_free(&ctx);
    int total = (int)(out_len + final_len);
    t_string result = tphp_rt_str_dup((t_string){
        .data = (char*)out, .length = total,
        .is_local = false, .is_lit = false
    });
    free(out);
    return result;
}

// ── openssl_decrypt：对称解密 ─────────────────────────────
//   ECB 模式特殊处理：逐块解密后手动移除 PKCS7 padding。
static inline t_string tphp_fn_openssl_decrypt(
    t_string cipher, t_string key, t_string iv,
    t_string data, t_int options
) {
    const char* cipher_name = STR_PTR(cipher);
    mbedtls_cipher_type_t ct = _openssl_cipher_to_mbedtls(cipher_name);
    if (ct == MBEDTLS_CIPHER_NONE) {
        char buf[256];
        snprintf(buf, sizeof(buf), "openssl_decrypt: unknown cipher '%s'", cipher_name);
        _openssl_throw(buf);
        return (t_string){0};
    }

    const mbedtls_cipher_info_t* info = mbedtls_cipher_info_from_type(ct);
    if (info == NULL) {
        _openssl_throw("openssl_decrypt: cipher info not found");
        return (t_string){0};
    }

    mbedtls_cipher_context_t ctx;
    mbedtls_cipher_init(&ctx);
    int ret = mbedtls_cipher_setup(&ctx, info);
    if (ret != 0) {
        mbedtls_cipher_free(&ctx);
        _openssl_throw("openssl_decrypt: mbedtls_cipher_setup failed");
        return (t_string){0};
    }

    ret = mbedtls_cipher_setkey(&ctx, (const unsigned char*)STR_PTR(key),
                                (int)key.length * 8, MBEDTLS_DECRYPT);
    if (ret != 0) {
        mbedtls_cipher_free(&ctx);
        _openssl_throw("openssl_decrypt: mbedtls_cipher_setkey failed");
        return (t_string){0};
    }

    size_t block_size = mbedtls_cipher_get_block_size(&ctx);
    int is_ecb = (mbedtls_cipher_get_cipher_mode(&ctx) == MBEDTLS_MODE_ECB);

    // ── ECB 模式：逐块解密 + 手动移除 PKCS7 padding ──
    // mbedtls cipher_update 对 ECB 要求 ilen == block_size，finish 是 no-op
    // mbedtls_cipher_set_padding_mode 对 ECB 返回 BAD_INPUT_DATA（仅 CBC 支持 padding），
    // 因此 ECB 路径完全手动处理 padding，不调用 set_padding_mode
    if (is_ecb) {
        if (data.length == 0 || data.length % block_size != 0) {
            mbedtls_cipher_free(&ctx);
            _openssl_throw("openssl_decrypt: ECB data must be non-empty and block-aligned");
            return (t_string){0};
        }

        unsigned char* out = (unsigned char*)malloc((size_t)data.length);
        if (out == NULL) {
            mbedtls_cipher_free(&ctx);
            _openssl_throw("openssl_decrypt: malloc failed");
            return (t_string){0};
        }

        const unsigned char* src = (const unsigned char*)STR_PTR(data);
        size_t total = 0;
        for (size_t i = 0; i < (size_t)data.length; i += block_size) {
            size_t olen = 0;
            ret = mbedtls_cipher_update(&ctx, src + i, block_size, out + total, &olen);
            if (ret != 0) {
                free(out);
                mbedtls_cipher_free(&ctx);
                _openssl_throw("openssl_decrypt: ECB cipher_update failed");
                return (t_string){0};
            }
            total += olen;
        }

        size_t final_len = 0;
        ret = mbedtls_cipher_finish(&ctx, out + total, &final_len);
        if (ret != 0) {
            free(out);
            mbedtls_cipher_free(&ctx);
            _openssl_throw("openssl_decrypt: ECB cipher_finish failed");
            return (t_string){0};
        }

        mbedtls_cipher_free(&ctx);

        // 移除 PKCS7 padding（仅当未指定 ZERO_PADDING）
        if (!(options & 2) && total > 0) {
            unsigned char pad_byte = out[total - 1];
            if (pad_byte == 0 || pad_byte > block_size) {
                free(out);
                _openssl_throw("openssl_decrypt: invalid PKCS7 padding");
                return (t_string){0};
            }
            for (size_t i = 0; i < (size_t)pad_byte; i++) {
                if (out[total - 1 - i] != pad_byte) {
                    free(out);
                    _openssl_throw("openssl_decrypt: invalid PKCS7 padding");
                    return (t_string){0};
                }
            }
            total -= pad_byte;
        }

        t_string result = tphp_rt_str_dup((t_string){
            .data = (char*)out, .length = (int)total,
            .is_local = false, .is_lit = false
        });
        free(out);
        return result;
    }

    // ── 非 ECB 模式（CBC 等）：使用 mbedtls 原生 update+finish ──
    // OPENSSL_ZERO_PADDING (2): 禁用 padding
    if (options & 2) {
        ret = mbedtls_cipher_set_padding_mode(&ctx, MBEDTLS_PADDING_NONE);
    } else {
        ret = mbedtls_cipher_set_padding_mode(&ctx, MBEDTLS_PADDING_PKCS7);
    }
    if (ret != 0) {
        mbedtls_cipher_free(&ctx);
        _openssl_throw("openssl_decrypt: mbedtls_cipher_set_padding_mode failed");
        return (t_string){0};
    }

    if (iv.length > 0) {
        ret = mbedtls_cipher_set_iv(&ctx, (const unsigned char*)STR_PTR(iv),
                                    (size_t)iv.length);
        if (ret != 0) {
            mbedtls_cipher_free(&ctx);
            _openssl_throw("openssl_decrypt: mbedtls_cipher_set_iv failed");
            return (t_string){0};
        }
    }

    size_t out_len = 0;
    size_t buf_size = (size_t)data.length + block_size + 1;
    unsigned char* out = (unsigned char*)malloc(buf_size);
    if (out == NULL) {
        mbedtls_cipher_free(&ctx);
        _openssl_throw("openssl_decrypt: malloc failed");
        return (t_string){0};
    }

    ret = mbedtls_cipher_update(&ctx, (const unsigned char*)STR_PTR(data),
                                (size_t)data.length, out, &out_len);
    if (ret != 0) {
        free(out);
        mbedtls_cipher_free(&ctx);
        _openssl_throw("openssl_decrypt: mbedtls_cipher_update failed");
        return (t_string){0};
    }

    size_t final_len = 0;
    ret = mbedtls_cipher_finish(&ctx, out + out_len, &final_len);
    if (ret != 0) {
        free(out);
        mbedtls_cipher_free(&ctx);
        _openssl_throw("openssl_decrypt: mbedtls_cipher_finish failed (wrong key/padding)");
        return (t_string){0};
    }

    mbedtls_cipher_free(&ctx);
    int total = (int)(out_len + final_len);
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

static inline t_string tphp_fn_openssl_random_pseudo_bytes(t_int length) {
    if (length <= 0) {
        return tphp_rt_str_dup((t_string){.data = (char*)"", .length = 0, .is_local = false, .is_lit = false});
    }
    _mbedtls_init_once();
    unsigned char* buf = (unsigned char*)malloc((size_t)length);
    if (buf == NULL) {
        _openssl_throw("openssl_random_pseudo_bytes: malloc failed");
        return (t_string){0};
    }
    int ret = mbedtls_ctr_drbg_random(&_mbedtls_ctr_drbg, buf, (size_t)length);
    if (ret != 0) {
        free(buf);
        _openssl_throw("openssl_random_pseudo_bytes: mbedtls_ctr_drbg_random failed");
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
// 哈希 API（基于 mbedtls_md）
// ════════════════════════════════════════════════════════════

// 内部：digest 名称 → mbedtls_md_type_t 映射
static inline mbedtls_md_type_t _openssl_digest_to_mbedtls(const char* name) {
    if (strcmp(name, "md5") == 0)    return MBEDTLS_MD_MD5;
    if (strcmp(name, "sha1") == 0)   return MBEDTLS_MD_SHA1;
    if (strcmp(name, "sha224") == 0) return MBEDTLS_MD_SHA224;
    if (strcmp(name, "sha256") == 0) return MBEDTLS_MD_SHA256;
    if (strcmp(name, "sha384") == 0) return MBEDTLS_MD_SHA384;
    if (strcmp(name, "sha512") == 0) return MBEDTLS_MD_SHA512;
    if (strcmp(name, "ripemd160") == 0) return MBEDTLS_MD_RIPEMD160;
    return MBEDTLS_MD_NONE;
}

// ── openssl_digest：计算哈希 ─────────────────────────────
static inline t_string tphp_fn_openssl_digest(t_string method, t_string data, t_bool raw_output) {
    const char* method_name = STR_PTR(method);
    mbedtls_md_type_t md_type = _openssl_digest_to_mbedtls(method_name);
    if (md_type == MBEDTLS_MD_NONE) {
        char buf[256];
        snprintf(buf, sizeof(buf), "openssl_digest: unknown digest '%s'", method_name);
        _openssl_throw(buf);
        return (t_string){0};
    }

    const mbedtls_md_info_t* info = mbedtls_md_info_from_type(md_type);
    if (info == NULL) {
        _openssl_throw("openssl_digest: md info not found");
        return (t_string){0};
    }

    unsigned char hash[MBEDTLS_MD_MAX_SIZE];
    size_t hash_len = 0;
    int ret = mbedtls_md(info, (const unsigned char*)STR_PTR(data),
                         (size_t)data.length, hash);
    if (ret != 0) {
        _openssl_throw("openssl_digest: mbedtls_md failed");
        return (t_string){0};
    }
    hash_len = mbedtls_md_get_size(info);

    if (raw_output) {
        return tphp_rt_str_dup((t_string){
            .data = (char*)hash, .length = (int)hash_len,
            .is_local = false, .is_lit = false
        });
    }

    // 转十六进制
    char* hex = (char*)malloc(hash_len * 2 + 1);
    if (hex == NULL) {
        _openssl_throw("openssl_digest: malloc failed");
        return (t_string){0};
    }
    static const char hexchars[] = "0123456789abcdef";
    for (size_t i = 0; i < hash_len; i++) {
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
// ════════════════════════════════════════════════════════════

#define TPHP_STREAM_TLS_IMPLEMENTED

// 简化实现：创建临时 TLS 会话并执行握手
//   crypto_type: bitmask（STREAM_CRYPTO_METHOD_*_CLIENT），当前忽略，始终用默认 TLS 配置
//   enable=false: 关闭 TLS（返回 1，调用方应在此前用 openssl_ssl_shutdown）
//   成功返回 TLS ctx 指针值（t_int），失败抛异常返回 -1
//   注意：返回的 ctx 指针供后续 openssl_ssl_read/write 使用，需手动 openssl_ctx_free 释放
static inline t_int tphp_fn_stream_socket_enable_crypto(t_int fd, t_bool enable, t_int crypto_type) {
    (void)crypto_type;

    if (fd < 0) {
        _openssl_throw("stream_socket_enable_crypto: invalid fd");
        return -1;
    }

    if (!enable) {
        return 1;
    }

    _mbedtls_init_once();
    _tphp_ssl_ctx_t* ctx = (_tphp_ssl_ctx_t*)calloc(1, sizeof(_tphp_ssl_ctx_t));
    if (ctx == NULL) {
        _openssl_throw("stream_socket_enable_crypto: calloc failed");
        return -1;
    }
    mbedtls_ssl_init(&ctx->ssl);
    mbedtls_ssl_config_init(&ctx->conf);
    mbedtls_x509_crt_init(&ctx->cacert);
    mbedtls_x509_crt_init(&ctx->clicert);
    mbedtls_pk_init(&ctx->pk);
    ctx->is_server = 0;

    int ret = mbedtls_ssl_config_defaults(&ctx->conf, MBEDTLS_SSL_IS_CLIENT,
                                          MBEDTLS_SSL_TRANSPORT_STREAM,
                                          MBEDTLS_SSL_PRESET_DEFAULT);
    if (ret != 0) {
        char buf[256];
        snprintf(buf, sizeof(buf), "stream_socket_enable_crypto: config_defaults failed: -0x%04x",
                 (unsigned int)-ret);
        _openssl_throw(buf);
        tphp_fn_openssl_ctx_free((t_int)(intptr_t)ctx);
        return -1;
    }
    mbedtls_ssl_conf_rng(&ctx->conf, mbedtls_ctr_drbg_random, &_mbedtls_ctr_drbg);
    mbedtls_ssl_conf_authmode(&ctx->conf, MBEDTLS_SSL_VERIFY_NONE);

    ret = mbedtls_ssl_setup(&ctx->ssl, &ctx->conf);
    if (ret != 0) {
        _openssl_throw("stream_socket_enable_crypto: ssl_setup failed");
        tphp_fn_openssl_ctx_free((t_int)(intptr_t)ctx);
        return -1;
    }

    mbedtls_ssl_set_bio(&ctx->ssl, (void*)(intptr_t)fd,
                        mbedtls_net_send, mbedtls_net_recv, NULL);

    ret = mbedtls_ssl_handshake(&ctx->ssl);
    if (ret != 0) {
        char buf[256];
        snprintf(buf, sizeof(buf), "stream_socket_enable_crypto: handshake failed: -0x%04x",
                 (unsigned int)-ret);
        _openssl_throw(buf);
        tphp_fn_openssl_ctx_free((t_int)(intptr_t)ctx);
        return -1;
    }

    return (t_int)(intptr_t)ctx;
}
