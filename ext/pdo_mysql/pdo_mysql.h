#pragma once
// ============================================================
// pdo_mysql.h — MySQL 驱动（纯 C 协议实现）
//
// 设计目标：
//   - 纯 C 实现 MySQL 协议（不依赖 libmysqlclient）
//   - 通过 pdo_driver_t 接口暴露给 PHP
//   - 用户通过 new PDO("mysql:host=...;port=...;dbname=...") 使用
//   - 认证：mysql_native_password（SHA1，内置实现）
//   - 协议：文本协议（COM_QUERY），预处理用文本协议模拟
//   - 不支持 SSL/TLS、Unix socket、多语句
//
// 依赖：
//   - ext/stream 的跨平台 socket 抽象（stream.h，由 .php 文件 #include）
//   - ext/pdo/pdo_driver.h 的驱动接口（由 .php 文件 #include）
//
// 头文件包含顺序（由 .php 文件控制）：
//   1. stream.h        — socket 跨平台抽象（STREAM_CLOSE/STREAM_ERRNO 等宏 + tphp_fn_stream_init）
//   2. pdo_driver.h    — pdo_driver_t 接口定义 + pdo_register_driver
//   3. pdo_mysql.h     — 本文件，MySQL 协议实现
//
// 内存安全：
//   - 所有 malloc 配对 free
//   - packet 读取循环 recv 直到读够
//   - sequence_id 每个命令重置为 0，每个 packet 递增 1
// ============================================================

#include "types.h"
#include <stdint.h>
#include <stdlib.h>
#include <string.h>
#include <stdio.h>

// ============================================================
// MySQL 能力标志（capabilities）
// ============================================================
#define CLIENT_LONG_PASSWORD          0x00000001
#define CLIENT_FOUND_ROWS             0x00000002
#define CLIENT_LONG_FLAG              0x00000004
#define CLIENT_CONNECT_WITH_DB        0x00000008
#define CLIENT_PROTOCOL_41            0x00000200
#define CLIENT_TRANSACTIONS           0x00002000
#define CLIENT_SECURE_CONNECTION      0x00008000
#define CLIENT_MULTI_STATEMENTS       0x00010000
#define CLIENT_MULTI_RESULTS          0x00020000
#define CLIENT_PLUGIN_AUTH            0x00080000
#define CLIENT_DEPRECATE_EOF          0x01000000

// MySQL 命令常量
#define COM_QUIT          0x01
#define COM_INIT_DB       0x02
#define COM_QUERY         0x03
#define COM_PING          0x0E

// MySQL 协议字段类型（仅文本协议常用类型）
#define MYSQL_TYPE_DECIMAL    0
#define MYSQL_TYPE_TINY       1
#define MYSQL_TYPE_SHORT      2
#define MYSQL_TYPE_LONG       3
#define MYSQL_TYPE_FLOAT      4
#define MYSQL_TYPE_DOUBLE     5
#define MYSQL_TYPE_NULL       6
#define MYSQL_TYPE_TIMESTAMP  7
#define MYSQL_TYPE_LONGLONG   8
#define MYSQL_TYPE_INT24      9
#define MYSQL_TYPE_DATE       10
#define MYSQL_TYPE_TIME       11
#define MYSQL_TYPE_DATETIME   12
#define MYSQL_TYPE_YEAR       13
#define MYSQL_TYPE_VARCHAR    15
#define MYSQL_TYPE_BIT        16
#define MYSQL_TYPE_NEWDECIMAL 246
#define MYSQL_TYPE_BLOB       252
#define MYSQL_TYPE_VAR_STRING 253
#define MYSQL_TYPE_STRING     254

// ============================================================
// SHA1 实现（标准 FIPS 180-4）
//   mysql_native_password 认证需要 SHA1
//   内置实现，不依赖外部库
// ============================================================

typedef struct {
    uint32_t state[5];
    uint64_t count;       // 已处理的字节数（bit 数 = count * 8）
    uint8_t  buffer[64];  // 当前块缓冲
} sha1_ctx;

// SHA1 循环左移
#define SHA1_ROL(value, bits) (((value) << (bits)) | ((value) >> (32 - (bits))))

// SHA1 处理一个 512-bit 块
static void sha1_transform(uint32_t state[5], const uint8_t buffer[64]) {
    uint32_t a, b, c, d, e;
    uint32_t w[80];
    int i;

    // 将 64 字节拆分为 16 个 32-bit 大端字
    for (i = 0; i < 16; i++) {
        w[i] = ((uint32_t)buffer[i*4] << 24)
             | ((uint32_t)buffer[i*4+1] << 16)
             | ((uint32_t)buffer[i*4+2] << 8)
             | ((uint32_t)buffer[i*4+3]);
    }
    // 扩展为 80 个字
    for (i = 16; i < 80; i++) {
        w[i] = SHA1_ROL(w[i-3] ^ w[i-8] ^ w[i-14] ^ w[i-16], 1);
    }

    a = state[0]; b = state[1]; c = state[2]; d = state[3]; e = state[4];

    for (i = 0; i < 80; i++) {
        uint32_t f, k, temp;
        if (i < 20) {
            f = (b & c) | ((~b) & d);
            k = 0x5A827999;
        } else if (i < 40) {
            f = b ^ c ^ d;
            k = 0x6ED9EBA1;
        } else if (i < 60) {
            f = (b & c) | (b & d) | (c & d);
            k = 0x8F1BBCDC;
        } else {
            f = b ^ c ^ d;
            k = 0xCA62C1D6;
        }
        temp = SHA1_ROL(a, 5) + f + e + k + w[i];
        e = d; d = c; c = SHA1_ROL(b, 30); b = a; a = temp;
    }

    state[0] += a; state[1] += b; state[2] += c; state[3] += d; state[4] += e;
}

static void sha1_init(sha1_ctx* ctx) {
    ctx->state[0] = 0x67452301;
    ctx->state[1] = 0xEFCDAB89;
    ctx->state[2] = 0x98BADCFE;
    ctx->state[3] = 0x10325476;
    ctx->state[4] = 0xC3D2E1F0;
    ctx->count = 0;
}

static void sha1_update(sha1_ctx* ctx, const uint8_t* data, size_t len) {
    size_t i;
    // 当前缓冲区已用的字节数
    size_t buf_used = (size_t)(ctx->count & 63);
    ctx->count += len;

    // 如果有剩余数据且能填满一个块，先填满
    if (buf_used > 0) {
        size_t need = 64 - buf_used;
        if (len < need) {
            memcpy(ctx->buffer + buf_used, data, len);
            return;
        }
        memcpy(ctx->buffer + buf_used, data, need);
        sha1_transform(ctx->state, ctx->buffer);
        data += need;
        len -= need;
    }

    // 处理完整块
    for (i = 0; i + 64 <= len; i += 64) {
        sha1_transform(ctx->state, data + i);
    }

    // 剩余不足一块的存入缓冲
    if (i < len) {
        memcpy(ctx->buffer, data + i, len - i);
    }
}

static void sha1_final(sha1_ctx* ctx, uint8_t digest[20]) {
    uint64_t bit_count = ctx->count * 8;
    size_t buf_used = (size_t)(ctx->count & 63);

    // 添加 0x80
    ctx->buffer[buf_used++] = 0x80;

    // 如果剩余不足 8 字节存放长度，需填到块尾再处理一块
    if (buf_used > 56) {
        while (buf_used < 64) ctx->buffer[buf_used++] = 0;
        sha1_transform(ctx->state, ctx->buffer);
        buf_used = 0;
    }
    // 填 0 到第 56 字节
    while (buf_used < 56) ctx->buffer[buf_used++] = 0;

    // 写入 64-bit 长度（大端）
    for (int i = 7; i >= 0; i--) {
        ctx->buffer[buf_used++] = (uint8_t)((bit_count >> (i * 8)) & 0xFF);
    }
    sha1_transform(ctx->state, ctx->buffer);

    // 输出摘要（大端）
    for (int i = 0; i < 5; i++) {
        digest[i*4]   = (uint8_t)((ctx->state[i] >> 24) & 0xFF);
        digest[i*4+1] = (uint8_t)((ctx->state[i] >> 16) & 0xFF);
        digest[i*4+2] = (uint8_t)((ctx->state[i] >> 8)  & 0xFF);
        digest[i*4+3] = (uint8_t)(ctx->state[i]         & 0xFF);
    }
}

// 便捷函数：一次性计算 SHA1
static void sha1_hash(const uint8_t* data, size_t len, uint8_t digest[20]) {
    sha1_ctx ctx;
    sha1_init(&ctx);
    sha1_update(&ctx, data, len);
    sha1_final(&ctx, digest);
}

// ============================================================
// mysql_native_password 认证算法
//   auth_response = SHA1(password) XOR SHA1(salt + SHA1(SHA1(password)))
//   注意：salt 在前，SHA1(SHA1(password)) 在后
//   如果密码为空，auth_response 长度为 0
// ============================================================
static void mysql_native_password(const char* password, const uint8_t* salt, int salt_len, uint8_t* out) {
    if (password == NULL || *password == '\0') {
        return;  // 空密码，调用方应使用 0 长度 auth_response
    }
    // SHA1(password)
    uint8_t sha1_pass[20];
    sha1_hash((const uint8_t*)password, strlen(password), sha1_pass);

    // SHA1(SHA1(password)) — 服务器存储的哈希
    uint8_t sha1_sha1_pass[20];
    sha1_hash(sha1_pass, 20, sha1_sha1_pass);

    // SHA1(salt + SHA1(SHA1(password)))
    sha1_ctx ctx;
    sha1_init(&ctx);
    sha1_update(&ctx, salt, (size_t)salt_len);
    sha1_update(&ctx, sha1_sha1_pass, 20);
    uint8_t sha1_salt[20];
    sha1_final(&ctx, sha1_salt);

    // auth_response = SHA1(password) XOR sha1_salt
    for (int i = 0; i < 20; i++) {
        out[i] = sha1_pass[i] ^ sha1_salt[i];
    }
}

