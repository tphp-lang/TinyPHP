<?php

declare(strict_types=1);

// ============================================================
// SSA 优化 Pass 单元测试（Task 13）
//
// 运行方式：
//   cd c:\project\php\TinyPHP
//   php tools/SSA/opt_pass_test.php
//
// 测试范围：
//   1.  CFG 构建（邻接表）
//   2.  支配树构建（Cooper-Harvey-Kennedy，if/else 结构）
//   3.  支配前沿计算
//   4.  常量折叠：1 + 2 → 3
//   5.  常量折叠：5 == 5 → true
//   6.  常量折叠：true && false → false
//   7.  常量折叠：10 - 4 → 6（减法）
//   8.  常量折叠：!true → false（NOT）
//   9.  死代码消除：未使用的赋值被删除
//   10. 死代码消除：保留有副作用的指令（CALL）
//   11. 死代码消除：保留有副作用的指令（STORE）
//   12. 死块消除：不可达块被删除
//   13. Phi 简化：所有 incoming 相同 → 替换
//   14. Phi 简化：单个 incoming → 替换
//   15. Phi 简化：自引用移除
//   16. runAll 综合优化
//   17. runUntilFixpoint 收敛
//
// 断言总数：>= 20
// ============================================================

require_once __DIR__ . '/../../src/AST/FlatAst.php';
require_once __DIR__ . '/../../src/SSA/SSA.php';
require_once __DIR__ . '/../../src/SSA/SSABuilder.php';
require_once __DIR__ . '/../../src/SSA/SSAOptPass.php';

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
// FlatAst 构建辅助（与 ssa_test.php 一致）
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

/** 统计函数中所有指令数 */
function countAllInst(SSAFunction $func): int
{
    $n = 0;
    foreach ($func->blocks as $block) {
        $n += count($block->instructions);
    }
    return $n;
}

/** 查找指定 dst value id 的指令 */
function findInstByDst(SSAFunction $func, int $dst): ?SSAInstruction
{
    foreach ($func->blocks as $block) {
        foreach ($block->instructions as $inst) {
            if ($inst->dst === $dst) {
                return $inst;
            }
        }
    }
    return null;
}

$opt = new SSAOptPass();

// ═══════════════════════════════════════════════════════════════
// 测试 1: CFG 构建（if/else 结构，4 块）
//   function f(bool $c): int { if ($c) { $x = 1; } else { $x = 2; } return $x; }
// ═══════════════════════════════════════════════════════════════
$ast = new FlatAst();
$cond = mkVar($ast, '$c');
$thenAssign = mkAssign($ast, '$x', mkIntLit($ast, 1));
$elseAssign = mkAssign($ast, '$x', mkIntLit($ast, 2));
$if = mkIf($ast, $cond, [$thenAssign], [$elseAssign]);
$ret = mkReturn($ast, mkVar($ast, '$x'));
$fnIdx = mkFunction($ast, 'f', [['name' => '$c', 'type' => 'bool'], ['name' => '$x', 'type' => 'int']], [$if, $ret], 'int');
$func = (new SSABuilder())->build($ast, $fnIdx, false);

$cfg = $opt->buildCFG($func);
// 找出各 block id
$entryId = $func->entryBlockId;
$thenId = $func->findBlockByLabel('if.then');
$elseId = $func->findBlockByLabel('if.else');
$mergeId = $func->findBlockByLabel('if.merge');

check(count($cfg) === 4, 'CFG: 4 blocks');
check($cfg[$entryId] === [$thenId, $elseId] || $cfg[$entryId] === [$elseId, $thenId],
    'CFG: entry successors = [then, else]');
check(in_array($mergeId, $cfg[$thenId]), 'CFG: then successor includes merge');
check(in_array($mergeId, $cfg[$elseId]), 'CFG: else successor includes merge');
check(count($cfg[$mergeId]) === 0, 'CFG: merge has no successors');

// ═══════════════════════════════════════════════════════════════
// 测试 2: 支配树构建（if/else 结构）
//   entry 支配 then/else/merge；then/else 不支配 merge（merge 是它们的 DF）
// ═══════════════════════════════════════════════════════════════
$idom = $opt->buildDominatorTree($func);
check($idom[$entryId] === $entryId, 'idom[entry] = entry (self-dom)');
check($idom[$thenId] === $entryId, 'idom[then] = entry');
check($idom[$elseId] === $entryId, 'idom[else] = entry');
check($idom[$mergeId] === $entryId, 'idom[merge] = entry');

