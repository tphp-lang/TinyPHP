<?php

declare(strict_types=1);

// ============================================================
// FlatTypeChecker 单元测试
//
// 运行方式：
//   cd c:\project\php\TinyPHP
//   php tools/AST/flat_typecheck_test.php
//
// 测试范围：
//   1. 字面量类型推导（int/float/string/bool/null）
//   2. 变量声明与类型追踪（AssignStmt 注册变量到作用域）
//   3. 二元运算类型推导（int+int=int, float+int=float, string.=string）
//   4. 函数定义的类型检查（参数类型注册、返回类型记录）
//   5. 函数调用的返回类型推导（查 SymbolTable 函数签名）
//   6. 简单错误检测（未定义变量、return 类型不匹配、赋值类型不匹配）
//   7. 作用域管理（if/while/for 块作用域）
//
// 测试方式：Parser 解析 PHP 代码 → FlatAstConverter 转换 →
//          FlatTypeChecker 检查 → 验证节点的 typ 字段（Type::idx()）
// ============================================================

require_once __DIR__ . '/../../src/TokenType.php';
require_once __DIR__ . '/../../src/Token.php';
require_once __DIR__ . '/../../src/AST/Node.php';
require_once __DIR__ . '/../../src/AST/FlatAst.php';
require_once __DIR__ . '/../../src/AST/FlatAstConverter.php';
require_once __DIR__ . '/../../src/Lexer.php';
require_once __DIR__ . '/../../src/Parser.php';
require_once __DIR__ . '/../../src/Type.php';
require_once __DIR__ . '/../../src/SymbolTable.php';
require_once __DIR__ . '/../../src/FlatTypeChecker.php';

$pass = 0;
$fail = 0;
$failures = [];

function check(bool $cond, string $label): void
{
    global $pass, $fail, $failures;
    if ($cond) {
        $pass++;
    } else {
        $fail++;
        $failures[] = $label;
        echo "[FAIL] $label\n";
    }
}

/** 解析 PHP 源码并返回 FlatAst */
function parseToFlatAst(string $source): FlatAst
{
    $lexer = new Lexer($source);
    $tokens = $lexer->tokenize();
    $parser = new Parser($tokens);
    return $parser->parseToFlatAst();
}

/** 在 FlatAst 中按 kind 深度查找第一个匹配的节点索引 */
function findFirstByKind(FlatAst $ast, NodeKind $kind): int
{
    foreach ($ast->nodes as $idx => $node) {
        if ($node['kind'] === $kind) return $idx;
    }
    return -1;
}

/** 按深度优先前序查找第一个匹配 kind 的节点索引（用于确保从 root 可达） */
function findFirstDFS(FlatAst $ast, NodeKind $kind): int
{
    if ($ast->root < 0) return -1;
    $stack = [$ast->root];
    while ($stack !== []) {
        $idx = array_pop($stack);
        $node = $ast->nodes[$idx];
        if ($node['kind'] === $kind) return $idx;
        for ($i = $node['children_count'] - 1; $i >= 0; $i--) {
            $stack[] = $ast->children[$node['children_start'] + $i];
        }
    }
    return -1;
}

// 类型 idx 常量（来自 Type 类）
$IDX_INT    = Type::IDX_INT;
$IDX_FLOAT  = Type::IDX_FLOAT;
$IDX_STRING = Type::IDX_STRING;
$IDX_BOOL   = Type::IDX_BOOL;
$IDX_NULL   = Type::IDX_NULL;
$IDX_ARRAY  = Type::IDX_ARRAY;
$IDX_MIXED  = Type::IDX_MIXED;
$IDX_OBJECT = Type::IDX_OBJECT;
$IDX_CALLBACK = Type::IDX_CALLBACK;

