# Yield/Generator 实现执行计划（续）— CodeGenerator + 测试

## 背景
原始计划位于 [yield-generator-implementation.md](file:///c:/project/php/TinyPHP/.trae/documents/yield-generator-implementation.md)，已批准。

**已完成步骤**（任务 #17–#20，本次会话不复查）：
- 步骤 1 ✅ Lexer：`TokenType::YIELD_KW`、`Lexer.php` 关键字映射
- 步骤 2 ✅ AST：[Node.php](file:///c:/project/php/TinyPHP/src/AST/Node.php) `YieldExpr` 类、`FunctionNode/MethodNode/ClosureExpr` 的 `isGenerator` 字段、`ASTVisitor::visitYieldExpr` 接口方法
- 步骤 3 ✅ Parser：[Parser.php](file:///c:/project/php/TinyPHP/src/Parser.php) `$genStack`、`parseYieldExpr`、`parseExprStmt`/`parsePrimary` 的 yield 钩子、移除 `$unsupportedFns` 中的 `'yield'`
- 步骤 5 ✅ 运行时：[include/minicoro.h](file:///c:/project/php/TinyPHP/include/minicoro.h)、[include/object/generator.h](file:///c:/project/php/TinyPHP/include/object/generator.h)（含 macOS+TCC stub）、[include/common.h](file:///c:/project/php/TinyPHP/include/common.h) 第 21 行 include

**已验证的关键事实**（基于 Phase 1 探查）：
- [CodeGenerator.php](file:///c:/project/php/TinyPHP/src/CodeGenerator.php) **尚未做任何 yield 相关修改**（grep 仅匹配 `class CodeGenerator implements ASTVisitor` 一行）
- `$typeMap` 在第 58–62 行，**未**含 `'Generator'`
- Generator 类注册需在 [resetState()](file:///c:/project/php/TinyPHP/src/CodeGenerator.php#L260) 中添加（参照 Exception 模式，第 272–276 行）
- [visitFunction](file:///c:/project/php/TinyPHP/src/CodeGenerator.php#L663)、[visitMethod](file:///c:/project/php/TinyPHP/src/CodeGenerator.php#L796)、[visitClosure](file:///c:/project/php/TinyPHP/src/CodeGenerator.php#L1845)、[visitReturnStmt](file:///c:/project/php/TinyPHP/src/CodeGenerator.php#L1018)、[visitForeachStmt](file:///c:/project/php/TinyPHP/src/CodeGenerator.php#L3527)、[inferCallReturnType](file:///c:/project/php/TinyPHP/src/CodeGenerator.php#L1346) 均需修改
- **重要更正**：[generator.h](file:///c:/project/php/TinyPHP/include/object/generator.h) 实际方法命名为 `tphp_class_Generator_<method>`（如 `tphp_class_Generator_current`、`tphp_class_Generator_valid`、`tphp_class_Generator_next`、`tphp_class_Generator_send`、`tphp_class_Generator_key`、`tphp_class_Generator_getReturn`、`tphp_class_Generator_rewind`），**不是** 原始计划 5b 中所述的 `tphp_gen_<method>`。CodeGenerator 必须使用 `tphp_class_Generator_<method>`。
- generator.h 已定义 `_gen_yield_pair`（第 73 行）、`tphp_class_Generator` 结构体（第 84 行）、`new_tphp_class_Generator(mco_coro*)`（第 108 行）、`_gen_resume_and_cache`（第 123 行）

## 目标
完成剩余两步：
- **步骤 4（CodeGenerator）**：生成器变换 + visitYieldExpr + foreach 扩展 + 类型注册
- **步骤 6（类型系统注册）**：与步骤 4 合并实现
- **步骤 7（测试）**：3 个功能测试文件 + 本地三编译器验证 + 全量回归

**核心约束（用户明确要求）**：不使用 yield 的代码必须保持原有性能与生成代码完全一致（零开销）。

## 实施步骤

### 步骤 4.1：注册 Generator 类到 SymbolTable
**文件**：[src/CodeGenerator.php](file:///c:/project/php/TinyPHP/src/CodeGenerator.php)
**位置**：[resetState()](file:///c:/project/php/TinyPHP/src/CodeGenerator.php#L260) 内，第 276 行（Exception 注册之后、Resource 注册之前）

新增代码：
```php
// 内置 Generator 类（基于 minicoro 协程）
$this->symbols->addClass('tphp_class_Generator');
$this->symbols->addClassName('Generator', 'tphp_class_Generator');
$this->symbols->getClass('tphp_class_Generator')->methods['current']   = new MethodInfo('t_var');
$this->symbols->getClass('tphp_class_Generator')->methods['key']        = new MethodInfo('t_var');
$this->symbols->getClass('tphp_class_Generator')->methods['next']        = new MethodInfo('t_var');
$this->symbols->getClass('tphp_class_Generator')->methods['send']       = new MethodInfo('t_var', ['t_var']);
$this->symbols->getClass('tphp_class_Generator')->methods['valid']      = new MethodInfo('t_int');
$this->symbols->getClass('tphp_class_Generator')->methods['getReturn']  = new MethodInfo('t_var');
$this->symbols->getClass('tphp_class_Generator')->methods['rewind']      = new MethodInfo('void');
$this->classMethodRetTypes['tphp_class_Generator'] = [
    'current' => 't_var', 'key' => 't_var', 'next' => 't_var',
    'send' => 't_var', 'valid' => 't_int', 'getReturn' => 't_var', 'rewind' => 'void',
];
$this->classParentName['tphp_class_Generator'] = '';
```

**原因**：让 `$gen->current()` 等方法调用通过现有方法分发机制生成 `tphp_class_Generator_current($gen)`。需要先确认 [SymbolTable::addClass](file:///c:/project/php/TinyPHP/src/SymbolTable.php) 与 `MethodInfo` 第二参数（参数类型数组）的签名。

### 步骤 4.2：添加 'Generator' 到 $typeMap
**位置**：[CodeGenerator.php 第 58–62 行](file:///c:/project/php/TinyPHP/src/CodeGenerator.php#L58)

修改后：
```php
private static array $typeMap = [
    'int' => 't_int', 'float' => 't_float', 'string' => 't_string',
    'bool' => 't_bool', 'void' => 'void', 'never' => 'void', 'array' => 't_array*',
    'mixed' => 't_var', 'null' => 'void*',
    'Generator' => 'tphp_class_Generator*',   // NEW
];
```

**原因**：让 `function gen(): Generator { ... }` 的返回类型正确映射为 `tphp_class_Generator*`。

### 步骤 4.3：添加预扫描生成器状态
**位置**：[visitProgram](file:///c:/project/php/TinyPHP/src/CodeGenerator.php#L114) 开头（重置后、SEC_CLSFWDS 之前）

新增属性（在 CodeGenerator 类顶部某处，与 `$funcRetTypes` 同区域）：
```php
/** 生成器函数标记：funcCName → true（用于 inferCallReturnType 和 SEC_FUNCFWDS 覆盖） */
private array $funcIsGenerator = [];
/** 当前是否在生成器入口函数体内（影响 visitReturnStmt / visitYieldExpr 行为） */
private bool $inGenerator = false;
```

新增方法：
```php
private function preScanGenerators(ProgramNode $node): void
{
    foreach ($node->functions as $fn) {
        if ($fn->isGenerator) {
            $cn = self::funcCName($fn);
            $this->funcIsGenerator[$cn] = true;
        }
    }
    // 方法在 visitClass 处理时注册（避免双重遍历），闭包在 visitClosure 处理
}
```

在 `visitProgram` 重置状态之后立即调用：
```php
$this->resetState();
$this->preScanGenerators($node);   // NEW
```

**原因**：让 `inferCallReturnType` 与 SEC_FUNCFWDS 能识别生成器函数，覆盖返回类型为 `tphp_class_Generator*`。

### 步骤 4.4：修改 SEC_FUNCFWDS 循环覆盖生成器返回类型
**位置**：[CodeGenerator.php 第 207–210 行](file:///c:/project/php/TinyPHP/src/CodeGenerator.php#L207)

修改前：
```php
foreach ($node->functions as $fn) {
    $ret = self::mapType($fn->returnType);
    $fnCName = self::funcCName($fn);
    $this->funcRetTypes[$fnCName] = $ret;
```

修改后：
```php
foreach ($node->functions as $fn) {
    $fnCName = self::funcCName($fn);
    if (!empty($this->funcIsGenerator[$fnCName])) {
        $ret = 'tphp_class_Generator*';
    } else {
        $ret = self::mapType($fn->returnType);
    }
    $this->funcRetTypes[$fnCName] = $ret;
```

**原因**：生成器函数的 C 包装函数返回 `tphp_class_Generator*`，而非 PHP 声明的 `Generator`（mapType 之后也是 `tphp_class_Generator*`，但显式覆盖更安全，避免大小写或别名问题）。

### 步骤 4.5：修改 visitFunction 分支生成器变换
**位置**：[visitFunction 第 663 行](file:///c:/project/php/TinyPHP/src/CodeGenerator.php#L663)

在方法开头加分支：
```php
public function visitFunction(FunctionNode $node): string
{
    if ($node->isGenerator) {
        return $this->emitGeneratorFunction($node);
    }
    // ... 现有代码原样保留 ...
}
```

新增 `emitGeneratorFunction(FunctionNode $node): string` 方法（核心变换）：
```php
private function emitGeneratorFunction(FunctionNode $node): string
{
    $fnCName = self::funcCName($node);
    $entryName = 'tphp_gen_' . $fnCName . '_entry';
    $paramsStruct = '_gen_params_' . $fnCName;

    // 1) 保存当前状态
    $savedDeclaredVars = $this->declaredVars;
    $savedVarTypes = $this->varTypes;
    $savedCurrentRetType = $this->currentRetType;
    $savedInGenerator = $this->inGenerator;

    // 2) 重置作用域
    $this->declaredVars = [];
    $this->varTypes = [];
    $this->symbols->clearScopeObjects();
    $this->symbols->clearScopeVars();
    $this->funcScopeDecls = [];
    $this->currentRetType = 't_var';   // 生成器内 return 表达式按 t_var 处理
    $this->inGenerator = true;

    // 3) 注册参数到局部变量表
    $paramFields = [];
    $paramVars = [];
    foreach ($node->params as $p) {
        $vn = self::varName($p->name);
        $ct = self::paramCType($p);
        $this->declaredVars[$vn] = true;
        $this->varTypes[$vn] = $ct;
        $paramVars[$vn] = true;
        $paramFields[] = "    {$ct} {$vn};";
    }

    // 4) 生成协程入口函数体
    $entryHeader = "static void {$entryName}(mco_coro* co) {";
    $unpackLines = [];
    $unpackLines[] = '    ' . $paramsStruct . '* _p = (' . $paramsStruct . '*)mco_get_user_data(co);';
    foreach ($node->params as $p) {
        $vn = self::varName($p->name);
        $ct = self::paramCType($p);
        $unpackLines[] = "    {$ct} {$vn}_tmp = _p->{$vn};";
    }
    $unpackLines[] = '    free(_p);';
    // 复制到正式局部变量名（避免被 generateScopeCleanup 误释放参数）
    foreach ($node->params as $p) {
        $vn = self::varName($p->name);
        $unpackLines[] = "    {$vn} = {$vn}_tmp;";
    }

    // for 循环提升声明
    $declLines = [];
    foreach ($this->funcScopeDecls as $vn => $ct) {
        $declLines[] = "    {$ct} {$vn};";
    }

    $bodyLines = [];
    foreach ($node->body as $s) {
        $bodyLines[] = $this->ind($s->accept($this));
    }

    // 末尾释放
    $tail = $this->generateScopeCleanup($paramVars);
    foreach ($this->symbols->scopeObjects() as $ov) {
        $tail[] = "    tp_obj_release({$ov});";
    }

    // 5) 生成包装函数
    $paramDecls = array_map(fn($p) => self::paramDecl($p), $node->params);
    $paramAssigns = [];
    foreach ($node->params as $p) {
        $vn = self::varName($p->name);
        $paramAssigns[] = "    _p->{$vn} = {$vn};";
    }

    $wrapperHeader = "tphp_class_Generator* {$fnCName}(" . implode(', ', $paramDecls) . ") {";
    $wrapperBody = [
        "    {$paramsStruct}* _p = ({$paramsStruct}*)calloc(1, sizeof({$paramsStruct}));",
        ...$paramAssigns,
        "    mco_desc desc = mco_desc_init({$entryName}, 0);",
        "    desc.user_data = _p;",
        "    mco_coro* co;",
        "    if (mco_create(&co, &desc) != MCO_SUCCESS) { free(_p); return NULL; }",
        "    return new_tphp_class_Generator(co);",
        "}",
    ];

    // 6) 参数结构体 typedef（输出到 SEC_TYPES）
    $typedef = "typedef struct {\n" . implode("\n", $paramFields) . "\n} {$paramsStruct};";
    $this->sectionLine(self::SEC_TYPES, $typedef);

    // 7) 恢复状态
    $this->declaredVars = $savedDeclaredVars;
    $this->varTypes = $savedVarTypes;
    $this->currentRetType = $savedCurrentRetType;
    $this->inGenerator = $savedInGenerator;

    // 8) 组装入口函数 + 包装函数
    $entryFn = $entryHeader . "\n" .
        implode("\n", $unpackLines) . "\n" .
        implode("\n", $declLines) . "\n" .
        implode("\n", $bodyLines) . "\n" .
        implode("\n", array_map(fn($l) => '    ' . $l, $tail)) . "\n}";
    $wrapperFn = $wrapperHeader . "\n" . implode("\n", $wrapperBody);

    return $entryFn . "\n\n" . $wrapperFn;
}
```

**说明**：
- SEC_TYPES 需确认存在（若不存在，改输出到 SEC_FUNCFWDS 顶部，或新增 SEC_GENPARAMS）。先 grep `SEC_TYPES` 确认。
- `mco_get_user_data`、`mco_desc_init`、`mco_create` 均在 minicoro.h 中声明。
- macOS+TCC stub 路径中 `mco_coro` 是 stub 类型，但 `mco_create` 为 stub no-op 返回 MCO_SUCCESS，`mco_get_user_data` 返回 NULL —— 包装函数仍能返回非 NULL Generator 对象（运行时不可用但不影响编译）。

### 步骤 4.6：添加 visitYieldExpr
**位置**：在 [visitReturnStmt](file:///c:/project/php/TinyPHP/src/CodeGenerator.php#L1018) 之后或 visitFunction 附近

```php
public function visitYieldExpr(YieldExpr $node): string
{
    // 1) 计算 value（转 t_var）
    $valCode = $node->value !== null ? $node->value->accept($this) : 'VAR_NULL()';
    if ($node->value !== null) {
        $valType = $this->inferType($node->value);
        $valVar = $this->wrapToTvar($node->value, $valCode, $valType);
    } else {
        $valVar = 'VAR_NULL()';
    }

    // 2) 计算 key
    if ($node->key !== null) {
        $keyCode = $node->key->accept($this);
        $keyType = $this->inferType($node->key);
        $keyExpr = $this->wrapToTvar($node->key, $keyCode, $keyType);
    } else {
        // 自动递增 int key
        $keyExpr = '((t_var){.type = TYPE_INT, .value._int = _auto_key++})';
    }

    // 3) 生成 push + yield + pop sent
    $lines = [];
    $lines[] = '{';
    $lines[] = '    _gen_yield_pair _yp;';
    $lines[] = '    _yp.key = ' . $keyExpr . ';';
    $lines[] = '    _yp.value = ' . $valVar . ';';
    $lines[] = '    mco_push(mco_running(), &_yp, sizeof(_yp));';
    $lines[] = '    mco_yield(mco_running());';
    $lines[] = '    t_var _sent;';
    $lines[] = '    if (mco_pop(mco_running(), &_sent, sizeof(t_var)) != MCO_SUCCESS) {';
    $lines[] = '        _sent = VAR_NULL();';
    $lines[] = '    }';
    $lines[] = '    /* yield 表达式值为 _sent，按上下文转换 */';
    $lines[] = '}';
    return implode("\n", $lines);
}
```

**关键问题**：`yield` 作为表达式需要返回一个值给上层使用（如 `$x = yield 5;`）。但 `mco_yield` 之后才能拿到 sent 值，且 `_sent` 是 t_var，需转换为 yield 表达式期望的 C 类型。

**简化方案（MVP）**：暂不实现 `$x = yield 5` 的双向传值（仅生成器函数内能读取 sent 值）。先支持 `yield $v;`（语句形式）和 `yield $k => $v;`，sent 值忽略。`generator_send.php` 测试用 `$gen->send()` 在**外部**读取 yield 值，但生成器内部不读取 sent 值——这样能简化实现。

**若用户希望完整支持 `$x = yield`**：需引入 yield-as-expression 的特殊处理（生成代码块 + 临时变量），改动较大。MVP 阶段建议先支持语句形式，send 测试仅验证外部接收 yield 值。

**决策点**：yield-as-expression 的支持范围。
- 选项 A：仅支持 `yield expr;` 语句形式（最简单，能通过 generator_basic + generator_return 测试）
- 选项 B：支持 `$x = yield expr;` 双向（能通过 generator_send 测试，但实现复杂）
- 选项 C：A + B 都支持（最完整）

→ 推荐 **选项 C**：完整 MVP。`yield` 表达式返回 `_sent` 的 t_var 值，由 `visitAssign` 的 t_var 路径处理（`wrapTvarAssign` 在第 4213 行对 t_var→t_var 是 identity）。

实现 yield-as-expression：
- `visitYieldExpr` 返回字符串 `"_yield_sent"`（一个标识符），并在生成代码前先输出一个语句块 + 声明 `t_var _yield_sent = ...;`
- 但这会破坏表达式上下文（不能在 `return yield 5;` 中嵌入语句块）

**更稳妥方案**：使用 GCC statement expression `({ ... _sent; })`，但 TCC 与 MSVC 兼容性需确认。TCC 支持 statement expression。

→ 采用 statement expression：
```php
public function visitYieldExpr(YieldExpr $node): string
{
    // ...计算 $keyExpr, $valVar...
    return "({ _gen_yield_pair _yp; _yp.key = {$keyExpr}; _yp.value = {$valVar}; " .
           "mco_push(mco_running(), &_yp, sizeof(_yp)); mco_yield(mco_running()); " .
           "t_var _sent; if (mco_pop(mco_running(), &_sent, sizeof(t_var)) != MCO_SUCCESS) { _sent = VAR_NULL(); } _sent; })";
}
```

返回的 t_var 值交给外层（如赋值、return、参数）按 t_var 处理。

### 步骤 4.7：修改 visitReturnStmt 处理生成器上下文
**位置**：[第 1018 行](file:///c:/project/php/TinyPHP/src/CodeGenerator.php#L1018)

```php
public function visitReturnStmt(ReturnStmtNode $node): string
{
    if ($this->inGenerator) {
        // 生成器内：push 返回值（t_var），然后裸 return;
        if ($node->expr !== null) {
            $code = $node->expr->accept($this);
            $valVar = $this->wrapToTvar($node->expr, $code, $this->inferType($node->expr));
            return "{ t_var _r = {$valVar}; mco_push(mco_running(), &_r, sizeof(t_var)); return; }";
        }
        return "{ t_var _r = VAR_NULL(); mco_push(mco_running(), &_r, sizeof(t_var)); return; }";
    }
    // 现有代码（零改动）
    if ($node->expr) {
        if ($node->expr instanceof VariableExpr) {
            $vn = self::varName($node->expr->name);
            $this->symbols->addReturnedVar($vn);
        }
        $code = $node->expr->accept($this);
        if ($this->currentRetType === 't_var') {
            $code = $this->wrapTvarAssign($node->expr, $code);
        }
        return 'return ' . $code . ';';
    }
    return 'return;';
}
```

**说明**：需新增辅助方法 `wrapToTvar(ExprNode $expr, string $code, string $type): string`，将任意类型转换为 t_var 字面量。若已存在类似方法（如 `wrapTvarAssign` 的反向），复用之。先 grep 确认。

### 步骤 4.8：修改 visitForeachStmt 支持 Generator 迭代
**位置**：[第 3527 行](file:///c:/project/php/TinyPHP/src/CodeGenerator.php#L3527)

在方法开头加分支：
```php
public function visitForeachStmt(ForeachStmtNode $node): string
{
    $iterType = $this->inferType($node->array);
    if (str_contains($iterType, 'tphp_class_Generator')) {
        return $this->emitGeneratorForeach($node, $iterType);
    }
    // ... 现有数组循环代码原样保留 ...
}
```

新增 `emitGeneratorForeach`：
```php
private function emitGeneratorForeach(ForeachStmtNode $node, string $iterType): string
{
    $g = $node->array->accept($this);
    $valVar = ltrim($node->valueVar, '$');
    $keyVar = $node->keyVar ? ltrim($node->keyVar, '$') : '';

    // 元素类型为 t_var（Generator 的 current() 返回 t_var）
    $elemType = 't_var';
    $needValDecl = !isset($this->declaredVars[$valVar]);
    $needKeyDecl = ($keyVar && !isset($this->declaredVars[$keyVar]));

    $this->declaredVars[$valVar] = true;
    $this->varTypes[$valVar] = $elemType;
    if ($keyVar) {
        $this->declaredVars[$keyVar] = true;
        $this->varTypes[$keyVar] = 't_var';
    }

    $lines = [];
    if ($needKeyDecl) $lines[] = "t_var {$keyVar};";
    if ($needValDecl) $lines[] = "t_var {$valVar};";
    $lines[] = "while (tphp_class_Generator_valid({$g})) {";
    if ($keyVar) {
        $lines[] = $this->ind("{$keyVar} = tphp_class_Generator_key({$g});");
    }
    $lines[] = $this->ind("{$valVar} = tphp_class_Generator_current({$g});");
    $this->scopeDepth++;
    foreach ($node->body as $s) $lines[] = $this->ind($s->accept($this));
    $this->scopeDepth--;
    $lines[] = $this->ind("tphp_class_Generator_next({$g});");
    $lines[] = '}';
    return implode("\n", $lines);
}
```

**说明**：`tphp_class_Generator_valid/current/key/next` 已在 [generator.h](file:///c:/project/php/TinyPHP/include/object/generator.h) 中实现，含自动 rewind 逻辑。

### 步骤 4.9：更新 inferCallReturnType
**位置**：[第 1346 行](file:///c:/project/php/TinyPHP/src/CodeGenerator.php#L1346) 函数开头

```php
private function inferCallReturnType(CallExpr $expr): string
{
    // NEW: 生成器函数返回 tphp_class_Generator*
    if ($expr->callee === null) {
        $fnCName = self::funcCNameFromName($expr->name);  // 需确认 helper 名称
        if ($fnCName && !empty($this->funcIsGenerator[$fnCName])) {
            return 'tphp_class_Generator*';
        }
    }
    // ... 现有代码原样保留 ...
}
```

**说明**：需先 grep 确认从函数名（如 `'gen'`）到 funcCName 的转换方式。SEC_FUNCFWDS 循环已用 `self::funcCName($fn)`，inferCallReturnType 中可能用 `$expr->name` 直接匹配。先读 inferCallReturnType 完整内容。

### 步骤 4.10：修改 visitMethod 与 visitClosure
**位置**：[visitMethod 第 796 行](file:///c:/project/php/TinyPHP/src/CodeGenerator.php#L796)、[visitClosure 第 1845 行](file:///c:/project/php/TinyPHP/src/CodeGenerator.php#L1845)

参照 visitFunction 模式：
```php
public function visitMethod(MethodNode $node): string
{
    if ($node->isGenerator) {
        return $this->emitGeneratorMethod($node);
    }
    // ... 现有代码 ...
}
```

`emitGeneratorMethod` 与 `emitGeneratorFunction` 类似，但：
- 入口函数需额外接收 `self` 指针（通过 params 结构体首字段）
- 包装函数签名包含 `tphp_class_<ClassName>* self` 作为首参数
- 方法注册到 `classMethodRetTypes[$className]`

**MVP 决策**：方法生成器较为复杂（需处理 self 传递、父类继承等）。若测试仅需独立函数生成器（generator_basic/send/return 均为独立函数），可推迟方法生成器到下一迭代。

→ **推荐**：先实现独立函数生成器，方法生成器与闭包生成器标记为 TODO。如果测试通过且时间允许，再补充方法与闭包。

### 步骤 7.1：创建测试文件

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
        $g = gen();
        foreach ($g as $v) {
            var_dump($v);
        }
        var_dump($g->getReturn());
    }
}
```

#### test/features/generator_send.php（需 yield-as-expression 支持）
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
        var_dump($gen->current());
        var_dump($gen->send(10));
        var_dump($gen->send(20));
    }
}
```

### 步骤 7.2：本地验证
```cmd
cd c:\project\php\TinyPHP
php tphp.php test/features/generator_basic.php --debug -cc tcc
php tphp.php test/features/generator_basic.php --debug -cc gcc
php tphp.php test/features/generator_basic.php --debug -cc clang
php tphp.php test/features/generator_return.php --debug -cc tcc
php tphp.php test/features/generator_send.php --debug -cc tcc
```
预期：三编译器均输出与 `#debug` 行匹配的 int() 值。

### 步骤 7.3：回归测试
```cmd
php .github/scripts/run_tests.php
```
特别关注：Parser/CodeGenerator 改动是否影响非生成器代码。选 2–3 个现有非生成器测试，对比改动前后 `build/main.c` 字节一致（零开销抽样验证）。

## 需在实施时先确认的细节
1. **SEC_TYPES 是否存在**：grep `SEC_TYPES` 在 CodeGenerator.php 中的定义。若不存在，参数结构体 typedef 输出到 SEC_FUNCFWDS 顶部。
2. **从函数名查 funcCName 的 helper**：读 `inferCallReturnType` 完整内容，确认它如何匹配独立函数（可能直接用 `$expr->name`，因为 funcCName 通常是 `tphp_fn_<name>`）。
3. **wrapToTvar 辅助方法**：grep `wrapTvarAssign`、`asTvar` 等，确认是否已有 ExprNode + 类型 → t_var 字面量的转换辅助。
4. **SymbolTable::addClass 签名**：确认是否支持第二参数 parent 类名（File 类用了 `'tphp_class_Resource'`）。
5. **MethodInfo 构造器**：确认第二参数是参数类型数组（File 类的 `__construct` 用了 `['t_string', 't_string']`）。

## 假设与决策
1. **yield-as-expression**：采用 GCC statement expression `({ ...; _sent; })`，TCC/GCC/Clang 均支持。MSVC 不支持但项目目标平台不含 MSVC。
2. **方法生成器**：MVP 推迟。先实现独立函数生成器与方法生成器框架（`visitMethod` 分支），但若实现成本高则仅支持独立函数。
3. **闭包生成器**：MVP 推迟。`visitClosure` 的 `isGenerator` 分支标记 TODO，遇到闭包内 yield 时报错「closures with yield not yet supported」。
4. **自动 key 计数器**：使用生成器入口函数的局部变量 `int _auto_key = 0;`，在 funcScopeDecls 中注入。需在 `emitGeneratorFunction` 开头手动添加此声明。
5. **macOS+TCC stub 行为**：编译通过，运行时 Generator 方法返回 NULL/0。该平台的 generator 测试不要求通过（CI 标注 skip 或接受失败）。
6. **零开销保证**：
   - `visitFunction`/`visitMethod`/`visitClosure`：`if (!$node->isGenerator)` 分支保留原逻辑
   - `visitReturnStmt`：`if (!$this->inGenerator)` 分支保留原逻辑
   - `visitForeachStmt`：`if (!str_contains($iterType, 'tphp_class_Generator'))` 分支保留原逻辑
   - `visitYieldExpr`：仅被生成器函数体调用
   - 预扫描 `preScanGenerators` 仅遍历函数节点，对非生成器代码无副作用
   - `$typeMap` 添加 `'Generator'` 不影响其他类型映射

## 验证步骤
1. **本地三编译器**：运行 generator_basic.php、generator_return.php、generator_send.php，预期 `#debug` 全部匹配
2. **回归**：`php .github/scripts/run_tests.php` 全绿
3. **零开销抽样**：选 `test/features/closure.php`、`test/features/foreach.php`、`test/features/exception.php` 三个非生成器测试，对比改动前后 `build/main.c` 字节一致（diff 为空）
4. **CI**：推送分支触发 workflow_dispatch，预期 11/12 平台通过（macOS+TCC 接受 stub 失败）

## 关键文件清单（剩余改动）
| 文件 | 改动类型 | 说明 |
|------|----------|------|
| `src/CodeGenerator.php` | 修改 | $typeMap、resetState 注册、preScanGenerators、SEC_FUNCFWDS 覆盖、visitFunction/Method/Closure 分支、visitYieldExpr、visitReturnStmt、visitForeachStmt、inferCallReturnType |
| `test/features/generator_basic.php` | 新增 | yield + foreach 测试 |
| `test/features/generator_return.php` | 新增 | getReturn() 测试 |
| `test/features/generator_send.php` | 新增 | send() 双向通信测试 |

## 风险与缓解
1. **statement expression 兼容性**：TCC 与 Clang 支持，但若某平台报错则降级为语句形式（仅支持 `yield;` 不支持 `$x = yield;`）
2. **generateScopeCleanup 误释放 yield 后存活的局部变量**：协程挂起时局部字符串/数组仍需存活。`generateScopeCleanup` 仅在函数末尾运行，挂起期间不释放——但若生成器提前 return 或抛异常，需确认释放路径。**缓解**：协程入口函数末尾的 generateScopeCleanup 仅在协程正常结束时运行；异常路径由 dtor 的 `mco_destroy` 释放协程栈（不调用 C 析构）——可能泄漏。需确认是否在 yield 表达式周围用 try-finally 包裹，或接受 MVP 阶段的小范围泄漏。
3. **`_auto_key` 在嵌套作用域**：若 yield 在嵌套块内（if/while 内），`_auto_key` 仍是函数级局部变量，无作用域问题。
4. **CodeGenerator 状态污染**：`emitGeneratorFunction` 保存/恢复 declaredVars、varTypes、currentRetType、inGenerator。其他状态（如 arrElementTypes、classFieldTypes）若被生成器体修改，需同样保存恢复——MVP 先观察测试结果再补。
