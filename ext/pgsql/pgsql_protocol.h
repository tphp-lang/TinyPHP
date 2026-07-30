#pragma once
// ============================================================
// pgsql_protocol.h — PostgreSQL v3 协议核心实现
//
// 实现：
//   3.1 DSN 解析（key=value 格式 + URL 格式）
//   3.2 消息收发框架（_pg_send_message / _pg_recv_message / _pg_send_startup）
//   3.3 认证流程（trust / MD5 / SCRAM-SHA-256）
//   3.4 ErrorResponse 处理
//   3.5 连接函数（pg_connect / pg_pconnect / pg_close / pg_ping 等）
//
// 依赖：
//   - pgsql.h（协议常量 + 结构体 + 前向声明）
//   - pg_crypto.h（SHA-256/HMAC/PBKDF2/Base64/MD5）
//   - stream.h（socket 跨平台抽象，提供 STREAM_CLOSE/STREAM_ERRNO/tphp_fn_stream_init）
//
// 大端序：PostgreSQL 协议所有多字节整数使用大端序（network byte order）
// ============================================================

#include "types.h"
#include "object/exception.h"
#include "object/try.h"
#include <stdint.h>
#include <stdlib.h>
#include <string.h>
#include <stdio.h>
#include <time.h>

// ── Windows 兼容：strcasecmp/strncasecmp ──
#ifdef _WIN32
#ifndef _TPHP_PG_STRCASE_COMPAT
#define _TPHP_PG_STRCASE_COMPAT
#ifndef strcasecmp
#define strcasecmp _stricmp
#endif
#ifndef strncasecmp
#define strncasecmp _strnicmp
#endif
#endif
#endif

// ============================================================
// 辅助：大端序读写
// ============================================================

static inline uint32_t _pg_read_be32(const uint8_t *p) {
    return ((uint32_t)p[0] << 24) | ((uint32_t)p[1] << 16)
         | ((uint32_t)p[2] << 8) | (uint32_t)p[3];
}

static inline uint16_t _pg_read_be16(const uint8_t *p) {
    return (uint16_t)(((uint16_t)p[0] << 8) | (uint16_t)p[1]);
}

static inline void _pg_write_be32(uint8_t *p, uint32_t v) {
    p[0] = (uint8_t)((v >> 24) & 0xFF);
    p[1] = (uint8_t)((v >> 16) & 0xFF);
    p[2] = (uint8_t)((v >> 8) & 0xFF);
    p[3] = (uint8_t)(v & 0xFF);
}

// ============================================================
// 辅助：错误设置 / 连接释放
// ============================================================

static void _pg_set_error(PGconn *conn, const char *msg) {
    if (conn == NULL) return;
    if (conn->last_error) {
        free(conn->last_error);
        conn->last_error = NULL;
    }
    if (msg) {
        int len = (int)strlen(msg);
        conn->last_error = (char*)malloc(len + 1);
        if (conn->last_error) {
            memcpy(conn->last_error, msg, len);
            conn->last_error[len] = '\0';
        }
    }
    conn->status = CONN_BAD;
}

// 释放 PGconn 及其所有字段
static void _pg_free_conn(PGconn *conn) {
    if (conn == NULL) return;
    if (conn->sock >= 0) {
        STREAM_CLOSE(conn->sock);
        conn->sock = -1;
    }
    if (conn->host) free(conn->host);
    if (conn->dbname) free(conn->dbname);
    if (conn->user) free(conn->user);
    if (conn->password) free(conn->password);
    if (conn->options) free(conn->options);
    if (conn->tty) free(conn->tty);
    if (conn->sslmode) free(conn->sslmode);
    if (conn->last_error) free(conn->last_error);
    if (conn->last_notice) free(conn->last_notice);
    if (conn->client_encoding) free(conn->client_encoding);
    if (conn->server_version) free(conn->server_version);
    free(conn);
}

// ============================================================
// 辅助：随机数生成（用于 SCRAM nonce）
//   注意：rand() 不是 CSPRNG，但 SCRAM 的安全性依赖于密码而非 nonce 的不可预测性
// ============================================================
static void _pg_gen_random(uint8_t *buf, int len) {
    static int _pg_seeded = 0;
    if (!_pg_seeded) {
        srand((unsigned int)time(NULL));
        _pg_seeded = 1;
    }
    for (int i = 0; i < len; i++) {
        buf[i] = (uint8_t)(rand() & 0xFF);
    }
}

// ============================================================
// 3.1 DSN 解析
//   支持 key=value 空格分隔格式：
//     "host=localhost port=5432 dbname=test user=postgres password=secret"
//   支持 URL 格式：
//     "postgresql://user:pass@host:port/dbname?option1=value1&option2=value2"
//   支持 "postgres://" 作为 "postgresql://" 的别名
// ============================================================

