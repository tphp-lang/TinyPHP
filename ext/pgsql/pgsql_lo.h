#pragma once
// ============================================================
// pgsql_lo.h — PostgreSQL Large Object 实现（Task 9.5）
//
// 实现方式：
//   通过扩展查询协议发送 "SELECT lo_xxx()" SQL，从结果集解析返回值。
//   无需实现 FunctionCall（'F'）协议。
//
// 实现函数：
//   9.5.1  pg_lo_create    — SELECT lo_create(0)，返回 OID
//   9.5.2  pg_lo_open      — SELECT lo_open($oid, $mode)，返回 Resource
//   9.5.3  pg_lo_read      — SELECT loread($fd, $len)，返回字符串
//   9.5.4  pg_lo_write     — SELECT lowrite($fd, $data)，返回写入字节数
//   9.5.5  pg_lo_seek      — SELECT lo_lseek($fd, $offset, $whence)，返回新位置
//   9.5.6  pg_lo_tell      — SELECT lo_tell($fd)，返回位置
//   9.5.7  pg_lo_truncate  — SELECT lo_truncate($fd, $len)
//   9.5.8  pg_lo_close     — SELECT lo_close($fd)，释放 Resource
//   9.5.9  pg_lo_unlink    — SELECT lo_unlink($oid)
//   9.5.10 pg_lo_import    — SELECT lo_import($filename)，返回 OID
//   9.5.11 pg_lo_export    — SELECT lo_export($oid, $filename)
//   9.5.12 pg_lo_read_all  — 循环 pg_lo_read 直到 EOF
//
// Resource 管理：
//   - pg_lo_open 返回的 Resource 注册到全局资源列表（tphp_rt_resource_insert）
//   - pg_lo_close 调用 tphp_rt_resource_delete 释放
//   - 析构回调 _pg_lo_dtor 兜底（异常路径：未显式关闭的 LO 在资源释放时关闭）
//
// 依赖：
//   - pgsql.h（结构体 + 常量 + 前向声明）
//   - pgsql_protocol.h（_pg_exec_query / _pg_result_free / _pg_set_error）
//   - pgsql_query.h（_pg_exec_query / _pg_result_free）
//   - pgsql_result.h（_pg_mk_str / _pg_mk_str_n）
//   - pgsql_misc.h（_pg_unescape_bytea / _pg_escape_bytea）
//   - pgsql_copy.h（_pg_quote_literal）
//   - include/object/resource.h（tphp_rt_resource_insert / delete / fetch / register_resource_type）
// ============================================================

#include "types.h"
#include "object/exception.h"
#include "object/try.h"
#include "object/resource.h"
#include "val.h"
#include <stdint.h>
#include <stdlib.h>
#include <string.h>
#include <stdio.h>

// ============================================================
// 内部辅助：Resource 类型注册（懒初始化）
// ============================================================

static int _pg_lo_rsrc_type = -1;

// 前向声明析构回调（定义在下方）
static void _pg_lo_dtor(void *ptr);

// 确保资源类型已注册（首次调用时注册，后续调用直接返回）
static int _pg_lo_ensure_rsrc_type(void) {
    if (_pg_lo_rsrc_type >= 0) return _pg_lo_rsrc_type;
    _pg_lo_rsrc_type = (int)tphp_rt_register_resource_type(_pg_lo_dtor, "pg_lo");
    return _pg_lo_rsrc_type;
}

// ============================================================
// 内部辅助：执行 LO 查询并检查错误
//   成功返回 PGresult*（调用者负责 _pg_result_free），失败 tp_throw 并返回 NULL
// ============================================================
static PGresult* _pg_lo_exec(PGconn *conn, const char *sql) {
    if (conn == NULL || sql == NULL) {
        tp_throw("pg_lo: invalid argument");
        return NULL;
    }
    PGresult *res = _pg_exec_query(conn, sql);
    if (res == NULL) {
        tp_throw(conn->last_error ? conn->last_error : "pg_lo: query failed");
        return NULL;
    }
    if (res->status == PGRES_FATAL_ERROR) {
        const char *emsg = res->err_msg ? res->err_msg
                                        : (conn->last_error ? conn->last_error : "pg_lo: query failed");
        tp_throw(emsg);
        _pg_result_free(res);
        return NULL;
    }
    return res;
}

