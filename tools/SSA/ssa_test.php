<?php

declare(strict_types=1);

// ============================================================
// SSA 中间表示层单元测试（Task 12）
//
// 运行方式：
//   cd c:\project\php\TinyPHP
//   php tools/SSA/ssa_test.php
//
// 测试范围：
//   1.  SSAType 创建与 equals（含 ptr/array 递归比较）
//   2.  SSAValue / SSABasicBlock / SSAFunction / SSAModule 创建
//   3.  OpCode / SSATypeKind 枚举完备性
//   4.  简单函数 SSA 构建（return $a）
//   5.  算术运算 SSA（$a + $b → ADD）
//   6.  变量赋值 SSA（$x = 1; $x = $x + 1; → 两个不同 value id）
//   7.  if 语句 SSA（BR + then/else + JMP merge）
//   8.  while 循环 SSA（cond + body + exit + 回边）
//   9.  Phi 节点插入（if/else 汇合点）
//   10. SSA 文本输出（dump 格式）
//
// 断言总数：>= 20
// ============================================================

require_once __DIR__ . '/../../src/AST/FlatAst.php';
require_once __DIR__ . '/../../src/SSA/SSA.php';
require_once __DIR__ . '/../../src/SSA/SSABuilder.php';

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

// ─────────────────────────────────────────────────────────────
// FlatAst 构建辅助（模拟 FlatAstConverter 输出的节点结构）
// ─────────────────────────────────────────────────────────────
function mkIntLit(FlatAst $ast, int $v): int
{
    return $ast->makeNode(NodeKind::IntLiteralExpr, $v);
}
function mkBoolLit(FlatAst $ast, bool $v): int
{
    return $ast->makeNode(NodeKind::BoolLiteralExpr, $v);
}
function mkVar(FlatAst $ast, string $name): int
{
    return $ast->makeNode(NodeKind::VariableExpr, $name);
}
function mkBinary(FlatAst $ast, string $op, int $l, int $r): int
{
    return $ast->makeNode(NodeKind::BinaryExpr, $op, [$l, $r]);
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
function mkEcho(FlatAst $ast, array $exprIdxs): int
{
    return $ast->makeNode(NodeKind::EchoStmtNode, null, $exprIdxs, [0, 0], ['exprCount' => count($exprIdxs)]);
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
function mkWhile(FlatAst $ast, int $condIdx, array $bodyStmts): int
{
    $children = array_merge([$condIdx], $bodyStmts);
    return $ast->makeNode(NodeKind::WhileStmtNode, null, $children, [0, 0], ['bodyCount' => count($bodyStmts)]);
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

/** 在函数的所有块中查找第一条匹配 op 的指令 */
function findInst(SSAFunction $func, OpCode $op): ?SSAInstruction
{
    foreach ($func->blocks as $block) {
        foreach ($block->instructions as $inst) {
            if ($inst->op === $op) {
                return $inst;
            }
        }
    }
    return null;
}

/** 统计函数中匹配 op 的指令数 */
function countInst(SSAFunction $func, OpCode $op): int
{
    $n = 0;
    foreach ($func->blocks as $block) {
        foreach ($block->instructions as $inst) {
            if ($inst->op === $op) {
                $n++;
            }
        }
    }
    return $n;
}

// ═══════════════════════════════════════════════════════════════
// 测试 1: SSAType 创建与 equals
// ═══════════════════════════════════════════════════════════════
check(SSAType::int()->equals(SSAType::int()), 'SSAType int equals int');
check(!SSAType::int()->equals(SSAType::float()), 'SSAType int not equals float');
check(SSAType::void()->equals(SSAType::void()), 'SSAType void equals void');
check(SSAType::bool()->equals(SSAType::bool()), 'SSAType bool equals bool');
check(SSAType::ptr(SSAType::int())->equals(SSAType::ptr(SSAType::int())), 'ptr<int> equals ptr<int>');
check(!SSAType::ptr(SSAType::int())->equals(SSAType::ptr(SSAType::float())), 'ptr<int> not equals ptr<float>');
check(SSAType::array(SSAType::int())->equals(SSAType::array(SSAType::int())), 'array<int> equals array<int>');
check(!SSAType::array(SSAType::int())->equals(SSAType::int()), 'array<int> not equals int');
check(SSAType::int()->kind === SSATypeKind::INT, 'int.kind === INT');
check(SSAType::void()->kind === SSATypeKind::VOID, 'void.kind === VOID');
check(count(SSATypeKind::cases()) === 8, 'SSATypeKind has 8 cases');
check(SSAType::int()->label() === 'int', 'int label = "int"');
check(SSAType::ptr(SSAType::int())->label() === 'ptr<int>', 'ptr<int> label');

// ═══════════════════════════════════════════════════════════════
// 测试 2: SSAValue / Block / Function / Module 创建
// ═══════════════════════════════════════════════════════════════
$mod = new SSAModule();
$fid = $mod->newFunction('test', [SSAType::int()], SSAType::int());
check($fid === 0, 'newFunction returns id 0');
check(count($mod->functions) === 1, 'module has 1 function');
check($mod->functions[0]->name === 'test', 'function name = test');

$fn = $mod->functions[0];
$bid = $fn->newBlock('entry');
check($bid === 0, 'newBlock returns id 0');
check($fn->blocks[0]->label === 'entry', 'block 0 label = entry');

$vid = $fn->newValue(SSAType::int(), 'a');
check($vid === 0, 'newValue returns id 0');
check($fn->values[0]->type->equals(SSAType::int()), 'value 0 type = int');
check($fn->values[0]->name === 'a', 'value 0 name = a');
check($fn->nextValueId === 1, 'nextValueId incremented to 1');

$fn->entryBlockId = $bid;
$inst = new SSAInstruction(OpCode::RET, null, [$vid]);
$fn->appendInst($bid, $inst);
check(count($fn->blocks[$bid]->instructions) === 1, 'block has 1 instruction after appendInst');

$fn->addEdge(0, 0); // self-loop for testing edge
check(in_array(0, $fn->blocks[0]->successors), 'addEdge: successor recorded');
check(in_array(0, $fn->blocks[0]->predecessors), 'addEdge: predecessor recorded');

check(count(OpCode::cases()) === 30, 'OpCode has 30 cases');
check(OpCode::RET->isTerminator(), 'RET is terminator');
check(OpCode::ADD->isTerminator() === false, 'ADD is not terminator');
check(OpCode::ADD->hasResult(), 'ADD has result');
check(OpCode::RET->hasResult() === false, 'RET has no result');

// ═══════════════════════════════════════════════════════════════
// 测试 3: 简单函数 SSA 构建 — function f(int $a): int { return $a; }
// ═══════════════════════════════════════════════════════════════
$ast = new FlatAst();
$varA = mkVar($ast, '$a');
$ret = mkReturn($ast, $varA);
$fnIdx = mkFunction($ast, 'f', [['name' => '$a', 'type' => 'int']], [$ret], 'int');
$ast->root = $fnIdx;

$builder = new SSABuilder();
$func = $builder->build($ast, $fnIdx);
check($func->name === 'f', 'built function name = f');
check($func->retType->equals(SSAType::int()), 'built retType = int');
check(count($func->paramTypes) === 1, '1 param type');
check($func->paramTypes[0]->equals(SSAType::int()), 'param 0 type = int');
check(count($func->blocks) === 1, 'simple function has 1 block');
check($func->entryBlockId === 0, 'entry block id = 0');
$entryBlock = $func->blocks[0];
$lastInst = end($entryBlock->instructions);
check($lastInst->op === OpCode::RET, 'entry block ends with RET');
check($lastInst->operands[0] === 0, 'RET operand = param value id 0');

// ═══════════════════════════════════════════════════════════════
// 测试 4: 算术运算 SSA — function add(int $a, int $b): int { return $a + $b; }
// ═══════════════════════════════════════════════════════════════
$ast = new FlatAst();
$varA = mkVar($ast, '$a');
$varB = mkVar($ast, '$b');
$add = mkBinary($ast, '+', $varA, $varB);
$ret = mkReturn($ast, $add);
$fnIdx = mkFunction($ast, 'add', [['name' => '$a', 'type' => 'int'], ['name' => '$b', 'type' => 'int']], [$ret], 'int');

$func = (new SSABuilder())->build($ast, $fnIdx);
$addInst = findInst($func, OpCode::ADD);
check($addInst !== null, 'ADD instruction generated');
check($addInst !== null && count($addInst->operands) === 2, 'ADD has 2 operands');
check($addInst !== null && $addInst->operands[0] === 0, 'ADD operand[0] = $a (id 0)');
check($addInst !== null && $addInst->operands[1] === 1, 'ADD operand[1] = $b (id 1)');
check($addInst !== null && $addInst->dst !== null, 'ADD has dst value');
$retInst = findInst($func, OpCode::RET);
check($retInst !== null && $retInst->operands[0] === $addInst->dst, 'RET uses ADD result');

// ═══════════════════════════════════════════════════════════════
// 测试 5: 变量赋值 SSA — $x = 1; $x = $x + 1; return $x;
//   验证两次赋值产生不同的 SSA value id
// ═══════════════════════════════════════════════════════════════
$ast = new FlatAst();
$one1 = mkIntLit($ast, 1);
$assign1 = mkAssign($ast, '$x', $one1);
$varX = mkVar($ast, '$x');
$one2 = mkIntLit($ast, 1);
$plus = mkBinary($ast, '+', $varX, $one2);
$assign2 = mkAssign($ast, '$x', $plus);
$ret = mkReturn($ast, mkVar($ast, '$x'));
$fnIdx = mkFunction($ast, 'f', [], [$assign1, $assign2, $ret], 'int');

$func = (new SSABuilder())->build($ast, $fnIdx, false); // 不插 phi（无分支，本也无需）
$copies = [];
foreach ($func->blocks as $block) {
    foreach ($block->instructions as $inst) {
        if ($inst->op === OpCode::COPY) {
            $copies[] = $inst;
        }
    }
}
check(count($copies) === 2, 'two COPY instructions for $x assignments');
check(count($copies) >= 2 && $copies[0]->dst !== $copies[1]->dst, 'two $x assignments have different value ids');
// 验证 ADD 的左操作数引用的是第一次赋值后的 $x（COPY 结果）
$addInst = findInst($func, OpCode::ADD);
check($addInst !== null && $addInst->operands[0] === $copies[0]->dst, 'ADD left operand = first $x version');
// 验证 RET 引用第二次赋值后的 $x
$retInst = findInst($func, OpCode::RET);
check($retInst !== null && $retInst->operands[0] === $copies[1]->dst, 'RET uses second $x version');

// ═══════════════════════════════════════════════════════════════
// 测试 6: if 语句 SSA — if ($c) { $x = 1; } else { $x = 2; } return $x;
//   验证 BR + then/else + JMP merge 结构
// ═══════════════════════════════════════════════════════════════
$ast = new FlatAst();
$cond = mkVar($ast, '$c');
$thenAssign = mkAssign($ast, '$x', mkIntLit($ast, 1));
$elseAssign = mkAssign($ast, '$x', mkIntLit($ast, 2));
$if = mkIf($ast, $cond, [$thenAssign], [$elseAssign]);
$ret = mkReturn($ast, mkVar($ast, '$x'));
$fnIdx = mkFunction($ast, 'f', [['name' => '$c', 'type' => 'bool'], ['name' => '$x', 'type' => 'int']], [$if, $ret], 'int');

$func = (new SSABuilder())->build($ast, $fnIdx, false); // 先不插 phi，验证骨架
check(count($func->blocks) === 4, 'if generates 4 blocks (entry + then + else + merge)');

$entryBlock = $func->blocks[$func->entryBlockId];
$lastInst = end($entryBlock->instructions);
check($lastInst->op === OpCode::BR, 'entry block ends with BR');
check(count($lastInst->operands) === 1, 'BR has 1 operand (cond)');
check(isset($lastInst->extra['then_block']) && isset($lastInst->extra['else_block']), 'BR has then_block/else_block');

// then 与 else 块应以 JMP 收尾
$jmpCount = 0;
foreach ($func->blocks as $block) {
    if (count($block->instructions) > 0) {
        $last = end($block->instructions);
        if ($last->op === OpCode::JMP) {
            $jmpCount++;
        }
    }
}
check($jmpCount === 2, 'then and else blocks both end with JMP');

// merge 块应有 2 个前驱
$mergeBlock = null;
foreach ($func->blocks as $b) {
    if ($b->label === 'if.merge') {
        $mergeBlock = $b;
        break;
    }
}
check($mergeBlock !== null, 'if.merge block exists');
check($mergeBlock !== null && count($mergeBlock->predecessors) === 2, 'merge block has 2 predecessors');
check($mergeBlock !== null && $mergeBlock->isMergePoint(), 'merge block isMergePoint()');

// ═══════════════════════════════════════════════════════════════
// 测试 7: while 循环 SSA — while ($c) { $x = 1; } return $x;
//   验证 cond + body + exit 块与回边结构
// ═══════════════════════════════════════════════════════════════
$ast = new FlatAst();
$cond = mkVar($ast, '$c');
$bodyAssign = mkAssign($ast, '$x', mkIntLit($ast, 1));
$while = mkWhile($ast, $cond, [$bodyAssign]);
$ret = mkReturn($ast, mkVar($ast, '$x'));
$fnIdx = mkFunction($ast, 'f', [['name' => '$c', 'type' => 'bool'], ['name' => '$x', 'type' => 'int']], [$while, $ret], 'int');

$func = (new SSABuilder())->build($ast, $fnIdx, false);
$labels = array_map(fn($b) => $b->label, $func->blocks);
check(in_array('while.cond', $labels), 'has while.cond block');
check(in_array('while.body', $labels), 'has while.body block');
check(in_array('while.exit', $labels), 'has while.exit block');

$condBlock = null;
$bodyBlock = null;
foreach ($func->blocks as $b) {
    if ($b->label === 'while.cond') $condBlock = $b;
    if ($b->label === 'while.body') $bodyBlock = $b;
}
check($condBlock !== null, 'while.cond block found');
check($condBlock !== null && count($condBlock->predecessors) === 2, 'while.cond has 2 preds (entry + body back-edge)');
check($condBlock !== null && end($condBlock->instructions)->op === OpCode::BR, 'while.cond ends with BR');

check($bodyBlock !== null, 'while.body block found');
$bodyLast = $bodyBlock ? end($bodyBlock->instructions) : null;
check($bodyLast !== null && $bodyLast->op === OpCode::JMP, 'while.body ends with JMP');
check($bodyLast !== null && $bodyLast->extra['target_block'] === $condBlock->id, 'while.body JMPs back to while.cond');

// ═══════════════════════════════════════════════════════════════
// 测试 8: Phi 节点插入 — if/else 汇合点
//   $x = 0; if (true) { $x = 1; } else { $x = 2; } return $x;
//   验证 merge 块插入了 PHI，且 incoming 值来自 then/else
// ═══════════════════════════════════════════════════════════════
$ast = new FlatAst();
$cond = mkBoolLit($ast, true);
$thenAssign = mkAssign($ast, '$x', mkIntLit($ast, 1));
$elseAssign = mkAssign($ast, '$x', mkIntLit($ast, 2));
$if = mkIf($ast, $cond, [$thenAssign], [$elseAssign]);
$ret = mkReturn($ast, mkVar($ast, '$x'));
$fnIdx = mkFunction($ast, 'f', [['name' => '$x', 'type' => 'int']], [$if, $ret], 'int');

// 带 phi 构建
$func = (new SSABuilder())->build($ast, $fnIdx, true);
$mergeBlock = null;
foreach ($func->blocks as $b) {
    if ($b->label === 'if.merge') {
        $mergeBlock = $b;
        break;
    }
}
check($mergeBlock !== null, 'phi test: if.merge block exists');
$phiInst = null;
if ($mergeBlock) {
    foreach ($mergeBlock->instructions as $inst) {
        if ($inst->op === OpCode::PHI) {
            $phiInst = $inst;
            break;
        }
    }
}
check($phiInst !== null, 'PHI instruction inserted at merge block');
check($phiInst !== null && count($phiInst->operands) === 2, 'PHI has 2 incoming values');
check($phiInst !== null && $phiInst->operands[0] !== $phiInst->operands[1], 'PHI incoming values differ');
check($phiInst !== null && count($phiInst->extra['blocks']) === 2, 'PHI has 2 source blocks');
check($phiInst !== null && $phiInst->dst !== null, 'PHI has dst value');
// PHI 应位于 merge 块的第一条
check($mergeBlock !== null && count($mergeBlock->instructions) > 0 && $mergeBlock->instructions[0]->op === OpCode::PHI, 'PHI is first instruction in merge');
// RET 应引用 PHI 结果（use 重写）
$retInst = findInst($func, OpCode::RET);
check($retInst !== null && $retInst->operands[0] === $phiInst->dst, 'RET uses PHI result (use rewritten)');

// 不带 phi 构建 → 无 PHI 指令
$funcNoPhi = (new SSABuilder())->build($ast, $fnIdx, false);
check(countInst($funcNoPhi, OpCode::PHI) === 0, 'no PHI when runPhi=false');

// ═══════════════════════════════════════════════════════════════
// 测试 9: SSA 文本输出（dump 格式）
// ═══════════════════════════════════════════════════════════════
$dump = dumpSSAFunction($func);
check(str_contains($dump, 'function f'), 'dump contains function signature');
check(str_contains($dump, 'block_0'), 'dump contains block_0');
check(str_contains($dump, 'PHI'), 'dump contains PHI');
check(str_contains($dump, 'CONST_INT'), 'dump contains CONST_INT');
check(str_contains($dump, 'CONST_BOOL'), 'dump contains CONST_BOOL');
check(str_contains($dump, 'RET'), 'dump contains RET');
check(str_contains($dump, 'COPY'), 'dump contains COPY');
check(str_contains($dump, 'BR'), 'dump contains BR');
check(str_contains($dump, 'JMP'), 'dump contains JMP');
check(str_contains($dump, '[entry]'), 'dump marks entry block');

// ═══════════════════════════════════════════════════════════════
// 测试 10: echo 与 CALL 指令
//   function f(int $a): int { echo $a; return $a; }
// ═══════════════════════════════════════════════════════════════
$ast = new FlatAst();
$varA = mkVar($ast, '$a');
$echo = mkEcho($ast, [$varA]);
$ret = mkReturn($ast, mkVar($ast, '$a'));
$fnIdx = mkFunction($ast, 'f', [['name' => '$a', 'type' => 'int']], [$echo, $ret], 'int');
$func = (new SSABuilder())->build($ast, $fnIdx, false);
$callInst = findInst($func, OpCode::CALL);
check($callInst !== null, 'echo generates CALL instruction');
check($callInst !== null && ($callInst->extra['func'] ?? '') === 'echo', 'CALL func = echo');
check($callInst !== null && count($callInst->operands) === 1, 'echo CALL has 1 arg');

// ═══════════════════════════════════════════════════════════════
// 测试 11: 一元运算 NEG / NOT
//   function f(int $a): int { return -$a; }
//   function g(bool $b): bool { return !$b; }
// ═══════════════════════════════════════════════════════════════
$ast = new FlatAst();
$neg = $ast->makeNode(NodeKind::UnaryExpr, '-', [mkVar($ast, '$a')]);
$fnIdx = mkFunction($ast, 'f', [['name' => '$a', 'type' => 'int']], [mkReturn($ast, $neg)], 'int');
$func = (new SSABuilder())->build($ast, $fnIdx, false);
check(findInst($func, OpCode::NEG) !== null, 'unary - generates NEG');

$ast2 = new FlatAst();
$not = $ast2->makeNode(NodeKind::UnaryExpr, '!', [mkVar($ast2, '$b')]);
$fnIdx2 = mkFunction($ast2, 'g', [['name' => '$b', 'type' => 'bool']], [mkReturn($ast2, $not)], 'bool');
$func2 = (new SSABuilder())->build($ast2, $fnIdx2, false);
check(findInst($func2, OpCode::NOT) !== null, 'unary ! generates NOT');

// ═══════════════════════════════════════════════════════════════
// 测试 12: 比较运算生成 bool 类型结果
//   function f(int $a, int $b): bool { return $a < $b; }
// ═══════════════════════════════════════════════════════════════
$ast = new FlatAst();
$lt = mkBinary($ast, '<', mkVar($ast, '$a'), mkVar($ast, '$b'));
$fnIdx = mkFunction($ast, 'f', [['name' => '$a', 'type' => 'int'], ['name' => '$b', 'type' => 'int']], [mkReturn($ast, $lt)], 'bool');
$func = (new SSABuilder())->build($ast, $fnIdx, false);
$ltInst = findInst($func, OpCode::LT);
check($ltInst !== null, 'LT instruction generated for <');
check($ltInst !== null && $func->values[$ltInst->dst]->type->equals(SSAType::bool()), 'LT result type = bool');

// ═══════════════════════════════════════════════════════════════
// 汇总
// ═══════════════════════════════════════════════════════════════
echo "\n";
echo "====================================\n";
echo "SSA 中间表示层单元测试结果\n";
echo "====================================\n";
echo "通过: {$pass}\n";
echo "失败: {$fail}\n";
echo "OpCode 枚举值数: " . count(OpCode::cases()) . "\n";
echo "SSATypeKind 枚举值数: " . count(SSATypeKind::cases()) . "\n";
echo "====================================\n";

// 输出一个简单函数的 SSA dump 示例
echo "\n--- SSA dump 示例（if/else + phi）---\n";
echo $dump;

if ($fail > 0) {
    echo "\n失败用例：\n";
    foreach ($failures as $f) {
        echo "  - {$f}\n";
    }
    exit(1);
}

echo "\n[OK] 全部测试通过\n";
exit(0);