// ─────────────────────────────────────────────────────────────
// 测试 1: 字面量类型推导
//   function f(): void { echo 42; echo 3.14; echo "hi"; echo true; echo null; }
//   验证：
//   - IntLiteralExpr.typ = IDX_INT
//   - FloatLiteralExpr.typ = IDX_FLOAT
//   - StringLiteralExpr.typ = IDX_STRING
//   - BoolLiteralExpr.typ = IDX_BOOL
//   - NullLiteralExpr.typ = IDX_NULL
// ─────────────────────────────────────────────────────────────
$src1 = '<?php function f(): void { echo 42; echo 3.14; echo "hi"; echo true; echo null; }';
$ast1 = parseToFlatAst($src1);
$symtab1 = new SymbolTable();
$checker1 = new FlatTypeChecker($ast1, $symtab1);
$checker1->check();

$intLit = findFirstDFS($ast1, NodeKind::IntLiteralExpr);
$floatLit = findFirstDFS($ast1, NodeKind::FloatLiteralExpr);
$strLit = findFirstDFS($ast1, NodeKind::StringLiteralExpr);
$boolLit = findFirstDFS($ast1, NodeKind::BoolLiteralExpr);
$nullLit = findFirstDFS($ast1, NodeKind::NullLiteralExpr);

check($intLit >= 0, '测试1: 找到 IntLiteralExpr 节点');
check($ast1->nodes[$intLit]['typ'] === $IDX_INT, '测试1: IntLiteral.typ = IDX_INT(' . $IDX_INT . '), 实际=' . $ast1->nodes[$intLit]['typ']);
check($ast1->nodes[$floatLit]['typ'] === $IDX_FLOAT, '测试1: FloatLiteral.typ = IDX_FLOAT');
check($ast1->nodes[$strLit]['typ'] === $IDX_STRING, '测试1: StringLiteral.typ = IDX_STRING');
check($ast1->nodes[$boolLit]['typ'] === $IDX_BOOL, '测试1: BoolLiteral.typ = IDX_BOOL');
check($ast1->nodes[$nullLit]['typ'] === $IDX_NULL, '测试1: NullLiteral.typ = IDX_NULL');

// ─────────────────────────────────────────────────────────────
// 测试 2: 变量声明与类型追踪
//   function f(): void { $x = 42; $y = $x; }
//   验证：
//   - 第一条 AssignStmt 推导 RHS=int，注册 $x=int
//   - VariableExpr($x) 查到 int 类型
//   - 第二条 AssignStmt 把 $x 的 int 类型赋给 $y
// ─────────────────────────────────────────────────────────────
$src2 = '<?php function f(): void { $x = 42; $y = $x; }';
$ast2 = parseToFlatAst($src2);
$checker2 = new FlatTypeChecker($ast2, new SymbolTable());
$checker2->check();

// 找到所有 VariableExpr，应有两个：$x 和 $y
$varIdxs = [];
foreach ($ast2->nodes as $idx => $n) {
    if ($n['kind'] === NodeKind::VariableExpr) $varIdxs[] = $idx;
}
check(count($varIdxs) === 1, '测试2: 只有一个 VariableExpr（$x，$y 是 AssignStmt.value）');

// $x 的 VariableExpr 类型应为 int
$varXIdx = $varIdxs[0];
check($ast2->nodes[$varXIdx]['value'] === '$x', '测试2: 找到的 VariableExpr 是 $x');
check($ast2->nodes[$varXIdx]['typ'] === $IDX_INT, '测试2: VariableExpr($x).typ = IDX_INT');

// 第二个 AssignStmt ($y = $x) 的 RHS 类型应该是 int（来自 $x 查找）
$assignStmts = [];
foreach ($ast2->nodes as $idx => $n) {
    if ($n['kind'] === NodeKind::AssignStmtNode) $assignStmts[] = $idx;
}
check(count($assignStmts) === 2, '测试2: 有 2 个 AssignStmt');
$assignY = $assignStmts[1];
check($ast2->nodes[$assignY]['value'] === '$y', '测试2: 第二个 AssignStmt 是 $y');
// $y = $x 中的 RHS（VariableExpr $x）的 typ 应为 int
$rhsY = $ast2->child($assignY, 0);
check($ast2->nodes[$rhsY]['typ'] === $IDX_INT, '测试2: $y = $x 的 RHS 类型为 int');

