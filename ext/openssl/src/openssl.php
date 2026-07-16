<?php
// ext/openssl/src/openssl.php — OpenSSL 扩展（TLS/SSL 加密支持）
//
// 本文件不做 phpc 桥接包装：所有 C 函数已使用 tphp_fn_ 前缀，
// PHP 侧直接调用 openssl_encrypt/openssl_digest/... 即可编译为 tphp_fn_openssl_*。
// 常量已在 openssl.h 中以 TPHP_CONST_OPENSSL_* 定义（CodeGenerator 自动加前缀引用）。
// 此文件唯一作用：通过 #include 将 openssl.h 引入生成的 C 代码，
// 使 tphp_fn_openssl_* 等函数声明对主 TU 可见（避免隐式 int 返回截断指针）。
//
// 依赖策略：
//   预编译 OpenSSL 3.0.21 静态库 + 头文件，由 CI 构建并打包
//   （ext/openssl/prebuilt/<OS>/lib/ + include/）
//
// 跨平台：
//   Windows: 需 ws2_32（OpenSSL 依赖 winsock）+ 预编译静态库
//   Linux:   需预编译静态库 + pthread/dl（OpenSSL 依赖）
//   macOS:   需预编译静态库
//   编译器:  TCC 需 TCC 编译的 OpenSSL 静态库（COFF/ELF 格式不兼容），
//            放在 lib-tcc/ 目录；GCC/Clang 用 lib/ 或 lib64/。
//            CI 中每个编译器 job 各自编译 OpenSSL 产出对应格式库。
//
// 包含顺序（重要）：
//   openssl.h 必须在 stream.h 之前 include
//   这样 openssl.h 定义的 TPHP_STREAM_TLS_IMPLEMENTED 生效，
//   stream.h 中的 stream_socket_enable_crypto stub 被跳过，
//   使用 openssl.h 中的真实 TLS 实现

#include __EXT__ . "openssl/src/openssl.h"

// OpenSSL 头文件路径（平台区分）
#flag Windows -I__EXT__ . "openssl/prebuilt/Windows/include"
#flag Linux -I__EXT__ . "openssl/prebuilt/Linux/include"
#flag Darwin -I__EXT__ . "openssl/prebuilt/Darwin/include"

// 库文件路径 + 链接（TCC 用 lib-tcc/，GCC/Clang 用 lib/ 或 lib64/）
// TCC 无法链接 MinGW GCC 生成的 COFF 静态库，必须用 TCC 重新编译 OpenSSL
#if TCC
#flag Windows -L__EXT__ . "openssl/prebuilt/Windows/lib-tcc"
#flag Linux -L__EXT__ . "openssl/prebuilt/Linux/lib-tcc"
#flag Darwin -L__EXT__ . "openssl/prebuilt/Darwin/lib-tcc"
#else
#flag Windows -L__EXT__ . "openssl/prebuilt/Windows/lib64"
#flag Linux -L__EXT__ . "openssl/prebuilt/Linux/lib"
#flag Darwin -L__EXT__ . "openssl/prebuilt/Darwin/lib"
#endif

// 链接 OpenSSL 静态库（-l flags 会被自动分离到 lateLinkFlags，放在源文件之后）
#flag -lssl -lcrypto
// Windows: OpenSSL 依赖 winsock + Windows Crypto API (CertOpenStore 等)
#flag Windows -lws2_32 -lcrypt32
