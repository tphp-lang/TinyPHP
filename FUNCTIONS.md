# TinyPHP 内置函数参考

> 按 PHP 扩展结构分类，含实现差异与性能说明。

---

## 总览

| PHP 扩展 | 对应 TinyPHP 文件 | 函数数 |
|----------|------------------|--------|
| `include/standard` 输出/类型/字符串 | `std/core.h` (合并 output+type+string) | 67 |
| `include/standard` HTML/Base64/URL | `std/html.h` | 6 |
| `include/standard` 数组 | `array.h` + `std/array_extra.h` | 41 |
| `include/standard` 数学 | `std/math.h` + `tphp_math.h` | 21 |
| `include/standard` 进制转换 | `conv.h` | 8 |
| `include/standard` 断言/随机 | `std/ctrl.h` | 5 |
| `include/json` | `os/json.h` | 3 |
| `include/hash` | `hash.h` | 5 |
| `include/date` | `os/times.h` | 9 |
| `include/ctype` | `std/ctrl.h` | 11 |
| `include/mbstring` (UTF-8) | `std/utf8.h` | 3 |
| `include/iconv` (字符集转换) | `iconv.h` | 8 |
| `ext/pcntl` | `ext/pcntl/` | 7 |
| `ext/posix` | `ext/posix/` | 14 |
| `ext/pcre` | `ext/pcre/` | 8 |
| `include/filter` | `filter.h` | 3 |
| `include/password` (bcrypt) | `os/password.h` | 2 |
| `ext/exif` (纯 phpc) | `ext/exif/src/exif.php` | 8 |
| `ext/calendar` (纯 tphp) | `ext/calendar/src/calendar.php` | 16 |
| `include/fileinfo` (MIME 检测) | `fileinfo.h` | 6 |
| `ext/stream` (socket stream) | `ext/stream/src/stream.h` | 21 |
| `ext/openssl` (TLS/加密) | `ext/openssl/src/openssl.h` | 21 |
| `ext/pdo` (PDO 统一 API + SQLite 驱动) | `ext/pdo/pdo.h` + `ext/pdo/src/pdo.php` | 33 |
| `ext/pdo_mysql` (MySQL 驱动，纯 C 协议) | `ext/pdo_mysql/pdo_mysql.h` | 0（复用 PDO API） |
| `ext/sqlite3` (函数式 SQLite) | `ext/sqlite3/sqlite3.h` + `ext/sqlite3/src/sqlite3.php` | 11 |
| `ext/pgsql` (PostgreSQL，纯 C 协议) | `ext/pgsql/src/pgsql.php` + `pgsql_constants.php` | 78 |
| `ext/pdo_pgsql` (PostgreSQL PDO 驱动) | `ext/pdo_pgsql/src/pdo_pgsql.php` | 3 |
| OOP / 异常 / Resource | `object/` | 14 |
| Generator / yield | `object/generator.h` + `minicoro.h` | 7 |
| 多线程 (Thread/Mutex/CondVar/WaitGroup) | `object/thread.h` + `compat/tinycthread.h` + `compat/tls.h` | 15 |
| 异步与协程 (Channel/Future/chan_select) | `object/channel.h` | 20 |
| C 互操作 (PHPC) | `phpc.h` | 40 |
| `ext/ui` (图形界面，基于 sokol) | `ext/ui/src/ui*.php` + `ui.h` | 9 类 + 9 枚举 |
| **合计** | | **450+** |

---

## C 标识符命名规范

| 场景 | 格式 | 示例 |
|------|------|------|
| 全局类 | `tphp_class_Name` | `tphp_class_Main` |
| 全局函数 | `tphp_fn_name` | `tphp_fn_hello` |
| 全局枚举 | `tphp_enum_Name` | `tphp_enum_Color` |
| 命名空间类 | `tphp_na_Ns_tphp_class_Name` | `tphp_na_Demo_Hello_tphp_class_MyClass` |
| 命名空间函数 | `tphp_na_Ns_tphp_fn_name` | `tphp_na_Demo_Hello_tphp_fn_greet` |
| 命名空间枚举 | `tphp_na_Ns_tphp_enum_Name` | `tphp_na_Colors_tphp_enum_Status` |
| 常量 | `TPHP_CONST_NAME` | `TPHP_CONST_PI` |
| 重载函数 | `tphp_fn_name_N` | `tphp_fn_add_1` (缺少 1 个默认值参数) |

---

## 函数默认值参数

> TinyPHP 支持函数参数默认值，采用**编译时重载**策略，零运行时开销。

### 语法

```php
function add(int $a, int $b = 10): int {
    return $a + $b;
}
```

### 规则

- 有默认值的参数必须放在参数列表末尾（与 PHP 原生一致）
- 默认值支持所有基本类型：`int`、`float`、`string`、`bool`
- 支持负数和表达式作为默认值
- **不支持** `callable` 类型作为默认值（编译时无法将字符串函数名转换为函数指针）

### 编译策略

编译器为每个有默认值的函数生成重载版本：

```php
// PHP 源码
function add(int $a, int $b = 10): int {
    return $a + $b;
}
echo add(5);     // 使用默认值
echo add(5, 20); // 覆盖默认值
```

生成的 C 代码：

```c
// 重载版本：缺少 1 个参数
static t_int tphp_fn_add_1(t_int a) {
    return tphp_fn_add(a, 10);
}

// 完整版本
static t_int tphp_fn_add(t_int a, t_int b) {
    return (a + b);
}

// 调用时自动选择
tphp_fn_add_1(5);      // add(5)
tphp_fn_add(5, 20);    // add(5, 20)
```

### 示例

```php
// 单个默认值
function greet(string $name, string $greeting = "hello"): string {
    return $greeting . " " . $name;
}
greet("world");          // "hello world"
greet("world", "hi");   // "hi world"

// 多个默认值
function calc(int $a, int $b = 5, int $c = 10): int {
    return $a + $b + $c;
}
calc(100);       // 115 (100 + 5 + 10)
calc(100, 20);   // 130 (100 + 20 + 10)
calc(100, 20, 30); // 150 (100 + 20 + 30)
```

---

## standard — 输出函数

> 文件: `std/core.h`

| php函数 | tphp函数 | 性能说明 | 差异说明 |
|------|--------|------|------|
| `echo $expr` | `echo $expr` | `fwrite(stdout)` + 每次调用 `fflush`，二进制安全，不解析 `%` 格式化符；O(n) 零堆分配 | PHP 支持 `echo $a, $b` 多参数语法，tphp 单参数（多参数由编译器展开为多次调用） |
| `var_dump(mixed $value, mixed ...$rest): void` | `var_dump(mixed $value): void` | type switch → `fprintf`/`fwrite` 递归输出，O(节点数)，零中间缓冲 | 单参数；浮点 `%g`（6 位有效数字）非 PHP `%.14G`（14 位）；普通对象输出 `object(ClassName)#<id>`（无属性列表）；stdClass 输出完整属性列表 `object(stdClass)#<id> (count) { ["k"]=> val ... }` |
| `print_r(mixed $value, bool $return = false): string\|true` | `print_r(mixed $value): void` | 递归格式化，O(节点数)，流式写入 stdout | **无 `$return` 参数**，始终返回 void；对象仅输出 `ClassName Object` 无属性；无循环引用检测（递归数组会栈溢出） |
| `exit(int\|string $status): void` | `exit(int $code): void` | `exit(code)` 单次 libc 调用，O(1) | 仅接受 int（PHP 还接受 string 消息）；无 shutdown 回调 |
| `isset(mixed $var, mixed ...$rest): bool` | `isset(mixed $var): bool` | 指针类型 → `ptr != NULL`；值类型 → 编译期 `true`；O(1) | 单参数；语义为指针 NULL 检查（值类型如 int/string 永远返回 true）；`isset($obj->prop)` 对 stdClass 检查属性存在且值非 null（与 PHP 一致） |
| `empty(mixed $var): bool` | `empty(mixed $var): bool` | 按类型分发内联：int→`==0`、string→`is_falsy`、float/bool/null 同；O(1) | — |

---

## standard — 类型函数

> 文件: `std/core.h`

### 类型检测

| php函数 | tphp函数 | 性能说明 | 差异说明 |
|------|--------|------|------|
| `is_int(mixed $value): bool` | `is_int(mixed $value): bool` | 静态类型编译期折叠为字面量 `true`/`false`；运行时 `v.type==TYPE_INT` | — |
| `is_float(mixed $value): bool` | `is_float(mixed $value): bool` | 同上，`v.type==TYPE_FLOAT` | — |
| `is_string(mixed $value): bool` | `is_string(mixed $value): bool` | 同上，`v.type==TYPE_STRING` | — |
| `is_bool(mixed $value): bool` | `is_bool(mixed $value): bool` | 同上，`v.type==TYPE_BOOL` | — |
| `is_array(mixed $value): bool` | `is_array(mixed $value): bool` | 同上，`v.type==TYPE_ARRAY` | — |
| `is_null(mixed $value): bool` | `is_null(mixed $value): bool` | 同上，`v.type==TYPE_NULL` | — |
| `is_object(mixed $value): bool` | `is_object(mixed $value): bool` | 同上，`v.type==TYPE_OBJECT` | — |
| `is_callable(mixed $value): bool` | `is_callable(mixed $value): bool` | 同上，`v.type==TYPE_CALLBACK` | 仅识别 Closure，不识别字符串/数组回调名 |
| `is_resource(mixed $value): bool` | `is_resource(mixed $value): bool` | 编译期 `tphp_class_` 类型 → `true`；运行时 `tp_obj_is_a` 检查继承链 | — |
| `is_numeric(mixed $value): bool` | `is_numeric(string $value): bool` | null-terminated 副本 + `strtoll`/`strtod` 扫描，`end==buf+len` 才 true | 仅字符串入参（PHP 接受 mixed）；函数名 `is_numeric_str` |
| `gettype(mixed $value): string` | `gettype(mixed $value): string` | 静态 `names[]` 表查表，O(1) | callable 返回 `"object"`；返回 static 缓冲非拷贝 |

### 类型转换

| php函数 | tphp函数 | 性能说明 | 差异说明 |
|------|--------|------|------|
| `intval(mixed $value, int $base = 10): int` | `intval(mixed $value): int` | type switch → cast，string 走 `tphp_rt_parse_int`，O(1) | 无 `$base` 参数 |
| `floatval(mixed $value): float` | `floatval(mixed $value): float` | type switch → cast，O(1) | — |
| `strval(mixed $value): string` | `strval(mixed $value): string` | int→`str_from_int`，float→`str_from_float`，bool→`"1"`/`""` | NULL 返回 data=NULL 的空串 |
| `boolval(mixed $value): bool` | `boolval(mixed $value): bool` | int→`!=0`，float→`!=0.0`，string→`!is_falsy`，O(1) | — |

### 环境变量

| php函数 | tphp函数 | 性能说明 | 差异说明 |
|------|--------|------|------|
| `getenv(string $name, bool $local_only = false): string\|false` | `getenv(string $name): string` | libc `getenv()` + 复制到线程局部 `str_pool` | 未找到返回 NULL 串（非 false）；key 截断 255；线程安全（P3-7） |
| `putenv(string $assignment): bool` | `putenv(string $assignment): void` | 复制到 static 缓冲 + libc `putenv()` | 返回 void（PHP 返回 bool）；key 截断 1023 |

---

## standard — 字符串函数

> 文件: `std/core.h`

字符串为 16 字节 SSO 值类型 `{ char* data; int length; bool is_local; }`。
≤23 字节内联存储（SSO），≤512B 通过 128KB bump allocator 分配，零 `malloc`。
拼接优化：3+ 片段 `.` 链编译期展平为 ROPE，单次分配。

### 基础操作

| php函数 | tphp函数 | 性能说明 | 差异说明 |
|------|--------|------|------|
| `strlen(string $string): int` | `strlen(string $string): int` | 返回 `s.length`，O(1) | null → 0 |
| `trim(string $string, string $characters = " \t\n\r\v\f"): string` | `trim(string $string): string` | 双向扫描，无空白时零分配，O(n) | 仅 ASCII 空白（`<= ' '`）；无 `$characters` 参数 |
| `ltrim(string $string, string $characters = " \t\n\r\v\f"): string` | `ltrim(string $string): string` | 左扫描，无空白时零分配，O(n) | 同 `trim` |
| `rtrim(string $string, string $characters = " \t\n\r\v\f"): string` | `rtrim(string $string): string` | 右扫描，无空白时零分配，O(n) | 同 `trim` |
| `substr(string $string, int $offset, ?int $length = null): string` | `substr(string $string, int $offset, int $length): string` | 偏移截取，全复制时零分配 | `$length` 必传（`0` 表示到末尾）；越界返回空串 |
| `strpos(string $haystack, string $needle, int $offset = 0): int\|false` | `strpos(string $haystack, string $needle): int` | `memcmp` 线性查找，O(n) | 未找到返回 `-1`（非 `false`）；无 `$offset` 参数；空 needle 返回 `0` |
| `strrpos(string $haystack, string $needle, int $offset = 0): int\|false` | `strrpos(string $haystack, string $needle): int` | `memcmp` 从右往左，O(n) | 未找到返回 `-1`（非 `false`）；无 `$offset` 参数；空 needle 返回 `haystack.length` |
| `stripos(string $haystack, string $needle, int $offset = 0): int\|false` | `stripos(string $haystack, string $needle): int` | ASCII 大小写折叠后 `memcmp`，O(n) | 未找到返回 `-1`（非 `false`）；无 `$offset` 参数；空 needle 返回 `0`；仅 ASCII A-Z/a-z |
| `strripos(string $haystack, string $needle, int $offset = 0): int\|false` | `strripos(string $haystack, string $needle): int` | ASCII 大小写折叠后从右往左 `memcmp`，O(n) | 未找到返回 `-1`（非 `false`）；无 `$offset` 参数；空 needle 返回 `haystack.length`；仅 ASCII A-Z/a-z |
| `str_contains(string $haystack, string $needle): bool` | `str_contains(string $haystack, string $needle): bool` | 委托 `strpos >= 0`，O(n) | — |
| `str_starts_with(string $haystack, string $needle): bool` | `str_starts_with(string $haystack, string $needle): bool` | 单次 `memcmp` 前缀，O(len(needle)) | — |
| `str_ends_with(string $haystack, string $needle): bool` | `str_ends_with(string $haystack, string $needle): bool` | 单次 `memcmp` 后缀，O(len(needle)) | — |
| `ord(string $string): int` | `ord(string $string): int` | 返回首字节 `(unsigned char)`，O(1) | 空串返回 `0` |
| `chr(int $codepoint): string` | `chr(int $codepoint): string` | `str_pool_alloc(2)` 写入字节，O(1) | 线程安全（P3-7） |

### 转换 / 格式化

| php函数 | tphp函数 | 性能说明 | 差异说明 |
|------|--------|------|------|
| `strtolower(string $string): string` | `strtolower(string $string): string` | A-Z → +32，先扫描 changed 决定是否分配，O(n) | 仅 ASCII（PHP 支持 Unicode） |
| `strtoupper(string $string): string` | `strtoupper(string $string): string` | a-z → -32，O(n) | 仅 ASCII |
| `ucfirst(string $string): string` | `ucfirst(string $string): string` | 首字符 a-z → -32，O(1) | 仅 ASCII 首字节 |
| `lcfirst(string $string): string` | `lcfirst(string $string): string` | 首字符 A-Z → +32，O(1) | 仅 ASCII 首字节 |
| `sprintf(string $format, mixed ...$values): string` | `sprintf(string $format, mixed ...$values): string` | CodeGenerator 编译期内联 `snprintf(NULL,0)` 测长→`str_pool_alloc`→`snprintf` | 类型映射：string→`.data`，float→`(double)`，其余→`(int)` |
| `number_format(float $num, int $decimals = 0, string $decimal_separator = ".", string $thousands_separator = ","): string` | `number_format(float $num): string` | 手工舍入 + 千分位逗号，O(log n) | 仅 1 参（无 `$decimals`/分隔符参数）；小数部分硬编码 `.` |

### 搜索 / 替换

| php函数 | tphp函数 | 性能说明 | 差异说明 |
|------|--------|------|------|
| `str_replace(array\|string $search, array\|string $replace, array\|string $subject, int &$count = null): array\|string` | `str_replace(string $search, string $replace, string $subject): string` | 两遍扫描 + `str_pool_alloc`，O(n) | 无 `$count` 参数；数组参数变体由编译器展开 |
| `substr_count(string $haystack, string $needle, int $offset = 0, ?int $length = null): int` | `substr_count(string $haystack, string $needle): int` | `memcmp` 暴力计数，O(n) | 无 `$offset`/`$length` 参数 |
| `strtr(string $string, array\|string $from, ?string $to = null): string` | `strtr(string $string, string $from, string $to): string` | 预建 128 字节 map，仅 ASCII 0-127，O(n) | 仅三参形式；不支持关联数组形式；非 ASCII 原样保留；函数名 `strtr2` |

### 数组 ↔ 字符串

| php函数 | tphp函数 | 性能说明 | 差异说明 |
|------|--------|------|------|
| `implode(string $separator, array $array): string` | `implode(string $separator, array $array): string` | 两遍：算总长→分配→memcpy，O(n) | 仅支持 string/int/float 元素 |
| `explode(string $separator, string $string, ?int $limit = null): array` | `explode(string $separator, string $string): array` | 预算 pieceCount→精确分配→逐段 push，O(n) | 无 `$limit` 参数；空 separator 返回单元素数组 |

### 工具函数

| php函数 | tphp函数 | 性能说明 | 差异说明 |
|------|--------|------|------|
| `str_repeat(string $string, int $times): string` | `str_repeat(string $string, int $times): string` | 一次分配 + 循环 memcpy，O(len×n) | `$times < 0` 抛错；上限 0x3FFFFF |
| `str_split(string $string, int $length = 1): array` | `str_split(string $string, int $length): array` | 逐段切片 → 数组，O(n) | `$length` 必传（无默认值 1）；`< 1` 抛错 |
| `str_pad(string $string, int $length, string $pad_string = " ", int $pad_type = STR_PAD_RIGHT): string` | `str_pad(string $string, int $length, string $pad_string, int $pad_type): string` | 计算填充 + memcpy，O(len) | 4 参数必传；`pad_type`: 0=RIGHT/1=LEFT/2=BOTH |
| `strrev(string $string): string` | `strrev(string $string): string` | 逐字节倒序复制，O(n) | — |
| `str_shuffle(string $string): string` | `str_shuffle(string $string): string` | 复制后 Fisher-Yates 洗牌，O(n) | 用 `rand_int`（非 CSPRNG） |
| `addslashes(string $string): string` | `addslashes(string $string): string` | 两遍扫描 → 无转义时零分配，O(n) | 转义 `'` `"` `\` |
| `stripslashes(string $string): string` | `stripslashes(string $string): string` | 两遍扫描，O(n) | `\` 后跟任意字符去掉 `\` |
| `bin2hex(string $string): string` | `bin2hex(string $string): string` | 查表 `0-9a-f` → 双倍输出，O(n) | 输出小写 hex |
| `hex2bin(string $string): string` | `hex2bin(string $string): string` | 每 2 字符解码为 1 字节，O(n) | 奇数长度/非 hex 字符抛错 |

---

## standard — HTML / Base64 / URL

> 文件: `std/html.h`

| php函数 | tphp函数 | 性能说明 | 差异说明 |
|------|--------|------|------|
| `htmlspecialchars(string $string, int $flags = ENT_QUOTES\|ENT_SUBSTITUTE\|ENT_HTML401, ?string $encoding = null, bool $double_encode = true): string` | `htmlspecialchars(string $string): string` | 两趟法：计长度→一次分配→memcpy | 无 `$flags`/`$encoding`/`$double_encode` 参数；单引号用 `&#039;` |
| `nl2br(string $string, bool $use_xhtml = true): string` | `nl2br(string $string): string` | 两趟法：计换行数→一次分配 | 仅处理 `\n`；无 `$use_xhtml` 参数；输出固定 `<br>`（非 `<br />`） |
| `base64_encode(string $string): string` | `base64_encode(string $string): string` | 查找表法，3→4 字符，RFC 4648，自动补 `=` | — |
| `base64_decode(string $string, bool $strict = false): string\|false` | `base64_decode(string $string): string` | 256B 逆查找表，跳过尾部 `=` | 遇非法字符 `break`（非返回 `false`）；无 `$strict` 参数 |
| `urlencode(string $string): string` | `urlencode(string $string): string` | 非安全字符 → `%XX`（大写 hex），全安全时零分配 | 空格→`%20`；安全字符含 `~` |
| `urldecode(string $string): string` | `urldecode(string $string): string` | `%XX`→字符 + `+`→空格 | — |
| `parse_url(string $url, int $component = -1): array\|string\|int\|false\|null` | `parse_url(string $url): array` | URL 解析 → 关联数组 | 无 `$component` 参数；不支持 user/pass/fragment；port 存为字符串 |
| `parse_str(string $string, array &$result): void` | `parse_str(string $string): array` | 按 `&` 分割，`%XX` 解码、`+`→空格，找 `=` 拆 key/val | 返回数组（PHP 是 byRef 写入变量）；不支持嵌套键 `a[b]=c`；每段截断 255 字节 |
| `http_build_query(array\|object $data, string $numeric_prefix = "", string $arg_separator = null, int $encoding_type = PHP_QUERY_RFC1738): string` | `http_build_query(array $data): string` | 遍历数组 + `urlencode`，key=value 用 `&` 连接 | 无 `$numeric_prefix`/`$arg_separator`/`$encoding_type` 参数；bool 值输出 `"1"`/`"0"` |

---

## standard — 数组函数

> 文件: `array.h` + `std/array_extra.h`

数组为 `t_array*` 指针（128 槽 LIFO 复用池 + 1.5× 增长因子 + str/int 键双哈希索引，≥8 键触发 O(1) 查找）。

数组字面量支持 spread 展开 `[...$arr1, ...$arr2]`（PHP 7.4+）：编译期调用 `tphp_fn_arr_spread(dst, src)` 逐元素复制，int 键重新索引（append），string 键保留并覆盖；支持与字面量混合 `[1, ...$arr, 2]`、嵌套 `[[...$a], [...$b]]`、内联函数参数 `var_dump([...$arr])`。

### 泛型数组支持

`array<T>` 泛型数组在编译期单态化为独立 C 类型（`t_arr_int`/`t_arr_str`/`t_arr_float`/`t_arr_bool`/`t_arr_var`/`t_arr_ptr`），元素紧凑存储（`array<int>` 的 value 是 8 字节 `t_int`，比 `array<mixed>` 的 24 字节 `t_var` 节省 67%）。

**协变转换**：`array<T>` 传给 `array<mixed>` 参数时自动调用 `tphp_fn_arr_{int|str|float|bool}_to_var`（O(n) 开销，重新分配 + 元素包装为 `t_var`）。无注解的 `array` 默认推导为 `array<mixed>`，无转换开销。

**内置函数行为**：

| 类别 | 函数 | 行为 |
|------|------|------|
| 协变转换调用 | `count`/`in_array`/`array_key_exists`/`implode`/`array_sum`/`array_product` | 通过 `arrayArgCode` 自动协变转换为 `t_array*`，调用通用函数 |
| 元素类型追踪 | `array_keys`→`t_int`、`array_values`/`array_merge`/`array_slice`/`array_unique`/`array_reverse`/`array_diff`/`array_intersect`/`array_pad`→跟随源数组、`array_combine`→从 values 推导、`array_chunk`→外层 `t_array*`、`array_fill`→`t_var`、`array_column`→`t_var`、`array_count_values`→`t_int`、`str_split`/`parse_str`/`parse_url`/`iconv_get_encoding`→`t_string` | 覆盖 `$builtinArrElemTypes` 硬编码默认值，确保返回数组元素类型正确 |
| 原地修改特化 | `sort`/`rsort`/`shuffle` | 特化实现 `tphp_fn_arr_{int\|str\|float\|bool}_{sort\|rsort\|shuffle}`，直接操作特化数组内存，避免协变转换丢失修改 |
| 拒绝 | `asort`/`arsort`/`ksort`/`krsort`/`uasort`/`usort` | 对 `array<T>` 抛编译期异常（保持 key-value 关联的函数不适用于有序列表） |
| 拒绝 | `array_push`/`array_pop`/`array_shift`/`array_unshift` | 对 `array<T>` 拒绝，用 `$arr[]=$val` 语法替代 |

**类型严格性**：显式声明 `array<T>` 后，push 不同类型会触发编译错误（须用 `array<mixed>` 表达异构意图）。

### 增删 / 统计

| php函数 | tphp函数 | 性能说明 | 差异说明 |
|------|--------|------|------|
| `count(Countable\|array $value, int $mode = COUNT_NORMAL): int` | `count(array $array, int $mode = 0): int` | `$mode==1` 递归遍历嵌套 `TYPE_ARRAY`，否则 `a->length`，O(1)/O(n) | `COUNT_RECURSIVE`（`$mode=1`）支持；仅 Countable→array |
| `array_push(array &$array, mixed ...$values): int` | `array_push(array &$array, mixed $value): int` | 追加 entry + 1.5× grow，O(1) | 仅单值非变参 |
| `array_pop(array &$array): mixed\|null` | `array_pop(array &$array): mixed` | 取最后一个 entry，O(1) | 空数组返回 `NULL` |
| `array_shift(array &$array): mixed\|null` | `array_shift(array &$array): mixed` | `memmove` 左移，O(n) | — |
| `array_unshift(array &$array, mixed ...$values): int` | `array_unshift(array &$array, mixed $value): int` | `memmove` 右移 + 重建 int 键，O(n) | 仅单值非变参 |

### 查找 / 键操作

| php函数 | tphp函数 | 性能说明 | 差异说明 |
|------|--------|------|------|
| `in_array(mixed $needle, array $array, bool $strict = false): bool` | `in_array(mixed $needle, array $array): bool` | 线性遍历比较，O(n) | 始终严格类型比较（无 PHP 松散转换）；无 `$strict` 参数 |
| `array_search(mixed $needle, array $array, bool $strict = false): int\|string\|false` | `array_search(array $array, mixed $needle): int` | 线性遍历比较，O(n) | 参数顺序反转；返回 int 索引非键名；失败返回 `-1`（非 `false`） |
| `array_key_exists(int\|string $key, array $array): bool` | `array_key_exists(int\|string $key, array $array): bool` | 调 `arr_get_int`/`arr_get_str` 判 NULL | 按 key 类型编译期分派为两个 C 函数 |
| `array_keys(array $array, mixed $search_value = null, bool $strict = false): array` | `array_keys(array $array, mixed $search = null): array` | 遍历提取 key，O(n) | 支持 `$search` 参数（严格类型+值比较）；无 `$strict` 参数（始终严格比较） |
| `array_values(array $array): array` | `array_values(array $array): array` | 遍历提取 value，O(n) | — |
| `array_key_first(array $array): int\|string\|null` | `array_key_first(array $array): int` | `len>0 ? 0 : -1`，O(1) | 返回 `t_int`，字符串键返回 `0` 占位；空返回 `-1` |
| `array_key_last(array $array): int\|string\|null` | `array_key_last(array $array): int` | `len>0 ? len-1 : -1`，O(1) | 返回 `t_int`，字符串键返回位置索引；空返回 `-1` |

### 合并 / 拆分

| php函数 | tphp函数 | 性能说明 | 差异说明 |
|------|--------|------|------|
| `array_merge(array ...$arrays): array` | `array_merge(array $array1, array $array2): array` | 逐 entry 复制，O(n+m) | 仅两参数非变参 |
| `array_chunk(array $array, int $length, bool $preserve_keys = false): array` | `array_chunk(array $array, int $length): array` | 按 length 切片为子数组，O(n) | 无 `$preserve_keys` 参数（总是重索引）；`length<1` 返回空数组 |
| `array_slice(array $array, int $offset, ?int $length = null, bool $preserve_keys = false): array` | `array_slice(array $array, int $offset, int $length, bool $preserve_keys): array` | 截取复制，O(k) | `$length` 必传（`0`/负值均表示到末尾）；`$preserve_keys` 必传 |
| `array_combine(array $keys, array $values): array` | `array_combine(array $keys, array $values): array` | keys+values → 新数组，O(n) | 长度不等返回空数组（非 `false`） |

### 集合操作

| php函数 | tphp函数 | 性能说明 | 差异说明 |
|------|--------|------|------|
| `array_unique(array $array, int $flags = SORT_STRING): array` | `array_unique(array $array): array` | ≤16 元素 O(n²) 双重比较，>16 用开放寻址哈希 | 返回新数组不改原数组；无 `$flags` |
| `array_diff(array $array, array ...$arrays): array` | `array_diff(array $array1, array $array2): array` | 双重循环 int/string 值比较，O(n×m) | ⚠️ 当前存在命名不匹配 bug，从 PHP 调用会编译失败 |
| `array_intersect(array $array, array ...$arrays): array` | `array_intersect(array $array1, array $array2): array` | 双重循环取交集，O(n×m) | ⚠️ 同 `array_diff` 命名不匹配问题 |
| `array_count_values(array $array): array` | `array_count_values(array $array): array` | 遍历统计频次，O(n) | int 值转为字符串键（PHP 保留 int 键）；非 int/string 值跳过 |
| `array_flip(array $array): array` | `array_flip(array $array): array` | key↔value 互换，O(n) | 非 int/string 值跳过 |

### 排序 / 随机

| php函数 | tphp函数 | 性能说明 | 差异说明 |
|------|--------|------|------|
| `sort(array &$array, int $flags = SORT_REGULAR): bool` | `sort(array &$array): void` | libc `qsort` 原地，O(n log n) | 返回 `void`（非 `bool`）；无 `$flags`；混合类型按 type tag 升序 |
| `rsort(array &$array, int $flags = SORT_REGULAR): bool` | `rsort(array &$array): void` | 同 `sort` 降序，O(n log n) | 同 `sort` |
| `ksort(array &$array, int $flags = SORT_REGULAR): bool` | `ksort(array &$array): void` | qsort 指针排序按键，O(n log n) | 同 `sort` |
| `krsort(array &$array, int $flags = SORT_REGULAR): bool` | `krsort(array &$array): void` | 同 `ksort` 降序，O(n log n) | 同 `sort` |
| `asort(array &$array, int $flags = SORT_REGULAR): bool` | `asort(array &$array): void` | qsort 按值保键，O(n log n) | 同 `sort` |
| `arsort(array &$array, int $flags = SORT_REGULAR): bool` | `arsort(array &$array): void` | 同 `asort` 降序，O(n log n) | 同 `sort` |
| `shuffle(array &$array): bool` | `shuffle(array &$array): void` | Fisher-Yates 原地洗牌，O(n) | 返回 `void`（非 `bool`）；用 `rand()`（非 CSPRNG） |
| `array_rand(array $array, int $num = 1): int\|string\|array` | `array_rand(array $array): int` | `rand_int(0,len-1)` 返回单键，O(1) | 无 `$num` 参数；字符串键返回位置索引；空数组返回 `-1` |

### 迭代器 / 填充 / 提取

