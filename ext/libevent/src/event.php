<?php
// ext/libevent/src/event.php — libevent wrapper for TinyPHP
//
// Provides EventBase and Event classes that wrap libevent C API.
// C functions use opaque pointers; PHP classes manage object lifecycle.

#flag -I__EXT__ . "/libevent/include"
#flag -L__EXT__ . "/libevent/lib"
#flag -l event_core
#include __EXT__ . "libevent/src/event.h"

// ── EventBase class ──
class EventBase {
    /** @var t_event_base */
    private t_event_base $base;

    public function __construct() {
        $this->base = C->tphp_fn_event_base_new();
    }

    public function dispatch(): int {
        return C->tphp_fn_event_base_dispatch($this->base);
    }

    public function loop(int $flags = 0): int {
        return C->tphp_fn_event_base_loop($this->base, $flags);
    }

    public function loopBreak(): int {
        return C->tphp_fn_event_base_loopbreak($this->base);
    }

    public function __destruct() {
        if ($this->base !== null) {
            C->tphp_fn_event_base_free($this->base);
            $this->base = null;
        }
    }
}

// ── Event class ──
class Event {
    /** @var t_event */
    private t_event $ev;

    public function __construct(EventBase $base, int $fd, int $events, callable $callback) {
        $this->ev = C->tphp_fn_event_new($base, $fd, $events, $callback, null);
    }

    public function add(int $timeoutMs = 0): int {
        return C->tphp_fn_event_add($this->ev, $timeoutMs);
    }

    public function del(): int {
        return C->tphp_fn_event_del($this->ev);
    }

    public function pending(int $events): int {
        return C->tphp_fn_event_pending($this->ev, $events);
    }

    public function __destruct() {
        if ($this->ev !== null) {
            C->tphp_fn_event_free($this->ev);
            $this->ev = null;
        }
    }
}

// ── EventBuffer class ──
class EventBuffer {
    /** @var t_event_buffer */
    private t_event_buffer $buf;

    public function __construct() {
        $this->buf = C->tphp_fn_event_buffer_new();
    }

    public function add(string $data): int {
        return C->tphp_fn_event_buffer_add($this->buf, $data, strlen($data));
    }

    public function drain(int $len): int {
        return C->tphp_fn_event_buffer_drain($this->buf, $len);
    }

    public function remove(int $len): string {
        $buf = str_repeat("\0", $len);
        C->tphp_fn_event_buffer_remove($this->buf, $buf, $len);
        return $buf;
    }

    public function length(): int {
        return C->tphp_fn_event_buffer_length($this->buf);
    }

    public function __destruct() {
        if ($this->buf !== null) {
            C->tphp_fn_event_buffer_free($this->buf);
            $this->buf = null;
        }
    }
}

// ── Constants ──
const EV_TIMEOUT   = 0x01;
const EV_READ      = 0x02;
const EV_WRITE     = 0x04;
const EV_SIGNAL    = 0x08;
const EV_PERSIST   = 0x10;
const EV_ET        = 0x20;
const EVLOOP_ONCE      = 0x01;
const EVLOOP_NONBLOCK  = 0x02;
