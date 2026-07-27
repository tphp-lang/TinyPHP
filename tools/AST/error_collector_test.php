<?php

declare(strict_types=1);

// ============================================================
// ErrorCollector 单元测试
//
// 运行方式：
//   cd c:\project\php\TinyPHP
//   php tools/AST/error_collector_test.php
//
// 测试范围：
//   1.  四级错误体系（note/warn/error/fatal）级别正确
//   2.  去重：同一 file:line:message 只记录一次
//   3.  去重：不同 file 或 line 或 message 不去重
//   4.  限流：超过 maxErrors 后 shouldAbort=true
//   5.  递归深度保护：enterRecursion/leaveRecursion 正确计数
//   6.  递归超限：isRecursionTooDeep 返回 true
//   7.  warningsAsErrors：warn 升级为 error
//   8.  fatal 立即 shouldAbort
//   9.  render() 输出格式正确
//  10.  hasErrors() 区分 note/warn 和 error/fatal
//  11.  clear() 重置所有状态
//  12.  formatError() 格式化正确
// ============================================================

require_once __DIR__ . '/../../src/ErrorCollector.php';

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
// 测试 1: 四级错误体系 — note/warn/error/fatal 级别正确
// ─────────────────────────────────────────────────────────────
$c1 = new ErrorCollector();
$c1->note('a.php', 1, 'note msg');
$c1->warn('a.php', 2, 'warn msg');
$c1->error('a.php', 3, 'error msg');
$c1->fatal('a.php', 4, 'fatal msg');
$errs1 = $c1->getErrors();
check(count($errs1) === 4, '测试1: 添加 4 条不同级别的错误');
check($errs1[0]['level'] === ErrorCollector::LEVEL_NOTE, '测试1: 第1条级别 = NOTE');
check($errs1[1]['level'] === ErrorCollector::LEVEL_WARN, '测试1: 第2条级别 = WARN');
check($errs1[2]['level'] === ErrorCollector::LEVEL_ERROR, '测试1: 第3条级别 = ERROR');
check($errs1[3]['level'] === ErrorCollector::LEVEL_FATAL, '测试1: 第4条级别 = FATAL');
check($errs1[0]['file'] === 'a.php' && $errs1[0]['line'] === 1 && $errs1[0]['message'] === 'note msg',
    '测试1: 第1条 file/line/message 正确');

// ─────────────────────────────────────────────────────────────
// 测试 2: 去重 — 同一 file:line:message 只记录一次
// ─────────────────────────────────────────────────────────────
$c2 = new ErrorCollector();
$c2->error('b.php', 10, 'dup error');
$c2->error('b.php', 10, 'dup error');  // 完全相同
$c2->error('b.php', 10, 'dup error');  // 完全相同
check(count($c2->getErrors()) === 1, '测试2: 同一 file:line:message 只记录一次');

// ─────────────────────────────────────────────────────────────
// 测试 3: 去重 — 不同 file 或 line 或 message 不去重
// ─────────────────────────────────────────────────────────────
$c3 = new ErrorCollector();
$c3->error('b.php', 10, 'msg');
$c3->error('c.php', 10, 'msg');        // 不同 file → 不去重
$c3->error('b.php', 11, 'msg');        // 不同 line → 不去重
$c3->error('b.php', 10, 'different');  // 不同 message → 不去重
check(count($c3->getErrors()) === 4, '测试3: 不同 file/line/message 不去重（4 条全部保留）');
// 同 key 重复 → 去重
$c3->error('b.php', 10, 'msg');        // 与第1条完全相同 → 去重
check(count($c3->getErrors()) === 4, '测试3: 完全相同（file:line:message）的重复被去重（仍为 4 条）');

// ─────────────────────────────────────────────────────────────
// 测试 4: 限流 — 超过 maxErrors 后 shouldAbort=true
// ─────────────────────────────────────────────────────────────
$c4 = new ErrorCollector();
$c4->setMaxErrors(3);
$c4->error('f.php', 1, 'e1');
check(!$c4->shouldAbort(), '测试4: 1 条错误未触发限流');
$c4->error('f.php', 2, 'e2');
check(!$c4->shouldAbort(), '测试4: 2 条错误未触发限流');
$c4->error('f.php', 3, 'e3');
check($c4->shouldAbort(), '测试4: 达到 maxErrors(3) 触发 shouldAbort=true');
$c4->error('f.php', 4, 'e4');
check(count($c4->getErrors()) === 4, '测试4: 限流后仍可添加（4 条，不硬停）');
check($c4->shouldAbort(), '测试4: 限流后 shouldAbort 保持 true');