// ============================================================
// MySQL 连接结构体
// ============================================================
typedef struct {
    int fd;                  // socket fd
    int sequence_id;         // MySQL packet sequence id（每个命令重置为 0）
    int error_code;          // 最近错误码
    char error_msg[512];     // 最近错误消息
    int64_t last_insert_id;  // 最近 INSERT 的 rowid
    int64_t affected_rows;   // 最近语句影响的行数
    char server_version[64]; // 服务器版本字符串
    int capabilities;        // 协商后的能力标志
    int charset;             // 字符集（mysql 编号）
    void* active_stmt;       // 当前有未消费结果集的 mysql_stmt_t*（防止连接不同步）
} mysql_conn_t;

// ============================================================
// MySQL 语句结构体（预处理模拟，文本协议）
// ============================================================
typedef struct {
    mysql_conn_t* conn;       // 所属连接（不持有所有权）
    char* sql_template;       // SQL 模板（带 ? 占位符）
    int   sql_template_len;   // 模板长度
    int   num_params;         // 参数数量（? 的个数）
    // 参数值存储
    int*     param_types;     // 0=null, 1=int, 2=text, 3=blob
    int64_t* param_ints;      // int 值
    char**   param_texts;     // text/blob 值指针
    int*     param_text_lens; // text/blob 长度
    // 结果集状态
    int    num_columns;       // 列数
    char** column_names;      // 列名数组（NULL 结尾的 C 字符串）
    int*   column_name_lens; // 列名长度数组
    int    eof_reached;       // 是否已读完结果集
    int    executed;          // 是否已执行（首次 step 时执行）
    // 当前行数据
    char**  row_values;       // 当前行各列的字符串值（借用，下次 step 失效）
    int*    row_value_lens;   // 当前行各列长度（0xFB 前缀的 NULL 用 -1 表示）
    int     row_value_count;  // 当前行实际列数
} mysql_stmt_t;

// ============================================================
// 内部辅助：socket 收发
// ============================================================

// 全循环发送：循环 send 直到全部发出，失败返回 -1
static int _mysql_send_all(int fd, const char* data, int len) {
    int sent = 0;
    while (sent < len) {
        int n = (int)send(fd, data + sent, len - sent, 0);
        if (n <= 0) return -1;
        sent += n;
    }
    return 0;
}

// 全循环接收：循环 recv 直到读够 len 字节，失败返回 -1
static int _mysql_recv_all(int fd, char* buf, int len) {
    int total = 0;
    while (total < len) {
        int n = (int)recv(fd, buf + total, len - total, 0);
        if (n <= 0) return -1;
        total += n;
    }
    return 0;
}

// ============================================================
// 内部辅助：错误设置
// ============================================================
static void _mysql_set_error(mysql_conn_t* conn, int code, const char* msg) {
    if (conn == NULL) return;
    conn->error_code = code;
    if (msg != NULL) {
        strncpy(conn->error_msg, msg, sizeof(conn->error_msg) - 1);
        conn->error_msg[sizeof(conn->error_msg) - 1] = '\0';
    } else {
        conn->error_msg[0] = '\0';
    }
}

// ============================================================
// MySQL packet 收发
// ============================================================

// 发送一个 MySQL packet（自动加 4 字节头 + 维护 sequence_id）
//   payload 长度必须 < 0xFFFFFF（不支持分包，单 packet 最大 16MB-1）
//   成功返回 0，失败返回 -1
static int _mysql_send_packet(mysql_conn_t* conn, const char* payload, int payload_len) {
    if (conn == NULL || payload == NULL || payload_len < 0) return -1;
    if (payload_len >= 0xFFFFFF) {
        _mysql_set_error(conn, 0, "packet too large (>16MB-1)");
        return -1;
    }
    char header[4];
    header[0] = (char)(payload_len & 0xFF);
    header[1] = (char)((payload_len >> 8) & 0xFF);
    header[2] = (char)((payload_len >> 16) & 0xFF);
    header[3] = (char)(conn->sequence_id & 0xFF);
    conn->sequence_id++;
    if (_mysql_send_all(conn->fd, header, 4) != 0) {
        _mysql_set_error(conn, 0, "send header failed");
        return -1;
    }
    if (payload_len > 0) {
        if (_mysql_send_all(conn->fd, payload, payload_len) != 0) {
            _mysql_set_error(conn, 0, "send payload failed");
            return -1;
        }
    }
    return 0;
}

// 接收一个 MySQL packet
//   buf:        接收缓冲区（调用方拥有）
//   buf_cap:    缓冲区容量
//   out_len:    输出 payload 长度
//   成功返回 0，失败返回 -1
//   注意：如果 payload 长度超过 buf_cap，会返回 -1（不支持分包接收）
static int _mysql_recv_packet(mysql_conn_t* conn, char* buf, int buf_cap, int* out_len) {
    if (conn == NULL || buf == NULL || out_len == NULL) return -1;
    *out_len = 0;
    char header[4];
    if (_mysql_recv_all(conn->fd, header, 4) != 0) {
        _mysql_set_error(conn, 0, "recv header failed");
        return -1;
    }
    int payload_len = ((int)(unsigned char)header[0])
                    | (((int)(unsigned char)header[1]) << 8)
                    | (((int)(unsigned char)header[2]) << 16);
    conn->sequence_id = ((unsigned char)header[3]) + 1;
    if (payload_len > buf_cap) {
        _mysql_set_error(conn, 0, "packet too large for buffer");
        return -1;
    }
    if (payload_len > 0) {
        if (_mysql_recv_all(conn->fd, buf, payload_len) != 0) {
            _mysql_set_error(conn, 0, "recv payload failed");
            return -1;
        }
    }
    *out_len = payload_len;
    return 0;
}

// ============================================================
// Length-Encoded Integer / String 读取
//   返回值：成功读取的字节数（用于推进读指针），失败返回 -1
//   in:    输入缓冲区
//   in_len: 输入缓冲区剩余长度
//   out:    输出值
// ============================================================
static int _mysql_read_lenenc_int(const char* in, int in_len, uint64_t* out) {
    if (in_len < 1) return -1;
    unsigned char b = (unsigned char)in[0];
    if (b < 0xFB) {
        *out = b;
        return 1;
    } else if (b == 0xFC) {
        if (in_len < 3) return -1;
        *out = (uint64_t)((unsigned char)in[1])
             | ((uint64_t)((unsigned char)in[2]) << 8);
        return 3;
    } else if (b == 0xFD) {
        if (in_len < 4) return -1;
        *out = (uint64_t)((unsigned char)in[1])
             | ((uint64_t)((unsigned char)in[2]) << 8)
             | ((uint64_t)((unsigned char)in[3]) << 16);
        return 4;
    } else if (b == 0xFE) {
        if (in_len < 9) return -1;
        uint64_t v = 0;
        for (int i = 0; i < 8; i++) {
            v |= ((uint64_t)((unsigned char)in[1 + i])) << (i * 8);
        }
        *out = v;
        return 9;
    }
    // 0xFF/0xFB 在 lenenc_int 中非法（0xFF 是 ERR packet 头，0xFB 是 NULL 标记）
    return -1;
}

// 读取 length-encoded string
//   返回值：读取的总字节数（含长度前缀），失败返回 -1
//   out_ptr: 指向 in 缓冲区内的字符串（不拷贝）
//   out_len: 字符串长度
static int _mysql_read_lenenc_str(const char* in, int in_len, const char** out_ptr, int* out_len) {
    uint64_t str_len;
    int n = _mysql_read_lenenc_int(in, in_len, &str_len);
    if (n < 0) return -1;
    if (n + (int)str_len > in_len) return -1;
    *out_ptr = in + n;
    *out_len = (int)str_len;
    return n + (int)str_len;
}

// ============================================================
// DSN 解析：mysql:host=127.0.0.1;port=3306;dbname=test;charset=utf8
//   或简化形式 mysql:127.0.0.1:3306/dbname
// ============================================================
typedef struct {
    char host[128];
    int  port;
    char dbname[128];
    char charset[32];
} mysql_dsn_t;