// ─────────────────────────────────────────────────────────────
// 测试 3: 二元运算类型推导
//   function f(): void { $a = 1 + 2; $b = 1.5 + 2; $c = "x" . "y"; $d = 1 < 2; }
//   验证：
//   - int + int = int
//   - float + int = float
//   - string . string = string
//   - int < int = bool
// ─────────────────────────────────────────────────────────────
$src3 = '<?php function f(): void { $a = 1 + 2; $b = 1.5 + 2; $c = "x" . "y"; $d = 1 < 2; }';
$ast3 = parseToFlatAst($src3);
$checker3 = new FlatTypeChecker($ast3, new SymbolTable());
$checker3->check();

// 找到所有 BinaryExpr 节点，按出现顺序
$binIdxs = [];
foreach ($ast3->nodes as $idx => $n) {
    if ($n['kind'] === NodeKind::BinaryExpr) $binIdxs[] = $idx;
}
check(count($binIdxs) === 4, '测试3: 有 4 个 BinaryExpr');

// 1+2 → int
check($ast3->nodes[$binIdxs[0]]['value'] === '+', '测试3: 第1个 BinaryExpr 是 +');
check($ast3->nodes[$binIdxs[0]]['typ'] === $IDX_INT, '测试3: 1+2 → int');

// 1.5+2 → float
check($ast3->nodes[$binIdxs[1]]['value'] === '+', '测试3: 第2个 BinaryExpr 是 +');
check($ast3->nodes[$binIdxs[1]]['typ'] === $IDX_FLOAT, '测试3: 1.5+2 → float');

// "x"."y" → string
check($ast3->nodes[$binIdxs[2]]['value'] === '.', '测试3: 第3个 BinaryExpr 是 .');
check($ast3->nodes[$binIdxs[2]]['typ'] === $IDX_STRING, '测试3: "x"."y" → string');

// 1<2 → bool
check($ast3->nodes[$binIdxs[3]]['value'] === '<', '测试3: 第4个 BinaryExpr 是 <');
check($ast3->nodes[$binIdxs[3]]['typ'] === $IDX_BOOL, '测试3: 1<2 → bool');

// ─────────────────────────────────────────────────────────────
// 测试 4: 函数定义的类型检查
//   function add(int $a, int $b): int { return $a + $b; }
//   验证：
//   - prescan 注册函数 add 到 SymbolTable（返回类型 t_int）
//   - 参数 $a, $b 在函数体内类型为 int
//   - return $a + $b 推导为 int，与声明 int 匹配（无错误）
// ─────────────────────────────────────────────────────────────
$src4 = '<?php function add(int $a, int $b): int { return $a + $b; }';
$ast4 = parseToFlatAst($src4);
$symtab4 = new SymbolTable();
$checker4 = new FlatTypeChecker($ast4, $symtab4);
$checker4->check();

// SymbolTable 中应能查到 tphp_fn_add
$fnInfo = $symtab4->getFunc('tphp_fn_add');
check($fnInfo !== null, '测试4: prescan 注册了 tphp_fn_add');
check($fnInfo->retType === 't_int', '测试4: add 返回类型 = t_int');
check(count($fnInfo->paramTypes) === 2, '测试4: add 有 2 个参数');
check($fnInfo->paramTypes[0] === 't_int', '测试4: add 参数[0] 类型 = t_int');
check($fnInfo->paramTypes[1] === 't_int', '测试4: add 参数[1] 类型 = t_int');

// 函数体中 $a + $b 应推导为 int
$binIdx = findFirstDFS($ast4, NodeKind::BinaryExpr);
check($ast4->nodes[$binIdx]['typ'] === $IDX_INT, '测试4: $a + $b 推导为 int');

// return 类型匹配，无错误
check(count($checker4->getErrors()) === 0, '测试4: 函数 add 无类型错误（return int 匹配）');