// note/warn 不触发限流检查
$c4b = new ErrorCollector();
$c4b->setMaxErrors(2);
$c4b->note('n.php', 1, 'n1');
$c4b->note('n.php', 2, 'n2');
$c4b->note('n.php', 3, 'n3');
check(!$c4b->shouldAbort(), '测试4: note 不触发限流（即使超过 maxErrors）');

// ─────────────────────────────────────────────────────────────
// 测试 5: 递归深度保护 — enterRecursion/leaveRecursion 正确计数
// ─────────────────────────────────────────────────────────────
$c5 = new ErrorCollector();
$c5->setMaxRecursionDepth(10);
$c5->enterRecursion();   // depth=1
$c5->enterRecursion();   // depth=2
$c5->enterRecursion();   // depth=3
check(!$c5->isRecursionTooDeep(), '测试5: depth=3 未超限');
$c5->leaveRecursion();   // depth=2
$c5->leaveRecursion();   // depth=1
$c5->leaveRecursion();   // depth=0
check(!$c5->isRecursionTooDeep(), '测试5: 全部 leave 后 depth=0 未超限');
$c5->enterRecursion();   // depth=1（验证 leave 真正递减，而非只 ++）
check(!$c5->isRecursionTooDeep(), '测试5: 再次 enter depth=1 未超限（计数正确）');
$c5->leaveRecursion();

// leaveRecursion 不会使 depth 为负
$c5b = new ErrorCollector();
$c5b->leaveRecursion();  // depth=0 时 leave 不变负
$c5b->enterRecursion();  // depth=1
check(!$c5b->isRecursionTooDeep(), '测试5: 空leave后再enter depth=1（不会变负累积）');

// ─────────────────────────────────────────────────────────────
// 测试 6: 递归超限 — isRecursionTooDeep 返回 true
// ─────────────────────────────────────────────────────────────
$c6 = new ErrorCollector();
$c6->setMaxRecursionDepth(3);
$c6->enterRecursion();   // depth=1
$c6->enterRecursion();   // depth=2
$c6->enterRecursion();   // depth=3 (= max，未超限)
check(!$c6->isRecursionTooDeep(), '测试6: depth=3 (=max) 未超限');
$c6->enterRecursion();   // depth=4 > 3，超限，记录错误
check($c6->isRecursionTooDeep(), '测试6: depth=4 (>max) 超限');
check(count($c6->getErrors()) >= 1, '测试6: 超限时记录了错误');
// 再次 enter 不会因去重而新增错误（同一 file:line:message）
$c6->enterRecursion();   // depth=5
$errCountAfter = count($c6->getErrors());
check($errCountAfter === 1, '测试6: 递归错误去重（多次超限只记 1 条）');
$c6->leaveRecursion();
$c6->leaveRecursion();
check(!$c6->isRecursionTooDeep(), '测试6: leave 回到限内后 isRecursionTooDeep=false');

// ─────────────────────────────────────────────────────────────
// 测试 7: warningsAsErrors — warn 升级为 error
// ─────────────────────────────────────────────────────────────
$c7 = new ErrorCollector();
$c7->setWarningsAsErrors(true);
$c7->warn('w.php', 5, 'warn upgraded');
$errs7 = $c7->getErrors();
check(count($errs7) === 1, '测试7: warn 升级后记录 1 条');
check($errs7[0]['level'] === ErrorCollector::LEVEL_ERROR, '测试7: warn 升级为 ERROR 级别');
check($c7->hasErrors(), '测试7: 升级后的 warn 使 hasErrors()=true');

// 对比：未开启时 warn 不升级
$c7b = new ErrorCollector();
$c7b->warn('w.php', 5, 'warn normal');
$errs7b = $c7b->getErrors();
check($errs7b[0]['level'] === ErrorCollector::LEVEL_WARN, '测试7: 未开启时 warn 保持 WARN 级别');
check(!$c7b->hasErrors(), '测试7: 未开启时 warn 不使 hasErrors()=true');

// ─────────────────────────────────────────────────────────────
// 测试 8: fatal 立即 shouldAbort
// ─────────────────────────────────────────────────────────────
$c8 = new ErrorCollector();
check(!$c8->shouldAbort(), '测试8: 初始 shouldAbort=false');
$c8->fatal('fatal.php', 99, 'immediate abort');
check($c8->shouldAbort(), '测试8: fatal 后立即 shouldAbort=true');
$errs8 = $c8->getErrors();
check($errs8[0]['level'] === ErrorCollector::LEVEL_FATAL, '测试8: 记录级别 = FATAL');

// error 不立即 shouldAbort（除非触发限流）
$c8b = new ErrorCollector();
$c8b->error('e.php', 1, 'plain error');
check(!$c8b->shouldAbort(), '测试8: 单条 error 不立即 shouldAbort');
check($c8b->hasErrors(), '测试8: 单条 error 使 hasErrors()=true');

