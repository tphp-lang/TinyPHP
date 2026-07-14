# TinyPHP 语法参考

> 基于 PHP 8.5 `zend_language_parser.y`，标注 TinyPHP 当前支持程度。
> ✅ 已支持 | ⬜ 待实现 | ❌ AOT 不可行 | 🔧 TinyPHP 独有扩展

---

## 0. AOT 编译要求（必读）

TinyPHP 是 PHP→C AOT 编译器，不是解释器。以下规则**必须遵守**，否则编译失败。

### 入口文件

```php
// (<?php 开头可选)
// 1. 必须有全局命名空间（无 namespace）的 class Main
// 2. 必须有 public function main(): void 作为入口
// 3. 不支持任何游离代码（所有语句必须在类/函数内）

class Main
{
    // 构造函数 — 接收命令行参数（可选）
    public function __construct(int $argc, array $argv) {}

    // 入口函数 — 必须强类型声明
    public function main(): void
    {
        echo "hello world\n";
    }

    // 析构函数 — 退出前自动调用（可选）
    public function __destruct() {}
}
```

### 类型系统

**类型固定**：变量在首次赋值时确定类型，后续不可变。尝试切换类型（如 `$x` 先 `int` 后 `string`）会在 C 编译阶段报错。

**可选类型标记**：局部变量和全局/命名空间常量支持可选的前置类型标记，声明类型与字面量/推断类型不一致时编译期报错。类属性和类常量的类型标记为必填。

```php
// 局部变量（可选类型标记）
int $x = 42;           // 等价于 $x = 42;
string $s = "hello";
Point $p = new Point(1, 2);
callable $fn = function(int $a): int { return $a; };
C.FILE* $f = c_open("file.txt");   // phpc 互操作 C 类型

// 全局/命名空间常量（可选类型标记）
const int MAX = 100;   // 等价于 const MAX = 100;
const string NAME = "app";

// 类常量（类型必填，保持不变）
class C {
    const int TIMEOUT = 30;
    const string TITLE = "Hello";
}
```

| PHP 类型 | C 类型 | 说明 |
|----------|--------|------|
| `int` | `int64_t` | 64 位有符号整数 |
| `float` | `double` | IEEE 754 双精度 |
| `string` | `t_string` | SSO ≤23 字节内联，超限走池 |
| `bool` | `bool` | true/false |
| `array` | `t_array*` | 有序映射，int/string 键 |
| `callable` | `t_callback` | 闭包/C 函数指针 |
| `void` | `void` | 仅返回类型 |
| `never` | `void` | 永不返回（exit/throw） |
| `mixed` | `t_var` | 标签联合体，有运行时开销 |
| `Generator` | `tphp_class_Generator*` | 协程对象（minicoro 实现，stackless） |
| `Thread`/`Mutex`/`CondVar`/`WaitGroup` | `tphp_class_X*` | 多线程 COS 类（tinycthread 封装，Thread-Local 运行时） |
| 类类型 | `tphp_class_X*` 或 `tphp_na_Ns_tphp_class_X*` | COS 对象指针（命名空间类带 `tphp_na_` 前缀） |

> ⚠️ **`===` 和 `==` 等价**：类型固定意味着编译期已知类型，"同时类型不同"的情况不存在。

### AOT 限制

以下 PHP 特性依赖运行时解释器/动态符号表，**永久不支持**：

| 类别 | 不支持 | 原因 |
|------|--------|------|
| 动态代码 | `eval()` `assert()` `create_function()` | 无解释器 |
| 动态调用 | `$fn()` `$obj->$m()` `call_user_func()` | 编译时不知名 |
| 变量变量 | `$$var` `${expr}` `compact()` `extract()` | 无运行时符号表 |
| 运行时内省 | `Reflection*` `debug_backtrace()` `get_defined_vars()` | 无运行时符号表/栈帧 |
| 动态参数（定参函数） | `func_get_args()` `func_num_args()` `func_get_arg($i)` | 定参已固化为 C 形参，无统一参数容器 |
| 动态引入 | `include` `require` `include_once` `require_once` | 无运行时文件加载 |
| 魔术方法 | `__call` `__get` `__set` `__callStatic` | 无动态分发 |
| 可空 | `?int` | 不做（破坏类型固定优势） |
| 联合类型 | `int\|string` | ✅ 已支持（映射到 `mixed`/`t_var`）；`Type\|Exception` 返回类型为 TinyPHP 扩展（C 仅生成 `\|` 前类型，`\|Exception` 为文档提示） |

---

## 1. 顶层声明（Top-level）

```
program:
    program statement_top
  | %empty

statement_top:
    namespace_decl
  | use_decl
  | const_decl
  | function_decl              ✅
  | class_decl                 ✅
  | enum_decl                  ✅
  | #include 指令               ✅ TinyPHP 扩展
  | #flag 指令                  ✅ TinyPHP 扩展
  | #callback 指令              ✅ TinyPHP 扩展
  | #import 指令                ✅ TinyPHP 扩展
  | interface_decl             ✅
  | trait_decl                 ✅
  | abstract_class_decl        ✅
```

---

## 2. 命名空间 / Use

```
namespace_decl:
    'namespace' name ';'                    ✅
  | 'namespace' name '{' statement_top* '}' ✅ (多文件模式不支持)

name:
    IDENTIFIER                              ✅
  | name '\' IDENTIFIER                    ✅

use_decl:
    'use' name ';'                          ✅ (类导入)
  | 'use' 'function' name ';'              ✅
  | 'use' 'function' name 'as' IDENTIFIER ';'  ✅
  | 'use' name 'as' IDENTIFIER ';'         ✅
  | 'use' group_use                        ✅ (use A\{B, C})
  | 'use' 'function' name '\' '{' fn_use (',' fn_use)* ','? ';'  ✅ (use function A\{f1, f2})

fn_use:
    IDENTIFIER                              ✅
  | IDENTIFIER 'as' IDENTIFIER              ✅
```

---

## 3. 类声明

```
class_decl:
    modifier* 'class' IDENTIFIER extends? implements? '{' member* '}'   ✅ 部分

modifier:
    'abstract'      ✅
  | 'final'         ✅ (仅修饰符，无运行时检查)
  | 'readonly'      ✅ (readonly class + readonly 属性，编译期检查，无运行时开销)

extends:
    'extends' name   ✅

implements:
    'implements' name (',' name)*   ✅

member:
    property_decl         ✅
  | method_decl           ✅
  | const_decl            ✅
  | 'use' trait_name      ✅
  | enum_case             ✅ (enum only)
```

### 3.1 属性