| php函数 | tphp函数 | 性能说明 | 差异说明 |
|------|--------|------|------|
| `current(array $array): mixed` | `current(array $array): mixed` | `entries[cursor]`，O(1) | 空/cursor 越界返回 `NULL` |
| `key(array $array): int\|string\|null` | `key(array $array): mixed` | `entries[cursor]` 键，O(1) | 越界返回 `NULL` |
| `next(array $array): mixed` | `next(array $array): mixed` | `cursor++` 返回新值，O(1) | 越界返回 `NULL` |
| `prev(array $array): mixed` | `prev(array $array): mixed` | `cursor--` 返回新值，O(1) | 越界返回 `NULL` |
| `end(array $array): mixed` | `end(array $array): mixed` | `cursor=length-1` 返回末值，O(1) | — |
| `reset(array $array): mixed` | `reset(array $array): mixed` | `cursor=0` 返回首值，O(1) | — |
| `range(int\|string $start, int\|string $end, int\|float $step = 1): array` | `range(int $start, int $end, int $step): array` | 预知长度一次分配，O(n) | 仅 int（不支持单字符字符串）；`step==0` 致命错误 |
| `array_fill(int $start_index, int $count, mixed $value): array` | `array_fill(int $start_index, int $count, mixed $value): array` | `set_int` 填充，O(n) | `count<0` 致命错误 |
| `array_pad(array $array, int $length, mixed $value): array` | `array_pad(array $array, int $length, mixed $value): array` | 预分配+复制，O(n) | `length>0` 右侧填充，`length<0` 左侧填充；`abs(length)<=len` 原样返回 |
| `array_reverse(array $array, bool $preserve_keys = false): array` | `array_reverse(array $array, bool $preserve_keys): array` | 倒序复制，O(n) | `$preserve_keys` 必传 |
| `array_column(array $array, int\|string\|null $column_key, int\|string\|null $index_key = null): array` | `array_column(array $array, string $column_key): array` | 遍历行匹配 string 键 push 值，O(n×m) | 仅 string 列名；无 `$index_key` 参数；对象行 push `NULL` |
| `max(mixed $value, mixed ...$values): mixed` | `max(array $array): mixed` / `max(mixed ...$vals): mixed` | 遍历比较，O(n) | 支持数组形式和可变参数形式（多参数打包为临时数组）；空数组致命错误 |
| `min(mixed $value, mixed ...$values): mixed` | `min(array $array): mixed` / `min(mixed ...$vals): mixed` | 遍历比较，O(n) | 同 `max` |
| `array_sum(array $array): int\|float` | `array_sum(array $array): mixed` | 遍历累加，遇 float 自动提升，O(n) | 非数值静默跳过（PHP 视为 0 并 warning） |
| `array_product(array $array): int\|float` | `array_product(array $array): mixed` | 遍历累乘，遇 float 自动提升，O(n) | 非数值静默跳过 |
| `array_is_list(array $array): bool` | `array_is_list(array $array): bool` | 检查所有 entry 为 `TYPE_INT` 且键==位置，O(n) | 空数组返回 `true` |
| `array_map(?callable $callback, array $array, array ...$arrays): array` | `array_map(callable $callback, array $array): array` | 编译期内联展开为 for 循环，O(n) | 回调必须类型已知；仅单数组；键不保留 |
| `array_filter(array $array, ?callable $callback = null, int $mode = 0): array` | `array_filter(array $array, callable $callback): array` | 编译期内联展开 + 过滤，O(n) | `$callback` 必填；键不保留；无 `USE_KEY`/`USE_BOTH` 模式 |
| `array_reduce(array $array, callable $callback, mixed $initial = null): mixed` | `array_reduce(array $array, callable $callback, mixed $initial): mixed` | 编译期内联累加器循环，O(n) | `$initial` 必填 |

---

## standard — 数学函数

> 文件: `std/math.h` + `tphp_math.h`

### 基础运算

| php函数 | tphp函数 | 性能说明 | 差异说明 |
|------|--------|------|------|
| `abs(int\|float $num): int\|float` | `abs(int $num): int` / `abs(float $num): float` | `llabs(v)` 或 `fabs(v)`，O(1) | 编译期按参数类型分派 `tphp_fn_abs_int`/`tphp_fn_abs_float` |
| `round(int\|float $num, int $precision = 0, int $mode = RoundingMode::HALF_UP): float` | `round(float $num): float` | libc `round(v)`，O(1) | 无 `$precision`/`$mode` 参数 |
| `ceil(int\|float $num): float` | `ceil(float $num): float` | libc `ceil`，O(1) | — |
| `floor(int\|float $num): float` | `floor(float $num): float` | libc `floor`，O(1) | — |
| `sqrt(float $num): float` | `sqrt(float $num): float` | `v >= 0.0 ? sqrt(v) : NAN`，O(1) | 负数返回 `NAN`（Windows 显示 `-1.#IND`） |
| `pow(int\|float $base, int\|float $exp): int\|float` | `pow(mixed $base, mixed $exp): mixed` | int^非负整数走 `tphp_rt_pow_int` 快速幂 O(log n)；否则 libc `pow` | int^负整数走 float 路径返回 float（PHP 语义）；int^非负整数返回 int |
| `pi(): float` | `pi(): float` | 返回 `M_PI` 常量，O(1) | — |
| `fmod(float $num1, float $num2): float` | `fmod(float $num1, float $num2): float` | libc `fmod`，O(1) | — |
| `deg2rad(float $num): float` | `deg2rad(float $num): float` | `num * (M_PI/180.0)`，O(1) | — |
| `rad2deg(float $num): float` | `rad2deg(float $num): float` | `num * (180.0/M_PI)`，O(1) | — |
| `intdiv(int $num1, int $num2): int` | `intdiv(int $num1, int $num2): int` | `a/b`，O(1) | 零除 `tp_throw`（字符串异常，非 `DivisionByZeroError` 对象） |

### 三角函数

| php函数 | tphp函数 | 性能说明 | 差异说明 |
|------|--------|------|------|
| `sin(float $num): float` | `sin(float $num): float` | libc `sin`，O(1) | — |
| `cos(float $num): float` | `cos(float $num): float` | libc `cos`，O(1) | — |
| `tan(float $num): float` | `tan(float $num): float` | libc `tan`，O(1) | — |
| `asin(float $num): float` | `asin(float $num): float` | libc `asin`，O(1) | — |
| `acos(float $num): float` | `acos(float $num): float` | libc `acos`，O(1) | — |
| `atan(float $num): float` | `atan(float $num): float` | libc `atan`，O(1) | — |
| `sinh(float $num): float` | `sinh(float $num): float` | libc `sinh`，O(1) | — |
| `cosh(float $num): float` | `cosh(float $num): float` | libc `cosh`，O(1) | — |
| `tanh(float $num): float` | `tanh(float $num): float` | libc `tanh`，O(1) | — |

### 指数/对数

| php函数 | tphp函数 | 性能说明 | 差异说明 |
|------|--------|------|------|
| `exp(float $num): float` | `exp(float $num): float` | libc `exp`，O(1) | — |
| `log(float $num, float $base = M_E): float` | `log(float $num): float` | libc `log`（自然对数），O(1) | 无 `$base` 参数 |
| `log10(float $num): float` | `log10(float $num): float` | libc `log10`，O(1) | — |
| `is_finite(float $num): bool` | `is_finite(float $num): bool` | `isfinite(x)`，O(1) | — |
| `is_infinite(float $num): bool` | `is_infinite(float $num): bool` | `isinf(x)`，O(1) | — |
| `is_nan(float $num): bool` | `is_nan(float $num): bool` | `isnan(x)`，O(1) | — |

---

## standard — 进制转换

> 文件: `conv.h` + `std/math.h`

| php函数 | tphp函数 | 性能说明 | 差异说明 |
|------|--------|------|------|
| `bindec(string $binary_string): int` | `bindec(string $binary_string): int` | `strtoll(s, NULL, 2)`，O(1) | 空串/NULL 返回 `0` |
| `hexdec(string $hex_string): int` | `hexdec(string $hex_string): int` | `strtoll(s, NULL, 16)`，O(1) | 用 `strtoll`（PHP 用 `strtoull` 防溢出） |
| `octdec(string $octal_string): int` | `octdec(string $octal_string): int` | `strtoll(s, NULL, 8)`，O(1) | — |
| `decbin(int $num): string` | `decbin(int $num): string` | `str_pool_alloc(72)` 逐位写后反转，O(1) | 线程安全（P3-7） |
| `decoct(int $num): string` | `decoct(int $num): string` | `str_pool_alloc(32)` + `snprintf("%llo")`，O(1) | 线程安全（P3-7）；按无符号处理 |
| `dechex(int $num): string` | `dechex(int $num): string` | `str_pool_alloc(32)` + `snprintf("%llx")`，O(1) | 线程安全（P3-7）；按无符号处理；小写 |
| `base_convert(string $num, int $from_base, int $to_base): string` | `base_convert(string $num, int $from_base, int $to_base): string` | 大整数堆计算，O(log n) | 精度受 64 字节缓冲限制（约 20 位十进制）；非法字符返回空串 |

---

## standard — 断言

> 文件: `std/ctrl.h`

| php函数 | tphp函数 | 性能说明 | 差异说明 |
|------|--------|------|------|
| — | `assert_true(bool $condition): void` | 失败→`fprintf(stderr)`→`exit(2)` | TinyPHP 自有断言，PHP 无对应函数 |
| — | `assert_false(bool $condition): void` | 同上 | 同上 |
| — | `assert_eq_int(int $a, int $b): void` | `a != b` → `fprintf(stderr)`+`exit(2)` | 同上 |
| — | `assert_eq_float(float $a, float $b): void` | `a != b` → `fprintf(stderr)`+`exit(2)` | 无精度容差，严格 `==` |
| — | `assert_eq_str(string $a, string $b): void` | `!str_eq` → `fprintf(stderr)`+`exit(2)` | 错误信息只打印长度不打印内容 |

---

## random — 随机数

> 文件: `rand.h`

全部统一走 CSPRNG（Windows → `rand_s`，Linux/macOS → `/dev/urandom`），零全局状态。

| php函数 | tphp函数 | 性能说明 | 差异说明 |
|------|--------|------|------|
| `rand(): int` / `rand(int $min, int $max): int` | `rand(int $min, int $max): int` | krng 伪随机（非 CSPRNG），O(1) | 强制 2 参（不支持无参形式） |
| `mt_rand(): int` / `mt_rand(int $min, int $max): int` | `mt_rand(int $min, int $max): int` | 直接等同 `rand_int`（非真正 Mersenne Twister），O(1) | 强制 2 参；非真 MT 算法 |
| `random_int(int $min, int $max): int` | `random_int(int $min, int $max): int` | 真 CSPRNG + 拒绝采样防模偏差，O(1) | `min > max` 时 `tp_throw` |
| `random_bytes(int $length): string` | `random_bytes(int $length): string` | 真 CSPRNG 原始二进制，O(n) | `length <= 0` 返回空串；`length > 1048576` 抛错 |

---

## password — 密码哈希

> 文件: `os/password.h`

基于 bcrypt 算法的 `password_hash` / `password_verify` 实现，参考 PHP 原生 `crypt_blowfish.c`（EksBlowfish 算法）。纯 C 静态实现，零外部依赖，兼容 AOT 编译。

| php函数 | tphp函数 | 性能说明 | 差异说明 |
|------|--------|------|------|
| `password_hash(string $password, string\|int\|null $algo, array $options = []): string` | `password_hash(string $password, int $algo, array $options): string` | `BF_crypt()` → 60 字符 `$2b$10$...` | 仅支持 `PASSWORD_BCRYPT`；`$options` 被忽略；cost 硬编码 10；空密码抛错 |
| `password_verify(string $password, string $hash): bool` | `password_verify(string $password, string $hash): bool` | `BF_crypt()` 重算 + 常量时间比较 | hash 长度 < 60 或格式不符直接返回 `false` |

**实现细节**：
- 算法：EksBlowfish（bcrypt），与 PHP 原生 `password_hash` 完全兼容
- 盐值：优先使用 CSPRNG（`_tphp_random_bytes`），回退到基于时间的伪随机
- 常量：`PASSWORD_BCRYPT = 1`，`PASSWORD_BCRYPT_DEFAULT_COST = 10`
- 输出格式：`$2b$10$<22-char-base64-salt><31-char-base64-hash>`，共 60 字符
- 安全：`password_verify` 使用常量时间比较，防止时序攻击
- bcrypt 前缀支持：`$2a$`、`$2b$`、`$2x$`、`$2y$`（兼容所有 PHP bcrypt 变体）

---

## ctype — 字符检测

> 文件: `std/ctrl.h`

11 个函数，直接映射 C `<ctype.h>`，零堆分配。空字符串返回 `false`。

| php函数 | tphp函数 | 性能说明 | 差异说明 |
|------|--------|------|------|
| `ctype_alnum(int\|string $text): bool` | `ctype_alnum(string $text): bool` | `isalnum` 逐字符，O(n) | 仅接受 string（PHP 还接受 int 解释为 ASCII 字符） |
| `ctype_alpha(int\|string $text): bool` | `ctype_alpha(string $text): bool` | `isalpha`，O(n) | 同上 |
| `ctype_cntrl(int\|string $text): bool` | `ctype_cntrl(string $text): bool` | `iscntrl`，O(n) | 同上 |
| `ctype_digit(int\|string $text): bool` | `ctype_digit(string $text): bool` | `isdigit`，O(n) | 同上 |
| `ctype_graph(int\|string $text): bool` | `ctype_graph(string $text): bool` | `isgraph`，O(n) | 同上 |
| `ctype_lower(int\|string $text): bool` | `ctype_lower(string $text): bool` | `islower`，O(n) | 同上 |
| `ctype_print(int\|string $text): bool` | `ctype_print(string $text): bool` | `isprint`，O(n) | 同上 |
| `ctype_punct(int\|string $text): bool` | `ctype_punct(string $text): bool` | `ispunct`，O(n) | 同上 |
| `ctype_space(int\|string $text): bool` | `ctype_space(string $text): bool` | `isspace`，O(n) | 同上 |
| `ctype_upper(int\|string $text): bool` | `ctype_upper(string $text): bool` | `isupper`，O(n) | 同上 |
| `ctype_xdigit(int\|string $text): bool` | `ctype_xdigit(string $text): bool` | `isxdigit`，O(n) | 同上 |

---

## json

> 文件: `os/json.h`

| php函数 | tphp函数 | 性能说明 | 差异说明 |
|------|--------|------|------|
| `json_encode(mixed $value, int $flags = 0, int $depth = 512): string\|false` | `json_encode(mixed $value): string` | 两趟法：计长→一次分配→写入，零 `str_concat` 开销 | 无 `$flags`/`$depth` 参数；NaN/Inf→`null`；`> 8MB` 返回 `"null"` |
| `json_decode(string $json, ?bool $associative = null, int $depth = 512, int $flags = 0): mixed` | `json_decode(string $json): mixed` | 递归下降解析 → `t_var` | 仅 1 参（无 `$associative`/`$depth`/`$flags`）；对象解析为关联数组；失败返回 `NULL` |
| `json_validate(string $json, int $depth = 512, int $flags = 0): bool` | `json_validate(string $json): bool` | 复用 `json_decode`，`type != TYPE_NULL` 即有效 | 合法 JSON `"null"` 会被误判为无效（实现缺陷） |

---

## hash

> 文件: `hash.h`

全部纯 C 算法（RFC 1321 / FIPS 180-4 / 查表法），零外部依赖。

| php函数 | tphp函数 | 性能说明 | 差异说明 |
|------|--------|------|------|
| `md5(string $string, bool $binary = false): string` | `md5(string $string): string` | RFC 1321 纯 C，`str_pool_alloc` | 无 `$binary` 参数；返回 32 字符小写 hex |
| `sha1(string $string, bool $binary = false): string` | `sha1(string $string): string` | FIPS 180-4 纯 C | 无 `$binary` 参数；返回 40 字符小写 hex |
| `hash(string $algo, string $data, bool $binary = false): string` | `sha256(string $string): string` | FIPS 180-4 纯 C | TinyPHP 直接提供 `sha256()` 内置函数（PHP 需 `hash('sha256', ...)`）；返回 64 字符小写 hex |
| `hash(string $algo, string $data, bool $binary = false): string` | `sha512(string $string): string` | FIPS 180-4 纯 C | 同上；返回 128 字符小写 hex |
| `hash_hmac(string $algo, string $data, string $key, bool $binary = false): string` | `hash_hmac(string $algo, string $data, string $key, bool $binary = false): string` | RFC 2104 纯 C，复用 SHA-256/SHA-512 | 支持 sha256/sha512；不支持 md5/sha1；`$binary=true` 返回原始摘要 |
| `crc32(string $string): int` | `crc32(string $string): int` | 256 项查表法，O(n) | C 函数名 `tphp_fn_crc32_str` |

---

## date — 时间函数

> 文件: `os/times.h`

| php函数 | tphp函数 | 性能说明 | 差异说明 |
|------|--------|------|------|
| `time(): int` | `time(): int` | `(t_int)time(NULL)`，O(1) | — |
| `date(string $format, ?int $timestamp = null): string` | `date(string $format, int $timestamp): string` | 手写解析 PHP 格式符 + `localtime` + SSO 返回 | `timestamp < 0` 回退到 `time(NULL)`；仅支持 `Y/y/m/n/d/j/H/G/i/s` 10 个格式符；无时区支持 |
| `sleep(int $seconds): int` | `sleep(int $seconds): void` | Win `Sleep(ms*1000)` / POSIX `sleep()` | 返回 void（PHP 返回 0）；负数直接返回 |
| `usleep(int $microseconds): void` | `usleep(int $microseconds): void` | Win `Sleep(us/1000)` / POSIX `usleep()` | 负数直接返回 |
| `hrtime(bool $number_as_number = false): array\|int\|float` | `hrtime(): int` | Win `QueryPerformanceCounter` / POSIX `clock_gettime(CLOCK_MONOTONIC)` | 返回单个纳秒整数（非 PHP 的 `[秒, 纳秒]` 数组）；无 `$number_as_number` 参数 |
| `microtime(bool $as_float = false): string\|float` | `microtime(): float` | Win QPC / POSIX `clock_gettime(MONOTONIC)` | 永远返回浮点秒（无 `$as_float` 参数） |
| `mktime(int $hour, ?int $minute = null, ?int $second = null, ?int $month = null, ?int $day = null, ?int $year = null): int\|false` | `mktime(int $hour, int $minute, int $second, int $month, int $day, int $year): int` | 日历天数累加法从 1970-01-01 起算 | 6 参数全必填（无默认值）；不归一化越界值 |
| `strtotime(string $datetime, ?int $baseTimestamp = null): int\|false` | `strtotime(string $datetime): int` | 纯数字直接返回 `time()`；支持 `Y-m-d`/`Y/m/d` 配 `H:i:s` | 仅支持几种绝对格式；不支持相对/自然语言格式；无 `$baseTimestamp` 参数 |
| `uniqid(string $prefix = "", bool $more_entropy = false): string` | `uniqid(string $prefix): string` | `str_pool_alloc(48)` + `sprintf "%08lx%05lx", time, rand` | 无 `$more_entropy` 参数；prefix 必填；线程安全（P3-7） |

---

## file — 文件 I/O

> 文件: `os/file.h`

| php函数 | tphp函数 | 性能说明 | 差异说明 |
|------|--------|------|------|
| `file_get_contents(string $filename, bool $use_include_path = false, ?resource $context = null, int $offset = 0, ?int $length = null): string\|false` | `file_get_contents(string $filename): string` | `fopen("rb")` → 测大小 → 单次 `fread` → `fclose` | 无 `$use_include_path`/`$context`/`$offset`/`$length` 参数；失败 `tp_throw`（非返回 `false`） |
| `file_put_contents(string $filename, mixed $data, int $flags = 0, ?resource $context = null): int\|false` | `file_put_contents(string $filename, string $data): bool` | `fopen("wb")` → `fwrite` → `fclose` | 无 `$flags`/`$context` 参数；只支持覆盖写（无 `FILE_APPEND`/`LOCK_EX`）；data 不支持数组；返回 `bool`（PHP 返回字节数） |
| `unlink(string $filename, ?resource $context = null): bool` | `unlink(string $filename): bool` | 拷贝到栈缓冲后 `remove()` | 无 `$context` 参数 |

---

## mbstring (UTF-8)

> 文件: `std/utf8.h`

| php函数 | tphp函数 | 性能说明 | 差异说明 |
|------|--------|------|------|
| `mb_strlen(string $string, ?string $encoding = null): int` | `mb_strlen(string $string): int` | UTF-8 字节解码计数，O(n) | 无 `$encoding` 参数（硬编码 UTF-8） |
| `mb_substr(string $string, int $start, ?int $length = null, ?string $encoding = null): string` | `mb_substr(string $string, int $start, int $length): string` | UTF-8 字符边界对齐，`str_pool_alloc` 拷贝 | `$length` 必填（PHP 可选默认到末尾）；无 `$encoding` 参数；`length <= 0` 一律取到末尾（不支持负 length 截尾） |
| `mb_strpos(string $haystack, string $needle, int $offset = 0, ?string $encoding = null): int\|false` | `mb_strpos(string $haystack, string $needle): int` | 委托 `strpos` 做字节级搜索，O(n×m) | 仅 2 参数（无 `$offset`/`$encoding`）；未找到返回 `-1`（非 `false`） |

---

## iconv — 字符集转换

> 文件: `include/iconv.h`（内置，非 `#import` 按需引入）
>
> 跨平台: POSIX 用系统 `<iconv.h>`（TCC 下改用手动前向声明，避开 macOS stdarg.h 缺失问题）；Windows 用 Win32 `MultiByteToWideChar`/`WideCharToMultiByte`。macOS 链接自动添加 `-liconv`。
> AOT 单返回类型: 失败统一 `tp_throw`（不返回 `false`）；`iconv_strpos` 未找到返回 `-1`。

**常量**

| 常量 | 值 | 说明 |
|------|----|------|
| `ICONV_IMPL` | `"iconv"` | 实现名称 |
| `ICONV_VERSION` | `"1.0"` | 版本号 |

**函数**

| php函数 | tphp函数 | 性能说明 | 差异说明 |
|------|--------|------|------|
| `iconv(string $from_encoding, string $to_encoding, string $string): string\|false` | `iconv(string $from_encoding, string $to_encoding, string $string): string` | POSIX 系统 iconv / Win32 API（经 UTF-16 中转） | 失败 `tp_throw`（非返回 `false`）；支持 `//IGNORE`/`//TRANSLIT` 后缀 |
| `iconv_strlen(string $string, ?string $encoding = null): int\|false` | `iconv_strlen(string $string, string $encoding): int` | UTF-8 快路径 + 转换计数 | `$encoding` 必填（PHP 可选默认 UTF-8） |
| `iconv_strpos(string $haystack, string $needle, int $offset = 0, ?string $encoding = null): int\|false` | `iconv_strpos(string $haystack, string $needle, int $offset, string $encoding): int` | 4 参数全必填；非 UTF-8 先转 UTF-8；按字符跳过 offset 后字节级 `memcmp` 搜索 | 未找到返回 `-1`（非 `false`）；`$offset`/`$encoding` 均无默认值 |
| `iconv_substr(string $string, int $offset, ?int $length = null, ?string $encoding = null): string\|false` | `iconv_substr(string $string, int $offset, int $length, string $encoding): string` | UTF-8 快路径，否则转 UTF-8 截取后转回原编码 | 4 参数全必填；`length <= 0` 表示到末尾（不支持负 length 截尾） |
| `iconv_get_encoding(string $type = "all"): array\|string\|false` | `iconv_get_encoding(string $type): array` | 始终返回 3 元素关联数组 | `$type` 参数被忽略；始终返回数组（PHP 依 type 返回 string\|array\|false） |
| `iconv_set_encoding(string $type, string $encoding): bool` | `iconv_set_encoding(string $type, string $encoding): bool` | 大小写不敏感匹配 type，写入 3 个全局 t_string | 未知 type 时 `tp_throw`（非返回 `false`） |
| `iconv_mime_encode(string $field_name, string $field_value, array $preferences = []): string\|false` | `iconv_mime_encode(string $field_name, string $field_value, array $preferences): string` | 解析 `prefs["output-charset"]`（默认 UTF-8）+ base64 编码 | 仅生成 B 编码；prefs 仅识别 `output-charset` |
| `iconv_mime_decode(string $string, int $mode = 0, ?string $encoding = null): string\|false` | `iconv_mime_decode(string $string, int $mode, string $encoding): string` | 支持 B 和 Q 编码；raw 字节按 src_cs 转到目标 charset | 3 参数全必填；`$mode` 被忽略；仅处理首段 MIME 编码（尾部剩余丢弃） |

---

## pcntl — 进程控制

> 文件: `ext/pcntl/`，POSIX 专属，按需引入 `#import pcntl`

| php函数 | tphp函数 | 性能说明 | 差异说明 |
|------|--------|------|------|
| `pcntl_fork(): int` | `pcntl_fork(): int` | `fork()`，O(1) | Windows 抛 `tp_throw` 异常（可 try-catch） |
| `pcntl_waitpid(int $pid, int &$status, int $flags = 0, array &$resource_usage = []): int` | `pcntl_waitpid(int $pid, int &$status, int $flags): int` | `waitpid()` | — |
| `pcntl_wait(int &$status, int $flags = 0, array &$resource_usage = []): int` | `pcntl_wait(int &$status): int` | `wait()` | 无 `$flags` 参数 |
| `pcntl_exec(string $path, array $args = [], array $env_vars = []): bool` | `pcntl_exec(string $path): void` | `execv(path, {path, NULL})` | 仅 1 参（无 `$args`/`$env_vars`）；argv 固定为 `{path, NULL}` |
| `pcntl_alarm(int $seconds): int` | `pcntl_alarm(int $seconds): int` | `alarm(sec > 0 ? sec : 0)` | — |
| `pcntl_get_last_error(): int` | `pcntl_get_last_error(): int` | 返回 `errno` | — |
| `pcntl_strerror(int $error_code): string` | `pcntl_strerror(int $error_code): string` | `strerror()` + SSO 包装 | — |

---

## posix — POSIX 系统

> 文件: `ext/posix/`，POSIX 专属，按需引入 `#import posix`

| php函数 | tphp函数 | 性能说明 | 差异说明 |
|------|--------|------|------|
| `posix_getpid(): int` | `posix_getpid(): int` | `getpid()` | — |
| `posix_getppid(): int` | `posix_getppid(): int` | `getppid()` | — |
| `posix_getuid(): int` | `posix_getuid(): int` | `getuid()` | — |
| `posix_geteuid(): int` | `posix_geteuid(): int` | `geteuid()` | — |
| `posix_getgid(): int` | `posix_getgid(): int` | `getgid()` | — |
| `posix_getegid(): int` | `posix_getegid(): int` | `getegid()` | — |
| `posix_getcwd(): string\|false` | `posix_getcwd(): string` | 栈缓冲 `char buf[4096]` + `getcwd()` + `_mk_str` 深拷贝 | 失败返回空 t_string（非 `false`）；线程安全（P3-7） |
| `posix_isatty(int $file_descriptor): bool` | `posix_isatty(int $file_descriptor): int` | `isatty()` | 返回 `t_int` 1/0（PHP 返回 bool） |
| `posix_kill(int $process_id, int $signal): bool` | `posix_kill(int $process_id, int $signal): int` | `kill()` | — |
| `posix_strerror(int $error_code): string` | `posix_strerror(int $error_code): string` | `strerror()` | — |
| `posix_get_last_error(): int` | `posix_get_last_error(): int` | 返回 `errno` | — |
| `posix_ttyname(int $file_descriptor): string\|false` | `posix_ttyname(int $file_descriptor): string` | `ttyname()` | 未匹配返回空 t_string（非 `false`） |
| `posix_uname(): array\|false` | — | ⬜ 未实现 | — |
| `posix_times(): array\|false` | — | ⬜ 未实现 | — |

---

## pcre — 正则表达式

> 文件: `ext/pcre/`，NFA VM 引擎（移植自 vlang `vlib/regex/pcre/regex.v`），按需引入 `#import pcre`

纯 C NFA VM 正则引擎（Russ Cox 模型，12 条指令），不依赖外部 PCRE2 库。128 位 bitset ASCII 字符类、Boyer-Moore 前缀跳过、32 槽 LRU 编译缓存。

**ReDoS 防护**：`tp_vm_match` 内置回溯计数器，超限（`TP_BACKTRACK_LIMIT=1000000`）设置 `backtrack_limit_exceeded` 标志，`tp_find_from` 检测后提前退出，5 个 `preg_*` 函数设置 `g_pcre_last_error = PREG_BACKTRACK_LIMIT_ERROR`。恶意模式（如 `(a+)+$`）会安全失败而非阻塞进程。

**与 PHP 差异**：`preg_match` / `preg_match_all` 返回匹配数组（空=无匹配）而非 `int + byRef $matches`；所有参数必须显式传入（AOT 不支持默认参数值 / byRef 输出参数）；不支持 `preg_replace_callback`；`\a`=`[a-z]`（PHP 为 BEL 0x07）、`\A`=`[A-Z]`（PHP 为字符串起始）；`i` 标志仅 ASCII 大小写折叠；不支持 lookahead / lookbehind / 原子组 `(?>)` / 占有量词 `*+` / Unicode 属性类 `\p{}`。

| php函数 | tphp函数 | 性能说明 | 差异说明 |
|------|--------|------|------|
| `preg_match(string $pattern, string $subject, array &$matches = null, int $flags = 0, int $offset = 0): int\|false` | `preg_match(string $pattern, string $subject): array` | NFA VM → `t_array*` | 无 byRef `$matches`；返回数组而非匹配次数；`result[0]`=完整匹配，`result[1..n]`=子组；无匹配返回空数组（非 `false`） |
| `preg_match_all(string $pattern, string $subject, array &$matches = null, int $flags = 0, int $offset = 0): int\|false` | `preg_match_all(string $pattern, string $subject): array` | 循环匹配 → 二维数组 | 无 byRef `$matches`；返回二维数组而非匹配总数；固定 `PREG_PATTERN_ORDER` |
| `preg_replace(array\|string $pattern, array\|string $replacement, array\|string $subject, int $limit = -1, int &$count = null): array\|string\|null` | `preg_replace(string $pattern, string $replacement, string $subject, int $limit): string` | 两趟法：计长→写入 | 仅单字符串（PHP 支持 array 入参返回 array/string）；支持 `$1`-`$9` 反向引用；`limit=-1` 无限制 |
| `preg_split(string $pattern, string $subject, int $limit = -1, int $flags = 0): array\|false` | `preg_split(string $pattern, string $subject, int $limit, int $flags): array` | 循环分割 → `t_array*` | 仅实现 `PREG_SPLIT_NO_EMPTY` 标志；`PREG_SPLIT_DELIM_CAPTURE` 定义但未处理 |
| `preg_grep(string $pattern, array $array, int $flags = 0): array` | `preg_grep(string $pattern, array $array, int $flags): array` | 遍历匹配 → `t_array*` | 整数键保留，字符串键降级为 push（不保留原 key）；非 string 元素跳过 |
| `preg_quote(string $str, ?string $delimiter = null): string` | `preg_quote(string $str, string $delimiter): string` | 两趟法转义元字符 | `$delimiter` 必填（PHP 默认 `null`） |
| `preg_last_error(): int` | `preg_last_error(): int` | 返回全局变量 `g_pcre_last_error` | — |
| `preg_last_error_msg(): string` | `preg_last_error_msg(): string` | switch 错误码 → 字符串 | — |

### 支持的正则语法

| 类别 | 语法 |
|------|------|
| 预定义类 | `\d \D \w \W \s \S \b \B` |
| 字母类 | `\a`(=[a-z]) `\A`(=[A-Z]) |
| 字符类 | `[...]` `[^...]` 范围 `a-z` |
| 量词 | `* + ? {n} {n,} {n,m}` + 懒惰 `?` |
| 分组 | `(...)` `(?:...)` `(?P<name>...)` |
| 标志 | `i m s`（分隔符后或内联 `(?i)`） |
| 锚点 / 选项 | `^ $` / `\|` |
| 转义 | `\n \r \t \v \f \0 \xHH` |

### 常量

| 常量 | 值 | 说明 |
|------|-----|------|
| `PREG_PATTERN_ORDER` | 1 | `preg_match_all` 默认顺序 |
| `PREG_SET_ORDER` | 2 | 定义但未实现（固定 PATTERN_ORDER） |
| `PREG_SPLIT_NO_EMPTY` | 1 | `preg_split` 去空片段 |
| `PREG_SPLIT_DELIM_CAPTURE` | 2 | `preg_split` 保留分隔符捕获组 |
| `PREG_GREP_INVERT` | 1 | `preg_grep` 反转结果 |
| `PREG_NO_ERROR` | 0 | 无错误 |
| `PREG_INTERNAL_ERROR` | 1 | 内部错误 |
| `PREG_BACKTRACK_LIMIT_ERROR` | 2 | 回溯超限（`TP_BACKTRACK_LIMIT=1000000`） |
| `PREG_RECURSION_LIMIT_ERROR` | 3 | 递归限制（未启用） |