static void _mysql_parse_dsn(const char* dsn, mysql_dsn_t* out) {
    // 默认值
    strcpy(out->host, "127.0.0.1");
    out->port = 3306;
    out->dbname[0] = '\0';
    strcpy(out->charset, "utf8");

    if (dsn == NULL) return;
    // 跳过 "mysql:" 前缀
    const char* p = dsn;
    if (strncasecmp(p, "mysql:", 6) == 0) p += 6;
    if (*p == '\0') return;

    // 检测是否为简化形式（host:port/dbname，无 = 号）
    if (strchr(p, '=') == NULL) {
        // 简化形式：host:port/dbname
        const char* slash = strchr(p, '/');
        const char* colon = strchr(p, ':');
        if (colon != NULL && (slash == NULL || colon < slash)) {
            int hlen = (int)(colon - p);
            if (hlen >= (int)sizeof(out->host)) hlen = sizeof(out->host) - 1;
            memcpy(out->host, p, hlen);
            out->host[hlen] = '\0';
            out->port = atoi(colon + 1);
            if (slash != NULL) {
                const char* db = slash + 1;
                int dlen = (int)strlen(db);
                if (dlen >= (int)sizeof(out->dbname)) dlen = sizeof(out->dbname) - 1;
                memcpy(out->dbname, db, dlen);
                out->dbname[dlen] = '\0';
            }
        } else {
            // 仅 host[/dbname]
            int hlen = (int)strlen(p);
            if (slash != NULL) hlen = (int)(slash - p);
            if (hlen >= (int)sizeof(out->host)) hlen = sizeof(out->host) - 1;
            memcpy(out->host, p, hlen);
            out->host[hlen] = '\0';
            if (slash != NULL) {
                const char* db = slash + 1;
                int dlen = (int)strlen(db);
                if (dlen >= (int)sizeof(out->dbname)) dlen = sizeof(out->dbname) - 1;
                memcpy(out->dbname, db, dlen);
                out->dbname[dlen] = '\0';
            }
        }
        return;
    }

    // key=value 形式
    const char* cur = p;
    while (*cur != '\0') {
        // 跳过分号
        while (*cur == ';') cur++;
        if (*cur == '\0') break;
        // 提取 key
        const char* eq = strchr(cur, '=');
        if (eq == NULL) break;
        int klen = (int)(eq - cur);
        const char* val = eq + 1;
        // 找下一个分号或末尾
        const char* next = strchr(val, ';');
        int vlen;
        if (next == NULL) {
            vlen = (int)strlen(val);
        } else {
            vlen = (int)(next - val);
        }
        if (klen == 4 && strncasecmp(cur, "host", 4) == 0) {
            if (vlen >= (int)sizeof(out->host)) vlen = sizeof(out->host) - 1;
            memcpy(out->host, val, vlen);
            out->host[vlen] = '\0';
        } else if (klen == 4 && strncasecmp(cur, "port", 4) == 0) {
            char pb[16];
            if (vlen >= (int)sizeof(pb)) vlen = sizeof(pb) - 1;
            memcpy(pb, val, vlen);
            pb[vlen] = '\0';
            out->port = atoi(pb);
        } else if (klen == 6 && strncasecmp(cur, "dbname", 6) == 0) {
            if (vlen >= (int)sizeof(out->dbname)) vlen = sizeof(out->dbname) - 1;
            memcpy(out->dbname, val, vlen);
            out->dbname[vlen] = '\0';
        } else if (klen == 7 && strncasecmp(cur, "charset", 7) == 0) {
            if (vlen >= (int)sizeof(out->charset)) vlen = sizeof(out->charset) - 1;
            memcpy(out->charset, val, vlen);
            out->charset[vlen] = '\0';
        }
        if (next == NULL) break;
        cur = next + 1;
    }
}

// ============================================================
// 握手：读取服务器 Handshake V10 packet
//   salt_out: 输出 20 字节 auth_plugin_data
//   salt_out_len: 实际 salt 长度（应为 20）
//   server_version_out: 服务器版本字符串
//   sv_cap: 服务器能力标志
//   成功返回 0，失败返回 -1
// ============================================================
static int _mysql_read_handshake(mysql_conn_t* conn, char* buf, int buf_cap,
                                  uint8_t* salt_out, int* salt_out_len,
                                  int* sv_cap) {
    int pkt_len;
    if (_mysql_recv_packet(conn, buf, buf_cap, &pkt_len) != 0) return -1;
    if (pkt_len < 1) {
        _mysql_set_error(conn, 0, "handshake packet too short");
        return -1;
    }
    // 检查是否为 ERR packet
    if ((unsigned char)buf[0] == 0xFF) {
        // ERR packet: 0xFF + 2 字节 error_code + (1 字节 '#' + 5 字节 sqlstate) + message
        int ec = (int)((unsigned char)buf[1]) | ((int)((unsigned char)buf[2]) << 8);
        char emsg[256];
        const char* mstart = buf + 3;
        int mlen = pkt_len - 3;
        // 跳过 '#'/sqlstate（如果 CLIENT_PROTOCOL_41）
        if (mlen >= 6 && buf[3] == '#') {
            mstart = buf + 9;
            mlen = pkt_len - 9;
        }
        if (mlen < 0) mlen = 0;
        if (mlen > (int)sizeof(emsg) - 1) mlen = (int)sizeof(emsg) - 1;
        memcpy(emsg, mstart, mlen);
        emsg[mlen] = '\0';
        _mysql_set_error(conn, ec, emsg);
        return -1;
    }
    // 协议版本应为 10
    if ((unsigned char)buf[0] != 10) {
        _mysql_set_error(conn, 0, "unsupported handshake protocol version");
        return -1;
    }
    int pos = 1;
    // 服务器版本字符串（NUL-terminated）
    const char* sv = buf + pos;
    int sv_max = pkt_len - pos;
    int sv_len = 0;
    while (sv_len < sv_max && sv[sv_len] != '\0') sv_len++;
    if (sv_len >= (int)sizeof(conn->server_version)) sv_len = (int)sizeof(conn->server_version) - 1;
    memcpy(conn->server_version, sv, sv_len);
    conn->server_version[sv_len] = '\0';
    pos += sv_len + 1;  // 跳过 NUL
    if (pos + 4 + 8 + 1 + 2 > pkt_len) {
        _mysql_set_error(conn, 0, "handshake packet truncated");
        return -1;
    }
    // 4 字节连接 id（跳过）
    pos += 4;
    // 8 字节 auth_plugin_data_part_1
    uint8_t salt_part1[8];
    memcpy(salt_part1, buf + pos, 8);
    pos += 8;
    // 1 字节填充（0x00）
    pos += 1;
    // 2 字节能力标志低 16 位
    int cap_lo = (int)((unsigned char)buf[pos]) | ((int)((unsigned char)buf[pos+1]) << 8);
    pos += 2;
    int capabilities = cap_lo;
    // 1 字节字符集
    if (pos < pkt_len) {
        conn->charset = (unsigned char)buf[pos];
        pos += 1;
    }
    // 2 字节状态标志（跳过）
    if (pos + 2 <= pkt_len) pos += 2;
    // 2 字节能力标志高 16 位
    if (pos + 2 <= pkt_len) {
        int cap_hi = (int)((unsigned char)buf[pos]) | ((int)((unsigned char)buf[pos+1]) << 8);
        capabilities |= (cap_hi << 16);
        pos += 2;
    }
    *sv_cap = capabilities;
    // 1 字节 auth_plugin_data 长度（如果 CLIENT_PLUGIN_AUTH）
    int auth_data_len = 0;
    if (capabilities & CLIENT_PLUGIN_AUTH) {
        if (pos < pkt_len) {
            auth_data_len = (unsigned char)buf[pos];
            pos += 1;
        }
    } else {
        pos += 1;  // 仍占用 1 字节
    }
    // 10 字节保留
    pos += 10;
    // 13 字节 auth_plugin_data_part_2（如果 CLIENT_SECURE_CONNECTION）
    // part2 长度 = max(13, auth_data_len - 8)
    int part2_len = 0;
    if (capabilities & CLIENT_SECURE_CONNECTION) {
        part2_len = auth_data_len - 8;
        if (part2_len < 13) part2_len = 13;
        if (pos + part2_len > pkt_len) {
            part2_len = pkt_len - pos;
            if (part2_len < 0) part2_len = 0;
        }
    }
    // 组合 salt: 8 字节 part1 + part2 字节
    int total_salt = 8 + part2_len;
    if (total_salt > 20) total_salt = 20;  // mysql_native_password 用 20 字节
    memcpy(salt_out, salt_part1, 8);
    if (part2_len > 0) {
        int copy = part2_len;
        if (copy > 12) copy = 12;  // part2 实际有效字节最多 12，组合后 = 20
        memcpy(salt_out + 8, buf + pos, copy);
    }
    *salt_out_len = 20;  // mysql_native_password 固定使用 20 字节
    (void)total_salt;
    pos += part2_len;
    // auth_plugin_name（如果 CLIENT_PLUGIN_AUTH）
    // 不解析，假设 mysql_native_password
    return 0;
}

// ============================================================
// 握手响应：发送 Handshake Response 41 packet
//   user:       用户名
//   pass:       密码
//   dbname:     数据库名（空字符串表示不指定）
//   salt:       20 字节 auth_plugin_data
//   salt_len:   salt 长度
//   sv_cap:     服务器能力标志
//   成功返回 0，失败返回 -1
// ============================================================
static int _mysql_send_handshake_response(mysql_conn_t* conn,
                                            const char* user, const char* pass,
                                            const char* dbname,
                                            const uint8_t* salt, int salt_len,
                                            int sv_cap) {
    (void)salt_len;
    // 客户端能力：取交集（不设置 CLIENT_DEPRECATE_EOF 简化结果集处理）
    int client_cap = CLIENT_LONG_PASSWORD | CLIENT_FOUND_ROWS | CLIENT_PROTOCOL_41
                   | CLIENT_TRANSACTIONS | CLIENT_SECURE_CONNECTION | CLIENT_PLUGIN_AUTH;
    if (dbname != NULL && *dbname != '\0') {
        client_cap |= CLIENT_CONNECT_WITH_DB;
    }
    int negotiated = client_cap & sv_cap;
    conn->capabilities = negotiated;

    // 计算 auth_response（mysql_native_password）
    uint8_t auth_resp[20];
    int auth_resp_len = 0;
    if (pass != NULL && *pass != '\0') {
        mysql_native_password(pass, salt, 20, auth_resp);
        auth_resp_len = 20;
    }

    // 构造 packet
    char buf[1024];
    int pos = 0;
    // 4 字节能力标志
    buf[pos++] = (char)(negotiated & 0xFF);
    buf[pos++] = (char)((negotiated >> 8) & 0xFF);
    buf[pos++] = (char)((negotiated >> 16) & 0xFF);
    buf[pos++] = (char)((negotiated >> 24) & 0xFF);
    // 4 字节最大包大小（16MB = 0x01000000，LE）
    buf[pos++] = 0x00;
    buf[pos++] = 0x00;
    buf[pos++] = 0x00;
    buf[pos++] = 0x01;
    // 1 字节字符集（utf8_general_ci = 33 = 0x21）
    buf[pos++] = 0x21;
    // 23 字节保留
    memset(buf + pos, 0, 23);
    pos += 23;
    // NUL-terminated 用户名
    int ulen = user ? (int)strlen(user) : 0;
    if (pos + ulen + 1 > (int)sizeof(buf)) {
        _mysql_set_error(conn, 0, "handshake response buffer overflow (user)");
        return -1;
    }
    if (ulen > 0) memcpy(buf + pos, user, ulen);
    pos += ulen;
    buf[pos++] = '\0';
    // auth_response（length-encoded）
    if (auth_resp_len == 20) {
        // 长度 < 251 → 1 字节长度 + 数据
        buf[pos++] = (char)20;
        memcpy(buf + pos, auth_resp, 20);
        pos += 20;
    } else {
        buf[pos++] = 0;  // 空密码，0 长度
    }
    // 数据库名（如果 CLIENT_CONNECT_WITH_DB）
    if (dbname != NULL && *dbname != '\0' && (negotiated & CLIENT_CONNECT_WITH_DB)) {
        int dlen = (int)strlen(dbname);
        if (pos + dlen + 1 > (int)sizeof(buf)) {
            _mysql_set_error(conn, 0, "handshake response buffer overflow (dbname)");
            return -1;
        }
        memcpy(buf + pos, dbname, dlen);
        pos += dlen;
        buf[pos++] = '\0';
    }
    // auth_plugin_name（如果 CLIENT_PLUGIN_AUTH）
    if (negotiated & CLIENT_PLUGIN_AUTH) {
        const char* plugin = "mysql_native_password";
        int plen = (int)strlen(plugin);
        if (pos + plen + 1 > (int)sizeof(buf)) {
            _mysql_set_error(conn, 0, "handshake response buffer overflow (plugin)");
            return -1;
        }
        memcpy(buf + pos, plugin, plen);
        pos += plen;
        buf[pos++] = '\0';
    }
    return _mysql_send_packet(conn, buf, pos);
}