// ─────────────────────────────────────────────────────────────
// 测试 5: 函数调用的返回类型推导
//   function add(int $a, int $b): int { return $a + $b; }
//   function main(): void { $r = add(1, 2); }
//   验证：CallExpr(add) → 查 SymbolTable 返回 int，typ=IDX_INT
// ─────────────────────────────────────────────────────────────
$src5 = '<?php function add(int $a, int $b): int { return $a + $b; } function main(): void { $r = add(1, 2); }';
$ast5 = parseToFlatAst($src5);
$checker5 = new FlatTypeChecker($ast5, new SymbolTable());
$checker5->check();

$callIdx = findFirstDFS($ast5, NodeKind::CallExpr);
check($callIdx >= 0, '测试5: 找到 CallExpr 节点');
check($ast5->nodes[$callIdx]['value'] === 'add', '测试5: CallExpr 调用的是 add');
check($ast5->nodes[$callIdx]['typ'] === $IDX_INT, '测试5: add(1,2) 返回类型 = IDX_INT');

// ─────────────────────────────────────────────────────────────
// 测试 6: 简单错误检测 — return 类型不匹配
//   function bad(): int { return "string"; }
//   验证：返回 string 与声明 int 不匹配 → 收集错误
// ─────────────────────────────────────────────────────────────
$src6 = '<?php function bad(): int { return "string"; }';
$ast6 = parseToFlatAst($src6);
$checker6 = new FlatTypeChecker($ast6, new SymbolTable());
$checker6->check();

$errors6 = $checker6->getErrors();
check(count($errors6) >= 1, '测试6: return 类型不匹配产生至少 1 个错误');
if (count($errors6) >= 1) {
    check(str_contains($errors6[0]['msg'], 'Return type mismatch') || str_contains($errors6[0]['msg'], 'mismatch'),
        '测试6: 错误信息包含 "Return type mismatch" 或 "mismatch"');
}

// ─────────────────────────────────────────────────────────────
// 测试 7: 简单错误检测 — 赋值类型不匹配
//   function f(): void { int $x = "string"; }
//   验证：显式声明 int $x 但赋值 string → 收集错误
// ─────────────────────────────────────────────────────────────
$src7 = '<?php function f(): void { int $x = "string"; }';
$ast7 = parseToFlatAst($src7);
$checker7 = new FlatTypeChecker($ast7, new SymbolTable());
$checker7->check();

$errors7 = $checker7->getErrors();
check(count($errors7) >= 1, '测试7: int $x = "string" 产生至少 1 个错误');
if (count($errors7) >= 1) {
    check(str_contains($errors7[0]['msg'], 'Cannot assign') || str_contains($errors7[0]['msg'], 'assign'),
        '测试7: 错误信息包含 "Cannot assign"');
}

// ─────────────────────────────────────────────────────────────
// 测试 8: 简单错误检测 — 未定义变量（严格模式）
//   function f(): void { echo $undefined; }
//   验证：strictUndefinedVar=true 时，访问未声明变量 → 收集错误
// ─────────────────────────────────────────────────────────────
$src8 = '<?php function f(): void { echo $undefined; }';
$ast8 = parseToFlatAst($src8);
$checker8 = new FlatTypeChecker($ast8, new SymbolTable());
$checker8->setStrictUndefinedVar(true);
$checker8->check();

$errors8 = $checker8->getErrors();
check(count($errors8) >= 1, '测试8: 严格模式下访问未声明变量产生错误');
if (count($errors8) >= 1) {
    check(str_contains($errors8[0]['msg'], 'Undefined variable'),
        '测试8: 错误信息包含 "Undefined variable"');
}

// 非严格模式下不产生错误（PHP 动态语义回退 mixed）
$ast8b = parseToFlatAst($src8);
$checker8b = new FlatTypeChecker($ast8b, new SymbolTable());
$checker8b->check();
check(count($checker8b->getErrors()) === 0, '测试8: 非严格模式下访问未声明变量不报错（动态语义）');
// 未声明变量的 typ 应为 mixed
$undefVarIdx = findFirstDFS($ast8b, NodeKind::VariableExpr);
check($ast8b->nodes[$undefVarIdx]['typ'] === $IDX_MIXED, '测试8: 未声明变量 typ = IDX_MIXED');

