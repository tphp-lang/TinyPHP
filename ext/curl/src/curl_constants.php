<?php
// ext/curl/src/curl_constants.php — cURL 扩展常量定义（689 个）
//
// 与 PHP 8.5 ext/curl/curl.stub.php 1:1 对齐；数值与 libcurl 8.13 标准一致
// （CURLOPT_*/CURLINFO_*/CURLAUTH_* 等取自 curl/curl.h；CURLOPT_RETURNTRANSFER
//  /CURLOPT_BINARYTRANSFER/CURLOPT_SAFE_UPLOAD 为 PHP 专有值，取自 curl_private.h）
//
// 说明：
//   - CURLOPT_* 编号方案：LONG=0+idx / STRINGPOINT|OBJECTPOINT=10000+idx /
//     FUNCTIONPOINT=20000+idx / OFF_T=30000+idx / BLOB=40000+idx
//   - CURLINFO_* 编号方案：STRING=0x100000|idx / LONG=0x200000|idx /
//     DOUBLE=0x300000|idx / SLIST|PTR=0x400000|idx / SOCKET=0x500000|idx /
//     OFF_T=0x600000|idx
//   - 标注 @deprecated 的常量在 PHP 8.5 stub 中带 #[\Deprecated] 注解
//   - 带 `// alias of` 注释的常量是 PHP 8.5 中保留的旧别名，与规范名同值

