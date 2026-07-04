#pragma once
// ext/pcre — PCRE-compatible NFA VM regex engine (pure C, no external deps)
// Ported from vlang vlib/regex/pcre/regex.v (MIT, Dario Deledda)
// Supports ~90% of common PCRE syntax. No lookahead/lookbehind/backref.
//
// API 设计：全部 tphp_fn_ 前缀，PHP 侧直接调用，无 byRef 输出参数。
// preg_match / preg_match_all 返回 t_array*（空数组=无匹配，NULL=编译错误）。

#include "types.h"

// ── 常量 ──────────────────────────────────────────────
#define PREG_PATTERN_ORDER         1
#define PREG_SET_ORDER             2
#define PREG_SPLIT_NO_EMPTY        1
#define PREG_SPLIT_DELIM_CAPTURE    2
#define PREG_GREP_INVERT           1
#define PREG_NO_ERROR              0
#define PREG_INTERNAL_ERROR        1
#define PREG_BACKTRACK_LIMIT_ERROR 2
#define PREG_RECURSION_LIMIT_ERROR 3

// ── PHP API 函数（tphp_fn_ 前缀，PHP 直接调用） ──────

// preg_match: 返回匹配数组；空数组=无匹配，NULL=编译错误
//   result[0]   = 完整匹配
//   result[1..n] = 子组匹配
t_array* tphp_fn_preg_match(t_string pattern, t_string subject);

// preg_match_all: 返回 PREG_PATTERN_ORDER 风格二维数组
//   result[0] = [所有完整匹配]
//   result[1] = [所有 group1 匹配]
t_array* tphp_fn_preg_match_all(t_string pattern, t_string subject);

// preg_replace: $limit=-1 无限制；支持 $1/$2 反向引用
t_string tphp_fn_preg_replace(t_string pattern, t_string replacement,
                              t_string subject, t_int limit);

// preg_split: $limit=-1 无限制
t_array* tphp_fn_preg_split(t_string pattern, t_string subject, t_int limit, t_int flags);

// preg_grep: $flags=PREG_GREP_INVERT 返回不匹配的元素
t_array* tphp_fn_preg_grep(t_string pattern, t_array *input, t_int flags);

// preg_quote: 转义正则特殊字符；delimiter 为空串则只转义元字符
t_string tphp_fn_preg_quote(t_string str, t_string delimiter);

// 错误信息
t_int    tphp_fn_preg_last_error(void);
t_string tphp_fn_preg_last_error_msg(void);
