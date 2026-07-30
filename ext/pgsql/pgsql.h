#pragma once
// ============================================================
// pgsql.h — PostgreSQL 扩展（纯 C 协议实现，不依赖 libpq）
//
// 设计目标：
//   - 纯 C 实现 PostgreSQL v3 协议（不依赖 libpq）
//   - 认证：trust / MD5 / SCRAM-SHA-256（内置 SHA-256/HMAC/PBKDF2）
//   - 协议：Extended Query（Parse/Bind/Execute）+ Simple Query
//   - Large Object 支持（lo_create/lo_open/lo_read/lo_write/lo_lseek/lo_close/lo_unlink）
//   - 持久连接池（pg_pconnect）
//   - 不支持 SSL/TLS、Unix socket（本期）
//
// 依赖：
//   - ext/stream 的跨平台 socket 抽象（stream.h，由 .php 文件 #include）
//   - ext/pgsql/pg_crypto.h（SHA-256/HMAC/PBKDF2/Base64/MD5，由 .php 文件 #include）
//
// 头文件包含顺序（由 .php 文件控制）：
//   1. stream/src/stream.h  — socket 跨平台抽象（STREAM_CLOSE/STREAM_ERRNO 等宏 + tphp_fn_stream_init）
//   2. pgsql/pgsql.h        — 本文件，协议常量 + 结构体 + 前向声明
//   3. pgsql/pg_crypto.h    — 密码学原语（SHA-256/HMAC/PBKDF2/Base64/MD5）
//   4. pgsql/pgsql_protocol.h — 协议实现（DSN 解析、消息收发、认证、连接函数）
//
// 内存安全：
//   - 所有 malloc 配对 free
//   - 消息读取循环 recv 直到读够
//   - PGresult 缓存所有行数据，支持随机访问
// ============================================================

#include "types.h"
#include <stdint.h>
#include <stdlib.h>
#include <string.h>
#include <stdio.h>

// ============================================================
// 协议版本
// ============================================================
#define PROTOCOL_VERSION       196608   // 3.0 << 16 = 0x00030000
#define PROTOCOL_MAJOR(v)      ((v) >> 16)
#define PROTOCOL_MINOR(v)      ((v) & 0xFFFF)

// CancelRequest 协议版本（特殊：11296 = 0x04D216 << 16 | 0x04D2）
#define CANCEL_REQUEST_CODE    80877102  // (1234 << 16) | 5678
#define SSL_REQUEST_CODE       80877103  // (1234 << 16) | 5679

// ============================================================
// 后端→前端 消息类型（1 字节 char）
// ============================================================
#define PG_MSG_AUTHENTICATION              'R'
#define PG_MSG_BACKEND_KEY_DATA            'K'
#define PG_MSG_READY_FOR_QUERY             'Z'
#define PG_MSG_ROW_DESCRIPTION             'T'
#define PG_MSG_DATA_ROW                    'D'
#define PG_MSG_COMMAND_COMPLETE            'C'
#define PG_MSG_EMPTY_QUERY_RESPONSE        'I'
#define PG_MSG_ERROR_RESPONSE              'E'
#define PG_MSG_NOTICE_RESPONSE             'N'
#define PG_MSG_PARAMETER_STATUS            'S'
#define PG_MSG_PARSE_COMPLETE              '1'
#define PG_MSG_BIND_COMPLETE               '2'
#define PG_MSG_CLOSE_COMPLETE              '3'
#define PG_MSG_NO_DATA                     'n'
#define PG_MSG_PORTAL_SUSPENDED            's'
#define PG_MSG_PARAMETER_DESCRIPTION       't'
#define PG_MSG_COPY_IN_RESPONSE            'G'
#define PG_MSG_COPY_OUT_RESPONSE           'H'
#define PG_MSG_COPY_DONE                   'c'
#define PG_MSG_COPY_DATA                   'd'
#define PG_MSG_COPY_BOTH_RESPONSE          'b'
#define PG_MSG_NOTIFICATION_RESPONSE       'A'
#define PG_MSG_BACKEND_PID                 'A'  // alias for NotificationResponse

// ============================================================
// 前端→后端 消息类型（1 字节 char）
// ============================================================
#define PG_MSG_QUERY                       'Q'
#define PG_MSG_PARSE                       'P'
#define PG_MSG_BIND                        'B'
#define PG_MSG_DESCRIBE                    'D'
#define PG_MSG_EXECUTE                     'E'
#define PG_MSG_SYNC                        'S'
#define PG_MSG_TERMINATE                   'X'
#define PG_MSG_CLOSE                       'C'
#define PG_MSG_FLUSH                       'H'
#define PG_MSG_COPY_DATA_FE                'd'
#define PG_MSG_COPY_DONE_FE                'c'
#define PG_MSG_COPY_FAIL                   'f'
#define PG_MSG_PASSWORD                    'p'
#define PG_MSG_FUNCTION_CALL               'F'

