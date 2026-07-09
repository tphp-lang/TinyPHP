<?php
// PCRE lookahead 测试 — 验证 (?=...) 正向 lookahead 和 (?!...) 负向 lookahead
#import pcre

#debug === Positive Lookahead ===
#debug pos_match=1
#debug pos_val=foo
#debug pos_nomatch=0
#debug
#debug === Negative Lookahead ===
#debug neg_match=1
#debug neg_val=foo
#debug neg_nomatch=0
#debug
#debug === Lookahead at start ===
#debug start_match=1
#debug start_val=foo
#debug
#debug === Number before unit ===
#debug num_px=100
#debug num_notpx=100
#debug
#debug === Capture in lookahead ===
#debug cap_count=2
#debug cap_full=123
#debug cap_grp1=123
#debug
#debug === Multiple lookaheads ===
#debug multi=1
#debug multi_val=ab
#debug
#debug === Lookahead alternation ===
#debug alt_match=1
#debug alt_val=foo
#debug
#debug === done ===

class Main {
    public function main(): void {
        // ── Positive lookahead: foo(?=bar) ──
        echo "=== Positive Lookahead ===\n";
        $m1 = preg_match('/foo(?=bar)/', 'foobar');
        echo "pos_match=" . count($m1) . "\n";
        echo "pos_val=" . $m1[0] . "\n";

        $m2 = preg_match('/foo(?=bar)/', 'foobaz');
        echo "pos_nomatch=" . count($m2) . "\n";

        echo "\n";

        // ── Negative lookahead: foo(?!bar) ──
        echo "=== Negative Lookahead ===\n";
        $m3 = preg_match('/foo(?!bar)/', 'foobaz');
        echo "neg_match=" . count($m3) . "\n";
        echo "neg_val=" . $m3[0] . "\n";

        $m4 = preg_match('/foo(?!bar)/', 'foobar');
        echo "neg_nomatch=" . count($m4) . "\n";

        echo "\n";

        // ── Lookahead at start: (?=foo)foo ──
        echo "=== Lookahead at start ===\n";
        $m5 = preg_match('/(?=foo)foo/', 'foo');
        echo "start_match=" . count($m5) . "\n";
        echo "start_val=" . $m5[0] . "\n";

        echo "\n";

        // ── Number before unit ──
        echo "=== Number before unit ===\n";
        $m6 = preg_match('/\d+(?=px)/', '100px');
        echo "num_px=" . $m6[0] . "\n";

        $m7 = preg_match('/\d+(?!px)/', '100em');
        echo "num_notpx=" . $m7[0] . "\n";

        echo "\n";

        // ── Capture inside positive lookahead ──
        echo "=== Capture in lookahead ===\n";
        $m8 = preg_match('/(?=(\d+))\d+/', '123abc');
        echo "cap_count=" . count($m8) . "\n";
        echo "cap_full=" . $m8[0] . "\n";
        echo "cap_grp1=" . $m8[1] . "\n";

        echo "\n";

        // ── Multiple lookaheads ──
        echo "=== Multiple lookaheads ===\n";
        $m9 = preg_match('/(?=a)a(?=b)b/', 'ab');
        echo "multi=" . count($m9) . "\n";
        echo "multi_val=" . $m9[0] . "\n";

        echo "\n";

        // ── Lookahead with alternation ──
        echo "=== Lookahead alternation ===\n";
        $m10 = preg_match('/(?=foo|bar)foo/', 'foobar');
        echo "alt_match=" . count($m10) . "\n";
        echo "alt_val=" . $m10[0] . "\n";

        echo "\n";

        // ── done ──
        echo "=== done ===\n";
    }
}
