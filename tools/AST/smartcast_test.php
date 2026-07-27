<?php

declare(strict_types=1);

// ============================================================
// SmartcastManager 单元测试 — instanceof 类型缩窄
//
// 运行方式：
//   cd c:\project\php\TinyPHP
//   php tools/AST/smartcast_test.php
//
// 测试范围：
//   1.  正向 instanceof：then 分支内 $x 缩窄为 Foo
//   2.  正向 instanceof：else 分支内 $x 类型保持原样
//   3.  否定 instanceof：then 分支内 $x 不缩窄
//   4.  否定 instanceof：else 分支内 $x 缩窄为 Foo
//   5.  elseif 链：各分支缩窄正确
//   6.  非 instanceof 条件：不触发 smartcast
//   7.  嵌套 instanceof：内层缩窄覆盖外层
//   8.  离开作用域后 smartcast 失效
//   9.  getEffectiveType 优先返回 smartcast
//  10.  detectInstanceofPattern 正确识别各种形式
//  11.  多条件 && ：then 分支内全部正向模式缩窄
//  12.  动态类名（$x instanceof $cls）不触发 smartcast
// ============================================================

require_once __DIR__ . '/../../src/AST/FlatAst.php';
require_once __DIR__ . '/../../src/ScopeTree.php';
require_once __DIR__ . '/../../src/Type.php';
require_once __DIR__ . '/../../src/SmartcastManager.php';

Type::init();

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
// AST 构建辅助函数
// ─────────────────────────────────────────────────────────────

/** 构造 instanceof 表达式节点：$varName instanceof $className */
function makeInstanceof(FlatAst $ast, string $varName, string $className): int
{
    $v = $ast->makeNode(NodeKind::VariableExpr, $varName);
    $c = $ast->makeNode(NodeKind::VariableExpr, $className);
    return $ast->makeNode(NodeKind::BinaryExpr, 'instanceof', [$v, $c]);
}

/** 构造 !expr 否定表达式节点 */
function makeNot(FlatAst $ast, int $innerIdx): int
{
    return $ast->makeNode(NodeKind::UnaryExpr, '!', [$innerIdx]);
}

/** 构造 BinaryExpr（用于 && 或比较运算） */
function makeBinary(FlatAst $ast, string $op, int $leftIdx, int $rightIdx): int
{
    return $ast->makeNode(NodeKind::BinaryExpr, $op, [$leftIdx, $rightIdx]);
}

/** 构造 NopStmtNode 占位语句 */
function makeNop(FlatAst $ast): int
{
    return $ast->makeNode(NodeKind::NopStmtNode, null);
}

/**
 * 构造 IfStmtNode。
 *
 * @param FlatAst    $ast
 * @param int        $condIdx      条件节点索引
 * @param array<int> $thenIdxs     then body 语句索引
 * @param array<int> $elseIfIdxs   ElseIfBranch 节点索引数组
 * @param array<int> $elseIdxs     else body 语句索引
 * @return int IfStmtNode 节点索引
 */
function makeIf(FlatAst $ast, int $condIdx, array $thenIdxs, array $elseIfIdxs = [], array $elseIdxs = []): int
{
    $children = array_merge([$condIdx], $thenIdxs, $elseIfIdxs, $elseIdxs);
    return $ast->makeNode(
        NodeKind::IfStmtNode,
        null,
        $children,
        [0, 0],
        [
            'thenBodyCount' => count($thenIdxs),
            'elseifCount'   => count($elseIfIdxs),
            'elseBodyCount' => count($elseIdxs),
        ],
    );
}

/** 构造 ElseIfBranch 节点 */
function makeElseIf(FlatAst $ast, int $condIdx, array $bodyIdxs): int
{
    $children = array_merge([$condIdx], $bodyIdxs);
    return $ast->makeNode(
        NodeKind::ElseIfBranch,
        null,
        $children,
        [0, 0],
        ['bodyCount' => count($bodyIdxs)],
    );
}