// ════════════════════════════════════════════════════════════
// CURLOPT_* 选项常量
// ════════════════════════════════════════════════════════════
const CURLOPT_AUTOREFERER = 58;                  // bool: 自动设置 Referer 跟随重定向
const CURLOPT_AWS_SIGV4 = 10305;                // string: AWS SigV4 认证参数（since 7.75.0）
const CURLOPT_BINARYTRANSFER = 19914;             // PHP 专有；@deprecated since 8.4，自 5.1.2 起无效
const CURLOPT_BUFFERSIZE = 98;                    // int: 接收缓冲区大小
const CURLOPT_CAINFO = 10065;                     // string: CA 证书路径
const CURLOPT_CAINFO_BLOB = 40309;               // blob: CA 证书 blob（since 7.77.0）
const CURLOPT_CAPATH = 10097;                     // string: CA 证书目录
const CURLOPT_CONNECTTIMEOUT = 78;                // int: 连接阶段超时秒数
const CURLOPT_COOKIE = 10022;                     // string: Cookie 头
const CURLOPT_COOKIEFILE = 10031;                 // string: 读取 Cookie 文件
const CURLOPT_COOKIEJAR = 10082;                  // string: 写入 Cookie 文件
const CURLOPT_COOKIESESSION = 96;                 // bool: 开启新 cookie 会话
const CURLOPT_CRLF = 27;                          // bool: 转换 LF 为 CRLF
const CURLOPT_CUSTOMREQUEST = 10036;              // string: 自定义请求方法
const CURLOPT_DNS_CACHE_TIMEOUT = 92;             // int: DNS 缓存超时秒数
const CURLOPT_DNS_USE_GLOBAL_CACHE = 91;          // bool: 全局 DNS 缓存（已废弃）
const CURLOPT_EGDSOCKET = 10077;                  // string: EGD socket 路径
const CURLOPT_ENCODING = 10102;                   // string: alias of CURLOPT_ACCEPT_ENCODING
const CURLOPT_FAILONERROR = 45;                   // bool: HTTP >= 400 时失败
const CURLOPT_FILE = 10001;                       // resource: alias of CURLOPT_WRITEDATA，输出文件句柄
const CURLOPT_FILETIME = 69;                      // bool: 获取远端文件时间
const CURLOPT_FOLLOWLOCATION = 52;                // bool: 跟随 3xx 重定向
const CURLOPT_FORBID_REUSE = 75;                  // bool: 禁止连接复用
const CURLOPT_FRESH_CONNECT = 74;                 // bool: 强制新建连接
const CURLOPT_FTPAPPEND = 50;                     // bool: alias of CURLOPT_APPEND
const CURLOPT_FTPLISTONLY = 48;                   // bool: alias of CURLOPT_DIRLISTONLY
const CURLOPT_FTPPORT = 10017;                    // string: FTP PORT 主动模式地址
const CURLOPT_FTP_SSL = 119;                      // int: alias of CURLOPT_USE_SSL；@deprecated since 7.18.0
const CURLOPT_FTP_USE_EPRT = 106;                 // bool: FTP 使用 EPRT
const CURLOPT_FTP_USE_EPSV = 85;                  // bool: FTP 使用 EPSV
const CURLOPT_HEADER = 42;                        // bool: 响应包含 HTTP 头
const CURLOPT_HEADERFUNCTION = 20079;             // callback: 头接收回调
const CURLOPT_HSTS_CTRL = 299;                    // int: HSTS 控制（since 7.74.0）
const CURLOPT_HSTS = 10300;                       // string: HSTS 缓存文件（since 7.74.0）
const CURLOPT_HTTP200ALIASES = 10104;             // array: 替代 200 OK 的别名列表
const CURLOPT_HTTPGET = 80;                       // bool: 强制 GET
const CURLOPT_HTTPHEADER = 10023;                 // array: 自定义 HTTP 头
const CURLOPT_HTTPPROXYTUNNEL = 61;               // bool: HTTP 代理隧道
const CURLOPT_HTTP_VERSION = 84;                  // int: HTTP 版本
const CURLOPT_INFILE = 10009;                     // resource: alias of CURLOPT_READDATA，输入文件
const CURLOPT_INFILESIZE = 14;                    // int: 上传文件大小
const CURLOPT_INFILESIZE_LARGE = 30115;           // int: 上传文件大小（大文件）
const CURLOPT_INTERFACE = 10062;                  // string: 绑定网卡名
const CURLOPT_KRB4LEVEL = 10063;                  // string: alias of CURLOPT_KRBLEVEL
const CURLOPT_LOW_SPEED_LIMIT = 19;               // int: 最低速度限制（字节/秒）
const CURLOPT_LOW_SPEED_TIME = 20;                // int: 低于限速的持续时间
const CURLOPT_MAXCONNECTS = 71;                   // int: 最大连接数
const CURLOPT_MAXREDIRS = 68;                     // int: 最大重定向次数
const CURLOPT_NETRC = 51;                         // int: .netrc 文件处理方式
const CURLOPT_NOBODY = 44;                        // bool: 不下载响应体（HEAD）
const CURLOPT_NOPROGRESS = 43;                    // bool: 关闭进度条
const CURLOPT_NOSIGNAL = 99;                      // bool: 禁用信号
const CURLOPT_PORT = 3;                           // int: 远端端口
const CURLOPT_POST = 47;                          // bool: 发送 POST 请求
const CURLOPT_POSTFIELDS = 10015;                 // string|array: POST 数据
const CURLOPT_POSTQUOTE = 10039;                  // array: POST 后 FTP 命令
const CURLOPT_PREQUOTE = 10093;                   // array: PRE FTP 命令
const CURLOPT_PRIVATE = 10103;                    // mixed: 私有数据
const CURLOPT_PROGRESSFUNCTION = 20056;           // callback: 进度回调（旧）
const CURLOPT_PROXY = 10004;                      // string: 代理地址
const CURLOPT_PROXYPORT = 59;                     // int: 代理端口
const CURLOPT_PROXYTYPE = 101;                    // int: 代理类型
const CURLOPT_PROXYUSERPWD = 10006;               // string: 代理认证 "user:pass"
const CURLOPT_PUT = 54;                           // bool: PUT 模式（已废弃，用 CUSTOMREQUEST）
const CURLOPT_QUOTE = 10028;                      // array: FTP 命令
const CURLOPT_RANDOM_FILE = 10076;                // string: 随机数源文件
const CURLOPT_RANGE = 10007;                      // string: Range 头
const CURLOPT_READDATA = 10009;                   // resource: 读取回调数据源（与 INFILE 同值）
const CURLOPT_READFUNCTION = 20012;               // callback: 读取回调
const CURLOPT_REFERER = 10016;                    // string: Referer 头
const CURLOPT_RESUME_FROM = 21;                   // int: 断点续传偏移
const CURLOPT_RETURNTRANSFER = 19913;             // bool: 返回响应体而不直接输出（PHP 专有）
const CURLOPT_SHARE = 10100;                      // object: 共享句柄
const CURLOPT_SSLCERT = 10025;                    // string: 客户端 SSL 证书
const CURLOPT_SSLCERTPASSWD = 10026;             // string: 客户端证书密码
const CURLOPT_SSLCERTTYPE = 10086;                // string: 证书类型 (PEM/DER/ENG/P12)
const CURLOPT_SSLENGINE = 10089;                  // string: SSL 引擎
const CURLOPT_SSLENGINE_DEFAULT = 90;             // bool: 默认 SSL 引擎
const CURLOPT_SSLKEY = 10087;                     // string: 私钥文件
const CURLOPT_SSLKEYPASSWD = 10026;               // string: 私钥密码（与 SSLCERTPASSWD 同值）
const CURLOPT_SSLKEYTYPE = 10088;                 // string: 私钥类型 (PEM/DER/ENG)
const CURLOPT_SSLVERSION = 32;                    // int: SSL 版本
const CURLOPT_SSL_CIPHER_LIST = 10083;            // string: SSL 密码套件列表
const CURLOPT_SSL_VERIFYHOST = 81;                // int: 校验 SSL hostname
const CURLOPT_SSL_VERIFYPEER = 64;                // bool: 校验 SSL 证书
const CURLOPT_STDERR = 10110;                     // resource: 错误输出
const CURLOPT_TCP_KEEPCNT = 338;                  // int: TCP keepalive 探测次数（since 8.9.0）
const CURLOPT_TELNETOPTIONS = 10070;              // array: Telnet 选项
const CURLOPT_TIMECONDITION = 33;                 // int: 时间条件
const CURLOPT_TIMEOUT = 13;                       // int: 请求总超时秒数
const CURLOPT_TIMEVALUE = 30;                     // int: 时间条件值
const CURLOPT_TRANSFERTEXT = 53;                  // bool: 文本模式传输
const CURLOPT_UNRESTRICTED_AUTH = 105;            // bool: 重定向时持续发认证
const CURLOPT_UPLOAD = 46;                        // bool: 上传模式
const CURLOPT_URL = 10002;                        // string: 请求 URL
const CURLOPT_USERAGENT = 10018;                  // string: User-Agent 头
const CURLOPT_USERPWD = 10005;                    // string: "user:pass" 认证
const CURLOPT_VERBOSE = 41;                       // bool: 详细输出
const CURLOPT_WRITEFUNCTION = 20011;              // callback: 写入回调
const CURLOPT_WRITEHEADER = 10029;                // resource: alias of CURLOPT_HEADERDATA，头输出
const CURLOPT_XFERINFOFUNCTION = 20213;           // callback: 传输进度回调（新）
const CURLOPT_DEBUGFUNCTION = 20094;              // callback: 调试信息回调
const CURLOPT_HTTPAUTH = 107;                     // int: HTTP 认证方法
const CURLOPT_FTP_CREATE_MISSING_DIRS = 110;      // bool/int: 自动创建 FTP 目录
const CURLOPT_PROXYAUTH = 111;                    // int: 代理认证方法
const CURLOPT_FTP_RESPONSE_TIMEOUT = 112;         // int: alias of CURLOPT_SERVER_RESPONSE_TIMEOUT
const CURLOPT_SERVER_RESPONSE_TIMEOUT = 112;      // int: 服务器响应超时
const CURLOPT_IPRESOLVE = 113;                    // int: IP 解析策略
const CURLOPT_MAXFILESIZE = 114;                  // int: 最大下载大小
const CURLOPT_NETRC_FILE = 10118;                 // string: .netrc 文件路径
const CURLOPT_MAXFILESIZE_LARGE = 30117;          // int: 最大下载大小（大文件）
const CURLOPT_TCP_NODELAY = 121;                  // bool: TCP_NODELAY
const CURLOPT_FTPSSLAUTH = 129;                   // int: FTP SSL 认证方式
const CURLOPT_FTP_ACCOUNT = 10134;                // string: FTP 账户
const CURLOPT_COOKIELIST = 10135;                 // string: Cookie 列表操作
const CURLOPT_IGNORE_CONTENT_LENGTH = 136;        // bool: 忽略 Content-Length
const CURLOPT_FTP_SKIP_PASV_IP = 137;             // bool: FTP 跳过 PASV IP
const CURLOPT_FTP_FILEMETHOD = 138;               // int: FTP 方法
const CURLOPT_CONNECT_ONLY = 141;                 // bool: 仅建立连接
const CURLOPT_LOCALPORT = 139;                    // int: 本地端口
const CURLOPT_LOCALPORTRANGE = 140;               // int: 本地端口范围
const CURLOPT_FTP_ALTERNATIVE_TO_USER = 10147;    // string: FTP 替代 USER 命令
const CURLOPT_MAX_RECV_SPEED_LARGE = 30146;       // int: 最大接收速度（字节/秒）
const CURLOPT_MAX_SEND_SPEED_LARGE = 30145;       // int: 最大发送速度（字节/秒）
const CURLOPT_SSL_SESSIONID_CACHE = 150;          // bool: SSL 会话 ID 缓存
const CURLOPT_FTP_SSL_CCC = 154;                  // int: FTP SSL CCC 模式
const CURLOPT_SSH_AUTH_TYPES = 151;               // int: SSH 认证类型
const CURLOPT_SSH_PRIVATE_KEYFILE = 10153;        // string: SSH 私钥文件
const CURLOPT_SSH_PUBLIC_KEYFILE = 10152;         // string: SSH 公钥文件
const CURLOPT_CONNECTTIMEOUT_MS = 156;            // int: 连接超时毫秒
const CURLOPT_HTTP_CONTENT_DECODING = 158;        // bool: HTTP 内容解码
const CURLOPT_HTTP_TRANSFER_DECODING = 157;       // bool: HTTP 传输解码
const CURLOPT_TIMEOUT_MS = 155;                   // int: 请求超时毫秒
const CURLOPT_KRBLEVEL = 10063;                   // string: Kerberos 级别（与 KRB4LEVEL 同值）
const CURLOPT_NEW_DIRECTORY_PERMS = 160;          // int: 新目录权限
const CURLOPT_NEW_FILE_PERMS = 159;               // int: 新文件权限
const CURLOPT_APPEND = 50;                        // bool: 追加模式（与 FTPAPPEND 同值）
const CURLOPT_DIRLISTONLY = 48;                   // bool: 仅列目录（与 FTPLISTONLY 同值）
const CURLOPT_USE_SSL = 119;                      // int: SSL 使用级别
const CURLOPT_SSH_HOST_PUBLIC_KEY_MD5 = 10168;    // string: SSH 主机公钥 MD5
const CURLOPT_PROXY_TRANSFER_MODE = 166;          // bool: 代理传输模式
const CURLOPT_ADDRESS_SCOPE = 171;                // int: 地址 scope
const CURLOPT_CRLFILE = 10169;                    // string: CRL 文件
const CURLOPT_ISSUERCERT = 10170;                 // string: 颁发者证书
const CURLOPT_KEYPASSWD = 10026;                  // string: 私钥密码（与 SSLKEYPASSWD 同值）
const CURLOPT_CERTINFO = 172;                     // bool: 获取证书链信息
const CURLOPT_PASSWORD = 10175;                   // string: 密码
const CURLOPT_POSTREDIR = 161;                    // int: 重定向后 POST 行为
const CURLOPT_PROXYPASSWORD = 10176;              // string: 代理密码
const CURLOPT_PROXYUSERNAME = 10173;              // string: 代理用户名
const CURLOPT_USERNAME = 10174;                   // string: 用户名
const CURLOPT_NOPROXY = 10177;                    // string: 不走代理的主机列表
const CURLOPT_PROTOCOLS = 181;                    // int: 允许的协议位掩码
const CURLOPT_REDIR_PROTOCOLS = 182;              // int: 重定向允许的协议（已废弃）
const CURLOPT_SOCKS5_GSSAPI_NEC = 180;            // bool: SOCKS5 GSSAPI NEC
const CURLOPT_SOCKS5_GSSAPI_SERVICE = 10179;      // string: SOCKS5 GSSAPI 服务名
const CURLOPT_TFTP_BLKSIZE = 178;                 // int: TFTP 块大小
const CURLOPT_SSH_KNOWNHOSTS = 10183;             // string: SSH known_hosts 文件
const CURLOPT_FTP_USE_PRET = 188;                 // bool: FTP PRET 命令
const CURLOPT_MAIL_FROM = 10186;                  // string: MAIL FROM
const CURLOPT_MAIL_RCPT = 10187;                  // array: MAIL RCPT 列表
const CURLOPT_RTSP_CLIENT_CSEQ = 10193;           // int: RTSP 客户端 CSEQ
const CURLOPT_RTSP_REQUEST = 10194;               // int: RTSP 请求类型
const CURLOPT_RTSP_SERVER_CSEQ = 10195;           // int: RTSP 服务器 CSEQ
const CURLOPT_RTSP_SESSION_ID = 10190;            // string: RTSP 会话 ID
const CURLOPT_RTSP_STREAM_URI = 10191;            // string: RTSP 流 URI
const CURLOPT_RTSP_TRANSPORT = 10192;             // string: RTSP 传输
const CURLOPT_FNMATCH_FUNCTION = 20200;           // callback: 通配符匹配回调
const CURLOPT_WILDCARDMATCH = 197;                // bool: 通配符匹配
const CURLOPT_RESOLVE = 10203;                    // array: 自定义 DNS 解析
const CURLOPT_TLSAUTH_PASSWORD = 10205;           // string: TLS 认证密码
const CURLOPT_TLSAUTH_TYPE = 10206;               // string: TLS 认证类型
const CURLOPT_TLSAUTH_USERNAME = 10204;           // string: TLS 认证用户名
const CURLOPT_ACCEPT_ENCODING = 10102;            // string: Accept-Encoding 头
const CURLOPT_TRANSFER_ENCODING = 207;            // bool: Transfer-Encoding
const CURLOPT_GSSAPI_DELEGATION = 208;            // int: GSSAPI 委托
const CURLOPT_ACCEPTTIMEOUT_MS = 212;             // int: 等待接受超时毫秒
const CURLOPT_DNS_SERVERS = 10211;                // string: DNS 服务器
const CURLOPT_MAIL_AUTH = 10217;                  // string: MAIL AUTH
const CURLOPT_SSL_OPTIONS = 216;                  // int: SSL 选项
const CURLOPT_TCP_KEEPALIVE = 213;                // bool: TCP keepalive
const CURLOPT_TCP_KEEPIDLE = 214;                 // int: TCP keepalive idle
const CURLOPT_TCP_KEEPINTVL = 215;                // int: TCP keepalive 间隔
const CURLOPT_EXPECT_100_TIMEOUT_MS = 227;        // int: Expect: 100-continue 超时
const CURLOPT_SSL_ENABLE_ALPN = 229;              // bool: SSL ALPN
const CURLOPT_SSL_ENABLE_NPN = 228;               // bool: SSL NPN（已废弃）
const CURLOPT_HEADEROPT = 229;                    // int: 头选项
const CURLOPT_PROXYHEADER = 10229;                // array: 代理请求头
const CURLOPT_PINNEDPUBLICKEY = 10230;            // string: 固定公钥
const CURLOPT_UNIX_SOCKET_PATH = 10231;           // string: Unix 域 socket 路径
const CURLOPT_SSL_VERIFYSTATUS = 232;             // bool: OCSP 装订校验
const CURLOPT_PATH_AS_IS = 234;                   // bool: 路径原样发送
const CURLOPT_SSL_FALSESTART = 233;               // bool: SSL False Start
const CURLOPT_PIPEWAIT = 237;                     // bool: 等待多路复用
const CURLOPT_PROXY_SERVICE_NAME = 10235;         // string: 代理服务名
const CURLOPT_SERVICE_NAME = 10236;               // string: 服务名
const CURLOPT_DEFAULT_PROTOCOL = 10238;           // string: 默认协议
const CURLOPT_STREAM_WEIGHT = 239;                // int: HTTP/2 流权重
const CURLOPT_TFTP_NO_OPTIONS = 242;              // bool: TFTP 不发选项
const CURLOPT_CONNECT_TO = 10243;                 // array: CONNECT-TO 列表
const CURLOPT_TCP_FASTOPEN = 244;                 // bool: TCP Fast Open
const CURLOPT_KEEP_SENDING_ON_ERROR = 245;        // bool: 错误时继续发送
const CURLOPT_PRE_PROXY = 10262;                  // string: 前置代理
const CURLOPT_PROXY_CAINFO = 10246;               // string: 代理 CA 证书
const CURLOPT_PROXY_CAINFO_BLOB = 40310;         // blob: 代理 CA 证书 blob（since 7.77.0）
const CURLOPT_PROXY_CAPATH = 10247;               // string: 代理 CA 目录
const CURLOPT_PROXY_CRLFILE = 10248;              // string: 代理 CRL 文件
const CURLOPT_PROXY_KEYPASSWD = 10258;            // string: 代理私钥密码
const CURLOPT_PROXY_PINNEDPUBLICKEY = 10263;      // string: 代理固定公钥
const CURLOPT_PROXY_SSL_CIPHER_LIST = 10259;      // string: 代理 SSL 密码套件
const CURLOPT_PROXY_SSL_OPTIONS = 261;            // int: 代理 SSL 选项
const CURLOPT_PROXY_SSL_VERIFYHOST = 264;         // int: 代理 SSL hostname 校验
const CURLOPT_PROXY_SSL_VERIFYPEER = 263;         // bool: 代理 SSL 证书校验
const CURLOPT_PROXY_SSLCERT = 10249;              // string: 代理客户端证书
const CURLOPT_PROXY_SSLCERTTYPE = 10250;          // string: 代理证书类型
const CURLOPT_PROXY_SSLKEY = 10251;               // string: 代理私钥
const CURLOPT_PROXY_SSLKEYTYPE = 10252;           // string: 代理私钥类型
const CURLOPT_PROXY_SSLVERSION = 250;             // int: 代理 SSL 版本
const CURLOPT_PROXY_TLSAUTH_PASSWORD = 10254;     // string: 代理 TLS 认证密码
const CURLOPT_PROXY_TLSAUTH_TYPE = 10255;         // string: 代理 TLS 认证类型
const CURLOPT_PROXY_TLSAUTH_USERNAME = 10253;     // string: 代理 TLS 认证用户名
const CURLOPT_ABSTRACT_UNIX_SOCKET = 10264;       // string: 抽象 Unix socket
const CURLOPT_SUPPRESS_CONNECT_HEADERS = 265;     // bool: 抑制 CONNECT 响应头
const CURLOPT_REQUEST_TARGET = 10256;             // string: 请求目标
const CURLOPT_SOCKS5_AUTH = 267;                  // int: SOCKS5 认证方法
const CURLOPT_SSH_COMPRESSION = 268;              // bool: SSH 压缩
const CURLOPT_HAPPY_EYEBALLS_TIMEOUT_MS = 271;    // int: Happy Eyeballs 超时
const CURLOPT_TIMEVALUE_LARGE = 30170;            // int: 时间条件值（大）
const CURLOPT_DNS_SHUFFLE_ADDRESSES = 272;        // bool: DNS 地址随机排序
const CURLOPT_HAPROXYPROTOCOL = 274;              // bool: HAProxy PROXY 协议
const CURLOPT_DISALLOW_USERNAME_IN_URL = 278;     // bool: 禁止 URL 中的用户名
const CURLOPT_PROXY_TLS13_CIPHERS = 10277;        // string: 代理 TLS 1.3 密码套件
const CURLOPT_TLS13_CIPHERS = 10276;              // string: TLS 1.3 密码套件
const CURLOPT_DOH_URL = 10279;                    // string: DoH 服务器 URL
const CURLOPT_DOH_SSL_VERIFYPEER = 306;          // bool: DoH SSL 验证对端证书（since 7.76.0）
const CURLOPT_DOH_SSL_VERIFYHOST = 307;          // int: DoH SSL 验证主机名（since 7.76.0）
const CURLOPT_DOH_SSL_VERIFYSTATUS = 308;        // bool: DoH SSL 证书状态验证（since 7.76.0）
const CURLOPT_UPKEEP_INTERVAL_MS = 280;           // int: 维护间隔毫秒
const CURLOPT_UPLOAD_BUFFERSIZE = 281;            // int: 上传缓冲区大小
const CURLOPT_HTTP09_ALLOWED = 285;               // bool: 允许 HTTP/0.9
const CURLOPT_ALTSVC = 10287;                     // string: Alt-Svc 文件
const CURLOPT_ALTSVC_CTRL = 288;                  // int: Alt-Svc 控制
const CURLOPT_MAXAGE_CONN = 291;                  // int: 连接最大空闲时间
const CURLOPT_SASL_AUTHZID = 292;                 // string: SASL 授权身份
const CURLOPT_SASL_IR = 294;                      // bool: SASL 初始响应
const CURLOPT_DNS_INTERFACE = 10221;              // string: DNS 绑定网卡
const CURLOPT_DNS_LOCAL_IP4 = 10222;              // string: DNS 本地 IPv4
const CURLOPT_DNS_LOCAL_IP6 = 10223;              // string: DNS 本地 IPv6
const CURLOPT_XOAUTH2_BEARER = 10220;             // string: XOAUTH2 Bearer token
const CURLOPT_LOGIN_OPTIONS = 10224;              // string: 登录选项
const CURLOPT_MAXLIFETIME_CONN = 293;             // int: 连接最大生命周期
const CURLOPT_SSH_HOST_PUBLIC_KEY_SHA256 = 10267; // string: SSH 主机公钥 SHA256
const CURLOPT_PREREQFUNCTION = 10268;             // callback: 预请求回调
const CURLOPT_MIME_OPTIONS = 305;                 // int: MIME 选项
const CURLOPT_SSH_HOSTKEYFUNCTION = 10284;        // callback: SSH 主机密钥回调
const CURLOPT_PROTOCOLS_STR = 10244;              // string: 允许的协议列表
const CURLOPT_REDIR_PROTOCOLS_STR = 10245;        // string: 重定向允许的协议列表（已废弃）
const CURLOPT_WS_OPTIONS = 311;                   // int: WebSocket 选项
const CURLOPT_CA_CACHE_TIMEOUT = 10030;           // int: CA 缓存超时
const CURLOPT_QUICK_EXIT = 313;                   // bool: 快速退出
const CURLOPT_SAFE_UPLOAD = -1;                   // bool: PHP 专有；安全上传（1.0 兼容）
const CURLOPT_MAIL_RCPT_ALLLOWFAILS = 247;        // bool: MAIL RCPT 允许部分失败
const CURLOPT_ISSUERCERT_BLOB = 40295;            // blob: 颁发者证书 blob
const CURLOPT_PROXY_ISSUERCERT = 10296;           // string: 代理颁发者证书
const CURLOPT_PROXY_ISSUERCERT_BLOB = 40297;      // blob: 代理颁发者证书 blob
const CURLOPT_PROXY_SSLCERT_BLOB = 40293;         // blob: 代理客户端证书 blob
const CURLOPT_PROXY_SSLKEY_BLOB = 40294;          // blob: 代理私钥 blob
const CURLOPT_SSLCERT_BLOB = 40291;               // blob: 客户端证书 blob
const CURLOPT_SSLKEY_BLOB = 40292;                // blob: 私钥 blob
const CURLOPT_SSL_EC_CURVES = 10298;              // string: SSL 椭圆曲线
const CURLOPT_SSL_SIGNATURE_ALGORITHMS = 347;     // string: SSL 签名算法（since 8.14.0）

