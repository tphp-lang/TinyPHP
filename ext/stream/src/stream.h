#pragma once
// ============================================================
// stream.h — 跨平台 socket stream 扩展（Windows winsock2 / POSIX）
//
// 设计说明：
//   - 所有函数接收 tphp 类型（t_string/t_int/t_array*），通过 $simpleFnMap 直接映射
//   - socket fd 以 t_int 流转（与 exif 的 FILE* → t_int 模式一致）
//   - 错误统一 tp_throw_ex（可被 try-catch 捕获，不返回 false）
//   - Winsock 首次调用自动 WSAStartup（懒初始化）
//   - FD_SETSIZE 提升到 1024（Windows 默认 64 太少）
//   - 所有函数 static inline，避免符号重复定义
//
// 依赖：
//   Windows: ws2_32.lib（stream.php 中 #flag windows -lws2_32）
//   POSIX:   无（socket API 在 libc 中）
// ============================================================

#include "types.h"
#include "object/exception.h"
#include "object/try.h"
#include "array.h"
#include "val.h"
#include <stdio.h>
#include <stdlib.h>
#include <string.h>

// ── 平台头文件 ──────────────────────────────────────────────
#ifdef _WIN32
  #ifndef FD_SETSIZE
    #define FD_SETSIZE 1024
  #endif
  // inet_pton 需要 _WIN32_WINNT >= 0x0600 (Windows Vista)
  #ifndef _WIN32_WINNT
    #define _WIN32_WINNT 0x0600
  #endif
  #include <winsock2.h>
  #include <ws2tcpip.h>
  #include <io.h>
  // 注意：不使用 #pragma comment(lib, "ws2_32.lib") — TCC 会将完整名 "ws2_32.lib"
  // 传给 tcc_add_library()，搜索 "ws2_32.lib.def" 等不存在的文件。
  // ws2_32 链接由 #flag windows -lws2_32（stream.php）和 tphp.php 自动检测共同提供。
  #define STREAM_CLOSE(fd)     closesocket((SOCKET)(fd))
  #define STREAM_ERRNO         WSAGetLastError()
  #define STREAM_EWOULDBLOCK   WSAEWOULDBLOCK
  #define STREAM_EINPROGRESS   WSAEINPROGRESS
  // Windows 用 SD_RECEIVE/SD_SEND/SD_BOTH，统一映射到 POSIX 名称
  #ifndef SHUT_RD
    #define SHUT_RD     SD_RECEIVE
  #endif
  #ifndef SHUT_WR
    #define SHUT_WR     SD_SEND
  #endif
  #ifndef SHUT_RDWR
    #define SHUT_RDWR   SD_BOTH
  #endif
  // TCC 的 ws2tcpip.h 缺少 inet_pton/inet_ntop 声明（仅声明 getaddrinfo 等）
  // GCC/Clang (MinGW) 的 ws2tcpip.h 在 _WIN32_WINNT >= 0x0600 时有声明
  // 这里统一手动声明，避免 implicit declaration 警告
  #ifndef __MINGW32__
    WINSOCK_API_LINKAGE int WSAAPI inet_pton(int af, const char *src, void *dst);
    WINSOCK_API_LINKAGE const char *WSAAPI inet_ntop(int af, const void *src, char *dst, size_t size);
  #endif
#else
  #include <sys/socket.h>
  #include <sys/select.h>
  #include <sys/un.h>
  #include <netinet/in.h>
  #include <netinet/tcp.h>
  #include <arpa/inet.h>
  #include <netdb.h>
  #include <unistd.h>
  #include <fcntl.h>
  #include <errno.h>
  #define STREAM_CLOSE(fd)     close((int)(fd))
  #define STREAM_ERRNO         errno
  #define STREAM_EWOULDBLOCK   EWOULDBLOCK
  #define STREAM_EINPROGRESS   EINPROGRESS
#endif

// ── 常量（CodeGenerator 需要 TPHP_CONST_ 前缀） ────────────
#define TPHP_CONST_STREAM_CLIENT_CONNECT          2
#define TPHP_CONST_STREAM_CLIENT_ASYNC_CONNECT    4
#define TPHP_CONST_STREAM_CLIENT_PERSISTENT       1
#define TPHP_CONST_STREAM_SERVER_BIND             4
#define TPHP_CONST_STREAM_SERVER_LISTEN           8
#define TPHP_CONST_STREAM_SHUT_RD                 0
#define TPHP_CONST_STREAM_SHUT_WR                 1
#define TPHP_CONST_STREAM_SHUT_RDWR               2
#define TPHP_CONST_STREAM_SOCK_STREAM             1
#define TPHP_CONST_STREAM_SOCK_DGRAM              2
#define TPHP_CONST_STREAM_SOCK_RAW                3
#define TPHP_CONST_STREAM_SOCK_RDM                4
#define TPHP_CONST_STREAM_SOCK_SEQPACKET          5
#define TPHP_CONST_STREAM_PF_INET                 2
#define TPHP_CONST_STREAM_PF_INET6                10
#define TPHP_CONST_STREAM_PF_UNIX                 1
#define TPHP_CONST_STREAM_IPPROTO_IP              0
#define TPHP_CONST_STREAM_IPPROTO_TCP             6
#define TPHP_CONST_STREAM_IPPROTO_UDP             17
#define TPHP_CONST_STREAM_IPPROTO_ICMP            1
#define TPHP_CONST_STREAM_IPPROTO_RAW             255
#define TPHP_CONST_STREAM_OOB                     1
#define TPHP_CONST_STREAM_PEEK                    2
#define TPHP_CONST_STREAM_NOTIFY_CONNECT          2
#define TPHP_CONST_STREAM_NOTIFY_AUTH_REQUIRED    3
#define TPHP_CONST_STREAM_NOTIFY_AUTH_RESULT      4
#define TPHP_CONST_STREAM_NOTIFY_MIME_TYPE_IS     5
#define TPHP_CONST_STREAM_NOTIFY_FILE_SIZE_IS     6
#define TPHP_CONST_STREAM_NOTIFY_REDIRECTED       7
#define TPHP_CONST_STREAM_NOTIFY_PROGRESS         8
#define TPHP_CONST_STREAM_NOTIFY_FAILURE          9
#define TPHP_CONST_STREAM_NOTIFY_COMPLETED       10
#define TPHP_CONST_STREAM_NOTIFY_RESOLVE         11
#define TPHP_CONST_STREAM_NOTIFY_SEVERITY_ERR     1
#define TPHP_CONST_STREAM_NOTIFY_SEVERITY_WARN    2
#define TPHP_CONST_STREAM_NOTIFY_SEVERITY_INFO    3
#define TPHP_CONST_STREAM_FILTER_READ             1
#define TPHP_CONST_STREAM_FILTER_WRITE            2
#define TPHP_CONST_STREAM_FILTER_ALL              3
#define TPHP_CONST_STREAM_AWAIT_READ              1
#define TPHP_CONST_STREAM_AWAIT_WRITE             2
#define TPHP_CONST_STREAM_AWAIT_READ_WRITE        3
// STREAM_CRYPTO_METHOD_* — 与 PHP 原生 bitmask 值完全一致
//   PHP 原生区分 _CLIENT/_SERVER 两套（值相同），TinyPHP 保持兼容
//   无后缀别名指向 _CLIENT 版本（向后兼容旧代码）
//   注意：CodeGenerator 把 PHP 常量名 strtoupper 后映射到 C 宏，
//         所以 C 宏名必须全大写（如 SSLV2 而非 SSLv2）
#define TPHP_CONST_STREAM_CRYPTO_METHOD_SSLV2_CLIENT     0x00000000
#define TPHP_CONST_STREAM_CRYPTO_METHOD_SSLV3_CLIENT     0x00000001
#define TPHP_CONST_STREAM_CRYPTO_METHOD_SSLV23_CLIENT    0x00000002
#define TPHP_CONST_STREAM_CRYPTO_METHOD_TLS_CLIENT       0x00000003
#define TPHP_CONST_STREAM_CRYPTO_METHOD_TLSV1_0_CLIENT   0x00000004
#define TPHP_CONST_STREAM_CRYPTO_METHOD_TLSV1_1_CLIENT   0x00000008
#define TPHP_CONST_STREAM_CRYPTO_METHOD_TLSV1_2_CLIENT   0x00000010
#define TPHP_CONST_STREAM_CRYPTO_METHOD_TLSV1_3_CLIENT   0x00000020
#define TPHP_CONST_STREAM_CRYPTO_METHOD_ANY_CLIENT       0x0000003F