// ─────────────────────────────────────────────────────────────
// 测试 1: 正向 instanceof — then 分支内 $x 缩窄为 Foo
//   if ($x instanceof Foo) { nop; }
// ─────────────────────────────────────────────────────────────
$ast1  = new FlatAst();
$cond1 = makeInstanceof($ast1, '$x', 'Foo');
$body1 = makeNop($ast1);
$if1   = makeIf($ast1, $cond1, [$body1]);

$scope1  = new ScopeTree();
$mgr1    = new SmartcastManager($ast1, $scope1);
$pat1    = $mgr1->detectInstanceofPattern($if1);
$thenSc1 = null;
$mgr1->processIfStmt($if1, function (string $branch) use ($mgr1, &$thenSc1) {
    if ($branch === 'then') {
        $thenSc1 = $mgr1->lookupSmartcast('$x');
    }
});

check($pat1 !== null, '测试1: detectInstanceofPattern 返回非 null');
check($pat1['var'] === '$x', '测试1: 模式 var=$x');
check($pat1['class'] === 'Foo', '测试1: 模式 class=Foo');
check($pat1['negated'] === false, '测试1: 模式 negated=false');
check($thenSc1 === 'Foo', '测试1: then 分支内 $x 缩窄为 Foo');

// ─────────────────────────────────────────────────────────────
// 测试 2: 正向 instanceof — else 分支内 $x 类型保持原样
//   if ($x instanceof Foo) { nop; } else { nop; }
// ─────────────────────────────────────────────────────────────
$ast2  = new FlatAst();
$cond2 = makeInstanceof($ast2, '$x', 'Foo');
$then2 = makeNop($ast2);
$else2 = makeNop($ast2);
$if2   = makeIf($ast2, $cond2, [$then2], [], [$else2]);

$scope2 = new ScopeTree();
$mgr2   = new SmartcastManager($ast2, $scope2);
$elseSc2 = null;
$elseEff2 = null;
$mgr2->processIfStmt($if2, function (string $branch) use ($mgr2, &$elseSc2, &$elseEff2) {
    if ($branch === 'else') {
        $elseSc2  = $mgr2->lookupSmartcast('$x');
        $elseEff2 = $mgr2->getEffectiveType('$x', 'object');
    }
});

check($elseSc2 === null, '测试2: else 分支内 $x 无 smartcast');
check($elseEff2 === 'object', '测试2: else 分支内 $x 有效类型保持原样 object');

// ─────────────────────────────────────────────────────────────
// 测试 3: 否定 instanceof — then 分支内 $x 不缩窄
//   if (!($x instanceof Foo)) { nop; }
// ─────────────────────────────────────────────────────────────
$ast3  = new FlatAst();
$cond3 = makeNot($ast3, makeInstanceof($ast3, '$x', 'Foo'));
$body3 = makeNop($ast3);
$if3   = makeIf($ast3, $cond3, [$body3]);

$scope3 = new ScopeTree();
$mgr3   = new SmartcastManager($ast3, $scope3);
$pat3   = $mgr3->detectInstanceofPattern($if3);
$thenSc3 = null;
$mgr3->processIfStmt($if3, function (string $branch) use ($mgr3, &$thenSc3) {
    if ($branch === 'then') {
        $thenSc3 = $mgr3->lookupSmartcast('$x');
    }
});

check($pat3 !== null, '测试3: 否定模式 detect 返回非 null');
check($pat3['negated'] === true, '测试3: 模式 negated=true');
check($thenSc3 === null, '测试3: 否定 then 分支内 $x 不缩窄');

// ─────────────────────────────────────────────────────────────
// 测试 4: 否定 instanceof — else 分支内 $x 缩窄为 Foo
//   if (!($x instanceof Foo)) { nop; } else { nop; }
// ─────────────────────────────────────────────────────────────
$ast4  = new FlatAst();
$cond4 = makeNot($ast4, makeInstanceof($ast4, '$x', 'Foo'));
$then4 = makeNop($ast4);
$else4 = makeNop($ast4);
$if4   = makeIf($ast4, $cond4, [$then4], [], [$else4]);

