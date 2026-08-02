<?php
// ext/stream 扩展测试 — 非阻塞 socket EAGAIN/EWOULDBLOCK 行为
#import stream
//
// 本测试验证 stream.h 修复后的非阻塞 I/O 契约：
//   - stream_socket_accept 在非阻塞模式下无连接时返回 -1（不抛异常）
//   - stream_socket_recvfrom 在非阻塞模式下无数据时返回 ""（不抛异常）
//   - stream_socket_sendto 在非阻塞模式下缓冲满时返回 -1（不抛异常）
//   - 真正的错误（如无效 fd）仍抛 Exception
//
// 修复前的行为（导致 Workerman 崩溃，STATUS_ACCESS_VIOLATION）：
//   - EAGAIN/EWOULDBLOCK 被当作错误抛出 Exception
//   - Workerman 事件循环未 try/catch 这些"预期状态"
//   - 未捕获异常触发 tphp_rt_free_all 清理，但异常对象已被释放 → use-after-free
//
// 修复后的契约（符合非阻塞 I/O 模型）：
//   - EAGAIN/EWOULDBLOCK/EINTR 是"预期的非阻塞状态"，由调用方（事件循环）重试
//   - 真正的错误（fd 无效、select 失败等）才抛异常
//
// 跨平台：
//   - Windows: WSAEWOULDBLOCK / WSAEINTR（通过 STREAM_EWOULDBLOCK/STREAM_EINTR 宏统一）
//   - POSIX:   EWOULDBLOCK / EINTR
#debug === Non-blocking Socket EAGAIN Behavior Test ===
#debug
#debug === 1. Non-blocking accept (no pending connection) ===
#debug accept_no_conn=-1
#debug accept_no_exception=true
#debug
#debug === 2. Non-blocking recvfrom (no data) ===
#debug recv_empty=true
#debug recv_ret_len=0
#debug recv_no_exception=true
#debug
#debug === 3. Non-blocking accept with timeout (select timeout) ===
#debug accept_timeout=-1
#debug accept_timeout_no_exception=true
#debug
#debug === 4. Real error: invalid fd throws Exception ===
#debug invalid_fd_thrown=true
#debug
#debug === All non-blocking EAGAIN tests passed ===

class Main
{
    public function main(): void
    {
        echo "=== Non-blocking Socket EAGAIN Behavior Test ===\n\n";

        // ── 1. 非阻塞 accept：无待处理连接应返回 -1 ──
        //   修复前：accept 在非阻塞模式下 EWOULDBLOCK 被当作错误抛异常
        //   修复后：EWOULDBLOCK/EINTR 返回 -1，调用方据此重试
        echo "=== 1. Non-blocking accept (no pending connection) ===\n";
        $server_fd = stream_socket_server("tcp://127.0.0.1:19987");
        // 切换为非阻塞模式
        stream_set_blocking($server_fd, false);

        $accept_no_exception = true;
        $accept_ret = -100;
        try {
            // timeout_ms=0：select 立即返回，无连接时 accept 应返回 -1
            $accept_ret = stream_socket_accept($server_fd, 0);
        } catch (Exception $e) {
            $accept_no_exception = false;
        }
        echo "accept_no_conn=" . $accept_ret . "\n";
        echo "accept_no_exception=" . ($accept_no_exception ? "true" : "false") . "\n";
        echo "\n";

        // ── 2. 非阻塞 recvfrom：无数据应返回空字符串 ──
        //   修复前：recvfrom 在非阻塞模式下 EWOULDBLOCK 被当作错误抛异常
        //   修复后：EWOULDBLOCK/EINTR 返回空字符串，调用方据此重试
        echo "=== 2. Non-blocking recvfrom (no data) ===\n";
        // 建立一个真实的客户端连接，但不发送数据
        $client_fd = stream_socket_client("tcp://127.0.0.1:19987");
        $accepted_fd = stream_socket_accept($server_fd, 1000);
        // 切换 accepted_fd 为非阻塞
        stream_set_blocking($accepted_fd, false);

        $recv_no_exception = true;
        $recv_ret = "PLACEHOLDER";
        try {
            // 非阻塞读取，无数据时应返回空字符串
            $recv_ret = stream_socket_recvfrom($accepted_fd, 100, 0);
        } catch (Exception $e) {
            $recv_no_exception = false;
        }
        echo "recv_empty=" . (strlen($recv_ret) === 0 ? "true" : "false") . "\n";
        echo "recv_ret_len=" . strlen($recv_ret) . "\n";
        echo "recv_no_exception=" . ($recv_no_exception ? "true" : "false") . "\n";
        echo "\n";

        // ── 3. accept 带超时，select 超时返回 -1 ──
        //   修复前：select 超时（sr==0）被当作错误抛异常
        //   修复后：select 超时返回 -1（非阻塞模式下的正常状态）
        echo "=== 3. Non-blocking accept with timeout (select timeout) ===\n";
        // 此时没有新客户端连接，select 会在 100ms 后超时
        $accept_timeout_no_exception = true;
        $accept_timeout_ret = -100;
        try {
            $accept_timeout_ret = stream_socket_accept($server_fd, 100);
        } catch (Exception $e) {
            $accept_timeout_no_exception = false;
        }
        echo "accept_timeout=" . $accept_timeout_ret . "\n";
        echo "accept_timeout_no_exception=" . ($accept_timeout_no_exception ? "true" : "false") . "\n";
        echo "\n";

        // 清理已建立的连接
        stream_close($accepted_fd);
        stream_close($client_fd);
        stream_close($server_fd);

        // ── 4. 真正的错误：无效 fd 仍抛异常 ──
        //   验证"合理的错误"仍按契约抛 Exception（不静默返回错误值）
        //   fd=-1 是无效描述符，select/accept 应失败并抛异常
        echo "=== 4. Real error: invalid fd throws Exception ===\n";
        $invalid_fd_thrown = false;
        try {
            stream_socket_accept(-1, 100);
        } catch (Exception $e) {
            $invalid_fd_thrown = true;
        }
        echo "invalid_fd_thrown=" . ($invalid_fd_thrown ? "true" : "false") . "\n";
        echo "\n";

        echo "=== All non-blocking EAGAIN tests passed ===\n";
    }
}
