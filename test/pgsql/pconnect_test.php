<?php
// ext/pgsql 扩展测试 — 持久连接
//
// 测试范围：
//   pg_pconnect / 连接池复用 / PGSQL_CONNECT_FORCE_NEW / pg_close 行为
#import pgsql

#debug === PGSQL Persistent Connection Test ===
#debug
#debug 1. pg_pconnect reuse (same PID): OK
#debug 2. PGSQL_CONNECT_FORCE_NEW (different PID): OK
#debug 3. pg_close + pconnect again: OK
#debug
#debug === All passed ===

class Main
{
    // 辅助：通过 SELECT pg_backend_pid() 获取后端 PID
    private function getPid(int $conn): int
    {
        $res = pg_query($conn, "SELECT pg_backend_pid()");
        $row = pg_fetch_row($res);
        $pid = intval($row[0]);
        pg_free_result($res);
        return $pid;
    }

    public function main(): void
    {
        echo "=== PGSQL Persistent Connection Test ===\n\n";

        $dsn = "host=127.0.0.1 port=5432 dbname=tinyphp_test user=postgres password=postgres";

        // 1. pg_pconnect 两次调用同 DSN 应复用连接（getPid 相同）
        try {
            $pc1 = pg_pconnect($dsn);
            $pc2 = pg_pconnect($dsn);
            $pid1 = $this->getPid($pc1);
            $pid2 = $this->getPid($pc2);
            $reuse = ($pid1 > 0 && $pid1 == $pid2);
            echo "1. pg_pconnect reuse (same PID): " . ($reuse ? "OK" : "FAIL") . "\n";
        } catch (Exception $e) {
            echo "1. pg_pconnect reuse (same PID): FAIL\n";
        }

        // 2. PGSQL_CONNECT_FORCE_NEW 创建新连接（getPid 不同）
        try {
            $pc3 = pg_pconnect($dsn, PGSQL_CONNECT_FORCE_NEW);
            $pid3 = $this->getPid($pc3);
            $diff = ($pid3 > 0 && $pid3 != $pid1);
            echo "2. PGSQL_CONNECT_FORCE_NEW (different PID): " . ($diff ? "OK" : "FAIL") . "\n";
        } catch (Exception $e) {
            echo "2. PGSQL_CONNECT_FORCE_NEW (different PID): FAIL\n";
        }

        // 3. pg_close 后 pconnect 应能再次获取连接
        try {
            // 关闭第一个持久连接
            pg_close($pc1);
            // 再次 pconnect 应成功
            $pc4 = pg_pconnect($dsn);
            $pid4 = $this->getPid($pc4);
            $ok = ($pc4 > 0 && $pid4 > 0);
            echo "3. pg_close + pconnect again: " . ($ok ? "OK" : "FAIL") . "\n";
        } catch (Exception $e) {
            echo "3. pg_close + pconnect again: FAIL\n";
        }

        // 清理：关闭剩余连接
        try {
            pg_close($pc2);
        } catch (Exception $e) {
        }
        try {
            pg_close($pc3);
        } catch (Exception $e) {
        }
        try {
            pg_close($pc4);
        } catch (Exception $e) {
        }

        echo "\n=== All passed ===\n";
    }
}
