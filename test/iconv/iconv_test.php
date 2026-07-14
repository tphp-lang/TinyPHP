<?php
// iconv 内置库测试 — 覆盖 8 个函数 + 2 个常量
// 失败路径改用 tp_throw (单返回类型)，与 AOT 契约一致
#debug === ICONV_CONST ===
#debug string(5) "iconv"
#debug string(3) "1.0"
#debug
#debug === ICONV_STRLEN ===
#debug int(5)
#debug int(5)
#debug int(11)
#debug
#debug === ICONV_STRPOS ===
#debug int(6)
#debug int(2)
#debug int(-1)
#debug
#debug === ICONV_SUBSTR ===
#debug string(5) "hello"
#debug string(4) "hél"
#debug string(5) "world"
#debug
#debug === ICONV_CONVERT ===
#debug string(5) "Hello"
#debug int(4)
#debug string(5) "café"
#debug
#debug === ICONV_GET_ENCODING ===
#debug enc_count=3
#debug input=UTF-8
#debug output=UTF-8
#debug internal=UTF-8
#debug
#debug === ICONV_SET_ENCODING ===
#debug bool(true)
#debug internal=ISO-8859-1
#debug
#debug === ICONV_MIME_ENCODE ===
#debug string(29) "Subject: =?UTF-8?B?SGVsbG8=?="
#debug
#debug === ICONV_MIME_DECODE ===
#debug string(14) "Subject: Hello"
#debug
#debug === done ===

class Main {
    public function main(): void {
        // ── 常量 ──
        echo "=== ICONV_CONST ===\n";
        var_dump(ICONV_IMPL);
        var_dump(ICONV_VERSION);
        echo "\n";

        // ── iconv_strlen ──
        echo "=== ICONV_STRLEN ===\n";
        var_dump(iconv_strlen("Hello", "UTF-8"));
        var_dump(iconv_strlen("héllo", "UTF-8"));        // é 为单字符 → 5
        var_dump(iconv_strlen("héllo world", "UTF-8"));  // 11 字符
        echo "\n";

        // ── iconv_strpos ──
        echo "=== ICONV_STRPOS ===\n";
        var_dump(iconv_strpos("hello world", "world", 0, "UTF-8"));  // 6
        var_dump(iconv_strpos("héllo", "l", 0, "UTF-8"));            // 2 (h-é-l)
        var_dump(iconv_strpos("hello", "z", 0, "UTF-8"));            // -1 未找到
        echo "\n";

        // ── iconv_substr ──
        echo "=== ICONV_SUBSTR ===\n";
        var_dump(iconv_substr("hello world", 0, 5, "UTF-8"));   // "hello"
        var_dump(iconv_substr("héllo", 0, 3, "UTF-8"));         // "hél"
        var_dump(iconv_substr("hello world", 6, 0, "UTF-8"));   // length=0 → 到末尾 "world"
        echo "\n";

        // ── iconv 编码转换 ──
        echo "=== ICONV_CONVERT ===\n";
        // UTF-8 → ISO-8859-1 (ASCII 子集不变)
        var_dump(iconv("UTF-8", "ISO-8859-1", "Hello"));
        // round-trip: café (UTF-8, 5 字节) → ISO-8859-1 (4 字节) → UTF-8 (5 字节, "café")
        $latin1 = iconv("UTF-8", "ISO-8859-1", "café");
        var_dump(iconv_strlen($latin1, "ISO-8859-1"));   // 单字节编码 → 4
        $back = iconv("ISO-8859-1", "UTF-8", $latin1);
        var_dump($back);                                   // "café"
        echo "\n";

        // ── iconv_get_encoding ──
        echo "=== ICONV_GET_ENCODING ===\n";
        $enc = iconv_get_encoding();
        echo "enc_count=" . count($enc) . "\n";
        echo "input=" . $enc["input_encoding"] . "\n";
        echo "output=" . $enc["output_encoding"] . "\n";
        echo "internal=" . $enc["internal_encoding"] . "\n";
        echo "\n";

        // ── iconv_set_encoding ──
        echo "=== ICONV_SET_ENCODING ===\n";
        var_dump(iconv_set_encoding("internal_encoding", "ISO-8859-1"));
        $enc2 = iconv_get_encoding();
        echo "internal=" . $enc2["internal_encoding"] . "\n";
        // 还原
        iconv_set_encoding("internal_encoding", "UTF-8");
        echo "\n";

        // ── iconv_mime_encode ──
        echo "=== ICONV_MIME_ENCODE ===\n";
        // base64("Hello") = "SGVsbG8="
        var_dump(iconv_mime_encode("Subject", "Hello"));
        echo "\n";

        // ── iconv_mime_decode ──
        echo "=== ICONV_MIME_DECODE ===\n";
        var_dump(iconv_mime_decode("Subject: =?UTF-8?B?SGVsbG8=?="));
        echo "\n";

        echo "=== done ===\n";
    }
}