// 从 PGresult 取第一行第一列的整数值
static t_int _pg_lo_get_int(PGresult *res) {
    if (res == NULL) return -1;
    if (res->status != PGRES_TUPLES_OK) return -1;
    if (res->num_rows < 1 || res->num_fields < 1) return -1;
    if (res->rows == NULL || res->rows[0] == NULL) return -1;
    return (t_int)strtoll(res->rows[0], NULL, 10);
}

// ============================================================
// Resource 析构回调
//   ptr 是 pg_lo_handle*
//   如果 fd >= 0（未显式关闭），执行 lo_close SQL 兜底
//   然后释放 handle
// ============================================================
static void _pg_lo_dtor(void *ptr) {
    pg_lo_handle *handle = (pg_lo_handle*)ptr;
    if (handle == NULL) return;

    // 兜底：如果 LO 未显式关闭，执行 lo_close SQL
    if (handle->fd >= 0 && handle->conn != NULL) {
        if (handle->conn->sock >= 0) {
            char sql[64];
            snprintf(sql, sizeof(sql), "SELECT lo_close(%d)", handle->fd);
            PGresult *res = _pg_exec_query(handle->conn, sql);
            if (res != NULL) _pg_result_free(res);
        }
        handle->fd = -1;
    }

    free(handle);
}

// ============================================================
// 9.5.1 pg_lo_create — 创建大对象，返回 OID
//   执行 SELECT lo_create(0)，从结果集第一行第一列解析 OID
// ============================================================
t_int _pg_lo_create(t_int conn_handle) {
    PGconn *conn = _PG_CONN_FROM_INT(conn_handle);
    if (conn == NULL) {
        tp_throw("pg_lo_create: invalid connection handle");
        return 0;
    }

    PGresult *res = _pg_lo_exec(conn, "SELECT lo_create(0)");
    if (res == NULL) return 0;

    t_int oid = _pg_lo_get_int(res);
    _pg_result_free(res);

    if (oid < 0) {
        tp_throw("pg_lo_create: failed to parse OID from result");
        return 0;
    }
    return oid;
}

// ============================================================
// 9.5.2 pg_lo_open — 打开大对象，返回 Resource
//   执行 SELECT lo_open($oid, $mode)
//   mode: "r"=INV_READ, "w"=INV_WRITE, "rw"=INV_READ|INV_WRITE
//   返回 Resource handle（t_int），ptr 存 pg_lo_handle*
// ============================================================
t_int _pg_lo_open(t_int conn_handle, t_int oid, t_string mode) {
    PGconn *conn = _PG_CONN_FROM_INT(conn_handle);
    if (conn == NULL) {
        tp_throw("pg_lo_open: invalid connection handle");
        return -1;
    }

    // 解析 mode 字符串 → INV_READ/INV_WRITE 位掩码
    const char *m = STR_PTR(mode);
    int mode_flags = 0;
    if (m != NULL) {
        for (int i = 0; i < mode.length; i++) {
            if (m[i] == 'r') mode_flags |= INV_READ;
            else if (m[i] == 'w') mode_flags |= INV_WRITE;
        }
    }
    if (mode_flags == 0) mode_flags = INV_READ;  // 默认只读

    // 构建 SQL: SELECT lo_open($oid, $mode)
    char sql[128];
    int n = snprintf(sql, sizeof(sql), "SELECT lo_open(%lld, %d)",
                     (long long)oid, mode_flags);
    if (n < 0 || n >= (int)sizeof(sql)) {
        tp_throw("pg_lo_open: SQL too long");
        return -1;
    }

    PGresult *res = _pg_lo_exec(conn, sql);
    if (res == NULL) return -1;

    t_int fd = _pg_lo_get_int(res);
    _pg_result_free(res);

    if (fd < 0) {
        tp_throw("pg_lo_open: failed to parse fd from result");
        return -1;
    }

    // 创建 pg_lo_handle
    pg_lo_handle *handle = (pg_lo_handle*)malloc(sizeof(pg_lo_handle));
    if (handle == NULL) {
        tp_throw("pg_lo_open: out of memory");
        return -1;
    }
    handle->fd = (int)fd;
    handle->oid = (uint32_t)oid;
    handle->mode = mode_flags;
    handle->pos = 0;
    handle->conn = conn;

    // 创建 Resource 并注册
    int rsrc_type = _pg_lo_ensure_rsrc_type();
    tphp_class_Resource *rsrc = new_tphp_class_Resource();
    if (rsrc == NULL) {
        free(handle);
        tp_throw("pg_lo_open: out of memory (resource)");
        return -1;
    }
    rsrc->type = rsrc_type;
    rsrc->ptr = handle;

    t_int rsrc_handle = tphp_rt_resource_insert(rsrc);
    if (rsrc_handle < 0) {
        free(handle);
        tp_throw("pg_lo_open: resource list full");
        return -1;
    }

    return rsrc_handle;
}

