<?php
// 对应 PHP ext/standard/tests/strings/str_contains.phpt
// 对应 PHP ext/standard/tests/strings/str_starts_with.phpt
// 对应 PHP ext/standard/tests/strings/str_ends_with.phpt
// 测试 str_contains / str_starts_with / str_ends_with（PHP 8.0+ 函数）
#debug bool(true)
#debug bool(true)
#debug bool(true)
#debug bool(true)
#debug bool(true)
#debug bool(false)
#debug bool(false)
#debug bool(true)
#debug bool(true)
#debug bool(false)
#debug bool(true)
#debug bool(false)
#debug bool(false)
#debug bool(true)
#debug bool(true)
#debug bool(true)
#debug bool(true)
#debug bool(false)
#debug bool(true)
#debug bool(true)
#debug bool(true)
#debug bool(false)

class Main
{
    public function main(): void
    {
        // str_contains（参考 str_contains.phpt）
        var_dump(str_contains("test string", "test"));
        var_dump(str_contains("test string", "string"));
        var_dump(str_contains("test string", "strin"));
        var_dump(str_contains("test string", "t s"));
        var_dump(str_contains("test string", "g"));
        var_dump(str_contains("tEst", "test"));
        var_dump(str_contains("teSt", "test"));
        var_dump(str_contains("", ""));
        var_dump(str_contains("a", ""));
        var_dump(str_contains("", "a"));

        // str_starts_with（参考 str_starts_with.phpt）
        var_dump(str_starts_with("beginningMiddleEnd", "beginning"));
        var_dump(str_starts_with("beginningMiddleEnd", "Beginning"));
        var_dump(str_starts_with("beginningMiddleEnd", "eginning"));
        var_dump(str_starts_with("beginningMiddleEnd", "beginningMiddleEnd"));
        var_dump(str_starts_with("beginningMiddleEnd", ""));
        var_dump(str_starts_with("", ""));

        // str_ends_with（参考 str_ends_with.phpt）
        var_dump(str_ends_with("beginningMiddleEnd", "End"));
        var_dump(str_ends_with("beginningMiddleEnd", "end"));
        var_dump(str_ends_with("beginningMiddleEnd", "MiddleEnd"));
        var_dump(str_ends_with("beginningMiddleEnd", ""));
        var_dump(str_ends_with("", ""));
        var_dump(str_ends_with("", "x"));
    }
}
