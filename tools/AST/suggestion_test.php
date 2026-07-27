<?php

declare(strict_types=1);

// ============================================================
// Suggestion 单元测试
//
// 运行方式：
//   cd c:\project\php\TinyPHP
//   php tools/AST/suggestion_test.php
//
// 测试范围：
//   1.  levenshteinDistance 空字符串边界
//   2.  levenshteinDistance 相同字符串 = 0
//   3.  levenshteinDistance 单字符差异
//   4.  levenshteinDistance 完全不同字符串
//   5.  levenshteinDistance 大小写敏感
//   6.  findClosest 命中（距离 ≤ 3）
//   7.  findClosest 未命中（距离 > 3）
//   8.  findClosest 空候选列表
//   9.  findClosest 多候选选最近
//  10.  suggestMethod 推荐
//  11.  suggestProperty 推荐
//  12.  suggestClass 推荐
//  13.  suggestFunction 推荐
//  14.  suggestConstant 推荐
//  15.  formatDidYouMean 有推荐
//  16.  formatDidYouMean 无推荐（返回空）
//  17.  enhanceErrorMessage 追加推荐
//  18.  enhanceErrorMessage 无推荐（原消息不变）
//  ≥ 20 个断言
// ============================================================

require_once __DIR__ . '/../../src/Suggestion.php';

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
// 测试 1: levenshteinDistance 空字符串
// ─────────────────────────────────────────────────────────────
check(Suggestion::levenshteinDistance('', '') === 0,    '测试1: dist("", "") = 0');
check(Suggestion::levenshteinDistance('', 'abc') === 3, '测试1: dist("", "abc") = 3（全部插入）');
check(Suggestion::levenshteinDistance('abc', '') === 3, '测试1: dist("abc", "") = 3（全部删除）');

// ─────────────────────────────────────────────────────────────
// 测试 2: levenshteinDistance 相同字符串 = 0
// ─────────────────────────────────────────────────────────────
check(Suggestion::levenshteinDistance('abc', 'abc') === 0,   '测试2: dist("abc", "abc") = 0');
check(Suggestion::levenshteinDistance('hello', 'hello') === 0, '测试2: dist("hello", "hello") = 0');
check(Suggestion::levenshteinDistance('', '') === 0,           '测试2: dist("", "") = 0（两个空串也视为相同）');

// ─────────────────────────────────────────────────────────────
// 测试 3: levenshteinDistance 单字符差异
// ─────────────────────────────────────────────────────────────
// 替换
check(Suggestion::levenshteinDistance('abc', 'abd') === 1, '测试3: dist("abc", "abd") = 1（单字符替换）');
// 插入
check(Suggestion::levenshteinDistance('abc', 'abcd') === 1, '测试3: dist("abc", "abcd") = 1（末尾插入）');
check(Suggestion::levenshteinDistance('cat', 'cats') === 1, '测试3: dist("cat", "cats") = 1（末尾插入）');
// 删除
check(Suggestion::levenshteinDistance('abcd', 'abc') === 1, '测试3: dist("abcd", "abc") = 1（末尾删除）');
// 中间插入
check(Suggestion::levenshteinDistance('nme', 'name') === 1, '测试3: dist("nme", "name") = 1（中间插入 a）');

// ─────────────────────────────────────────────────────────────
// 测试 4: levenshteinDistance 完全不同字符串
// ─────────────────────────────────────────────────────────────
check(Suggestion::levenshteinDistance('abc', 'xyz') === 3, '测试4: dist("abc", "xyz") = 3（全部替换）');
check(Suggestion::levenshteinDistance('aaa', 'bbb') === 3, '测试4: dist("aaa", "bbb") = 3（全部替换）');

// ─────────────────────────────────────────────────────────────
// 测试 5: levenshteinDistance 大小写敏感
// ─────────────────────────────────────────────────────────────
check(Suggestion::levenshteinDistance('Cat', 'cat') === 1, '测试5: dist("Cat", "cat") = 1（C vs c）');
check(Suggestion::levenshteinDistance('PHP', 'php') === 3, '测试5: dist("PHP", "php") = 3（全大写 vs 全小写）');
check(Suggestion::levenshteinDistance('getColor', 'getColor') === 0, '测试5: dist("getColor", "getColor") = 0（完全相同）');

