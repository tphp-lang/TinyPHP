/**
 * TinyPHP 自定义 mbedTLS 配置（裁剪版）
 *
 * 目标：仅支持 TinyPHP openssl 扩展的 21 个函数所需功能
 *   - 对称加密：AES-128/192/256-CBC（openssl_encrypt/decrypt）
 *   - 哈希：MD5/SHA1/SHA256/SHA512（openssl_digest）
 *   - 随机数：CTR_DRBG + Entropy（openssl_random_pseudo_bytes）
 *   - TLS 客户端：TLS 1.2/1.3（openssl_ssl_* + stream_socket_enable_crypto）
 *   - 证书解析：X.509 CRT/CRL PEM 解析（SSL 验证用）
 *
 * 裁剪内容（减小体积 + TCC 兼容）：
 *   - 禁用所有硬件 ASM（TCC 不支持）
 *   - 禁用 TLS 服务端（仅保留客户端）
 *   - 禁用 X.509 写入/CSR
 *   - 禁用 PKCS7/PKCS12
 *   - 禁用不需要的加密算法（ARIA/Camellia/DES/ChaCha20 等）
 *   - 禁用 PSA Crypto（减重）
 *   - 禁用 threading（单线程使用，TLS 操作内联）
 */

#ifndef MBEDTLS_CONFIG_H
#define MBEDTLS_CONFIG_H

/* ════════════════════════════════════════════════════════════
 * 系统层（必需）
 * ════════════════════════════════════════════════════════════ */
#define MBEDTLS_PLATFORM_C
#define MBEDTLS_PLATFORM_EXIT
#define MBEDTLS_PLATFORM_TIME
#define MBEDTLS_PLATFORM_FPRINTF
#define MBEDTLS_PLATFORM_PRINTF
#define MBEDTLS_PLATFORM_SNPRINTF
#define MBEDTLS_PLATFORM_VSNPRINTF

/* ════════════════════════════════════════════════════════════
 * TCC 兼容性配置（核心）
 *   - 禁用所有 ASM（TCC 不支持内联汇编）
 *   - 强制 32 位 bignum limbs（避免 TCC 64x64->128 乘法 bug）
 *   - 禁用 AES-NI / PadLock 硬件加速
 * ════════════════════════════════════════════════════════════ */
#if defined(__TINYC__)
    #undef MBEDTLS_HAVE_ASM
    #undef MBEDTLS_AESNI_C
    #undef MBEDTLS_PADLOCK_C
    #undef MBEDTLS_AESCE_C
    #define MBEDTLS_HAVE_INT32
#else
    #define MBEDTLS_HAVE_ASM
    /* AES-NI 仅在 x86_64 启用 */
    #if defined(__x86_64__) || defined(_M_X64)
        #define MBEDTLS_AESNI_C
    #endif
#endif

/* ════════════════════════════════════════════════════════════
 * 哈希算法（openssl_digest 支持）
 * ════════════════════════════════════════════════════════════ */
#define MBEDTLS_MD5_C
#define MBEDTLS_SHA1_C
#define MBEDTLS_SHA224_C
#define MBEDTLS_SHA256_C
#define MBEDTLS_SHA384_C
#define MBEDTLS_SHA512_C
#define MBEDTLS_MD_C
#define MBEDTLS_HASH_NO_HARDWARE

/* ════════════════════════════════════════════════════════════
 * 对称加密（openssl_encrypt/decrypt 支持 AES-CBC）
 * ════════════════════════════════════════════════════════════ */
#define MBEDTLS_AES_C
#define MBEDTLS_CIPHER_C
#define MBEDTLS_CIPHER_MODE_CBC
#define MBEDTLS_CIPHER_MODE_ECB
#define MBEDTLS_CIPHER_PADDING_PKCS7
#define MBEDTLS_CIPHER_PADDING_ZEROS

/* GCM/CCM（TLS AEAD 套件需要） */
#define MBEDTLS_GCM_C
#define MBEDTLS_CCM_C

/* ChaCha20-Poly1305（TLS 1.3 常用套件） */
#define MBEDTLS_CHACHA20_C
#define MBEDTLS_CHACHAPOLY_C
#define MBEDTLS_POLY1305_C

/* ════════════════════════════════════════════════════════════
 * 随机数（openssl_random_pseudo_bytes + TLS 握手）
 * ════════════════════════════════════════════════════════════ */
#define MBEDTLS_ENTROPY_C
#define MBEDTLS_CTR_DRBG_C
#define MBEDTLS_NO_PLATFORM_ENTROPY

/* Windows 熵源 */
#if defined(_WIN32)
    #define MBEDTLS_ENTROPY_WINDOWS_IMPL
