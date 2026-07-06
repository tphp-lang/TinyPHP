<?php

#flag -I__EXT__ . "/libevent/include"
#flag -L__EXT__ . "/libevent/lib"
#flag -l event_core
#include __EXT__ . "libevent/src/event.h"

// ============================================================
// Event
// ============================================================
final class Event
{
    const ET      = 32;
    const PERSIST = 16;
    const READ    = 2;
    const WRITE   = 4;
    const SIGNAL  = 8;
    const TIMEOUT = 1;

    public $pending;

    public function __construct(EventBase $base, mixed $fd, int $what, callable $cb, mixed $arg = null) {}
    public function add(double $timeout = null): bool { return false; }
    public function addSignal(double $timeout = null): bool { return false; }
    public function addTimer(double $timeout = null): bool { return false; }
    public function del(): bool { return false; }
    public function delSignal(): bool { return false; }
    public function delTimer(): bool { return false; }
    public function free(): void {}
    public static function getSupportedMethods(): array { return []; }
    public function pending(int $flags): bool { return false; }
    public function set(EventBase $base, mixed $fd, int $what = 0, callable $cb = null, mixed $arg = null): bool { return false; }
    public function setPriority(int $priority): bool { return false; }
    public function setTimer(EventBase $base, callable $cb, mixed $arg = null): bool { return false; }
    public static function signal(EventBase $base, int $signum, callable $cb, mixed $arg = null): Event { return null; }
    public static function timer(EventBase $base, callable $cb, mixed $arg = null): Event { return null; }
}

// ============================================================
// EventBase
// ============================================================
final class EventBase
{
    public function __construct(EventConfig $cfg = null) {}
    public function dispatch(): bool { return false; }
    public function loop(int $flags = 0): bool { return false; }
    public function loopBreak(): bool { return false; }
    public function loopContinue(): bool { return false; }
    public function exit(double $timeout = null): bool { return false; }
    public function stop(): bool { return false; }
    public function free(): void {}
    public function getMethod(): string { return ""; }
    public function getFeatures(): int { return 0; }
    public function getTimeOfDayCached(): float { return 0.0; }
    public function gotExit(): bool { return false; }
    public function gotStop(): bool { return false; }
    public function reInit(): bool { return false; }
    public function priorityInit(int $nPriorities): bool { return false; }
}

// ============================================================
// EventConfig
// ============================================================
final class EventConfig
{
    public function __construct() {}
    public function avoidMethod(string $method): bool { return false; }
    public function requireFeatures(int $feature): bool { return false; }
    public function setFlags(int $flags): bool { return false; }
    public function setMaxDispatchInterval(double $maxInterval) {}
}

// ============================================================
// EventTimer
// ============================================================
final class EventTimer
{
    public function __construct(EventBase $base, callable $callback, mixed $arg = null) {}
    public function add(double $timeout): bool { return false; }
    public function addTimer(double $timeout): bool { return false; }
    public function addTimerMilliseconds(int $timeout): bool { return false; }
    public function addTimerSeconds(double $timeout): bool { return false; }
    public function del(): bool { return false; }
    public function delTimer(): bool { return false; }
}

// ============================================================
// EventSignal
// ============================================================
final class EventSignal
{
    public function __construct(EventBase $base, int $signum, callable $callback, mixed $arg = null) {}
    public function add(double $timeout = null): bool { return false; }
    public function del(): bool { return false; }
}

// ============================================================
// EventBuffer
// ============================================================
final class EventBuffer
{
    public function __construct() {}
    public function add(string $data): bool { return false; }
    public function addBuffer(EventBuffer $buf): bool { return false; }
    public function appendFrom(EventBuffer $src, int $len): bool { return false; }
    public function copyout(int $len): string { return ""; }
    public function drain(int $len): bool { return false; }
    public function expand(int $len): bool { return false; }
    public function freeze(bool $freeze): bool { return false; }
    public function length(): int { return 0; }
    public function prepend(string $data): bool { return false; }
    public function prependBuffer(EventBuffer $buf): bool { return false; }
    public function pullup(int $len): string { return ""; }
    public function read(int $len): string { return ""; }
    public function readLine(int $eolStyle = 0): string { return ""; }
    public function search(string $what): int { return -1; }
    public function searchEol(int $eolStyle = 0): int { return -1; }
    public function substr(int $start, int $length): string { return ""; }
    public function unfreeze(bool $unfreeze): bool { return false; }
}

// ============================================================
// EventBufferEvent
// ============================================================
final class EventBufferEvent
{
    public function __construct(EventBase $base, mixed $fd, int $events) {}
    public function close(): void {}
    public function connect(string $addr, int $port): bool { return false; }
    public function connectHost(EventBase $dnsBase, int $family, int $sockType, string $hostname, int $port): bool { return false; }
    public static function createPair(EventBase $base, int $fd, int $events): EventBufferEvent { return null; }
    public function disable(int $events): bool { return false; }
    public function enable(int $events): bool { return false; }
    public function free(): void {}
    public function getEnabled(): int { return 0; }
    public function getInput(): EventBuffer { return null; }
    public function getOutput(): EventBuffer { return null; }
    public function read(int $len): string { return ""; }
    public function readBuffer(EventBuffer $buf): bool { return false; }
    public function setCallbacks(callable $readCb, callable $writeCb, callable $eventCb): void {}
    public function setPriority(int $priority): bool { return false; }
    public function setTimeouts(double $readTimeout, double $writeTimeout): bool { return false; }
    public function setWatermark(int $events, int $low, int $high): void {}
    public function write(string $data): bool { return false; }
    public function writeBuffer(EventBuffer $buf): bool { return false; }
}

// ============================================================
// EventListener
// ============================================================
final class EventListener
{
    public function __construct(EventBase $base, callable $callback, mixed $data, int $flags, int $backlog, string $addr) {}
    public function disable(): bool { return false; }
    public function enable(): bool { return false; }
    public function getBase(): EventBase { return null; }
    public function setCallback(callable $callback): void {}
    public function setErrorCallback(callable $callback): void {}
}

// ============================================================
// EventUtil
// ============================================================
final class EventUtil
{
    public static function getLastSocketErrno(): int { return 0; }
    public static function getLastSocketError(): string { return ""; }
    public static function getSocketFd(mixed $socket): int { return -1; }
    public static function setSocketOption(mixed $socket, int $level, int $optname, mixed $optval): bool { return false; }
}