// ═══════════════════════════════════════════════════════════════
// 测试 3: 支配前沿
//   DF[then] = {merge}, DF[else] = {merge}, DF[entry] = {}, DF[merge] = {}
// ═══════════════════════════════════════════════════════════════
$df = $opt->dominanceFrontiers($func, $idom);
check(in_array($mergeId, $df[$thenId]), 'DF[then] contains merge');
check(in_array($mergeId, $df[$elseId]), 'DF[else] contains merge');
check(count($df[$entryId]) === 0, 'DF[entry] is empty');
check(count($df[$mergeId]) === 0, 'DF[merge] is empty');

// ═══════════════════════════════════════════════════════════════
// 测试 4: 常量折叠 1 + 2 → 3
//   function f(): int { return 1 + 2; }
// ═══════════════════════════════════════════════════════════════
$ast = new FlatAst();
$add = mkBinary($ast, '+', mkIntLit($ast, 1), mkIntLit($ast, 2));
$fnIdx = mkFunction($ast, 'f', [], [mkReturn($ast, $add)], 'int');
$func = (new SSABuilder())->build($ast, $fnIdx, false);

$beforeAddCount = countInst($func, OpCode::ADD);
check($beforeAddCount === 1, 'const fold 1+2: ADD exists before folding');

$opt->constantFolding($func);
check(countInst($func, OpCode::ADD) === 0, 'const fold 1+2: ADD removed after folding');
// 找到折叠后的 CONST_INT 3
$foldedConst = null;
foreach ($func->blocks as $block) {
    foreach ($block->instructions as $inst) {
        if ($inst->op === OpCode::CONST_INT && ($inst->extra['value'] ?? null) === 3) {
            $foldedConst = $inst;
            break 2;
        }
    }
}
check($foldedConst !== null, 'const fold 1+2: CONST_INT 3 produced');

// ═══════════════════════════════════════════════════════════════
// 测试 5: 常量折叠 5 == 5 → true
//   function f(): bool { return 5 == 5; }
// ═══════════════════════════════════════════════════════════════
$ast = new FlatAst();
$eq = mkBinary($ast, '==', mkIntLit($ast, 5), mkIntLit($ast, 5));
$fnIdx = mkFunction($ast, 'f', [], [mkReturn($ast, $eq)], 'bool');
$func = (new SSABuilder())->build($ast, $fnIdx, false);

$opt->constantFolding($func);
check(countInst($func, OpCode::EQ) === 0, 'const fold 5==5: EQ removed');
$foldedBool = null;
foreach ($func->blocks as $block) {
    foreach ($block->instructions as $inst) {
        if ($inst->op === OpCode::CONST_BOOL && ($inst->extra['value'] ?? null) === true) {
            $foldedBool = $inst;
            break 2;
        }
    }
}
check($foldedBool !== null, 'const fold 5==5: CONST_BOOL true produced');

// ═══════════════════════════════════════════════════════════════
// 测试 6: 常量折叠 true && false → false
//   function f(): bool { return true && false; }
// ═══════════════════════════════════════════════════════════════
$ast = new FlatAst();
$and = mkBinary($ast, '&&', mkBoolLit($ast, true), mkBoolLit($ast, false));
$fnIdx = mkFunction($ast, 'f', [], [mkReturn($ast, $and)], 'bool');
$func = (new SSABuilder())->build($ast, $fnIdx, false);

$opt->constantFolding($func);
check(countInst($func, OpCode::AND) === 0, 'const fold true&&false: AND removed');
$foldedFalse = null;
foreach ($func->blocks as $block) {
    foreach ($block->instructions as $inst) {
        if ($inst->op === OpCode::CONST_BOOL && ($inst->extra['value'] ?? null) === false) {
            $foldedFalse = $inst;
            break 2;
        }
    }
}
check($foldedFalse !== null, 'const fold true&&false: CONST_BOOL false produced');

