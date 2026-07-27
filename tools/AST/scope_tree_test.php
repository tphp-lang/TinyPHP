<?php

declare(strict_types=1);

// ============================================================
// ScopeTree 单元测试
//
// 运行方式：
//   cd c:\project\php\TinyPHP
//   php tools/AST/scope_tree_test.php
//
// 测试范围：
//   1. 根作用域变量声明与查找
//   2. enterScope 子作用域可见父作用域变量
//   3. leaveScope 后子作用域变量不可见
//   4. 多层嵌套（3+ 层）沿 parent 链查找
//   5. innermost(pos) 二分查找定位正确作用域
//   6. innermost 在嵌套作用域中找到最内层
//   7. innermost 对不在任何子作用域的 pos 返回当前作用域
//   8. smartcast 添加和查找
//   9. smartcast 沿 parent 链查找
//  10. show() 输出格式正确
//  11. children 按 startPos 排序
//  12. 性能测试：1000 个作用域的 innermost 查找（< 1ms）
// ============================================================

require_once __DIR__ . '/../../src/ScopeTree.php';

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
// 测试 1: 根作用域变量声明与查找
// ─────────────────────────────────────────────────────────────
$tree1 = new ScopeTree();
$tree1->declareVar('a', 'int');
$tree1->declareVar('b', 'string');
check($tree1->lookupVar('a') === 'int', '测试1: 根作用域查找 a=int');
check($tree1->lookupVar('b') === 'string', '测试1: 根作用域查找 b=string');
check($tree1->lookupVar('notexist') === null, '测试1: 未声明变量返回 null');
check($tree1->getCurrent() === $tree1->getRoot(), '测试1: 初始 current=root');
check($tree1->getRoot()->depth === 0, '测试1: 根 depth=0');

// ─────────────────────────────────────────────────────────────
// 测试 2: enterScope 子作用域可见父作用域变量
// ─────────────────────────────────────────────────────────────
$tree2 = new ScopeTree();
$tree2->declareVar('a', 'int');
$tree2->enterScope(10, 100);
$tree2->declareVar('x', 'bool');
check($tree2->lookupVar('a') === 'int', '测试2: 子作用域可见父作用域变量 a');
check($tree2->lookupVar('x') === 'bool', '测试2: 子作用域查找本地变量 x');
check($tree2->getCurrent()->depth === 1, '测试2: 子作用域 depth=1');
check($tree2->getCurrent()->parent === $tree2->getRoot(), '测试2: 子作用域 parent=root');

// ─────────────────────────────────────────────────────────────
// 测试 3: leaveScope 后子作用域变量不可见
// ─────────────────────────────────────────────────────────────
$tree3 = new ScopeTree();
$tree3->declareVar('a', 'int');
$tree3->enterScope(10, 100);
$tree3->declareVar('x', 'bool');
$tree3->leaveScope();
check($tree3->getCurrent() === $tree3->getRoot(), '测试3: leaveScope 后 current=root');
check($tree3->lookupVar('x') === null, '测试3: leaveScope 后子作用域变量 x 不可见');
check($tree3->lookupVar('a') === 'int', '测试3: leaveScope 后根作用域变量 a 仍可见');

// ─────────────────────────────────────────────────────────────
// 测试 4: 多层嵌套（3+ 层）沿 parent 链查找
// ─────────────────────────────────────────────────────────────
$tree4 = new ScopeTree();
$tree4->declareVar('a', 'int');         // root (depth 0)
$tree4->enterScope(10, 1000);           // depth 1
$tree4->declareVar('b', 'string');
$tree4->enterScope(20, 500);            // depth 2
$tree4->declareVar('c', 'bool');
$tree4->enterScope(30, 100);            // depth 3
$tree4->declareVar('d', 'float');
check($tree4->getCurrent()->depth === 3, '测试4: 最深层 depth=3');
check($tree4->lookupVar('d') === 'float', '测试4: 第3层查找本地 d');
check($tree4->lookupVar('c') === 'bool', '测试4: 第3层查找父层 c');
check($tree4->lookupVar('b') === 'string', '测试4: 第3层查找祖父层 b');
check($tree4->lookupVar('a') === 'int', '测试4: 第3层查找根层 a');
check($tree4->lookupVar('notexist') === null, '测试4: 未声明返回 null');
$tree4->leaveScope();
check($tree4->lookupVar('d') === null, '测试4: 离开第3层后 d 不可见');
check($tree4->lookupVar('c') === 'bool', '测试4: 离开第3层后仍在第2层可见 c');

