<?php
// ext/pgsql 扩展测试 — 错误处理
//
// 测试范围：
//   连接失败 / SQL 语法错误 / 违反约束（唯一约束）
//   pg_last_error / pg_result_error / try-catch 捕获
#import pgsql

#debug === PGSQL Error Test ===
#debug
#debug 1. connection failure: OK
#debug 2. SQL syntax error: OK
#debug 3. unique constraint violation: OK
#debug 4. pg_last_error: OK
#debug 5. pg_result_error (empty on success): OK
#debug 6. try-catch: OK
#debug
#debug === All passed ===

class Main
{
    public function main(): void
    {
        echo "=== PGSQL Error Test ===\n\n";

        $dsn = "host=127.0.0.1 port=5432 dbname=tinyphp_test user=postgres password=postgres";

        // 1. 连接失败（错误主机）
        try {
            pg_connect("host=127.0.0.1 port=1 dbname=tinyphp_test user=postgres password=postgres");
            echo "1. connection failure: FAIL\n";
        } catch (Exception $e) {
            echo "1. connection failure: OK\n";
        }

        // 建立正常连接
        try {
            $conn = pg_connect($dsn);
        } catch (Exception $e) {
            echo "0. connect: FAIL\n";
            return;
        }

        // 建表（含唯一约束）
        try {
            pg_query($conn, "DROP TABLE IF EXISTS pg_error_test");
            pg_query($conn, "CREATE TABLE pg_error_test (id INT UNIQUE, name VARCHAR(100))");
        } catch (Exception $e) {
            echo "0. setup: FAIL\n";
        }

        // 2. SQL 语法错误
        try {
            pg_query($conn, "SELEC * FROM pg_error_test");
            echo "2. SQL syntax error: FAIL\n";
        } catch (Exception $e) {
            echo "2. SQL syntax error: OK\n";
        }

        // 3. 违反约束（唯一约束）
        try {
            pg_query($conn, "INSERT INTO pg_error_test VALUES (1, 'Alice')");
            pg_query($conn, "INSERT INTO pg_error_test VALUES (1, 'Bob')");
            echo "3. unique constraint violation: FAIL\n";
        } catch (Exception $e) {
            echo "3. unique constraint violation: OK\n";
        }

        // 4. pg_last_error
        try {
            $err = pg_last_error($conn);
            echo "4. pg_last_error: " . (strlen($err) > 0 ? "OK" : "FAIL") . "\n";
        } catch (Exception $e) {
            echo "4. pg_last_error: FAIL\n";
        }

        // 5. pg_result_error（成功结果应返回空字符串）
        try {
            $res = pg_query($conn, "SELECT 1");
            $err = pg_result_error($res);
            echo "5. pg_result_error (empty on success): " . (strlen($err) == 0 ? "OK" : "FAIL") . "\n";
            pg_free_result($res);
        } catch (Exception $e) {
            echo "5. pg_result_error (empty on success): FAIL\n";
        }

        // 6. try-catch 捕获
        try {
            pg_query($conn, "DROP TABLE no_such_table_xyz");
            echo "6. try-catch: FAIL\n";
        } catch (Exception $e) {
            echo "6. try-catch: OK\n";
        }

        // 清理
        try {
            pg_query($conn, "DROP TABLE IF EXISTS pg_error_test");
        } catch (Exception $e) {
        }

        pg_close($conn);
        echo "\n=== All passed ===\n";
    }
}
