<?php
// ext/stream 扩展测试 — 跨平台 socket stream 功能（完整覆盖 21 个函数）
//
// 本测试覆盖 stream.h 中所有 21 个公共 API：
//   1. stream_strerror           — 错误码→字符串
//   2. stream_last_error         — 最近错误码
//   3. stream_set_read_buffer    — 设置读缓冲（stub，返回 0）
//   4. stream_set_write_buffer   — 设置写缓冲（stub，返回 0）
//   5. stream_set_timeout        — 设置读写超时（SO_RCVTIMEO/SO_SNDTIMEO）
//   6. stream_isatty             — 是否 TTY（socket 不是 TTY）
//   7. stream_socket_server      — TCP 服务端 bind+listen
//   8. stream_socket_client      — TCP 客户端 connect
//   9. stream_socket_accept      — 服务端 accept（带超时）
//  10. stream_socket_sendto      — 发送数据（返回字节数）
//  11. stream_socket_recvfrom    — 接收数据
//  12. stream_socket_get_name    — getsockname/getpeername
//  13. stream_select             — 多路复用（poll 模式，保留数组 key）
//  14. stream_set_blocking        — 阻塞/非阻塞模式切换
//  15. stream_socket_shutdown     — 关闭读写方向
//  16. stream_socket_enable_crypto — TLS 启用（未加载 openssl 时抛异常）
//  17. stream_get_contents       — 读取剩余所有数据
//  18. stream_get_line           — 读到分隔符或长度
//  19. stream_get_meta_data      — 获取流元数据（数组）
//  20. stream_socket_pair        — 创建一对互连的 socket
//  21. stream_close              — 关闭 socket
//
// 与原生 PHP 行为对比：
//   - 原生 PHP stream_socket_sendto 返回 int|false，TinyPHP 返回 t_int（错误抛异常）
//   - 原生 PHP stream_socket_recvfrom 返回 string|false，TinyPHP 返回 t_string
//   - 原生 PHP stream_socket_accept timeout 是 float 秒，TinyPHP 是 int 毫秒
//   - 原生 PHP 错误返回 false，TinyPHP 抛 Exception（AOT 契约）
//   - stream_strerror/stream_last_error 是 TinyPHP 独有（PHP sockets 扩展有 socket_strerror）
//
// 跨平台注意：
//   - strerror 消息取决于系统语言（Windows FormatMessageA / POSIX strerror）
//   - TCP echo 使用 127.0.0.1 本地回环，跨平台兼容
//   - stream_socket_pair: POSIX 用 socketpair(AF_UNIX)，Windows 用 TCP 回环模拟
//   - 端口 19999 选择高位避免冲突
#debug === 1. stream_strerror ===
#debug strerror_0_nonempty=true
#debug strerror_2_nonempty=true
#debug
#debug === 2. stream_last_error ===
#debug last_error_ge_zero=true
#debug
#debug === 3. TCP echo (127.0.0.1:19999) ===
#debug server_created=true
#debug client_created=true
#debug accepted=true
#debug sent_bytes=5
#debug received=hello
#debug echoed_bytes=5
#debug echoed=hello
#debug
#debug === 4. stream_socket_get_name ===
#debug peer_name_exact=true
#debug sock_name_exact=true
#debug
#debug === 5. stream_select ===
#debug select_ret=1
#debug write_ready=true
#debug read_empty=true
#debug
#debug === 6. stream_set_blocking ===
#debug set_nonblock_ok=true
#debug set_block_ok=true
#debug
#debug === 7. stream_set_read_buffer ===
#debug set_read_buffer_ret=0
#debug
#debug === 8. stream_set_write_buffer ===
#debug set_write_buffer_ret=0
#debug
#debug === 9. stream_set_timeout ===
#debug set_timeout_ok=true
#debug
#debug === 10. stream_isatty ===
#debug isatty_socket=false
#debug
#debug === 11. stream_get_contents ===
#debug contents_len=5
#debug contents_data=hello
#debug
#debug === 12. stream_get_line ===
#debug line_data=world
#debug
#debug === 13. stream_get_meta_data ===
#debug meta_timed_out=false
#debug meta_blocked=true
#debug meta_stream_type_nonempty=true
#debug meta_seekable=false
#debug
#debug === 14. stream_socket_enable_crypto ===
#debug crypto_no_tls_thrown=true
#debug
#debug === 15. stream_socket_shutdown ===
#debug shutdown_rdwr_ok=true
#debug
#debug === 16. stream_socket_pair ===
#debug pair_len=2
#debug pair_echo=ping
#debug
#debug === 17. Error cases ===
#debug invalid_proto_thrown=true
#debug connect_refused_thrown=true
#debug
#debug === 18. Constant values ===
#debug const_sock_raw=3
#debug const_ipproto_ip=0
#debug const_option_write_buffer=5
#debug const_option_chunk_size=7
#debug const_crypto_tls_client=3
#debug const_crypto_tlsv1_2_client=16
#debug
#debug === stream tests done ===

