<?php

declare(strict_types=1);

// ============================================================
// SSAToCGenerator 单元测试（Task 15）
//
// 运行方式：
//   cd c:\project\php\TinyPHP
//   php tools/SSA/to_c_test.php
//
// 测试范围：
//   1.  简单函数 add(a, b) → return a + b; 降低
//   2.  常量折叠端到端（1 + 2 → 3）
//   3.  Phi 节点消除（if/else 汇合）
//   4.  控制流降低：CBR → if (...) goto ...; else goto ...;
//   5.  控制流降低：JMP → goto block_N;
//   6.  CALL 指令降低
//   7.  LOAD/STORE 降低
//   8.  多函数模块降低
//   9.  SSA 优化 + C 生成端到端
//   10. void 函数 RET 降低
//   11. 类型映射完整性
//   12. 函数签名 / 前向声明
//   13. 各种算术 / 比较 / 逻辑运算降低
//   14. CONST_FLOAT / CONST_BOOL / CONST_NULL 降低
//
// 断言总数：>= 30
// ============================================================

require_once __DIR__ . '/../../src/AST/FlatAst.php';
require_once __DIR__ . '/../../src/SSA/SSA.php';
require_once __DIR__ . '/../../src/SSA/SSABuilder.php';
require_once __DIR__ . '/../../src/SSA/SSAOptPass.php';
require_once __DIR__ . '/../../src/SSA/SSAToCGenerator.php';

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

function checkStr(string $haystack, string $needle, string $label): void
{
    global $pass, $fail, $failures;
    if (str_contains($haystack, $needle)) {
        $pass++;
    } else {
        $fail++;
        $failures[] = $label;
        echo "[FAIL] $label\n";
        echo "       expected substring: " . var_export($needle, true) . "\n";
        echo "       haystack (first 500 chars): " . substr($haystack, 0, 500) . "\n";
    }
}

function checkRegex(string $haystack, string $pattern, string $label): void
{
    global $pass, $fail, $failures;
    if (preg_match($pattern, $haystack)) {
        $pass++;
    } else {
        $fail++;
        $failures[] = $label;
        echo "[FAIL] $label\n";
        echo "       expected pattern: {$pattern}\n";
        echo "       haystack (first 500 chars): " . substr($haystack, 0, 500) . "\n";
    }
}