---

## filter — 过滤器

> 文件: `include/filter.h`（内置功能）

### 函数

| php函数 | tphp函数 | 性能说明 | 差异说明 |
|------|--------|------|------|
| `filter_var(mixed $value, int $filter = FILTER_DEFAULT, mixed $options = 0): mixed` | `filter_var(mixed $value, int $filter, int $flags): mixed` | header-only 实现，`str_pool_alloc` 输出 | 第三参数强制为 `int $flags`（PHP 是 `mixed $options`，可为 array）；数组选项需走 `filter_var_opt`；`FILTER_VALIDATE_REGEXP` 直接返回原串（不内置 PCRE） |
| `filter_list(): array` | `filter_list(): array` | 返回固定 18 个过滤器名字符串数组 | 无 `$sort` 参数 |
| `filter_id(string $name): int\|false` | `filter_id(string $name): int` | 名称转小写后 `strcmp` 匹配 | 未匹配返回 `-1`（非 `false`）；输入截断到 31 字节 |

### 验证过滤器（FILTER_VALIDATE_*）

验证失败返回 `NULL`，成功返回原值或类型转换后的值。

| 常量 | 值 | 说明 |
|------|-----|------|
| `FILTER_VALIDATE_INT` | 257 | 验证整数（支持 `FILTER_FLAG_ALLOW_OCTAL` / `FILTER_FLAG_ALLOW_HEX`） |
| `FILTER_VALIDATE_BOOL` | 258 | 验证布尔值（"1"/"true"/"on"/"yes" → true，"0"/"false"/"off"/"no" → false） |
| `FILTER_VALIDATE_FLOAT` | 259 | 验证浮点数（支持 `FILTER_FLAG_ALLOW_THOUSAND` / `FILTER_FLAG_ALLOW_SCIENTIFIC`） |
| `FILTER_VALIDATE_REGEXP` | 272 | 正则验证（需用 `preg_*` 代替） |
| `FILTER_VALIDATE_URL` | 273 | 验证 URL（要求 scheme://host 格式） |
| `FILTER_VALIDATE_EMAIL` | 274 | 验证 Email（RFC 5321 简化版，ASCII only） |
| `FILTER_VALIDATE_IP` | 275 | 验证 IP（IPv4 / IPv6） |
| `FILTER_VALIDATE_MAC` | 276 | 验证 MAC 地址（xx:xx:xx:xx:xx:xx 或 - 分隔） |
| `FILTER_VALIDATE_DOMAIN` | 277 | 验证域名 |

### 净化过滤器（FILTER_SANITIZE_*）

返回处理后的字符串。

| 常量 | 值 | 说明 |
|------|-----|------|
| `FILTER_SANITIZE_STRING` | 513 | 去除 HTML 标签 |
| `FILTER_SANITIZE_ENCODED` | 514 | URL 编码（rawurlencode 规则） |
| `FILTER_SANITIZE_SPECIAL_CHARS` | 515 | HTML 转义 `<>"'&` |
| `FILTER_SANITIZE_EMAIL` | 517 | 去除 email 非法字符 |
| `FILTER_SANITIZE_URL` | 518 | 去除 URL 非法字符 |
| `FILTER_SANITIZE_NUMBER_INT` | 519 | 仅保留数字和 `+-` |
| `FILTER_SANITIZE_NUMBER_FLOAT` | 520 | 仅保留数字和 `+-.,eE` |
| `FILTER_SANITIZE_ADD_SLASHES` | 523 | addslashes |
| `FILTER_SANITIZE_FULL_SPECIAL_CHARS` | 522 | 完整 HTML 实体转义 |

### 标志位（FILTER_FLAG_*）

| 常量 | 值 | 适用过滤器 |
|------|-----|----------|
| `FILTER_FLAG_ALLOW_OCTAL` | 1 | INT |
| `FILTER_FLAG_ALLOW_HEX` | 2 | INT |
| `FILTER_FLAG_STRIP_LOW` | 4 | STRING |
| `FILTER_FLAG_STRIP_HIGH` | 8 | STRING |
| `FILTER_FLAG_ENCODE_LOW` | 16 | STRING |
| `FILTER_FLAG_ENCODE_HIGH` | 32 | STRING |
| `FILTER_FLAG_ENCODE_AMP` | 64 | STRING |
| `FILTER_FLAG_NO_ENCODE_QUOTES` | 128 | STRING / SPECIAL_CHARS |
| `FILTER_FLAG_EMPTY_STRING_NULL` | 256 | STRING |
| `FILTER_FLAG_ALLOW_THOUSAND` | 8192 | FLOAT |
| `FILTER_FLAG_ALLOW_SCIENTIFIC` | 16384 | FLOAT |
| `FILTER_FLAG_PATH_REQUIRED` | 0x100000 | URL |
| `FILTER_FLAG_QUERY_REQUIRED` | 0x200000 | URL |
| `FILTER_FLAG_IPV4` | 0x100000 | IP |
| `FILTER_FLAG_IPV6` | 0x200000 | IP |

### options 数组

`filter_var` 第三参数可传关联数组，支持以下键：

| 键 | 适用过滤器 | 说明 |
|----|----------|------|
| `"flags"` | 所有 | 标志位组合（等价于 int 形式的第三参数） |
| `"min_range"` | INT | 最小值（含） |
| `"max_range"` | INT | 最大值（含） |

### 示例

```php
filter_var("42", FILTER_VALIDATE_INT);                     // int(42)
filter_var("abc", FILTER_VALIDATE_INT);                    // NULL
filter_var("user@example.com", FILTER_VALIDATE_EMAIL);     // "user@example.com"
filter_var("127.0.0.1", FILTER_VALIDATE_IP);              // "127.0.0.1"
filter_var("<b>hi</b>", FILTER_SANITIZE_SPECIAL_CHARS);    // "&lt;b&gt;hi&lt;/b&gt;"

// INT 范围验证
$opts = ["min_range" => 10, "max_range" => 100];
filter_var("50", FILTER_VALIDATE_INT, $opts);              // int(50)
filter_var("5", FILTER_VALIDATE_INT, $opts);               // NULL

// 八进制/十六进制
filter_var("077", FILTER_VALIDATE_INT, FILTER_FLAG_ALLOW_OCTAL);  // int(63)
filter_var("0xff", FILTER_VALIDATE_INT, FILTER_FLAG_ALLOW_HEX);   // int(255)
```

---

## exif — EXIF 图像元数据

> 文件: `ext/exif/src/exif.php`，按需引入 `#import exif`
>
> **纯 phpc 实现**，无自定义 C 代码。仅通过 C 标准库函数 (fopen/fgetc/fseek/ftell/fwrite/fclose) 实现二进制 JPEG/TIFF EXIF 格式解析。
> **所有函数参数/返回值使用 tphp 类型 (int/string/array)**，C 类型转换封装在函数内部:
> FILE* 指针通过 `phpc_ptr_to_int()` 转为 `t_int` 在 PHP 层流转，函数内部用 `phpc_int_to_ptr()` 转回 void* 调用 C 库。
> `defer C->fclose($f)` 确保文件句柄在所有退出路径（含异常）都正确关闭。

### 常量

| 常量 | 值 | 说明 |
|------|-----|------|
| `IMAGETYPE_GIF` | 1 | GIF 图像 |
| `IMAGETYPE_JPEG` | 2 | JPEG 图像 |
| `IMAGETYPE_PNG` | 3 | PNG 图像 |
| `IMAGETYPE_BMP` | 6 | BMP 图像 |
| `IMAGETYPE_TIFF_II` | 7 | TIFF (Intel 字节序, LE) |
| `IMAGETYPE_TIFF_MM` | 8 | TIFF (Motorola 字节序, BE) |
| `IMAGETYPE_WEBP` | 18 | WebP 图像 |

| TIFF 数据类型常量 | 值 | 说明 |
|------|-----|------|
| `EXIF_TYPE_BYTE` | 1 | uint8 |
| `EXIF_TYPE_ASCII` | 2 | null-terminated string |
| `EXIF_TYPE_SHORT` | 3 | uint16 |
| `EXIF_TYPE_LONG` | 4 | uint32 |
| `EXIF_TYPE_RATIONAL` | 5 | uint32 / uint32 |
| `EXIF_TYPE_UNDEFINED` | 7 | raw bytes |
| `EXIF_TYPE_SLONG` | 9 | int32 |
| `EXIF_TYPE_SRATIONAL` | 10 | int32 / int32 |

### 函数

| php函数 | tphp函数 | 性能说明 | 差异说明 |
|------|--------|------|------|
| `exif_imagetype(string $filename): int\|false` | `exif_imagetype(string $filename): int` | 读取文件头魔数 (2 字节)，O(1) | 文件无法打开 `tp_throw`（可 try-catch）；未知格式返回 `0`；支持 JPEG/GIF/PNG/BMP/TIFF_II/TIFF_MM |
| `exif_read_data(string $filename, string $sections = "", bool $arrays = false, bool $thumbnail = false): array\|false` | `exif_read_data(string $filename): array` | 逐字节解析 JPEG APP1/TIFF IFD，O(n) | 仅 1 参（无 `$sections`/`$arrays`/`$thumbnail`）；文件无法打开 `tp_throw`；无 EXIF 数据返回空数组；支持 IFD0/EXIF IFD/GPS IFD；支持 LE/BE 双字节序 |
| `exif_thumbnail(string $filename, int &$width, int &$height, int &$imagetype): string\|false` | `exif_thumbnail(string $filename): array` | 解析 IFD1 缩略图 | 返回关联数组 `["data"=>string, "width"=>int, "height"=>int, "imagetype"=>int]`（PHP 返回 string + byRef 参数）；无缩略图返回空数组 |
| `exif_tagname(int $index): string\|false` | `exif_tagname(int $index): string` | 预定义标签表 `strcmp` 查找 | 未知标签返回空字符串（非 `false`） |

### 支持的 EXIF 标签

| IFD | 标签 |
|-----|------|
| IFD0 (主图像) | Make, Model, Orientation, DateTime, Artist, Copyright, ImageDescription |
| EXIF IFD (拍摄参数) | ExposureTime, FNumber, ISOSpeedRatings, FocalLength, ExposureBiasValue, MeteringMode, Flash, WhiteBalance, ColorSpace, ExifImageWidth, ExifImageLength |
| GPS IFD | GPSLatitudeRef, GPSLatitude, GPSLongitudeRef, GPSLongitude, GPSAltitudeRef, GPSAltitude |

### 设计模式

```php
// 公开 API 纯 PHP 签名，参数/返回均为 tphp 类型
function exif_read_data(string $filename): array|Exception {
    // 内部用 phpc 桥接 C 标准库：FILE* → t_int 在 PHP 层流转
    $fp = phpc_ptr_to_int((C.void*)C->fopen(c_str($filename), c_str("rb")));
    if ($fp == 0) { throw new Exception("unable to open file"); }
    C.void* $f = phpc_int_to_ptr($fp);
    defer C->fclose($f);  // 所有退出路径自动关闭（含 return + fall-through）

    // 辅助函数接收 int $fp，内部用 phpc_int_to_ptr 转回 void* 调用 C 库
    $byte = exif_rd_byte($fp, $offset);   // function exif_rd_byte(int $fp, int $offset): int
    return $result;
}
```

> 测试: `test/exif/test_exif.php` (34 项检查，覆盖 JPEG LE/BE、TIFF II/MM、边界情况、thumbnail) 全部通过。

### 测试辅助函数

> 用于生成合成 JPEG/TIFF 文件供 `exif_read_data`/`exif_imagetype` 测试，非 PHP 原生 API。

| 函数 | 说明 |
|------|------|
| `exif_make_test_jpeg(string $filename): int` | 生成 JPEG+EXIF 文件（LE 字节序），返回 0=成功, -1=失败 |
| `exif_make_test_jpeg_ex(string $filename, int $le): int` | 生成 JPEG+EXIF 文件，`$le` 控制字节序 (1=LE/II, 0=BE/MM) |
| `exif_make_test_tiff(string $filename, int $le): int` | 生成 TIFF 文件，`$le` 控制字节序 |
| `exif_make_test_header(string $filename, int $b0, int $b1): int` | 生成指定 2 字节文件头的文件（测试 `exif_imagetype`） |

---

## calendar — 日历转换

> 文件: `ext/calendar/src/calendar.php`，按需引入 `#import calendar`
>
> **纯 tphp 实现**，无 C 代码、无外部依赖。基于 PHP ext/calendar 的 C 算法翻译为 tphp，所有日历转换基于儒略日 (Julian Day Number)。
> **AOT 错误处理**: 无效日期/超出范围 → `throw Exception`（不静默返回 0 或 "0/0/0"）。
> JD→日历转换返回 `array ["month","day","year"]`（全 int），不返回 PHP 的 "m/d/y" 字符串。
> 内部 helper 返回哨兵值 (0/`["year"=>0,...]`)，公共 API 检查后 throw — 异常不吞没。
> 犹太历 64 位直接算术（无需 C 源码的 32 位拆分溢出保护）。

### 常量

| 常量 | 值 | 说明 |
|------|-----|------|
| `CAL_GREGORIAN` | 0 | 公历 (Gregorian) |
| `CAL_JULIAN` | 1 | 儒略历 (Julian) |
| `CAL_JEWISH` | 2 | 犹太历 (Jewish / Hebrew) |
| `CAL_FRENCH` | 3 | 法国共和历 (French Republican) |
| `CAL_JEWISH_ADD_ALAFIM_GERESH` | 4 | 犹太历格式化标志（保留） |
| `CAL_NUM_CALS` | 4 | 日历类型总数 |
| `CAL_EASTER_DEFAULT` | 0 | 复活节算法：默认 |
| `CAL_EASTER_ROMAN` | 1 | 复活节算法：罗马 |
| `CAL_EASTER_ALWAYS_GREGORIAN` | 2 | 复活节算法：始终公历 |
| `CAL_EASTER_ALWAYS_JULIAN` | 3 | 复活节算法：始终儒略历 |

### 函数

| php函数 | tphp函数 | 性能说明 | 差异说明 |
|------|--------|------|------|
| `gregoriantojd(int $month, int $day, int $year): int` | `gregoriantojd(int $month, int $day, int $year): int\|Exception` | 纯整数算术，O(1) | 无效日期 `throw`（PHP 返回 0） |
| `jdtogregorian(int $jd): string` | `jdtogregorian(int $jd): array\|Exception` | 纯整数算术，O(1) | 返回 `["month","day","year"]` 数组（PHP 返回 "m/d/y" 字符串）；JD 超范围 `throw` |
| `juliantojd(int $month, int $day, int $year): int` | `juliantojd(int $month, int $day, int $year): int\|Exception` | 纯整数算术，O(1) | 无效日期 `throw` |
| `jdtojulian(int $jd): string` | `jdtojulian(int $jd): array\|Exception` | 纯整数算术，O(1) | 返回数组（非字符串）；JD 超范围 `throw` |
| `jewishtojd(int $month, int $day, int $year): int` | `jewishtojd(int $month, int $day, int $year): int\|Exception` | 纯整数算术，O(1) | 无效日期 `throw` |
| `jdtojewish(int $jd): string` | `jdtojewish(int $jd): array\|Exception` | 纯整数算术，O(1) | 返回数组（非字符串）；JD 超范围 `throw` |
| — | `jdtojewish_str(int $jd): string\|Exception` | 纯整数算术 + 月份名查找 | tphp 新增：返回 "day month_name year" 英文字符串 |
| — | `jewish_month_name(int $month): string` | O(1) 查表 | tphp 新增：返回犹太历月份英文名（闰年版本） |
| `frenchtojd(int $month, int $day, int $year): int` | `frenchtojd(int $month, int $day, int $year): int\|Exception` | 纯整数算术，O(1) | 无效日期 `throw`（PHP 返回 0）；仅支持年份 1-14 |
| `jdtofrench(int $jd): string` | `jdtofrench(int $jd): array\|Exception` | 纯整数算术，O(1) | 返回数组（非字符串）；JD 超范围 `throw` |
| `cal_days_in_month(int $calendar, int $month, int $year): int` | `cal_days_in_month(int $calendar, int $month, int $year): int\|Exception` | 两次 JD 计算，O(1) | 无效日历/日期 `throw`（PHP 返回 false） |
| `cal_from_jd(int $jd, int $calendar): array` | `cal_from_jd(int $jd, int $calendar): array\|Exception` | 一次 JD→日历转换，O(1) | 无效日历/JD `throw`；`date` 字段为 "m/d/y"；含 `dow`/`dayname`/`abbrevdayname`/`monthname`/`abbrevmonth` |
| `cal_to_jd(int $calendar, int $month, int $day, int $year): int` | `cal_to_jd(int $calendar, int $month, int $day, int $year): int\|Exception` | 分发到对应 xxxtojd，O(1) | 无效日历/日期 `throw` |
| `cal_info(int $calendar = -1): array` | `cal_info(int $calendar = -1): array\|Exception` | O(1) 查表 | 无效日历 `throw`；-1 返回所有日历信息（嵌套数组） |
| `easter_date(int $year, int $mode = 0): int` | `easter_date(int $year, int $mode = 0): int\|Exception` | Meeus/Jones/Butcher 算法，O(1) | year < 1970 `throw`（PHP 返回 false）；返回 Unix 时间戳 |
| `easter_days(int $year, int $mode = 0): int` | `easter_days(int $year, int $mode = 0): int\|Exception` | Meeus/Jones/Butcher 算法，O(1) | year <= 0 `throw`（PHP 返回 0）；返回距 3月21日的天数 |

### 设计模式

```php
// 内部 helper 返回哨兵值（不 throw），公共 API 检查后 throw
function _cal_gregorian_to_sdn(int $year, int $month, int $day): int {
    if ($year == 0 || $year < -4714 || $month <= 0 || $month > 12 || ...) {
        return 0;  // 哨兵值
    }
    // ... 纯整数算术
}

function gregoriantojd(int $month, int $day, int $year): int|Exception {
    $sdn = _cal_gregorian_to_sdn($year, $month, $day);
    if ($sdn == 0) {
        throw new Exception("gregoriantojd: invalid date");  // 有异常就报出
    }
    return $sdn;
}
```

> 测试: `test/calendar/test_calendar.php` (162 项检查，覆盖 4 种日历往返转换、复活节算法、异常处理) 全部通过。

---

## fileinfo — MIME 类型检测

> 文件: `include/fileinfo.h`（内置库，非 ext 按需引入）
>
> **不依赖 libmagic**，无需 magic.mgc 数据库文件分发。内置静态魔数表覆盖 60+ 常见文件类型（图片/音频/视频/文档/压缩包/字体/可执行文件/数据库/脚本/文本 BOM）。
> 使用 `Resource` 对象包装 finfo 状态（flags），字符串输出走 `str_pool_alloc` 自动释放。
> **AOT 单返回类型契约**: 失败统一 `tp_throw_ex(new_tphp_class_Exception(...))`，不返回 `false`。
> 文件检测只读前 512 字节（足够覆盖所有魔数偏移，含 TAR 偏移 257）。
> RIFF 格式二次检查（WAV/AVI/WebP 共享 RIFF 头，通过 sub-type 区分）。

### 常量

| 常量 | 值 | 说明 |
|------|-----|------|
| `FILEINFO_NONE` | 0 | 无特殊行为（返回文字描述） |
| `FILEINFO_SYMLINK` | 2 | 跟随符号链接 |
| `FILEINFO_DEVICES` | 8 | 查看设备内容 |
| `FILEINFO_MIME_TYPE` | 16 | 返回 MIME 类型 (如 "image/png") |
| `FILEINFO_CONTINUE` | 32 | 返回第一个匹配后继续查找 |
| `FILEINFO_PRESERVE_ATIME` | 128 | 不修改文件的访问时间 |
| `FILEINFO_RAW` | 256 | 不转换不可打印字符 |
| `FILEINFO_MIME_ENCODING` | 1024 | 返回 MIME 编码 (如 "binary"/"utf-8") |
| `FILEINFO_MIME` | 1040 | MIME_TYPE \| MIME_ENCODING (如 "image/jpeg; charset=binary") |
| `FILEINFO_EXTENSION` | 16777216 | 返回文件扩展名 (如 "jpeg"/"pdf") |

### 函数

| php函数 | tphp函数 | 性能说明 | 差异说明 |
|------|--------|------|------|
| `finfo_open(int $flags = FILEINFO_NONE, string $magic_file = ""): resource\|false` | `finfo_open(int $flags = FILEINFO_NONE, string $magic_file = ""): Resource\|Exception` | O(1) 分配 | 返回 `Resource`（非 `resource|false`）；`$magic_file` 保留兼容但忽略（内置魔数表）；失败 `throw` |
| `finfo_file(resource $finfo, string $filename, int $flags = FILEINFO_NONE): string\|false` | `finfo_file(Resource $finfo, string $filename, int $flags = FILEINFO_NONE): string\|Exception` | 读前 512B + O(n) 魔数匹配 | 失败 `throw`（空文件名/文件不存在/无效资源） |
| `finfo_buffer(resource $finfo, string $data, int $flags = FILEINFO_NONE): string\|false` | `finfo_buffer(Resource $finfo, string $data, int $flags = FILEINFO_NONE): string\|Exception` | O(n) 魔数匹配，零磁盘 I/O | 失败 `throw`（无效资源） |
| `finfo_close(resource $finfo): bool` | `finfo_close(Resource $finfo): void` | O(1) | 返回 `void`（非 `bool`） |
| `finfo_set_flags(resource $finfo, int $flags): bool` | `finfo_set_flags(Resource $finfo, int $flags): bool\|Exception` | O(1) | 始终返回 `true`；无效资源 `throw` |
| `mime_content_type(string $filename): string\|false` | `mime_content_type(string $filename): string\|Exception` | 读前 512B + O(n) 魔数匹配 | 等价 `finfo_open(MIME_TYPE)` + `finfo_file` + `finfo_close`；失败 `throw` |

### 设计模式

```c
// include/ 头文件风格：static inline + tphp_fn_ 前缀
// 错误用 tp_throw_ex（创建 Exception 对象，可被 catch(Exception) 捕获）
static inline t_string tphp_fn_finfo_file(tphp_class_Resource* finfo, t_string filename, t_int flags) {
    if (finfo == NULL || finfo->ptr == NULL) {
        tp_throw_ex(new_tphp_class_Exception(STR_LIT("finfo_file(): invalid fileinfo resource")));
        return (t_string){0};
    }
    if (STR_PTR(filename) == NULL || filename.length <= 0) {
        tp_throw_ex(new_tphp_class_Exception(STR_LIT("finfo_file(): empty filename")));
        return (t_string){0};
    }
    // ... 读取文件前 512 字节，魔数匹配
}
```

> 测试: `test/fileinfo/test_fileinfo.php` (104 项检查，覆盖 10 常量、40+ 文件类型 MIME 检测、5 种 flag 模式、finfo_file/mime_content_type、finfo_set_flags、5 个异常边界) 全部通过。

---

## zlib — 压缩/解压（gzip/zlib/deflate）

> 文件: `include/os/zlib.h`。依赖系统 zlib 库。编译器自动检测使用并链接（Linux/macOS: `-lz`，Windows+TCC: 直接链接 `zlib1.dll`，Windows+GCC/Clang: `-lz`）。
>
> 对标 PHP `ext/zlib`。错误统一抛 `Exception`（可 try-catch），不返回 `false`，符合 AOT 单返回类型契约。

### 常量

#### 编码格式

| 常量 | 值 | 说明 |
|------|---|------|
| `ZLIB_ENCODING_RAW` | -15 | 原始 DEFLATE（无头无校验，RFC 1951）|
| `ZLIB_ENCODING_GZIP` | 31 | gzip 格式（RFC 1952）|
| `ZLIB_ENCODING_DEFLATE` | 15 | zlib 格式（RFC 1950）|
| `FORCE_GZIP` | 31 | `ZLIB_ENCODING_GZIP` 别名 |
| `FORCE_DEFLATE` | 15 | `ZLIB_ENCODING_DEFLATE` 别名 |

#### 压缩级别

| 常量 | 值 | 说明 |
|------|---|------|
| `ZLIB_NO_COMPRESSION` | 0 | 不压缩 |
| `ZLIB_BEST_SPEED` | 1 | 最快速度 |
| `ZLIB_BEST_COMPRESSION` | 9 | 最小体积 |
| `ZLIB_DEFAULT_COMPRESSION` | -1 | 默认（zlib 默认级别=6）|

#### flush 模式（增量上下文）

| 常量 | 值 | 说明 |
|------|---|------|
| `ZLIB_NO_FLUSH` | 0 | 不刷新 |
| `ZLIB_PARTIAL_FLUSH` | 1 | 部分刷新 |
| `ZLIB_SYNC_FLUSH` | 2 | 同步刷新（`deflate_add`/`inflate_add` 默认）|
| `ZLIB_FULL_FLUSH` | 3 | 完全刷新 |
| `ZLIB_FINISH` | 4 | 结束输入（`ZLIB_FINISH` = 4）|
| `ZLIB_BLOCK` | 5 | 块模式 |

#### 压缩策略

| 常量 | 值 | 说明 |
|------|---|------|
| `ZLIB_FILTERED` | 1 | 过滤策略 |
| `ZLIB_HUFFMAN_ONLY` | 2 | 仅 Huffman |
| `ZLIB_RLE` | 3 | RLE 策略 |
| `ZLIB_FIXED` | 4 | 固定 Huffman |
| `ZLIB_DEFAULT_STRATEGY` | 0 | 默认策略 |

#### 状态码

| 常量 | 值 | 说明 |
|------|---|------|
| `ZLIB_OK` | 0 | 成功 |
| `ZLIB_STREAM_END` | 1 | 流结束 |
| `ZLIB_NEED_DICT` | 2 | 需要字典 |
| `ZLIB_ERRNO` | -1 | 系统错误 |
| `ZLIB_STREAM_ERROR` | -2 | 流错误 |
| `ZLIB_DATA_ERROR` | -3 | 数据错误 |
| `ZLIB_MEM_ERROR` | -4 | 内存错误 |
| `ZLIB_BUF_ERROR` | -5 | 缓冲区错误 |
| `ZLIB_VERSION_ERROR` | -6 | 版本不兼容 |

#### 其他

| 常量 | 值 | 说明 |
|------|---|------|
| `ZLIB_VERSION` | "1.3.2" | zlib 版本字符串 |
| `ZLIB_VERNUM` | 0x1320 | zlib 版本号 |

### 函数

#### 基础压缩/解压

| 函数 | 默认格式 | 说明 |
|------|---------|------|
| `gzcompress(string $data, int $level = -1, int $encoding = ZLIB_ENCODING_DEFLATE): string` | zlib | 压缩字符串 |
| `gzuncompress(string $data, int $max_length = 0, int $encoding = ZLIB_ENCODING_DEFLATE): string` | zlib | 解压 gzcompress 输出 |
| `gzencode(string $data, int $level = -1, int $encoding = ZLIB_ENCODING_GZIP): string` | gzip | 创建 gzip 压缩数据 |
| `gzdecode(string $data, int $max_length = 0): string` | auto | 解码 gzip 数据（自动检测格式）|
| `gzdeflate(string $data, int $level = -1, int $encoding = ZLIB_ENCODING_RAW): string` | raw | 原始 DEFLATE 压缩 |
| `gzinflate(string $data, int $max_length = 0): string` | raw | 解压原始 DEFLATE 数据 |
| `zlib_encode(string $data, int $encoding, int $level = -1): string` | 由 $encoding 指定 | 通用编码（与 `gzdeflate`/`gzcompress`/`gzencode` 等价的统一接口）|
| `zlib_decode(string $data, int $max_length = 0): string` | auto | 通用解码（自动检测 zlib/gzip 格式，不支持 raw）|

> `$level`: -1（默认）~ 9。`$max_length`: 0=无限制，>0=限制最大输出。失败时抛 `Exception`（可 try-catch），不返回 false。

#### gz 文件流 API

> gzFile 封装为 `Resource`，通过 `tphp_rt_register_resource_type` 注册析构回调，作用域结束自动 `gzclose`。

| 函数 | 返回 | 说明 |
|------|------|------|
| `gzopen(string $filename, string $mode): Resource` | Resource | 打开 gz 文件（mode 同 fopen，可附加压缩级别如 "wb9"）|
| `gzclose(Resource $stream): bool` | bool | 关闭 gz 文件 |
| `gzread(Resource $stream, int $length): string` | string | 读取指定长度（最多 length 字节）|
| `gzwrite(Resource $stream, string $data, int $length = 0): int` | int | 写入数据（0=写入全部），返回写入字节数 |
| `gzputs(Resource $stream, string $data, int $length = 0): int` | int | `gzwrite` 别名 |
| `gzeof(Resource $stream): bool` | bool | 是否到达文件尾（注意：仅在读取超出末尾后才返回 true）|
| `gzgets(Resource $stream, int $length = 0): string` | string | 读取一行（0=缓冲区大小）|
| `gzgetc(Resource $stream): string` | string | 读取单个字符 |
| `gzrewind(Resource $stream): bool` | bool | 重置到文件开头 |
| `gzseek(Resource $stream, int $offset, int $whence = SEEK_SET): int` | int | 定位（whence: 0=SEEK_SET, 1=SEEK_CUR），返回新位置 |
| `gztell(Resource $stream): int` | int | 返回当前位置 |
| `gzpassthru(Resource $stream): int` | int | 读取剩余数据并输出到 stdout，返回输出字节数 |
| `gzflush(Resource $stream, int $flush = ZLIB_SYNC_FLUSH): bool` | bool | 刷新输出缓冲区 |
| `gzfile(string $filename): array` | array<string> | 读取整个 gz 文件到数组（每行一个元素）|
| `readgzfile(string $filename): int` | int | 读取整个 gz 文件并输出到 stdout，返回输出字节数 |

#### 增量上下文 API（流式压缩/解压）

> 上下文封装为 `Resource`，支持分块输入。`ZLIB_FINISH` 表示输入结束。

| 函数 | 返回 | 说明 |
|------|------|------|
| `deflate_init(int $encoding, int $level = -1): Resource` | Resource | 创建压缩上下文（encoding: RAW/DEFLATE/GZIP）|
| `deflate_add(Resource $context, string $data, int $flush_mode = ZLIB_SYNC_FLUSH): string` | string | 增量压缩数据块 |
| `inflate_init(int $encoding): Resource` | Resource | 创建解压上下文（encoding: RAW/DEFLATE/GZIP/0=自动检测）|
| `inflate_add(Resource $context, string $data, int $flush_mode = ZLIB_SYNC_FLUSH): string` | string | 增量解压数据块 |
| `inflate_get_status(Resource $context): int` | int | 返回 zlib 状态码（如 `ZLIB_STREAM_END`=1 表示流结束）|
| `inflate_get_read_len(Resource $context): int` | int | 返回已解压的总字节数 |

### 用法

```php
$data = str_repeat("hello world ", 100);

// 基础压缩/解压
$compressed = gzcompress($data);         // zlib 格式
$restored = gzuncompress($compressed);   // 解压
var_dump(strlen($compressed) < strlen($data));  // true

$gz = gzencode($data);                   // gzip 格式
var_dump(gzdecode($gz) === $data);       // true

// zlib_encode / zlib_decode 通用接口
$enc = zlib_encode($data, ZLIB_ENCODING_GZIP);
$dec = zlib_decode($enc);
var_dump($dec === $data);                // true

// gz 文件流
$fp = gzopen("file.gz", "wb");
gzwrite($fp, $data);
gzclose($fp);

$fp = gzopen("file.gz", "rb");
$content = gzread($fp, 1024);
gzclose($fp);

// 增量上下文
$ctx = deflate_init(ZLIB_ENCODING_GZIP);
$c1 = deflate_add($ctx, "hello ", ZLIB_NO_FLUSH);
$c2 = deflate_add($ctx, "world", ZLIB_FINISH);

$ictx = inflate_init(ZLIB_ENCODING_GZIP);
$d1 = inflate_add($ictx, $c1, ZLIB_NO_FLUSH);
$d2 = inflate_add($ictx, $c2, ZLIB_FINISH);
echo $d1 . $d2;  // "hello world"
```

