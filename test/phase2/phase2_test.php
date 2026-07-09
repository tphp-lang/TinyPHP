<?php
#debug ===== 1. sha256 =====
#debug string(64) "e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855"
#debug string(64) "ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad"
#debug string(64) "b94d27b9934d3e08a52e52d7da7dabfac484efe37a5380ee9088f7ace2efcde9"
#debug string(64) "e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855"
#debug string(64) "e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855"
#debug int(64)
#debug
#debug ===== 2. sha512 =====
#debug string(128) "cf83e1357eefb8bdf1542850d66d8007d620e4050b5715dc83f4a921d36ce9ce47d0d13c5d85f2b0ff8318d2877eec2f63b931bd47417a81a538327af927da3e"
#debug string(128) "ddaf35a193617abacc417349ae20413112e6fa4e89a97ea20a9eeee64b55d39a2192992a274fc1a836ba3c23a3feebbd454d4423643ce80e2a9ac94fa54ca49f"
#debug int(128)
#debug
#debug ===== 3. base_convert =====
#debug string(3) "101"
#debug string(1) "f"
#debug string(4) "-101"
#debug string(1) "0"
#debug string(4) "zzzz"
#debug string(0) ""
#debug string(0) ""
#debug
#debug ===== 4. array_chunk =====
#debug int(3)
#debug int(0)
#debug int(1)
#debug
#debug ===== 5. array_combine =====
#debug int(3)
#debug int(0)
#debug
#debug ===== 6. array_count_values =====
#debug int(3)
#debug
#debug ===== 7. mb_strlen =====
#debug int(0)
#debug int(5)
#debug int(2)
#debug int(5)
#debug int(12)
#debug int(5)
#debug
#debug ===== 8. mb_substr =====
#debug string(2) "cd"
#debug string(3) "abc"
#debug string(4) "cdef"
#debug string(3) "fgh"
#debug
#debug === phase2 tests done ===

class Main
{
    public function main(): void
    {
        echo "===== 1. sha256 =====\n";
        $s0 = sha256("");
        var_dump($s0);
        var_dump(sha256("abc"));
        var_dump(sha256("hello world"));
        var_dump(sha256(""));
        var_dump(sha256(""));
        var_dump(strlen($s0));

        echo "\n===== 2. sha512 =====\n";
        var_dump(sha512(""));
        var_dump(sha512("abc"));
        var_dump(strlen(sha512("")));

        echo "\n===== 3. base_convert =====\n";
        var_dump(base_convert("5", 10, 2));
        var_dump(base_convert("15", 10, 16));
        var_dump(base_convert("-5", 10, 2));
        var_dump(base_convert("0", 10, 2));
        var_dump(base_convert("1679615", 10, 36));
        var_dump(base_convert("5", 1, 10));
        var_dump(base_convert("5", 10, 37));

        echo "\n===== 4. array_chunk =====\n";
        $c1 = array_chunk([1,2,3,4,5], 2);
        var_dump(count($c1));
        var_dump(count(array_chunk([], 2)));
        var_dump(count(array_chunk([1], 5)));

        echo "\n===== 5. array_combine =====\n";
        $r1 = array_combine(["a","b","c"], [1,2,3]);
        var_dump(count($r1));
        $r2 = array_combine(["a","b"], [1,2,3]);
        var_dump(count($r2));

        echo "\n===== 6. array_count_values =====\n";
        $cv = array_count_values(["a","b","a","c","a"]);
        var_dump(count($cv));

        echo "\n===== 7. mb_strlen =====\n";
        var_dump(mb_strlen(""));
        var_dump(mb_strlen("hello"));
        var_dump(mb_strlen("世界"));
        var_dump(mb_strlen("a世b界c"));
        var_dump(mb_strlen("Hello World!"));
        var_dump(mb_strlen("hello"));

        echo "\n===== 8. mb_substr =====\n";
        var_dump(mb_substr("abcdef", 2, 2));
        var_dump(mb_substr("abcdef", 0, 3));
        var_dump(mb_substr("abcdef", 2, 10));
        var_dump(mb_substr("abcdefgh", -3, 10));

        echo "\n=== phase2 tests done ===\n";
    }
}
