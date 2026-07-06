<?php
// ext/libevent/src/event.php — libevent 事件循环扩展
//
// 本文件不做 phpc 桥接：所有 C 函数使用 tphp_fn_ 前缀直接封装 libevent API。
// PHP 侧通过 $ptr 字段存储 C 指针（t_int = int64_t 可存 64 位指针）。
// 返回 t_string 的函数（event_base_get_method, evbuffer_read, evbuffer_readln）
// 已在 CodeGenerator.php 的 inferCallReturnType() 中注册返回类型。

#include __EXT__ . "/libevent/src/event.h"

#flag -I__EXT__ . "/libevent/include"
#flag -L__EXT__ . "/libevent/lib"
#flag -levent_core
#flag Windows -lws2_32
#flag Windows -ladvapi32

// ── EventConfig ──────────────────────────────────────────
// 对应 libevent 的 struct event_config，用于配置 EventBase 的行为。

class EventConfig
{
    public int $ptr = 0;

    public function __construct() {
        $this->ptr = event_config_new();
    }

    public function avoidMethod(string $method): bool {
        return (bool)event_config_avoid_method($this->ptr, $method);
    }

    public function requireFeatures(int $feature): bool {
        return (bool)event_config_require_features($this->ptr, $feature);
    }

    public function setFlag(int $flag): bool {
        return (bool)event_config_set_flag($this->ptr, $flag);
    }

    public function free(): void {
        if ($this->ptr != 0) {
            event_config_free($this->ptr);
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
        $this->ptr = event_base_new(0);
    }

    public function loop(int $flags = 0): int {
        return event_base_loop($this->ptr, $flags);
    }

    public function dispatch(): int {
        return event_base_dispatch($this->ptr);
    }

    public function exit(float $timeout = 0): void {
        if ($timeout <= 0) {
            event_base_loopbreak($this->ptr);
        } else {
            event_base_loopexit($this->ptr, $timeout);
        }
    }

    public function stop(): void {
        event_base_loopbreak($this->ptr);
    }

    public function getMethod(): string {
        return event_base_get_method($this->ptr);
    }

    public function getFeatures(): int {
        return event_base_get_features($this->ptr);
    }

    public function priorityInit(int $n): bool {
        return (bool)event_base_priority_init($this->ptr, $n);
    }

    public function free(): void {
        if ($this->ptr != 0) {
            event_base_free($this->ptr);
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
        $this->ptr = evbuffer_new();
    }

    public function add(string $data): bool {
        return (bool)evbuffer_add($this->ptr, $data);
    }

    public function read(int $maxlen): string {
        return evbuffer_read($this->ptr, $maxlen);
    }

    public function drain(int $len): bool {
        return (bool)evbuffer_drain($this->ptr, $len);
    }

    public function prepend(string $data): bool {
        return (bool)evbuffer_prepend($this->ptr, $data);
    }

    public function expand(int $len): bool {
        return (bool)evbuffer_expand($this->ptr, $len);
    }

    public function getLength(): int {
        return evbuffer_get_length($this->ptr);
    }

    public function readLine(int $eol): string {
        return evbuffer_readln($this->ptr, $eol);
    }

    public function free(): void {
        if ($this->ptr != 0) {
            evbuffer_free($this->ptr);
            $this->ptr = 0;
        }
    }
}
