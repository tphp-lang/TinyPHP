<?php

declare(strict_types=1);

// ============================================================
// FlatAst 单元测试
//
// 运行方式：
//   cd c:\project\php\TinyPHP
//   php tools/AST/flat_ast_test.php
//
// 测试范围：
//   - 节点构建（makeNode）
//   - 子节点访问（child / childNode / children / childCount）
//   - 追加子节点（appendChild，含末尾追加 + 重定位场景）
//   - 嵌套结构（多层表达式树）
//   - 克隆（clone：深拷贝独立性）
//   - 切片（slice：子树重映射）
//   - 遍历（traverse：DFS 顺序）
//   - 元数据（pos / extra / typ / sourceFile）
//   - NodeKind 枚举完备性
// ============================================================

require_once __DIR__ . '/../../src/AST/FlatAst.php';

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
// 测试 1: 基本节点构建 — makeNode 返回连续索引
// ─────────────────────────────────────────────────────────────
$ast = new FlatAst('test.php');
$n0 = $ast->makeNode(NodeKind::IntLiteralExpr, 42);
$n1 = $ast->makeNode(NodeKind::StringLiteralExpr, 'hi');
$n2 = $ast->makeNode(NodeKind::VariableExpr, '$x');

check($n0 === 0, 'makeNode 返回首个索引 0');
check($n1 === 1, 'makeNode 返回递增索引 1');
check($n2 === 2, 'makeNode 返回递增索引 2');
check($ast->nodeCount === 3, 'nodeCount 同步增长到 3');
check($ast->sourceFile === 'test.php', 'sourceFile 元数据保留');

// ─────────────────────────────────────────────────────────────
// 测试 2: 节点字段完整性
// ─────────────────────────────────────────────────────────────
$node = $ast->nodes[$n0];
check($node['kind'] === NodeKind::IntLiteralExpr, 'kind 字段正确');
check($node['value'] === 42, 'value 字段正确');
check($node['typ'] === 0, 'typ 默认 0');
check($node['children_start'] === 0, 'children_start 默认 0（无子节点时）');
check($node['children_count'] === 0, 'children_count 默认 0（无子节点时）');
check($node['pos'] === [0, 0], 'pos 默认 [0,0]');
check($node['extra'] === [], 'extra 默认空数组');

// ─────────────────────────────────────────────────────────────
// 测试 3: 带位置/extra 的节点
// ─────────────────────────────────────────────────────────────
$n3 = $ast->makeNode(
    NodeKind::MethodNode,
    'main',
    [],
    [10, 5],
    ['visibility' => 'public', 'isStatic' => false],
);
$node3 = $ast->nodes[$n3];
check($node3['pos'] === [10, 5], 'pos 自定义 [10,5]');
check($node3['extra']['visibility'] === 'public', 'extra.visibility 正确');
check($node3['value'] === 'main', 'value=方法名 main');

// ─────────────────────────────────────────────────────────────
// 测试 4: 带初始子节点的 makeNode
//   BinaryExpr($lit1 + $lit2)
// ─────────────────────────────────────────────────────────────
$ast2 = new FlatAst();
$a = $ast2->makeNode(NodeKind::IntLiteralExpr, 1);
$b = $ast2->makeNode(NodeKind::IntLiteralExpr, 2);
$add = $ast2->makeNode(NodeKind::BinaryExpr, '+', [$a, $b]);

check($ast2->childCount($add) === 2, 'BinaryExpr 有 2 个子节点');
check($ast2->child($add, 0) === $a, 'child(add,0) 返回左子节点索引');
check($ast2->child($add, 1) === $b, 'child(add,1) 返回右子节点索引');
check($ast2->childNode($add, 0)['value'] === 1, 'childNode(add,0).value === 1');
check($ast2->childNode($add, 1)['value'] === 2, 'childNode(add,1).value === 2');

// ─────────────────────────────────────────────────────────────
// 测试 5: children() 返回完整子节点索引列表
// ─────────────────────────────────────────────────────────────
$kids = $ast2->children($add);
check($kids === [$a, $b], 'children(add) === [a, b]');
check($ast2->children($a) === [], '叶子节点 children() 返回空数组');