// ============================================================
// 内部辅助：从 lob_handle 取 pg_lo_handle*
//   失败 tp_throw 并返回 NULL
// ============================================================
static pg_lo_handle* _pg_lo_fetch(t_int lob_handle) {
    tphp_class_Resource *rsrc = tphp_rt_resource_fetch(lob_handle);
    if (rsrc == NULL) {
        tp_throw("pg_lo: invalid large object handle");
        return NULL;
    }
    pg_lo_handle *handle = (pg_lo_handle*)rsrc->ptr;
    if (handle == NULL) {
        tp_throw("pg_lo: large object handle has null ptr");
        return NULL;
    }
    return handle;
}

// ============================================================
// 9.5.3 pg_lo_read — 读取数据
//   执行 SELECT loread($fd, $len)，返回字符串
//   结果为 bytea hex 格式（"\x..."），需解码
// ============================================================
t_string _pg_lo_read(t_int conn_handle, t_int lob_handle, t_int len) {
    PGconn *conn = _PG_CONN_FROM_INT(conn_handle);
    if (conn == NULL) {
        tp_throw("pg_lo_read: invalid connection handle");
        return (t_string){0};
    }
    pg_lo_handle *handle = _pg_lo_fetch(lob_handle);
    if (handle == NULL) return (t_string){0};

    if (len <= 0) return (t_string){0};

    // 构建 SQL: SELECT loread($fd, $len)
    char sql[128];
    int n = snprintf(sql, sizeof(sql), "SELECT loread(%d, %lld)",
                     handle->fd, (long long)len);
    if (n < 0 || n >= (int)sizeof(sql)) {
        tp_throw("pg_lo_read: SQL too long");
        return (t_string){0};
    }

    PGresult *res = _pg_lo_exec(conn, sql);
    if (res == NULL) return (t_string){0};

    // 检查结果
    t_string result = (t_string){0};
    if (res->status == PGRES_TUPLES_OK && res->num_rows >= 1 &&
        res->num_fields >= 1 && res->rows != NULL && res->rows[0] != NULL) {
        // loread 返回 bytea，文本模式下为 "\x..." hex 编码
        t_string raw = _pg_mk_str_n(res->rows[0], res->row_lens[0]);
        result = _pg_unescape_bytea(raw);
        // 更新当前位置
        handle->pos += result.length;
    } else {
        tp_throw("pg_lo_read: no data in result");
    }

    _pg_result_free(res);
    return result;
}