// ============================================================
// 认证类型常量（Authentication 消息 payload 前 4 字节 BE）
// ============================================================
#define AUTH_OK                0
#define AUTH_KRB4              1
#define AUTH_KRB5              2
#define AUTH_PASSWORD          3
#define AUTH_CRYPT             4
#define AUTH_MD5               5
#define AUTH_SCM_CREDS         6
#define AUTH_GSS               7
#define AUTH_GSS_CONTINUE      8
#define AUTH_SSPI              9
#define AUTH_SASL              10
#define AUTH_SASL_CONTINUE     11
#define AUTH_SASL_FINAL        12

// ============================================================
// OID 类型常量（PostgreSQL 内置类型）
// ============================================================
#define OID_BOOL        16
#define OID_BYTEA       17
#define OID_INT8        20
#define OID_INT2        21
#define OID_INT4        23
#define OID_TEXT        25
#define OID_OID         26
#define OID_FLOAT4      700
#define OID_FLOAT8      701
#define OID_VARCHAR     1043
#define OID_DATE        1082
#define OID_TIMESTAMP   1114
#define OID_NUMERIC     1700

// ============================================================
// 事务状态（ReadyForQuery 消息的 1 字节 payload）
// ============================================================
#define TRANS_IDLE      'I'   // 当前不在事务中
#define TRANS_INTRANS   'T'   // 在事务块中（空闲）
#define TRANS_INERROR   'E'   // 在中止的事务块中

// ============================================================
// 连接状态
// ============================================================
#define CONN_OK                 0
#define CONN_BAD                1
#define CONN_STARTED            2
#define CONN_MADE               3
#define CONN_AWAITING_RESPONSE  4
#define CONN_AUTH_OK            5
#define CONN_SETENV             6

// ============================================================
// ExecStatusType（PGresult.status）
// ============================================================
#define PGRES_EMPTY_QUERY       0
#define PGRES_COMMAND_OK        1
#define PGRES_TUPLES_OK         2
#define PGRES_COPY_OUT          3
#define PGRES_COPY_IN           4
#define PGRES_BAD_RESPONSE      5
#define PGRES_NONFATAL_ERROR    6
#define PGRES_FATAL_ERROR       7

// ============================================================
// Large Object 打开模式
// ============================================================
#define INV_READ        0x00040000
#define INV_WRITE       0x00020000

// ============================================================
// 持久连接池大小
// ============================================================
#define PG_PCONN_POOL_SIZE  32

// ============================================================
// 结构体定义
// ============================================================

// 字段元数据（RowDescription 解析结果）
typedef struct {
    char     name[64];      // 字段名
    uint32_t table_oid;     // 所属表 OID
    uint16_t column_num;    // 列号
    uint32_t type_oid;      // 类型 OID
    int16_t  type_size;     // 类型大小（-1=变长）
    int32_t  type_mod;      // 类型修饰符
    int16_t  format;        // 0=text, 1=binary
} pg_field_meta;

// PGresult 结构体（缓存所有行数据，支持随机访问）
typedef struct {
    pg_field_meta *fields;       // 字段元数据数组
    int            num_fields;   // 字段数
    char         **rows;         // 行数据二维数组（每行是 char* 数组）
    int           *row_lens;     // 各行各列长度二维数组（-1=NULL）
    int            num_rows;     // 行数
    int            cap_rows;     // 行容量
    int            cur_row;      // 当前行指针（pg_fetch_* 用）
    int            affected;     // 影响行数
    uint32_t       last_oid;     // 最后插入 OID
    int            status;       // ExecStatusType
    char           cmd_tag[64];  // 命令 tag
    char          *err_msg;      // 错误消息（status=FATAL_ERROR 时）
    char          *err_data;     // 原始 ErrorResponse payload（pg_result_error_field 用）
    int            err_data_len; // err_data 长度
} PGresult;

// PGconn 前向声明（pg_lo_handle 引用 PGconn*，需在定义前声明）
typedef struct PGconn PGconn;

// Large Object handle
typedef struct {
    int      fd;         // lo fd（模拟文件描述符）
    uint32_t oid;        // OID
    int      mode;       // 打开模式（INV_READ/INV_WRITE）
    int      pos;        // 当前位置
    PGconn  *conn;       // 所属连接（析构回调执行 lo_close 用）
} pg_lo_handle;

// PGconn 结构体
typedef struct PGconn {
    int            sock;             // socket fd
    int            status;           // 连接状态（CONN_*）
    char          *host;
    int            port;
    char          *dbname;
    char          *user;
    char          *password;
    char          *options;
    char          *tty;
    int            connect_timeout;
    char          *sslmode;          // 仅解析不实现
    int            backend_pid;      // 后端 PID
    int            backend_key;      // 后端密钥（CancelRequest 用）
    char           trans_status;     // 事务状态 I/T/E
    char          *last_error;       // 最后错误消息
    char          *last_notice;      // 最后通知消息
    char          *client_encoding;
    char          *server_version;
    int            std_conforming_strings;  // standard_conforming_strings 参数
    t_callback     notice_cb;        // 通知回调（NULL=未注册）
    int            is_persistent;    // 是否为持久连接（pconnect 创建）
    // Large Object fd 表
    pg_lo_handle   lo_handles[64];   // 最多 64 个并发 lo
    int            lo_count;
} PGconn;

