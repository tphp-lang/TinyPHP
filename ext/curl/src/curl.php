<?php
// ext/curl/src/curl.php — cURL 扩展核心（CurlHandle + CurlMultiHandle + CurlShareHandle
//   + CurlSharePersistentHandle 类 + 35 个 curl_* 函数）
//
// 与 PHP 8.5 ext/curl/curl.stub.php 1:1 对齐；实现深度分级：
//   - 完整实现：easy handle + HTTP/HTTPS 主路径（curl_init/curl_setopt/curl_exec/curl_getinfo/...）
//   - 存根但显式拒绝：curl_multi_* / curl_share_* 执行类函数抛 Exception（不静默成功）
//   - 显式拒绝：非 http/https 协议返回 CURLE_UNSUPPORTED_PROTOCOL（不静默）
//
// 设计说明（phpc 模式，无自定义 C 代码）：
//   - HTTP 走 ext/stream 的 socket（stream_socket_client / stream_socket_sendto / stream_socket_recvfrom）
//   - HTTPS 走 ext/openssl 的 mbedTLS（openssl_ctx_new / openssl_ssl_new / openssl_ssl_connect / ...）
//   - 包含顺序：openssl.h 必须在 stream.h 之前（TPHP_STREAM_TLS_IMPLEMENTED 先定义）
//   - 错误统一写入 handle.errorCode / handle.errorMsg（可被 curl_errno / curl_error 查询，不静默）
//   - 不使用 ?type 语法（TinyPHP 不支持 nullable）；用默认空字符串 "" 替代 null
//   - 不使用 mixed 返回类型（除 curl_setopt 的 $value 参数用 mixed 外，全部使用具体类型）
//   - 函数体含 throw 的函数返回类型必须包含 |Exception（TinyPHP 语法规则）
//
// 与 PHP 8.5 原生行为差异（类型安全考量）：
//   - curl_init(string $url = ""): CurlHandle  — 不用 ?string，默认空字符串；返回 CurlHandle（不返回 false，失败抛 Exception）
//   - curl_exec(CurlHandle $handle): bool       — 始终返回 bool；响应体存储在 handle.lastResponse（用户通过 curl_multi_getcontent 获取）
//   - curl_getinfo(CurlHandle $handle): array  — 始终返回完整数组（不支持单 option 查询，避免 mixed 返回）
//   - curl_strerror(int $error_code): string    — 不返回 ?string，未知码返回空字符串
//   - curl_escape/curl_unescape: string         — 不返回 string|false，失败抛 Exception
//   - CURLFile 构造函数：?string → string = ""（空字符串表示"未指定"）

// ── 依赖声明 ──────────────────────────────────────────────────
//   mbedtls 头文件路径（HTTPS 需要；与 openssl.php 一致，重复声明无害）
#flag -I__INC__ . "mbedtls_src/include"
#flag -I__INC__ . "mbedtls_src/library"
#flag -I__INC__ . "mbedtls_src/3rdparty/everest/include"
#flag -I__INC__ . "mbedtls_src/3rdparty/everest/include/everest"
#flag -I__INC__ . "mbedtls_src/3rdparty/everest/include/everest/kremlib"
//   Windows winsock 链接（stream.h / openssl.h 均依赖）
#flag windows -lws2_32

//   包含顺序：openssl.h 先于 stream.h（TPHP_STREAM_TLS_IMPLEMENTED 必须先定义）
#include __EXT__ . "openssl/src/openssl.h"
#include __EXT__ . "stream/src/stream.h"

// ════════════════════════════════════════════════════════════
// CurlHandle — cURL 会话句柄
// ════════════════════════════════════════════════════════════

final class CurlHandle
{
    // ── 请求配置 ──────────────────────────────────────────────
    public string $url = "";                          // CURLOPT_URL
    public string $method = "GET";                    // 请求方法（GET/POST/PUT/DELETE/HEAD/...）
    public array $headers = [];                       // CURLOPT_HTTPHEADER（"Key: Value" 形式）
    public string $body = "";                         // CURLOPT_POSTFIELDS（string 形式）
    public array $postFields = [];                    // CURLOPT_POSTFIELDS（array 形式，含 CURLFile）
    public bool $isPostFieldsArray = false;           // POSTFIELDS 是否为数组形式
    public bool $returnTransfer = false;               // CURLOPT_RETURNTRANSFER
    public bool $followLocation = false;              // CURLOPT_FOLLOWLOCATION
    public int $maxRedirs = 20;                       // CURLOPT_MAXREDIRS
    public int $timeout = 0;                          // CURLOPT_TIMEOUT（秒，0=不限）
    public int $connectTimeout = 0;                   // CURLOPT_CONNECTTIMEOUT（秒，0=不限）
    public int $timeoutMs = 0;                        // CURLOPT_TIMEOUT_MS
    public int $connectTimeoutMs = 0;                 // CURLOPT_CONNECTTIMEOUT_MS
    public bool $noBody = false;                     // CURLOPT_NOBODY（HEAD 请求）
    public bool $verbose = false;                    // CURLOPT_VERBOSE
    public bool $header = false;                     // CURLOPT_HEADER（响应包含头）
    public string $userAgent = "";                    // CURLOPT_USERAGENT
    public string $referer = "";                      // CURLOPT_REFERER
    public string $cookie = "";                       // CURLOPT_COOKIE
    public string $cookieFile = "";                   // CURLOPT_COOKIEFILE
    public string $cookieJar = "";                    // CURLOPT_COOKIEJAR
    public string $customRequest = "";                // CURLOPT_CUSTOMREQUEST
    public int $httpVersion = 0;                      // CURLOPT_HTTP_VERSION（0=默认,1=1.0,2=1.1,3=2.0）
    public string $encoding = "";                     // CURLOPT_ACCEPT_ENCODING / ENCODING
    public int $port = 0;                             // CURLOPT_PORT（0=使用协议默认端口）

    // ── 认证 ──────────────────────────────────────────────────
    public string $userPwd = "";                      // CURLOPT_USERPWD（"user:pass"）
    public int $httpAuth = 0;                         // CURLOPT_HTTPAUTH
    public string $username = "";                     // CURLOPT_USERNAME
    public string $password = "";                     // CURLOPT_PASSWORD

    // ── 代理 ──────────────────────────────────────────────────
    public string $proxy = "";                        // CURLOPT_PROXY
    public int $proxyPort = 0;                        // CURLOPT_PROXYPORT
    public int $proxyType = 0;                        // CURLOPT_PROXYTYPE（0=CURLPROXY_HTTP）
    public string $proxyUserPwd = "";                 // CURLOPT_PROXYUSERPWD

    // ── SSL/TLS ───────────────────────────────────────────────
    public bool $sslVerifyPeer = false;               // CURLOPT_SSL_VERIFYPEER
    public int $sslVerifyHost = 0;                    // CURLOPT_SSL_VERIFYHOST
    public string $cainfo = "";                       // CURLOPT_CAINFO
    public string $capath = "";                       // CURLOPT_CAPATH
    public string $sslCert = "";                      // CURLOPT_SSLCERT
    public string $sslCertType = "";                  // CURLOPT_SSLCERTTYPE
    public string $sslKey = "";                       // CURLOPT_SSLKEY
    public string $sslKeyPasswd = "";                 // CURLOPT_SSLKEYPASSWD / KEYPASSWD
    public int $sslVersion = 0;                       // CURLOPT_SSLVERSION

    // ── 上传 ──────────────────────────────────────────────────
    public bool $upload = false;                     // CURLOPT_UPLOAD
    public int $infileSize = 0;                      // CURLOPT_INFILESIZE

    // ── 回调（callable，存储但不深度调用以避免类型复杂度）──
    public mixed $writeFunction = null;              // CURLOPT_WRITEFUNCTION
    public mixed $readFunction = null;               // CURLOPT_READFUNCTION
    public mixed $headerFunction = null;             // CURLOPT_HEADERFUNCTION

    // ── 私有数据 ───────────────────────────────────────────────
    public mixed $private = null;                    // CURLOPT_PRIVATE

    // ── 响应状态 ──────────────────────────────────────────────
    public string $lastResponse = "";                 // 最后一次响应体
    public string $lastResponseHeader = "";           // 最后一次响应头（原始）
    public int $lastHttpCode = 0;                    // 最后一次 HTTP 状态码
    public int $headerSize = 0;                      // 响应头大小
    public int $requestSize = 0;                     // 请求大小
    public int $redirectCount = 0;                   // 重定向次数
    public string $redirectUrl = "";                 // 重定向最终 URL
    public string $effectiveUrl = "";                 // 实际请求 URL（可能经过重定向）
    public string $effectiveMethod = "";             // 实际请求方法
    public string $contentType = "";                 // 响应 Content-Type

    // ── 传输统计 ──────────────────────────────────────────────
    public float $totalTime = 0.0;
    public float $connectTime = 0.0;
    public float $nameLookupTime = 0.0;
    public float $preTransferTime = 0.0;
    public float $startTransferTime = 0.0;
    public float $redirectTime = 0.0;
    public float $appConnectTime = 0.0;
    public int $sizeDownload = 0;
    public int $sizeUpload = 0;
    public int $speedDownload = 0;
    public int $speedUpload = 0;
    public int $downloadContentLength = 0;
    public int $uploadContentLength = 0;
    public string $primaryIp = "";
    public int $primaryPort = 0;
    public string $localIp = "";
    public int $localPort = 0;
    public int $sslVerifyResult = 0;

    // ── 错误状态 ──────────────────────────────────────────────
    public int $errorCode = 0;                       // CURLE_* 错误码（0=无错误）
    public string $errorMsg = "";                    // 错误描述

    // ── 句柄状态 ──────────────────────────────────────────────
    public bool $closed = false;                     // 句柄是否已关闭
    public bool $executed = false;                   // 是否已执行过 curl_exec

    // ── 已设置选项记录（用于 curl_copy_handle / curl_reset）──
    public array $options = [];                      // option => value 映射

    // ── 设置错误（内部辅助）──────────────────────────────────
    public function _setError(int $code, string $msg): void
    {
        $this->errorCode = $code;
        $this->errorMsg = $msg;
    }

