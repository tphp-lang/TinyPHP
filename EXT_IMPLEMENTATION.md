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
| 5  | [stream](#5-stream) ✅ 已完成 | ⭐⭐⭐⭐  | winsock2(Windows)/libc(POSIX) | 15  |
| 6  | [SQLite](#6-sqlite)          | ⭐⭐⭐⭐  | sqlite3        | 6   |
| 7  | [cURL](#7-curl)              | ⭐⭐⭐⭐  | libcurl        | 8   |
| 8  | [OpenSSL](#8-openssl) ⏸️ 暂停 | ⭐⭐⭐⭐⭐ | OpenSSL 3.0.21（TCC 链接不兼容，待定） | 21  |
| 9  | [fileinfo](#9-fileinfo) ✅   | ⭐⭐⭐   | 内置魔数表       | 4   |
| 10 | [iconv](#10-iconv) ✅ 已完成 | ⭐⭐⭐   | libiconv/系统    | 8   |
| 11 | [exif](#11-exif) ✅ 已完成     | ⭐⭐⭐   | 无(纯解析)         | 6   |
| 12 | [ZIP](#12-zip) ✅ 已完成(内置)  | ⭐⭐⭐⭐  | 内置 zlib (手写ZIP) | 18  |
| 13 | [MySQL](#13-mysql)           | ⭐⭐⭐⭐⭐ | libmysqlclient | 16  |
| 14 | [PDO](#14-pdo)               | ⭐⭐⭐⭐⭐ | 每驱动各异          | 10  |
| 15 | [GD](#15-gd)                 | ⭐⭐⭐⭐⭐ | libgd+png+jpeg | 20  |

***

## 2. filter\_var ✅ 已完成

> 已实现于 `include/filter.h`（内置功能，非 ext/ 扩展），文档见 FUNCTIONS.md "ext/filter — 过滤器" 章节。

***

## 3. calendar ✅ 已完成

> **实现状态**: 已完成纯 tphp 实现于 `ext/calendar/src/calendar.php`，无 C 代码、无外部依赖。
> 基于 PHP ext/calendar 的 C 算法翻译为 tphp，所有日历转换基于儒略日 (Julian Day Number)。
> **AOT 错误处理**: 无效日期/超出范围 → `throw Exception`（不静默返回 0 或 "0/0/0"）。
> JD→日历转换返回 `array ["month","day","year"]`（全 int），不返回 PHP 的 "m/d/y" 字符串。
> 内部 helper 返回哨兵值 (0/`["year"=>0,...]`)，公共 API 检查后 throw — 异常不吞没。
> 犹太历 64 位直接算术（无需 C 源码的 32 位拆分溢出保护）。
> 测试: `test/calendar/test_calendar.php` (162 项检查) 全部通过。

### 推荐参考库

| 库                                   | 说明                             | 链接                                                     |
| ----------------------------------- | ------------------------------ | ------------------------------------------------------ |
| **PHP 源码** **`ext/calendar/`**      | sdncal.h + calendar.c，自包含 C 算法 | `ext/calendar/sdncal.h` (400行) + `calendar.c`          |
| **Meeus "Astronomical Algorithms"** | 日历算法圣经，SDN 体系来源                | 书籍 ISBN 978-0943396613                                 |
| **GNU** **`cal`**                   | Unix cal 命令源码                  | git.savannah.gnu.org/cgit/coreutils.git/tree/src/cal.c |
| **`libzahl`** (calendar module)     | 纯 C 日历库                        | github.com/maandree/libzahl                            |

### 完整 API

```php
// ================================================================
// 日历类型常量
// ================================================================
const CAL_GREGORIAN = 0;   // 公历 (Gregorian)
const CAL_JULIAN = 1;      // 儒略历 (Julian)
const CAL_JEWISH = 2;      // 犹太历 (Jewish / Hebrew)
const CAL_FRENCH = 3;      // 法国共和历 (French Republican)

// ================================================================
// 月份常量 (犹太历)
// ================================================================
const CAL_JEWISH_ADD_ALAFIM_GERESH = 4;
const CAL_NUM_CALS = 4;

// ================================================================
// 函数清单 (18个)
// ================================================================

/**
 * gregoriantojd(int $month, int $day, int $year): int
 *
 * 公历转儒略日 (Julian Day Number)。
 * 公元 1 年 1 月 1 日 = JD 1721426
 *
 * @example
 * gregoriantojd(1, 1, 2024); // 2460310
 */
function gregoriantojd(int $month, int $day, int $year): int;

/**
 * jdtogregorian(int $jd): array
 *
 * 儒略日转公历，返回关联数组 ["month", "day", "year"]。
 */
function jdtogregorian(int $jd): array;

/**
 * juliantojd(int $month, int $day, int $year): int
 *
 * 儒略历转儒略日。
 * 公元前的年份: year = -n 表示公元前 n+1 年
 */
function juliantojd(int $month, int $day, int $year): int;

/**
 * jdtojulian(int $jd): array
 *
 * 儒略日转儒略历，返回关联数组 ["month", "day", "year"]。
 */
function jdtojulian(int $jd): array;

/**
 * jewishtojd(int $month, int $day, int $year): int
 *
 * 犹太历转儒略日。
 * 犹太历月份: 1=Tishri, 2=Heshvan, ..., 7=Nisan(宗教年首), ...
 * 闰年有 13 个月 (Adar I + Adar II)
 */
function jewishtojd(int $month, int $day, int $year): int;

/**
 * jdtojewish(int $jd): array
 *
 * 儒略日转犹太历，返回关联数组 ["month", "day", "year"]。
 */
function jdtojewish(int $jd): array;

/**
 * jdtojewish_str(int $jd): string
 *
 * 返回带希伯来月份名的字符串。
 *
 * @example
 * jdtojewish_str(2460310); // "13 Adar I 5784"
 */
function jdtojewish_str(int $jd): string;

/**
 * jewish_month_name(int $month): string
 *
 * 返回犹太历月份英文名。
 *
 * @example
 * jewish_month_name(1); // "Tishri"
 */
function jewish_month_name(int $month): string;

/**
 * frenchtojd(int $month, int $day, int $year): int
 *
 * 法国共和历转儒略日。
 */
function frenchtojd(int $month, int $day, int $year): int;

/**
 * jdtofrench(int $jd): array
 *
 * 儒略日转法国共和历，返回关联数组 ["month", "day", "year"]。
 */
function jdtofrench(int $jd): array;

/**
 * cal_days_in_month(int $calendar, int $month, int $year): int
 *
 * 返回指定日历/年/月的天数。
 * 公历 2 月需判断闰年 (年份能整除400，或能整除4但不能整除100)
 * 犹太历 Heshvan 和 Kislev 天数可变 (取决于闰年)
 *
 * @example
 * cal_days_in_month(CAL_GREGORIAN, 2, 2024); // 29 (闰年)
 */
function cal_days_in_month(int $calendar, int $month, int $year): int;

/**
 * cal_from_jd(int $jd, int $calendar): array
 *
 * 将 JD 转换为指定日历，返回关联数组:
 *   ["date"] => "month/day/year"
 *   ["month"] => int, ["day"] => int, ["year"] => int
 *   ["dow"] => int (0=Sun..6=Sat)
 *   ["abbrevdayname"] => "Sun", ["dayname"] => "Sunday"
 *   ["abbrevmonth"] => "Jan", ["monthname"] => "January"
 */
function cal_from_jd(int $jd, int $calendar): array;

/**
 * cal_to_jd(int $calendar, int $month, int $day, int $year): int
 *
 * 日历转 JD。根据 $calendar 分发到对应的 xxx_to_jd 函数。
 */
function cal_to_jd(int $calendar, int $month, int $day, int $year): int;

/**
 * cal_info(int $calendar = -1): array
 *
 * 返回日历元信息 (月份名、最大天数等)。
 * -1 返回所有日历信息。
 */
function cal_info(int $calendar = -1): array;

/**
 * easter_date(int $year, int $mode = CAL_EASTER_DEFAULT): int
 *
 * 返回指定年复活节的 Unix 时间戳。
 * $mode: CAL_EASTER_DEFAULT, CAL_EASTER_ROMAN, CAL_EASTER_ALWAYS_GREGORIAN
 * 算法: Meeus/Jones/Butcher Gregorian algorithm
 */
function easter_date(int $year, int $mode = CAL_EASTER_DEFAULT): int;

/**
 * easter_days(int $year, int $mode = CAL_EASTER_DEFAULT): int
 *
 * 返回复活节距 3月21日 的天数 (可正可负)
 */
function easter_days(int $year, int $mode = CAL_EASTER_DEFAULT): int;
```

### 测试向量

```php
// 公历 → JD: 2024-01-01 = JD 2460310
assert(gregoriantojd(1, 1, 2024) == 2460310);

// JD → 公历
$d = cal_from_jd(2460310, CAL_GREGORIAN);
assert($d['month'] == 1 && $d['day'] == 1 && $d['year'] == 2024);

// 法国共和历: 共和 1 年葡月1日 = 1792-09-22 = JD 2375840
assert(frenchtojd(1, 1, 1) == 2375840);

// 犹太历 Tishri 1 = 公历 Sep/Oct
assert(cal_days_in_month(CAL_GREGORIAN, 2, 2024) == 29);  // 闰年2月
assert(cal_days_in_month(CAL_GREGORIAN, 2, 2023) == 28);  // 平年2月

// 2024 复活节 = 3月31日
$ts = easter_date(2024);
assert(date("Y-m-d", $ts) == "2024-03-31");
```

***

## 4. zlib (gzip)

### 推荐参考库

| 库                                | 说明                            | 链接                                |
| -------------------------------- | ----------------------------- | --------------------------------- |
| **标准 zlib**                      | 官方库，C 源码约 20 个文件              | zlib.net / github.com/madler/zlib |
| **PHP 源码** **`ext/zlib/zlib.c`** | 包装层参考                         | \~600行                            |
| **miniz**                        | 单文件 zlib 替代品 (\~4000行)，MIT 协议 | github.com/richgel999/miniz       |
| **zlib-ng**                      | zlib 的性能优化版                   | github.com/zlib-ng/zlib-ng        |

### 设计说明

- **内置 zlib 1.3.2 源码静态编译**（RFC 1950/1951/1952）：源码位于 `include/os/zlib_src/`（15 个 `.c` + 11 个 `.h`），编译时由 `tphp.php` 自动追加到编译列表，无需外部 `-lz` 或 `zlib1.dll`，确保纯 TCC 环境（无 MSYS2/GCC）也能使用 zlib/zip 扩展
- **AOT 单返回类型契约**：错误统一抛 `Exception`（可 try-catch），不返回 `false`
- **TCC Windows 兼容**：在 `include/os/zlib_src/zlib.h` 顶部 `#define EWOULDBLOCK EAGAIN`（TCC 的 `errno.h` 不定义 `EWOULDBLOCK`，`gzread.c` 需要）
- **内存安全**：压缩输出走 `str_pool_alloc`（作用域结束自动释放）；解压用 malloc 缓冲区循环扩展，完成后复制到 str_pool 并 free

### 完整 API

```php
// ================================================================
// 编码格式常量
// ================================================================
const ZLIB_ENCODING_RAW = -15;       // 原始 DEFLATE (不带 zlib/gzip 头)
const ZLIB_ENCODING_GZIP = 31;       // gzip 格式 (RFC 1952)
const ZLIB_ENCODING_DEFLATE = 15;    // zlib 格式 (RFC 1950)
const FORCE_GZIP = 31;               // ZLIB_ENCODING_GZIP 别名
const FORCE_DEFLATE = 15;            // ZLIB_ENCODING_DEFLATE 别名

// 压缩级别
const ZLIB_NO_COMPRESSION = 0;
const ZLIB_BEST_SPEED = 1;
const ZLIB_BEST_COMPRESSION = 9;
const ZLIB_DEFAULT_COMPRESSION = -1; // 默认 (zlib 默认=6)

// flush 模式（增量上下文）
const ZLIB_NO_FLUSH = 0;
const ZLIB_PARTIAL_FLUSH = 1;
const ZLIB_SYNC_FLUSH = 2;           // deflate_add/inflate_add 默认
const ZLIB_FULL_FLUSH = 3;
const ZLIB_FINISH = 4;               // 结束输入
const ZLIB_BLOCK = 5;

// 压缩策略
const ZLIB_FILTERED = 1;
const ZLIB_HUFFMAN_ONLY = 2;
const ZLIB_RLE = 3;
const ZLIB_FIXED = 4;
const ZLIB_DEFAULT_STRATEGY = 0;

// 状态码
const ZLIB_OK = 0;
const ZLIB_STREAM_END = 1;
const ZLIB_NEED_DICT = 2;
const ZLIB_ERRNO = -1;
const ZLIB_STREAM_ERROR = -2;
const ZLIB_DATA_ERROR = -3;
const ZLIB_MEM_ERROR = -4;
const ZLIB_BUF_ERROR = -5;
const ZLIB_VERSION_ERROR = -6;

// 版本
const ZLIB_VERSION = "1.3.2";
const ZLIB_VERNUM = 0x1320;

// ================================================================
// 基础压缩/解压函数
// ================================================================

/**
 * gzcompress(string $data, int $level = -1, int $encoding = ZLIB_ENCODING_DEFLATE): string
 *
 * 压缩字符串，使用 zlib DEFLATE。失败抛 Exception。
 */
function gzcompress(string $data, int $level = -1, int $encoding = ZLIB_ENCODING_DEFLATE): string;

/**
 * gzuncompress(string $data, int $max_length = 0, int $encoding = ZLIB_ENCODING_DEFLATE): string
 *
 * 解压 gzcompress 的输出。失败抛 Exception。
 */
function gzuncompress(string $data, int $max_length = 0, int $encoding = ZLIB_ENCODING_DEFLATE): string;

/**
 * gzencode(string $data, int $level = -1, int $encoding = ZLIB_ENCODING_GZIP): string
 *
 * 创建 gzip 格式 (.gz) 压缩数据。失败抛 Exception。
 */
function gzencode(string $data, int $level = -1, int $encoding = ZLIB_ENCODING_GZIP): string;

/**
 * gzdecode(string $data, int $max_length = 0): string
 *
 * 解码 gzip 格式压缩数据（自动检测格式）。失败抛 Exception。
 */
function gzdecode(string $data, int $max_length = 0): string;

/**
 * gzdeflate(string $data, int $level = -1, int $encoding = ZLIB_ENCODING_RAW): string
 *
 * 原始 DEFLATE 压缩（不带任何头部/校验）。失败抛 Exception。
 */
function gzdeflate(string $data, int $level = -1, int $encoding = ZLIB_ENCODING_RAW): string;

/**
 * gzinflate(string $data, int $max_length = 0): string
 *
 * 解压原始 DEFLATE 数据。失败抛 Exception。
 */
function gzinflate(string $data, int $max_length = 0): string;

/**
 * zlib_encode(string $data, int $encoding, int $level = -1): string
 *
 * 通用编码接口，与 gzdeflate/gzcompress/gzencode 等价。失败抛 Exception。
 */
function zlib_encode(string $data, int $encoding, int $level = -1): string;

/**
 * zlib_decode(string $data, int $max_length = 0): string
 *
 * 通用解码接口，自动检测 zlib/gzip 格式（不支持 raw）。失败抛 Exception。
 */
function zlib_decode(string $data, int $max_length = 0): string;

// ================================================================
// gz 文件流 API（gzFile 封装为 Resource）
// ================================================================

/**
 * gzopen(string $filename, string $mode): Resource
 *
 * 打开 gz 文件。mode 同 fopen，可附加压缩级别（如 "wb9"）。
 * 失败抛 Exception。
 */
function gzopen(string $filename, string $mode): Resource;

/**
 * gzclose(Resource $stream): bool
 *
 * 关闭 gz 文件。无效资源抛 Exception。
 */
function gzclose(Resource $stream): bool;

/**
 * gzread(Resource $stream, int $length): string
 *
 * 读取指定长度（最多 length 字节）。无效资源抛 Exception。
 */
function gzread(Resource $stream, int $length): string;

/**
 * gzwrite(Resource $stream, string $data, int $length = 0): int
 *
 * 写入数据（0=写入全部），返回写入字节数。
 */
function gzwrite(Resource $stream, string $data, int $length = 0): int;

/** gzwrite 别名 */
function gzputs(Resource $stream, string $data, int $length = 0): int;

/**
 * gzeof(Resource $stream): bool
 *
 * 是否到达文件尾。注意：仅在读取超出末尾后才返回 true。
 */
function gzeof(Resource $stream): bool;

/**
 * gzgets(Resource $stream, int $length = 0): string
 *
 * 读取一行（0=缓冲区大小）。
 */
function gzgets(Resource $stream, int $length = 0): string;

/**
 * gzgetc(Resource $stream): string
 *
 * 读取单个字符。
 */
function gzgetc(Resource $stream): string;

/** 重置到文件开头 */
function gzrewind(Resource $stream): bool;

/**
 * gzseek(Resource $stream, int $offset, int $whence = SEEK_SET): int
 *
 * 定位（whence: 0=SEEK_SET, 1=SEEK_CUR），返回新位置。
 */
function gzseek(Resource $stream, int $offset, int $whence = SEEK_SET): int;

/** 返回当前位置 */
function gztell(Resource $stream): int;

/**
 * gzpassthru(Resource $stream): int
 *
 * 读取剩余数据并输出到 stdout，返回输出字节数。
 */
function gzpassthru(Resource $stream): int;

/**
 * gzflush(Resource $stream, int $flush = ZLIB_SYNC_FLUSH): bool
 *
 * 刷新输出缓冲区。
 */
function gzflush(Resource $stream, int $flush = ZLIB_SYNC_FLUSH): bool;

/**
 * gzfile(string $filename): array
 *
 * 读取整个 gz 文件到数组（每行一个元素）。失败抛 Exception。
 */
function gzfile(string $filename): array;

/**
 * readgzfile(string $filename): int
 *
 * 读取整个 gz 文件并输出到 stdout，返回输出字节数。失败抛 Exception。
 */
function readgzfile(string $filename): int;

// ================================================================
// 增量上下文 API（流式压缩/解压，上下文封装为 Resource）
// ================================================================

/**
 * deflate_init(int $encoding, int $level = -1): Resource
 *
 * 创建压缩上下文。encoding: RAW/DEFLATE/GZIP。
 * 无效级别抛 Exception。
 */
function deflate_init(int $encoding, int $level = -1): Resource;

/**
 * deflate_add(Resource $context, string $data, int $flush_mode = ZLIB_SYNC_FLUSH): string
 *
 * 增量压缩数据块。flush_mode=ZLIB_FINISH 表示输入结束。
 */
function deflate_add(Resource $context, string $data, int $flush_mode = ZLIB_SYNC_FLUSH): string;

/**
 * inflate_init(int $encoding): Resource
 *
 * 创建解压上下文。encoding: RAW/DEFLATE/GZIP/0=自动检测。
 */
function inflate_init(int $encoding): Resource;

/**
 * inflate_add(Resource $context, string $data, int $flush_mode = ZLIB_SYNC_FLUSH): string
 *
 * 增量解压数据块。
 */
function inflate_add(Resource $context, string $data, int $flush_mode = ZLIB_SYNC_FLUSH): string;

/** 返回 zlib 状态码（ZLIB_STREAM_END=1 表示流结束）*/
function inflate_get_status(Resource $context): int;

/** 返回已解压的总字节数 */
function inflate_get_read_len(Resource $context): int;
```

***

## 5. stream ✅ 已完成

> **实现状态**: 已实现于 `ext/stream/src/stream.h` + `ext/stream/src/stream.php`（phpc 桥接 + `static inline` C 实现）。
> 跨平台 socket stream：Windows winsock2 / POSIX sys/socket.h。
> **AOT 错误处理**: 所有失败统一 `tp_throw_ex(new_tphp_class_Exception(...))`（可 try-catch，不返回 `false`）。
> socket fd 以 `t_int` 流转（与 exif 的 `FILE* → t_int` 模式一致），SSL/TLS 指针同样以 `t_int` 流转。
> Winsock 懒初始化（首次 socket 操作自动 `WSAStartup`），`FD_SETSIZE` 提升到 1024（Windows 默认 64 太少）。
> TLS 支持由 `ext/openssl` 扩展提供（`TPHP_STREAM_TLS_IMPLEMENTED` 守卫，openssl.h 必须在 stream.h 之前 include）。
> **当前 TLS 状态**: OpenSSL 扩展暂停（见 §8），`stream_socket_enable_crypto` 使用 `stream.h` 中的 stub，
> 调用时抛 "TLS not supported (OpenSSL extension not loaded)" 异常。非 TLS 流功能完全可用。
>
> **跨平台/编译器兼容**:
> - Windows: `_WIN32_WINNT 0x0600`（inet_pton 需要 Vista+）；`SHUT_RD/WR/RDWR` 映射到 `SD_RECEIVE/SEND/BOTH`；TCC 的 ws2tcpip.h 缺少 `inet_pton`/`inet_ntop` 声明，手动补充
> - TCC Windows: `-L` 指向 `tcc/win32/lib`（`-B` 不加入 `library_paths`），让 `-lws2_32` 找到 `ws2_32.def`
> - 不使用 `#pragma comment(lib, "ws2_32.lib")` — TCC 会将完整名传给 `tcc_add_library()`，搜索 `ws2_32.lib.def` 等不存在的文件
>
> 测试: `test/ext/stream_basic.php`（strerror 非空验证 + TCP echo 本地回环 + set_blocking + shutdown）全部通过。

### 推荐参考库

| 库                                   | 说明                             | 链接                                                     |
| ----------------------------------- | ------------------------------ | ------------------------------------------------------ |
| **PHP 源码** **`ext/sockets/`** + **`main/streams/`** | socket 封层 + stream 抽象层 | `sockets.c` + `main/streams/xp_socket.c`               |
| **POSIX** **`sys/socket.h`**         | 标准 socket API                  | opengroup.org/interface/v2.3bd2/xbd_6.html            |
| **Winsock2** **`winsock2.h`**        | Windows socket API               | learn.microsoft.com/en-us/windows/win32/api/winsock2/ |

### 完整 API

```php
// ================================================================
// 常量
// ================================================================
const STREAM_CLIENT_CONNECT          = 2;
const STREAM_CLIENT_ASYNC_CONNECT    = 4;
const STREAM_CLIENT_PERSISTENT       = 1;
const STREAM_SERVER_BIND             = 4;
const STREAM_SERVER_LISTEN           = 8;
const STREAM_SHUT_RD                 = 0;
const STREAM_SHUT_WR                 = 1;
const STREAM_SHUT_RDWR               = 2;
const STREAM_SOCK_STREAM             = 1;
const STREAM_SOCK_DGRAM              = 2;
const STREAM_SOCK_RDM                = 4;
const STREAM_SOCK_SEQPACKET          = 5;
const STREAM_PF_INET                 = 2;
const STREAM_PF_INET6                = 10;
const STREAM_PF_UNIX                 = 1;
const STREAM_IPPROTO_TCP             = 6;
const STREAM_IPPROTO_UDP             = 17;
const STREAM_IPPROTO_ICMP            = 1;
const STREAM_IPPROTO_RAW             = 255;
const STREAM_OOB                     = 1;
const STREAM_PEEK                    = 2;
const STREAM_AWAIT_READ              = 1;
const STREAM_AWAIT_WRITE             = 2;
const STREAM_AWAIT_READ_WRITE        = 3;
const STREAM_CRYPTO_METHOD_SSLv2     = 0;
const STREAM_CRYPTO_METHOD_SSLv3     = 1;
const STREAM_CRYPTO_METHOD_SSLv23    = 2;
const STREAM_CRYPTO_METHOD_TLS       = 3;
const STREAM_CRYPTO_METHOD_TLSv1_0   = 4;
const STREAM_CRYPTO_METHOD_TLSv1_1   = 5;
const STREAM_CRYPTO_METHOD_TLSv1_2   = 6;
const STREAM_CRYPTO_METHOD_TLSv1_3   = 7;
const STREAM_CRYPTO_ENABLE           = 1;
const STREAM_CRYPTO_DISABLE          = 0;
const STREAM_OPTION_BLOCKING         = 1;
const STREAM_OPTION_READ_BUFFER      = 3;
const STREAM_OPTION_READ_TIMEOUT     = 4;
const STREAM_OPTION_WRITE_TIMEOUT    = 5;
const STREAM_OPTION_CHUNK_SIZE       = 6;
const STREAM_FILTER_READ             = 1;
const STREAM_FILTER_WRITE            = 2;
const STREAM_FILTER_ALL              = 3;
const STREAM_NOTIFY_CONNECT          = 2;
const STREAM_NOTIFY_AUTH_REQUIRED    = 3;
const STREAM_NOTIFY_AUTH_RESULT      = 4;
const STREAM_NOTIFY_MIME_TYPE_IS     = 5;
const STREAM_NOTIFY_FILE_SIZE_IS     = 6;
const STREAM_NOTIFY_REDIRECTED       = 7;
const STREAM_NOTIFY_PROGRESS         = 8;
const STREAM_NOTIFY_FAILURE          = 9;
const STREAM_NOTIFY_COMPLETED        = 10;
const STREAM_NOTIFY_RESOLVE          = 11;
const STREAM_NOTIFY_SEVERITY_ERR     = 1;
const STREAM_NOTIFY_SEVERITY_WARN    = 2;
const STREAM_NOTIFY_SEVERITY_INFO    = 3;

// ================================================================
// 函数 (15个)
// ================================================================

function stream_close(int $fd): void;
function stream_last_error(): int;
function stream_strerror(int $err): string;
function stream_set_blocking(int $fd, bool $enable): bool;
function stream_set_read_buffer(int $fd, int $buffer): int;
function stream_isatty(int $fd): bool;
function stream_select(array $read, array $write, array $except, int $tv_sec, int $tv_usec = 0): int|Exception;
function stream_socket_server(string $address, int $flags = STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, array $context = []): int|Exception;
function stream_socket_accept(int $server_fd, int $timeout_ms = -1): int|Exception;
function stream_socket_client(string $address, int $timeout_ms = -1, int $flags = STREAM_CLIENT_CONNECT, array $context = []): int|Exception;
function stream_socket_recvfrom(int $fd, int $length, int $flags = 0): string|Exception;
function stream_socket_sendto(int $fd, string $data, int $flags = 0, string $address = ""): int|Exception;
function stream_socket_get_name(int $fd, bool $want_peer): string|Exception;
function stream_socket_shutdown(int $fd, int $how): bool|Exception;
function stream_socket_enable_crypto(int $fd, bool $enable, int $crypto_type = 0): int|Exception;
```

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

## 8. OpenSSL ⏸️ 暂停

> **实现状态**: 代码已实现于 `ext/openssl/src/openssl.h` + `ext/openssl/src/openssl.php`（phpc 桥接 + `static inline` C 实现），但**暂时停用**。
>
> **停用原因**: TCC 无法链接 MinGW GCC 生成的 COFF 静态库（对象格式不兼容）。
> 尝试用 TCC 重编译 OpenSSL 源码（`build/build_openssl_tcc.sh`）虽可编译大部分源文件，
> 但部分源文件依赖 TCC 缺失的头文件（如 `wspiapi.h`）且整体构建耗时过长，暂不可行。
> 待后续找到可行的 TCC 构建方案再启用。
>
> **当前状态**:
> - `ext/openssl/src/openssl.{h,php}` 代码保留（含 `#if TCC` 条件 `#flag` 区分 `lib-tcc/` 与 `lib/`/`lib64/`）
> - `test/ext/openssl_basic.php` 标记为 `@skip`（全平台跳过）
> - CI workflows（`build.yml`/`test.yml`）已移除 OpenSSL 构建步骤
> - `ext/stream` 的 TLS 入口 `stream_socket_enable_crypto` 使用 `stream.h` 中的 stub，调用时抛 "TLS not supported" 异常
> - 非 TLS 流功能（TCP/UDP socket、select、shutdown 等）不受影响
>
> **AOT 错误处理**（代码保留）: 所有失败统一 `tp_throw_ex(new_tphp_class_Exception(...))`（可 try-catch，不返回 `false`）。
> SSL*/SSL_CTX* 指针以 `t_int` 流转（`phpc_ptr_to_int` / `phpc_int_to_ptr` 模式，与 exif FILE* 一致）。
>
> **原预编译静态库策略**（暂停）:
> - 配置 `no-asm no-shared -DOPENSSL_NO_INLINE_ASM`（TCC 兼容）
> - 产物存放于 `ext/openssl/prebuilt/<OS>/lib-tcc/`（TCC 编译）或 `lib/`/`lib64/`（GCC/Clang 编译）
> - `openssl.php` 通过 `#flag` + `#if TCC` 条件编译声明 `-I`/`-L`/`-l`
> - Windows 额外链接 `-lws2_32 -lcrypt32`（OpenSSL 依赖 winsock + Windows Crypto API）
>
> **包含顺序**（代码保留）: `openssl.h` 必须在 `stream.h` 之前 include。
> `openssl.h` 定义 `TPHP_STREAM_TLS_IMPLEMENTED` 宏，使 `stream.h` 中的 `stream_socket_enable_crypto` stub 被跳过，
> 使用 `openssl.h` 中的真实 TLS 实现。
>
> 测试: `test/ext/openssl_basic.php`（`@skip` 标记，暂停运行）。
> 覆盖内容（代码保留）: random_pseudo_bytes + digest + encrypt/decrypt 往返 + error_string。

### 推荐参考库

| 库                             | 说明                     | 链接                                       |
| ----------------------------- | ---------------------- | ---------------------------------------- |
| **OpenSSL**                   | 官方加密库                  | openssl.org / github.com/openssl/openssl |
| **LibreSSL**                  | OpenBSD 维护的 OpenSSL 分支 | libressl.org                             |
| **PHP 源码** **`ext/openssl/`** | 完整包装层                  | `openssl.c` (\~150KB)                    |
| **mbedTLS**                   | 轻量替代 (ARM 嵌入式友好)       | github.com/Mbed-TLS/mbedtls              |
| **OpenSSL EVP 文档**            | 高层加密 API 参考            | openssl.org/docs/man3.0/man7/evp.html    |

### 完整 API

```php
// ================================================================
// 常量
// ================================================================
const SSL_OP_NO_COMPRESSION              = 0x00020000;
const SSL_OP_NO_SSLv2                    = 0x01000000;
const SSL_OP_NO_SSLv3                    = 0x02000000;
const SSL_OP_NO_TLSv1                    = 0x04000000;
const SSL_OP_NO_TLSv1_1                  = 0x10000000;
const SSL_OP_NO_TLSv1_2                  = 0x08000000;
const SSL_OP_NO_TLSv1_3                  = 0x20000000;
const SSL_OP_NO_RENEGOTIATION            = 0x40000000;
const SSL_VERIFY_NONE                    = 0;
const SSL_VERIFY_PEER                    = 1;
const SSL_VERIFY_FAIL_IF_NO_PEER_CERT    = 2;
const SSL_FILETYPE_PEM                   = 1;
const SSL_FILETYPE_ASN1                  = 2;
const X509_FILETYPE_PEM                  = 1;
const X509_FILETYPE_ASN1                 = 2;
const OPENSSL_KEYTYPE_RSA                = 0;
const OPENSSL_KEYTYPE_DSA                = 1;
const OPENSSL_KEYTYPE_DH                 = 2;
const OPENSSL_KEYTYPE_EC                 = 3;
const OPENSSL_ALGO_MD5                   = 2;
const OPENSSL_ALGO_SHA1                  = 1;
const OPENSSL_ALGO_SHA256                = 7;
const OPENSSL_ALGO_SHA384                = 8;
const OPENSSL_ALGO_SHA512                = 9;
const OPENSSL_CIPHER_AES_128_CBC         = 5;
const OPENSSL_CIPHER_AES_192_CBC         = 6;
const OPENSSL_CIPHER_AES_256_CBC         = 7;
const OPENSSL_RAW_DATA                   = 1;
const OPENSSL_ZERO_PADDING               = 2;
const OPENSSL_DONT_ZERO_PAD_KEY          = 4;
const OPENSSL_NO_PADDING                 = 3;
const OPENSSL_PKCS1_PADDING              = 1;
const OPENSSL_PKCS1_OAEP_PADDING         = 4;
const OPENSSL_SSLV23_PADDING             = 2;
const OPENSSL_DEFAULT_STREAM_CIPHERS     = "ECDHE-RSA-AES128-GCM-SHA256:ECDHE-ECDSA-AES128-GCM-SHA256";
const OPENSSL_PURPOSE_ANY                = 0;
const OPENSSL_PURPOSE_SSL_SERVER         = 1;
const OPENSSL_PURPOSE_SSL_CLIENT         = 2;

// ================================================================
// SSL Context API (5个)
// ================================================================
function openssl_ctx_new(int $method): int|Exception;
function openssl_ctx_free(int $ctx): void;
function openssl_ctx_use_certificate_file(int $ctx, string $file, int $type): bool|Exception;
function openssl_ctx_use_private_key_file(int $ctx, string $file, int $type): bool|Exception;
function openssl_ctx_set_verify(int $ctx, int $mode): void;
function openssl_ctx_set_options(int $ctx, int $options): int;

// ================================================================
// SSL Connection API (10个)
// ================================================================
function openssl_ssl_new(int $ctx): int|Exception;
function openssl_ssl_free(int $ssl): void;
function openssl_ssl_set_fd(int $ssl, int $fd): bool|Exception;
function openssl_ssl_connect(int $ssl): int|Exception;
function openssl_ssl_accept(int $ssl): int|Exception;
function openssl_ssl_read(int $ssl, int $length): string|Exception;
function openssl_ssl_write(int $ssl, string $data): int|Exception;
function openssl_ssl_shutdown(int $ssl): bool;
function openssl_ssl_get_cipher_name(int $ssl): string;
function openssl_ssl_get_version(int $ssl): string;

// ================================================================
// Error API (1个)
// ================================================================
function openssl_error_string(): string;

// ================================================================
// 对称加密 API (2个)
// ================================================================
function openssl_encrypt(string $cipher, string $key, string $iv, string $data, int $options = 0): string|Exception;
function openssl_decrypt(string $cipher, string $key, string $iv, string $data, int $options = 0): string|Exception;

// ================================================================
// 随机数 API (1个)
// ================================================================
function openssl_random_pseudo_bytes(int $length): string|Exception;

// ================================================================
// 哈希 API (1个)
// ================================================================
function openssl_digest(string $method, string $data, bool $raw_output = false): string|Exception;
```

***

## 9. fileinfo ✅ 已完成

> **实现状态**: 已作为内置库直接集成在 `include/fileinfo.h`（非 ext 按需引入）。
> 不依赖 libmagic（无需 magic.mgc 数据库文件分发），内置静态魔数表覆盖 60+ 常见文件类型。
> 使用 Resource 对象包装 finfo 状态（flags），字符串输出走 str_pool_alloc 自动释放。
> AOT 单返回类型契约: 失败统一 `tp_throw_ex(new_tphp_class_Exception(...))`（不返回 `false`）。

### 推荐参考库

| 库                              | 说明                      | 链接                          |
| ------------------------------ | ----------------------- | --------------------------- |
| **libmagic (file 命令)**         | BSD/MIT 协议，MIME 类型检测标准库 | darwinsys.com/file/         |
| **PHP 源码** **`ext/fileinfo/`** | 包装层 + 捆绑的 libmagic      | `fileinfo.c` + `libmagic/`  |
| **mimetype-io**                | 轻量纯 C MIME 检测 (无外部依赖)   | github.com/rsms/mimetype-io |

### 完整 API

```php
// ================================================================
// 常量
// ================================================================
const FILEINFO_NONE = 0;           // 无特殊行为
const FILEINFO_SYMLINK = 2;        // 跟随符号链接
const FILEINFO_MIME_TYPE = 16;     // 返回 MIME 类型 (如 "image/png")
const FILEINFO_MIME_ENCODING = 1024; // 返回 MIME 编码 (如 "charset=utf-8")
const FILEINFO_MIME = FILEINFO_MIME_TYPE | FILEINFO_MIME_ENCODING;
const FILEINFO_DEVICES = 8;        // 查看设备内容
const FILEINFO_CONTINUE = 32;      // 返回第一个匹配后继续查找
const FILEINFO_PRESERVE_ATIME = 128; // 不修改文件的访问时间
const FILEINFO_RAW = 256;          // 不转换不可打印字符
const FILEINFO_EXTENSION = 16777216; // 返回文件扩展名 (PHP 8.2+)

// ================================================================
// 函数
// ================================================================

/**
 * finfo_open(int $flags = FILEINFO_NONE, string $magic_file = ""): Resource
 *
 * 创建 fileinfo 资源。$magic_file 参数保留兼容但不使用（内置魔数表）。
 * 失败抛 Exception（内存不足等）。
 */
function finfo_open(int $flags = FILEINFO_NONE, string $magic_file = ""): Resource;

/**
 * finfo_file(Resource $finfo, string $filename, int $flags = FILEINFO_NONE): string
 *
 * 通过文件名检测文件类型（读取文件前 512 字节识别）。
 * 失败抛 Exception（空文件名、文件不存在、无效资源）。
 */
function finfo_file(Resource $finfo, string $filename, int $flags = FILEINFO_NONE): string;

/**
 * finfo_buffer(Resource $finfo, string $data, int $flags = FILEINFO_NONE): string
 *
 * 通过内存数据检测文件类型（不读磁盘）。
 * 失败抛 Exception（无效资源）。
 */
function finfo_buffer(Resource $finfo, string $data, int $flags = FILEINFO_NONE): string;

/**
 * finfo_close(Resource $finfo): void
 *
 * 关闭 fileinfo 资源。
 */
function finfo_close(Resource $finfo): void;

/**
 * finfo_set_flags(Resource $finfo, int $flags): bool
 *
 * 设置 fileinfo 资源的默认 flags。始终返回 true。
 * 失败抛 Exception（无效资源）。
 */
function finfo_set_flags(Resource $finfo, int $flags): bool;

/**
 * mime_content_type(string $filename): string
 *
 * 便捷函数，等价于:
 *   $fi = finfo_open(FILEINFO_MIME_TYPE);
 *   $r = finfo_file($fi, $filename);
 *   finfo_close($fi);
 *   return $r;
 * 失败抛 Exception（空文件名、文件不存在）。
 */
function mime_content_type(string $filename): string;
```

***

## 10. iconv ✅ 已完成

> **实现状态**: 已作为内置库直接集成在 `include/iconv.h`（非 ext 按需引入）。
> 跨平台策略: POSIX 用系统 `<iconv.h>`（**TCC 下改用手动前向声明** `iconv_t`/`iconv_open`/`iconv`/`iconv_close`，避开 TCC macOS 包缺失 `stdarg.h` 的链式包含问题；GCC/Clang 仍用系统 `<iconv.h>`）；Windows 用 Win32 `MultiByteToWideChar`/`WideCharToMultiByte`。macOS 链接自动添加 `-liconv`。
> AOT 单返回类型契约: 失败统一 `tp_throw`（不返回 `false`）；`iconv_strpos` 未找到返回 `-1`（非 `false`）。

### 推荐参考库

| 库                                  | 说明                                                    | 链接                         |
| ---------------------------------- | ----------------------------------------------------- | -------------------------- |
| **GNU libiconv**                   | 独立字符集转换库                                              | gnu.org/software/libiconv/ |
| **系统 iconv (POSIX)**               | glibc/macOS 内置的 iconv API                             | `<iconv.h>`                |
| **PHP 源码** **`ext/iconv/iconv.c`** | 包装层                                                   | \~80KB                     |
| **Win32 API**                      | Windows 替代: MultiByteToWideChar / WideCharToMultiByte | MSDN                       |

### 完整 API

```php
// ================================================================
// 常量
// ================================================================
const ICONV_IMPL = "glibc";    // 或 "libiconv" 取决于系统
const ICONV_VERSION = "2.39";  // 实现版本

// ================================================================
// 函数
// ================================================================

/**
 * iconv_strlen(string $str, string $charset = "UTF-8"): int
 *
 * 返回字符串的字符数（按指定编码）。
 * UTF-8 可复用 mb_strlen 实现。失败 tp_throw。
 */
function iconv_strlen(string $str, string $charset = "UTF-8"): int;

/**
 * iconv_strpos(string $haystack, string $needle, int $offset = 0,
 *              string $charset = "UTF-8"): int
 *
 * 在字符串中查找子串位置（按字符偏移）。
 * 等于 mb_strpos 的 iconv 版本。未找到返回 -1（非 false）。
 */
function iconv_strpos(string $haystack, string $needle, int $offset = 0,
                      string $charset = "UTF-8"): int;

/**
 * iconv_substr(string $str, int $offset, int $length = 0,
 *              string $charset = "UTF-8"): string
 *
 * 截取子串（按字符偏移）。
 * 等于 mb_substr 的 iconv 版本。失败 tp_throw。
 */
function iconv_substr(string $str, int $offset, int $length = 0,
                      string $charset = "UTF-8"): string;

/**
 * iconv(string $from_encoding, string $to_encoding, string $str): string
 *
 * 字符串编码转换。最核心的函数。失败 tp_throw。
 * 后缀 "//IGNORE": 忽略无法转换的字符
 * 后缀 "//TRANSLIT": 转换为近似字符
 *
 * @example
 * iconv("UTF-8", "ISO-8859-1//TRANSLIT", "café"); // "caf'e"
 */
function iconv(string $from_encoding, string $to_encoding, string $str): string;

/**
 * iconv_get_encoding(string $type = "all"): array
 *
 * 获取 iconv 内部配置。始终返回 3 元素关联数组。
 * $type: "all" → 全部, "input_encoding" → 输入编码, "output_encoding" → 输出编码,
 *        "internal_encoding" → 内部编码
 */
function iconv_get_encoding(string $type = "all"): array;

/**
 * iconv_set_encoding(string $type, string $encoding): bool
 *
 * 设置 iconv 内部配置。未知 type 时 tp_throw。
 */
function iconv_set_encoding(string $type, string $encoding): bool;

/**
 * iconv_mime_encode(string $field_name, string $field_value,
 *                   array $prefs = []): string
 *
 * 创建 MIME 编码的邮件头字段。失败 tp_throw。
 * $prefs 可选键: "scheme" (B/Q), "input-charset", "output-charset",
 *               "line-length", "line-break-chars"
 */
function iconv_mime_encode(string $field_name, string $field_value,
                           array $prefs = []): string;

/**
 * iconv_mime_decode(string $str, int $mode = 0, string $charset = "UTF-8"): string
 *
 * 解码 MIME 头字段。如 "=?UTF-8?B?...?=" 格式。失败 tp_throw。
 */
function iconv_mime_decode(string $str, int $mode = 0, string $charset = "UTF-8"): string;
```

***

## 11. exif ✅ 已完成

> **实现状态**: 已完成纯 phpc 实现于 `ext/exif/src/exif.php`，无自定义 C 代码。
> 仅通过 C 标准库函数 (fopen/fgetc/fseek/ftell/fwrite/fclose) 实现二进制 JPEG/TIFF EXIF 格式解析。
> **所有函数参数/返回值使用 tphp 类型**(int/string/array)，C 类型转换封装在函数内部:
> FILE* 指针通过 `phpc_ptr_to_int()` 转为 `t_int` 在 PHP 层流转，函数内部用 `phpc_int_to_ptr()` 转回 void* 调用 C 库。
> `defer C->fclose($f)` 确保文件句柄在所有退出路径（含异常）都正确关闭。
> 测试: `test/exif/test_exif.php` (34 项检查) 全部通过。

### 推荐参考库

| 库                                | 说明                               | 链接                               |
| -------------------------------- | -------------------------------- | -------------------------------- |
| **PHP 源码** **`ext/exif/exif.c`** | 完整 EXIF 解析器 (\~200K)             | 纯 C 实现，无外部依赖                     |
| **libexif**                      | C 语言 EXIF 标签解析库 (LGPL)           | github.com/libexif/libexif       |
| **TinyEXIF**                     | 轻量 C++ EXIF 解析器 (\~1000行)        | github.com/cdcseacave/TinyEXIF   |
| **easyexif**                     | 极简 C++ EXIF (\~600行)             | github.com/mayanklahiri/easyexif |
| **EXIF 标准**                      | CIPA DC-008-2012 (JPEG EXIF 2.3) | cipa.jp/std/std-sec.html         |

### 完整 API

```php
// ================================================================
// 函数
// ================================================================

/**
 * exif_read_data(string $filename, string $sections = "",
 *                bool $arrays = false, bool $thumbnail = false): array|false
 *
 * 读取 JPEG/TIFF 文件的 EXIF 头信息。核心函数。
 *
 * 返回关联数组，包含以下标签组:
 *
 * IFD0 (主图像):
 *   "Make"          => string  相机制造商
 *   "Model"         => string  相机型号
 *   "Orientation"   => int     方向 (1-8): 1=正常 6=顺时针90° 8=逆时针90°
 *   "DateTime"      => string  "2024:01:15 14:30:00"
 *   "Artist"        => string  作者
 *   "Copyright"     => string  版权
 *   "ImageDescription" => string 图片描述
 *
 * EXIF IFD (拍摄参数):
 *   "ExposureTime"       => string  "1/125"
 *   "FNumber"            => string  "f/2.8"
 *   "ISOSpeedRatings"    => int     ISO 值 (100/200/400/...)
 *   "FocalLength"        => string  "50 mm"
 *   "ExposureBiasValue"  => string  曝光补偿
 *   "MeteringMode"       => int     测光模式
 *   "Flash"              => int     闪光灯状态
 *   "WhiteBalance"       => int     白平衡
 *   "ColorSpace"         => int     色彩空间 (1=sRGB, 65535=未校准)
 *   "ExifImageWidth"     => int     图片宽度
 *   "ExifImageLength"    => int     图片高度
 *
 * COMPUTED (计算值):
 *   "Width"           => int
 *   "Height"          => int
 *   "IsColor"         => int     (1=彩色 0=黑白)
 *   "ApertureFNumber" => float   光圈值
 *   "FocusDistance"   => string  对焦距离
 *
 * GPS IFD:
 *   "GPSLatitudeRef"   => string  "N" 或 "S"
 *   "GPSLatitude"      => array   [度,分,秒] (rational 数组)
 *   "GPSLongitudeRef"  => string  "E" 或 "W"
 *   "GPSLongitude"     => array
 *   "GPSAltitudeRef"   => int     (0=海平面以上 1=以下)
 *   "GPSAltitude"      => string  海拔
 */
function exif_read_data(string $filename, string $sections = "",
                       bool $arrays = false, bool $thumbnail = false): array|false;

/**
 * exif_thumbnail(string $filename): array
 *
 * 读取 JPEG 文件的内嵌缩略图。
 * 返回 ["data" => string, "width" => int, "height" => int, "imagetype" => int]
 */
function exif_thumbnail(string $filename): array;

/**
 * exif_imagetype(string $filename): int|false
 *
 * 检测图像类型（不读取 EXIF，只看文件头魔数）。
 * 返回 IMAGETYPE_* 常量:
 *   IMAGETYPE_JPEG (2), IMAGETYPE_PNG (3),
 *   IMAGETYPE_GIF (1), IMAGETYPE_BMP (6),
 *   IMAGETYPE_TIFF_II (7), IMAGETYPE_TIFF_MM (8),
 *   IMAGETYPE_WEBP (18)
 */
function exif_imagetype(string $filename): int|false;

/**
 * exif_tagname(int $index): string|false
 *
 * 根据标签编号返回标签名称。
 *
 * @example
 * exif_tagname(0x010F); // "Make"
 */
function exif_tagname(int $index): string|false;
```

### 实现细节: EXIF 解析流程

```c
// JPEG EXIF 结构:
//   FF D8                        ← SOI
//   FF E1 [2字节长度]            ← APP1 Marker
//   "Exif\0\0"                   ← EXIF Header (6 bytes)
//   TIFF Header:
//     [2字节] Byte Order:  0x4949 (LE) 或 0x4D4D (BE)
//     [2字节] Magic:       0x002A
//     [4字节] IFD0 Offset:  通常 0x00000008
//   IFD0:  [2字节] Entry Count
//          每个 Entry 12 字节: tag(2) type(2) count(4) value/offset(4)
//   EXIF IFD:  从 TAG_EXIF_IFD (0x8769) 的值跳转
//   GPS IFD:   从 TAG_GPS_IFD (0x8825) 的值跳转

// TIFF 数据类型:
//   1 = BYTE        (uint8)
//   2 = ASCII       (null-terminated string)
//   3 = SHORT       (uint16)
//   4 = LONG        (uint32)
//   5 = RATIONAL    (uint32 numerator / uint32 denominator)
//   7 = UNDEFINED   (raw bytes)
//   9 = SLONG       (int32)
//   10 = SRATIONAL  (int32/int32)
```

***

## 12. ZIP

### 推荐参考库

| 库                          | 说明                        | 链接                                    |
| -------------------------- | ------------------------- | ------------------------------------- |
| **libzip**                 | C 语言 ZIP 操作库 (BSD 协议)     | libzip.org / github.com/nih-at/libzip |
| **PHP 源码** **`ext/zip/`**  | 完整包装层                     | `zip.c` (\~90KB)                      |
| **minizip (zlib contrib)** | zlib 自带的极简 ZIP 库          | zlib/contrib/minizip/                 |
| **zip.h (Kuba Podgórski)** | 单头文件 ZIP 库 (MIT, \~1000行) | github.com/kuba--/zip                 |

### 设计说明

- **不依赖 libzip**，手写 ZIP 本地文件头/中央目录/EOCD
- **压缩/解压复用内置 zlib 1.3.2 源码**的 `deflate`/`inflate`（raw DEFLATE, windowBits=-15）——见 [§4 zlib](#4-zlib-gzip)
- **CRC32 复用 zlib** 的 `crc32()`
- **ZipArchive 作为 Resource 子类**，内部状态通过 `ptr` 指向 `_tphp_zip_data`
- **读取模式**：整文件载入内存，解析中央目录
- **写入模式**：内存缓冲区构建，`zip_close` 时写入文件
- **AOT 单返回类型契约**：错误统一抛 `Exception`（可 try-catch），不返回 `false`
- **限制**：不支持修改已有归档（`zip_delete`/`zip_rename` 会抛异常，建议创建新归档替代）

### 完整 API

```php
// ================================================================
// 常量
// ================================================================
// 打开模式
const ZIP_CREATE = 1;           // 创建新文件(不存在时创建)
const ZIP_EXCL = 2;             // 排他创建(存在则失败)
const ZIP_CHECKCONS = 4;        // 检查一致性
const ZIP_TRUNCATE = 8;         // 截断(若存在则覆盖)
const ZIP_RDONLY = 16;          // 只读

// 标志位
const ZIP_FL_OVERWRITE = 1;     // 覆盖现有文件
const ZIP_FL_NOCASE = 2;        // 不区分大小写
const ZIP_FL_NODIR = 4;         // 不为目录创建条目
const ZIP_FL_COMPRESSED = 8;    // 读取压缩数据
const ZIP_FL_UNCHANGED = 16;    // 使用原始数据

// 压缩方法
const ZIP_CM_DEFAULT = -1;      // 默认压缩方法
const ZIP_CM_STORE = 0;         // 不压缩 (Stored)
const ZIP_CM_DEFLATE = 8;       // DEFLATE 压缩

// ================================================================
// 函数 — 归档操作
// ================================================================

/**
 * zip_open(string $filename, int $flags = 0): Resource
 *
 * 打开/创建 ZIP。返回 Resource。失败抛 Exception。
 *   ZIP_CREATE: 创建新文件(不存在时)
 *   ZIP_EXCL: 排他创建(存在则失败)
 *   ZIP_TRUNCATE: 截断已有文件
 *   ZIP_RDONLY: 只读
 *   ZIP_CHECKCONS: 验证一致性
 */
function zip_open(string $filename, int $flags = 0): Resource;

/**
 * zip_close(Resource $zip): bool
 *
 * 关闭归档（写入模式刷盘）。
 */
function zip_close(Resource $zip): bool;

/**
 * zip_num_files(Resource $zip): int
 *
 * 返回文件总数。
 */
function zip_num_files(Resource $zip): int;

/**
 * zip_get_error_string(Resource $zip): string
 *
 * 返回最后错误描述。
 */
function zip_get_error_string(Resource $zip): string;

/**
 * zip_locate(Resource $zip, string $name): int
 *
 * 按名查找条目索引。未找到返回 -1。
 */
function zip_locate(Resource $zip, string $name): int;

// ================================================================
// 函数 — 条目信息查询
// ================================================================

/**
 * zip_read(Resource $zip): array
 *
 * 返回所有条目列表。每个条目是关联数组:
 *   "name" => string  文件名
 *   "index" => int     索引
 *   "size" => int     原始大小 (字节)
 *   "comp_size" => int 压缩后大小
 *   "comp_method" => int 压缩方法 (0=store 8=deflate)
 *   "mtime" => int     修改时间 (Unix timestamp)
 */
function zip_read(Resource $zip): array;

/**
 * zip_stat(Resource $zip, int $index): array
 *
 * 获取单个条目信息（同 zip_read 单项结构）。越界抛 Exception。
 */
function zip_stat(Resource $zip, int $index): array;

/**
 * zip_entry_name(Resource $zip, int $index): string
 *
 * 返回条目名。越界抛 Exception。
 */
function zip_entry_name(Resource $zip, int $index): string;

/**
 * zip_entry_filesize(Resource $zip, int $index): int
 *
 * 返回条目原始大小。越界抛 Exception。
 */
function zip_entry_filesize(Resource $zip, int $index): int;

/**
 * zip_entry_compressedsize(Resource $zip, int $index): int
 *
 * 返回条目压缩后大小。越界抛 Exception。
 */
function zip_entry_compressedsize(Resource $zip, int $index): int;

/**
 * zip_entry_compressionmethod(Resource $zip, int $index): string
 *
 * 返回压缩方法名（"Stored" 或 "Deflated"）。越界抛 Exception。
 */
function zip_entry_compressionmethod(Resource $zip, int $index): string;

// ================================================================
// 函数 — 条目读写
// ================================================================

/**
 * zip_entry_open(Resource $zip, int $index): bool
 *
 * 打开条目准备读取。
 */
function zip_entry_open(Resource $zip, int $index): bool;

/**
 * zip_entry_read(Resource $zip, int $index, int $length = 0): string
 *
 * 读取条目内容。$length=0 → 读取全部。
 * 写模式下调用抛 Exception。
 */
function zip_entry_read(Resource $zip, int $index, int $length = 0): string;

/**
 * zip_entry_close(Resource $zip): bool
 *
 * 关闭当前打开的条目。
 */
function zip_entry_close(Resource $zip): bool;

/**
 * zip_add_file(Resource $zip, string $name, string $data,
 *              int $flags = 0, int $comp_method = ZIP_CM_DEFLATE): bool
 *
 * 添加文件。$name 是归档内路径。
 * comp_method: ZIP_CM_STORE(不压缩) 或 ZIP_CM_DEFLATE(默认)。
 */
function zip_add_file(Resource $zip, string $name, string $data,
                     int $flags = 0, int $comp_method = ZIP_CM_DEFLATE): bool;

/**
 * zip_add_dir(Resource $zip, string $dirname, int $flags = 0): bool
 *
 * 添加目录。$dirname 以 / 结尾（如 "mydir/"）。
 */
function zip_add_dir(Resource $zip, string $dirname, int $flags = 0): bool;

/**
 * zip_delete(Resource $zip, int $index): bool
 *
 * 删除条目。**不支持修改已有归档，抛 Exception。**
 * 建议创建新归档替代。
 */
function zip_delete(Resource $zip, int $index): bool;

/**
 * zip_rename(Resource $zip, int $index, string $new_name): bool
 *
 * 重命名条目。**不支持修改已有归档，抛 Exception。**
 * 建议创建新归档替代。
 */
function zip_rename(Resource $zip, int $index, string $new_name): bool;
```

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

## 14. PDO

| 属性         | 值                                                      |
| ---------- | ------------------------------------------------------ |
| **外部依赖**   | 按驱动: pdo\_mysql→libmariadb, pdo\_sqlite→sqlite3        |
| **预估行数**   | \~700 行 C (核心+驱动分派)                                    |
| **PHP 参考** | `ext/pdo/pdo.c` + `ext/pdo_mysql/` + `ext/pdo_sqlite/` |
| **难度**     | ⭐⭐⭐⭐⭐                                                  |

### 14.1 为什么 PDO 可以做

之前标记"深度绑定 Zend"过于保守。逐组件分析：

| 组件                       | Zend 依赖               | AOT 方案               |
| ------------------------ | --------------------- | -------------------- |
| `PDO` 类                  | `zend_class_entry` 注册 | TinyPHP COS class 系统 |
| `PDOStatement` 类         | 同上                    | COS class            |
| `PDOException`           | Zend exception        | 已有 `Exception` 支持    |
| `prepare()` 占位符          | 纯字符串 `:name`→`?` 替换   | \~80 行 C             |
| `bindValue()`            | t\_var 类型标记           | 已有类型系统               |
| `execute()`              | 驱动分发                  | 函数指针表 `pdo_driver`   |
| `fetch()` / `fetchAll()` | t\_array 填充           | 已有 array API         |
| 驱动层 (MySQL/SQLite)       | 委托 C 库                | libmariadb / sqlite3 |

### 14.2 常量

```
PDO::PARAM_NULL (0)  PARAM_INT (1)  PARAM_STR (2)  PARAM_BOOL (5)  PARAM_LOB (3)
PDO::FETCH_ASSOC (2)  FETCH_NUM (3)  FETCH_BOTH (4)  FETCH_COLUMN (7)
PDO::ERRMODE_SILENT (0)  ERRMODE_WARNING (1)  ERRMODE_EXCEPTION (2)
PDO::ATTR_ERRMODE (3)  ATTR_DEFAULT_FETCH_MODE (19)  ATTR_EMULATE_PREPARES (20)
```

### 14.3 核心架构

```c
// ── 驱动接口表 (每个驱动实现一个 pdo_driver 实例) ──
typedef struct {
    const char *name;
    void* (*open)(const char *dsn, const char *user, const char *pass, char **err);
    void  (*close)(void *conn);
    void* (*prepare)(void *conn, const char *sql);
    bool  (*execute)(void *stmt, t_var *params, int n);
    bool  (*fetch)(void *stmt, t_array **row, int mode);
    int   (*row_count)(void *stmt);
    const char* (*last_insert_id)(void *conn);
    const char* (*error)(void *conn);
    char* (*quote)(void *conn, const char *str, int len, int *out_len);
    bool  (*begin_txn)(void *conn);
    bool  (*commit)(void *conn);
    bool  (*rollback)(void *conn);
} pdo_driver;

// ── 编译时注册的驱动列表 ──
extern const pdo_driver pdo_mysql_driver;
extern const pdo_driver pdo_sqlite_driver;
static const pdo_driver *g_drivers[] = { &pdo_mysql_driver, &pdo_sqlite_driver, NULL };
```

### 14.4 PDO 类接口 (8 函数)

| 函数                                                | 说明                  |
| ------------------------------------------------- | ------------------- |
| `__construct($dsn, $user, $pass, $opts)`          | 解析 DSN→查找驱动→连接      |
| `prepare($sql)` → PDOStatement                    | 占位符解析→驱动 prepare    |
| `query($sql, $fetchMode)` → PDOStatement          | 快捷: prepare+execute |
| `exec($sql)` → int                                | 执行无结果 SQL, 返回影响行数   |
| `beginTransaction() / commit() / rollBack()`      | 委托驱动事务              |
| `lastInsertId($name?)` → string                   | AUTO\_INCREMENT 返回值 |
| `quote($str, $type)` → string                     | 驱动级转义, 防注入          |
| `setAttribute($attr, $val) / getAttribute($attr)` | 配置选项                |

### 14.5 PDOStatement 类接口 (6 函数)

| 函数                                  | 说明                |
| ----------------------------------- | ----------------- |
| `bindValue($param, $value, $type)`  | `:name`→位置映射, 存储值 |
| `execute($params?)` → bool          | 绑定参数→驱动 execute   |
| `fetch($mode=ASSOC)` → array\|false | 取一行→关联数组          |
| `fetchAll($mode=ASSOC)` → array     | 循环 fetch→全部行      |
| `fetchColumn($col=0)` → mixed       | 取第一行指定列           |
| `rowCount() / columnCount()` → int  | 元信息               |

### 14.6 典型用法

```php
// SQLite 内存库
$pdo = new PDO("sqlite::memory:");
$pdo->exec("CREATE TABLE t (id INTEGER PRIMARY KEY, name TEXT)");
$pdo->exec("INSERT INTO t VALUES (1, 'Alice')");

$stmt = $pdo->prepare("SELECT * FROM t WHERE id = :id");
$stmt->execute([":id" => 1]);
echo $stmt->fetch()["name"];  // "Alice"

// MySQL + 事务
$pdo = new PDO("mysql:host=127.0.0.1;dbname=test", "root", "");
$pdo->beginTransaction();
$pdo->exec("UPDATE accounts SET balance = balance - 100 WHERE id = 1");
$pdo->commit();

// Prepared statement 防注入
$stmt = $pdo->prepare("SELECT * FROM users WHERE name = :n");
$stmt->execute([":n" => "O'Brien"]);  // 自动转义
$rows = $stmt->fetchAll();
echo count($rows);
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
| 6  | SQLite         | \~500  | ⭐⭐⭐⭐       | sqlite3        | 8   | 1-2 天 |
| 7  | cURL           | \~300  | ⭐⭐⭐⭐       | libcurl        | 8   | 2-3 天 |
| 8  | OpenSSL        | \~500  | ⭐⭐⭐⭐⭐      | openssl        | 8   | 3-5 天 |
| 9  | fileinfo ✅    | \~200  | ⭐⭐⭐        | 内置魔数表       | 6   | 1 天   |
| 10 | iconv          | \~500  | ⭐⭐⭐ ✅ 已完成 | libiconv/系统    | 8   | ✅ 已完成 |
| 11 | exif           | \~800  | ⭐⭐⭐ ✅ 已完成 | 无(纯解析)         | 4   | ✅ 已完成 |
| 12 | ZIP            | \~400  | ⭐⭐⭐⭐       | libzip+zlib    | 12  | 2-3 天 |
| 13 | MySQL          | \~600  | ⭐⭐⭐⭐⭐      | libmariadb     | 16  | 3-5 天 |
| 14 | PDO            | \~700  | ⭐⭐⭐⭐⭐      | 按驱动            | 16  | 3-5 天 |
| 15 | GD             | \~600  | ⭐⭐⭐⭐⭐      | libgd+png+jpeg | 20  | 3-5 天 |