// ─────────────────────────────────────────────────────────────
// FlatAst 构建辅助（与 ssa_test.php 一致）
// ─────────────────────────────────────────────────────────────
function mkIntLit(FlatAst $ast, int $v): int
{
    return $ast->makeNode(NodeKind::IntLiteralExpr, $v);
}
function mkFloatLit(FlatAst $ast, float $v): int
{
    return $ast->makeNode(NodeKind::FloatLiteralExpr, $v);
}
function mkBoolLit(FlatAst $ast, bool $v): int
{
    return $ast->makeNode(NodeKind::BoolLiteralExpr, $v);
}
function mkNullLit(FlatAst $ast): int
{
    return $ast->makeNode(NodeKind::NullLiteralExpr, null);
}
function mkVar(FlatAst $ast, string $name): int
{
    return $ast->makeNode(NodeKind::VariableExpr, $name);
}
function mkBinary(FlatAst $ast, string $op, int $l, int $r): int
{
    return $ast->makeNode(NodeKind::BinaryExpr, $op, [$l, $r]);
}
function mkUnary(FlatAst $ast, string $op, int $expr): int
{
    return $ast->makeNode(NodeKind::UnaryExpr, $op, [$expr]);
}
function mkCall(FlatAst $ast, string $name, array $args): int
{
    return $ast->makeNode(NodeKind::CallExpr, $name, $args, [0, 0], [
        'isNullsafe' => false,
        'isRawC'     => false,
        'hasCallee'  => false,
        'argNames'   => [],
        'spreads'    => [],
        'argCount'   => count($args),
    ]);
}
function mkParam(FlatAst $ast, string $name, string $type = 'int'): int
{
    return $ast->makeNode(NodeKind::ParamNode, $name, [], [0, 0], ['type' => $type]);
}
function mkReturn(FlatAst $ast, ?int $exprIdx = null): int
{
    $children = $exprIdx !== null ? [$exprIdx] : [];
    return $ast->makeNode(NodeKind::ReturnStmtNode, null, $children, [0, 0], ['hasExpr' => $exprIdx !== null]);
}
function mkAssign(FlatAst $ast, string $varName, int $exprIdx, ?string $type = null): int
{
    return $ast->makeNode(NodeKind::AssignStmtNode, $varName, [$exprIdx], [0, 0], ['type' => $type]);
}
function mkExprStmt(FlatAst $ast, int $exprIdx): int
{
    return $ast->makeNode(NodeKind::ExprStmtNode, null, [$exprIdx], [0, 0], []);
}
function mkIf(FlatAst $ast, int $condIdx, array $thenStmts, array $elseStmts = []): int
{
    $children = array_merge([$condIdx], $thenStmts, $elseStmts);
    return $ast->makeNode(NodeKind::IfStmtNode, null, $children, [0, 0], [
        'thenBodyCount' => count($thenStmts),
        'elseifCount'   => 0,
        'elseBodyCount' => count($elseStmts),
    ]);
}
function mkFunction(FlatAst $ast, string $name, array $params, array $body, string $retType = 'int'): int
{
    $children = [];
    foreach ($params as $p) {
        $children[] = mkParam($ast, $p['name'], $p['type']);
    }
    foreach ($body as $stmt) {
        $children[] = $stmt;
    }
    return $ast->makeNode(NodeKind::FunctionNode, $name, $children, [0, 0], [
        'returnType'     => $retType,
        'paramCount'     => count($params),
        'attributeCount' => 0,
    ]);
}

/**
 * 构建 SSAModule
 * 支持两种根结构：
 *   1. root 本身是 FunctionNode（单函数测试常用）
 *   2. root 是 ProgramNode，遍历其子节点中的 FunctionNode
 */
function buildModule(FlatAst $ast): SSAModule
{
    $module = new SSAModule();
    $root = $ast->root;
    if ($root < 0) {
        return $module;
    }
    // 情况 1：root 直接是 FunctionNode
    if ($ast->nodes[$root]['kind'] === NodeKind::FunctionNode) {
        $builder = new SSABuilder();
        $func = $builder->build($ast, $root);
        $fid = $module->newFunction($func->name, $func->paramTypes, $func->retType);
        $module->functions[$fid] = $func;
        return $module;
    }
    // 情况 2：root 是 ProgramNode 或其他容器，遍历子节点
    $n = $ast->childCount($root);
    for ($i = 0; $i < $n; $i++) {
        $childIdx = $ast->child($root, $i);
        if ($ast->nodes[$childIdx]['kind'] === NodeKind::FunctionNode) {
            $builder = new SSABuilder();
            $func = $builder->build($ast, $childIdx);
            $fid = $module->newFunction($func->name, $func->paramTypes, $func->retType);
            $module->functions[$fid] = $func;
        }
    }
    return $module;
}

/**
 * 优化整个 module
 */
function optimizeModule(SSAModule $module): void
{
    $opt = new SSAOptPass();
    foreach ($module->functions as $func) {
        $opt->runUntilFixpoint($func);
    }
}

// 便捷：构建 + 优化 + 生成 C
function buildAndGenerate(FlatAst $ast, bool $optimize = true): string
{
    $module = buildModule($ast);
    if ($optimize) {
        optimizeModule($module);
    }
    return (new SSAToCGenerator())->generate($module, 'test.php');
}