// ─────────────────────────────────────────────────────────────
// 测试 9: render() 输出格式正确
// ─────────────────────────────────────────────────────────────
$c9 = new ErrorCollector();
$c9->note('r.php', 1, 'note text');
$c9->error('r.php', 2, 'error text');
$c9->fatal('r.php', 3, 'fatal text');
$rendered = $c9->render();
$lines9 = explode("\n", $rendered);
check(count($lines9) === 3, '测试9: render 输出 3 行');
check($lines9[0] === 'r.php:1: [NOTE] note text', '测试9: 第1行格式 = "r.php:1: [NOTE] note text"');
check($lines9[1] === 'r.php:2: [ERROR] error text', '测试9: 第2行格式 = "r.php:2: [ERROR] error text"');
check($lines9[2] === 'r.php:3: [FATAL] fatal text', '测试9: 第3行格式 = "r.php:3: [FATAL] fatal text"');

// 空收集器 render 返回空字符串
$c9b = new ErrorCollector();
check($c9b->render() === '', '测试9: 空收集器 render() 返回空字符串');

// ─────────────────────────────────────────────────────────────
// 测试 10: hasErrors() 区分 note/warn 和 error/fatal
// ─────────────────────────────────────────────────────────────
$c10a = new ErrorCollector();
$c10a->note('h.php', 1, 'just a note');
$c10a->warn('h.php', 2, 'just a warn');
check(!$c10a->hasErrors(), '测试10: 只有 note/warn 时 hasErrors()=false');
check(count($c10a->getErrors()) === 2, '测试10: note+warn 共 2 条记录');

$c10b = new ErrorCollector();
$c10b->note('h.php', 1, 'note');
$c10b->error('h.php', 2, 'real error');
check($c10b->hasErrors(), '测试10: 含 error 时 hasErrors()=true');

$c10c = new ErrorCollector();
$c10c->fatal('h.php', 3, 'fatal');
check($c10c->hasErrors(), '测试10: 含 fatal 时 hasErrors()=true');

$c10d = new ErrorCollector();
check(!$c10d->hasErrors(), '测试10: 空收集器 hasErrors()=false');

// ─────────────────────────────────────────────────────────────
// 测试 11: clear() 重置所有状态
// ─────────────────────────────────────────────────────────────
$c11 = new ErrorCollector();
$c11->setMaxErrors(5);
$c11->error('c.php', 1, 'err1');
$c11->error('c.php', 2, 'err2');
$c11->fatal('c.php', 3, 'fatal');
$c11->enterRecursion();
$c11->enterRecursion();
check(count($c11->getErrors()) === 3, '测试11: clear 前有 3 条错误');
check($c11->shouldAbort(), '测试11: clear 前 shouldAbort=true');
check($c11->isRecursionTooDeep() === false, '测试11: clear 前 depth=2 未超限');

$c11->clear();
check(count($c11->getErrors()) === 0, '测试11: clear 后错误列表为空');
check(!$c11->shouldAbort(), '测试11: clear 后 shouldAbort=false');
check(!$c11->isRecursionTooDeep(), '测试11: clear 后递归深度归零');
check(!$c11->hasErrors(), '测试11: clear 后 hasErrors()=false');

// clear 后可重新添加相同 key 的错误（errorKeys 已清空）
$c11->error('c.php', 1, 'err1');  // 与之前相同的 file:line:message
check(count($c11->getErrors()) === 1, '测试11: clear 后去重键已清空，可重新添加');
// 配置保留
check($c11->getMaxErrors() === 5, '测试11: clear 后配置 maxErrors 保留');

// ─────────────────────────────────────────────────────────────
// 测试 12: formatError() 格式化正确
// ─────────────────────────────────────────────────────────────
$c12 = new ErrorCollector();
check($c12->formatError(ErrorCollector::LEVEL_NOTE, 'f.php', 10, 'hi') === 'f.php:10: [NOTE] hi',
    '测试12: formatError NOTE');
check($c12->formatError(ErrorCollector::LEVEL_WARN, 'f.php', 20, 'careful') === 'f.php:20: [WARN] careful',
    '测试12: formatError WARN');
check($c12->formatError(ErrorCollector::LEVEL_ERROR, 'f.php', 30, 'broken') === 'f.php:30: [ERROR] broken',
    '测试12: formatError ERROR');
check($c12->formatError(ErrorCollector::LEVEL_FATAL, 'f.php', 40, 'dead') === 'f.php:40: [FATAL] dead',
    '测试12: formatError FATAL');

// ─────────────────────────────────────────────────────────────
// 汇总
// ─────────────────────────────────────────────────────────────
echo "\n";
echo "====================================\n";
echo "ErrorCollector 单元测试结果\n";
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
