<?php
#debug int(4)
#debug int(5)
#debug string(5) "HELLO"
#debug string(5) "hello"
#debug int(10)
#debug string(3) "abc"
#debug int(10)
#debug string(5) "world"
#debug string(11) "bazfoo,bar,"
#debug int(100)

// 独立函数 — 用于 pipe 测试
function double(int $x): int {
    return $x * 2;
}

function shout(string $s): string {
    return strtoupper($s);
}

function prefix(string $s, string $p): string {
    return $p . $s;
}

function add(int $a, int $b): int {
    return $a + $b;
}

class Main {
    public function main(): void {
        // 基本管道: 2 |> double(...) → double(2) = 4
        var_dump(2 |> double(...));

        // 链式: 2 |> double(...) |> add(..., 1) → add(double(2), 1) = 5
        var_dump(2 |> double(...) |> add(..., 1));

        // 字符串管道: "hello" |> shout(...) → "HELLO"
        var_dump("hello" |> shout(...));

        // 链式: "hello" |> shout(...) |> strtolower(...) → "hello"
        var_dump("hello" |> shout(...) |> strtolower(...));

        // 数字管道: 5 |> double(...) → 10
        var_dump(5 |> double(...));

        // 占位符在末尾: "abc" |> str_repeat(..., 1) → "abc"
        var_dump("abc" |> str_repeat(..., 1));

        // 多级管道: "hello" |> shout → "HELLO" |> strlen → 5 |> double → 10
        var_dump("hello" |> shout(...) |> strlen(...) |> double(...));

        // 无占位符: "world" |> strtolower(...) → "world"
        var_dump("world" |> strtolower(...));

        // 占位符在中间: "baz" |> prefix("foo,bar,", ...) → prefix("foo,bar,", "baz") = "baz"."foo,bar," = "bazfoo,bar,"
        var_dump("baz" |> prefix("foo,bar,", ...));

        // 大数: 50 |> double(...) → 100
        var_dump(50 |> double(...));
    }
}
