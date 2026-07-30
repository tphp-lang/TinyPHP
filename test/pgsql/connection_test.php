<?php
// ext/pgsql 扩展测试 — 连接管理
//
// 测试范围：
//   pg_connect / pg_close / pg_connection_status / pg_ping / pg_connection_reset / pg_pconnect
#import pgsql

#debug === PGSQL Connection Test ===
#debug
#debug 1. pg_connect normal: OK
#debug 2. pg_connect bad DSN: OK
#debug 3. pg_connection_status: OK
#debug 4. pg_ping: OK
#debug 5. pg_connection_reset: OK
#debug 6. pg_pconnect reuse: OK
#debug 7. pg_close: OK
#debug
#debug === All passed ===

class Main
{
    public function main(): void
    {
        echo "=== PGSQL Connection Test ===\n\n";

        $dsn = "host=127.0.0.1 port=5432 dbname=tinyphp_test user=postgres password=postgres";

        // 1. pg_connect 正常连接
        try {
            $conn = pg_connect($dsn);
            echo "1. pg_connect normal: " . ($conn > 0 ? "OK" : "FAIL") . "\n";
        } catch (Exception $e) {
            echo "1. pg_connect normal: FAIL\n";
        }

        // 2. pg_connect 错误 DSN（应抛异常）
        try {
            pg_connect("host=127.0.0.1 port=1 dbname=tinyphp_test user=postgres password=postgres");
            echo "2. pg_connect bad DSN: FAIL\n";
        } catch (Exception $e) {
            echo "2. pg_connect bad DSN: OK\n";
        }

        // 3. pg_connection_status
        try {
            $status = pg_connection_status($conn);
            echo "3. pg_connection_status: " . ($status == 0 ? "OK" : "FAIL") . "\n";
        } catch (Exception $e) {
            echo "3. pg_connection_status: FAIL\n";
        }

        // 4. pg_ping
        try {
            $ping = pg_ping($conn);
            echo "4. pg_ping: " . ($ping ? "OK" : "FAIL") . "\n";
        } catch (Exception $e) {
            echo "4. pg_ping: FAIL\n";
        }

        // 5. pg_connection_reset
        try {
            $reset = pg_connection_reset($conn);
            echo "5. pg_connection_reset: " . ($reset ? "OK" : "FAIL") . "\n";
        } catch (Exception $e) {
            echo "5. pg_connection_reset: FAIL\n";
        }

        // 6. pg_pconnect 复用
        try {
            $pc1 = pg_pconnect($dsn);
            $pc2 = pg_pconnect($dsn);
            $reuse = ($pc1 > 0 && $pc1 == $pc2);
            echo "6. pg_pconnect reuse: " . ($reuse ? "OK" : "FAIL") . "\n";
        } catch (Exception $e) {
            echo "6. pg_pconnect reuse: FAIL\n";
        }

        // 7. pg_close
        try {
            $closed = pg_close($conn);
            echo "7. pg_close: " . ($closed ? "OK" : "FAIL") . "\n";
        } catch (Exception $e) {
            echo "7. pg_close: FAIL\n";
        }

        echo "\n=== All passed ===\n";
    }
}