class Main
{
    public function main(): void
    {
        // ── 1. stream_strerror ──
        // 错误码 0：POSIX "Success" / Windows "The operation completed successfully."
        // 错误码 2：POSIX "No such file or directory" / Windows "The system cannot find the file specified."
        // 消息文本系统相关（语言/格式），仅验证非空
        echo "=== 1. stream_strerror ===\n";
        $err0 = stream_strerror(0);
        $err2 = stream_strerror(2);
        echo "strerror_0_nonempty=" . (strlen($err0) > 0 ? "true" : "false") . "\n";
        echo "strerror_2_nonempty=" . (strlen($err2) > 0 ? "true" : "false") . "\n";
        echo "\n";

        // ── 2. stream_last_error ──
        // 验证返回值是非负整数（具体值系统相关，不比较确切值）
        echo "=== 2. stream_last_error ===\n";
        $last_err = stream_last_error();
        echo "last_error_ge_zero=" . ($last_err >= 0 ? "true" : "false") . "\n";
        echo "\n";

        // ── 3. TCP echo ──
        // 原生 PHP stream_socket_sendto 返回字节数（int|false）
        // TinyPHP 返回 t_int（错误抛 Exception）
        echo "=== 3. TCP echo (127.0.0.1:19999) ===\n";
        $server_fd = stream_socket_server("tcp://127.0.0.1:19999");
        echo "server_created=" . ($server_fd >= 0 ? "true" : "false") . "\n";

        $client_fd = stream_socket_client("tcp://127.0.0.1:19999");
        echo "client_created=" . ($client_fd >= 0 ? "true" : "false") . "\n";

        // accept 超时 1000ms（TinyPHP 用毫秒，原生 PHP 用 float 秒）
        $accepted_fd = stream_socket_accept($server_fd, 1000);
        echo "accepted=" . ($accepted_fd >= 0 ? "true" : "false") . "\n";

        // 关键修复：stream_socket_sendto 返回发送的字节数（5），不是 "hello"
        $sent = stream_socket_sendto($client_fd, "hello", 0, "");
        echo "sent_bytes=" . $sent . "\n";

        $received = stream_socket_recvfrom($accepted_fd, 100, 0);
        echo "received=" . $received . "\n";

        // 回显
        $echoed_bytes = stream_socket_sendto($accepted_fd, $received, 0, "");
        echo "echoed_bytes=" . $echoed_bytes . "\n";

        $echoed = stream_socket_recvfrom($client_fd, 100, 0);
        echo "echoed=" . $echoed . "\n";
        echo "\n";

        // ── 4. stream_socket_get_name ──
        // 原生 PHP stream_socket_get_name(resource, bool $want_peer): string|false
        // TinyPHP 返回 t_string（"host:port" 格式）
        echo "=== 4. stream_socket_get_name ===\n";
        // getpeername on client_fd：远端是服务端 127.0.0.1:19999
        $peer_name = stream_socket_get_name($client_fd, true);
        // getsockname on accepted_fd：本地是服务端绑定地址 127.0.0.1:19999
        $sock_name = stream_socket_get_name($accepted_fd, false);
        echo "peer_name_exact=" . ($peer_name === "127.0.0.1:19999" ? "true" : "false") . "\n";
        echo "sock_name_exact=" . ($sock_name === "127.0.0.1:19999" ? "true" : "false") . "\n";
        echo "\n";

        // ── 5. stream_select ──
        // 原生 PHP stream_select(array &$read, array &$write, array &$except, int $tv_sec, int $tv_usec = 0): int|false
        // TinyPHP 修改数组 in-place，返回 t_int（就绪 fd 数）
        // poll 模式（tv_sec=0, tv_usec=0）：立即返回
        // client_fd 应可写（发送缓冲空闲），不应可读（已消费回显数据）
        echo "=== 5. stream_select ===\n";
        $read_arr = [$client_fd];
        $write_arr = [$client_fd];
        $empty_arr = [];
        $n = stream_select($read_arr, $write_arr, $empty_arr, 0, 0);
        echo "select_ret=" . $n . "\n";
        echo "write_ready=" . (count($write_arr) > 0 ? "true" : "false") . "\n";
        echo "read_empty=" . (count($read_arr) === 0 ? "true" : "false") . "\n";
        echo "\n";

        // ── 6. stream_set_blocking ──
        // 原生 PHP stream_set_blocking(resource, bool $enable): bool
        // 测试切换非阻塞→阻塞（双向）
        echo "=== 6. stream_set_blocking ===\n";
        $nb = stream_set_blocking($client_fd, false);
        echo "set_nonblock_ok=" . ($nb ? "true" : "false") . "\n";
        $b = stream_set_blocking($client_fd, true);
        echo "set_block_ok=" . ($b ? "true" : "false") . "\n";
        echo "\n";

        // ── 7. stream_set_read_buffer ──
        // stub 函数，socket 无 stdio 缓冲，总是返回 0
        echo "=== 7. stream_set_read_buffer ===\n";
        $rb = stream_set_read_buffer($client_fd, 1024);
        echo "set_read_buffer_ret=" . $rb . "\n";
        echo "\n";

        // ── 8. stream_set_write_buffer ──
        // stub 函数，socket 无 stdio 缓冲，总是返回 0（与 read_buffer 对称）
        echo "=== 8. stream_set_write_buffer ===\n";
        $wb = stream_set_write_buffer($client_fd, 1024);
        echo "set_write_buffer_ret=" . $wb . "\n";
        echo "\n";

        // ── 9. stream_set_timeout ──
        // 原生 PHP stream_set_timeout(resource, int $seconds, int $microseconds = 0): bool
        // TinyPHP 用 setsockopt(SO_RCVTIMEO/SO_SNDTIMEO) 实现
        echo "=== 9. stream_set_timeout ===\n";
        $to = stream_set_timeout($client_fd, 5, 0);
        echo "set_timeout_ok=" . ($to ? "true" : "false") . "\n";
        echo "\n";

        // ── 10. stream_isatty ──
        // socket 不是 TTY，应返回 false
        echo "=== 10. stream_isatty ===\n";
        $is_tty = stream_isatty($client_fd);
        echo "isatty_socket=" . ($is_tty ? "true" : "false") . "\n";
        echo "\n";

        // ── 11. stream_get_contents ──
        // 原生 PHP stream_get_contents(resource, ?int $length = null, int $offset = -1): string|false
        // TinyPHP: length=-1 读取所有，offset=-1 从当前位置
        // 通过 accepted_fd 发送数据，client_fd 读取
        echo "=== 11. stream_get_contents ===\n";
        stream_socket_sendto($accepted_fd, "hello", 0, "");
        $contents = stream_get_contents($client_fd, 5, -1);
        echo "contents_len=" . strlen($contents) . "\n";
        echo "contents_data=" . $contents . "\n";
        echo "\n";

        // ── 12. stream_get_line ──
        // 原生 PHP stream_get_line(resource, int $length, string $ending = ""): string|false
        // 读到 ending 分隔符（不返回 ending）或 length 或 EOF
        echo "=== 12. stream_get_line ===\n";
        // 发送 "world\n"，stream_get_line 读到 "\n" 为止（不返回 \n）
        stream_socket_sendto($accepted_fd, "world\n", 0, "");
        $line = stream_get_line($client_fd, 100, "\n");
        echo "line_data=" . $line . "\n";
        echo "\n";

        // ── 13. stream_get_meta_data ──
        // 原生 PHP stream_get_meta_data(resource): array
        // 返回 timed_out/blocked/eof/stream_type/seekable 等字段
        echo "=== 13. stream_get_meta_data ===\n";
        $meta = stream_get_meta_data($client_fd);
        echo "meta_timed_out=" . ($meta["timed_out"] === false ? "true" : "false") . "\n";
        echo "meta_blocked=" . ($meta["blocked"] === true ? "true" : "false") . "\n";
        echo "meta_stream_type_nonempty=" . (strlen($meta["stream_type"]) > 0 ? "true" : "false") . "\n";
        echo "meta_seekable=" . ($meta["seekable"] === false ? "true" : "false") . "\n";
        echo "\n";

        // ── 14. stream_socket_enable_crypto ──
        // 本测试未 #import openssl，stub 应抛异常
        // 原生 PHP 需 SSL 支持，TinyPHP 未加载 openssl 扩展时抛 Exception
        echo "=== 14. stream_socket_enable_crypto ===\n";
        $crypto_thrown = false;
        try {
            stream_socket_enable_crypto($client_fd, true, STREAM_CRYPTO_METHOD_TLS);
        } catch (Exception $e) {
            $crypto_thrown = true;
        }
        echo "crypto_no_tls_thrown=" . ($crypto_thrown ? "true" : "false") . "\n";
        echo "\n";

        // ── 15. stream_socket_shutdown ──
        // 原生 PHP stream_socket_shutdown(resource, int $mode): bool
        // STREAM_SHUT_RD=0, STREAM_SHUT_WR=1, STREAM_SHUT_RDWR=2
        echo "=== 15. stream_socket_shutdown ===\n";
        $shutdown_ok = stream_socket_shutdown($client_fd, STREAM_SHUT_RDWR);
        echo "shutdown_rdwr_ok=" . ($shutdown_ok ? "true" : "false") . "\n";
        echo "\n";

        // 清理 socket（close 对已 shutdown 的 socket 仍有效）
        stream_close($accepted_fd);
        stream_close($client_fd);
        stream_close($server_fd);

        // ── 16. stream_socket_pair ──
        // 原生 PHP stream_socket_pair(int $domain, int $type, int $protocol): array|false
        // POSIX: socketpair(AF_UNIX, SOCK_STREAM) — 返回 [fd0, fd1]
        // Windows: 用 TCP 回环模拟（仅 SOCK_STREAM）
        // 注意：使用 STREAM_PF_UNIX 而非 STREAM_PF_INET，因为 AF_UNIX socketpair
        //       在所有 POSIX 平台（Linux/macOS）都通用，AF_INET socketpair
        //       在某些环境可能受限
        echo "=== 16. stream_socket_pair ===\n";
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        echo "pair_len=" . count($pair) . "\n";
        // 通过 fd0 发送，fd1 接收
        stream_socket_sendto($pair[0], "ping", 0, "");
        $pair_echo = stream_socket_recvfrom($pair[1], 100, 0);
        echo "pair_echo=" . $pair_echo . "\n";
        stream_close($pair[0]);
        stream_close($pair[1]);
        echo "\n";

        // ── 17. Error cases ──
        // 原生 PHP 错误返回 false（配合 &$error_code），TinyPHP 抛 Exception
        echo "=== 17. Error cases ===\n";
        // 无效协议 "foo://"
        $invalid_proto_thrown = false;
        try {
            stream_socket_server("foo://127.0.0.1:19999");
        } catch (Exception $e) {
            $invalid_proto_thrown = true;
        }
        echo "invalid_proto_thrown=" . ($invalid_proto_thrown ? "true" : "false") . "\n";

        // 连接被拒绝（端口 1 通常无服务端监听，localhost 立即拒绝）
        $connect_refused_thrown = false;
        try {
            stream_socket_client("tcp://127.0.0.1:1", 500, 2);
        } catch (Exception $e) {
            $connect_refused_thrown = true;
        }
        echo "connect_refused_thrown=" . ($connect_refused_thrown ? "true" : "false") . "\n";
        echo "\n";

        // ── 18. Constant values ──
        // 验证常量值与 PHP 原生一致
        echo "=== 18. Constant values ===\n";
        echo "const_sock_raw=" . STREAM_SOCK_RAW . "\n";
        echo "const_ipproto_ip=" . STREAM_IPPROTO_IP . "\n";
        echo "const_option_write_buffer=" . STREAM_OPTION_WRITE_BUFFER . "\n";
        echo "const_option_chunk_size=" . STREAM_OPTION_CHUNK_SIZE . "\n";
        echo "const_crypto_tls_client=" . STREAM_CRYPTO_METHOD_TLS_CLIENT . "\n";
        echo "const_crypto_tlsv1_2_client=" . STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT . "\n";
        echo "\n";

        echo "=== stream tests done ===\n";
    }
}
