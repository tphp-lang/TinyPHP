<?php
// ext/pgsql 扩展测试 — 字段信息
//
// 测试范围：
//   pg_field_name / pg_field_num / pg_field_type / pg_field_type_oid
//   pg_field_size / pg_field_is_null / pg_field_prtlen / pg_field_table
//   建表含多种类型（int4/text/varchar/bool）
#import pgsql

#debug === PGSQL Field Test ===
#debug
#debug 1. pg_field_name: OK
#debug 2. pg_field_num: OK
#debug 3. pg_field_type: OK
#debug 4. pg_field_type_oid: OK
#debug 5. pg_field_size: OK
#debug 6. pg_field_is_null: OK
#debug 7. pg_field_prtlen: OK
#debug 8. pg_field_table: OK
#debug
#debug === All passed ===

class Main
{
    public function main(): void
    {
        echo "=== PGSQL Field Test ===\n\n";

        $dsn = "host=127.0.0.1 port=5432 dbname=tinyphp_test user=postgres password=postgres";

        try {
            $conn = pg_connect($dsn);
        } catch (Exception $e) {
            echo "0. connect: FAIL\n";
            return;
        }

        // 建表含多种类型（int4/text/varchar/bool）
        try {
            pg_query($conn, "DROP TABLE IF EXISTS pg_field_test");
            pg_query($conn, "CREATE TABLE pg_field_test (uid INT, title TEXT, label VARCHAR(50), active BOOL)");
            pg_query($conn, "INSERT INTO pg_field_test VALUES (42, 'hello', 'world', true)");
            pg_query($conn, "INSERT INTO pg_field_test VALUES (NULL, NULL, NULL, NULL)");
        } catch (Exception $e) {
            echo "0. setup: FAIL\n";
        }

        $res = pg_query($conn, "SELECT uid, title, label, active FROM pg_field_test");

        // 1. pg_field_name
        try {
            $n0 = pg_field_name($res, 0);
            $n1 = pg_field_name($res, 1);
            $n2 = pg_field_name($res, 2);
            $n3 = pg_field_name($res, 3);
            $ok = ($n0 == 'uid' && $n1 == 'title' && $n2 == 'label' && $n3 == 'active');
            echo "1. pg_field_name: " . ($ok ? "OK" : "FAIL") . "\n";
        } catch (Exception $e) {
            echo "1. pg_field_name: FAIL\n";
        }

        // 2. pg_field_num
        try {
            $ok = (pg_field_num($res, 'uid') == 0 && pg_field_num($res, 'title') == 1
                && pg_field_num($res, 'label') == 2 && pg_field_num($res, 'active') == 3);
            echo "2. pg_field_num: " . ($ok ? "OK" : "FAIL") . "\n";
        } catch (Exception $e) {
            echo "2. pg_field_num: FAIL\n";
        }

        // 3. pg_field_type
        try {
            $t0 = pg_field_type($res, 0);
            $t1 = pg_field_type($res, 1);
            $t2 = pg_field_type($res, 2);
            $t3 = pg_field_type($res, 3);
            $ok = ($t0 == 'int4' && $t1 == 'text' && $t2 == 'varchar' && $t3 == 'bool');
            echo "3. pg_field_type: " . ($ok ? "OK" : "FAIL") . "\n";
        } catch (Exception $e) {
            echo "3. pg_field_type: FAIL\n";
        }

        // 4. pg_field_type_oid
        try {
            $oid0 = pg_field_type_oid($res, 0);
            $oid1 = pg_field_type_oid($res, 1);
            $oid2 = pg_field_type_oid($res, 2);
            $oid3 = pg_field_type_oid($res, 3);
            $ok = ($oid0 == 23 && $oid1 == 25 && $oid2 == 1043 && $oid3 == 16);
            echo "4. pg_field_type_oid: " . ($ok ? "OK" : "FAIL") . "\n";
        } catch (Exception $e) {
            echo "4. pg_field_type_oid: FAIL\n";
        }

        // 5. pg_field_size
        try {
            $s0 = pg_field_size($res, 0);
            $s1 = pg_field_size($res, 1);
            $s2 = pg_field_size($res, 2);
            $s3 = pg_field_size($res, 3);
            $ok = ($s0 == 4 && $s1 == -1 && $s2 == -1 && $s3 == 1);
            echo "5. pg_field_size: " . ($ok ? "OK" : "FAIL") . "\n";
        } catch (Exception $e) {
            echo "5. pg_field_size: FAIL\n";
        }

        // 6. pg_field_is_null
        try {
            // Row 0: all non-null
            $r0_null = pg_field_is_null($res, 0, 0);
            // Row 1: all null
            $r1_null = pg_field_is_null($res, 1, 0);
            $ok = (!$r0_null && $r1_null);
            echo "6. pg_field_is_null: " . ($ok ? "OK" : "FAIL") . "\n";
        } catch (Exception $e) {
            echo "6. pg_field_is_null: FAIL\n";
        }

        // 7. pg_field_prtlen
        try {
            // Row 0, title='hello' → prtlen = 5
            $prtlen = pg_field_prtlen($res, 0, 1);
            echo "7. pg_field_prtlen: " . ($prtlen == 5 ? "OK" : "FAIL") . "\n";
        } catch (Exception $e) {
            echo "7. pg_field_prtlen: FAIL\n";
        }

        // 8. pg_field_table
        try {
            $table_oid = pg_field_table($res, 0);
            echo "8. pg_field_table: " . ($table_oid > 0 ? "OK" : "FAIL") . "\n";
        } catch (Exception $e) {
            echo "8. pg_field_table: FAIL\n";
        }

        pg_free_result($res);

        // 清理
        try {
            pg_query($conn, "DROP TABLE IF EXISTS pg_field_test");
        } catch (Exception $e) {
        }

        pg_close($conn);
        echo "\n=== All passed ===\n";
    }
}
