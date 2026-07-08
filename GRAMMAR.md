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
C.FILE $f = c_open("file.txt");   // phpc 互操作 C 类型

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
| 类类型 | `tphp_class_X*` 或 `tphp_na_Ns_tphp_class_X*` | COS 对象指针（命名空间类带 `tphp_na_` 前缀） |

> ⚠️ **`===` 和 `==` 等价**：类型固定意味着编译期已知类型，"同时类型不同"的情况不存在。

### AOT 限制

以下 PHP 特性依赖运行时解释器/动态符号表，**永久不支持**：

| 类别 | 不支持 | 原因 |
|------|--------|------|
| 动态代码 | `eval()` `assert()` `create_function()` | 无解释器 |
| 动态调用 | `$fn()` `$obj->$m()` `call_user_func()` | 编译时不知名 |
| 变量变量 | `$$var` `compact()` `extract()` | 无运行时符号表 |
| 运行时内省 | `Reflection*` `debug_backtrace()` `get_defined_vars()` | 无 |
| 动态引入 | `include` `require` | 无运行时文件加载 |
| 魔术方法 | `__call` `__get` `__set` `__callStatic` | 无动态分发 |
| 可空 | `?int` | 不做（破坏类型固定优势） |
| 联合类型 | `int\|string` | ✅ 已支持（映射到 `mixed`/`t_var`） |

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
  | 'readonly'      ✅ (仅修饰符)

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
    visibility 'static'? 'readonly'? type IDENTIFIER ';'            ✅ (type required)
  | visibility 'static'? 'readonly'? type IDENTIFIER '=' expr ';'  ✅ (type required)

> 属性和构造器属性提升**必须**写类型声明，`public $x` 会被拒绝。

visibility:
    'public'    ✅
  | 'private'   ✅

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
  | name        ✅ (类/枚举类型)
  | '?' type    ❌ 不做（AOT 哲学冲突，鼓励 t_var=丢性能）
  | type '|' type   ❌ 不做
  | '(' type (',' type)+ ')'   ⬜ (intersection types)
```

### 3.2 方法

```
method_decl:
    visibility 'function' IDENTIFIER '(' params ')' return_type? body   ✅
  | visibility 'static' 'function' IDENTIFIER '(' params ')' return_type? body  ✅ (部分)

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
> 支持类型：`int`、`float`、`string`、`bool`。不支持 `callable` 类型作为默认值。

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
  | assign_stmt                ✅ (支持可选类型标记: `type? '$' IDENTIFIER '=' expr ';'`)
  | array_push_stmt            🔧 ($a[] = expr — TinyPHP 内联语法糖)
  | compound_assign            ✅ (+= -= *= /= .=)
  | list_destructure           ✅ (含键名 "key"=>$var)
  | unset_stmt                 ✅
  | block                      ✅
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

throw_stmt:
    'throw' expr ';'   ✅
```

---

## 8. 表达式

### 8.1 运算符优先级（从低到高）

```
expr:
    yield_expr       ✅
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

yield_expr:
    'yield' expr                       ✅ (yield value)
  | 'yield' expr '=>' expr             ✅ (yield key => value)
  | 'yield'                            ✅ (yield null)
  | 'yield' 'from' expr                ⬜ (yield from 委托，待实现)

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
  | FLOAT_LIT        ✅ → t_float(double)
  | STRING_LIT       ✅ → t_string
  | TRUE_KW          ✅ → true
  | FALSE_KW         ✅ → false
  | NULL_KW          ✅ → null
  | MAGIC_LINE       ✅ (__LINE__)
  | MAGIC_FILE       ✅ (__FILE__)
  | MAGIC_DIR        ✅ (__DIR__)
  | DIR_SEP          ✅ (DIRECTORY_SEPARATOR)
  | IDENTIFIER       ✅ (变量 $var 或常量 CONST)
  | '(' expr ')'     ✅
  | '(' cast_type ')' expr          ✅ ((int)/(float)/(string)/(bool))
  | 'array' '(' args ')'            ✅
  | '[' args ']'                    ✅
  | 'new' name '(' args ')'         ✅
  | 'function' '(' params ')' body  ✅ (闭包)
  | 'fn' '(' typed_params ')' ':' type '=>' expr   ✅ (箭头函数，强制参数+返回类型)
  | 'match' '(' expr ')' '{' arm* '}' ✅
  | 'list' '(' list_vars ')' '=' expr   ✅
  | '[' list_vars ']' '=' expr           ✅
```

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
__FUNCTION__          ⬜
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
```

---

## 12. C 互操作扩展（TinyPHP 扩展）

