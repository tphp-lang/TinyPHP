<?php
// 字符串键数组性能基准 — 覆盖哈希索引优化路径
// 测试场景：
//   - 少量键（<8，线性扫描路径）
//   - 大量键（≥8，哈希索引路径）
//   - 重复查询（哈希索引命中优势）
//   - ksort 后查询（索引失效+重建）

class Main
{
    public function main(): void
    {
        echo "=== TinyPHP 字符串键数组性能 ===\n\n";

        $loops = 10000;
        $r = 0;
        $i = 0;

        // === 测试1: 少量字符串键创建+查询 (< 8, 线性扫描) ===
        $start = hrtime();
        for ($r = 0; $r < $loops; $r++) {
            $a = [];
            $a["k0"] = 0;
            $a["k1"] = 1;
            $a["k2"] = 2;
            $a["k3"] = 3;
            $x = $a["k0"] + $a["k1"] + $a["k2"] + $a["k3"];
        }
        $e1 = hrtime() - $start;
        echo "4字符串键创建+查询 x{$loops}: " . $e1 . " ns (" . ($e1 / 1000000) . " ms)\n";

        // === 测试2: 大量字符串键创建 (≥8, 触发哈希索引构建) ===
        $start = hrtime();
        for ($r = 0; $r < $loops; $r++) {
            $a = [];
            for ($i = 0; $i < 50; $i++) {
                $a["key" . $i] = $i;
            }
        }
        $e2 = hrtime() - $start;
        echo "50字符串键创建 x{$loops}: " . $e2 . " ns (" . ($e2 / 1000000) . " ms)\n";

        // === 测试3: 大量字符串键重复查询 (哈希索引命中优势) ===
        $a = [];
        for ($i = 0; $i < 50; $i++) {
            $a["key" . $i] = $i;
        }
        $start = hrtime();
        $s = 0;
        for ($r = 0; $r < $loops; $r++) {
            for ($i = 0; $i < 50; $i++) {
                $s += $a["key" . $i];
            }
        }
        $e3 = hrtime() - $start;
        echo "50字符串键查询x50 x{$loops}: " . $e3 . " ns (" . ($e3 / 1000000) . " ms)\n";

        // === 测试4: 大量字符串键更新 (哈希索引命中) ===
        $start = hrtime();
        for ($r = 0; $r < $loops; $r++) {
            for ($i = 0; $i < 50; $i++) {
                $a["key" . $i] = $r;
            }
        }
        $e4 = hrtime() - $start;
        echo "50字符串键更新x50 x{$loops}: " . $e4 . " ns (" . ($e4 / 1000000) . " ms)\n";

        // === 测试5: ksort 后查询 (索引失效+重建) ===
        $start = hrtime();
        for ($r = 0; $r < 100; $r++) {
            $a = [];
            for ($i = 0; $i < 50; $i++) {
                $a["key" . $i] = $i;
            }
            ksort($a);
            $x = $a["key25"];
        }
        $e5 = hrtime() - $start;
        echo "50键ksort+查询 x100: " . $e5 . " ns (" . ($e5 / 1000000) . " ms)\n";

        // === 测试6: 1000键查询 (大数组哈希索引优势) ===
        $big = [];
        for ($i = 0; $i < 1000; $i++) {
            $big["key" . $i] = $i;
        }
        $start = hrtime();
        $s = 0;
        for ($r = 0; $r < 1000; $r++) {
            $s += $big["key999"];
            $s += $big["key0"];
            $s += $big["key500"];
        }
        $e6 = hrtime() - $start;
        echo "1000键查询x3 x1000: " . $e6 . " ns (" . ($e6 / 1000000) . " ms)\n";

        echo "\n  TinyPHP总: " . (($e1 + $e2 + $e3 + $e4 + $e5 + $e6) / 1000000) . " ms\n";
        echo "=== done ===\n";
    }
}