// ============================================================
// 9.5.4 pg_lo_write — 写入数据
//   执行 SELECT lowrite($fd, $data)，返回写入字节数
//   data 为二进制数据，需 bytea hex 转义
// ============================================================
t_int _pg_lo_write(t_int conn_handle, t_int lob_handle, t_string data) {
    PGconn *conn = _PG_CONN_FROM_INT(conn_handle);
    if (conn == NULL) {
        tp_throw("pg_lo_write: invalid connection handle");
        return -1;
    }
    pg_lo_handle *handle = _pg_lo_fetch(lob_handle);
    if (handle == NULL) return -1;

    const char *data_ptr = STR_PTR(data);
    int data_len = data.length;
    if (data_ptr == NULL || data_len <= 0) {
        return 0;  // 空数据，写入 0 字节
    }

    // bytea hex 转义："\x" + hex(data)
    // 输出长度 = 2 + 2*data_len
    int escaped_cap = 2 + 2 * data_len + 1;
    char *escaped = (char*)malloc((size_t)escaped_cap);
    if (escaped == NULL) {
        tp_throw("pg_lo_write: out of memory (escape)");
        return -1;
    }
    escaped[0] = '\\';
    escaped[1] = 'x';
    static const char hex_chars[] = "0123456789abcdef";
    for (int i = 0; i < data_len; i++) {
        unsigned char b = (unsigned char)data_ptr[i];
        escaped[2 + 2*i]     = hex_chars[b >> 4];
        escaped[2 + 2*i + 1] = hex_chars[b & 0x0F];
    }
    escaped[2 + 2*data_len] = '\0';
    int escaped_len = 2 + 2 * data_len;

    // 构建 SQL: SELECT lowrite($fd, '\x...'::bytea)
    // SQL 长度 = 固定部分 + escaped_len + 引号等
    int sql_cap = 64 + escaped_len + 4;
    char *sql = (char*)malloc((size_t)sql_cap);
    if (sql == NULL) {
        free(escaped);
        tp_throw("pg_lo_write: out of memory (sql)");
        return -1;
    }
    int n = snprintf(sql, (size_t)sql_cap, "SELECT lowrite(%d, '%s'::bytea)",
                     handle->fd, escaped);
    free(escaped);
    if (n < 0 || n >= sql_cap) {
        free(sql);
        tp_throw("pg_lo_write: SQL too long");
        return -1;
    }

    PGresult *res = _pg_lo_exec(conn, sql);
    free(sql);
    if (res == NULL) return -1;

    t_int written = _pg_lo_get_int(res);
    _pg_result_free(res);

    if (written >= 0) {
        handle->pos += (int)written;
    }
    return written;
}

// ============================================================
// 9.5.5 pg_lo_seek — 移动指针
//   执行 SELECT lo_lseek($fd, $offset, $whence)，返回新位置
//   whence: PGSQL_SEEK_SET=0, PGSQL_SEEK_CUR=1, PGSQL_SEEK_END=2
// ============================================================
t_int _pg_lo_seek(t_int conn_handle, t_int lob_handle, t_int offset, t_int whence) {
    PGconn *conn = _PG_CONN_FROM_INT(conn_handle);
    if (conn == NULL) {
        tp_throw("pg_lo_seek: invalid connection handle");
        return -1;
    }
    pg_lo_handle *handle = _pg_lo_fetch(lob_handle);
    if (handle == NULL) return -1;

    char sql[128];
    int n = snprintf(sql, sizeof(sql), "SELECT lo_lseek(%d, %lld, %lld)",
                     handle->fd, (long long)offset, (long long)whence);
    if (n < 0 || n >= (int)sizeof(sql)) {
        tp_throw("pg_lo_seek: SQL too long");
        return -1;
    }

    PGresult *res = _pg_lo_exec(conn, sql);
    if (res == NULL) return -1;

    t_int new_pos = _pg_lo_get_int(res);
    _pg_result_free(res);

    if (new_pos >= 0) {
        handle->pos = (int)new_pos;
    }
    return new_pos;
}

// ============================================================
// 9.5.6 pg_lo_tell — 当前位置
//   执行 SELECT lo_tell($fd)，返回位置
// ============================================================
t_int _pg_lo_tell(t_int conn_handle, t_int lob_handle) {
    PGconn *conn = _PG_CONN_FROM_INT(conn_handle);
    if (conn == NULL) {
        tp_throw("pg_lo_tell: invalid connection handle");
        return -1;
    }
    pg_lo_handle *handle = _pg_lo_fetch(lob_handle);
    if (handle == NULL) return -1;

    char sql[64];
    snprintf(sql, sizeof(sql), "SELECT lo_tell(%d)", handle->fd);

    PGresult *res = _pg_lo_exec(conn, sql);
    if (res == NULL) return -1;

    t_int pos = _pg_lo_get_int(res);
    _pg_result_free(res);

    if (pos >= 0) {
        handle->pos = (int)pos;
    }
    return pos;
}

