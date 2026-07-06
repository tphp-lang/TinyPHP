<?php
#import libevent

class Main {
    public function main(): void {
        echo "=== EventBase test ===\n";
        $base = new EventBase();
        var_dump($base->getMethod());

        echo "=== EventTimer test ===\n";
        $timer = new EventTimer($base, function() {
            echo "timer fired\n";
        });
        $timer->add(100);

        echo "=== EventSignal test ===\n";
        $sig = new EventSignal($base, 2, function() {
            echo "SIGINT received\n";
        });
        $sig->add();

        echo "=== EventBuffer test ===\n";
        $buf = new EventBuffer();
        $buf->add("hello ");
        $buf->add("world");
        var_dump($buf->length());
        var_dump($buf->pullup(-1));

        echo "=== EventBufferEvent test ===\n";
        $bev = new EventBufferEvent($base, -1, EV_READ | EV_WRITE);
        var_dump($bev !== null);

        echo "=== Constants ===\n";
        var_dump(EV_READ);
        var_dump(EV_WRITE);
        var_dump(EV_PERSIST);
        var_dump(EVLOOP_ONCE);

        echo "=== Done ===\n";
    }
}
