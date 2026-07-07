<?php
// filter_var / filter_list / filter_id 测试 — 验证所有过滤器路径
#debug === VALIDATE_INT ===
#debug int(42)
#debug int(-7)
#debug NULL
#debug int(255)
#debug NULL
#debug
#debug === VALIDATE_BOOL ===
#debug bool(true)
#debug bool(true)
#debug bool(true)
#debug bool(true)
#debug bool(false)
#debug bool(false)
#debug bool(false)
#debug NULL
#debug
#debug === VALIDATE_FLOAT ===
#debug float(3.14)
#debug float(-0.5)
#debug NULL
#debug
#debug === VALIDATE_EMAIL ===
#debug string(16) "user@example.com"
#debug NULL
#debug string(22) "first.last@sub.dom.org"
#debug NULL
#debug
#debug === VALIDATE_URL ===
#debug string(23) "http://example.com/path"
#debug NULL
#debug string(28) "https://example.com?q=1#frag"
#debug NULL
#debug
#debug === VALIDATE_IP ===
#debug string(9) "127.0.0.1"
#debug string(18) "2001:0db8::1:2:3:4"
#debug NULL
#debug
#debug === VALIDATE_MAC ===
#debug string(17) "00:11:22:33:44:55"
#debug NULL
#debug
#debug === VALIDATE_DOMAIN ===
#debug string(11) "example.com"
#debug NULL
#debug
#debug === SANITIZE_STRING ===
#debug string(5) "hello"
#debug string(4) "ac&d"
#debug
#debug === SANITIZE_SPECIAL_CHARS ===
#debug string(24) "a&lt;b&gt;c&amp;d&quot;e"
#debug
#debug === SANITIZE_ENCODED ===
#debug string(5) "hello"
#debug string(4) "a%3D"
#debug
#debug === SANITIZE_EMAIL ===
#debug string(16) "user@example.com"
#debug string(18) "userat@example.com"
#debug
#debug === SANITIZE_URL ===
#debug string(22) "http://example.com/p?q"
#debug
#debug === SANITIZE_NUMBER_INT ===
#debug string(3) "+42"
#debug string(2) "-7"
#debug string(3) "123"
#debug
#debug === SANITIZE_NUMBER_FLOAT ===
#debug string(4) "3.14"
#debug string(8) "-0.5e+10"
#debug
#debug === SANITIZE_ADD_SLASHES ===
#debug string(10) "a\'b\"c\\d"
#debug
#debug === SANITIZE_FULL_SPECIAL_CHARS ===
#debug string(24) "a&lt;b&gt;c&amp;d&quot;e"
#debug
#debug === INT_RANGE (min/max) ===
#debug int(50)
#debug NULL
#debug NULL
#debug
#debug === FLAGS (OCTAL/HEX) ===
#debug int(63)
#debug int(255)
#debug
#debug === filter_list ===
#debug filter_count=18
#debug first=int
#debug
#debug === filter_id ===
#debug id_int=257
#debug id_email=274
#debug id_unknown=-1
#debug
#debug === done ===

