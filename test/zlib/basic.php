<?php
// zlib 扩展完整测试 — 对标 PHP 原生 ext/zlib
// 覆盖：6 个基础函数 + zlib_encode/decode + gz 文件流 API + 增量上下文 + 错误处理
#debug === 1. compress/decompress round-trip ===
#debug gzcompress: hello world
#debug gzencode: hello world
#debug gzdeflate: hello world
#debug
#debug === 2. zlib_encode/zlib_decode ===
#debug zlib_gzip: hello world
#debug zlib_deflate: hello world
#debug zlib_raw: hello world
#debug
#debug === 3. compression levels ===
#debug level_0: aaaaaa
#debug level_1: aaaaaa
#debug level_9: aaaaaa
#debug level_default: aaaaaa
#debug
#debug === 4. gz file write/read ===
#debug write_ok=1
#debug read_5=hello
#debug read_rest= world
#debug eof_after_exact=false
#debug read_past_end_len=0
#debug eof_after_past=true
#debug
#debug === 5. gz seek/tell/rewind ===
#debug getc=h
#debug rewind_ok=1
#debug seek_pos=6
#debug tell=6
#debug read_after_seek=world
#debug
#debug === 6. gzpassthru ===
#debug world
#debug passthru_len=5
#debug
#debug === 7. gzfile/readgzfile ===
#debug gzfile_count=1
#debug gzfile_data=hello world
#debug hello world
#debug readgzfile_len=11
#debug
#debug === 8. incremental context ===
#debug deflate_single: hello world
#debug deflate_multi: hello world
#debug inflate_status=1
#debug read_len_match=true
#debug
#debug === 9. error handling ===
#debug caught_empty_compress: zlib compress: empty input
#debug caught_empty_decompress: zlib decompress: empty input data
#debug caught_gzopen: gzopen(): failed to open file
#debug caught_invalid_level: deflate_init(): invalid compression level
#debug caught_gzread_closed: gzread(): invalid resource
#debug caught_double_gzclose: gzclose(): invalid resource
#debug
#debug === zlib tests done ===