$scope4 = new ScopeTree();
$mgr4   = new SmartcastManager($ast4, $scope4);
$elseSc4 = null;
$mgr4->processIfStmt($if4, function (string $branch) use ($mgr4, &$elseSc4) {
    if ($branch === 'else') {
        $elseSc4 = $mgr4->lookupSmartcast('$x');
    }
});

check($elseSc4 === 'Foo', '测试4: 否定 else 分支内 $x 缩窄为 Foo');

// ─────────────────────────────────────────────────────────────
// 测试 5: elseif 链 — 各分支缩窄正确
//   if ($x instanceof Foo) { nop; } elseif ($x instanceof Bar) { nop; }
// ─────────────────────────────────────────────────────────────
$ast5   = new FlatAst();
$cond5a = makeInstanceof($ast5, '$x', 'Foo');
$then5  = makeNop($ast5);
$cond5b = makeInstanceof($ast5, '$x', 'Bar');
$elifBody5 = makeNop($ast5);
$elif5  = makeElseIf($ast5, $cond5b, [$elifBody5]);
$if5    = makeIf($ast5, $cond5a, [$then5], [$elif5]);

$scope5 = new ScopeTree();
$mgr5   = new SmartcastManager($ast5, $scope5);
$thenSc5  = null;
$elifSc5  = null;
$mgr5->processIfStmt($if5, function (string $branch) use ($mgr5, &$thenSc5, &$elifSc5) {
    if ($branch === 'then') {
        $thenSc5 = $mgr5->lookupSmartcast('$x');
    } elseif ($branch === 'elseif') {
        $elifSc5 = $mgr5->lookupSmartcast('$x');
    }
});

check($thenSc5 === 'Foo', '测试5: then 分支 $x 缩窄为 Foo');
check($elifSc5 === 'Bar', '测试5: elseif 分支 $x 缩窄为 Bar');

// ─────────────────────────────────────────────────────────────
// 测试 6: 非 instanceof 条件 — 不触发 smartcast
//   if ($x > 0) { nop; }
// ─────────────────────────────────────────────────────────────
$ast6  = new FlatAst();
$lv6   = $ast6->makeNode(NodeKind::VariableExpr, '$x');
$rv6   = $ast6->makeNode(NodeKind::IntLiteralExpr, 0);
$cond6 = makeBinary($ast6, '>', $lv6, $rv6);
$body6 = makeNop($ast6);
$if6   = makeIf($ast6, $cond6, [$body6]);

$scope6 = new ScopeTree();
$mgr6   = new SmartcastManager($ast6, $scope6);
$pat6   = $mgr6->detectInstanceofPattern($if6);
$thenSc6 = 'UNSET';
$mgr6->processIfStmt($if6, function (string $branch) use ($mgr6, &$thenSc6) {
    if ($branch === 'then') {
        $thenSc6 = $mgr6->lookupSmartcast('$x');
    }
});

check($pat6 === null, '测试6: 非 instanceof 条件 detect 返回 null');
check($thenSc6 === null, '测试6: 非 instanceof 条件 then 分支无 smartcast');

// ─────────────────────────────────────────────────────────────
// 测试 7: 嵌套 instanceof — 内层缩窄覆盖外层
//   if ($x instanceof Foo) { if ($x instanceof Bar) { nop; } }
// ─────────────────────────────────────────────────────────────
$ast7   = new FlatAst();
$cond7o = makeInstanceof($ast7, '$x', 'Foo');          // 外层条件
$cond7i = makeInstanceof($ast7, '$x', 'Bar');          // 内层条件
$innerBody7 = makeNop($ast7);
$innerIf7 = makeIf($ast7, $cond7i, [$innerBody7]);
$outerIf7 = makeIf($ast7, $cond7o, [$innerIf7]);

