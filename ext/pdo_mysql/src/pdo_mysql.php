<?php
// ext/pdo_mysql/src/pdo_mysql.php — MySQL 驱动（纯 C 协议实现）
//
// 设计说明：
//   - 纯 C 实现 MySQL 协议（不依赖 libmysqlclient）
//   - 通过 pdo_driver_t 接口暴露给 PHP
//   - 用户通过 new PDO("mysql:host=...;port=...;dbname=...") 使用
//   - 认证：mysql_native_password（SHA1，内置实现）
//   - 协议：文本协议（COM_QUERY），预处理用文本协议模拟
//   - 不支持 SSL/TLS、Unix socket、多语句
//
// 依赖：ext/stream（socket 跨平台抽象）
//   - 本文件通过 #include 直接引入 stream.h，无需用户额外 #import stream
//   - Windows 链接 ws2_32 由本文件 #flag windows -lws2_32 提供
//
// 包含顺序（CodeGenerator 保证 ext/ 路径的头文件放在 common.h 之后）：
//   1. stream/src/stream.h    — socket 跨平台抽象
//   2. pdo/pdo_driver.h       — pdo_driver_t 接口定义 + pdo_register_driver
//   3. pdo_mysql/pdo_mysql.h  — MySQL 协议实现 + 驱动注册

// Windows 需要 winsock2 库（socket/connect/recv/send 等符号）
#flag windows -lws2_32

// 引入 stream.h（提供 STREAM_CLOSE/STREAM_ERRNO 等宏 + tphp_fn_stream_init）
#include __EXT__ . "stream/src/stream.h"

// 引入 pdo_driver.h（pdo_driver_t 接口 + pdo_register_driver/pdo_find_driver）
#include __EXT__ . "pdo/pdo_driver.h"

// 引入 pdo_mysql.h（MySQL 协议实现 + driver 注册）
#include __EXT__ . "pdo_mysql/pdo_mysql.h"
