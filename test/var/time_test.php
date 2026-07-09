<?php
#debug === time() ===
#debug time > 1700000000: bool(true)
#debug time() >= prev: bool(true)
#debug time diff >= 0: bool(true)
#debug sum of 3 time() calls > 0: bool(true)
#debug
#debug === date() ===
#debug ~ date(Y-m-d): 2024-06-01
#debug ~ date(H:i:s): 00:00:00
#debug ~ date(full): 2024-06-01 00:00:00
#debug date(Y) is numeric: bool(true)
#debug ~ date(Y/m/d): 2024/06/01
#debug ~ date(m) for ts>0: 06
#debug ~ nested date: 01
#debug ~ Date: 2024-06-01
#debug
#debug === sleep() ===
#debug sleep(1) elapsed >= 1: bool(true)
#debug conditional sleep(1) >= 1: bool(true)
#debug sleep(0) fast: bool(true)
#debug
#debug === usleep() ===
#debug usleep(0.5s) ok: bool(true)
#debug usleep(1ms) ok: bool(true)
#debug usleep(0) fast: bool(true)
#debug usleep(variable) ok: bool(true)
#debug
#debug === hrtime() ===
#debug hrtime > 0: bool(true)
#debug hrtime monotonic: bool(true)
#debug hrtime across 10ms > 1000000: bool(true)
#debug hrtime assigned: bool(true)
#debug hrtime sleep(1) ~1e9 ns: bool(true)
#debug ~ int(1000000000)
#debug
#debug === 混合场景 ===
#debug ~ [20??-??-?? ??:??:??] app started
#debug after sleep(1) time advanced: bool(true)
#debug ~   iter 1 at ??:??:??
#debug ~   iter 2 at ??:??:??
#debug date with ts=0: 1970-01-01
#debug ~ date with big ts: 2286-11-20
#debug
#debug === done ===

class Main
{
    public function main(): void
    {
        echo "=== time() ===\n";
        $this->testTime();

        echo "\n=== date() ===\n";
        $this->testDate();

        echo "\n=== sleep() ===\n";
        $this->testSleep();

        echo "\n=== usleep() ===\n";
        $this->testUsleep();

        echo "\n=== hrtime() ===\n";
        $this->testHrtime();

        echo "\n=== 混合场景 ===\n";
        $this->testMixed();

        echo "\n=== done ===\n";
    }

    private function testTime(): void
    {
        // 基础：应返回大数值（2024年后）
        $t1 = time();
        echo "time > 1700000000: ";
        var_dump($t1 > 1700000000);

        // 连续调用应递增
        $t2 = time();
        echo "time() >= prev: ";
        var_dump($t2 >= $t1);

        // 变量参与运算
        $diff = $t2 - $t1;
        echo "time diff >= 0: ";
        var_dump($diff >= 0);

        // 多次调用
        $sum = 0;
        $sum += time();
        $sum += time();
        $sum += time();
        echo "sum of 3 time() calls > 0: ";
        var_dump($sum > 0);
    }

    private function testDate(): void
    {
        // 固定时间戳 2024-06-01 00:00:00 UTC
        $ts = 1717200000;

        // 基础格式化
        $d1 = date("Y-m-d", $ts);
        echo "date(Y-m-d): " . $d1 . "\n";

        $d2 = date("H:i:s", $ts);
        echo "date(H:i:s): " . $d2 . "\n";

        $d3 = date("Y-m-d H:i:s", $ts);
        echo "date(full): " . $d3 . "\n";

        // 只给格式，不传时间戳 → 用当前时间
        $d4 = date("Y");
        echo "date(Y) is numeric: ";
        var_dump($d4 != "");

        // 变量格式
        $fmt = "Y/m/d";
        $d5 = date($fmt, $ts);
        echo "date(Y/m/d): " . $d5 . "\n";

        // 条件分支中调用
        if ($ts > 0) {
            $d6 = date("m", $ts);
            echo "date(m) for ts>0: " . $d6 . "\n";
        }

        // 嵌套表达式
        echo "nested date: " . date("d", $ts) . "\n";

        // date 返回值参与拼接
        $label = "Date: " . date("Y-m-d", $ts);
        echo $label . "\n";
    }

    private function testSleep(): void
    {
        $start = time();
        sleep(1);
        $end = time();

        echo "sleep(1) elapsed >= 1: ";
        var_dump($end >= $start + 1);

        // 条件 sleep
        $flag = true;
        if ($flag) {
            $before = time();
            sleep(1);
            $after = time();
            echo "conditional sleep(1) >= 1: ";
            var_dump($after >= $before + 1);
        }

        // sleep(0) 不应卡住
        $b = time();
        sleep(0);
        $a = time();
        echo "sleep(0) fast: ";
        var_dump($a >= $b);
    }

    private function testUsleep(): void
    {
        // 0.5 秒
        $s = time();
        usleep(500000);
        $e = time();
        echo "usleep(0.5s) ok: ";
        var_dump($e >= $s);

        // 小间隔 1ms
        $s2 = time();
        usleep(1000);
        $e2 = time();
        echo "usleep(1ms) ok: ";
        var_dump($e2 >= $s2);

        // 0 微秒
        $s3 = time();
        usleep(0);
        $e3 = time();
        echo "usleep(0) fast: ";
        var_dump($e3 >= $s3);

        // 变量参数
        $delay = 100000; // 0.1 秒
        $s4 = time();
        usleep($delay);
        $e4 = time();
        echo "usleep(variable) ok: ";
        var_dump($e4 >= $s4);
    }

    private function testHrtime(): void
    {
        // 返回正数（纳秒）
        $t1 = hrtime();
        echo "hrtime > 0: ";
        var_dump($t1 > 0);

        // 连续调用应递增
        $t2 = hrtime();
        echo "hrtime monotonic: ";
        var_dump($t2 >= $t1);

        // 跨 sleep 后显著变大
        $b1 = hrtime();
        usleep(10000); // 10ms
        $a1 = hrtime();
        $diff = $a1 - $b1;
        echo "hrtime across 10ms > 1000000: ";
        var_dump($diff > 1000000);

        // 变量接收
        $now = hrtime();
        echo "hrtime assigned: ";
        var_dump($now > 0);

        // 用于计算耗时
        $start = hrtime();
        sleep(1);
        $elapsed = hrtime() - $start;
        echo "hrtime sleep(1) ~1e9 ns: ";
        var_dump($elapsed > 900000000);
        var_dump($elapsed);
    }

    private function testMixed(): void
    {
        // 用 time + date 生成日志格式
        $ts = time();
        $log = date("[Y-m-d H:i:s]", $ts) . " app started";
        echo $log . "\n";

        // sleep 后再次获取时间验证变化
        $b1 = time();
        sleep(1);
        $a1 = time();
        echo "after sleep(1) time advanced: ";
        var_dump($a1 > $b1);

        // 在循环中混合使用
        $iter = 0;
        while ($iter < 2) {
            $iter = $iter + 1;
            echo " iter {$iter} at " . date("H:i:s") . "\n";
        }

        // 错误条件：传入无效时间戳 date 应不崩溃
        $safe = date("Y-m-d", 0);
        echo "date with ts=0: " . $safe . "\n";

        // 大时间戳
        $big = date("Y-m-d", 9999999999);
        echo "date with big ts: " . $big . "\n";
    }
}