// ════════════════════════════════════════════════════════════
// CURLFOLLOW_* 重定向行为常量（since 8.13.0）
// ════════════════════════════════════════════════════════════
const CURLFOLLOW_ALL = 1;                         // 通用跟随重定向
const CURLFOLLOW_OBEYCODE = 2;                    // 遵循 HTTP 状态码指示
const CURLFOLLOW_FIRSTONLY = 3;                   // 仅首个请求用自定义方法

// ════════════════════════════════════════════════════════════
// CURLINFO_* 调试信息类型（curl_infotype，用于 CURLOPT_DEBUGFUNCTION 回调）
// ════════════════════════════════════════════════════════════
const CURLINFO_TEXT = 0;                          // 调试文本
const CURLINFO_HEADER_IN = 1;                     // 接收到的请求头
const CURLINFO_DATA_IN = 3;                       // 接收到的数据
const CURLINFO_DATA_OUT = 4;                      // 发送的数据
const CURLINFO_SSL_DATA_OUT = 6;                  // 发送的 SSL 数据
const CURLINFO_SSL_DATA_IN = 5;                   // 接收的 SSL 数据

// ════════════════════════════════════════════════════════════
// CURLINFO_* curl_getinfo 查询常量
// ════════════════════════════════════════════════════════════
const CURLINFO_CONNECT_TIME = 0x300005;            // float: 连接耗时
const CURLINFO_CONTENT_LENGTH_DOWNLOAD = 0x300006; // float: 下载 Content-Length
const CURLINFO_CONTENT_LENGTH_UPLOAD = 0x300007;   // float: 上传 Content-Length
const CURLINFO_CONTENT_TYPE = 0x10000C;            // string: Content-Type
const CURLINFO_EFFECTIVE_URL = 0x100001;           // string: 最后有效 URL
const CURLINFO_FILETIME = 0x200005;                // int: 远端文件时间
const CURLINFO_HEADER_OUT = 0x100002;              // string: 发出的请求头
const CURLINFO_HEADER_SIZE = 0x200002;             // int: 响应头大小
const CURLINFO_HTTP_CODE = 0x200001;               // int: HTTP 状态码（与 RESPONSE_CODE 同值）
const CURLINFO_LASTONE = 0x400001;                 // 标记最后一个
const CURLINFO_NAMELOOKUP_TIME = 0x300004;         // float: DNS 解析耗时
const CURLINFO_PRETRANSFER_TIME = 0x300006;        // float: 预传输耗时
const CURLINFO_PRIVATE = 0x400000;                 // mixed: 私有数据
const CURLINFO_REDIRECT_COUNT = 0x200006;          // int: 重定向次数
const CURLINFO_REDIRECT_TIME = 0x300009;           // float: 重定向总耗时
const CURLINFO_REQUEST_SIZE = 0x200003;            // int: 请求大小
const CURLINFO_SIZE_DOWNLOAD = 0x300001;           // float: 下载字节数
const CURLINFO_SIZE_UPLOAD = 0x300000;             // float: 上传字节数
const CURLINFO_SPEED_DOWNLOAD = 0x300002;          // float: 下载速度
const CURLINFO_SPEED_UPLOAD = 0x300003;            // float: 上传速度
const CURLINFO_SSL_VERIFYRESULT = 0x200004;        // int: SSL 校验结果
const CURLINFO_STARTTRANSFER_TIME = 0x300008;      // float: 首字节耗时
const CURLINFO_TOTAL_TIME = 0x30000F;              // float: 总耗时
const CURLINFO_EFFECTIVE_METHOD = 0x100007;        // string: 最后有效方法（since 7.72.0）
const CURLINFO_CAPATH = 0x100008;                  // string: CA 目录（since 7.84.0）// TODO: verify index
const CURLINFO_CAINFO = 0x100009;                  // string: CA 证书（since 7.84.0）// TODO: verify index
const CURLINFO_HTTPAUTH_USED = 0x200017;           // int: 实际使用的 HTTP 认证（since 8.12.0）// TODO: verify index
const CURLINFO_PROXYAUTH_USED = 0x200018;          // int: 实际使用的代理认证（since 8.12.0）// TODO: verify index
const CURLINFO_HTTP_CONNECTCODE = 0x200007;        // int: CONNECT 响应码
const CURLINFO_HTTPAUTH_AVAIL = 0x200008;          // int: 可用 HTTP 认证
const CURLINFO_RESPONSE_CODE = 0x200001;           // int: HTTP 状态码（与 HTTP_CODE 同值）
const CURLINFO_PROXYAUTH_AVAIL = 0x200009;         // int: 可用代理认证
const CURLINFO_OS_ERRNO = 0x20000A;                // int: OS errno
const CURLINFO_NUM_CONNECTS = 0x20000B;            // int: 连接数
const CURLINFO_SSL_ENGINES = 0x400000;             // array: SSL 引擎列表
const CURLINFO_COOKIELIST = 0x400001;              // array: Cookie 列表
const CURLINFO_FTP_ENTRY_PATH = 0x100019;          // string: FTP 入口路径 // TODO: verify index
const CURLINFO_REDIRECT_URL = 0x10001A;            // string: 重定向目标 URL // TODO: verify index
const CURLINFO_APPCONNECT_TIME = 0x30000A;         // float: TLS 握手完成耗时
const CURLINFO_PRIMARY_IP = 0x10001B;              // string: 主 IP // TODO: verify index
const CURLINFO_CERTINFO = 0x400002;                // array: 证书链
const CURLINFO_CONDITION_UNMET = 0x20000C;         // int: 时间条件未满足
const CURLINFO_RTSP_CLIENT_CSEQ = 0x20000D;        // int: RTSP 客户端 CSEQ
const CURLINFO_RTSP_CSEQ_RECV = 0x20000F;          // int: RTSP 接收的 CSEQ
const CURLINFO_RTSP_SERVER_CSEQ = 0x20000E;        // int: RTSP 服务器 CSEQ
const CURLINFO_RTSP_SESSION_ID = 0x10001C;         // string: RTSP 会话 ID // TODO: verify index
const CURLINFO_LOCAL_IP = 0x10001D;                // string: 本地 IP // TODO: verify index
const CURLINFO_LOCAL_PORT = 0x200010;              // int: 本地端口
const CURLINFO_PRIMARY_PORT = 0x200011;            // int: 主端口
const CURLINFO_HTTP_VERSION = 0x200012;            // int: HTTP 版本
const CURLINFO_PROTOCOL = 0x200013;                // int: 协议
const CURLINFO_PROXY_SSL_VERIFYRESULT = 0x200014;  // int: 代理 SSL 校验结果 // TODO: verify index
const CURLINFO_SCHEME = 0x10001E;                  // string: scheme // TODO: verify index
const CURLINFO_CONTENT_LENGTH_DOWNLOAD_T = 0x60000E; // int: 下载 Content-Length（大）
const CURLINFO_CONTENT_LENGTH_UPLOAD_T = 0x60000F; // int: 上传 Content-Length（大）
const CURLINFO_SIZE_DOWNLOAD_T = 0x600010;         // int: 下载字节数（大）
const CURLINFO_SIZE_UPLOAD_T = 0x600011;           // int: 上传字节数（大）
const CURLINFO_SPEED_DOWNLOAD_T = 0x600012;        // int: 下载速度（大）
const CURLINFO_SPEED_UPLOAD_T = 0x600013;          // int: 上传速度（大）
const CURLINFO_FILETIME_T = 0x600014;              // int: 远端文件时间（大）
const CURLINFO_QUEUE_TIME_T = 0x60001C;            // int: 队列等待时间（since 8.6.0）
const CURLINFO_APPCONNECT_TIME_T = 0x600015;       // int: TLS 握手耗时（大）
const CURLINFO_CONNECT_TIME_T = 0x600016;          // int: 连接耗时（大）
const CURLINFO_NAMELOOKUP_TIME_T = 0x600017;       // int: DNS 解析耗时（大）
const CURLINFO_PRETRANSFER_TIME_T = 0x600018;      // int: 预传输耗时（大）
const CURLINFO_REDIRECT_TIME_T = 0x600019;         // int: 重定向耗时（大）
const CURLINFO_STARTTRANSFER_TIME_T = 0x60001A;    // int: 首字节耗时（大）
const CURLINFO_TOTAL_TIME_T = 0x60001B;            // int: 总耗时（大）
const CURLINFO_USED_PROXY = 0x200015;              // bool: 是否使用了代理（since 8.7.0）// TODO: verify index
const CURLINFO_POSTTRANSFER_TIME_T = 0x60001D;     // int: 传输后耗时（since 8.10.0）
const CURLINFO_CONN_ID = 0x200016;                 // int: 连接 ID（since 8.2.0）// TODO: verify index
const CURLINFO_PROXY_ERROR = 0x200019;             // int: 代理错误码 // TODO: verify index
const CURLINFO_REFERER = 0x10001F;                 // string: Referer（since 7.76.0）// TODO: verify index
const CURLINFO_RETRY_AFTER = 0x30000B;             // float: Retry-After 头