#define TPHP_CONST_STREAM_CRYPTO_METHOD_SSLV2_SERVER     0x00000000
#define TPHP_CONST_STREAM_CRYPTO_METHOD_SSLV3_SERVER     0x00000001
#define TPHP_CONST_STREAM_CRYPTO_METHOD_SSLV23_SERVER    0x00000002
#define TPHP_CONST_STREAM_CRYPTO_METHOD_TLS_SERVER       0x00000003
#define TPHP_CONST_STREAM_CRYPTO_METHOD_TLSV1_0_SERVER   0x00000004
#define TPHP_CONST_STREAM_CRYPTO_METHOD_TLSV1_1_SERVER   0x00000008
#define TPHP_CONST_STREAM_CRYPTO_METHOD_TLSV1_2_SERVER   0x00000010
#define TPHP_CONST_STREAM_CRYPTO_METHOD_TLSV1_3_SERVER   0x00000020
#define TPHP_CONST_STREAM_CRYPTO_METHOD_ANY_SERVER       0x0000003F

// 无后缀别名（向后兼容，指向 _CLIENT 版本）
#define TPHP_CONST_STREAM_CRYPTO_METHOD_SSLV2     TPHP_CONST_STREAM_CRYPTO_METHOD_SSLV2_CLIENT
#define TPHP_CONST_STREAM_CRYPTO_METHOD_SSLV3     TPHP_CONST_STREAM_CRYPTO_METHOD_SSLV3_CLIENT
#define TPHP_CONST_STREAM_CRYPTO_METHOD_SSLV23    TPHP_CONST_STREAM_CRYPTO_METHOD_SSLV23_CLIENT
#define TPHP_CONST_STREAM_CRYPTO_METHOD_TLS       TPHP_CONST_STREAM_CRYPTO_METHOD_TLS_CLIENT
#define TPHP_CONST_STREAM_CRYPTO_METHOD_TLSV1_0   TPHP_CONST_STREAM_CRYPTO_METHOD_TLSV1_0_CLIENT
#define TPHP_CONST_STREAM_CRYPTO_METHOD_TLSV1_1   TPHP_CONST_STREAM_CRYPTO_METHOD_TLSV1_1_CLIENT
#define TPHP_CONST_STREAM_CRYPTO_METHOD_TLSV1_2   TPHP_CONST_STREAM_CRYPTO_METHOD_TLSV1_2_CLIENT
#define TPHP_CONST_STREAM_CRYPTO_METHOD_TLSV1_3   TPHP_CONST_STREAM_CRYPTO_METHOD_TLSV1_3_CLIENT

// STREAM_CRYPTO_PROTO_* 别名（PHP 8.1+）
#define TPHP_CONST_STREAM_CRYPTO_PROTO_SSLV3      1
#define TPHP_CONST_STREAM_CRYPTO_PROTO_TLSV1_0    2
#define TPHP_CONST_STREAM_CRYPTO_PROTO_TLSV1_1    3
#define TPHP_CONST_STREAM_CRYPTO_PROTO_TLSV1_2    4
#define TPHP_CONST_STREAM_CRYPTO_PROTO_TLSV1_3    5

#define TPHP_CONST_STREAM_CRYPTO_ENABLE           1
#define TPHP_CONST_STREAM_CRYPTO_DISABLE          0
#define TPHP_CONST_STREAM_OPTION_BLOCKING         1
#define TPHP_CONST_STREAM_OPTION_READ_BUFFER      3
#define TPHP_CONST_STREAM_OPTION_READ_TIMEOUT     4
#define TPHP_CONST_STREAM_OPTION_WRITE_BUFFER     5
#define TPHP_CONST_STREAM_OPTION_CHUNK_SIZE       7

// ── 内部：抛异常辅助 ────────────────────────────────────────
static inline void _stream_throw(const char* msg) {
    t_string s;
    s.data = (char*)msg;
    s.length = (int)strlen(msg);
    s.is_local = false;
    s.is_lit = false;
    tp_throw_ex(new_tphp_class_Exception(s));
}

