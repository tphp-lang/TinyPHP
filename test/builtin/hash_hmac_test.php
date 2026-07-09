<?php

#debug === HMAC Test (RFC 4231) ===
#debug
#debug [TC2 sha256] 5bdcc146bf60754e6a042426089575c75a003f089d2739839dec58b964ec3843
#debug [TC2 sha512] 164b7a7bfcf819e2e395fbe73b56e0a387bd64222e831fd610270cd7ea2505549758bf75c05a994a6d034f65f8f0e6fdcaeab1a34d4a6b4b636e070a38bce737
#debug [binary sha256 len] 32
#debug [binary sha512 len] 64
#debug [unsupported algo] 0
#debug
#debug === All HMAC tests done ===

class Main {
    public function main(): void {
        echo "=== HMAC Test (RFC 4231) ===\n\n";

        // RFC 4231 Test Case 2: key="Jefe", data="what do ya want for nothing?"
        string $key = "Jefe";
        string $data = "what do ya want for nothing?";

        string $h256 = hash_hmac("sha256", $data, $key);
        echo "[TC2 sha256] " . $h256 . "\n";

        string $h512 = hash_hmac("sha512", $data, $key);
        echo "[TC2 sha512] " . $h512 . "\n";

        // Binary mode: raw digest bytes
        string $bin256 = hash_hmac("sha256", $data, $key, true);
        echo "[binary sha256 len] " . strlen($bin256) . "\n";

        string $bin512 = hash_hmac("sha512", $data, $key, true);
        echo "[binary sha512 len] " . strlen($bin512) . "\n";

        // Unsupported algorithm returns empty string
        string $bad = hash_hmac("md5", $data, $key);
        echo "[unsupported algo] " . strlen($bad) . "\n";

        echo "\n=== All HMAC tests done ===\n";
    }
}