// ═══════════════════════════════════════════════════════════════
// 测试 7: 常量折叠 10 - 4 → 6（减法）
// ═══════════════════════════════════════════════════════════════
$ast = new FlatAst();
$sub = mkBinary($ast, '-', mkIntLit($ast, 10), mkIntLit($ast, 4));
$fnIdx = mkFunction($ast, 'f', [], [mkReturn($ast, $sub)], 'int');
$func = (new SSABuilder())->build($ast, $fnIdx, false);

$opt->constantFolding($func);
check(countInst($func, OpCode::SUB) === 0, 'const fold 10-4: SUB removed');
$found6 = false;
foreach ($func->blocks as $block) {
    foreach ($block->instructions as $inst) {
        if ($inst->op === OpCode::CONST_INT && ($inst->extra['value'] ?? null) === 6) {
            $found6 = true;
            break 2;
        }
    }
}
check($found6, 'const fold 10-4: CONST_INT 6 produced');

// ═══════════════════════════════════════════════════════════════
// 测试 8: 常量折叠 !true → false（NOT）
// ═══════════════════════════════════════════════════════════════
$ast = new FlatAst();
$not = $ast->makeNode(NodeKind::UnaryExpr, '!', [mkBoolLit($ast, true)]);
$fnIdx = mkFunction($ast, 'f', [], [mkReturn($ast, $not)], 'bool');
$func = (new SSABuilder())->build($ast, $fnIdx, false);

$opt->constantFolding($func);
check(countInst($func, OpCode::NOT) === 0, 'const fold !true: NOT removed');
$foundNotFalse = false;
foreach ($func->blocks as $block) {
    foreach ($block->instructions as $inst) {
        if ($inst->op === OpCode::CONST_BOOL && ($inst->extra['value'] ?? null) === false) {
            $foundNotFalse = true;
            break 2;
        }
    }
}
check($foundNotFalse, 'const fold !true: CONST_BOOL false produced');

// ═══════════════════════════════════════════════════════════════
// 测试 9: 死代码消除 — 未使用的赋值被删除
//   function f(): int { $x = 1; return 0; }
//   $x 赋值后未使用，应被删除
// ═══════════════════════════════════════════════════════════════
$ast = new FlatAst();
$assign = mkAssign($ast, '$x', mkIntLit($ast, 1));
$ret = mkReturn($ast, mkIntLit($ast, 0));
$fnIdx = mkFunction($ast, 'f', [], [$assign, $ret], 'int');
$func = (new SSABuilder())->build($ast, $fnIdx, false);

$beforeCopyCount = countInst($func, OpCode::COPY);
check($beforeCopyCount >= 1, 'DCE: COPY exists before optimization');

$opt->deadCodeElimination($func);
// $x 的 COPY 应被删除（其 dst 未被 RET 使用）
$copyDeleted = true;
foreach ($func->blocks as $block) {
    foreach ($block->instructions as $inst) {
        if ($inst->op === OpCode::COPY) {
            $copyDeleted = false;
            break 2;
        }
    }
}
check($copyDeleted, 'DCE: unused COPY removed');
// RET 应保留
check(findInst($func, OpCode::RET) !== null, 'DCE: RET preserved');

// ═══════════════════════════════════════════════════════════════
// 测试 10: 死代码消除 — 保留有副作用的指令（CALL）
//   function f(int $a): int { echo $a; return $a; }
//   echo 生成 CALL，有副作用，应保留
// ═══════════════════════════════════════════════════════════════
$ast = new FlatAst();
$echo = mkEcho($ast, [mkVar($ast, '$a')]);
$ret = mkReturn($ast, mkVar($ast, '$a'));
$fnIdx = mkFunction($ast, 'f', [['name' => '$a', 'type' => 'int']], [$echo, $ret], 'int');
$func = (new SSABuilder())->build($ast, $fnIdx, false);

$opt->deadCodeElimination($func);
$callInst = findInst($func, OpCode::CALL);
check($callInst !== null, 'DCE: CALL (echo) preserved');
check($callInst !== null && ($callInst->extra['func'] ?? '') === 'echo', 'DCE: CALL func = echo');
check(findInst($func, OpCode::RET) !== null, 'DCE: RET preserved');