```
property_decl:
    visibility 'readonly'? 'static'? type IDENTIFIER ';'            ✅ (type required)
  | visibility 'readonly'? 'static'? type IDENTIFIER '=' expr ';'  ✅ (type required; readonly 禁止默认值)
  | visibility type IDENTIFIER '{' hook+ '}'           ✅ (Property Hook, PHP 8.4)

property_hook:
    'get' '=>' expr ';'        ✅ (短形式 get)
  | 'get' '{' stmt* '}'        ✅ (块形式 get)
  | 'set' '=>' expr ';'        ✅ (短形式 set, $value 为新值)
  | 'set' '{' stmt* '}'        ✅ (块形式 set, $value 为新值)

> 属性和构造器属性提升**必须**写类型声明，`public $x` 会被拒绝。
> **Property Hook**（PHP 8.4）：`public string $name { get => strtoupper($this->name); set => strtolower($value); }`
> - hook 体内 `$this->prop` 直接访问 backing field（避免递归）
> - 短形式 `set => expr;` 中 `$value` 代表赋入的新值，`expr` 计算结果存入 backing field
> - 块形式 `set { stmts }` 中需自行执行 `$this->prop = $value;`
> - 编译为 `static type cn_get_prop(cn* self)` / `static void cn_set_prop(cn* self, type value)` 方法
> - 属性访问 `$obj->prop` 和赋值 `$obj->prop = val` 自动改写为 getter/setter 调用
> - 支持继承：子类访问父类的 hooked 属性时，调用父类的 getter/setter
> ⚠️ **`static` 属性**：语法上接受 `public static int $x = 0;`，但 `static` 标志当前会丢失（编译为实例属性）。仅内置类（Thread/Parallel/Enum）支持真正的静态调用。
> ✅ **`readonly` 属性**：已实现。`readonly` 可修饰单个属性或整个类（`readonly class` 时所有属性自动 readonly）。readonly 属性只能在声明它的类的 `__construct` 内赋值一次，编译期静态检查，无运行时开销。不支持 `static readonly`（PHP 8.2 禁止）。readonly 属性不能有默认值。构造器属性提升支持 `public readonly int $x` 语法。

visibility:
    'public'    ✅
  | 'private'   ✅
  | 'protected' ❌ 不做（仅 public/private）

> ⚠️ **方法修饰符**：
> - `abstract` 方法修饰符：语法上接受（解析时跳过），但**不强制**子类实现（无运行时/编译期检查）
> - `final` 方法修饰符：**不支持**（Parser 不消费 `final` 关键字，写 `final public function` 会报语法错误）；`final` 仅支持类级别
> - `protected` 可见性：**不支持**，仅 `public`/`private`

type:
    'int'       ✅ → int64_t
  | 'float'     ✅ → double
  | 'string'    ✅ → t_string
  | 'bool'      ✅ → bool
  | 'array'     ✅ → t_array*
  | 'void'      ✅
  | 'never'     ✅
  | 'mixed'     ✅ → t_var
  | 'callable'  ✅ → t_callback
  | 'self'      ✅ (返回类型/参数类型，解析为当前类 CName)
  | name        ✅ (类/枚举类型)
  | 'true'      ⬜ (字面量类型，PHP 8.2+，未实现)
  | 'false'     ⬜ (字面量类型，PHP 8.2+，未实现)
  | 'null'      ⬜ (字面量类型，PHP 8.2+，未实现)
  | 'static'    ⬜ (返回类型，PHP 8.0+，未实现；self 已支持)
  | '?' type    ❌ 不做（AOT 哲学冲突，鼓励 t_var=丢性能）
  | type '|' ExceptionSubclass   ✅ (Type\|Exception 语法，见下方说明)
  | type '|' type   ❌ 不做（普通联合类型映射 t_var，仅返回类型支持 Type\|Exception）
  | '(' type (',' type)+ ')'   ⬜ (intersection types)
  | type '&' type   ⬜ (DNF 类型交集，PHP 8.2+，未实现)
```

> 🔧 **`Type|Exception` 返回类型语法**：TinyPHP 扩展语法。当函数/方法体包含 `throw` 或 `error()` 时，**必须**在返回类型中声明 `|Exception`（或任意 Exception 子类，如 `|RuntimeException`）。此为纯文档提示——C 代码仅生成 `|` 前的类型（如 `int|Exception` → C `int64_t`），`|Exception` 部分在编译期丢弃，零运行时开销。编译器通过 `isExceptionSubclass()` 沿父链检查，确保 `|` 后的类型确实是 Exception 子类。普通联合类型（如 `int|string`）仍映射到 `t_var`。
>
> **语法检查规则**：函数体中直接出现 `throw` 语句/表达式或 `error()` 调用时，返回类型必须包含 `|Exception`（或子类），否则编译报错。此规则不追溯间接调用（调用其他可能 throw 的函数不需声明）。

### 3.2 方法

```
method_decl:
    visibility 'function' IDENTIFIER '(' params ')' return_type? body   ✅
  | visibility 'static' 'function' IDENTIFIER '(' params ')' return_type? body  ✅ (部分)
  | 'abstract'? visibility 'function' IDENTIFIER '(' params ')' return_type? body  ✅ (abstract 修饰符接受但不强制子类实现)

return_type:
    ':' type   ✅
  | %empty    ✅ (默认 void)

特殊方法:
    '__construct'  ✅ (支持属性提升: 'public' type '$var' 语法)
    '__destruct'   ✅ (禁止写返回类型)
```

### 3.3 类常量

```
class_const_decl:
    visibility 'const' type IDENTIFIER '=' expr ';'  ✅ (type required, no auto-deduction)

> 类常量必须写类型声明，`const X = 1` 会被拒绝。全局/命名空间常量类型**可选**（省略时自动推导）：
>
> ```
> global_const_decl:
>     'const' type? IDENTIFIER '=' expr ';'  ✅ (type 可选；省略时按字面量推导 int/float/string/bool)
> ```
```

---

## 4. 枚举

```
enum_decl:
    'enum' IDENTIFIER ':' backing_type '{' case* '}'   ✅

backing_type:
    'int'      ✅
  | 'string'   ✅

enum_case:
    'case' IDENTIFIER '=' expr ';'   ✅
```

---

## 5. 函数

```
function_decl:
    'function' IDENTIFIER '(' params ')' return_type? body   ✅

closure:
    'function' '(' params ')' use_vars? return_type? body    ✅
  | 'fn' '(' typed_params ')' ':' type '=>' expr             ✅ (箭头函数单表达式，强制参数+返回类型)
  | 'fn' '(' typed_params ')' ':' type '=>' '{' stmts '}'    ✅ (箭头函数块体，须含 return；void 类型除外)

use_vars:
    'use' '(' var_list ')'   ✅
```

---

## 6. 参数

```
params:
    param (',' param)* ','?   ✅ (尾部逗号支持)

param:
    type IDENTIFIER '=' expr  ✅ (默认值，必须在参数列表末尾)
  | type '&' IDENTIFIER        ✅ (引用传参，支持所有类型)
  | type IDENTIFIER            ✅
  | IDENTIFIER                 ✅ (无类型，箭头函数)
  | 'public' type '$' IDENTIFIER   ✅ (构造器属性提升)
  | 'private' type '$' IDENTIFIER  ✅ (构造器属性提升)
```

> 默认值参数规则：有默认值的参数必须放在参数列表末尾，与 PHP 原生一致。
> 编译器会为每个默认值参数生成重载函数，调用时自动选择正确版本。
> 默认值支持任意常量表达式（算术、位运算、字符串拼接、括号等），如 `= 1 + 2`、`= "a" . "b"`、`= 0xFF | 0x10`。
> 支持类型：`int`、`float`、`string`、`bool`、`array`。不支持 `callable` 类型作为默认值（PHP 8.4+ 弃用隐式 nullable，TinyPHP 不支持 `?callable`）。