// ─────────────────────────────────────────────────────────────
// 测试 5: innermost(pos) 二分查找定位正确作用域
// ─────────────────────────────────────────────────────────────
$tree5 = new ScopeTree();
$tree5->declareVar('a', 'int');
$tree5->enterScope(10, 100);
$tree5->declareVar('x', 'bool');
$tree5->leaveScope();
$tree5->enterScope(200, 300);
$tree5->declareVar('y', 'float');
$tree5->leaveScope();
$scopeAt50 = $tree5->innermost(50);
check($scopeAt50->startPos === 10 && $scopeAt50->endPos === 100, '测试5: pos=50 落在 [10-100]');
$scopeAt250 = $tree5->innermost(250);
check($scopeAt250->startPos === 200 && $scopeAt250->endPos === 300, '测试5: pos=250 落在 [200-300]');

// ─────────────────────────────────────────────────────────────
// 测试 6: innermost 在嵌套作用域中找到最内层
// ─────────────────────────────────────────────────────────────
$tree6 = new ScopeTree();
$tree6->enterScope(0, 1000);              // depth1
$tree6->enterScope(100, 500);             // depth2 嵌套
$tree6->declareVar('inner', 'int');
$tree6->leaveScope();
$tree6->leaveScope();
$innermost6 = $tree6->innermost(200);
check($innermost6->depth === 2, '测试6: pos=200 找到最内层 depth=2');
check(array_key_exists('inner', $innermost6->objects), '测试6: 最内层包含变量 inner');
$outer6 = $tree6->innermost(700);
check($outer6->depth === 1, '测试6: pos=700 落在 depth=1');

// ─────────────────────────────────────────────────────────────
// 测试 7: innermost 对不在任何子作用域的 pos 返回当前作用域
//   注意：innermost 总是从 root 开始查找；pos 不在任何子作用域 → 返回 root
// ─────────────────────────────────────────────────────────────
$tree7 = new ScopeTree();
$tree7->enterScope(10, 100);
$tree7->declareVar('x', 'int');
$tree7->leaveScope();
// pos=150 不在 [10-100] 内，应返回 root
$scopeAt150 = $tree7->innermost(150);
check($scopeAt150 === $tree7->getRoot(), '测试7: pos=150 不在子作用域 → 返回 root');
// 边界：pos=10 应进入 [10-100]
$scopeAt10 = $tree7->innermost(10);
check($scopeAt10->startPos === 10, '测试7: 边界 pos=10 进入子作用域');
// 边界：pos=100 应进入 [10-100]
$scopeAt100 = $tree7->innermost(100);
check($scopeAt100->startPos === 10, '测试7: 边界 pos=100 进入子作用域');

// ─────────────────────────────────────────────────────────────
// 测试 8: smartcast 添加和查找
// ─────────────────────────────────────────────────────────────
$tree8 = new ScopeTree();
$tree8->declareVar('obj', 'object');
$tree8->addSmartcast('obj', 'Foo');
check($tree8->lookupSmartcast('obj') === 'Foo', '测试8: 查找 smartcast obj=Foo');
check($tree8->lookupSmartcast('notexist') === null, '测试8: 未声明 smartcast 返回 null');

// ─────────────────────────────────────────────────────────────
// 测试 9: smartcast 沿 parent 链查找（子作用域可见父作用域的 smartcast）
// ─────────────────────────────────────────────────────────────
$tree9 = new ScopeTree();
$tree9->declareVar('obj', 'object');
$tree9->addSmartcast('obj', 'Foo');      // 在 root 添加 smartcast
$tree9->enterScope(10, 100);
$tree9->declareVar('x', 'int');
check($tree9->lookupSmartcast('obj') === 'Foo', '测试9: 子作用域沿 parent 链查找 smartcast');
// 子作用域本地添加 smartcast 覆盖父作用域
$tree9->addSmartcast('obj', 'Bar');
check($tree9->lookupSmartcast('obj') === 'Bar', '测试9: 子作用域本地 smartcast 覆盖父作用域');
$tree9->leaveScope();
check($tree9->lookupSmartcast('obj') === 'Foo', '测试9: 离开子作用域后恢复父作用域 smartcast');

// ─────────────────────────────────────────────────────────────
// 测试 10: show() 输出格式正确
// ─────────────────────────────────────────────────────────────
$tree10 = new ScopeTree();
$tree10->declareVar('a', 'int');
$tree10->declareVar('b', 'string');
$tree10->enterScope(10, 50);
$tree10->declareVar('x', 'int');
$tree10->leaveScope();
$tree10->enterScope(60, 100);
$tree10->declareVar('y', 'string');
$tree10->addSmartcast('x', 'Foo');
$tree10->leaveScope();
$out10 = $tree10->show();
$lines10 = explode("\n", $out10);
check(count($lines10) === 3, '测试10: show() 输出 3 行（root+2子）');
check($lines10[0] === 'Scope[0-' . PHP_INT_MAX . '] vars={a:int, b:string}', '测试10: 第1行 root 格式正确');
check($lines10[1] === '  Scope[10-50] vars={x:int}', '测试10: 第2行 子作用域缩进+格式');
check($lines10[2] === '  Scope[60-100] vars={y:string} smartcasts={x:Foo}', '测试10: 第3行 smartcasts 格式');

