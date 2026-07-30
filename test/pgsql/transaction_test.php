<?php
// ext/pgsql 扩展测试 — 事务
//
// 测试范围：
//   BEGIN / COMMIT / ROLLBACK / pg_transaction_status
#import pgsql

#debug === PGSQL Transaction Test ===
#debug
#debug 1. BEGIN + INSERT + COMMIT: OK
#debug 2. BEGIN + INSERT + ROLLBACK: OK
#debug 3. pg_transaction_status (IDLE): OK
#debug 4. pg_transaction_status (INTRANS): OK
#debug
#debug === All passed ===

class Main
{
    public function main(): void
    {
        echo "=== PGSQL Transaction Test ===\n\n";

        $dsn = "host=127.0.0.1 port=5432 dbname=tinyphp_test user=postgres password=postgres";

        try {
            $conn = pg_connect($dsn);
        } catch (Exception $e) {
            echo "0. connect: FAIL\n";
            return;
        }

        // 建表
        try {
            pg_query($conn, "DROP TABLE IF EXISTS pg_txn_test");
            pg_query($conn, "CREATE TABLE pg_txn_test (id INT, name VARCHAR(100))");
        } catch (Exception $e) {
            echo "0. setup: FAIL\n";
        }

        // 1. BEGIN + INSERT + COMMIT
        try {
            pg_query($conn, "BEGIN");
            pg_query($conn, "INSERT INTO pg_txn_test VALUES (1, 'Alice')");
            pg_query($conn, "COMMIT");
            // 验证数据已提交
            $res = pg_query($conn, "SELECT count(*) FROM pg_txn_test");
            $row = pg_fetch_row($res);
            $count = intval($row[0]);
            pg_free_result($res);
            echo "1. BEGIN + INSERT + COMMIT: " . ($count == 1 ? "OK" : "FAIL") . "\n";
        } catch (Exception $e) {
            echo "1. BEGIN + INSERT + COMMIT: FAIL\n";
        }

        // 2. BEGIN + INSERT + ROLLBACK（数据应回滚）
        try {
            pg_query($conn, "BEGIN");
            pg_query($conn, "INSERT INTO pg_txn_test VALUES (2, 'Bob')");
            pg_query($conn, "ROLLBACK");
            // 验证数据已回滚（仍为 1 行）
            $res = pg_query($conn, "SELECT count(*) FROM pg_txn_test");
            $row = pg_fetch_row($res);
            $count = intval($row[0]);
            pg_free_result($res);
            echo "2. BEGIN + INSERT + ROLLBACK: " . ($count == 1 ? "OK" : "FAIL") . "\n";
        } catch (Exception $e) {
            echo "2. BEGIN + INSERT + ROLLBACK: FAIL\n";
        }

        // 3. pg_transaction_status — 空闲时应为 IDLE (0)
        try {
            $status = pg_transaction_status($conn);
            echo "3. pg_transaction_status (IDLE): " . ($status == 0 ? "OK" : "FAIL") . "\n";
        } catch (Exception $e) {
            echo "3. pg_transaction_status (IDLE): FAIL\n";
        }

        // 4. pg_transaction_status — 事务中应为 INTRANS (2)
        try {
            pg_query($conn, "BEGIN");
            $status = pg_transaction_status($conn);
            pg_query($conn, "ROLLBACK");
            // PGSQL_TRANSACTION_INTRANS = 2
            echo "4. pg_transaction_status (INTRANS): " . ($status == 2 ? "OK" : "FAIL") . "\n";
        } catch (Exception $e) {
            echo "4. pg_transaction_status (INTRANS): FAIL\n";
        }

        // 清理
        try {
            pg_query($conn, "DROP TABLE IF EXISTS pg_txn_test");
        } catch (Exception $e) {
        }

        pg_close($conn);
        echo "\n=== All passed ===\n";
    }
}