---

## 7. 语句

```
statement:
    expr ';'                   ✅
  | echo_stmt                  ✅
  | if_stmt                    ✅
  | while_stmt                 ✅
  | do_while_stmt              ✅
  | for_stmt                   ✅
  | foreach_stmt               ✅
  | switch_stmt                ✅
  | match_stmt                 ✅ (多条件支持)
  | return_stmt                ✅
  | break_stmt                 ✅
  | continue_stmt              ✅
  | goto_stmt                  ✅
  | try_stmt                   ✅ (COS setjmp/longjmp)
  | throw_stmt                 ✅ (Exception 类)
  | defer_stmt                 ✅ (Zig 风格作用域清理，编译期展开)
  | assign_stmt                ✅ (支持可选类型标记: `type? '$' IDENTIFIER '=' expr ';'`; 支持链式 `$a = $b = 1`)
  | array_push_stmt            🔧 ($a[] = expr — TinyPHP 内联语法糖)
  | compound_assign            ✅ (+= -= *= /= .=)
  | list_destructure           ✅ (含键名 "key"=>$var)
  | unset_stmt                 ✅
  | block                      ✅
  | 'static' type? '$' IDENTIFIER '=' expr? ';'  ✅ (静态局部变量，类型/初始值可选)
  | 'const' type? IDENTIFIER '=' expr ';'        ✅ (函数内常量，PHP 8.3+，类型可选)
```

### 7.1 具体语法

```
echo_stmt:
    'echo' expr (',' expr)* ';'   ✅

if_stmt:
    'if' '(' expr ')' body elseif* else?   ✅

while_stmt:
    'while' '(' expr ')' body   ✅

do_while_stmt:
    'do' body 'while' '(' expr ')' ';'   ✅

for_stmt:
    'for' '(' for_exprs ';' for_exprs ';' for_exprs ')' body   ✅

foreach_stmt:
    'foreach' '(' expr 'as' value ')' body                            ✅
  | 'foreach' '(' expr 'as' key '=>' value ')' body                  ✅
  | 'foreach' '(' expr 'as' '&' value ')' body                       ✅

switch_stmt:
    'switch' '(' expr ')' '{' case* default? '}'   ✅

> **fall-through 语义**：所有 switch（int/bool/string）均遵循 C switch 的穿透语义。
> case 体未以 `break` 结尾时，执行流穿透到下一个 case。
> 字符串 switch 通过 `if-goto` 标签链实现（C 原生 switch 不支持字符串），保留与 int/bool switch 一致的穿透行为。

match_stmt:
    'match' '(' expr ')' '{' arm (',' arm)* ','? '}'   ✅
arm:
    expr (',' expr)* '=>' expr   ✅ (多条件)

return_stmt:
    'return' expr? ';'   ✅

break_stmt:
    'break' INT_LIT? ';'   ✅

continue_stmt:
    'continue' INT_LIT? ';'   ✅

goto_stmt:
    'goto' IDENTIFIER ';'   ✅
    IDENTIFIER ':'           ✅ (标签)

try_stmt:
    'try' '{' statement* '}' catch* finally?   ✅

catch:
    'catch' '(' type? '$' IDENTIFIER ')' '{' statement* '}'   ✅

throw_stmt:
    'throw' expr ';'   ✅

defer_stmt:
    'defer' expr ';'                ✅ (单表达式清理: defer C->free($buf);)
  | 'defer' echo_stmt               ✅ (echo 清理: defer echo "done\n";)
  | 'defer' '{' statement* '}'      ✅ (块清理)
```

> **defer 语句**（Zig 风格作用域清理）：注册清理代码，在函数退出时按 LIFO（后进先出）顺序执行。
> - **函数级作用域**：PHP 无块作用域，defer 为函数级（非块级）。所有 defer 在函数退出时统一执行。
> - **编译期展开**：defer 代码在编译期展开到所有 `return` 点和 fall-through 尾部，**零运行时开销**。
> - **return 路径**：先求值 return 表达式到临时变量 `__defer_ret`，执行 defer 清理，再返回临时变量（避免 use-after-free）。
> - **异常路径限制**：`try-catch` 的 `longjmp` 路径**不执行** defer（与 C 局部变量析构限制一致）。如需异常路径清理，请在 `finally` 块中手动处理。
> - **典型用途**：C 指针释放（`defer C->free($buf);`）、资源关闭（`defer C->fclose($fp);`）、调试输出（`defer echo "exit\n";`）。

> **catch 类型限制**：
> - `catch (Exception $e)` / `catch (MyException $e)` — ✅ 支持用户自定义 Exception 子类
> - `catch (\Throwable $e)` — ❌ 不支持（`Throwable` 是 PHP 接口，TinyPHP 无接口 vtable；用 `catch (Exception $e)` 替代，所有异常都是 Exception 子类）
> - 无类型 `catch ($e)` — ✅ 兜底捕获（字符串消息，非对象异常）
>
> **`error()` 函数**：等价于 `throw new Exception($msg)` 的简写。调用 `error($msg)` 时，编译器生成 `tp_throw(STR_PTR_V($msg))`，抛出可被 try-catch 捕获的异常；未被 catch 时回退到 `exit(1)` + 输出 Fatal error 信息。函数体包含 `error()` 调用时，返回类型必须声明 `|Exception`（同 `throw` 规则）。

---

## 8. 表达式

### 8.1 运算符优先级（从低到高）

