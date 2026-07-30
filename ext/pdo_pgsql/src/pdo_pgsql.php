<?php
// ext/pdo_pgsql/src/pdo_pgsql.php — PostgreSQL PDO 驱动（纯 C 协议实现）
//
// 设计说明：
//   - 复用 ext/pgsql 的纯 C PostgreSQL v3 协议实现（不依赖 libpq）
//   - 通过 pdo_driver_t 接口暴露给 PDO 框架
//   - 用户通过 new PDO("pgsql:host=...;port=...;dbname=...") 使用
//   - DSN 前缀 "pgsql:" 匹配（pdo_find_driver 按 drv->name 查找）
//   - 认证：trust / MD5 / SCRAM-SHA-256（由 ext/pgsql 提供）
//   - 协议：Extended Query（Parse/Bind/Execute）+ Simple Query
//   - 参数化查询：将 PDO 的 ? 占位符转换为 PostgreSQL 的 $N 占位符
//   - 不支持 SSL/TLS、Unix socket（由 ext/pgsql 限制）
//
// Pdo\Pgsql 类说明：
//   TinyPHP 的 AOT 模型中 PDO 基类位于全局命名空间，跨命名空间继承
//   （namespace Pdo; class Pgsql extends \PDO）受限于解析器不支持前导 \。
//   因此 Pdo\Pgsql 驱动通过 pdo_driver_t 函数指针表实现，用户直接使用
//   new PDO("pgsql:...") 即可。PostgreSQL 专属功能通过本文件提供的
//   PHP 层包装函数（pdo_pgsql_get_pid / pdo_pgsql_get_notify）访问，
//   传入 PDO 实例的 $db 属性（dbh 句柄）即可调用。
//
// 依赖：ext/stream（socket 跨平台抽象）+ ext/pgsql（PostgreSQL 协议）+ ext/pdo（PDO 框架）
//   - 本文件通过 #include 直接引入所有头文件，无需用户额外 #import stream/pgsql
//   - Windows 链接 ws2_32 由本文件 #flag windows -lws2_32 提供
//
// 包含顺序（CodeGenerator 保证 ext/ 路径的头文件放在 common.h 之后）：
//   1. stream/src/stream.h        — socket 跨平台抽象（STREAM_CLOSE/STREAM_ERRNO 等宏）
//   2. pgsql/pgsql.h              — 协议常量 + PGconn/PGresult 结构体 + 前向声明
//   3. pgsql/pg_crypto.h          — SHA-256/HMAC/PBKDF2/Base64/MD5 密码学原语
//   4. pgsql/pgsql_protocol.h     — DSN 解析、消息收发、认证流程、连接函数实现
//   5. pgsql/pgsql_query.h        — 简单查询 + 扩展查询协议实现（_pg_exec_query 等）
//   6. pgsql/pgsql_result.h       — 结果集访问函数（_pg_mk_str / _pg_result_free）
//   7. pgsql/pgsql_misc.h         — 连接信息 + 转义函数（_pg_quote_ident 等）
//   8. pgsql/pgsql_copy.h         — COPY 协议函数（_pg_quote_literal）
//   9. pgsql/pgsql_lo.h           — Large Object 函数
//  10. pgsql/pgsql_pconnect.h     — 持久连接池（_pg_close_real）
//  11. pgsql/pgsql_notice.h       — 通知回调
//  12. pdo/pdo_driver.h           — pdo_driver_t 接口定义 + pdo_register_driver
//  13. pdo_pgsql/pdo_pgsql.h      — PostgreSQL PDO 驱动实现 + driver 注册

// Windows 需要 winsock2 库（socket/connect/recv/send 等符号）
#flag windows -lws2_32

// 引入 stream.h（提供 STREAM_CLOSE/STREAM_ERRNO 等宏 + tphp_fn_stream_init）
#include __EXT__ . "stream/src/stream.h"

// 引入 pgsql.h（协议常量 + PGconn/PGresult 结构体 + 前向声明）
#include __EXT__ . "pgsql/pgsql.h"

// 引入 pg_crypto.h（SHA-256/HMAC-SHA-256/PBKDF2/Base64/MD5 密码学原语）
#include __EXT__ . "pgsql/pg_crypto.h"

