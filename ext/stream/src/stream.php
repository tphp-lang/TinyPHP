<?php
// ext/stream/src/stream.php — 跨平台 socket stream 扩展
//
// 本文件不做 phpc 桥接包装：所有 C 函数已使用 tphp_fn_ 前缀，
// PHP 侧直接调用 stream_socket_server/stream_close/... 即可编译为 tphp_fn_stream_*。
// 常量已在 stream.h 中以 TPHP_CONST_STREAM_* 定义（CodeGenerator 自动加前缀引用）。
//
// 跨平台：
//   Windows: winsock2 (WSAStartup/closesocket/ioctlsocket/WSAGetLastError)
//   POSIX:   sys/socket.h, sys/select.h, netdb.h, fcntl.h
//   编译器:  TCC/GCC/Clang 均支持（无内置原子/ASM 依赖）
//
// TLS 支持由 ext/openssl 扩展提供（openssl.h 定义 TPHP_STREAM_TLS_IMPLEMENTED 后
// stream.h 中的 stream_socket_enable_crypto stub 被跳过）。
//
// 注意：不要在此文件中 #include stream.h，否则它会被当作"用户 include"
//       放在 common.h 之前，导致 stream.h 依赖的 object/exception.h 中的
//       t_object 类型未完整定义（incomplete type 错误）。
//       stream.h 由 CodeGenerator 自动检测 stream_* 函数调用后在 common.h 之后 include。

#flag windows -lws2_32