```
expr:
    yield_expr       ✅
  | pipe_expr        ✅
  | ternary_expr     ✅
  | logical_or       ✅
  | logical_and      ✅
  | bitwise_or       ✅
  | bitwise_xor      ✅
  | bitwise_and      ✅
  | equality         ✅
  | comparison       ✅
  | spaceship        ✅
  | bitwise_shift    ✅
  | additive         ✅
  | multiplicative   ✅
  | power            ✅
  | instanceof       ✅
  | prefix           ✅
  | postfix          ✅
  | primary          ✅

ternary_expr:
    expr '?' expr ':' expr   ✅
  | expr '?:' expr           ✅
  | coalesce_expr            ✅

pipe_expr:
    ternary_expr                  ✅
  | pipe_expr '|>' ternary_expr   ✅ (左结合，优先级低于 ?:)

> Pipe Operator `|>`：纯语法糖，`$x |> f(...)` 等价于 `f($x)`。
> - 右操作数为函数调用时，`...` 占位符决定参数插入位置：`$x |> f(..., $arg)` → `f($x, $arg)`
> - 右操作数为函数调用且无 `...` 时，左操作数追加为末尾参数：`$x |> f($arg)` → `f($arg, $x)`
> - 右操作数为可调用变量时，等价于闭包调用：`$x |> $callback` → `$callback($x)`
> - 支持链式：`$x |> f(...) |> g(...)` → `g(f($x))`

yield_expr:
    'yield' expr                       ✅ (yield value)
  | 'yield' expr '=>' expr             ✅ (yield key => value)
  | 'yield'                            ✅ (yield null)
  | 'yield' 'from' expr                ✅ (yield from 委托 Generator 或 array)

> Generator 函数返回 `Generator` 类型，基于 minicoro 库（stackless 协程）。
> 支持 `current()` / `send()` / `next()` / `getReturn()` 等方法。

coalesce_expr:
    expr '??' expr   ✅

logical_or:      expr '||' expr     ✅
logical_and:     expr '&&' expr     ✅
bitwise_or:      expr '|' expr      ✅
bitwise_xor:     expr '^' expr      ✅
bitwise_and:     expr '&' expr      ✅

equality:
    expr '==' expr    ✅
  | expr '!=' expr    ✅
  | expr '===' expr   ✅
  | expr '!==' expr   ✅

comparison:
    expr '<' expr   ✅
  | expr '>' expr   ✅
  | expr '<=' expr  ✅
  | expr '>=' expr  ✅

spaceship:
    expr '<=>' expr   ✅

bitwise_shift:
    expr '<<' expr   ✅
  | expr '>>' expr   ✅

additive:
    expr '+' expr  ✅
  | expr '-' expr  ✅
  | expr '.' expr  ✅ (字符串拼接)

multiplicative:
    expr '*' expr   ✅
  | expr '/' expr   ✅
  | expr '%' expr   ✅

power:
    expr '**' expr   ✅

prefix:
    '!' expr      ✅
  | '~' expr      ✅
  | '+' expr      ✅
  | '-' expr      ✅
  | '++' var      ✅
  | '--' var      ✅

postfix:
    var '++'                    ✅
  | var '--'                    ✅
  | expr '->' IDENTIFIER        ✅
  | expr '?->' IDENTIFIER       ✅ (nullsafe)
  | expr '->' IDENTIFIER '(' args ')'     ✅
  | expr '?->' IDENTIFIER '(' args ')'    ✅ (nullsafe call)
  | expr '::' IDENTIFIER                  ✅ (self:: / Class::)
  | expr '::' IDENTIFIER '(' args ')'     ✅ (self::method / Class::method)
  | expr '[' expr ']'          ✅
  | expr '(' args ')'          ✅
```

### 8.2 基础表达式

```
primary:
    INT_LIT          ✅ → t_int(int64_t)
       └─ 支持十进制 / 0x十六进制 / 0b二进制 / 0o八进制 / 下划线分隔 1_000_000
  | FLOAT_LIT        ✅ → t_float(double)
       └─ 支持小数 / 科学计数法 1e10 1.5E-3 / 下划线分隔 3_14.15_92
  | STRING_LIT       ✅ → t_string
       └─ 支持单引号 / 双引号(插值) / heredoc <<<EOT ... EOT; / nowdoc <<<'EOT' ... EOT;
  | TRUE_KW          ✅ → true
  | FALSE_KW         ✅ → false
  | NULL_KW          ✅ → null
  | MAGIC_LINE       ✅ (__LINE__)
  | MAGIC_FILE       ✅ (__FILE__)
  | MAGIC_DIR        ✅ (__DIR__)
  | DIR_SEP          ✅ (DIRECTORY_SEPARATOR)
  | MAGIC_CLASS      ✅ (__CLASS__)
  | MAGIC_METHOD     ✅ (__METHOD__)
  | MAGIC_FUNCTION   ✅ (__FUNCTION__)
  | MAGIC_NAMESPACE  ✅ (__NAMESPACE__)
  | IDENTIFIER       ✅ (变量 $var 或常量 CONST)
  | '(' expr ')'     ✅
  | '(' cast_type ')' expr          ✅ ((int)/(float)/(string)/(bool)/(C.XXX))
  | 'array' '(' args ')'            ✅
  | '[' args ']'                    ✅ (支持尾逗号；支持 spread `...$arr`)
  | 'new' name '(' args ')'         ✅
  | 'function' '(' params ')' body  ✅ (闭包)
  | 'fn' '(' typed_params ')' ':' type '=>' expr        ✅ (箭头函数单表达式，强制参数+返回类型)
  | 'fn' '(' typed_params ')' ':' type '=>' '{' stmts '}' ✅ 🔧 TinyPHP 扩展（块体箭头函数，须含 return；void 类型除外）
  | 'match' '(' expr ')' '{' arm* '}' ✅
  | 'list' '(' list_vars ')' '=' expr   ✅
  | '[' list_vars ']' '=' expr           ✅
  | 'throw' expr                         ✅ (throw 表达式，PHP 8.0+，语句表达式展开)
  | '...' expr                           ✅ (数组展开 spread，PHP 7.4+，见下方说明)
  | name '(' '...' ')'                   ⬜ (first-class callable strlen(...)，PHP 8.1+，未实现)
```

> 🔧 **TinyPHP 箭头函数扩展**：与 PHP 原生 `fn() => expr` 仅支持单表达式不同，TinyPHP 额外支持 `fn(): type => { stmts }` 块体形式，须以 `return` 结尾（`void` 类型除外）。这便于在箭头函数中编写多条语句。

> 🔧 **数组展开 spread `[...$arr1, ...$arr2]`**（PHP 7.4+）：数组字面量中 `...$arr` 前缀将源数组元素逐个展开。语义遵循 PHP：int 键重新索引（append 到末尾），string 键保留并覆盖（后写胜出）。编译期调用运行时辅助函数 `tphp_fn_arr_spread(dst, src)` 逐元素复制。支持与字面量混合 `[1, ...$arr, 2]`、嵌套 `[[...$a], [...$b]]`、作为函数实参 `var_dump([...$arr])`。`...` 由词法分析器输出为三个 `DOT` token，解析器通过 `peek(1)`/`peek(2)` 识别。

---

## 9. 运算符映射表

| PHP 运算符 | Token | C 输出 | 状态 |
|---|---|---|---|
| `+ - * / %` | PLUS/MINUS/STAR/SLASH/PERCENT | `+ - * / %` | ✅ |
| `**` | STAR_STAR | `tphp_rt_pow_int/float` | ✅ |
| `.` | DOT | `tphp_rt_str_concat` | ✅ |
| `=` | EQUALS | `=` | ✅ |
| `+= -= *= /= .=` | PLUS_EQ etc | `+= -= *= /=` (str concat for `.=`) | ✅ |
| `== !=` | EQ/NE | `== !=` (str: `tphp_rt_str_eq/ne`) | ✅ |
| `=== !==` | IDENTICAL/NOT_IDENTICAL | `== !=` (AOT 类型固定) | ✅ |
| `< > <= >=` | LT/GT/LE/GE | `< > <= >=` | ✅ |
| `<=>` | SPACESHIP | `((x)>(y)?1:((x)<(y)?-1:0))` | ✅ |
| `&& \|\| !` | AND/OR/NOT | `&& \|\| !` | ✅ |
| `& \| ^ ~` | AMP/PIPE/CARET/TILDE | `& \| ^ ~` | ✅ |
| `<< >>` | SL/SR | `<< >>` | ✅ |
| `++ --` | INC/DEC | `++ --` | ✅ |
| `?:` | QUEST/COLON | `?:` | ✅ |
| `??` | COALESCE/QUEST_QUEST | `(x) ? (x) : (y)` | ✅ |
| `?->` | NULLSAFE_ARROW | `if (obj) obj->m()` 块 | ✅ |
| `(int)` | INT_CAST | `(t_int)` | ✅ |
| `(float)` | FLOAT_CAST | `(t_float)` | ✅ |
| `(string)` | STRING_CAST | 按类型转换 | ✅ |
| `(bool)` | BOOL_CAST | 按假值规则 | ✅ |