// ─────────────────────────────────────────────────────────────
// 测试 6: findClosest 命中（距离 ≤ 3）
// ─────────────────────────────────────────────────────────────
// 距离 = 1
$r6a = Suggestion::findClosest('getColr', ['getColor', 'setBackground', 'fetchData'], 3);
check($r6a === 'getColor', '测试6: 命中距离最小的候选 getColor（dist=1）');
// 距离 = 0（候选列表中存在目标本身）
$r6b = Suggestion::findClosest('getColor', ['getColor', 'setColor'], 3);
check($r6b === 'getColor', '测试6: 候选中存在目标本身（dist=0）应被命中');
// 距离恰好等于 maxDistance（边界）
$r6c = Suggestion::findClosest('fooBar', ['fooBarBaz'], 3);
check($r6c === 'fooBarBaz', '测试6: 距离恰好等于 maxDistance=3 时仍命中');
// 自定义 maxDistance
$r6d = Suggestion::findClosest('abc', ['abd'], 1);
check($r6d === 'abd', '测试6: 自定义 maxDistance=1，距离 1 命中');

// ─────────────────────────────────────────────────────────────
// 测试 7: findClosest 未命中（距离 > 3）
// ─────────────────────────────────────────────────────────────
$r7a = Suggestion::findClosest('xyzabc', ['hello', 'world'], 3);
check($r7a === null, '测试7: 所有候选距离 > 3 返回 null');
// 默认 maxDistance=3，候选距离 = 4
$r7b = Suggestion::findClosest('abcd', ['xy'], 3);
check($r7b === null, '测试7: 距离 4 > maxDistance 3 返回 null');
// 候选与目标都很长，距离超过 3
$r7c = Suggestion::findClosest('abcdefghij', ['poissondb'], 3);
check($r7c === null, '测试7: 长字符串距离 > 3 返回 null');

// ─────────────────────────────────────────────────────────────
// 测试 8: findClosest 空候选列表
// ─────────────────────────────────────────────────────────────
$r8 = Suggestion::findClosest('foo', [], 3);
check($r8 === null, '测试8: 空候选列表返回 null');
// 不带 maxDistance 参数（使用默认值）
$r8b = Suggestion::findClosest('foo', []);
check($r8b === null, '测试8: 空候选列表 + 默认 maxDistance 返回 null');

// ─────────────────────────────────────────────────────────────
// 测试 9: findClosest 多候选选最近
// ─────────────────────────────────────────────────────────────
// target="cat", 候选: dog(3), bat(1), catch(2) → 期望 bat
$r9a = Suggestion::findClosest('cat', ['dog', 'bat', 'catch'], 3);
check($r9a === 'bat', '测试9: 多候选选最近 — cat/dog(3), cat/bat(1), cat/catch(2) → bat');
// 相同距离时返回第一个
// target="cat", 候选: dog(3), bat(1), hat(1) → 期望 bat（首个胜出）
$r9b = Suggestion::findClosest('cat', ['dog', 'bat', 'hat'], 3);
check($r9b === 'bat', '测试9: 相同距离时返回第一个 — bat 与 hat 均 dist=1 → bat');
// 第一个最近
// target="abc", 候选: abd(1), abcd(1), xbcd(2) → 期望 abd（首个胜出）
$r9c = Suggestion::findClosest('abc', ['abd', 'abcd', 'xbcd'], 3);
check($r9c === 'abd', '测试9: 首个最近的优先 — abd 与 abcd 均 dist=1 → abd');

// ─────────────────────────────────────────────────────────────
// 测试 10: suggestMethod 推荐
// ─────────────────────────────────────────────────────────────
$m10 = Suggestion::suggestMethod('getColr', ['getColor', 'setColor', 'setBackground']);
check($m10 === 'getColor', '测试10: suggestMethod 推荐 getColor');
// 无匹配返回 null
$m10b = Suggestion::suggestMethod('xyzabc', ['getColor', 'setColor']);
check($m10b === null, '测试10: suggestMethod 无匹配返回 null');

// ─────────────────────────────────────────────────────────────
// 测试 11: suggestProperty 推荐
// ─────────────────────────────────────────────────────────────
$p11 = Suggestion::suggestProperty('nme', ['name', 'age', 'value']);
check($p11 === 'name', '测试11: suggestProperty 推荐 name（dist("nme","name")=1）');
// 无匹配返回 null
$p11b = Suggestion::suggestProperty('zzzzzzz', ['name', 'age']);
check($p11b === null, '测试11: suggestProperty 无匹配返回 null');

// ─────────────────────────────────────────────────────────────
// 测试 12: suggestClass 推荐
// ─────────────────────────────────────────────────────────────
$c12 = Suggestion::suggestClass('Usr', ['User', 'Admin', 'Session']);
check($c12 === 'User', '测试12: suggestClass 推荐 User（dist("Usr","User")=1）');
// 无匹配返回 null
$c12b = Suggestion::suggestClass('Zzzzzzz', ['User', 'Admin']);
check($c12b === null, '测试12: suggestClass 无匹配返回 null');