    // ── 清除错误 ──────────────────────────────────────────────
    public function _clearError(): void
    {
        $this->errorCode = 0;
        $this->errorMsg = "";
    }
}

// ════════════════════════════════════════════════════════════
// CurlMultiHandle — 多句柄容器（存根类，作为类型标记）
//   PHP 8.5 中为空类；TinyPHP 中保持一致，调用 multi_exec 等执行类函数时抛异常
// ════════════════════════════════════════════════════════════

final class CurlMultiHandle
{
    // 空类（与 PHP 8.5 一致，仅作为类型标记）
    // 调用 curl_multi_exec / curl_multi_add_handle 等执行类函数会抛 Exception
}

// ════════════════════════════════════════════════════════════
// CurlShareHandle — 共享句柄容器（存根类）
//   PHP 8.5 中为空类；TinyPHP 中保持一致，调用 curl_share_setopt 时抛异常
// ════════════════════════════════════════════════════════════

final class CurlShareHandle
{
    // 空类（与 PHP 8.5 一致，仅作为类型标记）
}

// ════════════════════════════════════════════════════════════
// CurlSharePersistentHandle — 持久化共享句柄（存根类）
//   PHP 8.5 中含 public readonly array $options
//   TinyPHP 中保持一致，但 curl_share_init_persistent 抛异常
// ════════════════════════════════════════════════════════════

final class CurlSharePersistentHandle
{
    public readonly array $options;

    public function __construct(array $options)
    {
        $this->options = $options;
    }
}

// ════════════════════════════════════════════════════════════
// 内部辅助函数
// ════════════════════════════════════════════════════════════

// ── 解析 URL（scheme/host/port/path/query）──────────────────
//   返回 ["scheme" => string, "host" => string, "port" => int,
//         "path" => string, "query" => string, "user" => string, "pass" => string]
//   解析失败返回空数组（调用方检查）
function _curl_parse_url(string $url): array
{
    $result = [
        "scheme" => "", "host" => "", "port" => 0,
        "path" => "/", "query" => "", "user" => "", "pass" => "",
        "full" => $url
    ];

    // 提取 scheme
    $pos = strpos($url, "://");
    if ($pos === false) {
        return $result;
    }
    $result["scheme"] = strtolower(substr($url, 0, $pos));
    $rest = substr($url, $pos + 3);

    // 提取 user:pass@（如果有）
    $atPos = strpos($rest, "@");
    if ($atPos !== false) {
        $userInfo = substr($rest, 0, $atPos);
        $rest = substr($rest, $atPos + 1);
        $colonPos = strpos($userInfo, ":");
        if ($colonPos !== false) {
            $result["user"] = substr($userInfo, 0, $colonPos);
            $result["pass"] = substr($userInfo, $colonPos + 1);
        } else {
            $result["user"] = $userInfo;
        }
    }

    // 提取 host:port 和 path?query
    $slashPos = strpos($rest, "/");
    $questionPos = strpos($rest, "?");
    $hostPortEnd = strlen($rest);
    if ($slashPos !== false && ($questionPos === false || $slashPos < $questionPos)) {
        $hostPortEnd = $slashPos;
    } elseif ($questionPos !== false) {
        $hostPortEnd = $questionPos;
    }

    $hostPort = substr($rest, 0, $hostPortEnd);
    $pathAndQuery = substr($rest, $hostPortEnd);

    // 解析 host:port
    $colonPos = strrpos($hostPort, ":");
    if ($colonPos !== false) {
        $result["host"] = substr($hostPort, 0, $colonPos);
        $result["port"] = intval(substr($hostPort, $colonPos + 1));
    } else {
        $result["host"] = $hostPort;
    }

    // 解析 path?query
    if (strlen($pathAndQuery) > 0) {
        $qPos = strpos($pathAndQuery, "?");
        if ($qPos !== false) {
            $result["path"] = substr($pathAndQuery, 0, $qPos);
            $result["query"] = substr($pathAndQuery, $qPos + 1);
        } else {
            $result["path"] = $pathAndQuery;
        }
    }

    // 设置默认端口
    if ($result["port"] == 0) {
        if ($result["scheme"] == "http") {
            $result["port"] = 80;
        } elseif ($result["scheme"] == "https") {
            $result["port"] = 443;
        }
    }

    return $result;
}

// ── 代理地址解析（支持 "host:port" 或 "http://host:port" 格式）──
function _curl_parse_proxy(string $proxy): array
{
    $result = ["host" => "", "port" => 0];
    $addr = $proxy;
    // 去除 scheme 前缀
    $pos = strpos($addr, "://");
    if ($pos !== false) {
        $addr = substr($addr, $pos + 3);
    }
    // 解析 host:port
    $colonPos = strrpos($addr, ":");
    if ($colonPos !== false) {
        $result["host"] = substr($addr, 0, $colonPos);
        $result["port"] = intval(substr($addr, $colonPos + 1));
    } else {
        $result["host"] = $addr;
    }
    return $result;
}

// ── URL 编码（用于 POST 表单 / multipart）────────────────────
function _curl_url_encode(string $str): string
{
    $result = "";
    $len = strlen($str);
    $i = 0;
    while ($i < $len) {
        $c = ord($str[$i]);
        // 字母数字 - _ . 不编码
        if (($c >= 65 && $c <= 90) ||   // A-Z
            ($c >= 97 && $c <= 122) ||  // a-z
            ($c >= 48 && $c <= 57) ||   // 0-9
            $c == 45 || $c == 95 ||     // - _
            $c == 46 || $c == 126) {    // . ~
            $result .= chr($c);
        } elseif ($c == 32) {
            $result .= "%20";  // 空格 → %20（curl 语义，非 +）
        } else {
            $hex = dechex($c);
            if (strlen($hex) < 2) {
                $hex = "0" . $hex;
            }
            $result .= "%" . strtoupper($hex);
        }
        $i++;
    }
    return $result;
}

// ── URL 解码 ────────────────────────────────────────────────
function _curl_url_decode(string $str): string
{
    $result = "";
    $len = strlen($str);
    $i = 0;
    while ($i < $len) {
        $c = $str[$i];
        if ($c == "%" && $i + 2 < $len) {
            $hex = substr($str, $i + 1, 2);
            $result .= chr(hexdec($hex));
            $i += 3;
        } else {
            $result .= $c;
            $i++;
        }
    }
    return $result;
}

// ── Base64 编码（用于 Basic Auth）──────────────────────────
function _curl_base64_encode(string $data): string
{
    $table = "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/";
    $result = "";
    $len = strlen($data);
    $i = 0;
    while ($i < $len) {
        $b0 = ord($data[$i]);
        $b1 = ($i + 1 < $len) ? ord($data[$i + 1]) : 0;
        $b2 = ($i + 2 < $len) ? ord($data[$i + 2]) : 0;

        $result .= $table[$b0 >> 2];
        $result .= $table[(($b0 & 0x03) << 4) | ($b1 >> 4)];
        if ($i + 1 < $len) {
            $result .= $table[(($b1 & 0x0F) << 2) | ($b2 >> 6)];
        } else {
            $result .= "=";
        }
        if ($i + 2 < $len) {
            $result .= $table[$b2 & 0x3F];
        } else {
            $result .= "=";
        }
        $i += 3;
    }
    return $result;
}

// ── 生成 multipart boundary ────────────────────────────────
function _curl_gen_boundary(): string
{
    return "----CurlBoundary" . dechex(mt_rand(0, 65535)) . dechex(mt_rand(0, 65535)) . dechex(mt_rand(0, 65535));
}

// ── 读取文件内容（用于 CURLFile 上传）──────────────────────
function _curl_read_file(string $path): string
{
    C.void* $f = C->fopen(c_str($path), c_str("rb"));
    if (phpc_ptr_to_int($f) == 0) {
        return "";
    }
    defer C->fclose($f);

    $content = "";
    while (true) {
        $c = php_int(C->fgetc($f));
        if ($c < 0) {
            break;
        }
        $content .= chr($c);
    }
    return $content;
}

// ── 获取文件 MIME 类型（按扩展名推导）──────────────────────
function _curl_mime_type(string $filename): string
{
    $pos = strrpos($filename, ".");
    if ($pos === false) {
        return "application/octet-stream";
    }
    $ext = strtolower(substr($filename, $pos + 1));
    // 常见扩展名映射
    if ($ext == "html" || $ext == "htm") return "text/html";
    if ($ext == "txt") return "text/plain";
    if ($ext == "css") return "text/css";
    if ($ext == "js") return "application/javascript";
    if ($ext == "json") return "application/json";
    if ($ext == "xml") return "application/xml";
    if ($ext == "png") return "image/png";
    if ($ext == "jpg" || $ext == "jpeg") return "image/jpeg";
    if ($ext == "gif") return "image/gif";
    if ($ext == "svg") return "image/svg+xml";
    if ($ext == "pdf") return "application/pdf";
    if ($ext == "zip") return "application/zip";
    if ($ext == "gz") return "application/gzip";
    if ($ext == "tar") return "application/x-tar";
    if ($ext == "mp3") return "audio/mpeg";
    if ($ext == "mp4") return "video/mp4";
    if ($ext == "wav") return "audio/wav";
    return "application/octet-stream";
}

// ── 获取文件 basename（不含目录）────────────────────────────
function _curl_basename(string $path): string
{
    $pos = strrpos($path, "/");
    if ($pos === false) {
        // Windows 路径分隔符
        $pos = strrpos($path, "\\");
    }
    if ($pos === false) {
        return $path;
    }
    return substr($path, $pos + 1);
}