class Main
{
    public function main(): void
    {
        $orig = "hello world";

        // ── 1. compress/decompress round-trip ──
        echo "=== 1. compress/decompress round-trip ===\n";
        $c1 = gzcompress($orig);
        echo "gzcompress: " . gzuncompress($c1) . "\n";

        $c2 = gzencode($orig);
        echo "gzencode: " . gzdecode($c2) . "\n";

        $c3 = gzdeflate($orig);
        echo "gzdeflate: " . gzinflate($c3) . "\n";
        echo "\n";

        // ── 2. zlib_encode/zlib_decode (3 encodings, auto-detect decode) ──
        echo "=== 2. zlib_encode/zlib_decode ===\n";
        $zg = zlib_encode($orig, ZLIB_ENCODING_GZIP);
        echo "zlib_gzip: " . zlib_decode($zg) . "\n";

        $zd = zlib_encode($orig, ZLIB_ENCODING_DEFLATE);
        echo "zlib_deflate: " . zlib_decode($zd) . "\n";

        $zr = zlib_encode($orig, ZLIB_ENCODING_RAW);
        echo "zlib_raw: " . gzinflate($zr) . "\n";
        echo "\n";

        // ── 3. compression levels ──
        echo "=== 3. compression levels ===\n";
        $lvl_data = str_repeat("a", 6);
        echo "level_0: " . gzuncompress(gzcompress($lvl_data, 0)) . "\n";
        echo "level_1: " . gzuncompress(gzcompress($lvl_data, 1)) . "\n";
        echo "level_9: " . gzuncompress(gzcompress($lvl_data, 9)) . "\n";
        echo "level_default: " . gzuncompress(gzcompress($lvl_data)) . "\n";
        echo "\n";

        // ── 4. gz file write/read ──
        echo "=== 4. gz file write/read ===\n";
        $gzfile = __DIR__ . "/zlib_test.gz";

        $fp = gzopen($gzfile, "wb");
        gzwrite($fp, $orig);
        gzclose($fp);
        echo "write_ok=1\n";

        $fp = gzopen($gzfile, "rb");
        $chunk1 = gzread($fp, 5);
        echo "read_5=" . $chunk1 . "\n";
        $chunk2 = gzread($fp, 6);
        echo "read_rest=" . $chunk2 . "\n";
        // gzeof returns false after reading exactly the last bytes (buffer not refilled)
        $eof = gzeof($fp);
        echo "eof_after_exact=" . ($eof ? "true" : "false") . "\n";
        // Reading past end triggers eof
        $chunk3 = gzread($fp, 1);
        echo "read_past_end_len=" . strlen($chunk3) . "\n";
        $eof = gzeof($fp);
        echo "eof_after_past=" . ($eof ? "true" : "false") . "\n";
        gzclose($fp);
        echo "\n";

        // ── 5. gz seek/tell/rewind ──
        echo "=== 5. gz seek/tell/rewind ===\n";
        $fp = gzopen($gzfile, "rb");
        $c = gzgetc($fp);
        echo "getc=" . $c . "\n";
        $rw = gzrewind($fp);
        echo "rewind_ok=" . ($rw ? "1" : "0") . "\n";
        $pos = gzseek($fp, 6, 0);
        echo "seek_pos=" . $pos . "\n";
        $tell = gztell($fp);
        echo "tell=" . $tell . "\n";
        $chunk = gzread($fp, 5);
        echo "read_after_seek=" . $chunk . "\n";
        gzclose($fp);
        echo "\n";

        // ── 6. gzpassthru ──
        echo "=== 6. gzpassthru ===\n";
        $fp = gzopen($gzfile, "rb");
        gzread($fp, 6);
        $n = gzpassthru($fp);
        echo "\npassthru_len=" . $n . "\n";
        gzclose($fp);
        echo "\n";

        // ── 7. gzfile/readgzfile ──
        echo "=== 7. gzfile/readgzfile ===\n";
        $lines = gzfile($gzfile);
        echo "gzfile_count=" . count($lines) . "\n";
        echo "gzfile_data=" . $lines[0] . "\n";
        $len = readgzfile($gzfile);
        echo "\nreadgzfile_len=" . $len . "\n";
        echo "\n";

        // ── 8. incremental context ──
        echo "=== 8. incremental context ===\n";

        // single chunk
        $ctx = deflate_init(ZLIB_ENCODING_DEFLATE);
        $compressed = deflate_add($ctx, $orig, ZLIB_FINISH);
        $ctx2 = inflate_init(ZLIB_ENCODING_DEFLATE);
        $decompressed = inflate_add($ctx2, $compressed, ZLIB_FINISH);
        echo "deflate_single: " . $decompressed . "\n";

        // multi chunk
        $ctx3 = deflate_init(ZLIB_ENCODING_DEFLATE);
        $c_part1 = deflate_add($ctx3, "hello ", ZLIB_SYNC_FLUSH);
        $c_part2 = deflate_add($ctx3, "world", ZLIB_FINISH);
        $ctx4 = inflate_init(ZLIB_ENCODING_DEFLATE);
        $d_part1 = inflate_add($ctx4, $c_part1, ZLIB_SYNC_FLUSH);
        $d_part2 = inflate_add($ctx4, $c_part2, ZLIB_FINISH);
        echo "deflate_multi: " . $d_part1 . $d_part2 . "\n";

        // status + read_len
        $status = inflate_get_status($ctx2);
        $read_len = inflate_get_read_len($ctx2);
        echo "inflate_status=" . $status . "\n";
        echo "read_len_match=" . ($read_len == strlen($compressed) ? "true" : "false") . "\n";
        echo "\n";

        // ── 9. error handling ──
        echo "=== 9. error handling ===\n";

        try {
            gzcompress("");
        } catch (Exception $e) {
            echo "caught_empty_compress: " . $e->getMessage() . "\n";
        }

        try {
            gzuncompress("");
        } catch (Exception $e) {
            echo "caught_empty_decompress: " . $e->getMessage() . "\n";
        }

        try {
            gzopen("/nonexistent/path/file.gz", "rb");
        } catch (Exception $e) {
            echo "caught_gzopen: " . $e->getMessage() . "\n";
        }

        try {
            deflate_init(ZLIB_ENCODING_DEFLATE, 99);
        } catch (Exception $e) {
            echo "caught_invalid_level: " . $e->getMessage() . "\n";
        }

        // gzread on closed resource
        $fp = gzopen($gzfile, "rb");
        gzclose($fp);
        try {
            gzread($fp, 10);
        } catch (Exception $e) {
            echo "caught_gzread_closed: " . $e->getMessage() . "\n";
        }

        // double gzclose
        $fp2 = gzopen($gzfile, "rb");
        gzclose($fp2);
        try {
            gzclose($fp2);
        } catch (Exception $e) {
            echo "caught_double_gzclose: " . $e->getMessage() . "\n";
        }
        echo "\n";

        // cleanup
        unlink($gzfile);
        echo "=== zlib tests done ===\n";
    }
}