> 测试: `test/zlib/basic.php`（9 项：三种格式往返 + 压缩级别 + gz 文件读写 + seek/tell/rewind + gzpassthru + gzfile/readgzfile + 增量上下文 + 错误处理）全部通过。

---

## zip — ZIP 归档读写

> 文件: `include/os/zip.h`。依赖系统 zlib 库。手写 ZIP 容器格式（本地文件头/中央目录/EOCD），DEFLATE 压缩复用 zlib。ZipArchive 作为 `Resource` 子类。
>
> 对标 PHP `ext/zip`。错误统一抛 `Exception`（可 try-catch），不返回 `false`，符合 AOT 单返回类型契约。
>
> **限制**：不支持修改已有归档（`zip_delete`/`zip_rename` 会抛异常，建议创建新归档替代）。

### 常量

#### 打开模式

| 常量 | 值 | 说明 |
|------|---|------|
| `ZIP_CREATE` | 1 | 创建新文件（不存在时创建）|
| `ZIP_EXCL` | 2 | 排他创建（存在则失败）|
| `ZIP_CHECKCONS` | 4 | 检查一致性 |
| `ZIP_TRUNCATE` | 8 | 截断（若存在则覆盖）|
| `ZIP_RDONLY` | 16 | 只读 |

#### 标志位

| 常量 | 值 | 说明 |
|------|---|------|
| `ZIP_FL_OVERWRITE` | 1 | 覆盖现有文件 |
| `ZIP_FL_NOCASE` | 2 | 不区分大小写 |
| `ZIP_FL_NODIR` | 4 | 不为目录创建条目 |
| `ZIP_FL_COMPRESSED` | 8 | 读取压缩数据 |
| `ZIP_FL_UNCHANGED` | 16 | 使用原始数据 |

#### 压缩方法

| 常量 | 值 | 说明 |
|------|---|------|
| `ZIP_CM_DEFAULT` | -1 | 默认压缩方法 |
| `ZIP_CM_STORE` | 0 | 不压缩（Stored）|
| `ZIP_CM_DEFLATE` | 8 | DEFLATE 压缩 |

### 函数

#### 归档操作

| 函数 | 返回 | 说明 |
|------|------|------|
| `zip_open(string $filename, int $flags = 0): Resource` | Resource | 打开/创建 ZIP（返回 Resource）|
| `zip_close(Resource $zip): bool` | bool | 关闭归档（写入模式刷盘）|
| `zip_num_files(Resource $zip): int` | int | 返回文件总数 |
| `zip_get_error_string(Resource $zip): string` | string | 返回最后错误描述 |
| `zip_locate(Resource $zip, string $name): int` | int | 按名查找条目索引（未找到返回 -1）|

#### 条目信息查询

| 函数 | 返回 | 说明 |
|------|------|------|
| `zip_read(Resource $zip): array` | array | 返回所有条目列表（每项含 name/index/size/comp_size/comp_method/mtime）|
| `zip_stat(Resource $zip, int $index): array` | array | 获取单个条目信息（同 zip_read 单项结构）|
| `zip_entry_name(Resource $zip, int $index): string` | string | 返回条目名 |
| `zip_entry_filesize(Resource $zip, int $index): int` | int | 返回条目原始大小 |
| `zip_entry_compressedsize(Resource $zip, int $index): int` | int | 返回条目压缩后大小 |
| `zip_entry_compressionmethod(Resource $zip, int $index): string` | string | 返回压缩方法名（"Stored"/"Deflated"）|

#### 条目读写

| 函数 | 返回 | 说明 |
|------|------|------|
| `zip_entry_open(Resource $zip, int $index): bool` | bool | 打开条目准备读取 |
| `zip_entry_read(Resource $zip, int $index, int $length = 0): string` | string | 读取条目内容（0=全部）|
| `zip_entry_close(Resource $zip): bool` | bool | 关闭当前条目 |
| `zip_add_file(Resource $zip, string $name, string $data, int $flags = 0, int $comp_method = ZIP_CM_DEFLATE): bool` | bool | 添加文件 |
| `zip_add_dir(Resource $zip, string $dirname, int $flags = 0): bool` | bool | 添加目录（以 / 结尾）|
| `zip_delete(Resource $zip, int $index): bool` | bool | 删除条目（**不支持修改已有归档，抛异常**）|
| `zip_rename(Resource $zip, int $index, string $new_name): bool` | bool | 重命名条目（**不支持修改已有归档，抛异常**）|

### 用法

```php
// 创建 ZIP
$zip = zip_open("archive.zip", ZIP_CREATE);
zip_add_file($zip, "hello.txt", "hello world");
zip_add_file($zip, "data/config.json", '{"key":"value"}');
zip_add_dir($zip, "logs/");
zip_close($zip);

// 读取 ZIP
$zip2 = zip_open("archive.zip");
echo zip_num_files($zip2);           // 3
echo zip_entry_name($zip2, 0);       // "hello.txt"
echo zip_entry_filesize($zip2, 0);   // 11
echo zip_entry_compressionmethod($zip2, 0);  // "Deflated"
$idx = zip_locate($zip2, "hello.txt");  // 0
$content = zip_entry_read($zip2, 0); // "hello world"
zip_close($zip2);
```

> 测试: `test/zip/basic.php`（9 项：创建 ZIP + 条目名 + 条目大小 + 压缩方法 + 压缩后大小 + zip_locate + zip_entry_read + zip_stat + 错误处理）全部通过。

---

## curl — HTTP 客户端 ✅ 已完成

> 文件: `ext/curl/src/curl.h`。按需引入 `#import curl`（自动依赖 stream + openssl）。
>
> **实现状态**: 已完成。纯 PHP（phpc）实现，**不依赖 libcurl C 库**。
> HTTP 走 ext/stream 的 socket；HTTPS 走 ext/openssl 的 mbedTLS（3.6.6 静态编译）。
> 仅支持 HTTP/HTTPS 协议（其他协议返回 CURLE_UNSUPPORTED_PROTOCOL）；仅支持 CURLAUTH_BASIC 认证。
> 不支持 SOCKS 代理、curl_multi 并行、curl_share 共享（执行类 stub 抛 Exception，不静默成功）。
> 包含顺序: openssl.h 必须在 stream.h 之前（`TPHP_STREAM_TLS_IMPLEMENTED` 守卫）。
>
> **与 PHP 原生差异**（类型安全考量）:
> - `curl_init()` 返回 `CurlHandle`（不返回 `false`，失败抛 Exception）；不用 `?string`，默认空字符串。
> - `curl_exec()` 始终返回 `bool`；响应体存于 `handle.lastResponse`，用 `curl_multi_getcontent()` 获取。
> - `curl_getinfo()` 仅支持 `$option=0`（返回完整数组）；非 0 抛 Exception（避免 `mixed` 返回）。
> - `curl_strerror()` 未知码返回空字符串（不返回 `?string`）。
> - `curl_escape`/`curl_unescape` 失败抛 Exception（不返回 `false`）。
> - CURLFile 用空字符串 `""` 替代 PHP 的 `null` 表示"未指定"。
>
> 测试:
> - `test/curl/curl_unit.php`（无需网络，15+ 节覆盖全部 35 函数 + 690 常量全量遍历）。
> - `test/curl/curl_stub_test.php`（无需网络，multi/share stub 拒绝 + 不支持协议/认证/代理）。
> - `test/curl/curl_basic.php`（需网络，`@skip`，15 节真实 HTTP/HTTPS 集成测试）。

### 常量

> 共 **690 个常量**，定义于 `ext/curl/src/curl_constants.php`。以下按类别列出常用项，完整列表见源文件。

#### CURLOPT_\* 传输选项（~250 个）

| 常量 | 值 | 说明 |
|------|---|------|
| `CURLOPT_URL` | 10002 | string: 请求 URL |
| `CURLOPT_RETURNTRANSFER` | 19913 | bool: 返回响应体而不直接输出 |
| `CURLOPT_POST` | 47 | bool: 发送 POST 请求 |
| `CURLOPT_POSTFIELDS` | 10015 | string/array: POST 数据 |
| `CURLOPT_HTTPHEADER` | 10023 | array: 自定义 HTTP 头 |
| `CURLOPT_FOLLOWLOCATION` | 52 | bool: 跟随 3xx 重定向 |
| `CURLOPT_MAXREDIRS` | 68 | int: 最大重定向次数 |
| `CURLOPT_TIMEOUT` | 13 | int: 请求超时秒数 |
| `CURLOPT_CONNECTTIMEOUT` | 78 | int: 连接超时秒数 |
| `CURLOPT_TIMEOUT_MS` | 155 | int: 请求超时毫秒 |
| `CURLOPT_CONNECTTIMEOUT_MS` | 156 | int: 连接超时毫秒 |
| `CURLOPT_SSL_VERIFYPEER` | 64 | bool: 验证 SSL 证书 |
| `CURLOPT_SSL_VERIFYHOST` | 81 | int: 验证 SSL hostname |
| `CURLOPT_CAINFO` | 10065 | string: CA 证书文件 |
| `CURLOPT_SSLCERT` | 10025 | string: 客户端证书 |
| `CURLOPT_SSLKEY` | 10087 | string: 私钥文件 |
| `CURLOPT_USERAGENT` | 10018 | string: User-Agent 头 |
| `CURLOPT_REFERER` | 10016 | string: Referer 头 |
| `CURLOPT_COOKIE` | 10022 | string: Cookie 头 |
| `CURLOPT_COOKIEFILE` | 10031 | string: 读取 Cookie 文件 |
| `CURLOPT_COOKIEJAR` | 10082 | string: 写入 Cookie 文件 |
| `CURLOPT_PROXY` | 10004 | string: 代理地址 |
| `CURLOPT_PROXYPORT` | 59 | int: 代理端口 |
| `CURLOPT_PROXYTYPE` | 101 | int: 代理类型（HTTP/SOCKS4/SOCKS5） |
| `CURLOPT_HTTPAUTH` | 107 | int: HTTP 认证方法 |
| `CURLOPT_USERPWD` | 10005 | string: "user:pass" 认证 |
| `CURLOPT_USERNAME` | 10113 | string: 用户名 |
| `CURLOPT_PASSWORD` | 10115 | string: 密码 |
| `CURLOPT_HTTPGET` | 80 | bool: 强制 GET |
| `CURLOPT_NOBODY` | 44 | bool: 不下载响应体（HEAD） |
| `CURLOPT_CUSTOMREQUEST` | 10036 | string: 自定义请求方法 |
| `CURLOPT_VERBOSE` | 41 | bool: 详细输出 |
| `CURLOPT_HEADER` | 42 | bool: 响应中包含 HTTP 头 |
| `CURLOPT_UPLOAD` | 46 | bool: 上传模式 |
| `CURLOPT_INFILESIZE` | 14 | int: 上传文件大小 |
| `CURLOPT_HTTP_VERSION` | 84 | int: HTTP 版本 |
| `CURLOPT_ACCEPT_ENCODING` | 10102 | string: Accept-Encoding 头 |
| `CURLOPT_PORT` | 3 | int: 端口号 |
| `CURLOPT_RANGE` | 10007 | string: Range 头 |
| `CURLOPT_FILE` | 10001 | stream: 输出到文件（保留参数） |

> 完整列表另含: CURLOPT_SSLVERSION / CURLOPT_SSLCERTTYPE / CURLOPT_SSLKEYPASSWD /
> CURLOPT_PROXYUSERPWD / CURLOPT_NOPROGRESS / CURLOPT_IPRESOLVE / CURLOPT_FAILONERROR /
> CURLOPT_ENCODING / CURLOPT_TRANSFERTEXT / CURLOPT_CRLF / CURLOPT_AUTOREFERER 等约 250 项。

#### CURLINFO_\* 信息常量（~40 个）

| 常量 | 值 | 说明 |
|------|---|------|
| `CURLINFO_HTTP_CODE` | 0x2000001 | int: HTTP 状态码 |
| `CURLINFO_TOTAL_TIME` | 0x3000001 | float: 总耗时 |
| `CURLINFO_CONNECT_TIME` | 0x3000004 | float: 连接耗时 |
| `CURLINFO_SIZE_DOWNLOAD` | 0x3000006 | float: 下载字节数 |
| `CURLINFO_CONTENT_TYPE` | 0x100000C | string: Content-Type |
| `CURLINFO_HEADER_SIZE` | 0x2000002 | int: 头部大小 |
| `CURLINFO_REDIRECT_COUNT` | 0x2000008 | int: 重定向次数 |
| `CURLINFO_EFFECTIVE_URL` | 0x1000001 | string: 最终 URL |
| `CURLINFO_PRIMARY_IP` | 0x100000F | string: 主 IP |
| `CURLINFO_PRIMARY_PORT` | 0x200010 | int: 主端口 |

> 注: `curl_getinfo()` 仅支持 `$option=0`（返回完整数组），单 option 查询会抛 Exception。
> 数组字段: url/content_type/http_code/header_size/request_size/filetime/ssl_verify_result/
> redirect_count/total_time/namelookup_time/connect_time/pretransfer_time/size_upload/
> size_download/speed_download/download_content_length/upload_content_length/starttransfer_time/
> redirect_time/redirect_url/primary_ip/primary_port/local_ip/local_port/http_version/protocol/
> scheme/appconnect_time/effective_method。

#### CURLE_\* 错误码（~74 个）

| 常量 | 值 | 说明 |
|------|---|------|
| `CURLE_OK` | 0 | 无错误 |
| `CURLE_UNSUPPORTED_PROTOCOL` | 1 | 不支持的协议（非 http/https） |
| `CURLE_COULDNT_RESOLVE_HOST` | 6 | DNS 解析失败 |
| `CURLE_COULDNT_CONNECT` | 7 | 连接失败 |
| `CURLE_OPERATION_TIMEDOUT` | 28 | 操作超时 |
| `CURLE_TOO_MANY_REDIRECTS` | 47 | 重定向次数超限 |
| `CURLE_BAD_PASSWORD_ENTERED` | 46 | 认证失败 |
| `CURLE_SSL_CONNECT_ERROR` | 35 | SSL 连接错误 |
| `CURLE_PEER_FAILED_VERIFICATION` | 60 | 证书验证失败 |
| `CURLE_RECV_ERROR` | 56 | 接收错误 |
| `CURLE_SEND_ERROR` | 55 | 发送错误 |
| `CURLE_BAD_FUNCTION_ARGUMENT` | 43 | 参数错误 |

#### CURLAUTH_\* / CURLPROXY_\* 认证与代理

| 常量 | 值 | 说明 |
|------|---|------|
| `CURLAUTH_NONE` | 0 | 无认证 |
| `CURLAUTH_BASIC` | 1 | Basic 认证（仅此项支持） |
| `CURLAUTH_DIGEST` | 2 | Digest 认证（不支持） |
| `CURLAUTH_NEGOTIATE` | 4 | Negotiate（不支持） |
| `CURLAUTH_NTLM` | 8 | NTLM（不支持） |
| `CURLAUTH_ANY` | -17 | 任意（回退到 Basic） |
| `CURLPROXY_HTTP` | 0 | HTTP 代理 |
| `CURLPROXY_SOCKS4` | 4 | SOCKS4（不支持） |
| `CURLPROXY_SOCKS5` | 5 | SOCKS5（不支持） |

#### 其他常量类别

| 类别 | 前缀 | 说明 |
|------|------|------|
| HTTP 版本 | `CURL_HTTP_VERSION_*` | NONE/1_0/1_1/2_0/2TLS/3_0/3ONLY |
| 暂停标志 | `CURLPAUSE_*` | RECV/SEND/ALL/CONT |
| 协议位掩码 | `CURLPROTO_*` | HTTP/HTTPS/FTP/... |
| 版本特性 | `CURL_VERSION_*` | IPV6/SSL/LARGEFILE/... |
| Multi 选项 | `CURLMOPT_*` | PIPELINING/MAXCONNECTS/... |
| SSH 选项 | `CURLSSH_*` | AUTH_PUBLICKEY/AUTH_PASSWORD/... |
| SSL 选项 | `CURLSSLSET_*` | OK/NO_BACKENDS/... |
| RTSP 请求 | `CURL_RTSPREQ_*` | NONE/DESCRIBE/SETUP/PLAY/... |
| FTP SSL | `CURLFTPSSL_*` | NONE/TRY/CONTROL/ALL |
| 使用 SSL | `CURLUSESSL_*` | NONE/TRY/CONTROL/ALL |
| 代理错误 | `CURLPX_*` | OK/BAD_ADDRESS/... |

### 类

| 类 | 说明 |
|------|------|
| `CurlHandle` | cURL 会话句柄（final）。含 URL/method/headers/body/认证/代理/SSL/上传等全部配置字段 |
| `CurlMultiHandle` | 多句柄容器（final，空类，仅类型标记）。执行类函数抛 Exception |
| `CurlShareHandle` | 共享句柄容器（final，空类，仅类型标记）。curl_share_setopt 抛 Exception |
| `CurlSharePersistentHandle` | 持久化共享句柄（final）。`public readonly array $options` |
| `CURLFile` | 磁盘文件上传类（multipart/form-data） |
| `CURLStringFile` | 字符串内容作为文件上传（since PHP 8.0） |

```php
class CURLFile
{
    public string $name = "";       // 磁盘文件路径
    public string $mime = "";       // MIME 类型（空=按扩展名推导）
    public string $postname = "";   // 上传文件名（空=basename）

    public function __construct(string $filename, string $mime_type = "", string $posted_filename = "");
    public function getFilename(): string;
    public function getMimeType(): string;
    public function getPostFilename(): string;
    public function setMimeType(string $mime_type): void;
    public function setPostFilename(string $posted_filename): void;
}

class CURLStringFile
{
    public string $data;     // 文件内容字符串
    public string $postname; // 上传文件名（必须）
    public string $mime;     // MIME 类型（默认 application/octet-stream）

    public function __construct(string $data, string $postname, string $mime = "application/octet-stream");
}
```

### 函数

#### Easy Handle（18 个，完整实现）

| 函数 | 说明 |
|------|------|
| `curl_init(string $url = ""): CurlHandle` | 初始化 cURL 会话（默认空 URL） |
| `curl_close(CurlHandle $handle): void` | 关闭 cURL 会话，释放资源 |
| `curl_reset(CurlHandle $handle): void` | 重置句柄所有选项为默认值（保留句柄） |
| `curl_copy_handle(CurlHandle $handle): CurlHandle` | 深拷贝句柄（含所有选项） |
| `curl_upkeep(CurlHandle $handle): bool` | 维持连接活跃（保活探测） |
| `curl_pause(CurlHandle $handle, int $flags): int` | 暂停/恢复传输（CURLPAUSE_*） |
| `curl_setopt(CurlHandle $handle, int $option, mixed $value): bool` | 设置单个传输选项 |
| `curl_setopt_array(CurlHandle $handle, array $options): bool` | 批量设置多个选项 |
| `curl_exec(CurlHandle $handle): bool` | 执行会话；响应体用 curl_multi_getcontent 获取 |
| `curl_error(CurlHandle $handle): string` | 返回最后一次操作的错误描述 |
| `curl_errno(CurlHandle $handle): int` | 返回最后一次操作的错误码（CURLE_*） |
| `curl_strerror(int $error_code): string` | 错误码 → 描述（静态函数） |
| `curl_version(): array` | 返回版本信息（version/ssl_version/protocols/...） |
| `curl_escape(CurlHandle $handle, string $string): string` | URL 编码（RFC 3986） |
| `curl_unescape(CurlHandle $handle, string $string): string` | URL 解码 |
| `curl_file_create(string $filename, string $mime_type = "", string $posted_filename = ""): CURLFile` | 创建 CURLFile 对象 |
| `curl_getinfo(CurlHandle $handle, int $option = 0): array\|Exception` | 获取传输信息数组（$option=0 返回全部） |
| `curl_multi_getcontent(CurlHandle $handle): string` | 获取 curl_exec 后的响应体字符串 |

#### Multi Handle（11 个，6 个 stub 抛 Exception）

| 函数 | 说明 |
|------|------|
| `curl_multi_init(): CurlMultiHandle` | 创建 multi 句柄 |
| `curl_multi_close(CurlMultiHandle $multi_handle): void` | 关闭 multi 句柄（无操作） |
| `curl_multi_errno(CurlMultiHandle $multi_handle): int` | 返回 multi 错误码（始终 0） |
| `curl_multi_strerror(int $error_code): string` | multi 错误码 → 描述（委托 curl_strerror） |
| `curl_multi_get_handles(CurlMultiHandle $multi_handle): array` | 返回已添加 easy 句柄列表（stub 返回空） |
| `curl_multi_add_handle(CurlMultiHandle $multi_handle, CurlHandle $handle): int\|Exception` | stub，抛 Exception（无异步 I/O） |
| `curl_multi_remove_handle(CurlMultiHandle $multi_handle, CurlHandle $handle): int\|Exception` | stub，抛 Exception |
| `curl_multi_exec(CurlMultiHandle $multi_handle, int &$still_running): int\|Exception` | stub，抛 Exception（用顺序 curl_exec 替代） |
| `curl_multi_select(CurlMultiHandle $multi_handle, float $timeout = 1.0): int\|Exception` | stub，抛 Exception |
| `curl_multi_info_read(CurlMultiHandle $multi_handle, int &$queued_messages = 0): array\|Exception` | stub，抛 Exception |
| `curl_multi_setopt(CurlMultiHandle $multi_handle, int $option, int $value): bool\|Exception` | stub，抛 Exception |

#### Share Handle（6 个，2 个 stub 抛 Exception）

| 函数 | 说明 |
|------|------|
| `curl_share_init(): CurlShareHandle` | 创建 share 句柄 |
| `curl_share_close(CurlShareHandle $share_handle): void` | 关闭 share 句柄（无操作，PHP 8.5 已弃用） |
| `curl_share_errno(CurlShareHandle $share_handle): int` | 返回 share 错误码（始终 0） |
| `curl_share_strerror(int $error_code): string` | share 错误码 → 描述（委托 curl_strerror） |
| `curl_share_setopt(CurlShareHandle $share_handle, int $option, int $value): bool\|Exception` | stub，抛 Exception（无共享连接池） |
| `curl_share_init_persistent(array $share_options): CurlSharePersistentHandle\|Exception` | stub，抛 Exception |

### 示例

```php
#import stream
#import openssl
#import curl

class Main
{
    public function main(): void
    {
        // ── HTTP GET ──────────────────────────────────────
        $ch = curl_init("http://httpbin.org/get");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_exec($ch);
        $body = curl_multi_getcontent($ch);
        $info = curl_getinfo($ch);          // 完整信息数组
        echo $info["http_code"] . "\n";      // 200
        curl_close($ch);

        // ── HTTPS GET（自动 TLS） ────────────────────────
        $ch = curl_init("https://example.com");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        if (curl_exec($ch)) {
            echo curl_multi_getcontent($ch);
        } else {
            echo curl_error($ch);            // 错误描述
            echo curl_errno($ch);             // 错误码 (CURLE_*)
        }
        curl_close($ch);

        // ── POST JSON ────────────────────────────────────
        $ch = curl_init("http://httpbin.org/post");
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => '{"key":"value"}',
            CURLOPT_HTTPHEADER      => ["Content-Type: application/json"],
            CURLOPT_RETURNTRANSFER => true,
        ]);
        curl_exec($ch);
        echo curl_multi_getcontent($ch);
        curl_close($ch);

        // ── Basic Auth ───────────────────────────────────
        $ch = curl_init("http://httpbin.org/basic-auth/user/pass");
        curl_setopt_array($ch, [
            CURLOPT_HTTPAUTH        => CURLAUTH_BASIC,
            CURLOPT_USERPWD         => "user:pass",
            CURLOPT_RETURNTRANSFER => true,
        ]);
        curl_exec($ch);
        echo curl_getinfo($ch)["http_code"];  // 200
        curl_close($ch);

        // ── CURLFile 文件上传 ────────────────────────────
        $file = new CURLFile("/path/to/upload.txt", "text/plain", "upload.txt");
        $ch = curl_init("http://httpbin.org/post");
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => ["file" => $file],
            CURLOPT_RETURNTRANSFER => true,
        ]);
        curl_exec($ch);
        echo curl_multi_getcontent($ch);
        curl_close($ch);

        // ── CURLStringFile 字符串上传 ────────────────────
        $sf = new CURLStringFile("hello world", "hello.txt", "text/plain");
        $ch = curl_init("http://httpbin.org/post");
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => ["file" => $sf],
            CURLOPT_RETURNTRANSFER => true,
        ]);
        curl_exec($ch);
        curl_close($ch);

        // ── curl_strerror / curl_version ────────────────
        echo curl_strerror(CURLE_OK);          // "No error"
        $ver = curl_version();
        echo $ver["version"];                   // 版本号字符串
        echo implode(",", $ver["protocols"]);  // http,https

        // ── curl_escape / curl_unescape ──────────────────
        $ch = curl_init();
        echo curl_escape($ch, "hello world!"); // hello%20world%21
        echo curl_unescape($ch, "hello%20world"); // hello world
        curl_close($ch);

        // ── 不支持协议显式拒绝（不静默） ──────────────────
        $ch = curl_init("ftp://example.com");
        curl_exec($ch);                         // 返回 false
        echo curl_errno($ch);                   // 1 (CURLE_UNSUPPORTED_PROTOCOL)
        curl_close($ch);
    }
}
```

> 测试:
> - `test/curl/curl_unit.php`（无需网络）— 15+ 节覆盖全部 35 函数签名 + 690 常量全量遍历验证。
> - `test/curl/curl_stub_test.php`（无需网络）— multi/share stub 拒绝 + 不支持协议/认证/代理。
> - `test/curl/curl_basic.php`（`@skip`，需网络）— 15 节真实 HTTP/HTTPS 集成测试。

---

## stream — Socket Stream

> 文件: `ext/stream/src/stream.h`。按需引入 `#import stream`。
>
> 跨平台 socket stream：Windows winsock2 / POSIX sys/socket.h。socket fd 以 `t_int` 流转。
> Winsock 懒初始化（首次 socket 操作自动 `WSAStartup`），`FD_SETSIZE` 提升到 1024。
> AOT 单返回类型: 所有失败统一 `tp_throw_ex`（可 try-catch，不返回 `false`）。
> TLS 支持由 `ext/openssl` 扩展提供（`TPHP_STREAM_TLS_IMPLEMENTED` 守卫）。未加载 openssl 扩展时，
> `stream_socket_enable_crypto` 使用 stub，调用时抛 "TLS not supported" 异常并返回 `-1`。

### 常量

#### Socket 类型 / 协议

| 常量 | 值 | 说明 |
|------|---|------|
| `STREAM_SOCK_STREAM` | 1 | TCP 流 socket |
| `STREAM_SOCK_DGRAM` | 2 | UDP 数据报 socket |
| `STREAM_SOCK_RAW` | 3 | 原始 socket |
| `STREAM_SOCK_RDM` | 4 | 可靠数据报 |
| `STREAM_SOCK_SEQPACKET` | 5 | 顺序包 socket |
| `STREAM_PF_INET` | 2 | IPv4 |
| `STREAM_PF_INET6` | 10 | IPv6 |
| `STREAM_PF_UNIX` | 1 | Unix 域 socket |
| `STREAM_IPPROTO_IP` | 0 | IP 协议 |
| `STREAM_IPPROTO_TCP` | 6 | TCP |
| `STREAM_IPPROTO_UDP` | 17 | UDP |
| `STREAM_IPPROTO_ICMP` | 1 | ICMP |
| `STREAM_IPPROTO_RAW` | 255 | 原始 IP |

#### 客户端 / 服务端标志

| 常量 | 值 | 说明 |
|------|---|------|
| `STREAM_CLIENT_CONNECT` | 2 | 默认连接 |
| `STREAM_CLIENT_ASYNC_CONNECT` | 4 | 异步连接 |
| `STREAM_CLIENT_PERSISTENT` | 1 | 持久连接（保留参数） |
| `STREAM_SERVER_BIND` | 4 | 绑定地址 |
| `STREAM_SERVER_LISTEN` | 8 | 监听连接 |

#### Shutdown / 选项

| 常量 | 值 | 说明 |
|------|---|------|
| `STREAM_SHUT_RD` | 0 | 关闭读方向 |
| `STREAM_SHUT_WR` | 1 | 关闭写方向 |
| `STREAM_SHUT_RDWR` | 2 | 关闭双向 |
| `STREAM_OOB` | 1 | 带外数据 |
| `STREAM_PEEK` | 2 | 窥视（不消费数据） |
| `STREAM_OPTION_BLOCKING` | 1 | 阻塞模式 |
| `STREAM_OPTION_READ_BUFFER` | 3 | 读缓冲大小 |
| `STREAM_OPTION_READ_TIMEOUT` | 4 | 读超时 |
| `STREAM_OPTION_WRITE_BUFFER` | 5 | 写缓冲大小 |
| `STREAM_OPTION_CHUNK_SIZE` | 7 | 块大小 |

#### Crypto (TLS) — _CLIENT / _SERVER 两套

> 与 PHP 原生 bitmask 值完全一致。无后缀别名指向 `_CLIENT` 版本（向后兼容）。

| 常量 | 值 | 说明 |
|------|---|------|
| `STREAM_CRYPTO_METHOD_SSLv2_CLIENT` | 0x00 | SSLv2 客户端（已废弃） |
| `STREAM_CRYPTO_METHOD_SSLv3_CLIENT` | 0x01 | SSLv3 客户端 |
| `STREAM_CRYPTO_METHOD_SSLv23_CLIENT` | 0x02 | SSLv2.3/TLS 客户端（通用） |
| `STREAM_CRYPTO_METHOD_TLS_CLIENT` | 0x03 | TLS 客户端 |
| `STREAM_CRYPTO_METHOD_TLSv1_0_CLIENT` | 0x04 | TLS 1.0 客户端 |
| `STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT` | 0x08 | TLS 1.1 客户端 |
| `STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT` | 0x10 | TLS 1.2 客户端 |
| `STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT` | 0x20 | TLS 1.3 客户端 |
| `STREAM_CRYPTO_METHOD_ANY_CLIENT` | 0x3F | 任意 TLS 客户端 |
| `STREAM_CRYPTO_METHOD_*_SERVER` | 同上 | 服务端版本（值相同） |
| `STREAM_CRYPTO_METHOD_SSLv2` 等无后缀别名 | 同 `_CLIENT` | 向后兼容 |
| `STREAM_CRYPTO_PROTO_SSLv3` | 1 | PHP 8.1+ 别名 |
| `STREAM_CRYPTO_PROTO_TLSv1_0` | 2 | PHP 8.1+ 别名 |
| `STREAM_CRYPTO_PROTO_TLSv1_1` | 3 | PHP 8.1+ 别名 |
| `STREAM_CRYPTO_PROTO_TLSv1_2` | 4 | PHP 8.1+ 别名 |
| `STREAM_CRYPTO_PROTO_TLSv1_3` | 5 | PHP 8.1+ 别名 |
| `STREAM_CRYPTO_ENABLE` | 1 | 启用 TLS |
| `STREAM_CRYPTO_DISABLE` | 0 | 禁用 TLS |

### 函数

