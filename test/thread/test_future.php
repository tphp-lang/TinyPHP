<?php
// Future 测试 — 验证异步结果传递
//   - resolve + await
//   - reject + await 抛异常
//   - 跨线程 resolve + 主线程 await
//   - then 链式调用
//   - catch 错误恢复
//   - Future::all 全部完成
//   - Future::all 任一 reject
//   - Future::race 第一个完成

#debug === Future resolve ===
#debug resolve=42
#debug ready=1
#debug
#debug === Future reject ===
#debug reject_caught=1
#debug
#debug === Cross-thread ===
#debug cross=99
#debug
#debug === then chain ===
#debug then=84
#debug
#debug === catch recovery ===
#debug catch=0
#debug
#debug === Future::all ===
#debug all=60
#debug
#debug === Future::all reject ===
#debug all_reject=1
#debug
#debug === Future::race ===
#debug race=1
#debug
#debug === done ===

class Main
{
    public function main(): void
    {
        // ── Future resolve + await ──
        echo "=== Future resolve ===\n";
        $f = Future::create();
        $f->resolve(42);
        $v = $f->await();
        echo "resolve=" . $v . "\n";
        echo "ready=" . ($f->isReady() ? 1 : 0) . "\n";

        // ── Future reject + await throws ──
        echo "\n=== Future reject ===\n";
        $f2 = Future::create();
        $f2->reject(new Exception("failed"));
        $caught = 0;
        try {
            $f2->await();
        } catch (Exception $e) {
            $caught = 1;
        }
        echo "reject_caught=" . $caught . "\n";

        // ── Cross-thread: child resolves, main awaits ──
        echo "\n=== Cross-thread ===\n";
        $f3 = Future::create();
        $t = new Thread(function() use ($f3): int {
            Thread::sleep(0.05);
            $f3->resolve(99);
            return 0;
        });
        $t->start();
        $cv = $f3->await();
        echo "cross=" . $cv . "\n";
        $t->join();

        // ── then chain ──
        echo "\n=== then chain ===\n";
        $f4 = Future::create();
        $f4->resolve(42);
        $f4b = $f4->then(function(mixed $x): mixed { return $x * 2; });
        $tv = $f4b->await();
        echo "then=" . $tv . "\n";

        // ── catch recovery ──
        echo "\n=== catch recovery ===\n";
        $f5 = Future::create();
        $f5->reject(new Exception("err"));
        $f5b = $f5->catch(function(mixed $e): mixed { return 0; });
        $rv = $f5b->await();
        echo "catch=" . $rv . "\n";

        // ── Future::all (all resolve) ──
        echo "\n=== Future::all ===\n";
        $fa1 = Future::create();
        $fa2 = Future::create();
        $fa3 = Future::create();
        $fa1->resolve(10);
        $fa2->resolve(20);
        $fa3->resolve(30);
        $fall = Future::all([$fa1, $fa2, $fa3]);
        $arr = $fall->await();
        $sum = $arr[0] + $arr[1] + $arr[2];
        echo "all=" . $sum . "\n";

        // ── Future::all (one reject) ──
        echo "\n=== Future::all reject ===\n";
        $fb1 = Future::create();
        $fb2 = Future::create();
        $fb1->resolve(1);
        $fb2->reject(new Exception("bad"));
        $fall2 = Future::all([$fb1, $fb2]);
        $all_reject = 0;
        try {
            $fall2->await();
        } catch (Exception $e) {
            $all_reject = 1;
        }
        echo "all_reject=" . $all_reject . "\n";

        // ── Future::race ──
        echo "\n=== Future::race ===\n";
        $fr1 = Future::create();
        $fr2 = Future::create();
        $fr1->resolve(1);
        // fr2 stays pending
        $frace = Future::race([$fr1, $fr2]);
        $racev = $frace->await();
        echo "race=" . $racev . "\n";

        echo "\n=== done ===\n";
    }
}
