<?php

#flag -I__EXT__ . "/libevent/include"
#flag -L__EXT__ . "/libevent/lib"
#flag -l event_core
#include __EXT__ . "libevent/src/event.h"

class EventBase {
    public function __construct() {}
    public function dispatch(): int { return 0; }
    public function loop(int $flags = 0): int { return 0; }
    public function loopBreak(): int { return 0; }
    public function loopContinue(): int { return 0; }
    public function stop(): int { return 0; }
    public function free(): int { return 0; }
    public function getMethod(): string { return ""; }
}

class Event {
    public function __construct(EventBase $base, int $fd, int $events, callable $callback) {}
    public function add(int $timeoutMs = 0): int { return 0; }
    public function addSignal(): int { return 0; }
    public function addTimer(int $timeoutMs): int { return 0; }
    public function del(): int { return 0; }
    public function delSignal(): int { return 0; }
    public function delTimer(): int { return 0; }
    public function free(): void {}
    public function pending(int $events): int { return 0; }
    public function set(EventBase $base, int $fd, int $events, callable $callback): void {}
    public function setPriority(int $priority): int { return 0; }
    public function getPendingEvents(): int { return 0; }
    public function signal(EventBase $base, int $signum, callable $callback): void {}
    public function timer(EventBase $base, callable $callback): void {}
    public function setTimer(int $timeoutMs): int { return 0; }
}

class EventTimer {
    public function __construct(EventBase $base, callable $callback) {}
    public function add(int $timeoutMs): int { return 0; }
    public function addTimer(int $timeoutMs): int { return 0; }
    public function addTimerMilliseconds(int $timeoutMs): int { return 0; }
    public function addTimerSeconds(int $timeoutSec): int { return 0; }
    public function del(): int { return 0; }
    public function delTimer(): int { return 0; }
}

class EventSignal {
    public function __construct(EventBase $base, int $signum, callable $callback) {}
    public function add(): int { return 0; }
    public function del(): int { return 0; }
}

class EventBuffer {
    public function __construct() {}
    public function add(string $data): int { return 0; }
    public function addBuffer(EventBuffer $src): int { return 0; }
    public function drain(int $len): int { return 0; }
    public function remove(int $len): string { return ""; }
    public function length(): int { return 0; }
    public function prepend(string $data): int { return 0; }
    public function readLine(int $eolStyle = 0): string { return ""; }
    public function pullup(int $len = -1): string { return ""; }
}

class EventBufferEvent {
    public function __construct(EventBase $base, int $fd, int $events) {}
    public function enable(int $events): int { return 0; }
    public function disable(int $events): int { return 0; }
    public function free(): void {}
    public function setCallbacks(callable $readCb, callable $writeCb, callable $eventCb): void {}
    public function write(string $data): int { return 0; }
    public function writeBuffer(EventBuffer $buf): int { return 0; }
    public function getInput(): EventBuffer { return null; }
    public function getOutput(): EventBuffer { return null; }
    public function setTimeouts(int $readMs, int $writeMs): int { return 0; }
}
