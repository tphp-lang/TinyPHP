<?php // libevent 静态库由 CI 预构建（ext/libevent/lib/ + include/）
//
// 完整测试 libevent 扩展的三个核心类：EventConfig / EventBase / EventBuffer。
// 构建库：cd ext/libevent && cmake -B build && cmake --build build
// 或参考 ext/CMakeLists.txt 顶层构建。

#import libevent

#debug config ok
#debug priority ok
#debug dispatch ok
#debug len=2
#debug len=7
#debug read=ab
#debug len=5
#debug len=8
#debug len=8
#debug len=5
#debug line=abc
#debug len=5
#debug
#debug === libevent OK ===

class Main
{
    public function main(): void
    {
        // ── 1. EventConfig ──────────────────────────────────
        $cfg = new EventConfig();
        $cfg->requireFeatures(1); // EV_FEATURE_ET = 1
        $cfg->setFlag(1);         // EVENT_BASE_FLAG_NOLOCK = 1
        echo "config ok\n";

        // ── 2. EventBase ────────────────────────────────────
        // 注意：getMethod/getFeatures 跨平台不一致（win32/epoll/kqueue），
        // 不输出到 stdout，避免 #debug 比对受平台影响。
        $base = new EventBase();
        $method = $base->getMethod();      // 仅验证调用成功
        $features = $base->getFeatures();  // 仅验证调用成功

        $base->priorityInit(2);
        echo "priority ok\n";

        // 立即退出循环（无事件时 dispatch 会阻塞，用 loopexit 设置超时退出）
        $base->exit(0.01);
        $base->dispatch();
        echo "dispatch ok\n";

        $base->free();
        $cfg->free();

        // ── 3. EventBuffer ──────────────────────────────────
        $buf = new EventBuffer();

        // add + getLength
        $buf->add("ab");
        echo "len=" . $buf->getLength() . "\n"; // 2
        $buf->add("cdefg");
        echo "len=" . $buf->getLength() . "\n"; // 7

        // read（移除并读取前 N 字节）
        $data = $buf->read(2);
        echo "read=" . $data . "\n"; // ab
        echo "len=" . $buf->getLength() . "\n"; // 5

        // prepend（前插）
        $buf->prepend(">>>");
        echo "len=" . $buf->getLength() . "\n"; // 8

        // expand（预分配，不改变 length）
        $buf->expand(64);
        echo "len=" . $buf->getLength() . "\n"; // 8

        // drain（丢弃前 N 字节）
        $buf->drain(3);
        echo "len=" . $buf->getLength() . "\n"; // 5

        // readLine（EOL_STYLE_CRLF = 1）
        // 当前 buffer: "cdefg"，先 drain 清空再写入测试行
        $buf->drain(5);
        $buf->add("abc\r\ndef\r\n");
        $line = $buf->readLine(1);
        echo "line=" . $line . "\n"; // abc
        echo "len=" . $buf->getLength() . "\n"; // 5 (def\r\n)

        $buf->free();

        echo "\n=== libevent OK ===\n";
    }
}