// ============================================================
// 读取 OK/ERR packet
//   buf:     payload 数据
//   buf_len: payload 长度
//   返回值：1 = OK, 0 = ERR（已设置 error_msg）, -1 = 不是 OK/ERR
// ============================================================
static int _mysql_parse_ok_err(mysql_conn_t* conn, const char* buf, int buf_len) {
    if (buf_len < 1) return -1;
    unsigned char first = (unsigned char)buf[0];
    if (first == 0x00) {
        // OK packet
        int pos = 1;
        // lenenc affected_rows
        uint64_t ar;
        int n = _mysql_read_lenenc_int(buf + pos, buf_len - pos, &ar);
        if (n < 0) return -1;
        conn->affected_rows = (int64_t)ar;
        pos += n;
        // lenenc last_insert_id
        uint64_t li;
        n = _mysql_read_lenenc_int(buf + pos, buf_len - pos, &li);
        if (n < 0) return -1;
        conn->last_insert_id = (int64_t)li;
        pos += n;
        // 2 字节状态标志 + 2 字节警告数（如果 CLIENT_PROTOCOL_41）
        // 不解析剩余信息
        _mysql_set_error(conn, 0, "");
        return 1;
    } else if (first == 0xFE && buf_len < 9) {
        // EOF packet（标准 EOF 长度 <= 5 字节：1 + 2 警告 + 2 状态，保守用 < 9）
        // 注意：0xFE 且长度 >= 9 是 AuthSwitchRequest，不应当作 EOF/OK
        conn->affected_rows = 0;
        conn->last_insert_id = 0;
        _mysql_set_error(conn, 0, "");
        return 1;
    } else if (first == 0xFF) {
        // ERR packet
        if (buf_len < 3) return -1;
        int ec = (int)((unsigned char)buf[1]) | ((int)((unsigned char)buf[2]) << 8);
        const char* mstart = buf + 3;
        int mlen = buf_len - 3;
        if (mlen >= 6 && buf[3] == '#') {
            mstart = buf + 9;
            mlen = buf_len - 9;
        }
        if (mlen < 0) mlen = 0;
        if (mlen > (int)sizeof(conn->error_msg) - 1) mlen = (int)sizeof(conn->error_msg) - 1;
        memcpy(conn->error_msg, mstart, mlen);
        conn->error_msg[mlen] = '\0';
        conn->error_code = ec;
        return 0;
    }
    return -1;  // 不是 OK/ERR（可能是结果集开头）
}

// ============================================================
// 创建 TCP 连接到 MySQL 服务器
//   成功返回 0，失败返回 -1
// ============================================================
static int _mysql_connect_socket(mysql_conn_t* conn, const char* host, int port) {
    // 调用 stream.h 的初始化（Windows WSAStartup）
    tphp_fn_stream_init();

    struct sockaddr_in addr;
    memset(&addr, 0, sizeof(addr));
    addr.sin_family = AF_INET;
    addr.sin_port = htons((unsigned short)port);
    if (host == NULL || *host == '\0') host = "127.0.0.1";
    // 优先用 inet_pton 解析 IP，失败则用 gethostbyname
    if (inet_pton(AF_INET, host, &addr.sin_addr) != 1) {
        struct hostent* he = gethostbyname(host);
        if (he == NULL || he->h_addrtype != AF_INET || he->h_addr_list[0] == NULL) {
            _mysql_set_error(conn, 0, "failed to resolve hostname");
            return -1;
        }
        memcpy(&addr.sin_addr, he->h_addr_list[0], sizeof(addr.sin_addr));
    }

    int fd = (int)socket(AF_INET, SOCK_STREAM, 0);
    if (fd < 0) {
        _mysql_set_error(conn, 0, "socket() failed");
        return -1;
    }
    if (connect(fd, (struct sockaddr*)&addr, sizeof(addr)) < 0) {
        _mysql_set_error(conn, 0, "connect() failed");
        STREAM_CLOSE(fd);
        return -1;
    }
    // 设置默认 recv 超时 30 秒（避免永久阻塞）
#ifdef _WIN32
    DWORD to_ms = 30000;
    setsockopt((SOCKET)fd, SOL_SOCKET, SO_RCVTIMEO, (const char*)&to_ms, sizeof(to_ms));
    setsockopt((SOCKET)fd, SOL_SOCKET, SO_SNDTIMEO, (const char*)&to_ms, sizeof(to_ms));
#else
    struct timeval tv;
    tv.tv_sec = 30;
    tv.tv_usec = 0;
    setsockopt(fd, SOL_SOCKET, SO_RCVTIMEO, (const char*)&tv, sizeof(tv));
    setsockopt(fd, SOL_SOCKET, SO_SNDTIMEO, (const char*)&tv, sizeof(tv));
#endif
    conn->fd = fd;
    return 0;
}

// ============================================================
// 发送 COM_QUERY 并读取响应
//   sql:       SQL 字符串
//   sql_len:   SQL 长度
//   resp_buf:  响应缓冲区
//   resp_cap:  响应缓冲区容量
//   resp_len:  输出响应长度
//   返回值：1 = 结果集（resp_buf[0] 是列数 lenenc），0 = OK packet，-1 = 错误
// ============================================================
static int _mysql_send_query(mysql_conn_t* conn, const char* sql, int sql_len,
                                char* resp_buf, int resp_cap, int* resp_len) {
    conn->sequence_id = 0;  // 新命令重置 sequence_id
    // 构造 COM_QUERY packet: 1 字节 0x03 + SQL
    int pkt_len = 1 + sql_len;
    char* buf = (char*)malloc(pkt_len);
    if (buf == NULL) {
        _mysql_set_error(conn, 0, "out of memory");
        return -1;
    }
    buf[0] = (char)COM_QUERY;
    if (sql_len > 0) memcpy(buf + 1, sql, sql_len);
    int rc = _mysql_send_packet(conn, buf, pkt_len);
    free(buf);
    if (rc != 0) return -1;
    // 读取响应
    if (_mysql_recv_packet(conn, resp_buf, resp_cap, resp_len) != 0) return -1;
    if (*resp_len < 1) {
        _mysql_set_error(conn, 0, "empty response");
        return -1;
    }
    unsigned char first = (unsigned char)resp_buf[0];
    if (first == 0x00 || (first == 0xFE && *resp_len < 0xFFFFFF)) {
        // OK packet (0x00) — 不返回结果集
        return _mysql_parse_ok_err(conn, resp_buf, *resp_len) >= 0 ? 0 : -1;
    } else if (first == 0xFF) {
        // ERR packet
        _mysql_parse_ok_err(conn, resp_buf, *resp_len);
        return -1;
    } else {
        // 结果集：第一个字段是 lenenc 列数
        return 1;
    }
}

// ============================================================
// 读取 ColumnDefinition packet（仅提取列名）
//   返回值：0 = 成功（out_name 指向 resp_buf 内的字符串），-1 = 失败
// ============================================================
static int _mysql_read_column_def(mysql_conn_t* conn, char* name_out, int name_cap) {
    char buf[4096];
    int pkt_len;
    if (_mysql_recv_packet(conn, buf, sizeof(buf), &pkt_len) != 0) return -1;
    // 跳过 6 个 lenenc string: catalog, schema, table, org_table, name, org_name
    int pos = 0;
    const char* dummy_ptr;
    int dummy_len;
    // catalog
    int n = _mysql_read_lenenc_str(buf + pos, pkt_len - pos, &dummy_ptr, &dummy_len);
    if (n < 0) return -1;
    pos += n;
    // schema
    n = _mysql_read_lenenc_str(buf + pos, pkt_len - pos, &dummy_ptr, &dummy_len);
    if (n < 0) return -1;
    pos += n;
    // table
    n = _mysql_read_lenenc_str(buf + pos, pkt_len - pos, &dummy_ptr, &dummy_len);
    if (n < 0) return -1;
    pos += n;
    // org_table
    n = _mysql_read_lenenc_str(buf + pos, pkt_len - pos, &dummy_ptr, &dummy_len);
    if (n < 0) return -1;
    pos += n;
    // name
    const char* name_ptr;
    int name_len;
    n = _mysql_read_lenenc_str(buf + pos, pkt_len - pos, &name_ptr, &name_len);
    if (n < 0) return -1;
    if (name_len >= name_cap) name_len = name_cap - 1;
    memcpy(name_out, name_ptr, name_len);
    name_out[name_len] = '\0';
    return 0;
}