---

## 10. Magical / Special Tokens

```
__LINE__              ✅
__FILE__              ✅
__DIR__               ✅
DIRECTORY_SEPARATOR   ✅
$this                 ✅
self                  ✅
$GLOBALS              ❌
__CLASS__             ✅
__METHOD__            ✅
__FUNCTION__          ✅
__NAMESPACE__         ✅
__TRAIT__             ❌
```

---

## 11. 预处理器指令（TinyPHP 扩展）

```
#directive:
    '#include' '"' file '"'   ✅ (生成 #include "file" 到 C 代码)
  | '#include' '<' file '>'   ✅ (系统头文件)
  | '#flag' compiler? platform? flags   ✅ (编译器/平台过滤标志)
  | '#callback' type IDENTIFIER '(' params ')'   ✅ (声明 C 回调签名)
  | '#import' name   ✅ (按需引入 ext/name/src/*.php + *.c)
  | '#cstruct' IDENTIFIER '{' fields '}'   ✅ (声明 C 结构体字段布局,支持 $p->field 原生访问)
```

---

## 12. C 互操作扩展（TinyPHP 扩展）

```
c_call:
    'C->' IDENTIFIER '(' args ')'   ✅ (直接 C 函数调用)
  | 'C->' IDENTIFIER                ✅ (直接 C 常量/枚举/宏访问，无括号)

c_type_annotation:                  ✅ (借鉴 vlang C.Type 命名空间设计)
    'C.' IDENTIFIER ['*'+]          → C 类型注解（函数参数/返回值）
  // 值类型直译: C.int → int, C.double → double, C.char → char, C.bool → bool
  // 定宽整数: C.int32 → int32_t, C.uint64 → uint64_t 等
  // 指针用 * 后缀: C.void* → void*, C.char* → char*, C.int* → int*
  // 结构体: C.Point → Point（值类型）, C.Point* → Point*（指针需显式 *）

c_cast:                             ✅ (C 类型 cast,参考 vlang 直接类型映射)
    '(' 'C.' IDENTIFIER ['*'+] ')' expr    → C 类型转换
  // 值类型: (C.int)65, (C.double)3.14, (C.char)66, (C.bool)1
  // 定宽整数: (C.int32)1000, (C.int64)5e9, (C.uint32)4e9, (C.uint64)8e9
  // 指针类型: (C.void*)$ptr, (C.char*)$raw, (C.Point*)$p

c_struct:                           ✅ (#cstruct 声明,原生字段访问)
    '#cstruct' IDENTIFIER '{' fields '}'
  // #cstruct Point { C.double x; C.double y; }
  // $p->x → ((Point*)$p)->x (编译期展开,无需 C getter/setter)

c_type_bridge:
    'c_int(' expr ')'       ✅ → int32_t (宏,零开销)
  | 'c_float(' expr ')'     ✅ → double (宏,零开销)
  | 'c_str(' expr ')'       ✅ → const char* (static inline,STR_PTR 单次求值)
  | 'c_void_ptr(' expr ')'  ✅ → void* 透传 (宏,显式类型标记)
  | 'php_int(' expr ')'     ✅ → t_int (宏,零开销)
  | 'php_float(' expr ')'   ✅ → t_float (宏,零开销)
  | 'php_str(' expr ')'     ✅ → t_string (深拷贝,参数 const char*;static inline)
  | 'php_str_ptr(' expr ')' ✅ → t_string (接受 void*,宏展开为 php_str)
  | 'php_str_clone(' expr ')' ✅ → t_string (深拷贝,宏展开为 php_str)

phpc_array:
    'phpc_arr_int(' expr ')'       ✅ → int32_t* (malloc，类型不匹配抛 tp_throw)
  | 'phpc_arr_dbl(' expr ')'       ✅ → double* (malloc，类型不匹配抛 tp_throw)
  | 'phpc_arr_str(' expr ')'       ✅ → char** (malloc，类型不匹配抛 tp_throw)
  | 'phpc_new_arr_int(' p,p ')'    ✅ → t_array*
  | 'phpc_new_arr_dbl(' p,p ')'    ✅ → t_array*
  | 'phpc_new_arr_str(' p,p ')'    ✅ → t_array*
  | 'phpc_new_arr()'               ✅ → t_array*

phpc_object:
    'phpc_obj(' expr ')'                       ✅ → void* (借用语义)
  | 'phpc_new_obj(' ptr ',' cls ')'            ✅ → t_object* (接管语义)
  | 'phpc_unregister_obj(' expr ')'            ✅ → void (解除注册，防 double-free)
  | 'phpc_obj_steal(' expr ')'                 ✅ → void (标记分离，C 库可安全 free)

phpc_callback:
    'phpc_fn(' cb ')'              ✅ → void*
  | 'phpc_env(' cb ')'             ✅ → void*
  | 'phpc_fn_i32(' cb ')'          ✅ → int32_t(*)(int32_t, void*)
  | 'phpc_fn_i64(' cb ')'          ✅ → int64_t(*)(int64_t, void*)
  | 'phpc_fn_f64(' cb ')'          ✅ → double(*)(double, void*)
  | 'phpc_thunk(' name ',' cb ')'  ✅ → 按 #callback 签名生成 thunk
  | 'phpc_env_pin(' cb ')'         ✅ → void* (固定 env，异步回调安全)
  | 'phpc_env_unpin(' env ')'      ✅ → void (解除固定)

phpc_memory:
    'phpc_auto(' ptr ')'           ✅ → void* (通用 C 指针自动注册,程序结束/异常自动 free)
  | 'phpc_free(' ptr ')'           ✅ → free(ptr) + 先注销注册防 double-free + 自动置零变量防 UAF
  | 'phpc_free_str_arr(' p,p ')'   ✅ → 释放字符串数组 + 自动置零
  | 'phpc_assert_ptr(' p,name ')'  ✅ → 断言非 NULL，否则抛 tp_throw

phpc_ptr_bridge:
    'phpc_ptr_to_int(' ptr ')'     ✅ → t_int (void* → t_int,用 intptr_t 保证可移植性)
  | 'phpc_int_to_ptr(' v ')'       ✅ → void* (t_int → void*,函数内部转回调用 C 库)
```

---

## 13. 与 PHP 8.5 的差异汇总

### ✅ 完全兼容

