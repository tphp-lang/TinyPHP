# TinyPHP 扩展 API 参考与实现计划

> 待实现的 15 个扩展。每个列出：全部函数签名 / 常量 / 参数说明 / 返回值 / 推荐参考库 / 实现步骤。
> 优先级见底部表格，建议按 bcrypt → filter → calendar 顺序逐步实现。

### C 标识符命名规范

| 场景     | 格式                           | 示例                                |
| ------ | ---------------------------- | --------------------------------- |
| 全局类    | `tphp_class_Name`            | `tphp_class_Main`                 |
| 全局函数   | `tphp_fn_name`               | `tphp_fn_hello`                   |
| 命名空间类  | `tphp_na_Ns_tphp_class_Name` | `tphp_na_Demo_tphp_class_MyClass` |
| 命名空间函数 | `tphp_na_Ns_tphp_fn_name`    | `tphp_na_Demo_tphp_fn_greet`      |
| 常量     | `TPHP_CONST_NAME`            | `TPHP_CONST_PI`                   |

### 资源类型设计说明

> TinyPHP 使用**资源对象化**替代 PHP 的弱类型 resource。
> 函数签名中的 `resource` 类型对应具体的对象类（如 `File`、`Socket`、`Db`），
> 而非 PHP 的运行期类型擦除句柄。详见 `object/resource.h`。

***

## 目录

