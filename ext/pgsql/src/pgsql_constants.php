<?php
// ext/pgsql/src/pgsql_constants.php — PostgreSQL 扩展常量定义（约 60 个）
//
// 与 PHP 8.5.8 ext/pgsql/pgsql.stub.php 对齐。
//
// 说明：
//   - PGSQL_CONNECT_FORCE_NEW (2)：强制创建新连接（不复用持久连接池）
//   - PGSQL_CONNECT_ASYNC (4)：异步连接（本期不支持，仅定义常量）
//   - PGSQL_CONNECT_TIMEOUT (8)：连接超时（本期不支持，仅定义常量）
//   - PGSQL_ASSOC/NUM/BOTH：结果集读取模式
//   - PGSQL_STATUS_LONG/STRING：pg_last_oid 返回值类型
//   - PGSQL_EMPTY_QUERY ~ PGSQL_FATAL_ERROR：pg_result_status 返回值
//   - PGSQL_SEEK_SET/CUR/END：lo_lseek 的 whence 参数
//   - PGSQL_DML_*：pg_insert/update/delete/select 的 option 参数
//   - PGSQL_TRANSACTION_*：pg_transaction_status 返回值
//   - PGSQL_DIAG_*：pg_result_error_field 的 fieldcode 参数
//   - PGSQL_ERRORS_TERSE/DEFAULT/VERBOSE：pg_set_error_verbosity 的 verbosity 参数
//   - PGSQL_CONV_*：pg_convert 的 options 参数
//   - PGSQL_CLOSE_FORCE/RESET：pg_close 的 option 参数（PHP 8.4+）

// ════════════════════════════════════════════════════════════
// 连接标志（pg_connect / pg_pconnect 的 flags 参数）
// ════════════════════════════════════════════════════════════
const PGSQL_CONNECT_FORCE_NEW = 2;            // 强制创建新连接
const PGSQL_CONNECT_ASYNC = 4;                // 异步连接（本期仅定义）
const PGSQL_CONNECT_TIMEOUT = 8;              // 连接超时（本期仅定义）

// ════════════════════════════════════════════════════════════
// 结果集读取模式（pg_fetch_array / pg_fetch_assoc / pg_fetch_row）
// ════════════════════════════════════════════════════════════
const PGSQL_ASSOC = 1;                        // 关联数组
const PGSQL_NUM = 2;                          // 数字索引数组
const PGSQL_BOTH = 3;                         // 同时返回关联和数字索引

// ════════════════════════════════════════════════════════════
// pg_last_oid 返回值类型
// ════════════════════════════════════════════════════════════
const PGSQL_STATUS_LONG = 1;                  // 返回整数 OID
const PGSQL_STATUS_STRING = 2;                // 返回字符串 OID

// ════════════════════════════════════════════════════════════
// pg_result_status 返回值（ExecStatusType）
// ════════════════════════════════════════════════════════════
const PGSQL_EMPTY_QUERY = 0;                  // 空查询
const PGSQL_COMMAND_OK = 1;                   // 命令成功（无结果集）
const PGSQL_TUPLES_OK = 2;                    // 查询成功（有结果集）
const PGRES_COPY_OUT = 3;                     // COPY TO 开始
const PGRES_COPY_IN = 4;                      // COPY FROM 开始
const PGSQL_BAD_RESPONSE = 5;                 // 无法理解的响应
const PGSQL_NONFATAL_ERROR = 6;               // 非致命错误
const PGSQL_FATAL_ERROR = 7;                  // 致命错误

// ════════════════════════════════════════════════════════════
// lo_lseek 的 whence 参数
// ════════════════════════════════════════════════════════════
const PGSQL_SEEK_SET = 0;                     // 从文件开头
const PGSQL_SEEK_CUR = 1;                     // 从当前位置
const PGSQL_SEEK_END = 2;                     // 从文件末尾

// ════════════════════════════════════════════════════════════
// pg_insert/update/delete/select 的 option 参数（DML 操作标志）
// ════════════════════════════════════════════════════════════
const PGSQL_DML_NO_CONV = 0;                  // 不进行类型转换
const PGSQL_DML_EXEC = 1;                     // 执行 SQL
const PGSQL_DML_ASYNC = 2;                    // 异步执行（本期仅定义）
const PGSQL_DML_STRING = 3;                   // 返回 SQL 字符串（不执行）
const PGSQL_DML_ESCAPE = 4;                   // 使用 pg_escape 转义