| 语法 | 备注 |
|------|------|
| `if/elseif/else` `while` `do-while` `for` `foreach` `switch` `match` | 全部控制流 |
| `break N/continue N/goto` 标签 | `break 2;` 跳出多层结构（loop label 栈 + goto 实现） |
| `class` `extends` `interface` `implements` `trait+use` `abstract class` `final class` `readonly` | COS struct 嵌套继承；`abstract`/`final` 仅类级别 |
| `enum` `enum case` | int/string backing |
| `try/catch(Exception $e)/finally` `throw` `error()` | COS setjmp/longjmp；`error()` 等价于 `throw new Exception($msg)`，可被 catch |
| `function` `closure` `fn =>` `fn => {...}` `use($x)` | 全部闭包形态（块体箭头函数为 TinyPHP 扩展） |
| `yield` `yield from` `Generator` | minicoro stackless 协程，支持 send/getReturn |
| 完整 15 级运算符优先级 | 含 `<=>` `??` `?:` `?->` `**` `&|^~` `<<>>` `\|>` |
| `(int)` `(float)` `(string)` `(bool)` 类型转换 | — |
| `namespace A\B` `use A\{B,C}` `use function A\{f1,f2}` `use A\{B, function f}` | 分组导入 |
| `list()/$a[] =` 解构 | 含键名 `"key"=>$v` |
| `int &$x` 引用传参 | 全类型支持（int/float/bool/string/array/对象） |
| `self::CONST` `Class::CONST` `self::method()` `parent::method()` `parent::__construct()` | `parent::` 通过 `_parent` 字段访问父类（COS 结构体继承） |
| `__construct(public $x)` 属性提升 | — |
| Property Hook `public string $x { get => ...; set => ...; }` | PHP 8.4，编译为 getter/setter 方法 |
| Pipe Operator `$x \|> f(...)` | PHP 8.4 RFC，纯语法糖 |
| `__destruct` | 作用域结束自动调用 |
| `__LINE__` `__FILE__` `__DIR__` `__CLASS__` `__METHOD__` `__FUNCTION__` `__NAMESPACE__` `DIRECTORY_SEPARATOR` | 编译期替换 |
| `instanceof` | 遍历类链 |
| `isset()` `empty()` | `isset()` 值类型编译期 `true`，指针运行时 NULL 检测；`empty()` 按类型分发（PHP falsy 语义） |
| 数字字面量 `0x1F` `0b1010` `0o777` `1_000_000` | 十六/二/八进制 + 下划线分隔 |
| 浮点字面量 `1e10` `1.5E-3` `3_14.15_92` | 科学计数法 + 下划线分隔 |
| `<<<EOT ... EOT;` heredoc / `<<<'EOT' ... EOT;` nowdoc | 含 `$var` / `{$var->prop}` 插值 |
| 函数调用尾逗号 `f(a, b,)` | 参数列表/match arm/use 分组均支持 |

### 🔧 TinyPHP 独有

| 语法 | 说明 |
|------|------|
| `Type\|Exception` 返回类型 | 函数含 throw/error() 时必须声明（如 `: int\|Exception`），纯文档提示，C 仅生成 `\|` 前类型，编译期检查 `|` 后为 Exception 子类 |
| `error($msg)` 函数 | `throw new Exception($msg)` 简写，生成 `tp_throw()`，可被 try-catch 捕获，未捕获时回退 exit(1) |
| `fn(): type => { stmts }` | 块体箭头函数（PHP 原生仅支持单表达式 `fn() => expr`），须以 `return` 结尾（void 除外） |
| `#include "file.h"` | 嵌入 C 头文件 |
| `#include <sys.h>` | 系统头文件 |
| `#flag [CC] [OS] flags` | 编译器/平台过滤链接标志 |
| `#callback type name(params)` | 声明 C 回调签名 |
| `#cstruct Name { C.type field; ... }` | 声明 C 结构体字段布局,`$p->field` 原生访问 |
| `#debug expected` | 测试预期输出（`--debug` 模式） |
| `#import name` | 按需引入扩展（自动加载 ext/name/src/） |
| `C->func(args)` | 直接 C 函数调用 |
| `C->CONST` | 直接 C 常量/枚举/宏访问（无括号时按 `t_int` 推断） |
| `C.Type` | C 类型注解（函数参数/返回值。值类型 `C.int`→`int`/`C.double`→`double`/`C.char`→`char`；定宽 `C.int32`→`int32_t`/`C.uint64`→`uint64_t`；指针 `C.void*`→`void*`/`C.Point*`→`Point*`，用 `*` 后缀） |
| `(C.XXX) expr` | C 类型 cast（值类型 `(C.int)`/`(C.double)`/`(C.char)`/`(C.bool)`；定宽 `(C.int32)`/`(C.uint64)`；指针 `(C.void*)`/`(C.char*)`/`(C.Point*)`） |
| `c_int/c_float/c_str/c_void_ptr` | PHP → C 类型桥接(前 3 个宏零开销,c_str 保持 inline) |
| `php_int/php_float/php_str/php_str_ptr/php_str_clone` | C → PHP 类型桥接(前 2 个宏零开销,php_str 保持 inline) |
| `phpc_arr_*` `phpc_obj` `phpc_new_obj` `phpc_unregister_obj` `phpc_obj_steal` `phpc_fn_*` `phpc_thunk` `phpc_env_pin` `phpc_env_unpin` | 数组/对象/回调互操作 |
| `phpc_auto` `phpc_free` `phpc_free_str_arr` `phpc_assert_ptr` | C 内存自动管理/释放/安全断言 |
| `defer EXPR;` / `defer { ... }` | Zig 风格作用域清理：编译期展开到 return/fall-through 路径，LIFO 执行，零运行时开销 |
| C 指针泄漏编译期提醒 | `C.T*` transfer 指针未 defer/free 时输出 `[WARN]` 到 stderr（不阻断编译；识别 `*_free`/`*_destroy`/`*_release`/`*_close`/`*_delete` 等清理函数命名约定） |
| `phpc_ptr_to_int` `phpc_int_to_ptr` | 指针↔整数桥接(让 C 指针以 t_int 在 PHP 层流转) |
| `Thread`/`Mutex`/`CondVar`/`WaitGroup` | 多线程 OOP API（tinycthread 封装，Thread-Local 运行时无锁竞争） |
| `Parallel::for`/`Parallel::map` | 数据并行（连续分片，线程失败降级为内联执行） |
| `#[Attribute(p: type, ...)] const NAME = [];` | 注解类型声明（附着于全局/命名空间 const，详见 §14） |
| `#[NAME(arg1, arg2, ...)]` | 注解使用（仅位置参数，附着于 class/method/function，编译期收集） |
| `ROUTE[0]->data` / `->type` / `->name` | 注解 entry 属性访问（编译期展开为静态指针，零开销） |
| `ROUTE[0]->call(...$args)` | 注解 entry 调用方法/静态方法/函数（编译期展开为直接调用，零开销） |
| `ROUTE[0]->newInstance(...$args)` | 注解 entry 实例化类（编译期展开为 `new_tphp_class_X(args)`，零开销） |

### ❌ 不支持（AOT 物理不可行）