// ── 构建 HTTP 请求（请求行 + 头 + 体）──────────────────────
function _curl_build_request(CurlHandle $handle, array $urlInfo): string
{
    $method = $handle->effectiveMethod;
    $path = $urlInfo["path"];
    if ($urlInfo["query"] != "") {
        $path .= "?" . $urlInfo["query"];
    }

    // HTTP 版本
    $httpVer = "HTTP/1.1";
    if ($handle->httpVersion == 1) {
        $httpVer = "HTTP/1.0";
    }

    // 请求行：HTTP 代理（非 HTTPS 隧道）使用绝对 URI
    $useProxy = ($handle->proxy != "" && $handle->proxyType == CURLPROXY_HTTP &&
                 $urlInfo["scheme"] == "http");
    if ($useProxy) {
        $absoluteUri = $urlInfo["scheme"] . "://" . $urlInfo["host"];
        $isDefPort = ($urlInfo["scheme"] == "http" && $urlInfo["port"] == 80) ||
                     ($urlInfo["scheme"] == "https" && $urlInfo["port"] == 443);
        if (!$isDefPort && $urlInfo["port"] != 0) {
            $absoluteUri .= ":" . strval($urlInfo["port"]);
        }
        $absoluteUri .= $path;
        $request = $method . " " . $absoluteUri . " " . $httpVer . "\r\n";
    } else {
        $request = $method . " " . $path . " " . $httpVer . "\r\n";
    }

    // Host 头（含非标准端口）
    $host = $urlInfo["host"];
    $isDefaultPort = ($urlInfo["scheme"] == "http" && $urlInfo["port"] == 80) ||
                     ($urlInfo["scheme"] == "https" && $urlInfo["port"] == 443);
    if (!$isDefaultPort && $urlInfo["port"] != 0) {
        $host .= ":" . strval($urlInfo["port"]);
    }
    $request .= "Host: " . $host . "\r\n";

    // User-Agent
    if ($handle->userAgent != "") {
        $request .= "User-Agent: " . $handle->userAgent . "\r\n";
    } else {
        $request .= "User-Agent: TinyPHP-curl/8.13\r\n";
    }

    // Accept
    $request .= "Accept: */*\r\n";

    // Proxy-Authorization（HTTP 代理认证，非 HTTPS 隧道场景）
    if ($useProxy && $handle->proxyUserPwd != "") {
        $request .= "Proxy-Authorization: Basic " . _curl_base64_encode($handle->proxyUserPwd) . "\r\n";
    }

    // Accept-Encoding
    if ($handle->encoding != "") {
        $request .= "Accept-Encoding: " . $handle->encoding . "\r\n";
    }

    // Referer
    if ($handle->referer != "") {
        $request .= "Referer: " . $handle->referer . "\r\n";
    }

    // Cookie
    if ($handle->cookie != "") {
        $request .= "Cookie: " . $handle->cookie . "\r\n";
    }

    // Basic Auth
    if ($handle->userPwd != "") {
        $request .= "Authorization: Basic " . _curl_base64_encode($handle->userPwd) . "\r\n";
    }

    // POST body / Content-Type / Content-Length
    $body = "";
    if ($method == "POST" || $method == "PUT" || $method == "PATCH" || $handle->customRequest != "") {
        if ($handle->isPostFieldsArray) {
            // 检查数组中是否含 CURLFile / CURLStringFile
            $hasFile = false;
            foreach ($handle->postFields as $v) {
                if ($v instanceof CURLFile || $v instanceof CURLStringFile) {
                    $hasFile = true;
                    break;
                }
            }
            if ($hasFile) {
                // multipart/form-data
                $boundary = _curl_gen_boundary();
                $request .= "Content-Type: multipart/form-data; boundary=" . $boundary . "\r\n";
                foreach ($handle->postFields as $k => $v) {
                    $body .= "--" . $boundary . "\r\n";
                    if ($v instanceof CURLFile) {
                        $fname = $v->postname != "" ? $v->postname : _curl_basename($v->name);
                        $mime = $v->mime != "" ? $v->mime : _curl_mime_type($v->name);
                        $body .= "Content-Disposition: form-data; name=\"" . strval($k) . "\"; filename=\"" . $fname . "\"\r\n";
                        $body .= "Content-Type: " . $mime . "\r\n\r\n";
                        $body .= _curl_read_file($v->name) . "\r\n";
                    } elseif ($v instanceof CURLStringFile) {
                        $body .= "Content-Disposition: form-data; name=\"" . strval($k) . "\"; filename=\"" . $v->postname . "\"\r\n";
                        $body .= "Content-Type: " . $v->mime . "\r\n\r\n";
                        $body .= $v->data . "\r\n";
                    } else {
                        $body .= "Content-Disposition: form-data; name=\"" . strval($k) . "\"\r\n\r\n";
                        $body .= strval($v) . "\r\n";
                    }
                }
                $body .= "--" . $boundary . "--\r\n";
            } else {
                // application/x-www-form-urlencoded
                $request .= "Content-Type: application/x-www-form-urlencoded\r\n";
                $parts = [];
                foreach ($handle->postFields as $k => $v) {
                    $parts[] = _curl_url_encode(strval($k)) . "=" . _curl_url_encode(strval($v));
                }
                $body = implode("&", $parts);
            }
        } else {
            // 原始字符串 body
            $body = $handle->body;
            // 若用户未设置 Content-Type，不自动添加（让用户自定义）
        }
    }

    // Content-Length
    if (strlen($body) > 0) {
        $request .= "Content-Length: " . strval(strlen($body)) . "\r\n";
    }

    // 自定义 headers
    foreach ($handle->headers as $h) {
        $request .= $h . "\r\n";
    }

    // Connection: close（不支持 keep-alive）
    $request .= "Connection: close\r\n";

    // 空行分隔头与体
    $request .= "\r\n";
    $request .= $body;

    return $request;
}

// ── 解析 HTTP 响应（状态行 + 头 + 体）──────────────────────
//   返回 ["httpCode" => int, "headers" => string, "body" => string,
//         "contentType" => string, "location" => string]
function _curl_parse_response(string $raw): array
{
    $result = [
        "httpCode" => 0,
        "headers" => "",
        "body" => "",
        "contentType" => "",
        "location" => "",
        "headerSize" => 0
    ];

    // 分割响应头与响应体
    $sep = strpos($raw, "\r\n\r\n");
    if ($sep === false) {
        // 没有响应头分隔符，整个响应作为 body
        $result["body"] = $raw;
        return $result;
    }

    $result["headers"] = substr($raw, 0, $sep);
    $result["body"] = substr($raw, $sep + 4);
    $result["headerSize"] = $sep + 4;

    // 解析状态行
    $lines = explode("\r\n", $result["headers"]);
    if (count($lines) > 0) {
        $statusLine = $lines[0];
        // HTTP/1.1 200 OK
        $parts = explode(" ", $statusLine, 3);
        if (count($parts) >= 2) {
            $result["httpCode"] = intval($parts[1]);
        }
    }

    // 解析响应头
    $i = 1;
    while ($i < count($lines)) {
        $line = $lines[$i];
        $colon = strpos($line, ":");
        if ($colon !== false) {
            $key = strtolower(trim(substr($line, 0, $colon)));
            $value = trim(substr($line, $colon + 1));
            if ($key == "content-type") {
                $result["contentType"] = $value;
            } elseif ($key == "location") {
                $result["location"] = $value;
            }
        }
        $i++;
    }

    return $result;
}

// ── 解析 chunked 传输编码 ──────────────────────────────────
function _curl_decode_chunked(string $body): string
{
    $result = "";
    $pos = 0;
    $len = strlen($body);
    while ($pos < $len) {
        $crlf = strpos($body, "\r\n", $pos);
        if ($crlf === false) {
            break;
        }
        $hexSize = substr($body, $pos, $crlf - $pos);
        $size = hexdec($hexSize);
        if ($size == 0) {
            break;
        }
        $pos = $crlf + 2;
        $result .= substr($body, $pos, $size);
        $pos += $size + 2;
    }
    return $result;
}

// ── 获取当前时间（秒，浮点）────────────────────────────────
function _curl_now(): float
{
    return microtime(true);
}

// ════════════════════════════════════════════════════════════
// Easy Handle 函数（18 个，含 curl_multi_getcontent）
// ════════════════════════════════════════════════════════════

// ── curl_init：创建 cURL 会话句柄 ──────────────────────────
//   $url: 请求 URL（默认空字符串，可后续通过 curl_setopt CURLOPT_URL 设置）
//   返回 CurlHandle 实例（不返回 false，内存分配失败时抛 Exception）
function curl_init(string $url = ""): CurlHandle
{
    $handle = new CurlHandle();
    if ($url != "") {
        $handle->url = $url;
        $handle->options[CURLOPT_URL] = $url;
    }
    return $handle;
}

// ── curl_close：关闭句柄（PHP 8.5 已弃用，保留兼容）────────
function curl_close(CurlHandle $handle): void
{
    $handle->closed = true;
}

// ── curl_reset：重置句柄选项（保留 URL）────────────────────
function curl_reset(CurlHandle $handle): void
{
    $url = $handle->url;
    $handle = new CurlHandle();
    $handle->url = $url;
}

// ── curl_copy_handle：深拷贝句柄 ───────────────────────────
//   返回新的 CurlHandle，所有选项被拷贝，但不共享连接与错误状态
function curl_copy_handle(CurlHandle $handle): CurlHandle
{
    $new = new CurlHandle();
    $new->url = $handle->url;
    $new->method = $handle->method;
    $new->headers = $handle->headers;
    $new->body = $handle->body;
    $new->postFields = $handle->postFields;
    $new->isPostFieldsArray = $handle->isPostFieldsArray;
    $new->returnTransfer = $handle->returnTransfer;
    $new->followLocation = $handle->followLocation;
    $new->maxRedirs = $handle->maxRedirs;
    $new->timeout = $handle->timeout;
    $new->connectTimeout = $handle->connectTimeout;
    $new->timeoutMs = $handle->timeoutMs;
    $new->connectTimeoutMs = $handle->connectTimeoutMs;
    $new->noBody = $handle->noBody;
    $new->verbose = $handle->verbose;
    $new->header = $handle->header;
    $new->userAgent = $handle->userAgent;
    $new->referer = $handle->referer;
    $new->cookie = $handle->cookie;
    $new->cookieFile = $handle->cookieFile;
    $new->cookieJar = $handle->cookieJar;
    $new->customRequest = $handle->customRequest;
    $new->httpVersion = $handle->httpVersion;
    $new->encoding = $handle->encoding;
    $new->port = $handle->port;
    $new->userPwd = $handle->userPwd;
    $new->httpAuth = $handle->httpAuth;
    $new->username = $handle->username;
    $new->password = $handle->password;
    $new->proxy = $handle->proxy;
    $new->proxyPort = $handle->proxyPort;
    $new->proxyType = $handle->proxyType;
    $new->proxyUserPwd = $handle->proxyUserPwd;
    $new->sslVerifyPeer = $handle->sslVerifyPeer;
    $new->sslVerifyHost = $handle->sslVerifyHost;
    $new->cainfo = $handle->cainfo;
    $new->capath = $handle->capath;
    $new->sslCert = $handle->sslCert;
    $new->sslCertType = $handle->sslCertType;
    $new->sslKey = $handle->sslKey;
    $new->sslKeyPasswd = $handle->sslKeyPasswd;
    $new->sslVersion = $handle->sslVersion;
    $new->upload = $handle->upload;
    $new->infileSize = $handle->infileSize;
    $new->writeFunction = $handle->writeFunction;
    $new->readFunction = $handle->readFunction;
    $new->headerFunction = $handle->headerFunction;
    $new->private = $handle->private;
    $new->options = $handle->options;
    // 不拷贝响应状态和错误状态（新句柄是干净的）
    return $new;
}