// ============================================================
// 9.5.7 pg_lo_truncate — 截断
//   执行 SELECT lo_truncate($fd, $len)
// ============================================================
t_bool _pg_lo_truncate(t_int conn_handle, t_int lob_handle, t_int len) {
    PGconn *conn = _PG_CONN_FROM_INT(conn_handle);
    if (conn == NULL) {
        tp_throw("pg_lo_truncate: invalid connection handle");
        return false;
    }
    pg_lo_handle *handle = _pg_lo_fetch(lob_handle);
    if (handle == NULL) return false;

    char sql[128];
    int n = snprintf(sql, sizeof(sql), "SELECT lo_truncate(%d, %lld)",
                     handle->fd, (long long)len);
    if (n < 0 || n >= (int)sizeof(sql)) {
        tp_throw("pg_lo_truncate: SQL too long");
        return false;
    }

    PGresult *res = _pg_lo_exec(conn, sql);
    if (res == NULL) return false;

    t_int rc = _pg_lo_get_int(res);
    _pg_result_free(res);

    return (rc == 0) ? true : false;
}

// ============================================================
// 9.5.8 pg_lo_close — 关闭
//   执行 SELECT lo_close($fd)
//   从 Resource 列表移除（tphp_rt_resource_delete），释放 pg_lo_handle
// ============================================================
void _pg_lo_close(t_int conn_handle, t_int lob_handle) {
    PGconn *conn = _PG_CONN_FROM_INT(conn_handle);
    if (conn == NULL) {
        tp_throw("pg_lo_close: invalid connection handle");
        return;
    }
    tphp_class_Resource *rsrc = tphp_rt_resource_fetch(lob_handle);
    if (rsrc == NULL) {
        tp_throw("pg_lo_close: invalid large object handle");
        return;
    }
    pg_lo_handle *handle = (pg_lo_handle*)rsrc->ptr;
    if (handle == NULL) {
        tphp_rt_resource_delete(lob_handle);
        return;
    }

    // 执行 lo_close SQL（仅当 fd 有效且连接可用）
    if (handle->fd >= 0 && conn->sock >= 0) {
        char sql[64];
        snprintf(sql, sizeof(sql), "SELECT lo_close(%d)", handle->fd);
        PGresult *res = _pg_exec_query(conn, sql);
        if (res != NULL) _pg_result_free(res);
        handle->fd = -1;  // 标记已关闭，防止 dtor 重复执行
    }

    // 从资源列表移除（触发 dtor 释放 handle）
    tphp_rt_resource_delete(lob_handle);
}

// ============================================================
// 9.5.9 pg_lo_unlink — 删除大对象
//   执行 SELECT lo_unlink($oid)
// ============================================================
t_bool _pg_lo_unlink(t_int conn_handle, t_int oid) {
    PGconn *conn = _PG_CONN_FROM_INT(conn_handle);
    if (conn == NULL) {
        tp_throw("pg_lo_unlink: invalid connection handle");
        return false;
    }

    char sql[64];
    snprintf(sql, sizeof(sql), "SELECT lo_unlink(%lld)", (long long)oid);

    PGresult *res = _pg_lo_exec(conn, sql);
    if (res == NULL) return false;

    t_int rc = _pg_lo_get_int(res);
    _pg_result_free(res);

    return (rc == 1) ? true : false;
}

