<?php
// ext/pgsql 扩展测试 — 预处理语句
//
// 测试范围：
//   pg_prepare / pg_execute / pg_query_params
//   int 参数 / string 参数 / 重复 execute / 参数化 SELECT
#import pgsql

#debug === PGSQL Prepared Test ===
#debug
#debug 1. pg_prepare + pg_execute (int): OK
#debug 2. pg_prepare + pg_execute (string): OK
#debug 3. pg_query_params: OK
#debug 4. repeated execute: OK
#debug 5. parameterized SELECT: OK
#debug
#debug === All passed ===

class Main
{
    public function main(): void
    {
        echo "=== PGSQL Prepared Test ===\n\n";

        $dsn = "host=127.0.0.1 port=5432 dbname=tinyphp_test user=postgres password=postgres";

        try {
            $conn = pg_connect($dsn);
        } catch (Exception $e) {
            echo "0. connect: FAIL\n";
            return;
        }

        // 建表并插入数据
        try {
            pg_query($conn, "DROP TABLE IF EXISTS pg_prep_test");
            pg_query($conn, "CREATE TABLE pg_prep_test (id SERIAL PRIMARY KEY, name VARCHAR(100), age INT)");
            pg_query($conn, "INSERT INTO pg_prep_test (name, age) VALUES ('Alice', 30)");
            pg_query($conn, "INSERT INTO pg_prep_test (name, age) VALUES ('Bob', 25)");
        } catch (Exception $e) {
            echo "0. setup: FAIL\n";
        }

        // 1. pg_prepare + pg_execute（int 参数）
        try {
            pg_prepare($conn, "stmt_int", "SELECT name FROM pg_prep_test WHERE age = $1");
            $res = pg_execute($conn, "stmt_int", [30]);
            $num = pg_num_rows($res);
            $row = pg_fetch_assoc($res);
            echo "1. pg_prepare + pg_execute (int): " . ($num == 1 && $row['name'] == 'Alice' ? "OK" : "FAIL") . "\n";
            pg_free_result($res);
        } catch (Exception $e) {
            echo "1. pg_prepare + pg_execute (int): FAIL\n";
        }

        // 2. pg_prepare + pg_execute（string 参数）
        try {
            pg_prepare($conn, "stmt_str", "SELECT age FROM pg_prep_test WHERE name = $1");
            $res = pg_execute($conn, "stmt_str", ["Bob"]);
            $num = pg_num_rows($res);
            $row = pg_fetch_assoc($res);
            echo "2. pg_prepare + pg_execute (string): " . ($num == 1 && $row['age'] == 25 ? "OK" : "FAIL") . "\n";
            pg_free_result($res);
        } catch (Exception $e) {
            echo "2. pg_prepare + pg_execute (string): FAIL\n";
        }

        // 3. pg_query_params
        try {
            $res = pg_query_params($conn, "SELECT * FROM pg_prep_test WHERE age > $1", [20]);
            $num = pg_num_rows($res);
            echo "3. pg_query_params: " . ($num == 2 ? "OK" : "FAIL") . "\n";
            pg_free_result($res);
        } catch (Exception $e) {
            echo "3. pg_query_params: FAIL\n";
        }

        // 4. 重复 execute
        try {
            $res1 = pg_execute($conn, "stmt_int", [30]);
            $res2 = pg_execute($conn, "stmt_int", [30]);
            $ok = (pg_num_rows($res1) == 1 && pg_num_rows($res2) == 1);
            echo "4. repeated execute: " . ($ok ? "OK" : "FAIL") . "\n";
            pg_free_result($res1);
            pg_free_result($res2);
        } catch (Exception $e) {
            echo "4. repeated execute: FAIL\n";
        }

        // 5. 参数化 SELECT
        try {
            $res = pg_query_params($conn, "SELECT name, age FROM pg_prep_test WHERE name = $1", ["Alice"]);
            $row = pg_fetch_assoc($res);
            echo "5. parameterized SELECT: " . ($row['name'] == 'Alice' && $row['age'] == 30 ? "OK" : "FAIL") . "\n";
            pg_free_result($res);
        } catch (Exception $e) {
            echo "5. parameterized SELECT: FAIL\n";
        }

        // 清理
        try {
            pg_query($conn, "DROP TABLE IF EXISTS pg_prep_test");
        } catch (Exception $e) {
        }

        pg_close($conn);
        echo "\n=== All passed ===\n";
    }
}