// ═══════════════════════════════════════════════════════════════
// 测试 1: 简单函数 add(int a, int b) → return a + b;
// ═══════════════════════════════════════════════════════════════
$ast = new FlatAst();
$add = mkBinary($ast, '+', mkVar($ast, '$a'), mkVar($ast, '$b'));
$ret = mkReturn($ast, $add);
$fnIdx = mkFunction($ast, 'add', [['name' => '$a', 'type' => 'int'], ['name' => '$b', 'type' => 'int']], [$ret], 'int');
$ast->root = $fnIdx;

$cCode = buildAndGenerate($ast, false);
check(strpos($cCode, 'static int64_t add(int64_t v_a, int64_t v_b);') !== false, 'add: forward declaration');
check(strpos($cCode, 'static int64_t add(int64_t v_a, int64_t v_b) {') !== false, 'add: function definition signature');
check(strpos($cCode, 'block_0:  /* entry */') !== false, 'add: entry block label');
check(strpos($cCode, '= v_a + v_b;') !== false, 'add: ADD lowered to a + b');
checkRegex($cCode, '/return v_\w+;/', 'add: RET lowered to return v_<name>');
check(strpos($cCode, '#include "common.h"') !== false, 'add: includes common.h');

// ═══════════════════════════════════════════════════════════════
// 测试 2: 常量折叠端到端 — $x = 1 + 2; return $x; → 应输出 CONST_INT 3
// ═══════════════════════════════════════════════════════════════
$ast = new FlatAst();
$one = mkIntLit($ast, 1);
$two = mkIntLit($ast, 2);
$plus = mkBinary($ast, '+', $one, $two);
$assignX = mkAssign($ast, '$x', $plus);
$varX = mkVar($ast, '$x');
$ret = mkReturn($ast, $varX);
$fnIdx = mkFunction($ast, 'f', [], [$assignX, $ret], 'int');
$ast->root = $fnIdx;

$cCode = buildAndGenerate($ast, true);
// 常量折叠后应包含 `int64_t v_x = 3;`
check(strpos($cCode, 'int64_t v_x = 3;') !== false, 'fold: 1+2 folded to v_x = 3');
check(strpos($cCode, 'return v_x;') !== false, 'fold: return v_x');
// 折叠后不应再有 ADD 指令
check(strpos($cCode, ' v_a + v_b') === false, 'fold: no ADD instruction remains');
check(strpos($cCode, ' v_0 + v_1') === false, 'fold: no ADD between constant ids');

// ═══════════════════════════════════════════════════════════════
// 测试 3: Phi 节点消除 — if ($c) { $x = 1; } else { $x = 2; } return $x;
// ═══════════════════════════════════════════════════════════════
$ast = new FlatAst();
$cond = mkVar($ast, '$c');
$thenAssign = mkAssign($ast, '$x', mkIntLit($ast, 1));
$elseAssign = mkAssign($ast, '$x', mkIntLit($ast, 2));
$if = mkIf($ast, $cond, [$thenAssign], [$elseAssign]);
$ret = mkReturn($ast, mkVar($ast, '$x'));
$fnIdx = mkFunction($ast, 'f', [['name' => '$c', 'type' => 'bool'], ['name' => '$x', 'type' => 'int']], [$if, $ret], 'int');
$ast->root = $fnIdx;

$cCode = buildAndGenerate($ast, false);
// C 代码不应再有 PHI 字样
check(strpos($cCode, 'PHI') === false, 'phi-elim: no PHI in C output');
check(strpos($cCode, 'phi') === false || stripos($cCode, 'phi result') !== false, 'phi-elim: no lowercase phi (except phi result comment)');
// 前驱块末尾应有 phi 复制（v_x = ...）
checkRegex($cCode, '/v_x = v_\w+;/', 'phi-elim: phi copy v_x = v_<src> present in predecessor');
// phi 结果变量应被声明（在 entry block 顶部）
check(strpos($cCode, '/* phi result */') !== false, 'phi-elim: phi result declaration present');
// 应包含两个 goto（then 与 else 块的 JMP）
check(substr_count($cCode, 'goto block_') >= 3, 'phi-elim: at least 3 goto statements (BR + 2 JMP)');

