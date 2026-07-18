<?php
#debug -- 1. ucfirst/lcfirst --
#debug ucfirst=string(5) "Hello"
#debug lcfirst=string(5) "hello"
#debug -- 2. strrev/repeat --
#debug rev=string(3) "cba"
#debug repeat=string(6) "ababab"
#debug -- 3. str_split --
#debug split[0]=int(0)
#debug cnt=3
#debug -- 4. str_pad --
#debug padR=string(5) "ab---"
#debug padL=string(5) "---ab"
#debug -- 5. sub count/shuffle --
#debug cnt(ab)=3
#debug shuf len=3
#debug -- 6. slashes --
#debug add=string(3) "abc"
#debug strip=string(3) "abc"
#debug -- 7. hex --
#debug b2h=string(4) "4142"
#debug h2b=string(2) "AB"
#debug -- 8. url encode/decode --
#debug enc=string(13) "hello%20world"
#debug dec=string(11) "hello world"
#debug -- 9. array_chunk --
#debug chunks=3
#debug -- 10. array combine/flip/column --
#debug comb cnt=2
#debug flip cnt=2
#debug col cnt=2
#debug
#debug === Tier2 OK ===

class Main {
    public function main(): void {
        // ── 1. ucfirst / lcfirst ──
        echo "-- 1. ucfirst/lcfirst --\n";
        echo "ucfirst="; var_dump(ucfirst("hello"));
        echo "lcfirst="; var_dump(lcfirst("Hello"));

        // ── 2. strrev / str_repeat ──
        echo "-- 2. strrev/repeat --\n";
        echo "rev="; var_dump(strrev("abc"));
        echo "repeat="; var_dump(str_repeat("ab", 3));

        // ── 3. str_split ──
        echo "-- 3. str_split --\n";
        $sp = str_split("abc");
        echo "split[0]="; var_dump($sp[0]);
        echo "cnt=" . count($sp) . "\n";

        // ── 4. str_pad ──
        echo "-- 4. str_pad --\n";
        echo "padR="; var_dump(str_pad("ab", 5, "-", 0));
        echo "padL="; var_dump(str_pad("ab", 5, "-", 1));

        // ── 5. substr_count / str_shuffle ──
        echo "-- 5. sub count/shuffle --\n";
        echo "cnt(ab)=" . substr_count("ababab", "ab") . "\n";
        echo "shuf len=" . strlen(str_shuffle("abc")) . "\n";

        // ── 6. addslashes / stripslashes ──
        echo "-- 6. slashes --\n";
        echo "add="; var_dump(addslashes("abc"));
        echo "strip="; var_dump(stripslashes("abc"));

        // ── 7. bin2hex / hex2bin ──
        echo "-- 7. hex --\n";
        echo "b2h="; var_dump(bin2hex("AB"));
        echo "h2b="; var_dump(hex2bin("4142"));

        // ── 8. urlencode / urldecode ──
        echo "-- 8. url encode/decode --\n";
        echo "enc="; var_dump(urlencode("hello world"));
        echo "dec="; var_dump(urldecode("hello%20world"));

        // ── 9. array_chunk ──
        echo "-- 9. array_chunk --\n";
        echo "chunks=" . count(array_chunk([1,2,3,4,5], 2)) . "\n";

        // ── 10. array_combine / flip / column ──
        echo "-- 10. array combine/flip/column --\n";
        $cb = array_combine(["a","b"], [1,2]);
        echo "comb cnt=" . count($cb) . "\n";
        $af = array_flip([0=>100, 1=>200]);
        echo "flip cnt=" . count($af) . "\n";
        $rows = [["id"=>1, "name"=>"A"], ["id"=>2, "name"=>"B"]];
        echo "col cnt=" . count(array_column($rows, "name")) . "\n";

        echo "\n=== Tier2 OK ===\n";
    }
}
