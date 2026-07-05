# Yield / Generator 实现计划（MVP）

## 目标
在 TinyPHP 中实现 PHP 原生的 `yield` 和 `Generator`，基于已验证的 minicoro 库。

**核心约束**：不使用 `yield` 的代码必须保持原有性能与生成代码完全一致（零开销）。生成器变换仅在函数被标记为 `isGenerator` 时生效。

## 当前状态
- minicoro 已在 `test/thirdparty/` 验证通过（CI 仅 macOS+TCC 使用 stub，不影响其他 11 个配置）
- `yield` 在 [Parser.php:1470](file:///c:/project/php/TinyPHP/src/Parser.php#L1470) 被显式屏蔽
- 无 `YIELD_KW` token、无 `YieldExpr` AST 节点、无 `isGenerator` 标志
- [CodeGenerator.php](file:///c:/project/php/TinyPHP/src/CodeGenerator.php) 无 yield/generator 支持
- [visitForeachStmt](file:///c:/project/php/TinyPHP/src/CodeGenerator.php#L3527) 仅支持数组迭代
- 运行时：[t_object/t_class](file:///c:/project/php/TinyPHP/include/object/object.h) 对象系统就绪；[tp_throw_ex](file:///c:/project/php/TinyPHP/include/object/try.h#L61) 调用 `tphp_rt_free_all()` 会释放所有追踪分配（type 0→`tp_obj_release`→dtor，可安全销毁 Generator）

## 范围

### MVP（本次实现）
- `yield $v` 和 `yield $k => $v` 语法
- 生成器函数 / 方法 / 闭包：`function gen(): Generator { yield 1; }`
- Generator 方法：`current()`、`key()`、`next()`、`send($v)`、`valid()`、`getReturn()`、`rewind()`
- `foreach ($gen as $k => $v) { ... }`
- 生成器中的 `return $v` → `getReturn()`

### 暂不实现（推迟）
- `yield from` 委托
- `Generator::throw($e)`
- 严格的 `rewind()` 语义（首次 current/next 自动 rewind）
- Iterator 接口（非 Generator 类）
- 箭头函数生成器

## 架构决策

### 1. 生成器函数变换（双函数模式）
PHP 生成器函数 `function gen(int $n): Generator { ... yield ...; return $r; }` 编译为两个 C 函数：

```c
/* 参数传输结构体 */
typedef struct { int n; } _gen_params_gen;

/* 协程入口函数（函数体在此） */
static void tphp_gen_gen_entry(mco_coro* co) {
    _gen_params_gen* _p = (_gen_params_gen*)mco_get_user_data(co);
    int n = _p->n;          /* 复制到局部变量 */
    free(_p);                /* 参数结构体已用完 */
    /* ... 函数体（yield→push+yield, return→push+return）... */
    /* 末尾自动生成 generateScopeCleanup（释放局部字符串/数组） */
}

/* 包装函数——调用方实际调用 */
tphp_class_Generator* tphp_fn_gen(int n) {
    _gen_params_gen* _p = calloc(1, sizeof(_gen_params_gen));
    _p->n = n;
    mco_desc desc = mco_desc_init(tphp_gen_gen_entry, 0);
    desc.user_data = _p;
    mco_coro* co;
    if (mco_create(&co, &desc) != MCO_SUCCESS) {
        free(_p); return NULL;
    }
    return new_tphp_class_Generator(co);
}
```

**零开销保证**：`visitFunction` 在最外层 `if ($node->isGenerator)` 分支判断——非生成器函数走原路径，生成代码完全不变。

### 2. Yield 协议（mco_push/mco_pop）
- 每次 `yield` 推送一个 `{t_var key, t_var value}` 对到协程存储
- `return` 推送单个 `t_var`（返回值）
- 调用方（Generator 方法）在 `mco_resume` 后：
  - `MCO_DEAD` → 弹出返回值（若有字节存储）
  - `MCO_SUSPENDED` → 弹出 yield 对

```c
typedef struct {
    t_var key;
    t_var value;
} _gen_yield_pair;
```

`yield` 表达式求值为 `send()` 传入的值（通过 resume 后弹出 `t_var` 获取）。

### 3. Generator 类（`include/object/generator.h`）
遵循 [exception.h](file:///c:/project/php/TinyPHP/include/object/exception.h) 模式：

```c
typedef struct {
    t_object _obj;
    mco_coro* co;
    t_var cur_key;       /* 缓存当前 key */
    t_var cur_val;       /* 缓存当前 value */
    t_var ret_val;       /* 缓存 return 值 */
    bool started;        /* 是否已首次 resume */
    bool done;           /* 是否已完成 */
} tphp_class_Generator;
```

- `_class_tphp_class_Generator` 描述符，`dtor` 调用 `mco_destroy(co)`（接受 suspended 状态的协程，已在测试中验证）
- `new_tphp_class_Generator(mco_coro*)` 分配并通过 `tphp_rt_register(ptr, 0)` 注册（异常时 `tphp_rt_free_all` 会触发 dtor）
- 方法：`tphp_gen_current/key/next/send/valid/getReturn/rewind`，均为 `tphp_fn_` 前缀（直接可被 PHP 调用）

### 4. isGenerator 检测
Parser 维护 `$genStack` 栈：
- `parseFunction`/`parseMethod`/`parseClosure` 在 `parseBlock` 前压入 `false`，弹出后作为 `isGenerator` 传给节点构造器
- `parseYieldExpr` 将栈顶设为 `true`

### 5. 类型推断
CodeGenerator 新增预扫描：在 `visitProgram` 开始时遍历所有 FunctionNode/MethodNode，填充：
- `$this->funcIsGenerator[funcCName] = true/false`
- `$this->funcRetTypes[funcCName]` 对生成器覆盖为 `tphp_class_Generator*`

`inferCallReturnType` 查 `funcIsGenerator` → 返回 `tphp_class_Generator*`。这样 `foreach (gen() as $v)` 能正确推导为 Generator 迭代。

### 6. foreach 扩展
[visitForeachStmt](file:///c:/project/php/TinyPHP/src/CodeGenerator.php#L3527) 在最外层根据 `inferType($node->array)` 分支：
- 类型为 `tphp_class_Generator*` → 生成 Generator 循环
- 否则 → 走现有数组循环（零改动）

Generator 循环模板：
```c
while (tphp_gen_valid($g)) {
    $k = tphp_gen_key($g);   /* 若有 keyVar */
    $v = tphp_gen_current($g);
    /* ...body... */
    tphp_gen_next($g);
}
```

## 实施步骤

### 步骤 1：Lexer 添加 yield 关键字
- [src/TokenType.php](file:///c:/project/php/TinyPHP/src/TokenType.php) 在 `RETURN_KW`（第 17 行）附近添加 `case YIELD_KW = 'yield';`
- [src/Lexer.php](file:///c:/project/php/TinyPHP/src/Lexer.php) 在 `$keywords` 数组（约第 94 行 `while` 附近）添加 `'yield' => TokenType::YIELD_KW`

### 步骤 2：AST 添加 YieldExpr 节点和 isGenerator 标志
[src/AST/Node.php](file:///c:/project/php/TinyPHP/src/AST/Node.php)：
- 新增 `YieldExpr extends ExprNode`：
  ```php
  class YieldExpr extends ExprNode {
      public function __construct(
          public readonly ?ExprNode $key,    /* null 表示无 key，自动递增 */
          public readonly ?ExprNode $value,  /* null 表示 yield; (yield NULL) */
      ) {}
      public function accept(ASTVisitor $visitor): string {
          return $visitor->visitYieldExpr($this);
      }
  }
  ```
- `FunctionNode`（第 49 行）、`MethodNode`（第 113 行）、`ClosureExpr`（第 663 行）构造器添加 `public readonly bool $isGenerator = false` 参数（带默认值，向后兼容）
- `ASTVisitor` 接口（第 860 行）添加 `public function visitYieldExpr(YieldExpr $node): string;`

### 步骤 3：Parser 解析 yield、检测生成器
[src/Parser.php](file:///c:/project/php/TinyPHP/src/Parser.php)：
- **删除**第 1470 行 `$unsupportedFns` 数组中的 `'yield'` 条目
- 添加 `private array $genStack = [];` 属性
- `parseFunction`（第 376 行）、`parseMethod`（第 595 行）、`parseClosure`（第 1616 行）：在 `parseBlock()` 前压栈，弹出后传入构造器
  ```php
  $this->genStack[] = false;
  $body = $this->parseBlock();
  $isGen = array_pop($this->genStack);
  return new FunctionNode(..., isGenerator: $isGen);
  ```
- 新增 `parseYieldExpr()` 方法：
  ```php
  private function parseYieldExpr(): ExprNode {
      if (!empty($this->genStack)) {
          $this->genStack[count($this->genStack) - 1] = true;
      }
      $line = $this->peek()->line; $col = $this->peek()->column;
      // yield;  或  yield;  （空 yield）
      if ($this->check(TokenType::SEMICOLON) || $this->check(TokenType::RPAREN)) {
          return $this->setPos(new YieldExpr(null, null), $line, $col);
      }
      $value = $this->parseExpr();   /* 使用 parseExpr 让 yield $a + $b 解析为 yield ($a + $b) */
      $key = null;
      if ($this->match(TokenType::DOUBLE_ARROW)) {
          $key = $value;
          $value = $this->parseExpr();
      }
      return $this->setPos(new YieldExpr($key, $value), $line, $col);
  }
  ```
- 在 `parseExprStmt`（第 1110 行）开头添加 yield 检测：
  ```php
  if ($this->match(TokenType::YIELD_KW)) {
      $expr = $this->parseYieldExpr();
      // 处理 $x = yield ... 形式
      if ($expr instanceof VariableExpr && $this->check(TokenType::EQUALS)) {
          // 不会发生：parseYieldExpr 已消费整个 yield 表达式
      }
      $this->consume(TokenType::SEMICOLON, 'Expected ;');
      return new ExprStmtNode($expr);
  }
  ```
- 在 `parsePrimary`（约第 1353 行）添加 YIELD_KW 处理（用于 `$x = yield 5;` 等表达式上下文）：
  ```php
  if ($this->match(TokenType::YIELD_KW)) {
      return $this->parseYieldExpr();  /* 不消费分号 */
  }
  ```

### 步骤 4：CodeGenerator 生成器变换
[src/CodeGenerator.php](file:///c:/project/php/TinyPHP/src/CodeGenerator.php)：

#### 4a. 新增 `visitYieldExpr`
```php
public function visitYieldExpr(YieldExpr $node): string
{
    // 1. 计算 key（无则自动递增 int）
    // 2. 计算 value，转换为 t_var
    // 3. push {key, value} 到 mco_running() 存储
    // 4. mco_yield(mco_running())
    // 5. pop sent t_var
    // 6. 返回 sent 值（转换为期望类型）
}
```
具体生成代码模式：
```c
{
    _gen_yield_pair _yp;
    _yp.key = (t_var){.type = TYPE_INT, .value._int = _auto_key++};
    _yp.value = <value as t_var>;
    mco_push(mco_running(), &_yp, sizeof(_yp));
    mco_yield(mco_running());
    t_var _sent;
    if (mco_pop(mco_running(), &_sent, sizeof(t_var)) != MCO_SUCCESS) {
        _sent = VAR_NULL();
    }
    /* _sent 作为 yield 表达式的值（供赋值使用） */
}
```

#### 4b. 修改 `visitFunction`（第 663 行）
在最外层分支：
```php
if (!$node->isGenerator) {
    // 现有代码原样保留（零改动）
    return $this->emitNormalFunction($node);
}
// 生成器变换
return $this->emitGeneratorFunction($node);
```
`emitGeneratorFunction` 做三件事：
1. 发射参数结构体 typedef 到 `SEC_TYPES`（或新 `SEC_GENPARAMS`）
2. 发射协程入口函数 `tphp_gen_<name>_entry` 到 `SEC_FUNCIMPL`，函数体：
   - 开头：从 `user_data` 解包参数到局部变量，`free(user_data)`
   - 中间：变换后的 body（yield→visitYieldExpr, return→visitReturnStmt 生成器模式）
   - 结尾：`generateScopeCleanup` 释放局部资源
3. 发射包装函数 `tphp_fn_<name>` 到 `SEC_FUNCIMPL`，创建协程并返回 `tphp_class_Generator*`

`visitMethod` 和 `visitClosure` 做类似分支处理。

#### 4c. 修改 `visitReturnStmt`（第 1018 行）
检测是否在生成器上下文（新增 `$this->inGenerator` 标志，进入生成器入口时设 true）：
- 生成器内：`{ t_var _r = <expr as t_var>; mco_push(mco_running(), &_r, sizeof(t_var)); return; }`
- 非生成器：现有代码（零改动）

#### 4d. 修改 `visitForeachStmt`（第 3527 行）
在最外层根据 `inferType($node->array)` 分支：
```php
$iterType = $this->inferType($node->array);
if (str_contains($iterType, 'tphp_class_Generator')) {
    return $this->emitGeneratorForeach($node, $iterType);
}
// 现有数组循环代码原样保留
```
`emitGeneratorForeach` 生成：
```c
{
    tphp_class_Generator* _g = <iterable>;
    /* 若 keyVar/valueVar 需要声明则在此声明 */
    while (tphp_gen_valid(_g)) {
        /* if keyVar: <keyType> <keyVar> = tphp_gen_key(_g); */
        <valType> <valVar> = tphp_gen_current(_g);
        /* ...body... */
        tphp_gen_next(_g);
    }
}
```

#### 4e. 预扫描生成器状态
在 `visitProgram` 开头（或 CodeGenerator 初始化后）调用 `preScanGenerators(ProgramNode)`：
```php
private function preScanGenerators(ProgramNode $prog): void {
    foreach ($prog->statements as $stmt) {
        if ($stmt instanceof FunctionNode && $stmt->isGenerator) {
            $this->funcIsGenerator[self::funcCName($stmt)] = true;
            $this->funcRetTypes[self::funcCName($stmt)] = 'tphp_class_Generator*';
        }
        // 方法在 visitClass 时注册
    }
}
```
`inferCallReturnType`（第 1346 行）增加：
```php
$fnCName = self::funcCNameFromCall($expr);
if ($fnCName && isset($this->funcIsGenerator[$fnCName]) && $this->funcIsGenerator[$fnCName]) {
    return 'tphp_class_Generator*';
}
```

### 步骤 5：运行时——提升 minicoro 并添加 Generator 类

#### 5a. 提升 minicoro.h 到 include/
- 复制 `test/thirdparty/minicoro.h` → `include/minicoro.h`
- 在文件顶部添加 TCC 兼容性宏（从 `minicoro_test.h` 提取）：
  ```c
  /* TCC Windows: kernel32.def lacks CreateFiberEx */
  #if defined(_WIN32) && !defined(__GNUC__) && !defined(_MSC_VER)
    #define CreateFiberEx(commit, reserve, flags, fn, param) \
        CreateFiber((reserve), (fn), (param))
  #endif
  ```
- macOS+TCC 的 stub 不在此文件处理——在 generator.h 中处理（因为 generator.h 需要 minicoro 类型）

#### 5b. 创建 include/object/generator.h
```c
#pragma once
#include "object/object.h"
#include "val.h"

#if defined(__APPLE__) && !defined(__GNUC__)
/* Stub: TCC on macOS — ucontext broken, Generator non-functional */
typedef struct mco_coro_t_stub { int _dummy; } mco_coro;
/* ... 最小 stub 声明让编译通过，运行时 Generator 方法返回 NULL/0 ... */
#else
#define MCO_NO_DEBUG
#include "minicoro.h"
#endif

typedef struct {
    t_object _obj;
    mco_coro* co;
    t_var cur_key;
    t_var cur_val;
    t_var ret_val;
    bool started;
    bool done;
} tphp_class_Generator;

/* Class descriptor */
static void* _vtable_tphp_class_Generator[1] = { NULL };
static const t_class _class_tphp_class_Generator = {
    .name          = "Generator",
    .parent        = NULL,
    .instance_size = sizeof(tphp_class_Generator),
    .dtor          = (void*)tphp_class_Generator___destruct,
    .vtable        = _vtable_tphp_class_Generator,
    .vtable_len    = 0,
};

/* Constructor */
static inline tphp_class_Generator* new_tphp_class_Generator(mco_coro* co) {
    tphp_class_Generator* self = (tphp_class_Generator*)tp_obj_alloc(&_class_tphp_class_Generator);
    if (self) {
        self->co = co;
        self->cur_key = VAR_NULL();
        self->cur_val = VAR_NULL();
        self->ret_val = VAR_NULL();
        self->started = false;
        self->done = false;
        tphp_rt_register((void*)self, 0);
    }
    return self;
}

/* Destructor */
void tphp_class_Generator___destruct(tphp_class_Generator* self) {
    if (self && self->co) {
        #if !defined(__APPLE__) || defined(__GNUC__)
        mco_destroy(self->co);
        #endif
        self->co = NULL;
    }
}

/* Methods — tphp_fn_ prefix, directly callable from PHP */
t_var tphp_gen_current(tphp_class_Generator* self);   /* returns cur_val */
t_var tphp_gen_key(tphp_class_Generator* self);        /* returns cur_key */
t_int tphp_gen_valid(tphp_class_Generator* self);     /* returns !done */
t_var tphp_gen_next(tphp_class_Generator* self);      /* resume, cache new cur_key/cur_val */
t_var tphp_gen_send(tphp_class_Generator* self, t_var v);  /* push v, resume, return next yield value */
t_var tphp_gen_getReturn(tphp_class_Generator* self);  /* returns ret_val */
void tphp_gen_rewind(tphp_class_Generator* self);     /* if !started, do first resume */
```

`next()` 内部逻辑：
1. 若 `!started`：先做首次 resume（`rewind` 语义）
2. push 一个 `t_var`（NULL 或 send 的值）到协程存储
3. `mco_resume(co)`
4. 若 `MCO_DEAD`：pop 返回值到 `ret_val`，设 `done=true`
5. 若 `MCO_SUSPENDED`：pop `_gen_yield_pair` 到 `{cur_key, cur_val}`
6. 返回 `cur_val`

#### 5c. 更新 include/common.h
添加（在 `exception.h` 之后）：
```c
#include "object/generator.h"
```
Generator 类内嵌定义实现（让单 TU 编译包含），方法实现放 generator.h 内 `static inline`（小方法）或 generator.h 内非 inline（大方法如 next/send）。

### 步骤 6：类型系统注册
- [src/CodeGenerator.php](file:///c:/project/php/TinyPHP/src/CodeGenerator.php) `mapType`：`'Generator'` → `'tphp_class_Generator*'`
- `inferType`：VariableExpr 若 varTypes 标记为 `tphp_class_Generator*`，正确返回
- `visitAssign`：赋值 `$g = gen()` 时记录 `varTypes[$g] = 'tphp_class_Generator*'`（通过 inferCallReturnType 流入）
- 方法调用：`$gen->current()` 调用应生成 `tphp_gen_current($gen)`，返回类型 `t_var`——需在 `visitCall` 的方法分发中识别 Generator 方法（或通过现有对象方法分发机制，需确认）

### 步骤 7：测试
创建三个测试文件：

#### test/features/generator_basic.php
```php
<?php
#debug int(1)
#debug int(2)
#debug int(3)

function gen(): Generator {
    yield 1;
    yield 2;
    yield 3;
}

class Main {
    public function main(): void {
        foreach (gen() as $v) {
            var_dump($v);
        }
    }
}
```

#### test/features/generator_send.php
```php
<?php
#debug int(0)
#debug int(10)
#debug int(20)

function counter(): Generator {
    $x = 0;
    while (true) {
        $x = yield $x;
    }
}

class Main {
    public function main(): void {
        $gen = counter();
        var_dump($gen->current());   /* 0 (auto-rewind 后首个 yield 值) */
        var_dump($gen->send(10));    /* 10 */
        var_dump($gen->send(20));    /* 20 */
    }
}
```

#### test/features/generator_return.php
```php
<?php
#debug int(1)
#debug int(2)
#debug int(99)

function gen(): Generator {
    yield 1;
    yield 2;
    return 99;
}

class Main {
    public function main(): void {
        $gen = gen();
        foreach ($gen as $v) {
            var_dump($v);
        }
        var_dump($gen->getReturn());
    }
}
```

## 假设与决策
- **生成器返回类型**：PHP 要求生成器声明 `: Generator` 或 `: Iterator`。TinyPHP 接受 `: Generator`（大小写不敏感）。若函数含 `yield` 但无返回类型或返回类型不是 Generator，仍按生成器处理（运行时返回 Generator 对象）。
- **自动 key**：`yield $v`（无 key）从 0 自动递增，匹配 PHP 行为。每个生成器维护独立的 `_auto_key` 计数器（局部变量）。
- **send() 前自动 rewind**：首次 `current()`/`next()`/`send()` 自动触发首次 resume（PHP 严格语义要求 rewind，但 MVP 简化为自动）。
- **macOS+TCC**：Generator 类使用 stub 编译通过，运行时方法返回 NULL/0。该平台的 generator 测试需 `@skip` 标注。其他 11 个 CI 配置正常。
- **零开销验证**：所有非生成器路径保持原代码不变。具体保证：
  - `visitFunction`/`visitMethod`/`visitClosure`：`if (!$node->isGenerator)` 分支保留原逻辑
  - `visitReturnStmt`：`if (!$this->inGenerator)` 分支保留原逻辑
  - `visitForeachStmt`：`if (!str_contains($iterType, 'Generator'))` 分支保留原逻辑
  - `visitYieldExpr`：仅被生成器函数体调用，非生成器永不到达
  - minicoro.h：仅声明，无 `MINICORO_IMPL`（实现在 generator.h 中定义），非生成器代码不引用任何 minicoro 符号
- **参数结构体内存**：包装函数 `calloc` 参数结构体，协程入口函数 `free` 它（已复制到局部变量）。`tphp_rt_register` 不追踪参数结构体（生命周期由协程入口管理）。
- **异常安全**：Generator 对象通过 `tphp_rt_register(ptr, 0)` 注册，异常时 `tphp_rt_free_all` → `tp_obj_release` → dtor → `mco_destroy`。协程处于 suspended 状态时 `mco_destroy` 安全（已验证）。协程内的局部字符串/数组也通过 `tphp_rt_register` 追踪，会被一同释放。

## 验证步骤
1. 本地 Windows 三编译器运行新测试：
   ```
   php tphp.php test/features/generator_basic.php --debug -cc tcc
   php tphp.php test/features/generator_basic.php --debug -cc gcc
   php tphp.php test/features/generator_basic.php --debug -cc clang
   ```
   （send.php、return.php 同样）
2. 回归测试：运行全部现有测试，确认无回归：
   ```
   php .github/scripts/run_tests.php
   ```
   特别关注 Parser/CodeGenerator 改动是否影响非生成器代码。
3. CI 验证：推送分支，触发 workflow_dispatch，预期 11/12 平台通过（macOS+TCC 标注 `@skip` 或返回 stub 值）。
4. 零开销抽样：选 2-3 个现有非生成器测试，对比改动前后生成的 `build/main.c` 是否字节一致（可选但推荐）。

## 关键文件清单
| 文件 | 改动类型 | 说明 |
|------|----------|------|
| `src/TokenType.php` | 新增 | `YIELD_KW` 枚举 |
| `src/Lexer.php` | 新增 | yield 关键字映射 |
| `src/AST/Node.php` | 新增 | `YieldExpr` 类、`isGenerator` 字段、`visitYieldExpr` 接口方法 |
| `src/Parser.php` | 修改 | 删除 yield 屏蔽、添加 `parseYieldExpr`、`$genStack` 跟踪 |
| `src/CodeGenerator.php` | 修改 | `visitYieldExpr`、生成器变换、foreach 扩展、预扫描、类型推断 |
| `include/minicoro.h` | 新增 | 从 test/thirdparty 提升，附 TCC Windows 宏 |
| `include/object/generator.h` | 新增 | Generator 类定义与方法实现 |
| `include/common.h` | 修改 | 添加 `#include "object/generator.h"` |
| `test/features/generator_basic.php` | 新增 | 基础 yield + foreach 测试 |
| `test/features/generator_send.php` | 新增 | send() 双向通信测试 |
| `test/features/generator_return.php` | 新增 | getReturn() 测试 |

## 风险与缓解
1. **生成器内局部变量生命周期**：yield 后协程挂起，局部变量在协程栈上保留。`generateScopeCleanup` 在协程入口函数末尾运行（正常结束）或 dtor 调用 `mco_destroy` 时释放栈——两者都安全。
2. **嵌套生成器调用**：PHP 允许生成器调用其他生成器。minicoro 支持 `mco_running()` 嵌套——每个生成器有独立协程，`mco_yield` 只挂起当前协程。无需特殊处理。
3. **闭包内 yield**：PHP 允许闭包作为生成器。`visitClosure` 需同样做生成器变换。闭包的 `useVars` 捕获通过 `user_data` 传输（与参数合并到同一结构体）。
4. **CodeGenerator 状态污染**：进入生成器变换前保存所有状态（declaredVars、varTypes、symbols、currentRetType、inGenerator），变换后恢复——参照 [visitClosure](file:///c:/project/php/TinyPHP/src/CodeGenerator.php#L1845) 的保存/恢复模式。
5. **方法调用分发**：`$gen->current()` 需生成 `tphp_gen_current($gen)`。需确认 CodeGenerator 现有对象方法调用机制是否能正确分发（可能需要在方法解析中识别 Generator 类的特殊方法）。若现有机制不支持，可能需要为 Generator 方法定义为 `tphp_fn_` 前缀的全局函数并通过特殊路径调用。
