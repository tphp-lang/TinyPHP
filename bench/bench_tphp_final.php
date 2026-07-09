<?php
// TinyPHP JSON 性能基准 (直写风格, 无闭包)
// 运行: tphp bench/bench_tphp_final.php && ./bench_tphp_final

class Main
{
    public function main(): void
    {
        $N       = 10000;
        $N_SMALL = 20000;
        $N_DEC   = 5000;
        $N_DEC_L = 2000;

        echo "===== TinyPHP JSON Bench =====\n";
        echo "System: Windows x64 | TinyPHP AOT (TCC)\n\n";

        // ── 构建数据 ──
        $smallArr = [1,2,3,4,5,6,7,8,9,10];

        $largeArr = [];
        $i = 0; while ($i < 1000) { $largeArr[] = $i; $i = $i + 1; }

        $nestedObj = [];
        $j = 0;
        while ($j < 50) {
            $inner = [];
            $inner['id'] = $j;
            $inner['name'] = 'user_' . $j;
            $inner['score'] = 95.5;
            $inner['active'] = true;
            $inner['tags'] = [$j, $j+1, $j+2];
            $nestedObj[$j] = $inner;
            $j = $j + 1;
        }

        $nestedEnc = json_encode($nestedObj);
        $smallEnc  = json_encode($smallArr);
        $largeEnc  = json_encode($largeArr);

        echo "data ready\n\n";

        // ═══ json_encode ═══
        echo "----- json_encode -----\n";

        $t0 = microtime();
        $i = 0; while ($i < $N_SMALL) { json_encode($smallArr); $i = $i + 1; }
        $this->show('encode small(10)    ', microtime() - $t0, $N_SMALL);

        $t0 = microtime();
        $i = 0; while ($i < $N) { json_encode($largeArr); $i = $i + 1; }
        $this->show('encode large(1000)  ', microtime() - $t0, $N);

        $t0 = microtime();
        $i = 0; while ($i < $N) { json_encode($nestedObj); $i = $i + 1; }
        $this->show('encode nested(50x5) ', microtime() - $t0, $N);

        // ═══ json_decode ═══
        echo "\n----- json_decode -----\n";

        $t0 = microtime();
        $i = 0; while ($i < $N_SMALL) { json_decode($smallEnc); $i = $i + 1; }
        $this->show('decode small(10)    ', microtime() - $t0, $N_SMALL);

        $t0 = microtime();
        $i = 0; while ($i < $N_DEC_L) { json_decode($largeEnc); $i = $i + 1; }
        $this->show('decode large(1000)  ', microtime() - $t0, $N_DEC_L);

        $t0 = microtime();
        $i = 0; while ($i < $N_DEC) { json_decode($nestedEnc); $i = $i + 1; }
        $this->show('decode nested(50x5) ', microtime() - $t0, $N_DEC);

        // ═══ round-trip ═══
        echo "\n----- round-trip -----\n";
        $RT = 5000;

        $t0 = microtime();
        $i = 0; while ($i < $RT) {
            $e = json_encode($smallArr);
            json_decode($e);
            $i = $i + 1;
        }
        $this->show('rtrip small(10)     ', microtime() - $t0, $RT);

        $t0 = microtime();
        $i = 0; while ($i < $RT) {
            $e = json_encode($nestedObj);
            json_decode($e);
            $i = $i + 1;
        }
        $this->show('rtrip nested(50x5)  ', microtime() - $t0, $RT);

        echo "\n===== DONE =====\n";
    }

    private function show(string $label, float $secs, int $iters): void
    {
        $us = $secs * 1000000.0 / (float)$iters;
        echo $label . ' ' . $secs . 's  (' . $us . "us/op)\n";
    }
}
