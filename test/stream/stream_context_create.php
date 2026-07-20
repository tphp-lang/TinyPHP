<?php
// stream_context_create.php — stream_context_create 占位实现测试
//   验证 stream_context_create() 返回占位值（0），且可作为 context 参数
//   传给 stream_socket_server（context 参数被 (void)context; 忽略）
//   覆盖三种调用形式：
//     1. stream_context_create()             — 无参数（默认 NULL）
//     2. stream_context_create([])           — 空数组
//     3. stream_context_create($contextOpts) — 非空数组（典型框架用法）
#import stream
#debug === stream_context_create ===
#debug
#debug 1. ctx_no_arg=0
#debug 2. ctx_type=int
#debug 3. server_with_ctx>=0
#debug 4. server_no_ctx>=0
#debug 5. ctx_ignored=true
#debug 6. ctx_empty_array=0
#debug 7. ctx_with_options=0
#debug 8. server_with_opts_ctx>=0
#debug
#debug === OK ===

class Main
{
    public function main(): void
    {
        echo "=== stream_context_create ===\n\n";

        // 1. 基本调用：stream_context_create() 返回占位值 0
        $ctx = stream_context_create();
        echo '1. ctx_no_arg=' . $ctx . "\n";      // 0

        // 2. 类型验证：返回值是 t_int（不是 array/object/resource）
        //    TinyPHP 无 gettype()，用 is_int 验证
        $isInt = $ctx === 0;
        echo '2. ctx_type=' . ($isInt ? 'int' : 'other') . "\n";

        // 3. 将 context 传给 stream_socket_server（context 参数被忽略）
        //    server_fd 应为非负数（成功创建）
        $serverWithCtx = stream_socket_server("tcp://127.0.0.1:19998", 12, $ctx);
        echo '3. server_with_ctx>=' . ($serverWithCtx >= 0 ? '0' : '-1') . "\n";

        // 4. 不传 context（用默认值 NULL）— 行为应与传 $ctx 一致
        $serverNoCtx = stream_socket_server("tcp://127.0.0.1:19997");
        echo '4. server_no_ctx>=' . ($serverNoCtx >= 0 ? '0' : '-1') . "\n";

        // 5. 验证 context 参数被忽略：传 0 与传 NULL 行为相同
        //    （TinyPHP stream_socket_server 实现中 (void)context; 忽略该参数）
        $ctxIgnored = ($serverWithCtx >= 0) === ($serverNoCtx >= 0);
        echo '5. ctx_ignored=' . ($ctxIgnored ? 'true' : 'false') . "\n";

        // 6. 传空数组 stream_context_create([]) — 与无参数等价
        $ctxEmpty = stream_context_create([]);
        echo '6. ctx_empty_array=' . $ctxEmpty . "\n";   // 0

        // 7. 传非空数组（典型框架用法，如 workerman 的 stream_context_create($contextOpts)）
        //    options 内容被忽略，仅验证签名接受 1 个数组参数
        $contextOpts = [
            'socket' => [
                'bindto' => '0.0.0.0:8080',
            ],
        ];
        $ctxOpts = stream_context_create($contextOpts);
        echo '7. ctx_with_options=' . $ctxOpts . "\n";   // 0

        // 8. 将带 options 的 context 传给 stream_socket_server
        $serverWithOptsCtx = stream_socket_server("tcp://127.0.0.1:19996", 12, $ctxOpts);
        echo '8. server_with_opts_ctx>=' . ($serverWithOptsCtx >= 0 ? '0' : '-1') . "\n";

        echo "\n=== OK ===\n";
    }
}
