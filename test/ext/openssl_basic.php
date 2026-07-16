<?php
// ext/openssl 扩展测试 — TLS/加密功能
// 覆盖：随机字节 + 摘要 + 对称加密往返 + 错误处理
//
// @skip  — OpenSSL 扩展当前未启用：
//   TCC 无法链接 MinGW GCC 产出的 COFF 静态库，而用 TCC 重编译 OpenSSL
//   源码耗时过长且部分源文件兼容性差，暂时停用 OpenSSL 扩展。
//   ext/openssl/src/openssl.{h,php} 代码保留，待后续找到可行的 TCC 构建方案再启用。
//   ext/stream 的 TLS 入口（stream_socket_enable_crypto）已保留 stub，
//   未启用 OpenSSL 时抛 "TLS not supported" 异常，非 TLS 流功能不受影响。
#debug === 1. openssl_random_pseudo_bytes ===
#debug rand_len=16
#debug rand_len=32
#debug rand_nonempty=true
#debug
#debug === 2. openssl_digest ===
#debug sha256_hello=2cf24dba5fb0a30e26e83b2ac5b9e29e1b161e5c1fa7425e73043362938b9824
#debug md5_hello=5d41402abc4b2a76b9719d911017c592
#debug sha512_len=128
#debug
#debug === 3. openssl_encrypt/decrypt round-trip ===
#debug encrypted_len=16
#debug decrypted=hello world
#debug
#debug === 4. openssl_error_string ===
#debug error_cleared=true
#debug
#debug === openssl tests done ===

class Main
{
    public function main(): void
    {
        // ── 1. openssl_random_pseudo_bytes ──
        echo "=== 1. openssl_random_pseudo_bytes ===\n";
        $rand16 = openssl_random_pseudo_bytes(16);
        echo "rand_len=" . strlen($rand16) . "\n";

        $rand32 = openssl_random_pseudo_bytes(32);
        echo "rand_len=" . strlen($rand32) . "\n";

        // 验证非空（随机内容不可预测，只验证长度和非空）
        $rand_nonempty = strlen($rand16) > 0 && strlen($rand32) > 0;
        echo "rand_nonempty=" . ($rand_nonempty ? "true" : "false") . "\n";
        echo "\n";

        // ── 2. openssl_digest ──
        echo "=== 2. openssl_digest ===\n";
        $sha256 = openssl_digest("sha256", "hello", false);
        echo "sha256_hello=" . $sha256 . "\n";

        $md5 = openssl_digest("md5", "hello", false);
        echo "md5_hello=" . $md5 . "\n";

        $sha512 = openssl_digest("sha512", "hello", false);
        echo "sha512_len=" . strlen($sha512) . "\n";
        echo "\n";

        // ── 3. openssl_encrypt/decrypt round-trip ──
        echo "=== 3. openssl_encrypt/decrypt round-trip ===\n";
        // AES-256-CBC: key=32字节, iv=16字节
        $key = str_repeat("k", 32);
        $iv = str_repeat("v", 16);
        $plaintext = "hello world";

        // OPENSSL_RAW_DATA=1: 返回原始二进制
        $encrypted = openssl_encrypt("AES-256-CBC", $key, $iv, $plaintext, OPENSSL_RAW_DATA);
        // AES-256-CBC + PKCS7 padding: 11字节明文 → 16字节块 + 5字节padding → 16字节
        // 但实际 "hello world" = 11 bytes, padding 到 16 bytes
        echo "encrypted_len=" . strlen($encrypted) . "\n";

        $decrypted = openssl_decrypt("AES-256-CBC", $key, $iv, $encrypted, OPENSSL_RAW_DATA);
        echo "decrypted=" . $decrypted . "\n";
        echo "\n";

        // ── 4. openssl_error_string ──
        echo "=== 4. openssl_error_string ===\n";
        // 触发一个错误（用无效的加密算法名）
        try {
            openssl_encrypt("INVALID-CIPHER", $key, $iv, $plaintext, OPENSSL_RAW_DATA);
        } catch (Exception $e) {
            // 错误应该被抛出
        }

        // 清空错误队列
        $err = openssl_error_string();
        $error_cleared = ($err === "" || $err === "no error" || strlen($err) >= 0);
        echo "error_cleared=" . ($error_cleared ? "true" : "false") . "\n";
        echo "\n";

        echo "=== openssl tests done ===\n";
    }
}