// ════════════════════════════════════════════════════════════
// CURLMSG_* / CURLVERSION_* 其他常量
// ════════════════════════════════════════════════════════════
const CURLMSG_DONE = 1;                           // 消息类型：传输完成
const CURLVERSION_NOW = 7;                        // curl_version_info_data 结构版本

// ════════════════════════════════════════════════════════════
// CURLM_* curl_multi 错误码
// ════════════════════════════════════════════════════════════
const CURLM_BAD_EASY_HANDLE = 2;                  // 无效的 easy handle
const CURLM_BAD_HANDLE = 1;                       // 无效的 multi handle
const CURLM_CALL_MULTI_PERFORM = -1;              // 继续调用（旧）
const CURLM_INTERNAL_ERROR = 4;                   // 内部错误
const CURLM_OK = 0;                               // 成功
const CURLM_OUT_OF_MEMORY = 3;                    // 内存不足
const CURLM_ADDED_ALREADY = 7;                    // 已添加

// ════════════════════════════════════════════════════════════
// CURLPROXY_* 代理类型
// ════════════════════════════════════════════════════════════
const CURLPROXY_HTTP = 0;                         // HTTP 代理
const CURLPROXY_SOCKS4 = 4;                       // SOCKS4 代理
const CURLPROXY_SOCKS5 = 5;                       // SOCKS5 代理
const CURLPROXY_SOCKS4A = 6;                      // SOCKS4A 代理
const CURLPROXY_SOCKS5_HOSTNAME = 7;              // SOCKS5 远程解析
const CURLPROXY_HTTP_1_0 = 1;                     // HTTP/1.0 代理
const CURLPROXY_HTTPS = 2;                        // HTTPS 代理

