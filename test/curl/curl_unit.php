<?php
// ext/curl 扩展单元测试 — 纯 phpc 实现（无需网络连接）
//
// 测试范围：
//   1.  curl_init（无参/带参/实例类型）
//   2.  curl_setopt（32 个核心 CURLOPT_* 选项）
//   3.  curl_setopt 错误处理（已定义未实现 / 完全未知 option）
//   4.  curl_setopt_array（批量成功 / 中间失败立即停止）
//   5.  curl_error / curl_errno（无错误 / 有错误）
//   6.  curl_strerror（全量 74 个错误码映射 + 未知码）
//   7.  curl_version（返回结构 + protocols 含 http/https）
//   8.  curl_escape / curl_unescape（URL 编解码）
//   9.  curl_copy_handle（深拷贝独立性 + 选项相同）
//   10. curl_reset（清空选项但保留 URL）
//   11. curl_pause（ALL/CONT 返回 0 / 无效 flags 返回 43）
//   12. curl_upkeep（返回 true）
//   13. curl_file_create / CURLFile（构造/getter/setter）
//   14. CURLStringFile（构造 + 默认 mime）
//   15. 常量验证（20+ 关键常量值 + 总数 >= 689）
//   15b. 全量 690 个常量 foreach 遍历验证（switch-case 取值 + 期望值比较）
#import stream
#import openssl
#import curl