// ─────────────────────────────────────────────────────────────
// 测试 6: 嵌套结构 — (1 + 2) * (3 + 4)
//   mul
//   ├── add1 (1+2)
//   │   ├── 1
//   │   └── 2
//   └── add2 (3+4)
//       ├── 3
//       └── 4
// ─────────────────────────────────────────────────────────────
$ast3 = new FlatAst();
$l1 = $ast3->makeNode(NodeKind::IntLiteralExpr, 1);
$l2 = $ast3->makeNode(NodeKind::IntLiteralExpr, 2);
$l3 = $ast3->makeNode(NodeKind::IntLiteralExpr, 3);
$l4 = $ast3->makeNode(NodeKind::IntLiteralExpr, 4);
$add1 = $ast3->makeNode(NodeKind::BinaryExpr, '+', [$l1, $l2]);
$add2 = $ast3->makeNode(NodeKind::BinaryExpr, '+', [$l3, $l4]);
$mul  = $ast3->makeNode(NodeKind::BinaryExpr, '*', [$add1, $add2]);
$ast3->root = $mul;

check($ast3->nodeCount === 7, '嵌套树共 7 个节点');
check($ast3->childCount($mul) === 2, '根 mul 有 2 个子节点');
check($ast3->child($mul, 0) === $add1, 'mul 的第 0 个子节点是 add1');
check($ast3->child($mul, 1) === $add2, 'mul 的第 1 个子节点是 add2');

// 深度访问：mul → add1 → l1
check($ast3->childNode($mul, 0)['kind'] === NodeKind::BinaryExpr, 'mul.child[0] 是 BinaryExpr');
check($ast3->childNode($mul, 0)['value'] === '+', 'mul.child[0].value === "+"');
check($ast3->childNode($ast3->child($mul, 0), 0)['value'] === 1, 'mul→add1→l1 value === 1');
check($ast3->childNode($ast3->child($mul, 1), 1)['value'] === 4, 'mul→add2→l4 value === 4');

// ─────────────────────────────────────────────────────────────
// 测试 7: appendChild 末尾追加（O(1) 路径）
//   先 makeNode 创建空父节点，再 appendChild
// ─────────────────────────────────────────────────────────────
$ast4 = new FlatAst();
$parent = $ast4->makeNode(NodeKind::BlockStmtNode);
check($ast4->childCount($parent) === 0, 'BlockStmt 初始 0 个子节点');
$c1 = $ast4->makeNode(NodeKind::NopStmtNode);
$c2 = $ast4->makeNode(NodeKind::NopStmtNode);
$c3 = $ast4->makeNode(NodeKind::NopStmtNode);
$ast4->appendChild($parent, $c1);
$ast4->appendChild($parent, $c2);
$ast4->appendChild($parent, $c3);
check($ast4->childCount($parent) === 3, 'appendChild 3 次后 count=3');
check($ast4->children($parent) === [$c1, $c2, $c3], 'appendChild 保持顺序');

// ─────────────────────────────────────────────────────────────
// 测试 8: appendChild 重定位场景
//   parentA 创建 → 给 parentA 加子节点 → 创建 parentB → 给 parentB 加子节点
//   → 再给 parentA 加子节点（此时 parentA 的子节点段不在末尾，需重定位）
// ─────────────────────────────────────────────────────────────
$ast5 = new FlatAst();
$pa = $ast5->makeNode(NodeKind::BlockStmtNode);
$ca1 = $ast5->makeNode(NodeKind::NopStmtNode);
$ast5->appendChild($pa, $ca1);              // pa.children = [ca1]，位于 children 末尾
$pb = $ast5->makeNode(NodeKind::BlockStmtNode);
$cb1 = $ast5->makeNode(NodeKind::NopStmtNode);
$ast5->appendChild($pb, $cb1);              // pb.children = [cb1]，现在末尾是 cb1
$ca2 = $ast5->makeNode(NodeKind::NopStmtNode);
$ast5->appendChild($pa, $ca2);              // pa 的子节点段不在末尾 → 触发重定位