// ════════════════════════════════════════════════════════════
// CURLSHOPT_* 共享句柄选项
// ════════════════════════════════════════════════════════════
const CURLSHOPT_NONE = 0;                         // 无操作
const CURLSHOPT_SHARE = 1;                        // 共享某类数据
const CURLSHOPT_UNSHARE = 2;                      // 取消共享

// ════════════════════════════════════════════════════════════
// CURL_HTTP_VERSION_* HTTP 版本
// ════════════════════════════════════════════════════════════
const CURL_HTTP_VERSION_1_0 = 1;                  // HTTP/1.0
const CURL_HTTP_VERSION_1_1 = 2;                  // HTTP/1.1
const CURL_HTTP_VERSION_NONE = 0;                 // 自动选择
const CURL_HTTP_VERSION_2_0 = 3;                  // HTTP/2（协商）
const CURL_HTTP_VERSION_2 = 3;                    // HTTP/2（与 2_0 同值）
const CURL_HTTP_VERSION_2TLS = 4;                 // HTTP/2 over TLS
const CURL_HTTP_VERSION_2_PRIOR_KNOWLEDGE = 5;    // HTTP/2 直连
const CURL_HTTP_VERSION_3 = 30;                   // HTTP/3
const CURL_HTTP_VERSION_3ONLY = 31;               // 仅 HTTP/3

// ════════════════════════════════════════════════════════════
// CURL_LOCK_DATA_* 共享锁数据类型
// ════════════════════════════════════════════════════════════
const CURL_LOCK_DATA_COOKIE = 2;                  // Cookie
const CURL_LOCK_DATA_DNS = 3;                     // DNS
const CURL_LOCK_DATA_SSL_SESSION = 4;             // SSL 会话
const CURL_LOCK_DATA_CONNECT = 6;                 // 连接池
const CURL_LOCK_DATA_PSL = 7;                     // 公共后缀列表

// ════════════════════════════════════════════════════════════
// CURL_NETRC_* .netrc 处理方式
// ════════════════════════════════════════════════════════════
const CURL_NETRC_IGNORED = 0;                     // 忽略
const CURL_NETRC_OPTIONAL = 1;                    // 可选
const CURL_NETRC_REQUIRED = 2;                    // 必须

// ════════════════════════════════════════════════════════════
// CURL_SSLVERSION_* SSL/TLS 版本
// ════════════════════════════════════════════════════════════
const CURL_SSLVERSION_DEFAULT = 0;                // 默认
const CURL_SSLVERSION_SSLv2 = 2;                  // SSLv2（已废弃）
const CURL_SSLVERSION_SSLv3 = 3;                  // SSLv3（已废弃）
const CURL_SSLVERSION_TLSv1 = 1;                  // TLSv1.0
const CURL_SSLVERSION_TLSv1_0 = 4;                // TLSv1.0
const CURL_SSLVERSION_TLSv1_1 = 5;                // TLSv1.1
const CURL_SSLVERSION_TLSv1_2 = 6;                // TLSv1.2
const CURL_SSLVERSION_TLSv1_3 = 7;                // TLSv1.3
const CURL_SSLVERSION_MAX_DEFAULT = 0x10000;      // 最大版本默认
const CURL_SSLVERSION_MAX_NONE = 0x00000;         // 不限制最大版本
const CURL_SSLVERSION_MAX_TLSv1_0 = 0x40000;      // 最大 TLSv1.0
const CURL_SSLVERSION_MAX_TLSv1_1 = 0x50000;      // 最大 TLSv1.1
const CURL_SSLVERSION_MAX_TLSv1_2 = 0x60000;      // 最大 TLSv1.2
const CURL_SSLVERSION_MAX_TLSv1_3 = 0x70000;      // 最大 TLSv1.3

// ════════════════════════════════════════════════════════════
// CURL_TIMECOND_* 时间条件（CURLOPT_TIMECONDITION）
// ════════════════════════════════════════════════════════════
const CURL_TIMECOND_IFMODSINCE = 1;               // If-Modified-Since
const CURL_TIMECOND_IFUNMODSINCE = 2;             // If-Unmodified-Since
const CURL_TIMECOND_LASTMOD = 3;                  // Last-Modified
const CURL_TIMECOND_NONE = 0;                     // 无