// ════════════════════════════════════════════════════════════
// pg_transaction_status 返回值
// ════════════════════════════════════════════════════════════
const PGSQL_TRANSACTION_IDLE = 0;             // 当前不在事务中
const PGSQL_TRANSACTION_ACTIVE = 1;           // 正在执行查询
const PGSQL_TRANSACTION_INTRANS = 2;          // 在事务块中（空闲）
const PGSQL_TRANSACTION_INERROR = 3;          // 在中止的事务块中
const PGSQL_TRANSACTION_UNKNOWN = 4;          // 未知（连接异常）

// ════════════════════════════════════════════════════════════
// pg_result_error_field 的 fieldcode 参数（ErrorResponse 字段标识）
// ════════════════════════════════════════════════════════════
const PGSQL_DIAG_SEVERITY = 0;                // 严重程度（ERROR/FATAL 等）
const PGSQL_DIAG_SQLSTATE = 1;                // SQLSTATE 错误码
const PGSQL_DIAG_MESSAGE = 2;                 // 主错误消息
// PGSQL_DIAG_DETAIL = 3 — 旧别名（PHP 8.x 用 PGSQL_DIAG_MESSAGE_PRIMARY）
const PGSQL_DIAG_MESSAGE_PRIMARY = 2;         // 主错误消息（别名）
const PGSQL_DIAG_DETAIL = 3;                  // 详细信息
const PGSQL_DIAG_MESSAGE_DETAIL = 3;          // 详细信息（别名）
const PGSQL_DIAG_HINT = 4;                    // 提示
const PGSQL_DIAG_MESSAGE_HINT = 4;            // 提示（别名）
const PGSQL_DIAG_POSITION = 5;                // 错误位置（1-based）
const PGSQL_DIAG_INTERNAL_POSITION = 6;       // 内部错误位置
const PGSQL_DIAG_INTERNAL_QUERY = 7;          // 内部 SQL 文本
const PGSQL_DIAG_CONTEXT = 8;                 // 上下文
const PGSQL_DIAG_SOURCE_FILE = 9;             // 源文件名
const PGSQL_DIAG_SOURCE_LINE = 10;            // 源行号
const PGSQL_DIAG_STATEMENT_POSITION = 5;      // 别名
const PGSQL_DIAG_NON_HIGHLIGHTED = 11;        // 非高亮（PHP 别名，PG16+ Schema 名）
const PGSQL_DIAG_SCHEMA_NAME = 11;            // 模式名
const PGSQL_DIAG_TABLE_NAME = 12;             // 表名
const PGSQL_DIAG_COLUMN_NAME = 13;            // 列名
const PGSQL_DIAG_DATATYPE_NAME = 14;          // 数据类型名
const PGSQL_DIAG_CONSTRAINT_NAME = 15;        // 约束名
const PGSQL_DIAG_SOURCE_FUNCTION = 18;        // 源函数名

// ════════════════════════════════════════════════════════════
// pg_set_error_verbosity 的 verbosity 参数
// ════════════════════════════════════════════════════════════
const PGSQL_ERRORS_TERSE = 1;                 // 简洁模式（仅 severity + message）
const PGSQL_ERRORS_DEFAULT = 2;               // 默认模式
const PGSQL_ERRORS_VERBOSE = 3;               // 详细模式

// ════════════════════════════════════════════════════════════
// pg_convert 的 options 参数
// ════════════════════════════════════════════════════════════
const PGSQL_CONV_IGNORE_DEFAULT = 2;          // 忽略默认值
const PGSQL_CONV_FORCE_NULL = 4;              // 空字符串转 NULL
const PGSQL_CONV_IGNORE_NOT_NULL = 8;         // 忽略 NOT NULL 约束

// ════════════════════════════════════════════════════════════
// pg_close 的 option 参数（PHP 8.4+）
// ════════════════════════════════════════════════════════════
const PGSQL_CLOSE_FORCE = 1;                  // 强制关闭（不发送 Terminate 消息）
const PGSQL_CLOSE_RESET = 2;                  // 重置连接状态后关闭
