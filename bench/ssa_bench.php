<?php

declare(strict_types=1);

// ============================================================
// SSA 性能基准测试（SubTask 15.5）
//
// 目标：量化 SSA 优化 Pass 对 IR 和生成 C 代码的影响
//
// 运行方式：
//   cd c:\project\php\TinyPHP
//   php bench/ssa_bench.php
//
// 指标：
//   1. 优化前后 IR 指令总数 / 基本块数
//   2. 优化前后生成的 C 代码行数
//   3. SSA 构建 + 优化的 PHP 端耗时
//
// 注意：
//   当前 SSA 管线仅处理顶层函数（不含 class Main 方法），
//   因此本基准用纯顶层函数衡量 SSA 优化本身的收益，
//   不涉及端到端可执行文件的运行时性能。
// ============================================================

spl_autoload_register(function (string $class): void {
    $baseDir = __DIR__ . '/../src';
    $parts = explode('\\', $class);
    $file = $baseDir . '/' . implode('/', $parts) . '.php';
    if (file_exists($file)) require_once $file;
});

require_once __DIR__ . '/../src/TokenType.php';
require_once __DIR__ . '/../src/Token.php';
require_once __DIR__ . '/../src/AST/Node.php';
require_once __DIR__ . '/../src/Lexer.php';
require_once __DIR__ . '/../src/Parser.php';
require_once __DIR__ . '/../src/AST/FlatAst.php';
require_once __DIR__ . '/../src/AST/FlatAstConverter.php';
require_once __DIR__ . '/../src/SSA/SSA.php';
require_once __DIR__ . '/../src/SSA/SSABuilder.php';
require_once __DIR__ . '/../src/SSA/SSAOptPass.php';
require_once __DIR__ . '/../src/SSA/SSAToCGenerator.php';

// ═══════════════════════════════════════════════════════════════
// 基准用例
// ═══════════════════════════════════════════════════════════════

/** @var array<string, string> name => PHP 源码（含顶层函数） */
const BENCH_CASES = [
    // 1. 常量折叠：1+2+3 → 6
    'const_fold' => <<<'PHP'
<?php
function const_fold(): int {
    $a = 1;
    $b = 2;
    $c = 3;
    return $a + $b + $c;
}
PHP,

    // 2. 死代码消除：$b 未使用
    'dead_code' => <<<'PHP'
<?php
function dead_code(int $x): int {
    $a = $x + 1;
    $b = $x * 2;
    $c = $a + 3;
    return $c;
}
PHP,

    // 3. COPY 传播：链式赋值
    'copy_chain' => <<<'PHP'
<?php
function copy_chain(int $x): int {
    $a = $x;
    $b = $a;
    $c = $b;
    return $c;
}
PHP,

    // 4. 死块消除：常量条件分支
    'dead_block' => <<<'PHP'
<?php
function dead_block(int $x): int {
    $flag = true;
    if ($flag) {
        return $x + 1;
    }
    return $x;
}
PHP,

    // 5. 斐波那契递归（控制流 + 递归调用）
    'fib' => <<<'PHP'
<?php
function fib(int $n): int {
    if ($n < 2) {
        return $n;
    }
    return fib($n - 1) + fib($n - 2);
}
PHP,

    // 6. 循环累加（Phi 节点 + 控制流）
    'loop_sum' => <<<'PHP'
<?php
function loop_sum(int $n): int {
    $s = 0;
    $i = 0;
    while ($i < $n) {
        $s = $s + $i;
        $i = $i + 1;
    }
    return $s;
}
PHP,

    // 7. 嵌套常量折叠 + 死代码
    'mixed_opt' => <<<'PHP'
<?php
function mixed_opt(int $x): int {
    $a = 10;
    $b = 20;
    $c = $a + $b;
    $unused = $x * 100;
    $d = $c + 5;
    return $d;
}
PHP,

    // 8. 多分支 Phi（if/else 合并）
    'multi_branch' => <<<'PHP'
<?php
function multi_branch(int $x, int $y): int {
    if ($x > $y) {
        $r = $x + $y;
    } else {
        $r = $x - $y;
    }
    return $r;
}
PHP,
];

// ═══════════════════════════════════════════════════════════════
// 辅助函数
// ═══════════════════════════════════════════════════════════════

/**
 * 统计 SSAFunction 的总指令数和基本块数。
 * @return array{instructions: int, blocks: int}
 */
function countSSAStats(SSAFunction $func): array
{
    $instCount = 0;
    foreach ($func->blocks as $block) {
        $instCount += count($block->instructions);
    }
    return ['instructions' => $instCount, 'blocks' => count($func->blocks)];
}

/**
 * 从 PHP 源码构建 SSAModule（未优化）。
 * @return SSAModule|null 失败返回 null
 */