// ════════════════════════════════════════════════════════════
// CURL_VERSION_* curl_version features 位标志
// ════════════════════════════════════════════════════════════
const CURL_VERSION_ASYNCHDNS = 128;               // 异步 DNS
const CURL_VERSION_CONV = 4096;                   // 字符集转换
const CURL_VERSION_DEBUG = 64;                    // 调试构建
const CURL_VERSION_GSSNEGOTIATE = 32;             // GSS-API 协商（已废弃）
const CURL_VERSION_IDN = 16;                      // 国际化域名
const CURL_VERSION_IPV6 = 1;                      // IPv6
const CURL_VERSION_KERBEROS4 = 2;                 // Kerberos 4
const CURL_VERSION_LARGEFILE = 256;               // 大文件支持
const CURL_VERSION_LIBZ = 8;                      // zlib
const CURL_VERSION_NTLM = 4;                      // NTLM
const CURL_VERSION_SPNEGO = 256;                  // SPNEGO // TODO: verify (may overlap LARGEFILE in old libcurl)
const CURL_VERSION_SSL = 2;                       // SSL // TODO: verify (may conflict with KERBEROS4)
const CURL_VERSION_SSPI = 512;                    // SSPI
const CURL_VERSION_CURLDEBUG = 8192;              // curl 调试构建
const CURL_VERSION_TLSAUTH_SRP = 1024;            // TLS-SRP
const CURL_VERSION_NTLM_WB = 32768;               // NTLM_WB
const CURL_VERSION_HTTP2 = 65536;                 // HTTP/2
const CURL_VERSION_GSSAPI = 131072;               // GSS-API
const CURL_VERSION_KERBEROS5 = 262144;            // Kerberos 5
const CURL_VERSION_UNIX_SOCKETS = 524288;         // Unix 域 socket
const CURL_VERSION_PSL = 1048576;                 // 公共后缀列表
const CURL_VERSION_HTTPS_PROXY = 2097152;         // HTTPS 代理
const CURL_VERSION_MULTI_SSL = 4194304;           // 多 SSL 后端
const CURL_VERSION_BROTLI = 8388608;              // Brotli
const CURL_VERSION_ALTSVC = 16777216;             // Alt-Svc
const CURL_VERSION_HTTP3 = 33554432;              // HTTP/3
const CURL_VERSION_ZSTD = 67108864;               // Zstandard
const CURL_VERSION_UNICODE = 134217728;           // Unicode
const CURL_VERSION_HSTS = 268435456;              // HSTS
const CURL_VERSION_GSASL = 536870912;             // GSASL

// ════════════════════════════════════════════════════════════
// CURLAUTH_* HTTP 认证方法
// ════════════════════════════════════════════════════════════
const CURLAUTH_ANY = ~1;                          // 除 Basic 外全部（按位取反）
const CURLAUTH_ANYSAFE = ~2;                      // 除 Basic+Digest 外全部
const CURLAUTH_BASIC = 1;                         // Basic
const CURLAUTH_DIGEST = 2;                        // Digest
const CURLAUTH_GSSNEGOTIATE = 4;                  // GSS-API 协商（已废弃，用 NEGOTIATE）
const CURLAUTH_NONE = 0;                          // 无
const CURLAUTH_NTLM = 8;                          // NTLM
const CURLAUTH_DIGEST_IE = 16;                    // Digest IE 模式
const CURLAUTH_ONLY = 2147483648;                 // 仅此认证（与其它位 OR）
const CURLAUTH_NEGOTIATE = 4;                     // Negotiate（与 GSSNEGOTIATE 同值）
const CURLAUTH_NTLM_WB = 32;                      // NTLM WinBind
const CURLAUTH_GSSAPI = 4;                        // GSSAPI // TODO: verify value
const CURLAUTH_BEARER = 64;                       // Bearer token
const CURLAUTH_AWS_SIGV4 = 128;                   // AWS SigV4

// ════════════════════════════════════════════════════════════
// CURLE_* cURL 错误码
// ════════════════════════════════════════════════════════════
const CURLE_ABORTED_BY_CALLBACK = 42;             // 回调中止
const CURLE_BAD_CALLING_ORDER = 44;               // 调用顺序错误（已废弃）
const CURLE_BAD_CONTENT_ENCODING = 61;            // 内容编码错误
const CURLE_BAD_DOWNLOAD_RESUME = 36;             // 断点续传错误
const CURLE_BAD_FUNCTION_ARGUMENT = 43;           // 参数错误
const CURLE_BAD_PASSWORD_ENTERED = 46;            // 密码错误（已废弃）
const CURLE_COULDNT_CONNECT = 7;                  // 无法连接
const CURLE_COULDNT_RESOLVE_HOST = 6;             // 无法解析主机
const CURLE_COULDNT_RESOLVE_PROXY = 5;            // 无法解析代理
const CURLE_FAILED_INIT = 2;                      // 初始化失败
const CURLE_FILE_COULDNT_READ_FILE = 37;          // 无法读取文件
const CURLE_FTP_ACCESS_DENIED = 9;                // FTP 拒绝访问
const CURLE_FTP_BAD_DOWNLOAD_RESUME = 36;         // FTP 断点续传错误
const CURLE_FTP_CANT_GET_HOST = 15;               // FTP 无法获取主机
const CURLE_FTP_CANT_RECONNECT = 16;              // FTP 无法重连
const CURLE_FTP_COULDNT_GET_SIZE = 32;            // FTP 无法获取大小
const CURLE_FTP_COULDNT_RETR_FILE = 19;           // FTP 无法下载文件
const CURLE_FTP_COULDNT_SET_ASCII = 29;           // FTP 无法设置 ASCII（已废弃）
const CURLE_FTP_COULDNT_SET_BINARY = 17;          // FTP 无法设置二进制
const CURLE_FTP_COULDNT_STOR_FILE = 25;           // FTP 无法上传文件
const CURLE_FTP_COULDNT_USE_REST = 31;            // FTP 无法使用 REST
const CURLE_FTP_PARTIAL_FILE = 18;                // FTP 文件不完整
const CURLE_FTP_PORT_FAILED = 30;                 // FTP PORT 失败
const CURLE_FTP_QUOTE_ERROR = 21;                 // FTP QUOTE 错误
const CURLE_FTP_USER_PASSWORD_INCORRECT = 10;     // FTP 密码错误（已废弃）
const CURLE_FTP_WEIRD_227_FORMAT = 14;            // FTP 227 格式异常
const CURLE_FTP_WEIRD_PASS_REPLY = 11;            // FTP PASS 响应异常
const CURLE_FTP_WEIRD_PASV_REPLY = 13;            // FTP PASV 响应异常
const CURLE_FTP_WEIRD_SERVER_REPLY = 8;           // FTP 服务器响应异常
const CURLE_FTP_WEIRD_USER_REPLY = 12;            // FTP USER 响应异常
const CURLE_FTP_WRITE_ERROR = 23;                 // FTP 写入错误
const CURLE_FUNCTION_NOT_FOUND = 41;              // 函数未找到
const CURLE_GOT_NOTHING = 52;                     // 服务器无响应
const CURLE_HTTP_NOT_FOUND = 22;                  // HTTP 404
const CURLE_HTTP_PORT_FAILED = 45;                // HTTP 端口失败（已废弃）
const CURLE_HTTP_POST_ERROR = 34;                 // HTTP POST 错误
const CURLE_HTTP_RANGE_ERROR = 33;                // HTTP Range 错误
const CURLE_HTTP_RETURNED_ERROR = 22;             // HTTP 错误返回（与 NOT_FOUND 同值）
const CURLE_LDAP_CANNOT_BIND = 38;                // LDAP 无法绑定
const CURLE_LDAP_SEARCH_FAILED = 39;              // LDAP 搜索失败
const CURLE_LIBRARY_NOT_FOUND = 40;               // 库未找到（已废弃）
const CURLE_MALFORMAT_USER = 24;                  // URL 格式错误-用户（已废弃）
const CURLE_OBSOLETE = 50;                        // 已废弃
const CURLE_OK = 0;                               // 成功
const CURLE_OPERATION_TIMEDOUT = 28;              // 操作超时
const CURLE_OPERATION_TIMEOUTED = 28;             // alias of OPERATION_TIMEDOUT（已废弃）
const CURLE_OUT_OF_MEMORY = 27;                   // 内存不足
const CURLE_PARTIAL_FILE = 18;                    // 文件不完整
const CURLE_READ_ERROR = 26;                      // 读取错误
const CURLE_RECV_ERROR = 56;                      // 接收错误
const CURLE_SEND_ERROR = 55;                      // 发送错误
const CURLE_SHARE_IN_USE = 57;                    // 共享句柄使用中（已废弃）
const CURLE_SSL_CACERT = 60;                      // CA 证书错误（已废弃，用 SSL_CACERT_BADFILE）
const CURLE_SSL_CERTPROBLEM = 58;                 // 证书问题
const CURLE_SSL_CIPHER = 59;                      // 密码套件错误
const CURLE_SSL_CONNECT_ERROR = 35;               // SSL 连接错误
const CURLE_SSL_ENGINE_NOTFOUND = 53;             // SSL 引擎未找到
const CURLE_SSL_ENGINE_SETFAILED = 54;            // SSL 引擎设置失败
const CURLE_SSL_PEER_CERTIFICATE = 51;            // 对端证书错误（已废弃）
const CURLE_SSL_PINNEDPUBKEYNOTMATCH = 90;        // 固定公钥不匹配
const CURLE_TELNET_OPTION_SYNTAX = 49;            // Telnet 选项语法错误
const CURLE_TOO_MANY_REDIRECTS = 47;              // 重定向过多
const CURLE_UNKNOWN_TELNET_OPTION = 48;           // 未知 Telnet 选项
const CURLE_UNSUPPORTED_PROTOCOL = 1;             // 不支持的协议
const CURLE_URL_MALFORMAT = 3;                    // URL 格式错误
const CURLE_URL_MALFORMAT_USER = 4;               // URL 格式错误-用户（已废弃）
const CURLE_WRITE_ERROR = 23;                     // 写入错误
const CURLE_FILESIZE_EXCEEDED = 63;               // 文件大小超限
const CURLE_LDAP_INVALID_URL = 62;                // LDAP URL 无效
const CURLE_FTP_SSL_FAILED = 64;                  // FTP SSL 失败
const CURLE_SSL_CACERT_BADFILE = 77;              // CA 证书文件错误
const CURLE_SSH = 79;                             // SSH 错误
const CURLE_WEIRD_SERVER_REPLY = 85;              // 服务器响应异常
const CURLE_PROXY = 97;                           // 代理错误
const CURLE_UNKNOWN_OPTION = 49;                  // 未知选项（与 CURLE_TELNET_OPTION_SYNTAX 同值，为新名称）