// ═══════════════════════════════════════════════════════════════
// 测试 4: CBR 降低 — if (cond) goto block_X; else goto block_Y;
// ═══════════════════════════════════════════════════════════════
$ast = new FlatAst();
$cond = mkVar($ast, '$c');
$if = mkIf($ast, $cond, [mkAssign($ast, '$x', mkIntLit($ast, 1))], [mkAssign($ast, '$x', mkIntLit($ast, 2))]);
$ret = mkReturn($ast, mkVar($ast, '$x'));
$fnIdx = mkFunction($ast, 'f', [['name' => '$c', 'type' => 'bool'], ['name' => '$x', 'type' => 'int']], [$if, $ret], 'int');
$ast->root = $fnIdx;

$cCode = buildAndGenerate($ast, false);
checkRegex($cCode, '/if \(v_c\) goto block_\d+; else goto block_\d+;/', 'cbr: if (v_cond) goto block_X; else goto block_Y;');

// ═══════════════════════════════════════════════════════════════
// 测试 5: JMP 降低 — goto block_N;
// ═══════════════════════════════════════════════════════════════
checkRegex($cCode, '/goto block_\d+;/', 'jmp: goto block_N; present');
// JMP 不应带 if
$jmpCount = preg_match_all('/^\s*goto block_\d+;\s*$/m', $cCode);
check($jmpCount >= 2, 'jmp: at least 2 unconditional goto statements (then/else JMP)');

// ═══════════════════════════════════════════════════════════════
// 测试 6: CALL 指令降低 — function caller(int a) { return callee(a); }
// ═══════════════════════════════════════════════════════════════
$ast = new FlatAst();
$call = mkCall($ast, 'callee', [mkVar($ast, '$a')]);
$ret = mkReturn($ast, $call);
$fnIdx = mkFunction($ast, 'caller', [['name' => '$a', 'type' => 'int']], [$ret], 'int');
$ast->root = $fnIdx;

$cCode = buildAndGenerate($ast, false);
checkRegex($cCode, '/int64_t v_\w+ = callee\(v_a\);/', 'call: int64_t v_<dst> = callee(v_a);');
check(strpos($cCode, 'return v_') !== false, 'call: return v_<dst>');

// ═══════════════════════════════════════════════════════════════
// 测试 7: LOAD / STORE 降低（手工构造 SSA）
// ═══════════════════════════════════════════════════════════════
$module = new SSAModule();
$fid = $module->newFunction('loadstore', [], SSAType::void());
$func = $module->functions[$fid];
$func->entryBlockId = $func->newBlock('entry');
$ptrType = SSAType::ptr(SSAType::int());
$intType = SSAType::int();

$pVal = $func->newValue($ptrType, 'p');
$vVal = $func->newValue($intType, 'v');
$rVal = $func->newValue($intType);  // LOAD 结果
$func->appendInst($func->entryBlockId, new SSAInstruction(OpCode::STORE, null, [$pVal, $vVal]));
$func->appendInst($func->entryBlockId, new SSAInstruction(OpCode::LOAD, $rVal, [$pVal]));
$func->appendInst($func->entryBlockId, new SSAInstruction(OpCode::RET, null, []));

$cCode = (new SSAToCGenerator())->generate($module, 'loadstore.php');
check(strpos($cCode, '*v_p = v_v;') !== false, 'loadstore: STORE lowered to *v_p = v_v;');
checkRegex($cCode, '/int64_t v_\w+ = \*v_p;/', 'loadstore: LOAD lowered to int64_t v_<r> = *v_p;');
check(strpos($cCode, 'return;') !== false, 'loadstore: void RET lowered to return;');