function buildSSAModule(string $source): ?SSAModule
{
    $lexer  = new Lexer($source);
    $tokens = $lexer->tokenize();
    $parser = new Parser($tokens);
    $program = $parser->parse();

    $converter = new FlatAstConverter();
    $flatAst = $converter->convert($program);

    $module = new SSAModule();
    $programIdx = $flatAst->root;
    $childCount = $flatAst->childCount($programIdx);
    for ($i = 0; $i < $childCount; $i++) {
        $childIdx = $flatAst->child($programIdx, $i);
        if ($flatAst->nodes[$childIdx]['kind'] === NodeKind::FunctionNode) {
            $builder = new SSABuilder();
            $ssaFunc = $builder->build($flatAst, $childIdx);
            $fid = $module->newFunction($ssaFunc->name, $ssaFunc->paramTypes, $ssaFunc->retType);
            $module->functions[$fid] = $ssaFunc;
        }
    }
    return $module;
}

/**
 * 统计 SSAModule 的总指标。
 * @return array{instructions: int, blocks: int, functions: int}
 */
function moduleStats(SSAModule $module): array
{
    $totalInst = 0;
    $totalBlocks = 0;
    foreach ($module->functions as $func) {
        $stats = countSSAStats($func);
        $totalInst += $stats['instructions'];
        $totalBlocks += $stats['blocks'];
    }
    return ['instructions' => $totalInst, 'blocks' => $totalBlocks, 'functions' => count($module->functions)];
}

// ═══════════════════════════════════════════════════════════════
// 主流程
// ═══════════════════════════════════════════════════════════════

echo "═══════════════════════════════════════════════════════════════\n";
echo " SSA 性能基准测试（SubTask 15.5）\n";
echo "═══════════════════════════════════════════════════════════════\n\n";

$results = [];
$assertions = 0;

foreach (BENCH_CASES as $name => $source) {
    // 构建两份独立 SSA：一份优化前基准，一份用于优化
    $t0 = microtime(true);
    $moduleBefore = buildSSAModule($source);
    $moduleAfter  = buildSSAModule($source);
    $tBuild = microtime(true) - $t0;

    if ($moduleBefore === null || $moduleAfter === null || count($moduleBefore->functions) === 0) {
        echo "  [SKIP] {$name}: 构建失败或无函数\n";
        continue;
    }

    // 优化前指标
    $statsBefore = moduleStats($moduleBefore);
    $cBefore = (new SSAToCGenerator())->generate($moduleBefore, "bench_{$name}.php");
    $cLinesBefore = substr_count($cBefore, "\n");

    // 运行优化
    $t0 = microtime(true);
    $optPass = new SSAOptPass();
    foreach ($moduleAfter->functions as $func) {
        $optPass->runUntilFixpoint($func);
    }
    $tOpt = microtime(true) - $t0;

    // 优化后指标
    $statsAfter = moduleStats($moduleAfter);
    $cAfter = (new SSAToCGenerator())->generate($moduleAfter, "bench_{$name}.php");
    $cLinesAfter = substr_count($cAfter, "\n");

    // 指令减少率
    $instReduction = $statsBefore['instructions'] > 0
        ? (1 - $statsAfter['instructions'] / $statsBefore['instructions']) * 100
        : 0.0;

    $results[] = [
        'name'           => $name,
        'inst_before'    => $statsBefore['instructions'],
        'inst_after'     => $statsAfter['instructions'],
        'inst_reduction' => $instReduction,
        'blocks_before'  => $statsBefore['blocks'],
        'blocks_after'   => $statsAfter['blocks'],
        'c_before'       => $cLinesBefore,
        'c_after'        => $cLinesAfter,
        't_build_ms'     => $tBuild * 1000,
        't_opt_ms'       => $tOpt * 1000,
    ];

    // 断言：优化后指令数 <= 优化前
    if ($statsAfter['instructions'] <= $statsBefore['instructions']) {
        $assertions++;
    } else {
        echo "  [WARN] {$name}: 优化后指令数增加 ({$statsBefore['instructions']} → {$statsAfter['instructions']})\n";
    }
    // 断言：优化后块数 <= 优化前
    if ($statsAfter['blocks'] <= $statsBefore['blocks']) {
        $assertions++;
    }
    // 断言：优化后 C 代码行数 <= 优化前
    if ($cLinesAfter <= $cLinesBefore) {
        $assertions++;
    }
}

// ═══════════════════════════════════════════════════════════════
// 输出对比表
// ═══════════════════════════════════════════════════════════════

echo "┌────────────────┬──────────┬──────────┬──────────┬──────────┬──────────┬──────────┬──────────┬──────────┐\n";
echo "│ Benchmark      │ Inst Pre │ Inst Post│ Reduce % │ Blk Pre  │ Blk Post │ C Pre     │ C Post   │ Opt ms   │\n";
echo "├────────────────┼──────────┼──────────┼──────────┼──────────┼──────────┼──────────┼──────────┼──────────┤\n";

