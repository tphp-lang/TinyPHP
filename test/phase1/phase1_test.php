<?php
#debug ===== htmlspecialchars =====
#debug string(22) "a&lt;b&gt;&amp;&quot;c"
#debug string(6) "normal"
#debug string(0) ""
#debug
#debug ===== nl2br =====
#debug string(18) "a<br>
#debug b<br>
#debug <br>
#debug c"
#debug string(6) "normal"
#debug
#debug ===== base64 encode =====
#debug string(8) "SGVsbG8h"
#debug string(4) "YWI="
#debug string(16) "TG9yZW0gaXBzdW0="
#debug string(0) ""
#debug
#debug ===== base64 decode =====
#debug string(6) "Hello!"
#debug string(2) "ab"
#debug string(11) "Lorem ipsum"
#debug string(0) ""
#debug
#debug ===== base64 roundtrip =====
#debug ok
#debug ok
#debug
#debug === phase1 tests done ===

class Main
{
    public function main(): void
    {
        echo "===== htmlspecialchars =====\n";
        var_dump(htmlspecialchars('a<b>&"c'));
        var_dump(htmlspecialchars("normal"));
        var_dump(htmlspecialchars(""));

        echo "\n===== nl2br =====\n";
        var_dump(nl2br("a\nb\n\nc"));
        var_dump(nl2br("normal"));

        echo "\n===== base64 encode =====\n";
        var_dump(base64_encode("Hello!"));
        var_dump(base64_encode("ab"));
        var_dump(base64_encode("Lorem ipsum"));
        var_dump(base64_encode(""));

        echo "\n===== base64 decode =====\n";
        var_dump(base64_decode("SGVsbG8h"));
        var_dump(base64_decode("YWI="));
        var_dump(base64_decode("TG9yZW0gaXBzdW0="));
        var_dump(base64_decode(""));

        echo "\n===== base64 roundtrip =====\n";
        $orig = "hello world";
        $enc = base64_encode($orig);
        $dec = base64_decode($enc);
        if ($orig == $dec) {
            echo "ok\n";
        } else {
            echo "FAIL\n";
        }
        $enc2 = base64_encode($orig);
        $dec2 = base64_decode($enc2);
        if ($orig == $dec2) {
            echo "ok\n";
        } else {
            echo "FAIL\n";
        }

        echo "\n=== phase1 tests done ===\n";
    }
}