#else
    #define MBEDTLS_ENTROPY_HARDWARE_ALT
    #define MBEDTLS_TIMING_C
#endif

/* ════════════════════════════════════════════════════════════
 * 公钥算法（TLS 握手需要 RSA/ECDSA/ECDH）
 * ════════════════════════════════════════════════════════════ */
#define MBEDTLS_RSA_C
#define MBEDTLS_PKCS1_V15
#define MBEDTLS_PKCS1_V21
#define MBEDTLS_ECP_C
#define MBEDTLS_ECDH_C
#define MBEDTLS_ECDSA_C
#define MBEDTLS_ECP_DP_SECP256R1_ENABLED
#define MBEDTLS_ECP_DP_SECP384R1_ENABLED
#define MBEDTLS_ECP_DP_SECP521R1_ENABLED
#define MBEDTLS_ECP_DP_CURVE25519_ENABLED
#define MBEDTLS_ECP_DP_CURVE448_ENABLED

#define MBEDTLS_PK_C
#define MBEDTLS_PK_PARSE_C
#define MBEDTLS_PK_PARSE_EC_COMPRESSED
#define MBEDTLS_PK_WRITE_C  /* X.509 CRT 解析间接需要 */

#define MBEDTLS_BIGNUM_C
#define MBEDTLS_GENPRIME

/* HKDF（TLS 1.3 密钥派生） */
#define MBEDTLS_HKDF_C

/* CMAC（部分 TLS 套件需要） */
#define MBEDTLS_CMAC_C

/* ════════════════════════════════════════════════════════════
 * TLS（核心功能）
 * ════════════════════════════════════════════════════════════ */
#define MBEDTLS_SSL_TLS_C
#define MBEDTLS_SSL_CLI_C
#define MBEDTLS_SSL_SRV_C           /* stream_socket_enable_crypto 需要 */
#define MBEDTLS_SSL_PROTO_TLS1_2
/* TLS 1.3 需要 PSA Crypto，已禁用（保持零依赖 + 减重） */

/* 密钥交换方法（至少启用一个，否则 check_config.h 报错） */
#define MBEDTLS_KEY_EXCHANGE_ECDHE_RSA_ENABLED   /* 前向保密，最常用 */
#define MBEDTLS_KEY_EXCHANGE_ECDHE_ECDSA_ENABLED /* ECDSA 证书 */
#define MBEDTLS_KEY_EXCHANGE_RSA_ENABLED         /* 兼容性回退 */

/* TLS 特性 */
#define MBEDTLS_SSL_DTLS_ANTI_REPLAY
#define MBEDTLS_SSL_ALPN
#define MBEDTLS_SSL_SERVER_NAME_INDICATION
#define MBEDTLS_SSL_SESSION_TICKETS
#define MBEDTLS_SSL_RENEGOTIATION
#define MBEDTLS_SSL_MAX_FRAGMENT_LENGTH
#define MBEDTLS_SSL_ENCRYPT_THEN_MAC
#define MBEDTLS_SSL_EXTENDED_MASTER_SECRET
#define MBEDTLS_SSL_TICKET_C

/* ════════════════════════════════════════════════════════════
 * X.509（TLS 证书验证）
 * ════════════════════════════════════════════════════════════ */
#define MBEDTLS_X509_USE_C
#define MBEDTLS_X509_CRT_PARSE_C
#define MBEDTLS_X509_CRL_PARSE_C
#define MBEDTLS_X509_CSR_PARSE_C    /* parse cert from stream */
#define MBEDTLS_X509_RSASSA_PSS_SUPPORT

/* ════════════════════════════════════════════════════════════
 * ASN.1 / OID / PEM（证书解析需要）
 * ════════════════════════════════════════════════════════════ */
#define MBEDTLS_ASN1_PARSE_C
#define MBEDTLS_ASN1_WRITE_C
#define MBEDTLS_OID_C
#define MBEDTLS_PEM_PARSE_C
#define MBEDTLS_PEM_WRITE_C

/* ════════════════════════════════════════════════════════════
 * 错误处理 / 时间 / 内存
 * ════════════════════════════════════════════════════════════ */
#define MBEDTLS_ERROR_C
#define MBEDTLS_ERROR_STRERROR_DUMMY
#define MBEDTLS_FS_IO
#define MBEDTLS_HAVE_TIME
#define MBEDTLS_HAVE_TIME_DATE
#define MBEDTLS_HAVE_DATE
#define MBEDTLS_NET_C            /* mbedtls_net_sockets.c (用于 SSL BIO) */

