<?php
// Channel 测试 — 验证 CSP 风格有界通道
//   - 基本收发（单线程 push + pop）
//   - 跨线程收发
//   - 有界容量验证
//   - tryPush/tryPop 非阻塞
//   - close 唤醒阻塞的 pop
//   - close 后 pop 返回剩余再返回 null
//   - close 后 push 抛 ChannelClosedException

#debug === Channel basic ===
#debug basic=42
#debug len=0
#debug
#debug === Cross-thread ===
#debug cross=100
#debug
#debug === Bounded capacity ===
#debug cap=2
#debug tryPush_full=0
#debug after_pop=1
#debug
#debug === close wakes pop ===
#debug close_wake=1
#debug
#debug === close remaining ===
#debug rem1=1
#debug rem2=2
#debug rem3=0
#debug
#debug === push after close ===
#debug push_closed=1
#debug
#debug === done ===

class Main
{
    public function main(): void
    {
        // ── Channel basic: push + pop (single thread) ──
        echo "=== Channel basic ===\n";
        $ch = new Channel(8);
        $ch->push(42);
        $v = $ch->pop();
        echo "basic=" . $v . "\n";
        echo "len=" . $ch->length() . "\n";

        // ── Cross-thread: producer/consumer ──
        echo "\n=== Cross-thread ===\n";
        $ch2 = new Channel(4);
        $producer = new Thread(function() use ($ch2): int {
            $ch2->push(100);
            return 0;
        });
        $producer->start();
        $cv = $ch2->pop();
        echo "cross=" . $cv . "\n";
        $producer->join();

        // ── Bounded capacity: tryPush when full ──
        echo "\n=== Bounded capacity ===\n";
        $ch3 = new Channel(2);
        $r1 = $ch3->tryPush(1);
        $r2 = $ch3->tryPush(2);
        $r3 = $ch3->tryPush(3);  // should fail (full)
        echo "cap=" . ($r1 && $r2 ? 2 : 0) . "\n";
        echo "tryPush_full=" . ($r3 ? 1 : 0) . "\n";
        $ch3->pop();
        $r4 = $ch3->tryPush(3);  // should succeed now
        echo "after_pop=" . ($r4 ? 1 : 0) . "\n";

        // ── close wakes blocked pop ──
        echo "\n=== close wakes pop ===\n";
        $ch4 = new Channel(1);
        $closer = new Thread(function() use ($ch4): int {
            Thread::sleep(0.05);
            $ch4->close();
            return 0;
        });
        $closer->start();
        $rv = $ch4->pop();  // blocks, then returns null after close
        echo "close_wake=" . ($rv === null ? 1 : 0) . "\n";
        $closer->join();

        // ── close: pop remaining then null ──
        echo "\n=== close remaining ===\n";
        $ch5 = new Channel(4);
        $ch5->push(1);
        $ch5->push(2);
        $ch5->close();
        $a = $ch5->pop();
        $b = $ch5->pop();
        $c = $ch5->pop();  // null (empty + closed)
        echo "rem1=" . $a . "\n";
        echo "rem2=" . $b . "\n";
        echo "rem3=" . ($c === null ? 0 : $c) . "\n";

        // ── push after close throws ──
        echo "\n=== push after close ===\n";
        $ch6 = new Channel(1);
        $ch6->close();
        $caught = 0;
        try {
            $ch6->push(99);
        } catch (ChannelClosedException $e) {
            $caught = 1;
        }
        echo "push_closed=" . $caught . "\n";

        echo "\n=== done ===\n";
    }
}