| TinyPHP 签名 | PHP 原生签名 | 实现差异 |
|--------------|-------------|---------|
| `stream_close(int $fd): void` | `stream_close($stream): void` | fd 直接为 int（非 Resource 对象） |
| `stream_last_error(): int` | `stream_last_error(): int` | Windows: WSAGetLastError；POSIX: errno |
| `stream_strerror(int $err): string` | `stream_strerror(int $code): string` | Windows: FormatMessageA；POSIX: strerror。消息语言取决于系统 |
| `stream_set_blocking(int $fd, bool $enable): bool` | `stream_set_blocking($stream, bool $enable): bool` | Windows: ioctlsocket；POSIX: fcntl |
| `stream_set_read_buffer(int $fd, int $buffer): int` | `stream_set_read_buffer($stream, int $buffer): int` | socket 无 stdio 缓冲，固定返回 0 |
| `stream_set_write_buffer(int $fd, int $buffer): int` | `stream_set_write_buffer($stream, int $buffer): int` | socket 无 stdio 缓冲，固定返回 0 |
| `stream_set_timeout(int $fd, int $seconds, int $microseconds = 0): bool` | `stream_set_timeout($stream, int $seconds, int $microseconds): bool` | `setsockopt(SO_RCVTIMEO/SO_SNDTIMEO)` |
| `stream_isatty(int $fd): bool` | `stream_isatty($stream): bool` | Windows: `_isatty`；POSIX: `isatty` |
| `stream_select(array $read, array $write, array $except, int $tv_sec, int $tv_usec = 0): int\|Exception` | `stream_select(array &$read, array &$write, array &$except, int $tv_sec, int $tv_usec = 0): int\|false` | in-place 过滤就绪 fd 并清除哈希索引；失败抛 Exception |
| `stream_get_contents(int $fd, int $length = -1, int $offset = -1): string\|Exception` | `stream_get_contents($stream, int $length, int $offset): string\|false` | `length=-1` 读全部；`offset=-1` 从当前位置（socket 不支持 offset） |
| `stream_get_line(int $fd, int $length, string $ending = ""): string\|Exception` | `stream_get_line($stream, int $length, string $ending): string\|false` | 读到分隔符或长度（不返回 ending） |
| `stream_get_meta_data(int $fd): array` | `stream_get_meta_data($stream): array` | 返回 `timed_out`/`blocked`/`eof`/`stream_type`/`unread_bytes`/`seekable` |
| `stream_context_create(array $options = []): int` | `stream_context_create(array $options = [], array $params = []): resource\|false` | 占位实现：返回 `0`；接收 `options` 数组参数保持签名兼容，但内容被忽略（TinyPHP 的 `stream_socket_server`/`stream_socket_client` 等忽略 context 参数 `(void)context;`）；不支持 `params` 参数 |
| `stream_socket_server(string $address, int $flags = STREAM_SERVER_BIND \| STREAM_SERVER_LISTEN, array $context = []): int\|Exception` | `stream_socket_server(string $address, int $errno, int $errstr, int $timeout, int $flags, $context): resource\|false` | 无 byRef errno/errstr；失败抛 Exception |
| `stream_socket_accept(int $server_fd, int $timeout_ms = -1): int\|Exception` | `stream_socket_accept($server, float $timeout, string $peername): resource\|false` | timeout 单位为毫秒（PHP 为秒）；timeout_ms>=0 时用 select 等待可读，超时或无连接返回 `-1`（不抛异常，供事件循环轮询），accept() 真正失败抛 Exception |
| `stream_socket_client(string $address, int $timeout_ms = -1, int $flags = STREAM_CLIENT_CONNECT, array $context = []): int\|Exception` | `stream_socket_client(string $address, int $errno, int $errstr, ..., int $flags, $context): resource\|false` | 无 byRef errno/errstr |
| `stream_socket_recvfrom(int $fd, int $length, int $flags = 0): string\|Exception` | `stream_socket_recvfrom($socket, int $length, int $flags, string &$address): string\|false` | 不返回对端地址（用 `stream_socket_get_name` 替代）；非阻塞模式下 EAGAIN/EWOULDBLOCK/EINTR 返回空字符串（不抛异常，供事件循环轮询），真错误抛 Exception |
| `stream_socket_sendto(int $fd, string $data, int $flags = 0, string $address = ""): int\|Exception` | `stream_socket_sendto($socket, string $data, int $flags, string $address): int\|false` | 非阻塞模式下 EAGAIN/EWOULDBLOCK/EINTR 返回 `-1`（不抛异常，供事件循环轮询），真错误抛 Exception |
| `stream_socket_get_name(int $fd, bool $want_peer): string\|Exception` | `stream_socket_get_name($socket, bool $want_peer): string\|false` | 失败抛 Exception |
| `stream_socket_shutdown(int $fd, int $how): bool\|Exception` | `stream_socket_shutdown($stream, int $how): bool` | 失败抛 Exception |
| `stream_socket_enable_crypto(int $fd, bool $enable, int $crypto_type = 0): int\|Exception` | `stream_socket_enable_crypto($stream, bool $enable, int $crypto_type): int\|bool\|false` | 需 `ext/openssl`；未加载抛 Exception 并返回 `-1` |
| `stream_socket_pair(int $domain, int $type, int $protocol): array\|Exception` | `stream_socket_pair(int $domain, int $type, int $protocol): array\|false` | POSIX `socketpair()`；Windows 用 TCP 回环模拟 |

### 示例

```php
// TCP echo 服务端 + 客户端
$server = stream_socket_server("tcp://127.0.0.1:19999");
$client = stream_socket_client("tcp://127.0.0.1:19999");
$accepted = stream_socket_accept($server, 1000);
stream_socket_sendto($client, "hello", 0, "");
echo stream_socket_recvfrom($accepted, 100, 0);  // "hello"

// 流元数据
$meta = stream_get_meta_data($client);
echo $meta["stream_type"];  // "tcp_socket"
echo $meta["blocked"];      // true

// socket pair（进程间通信）
$pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
stream_socket_sendto($pair[0], "ping", 0, "");
echo stream_socket_recvfrom($pair[1], 100, 0);  // "ping"

stream_close($server);
```

> 测试: `test/stream/stream_basic.php`（18 节覆盖全部 21 个函数）全部通过。

---

## openssl — TLS/SSL 加密 ✅ 已完成

> 文件: `ext/openssl/src/openssl.h`。按需引入 `#import openssl`。
>
> **实现状态**: 已完成。底层使用内置 mbedTLS 3.6.6 源码静态编译（`include/mbedtls_src/`），
> 保持 OpenSSL 函数名和语义，避免 OpenSSL TCC 链接不兼容问题。零运行时依赖。
>
> SSL*/SSL_CTX* 指针以 `t_int` 流转（phpc_ptr_to_int / phpc_int_to_ptr）。
> AOT 单返回类型: 所有失败统一 `tp_throw_ex`（可 try-catch，不返回 `false`）。
> 包含顺序: openssl.h 必须在 stream.h 之前（`TPHP_STREAM_TLS_IMPLEMENTED` 守卫）。
>
> 测试: `test/openssl/openssl_basic.php`（21 节覆盖 20 个函数，仅 `ssl_accept` 需服务端）。
> 标记 `@skip` — CI 默认跳过（mbedTLS 编译较慢，约 30s+），本地可手动运行：
> `php tphp.php test/openssl/openssl_basic.php --debug`

### 常量

#### SSL 选项

| 常量 | 值 | 说明 |
|------|---|------|
| `SSL_OP_NO_COMPRESSION` | 0x00020000 | 禁用压缩 |
| `SSL_OP_NO_SSLv2` | 0x01000000 | 禁用 SSLv2 |
| `SSL_OP_NO_SSLv3` | 0x02000000 | 禁用 SSLv3 |
| `SSL_OP_NO_TLSv1` | 0x04000000 | 禁用 TLS 1.0 |
| `SSL_OP_NO_TLSv1_1` | 0x10000000 | 禁用 TLS 1.1 |
| `SSL_OP_NO_TLSv1_2` | 0x08000000 | 禁用 TLS 1.2 |
| `SSL_OP_NO_TLSv1_3` | 0x20000000 | 禁用 TLS 1.3 |
| `SSL_OP_NO_RENEGOTIATION` | 0x40000000 | 禁用重协商 |

#### 验证模式

| 常量 | 值 | 说明 |
|------|---|------|
| `SSL_VERIFY_NONE` | 0 | 不验证 |
| `SSL_VERIFY_PEER` | 1 | 验证对端证书 |
| `SSL_VERIFY_FAIL_IF_NO_PEER_CERT` | 2 | 无证书则失败 |

#### 文件类型 / 密钥类型 / 算法

| 常量 | 值 | 说明 |
|------|---|------|
| `SSL_FILETYPE_PEM` | 1 | PEM 格式 |
| `SSL_FILETYPE_ASN1` | 2 | ASN1 格式 |
| `OPENSSL_KEYTYPE_RSA` | 0 | RSA 密钥 |
| `OPENSSL_KEYTYPE_EC` | 3 | EC 密钥 |
| `OPENSSL_ALGO_SHA256` | 7 | SHA-256 签名算法 |
| `OPENSSL_ALGO_SHA512` | 9 | SHA-512 签名算法 |

#### 加密选项

| 常量 | 值 | 说明 |
|------|---|------|
| `OPENSSL_RAW_DATA` | 1 | 返回原始二进制 |
| `OPENSSL_ZERO_PADDING` | 2 | 不使用 PKCS#7 填充 |
| `OPENSSL_DONT_ZERO_PAD_KEY` | 4 | 不填充密钥 |

### 函数

#### SSL Context API

| 函数 | 说明 |
|------|------|
| `openssl_ctx_new(int $method): int\|Exception` | 创建 SSL/TLS 上下文，返回 ctx 指针值 |
| `openssl_ctx_free(int $ctx): void` | 释放上下文 |
| `openssl_ctx_use_certificate_file(int $ctx, string $file, int $type): bool\|Exception` | 加载证书文件 |
| `openssl_ctx_use_private_key_file(int $ctx, string $file, int $type): bool\|Exception` | 加载私钥文件 |
| `openssl_ctx_set_verify(int $ctx, int $mode): void` | 设置验证模式 |
| `openssl_ctx_set_options(int $ctx, int $options): int` | 设置选项（位掩码） |

#### SSL Connection API

| 函数 | 说明 |
|------|------|
| `openssl_ssl_new(int $ctx): int\|Exception` | 从上下文创建 SSL 对象 |
| `openssl_ssl_free(int $ssl): void` | 释放 SSL 对象 |
| `openssl_ssl_set_fd(int $ssl, int $fd): bool\|Exception` | 关联 socket fd |
| `openssl_ssl_connect(int $ssl): int\|Exception` | 客户端 TLS 握手 |
| `openssl_ssl_accept(int $ssl): int\|Exception` | 服务端 TLS 握手 |
| `openssl_ssl_read(int $ssl, int $length): string\|Exception` | 读取解密数据 |
| `openssl_ssl_write(int $ssl, string $data): int\|Exception` | 写入数据（加密发送） |
| `openssl_ssl_shutdown(int $ssl): bool` | 优雅关闭 TLS |
| `openssl_ssl_get_cipher_name(int $ssl): string` | 获取加密套件名 |
| `openssl_ssl_get_version(int $ssl): string` | 获取 TLS 协议版本 |

#### Error / 加密 / 随机 / 哈希 API

| 函数 | 说明 |
|------|------|
| `openssl_error_string(): string` | 获取并清空错误队列 |
| `openssl_encrypt(string $cipher, string $key, string $iv, string $data, int $options = 0): string\|Exception` | 对称加密（AES-256-CBC 等） |
| `openssl_decrypt(string $cipher, string $key, string $iv, string $data, int $options = 0): string\|Exception` | 对称解密 |
| `openssl_random_pseudo_bytes(int $length): string\|Exception` | 生成 CSPRNG 随机字节 |
| `openssl_digest(string $method, string $data, bool $raw_output = false): string\|Exception` | 计算哈希（sha256/md5/sha512 等） |

### 示例

```php
// AES-256-CBC 加解密往返
$key = str_repeat("k", 32);   // 32 字节密钥
$iv  = str_repeat("v", 16);   // 16 字节 IV
$encrypted = openssl_encrypt("AES-256-CBC", $key, $iv, "hello world");
$decrypted = openssl_decrypt("AES-256-CBC", $key, $iv, $encrypted);
echo $decrypted;  // "hello world"

// 哈希
echo openssl_digest("sha256", "hello");  // 2cf24dba5fb0a30e...
```

> 测试: `test/ext/openssl_basic.php`（random_pseudo_bytes + digest + encrypt/decrypt 往返 + error_string）。
> 注意: 测试标记为 `@skip`（需预编译 OpenSSL 静态库），CI 环境运行。

---

## pdo — 数据库（PDO 统一 API）

> 文件: `ext/pdo/pdo.h` + `ext/pdo/src/pdo.php`。按需引入 `#import pdo`。
> 同时使用 MySQL 时追加 `#import pdo_mysql`（自动按 DSN 前缀分发到对应驱动）。
>
> **已实现驱动**：
> - **SQLite 驱动**（默认）：SQLite amalgamation 3.46.0 静态编译（`include/os/sqlite_src/sqlite3.c`），零运行时依赖。
> - **MySQL 驱动**：纯 C 协议实现（`ext/pdo_mysql/pdo_mysql.h`，约 1644 行），零外部依赖，
>   认证 `mysql_native_password`（SHA1），协议为文本协议（COM_QUERY），不支持 SSL/TLS/Unix socket。
>   详见 [EXT_IMPLEMENTATION.md §13](EXT_IMPLEMENTATION.md#13-mysql-✅-已完成)。
>
> **Driver 抽象架构**：`ext/pdo/pdo_driver.h` 定义 `pdo_driver_t` 函数指针表接口，
> 每个驱动实现该接口并通过 C constructor 自动注册。PDO/PDOStatement 类所有 C 调用
> 通过 `pdo_driver_*` 包装函数分发，PHP 用户层 API 完全不变。
>
> **AOT 类型安全**：所有方法参数/返回值使用 tphp 具体类型（int/string/array/bool），
> 不使用 `mixed`/`t_var`（会触发运行时类型分发，违反 AOT 编译期类型解析原则）。
> **指针 ↔ int 桥接**：`sqlite3*`/`mysql_conn_t*`/`sqlite3_stmt*`/`mysql_stmt_t*` 等指针
> 以 `t_int` 存储在 PHP 类字段中，方法内部用 `phpc_int_to_ptr` 转回 `C.void*` 调用 driver C API。
> **错误处理**：所有错误抛 `Exception`（`tp_throw_ex`），可被 `try-catch` 捕获，不静默返回 false。
> **多态拆分**：PHP 原生使用 `mixed` 的方法按类型拆分为多个具体签名
> （`bindValueInt`/`bindValueStr`/`bindValueNamedInt`/`bindValueNamedStr`，
> `getAttributeStr`/`getAttributeInt`/`getAttributeBool`，
> `fetchColumnStr`/`fetchColumnInt`）。
> **fetch 语义**：`fetch()` 始终返回 `array`（取完返回 `[]`，用 `fetchDone()` 检测是否取完），
> 所有列值统一转为 string（int/float/null 内部转换）。
> **MySQL 文本协议适配**：MySQL 文本协议所有列值都是字符串，
> `fetchColumnInt` 内部用 `strtoll` 将 TEXT 列转换为整数（适用于 `COUNT(*)` 等）。
> `|Exception` 返回类型为纯语法提示，C 代码只生成 `|` 前的类型。

### 常量

| 常量 | 值 | 说明 |
|------|-----|------|
| `PDO::PARAM_NULL` | 0 | NULL 参数 |
| `PDO::PARAM_INT` | 1 | 整数参数 |
| `PDO::PARAM_STR` | 2 | 字符串参数（默认） |
| `PDO::PARAM_LOB` | 3 | 大对象参数 |
| `PDO::PARAM_BOOL` | 5 | 布尔参数 |
| `PDO::FETCH_DEFAULT` | 0 | 使用默认模式 |
| `PDO::FETCH_ASSOC` | 2 | 关联数组 |
| `PDO::FETCH_NUM` | 3 | 索引数组 |
| `PDO::FETCH_BOTH` | 4 | 关联 + 索引（默认） |
| `PDO::FETCH_KEY_PAIR` | 12 | 第一列 key → 第二列 value |
| `PDO::FETCH_ORI_NEXT` | 0 | 游标方向（SQLite 仅支持 NEXT） |
| `PDO::ERR_NONE` | "00000" | 无错误 SQLSTATE |
| `PDO::ERRMODE_SILENT` | 0 | 静默模式（仅兼容性，错误仍抛异常） |
| `PDO::ERRMODE_WARNING` | 1 | 警告模式（仅兼容性，错误仍抛异常） |
| `PDO::ERRMODE_EXCEPTION` | 2 | 异常模式（实际行为） |
| `PDO::CASE_NATURAL` | 0 | 列名原样 |
| `PDO::CASE_LOWER` | 1 | 列名转小写 |
| `PDO::CASE_UPPER` | 2 | 列名转大写 |
| `PDO::CURSOR_FWDONLY` | 0 | 前向游标（SQLite 唯一支持） |
| `PDO::CURSOR_SCROLL` | 1 | 滚动游标（不支持，prepare 时抛异常） |
| `PDO::NULL_NATURAL` | 0 | NULL 原样 |
| `PDO::NULL_EMPTY_STRING` | 1 | 空串转 NULL |
| `PDO::NULL_TO_STRING` | 2 | NULL 转空串 |
| `PDO::ATTR_AUTOCOMMIT` | 0 | 自动提交（SQLite 始终为 true） |
| `PDO::ATTR_TIMEOUT` | 2 | busy timeout（秒） |
| `PDO::ATTR_ERRMODE` | 3 | 错误模式 |
| `PDO::ATTR_SERVER_VERSION` | 4 | 服务端版本（= SQLite 版本） |
| `PDO::ATTR_CLIENT_VERSION` | 5 | 客户端版本（= SQLite 版本） |
| `PDO::ATTR_SERVER_INFO` | 6 | 服务端信息（= SQLite 版本） |
| `PDO::ATTR_CONNECTION_STATUS` | 7 | 连接状态 |
| `PDO::ATTR_CASE` | 8 | 列名大小写 |
| `PDO::ATTR_CURSOR` | 10 | 游标类型 |
| `PDO::ATTR_ORACLE_NULLS` | 11 | Oracle 空值兼容 |
| `PDO::ATTR_DRIVER_NAME` | 16 | 驱动名（"sqlite"） |
| `PDO::ATTR_STRINGIFY_FETCHES` | 17 | 字符串化取值（始终为 true） |
| `PDO::ATTR_DEFAULT_FETCH_MODE` | 19 | 默认 fetch 模式 |
| `PDO::ATTR_OPEN_FLAGS` | 1000 | sqlite3_open_v2 flags（SQLite 特有） |
| `PDO::ATTR_READONLY_STATEMENT` | 1001 | 只读语句（SQLite 特有） |
| `PDO::ATTR_EXTENDED_RESULT_CODES` | 1002 | 扩展结果码（SQLite 特有） |
| `PDO::ATTR_BUSY_STATEMENT` | 1003 | 忙语句（SQLite 特有） |
| `PDO::ATTR_TRANSACTION_MODE` | 1005 | 事务模式（SQLite 特有） |
| `PDO::OPEN_READONLY` | 1 | 只读打开 |
| `PDO::OPEN_READWRITE` | 2 | 读写打开 |
| `PDO::OPEN_CREATE` | 4 | 自动创建 |
| `PDO::TRANSACTION_MODE_DEFERRED` | 0 | DEFERRED 事务（默认） |
| `PDO::TRANSACTION_MODE_IMMEDIATE` | 1 | IMMEDIATE 事务 |
| `PDO::TRANSACTION_MODE_EXCLUSIVE` | 2 | EXCLUSIVE 事务 |

### PDO 类方法（16 个）

| php函数 | tphp函数 | 性能说明 | 差异说明 |
|------|--------|------|------|
| `__construct(string $dsn, string $user = "", string $pass = "", array $options = [])` | 同 | 解析 DSN → sqlite3_open_v2 → 设置 busy_timeout + 扩展结果码 | 仅支持 `sqlite:` 前缀 DSN；`$options` 数组键为 ATTR_\* 常量（int），值为 int |
| `prepare(string $query, array $options = []): PDOStatement` | `prepare(string $query, array $options = []): PDOStatement\|Exception` | sqlite3_prepare_v2 + 包装为 PDOStatement | `$options[ATTR_CURSOR]` 仅接受 `CURSOR_FWDONLY`，否则抛异常 |
| `query(string $query, int $fetchMode = 0): PDOStatement` | `query(string $query, int $fetchMode = 0): PDOStatement\|Exception` | prepare + execute + setFetchMode | 仅 2 参（PHP 原生有 4 参：`$mode`/`$arg`/`$ctor_args`，后两者依赖反射，已砍掉） |
| `exec(string $statement): int` | `exec(string $statement): int\|Exception` | sqlite3_exec + sqlite3_changes | 返回受影响行数；失败抛异常（PHP 返回 `false`） |
| `quote(string $string, int $type = PARAM_STR): string` | `quote(string $string, int $type = 2): string\|Exception` | sqlite3_mprintf `%Q` 格式 | `$type` 参数仅兼容性，统一按字符串转义 |
| `lastInsertId(string $name = ""): string` | `lastInsertId(string $name = ""): string\|Exception` | sqlite3_last_insert_rowid | `$name` 参数仅兼容性（SQLite 无 sequence 概念）；返回值 strval 转字符串（避免大整数溢出） |
| `beginTransaction(): bool` | 同 | sqlite3_exec "BEGIN [IMMEDIATE\|EXCLUSIVE]" | 受 `ATTR_TRANSACTION_MODE` 控制；已在事务中抛异常 |
| `commit(): bool` | 同 | sqlite3_exec "COMMIT" | 不在事务中抛异常 |
| `rollBack(): bool` | 同 | sqlite3_exec "ROLLBACK" | 不在事务中抛异常 |
| `inTransaction(): bool` | 同 | 读取 `inTransaction` 字段 | — |
| `errorCode(): string` | 同 | sqlite3_errcode → SQLSTATE 映射 | 返回 5 字符 SQLSTATE（如 "00000"/"HY000"/"23000"） |
| `errorInfo(): array` | 同 | [SQLSTATE, native_code, message] | 返回 `array<string>`（native_code 也 strval 转字符串） |
| `getAttribute(int $attribute): mixed` | `getAttributeStr(int $attribute): string` | ATTR_DRIVER_NAME / ATTR_SERVER_VERSION / ATTR_CLIENT_VERSION / ATTR_SERVER_INFO | 按返回类型拆分为 3 个方法；不支持的属性返回空字符串 |
| ↑ | `getAttributeInt(int $attribute): int` | ATTR_ERRMODE / ATTR_CASE / ATTR_ORACLE_NULLS / ATTR_DEFAULT_FETCH_MODE / ATTR_TIMEOUT | 不支持的属性返回 0 |
| ↑ | `getAttributeBool(int $attribute): bool` | ATTR_AUTOCOMMIT（SQLite 始终为 true） | 不支持的属性返回 false |
| `setAttribute(int $attribute, mixed $value): bool` | `setAttribute(int $attribute, int $value): bool` | 写入字段或调用 sqlite3_busy_timeout | `$value` 统一为 int（所有可设置属性均为整数类型）；不支持的属性静默忽略并返回 true |
| `getAvailableDrivers(): array` | 同（静态方法） | 返回 `["sqlite"]` | 编译期固定列表，无运行时查表 |

### PDOStatement 类方法（17 个）

| php函数 | tphp函数 | 性能说明 | 差异说明 |
|------|--------|------|------|
| `bindValue(int\|string $param, mixed $value, int $type = PARAM_STR): bool` | `bindValueInt(int $param, int $value, int $type = 2): bool` | sqlite3_bind_int64/bind_null/bind_text | 按位置绑定（1-based）；`$type` 控制 NULL/INT/BOOL/STR 绑定方式 |
| ↑ | `bindValueStr(int $param, string $value, int $type = 2): bool` | sqlite3_bind_text | 按位置绑定字符串 |
| ↑ | `bindValueNamedInt(string $param, int $value, int $type = 2): bool` | sqlite3_bind_parameter_index + bind_* | 按命名参数（":name"）绑定 |
| ↑ | `bindValueNamedStr(string $param, string $value, int $type = 2): bool` | 同上 | 按命名参数绑定字符串 |
| `bindParam(...)` | — | — | 不支持引用语义，回退到 `bindValue*`（值拷贝） |
| `execute(array $params = null): bool` | `execute(array $params = []): bool` | sqlite3_reset + clear_bindings + bind_params + step | 默认值 `[]`（非 `null`，PHP 8.4+ 废弃隐式 nullable）；首步预取一行；参数数组由 C helper `pdo_bind_params` 内部处理 t_var 类型分发 |
| `fetch(int $mode = FETCH_BOTH, ...): mixed\|false` | `fetch(int $mode = 0): array` | sqlite3_step + column_* | 始终返回 `array<string>`（列值统一 strval）；取完返回 `[]`；不支持 `FETCH_OBJ`/`FETCH_CLASS`/`FETCH_BOUND` |
| `fetchAll(int $mode = FETCH_BOTH): array` | `fetchAll(int $mode = 0): array` | 循环 fetch | 返回 `array<array<string>>`（FETCH_KEY_PAIR 模式返回 `array<string>` 单层） |
| `fetchColumn(int $col = 0): mixed\|false` | `fetchColumnStr(int $col = 0): string` | fetch FETCH_NUM + 索引取值 | 按返回类型拆分为 2 个方法；取完返回空字符串 |
| ↑ | `fetchColumnInt(int $col = 0): int` | 同上 + _fetchColumnInt | 适用于 INTEGER 列（如 COUNT(*))）；取完返回 0 |
| — | `fetchDone(): bool` | 读取 `done` 字段 | 新增方法，替代 PHP `fetch() === false` 的判断 |
| `closeCursor(): bool` | 同 | sqlite3_reset + 重置内部状态 | 幂等；允许再次 execute |
| `columnCount(): int` | 同 | sqlite3_column_count | — |
| `rowCount(): int` | 同 | 缓存的 sqlite3_changes 值 | 仅对 INSERT/UPDATE/DELETE 有效（SQLite 限制） |
| `setFetchMode(int $mode, ...): bool` | `setFetchMode(int $mode, int $arg = 0): bool` | 写入 fetchMode/fetchCol 字段 | `$arg` 仅 FETCH_COLUMN 模式用（列号，int）；其他模式的额外参数已砍掉 |
| `errorCode(): string` | 同 | sqlite3_errcode(db) → SQLSTATE | 通过 `db` 字段查询（语句所属连接） |
| `errorInfo(): array` | 同 | [SQLSTATE, native_code, message] | 同 PDO::errorInfo |
| `getColumnMeta(int $col): array` | 同 | sqlite3_column_name/decltype/type | 返回 `["native_type"=>string, "pdo_type"=>string, "name"=>string]`（pdo_type 已 strval） |

### 公开属性

| 类 | 属性 | 类型 | 说明 |
|----|------|------|------|
| `PDOStatement` | `$queryString` | `string` | SQL 查询字符串（PHP 原生公开属性） |
| `PDOStatement` | `$fetchMode` | `int` | 默认 fetch 模式（FETCH_BOTH=4） |
| `PDOStatement` | `$fetchCol` | `int` | FETCH_COLUMN 模式的列号 |
| `PDOStatement` | `$rowCount` | `int` | 受影响行数 |
| `PDOStatement` | `$executed` | `bool` | 是否已 execute |
| `PDOStatement` | `$done` | `bool` | 是否取完 |
| `PDOStatement` | `$columnCount` | `int` | 列数 |
| `PDO` | `$errMode` | `int` | 错误模式（实际始终为 EXCEPTION） |
| `PDO` | `$caseMode` | `int` | 列名大小写模式 |
| `PDO` | `$nullMode` | `int` | 空值处理模式 |
| `PDO` | `$defaultFetchMode` | `int` | 默认 fetch 模式（FETCH_BOTH=4） |
| `PDO` | `$inTransaction` | `bool` | 是否在事务中 |
| `PDO` | `$txnMode` | `int` | 事务模式（DEFERRED/IMMEDIATE/EXCLUSIVE） |
| `PDO` | `$openFlags` | `int` | sqlite3_open_v2 flags（默认 READWRITE\|CREATE=6） |

> `PDO::$db` 和 `PDOStatement::$stmt`/`$db` 为指针的 int 形式存储，技术上公开但不建议用户直接访问。

### 设计模式

```php
// 公开 API 纯 tphp 类型签名，指针以 int 在 PHP 层流转
class PDO {
    public int $db = 0;  // sqlite3* 指针的 int 形式
    public function __construct(string $dsn, string $username = "",
                                string $password = "", array $options = []) {
        // C 包装函数 pdo_open_db 内部完成 t_int ↔ sqlite3* 转换
        $dsnC = c_str($dsn);
        $this->db = pdo_open_db($dsnC, c_int($this->openFlags));
        if ($this->db == 0) { return; }  // 已抛异常
        // 方法内部用 C.void* 局部变量承接 phpc_int_to_ptr 返回值
        C.void* $dbh = phpc_int_to_ptr($this->db);
        C->sqlite3_busy_timeout($dbh, c_int(60000));
    }
    public function __destruct() {
        if ($this->db != 0) {
            C.void* $dbh = phpc_int_to_ptr($this->db);
            C->sqlite3_close_v2($dbh);
            $this->db = 0;
        }
    }
}

// 多态方法按类型拆分（避免 mixed/t_var）
class PDOStatement {
    public function fetch(int $mode = 0): array {
        // 始终返回 array<string>（列值统一 strval）
        // 取完返回 []（用 fetchDone() 检测）
    }
    public function fetchColumnStr(int $col = 0): string { ... }
    public function fetchColumnInt(int $col = 0): int { ... }
    public function fetchDone(): bool { return $this->done; }
}
```

### 典型用法

```php
#import pdo

// SQLite 内存库 + 预处理
$pdo = new PDO("sqlite::memory:");
$pdo->exec("CREATE TABLE t (id INTEGER PRIMARY KEY, name TEXT, age INTEGER)");
$pdo->exec("INSERT INTO t VALUES (1, 'Alice', 30)");

// 命名参数绑定
$stmt = $pdo->prepare("SELECT * FROM t WHERE id = :id");
$stmt->bindValueNamedInt(":id", 1);
$stmt->execute();
$row = $stmt->fetch(PDO::FETCH_ASSOC);
echo $row["name"];  // "Alice"

// fetchAll + FETCH_KEY_PAIR
$pdo->exec("INSERT INTO t VALUES (2, 'Bob', 25)");
$pairs = $pdo->query("SELECT name, age FROM t")->fetchAll(PDO::FETCH_KEY_PAIR);
echo $pairs["Alice"];  // "30"

// 事务
$pdo->beginTransaction();
$pdo->exec("UPDATE t SET age = 31 WHERE id = 1");
$pdo->commit();

// 错误处理（可 try-catch）
try {
    $pdo->exec("SELECT * FROM nonexistent");
} catch (Exception $e) {
    echo $e->getMessage();  // "PDO::exec: no such table: nonexistent"
}

// fetchColumnInt 适用于 COUNT(*) 等
$count = $pdo->query("SELECT COUNT(*) FROM t")->fetchColumnInt(0);
echo $count;  // 2
```

```php
// MySQL 驱动示例（纯 C 协议实现，零外部依赖）
#import pdo
#import pdo_mysql

$pdo = new PDO("mysql:host=127.0.0.1;port=3306;dbname=test", "root", "secret");

// 建表
$pdo->exec("CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    age INT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// 预处理 + 位置绑定（MySQL 文本协议模拟预处理）
$stmt = $pdo->prepare("INSERT INTO users (name, age) VALUES (?, ?)");
$stmt->bindValueStr(1, "Alice");
$stmt->bindValueInt(2, 30);
$stmt->execute();
echo $pdo->lastInsertId();  // 自增 ID

// fetchColumnInt 适用于 COUNT(*)（MySQL 文本协议 + strtoll 自动转换）
$count = $pdo->query("SELECT COUNT(*) FROM users", PDO::FETCH_NUM)->fetchColumnInt(0);

// 事务
$pdo->beginTransaction();
$pdo->exec("UPDATE users SET age = 31 WHERE id = 1");
$pdo->commit();

// 错误处理（可 try-catch，错误消息由 MySQL 服务器返回）
try {
    $pdo->exec("SELECT * FROM nonexistent_table");
} catch (Exception $e) {
    echo $e->getMessage();  // "PDO::exec: Table 'test.nonexistent_table' doesn't exist"
}
```