// ═══════════════════════════════════════════════════════════════
// 测试 8: 多函数模块降低（两个函数，一个调用另一个）
// ═══════════════════════════════════════════════════════════════
$ast = new FlatAst();
// function double(int x): int { return x * 2; }
$mul = mkBinary($ast, '*', mkVar($ast, '$x'), mkIntLit($ast, 2));
$ret1 = mkReturn($ast, $mul);
$fn1 = mkFunction($ast, 'double', [['name' => '$x', 'type' => 'int']], [$ret1], 'int');
// function main(): int { return double(5); }
$call = mkCall($ast, 'double', [mkIntLit($ast, 5)]);
$ret2 = mkReturn($ast, $call);
$fn2 = mkFunction($ast, 'main', [], [$ret2], 'int');
// 包裹在 ProgramNode 里
$program = $ast->makeNode(NodeKind::ProgramNode, null, [$fn1, $fn2], [0, 0], [
    'hasMainClass'    => false,
    'extraClassCount' => 0,
    'functionCount'   => 2,
    'constantCount'   => 0,
    'enumCount'       => 0,
    'includes'        => [],
    'ccFlags'         => [],
    'callbacks'       => [],
    'debugs'          => [],
    'cstructs'        => [],
    'useImports'      => [],
]);
$ast->root = $program;

$cCode = buildAndGenerate($ast, false);
check(strpos($cCode, 'static int64_t double(int64_t v_x);') !== false, 'multi: double forward declaration');
check(strpos($cCode, 'static int64_t main(void);') !== false, 'multi: main forward declaration');
check(strpos($cCode, 'static int64_t double(int64_t v_x) {') !== false, 'multi: double definition');
check(strpos($cCode, 'static int64_t main(void) {') !== false, 'multi: main definition');
// SSA 形式下字面量 2 是独立的 CONST_INT 值（v_1），MUL 引用 v_x * v_1
check(strpos($cCode, '= v_x * v_') !== false, 'multi: double body has v_x * v_<const>');
// SSA 形式下字面量 5 是独立的 CONST_INT 值，CALL 引用 double(v_<const>)
checkRegex($cCode, '/int64_t v_\w+ = double\(v_\w+\);/', 'multi: main body calls double(v_<const>)');

// ═══════════════════════════════════════════════════════════════
// 测试 9: SSA 优化 + C 生成端到端流程
//   function f(int $a): int { $x = 1 + 2; $y = $x + 3; return $y; }
//   优化后 $y 应被折叠为 6
// ═══════════════════════════════════════════════════════════════
$ast = new FlatAst();
$innerAdd = mkBinary($ast, '+', mkIntLit($ast, 1), mkIntLit($ast, 2));
$assignX = mkAssign($ast, '$x', $innerAdd);
$outerAdd = mkBinary($ast, '+', mkVar($ast, '$x'), mkIntLit($ast, 3));
$assignY = mkAssign($ast, '$y', $outerAdd);
$ret = mkReturn($ast, mkVar($ast, '$y'));
$fnIdx = mkFunction($ast, 'f', [['name' => '$a', 'type' => 'int']], [$assignX, $assignY, $ret], 'int');
$ast->root = $fnIdx;

$cCode = buildAndGenerate($ast, true);
// 优化后 $y 应直接为 6
check(strpos($cCode, 'int64_t v_y = 6;') !== false, 'e2e-fold: 1+2+3 folded to v_y = 6');
check(strpos($cCode, 'return v_y;') !== false, 'e2e-fold: return v_y');
// 不应再有 ADD 指令
check(strpos($cCode, ' + ') === false, 'e2e-fold: no ADD remaining after optimization');
check(strpos($cCode, ' + v_') === false, 'e2e-fold: no ADD with value operand');

// ═══════════════════════════════════════════════════════════════
// 测试 10: void 函数返回降低
//   注意：SSABuilder.ensureTerminator() 当前为空实现，不会自动补 RET，
//   因此测试中显式写 return; 以验证 void RET 降低为 return;
// ═══════════════════════════════════════════════════════════════
$ast = new FlatAst();
$echo = mkExprStmt($ast, mkCall($ast, 'echo', [mkIntLit($ast, 42)]));
$ret = mkReturn($ast, null);  // 显式 return; 验证 void RET 降低
$fnIdx = mkFunction($ast, 'p', [], [$echo, $ret], 'void');
$ast->root = $fnIdx;

