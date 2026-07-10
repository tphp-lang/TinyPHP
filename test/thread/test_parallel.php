<?php
// Parallel::map / Parallel::for 测试 — 验证 P4-4 数据并行 API
//   - Parallel::map: int→int 并行变换（多线程 + 单线程降级）
//   - Parallel::for: Mutex 保护的可变 Counter 累加（跨线程安全）

#debug === Parallel::map ===
#debug map_ok=1
#debug
#debug === Parallel::for ===
#debug for_sum=4950
#debug
#debug === Parallel::map (single thread) ===
#debug map_single=1
#debug
#debug === done ===

class Counter
{
    public int $value = 0;
}

class Main
{
    public function main(): void
    {
        // ── Parallel::map: int→int 并行变换（默认 4 线程）──
        echo "=== Parallel::map ===\n";
        $data = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10];
        $result = Parallel::map($data, fn(int $x): int => $x * $x);
        // 期望: 1,4,9,16,25,36,49,64,81,100
        $ok = true;
        $expected = [1, 4, 9, 16, 25, 36, 49, 64, 81, 100];
        for ($i = 0; $i < 10; $i++) {
            if ($result[$i] != $expected[$i]) {
                $ok = false;
                break;
            }
        }
        echo "map_ok=" . ($ok ? 1 : 0) . "\n";

        // ── Parallel::for: Mutex 保护的 Counter 累加 ──
        // 0+1+2+...+99 = 4950
        echo "\n=== Parallel::for ===\n";
        $counter = new Counter();
        $mutex = new Mutex(false);
        Parallel::for(100, function(int $i) use ($counter, $mutex): void {
            $mutex->lock();
            $counter->value += $i;
            $mutex->unlock();
        });
        echo "for_sum=" . $counter->value . "\n";

        // ── Parallel::map with threads=1（单线程降级路径）──
        echo "\n=== Parallel::map (single thread) ===\n";
        $data2 = [10, 20, 30, 40, 50];
        $result2 = Parallel::map($data2, fn(int $x): int => $x + 1, 1);
        // 期望: 11,21,31,41,51
        $ok2 = ($result2[0] == 11 && $result2[1] == 21 && $result2[2] == 31
             && $result2[3] == 41 && $result2[4] == 51);
        echo "map_single=" . ($ok2 ? 1 : 0) . "\n";

        echo "\n=== done ===\n";
    }
}
