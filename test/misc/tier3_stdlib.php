<?php
#debug -- 1. Hashing --
#debug md5 len=32
#debug sha1 len=40
#debug ~ crc32=907060870
#debug -- 2. parse_url --
#debug url keys=5
#debug -- 3. parse_str --
#debug ps cnt=2
#debug -- 4. strtr --
#debug tr=string(3) "12c"
#debug -- 5. ksort/krsort --
#debug ks cnt=3
#debug krs cnt=2
#debug -- 6. asort/arsort --
#debug as cnt=3
#debug ars cnt=3
#debug
#debug === Tier3 OK ===

class Main {
    public function main(): void {
        // ── 1. md5 / sha1 / crc32 ──
        echo "-- 1. Hashing --\n";
        echo "md5 len=" . strlen(md5("hello")) . "\n";
        echo "sha1 len=" . strlen(sha1("hello")) . "\n";
        echo "crc32=" . crc32("hello") . "\n";

        // ── 2. parse_url ──
        echo "-- 2. parse_url --\n";
        $u = parse_url("https://e.com:8080/p?q=1");
        echo "url keys=" . count($u) . "\n";

        // ── 3. parse_str ──
        echo "-- 3. parse_str --\n";
        echo "ps cnt=" . count(parse_str("a=1&b=2")) . "\n";

        // ── 4. strtr ──
        echo "-- 4. strtr --\n";
        echo "tr="; var_dump(strtr("abc", "ab", "12"));

        // ── 5. ksort / krsort ──
        echo "-- 5. ksort/krsort --\n";
        $a = [2=>"b", 1=>"a", 3=>"c"];
        ksort($a);
        echo "ks cnt=" . count($a) . "\n";
        $b = [2=>"b", 1=>"a"];
        krsort($b);
        echo "krs cnt=" . count($b) . "\n";

        // ── 6. asort / arsort ──
        echo "-- 6. asort/arsort --\n";
        $c = [0=>30, 1=>10, 2=>20];
        asort($c);
        echo "as cnt=" . count($c) . "\n";
        $d = [0=>10, 1=>30, 2=>20];
        arsort($d);
        echo "ars cnt=" . count($d) . "\n";

        echo "\n=== Tier3 OK ===\n";
    }
}