// ============================================================
// 跳过 EOF packet
//   成功返回 0，失败返回 -1
// ============================================================
static int _mysql_skip_eof(mysql_conn_t* conn) {
    char buf[16];
    int pkt_len;
    if (_mysql_recv_packet(conn, buf, sizeof(buf), &pkt_len) != 0) return -1;
    if (pkt_len < 1) return -1;
    if ((unsigned char)buf[0] != 0xFE) {
        _mysql_set_error(conn, 0, "expected EOF packet");
        return -1;
    }
    return 0;
}

// ============================================================
// 读取一行 Row packet
//   stmt:     语句（用于存储行值）
//   返回值：1 = 有行, 0 = EOF（结果集结束），-1 = 错误
// ============================================================
static int _mysql_read_row(mysql_stmt_t* stmt) {
    mysql_conn_t* conn = stmt->conn;
    // 复用较大的缓冲区
    char buf[16384];
    int pkt_len;
    if (_mysql_recv_packet(conn, buf, sizeof(buf), &pkt_len) != 0) return -1;
    if (pkt_len < 1) {
        _mysql_set_error(conn, 0, "empty row packet");
        return -1;
    }
    unsigned char first = (unsigned char)buf[0];
    if (first == 0xFE && pkt_len < 0xFFFFFF) {
        // EOF packet — 结果集结束
        stmt->eof_reached = 1;
        return 0;
    } else if (first == 0xFF) {
        // ERR packet
        _mysql_parse_ok_err(conn, buf, pkt_len);
        return -1;
    }
    // 解析 Row packet: num_columns 个 lenenc string
    int pos = 0;
    int cc = stmt->num_columns;
    // 释放上一行的 row_values（如果是 malloc'd）
    if (stmt->row_values != NULL) {
        for (int i = 0; i < stmt->row_value_count; i++) {
            if (stmt->row_values[i] != NULL) free(stmt->row_values[i]);
        }
        free(stmt->row_values);
        stmt->row_values = NULL;
    }
    if (stmt->row_value_lens != NULL) {
        free(stmt->row_value_lens);
        stmt->row_value_lens = NULL;
    }
    stmt->row_values = (char**)malloc(sizeof(char*) * cc);
    stmt->row_value_lens = (int*)malloc(sizeof(int) * cc);
    if (stmt->row_values == NULL || stmt->row_value_lens == NULL) {
        if (stmt->row_values) free(stmt->row_values);
        if (stmt->row_value_lens) free(stmt->row_value_lens);
        stmt->row_values = NULL;
        stmt->row_value_lens = NULL;
        _mysql_set_error(conn, 0, "out of memory");
        return -1;
    }
    stmt->row_value_count = cc;
    for (int i = 0; i < cc; i++) {
        if (pos >= pkt_len) {
            // 不完整数据
            stmt->row_values[i] = NULL;
            stmt->row_value_lens[i] = -1;
            continue;
        }
        unsigned char fb = (unsigned char)buf[pos];
        if (fb == 0xFB) {
            // NULL 值
            stmt->row_values[i] = NULL;
            stmt->row_value_lens[i] = -1;
            pos += 1;
        } else {
            // lenenc string
            const char* sptr;
            int slen;
            int n = _mysql_read_lenenc_str(buf + pos, pkt_len - pos, &sptr, &slen);
            if (n < 0) {
                stmt->row_values[i] = NULL;
                stmt->row_value_lens[i] = -1;
                break;
            }
            // 深拷贝（buf 在下次 recv 后失效）
            char* dup = (char*)malloc(slen + 1);
            if (dup == NULL) {
                _mysql_set_error(conn, 0, "out of memory");
                return -1;
            }
            memcpy(dup, sptr, slen);
            dup[slen] = '\0';
            stmt->row_values[i] = dup;
            stmt->row_value_lens[i] = slen;
            pos += n;
        }
    }
    return 1;
}

// ============================================================
// 消费剩余结果集（用于 exec 中的 SELECT 语句）
// ============================================================
static int _mysql_consume_resultset(mysql_stmt_t* stmt) {
    int rows = 0;
    while (1) {
        int rc = _mysql_read_row(stmt);
        if (rc <= 0) break;
        rows++;
    }
    return rows;
}

// ============================================================
// 驱动函数前向声明（避免前向引用导致隐式声明）
// ============================================================
static int _pdo_mysql_open(const char* dsn, int flags, const char* user, const char* pass, void** dbh);
static void _pdo_mysql_close(void* dbh);
static int _pdo_mysql_exec(void* dbh, const char* sql);
static int _pdo_mysql_prepare(void* dbh, const char* sql, void** stmt);
static int _pdo_mysql_bind_int(void* stmt, int idx, int64_t val);
static int _pdo_mysql_bind_text(void* stmt, int idx, const char* val, int len);
static int _pdo_mysql_bind_blob(void* stmt, int idx, const char* data, int len);
static int _pdo_mysql_bind_null(void* stmt, int idx);
static int _pdo_mysql_bind_param_index(void* stmt, const char* name);
static int _pdo_mysql_step(void* stmt);
static int _pdo_mysql_reset(void* stmt);
static int _pdo_mysql_clear_bindings(void* stmt);
static int _pdo_mysql_finalize(void* stmt);
static int _pdo_mysql_column_count(void* stmt);
static int _pdo_mysql_column_type(void* stmt, int col);
static int64_t _pdo_mysql_column_int64(void* stmt, int col);
static double _pdo_mysql_column_double(void* stmt, int col);
static const char* _pdo_mysql_column_text(void* stmt, int col);
static int _pdo_mysql_column_bytes(void* stmt, int col);
static const char* _pdo_mysql_column_name(void* stmt, int col);
static const char* _pdo_mysql_column_decltype(void* stmt, int col);
static int _pdo_mysql_data_count(void* stmt);
static int64_t _pdo_mysql_changes(void* dbh);
static int64_t _pdo_mysql_last_insert_rowid(void* dbh);
static int _pdo_mysql_errcode(void* dbh);
static const char* _pdo_mysql_errmsg(void* dbh);
static int _pdo_mysql_busy_timeout(void* dbh, int ms);
static void _pdo_mysql_extended_result_codes(void* dbh, int on);
static char* _pdo_mysql_quote(const char* s);
static void _pdo_mysql_free_quote(char* s);
static const char* _pdo_mysql_driver_name(void);
static const char* _pdo_mysql_server_version(void* dbh);

// ============================================================
// 驱动函数实现
// ============================================================

// ── _pdo_mysql_open: 解析 DSN + 连接 + 认证 ──
//
// 失败处理契约（与 pdo_driver_open 配合）：
//   - 失败时 *dbh = conn（不 free、不 close fd），返回 -1
//   - 错误信息已写入 conn->error_msg
//   - pdo_driver_open 调用 drv->errmsg(dbh) 读取错误信息后调用 drv->close(dbh) 释放
//   - 这样失败也能拿到详细错误（如握手错误、认证错误）
//   - 仅在 malloc 失败这种极端情况下 *dbh 保持 NULL
static int _pdo_mysql_open(const char* dsn, int flags, const char* user, const char* pass, void** dbh) {
    (void)flags;
    if (dsn == NULL || dbh == NULL) return -1;
    *dbh = NULL;
    // 验证 DSN 前缀
    if (strncasecmp(dsn, "mysql:", 6) != 0) return -1;

    mysql_dsn_t dsn_info;
    _mysql_parse_dsn(dsn, &dsn_info);

    mysql_conn_t* conn = (mysql_conn_t*)malloc(sizeof(mysql_conn_t));
    if (conn == NULL) return -1;
    memset(conn, 0, sizeof(*conn));
    conn->fd = -1;
    conn->sequence_id = 0;
    conn->charset = 33;  // utf8_general_ci
    conn->capabilities = 0;

    // 1. 创建 socket 连接
    if (_mysql_connect_socket(conn, dsn_info.host, dsn_info.port) != 0) {
        // _mysql_connect_socket 已 set_error
        *dbh = conn;  // 保留 conn 供 driver 读取错误
        return -1;
    }

    // 2. 读取握手 packet
    char hs_buf[4096];
    uint8_t salt[20];
    int salt_len;
    int sv_cap;
    if (_mysql_read_handshake(conn, hs_buf, sizeof(hs_buf), salt, &salt_len, &sv_cap) != 0) {
        // _mysql_read_handshake 已 set_error
        *dbh = conn;  // 保留 conn 供 driver 读取错误
        return -1;
    }

    // 3. 发送握手响应（含认证）
    if (_mysql_send_handshake_response(conn, user ? user : "", pass ? pass : "",
                                       dsn_info.dbname[0] ? dsn_info.dbname : NULL,
                                       salt, salt_len, sv_cap) != 0) {
        // _mysql_send_handshake_response 已 set_error
        *dbh = conn;  // 保留 conn 供 driver 读取错误
        return -1;
    }

    // 4. 读取 OK/ERR/AuthSwitchRequest packet
    char resp_buf[1024];
    int resp_len;
    if (_mysql_recv_packet(conn, resp_buf, sizeof(resp_buf), &resp_len) != 0) {
        // _mysql_recv_packet 已 set_error
        *dbh = conn;  // 保留 conn 供 driver 读取错误
        return -1;
    }
    // 检测 AuthSwitchRequest（0xFE 开头且长度 > 1，标准 EOF 长度 <= 5）
    if (resp_len >= 2 && (unsigned char)resp_buf[0] == 0xFE) {
        // AuthSwitchRequest: 0xFE + NUL-terminated plugin_name + plugin_data
        const char* plugin_name = resp_buf + 1;
        int pn_len = 0;
        while (pn_len < resp_len - 1 && plugin_name[pn_len] != '\0') pn_len++;
        if (pn_len == 21 && strncmp(plugin_name, "mysql_native_password", 21) == 0) {
            // mysql_native_password 切换：读取新 salt，重新认证
            int data_off = 1 + pn_len + 1;  // 0xFE + plugin_name + NUL
            if (data_off + 20 <= resp_len) {
                uint8_t new_salt[20];
                memcpy(new_salt, resp_buf + data_off, 20);
                uint8_t auth_resp[20];
                mysql_native_password(pass ? pass : "", new_salt, 20, auth_resp);
                if (_mysql_send_packet(conn, (const char*)auth_resp, 20) != 0) {
                    *dbh = conn;
                    return -1;
                }
                // 读取切换后的 OK/ERR
                if (_mysql_recv_packet(conn, resp_buf, sizeof(resp_buf), &resp_len) != 0) {
                    *dbh = conn;
                    return -1;
                }
            }
        } else {
            // 不支持的认证插件（如 caching_sha2_password，需要 RSA/SHA256）
            char msg[256];
            snprintf(msg, sizeof(msg),
                     "unsupported auth plugin '%.*s' (only mysql_native_password is supported; "
                     "run: ALTER USER 'root'@'%%' IDENTIFIED WITH mysql_native_password BY 'password')",
                     pn_len, plugin_name);
            _mysql_set_error(conn, 0, msg);
            *dbh = conn;
            return -1;
        }
    }
    int ok = _mysql_parse_ok_err(conn, resp_buf, resp_len);
    if (ok <= 0) {
        // ERR packet 或格式错误（_mysql_parse_ok_err 已 set_error）
        if (conn->error_msg[0] == '\0') {
            _mysql_set_error(conn, 0, "authentication failed");
        }
        *dbh = conn;  // 保留 conn 供 driver 读取错误
        return -1;
    }

    // 5. 设置字符集（如果非默认 utf8）
    if (dsn_info.charset[0] != '\0' && strcasecmp(dsn_info.charset, "utf8") != 0
        && strcasecmp(dsn_info.charset, "utf8mb3") != 0) {
        char set_names_sql[64];
        snprintf(set_names_sql, sizeof(set_names_sql), "SET NAMES %s", dsn_info.charset);
        char qbuf[256];
        int qlen;
        int qr = _mysql_send_query(conn, set_names_sql, (int)strlen(set_names_sql),
                                    qbuf, sizeof(qbuf), &qlen);
        if (qr == 1) {
            // 意外返回结果集 — 完整消费（列数 + 列定义 + EOF + 行 + EOF）
            uint64_t cc;
            if (_mysql_read_lenenc_int(qbuf, qlen, &cc) > 0 && cc > 0) {
                mysql_stmt_t tmp;
                memset(&tmp, 0, sizeof(tmp));
                tmp.conn = conn;
                tmp.num_columns = (int)cc;
                for (uint64_t i = 0; i < cc; i++) {
                    char nb[256];
                    _mysql_read_column_def(conn, nb, sizeof(nb));
                }
                if (!(conn->capabilities & CLIENT_DEPRECATE_EOF)) _mysql_skip_eof(conn);
                _mysql_consume_resultset(&tmp);
            }
        }
    }

    *dbh = conn;
    return 0;
}