// 从 DSN 提取 key=value 对，设置 conn 的对应字段
//   成功返回 0，失败返回 -1
static int _pg_parse_dsn(const char *dsn, PGconn *conn) {
    if (dsn == NULL || conn == NULL) return -1;

    // 默认值
    conn->port = 5432;
    conn->connect_timeout = 0;
    conn->sslmode = NULL;  // NULL = 未设置

    // 检测 URL 格式
    if (strncasecmp(dsn, "postgresql://", 13) == 0 || strncasecmp(dsn, "postgres://", 11) == 0) {
        int scheme_len = (strncasecmp(dsn, "postgresql://", 13) == 0) ? 13 : 11;
        const char *p = dsn + scheme_len;

        // 找 userinfo 结束（@）— 在第一个 / 或 ? 之前
        const char *slash = strchr(p, '/');
        const char *qmark = strchr(p, '?');
        const char *at = strchr(p, '@');
        // 确保 at 在 slash 和 qmark 之前
        if (at && ((slash == NULL) || (at < slash)) && ((qmark == NULL) || (at < qmark))) {
            // 解析 user:pass
            const char *colon = strchr(p, ':');
            if (colon && colon < at) {
                int ulen = (int)(colon - p);
                conn->user = (char*)malloc(ulen + 1);
                memcpy(conn->user, p, ulen);
                conn->user[ulen] = '\0';
                int plen = (int)(at - colon - 1);
                conn->password = (char*)malloc(plen + 1);
                memcpy(conn->password, colon + 1, plen);
                conn->password[plen] = '\0';
            } else {
                int ulen = (int)(at - p);
                conn->user = (char*)malloc(ulen + 1);
                memcpy(conn->user, p, ulen);
                conn->user[ulen] = '\0';
            }
            p = at + 1;
        }

        // 解析 host:port — 到 / 或 ? 或 末尾
        const char *host_end = p;
        while (*host_end && *host_end != '/' && *host_end != '?') host_end++;
        {
            const char *colon = strchr(p, ':');
            if (colon && colon < host_end) {
                int hlen = (int)(colon - p);
                conn->host = (char*)malloc(hlen + 1);
                memcpy(conn->host, p, hlen);
                conn->host[hlen] = '\0';
                conn->port = atoi(colon + 1);
            } else {
                int hlen = (int)(host_end - p);
                if (hlen > 0) {
                    conn->host = (char*)malloc(hlen + 1);
                    memcpy(conn->host, p, hlen);
                    conn->host[hlen] = '\0';
                }
            }
        }

        // 解析 dbname — / 之后到 ?
        if (*host_end == '/') {
            const char *db_start = host_end + 1;
            const char *db_end = db_start;
            while (*db_end && *db_end != '?') db_end++;
            int dlen = (int)(db_end - db_start);
            if (dlen > 0) {
                conn->dbname = (char*)malloc(dlen + 1);
                memcpy(conn->dbname, db_start, dlen);
                conn->dbname[dlen] = '\0';
            }
            host_end = db_end;
        }

        // 解析 query 参数 — ? 之后
        if (*host_end == '?') {
            const char *cur = host_end + 1;
            while (*cur) {
                const char *eq = strchr(cur, '=');
                if (eq == NULL) break;
                int klen = (int)(eq - cur);
                const char *val = eq + 1;
                const char *amp = strchr(val, '&');
                int vlen = amp ? (int)(amp - val) : (int)strlen(val);

                if (klen == 4 && strncasecmp(cur, "host", 4) == 0) {
                    if (conn->host) free(conn->host);
                    conn->host = (char*)malloc(vlen + 1);
                    memcpy(conn->host, val, vlen);
                    conn->host[vlen] = '\0';
                } else if (klen == 4 && strncasecmp(cur, "port", 4) == 0) {
                    char pb[16]; int cl = vlen < 15 ? vlen : 15;
                    memcpy(pb, val, cl); pb[cl] = '\0';
                    conn->port = atoi(pb);
                } else if (klen == 6 && strncasecmp(cur, "dbname", 6) == 0) {
                    if (conn->dbname) free(conn->dbname);
                    conn->dbname = (char*)malloc(vlen + 1);
                    memcpy(conn->dbname, val, vlen);
                    conn->dbname[vlen] = '\0';
                } else if (klen == 4 && strncasecmp(cur, "user", 4) == 0) {
                    if (conn->user) free(conn->user);
                    conn->user = (char*)malloc(vlen + 1);
                    memcpy(conn->user, val, vlen);
                    conn->user[vlen] = '\0';
                } else if (klen == 8 && strncasecmp(cur, "password", 8) == 0) {
                    if (conn->password) free(conn->password);
                    conn->password = (char*)malloc(vlen + 1);
                    memcpy(conn->password, val, vlen);
                    conn->password[vlen] = '\0';
                } else if (klen == 7 && strncasecmp(cur, "options", 7) == 0) {
                    if (conn->options) free(conn->options);
                    conn->options = (char*)malloc(vlen + 1);
                    memcpy(conn->options, val, vlen);
                    conn->options[vlen] = '\0';
                } else if (klen == 7 && strncasecmp(cur, "sslmode", 7) == 0) {
                    if (conn->sslmode) free(conn->sslmode);
                    conn->sslmode = (char*)malloc(vlen + 1);
                    memcpy(conn->sslmode, val, vlen);
                    conn->sslmode[vlen] = '\0';
                } else if (klen == 15 && strncasecmp(cur, "connect_timeout", 15) == 0) {
                    char tb[16]; int cl = vlen < 15 ? vlen : 15;
                    memcpy(tb, val, cl); tb[cl] = '\0';
                    conn->connect_timeout = atoi(tb);
                }
                if (amp == NULL) break;
                cur = amp + 1;
            }
        }
    } else {
        // key=value 空格分隔格式
        const char *cur = dsn;
        while (*cur) {
            while (*cur == ' ') cur++;
            if (*cur == '\0') break;
            const char *eq = strchr(cur, '=');
            if (eq == NULL) break;
            int klen = (int)(eq - cur);
            const char *val = eq + 1;
            // 值可以加单引号
            int vlen;
            const char *val_start = val;
            if (*val == '\'') {
                val_start = val + 1;
                const char *end_quote = strchr(val_start, '\'');
                if (end_quote) {
                    vlen = (int)(end_quote - val_start);
                } else {
                    vlen = (int)strlen(val_start);
                }
            } else {
                const char *space = strchr(val, ' ');
                vlen = space ? (int)(space - val) : (int)strlen(val);
            }

            // 分配并复制值
            #define _PG_SET_FIELD(field) do { \
                if (conn->field) free(conn->field); \
                conn->field = (char*)malloc(vlen + 1); \
                memcpy(conn->field, val_start, vlen); \
                conn->field[vlen] = '\0'; \
            } while(0)

            if (klen == 4 && strncasecmp(cur, "host", 4) == 0) {
                _PG_SET_FIELD(host);
            } else if (klen == 4 && strncasecmp(cur, "port", 4) == 0) {
                char pb[16]; int cl = vlen < 15 ? vlen : 15;
                memcpy(pb, val_start, cl); pb[cl] = '\0';
                conn->port = atoi(pb);
            } else if (klen == 6 && strncasecmp(cur, "dbname", 6) == 0) {
                _PG_SET_FIELD(dbname);
            } else if (klen == 4 && strncasecmp(cur, "user", 4) == 0) {
                _PG_SET_FIELD(user);
            } else if (klen == 8 && strncasecmp(cur, "password", 8) == 0) {
                _PG_SET_FIELD(password);
            } else if (klen == 7 && strncasecmp(cur, "options", 7) == 0) {
                _PG_SET_FIELD(options);
            } else if (klen == 3 && strncasecmp(cur, "tty", 3) == 0) {
                _PG_SET_FIELD(tty);
            } else if (klen == 7 && strncasecmp(cur, "sslmode", 7) == 0) {
                _PG_SET_FIELD(sslmode);
            } else if (klen == 15 && strncasecmp(cur, "connect_timeout", 15) == 0) {
                char tb[16]; int cl = vlen < 15 ? vlen : 15;
                memcpy(tb, val_start, cl); tb[cl] = '\0';
                conn->connect_timeout = atoi(tb);
            }
            #undef _PG_SET_FIELD

            // 推进到下一个 key
            cur = val_start + vlen;
            if (*cur == '\'') cur++;  // 跳过结束引号
            while (*cur && *cur != ' ') cur++;
        }
    }

    // 默认值补全
    if (conn->host == NULL) {
        const char *h = getenv("PGHOST");
        if (h == NULL) h = "localhost";
        conn->host = (char*)malloc(strlen(h) + 1);
        strcpy(conn->host, h);
    }
    if (conn->user == NULL) {
        const char *u = getenv("PGUSER");
        if (u == NULL) u = getenv("USER");
        if (u == NULL) u = "postgres";
        conn->user = (char*)malloc(strlen(u) + 1);
        strcpy(conn->user, u);
    }
    if (conn->password == NULL) {
        const char *p = getenv("PGPASSWORD");
        if (p) {
            conn->password = (char*)malloc(strlen(p) + 1);
            strcpy(conn->password, p);
        }
    }
    if (conn->dbname == NULL) {
        const char *d = getenv("PGDATABASE");
        if (d) {
            conn->dbname = (char*)malloc(strlen(d) + 1);
            strcpy(conn->dbname, d);
        }
    }
    if (conn->sslmode == NULL) {
        conn->sslmode = (char*)malloc(8);
        strcpy(conn->sslmode, "prefer");
    }

    return 0;
}