$totals = ['inst_before' => 0, 'inst_after' => 0, 'blocks_before' => 0, 'blocks_after' => 0, 'c_before' => 0, 'c_after' => 0, 't_build_ms' => 0, 't_opt_ms' => 0];

foreach ($results as $r) {
    printf("│ %-14s │ %8d │ %8d │ %7.1f%% │ %8d │ %8d │ %8d │ %8d │ %7.2f  │\n",
        $r['name'],
        $r['inst_before'],
        $r['inst_after'],
        $r['inst_reduction'],
        $r['blocks_before'],
        $r['blocks_after'],
        $r['c_before'],
        $r['c_after'],
        $r['t_opt_ms']
    );
    foreach (['inst_before', 'inst_after', 'blocks_before', 'blocks_after', 'c_before', 'c_after', 't_build_ms', 't_opt_ms'] as $k) {
        $totals[$k] += $r[$k];
    }
}

echo "├────────────────┼──────────┼──────────┼──────────┼──────────┼──────────┼──────────┼──────────┼──────────┤\n";

$totalReduction = $totals['inst_before'] > 0
    ? (1 - $totals['inst_after'] / $totals['inst_before']) * 100
    : 0.0;
printf("│ %-14s │ %8d │ %8d │ %7.1f%% │ %8d │ %8d │ %8d │ %8d │ %7.2f  │\n",
    'TOTAL',
    $totals['inst_before'],
    $totals['inst_after'],
    $totalReduction,
    $totals['blocks_before'],
    $totals['blocks_after'],
    $totals['c_before'],
    $totals['c_after'],
    $totals['t_opt_ms']
);

echo "└────────────────┴──────────┴──────────┴──────────┴──────────┴──────────┴──────────┴──────────┴──────────┘\n\n";

echo "构建总耗时: " . sprintf("%.2f", $totals['t_build_ms']) . " ms\n";
echo "优化总耗时: " . sprintf("%.2f", $totals['t_opt_ms']) . " ms\n";
echo "指令减少: {$totals['inst_before']} → {$totals['inst_after']} (" . sprintf("%.1f%%", $totalReduction) . ")\n";
echo "C 代码行数减少: {$totals['c_before']} → {$totals['c_after']}\n\n";

// ═══════════════════════════════════════════════════════════════
// 结论断言
// ═══════════════════════════════════════════════════════════════

$allPassed = true;

// 1. 至少有一个基准的指令数减少
$hasReduction = false;
foreach ($results as $r) {
    if ($r['inst_after'] < $r['inst_before']) {
        $hasReduction = true;
        break;
    }
}
if ($hasReduction) {
    $assertions++;
    echo "[OK] 至少一个基准显示指令数减少\n";
} else {
    $allPassed = false;
    echo "[FAIL] 没有基准显示指令数减少\n";
}

// 2. 常量折叠基准：优化后应显著减少
$constFold = null;
foreach ($results as $r) {
    if ($r['name'] === 'const_fold') $constFold = $r;
}
if ($constFold !== null && $constFold['inst_after'] < $constFold['inst_before']) {
    $assertions++;
    echo "[OK] const_fold: {$constFold['inst_before']} → {$constFold['inst_after']} 指令\n";
} else {
    $allPassed = false;
    echo "[FAIL] const_fold 未显示优化效果\n";
}

// 3. 死代码基准：优化后应减少
$deadCode = null;
foreach ($results as $r) {
    if ($r['name'] === 'dead_code') $deadCode = $r;
}
if ($deadCode !== null && $deadCode['inst_after'] < $deadCode['inst_before']) {
    $assertions++;
    echo "[OK] dead_code: {$deadCode['inst_before']} → {$deadCode['inst_after']} 指令\n";
} else {
    $allPassed = false;
    echo "[FAIL] dead_code 未显示优化效果\n";
}

// 4. 总体 C 代码不应增加
if ($totals['c_after'] <= $totals['c_before']) {
    $assertions++;
    echo "[OK] 总 C 代码行数未增加 ({$totals['c_before']} → {$totals['c_after']})\n";
} else {
    $allPassed = false;
    echo "[FAIL] 总 C 代码行数增加\n";
}

// 5. 优化耗时合理（< 100ms 总计）
if ($totals['t_opt_ms'] < 100.0) {
    $assertions++;
    echo "[OK] 优化总耗时合理 (< 100ms)\n";
} else {
    $allPassed = false;
    echo "[FAIL] 优化总耗时过长: " . sprintf("%.2f", $totals['t_opt_ms']) . " ms\n";
}

echo "\n═══════════════════════════════════════════════════════════════\n";
echo " 断言通过: {$assertions}\n";
echo " 结果: " . ($allPassed ? "[OK] 全部通过" : "[FAIL] 存在失败") . "\n";
echo "═══════════════════════════════════════════════════════════════\n";

exit($allPassed ? 0 : 1);
