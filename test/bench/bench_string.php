<?php

// ============================================================
// 字符串操作性能基准 — 覆盖 SSO/ROPE/Arena 优化路径
// 测试场景：拼接/子串/查找/替换/大小写/正则
// 运行: php tphp.php test/bench/bench_string.php -o build/bench_string.exe
// ============================================================

class Main
{
    public function main(): void
    {
        echo "=== TinyPHP String Benchmark ===\n\n";

        $N = 100000;
        $M = $N / 10;

        // ═══ 1. 字符串拼接（2 片段）x N ═══
        $t0 = hrtime();
        $i = 0;
        while ($i < $N) {
            $s = "hello" . "world";
            $i = $i + 1;
        }
        $t1 = hrtime();
        echo "1. concat 2 frags x" . $N . ":       " . ($t1 - $t0) . " ns\n";

        // ═══ 2. 字符串拼接（4 片段，ROPE 路径）x N ═══
        $t0 = hrtime();
        $i = 0;
        while ($i < $N) {
            $s = "a" . "b" . "c" . "d";
            $i = $i + 1;
        }
        $t1 = hrtime();
        echo "2. concat 4 frags x" . $N . ":       " . ($t1 - $t0) . " ns\n";

        // ═══ 3. 变量插值 x N ═══
        $name = "Alice";
        $age = 30;
        $t0 = hrtime();
        $i = 0;
        while ($i < $N) {
            $s = "name=" . $name . ", age=" . $age;
            $i = $i + 1;
        }
        $t1 = hrtime();
        echo "3. concat vars x" . $N . ":          " . ($t1 - $t0) . " ns\n";

        // ═══ 4. 长字符串拼接 x M ═══
        $t0 = hrtime();
        $i = 0;
        while ($i < $M) {
            $s = "prefix";
            $j = 0;
            while ($j < 20) {
                $s = $s . "-segment" . $j;
                $j = $j + 1;
            }
            $i = $i + 1;
        }
        $t1 = hrtime();
        echo "4. concat loop 20 x" . $M . ":       " . ($t1 - $t0) . " ns\n";

        // ═══ 5. substr 取子串 x N ═══
        $longStr = "the quick brown fox jumps over the lazy dog";
        $t0 = hrtime();
        $i = 0;
        while ($i < $N) {
            $s = substr($longStr, 10, 15);
            $i = $i + 1;
        }
        $t1 = hrtime();
        echo "5. substr x" . $N . ":               " . ($t1 - $t0) . " ns\n";

        // ═══ 6. strpos 查找 x N ═══
        $t0 = hrtime();
        $sum = 0;
        $i = 0;
        while ($i < $N) {
            $p = strpos($longStr, "fox");
            $sum = $sum + $p;
            $i = $i + 1;
        }
        $t1 = hrtime();
        echo "6. strpos x" . $N . ":               " . ($t1 - $t0) . " ns  (pos=" . $sum . ")\n";

        // ═══ 7. str_replace 替换 x N ═══
        $t0 = hrtime();
        $i = 0;
        while ($i < $N) {
            $s = str_replace("fox", "cat", $longStr);
            $i = $i + 1;
        }
        $t1 = hrtime();
        echo "7. str_replace x" . $N . ":          " . ($t1 - $t0) . " ns\n";

        // ═══ 8. strtolower/upper x N ═══
        $mixed = "Hello World Foo Bar";
        $t0 = hrtime();
        $i = 0;
        while ($i < $N) {
            $s = strtolower($mixed);
            $i = $i + 1;
        }
        $t1 = hrtime();
        echo "8. strtolower x" . $N . ":           " . ($t1 - $t0) . " ns\n";

        // ═══ 9. trim x N ═══
        $padded = "   hello world   ";
        $t0 = hrtime();
        $i = 0;
        while ($i < $N) {
            $s = trim($padded);
            $i = $i + 1;
        }
        $t1 = hrtime();
        echo "9. trim x" . $N . ":                 " . ($t1 - $t0) . " ns\n";

        // ═══ 10. strlen 长度 x N ═══
        $t0 = hrtime();
        $sum = 0;
        $i = 0;
        while ($i < $N) {
            $sum = $sum + strlen($longStr);
            $i = $i + 1;
        }
        $t1 = hrtime();
        echo "10. strlen x" . $N . ":              " . ($t1 - $t0) . " ns  (sum=" . $sum . ")\n";

        // ═══ 11. explode+implode x M ═══
        $csv = "1,2,3,4,5,6,7,8,9,10,11,12,13,14,15";
        $t0 = hrtime();
        $i = 0;
        while ($i < $M) {
            $parts = explode(",", $csv);
            $back = implode("|", $parts);
            $i = $i + 1;
        }
        $t1 = hrtime();
        echo "11. explode+implode x" . $M . ":      " . ($t1 - $t0) . " ns\n";

        // ═══ 12. sprintf 格式化 x M ═══
        $t0 = hrtime();
        $i = 0;
        while ($i < $M) {
            $s = sprintf("id=%d name=%s score=%.2f", 42, "alice", 95.5);
            $i = $i + 1;
        }
        $t1 = hrtime();
        echo "12. sprintf x" . $M . ":              " . ($t1 - $t0) . " ns\n";

        echo "\n=== done ===\n";
    }
}
