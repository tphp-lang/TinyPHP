<?php
// ext/pgsql 扩展测试 — Large Object 全套
//
// 测试范围：
//   pg_lo_create / pg_lo_open / pg_lo_read / pg_lo_write / pg_lo_seek / pg_lo_tell
//   pg_lo_truncate / pg_lo_close / pg_lo_unlink / pg_lo_import / pg_lo_export / pg_lo_read_all
#import pgsql

#debug === PGSQL Large Object Test ===
#debug
#debug 1. pg_lo_create: OK
#debug 2. pg_lo_open + pg_lo_write + pg_lo_read: OK
#debug 3. pg_lo_seek + pg_lo_tell: OK
#debug 4. pg_lo_truncate: OK
#debug 5. pg_lo_read_all: OK
#debug 6. pg_lo_import + pg_lo_export: OK
#debug 7. pg_lo_unlink: OK
#debug
#debug === All passed ===

class Main
{
    public function main(): void
    {
        echo "=== PGSQL Large Object Test ===\n\n";

        $dsn = "host=127.0.0.1 port=5432 dbname=tinyphp_test user=postgres password=postgres";

        try {
            $conn = pg_connect($dsn);
        } catch (Exception $e) {
            echo "0. connect: FAIL\n";
            return;
        }

        // 1. pg_lo_create — 返回 OID（Large Object 需在事务中）
        try {
            pg_query($conn, "BEGIN");
            $oid = pg_lo_create($conn);
            pg_query($conn, "COMMIT");
            echo "1. pg_lo_create: " . ($oid > 0 ? "OK" : "FAIL") . "\n";
        } catch (Exception $e) {
            echo "1. pg_lo_create: FAIL\n";
            pg_query($conn, "ROLLBACK");
        }

        // 2. pg_lo_open + pg_lo_write + pg_lo_read — 往返测试
        try {
            pg_query($conn, "BEGIN");
            $lob = pg_lo_open($conn, $oid, "rw");
            $written = pg_lo_write($conn, $lob, "hello world");
            // seek 回开头再读
            pg_lo_seek($conn, $lob, 0, 0);
            $data = pg_lo_read($conn, $lob, 11);
            pg_lo_close($conn, $lob);
            pg_query($conn, "COMMIT");
            $ok = ($written == 11 && $data == "hello world");
            echo "2. pg_lo_open + pg_lo_write + pg_lo_read: " . ($ok ? "OK" : "FAIL") . "\n";
        } catch (Exception $e) {
            echo "2. pg_lo_open + pg_lo_write + pg_lo_read: FAIL\n";
            pg_query($conn, "ROLLBACK");
        }

        // 3. pg_lo_seek + pg_lo_tell — 定位测试
        try {
            pg_query($conn, "BEGIN");
            $lob = pg_lo_open($conn, $oid, "rw");
            pg_lo_write($conn, $lob, "ABCDEFGH");
            // seek 到位置 3
            pg_lo_seek($conn, $lob, 3, 0);
            $pos = pg_lo_tell($conn, $lob);
            // 读取 2 字节
            $chunk = pg_lo_read($conn, $lob, 2);
            pg_lo_close($conn, $lob);
            pg_query($conn, "COMMIT");
            $ok = ($pos == 3 && $chunk == "DE");
            echo "3. pg_lo_seek + pg_lo_tell: " . ($ok ? "OK" : "FAIL") . "\n";
        } catch (Exception $e) {
            echo "3. pg_lo_seek + pg_lo_tell: FAIL\n";
            pg_query($conn, "ROLLBACK");
        }

        // 4. pg_lo_truncate — 截断测试
        try {
            pg_query($conn, "BEGIN");
            $lob = pg_lo_open($conn, $oid, "rw");
            // 先写入 10 字节
            pg_lo_write($conn, $lob, "0123456789");
            // 截断到 5 字节
            $trunc_ok = pg_lo_truncate($conn, $lob, 5);
            // seek 回开头读取全部
            pg_lo_seek($conn, $lob, 0, 0);
            $data = pg_lo_read($conn, $lob, 10);
            pg_lo_close($conn, $lob);
            pg_query($conn, "COMMIT");
            // 截断后应只剩 "01234"
            $ok = ($trunc_ok && $data == "01234");
            echo "4. pg_lo_truncate: " . ($ok ? "OK" : "FAIL") . "\n";
        } catch (Exception $e) {
            echo "4. pg_lo_truncate: FAIL\n";
            pg_query($conn, "ROLLBACK");
        }

        // 5. pg_lo_read_all — 读取全部内容
        try {
            pg_query($conn, "BEGIN");
            $lob = pg_lo_open($conn, $oid, "r");
            $all = pg_lo_read_all($conn, $lob);
            pg_lo_close($conn, $lob);
            pg_query($conn, "COMMIT");
            // 上一步 truncate 后 LO 内容为 "01234"
            $ok = ($all == "01234");
            echo "5. pg_lo_read_all: " . ($ok ? "OK" : "FAIL") . "\n";
        } catch (Exception $e) {
            echo "5. pg_lo_read_all: FAIL\n";
            pg_query($conn, "ROLLBACK");
        }

        // 6. pg_lo_import + pg_lo_export — 文件导入导出
        try {
            // 创建临时文件
            $import_file = "lo_import_test.txt";
            $export_file = "lo_export_test.txt";
            file_put_contents($import_file, "LO import/export test data");

            pg_query($conn, "BEGIN");
            // lo_import 读取文件并创建 LO
            $import_oid = pg_lo_import($conn, $import_file);
            $ok1 = ($import_oid > 0);
            // lo_export 将 LO 写入文件
            $export_ok = pg_lo_export($conn, $import_oid, $export_file);
            pg_query($conn, "COMMIT");

            // 验证导出文件内容
            $exported = file_get_contents($export_file);
            $ok2 = ($export_ok && $exported == "LO import/export test data");

            // 清理导入的 LO
            pg_query($conn, "BEGIN");
            pg_lo_unlink($conn, $import_oid);
            pg_query($conn, "COMMIT");

            // 删除临时文件
            unlink($import_file);
            unlink($export_file);

            echo "6. pg_lo_import + pg_lo_export: " . ($ok1 && $ok2 ? "OK" : "FAIL") . "\n";
        } catch (Exception $e) {
            echo "6. pg_lo_import + pg_lo_export: FAIL\n";
            pg_query($conn, "ROLLBACK");
        }

        // 7. pg_lo_unlink — 删除 LO
        try {
            pg_query($conn, "BEGIN");
            $ok = pg_lo_unlink($conn, $oid);
            pg_query($conn, "COMMIT");
            echo "7. pg_lo_unlink: " . ($ok ? "OK" : "FAIL") . "\n";
        } catch (Exception $e) {
            echo "7. pg_lo_unlink: FAIL\n";
            pg_query($conn, "ROLLBACK");
        }

        pg_close($conn);
        echo "\n=== All passed ===\n";
    }
}
