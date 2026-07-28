<?php
// ext/curl 扩展集成测试 — 纯 phpc 实现（需要真实网络连接）
//
// 测试范围：
//   1.  HTTP GET（http://httpbin.org/get）
//   2.  HTTPS GET（https://example.com）
//   3.  POST 表单（http://httpbin.org/post）
//   4.  POST JSON（自定义 Content-Type + body）
//   5.  自定义 headers（X-Custom 头回显）
//   6.  PUT/DELETE 自定义方法
//   7.  HEAD 请求（NOBODY=true）
//   8.  重定向跟随（http://httpbin.org/redirect/3）
//   9.  重定向超限（http://httpbin.org/redirect/10 + MAXREDIRS=3）
//   10. 超时测试（不可达主机 + TIMEOUT=2）
//   11. curl_getinfo 字段验证
//   12. 错误路径（DNS 失败、连接拒绝）
//   13. Basic Auth（httpbin.org/basic-auth/user/pass）
//   14. Cookie（CURLOPT_COOKIE）
//   15. CURLFile multipart 文件上传（CURLFile / CURLStringFile / curl_file_create）
//
// @skip  — CI 默认跳过（需要真实网络连接）：
//   本测试需要访问 httpbin.org / example.com 等外部服务，CI 环境可能无网络。
//   本地可手动运行：php tphp.php test/curl/curl_basic.php
#import stream
#import openssl
#import curl

class Main
{
    public function main(): void
    {
        echo "=== cURL Integration Test (network required) ===\n\n";

        // ── 1. HTTP GET ──
        echo "-- 1. HTTP GET --\n";
        try {
            $ch = curl_init("http://httpbin.org/get");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 15);
            $ok = curl_exec($ch);
            if ($ok === true) {
                $body = curl_multi_getcontent($ch);
                $info = curl_getinfo($ch);
                echo "1. HTTP GET: PASS (HTTP " . $info["http_code"] . ", " . strlen($body) . " bytes)\n";
            } else {
                echo "1. HTTP GET: FAIL (errno=" . curl_errno($ch) . ": " . curl_error($ch) . ")\n";
            }
            curl_close($ch);
        } catch (Exception $e) {
            echo "1. HTTP GET: FAIL (Exception: " . $e->getMessage() . ")\n";
        }

