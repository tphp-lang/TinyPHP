<?php
// ext/sqlite3/src/sqlite3.php — SQLite3 扩展（函数式 API）
//
// 纯 C 扩展：函数实现位于 sqlite3.h（tphp_fn_sqlite_ 前缀，static inline），
// PHP 侧直接调用 sqlite_open/sqlite_exec/sqlite_query/... 即可编译为 tphp_fn_sqlite_*。
//
// 设计说明（AOT 类型安全）：
//   - 所有函数参数/返回值使用 tphp 具体类型（int/string/array/bool）
//   - 数据库句柄用 int 存储（sqlite3* 指针转 int）
//   - 错误抛 Exception（可被 try-catch 捕获）
//   - 查询结果统一返回 array<array<string>>（列值统一转字符串）
//   - 不使用 mixed / t_var / resource
//
// 依赖：内置 SQLite amalgamation 3.46.0（include/os/sqlite_src/sqlite3.c）
//   通过 #flag 加入编译，与 ext/pdo 共享同一份源码（避免重复编译）

// SQLite amalgamation 源码（9MB 单文件，#flag .c 机制自动加入编译）
#flag __INC__ . "os/sqlite_src/sqlite3.c"
// 头文件搜索路径
#flag -I__INC__ . "os/sqlite_src"

// 引入 C 包装函数（static inline）+ 常量 + 辅助函数
#include __EXT__ . "sqlite3/sqlite3.h"

// ============================================================
// 常量（CodeGenerator 生成 TPHP_CONST_<NAME> #define）
// ============================================================

// ── query 返回数组模式 ──
const SQLITE3_ASSOC = 1;          // 关联数组
const SQLITE3_NUM = 2;            // 索引数组
const SQLITE3_BOTH = 3;           // 关联 + 索引

// ── 列类型（与 SQLite3 内部类型对齐）──
const SQLITE3_INTEGER = 1;
const SQLITE3_FLOAT = 2;
const SQLITE3_TEXT = 3;
const SQLITE3_BLOB = 4;
const SQLITE3_NULL = 5;

// ── sqlite_open flags（与 sqlite3_open_v2 flags 对齐）──
const SQLITE3_OPEN_READONLY = 1;
const SQLITE3_OPEN_READWRITE = 2;
const SQLITE3_OPEN_CREATE = 4;
