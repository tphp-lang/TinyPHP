<?php
// chan_select 测试 — 验证多通道多路复用
//   - 就绪通道返回索引
//   - 无就绪时阻塞（短暂）
//   - 超时返回 -1
//   - 全关闭返回 -2
//   - 跨线程 push 后 select 返回

#debug === chan_select ready ===
#debug ready_idx=1
#debug
#debug === chan_select timeout ===
#debug timeout=-1
#debug
#debug === chan_select all closed ===
#debug all_closed=-2
#debug
#debug === chan_select cross-thread ===
#debug cross_idx=0
#debug
#debug === done ===

class Main
{
    public function main(): void
    {
        // ── chan_select: ready channel returns index ──
        echo "=== chan_select ready ===\n";
        $ch1 = new Channel(4);
        $ch2 = new Channel(4);
        $ch2->push("hello");
        $idx = chan_select([$ch1, $ch2], 100);
        echo "ready_idx=" . $idx . "\n";

        // ── chan_select: timeout ──
        echo "\n=== chan_select timeout ===\n";
        $ch3 = new Channel(4);
        $ch4 = new Channel(4);
        $tidx = chan_select([$ch3, $ch4], 100);
        echo "timeout=" . $tidx . "\n";

        // ── chan_select: all closed ──
        echo "\n=== chan_select all closed ===\n";
        $ch5 = new Channel(4);
        $ch6 = new Channel(4);
        $ch5->close();
        $ch6->close();
        $cidx = chan_select([$ch5, $ch6], 100);
        echo "all_closed=" . $cidx . "\n";

        // ── chan_select: cross-thread push ──
        echo "\n=== chan_select cross-thread ===\n";
        $ch7 = new Channel(4);
        $ch8 = new Channel(4);
        $producer = new Thread(function() use ($ch7): int {
            Thread::sleep(0.05);
            $ch7->push("data");
            return 0;
        });
        $producer->start();
        $xidx = chan_select([$ch7, $ch8], 5000);
        echo "cross_idx=" . $xidx . "\n";
        $producer->join();

        echo "\n=== done ===\n";
    }
}