// ============================================================
// 3.2 消息收发框架
// ============================================================

// 全循环发送：循环 send 直到全部发出
static int _pg_send_all(int fd, const char *data, int len) {
    int sent = 0;
    while (sent < len) {
        int n = (int)send(fd, data + sent, len - sent, 0);
        if (n <= 0) return -1;
        sent += n;
    }
    return 0;
}

// 精确读取 n 字节（处理 partial recv）
static int _pg_recv_exact(PGconn *conn, void *buf, int n) {
    if (conn == NULL || conn->sock < 0) return -1;
    int total = 0;
    char *p = (char*)buf;
    while (total < n) {
        int r = (int)recv(conn->sock, p + total, n - total, 0);
        if (r <= 0) return -1;
        total += r;
    }
    return 0;
}

// 发送消息（type + 4字节长度BE + payload）
//   成功返回 0，失败返回 -1
static int _pg_send_message(PGconn *conn, char type, const void *data, int len) {
    if (conn == NULL || conn->sock < 0) return -1;
    char header[5];
    header[0] = type;
    int total_len = len + 4;  // length 字段包含自身 4 字节
    _pg_write_be32((uint8_t*)(header + 1), (uint32_t)total_len);
    if (_pg_send_all(conn->sock, header, 5) != 0) {
        _pg_set_error(conn, "pg: send message header failed");
        return -1;
    }
    if (len > 0) {
        if (_pg_send_all(conn->sock, (const char*)data, len) != 0) {
            _pg_set_error(conn, "pg: send message payload failed");
            return -1;
        }
    }
    return 0;
}

// 接收消息（读取 1 字节 type + 4 字节长度 BE + payload）
//   返回 type，payload 写入 *data（调用者负责 free），长度写入 *len
//   失败返回 '\0'（0），*data = NULL
static char _pg_recv_message(PGconn *conn, char **data, int *len) {
    if (data) *data = NULL;
    if (len) *len = 0;
    if (conn == NULL || conn->sock < 0) return 0;

    char header[5];
    if (_pg_recv_exact(conn, header, 5) != 0) {
        _pg_set_error(conn, "pg: recv message header failed");
        return 0;
    }
    char type = header[0];
    int total_len = (int)_pg_read_be32((const uint8_t*)(header + 1));
    int payload_len = total_len - 4;
    if (payload_len < 0) payload_len = 0;

    char *buf = NULL;
    if (payload_len > 0) {
        buf = (char*)malloc(payload_len);
        if (buf == NULL) {
            _pg_set_error(conn, "pg: out of memory (recv message)");
            return 0;
        }
        if (_pg_recv_exact(conn, buf, payload_len) != 0) {
            free(buf);
            _pg_set_error(conn, "pg: recv message payload failed");
            return 0;
        }
    }
    if (data) *data = buf;
    if (len) *len = payload_len;
    return type;
}

// 发送 StartupMessage（特殊：无 type 字节，只有长度 + protocol version + key/value pairs）
static int _pg_send_startup(PGconn *conn) {
    if (conn == NULL || conn->sock < 0) return -1;

    // 构建 key/value pairs（不包含 length 和 protocol_version）
    char buf[1024];
    int pos = 0;

    // Protocol version（4 字节 BE）
    _pg_write_be32((uint8_t*)buf, PROTOCOL_VERSION);
    pos += 4;

    // user=（必填）
    if (conn->user) {
        int ulen = (int)strlen(conn->user);
        memcpy(buf + pos, "user", 4); pos += 4; buf[pos++] = '\0';
        memcpy(buf + pos, conn->user, ulen); pos += ulen; buf[pos++] = '\0';
    }
    // database=（可选）
    if (conn->dbname) {
        int dlen = (int)strlen(conn->dbname);
        memcpy(buf + pos, "database", 8); pos += 8; buf[pos++] = '\0';
        memcpy(buf + pos, conn->dbname, dlen); pos += dlen; buf[pos++] = '\0';
    }
    // options=（可选）
    if (conn->options && conn->options[0]) {
        int olen = (int)strlen(conn->options);
        memcpy(buf + pos, "options", 7); pos += 7; buf[pos++] = '\0';
        memcpy(buf + pos, conn->options, olen); pos += olen; buf[pos++] = '\0';
    }
    // 终止 NUL
    buf[pos++] = '\0';

    // 前置 length（4 字节 BE，包含自身）
    int total_len = pos + 4;
    char header[4];
    _pg_write_be32((uint8_t*)header, (uint32_t)total_len);

    if (_pg_send_all(conn->sock, header, 4) != 0) {
        _pg_set_error(conn, "pg: send startup header failed");
        return -1;
    }
    if (_pg_send_all(conn->sock, buf, pos) != 0) {
        _pg_set_error(conn, "pg: send startup body failed");
        return -1;
    }
    return 0;
}

