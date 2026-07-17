<?php
// ext/pcre/src/pcre.php — PCRE 正则扩展
//
// 本文件不做 phpc 桥接包装：所有 C 函数已使用 tphp_fn_ 前缀，
// PHP 侧直接调用 preg_match/preg_replace/... 即可编译为 tphp_fn_preg_*。
//
// 设计遵循 phpc 显式模型：
//   - #import pcre 只把本 .php 加入编译列表（不自动收集 .c）
//   - C 依赖通过 #flag 显式声明：pcre.c 由编译器编译并链接
//   - #include __EXT__ 引入 pcre.h（仅函数声明 + 自带 types.h，放 common.h 前安全）

#flag __EXT__ . "pcre/src/pcre.c"
#include __EXT__ . "pcre/src/pcre.h"