// ── 内部：地址解析 "tcp://0.0.0.0:8080" → proto, host, port ──
typedef struct {
    char proto[8];     // "tcp", "udp", "unix", "ssl", "tls"
    char host[256];
    int  port;
    int  sock_type;    // SOCK_STREAM / SOCK_DGRAM
    int  protocol;     // IPPROTO_TCP / IPPROTO_UDP / 0
} _stream_addr_t;

static inline int _stream_parse_address(t_string address, _stream_addr_t* out) {
    const char* addr = STR_PTR(address);
    const char* pos = strstr(addr, "://");
    if (pos == NULL) {
        // 无协议前缀，默认 tcp
        strncpy(out->proto, "tcp", sizeof(out->proto) - 1);
        out->proto[sizeof(out->proto) - 1] = '\0';
        strncpy(out->host, addr, sizeof(out->host) - 1);
        out->host[sizeof(out->host) - 1] = '\0';
    } else {
        int plen = (int)(pos - addr);
        if (plen >= (int)sizeof(out->proto)) plen = sizeof(out->proto) - 1;
        memcpy(out->proto, addr, plen);
        out->proto[plen] = '\0';
        pos += 3;  // 跳过 "://"
        strncpy(out->host, pos, sizeof(out->host) - 1);
        out->host[sizeof(out->host) - 1] = '\0';
    }

    // 确定协议
    if (strcmp(out->proto, "tcp") == 0 || strcmp(out->proto, "ssl") == 0 || strcmp(out->proto, "tls") == 0) {
        out->sock_type = SOCK_STREAM;
        out->protocol = IPPROTO_TCP;
    } else if (strcmp(out->proto, "udp") == 0) {
        out->sock_type = SOCK_DGRAM;
        out->protocol = IPPROTO_UDP;
    } else if (strcmp(out->proto, "unix") == 0) {
        out->sock_type = SOCK_STREAM;
        out->protocol = 0;
    } else {
        return -1;  // 未知协议
    }

    // 解析 host:port（unix:// 不解析端口）
    if (strcmp(out->proto, "unix") != 0) {
        char* colon = strrchr(out->host, ':');
        if (colon != NULL) {
            *colon = '\0';
            out->port = atoi(colon + 1);
        } else {
            out->port = 0;
        }
    }

    return 0;
}

// ════════════════════════════════════════════════════════════
// 公共 API（接收 tphp 类型，通过 $simpleFnMap 直接映射）
// ════════════════════════════════════════════════════════════

// ── Winsock 懒初始化 ────────────────────────────────────────
static inline void tphp_fn_stream_init(void) {
#ifdef _WIN32
    static int _initialized = 0;
    if (!_initialized) {
        WSADATA d;
        if (WSAStartup(MAKEWORD(2, 2), &d) != 0) {
            _stream_throw("stream: WSAStartup failed");
        }
        _initialized = 1;
    }
#endif
}

// ── 关闭 socket ─────────────────────────────────────────────
static inline void tphp_fn_stream_close(t_int fd) {
    if (fd >= 0) {
        STREAM_CLOSE(fd);
    }
}

// ── 获取最近错误码 ──────────────────────────────────────────
static inline t_int tphp_fn_stream_last_error(void) {
    return (t_int)STREAM_ERRNO;
}

// ── 错误码 → 字符串 ────────────────────────────────────────
static inline t_string tphp_fn_stream_strerror(t_int err) {
#ifdef _WIN32
    char buf[256];
    FormatMessageA(FORMAT_MESSAGE_FROM_SYSTEM | FORMAT_MESSAGE_IGNORE_INSERTS,
                   NULL, (DWORD)err, 0, buf, sizeof(buf), NULL);
    int len = (int)strlen(buf);
    while (len > 0 && (buf[len-1] == '\r' || buf[len-1] == '\n')) buf[--len] = 0;
    return tphp_rt_str_dup((t_string){.data = buf, .length = len, .is_local = false, .is_lit = false});
#else
    const char* s = strerror((int)err);
    return tphp_rt_str_dup((t_string){.data = (char*)s, .length = (int)strlen(s), .is_local = false, .is_lit = false});
#endif
}

// ── 设置非阻塞模式 ──────────────────────────────────────────
static inline t_bool tphp_fn_stream_set_blocking(t_int fd, t_bool enable) {
    if (fd < 0) return false;
#ifdef _WIN32
    u_long mode = enable ? 0 : 1;
    return ioctlsocket((SOCKET)fd, FIONBIO, &mode) == 0 ? true : false;
#else
    int flags = fcntl((int)fd, F_GETFL, 0);
    if (flags < 0) return false;
    if (enable) {
        flags &= ~O_NONBLOCK;
    } else {
        flags |= O_NONBLOCK;
    }
    return fcntl((int)fd, F_SETFL, flags) == 0 ? true : false;
#endif
}

// ── 设置读缓冲大小（socket 流无 stdio 缓冲，直接返回 0） ────
static inline t_int tphp_fn_stream_set_read_buffer(t_int fd, t_int buffer) {
    (void)fd;
    (void)buffer;
    return 0;
}

// ── isatty ─────────────────────────────────────────────────
static inline t_bool tphp_fn_stream_isatty(t_int fd) {
#ifdef _WIN32
    return _isatty((int)fd) ? true : false;
#else
    return isatty((int)fd) ? true : false;
#endif
}