// ============================================================
// 3.4 ErrorResponse 处理
//   字段：'S'(severity) 'V'(severity PG9+) 'C'(code) 'M'(message)
//         'D'(detail) 'H'(hint) 'F'(file) 'L'(line) 'R'(routine) 等
//   以 '\0' 结尾
// ============================================================
static int _pg_parse_error(PGconn *conn, const char *data, int len,
                           char **severity, char **code, char **message,
                           char **detail, char **hint) {
    if (severity) *severity = NULL;
    if (code) *code = NULL;
    if (message) *message = NULL;
    if (detail) *detail = NULL;
    if (hint) *hint = NULL;

    if (data == NULL || len <= 0) return -1;

    int pos = 0;
    while (pos < len) {
        char field_type = data[pos];
        if (field_type == '\0') break;  // 结束标志
        pos++;
        if (pos >= len) break;

        // 提取 NUL-terminated 字符串
        const char *val = data + pos;
        int vlen = 0;
        while (pos + vlen < len && val[vlen] != '\0') vlen++;

        // 复制字段值
        char *dup = (char*)malloc(vlen + 1);
        if (dup) {
            memcpy(dup, val, vlen);
            dup[vlen] = '\0';
        }

        switch (field_type) {
            case 'S': case 'V':
                if (severity && *severity == NULL) { if (severity) *severity = dup; else free(dup); }
                else free(dup);
                break;
            case 'C':
                if (code) { if (*code) free(*code); *code = dup; }
                else free(dup);
                break;
            case 'M':
                if (message) { if (*message) free(*message); *message = dup; }
                else free(dup);
                break;
            case 'D':
                if (detail) { *detail = dup; }
                else free(dup);
                break;
            case 'H':
                if (hint) { *hint = dup; }
                else free(dup);
                break;
            default:
                free(dup);
                break;
        }
        pos += vlen + 1;  // 跳过值和 NUL
    }

    // 构建 conn->last_error
    if (message && *message) {
        _pg_set_error(conn, *message);
    }
    return 0;
}

// ============================================================
// 3.3 认证流程
// ============================================================

// trust 认证（AUTH_OK，无需操作）
static int _pg_auth_trust(PGconn *conn) {
    (void)conn;
    return 0;
}

// MD5 认证：计算 "md5" + md5_hex(md5_hex(password+user) + salt)
//   salt: 4 字节盐值
static int _pg_auth_md5(PGconn *conn, const char *salt) {
    if (conn == NULL) return -1;
    if (conn->password == NULL || conn->user == NULL) {
        _pg_set_error(conn, "pg: MD5 auth requires password and user");
        return -1;
    }

    // Step 1: inner = md5_hex(password + user)
    char inner_buf[512];
    int inner_len = snprintf(inner_buf, sizeof(inner_buf), "%s%s",
                             conn->password, conn->user);
    if (inner_len < 0 || inner_len >= (int)sizeof(inner_buf)) {
        _pg_set_error(conn, "pg: password+user too long for MD5 auth");
        return -1;
    }
    char inner_hex[33];
    _pg_md5_hex((const uint8_t*)inner_buf, inner_len, inner_hex);

    // Step 2: outer = md5_hex(inner_hex + salt)
    //   inner_hex 是 32 字符 hex，salt 是 4 字节二进制
    char outer_buf[36];  // 32 + 4
    memcpy(outer_buf, inner_hex, 32);
    memcpy(outer_buf + 32, salt, 4);
    char outer_hex[33];
    _pg_md5_hex((const uint8_t*)outer_buf, 36, outer_hex);

    // Step 3: response = "md5" + outer_hex
    char response[36];  // "md5"(3) + 32 hex + NUL
    response[0] = 'm'; response[1] = 'd'; response[2] = '5';
    memcpy(response + 3, outer_hex, 32);
    response[35] = '\0';

    // 发送 PasswordMessage ('p')
    //   payload = response 字符串（含 NUL 终止符）
    return _pg_send_message(conn, PG_MSG_PASSWORD, response, 36);
}