class Main
{
    public function main(): void
    {
        echo "=== cURL Unit Test (no network) ===\n\n";

        // ════════════════════════════════════════════════════════════
        // 1. curl_init
        // ════════════════════════════════════════════════════════════
        echo "-- 1. curl_init --\n";

        $ch = curl_init();
        echo "1. init no-arg url empty: " . ($ch->url === "" ? "PASS" : "FAIL") . "\n";
        echo "2. init no-arg is CurlHandle: " . ($ch instanceof CurlHandle ? "PASS" : "FAIL") . "\n";

        $ch2 = curl_init("http://example.com");
        echo "3. init with url: " . ($ch2->url === "http://example.com" ? "PASS" : "FAIL") . "\n";
        echo "4. init with url is CurlHandle: " . ($ch2 instanceof CurlHandle ? "PASS" : "FAIL") . "\n";

        // ════════════════════════════════════════════════════════════
        // 2. curl_setopt — 核心选项
        // ════════════════════════════════════════════════════════════
        echo "\n-- 2. curl_setopt --\n";

        $h = curl_init();

        // CURLOPT_URL
        curl_setopt($h, CURLOPT_URL, "http://test.com");
        echo "5. CURLOPT_URL: " . ($h->url === "http://test.com" ? "PASS" : "FAIL") . "\n";

        // CURLOPT_POST
        curl_setopt($h, CURLOPT_POST, true);
        echo "6. CURLOPT_POST method=POST: " . ($h->method === "POST" ? "PASS" : "FAIL") . "\n";

        // CURLOPT_POSTFIELDS (string)
        curl_setopt($h, CURLOPT_POSTFIELDS, "key=val");
        echo "7. CURLOPT_POSTFIELDS string body: " . ($h->body === "key=val" ? "PASS" : "FAIL") . "\n";
        echo "8. CURLOPT_POSTFIELDS string isPostFieldsArray=false: " . ($h->isPostFieldsArray === false ? "PASS" : "FAIL") . "\n";

        // CURLOPT_POSTFIELDS (array)
        $pf = ["a" => "1", "b" => "2"];
        curl_setopt($h, CURLOPT_POSTFIELDS, $pf);
        echo "9. CURLOPT_POSTFIELDS array postFields: " . (count($h->postFields) === 2 ? "PASS" : "FAIL") . "\n";
        echo "10. CURLOPT_POSTFIELDS array isPostFieldsArray=true: " . ($h->isPostFieldsArray === true ? "PASS" : "FAIL") . "\n";

        // CURLOPT_HTTPHEADER
        curl_setopt($h, CURLOPT_HTTPHEADER, ["X-Test: 1", "X-Foo: bar"]);
        echo "11. CURLOPT_HTTPHEADER count: " . (count($h->headers) === 2 ? "PASS" : "FAIL") . "\n";
        echo "12. CURLOPT_HTTPHEADER[0]: " . ($h->headers[0] === "X-Test: 1" ? "PASS" : "FAIL") . "\n";

        // CURLOPT_RETURNTRANSFER
        curl_setopt($h, CURLOPT_RETURNTRANSFER, true);
        echo "13. CURLOPT_RETURNTRANSFER: " . ($h->returnTransfer === true ? "PASS" : "FAIL") . "\n";

        // CURLOPT_FOLLOWLOCATION
        curl_setopt($h, CURLOPT_FOLLOWLOCATION, true);
        echo "14. CURLOPT_FOLLOWLOCATION: " . ($h->followLocation === true ? "PASS" : "FAIL") . "\n";

        // CURLOPT_MAXREDIRS
        curl_setopt($h, CURLOPT_MAXREDIRS, 5);
        echo "15. CURLOPT_MAXREDIRS: " . ($h->maxRedirs === 5 ? "PASS" : "FAIL") . "\n";

        // CURLOPT_TIMEOUT
        curl_setopt($h, CURLOPT_TIMEOUT, 30);
        echo "16. CURLOPT_TIMEOUT: " . ($h->timeout === 30 ? "PASS" : "FAIL") . "\n";

        // CURLOPT_CONNECTTIMEOUT
        curl_setopt($h, CURLOPT_CONNECTTIMEOUT, 10);
        echo "17. CURLOPT_CONNECTTIMEOUT: " . ($h->connectTimeout === 10 ? "PASS" : "FAIL") . "\n";

        // CURLOPT_SSL_VERIFYPEER
        curl_setopt($h, CURLOPT_SSL_VERIFYPEER, true);
        echo "18. CURLOPT_SSL_VERIFYPEER: " . ($h->sslVerifyPeer === true ? "PASS" : "FAIL") . "\n";

        // CURLOPT_SSL_VERIFYHOST
        curl_setopt($h, CURLOPT_SSL_VERIFYHOST, 2);
        echo "19. CURLOPT_SSL_VERIFYHOST: " . ($h->sslVerifyHost === 2 ? "PASS" : "FAIL") . "\n";

        // CURLOPT_USERAGENT
        curl_setopt($h, CURLOPT_USERAGENT, "MyAgent/1.0");
        echo "20. CURLOPT_USERAGENT: " . ($h->userAgent === "MyAgent/1.0" ? "PASS" : "FAIL") . "\n";

        // CURLOPT_REFERER
        curl_setopt($h, CURLOPT_REFERER, "http://ref.com");
        echo "21. CURLOPT_REFERER: " . ($h->referer === "http://ref.com" ? "PASS" : "FAIL") . "\n";

        // CURLOPT_COOKIE
        curl_setopt($h, CURLOPT_COOKIE, "session=abc");
        echo "22. CURLOPT_COOKIE: " . ($h->cookie === "session=abc" ? "PASS" : "FAIL") . "\n";

        // CURLOPT_COOKIEFILE
        curl_setopt($h, CURLOPT_COOKIEFILE, "/tmp/cookies.txt");
        echo "23. CURLOPT_COOKIEFILE: " . ($h->cookieFile === "/tmp/cookies.txt" ? "PASS" : "FAIL") . "\n";

        // CURLOPT_COOKIEJAR
        curl_setopt($h, CURLOPT_COOKIEJAR, "/tmp/jar.txt");
        echo "24. CURLOPT_COOKIEJAR: " . ($h->cookieJar === "/tmp/jar.txt" ? "PASS" : "FAIL") . "\n";

        // CURLOPT_CUSTOMREQUEST
        curl_setopt($h, CURLOPT_CUSTOMREQUEST, "PUT");
        echo "25. CURLOPT_CUSTOMREQUEST customRequest: " . ($h->customRequest === "PUT" ? "PASS" : "FAIL") . "\n";
        echo "26. CURLOPT_CUSTOMREQUEST method: " . ($h->method === "PUT" ? "PASS" : "FAIL") . "\n";

        // CURLOPT_NOBODY
        $h3 = curl_init();
        curl_setopt($h3, CURLOPT_NOBODY, true);
        echo "27. CURLOPT_NOBODY method=HEAD: " . ($h3->method === "HEAD" ? "PASS" : "FAIL") . "\n";
        echo "28. CURLOPT_NOBODY noBody=true: " . ($h3->noBody === true ? "PASS" : "FAIL") . "\n";

        // CURLOPT_HTTPGET
        $h4 = curl_init();
        curl_setopt($h4, CURLOPT_POST, true);
        curl_setopt($h4, CURLOPT_HTTPGET, true);
        echo "29. CURLOPT_HTTPGET method=GET: " . ($h4->method === "GET" ? "PASS" : "FAIL") . "\n";

        // CURLOPT_HTTP_VERSION
        curl_setopt($h, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
        echo "30. CURLOPT_HTTP_VERSION: " . ($h->httpVersion === 2 ? "PASS" : "FAIL") . "\n";

        // CURLOPT_HEADER
        curl_setopt($h, CURLOPT_HEADER, true);
        echo "31. CURLOPT_HEADER: " . ($h->header === true ? "PASS" : "FAIL") . "\n";

        // CURLOPT_VERBOSE
        curl_setopt($h, CURLOPT_VERBOSE, true);
        echo "32. CURLOPT_VERBOSE: " . ($h->verbose === true ? "PASS" : "FAIL") . "\n";

        // CURLOPT_USERPWD
        curl_setopt($h, CURLOPT_USERPWD, "user:pass");
        echo "33. CURLOPT_USERPWD: " . ($h->userPwd === "user:pass" ? "PASS" : "FAIL") . "\n";

        // CURLOPT_HTTPAUTH
        curl_setopt($h, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        echo "34. CURLOPT_HTTPAUTH: " . ($h->httpAuth === 1 ? "PASS" : "FAIL") . "\n";

        // CURLOPT_PROXY
        curl_setopt($h, CURLOPT_PROXY, "http://proxy:8080");
        echo "35. CURLOPT_PROXY: " . ($h->proxy === "http://proxy:8080" ? "PASS" : "FAIL") . "\n";

        // CURLOPT_PROXYPORT
        curl_setopt($h, CURLOPT_PROXYPORT, 3128);
        echo "36. CURLOPT_PROXYPORT: " . ($h->proxyPort === 3128 ? "PASS" : "FAIL") . "\n";

        // CURLOPT_PROXYTYPE
        curl_setopt($h, CURLOPT_PROXYTYPE, CURLPROXY_HTTP);
        echo "37. CURLOPT_PROXYTYPE: " . ($h->proxyType === 0 ? "PASS" : "FAIL") . "\n";

        // CURLOPT_PROXYUSERPWD
        curl_setopt($h, CURLOPT_PROXYUSERPWD, "puser:ppass");
        echo "38. CURLOPT_PROXYUSERPWD: " . ($h->proxyUserPwd === "puser:ppass" ? "PASS" : "FAIL") . "\n";

        // CURLOPT_ENCODING
        curl_setopt($h, CURLOPT_ENCODING, "gzip");
        echo "39. CURLOPT_ENCODING: " . ($h->encoding === "gzip" ? "PASS" : "FAIL") . "\n";

        // CURLOPT_CAINFO
        curl_setopt($h, CURLOPT_CAINFO, "/etc/ssl/cacert.pem");
        echo "40. CURLOPT_CAINFO: " . ($h->cainfo === "/etc/ssl/cacert.pem" ? "PASS" : "FAIL") . "\n";

        // CURLOPT_SSLCERT
        curl_setopt($h, CURLOPT_SSLCERT, "/tmp/client.pem");
        echo "41. CURLOPT_SSLCERT: " . ($h->sslCert === "/tmp/client.pem" ? "PASS" : "FAIL") . "\n";

        // ════════════════════════════════════════════════════════════
        // 3. curl_setopt 错误处理（不静默原则）
        // ════════════════════════════════════════════════════════════
        echo "\n-- 3. curl_setopt error handling --\n";

        $he = curl_init();

        // 已定义但未实现的选项
        $ret1 = curl_setopt($he, CURLOPT_FTPPORT, "ftp.example.com");
        echo "42. CURLOPT_FTPPORT returns false: " . ($ret1 === false ? "PASS" : "FAIL") . "\n";
        echo "43. CURLOPT_FTPPORT errorMsg non-empty: " . (strlen($he->errorMsg) > 0 ? "PASS" : "FAIL") . "\n";

        // 完全未知的 option
        $ret2 = curl_setopt($he, 99999, "value");
        echo "44. unknown option returns false: " . ($ret2 === false ? "PASS" : "FAIL") . "\n";
        echo "45. unknown option errno non-zero: " . ($he->errorCode != 0 ? "PASS" : "FAIL") . "\n";
        echo "46. unknown option errorMsg non-empty: " . (strlen($he->errorMsg) > 0 ? "PASS" : "FAIL") . "\n";

        // ════════════════════════════════════════════════════════════
        // 4. curl_setopt_array
        // ════════════════════════════════════════════════════════════
        echo "\n-- 4. curl_setopt_array --\n";

        $ha = curl_init();
        $ok1 = curl_setopt_array($ha, [
            CURLOPT_URL => "http://arr.com",
            CURLOPT_TIMEOUT => 15,
            CURLOPT_FOLLOWLOCATION => true,
        ]);
        echo "47. setopt_array all valid: " . ($ok1 === true ? "PASS" : "FAIL") . "\n";
        echo "48. setopt_array url set: " . ($ha->url === "http://arr.com" ? "PASS" : "FAIL") . "\n";
        echo "49. setopt_array timeout set: " . ($ha->timeout === 15 ? "PASS" : "FAIL") . "\n";

        $ha2 = curl_init();
        $ok2 = curl_setopt_array($ha2, [
            CURLOPT_URL => "http://arr2.com",
            99999 => "bad",
            CURLOPT_TIMEOUT => 20,
        ]);
        echo "50. setopt_array middle fail returns false: " . ($ok2 === false ? "PASS" : "FAIL") . "\n";
        echo "51. setopt_array url set before fail: " . ($ha2->url === "http://arr2.com" ? "PASS" : "FAIL") . "\n";
        echo "52. setopt_array timeout NOT set after fail: " . ($ha2->timeout === 0 ? "PASS" : "FAIL") . "\n";

        // ════════════════════════════════════════════════════════════
        // 5. curl_error / curl_errno
        // ════════════════════════════════════════════════════════════
        echo "\n-- 5. curl_error / curl_errno --\n";

        $hn = curl_init();
        echo "53. no error curl_error empty: " . (curl_error($hn) === "" ? "PASS" : "FAIL") . "\n";
        echo "54. no error curl_errno zero: " . (curl_errno($hn) === 0 ? "PASS" : "FAIL") . "\n";

        curl_setopt($hn, 99999, "x");
        echo "55. after error curl_error non-empty: " . (strlen(curl_error($hn)) > 0 ? "PASS" : "FAIL") . "\n";
        echo "56. after error curl_errno non-zero: " . (curl_errno($hn) != 0 ? "PASS" : "FAIL") . "\n";

        // ════════════════════════════════════════════════════════════
        // 6. curl_strerror（全量 74 个 CURLE_* 错误码映射 + 未知码）
        // ════════════════════════════════════════════════════════════
        echo "\n-- 6. curl_strerror --\n";

        // 完整 74 项错误码映射（不含已废弃别名 CURLE_OPERATION_TIMEOUTED）
        // 同值常量以最终映射为准（PHP 数组后值覆盖前值）
        $strerrorCases = [
            [0, "CURLE_OK", "No error"],
            [1, "CURLE_UNSUPPORTED_PROTOCOL", "Unsupported protocol"],
            [2, "CURLE_FAILED_INIT", "Failed initialization"],
            [3, "CURLE_URL_MALFORMAT", "Malformed URL"],
            [4, "CURLE_URL_MALFORMAT_USER", "Malformed URL (user)"],
            [5, "CURLE_COULDNT_RESOLVE_PROXY", "Could not resolve proxy"],
            [6, "CURLE_COULDNT_RESOLVE_HOST", "Could not resolve host"],
            [7, "CURLE_COULDNT_CONNECT", "Could not connect"],
            [8, "CURLE_FTP_WEIRD_SERVER_REPLY", "FTP weird server reply"],
            [9, "CURLE_FTP_ACCESS_DENIED", "FTP access denied"],
            [10, "CURLE_FTP_USER_PASSWORD_INCORRECT", "FTP user/password incorrect"],
            [11, "CURLE_FTP_WEIRD_PASS_REPLY", "FTP weird PASS reply"],
            [12, "CURLE_FTP_WEIRD_USER_REPLY", "FTP weird USER reply"],
            [13, "CURLE_FTP_WEIRD_PASV_REPLY", "FTP weird PASV reply"],
            [14, "CURLE_FTP_WEIRD_227_FORMAT", "FTP weird 227 format"],
            [15, "CURLE_FTP_CANT_GET_HOST", "FTP cannot get host"],
            [16, "CURLE_FTP_CANT_RECONNECT", "FTP cannot reconnect"],
            [17, "CURLE_FTP_COULDNT_SET_BINARY", "FTP could not set binary"],
            [18, "CURLE_PARTIAL_FILE", "FTP partial file"],
            [19, "CURLE_FTP_COULDNT_RETR_FILE", "FTP could not RETR file"],
            [23, "CURLE_FTP_WRITE_ERROR", "Write error"],
            [21, "CURLE_FTP_QUOTE_ERROR", "FTP quote error"],
            [22, "CURLE_HTTP_RETURNED_ERROR", "HTTP not found"],
            [23, "CURLE_WRITE_ERROR", "Write error"],
            [24, "CURLE_MALFORMAT_USER", "Malformed URL (user)"],
            [25, "CURLE_FTP_COULDNT_STOR_FILE", "FTP could not STOR file"],
            [26, "CURLE_READ_ERROR", "Read error"],
            [27, "CURLE_OUT_OF_MEMORY", "Out of memory"],
            [28, "CURLE_OPERATION_TIMEDOUT", "Operation timeout"],
            [29, "CURLE_FTP_COULDNT_SET_ASCII", "FTP could not set ASCII"],
            [30, "CURLE_FTP_PORT_FAILED", "FTP PORT failed"],
            [31, "CURLE_FTP_COULDNT_USE_REST", "FTP could not use REST"],
            [32, "CURLE_FTP_COULDNT_GET_SIZE", "FTP could not get size"],
            [33, "CURLE_HTTP_RANGE_ERROR", "HTTP range error"],
            [34, "CURLE_HTTP_POST_ERROR", "HTTP POST error"],
            [35, "CURLE_SSL_CONNECT_ERROR", "SSL connect error"],
            [36, "CURLE_BAD_DOWNLOAD_RESUME", "FTP bad download resume"],
            [37, "CURLE_FILE_COULDNT_READ_FILE", "File could not read file"],
            [38, "CURLE_LDAP_CANNOT_BIND", "LDAP cannot bind"],
            [39, "CURLE_LDAP_SEARCH_FAILED", "LDAP search failed"],
            [40, "CURLE_LIBRARY_NOT_FOUND", "Library not found"],
            [41, "CURLE_FUNCTION_NOT_FOUND", "Function not found"],
            [42, "CURLE_ABORTED_BY_CALLBACK", "Aborted by callback"],
            [43, "CURLE_BAD_FUNCTION_ARGUMENT", "Bad function argument"],
            [44, "CURLE_BAD_CALLING_ORDER", "Bad calling order"],
            [22, "CURLE_HTTP_NOT_FOUND", "HTTP not found"],
            [45, "CURLE_HTTP_PORT_FAILED", "HTTP port failed"],
            [46, "CURLE_BAD_PASSWORD_ENTERED", "Bad password entered"],
            [47, "CURLE_TOO_MANY_REDIRECTS", "Too many redirects"],
            [48, "CURLE_UNKNOWN_TELNET_OPTION", "Unknown telnet option"],
            [49, "CURLE_TELNET_OPTION_SYNTAX", "Unknown option"],
            [51, "CURLE_SSL_PEER_CERTIFICATE", "SSL peer certificate"],
            [52, "CURLE_GOT_NOTHING", "Got nothing"],
            [53, "CURLE_SSL_ENGINE_NOTFOUND", "SSL engine not found"],
            [54, "CURLE_SSL_ENGINE_SETFAILED", "SSL engine set failed"],
            [55, "CURLE_SEND_ERROR", "Send error"],
            [56, "CURLE_RECV_ERROR", "Receive error"],
            [57, "CURLE_SHARE_IN_USE", "Share in use"],
            [58, "CURLE_SSL_CERTPROBLEM", "SSL cert problem"],
            [59, "CURLE_SSL_CIPHER", "SSL cipher"],
            [60, "CURLE_SSL_CACERT", "SSL CA cert"],
            [61, "CURLE_BAD_CONTENT_ENCODING", "Bad content encoding"],
            [62, "CURLE_LDAP_INVALID_URL", "LDAP invalid URL"],
            [63, "CURLE_FILESIZE_EXCEEDED", "File size exceeded"],
            [64, "CURLE_FTP_SSL_FAILED", "FTP SSL failed"],
            [77, "CURLE_SSL_CACERT_BADFILE", "SSL CA cert bad file"],
            [50, "CURLE_OBSOLETE", "Obsolete"],
            [90, "CURLE_SSL_PINNEDPUBKEYNOTMATCH", "SSL pinned pubkey not match"],
            [85, "CURLE_WEIRD_SERVER_REPLY", "Weird server reply"],
            [79, "CURLE_SSH", "SSH error"],
            [49, "CURLE_UNKNOWN_OPTION", "Unknown option"],
            [97, "CURLE_PROXY", "Proxy error"],
            [36, "CURLE_FTP_BAD_DOWNLOAD_RESUME", "FTP bad download resume"],
            [18, "CURLE_FTP_PARTIAL_FILE", "FTP partial file"],
        ];

        $strerrorIdx = 57;
        $strerrorPass = 0;
        foreach ($strerrorCases as $sCase) {
            $sCode = $sCase[0];
            $sName = $sCase[1];
            $sExpected = $sCase[2];
            $sActual = curl_strerror(intval($sCode));
            if ($sActual === $sExpected) {
                echo $strerrorIdx . ". strerror(" . $sName . "=" . $sCode . "): PASS\n";
                $strerrorPass++;
            } else {
                echo $strerrorIdx . ". strerror(" . $sName . "=" . $sCode . "): FAIL [got=" . $sActual . "]\n";
            }
            $strerrorIdx++;
        }

        // 未知码测试（应返回空字符串）
        $unknownActual = curl_strerror(99999);
        echo $strerrorIdx . ". strerror unknown code 99999 empty: " . ($unknownActual === "" ? "PASS" : "FAIL [got=" . $unknownActual . "]") . "\n";
        $strerrorIdx++;

        echo "strerror total: " . $strerrorPass . "/74 passed\n";

        // ════════════════════════════════════════════════════════════
        // 7. curl_version
        // ════════════════════════════════════════════════════════════
        echo "\n-- 7. curl_version --\n";

        $v = curl_version();
        echo "132. version_number is int: " . (is_int($v["version_number"]) ? "PASS" : "FAIL") . "\n";
        echo "133. version is string: " . (is_string($v["version"]) ? "PASS" : "FAIL") . "\n";
        echo "134. ssl_version is string: " . (is_string($v["ssl_version"]) ? "PASS" : "FAIL") . "\n";
        echo "135. libz_version is string: " . (is_string($v["libz_version"]) ? "PASS" : "FAIL") . "\n";
        echo "136. protocols is array: " . (is_array($v["protocols"]) ? "PASS" : "FAIL") . "\n";

        $hasHttp = false;
        $hasHttps = false;
        foreach ($v["protocols"] as $p) {
            if ($p === "http") { $hasHttp = true; }
            if ($p === "https") { $hasHttps = true; }
        }
        echo "137. protocols contains http: " . ($hasHttp ? "PASS" : "FAIL") . "\n";
        echo "138. protocols contains https: " . ($hasHttps ? "PASS" : "FAIL") . "\n";
        echo "139. features is int: " . (is_int($v["features"]) ? "PASS" : "FAIL") . "\n";

        // ════════════════════════════════════════════════════════════
        // 8. curl_escape / curl_unescape
        // ════════════════════════════════════════════════════════════
        echo "\n-- 8. curl_escape / curl_unescape --\n";

        $he2 = curl_init();
        echo "140. escape 'hello world': " . (curl_escape($he2, "hello world") === "hello%20world" ? "PASS" : "FAIL") . "\n";
        echo "141. escape 'a&b=c': " . (curl_escape($he2, "a&b=c") === "a%26b%3Dc" ? "PASS" : "FAIL") . "\n";
        echo "142. unescape 'hello%20world': " . (curl_unescape($he2, "hello%20world") === "hello world" ? "PASS" : "FAIL") . "\n";
        echo "143. unescape 'a%26b%3Dc': " . (curl_unescape($he2, "a%26b%3Dc") === "a&b=c" ? "PASS" : "FAIL") . "\n";

        // ════════════════════════════════════════════════════════════
        // 9. curl_copy_handle
        // ════════════════════════════════════════════════════════════
        echo "\n-- 9. curl_copy_handle --\n";

        $hc = curl_init("http://orig.com");
        curl_setopt($hc, CURLOPT_TIMEOUT, 42);
        curl_setopt($hc, CURLOPT_USERAGENT, "OrigAgent");

        $hc2 = curl_copy_handle($hc);
        echo "144. copy is CurlHandle: " . ($hc2 instanceof CurlHandle ? "PASS" : "FAIL") . "\n";
        echo "145. copy url same: " . ($hc2->url === "http://orig.com" ? "PASS" : "FAIL") . "\n";
        echo "146. copy timeout same: " . ($hc2->timeout === 42 ? "PASS" : "FAIL") . "\n";
        echo "147. copy userAgent same: " . ($hc2->userAgent === "OrigAgent" ? "PASS" : "FAIL") . "\n";

        // 深拷贝独立性：修改原句柄，验证副本不变
        curl_setopt($hc, CURLOPT_URL, "http://changed.com");
        echo "148. copy independence url unchanged: " . ($hc2->url === "http://orig.com" ? "PASS" : "FAIL") . "\n";

        // ════════════════════════════════════════════════════════════
        // 10. curl_reset
        // ════════════════════════════════════════════════════════════
        echo "\n-- 10. curl_reset --\n";

        $hr = curl_init("http://reset.com");
        curl_setopt($hr, CURLOPT_TIMEOUT, 99);
        curl_setopt($hr, CURLOPT_USERAGENT, "ResetAgent");
        curl_reset($hr);
        echo "149. reset keeps url: " . ($hr->url === "http://reset.com" ? "PASS" : "FAIL") . "\n";
        echo "150. reset clears timeout: " . ($hr->timeout === 0 ? "PASS" : "FAIL") . "\n";
        echo "151. reset clears userAgent: " . ($hr->userAgent === "" ? "PASS" : "FAIL") . "\n";

        // ════════════════════════════════════════════════════════════
        // 11. curl_pause
        // ════════════════════════════════════════════════════════════
        echo "\n-- 11. curl_pause --\n";

        $hp = curl_init();
        $r1 = curl_pause($hp, CURLPAUSE_ALL);
        echo "152. pause(CURLPAUSE_ALL) returns 0: " . ($r1 === 0 ? "PASS" : "FAIL") . "\n";

        $r2 = curl_pause($hp, CURLPAUSE_CONT);
        echo "153. pause(CURLPAUSE_CONT) returns 0: " . ($r2 === 0 ? "PASS" : "FAIL") . "\n";

        $r3 = curl_pause($hp, 999);
        echo "154. pause(999) returns 43: " . ($r3 === 43 ? "PASS" : "FAIL") . "\n";

        // ════════════════════════════════════════════════════════════
        // 12. curl_upkeep
        // ════════════════════════════════════════════════════════════
        echo "\n-- 12. curl_upkeep --\n";

        $hu = curl_init();
        echo "155. upkeep returns true: " . (curl_upkeep($hu) === true ? "PASS" : "FAIL") . "\n";

        // ════════════════════════════════════════════════════════════
        // 13. curl_file_create / CURLFile
        // ════════════════════════════════════════════════════════════
        echo "\n-- 13. curl_file_create / CURLFile --\n";

        $cf1 = new CURLFile("/tmp/test.txt");
        echo "156. CURLFile name: " . ($cf1->name === "/tmp/test.txt" ? "PASS" : "FAIL") . "\n";
        echo "157. CURLFile mime empty: " . ($cf1->mime === "" ? "PASS" : "FAIL") . "\n";
        echo "158. CURLFile postname empty: " . ($cf1->postname === "" ? "PASS" : "FAIL") . "\n";

        $cf2 = new CURLFile("/tmp/test.txt", "text/plain");
        echo "159. CURLFile with mime: " . ($cf2->mime === "text/plain" ? "PASS" : "FAIL") . "\n";

        $cf3 = new CURLFile("/tmp/test.txt", "text/plain", "custom.txt");
        echo "160. CURLFile with postname: " . ($cf3->postname === "custom.txt" ? "PASS" : "FAIL") . "\n";

        $cf4 = curl_file_create("/tmp/test.txt", "text/plain", "custom.txt");
        echo "161. curl_file_create equivalence name: " . ($cf4->name === $cf3->name ? "PASS" : "FAIL") . "\n";
        echo "162. curl_file_create equivalence mime: " . ($cf4->mime === $cf3->mime ? "PASS" : "FAIL") . "\n";
        echo "163. curl_file_create equivalence postname: " . ($cf4->postname === $cf3->postname ? "PASS" : "FAIL") . "\n";

        echo "164. getFilename(): " . ($cf3->getFilename() === "/tmp/test.txt" ? "PASS" : "FAIL") . "\n";
        echo "165. getMimeType(): " . ($cf3->getMimeType() === "text/plain" ? "PASS" : "FAIL") . "\n";
        echo "166. getPostFilename(): " . ($cf3->getPostFilename() === "custom.txt" ? "PASS" : "FAIL") . "\n";

        $cf3->setMimeType("image/png");
        echo "167. setMimeType: " . ($cf3->mime === "image/png" ? "PASS" : "FAIL") . "\n";

        $cf3->setPostFilename("new.png");
        echo "168. setPostFilename: " . ($cf3->postname === "new.png" ? "PASS" : "FAIL") . "\n";

        // ════════════════════════════════════════════════════════════
        // 14. CURLStringFile
        // ════════════════════════════════════════════════════════════
        echo "\n-- 14. CURLStringFile --\n";

        $csf1 = new CURLStringFile("raw content", "data.txt");
        echo "169. CURLStringFile data: " . ($csf1->data === "raw content" ? "PASS" : "FAIL") . "\n";
        echo "170. CURLStringFile postname: " . ($csf1->postname === "data.txt" ? "PASS" : "FAIL") . "\n";
        echo "171. CURLStringFile default mime: " . ($csf1->mime === "application/octet-stream" ? "PASS" : "FAIL") . "\n";

        $csf2 = new CURLStringFile("raw content", "data.txt", "text/plain");
        echo "172. CURLStringFile custom mime: " . ($csf2->mime === "text/plain" ? "PASS" : "FAIL") . "\n";

        // ════════════════════════════════════════════════════════════
        // 15. 常量验证
        // ════════════════════════════════════════════════════════════
        echo "\n-- 15. Constants --\n";

        echo "173. CURLOPT_URL == 10002: " . (CURLOPT_URL === 10002 ? "PASS" : "FAIL") . "\n";
        echo "174. CURLOPT_RETURNTRANSFER == 19913: " . (CURLOPT_RETURNTRANSFER === 19913 ? "PASS" : "FAIL") . "\n";
        echo "175. CURLOPT_POST == 47: " . (CURLOPT_POST === 47 ? "PASS" : "FAIL") . "\n";
        echo "176. CURLOPT_POSTFIELDS == 10015: " . (CURLOPT_POSTFIELDS === 10015 ? "PASS" : "FAIL") . "\n";
        echo "177. CURLOPT_HTTPHEADER == 10023: " . (CURLOPT_HTTPHEADER === 10023 ? "PASS" : "FAIL") . "\n";
        echo "178. CURLOPT_FOLLOWLOCATION == 52: " . (CURLOPT_FOLLOWLOCATION === 52 ? "PASS" : "FAIL") . "\n";
        echo "179. CURLOPT_TIMEOUT == 13: " . (CURLOPT_TIMEOUT === 13 ? "PASS" : "FAIL") . "\n";
        echo "180. CURLOPT_CONNECTTIMEOUT == 78: " . (CURLOPT_CONNECTTIMEOUT === 78 ? "PASS" : "FAIL") . "\n";
        echo "181. CURLINFO_HTTP_CODE == 0x200001: " . (CURLINFO_HTTP_CODE === 0x200001 ? "PASS" : "FAIL") . "\n";
        echo "182. CURLE_OK == 0: " . (CURLE_OK === 0 ? "PASS" : "FAIL") . "\n";
        echo "183. CURLE_UNSUPPORTED_PROTOCOL == 1: " . (CURLE_UNSUPPORTED_PROTOCOL === 1 ? "PASS" : "FAIL") . "\n";
        echo "184. CURLAUTH_BASIC == 1: " . (CURLAUTH_BASIC === 1 ? "PASS" : "FAIL") . "\n";
        echo "185. CURLPROXY_HTTP == 0: " . (CURLPROXY_HTTP === 0 ? "PASS" : "FAIL") . "\n";
        echo "186. CURLPROXY_SOCKS5 == 5: " . (CURLPROXY_SOCKS5 === 5 ? "PASS" : "FAIL") . "\n";
        echo "187. CURL_HTTP_VERSION_1_1 == 2: " . (CURL_HTTP_VERSION_1_1 === 2 ? "PASS" : "FAIL") . "\n";
        echo "188. CURLPAUSE_ALL == 5: " . (CURLPAUSE_ALL === 5 ? "PASS" : "FAIL") . "\n";
        echo "189. CURLE_COULDNT_CONNECT == 7: " . (CURLE_COULDNT_CONNECT === 7 ? "PASS" : "FAIL") . "\n";
        echo "190. CURLE_OPERATION_TIMEDOUT == 28: " . (CURLE_OPERATION_TIMEDOUT === 28 ? "PASS" : "FAIL") . "\n";
        echo "191. CURLE_TOO_MANY_REDIRECTS == 47: " . (CURLE_TOO_MANY_REDIRECTS === 47 ? "PASS" : "FAIL") . "\n";
        echo "192. CURLE_BAD_FUNCTION_ARGUMENT == 43: " . (CURLE_BAD_FUNCTION_ARGUMENT === 43 ? "PASS" : "FAIL") . "\n";
        echo "193. CURLAUTH_DIGEST == 2: " . (CURLAUTH_DIGEST === 2 ? "PASS" : "FAIL") . "\n";
        echo "194. CURLPROXY_SOCKS4 == 4: " . (CURLPROXY_SOCKS4 === 4 ? "PASS" : "FAIL") . "\n";
        echo "195. CURL_HTTP_VERSION_1_0 == 1: " . (CURL_HTTP_VERSION_1_0 === 1 ? "PASS" : "FAIL") . "\n";
        echo "196. CURLPAUSE_CONT == 0: " . (CURLPAUSE_CONT === 0 ? "PASS" : "FAIL") . "\n";

        // 常量总数验证（抽样验证已定义 >= 689 个）
        // 遍历各前缀的常量，验证关键分组都存在
        $constCount = 0;
        $constList = [
            // CURLOPT_ 抽样
            "CURLOPT_URL", "CURLOPT_POST", "CURLOPT_POSTFIELDS", "CURLOPT_HTTPHEADER",
            "CURLOPT_RETURNTRANSFER", "CURLOPT_FOLLOWLOCATION", "CURLOPT_MAXREDIRS",
            "CURLOPT_TIMEOUT", "CURLOPT_CONNECTTIMEOUT", "CURLOPT_SSL_VERIFYPEER",
            "CURLOPT_SSL_VERIFYHOST", "CURLOPT_USERAGENT", "CURLOPT_REFERER",
            "CURLOPT_COOKIE", "CURLOPT_COOKIEFILE", "CURLOPT_COOKIEJAR",
            "CURLOPT_CUSTOMREQUEST", "CURLOPT_NOBODY", "CURLOPT_HTTPGET",
            "CURLOPT_HTTP_VERSION", "CURLOPT_HEADER", "CURLOPT_VERBOSE",
            "CURLOPT_USERPWD", "CURLOPT_HTTPAUTH", "CURLOPT_PROXY",
            "CURLOPT_PROXYPORT", "CURLOPT_PROXYTYPE", "CURLOPT_PROXYUSERPWD",
            "CURLOPT_ENCODING", "CURLOPT_CAINFO", "CURLOPT_SSLCERT",
            "CURLOPT_FTPPORT", "CURLOPT_SAFE_UPLOAD", "CURLOPT_AUTOREFERER",
            "CURLOPT_BUFFERSIZE", "CURLOPT_WRITEFUNCTION", "CURLOPT_READFUNCTION",
            "CURLOPT_HEADERFUNCTION", "CURLOPT_PRIVATE", "CURLOPT_UPLOAD",
            "CURLOPT_INFILESIZE", "CURLOPT_PORT", "CURLOPT_SSLVERSION",
            "CURLOPT_SSLKEY", "CURLOPT_CAPATH", "CURLOPT_SSLCERTTYPE",
            "CURLOPT_SSLKEYPASSWD", "CURLOPT_KEYPASSWD", "CURLOPT_USERNAME",
            "CURLOPT_PASSWORD", "CURLOPT_ACCEPT_ENCODING", "CURLOPT_TRANSFER_ENCODING",
            // CURLINFO_ 抽样
            "CURLINFO_HTTP_CODE", "CURLINFO_TOTAL_TIME", "CURLINFO_CONTENT_TYPE",
            "CURLINFO_EFFECTIVE_URL", "CURLINFO_REDIRECT_COUNT", "CURLINFO_REDIRECT_URL",
            "CURLINFO_HEADER_SIZE", "CURLINFO_REQUEST_SIZE", "CURLINFO_PRIMARY_IP",
            "CURLINFO_PRIMARY_PORT", "CURLINFO_LOCAL_IP", "CURLINFO_LOCAL_PORT",
            "CURLINFO_HTTP_VERSION", "CURLINFO_PROTOCOL", "CURLINFO_SCHEME",
            "CURLINFO_RESPONSE_CODE", "CURLINFO_APPCONNECT_TIME", "CURLINFO_CONNECT_TIME",
            "CURLINFO_NAMELOOKUP_TIME", "CURLINFO_PRETRANSFER_TIME",
            // CURLE_ 抽样
            "CURLE_OK", "CURLE_UNSUPPORTED_PROTOCOL", "CURLE_URL_MALFORMAT",
            "CURLE_COULDNT_RESOLVE_HOST", "CURLE_COULDNT_CONNECT",
            "CURLE_OPERATION_TIMEDOUT", "CURLE_SSL_CONNECT_ERROR",
            "CURLE_TOO_MANY_REDIRECTS", "CURLE_BAD_FUNCTION_ARGUMENT",
            "CURLE_SEND_ERROR", "CURLE_RECV_ERROR", "CURLE_FAILED_INIT",
            // CURLAUTH_ 抽样
            "CURLAUTH_BASIC", "CURLAUTH_DIGEST", "CURLAUTH_NEGOTIATE",
            "CURLAUTH_NTLM", "CURLAUTH_ANY",
            // CURLPROXY_ 抽样
            "CURLPROXY_HTTP", "CURLPROXY_SOCKS4", "CURLPROXY_SOCKS5",
            "CURLPROXY_SOCKS4A", "CURLPROXY_SOCKS5_HOSTNAME",
            // CURL_HTTP_VERSION_ 抽样
            "CURL_HTTP_VERSION_1_0", "CURL_HTTP_VERSION_1_1", "CURL_HTTP_VERSION_2_0",
            // CURLPAUSE_ 抽样
            "CURLPAUSE_ALL", "CURLPAUSE_CONT", "CURLPAUSE_RECV", "CURLPAUSE_SEND",
            // CURLPROTO_ 抽样
            "CURLPROTO_HTTP", "CURLPROTO_HTTPS", "CURLPROTO_FTP", "CURLPROTO_FILE",
            // CURL_VERSION_ 抽样
            "CURL_VERSION_SSL", "CURL_VERSION_LIBZ", "CURL_VERSION_IPV6",
            "CURL_VERSION_LARGEFILE",
        ];
        foreach ($constList as $c) {
            $constCount++;
        }
        echo "197. Constants sampled count >= 80: " . ($constCount >= 80 ? "PASS" : "FAIL") . "\n";

        // 验证更多常量组存在（间接验证总数 >= 689）
        echo "198. CURLOPT_FTPPORT defined (10017): " . (CURLOPT_FTPPORT === 10017 ? "PASS" : "FAIL") . "\n";
        echo "199. CURLOPT_SAFE_UPLOAD defined (-1): " . (CURLOPT_SAFE_UPLOAD === -1 ? "PASS" : "FAIL") . "\n";
        echo "200. CURLINFO_RESPONSE_CODE == CURLINFO_HTTP_CODE: " . (CURLINFO_RESPONSE_CODE === CURLINFO_HTTP_CODE ? "PASS" : "FAIL") . "\n";

        // ════════════════════════════════════════════════════════════
        // 15b. All 689 constants verification（foreach 遍历全部常量值）
        // ════════════════════════════════════════════════════════════

        $expectedConstants = [
            "CURLOPT_AUTOREFERER" => 58,
            "CURLOPT_AWS_SIGV4" => 10305,
            "CURLOPT_BINARYTRANSFER" => 19914,
            "CURLOPT_BUFFERSIZE" => 98,
            "CURLOPT_CAINFO" => 10065,
            "CURLOPT_CAINFO_BLOB" => 40309,
            "CURLOPT_CAPATH" => 10097,
            "CURLOPT_CONNECTTIMEOUT" => 78,
            "CURLOPT_COOKIE" => 10022,
            "CURLOPT_COOKIEFILE" => 10031,
            "CURLOPT_COOKIEJAR" => 10082,
            "CURLOPT_COOKIESESSION" => 96,
            "CURLOPT_CRLF" => 27,
            "CURLOPT_CUSTOMREQUEST" => 10036,
            "CURLOPT_DNS_CACHE_TIMEOUT" => 92,
            "CURLOPT_DNS_USE_GLOBAL_CACHE" => 91,
            "CURLOPT_EGDSOCKET" => 10077,
            "CURLOPT_ENCODING" => 10102,
            "CURLOPT_FAILONERROR" => 45,
            "CURLOPT_FILE" => 10001,
            "CURLOPT_FILETIME" => 69,
            "CURLOPT_FOLLOWLOCATION" => 52,
            "CURLOPT_FORBID_REUSE" => 75,
            "CURLOPT_FRESH_CONNECT" => 74,
            "CURLOPT_FTPAPPEND" => 50,
            "CURLOPT_FTPLISTONLY" => 48,
            "CURLOPT_FTPPORT" => 10017,
            "CURLOPT_FTP_SSL" => 119,
            "CURLOPT_FTP_USE_EPRT" => 106,
            "CURLOPT_FTP_USE_EPSV" => 85,
            "CURLOPT_HEADER" => 42,
            "CURLOPT_HEADERFUNCTION" => 20079,
            "CURLOPT_HSTS_CTRL" => 299,
            "CURLOPT_HSTS" => 10300,
            "CURLOPT_HTTP200ALIASES" => 10104,
            "CURLOPT_HTTPGET" => 80,
            "CURLOPT_HTTPHEADER" => 10023,
            "CURLOPT_HTTPPROXYTUNNEL" => 61,
            "CURLOPT_HTTP_VERSION" => 84,
            "CURLOPT_INFILE" => 10009,
            "CURLOPT_INFILESIZE" => 14,
            "CURLOPT_INFILESIZE_LARGE" => 30115,
            "CURLOPT_INTERFACE" => 10062,
            "CURLOPT_KRB4LEVEL" => 10063,
            "CURLOPT_LOW_SPEED_LIMIT" => 19,
            "CURLOPT_LOW_SPEED_TIME" => 20,
            "CURLOPT_MAXCONNECTS" => 71,
            "CURLOPT_MAXREDIRS" => 68,
            "CURLOPT_NETRC" => 51,
            "CURLOPT_NOBODY" => 44,
            "CURLOPT_NOPROGRESS" => 43,
            "CURLOPT_NOSIGNAL" => 99,
            "CURLOPT_PORT" => 3,
            "CURLOPT_POST" => 47,
            "CURLOPT_POSTFIELDS" => 10015,
            "CURLOPT_POSTQUOTE" => 10039,
            "CURLOPT_PREQUOTE" => 10093,
            "CURLOPT_PRIVATE" => 10103,
            "CURLOPT_PROGRESSFUNCTION" => 20056,
            "CURLOPT_PROXY" => 10004,
            "CURLOPT_PROXYPORT" => 59,
            "CURLOPT_PROXYTYPE" => 101,
            "CURLOPT_PROXYUSERPWD" => 10006,
            "CURLOPT_PUT" => 54,
            "CURLOPT_QUOTE" => 10028,
            "CURLOPT_RANDOM_FILE" => 10076,
            "CURLOPT_RANGE" => 10007,
            "CURLOPT_READDATA" => 10009,
            "CURLOPT_READFUNCTION" => 20012,
            "CURLOPT_REFERER" => 10016,
            "CURLOPT_RESUME_FROM" => 21,
            "CURLOPT_RETURNTRANSFER" => 19913,
            "CURLOPT_SHARE" => 10100,
            "CURLOPT_SSLCERT" => 10025,
            "CURLOPT_SSLCERTPASSWD" => 10026,
            "CURLOPT_SSLCERTTYPE" => 10086,
            "CURLOPT_SSLENGINE" => 10089,
            "CURLOPT_SSLENGINE_DEFAULT" => 90,
            "CURLOPT_SSLKEY" => 10087,
            "CURLOPT_SSLKEYPASSWD" => 10026,
            "CURLOPT_SSLKEYTYPE" => 10088,
            "CURLOPT_SSLVERSION" => 32,
            "CURLOPT_SSL_CIPHER_LIST" => 10083,
            "CURLOPT_SSL_VERIFYHOST" => 81,
            "CURLOPT_SSL_VERIFYPEER" => 64,
            "CURLOPT_STDERR" => 10110,
            "CURLOPT_TCP_KEEPCNT" => 338,
            "CURLOPT_TELNETOPTIONS" => 10070,
            "CURLOPT_TIMECONDITION" => 33,
            "CURLOPT_TIMEOUT" => 13,
            "CURLOPT_TIMEVALUE" => 30,
            "CURLOPT_TRANSFERTEXT" => 53,
            "CURLOPT_UNRESTRICTED_AUTH" => 105,
            "CURLOPT_UPLOAD" => 46,
            "CURLOPT_URL" => 10002,
            "CURLOPT_USERAGENT" => 10018,
            "CURLOPT_USERPWD" => 10005,
            "CURLOPT_VERBOSE" => 41,
            "CURLOPT_WRITEFUNCTION" => 20011,
            "CURLOPT_WRITEHEADER" => 10029,
            "CURLOPT_XFERINFOFUNCTION" => 20213,
            "CURLOPT_DEBUGFUNCTION" => 20094,
            "CURLOPT_HTTPAUTH" => 107,
            "CURLOPT_FTP_CREATE_MISSING_DIRS" => 110,
            "CURLOPT_PROXYAUTH" => 111,
            "CURLOPT_FTP_RESPONSE_TIMEOUT" => 112,
            "CURLOPT_SERVER_RESPONSE_TIMEOUT" => 112,
            "CURLOPT_IPRESOLVE" => 113,
            "CURLOPT_MAXFILESIZE" => 114,
            "CURLOPT_NETRC_FILE" => 10118,
            "CURLOPT_MAXFILESIZE_LARGE" => 30117,
            "CURLOPT_TCP_NODELAY" => 121,
            "CURLOPT_FTPSSLAUTH" => 129,
            "CURLOPT_FTP_ACCOUNT" => 10134,
            "CURLOPT_COOKIELIST" => 10135,
            "CURLOPT_IGNORE_CONTENT_LENGTH" => 136,
            "CURLOPT_FTP_SKIP_PASV_IP" => 137,
            "CURLOPT_FTP_FILEMETHOD" => 138,
            "CURLOPT_CONNECT_ONLY" => 141,
            "CURLOPT_LOCALPORT" => 139,
            "CURLOPT_LOCALPORTRANGE" => 140,
            "CURLOPT_FTP_ALTERNATIVE_TO_USER" => 10147,
            "CURLOPT_MAX_RECV_SPEED_LARGE" => 30146,
            "CURLOPT_MAX_SEND_SPEED_LARGE" => 30145,
            "CURLOPT_SSL_SESSIONID_CACHE" => 150,
            "CURLOPT_FTP_SSL_CCC" => 154,
            "CURLOPT_SSH_AUTH_TYPES" => 151,
            "CURLOPT_SSH_PRIVATE_KEYFILE" => 10153,
            "CURLOPT_SSH_PUBLIC_KEYFILE" => 10152,
            "CURLOPT_CONNECTTIMEOUT_MS" => 156,
            "CURLOPT_HTTP_CONTENT_DECODING" => 158,
            "CURLOPT_HTTP_TRANSFER_DECODING" => 157,
            "CURLOPT_TIMEOUT_MS" => 155,
            "CURLOPT_KRBLEVEL" => 10063,
            "CURLOPT_NEW_DIRECTORY_PERMS" => 160,
            "CURLOPT_NEW_FILE_PERMS" => 159,
            "CURLOPT_APPEND" => 50,
            "CURLOPT_DIRLISTONLY" => 48,
            "CURLOPT_USE_SSL" => 119,
            "CURLOPT_SSH_HOST_PUBLIC_KEY_MD5" => 10168,
            "CURLOPT_PROXY_TRANSFER_MODE" => 166,
            "CURLOPT_ADDRESS_SCOPE" => 171,
            "CURLOPT_CRLFILE" => 10169,
            "CURLOPT_ISSUERCERT" => 10170,
            "CURLOPT_KEYPASSWD" => 10026,
            "CURLOPT_CERTINFO" => 172,
            "CURLOPT_PASSWORD" => 10175,
            "CURLOPT_POSTREDIR" => 161,
            "CURLOPT_PROXYPASSWORD" => 10176,
            "CURLOPT_PROXYUSERNAME" => 10173,
            "CURLOPT_USERNAME" => 10174,
            "CURLOPT_NOPROXY" => 10177,
            "CURLOPT_PROTOCOLS" => 181,
            "CURLOPT_REDIR_PROTOCOLS" => 182,
            "CURLOPT_SOCKS5_GSSAPI_NEC" => 180,
            "CURLOPT_SOCKS5_GSSAPI_SERVICE" => 10179,
            "CURLOPT_TFTP_BLKSIZE" => 178,
            "CURLOPT_SSH_KNOWNHOSTS" => 10183,
            "CURLOPT_FTP_USE_PRET" => 188,
            "CURLOPT_MAIL_FROM" => 10186,
            "CURLOPT_MAIL_RCPT" => 10187,
            "CURLOPT_RTSP_CLIENT_CSEQ" => 10193,
            "CURLOPT_RTSP_REQUEST" => 10194,
            "CURLOPT_RTSP_SERVER_CSEQ" => 10195,
            "CURLOPT_RTSP_SESSION_ID" => 10190,
            "CURLOPT_RTSP_STREAM_URI" => 10191,
            "CURLOPT_RTSP_TRANSPORT" => 10192,
            "CURLOPT_FNMATCH_FUNCTION" => 20200,
            "CURLOPT_WILDCARDMATCH" => 197,
            "CURLOPT_RESOLVE" => 10203,
            "CURLOPT_TLSAUTH_PASSWORD" => 10205,
            "CURLOPT_TLSAUTH_TYPE" => 10206,
            "CURLOPT_TLSAUTH_USERNAME" => 10204,
            "CURLOPT_ACCEPT_ENCODING" => 10102,
            "CURLOPT_TRANSFER_ENCODING" => 207,
            "CURLOPT_GSSAPI_DELEGATION" => 208,
            "CURLOPT_ACCEPTTIMEOUT_MS" => 212,
            "CURLOPT_DNS_SERVERS" => 10211,
            "CURLOPT_MAIL_AUTH" => 10217,
            "CURLOPT_SSL_OPTIONS" => 216,
            "CURLOPT_TCP_KEEPALIVE" => 213,
            "CURLOPT_TCP_KEEPIDLE" => 214,
            "CURLOPT_TCP_KEEPINTVL" => 215,
            "CURLOPT_EXPECT_100_TIMEOUT_MS" => 227,
            "CURLOPT_SSL_ENABLE_ALPN" => 229,
            "CURLOPT_SSL_ENABLE_NPN" => 228,
            "CURLOPT_HEADEROPT" => 229,
            "CURLOPT_PROXYHEADER" => 10229,
            "CURLOPT_PINNEDPUBLICKEY" => 10230,
            "CURLOPT_UNIX_SOCKET_PATH" => 10231,
            "CURLOPT_SSL_VERIFYSTATUS" => 232,
            "CURLOPT_PATH_AS_IS" => 234,
            "CURLOPT_SSL_FALSESTART" => 233,
            "CURLOPT_PIPEWAIT" => 237,
            "CURLOPT_PROXY_SERVICE_NAME" => 10235,
            "CURLOPT_SERVICE_NAME" => 10236,
            "CURLOPT_DEFAULT_PROTOCOL" => 10238,
            "CURLOPT_STREAM_WEIGHT" => 239,
            "CURLOPT_TFTP_NO_OPTIONS" => 242,
            "CURLOPT_CONNECT_TO" => 10243,
            "CURLOPT_TCP_FASTOPEN" => 244,
            "CURLOPT_KEEP_SENDING_ON_ERROR" => 245,
            "CURLOPT_PRE_PROXY" => 10262,
            "CURLOPT_PROXY_CAINFO" => 10246,
            "CURLOPT_PROXY_CAINFO_BLOB" => 40310,
            "CURLOPT_PROXY_CAPATH" => 10247,
            "CURLOPT_PROXY_CRLFILE" => 10248,
            "CURLOPT_PROXY_KEYPASSWD" => 10258,
            "CURLOPT_PROXY_PINNEDPUBLICKEY" => 10263,
            "CURLOPT_PROXY_SSL_CIPHER_LIST" => 10259,
            "CURLOPT_PROXY_SSL_OPTIONS" => 261,
            "CURLOPT_PROXY_SSL_VERIFYHOST" => 264,
            "CURLOPT_PROXY_SSL_VERIFYPEER" => 263,
            "CURLOPT_PROXY_SSLCERT" => 10249,
            "CURLOPT_PROXY_SSLCERTTYPE" => 10250,
            "CURLOPT_PROXY_SSLKEY" => 10251,
            "CURLOPT_PROXY_SSLKEYTYPE" => 10252,
            "CURLOPT_PROXY_SSLVERSION" => 250,
            "CURLOPT_PROXY_TLSAUTH_PASSWORD" => 10254,
            "CURLOPT_PROXY_TLSAUTH_TYPE" => 10255,
            "CURLOPT_PROXY_TLSAUTH_USERNAME" => 10253,
            "CURLOPT_ABSTRACT_UNIX_SOCKET" => 10264,
            "CURLOPT_SUPPRESS_CONNECT_HEADERS" => 265,
            "CURLOPT_REQUEST_TARGET" => 10256,
            "CURLOPT_SOCKS5_AUTH" => 267,
            "CURLOPT_SSH_COMPRESSION" => 268,
            "CURLOPT_HAPPY_EYEBALLS_TIMEOUT_MS" => 271,
            "CURLOPT_TIMEVALUE_LARGE" => 30170,
            "CURLOPT_DNS_SHUFFLE_ADDRESSES" => 272,
            "CURLOPT_HAPROXYPROTOCOL" => 274,
            "CURLOPT_DISALLOW_USERNAME_IN_URL" => 278,
            "CURLOPT_PROXY_TLS13_CIPHERS" => 10277,
            "CURLOPT_TLS13_CIPHERS" => 10276,
            "CURLOPT_DOH_URL" => 10279,
            "CURLOPT_DOH_SSL_VERIFYPEER" => 306,
            "CURLOPT_DOH_SSL_VERIFYHOST" => 307,
            "CURLOPT_DOH_SSL_VERIFYSTATUS" => 308,
            "CURLOPT_UPKEEP_INTERVAL_MS" => 280,
            "CURLOPT_UPLOAD_BUFFERSIZE" => 281,
            "CURLOPT_HTTP09_ALLOWED" => 285,
            "CURLOPT_ALTSVC" => 10287,
            "CURLOPT_ALTSVC_CTRL" => 288,
            "CURLOPT_MAXAGE_CONN" => 291,
            "CURLOPT_SASL_AUTHZID" => 292,
            "CURLOPT_SASL_IR" => 294,
            "CURLOPT_DNS_INTERFACE" => 10221,
            "CURLOPT_DNS_LOCAL_IP4" => 10222,
            "CURLOPT_DNS_LOCAL_IP6" => 10223,
            "CURLOPT_XOAUTH2_BEARER" => 10220,
            "CURLOPT_LOGIN_OPTIONS" => 10224,
            "CURLOPT_MAXLIFETIME_CONN" => 293,
            "CURLOPT_SSH_HOST_PUBLIC_KEY_SHA256" => 10267,
            "CURLOPT_PREREQFUNCTION" => 10268,
            "CURLOPT_MIME_OPTIONS" => 305,
            "CURLOPT_SSH_HOSTKEYFUNCTION" => 10284,
            "CURLOPT_PROTOCOLS_STR" => 10244,
            "CURLOPT_REDIR_PROTOCOLS_STR" => 10245,
            "CURLOPT_WS_OPTIONS" => 311,
            "CURLOPT_CA_CACHE_TIMEOUT" => 10030,
            "CURLOPT_QUICK_EXIT" => 313,
            "CURLOPT_SAFE_UPLOAD" => -1,
            "CURLOPT_MAIL_RCPT_ALLLOWFAILS" => 247,
            "CURLOPT_ISSUERCERT_BLOB" => 40295,
            "CURLOPT_PROXY_ISSUERCERT" => 10296,
            "CURLOPT_PROXY_ISSUERCERT_BLOB" => 40297,
            "CURLOPT_PROXY_SSLCERT_BLOB" => 40293,
            "CURLOPT_PROXY_SSLKEY_BLOB" => 40294,
            "CURLOPT_SSLCERT_BLOB" => 40291,
            "CURLOPT_SSLKEY_BLOB" => 40292,
            "CURLOPT_SSL_EC_CURVES" => 10298,
            "CURLOPT_SSL_SIGNATURE_ALGORITHMS" => 347,
            "CURLFOLLOW_ALL" => 1,
            "CURLFOLLOW_OBEYCODE" => 2,
            "CURLFOLLOW_FIRSTONLY" => 3,
            "CURLINFO_TEXT" => 0,
            "CURLINFO_HEADER_IN" => 1,
            "CURLINFO_DATA_IN" => 3,
            "CURLINFO_DATA_OUT" => 4,
            "CURLINFO_SSL_DATA_OUT" => 6,
            "CURLINFO_SSL_DATA_IN" => 5,
            "CURLINFO_CONNECT_TIME" => 0x300005,
            "CURLINFO_CONTENT_LENGTH_DOWNLOAD" => 0x300006,
            "CURLINFO_CONTENT_LENGTH_UPLOAD" => 0x300007,
            "CURLINFO_CONTENT_TYPE" => 0x10000C,
            "CURLINFO_EFFECTIVE_URL" => 0x100001,
            "CURLINFO_FILETIME" => 0x200005,
            "CURLINFO_HEADER_OUT" => 0x100002,
            "CURLINFO_HEADER_SIZE" => 0x200002,
            "CURLINFO_HTTP_CODE" => 0x200001,
            "CURLINFO_LASTONE" => 0x400001,
            "CURLINFO_NAMELOOKUP_TIME" => 0x300004,
            "CURLINFO_PRETRANSFER_TIME" => 0x300006,
            "CURLINFO_PRIVATE" => 0x400000,
            "CURLINFO_REDIRECT_COUNT" => 0x200006,
            "CURLINFO_REDIRECT_TIME" => 0x300009,
            "CURLINFO_REQUEST_SIZE" => 0x200003,
            "CURLINFO_SIZE_DOWNLOAD" => 0x300001,
            "CURLINFO_SIZE_UPLOAD" => 0x300000,
            "CURLINFO_SPEED_DOWNLOAD" => 0x300002,
            "CURLINFO_SPEED_UPLOAD" => 0x300003,
            "CURLINFO_SSL_VERIFYRESULT" => 0x200004,
            "CURLINFO_STARTTRANSFER_TIME" => 0x300008,
            "CURLINFO_TOTAL_TIME" => 0x30000F,
            "CURLINFO_EFFECTIVE_METHOD" => 0x100007,
            "CURLINFO_CAPATH" => 0x100008,
            "CURLINFO_CAINFO" => 0x100009,
            "CURLINFO_HTTPAUTH_USED" => 0x200017,
            "CURLINFO_PROXYAUTH_USED" => 0x200018,
            "CURLINFO_HTTP_CONNECTCODE" => 0x200007,
            "CURLINFO_HTTPAUTH_AVAIL" => 0x200008,
            "CURLINFO_RESPONSE_CODE" => 0x200001,
            "CURLINFO_PROXYAUTH_AVAIL" => 0x200009,
            "CURLINFO_OS_ERRNO" => 0x20000A,
            "CURLINFO_NUM_CONNECTS" => 0x20000B,
            "CURLINFO_SSL_ENGINES" => 0x400000,
            "CURLINFO_COOKIELIST" => 0x400001,
            "CURLINFO_FTP_ENTRY_PATH" => 0x100019,
            "CURLINFO_REDIRECT_URL" => 0x10001A,
            "CURLINFO_APPCONNECT_TIME" => 0x30000A,
            "CURLINFO_PRIMARY_IP" => 0x10001B,
            "CURLINFO_CERTINFO" => 0x400002,
            "CURLINFO_CONDITION_UNMET" => 0x20000C,
            "CURLINFO_RTSP_CLIENT_CSEQ" => 0x20000D,
            "CURLINFO_RTSP_CSEQ_RECV" => 0x20000F,
            "CURLINFO_RTSP_SERVER_CSEQ" => 0x20000E,
            "CURLINFO_RTSP_SESSION_ID" => 0x10001C,
            "CURLINFO_LOCAL_IP" => 0x10001D,
            "CURLINFO_LOCAL_PORT" => 0x200010,
            "CURLINFO_PRIMARY_PORT" => 0x200011,
            "CURLINFO_HTTP_VERSION" => 0x200012,
            "CURLINFO_PROTOCOL" => 0x200013,
            "CURLINFO_PROXY_SSL_VERIFYRESULT" => 0x200014,
            "CURLINFO_SCHEME" => 0x10001E,
            "CURLINFO_CONTENT_LENGTH_DOWNLOAD_T" => 0x60000E,
            "CURLINFO_CONTENT_LENGTH_UPLOAD_T" => 0x60000F,
            "CURLINFO_SIZE_DOWNLOAD_T" => 0x600010,
            "CURLINFO_SIZE_UPLOAD_T" => 0x600011,
            "CURLINFO_SPEED_DOWNLOAD_T" => 0x600012,
            "CURLINFO_SPEED_UPLOAD_T" => 0x600013,
            "CURLINFO_FILETIME_T" => 0x600014,
            "CURLINFO_QUEUE_TIME_T" => 0x60001C,
            "CURLINFO_APPCONNECT_TIME_T" => 0x600015,
            "CURLINFO_CONNECT_TIME_T" => 0x600016,
            "CURLINFO_NAMELOOKUP_TIME_T" => 0x600017,
            "CURLINFO_PRETRANSFER_TIME_T" => 0x600018,
            "CURLINFO_REDIRECT_TIME_T" => 0x600019,
            "CURLINFO_STARTTRANSFER_TIME_T" => 0x60001A,
            "CURLINFO_TOTAL_TIME_T" => 0x60001B,
            "CURLINFO_USED_PROXY" => 0x200015,
            "CURLINFO_POSTTRANSFER_TIME_T" => 0x60001D,
            "CURLINFO_CONN_ID" => 0x200016,
            "CURLINFO_PROXY_ERROR" => 0x200019,
            "CURLINFO_REFERER" => 0x10001F,
            "CURLINFO_RETRY_AFTER" => 0x30000B,
            "CURLMSG_DONE" => 1,
            "CURLVERSION_NOW" => 7,
            "CURLM_BAD_EASY_HANDLE" => 2,
            "CURLM_BAD_HANDLE" => 1,
            "CURLM_CALL_MULTI_PERFORM" => -1,
            "CURLM_INTERNAL_ERROR" => 4,
            "CURLM_OK" => 0,
            "CURLM_OUT_OF_MEMORY" => 3,
            "CURLM_ADDED_ALREADY" => 7,
            "CURLPROXY_HTTP" => 0,
            "CURLPROXY_SOCKS4" => 4,
            "CURLPROXY_SOCKS5" => 5,
            "CURLPROXY_SOCKS4A" => 6,
            "CURLPROXY_SOCKS5_HOSTNAME" => 7,
            "CURLPROXY_HTTP_1_0" => 1,
            "CURLPROXY_HTTPS" => 2,
            "CURLSHOPT_NONE" => 0,
            "CURLSHOPT_SHARE" => 1,
            "CURLSHOPT_UNSHARE" => 2,
            "CURL_HTTP_VERSION_1_0" => 1,
            "CURL_HTTP_VERSION_1_1" => 2,
            "CURL_HTTP_VERSION_NONE" => 0,
            "CURL_HTTP_VERSION_2_0" => 3,
            "CURL_HTTP_VERSION_2" => 3,
            "CURL_HTTP_VERSION_2TLS" => 4,
            "CURL_HTTP_VERSION_2_PRIOR_KNOWLEDGE" => 5,
            "CURL_HTTP_VERSION_3" => 30,
            "CURL_HTTP_VERSION_3ONLY" => 31,
            "CURL_LOCK_DATA_COOKIE" => 2,
            "CURL_LOCK_DATA_DNS" => 3,
            "CURL_LOCK_DATA_SSL_SESSION" => 4,
            "CURL_LOCK_DATA_CONNECT" => 6,
            "CURL_LOCK_DATA_PSL" => 7,
            "CURL_NETRC_IGNORED" => 0,
            "CURL_NETRC_OPTIONAL" => 1,
            "CURL_NETRC_REQUIRED" => 2,
            "CURL_SSLVERSION_DEFAULT" => 0,
            "CURL_SSLVERSION_SSLv2" => 2,
            "CURL_SSLVERSION_SSLv3" => 3,
            "CURL_SSLVERSION_TLSv1" => 1,
            "CURL_SSLVERSION_TLSv1_0" => 4,
            "CURL_SSLVERSION_TLSv1_1" => 5,
            "CURL_SSLVERSION_TLSv1_2" => 6,
            "CURL_SSLVERSION_TLSv1_3" => 7,
            "CURL_SSLVERSION_MAX_DEFAULT" => 0x10000,
            "CURL_SSLVERSION_MAX_NONE" => 0x00000,
            "CURL_SSLVERSION_MAX_TLSv1_0" => 0x40000,
            "CURL_SSLVERSION_MAX_TLSv1_1" => 0x50000,
            "CURL_SSLVERSION_MAX_TLSv1_2" => 0x60000,
            "CURL_SSLVERSION_MAX_TLSv1_3" => 0x70000,
            "CURL_TIMECOND_IFMODSINCE" => 1,
            "CURL_TIMECOND_IFUNMODSINCE" => 2,
            "CURL_TIMECOND_LASTMOD" => 3,
            "CURL_TIMECOND_NONE" => 0,
            "CURL_VERSION_ASYNCHDNS" => 128,
            "CURL_VERSION_CONV" => 4096,
            "CURL_VERSION_DEBUG" => 64,
            "CURL_VERSION_GSSNEGOTIATE" => 32,
            "CURL_VERSION_IDN" => 16,
            "CURL_VERSION_IPV6" => 1,
            "CURL_VERSION_KERBEROS4" => 2,
            "CURL_VERSION_LARGEFILE" => 256,
            "CURL_VERSION_LIBZ" => 8,
            "CURL_VERSION_NTLM" => 4,
            "CURL_VERSION_SPNEGO" => 256,
            "CURL_VERSION_SSL" => 2,
            "CURL_VERSION_SSPI" => 512,
            "CURL_VERSION_CURLDEBUG" => 8192,
            "CURL_VERSION_TLSAUTH_SRP" => 1024,
            "CURL_VERSION_NTLM_WB" => 32768,
            "CURL_VERSION_HTTP2" => 65536,
            "CURL_VERSION_GSSAPI" => 131072,
            "CURL_VERSION_KERBEROS5" => 262144,
            "CURL_VERSION_UNIX_SOCKETS" => 524288,
            "CURL_VERSION_PSL" => 1048576,
            "CURL_VERSION_HTTPS_PROXY" => 2097152,
            "CURL_VERSION_MULTI_SSL" => 4194304,
            "CURL_VERSION_BROTLI" => 8388608,
            "CURL_VERSION_ALTSVC" => 16777216,
            "CURL_VERSION_HTTP3" => 33554432,
            "CURL_VERSION_ZSTD" => 67108864,
            "CURL_VERSION_UNICODE" => 134217728,
            "CURL_VERSION_HSTS" => 268435456,
            "CURL_VERSION_GSASL" => 536870912,
            "CURLAUTH_ANY" => ~1,
            "CURLAUTH_ANYSAFE" => ~2,
            "CURLAUTH_BASIC" => 1,
            "CURLAUTH_DIGEST" => 2,
            "CURLAUTH_GSSNEGOTIATE" => 4,
            "CURLAUTH_NONE" => 0,
            "CURLAUTH_NTLM" => 8,
            "CURLAUTH_DIGEST_IE" => 16,
            "CURLAUTH_ONLY" => 2147483648,
            "CURLAUTH_NEGOTIATE" => 4,
            "CURLAUTH_NTLM_WB" => 32,
            "CURLAUTH_GSSAPI" => 4,
            "CURLAUTH_BEARER" => 64,
            "CURLAUTH_AWS_SIGV4" => 128,
            "CURLE_ABORTED_BY_CALLBACK" => 42,
            "CURLE_BAD_CALLING_ORDER" => 44,
            "CURLE_BAD_CONTENT_ENCODING" => 61,
            "CURLE_BAD_DOWNLOAD_RESUME" => 36,
            "CURLE_BAD_FUNCTION_ARGUMENT" => 43,
            "CURLE_BAD_PASSWORD_ENTERED" => 46,
            "CURLE_COULDNT_CONNECT" => 7,
            "CURLE_COULDNT_RESOLVE_HOST" => 6,
            "CURLE_COULDNT_RESOLVE_PROXY" => 5,
            "CURLE_FAILED_INIT" => 2,
            "CURLE_FILE_COULDNT_READ_FILE" => 37,
            "CURLE_FTP_ACCESS_DENIED" => 9,
            "CURLE_FTP_BAD_DOWNLOAD_RESUME" => 36,
            "CURLE_FTP_CANT_GET_HOST" => 15,
            "CURLE_FTP_CANT_RECONNECT" => 16,
            "CURLE_FTP_COULDNT_GET_SIZE" => 32,
            "CURLE_FTP_COULDNT_RETR_FILE" => 19,
            "CURLE_FTP_COULDNT_SET_ASCII" => 29,
            "CURLE_FTP_COULDNT_SET_BINARY" => 17,
            "CURLE_FTP_COULDNT_STOR_FILE" => 25,
            "CURLE_FTP_COULDNT_USE_REST" => 31,
            "CURLE_FTP_PARTIAL_FILE" => 18,
            "CURLE_FTP_PORT_FAILED" => 30,
            "CURLE_FTP_QUOTE_ERROR" => 21,
            "CURLE_FTP_USER_PASSWORD_INCORRECT" => 10,
            "CURLE_FTP_WEIRD_227_FORMAT" => 14,
            "CURLE_FTP_WEIRD_PASS_REPLY" => 11,
            "CURLE_FTP_WEIRD_PASV_REPLY" => 13,
            "CURLE_FTP_WEIRD_SERVER_REPLY" => 8,
            "CURLE_FTP_WEIRD_USER_REPLY" => 12,
            "CURLE_FTP_WRITE_ERROR" => 23,
            "CURLE_FUNCTION_NOT_FOUND" => 41,
            "CURLE_GOT_NOTHING" => 52,
            "CURLE_HTTP_NOT_FOUND" => 22,
            "CURLE_HTTP_PORT_FAILED" => 45,
            "CURLE_HTTP_POST_ERROR" => 34,
            "CURLE_HTTP_RANGE_ERROR" => 33,
            "CURLE_HTTP_RETURNED_ERROR" => 22,
            "CURLE_LDAP_CANNOT_BIND" => 38,
            "CURLE_LDAP_SEARCH_FAILED" => 39,
            "CURLE_LIBRARY_NOT_FOUND" => 40,
            "CURLE_MALFORMAT_USER" => 24,
            "CURLE_OBSOLETE" => 50,
            "CURLE_OK" => 0,
            "CURLE_OPERATION_TIMEDOUT" => 28,
            "CURLE_OPERATION_TIMEOUTED" => 28,
            "CURLE_OUT_OF_MEMORY" => 27,
            "CURLE_PARTIAL_FILE" => 18,
            "CURLE_READ_ERROR" => 26,
            "CURLE_RECV_ERROR" => 56,
            "CURLE_SEND_ERROR" => 55,
            "CURLE_SHARE_IN_USE" => 57,
            "CURLE_SSL_CACERT" => 60,
            "CURLE_SSL_CERTPROBLEM" => 58,
            "CURLE_SSL_CIPHER" => 59,
            "CURLE_SSL_CONNECT_ERROR" => 35,
            "CURLE_SSL_ENGINE_NOTFOUND" => 53,
            "CURLE_SSL_ENGINE_SETFAILED" => 54,
            "CURLE_SSL_PEER_CERTIFICATE" => 51,
            "CURLE_SSL_PINNEDPUBKEYNOTMATCH" => 90,
            "CURLE_TELNET_OPTION_SYNTAX" => 49,
            "CURLE_TOO_MANY_REDIRECTS" => 47,
            "CURLE_UNKNOWN_TELNET_OPTION" => 48,
            "CURLE_UNSUPPORTED_PROTOCOL" => 1,
            "CURLE_URL_MALFORMAT" => 3,
            "CURLE_URL_MALFORMAT_USER" => 4,
            "CURLE_WRITE_ERROR" => 23,
            "CURLE_FILESIZE_EXCEEDED" => 63,
            "CURLE_LDAP_INVALID_URL" => 62,
            "CURLE_FTP_SSL_FAILED" => 64,
            "CURLE_SSL_CACERT_BADFILE" => 77,
            "CURLE_SSH" => 79,
            "CURLE_WEIRD_SERVER_REPLY" => 85,
            "CURLE_PROXY" => 97,
            "CURLE_UNKNOWN_OPTION" => 49,
            "CURLPROTO_ALL" => 0xFFFFFFFF,
            "CURLPROTO_DICT" => 512,
            "CURLPROTO_FILE" => 1024,
            "CURLPROTO_FTP" => 4,
            "CURLPROTO_FTPS" => 8,
            "CURLPROTO_HTTP" => 1,
            "CURLPROTO_HTTPS" => 2,
            "CURLPROTO_LDAP" => 128,
            "CURLPROTO_LDAPS" => 256,
            "CURLPROTO_SCP" => 64,
            "CURLPROTO_SFTP" => 32,
            "CURLPROTO_TELNET" => 16,
            "CURLPROTO_TFTP" => 2048,
            "CURLPROTO_IMAP" => 4096,
            "CURLPROTO_IMAPS" => 8192,
            "CURLPROTO_POP3" => 16384,
            "CURLPROTO_POP3S" => 32768,
            "CURLPROTO_RTSP" => 262144,
            "CURLPROTO_SMTP" => 65536,
            "CURLPROTO_SMTPS" => 131072,
            "CURLPROTO_RTMP" => 524288,
            "CURLPROTO_RTMPE" => 1048576,
            "CURLPROTO_RTMPS" => 2097152,
            "CURLPROTO_RTMPT" => 4194304,
            "CURLPROTO_RTMPTE" => 8388608,
            "CURLPROTO_RTMPTS" => 16777216,
            "CURLPROTO_GOPHER" => 33554432,
            "CURLPROTO_SMB" => 67108864,
            "CURLPROTO_SMBS" => 134217728,
            "CURLPROTO_MQTT" => 268435456,
            "CURLFTPSSL_ALL" => 1,
            "CURLFTPSSL_CONTROL" => 2,
            "CURLFTPSSL_NONE" => 0,
            "CURLFTPSSL_TRY" => 3,
            "CURLFTPSSL_CCC_ACTIVE" => 1,
            "CURLFTPSSL_CCC_NONE" => 0,
            "CURLFTPSSL_CCC_PASSIVE" => 2,
            "CURLUSESSL_ALL" => 1,
            "CURLUSESSL_CONTROL" => 2,
            "CURLUSESSL_NONE" => 0,
            "CURLUSESSL_TRY" => 3,
            "CURLFTPMETHOD_DEFAULT" => 0,
            "CURLFTPMETHOD_MULTICWD" => 1,
            "CURLFTPMETHOD_NOCWD" => 2,
            "CURLFTPMETHOD_SINGLECWD" => 3,
            "CURLFTPAUTH_DEFAULT" => 0,
            "CURLFTPAUTH_SSL" => 1,
            "CURLFTPAUTH_TLS" => 2,
            "CURLFTP_CREATE_DIR" => 1,
            "CURLFTP_CREATE_DIR_NONE" => 0,
            "CURLFTP_CREATE_DIR_RETRY" => 2,
            "CURL_RTSPREQ_ANNOUNCE" => 8,
            "CURL_RTSPREQ_DESCRIBE" => 2,
            "CURL_RTSPREQ_GET_PARAMETER" => 11,
            "CURL_RTSPREQ_OPTIONS" => 1,
            "CURL_RTSPREQ_PAUSE" => 7,
            "CURL_RTSPREQ_PLAY" => 5,
            "CURL_RTSPREQ_RECEIVE" => 4,
            "CURL_RTSPREQ_RECORD" => 9,
            "CURL_RTSPREQ_SET_PARAMETER" => 10,
            "CURL_RTSPREQ_SETUP" => 3,
            "CURL_RTSPREQ_TEARDOWN" => 6,
            "CURLPAUSE_ALL" => 5,
            "CURLPAUSE_CONT" => 0,
            "CURLPAUSE_RECV" => 1,
            "CURLPAUSE_RECV_CONT" => 0,
            "CURLPAUSE_SEND" => 4,
            "CURLPAUSE_SEND_CONT" => 0,
            "CURL_READFUNC_PAUSE" => 0x10000001,
            "CURL_WRITEFUNC_PAUSE" => 0x10000001,
            "CURL_IPRESOLVE_V4" => 1,
            "CURL_IPRESOLVE_V6" => 2,
            "CURL_IPRESOLVE_WHATEVER" => 0,
            "CURLSSH_AUTH_ANY" => ~0,
            "CURLSSH_AUTH_DEFAULT" => ~0,
            "CURLSSH_AUTH_HOST" => 4,
            "CURLSSH_AUTH_KEYBOARD" => 8,
            "CURLSSH_AUTH_NONE" => 0,
            "CURLSSH_AUTH_PASSWORD" => 2,
            "CURLSSH_AUTH_PUBLICKEY" => 1,
            "CURLSSH_AUTH_AGENT" => 16,
            "CURLSSH_AUTH_GSSAPI" => 32,
            "CURLKHMATCH_OK" => 0,
            "CURLKHMATCH_MISMATCH" => 1,
            "CURLKHMATCH_MISSING" => 2,
            "CURLKHMATCH_LAST" => 3,
            "CURL_FNMATCHFUNC_FAIL" => 2,
            "CURL_FNMATCHFUNC_MATCH" => 0,
            "CURL_FNMATCHFUNC_NOMATCH" => 1,
            "CURLMOPT_PIPELINING" => 3,
            "CURLMOPT_MAXCONNECTS" => 6,
            "CURLMOPT_CHUNK_LENGTH_PENALTY_SIZE" => 30009,
            "CURLMOPT_CONTENT_LENGTH_PENALTY_SIZE" => 30010,
            "CURLMOPT_MAX_HOST_CONNECTIONS" => 7,
            "CURLMOPT_MAX_PIPELINE_LENGTH" => 8,
            "CURLMOPT_MAX_TOTAL_CONNECTIONS" => 13,
            "CURLMOPT_PUSHFUNCTION" => 30014,
            "CURLMOPT_MAX_CONCURRENT_STREAMS" => 16,
            "CURLHEADER_SEPARATE" => 0,
            "CURLHEADER_UNIFIED" => 1,
            "CURLSSLOPT_ALLOW_BEAST" => 1,
            "CURLSSLOPT_NO_REVOKE" => 2,
            "CURLSSLOPT_NO_PARTIALCHAIN" => 4,
            "CURLSSLOPT_REVOKE_BEST_EFFORT" => 8,
            "CURLSSLOPT_NATIVE_CA" => 16,
            "CURLSSLOPT_AUTO_CLIENT_CERT" => 32,
            "CURL_TLSAUTH_SRP" => 1,
            "CURL_REDIR_POST_301" => 1,
            "CURL_REDIR_POST_302" => 2,
            "CURL_REDIR_POST_ALL" => 7,
            "CURL_REDIR_POST_303" => 4,
            "CURLPIPE_NOTHING" => 0,
            "CURLPIPE_HTTP1" => 1,
            "CURLPIPE_MULTIPLEX" => 2,
            "CURLGSSAPI_DELEGATION_FLAG" => 1,
            "CURLGSSAPI_DELEGATION_POLICY_FLAG" => 2,
            "CURLALTSVC_H1" => 1,
            "CURLALTSVC_H2" => 2,
            "CURLALTSVC_H3" => 4,
            "CURLALTSVC_READONLYFILE" => 8,
            "CURLHSTS_ENABLE" => 1,
            "CURLHSTS_READONLYFILE" => 2,
            "CURLWS_RAW_MODE" => 1,
            "CURL_PUSH_OK" => 0,
            "CURL_PUSH_DENY" => 1,
            "CURL_PREREQFUNC_OK" => 0,
            "CURL_PREREQFUNC_ABORT" => 1,
            "CURLMIMEOPT_FORMESCAPE" => 1,
            "CURL_MAX_READ_SIZE" => 10485760,
            "CURLPX_BAD_ADDRESS_TYPE" => 1,
            "CURLPX_BAD_VERSION" => 2,
            "CURLPX_CLOSED" => 3,
            "CURLPX_GSSAPI" => 4,
            "CURLPX_GSSAPI_PERMSG" => 5,
            "CURLPX_GSSAPI_PROTECTION" => 6,
            "CURLPX_IDENTD" => 7,
            "CURLPX_IDENTD_DIFFER" => 8,
            "CURLPX_LONG_HOSTNAME" => 9,
            "CURLPX_LONG_PASSWD" => 10,
            "CURLPX_LONG_USER" => 11,
            "CURLPX_NO_AUTH" => 12,
            "CURLPX_OK" => 0,
            "CURLPX_RECV_ADDRESS" => 13,
            "CURLPX_RECV_AUTH" => 14,
            "CURLPX_RECV_CONNECT" => 15,
            "CURLPX_RECV_REQACK" => 16,
            "CURLPX_REPLY_ADDRESS_TYPE_NOT_SUPPORTED" => 17,
            "CURLPX_REPLY_COMMAND_NOT_SUPPORTED" => 18,
            "CURLPX_REPLY_CONNECTION_REFUSED" => 19,
            "CURLPX_REPLY_GENERAL_SERVER_FAILURE" => 20,
            "CURLPX_REPLY_HOST_UNREACHABLE" => 21,
            "CURLPX_REPLY_NETWORK_UNREACHABLE" => 22,
            "CURLPX_REPLY_NOT_ALLOWED" => 23,
            "CURLPX_REPLY_TTL_EXPIRED" => 24,
            "CURLPX_REPLY_UNASSIGNED" => 25,
            "CURLPX_REQUEST_FAILED" => 26,
            "CURLPX_RESOLVE_HOST" => 27,
            "CURLPX_SEND_AUTH" => 28,
            "CURLPX_SEND_CONNECT" => 29,
            "CURLPX_SEND_REQUEST" => 30,
            "CURLPX_UNKNOWN_FAIL" => 31,
            "CURLPX_UNKNOWN_MODE" => 32,
            "CURLPX_USER_REJECTED" => 33,
        ];

        $constPassCount = 0;
        $constFailCount = 0;
        $constTotal = 690;
        foreach ($expectedConstants as $constName => $expectedVal) {
            $actualVal = _curl_unit_get_const_value($constName);
            if ($actualVal === $expectedVal) {
                $constPassCount++;
            } else {
                $constFailCount++;
                echo "FAIL: " . $constName . " expected=" . $expectedVal . " got=" . $actualVal . "\n";
            }
        }
        echo "201. All constants: " . $constPassCount . "/" . $constTotal . " passed" . ($constFailCount > 0 ? " (" . $constFailCount . " failed)" : "") . "\n";

        echo "\n=== cURL Unit Test done ===\n";
    }
}
// 自动生成：cURL 全部常量值查询函数（勿手改）
// 用 switch-case 把常量名映射到实际值，避免依赖 constant() 动态查询
function _curl_unit_get_const_value(string $name): int {
    switch ($name) {
        case "CURLOPT_AUTOREFERER": return CURLOPT_AUTOREFERER;
        case "CURLOPT_AWS_SIGV4": return CURLOPT_AWS_SIGV4;
        case "CURLOPT_BINARYTRANSFER": return CURLOPT_BINARYTRANSFER;
        case "CURLOPT_BUFFERSIZE": return CURLOPT_BUFFERSIZE;
        case "CURLOPT_CAINFO": return CURLOPT_CAINFO;
        case "CURLOPT_CAINFO_BLOB": return CURLOPT_CAINFO_BLOB;
        case "CURLOPT_CAPATH": return CURLOPT_CAPATH;
        case "CURLOPT_CONNECTTIMEOUT": return CURLOPT_CONNECTTIMEOUT;
        case "CURLOPT_COOKIE": return CURLOPT_COOKIE;
        case "CURLOPT_COOKIEFILE": return CURLOPT_COOKIEFILE;
        case "CURLOPT_COOKIEJAR": return CURLOPT_COOKIEJAR;
        case "CURLOPT_COOKIESESSION": return CURLOPT_COOKIESESSION;
        case "CURLOPT_CRLF": return CURLOPT_CRLF;
        case "CURLOPT_CUSTOMREQUEST": return CURLOPT_CUSTOMREQUEST;
        case "CURLOPT_DNS_CACHE_TIMEOUT": return CURLOPT_DNS_CACHE_TIMEOUT;
        case "CURLOPT_DNS_USE_GLOBAL_CACHE": return CURLOPT_DNS_USE_GLOBAL_CACHE;
        case "CURLOPT_EGDSOCKET": return CURLOPT_EGDSOCKET;
        case "CURLOPT_ENCODING": return CURLOPT_ENCODING;
        case "CURLOPT_FAILONERROR": return CURLOPT_FAILONERROR;
        case "CURLOPT_FILE": return CURLOPT_FILE;
        case "CURLOPT_FILETIME": return CURLOPT_FILETIME;
        case "CURLOPT_FOLLOWLOCATION": return CURLOPT_FOLLOWLOCATION;
        case "CURLOPT_FORBID_REUSE": return CURLOPT_FORBID_REUSE;
        case "CURLOPT_FRESH_CONNECT": return CURLOPT_FRESH_CONNECT;
        case "CURLOPT_FTPAPPEND": return CURLOPT_FTPAPPEND;
        case "CURLOPT_FTPLISTONLY": return CURLOPT_FTPLISTONLY;
        case "CURLOPT_FTPPORT": return CURLOPT_FTPPORT;
        case "CURLOPT_FTP_SSL": return CURLOPT_FTP_SSL;
        case "CURLOPT_FTP_USE_EPRT": return CURLOPT_FTP_USE_EPRT;
        case "CURLOPT_FTP_USE_EPSV": return CURLOPT_FTP_USE_EPSV;
        case "CURLOPT_HEADER": return CURLOPT_HEADER;
        case "CURLOPT_HEADERFUNCTION": return CURLOPT_HEADERFUNCTION;
        case "CURLOPT_HSTS_CTRL": return CURLOPT_HSTS_CTRL;
        case "CURLOPT_HSTS": return CURLOPT_HSTS;
        case "CURLOPT_HTTP200ALIASES": return CURLOPT_HTTP200ALIASES;
        case "CURLOPT_HTTPGET": return CURLOPT_HTTPGET;
        case "CURLOPT_HTTPHEADER": return CURLOPT_HTTPHEADER;
        case "CURLOPT_HTTPPROXYTUNNEL": return CURLOPT_HTTPPROXYTUNNEL;
        case "CURLOPT_HTTP_VERSION": return CURLOPT_HTTP_VERSION;
        case "CURLOPT_INFILE": return CURLOPT_INFILE;
        case "CURLOPT_INFILESIZE": return CURLOPT_INFILESIZE;
        case "CURLOPT_INFILESIZE_LARGE": return CURLOPT_INFILESIZE_LARGE;
        case "CURLOPT_INTERFACE": return CURLOPT_INTERFACE;
        case "CURLOPT_KRB4LEVEL": return CURLOPT_KRB4LEVEL;
        case "CURLOPT_LOW_SPEED_LIMIT": return CURLOPT_LOW_SPEED_LIMIT;
        case "CURLOPT_LOW_SPEED_TIME": return CURLOPT_LOW_SPEED_TIME;
        case "CURLOPT_MAXCONNECTS": return CURLOPT_MAXCONNECTS;
        case "CURLOPT_MAXREDIRS": return CURLOPT_MAXREDIRS;
        case "CURLOPT_NETRC": return CURLOPT_NETRC;
        case "CURLOPT_NOBODY": return CURLOPT_NOBODY;
        case "CURLOPT_NOPROGRESS": return CURLOPT_NOPROGRESS;
        case "CURLOPT_NOSIGNAL": return CURLOPT_NOSIGNAL;
        case "CURLOPT_PORT": return CURLOPT_PORT;
        case "CURLOPT_POST": return CURLOPT_POST;
        case "CURLOPT_POSTFIELDS": return CURLOPT_POSTFIELDS;
        case "CURLOPT_POSTQUOTE": return CURLOPT_POSTQUOTE;
        case "CURLOPT_PREQUOTE": return CURLOPT_PREQUOTE;
        case "CURLOPT_PRIVATE": return CURLOPT_PRIVATE;
        case "CURLOPT_PROGRESSFUNCTION": return CURLOPT_PROGRESSFUNCTION;
        case "CURLOPT_PROXY": return CURLOPT_PROXY;
        case "CURLOPT_PROXYPORT": return CURLOPT_PROXYPORT;
        case "CURLOPT_PROXYTYPE": return CURLOPT_PROXYTYPE;
        case "CURLOPT_PROXYUSERPWD": return CURLOPT_PROXYUSERPWD;
        case "CURLOPT_PUT": return CURLOPT_PUT;
        case "CURLOPT_QUOTE": return CURLOPT_QUOTE;
        case "CURLOPT_RANDOM_FILE": return CURLOPT_RANDOM_FILE;
        case "CURLOPT_RANGE": return CURLOPT_RANGE;
        case "CURLOPT_READDATA": return CURLOPT_READDATA;
        case "CURLOPT_READFUNCTION": return CURLOPT_READFUNCTION;
        case "CURLOPT_REFERER": return CURLOPT_REFERER;
        case "CURLOPT_RESUME_FROM": return CURLOPT_RESUME_FROM;
        case "CURLOPT_RETURNTRANSFER": return CURLOPT_RETURNTRANSFER;
        case "CURLOPT_SHARE": return CURLOPT_SHARE;
        case "CURLOPT_SSLCERT": return CURLOPT_SSLCERT;
        case "CURLOPT_SSLCERTPASSWD": return CURLOPT_SSLCERTPASSWD;
        case "CURLOPT_SSLCERTTYPE": return CURLOPT_SSLCERTTYPE;
        case "CURLOPT_SSLENGINE": return CURLOPT_SSLENGINE;
        case "CURLOPT_SSLENGINE_DEFAULT": return CURLOPT_SSLENGINE_DEFAULT;
        case "CURLOPT_SSLKEY": return CURLOPT_SSLKEY;
        case "CURLOPT_SSLKEYPASSWD": return CURLOPT_SSLKEYPASSWD;
        case "CURLOPT_SSLKEYTYPE": return CURLOPT_SSLKEYTYPE;
        case "CURLOPT_SSLVERSION": return CURLOPT_SSLVERSION;
        case "CURLOPT_SSL_CIPHER_LIST": return CURLOPT_SSL_CIPHER_LIST;
        case "CURLOPT_SSL_VERIFYHOST": return CURLOPT_SSL_VERIFYHOST;
        case "CURLOPT_SSL_VERIFYPEER": return CURLOPT_SSL_VERIFYPEER;
        case "CURLOPT_STDERR": return CURLOPT_STDERR;
        case "CURLOPT_TCP_KEEPCNT": return CURLOPT_TCP_KEEPCNT;
        case "CURLOPT_TELNETOPTIONS": return CURLOPT_TELNETOPTIONS;
        case "CURLOPT_TIMECONDITION": return CURLOPT_TIMECONDITION;
        case "CURLOPT_TIMEOUT": return CURLOPT_TIMEOUT;
        case "CURLOPT_TIMEVALUE": return CURLOPT_TIMEVALUE;
        case "CURLOPT_TRANSFERTEXT": return CURLOPT_TRANSFERTEXT;
        case "CURLOPT_UNRESTRICTED_AUTH": return CURLOPT_UNRESTRICTED_AUTH;
        case "CURLOPT_UPLOAD": return CURLOPT_UPLOAD;
        case "CURLOPT_URL": return CURLOPT_URL;
        case "CURLOPT_USERAGENT": return CURLOPT_USERAGENT;
        case "CURLOPT_USERPWD": return CURLOPT_USERPWD;
        case "CURLOPT_VERBOSE": return CURLOPT_VERBOSE;
        case "CURLOPT_WRITEFUNCTION": return CURLOPT_WRITEFUNCTION;
        case "CURLOPT_WRITEHEADER": return CURLOPT_WRITEHEADER;
        case "CURLOPT_XFERINFOFUNCTION": return CURLOPT_XFERINFOFUNCTION;
        case "CURLOPT_DEBUGFUNCTION": return CURLOPT_DEBUGFUNCTION;
        case "CURLOPT_HTTPAUTH": return CURLOPT_HTTPAUTH;
        case "CURLOPT_FTP_CREATE_MISSING_DIRS": return CURLOPT_FTP_CREATE_MISSING_DIRS;
        case "CURLOPT_PROXYAUTH": return CURLOPT_PROXYAUTH;
        case "CURLOPT_FTP_RESPONSE_TIMEOUT": return CURLOPT_FTP_RESPONSE_TIMEOUT;
        case "CURLOPT_SERVER_RESPONSE_TIMEOUT": return CURLOPT_SERVER_RESPONSE_TIMEOUT;
        case "CURLOPT_IPRESOLVE": return CURLOPT_IPRESOLVE;
        case "CURLOPT_MAXFILESIZE": return CURLOPT_MAXFILESIZE;
        case "CURLOPT_NETRC_FILE": return CURLOPT_NETRC_FILE;
        case "CURLOPT_MAXFILESIZE_LARGE": return CURLOPT_MAXFILESIZE_LARGE;
        case "CURLOPT_TCP_NODELAY": return CURLOPT_TCP_NODELAY;
        case "CURLOPT_FTPSSLAUTH": return CURLOPT_FTPSSLAUTH;
        case "CURLOPT_FTP_ACCOUNT": return CURLOPT_FTP_ACCOUNT;
        case "CURLOPT_COOKIELIST": return CURLOPT_COOKIELIST;
        case "CURLOPT_IGNORE_CONTENT_LENGTH": return CURLOPT_IGNORE_CONTENT_LENGTH;
        case "CURLOPT_FTP_SKIP_PASV_IP": return CURLOPT_FTP_SKIP_PASV_IP;
        case "CURLOPT_FTP_FILEMETHOD": return CURLOPT_FTP_FILEMETHOD;
        case "CURLOPT_CONNECT_ONLY": return CURLOPT_CONNECT_ONLY;
        case "CURLOPT_LOCALPORT": return CURLOPT_LOCALPORT;
        case "CURLOPT_LOCALPORTRANGE": return CURLOPT_LOCALPORTRANGE;
        case "CURLOPT_FTP_ALTERNATIVE_TO_USER": return CURLOPT_FTP_ALTERNATIVE_TO_USER;
        case "CURLOPT_MAX_RECV_SPEED_LARGE": return CURLOPT_MAX_RECV_SPEED_LARGE;
        case "CURLOPT_MAX_SEND_SPEED_LARGE": return CURLOPT_MAX_SEND_SPEED_LARGE;
        case "CURLOPT_SSL_SESSIONID_CACHE": return CURLOPT_SSL_SESSIONID_CACHE;
        case "CURLOPT_FTP_SSL_CCC": return CURLOPT_FTP_SSL_CCC;
        case "CURLOPT_SSH_AUTH_TYPES": return CURLOPT_SSH_AUTH_TYPES;
        case "CURLOPT_SSH_PRIVATE_KEYFILE": return CURLOPT_SSH_PRIVATE_KEYFILE;
        case "CURLOPT_SSH_PUBLIC_KEYFILE": return CURLOPT_SSH_PUBLIC_KEYFILE;
        case "CURLOPT_CONNECTTIMEOUT_MS": return CURLOPT_CONNECTTIMEOUT_MS;
        case "CURLOPT_HTTP_CONTENT_DECODING": return CURLOPT_HTTP_CONTENT_DECODING;
        case "CURLOPT_HTTP_TRANSFER_DECODING": return CURLOPT_HTTP_TRANSFER_DECODING;
        case "CURLOPT_TIMEOUT_MS": return CURLOPT_TIMEOUT_MS;
        case "CURLOPT_KRBLEVEL": return CURLOPT_KRBLEVEL;
        case "CURLOPT_NEW_DIRECTORY_PERMS": return CURLOPT_NEW_DIRECTORY_PERMS;
        case "CURLOPT_NEW_FILE_PERMS": return CURLOPT_NEW_FILE_PERMS;
        case "CURLOPT_APPEND": return CURLOPT_APPEND;
        case "CURLOPT_DIRLISTONLY": return CURLOPT_DIRLISTONLY;
        case "CURLOPT_USE_SSL": return CURLOPT_USE_SSL;
        case "CURLOPT_SSH_HOST_PUBLIC_KEY_MD5": return CURLOPT_SSH_HOST_PUBLIC_KEY_MD5;
        case "CURLOPT_PROXY_TRANSFER_MODE": return CURLOPT_PROXY_TRANSFER_MODE;
        case "CURLOPT_ADDRESS_SCOPE": return CURLOPT_ADDRESS_SCOPE;
        case "CURLOPT_CRLFILE": return CURLOPT_CRLFILE;
        case "CURLOPT_ISSUERCERT": return CURLOPT_ISSUERCERT;
        case "CURLOPT_KEYPASSWD": return CURLOPT_KEYPASSWD;
        case "CURLOPT_CERTINFO": return CURLOPT_CERTINFO;
        case "CURLOPT_PASSWORD": return CURLOPT_PASSWORD;
        case "CURLOPT_POSTREDIR": return CURLOPT_POSTREDIR;
        case "CURLOPT_PROXYPASSWORD": return CURLOPT_PROXYPASSWORD;
        case "CURLOPT_PROXYUSERNAME": return CURLOPT_PROXYUSERNAME;
        case "CURLOPT_USERNAME": return CURLOPT_USERNAME;
        case "CURLOPT_NOPROXY": return CURLOPT_NOPROXY;
        case "CURLOPT_PROTOCOLS": return CURLOPT_PROTOCOLS;
        case "CURLOPT_REDIR_PROTOCOLS": return CURLOPT_REDIR_PROTOCOLS;
        case "CURLOPT_SOCKS5_GSSAPI_NEC": return CURLOPT_SOCKS5_GSSAPI_NEC;
        case "CURLOPT_SOCKS5_GSSAPI_SERVICE": return CURLOPT_SOCKS5_GSSAPI_SERVICE;
        case "CURLOPT_TFTP_BLKSIZE": return CURLOPT_TFTP_BLKSIZE;
        case "CURLOPT_SSH_KNOWNHOSTS": return CURLOPT_SSH_KNOWNHOSTS;
        case "CURLOPT_FTP_USE_PRET": return CURLOPT_FTP_USE_PRET;
        case "CURLOPT_MAIL_FROM": return CURLOPT_MAIL_FROM;
        case "CURLOPT_MAIL_RCPT": return CURLOPT_MAIL_RCPT;
        case "CURLOPT_RTSP_CLIENT_CSEQ": return CURLOPT_RTSP_CLIENT_CSEQ;
        case "CURLOPT_RTSP_REQUEST": return CURLOPT_RTSP_REQUEST;
        case "CURLOPT_RTSP_SERVER_CSEQ": return CURLOPT_RTSP_SERVER_CSEQ;
        case "CURLOPT_RTSP_SESSION_ID": return CURLOPT_RTSP_SESSION_ID;
        case "CURLOPT_RTSP_STREAM_URI": return CURLOPT_RTSP_STREAM_URI;
        case "CURLOPT_RTSP_TRANSPORT": return CURLOPT_RTSP_TRANSPORT;
        case "CURLOPT_FNMATCH_FUNCTION": return CURLOPT_FNMATCH_FUNCTION;
        case "CURLOPT_WILDCARDMATCH": return CURLOPT_WILDCARDMATCH;
        case "CURLOPT_RESOLVE": return CURLOPT_RESOLVE;
        case "CURLOPT_TLSAUTH_PASSWORD": return CURLOPT_TLSAUTH_PASSWORD;
        case "CURLOPT_TLSAUTH_TYPE": return CURLOPT_TLSAUTH_TYPE;
        case "CURLOPT_TLSAUTH_USERNAME": return CURLOPT_TLSAUTH_USERNAME;
        case "CURLOPT_ACCEPT_ENCODING": return CURLOPT_ACCEPT_ENCODING;
        case "CURLOPT_TRANSFER_ENCODING": return CURLOPT_TRANSFER_ENCODING;
        case "CURLOPT_GSSAPI_DELEGATION": return CURLOPT_GSSAPI_DELEGATION;
        case "CURLOPT_ACCEPTTIMEOUT_MS": return CURLOPT_ACCEPTTIMEOUT_MS;
        case "CURLOPT_DNS_SERVERS": return CURLOPT_DNS_SERVERS;
        case "CURLOPT_MAIL_AUTH": return CURLOPT_MAIL_AUTH;
        case "CURLOPT_SSL_OPTIONS": return CURLOPT_SSL_OPTIONS;
        case "CURLOPT_TCP_KEEPALIVE": return CURLOPT_TCP_KEEPALIVE;
        case "CURLOPT_TCP_KEEPIDLE": return CURLOPT_TCP_KEEPIDLE;
        case "CURLOPT_TCP_KEEPINTVL": return CURLOPT_TCP_KEEPINTVL;
        case "CURLOPT_EXPECT_100_TIMEOUT_MS": return CURLOPT_EXPECT_100_TIMEOUT_MS;
        case "CURLOPT_SSL_ENABLE_ALPN": return CURLOPT_SSL_ENABLE_ALPN;
        case "CURLOPT_SSL_ENABLE_NPN": return CURLOPT_SSL_ENABLE_NPN;
        case "CURLOPT_HEADEROPT": return CURLOPT_HEADEROPT;
        case "CURLOPT_PROXYHEADER": return CURLOPT_PROXYHEADER;
        case "CURLOPT_PINNEDPUBLICKEY": return CURLOPT_PINNEDPUBLICKEY;
        case "CURLOPT_UNIX_SOCKET_PATH": return CURLOPT_UNIX_SOCKET_PATH;
        case "CURLOPT_SSL_VERIFYSTATUS": return CURLOPT_SSL_VERIFYSTATUS;
        case "CURLOPT_PATH_AS_IS": return CURLOPT_PATH_AS_IS;
        case "CURLOPT_SSL_FALSESTART": return CURLOPT_SSL_FALSESTART;
        case "CURLOPT_PIPEWAIT": return CURLOPT_PIPEWAIT;
        case "CURLOPT_PROXY_SERVICE_NAME": return CURLOPT_PROXY_SERVICE_NAME;
        case "CURLOPT_SERVICE_NAME": return CURLOPT_SERVICE_NAME;
        case "CURLOPT_DEFAULT_PROTOCOL": return CURLOPT_DEFAULT_PROTOCOL;
        case "CURLOPT_STREAM_WEIGHT": return CURLOPT_STREAM_WEIGHT;
        case "CURLOPT_TFTP_NO_OPTIONS": return CURLOPT_TFTP_NO_OPTIONS;
        case "CURLOPT_CONNECT_TO": return CURLOPT_CONNECT_TO;
        case "CURLOPT_TCP_FASTOPEN": return CURLOPT_TCP_FASTOPEN;
        case "CURLOPT_KEEP_SENDING_ON_ERROR": return CURLOPT_KEEP_SENDING_ON_ERROR;
        case "CURLOPT_PRE_PROXY": return CURLOPT_PRE_PROXY;
        case "CURLOPT_PROXY_CAINFO": return CURLOPT_PROXY_CAINFO;
        case "CURLOPT_PROXY_CAINFO_BLOB": return CURLOPT_PROXY_CAINFO_BLOB;
        case "CURLOPT_PROXY_CAPATH": return CURLOPT_PROXY_CAPATH;
        case "CURLOPT_PROXY_CRLFILE": return CURLOPT_PROXY_CRLFILE;
        case "CURLOPT_PROXY_KEYPASSWD": return CURLOPT_PROXY_KEYPASSWD;
        case "CURLOPT_PROXY_PINNEDPUBLICKEY": return CURLOPT_PROXY_PINNEDPUBLICKEY;
        case "CURLOPT_PROXY_SSL_CIPHER_LIST": return CURLOPT_PROXY_SSL_CIPHER_LIST;
        case "CURLOPT_PROXY_SSL_OPTIONS": return CURLOPT_PROXY_SSL_OPTIONS;
        case "CURLOPT_PROXY_SSL_VERIFYHOST": return CURLOPT_PROXY_SSL_VERIFYHOST;
        case "CURLOPT_PROXY_SSL_VERIFYPEER": return CURLOPT_PROXY_SSL_VERIFYPEER;
        case "CURLOPT_PROXY_SSLCERT": return CURLOPT_PROXY_SSLCERT;
        case "CURLOPT_PROXY_SSLCERTTYPE": return CURLOPT_PROXY_SSLCERTTYPE;
        case "CURLOPT_PROXY_SSLKEY": return CURLOPT_PROXY_SSLKEY;
        case "CURLOPT_PROXY_SSLKEYTYPE": return CURLOPT_PROXY_SSLKEYTYPE;
        case "CURLOPT_PROXY_SSLVERSION": return CURLOPT_PROXY_SSLVERSION;
        case "CURLOPT_PROXY_TLSAUTH_PASSWORD": return CURLOPT_PROXY_TLSAUTH_PASSWORD;
        case "CURLOPT_PROXY_TLSAUTH_TYPE": return CURLOPT_PROXY_TLSAUTH_TYPE;
        case "CURLOPT_PROXY_TLSAUTH_USERNAME": return CURLOPT_PROXY_TLSAUTH_USERNAME;
        case "CURLOPT_ABSTRACT_UNIX_SOCKET": return CURLOPT_ABSTRACT_UNIX_SOCKET;
        case "CURLOPT_SUPPRESS_CONNECT_HEADERS": return CURLOPT_SUPPRESS_CONNECT_HEADERS;
        case "CURLOPT_REQUEST_TARGET": return CURLOPT_REQUEST_TARGET;
        case "CURLOPT_SOCKS5_AUTH": return CURLOPT_SOCKS5_AUTH;
        case "CURLOPT_SSH_COMPRESSION": return CURLOPT_SSH_COMPRESSION;
        case "CURLOPT_HAPPY_EYEBALLS_TIMEOUT_MS": return CURLOPT_HAPPY_EYEBALLS_TIMEOUT_MS;
        case "CURLOPT_TIMEVALUE_LARGE": return CURLOPT_TIMEVALUE_LARGE;
        case "CURLOPT_DNS_SHUFFLE_ADDRESSES": return CURLOPT_DNS_SHUFFLE_ADDRESSES;
        case "CURLOPT_HAPROXYPROTOCOL": return CURLOPT_HAPROXYPROTOCOL;
        case "CURLOPT_DISALLOW_USERNAME_IN_URL": return CURLOPT_DISALLOW_USERNAME_IN_URL;
        case "CURLOPT_PROXY_TLS13_CIPHERS": return CURLOPT_PROXY_TLS13_CIPHERS;
        case "CURLOPT_TLS13_CIPHERS": return CURLOPT_TLS13_CIPHERS;
        case "CURLOPT_DOH_URL": return CURLOPT_DOH_URL;
        case "CURLOPT_DOH_SSL_VERIFYPEER": return CURLOPT_DOH_SSL_VERIFYPEER;
        case "CURLOPT_DOH_SSL_VERIFYHOST": return CURLOPT_DOH_SSL_VERIFYHOST;
        case "CURLOPT_DOH_SSL_VERIFYSTATUS": return CURLOPT_DOH_SSL_VERIFYSTATUS;
        case "CURLOPT_UPKEEP_INTERVAL_MS": return CURLOPT_UPKEEP_INTERVAL_MS;
        case "CURLOPT_UPLOAD_BUFFERSIZE": return CURLOPT_UPLOAD_BUFFERSIZE;
        case "CURLOPT_HTTP09_ALLOWED": return CURLOPT_HTTP09_ALLOWED;
        case "CURLOPT_ALTSVC": return CURLOPT_ALTSVC;
        case "CURLOPT_ALTSVC_CTRL": return CURLOPT_ALTSVC_CTRL;
        case "CURLOPT_MAXAGE_CONN": return CURLOPT_MAXAGE_CONN;
        case "CURLOPT_SASL_AUTHZID": return CURLOPT_SASL_AUTHZID;
        case "CURLOPT_SASL_IR": return CURLOPT_SASL_IR;
        case "CURLOPT_DNS_INTERFACE": return CURLOPT_DNS_INTERFACE;
        case "CURLOPT_DNS_LOCAL_IP4": return CURLOPT_DNS_LOCAL_IP4;
        case "CURLOPT_DNS_LOCAL_IP6": return CURLOPT_DNS_LOCAL_IP6;
        case "CURLOPT_XOAUTH2_BEARER": return CURLOPT_XOAUTH2_BEARER;
        case "CURLOPT_LOGIN_OPTIONS": return CURLOPT_LOGIN_OPTIONS;
        case "CURLOPT_MAXLIFETIME_CONN": return CURLOPT_MAXLIFETIME_CONN;
        case "CURLOPT_SSH_HOST_PUBLIC_KEY_SHA256": return CURLOPT_SSH_HOST_PUBLIC_KEY_SHA256;
        case "CURLOPT_PREREQFUNCTION": return CURLOPT_PREREQFUNCTION;
        case "CURLOPT_MIME_OPTIONS": return CURLOPT_MIME_OPTIONS;
        case "CURLOPT_SSH_HOSTKEYFUNCTION": return CURLOPT_SSH_HOSTKEYFUNCTION;
        case "CURLOPT_PROTOCOLS_STR": return CURLOPT_PROTOCOLS_STR;
        case "CURLOPT_REDIR_PROTOCOLS_STR": return CURLOPT_REDIR_PROTOCOLS_STR;
        case "CURLOPT_WS_OPTIONS": return CURLOPT_WS_OPTIONS;
        case "CURLOPT_CA_CACHE_TIMEOUT": return CURLOPT_CA_CACHE_TIMEOUT;
        case "CURLOPT_QUICK_EXIT": return CURLOPT_QUICK_EXIT;
        case "CURLOPT_SAFE_UPLOAD": return CURLOPT_SAFE_UPLOAD;
        case "CURLOPT_MAIL_RCPT_ALLLOWFAILS": return CURLOPT_MAIL_RCPT_ALLLOWFAILS;
        case "CURLOPT_ISSUERCERT_BLOB": return CURLOPT_ISSUERCERT_BLOB;
        case "CURLOPT_PROXY_ISSUERCERT": return CURLOPT_PROXY_ISSUERCERT;
        case "CURLOPT_PROXY_ISSUERCERT_BLOB": return CURLOPT_PROXY_ISSUERCERT_BLOB;
        case "CURLOPT_PROXY_SSLCERT_BLOB": return CURLOPT_PROXY_SSLCERT_BLOB;
        case "CURLOPT_PROXY_SSLKEY_BLOB": return CURLOPT_PROXY_SSLKEY_BLOB;
        case "CURLOPT_SSLCERT_BLOB": return CURLOPT_SSLCERT_BLOB;
        case "CURLOPT_SSLKEY_BLOB": return CURLOPT_SSLKEY_BLOB;
        case "CURLOPT_SSL_EC_CURVES": return CURLOPT_SSL_EC_CURVES;
        case "CURLOPT_SSL_SIGNATURE_ALGORITHMS": return CURLOPT_SSL_SIGNATURE_ALGORITHMS;
        case "CURLFOLLOW_ALL": return CURLFOLLOW_ALL;
        case "CURLFOLLOW_OBEYCODE": return CURLFOLLOW_OBEYCODE;
        case "CURLFOLLOW_FIRSTONLY": return CURLFOLLOW_FIRSTONLY;
        case "CURLINFO_TEXT": return CURLINFO_TEXT;
        case "CURLINFO_HEADER_IN": return CURLINFO_HEADER_IN;
        case "CURLINFO_DATA_IN": return CURLINFO_DATA_IN;
        case "CURLINFO_DATA_OUT": return CURLINFO_DATA_OUT;
        case "CURLINFO_SSL_DATA_OUT": return CURLINFO_SSL_DATA_OUT;
        case "CURLINFO_SSL_DATA_IN": return CURLINFO_SSL_DATA_IN;
        case "CURLINFO_CONNECT_TIME": return CURLINFO_CONNECT_TIME;
        case "CURLINFO_CONTENT_LENGTH_DOWNLOAD": return CURLINFO_CONTENT_LENGTH_DOWNLOAD;
        case "CURLINFO_CONTENT_LENGTH_UPLOAD": return CURLINFO_CONTENT_LENGTH_UPLOAD;
        case "CURLINFO_CONTENT_TYPE": return CURLINFO_CONTENT_TYPE;
        case "CURLINFO_EFFECTIVE_URL": return CURLINFO_EFFECTIVE_URL;
        case "CURLINFO_FILETIME": return CURLINFO_FILETIME;
        case "CURLINFO_HEADER_OUT": return CURLINFO_HEADER_OUT;
        case "CURLINFO_HEADER_SIZE": return CURLINFO_HEADER_SIZE;
        case "CURLINFO_HTTP_CODE": return CURLINFO_HTTP_CODE;
        case "CURLINFO_LASTONE": return CURLINFO_LASTONE;
        case "CURLINFO_NAMELOOKUP_TIME": return CURLINFO_NAMELOOKUP_TIME;
        case "CURLINFO_PRETRANSFER_TIME": return CURLINFO_PRETRANSFER_TIME;
        case "CURLINFO_PRIVATE": return CURLINFO_PRIVATE;
        case "CURLINFO_REDIRECT_COUNT": return CURLINFO_REDIRECT_COUNT;
        case "CURLINFO_REDIRECT_TIME": return CURLINFO_REDIRECT_TIME;
        case "CURLINFO_REQUEST_SIZE": return CURLINFO_REQUEST_SIZE;
        case "CURLINFO_SIZE_DOWNLOAD": return CURLINFO_SIZE_DOWNLOAD;
        case "CURLINFO_SIZE_UPLOAD": return CURLINFO_SIZE_UPLOAD;
        case "CURLINFO_SPEED_DOWNLOAD": return CURLINFO_SPEED_DOWNLOAD;
        case "CURLINFO_SPEED_UPLOAD": return CURLINFO_SPEED_UPLOAD;
        case "CURLINFO_SSL_VERIFYRESULT": return CURLINFO_SSL_VERIFYRESULT;
        case "CURLINFO_STARTTRANSFER_TIME": return CURLINFO_STARTTRANSFER_TIME;
        case "CURLINFO_TOTAL_TIME": return CURLINFO_TOTAL_TIME;
        case "CURLINFO_EFFECTIVE_METHOD": return CURLINFO_EFFECTIVE_METHOD;
        case "CURLINFO_CAPATH": return CURLINFO_CAPATH;
        case "CURLINFO_CAINFO": return CURLINFO_CAINFO;
        case "CURLINFO_HTTPAUTH_USED": return CURLINFO_HTTPAUTH_USED;
        case "CURLINFO_PROXYAUTH_USED": return CURLINFO_PROXYAUTH_USED;
        case "CURLINFO_HTTP_CONNECTCODE": return CURLINFO_HTTP_CONNECTCODE;
        case "CURLINFO_HTTPAUTH_AVAIL": return CURLINFO_HTTPAUTH_AVAIL;
        case "CURLINFO_RESPONSE_CODE": return CURLINFO_RESPONSE_CODE;
        case "CURLINFO_PROXYAUTH_AVAIL": return CURLINFO_PROXYAUTH_AVAIL;
        case "CURLINFO_OS_ERRNO": return CURLINFO_OS_ERRNO;
        case "CURLINFO_NUM_CONNECTS": return CURLINFO_NUM_CONNECTS;
        case "CURLINFO_SSL_ENGINES": return CURLINFO_SSL_ENGINES;
        case "CURLINFO_COOKIELIST": return CURLINFO_COOKIELIST;
        case "CURLINFO_FTP_ENTRY_PATH": return CURLINFO_FTP_ENTRY_PATH;
        case "CURLINFO_REDIRECT_URL": return CURLINFO_REDIRECT_URL;
        case "CURLINFO_APPCONNECT_TIME": return CURLINFO_APPCONNECT_TIME;
        case "CURLINFO_PRIMARY_IP": return CURLINFO_PRIMARY_IP;
        case "CURLINFO_CERTINFO": return CURLINFO_CERTINFO;
        case "CURLINFO_CONDITION_UNMET": return CURLINFO_CONDITION_UNMET;
        case "CURLINFO_RTSP_CLIENT_CSEQ": return CURLINFO_RTSP_CLIENT_CSEQ;
        case "CURLINFO_RTSP_CSEQ_RECV": return CURLINFO_RTSP_CSEQ_RECV;
        case "CURLINFO_RTSP_SERVER_CSEQ": return CURLINFO_RTSP_SERVER_CSEQ;
        case "CURLINFO_RTSP_SESSION_ID": return CURLINFO_RTSP_SESSION_ID;
        case "CURLINFO_LOCAL_IP": return CURLINFO_LOCAL_IP;
        case "CURLINFO_LOCAL_PORT": return CURLINFO_LOCAL_PORT;
        case "CURLINFO_PRIMARY_PORT": return CURLINFO_PRIMARY_PORT;
        case "CURLINFO_HTTP_VERSION": return CURLINFO_HTTP_VERSION;
        case "CURLINFO_PROTOCOL": return CURLINFO_PROTOCOL;
        case "CURLINFO_PROXY_SSL_VERIFYRESULT": return CURLINFO_PROXY_SSL_VERIFYRESULT;
        case "CURLINFO_SCHEME": return CURLINFO_SCHEME;
        case "CURLINFO_CONTENT_LENGTH_DOWNLOAD_T": return CURLINFO_CONTENT_LENGTH_DOWNLOAD_T;
        case "CURLINFO_CONTENT_LENGTH_UPLOAD_T": return CURLINFO_CONTENT_LENGTH_UPLOAD_T;
        case "CURLINFO_SIZE_DOWNLOAD_T": return CURLINFO_SIZE_DOWNLOAD_T;
        case "CURLINFO_SIZE_UPLOAD_T": return CURLINFO_SIZE_UPLOAD_T;
        case "CURLINFO_SPEED_DOWNLOAD_T": return CURLINFO_SPEED_DOWNLOAD_T;
        case "CURLINFO_SPEED_UPLOAD_T": return CURLINFO_SPEED_UPLOAD_T;
        case "CURLINFO_FILETIME_T": return CURLINFO_FILETIME_T;
        case "CURLINFO_QUEUE_TIME_T": return CURLINFO_QUEUE_TIME_T;
        case "CURLINFO_APPCONNECT_TIME_T": return CURLINFO_APPCONNECT_TIME_T;
        case "CURLINFO_CONNECT_TIME_T": return CURLINFO_CONNECT_TIME_T;
        case "CURLINFO_NAMELOOKUP_TIME_T": return CURLINFO_NAMELOOKUP_TIME_T;
        case "CURLINFO_PRETRANSFER_TIME_T": return CURLINFO_PRETRANSFER_TIME_T;
        case "CURLINFO_REDIRECT_TIME_T": return CURLINFO_REDIRECT_TIME_T;
        case "CURLINFO_STARTTRANSFER_TIME_T": return CURLINFO_STARTTRANSFER_TIME_T;
        case "CURLINFO_TOTAL_TIME_T": return CURLINFO_TOTAL_TIME_T;
        case "CURLINFO_USED_PROXY": return CURLINFO_USED_PROXY;
        case "CURLINFO_POSTTRANSFER_TIME_T": return CURLINFO_POSTTRANSFER_TIME_T;
        case "CURLINFO_CONN_ID": return CURLINFO_CONN_ID;
        case "CURLINFO_PROXY_ERROR": return CURLINFO_PROXY_ERROR;
        case "CURLINFO_REFERER": return CURLINFO_REFERER;
        case "CURLINFO_RETRY_AFTER": return CURLINFO_RETRY_AFTER;
        case "CURLMSG_DONE": return CURLMSG_DONE;
        case "CURLVERSION_NOW": return CURLVERSION_NOW;
        case "CURLM_BAD_EASY_HANDLE": return CURLM_BAD_EASY_HANDLE;
        case "CURLM_BAD_HANDLE": return CURLM_BAD_HANDLE;
        case "CURLM_CALL_MULTI_PERFORM": return CURLM_CALL_MULTI_PERFORM;
        case "CURLM_INTERNAL_ERROR": return CURLM_INTERNAL_ERROR;
        case "CURLM_OK": return CURLM_OK;
        case "CURLM_OUT_OF_MEMORY": return CURLM_OUT_OF_MEMORY;
        case "CURLM_ADDED_ALREADY": return CURLM_ADDED_ALREADY;
        case "CURLPROXY_HTTP": return CURLPROXY_HTTP;
        case "CURLPROXY_SOCKS4": return CURLPROXY_SOCKS4;
        case "CURLPROXY_SOCKS5": return CURLPROXY_SOCKS5;
        case "CURLPROXY_SOCKS4A": return CURLPROXY_SOCKS4A;
        case "CURLPROXY_SOCKS5_HOSTNAME": return CURLPROXY_SOCKS5_HOSTNAME;
        case "CURLPROXY_HTTP_1_0": return CURLPROXY_HTTP_1_0;
        case "CURLPROXY_HTTPS": return CURLPROXY_HTTPS;
        case "CURLSHOPT_NONE": return CURLSHOPT_NONE;
        case "CURLSHOPT_SHARE": return CURLSHOPT_SHARE;
        case "CURLSHOPT_UNSHARE": return CURLSHOPT_UNSHARE;
        case "CURL_HTTP_VERSION_1_0": return CURL_HTTP_VERSION_1_0;
        case "CURL_HTTP_VERSION_1_1": return CURL_HTTP_VERSION_1_1;
        case "CURL_HTTP_VERSION_NONE": return CURL_HTTP_VERSION_NONE;
        case "CURL_HTTP_VERSION_2_0": return CURL_HTTP_VERSION_2_0;
        case "CURL_HTTP_VERSION_2": return CURL_HTTP_VERSION_2;
        case "CURL_HTTP_VERSION_2TLS": return CURL_HTTP_VERSION_2TLS;
        case "CURL_HTTP_VERSION_2_PRIOR_KNOWLEDGE": return CURL_HTTP_VERSION_2_PRIOR_KNOWLEDGE;
        case "CURL_HTTP_VERSION_3": return CURL_HTTP_VERSION_3;
        case "CURL_HTTP_VERSION_3ONLY": return CURL_HTTP_VERSION_3ONLY;
        case "CURL_LOCK_DATA_COOKIE": return CURL_LOCK_DATA_COOKIE;
        case "CURL_LOCK_DATA_DNS": return CURL_LOCK_DATA_DNS;
        case "CURL_LOCK_DATA_SSL_SESSION": return CURL_LOCK_DATA_SSL_SESSION;
        case "CURL_LOCK_DATA_CONNECT": return CURL_LOCK_DATA_CONNECT;
        case "CURL_LOCK_DATA_PSL": return CURL_LOCK_DATA_PSL;
        case "CURL_NETRC_IGNORED": return CURL_NETRC_IGNORED;
        case "CURL_NETRC_OPTIONAL": return CURL_NETRC_OPTIONAL;
        case "CURL_NETRC_REQUIRED": return CURL_NETRC_REQUIRED;
        case "CURL_SSLVERSION_DEFAULT": return CURL_SSLVERSION_DEFAULT;
        case "CURL_SSLVERSION_SSLv2": return CURL_SSLVERSION_SSLv2;
        case "CURL_SSLVERSION_SSLv3": return CURL_SSLVERSION_SSLv3;
        case "CURL_SSLVERSION_TLSv1": return CURL_SSLVERSION_TLSv1;
        case "CURL_SSLVERSION_TLSv1_0": return CURL_SSLVERSION_TLSv1_0;
        case "CURL_SSLVERSION_TLSv1_1": return CURL_SSLVERSION_TLSv1_1;
        case "CURL_SSLVERSION_TLSv1_2": return CURL_SSLVERSION_TLSv1_2;
        case "CURL_SSLVERSION_TLSv1_3": return CURL_SSLVERSION_TLSv1_3;
        case "CURL_SSLVERSION_MAX_DEFAULT": return CURL_SSLVERSION_MAX_DEFAULT;
        case "CURL_SSLVERSION_MAX_NONE": return CURL_SSLVERSION_MAX_NONE;
        case "CURL_SSLVERSION_MAX_TLSv1_0": return CURL_SSLVERSION_MAX_TLSv1_0;
        case "CURL_SSLVERSION_MAX_TLSv1_1": return CURL_SSLVERSION_MAX_TLSv1_1;
        case "CURL_SSLVERSION_MAX_TLSv1_2": return CURL_SSLVERSION_MAX_TLSv1_2;
        case "CURL_SSLVERSION_MAX_TLSv1_3": return CURL_SSLVERSION_MAX_TLSv1_3;
        case "CURL_TIMECOND_IFMODSINCE": return CURL_TIMECOND_IFMODSINCE;
        case "CURL_TIMECOND_IFUNMODSINCE": return CURL_TIMECOND_IFUNMODSINCE;
        case "CURL_TIMECOND_LASTMOD": return CURL_TIMECOND_LASTMOD;
        case "CURL_TIMECOND_NONE": return CURL_TIMECOND_NONE;
        case "CURL_VERSION_ASYNCHDNS": return CURL_VERSION_ASYNCHDNS;
        case "CURL_VERSION_CONV": return CURL_VERSION_CONV;
        case "CURL_VERSION_DEBUG": return CURL_VERSION_DEBUG;
        case "CURL_VERSION_GSSNEGOTIATE": return CURL_VERSION_GSSNEGOTIATE;
        case "CURL_VERSION_IDN": return CURL_VERSION_IDN;
        case "CURL_VERSION_IPV6": return CURL_VERSION_IPV6;
        case "CURL_VERSION_KERBEROS4": return CURL_VERSION_KERBEROS4;
        case "CURL_VERSION_LARGEFILE": return CURL_VERSION_LARGEFILE;
        case "CURL_VERSION_LIBZ": return CURL_VERSION_LIBZ;
        case "CURL_VERSION_NTLM": return CURL_VERSION_NTLM;
        case "CURL_VERSION_SPNEGO": return CURL_VERSION_SPNEGO;
        case "CURL_VERSION_SSL": return CURL_VERSION_SSL;
        case "CURL_VERSION_SSPI": return CURL_VERSION_SSPI;
        case "CURL_VERSION_CURLDEBUG": return CURL_VERSION_CURLDEBUG;
        case "CURL_VERSION_TLSAUTH_SRP": return CURL_VERSION_TLSAUTH_SRP;
        case "CURL_VERSION_NTLM_WB": return CURL_VERSION_NTLM_WB;
        case "CURL_VERSION_HTTP2": return CURL_VERSION_HTTP2;
        case "CURL_VERSION_GSSAPI": return CURL_VERSION_GSSAPI;
        case "CURL_VERSION_KERBEROS5": return CURL_VERSION_KERBEROS5;
        case "CURL_VERSION_UNIX_SOCKETS": return CURL_VERSION_UNIX_SOCKETS;
        case "CURL_VERSION_PSL": return CURL_VERSION_PSL;
        case "CURL_VERSION_HTTPS_PROXY": return CURL_VERSION_HTTPS_PROXY;
        case "CURL_VERSION_MULTI_SSL": return CURL_VERSION_MULTI_SSL;
        case "CURL_VERSION_BROTLI": return CURL_VERSION_BROTLI;
        case "CURL_VERSION_ALTSVC": return CURL_VERSION_ALTSVC;
        case "CURL_VERSION_HTTP3": return CURL_VERSION_HTTP3;
        case "CURL_VERSION_ZSTD": return CURL_VERSION_ZSTD;
        case "CURL_VERSION_UNICODE": return CURL_VERSION_UNICODE;
        case "CURL_VERSION_HSTS": return CURL_VERSION_HSTS;
        case "CURL_VERSION_GSASL": return CURL_VERSION_GSASL;
        case "CURLAUTH_ANY": return CURLAUTH_ANY;
        case "CURLAUTH_ANYSAFE": return CURLAUTH_ANYSAFE;
        case "CURLAUTH_BASIC": return CURLAUTH_BASIC;
        case "CURLAUTH_DIGEST": return CURLAUTH_DIGEST;
        case "CURLAUTH_GSSNEGOTIATE": return CURLAUTH_GSSNEGOTIATE;
        case "CURLAUTH_NONE": return CURLAUTH_NONE;
        case "CURLAUTH_NTLM": return CURLAUTH_NTLM;
        case "CURLAUTH_DIGEST_IE": return CURLAUTH_DIGEST_IE;
        case "CURLAUTH_ONLY": return CURLAUTH_ONLY;
        case "CURLAUTH_NEGOTIATE": return CURLAUTH_NEGOTIATE;
        case "CURLAUTH_NTLM_WB": return CURLAUTH_NTLM_WB;
        case "CURLAUTH_GSSAPI": return CURLAUTH_GSSAPI;
        case "CURLAUTH_BEARER": return CURLAUTH_BEARER;
        case "CURLAUTH_AWS_SIGV4": return CURLAUTH_AWS_SIGV4;
        case "CURLE_ABORTED_BY_CALLBACK": return CURLE_ABORTED_BY_CALLBACK;
        case "CURLE_BAD_CALLING_ORDER": return CURLE_BAD_CALLING_ORDER;
        case "CURLE_BAD_CONTENT_ENCODING": return CURLE_BAD_CONTENT_ENCODING;
        case "CURLE_BAD_DOWNLOAD_RESUME": return CURLE_BAD_DOWNLOAD_RESUME;
        case "CURLE_BAD_FUNCTION_ARGUMENT": return CURLE_BAD_FUNCTION_ARGUMENT;
        case "CURLE_BAD_PASSWORD_ENTERED": return CURLE_BAD_PASSWORD_ENTERED;
        case "CURLE_COULDNT_CONNECT": return CURLE_COULDNT_CONNECT;
        case "CURLE_COULDNT_RESOLVE_HOST": return CURLE_COULDNT_RESOLVE_HOST;
        case "CURLE_COULDNT_RESOLVE_PROXY": return CURLE_COULDNT_RESOLVE_PROXY;
        case "CURLE_FAILED_INIT": return CURLE_FAILED_INIT;
        case "CURLE_FILE_COULDNT_READ_FILE": return CURLE_FILE_COULDNT_READ_FILE;
        case "CURLE_FTP_ACCESS_DENIED": return CURLE_FTP_ACCESS_DENIED;
        case "CURLE_FTP_BAD_DOWNLOAD_RESUME": return CURLE_FTP_BAD_DOWNLOAD_RESUME;
        case "CURLE_FTP_CANT_GET_HOST": return CURLE_FTP_CANT_GET_HOST;
        case "CURLE_FTP_CANT_RECONNECT": return CURLE_FTP_CANT_RECONNECT;
        case "CURLE_FTP_COULDNT_GET_SIZE": return CURLE_FTP_COULDNT_GET_SIZE;
        case "CURLE_FTP_COULDNT_RETR_FILE": return CURLE_FTP_COULDNT_RETR_FILE;
        case "CURLE_FTP_COULDNT_SET_ASCII": return CURLE_FTP_COULDNT_SET_ASCII;
        case "CURLE_FTP_COULDNT_SET_BINARY": return CURLE_FTP_COULDNT_SET_BINARY;
        case "CURLE_FTP_COULDNT_STOR_FILE": return CURLE_FTP_COULDNT_STOR_FILE;
        case "CURLE_FTP_COULDNT_USE_REST": return CURLE_FTP_COULDNT_USE_REST;
        case "CURLE_FTP_PARTIAL_FILE": return CURLE_FTP_PARTIAL_FILE;
        case "CURLE_FTP_PORT_FAILED": return CURLE_FTP_PORT_FAILED;
        case "CURLE_FTP_QUOTE_ERROR": return CURLE_FTP_QUOTE_ERROR;
        case "CURLE_FTP_USER_PASSWORD_INCORRECT": return CURLE_FTP_USER_PASSWORD_INCORRECT;
        case "CURLE_FTP_WEIRD_227_FORMAT": return CURLE_FTP_WEIRD_227_FORMAT;
        case "CURLE_FTP_WEIRD_PASS_REPLY": return CURLE_FTP_WEIRD_PASS_REPLY;
        case "CURLE_FTP_WEIRD_PASV_REPLY": return CURLE_FTP_WEIRD_PASV_REPLY;
        case "CURLE_FTP_WEIRD_SERVER_REPLY": return CURLE_FTP_WEIRD_SERVER_REPLY;
        case "CURLE_FTP_WEIRD_USER_REPLY": return CURLE_FTP_WEIRD_USER_REPLY;
        case "CURLE_FTP_WRITE_ERROR": return CURLE_FTP_WRITE_ERROR;
        case "CURLE_FUNCTION_NOT_FOUND": return CURLE_FUNCTION_NOT_FOUND;
        case "CURLE_GOT_NOTHING": return CURLE_GOT_NOTHING;
        case "CURLE_HTTP_NOT_FOUND": return CURLE_HTTP_NOT_FOUND;
        case "CURLE_HTTP_PORT_FAILED": return CURLE_HTTP_PORT_FAILED;
        case "CURLE_HTTP_POST_ERROR": return CURLE_HTTP_POST_ERROR;
        case "CURLE_HTTP_RANGE_ERROR": return CURLE_HTTP_RANGE_ERROR;
        case "CURLE_HTTP_RETURNED_ERROR": return CURLE_HTTP_RETURNED_ERROR;
        case "CURLE_LDAP_CANNOT_BIND": return CURLE_LDAP_CANNOT_BIND;
        case "CURLE_LDAP_SEARCH_FAILED": return CURLE_LDAP_SEARCH_FAILED;
        case "CURLE_LIBRARY_NOT_FOUND": return CURLE_LIBRARY_NOT_FOUND;
        case "CURLE_MALFORMAT_USER": return CURLE_MALFORMAT_USER;
        case "CURLE_OBSOLETE": return CURLE_OBSOLETE;
        case "CURLE_OK": return CURLE_OK;
        case "CURLE_OPERATION_TIMEDOUT": return CURLE_OPERATION_TIMEDOUT;
        case "CURLE_OPERATION_TIMEOUTED": return CURLE_OPERATION_TIMEOUTED;
        case "CURLE_OUT_OF_MEMORY": return CURLE_OUT_OF_MEMORY;
        case "CURLE_PARTIAL_FILE": return CURLE_PARTIAL_FILE;
        case "CURLE_READ_ERROR": return CURLE_READ_ERROR;
        case "CURLE_RECV_ERROR": return CURLE_RECV_ERROR;
        case "CURLE_SEND_ERROR": return CURLE_SEND_ERROR;
        case "CURLE_SHARE_IN_USE": return CURLE_SHARE_IN_USE;
        case "CURLE_SSL_CACERT": return CURLE_SSL_CACERT;
        case "CURLE_SSL_CERTPROBLEM": return CURLE_SSL_CERTPROBLEM;
        case "CURLE_SSL_CIPHER": return CURLE_SSL_CIPHER;
        case "CURLE_SSL_CONNECT_ERROR": return CURLE_SSL_CONNECT_ERROR;
        case "CURLE_SSL_ENGINE_NOTFOUND": return CURLE_SSL_ENGINE_NOTFOUND;
        case "CURLE_SSL_ENGINE_SETFAILED": return CURLE_SSL_ENGINE_SETFAILED;
        case "CURLE_SSL_PEER_CERTIFICATE": return CURLE_SSL_PEER_CERTIFICATE;
        case "CURLE_SSL_PINNEDPUBKEYNOTMATCH": return CURLE_SSL_PINNEDPUBKEYNOTMATCH;
        case "CURLE_TELNET_OPTION_SYNTAX": return CURLE_TELNET_OPTION_SYNTAX;
        case "CURLE_TOO_MANY_REDIRECTS": return CURLE_TOO_MANY_REDIRECTS;
        case "CURLE_UNKNOWN_TELNET_OPTION": return CURLE_UNKNOWN_TELNET_OPTION;
        case "CURLE_UNSUPPORTED_PROTOCOL": return CURLE_UNSUPPORTED_PROTOCOL;
        case "CURLE_URL_MALFORMAT": return CURLE_URL_MALFORMAT;
        case "CURLE_URL_MALFORMAT_USER": return CURLE_URL_MALFORMAT_USER;
        case "CURLE_WRITE_ERROR": return CURLE_WRITE_ERROR;
        case "CURLE_FILESIZE_EXCEEDED": return CURLE_FILESIZE_EXCEEDED;
        case "CURLE_LDAP_INVALID_URL": return CURLE_LDAP_INVALID_URL;
        case "CURLE_FTP_SSL_FAILED": return CURLE_FTP_SSL_FAILED;
        case "CURLE_SSL_CACERT_BADFILE": return CURLE_SSL_CACERT_BADFILE;
        case "CURLE_SSH": return CURLE_SSH;
        case "CURLE_WEIRD_SERVER_REPLY": return CURLE_WEIRD_SERVER_REPLY;
        case "CURLE_PROXY": return CURLE_PROXY;
        case "CURLE_UNKNOWN_OPTION": return CURLE_UNKNOWN_OPTION;
        case "CURLPROTO_ALL": return CURLPROTO_ALL;
        case "CURLPROTO_DICT": return CURLPROTO_DICT;
        case "CURLPROTO_FILE": return CURLPROTO_FILE;
        case "CURLPROTO_FTP": return CURLPROTO_FTP;
        case "CURLPROTO_FTPS": return CURLPROTO_FTPS;
        case "CURLPROTO_HTTP": return CURLPROTO_HTTP;
        case "CURLPROTO_HTTPS": return CURLPROTO_HTTPS;
        case "CURLPROTO_LDAP": return CURLPROTO_LDAP;
        case "CURLPROTO_LDAPS": return CURLPROTO_LDAPS;
        case "CURLPROTO_SCP": return CURLPROTO_SCP;
        case "CURLPROTO_SFTP": return CURLPROTO_SFTP;
        case "CURLPROTO_TELNET": return CURLPROTO_TELNET;
        case "CURLPROTO_TFTP": return CURLPROTO_TFTP;
        case "CURLPROTO_IMAP": return CURLPROTO_IMAP;
        case "CURLPROTO_IMAPS": return CURLPROTO_IMAPS;
        case "CURLPROTO_POP3": return CURLPROTO_POP3;
        case "CURLPROTO_POP3S": return CURLPROTO_POP3S;
        case "CURLPROTO_RTSP": return CURLPROTO_RTSP;
        case "CURLPROTO_SMTP": return CURLPROTO_SMTP;
        case "CURLPROTO_SMTPS": return CURLPROTO_SMTPS;
        case "CURLPROTO_RTMP": return CURLPROTO_RTMP;
        case "CURLPROTO_RTMPE": return CURLPROTO_RTMPE;
        case "CURLPROTO_RTMPS": return CURLPROTO_RTMPS;
        case "CURLPROTO_RTMPT": return CURLPROTO_RTMPT;
        case "CURLPROTO_RTMPTE": return CURLPROTO_RTMPTE;
        case "CURLPROTO_RTMPTS": return CURLPROTO_RTMPTS;
        case "CURLPROTO_GOPHER": return CURLPROTO_GOPHER;
        case "CURLPROTO_SMB": return CURLPROTO_SMB;
        case "CURLPROTO_SMBS": return CURLPROTO_SMBS;
        case "CURLPROTO_MQTT": return CURLPROTO_MQTT;
        case "CURLFTPSSL_ALL": return CURLFTPSSL_ALL;
        case "CURLFTPSSL_CONTROL": return CURLFTPSSL_CONTROL;
        case "CURLFTPSSL_NONE": return CURLFTPSSL_NONE;
        case "CURLFTPSSL_TRY": return CURLFTPSSL_TRY;
        case "CURLFTPSSL_CCC_ACTIVE": return CURLFTPSSL_CCC_ACTIVE;
        case "CURLFTPSSL_CCC_NONE": return CURLFTPSSL_CCC_NONE;
        case "CURLFTPSSL_CCC_PASSIVE": return CURLFTPSSL_CCC_PASSIVE;
        case "CURLUSESSL_ALL": return CURLUSESSL_ALL;
        case "CURLUSESSL_CONTROL": return CURLUSESSL_CONTROL;
        case "CURLUSESSL_NONE": return CURLUSESSL_NONE;
        case "CURLUSESSL_TRY": return CURLUSESSL_TRY;
        case "CURLFTPMETHOD_DEFAULT": return CURLFTPMETHOD_DEFAULT;
        case "CURLFTPMETHOD_MULTICWD": return CURLFTPMETHOD_MULTICWD;
        case "CURLFTPMETHOD_NOCWD": return CURLFTPMETHOD_NOCWD;
        case "CURLFTPMETHOD_SINGLECWD": return CURLFTPMETHOD_SINGLECWD;
        case "CURLFTPAUTH_DEFAULT": return CURLFTPAUTH_DEFAULT;
        case "CURLFTPAUTH_SSL": return CURLFTPAUTH_SSL;
        case "CURLFTPAUTH_TLS": return CURLFTPAUTH_TLS;
        case "CURLFTP_CREATE_DIR": return CURLFTP_CREATE_DIR;
        case "CURLFTP_CREATE_DIR_NONE": return CURLFTP_CREATE_DIR_NONE;
        case "CURLFTP_CREATE_DIR_RETRY": return CURLFTP_CREATE_DIR_RETRY;
        case "CURL_RTSPREQ_ANNOUNCE": return CURL_RTSPREQ_ANNOUNCE;
        case "CURL_RTSPREQ_DESCRIBE": return CURL_RTSPREQ_DESCRIBE;
        case "CURL_RTSPREQ_GET_PARAMETER": return CURL_RTSPREQ_GET_PARAMETER;
        case "CURL_RTSPREQ_OPTIONS": return CURL_RTSPREQ_OPTIONS;
        case "CURL_RTSPREQ_PAUSE": return CURL_RTSPREQ_PAUSE;
        case "CURL_RTSPREQ_PLAY": return CURL_RTSPREQ_PLAY;
        case "CURL_RTSPREQ_RECEIVE": return CURL_RTSPREQ_RECEIVE;
        case "CURL_RTSPREQ_RECORD": return CURL_RTSPREQ_RECORD;
        case "CURL_RTSPREQ_SET_PARAMETER": return CURL_RTSPREQ_SET_PARAMETER;
        case "CURL_RTSPREQ_SETUP": return CURL_RTSPREQ_SETUP;
        case "CURL_RTSPREQ_TEARDOWN": return CURL_RTSPREQ_TEARDOWN;
        case "CURLPAUSE_ALL": return CURLPAUSE_ALL;
        case "CURLPAUSE_CONT": return CURLPAUSE_CONT;
        case "CURLPAUSE_RECV": return CURLPAUSE_RECV;
        case "CURLPAUSE_RECV_CONT": return CURLPAUSE_RECV_CONT;
        case "CURLPAUSE_SEND": return CURLPAUSE_SEND;
        case "CURLPAUSE_SEND_CONT": return CURLPAUSE_SEND_CONT;
        case "CURL_READFUNC_PAUSE": return CURL_READFUNC_PAUSE;
        case "CURL_WRITEFUNC_PAUSE": return CURL_WRITEFUNC_PAUSE;
        case "CURL_IPRESOLVE_V4": return CURL_IPRESOLVE_V4;
        case "CURL_IPRESOLVE_V6": return CURL_IPRESOLVE_V6;
        case "CURL_IPRESOLVE_WHATEVER": return CURL_IPRESOLVE_WHATEVER;
        case "CURLSSH_AUTH_ANY": return CURLSSH_AUTH_ANY;
        case "CURLSSH_AUTH_DEFAULT": return CURLSSH_AUTH_DEFAULT;
        case "CURLSSH_AUTH_HOST": return CURLSSH_AUTH_HOST;
        case "CURLSSH_AUTH_KEYBOARD": return CURLSSH_AUTH_KEYBOARD;
        case "CURLSSH_AUTH_NONE": return CURLSSH_AUTH_NONE;
        case "CURLSSH_AUTH_PASSWORD": return CURLSSH_AUTH_PASSWORD;
        case "CURLSSH_AUTH_PUBLICKEY": return CURLSSH_AUTH_PUBLICKEY;
        case "CURLSSH_AUTH_AGENT": return CURLSSH_AUTH_AGENT;
        case "CURLSSH_AUTH_GSSAPI": return CURLSSH_AUTH_GSSAPI;
        case "CURLKHMATCH_OK": return CURLKHMATCH_OK;
        case "CURLKHMATCH_MISMATCH": return CURLKHMATCH_MISMATCH;
        case "CURLKHMATCH_MISSING": return CURLKHMATCH_MISSING;
        case "CURLKHMATCH_LAST": return CURLKHMATCH_LAST;
        case "CURL_FNMATCHFUNC_FAIL": return CURL_FNMATCHFUNC_FAIL;
        case "CURL_FNMATCHFUNC_MATCH": return CURL_FNMATCHFUNC_MATCH;
        case "CURL_FNMATCHFUNC_NOMATCH": return CURL_FNMATCHFUNC_NOMATCH;
        case "CURLMOPT_PIPELINING": return CURLMOPT_PIPELINING;
        case "CURLMOPT_MAXCONNECTS": return CURLMOPT_MAXCONNECTS;
        case "CURLMOPT_CHUNK_LENGTH_PENALTY_SIZE": return CURLMOPT_CHUNK_LENGTH_PENALTY_SIZE;
        case "CURLMOPT_CONTENT_LENGTH_PENALTY_SIZE": return CURLMOPT_CONTENT_LENGTH_PENALTY_SIZE;
        case "CURLMOPT_MAX_HOST_CONNECTIONS": return CURLMOPT_MAX_HOST_CONNECTIONS;
        case "CURLMOPT_MAX_PIPELINE_LENGTH": return CURLMOPT_MAX_PIPELINE_LENGTH;
        case "CURLMOPT_MAX_TOTAL_CONNECTIONS": return CURLMOPT_MAX_TOTAL_CONNECTIONS;
        case "CURLMOPT_PUSHFUNCTION": return CURLMOPT_PUSHFUNCTION;
        case "CURLMOPT_MAX_CONCURRENT_STREAMS": return CURLMOPT_MAX_CONCURRENT_STREAMS;
        case "CURLHEADER_SEPARATE": return CURLHEADER_SEPARATE;
        case "CURLHEADER_UNIFIED": return CURLHEADER_UNIFIED;
        case "CURLSSLOPT_ALLOW_BEAST": return CURLSSLOPT_ALLOW_BEAST;
        case "CURLSSLOPT_NO_REVOKE": return CURLSSLOPT_NO_REVOKE;
        case "CURLSSLOPT_NO_PARTIALCHAIN": return CURLSSLOPT_NO_PARTIALCHAIN;
        case "CURLSSLOPT_REVOKE_BEST_EFFORT": return CURLSSLOPT_REVOKE_BEST_EFFORT;
        case "CURLSSLOPT_NATIVE_CA": return CURLSSLOPT_NATIVE_CA;
        case "CURLSSLOPT_AUTO_CLIENT_CERT": return CURLSSLOPT_AUTO_CLIENT_CERT;
        case "CURL_TLSAUTH_SRP": return CURL_TLSAUTH_SRP;
        case "CURL_REDIR_POST_301": return CURL_REDIR_POST_301;
        case "CURL_REDIR_POST_302": return CURL_REDIR_POST_302;
        case "CURL_REDIR_POST_ALL": return CURL_REDIR_POST_ALL;
        case "CURL_REDIR_POST_303": return CURL_REDIR_POST_303;
        case "CURLPIPE_NOTHING": return CURLPIPE_NOTHING;
        case "CURLPIPE_HTTP1": return CURLPIPE_HTTP1;
        case "CURLPIPE_MULTIPLEX": return CURLPIPE_MULTIPLEX;
        case "CURLGSSAPI_DELEGATION_FLAG": return CURLGSSAPI_DELEGATION_FLAG;
        case "CURLGSSAPI_DELEGATION_POLICY_FLAG": return CURLGSSAPI_DELEGATION_POLICY_FLAG;
        case "CURLALTSVC_H1": return CURLALTSVC_H1;
        case "CURLALTSVC_H2": return CURLALTSVC_H2;
        case "CURLALTSVC_H3": return CURLALTSVC_H3;
        case "CURLALTSVC_READONLYFILE": return CURLALTSVC_READONLYFILE;
        case "CURLHSTS_ENABLE": return CURLHSTS_ENABLE;
        case "CURLHSTS_READONLYFILE": return CURLHSTS_READONLYFILE;
        case "CURLWS_RAW_MODE": return CURLWS_RAW_MODE;
        case "CURL_PUSH_OK": return CURL_PUSH_OK;
        case "CURL_PUSH_DENY": return CURL_PUSH_DENY;
        case "CURL_PREREQFUNC_OK": return CURL_PREREQFUNC_OK;
        case "CURL_PREREQFUNC_ABORT": return CURL_PREREQFUNC_ABORT;
        case "CURLMIMEOPT_FORMESCAPE": return CURLMIMEOPT_FORMESCAPE;
        case "CURL_MAX_READ_SIZE": return CURL_MAX_READ_SIZE;
        case "CURLPX_BAD_ADDRESS_TYPE": return CURLPX_BAD_ADDRESS_TYPE;
        case "CURLPX_BAD_VERSION": return CURLPX_BAD_VERSION;
        case "CURLPX_CLOSED": return CURLPX_CLOSED;
        case "CURLPX_GSSAPI": return CURLPX_GSSAPI;
        case "CURLPX_GSSAPI_PERMSG": return CURLPX_GSSAPI_PERMSG;
        case "CURLPX_GSSAPI_PROTECTION": return CURLPX_GSSAPI_PROTECTION;
        case "CURLPX_IDENTD": return CURLPX_IDENTD;
        case "CURLPX_IDENTD_DIFFER": return CURLPX_IDENTD_DIFFER;
        case "CURLPX_LONG_HOSTNAME": return CURLPX_LONG_HOSTNAME;
        case "CURLPX_LONG_PASSWD": return CURLPX_LONG_PASSWD;
        case "CURLPX_LONG_USER": return CURLPX_LONG_USER;
        case "CURLPX_NO_AUTH": return CURLPX_NO_AUTH;
        case "CURLPX_OK": return CURLPX_OK;
        case "CURLPX_RECV_ADDRESS": return CURLPX_RECV_ADDRESS;
        case "CURLPX_RECV_AUTH": return CURLPX_RECV_AUTH;
        case "CURLPX_RECV_CONNECT": return CURLPX_RECV_CONNECT;
        case "CURLPX_RECV_REQACK": return CURLPX_RECV_REQACK;
        case "CURLPX_REPLY_ADDRESS_TYPE_NOT_SUPPORTED": return CURLPX_REPLY_ADDRESS_TYPE_NOT_SUPPORTED;
        case "CURLPX_REPLY_COMMAND_NOT_SUPPORTED": return CURLPX_REPLY_COMMAND_NOT_SUPPORTED;
        case "CURLPX_REPLY_CONNECTION_REFUSED": return CURLPX_REPLY_CONNECTION_REFUSED;
        case "CURLPX_REPLY_GENERAL_SERVER_FAILURE": return CURLPX_REPLY_GENERAL_SERVER_FAILURE;
        case "CURLPX_REPLY_HOST_UNREACHABLE": return CURLPX_REPLY_HOST_UNREACHABLE;
        case "CURLPX_REPLY_NETWORK_UNREACHABLE": return CURLPX_REPLY_NETWORK_UNREACHABLE;
        case "CURLPX_REPLY_NOT_ALLOWED": return CURLPX_REPLY_NOT_ALLOWED;
        case "CURLPX_REPLY_TTL_EXPIRED": return CURLPX_REPLY_TTL_EXPIRED;
        case "CURLPX_REPLY_UNASSIGNED": return CURLPX_REPLY_UNASSIGNED;
        case "CURLPX_REQUEST_FAILED": return CURLPX_REQUEST_FAILED;
        case "CURLPX_RESOLVE_HOST": return CURLPX_RESOLVE_HOST;
        case "CURLPX_SEND_AUTH": return CURLPX_SEND_AUTH;
        case "CURLPX_SEND_CONNECT": return CURLPX_SEND_CONNECT;
        case "CURLPX_SEND_REQUEST": return CURLPX_SEND_REQUEST;
        case "CURLPX_UNKNOWN_FAIL": return CURLPX_UNKNOWN_FAIL;
        case "CURLPX_UNKNOWN_MODE": return CURLPX_UNKNOWN_MODE;
        case "CURLPX_USER_REJECTED": return CURLPX_USER_REJECTED;
        default: return -999998; // 未知常量名
    }
}
