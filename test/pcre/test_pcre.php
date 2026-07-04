<?php
// PCRE 正则扩展测试 — 验证 preg_match/preg_match_all/preg_replace/preg_quote/preg_split/preg_grep
#import pcre

#debug === preg_match ===
#debug match_count=2
#debug full=Hello World
#debug group1=World
#debug nomatch=0
#debug case_match=1
#debug
#debug === preg_match_all ===
#debug all_groups=1
#debug all_count=3
#debug m0=1
#debug m1=2
#debug m2=3
#debug
#debug === preg_replace ===
#debug replace=aXbX
#debug backref=World!
#debug limit=aXbXc3d4
#debug
#debug === preg_quote ===
#debug quote=a\+b\*c
#debug
#debug === preg_split ===
#debug split_count=3
#debug s0=a
#debug s1=b
#debug s2=c
#debug
#debug === preg_grep ===
#debug grep_count=2
#debug
#debug === done ===

class Main {
    public function main(): void {
        // ── preg_match ──
        echo "=== preg_match ===\n";
        $m = preg_match('/^Hello (\w+)/', 'Hello World');
        echo "match_count=" . count($m) . "\n";
        echo "full=" . $m[0] . "\n";
        echo "group1=" . $m[1] . "\n";

        $m2 = preg_match('/xyz/', 'Hello World');
        echo "nomatch=" . count($m2) . "\n";

        $m3 = preg_match('/hello/i', 'Hello World');
        echo "case_match=" . count($m3) . "\n";

        echo "\n";

        // ── preg_match_all ──
        echo "=== preg_match_all ===\n";
        $all = preg_match_all('/\d+/', 'a1b2c3');
        // $all[0] = array of all full matches
        $grp0 = $all[0];
        echo "all_groups=" . count($all) . "\n";
        echo "all_count=" . count($grp0) . "\n";
        echo "m0=" . $grp0[0] . "\n";
        echo "m1=" . $grp0[1] . "\n";
        echo "m2=" . $grp0[2] . "\n";

        echo "\n";

        // ── preg_replace ──
        echo "=== preg_replace ===\n";
        $r1 = preg_replace('/\d/', 'X', 'a1b2', -1);
        echo "replace=" . $r1 . "\n";

        $r2 = preg_replace('/^Hello (\w+)/', '$1!', 'Hello World', -1);
        echo "backref=" . $r2 . "\n";

        $r3 = preg_replace('/\d/', 'X', 'a1b2c3d4', 2);
        echo "limit=" . $r3 . "\n";

        echo "\n";

        // ── preg_quote ──
        echo "=== preg_quote ===\n";
        $q = preg_quote('a+b*c', '');
        echo "quote=" . $q . "\n";

        echo "\n";

        // ── preg_split ──
        echo "=== preg_split ===\n";
        $parts = preg_split('/[,;]/', 'a,b;c', -1, 0);
        echo "split_count=" . count($parts) . "\n";
        echo "s0=" . $parts[0] . "\n";
        echo "s1=" . $parts[1] . "\n";
        echo "s2=" . $parts[2] . "\n";

        echo "\n";

        // ── preg_grep ──
        echo "=== preg_grep ===\n";
        $input = ['apple', 'banana', 'cherry', '123', '456'];
        $matched = preg_grep('/^\d+$/', $input, 0);
        echo "grep_count=" . count($matched) . "\n";

        echo "\n";

        // ── done ──
        echo "=== done ===\n";
    }
}