$scope7 = new ScopeTree();
$mgr7   = new SmartcastManager($ast7, $scope7);
$nestedThens7 = [];
$mgr7->processIfStmt($outerIf7, function (string $branch) use ($mgr7, &$nestedThens7) {
    if ($branch === 'then') {
        $nestedThens7[] = $mgr7->lookupSmartcast('$x');
    }
});

check($nestedThens7 === ['Foo', 'Bar'], '测试7: 嵌套 then 分支依次缩窄为 Foo, Bar（内层覆盖外层）');

// ─────────────────────────────────────────────────────────────
// 测试 8: 离开作用域后 smartcast 失效
//   processIfStmt 完成后，当前作用域无 smartcast
// ─────────────────────────────────────────────────────────────
$ast8  = new FlatAst();
$cond8 = makeInstanceof($ast8, '$x', 'Foo');
$body8 = makeNop($ast8);
$if8   = makeIf($ast8, $cond8, [$body8]);

$scope8 = new ScopeTree();
$scope8->declareVar('$x', 'object');
$mgr8   = new SmartcastManager($ast8, $scope8);
$mgr8->processIfStmt($if8);

check($scope8->getCurrent() === $scope8->getRoot(), '测试8: processIfStmt 后 current 回到 root');
check($mgr8->lookupSmartcast('$x') === null, '测试8: 离开作用域后 smartcast 失效');
check($mgr8->getEffectiveType('$x', 'object') === 'object', '测试8: 离开后有效类型恢复为原类型 object');

// ─────────────────────────────────────────────────────────────
// 测试 9: getEffectiveType 优先返回 smartcast
// ─────────────────────────────────────────────────────────────
$scope9 = new ScopeTree();
$ast9   = new FlatAst();
$mgr9   = new SmartcastManager($ast9, $scope9);
$scope9->declareVar('$obj', 'object');
$mgr9->applySmartcast('$obj', 'Bar');

check($mgr9->getEffectiveType('$obj', 'object') === 'Bar', '测试9: 有 smartcast 时返回 Bar');
check($mgr9->getEffectiveType('$obj', Type::$object) === 'Bar', '测试9: 有 smartcast 时覆盖 Type 对象原类型');
$scope9->enterScope(0, 0);
check($mgr9->getEffectiveType('$obj', 'object') === 'Bar', '测试9: 子作用域沿 parent 链继承 smartcast');
$scope9->leaveScope();
check($mgr9->getEffectiveType('$unknown', 'int') === 'int', '测试9: 无 smartcast 时返回原类型 int');

// ─────────────────────────────────────────────────────────────
// 测试 10: detectInstanceofPattern 正确识别各种形式
// ─────────────────────────────────────────────────────────────
// (a) 正向
$ast10a  = new FlatAst();
$cond10a = makeInstanceof($ast10a, '$x', 'Foo');
$if10a   = makeIf($ast10a, $cond10a, [makeNop($ast10a)]);
$mgr10a  = new SmartcastManager($ast10a, new ScopeTree());
$pat10a  = $mgr10a->detectInstanceofPattern($if10a);
check($pat10a === ['var' => '$x', 'class' => 'Foo', 'negated' => false], '测试10a: 正向模式识别正确');

// (b) 否定
$ast10b  = new FlatAst();
$cond10b = makeNot($ast10b, makeInstanceof($ast10b, '$y', 'Baz'));
$if10b   = makeIf($ast10b, $cond10b, [makeNop($ast10b)]);
$mgr10b  = new SmartcastManager($ast10b, new ScopeTree());
$pat10b  = $mgr10b->detectInstanceofPattern($if10b);
check($pat10b === ['var' => '$y', 'class' => 'Baz', 'negated' => true], '测试10b: 否定模式识别正确');

// (c) 多条件 && — 取第一个
$ast10c  = new FlatAst();
$left10c = makeInstanceof($ast10c, '$a', 'A');
$right10c = makeInstanceof($ast10c, '$b', 'B');
$cond10c = makeBinary($ast10c, '&&', $left10c, $right10c);
$if10c   = makeIf($ast10c, $cond10c, [makeNop($ast10c)]);
$mgr10c  = new SmartcastManager($ast10c, new ScopeTree());
$pat10c  = $mgr10c->detectInstanceofPattern($if10c);
check($pat10c === ['var' => '$a', 'class' => 'A', 'negated' => false], '测试10c: && 多条件取第一个模式');