// ═══════════════════════════════════════════════════════════════
// 测试 11: 死代码消除 — 保留有副作用的指令（STORE）
//   手动构建：ALLOCA + STORE + RET
// ═══════════════════════════════════════════════════════════════
$func = new SSAFunction('store_test', SSAType::int(), []);
$entryId = $func->newBlock('entry');
$func->entryBlockId = $entryId;

$ptrVal = $func->newValue(SSAType::ptr(SSAType::int()));    // v0
$constVal = $func->newValue(SSAType::int());                 // v1
$unusedVal = $func->newValue(SSAType::int());                // v2 (unused, should be removed)

$func->appendInst($entryId, new SSAInstruction(OpCode::ALLOCA, $ptrVal, [], ['elem_type' => SSAType::int(), 'count' => 1]));
$func->appendInst($entryId, new SSAInstruction(OpCode::CONST_INT, $constVal, [], ['value' => 42]));
$func->appendInst($entryId, new SSAInstruction(OpCode::STORE, null, [$ptrVal, $constVal]));
$func->appendInst($entryId, new SSAInstruction(OpCode::CONST_INT, $unusedVal, [], ['value' => 99]));
$func->appendInst($entryId, new SSAInstruction(OpCode::RET, null, [$constVal]));

$opt->deadCodeElimination($func);
check(findInst($func, OpCode::STORE) !== null, 'DCE: STORE preserved');
check(findInst($func, OpCode::ALLOCA) !== null, 'DCE: ALLOCA preserved (used by STORE)');
// unused CONST_INT 99 应被删除
$has99 = false;
foreach ($func->blocks as $block) {
    foreach ($block->instructions as $inst) {
        if ($inst->op === OpCode::CONST_INT && ($inst->extra['value'] ?? null) === 99) {
            $has99 = true;
            break 2;
        }
    }
}
check(!$has99, 'DCE: unused CONST_INT 99 removed');

// ═══════════════════════════════════════════════════════════════
// 测试 12: 死块消除 — 不可达块被删除
//   手动构建：entry → merge，外加一个不可达块 unreachable → merge
// ═══════════════════════════════════════════════════════════════
$func = new SSAFunction('dead_block_test', SSAType::int(), []);
$entryId = $func->newBlock('entry');
$func->entryBlockId = $entryId;
$mergeId = $func->newBlock('merge');
$unreachableId = $func->newBlock('unreachable');

$v0 = $func->newValue(SSAType::int());

// entry: CONST_INT 5, JMP merge
$func->appendInst($entryId, new SSAInstruction(OpCode::CONST_INT, $v0, [], ['value' => 5]));
$func->appendInst($entryId, new SSAInstruction(OpCode::JMP, null, [], ['target_block' => $mergeId]));
$func->addEdge($entryId, $mergeId);

// merge: RET v0
$func->appendInst($mergeId, new SSAInstruction(OpCode::RET, null, [$v0]));
// 注意：merge 的 predecessors 已通过 addEdge 设置为 [entry]

// unreachable: RET v0（不可达，应被删除）
$func->appendInst($unreachableId, new SSAInstruction(OpCode::RET, null, [$v0]));
// 手动添加 unreachable → merge 的边（模拟错误的前驱关系）
$func->blocks[$mergeId]->predecessors[] = $unreachableId;
$func->blocks[$unreachableId]->successors[] = $mergeId;

check(count($func->blocks) === 3, 'DBE: 3 blocks before optimization');

$opt->deadBlockElimination($func);
check(count($func->blocks) === 2, 'DBE: unreachable block removed (2 blocks remain)');
check(!isset($func->blocks[$unreachableId]), 'DBE: unreachable block id no longer exists');
// merge 的 predecessors 应不再包含 unreachable
check(!in_array($unreachableId, $func->blocks[$mergeId]->predecessors), 'DBE: unreachable removed from merge predecessors');

// ═══════════════════════════════════════════════════════════════
// 测试 13: Phi 简化 — 所有 incoming 相同 → 替换
//   手动构建：PHI [v0 from then, v0 from else] → 替换为 v0
// ═══════════════════════════════════════════════════════════════
$func = new SSAFunction('phi_same', SSAType::int(), []);
$entryId = $func->newBlock('entry');
$func->entryBlockId = $entryId;
$thenId = $func->newBlock('then');
$elseId = $func->newBlock('else');
$mergeId = $func->newBlock('merge');