// 持久连接池条目
typedef struct {
    PGconn  *conn;       // 连接指针
    char    *dsn;        // DSN 字符串（用于匹配）
    uint64_t dsn_hash;   // DSN 哈希
    int      in_use;     // 是否正在使用
} pg_pconn_slot;

// ============================================================
// 内部协议函数前向声明（实现在 pgsql_protocol.h）
// ============================================================

// DSN 解析
static int _pg_parse_dsn(const char *dsn, PGconn *conn);

// 消息收发框架
static int  _pg_send_message(PGconn *conn, char type, const void *data, int len);
static char _pg_recv_message(PGconn *conn, char **data, int *len);
static int  _pg_recv_exact(PGconn *conn, void *buf, int n);
static int  _pg_send_startup(PGconn *conn);
static int  _pg_send_all(int fd, const char *data, int len);

// 认证流程
static int _pg_do_auth(PGconn *conn);
static int _pg_auth_trust(PGconn *conn);
static int _pg_auth_md5(PGconn *conn, const char *salt);
static int _pg_auth_scram_sha256(PGconn *conn, const char *server_first, int len);

// ErrorResponse 处理
static int _pg_parse_error(PGconn *conn, const char *data, int len,
                           char **severity, char **code, char **message,
                           char **detail, char **hint);

// 连接生命周期
static int _pg_connect_socket(PGconn *conn);
static void _pg_free_conn(PGconn *conn);
static void _pg_set_error(PGconn *conn, const char *msg);
static int _pg_consume_startup_messages(PGconn *conn);

// ============================================================
// PHP 层暴露的 C 包装函数前向声明（_pg_* 命名）
// 实现在 pgsql_protocol.h，PHP 层声明在 src/pgsql.php（后续 Task 10）
// ============================================================

// 连接管理
t_int  _pg_connect(t_string dsn);
t_int  _pg_pconnect(t_string dsn, t_int flags);
t_bool _pg_close(t_int conn_handle, t_int close_flags);
t_int  _pg_connection_status(t_int conn_handle);
t_bool _pg_ping(t_int conn_handle);
t_bool _pg_connection_reset(t_int conn_handle);

// Large Object（实现在 pgsql_lo.h，Task 9.5）
t_int    _pg_lo_create(t_int conn_handle);
t_int    _pg_lo_open(t_int conn_handle, t_int oid, t_string mode);
t_string _pg_lo_read(t_int conn_handle, t_int lob_handle, t_int len);
t_int    _pg_lo_write(t_int conn_handle, t_int lob_handle, t_string data);
t_int    _pg_lo_seek(t_int conn_handle, t_int lob_handle, t_int offset, t_int whence);
t_int    _pg_lo_tell(t_int conn_handle, t_int lob_handle);
t_bool   _pg_lo_truncate(t_int conn_handle, t_int lob_handle, t_int len);
void     _pg_lo_close(t_int conn_handle, t_int lob_handle);
t_bool   _pg_lo_unlink(t_int conn_handle, t_int oid);
t_int    _pg_lo_import(t_int conn_handle, t_string filename);
t_bool   _pg_lo_export(t_int conn_handle, t_int oid, t_string filename);
t_string _pg_lo_read_all(t_int conn_handle, t_int lob_handle);

// 通知回调
void _pg_set_notice_callback(t_int conn_handle, t_callback callback);

// 查询与预处理（实现在 pgsql_query.h）
t_int  _pg_query(t_int conn_handle, t_string sql);
t_int  _pg_query_params(t_int conn_handle, t_string sql, t_array *params);
t_int  _pg_prepare(t_int conn_handle, t_string stmt_name, t_string sql);
t_int  _pg_execute(t_int conn_handle, t_string stmt_name, t_array *params);
void   _pg_free_result(t_int result_handle);

// 指针 ↔ t_int 转换辅助宏
#define _PG_CONN_FROM_INT(v)    ((PGconn*)(intptr_t)(v))
#define _PG_CONN_TO_INT(p)      ((t_int)(intptr_t)(p))
#define _PG_RESULT_FROM_INT(v)  ((PGresult*)(intptr_t)(v))
#define _PG_RESULT_TO_INT(p)    ((t_int)(intptr_t)(p))

// ============================================================
// 内部函数前向声明（实现在 pgsql_pconnect.h / pgsql_notice.h，Task 9.6/9.7）
// ============================================================

// 持久连接池（实现在 pgsql_pconnect.h）
static t_int  _pg_pconnect_real(t_string dsn, t_int flags);
static void   _pg_close_real(PGconn *conn, t_int close_flags);
static void   _pg_pconn_pool_cleanup(void);

// 通知回调调用（实现在 pgsql_notice.h）
static void _pg_invoke_notice_cb(PGconn *conn, const char *msg, int len);