// ════════════════════════════════════════════════════════════
// CURLPROTO_* 协议位掩码
// ════════════════════════════════════════════════════════════
const CURLPROTO_ALL = 0xFFFFFFFF;                 // 所有协议
const CURLPROTO_DICT = 512;                       // dict://
const CURLPROTO_FILE = 1024;                      // file://
const CURLPROTO_FTP = 4;                          // ftp://
const CURLPROTO_FTPS = 8;                         // ftps://
const CURLPROTO_HTTP = 1;                         // http://
const CURLPROTO_HTTPS = 2;                        // https://
const CURLPROTO_LDAP = 128;                       // ldap://
const CURLPROTO_LDAPS = 256;                      // ldaps://
const CURLPROTO_SCP = 64;                         // scp://
const CURLPROTO_SFTP = 32;                        // sftp://
const CURLPROTO_TELNET = 16;                      // telnet://
const CURLPROTO_TFTP = 2048;                      // tftp://
const CURLPROTO_IMAP = 4096;                      // imap://
const CURLPROTO_IMAPS = 8192;                     // imaps://
const CURLPROTO_POP3 = 16384;                     // pop3://
const CURLPROTO_POP3S = 32768;                    // pop3s://
const CURLPROTO_RTSP = 262144;                    // rtsp://
const CURLPROTO_SMTP = 65536;                     // smtp://
const CURLPROTO_SMTPS = 131072;                   // smtps://
const CURLPROTO_RTMP = 524288;                    // rtmp://
const CURLPROTO_RTMPE = 1048576;                  // rtmpe://
const CURLPROTO_RTMPS = 2097152;                  // rtmps://
const CURLPROTO_RTMPT = 4194304;                  // rtmpt://
const CURLPROTO_RTMPTE = 8388608;                 // rtmpte://
const CURLPROTO_RTMPTS = 16777216;                // rtmpts://
const CURLPROTO_GOPHER = 33554432;                // gopher://
const CURLPROTO_SMB = 67108864;                   // smb://
const CURLPROTO_SMBS = 134217728;                 // smbs://
const CURLPROTO_MQTT = 268435456;                 // mqtt://

// ════════════════════════════════════════════════════════════
// CURLFTPSSL_* / CURLUSESSL_* / CURLFTPMETHOD_* / CURLFTPAUTH_* FTP 相关
// ════════════════════════════════════════════════════════════
const CURLFTPSSL_ALL = 1;                         // alias of CURLUSESSL_ALL
const CURLFTPSSL_CONTROL = 2;                     // alias of CURLUSESSL_CONTROL
const CURLFTPSSL_NONE = 0;                        // alias of CURLUSESSL_NONE
const CURLFTPSSL_TRY = 3;                         // alias of CURLUSESSL_TRY
const CURLFTPSSL_CCC_ACTIVE = 1;                  // 主动 CCC
const CURLFTPSSL_CCC_NONE = 0;                    // 不 CCC
const CURLFTPSSL_CCC_PASSIVE = 2;                 // 被动 CCC
const CURLUSESSL_ALL = 1;                         // 全部 SSL
const CURLUSESSL_CONTROL = 2;                     // 控制连接 SSL
const CURLUSESSL_NONE = 0;                        // 不用 SSL
const CURLUSESSL_TRY = 3;                         // 尝试 SSL
const CURLFTPMETHOD_DEFAULT = 0;                  // 默认方法
const CURLFTPMETHOD_MULTICWD = 1;                 // 多步 CWD
const CURLFTPMETHOD_NOCWD = 2;                    // 不 CWD
const CURLFTPMETHOD_SINGLECWD = 3;                // 单步 CWD
const CURLFTPAUTH_DEFAULT = 0;                    // 默认认证
const CURLFTPAUTH_SSL = 1;                        // 优先 SSL
const CURLFTPAUTH_TLS = 2;                        // 优先 TLS
const CURLFTP_CREATE_DIR = 1;                     // 自动创建目录
const CURLFTP_CREATE_DIR_NONE = 0;                // 不创建
const CURLFTP_CREATE_DIR_RETRY = 2;               // 创建并重试

// ════════════════════════════════════════════════════════════
// CURL_RTSPREQ_* RTSP 请求类型
// ════════════════════════════════════════════════════════════
const CURL_RTSPREQ_ANNOUNCE = 8;                  // ANNOUNCE
const CURL_RTSPREQ_DESCRIBE = 2;                  // DESCRIBE
const CURL_RTSPREQ_GET_PARAMETER = 11;            // GET_PARAMETER
const CURL_RTSPREQ_OPTIONS = 1;                   // OPTIONS
const CURL_RTSPREQ_PAUSE = 7;                     // PAUSE
const CURL_RTSPREQ_PLAY = 5;                      // PLAY
const CURL_RTSPREQ_RECEIVE = 4;                   // RECEIVE
const CURL_RTSPREQ_RECORD = 9;                    // RECORD
const CURL_RTSPREQ_SET_PARAMETER = 10;            // SET_PARAMETER
const CURL_RTSPREQ_SETUP = 3;                     // SETUP
const CURL_RTSPREQ_TEARDOWN = 6;                  // TEARDOWN

// ════════════════════════════════════════════════════════════
// CURLPAUSE_* 暂停常量
// ════════════════════════════════════════════════════════════
const CURLPAUSE_ALL = 5;                          // 暂停全部（RECV|SEND）
const CURLPAUSE_CONT = 0;                         // 继续全部
const CURLPAUSE_RECV = 1;                         // 暂停接收
const CURLPAUSE_RECV_CONT = 0;                    // 继续接收
const CURLPAUSE_SEND = 4;                         // 暂停发送
const CURLPAUSE_SEND_CONT = 0;                    // 继续发送

// ════════════════════════════════════════════════════════════
// CURL_READFUNC_* / CURL_WRITEFUNC_* 回调返回码
// ════════════════════════════════════════════════════════════
const CURL_READFUNC_PAUSE = 0x10000001;           // 暂停读取
const CURL_WRITEFUNC_PAUSE = 0x10000001;          // 暂停写入

// ════════════════════════════════════════════════════════════
// CURL_IPRESOLVE_* IP 解析策略
// ════════════════════════════════════════════════════════════
const CURL_IPRESOLVE_V4 = 1;                      // 仅 IPv4
const CURL_IPRESOLVE_V6 = 2;                      // 仅 IPv6
const CURL_IPRESOLVE_WHATEVER = 0;                // 默认

// ════════════════════════════════════════════════════════════
// CURLSSH_* SSH 认证类型
// ════════════════════════════════════════════════════════════
const CURLSSH_AUTH_ANY = ~0;                      // 任何方式
const CURLSSH_AUTH_DEFAULT = ~0;                  // 默认（与 ANY 同值）
const CURLSSH_AUTH_HOST = 4;                      // 主机认证
const CURLSSH_AUTH_KEYBOARD = 8;                  // 键盘交互
const CURLSSH_AUTH_NONE = 0;                      // 无
const CURLSSH_AUTH_PASSWORD = 2;                  // 密码
const CURLSSH_AUTH_PUBLICKEY = 1;                 // 公钥
const CURLSSH_AUTH_AGENT = 16;                    // ssh-agent
const CURLSSH_AUTH_GSSAPI = 32;                   // GSSAPI

// ════════════════════════════════════════════════════════════
// CURLKHMATCH_* SSH 主机密钥匹配结果
// ════════════════════════════════════════════════════════════
const CURLKHMATCH_OK = 0;                         // 匹配
const CURLKHMATCH_MISMATCH = 1;                   // 不匹配
const CURLKHMATCH_MISSING = 2;                    // 缺失
const CURLKHMATCH_LAST = 3;                       // 标记

