# Changelog

本文件记录 TinyPHP 的版本变更历史。格式参考 [Keep a Changelog](https://keepachangelog.com/zh-CN/)。

---

## [Unreleased]

### 修复

- **macOS `-framework` 链接支持**：TCC 不识别 macOS 的 `-framework X` 语法（会把 `X` 当作输入文件，报错 `file 'OpenGL' not found`）。`tphp.php` 在 #flag 处理中自动把 `-framework X` 转换为 `-Wl,-framework,X`（透传给系统 `ld`），`-F path` 同理转换为 `-Wl,-F,path`。`-Wl,` 开头的 token 分离到 `$lateLinkFlags`（链接器选项放在源文件之后，遵循单遍扫描顺序）

## [0.2.0-beta.3] — 2026-07-19

### 新增

- **PDO 扩展**（SQLite 驱动）：首个基于类的扩展实现，含 `PDO` + `PDOStatement` 两个类，16 + 17 = 33 个方法。SQLite amalgamation 3.46.0 静态编译，零运行时依赖。
  - **AOT 类型安全**：所有方法参数/返回值使用 tphp 具体类型（int/string/array/bool），不使用 `mixed`/`t_var`。PHP 原生 `mixed` 方法按类型拆分（`bindValueInt`/`bindValueStr`/`bindValueNamedInt`/`bindValueNamedStr`，`getAttributeStr`/`getAttributeInt`/`getAttributeBool`，`fetchColumnStr`/`fetchColumnInt`）
  - **指针 ↔ int 桥接**：sqlite3*/sqlite3_stmt* 指针以 `t_int` 存储在 PHP 类字段中，方法内部用 `phpc_int_to_ptr` 转回 `C.void*` 调用 SQLite C API
  - **错误处理**：所有错误抛 `Exception`（`tp_throw_ex`），可被 `try-catch` 捕获
  - 测试：`test/pdo/pdo_basic.php`（19 节覆盖连接/exec/prepare/位置绑定/命名绑定/execute(array)/fetch 模式/fetchAll/fetchColumn/事务/lastInsertId/rowCount/quote/getAttribute/setAttribute/errorCode/getColumnMeta/closeCursor 复用/错误处理/静态方法/NULL/float 列）
- **stream 扩展对标 PHP 原生补全**（新增 6 个函数，总数 15 → 21）：
  - `stream_set_write_buffer(int $fd, int $buffer): int` — 设置写缓冲（socket 无 stdio 缓冲，stub 返回 0）
  - `stream_set_timeout(int $fd, int $seconds, int $microseconds = 0): bool` — 设置读写超时（`setsockopt(SO_RCVTIMEO/SO_SNDTIMEO)`）
  - `stream_get_contents(int $fd, int $length = -1, int $offset = -1): string` — 读取剩余所有数据（`length=-1` 读全部，`offset=-1` 从当前位置）
  - `stream_get_line(int $fd, int $length, string $ending = ""): string` — 读到分隔符或长度（不返回 ending）
  - `stream_get_meta_data(int $fd): array` — 获取流元数据（`timed_out`/`blocked`/`eof`/`stream_type`/`unread_bytes`/`seekable`）
  - `stream_socket_pair(int $domain, int $type, int $protocol): array` — 创建一对互连的 socket（POSIX `socketpair()`，Windows 用 TCP 回环模拟）
- **统一 Response File 机制**（`tphp.php`）：当总命令行长度超过 8000 字符时，自动把可变参数（`-I`/`-D`/`-O`/源文件/`-L`/`-l`/`.a`）全部写入 `@file`，保留核心参数（编译器路径、`-B`、内置 `-I`、`-o`）在命令行。TCC/GCC/Clang 均支持 `@file` 语法。彻底解决 Windows CreateProcess 32767 字符限制问题。
  - 覆盖主编译路径和 TCC `.a` fallback 路径
  - 修复旧机制仅覆盖 `$extraSrcs` 且会被 zlib 检测覆盖的 bug
  - 修复 fallback 路径丢失 `-shared` 标记的潜在 bug

### 变更

- **`C->` 调用强制类型声明**（AOT 类型安全）：`C->func()` 和 `C->CONST` 赋值给变量时**必须显式声明类型**，否则编译错误。消除了 `$ptrFns` 白名单和默认 `t_int` 假设，编译期即捕获类型错误。
  - 语句上下文（`C->foo();`）无需声明
  - 赋值上下文：`int $rc = C->foo();` / `C.void* $p = C->foo();` / `float $x = C->foo();`
  - 表达式上下文：用 cast 包装（`php_int(C->foo())`）或先赋值给类型化变量
  - 影响：16 处旧代码需补充类型声明（1 处 ext/demo + 15 处 test/phpc）
- **`#import` 机制重构为显式模型**：`#import name` 只收集 `ext/name/src/*.php`，不再自动收集 `.c` 文件。C 依赖由 ext 的 `.php` 通过 `#flag` 显式声明（如 `#flag __EXT__ . "name/src/name.c"`），符合 phpc 显式声明哲学。
  - 移除 `tphp.php` 中 `#import` 的 `.c` 自动收集逻辑（`$importCFiles` 变量删除）
  - 移除 `tphp.php` 中 `#include .h` 自动关联同名 `.c` 的副作用（`#include` 只负责引入头文件）
  - `ext/pcre/src/pcre.php`、`ext/demo/src/demo.php` 添加 `#flag __EXT__ . ".../xxx.c"` 显式声明 C 依赖
  - 新建 `ext/posix/src/posix.php`、`ext/pcntl/src/pcntl.php` stub（纯 C 扩展的 .php 入口，声明 `#flag` + `#include`）
  - `ext/posix/src/posix.c`、`ext/pcntl/src/pcntl.c` 修复 include 依赖：移除 `common.h`（含非 static inline 函数，多 TU 重复定义），Windows 分支改用 `fprintf+exit`（不使用 `tp_throw`，因 posix/pcntl 在 Windows 上 `@skip`）
  - `CodeGenerator.php` `$builtinRetTypes` 注册 12 个 posix 函数 + 7 个 pcntl 函数返回类型
- **stream 常量体系对标 PHP 原生**：
  - 新增 `STREAM_SOCK_RAW`=3、`STREAM_IPPROTO_IP`=0
  - 修正 `STREAM_OPTION_WRITE_BUFFER`=5（原错误命名 `WRITE_TIMEOUT`）、`STREAM_OPTION_CHUNK_SIZE`=7（原错误值 6）
  - `STREAM_CRYPTO_METHOD_*` 改为 PHP 原生 `_CLIENT`/`_SERVER` 后缀 + bitmask 值（如 `TLSv1_2_CLIENT`=0x10），保留无后缀别名指向 `_CLIENT` 版本（向后兼容）
  - 新增 `STREAM_CRYPTO_METHOD_ANY_CLIENT`/`ANY_SERVER`=0x3F、`STREAM_CRYPTO_PROTO_*`（PHP 8.1+ 别名）
  - C 宏名统一全大写（如 `SSLV2_CLIENT` 而非 `SSLv2_CLIENT`），因为 CodeGenerator 把 PHP 常量名 `strtoupper` 后映射到 C 宏
- **`stream_socket_enable_crypto` 返回值语义修正**：stub 和真实实现失败时返回 `-1`（而非 `0`），避免与 PHP 原生"0=非阻塞需重试"语义混淆

### 修复

- **`stream_select` 数组索引安全**：过滤未就绪 fd 后 in-place 压缩数组，同时调用 `arr_stridx_free`/`arr_intidx_free` 清除哈希索引，避免 entry 位置变化导致索引指向错误位置（内存安全 bug）
- **`CodeGenerator.php` 字符串键类型追踪不一致**（`visitArrayAccess`）：未知字符串键默认改为 `'t_string'`（与 `inferType` 行为和注释"无记录用 get_str_str"一致），原代码误用数组变量类型 `t_array*` 导致 `$meta["stream_type"]` 生成 `tphp_fn_arr_get_str_arr`（返回 `t_array*`），传给 `strlen` 报类型错误
- **`CodeGenerator.php` BinaryExpr 误匹配嵌套调用**：`str_contains($lCode, 'tphp_fn_arr_get_str_str')` 会误匹配嵌套在 `strlen(...)` 等调用内的 `get_str_str`，导致 `tphp_rt_parse_int(tphp_fn_strlen(...))` 类型错误。改用 `$lt === 't_string'` 类型推断检查
- **`array.h` 数组 getter 不处理 TYPE_BOOL**：`arr_get_str_int`/`arr_get_str_str`/`arr_get_int_int`/`arr_get_int_str` 对 TYPE_BOOL 返回零值/空串，导致 `$meta["blocked"] === true` 恒为 false。修复为遵循 PHP 类型转换语义（bool true → 1/"1"，false → 0/""）

### 文档

- [EXT_IMPLEMENTATION.md](EXT_IMPLEMENTATION.md) — stream 章节更新：函数数 15 → 21，常量表补全（新增 `STREAM_SOCK_RAW`/`STREAM_IPPROTO_IP`/`STREAM_CRYPTO_METHOD_*_CLIENT`/`_SERVER`/`STREAM_CRYPTO_PROTO_*`），修正 `STREAM_OPTION_WRITE_BUFFER`/`CHUNK_SIZE` 值，API 列表新增 6 个函数
- [FUNCTIONS.md](FUNCTIONS.md) — stream 章节同步更新，总览函数数 339+ → 345+
- [README.md](README.md) — 内置函数数 306+ → 312+

### 测试

- `test/ext/stream_basic.php` 完整重写：18 个测试节覆盖全部 21 个函数（strerror/last_error/TCP echo/get_name/select/blocking/read_buffer/write_buffer/timeout/isatty/get_contents/get_line/get_meta_data/enable_crypto/shutdown/socket_pair/error cases/constant values）
- 全部 168 个测试通过（Windows AMD64 + TCC）

### 变更（续）

- **OpenSSL 扩展方案重构：内置 mbedTLS 3.6.6 源码静态编译**（零运行时依赖）：
  - **核心约束**：TinyPHP 承诺零运行时依赖，不能动态链接系统 OpenSSL（`libssl.so`/`.dll` 等）
  - **参考方案**：vlang `thirdparty/mbedtls/` 集成模式，内置 mbedTLS 3.6.6 源码（`include/mbedtls_src/`）
  - **mbedTLS 3.6.6**：ARM 维护的 TLS 库，纯 C 无 ASM 依赖，TCC 友好，裁剪版配置仅启用 21 个 openssl 函数所需功能
  - **预编译静态库策略**：mbedtls 源码先逐文件编译为 `.o`，再归档为 `libmbedtls.a`，最后与主程序链接
    - 原因：TCC 一次编译过多 `.c` 文件时内部符号表溢出，导致 `static inline` 函数声明丢失（`tphp_fn_echo` 等变为隐式声明）
    - TCC 的 `-c` 模式不支持 `-o` 同时编译多个文件（报 `cannot specify output file with -c many files`），必须逐文件编译
    - 缓存机制：基于 `mbedtls_config.h` 和源文件 mtime 检测，未变更时复用 `build/mbedtls_cache/libmbedtls.a`
    - 预编译失败直接报错退出（不回退到直接编译 `.c`，避免符号表溢出）
  - **TCC 兼容性配置**（`mbedtls_config.h`）：
    - 禁用 `MBEDTLS_HAVE_ASM`、`MBEDTLS_AESNI_C`，强制 `MBEDTLS_HAVE_INT32`（避免 TCC 64x64→128 乘法 bug）
    - 禁用 TLS 1.3（需 PSA Crypto，已裁剪），仅保留 TLS 1.2
    - 添加 `ECDHE_RSA`/`ECDHE_ECDSA`/`RSA` 三种密钥交换方法
  - **Windows + TCC 兼容补丁**（`mbedtls_config.h` 末尾）：
    - `gmtime_s` 不可用（TCC win32 CRT 无 C11 边界检查库）→ 定义 `PLATFORM_UTIL_USE_GMTIME` 使用 `gmtime` 替代
    - `__stosb` 不可用（TCC 无 MSVC intrinsic，`SecureZeroMemory` 依赖）→ 定义 `MBEDTLS_PLATFORM_HAS_EXPLICIT_BZERO` + `explicit_bzero` 宏（volatile 循环）
  - **`-I` 路径顺序修复**：TinyPHP 的 `include/` 必须在 mbedtls 的 `-I` 路径之前，否则 mbedtls 的 `library/common.h` 会顶替 TinyPHP 的 `include/common.h`，导致 `tphp_fn_echo` 等 builtin 函数声明丢失
  - **测试恢复**：`test/ext/openssl_basic.php` 仍标记 `@skip`（CI 默认跳过，mbedTLS 预编译较慢），但本地可手动运行验证

### 新增

- **条件编译指令 `#if`/`#elseif`/`#else`/`#endif`**（TinyPHP 扩展）：
  - 解析期求值：非命中分支的 token 直接跳过（不解析、不类型检查、不生成 C 代码），与 V 语言 `$if` 默认行为一致
  - 可出现在**顶层**（包裹 `#include`/`#flag`/`#callback`/`#cstruct`/`class`/`function`/`const`/`enum`）和**函数体内**（包裹任意语句）
  - 条件表达式支持 `!`/`&&`/`||`/`()` 组合，标识符大小写不敏感
  - 内置标识符：`Windows`/`Linux`/`MacOS`/`Darwin`（OS）、`TCC`/`GCC`/`Clang`（编译器）、`x86_64`/`aarch64`/`arm64`（架构）、`debug`/`prod`（模式）
  - 未知标识符视为 `false`（前向兼容，不报错）
  - 目标 OS/架构优先取 `-os`/`-arch` 参数，未指定时取宿主环境（支持交叉编译条件判定）
  - `#elseif` 别名 `#elif`（兼容 C 习惯）
  - 测试用例：`test/syntax/conditional_compile.php`（顶层/函数体内/嵌套/复合条件/取反）

- **zlib 扩展对标 PHP 原生补全**（新增 22 个函数）：
  - **gz 文件流 API**（15 个）：`gzopen`/`gzclose`/`gzread`/`gzwrite`/`gzputs`/`gzeof`/`gzgets`/`gzgetc`/`gzrewind`/`gzseek`/`gztell`/`gzpassthru`/`gzflush`/`gzfile`/`readgzfile`，统一以 `Resource` 封装 `gzFile`
  - **增量上下文 API**（6 个）：`deflate_init`/`deflate_add`/`inflate_init`/`inflate_add`/`inflate_get_status`/`inflate_get_read_len`，上下文封装为 Resource
  - 通用接口 `zlib_encode`/`zlib_decode`
  - 完整常量集（编码格式/压缩级别/flush 模式/压缩策略/状态码/版本）
- **zip 扩展对标补全**（新增 5 个函数）：`zip_locate`、`zip_entry_name`、`zip_entry_filesize`、`zip_entry_compressedsize`、`zip_entry_compressionmethod`
- 完整常量集（ZIP 打开模式/标志位/压缩方法）

- **ext-stream 扩展**（新增 15 个函数，跨平台 socket stream）：
  - 核心 API：`stream_socket_server`/`stream_socket_client`/`stream_socket_accept`/`stream_close`/`stream_read`/`stream_write`/`stream_set_blocking`/`stream_socket_shutdown`/`stream_getsockname`/`stream_getpeername`/`stream_strerror`/`stream_isatty`/`stream_select`/`stream_socket_enable_crypto`（openssl.h 提供 TLS 实现，stream.h 提供 stub）/`stream_socket_recvfrom`
  - 完整常量集（45+）：socket 类型/协议、客户端/服务端标志、shutdown 模式、socket 选项、crypto 方法
  - 跨平台抽象：Windows winsock2（`closesocket`/`WSAGetLastError`/`ioctlsocket`/`SD_RECEIVE`/`SD_SEND`/`SD_BOTH`）vs POSIX（`close`/`errno`/`fcntl`/`SHUT_RD`/`SHUT_WR`/`SHUT_RDWR`）
  - Windows winsock 懒加载：首次 socket 操作触发 `WSAStartup`（`tphp_fn_stream_init`）
  - `FD_SETSIZE` 在 Windows 提升至 1024（默认 64 不满足高并发）
  - AOT 异常契约：所有错误抛 `Exception`（可 try-catch），不返回 `false`

- **ext-openssl 扩展**（新增 21 个函数，TLS/SSL 加密）：
  - **SSL Context API**（5 个）：`openssl_ctx_new`/`openssl_ctx_free`/`openssl_ctx_use_certificate_file`/`openssl_ctx_use_private_key_file`/`openssl_ctx_set_verify`/`openssl_ctx_set_options`
  - **SSL Connection API**（10 个）：`openssl_ssl_new`/`openssl_ssl_free`/`openssl_ssl_set_fd`/`openssl_ssl_connect`/`openssl_ssl_accept`/`openssl_ssl_read`/`openssl_ssl_write`/`openssl_ssl_shutdown`/`openssl_ssl_get_cipher_name`/`openssl_ssl_get_version`
  - **Error/Encrypt/Random/Hash API**（5 个）：`openssl_error_string`/`openssl_encrypt`/`openssl_decrypt`/`openssl_random_pseudo_bytes`/`openssl_digest`
  - 完整常量集（30+）：SSL 选项、验证模式、文件/密钥类型、加密选项
  - **依赖策略**：内置 mbedTLS 3.6.6 源码静态编译（`include/mbedtls_src/`），零运行时依赖，所有平台/编译器组合（包括纯 TCC 环境）均可使用
  - **TCC 兼容**：`mbedtls_config.h` 禁用 ASM + 强制 32 位 bignum limbs，Windows+TCC 额外补丁 `gmtime_s`/`__stosb`
  - **预编译策略**：逐文件编译为 `.o` → 归档为 `libmbedtls.a` → 链接到主程序（解决 TCC 符号表溢出）
  - **TLS 集成**：`openssl.h` 定义 `TPHP_STREAM_TLS_IMPLEMENTED` 后 `stream.h` 跳过 stub，使用真实 TLS 实现（openssl.h 必须在 stream.h 之前 include）
  - **SSL*/SSL_CTX* 指针以 t_int 流转**：遵循 exif FILE* 模式（`phpc_ptr_to_int`/`phpc_int_to_ptr`）
  - AOT 异常契约：所有错误抛 `tp_throw_ex` 异常（可被 try-catch 捕获）

### 变更

- **CI workflows 移除 OpenSSL 构建步骤**（`.github/workflows/build.yml` + `.github/workflows/test.yml`）：
  - 4 个 OS job（Windows/Linux x86_64/Linux aarch64/macOS）全部移除 TCC/GCC/Clang 分支的 OpenSSL 构建和 Verify 步骤
  - 移除 `OPENSSL_VERSION`/`OPENSSL_SOURCE_URL` 环境变量
  - MSYS2 安装项去掉 perl/nasm（仅 OpenSSL 构建需要）
  - 删除独立的 `.github/workflows/build-openssl.yml`（已合并到 build/test 后又移除）
  - **OpenSSL 扩展已通过内置 mbedTLS 源码恢复**，不再需要 CI 构建系统 OpenSSL 静态库
- **zlib 依赖架构重构**：从"系统 zlib 动态发现（MSYS2 路径 + PATH 扫描 + DLL 复制）"改为**内置 zlib 1.3.2 源码静态编译**：
  - 源码置于 `include/os/zlib_src/`（15 个 `.c` + 11 个 `.h`，约 332KB）
  - `tphp.php` 检测生成的 C 代码引用 `os/zlib.h` 后，自动将 zlib 源码 `.c` 加入编译列表
  - 移除 `tphp.php` 中写死的 MSYS2 路径（`C:\msys64\...`/`C:\env\msys2\...`）
  - 删除 `tcc/win32/lib/zlib1.dll`，零运行时依赖
  - **确保纯 TCC 环境（无 MSYS2/GCC/Clang）也能使用 zlib/zip 扩展**
- **AOT 异常契约统一**：zlib/zip 全部 API 失败时抛 `Exception`（可 try-catch），不返回 `false`
- `include/os/zlib.h` 简化为直接包含内置 `zlib_src/zlib.h`，移除 TCC 手动声明块
- zip 不支持修改已有归档：`zip_delete`/`zip_rename` 抛异常
- **移除 `c_float` / `php_float` 桥接函数**：`t_float` 就是 `double`，转换是无意义的空操作。float 类型直接传递即可。保留 `c_int`/`php_int`（有截断/提升意义）。
- **`.` 点指令只收集 .php 文件**：不再递归扫描 `.c` 文件，避免误收集不需要的源文件。`.c` 文件改由 `#flag` 显式声明（`#flag my_helper.c`），自动加入编译列表。
- **`tphp.php` TCC Windows 库搜索路径补全**：`-B` 仅设置 `tcc_lib_path`（用于 libtcc1.a），`-l` 库搜索走 `library_paths`。Windows dev 模式下额外追加 `-L` 指向 `tcc/win32/lib`，否则 `-lws2_32` 等系统库无法定位 `.def` 文件。
- **`CodeGenerator.php` stream/openssl 类型推断注册**：
  - `$simpleFnMap` 新增 `stream_*` → `tphp_fn_stream_*` 和 `openssl_*` → `tphp_fn_openssl_*` 映射
  - `$builtinRetTypes` 注册 stream/openssl 函数返回类型（避免指针被默认推断为 `t_int` 导致截断）
  - include 顺序：openssl.h 在 stream.h 之前（保证 `TPHP_STREAM_TLS_IMPLEMENTED` 先定义）
  - 项目根目录加入 `-I` 搜索路径（生成的 C 代码引用 `ext/stream/src/stream.h` 相对路径）

### 修复

- `gzfile()` 返回数组元素类型推断（`$builtinArrElemTypes` 注册为 `t_string`）
- `gzeof()` 行为说明：仅在读取超出末尾后返回 true（与 PHP 原生一致）
- `zlib_decode()` 不支持 RAW DEFLATE 自动检测，需用 `gzinflate()` 解码
- 测试运行器文件名冲突：用相对路径生成唯一可执行文件名（`test_zip_basic.exe`/`test_zlib_basic.exe`）
- Windows+TCC 的 `EWOULDBLOCK` 未定义：`zlib_src/zlib.h` 顶部 `#define EWOULDBLOCK EAGAIN`
- `$extraSrcs` 重建时机：追加 zlib 源码后必须重建，否则编译命令缺少这些 `.c` 文件
- TCC macOS `stdarg.h` 缺失：`zconf.h` 用 `__builtin_va_*` 替代，`gzwrite.c` 跳过 `#include <stdarg.h>`，`gzguts.h` POSIX 分支添加 `#include <unistd.h>`
- `CodeGenerator.php` 中 `c_str` 条件重复（清理）
- **Windows+TCC `inet_pton`/`inet_ntop` 隐式声明**：TCC 自带 `ws2tcpip.h` 仅声明 `getaddrinfo` 等，未声明 `inet_pton`/`inet_ntop`（与 `_WIN32_WINNT` 无关）。在 `ext/stream/src/stream.h` 中手动声明，用 `#ifndef __MINGW32__` 守卫（GCC/Clang MinGW 自带正确声明）
- **Windows+TCC `ws2_32.lib not found`**：双重根因——① `-B` 不影响 `-l` 库搜索路径，需额外 `-L` 指向 `tcc/win32/lib`；② `#pragma comment(lib, "ws2_32.lib")` 触发 TCC `tcc_add_pragma_libs()`，将完整名 `"ws2_32.lib"`（含 `.lib` 后缀）传给 `tcc_add_library()`，搜索 `ws2_32.lib.def` 等不存在的文件。移除 `#pragma comment(lib, ...)`，依赖 `#flag windows -lws2_32` 提供
- **`stream_strerror` 测试跨语言兼容**：Windows `FormatMessageA` 返回系统语言消息（中文 Windows 返回中文），测试改为验证 `strlen > 0`（非空），不比较确切文本
- **`_WIN32_WINNT 0x0600`**：Windows Vista+ 才有 `inet_pton`/`inet_ntop`，`ext/stream/src/stream.h` 顶部定义
- **Windows `SHUT_*` → `SD_*` 映射**：Windows winsock2 使用 `SD_RECEIVE`/`SD_SEND`/`SD_BOTH`，与 POSIX `SHUT_RD`/`SHUT_WR`/`SHUT_RDWR` 不同，stream.h 中条件映射

### 文档

- [FUNCTIONS.md](FUNCTIONS.md) — 补全 zlib（29 函数 + 完整常量表）和 zip（18 函数 + 完整常量表）章节，版本号修正为 `1.3.2`/`0x1320`
- [FUNCTIONS.md](FUNCTIONS.md) — 新增 stream（15 函数 + 4 张常量表）和 openssl（21 函数 + 4 张常量表 + 加密/摘要代码示例）章节，总览函数数更新为 `339+`
- [EXT_IMPLEMENTATION.md](EXT_IMPLEMENTATION.md) — zlib（§4）和 zip（§12）章节完整重写：
  - API 返回类型从 `string|false`/`ZipArchive|false` 改为 AOT 异常契约
  - 设计说明更新为"内置 zlib 1.3.2 源码静态编译"
  - 目录表函数数修正：zlib 6→29、zip 12→18
- [EXT_IMPLEMENTATION.md](EXT_IMPLEMENTATION.md) — 新增 stream（§5）章节，更新 OpenSSL（§8）章节：
  - stream：完整 API、Windows/TCC 兼容性说明（`inet_pton` 声明、`#pragma comment` 不兼容、`SHUT_*`→`SD_*` 映射）
  - OpenSSL：API 返回类型从 `string|false` 改为 `string|Exception` AOT 契约，新增预编译静态库策略说明
  - 目录表标记 stream/OpenSSL 为 ✅ 已完成
- [README.md](README.md) — 内置函数数量从 `281+` 更新为 `306+`，描述加入 zlib/zip

### 测试

- `test/zlib/basic.php` 重写：覆盖基础压缩/解压、gz 文件流、增量上下文、gzeof 双状态演示、RAW DEFLATE 解码
- `test/zip/basic.php` 扩展：覆盖归档创建/读取/条目信息查询/locate
- `test/ext/stream_basic.php` 新增：覆盖 `stream_strerror`（非空检查，跨语言兼容）、TCP echo（127.0.0.1 本地回环）、`stream_set_blocking`、`stream_socket_shutdown`
- `test/ext/openssl_basic.php` 新增（`@skip` 标记，OpenSSL 扩展暂停）：覆盖 `openssl_random_pseudo_bytes`、`openssl_digest`（sha256/md5/sha512）、`openssl_encrypt`/`openssl_decrypt` 往返（AES-256-CBC）、`openssl_error_string`

### 测试体系对标 PHP 原生

- **测试目录重组**：按功能类别重新归类，对齐 PHP 原生 `tests/` 布局
  - 移除按开发阶段命名的目录（`phase1/`/`phase2/`/`tier1/`/`tier2/`/`tier3/`/`builtin/`）
  - 顶层子目录改为功能分类：`lang/`/`func/`/`array/`/`strings/`/`math/`/`type/`/`object/`/`control_flow/`/`syntax/`/`error/`
  - 根目录散落的 `verify_*.php` 文件移入对应功能目录
  - 每个测试文件头部标注 PHP 原生测试编号（如 `// 对应 PHP tests/lang/007.phpt`）
- **新增 50+ PHP 8.5 原生测试移植**：
  - `test/lang/`：do-while、break/continue 多层、list 解构、闭包、heredoc、三元运算符、null 合并、foreach key=>value 等 11 个测试
  - `test/func/`：strlen、递归、默认参数、引用传参等 5 个测试
  - `test/strings/`：sprintf/printf、str_replace、substr、explode/implode、trim 系列、strpos、strrev、chr/ord、strtolower/strtoupper、str_contains/str_starts_with/str_ends_with、str_repeat/str_pad/str_split 等 15 个测试
  - `test/math/`：abs/ceil/floor/round、max/min/pow/sqrt、intdiv/fmod、进制转换、log/log10/sin/cos/tan/pi 等 9 个测试
  - `test/array/`：array_push/pop/shift/unshift、array_slice/merge/flip/reverse、array_keys/values/map/filter/reduce、sort/rsort/asort/ksort/usort、in_array/array_search/count、array_sum/product/fill/pad 等 8 个测试
- **测试数量**：169 → 209（+40，超出 200+ 目标）
- **跨平台 CI 全绿**：Windows AMD64 / Linux x86_64 / Linux aarch64 / macOS arm64 四平台全部通过

### 兼容性缺陷修复（14 项）

- **嵌套数组深层访问**（`$arr[0][1][2]`、`$m["items"][0]["id"]`）：
  - 根因：`visitArrayAccess`/`inferType` 仅追踪单层数组元素类型，3 层及以上嵌套访问返回空数组或空字符串
  - 修复：新增 `arrLiteralAST` 字段保存数组字面量 AST，`traceNestedAccessType()` 方法递归追踪访问链到具体键的值类型；`arrNestedDepth` 字段记录数组嵌套深度，`inferArrayNestedDepth()` 计算总深度，访问时按深度判断到达叶层还是中间数组层
  - 测试：`test/array/nested_access.php`（6 节覆盖 3 层 int、字符串键、混合 int/str 键、4 层深度、foreach 嵌套、嵌套运算）
- **list/[] 解构类型推断**：
  - 根因：`generateListAssign` 用单一 `elemType` 处理整个 list，混合类型数组 `[10, [20, [30]]]` 中 `$a1`（int）被当作 array 读取
  - 修复：`generateListAssign` 新增 `srcLiteral` 参数，从源数组字面量按位置推断每个元素类型（per-index type inference）；零值按元素类型生成（`t_string` → `(t_string){0}`，`t_float` → `0.0`，`t_array*` → `NULL`，`t_int`/`t_bool` → `0`）
  - 测试：`test/lang/lang_039_list_destructure.php`、`test/array/list_test.php`
- **`??` null 合并数组键**：
  - 根因：`??` 直接返回数组 getter 结果，但缺失键的 getter 返回默认值（0/空串）而非 null
  - 修复：`??` 左侧为 `ArrayAccessExpr` 时生成 `array_key_exists` 检查，键存在才取值，否则取右侧
  - 测试：`test/lang/lang_044_null_coalesce.php`
- **abs() 类型分派**：`abs(int)` → `tphp_fn_abs_int`，`abs(float)` → `tphp_fn_abs_float`，返回类型跟随参数类型（测试：`test/math/abs_basic.php`）
- **pow() 负指数语义**：`tphp_fn_pow` 整数路径要求 `exp.value._int >= 0`，负指数走 float 路径返回 float（PHP 语义，测试：`test/math/pow_sqrt_basic.php`）
- **sqrt() 负数返回 NAN**：从 `0.0` 改为 `NAN`（测试：`test/math/sqrt_basic.php`）
- **count() COUNT_RECURSIVE**：新增 `tphp_fn_arr_count_recursive` 递归统计嵌套数组元素数（测试：`test/array/count_recursive.php`）
- **array_pad() 实现**：新增 `tphp_fn_arr_pad`，支持正 size 右填充、负 size 左填充（测试：`test/array/array_pad_basic.php`）
- **array_keys() 搜索参数**：新增 `tphp_fn_array_keys_search`，用 `tphp_rt_var_equals` 严格比较类型+值（测试：`test/array/array_keys_search.php`）
- **max/min 可变参数**：新增 `variadic_pack` 分派，多个参数打包为临时 `t_array*` 再调用 `tphp_fn_max`/`tphp_fn_min`（测试：`test/math/max_min_basic.php`）
- **空数组字面量 `[]` 不设置 arrElementTypes**：避免 `[]` 被误判为 `t_int`，后续 `$arr[$k]=val` 变量键赋值后读取返回 0（测试：`test/array/array_str_key.php`）
- **未知字符串键类型回退**：`visitArrayAccess`/`inferType` 先查 `arrElementTypes[$arrName]` 再默认 `t_string`，避免类型不匹配
- **break 2 / continue 2 语义**：多层循环的 break/continue N 正确跳出指定层数（测试：`test/lang/lang_037.php`）
- **暂缓**：`array_splice` 需要 by-reference 修改支持，AOT 下不支持，测试保留 `@skip`

### 文档

- [FUNCTIONS.md](FUNCTIONS.md) — 更新 `count()`（COUNT_RECURSIVE）、`array_keys()`（search 参数）、`max()`/`min()`（可变参数）、`abs()`（类型分派）、`pow()`（负指数）、`sqrt()`（NAN）条目；新增 `array_pad()` 条目
- [GRAMMAR.md](GRAMMAR.md) — 更新 `??` 运算符（数组键用 array_key_exists）、`list`/`[]` 解构（类型感知零值）、新增嵌套数组深层访问条目、更新 `??=` 和 `...$args` 备注

### 测试

- 全部 209 个测试通过（Windows AMD64 / Linux x86_64 / Linux aarch64 / macOS arm64 四平台 CI 全绿）
- 含纯 TCC 环境验证（无 MSYS2/GCC/Clang）

---

## [0.2.0-beta.1] — 2026-07-14

首个公开测试版。PHP → C AOT 转译器，支持 Windows / Linux / macOS × x86_64 / aarch64，可选用 TCC / GCC / Clang 三种编译器。

### 核心特性

- **AOT 转译**：PHP 源码 → C 源码 → 原生机器码，无运行时解释器，零启动开销
- **强类型**：编译期类型固定，所有类型转换在编译期确定（无 PHP 弱类型运行时开销）
- **跨平台编译**：单一工具链支持 4 平台交叉编译（`-os`/`-arch` 参数）
- **C 互操作**：`phpc` 桥接层，可直接调用 C 库函数、声明 C 结构体、传递 C 指针
- **281+ 内置函数**：覆盖标准库（字符串/数组/数学/JSON/Hash/Date/ctype）、PCRE、iconv、exif、password、posix、pcntl、filter 等
- **多线程**：Thread/Mutex/CondVar/WaitGroup + Parallel::for/map 数据并行 API
- **动态库导出**：`#[Export]` 注解 + `-shared` 选项，将 PHP 函数导出为 C 符号

### 语法支持

#### 完全兼容 PHP 原生（✅）

- 控制流：`if/elseif/else`、`while`、`do-while`、`for`、`foreach`、`switch`、`match`、`break N`、`continue N`、`goto`
- OOP：`class`、`extends`、`interface`、`implements`、`trait+use`、`abstract class`、`final class`、`readonly`、`self::`、`parent::`、`parent::__construct()`
- 异常：`try/catch/finally`、`throw`（含表达式形式）、自定义 Exception 子类
- 函数：默认参数、命名参数（部分）、箭头函数（单表达式 + 块体）、闭包
- 类型：`int/float/string/bool/array/void`、`?T` 可选标记（局部变量/常量）、`Type|Exception` 返回类型
- 运算符：`**`、`<=>`、`??`、`|>` 管道、`?->` nullsafe、赋值复合运算符
- 字符串：heredoc/nowdoc、`{$var}` 花括号插值、转义序列
- 其他：`instanceof`、`isset()`、`empty()`、`enum`、`fn` 箭头函数、`yield`/Generator、属性 Hook、`#[Attribute]` 注解

#### 不支持（AOT 物理不可行）

- `eval()` / `assert($str)` / `create_function()` — 无运行时解释器
- `include` / `require` — 无运行时加载
- `$$var` / `compact()` / `extract()` / `get_defined_vars()` — 依赖运行时符号表
- `$fn()` / `call_user_func()` — 编译时不知函数名
- 魔术方法 `__call`/`__get`/`__set`/`__toString`/`__invoke`/`__clone` 等 — 需动态分发
- `Reflection*` 全系列、`debug_backtrace()`、`$GLOBALS` — 运行时内省

#### 不做（权衡）

- `?T` 可空类型 / `int|string` 联合类型 — 破坏类型固定优势
- `...$args` 可变参数、命名参数（完整版）— AOT 无意义/需动态栈
- `clone` / `declare(strict_types=1)` / `\u{}` Unicode 转义 / 返回引用 `function &f()`
- `protected` 可见性（仅 `public`/`private`）、`final` 方法修饰符（仅类级别）
- `catch (\Throwable $e)` — 无接口 vtable，用 `catch (Exception $e)` 替代

### 已知限制

- **`static` 属性修饰符**：语法上接受但当前标志会丢失（编译为实例属性）；仅内置类（Thread/Parallel/Enum）支持真正静态调用
- **macOS + TCC**：Generator 通过 pthread 线程模拟实现（替代 minicoro ASM/ucontext），其他平台使用原 minicoro
- **TCC + Windows**：使用 ELF 目标格式，与 MinGW/CMake 构建的 COFF 格式 `.a` 库不兼容

### 工具链

- 内置 TCC 编译器（无需安装 C 编译器即可使用）
- 支持 `-cc` 切换 GCC / Clang
- `--debug` 模式：打印编译命令 + `#debug` 预期输出比对
- CI 矩阵：4 平台 × 3 编译器 = 12 矩阵全量测试

### 测试

- 191 个测试文件，161 个可执行测试全部通过
- 含 `test/lang/` 目录 23 个对标 PHP 原生 `tests/lang/` 的语言基础测试

### 文档

- [README.md](README.md) — 快速上手 + CLI 用法
- [GRAMMAR.md](GRAMMAR.md) — 完整语法参考（含支持/不支持矩阵）
- [FUNCTIONS.md](FUNCTIONS.md) — 281+ 内置函数参考
- [QUICK_START.md](QUICK_START.md) — 5 分钟入门
- [CONTRIBUTING.md](CONTRIBUTING.md) — 架构与开发指南

---

## [0.1.0] — 内部开发版

初始内部版本，未公开发布。包含基础转译功能、OOP、控制流、标准库函数、PCRE 扩展。
