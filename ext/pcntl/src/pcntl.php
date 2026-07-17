<?php
// ext/pcntl/src/pcntl.php — 进程控制扩展 stub（POSIX only）
//
// 纯 C 扩展：函数实现位于 pcntl.c（tphp_fn_ 前缀），PHP 侧直接调用。
// 设计遵循 phpc 显式模型：
//   - #import pcntl 只把本 .php 加入编译列表（不自动收集 .c）
//   - C 依赖通过 #flag 显式声明：pcntl.c 由编译器编译并链接
//   - #include __EXT__ 引入 pcntl.h（仅函数声明 + 自带 types.h，放 common.h 前安全）

#flag __EXT__ . "pcntl/src/pcntl.c"
#include __EXT__ . "pcntl/src/pcntl.h"