// ─────────────────────────────────────────────────────────────
// 测试 9: 作用域管理 — if 块内的变量不影响外层
//   function f(): void { if (true) { $inner = 42; } echo $inner; }
//   验证：$inner 在 if 块内声明，离开后作用域失效
//        严格模式下 echo $inner 应报未定义错误
// ─────────────────────────────────────────────────────────────
$src9 = '<?php function f(): void { if (true) { $inner = 42; } echo $inner; }';
$ast9 = parseToFlatAst($src9);
$checker9 = new FlatTypeChecker($ast9, new SymbolTable());
$checker9->setStrictUndefinedVar(true);
$checker9->check();

$errors9 = $checker9->getErrors();
$hasUndefinedInner = false;
foreach ($errors9 as $e) {
    if (str_contains($e['msg'], '$inner')) { $hasUndefinedInner = true; break; }
}
check($hasUndefinedInner, '测试9: if 块外访问 $inner 触发未定义错误（块作用域隔离）');

// ─────────────────────────────────────────────────────────────
// 测试 10: for 循环变量声明
//   function f(): void { for ($i = 0; $i < 10; $i++) { echo $i; } }
//   验证：for-init 的 $i = 0 声明 $i 为 int；循环体内 $i 的 typ = int
// ─────────────────────────────────────────────────────────────
$src10 = '<?php function f(): void { for ($i = 0; $i < 10; $i++) { echo $i; } }';
$ast10 = parseToFlatAst($src10);
$checker10 = new FlatTypeChecker($ast10, new SymbolTable());
$checker10->check();

// 找到 echo $i 中的 $i (VariableExpr)，验证 typ = int
$varIdxs = [];
foreach ($ast10->nodes as $idx => $n) {
    if ($n['kind'] === NodeKind::VariableExpr && $n['value'] === '$i') $varIdxs[] = $idx;
}
check(count($varIdxs) >= 3, '测试10: 至少有 3 个 $i 引用（init/cond/body）');
// 循环体内 echo $i 的 $i 应为 int
$bodyVarIdx = $varIdxs[count($varIdxs) - 1];
check($ast10->nodes[$bodyVarIdx]['typ'] === $IDX_INT, '测试10: 循环体内 $i.typ = IDX_INT');

// ─────────────────────────────────────────────────────────────
// 测试 11: 类属性访问类型推导
//   class Foo { public int $x = 10; public function get(): int { return $this->x; } }
//   验证：$this->x 通过 SymbolTable.getClassPropType 查到 t_int → typ=IDX_INT
// ─────────────────────────────────────────────────────────────
$src11 = '<?php class Foo { public int $x = 10; public function get(): int { return $this->x; } }';
$ast11 = parseToFlatAst($src11);
$checker11 = new FlatTypeChecker($ast11, new SymbolTable());
$checker11->check();

$paIdx = findFirstDFS($ast11, NodeKind::PropertyAccessExpr);
check($paIdx >= 0, '测试11: 找到 PropertyAccessExpr');
check($ast11->nodes[$paIdx]['value'] === 'x', '测试11: 属性名为 x');
check($ast11->nodes[$paIdx]['typ'] === $IDX_INT, '测试11: $this->x.typ = IDX_INT（查类属性类型）');

// ─────────────────────────────────────────────────────────────
// 测试 12: 类方法调用返回类型推导
//   class Calc { public function get(): int { return 42; } }
//   function f(): void { $c = new Calc(); $r = $c->get(); }
//   验证：$c->get() 通过 varClassCNames + getClassMethod 查到返回 int
// ─────────────────────────────────────────────────────────────
$src12 = '<?php class Calc { public function get(): int { return 42; } } function f(): void { $c = new Calc(); $r = $c->get(); }';
$ast12 = parseToFlatAst($src12);
$checker12 = new FlatTypeChecker($ast12, new SymbolTable());
$checker12->check();