// ── stream_select ──────────────────────────────────────────
//   修改数组 in-place：仅保留就绪的 fd
//   tv_sec < 0 表示无限等待
static inline t_int tphp_fn_stream_select(
    t_array* read_arr, t_array* write_arr, t_array* except_arr,
    t_int tv_sec, t_int tv_usec
) {
    tphp_fn_stream_init();

    fd_set rfds, wfds, efds;
    FD_ZERO(&rfds);
    FD_ZERO(&wfds);
    FD_ZERO(&efds);

    int max_fd = -1;

    if (read_arr != NULL) {
        for (int i = 0; i < read_arr->length; i++) {
            if (read_arr->entries[i].val.type == TYPE_INT) {
                int fd = (int)read_arr->entries[i].val.value._int;
                FD_SET(fd, &rfds);
                if (fd > max_fd) max_fd = fd;
            }
        }
    }
    if (write_arr != NULL) {
        for (int i = 0; i < write_arr->length; i++) {
            if (write_arr->entries[i].val.type == TYPE_INT) {
                int fd = (int)write_arr->entries[i].val.value._int;
                FD_SET(fd, &wfds);
                if (fd > max_fd) max_fd = fd;
            }
        }
    }
    if (except_arr != NULL) {
        for (int i = 0; i < except_arr->length; i++) {
            if (except_arr->entries[i].val.type == TYPE_INT) {
                int fd = (int)except_arr->entries[i].val.value._int;
                FD_SET(fd, &efds);
                if (fd > max_fd) max_fd = fd;
            }
        }
    }

    struct timeval tv;
    struct timeval* tvp;
    if (tv_sec < 0) {
        tvp = NULL;
    } else {
        tv.tv_sec = (long)tv_sec;
        tv.tv_usec = (long)tv_usec;
        tvp = &tv;
    }

    int ret = select(max_fd + 1, &rfds, &wfds, &efds, tvp);
    if (ret < 0) {
        int err = STREAM_ERRNO;
        char buf[128];
        snprintf(buf, sizeof(buf), "stream_select: select() failed (errno=%d)", err);
        _stream_throw(buf);
        return -1;
    }

    // 过滤数组 — in-place 压缩，保留原 key（结构体赋值复制 key+val）
    // 注意：删除条目后 str_index/int_index 中的 entry_idx 会失效，
    //       需清除索引让下次访问时自动重建
    if (read_arr != NULL) {
        int w = 0;
        for (int i = 0; i < read_arr->length; i++) {
            if (read_arr->entries[i].val.type == TYPE_INT) {
                int fd = (int)read_arr->entries[i].val.value._int;
                if (FD_ISSET(fd, &rfds)) {
                    if (w != i) read_arr->entries[w] = read_arr->entries[i];
                    w++;
                }
            } else {
                if (w != i) read_arr->entries[w] = read_arr->entries[i];
                w++;
            }
        }
        read_arr->length = w;
        arr_stridx_free(read_arr);  // 清除索引（entry 位置已变）
        arr_intidx_free(read_arr);
    }
    if (write_arr != NULL) {
        int w = 0;
        for (int i = 0; i < write_arr->length; i++) {
            if (write_arr->entries[i].val.type == TYPE_INT) {
                int fd = (int)write_arr->entries[i].val.value._int;
                if (FD_ISSET(fd, &wfds)) {
                    if (w != i) write_arr->entries[w] = write_arr->entries[i];
                    w++;
                }
            } else {
                if (w != i) write_arr->entries[w] = write_arr->entries[i];
                w++;
            }
        }
        write_arr->length = w;
        arr_stridx_free(write_arr);
        arr_intidx_free(write_arr);
    }
    if (except_arr != NULL) {
        int w = 0;
        for (int i = 0; i < except_arr->length; i++) {
            if (except_arr->entries[i].val.type == TYPE_INT) {
                int fd = (int)except_arr->entries[i].val.value._int;
                if (FD_ISSET(fd, &efds)) {
                    if (w != i) except_arr->entries[w] = except_arr->entries[i];
                    w++;
                }
            } else {
                if (w != i) except_arr->entries[w] = except_arr->entries[i];
                w++;
            }
        }
        except_arr->length = w;
        arr_stridx_free(except_arr);
        arr_intidx_free(except_arr);
    }

    return (t_int)ret;
}

// ── stream_socket_server ────────────────────────────────────
//   address: "tcp://0.0.0.0:8080" / "udp://..." / "unix:///path"
//   flags: STREAM_SERVER_BIND | STREAM_SERVER_LISTEN
static inline t_int tphp_fn_stream_socket_server(t_string address, t_int flags, t_array* context) {
    (void)context;
    tphp_fn_stream_init();

    _stream_addr_t addr;
    if (_stream_parse_address(address, &addr) != 0) {
        _stream_throw("stream_socket_server: unknown protocol in address");
        return -1;
    }

    int fd = -1;

    if (strcmp(addr.proto, "unix") == 0) {
#ifdef _WIN32
        _stream_throw("stream_socket_server: unix:// not supported on Windows");
        return -1;
#else
        fd = (int)socket(AF_UNIX, addr.sock_type, 0);
        if (fd < 0) {
            _stream_throw("stream_socket_server: socket() failed");
            return -1;
        }
        struct sockaddr_un unaddr;
        memset(&unaddr, 0, sizeof(unaddr));
        unaddr.sun_family = AF_UNIX;
        strncpy(unaddr.sun_path, addr.host, sizeof(unaddr.sun_path) - 1);
        unlink(addr.host);
        if (bind(fd, (struct sockaddr*)&unaddr, sizeof(unaddr)) < 0) {
            char buf[256];
            snprintf(buf, sizeof(buf), "stream_socket_server: bind(%s) failed: %s", addr.host, strerror(errno));
            STREAM_CLOSE(fd);
            _stream_throw(buf);
            return -1;
        }
        if (addr.sock_type == SOCK_STREAM && (flags & 8 /* STREAM_SERVER_LISTEN */)) {
            if (listen(fd, 128) < 0) {
                char buf[256];
                snprintf(buf, sizeof(buf), "stream_socket_server: listen() failed: %s", strerror(errno));
                STREAM_CLOSE(fd);
                _stream_throw(buf);
                return -1;
            }
        }
        return (t_int)fd;
#endif
    }

    // IPv4 TCP/UDP
    fd = (int)socket(AF_INET, addr.sock_type, addr.protocol);
    if (fd < 0) {
        _stream_throw("stream_socket_server: socket() failed");
        return -1;
    }

    int opt = 1;
    setsockopt(fd, SOL_SOCKET, SO_REUSEADDR, (const char*)&opt, sizeof(opt));

    struct sockaddr_in saddr;
    memset(&saddr, 0, sizeof(saddr));
    saddr.sin_family = AF_INET;
    saddr.sin_port = htons((u_short)addr.port);
    if (addr.host[0] == '\0' || strcmp(addr.host, "0.0.0.0") == 0) {
        saddr.sin_addr.s_addr = INADDR_ANY;
    } else {
        inet_pton(AF_INET, addr.host, &saddr.sin_addr);
    }

    if (bind(fd, (struct sockaddr*)&saddr, sizeof(saddr)) < 0) {
        int err = STREAM_ERRNO;
        char buf[512];
        snprintf(buf, sizeof(buf), "stream_socket_server: bind(%s:%d) failed (errno=%d)", addr.host, addr.port, err);
        STREAM_CLOSE(fd);
        _stream_throw(buf);
        return -1;
    }

    if (addr.sock_type == SOCK_STREAM && (flags & 8 /* STREAM_SERVER_LISTEN */)) {
        if (listen(fd, 128) < 0) {
            int err = STREAM_ERRNO;
            char buf[256];
            snprintf(buf, sizeof(buf), "stream_socket_server: listen() failed (errno=%d)", err);
            STREAM_CLOSE(fd);
            _stream_throw(buf);
            return -1;
        }
    }

    return (t_int)fd;
}

