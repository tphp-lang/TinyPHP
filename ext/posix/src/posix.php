<?php
// ext/posix/src/posix.php — POSIX 系统函数扩展 stub
//
// 纯 C 扩展：函数实现位于 posix.c（tphp_fn_ 前缀），PHP 侧直接调用。
// 设计遵循 phpc 显式模型：
//   - #import posix 只把本 .php 加入编译列表（不自动收集 .c）
//   - C 依赖通过 #flag 显式声明：posix.c 由编译器编译并链接
//   - #include __EXT__ 引入 posix.h（仅函数声明 + 自带 types.h，放 common.h 前安全）

#flag __EXT__ . "posix/src/posix.c"
#include __EXT__ . "posix/src/posix.h"