$cCode = buildAndGenerate($ast, false);
check(strpos($cCode, 'static void p(void);') !== false, 'void: forward declaration');
check(strpos($cCode, 'static void p(void) {') !== false, 'void: definition signature');
// void 函数末尾应有 return;（显式 return 降低）
check(strpos($cCode, 'return;') !== false, 'void: return; present');
// 不应有 return v_<X>;
check(strpos($cCode, 'return v_') === false, 'void: no return with value');

// ═══════════════════════════════════════════════════════════════
// 测试 11: 类型映射完整性（直接调用 mapType）
// ═══════════════════════════════════════════════════════════════
$gen = new SSAToCGenerator();
check($gen->mapType(SSAType::void()) === 'void', 'type: void → void');
check($gen->mapType(SSAType::int()) === 'int64_t', 'type: int → int64_t');
check($gen->mapType(SSAType::float()) === 'double', 'type: float → double');
check($gen->mapType(SSAType::bool()) === 'bool', 'type: bool → bool');
check($gen->mapType(SSAType::ptr(SSAType::void())) === 'void*', 'type: ptr<void> → void*');
check($gen->mapType(SSAType::ptr(SSAType::int())) === 't_string*', 'type: ptr<int> → t_string*');
check($gen->mapType(SSAType::ptr(SSAType::float())) === 'double*', 'type: ptr<float> → double*');
check($gen->mapType(SSAType::ptr(SSAType::bool())) === 'bool*', 'type: ptr<bool> → bool*');
check($gen->mapType(SSAType::ptr(SSAType::ptr(SSAType::int()))) === 't_string**', 'type: ptr<ptr<int>> → t_string**');
check($gen->mapType(SSAType::array(SSAType::int())) === 't_array*', 'type: array<int> → t_array*');
check($gen->mapType(new SSAType(SSATypeKind::STRUCT)) === 't_object*', 'type: struct → t_object*');
check($gen->mapType(new SSAType(SSATypeKind::FUNC)) === 'void*', 'type: func → void*');

// ═══════════════════════════════════════════════════════════════
// 测试 12: CONST_FLOAT / CONST_BOOL / CONST_NULL 降低
// ═══════════════════════════════════════════════════════════════
$ast = new FlatAst();
$ret = mkReturn($ast, mkFloatLit($ast, 3.14));
$fnIdx = mkFunction($ast, 'ff', [], [$ret], 'float');
$ast->root = $fnIdx;
$cCode = buildAndGenerate($ast, false);
check(strpos($cCode, 'double v_') !== false, 'float-lit: double type prefix');
check(strpos($cCode, '3.14') !== false, 'float-lit: 3.14 literal in C output');

$ast = new FlatAst();
$ret = mkReturn($ast, mkBoolLit($ast, true));
$fnIdx = mkFunction($ast, 'fb', [], [$ret], 'bool');
$ast->root = $fnIdx;
$cCode = buildAndGenerate($ast, false);
check(strpos($cCode, 'bool v_') !== false, 'bool-lit: bool type prefix');
check(strpos($cCode, '= true;') !== false, 'bool-lit: true literal in C output');

$ast = new FlatAst();
$ret = mkReturn($ast, mkNullLit($ast));
$fnIdx = mkFunction($ast, 'fn', [], [$ret], 'int');  // SSABuilder 对 null 用 ptr<void>
$ast->root = $fnIdx;
$cCode = buildAndGenerate($ast, false);
check(strpos($cCode, '= NULL;') !== false, 'null-lit: NULL literal in C output');