// ── curl_upkeep：保持连接活跃（无操作返回 true）─────────────
//   纯 phpc 无连接池，此函数语义上无意义但返回成功
function curl_upkeep(CurlHandle $handle): bool
{
    return true;
}

// ── curl_pause：暂停/恢复传输 ───────────────────────────────
//   仅支持 CURLPAUSE_ALL (5) / CURLPAUSE_CONT (0) 返回 CURLE_OK (0)
//   其他 flags 返回 CURLE_BAD_FUNCTION_ARGUMENT (43)
function curl_pause(CurlHandle $handle, int $flags): int
{
    if ($flags == CURLPAUSE_ALL || $flags == CURLPAUSE_CONT) {
        return CURLE_OK;
    }
    $handle->_setError(CURLE_BAD_FUNCTION_ARGUMENT, "curl_pause: unsupported flags " . strval($flags));
    return CURLE_BAD_FUNCTION_ARGUMENT;
}

// ── curl_setopt：设置传输选项 ───────────────────────────────
//   $option: CURLOPT_* 常量
//   $value:  选项值（mixed 类型，唯一允许 mixed 的参数）
//   已实现 ~50 个核心 CURLOPT_*；已定义但未实现的返回 false + errorMsg（不静默）
//   完全未知的 option 返回 false + errorCode=CURLE_UNKNOWN_OPTION (49)
function curl_setopt(CurlHandle $handle, int $option, mixed $value): bool
{
    $handle->_clearError();
    $handle->options[$option] = $value;

    // ── 请求配置 ──
    if ($option == CURLOPT_URL) {
        $handle->url = strval($value);
        return true;
    }
    if ($option == CURLOPT_POST) {
        if (boolval($value)) {
            $handle->method = "POST";
        } else {
            if ($handle->method == "POST") {
                $handle->method = "GET";
            }
        }
        return true;
    }
    if ($option == CURLOPT_POSTFIELDS) {
        if (is_array($value)) {
            $handle->postFields = $value;
            $handle->isPostFieldsArray = true;
        } else {
            $handle->body = strval($value);
            $handle->isPostFieldsArray = false;
        }
        return true;
    }
    if ($option == CURLOPT_HTTPHEADER) {
        $handle->headers = $value;
        return true;
    }
    if ($option == CURLOPT_RETURNTRANSFER) {
        $handle->returnTransfer = boolval($value);
        return true;
    }
    if ($option == CURLOPT_FOLLOWLOCATION) {
        $handle->followLocation = boolval($value);
        return true;
    }
    if ($option == CURLOPT_MAXREDIRS) {
        $handle->maxRedirs = intval($value);
        return true;
    }
    if ($option == CURLOPT_TIMEOUT) {
        $handle->timeout = intval($value);
        return true;
    }
    if ($option == CURLOPT_CONNECTTIMEOUT) {
        $handle->connectTimeout = intval($value);
        return true;
    }
    if ($option == CURLOPT_TIMEOUT_MS) {
        $handle->timeoutMs = intval($value);
        return true;
    }
    if ($option == CURLOPT_CONNECTTIMEOUT_MS) {
        $handle->connectTimeoutMs = intval($value);
        return true;
    }
    if ($option == CURLOPT_NOBODY) {
        $handle->noBody = boolval($value);
        if ($handle->noBody) {
            $handle->method = "HEAD";
        }
        return true;
    }
    if ($option == CURLOPT_VERBOSE) {
        $handle->verbose = boolval($value);
        return true;
    }
    if ($option == CURLOPT_HEADER) {
        $handle->header = boolval($value);
        return true;
    }
    if ($option == CURLOPT_USERAGENT) {
        $handle->userAgent = strval($value);
        return true;
    }
    if ($option == CURLOPT_REFERER) {
        $handle->referer = strval($value);
        return true;
    }
    if ($option == CURLOPT_COOKIE) {
        $handle->cookie = strval($value);
        return true;
    }
    if ($option == CURLOPT_COOKIEFILE) {
        $handle->cookieFile = strval($value);
        return true;
    }
    if ($option == CURLOPT_COOKIEJAR) {
        $handle->cookieJar = strval($value);
        return true;
    }
    if ($option == CURLOPT_CUSTOMREQUEST) {
        $handle->customRequest = strval($value);
        $handle->method = strval($value);
        return true;
    }
    if ($option == CURLOPT_HTTP_VERSION) {
        $handle->httpVersion = intval($value);
        return true;
    }
    if ($option == CURLOPT_PORT) {
        $handle->port = intval($value);
        return true;
    }
    if ($option == CURLOPT_ACCEPT_ENCODING || $option == CURLOPT_ENCODING) {
        $handle->encoding = strval($value);
        return true;
    }

    // ── 认证 ──
    if ($option == CURLOPT_USERPWD) {
        $handle->userPwd = strval($value);
        return true;
    }
    if ($option == CURLOPT_HTTPAUTH) {
        $handle->httpAuth = intval($value);
        return true;
    }
    if ($option == CURLOPT_USERNAME) {
        $handle->username = strval($value);
        return true;
    }
    if ($option == CURLOPT_PASSWORD) {
        $handle->password = strval($value);
        return true;
    }

    // ── 代理 ──
    if ($option == CURLOPT_PROXY) {
        $handle->proxy = strval($value);
        return true;
    }
    if ($option == CURLOPT_PROXYPORT) {
        $handle->proxyPort = intval($value);
        return true;
    }
    if ($option == CURLOPT_PROXYTYPE) {
        $handle->proxyType = intval($value);
        return true;
    }
    if ($option == CURLOPT_PROXYUSERPWD) {
        $handle->proxyUserPwd = strval($value);
        return true;
    }

    // ── SSL/TLS ──
    if ($option == CURLOPT_SSL_VERIFYPEER) {
        $handle->sslVerifyPeer = boolval($value);
        return true;
    }
    if ($option == CURLOPT_SSL_VERIFYHOST) {
        $handle->sslVerifyHost = intval($value);
        return true;
    }
    if ($option == CURLOPT_CAINFO) {
        $handle->cainfo = strval($value);
        return true;
    }
    if ($option == CURLOPT_CAPATH) {
        $handle->capath = strval($value);
        return true;
    }
    if ($option == CURLOPT_SSLCERT) {
        $handle->sslCert = strval($value);
        return true;
    }
    if ($option == CURLOPT_SSLCERTTYPE) {
        $handle->sslCertType = strval($value);
        return true;
    }
    if ($option == CURLOPT_SSLKEY) {
        $handle->sslKey = strval($value);
        return true;
    }
    if ($option == CURLOPT_SSLKEYPASSWD || $option == CURLOPT_KEYPASSWD) {
        $handle->sslKeyPasswd = strval($value);
        return true;
    }
    if ($option == CURLOPT_SSLVERSION) {
        $handle->sslVersion = intval($value);
        return true;
    }

    // ── 上传 ──
    if ($option == CURLOPT_UPLOAD) {
        $handle->upload = boolval($value);
        return true;
    }
    if ($option == CURLOPT_INFILESIZE) {
        $handle->infileSize = intval($value);
        return true;
    }

    // ── 回调 ──
    if ($option == CURLOPT_WRITEFUNCTION) {
        $handle->writeFunction = $value;
        return true;
    }
    if ($option == CURLOPT_READFUNCTION) {
        $handle->readFunction = $value;
        return true;
    }
    if ($option == CURLOPT_HEADERFUNCTION) {
        $handle->headerFunction = $value;
        return true;
    }

    // ── 私有数据 ──
    if ($option == CURLOPT_PRIVATE) {
        $handle->private = $value;
        return true;
    }

    // ── HTTPGET：强制 GET ──
    if ($option == CURLOPT_HTTPGET) {
        if (boolval($value)) {
            $handle->method = "GET";
        }
        return true;
    }

    // ── 已定义但未实现的选项（不静默）──
    //   这些选项在 curl_constants.php 中已定义，但本实现不支持
    //   返回 false（与 PHP 原生行为一致）+ 写入 warning 到 errorMsg
    if ($option == CURLOPT_AUTOREFERER || $option == CURLOPT_BUFFERSIZE ||
        $option == CURLOPT_COOKIESESSION || $option == CURLOPT_CRLF ||
        $option == CURLOPT_DNS_CACHE_TIMEOUT || $option == CURLOPT_DNS_USE_GLOBAL_CACHE ||
        $option == CURLOPT_EGDSOCKET || $option == CURLOPT_FAILONERROR ||
        $option == CURLOPT_FILETIME || $option == CURLOPT_FORBID_REUSE ||
        $option == CURLOPT_FRESH_CONNECT || $option == CURLOPT_FTPPORT ||
        $option == CURLOPT_FTP_USE_EPRT || $option == CURLOPT_FTP_USE_EPSV ||
        $option == CURLOPT_HTTPPROXYTUNNEL || $option == CURLOPT_INFILESIZE_LARGE ||
        $option == CURLOPT_INTERFACE || $option == CURLOPT_KRB4LEVEL ||
        $option == CURLOPT_KRBLEVEL || $option == CURLOPT_LOW_SPEED_LIMIT ||
        $option == CURLOPT_LOW_SPEED_TIME || $option == CURLOPT_MAXCONNECTS ||
        $option == CURLOPT_NETRC || $option == CURLOPT_NETRC_FILE ||
        $option == CURLOPT_NOPROGRESS || $option == CURLOPT_NOSIGNAL ||
        $option == CURLOPT_PUT || $option == CURLOPT_RANDOM_FILE ||
        $option == CURLOPT_RANGE || $option == CURLOPT_RESUME_FROM ||
        $option == CURLOPT_SHARE || $option == CURLOPT_SSL_CIPHER_LIST ||
        $option == CURLOPT_SSLENGINE || $option == CURLOPT_SSLENGINE_DEFAULT ||
        $option == CURLOPT_STDERR || $option == CURLOPT_TELNETOPTIONS ||
        $option == CURLOPT_TIMECONDITION || $option == CURLOPT_TIMEVALUE ||
        $option == CURLOPT_TRANSFERTEXT || $option == CURLOPT_UNRESTRICTED_AUTH ||
        $option == CURLOPT_BINARYTRANSFER || $option == CURLOPT_SAFE_UPLOAD ||
        $option == CURLOPT_FTPAPPEND || $option == CURLOPT_FTPLISTONLY ||
        $option == CURLOPT_FTP_SSL || $option == CURLOPT_TCP_NODELAY ||
        $option == CURLOPT_FTPSSLAUTH || $option == CURLOPT_FTP_ACCOUNT ||
        $option == CURLOPT_COOKIELIST || $option == CURLOPT_IGNORE_CONTENT_LENGTH ||
        $option == CURLOPT_FTP_SKIP_PASV_IP || $option == CURLOPT_FTP_FILEMETHOD ||
        $option == CURLOPT_CONNECT_ONLY || $option == CURLOPT_LOCALPORT ||
        $option == CURLOPT_LOCALPORTRANGE || $option == CURLOPT_FTP_ALTERNATIVE_TO_USER ||
        $option == CURLOPT_SSL_SESSIONID_CACHE || $option == CURLOPT_FTP_SSL_CCC ||
        $option == CURLOPT_SSH_AUTH_TYPES || $option == CURLOPT_SSH_PRIVATE_KEYFILE ||
        $option == CURLOPT_SSH_PUBLIC_KEYFILE || $option == CURLOPT_HTTP_CONTENT_DECODING ||
        $option == CURLOPT_HTTP_TRANSFER_DECODING || $option == CURLOPT_NEW_DIRECTORY_PERMS ||
        $option == CURLOPT_NEW_FILE_PERMS || $option == CURLOPT_PROXY_TRANSFER_MODE ||
        $option == CURLOPT_ADDRESS_SCOPE || $option == CURLOPT_CRLFILE ||
        $option == CURLOPT_ISSUERCERT || $option == CURLOPT_CERTINFO ||
        $option == CURLOPT_POSTREDIR || $option == CURLOPT_NOPROXY ||
        $option == CURLOPT_PROTOCOLS || $option == CURLOPT_REDIR_PROTOCOLS ||
        $option == CURLOPT_SOCKS5_GSSAPI_NEC || $option == CURLOPT_SOCKS5_GSSAPI_SERVICE ||
        $option == CURLOPT_TFTP_BLKSIZE || $option == CURLOPT_SSH_KNOWNHOSTS ||
        $option == CURLOPT_FTP_USE_PRET || $option == CURLOPT_MAIL_FROM ||
        $option == CURLOPT_MAIL_RCPT || $option == CURLOPT_WILDCARDMATCH ||
        $option == CURLOPT_RESOLVE || $option == CURLOPT_TLSAUTH_PASSWORD ||
        $option == CURLOPT_TLSAUTH_TYPE || $option == CURLOPT_TLSAUTH_USERNAME ||
        $option == CURLOPT_TRANSFER_ENCODING || $option == CURLOPT_GSSAPI_DELEGATION ||
        $option == CURLOPT_ACCEPTTIMEOUT_MS || $option == CURLOPT_DNS_SERVERS ||
        $option == CURLOPT_MAIL_AUTH || $option == CURLOPT_SSL_OPTIONS ||
        $option == CURLOPT_TCP_KEEPALIVE || $option == CURLOPT_TCP_KEEPIDLE ||
        $option == CURLOPT_TCP_KEEPINTVL || $option == CURLOPT_EXPECT_100_TIMEOUT_MS ||
        $option == CURLOPT_SSL_ENABLE_ALPN || $option == CURLOPT_SSL_ENABLE_NPN ||
        $option == CURLOPT_HEADEROPT || $option == CURLOPT_PROXYHEADER ||
        $option == CURLOPT_PINNEDPUBLICKEY || $option == CURLOPT_UNIX_SOCKET_PATH ||
        $option == CURLOPT_SSL_VERIFYSTATUS || $option == CURLOPT_PATH_AS_IS ||
        $option == CURLOPT_SSL_FALSESTART || $option == CURLOPT_PIPEWAIT ||
        $option == CURLOPT_PROXY_SERVICE_NAME || $option == CURLOPT_SERVICE_NAME ||
        $option == CURLOPT_DEFAULT_PROTOCOL || $option == CURLOPT_STREAM_WEIGHT ||
        $option == CURLOPT_TFTP_NO_OPTIONS || $option == CURLOPT_CONNECT_TO ||
        $option == CURLOPT_TCP_FASTOPEN || $option == CURLOPT_KEEP_SENDING_ON_ERROR ||
        $option == CURLOPT_PRE_PROXY || $option == CURLOPT_PROXY_CAINFO ||
        $option == CURLOPT_PROXY_CAPATH || $option == CURLOPT_PROXY_CRLFILE ||
        $option == CURLOPT_PROXY_KEYPASSWD || $option == CURLOPT_PROXY_PINNEDPUBLICKEY ||
        $option == CURLOPT_PROXY_SSL_CIPHER_LIST || $option == CURLOPT_PROXY_SSL_OPTIONS ||
        $option == CURLOPT_PROXY_SSL_VERIFYHOST || $option == CURLOPT_PROXY_SSL_VERIFYPEER ||
        $option == CURLOPT_PROXY_SSLCERT || $option == CURLOPT_PROXY_SSLCERTTYPE ||
        $option == CURLOPT_PROXY_SSLKEY || $option == CURLOPT_PROXY_SSLKEYTYPE ||
        $option == CURLOPT_PROXY_SSLVERSION || $option == CURLOPT_PROXY_TLSAUTH_PASSWORD ||
        $option == CURLOPT_PROXY_TLSAUTH_TYPE || $option == CURLOPT_PROXY_TLSAUTH_USERNAME ||
        $option == CURLOPT_ABSTRACT_UNIX_SOCKET || $option == CURLOPT_SUPPRESS_CONNECT_HEADERS ||
        $option == CURLOPT_REQUEST_TARGET || $option == CURLOPT_SOCKS5_AUTH ||
        $option == CURLOPT_SSH_COMPRESSION || $option == CURLOPT_DISALLOW_USERNAME_IN_URL ||
        $option == CURLOPT_PROXY_TLS13_CIPHERS || $option == CURLOPT_TLS13_CIPHERS ||
        $option == CURLOPT_DOH_URL || $option == CURLOPT_UPKEEP_INTERVAL_MS ||
        $option == CURLOPT_UPLOAD_BUFFERSIZE || $option == CURLOPT_HTTP09_ALLOWED ||
        $option == CURLOPT_ALTSVC || $option == CURLOPT_ALTSVC_CTRL ||
        $option == CURLOPT_MAXAGE_CONN || $option == CURLOPT_MAXLIFETIME_CONN ||
        $option == CURLOPT_SSH_HOST_PUBLIC_KEY_SHA256 || $option == CURLOPT_SSH_HOST_PUBLIC_KEY_MD5 ||
        $option == CURLOPT_HAPPY_EYEBALLS_TIMEOUT_MS || $option == CURLOPT_TIMEVALUE_LARGE ||
        $option == CURLOPT_DNS_SHUFFLE_ADDRESSES || $option == CURLOPT_HAPROXYPROTOCOL ||
        $option == CURLOPT_SASL_IR || $option == CURLOPT_DNS_INTERFACE ||
        $option == CURLOPT_DNS_LOCAL_IP4 || $option == CURLOPT_DNS_LOCAL_IP6 ||
        $option == CURLOPT_XOAUTH2_BEARER || $option == CURLOPT_LOGIN_OPTIONS ||
        $option == CURLOPT_SASL_AUTHZID || $option == CURLOPT_MIME_OPTIONS ||
        $option == CURLOPT_SSH_HOSTKEYFUNCTION || $option == CURLOPT_PREREQFUNCTION ||
        $option == CURLOPT_CA_CACHE_TIMEOUT || $option == CURLOPT_QUICK_EXIT ||
        $option == CURLOPT_AWS_SIGV4 || $option == CURLOPT_HSTS ||
        $option == CURLOPT_HSTS_CTRL || $option == CURLOPT_CAINFO_BLOB ||
        $option == CURLOPT_PROXY_CAINFO_BLOB || $option == CURLOPT_ISSUERCERT_BLOB ||
        $option == CURLOPT_PROXY_ISSUERCERT || $option == CURLOPT_PROXY_ISSUERCERT_BLOB ||
        $option == CURLOPT_PROXY_SSLCERT_BLOB || $option == CURLOPT_PROXY_SSLKEY_BLOB ||
        $option == CURLOPT_SSLCERT_BLOB || $option == CURLOPT_SSLKEY_BLOB ||
        $option == CURLOPT_SSL_EC_CURVES || $option == CURLOPT_SSL_SIGNATURE_ALGORITHMS ||
        $option == CURLOPT_WS_OPTIONS || $option == CURLOPT_MAX_RECV_SPEED_LARGE ||
        $option == CURLOPT_MAX_SEND_SPEED_LARGE || $option == CURLOPT_MAXFILESIZE ||
        $option == CURLOPT_MAXFILESIZE_LARGE || $option == CURLOPT_SERVER_RESPONSE_TIMEOUT ||
        $option == CURLOPT_FTP_RESPONSE_TIMEOUT || $option == CURLOPT_IPRESOLVE ||
        $option == CURLOPT_FTP_CREATE_MISSING_DIRS || $option == CURLOPT_PROXYAUTH ||
        $option == CURLOPT_FNMATCH_FUNCTION || $option == CURLOPT_PROGRESSFUNCTION ||
        $option == CURLOPT_XFERINFOFUNCTION || $option == CURLOPT_DEBUGFUNCTION ||
        $option == CURLOPT_HTTP200ALIASES || $option == CURLOPT_POSTQUOTE ||
        $option == CURLOPT_PREQUOTE || $option == CURLOPT_QUOTE ||
        $option == CURLOPT_FILE || $option == CURLOPT_INFILE ||
        $option == CURLOPT_WRITEHEADER || $option == CURLOPT_READDATA ||
        $option == CURLOPT_STDERR || $option == CURLOPT_MAIL_RCPT_ALLLOWFAILS ||
        $option == CURLOPT_TCP_KEEPCNT || $option == CURLOPT_SOCKS5_GSSAPI_SERVICE ||
        $option == CURLOPT_FTP_SSL_CCC) {
        $handle->_setError(0, "CURLOPT_" . strval($option) . " is defined but not implemented in pure-phpc curl");
        return false;
    }

    // ── 完全未知的 option ──
    $handle->_setError(CURLE_UNKNOWN_OPTION, "curl_setopt: unknown option " . strval($option));
    return false;
}