$v0 = $func->newValue(SSAType::int());  // 常量 5
$v1 = $func->newValue(SSAType::bool()); // 条件
$v2 = $func->newValue(SSAType::int());  // PHI dst

// entry: CONST_INT 5, CONST_BOOL true, BR v1, then, else
$func->appendInst($entryId, new SSAInstruction(OpCode::CONST_INT, $v0, [], ['value' => 5]));
$func->appendInst($entryId, new SSAInstruction(OpCode::CONST_BOOL, $v1, [], ['value' => true]));
$func->appendInst($entryId, new SSAInstruction(OpCode::BR, null, [$v1], ['then_block' => $thenId, 'else_block' => $elseId]));
$func->addEdge($entryId, $thenId);
$func->addEdge($entryId, $elseId);

// then: JMP merge
$func->appendInst($thenId, new SSAInstruction(OpCode::JMP, null, [], ['target_block' => $mergeId]));
$func->addEdge($thenId, $mergeId);

// else: JMP merge
$func->appendInst($elseId, new SSAInstruction(OpCode::JMP, null, [], ['target_block' => $mergeId]));
$func->addEdge($elseId, $mergeId);

// merge: PHI [v0 from then, v0 from else], RET v2
$func->appendInst($mergeId, new SSAInstruction(OpCode::PHI, $v2, [$v0, $v0], ['blocks' => [$thenId, $elseId]]));
$func->appendInst($mergeId, new SSAInstruction(OpCode::RET, null, [$v2]));

$phiBefore = countInst($func, OpCode::PHI);
check($phiBefore === 1, 'phi-same: PHI exists before simplification');

$opt->phiSimplification($func);
check(countInst($func, OpCode::PHI) === 0, 'phi-same: PHI removed (all incoming same)');
// RET 应引用 v0（替换后）
$retInst = findInst($func, OpCode::RET);
check($retInst !== null && $retInst->operands[0] === $v0, 'phi-same: RET now references v0 (replaced)');

// ═══════════════════════════════════════════════════════════════
// 测试 14: Phi 简化 — 单个 incoming → 替换
//   手动构建：PHI [v0 from then]（只有一个 incoming）
// ═══════════════════════════════════════════════════════════════
$func = new SSAFunction('phi_single', SSAType::int(), []);
$entryId = $func->newBlock('entry');
$func->entryBlockId = $entryId;
$thenId = $func->newBlock('then');
$mergeId = $func->newBlock('merge');

$v0 = $func->newValue(SSAType::int());  // 常量 7
$v1 = $func->newValue(SSAType::int());  // PHI dst

// entry: CONST_INT 7, JMP then
$func->appendInst($entryId, new SSAInstruction(OpCode::CONST_INT, $v0, [], ['value' => 7]));
$func->appendInst($entryId, new SSAInstruction(OpCode::JMP, null, [], ['target_block' => $thenId]));
$func->addEdge($entryId, $thenId);

// then: JMP merge
$func->appendInst($thenId, new SSAInstruction(OpCode::JMP, null, [], ['target_block' => $mergeId]));
$func->addEdge($thenId, $mergeId);

// merge: PHI [v0 from then], RET v1
$func->appendInst($mergeId, new SSAInstruction(OpCode::PHI, $v1, [$v0], ['blocks' => [$thenId]]));
$func->appendInst($mergeId, new SSAInstruction(OpCode::RET, null, [$v1]));

$opt->phiSimplification($func);
check(countInst($func, OpCode::PHI) === 0, 'phi-single: PHI removed (single incoming)');
$retInst = findInst($func, OpCode::RET);
check($retInst !== null && $retInst->operands[0] === $v0, 'phi-single: RET references v0 (replaced)');

// ═══════════════════════════════════════════════════════════════
// 测试 15: Phi 简化 — 自引用移除
//   PHI [v0 from b0, v1 from b1, v1 from b2]（v1 是 phi dst，自引用）
//   移除自引用后只剩 [v0 from b0]，应替换为 v0
// ═══════════════════════════════════════════════════════════════
$func = new SSAFunction('phi_self', SSAType::int(), []);
$entryId = $func->newBlock('entry');
$func->entryBlockId = $entryId;
$b1Id = $func->newBlock('b1');
$b2Id = $func->newBlock('b2');
$mergeId = $func->newBlock('merge');

