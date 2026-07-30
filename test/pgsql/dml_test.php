<?php
// ext/pgsql 扩展测试 — 高层 DML
//
// 测试范围：
//   pg_insert_result / pg_insert_sql / pg_update_result / pg_update_sql
//   pg_delete_result / pg_delete_sql / pg_select / pg_convert / pg_meta_data
#import pgsql

#debug === PGSQL DML Test ===
#debug
#debug 1. pg_insert_result: OK
#debug 2. pg_insert_sql: OK
#debug 3. pg_update_result: OK
#debug 4. pg_update_sql: OK
#debug 5. pg_delete_result: OK
#debug 6. pg_delete_sql: OK
#debug 7. pg_select: OK
#debug 8. pg_convert: OK
#debug 9. pg_meta_data: OK
#debug
#debug === All passed ===

class Main
{
    public function main(): void
    {
        echo "=== PGSQL DML Test ===\n\n";

        $dsn = "host=127.0.0.1 port=5432 dbname=tinyphp_test user=postgres password=postgres";

        try {
            $conn = pg_connect($dsn);
        } catch (Exception $e) {
            echo "0. connect: FAIL\n";
            return;
        }

        // 建表
        try {
            pg_query($conn, "DROP TABLE IF EXISTS pg_dml_test");
            pg_query($conn, "CREATE TABLE pg_dml_test (id SERIAL PRIMARY KEY, name VARCHAR(100), age INT)");
        } catch (Exception $e) {
            echo "0. setup: FAIL\n";
        }

        // 1. pg_insert_result — 执行 INSERT
        try {
            $res = pg_insert_result($conn, "pg_dml_test", ["name" => "Alice", "age" => 30]);
            $affected = pg_affected_rows($res);
            pg_free_result($res);
            echo "1. pg_insert_result: " . ($affected == 1 ? "OK" : "FAIL") . "\n";
        } catch (Exception $e) {
            echo "1. pg_insert_result: FAIL\n";
        }

        // 2. pg_insert_sql — 返回 SQL 字符串
        try {
            $sql = pg_insert_sql($conn, "pg_dml_test", ["name" => "Bob", "age" => 25]);
            $ok = (strlen($sql) > 0 && substr($sql, 0, 6) == "INSERT");
            echo "2. pg_insert_sql: " . ($ok ? "OK" : "FAIL") . "\n";
        } catch (Exception $e) {
            echo "2. pg_insert_sql: FAIL\n";
        }

        // 3. pg_update_result — 执行 UPDATE
        try {
            $res = pg_update_result($conn, "pg_dml_test", ["age" => 31], ["name" => "Alice"]);
            $affected = pg_affected_rows($res);
            pg_free_result($res);
            echo "3. pg_update_result: " . ($affected == 1 ? "OK" : "FAIL") . "\n";
        } catch (Exception $e) {
            echo "3. pg_update_result: FAIL\n";
        }

        // 4. pg_update_sql — 返回 UPDATE SQL 字符串
        try {
            $sql = pg_update_sql($conn, "pg_dml_test", ["age" => 26], ["name" => "Bob"]);
            $ok = (strlen($sql) > 0 && substr($sql, 0, 6) == "UPDATE");
            echo "4. pg_update_sql: " . ($ok ? "OK" : "FAIL") . "\n";
        } catch (Exception $e) {
            echo "4. pg_update_sql: FAIL\n";
        }

        // 5. pg_delete_result — 执行 DELETE
        try {
            // 先插入一条待删除数据
            pg_query($conn, "INSERT INTO pg_dml_test (name, age) VALUES ('Temp', 99)");
            $res = pg_delete_result($conn, "pg_dml_test", ["name" => "Temp"]);
            $affected = pg_affected_rows($res);
            pg_free_result($res);
            echo "5. pg_delete_result: " . ($affected == 1 ? "OK" : "FAIL") . "\n";
        } catch (Exception $e) {
            echo "5. pg_delete_result: FAIL\n";
        }

        // 6. pg_delete_sql — 返回 DELETE SQL 字符串
        try {
            $sql = pg_delete_sql($conn, "pg_dml_test", ["name" => "Alice"]);
            $ok = (strlen($sql) > 0 && substr($sql, 0, 6) == "DELETE");
            echo "6. pg_delete_sql: " . ($ok ? "OK" : "FAIL") . "\n";
        } catch (Exception $e) {
            echo "6. pg_delete_sql: FAIL\n";
        }

        // 7. pg_select — 返回结果数组
        try {
            $rows = pg_select($conn, "pg_dml_test", ["name" => "Alice"]);
            $ok = (count($rows) == 1);
            echo "7. pg_select: " . ($ok ? "OK" : "FAIL") . "\n";
        } catch (Exception $e) {
            echo "7. pg_select: FAIL\n";
        }

        // 8. pg_convert — 转换数组
        try {
            $converted = pg_convert($conn, "pg_dml_test", ["name" => "Charlie", "age" => 35]);
            $ok = (count($converted) == 2);
            echo "8. pg_convert: " . ($ok ? "OK" : "FAIL") . "\n";
        } catch (Exception $e) {
            echo "8. pg_convert: FAIL\n";
        }

        // 9. pg_meta_data — 返回字段元数据
        try {
            $meta = pg_meta_data($conn, "pg_dml_test");
            $ok = (count($meta) >= 3 && isset($meta["name"]));
            echo "9. pg_meta_data: " . ($ok ? "OK" : "FAIL") . "\n";
        } catch (Exception $e) {
            echo "9. pg_meta_data: FAIL\n";
        }

        // 清理
        try {
            pg_query($conn, "DROP TABLE IF EXISTS pg_dml_test");
        } catch (Exception $e) {
        }

        pg_close($conn);
        echo "\n=== All passed ===\n";
    }
}
