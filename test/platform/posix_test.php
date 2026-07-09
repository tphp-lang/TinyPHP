<?php // @skip — POSIX only; Windows 触发 fatal error → exit(1)

#import posix

#debug -- 1. pid/uid --
#debug ~ pid=
#debug ~ ppid=
#debug ~ uid=
#debug ~ gid=
#debug -- 2. getcwd --
#debug ~ string(
#debug -- 3. isatty/error --
#debug ~ tty(1)=
#debug ~ last=0
#debug === posix OK ===

class Main {
    public function main(): void {
        // ── 1. Process identity ──
        echo "-- 1. pid/uid --\n";
        echo "pid=" . posix_getpid() . "\n";
        echo "ppid=" . posix_getppid() . "\n";
        echo "uid=" . posix_getuid() . "\n";
        echo "gid=" . posix_getgid() . "\n";

        // ── 2. getcwd ──
        echo "-- 2. getcwd --\n";
        var_dump(posix_getcwd());

        // ── 3. isatty / error ──
        echo "-- 3. isatty/error --\n";
        echo "tty(1)=" . posix_isatty(1) . "\n";
        echo "last=" . posix_get_last_error() . "\n";

        echo "\n=== posix OK ===\n";
    }
}