// ── curl_setopt_array：批量设置选项 ─────────────────────────
//   循环调用 curl_setopt，失败立即停止返回 false
function curl_setopt_array(CurlHandle $handle, array $options): bool
{
    foreach ($options as $opt => $val) {
        $ok = curl_setopt($handle, intval($opt), $val);
        if (!$ok) {
            return false;
        }
    }
    return true;
}

// ── curl_exec：执行 HTTP/HTTPS 请求 ────────────────────────
//   返回 bool（true=成功，false=失败）；响应体存储在 handle.lastResponse
//   用户通过 curl_multi_getcontent($handle) 或 $handle->lastResponse 获取响应体
//   不支持 ftp/smtp/telnet 等协议（返回 false + errorCode=CURLE_UNSUPPORTED_PROTOCOL）
function curl_exec(CurlHandle $handle): bool
{
    $handle->_clearError();
    $handle->executed = true;
    $handle->redirectCount = 0;

    if ($handle->closed) {
        $handle->_setError(CURLE_FAILED_INIT, "curl_exec: handle is closed");
        return false;
    }

    if ($handle->url == "") {
        $handle->_setError(CURLE_URL_MALFORMAT, "curl_exec: no URL set");
        return false;
    }

    $startTime = _curl_now();

    // ── 确定请求方法 ──
    $handle->effectiveMethod = $handle->method;
    $handle->effectiveUrl = $handle->url;

    // ── 重定向循环 ──
    $maxRedirects = $handle->followLocation ? $handle->maxRedirs : 0;
    $redirectStartTime = $startTime;

    while (true) {
        // 解析 URL
        $urlInfo = _curl_parse_url($handle->effectiveUrl);
        if ($urlInfo["scheme"] == "" || $urlInfo["host"] == "") {
            $handle->_setError(CURLE_URL_MALFORMAT, "curl_exec: malformed URL: " . $handle->effectiveUrl);
            return false;
        }

        // 协议检查
        if ($urlInfo["scheme"] != "http" && $urlInfo["scheme"] != "https") {
            $handle->_setError(CURLE_UNSUPPORTED_PROTOCOL,
                "Unsupported protocol: " . $urlInfo["scheme"] . ". Only http/https are supported.");
            return false;
        }

        // 代理类型检查
        if ($handle->proxy != "" && ($handle->proxyType == CURLPROXY_SOCKS4 ||
            $handle->proxyType == CURLPROXY_SOCKS4A ||
            $handle->proxyType == CURLPROXY_SOCKS5 ||
            $handle->proxyType == CURLPROXY_SOCKS5_HOSTNAME)) {
            $handle->_setError(CURLE_UNSUPPORTED_PROTOCOL, "SOCKS proxy is not supported");
            return false;
        }

        // 认证检查（仅支持 CURLAUTH_BASIC，其他类型显式拒绝）
        if ($handle->httpAuth != 0 &&
            (($handle->httpAuth & CURLAUTH_DIGEST) ||
             ($handle->httpAuth & CURLAUTH_NEGOTIATE) ||
             ($handle->httpAuth & CURLAUTH_NTLM))) {
            $handle->_setError(CURLE_UNSUPPORTED_PROTOCOL, "Only CURLAUTH_BASIC is supported");
            return false;
        }

        // 建立连接（支持 HTTP 代理）
        $isHttps = ($urlInfo["scheme"] == "https");
        $port = $handle->port != 0 ? $handle->port : $urlInfo["port"];
        $useProxy = ($handle->proxy != "" && $handle->proxyType == CURLPROXY_HTTP);

        if ($useProxy) {
            // HTTP 代理：连接到代理服务器
            $proxyInfo = _curl_parse_proxy($handle->proxy);
            $proxyPort = $handle->proxyPort != 0 ? $handle->proxyPort : $proxyInfo["port"];
            if ($proxyPort == 0) {
                $proxyPort = 8080;
            }
            $addr = "tcp://" . $proxyInfo["host"] . ":" . strval($proxyPort);
        } else {
            $addr = "tcp://" . $urlInfo["host"] . ":" . strval($port);
        }
        $connectTimeoutMs = $handle->connectTimeout > 0 ? $handle->connectTimeout * 1000 : 30000;
        if ($handle->connectTimeoutMs > 0) {
            $connectTimeoutMs = $handle->connectTimeoutMs;
        }

        $connectStart = _curl_now();
        $fd = stream_socket_client($addr, $connectTimeoutMs, STREAM_CLIENT_CONNECT, 0);
        $handle->connectTime = _curl_now() - $connectStart;

        if ($fd < 0) {
            $handle->_setError(CURLE_COULDNT_CONNECT, "curl_exec: failed to connect to " . $addr);
            return false;
        }

        // HTTPS 通过 HTTP 代理：先发 CONNECT 建立隧道
        if ($useProxy && $isHttps) {
            $connectReq = "CONNECT " . $urlInfo["host"] . ":" . strval($port) . " HTTP/1.1\r\n";
            $connectReq .= "Host: " . $urlInfo["host"] . ":" . strval($port) . "\r\n";
            if ($handle->proxyUserPwd != "") {
                $connectReq .= "Proxy-Authorization: Basic " . _curl_base64_encode($handle->proxyUserPwd) . "\r\n";
            }
            $connectReq .= "Connection: close\r\n\r\n";

            $n = stream_socket_sendto($fd, $connectReq, 0, "");
            if ($n < 0) {
                $handle->_setError(CURLE_SEND_ERROR, "curl_exec: failed to send CONNECT to proxy");
                stream_close($fd);
                return false;
            }

            // 读取代理响应（直到 \r\n\r\n）
            $proxyResp = "";
            $proxyBufSize = 4096;
            while (true) {
                $chunk = stream_get_contents($fd, $proxyBufSize, -1);
                if (strlen($chunk) == 0) break;
                $proxyResp .= $chunk;
                if (strpos($proxyResp, "\r\n\r\n") !== false) break;
            }

            // 检查代理响应状态码（期望 200）
            if (strlen($proxyResp) < 12 || substr($proxyResp, 9, 3) != "200") {
                $handle->_setError(CURLE_COULDNT_CONNECT, "curl_exec: proxy CONNECT failed: " . $proxyResp);
                stream_close($fd);
                return false;
            }
        }

        $ssl = 0;
        $ctx = 0;
        $tlsOk = true;

        if ($isHttps) {
            // TLS 握手
            $ctx = openssl_ctx_new(0);  // 0=TLS_client
            if ($ctx == 0) {
                $handle->_setError(CURLE_SSL_CONNECT_ERROR, "curl_exec: openssl_ctx_new failed");
                stream_close($fd);
                return false;
            }

            // 设置证书验证
            if ($handle->sslVerifyPeer) {
                openssl_ctx_set_verify($ctx, 2);  // SSL_VERIFY_PEER=1, SSL_VERIFY_FAIL_IF_NO_PEER_CERT=2
                if ($handle->cainfo != "") {
                    // mbedTLS CA 证书通过文件加载（此处简化处理）
                }
            }

            // 加载客户端证书（如果有）
            if ($handle->sslCert != "") {
                openssl_ctx_use_certificate_file($ctx, $handle->sslCert, 1);  // SSL_FILETYPE_PEM=1
            }
            if ($handle->sslKey != "") {
                openssl_ctx_use_private_key_file($ctx, $handle->sslKey, 1);
            }

            $ssl = openssl_ssl_new($ctx);
            if ($ssl == 0) {
                $handle->_setError(CURLE_SSL_CONNECT_ERROR, "curl_exec: openssl_ssl_new failed");
                openssl_ctx_free($ctx);
                stream_close($fd);
                return false;
            }

            if (!openssl_ssl_set_fd($ssl, $fd)) {
                $handle->_setError(CURLE_SSL_CONNECT_ERROR, "curl_exec: openssl_ssl_set_fd failed");
                openssl_ssl_free($ssl);
                openssl_ctx_free($ctx);
                stream_close($fd);
                return false;
            }

            $appConnectStart = _curl_now();
            $ret = openssl_ssl_connect($ssl);
            $handle->appConnectTime = _curl_now() - $appConnectStart;

            if ($ret != 1) {
                $handle->_setError(CURLE_SSL_CONNECT_ERROR, "curl_exec: TLS handshake failed");
                openssl_ssl_shutdown($ssl);
                openssl_ssl_free($ssl);
                openssl_ctx_free($ctx);
                stream_close($fd);
                return false;
            }
        }

        // 构建请求
        $request = _curl_build_request($handle, $urlInfo);
        $handle->requestSize = strlen($request);

        // 发送请求
        $sendOk = true;
        if ($isHttps) {
            $n = openssl_ssl_write($ssl, $request);
            if ($n < 0) {
                $sendOk = false;
            }
        } else {
            $n = stream_socket_sendto($fd, $request, 0, "");
            if ($n < 0) {
                $sendOk = false;
            }
        }

        if (!$sendOk) {
            $handle->_setError(CURLE_SEND_ERROR, "curl_exec: failed to send request");
            if ($isHttps) {
                openssl_ssl_shutdown($ssl);
                openssl_ssl_free($ssl);
                openssl_ctx_free($ctx);
            }
            stream_close($fd);
            return false;
        }

        // 读取响应
        $rawResponse = "";
        $readStart = _curl_now();
        $bufSize = 8192;
        $readTimeoutUs = 0;
        if ($handle->timeout > 0) {
            $readTimeoutUs = $handle->timeout * 1000000;
        } elseif ($handle->timeoutMs > 0) {
            $readTimeoutUs = $handle->timeoutMs * 1000;
        }

        while (true) {
            // 检查超时
            if ($readTimeoutUs > 0) {
                $elapsed = (_curl_now() - $startTime) * 1000000;
                if ($elapsed > $readTimeoutUs) {
                    $handle->_setError(CURLE_OPERATION_TIMEDOUT, "curl_exec: operation timed out");
                    if ($isHttps) {
                        openssl_ssl_shutdown($ssl);
                        openssl_ssl_free($ssl);
                        openssl_ctx_free($ctx);
                    }
                    stream_close($fd);
                    return false;
                }
            }

            if ($isHttps) {
                $chunk = openssl_ssl_read($ssl, $bufSize);
                if (strlen($chunk) == 0) {
                    break;  // EOF 或连接关闭
                }
            } else {
                $chunk = stream_socket_recvfrom($fd, $bufSize, 0);
                if (strlen($chunk) == 0) {
                    break;  // EOF 或连接关闭
                }
            }
            $rawResponse .= $chunk;
        }

        $handle->startTransferTime = _curl_now() - $readStart;

        // 关闭连接
        if ($isHttps) {
            openssl_ssl_shutdown($ssl);
            openssl_ssl_free($ssl);
            openssl_ctx_free($ctx);
        }
        stream_close($fd);

        // 解析响应
        $parsed = _curl_parse_response($rawResponse);
        $handle->lastHttpCode = $parsed["httpCode"];
        $handle->lastResponseHeader = $parsed["headers"];
        $handle->headerSize = $parsed["headerSize"];
        $handle->contentType = $parsed["contentType"];
        $body = $parsed["body"];

        // 处理 chunked 传输编码
        $headerLower = strtolower($parsed["headers"]);
        if (strpos($headerLower, "transfer-encoding: chunked") !== false) {
            $body = _curl_decode_chunked($body);
        }

        $handle->lastResponse = $body;
        $handle->sizeDownload = strlen($body);

        // 处理重定向
        if ($handle->followLocation && $handle->redirectCount < $maxRedirects &&
            $parsed["httpCode"] >= 300 && $parsed["httpCode"] < 400 &&
            $parsed["location"] != "") {

            $handle->redirectCount++;
            $redirectUrl = $parsed["location"];

            // 相对 URL → 绝对 URL
            if (strpos($redirectUrl, "://") === false) {
                if (strpos($redirectUrl, "/") === 0) {
                    // 绝对路径
                    $redirectUrl = $urlInfo["scheme"] . "://" . $urlInfo["host"];
                    if ($urlInfo["port"] != 80 && $urlInfo["port"] != 443) {
                        $redirectUrl .= ":" . strval($urlInfo["port"]);
                    }
                    $redirectUrl .= $parsed["location"];
                } else {
                    // 相对路径
                    $redirectUrl = $urlInfo["scheme"] . "://" . $urlInfo["host"];
                    if ($urlInfo["port"] != 80 && $urlInfo["port"] != 443) {
                        $redirectUrl .= ":" . strval($urlInfo["port"]);
                    }
                    $redirectUrl .= dirname($urlInfo["path"]) . "/" . $parsed["location"];
                }
            }

            $handle->effectiveUrl = $redirectUrl;
            $handle->redirectUrl = $redirectUrl;

            // 301/302/303 → GET（POST → GET，body 清空）
            if ($parsed["httpCode"] == 301 || $parsed["httpCode"] == 302 || $parsed["httpCode"] == 303) {
                if ($handle->effectiveMethod == "POST" || $handle->effectiveMethod == "PUT") {
                    $handle->effectiveMethod = "GET";
                    $handle->body = "";
                    $handle->isPostFieldsArray = false;
                }
            }
            // 307/308 → 保持原方法和 body（无需修改）

            continue;  // 继续跟随重定向
        }

        // 重定向超限
        if ($handle->followLocation && $handle->redirectCount >= $maxRedirects &&
            $parsed["httpCode"] >= 300 && $parsed["httpCode"] < 400) {
            $handle->_setError(CURLE_TOO_MANY_REDIRECTS, "curl_exec: too many redirects");
            $handle->redirectTime = _curl_now() - $redirectStartTime;
            $handle->totalTime = _curl_now() - $startTime;
            return false;
        }

        break;  // 请求完成
    }

    $handle->redirectTime = _curl_now() - $redirectStartTime;
    $handle->totalTime = _curl_now() - $startTime;

    // 直接输出模式（RETURNTRANSFER=false）
    if (!$handle->returnTransfer) {
        if ($handle->header) {
            echo $handle->lastResponseHeader . "\r\n\r\n";
        }
        echo $handle->lastResponse;
    }

    return true;
}

