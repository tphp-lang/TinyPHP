<?php
#debug ===== ctype_alnum =====
#debug bool(true)
#debug bool(false)
#debug bool(false)
#debug ===== ctype_alpha =====
#debug bool(true)
#debug bool(false)
#debug ===== ctype_digit =====
#debug bool(true)
#debug bool(false)
#debug ===== ctype_lower =====
#debug bool(true)
#debug bool(false)
#debug ===== ctype_upper =====
#debug bool(true)
#debug bool(false)
#debug ===== ctype_space =====
#debug bool(true)
#debug bool(false)
#debug ===== ctype_xdigit =====
#debug bool(true)
#debug bool(false)
#debug ===== ctype_punct =====
#debug bool(true)
#debug bool(false)
#debug ===== ctype_cntrl =====
#debug bool(true)
#debug bool(false)
#debug ===== ctype_graph =====
#debug bool(true)
#debug bool(false)
#debug ===== ctype_print =====
#debug bool(true)
#debug bool(false)
#debug ===== empty string =====
#debug bool(false)
#debug bool(false)

class Main {
    public function main(): void {
        echo "===== ctype_alnum =====\n";
        var_dump(ctype_alnum("abc123"));
        var_dump(ctype_alnum("abc 123"));
        var_dump(ctype_alnum(""));

        echo "===== ctype_alpha =====\n";
        var_dump(ctype_alpha("abcXYZ"));
        var_dump(ctype_alpha("abc123"));

        echo "===== ctype_digit =====\n";
        var_dump(ctype_digit("12345"));
        var_dump(ctype_digit("12.34"));

        echo "===== ctype_lower =====\n";
        var_dump(ctype_lower("hello"));
        var_dump(ctype_lower("Hello"));

        echo "===== ctype_upper =====\n";
        var_dump(ctype_upper("WORLD"));
        var_dump(ctype_upper("World"));

        echo "===== ctype_space =====\n";
        var_dump(ctype_space(" \t\n\r"));
        var_dump(ctype_space("a "));

        echo "===== ctype_xdigit =====\n";
        var_dump(ctype_xdigit("abcdef0123456789"));
        var_dump(ctype_xdigit("abcXYZ"));

        echo "===== ctype_punct =====\n";
        var_dump(ctype_punct('!@#$'));
        var_dump(ctype_punct("hello"));

        echo "===== ctype_cntrl =====\n";
        var_dump(ctype_cntrl("\n\r\t"));
        var_dump(ctype_cntrl("abc"));

        echo "===== ctype_graph =====\n";
        var_dump(ctype_graph("abc123!"));
        var_dump(ctype_graph("abc 123"));

        echo "===== ctype_print =====\n";
        var_dump(ctype_print("hello world"));
        var_dump(ctype_print("a\nb"));

        echo "===== empty string =====\n";
        var_dump(ctype_alnum(""));
        var_dump(ctype_alpha(""));
    }
}
