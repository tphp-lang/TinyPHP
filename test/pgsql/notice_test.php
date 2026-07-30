<?php
// ext/pgsql 扩展测试 — 通知回调
//
// 测试范围：
//   pg_set_notice_callback — 注册回调后执行产生 NOTICE 的 SQL，验证回调被调用
#import pgsql

#debug === PGSQL Notice Callback Test ===
#debug
#debug 1. register callback: OK
#debug 2. NOTICE triggered: OK
#debug 3. callback invoked: OK
#debug
#debug === All passed ===

class Main
{
    public function main(): void
    {
        echo "=== PGSQL Notice Callback Test ===\n\n";

        $dsn = "host=127.0.0.1 port=5432 dbname=tinyphp_test user=postgres password=postgres";

        try {
            $conn = pg_connect($dsn);
        } catch (Exception $e) {
            echo "0. connect: FAIL\n";
            return;
        }

        // 用于收集 notice 消息（闭包捕获数组指针，tphp 中数组为指针类型，
        // 闭包内 push 不触发 realloc 时外层可见修改）
        $notices = [];

        // 1. 注册通知回调
        try {
            pg_set_notice_callback($conn, function(string $msg) use ($notices): void {
                $notices[] = $msg;
            });
            echo "1. register callback: OK\n";
        } catch (Exception $e) {
            echo "1. register callback: FAIL\n";
        }

        // 2. 执行产生 NOTICE 的 SQL
        // DROP TABLE IF EXISTS 对不存在的表会产生 NOTICE: table does not exist
        try {
            pg_query($conn, "DROP TABLE IF EXISTS notice_nonexistent_xyz");
            // 通过 pg_last_notice 验证 NOTICE 被接收
            $notice = pg_last_notice($conn);
            $ok = (strlen($notice) > 0);
            echo "2. NOTICE triggered: " . ($ok ? "OK" : "FAIL") . "\n";
        } catch (Exception $e) {
            echo "2. NOTICE triggered: FAIL\n";
        }

        // 3. 验证回调被调用（notices 数组应有内容）
        try {
            $ok = (count($notices) > 0);
            echo "3. callback invoked: " . ($ok ? "OK" : "FAIL") . "\n";
        } catch (Exception $e) {
            echo "3. callback invoked: FAIL\n";
        }

        pg_close($conn);
        echo "\n=== All passed ===\n";
    }
}
