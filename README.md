# TinyPHP

> PHP → C 转译编译器，将 PHP 子集转为安全 C 代码，由编译器编译为原生产物（可执行文件、静态库、动态库等）。

## 快速开始

```bash
# 编译单文件
php tphp.php test/var/var.php

# 编译多文件
php tphp.php main.php demo.php lib/helper.php

# 扫描当前目录所有 .php 编译
php tphp.php .

# 指定输出
php tphp.php main.php -o app.exe

# 指定编译器（默认内置 TCC）
php tphp.php main.php -cc gcc
php tphp.php main.php -cc clang
```

编译后直接运行生成的产物即可（Windows 为 `.exe`，Linux/macOS 为无后缀可执行文件）。

## 编译流水线

```
PHP 源码 → Lexer → Token[] → Parser → AST → CodeGenerator → .c → 编译器 → 产物
```

- `src/Lexer.php` — 词法分析，逐字符扫描生成 Token 流
- `src/Parser.php` — 递归下降解析，构建 AST
- `src/CodeGenerator.php` — 访问者模式生成 C 代码
- `tcc/win32/` — 内置 TCC 0.9.27（需 MSYS2 MinGW64 编译）

## 支持的语言特性

### 类型系统

| PHP | C |
|-----|---|
| `int` | `int64_t` |
| `float` | `double` |
| `string` | `struct { char *data; int length; }` |
| `bool` | `bool` |
| `null` | `void *` |
| `array` | `t_array *`（有序映射，int/string 键，支持嵌套） |
| `callable` | `t_callback { void *func; void *env; }` |

### 语法支持

```php
class Main {
    public function main(): void {
        // 变量
        $a = 10;
        $b = "hello";
        $c = true;
        $d = 1.01;
        $e = null;

        // 运算符: + - * / % . && || ! == != < > <= >=
        // 复合赋值: += -= *= /= .=
        // 自增自减: ++$i $i++ --$i $i--
        // 三元 & 合并: ?:  ??
        // 位运算: & | ^ ~ << >>
        $sum = $a + 5;
        $a += 10;
        $a++;
        $r = 10 % 3;
        $x = $a > 0 ? "yes" : "no";
        $y = $maybeNull ?? "default";

        // 控制流: if/elseif/else/while/do-while/for/foreach/switch/break/continue
        do { $i++; } while ($i < 10);

        // 字符串拼接 & 插值
        $s = $a . " " . $b;
        $s2 = "hello $d\n";
        $s3 = "hello {$d}\n";

        // 数组
        $arr = [1, 2, 3];
        $map = ["key" => "val", "nested" => [4, 5]];
        $x = $arr[0];
        count($arr);

        // 控制流
        if ($a > 0) { } elseif ($a == 0) { } else { }
        while ($i < 10) { $i++; }
        for ($i = 0; $i < 10; $i++) { }
        foreach ($arr as $v) { }
        foreach ($map as $k => $v) { }
        switch ($v) {
            case 1: break;
            default: break;
        }
        break; continue;

        // 输出 & 调试
        echo "hello\n";
        var_dump($a);
        exit(0);

        // 对象 & 链式调用
        $d = new Demo();
        $d->hello();
        $calc->add(5)->multiply(3);

        // 闭包 & 变量捕获
        $fn = function (int $x): int { return $x * 2; };
        var_dump($fn(21));
        $m = 10;
        $capture = function (int $x) use ($m): int { return $x * $m; };

        // 类型转换
        $s = (string)123;
        $i = (int)"456";
        $f = (float)"1.2";
        $b = (bool)0;
        $a = (array)42;
    }
}
```

### 枚举

```php
enum Color: string {
    case RED = "red";
    case GREEN = "green";
}

enum Num: int {
    case ONE = 1;
    case TWO = 2;
}

// 使用
$c = Color::RED;
echo $c->value;   // "red"
echo $c->name;    // "RED"
$v = Num::ONE->value;  // 1
// 指针同一性比较: $c == Color::RED
// 值比较: $c->value == "red"
// 跨命名空间枚举需要 use 导入
```

