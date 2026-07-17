<?php
// ext/openssl/src/openssl.php — OpenSSL 扩展（TLS/SSL 加密支持）
//
// 本文件不做 phpc 桥接包装：所有 C 函数已使用 tphp_fn_ 前缀，
// PHP 侧直接调用 openssl_encrypt/openssl_digest/... 即可编译为 tphp_fn_openssl_*。
// 常量已在 openssl.h 中以 TPHP_CONST_OPENSSL_* 定义（CodeGenerator 自动加前缀引用）。
// 此文件唯一作用：通过 #include 将 openssl.h 引入生成的 C 代码，
// 使 tphp_fn_openssl_* 等函数声明对主 TU 可见（避免隐式 int 返回截断指针）。
//
// 依赖策略（参考 vlang 默认 TLS 方案，内置源码静态编译）：
//   内置 mbedTLS 3.6.6 源码（include/mbedtls_src/）静态编译，零运行时依赖。
//   - 所有平台/编译器组合（包括纯 TCC 环境）都能使用 openssl 扩展
//   - 无需外部 -lssl/-lcrypto 或系统 OpenSSL 安装
//   - TCC 兼容：通过 mbedtls_config.h 禁用 ASM + 强制 32 位 bignum limbs
//
// 跨平台：
//   Windows: 需 ws2_32（mbedtls net_sockets.c 依赖 winsock）
//   Linux:   需 pthread/dl（部分 mbedtls 模块依赖）— 默认已在 tphp.php 链接
//   macOS:   无额外依赖
//   编译器:  TCC/GCC/Clang 统一使用相同源码和配置
//
// 源码收集：
//   mbedtls_src/library/*.c 由 tphp.php 自动检测并加入 $allCFiles（参考 zlib_src 模式）
//   本文件仅声明 -I 头文件路径，不重复声明 .c 源码
//
// 包含顺序（重要）：
//   openssl.h 由 CodeGenerator 自动检测 openssl_* 函数调用后在 common.h 之后 include
//   （CodeGenerator.php 的 $needOpenssl 逻辑确保 openssl.h 在 common.h 之后）
//   openssl.h 必须在 stream.h 之前 include（CodeGenerator 保证此顺序）
//   这样 openssl.h 定义的 TPHP_STREAM_TLS_IMPLEMENTED 生效，
//   stream.h 中的 stream_socket_enable_crypto stub 被跳过，
//   使用 openssl.h 中的真实 TLS 实现
//
// 注意：不要在此文件中 #include openssl.h，否则它会被当作"用户 include"
//       放在 common.h 之前，导致 mbedtls 头文件干扰 TinyPHP 运行时头文件的 include 链。

// mbedtls 头文件路径（本地源码，通过 -I 查找）
//   __INC__ 展开为 TinyPHP 的 include/ 目录
//   mbedtls_src/include/        — mbedtls/*.h 和 psa/*.h
//   mbedtls_src/library/        — ssl_misc.h 等内部头
//   mbedtls_src/3rdparty/everest/include/ — X25519 实现
#flag -I__INC__ . "mbedtls_src/include"
#flag -I__INC__ . "mbedtls_src/library"
#flag -I__INC__ . "mbedtls_src/3rdparty/everest/include"
#flag -I__INC__ . "mbedtls_src/3rdparty/everest/include/everest"
#flag -I__INC__ . "mbedtls_src/3rdparty/everest/include/everest/kremlib"