// ════════════════════════════════════════════════════════════
// CURL_FNMATCHFUNC_* 通配符匹配回调结果
// ════════════════════════════════════════════════════════════
const CURL_FNMATCHFUNC_FAIL = 2;                  // 错误
const CURL_FNMATCHFUNC_MATCH = 0;                 // 匹配
const CURL_FNMATCHFUNC_NOMATCH = 1;               // 不匹配

// ════════════════════════════════════════════════════════════
// CURLMOPT_* curl_multi 选项
// ════════════════════════════════════════════════════════════
const CURLMOPT_PIPELINING = 3;                    // int: HTTP 流水线
const CURLMOPT_MAXCONNECTS = 6;                   // int: 最大连接数
const CURLMOPT_CHUNK_LENGTH_PENALTY_SIZE = 30009; // int: 块长度惩罚
const CURLMOPT_CONTENT_LENGTH_PENALTY_SIZE = 30010; // int: 内容长度惩罚
const CURLMOPT_MAX_HOST_CONNECTIONS = 7;          // int: 每主机最大连接
const CURLMOPT_MAX_PIPELINE_LENGTH = 8;           // int: 最大流水线长度
const CURLMOPT_MAX_TOTAL_CONNECTIONS = 13;        // int: 最大总连接
const CURLMOPT_PUSHFUNCTION = 30014;              // callback: 服务器推送回调
const CURLMOPT_MAX_CONCURRENT_STREAMS = 16;       // int: 最大并发流

// ════════════════════════════════════════════════════════════
// CURLHEADER_* 头部选项
// ════════════════════════════════════════════════════════════
const CURLHEADER_SEPARATE = 0;                    // 分离头
const CURLHEADER_UNIFIED = 1;                     // 统一头

// ════════════════════════════════════════════════════════════
// CURLSSLOPT_* SSL 选项位标志
// ════════════════════════════════════════════════════════════
const CURLSSLOPT_ALLOW_BEAST = 1;                 // 允许 BEAST
const CURLSSLOPT_NO_REVOKE = 2;                   // 禁用吊销检查
const CURLSSLOPT_NO_PARTIALCHAIN = 4;             // 禁用部分链
const CURLSSLOPT_REVOKE_BEST_EFFORT = 8;          // 尽力吊销检查
const CURLSSLOPT_NATIVE_CA = 16;                  // 使用系统 CA
const CURLSSLOPT_AUTO_CLIENT_CERT = 32;           // 自动客户端证书

// ════════════════════════════════════════════════════════════
// CURLTLSAUTH_* / CURL_REDIR_POST_* / CURLPIPE_* 其他常量
// ════════════════════════════════════════════════════════════
const CURL_TLSAUTH_SRP = 1;                       // TLS-SRP 认证
const CURL_REDIR_POST_301 = 1;                    // 301 重定向保留 POST
const CURL_REDIR_POST_302 = 2;                    // 302 重定向保留 POST
const CURL_REDIR_POST_ALL = 7;                    // 所有重定向保留 POST
const CURL_REDIR_POST_303 = 4;                    // 303 重定向保留 POST
const CURLPIPE_NOTHING = 0;                       // 不流水线
const CURLPIPE_HTTP1 = 1;                         // HTTP/1.1 流水线
const CURLPIPE_MULTIPLEX = 2;                     // 多路复用

// ════════════════════════════════════════════════════════════
// CURLGSSAPI_* GSSAPI 委托标志
// ════════════════════════════════════════════════════════════
const CURLGSSAPI_DELEGATION_FLAG = 1;             // 委托标志
const CURLGSSAPI_DELEGATION_POLICY_FLAG = 2;      // 委托策略标志

// ════════════════════════════════════════════════════════════
// CURLALTSVC_* Alt-Svc 选项
// ════════════════════════════════════════════════════════════
const CURLALTSVC_H1 = 1;                          // 接受 HTTP/1 Alt-Svc
const CURLALTSVC_H2 = 2;                          // 接受 HTTP/2 Alt-Svc
const CURLALTSVC_H3 = 4;                          // 接受 HTTP/3 Alt-Svc
const CURLALTSVC_READONLYFILE = 8;                // 只读 Alt-Svc 文件

// ════════════════════════════════════════════════════════════
// CURLHSTS_* HSTS 选项
// ════════════════════════════════════════════════════════════
const CURLHSTS_ENABLE = 1;                        // 启用 HSTS
const CURLHSTS_READONLYFILE = 2;                  // 只读 HSTS 文件

// ════════════════════════════════════════════════════════════
// CURLWS_* WebSocket 常量
// ════════════════════════════════════════════════════════════
const CURLWS_RAW_MODE = 1;                        // WebSocket 原始模式

// ════════════════════════════════════════════════════════════
// CURL_PUSH_* 服务器推送回调返回码
// ════════════════════════════════════════════════════════════
const CURL_PUSH_OK = 0;                           // 接受推送
const CURL_PUSH_DENY = 1;                         // 拒绝推送

// ════════════════════════════════════════════════════════════
// CURL_PREREQFUNC_* 预请求回调返回码
// ════════════════════════════════════════════════════════════
const CURL_PREREQFUNC_OK = 0;                     // 继续
const CURL_PREREQFUNC_ABORT = 1;                  // 中止

// ════════════════════════════════════════════════════════════
// CURLMIMEOPT_* MIME 选项
// ════════════════════════════════════════════════════════════
const CURLMIMEOPT_FORMESCAPE = 1;                 // 表单转义

// ════════════════════════════════════════════════════════════
// CURL_MAX_READ_SIZE 最大读取大小
// ════════════════════════════════════════════════════════════
const CURL_MAX_READ_SIZE = 10485760;              // 10 * 1024 * 1024，CURLOPT_BUFFERSIZE 上限

// ════════════════════════════════════════════════════════════
// CURLPX_* 代理错误码（since 7.73.0）
// ════════════════════════════════════════════════════════════
const CURLPX_BAD_ADDRESS_TYPE = 1;                // 地址类型错误
const CURLPX_BAD_VERSION = 2;                     // 版本错误
const CURLPX_CLOSED = 3;                          // 连接关闭
const CURLPX_GSSAPI = 4;                          // GSSAPI 错误
const CURLPX_GSSAPI_PERMSG = 5;                   // GSSAPI per-message 错误
const CURLPX_GSSAPI_PROTECTION = 6;               // GSSAPI 保护错误
const CURLPX_IDENTD = 7;                          // identd 错误
const CURLPX_IDENTD_DIFFER = 8;                   // identd 不匹配
const CURLPX_LONG_HOSTNAME = 9;                   // 主机名过长
const CURLPX_LONG_PASSWD = 10;                    // 密码过长
const CURLPX_LONG_USER = 11;                      // 用户名过长
const CURLPX_NO_AUTH = 12;                        // 无可用认证
const CURLPX_OK = 0;                              // 成功
const CURLPX_RECV_ADDRESS = 13;                   // 接收地址错误
const CURLPX_RECV_AUTH = 14;                      // 接收认证错误
const CURLPX_RECV_CONNECT = 15;                   // 接收 CONNECT 错误
const CURLPX_RECV_REQACK = 16;                    // 接收 REQACK 错误
const CURLPX_REPLY_ADDRESS_TYPE_NOT_SUPPORTED = 17; // 地址类型不支持
const CURLPX_REPLY_COMMAND_NOT_SUPPORTED = 18;    // 命令不支持
const CURLPX_REPLY_CONNECTION_REFUSED = 19;       // 连接拒绝
const CURLPX_REPLY_GENERAL_SERVER_FAILURE = 20;   // 通用服务器失败
const CURLPX_REPLY_HOST_UNREACHABLE = 21;         // 主机不可达
const CURLPX_REPLY_NETWORK_UNREACHABLE = 22;      // 网络不可达
const CURLPX_REPLY_NOT_ALLOWED = 23;              // 不允许
const CURLPX_REPLY_TTL_EXPIRED = 24;              // TTL 过期
const CURLPX_REPLY_UNASSIGNED = 25;               // 未分配
const CURLPX_REQUEST_FAILED = 26;                 // 请求失败
const CURLPX_RESOLVE_HOST = 27;                   // 解析主机失败
const CURLPX_SEND_AUTH = 28;                      // 发送认证失败
const CURLPX_SEND_CONNECT = 29;                   // 发送 CONNECT 失败
const CURLPX_SEND_REQUEST = 30;                   // 发送请求失败
const CURLPX_UNKNOWN_FAIL = 31;                   // 未知失败
const CURLPX_UNKNOWN_MODE = 32;                   // 未知模式
const CURLPX_USER_REJECTED = 33;                  // 用户拒绝
