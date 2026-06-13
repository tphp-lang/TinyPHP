# TinyPHP

> 一个零依赖的 PHP AOT 编译器 —— 将 PHP-like 源码直接编译为原生 x86-64 可执行文件

[![Language](https://img.shields.io/badge/language-PHP%208.1+-blue)](https://www.php.net/)
[![License](https://img.shields.io/badge/license-MIT-green)](LICENSE)

## 特性

- **零依赖**：无需 LLVM、GCC、链接器，编译器本身完全用 PHP 编写
- **AOT 编译**：源码直接生成 x86-64 机器码，输出原生 PE (Windows) 或 ELF (Linux) 可执行文件
- **强类型**：语法参考 PHP，但所有变量必须在声明时确定类型，编译期类型推断
- **双平台**：Windows (Win32 API / PE32+) 和 Linux (syscall / ELF64) 代码生成
- **多文件编译**：支持多文件 + 多命名空间 + `use` 导入 + 函数调用验证
- **OOP**：支持 class 定义、`new` 实例化、方法调用、`$this`、`__construct`/`__destruct`
- **枚举**：支持 `enum Name: int/string { case A = 1; }`，`Name::Case` 访问，`->value`
- **FFI**：`#extern` 声明 + `-lib` 参数直接调用 C 动态库（Windows）

## 快速开始

```bash
# 单文件编译
php tphp.php test/main/main.php
.\main.exe      # Windows
./main          # Linux

# 多文件编译
php tphp.php test/files/main.php test/files/demo.php test/files/name.php

# 目录编译（自动扫描所有 .php 文件，main.php 作为入口）
php tphp.php .

# FFI C 库调用
cd test/ext && php ../../tphp.php main.php -lib libhello.dll

# 指定目标平台
php tphp.php test/main/main.php --target=windows -o hello.exe
php tphp.php test/main/main.php --target=linux   -o hello
```

## 命令行

```
用法:
  php tphp.php <files...|dir> [options]

选项:
  --target=<os>    目标平台: linux 或 windows (默认: 当前系统)
  -o <file>        输出文件路径 (默认: 入口文件去掉 .php)
  -lib <path>      FFI 动态库路径 (#extern 时必须指定)
  -h, --help       显示帮助
```

## 语言参考

### 程序结构

```php
<?php

namespace Main;  // 入口命名空间必须是 Main

function main(): void  // 入口函数必须是 main()，返回 void
{
    // 你的代码...
}
```

### 多文件 & 命名空间

每个文件必须声明一个命名空间，入口文件必须是 `namespace Main`。通过 `use` 语句导入其他命名空间的函数和类：

```php
<?php

namespace Main;

use function Demo\myDemo;        // 导入函数
use function MyAdmin\Name\getAge; 
use Demo\MyDemo;                  // 导入类
use MyEmun\{IsMyEmun, MyInt, MyStr};  // 组导入

function main(): void
{
    myDemo();           // 调用 Demo 命名空间的函数
    getAge(10.5);       // 调用 MyAdmin\Name 命名空间的函数
    
    $obj = new MyDemo();  // 实例化 Demo 命名空间的类
    $obj->hello();        // 调用对象方法
}
```

### 枚举

支持 int 和 string 类型的枚举，`var_dump` 输出 `(enum) Name::Case` 格式：

```php
namespace MyEmun;

enum MyInt: int          // int 类型枚举
{
    case A = 1;
    case B = 2;
}

enum MyStr: string       // string 类型枚举
{
    case A = "a";
    case B = "b";
}

// 使用
namespace Main;
use MyEmun\{MyInt, MyStr};

var_dump(MyInt::A);      // (int) 1
var_dump(MyStr::A);      // (string) a
echo MyStr::A->value;    // a
```

**方法中使用枚举类型提示**：
```php
function isMyInt(MyInt $i): int
{
    var_dump($i);         // (enum) MyInt::A
    return $i->value;     // 1
}
```

### 变量与类型

所有变量 **必须初始化**，强类型推断，声明后类型不变：

| 类型        | 内存布局        | 说明                     | 示例                         |
| ----------- | --------------- | ------------------------ | ---------------------------- |
| `int`       | 8 字节 (i64)    | 64 位有符号整数          | `$a = 1;`                    |
| `float`     | 8 字节 (f64)    | IEEE 754 双精度浮点      | `$b = 1.0;`                  |
| `string`    | 16 字节 (ptr+len)| UTF-8 字符串            | `$c = 'hello world🚀';`      |
| `bool`      | 8 字节          | 布尔值                   | `$e = true;`                 |
| `null`      | 8 字节          | 空值                     | `$f = null;`                 |
| `callable`  | 8 字节 (fn ptr) | 匿名函数/闭包            | `$g = function(int $a, int $b): int { ... };` |
| `array`     | 16 + 元素空间   | 强类型元素数组            | `$arr = array("int", [1,2,3]);` |
| `object`    | 8 字节 (handle) | 类实例                   | `$obj = new MyClass();` |

```php
$d;  // ❌ 编译错误：变量不能未初始化
```

### 函数

支持用户自定义函数、多命名空间函数和 `use function` 导入：

```php
// 同一命名空间内直接调用
function add(int $a, int $b): int
{
    return $a + $b;
}

// 跨命名空间调用
namespace Main;
use function Demo\myDemo;

function main(): void
{
    myDemo();              // 调用 Demo\myDemo()
    $c = add(1, 2);       // 调用本命名空间的函数
    var_dump($c);          // (int) 3
}
```

### 类与对象

支持 class 定义、`public`/`private` 方法、构造/析构函数：

```php
class MyClass
{
    public function __construct()
    {
        var_dump("constructed");
    }

    public function hello(): void
    {
        $this->priv();     // $this 调用私有方法
        var_dump("hello");
    }

    private function priv(): void
    {
        var_dump("private method");
    }

    public function __destruct()
    {
        var_dump("destructed");
    }
}

// 使用
$obj = new MyClass();   // 输出: (string) constructed
$obj->hello();          // 输出: (string) private method
                        //      (string) hello
// 离开作用域时自动调用 __destruct()
                        // 输出: (string) destructed
```

### 数组

数组是强类型的，声明时指定元素类型，最大容量 64 个元素：

```php
$a = array("int", [1, 2, 3]);       // int 数组
var_dump($a);                        // (array(int)) [1, 2, 3]
var_dump(count($a));                 // (int) 3
var_dump($a[1]);                     // (int) 2

$a[] = 4;                            // 追加元素
unset($a[1]);                        // 删除元素

// callback 数组 — 存储闭包
$b = array("callback", [
    function (int $a, int $b): int { return $a + $b; },
    function (string $a, string $b): string { return $a . $b; },
]);
var_dump($b[0](1, 2));              // (int) 3
var_dump($b[1]("hello", "world"));  // (string) helloworld
```

### 闭包

闭包必须有强类型参数和返回值声明：

```php
$add = function (int $a, int $b): int {
    return $a + $b;
};
var_dump($add(1, 2));  // (int) 3
```

### 常量与输出

```php
const PI = 3.1415926;
const RED = "red";

function main(): void
{
    echo PI . "\n";        // 3.141593
    echo RED . "\n";       // red
}
```

### 类型转换

```php
// (int) — 截断整数
echo (int)3.999 . "\n";      // 3   浮点截断
echo (int)"123abc" . "\n";   // 123 前导数字
echo (int)"abc123" . "\n";   // 0   非数字开头
echo (int)true . "\n";        // 1
echo (int)false . "\n";       // 0
echo (int)null . "\n";        // 0

// (float) — 字符串编译期解析
echo (float)10 . "\n";          // 10
echo (float)"123.45abc" . "\n"; // 123.45  前导数字（编译期）
echo (float)"1.2e3" . "\n";     // 1200    科学计数法
echo (float)"abc123" . "\n";    // 0       非数字开头
echo (float)true . "\n";         // 1
echo (float)null . "\n";         // 0

// (string) — itoa/ftoa 栈缓冲
echo (string)123 . "\n";      // "123"
echo (string)123.45 . "\n";   // "123.45"
echo (string)true . "\n";     // "1"
echo (string)false . "\n";    // "0"
echo (string)null . "\n";     // ""

// $var 字符串插值
$a = 456;
$b = "$a";    // "456" — 等价于 (string)$a
```

### 控制流

```php
// if / else if / else
if ($a > 0) {
    var_dump($a);
} else if ($a == 0) {
    var_dump("zero");
} else {
    var_dump($b);
}

// while
while ($a < 10) {
    $a = $a + 1;
}

// for
for ($i = 0; $i < 10; $i++) {
    if ($i == 5) { break; }
}

// switch
switch ($cmd) {
    case 1: print("start\n"); break;
    default: print("unknown\n");
}
```

### 表达式

```php
// 算术
$a + $b;   $a - $b;   $a * $b;   $a / $b;   $a % $b;

// 比较
$a == $b;  $a === $b;  $a != $b;  $a !== $b;
$a < $b;   $a > $b;    $a <= $b;  $a >= $b;

// 自增/自减
$a++;    $a--;

// 字符串拼接
$c . $c . "-$c";
```

### FFI（C 动态库调用）

通过 `#extern` 声明 C 函数签名，`-lib` 参数加载 DLL：

```php
#extern void say_hello(const char* name);

function main(): void
{
    C->say_hello(CStr("tphp"));  // Hello tphp
}
```

```bash
php tphp.php main.php -lib .\libhello.dll
```

| 函数 | 方向 | 说明 |
|------|------|------|
| `CStr($s)` | tPHP→C | 字符串 → C 字符串 (heap + null) |
| `CInt($n)` | tPHP→C | 整数直通 |
| `CFloat($f)` | tPHP→C | 浮点直通 |
| `CBool($b)` | tPHP→C | 布尔 → 0/1 |
| `TInt($n)` | C→tPHP | 整数直通 |
| `TFloat($f)` | C→tPHP | 浮点直通 |
| `TBool($b)` | C→tPHP | 0/1 → 整数 |
| `TStr($p)` | C→tPHP | C 字符串 → tPHP 字符串 |

### 内置函数/关键字

| 关键字       | 说明                |
| ------------ | -------------------- |
| `var_dump()` | 输出值和类型信息     |
| `strlen()`   | 返回字符串字节长度   |
| `count()`    | 返回数组元素个数     |
| `echo`/`print`| 输出表达式          |
| `const`      | 定义命名空间级常量   |
| `(int)`      | 类型转换为整数       |
| `(float)`    | 类型转换为浮点（含编译期字符串解析） |
| `(string)`   | 类型转换为字符串（itoa/ftoa） |
| `enum`       | 定义枚举类型         |
| `#extern`    | 声明外部 C 函数      |

## 架构

```
源码 (.php × N)
  │
  ▼
Compiler  (多文件合并、命名空间/导入解析、函数调用验证)
  │
  ▼
Lexer (词法分析)     → Token 流
  │
  ▼
Parser (语法分析)    → AST (Pratt Parser)
  │
  ▼
CodeGenerator        → x86-64 机器码 (X64Builder)
  │           │
  ▼           ▼
PEWriter     ELFWriter
(Windows)    (Linux)
  │           │
  ▼           ▼
Win32 API    syscall
(kernel32)   (write, exit)
  │           │
  └─────┬─────┘
        ▼
  原生可执行文件 (.exe / ELF)
```

### 双平台对比

| 特性       | Windows                     | Linux                    |
| ---------- | --------------------------- | ------------------------ |
| 输出格式   | PE32+                       | ELF64                    |
| 输出方式   | Win32 API (WriteFile)       | syscall (write, exit)    |
| 调用约定   | Microsoft x64 (RCX,RDX,R8,R9)| System V (RDI,RSI,RDX,RCX) |
| 字符串存储 | .rdata 段 (RVA 0x3000+), RIP 相对寻址 | .text 段末尾内嵌         |
| 字符串拼接 | HeapAlloc + rep movsb       | 顺序输出                 |
| IAT 导入   | kernel32.dll (8 函数, 含 GetStdHandle/HapAlloc/GetProcessHeap) | 不需要 |

## 源码结构

```
tphp/
├── tphp.php                     # CLI 入口：参数解析、多文件扩展、编译调度
├── src/
│   ├── AST/                     # AST 节点定义（按类别拆分）
│   │   ├── Node.php             #   ASTNode 基类 + TphpType 枚举 + ExprNode/StmtNode
│   │   ├── Decl.php             #   声明节点：ProgramNode, FunctionDecl, ClassDecl, EnumDecl 等
│   │   ├── Stmt.php             #   语句节点：VarDecl, If/While/For/Switch/Break 等
│   │   └── Expr.php             #   表达式节点：字面量, BinaryOp, FuncCall, Closure 等
│   ├── CodeGen/                 # 代码生成 traits（按功能域拆分）
│   │   ├── BaseGenerator.php    #   共享状态：类型推断、变量管理、内存 helpers
│   │   ├── Linux/
│   │   │   ├── Helpers.php      #     itoa/ftoa/atoi 机器码例程
│   │   │   ├── Output.php       #     print/echo/var_dump (syscall)
│   │   │   ├── ControlFlow.php  #     if/while/for/switch/break
│   │   │   └── ArrayOps.php     #     数组/字符串/index 操作
│   │   └── Windows/
│   │       ├── Helpers.php      #     堆清理 + itoa/ftoa/atoi
│   │       ├── Output.php       #     print/echo/var_dump (Win32 API)
│   │       ├── ControlFlow.php  #     if/while/for/switch/break
│   │       ├── ArrayOps.php     #     数组/字符串/index 操作
│   │       └── FFI.php          #     LoadLibrary/GetProcAddress/C类型转换
│   ├── Token.php                # Token + TokenType（合并）
│   ├── TokenType.php            # TokenType 兼容 stub
│   ├── Lexer.php                # 词法分析器
│   ├── Parser.php               # 语法分析器 (Pratt parser)
│   ├── X64Builder.php           # x86-64 机器码构建器
│   ├── CodeGenerator.php        # 代码生成 (Linux / syscall) + traits
│   ├── CodeGeneratorWindows.php # 代码生成 (Windows / Win32 API) + traits
│   ├── Compiler.php             # 编译流程编排 + 多文件合并
│   ├── Validator.php            # 语义验证：main() 检查、函数调用验证
│   ├── ELFWriter.php            # ELF64 二进制写入器
│   └── PEWriter.php             # PE32+ 二进制写入器
└── test/
    ├── main/                    # 基础功能测试
    │   ├── main.php             # 最小示例
    │   └── function.php         # 多函数定义/调用
    ├── var/                     # 变量/数组/类型测试
    │   ├── var.php              # 综合功能演示
    │   ├── float.php            # 浮点数输出
    │   ├── array.php            # 数组功能演示
    │   ├── type_conversion.php  # 类型转换测试
    │   └── const.php            # 常量测试
    ├── files/                   # 多文件/命名空间/OOP 测试
    │   ├── main.php             # 入口（use/new/方法调用/跨命名空间）
    │   ├── demo.php             # Demo 命名空间 + class MyDemo
    │   ├── name.php             # MyAdmin\Name 命名空间函数
    │   ├── other2.php           # 共享命名空间
    │   └── other/other.php      # Other 命名空间跨文件调用
    ├── emun/                    # 枚举类型测试
    │   ├── main.php             # 入口（组导入/参数/var_dump 格式）
    │   └── my_emun.php          # MyEmun 命名空间 enum + class
    ├── heap/                    # 堆内存管理测试
    │   ├── concat_test.php      # 字符串拼接 + HeapFree
    │   ├── class_test_main.php  # 类方法中堆字符串
    │   └── class_test_demo.php  # class MyDemo
    ├── control_flow/            # 控制流测试
    │   ├── string.php           # if/for/switch/break/===
    │   └── int.php              # 整数控制流
    └── ext/                     # FFI 外部库测试
        ├── main.php             # FFI 调用示例
        ├── help.c               # DLL 源码
        └── libhello.def         # DLL 导出定义
```

## 路线图

- [x] **多函数定义与调用**：支持多个用户自定义函数 + 前向引用
- [x] **多文件编译**：多文件 + 多命名空间 + `use` 导入 + 函数调用验证
- [x] **Class / OOP**：class 定义、`new`/`$this`/`->`、构造/析构、public/private
- [x] **枚举**：`enum Name: int/string`、`Name::Case`、`->value`、`(enum)` var_dump
- [x] **控制流**：`for` / `switch` / `else if` / `break` / `===` / `!==` / `$i++`
- [x] **类型转换**：`(int)` / `(float)` / `(string)` 三类型，`(float)` 编译期字符串解析
- [x] **常量**：`const` 声明 + 引用
- [x] **echo/print**：两种输出方式
- [x] **字符串插值**：`"$var"` 表达式自动转换为字符串
- [x] **内存管理**：字符串拼接 HeapAlloc → 离开作用域自动 HeapFree
- [x] **C 动态库调用 (FFI)**：`#extern` + `C->` + `-lib`（LoadLibrary/GetProcAddress）
- [ ] **foreach** / **continue** / **do-while**
- [x] **浮点精度输出**：ftoa 支持小数部分
- [ ] **类属性/字段**：class 中的成员变量

## License

MIT