### heredoc / nowdoc

```php
// heredoc：支持插值和转义
$str = <<<EOD
Hello $name!
Line with \n newline
Dollar sign: \$var  // 不插值
EOD;

// nowdoc：无插值，纯文本
$str = <<<'NOW'
Hello $name!
No interpolation.
NOW;
```

### 运算符完整列表

| 类别 | 运算符 |
|------|--------|
| 算术 | `+` `-` `*` `/` `%` |
| 字符串 | `.` |
| 比较 | `==` `!=` `<` `>` `<=` `>=` |
| 逻辑 | `&&` `\|\|` `!` |
| 位运算 | `&` `\|` `^` `~` `<<` `>>` |
| 赋值 | `=` |
| 复合赋值 | `+=` `-=` `*=` `/=` `.=` |
| 自增/自减 | `++` `--`（前缀+后缀） |
| 三元/合并 | `?:` `??` |
| 类型转换 | `(int)` `(float)` `(string)` `(bool)` `(array)` |
| 其他 | `->` `=>` `::` `\` `list()` |

### 类型强制转换

| 转换 | 示例 | 规则 |
|------|------|------|
| `(string)` | `(string)123` → `"123"` | int/float→数字串，bool→"1"/""，null→"" |
| `(int)` | `(int)"123abc"` → `123` | 字符串提取前导数字，float 截断 |
| `(float)` | `(float)"1.2e3"` → `1200` | 支持科学计数法 |
| `(bool)` | `(bool)"0"` → `false` | ""/"0"/0/0.0/null/[]→false，其余→true |
| `(array)` | `(array)123` → `[123]` | 标量→单元素数组，null→空数组 |

`(string)` 转换数组/对象时编译报错；`(int)`/`(float)` 转换对象时编译报错。

### 多文件 & 命名空间

```php
// main.php — 入口（必须有全局 class Main）
use Demo\Demo;
use Demo\Status;      // 枚举也可以 use 导入
use function Demo\myFunc;

class Main { ... }

// demo.php — 命名空间
namespace Demo;
class Demo { ... }
function myFunc(): void { ... }
enum Status: int { case ACTIVE = 1; }

// other.php — 同名命名空间跨文件扩展
namespace Demo;
function myFunc2(): void { ... }
```

支持 `use Foo\Bar`、`use Foo\Bar as Alias`、`use Foo\{A, B, function F}` 组合导入。
枚举跨命名空间引用需 `use` 导入，否则视为未定义常量报错。

### 内置函数

| 函数 | 说明 |
|------|------|
| `echo` | 输出字符串，支持多参数逗号分隔 |
| `var_dump` | PHP 风格递归调试输出 |
| `var_export` | 可读字符串输出 |
| `count` | 数组元素计数 |
| `exit($code)` / `die($code)` | 终止程序，无参默认 `exit(0)` |
| `isset($x)` | 变量是否已设置且不为 null |
| `empty($x)` | 变量是否为 PHP 假值（null/0/0.0/false/空串/"0"） |
| `list($a,$b)` | 数组解构赋值 `list($a,$b) = [1,2]` |
| `unset($x)` | 释放变量（int→0，string→清空，array→free，object→析构） |

### 控制流完整列表

`if` / `elseif` / `else` · `while` · `do-while` · `for` · `foreach` · `switch` / `case` / `default` · `break` · `continue` · `goto` · `match`

### 新增类型

| 特性 | 示例 | C 映射 |
|------|------|--------|
| `mixed` | `mixed $x = 42; $x = "hi";` | `t_var`（类型标签 union） |
| 联合类型 | `int\|string $v = 10;` | `t_var`（同上） |

> 声明为 `mixed` 或联合类型的变量才能变更类型；普通变量类型一旦确定不可变。

### 不支持 / TODO

| 优先级 | 特性 | 说明 |
|--------|------|------|
| 🟡 | `strlen` `strpos` `substr` `trim` `explode` `implode` | 字符串处理函数 |
| 🟡 | `array_push` `array_pop` `array_shift` `array_keys` `array_values` `in_array` `array_key_exists` | 数组操作函数 |
| 🟡 | `is_int` `is_string` `is_float` `is_bool` `is_array` `is_null` | 类型检测函数 |
| 🟡 | `intval` `floatval` `strval` `boolval` `sprintf` `printf` | 格式化/转换函数 |
| 🟡 | `rand` `mt_rand` `time` `date` `sleep` `usleep` | 系统函数 |
| 🟡 | `file_get_contents` `file_put_contents` `json_encode` `json_decode` | I/O 和 JSON |
| 🟡 | `try` / `catch` / `throw` | 异常处理 |
| 🟢 | `**` 幂运算符、`<=>` 太空船、命名参数 | PHP 8.0+ 语法 |
| 🟢 | `readonly` `never` | 新类型关键字 |
| 🟢 | `declare()` | 编译器指令 |
| 🟢 | `#[Attribute]` | 元编程/注解 |
| 🟢 | `include`/`require`、`eval()` | AOT 编译不支持 |

