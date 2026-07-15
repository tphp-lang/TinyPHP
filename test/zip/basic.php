<?php
// zip 扩展完整测试 — 对标 PHP 原生 ext/zip
// 覆盖：创建+读取往返 + 条目信息查询 + zip_locate + zip_entry_read + 错误处理
#debug === 1. create zip ===
#debug create_ok=1
#debug num_files=3
#debug
#debug === 2. entry name ===
#debug name_0=file1.txt
#debug name_1=file2.txt
#debug name_2=mydir/
#debug
#debug === 3. entry filesize ===
#debug size_0=5
#debug size_1=5
#debug size_2=0
#debug
#debug === 4. entry compression method ===
#debug method_0=Deflated
#debug method_1=Deflated
#debug method_2=Stored
#debug
#debug === 5. entry compressed size ===
#debug comp_size_0_valid=true
#debug comp_size_1_valid=true
#debug comp_size_2=0
#debug
#debug === 6. zip_locate ===
#debug locate_file2=1
#debug locate_file1=0
#debug locate_mydir=2
#debug locate_missing=-1
#debug
#debug === 7. zip_entry_read ===
#debug read_0=hello
#debug read_1=world
#debug read_0_partial=hel
#debug
#debug === 8. zip_stat ===
#debug stat_name=file1.txt
#debug stat_size=5
#debug stat_method=8
#debug
#debug === 9. error handling ===
#debug caught_nonexistent: zip_open(): file not found
#debug caught_delete: zip_delete(): modifying existing archives is not supported. Create a new archive instead.
#debug caught_rename: zip_rename(): modifying existing archives is not supported. Create a new archive instead.
#debug caught_invalid_index: zip_entry_name(): index out of range
#debug caught_read_write_mode: zip_entry_read(): zip is in write mode
#debug
#debug === zip tests done ===

class Main
{
    public function main(): void
    {
        $zipfile = __DIR__ . "/zip_test_tmp.zip";

        // ── 1. create zip (write mode) ──
        echo "=== 1. create zip ===\n";
        $zip = zip_open($zipfile, ZIP_CREATE);
        zip_add_file($zip, "file1.txt", "hello");
        zip_add_file($zip, "file2.txt", "world");
        zip_add_dir($zip, "mydir");
        zip_close($zip);
        echo "create_ok=1\n";

        // reopen (read mode)
        $zip = zip_open($zipfile);
        echo "num_files=" . zip_num_files($zip) . "\n";
        echo "\n";

        // ── 2. entry name ──
        echo "=== 2. entry name ===\n";
        echo "name_0=" . zip_entry_name($zip, 0) . "\n";
        echo "name_1=" . zip_entry_name($zip, 1) . "\n";
        echo "name_2=" . zip_entry_name($zip, 2) . "\n";
        echo "\n";

        // ── 3. entry filesize ──
        echo "=== 3. entry filesize ===\n";
        echo "size_0=" . zip_entry_filesize($zip, 0) . "\n";
        echo "size_1=" . zip_entry_filesize($zip, 1) . "\n";
        echo "size_2=" . zip_entry_filesize($zip, 2) . "\n";
        echo "\n";

        // ── 4. entry compression method ──
        echo "=== 4. entry compression method ===\n";
        echo "method_0=" . zip_entry_compressionmethod($zip, 0) . "\n";
        echo "method_1=" . zip_entry_compressionmethod($zip, 1) . "\n";
        echo "method_2=" . zip_entry_compressionmethod($zip, 2) . "\n";
        echo "\n";

        // ── 5. entry compressed size ──
        echo "=== 5. entry compressed size ===\n";
        $cs0 = zip_entry_compressedsize($zip, 0);
        echo "comp_size_0_valid=" . ($cs0 > 0 ? "true" : "false") . "\n";
        $cs1 = zip_entry_compressedsize($zip, 1);
        echo "comp_size_1_valid=" . ($cs1 > 0 ? "true" : "false") . "\n";
        $cs2 = zip_entry_compressedsize($zip, 2);
        echo "comp_size_2=" . $cs2 . "\n";
        echo "\n";

        // ── 6. zip_locate ──
        echo "=== 6. zip_locate ===\n";
        echo "locate_file2=" . zip_locate($zip, "file2.txt") . "\n";
        echo "locate_file1=" . zip_locate($zip, "file1.txt") . "\n";
        echo "locate_mydir=" . zip_locate($zip, "mydir/") . "\n";
        echo "locate_missing=" . zip_locate($zip, "nonexistent.txt") . "\n";
        echo "\n";

        // ── 7. zip_entry_read ──
        echo "=== 7. zip_entry_read ===\n";
        echo "read_0=" . zip_entry_read($zip, 0) . "\n";
        echo "read_1=" . zip_entry_read($zip, 1) . "\n";
        echo "read_0_partial=" . zip_entry_read($zip, 0, 3) . "\n";
        echo "\n";

        // ── 8. zip_stat ──
        echo "=== 8. zip_stat ===\n";
        $stat = zip_stat($zip, 0);
        echo "stat_name=" . $stat["name"] . "\n";
        echo "stat_size=" . $stat["size"] . "\n";
        echo "stat_method=" . $stat["comp_method"] . "\n";
        echo "\n";

        // ── 9. error handling ──
        echo "=== 9. error handling ===\n";

        // nonexistent file
        try {
            zip_open("/nonexistent/path/zip.zip");
        } catch (Exception $e) {
            echo "caught_nonexistent: " . $e->getMessage() . "\n";
        }

        // zip_delete on read archive
        try {
            zip_delete($zip, 0);
        } catch (Exception $e) {
            echo "caught_delete: " . $e->getMessage() . "\n";
        }

        // zip_rename on read archive
        try {
            zip_rename($zip, 0, "newname.txt");
        } catch (Exception $e) {
            echo "caught_rename: " . $e->getMessage() . "\n";
        }

        // invalid index
        try {
            zip_entry_name($zip, 99);
        } catch (Exception $e) {
            echo "caught_invalid_index: " . $e->getMessage() . "\n";
        }

        // zip_entry_read on write-mode zip
        $zipw = zip_open($zipfile, ZIP_CREATE | ZIP_TRUNCATE);
        try {
            zip_entry_read($zipw, 0);
        } catch (Exception $e) {
            echo "caught_read_write_mode: " . $e->getMessage() . "\n";
        }
        zip_close($zipw);

        echo "\n";

        // cleanup
        zip_close($zip);
        unlink($zipfile);
        echo "=== zip tests done ===\n";
    }
}