$v0 = $func->newValue(SSAType::int());  // 常量 9
$v1 = $func->newValue(SSAType::int());  // PHI dst（自引用）

// entry: CONST_INT 9, JMP b1
$func->appendInst($entryId, new SSAInstruction(OpCode::CONST_INT, $v0, [], ['value' => 9]));
$func->appendInst($entryId, new SSAInstruction(OpCode::JMP, null, [], ['target_block' => $b1Id]));
$func->addEdge($entryId, $b1Id);

// b1: JMP merge
$func->appendInst($b1Id, new SSAInstruction(OpCode::JMP, null, [], ['target_block' => $mergeId]));
$func->addEdge($b1Id, $mergeId);

// b2: JMP merge
$func->appendInst($b2Id, new SSAInstruction(OpCode::JMP, null, [], ['target_block' => $mergeId]));
$func->addEdge($b2Id, $mergeId);

// merge: PHI [v0 from b1, v1 from b2 (self-ref), v1 from entry (self-ref)], RET v1
// 注意：自引用 entry 通常出现在循环回边场景
$func->appendInst($mergeId, new SSAInstruction(
    OpCode::PHI, $v1, [$v0, $v1, $v1], ['blocks' => [$b1Id, $b2Id, $entryId]]
));
$func->appendInst($mergeId, new SSAInstruction(OpCode::RET, null, [$v1]));

$opt->phiSimplification($func);
// 移除自引用后只剩 v0，应替换
check(countInst($func, OpCode::PHI) === 0, 'phi-self: PHI removed (self-refs removed, single unique remaining)');
$retInst = findInst($func, OpCode::RET);
check($retInst !== null && $retInst->operands[0] === $v0, 'phi-self: RET references v0 (self-ref removed and replaced)');

// ═══════════════════════════════════════════════════════════════
// 测试 16: runAll 综合优化
//   function f(): int { $x = 1 + 2; return $x; }
//   优化后应只剩 CONST_INT 3 + RET
// ═══════════════════════════════════════════════════════════════
$ast = new FlatAst();
$add = mkBinary($ast, '+', mkIntLit($ast, 1), mkIntLit($ast, 2));
$assign = mkAssign($ast, '$x', $add);
$ret = mkReturn($ast, mkVar($ast, '$x'));
$fnIdx = mkFunction($ast, 'f', [], [$assign, $ret], 'int');
$func = (new SSABuilder())->build($ast, $fnIdx, false);

$beforeInstCount = countAllInst($func);
$opt->runAll($func);
$afterInstCount = countAllInst($func);

check($afterInstCount < $beforeInstCount, 'runAll: instruction count reduced');
check($afterInstCount === 2, 'runAll: optimized to 2 instructions (CONST_INT 3 + RET)');
// 验证最终只剩 CONST_INT 3 和 RET
$hasConst3 = false;
$hasRet = false;
foreach ($func->blocks as $block) {
    foreach ($block->instructions as $inst) {
        if ($inst->op === OpCode::CONST_INT && ($inst->extra['value'] ?? null) === 3) {
            $hasConst3 = true;
        }
        if ($inst->op === OpCode::RET) {
            $hasRet = true;
        }
    }
}
check($hasConst3, 'runAll: CONST_INT 3 present');
check($hasRet, 'runAll: RET present');
check(countInst($func, OpCode::ADD) === 0, 'runAll: ADD eliminated');
check(countInst($func, OpCode::COPY) === 0, 'runAll: COPY eliminated');