// ── _pdo_mysql_close: 关闭连接 ──
static void _pdo_mysql_close(void* dbh) {
    if (dbh == NULL) return;
    mysql_conn_t* conn = (mysql_conn_t*)dbh;
    if (conn->fd >= 0) {
        // 发送 COM_QUIT（不要求响应）
        char buf[1];
        buf[0] = (char)COM_QUIT;
        conn->sequence_id = 0;
        _mysql_send_packet(conn, buf, 1);
        STREAM_CLOSE(conn->fd);
        conn->fd = -1;
    }
    free(conn);
}

// ── _pdo_mysql_exec: 执行无结果集 SQL ──
static int _pdo_mysql_exec(void* dbh, const char* sql) {
    if (dbh == NULL || sql == NULL) return -1;
    mysql_conn_t* conn = (mysql_conn_t*)dbh;
    // 在同一连接上发起查询前，必须先消费前一个 stmt 的未消费结果集
    if (conn->active_stmt != NULL) {
        mysql_stmt_t* prev = (mysql_stmt_t*)conn->active_stmt;
        if (prev->executed && !prev->eof_reached && prev->num_columns > 0) {
            _mysql_consume_resultset(prev);
        }
        conn->active_stmt = NULL;
    }
    char resp_buf[4096];
    int resp_len;
    int rc = _mysql_send_query(conn, sql, (int)strlen(sql), resp_buf, sizeof(resp_buf), &resp_len);
    if (rc < 0) return -1;
    if (rc == 0) {
        // OK packet — 返回 affected_rows
        return (int)conn->affected_rows;
    }
    // 结果集 — 消费掉所有行
    // 先解析列数
    uint64_t col_count;
    int n = _mysql_read_lenenc_int(resp_buf, resp_len, &col_count);
    if (n < 0) {
        _mysql_set_error(conn, 0, "invalid column count");
        return -1;
    }
    mysql_stmt_t tmp;
    memset(&tmp, 0, sizeof(tmp));
    tmp.conn = conn;
    tmp.num_columns = (int)col_count;
    // 跳过 column definitions
    for (uint64_t i = 0; i < col_count; i++) {
        char name_buf[256];
        if (_mysql_read_column_def(conn, name_buf, sizeof(name_buf)) != 0) {
            return -1;
        }
    }
    // 跳过 EOF（如果未启用 CLIENT_DEPRECATE_EOF，我们没启用）
    if (!(conn->capabilities & CLIENT_DEPRECATE_EOF)) {
        if (_mysql_skip_eof(conn) != 0) return -1;
    }
    int rows = _mysql_consume_resultset(&tmp);
    // 释放 tmp 的 row_values（如果最后一行被读了）
    if (tmp.row_values != NULL) {
        for (int i = 0; i < tmp.row_value_count; i++) {
            if (tmp.row_values[i] != NULL) free(tmp.row_values[i]);
        }
        free(tmp.row_values);
        free(tmp.row_value_lens);
    }
    return rows;
}

// ── _pdo_mysql_prepare: 预处理（仅存储 SQL 模板和参数数量）──
static int _pdo_mysql_prepare(void* dbh, const char* sql, void** stmt) {
    if (dbh == NULL || sql == NULL || stmt == NULL) return -1;
    mysql_conn_t* conn = (mysql_conn_t*)dbh;
    int sql_len = (int)strlen(sql);
    // 统计 ? 个数（不区分字符串内的 ?，简化处理）
    int num_params = 0;
    int in_single = 0, in_double = 0;
    for (int i = 0; i < sql_len; i++) {
        char c = sql[i];
        if (in_single) {
            if (c == '\'') in_single = 0;
        } else if (in_double) {
            if (c == '"') in_double = 0;
        } else {
            if (c == '\'') in_single = 1;
            else if (c == '"') in_double = 1;
            else if (c == '?') num_params++;
        }
    }
    mysql_stmt_t* s = (mysql_stmt_t*)malloc(sizeof(mysql_stmt_t));
    if (s == NULL) {
        _mysql_set_error(conn, 0, "out of memory");
        return -1;
    }
    memset(s, 0, sizeof(*s));
    s->conn = conn;
    s->sql_template = (char*)malloc(sql_len + 1);
    if (s->sql_template == NULL) {
        free(s);
        _mysql_set_error(conn, 0, "out of memory");
        return -1;
    }
    memcpy(s->sql_template, sql, sql_len);
    s->sql_template[sql_len] = '\0';
    s->sql_template_len = sql_len;
    s->num_params = num_params;
    // 分配参数数组
    if (num_params > 0) {
        s->param_types = (int*)malloc(sizeof(int) * num_params);
        s->param_ints = (int64_t*)malloc(sizeof(int64_t) * num_params);
        s->param_texts = (char**)malloc(sizeof(char*) * num_params);
        s->param_text_lens = (int*)malloc(sizeof(int) * num_params);
        if (s->param_types == NULL || s->param_ints == NULL
            || s->param_texts == NULL || s->param_text_lens == NULL) {
            if (s->param_types) free(s->param_types);
            if (s->param_ints) free(s->param_ints);
            if (s->param_texts) free(s->param_texts);
            if (s->param_text_lens) free(s->param_text_lens);
            free(s->sql_template);
            free(s);
            _mysql_set_error(conn, 0, "out of memory");
            return -1;
        }
        memset(s->param_types, 0, sizeof(int) * num_params);
        for (int i = 0; i < num_params; i++) {
            s->param_texts[i] = NULL;
            s->param_text_lens[i] = 0;
        }
    }
    *stmt = s;
    return 0;
}

// ── 绑定函数 ──
static int _pdo_mysql_bind_int(void* stmt, int idx, int64_t val) {
    if (stmt == NULL || idx < 1) return -1;
    mysql_stmt_t* s = (mysql_stmt_t*)stmt;
    if (idx > s->num_params) return -1;
    int i = idx - 1;
    // 释放之前的 text 值（如果存在）
    if (s->param_texts != NULL && s->param_texts[i] != NULL) {
        free(s->param_texts[i]);
        s->param_texts[i] = NULL;
    }
    s->param_ints[i] = val;
    s->param_types[i] = 1;
    return 0;
}

static int _pdo_mysql_bind_text(void* stmt, int idx, const char* val, int len) {
    if (stmt == NULL || idx < 1 || val == NULL || len < 0) return -1;
    mysql_stmt_t* s = (mysql_stmt_t*)stmt;
    if (idx > s->num_params) return -1;
    int i = idx - 1;
    if (s->param_texts[i] != NULL) free(s->param_texts[i]);
    s->param_texts[i] = (char*)malloc(len + 1);
    if (s->param_texts[i] == NULL) return -1;
    memcpy(s->param_texts[i], val, len);
    s->param_texts[i][len] = '\0';
    s->param_text_lens[i] = len;
    s->param_types[i] = 2;
    return 0;
}

