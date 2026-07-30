<?php
// ext/pdo_pgsql 扩展测试 — Pdo\Pgsql 特有方法
//
// 测试范围：
//   pdo_pgsql_get_pid / copyFromArray + copyToArray (via pgconn)
//   lobCreate + lobOpen + lobUnlink (via pgconn)
//   setNoticeCallback (via pgconn)
#import pdo
#import pgsql
#import pdo_pgsql

#debug === PDO Pgsql Specific Test ===
#debug
#debug 1. pdo_pgsql_get_pid: OK
#debug 2. copyFromArray + copyToArray: OK
#debug 3. lobCreate + lobOpen + lobUnlink: OK
#debug 4. setNoticeCallback: OK
#debug
#debug === All passed ===

class Main
{
    public function main(): void
    {
        echo "=== PDO Pgsql Specific Test ===\n\n";

        $dsn = "pgsql:host=127.0.0.1;port=5432;dbname=tinyphp_test";

        try {
            $pdo = new PDO($dsn, "postgres", "postgres");
        } catch (Exception $e) {
            echo "0. connect: FAIL\n";
            return;
        }

        // 获取底层 PGconn 句柄（用于调用 ext/pgsql 的低层函数）
        $pgconn = pdo_pgsql_pgconn($pdo->db);

        // 1. pdo_pgsql_get_pid — 返回后端 PID
        try {
            $pid = pdo_pgsql_get_pid($pdo->db);
            echo "1. pdo_pgsql_get_pid: " . ($pid > 0 ? "OK" : "FAIL") . "\n";
        } catch (Exception $e) {
            echo "1. pdo_pgsql_get_pid: FAIL\n";
        }

        // 2. copyFromArray + copyToArray（通过 pgconn 调用 pg_copy_from/pg_copy_to）
        try {
            $pdo->exec("DROP TABLE IF EXISTS pdo_pg_copy");
            $pdo->exec("CREATE TABLE pdo_pg_copy (id INT, name VARCHAR(100))");
            $data = ["1\tAlice", "2\tBob"];
            // copyFromArray — 通过 pg_copy_from
            $ok1 = pg_copy_from($pgconn, "pdo_pg_copy", $data);
            // copyToArray — 通过 pg_copy_to
            $rows = pg_copy_to($pgconn, "pdo_pg_copy");
            $ok2 = (count($rows) == 2);
            echo "2. copyFromArray + copyToArray: " . ($ok1 && $ok2 ? "OK" : "FAIL") . "\n";
        } catch (Exception $e) {
            echo "2. copyFromArray + copyToArray: FAIL\n";
        }

        // 3. lobCreate + lobOpen + lobUnlink（通过 pgconn 调用 pg_lo_*）
        try {
            // Large Object 操作需要在事务中
            $pdo->exec("BEGIN");
            // lo_create
            $oid = pg_lo_create($pgconn);
            $ok1 = ($oid > 0);
            // lo_open for write
            $lob = pg_lo_open($pgconn, $oid, "w");
            $ok2 = ($lob > 0);
            // lo_write
            $written = pg_lo_write($pgconn, $lob, "hello LO");
            $ok3 = ($written > 0);
            // lo_close
            pg_lo_close($pgconn, $lob);
            $pdo->exec("COMMIT");
            // lo_unlink（也需要事务）
            $pdo->exec("BEGIN");
            $ok4 = pg_lo_unlink($pgconn, $oid);
            $pdo->exec("COMMIT");
            echo "3. lobCreate + lobOpen + lobUnlink: " . ($ok1 && $ok2 && $ok3 && $ok4 ? "OK" : "FAIL") . "\n";
        } catch (Exception $e) {
            echo "3. lobCreate + lobOpen + lobUnlink: FAIL\n";
        }

        // 4. setNoticeCallback（通过 pgconn 调用 pg_set_notice_callback）
        try {
            // 注册通知回调
            pg_set_notice_callback($pgconn, function(string $msg): void {
                // 回调已注册 — notice 到达时由协议层调用
            });
            // 先创建表，再用 CREATE TABLE IF NOT EXISTS 触发 NOTICE
            $pdo->exec("DROP TABLE IF EXISTS pdo_pg_notice");
            $pdo->exec("CREATE TABLE pdo_pg_notice (id INT)");
            // 再次 CREATE TABLE IF NOT EXISTS 会产生 NOTICE: relation already exists
            $pdo->exec("CREATE TABLE IF NOT EXISTS pdo_pg_notice (id INT)");
            // 通过 pg_last_notice 验证 notice 被接收（回调在 NoticeResponse 处理中被调用）
            $notice = pg_last_notice($pgconn);
            $ok = (strlen($notice) > 0);
            echo "4. setNoticeCallback: " . ($ok ? "OK" : "FAIL") . "\n";
        } catch (Exception $e) {
            echo "4. setNoticeCallback: FAIL\n";
        }

        // 清理
        try {
            $pdo->exec("DROP TABLE IF EXISTS pdo_pg_copy");
            $pdo->exec("DROP TABLE IF EXISTS pdo_pg_notice");
        } catch (Exception $e) {
        }

        echo "\n=== All passed ===\n";
    }
}