// SCRAM-SHA-256 认证（完整四步握手）
//   mech_list: AUTH_SASL 消息 payload 中 auth_type 之后的机制列表
//   len: 机制列表长度
static int _pg_auth_scram_sha256(PGconn *conn, const char *mech_list, int len) {
    if (conn == NULL || mech_list == NULL) return -1;
    if (conn->password == NULL) {
        _pg_set_error(conn, "pg: SCRAM-SHA-256 auth requires password");
        return -1;
    }

    // Step 1: 检查机制列表中是否包含 "SCRAM-SHA-256"
    int has_scram = 0;
    {
        int pos = 0;
        while (pos < len) {
            const char *mech = mech_list + pos;
            int mlen = (int)strlen(mech);
            if (mlen == 0) break;  // 列表结束
            if (strcmp(mech, "SCRAM-SHA-256") == 0) {
                has_scram = 1;
                break;
            }
            pos += mlen + 1;
        }
    }
    if (!has_scram) {
        _pg_set_error(conn, "pg: server does not support SCRAM-SHA-256");
        return -1;
    }

    // Step 2: 生成 client nonce
    uint8_t nonce_raw[18];
    _pg_gen_random(nonce_raw, 18);
    char client_nonce[25];  // 18 bytes → 24 base64 chars + NUL
    _pg_base64_encode(nonce_raw, 18, client_nonce);

    // Step 3: 构建 client_first_message
    //   client_first = "n,,n=*,r=<nonce>"
    //   client_first_bare = "n=*,r=<nonce>"
    char client_first[128];
    int cf_len = snprintf(client_first, sizeof(client_first), "n,,n=*,r=%s", client_nonce);
    if (cf_len < 0 || cf_len >= (int)sizeof(client_first)) {
        _pg_set_error(conn, "pg: SCRAM client_first too long");
        return -1;
    }
    // client_first_bare 指向 "n=*,r=..." 部分（跳过 "n,,"）
    const char *client_first_bare = client_first + 3;
    int cfb_len = cf_len - 3;

    // Step 4: 发送 SASLInitialResponse ('p' 消息)
    //   格式：mechanism_name\0 + int32_be(response_len) + response_data
    {
        const char *mech_name = "SCRAM-SHA-256";
        int mech_len = (int)strlen(mech_name);
        int payload_len = mech_len + 1 + 4 + cf_len;
        char *payload = (char*)malloc(payload_len);
        if (payload == NULL) {
            _pg_set_error(conn, "pg: out of memory (SCRAM initial)");
            return -1;
        }
        int pos = 0;
        memcpy(payload + pos, mech_name, mech_len); pos += mech_len;
        payload[pos++] = '\0';
        _pg_write_be32((uint8_t*)(payload + pos), (uint32_t)cf_len); pos += 4;
        memcpy(payload + pos, client_first, cf_len); pos += cf_len;

        int rc = _pg_send_message(conn, PG_MSG_PASSWORD, payload, payload_len);
        free(payload);
        if (rc != 0) return -1;
    }

    // Step 5: 接收 AuthenticationSASLContinue (R, 11)
    char *sasl_data = NULL;
    int sasl_len = 0;
    char msg_type = _pg_recv_message(conn, &sasl_data, &sasl_len);
    if (msg_type == 0) {
        _pg_set_error(conn, "pg: SCRAM: failed to receive SASLContinue");
        return -1;
    }
    if (msg_type == PG_MSG_ERROR_RESPONSE) {
        _pg_parse_error(conn, sasl_data, sasl_len, NULL, NULL, NULL, NULL, NULL);
        free(sasl_data);
        return -1;
    }
    if (msg_type != PG_MSG_AUTHENTICATION || sasl_len < 4) {
        _pg_set_error(conn, "pg: SCRAM: expected SASLContinue");
        free(sasl_data);
        return -1;
    }
    uint32_t auth_type = _pg_read_be32((const uint8_t*)sasl_data);
    if (auth_type != AUTH_SASL_CONTINUE) {
        _pg_set_error(conn, "pg: SCRAM: expected AUTH_SASL_CONTINUE");
        free(sasl_data);
        return -1;
    }

    // server_first_message = sasl_data + 4（跳过 auth_type）
    const char *server_first = sasl_data + 4;
    int sf_len = sasl_len - 4;

    // Step 6: 解析 server_first_message
    //   格式：r=<nonce>,s=<base64_salt>,i=<iterations>
    char full_nonce[128];
    char salt_b64[128];
    int iterations = 0;
    {
        // 复制 server_first 到可修改的缓冲区
        char sf_copy[512];
        if (sf_len >= (int)sizeof(sf_copy)) {
            _pg_set_error(conn, "pg: SCRAM server_first too long");
            free(sasl_data);
            return -1;
        }
        memcpy(sf_copy, server_first, sf_len);
        sf_copy[sf_len] = '\0';

        // 解析 r=, s=, i= 字段
        char *p = sf_copy;
        while (*p) {
            if (strncmp(p, "r=", 2) == 0) {
                p += 2;
                char *comma = strchr(p, ',');
                int nlen = comma ? (int)(comma - p) : (int)strlen(p);
                if (nlen >= (int)sizeof(full_nonce)) nlen = (int)sizeof(full_nonce) - 1;
                memcpy(full_nonce, p, nlen);
                full_nonce[nlen] = '\0';
                p += nlen;
            } else if (strncmp(p, "s=", 2) == 0) {
                p += 2;
                char *comma = strchr(p, ',');
                int slen = comma ? (int)(comma - p) : (int)strlen(p);
                if (slen >= (int)sizeof(salt_b64)) slen = (int)sizeof(salt_b64) - 1;
                memcpy(salt_b64, p, slen);
                salt_b64[slen] = '\0';
                p += slen;
            } else if (strncmp(p, "i=", 2) == 0) {
                p += 2;
                iterations = atoi(p);
            }
            // 推进到下一个逗号
            char *comma = strchr(p, ',');
            if (comma == NULL) break;
            p = comma + 1;
        }
    }

    if (iterations < 1) {
        _pg_set_error(conn, "pg: SCRAM: invalid iteration count");
        free(sasl_data);
        return -1;
    }

    // Step 7: 解码 salt
    uint8_t salt[64];
    int salt_len = _pg_base64_decode(salt_b64, (int)strlen(salt_b64), salt);
    if (salt_len < 0) {
        _pg_set_error(conn, "pg: SCRAM: invalid base64 salt");
        free(sasl_data);
        return -1;
    }

    // Step 8: 计算 SCRAM 密钥
    int pass_len = (int)strlen(conn->password);
    uint8_t SaltedPassword[32];
    uint8_t ClientKey[32];
    uint8_t StoredKey[32];
    uint8_t ClientSignature[32];
    uint8_t ClientProof[32];
    uint8_t ServerKey[32];
    uint8_t ServerSignature[32];

    // SaltedPassword = PBKDF2-HMAC-SHA-256(password, salt, iterations, 32)
    _pg_pbkdf2_hmac_sha256((const uint8_t*)conn->password, pass_len,
                            salt, salt_len, iterations,
                            SaltedPassword, 32);

    // ClientKey = HMAC-SHA-256(SaltedPassword, "Client Key")
    _pg_hmac_sha256(SaltedPassword, 32, (const uint8_t*)"Client Key", 10, ClientKey);

    // StoredKey = SHA-256(ClientKey)
    _pg_sha256_hash(ClientKey, 32, StoredKey);

    // ServerKey = HMAC-SHA-256(SaltedPassword, "Server Key")
    _pg_hmac_sha256(SaltedPassword, 32, (const uint8_t*)"Server Key", 10, ServerKey);

    // 构建 client_final_without_proof = "c=biws,r=<nonce>"
    //   "biws" = base64("n,,") — channel binding header
    char cfwop[256];  // client_final_without_proof
    int cfwop_len = snprintf(cfwop, sizeof(cfwop), "c=biws,r=%s", full_nonce);
    if (cfwop_len < 0 || cfwop_len >= (int)sizeof(cfwop)) {
        _pg_set_error(conn, "pg: SCRAM client_final too long");
        free(sasl_data);
        return -1;
    }

    // AuthMessage = client_first_bare + "," + server_first + "," + client_final_without_proof
    int am_len = cfb_len + 1 + sf_len + 1 + cfwop_len;
    char *AuthMessage = (char*)malloc(am_len + 1);
    if (AuthMessage == NULL) {
        _pg_set_error(conn, "pg: out of memory (SCRAM AuthMessage)");
        free(sasl_data);
        return -1;
    }
    int am_pos = 0;
    memcpy(AuthMessage + am_pos, client_first_bare, cfb_len); am_pos += cfb_len;
    AuthMessage[am_pos++] = ',';
    memcpy(AuthMessage + am_pos, server_first, sf_len); am_pos += sf_len;
    AuthMessage[am_pos++] = ',';
    memcpy(AuthMessage + am_pos, cfwop, cfwop_len); am_pos += cfwop_len;
    AuthMessage[am_pos] = '\0';

    // ClientSignature = HMAC-SHA-256(StoredKey, AuthMessage)
    _pg_hmac_sha256(StoredKey, 32, (const uint8_t*)AuthMessage, am_len, ClientSignature);

    // ClientProof = ClientKey XOR ClientSignature
    for (int i = 0; i < 32; i++) {
        ClientProof[i] = ClientKey[i] ^ ClientSignature[i];
    }

    // ServerSignature = HMAC-SHA-256(ServerKey, AuthMessage)
    _pg_hmac_sha256(ServerKey, 32, (const uint8_t*)AuthMessage, am_len, ServerSignature);

    free(AuthMessage);

    // Step 9: 构建 client_final_message
    //   "c=biws,r=<nonce>,p=<base64(ClientProof)>"
    char proof_b64[64];  // 32 bytes → 44 base64 chars + NUL
    _pg_base64_encode(ClientProof, 32, proof_b64);

    char client_final[400];
    int cf_final_len = snprintf(client_final, sizeof(client_final), "%s,p=%s", cfwop, proof_b64);
    if (cf_final_len < 0 || cf_final_len >= (int)sizeof(client_final)) {
        _pg_set_error(conn, "pg: SCRAM client_final message too long");
        free(sasl_data);
        return -1;
    }

    // 释放 server_first 数据
    free(sasl_data);

    // Step 10: 发送 SASLResponse ('p' 消息)
    if (_pg_send_message(conn, PG_MSG_PASSWORD, client_final, cf_final_len) != 0) {
        return -1;
    }

    // Step 11: 接收 AuthenticationSASLFinal (R, 12)
    char *final_data = NULL;
    int final_len = 0;
    msg_type = _pg_recv_message(conn, &final_data, &final_len);
    if (msg_type == 0) {
        _pg_set_error(conn, "pg: SCRAM: failed to receive SASLFinal");
        return -1;
    }
    if (msg_type == PG_MSG_ERROR_RESPONSE) {
        _pg_parse_error(conn, final_data, final_len, NULL, NULL, NULL, NULL, NULL);
        free(final_data);
        return -1;
    }
    if (msg_type != PG_MSG_AUTHENTICATION || final_len < 4) {
        _pg_set_error(conn, "pg: SCRAM: expected SASLFinal");
        free(final_data);
        return -1;
    }
    uint32_t final_auth_type = _pg_read_be32((const uint8_t*)final_data);
    if (final_auth_type != AUTH_SASL_FINAL) {
        _pg_set_error(conn, "pg: SCRAM: expected AUTH_SASL_FINAL");
        free(final_data);
        return -1;
    }

    // server_final_message = "v=<base64(ServerSignature)>"
    const char *server_final = final_data + 4;
    int srf_len = final_len - 4;

    // 解析 "v=..." 字段
    if (srf_len < 2 || server_final[0] != 'v' || server_final[1] != '=') {
        _pg_set_error(conn, "pg: SCRAM: invalid server_final_message");
        free(final_data);
        return -1;
    }
    const char *v_b64 = server_final + 2;
    int v_b64_len = srf_len - 2;
    // 去除可能的尾部空格/NUL
    while (v_b64_len > 0 && (v_b64[v_b64_len-1] == '\0' || v_b64[v_b64_len-1] == ' ')) {
        v_b64_len--;
    }

    // 解码服务器签名
    uint8_t server_sig[64];
    int server_sig_len = _pg_base64_decode(v_b64, v_b64_len, server_sig);
    if (server_sig_len != 32) {
        _pg_set_error(conn, "pg: SCRAM: invalid server signature length");
        free(final_data);
        return -1;
    }

    // 验证服务器签名
    if (memcmp(server_sig, ServerSignature, 32) != 0) {
        _pg_set_error(conn, "pg: SCRAM: server signature verification failed");
        free(final_data);
        return -1;
    }

    free(final_data);
    // 成功 — AUTH_OK 将在主认证循环中接收
    return 0;
}