| 语法 | 原因 |
|------|------|
| `eval()` `assert($str)` `create_function()` | 无运行时解释器 |
| `$$var` `${expr}` | 编译时不知变量名（无运行时符号表） |
| `compact()` `extract()` `get_defined_vars()` | 依赖运行时符号表 |
| `$fn()` `$obj->$m()` `call_user_func()` | 编译时不知函数名 |
| `include` `require` `include_once` `require_once` | 无运行时文件加载 |
| `__call` `__get` `__set` `__callStatic` | 无动态分发 |
| `__toString` `__invoke` `__clone` `__debugInfo` `__sleep` `__wakeup` `__serialize` `__unserialize` `__isset` `__unset` `__set_state` | 均需运行时动态分发或序列化运行时支持 |
| 动态属性 `$obj->dynamicProp = 1` | 类布局编译期固定，无动态属性表（PHP 8.2 已弃用） |
| `Reflection*` 全系列 | 运行时内省 |
| `debug_backtrace()` `debug_print_backtrace()` | 运行时栈帧 |
| `func_get_args()` `func_num_args()` `func_get_arg($i)`（定参函数） | 参数已固化为 C 形参，无统一容器（可变参数函数 `...$args` 中可支持，见下方说明） |
| `ArrayAccess` 接口语义 | `offsetGet/offsetSet` 需运行时动态分发（`implements ArrayAccess` 仅记录，不生效） |
| `Iterator` / `IteratorAggregate` 接口语义 | `rewind/valid/next/current/key` 需运行时动态分发（foreach 仅支持 array 和 Generator） |
| `Stringable` 接口语义 | `__toString` 需运行时动态分发 |
| `Closure::bind` `->bindTo` `Closure::call` `Closure::fromCallable` | 闭包作用域编译期通过 `use` 固定，无法运行时重绑定 |
| `__COMPILER_HALT_OFFSET__` | 无运行时文件加载 |
| `$GLOBALS` 超全局 | 无运行时全局符号表 |

### ❌ 不做（权衡）

| 语法 | 原因 |
|------|------|
| `?int` `int\|string` | 破坏类型固定优势 |
| `...$args` 可变参数 | 需动态栈构造 |
| 命名参数 | AOT 无意义 |
| `#[Route(path: "/x")]` 命名参数注解 | 与全局命名参数禁用一致；注解仅支持位置参数 `#[Route("/x")]` |
| 运行时反射 `Reflection*` | AOT 无运行时元数据，注解仅供编译期消费 |
| first-class callable `strlen(...)` | AOT 下函数编译期已知，可用闭包 `function() use() {}` 或直接函数调用替代，额外语法无收益 |
| `true`/`false`/`null` 字面量类型 | AOT 零性能收益；编译期验证成本高收益低；与现有 `?T` 不做的哲学冲突；纯文档性特性 |
| `static` 返回类型 | AOT 无后期绑定，语义与 `self` 完全相同，多此一举 |
| DNF/intersection 类型 `A&B` `(A&B)\|C` | 实现复杂（需接口 vtable 或 `t_var` 降级），收益有限，破坏类型固定优势 |
| `??=` 空合并赋值 | 可用 `$a = $a ?? $b` 展开；AOT 下 `??` 仅对指针有意义 |
| `clone` 关键字 | 需 `__clone` 动态分发；COS 对象无通用深拷贝 |
| `declare(strict_types=1)` | TinyPHP 已是强类型 AOT，`declare` 无意义 |
| `\u{XXXX}` Unicode 转义 | C 不支持 `\u{}` 语法；可用 `\xXX` 或直接嵌入 UTF-8 字符 |
| 返回引用 `function &f()` | AOT 类型固定下无意义；COS 对象按指针传递已是引用 |

> ⚠️ 定参 vs 可变参数的 `func_get_args()`：
> - **定参函数**（`function f(int $a, string $b)`）：参数编译为独立 C 形参 `t_int a, t_string b`，没有统一容器 → `func_get_args()` **不可行**
> - **可变参数函数**（`function f(...$args)`）：可编译为 `t_array* args` 形参 → `func_get_args()` 直接返回 `args` 即可 → **可行**

---

## 14. 注解系统（TinyPHP 扩展）

TinyPHP 注解采用**纯编译期消费**策略（方向 A）：注解在编译期被消费生成代码或进行检查，运行时零开销，明确**不支持运行时反射**（方向 B）。

### 14.1 设计哲学

| 维度 | 选择 | 理由 |
|------|------|------|
| 消费时机 | 编译期 | AOT 零开销，注解信息编译期已知 |
| 运行时元数据 | 无 | 避免二进制体积膨胀和运行时查找开销 |
| 参数形式 | 仅位置参数 | 与全局命名参数禁用一致；命名参数在 AOT 下无意义 |
| 索引形式 | 仅静态索引 `ROUTE[0]` | 编译期展开为零开销直接调用；动态索引 `ROUTE[$i]` 不支持 |
| 跨命名空间 | 与普通常量作用域一致 | 短名匹配（同命名空间 + 全局回退）；`use const` 导入解析为 FQ 名精确匹配 |

### 14.2 语法

```
// 注解类型声明：附着于全局/命名空间 const
annotation_decl:
    '#[Attribute' '(' param_list ')' ']' 'const' IDENTIFIER '=' '[' ']' ';'
  // param_list: name ':' type ['=' default_expr] (',' ...)*
  //   type: 'int' | 'float' | 'string' | 'bool' | 'array' | ...
  //   const 必须为空数组字面量 []

// 注解使用：附着于 class / method / function（可连续多个 #[...]）
annotation_use:
    '#[' IDENTIFIER ['(' arg_list ')'] ']'
  // IDENTIFIER 可含命名空间限定（Ns\Name）
  // arg_list: expr (',' expr)*  — 仅位置参数，命名参数报语法错误
```

### 14.3 示例

```php
<?php // @multi @with child/child.php

// 1. 声明注解类型
#[Attribute(path: string)]
const ROUTE = [];

class Main {
    // 2. 使用注解（仅位置参数）
    #[ROUTE("/test")]
    public function test(int $id): void {
        echo "test->$id\n";
    }

    #[ROUTE("/test2")]
    public function test2(): void { ... }

    public function main(): void {
        // 3. 访问注解 entry 属性（编译期展开为静态指针）
        var_dump(ROUTE);                 // array(N) { [0]=>object(AnnotationEntry) ... }
        var_dump(ROUTE[0]->name);        // string(10) "Main->test"
        var_dump(ROUTE[0]->type);        // string(6) "method"
        var_dump(ROUTE[0]->data);        // array(1) { [0]=>string(5) "/test" }

        // 4. 调用注解目标（编译期展开为直接调用，零开销）
        ROUTE[0]->call(12);              // → tphp_class_Main_test(new_tphp_class_Main(...), 12)
        ROUTE[1]->call();                // → tphp_class_Main_test2(new_tphp_class_Main(...))

        // 5. 实例化注解类目标（编译期展开为 new_tphp_class_X(args)）
        $demo = ROUTE[2]->newInstance("TinyPHP");  // → new_tphp_class_Demo(STR_LIT("TinyPHP"))
        $demo->hello();
    }
}

#[ROUTE("/Demo")]
class Demo {
    public function __construct(string $name) { ... }
    public function hello(): void { ... }
}
```

### 14.4 AnnotationEntry 内置类

每个注解使用编译期收集为一个 `AnnotationEntry` 实例（C 结构体，非用户类）：