// ═══════════════════════════════════════════════════════════════
// 测试 13: 算术 / 比较 / 逻辑运算降低
// ═══════════════════════════════════════════════════════════════
function buildBinaryFunc(string $op, string $fnName, string $retType = 'int'): string
{
    $ast = new FlatAst();
    $add = mkBinary($ast, $op, mkVar($ast, '$a'), mkVar($ast, '$b'));
    $ret = mkReturn($ast, $add);
    $fnIdx = mkFunction($ast, $fnName, [['name' => '$a', 'type' => 'int'], ['name' => '$b', 'type' => 'int']], [$ret], $retType);
    $ast->root = $fnIdx;
    return buildAndGenerate($ast, false);
}

check(strpos(buildBinaryFunc('-', 'sub_f'), '= v_a - v_b;') !== false, 'arith: SUB → a - b');
check(strpos(buildBinaryFunc('*', 'mul_f'), '= v_a * v_b;') !== false, 'arith: MUL → a * b');
check(strpos(buildBinaryFunc('/', 'div_f'), '= v_a / v_b;') !== false, 'arith: DIV → a / b');
check(strpos(buildBinaryFunc('%', 'mod_f'), '= v_a % v_b;') !== false, 'arith: MOD → a % b');

// 比较运算（结果为 bool）
$cmpCode = buildBinaryFunc('==', 'eq_f', 'bool');
check(strpos($cmpCode, 'bool v_') !== false, 'cmp: EQ result is bool');
check(strpos($cmpCode, '= (v_a == v_b);') !== false, 'cmp: EQ → (a == b)');
check(strpos(buildBinaryFunc('!=', 'ne_f', 'bool'), '= (v_a != v_b);') !== false, 'cmp: NE → (a != b)');
check(strpos(buildBinaryFunc('<', 'lt_f', 'bool'), '= (v_a < v_b);') !== false, 'cmp: LT → (a < b)');
check(strpos(buildBinaryFunc('<=', 'le_f', 'bool'), '= (v_a <= v_b);') !== false, 'cmp: LE → (a <= b)');
check(strpos(buildBinaryFunc('>', 'gt_f', 'bool'), '= (v_a > v_b);') !== false, 'cmp: GT → (a > b)');
check(strpos(buildBinaryFunc('>=', 'ge_f', 'bool'), '= (v_a >= v_b);') !== false, 'cmp: GE → (a >= b)');

// 逻辑运算
check(strpos(buildBinaryFunc('&&', 'and_f', 'bool'), '= v_a && v_b;') !== false, 'logic: AND → a && b');
check(strpos(buildBinaryFunc('||', 'or_f', 'bool'), '= v_a || v_b;') !== false, 'logic: OR → a || b');

// 一元运算
$ast = new FlatAst();
$neg = mkUnary($ast, '-', mkVar($ast, '$a'));
$ret = mkReturn($ast, $neg);
$fnIdx = mkFunction($ast, 'neg_f', [['name' => '$a', 'type' => 'int']], [$ret], 'int');
$ast->root = $fnIdx;
check(strpos(buildAndGenerate($ast, false), '= -v_a;') !== false, 'unary: NEG → -a');

$ast = new FlatAst();
$not = mkUnary($ast, '!', mkVar($ast, '$a'));
$ret = mkReturn($ast, $not);
$fnIdx = mkFunction($ast, 'not_f', [['name' => '$a', 'type' => 'bool']], [$ret], 'bool');
$ast->root = $fnIdx;
check(strpos(buildAndGenerate($ast, false), '= !v_a;') !== false, 'unary: NOT → !a');

// ═══════════════════════════════════════════════════════════════
// 测试 14: 函数签名 / 前向声明完备性
// ═══════════════════════════════════════════════════════════════
$ast = new FlatAst();
$varA = mkVar($ast, '$a');
$varB = mkVar($ast, '$b');
$varC = mkVar($ast, '$c');
$ret = mkReturn($ast, $varA);
$fnIdx = mkFunction($ast, 'three', [
    ['name' => '$a', 'type' => 'int'],
    ['name' => '$b', 'type' => 'float'],
    ['name' => '$c', 'type' => 'bool'],
], [$ret], 'int');
$ast->root = $fnIdx;

