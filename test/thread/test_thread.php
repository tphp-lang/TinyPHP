<?php
// Thread/Mutex/CondVar/WaitGroup 测试 — 验证 P4 线程支持
//   - Thread: create/start/join/detach
//   - Mutex: lock/tryLock/unlock
//   - WaitGroup: add/done/wait (含跨线程)
//   - Thread 静态方法: yield/sleep/id
//   - CondVar: signal/broadcast (非阻塞)
//   - CondVar: wait + signal (跨线程协调)

#debug === Thread basic ===
#debug ret=42
#debug
#debug === Mutex ===
#debug lock=1
#debug tryLock_locked=0
#debug unlock=1
#debug
#debug === WaitGroup sync ===
#debug wg_sync=1
#debug
#debug === Thread + WaitGroup ===
#debug cross_thread=1
#debug
#debug === Thread static ===
#debug id_positive=1
#debug
#debug === CondVar ===
#debug signal=1
#debug broadcast=1
#debug
#debug === CondVar wait ===
#debug cv_wait=1
#debug
#debug === done ===

class Main
{
    public function main(): void
    {
        // ── Thread basic: create, start, join ──
        $thread = new Thread(function(): int {
            return 42;
        });
        $thread->start();
        $ret = $thread->join();
        echo "=== Thread basic ===\n";
        echo "ret=" . $ret . "\n";

        // ── Mutex: lock, tryLock, unlock ──
        echo "\n=== Mutex ===\n";
        $mutex = new Mutex(false);
        $locked = $mutex->lock();
        echo "lock=" . ($locked ? 1 : 0) . "\n";
        // tryLock on a locked non-recursive mutex should fail
        $tryLocked = $mutex->tryLock();
        echo "tryLock_locked=" . ($tryLocked ? 1 : 0) . "\n";
        $unlocked = $mutex->unlock();
        echo "unlock=" . ($unlocked ? 1 : 0) . "\n";

        // ── WaitGroup sync (same thread) ──
        echo "\n=== WaitGroup sync ===\n";
        $wg = new WaitGroup();
        $wg->add(1);
        $wg->done();
        $wg->wait();
        echo "wg_sync=1\n";

        // ── Thread + WaitGroup (cross-thread) ──
        echo "\n=== Thread + WaitGroup ===\n";
        $wg2 = new WaitGroup();
        $wg2->add(1);
        $t2 = new Thread(function() use ($wg2): int {
            $wg2->done();
            return 0;
        });
        $t2->start();
        $wg2->wait();
        $t2->join();
        echo "cross_thread=1\n";

        // ── Thread static methods ──
        echo "\n=== Thread static ===\n";
        Thread::yield();
        Thread::sleep(0.001);
        $tid = Thread::id();
        echo "id_positive=" . ($tid > 0 ? 1 : 0) . "\n";

        // ── CondVar: signal/broadcast (non-blocking) ──
        echo "\n=== CondVar ===\n";
        $cv = new CondVar();
        $sig = $cv->signal();
        echo "signal=" . ($sig ? 1 : 0) . "\n";
        $bcast = $cv->broadcast();
        echo "broadcast=" . ($bcast ? 1 : 0) . "\n";

        // ── CondVar: wait + signal (cross-thread coordination) ──
        // 时序保证（无竞态）：
        //   1. 子线程 lock → done(WaitGroup) → wait(释放 lock 并阻塞)
        //   2. 主线程 wg->wait() 返回 → lock(阻塞，因子线程持有) → 子线程 wait 释放 lock
        //   3. 主线程获取 lock → signal(唤醒子线程) → unlock
        //   4. 子线程 wait 返回 → unlock → return 1
        //   5. 主线程 join 获取返回值
        echo "\n=== CondVar wait ===\n";
        $mutex2 = new Mutex(false);
        $cv2 = new CondVar();
        $wg3 = new WaitGroup();
        $wg3->add(1);

        $consumer = new Thread(function() use ($mutex2, $cv2, $wg3): int {
            $mutex2->lock();
            $wg3->done();            // 通知主线程：已获取 lock，即将 wait
            $cv2->wait($mutex2);     // 释放 lock 并等待 signal
            $mutex2->unlock();
            return 1;
        });
        $consumer->start();

        $wg3->wait();                // 等待子线程获取 lock
        $mutex2->lock();             // 子线程 wait 后释放 lock，主线程获取
        $cv2->signal();              // 唤醒子线程
        $mutex2->unlock();

        $ret = $consumer->join();
        echo "cv_wait=" . ($ret == 1 ? 1 : 0) . "\n";

        echo "\n=== done ===\n";
    }
}