        // ── 2. HTTPS GET ──
        echo "\n-- 2. HTTPS GET --\n";
        try {
            $ch = curl_init("https://example.com");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 15);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            $ok = curl_exec($ch);
            if ($ok === true) {
                $body = curl_multi_getcontent($ch);
                $info = curl_getinfo($ch);
                echo "2. HTTPS GET: PASS (HTTP " . $info["http_code"] . ", " . strlen($body) . " bytes)\n";
            } else {
                echo "2. HTTPS GET: FAIL (errno=" . curl_errno($ch) . ": " . curl_error($ch) . ")\n";
            }
            curl_close($ch);
        } catch (Exception $e) {
            echo "2. HTTPS GET: FAIL (Exception: " . $e->getMessage() . ")\n";
        }

        // ── 3. POST 表单 ──
        echo "\n-- 3. POST form --\n";
        try {
            $ch = curl_init("http://httpbin.org/post");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 15);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, "name=foo&value=bar");
            $ok = curl_exec($ch);
            if ($ok === true) {
                $body = curl_multi_getcontent($ch);
                $hasForm = strpos($body, "foo") !== false;
                echo "3. POST form: " . ($hasForm ? "PASS" : "FAIL (body missing form data)") . "\n";
            } else {
                echo "3. POST form: FAIL (errno=" . curl_errno($ch) . ": " . curl_error($ch) . ")\n";
            }
            curl_close($ch);
        } catch (Exception $e) {
            echo "3. POST form: FAIL (Exception: " . $e->getMessage() . ")\n";
        }

        // ── 4. POST JSON ──
        echo "\n-- 4. POST JSON --\n";
        try {
            $ch = curl_init("http://httpbin.org/post");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 15);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, '{"key":"value","num":42}');
            curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json"]);
            $ok = curl_exec($ch);
            if ($ok === true) {
                $body = curl_multi_getcontent($ch);
                $hasJson = strpos($body, "value") !== false;
                echo "4. POST JSON: " . ($hasJson ? "PASS" : "FAIL (body missing JSON data)") . "\n";
            } else {
                echo "4. POST JSON: FAIL (errno=" . curl_errno($ch) . ": " . curl_error($ch) . ")\n";
            }
            curl_close($ch);
        } catch (Exception $e) {
            echo "4. POST JSON: FAIL (Exception: " . $e->getMessage() . ")\n";
        }

        // ── 5. 自定义 headers ──
        echo "\n-- 5. Custom headers --\n";
        try {
            $ch = curl_init("http://httpbin.org/headers");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 15);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ["X-Custom-Header: TestValue123"]);
            $ok = curl_exec($ch);
            if ($ok === true) {
                $body = curl_multi_getcontent($ch);
                $hasHeader = strpos($body, "TestValue123") !== false;
                echo "5. Custom headers: " . ($hasHeader ? "PASS" : "FAIL (header not echoed)") . "\n";
            } else {
                echo "5. Custom headers: FAIL (errno=" . curl_errno($ch) . ": " . curl_error($ch) . ")\n";
            }
            curl_close($ch);
        } catch (Exception $e) {
            echo "5. Custom headers: FAIL (Exception: " . $e->getMessage() . ")\n";
        }

        // ── 6. PUT/DELETE 自定义方法 ──
        echo "\n-- 6. PUT/DELETE custom method --\n";
        try {
            $ch = curl_init("http://httpbin.org/put");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 15);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
            curl_setopt($ch, CURLOPT_POSTFIELDS, "putdata");
            $ok = curl_exec($ch);
            if ($ok === true) {
                $body = curl_multi_getcontent($ch);
                $info = curl_getinfo($ch);
                echo "6a. PUT: PASS (HTTP " . $info["http_code"] . ")\n";
            } else {
                echo "6a. PUT: FAIL (errno=" . curl_errno($ch) . ": " . curl_error($ch) . ")\n";
            }
            curl_close($ch);
        } catch (Exception $e) {
            echo "6a. PUT: FAIL (Exception: " . $e->getMessage() . ")\n";
        }
        try {
            $ch = curl_init("http://httpbin.org/delete");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 15);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "DELETE");
            $ok = curl_exec($ch);
            if ($ok === true) {
                $body = curl_multi_getcontent($ch);
                $info = curl_getinfo($ch);
                echo "6b. DELETE: PASS (HTTP " . $info["http_code"] . ")\n";
            } else {
                echo "6b. DELETE: FAIL (errno=" . curl_errno($ch) . ": " . curl_error($ch) . ")\n";
            }
            curl_close($ch);
        } catch (Exception $e) {
            echo "6b. DELETE: FAIL (Exception: " . $e->getMessage() . ")\n";
        }

        // ── 7. HEAD 请求 ──
        echo "\n-- 7. HEAD request --\n";
        try {
            $ch = curl_init("http://httpbin.org/get");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 15);
            curl_setopt($ch, CURLOPT_NOBODY, true);
            $ok = curl_exec($ch);
            if ($ok === true) {
                $body = curl_multi_getcontent($ch);
                $info = curl_getinfo($ch);
                echo "7. HEAD: PASS (HTTP " . $info["http_code"] . ", body=" . strlen($body) . " bytes)\n";
            } else {
                echo "7. HEAD: FAIL (errno=" . curl_errno($ch) . ": " . curl_error($ch) . ")\n";
            }
            curl_close($ch);
        } catch (Exception $e) {
            echo "7. HEAD: FAIL (Exception: " . $e->getMessage() . ")\n";
        }

        // ── 8. 重定向跟随 ──
        echo "\n-- 8. Redirect follow --\n";
        try {
            $ch = curl_init("http://httpbin.org/redirect/3");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 20);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_MAXREDIRS, 10);
            $ok = curl_exec($ch);
            if ($ok === true) {
                $info = curl_getinfo($ch);
                echo "8. Redirect follow: PASS (HTTP " . $info["http_code"] . ", redirects=" . $info["redirect_count"] . ")\n";
            } else {
                echo "8. Redirect follow: FAIL (errno=" . curl_errno($ch) . ": " . curl_error($ch) . ")\n";
            }
            curl_close($ch);
        } catch (Exception $e) {
            echo "8. Redirect follow: FAIL (Exception: " . $e->getMessage() . ")\n";
        }

        // ── 9. 重定向超限 ──
        echo "\n-- 9. Redirect exceeded --\n";
        try {
            $ch = curl_init("http://httpbin.org/redirect/10");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 20);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
            $ok = curl_exec($ch);
            if ($ok === false) {
                $errNo = curl_errno($ch);
                $err = curl_error($ch);
                $isTooMany = ($errNo === 47 || strpos($err, "too many redirects") !== false);
                echo "9. Redirect exceeded: " . ($isTooMany ? "PASS (errno=" . $errNo . ")" : "FAIL (unexpected errno=" . $errNo . ": " . $err . ")") . "\n";
            } else {
                echo "9. Redirect exceeded: FAIL (should have failed with too many redirects)\n";
            }
            curl_close($ch);
        } catch (Exception $e) {
            echo "9. Redirect exceeded: FAIL (Exception: " . $e->getMessage() . ")\n";
        }

        // ── 10. 超时测试 ──
        echo "\n-- 10. Timeout --\n";
        try {
            $ch = curl_init("http://10.255.255.1");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 2);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
            $ok = curl_exec($ch);
            if ($ok === false) {
                $errNo = curl_errno($ch);
                $isTimeout = ($errNo === 28 || $errNo === 7 || $errNo === 6);
                echo "10. Timeout: " . ($isTimeout ? "PASS (errno=" . $errNo . ")" : "FAIL (unexpected errno=" . $errNo . ": " . curl_error($ch) . ")") . "\n";
            } else {
                echo "10. Timeout: FAIL (should have timed out)\n";
            }
            curl_close($ch);
        } catch (Exception $e) {
            echo "10. Timeout: FAIL (Exception: " . $e->getMessage() . ")\n";
        }

        // ── 11. curl_getinfo 字段验证 ──
        echo "\n-- 11. curl_getinfo fields --\n";
        try {
            $ch = curl_init("http://httpbin.org/get");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 15);
            $ok = curl_exec($ch);
            if ($ok === true) {
                $info = curl_getinfo($ch);
                $hasUrl = isset($info["url"]);
                $hasHttpCode = isset($info["http_code"]);
                $hasTotalTime = isset($info["total_time"]);
                $hasContentType = isset($info["content_type"]);
                $hasRedirectCount = isset($info["redirect_count"]);
                $hasPrimaryIp = isset($info["primary_ip"]);
                $hasEffectiveMethod = isset($info["effective_method"]);
                $allPresent = $hasUrl && $hasHttpCode && $hasTotalTime && $hasContentType && $hasRedirectCount && $hasPrimaryIp && $hasEffectiveMethod;
                echo "11. getinfo fields: " . ($allPresent ? "PASS" : "FAIL (missing fields)") . "\n";
                echo "    http_code=" . $info["http_code"] . " total_time=" . $info["total_time"] . " method=" . $info["effective_method"] . "\n";
            } else {
                echo "11. getinfo fields: FAIL (request failed: " . curl_error($ch) . ")\n";
            }
            curl_close($ch);
        } catch (Exception $e) {
            echo "11. getinfo fields: FAIL (Exception: " . $e->getMessage() . ")\n";
        }

        // ── 12. 错误路径测试 ──
        echo "\n-- 12. Error paths --\n";
        // DNS 失败
        try {
            $ch = curl_init("http://this-domain-does-not-exist-123456789.invalid");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 5);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
            $ok = curl_exec($ch);
            if ($ok === false) {
                $errNo = curl_errno($ch);
                echo "12a. DNS failure: PASS (errno=" . $errNo . ": " . curl_error($ch) . ")\n";
            } else {
                echo "12a. DNS failure: FAIL (should have failed)\n";
            }
            curl_close($ch);
        } catch (Exception $e) {
            echo "12a. DNS failure: FAIL (Exception: " . $e->getMessage() . ")\n";
        }
        // 连接拒绝
        try {
            $ch = curl_init("http://127.0.0.1:1");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 5);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
            $ok = curl_exec($ch);
            if ($ok === false) {
                $errNo = curl_errno($ch);
                echo "12b. Connection refused: PASS (errno=" . $errNo . ": " . curl_error($ch) . ")\n";
            } else {
                echo "12b. Connection refused: FAIL (should have failed)\n";
            }
            curl_close($ch);
        } catch (Exception $e) {
            echo "12b. Connection refused: FAIL (Exception: " . $e->getMessage() . ")\n";
        }

        // ── 13. Basic Auth ──
        echo "\n-- 13. Basic Auth --\n";
        try {
            $ch = curl_init("http://httpbin.org/basic-auth/user/pass");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 15);
            curl_setopt($ch, CURLOPT_USERPWD, "user:pass");
            curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
            $ok = curl_exec($ch);
            if ($ok === true) {
                $body = curl_multi_getcontent($ch);
                $info = curl_getinfo($ch);
                $isAuth = ($info["http_code"] === 200 && strpos($body, "authenticated") !== false);
                echo "13. Basic Auth: " . ($isAuth ? "PASS (HTTP " . $info["http_code"] . ")" : "FAIL (HTTP " . $info["http_code"] . ")") . "\n";
            } else {
                echo "13. Basic Auth: FAIL (errno=" . curl_errno($ch) . ": " . curl_error($ch) . ")\n";
            }
            curl_close($ch);
        } catch (Exception $e) {
            echo "13. Basic Auth: FAIL (Exception: " . $e->getMessage() . ")\n";
        }

        // ── 14. Cookie ──
        echo "\n-- 14. Cookie --\n";
        try {
            $ch = curl_init("http://httpbin.org/cookies");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 15);
            curl_setopt($ch, CURLOPT_COOKIE, "testcookie=cookievalue123");
            $ok = curl_exec($ch);
            if ($ok === true) {
                $body = curl_multi_getcontent($ch);
                $hasCookie = strpos($body, "cookievalue123") !== false;
                echo "14. Cookie: " . ($hasCookie ? "PASS" : "FAIL (cookie not echoed)") . "\n";
            } else {
                echo "14. Cookie: FAIL (errno=" . curl_errno($ch) . ": " . curl_error($ch) . ")\n";
            }
            curl_close($ch);
        } catch (Exception $e) {
            echo "14. Cookie: FAIL (Exception: " . $e->getMessage() . ")\n";
        }

        // ── 15. CURLFile multipart 文件上传 ──
        echo "\n-- 15. CURLFile multipart upload --\n";
        try {
            // 创建临时文件（tempnam/sys_get_temp_dir 不可用，用 __DIR__ + uniqid 构造唯一路径）
            $tmpFile = __DIR__ . "/curl_test_" . uniqid() . ".txt";
            $fileContent = "Hello cURL multipart upload!\nLine 2 of test file.\n";
            file_put_contents($tmpFile, $fileContent);

            // 15a. CURLFile 基本上传
            $ch = curl_init("http://httpbin.org/post");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 15);
            curl_setopt($ch, CURLOPT_POST, true);
            $cfile = new CURLFile($tmpFile, "text/plain", "upload_test.txt");
            curl_setopt($ch, CURLOPT_POSTFIELDS, [
                "file" => $cfile,
                "extra_field" => "test_value_123",
            ]);
            $ok = curl_exec($ch);
            if ($ok === true) {
                $body = curl_multi_getcontent($ch);
                $info = curl_getinfo($ch);
                $hasFileName = strpos($body, "upload_test.txt") !== false;
                $hasFileContent = strpos($body, "Hello cURL multipart upload!") !== false;
                $hasExtraField = strpos($body, "test_value_123") !== false;
                $isMultipart = strpos($info["content_type"], "multipart/form-data") !== false;
                $allOk = $hasFileName && $hasFileContent && $hasExtraField;
                echo "15a. CURLFile upload: " . ($allOk ? "PASS (HTTP " . $info["http_code"] . ", file received)" : "FAIL (HTTP " . $info["http_code"] . ")") . "\n";
                if (!$hasFileName) echo "    - filename not echoed\n";
                if (!$hasFileContent) echo "    - file content not echoed\n";
                if (!$hasExtraField) echo "    - extra field not echoed\n";
            } else {
                echo "15a. CURLFile upload: FAIL (errno=" . curl_errno($ch) . ": " . curl_error($ch) . ")\n";
            }
            curl_close($ch);

            // 15b. CURLStringFile 上传（内存数据）
            $ch = curl_init("http://httpbin.org/post");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 15);
            curl_setopt($ch, CURLOPT_POST, true);
            $sfile = new CURLStringFile("string file content here", "string_test.txt", "text/plain");
            curl_setopt($ch, CURLOPT_POSTFIELDS, [
                "file" => $sfile,
            ]);
            $ok = curl_exec($ch);
            if ($ok === true) {
                $body = curl_multi_getcontent($ch);
                $info = curl_getinfo($ch);
                $hasStringContent = strpos($body, "string file content here") !== false;
                $hasStringName = strpos($body, "string_test.txt") !== false;
                $allOk = $hasStringContent && $hasStringName;
                echo "15b. CURLStringFile upload: " . ($allOk ? "PASS (HTTP " . $info["http_code"] . ", string file received)" : "FAIL (HTTP " . $info["http_code"] . ")") . "\n";
                if (!$hasStringContent) echo "    - string content not echoed\n";
                if (!$hasStringName) echo "    - string filename not echoed\n";
            } else {
                echo "15b. CURLStringFile upload: FAIL (errno=" . curl_errno($ch) . ": " . curl_error($ch) . ")\n";
            }
            curl_close($ch);

            // 15c. curl_file_create() 函数等价性
            $ch = curl_init("http://httpbin.org/post");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 15);
            curl_setopt($ch, CURLOPT_POST, true);
            $fcfile = curl_file_create($tmpFile, "text/plain", "create_test.txt");
            curl_setopt($ch, CURLOPT_POSTFIELDS, [
                "file" => $fcfile,
            ]);
            $ok = curl_exec($ch);
            if ($ok === true) {
                $body = curl_multi_getcontent($ch);
                $info = curl_getinfo($ch);
                $hasCreateName = strpos($body, "create_test.txt") !== false;
                $hasCreateContent = strpos($body, "Hello cURL multipart upload!") !== false;
                $allOk = $hasCreateName && $hasCreateContent;
                echo "15c. curl_file_create: " . ($allOk ? "PASS (HTTP " . $info["http_code"] . ")" : "FAIL (HTTP " . $info["http_code"] . ")") . "\n";
            } else {
                echo "15c. curl_file_create: FAIL (errno=" . curl_errno($ch) . ": " . curl_error($ch) . ")\n";
            }
            curl_close($ch);

            // 清理临时文件
            unlink($tmpFile);
        } catch (Exception $e) {
            echo "15. CURLFile multipart upload: FAIL (Exception: " . $e->getMessage() . ")\n";
        }

        echo "\n=== cURL Integration Test done ===\n";
    }
}