$cCode = buildAndGenerate($ast, false);
check(strpos($cCode, 'static int64_t three(int64_t v_a, double v_b, bool v_c);') !== false, 'sig: forward decl with 3 params');
check(strpos($cCode, 'static int64_t three(int64_t v_a, double v_b, bool v_c) {') !== false, 'sig: definition with 3 params');

// ═══════════════════════════════════════════════════════════════
// 测试 15: 块标签命名 — block_0, block_1, ...
// ═══════════════════════════════════════════════════════════════
$ast = new FlatAst();
$cond = mkVar($ast, '$c');
$if = mkIf($ast, $cond, [mkAssign($ast, '$x', mkIntLit($ast, 1))], [mkAssign($ast, '$x', mkIntLit($ast, 2))]);
$ret = mkReturn($ast, mkVar($ast, '$x'));
$fnIdx = mkFunction($ast, 'f', [['name' => '$c', 'type' => 'bool'], ['name' => '$x', 'type' => 'int']], [$if, $ret], 'int');
$ast->root = $fnIdx;

$cCode = buildAndGenerate($ast, false);
check(strpos($cCode, 'block_0:') !== false, 'label: block_0 exists');
check(strpos($cCode, 'block_1:') !== false, 'label: block_1 exists');
check(strpos($cCode, 'block_2:') !== false, 'label: block_2 exists');
check(strpos($cCode, 'block_3:') !== false, 'label: block_3 exists');
check(strpos($cCode, 'block_0:  /* entry */') !== false, 'label: entry block has /* entry */ comment');

// ═══════════════════════════════════════════════════════════════
// 测试 16: 寄存器命名 — SSA Value name 去掉 $ 前缀，加 v_ 前缀
// ═══════════════════════════════════════════════════════════════
$ast = new FlatAst();
$ret = mkReturn($ast, mkVar($ast, '$myVar'));
$fnIdx = mkFunction($ast, 'f', [['name' => '$myVar', 'type' => 'int']], [$ret], 'int');
$ast->root = $fnIdx;

$cCode = buildAndGenerate($ast, false);
check(strpos($cCode, 'v_myVar') !== false, 'naming: $myVar → v_myVar');
check(strpos($cCode, 'return v_myVar;') !== false, 'naming: return v_myVar');
// 不应出现原始的 $myVar
check(strpos($cCode, '$myVar') === false, 'naming: no $myVar in C output');

// ═══════════════════════════════════════════════════════════════
// 测试 17: 整体 C 代码结构（include / 前向声明 / 定义）
// ═══════════════════════════════════════════════════════════════
$ast = new FlatAst();
$ret = mkReturn($ast, mkIntLit($ast, 42));
$fnIdx = mkFunction($ast, 'f', [], [$ret], 'int');
$ast->root = $fnIdx;

$cCode = buildAndGenerate($ast, false);
check(strpos($cCode, '#include "common.h"') !== false, 'struct: include common.h');
check(strpos($cCode, 'Generated by TinyPHP SSA') !== false, 'struct: generator banner');
check(strpos($cCode, 'static int64_t f(void);') !== false, 'struct: f forward declaration');
check(strpos($cCode, 'static int64_t f(void) {') !== false, 'struct: f definition');
check(strpos($cCode, '}') !== false, 'struct: closing brace');

// ═══════════════════════════════════════════════════════════════
// 汇总
// ═══════════════════════════════════════════════════════════════
echo "\n";
echo "====================================\n";
echo "SSAToCGenerator 单元测试结果\n";
echo "====================================\n";
echo "通过: {$pass}\n";
echo "失败: {$fail}\n";
echo "====================================\n";

if ($fail > 0) {
    echo "\n失败列表：\n";
    foreach ($failures as $f) {
        echo "  - {$f}\n";
    }
    exit(1);
}

echo "\n所有测试通过。\n";
exit(0);