// ── stream_socket_accept ────────────────────────────────────
//   返回客户端 fd
//   timeout_ms: -1 表示使用默认（阻塞）
static inline t_int tphp_fn_stream_socket_accept(t_int server_fd, t_int timeout_ms) {
    struct sockaddr_in addr;
    socklen_t addr_len = sizeof(addr);

    // 超时等待：用 select 检查可读
    if (timeout_ms >= 0) {
        fd_set rfds;
        FD_ZERO(&rfds);
        FD_SET((int)server_fd, &rfds);
        struct timeval tv;
        tv.tv_sec = (long)(timeout_ms / 1000);
        tv.tv_usec = (long)((timeout_ms % 1000) * 1000);
        int sr = select((int)server_fd + 1, &rfds, NULL, NULL, &tv);
        if (sr <= 0) {
            _stream_throw("stream_socket_accept: timeout or error");
            return -1;
        }
    }

    int fd = (int)accept((int)server_fd, (struct sockaddr*)&addr, &addr_len);
    if (fd < 0) {
        int err = STREAM_ERRNO;
        char buf[128];
        snprintf(buf, sizeof(buf), "stream_socket_accept: accept() failed (errno=%d)", err);
        _stream_throw(buf);
        return -1;
    }

    return (t_int)fd;
}

// ── stream_socket_client ────────────────────────────────────
//   address: "tcp://host:port" / "udp://host:port"
static inline t_int tphp_fn_stream_socket_client(t_string address, t_int timeout_ms, t_int flags, t_array* context) {
    (void)context;
    (void)flags;
    tphp_fn_stream_init();

    _stream_addr_t addr;
    if (_stream_parse_address(address, &addr) != 0) {
        _stream_throw("stream_socket_client: unknown protocol in address");
        return -1;
    }

    if (strcmp(addr.proto, "unix") == 0) {
#ifdef _WIN32
        _stream_throw("stream_socket_client: unix:// not supported on Windows");
        return -1;
#else
        int fd = (int)socket(AF_UNIX, addr.sock_type, 0);
        if (fd < 0) {
            _stream_throw("stream_socket_client: socket() failed");
            return -1;
        }
        struct sockaddr_un unaddr;
        memset(&unaddr, 0, sizeof(unaddr));
        unaddr.sun_family = AF_UNIX;
        strncpy(unaddr.sun_path, addr.host, sizeof(unaddr.sun_path) - 1);
        if (connect(fd, (struct sockaddr*)&unaddr, sizeof(unaddr)) < 0) {
            char buf[256];
            snprintf(buf, sizeof(buf), "stream_socket_client: connect(%s) failed: %s", addr.host, strerror(errno));
            STREAM_CLOSE(fd);
            _stream_throw(buf);
            return -1;
        }
        return (t_int)fd;
#endif
    }

    // IPv4 TCP/UDP via getaddrinfo
    struct addrinfo hints, *res, *rp;
    memset(&hints, 0, sizeof(hints));
    hints.ai_family = AF_INET;
    hints.ai_socktype = addr.sock_type;
    hints.ai_protocol = addr.protocol;

    char port_str[16];
    snprintf(port_str, sizeof(port_str), "%d", addr.port);

    int gai_ret = getaddrinfo(addr.host, port_str, &hints, &res);
    if (gai_ret != 0) {
        char buf[256];
        snprintf(buf, sizeof(buf), "stream_socket_client: getaddrinfo(%s:%d) failed",
                 addr.host, addr.port);
        _stream_throw(buf);
        return -1;
    }

    int fd = -1;
    for (rp = res; rp != NULL; rp = rp->ai_next) {
        fd = (int)socket(rp->ai_family, rp->ai_socktype, rp->ai_protocol);
        if (fd < 0) continue;
        if (connect(fd, rp->ai_addr, (int)rp->ai_addrlen) == 0) {
            break;
        }
        STREAM_CLOSE(fd);
        fd = -1;
    }

    freeaddrinfo(res);

    if (fd < 0) {
        int err = STREAM_ERRNO;
        char buf[256];
        snprintf(buf, sizeof(buf), "stream_socket_client: connect(%s:%d) failed (errno=%d)",
                 addr.host, addr.port, err);
        _stream_throw(buf);
        return -1;
    }

    return (t_int)fd;
}

// ── stream_socket_recvfrom ──────────────────────────────────
//   flags: STREAM_OOB=1, STREAM_PEEK=2
//   返回收到的数据
static inline t_string tphp_fn_stream_socket_recvfrom(t_int fd, t_int length, t_int flags) {
    char* buf = (char*)malloc((size_t)length + 1);
    if (buf == NULL) {
        _stream_throw("stream_socket_recvfrom: malloc failed");
        return (t_string){0};
    }

    struct sockaddr_in addr;
    socklen_t addr_len = sizeof(addr);
    int n = recvfrom((int)fd, buf, (int)length, (int)flags,
                     (struct sockaddr*)&addr, &addr_len);
    if (n < 0) {
        free(buf);
        int err = STREAM_ERRNO;
        char buf2[128];
        snprintf(buf2, sizeof(buf2), "stream_socket_recvfrom: recvfrom() failed (errno=%d)", err);
        _stream_throw(buf2);
        return (t_string){0};
    }

    buf[n] = '\0';
    t_string result = tphp_rt_str_dup((t_string){.data = buf, .length = n, .is_local = false, .is_lit = false});
    free(buf);
    return result;
}