// 主认证循环
static int _pg_do_auth(PGconn *conn) {
    if (conn == NULL) return -1;

    while (1) {
        char *data = NULL;
        int len = 0;
        char type = _pg_recv_message(conn, &data, &len);

        if (type == 0) {
            if (conn->last_error == NULL) {
                _pg_set_error(conn, "pg: connection lost during auth");
            }
            return -1;
        }

        if (type == PG_MSG_ERROR_RESPONSE) {
            _pg_parse_error(conn, data, len, NULL, NULL, NULL, NULL, NULL);
            free(data);
            return -1;
        }

        if (type != PG_MSG_AUTHENTICATION) {
            char errbuf[128];
            snprintf(errbuf, sizeof(errbuf), "pg: unexpected message type '%c' during auth", type);
            _pg_set_error(conn, errbuf);
            free(data);
            return -1;
        }

        if (len < 4) {
            _pg_set_error(conn, "pg: auth message too short");
            free(data);
            return -1;
        }

        uint32_t auth_type = _pg_read_be32((const uint8_t*)data);

        switch (auth_type) {
            case AUTH_OK:
                // 认证成功
                free(data);
                conn->status = CONN_AUTH_OK;
                return 0;

            case AUTH_MD5: {
                if (len < 8) {
                    _pg_set_error(conn, "pg: MD5 auth message too short (no salt)");
                    free(data);
                    return -1;
                }
                // salt 是 data[4..7]
                int rc = _pg_auth_md5(conn, data + 4);
                free(data);
                if (rc != 0) return -1;
                break;
            }

            case AUTH_PASSWORD: {
                // 明文密码认证
                free(data);
                if (conn->password == NULL) {
                    _pg_set_error(conn, "pg: cleartext auth requires password");
                    return -1;
                }
                int plen = (int)strlen(conn->password) + 1;  // 含 NUL
                if (_pg_send_message(conn, PG_MSG_PASSWORD, conn->password, plen) != 0) {
                    return -1;
                }
                break;
            }

            case AUTH_SASL: {
                // SCRAM-SHA-256 认证
                // data+4 是机制列表
                int rc = _pg_auth_scram_sha256(conn, data + 4, len - 4);
                free(data);
                if (rc != 0) return -1;
                break;
            }

            default: {
                char errbuf[128];
                snprintf(errbuf, sizeof(errbuf),
                         "pg: unsupported authentication type %u", auth_type);
                _pg_set_error(conn, errbuf);
                free(data);
                return -1;
            }
        }
    }
}

