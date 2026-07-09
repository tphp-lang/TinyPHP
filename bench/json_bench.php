<?php
// ============================================================
// JSON 性能基准测试: TinyPHP (tphp) vs PHP 原生
//
// 运行方式:
//   PHP 原生:  php bench/json_bench.php
//   TinyPHP:   tphp bench/json_bench.php && ./bench/json_bench
// ============================================================

class Main
{
    const int ITER = 10000;
    const int WARM = 1000;

    // ── encode 测试数据 ──
    private array $smallArr;
    private array $largeArr;
    private array $nestedObj;
    private string $escapeStr;
    private array $mixedArr;

    // ── decode 测试数据 ──  
    private string $smallJson;
    private string $largeJson;
    private string $nestedJson;
    private string $objJson;

    public function main(): void
    {
        $this->setup();

        echo "========================================\n";
        echo "  JSON 性能对比: TinyPHP vs PHP 原生\n";
        echo "  迭代次数: " . self::ITER . "  预热: " . self::WARM . "\n";
        echo "========================================\n\n";

        // ──── json_encode ────
        echo "───── json_encode ─────\n";

        $this->bench('encode(小数组 10元素)     ', function(): string {
            return json_encode($this->smallArr);
        });

        $this->bench('encode(大数组 1000元素)   ', function(): string {
            return json_encode($this->largeArr);
        });

        $this->bench('encode(嵌套对象 50键)     ', function(): string {
            return json_encode($this->nestedObj);
        });

        $this->bench('encode(含转义字符串)      ', function(): string {
            return json_encode($this->escapeStr);
        });

        $this->bench('encode(混合类型数组)      ', function(): string {
            return json_encode($this->mixedArr);
        });

        // ──── json_decode ────
        echo "\n───── json_decode ─────\n";

        $this->bench('decode(小JSON 10元素)     ', function(): mixed {
            return json_decode($this->smallJson);
        });

        $this->bench('decode(大JSON 1000元素)   ', function(): mixed {
            return json_decode($this->largeJson);
        });

        $this->bench('decode(深层嵌套JSON)      ', function(): mixed {
            return json_decode($this->nestedJson);
        });

        $this->bench('decode(对象JSON 50键)     ', function(): mixed {
            return json_decode($this->objJson);
        });

        // ──── round-trip ────
        echo "\n───── round-trip (encode → decode → encode) ─────\n";

        $this->bench('round-trip(小数组)        ', function(): string {
            $enc = json_encode($this->smallArr);
            $dec = json_decode($enc);
            return json_encode($dec);
        });

        $this->bench('round-trip(嵌套对象)      ', function(): string {
            $enc = json_encode($this->nestedObj);
            $dec = json_decode($enc);
            return json_encode($dec);
        });

        echo "\n========================================\n";
        echo "  测试完成\n";
        echo "========================================\n";
    }

    // ── 初始化测试数据 ──
    private function setup(): void
    {
        // 小数组
        $this->smallArr = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10];

        // 大数组
        $this->largeArr = [];
        $i = 0;
        while ($i < 1000) {
            $this->largeArr[] = $i;
            $i = $i + 1;
        }

        // 嵌套数组对象
        $this->nestedObj = [];
        $j = 0;
        while ($j < 50) {
            $inner = [];
            $inner['id'] = $j;
            $inner['name'] = 'user_' . $j;
            $inner['score'] = 95.5;
            $inner['active'] = true;
            $inner['tags'] = [$j, $j + 1, $j + 2];
            $this->nestedObj[$j] = $inner;
            $j = $j + 1;
        }

        // 含转义字符串
        $this->escapeStr = "hello \"world\"\nline2\tindented\rreturn\\path";

        // 混合类型数组
        $this->mixedArr = [42, -7, 3.14159, "hello", true, false, null, [1, 2, 3]];
        $this->mixedArr['key'] = 'value';

        // ── decode 测试 JSON ──

        // 小 JSON
        $this->smallJson = '[1,2,3,4,5,6,7,8,9,10]';

        // 大 JSON
        $big = '[';
        $k = 0;
        while ($k < 1000) {
            if ($k > 0) { $big = $big . ','; }
            $big = $big . $k;
            $k = $k + 1;
        }
        $big = $big . ']';
        $this->largeJson = $big;

        // 深层嵌套 JSON
        $this->nestedJson = '[[[[1,2],[3,4]],[[5,6],[7,8]]],[[[9,10],[11,12]],[[13,14],[15,16]]]]';

        // 对象 JSON
        $obj = '{';
        $m = 0;
        while ($m < 50) {
            if ($m > 0) { $obj = $obj . ','; }
            $obj = $obj . '"key_' . $m . '":' . ($m * 10);
            $m = $m + 1;
        }
        $obj = $obj . '}';
        $this->objJson = $obj;
    }

    // ── 基准测试运行器 ──
    private function bench(string $label, callable $fn): void
    {
        // 预热
        $w = 0;
        while ($w < self::WARM) {
            $fn();
            $w = $w + 1;
        }

        // 正式计时
        $start = microtime(true);
        $iter = 0;
        while ($iter < self::ITER) {
            $fn();
            $iter = $iter + 1;
        }
        $elapsed = microtime(true) - $start;
        $perOp = ($elapsed / (float)self::ITER) * 1000000.0;

        printf("%s  %8.2f 秒  (%7.2f μs/op)\n", $label, $elapsed, $perOp);
    }
}