// ─────────────────────────────────────────────────────────────
// 测试 11: children 按 startPos 排序
//   乱序插入多个子作用域，验证 children 数组升序
// ─────────────────────────────────────────────────────────────
$tree11 = new ScopeTree();
// 通过 enterScope 插入：每次 enter 后立即 leave，使所有子作用域都挂在 root 下
$inserts = [300, 100, 500, 200, 400];
foreach ($inserts as $sp) {
    $tree11->enterScope($sp, $sp + 50);
    $tree11->leaveScope();
}
$children11 = $tree11->getRoot()->children;
check(count($children11) === 5, '测试11: root 有 5 个子作用域');
$sps = [];
foreach ($children11 as $c) {
    $sps[] = $c->startPos;
}
check($sps === [100, 200, 300, 400, 500], '测试11: children 按 startPos 升序排列 (' . implode(',', $sps) . ')');
// 验证 innermost 仍能正确工作
check($tree11->innermost(125)->startPos === 100, '测试11: innermost(125) -> [100]');
check($tree11->innermost(425)->startPos === 400, '测试11: innermost(425) -> [400]');
check($tree11->innermost(600) === $tree11->getRoot(), '测试11: innermost(600) -> root（不在任何子作用域）');

// ─────────────────────────────────────────────────────────────
// 测试 12: 性能测试：1000 个作用域的 innermost 查找（< 1ms）
//   构造 1000 个不重叠的子作用域挂在 root 下，再构造一个嵌套到深度 1000 的链，
//   分别测试 innermost 在最坏情况下的耗时。
// ─────────────────────────────────────────────────────────────
$tree12 = new ScopeTree();
// 1000 个不重叠的平铺子作用域
for ($i = 0; $i < 1000; $i++) {
    $tree12->enterScope($i * 1000, $i * 1000 + 999);
    $tree12->leaveScope();
}
// 另一棵深度嵌套树
$treeDeep = new ScopeTree();
$treeDeep->enterScope(0, 1000000);
for ($i = 1; $i < 1000; $i++) {
    $treeDeep->enterScope($i, 1000000 - $i);
}
for ($i = 0; $i < 1000; $i++) {
    $treeDeep->leaveScope();
}

// 测试平铺 1000 作用域的 innermost 查找耗时
$iters = 10000;
$start = hrtime(true);
for ($i = 0; $i < $iters; $i++) {
    $tree12->innermost(500500);  // 落在第 500 个作用域
}
$end = hrtime(true);
$elapsedNs = $end - $start;
$elapsedMsPerCall = ($elapsedNs / $iters) / 1e6;  // ms/call
echo "测试12: 平铺1000作用域 innermost 单次耗时 = " . round($elapsedMsPerCall, 6) . " ms ({$iters} 次迭代)\n";
check($elapsedMsPerCall < 1.0, '测试12: 平铺1000作用域 innermost 单次 < 1ms（实际=' . round($elapsedMsPerCall, 6) . 'ms）');
// 正确性
check($tree12->innermost(500500)->startPos === 500000, '测试12: 平铺 pos=500500 落在 [500000]');
check($tree12->innermost(0)->startPos === 0, '测试12: 平铺 pos=0 落在 [0]');
check($tree12->innermost(999999)->startPos === 999000, '测试12: 平铺 pos=999999 落在 [999000]');

// 测试深度嵌套树的 innermost 查找耗时
$start2 = hrtime(true);
for ($i = 0; $i < $iters; $i++) {
    $treeDeep->innermost(500);  // 深度约 500
}
$end2 = hrtime(true);
$elapsedMsPerCall2 = ($end2 - $start2) / $iters / 1e6;
echo "测试12: 深度1000嵌套 innermost 单次耗时 = " . round($elapsedMsPerCall2, 6) . " ms ({$iters} 次迭代)\n";
check($elapsedMsPerCall2 < 1.0, '测试12: 深度1000嵌套 innermost 单次 < 1ms（实际=' . round($elapsedMsPerCall2, 6) . 'ms）');
// 深度嵌套正确性：pos=500 应落在深度 501 的作用域
//   深度 d 的作用域：startPos=d-1, endPos=1000000-(d-1)
//   pos=500 满足 startPos<=500 即 d-1<=500 → d<=501；最内层为 depth=501
$scopeDeep500 = $treeDeep->innermost(500);
check($scopeDeep500->depth === 501, '测试12: 深度嵌套 pos=500 落在 depth=501（实际=' . $scopeDeep500->depth . '）');

// ═══════════════════════════════════════════════════════════════
// 汇总
// ═══════════════════════════════════════════════════════════════
echo "\n";
echo "====================================\n";
echo "ScopeTree 单元测试结果\n";
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