check($ast5->childCount($pa) === 2, 'pa 重定位后子节点数=2');
check($ast5->childCount($pb) === 1, 'pb 子节点数仍=1（不受影响）');
check($ast5->children($pa) === [$ca1, $ca2], 'pa 重定位后子节点顺序正确 [ca1, ca2]');
check($ast5->children($pb) === [$cb1], 'pb 子节点仍为 [cb1]');

// ─────────────────────────────────────────────────────────────
// 测试 9: clone — 深拷贝独立性
// ─────────────────────────────────────────────────────────────
$ast6 = new FlatAst();
$r = $ast6->makeNode(NodeKind::IntLiteralExpr, 100);
$ast6->root = $r;
$clone = $ast6->clone();
check($clone->nodeCount === 1, 'clone 后 nodeCount 一致');
check($clone->nodes[$r]['value'] === 100, 'clone 后节点 value 一致');

// 修改原 AST，clone 不应受影响
$ast6->nodes[$r]['value'] = 999;
check($ast6->nodes[$r]['value'] === 999, '原 AST 修改成功');
check($clone->nodes[$r]['value'] === 100, 'clone 不受原 AST 修改影响（深拷贝独立）');

// ─────────────────────────────────────────────────────────────
// 测试 10: slice — 子树切片，索引重映射
//   原树: root=add1 (1+2)，切片 add1 得到新 FlatAst
// ─────────────────────────────────────────────────────────────
$ast7 = new FlatAst();
$s1 = $ast7->makeNode(NodeKind::IntLiteralExpr, 1);
$s2 = $ast7->makeNode(NodeKind::IntLiteralExpr, 2);
$sAdd = $ast7->makeNode(NodeKind::BinaryExpr, '+', [$s1, $s2]);
$exprStmt = $ast7->makeNode(NodeKind::ExprStmtNode, null, [$sAdd]);
$ast7->root = $exprStmt;

$sub = $ast7->slice($sAdd);
check($sub->nodeCount === 3, 'slice 后子树共 3 个节点（add+两个字面量）');
check($sub->root === 0, 'slice 后根节点索引重映射为 0');
// 子树根 = BinaryExpr，应有 2 个子节点
check($sub->childCount(0) === 2, 'slice 后根节点有 2 个子节点');
check($sub->childNode(0, 0)['value'] === 1, 'slice 后左子节点 value=1');
check($sub->childNode(0, 1)['value'] === 2, 'slice 后右子节点 value=2');
// 验证新索引在 [0, nodeCount) 范围内
$ok = true;
foreach ($sub->children(0) as $childIdx) {
    if ($childIdx < 0 || $childIdx >= $sub->nodeCount) { $ok = false; break; }
}
check($ok, 'slice 后所有子节点索引在合法范围');

// ─────────────────────────────────────────────────────────────
// 测试 11: traverse — DFS 遍历顺序
//   树:    mul
//         /   \
//       add1  add2
//       / \   / \
//      1   2 3   4
//   DFS 前序（根-左-右）期望: mul, add1, 1, 2, add2, 3, 4
// ─────────────────────────────────────────────────────────────
$visited = [];
$ast3->traverse($mul, function (int $idx, array $node) use (&$visited, $ast3): void {
    $visited[] = $node['kind']->name . ($node['value'] !== null ? ':' . $node['value'] : '');
});
$expected = [
    'BinaryExpr:*',
    'BinaryExpr:+',
    'IntLiteralExpr:1',
    'IntLiteralExpr:2',
    'BinaryExpr:+',
    'IntLiteralExpr:3',
    'IntLiteralExpr:4',
];
check($visited === $expected, 'traverse DFS 顺序正确（根-左-右）');

// ─────────────────────────────────────────────────────────────
// 测试 12: traverse 计数 — 验证遍历到全部节点
// ─────────────────────────────────────────────────────────────
$count = 0;
$ast3->traverse($mul, function () use (&$count) { $count++; });
check($count === 7, 'traverse 访问 7 个节点');