// ============================================================
// 9.5.10 pg_lo_import — 从客户端文件导入到大对象
//   客户端实现：读取本地文件 → lo_create → lo_open(w) → lo_write 分块 → lo_close
//   （不使用 SELECT lo_import() 服务端函数，因为该函数操作服务端文件系统）
//   返回 OID（成功），0（失败）
// ============================================================
t_int _pg_lo_import(t_int conn_handle, t_string filename) {
    PGconn *conn = _PG_CONN_FROM_INT(conn_handle);
    if (conn == NULL) {
        tp_throw("pg_lo_import: invalid connection handle");
        return 0;
    }
    const char *fname = STR_PTR(filename);
    if (fname == NULL || filename.length == 0) {
        tp_throw("pg_lo_import: empty filename");
        return 0;
    }

    // 1. 打开本地文件
    FILE *fp = fopen(fname, "rb");
    if (fp == NULL) {
        tp_throw("pg_lo_import: cannot open file");
        return 0;
    }

    // 2. 创建 LO
    PGresult *res = _pg_lo_exec(conn, "SELECT lo_create(0)");
    if (res == NULL) { fclose(fp); return 0; }
    t_int oid = _pg_lo_get_int(res);
    _pg_result_free(res);
    if (oid < 0) {
        fclose(fp);
        tp_throw("pg_lo_import: lo_create failed");
        return 0;
    }

    // 3. 打开 LO 写入
    char sql[128];
    snprintf(sql, sizeof(sql), "SELECT lo_open(%lld, %d)", (long long)oid, INV_WRITE);
    res = _pg_lo_exec(conn, sql);
    if (res == NULL) { fclose(fp); return 0; }
    t_int fd = _pg_lo_get_int(res);
    _pg_result_free(res);
    if (fd < 0) {
        fclose(fp);
        tp_throw("pg_lo_import: lo_open failed");
        return 0;
    }

    // 4. 读取文件并写入 LO（分块）
    char buf[8192];
    static const char hex_chars[] = "0123456789abcdef";
    while (1) {
        size_t nread = fread(buf, 1, sizeof(buf), fp);
        if (nread == 0) break;

        // bytea hex 转义：'\x' + hex(data)
        int escaped_len = 2 + 2 * (int)nread;
        char *escaped = (char*)malloc((size_t)escaped_len + 1);
        if (escaped == NULL) {
            fclose(fp);
            tp_throw("pg_lo_import: out of memory (escape)");
            return 0;
        }
        escaped[0] = '\\';
        escaped[1] = 'x';
        for (int i = 0; i < (int)nread; i++) {
            unsigned char b = (unsigned char)buf[i];
            escaped[2 + 2 * i]     = hex_chars[b >> 4];
            escaped[2 + 2 * i + 1] = hex_chars[b & 0x0F];
        }
        escaped[escaped_len] = '\0';

        int sql_cap = 64 + escaped_len + 4;
        char *sql2 = (char*)malloc((size_t)sql_cap);
        if (sql2 == NULL) {
            free(escaped);
            fclose(fp);
            tp_throw("pg_lo_import: out of memory (sql)");
            return 0;
        }
        snprintf(sql2, (size_t)sql_cap, "SELECT lowrite(%d, '%s'::bytea)", (int)fd, escaped);
        free(escaped);

        PGresult *res2 = _pg_lo_exec(conn, sql2);
        free(sql2);
        if (res2 == NULL) { fclose(fp); return 0; }
        t_int written = _pg_lo_get_int(res2);
        _pg_result_free(res2);
        if (written < 0) {
            fclose(fp);
            tp_throw("pg_lo_import: lowrite failed");
            return 0;
        }
    }

    fclose(fp);

    // 5. 关闭 LO
    snprintf(sql, sizeof(sql), "SELECT lo_close(%d)", (int)fd);
    res = _pg_lo_exec(conn, sql);
    if (res != NULL) _pg_result_free(res);

    return oid;
}

// ============================================================
// 9.5.11 pg_lo_export — 从大对象导出到客户端文件
//   客户端实现：lo_open(r) → loread 分块 → 写入本地文件 → lo_close
//   （不使用 SELECT lo_export() 服务端函数，因为该函数操作服务端文件系统）
//   返回 true 成功，false 失败
// ============================================================
t_bool _pg_lo_export(t_int conn_handle, t_int oid, t_string filename) {
    PGconn *conn = _PG_CONN_FROM_INT(conn_handle);
    if (conn == NULL) {
        tp_throw("pg_lo_export: invalid connection handle");
        return false;
    }
    const char *fname = STR_PTR(filename);
    if (fname == NULL || filename.length == 0) {
        tp_throw("pg_lo_export: empty filename");
        return false;
    }

    // 1. 打开 LO 读取
    char sql[128];
    snprintf(sql, sizeof(sql), "SELECT lo_open(%lld, %d)", (long long)oid, INV_READ);
    PGresult *res = _pg_lo_exec(conn, sql);
    if (res == NULL) return false;
    t_int fd = _pg_lo_get_int(res);
    _pg_result_free(res);
    if (fd < 0) {
        tp_throw("pg_lo_export: lo_open failed");
        return false;
    }

    // 2. 打开本地文件写入
    FILE *fp = fopen(fname, "wb");
    if (fp == NULL) {
        snprintf(sql, sizeof(sql), "SELECT lo_close(%d)", (int)fd);
        res = _pg_lo_exec(conn, sql);
        if (res != NULL) _pg_result_free(res);
        tp_throw("pg_lo_export: cannot open file for writing");
        return false;
    }

    // 3. 循环读取 LO 并写入文件
    int chunk_size = 8192;
    while (1) {
        snprintf(sql, sizeof(sql), "SELECT loread(%d, %d)", (int)fd, chunk_size);
        res = _pg_lo_exec(conn, sql);
        if (res == NULL) { fclose(fp); return false; }

        if (res->status == PGRES_TUPLES_OK && res->num_rows >= 1 &&
            res->num_fields >= 1 && res->rows != NULL && res->rows[0] != NULL) {
            t_string raw = _pg_mk_str_n(res->rows[0], res->row_lens[0]);
            t_string data = _pg_unescape_bytea(raw);
            if (data.length > 0 && STR_PTR(data) != NULL) {
                fwrite(STR_PTR(data), 1, (size_t)data.length, fp);
            }
            _pg_result_free(res);
            if (data.length == 0) break;  // EOF
        } else {
            _pg_result_free(res);
            break;
        }
    }

    fclose(fp);

    // 4. 关闭 LO
    snprintf(sql, sizeof(sql), "SELECT lo_close(%d)", (int)fd);
    res = _pg_lo_exec(conn, sql);
    if (res != NULL) _pg_result_free(res);

    return true;
}