> 测试:
> - `test/pdo/pdo_basic.php`（19 节覆盖连接/exec/prepare/位置绑定/命名绑定/execute(array)/
>   fetch 模式/fetchAll/fetchColumn/事务/lastInsertId/rowCount/quote/getAttribute/setAttribute/
>   errorCode/getColumnMeta/closeCursor 复用/错误处理/静态方法/NULL/float 列）全部通过。
> - `test/pdo_mysql/pdo_mysql_integration.php`（11 节覆盖连接/建库建表/插入/
>   FETCH_ASSOC 查询/位置绑定 Int+Str/COUNT(*) fetchColumnInt/事务/错误处理/quote/清理）
>   全部通过（MySQL 8.0.12）。

---

## sqlite3 — SQLite 数据库（函数式 API）

> 文件: `ext/sqlite3/sqlite3.h` + `ext/sqlite3/src/sqlite3.php`。按需引入 `#import sqlite3`。
>
> **函数式 API**（不使用 OO，避免类型多态开销），与 `ext/pdo` 共享同一份 SQLite amalgamation 3.46.0 静态编译
> （`include/os/sqlite_src/sqlite3.c`），零运行时依赖。
> **AOT 类型安全**：所有函数参数/返回值使用 tphp 具体类型（int/string/array/bool）。
> **指针 ↔ int 桥接**：`sqlite3*` 指针以 `int` 存储在 PHP 变量中，
> C 包装函数内部用 `(sqlite3*)(intptr_t)` 转换。
> **错误处理**：I/O 错误抛 `Exception`（`tp_throw_ex`），可被 `try-catch` 捕获，不静默返回 false。
> **查询结果**：`sqlite_query` 返回 `array<array<string>>`（外层是行，内层是列值字符串），
> int/float/null/blob 统一转为 string。
> **NULL 语义**：NULL 值返回空字符串 `""`（AOT 无 `mixed`，无法区分 NULL 和空串）。

### 常量

| 常量 | 值 | 说明 |
|------|-----|------|
| `SQLITE3_ASSOC` | 1 | `sqlite_query` 返回关联数组 |
| `SQLITE3_NUM` | 2 | `sqlite_query` 返回索引数组 |
| `SQLITE3_BOTH` | 3 | `sqlite_query` 返回索引+关联（默认） |
| `SQLITE3_INTEGER` | 1 | 列类型：整数 |
| `SQLITE3_FLOAT` | 2 | 列类型：浮点 |
| `SQLITE3_TEXT` | 3 | 列类型：文本 |
| `SQLITE3_BLOB` | 4 | 列类型：BLOB |
| `SQLITE3_NULL` | 5 | 列类型：NULL |
| `SQLITE3_OPEN_READONLY` | 1 | 只读打开 |
| `SQLITE3_OPEN_READWRITE` | 2 | 读写打开 |
| `SQLITE3_OPEN_CREATE` | 4 | 不存在则创建（默认组合：6 = READWRITE\|CREATE） |

### 函数

#### `sqlite_open`

```php
function sqlite_open(string $filename, int $flags = 6, string $enc_key = ""): int
```

打开/创建 SQLite 数据库。`$filename = ":memory:"` 创建内存数据库。
失败抛 `Exception`，成功返回数据库句柄（`sqlite3*` 指针的 int 形式）。

#### `sqlite_close`

```php
function sqlite_close(int $db): void
```

关闭数据库连接（调用 `sqlite3_close_v2`）。

#### `sqlite_exec`

```php
function sqlite_exec(int $db, string $sql): bool
```

执行不返回结果的 SQL（CREATE/INSERT/UPDATE/DELETE 等）。
失败抛 `Exception`，成功返回 `true`。

#### `sqlite_query`

```php
function sqlite_query(int $db, string $sql, int $mode = 3): array
```

执行 SELECT 查询，返回所有行。
`$mode`：`SQLITE3_ASSOC`(1) / `SQLITE3_NUM`(2) / `SQLITE3_BOTH`(3, 默认)。
返回 `array<array<string>>`，外层是行，内层是列值（统一 string）。
失败抛 `Exception`。

#### `sqlite_query_single`

```php
function sqlite_query_single(int $db, string $sql, int $mode = 3): array
```

执行 SELECT 查询，只返回第一行。
无结果返回空数组 `[]`（不抛异常），适合 `COUNT(*)`/`LIMIT 1`。
失败抛 `Exception`。

#### `sqlite_escape_string`

```php
function sqlite_escape_string(string $str): string
```

转义 SQL 字符串（单引号翻倍为 `''`，使用 `sqlite3_mprintf %q`）。
返回不带引号的转义字符串（如 `O'Brien` → `O''Brien`）。

#### `sqlite_changes`

```php
function sqlite_changes(int $db): int
```

返回最近一次 INSERT/UPDATE/DELETE 影响的行数。

#### `sqlite_last_insert_rowid`

```php
function sqlite_last_insert_rowid(int $db): int
```

返回最近一次 INSERT 的 rowid。

#### `sqlite_last_error_msg`

```php
function sqlite_last_error_msg(int $db): string
```

返回最近一次错误的消息（调用 `sqlite3_errmsg`，如 `"not an error"` 表示无错误）。

#### `sqlite_last_error_code`

```php
function sqlite_last_error_code(int $db): int
```

返回最近一次错误的码（`SQLITE_OK=0` 表示无错误）。

#### `sqlite_version`

```php
function sqlite_version(): string
```

返回 SQLite 库版本字符串（如 `"3.46.0"`）。

> 测试: `test/sqlite3/sqlite3_basic.php`（13 节覆盖 open/exec/query(ASSOC/NUM/BOTH)/query_single/
> changes/last_insert_rowid/error_info/escape_string/NULL/float/BLOB/错误处理/close 重开）全部通过。

---

## gd — 图像处理 ✅ 已完成

> 文件: `ext/gd/src/gd.php` + `gd_constants.php` + `gd_fonts.php` + `gd_codec_png.php`，按需引入 `#import gd`
>
> **纯 phpc 实现**，无自定义 C 代码，不依赖 libgd / libpng / libjpeg / libfreetype。
> 仅复用 TinyPHP 已内置的 zlib 1.3.2 源码（用于 PNG 编解码）。
> **支持格式**: PNG / GIF / BMP / GD / GD2 / WBMP / XBM / TGA（8 种完整编解码）
> **不支持**: JPEG / WebP / AVIF / XPM / FreeType 字体渲染（调用时抛 `RuntimeException`，不静默返回 false）
> **数据模型**: `GdImage` 对象维护像素数组（`array<int>`），真彩色每元素 `0x7FRRGGBB`，调色板每元素为索引

### 类

```php
final class GdImage {
    public int $width, $height;
    public bool $trueColor;
    public array $pixels;      // 真彩色: 0x7FRRGGBB, 调色板: 索引
    public array $palette;     // 调色板颜色表
    public bool $alphaBlending, $saveAlpha, $interlace;
    public array $clip, $style;
    public int $thickness, $transparentColor, $resolutionX, $resolutionY, $interpolationMethod;
    public mixed $brush = null, $tile = null;
}

final class GdFont {
    public int $width, $height;
    public array $glyphs;      // 字符位图数组
}
```

### 常量（89 个）

| 类别 | 常量示例 | 说明 |
|------|---------|------|
| 图像类型 | `IMG_GIF` `IMG_PNG` `IMG_BMP` `IMG_WBMP` `IMG_XPM` `IMG_WEBP` `IMG_AVIF` `IMG_TGA` | `imagetypes()` 位掩码 |
| 特殊颜色 | `IMG_COLOR_TILED` `IMG_COLOR_STYLED` `IMG_COLOR_BRUSHED` `IMG_COLOR_STYLEDBRUSHED` `IMG_COLOR_TRANSPARENT` | 绘图特殊颜色 |
| 弧形 | `IMG_ARC_ROUNDED` `IMG_ARC_PIE` `IMG_ARC_CHORD` `IMG_ARC_NOFILL` `IMG_ARC_EDGED` | `imagefilledarc` 样式 |
| GD2 格式 | `IMG_GD2_RAW` `IMG_GD2_COMPRESSED` | GD2 编码模式 |
| 翻转 | `IMG_FLIP_HORIZONTAL` `IMG_FLIP_VERTICAL` `IMG_FLIP_BOTH` | `imageflip` 模式 |
| 图层效果 | `IMG_EFFECT_REPLACE` `IMG_EFFECT_ALPHABLEND` `IMG_EFFECT_NORMAL` `IMG_EFFECT_OVERLAY` `IMG_EFFECT_MULTIPLY` | `imagelayereffect` |
| 裁剪 | `IMG_CROP_DEFAULT` `IMG_CROP_TRANSPARENT` `IMG_CROP_BLACK` `IMG_CROP_WHITE` `IMG_CROP_SIDES` `IMG_CROP_THRESHOLD` | `imagecropauto` 模式 |
| 插值方法 | `IMG_BELL` `IMG_BILINEAR_FIXED` `IMG_BICUBIC` `IMG_BICUBIC_FIXED` `IMG_BOX` `IMG_BSPLINE` `IMG_CATMULLROM` `IMG_GAUSSIAN` `IMG_HERMITE` `IMG_HAMMING` `IMG_HANNING` `IMG_MITCHELL` `IMG_NEAREST_NEIGHBOUR` `IMG_TRIANGLE` 等 | `imagesetinterpolation` |
| 仿射 | `IMG_AFFINE_TRANSLATE` `IMG_AFFINE_SCALE` `IMG_AFFINE_ROTATE` `IMG_AFFINE_SHEAR_HORIZONTAL` `IMG_AFFINE_SHEAR_VERTICAL` | `imageaffinematrixget` 类型 |
| 滤镜 | `IMG_FILTER_NEGATE` `IMG_FILTER_GRAYSCALE` `IMG_FILTER_BRIGHTNESS` `IMG_FILTER_CONTRAST` `IMG_FILTER_COLORIZE` `IMG_FILTER_EDGEDETECT` `IMG_FILTER_GAUSSIAN_BLUR` `IMG_FILTER_SELECTIVE_BLUR` `IMG_FILTER_EMBOSS` `IMG_FILTER_MEAN_REMOVAL` `IMG_FILTER_SMOOTH` `IMG_FILTER_PIXELATE` `IMG_FILTER_SCATTER` | `imagefilter` 类型 |
| PNG 过滤 | `PNG_NO_FILTER` `PNG_FILTER_NONE` `PNG_FILTER_SUB` `PNG_FILTER_UP` `PNG_FILTER_AVG` `PNG_FILTER_PAETH` `PNG_ALL_FILTERS` | `imagepng` filters |
| 版本 | `GD_VERSION` `GD_MAJOR_VERSION` `GD_MINOR_VERSION` `GD_RELEASE_VERSION` `GD_EXTRA_VERSION` `GD_BUNDLED` | GD 版本信息 |

### 函数分组

| 分组 | 函数数 | 函数列表 |
|------|--------|---------|
| 创建/销毁/信息 | 9 | `imagecreate` `imagecreatetruecolor` `imagedestroy` `imagecreatefromstring` `imagesx` `imagesy` `imageistruecolor` `gd_info` `imagetypes` |
| 颜色管理 | 17 | `imagecolorallocate` `imagecolorallocatealpha` `imagecolorat` `imagecolorsforindex` `imagecolorclosest` `imagecolorclosestalpha` `imagecolorclosesthwb` `imagecolorexact` `imagecolorexactalpha` `imagecolorresolve` `imagecolorresolvealpha` `imagecolordeallocate` `imagecolorset` `imagecolorstotal` `imagecolortransparent` `imagepalettecopy` `imagecolormatch` |
| 绘图 | 15 | `imagesetpixel` `imageline` `imagedashedline` `imagerectangle` `imagefilledrectangle` `imagesetthickness` `imagearc` `imageellipse` `imagefilledellipse` `imagefilledarc` `imagefill` `imagefilltoborder` `imagepolygon` `imageopenpolygon` `imagefilledpolygon` |
| 字体与文字 | 7 | `imagefontwidth` `imagefontheight` `imagechar` `imagecharup` `imagestring` `imagestringup` `imageloadfont` |
| 复制与缩放 | 5 | `imagecopy` `imagecopymerge` `imagecopymergegray` `imagecopyresized` `imagecopyresampled` |
| 变换 | 8 | `imageflip` `imagerotate` `imagecrop` `imagecropauto` `imagescale` `imageaffine` `imageaffinematrixget` `imageaffinematrixconcat` |
| 滤镜与卷积 | 4 | `imagefilter`（13 种滤镜）`imageconvolution` `imagegammacorrect` `imageantialias` |
| 状态与属性 | 14 | `imagetruecolortopalette` `imagepalettetotruecolor` `imagealphablending` `imagesavealpha` `imagelayereffect` `imagesetstyle` `imagesetbrush` `imagesettile` `imagesetclip` `imagegetclip` `imagegetinterpolation` `imagesetinterpolation` `imageresolution` `imageinterlace` |
| 编解码（支持） | 16 | `imagecreatefrompng` `imagepng` `imagecreatefromgif` `imagegif` `imagecreatefrombmp` `imagebmp` `imagecreatefromgd` `imagegd` `imagecreatefromgd2` `imagecreatefromgd2part` `imagegd2` `imagecreatefromwbmp` `imagewbmp` `imagecreatefromxbm` `imagexbm` `imagecreatefromtga` |
| 编解码（不支持） | 13 | `imagecreatefromjpeg` `imagejpeg` `imagecreatefromwebp` `imagewebp` `imagecreatefromavif` `imageavif` `imagecreatefromxpm` `imagettftext` `imagefttext` `imagettfbbox` `imageftbbox` `imagegrabwindow` `imagegrabscreen` |

### 差异说明

- 所有 `GdImage|false` 返回类型改为 `GdImage|Exception`（失败抛 Exception，不返回 false）
- `gd_info()` / `imagetypes()` 真实反映能力：JPEG/WebP/AVIF/XPM/FreeType Support 为 false
- 不支持的格式调用时抛 `RuntimeException`，消息明确指出格式名称
- `imagefilter` 参数为固定 4 个 `int $arg`（PHP 用 `...$args` 可变参数）
- `imageinterlace` / `imageresolution` 使用 `-1` / `null` 默认值实现 getter/setter 双模式
- `imagepolygon` / `imageopenpolygon` / `imagefilledpolygon` 第三参数为 `int $num_points_or_color`，第四参数为 `int $color = -1`

### 示例

```php
#import gd

// 1. 创建缩略图（PNG 格式）
$src = imagecreatefrompng("photo.png");
$w = imagesx($src); $h = imagesy($src);
$thumb = imagecreatetruecolor(150, 150);
imagecopyresampled($thumb, $src, 0, 0, 0, 0, 150, 150, $w, $h);
imagepng($thumb, "thumb.png", 6);
imagedestroy($src);
imagedestroy($thumb);

// 2. GIF 调色板图像
$img = imagecreate(100, 50);
$bg = imagecolorallocate($img, 255, 255, 255);
$black = imagecolorallocate($img, 0, 0, 0);
imagestring($img, 3, 10, 10, "Hello GIF", $black);
imagegif($img, "hello.gif");
imagedestroy($img);

// 3. 滤镜效果
$img = imagecreatefrompng("photo.png");
imagefilter($img, IMG_FILTER_GRAYSCALE);
imagefilter($img, IMG_FILTER_BRIGHTNESS, 50);
imagepng($img, "grayscale.png");
imagedestroy($img);
```

> 测试: `test/gd/`（17 个测试文件，762 断言），TCC/GCC 16.1.0/Clang 22.1.7 全部通过

---

## pgsql — PostgreSQL 扩展 ✅ 已完成

> 文件: `ext/pgsql/src/pgsql.php` + `pgsql_constants.php`，按需引入 `#import pgsql`
>
> **纯 C 实现 PostgreSQL v3 协议**，不依赖 libpq。复用 ext/stream 的 socket 跨平台抽象。
> **认证**: trust / MD5 / SCRAM-SHA-256（内置 SHA-256/HMAC/PBKDF2 密码学原语）
> **协议**: Extended Query（Parse/Bind/Execute）+ Simple Query
> **不支持**: SSL/TLS、Unix socket（本期）
> **AOT 类型安全**: 所有参数/返回值用 tphp 具体类型（int/string/array/bool），不使用 `mixed`/`t_var`
> **指针 ↔ int 桥接**: `PgSql\Connection` 和 `PgSql\Result` 以 `int` 存储指针（不声明 PHP 层类，AOT 模型下 int 即可承载句柄）
> **错误处理**: 所有错误抛 `Exception`（`tp_throw`），可被 `try-catch` 捕获，不静默返回 false
> **多态拆分**: PHP 原生 `mixed` 返回按类型拆分（如 `pg_insert_result` 返回 int，`pg_insert_sql` 返回 string）
> **持久连接池**: `pg_pconnect` 参考 vlang Pool+IdleSlot 模式实现连接复用
> **Large Object**: `pg_lo_open` 返回 int 句柄，基于 `tphp_class_Resource` 实现资源管理
> **通知回调**: `pg_set_notice_callback` 基于 `t_callback` 实现 callable 透传

### 类

（无 PHP 层类，`PgSql\Connection` 和 `PgSql\Result` 以 `int` 存储指针）

### 常量（约 60 个）

| 类别 | 常量 | 值 | 说明 |
|------|------|-----|------|
| **连接标志** | `PGSQL_CONNECT_FORCE_NEW` | 0x2 | 强制创建新连接 |
| | `PGSQL_CONNECT_ASYNC` | 0x4 | 异步连接（仅定义） |
| | `PGSQL_CONNECT_TIMEOUT` | 0x8 | 连接超时（仅定义） |
| **结果集读取模式** | `PGSQL_ASSOC` | 0x1 | 关联数组 |
| | `PGSQL_NUM` | 0x2 | 数字索引数组 |
| | `PGSQL_BOTH` | 0x3 | 关联 + 索引 |
| **pg_last_oid 返回类型** | `PGSQL_STATUS_LONG` | 0x1 | 返回整数 OID |
| | `PGSQL_STATUS_STRING` | 0x2 | 返回字符串 OID |
| **pg_result_status 返回值** | `PGSQL_EMPTY_QUERY` | 0x0 | 空查询 |
| | `PGSQL_COMMAND_OK` | 0x1 | 命令成功（无结果集） |
| | `PGSQL_TUPLES_OK` | 0x2 | 查询成功（有结果集） |
| | `PGRES_COPY_OUT` | 0x3 | COPY TO 开始 |
| | `PGRES_COPY_IN` | 0x4 | COPY FROM 开始 |
| | `PGSQL_BAD_RESPONSE` | 0x5 | 无法理解的响应 |
| | `PGSQL_NONFATAL_ERROR` | 0x6 | 非致命错误 |
| | `PGSQL_FATAL_ERROR` | 0x7 | 致命错误 |
| **lo_lseek whence** | `PGSQL_SEEK_SET` | 0x0 | 从文件开头 |
| | `PGSQL_SEEK_CUR` | 0x1 | 从当前位置 |
| | `PGSQL_SEEK_END` | 0x2 | 从文件末尾 |
| **DML 操作标志** | `PGSQL_DML_NO_CONV` | 0x0 | 不进行类型转换 |
| | `PGSQL_DML_EXEC` | 0x1 | 执行 SQL |
| | `PGSQL_DML_ASYNC` | 0x2 | 异步执行（仅定义） |
| | `PGSQL_DML_STRING` | 0x3 | 返回 SQL 字符串（不执行） |
| | `PGSQL_DML_ESCAPE` | 0x4 | 使用 pg_escape 转义 |
| **pg_transaction_status 返回值** | `PGSQL_TRANSACTION_IDLE` | 0x0 | 不在事务中 |
| | `PGSQL_TRANSACTION_ACTIVE` | 0x1 | 正在执行查询 |
| | `PGSQL_TRANSACTION_INTRANS` | 0x2 | 在事务块中（空闲） |
| | `PGSQL_TRANSACTION_INERROR` | 0x3 | 在中止的事务块中 |
| | `PGSQL_TRANSACTION_UNKNOWN` | 0x4 | 未知（连接异常） |
| **pg_result_error_field fieldcode** | `PGSQL_DIAG_SEVERITY` | 0x0 | 严重程度 |
| | `PGSQL_DIAG_SQLSTATE` | 0x1 | SQLSTATE 错误码 |
| | `PGSQL_DIAG_MESSAGE` / `PGSQL_DIAG_MESSAGE_PRIMARY` | 0x2 | 主错误消息 |
| | `PGSQL_DIAG_DETAIL` / `PGSQL_DIAG_MESSAGE_DETAIL` | 0x3 | 详细信息 |
| | `PGSQL_DIAG_HINT` / `PGSQL_DIAG_MESSAGE_HINT` | 0x4 | 提示 |
| | `PGSQL_DIAG_POSITION` / `PGSQL_DIAG_STATEMENT_POSITION` | 0x5 | 错误位置 |
| | `PGSQL_DIAG_INTERNAL_POSITION` | 0x6 | 内部错误位置 |
| | `PGSQL_DIAG_INTERNAL_QUERY` | 0x7 | 内部 SQL 文本 |
| | `PGSQL_DIAG_CONTEXT` | 0x8 | 上下文 |
| | `PGSQL_DIAG_SOURCE_FILE` | 0x9 | 源文件名 |
| | `PGSQL_DIAG_SOURCE_LINE` | 0xA | 源行号 |
| | `PGSQL_DIAG_NON_HIGHLIGHTED` / `PGSQL_DIAG_SCHEMA_NAME` | 0xB | 模式名 |
| | `PGSQL_DIAG_TABLE_NAME` | 0xC | 表名 |
| | `PGSQL_DIAG_COLUMN_NAME` | 0xD | 列名 |
| | `PGSQL_DIAG_DATATYPE_NAME` | 0xE | 数据类型名 |
| | `PGSQL_DIAG_CONSTRAINT_NAME` | 0xF | 约束名 |
| | `PGSQL_DIAG_SOURCE_FUNCTION` | 0x12 | 源函数名 |
| **pg_set_error_verbosity** | `PGSQL_ERRORS_TERSE` | 0x1 | 简洁模式 |
| | `PGSQL_ERRORS_DEFAULT` | 0x2 | 默认模式 |
| | `PGSQL_ERRORS_VERBOSE` | 0x3 | 详细模式 |
| **pg_convert options** | `PGSQL_CONV_IGNORE_DEFAULT` | 0x2 | 忽略默认值 |
| | `PGSQL_CONV_FORCE_NULL` | 0x4 | 空字符串转 NULL |
| | `PGSQL_CONV_IGNORE_NOT_NULL` | 0x8 | 忽略 NOT NULL 约束 |
| **pg_close options** | `PGSQL_CLOSE_FORCE` | 0x1 | 强制关闭（不发送 Terminate） |
| | `PGSQL_CLOSE_RESET` | 0x2 | 重置连接状态后关闭 |

### 函数（78 个）

#### 连接管理（6 个）

| 函数 | 签名 | 说明 |
|------|------|------|
| `pg_connect` | `(string $dsn): int` | 连接 PostgreSQL（返回连接句柄） |
| `pg_pconnect` | `(string $dsn, int $flags = 0): int` | 持久连接（Pool+IdleSlot 复用模式） |
| `pg_close` | `(int $conn, int $close_flags = 0): bool` | 关闭连接（`PGSQL_CLOSE_FORCE` 强制关闭持久连接） |
| `pg_connection_status` | `(int $conn): int` | 连接状态 |
| `pg_connection_reset` | `(int $conn): bool` | 重置连接 |
| `pg_ping` | `(int $conn): bool` | Ping 服务器 |

#### 查询（5 个）

| 函数 | 签名 | 说明 |
|------|------|------|
| `pg_query` | `(int $conn, string $sql): int` | 执行 SQL（Simple Query 协议） |
| `pg_query_params` | `(int $conn, string $sql, array $params): int` | 参数化查询（Extended Query，$N 占位符） |
| `pg_prepare` | `(int $conn, string $stmt_name, string $sql): int` | 预处理语句 |
| `pg_execute` | `(int $conn, string $stmt_name, array $params): int` | 执行预处理语句 |
| `pg_free_result` | `(int $result): void` | 释放结果集 |

#### 结果集（25 个）

| 函数 | 签名 | 说明 |
|------|------|------|
| `pg_num_rows` | `(int $result): int` | 行数 |
| `pg_num_fields` | `(int $result): int` | 列数 |
| `pg_affected_rows` | `(int $result): int` | 受影响行数 |
| `pg_last_oid` | `(int $result): int` | 最后插入的 OID |
| `pg_field_name` | `(int $result, int $field_num): string` | 列名 |
| `pg_field_num` | `(int $result, string $field_name): int` | 列号 |
| `pg_field_type` | `(int $result, int $field_num): string` | 列类型名 |
| `pg_field_type_oid` | `(int $result, int $field_num): int` | 列类型 OID |
| `pg_field_size` | `(int $result, int $field_num): int` | 列大小 |
| `pg_field_prtlen` | `(int $result, int $row_num, int $field_num): int` | 字段打印长度 |
| `pg_field_is_null` | `(int $result, int $row_num, int $field_num): bool` | 字段是否 NULL |
| `pg_field_table` | `(int $result, int $field_num): int` | 字段所属表 OID |
| `pg_fetch_row` | `(int $result): array` | 取一行（索引数组），取完返回 `[]` |
| `pg_fetch_assoc` | `(int $result): array` | 取一行（关联数组），取完返回 `[]` |
| `pg_fetch_array` | `(int $result, int $result_type = 3): array` | 取一行（`PGSQL_ASSOC`/`NUM`/`BOTH`） |
| `pg_fetch_all` | `(int $result, int $result_type = 3): array` | 取所有行 |
| `pg_fetch_all_columns` | `(int $result, int $col = 0): array` | 取单列所有值 |
| `pg_fetch_result_str` | `(int $result, int $row, string $field): string` | 取单值（多态拆分为 string 版本） |
| `pg_result_status` | `(int $result, int $mode = 1): int` | 结果状态（int 模式） |
| `pg_result_status_str` | `(int $result): string` | 结果状态（string 模式，多态拆分） |
| `pg_result_seek` | `(int $result, int $offset): bool` | 移动行指针 |
| `pg_result_error` | `(int $result): string` | 结果错误消息 |
| `pg_result_error_field` | `(int $result, int $field_code): string` | 错误字段值（`PGSQL_DIAG_*`） |
| `pg_last_error` | `(int $conn): string` | 连接最后错误消息 |
| `pg_last_notice` | `(int $conn): string` | 连接最后通知消息 |

#### 连接信息（10 个）

| 函数 | 签名 | 说明 |
|------|------|------|
| `pg_dbname` | `(int $conn): string` | 数据库名 |
| `pg_host` | `(int $conn): string` | 主机名 |
| `pg_port` | `(int $conn): int` | 端口 |
| `pg_options` | `(int $conn): string` | 连接选项 |
| `pg_tty` | `(int $conn): string` | 终端（兼容性） |
| `pg_version` | `(int $conn): array` | 版本信息 |
| `pg_parameter_status` | `(int $conn, string $param_name): string` | 服务器参数状态 |
| `pg_transaction_status` | `(int $conn): int` | 事务状态 |
| `pg_client_encoding` | `(int $conn): string` | 客户端编码 |
| `pg_set_client_encoding` | `(int $conn, string $encoding): int` | 设置客户端编码 |

#### 转义（5 个）

| 函数 | 签名 | 说明 |
|------|------|------|
| `pg_escape_string` | `(int $conn, string $data): string` | 转义字符串 |
| `pg_escape_literal` | `(int $conn, string $data): string` | 转义为字面量（带引号） |
| `pg_escape_identifier` | `(int $conn, string $data): string` | 转义为标识符（带引号） |
| `pg_escape_bytea` | `(int $conn, string $data): string` | 转义 bytea 二进制 |
| `pg_unescape_bytea` | `(string $data): string` | 反转义 bytea（无需连接句柄） |

#### COPY（5 个）

| 函数 | 签名 | 说明 |
|------|------|------|
| `pg_copy_to` | `(int $conn, string $table_name, string $separator = "\t", string $null_as = "\\\\N"): array` | COPY TO 导出为数组 |
| `pg_copy_from` | `(int $conn, string $table_name, array $rows, string $separator = "\t", string $null_as = "\\\\N"): bool` | COPY FROM 从数组导入 |
| `pg_put_copy_data` | `(int $conn, string $data): bool` | 发送 COPY 数据 |
| `pg_put_copy_end` | `(int $conn, string $error_msg = ""): bool` | 结束 COPY（可带错误消息） |
| `pg_end_copy` | `(int $conn): bool` | 同步 COPY 状态 |

#### DML（9 个）

| 函数 | 签名 | 说明 |
|------|------|------|
| `pg_meta_data` | `(int $conn, string $table_name): array` | 表元数据 |
| `pg_convert` | `(int $conn, string $table_name, array $assoc_array, int $flags = 0): array` | 转换关联数组匹配表结构 |
| `pg_insert_result` | `(int $conn, string $table_name, array $assoc, int $flags = 1): int` | 插入并返回受影响行数（多态拆分） |
| `pg_insert_sql` | `(int $conn, string $table_name, array $assoc, int $flags = 1): string` | 生成 INSERT SQL（`PGSQL_DML_STRING`） |
| `pg_update_result` | `(int $conn, string $table_name, array $assoc, array $condition, int $flags = 1): int` | 更新并返回受影响行数 |
| `pg_update_sql` | `(int $conn, string $table_name, array $assoc, array $condition, int $flags = 1): string` | 生成 UPDATE SQL |
| `pg_delete_result` | `(int $conn, string $table_name, array $condition, int $flags = 1): int` | 删除并返回受影响行数 |
| `pg_delete_sql` | `(int $conn, string $table_name, array $condition, int $flags = 1): string` | 生成 DELETE SQL |
| `pg_select` | `(int $conn, string $table_name, array $assoc, int $conditions = 0, int $flags = 1): array` | 查询匹配行 |

#### Large Object（12 个）

| 函数 | 签名 | 说明 |
|------|------|------|
| `pg_lo_create` | `(int $conn): int` | 创建 Large Object，返回 OID |
| `pg_lo_open` | `(int $conn, int $oid, string $mode): int` | 打开 Large Object（返回 Resource 句柄） |
| `pg_lo_read` | `(int $conn, int $lob, int $len): string` | 读取 Large Object |
| `pg_lo_write` | `(int $conn, int $lob, string $data): int` | 写入 Large Object |
| `pg_lo_seek` | `(int $conn, int $lob, int $offset, int $whence = 0): int` | 移动 Large Object 指针 |
| `pg_lo_tell` | `(int $conn, int $lob): int` | 当前位置 |
| `pg_lo_truncate` | `(int $conn, int $lob, int $len): bool` | 截断 Large Object |
| `pg_lo_close` | `(int $conn, int $lob): void` | 关闭 Large Object |
| `pg_lo_unlink` | `(int $conn, int $oid): bool` | 删除 Large Object |
| `pg_lo_import` | `(int $conn, string $filename): int` | 从文件导入 Large Object |
| `pg_lo_export` | `(int $conn, int $oid, string $filename): bool` | 导出 Large Object 到文件 |
| `pg_lo_read_all` | `(int $conn, int $lob): string` | 读取全部 Large Object 内容 |

#### 通知回调（1 个）

| 函数 | 签名 | 说明 |
|------|------|------|
| `pg_set_notice_callback` | `(int $conn, callable $callback): void` | 设置通知回调（`t_callback` 透传） |

### 差异说明

- PHP 原生 `mixed` 返回值按类型拆分（如 `pg_insert` → `pg_insert_result`/`pg_insert_sql`）
- `pg_fetch_result` 多态返回拆分为 `pg_fetch_result_str`（string 版本）
- `pg_result_status` 多态返回拆分为 `pg_result_status`（int）/`pg_result_status_str`（string）
- `pg_lo_open` 返回 `int`（Large Object 句柄以 int 存储，基于 `tphp_class_Resource`）
- `PgSql\Connection` / `PgSql\Result` 不声明为 PHP 类（AOT 模型下用 int 存储指针）
- `pg_pconnect` 持久连接池参考 vlang Pool+IdleSlot 模式实现
- `pg_set_notice_callback` 的 `callable` 参数映射为 `t_callback` 类型直接透传 C 层
- 所有错误抛 `Exception`（`tp_throw`），不返回 `false`

### 示例