> **明确不支持**（设计限制，非 TODO）：`global`、默认参数值、可变参数 `…$args`、`static` 变量、`__LINE__` `__FILE__` `__DIR__`、`yield` 生成器 — 依赖运行时动态行为或 C 无对应机制。

## C 运行时 (`include/`)

| 文件 | 内容 |
|------|------|
| `common.h` | 总入口，引入所有头文件 |
| `types.h` | 类型系统：`t_int`, `t_float`, `t_string`, `t_bool`, `t_var`, `t_array`, `t_object`, `t_callback`, `ClassVTable` |
| `val.h` | 便捷宏：`VAR_INT`, `VAR_FLOAT`, `VAR_BOOL`, `VAR_STRING`, `VAR_ARRAY`, `VAR_CALLBACK`, `VAR_NULL`, `STR_LIT` |
| `array.h` | PHP 风格数组：`tphp_fn_arr_create/push/set_str/set_int/get_str/get_int/index/count/free`，引用计数 + 嵌套安全释放 |
| `runtime.h` | 内部辅助：`tphp_rt_init`（UTF-8 控制台）、`tphp_rt_str_concat/dup/free`（内存安全）、`tphp_rt_str_eq/lt/gt`（字符串比较）、`tphp_rt_parse_int/float`（字符串解析）、`tphp_rt_object_free`（引用计数对象析构） |
| `builtin.h` | 公开内置：`tphp_fn_echo`、`tphp_fn_var_dump`、`tphp_fn_exit`、`tphp_fn_isset`、`tphp_fn_empty_*` |

### 命名前缀约定

| 前缀 | 用途 | 示例 |
|------|------|------|
| `tphp_class_` | 用户类 C 结构体 | `tphp_class_Main`, `tphp_class_Demo_Helper` |
| `tphp_fn_` | 所有函数（内置+用户） | `tphp_fn_echo`, `tphp_fn_demo_myFunc` |
| `tphp_rt_` | 运行时内部辅助 | `tphp_rt_str_concat`, `tphp_rt_object_free` |
| `tphp_enum_` | 枚举 C 结构体 | `tphp_enum_Color`, `tphp_enum_Complex_Enums_Status` |
| `TPHP_CONST_` | 常量宏 | `TPHP_CONST_VERSION` |

## 输出结构

```
执行目录/
├── main.exe        ← 编译产物
└── build/
    └── main.c      ← 中间 C 代码（每次编译前清空）
```

所有 C 标识符统一加 `tphp_` 前缀，避免与标准库冲突。

## CLI 选项

| 选项 | 说明 |
|------|------|
| `-o <output>` | 输出文件路径（默认执行目录下以入口文件名命名） |
| `-cc <compiler>` | 指定 C 编译器（默认内置 TCC） |
| `-h, --help` | 显示帮助 |

## 构建 TCC

```bash
# Windows (MSYS2 MinGW64)
cd tcc/win32 && cmd /c build-tcc.bat

# Linux / macOS
cd tcc && ./configure && make
```

## 许可证

MIT