// ─────────────────────────────────────────────────────────────
// 测试 13: NodeKind 枚举完备性 — 必须覆盖 Node.php 全部节点类
// ─────────────────────────────────────────────────────────────
$expectedKinds = [
    // 顶层结构
    'ProgramNode', 'FunctionNode', 'ClassNode', 'MethodNode', 'PropertyDeclNode',
    'PropertyHook', 'ParamNode', 'ConstNode', 'EnumNode', 'EnumCaseNode',
    'AttributeDeclNode', 'AttributeUseNode',
    // 语句
    'EchoStmtNode', 'ReturnStmtNode', 'AssignStmtNode', 'ListStmtNode',
    'AssignPropStmtNode', 'AssignArrayStmtNode', 'AssignArrayPushStmtNode',
    'IfStmtNode', 'ElseIfBranch', 'WhileStmtNode', 'DoWhileStmtNode',
    'ForStmtNode', 'ForeachStmtNode', 'SwitchStmtNode', 'CaseBranch',
    'BreakStmtNode', 'GotoStmtNode', 'TryStmtNode', 'ThrowStmtNode',
    'LabelStmtNode', 'ContinueStmtNode', 'ExprStmtNode', 'NopStmtNode',
    'StaticStmtNode', 'ConstStmtNode', 'BlockStmtNode', 'DeferStmtNode',
    // 表达式
    'StringLiteralExpr', 'IntLiteralExpr', 'FloatLiteralExpr', 'BoolLiteralExpr',
    'NullLiteralExpr', 'MagicConstExpr', 'ArrayEntryNode', 'ArrayLiteralExpr',
    'ArrayAccessExpr', 'ArrayAppendExpr', 'PropertyAccessExpr', 'EnumAccessExpr',
    'ClosureExpr', 'YieldExpr', 'YieldFromExpr', 'PipeExpr', 'PlaceholderExpr',
    'CallableConvertExpr', 'VariableExpr', 'UnaryExpr', 'PostfixExpr',
    'CompoundAssignExpr', 'BinaryExpr', 'TernaryExpr', 'NullCoalesceExpr',
    'MatchArm', 'MatchExpr', 'CallExpr', 'CastExpr', 'NewExpr',
    'ThrowExprNode', 'OrBlockExpr',
];
$cases = array_map(fn($c) => $c->name, NodeKind::cases());
sort($cases);
$expectedSorted = $expectedKinds;
sort($expectedSorted);
check($cases === $expectedSorted, 'NodeKind 覆盖全部 ' . count($expectedKinds) . ' 个节点类型');
check(count(NodeKind::cases()) === 71, 'NodeKind 共 71 个枚举值');

// ─────────────────────────────────────────────────────────────
// 测试 14: typ 字段可写（模拟 TypeChecker 填充）
// ─────────────────────────────────────────────────────────────
$ast8 = new FlatAst();
$intLit = $ast8->makeNode(NodeKind::IntLiteralExpr, 42);
$ast8->nodes[$intLit]['typ'] = 1; // 假设 1 = IDX_INT
check($ast8->nodes[$intLit]['typ'] === 1, 'typ 字段可被 TypeChecker 修改');

// ─────────────────────────────────────────────────────────────
// 测试 15: 真实语法片段 — 函数定义 + 调用
//   function add(int $a, int $b): int { return $a + $b; }
//   简化为 FlatAst 结构
// ─────────────────────────────────────────────────────────────
$ast9 = new FlatAst('demo.php');
$paramA = $ast9->makeNode(NodeKind::ParamNode, '$a', [], [1, 18], ['type' => 'int']);
$paramB = $ast9->makeNode(NodeKind::ParamNode, '$b', [], [1, 27], ['type' => 'int']);
$fnName = 'add';
// body: return $a + $b;
$varA = $ast9->makeNode(NodeKind::VariableExpr, '$a');
$varB = $ast9->makeNode(NodeKind::VariableExpr, '$b');
$plus = $ast9->makeNode(NodeKind::BinaryExpr, '+', [$varA, $varB]);
$ret  = $ast9->makeNode(NodeKind::ReturnStmtNode, null, [$plus]);
$fn   = $ast9->makeNode(
    NodeKind::FunctionNode,
    $fnName,
    [$paramA, $paramB, $ret],
    [1, 1],
    ['returnType' => 'int'],
);
$ast9->root = $fn;