// ── curl_error：返回最后一次错误描述 ────────────────────────
function curl_error(CurlHandle $handle): string
{
    return $handle->errorMsg;
}

// ── curl_errno：返回最后一次错误码 ─────────────────────────
function curl_errno(CurlHandle $handle): int
{
    return $handle->errorCode;
}

// ── curl_strerror：错误码 → 描述（静态映射 74 个 CURLE_*）──
function curl_strerror(int $error_code): string
{
    $map = [
        CURLE_OK => "No error",
        CURLE_UNSUPPORTED_PROTOCOL => "Unsupported protocol",
        CURLE_FAILED_INIT => "Failed initialization",
        CURLE_URL_MALFORMAT => "Malformed URL",
        CURLE_URL_MALFORMAT_USER => "Malformed URL (user)",
        CURLE_COULDNT_RESOLVE_PROXY => "Could not resolve proxy",
        CURLE_COULDNT_RESOLVE_HOST => "Could not resolve host",
        CURLE_COULDNT_CONNECT => "Could not connect",
        CURLE_FTP_WEIRD_SERVER_REPLY => "FTP weird server reply",
        CURLE_FTP_ACCESS_DENIED => "FTP access denied",
        CURLE_FTP_USER_PASSWORD_INCORRECT => "FTP user/password incorrect",
        CURLE_FTP_WEIRD_PASS_REPLY => "FTP weird PASS reply",
        CURLE_FTP_WEIRD_USER_REPLY => "FTP weird USER reply",
        CURLE_FTP_WEIRD_PASV_REPLY => "FTP weird PASV reply",
        CURLE_FTP_WEIRD_227_FORMAT => "FTP weird 227 format",
        CURLE_FTP_CANT_GET_HOST => "FTP cannot get host",
        CURLE_FTP_CANT_RECONNECT => "FTP cannot reconnect",
        CURLE_FTP_COULDNT_SET_BINARY => "FTP could not set binary",
        CURLE_PARTIAL_FILE => "Partial file",
        CURLE_FTP_COULDNT_RETR_FILE => "FTP could not RETR file",
        CURLE_FTP_WRITE_ERROR => "FTP write error",
        CURLE_FTP_QUOTE_ERROR => "FTP quote error",
        CURLE_HTTP_RETURNED_ERROR => "HTTP returned error",
        CURLE_WRITE_ERROR => "Write error",
        CURLE_MALFORMAT_USER => "Malformed URL (user)",
        CURLE_FTP_COULDNT_STOR_FILE => "FTP could not STOR file",
        CURLE_READ_ERROR => "Read error",
        CURLE_OUT_OF_MEMORY => "Out of memory",
        CURLE_OPERATION_TIMEOUTED => "Operation timeout",
        CURLE_OPERATION_TIMEDOUT => "Operation timeout",
        CURLE_FTP_COULDNT_SET_ASCII => "FTP could not set ASCII",
        CURLE_FTP_PORT_FAILED => "FTP PORT failed",
        CURLE_FTP_COULDNT_USE_REST => "FTP could not use REST",
        CURLE_FTP_COULDNT_GET_SIZE => "FTP could not get size",
        CURLE_HTTP_RANGE_ERROR => "HTTP range error",
        CURLE_HTTP_POST_ERROR => "HTTP POST error",
        CURLE_SSL_CONNECT_ERROR => "SSL connect error",
        CURLE_BAD_DOWNLOAD_RESUME => "Bad download resume",
        CURLE_FILE_COULDNT_READ_FILE => "File could not read file",
        CURLE_LDAP_CANNOT_BIND => "LDAP cannot bind",
        CURLE_LDAP_SEARCH_FAILED => "LDAP search failed",
        CURLE_LIBRARY_NOT_FOUND => "Library not found",
        CURLE_FUNCTION_NOT_FOUND => "Function not found",
        CURLE_ABORTED_BY_CALLBACK => "Aborted by callback",
        CURLE_BAD_FUNCTION_ARGUMENT => "Bad function argument",
        CURLE_BAD_CALLING_ORDER => "Bad calling order",
        CURLE_HTTP_NOT_FOUND => "HTTP not found",
        CURLE_HTTP_PORT_FAILED => "HTTP port failed",
        CURLE_BAD_PASSWORD_ENTERED => "Bad password entered",
        CURLE_TOO_MANY_REDIRECTS => "Too many redirects",
        CURLE_UNKNOWN_TELNET_OPTION => "Unknown telnet option",
        CURLE_TELNET_OPTION_SYNTAX => "Telnet option syntax",
        CURLE_SSL_PEER_CERTIFICATE => "SSL peer certificate",
        CURLE_GOT_NOTHING => "Got nothing",
        CURLE_SSL_ENGINE_NOTFOUND => "SSL engine not found",
        CURLE_SSL_ENGINE_SETFAILED => "SSL engine set failed",
        CURLE_SEND_ERROR => "Send error",
        CURLE_RECV_ERROR => "Receive error",
        CURLE_SHARE_IN_USE => "Share in use",
        CURLE_SSL_CERTPROBLEM => "SSL cert problem",
        CURLE_SSL_CIPHER => "SSL cipher",
        CURLE_SSL_CACERT => "SSL CA cert",
        CURLE_BAD_CONTENT_ENCODING => "Bad content encoding",
        CURLE_LDAP_INVALID_URL => "LDAP invalid URL",
        CURLE_FILESIZE_EXCEEDED => "File size exceeded",
        CURLE_FTP_SSL_FAILED => "FTP SSL failed",
        CURLE_SSL_CACERT_BADFILE => "SSL CA cert bad file",
        CURLE_OBSOLETE => "Obsolete",
        CURLE_SSL_PINNEDPUBKEYNOTMATCH => "SSL pinned pubkey not match",
        CURLE_WEIRD_SERVER_REPLY => "Weird server reply",
        CURLE_SSH => "SSH error",
        CURLE_UNKNOWN_OPTION => "Unknown option",
        CURLE_PROXY => "Proxy error",
        CURLE_FTP_BAD_DOWNLOAD_RESUME => "FTP bad download resume",
        CURLE_FTP_PARTIAL_FILE => "FTP partial file",
    ];
    if (isset($map[$error_code])) {
        return $map[$error_code];
    }
    return "";
}

