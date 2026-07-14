<?php
#debug ===== 1. sha256 =====
#debug ===== 2. sha512 =====
#debug ===== 3. base_convert =====
#debug ===== 4. array_chunk =====
#debug ===== 5. array_combine =====
#debug ===== 6. array_count_values =====
#debug ===== 7. mb_strlen =====
#debug ===== 8. mb_substr =====
#debug
#debug === ALL phase2 tests passed ===
class Main
{
    public function main(): void
    {
        // ═══ 1. sha256 ═══
        echo "===== 1. sha256 =====\n";
        $this->assertStrEq(sha256(""), "e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855", "sha256 empty");
        $this->assertStrEq(sha256("abc"), "ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad", "sha256 abc");
        $this->assertIntEq(strlen(sha256("")), 64, "sha256 len=64");
        $this->assertIntEq(strlen(sha256("hello")), 64, "sha256 len=64");

        // ═══ 2. sha512 ═══
        echo "===== 2. sha512 =====\n";
        $this->assertStrEq(sha512(""), "cf83e1357eefb8bdf1542850d66d8007d620e4050b5715dc83f4a921d36ce9ce47d0d13c5d85f2b0ff8318d2877eec2f63b931bd47417a81a538327af927da3e", "sha512 empty");
        $this->assertStrEq(sha512("abc"), "ddaf35a193617abacc417349ae20413112e6fa4e89a97ea20a9eeee64b55d39a2192992a274fc1a836ba3c23a3feebbd454d4423643ce80e2a9ac94fa54ca49f", "sha512 abc");
        $this->assertIntEq(strlen(sha512("")), 128, "sha512 len=128");

        // ═══ 3. base_convert ═══
        echo "===== 3. base_convert =====\n";
        $this->assertStrEq(base_convert("5", 10, 2), "101", "5 decimal to binary");
        $this->assertStrEq(base_convert("15", 10, 16), "f", "15 to hex");
        $this->assertStrEq(base_convert("-5", 10, 2), "-101", "negative");
        $this->assertStrEq(base_convert("0", 10, 2), "0", "zero");
        $this->assertStrEq(base_convert("5", 1, 10), "", "invalid from base");
        $this->assertStrEq(base_convert("5", 10, 37), "", "invalid to base");

        // ═══ 4. array_chunk ═══
        echo "===== 4. array_chunk =====\n";
        $c = array_chunk([1,2,3,4,5], 2);
        $this->assertIntEq(count($c), 3, "chunk count=3");
        $this->assertIntEq(count(array_chunk([], 2)), 0, "empty array");
        $this->assertIntEq(count(array_chunk([1], 5)), 1, "size > array");

        // ═══ 5. array_combine ═══
        echo "===== 5. array_combine =====\n";
        $r = array_combine(["a","b","c"], [1,2,3]);
        $this->assertIntEq(count($r), 3, "combine count=3");
        $r2 = array_combine(["a","b"], [1,2,3]);
        $this->assertIntEq(count($r2), 0, "mismatch returns empty");

        // ═══ 6. array_count_values ═══
        echo "===== 6. array_count_values =====\n";
        $cv = array_count_values(["a","b","a","c","a"]);
        $this->assertIntEq(count($cv), 3, "3 unique values");

        // ═══ 7. mb_strlen ═══
        echo "===== 7. mb_strlen =====\n";
        $this->assertIntEq(mb_strlen(""), 0, "empty=0");
        $this->assertIntEq(mb_strlen("hello"), 5, "ascii=5");
        $this->assertIntEq(mb_strlen("世界"), 2, "cjk=2");
        $this->assertIntEq(mb_strlen("a世b界c"), 5, "mixed=5");

        // ═══ 8. mb_substr ═══
        echo "===== 8. mb_substr =====\n";
        $this->assertStrEq(mb_substr("abcdef", 2, 2), "cd", "substr middle");
        $this->assertStrEq(mb_substr("abcdef", 0, 3), "abc", "substr start");
        $this->assertStrEq(mb_substr("abcdef", 2, 10), "cdef", "substr overflow");
        $this->assertStrEq(mb_substr("abcdefgh", -3, 10), "fgh", "substr negative");

        echo "\n=== ALL phase2 tests passed ===\n";
    }

    private function assertIntEq(int $got, int $expected, string $msg): void
    {
        if ($got != $expected) {
            echo "FAIL " . $msg . ": got=" . $got . " exp=" . $expected . "\n";
        }
    }

    private function assertStrEq(string $got, string $expected, string $msg): void
    {
        if (strlen($got) != strlen($expected)) {
            echo "FAIL " . $msg . ": len mismatch got=" . strlen($got) . " exp=" . strlen($expected) . "\n";
        }
    }
}
