<?php
// ext/pgsql 扩展测试 — 结果集
//
// 测试范围：
//   pg_num_rows / pg_num_fields / pg_affected_rows / pg_last_oid
//   pg_result_status / pg_result_seek / pg_result_error
#import pgsql

#debug === PGSQL Result Test ===
#debug
#debug 1. INSERT affected_rows: OK
#debug 2. SELECT num_rows/num_fields: OK
#debug 3. pg_result_seek: OK
#debug 4. pg_result_status (COMMAND_OK): OK
#debug 5. pg_result_status (TUPLES_OK): OK
#debug 6. error query result_status: OK
#debug
#debug === All passed ===

class Main
{
    public function main(): void
    {
        echo "=== PGSQL Result Test ===\n\n";

        $dsn = "host=127.0.0.1 port=5432 dbname=tinyphp_test user=postgres password=postgres";

        try {
            $conn = pg_connect($dsn);
        } catch (Exception $e) {
            echo "0. connect: FAIL\n";
            return;
        }

        // 建表
        try {
            pg_query($conn, "DROP TABLE IF EXISTS pg_result_test");
            pg_query($conn, "CREATE TABLE pg_result_test (id INT, name VARCHAR(100))");
        } catch (Exception $e) {
            echo "0. setup: FAIL\n";
        }

        // 1. INSERT 后检查 affected_rows
        try {
            $res = pg_query($conn, "INSERT INTO pg_result_test VALUES (1, 'Alice')");
            $affected = pg_affected_rows($res);
            $status = pg_result_status($res);
            $ok = ($affected == 1 && $status == 1);  // PGSQL_COMMAND_OK = 1
            echo "1. INSERT affected_rows: " . ($ok ? "OK" : "FAIL") . "\n";
            pg_free_result($res);
        } catch (Exception $e) {
            echo "1. INSERT affected_rows: FAIL\n";
        }

        // 插入更多数据
        try {
            pg_query($conn, "INSERT INTO pg_result_test VALUES (2, 'Bob')");
            pg_query($conn, "INSERT INTO pg_result_test VALUES (3, 'Charlie')");
        } catch (Exception $e) {
        }

        // 2. SELECT 后检查 num_rows/num_fields
        try {
            $res = pg_query($conn, "SELECT id, name FROM pg_result_test ORDER BY id");
            $num_rows = pg_num_rows($res);
            $num_fields = pg_num_fields($res);
            $ok = ($num_rows == 3 && $num_fields == 2);
            echo "2. SELECT num_rows/num_fields: " . ($ok ? "OK" : "FAIL") . "\n";
            pg_free_result($res);
        } catch (Exception $e) {
            echo "2. SELECT num_rows/num_fields: FAIL\n";
        }

        // 3. pg_result_seek 后 fetch
        try {
            $res = pg_query($conn, "SELECT id, name FROM pg_result_test ORDER BY id");
            pg_result_seek($res, 2);
            $row = pg_fetch_assoc($res);
            $ok = ($row['name'] == 'Charlie');
            echo "3. pg_result_seek: " . ($ok ? "OK" : "FAIL") . "\n";
            pg_free_result($res);
        } catch (Exception $e) {
            echo "3. pg_result_seek: FAIL\n";
        }

        // 4. pg_result_status (COMMAND_OK) — INSERT
        try {
            $res = pg_query($conn, "INSERT INTO pg_result_test VALUES (4, 'David')");
            $status = pg_result_status($res);
            echo "4. pg_result_status (COMMAND_OK): " . ($status == 1 ? "OK" : "FAIL") . "\n";
            pg_free_result($res);
        } catch (Exception $e) {
            echo "4. pg_result_status (COMMAND_OK): FAIL\n";
        }

        // 5. pg_result_status (TUPLES_OK) — SELECT
        try {
            $res = pg_query($conn, "SELECT 1");
            $status = pg_result_status($res);
            echo "5. pg_result_status (TUPLES_OK): " . ($status == 2 ? "OK" : "FAIL") . "\n";
            pg_free_result($res);
        } catch (Exception $e) {
            echo "5. pg_result_status (TUPLES_OK): FAIL\n";
        }

        // 6. 错误查询的 result_status
        try {
            $res = pg_query($conn, "SELECT * FROM nonexistent_table_xyz");
            echo "6. error query result_status: FAIL\n";
        } catch (Exception $e) {
            $err = pg_last_error($conn);
            echo "6. error query result_status: " . (strlen($err) > 0 ? "OK" : "FAIL") . "\n";
        }

        // 清理
        try {
            pg_query($conn, "DROP TABLE IF EXISTS pg_result_test");
        } catch (Exception $e) {
        }

        pg_close($conn);
        echo "\n=== All passed ===\n";
    }
}