```php
#import pgsql

// 1. 连接 + 查询
$conn = pg_connect("host=127.0.0.1 port=5432 dbname=test user=postgres password=secret");
$result = pg_query($conn, "SELECT id, name FROM users WHERE id = 1");
$row = pg_fetch_assoc($result);
echo $row["name"];
pg_free_result($result);

// 2. 参数化查询（Extended Query）
$result = pg_query_params($conn, "SELECT * FROM users WHERE age > $1 AND city = $2", [18, "Beijing"]);
while ($row = pg_fetch_assoc($result)) {
    echo $row["name"] . "\n";
}

// 3. 预处理 + 执行
pg_prepare($conn, "stmt1", "INSERT INTO users (name, age) VALUES ($1, $2)");
pg_execute($conn, "stmt1", ["Alice", 30]);

// 4. DML（生成 SQL 字符串，不执行）
$sql = pg_insert_sql($conn, "users", ["name" => "Bob", "age" => 25], PGSQL_DML_STRING);
echo $sql;  // INSERT INTO users (name, age) VALUES ('Bob', 25)

// 5. Large Object
$oid = pg_lo_create($conn);
$lob = pg_lo_open($conn, $oid, "w");
pg_lo_write($conn, $lob, "binary data");
pg_lo_close($conn, $lob);

// 6. 错误处理（可 try-catch）
try {
    pg_query($conn, "SELECT * FROM nonexistent_table");
} catch (Exception $e) {
    echo $e->getMessage();
}
```

---

## pdo_pgsql — PostgreSQL PDO 驱动 ✅ 已完成

> 文件: `ext/pdo_pgsql/src/pdo_pgsql.php`，按需引入 `#import pdo_pgsql`
>
> **通过 PDO 接口访问 PostgreSQL**，复用 ext/pgsql 的纯 C 协议实现（不依赖 libpq）。
> 用户通过 `new PDO("pgsql:host=...;port=...;dbname=...")` 使用，DSN 前缀 `pgsql:` 匹配。
> **认证**: trust / MD5 / SCRAM-SHA-256（由 ext/pgsql 提供）
> **参数化查询**: 将 PDO 的 `?` 占位符转换为 PostgreSQL 的 `$N` 占位符
> **Pdo\Pgsql 类说明**: TinyPHP AOT 模型中跨命名空间继承受限，驱动通过 `pdo_driver_t` 函数指针表
> 实现，用户直接使用 `new PDO("pgsql:...")` 即可。PostgreSQL 专属功能通过本文件提供的
> PHP 层包装函数访问，传入 PDO 实例的 `$db` 属性（dbh 句柄）即可调用。
> **AOT 类型安全**: 所有参数/返回值用 tphp 具体类型（int/string/array），指针以 int 存储，
> 用 `c_int()`/`php_int()` 转换。
> **错误处理**: 所有错误抛 `Exception`（`tp_throw`），可被 `try-catch` 捕获。

### 常量

| 常量 | 值 | 说明 |
|------|-----|------|
| `PDO::ATTR_DISABLE_PREPARES` | 0x7D0 | 禁用服务端预处理（始终用 Simple Query 协议） |
| `PDO::ATTR_RESULT_MEMORY_SIZE` | 0x7D1 | 结果集内存占用上限（字节，0=无限制） |

### 函数（3 个）

| 函数 | 签名 | 说明 |
|------|------|------|
| `pdo_pgsql_get_pid` | `(int $dbh): int` | 获取后端进程 PID（无效句柄返回 0） |
| `pdo_pgsql_get_notify` | `(int $dbh, int $result_type = 1, int $timeout_ms = 0): array` | 获取异步通知（返回 `[pid, channel, message]`，无通知返回 `[]`） |
| `pdo_pgsql_pgconn` | `(int $dbh): int` | 从 PDO dbh 提取底层 PGconn 句柄（可用于 ext/pgsql 的 `pg_*` 函数） |

### 示例

```php
#import pdo
#import pdo_pgsql

// 通过 PDO 接口使用 PostgreSQL
$db = new PDO("pgsql:host=127.0.0.1;port=5432;dbname=test", "postgres", "secret");

// 预处理 + 位置绑定（? → $N 自动转换）
$stmt = $db->prepare("SELECT * FROM users WHERE age > ? AND city = ?");
$stmt->bindValueInt(1, 18);
$stmt->bindValueStr(2, "Beijing");
$stmt->execute();
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    echo $row["name"] . "\n";
}

// PostgreSQL 专属功能
$pid = pdo_pgsql_get_pid($db->db);       // 后端进程 PID
$notify = pdo_pgsql_get_notify($db->db);  // 异步通知（LISTEN/NOTIFY）
$pgconn = pdo_pgsql_pgconn($db->db);      // 提取 PGconn 句柄，可直接调用 pg_* 函数

// 事务
$db->beginTransaction();
$db->exec("UPDATE users SET age = 31 WHERE id = 1");
$db->commit();

// 错误处理（可 try-catch）
try {
    $db->exec("SELECT * FROM nonexistent_table");
} catch (Exception $e) {
    echo $e->getMessage();
}
```

---

## ui — 图形界面扩展（基于 sokol）✅ 已完成

> 文件: `ext/ui/src/ui.php` / `ui_widget.php` / `ui_layout.php` / `ui_softinput.php` / `ui_enums.php` + `ui.h`
>
> 基于 sokol C 库（sokol_app/sokol_gfx/sokol_glue/sokol_log/sokol_time）实现跨平台图形界面。
> 纯 phpc 模式：C 包装函数在 `ui.h`，PHP 类在 `ui*.php`。所有 UI 类使用 `namespace UI`，
> 用户通过 `use UI\App;` 引入。`#import ui` 自动加载全部 PHP 源文件。
>
> **平台后端**：Windows/Linux = OpenGL (SOKOL_GLCORE)，macOS = Metal (SOKOL_METAL)，Android = GLES3 (SOKOL_GLES3 + SOKOL_NO_ENTRY)。
> TCC 缺失 `windowsx.h`，故 Windows 使用 OpenGL 而非 D3D11。GL 版本固定为 3.3 Core（覆盖绝大多数桌面 GPU，比 sokol 默认 4.3 兼容性更好）。Android GLES 版本为 3.0（不能用桌面 GL 3.3，否则 `eglCreateContext` 失败秒退）。
>
> **Android 平台适配**（`-os android`）：
> - **入口机制**：Android NativeActivity 模式下入口为 `ANativeActivity_onCreate`（sokol 提供），而非 `main()`。sokol 在其中调用 `sokol_main()` 获取 desc，此时 PHP 的 `Main::main()` 未执行。`sokol_main()` 首次调用时执行 `tphp_android_main()` 填充全局 desc；CodeGenerator 在 Android 模式下生成 `tphp_android_main()` 替代 `main()`，且不释放 Main 对象和 `_argv`（避免闭包悬垂指针）
> - **JNI 软键盘**：`SoftInput::show()`/`hide()` 通过 JNI 调用 Android `InputMethodManager`。`_ui_android_show_softinput`/`_ui_android_hide_softinput` 使用 `AttachCurrentThread` 安全附加线程，`_ui_jni_check` 检查异常并释放局部引用
> - **触摸事件转换**：sokol 在 Android 上生成 `TOUCHES_BEGAN/ENDED/MOVED` 事件，`_ui_sokol_event_cb` 中将首个触摸点转换为 `MOUSE_DOWN/UP/MOVE` 事件，桌面端 PHP 代码无需修改即可在 Android 上响应触摸
> - **原生按键事件拦截**：sokol 的 `_sapp_android_key_event` 只处理 BACK 键，其他按键直接丢弃。通过 `native_event_cb` 钩子拦截 `AInputEvent`，`_ui_android_map_keycode` 将 `AKEYCODE_*` 映射为 PHP Key 枚举的 ASCII 值（A-Z/0-9/空格/回车/退格/方向键等），构造 `sapp_event` 并调用 `_ui_sokol_event_cb` 分发；对可打印字符（ASCII 32-126）额外生成 `CHAR` 事件驱动 TextBox 输入
> - **stdout 重定向**：Android NativeActivity 的 stdout 默认不输出到 logcat。用 pipe + 后台线程将 stdout/stderr 重定向到 `__android_log_write`（tag: `tphp`），便于调试
> - **默认竖屏**：`AndroidManifest.xml` 中 `android:screenOrientation` 设置为 `portrait`
> - **字体渲染**：使用内置 font8x8 点阵字体表，跨平台通用（替代 Win32 GDI 字体生成，Android 上不可用）
>
> **后端自动选择（GPU → CPU 软件渲染回退）**：
> `ui_app_run` 先尝试 sokol（GPU）后端，若初始化失败（如无硬件 GPU、RDP/虚拟机环境下 WGL 像素格式选择失败 `WIN32_WGL_FIND_PIXELFORMAT_FAILED`），sokol panic 经 `_ui_slog_func` 转为 `tp_throw`，`ui_app_run` 捕获后自动回退到 CPU 软件渲染后端（`_cpu_app_run`，输出 `[ui] GPU backend unavailable, falling back to CPU software renderer`）。整个过程对 PHP 侧透明，用户代码无需任何改动。
> - **DrawDevice 抽象**（`ui_draw_device_t`，参考 vlang/ui 的 DrawDevice 接口设计）：定义 `begin_pass`/`end_pass`/`fill_rect`/`draw_text`/`draw_line`/`draw_rect`/`draw_circle` 函数指针表。`ui_clear`/`ui_fill_rect` 等公共 API 通过 `_ui_state.device` 分派到当前后端（`_ui_sokol_device` 或 `_ui_cpu_device`）。
> - **CPU 软件渲染后端**（`ui_cpu.h`，Windows 实现）：Win32 窗口 + `CreateDIBSection` 帧缓冲 + GDI 显示。`fill_rect`/`draw_line`（Bresenham）/`draw_rect`/`draw_circle`（中点圆算法）直接操作帧缓冲像素；`draw_text` 用 GDI `TextOut` 绘制（利用系统字体，无需内嵌字体）。事件循环用 `PeekMessage`，事件构造 `sapp_event` 结构保持与 sokol 后端兼容（PHP 侧 `Event::fromPtr` 无需改动）。
> - **后端类型**（`ui_backend_t`）：`UI_BACKEND_SOKOL`（GPU）/ `UI_BACKEND_CPU`（软件渲染）。窗口查询函数（`ui_window_width` 等）按 `backend` 分派到 `sapp_*` 或 `_cpu_window_*`。
> - macOS Metal 总是可用（含软件回退），无需 CPU 后端；Linux 桌面通常有 GPU 或 llvmpipe，X11 CPU 后端预留未实现。
>
> **异常处理契约**（关键设计，杜绝静默崩溃）：
> - **sokol panic 不再 `abort()`**：自定义 `_ui_slog_func` 替代 `sokol_log.h` 的 `slog_func`，panic 级别（log_level==0）输出到 stderr 后调 `tp_throw`（可被 try-catch 捕获），而非 `slog_func` 的 `abort()`（直接杀死进程，PHP 侧无法处理）。
> - **C 回调异常捕获**：三个 sokol 回调（init/frame/event）均包裹 `TP_TRY`/`TP_CATCH_ANY`。PHP 回调内 `tp_throw` 触发 `longjmp`，若不捕获会跳过 sokol 事件循环导致静默崩溃。捕获后输出到 stderr 并调 `sapp_request_quit()` 干净退出。CPU 后端的事件循环同样包裹回调异常。
> - **`sapp_run` 异常包裹 + 自动回退**：`ui_app_run` 用 `TP_TRY`/`TP_CATCH_ANY` 包裹 `sapp_run`，sokol 初始化 panic（如 `WIN32_WGL_FIND_PIXELFORMAT_FAILED`）被捕获后自动回退到 CPU 软件渲染后端，而非直接返回错误或 `exit(1)`。
> - **pass 自动收尾**：`ui_state_t.pass_active` 跟踪 `begin_pass` 状态，frame 回调末尾自动调用 `end_pass`，即使 PHP 回调中途抛异常也不会漏掉，防止后端状态不一致导致下一帧渲染崩溃。
>
> **绘图契约**：所有 `Graphics::*` 方法必须在 `onFrame` 回调内调用，否则抛 `Exception("drawing outside frame callback")`；无绘图设备时抛 `Exception("no draw device initialized")`。`ui_clear` 调用 `device->begin_pass` 并设置 `pass_active=true`；`ui_end_frame` 调用 `device->end_pass` 手动结束 pass（可选，frame 回调会自动调用）；无 active pass 时调 `ui_end_frame` 抛 `Exception("end_frame called without active pass")`。GPU 后端（sokol_gfx）和 CPU 后端（ui_cpu.h）均实现全部形状绘制函数（`fill_rect`/`draw_line`/`draw_rect`/`draw_circle`），无 GPU 环境自动回退到 CPU 后端。
>
> **参数校验**（不静默处理）：
> - `App::__construct` / `ui_app_run`：width/height 必须 > 0，否则抛 `Exception("app_run: invalid window dimensions")`
> - `Window::setCursor`：cursor 必须 < `_SAPP_MOUSECURSOR_NUM`，否则抛 `Exception("set_cursor: invalid cursor value")`
> - `Event::fromPtr` 系列：NULL 事件指针抛 `Exception("event_*: NULL event pointer")`（不返回 0 误导调用方）
> - `ui_end_frame`：在 frame 回调外调用或无 active pass 时抛异常（不静默 return）
>
> **事件契约**：sokol `sapp_event*` 指针以 `t_int` 流转（`intptr_t` 转换），PHP 侧通过 `Event::fromPtr($evPtr)` 解析。
>
> **回调存储**：`onInit`/`onFrame`/`onEvent`/`SoftInput::onInput` 注册的闭包通过 `phpc_env_pin` 钉在全局 pin 表，防异步回调 UAF。
>
> **编译链接标志**（`#import ui` 自动添加）：
> - Windows：`-lgdi32 -luser32 -lopengl32 -lshell32`
> - Linux：`-lX11 -lGL -lXi -lXcursor -ldl -lpthread`
> - macOS：`-framework Cocoa -framework MetalKit -framework Metal`
> - Android：`-landroid -lEGL -lGLESv3 -llog`（NDK Clang 工具链，`-os android` 时）
>
> **命名空间**：所有 UI 类位于 `UI` 命名空间下，通过 `use UI\App;` 等引入。C 包装函数使用 `tphp_fn__ui_*` 双下划线前缀（内部使用，不暴露给用户空间）；PHP 侧通过 `_ui_*()` 函数调用（带前导下划线，约定为内部使用）。用户通过 OOP 类使用 UI 功能，不直接调用 `_ui_*` 函数。

### App 类 — 应用入口

> 管理窗口生命周期和回调。

| 方法 | 签名 | 说明 |
|------|------|------|
| `__construct` | `(int $width, int $height, string $title): void` | 设置窗口尺寸和标题 |
| `onInit` | `(callable $cb): void` | 注册初始化回调（`sg_setup` 后调用一次） |
| `onFrame` | `(callable $cb): void` | 注册每帧回调（闭包内调用 `Graphics::*` 绘图） |
| `onEvent` | `(callable $cb): void` | 注册事件回调，签名 `function(int $evPtr)` |
| `run` | `(): void` | 进入主循环（阻塞，直到窗口关闭） |

### Window 类 — 窗口查询（静态）

| 方法 | 签名 | 说明 |
|------|------|------|
| `width` (静态) | `(): int` | 当前窗口宽度（可随 `Resized` 事件变化） |
| `height` (静态) | `(): int` | 当前窗口高度 |
| `dpiScale` (静态) | `(): float` | DPI 缩放比例（high_dpi 模式） |
| `setCursor` (静态) | `(int $cursor): void` | 设置鼠标光标类型（`Cursor` 枚举值） |

### Event 类 — 事件对象

> 由 `Event::fromPtr(int $evPtr)` 从 `sapp_event*` 指针解析。属性映射 sokol 事件字段。

| 属性/方法 | 类型 | 说明 |
|-----------|------|------|
| `type` | `int` | 事件类型（`EventType` 枚举值） |
| `x` | `int` | 鼠标 X 坐标 |
| `y` | `int` | 鼠标 Y 坐标 |
| `button` | `int` | 鼠标按键（`MouseButton` 枚举值） |
| `key` | `int` | 键码（`Key` 枚举值） |
| `modifiers` | `int` | 修饰键位掩码（`KeyMod` 位组合） |
| `codepoint` | `int` | 字符输入 Unicode codepoint（`Char` 事件） |
| `touchCount` | `int` | 触摸点数量 |
| `fromPtr` (静态) | `(int $evPtr): Event` | 从 C 事件指针构造 Event 对象 |

### Color 类 — RGBA 颜色

> 0-255 分量。`toUint()` 返回 `0xAABBGGRR` 格式（sokol little-endian RGBA）。

| 属性/方法 | 类型/签名 | 说明 |
|-----------|----------|------|
| `r` | `int` | 红色分量（0-255） |
| `g` | `int` | 绿色分量（0-255） |
| `b` | `int` | 蓝色分量（0-255） |
| `a` | `int` | 透明度分量（0-255） |
| `__construct` | `(int $r = 0, int $g = 0, int $b = 0, int $a = 255): void` | RGBA 分量构造 |
| `toUint` | `(): int` | 转换为 `0xAABBGGRR` uint32 |
| `black` (静态) | `(): Color` | 黑色 (0,0,0,255) |
| `white` (静态) | `(): Color` | 白色 (255,255,255,255) |
| `red` (静态) | `(): Color` | 红色 (255,0,0,255) |
| `green` (静态) | `(): Color` | 绿色 (0,255,0,255) |
| `blue` (静态) | `(): Color` | 蓝色 (0,0,255,255) |

### Rect 类 — 矩形区域

| 属性/方法 | 类型/签名 | 说明 |
|-----------|----------|------|
| `x` | `int` | 左上角 X 坐标 |
| `y` | `int` | 左上角 Y 坐标 |
| `width` | `int` | 宽度 |
| `height` | `int` | 高度 |
| `__construct` | `(int $x = 0, int $y = 0, int $width = 0, int $height = 0): void` | 坐标和尺寸构造 |
| `contains` | `(int $x, int $y): bool` | 判断点是否在矩形内（含左上角，不含右下角） |

### Graphics 类 — 2D 绘图（静态）

> 所有方法必须在 `onFrame` 回调内调用，否则抛 `Exception("drawing outside frame callback")`。

| 方法 | 签名 | 说明 |
|------|------|------|
| `clear` (静态) | `(Color $color): void` | 清屏（设置 pass action clear color） |
| `fillRect` (静态) | `(Rect $rect, Color $color): void` | 填充矩形 |
| `drawText` (静态) | `(int $x, int $y, string $text, Color $color): void` | 绘制文本 |
| `drawLine` (静态) | `(int $x1, int $y1, int $x2, int $y2, Color $color): void` | 绘制直线 |
| `drawRect` (静态) | `(Rect $rect, Color $color): void` | 绘制矩形边框 |
| `drawCircle` (静态) | `(int $cx, int $cy, int $r, Color $color): void` | 绘制圆形 |

### Widget 抽象基类 — 控件基类

> 所有控件的基类。生命周期：`init` → `proposeSize` → `setPos` → `draw` → `cleanup`。
>
> 注：TinyPHP CodeGenerator 对 `interface` 不生成方法实现，故用 `abstract class` + stub 方法。

| 属性/方法 | 类型/签名 | 说明 |
|-----------|----------|------|
| `bounds` | `Rect` | 控件边界矩形 |
| `init` | `(): void` | 初始化控件状态 |
| `draw` | `(): void` | 渲染控件（在 frame 回调内调用） |
| `setPos` | `(int $x, int $y): void` | 设置位置（由布局系统调用） |
| `proposeSize` | `(int $availableW, int $availableH): array` | 提议尺寸，返回 `[w, h]` |
| `size` | `(): array` | 返回 `[width, height]` |
| `pointInside` | `(int $x, int $y): bool` | 命中测试 |
| `cleanup` | `(): void` | 释放资源 |
| `onMouseDown` | `(int $x, int $y): void` | 鼠标按下事件 |
| `onMouseUp` | `(int $x, int $y): void` | 鼠标释放事件 |
| `onMouseMove` | `(int $x, int $y): void` | 鼠标移动事件 |
| `onKeyDown` | `(int $key): void` | 键盘按下事件 |
| `onChar` | `(int $codepoint): void` | 字符输入事件 |

### WidgetContainer 类 — 控件容器

> 管理子控件列表和事件分发。z-index = 插入顺序（后加 = 上层）。

| 属性/方法 | 类型/签名 | 说明 |
|-----------|----------|------|
| `children` | `array` | 子控件列表 |
| `focusedIdx` | `int` | 当前焦点控件索引（-1 = 无焦点） |
| `addChild` | `(Widget $w): void` | 添加子控件 |
| `removeChild` | `(Widget $w): void` | 移除子控件（重置焦点） |
| `childCount` | `(): int` | 子控件数量 |
| `drawAll` | `(): void` | 按 z-index 升序绘制所有子控件 |
| `hitTestIndex` | `(int $x, int $y): int` | 从上层到下层命中测试，返回索引（-1 = 未命中） |
| `dispatchMouseDown` | `(int $x, int $y): void` | 分发鼠标按下（命中控件设为焦点） |
| `dispatchMouseUp` | `(int $x, int $y): void` | 向所有子控件广播鼠标释放 |
| `dispatchMouseMove` | `(int $x, int $y): void` | 向所有子控件广播鼠标移动 |
| `dispatchKeyDown` | `(int $key): void` | 向焦点控件分发键盘按下 |
| `dispatchChar` | `(int $codepoint): void` | 向焦点控件分发字符输入 |
| `cleanupAll` | `(): void` | 清理所有子控件 |

### Button 类 — 按钮控件

> 继承 `Widget`。属性：`text`/`bgColor`/`textColor`/`onClick`/`bounds`/`state`。

| 属性/方法 | 类型/签名 | 说明 |
|-----------|----------|------|
| `text` | `string` | 按钮文本 |
| `bgColor` | `Color` | 背景色 |
| `textColor` | `Color` | 文本颜色 |
| `onClick` | `mixed` | 点击回调（`function(): void`） |
| `state` | `int` | 控件状态（`WidgetState` 枚举值） |
| `__construct` | `(string $text = ""): void` | 构造按钮并设置文本 |
| `press` | `(): void` | 按下（设置 Pressed 状态） |
| `release` | `(): void` | 释放（若 Pressed 则触发 `click`） |
| `click` | `(): void` | 触发 `onClick` 回调 |

### Label 类 — 文本标签

> 继承 `Widget`。无交互，仅显示。属性：`text`/`color`/`fontSize`/`bounds`。

| 属性/方法 | 类型/签名 | 说明 |
|-----------|----------|------|
| `text` | `string` | 标签文本 |
| `color` | `Color` | 文本颜色 |
| `fontSize` | `int` | 字体大小（默认 14） |
| `__construct` | `(string $text = ""): void` | 构造标签并设置文本 |

### TextBox 类 — 文本输入框

> 继承 `Widget`。支持文本编辑、光标移动、焦点管理。

| 属性/方法 | 类型/签名 | 说明 |
|-----------|----------|------|
| `text` | `string` | 当前文本 |
| `cursorPos` | `int` | 光标位置（0 到 `strlen(text)`） |
| `focused` | `bool` | 是否聚焦 |
| `bgColor` | `Color` | 背景色 |
| `textColor` | `Color` | 文本颜色 |
| `cursorColor` | `Color` | 光标颜色 |
| `__construct` | `(string $text = ""): void` | 构造输入框并设置初始文本 |
| `focus` | `(): void` | 获取焦点 |
| `blur` | `(): void` | 失去焦点 |
| `handleKeyDown` | `(int $key): void` | 处理键盘（Backspace/Delete/Left/Right/Home/End） |
| `handleChar` | `(int $codepoint): void` | 处理字符输入（仅可打印 ASCII 32-126） |

### CheckBox 类 — 复选框

> 继承 `Widget`。属性：`checked`/`text`/`onChange`/`color`。

| 属性/方法 | 类型/签名 | 说明 |
|-----------|----------|------|
| `checked` | `bool` | 是否选中 |
| `text` | `string` | 复选框标签文本 |
| `onChange` | `mixed` | 状态变化回调（`function(bool $checked): void`） |
| `color` | `Color` | 边框颜色 |
| `__construct` | `(string $text = ""): void` | 构造复选框并设置标签文本 |
| `toggle` | `(): void` | 切换选中状态，触发 `onChange` |
| `setChecked` | `(bool $checked): void` | 直接设置选中状态（不触发回调） |

### Slider 类 — 滑块

> 继承 `Widget`。值夹紧到 `[min, max]`。

| 属性/方法 | 类型/签名 | 说明 |
|-----------|----------|------|
| `min` | `int` | 最小值 |
| `max` | `int` | 最大值 |
| `value` | `int` | 当前值（始终在 `[min, max]` 内） |
| `onChange` | `mixed` | 值变化回调（`function(int $value): void`） |
| `trackColor` | `Color` | 轨道颜色 |
| `handleColor` | `Color` | 手柄颜色 |
| `__construct` | `(int $min, int $max, int $value): void` | 构造滑块并设置范围与初始值 |
| `beginDrag` | `(int $x, int $y): void` | 开始拖动 |
| `drag` | `(int $x, int $y): void` | 拖动中（仅 `dragging=true` 时更新） |
| `endDrag` | `(): void` | 结束拖动 |
| `setValue` | `(int $value): void` | 直接设置值（夹紧，不触发回调） |

### Layout 抽象基类 — 布局基类

> 继承 `Widget`。布局本身也是控件，可嵌套。

| 方法 | 签名 | 说明 |
|------|------|------|
| `addWidget` | `(Widget $w, int $a = 0, int $b = 0, int $c = 0, int $d = 0): void` | 添加子控件（参数语义由子类定义） |
| `updateLayout` | `(): void` | 重新计算子控件位置和尺寸 |
| `asWidget` | `(): Widget` | 返回自身作为 Widget（用于嵌套） |

### Stack 类 — flex 线性布局

> 继承 `Layout`。支持 Row/Column 方向、Compact/Stretch/Fixed 尺寸模式。
>
> **静态分派限制**：`proposeSize` 由 Widget 基类返回 `[0,0]`，Compact 模式直接读取子控件预计算的 `bounds`（用户需在 `addWidget` 前先调用 `proposeSize`）。

| 属性/方法 | 类型/签名 | 说明 |
|-----------|----------|------|
| `direction` | `int` | 方向（`Direction::Row=0` / `Column=1`） |
| `spacing` | `int` | 子控件间距（默认 4） |
| `padding` | `int` | 容器内边距（默认 0） |
| `children` | `array` | 子控件列表 |
| `sizeModes` | `array` | 各子控件的尺寸模式（`ChildSize` 枚举值） |
| `dimensions` | `array` | 各子控件的固定尺寸（Fixed 模式） |
| `__construct` | `(int $direction = 0): void` | 构造布局，`direction`=`Direction::Row=0` / `Column=1` |
| `column` (静态) | `(...$children): Stack` | 创建垂直排列布局（Compact 模式） |
| `row` (静态) | `(...$children): Stack` | 创建水平排列布局（Compact 模式） |
| `addWidget` | `(Widget $w, int $sizeMode = 0, int $fixedDim = 0): void` | 添加子控件，`sizeMode`=`ChildSize` 枚举值 |

### CanvasLayout 类 — 绝对定位布局

> 继承 `Layout`。子控件位置由 `addWidget` 时指定。

| 属性/方法 | 类型/签名 | 说明 |
|-----------|----------|------|
| `children` | `array` | 子控件列表 |
| `childX` | `array` | 各子控件 X 坐标 |
| `childY` | `array` | 各子控件 Y 坐标 |
| `childW` | `array` | 各子控件宽度 |
| `childH` | `array` | 各子控件高度 |
| `addWidget` | `(Widget $w, int $x, int $y, int $width, int $height): void` | 添加子控件并设置边界 |
| `updateLayout` | `(): void` | 重新应用 `addWidget` 时记录的边界 |

### SoftInput 类 — 软键盘管理（静态）

> 桌面端 `show`/`hide` 为 no-op（有物理键盘），`isVisible` 始终返回 false。
> Android 端通过 JNI 调用 `InputMethodManager` 实现（`_ui_android_show_softinput`/`_ui_android_hide_softinput`）。
> 回调存储在 C 层 `_ui_softinput_cb`。

| 方法 | 签名 | 说明 |
|------|------|------|
| `show` (静态) | `(): void` | 显示软键盘（桌面端 no-op，Android 端 JNI 调用 InputMethodManager） |
| `hide` (静态) | `(): void` | 隐藏软键盘（桌面端 no-op，Android 端 JNI 调用 InputMethodManager） |
| `isVisible` (静态) | `(): bool` | 软键盘是否可见 |
| `onInput` (静态) | `(callable $cb): void` | 注册字符输入回调（`function(int $codepoint): void`） |
| `dispatch` (静态) | `(int $codepoint): void` | 分发字符输入（由 `Char` 事件触发） |
| `clear` (静态) | `(): void` | 清理回调 |

### 枚举

| 枚举 | 类型 | 说明 |
|------|------|------|
| `EventType` | `int` | 事件类型（Invalid=0/KeyDown=1/KeyUp=2/Char=3/MouseDown=4/MouseUp=5/MouseScroll=6/MouseMove=7/MouseEnter=8/MouseLeave=9/TouchDown=10/TouchMove=11/TouchUp=12/TouchCancel=13/Resized=14/Iconified=15/Restored=16/Focused=17/Unfocused=18/Suspended=19/Resumed=20/Quit=21） |
| `Key` | `int` | 常用键码（Invalid=0/Backspace=8/Tab=9/Enter=13/Escape=27/Space=32/PageUp=33/PageDown=34/End=35/Home=36/Left=37/Up=38/Right=39/Down=40/Delete=46/A-Z=65-90/Shift=16/Ctrl=17/Alt=18/F1-F12=112-123） |
| `MouseButton` | `int` | 鼠标按键（Left=0/Right=1/Middle=2） |
| `KeyMod` | `int` | 修饰键位掩码（Shift=1/Ctrl=2/Alt=4/Super=8） |
| `Cursor` | `int` | 光标类型（Arrow=0/IBeam=1/Cross=2/Hand=3/ResizeX=4/ResizeY=5/ResizeAll=6/None=7） |
| `Direction` | `int` | 布局方向（Row=0/Column=1） |
| `WidgetState` | `int` | 控件状态（Normal=0/Hovered=1/Pressed=2/Focused=3/Disabled=4） |
| `LayoutAlign` | `int` | 布局对齐（Start=0/Center=1/End=2/Stretch=3） |
| `ChildSize` | `int` | 子元素尺寸模式（Compact=0/Stretch=1/Fixed=2） |

### 示例

```php
#import ui

use UI\App;
use UI\Color;
use UI\Rect;
use UI\Graphics;
use UI\Event;
use UI\EventType;
use UI\Key;

class Main {
    public function main(): void {
        $app = new App(640, 480, "My App");

        $app->onFrame(function(): void {
            Graphics::clear(Color::black());
            Graphics::fillRect(new Rect(10, 10, 100, 50), Color::red());
            Graphics::drawText(10, 80, "Hello UI", Color::white());
        });

        $app->onEvent(function(int $evPtr): void {
            $ev = Event::fromPtr($evPtr);
            if ($ev->type === EventType::KeyDown->value
                && $ev->key === Key::Escape->value) {
                echo "escape pressed\n";
            }
        });

        $app->run();
    }
}
```

---

## 异常

> 文件: `object/try.h`

