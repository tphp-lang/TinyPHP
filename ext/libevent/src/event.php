<?php
// ext/libevent/src/event.php — libevent object classes for TinyPHP
//
// C structs use tphp_class_ pattern with t_object header.
// PHP classes call tphp_class_* methods via object dispatch.

#flag -I__EXT__ . "/libevent/include"
#flag -L__EXT__ . "/libevent/lib"
#flag -l event_core
#include __EXT__ . "libevent/src/event.h"

// ── EventBase ──
class EventBase {
    /** @var resource */
    private $base;

    public function __construct() {
        C->tphp_class_EventBase___construct($this);
    }

    public function dispatch(): int {
        return C->tphp_class_EventBase_dispatch($this);
    }

    public function loop(int $flags = 0): int {
        return C->tphp_class_EventBase_loop($this, $flags);
    }

    public function loopBreak(): int {
        return C->tphp_class_EventBase_loopBreak($this);
    }

    public function __destruct() {
        C->tphp_class_EventBase___destruct($this);
    }
}

// ── Event ──
class Event {
    /** @var resource */
    private $ev;

    public function __construct(EventBase $base, int $fd, int $events, callable $callback) {
        C->tphp_class_Event___construct($this, $base, $fd, $events, $callback);
    }

    public function add(int $timeoutMs = 0): int {
        return C->tphp_class_Event_add($this, $timeoutMs);
    }

    public function del(): int {
        return C->tphp_class_Event_del($this);
    }

    public function pending(int $events): int {
        return C->tphp_class_Event_pending($this, $events);
    }

    public function __destruct() {
        C->tphp_class_Event___destruct($this);
    }
}

// ── EventBuffer ──
class EventBuffer {
    /** @var resource */
    private $buf;

    public function __construct() {
        C->tphp_class_EventBuffer___construct($this);
    }

    public function add(string $data): int {
        return C->tphp_class_EventBuffer_add($this, $data);
    }

    public function drain(int $len): int {
        return C->tphp_class_EventBuffer_drain($this, $len);
    }

    public function remove(int $len): string {
        return C->tphp_class_EventBuffer_remove($this, $len);
    }

    public function length(): int {
        return C->tphp_class_EventBuffer_length($this);
    }

    public function __destruct() {
        C->tphp_class_EventBuffer___destruct($this);
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