class Main {
    public function main(): void {
        // ── VALIDATE_INT ──
        echo "=== VALIDATE_INT ===\n";
        var_dump(filter_var("42", FILTER_VALIDATE_INT));
        var_dump(filter_var("-7", FILTER_VALIDATE_INT));
        var_dump(filter_var("abc", FILTER_VALIDATE_INT));
        var_dump(filter_var("0xff", FILTER_VALIDATE_INT, FILTER_FLAG_ALLOW_HEX));
        var_dump(filter_var("3.14", FILTER_VALIDATE_INT));
        echo "\n";

        // ── VALIDATE_BOOL ──
        echo "=== VALIDATE_BOOL ===\n";
        var_dump(filter_var("1", FILTER_VALIDATE_BOOL));
        var_dump(filter_var("true", FILTER_VALIDATE_BOOL));
        var_dump(filter_var("on", FILTER_VALIDATE_BOOL));
        var_dump(filter_var("yes", FILTER_VALIDATE_BOOL));
        var_dump(filter_var("0", FILTER_VALIDATE_BOOL));
        var_dump(filter_var("false", FILTER_VALIDATE_BOOL));
        var_dump(filter_var("off", FILTER_VALIDATE_BOOL));
        var_dump(filter_var("maybe", FILTER_VALIDATE_BOOL));
        echo "\n";

        // ── VALIDATE_FLOAT ──
        echo "=== VALIDATE_FLOAT ===\n";
        var_dump(filter_var("3.14", FILTER_VALIDATE_FLOAT));
        var_dump(filter_var("-0.5", FILTER_VALIDATE_FLOAT));
        var_dump(filter_var("abc", FILTER_VALIDATE_FLOAT));
        echo "\n";

        // ── VALIDATE_EMAIL ──
        echo "=== VALIDATE_EMAIL ===\n";
        var_dump(filter_var("user@example.com", FILTER_VALIDATE_EMAIL));
        var_dump(filter_var("not-an-email", FILTER_VALIDATE_EMAIL));
        var_dump(filter_var("first.last@sub.dom.org", FILTER_VALIDATE_EMAIL));
        var_dump(filter_var("@no-local.com", FILTER_VALIDATE_EMAIL));
        echo "\n";

        // ── VALIDATE_URL ──
        echo "=== VALIDATE_URL ===\n";
        var_dump(filter_var("http://example.com/path", FILTER_VALIDATE_URL));
        var_dump(filter_var("not-a-url", FILTER_VALIDATE_URL));
        var_dump(filter_var("https://example.com?q=1#frag", FILTER_VALIDATE_URL));
        var_dump(filter_var("ftp://", FILTER_VALIDATE_URL));
        echo "\n";

        // ── VALIDATE_IP ──
        echo "=== VALIDATE_IP ===\n";
        var_dump(filter_var("127.0.0.1", FILTER_VALIDATE_IP));
        var_dump(filter_var("2001:0db8::1:2:3:4", FILTER_VALIDATE_IP));
        var_dump(filter_var("999.999.999.999", FILTER_VALIDATE_IP));
        echo "\n";

        // ── VALIDATE_MAC ──
        echo "=== VALIDATE_MAC ===\n";
        var_dump(filter_var("00:11:22:33:44:55", FILTER_VALIDATE_MAC));
        var_dump(filter_var("00-11-22-33-44", FILTER_VALIDATE_MAC));
        echo "\n";

        // ── VALIDATE_DOMAIN ──
        echo "=== VALIDATE_DOMAIN ===\n";
        var_dump(filter_var("example.com", FILTER_VALIDATE_DOMAIN));
        var_dump(filter_var("-bad.com", FILTER_VALIDATE_DOMAIN));
        echo "\n";

        // ── SANITIZE_STRING ──
        echo "=== SANITIZE_STRING ===\n";
        var_dump(filter_var("hello", FILTER_SANITIZE_STRING));
        var_dump(filter_var('a<b>c&d', FILTER_SANITIZE_STRING));
        echo "\n";

        // ── SANITIZE_SPECIAL_CHARS ──
        echo "=== SANITIZE_SPECIAL_CHARS ===\n";
        var_dump(filter_var('a<b>c&d"e', FILTER_SANITIZE_SPECIAL_CHARS));
        echo "\n";

        // ── SANITIZE_ENCODED ──
        echo "=== SANITIZE_ENCODED ===\n";
        var_dump(filter_var("hello", FILTER_SANITIZE_ENCODED));
        var_dump(filter_var("a=", FILTER_SANITIZE_ENCODED));
        echo "\n";

        // ── SANITIZE_EMAIL ──
        echo "=== SANITIZE_EMAIL ===\n";
        var_dump(filter_var("user@example.com", FILTER_SANITIZE_EMAIL));
        var_dump(filter_var("user!#at@example.com", FILTER_SANITIZE_EMAIL));
        echo "\n";

        // ── SANITIZE_URL ──
        echo "=== SANITIZE_URL ===\n";
        var_dump(filter_var("http://example.com/p?q", FILTER_SANITIZE_URL));
        echo "\n";

        // ── SANITIZE_NUMBER_INT ──
        echo "=== SANITIZE_NUMBER_INT ===\n";
        var_dump(filter_var("+42abc", FILTER_SANITIZE_NUMBER_INT));
        var_dump(filter_var("-7xyz", FILTER_SANITIZE_NUMBER_INT));
        var_dump(filter_var("a1b2c3", FILTER_SANITIZE_NUMBER_INT));
        echo "\n";

        // ── SANITIZE_NUMBER_FLOAT ──
        echo "=== SANITIZE_NUMBER_FLOAT ===\n";
        var_dump(filter_var("3.14xyz", FILTER_SANITIZE_NUMBER_FLOAT));
        var_dump(filter_var("-0.5e+10", FILTER_SANITIZE_NUMBER_FLOAT));
        echo "\n";

        // ── SANITIZE_ADD_SLASHES ──
        echo "=== SANITIZE_ADD_SLASHES ===\n";
        var_dump(filter_var('a\'b"c\\d', FILTER_SANITIZE_ADD_SLASHES));
        echo "\n";

        // ── SANITIZE_FULL_SPECIAL_CHARS ──
        echo "=== SANITIZE_FULL_SPECIAL_CHARS ===\n";
        var_dump(filter_var('a<b>c&d"e', FILTER_SANITIZE_FULL_SPECIAL_CHARS));
        echo "\n";

        // ── INT_RANGE (min/max via options array) ──
        echo "=== INT_RANGE (min/max) ===\n";
        $opts = ["min_range" => 10, "max_range" => 100];
        var_dump(filter_var("50", FILTER_VALIDATE_INT, $opts));
        var_dump(filter_var("5", FILTER_VALIDATE_INT, $opts));
        var_dump(filter_var("500", FILTER_VALIDATE_INT, $opts));
        echo "\n";

        // ── FLAGS (OCTAL/HEX) ──
        echo "=== FLAGS (OCTAL/HEX) ===\n";
        var_dump(filter_var("077", FILTER_VALIDATE_INT, FILTER_FLAG_ALLOW_OCTAL));
        var_dump(filter_var("0xff", FILTER_VALIDATE_INT, FILTER_FLAG_ALLOW_HEX));
        echo "\n";

        // ── filter_list ──
        echo "=== filter_list ===\n";
        $list = filter_list();
        echo "filter_count=" . count($list) . "\n";
        echo "first=" . $list[0] . "\n";
        echo "\n";

        // ── filter_id ──
        echo "=== filter_id ===\n";
        echo "id_int=" . filter_id("int") . "\n";
        echo "id_email=" . filter_id("validate_email") . "\n";
        echo "id_unknown=" . filter_id("nonexistent") . "\n";
        echo "\n";

        echo "=== done ===\n";
    }
}