```
c_call:
    'C->' IDENTIFIER '(' args ')'   ✅ (直接 C 函数调用)
  | 'C->' IDENTIFIER                ✅ (直接 C 常量/枚举/宏访问，无括号)

c_type_annotation:                  ✅ (借鉴 vlang C.Type 命名空间设计)
    'C.' IDENTIFIER                 → C 类型注解（函数参数/返回值）
  // C.int → int, C.double → double, C.char_ptr → char*
  // C.void_ptr → void*, C.FILE → FILE*（结构体指针默认）

c_type_bridge:
    'c_int(' expr ')'       ✅ → int32_t
  | 'c_float(' expr ')'     ✅ → double
  | 'c_str(' expr ')'       ✅ → const char*
  | 'php_int(' expr ')'     ✅ → t_int
  | 'php_float(' expr ')'   ✅ → t_float
  | 'php_str(' expr ')'     ✅ → t_string (深拷贝，复用 C 内存)
  | 'php_str_clone(' expr ')' ✅ → t_string (深拷贝，明确克隆语义)

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
    'phpc_free(' ptr ')'           ✅ → free(ptr) + 自动置零变量防 UAF
  | 'phpc_free_str_arr(' p,p ')'   ✅ → 释放字符串数组 + 自动置零
  | 'phpc_assert_ptr(' p,name ')'  ✅ → 断言非 NULL，否则抛 tp_throw
```

---

## 13. 与 PHP 8.5 的差异汇总

### ✅ 完全兼容

| 语法 | 备注 |
|------|------|
| `if/elseif/else` `while` `do-while` `for` `foreach` `switch` `match` | 全部控制流 |
| `break/continue/goto` 标签 | — |
| `class` `extends` `interface` `implements` `trait+use` `abstract` `final` `readonly` | COS struct 嵌套继承 |
| `enum` `enum case` | int/string backing |
| `try/catch(Exception $e)/finally` `throw` | COS setjmp/longjmp |
| `function` `closure` `fn =>` `use($x)` | 全部闭包形态 |
| 完整 15 级运算符优先级 | 含 `<=>` `??` `?:` `?->` `**` `&|^~` `<<>>` |
| `(int)` `(float)` `(string)` `(bool)` 类型转换 | — |
| `namespace A\B` `use A\{B,C}` `use function A\{f1,f2}` `use A\{B, function f}` | 分组导入 |
| `list()/$a[] =` 解构 | 含键名 `"key"=>$v` |
| `int &$x` 引用传参 | 全类型支持（int/float/bool/string/array/对象） |
| `self::CONST` `Class::CONST` `self::method()` | — |
| `__construct(public $x)` 属性提升 | — |
| `__destruct` | 作用域结束自动调用 |
| `__LINE__` `__FILE__` `__DIR__` `__CLASS__` `__METHOD__` `DIRECTORY_SEPARATOR` | 编译期替换 |
| `instanceof` | 遍历类链 |

### 🔧 TinyPHP 独有

| 语法 | 说明 |
|------|------|
| `#include "file.h"` | 嵌入 C 头文件 |
| `#include <sys.h>` | 系统头文件 |
| `#flag [CC] [OS] flags` | 编译器/平台过滤链接标志 |
| `#callback type name(params)` | 声明 C 回调签名 |
| `#debug expected` | 测试预期输出（`--debug` 模式） |
| `C->func(args)` | 直接 C 函数调用 |
| `C->CONST` | 直接 C 常量/枚举/宏访问（无括号时按 `t_int` 推断） |
| `C.Type` | C 类型注解（函数参数/返回值，如 `C.Point` → `Point*`） |
| `c_int/c_float/c_str` | PHP → C 类型桥接 |
| `php_int/php_float/php_str/php_str_clone` | C → PHP 类型桥接 |
| `phpc_arr_*` `phpc_obj` `phpc_new_obj` `phpc_unregister_obj` `phpc_obj_steal` `phpc_fn_*` `phpc_thunk` `phpc_env_pin` `phpc_env_unpin` | 数组/对象/回调互操作 |
| `phpc_free` `phpc_free_str_arr` `phpc_assert_ptr` | C 内存释放/安全断言 |
| `#import pcntl` | 按需引入扩展（自动加载 ext/pcntl/src/） |
| `int &$x` | 引用传参（int/float/bool/string/array/对象全类型支持） |

### ❌ 不支持（AOT 物理不可行）

| 语法 | 原因 |
|------|------|
| `eval()` `assert($str)` `create_function()` | 无运行时解释器 |
| `$$var` `${expr}` | 编译时不知变量名 |
| `$fn()` `$obj->$m()` `call_user_func()` | 编译时不知函数名 |
| `include` `require` | 无运行时文件加载 |
| `__call` `__get` `__set` `__callStatic` | 无动态分发 |
| `Reflection*` 全系列 | 运行时内省 |
| `debug_backtrace()` `get_defined_vars()` | 运行时符号表 |

### ❌ 不做（权衡）

| 语法 | 原因 |
|------|------|
| `?int` `int\|string` | 破坏类型固定优势 |
| `...$args` 可变参数 | 需动态栈构造 |
| 命名参数 | AOT 无意义 |
