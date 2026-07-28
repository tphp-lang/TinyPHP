<?php
// ext/curl 扩展存根拒绝测试 — 纯 phpc 实现（无需网络连接）
//
// 测试范围：
//   1. curl_multi_* 存根函数（init/close/errno/strerror/get_handles/getcontent 不抛异常；
//      add_handle/remove_handle/exec/select/info_read/setopt 抛 Exception）
//   2. curl_share_* 存根函数（init/close/errno/strerror 不抛异常；
//      setopt/init_persistent 抛 Exception）
//   3. 不支持协议测试（ftp/smtp/telnet/file → curl_exec 返回 false + errno=1）
//   4. 不支持认证测试（CURLAUTH_DIGEST → curl_exec 返回 false + errorMsg 非空）
//   5. 不支持代理类型测试（CURLPROXY_SOCKS5 → curl_exec 返回 false + errorMsg 非空）
//
// 设计说明：
//   - 协议/认证/代理拒绝均在 curl_exec 网络连接之前检查，因此无需网络
//   - 遵循"不静默"原则：不支持的功能必须返回 false 或抛 Exception，不静默成功
#import stream
#import openssl
#import curl

class Main
{
    public function main(): void
    {
        echo "=== cURL Stub & Reject Test (no network) ===\n\n";

        // ════════════════════════════════════════════════════════════
        // 1. curl_multi_* 存根测试
        // ════════════════════════════════════════════════════════════
        echo "-- 1. curl_multi_* stubs --\n";

        // 1a. 不抛异常的函数
        $mh = curl_multi_init();
        echo "1. multi_init returns CurlMultiHandle: " . ($mh instanceof CurlMultiHandle ? "PASS" : "FAIL") . "\n";

        $multiClosed = false;
        try {
            curl_multi_close($mh);
            $multiClosed = true;
        } catch (Exception $e) {
            $multiClosed = false;
        }
        echo "2. multi_close no exception: " . ($multiClosed ? "PASS" : "FAIL") . "\n";

        echo "3. multi_errno returns 0: " . (curl_multi_errno($mh) === 0 ? "PASS" : "FAIL") . "\n";

        echo "4. multi_strerror(0) == 'No error': " . (curl_multi_strerror(0) === "No error" ? "PASS" : "FAIL") . "\n";
        echo "5. multi_strerror(7) == 'Could not connect': " . (curl_multi_strerror(7) === "Could not connect" ? "PASS" : "FAIL") . "\n";

        $handles = curl_multi_get_handles($mh);
        echo "6. multi_get_handles returns array: " . (is_array($handles) ? "PASS" : "FAIL") . "\n";
        echo "7. multi_get_handles empty: " . (count($handles) === 0 ? "PASS" : "FAIL") . "\n";

        // multi_getcontent 返回 handle 的 lastResponse
        $ch = curl_init("http://example.com");
        $ch->lastResponse = "test response content";
        echo "8. multi_getcontent returns lastResponse: " . (curl_multi_getcontent($ch) === "test response content" ? "PASS" : "FAIL") . "\n";

        // 1b. 抛 Exception 的函数
        $caught = false;
        try {
            curl_multi_add_handle($mh, $ch);
        } catch (Exception $e) {
            $caught = true;
        }
        echo "9. multi_add_handle throws: " . ($caught ? "PASS" : "FAIL") . "\n";

        $caught = false;
        try {
            curl_multi_remove_handle($mh, $ch);
        } catch (Exception $e) {
            $caught = true;
        }
        echo "10. multi_remove_handle throws: " . ($caught ? "PASS" : "FAIL") . "\n";

        $caught = false;
        $stillRunning = 0;
        try {
            curl_multi_exec($mh, $stillRunning);
        } catch (Exception $e) {
            $caught = true;
        }
        echo "11. multi_exec throws: " . ($caught ? "PASS" : "FAIL") . "\n";

        $caught = false;
        try {
            curl_multi_select($mh);
        } catch (Exception $e) {
            $caught = true;
        }
        echo "12. multi_select throws: " . ($caught ? "PASS" : "FAIL") . "\n";

        $caught = false;
        $queuedMsgs = 0;
        try {
            curl_multi_info_read($mh, $queuedMsgs);
        } catch (Exception $e) {
            $caught = true;
        }
        echo "13. multi_info_read throws: " . ($caught ? "PASS" : "FAIL") . "\n";

        $caught = false;
        try {
            curl_multi_setopt($mh, 1, 1);
        } catch (Exception $e) {
            $caught = true;
        }
        echo "14. multi_setopt throws: " . ($caught ? "PASS" : "FAIL") . "\n";

        // ════════════════════════════════════════════════════════════
        // 2. curl_share_* 存根测试
        // ════════════════════════════════════════════════════════════
        echo "\n-- 2. curl_share_* stubs --\n";

        // 2a. 不抛异常的函数
        $sh = curl_share_init();
        echo "15. share_init returns CurlShareHandle: " . ($sh instanceof CurlShareHandle ? "PASS" : "FAIL") . "\n";

        $shareClosed = false;
        try {
            curl_share_close($sh);
            $shareClosed = true;
        } catch (Exception $e) {
            $shareClosed = false;
        }
        echo "16. share_close no exception: " . ($shareClosed ? "PASS" : "FAIL") . "\n";

        echo "17. share_errno returns 0: " . (curl_share_errno($sh) === 0 ? "PASS" : "FAIL") . "\n";

        echo "18. share_strerror(0) == 'No error': " . (curl_share_strerror(0) === "No error" ? "PASS" : "FAIL") . "\n";

        // 2b. 抛 Exception 的函数
        $caught = false;
        try {
            curl_share_setopt($sh, 1, 1);
        } catch (Exception $e) {
            $caught = true;
        }
        echo "19. share_setopt throws: " . ($caught ? "PASS" : "FAIL") . "\n";

        $caught = false;
        try {
            curl_share_init_persistent([]);
        } catch (Exception $e) {
            $caught = true;
        }
        echo "20. share_init_persistent throws: " . ($caught ? "PASS" : "FAIL") . "\n";

        // ════════════════════════════════════════════════════════════
        // 3. 不支持协议测试（不静默原则）
        // ════════════════════════════════════════════════════════════
        echo "\n-- 3. Unsupported protocol rejection --\n";

        // ftp://
        $chftp = curl_init("ftp://example.com");
        curl_setopt($chftp, CURLOPT_RETURNTRANSFER, true);
        $retFtp = curl_exec($chftp);
        $errFtp = curl_error($chftp);
        $errnoFtp = curl_errno($chftp);
        echo "21. ftp exec returns false: " . ($retFtp === false ? "PASS" : "FAIL") . "\n";
        echo "22. ftp errno == 1 (UNSUPPORTED_PROTOCOL): " . ($errnoFtp === 1 ? "PASS" : "FAIL") . "\n";
        echo "23. ftp errorMsg non-empty: " . (strlen($errFtp) > 0 ? "PASS" : "FAIL") . "\n";
        echo "24. ftp msg has 'Unsupported protocol': " . (strpos($errFtp, "Unsupported protocol") !== false ? "PASS" : "FAIL") . "\n";
        echo "25. ftp msg has 'Only http/https': " . (strpos($errFtp, "Only http/https are supported") !== false ? "PASS" : "FAIL") . "\n";

        // smtp://
        $chsmtp = curl_init("smtp://example.com");
        curl_setopt($chsmtp, CURLOPT_RETURNTRANSFER, true);
        $retSmtp = curl_exec($chsmtp);
        $errSmtp = curl_error($chsmtp);
        $errnoSmtp = curl_errno($chsmtp);
        echo "26. smtp exec returns false: " . ($retSmtp === false ? "PASS" : "FAIL") . "\n";
        echo "27. smtp errno == 1: " . ($errnoSmtp === 1 ? "PASS" : "FAIL") . "\n";
        echo "28. smtp errorMsg non-empty: " . (strlen($errSmtp) > 0 ? "PASS" : "FAIL") . "\n";

        // telnet://
        $chtelnet = curl_init("telnet://example.com");
        curl_setopt($chtelnet, CURLOPT_RETURNTRANSFER, true);
        $retTelnet = curl_exec($chtelnet);
        $errTelnet = curl_error($chtelnet);
        $errnoTelnet = curl_errno($chtelnet);
        echo "29. telnet exec returns false: " . ($retTelnet === false ? "PASS" : "FAIL") . "\n";
        echo "30. telnet errno == 1: " . ($errnoTelnet === 1 ? "PASS" : "FAIL") . "\n";
        echo "31. telnet errorMsg non-empty: " . (strlen($errTelnet) > 0 ? "PASS" : "FAIL") . "\n";

        // file://
        $chfile = curl_init("file:///etc/passwd");
        curl_setopt($chfile, CURLOPT_RETURNTRANSFER, true);
        $retFile = curl_exec($chfile);
        $errFile = curl_error($chfile);
        $errnoFile = curl_errno($chfile);
        echo "32. file exec returns false: " . ($retFile === false ? "PASS" : "FAIL") . "\n";
        echo "33. file errno == 1: " . ($errnoFile === 1 ? "PASS" : "FAIL") . "\n";
        echo "34. file errorMsg non-empty: " . (strlen($errFile) > 0 ? "PASS" : "FAIL") . "\n";

        // ════════════════════════════════════════════════════════════
        // 4. 不支持认证测试（不静默原则）
        // ════════════════════════════════════════════════════════════
        echo "\n-- 4. Unsupported auth rejection --\n";

        $chAuth = curl_init("http://example.com");
        curl_setopt($chAuth, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($chAuth, CURLOPT_HTTPAUTH, CURLAUTH_DIGEST);
        $retAuth = curl_exec($chAuth);
        $errAuth = curl_error($chAuth);
        echo "35. digest auth exec returns false: " . ($retAuth === false ? "PASS" : "FAIL") . "\n";
        echo "36. digest auth errorMsg has 'Only CURLAUTH_BASIC': " . (strpos($errAuth, "Only CURLAUTH_BASIC is supported") !== false ? "PASS" : "FAIL") . "\n";

        // ════════════════════════════════════════════════════════════
        // 5. 不支持代理类型测试（不静默原则）
        // ════════════════════════════════════════════════════════════
        echo "\n-- 5. Unsupported proxy type rejection --\n";

        $chProxy = curl_init("http://example.com");
        curl_setopt($chProxy, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($chProxy, CURLOPT_PROXY, "socks5://proxy:1080");
        curl_setopt($chProxy, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS5);
        $retProxy = curl_exec($chProxy);
        $errProxy = curl_error($chProxy);
        echo "37. socks5 proxy exec returns false: " . ($retProxy === false ? "PASS" : "FAIL") . "\n";
        echo "38. socks5 proxy errorMsg has 'SOCKS proxy is not supported': " . (strpos($errProxy, "SOCKS proxy is not supported") !== false ? "PASS" : "FAIL") . "\n";

        echo "\n=== cURL Stub & Reject Test done ===\n";
    }
}