// (d) 非 instanceof
$ast10d  = new FlatAst();
$lv10d   = $ast10d->makeNode(NodeKind::VariableExpr, '$x');
$rv10d   = $ast10d->makeNode(NodeKind::IntLiteralExpr, 1);
$cond10d = makeBinary($ast10d, '==', $lv10d, $rv10d);
$if10d   = makeIf($ast10d, $cond10d, [makeNop($ast10d)]);
$mgr10d  = new SmartcastManager($ast10d, new ScopeTree());
check($mgr10d->detectInstanceofPattern($if10d) === null, '测试10d: == 比较 返回 null');

// (e) 非 IfStmtNode 节点
$ast10e  = new FlatAst();
$nop10e  = makeNop($ast10e);
$mgr10e  = new SmartcastManager($ast10e, new ScopeTree());
check($mgr10e->detectInstanceofPattern($nop10e) === null, '测试10e: 非 IfStmtNode 返回 null');

// ─────────────────────────────────────────────────────────────
// 测试 11: 多条件 && — then 分支内全部正向模式缩窄
//   if ($x instanceof Foo && $y instanceof Bar) { nop; }
// ─────────────────────────────────────────────────────────────
$ast11   = new FlatAst();
$left11  = makeInstanceof($ast11, '$x', 'Foo');
$right11 = makeInstanceof($ast11, '$y', 'Bar');
$cond11  = makeBinary($ast11, '&&', $left11, $right11);
$body11  = makeNop($ast11);
$if11    = makeIf($ast11, $cond11, [$body11]);

$scope11 = new ScopeTree();
$mgr11   = new SmartcastManager($ast11, $scope11);
$scX11 = null;
$scY11 = null;
$mgr11->processIfStmt($if11, function (string $branch) use ($mgr11, &$scX11, &$scY11) {
    if ($branch === 'then') {
        $scX11 = $mgr11->lookupSmartcast('$x');
        $scY11 = $mgr11->lookupSmartcast('$y');
    }
});

check($scX11 === 'Foo', '测试11: && 多条件 then 分支 $x 缩窄为 Foo');
check($scY11 === 'Bar', '测试11: && 多条件 then 分支 $y 缩窄为 Bar');

// ─────────────────────────────────────────────────────────────
// 测试 12: 动态类名（$x instanceof $cls）不触发 smartcast
//   if ($x instanceof $cls) { nop; }
// ─────────────────────────────────────────────────────────────
$ast12  = new FlatAst();
$lv12   = $ast12->makeNode(NodeKind::VariableExpr, '$x');
$rv12   = $ast12->makeNode(NodeKind::VariableExpr, '$cls'); // 动态类名变量
$cond12 = $ast12->makeNode(NodeKind::BinaryExpr, 'instanceof', [$lv12, $rv12]);
$body12 = makeNop($ast12);
$if12   = makeIf($ast12, $cond12, [$body12]);

$scope12 = new ScopeTree();
$mgr12   = new SmartcastManager($ast12, $scope12);
$pat12   = $mgr12->detectInstanceofPattern($if12);
$thenSc12 = 'UNSET';
$mgr12->processIfStmt($if12, function (string $branch) use ($mgr12, &$thenSc12) {
    if ($branch === 'then') {
        $thenSc12 = $mgr12->lookupSmartcast('$x');
    }
});

check($pat12 === null, '测试12: 动态类名 instanceof detect 返回 null');
check($thenSc12 === null, '测试12: 动态类名 then 分支不缩窄');

// ═══════════════════════════════════════════════════════════════
// 汇总
// ═══════════════════════════════════════════════════════════════
echo "\n";
echo "====================================\n";
echo "SmartcastManager 单元测试结果\n";
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
