<?php
// TinyPHP JSON 性能基准
// 运行: tphp bench/bench_tphp.php && ./bench/bench_tphp

class Main
{
    public function main(): void
    {
        $N = 10000;

        echo "===== TinyPHP JSON Bench (iter=10000) =====\n\n";

        // ── 准备数据 ──
        $smallArr = [1,2,3,4,5,6,7,8,9,10];

        $largeArr = [];
        $i = 0;
        while ($i < 1000) { $largeArr[] = $i; $i = $i + 1; }

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

        $smallJson = '[1,2,3,4,5,6,7,8,9,10]';
        $largeJson = $this->mkJson(1000);
        $objJson   = $this->mkObjJson(50);

        // ── json_encode ──
        echo "----- json_encode -----\n";
        $this->benchEncode('encode small(10)      ', $smallArr, $N);
        $this->benchEncode('encode large(1000)    ', $largeArr, $N);
        $this->benchEncode('encode nested(50x5)   ', $nestedObj, $N);

        // ── json_decode ──
        echo "\n----- json_decode -----\n";
        $this->benchDecode('decode small(10)      ', $smallJson, $N);
        $this->benchDecode('decode large(1000)    ', $largeJson, $N);
        $this->benchDecode('decode object(50)     ', $objJson, $N);

        // ── round-trip ──
        echo "\n----- round-trip -----\n";
        $RT = 5000;
        $w = 0; while ($w < 500) { $this->roundTrip($smallArr);  $w = $w + 1; }
        $t0 = microtime();
        $k = 0; while ($k < $RT) { $this->roundTrip($smallArr);  $k = $k + 1; }
        $elapsed = microtime() - $t0;
        echo 'rtrip small(10)  ' . $elapsed . "s\n";

        $w = 0; while ($w < 500) { $this->roundTrip($nestedObj); $w = $w + 1; }
        $t0 = microtime();
        $k = 0; while ($k < $RT) { $this->roundTrip($nestedObj); $k = $k + 1; }
        $elapsed = microtime() - $t0;
        echo 'rtrip nest(50x5) ' . $elapsed . "s\n";

        echo "\n===== DONE =====\n";
    }

    private function benchEncode(string $label, array $data, int $N): void
    {
        // 预热
        $w = 0; while ($w < 500) { json_encode($data); $w = $w + 1; }
        // 计时
        $t0 = microtime();
        $i = 0; while ($i < $N) { json_encode($data); $i = $i + 1; }
        $elapsed = microtime() - $t0;
        echo $label . ' ' . $elapsed . "s\n";
    }

    private function benchDecode(string $label, string $json, int $N): void
    {
        $w = 0; while ($w < 500) { json_decode($json); $w = $w + 1; }
        $t0 = microtime();
        $i = 0; while ($i < $N) { json_decode($json); $i = $i + 1; }
        $elapsed = microtime() - $t0;
        echo $label . ' ' . $elapsed . "s\n";
    }

    private function roundTrip(array $data): string
    {
        $enc = json_encode($data);
        $dec = json_decode($enc);
        return json_encode($dec);
    }

    private function mkJson(int $n): string
    {
        $s = '[';
        $k = 0;
        while ($k < $n) {
            if ($k > 0) { $s = $s . ','; }
            $s = $s . $k;
            $k = $k + 1;
        }
        $s = $s . ']';
        return $s;
    }

    private function mkObjJson(int $n): string
    {
        $s = '{';
        $m = 0;
        while ($m < $n) {
            if ($m > 0) { $s = $s . ','; }
            $s = $s . '"k_' . $m . '":' . ($m * 10);
            $m = $m + 1;
        }
        $s = $s . '}';
        return $s;
    }
}