// ═══════════════════════════════════════════════════════════════
// 测试 17: runUntilFixpoint 收敛
//   构建一个需要多轮优化的函数
//   function f(): int { $x = 1 + 2; $y = $x + 3; return $y; }
//   第一轮：1+2→3，$x=COPY(3)；第二轮：COPY 传播，3+3→6
// ═══════════════════════════════════════════════════════════════
$ast = new FlatAst();
$innerAdd = mkBinary($ast, '+', mkIntLit($ast, 1), mkIntLit($ast, 2));
$assignX = mkAssign($ast, '$x', $innerAdd);
$varX = mkVar($ast, '$x');
$outerAdd = mkBinary($ast, '+', $varX, mkIntLit($ast, 3));
$assignY = mkAssign($ast, '$y', $outerAdd);
$ret = mkReturn($ast, mkVar($ast, '$y'));
$fnIdx = mkFunction($ast, 'f', [], [$assignX, $assignY, $ret], 'int');
$func = (new SSABuilder())->build($ast, $fnIdx, false);

$beforeDump = dumpSSAFunction($func);
$opt->runUntilFixpoint($func, 10);
$afterDump = dumpSSAFunction($func);

check($beforeDump !== $afterDump, 'fixpoint: SSA changed after optimization');
check(countInst($func, OpCode::ADD) === 0, 'fixpoint: all ADD folded');
check(countInst($func, OpCode::COPY) === 0, 'fixpoint: all COPY eliminated');
// 最终应有 CONST_INT 6 (1+2+3)
$hasConst6 = false;
foreach ($func->blocks as $block) {
    foreach ($block->instructions as $inst) {
        if ($inst->op === OpCode::CONST_INT && ($inst->extra['value'] ?? null) === 6) {
            $hasConst6 = true;
            break 2;
        }
    }
}
check($hasConst6, 'fixpoint: CONST_INT 6 produced (1+2+3)');

// ═══════════════════════════════════════════════════════════════
// 测试 18: 常量条件跳转折叠 + 死块消除
//   function f(bool $c): int { if (true) { $x = 1; } else { $x = 2; } return $x; }
//   BR true → JMP then；else 块变为不可达，被删除
// ═══════════════════════════════════════════════════════════════
$ast = new FlatAst();
$cond = mkBoolLit($ast, true);
$thenAssign = mkAssign($ast, '$x', mkIntLit($ast, 1));
$elseAssign = mkAssign($ast, '$x', mkIntLit($ast, 2));
$if = mkIf($ast, $cond, [$thenAssign], [$elseAssign]);
$ret = mkReturn($ast, mkVar($ast, '$x'));
$fnIdx = mkFunction($ast, 'f', [['name' => '$x', 'type' => 'int']], [$if, $ret], 'int');
$func = (new SSABuilder())->build($ast, $fnIdx, true); // 插入 phi

$beforeBlockCount = count($func->blocks);
$opt->runAll($func);
$afterBlockCount = count($func->blocks);

check($afterBlockCount < $beforeBlockCount, 'branch-fold: block count reduced');
check(countInst($func, OpCode::BR) === 0, 'branch-fold: BR replaced with JMP (or removed)');
// else 块应被删除
$hasElseBlock = false;
foreach ($func->blocks as $block) {
    if ($block->label === 'if.else') {
        $hasElseBlock = true;
        break;
    }
}
check(!$hasElseBlock, 'branch-fold: if.else block removed (dead)');

// ═══════════════════════════════════════════════════════════════
// 汇总
// ═══════════════════════════════════════════════════════════════
echo "\n";
echo "====================================\n";
echo "SSA 优化 Pass 单元测试结果\n";
echo "====================================\n";
echo "通过: {$pass}\n";
echo "失败: {$fail}\n";
echo "====================================\n";

// 输出优化前后对比示例
echo "\n--- 优化前后对比（runAll 示例）---\n";
$ast = new FlatAst();
$add = mkBinary($ast, '+', mkIntLit($ast, 1), mkIntLit($ast, 2));
$assign = mkAssign($ast, '$x', $add);
$ret = mkReturn($ast, mkVar($ast, '$x'));
$fnIdx = mkFunction($ast, 'demo', [], [$assign, $ret], 'int');
$func = (new SSABuilder())->build($ast, $fnIdx, false);
echo "[优化前]\n";
echo dumpSSAFunction($func);
$opt->runAll($func);
echo "[优化后]\n";
echo dumpSSAFunction($func);

if ($fail > 0) {
    echo "\n失败用例：\n";
    foreach ($failures as $f) {
        echo "  - {$f}\n";
    }
    exit(1);
}

echo "\n[OK] 全部测试通过\n";
exit(0);
