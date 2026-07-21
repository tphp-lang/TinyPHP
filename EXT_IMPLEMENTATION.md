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
| 6  | [SQLite](#6-sqlite) ✅ 已完成 | ⭐⭐⭐⭐  | 内置 SQLite 3.46.0 amalgamation | 11  |
| 7  | [cURL](#7-curl)              | ⭐⭐⭐⭐  | libcurl        | 8   |
| 8  | [OpenSSL](#8-openssl) ✅ 已完成 | ⭐⭐⭐⭐⭐ | 内置 mbedTLS 3.6.6 源码（静态编译，零运行时依赖） | 21  |
| 9  | [fileinfo](#9-fileinfo) ✅   | ⭐⭐⭐   | 内置魔数表       | 4   |
| 10 | [iconv](#10-iconv) ✅ 已完成 | ⭐⭐⭐   | libiconv/系统    | 8   |
| 11 | [exif](#11-exif) ✅ 已完成     | ⭐⭐⭐   | 无(纯解析)         | 6   |
| 12 | [ZIP](#12-zip) ✅ 已完成(内置)  | ⭐⭐⭐⭐  | 内置 zlib (手写ZIP) | 18  |
| 13 | [MySQL](#13-mysql) ✅ 已完成 | ⭐⭐⭐⭐⭐ | 无（纯 C 协议实现） | 0（driver 复用 PDO API）  |
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

> 已实现于 ext/sqlite3/sqlite3.h + ext/sqlite3/src/sqlite3.php（函数式 API，内置 SQLite 3.46.0 amalgamation 静态编译），文档见 FUNCTIONS.md "sqlite3 — SQLite 数据库" 章节。

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

## 13. MySQL ✅ 已完成

> 已实现于 `ext/pdo_mysql/pdo_mysql.h`（约 1644 行 C 代码，纯 MySQL 协议实现，零外部依赖）。
> 文档见 FUNCTIONS.md "pdo — MySQL 数据库" 章节。
>
> **设计目标**：纯 C 实现 MySQL 客户端协议，**不依赖 libmysqlclient / libmariadb / mysqlnd**。
> **认证**：`mysql_native_password`（SHA1，内置 FIPS 180-4 实现，无外部加密库依赖）。
> **协议**：MySQL 文本协议（COM_QUERY），预处理用文本协议模拟（客户端拼接完整 SQL）。
> **集成方式**：通过 `pdo_driver_t` 函数指针表暴露给 PDO 类，PHP 用户层 API 完全不变。
> **不支持**：SSL/TLS、Unix socket、多语句、`caching_sha2_password` 认证、二进制预处理协议。
>
> **协议同步机制**（`active_stmt`）：MySQL 协议要求结果集完整消费（直到 EOF packet），否则连接不同步。
> 在 `mysql_conn_t` 中维护 `active_stmt` 字段跟踪当前有未消费结果集的 stmt，
> 在新查询发送前（`step`/`exec`）自动 drain 旧 stmt 的剩余结果集。

| 属性         | 值                                           |
| ---------- | ------------------------------------------- |
| **外部依赖**   | 无（纯 C 协议实现，复用 ext/stream 的 socket 抽象） |
| **实现行数**   | \~1644 行 C 代码（单文件 `pdo_mysql.h`） |
| **PHP 参考** | `ext/mysqlnd/`（协议实现参考）+ V `vlib/db/mysql/` |
| **难度**     | ⭐⭐⭐⭐⭐                                       |

### 13.1 推荐参考库

| 库                                | 说明                                | 链接                                                            |
| -------------------------------- | --------------------------------- | ------------------------------------------------------------- |
| **MySQL 协议文档**                   | 客户端-服务端协议（核心参考）                 | dev.mysql.com/doc/dev/mysql-server/latest/PAGE\_PROTOCOL.html |
| **PHP 源码** **`ext/mysqlnd/`**    | PHP Native Driver（纯协议实现参考）      | `mysqlnd/` 目录                                                 |
| **V 源码** **`vlib/db/mysql/`**     | V 语言 MySQL 纯协议实现（架构参考）          | `mysql.v`                                                     |
| **SHA1 (FIPS 180-4)**             | mysql_native_password 认证所需         | csrc.nist.gov/publications/fips/fips180-4.pdf                 |

### 13.2 设计说明（AOT 类型安全）

- 通过 `pdo_driver_t` 函数指针表暴露，PHP 层使用 `new PDO("mysql:host=...;port=...;dbname=...")`
- 数据库句柄（`mysql_conn_t*`）和语句句柄（`mysql_stmt_t*`）以 `int` (`t_int`=`int64_t`) 存储在 PHP 变量中
- 通过 `_PDO_DRV_FROM_INT` / `_PDO_DBH_FROM_INT` / `_PDO_STMT_FROM_INT` 宏在 C 层转换回指针
- 预处理用文本协议模拟：客户端在 `step` 首次调用时将 `?` 占位符替换为转义后的参数值，拼接成完整 SQL 发送
- MySQL 文本协议所有列值都是字符串，PDO 层用 `_fetchColumnInt`（支持 TEXT 列 + `strtoll` 转换）适配
- 错误抛 `Exception`（`tp_throw_ex`），错误消息由 driver 的 `errmsg` 提供（保存在 `mysql_conn_t.error_msg`）
- `quote` 转义 `\x00` `\n` `\r` `\\` `'` `"` `\x1a` 字符，结果带单引号包裹
- 认证算法：`SHA1(password) XOR SHA1(salt + SHA1(SHA1(password)))`（salt 在前）

### 13.3 实现要点

```c
// ext/pdo_mysql/pdo_mysql.h（约 1644 行，单文件）

// ── 协议结构 ──
typedef struct {
    int fd;                  // socket fd（来自 ext/stream）
    int sequence_id;         // MySQL packet sequence id（每个命令重置为 0）
    int error_code;
    char error_msg[512];
    int64_t last_insert_id;
    int64_t affected_rows;
    char server_version[64];
    int capabilities;
    int charset;
    void* active_stmt;       // 当前有未消费结果集的 mysql_stmt_t*（防协议不同步）
} mysql_conn_t;

typedef struct {
    mysql_conn_t* conn;
    char* sql_template;      // SQL 模板（带 ? 占位符）
    int num_params;
    int* param_types;        // 0=null, 1=int, 2=text, 3=blob
    int64_t* param_ints;
    char** param_texts;
    int* param_text_lens;
    int num_columns;
    char** column_names;
    int* column_name_lens;
    int eof_reached;
    int executed;
    char** row_values;       // 当前行各列字符串值（借用，下次 step 失效）
    int* row_value_lens;
    int row_value_count;
} mysql_stmt_t;

// ── 关键流程 ──
// 1. open: TCP 连接 → 接收 Handshake V10 → 发 Handshake Response 41 → 认证 → OK packet
// 2. exec/step: 拼接 SQL → 发 COM_QUERY → 读 Resultset Header → Column Definition ×N → EOF → Row ×N → EOF
// 3. active_stmt 机制：新查询发送前 drain 旧 stmt 剩余结果集（防止协议不同步）
// 4. mysql_native_password: SHA1(password) XOR SHA1(salt + SHA1(SHA1(password)))

// ── driver 注册（constructor 自动调用）──
__attribute__((constructor)) static void _pdo_mysql_register(void) {
    pdo_register_driver(&_pdo_mysql_driver);
}
```

### 13.4 协议同步问题（已解决）

**问题**：生成的 C 代码赋值模式为 `tmp = PDO::query(...); tp_obj_release(st); st = tmp;`——
新查询在旧 statement finalize 之前就发送了，导致 MySQL 连接协议不同步，引发堆崩溃（`STATUS_HEAP_CORRUPTION 0xC0000374`）。

**解决方案**：在 `mysql_conn_t` 添加 `active_stmt` 字段，跟踪当前有未消费结果集的 stmt。
- 在 `_pdo_mysql_step` 和 `_pdo_mysql_exec` 发送新查询前，检查并 drain 旧 stmt 的剩余结果集（`_mysql_consume_resultset`）
- 在 step 首次成功后设置 `active_stmt = s`
- 在 step EOF / finalize / reset 中清除 `active_stmt`（如果指向本 stmt）

### 13.5 典型用法

```php
#import pdo
#import pdo_mysql

// 连接 MySQL 8.0
$pdo = new PDO("mysql:host=127.0.0.1;port=3306;dbname=test", "root", "secret");

// 建表
$pdo->exec("CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    age INT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// 预处理 + 位置绑定
$stmt = $pdo->prepare("INSERT INTO users (name, age) VALUES (?, ?)");
$stmt->bindValueStr(1, "Alice");
$stmt->bindValueInt(2, 30);
$stmt->execute();
echo $pdo->lastInsertId();  // 自增 ID

// 查询（FETCH_ASSOC）
$stmt = $pdo->query("SELECT * FROM users", PDO::FETCH_ASSOC);
while (!$stmt->fetchDone()) {
    $row = $stmt->fetch();
    echo $row["name"] . "\n";
}

// fetchColumnInt 适用于 COUNT(*)（MySQL 文本协议 + 自动转换）
$count = $pdo->query("SELECT COUNT(*) FROM users", PDO::FETCH_NUM)->fetchColumnInt(0);

// 事务
$pdo->beginTransaction();
$pdo->exec("UPDATE users SET age = 31 WHERE id = 1");
$pdo->commit();

// 错误处理（可 try-catch）
try {
    $pdo->exec("SELECT * FROM nonexistent_table");
} catch (Exception $e) {
    echo $e->getMessage();  // "PDO::exec: Table 'test.nonexistent_table' doesn't exist"
}
```

> 测试: `test/pdo_mysql/pdo_mysql_integration.php`（11 节覆盖连接/建库建表/插入/
> FETCH_ASSOC 查询/位置绑定 Int+Str/COUNT(*) fetchColumnInt/事务/错误处理/quote/清理）
> 全部通过（MySQL 8.0.12，root/root，port 3306）。

***

## 14. PDO ✅ 已完成

> 已实现于 ext/pdo/pdo.h + ext/pdo/src/pdo.php（SQLite 驱动，内置 amalgamation 3.46.0），文档见 FUNCTIONS.md "pdo — SQLite 数据库" 章节。
>
> **Driver 抽象架构**（已完成）：ext/pdo/pdo_driver.h 定义 `pdo_driver_t` 函数指针表接口，SQLite 驱动实现该接口并通过 constructor 自动注册。PDO/PDOStatement 类所有 C 调用通过 `pdo_driver_*` 包装函数分发，添加 MySQL/PostgreSQL 驱动只需实现 driver 接口，PHP 类无需修改。
>
> **已实现驱动**：SQLite（内置 amalgamation 3.46.0）、MySQL（纯 C 协议实现，见 [§13](#13-mysql-✅-已完成)）。

### 驱动抽象架构

```
ext/pdo/
  pdo_driver.h    — 公共接口（pdo_driver_t 结构体 + 注册/查找 + 包装函数）
  pdo.h           — SQLite 驱动实现（填充函数指针表 + 自动注册）
  src/pdo.php     — PDO/PDOStatement 类（通过 driver 指针分发）

ext/pdo_mysql/   — MySQL 驱动（✅ 已实现，纯 C 协议，见 §13）
ext/pdo_pgsql/   — PostgreSQL 驱动（未来扩展）
```

**用户使用方式**：
```php
#import pdo          // 只用 SQLite
#import pdo_mysql    // 只用 MySQL
#import pdo; #import pdo_mysql  // 两个都链接，运行时按 DSN 分发

$pdo = new PDO("sqlite:memory:");                // 自动查找 sqlite driver
$pdo = new PDO("mysql:host=...", $user, $pass);  // 自动查找 mysql driver
```

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
| 6  | SQLite ✅      | \~500  | ⭐⭐⭐⭐ ✅ 已完成 | sqlite3 (内置 amalgamation) | 11  | ✅ 已完成 |
| 7  | cURL           | \~300  | ⭐⭐⭐⭐       | libcurl        | 8   | 2-3 天 |
| 8  | OpenSSL        | \~500  | ⭐⭐⭐⭐⭐      | openssl        | 8   | 3-5 天 |
| 9  | fileinfo ✅    | \~200  | ⭐⭐⭐        | 内置魔数表       | 6   | 1 天   |
| 10 | iconv          | \~500  | ⭐⭐⭐ ✅ 已完成 | libiconv/系统    | 8   | ✅ 已完成 |
| 11 | exif           | \~800  | ⭐⭐⭐ ✅ 已完成 | 无(纯解析)         | 4   | ✅ 已完成 |
| 12 | ZIP            | \~400  | ⭐⭐⭐⭐       | libzip+zlib    | 12  | 2-3 天 |
| 13 | MySQL          | \~600  | ⭐⭐⭐⭐⭐      | libmariadb     | 16  | 3-5 天 |
| 14 | PDO            | \~700  | ⭐⭐⭐⭐⭐ ✅ 已完成 | sqlite3 (内置 amalgamation) | 16  | ✅ 已完成 |
| 15 | GD             | \~600  | ⭐⭐⭐⭐⭐      | libgd+png+jpeg | 20  | 3-5 天 |