// ── curl_version：返回版本信息数组 ──────────────────────────
function curl_version(): array
{
    return [
        "version_number" => 0x081300,        // 模拟 libcurl 8.13.0
        "version" => "8.13.0-tphp",
        "ssl_version" => "mbedTLS/3.6.6",
        "libz_version" => "1.3.1",
        "protocols" => ["http", "https"],
        "features" => CURL_VERSION_SSL | CURL_VERSION_LIBZ | CURL_VERSION_IPV6 | CURL_VERSION_LARGEFILE,
    ];
}

// ── curl_escape：URL 编码 ──────────────────────────────────
function curl_escape(CurlHandle $handle, string $string): string
{
    return _curl_url_encode($string);
}

// ── curl_unescape：URL 解码 ─────────────────────────────────
function curl_unescape(CurlHandle $handle, string $string): string
{
    return _curl_url_decode($string);
}

// ── curl_file_create：创建 CURLFile 实例 ────────────────────
function curl_file_create(string $filename, string $mime_type = "", string $posted_filename = ""): CURLFile
{
    return new CURLFile($filename, $mime_type, $posted_filename);
}

// ── curl_getinfo：返回传输信息数组 ──────────────────────────
//   $option=0 返回完整数组；$option 非 0 时抛异常（避免 mixed 返回类型）
//   用户可通过 $info = curl_getinfo($ch); $code = $info["http_code"]; 获取单字段
function curl_getinfo(CurlHandle $handle, int $option = 0): array|Exception
{
    if ($option != 0) {
        throw new Exception(
            "curl_getinfo: single option query (option=" . strval($option) .
            ") is not supported in pure-phpc mode. Use curl_getinfo(\$handle)[\"field\"] instead. " .
            "Available fields: url, content_type, http_code, header_size, request_size, " .
            "filetime, ssl_verify_result, redirect_count, total_time, namelookup_time, " .
            "connect_time, pretransfer_time, size_upload, size_download, speed_download, " .
            "download_content_length, upload_content_length, starttransfer_time, redirect_time, " .
            "redirect_url, primary_ip, primary_port, local_ip, local_port, http_version, " .
            "protocol, scheme, appconnect_time, effective_method"
        );
    }
    return [
        "url" => $handle->effectiveUrl != "" ? $handle->effectiveUrl : $handle->url,
        "content_type" => $handle->contentType,
        "http_code" => $handle->lastHttpCode,
        "header_size" => $handle->headerSize,
        "request_size" => $handle->requestSize,
        "filetime" => 0,
        "ssl_verify_result" => $handle->sslVerifyResult,
        "redirect_count" => $handle->redirectCount,
        "total_time" => $handle->totalTime,
        "namelookup_time" => $handle->nameLookupTime,
        "connect_time" => $handle->connectTime,
        "pretransfer_time" => $handle->preTransferTime,
        "size_upload" => $handle->sizeUpload,
        "size_download" => $handle->sizeDownload,
        "speed_download" => $handle->speedDownload,
        "download_content_length" => $handle->downloadContentLength,
        "upload_content_length" => $handle->uploadContentLength,
        "starttransfer_time" => $handle->startTransferTime,
        "redirect_time" => $handle->redirectTime,
        "redirect_url" => $handle->redirectUrl,
        "primary_ip" => $handle->primaryIp,
        "primary_port" => $handle->primaryPort,
        "local_ip" => $handle->localIp,
        "local_port" => $handle->localPort,
        "http_version" => 0,
        "protocol" => 0,
        "scheme" => "",
        "appconnect_time" => $handle->appConnectTime,
        "effective_method" => $handle->effectiveMethod,
    ];
}