/* 标准内存分配（不使用 MBEDTLS_MEMORY_BUFFER_ALLOC_C）
 * 注意：不定义 MBEDTLS_PLATFORM_STD_* 宏，它们需要对应的 *_ALT 宏。
 * mbedtls 默认使用标准 C 库的 calloc/free/printf 等，无需额外配置。 */

/* ════════════════════════════════════════════════════════════
 * 常量时间（防侧信道）
 * ════════════════════════════════════════════════════════════ */
#define MBEDTLS_CONSTANT_TIME_C

/* ════════════════════════════════════════════════════════════
 * 编译选项
 * ════════════════════════════════════════════════════════════ */
/* Base64（部分 PEM 编码需要） */
#define MBEDTLS_BASE64_C

/* VERSION（openssl_ssl_get_version 等可能用到） */
#define MBEDTLS_VERSION_C

/* DEBUG（可选，生产可关） */
/* #define MBEDTLS_DEBUG_C */

/* SELF_TEST 不需要 */
/* #define MBEDTLS_SELF_TEST */

/* ════════════════════════════════════════════════════════════
 * 明确禁用的模块（减小体积）
 * ════════════════════════════════════════════════════════════ */
#undef MBEDTLS_ARIA_C
#undef MBEDTLS_CAMELLIA_C
#undef MBEDTLS_DES_C
#undef MBEDTLS_BLOWFISH_C
#undef MBEDTLS_ARC4_C
#undef MBEDTLS_XTEA_C
#undef MBEDTLS_NIST_KW_C
#undef MBEDTLS_RIPEMD160_C
#undef MBEDTLS_SHA3_C
#undef MBEDTLS_MD2_C
#undef MBEDTLS_MD4_C
#undef MBEDTLS_X509_WRITE_CRT
#undef MBEDTLS_X509_WRITE_CSR
#undef MBEDTLS_PKCS7_C
#undef MBEDTLS_PKCS12_C
#undef MBEDTLS_PKCS5_C
#undef MBEDTLS_LMS_C
#undef MBEDTLS_ECJPAKE_C
#undef MBEDTLS_DHM_C
#undef MBEDTLS_SSL_COOKIE_C
#undef MBEDTLS_SSL_CACHE_C
#undef MBEDTLS_MEMORY_BUFFER_ALLOC_C
#undef MBEDTLS_PLATFORM_ZEROIZE_ALT

/* PSA Crypto（全部禁用，减重最大） */
#undef MBEDTLS_PSA_CRYPTO_C
#undef MBEDTLS_PSA_CRYPTO_EXTERNAL_RNG
#undef MBEDTLS_PSA_CRYPTO_STORAGE_C
#undef MBEDTLS_PSA_CRYPTO_SPM
#undef MBEDTLS_PSA_INJECT_ENTROPY
#undef MBEDTLS_PSA_STATIC_CRYPTO_SLOTS

/* Threading（单线程使用，禁用以简化） */
#undef MBEDTLS_THREADING_C
#undef MBEDTLS_THREADING_PTHREAD
#undef MBEDTLS_THREADING_ALT

/* ════════════════════════════════════════════════════════════
 * 检查配置一致性
 * ════════════════════════════════════════════════════════════ */
#if defined(MBEDTLS_PSA_CRYPTO_C) && !defined(MBEDTLS_PSA_CRYPTO_EXTERNAL_RNG)
    /* PSA 启用时必须提供 RNG 源 */
#endif

/* ════════════════════════════════════════════════════════════
 * Windows + TCC 兼容性补丁
 *   TCC 的 win32 CRT 不提供 gmtime_s（C11 边界检查库）和 __stosb
 *   （MSVC intrinsic，SecureZeroMemory 依赖）。
 *   通过以下宏避开它们：
 *     - PLATFORM_UTIL_USE_GMTIME: 让 platform_util.c 使用 gmtime 替代 gmtime_s
 *     - MBEDTLS_PLATFORM_HAS_EXPLICIT_BZERO + explicit_bzero 宏: 替代
 *       SecureZeroMemory（绕过 __stosb），用 volatile 循环保证不被优化删除
 * ════════════════════════════════════════════════════════════ */
#if defined(_WIN32) && defined(__TINYC__)
#define PLATFORM_UTIL_USE_GMTIME
#define MBEDTLS_PLATFORM_HAS_EXPLICIT_BZERO
/* 注意：不能用 memset 宏（编译器可能优化掉），用 volatile 循环 */
#define explicit_bzero(s, n) do { \
    volatile unsigned char *_tphp_p = (volatile unsigned char *)(s); \
    unsigned long _tphp_n = (unsigned long)(n); \
    while (_tphp_n--) *_tphp_p++ = 0; \
} while (0)
#endif

#endif /* MBEDTLS_CONFIG_H */