static int _pdo_mysql_bind_blob(void* stmt, int idx, const char* data, int len) {
    // 同 bind_text，类型标记为 blob
    if (stmt == NULL || idx < 1 || data == NULL || len < 0) return -1;
    mysql_stmt_t* s = (mysql_stmt_t*)stmt;
    if (idx > s->num_params) return -1;
    int i = idx - 1;
    if (s->param_texts[i] != NULL) free(s->param_texts[i]);
    s->param_texts[i] = (char*)malloc(len + 1);
    if (s->param_texts[i] == NULL) return -1;
    memcpy(s->param_texts[i], data, len);
    s->param_texts[i][len] = '\0';
    s->param_text_lens[i] = len;
    s->param_types[i] = 3;
    return 0;
}

static int _pdo_mysql_bind_null(void* stmt, int idx) {
    if (stmt == NULL || idx < 1) return -1;
    mysql_stmt_t* s = (mysql_stmt_t*)stmt;
    if (idx > s->num_params) return -1;
    int i = idx - 1;
    if (s->param_texts[i] != NULL) {
        free(s->param_texts[i]);
        s->param_texts[i] = NULL;
    }
    s->param_types[i] = 0;
    return 0;
}

static int _pdo_mysql_bind_param_index(void* stmt, const char* name) {
    // MySQL 文本协议模拟不支持命名参数
    (void)stmt; (void)name;
    return 0;
}

// ── _pdo_mysql_step: 执行查询并返回一行 ──
static int _pdo_mysql_step(void* stmt) {
    if (stmt == NULL) return -1;
    mysql_stmt_t* s = (mysql_stmt_t*)stmt;
    mysql_conn_t* conn = s->conn;
    if (conn == NULL) return -1;

    if (!s->executed) {
        // 首次 step：构造完整 SQL（用参数值替换 ?），发送 COM_QUERY，读取列定义
        s->executed = 1;

        // 在同一连接上发起查询前，必须先消费前一个 stmt 的未消费结果集，
        // 否则 MySQL 协议会不同步（前一个结果集的 EOF/Row packet 会被当作新查询的响应）
        if (conn->active_stmt != NULL && conn->active_stmt != s) {
            mysql_stmt_t* prev = (mysql_stmt_t*)conn->active_stmt;
            if (prev->executed && !prev->eof_reached && prev->num_columns > 0) {
                _mysql_consume_resultset(prev);
            }
            conn->active_stmt = NULL;
        }
        // 估算最终 SQL 长度
        int est_len = s->sql_template_len + 1;
        for (int i = 0; i < s->num_params; i++) {
            switch (s->param_types[i]) {
                case 0: est_len += 4; break;  // "NULL"
                case 1: est_len += 24; break; // int64 最大 20 位 + 余量
                case 2:
                case 3:
                    est_len += s->param_text_lens[i] * 2 + 3; // 转义最坏翻倍 + 引号
                    break;
            }
        }
        char* final_sql = (char*)malloc(est_len);
        if (final_sql == NULL) {
            _mysql_set_error(conn, 0, "out of memory");
            return -1;
        }
        int out_pos = 0;
        int param_idx = 0;
        for (int i = 0; i < s->sql_template_len; i++) {
            char c = s->sql_template[i];
            if (c == '?') {
                // 替换为参数值
                if (param_idx < s->num_params) {
                    switch (s->param_types[param_idx]) {
                        case 0:
                            memcpy(final_sql + out_pos, "NULL", 4);
                            out_pos += 4;
                            break;
                        case 1: {
                            char ibuf[24];
                            int n = snprintf(ibuf, sizeof(ibuf), "%lld", (long long)s->param_ints[param_idx]);
                            memcpy(final_sql + out_pos, ibuf, n);
                            out_pos += n;
                            break;
                        }
                        case 2:
                        case 3: {
                            // 转义并加引号
                            final_sql[out_pos++] = '\'';
                            const char* src = s->param_texts[param_idx];
                            int srclen = s->param_text_lens[param_idx];
                            for (int j = 0; j < srclen; j++) {
                                char ch = src[j];
                                switch (ch) {
                                    case '\'': final_sql[out_pos++] = '\\'; final_sql[out_pos++] = '\''; break;
                                    case '\\': final_sql[out_pos++] = '\\'; final_sql[out_pos++] = '\\'; break;
                                    case '"':  final_sql[out_pos++] = '\\'; final_sql[out_pos++] = '"';  break;
                                    case '\0': final_sql[out_pos++] = '\\'; final_sql[out_pos++] = '0';  break;
                                    case '\n': final_sql[out_pos++] = '\\'; final_sql[out_pos++] = 'n';  break;
                                    case '\r': final_sql[out_pos++] = '\\'; final_sql[out_pos++] = 'r';  break;
                                    case '\x1a': final_sql[out_pos++] = '\\'; final_sql[out_pos++] = 'Z'; break;
                                    default:   final_sql[out_pos++] = ch; break;
                                }
                            }
                            final_sql[out_pos++] = '\'';
                            break;
                        }
                    }
                    param_idx++;
                } else {
                    final_sql[out_pos++] = '?';  // 参数不足，保留 ?
                }
            } else {
                final_sql[out_pos++] = c;
            }
        }
        final_sql[out_pos] = '\0';

        // 发送 COM_QUERY
        char resp_buf[4096];
        int resp_len;
        int rc = _mysql_send_query(conn, final_sql, out_pos,
                                    resp_buf, sizeof(resp_buf), &resp_len);
        free(final_sql);
        if (rc < 0) return -1;
        if (rc == 0) {
            // OK packet — 无结果集（INSERT/UPDATE/DELETE）
            s->num_columns = 0;
            s->eof_reached = 1;
            return PDO_STEP_DONE;
        }
        // 结果集：解析列数
        uint64_t col_count;
        int n = _mysql_read_lenenc_int(resp_buf, resp_len, &col_count);
        if (n < 0) {
            _mysql_set_error(conn, 0, "invalid column count");
            return -1;
        }
        s->num_columns = (int)col_count;
        // 读取 column definitions
        if (s->num_columns > 0) {
            s->column_names = (char**)malloc(sizeof(char*) * s->num_columns);
            s->column_name_lens = (int*)malloc(sizeof(int) * s->num_columns);
            if (s->column_names == NULL || s->column_name_lens == NULL) {
                _mysql_set_error(conn, 0, "out of memory");
                return -1;
            }
            for (int i = 0; i < s->num_columns; i++) {
                char name_buf[256];
                if (_mysql_read_column_def(conn, name_buf, sizeof(name_buf)) != 0) {
                    return -1;
                }
                int nl = (int)strlen(name_buf);
                char* dup = (char*)malloc(nl + 1);
                if (dup == NULL) {
                    _mysql_set_error(conn, 0, "out of memory");
                    return -1;
                }
                memcpy(dup, name_buf, nl);
                dup[nl] = '\0';
                s->column_names[i] = dup;
                s->column_name_lens[i] = nl;
            }
        }
        // 跳过列定义后的 EOF
        if (!(conn->capabilities & CLIENT_DEPRECATE_EOF)) {
            if (_mysql_skip_eof(conn) != 0) return -1;
        }
        // 读取第一行
        int row_rc = _mysql_read_row(s);
        if (row_rc < 0) return -1;
        if (row_rc == 0) {
            // 空结果集
            s->eof_reached = 1;
            return PDO_STEP_DONE;
        }
        // 标记当前 stmt 为活动 stmt（有未消费结果集）
        conn->active_stmt = s;
        return PDO_STEP_ROW;
    }
    // 后续 step：读取下一行
    if (s->eof_reached) return PDO_STEP_DONE;
    int rc = _mysql_read_row(s);
    if (rc < 0) return -1;
    if (rc == 0) {
        // EOF — 结果集已读完，清除 active_stmt
        if (conn->active_stmt == s) conn->active_stmt = NULL;
        return PDO_STEP_DONE;
    }
    return PDO_STEP_ROW;
}

// ── _pdo_mysql_reset: 重置语句 ──
static int _pdo_mysql_reset(void* stmt) {
    if (stmt == NULL) return -1;
    mysql_stmt_t* s = (mysql_stmt_t*)stmt;
    // 如果已执行且有结果集未消费完，必须消费剩余行（包括 EOF packet）
    // 否则连接会不同步，下一次查询会读到遗留的 packet
    if (s->executed && !s->eof_reached && s->num_columns > 0) {
        _mysql_consume_resultset(s);
    }
    // 清除连接上的 active_stmt 引用（如果指向本 stmt）
    if (s->conn != NULL && s->conn->active_stmt == s) {
        s->conn->active_stmt = NULL;
    }
    // 释放当前行数据
    if (s->row_values != NULL) {
        for (int i = 0; i < s->row_value_count; i++) {
            if (s->row_values[i] != NULL) free(s->row_values[i]);
        }
        free(s->row_values);
        s->row_values = NULL;
    }
    if (s->row_value_lens != NULL) {
        free(s->row_value_lens);
        s->row_value_lens = NULL;
    }
    s->row_value_count = 0;
    s->eof_reached = 0;
    s->executed = 0;
    return 0;
}

// ── _pdo_mysql_clear_bindings: 清除绑定 ──
static int _pdo_mysql_clear_bindings(void* stmt) {
    if (stmt == NULL) return -1;
    mysql_stmt_t* s = (mysql_stmt_t*)stmt;
    for (int i = 0; i < s->num_params; i++) {
        if (s->param_texts != NULL && s->param_texts[i] != NULL) {
            free(s->param_texts[i]);
            s->param_texts[i] = NULL;
        }
        s->param_text_lens[i] = 0;
        s->param_types[i] = 0;
    }
    return 0;
}

