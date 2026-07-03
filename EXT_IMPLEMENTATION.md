# TinyPHP 扩展 API 参考与实现计划

> 待实现的 15 个扩展。每个列出：全部函数签名 / 常量 / 参数说明 / 返回值 / 推荐参考库 / 实现步骤。
> 优先级见底部表格，建议按 bcrypt → filter → calendar 顺序逐步实现。

---

## 目录

| # | 扩展 | 复杂度 | 依赖 | 函数数 |
|---|------|--------|------|--------|
| 2 | [filter_var](#2-filter_var) | ⭐⭐ | 无 | 2 |
| 3 | [calendar](#3-calendar) | ⭐⭐⭐ | 无 | 18 |
| 4 | [zlib (gzip)](#4-zlib-gzip) | ⭐⭐⭐ | zlib | 6 |
| 5 | [PCRE2 (preg_*)](#5-pcre2-preg_) | ⭐⭐⭐⭐ | pcre2 | 7 |
| 6 | [SQLite](#6-sqlite) | ⭐⭐⭐⭐ | sqlite3 | 6 |
| 7 | [cURL](#7-curl) | ⭐⭐⭐⭐ | libcurl | 8 |
| 8 | [OpenSSL](#8-openssl) | ⭐⭐⭐⭐⭐ | openssl | 8 |
| 9 | [fileinfo](#9-fileinfo) | ⭐⭐⭐ | libmagic | 4 |
| 10 | [iconv](#10-iconv) | ⭐⭐⭐ | libiconv/系统 | 3 |
| 11 | [exif](#11-exif) | ⭐⭐⭐ | 无(纯解析) | 6 |
| 12 | [ZIP](#12-zip) | ⭐⭐⭐⭐ | libzip+zlib | 8 |
| 13 | [MySQL](#13-mysql) | ⭐⭐⭐⭐⭐ | libmysqlclient | 16 |
| 14 | [PDO](#14-pdo) | ⭐⭐⭐⭐⭐ | 每驱动各异 | 10 |
| 15 | [GD](#15-gd) | ⭐⭐⭐⭐⭐ | libgd+png+jpeg | 20 |

---

## 2. filter_var

### 推荐参考库

| 库 | 说明 | 链接 |
|----|------|------|
| **PHP 源码 `ext/filter/`** | 完整实现，含 `filter.c` + `logical_filters.c` + `sanitizing_filters.c` | 3 个文件合计 ~150KB |
| **`libcidr`** | IP/CIDR 验证 | github.com/nickwhitman1993/libcidr |
| **RFC 5321** | email 格式规范 | tools.ietf.org/html/rfc5321 |
| **RFC 3986** | URL 格式规范 | tools.ietf.org/html/rfc3986 |

### 完整 API

```c
// ================================================================
// 过滤器常量 (int) — 验证过滤器
// ================================================================
#define FILTER_VALIDATE_INT      257   // 验证整数
#define FILTER_VALIDATE_BOOL     258   // 验证布尔值 ("1"/"true"/"on"/"yes")
#define FILTER_VALIDATE_FLOAT    259   // 验证浮点数
#define FILTER_VALIDATE_REGEXP   272   // 验证正则匹配 (需 PCRE2)
#define FILTER_VALIDATE_URL      273   // 验证 URL (RFC 3986)
#define FILTER_VALIDATE_EMAIL    274   // 验证 Email (RFC 5321)
#define FILTER_VALIDATE_IP       275   // 验证 IP 地址
#define FILTER_VALIDATE_MAC      276   // 验证 MAC 地址
#define FILTER_VALIDATE_DOMAIN   277   // 验证域名

// ================================================================
// 净化过滤器 (sanitize)
// ================================================================
#define FILTER_SANITIZE_STRING          513  // 去除 HTML 标签/编码
#define FILTER_SANITIZE_ENCODED         514  // URL 编码
#define FILTER_SANITIZE_SPECIAL_CHARS   515  // HTML 转义 <>"'&
#define FILTER_SANITIZE_EMAIL           517  // 去除 email 非法字符
#define FILTER_SANITIZE_URL             518  // 去除 URL 非法字符
#define FILTER_SANITIZE_NUMBER_INT      519  // 去除非数字/+- 以外字符
#define FILTER_SANITIZE_NUMBER_FLOAT    520  // 去除非数字/+-.,eE 以外字符
#define FILTER_SANITIZE_ADD_SLASHES     523  // 调用 addslashes()
#define FILTER_SANITIZE_FULL_SPECIAL_CHARS 522  // 完整 HTML 实体

// ================================================================
// 标志位 (flags, 组合使用)
// ================================================================
#define FILTER_FLAG_NONE              0    // 无标志
#define FILTER_FLAG_ALLOW_OCTAL       1    // INT: 允许八进制 "0" 前缀
#define FILTER_FLAG_ALLOW_HEX         2    // INT: 允许十六进制 "0x" 前缀
#define FILTER_FLAG_STRIP_LOW         4    // STRING: 去除 < 32 的字符
#define FILTER_FLAG_STRIP_HIGH        8    // STRING: 去除 > 127 的字符
#define FILTER_FLAG_ENCODE_LOW       16    // STRING: 编码 < 32 字符
#define FILTER_FLAG_ENCODE_HIGH      32    // STRING: 编码 > 127 字符
#define FILTER_FLAG_ENCODE_AMP       64    // STRING: 编码 &
#define FILTER_FLAG_NO_ENCODE_QUOTES 128   // STRING: 不编码引号
#define FILTER_FLAG_EMPTY_STRING_NULL 256  // STRING: 空串→NULL
#define FILTER_FLAG_ALLOW_FRACTION   4096  // INT: 允许小数点
#define FILTER_FLAG_ALLOW_THOUSAND   8192  // INT: 允许千分位 ','
#define FILTER_FLAG_ALLOW_SCIENTIFIC 16384 // INT: 允许科学计数法
#define FILTER_FLAG_PATH_REQUIRED    0x100000  // URL: 必须含 path
#define FILTER_FLAG_QUERY_REQUIRED   0x200000  // URL: 必须含 query
#define FILTER_FLAG_IPV4           0x100000  // IP: 仅 IPv4
#define FILTER_FLAG_IPV6           0x200000  // IP: 仅 IPv6
#define FILTER_FLAG_NO_RES_RANGE   0x400000  // IP: 排除保留/私有地址
#define FILTER_FLAG_NO_PRIV_RANGE  0x800000  // IP: 排除私有地址
#define FILTER_FLAG_EMAIL_UNICODE  0x100000  // EMAIL: 允许 Unicode
#define FILTER_FLAG_HOSTNAME       0x100000  // URL: 要求 hostname

// ================================================================
// 函数
// ================================================================

/**
 * filter_var(mixed $value, int $filter, array|int $options = 0) → mixed
 *
 * 用指定过滤器验证/净化单个变量。
 *
 * @param value    输入值 (t_var)
 * @param filter   过滤器常量 (t_int), FILTER_VALIDATE_* 或 FILTER_SANITIZE_*
 * @param options  选项: 可以是 flags (int) 或关联数组 (t_array*)
 *                 关联数组支持键:
 *                 "options" → 子选项数组 (如 "min_range"/"max_range" 用于 INT)
 *                 "flags"   → 标志位组合
 * @return         验证通过 → 原值或处理后值 (t_var)
 *                 验证失败 → TYPE_NULL (PHP 中为 false)
 *
 * 示例:
 *   filter_var("42", FILTER_VALIDATE_INT)                  → int(42)
 *   filter_var("abc", FILTER_VALIDATE_INT)                 → NULL
 *   filter_var("user@example.com", FILTER_VALIDATE_EMAIL)  → "user@example.com"
 *
 * PHP 参考: ext/filter/filter.c:306
 */
t_var tphp_fn_filter_var(t_var value, t_int filter, t_var options);

/**
 * filter_var_array(array $data, array|int $definition, bool $add_empty = true) → array
 *
 * 批量过滤数组中的多个值。
 *
 * @param data        输入数组 (t_array*)
 * @param definition  过滤器定义:
 *                    若为 int: 对所有元素应用同一过滤器
 *                    若为 t_array*: 每个键映射到 filterspec 数组
 *                    filterspec: ["filter"=>FILTER, "flags"=>int, "options"=>array]
 * @param add_empty   是否在结果中包含不存在的键 (t_bool)
 * @return            过滤后的数组 (t_array*)
 *
 * PHP 参考: ext/filter/filter.c:383
 */
t_array* tphp_fn_filter_var_array(t_array *data, t_var definition, bool add_empty);

/**
 * filter_list() → array
 *
 * 返回所有支持的过滤器名称列表。
 * (可选实现, ~20行)
 */
t_array* tphp_fn_filter_list(void);

/**
 * filter_id(string $name) → int|false
 *
 * 根据过滤器名称返回 ID。如 filter_id("int") → 257
 * (可选实现, ~50行)
 */
t_int tphp_fn_filter_id(t_string name);
```

---

## 3. calendar

### 推荐参考库

| 库 | 说明 | 链接 |
|----|------|------|
| **PHP 源码 `ext/calendar/`** | sdncal.h + calendar.c，自包含 C 算法 | `ext/calendar/sdncal.h` (400行) + `calendar.c` |
| **Meeus "Astronomical Algorithms"** | 日历算法圣经，SDN 体系来源 | 书籍 ISBN 978-0943396613 |
| **GNU `cal`** | Unix cal 命令源码 | git.savannah.gnu.org/cgit/coreutils.git/tree/src/cal.c |
| **`libzahl`** (calendar module) | 纯 C 日历库 | github.com/maandree/libzahl |

### 完整 API

```c
// ================================================================
// 日历类型常量
// ================================================================
#define CAL_GREGORIAN   0   // 公历 (Gregorian)
#define CAL_JULIAN      1   // 儒略历 (Julian)
#define CAL_JEWISH      2   // 犹太历 (Jewish / Hebrew)
#define CAL_FRENCH      3   // 法国共和历 (French Republican)

// ================================================================
// 月份常量 (犹太历)
// ================================================================
#define CAL_JEWISH_ADD_ALAFIM_GERESH 4
#define CAL_NUM_CALS  4

// ================================================================
// 函数清单 (18个)
// ================================================================

/* ── 公历 (Gregorian) ── */
t_int  tphp_fn_gregoriantojd(t_int month, t_int day, t_int year);
// PHP: gregoriantojd(1, 1, 2024) → 2460310 (JD = Julian Day Number)
// 公元 1 年 1 月 1 日 = JD 1721426

void   tphp_fn_jdtogregorian(t_int jd, t_int *month, t_int *day, t_int *year);
// 通过指针返回 month/day/year。用法:
//   t_int m, d, y; jdtogregorian(jd, &m, &d, &y);
// 需在 PHP 侧包装为返回数组: cal_from_jd(jd, CAL_GREGORIAN)

/* ── 儒略历 (Julian) ── */
t_int  tphp_fn_juliantojd(t_int month, t_int day, t_int year);
// 公元前的年份: year = -n 表示公元前 n+1 年
void   tphp_fn_jdtojulian(t_int jd, t_int *month, t_int *day, t_int *year);

/* ── 犹太历 (Jewish / Hebrew) ── */
t_int  tphp_fn_jewishtojd(t_int month, t_int day, t_int year);
void   tphp_fn_jdtojewish(t_int jd, t_int *month, t_int *day, t_int *year);
// 犹太历月份: 1=Tishri, 2=Heshvan, ..., 7=Nisan(宗教年首), ...
// 闰年有 13 个月 (Adar I + Adar II)
t_string tphp_fn_jdtojewish_str(t_int jd);
// 返回带希伯来月份名的字符串: "13 Adar I 5784"
t_string tphp_fn_jewish_month_name(t_int month);
// 返回月份英文名: "Tishri", "Heshvan", ...

/* ── 法国共和历 (French Republican) ── */
t_int  tphp_fn_frenchtojd(t_int month, t_int day, t_int year);
void   tphp_fn_jdtofrench(t_int jd, t_int *month, t_int *day, t_int *year);

/* ── 工具函数 ── */

/**
 * cal_days_in_month(int $calendar, int $month, int $year) → int
 *
 * 返回指定日历/年/月的天数。
 *   公历 2 月需判断闰年 (年份能整除400，或能整除4但不能整除100)
 *   犹太历 Heshvan 和 Kislev 天数可变 (取决于闰年)
 *
 * PHP: CAL_GREGORIAN, 2, 2024 → 29 (闰年)
 */
t_int tphp_fn_cal_days_in_month(t_int calendar, t_int month, t_int year);

/**
 * cal_from_jd(int $jd, int $calendar) → array
 *
 * 将 JD 转换为指定日历，返回关联数组:
 *   ["date"] => "month/day/year"
 *   ["month"] => int, ["day"] => int, ["year"] => int
 *   ["dow"] => int (0=Sun..6=Sat)
 *   ["abbrevdayname"] => "Sun", ["dayname"] => "Sunday"
 *   ["abbrevmonth"] => "Jan", ["monthname"] => "January"
 */
t_array* tphp_fn_cal_from_jd(t_int jd, t_int calendar);

/**
 * cal_to_jd(int $calendar, int $month, int $day, int $year) → int
 * 日历转 JD。根据 $calendar 分发到对应的 xxx_to_jd 函数。
 */
t_int tphp_fn_cal_to_jd(t_int calendar, t_int month, t_int day, t_int year);

/**
 * cal_info(int $calendar = -1) → array
 * 返回日历元信息 (月份名、最大天数等)。
 * -1 返回所有日历信息。
 */
t_array* tphp_fn_cal_info(t_int calendar);

/* ── 复活节 (Easter) ── */

/**
 * easter_date(int $year, int $mode = CAL_EASTER_DEFAULT) → int
 * 返回指定年复活节的 Unix 时间戳。
 * $mode: CAL_EASTER_DEFAULT, CAL_EASTER_ROMAN, CAL_EASTER_ALWAYS_GREGORIAN
 * 算法: Meeus/Jones/Butcher Gregorian algorithm
 */
t_int tphp_fn_easter_date(t_int year, t_int mode);

/**
 * easter_days(int $year, int $mode = CAL_EASTER_DEFAULT) → int
 * 返回复活节距 3月21日 的天数 (可正可负)
 */
t_int tphp_fn_easter_days(t_int year, t_int mode);
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

---

## 4. zlib (gzip)

### 推荐参考库

| 库 | 说明 | 链接 |
|----|------|------|
| **标准 zlib** | 官方库，C 源码约 20 个文件 | zlib.net / github.com/madler/zlib |
| **PHP 源码 `ext/zlib/zlib.c`** | 包装层参考 | ~600行 |
| **miniz** | 单文件 zlib 替代品 (~4000行)，MIT 协议 | github.com/richgel999/miniz |
| **zlib-ng** | zlib 的性能优化版 | github.com/zlib-ng/zlib-ng |

### 完整 API

```c
// ================================================================
// 压缩级别常量
// ================================================================
#define ZLIB_ENCODING_RAW      -15  // 原始 DEFLATE (不带 zlib/gzip 头)
#define ZLIB_ENCODING_GZIP      31  // gzip 格式 (RFC 1952)
#define ZLIB_ENCODING_DEFLATE   15  // zlib 格式 (RFC 1950)
#define ZLIB_NO_COMPRESSION      0   // 不压缩
#define ZLIB_BEST_SPEED          1   // 最快速度
#define ZLIB_BEST_COMPRESSION    9   // 最小体积
#define ZLIB_DEFAULT_COMPRESSION -1  // 默认 (zlib 默认=6)

// ================================================================
// 函数
// ================================================================

/**
 * gzcompress(string $data, int $level = -1, int $encoding = ZLIB_ENCODING_DEFLATE) → string|false
 *
 * 压缩字符串，使用 zlib DEFLATE。
 *
 * @param data      待压缩数据 (t_string)
 * @param level     压缩级别 -1~9 (t_int)
 * @param encoding  编码格式: ZLIB_ENCODING_DEFLATE (默认) / GZIP / RAW
 * @return          压缩后数据 (t_string), 失败返回空
 *
 * PHP 参考: ext/zlib/zlib.c:253
 */
t_string tphp_fn_gzcompress(t_string data, t_int level, t_int encoding);

/**
 * gzuncompress(string $data, int $max_length = 0, int $encoding = ZLIB_ENCODING_DEFLATE) → string|false
 *
 * 解压 gzcompress 的输出。
 *
 * @param data        压缩数据 (t_string)
 * @param max_length  解压后最大长度，0=自动 (t_int)
 * @param encoding    编码格式 (t_int), 必须与压缩时一致
 * @return            解压后数据 (t_string)
 *
 * PHP 参考: ext/zlib/zlib.c:321
 */
t_string tphp_fn_gzuncompress(t_string data, t_int max_length, t_int encoding);

/**
 * gzencode(string $data, int $level = -1, int $encoding = ZLIB_ENCODING_GZIP) → string|false
 *
 * 创建 gzip 格式 (.gz) 压缩数据。
 * 与 gzcompress 的区别: 默认编码为 GZIP, 包含文件头+CRC32校验
 *
 * PHP 参考: ext/zlib/zlib.c:190
 */
t_string tphp_fn_gzencode(t_string data, t_int level, t_int encoding);

/**
 * gzdecode(string $data, int $max_length = 0) → string|false
 *
 * 解码 gzip 格式压缩数据。
 *
 * PHP 参考: ext/zlib/zlib.c:218
 */
t_string tphp_fn_gzdecode(t_string data, t_int max_length);

/**
 * gzdeflate(string $data, int $level = -1, int $encoding = ZLIB_ENCODING_RAW) → string|false
 *
 * 原始 DEFLATE 压缩（不带任何头部/校验）。
 * 等价于 gzcompress(..., encoding=ZLIB_ENCODING_RAW)
 *
 * PHP 参考: ext/zlib/zlib.c:370
 */
t_string tphp_fn_gzdeflate(t_string data, t_int level, t_int encoding);

/**
 * gzinflate(string $data, int $max_length = 0) → string|false
 *
 * 解压原始 DEFLATE 数据。
 *
 * PHP 参考: ext/zlib/zlib.c:395
 */
t_string tphp_fn_gzinflate(t_string data, t_int max_length);
```

---

## 5. PCRE2 (preg_*)

### 推荐参考库

| 库 | 说明 | 链接 |
|----|------|------|
| **PCRE2 官方** | 最新正则引擎，v10.x | github.com/PCRE2Project/pcre2 |
| **PHP 源码 `ext/pcre/`** | 包装层参考 | `php_pcre.c` (~130KB) + `pcre2lib/` |
| **`sregex` (OpenResty)** | 流式正则引擎，C 源码 ~8000 行 | github.com/openresty/sregex |
| **RE2 (Google)** | 线性时间正则，C++ | github.com/google/re2 |

### 完整 API

```c
// ================================================================
// 常量
// ================================================================
#define PREG_PATTERN_ORDER         1   // preg_match_all: $matches[0]=全匹配, [1]=group1, ...
#define PREG_SET_ORDER             2   // preg_match_all: $matches[0]=第1个匹配组, [1]=第2个, ...
#define PREG_OFFSET_CAPTURE      256   // 同时返回偏移量
#define PREG_UNMATCHED_AS_NULL   512   // 未匹配的子组设为 NULL 而非 ""
#define PREG_SPLIT_NO_EMPTY        1   // preg_split: 去除空片段
#define PREG_SPLIT_DELIM_CAPTURE   2   // preg_split: 保留分隔符捕获组
#define PREG_SPLIT_OFFSET_CAPTURE  4   // preg_split: 返回偏移量
#define PREG_GREP_INVERT           1   // preg_grep: 反转结果
#define PREG_NO_ERROR              0   // 最后错误码
#define PREG_INTERNAL_ERROR        1
#define PREG_BACKTRACK_LIMIT_ERROR 2
#define PREG_RECURSION_LIMIT_ERROR 3
#define PREG_BAD_UTF8_ERROR        4
#define PREG_BAD_UTF8_OFFSET_ERROR 5
#define PREG_JIT_STACKLIMIT_ERROR  6

// ================================================================
// 函数
// ================================================================

/**
 * preg_match(string $pattern, string $subject, array &$matches = null,
 *            int $flags = 0, int $offset = 0) → int|false
 *
 * 执行正则匹配，返回匹配次数 (0 或 1)。
 *
 * @param pattern  Perl 兼容正则 (t_string), 如 "/^[a-z]+$/i"
 * @param subject  被搜索字符串 (t_string)
 * @param matches  输出: 匹配结果数组 (t_array**)
 *                 $matches[0] = 完整匹配文本
 *                 $matches[1..n] = 子组匹配文本
 * @param flags    PREG_OFFSET_CAPTURE 等组合
 * @param offset   开始搜索的字节偏移 (t_int, 默认 0)
 * @return         1=匹配, 0=不匹配, NULL=错误(正则编译失败)
 *
 * PHP 参考: ext/pcre/php_pcre.c:283
 */
t_var tphp_fn_preg_match(t_string pattern, t_string subject, t_array **matches,
                         t_int flags, t_int offset);

/**
 * preg_match_all(string $pattern, string $subject, array &$matches = null,
 *                int $flags = PREG_PATTERN_ORDER, int $offset = 0) → int|false
 *
 * 执行全局正则匹配，返回匹配次数。
 * $flags: PREG_PATTERN_ORDER → $matches[0]=所有完整匹配, [1]=所有group1匹配, ...
 *         PREG_SET_ORDER      → $matches[0]=第1次匹配的各组, [1]=第2次, ...
 *
 * PHP 参考: ext/pcre/php_pcre.c:392
 */
t_var tphp_fn_preg_match_all(t_string pattern, t_string subject, t_array **matches,
                             t_int flags, t_int offset);

/**
 * preg_replace(string|array $pattern, string|array $replacement,
 *              string|array $subject, int $limit = -1, int &$count = null) → string|array
 *
 * 正则替换。$limit=-1 表示无限制。
 *
 * PHP 参考: ext/pcre/php_pcre.c:492
 */
t_string tphp_fn_preg_replace(t_string pattern, t_string replacement,
                              t_string subject, t_int limit, t_int *count);

/**
 * preg_split(string $pattern, string $subject, int $limit = -1, int $flags = 0) → array
 *
 * 用正则分割字符串。
 * $limit=-1 表示无限制。
 *
 * PHP 参考: ext/pcre/php_pcre.c:620
 */
t_array* tphp_fn_preg_split(t_string pattern, t_string subject, t_int limit, t_int flags);

/**
 * preg_grep(string $pattern, array $input, int $flags = 0) → array
 *
 * 返回数组中匹配 pattern 的元素。
 * $flags: PREG_GREP_INVERT → 返回不匹配的元素。
 *
 * PHP 参考: ext/pcre/php_pcre.c:712
 */
t_array* tphp_fn_preg_grep(t_string pattern, t_array *input, t_int flags);

/**
 * preg_quote(string $str, string $delimiter = null) → string
 *
 * 转义正则特殊字符。如 preg_quote("a+b*c") → "a\+b\*c"
 * 纯字符串操作, 不需要 PCRE2。
 *
 * PHP 参考: ext/pcre/php_pcre.c:770
 */
t_string tphp_fn_preg_quote(t_string str, t_string delimiter);

/**
 * preg_last_error() → int
 * preg_last_error_msg() → string
 *
 * 返回最后一次 PCRE 操作的错误码/错误消息。
 */
t_int tphp_fn_preg_last_error(void);
t_string tphp_fn_preg_last_error_msg(void);
```

### 测试向量

```php
// preg_match
preg_match('/^Hello (\w+)/', 'Hello World', $m);
// $m = ["Hello World", "World"]

// preg_match_all
preg_match_all('/\d+/', 'a1b2c3', $m);
// $m = [["1","2","3"]]

// preg_replace
preg_replace('/\d/', 'X', 'a1b2');  // "aXbX"

// preg_split
preg_split('/[,;]/', 'a,b;c');  // ["a","b","c"]

// preg_quote
preg_quote('a+b*c');  // "a\+b\*c"
```

---

## 6. SQLite

### 推荐参考库

| 库 | 说明 | 链接 |
|----|------|------|
| **SQLite3 Amalgamation** | 官方单文件发行版，sqlite3.c+sqlite3.h | sqlite.org/download.html |
| **PHP 源码 `ext/sqlite3/`** | OO 接口参考 | `sqlite3.c` (~70KB) |
| **SQLite 官方文档** | C API 完整参考 | sqlite.org/capi3ref.html |

### 完整 API

```c
// ================================================================
// 常量
// ================================================================
#define SQLITE3_OK            0    // 成功
#define SQLITE3_ASSOC         1    // query 返回关联数组
#define SQLITE3_NUM           2    // query 返回索引数组
#define SQLITE3_BOTH          3    // query 返回索引+关联
#define SQLITE3_INTEGER       1    // 列类型
#define SQLITE3_FLOAT         2
#define SQLITE3_TEXT          3
#define SQLITE3_BLOB          4
#define SQLITE3_NULL          5
#define SQLITE3_OPEN_READONLY   1
#define SQLITE3_OPEN_READWRITE  2
#define SQLITE3_OPEN_CREATE     4

// ================================================================
// 函数
// ================================================================

/**
 * sqlite_open(string $filename, int $flags = READWRITE|CREATE, string $enc_key = "") → resource|false
 *
 * 打开/创建 SQLite 数据库。
 * $filename = ":memory:" 创建内存数据库。
 *
 * PHP 参考: ext/sqlite3/sqlite3.c:199
 */
tphp_sqlite_db* tphp_fn_sqlite_open(t_string filename, t_int flags, t_string enc_key);

/**
 * sqlite_close(resource $db) → void
 *
 * 关闭数据库连接。
 *
 * PHP 参考: ext/sqlite3/sqlite3.c:269
 */
void tphp_fn_sqlite_close(tphp_sqlite_db *db);

/**
 * sqlite_exec(resource $db, string $sql) → bool
 *
 * 执行不返回结果的 SQL (CREATE, INSERT, UPDATE, DELETE 等)
 *
 * PHP 参考: ext/sqlite3/sqlite3.c:330
 */
bool tphp_fn_sqlite_exec(tphp_sqlite_db *db, t_string sql);

/**
 * sqlite_query(resource $db, string $sql) → array|false
 *
 * 执行 SELECT 查询，返回结果数组。
 * 每行是关联数组 (键=列名, 值=列值)。
 *
 * PHP 参考: ext/sqlite3/sqlite3.c:390
 */
t_array* tphp_fn_sqlite_query(tphp_sqlite_db *db, t_string sql);

/**
 * sqlite_query_single(resource $db, string $sql) → array|false
 *
 * 执行 SELECT 查询，只返回第一行。
 * 适合 COUNT(*)、LIMIT 1 等场景。
 */
t_array* tphp_fn_sqlite_query_single(tphp_sqlite_db *db, t_string sql);

/**
 * sqlite_escape_string(string $str) → string
 *
 * 转义 SQL 字符串中的特殊字符 (单引号等)。
 *
 * PHP 参考: ext/sqlite3/sqlite3.c:518
 */
t_string tphp_fn_sqlite_escape_string(t_string str);

/**
 * sqlite_changes(resource $db) → int
 *
 * 返回最近一次 INSERT/UPDATE/DELETE 影响的行数。
 */
t_int tphp_fn_sqlite_changes(tphp_sqlite_db *db);

/**
 * sqlite_last_insert_rowid(resource $db) → int
 *
 * 返回最近一次 INSERT 的 rowid。
 */
t_int tphp_fn_sqlite_last_insert_rowid(tphp_sqlite_db *db);

/**
 * sqlite_last_error_msg(resource $db) → string
 */
t_string tphp_fn_sqlite_last_error_msg(tphp_sqlite_db *db);
```

---

## 7. cURL

### 推荐参考库

| 库 | 说明 | 链接 |
|----|------|------|
| **libcurl** | 官方 HTTP/FTP/... 多协议客户端库 | curl.se/libcurl/ |
| **PHP 源码 `ext/curl/interface.c`** | 完整包装层 | ~180KB, 900+ 行核心函数 |
| **curl_easy_setopt 手册** | 所有选项常量定义 | curl.se/libcurl/c/curl_easy_setopt.html |

### 完整 API

```c
// ================================================================
// 常用选项常量 (CURLOPT_*)
// 完整列表: https://curl.se/libcurl/c/curl_easy_setopt.html
// ================================================================
#define CURLOPT_URL              10002   // string: 请求 URL
#define CURLOPT_RETURNTRANSFER   19913   // bool:  返回响应体而不直接输出
#define CURLOPT_POST             47      // bool:  发送 POST 请求
#define CURLOPT_POSTFIELDS       10015   // string: POST 数据
#define CURLOPT_HTTPHEADER       10023   // array:  自定义 HTTP 头
#define CURLOPT_FOLLOWLOCATION   52      // bool:  跟随 3xx 重定向
#define CURLOPT_MAXREDIRS        68      // int:   最大重定向次数
#define CURLOPT_TIMEOUT          13      // int:   请求超时秒数
#define CURLOPT_CONNECTTIMEOUT   78      // int:   连接超时秒数
#define CURLOPT_SSL_VERIFYPEER   64      // bool:  验证 SSL 证书
#define CURLOPT_SSL_VERIFYHOST   81      // int:   验证 SSL hostname
#define CURLOPT_USERAGENT        10018   // string: User-Agent 头
#define CURLOPT_REFERER          10016   // string: Referer 头
#define CURLOPT_COOKIE           10022   // string: Cookie 头
#define CURLOPT_COOKIEFILE       10031   // string: 读取 Cookie 文件
#define CURLOPT_COOKIEJAR        10082   // string: 写入 Cookie 文件
#define CURLOPT_PROXY            10004   // string: 代理地址
#define CURLOPT_PROXYPORT        59      // int:   代理端口
#define CURLOPT_PROXYTYPE        101     // int:   代理类型 (HTTP/SOCKS4/SOCKS5)
#define CURLOPT_HTTPAUTH         107     // int:   HTTP 认证方法
#define CURLOPT_USERPWD          10005   // string: "user:pass" 认证
#define CURLOPT_HTTPGET          80      // bool:  强制 GET
#define CURLOPT_NOBODY           44      // bool:  不下载响应体 (HEAD)
#define CURLOPT_CUSTOMREQUEST    10036   // string: 自定义请求方法 (PUT/DELETE等)
#define CURLOPT_VERBOSE          41      // bool:  详细输出
#define CURLOPT_HEADER           42      // bool:  响应中包含 HTTP 头
#define CURLOPT_NOPROGRESS       43      // bool:  关闭进度条
#define CURLOPT_UPLOAD           46      // bool:  上传模式
#define CURLOPT_INFILESIZE       14      // int:   上传文件大小
#define CURLOPT_HTTP_VERSION     84      // int:   HTTP 版本 (1.0/1.1/2/3)
#define CURLOPT_IPRESOLVE        113     // int:   IP 解析 (IPv4/IPv6)

// ================================================================
// CURLINFO_* 常量 (用于 curl_getinfo)
// ================================================================
#define CURLINFO_HTTP_CODE       0x2000001  // int:   HTTP 状态码
#define CURLINFO_TOTAL_TIME      0x3000001  // float: 总耗时
#define CURLINFO_SIZE_DOWNLOAD   0x3000006  // float: 下载字节数
#define CURLINFO_CONTENT_TYPE    0x100000C  // string: Content-Type

// ================================================================
// 函数
// ================================================================

/**
 * curl_init(string $url = null) → CurlHandle|false
 *
 * 初始化 cURL 会话，返回句柄。
 *
 * PHP 参考: ext/curl/interface.c:123
 */
tphp_curl_handle* tphp_fn_curl_init(t_string url);

/**
 * curl_setopt(CurlHandle $ch, int $option, mixed $value) → bool
 *
 * 设置 cURL 传输选项。最常用的函数。
 *
 * $option 支持的类型映射:
 *   string 选项 → curl_easy_setopt(ch, CURLOPT_URL, value)
 *   bool   选项 → curl_easy_setopt(ch, opt, (long)value)
 *   int    选项 → curl_easy_setopt(ch, opt, (long)value)
 *   array  选项 → curl_slist_append 循环 (用于 CURLOPT_HTTPHEADER)
 *
 * PHP 参考: ext/curl/interface.c:260
 */
bool tphp_fn_curl_setopt(tphp_curl_handle *ch, t_int option, t_var value);

/**
 * curl_setopt_array(CurlHandle $ch, array $options) → bool
 *
 * 批量设置多个选项。
 * $options 键为 CURLOPT_* 常量, 值为选项值。
 * 失败时立即停止处理后续选项。
 *
 * PHP 参考: ext/curl/interface.c:340
 */
bool tphp_fn_curl_setopt_array(tphp_curl_handle *ch, t_array *options);

/**
 * curl_exec(CurlHandle $ch) → string|bool
 *
 * 执行 cURL 会话，返回响应体字符串。
 * 需要先设置 CURLOPT_RETURNTRANSFER = 1。
 * 失败返回空字符串。
 *
 * PHP 参考: ext/curl/interface.c:405
 */
t_string tphp_fn_curl_exec(tphp_curl_handle *ch);

/**
 * curl_getinfo(CurlHandle $ch, int $option = 0) → mixed
 *
 * 获取传输信息。$option=0 返回所有信息数组。
 * 常用 option: CURLINFO_HTTP_CODE, CURLINFO_TOTAL_TIME
 *
 * PHP 参考: ext/curl/interface.c:460
 */
t_var tphp_fn_curl_getinfo(tphp_curl_handle *ch, t_int option);

/**
 * curl_error(CurlHandle $ch) → string
 *
 * 返回最后一次 cURL 操作的错误描述文本。
 *
 * PHP 参考: ext/curl/interface.c:530
 */
t_string tphp_fn_curl_error(tphp_curl_handle *ch);

/**
 * curl_errno(CurlHandle $ch) → int
 *
 * 返回最后一次 cURL 操作的错误码 (CURLE_*)
 *
 * PHP 参考: ext/curl/interface.c:555
 */
t_int tphp_fn_curl_errno(tphp_curl_handle *ch);

/**
 * curl_close(CurlHandle $ch) → void
 *
 * 关闭 cURL 会话，释放所有资源。
 *
 * PHP 参考: ext/curl/interface.c:575
 */
void tphp_fn_curl_close(tphp_curl_handle *ch);

/**
 * curl_version() → array
 *
 * 返回 cURL 版本信息数组:
 *   ["version_number"] → int
 *   ["version"] → string
 *   ["ssl_version"] → string
 *   ["libz_version"] → string
 *   ["protocols"] → array of string
 *
 * PHP 参考: ext/curl/interface.c:598
 */
t_array* tphp_fn_curl_version(void);
```

---

## 8. OpenSSL

### 推荐参考库

| 库 | 说明 | 链接 |
|----|------|------|
| **OpenSSL** | 官方加密库 | openssl.org / github.com/openssl/openssl |
| **LibreSSL** | OpenBSD 维护的 OpenSSL 分支 | libressl.org |
| **PHP 源码 `ext/openssl/`** | 完整包装层 | `openssl.c` (~150KB) |
| **mbedTLS** | 轻量替代 (ARM 嵌入式友好) | github.com/Mbed-TLS/mbedtls |
| **OpenSSL EVP 文档** | 高层加密 API 参考 | openssl.org/docs/man3.0/man7/evp.html |

### 完整 API

```c
// ================================================================
// 加密方法名称 (openssl_get_cipher_methods 返回的子集)
// ================================================================
// 通过 OpenSSL 的 EVP_get_cipherbyname() 动态查询
// 常用方法: "aes-128-cbc","aes-256-cbc","aes-128-gcm","aes-256-gcm"
//          "aes-128-ctr","aes-256-ctr","aes-128-ecb","aes-256-ecb"
//          "des-ede3-cbc","bf-cbc","rc4","rc2","camellia-256-cbc"

// ================================================================
// 常量
// ================================================================
#define OPENSSL_RAW_DATA    1   // 返回原始二进制，不 base64 编码
#define OPENSSL_ZERO_PADDING 2  // 不用 PKCS#7 填充 (仅用于特殊场景)
#define OPENSSL_DONT_ZERO_PAD_KEY 4

// ================================================================
// 函数
// ================================================================

/**
 * openssl_encrypt(string $data, string $method, string $key,
 *                 int $options = 0, string $iv = "", string &$tag = null,
 *                 string $aad = "", int $tag_length = 16) → string|false
 *
 * 加密数据。支持 AES-128/256-CBC/GCM/CTR 等。
 *
 * @param data       明文 (t_string)
 * @param method     加密方法名 (t_string), 如 "aes-256-cbc"
 * @param key        密钥 (t_string), 长度取决于算法 (AES-256=32字节)
 * @param options    OPENSSL_RAW_DATA: 返回原始二进制, 否则 base64 编码
 * @param iv         初始化向量 (t_string), CBC/CTR 需要 16 字节
 * @param tag        GCM 认证标签输出 (仅 GCM 模式)
 * @param aad        GCM 附加认证数据 (仅 GCM)
 * @param tag_length GCM 标签长度 (4/8/12/13/14/16)
 * @return           密文 (t_string)
 *
 * PHP 参考: ext/openssl/openssl.c:1234
 */
t_string tphp_fn_openssl_encrypt(t_string data, t_string method, t_string key,
                                 t_int options, t_string iv);

/**
 * openssl_decrypt(string $data, string $method, string $key,
 *                 int $options = 0, string $iv = "", string $tag = null,
 *                 string $aad = "") → string|false
 *
 * 解密数据。参数与 encrypt 对称。
 *
 * PHP 参考: ext/openssl/openssl.c:1340
 */
t_string tphp_fn_openssl_decrypt(t_string data, t_string method, t_string key,
                                 t_int options, t_string iv);

/**
 * openssl_random_pseudo_bytes(int $length, bool &$crypto_strong = null) → string|false
 *
 * 生成加密安全的随机字节。使用 OpenSSL 的 CSPRNG。
 *
 * PHP 参考: ext/openssl/openssl.c:563
 */
t_string tphp_fn_openssl_random_pseudo_bytes(t_int length);

/**
 * openssl_get_cipher_methods(bool $aliases = false) → array
 *
 * 返回支持的加密方法列表。
 *
 * PHP 参考: ext/openssl/openssl.c:1525
 */
t_array* tphp_fn_openssl_get_cipher_methods(bool aliases);

/**
 * openssl_cipher_iv_length(string $method) → int|false
 *
 * 返回指定加密方法的 IV 长度。
 *
 * PHP 参考: ext/openssl/openssl.c:1589
 */
t_int tphp_fn_openssl_cipher_iv_length(t_string method);

/**
 * openssl_cipher_key_length(string $method) → int|false
 *
 * 返回指定加密方法的密钥长度。
 */
t_int tphp_fn_openssl_cipher_key_length(t_string method);

/**
 * openssl_digest(string $data, string $method, bool $raw = false) → string|false
 *
 * 计算消息摘要 (哈希)。$method: "sha256", "sha512", "md5", etc.
 * 可用 openssl_get_md_methods() 获取支持的算法列表。
 *
 * PHP 参考: ext/openssl/openssl.c:1127
 */
t_string tphp_fn_openssl_digest(t_string data, t_string method, bool raw_output);

/**
 * openssl_get_md_methods(bool $aliases = false) → array
 *
 * 返回支持的摘要算法列表。
 */
t_array* tphp_fn_openssl_get_md_methods(bool aliases);
```

---

## 9. fileinfo

### 推荐参考库

| 库 | 说明 | 链接 |
|----|------|------|
| **libmagic (file 命令)** | BSD/MIT 协议，MIME 类型检测标准库 | darwinsys.com/file/ |
| **PHP 源码 `ext/fileinfo/`** | 包装层 + 捆绑的 libmagic | `fileinfo.c` + `libmagic/` |
| **mimetype-io** | 轻量纯 C MIME 检测 (无外部依赖) | github.com/rsms/mimetype-io |

### 完整 API

```c
// ================================================================
// 常量
// ================================================================
#define FILEINFO_NONE              0    // 无特殊行为
#define FILEINFO_SYMLINK           2    // 跟随符号链接
#define FILEINFO_MIME_TYPE        16    // 返回 MIME 类型 (如 "image/png")
#define FILEINFO_MIME_ENCODING   1024   // 返回 MIME 编码 (如 "charset=utf-8")
#define FILEINFO_MIME       (FILEINFO_MIME_TYPE | FILEINFO_MIME_ENCODING)
#define FILEINFO_DEVICES          8    // 查看设备内容
#define FILEINFO_CONTINUE        32    // 返回第一个匹配后继续查找
#define FILEINFO_PRESERVE_ATIME 128    // 不修改文件的访问时间
#define FILEINFO_RAW            256    // 不转换不可打印字符
#define FILEINFO_EXTENSION     2048    // 返回文件扩展名 (PHP 8.2+)

// ================================================================
// 函数
// ================================================================

/**
 * finfo_open(int $flags = FILEINFO_NONE, string $magic_file = null) → resource|false
 *
 * 创建 fileinfo 资源。
 * $magic_file: 自定义 magic 数据库路径，默认使用系统 magic.mgc。
 *
 * PHP 参考: ext/fileinfo/fileinfo.c:202
 */
void* tphp_fn_finfo_open(t_int flags, t_string magic_file);

/**
 * finfo_file(resource $finfo, string $filename, int $flags = FILEINFO_NONE,
 *            resource $context = null) → string|false
 *
 * 通过文件名检测文件类型（读取文件内容识别）。
 *
 * PHP 参考: ext/fileinfo/fileinfo.c:285
 */
t_string tphp_fn_finfo_file(void *finfo, t_string filename, t_int flags);

/**
 * finfo_buffer(resource $finfo, string $data, int $flags = FILEINFO_NONE,
 *              resource $context = null) → string|false
 *
 * 通过内存数据检测文件类型（不读磁盘）。
 *
 * PHP 参考: ext/fileinfo/fileinfo.c:334
 */
t_string tphp_fn_finfo_buffer(void *finfo, t_string data, t_int flags);

/**
 * finfo_close(resource $finfo) → bool
 *
 * 关闭 fileinfo 资源。
 *
 * PHP 参考: ext/fileinfo/fileinfo.c:378
 */
bool tphp_fn_finfo_close(void *finfo);

/**
 * finfo_set_flags(resource $finfo, int $flags) → bool
 *
 * 设置 libmagic 选项。
 * PHP 8.3 新增。
 */
bool tphp_fn_finfo_set_flags(void *finfo, t_int flags);

/**
 * mime_content_type(string $filename) → string|false
 *
 * 便捷函数，等价于:
 *   $fi = finfo_open(FILEINFO_MIME_TYPE);
 *   $r = finfo_file($fi, $filename);
 *   finfo_close($fi);
 *   return $r;
 *
 * PHP 参考: ext/fileinfo/fileinfo.c:415
 */
t_string tphp_fn_mime_content_type(t_string filename);
```

---

## 10. iconv

### 推荐参考库

| 库 | 说明 | 链接 |
|----|------|------|
| **GNU libiconv** | 独立字符集转换库 | gnu.org/software/libiconv/ |
| **系统 iconv (POSIX)** | glibc/macOS 内置的 iconv API | `<iconv.h>` |
| **PHP 源码 `ext/iconv/iconv.c`** | 包装层 | ~80KB |
| **Win32 API** | Windows 替代: MultiByteToWideChar / WideCharToMultiByte | MSDN |

### 完整 API

```c
// ================================================================
// 常量
// ================================================================
#define ICONV_IMPL    "glibc"   // 或 "libiconv" 取决于系统
#define ICONV_VERSION "2.39"    // 实现版本

// ================================================================
// 函数
// ================================================================

/**
 * iconv_strlen(string $str, string $charset = "UTF-8") → int|false
 *
 * 返回字符串的字符数（按指定编码）。
 * UTF-8 可复用 mb_strlen 实现。
 *
 * PHP 参考: ext/iconv/iconv.c:1644
 */
t_int tphp_fn_iconv_strlen(t_string str, t_string charset);

/**
 * iconv_strpos(string $haystack, string $needle, int $offset = 0,
 *              string $charset = "UTF-8") → int|false
 *
 * 在字符串中查找子串位置（按字符偏移）。
 * 等于 mb_strpos 的 iconv 版本。
 *
 * PHP 参考: ext/iconv/iconv.c:1688
 */
t_int tphp_fn_iconv_strpos(t_string haystack, t_string needle, t_int offset, t_string charset);

/**
 * iconv_substr(string $str, int $offset, int $length = null,
 *              string $charset = "UTF-8") → string|false
 *
 * 截取子串（按字符偏移）。
 * 等于 mb_substr 的 iconv 版本。
 *
 * PHP 参考: ext/iconv/iconv.c:1742
 */
t_string tphp_fn_iconv_substr(t_string str, t_int offset, t_int length, t_string charset);

/**
 * iconv(string $from_encoding, string $to_encoding, string $str) → string|false
 *
 * 字符串编码转换。最核心的函数。
 *   如: iconv("UTF-8", "ISO-8859-1//TRANSLIT", "café") → "caf'e"
 *   后缀 "//IGNORE": 忽略无法转换的字符
 *   后缀 "//TRANSLIT": 转换为近似字符
 *
 * PHP 参考: ext/iconv/iconv.c:1525
 */
t_string tphp_fn_iconv(t_string from_enc, t_string to_enc, t_string str);

/**
 * iconv_get_encoding(string $type = "all") → array|string|false
 *
 * 获取 iconv 内部配置。
 * $type: "all" → 全部, "input_encoding" → 输入编码, "output_encoding" → 输出编码,
 *        "internal_encoding" → 内部编码
 *
 * PHP 参考: ext/iconv/iconv.c:1820
 */
t_var tphp_fn_iconv_get_encoding(t_string type);

/**
 * iconv_set_encoding(string $type, string $encoding) → bool
 *
 * 设置 iconv 内部配置。
 *
 * PHP 参考: ext/iconv/iconv.c:1780
 */
bool tphp_fn_iconv_set_encoding(t_string type, t_string encoding);

/**
 * iconv_mime_encode(string $field_name, string $field_value,
 *                   array $prefs = []) → string|false
 *
 * 创建 MIME 编码的邮件头字段。
 * $prefs 可选键: "scheme" (B/Q), "input-charset", "output-charset",
 *               "line-length", "line-break-chars"
 *
 * PHP 参考: ext/iconv/iconv.c:1878
 */
t_string tphp_fn_iconv_mime_encode(t_string name, t_string value, t_array *prefs);

/**
 * iconv_mime_decode(string $str, int $mode = 0, string $charset = "UTF-8") → string|false
 *
 * 解码 MIME 头字段。如 "=?UTF-8?B?...?=" 格式。
 *
 * PHP 参考: ext/iconv/iconv.c:1988
 */
t_string tphp_fn_iconv_mime_decode(t_string str, t_int mode, t_string charset);
```

---

## 11. exif

### 推荐参考库

| 库 | 说明 | 链接 |
|----|------|------|
| **PHP 源码 `ext/exif/exif.c`** | 完整 EXIF 解析器 (~200K) | 纯 C 实现，无外部依赖 |
| **libexif** | C 语言 EXIF 标签解析库 (LGPL) | github.com/libexif/libexif |
| **TinyEXIF** | 轻量 C++ EXIF 解析器 (~1000行) | github.com/cdcseacave/TinyEXIF |
| **easyexif** | 极简 C++ EXIF (~600行) | github.com/mayanklahiri/easyexif |
| **EXIF 标准** | CIPA DC-008-2012 (JPEG EXIF 2.3) | cipa.jp/std/std-sec.html |

### 完整 API

```c
// ================================================================
// 函数
// ================================================================

/**
 * exif_read_data(string $filename, string $sections = null,
 *                bool $arrays = false, bool $thumbnail = false) → array|false
 *
 * 读取 JPEG/TIFF 文件的 EXIF 头信息。核心函数。
 *
 * 返回关联数组，包含以下标签组:
 *
 * IFD0 (主图像):
 *   "Make"          → string  相机制造商
 *   "Model"         → string  相机型号
 *   "Orientation"   → int     方向 (1-8): 1=正常 6=顺时针90° 8=逆时针90°
 *   "DateTime"      → string  "2024:01:15 14:30:00"
 *   "Artist"        → string  作者
 *   "Copyright"     → string  版权
 *   "ImageDescription" → string 图片描述
 *
 * EXIF IFD (拍摄参数):
 *   "ExposureTime"       → string  "1/125"
 *   "FNumber"            → string  "f/2.8"
 *   "ISOSpeedRatings"    → int     ISO 值 (100/200/400/...)
 *   "FocalLength"        → string  "50 mm"
 *   "ExposureBiasValue"  → string  曝光补偿
 *   "MeteringMode"       → int     测光模式
 *   "Flash"              → int     闪光灯状态
 *   "WhiteBalance"       → int     白平衡
 *   "ColorSpace"         → int     色彩空间 (1=sRGB, 65535=未校准)
 *   "ExifImageWidth"     → int     图片宽度
 *   "ExifImageLength"    → int     图片高度
 *
 * COMPUTED (计算值):
 *   "Width"           → int
 *   "Height"          → int
 *   "IsColor"         → int     (1=彩色 0=黑白)
 *   "ApertureFNumber" → float   光圈值
 *   "FocusDistance"   → string  对焦距离
 *
 * GPS IFD:
 *   "GPSLatitudeRef"   → string  "N" 或 "S"
 *   "GPSLatitude"       → array   [度,分,秒] (rational 数组)
 *   "GPSLongitudeRef"  → string  "E" 或 "W"
 *   "GPSLongitude"      → array
 *   "GPSAltitudeRef"    → int     (0=海平面以上 1=以下)
 *   "GPSAltitude"       → string  海拔
 *
 * PHP 参考: ext/exif/exif.c:1669
 */
t_array* tphp_fn_exif_read_data(t_string filename, t_string sections,
                                 bool arrays, bool thumbnail);

/**
 * exif_thumbnail(string $filename, int &$width, int &$height,
 *                int &$imagetype) → string|false
 *
 * 读取 JPEG 文件的内嵌缩略图。
 *
 * @param filename   JPEG 文件路径 (t_string)
 * @param width      输出: 缩略图宽度 (int*)
 * @param height     输出: 缩略图高度 (int*)
 * @param imagetype  输出: 缩略图类型 (IMAGETYPE_JPEG/TIFF)
 * @return           缩略图原始二进制数据 (t_string)
 *
 * PHP 参考: ext/exif/exif.c:2392
 */
t_string tphp_fn_exif_thumbnail(t_string filename, t_int *width,
                                 t_int *height, t_int *imagetype);

/**
 * exif_imagetype(string $filename) → int|false
 *
 * 检测图像类型（不读取 EXIF，只看文件头魔数）。
 * 返回 IMAGETYPE_* 常量:
 *   IMAGETYPE_JPEG (2), IMAGETYPE_PNG (3),
 *   IMAGETYPE_GIF (1), IMAGETYPE_BMP (6),
 *   IMAGETYPE_TIFF_II (7), IMAGETYPE_TIFF_MM (8),
 *   IMAGETYPE_WEBP (18)
 *
 * PHP 参考: ext/exif/exif.c:2478
 */
t_int tphp_fn_exif_imagetype(t_string filename);

/**
 * exif_tagname(int $index) → string|false
 *
 * 根据标签编号返回标签名称。
 *   如: exif_tagname(0x010F) → "Make"
 *
 * PHP 参考: ext/exif/exif.c:2507
 */
t_string tphp_fn_exif_tagname(t_int index);
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

---

## 12. ZIP

### 推荐参考库

| 库 | 说明 | 链接 |
|----|------|------|
| **libzip** | C 语言 ZIP 操作库 (BSD 协议) | libzip.org / github.com/nih-at/libzip |
| **PHP 源码 `ext/zip/`** | 完整包装层 | `zip.c` (~90KB) |
| **minizip (zlib contrib)** | zlib 自带的极简 ZIP 库 | zlib/contrib/minizip/ |
| **zip.h (Kuba Podgórski)** | 单头文件 ZIP 库 (MIT, ~1000行) | github.com/kuba--/zip |

### 完整 API

```c
// ================================================================
// 常量
// ================================================================
#define ZIP_CREATE     1    // 创建(若存在则失败)
#define ZIP_EXCL       2    // 排他创建
#define ZIP_CHECKCONS  4    // 检查一致性
#define ZIP_TRUNCATE   8    // 截断(若存在则覆盖)
#define ZIP_RDONLY    16    // 只读
#define ZIP_FL_OVERWRITE  1 // 覆盖现有文件
#define ZIP_FL_NOCASE     2 // 不区分大小写
#define ZIP_FL_NODIR      4 // 不为目录创建条目
#define ZIP_FL_COMPRESSED 8 // 读取压缩数据
#define ZIP_FL_UNCHANGED 16 // 使用原始数据

#define ZIP_CM_DEFAULT  -1  // 默认压缩方法
#define ZIP_CM_STORE     0  // 不压缩
#define ZIP_CM_DEFLATE   8  // DEFLATE 压缩

#define ZIP_ER_OK         0
#define ZIP_ER_MULTIDISK  1
#define ZIP_ER_RENAME     2
#define ZIP_ER_CLOSE      3
#define ZIP_ER_SEEK       4
#define ZIP_ER_READ       5
#define ZIP_ER_WRITE      6
#define ZIP_ER_CRC        7
#define ZIP_ER_ZIPCLOSED  8
#define ZIP_ER_NOENT      9
#define ZIP_ER_EXISTS    10
#define ZIP_ER_OPEN      11
#define ZIP_ER_TMPOPEN   12
#define ZIP_ER_ZLIB      13
#define ZIP_ER_MEMORY    14
#define ZIP_ER_CHANGED   15
#define ZIP_ER_COMPNOTSUPP 16
#define ZIP_ER_EOF       17
#define ZIP_ER_INVAL     18
#define ZIP_ER_NOZIP     19
#define ZIP_ER_INTERNAL  20
#define ZIP_ER_INCONS    21
#define ZIP_ER_REMOVE    22
#define ZIP_ER_DELETED   23
#define ZIP_ER_ENCRNOTSUPP 24
#define ZIP_ER_RDONLY    25
#define ZIP_ER_NOPASSWD   26
#define ZIP_ER_WRONGPASSWD 27
#define ZIP_ER_OPNOTSUPP  28
#define ZIP_ER_INUSE      29
#define ZIP_ER_TELL       30
#define ZIP_ER_COMPRESSED_DATA 31
#define ZIP_ER_CANCELLED  32

// ================================================================
// 函数
// ================================================================

/**
 * zip_open(string $filename, int $flags = 0) → ZipArchive|false
 *
 * 打开 ZIP 文件。混合或条件:
 *   ZIP_CREATE: 创建新文件(不存在时)
 *   ZIP_EXCL:   排他创建(存在则失败)
 *   ZIP_TRUNCATE: 截断已有文件
 *   ZIP_RDONLY: 只读
 *   ZIP_CHECKCONS: 验证一致性
 *
 * PHP 参考: ext/zip/zip.c:583
 */
zip_t* tphp_fn_zip_open(t_string filename, t_int flags);

/**
 * zip_close(resource $zip) → bool
 *
 * 关闭 ZIP 归档，写入所有更改。
 *
 * PHP 参考: ext/zip/zip.c:617
 */
bool tphp_fn_zip_close(zip_t *zip);

/**
 * zip_read(resource $zip) → array
 *
 * 返回 ZIP 中所有文件的列表。
 * 每个条目是关联数组:
 *   "name"  → string  文件名
 *   "index" → int     索引
 *   "size"  → int     原始大小 (字节)
 *   "comp_size" → int 压缩后大小
 *   "comp_method" → int 压缩方法 (0=store 8=deflate)
 *   "mtime" → int     修改时间 (Unix timestamp)
 *
 * PHP 参考: ext/zip/zip.c:720
 */
t_array* tphp_fn_zip_read(zip_t *zip);

/**
 * zip_entry_open(resource $zip, int $index) → bool
 *
 * 打开 ZIP 中的条目准备读取。
 * 为 zip_entry_read 做准备。
 *
 * PHP 参考: ext/zip/zip.c:800
 */
bool tphp_fn_zip_entry_open(zip_t *zip, t_int index);

/**
 * zip_entry_read(resource $zip, int $index, int $length = 0) → string|false
 *
 * 读取 ZIP 中条目的内容。
 * $length=0 → 读取全部。
 *
 * PHP 参考: ext/zip/zip.c:835
 */
t_string tphp_fn_zip_entry_read(zip_t *zip, t_int index, t_int length);

/**
 * zip_entry_close(resource $zip) → bool
 *
 * 关闭当前打开的条目。
 *
 * PHP 参考: ext/zip/zip.c:870
 */
bool tphp_fn_zip_entry_close(zip_t *zip);

/**
 * zip_add_file(resource $zip, string $name, string $data,
 *              int $flags = 0, int $comp_method = ZIP_CM_DEFLATE) → bool
 *
 * 向 ZIP 中添加一个文件。$name 是归档内路径。
 * 如果已存在同名文件，设置 ZIP_FL_OVERWRITE 覆盖。
 *
 * PHP 参考: ext/zip/zip.c:900
 */
bool tphp_fn_zip_add_file(zip_t *zip, t_string name, t_string data,
                          t_int flags, t_int comp_method);

/**
 * zip_add_dir(resource $zip, string $dirname, int $flags = 0) → bool
 *
 * 添加目录。$dirname 以 / 结尾（如 "mydir/"）。
 */
bool tphp_fn_zip_add_dir(zip_t *zip, t_string dirname, t_int flags);

/**
 * zip_delete(resource $zip, int $index) → bool
 *
 * 从 ZIP 中删除指定索引的文件。需要先 zip_close 才写入磁盘。
 */
bool tphp_fn_zip_delete(zip_t *zip, t_int index);

/**
 * zip_rename(resource $zip, int $index, string $new_name) → bool
 *
 * 重命名 ZIP 中的文件。
 */
bool tphp_fn_zip_rename(zip_t *zip, t_int index, t_string new_name);

/**
 * zip_stat(resource $zip, int $index) → array|false
 *
 * 获取单个条目的详细信息（同 zip_read 中单个条目的结构）。
 */
t_array* tphp_fn_zip_stat(zip_t *zip, t_int index);

/**
 * zip_num_files(resource $zip) → int
 *
 * 返回 ZIP 中文件总数。
 */
t_int tphp_fn_zip_num_files(zip_t *zip);

/**
 * zip_get_error_string(resource $zip) → string
 *
 * 返回最后一次错误的描述文本。
 */
t_string tphp_fn_zip_get_error_string(zip_t *zip);
```

---

## 13. MySQL

| 属性 | 值 |
|------|-----|
| **外部依赖** | libmysqlclient 或 libmariadb (~5MB, 系统库或捆绑) |
| **预估行数** | ~600 行 C 包装 |
| **PHP 参考** | `ext/mysqli/mysqli.c` + `ext/mysqlnd/` |
| **难度** | ⭐⭐⭐⭐⭐ |

### 13.1 推荐参考库

| 库 | 说明 | 链接 |
|----|------|------|
| **MySQL C API (libmysqlclient)** | 官方客户端库，LGPL 协议 | dev.mysql.com/doc/c-api/8.0/en/ |
| **MariaDB C API (libmariadb)** | 兼容 MySQL，LGPL 协议，更轻量 | mariadb.com/kb/en/mariadb-connector-c/ |
| **PHP 源码 `ext/mysqli/`** | OO 接口参考 | `mysqli.c` (~150KB) |
| **PHP 源码 `ext/mysqlnd/`** | MySQL Native Driver（不依赖 libmysql） | `mysqlnd/` 目录 |
| **MySQL 协议文档** | 客户端-服务端协议 | dev.mysql.com/doc/dev/mysql-server/latest/PAGE_PROTOCOL.html |

### 13.2 选择：libmysqlclient vs libmariadb vs mysqlnd

| 方案 | 优点 | 缺点 |
|------|------|------|
| **libmysqlclient** (MySQL 官方) | 最完整支持，文档丰富 | ~5MB 体积，MySQL 8.0+ 默认认证机制复杂 (caching_sha2_password) |
| **libmariadb** (MariaDB C Connector) | LGPL 协议，支持 MySQL + MariaDB，体积较小 | API 与 libmysqlclient 基本兼容但有细微差异 |
| **mysqlnd** (PHP 原生) | 零外部依赖，纯 PHP 实现 | 代码量巨大 (~5万行 C)，深度绑定 Zend，不适合 AOT |

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

```c
// ================================================================
// 数据类型常量
// ================================================================
#define MYSQL_TYPE_DECIMAL     0
#define MYSQL_TYPE_TINY        1   // TINYINT: 1 byte
#define MYSQL_TYPE_SHORT       2   // SMALLINT: 2 bytes
#define MYSQL_TYPE_LONG        3   // INT: 4 bytes
#define MYSQL_TYPE_FLOAT       4
#define MYSQL_TYPE_DOUBLE      5
#define MYSQL_TYPE_NULL        6
#define MYSQL_TYPE_TIMESTAMP   7
#define MYSQL_TYPE_LONGLONG    8   // BIGINT: 8 bytes
#define MYSQL_TYPE_INT24       9   // MEDIUMINT
#define MYSQL_TYPE_DATE       10
#define MYSQL_TYPE_TIME       11
#define MYSQL_TYPE_DATETIME   12
#define MYSQL_TYPE_YEAR       13
#define MYSQL_TYPE_VARCHAR    15
#define MYSQL_TYPE_BIT        16
#define MYSQL_TYPE_JSON       245
#define MYSQL_TYPE_NEWDECIMAL 246
#define MYSQL_TYPE_ENUM       247
#define MYSQL_TYPE_SET        248
#define MYSQL_TYPE_TINY_BLOB  249
#define MYSQL_TYPE_MEDIUM_BLOB 250
#define MYSQL_TYPE_LONG_BLOB  251
#define MYSQL_TYPE_BLOB       252
#define MYSQL_TYPE_VAR_STRING 253
#define MYSQL_TYPE_STRING     254
#define MYSQL_TYPE_GEOMETRY   255

// ================================================================
// 连接选项
// ================================================================
#define MYSQL_CLIENT_COMPRESS       32   // 使用压缩协议
#define MYSQL_CLIENT_SSL           2048  // 使用 SSL
#define MYSQL_CLIENT_FOUND_ROWS      2   // 返回匹配行数(非受影响行数)
#define MYSQL_CLIENT_IGNORE_SPACE   256   // 忽略函数名后的空格
#define MYSQL_CLIENT_INTERACTIVE   1024   // 交互式超时

// ================================================================
// fetch 模式
// ================================================================
#define MYSQL_ASSOC  1   // 关联数组
#define MYSQL_NUM    2   // 索引数组
#define MYSQL_BOTH   3   // 关联 + 索引

// ================================================================
// 函数 — 连接管理
// ================================================================

/**
 * mysql_connect(string $host = "localhost", string $user = "",
 *               string $pass = "", string $db = "", int $port = 3306,
 *               string $socket = "", int $flags = 0) → resource|false
 *
 * 打开 MySQL 服务器连接。建议使用持久连接（连接池）。
 *
 * @param host    主机名，可含端口 "host:port" 或 "host:/path/to/socket"
 * @param user    用户名
 * @param pass    密码
 * @param db      默认数据库名 (可选)
 * @param port    端口号 (0=默认3306)
 * @param socket  Unix socket 路径 (Windows下忽略)
 * @param flags   客户端标志组合 (MYSQL_CLIENT_*)
 * @return        连接资源 (MYSQL*)，失败返回 NULL
 *
 * PHP 参考: ext/mysqli/mysqli_nonapi.c:137
 */
MYSQL* tphp_fn_mysql_connect(t_string host, t_string user, t_string pass,
                              t_string db, t_int port, t_string socket, t_int flags);

/**
 * mysql_close(resource $link = null) → bool
 *
 * 关闭 MySQL 连接。$link 为 NULL 时关闭上一次打开的连接。
 *
 * PHP 参考: ext/mysql/php_mysql.c:527
 */
bool tphp_fn_mysql_close(MYSQL *link);

/**
 * mysql_select_db(string $dbname, resource $link = null) → bool
 *
 * 选择默认数据库。
 *
 * PHP 参考: ext/mysql/php_mysql.c:563
 */
bool tphp_fn_mysql_select_db(t_string dbname, MYSQL *link);

/**
 * mysql_ping(resource $link = null) → bool
 *
 * 检查连接是否存活，断开则自动重连。
 *
 * PHP 参考: ext/mysqli/mysqli_nonapi.c:265
 */
bool tphp_fn_mysql_ping(MYSQL *link);

/**
 * mysql_set_charset(string $charset, resource $link = null) → bool
 *
 * 设置客户端字符集。如 "utf8mb4", "latin1"。
 * 等价于 SET NAMES charset
 *
 * PHP 参考: ext/mysqli/mysqli_nonapi.c:292
 */
bool tphp_fn_mysql_set_charset(t_string charset, MYSQL *link);

/**
 * mysql_character_set_name(resource $link = null) → string
 *
 * 返回当前连接的字符集名称。
 */
t_string tphp_fn_mysql_character_set_name(MYSQL *link);

/**
 * mysql_get_host_info(resource $link = null) → string
 * mysql_get_server_info(resource $link = null) → string
 * mysql_get_proto_info(resource $link = null) → int
 *
 * 返回连接元信息
 */
t_string tphp_fn_mysql_get_host_info(MYSQL *link);
t_string tphp_fn_mysql_get_server_info(MYSQL *link);
t_int   tphp_fn_mysql_get_proto_info(MYSQL *link);

// ================================================================
// 函数 — 查询执行
// ================================================================

/**
 * mysql_query(string $sql, resource $link = null) → resource|false
 *
 * 执行 SQL 查询。SELECT/SHOW/DESCRIBE 返回结果集，INSERT/UPDATE/DELETE 等返回 true。
 *
 * @param sql    SQL 语句 (t_string), 不建议用分号结尾
 * @param link   连接资源 (MYSQL*)
 * @return       结果集资源 (MYSQL_RES*)，失败返回 NULL
 *               非 SELECT 语句成功返回 非NULL非MYSQL_RES 的特殊值
 *
 * PHP 参考: ext/mysql/php_mysql.c:750
 */
MYSQL_RES* tphp_fn_mysql_query(t_string sql, MYSQL *link);

/**
 * mysql_unbuffered_query(string $sql, resource $link = null) → resource|false
 *
 * 执行查询但不立即获取所有结果（流式获取，大数据集省内存）。
 * 注意：在读取完所有行前不能执行其他查询。
 */
MYSQL_RES* tphp_fn_mysql_unbuffered_query(t_string sql, MYSQL *link);

// ================================================================
// 函数 — 结果集读取
// ================================================================

/**
 * mysql_fetch_array(resource $result, int $result_type = MYSQL_BOTH) → array|false
 *
 * 从结果集中取一行。$result_type 控制返回格式。
 *   MYSQL_ASSOC → ["id"=>1, "name"=>"Alice"]
 *   MYSQL_NUM   → [0=>1, 1=>"Alice"]
 *   MYSQL_BOTH  → 两者都有
 *
 * 读取完所有行返回 false。
 *
 * PHP 参考: ext/mysql/php_mysql.c:913
 */
t_array* tphp_fn_mysql_fetch_array(MYSQL_RES *result, t_int result_type);

/**
 * mysql_fetch_assoc(resource $result) → array|false
 *
 * 从结果集中取一行关联数组。等价于 mysql_fetch_array($r, MYSQL_ASSOC)
 */
t_array* tphp_fn_mysql_fetch_assoc(MYSQL_RES *result);

/**
 * mysql_fetch_row(resource $result) → array|false
 *
 * 从结果集中取一行索引数组。等价于 mysql_fetch_array($r, MYSQL_NUM)
 */
t_array* tphp_fn_mysql_fetch_row(MYSQL_RES *result);

// ================================================================
// 函数 — 结果集元信息
// ================================================================

/**
 * mysql_num_rows(resource $result) → int|false
 *
 * SELECT 返回的行数。
 *
 * PHP 参考: ext/mysql/php_mysql.c:1111
 */
t_int tphp_fn_mysql_num_rows(MYSQL_RES *result);

/**
 * mysql_num_fields(resource $result) → int|false
 *
 * 结果集中的列数。
 *
 * PHP 参考: ext/mysql/php_mysql.c:1140
 */
t_int tphp_fn_mysql_num_fields(MYSQL_RES *result);

/**
 * mysql_field_name(resource $result, int $index) → string|false
 *
 * 返回指定列索引的字段名。如 mysql_field_name($r, 0) → "id"
 *
 * PHP 参考: ext/mysql/php_mysql.c:1260
 */
t_string tphp_fn_mysql_field_name(MYSQL_RES *result, t_int index);

/**
 * mysql_field_type(resource $result, int $index) → string
 *
 * 返回指定列的 MySQL 类型名。如 "int", "varchar", "datetime"
 */
t_string tphp_fn_mysql_field_type(MYSQL_RES *result, t_int index);

/**
 * mysql_field_len(resource $result, int $index) → int
 *
 * 返回指定列的最大长度。
 */
t_int tphp_fn_mysql_field_len(MYSQL_RES *result, t_int index);

/**
 * mysql_field_flags(resource $result, int $index) → string
 *
 * 返回指定列的标志。如 "not_null primary_key auto_increment"
 */
t_string tphp_fn_mysql_field_flags(MYSQL_RES *result, t_int index);

/**
 * mysql_fetch_lengths(resource $result) → array|false
 *
 * 返回当前行各列值的长度数组（字节数）。
 */
t_array* tphp_fn_mysql_fetch_lengths(MYSQL_RES *result);

/**
 * mysql_field_seek(resource $result, int $offset) → bool
 *
 * 设置字段指针到指定偏移（用于 mysql_fetch_field）。
 */
bool tphp_fn_mysql_field_seek(MYSQL_RES *result, t_int offset);

// ================================================================
// 函数 — 影响行数 / 错误处理
// ================================================================

/**
 * mysql_affected_rows(resource $link = null) → int
 *
 * 返回上一次 INSERT/UPDATE/DELETE 影响的行数。
 * REPLACE 删除+插入计为 2。
 *
 * PHP 参考: ext/mysql/php_mysql.c:1080
 */
t_int tphp_fn_mysql_affected_rows(MYSQL *link);

/**
 * mysql_insert_id(resource $link = null) → int
 *
 * 返回上一次 INSERT 的 AUTO_INCREMENT ID。
 *
 * PHP 参考: ext/mysql/php_mysql.c:1094
 */
t_int tphp_fn_mysql_insert_id(MYSQL *link);

/**
 * mysql_errno(resource $link = null) → int
 *
 * 返回上一次操作的错误码。
 *
 * PHP 参考: ext/mysql/php_mysql.c:865
 */
t_int tphp_fn_mysql_errno(MYSQL *link);

/**
 * mysql_error(resource $link = null) → string
 *
 * 返回上一次操作的错误消息。
 *
 * PHP 参考: ext/mysql/php_mysql.c:883
 */
t_string tphp_fn_mysql_error(MYSQL *link);

/**
 * mysql_info(resource $link = null) → string|false
 *
 * 返回关于最近一条查询的详细信息。
 * 如 "Records: 5  Duplicates: 0  Warnings: 0"
 */
t_string tphp_fn_mysql_info(MYSQL *link);

// ================================================================
// 函数 — 数据转义
// ================================================================

/**
 * mysql_real_escape_string(string $str, resource $link = null) → string|false
 *
 * 转义 SQL 特殊字符（\x00, \n, \r, \, ', ", \x1a）防注入。
 * 必须 v0.0.8 使用当前连接字符集。
 *
 * PHP 参考: ext/mysql/php_mysql.c:933
 */
t_string tphp_fn_mysql_real_escape_string(t_string str, MYSQL *link);

/**
 * mysql_escape_string(string $str) → string
 *
 * 转义 SQL 特殊字符（不使用连接字符集，不推荐）。
 * 推荐使用 mysql_real_escape_string。
 *
 * PHP 参考: ext/mysql/php_mysql.c:955
 */
t_string tphp_fn_mysql_escape_string(t_string str);

// ================================================================
// 函数 — 事务
// ================================================================

/**
 * mysql_begin_transaction(resource $link, int $flags = 0) → bool
 *
 * 开始事务。等价于 mysql_query("START TRANSACTION").
 *
 * @param flags  MYSQL_TRANS_START_WITH_CONSISTENT_SNAPSHOT (1)
 *               MYSQL_TRANS_START_READ_WRITE (2)
 *               MYSQL_TRANS_START_READ_ONLY (4)
 */
bool tphp_fn_mysql_begin_transaction(MYSQL *link, t_int flags);

/**
 * mysql_commit(resource $link, int $flags = 0) → bool
 *
 * 提交事务。
 */
bool tphp_fn_mysql_commit(MYSQL *link, t_int flags);

/**
 * mysql_rollback(resource $link, int $flags = 0) → bool
 *
 * 回滚事务。
 */
bool tphp_fn_mysql_rollback(MYSQL *link, t_int flags);

// ================================================================
// 函数 — 连接池 / 清理
// ================================================================

/**
 * mysql_free_result(resource $result) → bool
 *
 * 释放结果集内存。
 *
 * PHP 参考: ext/mysql/php_mysql.c:810
 */
bool tphp_fn_mysql_free_result(MYSQL_RES *result);

/**
 * mysql_data_seek(resource $result, int $row) → bool
 *
 * 移动结果集指针到指定行。
 *
 * PHP 参考: ext/mysql/php_mysql.c:1190
 */
bool tphp_fn_mysql_data_seek(MYSQL_RES *result, t_int row);
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

---

## 14. PDO

| 属性 | 值 |
|------|-----|
| **外部依赖** | 按驱动: pdo_mysql→libmariadb, pdo_sqlite→sqlite3 |
| **预估行数** | ~700 行 C (核心+驱动分派) |
| **PHP 参考** | `ext/pdo/pdo.c` + `ext/pdo_mysql/` + `ext/pdo_sqlite/` |
| **难度** | ⭐⭐⭐⭐⭐ |

### 14.1 为什么 PDO 可以做

之前标记"深度绑定 Zend"过于保守。逐组件分析：

| 组件 | Zend 依赖 | AOT 方案 |
|------|----------|---------|
| `PDO` 类 | `zend_class_entry` 注册 | TinyPHP COS class 系统 |
| `PDOStatement` 类 | 同上 | COS class |
| `PDOException` | Zend exception | 已有 `Exception` 支持 |
| `prepare()` 占位符 | 纯字符串 `:name`→`?` 替换 | ~80 行 C |
| `bindValue()` | t_var 类型标记 | 已有类型系统 |
| `execute()` | 驱动分发 | 函数指针表 `pdo_driver` |
| `fetch()` / `fetchAll()` | t_array 填充 | 已有 array API |
| 驱动层 (MySQL/SQLite) | 委托 C 库 | libmariadb / sqlite3 |

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

| 函数 | 说明 |
|------|------|
| `__construct($dsn, $user, $pass, $opts)` | 解析 DSN→查找驱动→连接 |
| `prepare($sql)` → PDOStatement | 占位符解析→驱动 prepare |
| `query($sql, $fetchMode)` → PDOStatement | 快捷: prepare+execute |
| `exec($sql)` → int | 执行无结果 SQL, 返回影响行数 |
| `beginTransaction() / commit() / rollBack()` | 委托驱动事务 |
| `lastInsertId($name?)` → string | AUTO_INCREMENT 返回值 |
| `quote($str, $type)` → string | 驱动级转义, 防注入 |
| `setAttribute($attr, $val) / getAttribute($attr)` | 配置选项 |

### 14.5 PDOStatement 类接口 (6 函数)

| 函数 | 说明 |
|------|------|
| `bindValue($param, $value, $type)` | `:name`→位置映射, 存储值 |
| `execute($params?)` → bool | 绑定参数→驱动 execute |
| `fetch($mode=ASSOC)` → array\|false | 取一行→关联数组 |
| `fetchAll($mode=ASSOC)` → array | 循环 fetch→全部行 |
| `fetchColumn($col=0)` → mixed | 取第一行指定列 |
| `rowCount() / columnCount()` → int | 元信息 |

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

---

## 15. GD — 图像处理

| 属性 | 值 |
|------|-----|
| **外部依赖** | libgd + libpng + libjpeg (~2MB 静态库) |
| **预估行数** | ~600 行 C 包装 |
| **PHP 参考** | `ext/gd/gd.c` (~200KB) + `libgd/gd.c` |
| **难度** | ⭐⭐⭐⭐⭐ |

### 15.1 为什么 GD 可以做

之前标记为"不适合 AOT"也是过于保守。libgd 本身就是纯 C 库（MIT 协议），libpng 和 libjpeg 也是标准 C 库。三者都可以静态编译捆绑进 PHAR。

| 组件 | 依赖 | 捆绑体积 |
|------|------|---------|
| libgd | 无 (自包含) | ~300KB |
| libpng | zlib (已有) | ~200KB |
| libjpeg | 无 | ~300KB |
| libfreetype (可选) | 无 | ~500KB (文字渲染) |
| **合计最小集** | gd+png+jpeg | **~800KB** |

### 15.2 推荐参考库

| 库 | 说明 | 链接 |
|----|------|------|
| **libgd** | 官方 C 图像库 (MIT) | github.com/libgd/libgd |
| **PHP 源码 `ext/gd/gd.c`** | 完整包装层 | ~200KB |
| **libpng** | PNG 编解码 | libpng.org |
| **libjpeg-turbo** | JPEG 编解码 (性能更好) | github.com/libjpeg-turbo/libjpeg-turbo |

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

```c
// ── 创建 / 销毁 ──
gdImagePtr tphp_fn_imagecreate(t_int w, t_int h);
// 创建调色板图像 (256 色, GIF 风格)
gdImagePtr tphp_fn_imagecreatetruecolor(t_int w, t_int h);
// 创建真彩色图像 (16M 色, PNG/JPEG 风格)
void tphp_fn_imagedestroy(gdImagePtr im);

// ── 从文件加载 ──
gdImagePtr tphp_fn_imagecreatefrompng(t_string path);
gdImagePtr tphp_fn_imagecreatefromjpeg(t_string path);
gdImagePtr tphp_fn_imagecreatefromgif(t_string path);
// 检测类型自动加载
gdImagePtr tphp_fn_imagecreatefromstring(t_string data);

// ── 输出到文件 / 浏览器 ──
bool tphp_fn_imagepng(gdImagePtr im, t_string path, t_int quality);
// quality: 0-9 (PNG 压缩级别, 0=无压缩)
bool tphp_fn_imagejpeg(gdImagePtr im, t_string path, t_int quality);
// quality: 0-100 (JPEG, 默认 75)
bool tphp_fn_imagegif(gdImagePtr im, t_string path);
// 直接输出到 stdout (用于 HTTP 响应)
void tphp_fn_imagepng_stdout(gdImagePtr im);
void tphp_fn_imagejpeg_stdout(gdImagePtr im, t_int quality);

// ── 颜色分配 ──
int  tphp_fn_imagecolorallocate(gdImagePtr im, t_int r, t_int g, t_int b);
int  tphp_fn_imagecolorallocatealpha(gdImagePtr im, t_int r, t_int g, t_int b, t_int alpha);
// 返回颜色索引 (调色板) 或颜色值 (真彩色)
// 第一个分配的颜色自动成为背景色

// ── 图形绘制 ──
bool tphp_fn_imageline(gdImagePtr im, t_int x1, t_int y1, t_int x2, t_int y2, t_int color);
bool tphp_fn_imagerectangle(gdImagePtr im, t_int x1, t_int y1, t_int x2, t_int y2, t_int color);
bool tphp_fn_imagefilledrectangle(gdImagePtr im, t_int x1, t_int y1, t_int x2, t_int y2, t_int color);
bool tphp_fn_imageellipse(gdImagePtr im, t_int cx, t_int cy, t_int w, t_int h, t_int color);
bool tphp_fn_imagefilledellipse(gdImagePtr im, t_int cx, t_int cy, t_int w, t_int h, t_int color);
bool tphp_fn_imagepolygon(gdImagePtr im, t_array *points, t_int n, t_int color);
// points: [x0, y0, x1, y1, ...]
bool tphp_fn_imagesetpixel(gdImagePtr im, t_int x, t_int y, t_int color);

// ── 图像信息 ──
t_int tphp_fn_imagesx(gdImagePtr im);    // 宽度
t_int tphp_fn_imagesy(gdImagePtr im);    // 高度

// ── 变换 ──
bool tphp_fn_imagecopy(gdImagePtr dst, gdImagePtr src,
    t_int dx, t_int dy, t_int sx, t_int sy, t_int sw, t_int sh);
bool tphp_fn_imagecopyresized(gdImagePtr dst, gdImagePtr src,
    t_int dx, t_int dy, t_int sx, t_int sy, t_int dw, t_int dh, t_int sw, t_int sh);
bool tphp_fn_imagecopyresampled(gdImagePtr dst, gdImagePtr src,
    t_int dx, t_int dy, t_int sx, t_int sy, t_int dw, t_int dh, t_int sw, t_int sh);
// resampled: 双线性插值 (比 resized 质量好)
gdImagePtr tphp_fn_imagerotate(gdImagePtr im, t_float angle, t_int bgcolor);

// ── 文字 (可选, 需 libfreetype) ──
array  tphp_fn_imagettftext(gdImagePtr im, t_float size, t_float angle,
    t_int x, t_int y, t_int color, t_string fontfile, t_string text);
// 返回包围盒: [x1,y1, x2,y2, x3,y3, x4,y4]
// 不需要 freetype 的基础文字:
bool tphp_fn_imagestring(gdImagePtr im, t_int font, t_int x, t_int y,
    t_string s, t_int color);
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

---

## 实现优先级

| # | 扩展 | 行数 | 难度 | 依赖 | 函数数 | 典型耗时 |
|---|------|------|------|------|--------|---------|
| 1 | bcrypt | ~350 | ⭐⭐ ✅ 已完成 | 无 | 2 | ✅ 已完成 |
| 2 | filter_var | ~400 | ⭐⭐ | 无 | 4 | 1 天 |
| 3 | calendar | ~1000 | ⭐⭐⭐ | 无 | 18 | 2-3 天 |
| 4 | zlib | ~200 | ⭐⭐⭐ | zlib | 6 | 半天 |
| 5 | PCRE2 preg_* | ~400 | ⭐⭐⭐⭐ | pcre2 | 7 | 2-3 天 |
| 6 | SQLite | ~500 | ⭐⭐⭐⭐ | sqlite3 | 8 | 1-2 天 |
| 7 | cURL | ~300 | ⭐⭐⭐⭐ | libcurl | 8 | 2-3 天 |
| 8 | OpenSSL | ~500 | ⭐⭐⭐⭐⭐ | openssl | 8 | 3-5 天 |
| 9 | fileinfo | ~200 | ⭐⭐⭐ | libmagic | 6 | 1 天 |
| 10 | iconv | ~200 | ⭐⭐⭐ | libiconv/系统 | 7 | 1 天 |
| 11 | exif | ~800 | ⭐⭐⭐ | 无(纯解析) | 4 | 2-3 天 |
| 12 | ZIP | ~400 | ⭐⭐⭐⭐ | libzip+zlib | 12 | 2-3 天 |
| 13 | MySQL | ~600 | ⭐⭐⭐⭐⭐ | libmariadb | 16 | 3-5 天 |
| 14 | PDO | ~700 | ⭐⭐⭐⭐⭐ | 按驱动 | 16 | 3-5 天 |
| 15 | GD | ~600 | ⭐⭐⭐⭐⭐ | libgd+png+jpeg | 20 | 3-5 天 |
