<?php
// ext/stream 扩展测试 — 跨平台 socket stream 功能
// 覆盖：strerror + TCP echo（本地回环）+ set_blocking + shutdown
//
// 跨平台注意：
//   - strerror 返回的消息取决于系统语言（Windows FormatMessageA / POSIX strerror）
//     不比较确切文本，只验证返回非空字符串
//   - TCP echo 使用 127.0.0.1 本地回环，跨平台兼容
//   - 端口 19999 选择高位避免冲突
//   - stream_isatty 的返回值取决于运行环境（TTY/管道），不纳入 #debug 比较
#debug === 1. stream_strerror ===
#debug strerror_nonempty=true
#debug strerror_2_nonempty=true
#debug
#debug === 2. TCP echo (127.0.0.1:19999) ===
#debug sent=hello
#debug received=hello
#debug echoed=hello
#debug
#debug === 3. stream_set_blocking ===
#debug set_blocking_ok=true
#debug
#debug === 4. stream_socket_shutdown ===
#debug shutdown_ok=true

class Main
{
    public function main(): void
    {
        // ── 1. stream_strerror ──
        // 不比较确切文本（取决于系统语言），只验证返回非空
        $err0 = stream_strerror(0);
        $err2 = stream_strerror(2);
        echo "=== 1. stream_strerror ===\n";
        echo "strerror_nonempty=" . (strlen($err0) > 0 ? "true" : "false") . "\n";
        echo "strerror_2_nonempty=" . (strlen($err2) > 0 ? "true" : "false") . "\n";
        echo "\n";

        // ── 2. TCP echo ──
        echo "=== 2. TCP echo (127.0.0.1:19999) ===\n";
        // 服务端：绑定 127.0.0.1:19999
        $server_fd = stream_socket_server("tcp://127.0.0.1:19999");
        if ($server_fd < 0) {
            echo "server_failed\n";
            return;
        }

        // 客户端：连接（localhost 即时完成）
        $client_fd = stream_socket_client("tcp://127.0.0.1:19999");
        if ($client_fd < 0) {
            echo "client_failed\n";
            stream_close($server_fd);
            return;
        }

        // 服务端：accept（1 秒超时）
        $accepted_fd = stream_socket_accept($server_fd, 1000);
        if ($accepted_fd < 0) {
            echo "accept_failed\n";
            stream_close($client_fd);
            stream_close($server_fd);
            return;
        }

        // 客户端发送 "hello"
        $sent = stream_socket_sendto($client_fd, "hello", 0, "");
        echo "sent=" . ($sent > 0 ? "hello" : "failed") . "\n";

        // 服务端接收
        $received = stream_socket_recvfrom($accepted_fd, 100, 0);
        echo "received=" . $received . "\n";

        // 服务端回显
        stream_socket_sendto($accepted_fd, $received, 0, "");

        // 客户端接收回显
        $echoed = stream_socket_recvfrom($client_fd, 100, 0);
        echo "echoed=" . $echoed . "\n";
        echo "\n";

        // ── 3. stream_set_blocking ──
        echo "=== 3. stream_set_blocking ===\n";
        $blocking_ok = stream_set_blocking($client_fd, false);
        echo "set_blocking_ok=" . ($blocking_ok ? "true" : "false") . "\n";
        echo "\n";

        // ── 4. stream_socket_shutdown ──
        echo "=== 4. stream_socket_shutdown ===\n";
        $shutdown_ok = stream_socket_shutdown($client_fd, STREAM_SHUT_RDWR);
        echo "shutdown_ok=" . ($shutdown_ok ? "true" : "false") . "\n";

        // 清理
        stream_close($accepted_fd);
        stream_close($client_fd);
        stream_close($server_fd);
    }
}
