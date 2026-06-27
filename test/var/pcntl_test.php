<?php

// @exit 1 — POSIX only, Windows will crash (expected)

class Main {
    public function main(): void {
        // pcntl_fork returns -1 on Win / no-fork, child exits immediately on Linux
        echo "-- 1. fork --\n";
        $pid = pcntl_fork();
        if ($pid == -1) {
            echo "fork failed (expected on Windows)\n";
        } elseif ($pid == 0) {
            echo "child ok\n";
            exit(0);
        } else {
            echo "parent pid=" . $pid . "\n";
            $st = 0;
            pcntl_waitpid($pid, $st, 0);
            echo "child reaped\n";
        }

        echo "-- 2. error/alarm --\n";
        echo "errno=" . pcntl_get_last_error() . "\n";
        echo "alarm=" . pcntl_alarm(0) . "\n";

        echo "\n=== pcntl OK ===\n";
    }
}
