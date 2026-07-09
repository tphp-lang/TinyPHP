<?php
#debug -- 1. Math --
#debug pi=float(3.1415926535898)
#debug deg2rad=float(3.1415926535898)
#debug rad2deg=float(180)
#debug intdiv=int(3)
#debug pow=int(8)
#debug -- 2. Ord/Chr --
#debug ord(A)=int(65)
#debug chr(65)=string(1) "A"
#debug -- 3. String checks --
#debug start=bool(true)
#debug end=bool(true)
#debug -- 4. is_numeric --
#debug num(42)=bool(true)
#debug num(hi)=bool(false)
#debug -- 5. gettype --
#debug t(42)=string(3) "int"
#debug -- 6. Base --
#debug bindec(1010)=int(10)
#debug octdec(17)=int(15)
#debug hexdec(ff)=int(255)
#debug decbin(10)=string(4) "1010"
#debug decoct(15)=string(2) "17"
#debug dechex(255)=string(2) "ff"
#debug -- 7. number_format --
#debug fmt(1234567)=string(9) "1,234,567"
#debug fmt(1234.5678,2)=string(8) "1,234.57"
#debug -- 8. Array pointer --
#debug cur=int(10)
#debug key=int(0)
#debug next=int(20)
#debug prev=int(10)
#debug end=int(50)
#debug reset=int(10)
#debug -- 9. Array keys --
#debug kf=0 kl=4
#debug list=bool(true)
#debug rand>=bool(true)
#debug -- 10. getenv/putenv --
#debug ~ getenv PATH len=690
#debug putenv ok
#debug -- 11. Time --
#debug ~ mktime=1782561600
#debug ~ strtotime=1704067200
#debug uniqid len=13
#debug
#debug === Tier1 OK ===

class Main {
    public function main(): void {
        // ── 1. pi / deg2rad / rad2deg / intdiv / pow ──
        echo "-- 1. Math --\n";
        echo "pi="; var_dump(pi());
        echo "deg2rad="; var_dump(deg2rad(180.0));
        echo "rad2deg="; var_dump(rad2deg(3.141592653589793));
        echo "intdiv="; var_dump(intdiv(10, 3));
        echo "pow="; var_dump(pow(2, 3));

        // ── 2. ord / chr ──
        echo "-- 2. Ord/Chr --\n";
        echo "ord(A)="; var_dump(ord("A"));
        echo "chr(65)="; var_dump(chr(65));

        // ── 3. str_starts_with / str_ends_with ──
        echo "-- 3. String checks --\n";
        echo "start="; var_dump(str_starts_with("hello world", "hello"));
        echo "end="; var_dump(str_ends_with("hello world", "world"));

        // ── 4. is_numeric ──
        echo "-- 4. is_numeric --\n";
        echo "num(42)="; var_dump(is_numeric("42"));
        echo "num(hi)="; var_dump(is_numeric("hello"));

        // ── 5. gettype ──
        echo "-- 5. gettype --\n";
        echo "t(42)="; var_dump(gettype(42));

        // ── 6. 进制转换 ──
        echo "-- 6. Base --\n";
        echo "bindec(1010)="; var_dump(bindec("1010"));
        echo "octdec(17)="; var_dump(octdec("17"));
        echo "hexdec(ff)="; var_dump(hexdec("ff"));
        echo "decbin(10)="; var_dump(decbin(10));
        echo "decoct(15)="; var_dump(decoct(15));
        echo "dechex(255)="; var_dump(dechex(255));

        // ── 7. number_format ──
        echo "-- 7. number_format --\n";
        echo "fmt(1234567)="; var_dump(number_format(1234567));
        echo "fmt(1234.5678,2)="; var_dump(number_format(1234.5678, 2));

        // ── 8. 数组指针 ──
        echo "-- 8. Array pointer --\n";
        $arr = [10, 20, 30, 40, 50];
        echo "cur="; var_dump(current($arr));
        echo "key="; var_dump(key($arr));
        echo "next="; var_dump(next($arr));
        echo "prev="; var_dump(prev($arr));
        echo "end="; var_dump(end($arr));
        echo "reset="; var_dump(reset($arr));

        // ── 9. array_key_first/last / is_list / rand ──
        echo "-- 9. Array keys --\n";
        echo "kf=" . array_key_first($arr) . " kl=" . array_key_last($arr) . "\n";
        echo "list="; var_dump(array_is_list([1,2,3]));
        echo "rand>="; var_dump(array_rand([100,200,300]) >= 0);

        // ── 10. env ──
        echo "-- 10. getenv/putenv --\n";
        echo "getenv PATH len=" . strlen(getenv("PATH")) . "\n";
        putenv("TPHP_TEST=1");
        echo "putenv ok\n";

        // ── 11. 时间 ──
        echo "-- 11. Time --\n";
        echo "mktime=" . mktime(12, 0, 0, 6, 27, 2026) . "\n";
        echo "strtotime=" . strtotime("2024-01-01") . "\n";
        echo "uniqid len=" . strlen(uniqid()) . "\n";

        echo "\n=== Tier1 OK ===\n";
    }
}