// 找到方法调用 CallExpr（hasCallee=true），验证 typ = int
$callIdxs = [];
foreach ($ast12->nodes as $idx => $n) {
    if ($n['kind'] === NodeKind::CallExpr) $callIdxs[] = $idx;
}
// 第一个 CallExpr 可能是 new（不是 CallExpr，是 NewExpr）
// 实际只有 $c->get() 一个 CallExpr
check(count($callIdxs) === 1, '测试12: 有 1 个 CallExpr（$c->get()）');
$methodCallIdx = $callIdxs[0];
check($ast12->nodes[$methodCallIdx]['typ'] === $IDX_INT, '测试12: $c->get().typ = IDX_INT');

// ─────────────────────────────────────────────────────────────
// 测试 13: Cast 表达式类型推导
//   function f(): void { $a = (int)3.14; $b = (string)42; $c = (float)1; }
//   验证：(int)X → int, (string)X → string, (float)X → float
// ─────────────────────────────────────────────────────────────
$src13 = '<?php function f(): void { $a = (int)3.14; $b = (string)42; $c = (float)1; }';
$ast13 = parseToFlatAst($src13);
$checker13 = new FlatTypeChecker($ast13, new SymbolTable());
$checker13->check();

$castIdxs = [];
foreach ($ast13->nodes as $idx => $n) {
    if ($n['kind'] === NodeKind::CastExpr) $castIdxs[] = $idx;
}
check(count($castIdxs) === 3, '测试13: 有 3 个 CastExpr');
check($ast13->nodes[$castIdxs[0]]['value'] === 'int' && $ast13->nodes[$castIdxs[0]]['typ'] === $IDX_INT, '测试13: (int)3.14 → int');
check($ast13->nodes[$castIdxs[1]]['value'] === 'string' && $ast13->nodes[$castIdxs[1]]['typ'] === $IDX_STRING, '测试13: (string)42 → string');
check($ast13->nodes[$castIdxs[2]]['value'] === 'float' && $ast13->nodes[$castIdxs[2]]['typ'] === $IDX_FLOAT, '测试13: (float)1 → float');

// ─────────────────────────────────────────────────────────────
// 测试 14: New 表达式 + 接口/抽象类不可实例化
//   interface I {} function f(): void { $x = new I(); }
//   验证：实例化接口 → 收集错误；NewExpr.typ = IDX_OBJECT
// ─────────────────────────────────────────────────────────────
$src14 = '<?php interface I {} function f(): void { $x = new I(); }';
$ast14 = parseToFlatAst($src14);
$checker14 = new FlatTypeChecker($ast14, new SymbolTable());
$checker14->check();

$newIdx = findFirstDFS($ast14, NodeKind::NewExpr);
check($newIdx >= 0, '测试14: 找到 NewExpr');
check($ast14->nodes[$newIdx]['typ'] === $IDX_OBJECT, '测试14: new I().typ = IDX_OBJECT');
$errors14 = $checker14->getErrors();
$hasInstantiationError = false;
foreach ($errors14 as $e) {
    if (str_contains($e['msg'], 'Cannot instantiate')) { $hasInstantiationError = true; break; }
}
check($hasInstantiationError, '测试14: 实例化接口产生 "Cannot instantiate" 错误');

// ─────────────────────────────────────────────────────────────
// 测试 15: while 块作用域 + 复合赋值
//   function f(): void { $i = 0; while ($i < 10) { $i += 1; } }
//   验证：$i += 1 (CompoundAssignExpr) 的 typ = int
// ─────────────────────────────────────────────────────────────
$src15 = '<?php function f(): void { $i = 0; while ($i < 10) { $i += 1; } }';
$ast15 = parseToFlatAst($src15);
$checker15 = new FlatTypeChecker($ast15, new SymbolTable());
$checker15->check();