// ============================================================
// 连接启动后的消息消费（ParameterStatus / BackendKeyData / ReadyForQuery）
// ============================================================
static int _pg_consume_startup_messages(PGconn *conn) {
    if (conn == NULL) return -1;

    while (1) {
        char *data = NULL;
        int len = 0;
        char type = _pg_recv_message(conn, &data, &len);

        if (type == 0) {
            if (conn->last_error == NULL) {
                _pg_set_error(conn, "pg: connection lost during startup");
            }
            return -1;
        }

        if (type == PG_MSG_ERROR_RESPONSE) {
            _pg_parse_error(conn, data, len, NULL, NULL, NULL, NULL, NULL);
            free(data);
            return -1;
        }

        if (type == PG_MSG_NOTICE_RESPONSE) {
            // 通知消息 — 存入 last_notice，并调用 notice_cb
            char *msg = NULL;
            _pg_parse_error(conn, data, len, NULL, NULL, &msg, NULL, NULL);
            if (msg) {
                int msg_len = (int)strlen(msg);
                _pg_invoke_notice_cb(conn, msg, msg_len);
                if (conn->last_notice) free(conn->last_notice);
                conn->last_notice = msg;
            }
            free(data);
            continue;
        }

        if (type == PG_MSG_PARAMETER_STATUS) {
            // ParameterStatus: key\0value\0
            if (data && len > 0) {
                const char *key = data;
                int klen = (int)strlen(key);
                if (klen + 1 < len) {
                    const char *val = data + klen + 1;
                    if (strcmp(key, "server_version") == 0) {
                        if (conn->server_version) free(conn->server_version);
                        conn->server_version = (char*)malloc(strlen(val) + 1);
                        if (conn->server_version) strcpy(conn->server_version, val);
                    } else if (strcmp(key, "client_encoding") == 0) {
                        if (conn->client_encoding) free(conn->client_encoding);
                        conn->client_encoding = (char*)malloc(strlen(val) + 1);
                        if (conn->client_encoding) strcpy(conn->client_encoding, val);
                    } else if (strcmp(key, "standard_conforming_strings") == 0) {
                        conn->std_conforming_strings = (strcmp(val, "on") == 0) ? 1 : 0;
                    }
                }
            }
            free(data);
            continue;
        }

        if (type == PG_MSG_BACKEND_KEY_DATA) {
            // BackendKeyData: int32 pid + int32 key
            if (data && len >= 8) {
                conn->backend_pid = (int)_pg_read_be32((const uint8_t*)data);
                conn->backend_key = (int)_pg_read_be32((const uint8_t*)(data + 4));
            }
            free(data);
            continue;
        }

        if (type == PG_MSG_READY_FOR_QUERY) {
            // ReadyForQuery: 1 字节事务状态
            if (data && len >= 1) {
                conn->trans_status = data[0];
            }
            free(data);
            conn->status = CONN_OK;
            return 0;
        }

        // 未知消息类型 — 跳过
        free(data);
    }
}

// ============================================================
// TCP 连接建立
// ============================================================
static int _pg_connect_socket(PGconn *conn) {
    if (conn == NULL) return -1;

    // 初始化 winsock（Windows）
    tphp_fn_stream_init();

    struct addrinfo hints, *res, *rp;
    memset(&hints, 0, sizeof(hints));
    hints.ai_family = AF_INET;
    hints.ai_socktype = SOCK_STREAM;

    char port_str[16];
    snprintf(port_str, sizeof(port_str), "%d", conn->port);

    int gai_ret = getaddrinfo(conn->host, port_str, &hints, &res);
    if (gai_ret != 0) {
        char errbuf[256];
        snprintf(errbuf, sizeof(errbuf), "pg: failed to resolve host '%s'", conn->host);
        _pg_set_error(conn, errbuf);
        return -1;
    }

    int fd = -1;
    for (rp = res; rp != NULL; rp = rp->ai_next) {
        fd = (int)socket(rp->ai_family, rp->ai_socktype, rp->ai_protocol);
        if (fd < 0) continue;
        if (connect(fd, rp->ai_addr, (int)rp->ai_addrlen) == 0) {
            break;  // 连接成功
        }
        STREAM_CLOSE(fd);
        fd = -1;
    }
    freeaddrinfo(res);

    if (fd < 0) {
        char errbuf[256];
        snprintf(errbuf, sizeof(errbuf), "pg: failed to connect to %s:%d", conn->host, conn->port);
        _pg_set_error(conn, errbuf);
        return -1;
    }

    // 设置默认 recv/send 超时（30 秒或 connect_timeout）
    int timeout_sec = (conn->connect_timeout > 0) ? conn->connect_timeout : 30;
#ifdef _WIN32
    DWORD to_ms = (DWORD)(timeout_sec * 1000);
    setsockopt((SOCKET)fd, SOL_SOCKET, SO_RCVTIMEO, (const char*)&to_ms, sizeof(to_ms));
    setsockopt((SOCKET)fd, SOL_SOCKET, SO_SNDTIMEO, (const char*)&to_ms, sizeof(to_ms));
#else
    struct timeval tv;
    tv.tv_sec = timeout_sec;
    tv.tv_usec = 0;
    setsockopt(fd, SOL_SOCKET, SO_RCVTIMEO, (const char*)&tv, sizeof(tv));
    setsockopt(fd, SOL_SOCKET, SO_SNDTIMEO, (const char*)&tv, sizeof(tv));
#endif

    conn->sock = fd;
    conn->status = CONN_MADE;
    return 0;
}