// ── stream_socket_sendto ────────────────────────────────────
//   address: "host:port" 或空字符串（使用已连接的目标）
static inline t_int tphp_fn_stream_socket_sendto(t_int fd, t_string data, t_int flags, t_string address) {
    const char* data_ptr = STR_PTR(data);
    int data_len = data.length;
    const char* addr_str = STR_PTR(address);

    int n;
    if (addr_str != NULL && addr_str[0] != '\0') {
        char host[256];
        int port = 0;
        const char* colon = strrchr(addr_str, ':');
        if (colon != NULL) {
            int hlen = (int)(colon - addr_str);
            if (hlen >= (int)sizeof(host)) hlen = sizeof(host) - 1;
            memcpy(host, addr_str, hlen);
            host[hlen] = '\0';
            port = atoi(colon + 1);
        } else {
            strncpy(host, addr_str, sizeof(host) - 1);
            host[sizeof(host) - 1] = '\0';
        }

        struct sockaddr_in saddr;
        memset(&saddr, 0, sizeof(saddr));
        saddr.sin_family = AF_INET;
        saddr.sin_port = htons((u_short)port);
        inet_pton(AF_INET, host, &saddr.sin_addr);

        n = sendto((int)fd, data_ptr, data_len, (int)flags,
                   (struct sockaddr*)&saddr, sizeof(saddr));
    } else {
        n = send((int)fd, data_ptr, data_len, (int)flags);
    }

    if (n < 0) {
        int err = STREAM_ERRNO;
        char buf[128];
        snprintf(buf, sizeof(buf), "stream_socket_sendto: failed (errno=%d)", err);
        _stream_throw(buf);
        return -1;
    }

    return (t_int)n;
}

// ── stream_socket_get_name ──────────────────────────────────
static inline t_string tphp_fn_stream_socket_get_name(t_int fd, t_bool want_peer) {
    struct sockaddr_in addr;
    socklen_t addr_len = sizeof(addr);
    int ret;

    if (want_peer) {
        ret = getpeername((int)fd, (struct sockaddr*)&addr, &addr_len);
    } else {
        ret = getsockname((int)fd, (struct sockaddr*)&addr, &addr_len);
    }

    if (ret < 0) {
        int err = STREAM_ERRNO;
        char buf[128];
        snprintf(buf, sizeof(buf), "stream_socket_get_name: failed (errno=%d)", err);
        _stream_throw(buf);
        return (t_string){0};
    }

    char ip[64];
    inet_ntop(AF_INET, &addr.sin_addr, ip, sizeof(ip));
    char full[80];
    snprintf(full, sizeof(full), "%s:%d", ip, ntohs(addr.sin_port));
    return tphp_rt_str_dup((t_string){.data = full, .length = (int)strlen(full), .is_local = false, .is_lit = false});
}

// ── stream_socket_shutdown ──────────────────────────────────
static inline t_bool tphp_fn_stream_socket_shutdown(t_int fd, t_int how) {
    int h;
    switch (how) {
        case 0: h = SHUT_RD; break;
        case 1: h = SHUT_WR; break;
        default: h = SHUT_RDWR; break;
    }
    if (shutdown((int)fd, h) < 0) {
        int err = STREAM_ERRNO;
        char buf[128];
        snprintf(buf, sizeof(buf), "stream_socket_shutdown: failed (errno=%d)", err);
        _stream_throw(buf);
        return false;
    }
    return true;
}

// ── stream_socket_enable_crypto（TLS 支持，需 OpenSSL 扩展） ──
//   当 ext/openssl 扩展加载时（openssl.h 在 stream.h 之前 include），
//   openssl.h 会定义 TPHP_STREAM_TLS_IMPLEMENTED 并提供真实实现，此处跳过。
//   未启用 OpenSSL 扩展时抛异常。
#ifndef TPHP_STREAM_TLS_IMPLEMENTED
#define TPHP_STREAM_TLS_IMPLEMENTED
// stub：未加载 openssl 扩展时抛异常
//   PHP 原生返回三态：true(成功) / false(失败) / 0(非阻塞需重试)
//   TinyPHP AOT 契约：错误统一抛异常，成功返回 1
//   因此 stub 抛异常后返回 -1（而非 0，避免与"需重试"语义混淆）
static inline t_int tphp_fn_stream_socket_enable_crypto(t_int fd, t_bool enable, t_int crypto_type) {
    (void)fd;
    (void)enable;
    (void)crypto_type;
    _stream_throw("stream_socket_enable_crypto: TLS not supported (OpenSSL extension not loaded)");
    return -1;
}
#endif

// ════════════════════════════════════════════════════════════
// 补充 API（对齐 PHP 原生 stream 扩展）
// ════════════════════════════════════════════════════════════

// ── stream_set_write_buffer：设置写缓冲（socket 无 stdio 缓冲，stub） ──
//   PHP: stream_set_write_buffer(resource $stream, int $buffer): int
//   返回 0 成功（socket 无 stdio 缓冲，恒返回 0）
static inline t_int tphp_fn_stream_set_write_buffer(t_int fd, t_int buffer) {
    (void)fd;
    (void)buffer;
    return 0;
}

// ── stream_set_timeout：设置读写超时 ──
//   PHP: stream_set_timeout(resource $stream, int $seconds, int $microseconds = 0): bool
//   用 setsockopt(SO_RCVTIMEO/SO_SNDTIMEO) 实现（仅对 socket fd 有效）
static inline t_bool tphp_fn_stream_set_timeout(t_int fd, t_int seconds, t_int microseconds) {
    if (fd < 0) return false;
#ifdef _WIN32
    DWORD ms = (DWORD)(seconds * 1000 + microseconds / 1000);
    if (setsockopt((SOCKET)fd, SOL_SOCKET, SO_RCVTIMEO, (const char*)&ms, sizeof(ms)) != 0) {
        int err = STREAM_ERRNO;
        char buf[128];
        snprintf(buf, sizeof(buf), "stream_set_timeout: SO_RCVTIMEO failed (errno=%d)", err);
        _stream_throw(buf);
        return false;
    }
    if (setsockopt((SOCKET)fd, SOL_SOCKET, SO_SNDTIMEO, (const char*)&ms, sizeof(ms)) != 0) {
        int err = STREAM_ERRNO;
        char buf[128];
        snprintf(buf, sizeof(buf), "stream_set_timeout: SO_SNDTIMEO failed (errno=%d)", err);
        _stream_throw(buf);
        return false;
    }
#else
    struct timeval tv;
    tv.tv_sec = (long)seconds;
    tv.tv_usec = (long)microseconds;
    if (setsockopt((int)fd, SOL_SOCKET, SO_RCVTIMEO, (const char*)&tv, sizeof(tv)) != 0) {
        int err = STREAM_ERRNO;
        char buf[128];
        snprintf(buf, sizeof(buf), "stream_set_timeout: SO_RCVTIMEO failed (errno=%d)", err);
        _stream_throw(buf);
        return false;
    }
    if (setsockopt((int)fd, SOL_SOCKET, SO_SNDTIMEO, (const char*)&tv, sizeof(tv)) != 0) {
        int err = STREAM_ERRNO;
        char buf[128];
        snprintf(buf, sizeof(buf), "stream_set_timeout: SO_SNDTIMEO failed (errno=%d)", err);
        _stream_throw(buf);
        return false;
    }
#endif
    return true;
}

