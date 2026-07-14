<?php
// TinyPHP + PHP 双兼容 JSON 性能基准
// TinyPHP: tphp bench/bench_cmp.php && ./bench_cmp
// PHP:     php bench/bench_cmp.php

class Main
{
    public function main(): void
    {
        $N = 10000;

        echo "===== JSON Benchmark (iter=" . $N . ") =====\n\n";

        // ═══ 准备数据 ═══
        // smallArr: 10个int
        $smallArr = [1,2,3,4,5,6,7,8,9,10];

        // largeArr: 1000个int
        $largeArr = [];
        $i = 0; while ($i < 1000) { $largeArr[] = $i; $i = $i + 1; }

        // nestedObj: 50个对象, 每对象5键 (id,name,score,active,tags)
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

        // 预生成 encode 结果用于 decode 测试
        $nestedEnc = json_encode($nestedObj);
        $smallEnc  = json_encode($smallArr);
        $largeEnc  = json_encode($largeArr);

        // ═══ json_encode ═══
        echo "----- json_encode -----\n";
        $this->timed('encode small(10)    ', function() use ($smallArr): string { return json_encode($smallArr); }, $N);
        $this->timed('encode large(1000)  ', function() use ($largeArr): string { return json_encode($largeArr); }, $N);
        $this->timed('encode nested(50x5) ', function() use ($nestedObj): string { return json_encode($nestedObj); }, $N);

        // ═══ json_decode ═══
        echo "\n----- json_decode -----\n";
        $this->timed('decode small(10)    ', function() use ($smallEnc) { return json_decode($smallEnc); }, $N);
        $this->timed('decode large(1000)  ', function() use ($largeEnc) { return json_decode($largeEnc); }, $N);
        $this->timed('decode nested(50x5) ', function() use ($nestedEnc) { return json_decode($nestedEnc); }, $N);

        // ═══ round-trip ═══
        echo "\n----- round-trip -----\n";
        $RT = 5000;
        $this->timed('rtrip small(10)     ', function() use ($smallArr): string {
            return json_encode(json_decode(json_encode($smallArr)));
        }, $RT);
        $this->timed('rtrip nested(50x5)  ', function() use ($nestedObj): string {
            return json_encode(json_decode(json_encode($nestedObj)));
        }, $RT);

        echo "\n===== DONE =====\n";
    }

    private function timed(string $label, callable $fn, int $n): void
    {
        // 预热 500 次
        $w = 0; while ($w < 500) { $fn(); $w = $w + 1; }
        // 正式计时
        $t0 = microtime();
        $i = 0; while ($i < $n) { $fn(); $i = $i + 1; }
        $elapsed = microtime() - $t0;
        $perOp = $elapsed * 1000000.0 / (float)$n;
        echo $label . ' ' . $elapsed . 's  ' . $perOp . "us/op\n";
    }
}