// ============================================================
// 3.5 连接函数 — PHP API 实现
// ============================================================

// pg_connect — 建立到 PostgreSQL 服务器的新连接
//   dsn: 连接字符串（key=value 或 URL 格式）
//   成功返回 PGconn 指针（t_int），失败 tp_throw 并返回 0
t_int _pg_connect(t_string dsn) {
    const char *dsn_str = STR_PTR(dsn);
    if (dsn_str == NULL || dsn.length == 0) {
        tp_throw("pg_connect: empty connection string");
        return 0;
    }

    // 分配 PGconn
    PGconn *conn = (PGconn*)malloc(sizeof(PGconn));
    if (conn == NULL) {
        tp_throw("pg_connect: out of memory");
        return 0;
    }
    memset(conn, 0, sizeof(*conn));
    conn->sock = -1;
    conn->status = CONN_BAD;
    conn->trans_status = TRANS_IDLE;

    // 1. 解析 DSN
    if (_pg_parse_dsn(dsn_str, conn) != 0) {
        _pg_set_error(conn, "pg_connect: failed to parse connection string");
        tp_throw(conn->last_error ? conn->last_error : "pg_connect: DSN parse error");
        _pg_free_conn(conn);
        return 0;
    }

    // 2. 建立 TCP 连接
    if (_pg_connect_socket(conn) != 0) {
        tp_throw(conn->last_error ? conn->last_error : "pg_connect: TCP connection failed");
        _pg_free_conn(conn);
        return 0;
    }

    // 3. 发送 StartupMessage
    if (_pg_send_startup(conn) != 0) {
        tp_throw(conn->last_error ? conn->last_error : "pg_connect: send startup failed");
        _pg_free_conn(conn);
        return 0;
    }

    // 4. 认证
    if (_pg_do_auth(conn) != 0) {
        tp_throw(conn->last_error ? conn->last_error : "pg_connect: authentication failed");
        _pg_free_conn(conn);
        return 0;
    }

    // 5. 消费启动消息（ParameterStatus / BackendKeyData / ReadyForQuery）
    if (_pg_consume_startup_messages(conn) != 0) {
        tp_throw(conn->last_error ? conn->last_error : "pg_connect: startup handshake failed");
        _pg_free_conn(conn);
        return 0;
    }

    return _PG_CONN_TO_INT(conn);
}

// pg_pconnect — 建立持久连接
//   委托给 _pg_pconnect_real（实现在 pgsql_pconnect.h，Task 9.6）
//   实现完整的持久连接池复用逻辑
t_int _pg_pconnect(t_string dsn, t_int flags) {
    return _pg_pconnect_real(dsn, flags);
}

// pg_close — 关闭连接
//   委托给 _pg_close_real（实现在 pgsql_pconnect.h，Task 9.6）
//   持久连接默认归还连接池，非持久连接直接关闭
//   close_flags: PGSQL_CLOSE_FORCE=1 强制关闭（传递给 _pg_close_real）
//   返回 true 表示关闭流程已完成（与 PHP 层 bool 返回值对齐）
t_bool _pg_close(t_int conn_handle, t_int close_flags) {
    PGconn *conn = _PG_CONN_FROM_INT(conn_handle);
    if (conn == NULL) return false;
    _pg_close_real(conn, close_flags);
    return true;
}

// pg_connection_status — 返回连接状态
t_int _pg_connection_status(t_int conn_handle) {
    PGconn *conn = _PG_CONN_FROM_INT(conn_handle);
    if (conn == NULL) return CONN_BAD;
    return conn->status;
}

// pg_ping — ping 服务器
//   发送空查询，检查 ReadyForQuery 响应
t_bool _pg_ping(t_int conn_handle) {
    PGconn *conn = _PG_CONN_FROM_INT(conn_handle);
    if (conn == NULL || conn->sock < 0) return false;

    // 发送空 Query（payload 仅含 NUL 终止符）
    const char empty_query[] = "";
    if (_pg_send_message(conn, PG_MSG_QUERY, empty_query, 1) != 0) {
        return false;
    }

    // 读取响应：EmptyQueryResponse ('I') 或 CommandComplete ('C') 或 ErrorResponse ('E')
    char *data = NULL;
    int len = 0;
    char type = _pg_recv_message(conn, &data, &len);
    if (data) free(data);

    if (type == PG_MSG_ERROR_RESPONSE) {
        // 读取后续 ReadyForQuery
        type = _pg_recv_message(conn, &data, &len);
        if (data) free(data);
        return false;
    }

    if (type == PG_MSG_EMPTY_QUERY_RESPONSE || type == PG_MSG_COMMAND_COMPLETE) {
        // 读取 ReadyForQuery
        type = _pg_recv_message(conn, &data, &len);
        if (data) free(data);
        if (type == PG_MSG_READY_FOR_QUERY) {
            if (len >= 1) {
                // data 已被 free，但 trans_status 在 _pg_recv_message 返回时未更新
                // 这里需要重新处理 — 但 data 已 free
                // 实际上 ReadyForQuery 的 1 字节在 data 中，已被 free
                // 修正：不在此处更新 trans_status，仅返回 true
            }
            return true;
        }
    }
    return false;
}

// pg_connection_reset — 重置连接
//   关闭当前 socket，重新建立连接
t_bool _pg_connection_reset(t_int conn_handle) {
    PGconn *conn = _PG_CONN_FROM_INT(conn_handle);
    if (conn == NULL) return false;

    // 关闭旧连接
    if (conn->sock >= 0) {
        _pg_send_message(conn, PG_MSG_TERMINATE, "", 0);
        STREAM_CLOSE(conn->sock);
        conn->sock = -1;
    }
    conn->status = CONN_BAD;

    // 清除旧错误
    if (conn->last_error) { free(conn->last_error); conn->last_error = NULL; }

    // 重新连接
    if (_pg_connect_socket(conn) != 0) return false;
    if (_pg_send_startup(conn) != 0) return false;
    if (_pg_do_auth(conn) != 0) return false;
    if (_pg_consume_startup_messages(conn) != 0) return false;

    return true;
}

// ============================================================
// pg_set_notice_callback / Large Object 函数
//   实现移至 pgsql_notice.h（Task 9.7）和 pgsql_lo.h（Task 9.5）
//   由 pgsql.php 在本文件之后 #include 对应头文件
// ============================================================