// ── stream_get_contents：读取剩余所有数据 ──
//   PHP: stream_get_contents(resource $stream, ?int $length = null, int $offset = -1): string|false
//   TinyPHP: length = -1 表示读取所有，offset = -1 表示从当前位置
//   对 socket fd：offset 不支持（返回错误），用 recv 循环读取
static inline t_string tphp_fn_stream_get_contents(t_int fd, t_int length, t_int offset) {
    if (fd < 0) {
        _stream_throw("stream_get_contents: invalid fd");
        return (t_string){0};
    }
    if (offset >= 0) {
        _stream_throw("stream_get_contents: offset not supported for socket streams");
        return (t_string){0};
    }

    // 确定读取策略
    size_t cap = (length > 0) ? (size_t)length : 8192;  // 默认初始 8KB
    size_t total = 0;
    char* buf = (char*)malloc(cap);
    if (buf == NULL) {
        _stream_throw("stream_get_contents: malloc failed");
        return (t_string){0};
    }

    for (;;) {
        if (total >= (size_t)length && length > 0) break;  // 达到指定长度
        size_t want = (length > 0) ? (size_t)length - total : cap - total;
        if (want == 0) break;

        // 缓冲区不足时扩容（仅 length = -1 即读取所有时）
        if (length < 0 && total + want > cap) {
            cap *= 2;
            char* nb = (char*)realloc(buf, cap);
            if (nb == NULL) {
                free(buf);
                _stream_throw("stream_get_contents: realloc failed");
                return (t_string){0};
            }
            buf = nb;
            want = cap - total;
        }

        int n = recv((int)fd, buf + total, (int)want, 0);
        if (n < 0) {
            int err = STREAM_ERRNO;
#ifdef _WIN32
            if (err == STREAM_EWOULDBLOCK) break;  // 非阻塞，已读完
#else
            if (err == EAGAIN || err == EWOULDBLOCK) break;
#endif
            free(buf);
            char ebuf[128];
            snprintf(ebuf, sizeof(ebuf), "stream_get_contents: recv failed (errno=%d)", err);
            _stream_throw(ebuf);
            return (t_string){0};
        }
        if (n == 0) break;  // 对端关闭（EOF）
        total += (size_t)n;
    }

    t_string result = tphp_rt_str_dup((t_string){
        .data = buf, .length = (int)total,
        .is_local = false, .is_lit = false
    });
    free(buf);
    return result;
}

// ── stream_get_line：读到分隔符或长度 ──
//   PHP: stream_get_line(resource $stream, int $length, string $ending = ""): string|false
//   读到 $length 字节、$ending 字符串或 EOF（取先到者），不返回 ending
//   length: 最大读取长度（不含 ending）
//   ending: 分隔符（空字符串表示读到 length 或 EOF）
static inline t_string tphp_fn_stream_get_line(t_int fd, t_int length, t_string ending) {
    if (fd < 0) {
        _stream_throw("stream_get_line: invalid fd");
        return (t_string){0};
    }
    if (length <= 0) {
        _stream_throw("stream_get_line: length must be positive");
        return (t_string){0};
    }

    char* buf = (char*)malloc((size_t)length);
    if (buf == NULL) {
        _stream_throw("stream_get_line: malloc failed");
        return (t_string){0};
    }

    const char* end_pat = STR_PTR(ending);
    int end_len = ending.length;
    size_t total = 0;

    while (total < (size_t)length) {
        char c;
        int n = recv((int)fd, &c, 1, 0);
        if (n < 0) {
            int err = STREAM_ERRNO;
#ifdef _WIN32
            if (err == STREAM_EWOULDBLOCK && total > 0) break;
#else
            if ((err == EAGAIN || err == EWOULDBLOCK) && total > 0) break;
#endif
            free(buf);
            char ebuf[128];
            snprintf(ebuf, sizeof(ebuf), "stream_get_line: recv failed (errno=%d)", err);
            _stream_throw(ebuf);
            return (t_string){0};
        }
        if (n == 0) break;  // EOF

        buf[total++] = c;

        // 检查是否匹配 ending
        if (end_len > 0 && total >= (size_t)end_len) {
            if (memcmp(buf + total - end_len, end_pat, (size_t)end_len) == 0) {
                total -= (size_t)end_len;  // 不返回 ending
                break;
            }
        }
    }

    t_string result = tphp_rt_str_dup((t_string){
        .data = buf, .length = (int)total,
        .is_local = false, .is_lit = false
    });
    free(buf);
    return result;
}

