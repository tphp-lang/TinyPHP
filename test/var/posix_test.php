<?php

// @skip — POSIX only, Windows will crash (expected)

class Main {
    public function main(): void {
        // ── 1. Process identity ──
        echo "-- 1. pid/uid --\n";
        echo "pid=" . posix_getpid() . "\n";
        echo "ppid=" . posix_getppid() . "\n";
        echo "uid=" . posix_getuid() . "\n";
        echo "gid=" . posix_getgid() . "\n";

        // ── 2. getcwd / uname ──
        echo "-- 2. getcwd/uname --\n";
        echo "cwd="; var_dump(posix_getcwd());
        echo "sysname=" . posix_uname()["sysname"] . "\n";

        // ── 3. error ──
        echo "-- 3. strerror --\n";
        echo "err="; var_dump(posix_strerror(0));
        echo "last=" . posix_get_last_error() . "\n";

        // ── 4. isatty ──
        echo "-- 4. isatty --\n";
        echo "tty(1)=" . posix_isatty(1) . "\n";

        echo "\n=== posix OK ===\n";
    }
}