$caIdx = findFirstDFS($ast15, NodeKind::CompoundAssignExpr);
check($caIdx >= 0, '测试15: 找到 CompoundAssignExpr');
check($ast15->nodes[$caIdx]['value'] === '+=', '测试15: 复合赋值运算符 = +=');
check($ast15->nodes[$caIdx]['typ'] === $IDX_INT, '测试15: $i += 1.typ = IDX_INT');

// ─────────────────────────────────────────────────────────────
// 测试 16: 嵌套作用域 — 内层可访问外层变量
//   function f(): void { $x = 1; if (true) { $y = $x + 1; } }
//   验证：内层 if 块中 $x 仍可查到（int），$y 推导为 int
// ─────────────────────────────────────────────────────────────
$src16 = '<?php function f(): void { $x = 1; if (true) { $y = $x + 1; } }';
$ast16 = parseToFlatAst($src16);
$checker16 = new FlatTypeChecker($ast16, new SymbolTable());
$checker16->check();

// 找到 $x + 1 的 BinaryExpr，验证 typ = int
$binIdxs = [];
foreach ($ast16->nodes as $idx => $n) {
    if ($n['kind'] === NodeKind::BinaryExpr) $binIdxs[] = $idx;
}
check(count($binIdxs) === 1, '测试16: 有 1 个 BinaryExpr（$x + 1）');
check($ast16->nodes[$binIdxs[0]]['typ'] === $IDX_INT, '测试16: $x + 1.typ = IDX_INT（内层可访问外层 $x）');

// ─────────────────────────────────────────────────────────────
// 测试 17: 闭包表达式类型
//   function f(): void { $cb = function(): int { return 42; }; }
//   验证：ClosureExpr.typ = IDX_CALLBACK
// ─────────────────────────────────────────────────────────────
$src17 = '<?php function f(): void { $cb = function(): int { return 42; }; }';
$ast17 = parseToFlatAst($src17);
$checker17 = new FlatTypeChecker($ast17, new SymbolTable());
$checker17->check();

$closureIdx = findFirstDFS($ast17, NodeKind::ClosureExpr);
check($closureIdx >= 0, '测试17: 找到 ClosureExpr');
check($ast17->nodes[$closureIdx]['typ'] === $IDX_CALLBACK, '测试17: ClosureExpr.typ = IDX_CALLBACK');

// ─────────────────────────────────────────────────────────────
// 测试 18: 三元运算符类型推导
//   function f(): void { $x = true ? 1 : 2; $y = true ? 1 : "s"; }
//   验证：true?1:2 → int（两分支同类型）；true?1:"s" → mixed（类型不同）
// ─────────────────────────────────────────────────────────────
$src18 = '<?php function f(): void { $x = true ? 1 : 2; $y = true ? 1 : "s"; }';
$ast18 = parseToFlatAst($src18);
$checker18 = new FlatTypeChecker($ast18, new SymbolTable());
$checker18->check();

$ternIdxs = [];
foreach ($ast18->nodes as $idx => $n) {
    if ($n['kind'] === NodeKind::TernaryExpr) $ternIdxs[] = $idx;
}
check(count($ternIdxs) === 2, '测试18: 有 2 个 TernaryExpr');
check($ast18->nodes[$ternIdxs[0]]['typ'] === $IDX_INT, '测试18: true?1:2 → int（公共类型）');
check($ast18->nodes[$ternIdxs[1]]['typ'] === $IDX_MIXED, '测试18: true?1:"s" → mixed（类型不同）');

// ─────────────────────────────────────────────────────────────
// 汇总
// ─────────────────────────────────────────────────────────────
echo "\n";
echo "====================================\n";
echo "FlatTypeChecker 单元测试结果\n";
echo "====================================\n";
echo "通过: {$pass}\n";
echo "失败: {$fail}\n";
echo "====================================\n";

if ($fail > 0) {
    echo "\n失败用例：\n";
    foreach ($failures as $f) {
        echo "  - {$f}\n";
    }
    exit(1);
}

echo "\n[OK] 全部测试通过\n";
exit(0);