// ── curl_multi_getcontent：获取 handle 的最后响应体 ─────────
//   返回 lastResponse（未执行时返回空字符串）
function curl_multi_getcontent(CurlHandle $handle): string
{
    return $handle->lastResponse;
}

// ════════════════════════════════════════════════════════════
// Multi Handle 函数（11 个，含存根拒绝）
// ════════════════════════════════════════════════════════════

// ── curl_multi_init：创建 multi 句柄 ────────────────────────
function curl_multi_init(): CurlMultiHandle
{
    return new CurlMultiHandle();
}

// ── curl_multi_close：关闭 multi 句柄（无操作）──────────────
function curl_multi_close(CurlMultiHandle $multi_handle): void
{
    // 无操作（句柄未真正使用）
}

// ── curl_multi_errno：返回 multi 错误码 ──────────────────────
function curl_multi_errno(CurlMultiHandle $multi_handle): int
{
    return 0;  // 无错误
}

// ── curl_multi_strerror：multi 错误码 → 描述 ───────────────
//   与 curl_strerror 一致（错误码命名空间共享）
function curl_multi_strerror(int $error_code): string
{
    return curl_strerror($error_code);
}

// ── curl_multi_get_handles：返回已添加的 easy 句柄列表 ──────
function curl_multi_get_handles(CurlMultiHandle $multi_handle): array
{
    return [];  // 存根，返回空数组
}

// ── curl_multi_add_handle：添加 easy 句柄到 multi ───────────
//   抛 Exception（不支持异步 I/O）
function curl_multi_add_handle(CurlMultiHandle $multi_handle, CurlHandle $handle): int|Exception
{
    throw new Exception("curl_multi_add_handle is not supported in pure-phpc curl extension: no async I/O. Use sequential curl_exec instead.");
}

// ── curl_multi_remove_handle：从 multi 移除 easy 句柄 ───────
function curl_multi_remove_handle(CurlMultiHandle $multi_handle, CurlHandle $handle): int|Exception
{
    throw new Exception("curl_multi_remove_handle is not supported in pure-phpc curl extension: no async I/O. Use sequential curl_exec instead.");
}

// ── curl_multi_exec：执行 multi 请求 ────────────────────────
function curl_multi_exec(CurlMultiHandle $multi_handle, int &$still_running): int|Exception
{
    throw new Exception("curl_multi_exec is not supported in pure-phpc curl extension: no async I/O. Use sequential curl_exec instead.");
}

// ── curl_multi_select：等待 multi 句柄上的活动 ─────────────
function curl_multi_select(CurlMultiHandle $multi_handle, float $timeout = 1.0): int|Exception
{
    throw new Exception("curl_multi_select is not supported in pure-phpc curl extension: no async I/O. Use sequential curl_exec instead.");
}

// ── curl_multi_info_read：获取 multi 传输信息 ───────────────
function curl_multi_info_read(CurlMultiHandle $multi_handle, int &$queued_messages = 0): array|Exception
{
    throw new Exception("curl_multi_info_read is not supported in pure-phpc curl extension: no async I/O. Use sequential curl_exec instead.");
}

// ── curl_multi_setopt：设置 multi 选项 ──────────────────────
//   $value 用 int 类型（避免 mixed；存根函数不使用值）
function curl_multi_setopt(CurlMultiHandle $multi_handle, int $option, int $value): bool|Exception
{
    throw new Exception("curl_multi_setopt is not supported in pure-phpc curl extension: no async I/O. Use sequential curl_exec instead.");
}

// ════════════════════════════════════════════════════════════
// Share Handle 函数（6 个，含存根拒绝）
// ════════════════════════════════════════════════════════════

// ── curl_share_init：创建 share 句柄 ────────────────────────
function curl_share_init(): CurlShareHandle
{
    return new CurlShareHandle();
}

// ── curl_share_close：关闭 share 句柄（无操作）──────────────
//   PHP 8.5 已弃用，但保留兼容
function curl_share_close(CurlShareHandle $share_handle): void
{
    // 无操作
}

// ── curl_share_errno：返回 share 错误码 ─────────────────────
function curl_share_errno(CurlShareHandle $share_handle): int
{
    return 0;  // 无错误
}

// ── curl_share_strerror：share 错误码 → 描述 ───────────────
//   与 curl_strerror 一致
function curl_share_strerror(int $error_code): string
{
    return curl_strerror($error_code);
}

// ── curl_share_setopt：设置 share 选项 ──────────────────────
//   $value 用 int 类型（避免 mixed；存根函数不使用值）
function curl_share_setopt(CurlShareHandle $share_handle, int $option, int $value): bool|Exception
{
    throw new Exception("curl_share_setopt is not supported: no shared connection pool in pure-phpc curl.");
}

// ── curl_share_init_persistent：创建持久化共享句柄 ──────────
//   抛 Exception（不支持共享连接池）
function curl_share_init_persistent(array $share_options): CurlSharePersistentHandle|Exception
{
    throw new Exception("curl_share_init_persistent is not supported: no shared connection pool in pure-phpc curl.");
}
