<?php
// Future::race 测试 — 验证 race 等待任意一个完成（不只 futures[0]）
//   - futures[0] 永不完成
//   - futures[1] 在子线程中先完成
//   - race 应返回 futures[1] 的结果（42），而非阻塞在 futures[0]

#debug === Future::race (second wins) ===
#debug race=42
#debug
#debug === done ===

class Main
{
    public function main(): void
    {
        // ── futures[0] 永不完成，futures[1] 子线程延迟后完成 ──
        // 旧实现只等 futures[0] 的 condvar，会永久阻塞；修复后轮询全部 Future
        echo "=== Future::race (second wins) ===\n";

        $f1 = Future::create();   // futures[0] — 永不 resolve
        $f2 = Future::create();   // futures[1] — 子线程 resolve

        $t = new Thread(function() use ($f2): int {
            Thread::sleep(0.05);
            $f2->resolve(42);
            return 0;
        });
        $t->start();

        $frace = Future::race([$f1, $f2]);
        $racev = $frace->await();
        echo "race=" . $racev . "\n";

        $t->join();

        echo "\n=== done ===\n";
    }
}
