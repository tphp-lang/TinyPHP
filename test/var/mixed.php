<?php

class Main
{
    public function main(): void
    {
        echo "===== mixed param =====\n";
        $this->testMixedParam();

        echo "===== union param =====\n";
        $this->testUnionParam();

        echo "===== mixed return =====\n";
        $this->testMixedReturn();

        echo "===== mixed in closures =====\n";
        $this->testClosures();

        echo "===== mixed in control flow =====\n";
        $this->testControlFlow();

        echo "all mixed/union tests passed\n";
    }

    // ── 1. mixed 参数类型 ──
    public function acceptMixed(mixed $x): void
    {
        var_dump($x);
        if ($x == 42) {
            echo "mixed int compare ok\n";
        }
    }

    private function testMixedParam(): void
    {
        $this->acceptMixed(42);
        $this->acceptMixed("hello");
        $this->acceptMixed(true);
    }

    // ── 2. int|string 联合类型参数 ──
    public function acceptUnion(int|string $v): void
    {
        var_dump($v);
    }

    private function testUnionParam(): void
    {
        $this->acceptUnion(100);
        $this->acceptUnion("world");
    }

    // ── 3. mixed 返回类型 ──
    private function makeMixedInt(): mixed
    {
        return 42;
    }

    private function makeMixedString(): mixed
    {
        return "text";
    }

    private function testMixedReturn(): void
    {
        $a = $this->makeMixedInt();
        var_dump($a);
        $b = $this->makeMixedString();
        var_dump($b);
    }

    // ── 4. 闭包中使用 mixed ──
    private function testClosures(): void
    {
        // 捕获 mixed 变量
        $base = 10;
        $fn = function(int $x) use ($base): int {
            return $x + $base;
        };
        $r = $fn(5);
        var_dump($r);

        // mixed 返回的闭包
        $mkInt = function(): mixed {
            return 123;
        };
        $rv = $mkInt();
        var_dump($rv);

        $mkStr = function(): mixed {
            return "closure string";
        };
        $rv2 = $mkStr();
        var_dump($rv2);
    }

    // ── 5. 控制流中使用 mixed 返回 ──
    private function getValue(int $mode): mixed
    {
        if ($mode == 1) {
            return 42;
        } elseif ($mode == 2) {
            return "text";
        } else {
            return true;
        }
    }

    private function testControlFlow(): void
    {
        // with while
        $cnt = 3;
        $acc = 0;
        while ($cnt > 0) {
            $acc += $cnt;
            $cnt = $cnt - 1;
        }
        var_dump($acc);

        // with for
        $total = 0;
        for ($i = 1; $i <= 3; $i = $i + 1) {
            $total += $i;
        }
        var_dump($total);

        // with mixed function call
        $v1 = $this->getValue(1);
        var_dump($v1);
        $v2 = $this->getValue(2);
        var_dump($v2);

        // ternary
        $cond = true;
        $result = ($cond) ? 100 : 0;
        var_dump($result);

        // null coalesce
        $maybeNull = null;
        $fallback = $maybeNull ?? 42;
        var_dump($fallback);
    }
}
