<?php
// ext/pgsql 扩展测试 — fetch 系列
//
// 测试范围：
//   pg_fetch_row / pg_fetch_assoc / pg_fetch_array (BOTH)
//   pg_fetch_all / pg_fetch_all_columns / 取完返回空数组
#import pgsql

#debug === PGSQL Fetch Test ===
#debug
#debug 1. pg_fetch_row: OK
#debug 2. pg_fetch_assoc: OK
#debug 3. pg_fetch_array (BOTH): OK
#debug 4. pg_fetch_all: OK
#debug 5. pg_fetch_all_columns: OK
#debug 6. fetch exhausted: OK
#debug
#debug === All passed ===

class Main
{
    public function main(): void
    {
        echo "=== PGSQL Fetch Test ===\n\n";

        $dsn = "host=127.0.0.1 port=5432 dbname=tinyphp_test user=postgres password=postgres";

        try {
            $conn = pg_connect($dsn);
        } catch (Exception $e) {
            echo "0. connect: FAIL\n";
            return;
        }

        // 建表并插入数据
        try {
            pg_query($conn, "DROP TABLE IF EXISTS pg_fetch_test");
            pg_query($conn, "CREATE TABLE pg_fetch_test (id INT, name VARCHAR(100))");
            pg_query($conn, "INSERT INTO pg_fetch_test VALUES (1, 'Alice')");
            pg_query($conn, "INSERT INTO pg_fetch_test VALUES (2, 'Bob')");
            pg_query($conn, "INSERT INTO pg_fetch_test VALUES (3, 'Charlie')");
        } catch (Exception $e) {
            echo "0. setup: FAIL\n";
        }

        // 1. pg_fetch_row（数字索引）
        try {
            $res = pg_query($conn, "SELECT * FROM pg_fetch_test ORDER BY id");
            $row = pg_fetch_row($res);
            $ok = ($row[0] == 1 && $row[1] == 'Alice');
            echo "1. pg_fetch_row: " . ($ok ? "OK" : "FAIL") . "\n";
            pg_free_result($res);
        } catch (Exception $e) {
            echo "1. pg_fetch_row: FAIL\n";
        }

        // 2. pg_fetch_assoc（关联数组）
        try {
            $res = pg_query($conn, "SELECT * FROM pg_fetch_test ORDER BY id");
            $row = pg_fetch_assoc($res);
            $ok = ($row['id'] == 1 && $row['name'] == 'Alice');
            echo "2. pg_fetch_assoc: " . ($ok ? "OK" : "FAIL") . "\n";
            pg_free_result($res);
        } catch (Exception $e) {
            echo "2. pg_fetch_assoc: FAIL\n";
        }

        // 3. pg_fetch_array（BOTH 模式）
        try {
            $res = pg_query($conn, "SELECT * FROM pg_fetch_test ORDER BY id");
            $row = pg_fetch_array($res, PGSQL_BOTH);
            $ok = ($row[0] == 1 && $row['id'] == 1 && $row[1] == 'Alice' && $row['name'] == 'Alice');
            echo "3. pg_fetch_array (BOTH): " . ($ok ? "OK" : "FAIL") . "\n";
            pg_free_result($res);
        } catch (Exception $e) {
            echo "3. pg_fetch_array (BOTH): FAIL\n";
        }

        // 4. pg_fetch_all（所有行）
        try {
            $res = pg_query($conn, "SELECT * FROM pg_fetch_test ORDER BY id");
            $all = pg_fetch_all($res, PGSQL_ASSOC);
            $ok = (count($all) == 3 && $all[0]['name'] == 'Alice' && $all[2]['name'] == 'Charlie');
            echo "4. pg_fetch_all: " . ($ok ? "OK" : "FAIL") . "\n";
            pg_free_result($res);
        } catch (Exception $e) {
            echo "4. pg_fetch_all: FAIL\n";
        }

        // 5. pg_fetch_all_columns（指定列）
        try {
            $res = pg_query($conn, "SELECT * FROM pg_fetch_test ORDER BY id");
            $cols = pg_fetch_all_columns($res, 1);
            $ok = (count($cols) == 3 && $cols[0] == 'Alice' && $cols[1] == 'Bob' && $cols[2] == 'Charlie');
            echo "5. pg_fetch_all_columns: " . ($ok ? "OK" : "FAIL") . "\n";
            pg_free_result($res);
        } catch (Exception $e) {
            echo "5. pg_fetch_all_columns: FAIL\n";
        }

        // 6. 取完返回空数组
        try {
            $res = pg_query($conn, "SELECT * FROM pg_fetch_test ORDER BY id");
            pg_fetch_row($res);
            pg_fetch_row($res);
            pg_fetch_row($res);
            $empty = pg_fetch_row($res);
            echo "6. fetch exhausted: " . (count($empty) == 0 ? "OK" : "FAIL") . "\n";
            pg_free_result($res);
        } catch (Exception $e) {
            echo "6. fetch exhausted: FAIL\n";
        }

        // 清理
        try {
            pg_query($conn, "DROP TABLE IF EXISTS pg_fetch_test");
        } catch (Exception $e) {
        }

        pg_close($conn);
        echo "\n=== All passed ===\n";
    }
}