| #  | 扩展                           | 复杂度   | 依赖             | 函数数 |
| -- | ---------------------------- | ----- | -------------- | --- |
| 2  | filter\_var ✅ 已完成 | ⭐⭐    | 无              | 2   |
| 3  | calendar ✅ 已完成 | ⭐⭐⭐   | 无              | 16  |
| 4  | [zlib (gzip)](#4-zlib-gzip) ✅ 已完成(内置) | ⭐⭐⭐   | 内置 zlib 1.3.2 源码 | 29  |
| 5  | [stream](#5-stream) ✅ 已完成 | ⭐⭐⭐⭐  | winsock2(Windows)/libc(POSIX) | 21  |
| 6  | [SQLite](#6-sqlite)          | ⭐⭐⭐⭐  | sqlite3        | 6   |
| 7  | [cURL](#7-curl)              | ⭐⭐⭐⭐  | libcurl        | 8   |
| 8  | [OpenSSL](#8-openssl) ✅ 已完成 | ⭐⭐⭐⭐⭐ | 内置 mbedTLS 3.6.6 源码（静态编译，零运行时依赖） | 21  |
| 9  | [fileinfo](#9-fileinfo) ✅   | ⭐⭐⭐   | 内置魔数表       | 4   |
| 10 | [iconv](#10-iconv) ✅ 已完成 | ⭐⭐⭐   | libiconv/系统    | 8   |
| 11 | [exif](#11-exif) ✅ 已完成     | ⭐⭐⭐   | 无(纯解析)         | 6   |
| 12 | [ZIP](#12-zip) ✅ 已完成(内置)  | ⭐⭐⭐⭐  | 内置 zlib (手写ZIP) | 18  |
| 13 | [MySQL](#13-mysql)           | ⭐⭐⭐⭐⭐ | libmysqlclient | 16  |
| 14 | [PDO](#14-pdo) ✅ 已完成       | ⭐⭐⭐⭐⭐ | sqlite3 (内置 amalgamation) | 10  |
| 15 | [GD](#15-gd)                 | ⭐⭐⭐⭐⭐ | libgd+png+jpeg | 20  |

***

## 2. filter\_var ✅ 已完成

> 已实现于 `include/filter.h`（内置功能，非 ext/ 扩展），文档见 FUNCTIONS.md "ext/filter — 过滤器" 章节。

***

## 3. calendar ✅ 已完成

> 已实现于 `ext/calendar/src/calendar.php`，文档见 FUNCTIONS.md "calendar — 日历转换" 章节。

***

## 4. zlib (gzip) ✅ 已完成(内置)

> 已实现于 include/zlib/（内置 zlib 1.3.2 源码静态编译），文档见 FUNCTIONS.md "zlib — gzip 压缩" 章节。

***

## 5. stream ✅ 已完成

> 已实现于 ext/stream/src/stream.h + ext/stream/src/stream.php，文档见 FUNCTIONS.md "stream — 流与 Socket" 章节。

***

## 6. SQLite

### 推荐参考库

| 库                             | 说明                           | 链接                       |
| ----------------------------- | ---------------------------- | ------------------------ |
| **SQLite3 Amalgamation**      | 官方单文件发行版，sqlite3.c+sqlite3.h | sqlite.org/download.html |
| **PHP 源码** **`ext/sqlite3/`** | OO 接口参考                      | `sqlite3.c` (\~70KB)     |
| **SQLite 官方文档**               | C API 完整参考                   | sqlite.org/capi3ref.html |

### 完整 API

```php
// ================================================================
// 常量
// ================================================================
const SQLITE3_OK = 0;             // 成功
const SQLITE3_ASSOC = 1;          // query 返回关联数组
const SQLITE3_NUM = 2;            // query 返回索引数组
const SQLITE3_BOTH = 3;           // query 返回索引+关联
const SQLITE3_INTEGER = 1;        // 列类型
const SQLITE3_FLOAT = 2;
const SQLITE3_TEXT = 3;
const SQLITE3_BLOB = 4;
const SQLITE3_NULL = 5;
const SQLITE3_OPEN_READONLY = 1;
const SQLITE3_OPEN_READWRITE = 2;
const SQLITE3_OPEN_CREATE = 4;

// ================================================================
// 函数
// ================================================================

/**
 * sqlite_open(string $filename, int $flags = READWRITE|CREATE, string $enc_key = ""): resource|false
 *
 * 打开/创建 SQLite 数据库。
 * $filename = ":memory:" 创建内存数据库。
 */
function sqlite_open(string $filename, int $flags = READWRITE|CREATE, string $enc_key = ""): resource|false;

/**
 * sqlite_close(resource $db): void
 *
 * 关闭数据库连接。
 */
function sqlite_close(resource $db): void;

/**
 * sqlite_exec(resource $db, string $sql): bool
 *
 * 执行不返回结果的 SQL (CREATE, INSERT, UPDATE, DELETE 等)
 */
function sqlite_exec(resource $db, string $sql): bool;

/**
 * sqlite_query(resource $db, string $sql): array|false
 *
 * 执行 SELECT 查询，返回结果数组。
 * 每行是关联数组 (键=列名, 值=列值)。
 */
function sqlite_query(resource $db, string $sql): array|false;

/**
 * sqlite_query_single(resource $db, string $sql): array|false
 *
 * 执行 SELECT 查询，只返回第一行。
 * 适合 COUNT(*)、LIMIT 1 等场景。
 */
function sqlite_query_single(resource $db, string $sql): array|false;

/**
 * sqlite_escape_string(string $str): string
 *
 * 转义 SQL 字符串中的特殊字符 (单引号等)。
 */
function sqlite_escape_string(string $str): string;

/**
 * sqlite_changes(resource $db): int
 *
 * 返回最近一次 INSERT/UPDATE/DELETE 影响的行数。
 */
function sqlite_changes(resource $db): int;

/**
 * sqlite_last_insert_rowid(resource $db): int
 *
 * 返回最近一次 INSERT 的 rowid。
 */
function sqlite_last_insert_rowid(resource $db): int;

/**
 * sqlite_last_error_msg(resource $db): string
 *
 * 返回最近一次错误的消息。
 */
function sqlite_last_error_msg(resource $db): string;
```

***

## 7. cURL

### 推荐参考库

| 库                                     | 说明                      | 链接                                        |
| ------------------------------------- | ----------------------- | ----------------------------------------- |
| **libcurl**                           | 官方 HTTP/FTP/... 多协议客户端库 | curl.se/libcurl/                          |
| **PHP 源码** **`ext/curl/interface.c`** | 完整包装层                   | \~180KB, 900+ 行核心函数                       |
| **curl\_easy\_setopt 手册**             | 所有选项常量定义                | curl.se/libcurl/c/curl\_easy\_setopt.html |

### 完整 API

```php
// ================================================================
// 常用选项常量 (CURLOPT_*)
// 完整列表: https://curl.se/libcurl/c/curl_easy_setopt.html
// ================================================================
const CURLOPT_URL = 10002;           // string: 请求 URL
const CURLOPT_RETURNTRANSFER = 19913; // bool:  返回响应体而不直接输出
const CURLOPT_POST = 47;            // bool:  发送 POST 请求
const CURLOPT_POSTFIELDS = 10015;   // string: POST 数据
const CURLOPT_HTTPHEADER = 10023;   // array:  自定义 HTTP 头
const CURLOPT_FOLLOWLOCATION = 52;   // bool:  跟随 3xx 重定向
const CURLOPT_MAXREDIRS = 68;        // int:   最大重定向次数
const CURLOPT_TIMEOUT = 13;          // int:   请求超时秒数
const CURLOPT_CONNECTTIMEOUT = 78;   // int:   连接超时秒数
const CURLOPT_SSL_VERIFYPEER = 64;   // bool:  验证 SSL 证书
const CURLOPT_SSL_VERIFYHOST = 81;   // int:   验证 SSL hostname
const CURLOPT_USERAGENT = 10018;     // string: User-Agent 头
const CURLOPT_REFERER = 10016;       // string: Referer 头
const CURLOPT_COOKIE = 10022;        // string: Cookie 头
const CURLOPT_COOKIEFILE = 10031;    // string: 读取 Cookie 文件
const CURLOPT_COOKIEJAR = 10082;     // string: 写入 Cookie 文件
const CURLOPT_PROXY = 10004;         // string: 代理地址
const CURLOPT_PROXYPORT = 59;        // int:   代理端口
const CURLOPT_PROXYTYPE = 101;       // int:   代理类型 (HTTP/SOCKS4/SOCKS5)
const CURLOPT_HTTPAUTH = 107;        // int:   HTTP 认证方法
const CURLOPT_USERPWD = 10005;       // string: "user:pass" 认证
const CURLOPT_HTTPGET = 80;          // bool:  强制 GET
const CURLOPT_NOBODY = 44;           // bool:  不下载响应体 (HEAD)
const CURLOPT_CUSTOMREQUEST = 10036; // string: 自定义请求方法 (PUT/DELETE等)
const CURLOPT_VERBOSE = 41;          // bool:  详细输出
const CURLOPT_HEADER = 42;           // bool:  响应中包含 HTTP 头
const CURLOPT_NOPROGRESS = 43;       // bool:  关闭进度条
const CURLOPT_UPLOAD = 46;           // bool:  上传模式
const CURLOPT_INFILESIZE = 14;       // int:   上传文件大小
const CURLOPT_HTTP_VERSION = 84;     // int:   HTTP 版本 (1.0/1.1/2/3)
const CURLOPT_IPRESOLVE = 113;       // int:   IP 解析 (IPv4/IPv6)

// ================================================================
// CURLINFO_* 常量 (用于 curl_getinfo)
// ================================================================
const CURLINFO_HTTP_CODE = 0x2000001;       // int:   HTTP 状态码
const CURLINFO_TOTAL_TIME = 0x3000001;      // float: 总耗时
const CURLINFO_SIZE_DOWNLOAD = 0x3000006;   // float: 下载字节数
const CURLINFO_CONTENT_TYPE = 0x100000C;    // string: Content-Type

// ================================================================
// 函数
// ================================================================

/**
 * curl_init(string $url = ""): CurlHandle|false
 *
 * 初始化 cURL 会话，返回句柄。
 */
function curl_init(string $url = ""): CurlHandle|false;

/**
 * curl_setopt(CurlHandle $ch, int $option, mixed $value): bool
 *
 * 设置 cURL 传输选项。最常用的函数。
 *
 * @param CurlHandle $ch cURL 句柄
 * @param int $option CURLOPT_* 常量
 * @param mixed $value 选项值 (string/int/bool/array)
 * @return bool 成功返回 true
 */
function curl_setopt(CurlHandle $ch, int $option, mixed $value): bool;

/**
 * curl_setopt_array(CurlHandle $ch, array $options): bool
 *
 * 批量设置多个选项。
 * $options 键为 CURLOPT_* 常量, 值为选项值。
 * 失败时立即停止处理后续选项。
 */
function curl_setopt_array(CurlHandle $ch, array $options): bool;

/**
 * curl_exec(CurlHandle $ch): string|bool
 *
 * 执行 cURL 会话，返回响应体字符串。
 * 需要先设置 CURLOPT_RETURNTRANSFER = 1。
 * 失败返回 false。
 */
function curl_exec(CurlHandle $ch): string|bool;

/**
 * curl_getinfo(CurlHandle $ch, int $option = 0): mixed
 *
 * 获取传输信息。$option=0 返回所有信息数组。
 * 常用 option: CURLINFO_HTTP_CODE, CURLINFO_TOTAL_TIME
 */
function curl_getinfo(CurlHandle $ch, int $option = 0): mixed;

/**
 * curl_error(CurlHandle $ch): string
 *
 * 返回最后一次 cURL 操作的错误描述文本。
 */
function curl_error(CurlHandle $ch): string;

/**
 * curl_errno(CurlHandle $ch): int
 *
 * 返回最后一次 cURL 操作的错误码 (CURLE_*)
 */
function curl_errno(CurlHandle $ch): int;

/**
 * curl_close(CurlHandle $ch): void
 *
 * 关闭 cURL 会话，释放所有资源。
 */
function curl_close(CurlHandle $ch): void;

/**
 * curl_version(): array
 *
 * 返回 cURL 版本信息数组:
 *   ["version_number"] => int
 *   ["version"] => string
 *   ["ssl_version"] => string
 *   ["libz_version"] => string
 *   ["protocols"] => array of string
 */
function curl_version(): array;
```

***

## 8. OpenSSL ✅ 已完成

> 已实现于 ext/openssl/src/openssl.h + ext/openssl/src/openssl.php（内置 mbedTLS 3.6.6 源码），文档见 FUNCTIONS.md "openssl — TLS/SSL 加密" 章节。

***

## 9. fileinfo ✅ 已完成

> 已作为内置库集成在 include/fileinfo.h，文档见 FUNCTIONS.md "fileinfo — 文件类型检测" 章节。

***

## 10. iconv ✅ 已完成

> 已作为内置库集成在 include/iconv.h，文档见 FUNCTIONS.md "iconv — 字符编码转换" 章节。

***

## 11. exif ✅ 已完成

> 已实现于 ext/exif/src/exif.php，文档见 FUNCTIONS.md "exif — EXIF 元数据" 章节。

***

## 12. ZIP ✅ 已完成(内置)

> 已实现于 ext/zip/（内置 zlib + 手写 ZIP 格式），文档见 FUNCTIONS.md "zip — ZIP 压缩" 章节。

***

## 13. MySQL

| 属性         | 值                                           |
| ---------- | ------------------------------------------- |
| **外部依赖**   | libmysqlclient 或 libmariadb (\~5MB, 系统库或捆绑) |
| **预估行数**   | \~600 行 C 包装                                |
| **PHP 参考** | `ext/mysqli/mysqli.c` + `ext/mysqlnd/`      |
| **难度**     | ⭐⭐⭐⭐⭐                                       |

### 13.1 推荐参考库

| 库                                | 说明                                | 链接                                                            |
| -------------------------------- | --------------------------------- | ------------------------------------------------------------- |
| **MySQL C API (libmysqlclient)** | 官方客户端库，LGPL 协议                    | dev.mysql.com/doc/c-api/8.0/en/                               |
| **MariaDB C API (libmariadb)**   | 兼容 MySQL，LGPL 协议，更轻量              | mariadb.com/kb/en/mariadb-connector-c/                        |
| **PHP 源码** **`ext/mysqli/`**     | OO 接口参考                           | `mysqli.c` (\~150KB)                                          |
| **PHP 源码** **`ext/mysqlnd/`**    | MySQL Native Driver（不依赖 libmysql） | `mysqlnd/` 目录                                                 |
| **MySQL 协议文档**                   | 客户端-服务端协议                         | dev.mysql.com/doc/dev/mysql-server/latest/PAGE\_PROTOCOL.html |

### 13.2 选择：libmysqlclient vs libmariadb vs mysqlnd

| 方案                                   | 优点                              | 缺点                                                     |
| ------------------------------------ | ------------------------------- | ------------------------------------------------------ |
| **libmysqlclient** (MySQL 官方)        | 最完整支持，文档丰富                      | \~5MB 体积，MySQL 8.0+ 默认认证机制复杂 (caching\_sha2\_password) |
| **libmariadb** (MariaDB C Connector) | LGPL 协议，支持 MySQL + MariaDB，体积较小 | API 与 libmysqlclient 基本兼容但有细微差异                        |
| **mysqlnd** (PHP 原生)                 | 零外部依赖，纯 PHP 实现                  | 代码量巨大 (\~5万行 C)，深度绑定 Zend，不适合 AOT                      |

**推荐方案**: **libmariadb** (兼容 MySQL 8.0 协议，API 与 libmysqlclient 99% 兼容，LGPL 无许可问题)

### 13.3 依赖安装

```bash
# Linux (Debian/Ubuntu)
apt install libmariadb-dev

# Linux (RHEL/CentOS)
yum install mariadb-connector-c-devel

# macOS
brew install mariadb-connector-c

# Windows (MSYS2)
pacman -S mingw-w64-x86_64-libmariadbclient
# 或下载 https://mariadb.com/downloads/connectors/connectors-data-access/c-connector/
```

### 13.4 完整 API

```php
// ================================================================
// 数据类型常量
// ================================================================
const MYSQL_TYPE_DECIMAL = 0;
const MYSQL_TYPE_TINY = 1;        // TINYINT: 1 byte
const MYSQL_TYPE_SHORT = 2;       // SMALLINT: 2 bytes
const MYSQL_TYPE_LONG = 3;        // INT: 4 bytes
const MYSQL_TYPE_FLOAT = 4;
const MYSQL_TYPE_DOUBLE = 5;
const MYSQL_TYPE_NULL = 6;
const MYSQL_TYPE_TIMESTAMP = 7;
const MYSQL_TYPE_LONGLONG = 8;    // BIGINT: 8 bytes
const MYSQL_TYPE_INT24 = 9;       // MEDIUMINT
const MYSQL_TYPE_DATE = 10;
const MYSQL_TYPE_TIME = 11;
const MYSQL_TYPE_DATETIME = 12;
const MYSQL_TYPE_YEAR = 13;
const MYSQL_TYPE_VARCHAR = 15;
const MYSQL_TYPE_BIT = 16;
const MYSQL_TYPE_JSON = 245;
const MYSQL_TYPE_NEWDECIMAL = 246;
const MYSQL_TYPE_ENUM = 247;
const MYSQL_TYPE_SET = 248;
const MYSQL_TYPE_TINY_BLOB = 249;
const MYSQL_TYPE_MEDIUM_BLOB = 250;
const MYSQL_TYPE_LONG_BLOB = 251;
const MYSQL_TYPE_BLOB = 252;
const MYSQL_TYPE_VAR_STRING = 253;
const MYSQL_TYPE_STRING = 254;
const MYSQL_TYPE_GEOMETRY = 255;

// ================================================================
// 连接选项
// ================================================================
const MYSQL_CLIENT_COMPRESS = 32;       // 使用压缩协议
const MYSQL_CLIENT_SSL = 2048;          // 使用 SSL
const MYSQL_CLIENT_FOUND_ROWS = 2;      // 返回匹配行数(非受影响行数)
const MYSQL_CLIENT_IGNORE_SPACE = 256;  // 忽略函数名后的空格
const MYSQL_CLIENT_INTERACTIVE = 1024;  // 交互式超时

// ================================================================
// fetch 模式
// ================================================================
const MYSQL_ASSOC = 1;   // 关联数组
const MYSQL_NUM = 2;     // 索引数组
const MYSQL_BOTH = 3;    // 关联 + 索引

// ================================================================
// 函数 — 连接管理
// ================================================================

/**
 * mysql_connect(string $host = "localhost", string $user = "",
 *               string $pass = "", string $db = "", int $port = 3306,
 *               string $socket = "", int $flags = 0): resource|false
 *
 * 打开 MySQL 服务器连接。建议使用持久连接（连接池）。
 *
 * @param string $host 主机名，可含端口 "host:port" 或 "host:/path/to/socket"
 * @param string $user 用户名
 * @param string $pass 密码
 * @param string $db 默认数据库名 (可选)
 * @param int $port 端口号 (0=默认3306)
 * @param string $socket Unix socket 路径 (Windows下忽略)
 * @param int $flags 客户端标志组合 (MYSQL_CLIENT_*)
 * @return resource|false 连接资源，失败返回 false
 */
function mysql_connect(string $host = "localhost", string $user = "",
                      string $pass = "", string $db = "", int $port = 3306,
                      string $socket = "", int $flags = 0): resource|false;

/**
 * mysql_close(resource $link = null): bool
 *
 * 关闭 MySQL 连接。$link 为 null 时关闭上一次打开的连接。
 */
function mysql_close(resource $link = null): bool;

/**
 * mysql_select_db(string $dbname, resource $link = null): bool
 *
 * 选择默认数据库。
 */
function mysql_select_db(string $dbname, resource $link = null): bool;

/**
 * mysql_ping(resource $link = null): bool
 *
 * 检查连接是否存活，断开则自动重连。

 * PHP 参考: ext/mysqli/mysqli_nonapi.c:265
 */
function mysql_ping(resource $link = null): bool;

/**
 * mysql_set_charset(string $charset, resource $link = null): bool
 *
 * 设置客户端字符集。如 "utf8mb4", "latin1"。
 * 等价于 SET NAMES charset
 */
function mysql_set_charset(string $charset, resource $link = null): bool;

/**
 * mysql_character_set_name(resource $link = null): string
 *
 * 返回当前连接的字符集名称。
 */
function mysql_character_set_name(resource $link = null): string;

/**
 * mysql_get_host_info(resource $link = null): string
 *
 * 返回连接主机信息。
 */
function mysql_get_host_info(resource $link = null): string;

/**
 * mysql_get_server_info(resource $link = null): string
 *
 * 返回 MySQL 服务器版本号。
 */
function mysql_get_server_info(resource $link = null): string;

/**
 * mysql_get_proto_info(resource $link = null): int
 *
 * 返回连接使用的协议版本号。
 */
function mysql_get_proto_info(resource $link = null): int;

// ================================================================
// 函数 — 查询执行
// ================================================================

/**
 * mysql_query(string $sql, resource $link = null): resource|false
 *
 * 执行 SQL 查询。SELECT/SHOW/DESCRIBE 返回结果集，INSERT/UPDATE/DELETE 等返回 true。
 *
 * @param string $sql SQL 语句, 不建议用分号结尾
 * @param resource $link 连接资源
 * @return resource|false 结果集资源，失败返回 false
 */
function mysql_query(string $sql, resource $link = null): resource|false;

/**
 * mysql_unbuffered_query(string $sql, resource $link = null): resource|false
 *
 * 执行查询但不立即获取所有结果（流式获取，大数据集省内存）。
 * 注意：在读取完所有行前不能执行其他查询。
 */
function mysql_unbuffered_query(string $sql, resource $link = null): resource|false;

// ================================================================
// 函数 — 结果集读取
// ================================================================

/**
 * mysql_fetch_array(resource $result, int $result_type = MYSQL_BOTH): array|false
 *
 * 从结果集中取一行。$result_type 控制返回格式。
 *   MYSQL_ASSOC → ["id"=>1, "name"=>"Alice"]
 *   MYSQL_NUM   → [0=>1, 1=>"Alice"]
 *   MYSQL_BOTH  → 两者都有
 *
 * 读取完所有行返回 false。
 */
function mysql_fetch_array(resource $result, int $result_type = MYSQL_BOTH): array|false;

/**
 * mysql_fetch_assoc(resource $result): array|false
 *
 * 从结果集中取一行关联数组。等价于 mysql_fetch_array($r, MYSQL_ASSOC)
 */
function mysql_fetch_assoc(resource $result): array|false;

/**
 * mysql_fetch_row(resource $result): array|false
 *
 * 从结果集中取一行索引数组。等价于 mysql_fetch_array($r, MYSQL_NUM)
 */
function mysql_fetch_row(resource $result): array|false;

// ================================================================
// 函数 — 结果集元信息
// ================================================================

/**
 * mysql_num_rows(resource $result): int|false
 *
 * SELECT 返回的行数。
 */
function mysql_num_rows(resource $result): int|false;

/**
 * mysql_num_fields(resource $result): int|false
 *
 * 结果集中的列数。
 */
function mysql_num_fields(resource $result): int|false;

/**
 * mysql_field_name(resource $result, int $index): string|false
 *
 * 返回指定列索引的字段名。
 *
 * @example
 * mysql_field_name($r, 0); // "id"
 */
function mysql_field_name(resource $result, int $index): string|false;

/**
 * mysql_field_type(resource $result, int $index): string
 *
 * 返回指定列的 MySQL 类型名。如 "int", "varchar", "datetime"
 */
function mysql_field_type(resource $result, int $index): string;

/**
 * mysql_field_len(resource $result, int $index): int
 *
 * 返回指定列的最大长度。
 */
function mysql_field_len(resource $result, int $index): int;

/**
 * mysql_field_flags(resource $result, int $index): string
 *
 * 返回指定列的标志。如 "not_null primary_key auto_increment"
 */
function mysql_field_flags(resource $result, int $index): string;

/**
 * mysql_fetch_lengths(resource $result): array|false
 *
 * 返回当前行各列值的长度数组（字节数）。
 */
function mysql_fetch_lengths(resource $result): array|false;

/**
 * mysql_field_seek(resource $result, int $offset): bool
 *
 * 设置字段指针到指定偏移（用于 mysql_fetch_field）。
 */
function mysql_field_seek(resource $result, int $offset): bool;

// ================================================================
// 函数 — 影响行数 / 错误处理
// ================================================================

/**
 * mysql_affected_rows(resource $link = null): int
 *
 * 返回上一次 INSERT/UPDATE/DELETE 影响的行数。
 * REPLACE 删除+插入计为 2。
 */
function mysql_affected_rows(resource $link = null): int;

/**
 * mysql_insert_id(resource $link = null): int
 *
 * 返回上一次 INSERT 的 AUTO_INCREMENT ID。
 */
function mysql_insert_id(resource $link = null): int;

/**
 * mysql_errno(resource $link = null): int
 *
 * 返回上一次操作的错误码。
 */
function mysql_errno(resource $link = null): int;

/**
 * mysql_error(resource $link = null): string
 *
 * 返回上一次操作的错误消息。
 */
function mysql_error(resource $link = null): string;

/**
 * mysql_info(resource $link = null): string|false
 *
 * 返回关于最近一条查询的详细信息。
 * 如 "Records: 5  Duplicates: 0  Warnings: 0"
 */
function mysql_info(resource $link = null): string|false;

// ================================================================
// 函数 — 数据转义
// ================================================================

/**
 * mysql_real_escape_string(string $str, resource $link = null): string|false
 *
 * 转义 SQL 特殊字符（\x00, \n, \r, \, ', ", \x1a）防注入。
 * 必须使用当前连接字符集。
 */
function mysql_real_escape_string(string $str, resource $link = null): string|false;

/**
 * mysql_escape_string(string $str): string
 *
 * 转义 SQL 特殊字符（不使用连接字符集，不推荐）。
 * 推荐使用 mysql_real_escape_string。
 */
function mysql_escape_string(string $str): string;

// ================================================================
// 函数 — 事务
// ================================================================

/**
 * mysql_begin_transaction(resource $link, int $flags = 0): bool
 *
 * 开始事务。等价于 mysql_query("START TRANSACTION").
 *
 * @param int $flags MYSQL_TRANS_START_WITH_CONSISTENT_SNAPSHOT (1)
 *                   MYSQL_TRANS_START_READ_WRITE (2)
 *                   MYSQL_TRANS_START_READ_ONLY (4)
 */
function mysql_begin_transaction(resource $link, int $flags = 0): bool;

/**
 * mysql_commit(resource $link, int $flags = 0): bool
 *
 * 提交事务。
 */
function mysql_commit(resource $link, int $flags = 0): bool;

/**
 * mysql_rollback(resource $link, int $flags = 0): bool
 *
 * 回滚事务。
 */
function mysql_rollback(resource $link, int $flags = 0): bool;

// ================================================================
// 函数 — 连接池 / 清理
// ================================================================

/**
 * mysql_free_result(resource $result): bool
 *
 * 释放结果集内存。
 */
function mysql_free_result(resource $result): bool;

/**
 * mysql_data_seek(resource $result, int $row): bool
 *
 * 移动结果集指针到指定行。
 */
function mysql_data_seek(resource $result, int $row): bool;
```

### 13.5 典型使用流程

```php
// 1. 连接
$db = mysql_connect("localhost", "root", "secret");
mysql_select_db("mydb", $db);
mysql_set_charset("utf8mb4", $db);

// 2. 查询 (SELECT)
$result = mysql_query("SELECT id, name, email FROM users WHERE age > 18", $db);
echo mysql_num_rows($result);  // 行数

// 3. 遍历结果
while ($row = mysql_fetch_assoc($result)) {
    echo $row["id"] . ": " . $row["name"] . " - " . $row["email"] . "\n";
}
mysql_free_result($result);

// 4. 写入 (INSERT/UPDATE/DELETE)
$name = mysql_real_escape_string("O'Brien", $db);
mysql_query("INSERT INTO users (name) VALUES ('$name')", $db);
echo mysql_insert_id($db);     // 自增 ID
echo mysql_affected_rows($db); // 影响行数

// 5. 事务
mysql_begin_transaction($db);
mysql_query("UPDATE accounts SET balance = balance - 100 WHERE id = 1", $db);
mysql_query("UPDATE accounts SET balance = balance + 100 WHERE id = 2", $db);
mysql_commit($db);  // 或 mysql_rollback($db);

// 6. 关闭
mysql_close($db);
```

### 13.6 实现要点

```c
// ext/mysql/src/mysql_wrap.c
#include <mysql.h>  // 或 <mariadb/mysql.h>

// 存储最后连接（用于 $link=null 默认连接）
static MYSQL *_mysql_last_link = NULL;

// 结果集包装结构（返回给 PHP 端）
typedef struct {
    MYSQL_RES *res;
    int num_fields;
    unsigned long *lengths;
    MYSQL_FIELD *fields;
} tphp_mysql_result;

// ── mysql_connect ──
MYSQL* tphp_fn_mysql_connect(t_string host, t_string user, t_string pass,
                              t_string db, t_int port, t_string socket, t_int flags) {
    MYSQL *conn = mysql_init(NULL);
    if (!conn) return NULL;
    
    // 设置选项
    if (flags & MYSQL_CLIENT_COMPRESS)
        mysql_options(conn, MYSQL_OPT_COMPRESS, NULL);
    
    const char *h = (host.length > 0) ? STR_PTR(host) : "localhost";
    const char *u = (user.length > 0) ? STR_PTR(user) : "";
    const char *p = (pass.length > 0) ? STR_PTR(pass) : "";
    const char *d = (db.length > 0) ? STR_PTR(db) : NULL;
    unsigned int pn = (port > 0 && port < 65536) ? (unsigned int)port : 3306;
    
    if (!mysql_real_connect(conn, h, u, p, d, pn,
        (socket.length > 0) ? STR_PTR(socket) : NULL, (unsigned long)flags)) {
        mysql_close(conn);
        return NULL;
    }
    
    _mysql_last_link = conn;
    tphp_rt_register(conn, 5);  // type 5 = mysql_close on cleanup
    return conn;
}

// ── mysql_fetch_assoc ──
t_array* tphp_fn_mysql_fetch_assoc(MYSQL_RES *res) {
    if (!res) return NULL;
    
    MYSQL_ROW row = mysql_fetch_row(res);
    if (!row) return NULL;
    
    unsigned long *lengths = mysql_fetch_lengths(res);
    unsigned int nf = mysql_num_fields(res);
    MYSQL_FIELD *fields = mysql_fetch_fields(res);
    
    t_array *arr = tphp_fn_arr_create((int)nf);
    tphp_rt_register(arr, 1);
    
    for (unsigned int i = 0; i < nf; i++) {
        t_string key = tphp_rt_str_dup(
            (t_string){fields[i].name, (int)strlen(fields[i].name)});
        
        if (row[i] == NULL) {
            arr = tphp_fn_arr_set_str(arr, key, VAR_NULL());
        } else {
            t_string val = tphp_rt_str_dup(
                (t_string){row[i], (int)lengths[i]});
            arr = tphp_fn_arr_set_str(arr, key, VAR_STRING(val));
        }
    }
    
    return arr;
}
```

### 13.7 编译链接

```bash
# Linux (libmariadb)
gcc ... $(mysql_config --cflags) $(mysql_config --libs)
# 或手动: -I/usr/include/mariadb -lmariadb

# macOS (Homebrew)
gcc ... -I/usr/local/opt/mariadb-connector-c/include \
        -L/usr/local/opt/mariadb-connector-c/lib -lmariadb

# Windows (MSYS2)
gcc ... -I/c/msys64/mingw64/include/mariadb \
        -L/c/msys64/mingw64/lib -lmariadb

# 静态捆绑 (推荐用于 PHAR 分发)
# 下载 mariadb-connector-c 源码，编译为 libmariadb.a
git clone https://github.com/mariadb-corporation/mariadb-connector-c
cd mariadb-connector-c && cmake . && make
# 得到 libmariadb.a (~3MB)
```

### 13.8 测试

```php
$db = mysql_connect("127.0.0.1", "root", "test123");
assert(mysql_ping($db) === true);

mysql_select_db("test", $db);
mysql_set_charset("utf8mb4", $db);

// 创建表
mysql_query("CREATE TABLE IF NOT EXISTS tmp (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(100))", $db);

// 插入
$escaped = mysql_real_escape_string("O'Brien", $db);
mysql_query("INSERT INTO tmp (name) VALUES ('$escaped')", $db);
echo mysql_insert_id($db);  // > 0
echo mysql_affected_rows($db);  // 1

// 查询
$res = mysql_query("SELECT * FROM tmp", $db);
echo mysql_num_rows($res);  // >= 1
echo mysql_num_fields($res);  // 2

while ($row = mysql_fetch_assoc($res)) {
    echo $row['id'] . '=' . $row['name'] . "\n";
}
mysql_free_result($res);

// 事务
mysql_begin_transaction($db);
mysql_query("UPDATE tmp SET name = 'Alice' WHERE id = 1", $db);
mysql_rollback($db);
// name 仍然是 O'Brien

// 清理
mysql_query("DROP TABLE tmp", $db);
mysql_close($db);
```

***

## 14. PDO ✅ 已完成

> 已实现于 ext/pdo/pdo.h + ext/pdo/src/pdo.php（SQLite 驱动，内置 amalgamation 3.46.0），文档见 FUNCTIONS.md "pdo — SQLite 数据库" 章节。

***

## 15. GD — 图像处理

| 属性         | 值                                      |
| ---------- | -------------------------------------- |
| **外部依赖**   | libgd + libpng + libjpeg (\~2MB 静态库)   |
| **预估行数**   | \~600 行 C 包装                           |
| **PHP 参考** | `ext/gd/gd.c` (\~200KB) + `libgd/gd.c` |
| **难度**     | ⭐⭐⭐⭐⭐                                  |

### 15.1 为什么 GD 可以做

之前标记为"不适合 AOT"也是过于保守。libgd 本身就是纯 C 库（MIT 协议），libpng 和 libjpeg 也是标准 C 库。三者都可以静态编译捆绑进 PHAR。

| 组件               | 依赖          | 捆绑体积           |
| ---------------- | ----------- | -------------- |
| libgd            | 无 (自包含)     | \~300KB        |
| libpng           | zlib (已有)   | \~200KB        |
| libjpeg          | 无           | \~300KB        |
| libfreetype (可选) | 无           | \~500KB (文字渲染) |
| **合计最小集**        | gd+png+jpeg | **\~800KB**    |

### 15.2 推荐参考库

| 库                            | 说明              | 链接                                     |
| ---------------------------- | --------------- | -------------------------------------- |
| **libgd**                    | 官方 C 图像库 (MIT)  | github.com/libgd/libgd                 |
| **PHP 源码** **`ext/gd/gd.c`** | 完整包装层           | \~200KB                                |
| **libpng**                   | PNG 编解码         | libpng.org                             |
| **libjpeg-turbo**            | JPEG 编解码 (性能更好) | github.com/libjpeg-turbo/libjpeg-turbo |

### 15.3 依赖安装

```bash
# Linux
apt install libgd-dev libpng-dev libjpeg-dev

# macOS
brew install gd libpng jpeg

# Windows (MSYS2)
pacman -S mingw-w64-x86_64-gd mingw-w64-x86_64-libpng mingw-w64-x86_64-libjpeg-turbo
```

### 15.4 完整 API (20 函数)

```php
// ── 创建 / 销毁 ──

/**
 * imagecreate(int $w, int $h): resource
 *
 * 创建调色板图像 (256 色, GIF 风格)。
 */
function imagecreate(int $w, int $h): resource;

/**
 * imagecreatetruecolor(int $w, int $h): resource
 *
 * 创建真彩色图像 (16M 色, PNG/JPEG 风格)。
 */
function imagecreatetruecolor(int $w, int $h): resource;

/**
 * imagedestroy(resource $im): bool
 *
 * 销毁图像，释放内存。
 */
function imagedestroy(resource $im): bool;

// ── 从文件加载 ──

/**
 * imagecreatefrompng(string $path): resource
 *
 * 从 PNG 文件创建图像。
 */
function imagecreatefrompng(string $path): resource;

/**
 * imagecreatefromjpeg(string $path): resource
 *
 * 从 JPEG 文件创建图像。
 */
function imagecreatefromjpeg(string $path): resource;

/**
 * imagecreatefromgif(string $path): resource
 *
 * 从 GIF 文件创建图像。
 */
function imagecreatefromgif(string $path): resource;

/**
 * imagecreatefromstring(string $data): resource
 *
 * 从字符串数据检测类型自动加载。
 */
function imagecreatefromstring(string $data): resource;

// ── 输出到文件 / 浏览器 ──

/**
 * imagepng(resource $im, string $path = "", int $quality = -1): bool
 *
 * 输出 PNG 图像到文件。
 * quality: 0-9 (PNG 压缩级别, 0=无压缩, -1=默认6)
 */
function imagepng(resource $im, string $path = "", int $quality = -1): bool;

/**
 * imagejpeg(resource $im, string $path = "", int $quality = -1): bool
 *
 * 输出 JPEG 图像到文件。
 * quality: 0-100 (默认 -1 即 75)
 */
function imagejpeg(resource $im, string $path = "", int $quality = -1): bool;

/**
 * imagegif(resource $im, string $path = ""): bool
 *
 * 输出 GIF 图像到文件。
 */
function imagegif(resource $im, string $path = ""): bool;

/**
 * imagepng_stdout(resource $im): void
 *
 * 直接输出 PNG 到 stdout (用于 HTTP 响应)。
 */
function imagepng_stdout(resource $im): void;

/**
 * imagejpeg_stdout(resource $im, int $quality = -1): void
 *
 * 直接输出 JPEG 到 stdout (用于 HTTP 响应)。
 */
function imagejpeg_stdout(resource $im, int $quality = -1): void;

// ── 颜色分配 ──

/**
 * imagecolorallocate(resource $im, int $r, int $g, int $b): int
 *
 * 为图像分配颜色。第一个分配的颜色自动成为背景色。
 * 返回颜色索引 (调色板) 或颜色值 (真彩色)。
 */
function imagecolorallocate(resource $im, int $r, int $g, int $b): int;

/**
 * imagecolorallocatealpha(resource $im, int $r, int $g, int $b, int $alpha): int
 *
 * 分配带透明度的颜色。alpha: 0=不透明, 127=完全透明。
 */
function imagecolorallocatealpha(resource $im, int $r, int $g, int $b, int $alpha): int;

// ── 图形绘制 ──

/**
 * imageline(resource $im, int $x1, int $y1, int $x2, int $y2, int $color): bool
 *
 * 画线。
 */
function imageline(resource $im, int $x1, int $y1, int $x2, int $y2, int $color): bool;

/**
 * imagerectangle(resource $im, int $x1, int $y1, int $x2, int $y2, int $color): bool
 *
 * 画矩形边框。
 */
function imagerectangle(resource $im, int $x1, int $y1, int $x2, int $y2, int $color): bool;

/**
 * imagefilledrectangle(resource $im, int $x1, int $y1, int $x2, int $y2, int $color): bool
 *
 * 画填充矩形。
 */
function imagefilledrectangle(resource $im, int $x1, int $y1, int $x2, int $y2, int $color): bool;

/**
 * imageellipse(resource $im, int $cx, int $cy, int $w, int $h, int $color): bool
 *
 * 画椭圆边框。
 */
function imageellipse(resource $im, int $cx, int $cy, int $w, int $h, int $color): bool;

/**
 * imagefilledellipse(resource $im, int $cx, int $cy, int $w, int $h, int $color): bool
 *
 * 画填充椭圆。
 */
function imagefilledellipse(resource $im, int $cx, int $cy, int $w, int $h, int $color): bool;

/**
 * imagepolygon(resource $im, array $points, int $n, int $color): bool
 *
 * 画多边形。points: [x0, y0, x1, y1, ...]
 */
function imagepolygon(resource $im, array $points, int $n, int $color): bool;

/**
 * imagesetpixel(resource $im, int $x, int $y, int $color): bool
 *
 * 画单个像素点。
 */
function imagesetpixel(resource $im, int $x, int $y, int $color): bool;

// ── 图像信息 ──

/**
 * imagesx(resource $im): int
 *
 * 返回图像宽度。
 */
function imagesx(resource $im): int;

/**
 * imagesy(resource $im): int
 *
 * 返回图像高度。
 */
function imagesy(resource $im): int;

// ── 变换 ──

/**
 * imagecopy(resource $dst, resource $src, int $dx, int $dy, int $sx, int $sy, int $sw, int $sh): bool
 *
 * 复制图像区域。
 */
function imagecopy(resource $dst, resource $src, int $dx, int $dy, int $sx, int $sy, int $sw, int $sh): bool;

/**
 * imagecopyresized(resource $dst, resource $src,
 *     int $dx, int $dy, int $sx, int $sy, int $dw, int $dh, int $sw, int $sh): bool
 *
 * 复制并缩放图像（最近邻插值）。
 */
function imagecopyresized(resource $dst, resource $src,
    int $dx, int $dy, int $sx, int $sy, int $dw, int $dh, int $sw, int $sh): bool;

/**
 * imagecopyresampled(resource $dst, resource $src,
 *     int $dx, int $dy, int $sx, int $sy, int $dw, int $dh, int $sw, int $sh): bool
 *
 * 复制并缩放图像（双线性插值，比 resized 质量好）。
 */
function imagecopyresampled(resource $dst, resource $src,
    int $dx, int $dy, int $sx, int $sy, int $dw, int $dh, int $sw, int $sh): bool;

/**
 * imagerotate(resource $im, float $angle, int $bgcolor): resource
 *
 * 旋转图像。$angle 为逆时针角度。
 */
function imagerotate(resource $im, float $angle, int $bgcolor): resource;

// ── 文字 (可选, 需 libfreetype) ──

/**
 * imagettftext(resource $im, float $size, float $angle,
 *     int $x, int $y, int $color, string $fontfile, string $text): array|false
 *
 * 用 TrueType 字体写文字。返回包围盒: [x1,y1, x2,y2, x3,y3, x4,y4]
 */
function imagettftext(resource $im, float $size, float $angle,
    int $x, int $y, int $color, string $fontfile, string $text): array|false;

/**
 * imagestring(resource $im, int $font, int $x, int $y, string $s, int $color): bool
 *
 * 用内置字体写文字（不需要 freetype）。
 */
function imagestring(resource $im, int $font, int $x, int $y, string $s, int $color): bool;
```

### 15.5 典型用法

```php
// 1. 创建缩略图
$src = imagecreatefromjpeg("photo.jpg");
$w = imagesx($src); $h = imagesy($src);
$thumb = imagecreatetruecolor(150, 150);
imagecopyresampled($thumb, $src, 0, 0, 0, 0, 150, 150, $w, $h);
imagejpeg($thumb, "thumb.jpg", 85);
imagedestroy($src);
imagedestroy($thumb);

// 2. 动态生成验证码图片
$img = imagecreatetruecolor(100, 40);
$bg  = imagecolorallocate($img, 255, 255, 255);
$red = imagecolorallocate($img, 255, 0, 0);
imagefilledrectangle($img, 0, 0, 100, 40, $bg);
imagestring($img, 5, 30, 10, "AB12", $red);
header("Content-Type: image/png");
imagepng($img);
imagedestroy($img);

// 3. 拼图
$bg = imagecreatetruecolor(200, 100);
$pic1 = imagecreatefrompng("a.png");
$pic2 = imagecreatefrompng("b.png");
imagecopy($bg, $pic1, 0, 0, 0, 0, 100, 100);
imagecopy($bg, $pic2, 100, 0, 0, 0, 100, 100);
imagepng($bg, "merged.png");
```

### 15.6 实现要点

```c
// ext/gd/src/gd_wrap.c
#include "gd.h"

gdImagePtr tphp_fn_imagecreatefromjpeg(t_string path) {
    char buf[1024];
    snprintf(buf, 1024, "%.*s", path.length, STR_PTR(path));
    FILE *f = fopen(buf, "rb");
    if (!f) return NULL;
    gdImagePtr im = gdImageCreateFromJpeg(f);
    fclose(f);
    tphp_rt_register(im, 7);  // type 7 = gdImageDestroy on cleanup
    return im;
}

void tphp_fn_imagejpeg(gdImagePtr im, t_string path, t_int quality) {
    char buf[1024];
    snprintf(buf, 1024, "%.*s", path.length, STR_PTR(path));
    FILE *f = fopen(buf, "wb");
    if (!f) return;
    gdImageJpeg(im, f, (int)quality);
    fclose(f);
}
```

***

## 实现优先级

| #  | 扩展             | 行数     | 难度         | 依赖             | 函数数 | 典型耗时  |
| -- | -------------- | ------ | ---------- | -------------- | --- | ----- |
| 1  | bcrypt         | \~350  | ⭐⭐ ✅ 已完成   | 无              | 2   | ✅ 已完成 |
| 2  | filter\_var    | \~400  | ⭐⭐ ✅ 已完成   | 无              | 4   | ✅ 已完成 |
| 3  | calendar       | \~1000 | ⭐⭐⭐ ✅ 已完成 | 无              | 16  | ✅ 已完成 |
| 4  | zlib           | \~200  | ⭐⭐⭐        | zlib           | 6   | 半天    |
| 5  | PCRE2 preg\_\* | \~1500 | ⭐⭐⭐⭐ ✅ 已完成 | vlang pcre VM  | 8   | ✅ 已完成 |
| 6  | SQLite         | \~500  | ⭐⭐⭐⭐       | sqlite3        | 8   | 1-2 天 |
| 7  | cURL           | \~300  | ⭐⭐⭐⭐       | libcurl        | 8   | 2-3 天 |
| 8  | OpenSSL        | \~500  | ⭐⭐⭐⭐⭐      | openssl        | 8   | 3-5 天 |
| 9  | fileinfo ✅    | \~200  | ⭐⭐⭐        | 内置魔数表       | 6   | 1 天   |
| 10 | iconv          | \~500  | ⭐⭐⭐ ✅ 已完成 | libiconv/系统    | 8   | ✅ 已完成 |
| 11 | exif           | \~800  | ⭐⭐⭐ ✅ 已完成 | 无(纯解析)         | 4   | ✅ 已完成 |
| 12 | ZIP            | \~400  | ⭐⭐⭐⭐       | libzip+zlib    | 12  | 2-3 天 |
| 13 | MySQL          | \~600  | ⭐⭐⭐⭐⭐      | libmariadb     | 16  | 3-5 天 |
| 14 | PDO            | \~700  | ⭐⭐⭐⭐⭐ ✅ 已完成 | sqlite3 (内置 amalgamation) | 16  | ✅ 已完成 |
| 15 | GD             | \~600  | ⭐⭐⭐⭐⭐      | libgd+png+jpeg | 20  | 3-5 天 |