// ── stream_get_meta_data：获取流元数据 ──
//   PHP: stream_get_meta_data(resource $stream): array
//   返回字段：timed_out(bool), blocked(bool), eof(bool), stream_type(string), unread_bytes(int)
//   注意：TinyPHP 用 fd 而非 resource，部分字段为近似值
static inline t_array* tphp_fn_stream_get_meta_data(t_int fd) {
    t_array* a = tphp_fn_arr_create(8);
    if (a == NULL) {
        _stream_throw("stream_get_meta_data: arr_create failed");
        return NULL;
    }
    tphp_rt_register((void*)a, 1);

    // timed_out: socket 无 PHP 层超时跟踪，恒 false
    tphp_fn_arr_set_str(a, STR_LIT("timed_out"),
        ((t_var){.type = TYPE_BOOL, .value._bool = false}));
    // blocked: 检查是否阻塞
#ifdef _WIN32
    // Windows: FIONBIO 是设置而非读取，简化为 true（默认阻塞）
    bool blocked = true;
#else
    int flags = fcntl((int)fd, F_GETFL, 0);
    bool blocked = (flags >= 0 && !(flags & O_NONBLOCK)) ? true : false;
#endif
    tphp_fn_arr_set_str(a, STR_LIT("blocked"),
        ((t_var){.type = TYPE_BOOL, .value._bool = blocked}));
    // eof: 用 MSG_PEEK 检测（不消费数据）
    char probe;
    int n = recv((int)fd, &probe, 1, MSG_PEEK);
    bool eof = (n == 0) ? true : false;
    tphp_fn_arr_set_str(a, STR_LIT("eof"),
        ((t_var){.type = TYPE_BOOL, .value._bool = eof}));
    // stream_type
    tphp_fn_arr_set_str(a, STR_LIT("stream_type"),
        ((t_var){.type = TYPE_STRING, .value._string = STR_LIT("socket_stream")}));
    // unread_bytes: socket 无缓冲队列长度查询，返回 0
    tphp_fn_arr_set_str(a, STR_LIT("unread_bytes"),
        ((t_var){.type = TYPE_INT, .value._int = 0}));
    // seekable: socket 不可 seek
    tphp_fn_arr_set_str(a, STR_LIT("seekable"),
        ((t_var){.type = TYPE_BOOL, .value._bool = false}));

    return a;
}

// ── stream_socket_pair：创建一对互连的 socket ──
//   PHP: stream_socket_pair(int $domain, int $type, int $protocol): array|false
//   domain: STREAM_PF_INET(2) / STREAM_PF_UNIX(1) / STREAM_PF_INET6(10)
//   type:   STREAM_SOCK_STREAM(1) / STREAM_SOCK_DGRAM(2) / STREAM_SOCK_RAW(3) 等
//   返回数组 [fd0, fd1]
//   Windows 不支持 AF_UNIX socketpair，用 TCP 回环连接模拟
static inline t_array* tphp_fn_stream_socket_pair(t_int domain, t_int type, t_int protocol) {
    (void)protocol;
    tphp_fn_stream_init();

    int sv[2] = {-1, -1};
    int af, st;

    switch (domain) {
        case 1:  af = AF_UNIX; break;
        case 2:  af = AF_INET; break;
        default:
            _stream_throw("stream_socket_pair: unsupported domain (only PF_UNIX/PF_INET)");
            return NULL;
    }
    switch (type) {
        case 1:  st = SOCK_STREAM; break;
        case 2:  st = SOCK_DGRAM; break;
        default:
            _stream_throw("stream_socket_pair: unsupported type (only SOCK_STREAM/SOCK_DGRAM)");
            return NULL;
    }

#ifdef _WIN32
    // Windows 无 socketpair(AF_UNIX)，用 TCP 回环模拟（仅 SOCK_STREAM）
    if (st != SOCK_STREAM) {
        _stream_throw("stream_socket_pair: Windows only supports SOCK_STREAM");
        return NULL;
    }
    // 创建监听 socket
    int listener = (int)socket(AF_INET, SOCK_STREAM, 0);
    if (listener < 0) {
        _stream_throw("stream_socket_pair: socket() failed");
        return NULL;
    }
    struct sockaddr_in addr;
    memset(&addr, 0, sizeof(addr));
    addr.sin_family = AF_INET;
    addr.sin_addr.s_addr = htonl(INADDR_LOOPBACK);
    addr.sin_port = 0;  // 让系统分配端口
    if (bind(listener, (struct sockaddr*)&addr, sizeof(addr)) != 0) {
        char buf[128];
        snprintf(buf, sizeof(buf), "stream_socket_pair: bind failed (errno=%d)", STREAM_ERRNO);
        STREAM_CLOSE(listener);
        _stream_throw(buf);
        return NULL;
    }
    socklen_t alen = sizeof(addr);
    if (getsockname(listener, (struct sockaddr*)&addr, &alen) != 0) {
        char buf[128];
        snprintf(buf, sizeof(buf), "stream_socket_pair: getsockname failed (errno=%d)", STREAM_ERRNO);
        STREAM_CLOSE(listener);
        _stream_throw(buf);
        return NULL;
    }
    if (listen(listener, 1) != 0) {
        char buf[128];
        snprintf(buf, sizeof(buf), "stream_socket_pair: listen failed (errno=%d)", STREAM_ERRNO);
        STREAM_CLOSE(listener);
        _stream_throw(buf);
        return NULL;
    }
    sv[0] = (int)socket(AF_INET, SOCK_STREAM, 0);
    if (sv[0] < 0) {
        STREAM_CLOSE(listener);
        _stream_throw("stream_socket_pair: socket(sv0) failed");
        return NULL;
    }
    if (connect(sv[0], (struct sockaddr*)&addr, sizeof(addr)) != 0) {
        char buf[128];
        snprintf(buf, sizeof(buf), "stream_socket_pair: connect failed (errno=%d)", STREAM_ERRNO);
        STREAM_CLOSE(listener);
        STREAM_CLOSE(sv[0]);
        _stream_throw(buf);
        return NULL;
    }
    sv[1] = (int)accept(listener, NULL, NULL);
    STREAM_CLOSE(listener);
    if (sv[1] < 0) {
        char buf[128];
        snprintf(buf, sizeof(buf), "stream_socket_pair: accept failed (errno=%d)", STREAM_ERRNO);
        STREAM_CLOSE(sv[0]);
        _stream_throw(buf);
        return NULL;
    }
#else
    // POSIX：直接用 socketpair()
    if (socketpair(af, st, 0, sv) != 0) {
        char buf[128];
        snprintf(buf, sizeof(buf), "stream_socket_pair: socketpair failed (errno=%d)", STREAM_ERRNO);
        _stream_throw(buf);
        return NULL;
    }
#endif

    t_array* a = tphp_fn_arr_create(2);
    if (a == NULL) {
        STREAM_CLOSE(sv[0]);
        STREAM_CLOSE(sv[1]);
        _stream_throw("stream_socket_pair: arr_create failed");
        return NULL;
    }
    tphp_rt_register((void*)a, 1);

    tphp_fn_arr_push(a, ((t_var){.type = TYPE_INT, .value._int = (t_int)sv[0]}));
    tphp_fn_arr_push(a, ((t_var){.type = TYPE_INT, .value._int = (t_int)sv[1]}));

    return a;
}
