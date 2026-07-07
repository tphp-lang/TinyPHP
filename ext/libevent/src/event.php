<?php
// ext/libevent/src/event.php — libevent 事件循环扩展
//
// 本文件做 phpc 桥接：所有 C 函数直接封装 libevent API。
// PHP 侧通过 $ptr 字段存储 C 指针（t_int = int64_t 可存 64 位指针）。
// 返回 t_string 的函数（event_base_get_method, evbuffer_read, evbuffer_readln）
// 已在 CodeGenerator.php 的 inferCallReturnType() 中注册返回类型。

#include "event.h"

#flag -I__EXT__ . "/libevent/include"
#flag -L__EXT__ . "/libevent/lib"
// Linux/macOS: 三家编译器 (TCC/GCC/Clang) 共用 libevent_core.a (相同对象格式 + ABI)
// Windows: TCC 生成 ELF 而 MinGW GCC/Clang 生成 COFF，格式不互兼容，TCC 需单独的 _tcc.a
#flag Linux -levent_core
#flag MacOS -levent_core
#flag Windows GCC -levent_core
#flag Windows Clang -levent_core
#flag Windows TCC -levent_core_tcc
#flag Windows -lws2_32
#flag Windows -ladvapi32

// ── EventConfig ──────────────────────────────────────────
// 对应 libevent 的 struct event_config，用于配置 EventBase 的行为。

class EventConfig
{
    public int $ptr = 0;

    public function __construct() {
        $this->ptr = C->libevent_config_new();
    }

    public function avoidMethod(string $method): bool {
        return (bool)C->libevent_config_avoid_method($this->ptr, $method);
    }

    public function requireFeatures(int $feature): bool {
        return (bool)C->libevent_config_require_features($this->ptr, $feature);
    }

    public function setFlag(int $flag): bool {
        return (bool)C->libevent_config_set_flag($this->ptr, $flag);
    }

    public function free(): void {
        if ($this->ptr != 0) {
            C->libevent_config_free($this->ptr);
            $this->ptr = 0;
        }
    }
}

// ── EventBase ────────────────────────────────────────────
// 对应 libevent 的 struct event_base，事件循环的核心。

class EventBase
{
    public int $ptr = 0;

    public function __construct() {
        $this->ptr = C->libevent_base_new(0);
    }

    public function loop(int $flags = 0): int {
        return C->libevent_base_loop($this->ptr, $flags);
    }

    public function dispatch(): int {
        return C->libevent_base_dispatch($this->ptr);
    }

    public function exit(float $timeout = 0): void {
        if ($timeout <= 0) {
            C->libevent_base_loopbreak($this->ptr);
        } else {
            C->libevent_base_loopexit($this->ptr, $timeout);
        }
    }

    public function stop(): void {
        C->libevent_base_loopbreak($this->ptr);
    }

    public function getMethod(): string {
        return C->libevent_base_get_method($this->ptr);
    }

    public function getFeatures(): int {
        return C->libevent_base_get_features($this->ptr);
    }

    public function priorityInit(int $n): bool {
        return (bool)C->libevent_base_priority_init($this->ptr, $n);
    }

    public function free(): void {
        if ($this->ptr != 0) {
            C->libevent_base_free($this->ptr);
            $this->ptr = 0;
        }
    }
}

// ── EventBuffer ──────────────────────────────────────────
// 对应 libevent 的 struct evbuffer，用于缓冲区操作。

class EventBuffer
{
    public int $ptr = 0;

    public function __construct() {
        $this->ptr = C->libevbuffer_new();
    }

    public function add(string $data): bool {
        return (bool)C->libevbuffer_add($this->ptr, $data);
    }

    public function read(int $maxlen): string {
        return C->libevbuffer_read($this->ptr, $maxlen);
    }

    public function drain(int $len): bool {
        return (bool)C->libevbuffer_drain($this->ptr, $len);
    }

    public function prepend(string $data): bool {
        return (bool)C->libevbuffer_prepend($this->ptr, $data);
    }

    public function expand(int $len): bool {
        return (bool)C->libevbuffer_expand($this->ptr, $len);
    }

    public function getLength(): int {
        return C->libevbuffer_get_length($this->ptr);
    }

    public function readLine(int $eol): string {
        return C->libevbuffer_readln($this->ptr, $eol);
    }

    public function free(): void {
        if ($this->ptr != 0) {
            C->libevbuffer_free($this->ptr);
            $this->ptr = 0;
        }
    }
}