| 语法 | C 实现 | 内存安全 |
|------|--------|---------|
| `try { ... } catch (Exception $e)` | `setjmp/longjmp` | ✅ 先 `tphp_rt_free_all()` |
| 多 catch 子句 `catch (A $e) ... catch (B $e)` | 类型匹配表 + `exception_offset` 计算子类→Exception 偏移 | ✅ 子类异常被父类 catch 正确匹配 |
| `finally { ... }` | `TP_FINALLY` 宏 | ✅ 始终执行 |
| `throw new Exception("msg")` | 复制到 256B 栈缓冲 → `longjmp` | ✅ |
| `throw "string"` | `tp_throw` → `longjmp` | ✅ |
| `throw` 表达式 `$x = throw new E()` | TCC 语句表达式 `({ throw_code; 0; })` 包装 | ✅ 语句上下文直接展开为 throw 语句 |
| `error($msg)` | 生成 `tp_throw(STR_PTR_V($msg))` | ✅ 可被 try-catch 捕获，未捕获时回退 `exit(1)` |
| `Type|Exception` 返回类型 | 纯语法提示，C 代码只生成 `\|` 前的类型 | 编译期检查：含 `throw`/`error()` 的函数必须声明 `\|Exception`（`Main::main` 除外） |

---

## OOP 语法

> 文件: `object/object.h`

| 语法 | 实现 | 说明 |
|------|------|------|
| `class B extends A` | COS struct 嵌套 `_parent` | — |
| `abstract class` | 禁止 `new` | 抽象方法无体 |
| `interface` | 纯抽象类 | 编译期类型标记 |
| `implements` | 编译期契约 | 不强制检查 |
| `trait` + `use TraitName` | 方法扁平化 | — |
| `instanceof` | `tp_obj_is_a(obj, &_class_X)` | 遍历类链 |
| `parent::method()` | `&self->_parent` + 父类函数名 | — |
| `__CLASS__ / __METHOD__` | 编译期字符串常量 | — |
| `__destruct` | 作用域结束自动 `tp_obj_release` | 池回收 |
| Property Hook `public string $x { get => ...; set => ...; }` | 编译为 `static type cn_get_x(cn*)` / `static void cn_set_x(cn*, type)` | hook 体内 `$this->x` 直接访问 backing field；短形式 `set => expr;` 中 `$value` 为新值；支持继承 |
| Pipe Operator `$x \|> f(...)` | 纯语法糖 → `f($x)` | `...` 占位符控制参数位置；无占位符时追加为末尾参数；支持链式和可调用变量 |

### Resource 类型

> 文件: `object/resource.h`

| 特性 | 实现 | 说明 |
|------|------|------|
| `Resource` 基类 | `tphp_class_Resource` | 模拟 PHP `zend_resource`，含 `handle`/`type`/`ptr` 字段 |
| `File` 子类 | `tphp_class_File extends Resource` | 替代 PHP `fopen()` resource，含 `FILE* fp` |
| `is_resource($v)` | `tp_obj_is_a` 检查继承链 | 编译期静态类型直接返回 `true`/`false` |
| `$f->getType()` | 返回资源类型 ID | `RSRC_TYPE_FILE=0` 等 |
| `$f->isOpen()` | 检查文件是否打开 | `fp != NULL` |
| `$f->close()` | 幂等关闭 | 重复调用安全 |
| 资源列表 | LIFO 空闲槽复用池 | O(1) 插入/删除，最多 2048 活跃资源 |
| RAII 自动释放 | `tp_obj_release` → `__destruct` → `fclose` | 作用域结束自动关闭 |
| `tphp_rt_free_all_resources()` | 异常路径释放所有资源 | 防内存泄漏 |

---

### stdClass 动态属性对象

> 文件: `object/stdclass.h`

PHP 原生 `stdClass` 兼容实现，作为动态属性容器。基于 `t_array` 哈希索引存储字符串键到 `t_var` 的映射。

| 特性 | 实现 | 说明 |
|------|------|------|
| `new stdClass()` | `new_stdClass()` | 分配 `tphp_class_stdClass`（t_object header + t_array* props），初始化空属性表 |
| `$obj->prop = $v` | `tphp_fn_stdclass_set(obj, STR_LIT("prop"), VAR_XXX(v))` | 字面量属性名赋值，`wrapTVar` 按源类型包装为 t_var |
| `$obj->prop` | `tphp_fn_stdclass_get(obj, STR_LIT("prop"))` | 字面量属性名读取，返回 t_var；未定义返回 VAR_NULL |
| `isset($obj->prop)` | `tphp_fn_stdclass_isset(obj, STR_LIT("prop"))` | PHP 语义：键存在且值非 null 才返回 true |
| `unset($obj->prop)` | `tphp_fn_stdclass_unset(obj, STR_LIT("prop"))` | 从属性表移除指定键 |
| `foreach ($obj as $k => $v)` | `emitStdClassForeach` | 遍历内部 t_array，$k 为 t_string，$v 为 t_var |
| `(object) $array` | `tphp_fn_stdclass_from_array(arr)` | 复制数组键值对为 stdClass 属性 |
| `(array) $stdClass` | `tphp_fn_stdclass_to_array(obj)` | 提取属性表为关联数组 |
| `get_object_vars($obj)` | `tphp_fn_stdclass_to_array(obj)` | 返回属性关联数组（仅支持 stdClass） |
| `clone $obj` | `tphp_fn_stdclass_clone(obj)` | 深拷贝属性表（运行时已就绪，待 clone 语法接入） |
| `var_dump($obj)` | 递归输出属性 | `object(stdClass)#N (count) { ["k"]=> val ... }` |

> **AOT 约束**：仅支持字面量属性名（编译期已知）。不支持 `$obj->$var` 动态属性名（与 `$$var` 同理）。`json_decode` 保持返回 array（JSON 键运行时才知道，返回 stdClass 后无法用字面量属性名访问）。

## Generator / yield

> 文件: `object/generator.h` + `include/minicoro.h`
>
> 基于 minicoro 协程库实现。生成器函数编译为**双函数**：协程入口 `tphp_gen_<name>_entry(mco_coro* co)` + 包装器 `tphp_fn_<name>(...)`。
> **不使用 yield 的函数零开销**——编译为普通函数，不引入协程。

### yield 语法

| 语法 | 说明 | 示例 |
|------|------|------|
| `yield $v` | 产出值 | `yield 42;` |
| `yield $k => $v` | 产出键值对 | `yield "a" => 10;` |
| `yield from $gen` | 委托子生成器或 array，透传其所有值；返回子生成器的 return 值 | `$r = yield from inner();` |
| `return $v;` | 生成器返回值（配合 `getReturn()`） | `return 99;` |
| `$g->send($v)` | 向 yield 表达式发送值，返回下一个 yield 值 | `$g->send(100)` |

### Generator 类方法

| 方法 | 返回 | 说明 |
|------|------|------|
| `current()` | `t_var` | 当前 yield 的值；未启动时先 `rewind()` |
| `key()` | `t_var` | 当前 yield 的 key |
| `next()` | `t_var` | 推进到下一个 yield，返回新值 |
| `send($v)` | `t_var` | 发送值到 yield 表达式，返回下一个 yield 值 |
| `valid()` | `t_int` | 是否仍有可迭代的值（1/0） |
| `getReturn()` | `t_var` | 生成器的 return 值 |
| `rewind()` | `void` | 首次 resume，推进到第一个 yield |

### foreach 迭代

```php
function gen(): Generator {
    yield 1;
    yield "a" => 10;
    yield 2;
    return 99;
}

$g = gen();
foreach ($g as $k => $v) {
    var_dump($k);   // 0, "a", 1
    var_dump($v);   // 1, 10, 2
}
var_dump($g->getReturn());  // 99
```

### send() 双向传值

```php
function gen(): Generator {
    $x = yield 1;   // 接收 send() 传入的值
    yield $x + 1;
}

$gen = gen();
var_dump($gen->current());   // 1
var_dump($gen->send(100));   // 101
```

### AOT 约束

| 约束 | 说明 |
|------|------|
| `callable` 参数须用闭包 | `gen(1, 3, "apply")` 不可行——字符串是运行时数据，编译期无法解析为函数符号。须用 `gen(1, 3, fn($x) => apply($x))` |
| macOS + TCC | **不支持**，编译时报错。TCC 的 `ucontext_t` 布局与 Apple Silicon 不匹配，请使用 `-cc gcc` 或 `-cc clang` |

### 平台支持

| 平台 | TCC | GCC / Clang |
|------|-----|-------------|
| Windows x86_64 | Win32 Fiber | ASM |
| Linux x86_64 | ucontext | ASM |
| Linux aarch64 | ucontext | ASM |
| macOS aarch64 + TCC | **不支持**（编译报错） | ASM |

---

## 多线程 (Thread / Mutex / CondVar / WaitGroup)

> 文件: `object/thread.h`（COS 封装）+ `compat/tinycthread.h`（tinycthread v1.1 优化版）+ `compat/tls.h`（TCC+Windows TLS 兼容层）
>
> 基于 tinycthread 跨平台线程库（zlib license），提供 OOP 风格的线程 API。
> 采用**策略 A（Thread-Local 运行时）**：每个线程拥有独立的 `str_pool`/`arr_pool`/`obj_pool`，
> 线程间只能传递值类型（int/float/bool）或堆分配数据，无锁竞争。

### Thread 类

| 方法 | 签名 | 说明 |
|------|------|------|
| `__construct` | `(callable $fn): void` | 接收闭包（须返回 `int` 作为线程退出码）；闭包副本堆分配，`start` 后转交子线程 |
| `start` | `(): bool` | `thrd_create` 创建线程；成功返回 `true` |
| `join` | `(): int` | `thrd_join` 等待线程结束，返回退出码；未启动/已结束返回缓存的 `ret` |
| `detach` | `(): bool` | `thrd_detach` 分离线程（结束后自动回收）；析构时若仍运行自动 detach |
| `yield` (静态) | `(): void` | `thrd_yield` 让出 CPU 时间片 |
| `sleep` (静态) | `(float $seconds): void` | `thrd_sleep` 秒级休眠（支持小数毫秒/微秒） |
| `id` (静态) | `(): int` | 当前线程 ID（Windows: `GetCurrentThreadId`，POSIX: `pthread_self`） |

### Mutex 类

| 方法 | 签名 | 说明 |
|------|------|------|
| `__construct` | `(bool $recursive = false): void` | `mtx_init`；`recursive=true` 用 CRITICAL_SECTION，`false` 用 SRWLOCK（更轻量） |
| `lock` | `(): bool` | `mtx_lock` 阻塞加锁 |
| `tryLock` | `(): bool` | `mtx_trylock` 非阻塞加锁；已锁定返回 `false` |
| `unlock` | `(): bool` | `mtx_unlock` 解锁 |

### CondVar 类

| 方法 | 签名 | 说明 |
|------|------|------|
| `__construct` | `(): void` | `cnd_init`；Windows 用 CONDITION_VARIABLE，POSIX 用 `pthread_cond_t` |
| `wait` | `(Mutex $m): bool` | `cnd_wait` 原子释放锁并阻塞，被唤醒后重新加锁 |
| `signal` | `(): bool` | `cnd_signal` 唤醒一个等待线程 |
| `broadcast` | `(): bool` | `cnd_broadcast` 唤醒所有等待线程（已修复 tinycthread POSIX 的 `pthread_cond_signal` bug） |

### WaitGroup 类

| 方法 | 签名 | 说明 |
|------|------|------|
| `__construct` | `(): void` | `tphp_wg_init`；单 u64 state（高32位任务数 + 低32位等待数）+ Semaphore |
| `add` | `(int $delta): void` | 增减任务计数（`delta` 可为负） |
| `done` | `(): void` | 任务完成，计数减 1 |
| `wait` | `(): void` | 阻塞直到所有任务完成（计数归零） |

### 示例

```php
// Thread + join
$thread = new Thread(function(): int {
    return 42;
});
$thread->start();
$ret = $thread->join();  // 42

// Thread + WaitGroup 跨线程同步
$wg = new WaitGroup();
$wg->add(1);
$t = new Thread(function() use ($wg): int {
    $wg->done();
    return 0;
});
$t->start();
$wg->wait();
$t->join();

// Mutex 互斥
$mutex = new Mutex(false);
$mutex->lock();
// ... 临界区 ...
$mutex->unlock();

// 静态方法
Thread::yield();
Thread::sleep(0.5);
$tid = Thread::id();
```

### 线程安全模型

| 机制 | 说明 |
|------|------|
| Thread-Local 运行时 | 每个线程独立 `str_pool`（128KB Arena）/`arr_freelist`（128 槽）/`obj_freelist`（128 槽），无锁竞争 |
| TCC+Windows TLS | TCC 不支持 `_Thread_local`/`__declspec(thread)`；`compat/tls.h` 用 Windows TLS API（`TlsAlloc`/`TlsGetValue`/`TlsSetValue`）实现真正线程隔离 |
| GCC/Clang/MSVC | 直接用 `_Thread_local`（性能更好） |
| 闭包跨线程传递 | `t_callback {func, env}` 堆分配副本传递给子线程，`_tphp_thread_entry` 适配器调用后释放 |
| 子线程清理 | 退出时调 `tphp_thread_cleanup()` 释放 TLS 内存池；`tphp_tls_destroy()` 释放 TLS 结构体 |

### 平台支持

| 平台 | TCC | GCC / Clang |
|------|-----|-------------|
| Windows x86_64 | ✅ Win32 线程 + TLS API | ✅ Win32 线程 + `_Thread_local` |
| Linux x86_64 | ✅ pthread + `_Thread_local` | ✅ pthread + `_Thread_local` |
| Linux aarch64 | ✅ pthread + `_Thread_local` | ✅ pthread + `_Thread_local` |
| macOS aarch64 | ✅ pthread + `_Thread_local` | ✅ pthread + `_Thread_local` |

---

## 异步与协程通信 (Channel / Future / chan_select)

> 文件: `object/channel.h`（参考 vlang 的 CSP 通信模型）
>
> 基于 tinycthread 的 mutex + condvar 实现线程/协程间安全通信。
> 采用**自旋 + 阻塞混合策略**：push/pop/await 阻塞前自旋 750 次以减少 syscall。
> Channel 使用固定容量环形缓冲区，push/pop 零 malloc。

### Channel 类

> CSP 风格有界通道。容量在 `__construct` 时固定，不可扩展。

| 方法 | 签名 | 说明 |
|------|------|------|
| `__construct` | `(int $capacity): void` | 分配 `capacity` 大小的环形缓冲区，初始化 mutex/condvar |
| `push` | `(mixed $v): void` | 阻塞式入队：满则自旋 750 次 → cnd_wait；入槽时 `_arr_val_retain`；**close 后 push 抛 `ChannelClosedException`** |
| `pop` | `(): mixed` | 阻塞式出队：空则检查 `is_closed`（关闭返回 `null`）→ cnd_wait；返回值不额外 retain |
| `tryPush` | `(mixed $v): bool` | 非阻塞入队：满立即返回 `false`，成功返回 `true`；**close 后抛 `ChannelClosedException`** |
| `tryPop` | `(): mixed` | 非阻塞出队：空立即返回 `null`，成功返回元素 |
| `close` | `(): void` | 设置 `is_closed=1` → `cnd_broadcast` 唤醒所有等待者；剩余元素由 dtor 释放 |
| `isClosed` | `(): bool` | 无锁原子读 `is_closed` |
| `length` | `(): int` | 无锁原子读 `count` |
| `capacity` | `(): int` | 返回构造时固定的容量 |

### Future 类

> 一次性异步结果传递机制。state 机：PENDING → RESOLVED / REJECTED，状态转换原子化。

| 方法 | 签名 | 说明 |
|------|------|------|
| `create` (静态) | `(): Future` | 创建 PENDING 状态的 Future |
| `resolve` | `(mixed $v): void` | CAS state PENDING→RESOLVED，retain result，`cnd_broadcast` 唤醒等待者 |
| `reject` | `(mixed $err): void` | CAS state PENDING→REJECTED，retain error，`cnd_broadcast` 唤醒等待者 |
| `await` | `(): mixed` | 自旋 750 次检查 state → cnd_wait；resolve 返回 result；**reject 抛 `FutureRejectedException`** |
| `isReady` | `(): bool` | 原子读 state（RESOLVED 或 REJECTED 返回 `true`） |
| `isRejected` | `(): bool` | 原子读 state 是否 REJECTED |
| `then` | `(callable $cb): Future` | 链式回调：非抛出式等待原 Future，resolve 时调 `$cb(result)` 写入新 Future，reject 透传 |
| `catch` | `(callable $cb): Future` | 错误恢复：非抛出式等待原 Future，reject 时调 `$cb(error)` 写入新 Future，resolve 透传 |
| `all` (静态) | `(array $futures): Future` | 计数器追踪 N 个子 Future，全部 resolve 则 resolve 数组，任一 reject 则整体 reject |
| `race` (静态) | `(array $futures): Future` | 任一子 Future 完成即转发结果/错误到新 Future |

### chan_select 函数

```php
function chan_select(array $channels, int $timeout_ms = -1): int {}
```

| 返回值 | 含义 |
|--------|------|
| `>= 0` | 就绪通道在 `$channels` 中的索引（有元素可 pop 或已关闭） |
| `-1` | 超时（`timeout_ms > 0` 时生效） |
| `-2` | 所有通道都已关闭 |

> spin 循环遍历 channels，间隔用 `thrd_yield` 避免空转。`timeout_ms = -1` 表示无限等待。

### 异常类型

| 异常 | 抛出场景 | 父类 |
|------|----------|------|
| `ChannelClosedException` | `push`/`tryPush` 到已关闭的 Channel | `Exception` |
| `FutureRejectedException` | `await` 一个被 `reject` 的 Future | `Exception` |

### 示例

```php
// Channel 跨线程通信
$ch = new Channel(4);
$t = new Thread(function() use ($ch): int {
    $ch->push(42);
    $ch->close();
    return 0;
});
$t->start();
$v = $ch->pop();   // 42
$t->join();

// Future 异步结果
$f = Future::create();
$t2 = new Thread(function() use ($f): int {
    $f->resolve("done");
    return 0;
});
$t2->start();
echo $f->await();  // "done"
$t2->join();

// Future 链式 + 错误恢复
$fut = Future::create();
$fut->resolve(10);
$doubled = $fut->then(fn(mixed $x): mixed => $x * 2);
echo $doubled->await();  // 20

// chan_select 多路复用
$ch1 = new Channel(4);
$ch2 = new Channel(4);
$ch2->push("hello");
$idx = chan_select([$ch1, $ch2], 100);  // 1
```

### 内存安全契约

| 资源 | push/resolve 时 | pop/await 时 | close/dtor 时 |
|------|----------------|--------------|---------------|
| `t_var`（含 string/array/object） | `_arr_val_retain`（+1 引用） | 不额外 retain（调用方管理返回值生命周期） | 遍历 `_arr_val_release` 释放剩余元素 |
| Channel 环形缓冲区 | 构造时一次性 `malloc` | — | dtor `free` ringbuf + 销毁 mtx/cnd |
| Future result/error | resolve/reject 时 retain | await 返回时不 retain | dtor release 两者 |

> **关键保证**：即使忘记 `close()`，Channel dtor 也会释放所有剩余元素；Future dtor 释放 result 和 error，无内存泄漏。

### 性能契约

| 机制 | 说明 |
|------|------|
| 自旋 750 次 | push/pop/await 阻塞前自旋，减少 syscall（与 vlang 一致） |
| 环形缓冲区 | Channel push/pop 零 malloc，固定容量 O(1) 入队出队 |
| 无锁原子读 | `isReady`/`isClosed`/`length`/`isRejected` 不加锁 |
| thrd_yield | chan_select spin 间隔让出 CPU，避免空转 |

---

## C 互操作 (PHPC)

> 文件: `phpc.h`
> 设计参考 vlang:纯透传函数用 `#define` 宏(零开销 + 常量折叠),有副作用/复杂逻辑的用 `static inline`(确保单次求值)

### 类型桥接

| 函数 | 方向 | 说明 |
|------|------|------|
| `c_int($x)` | PHP → C | → `int32_t` (宏,零开销,有截断 t_int→int32) |
| `c_str($s)` | PHP → C | → `const char*` (static inline,STR_PTR 单次求值) |
| `c_void_ptr($p)` | PHP → C | → `void*` 透传 (宏,显式类型标记) |
| `php_int($x)` | C → PHP | → `t_int` (宏,零开销,有提升 int32→t_int) |
| `php_str($s)` | C → PHP | → `t_string` (深拷贝,参数 const char*;static inline,有 strlen+dup 逻辑) |
| `php_str_ptr($ptr)` | C → PHP | → `t_string` (接受 void*,内部 cast 为 const char*;宏展开为 php_str 单次调用) |
| `php_str_clone($s)` | C → PHP | → `t_string` (深拷贝,明确克隆语义;宏展开为 php_str) |

> **已移除**：`c_float` / `php_float` — t_float 就是 double，转换无意义。float 类型直接传递即可。

### C 调用与类型注解

| 函数/语法 | 方向 | 说明 |
|------|------|------|
| `C->func(args)` | 直接 C 调用 | 无 name mangling。**赋值给变量必须显式声明类型**（`int $x = C->foo()` / `C.void* $p = C->foo()`），编译期即捕获类型错误。语句上下文（`C->foo();`）无需声明。表达式上下文需用 cast 包装（`php_int(C->foo())`）或先赋值给类型化变量 |
| `C->CONST` | 直接 C 常量/枚举/宏访问 | 无括号形式。**赋值给变量必须显式声明类型**（`int $x = C->COLOR_RED;`）。默认按 `int` 推断 |
| `C.Type` | C 类型注解 | 函数参数/返回值用 C 类型（`C.int`→`int`，`C.Point*`→`Point*`，指针用 `*` 后缀） |
| `(C.XXX) expr` | C 类型 cast | `(C.int)`/`(C.int32)`/`(C.int64)`/`(C.uint32)`/`(C.uint64)`/`(C.float)`/`(C.double)`/`(C.char)`/`(C.bool)`/`(C.void)`/`(C.void*)`/`(C.char*)`/`(C.int*)`/`(C.double*)`/`(C.XXX*)` |

> **C-> 调用类型声明规则**（AOT 类型安全，编译期消除返回类型歧义）：
>
> | 使用场景 | 规则 | 示例 |
> |---------|------|------|
> | **语句上下文**（void 函数） | 不需要类型声明 | `C->sqlite3_finalize($s);` |
> | **赋值上下文** | **必须显式声明类型**，否则编译错误 | `int $rc = C->sqlite3_step($s);`<br>`C.void* $p = C->sqlite3_column_text($s, 0);` |
> | **表达式上下文**（`if`/比较/算术） | 用 cast 包装或先赋值给类型化变量 | `if (php_int(C->sqlite3_step($s)) == 100)`<br>或 `int $rc = C->sqlite3_step($s); if ($rc == 100)` |
>
> **类型选择**（C 返回类型 → 推荐声明）：
>
> | C 返回类型 | 推荐声明 | 说明 |
> |-----------|---------|------|
> | `int` / `int64_t` | `int` | t_int = int64_t，C 隐式转换 |
> | `double` / `float` | `float` | t_float = double，echo/cast/return 正常工作 |
> | `const char*`（借用指针） | `C.char*` | 借用指针，需 `php_str()` 转为拥有的 t_string |
> | `void*` / `T*`（指针） | `C.void*` / `C.T*` | 指针类型，需 `phpc_ptr_to_int()` 转为 int 存储 |
>
> 原因：C-> 调用返回类型编译期不可靠（无法解析 C 头文件签名），强制声明消除了白名单和默认 `t_int` 假设，符合 AOT 显式优于推导的哲学。

### 预处理器指令

| 指令 | 说明 |
|------|------|
| `#include "file.h"` | 生成 `#include` |
| `#flag [CC] [OS] flags` | 平台+编译器过滤 |
| `#callback type name(params)` | 声明 C 回调签名 |
| `#import name` | 按需引入 ext/name/src/*.php（C 依赖由 .php 中 #flag 显式声明） |
| `#cstruct Name { C.type field; ... }` | 声明 C 结构体字段布局,支持 `$p->field` 原生访问(编译期展开为 `((Struct*)$p)->field`) |

### 数组互操作

| 函数 | 方向 | 说明 |
|------|------|------|
| `phpc_arr_int/dbl` | PHP→C | 严格类型检查,**类型不匹配抛 tp_throw 异常**,malloc,**自动注册到运行时**(程序结束/异常自动释放，**无需手动 `phpc_free`**) |
| `phpc_arr_str` | PHP→C | 严格类型检查,malloc,**不自动注册**(需用 `defer phpc_free_str_arr($arr, $len)` 或手动释放) |
| `phpc_new_arr_int/dbl/str` | C→PHP | 深拷贝 |
| `phpc_new_arr()` | C→PHP | 深拷贝 |

### 对象/回调互操作

| 函数 | 方向 | 说明 |
|------|------|------|
| `phpc_obj` | 双向 | 对象指针(借用语义,宏透传) |
| `phpc_new_obj` | C→PHP | 包裹 C 指针为 PHP 对象（接管语义） |
| `phpc_unregister_obj` | 双向 | 解除对象注册（C 库自行 free 时调用，防 double-free） |
| `phpc_obj_steal` | 双向 | 标记对象"已分离"（refcount=-1），C 库可安全 free（防 double-free） |
| `phpc_fn` / `phpc_env` | 双向 | 函数/环境指针（宏透传,字段访问） |
| `phpc_fn_i32/i64/f64` | 双向 | 类型化函数指针 cast(宏,零开销) |
| `phpc_new_fn` / `phpc_new_fn_env` | C→PHP | 构造回调(宏,复合字面量) |
| `phpc_thunk('name', $fn)` | no-env 回调 | 按 #callback 生成 thunk |
| `phpc_assert_ptr` | 安全 | 断言指针非 NULL，NULL 时抛 tp_throw 异常（可 try-catch） |
| `phpc_env_pin` / `phpc_env_unpin` | 安全 | 固定/解除固定闭包 env（异步回调安全） |

### 内存管理

| 函数 | 说明 |
|------|------|
| `phpc_auto($ptr)` | 通用 C 指针自动注册,程序结束/异常时自动 free(透传 ptr,方便链式调用) |
| `phpc_free($ptr)` | free + **先注销注册防 double-free** + 自动置零变量防 UAF |
| `phpc_free_str_arr($arr, $len)` | 释放字符串数组 + 自动置零 |

### 指针 ↔ 整数桥接

| 函数 | 方向 | 说明 |
|------|------|------|
| `phpc_ptr_to_int($ptr)` | void* → t_int | 让 C 指针以 t_int 在 PHP 层流转(用 intptr_t 保证可移植性) |
| `phpc_int_to_ptr($v)` | t_int → void* | 函数内部转回 void* 调用 C 库 |

> **设计模式**: 函数参数/返回值用 tphp 类型(int/string/array),内部用 phpc_int_to_ptr 转回 void*。
> `defer C->fclose($f)` / `defer C->free($p)` 确保所有退出路径正确清理（exif 扩展采用此模式）。
> 参见 `ext/exif/src/exif.php` — 所有函数签名纯 PHP 风格,C 类型转换封装在函数内部。

---

## 注解系统（Annotation）

> 文件: `include/object/annotation.h`, `src/CodeGenerator.php`
> 设计: 纯编译期消费（方向 A），运行时零开销，不支持运行时反射。详见 `GRAMMAR.md` §14

### 声明与使用

```php
// 声明注解类型（附着于全局/命名空间 const，必须为空数组）
#[Attribute(path: string, method: array = [])]
const ROUTE = [];

// 使用注解（仅位置参数，附着于 class/method/function）
#[ROUTE("/test", ["GET", "POST"])]
public function test(int $id): void { ... }
```

### AnnotationEntry 内置类

每个注解使用编译期收集为一个 `AnnotationEntry` 实例（C 结构体，非用户类）：

| 成员 | 类型 | 说明 |
|------|------|------|
| `$data` | `array` | 位置参数数组 |
| `$type` | `string` | `method` / `static_method` / `class` / `function` |
| `$name` | `string` | 限定名（`Ns\Class->method` / `Ns\Class::static` / `Ns\func` / `Ns\Class`） |

### 编译期 API

| 表达式 | 展开为 | 说明 |
|--------|--------|------|
| `ROUTE[0]` | `_annot_ROUTE_0` | 静态 `AnnotationEntry*` 指针 |
| `ROUTE[0]->data` | `_annot_ROUTE_0->data` | 位置参数数组 |
| `ROUTE[0]->type` | `_annot_ROUTE_0->type` | 目标类型字符串 |
| `ROUTE[0]->name` | `_annot_ROUTE_0->name` | 限定名字符串 |
| `ROUTE[0]->call(...$args)` | 直接调用目标方法/静态方法/函数 | 零开销（编译期展开） |
| `ROUTE[0]->newInstance(...$args)` | `new_tphp_class_X(args)` | 零开销（编译期展开） |

### 限制

| 特性 | 状态 | 原因 |
|------|------|------|
| 位置参数 | ✅ 支持 | — |
| 命名参数 `#[ROUTE(path: "/x")]` | ❌ 禁用 | 与全局命名参数禁用一致 |
| 静态索引 `ROUTE[0]` | ✅ 支持 | 编译期展开 |
| 动态索引 `ROUTE[$i]` | ❌ 不支持 | 编译期无法确定目标 |
| 运行时反射 `ReflectionAttribute` | ❌ 不支持 | AOT 无运行时元数据 |
| 注解作用于属性/参数 | ❌ 不支持 | 当前仅 class/method/function |
| 注解继承 | ❌ 不支持 | 编译期收集不递归父类 |

### 示例

参见 `test/attribute/main.php` + `test/attribute/child/child.php`（多文件测试，覆盖 method/class/跨命名空间注解）。

---

## 内存安全

| 机制 | 说明 |
|------|------|
| 资源追踪链表 | `tphp_rt_register(ptr, type)` → `tp_throw`/`tphp_rt_free_all()` 时遍历释放 |
| 64KB 字符串池 | bump allocator，≤512B 零 `malloc` |
| 128 槽数组池 | LIFO 复用，1.5× 增长因子 |
| 128 槽对象池 | LIFO 复用，`tp_obj_release` 回收到池 |
| COS refcount | `tp_obj_retain` / `tp_obj_release` |
| scope 自动析构 | 方法尾注入 `tp_obj_release(var)` |
| 异常安全 | `tp_throw` 先 `tphp_rt_free_all()` 再 `longjmp` |

---

## 编译器兼容

| 文件 | 说明 |
|------|------|
| `compat.h` | TCC：`round` fallback + `ceil/floor/sqrt/pow` 声明 |
| `json.h` | TCC：`isnan`/`isinf` 自研实现 |
| `conv.h` | TCC：`_tphp_pow10` 循环替代 `pow()` |
| `tphp.php` | Win GCC 自动 `-Wno-implicit-function-declaration` |
| **原则** | 所有 TCC 特殊处理用 `#ifdef __TINYC__` 隔离 |

---

## 暂缓（可做但低优先级）

| 功能 | 原因 |
|------|------|
| `serialize / unserialize` | PHP 序列化格式完整解析器 |
| `Date* OO API` (30+) | 需完整 DateTime 类 |
| `array_multisort / natsort` | 专用场景 |
| `usort / uasort / uksort` | 需闭包回调 |
| `calendar` 全套 | ~1000行 sdncal，延后 |

---

## AOT 不可行

以下依赖运行时解释器、动态符号表或 VM 机制，**永久不支持**：

| 类别 | 函数/特性 |
|------|----------|
| 动态执行 | `eval()`, `create_function()`, `assert($str)` |
| 动态调用 | `call_user_func`, `$fn()`, `$obj->$method()` |
| 符号表 | `$$var`, `${expr}`, `compact()`, `extract()`, `get_defined_vars()` |
| 反射 | `Reflection*`, `debug_backtrace()`, `debug_print_backtrace()` |
| 回调注册 | `set_error_handler`, `register_shutdown_function`, `ob_start($cb)` |
| 动态引入 | `include`, `require`, `include_once`, `require_once` |
| 动态参数（定参） | `func_get_args()`, `func_num_args()`, `func_get_arg($i)`（仅在定参函数中不可行；可变参数函数 `...$args` 中可支持，参数编译为 `t_array*`） |

## AOT 可行但未实现

以下语法 AOT 物理可行（编译期信息完整），尚未实现，属于待办：

| 语法 | PHP 版本 | 说明 |
|------|---------|------|
| 表达式位置 `match` | 8.4 | 已有 `match` 语句，仅需放开表达式上下文 |
| `as` 类型转换表达式 | 8.4 | 编译期已知类型，类似已有强制转换 |
