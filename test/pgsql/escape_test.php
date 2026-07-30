<?php
// ext/pgsql 扩展测试 — 转义
//
// 测试范围：
//   pg_escape_string / pg_escape_literal / pg_escape_identifier
//   pg_escape_bytea / pg_unescape_bytea
#import pgsql

#debug === PGSQL Escape Test ===
#debug
#debug 1. pg_escape_string: OK
#debug 2. pg_escape_bytea + pg_unescape_bytea: OK
#debug 3. pg_escape_literal: OK
#debug 4. pg_escape_identifier: OK
#debug
#debug === All passed ===

class Main
{
    public function main(): void
    {
        echo "=== PGSQL Escape Test ===\n\n";

        $dsn = "host=127.0.0.1 port=5432 dbname=tinyphp_test user=postgres password=postgres";

        try {
            $conn = pg_connect($dsn);
        } catch (Exception $e) {
            echo "0. connect: FAIL\n";
            return;
        }

        // 1. pg_escape_string（单引号转义）
        try {
            $escaped = pg_escape_string($conn, "it's a test");
            echo "1. pg_escape_string: " . ($escaped == "it''s a test" ? "OK" : "FAIL") . "\n";
        } catch (Exception $e) {
            echo "1. pg_escape_string: FAIL\n";
        }

        // 2. pg_escape_bytea + pg_unescape_bytea（hex 编解码往返）
        try {
            $original = "hello world";
            $escaped = pg_escape_bytea($conn, $original);
            $decoded = pg_unescape_bytea($escaped);
            echo "2. pg_escape_bytea + pg_unescape_bytea: " . ($decoded == $original ? "OK" : "FAIL") . "\n";
        } catch (Exception $e) {
            echo "2. pg_escape_bytea + pg_unescape_bytea: FAIL\n";
        }

        // 3. pg_escape_literal（literal 格式）
        try {
            $lit1 = pg_escape_literal($conn, "hello");
            $lit2 = pg_escape_literal($conn, "it's");
            $ok = ($lit1 == "'hello'" && $lit2 == "'it''s'");
            echo "3. pg_escape_literal: " . ($ok ? "OK" : "FAIL") . "\n";
        } catch (Exception $e) {
            echo "3. pg_escape_literal: FAIL\n";
        }

        // 4. pg_escape_identifier（identifier 格式）
        try {
            $id1 = pg_escape_identifier($conn, "mycol");
            $id2 = pg_escape_identifier($conn, "my col");
            $ok = ($id1 == '"mycol"' && $id2 == '"my col"');
            echo "4. pg_escape_identifier: " . ($ok ? "OK" : "FAIL") . "\n";
        } catch (Exception $e) {
            echo "4. pg_escape_identifier: FAIL\n";
        }

        pg_close($conn);
        echo "\n=== All passed ===\n";
    }
}