// 引入 pgsql_protocol.h（DSN 解析、消息收发、认证流程、连接函数实现）
#include __EXT__ . "pgsql/pgsql_protocol.h"

// 引入 pgsql_query.h（简单查询 + 扩展查询协议实现）
#include __EXT__ . "pgsql/pgsql_query.h"

// 引入 pgsql_result.h（结果集访问函数 — _pg_mk_str / _pg_result_free）
#include __EXT__ . "pgsql/pgsql_result.h"

// 引入 pgsql_misc.h（连接信息 + 转义函数）
#include __EXT__ . "pgsql/pgsql_misc.h"

// 引入 pgsql_copy.h（COPY 协议函数 — 定义 _pg_quote_literal）
#include __EXT__ . "pgsql/pgsql_copy.h"

// 引入 pgsql_lo.h（Large Object 协议函数）
#include __EXT__ . "pgsql/pgsql_lo.h"

// 引入 pgsql_pconnect.h（持久连接池 — _pg_close_real）
#include __EXT__ . "pgsql/pgsql_pconnect.h"

// 引入 pgsql_notice.h（通知回调）
#include __EXT__ . "pgsql/pgsql_notice.h"

// 引入 pdo_driver.h（pdo_driver_t 接口 + pdo_register_driver/pdo_find_driver）
#include __EXT__ . "pdo/pdo_driver.h"

// 引入 pdo_pgsql.h（PostgreSQL PDO 驱动实现 + driver 注册）
#include __EXT__ . "pdo_pgsql/pdo_pgsql.h"

// ════════════════════════════════════════════════════════════
// Pdo\Pgsql — PostgreSQL 专属功能（PHP 层包装函数）
//
// 设计原则：
//   1. 所有参数/返回值用 tphp 具体类型（int/string/array）
//   2. 指针以 int 存储，用 c_int() / php_int() 转换
//   3. 错误由 C 层 tp_throw 抛出，PHP 层不重复检查
//   4. dbh 参数为 PDO 实例的 $db 属性（pdo_driver_open 返回的句柄）
//
// 用法示例：
//   $db = new PDO("pgsql:host=127.0.0.1;dbname=test", "postgres", "pass");
//   $pid = pdo_pgsql_get_pid($db->db);
//   $notify = pdo_pgsql_get_notify($db->db);
// ════════════════════════════════════════════════════════════

// ── pdo_pgsql_get_pid: 获取后端进程 PID ──
//   参数 $dbh: PDO 实例的 $db 属性（pdo_driver_open 返回的 dbh 句柄）
//   返回: PostgreSQL 后端进程 PID（>0），无效句柄返回 0
function pdo_pgsql_get_pid(int $dbh): int
{
    return php_int(_pgpdo_get_pid(c_int($dbh)));
}

// ── pdo_pgsql_get_notify: 获取异步通知 ──
//   参数 $dbh: PDO 实例的 $db 属性
//   参数 $result_type: 结果数组类型（PGSQL_ASSOC=1 关联, PGSQL_NUM=2 索引, PGSQL_BOTH=3 两者）
//   参数 $timeout_ms: 等待超时（毫秒），0 表示不等待
//   返回: 包含 [pid, channel, message] 的数组；无通知返回空数组
//   注意: 当前实现返回空数组（LISTEN/NOTIFY 支持需要协议层修改）
function pdo_pgsql_get_notify(int $dbh, int $result_type = 1, int $timeout_ms = 0): array
{
    $arr = _pgpdo_get_notify(c_int($dbh), c_int($result_type), c_int($timeout_ms));
    if ($arr == 0) {
        return [];
    }
    return $arr;
}

// ── pdo_pgsql_pgconn: 从 PDO dbh 提取底层 PGconn 句柄 ──
//   参数 $dbh: PDO 实例的 $db 属性
//   返回: PGconn 指针的 int 表示（可用于 ext/pgsql 的 pg_* 函数），无效返回 0
//   用途: 允许在 PDO 连接上直接调用 ext/pgsql 的低层函数
function pdo_pgsql_pgconn(int $dbh): int
{
    return php_int(_pgpdo_pgconn(c_int($dbh)));
}
