<?php
// ext/pgsql 扩展测试 — 简单查询 CRUD
//
// 测试范围：
//   CREATE TABLE / INSERT / SELECT / UPDATE / DELETE / DROP TABLE
//   pg_query + pg_affected_rows + pg_num_rows + pg_fetch_assoc
#import pgsql

#debug === PGSQL Query Test ===
#debug
#debug 1. CREATE TABLE: OK
#debug 2. INSERT: OK
#debug 3. SELECT: OK
#debug 4. UPDATE: OK
#debug 5. DELETE: OK
#debug 6. DROP TABLE: OK
#debug
#debug === All passed ===

class Main
{
    public function main(): void
    {
        echo "=== PGSQL Query Test ===\n\n";

        $dsn = "host=127.0.0.1 port=5432 dbname=tinyphp_test user=postgres password=postgres";

        try {
            $conn = pg_connect($dsn);
        } catch (Exception $e) {
            echo "0. connect: FAIL\n";
            return;
        }

        // 1. CREATE TABLE
        try {
            pg_query($conn, "DROP TABLE IF EXISTS pg_query_test");
            pg_query($conn, "CREATE TABLE pg_query_test (id SERIAL PRIMARY KEY, name VARCHAR(100), age INT)");
            echo "1. CREATE TABLE: OK\n";
        } catch (Exception $e) {
            echo "1. CREATE TABLE: FAIL\n";
        }

        // 2. INSERT（pg_query + pg_affected_rows）
        try {
            $res = pg_query($conn, "INSERT INTO pg_query_test (name, age) VALUES ('Alice', 30)");
            $affected = pg_affected_rows($res);
            echo "2. INSERT: " . ($affected == 1 ? "OK" : "FAIL") . "\n";
            pg_free_result($res);
        } catch (Exception $e) {
            echo "2. INSERT: FAIL\n";
        }

        // 3. SELECT（pg_query + pg_num_rows + pg_fetch_assoc）
        try {
            $res = pg_query($conn, "SELECT id, name, age FROM pg_query_test WHERE name = 'Alice'");
            $num = pg_num_rows($res);
            $row = pg_fetch_assoc($res);
            $ok = ($num == 1 && $row['name'] == 'Alice' && $row['age'] == 30);
            echo "3. SELECT: " . ($ok ? "OK" : "FAIL") . "\n";
            pg_free_result($res);
        } catch (Exception $e) {
            echo "3. SELECT: FAIL\n";
        }

        // 4. UPDATE
        try {
            $res = pg_query($conn, "UPDATE pg_query_test SET age = 31 WHERE name = 'Alice'");
            $affected = pg_affected_rows($res);
            echo "4. UPDATE: " . ($affected == 1 ? "OK" : "FAIL") . "\n";
            pg_free_result($res);
        } catch (Exception $e) {
            echo "4. UPDATE: FAIL\n";
        }

        // 5. DELETE
        try {
            $res = pg_query($conn, "DELETE FROM pg_query_test WHERE name = 'Alice'");
            $affected = pg_affected_rows($res);
            echo "5. DELETE: " . ($affected == 1 ? "OK" : "FAIL") . "\n";
            pg_free_result($res);
        } catch (Exception $e) {
            echo "5. DELETE: FAIL\n";
        }

        // 6. DROP TABLE
        try {
            pg_query($conn, "DROP TABLE IF EXISTS pg_query_test");
            echo "6. DROP TABLE: OK\n";
        } catch (Exception $e) {
            echo "6. DROP TABLE: FAIL\n";
        }

        pg_close($conn);
        echo "\n=== All passed ===\n";
    }
}
