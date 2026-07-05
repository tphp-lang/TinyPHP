# Yield/Generator 修复计划 — emitGeneratorForeach 缓存迭代器

## 背景
本会话延续上一个被压缩上下文的会话。已完成的步骤：
- 步骤 1–3 ✅ Lexer/AST/Parser
- 步骤 5 ✅ 运行时（[include/object/generator.h](file:///c:/project/php/TinyPHP/include/object/generator.h)、[include/minicoro.h](file:///c:/project/php/TinyPHP/include/minicoro.h)、[include/common.h](file:///c:/project/php/TinyPHP/include/common.h#L21) include）
- 步骤 4 ✅ CodeGenerator 主体（`$funcIsGenerator`/`$inGenerator` 属性、`preScanGenerators`、`emitGeneratorFunction`、`visitYieldExpr`、`visitReturnStmt` inGenerator 分支、`visitForeachStmt` Generator 分支）
- 步骤 7.1 ✅ 3 个测试文件已创建（[test/generator/generator_basic.php](file:///c:/project/php/TinyPHP/test/generator/generator_basic.php)、[generator_return.php](file:///c:/project/php/TinyPHP/test/generator/generator_return.php)、[generator_send.php](file:///c:/project/php/TinyPHP/test/generator/generator_send.php)）

**唯一未完成的步骤**：步骤 4.8 的 `emitGeneratorForeach` 存在 bug，步骤 7.2/7.3 测试验证未跑通。

## 当前问题

[src/CodeGenerator.php 第 3847–3877 行](file:///c:/project/php/TinyPHP/src/CodeGenerator.php#L3847-L3877) 的 `emitGeneratorForeach`：

```php
private function emitGeneratorForeach(ForeachStmtNode $node): string
{
    $g = $node->array->accept($this);   // 仅生成 C 代码字符串，并未求值
    // ...
    $lines[] = "while (tphp_class_Generator_valid({$g})) {";       // 嵌入 $g
    $lines[] = $this->ind("{$keyVar} = tphp_class_Generator_key({$g});");   // 再次嵌入
    $lines[] = $this->ind("{$valVar} = tphp_class_Generator_current({$g});");  // 再次嵌入
    $lines[] = $this->ind("tphp_class_Generator_next({$g});");     // 再次嵌入
```

`$g` 是一个 C 表达式字符串（如 `tphp_fn_gen()`），每次嵌入都会产生一次新的函数调用。导致每次循环迭代的 `valid/current/next` 各创建一个**新**的 Generator 对象，从而永远拿到第一个 yield 值。

测试输出（错误）：
```
[YES] int(1)
[FAIL] --debug mismatch at line 2
  expected: int(2)
  got     : int(1)
```

## 修复方案

将迭代器表达式求值**一次**并存入临时变量 `_gen_iter_N`，后续所有 `tphp_class_Generator_*` 调用统一引用该变量。

### 改动文件
[src/CodeGenerator.php](file:///c:/project/php/TinyPHP/src/CodeGenerator.php) — 仅修改 `emitGeneratorForeach` 方法（第 3847–3877 行）。

### 修改后代码

```php
/** 生成器 foreach：while (valid) { key/current; body; next; } */
private function emitGeneratorForeach(ForeachStmtNode $node): string
{
    // 评估 iterable 表达式一次，存入临时变量（避免每次循环重建生成器）
    $gExpr = $node->array->accept($this);
    $gTmp = '_gen_iter_' . (++$this->tmpVarCounter);

    $valVar = ltrim($node->valueVar, '$');
    $keyVar = $node->keyVar ? ltrim($node->keyVar, '$') : '';

    $needValDecl = !isset($this->declaredVars[$valVar]);
    $needKeyDecl = ($keyVar && !isset($this->declaredVars[$keyVar]));

    $this->declaredVars[$valVar] = true;
    $this->varTypes[$valVar] = 't_var';
    if ($keyVar) {
        $this->declaredVars[$keyVar] = true;
        $this->varTypes[$keyVar] = 't_var';
    }

    $lines = [];
    $lines[] = "tphp_class_Generator* {$gTmp} = {$gExpr};";
    if ($needKeyDecl) $lines[] = "t_var {$keyVar};";
    if ($needValDecl) $lines[] = "t_var {$valVar};";
    $lines[] = "while (tphp_class_Generator_valid({$gTmp})) {";
    if ($keyVar) {
        $lines[] = $this->ind("{$keyVar} = tphp_class_Generator_key({$gTmp});");
    }
    $lines[] = $this->ind("{$valVar} = tphp_class_Generator_current({$gTmp});");
    $this->scopeDepth++;
    foreach ($node->body as $s) $lines[] = $this->ind($s->accept($this));
    $this->scopeDepth--;
    $lines[] = $this->ind("tphp_class_Generator_next({$gTmp});");
    $lines[] = '}';
    return implode("\n", $lines);
}
```

### 关键改动点
1. 新增 `$gTmp = '_gen_iter_' . (++$this->tmpVarCounter);`（沿用项目既有的临时变量命名前缀风格，见 `_fc_`/`_fi_`/`_tmp_` 等，第 3741–3742 行）
2. 新增 `$lines[] = "tphp_class_Generator* {$gTmp} = {$gExpr};";` 在 while 之前求值一次
3. 所有 4 处 `{$g}` 改为 `{$gTmp}`

### 零开销保证
- 此修改仅在 `iterType` 包含 `tphp_class_Generator` 时进入（[第 3736 行](file:///c:/project/php/TinyPHP/src/CodeGenerator.php#L3736) 的 `str_contains` 检查），非生成器 foreach 走原数组循环路径，完全不受影响。

## 验证步骤

### 1. 单测试用例验证（TCC，省略 `-cc` 用内置 TCC）
```cmd
cd c:\project\php\TinyPHP
php tphp.php test/generator/generator_basic.php --debug
```
预期输出（3 行 YES）：
```
[YES] int(1)
[YES] int(2)
[YES] int(3)
```

### 2. 其余两个生成器测试
```cmd
php tphp.php test/generator/generator_return.php --debug
php tphp.php test/generator/generator_send.php --debug
```
- `generator_return.php` 预期：`int(1) int(2) int(99)`
- `generator_send.php` 预期：`int(1) int(2) int(3)`

### 3. GCC 与 Clang 兼容性（generator_basic.php）
```cmd
php tphp.php test/generator/generator_basic.php --debug -cc gcc
php tphp.php test/generator/generator_basic.php --debug -cc clang
```
预期：均输出 `int(1) int(2) int(3)`。

### 4. 全量回归测试
```cmd
php .github/scripts/run_tests.php
```
预期：所有原有测试仍通过（零开销验证）。Generator 测试中除 macOS+TCC stub 路径外的所有平台应通过。

## 假设与决策
1. **macOS+TCC 配置**：用户已确认仅此一个配置不支持（minicoro stub 路径），不影响整体实现。运行时该平台 Generator 方法返回 NULL/0，测试可接受失败或标注 `@skip`。
2. **不修改其他文件**：除 `emitGeneratorForeach` 方法体外，不动任何其他代码、测试或文档。
3. **不动既有 plan 文档**：原 `yield-generator-codegen-execution.md` 保留作为历史记录，本计划为最终收尾步骤的执行依据。