// ============================================================
// 9.5.12 pg_lo_read_all — 一次性读取全部
//   循环 pg_lo_read 直到 EOF（返回空字符串）
// ============================================================
t_string _pg_lo_read_all(t_int conn_handle, t_int lob_handle) {
    PGconn *conn = _PG_CONN_FROM_INT(conn_handle);
    if (conn == NULL) {
        tp_throw("pg_lo_read_all: invalid connection handle");
        return (t_string){0};
    }
    pg_lo_handle *handle = _pg_lo_fetch(lob_handle);
    if (handle == NULL) return (t_string){0};

    // 动态缓冲区累积读取数据
    int buf_cap = 8192;
    int buf_len = 0;
    char *buf = (char*)malloc((size_t)buf_cap);
    if (buf == NULL) {
        tp_throw("pg_lo_read_all: out of memory");
        return (t_string){0};
    }

    int chunk_size = 8192;
    while (1) {
        // 读取一块
        char sql[128];
        int n = snprintf(sql, sizeof(sql), "SELECT loread(%d, %d)",
                         handle->fd, chunk_size);
        if (n < 0 || n >= (int)sizeof(sql)) {
            tp_throw("pg_lo_read_all: SQL too long");
            free(buf);
            return (t_string){0};
        }

        PGresult *res = _pg_lo_exec(conn, sql);
        if (res == NULL) {
            free(buf);
            return (t_string){0};
        }

        if (res->status != PGRES_TUPLES_OK || res->num_rows < 1 ||
            res->num_fields < 1 || res->rows == NULL || res->rows[0] == NULL) {
            _pg_result_free(res);
            tp_throw("pg_lo_read_all: no data in result");
            free(buf);
            return (t_string){0};
        }

        // bytea hex 解码
        t_string raw = _pg_mk_str_n(res->rows[0], res->row_lens[0]);
        t_string chunk = _pg_unescape_bytea(raw);
        _pg_result_free(res);

        int chunk_len = chunk.length;
        if (chunk_len <= 0) {
            // EOF
            break;
        }

        // 扩容缓冲区
        if (buf_len + chunk_len > buf_cap) {
            while (buf_len + chunk_len > buf_cap) buf_cap *= 2;
            char *new_buf = (char*)realloc(buf, (size_t)buf_cap);
            if (new_buf == NULL) {
                tp_throw("pg_lo_read_all: out of memory (realloc)");
                free(buf);
                return (t_string){0};
            }
            buf = new_buf;
        }

        const char *chunk_ptr = STR_PTR(chunk);
        memcpy(buf + buf_len, chunk_ptr, (size_t)chunk_len);
        buf_len += chunk_len;
        handle->pos += chunk_len;

        // 读取不足 chunk_size，说明已到末尾
        if (chunk_len < chunk_size) break;
    }

    t_string result = _pg_mk_str_n(buf, buf_len);
    free(buf);
    return result;
}
