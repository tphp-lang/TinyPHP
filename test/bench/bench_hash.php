<?php

// ============================================================
// 哈希函数性能基准 — md5/sha1/sha256/crc32 吞吐量
// 运行: php tphp.php test/bench/bench_hash.php -o build/bench_hash.exe
// ============================================================

class Main
{
    public function main(): void
    {
        echo "=== TinyPHP Hash Benchmark ===\n\n";

        $N = 100000;

        // ── 测试数据 ──
        $small = "hello world";
        $medium = str_repeat("abcdefghij", 100);   // 1KB
        $large  = str_repeat("abcdefghij", 10000); // 100KB

        // ═══ 1. md5 小字符串 x N ═══
        $t0 = hrtime();
        $i = 0;
        while ($i < $N) {
            $h = md5($small);
            $i = $i + 1;
        }
        $t1 = hrtime();
        echo "1. md5(11B) x" . $N . ":         " . ($t1 - $t0) . " ns\n";

        // ═══ 2. md5 1KB x N/10 ═══
        $m2 = (int)($N / 10);
        $t0 = hrtime();
        $i = 0;
        while ($i < $m2) {
            $h = md5($medium);
            $i = $i + 1;
        }
        $t1 = hrtime();
        echo "2. md5(1KB) x" . $m2 . ":          " . ($t1 - $t0) . " ns\n";

        // ═══ 3. md5 100KB x N/100 ═══
        $m3 = (int)($N / 100);
        $t0 = hrtime();
        $i = 0;
        while ($i < $m3) {
            $h = md5($large);
            $i = $i + 1;
        }
        $t1 = hrtime();
        echo "3. md5(100KB) x" . $m3 . ":         " . ($t1 - $t0) . " ns\n";

        // ═══ 4. sha1 小字符串 x N ═══
        $t0 = hrtime();
        $i = 0;
        while ($i < $N) {
            $h = sha1($small);
            $i = $i + 1;
        }
        $t1 = hrtime();
        echo "4. sha1(11B) x" . $N . ":        " . ($t1 - $t0) . " ns\n";

        // ═══ 5. sha1 1KB x N/10 ═══
        $t0 = hrtime();
        $i = 0;
        while ($i < $m2) {
            $h = sha1($medium);
            $i = $i + 1;
        }
        $t1 = hrtime();
        echo "5. sha1(1KB) x" . $m2 . ":         " . ($t1 - $t0) . " ns\n";

        // ═══ 6. sha1 100KB x N/100 ═══
        $t0 = hrtime();
        $i = 0;
        while ($i < $m3) {
            $h = sha1($large);
            $i = $i + 1;
        }
        $t1 = hrtime();
        echo "6. sha1(100KB) x" . $m3 . ":        " . ($t1 - $t0) . " ns\n";

        // ═══ 7. sha256 小字符串 x N ═══
        $t0 = hrtime();
        $i = 0;
        while ($i < $N) {
            $h = sha256($small);
            $i = $i + 1;
        }
        $t1 = hrtime();
        echo "7. sha256(11B) x" . $N . ":      " . ($t1 - $t0) . " ns\n";

        // ═══ 8. sha256 1KB x N/10 ═══
        $t0 = hrtime();
        $i = 0;
        while ($i < $m2) {
            $h = sha256($medium);
            $i = $i + 1;
        }
        $t1 = hrtime();
        echo "8. sha256(1KB) x" . $m2 . ":       " . ($t1 - $t0) . " ns\n";

        // ═══ 9. sha256 100KB x N/100 ═══
        $t0 = hrtime();
        $i = 0;
        while ($i < $m3) {
            $h = sha256($large);
            $i = $i + 1;
        }
        $t1 = hrtime();
        echo "9. sha256(100KB) x" . $m3 . ":      " . ($t1 - $t0) . " ns\n";

        // ═══ 10. crc32 x N ═══
        $t0 = hrtime();
        $sum = 0;
        $i = 0;
        while ($i < $N) {
            $sum = $sum + crc32($small);
            $i = $i + 1;
        }
        $t1 = hrtime();
        echo "10. crc32(11B) x" . $N . ":      " . ($t1 - $t0) . " ns  (sum=" . $sum . ")\n";

        // ═══ 11. hash_hmac sha256 x N/10 ═══
        $t0 = hrtime();
        $i = 0;
        while ($i < $m2) {
            $h = hash_hmac("sha256", $small, "secret_key", false);
            $i = $i + 1;
        }
        $t1 = hrtime();
        echo "11. hmac-sha256 x" . $m2 . ":       " . ($t1 - $t0) . " ns\n";

        // ═══ 12. sha512 小字符串 x N ═══
        $t0 = hrtime();
        $i = 0;
        while ($i < $N) {
            $h = sha512($small);
            $i = $i + 1;
        }
        $t1 = hrtime();
        echo "12. sha512(11B) x" . $N . ":      " . ($t1 - $t0) . " ns\n";

        echo "\n=== done ===\n";
    }
}