// ─────────────────────────────────────────────────────────────
// 测试 13: suggestFunction 推荐
// ─────────────────────────────────────────────────────────────
$f13 = Suggestion::suggestFunction('prnt', ['print', 'echo', 'printf']);
check($f13 === 'print', '测试13: suggestFunction 推荐 print（dist("prnt","print")=1）');
// 多候选选最近 — printf vs print：prnt 与 print 距离 1，与 printf 距离 2 → print
check(Suggestion::suggestFunction('prnt', ['printf', 'print']) === 'print',
    '测试13: suggestFunction 多候选选最近 — printf(2) vs print(1) → print');

// ─────────────────────────────────────────────────────────────
// 测试 14: suggestConstant 推荐
// ─────────────────────────────────────────────────────────────
$k14 = Suggestion::suggestConstant('MAX_VLUE', ['MAX_VALUE', 'MIN_VALUE', 'DEFAULT_VALUE']);
check($k14 === 'MAX_VALUE', '测试14: suggestConstant 推荐 MAX_VALUE（dist=1，插入 A）');
// 无匹配返回 null
$k14b = Suggestion::suggestConstant('ZZZZZZZ', ['MAX_VALUE', 'MIN_VALUE']);
check($k14b === null, '测试14: suggestConstant 无匹配返回 null');

// ─────────────────────────────────────────────────────────────
// 测试 15: formatDidYouMean 有推荐
// ─────────────────────────────────────────────────────────────
$f15 = Suggestion::formatDidYouMean('fooBar', 'fooBarBaz');
check($f15 === "Did you mean 'fooBarBaz'?", '测试15: formatDidYouMean 有推荐格式正确');
$f15b = Suggestion::formatDidYouMean('getColr', 'getColor');
check($f15b === "Did you mean 'getColor'?", '测试15: formatDidYouMean 另一例格式正确');

// ─────────────────────────────────────────────────────────────
// 测试 16: formatDidYouMean 无推荐（返回空）
// ─────────────────────────────────────────────────────────────
$f16 = Suggestion::formatDidYouMean('fooBar', null);
check($f16 === '', '测试16: formatDidYouMean null 返回空字符串');
// 空候选列表场景：findClosest 返回 null → formatDidYouMean 也返回空
$suggestion16 = Suggestion::findClosest('fooBar', []);
check(Suggestion::formatDidYouMean('fooBar', $suggestion16) === '',
    '测试16: 空候选 → findClosest=null → formatDidYouMean 返回空字符串');

// ─────────────────────────────────────────────────────────────
// 测试 17: enhanceErrorMessage 追加推荐
// ─────────────────────────────────────────────────────────────
$e17 = Suggestion::enhanceErrorMessage("Undefined method 'fooBar'", 'fooBar', ['fooBarBaz']);
check($e17 === "Undefined method 'fooBar'. Did you mean 'fooBarBaz'?",
    '测试17: enhanceErrorMessage 追加 "Did you mean ..." 提示');
// 推荐属性
$e17b = Suggestion::enhanceErrorMessage("Undefined property 'nme'", 'nme', ['name', 'age']);
check($e17b === "Undefined property 'nme'. Did you mean 'name'?",
    '测试17: enhanceErrorMessage 属性推荐');
// 推荐类
$e17c = Suggestion::enhanceErrorMessage("Undefined class 'Usr'", 'Usr', ['User', 'Admin']);
check($e17c === "Undefined class 'Usr'. Did you mean 'User'?",
    '测试17: enhanceErrorMessage 类推荐');

// ─────────────────────────────────────────────────────────────
// 测试 18: enhanceErrorMessage 无推荐（原消息不变）
// ─────────────────────────────────────────────────────────────
$e18 = Suggestion::enhanceErrorMessage("Undefined method 'xyzabc'", 'xyzabc', ['hello', 'world']);
check($e18 === "Undefined method 'xyzabc'",
    '测试18: 无推荐时原消息原样返回（不附加句点）');
// 空候选列表
$e18b = Suggestion::enhanceErrorMessage("Undefined method 'foo'", 'foo', []);
check($e18b === "Undefined method 'foo'",
    '测试18: 空候选列表时原消息原样返回');
// 推荐距离恰好等于 maxDistance（边界命中）
$e18c = Suggestion::enhanceErrorMessage("msg", 'fooBar', ['fooBarBaz']);
check($e18c === "msg. Did you mean 'fooBarBaz'?",
    '测试18: 边界 — 距离恰好 == maxDistance 时仍命中推荐');

// ─────────────────────────────────────────────────────────────
// 汇总
// ─────────────────────────────────────────────────────────────
echo "\n";
echo "====================================\n";
echo "Suggestion 单元测试结果\n";
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
