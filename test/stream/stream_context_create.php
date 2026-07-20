<?php
// stream_context_create.php — stream_context_create 占位实现测试
//   验证 stream_context_create() 返回占位值（0），且可作为 context 参数
//   传给 stream_socket_server（context 参数被 (void)context; 忽略）
#import stream
#debug === stream_context_create ===
#debug
#debug 1. ctx=0
#debug 2. ctx_type=int
#debug 3. server_with_ctx>=0
#debug 4. server_no_ctx>=0
#debug 5. ctx_ignored=true
#debug
#debug === OK ===

class Main
{
    public function main(): void
    {
        echo "=== stream_context_create ===\n\n";

        // 1. 基本调用：stream_context_create() 返回占位值 0
        $ctx = stream_context_create();
        echo '1. ctx=' . $ctx . "\n";                // 0

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

        echo "\n=== OK ===\n";
    }
}