check($ast9->nodeCount === 7, '函数定义 AST 共 7 个节点');
check($ast9->childCount($fn) === 3, 'FunctionNode 有 3 个子节点（2 param + 1 body）');
check($ast9->childNode($fn, 0)['kind'] === NodeKind::ParamNode, '第 1 个子节点是 ParamNode');
check($ast9->childNode($fn, 2)['kind'] === NodeKind::ReturnStmtNode, '第 3 个子节点是 ReturnStmtNode');
// 深度访问：fn → ret → plus → varA
$plusIdx = $ast9->child($ast9->child($fn, 2), 0);
check($ast9->nodes[$plusIdx]['value'] === '+', 'fn→ret→plus value === "+"');
check($ast9->childNode($plusIdx, 0)['value'] === '$a', 'fn→ret→plus→varA value === "$a"');

// ─────────────────────────────────────────────────────────────
// 测试 16: 遍历性能基准 — 大量节点遍历应在合理时间内完成
//   构建一棵深度 1000 的左链 AST：1 -> 2 -> 3 -> ... -> 1000
// ─────────────────────────────────────────────────────────────
$ast10 = new FlatAst();
// 先创建 1000 个节点
$indices = [];
for ($i = 0; $i < 1000; $i++) {
    $indices[$i] = $ast10->makeNode(NodeKind::IntLiteralExpr, $i);
}
// 然后用 BinaryExpr 串成左链：(((0 + 1) + 2) + 3) + ...
$prev = $indices[0];
for ($i = 1; $i < 1000; $i++) {
    $prev = $ast10->makeNode(NodeKind::BinaryExpr, '+', [$prev, $indices[$i]]);
}
$ast10->root = $prev;

$expectedNodes = 1000 + 999; // 1000 字面量 + 999 BinaryExpr
check($ast10->nodeCount === $expectedNodes, "深度 1000 左链共 {$expectedNodes} 个节点");

$start = microtime(true);
$visitedCount = 0;
$ast10->traverse($ast10->root, function () use (&$visitedCount) { $visitedCount++; });
$elapsed = microtime(true) - $start;

check($visitedCount === $expectedNodes, "traverse 访问了全部 {$expectedNodes} 个节点");
check($elapsed < 1.0, "遍历 {$expectedNodes} 个节点耗时 < 1s（实际 " . round($elapsed, 4) . "s）");

// ─────────────────────────────────────────────────────────────
// 测试 17: 子节点索引连续存储 — 验证 children 数组的紧凑性
//   构建多节点树后，children 数组应保持小（无冗余空洞）
// ─────────────────────────────────────────────────────────────
$ast11 = new FlatAst();
$x = $ast11->makeNode(NodeKind::IntLiteralExpr, 1);
$y = $ast11->makeNode(NodeKind::IntLiteralExpr, 2);
$z = $ast11->makeNode(NodeKind::IntLiteralExpr, 3);
$call = $ast11->makeNode(NodeKind::CallExpr, 'foo', [$x, $y, $z]);
// children 数组应该只有 3 个元素
check(count($ast11->children) === 3, 'children 数组紧凑存储（3 个元素）');
check($ast11->childCount($call) === 3, 'CallExpr 有 3 个参数子节点');

// ─────────────────────────────────────────────────────────────
// 汇总
// ─────────────────────────────────────────────────────────────
echo "\n";
echo "====================================\n";
echo "FlatAst 单元测试结果\n";
echo "====================================\n";
echo "通过: {$pass}\n";
echo "失败: {$fail}\n";
echo "NodeKind 枚举值数: " . count(NodeKind::cases()) . "\n";
echo "节点总数（测试 16 大树）: {$expectedNodes}\n";
echo "遍历耗时: " . round($elapsed, 6) . "s\n";
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
