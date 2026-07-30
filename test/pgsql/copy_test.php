<?php
// ext/pgsql 扩展测试 — COPY 操作
//
// 测试范围：
//   pg_copy_to / pg_copy_from / pg_put_copy_data / pg_put_copy_end / pg_end_copy
#import pgsql

#debug === PGSQL Copy Test ===
#debug
#debug 1. pg_copy_to: OK
#debug 2. pg_copy_from: OK
#debug 3. pg_put_copy_data + pg_put_copy_end: OK
#debug 4. pg_end_copy: OK
#debug
#debug === All passed ===

class Main
{
    public function main(): void
    {
        echo "=== PGSQL Copy Test ===\n\n";

        $dsn = "host=127.0.0.1 port=5432 dbname=tinyphp_test user=postgres password=postgres";

        try {
            $conn = pg_connect($dsn);
        } catch (Exception $e) {
            echo "0. connect: FAIL\n";
            return;
        }

        // 建表并插入数据
        try {
            pg_query($conn, "DROP TABLE IF EXISTS pg_copy_test");
            pg_query($conn, "CREATE TABLE pg_copy_test (id INT, name VARCHAR(100))");
            pg_query($conn, "INSERT INTO pg_copy_test VALUES (1, 'Alice')");
            pg_query($conn, "INSERT INTO pg_copy_test VALUES (2, 'Bob')");
            pg_query($conn, "INSERT INTO pg_copy_test VALUES (3, 'Charlie')");
        } catch (Exception $e) {
            echo "0. setup: FAIL\n";
        }

        // 1. pg_copy_to — 导出为数组
        try {
            $rows = pg_copy_to($conn, "pg_copy_test");
            $ok = (count($rows) == 3);
            echo "1. pg_copy_to: " . ($ok ? "OK" : "FAIL") . "\n";
        } catch (Exception $e) {
            echo "1. pg_copy_to: FAIL\n";
        }

        // 2. pg_copy_from — 从数组导入
        try {
            pg_query($conn, "TRUNCATE pg_copy_test");
            $data = [
                "10\tDavid",
                "20\tEve",
            ];
            $result = pg_copy_from($conn, "pg_copy_test", $data);
            // 验证导入结果
            $res = pg_query($conn, "SELECT count(*) FROM pg_copy_test");
            $row = pg_fetch_row($res);
            $count = intval($row[0]);
            pg_free_result($res);
            echo "2. pg_copy_from: " . ($result && $count == 2 ? "OK" : "FAIL") . "\n";
        } catch (Exception $e) {
            echo "2. pg_copy_from: FAIL\n";
        }

        // 3. pg_put_copy_data + pg_put_copy_end — 低层 COPY FROM STDIN
        try {
            pg_query($conn, "TRUNCATE pg_copy_test");
            // 发起 COPY FROM STDIN
            pg_query($conn, "COPY pg_copy_test FROM STDIN");
            // 发送数据行
            pg_put_copy_data($conn, "30\tFrank\n");
            pg_put_copy_data($conn, "40\tGrace\n");
            // 结束 COPY
            $end_ok = pg_put_copy_end($conn);
            // 验证导入结果
            $res = pg_query($conn, "SELECT count(*) FROM pg_copy_test");
            $row = pg_fetch_row($res);
            $count = intval($row[0]);
            pg_free_result($res);
            echo "3. pg_put_copy_data + pg_put_copy_end: " . ($end_ok && $count == 2 ? "OK" : "FAIL") . "\n";
        } catch (Exception $e) {
            echo "3. pg_put_copy_data + pg_put_copy_end: FAIL\n";
        }

        // 4. pg_end_copy — 同步连接状态（无进行中的 COPY 时应返回 true）
        try {
            $ok = pg_end_copy($conn);
            echo "4. pg_end_copy: " . ($ok ? "OK" : "FAIL") . "\n";
        } catch (Exception $e) {
            echo "4. pg_end_copy: FAIL\n";
        }

        // 清理
        try {
            pg_query($conn, "DROP TABLE IF EXISTS pg_copy_test");
        } catch (Exception $e) {
        }

        pg_close($conn);
        echo "\n=== All passed ===\n";
    }
}
