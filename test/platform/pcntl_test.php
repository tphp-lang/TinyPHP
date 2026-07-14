<?php // @skip — POSIX only; Windows 触发 fatal error → exit(1)

#import pcntl

#debug -- 1. fork --
#debug ~ fork failed (expected on Windows)
#debug -- 2. error/alarm --
#debug errno=0
#debug alarm=0
#debug -- 3. strerror --
#debug ~ strerror=No error
#debug
#debug === pcntl OK ===
#debug ~ child ok
#debug ~ parent pid=
#debug ~ child reaped status=

class Main {
    public function main(): void {
        echo "-- 1. fork --\n";
        $pid = pcntl_fork();
        if ($pid == -1) {
            echo "fork failed (expected on Windows)\n";
        } elseif ($pid == 0) {
            echo "child ok\n";
            exit(0);
        } else {
            echo "parent pid=" . $pid . "\n";
            $status = 0;
            pcntl_waitpid($pid, $status, 0);
            echo "child reaped status=" . $status . "\n";
        }

        echo "-- 2. error/alarm --\n";
        echo "errno=" . pcntl_get_last_error() . "\n";
        echo "alarm=" . pcntl_alarm(0) . "\n";

        echo "-- 3. strerror --\n";
        echo "strerror=" . pcntl_strerror(0) . "\n";

        echo "\n=== pcntl OK ===\n";
    }
}
