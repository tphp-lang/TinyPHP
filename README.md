# TinyPHP

> PHP → C 转译编译器，将 PHP 子集转为安全 C 代码，由 TCC 编译为原生产物（可执行文件、静态库、动态库等）。

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
```

编译后直接运行生成的产物即可（Windows 为 `.exe`，Linux/macOS 为无后缀可执行文件）。

## 编译流水线

```
PHP 源码 → Lexer → Token[] → Parser → AST → CodeGenerator → .c → TCC → .exe
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
// 类与方法（强类型）
class Main {
    public function main(): void {
        // 变量
        $a = 10;
        $b = "hello";
        $c = true;
        $d = 1.01;
        $e = null;

        // 运算
        $z = $a + 5;

        // 数组字面量
        $arr = [1, 2, 3];
        $nested = [10, "str", true, [4, 5]];

        // 输出
        echo "hello\n";

        // 调试（完整类型输出）
        var_dump($a);        // int(10)
        var_dump($arr);      // array(3) { [0]=> int(1) ... }
        var_dump($nested);   // 递归嵌套输出

        // 对象
        $d = new Demo();
        $d->hello();

        // 匿名函数 / 闭包
        $fn = function (): int { return 10; };
        var_dump($fn());     // int(10)

        // 调用
        $this->test();
        myFn();
    }
}
```

### 多文件 & 命名空间

```php
// main.php — 入口（必须有全局 class Main）
use Demo\Demo;
use function Demo\myFunc;

class Main { ... }

// demo.php — 命名空间
namespace Demo;
class Demo { ... }
function myFunc(): void { ... }

// other.php — 同名命名空间跨文件扩展
namespace Demo;
function myFunc2(): void { ... }
```

支持 `use Foo\Bar`、`use Foo\Bar as Alias`、`use Foo\{A, B, function F}` 组合导入。

### 不支持的

- `if` / `else` / `while` / `for` 等控制流
- 数组字面量之外的数组操作（如 `$a['key']`）
- 字符串插值、`use` 闭包捕获
- 游离代码（不在 class/function 内）
- 任何形式的 `include` / `require`

## C 运行时 (`include/`)

| 文件 | 内容 |
|------|------|
| `types.h` | 类型系统：`t_int`, `t_float`, `t_string`, `t_bool`, `t_var`, `t_array`, `t_object`, `t_callback` |
| `val.h` | 便捷宏：`VAR_INT`, `VAR_STRING`, `VAR_ARRAY`, `VAR_NULL`, `VAR_CALLBACK`, `STR_LIT` |
| `array.h` | PHP 风格数组：`tphp_arr_create`, `tphp_arr_push`, `tphp_arr_set_str/int`, `tphp_arr_get_str/int` |
| `function.h` | 运行时：`tphp_echo`, `tphp_var_dump`, `tphp_str_from_int/float/bool`, `tphp_object_free` |

## 输出结构

```
执行目录/
├── main.exe        ← 编译产物
└── build/
    └── main.c      ← 中间 C 代码（每次编译前清空）
```

所有 C 标识符统一加 `tphp_` 前缀，避免与标准库冲突。

## 构建 TCC

```bash
# Windows (MSYS2 MinGW64)
cd tcc/win32 && cmd /c build-tcc.bat

# Linux / macOS
cd tcc && ./configure && make
```

## 许可证

MIT
