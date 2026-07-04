<?php
// ext/pcre/src/pcre.php — PCRE 正则扩展
//
// 本文件不做 phpc 桥接包装：所有 C 函数已使用 tphp_fn_ 前缀，
// PHP 侧直接调用 preg_match/preg_replace/... 即可编译为 tphp_fn_preg_*。
// 此文件唯一作用：通过 #include 将 pcre.h 引入生成的 C 代码，
// 使 tphp_fn_preg_match 等函数声明对主 TU 可见（避免隐式 int 返回截断指针）。

#include __EXT__ . "pcre/src/pcre.h"