// ── _pdo_mysql_finalize: 释放语句 ──
static int _pdo_mysql_finalize(void* stmt) {
    if (stmt == NULL) return 0;
    mysql_stmt_t* s = (mysql_stmt_t*)stmt;
    // 消费剩余结果集（同 reset，防止连接不同步）
    if (s->executed && !s->eof_reached && s->num_columns > 0) {
        _mysql_consume_resultset(s);
    }
    // 清除连接上的 active_stmt 引用（如果指向本 stmt）
    if (s->conn != NULL && s->conn->active_stmt == s) {
        s->conn->active_stmt = NULL;
    }
    // 释放 SQL 模板
    if (s->sql_template != NULL) free(s->sql_template);
    // 释放参数数组
    if (s->param_types != NULL) free(s->param_types);
    if (s->param_ints != NULL) free(s->param_ints);
    if (s->param_texts != NULL) {
        for (int i = 0; i < s->num_params; i++) {
            if (s->param_texts[i] != NULL) free(s->param_texts[i]);
        }
        free(s->param_texts);
    }
    if (s->param_text_lens != NULL) free(s->param_text_lens);
    // 释放列名
    if (s->column_names != NULL) {
        for (int i = 0; i < s->num_columns; i++) {
            if (s->column_names[i] != NULL) free(s->column_names[i]);
        }
        free(s->column_names);
    }
    if (s->column_name_lens != NULL) free(s->column_name_lens);
    // 释放当前行
    if (s->row_values != NULL) {
        for (int i = 0; i < s->row_value_count; i++) {
            if (s->row_values[i] != NULL) free(s->row_values[i]);
        }
        free(s->row_values);
    }
    if (s->row_value_lens != NULL) free(s->row_value_lens);
    free(s);
    return 0;
}

// ── 列信息函数 ──
static int _pdo_mysql_column_count(void* stmt) {
    if (stmt == NULL) return 0;
    return ((mysql_stmt_t*)stmt)->num_columns;
}

static int _pdo_mysql_column_type(void* stmt, int col) {
    if (stmt == NULL) return PDO_COL_NULL;
    mysql_stmt_t* s = (mysql_stmt_t*)stmt;
    if (col < 0 || col >= s->num_columns) return PDO_COL_NULL;
    // MySQL 文本协议所有列都是字符串
    // NULL 值用 PDO_COL_NULL 表示
    if (s->row_value_lens != NULL && col < s->row_value_count) {
        if (s->row_value_lens[col] < 0) return PDO_COL_NULL;
    }
    return PDO_COL_TEXT;
}

static int64_t _pdo_mysql_column_int64(void* stmt, int col) {
    const char* s = _pdo_mysql_column_text(stmt, col);
    if (s == NULL) return 0;
    return (int64_t)strtoll(s, NULL, 10);
}

static double _pdo_mysql_column_double(void* stmt, int col) {
    const char* s = _pdo_mysql_column_text(stmt, col);
    if (s == NULL) return 0.0;
    return strtod(s, NULL);
}

static const char* _pdo_mysql_column_text(void* stmt, int col) {
    if (stmt == NULL) return NULL;
    mysql_stmt_t* s = (mysql_stmt_t*)stmt;
    if (col < 0 || col >= s->num_columns) return NULL;
    if (s->row_values == NULL || col >= s->row_value_count) return NULL;
    const char* r = s->row_values[col];
    return r;  // NULL 表示 SQL NULL
}

static int _pdo_mysql_column_bytes(void* stmt, int col) {
    if (stmt == NULL) return 0;
    mysql_stmt_t* s = (mysql_stmt_t*)stmt;
    if (col < 0 || col >= s->num_columns) return 0;
    if (s->row_value_lens == NULL || col >= s->row_value_count) return 0;
    int len = s->row_value_lens[col];
    return len < 0 ? 0 : len;
}

static const char* _pdo_mysql_column_name(void* stmt, int col) {
    if (stmt == NULL) return NULL;
    mysql_stmt_t* s = (mysql_stmt_t*)stmt;
    if (col < 0 || col >= s->num_columns) return NULL;
    const char* r = s->column_names[col];
    return r;
}

static const char* _pdo_mysql_column_decltype(void* stmt, int col) {
    // 文本协议无法获取声明类型，返回 NULL
    (void)stmt; (void)col;
    return NULL;
}

static int _pdo_mysql_data_count(void* stmt) {
    if (stmt == NULL) return 0;
    mysql_stmt_t* s = (mysql_stmt_t*)stmt;
    return s->row_value_count;
}

// ── 连接信息函数 ──
static int64_t _pdo_mysql_changes(void* dbh) {
    if (dbh == NULL) return 0;
    return ((mysql_conn_t*)dbh)->affected_rows;
}

static int64_t _pdo_mysql_last_insert_rowid(void* dbh) {
    if (dbh == NULL) return 0;
    return ((mysql_conn_t*)dbh)->last_insert_id;
}

static int _pdo_mysql_errcode(void* dbh) {
    if (dbh == NULL) return 0;
    return ((mysql_conn_t*)dbh)->error_code;
}

static const char* _pdo_mysql_errmsg(void* dbh) {
    if (dbh == NULL) return "no database connection";
    return ((mysql_conn_t*)dbh)->error_msg;
}

static int _pdo_mysql_busy_timeout(void* dbh, int ms) {
    if (dbh == NULL) return -1;
    mysql_conn_t* conn = (mysql_conn_t*)dbh;
    if (conn->fd < 0) return -1;
#ifdef _WIN32
    DWORD to_ms = (DWORD)ms;
    setsockopt((SOCKET)conn->fd, SOL_SOCKET, SO_RCVTIMEO, (const char*)&to_ms, sizeof(to_ms));
    setsockopt((SOCKET)conn->fd, SOL_SOCKET, SO_SNDTIMEO, (const char*)&to_ms, sizeof(to_ms));
#else
    struct timeval tv;
    tv.tv_sec = ms / 1000;
    tv.tv_usec = (ms % 1000) * 1000;
    setsockopt(conn->fd, SOL_SOCKET, SO_RCVTIMEO, (const char*)&tv, sizeof(tv));
    setsockopt(conn->fd, SOL_SOCKET, SO_SNDTIMEO, (const char*)&tv, sizeof(tv));
#endif
    return 0;
}

static void _pdo_mysql_extended_result_codes(void* dbh, int on) {
    // MySQL 没有扩展结果码，空操作
    (void)dbh; (void)on;
}

// ── 转义 ──
static char* _pdo_mysql_quote(const char* s) {
    if (s == NULL) return NULL;
    int len = (int)strlen(s);
    char* out = (char*)malloc(len * 2 + 3);  // 最坏每字符翻倍 + 2 引号 + \0
    if (out == NULL) return NULL;
    out[0] = '\'';
    int j = 1;
    for (int i = 0; i < len; i++) {
        switch (s[i]) {
            case '\'':  out[j++] = '\\'; out[j++] = '\''; break;
            case '\\':  out[j++] = '\\'; out[j++] = '\\'; break;
            case '"':   out[j++] = '\\'; out[j++] = '"';  break;
            case '\0':  out[j++] = '\\'; out[j++] = '0';  break;
            case '\n':  out[j++] = '\\'; out[j++] = 'n';  break;
            case '\r':  out[j++] = '\\'; out[j++] = 'r';  break;
            case '\x1a': out[j++] = '\\'; out[j++] = 'Z'; break;
            default:    out[j++] = s[i]; break;
        }
    }
    out[j++] = '\'';
    out[j] = '\0';
    return out;
}

static void _pdo_mysql_free_quote(char* s) {
    if (s != NULL) free(s);
}

// ── 驱动元信息 ──
static const char* _pdo_mysql_driver_name(void) {
    return "mysql";
}

// server_version 是实例属性（依赖 dbh），从连接中读取
static const char* _pdo_mysql_server_version(void* dbh) {
    if (dbh == NULL) return "unknown";
    mysql_conn_t* conn = (mysql_conn_t*)dbh;
    return conn->server_version;
}

// ── MySQL 驱动实例（函数指针表）──
static const pdo_driver_t pdo_mysql_driver = {
    .name                 = "mysql",
    .open                 = _pdo_mysql_open,
    .close                = _pdo_mysql_close,
    .exec                 = _pdo_mysql_exec,
    .prepare              = _pdo_mysql_prepare,
    .bind_int             = _pdo_mysql_bind_int,
    .bind_text            = _pdo_mysql_bind_text,
    .bind_blob            = _pdo_mysql_bind_blob,
    .bind_null            = _pdo_mysql_bind_null,
    .bind_param_index     = _pdo_mysql_bind_param_index,
    .step                 = _pdo_mysql_step,
    .reset                = _pdo_mysql_reset,
    .clear_bindings       = _pdo_mysql_clear_bindings,
    .finalize              = _pdo_mysql_finalize,
    .column_count         = _pdo_mysql_column_count,
    .column_type          = _pdo_mysql_column_type,
    .column_int64         = _pdo_mysql_column_int64,
    .column_double        = _pdo_mysql_column_double,
    .column_text          = _pdo_mysql_column_text,
    .column_bytes         = _pdo_mysql_column_bytes,
    .column_name          = _pdo_mysql_column_name,
    .column_decltype      = _pdo_mysql_column_decltype,
    .data_count           = _pdo_mysql_data_count,
    .changes              = _pdo_mysql_changes,
    .last_insert_rowid    = _pdo_mysql_last_insert_rowid,
    .errcode              = _pdo_mysql_errcode,
    .errmsg               = _pdo_mysql_errmsg,
    .busy_timeout         = _pdo_mysql_busy_timeout,
    .extended_result_codes = _pdo_mysql_extended_result_codes,
    .quote                = _pdo_mysql_quote,
    .free_quote           = _pdo_mysql_free_quote,
    .driver_name          = _pdo_mysql_driver_name,
    .server_version       = _pdo_mysql_server_version,
};

// ── 注册 MySQL 驱动 ──
//   constructor + static 在部分 TCC 版本下会被死代码消除，
//   因此同时提供 constructor 和显式注册函数（PHP 层调用 pdo_mysql_init()）
__attribute__((constructor))
static void _pdo_mysql_register(void) {
    pdo_register_driver(&pdo_mysql_driver);
}

// 显式注册（供 PHP 层调用，确保跨编译器一致）
//   返回 1 表示注册成功（或已注册），0 表示失败
static inline int tphp_fn_pdo_mysql_init(void) {
    pdo_register_driver(&pdo_mysql_driver);
    return 1;
}