| 属性 | 类型 | 说明 |
|------|------|------|
| `$data` | `array` | 位置参数数组（注解使用时传入的参数） |
| `$type` | `string` | 目标类型：`method` / `static_method` / `class` / `function` |
| `$name` | `string` | 限定名：`Ns\Class->method` / `Ns\Class::staticMethod` / `Ns\func` / `Ns\Class` |

| 方法 | 说明 |
|------|------|
| `call(...$args): T` | 调用目标方法/静态方法/函数，返回目标返回类型（class 目标报错） |
| `newInstance(...$args): ClassType` | 实例化目标类，返回精确类类型（非 class 目标报错） |

### 14.5 编译期展开规则

| 源代码 | 编译期展开为 | 开销 |
|--------|-------------|------|
| `ROUTE[0]` | `_annot_ROUTE_0`（静态 `AnnotationEntry*` 指针） | 零 |
| `ROUTE[0]->data` | `_annot_ROUTE_0->data` | 零 |
| `ROUTE[0]->type` | `_annot_ROUTE_0->type` | 零 |
| `ROUTE[0]->name` | `_annot_ROUTE_0->name` | 零 |
| `ROUTE[0]->call(12)`（method） | `(tphp_class_Main_test(new_tphp_class_Main(...), 12))` | 零（直接调用） |
| `ROUTE[0]->call(12)`（static_method） | `tphp_class_Main_staticMethod(12)` | 零 |
| `ROUTE[0]->call(12)`（function） | `tphp_fn_funcName(12)` | 零 |
| `ROUTE[0]->newInstance("x")`（class） | `new_tphp_class_Demo(STR_LIT("x"))` | 零 |
| `ROUTE[$i]->call(12)`（动态索引） | **不支持**（编译期无法确定目标） | — |

### 14.6 扫描顺序与限定名规则

注解 entry 按以下顺序收集到常量数组：

1. `mainClass`（Main 类）的类级注解 → 方法级注解（按方法声明顺序）
2. `extraClasses`（辅助文件优先解析，按文件参数顺序）的类级注解 → 方法级注解
3. `functions` 的函数级注解

限定名命名规则：

| 目标类型 | 限定名格式 | 示例 |
|---------|-----------|------|
| method | `Ns\Class->method` | `Main->test` / `Child\MyChild->hello` |
| static_method | `Ns\Class::method` | `Main::create` |
| function | `Ns\func` | `greet` / `Utils\helper` |
| class | `Ns\Class` | `Demo` / `Child\MyChild` |

### 14.7 跨命名空间匹配

注解名匹配与普通常量作用域规则一致：
- **短名** → 匹配短名（同命名空间常量 + 全局常量回退，与 PHP 普通常量行为相同）
- **FQ 名**（含 `\` 或经 `use const` 导入）→ 精确匹配 FQ 名

`use const` 导入是可选的：全局常量无需导入即可跨命名空间使用（全局回退）；命名空间常量也可通过 `use const` 导入或直接使用 FQ 名。

```php
// child.php
namespace Child;
#[Attribute(name: string)]
const GET_NAME = [];

// main.php（全局命名空间）
use const Child\GET_NAME;  // 可选：导入后解析为 FQ 名 Child\GET_NAME

class Main {
    #[GET_NAME("/test3")]  // 短名匹配 Child\GET_NAME（经 use const 导入或全局回退）
    public function test3(): void { ... }
}

// child.php 中引用全局注解常量
#[ROUTE("/MyChild")]  // ROUTE 是全局常量，无需 use const 导入（全局回退）
class MyChild { ... }
```

### 14.8 编译期校验

- **参数数量校验**：注解使用的参数数量必须在声明参数的 `[required, total]` 范围内（支持默认值）
- **命名参数禁用**：`#[ROUTE(path: "/x")]` 报语法错误，应使用 `#[ROUTE("/x")]`
- **目标类型校验**：`call()` 仅用于 method/static_method/function，`newInstance()` 仅用于 class
- **静态索引校验**：`ROUTE[N]` 的 N 必须为编译期整数常量且在有效范围内

### 14.9 不支持

| 特性 | 原因 |
|------|------|
| 运行时反射 `ReflectionAttribute` | AOT 无运行时元数据 |
| 动态索引 `ROUTE[$i]->call()` | 编译期无法确定目标 |
| 注解继承（子类继承父类注解） | 编译期收集不递归父类 |
| 注解作用于属性/参数 | 当前仅支持 class/method/function |

## 15. 动态库导出 `#[Export]` + `-shared`（TinyPHP 扩展）

`#[Export("name")]` 是内置注解，标记独立函数导出为 C 函数。配合 `-shared` 编译选项，生成可被外部 C 代码调用的动态库（`.dll`/`.so`/`.dylib`）。

与 §14 注解系统**独立**：`#[Export]` 不经过 `#[Attribute]` 声明，不收集到注解常量数组，仅控制 C 符号导出。

### 15.1 语法

```php
#[Export("c_function_name")]
function phpFunc(params): retType { ... }
```

- 参数为字符串字面量，指定导出的 C 函数名（必须为合法 C 标识符，全局唯一）
- 仅可用于独立函数（`function`），用于方法报语法错误
- 非 `-shared` 模式下静默忽略，函数仍可正常调用

### 15.2 类型约束

| 方面 | 规则 |
|------|------|
| 参数类型 | 允许 `int`/`float`/`bool`/`string`/`void`/`C.Type`，**禁止 `array`** |
| 返回值类型 | 同上，**禁止 `array`** |
| string 映射 | 直接暴露 `t_string*`（不桥接 `char*`），C 侧需包含 `tphp_runtime.h` |

### 15.3 生成机制

`-shared` 模式下，每个 `#[Export("name")]` 函数生成同签名 trampoline：

```c
TPHP_EXPORT t_int add(t_int a, t_int b) {
    return tphp_fn_add(a, b);   // 调用原始 AOT 函数
}
```

- **TPHP_EXPORT 宏**：Windows `__declspec(dllexport)` / GCC `__attribute__((visibility("default")))`
- **Runtime 自动初始化**：Windows `DllMain(DLL_PROCESS_ATTACH)` / Linux/macOS `__attribute__((constructor))`，调用 `tphp_rt_init()` + 注解初始化函数
- **Main 类**：`-shared` 模式仍需 Main 入口类（不豁免），但 `main()` 不被执行

### 15.4 `-shared` 编译选项

```bash
tphp foo.php -shared -o foo.dll     # Windows
tphp foo.php -shared -o foo.so      # Linux
tphp foo.php -shared -o foo.dylib   # macOS
```

- 输出文件名机制与可执行文件一致（`-o` 指定，默认从入口文件名派生 + 平台扩展名）
- 传递 `-shared` 给链接器
- `--debug` 模式下不做输出比较（DLL 不可直接执行）

### 15.5 多注解共存

`#[Export]` 可与 §14 用户注解共存于同一函数，互不干扰：

```php
#[Export("multi_fn")]     // 导出为 C 函数（-shared 模式）
#[ROUTE("/api/multi")]    // 收集到 ROUTE 注解数组（所有模式）
function multiFn(int $n): void { ... }
```
