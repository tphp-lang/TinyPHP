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
| OOP / 异常 / Resource | `object/` | 14 |
| Generator / yield | `object/generator.h` + `minicoro.h` | 7 |
| 多线程 (Thread/Mutex/CondVar/WaitGroup) | `object/thread.h` + `compat/tinycthread.h` + `compat/tls.h` | 15 |
| C 互操作 (PHPC) | `phpc.h` | 40 |
| **合计** | | **303+** |

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
| `var_dump(mixed $value, mixed ...$rest): void` | `var_dump(mixed $value): void` | type switch → `fprintf`/`fwrite` 递归输出，O(节点数)，零中间缓冲 | 单参数；浮点 `%g`（6 位有效数字）非 PHP `%.14G`（14 位）；对象仅输出 `object(ClassName)` 无属性列表 |
| `print_r(mixed $value, bool $return = false): string\|true` | `print_r(mixed $value): void` | 递归格式化，O(节点数)，流式写入 stdout | **无 `$return` 参数**，始终返回 void；对象仅输出 `ClassName Object` 无属性；无循环引用检测（递归数组会栈溢出） |
| `exit(int\|string $status): void` | `exit(int $code): void` | `exit(code)` 单次 libc 调用，O(1) | 仅接受 int（PHP 还接受 string 消息）；无 shutdown 回调 |
| `isset(mixed $var, mixed ...$rest): bool` | `isset(mixed $var): bool` | 指针类型 → `ptr != NULL`；值类型 → 编译期 `true`；O(1) | 单参数；语义为指针 NULL 检查（值类型如 int/string 永远返回 true） |
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

### 增删 / 统计

| php函数 | tphp函数 | 性能说明 | 差异说明 |
|------|--------|------|------|
| `count(Countable\|array $value, int $mode = COUNT_NORMAL): int` | `count(array $array): int` | `a->length`，O(1) | 无 `$mode` 参数，不支持 `COUNT_RECURSIVE` |
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
| `array_keys(array $array, mixed $search_value = null, bool $strict = false): array` | `array_keys(array $array): array` | 遍历提取 key，O(n) | 无 `$search_value`/`$strict` 参数 |
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
| `array_reverse(array $array, bool $preserve_keys = false): array` | `array_reverse(array $array, bool $preserve_keys): array` | 倒序复制，O(n) | `$preserve_keys` 必传 |
| `array_column(array $array, int\|string\|null $column_key, int\|string\|null $index_key = null): array` | `array_column(array $array, string $column_key): array` | 遍历行匹配 string 键 push 值，O(n×m) | 仅 string 列名；无 `$index_key` 参数；对象行 push `NULL` |
| `max(mixed $value, mixed ...$values): mixed` | `max(array $array): mixed` | 遍历比较，O(n) | 仅数组形式（不支持可变参数）；空数组致命错误 |
| `min(mixed $value, mixed ...$values): mixed` | `min(array $array): mixed` | 遍历比较，O(n) | 同 `max` |
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
| `abs(int\|float $num): int\|float` | `abs(int $num): int` | `llabs(v)`，O(1) | 仅整型（PHP 同时支持 int/float） |
| `round(int\|float $num, int $precision = 0, int $mode = RoundingMode::HALF_UP): float` | `round(float $num): float` | libc `round(v)`，O(1) | 无 `$precision`/`$mode` 参数 |
| `ceil(int\|float $num): float` | `ceil(float $num): float` | libc `ceil`，O(1) | — |
| `floor(int\|float $num): float` | `floor(float $num): float` | libc `floor`，O(1) | — |
| `sqrt(float $num): float` | `sqrt(float $num): float` | `v >= 0.0 ? sqrt(v) : 0.0`，O(1) | 负数返回 `0.0`（PHP 返回 `NAN`） |
| `pow(int\|float $base, int\|float $exp): int\|float` | `pow(mixed $base, mixed $exp): mixed` | int^int 走 `tphp_rt_pow_int` 快速幂 O(log n)；否则 libc `pow` | int^int 返回 int（负指数返回 0，PHP 返回 float） |
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
| `C->func(args)` | 直接 C 调用 | 无 name mangling |
| `C->CONST` | 直接 C 常量/枚举/宏访问 | 无括号形式，按 `t_int` 推断 |
| `C.Type` | C 类型注解 | 函数参数/返回值用 C 类型（`C.int`→`int`，`C.Point*`→`Point*`，指针用 `*` 后缀） |
| `(C.XXX) expr` | C 类型 cast | `(C.int)`/`(C.int32)`/`(C.int64)`/`(C.uint32)`/`(C.uint64)`/`(C.float)`/`(C.double)`/`(C.char)`/`(C.bool)`/`(C.void)`/`(C.void*)`/`(C.char*)`/`(C.int*)`/`(C.double*)`/`(C.XXX*)` |

### 预处理器指令

| 指令 | 说明 |
|------|------|
| `#include "file.h"` | 生成 `#include` |
| `#flag [CC] [OS] flags` | 平台+编译器过滤 |
| `#callback type name(params)` | 声明 C 回调签名 |
| `#import name` | 按需引入 ext/name/src/*.php + *.c |
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
