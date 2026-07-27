<?php

declare(strict_types=1);

// ============================================================
// MultiBuffer 单元测试
//
// 运行方式：
//   cd c:\project\php\TinyPHP
//   php tools/AST/multi_buffer_test.php
//
// 测试范围：
//   1.  初始化所有缓冲区为空
//   2.  append 到当前缓冲区
//   3.  appendTo 向指定缓冲区追加
//   4.  select 切换当前缓冲区
//   5.  appendLine 追加换行
//   6.  render 按 bufferOrder 拼接
//   7.  renderWithSeparators 插入分区注释
//   8.  空缓冲区跳过
//   9.  getBuffer/setBuffer
//  10.  clear/clearAll
//  11.  ensureForwardDecl 去重
//  12.  addAutoFunc 去重
//  13.  addCleanup
//  14.  hasContent
//  15.  bufferNames
//  16.  完整 C 代码生成示例
// ============================================================

require_once __DIR__ . '/../../src/MultiBuffer.php';

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
// 测试 1: 初始化所有缓冲区为空
// ─────────────────────────────────────────────────────────────
$b1 = new MultiBuffer();
$expectedNames = ['cheaders', 'typedefs', 'definitions', 'auto_funcs', 'out', 'cleanups'];
foreach ($expectedNames as $name) {
    check($b1->getBuffer($name) === '', "测试1: 初始化时 {$name} 为空");
    check(!$b1->hasContent($name), "测试1: 初始化时 {$name} hasContent=false");
}
check($b1->current() === 'out', '测试1: 初始 currentBuffer = out');

// ─────────────────────────────────────────────────────────────
// 测试 2: append 到当前缓冲区（默认 out）
// ─────────────────────────────────────────────────────────────
$b2 = new MultiBuffer();
$b2->append('hello');
$b2->append(' world');
check($b2->getBuffer('out') === 'hello world', '测试2: append 累加到当前缓冲区');
check($b2->hasContent('out'), '测试2: out hasContent=true');
check(!$b2->hasContent('cheaders'), '测试2: cheaders 仍为空');

// ─────────────────────────────────────────────────────────────
// 测试 3: appendTo 向指定缓冲区追加（不影响 currentBuffer）
// ─────────────────────────────────────────────────────────────
$b3 = new MultiBuffer();
$b3->appendTo('cheaders', '#include <stdio.h>');
$b3->appendTo('typedefs', 'typedef int t_int;');
check($b3->getBuffer('cheaders') === '#include <stdio.h>', '测试3: appendTo 写入 cheaders');
check($b3->getBuffer('typedefs') === 'typedef int t_int;', '测试3: appendTo 写入 typedefs');
check($b3->getBuffer('out') === '', '测试3: out 未受 appendTo 影响');
check($b3->current() === 'out', '测试3: appendTo 不改变 currentBuffer');

// ─────────────────────────────────────────────────────────────
// 测试 4: select 切换当前缓冲区
// ─────────────────────────────────────────────────────────────
$b4 = new MultiBuffer();
$b4->select('cheaders');
check($b4->current() === 'cheaders', '测试4: select 切换到 cheaders');
$b4->append('#include <stdlib.h>');
check($b4->getBuffer('cheaders') === '#include <stdlib.h>', '测试4: append 写入切换后的 cheaders');
$b4->select('out');
$b4->append('int main(){return 0;}');
check($b4->getBuffer('out') === 'int main(){return 0;}', '测试4: select 回 out 后 append 写入 out');
check($b4->getBuffer('cheaders') === '#include <stdlib.h>', '测试4: cheaders 内容保持');

// ─────────────────────────────────────────────────────────────
// 测试 5: appendLine 追加换行
// ─────────────────────────────────────────────────────────────
$b5 = new MultiBuffer();
$b5->appendLine('line1');
$b5->appendLine('line2');
check($b5->getBuffer('out') === "line1\nline2\n", '测试5: appendLine 每行末尾有 \n');
$b5->appendLine();  // 空行
check($b5->getBuffer('out') === "line1\nline2\n\n", '测试5: appendLine() 空参数追加空行');
// appendLineTo
$b5->appendLineTo('cheaders', '#include <string.h>');
check($b5->getBuffer('cheaders') === "#include <string.h>\n", '测试5: appendLineTo 追加含换行');

// ─────────────────────────────────────────────────────────────
// 测试 6: render 按 bufferOrder 拼接
// ─────────────────────────────────────────────────────────────
$b6 = new MultiBuffer();
$b6->appendLineTo('cheaders', '#include <stdio.h>');
$b6->appendLineTo('typedefs', 'typedef int t_int;');
$b6->appendLineTo('definitions', 't_int add(t_int, t_int);');
$b6->appendLineTo('out', 'int main() { return 0; }');
$rendered6 = $b6->render();
// 按 bufferOrder: cheaders → typedefs → definitions → out（空缓冲区 auto_funcs/cleanups 跳过）
// 每个缓冲区以 \n 结尾，implode("\n\n") 会在缓冲区之间产生 \n\n\n（一个尾换行 + 两个分隔换行）
$expected6 = "#include <stdio.h>\n\n\n" .
             "typedef int t_int;\n\n\n" .
             "t_int add(t_int, t_int);\n\n\n" .
             "int main() { return 0; }\n";
check($rendered6 === $expected6, '测试6: render 按 bufferOrder 顺序拼接（含空行分隔）');
// 验证顺序：cheaders 在 typedefs 前，typedefs 在 definitions 前，definitions 在 out 前
$posStdio6  = strpos($rendered6, '#include <stdio.h>');
$posTypedef6 = strpos($rendered6, 'typedef int t_int;');
$posFwdDecl6 = strpos($rendered6, 't_int add(t_int, t_int);');
$posMain6    = strpos($rendered6, 'int main()');
check($posStdio6 < $posTypedef6 && $posTypedef6 < $posFwdDecl6 && $posFwdDecl6 < $posMain6,
    '测试6: render 内容按 bufferOrder 顺序出现');

// ─────────────────────────────────────────────────────────────
// 测试 7: renderWithSeparators 插入分区注释
// ─────────────────────────────────────────────────────────────
$b7 = new MultiBuffer();
$b7->appendLineTo('cheaders', '#include <stdio.h>');
$b7->appendLineTo('typedefs', 'typedef int t_int;');
$b7->appendLineTo('out', 'int main() { return 0; }');
$rendered7 = $b7->renderWithSeparators(true);
check(str_contains($rendered7, '/* === CHEADERS === */'), '测试7: 包含 CHEADERS 分区注释');
check(str_contains($rendered7, '/* === TYPEDEFS === */'), '测试7: 包含 TYPEDEFS 分区注释');
check(str_contains($rendered7, '/* === OUT === */'), '测试7: 包含 OUT 分区注释');
// 空缓冲区不应有注释
check(!str_contains($rendered7, '/* === DEFINITIONS === */'), '测试7: 空缓冲区 definitions 无注释');
check(!str_contains($rendered7, '/* === AUTO_FUNCS === */'), '测试7: 空缓冲区 auto_funcs 无注释');
check(!str_contains($rendered7, '/* === CLEANUPS === */'), '测试7: 空缓冲区 cleanups 无注释');
// 分区注释顺序正确：CHEADERS 在 TYPEDEFS 前，TYPEDEFS 在 OUT 前
$posChead = strpos($rendered7, '/* === CHEADERS === */');
$posType  = strpos($rendered7, '/* === TYPEDEFS === */');
$posOut   = strpos($rendered7, '/* === OUT === */');
check($posChead !== false && $posType !== false && $posOut !== false && $posChead < $posType && $posType < $posOut,
    '测试7: 分区注释按 bufferOrder 顺序出现');
// withComments=false 退化为 render()
check($b7->renderWithSeparators(false) === $b7->render(), '测试7: renderWithSeparators(false) 等价于 render()');

// ─────────────────────────────────────────────────────────────
// 测试 8: 空缓冲区跳过
// ─────────────────────────────────────────────────────────────
$b8 = new MultiBuffer();
// 只设置 out
$b8->appendLineTo('out', 'int main() { return 0; }');
$r8 = $b8->render();
check($r8 === "int main() { return 0; }\n", '测试8: 只有 out 有内容时 render 只输出 out');
check(!str_contains($r8, "\n\n\n"), '测试8: 不会出现连续多个空行（空缓冲区跳过）');

// 全空时 render 返回空字符串
$b8b = new MultiBuffer();
check($b8b->render() === '', '测试8: 全空 render 返回空字符串');
check($b8b->renderWithSeparators() === '', '测试8: 全空 renderWithSeparators 返回空字符串');

// ─────────────────────────────────────────────────────────────
// 测试 9: getBuffer/setBuffer
// ─────────────────────────────────────────────────────────────
$b9 = new MultiBuffer();
$b9->setBuffer('typedefs', 'typedef long t_long;');
check($b9->getBuffer('typedefs') === 'typedef long t_long;', '测试9: setBuffer 覆盖后 getBuffer 返回新值');
$b9->setBuffer('typedefs', '');
check($b9->getBuffer('typedefs') === '', '测试9: setBuffer 设为空字符串');
check(!$b9->hasContent('typedefs'), '测试9: setBuffer 空字符串后 hasContent=false');

// ─────────────────────────────────────────────────────────────
// 测试 10: clear/clearAll
// ─────────────────────────────────────────────────────────────
$b10 = new MultiBuffer();
$b10->appendLineTo('cheaders', '#include <stdio.h>');
$b10->appendLineTo('typedefs', 'typedef int t_int;');
$b10->appendLineTo('out', 'int main() { return 0; }');
$b10->clear('typedefs');
check($b10->getBuffer('typedefs') === '', '测试10: clear 单个缓冲区');
check($b10->getBuffer('cheaders') === "#include <stdio.h>\n", '测试10: clear 不影响其他缓冲区');
check($b10->getBuffer('out') === "int main() { return 0; }\n", '测试10: clear out 保持');
// clearAll
$b10->clearAll();
foreach (['cheaders', 'typedefs', 'definitions', 'auto_funcs', 'out', 'cleanups'] as $n) {
    check($b10->getBuffer($n) === '', "测试10: clearAll 后 {$n} 为空");
}
check($b10->current() === 'out', '测试10: clearAll 后 currentBuffer 重置为 out');

// ─────────────────────────────────────────────────────────────
// 测试 11: ensureForwardDecl 去重
// ─────────────────────────────────────────────────────────────
$b11 = new MultiBuffer();
$b11->ensureForwardDecl('t_int tphp_fn_add(t_int, t_int);');
check($b11->getBuffer('definitions') === "t_int tphp_fn_add(t_int, t_int);\n", '测试11: 首次添加写入 definitions');
$b11->ensureForwardDecl('t_int tphp_fn_add(t_int, t_int);');  // 重复
check($b11->getBuffer('definitions') === "t_int tphp_fn_add(t_int, t_int);\n", '测试11: 重复声明被去重（只保留一份）');
$b11->ensureForwardDecl('t_int tphp_fn_sub(t_int, t_int);');  // 不同声明
check($b11->getBuffer('definitions') === "t_int tphp_fn_add(t_int, t_int);\nt_int tphp_fn_sub(t_int, t_int);\n",
    '测试11: 不同声明追加到 definitions');
// 子串去重：声明的子串已存在时也不会重复添加
$b11->ensureForwardDecl('tphp_fn_add');  // 子串已存在于 definitions
check(!str_contains($b11->getBuffer('definitions'), "tphp_fn_add\ntphp_fn_add\n"),
    '测试11: 子串匹配也会去重');

// ─────────────────────────────────────────────────────────────
// 测试 12: addAutoFunc 去重
// ─────────────────────────────────────────────────────────────
$b12 = new MultiBuffer();
$funcDef = "char* tphp_str_dup(const char* s) { return strdup(s); }";
$b12->addAutoFunc($funcDef);
check($b12->getBuffer('auto_funcs') === $funcDef . "\n", '测试12: 首次添加写入 auto_funcs');
$b12->addAutoFunc($funcDef);  // 重复
check($b12->getBuffer('auto_funcs') === $funcDef . "\n", '测试12: 重复函数定义被去重');
$b12->addAutoFunc("void tphp_free(void* p) { free(p); }");  // 不同函数
check(str_contains($b12->getBuffer('auto_funcs'), 'tphp_free'),
    '测试12: 不同函数定义追加到 auto_funcs');
check(substr_count($b12->getBuffer('auto_funcs'), $funcDef) === 1,
    '测试12: 原函数仍只有一份');

// ─────────────────────────────────────────────────────────────
// 测试 13: addCleanup 添加清理代码
// ─────────────────────────────────────────────────────────────
$b13 = new MultiBuffer();
$b13->addCleanup('atexit(cleanup_resources);');
check($b13->getBuffer('cleanups') === "atexit(cleanup_resources);\n", '测试13: addCleanup 写入 cleanups');
$b13->addCleanup('free(global_buffer);');
check($b13->getBuffer('cleanups') === "atexit(cleanup_resources);\nfree(global_buffer);\n",
    '测试13: addCleanup 累加（不去重）');
check($b13->hasContent('cleanups'), '测试13: cleanups hasContent=true');

// ─────────────────────────────────────────────────────────────
// 测试 14: hasContent
// ─────────────────────────────────────────────────────────────
$b14 = new MultiBuffer();
check(!$b14->hasContent('out'), '测试14: 空 out hasContent=false');
$b14->appendTo('out', 'x');
check($b14->hasContent('out'), '测试14: 有内容后 out hasContent=true');
check(!$b14->hasContent('cheaders'), '测试14: 仍未写入 cheaders hasContent=false');
$b14->appendTo('cheaders', '');
check(!$b14->hasContent('cheaders'), '测试14: 空字符串不算内容');

// ─────────────────────────────────────────────────────────────
// 测试 15: bufferNames
// ─────────────────────────────────────────────────────────────
$b15 = new MultiBuffer();
$names15 = $b15->bufferNames();
check($names15 === ['cheaders', 'typedefs', 'definitions', 'auto_funcs', 'out', 'cleanups'],
    '测试15: bufferNames 返回完整 6 个缓冲区名（按 bufferOrder 顺序）');
check(count($names15) === 6, '测试15: 缓冲区数量 = 6');

// ─────────────────────────────────────────────────────────────
// 测试 16: 完整 C 代码生成示例
// 模拟 FlatCodeGenerator 生成一个简单 C 程序：
//   - cheaders: 标准 IO 头
//   - typedefs: t_int 类型
//   - definitions: add 函数前向声明
//   - auto_funcs: 字符串复制辅助函数
//   - out: add 函数实现 + main 函数
//   - cleanups: atexit 注册
// ─────────────────────────────────────────────────────────────
$b16 = new MultiBuffer();

// 模拟编译 PHP: function add(int $a, int $b): int { return $a + $b; }
//              echo add(1, 2);

// 1. C 头文件
$b16->appendLineTo('cheaders', '#include <stdio.h>');
$b16->appendLineTo('cheaders', '#include <stdlib.h>');

// 2. 类型定义
$b16->appendLineTo('typedefs', 'typedef int t_int;');

// 3. 函数前向声明（遇到调用 add(1,2) 时确保已声明）
$b16->ensureForwardDecl('t_int tphp_fn_add(t_int a, t_int b);');

// 4. 自动生成的辅助函数（例如字符串处理）
$b16->addAutoFunc('char* tphp_str_dup(const char* s) { return strdup(s); }');

// 5. 函数体
$b16->appendLineTo('out', 't_int tphp_fn_add(t_int a, t_int b) {');
$b16->appendLineTo('out', '    return a + b;');
$b16->appendLineTo('out', '}');
$b16->appendLineTo('out', '');
$b16->appendLineTo('out', 'int main(void) {');
$b16->appendLineTo('out', '    t_int r = tphp_fn_add(1, 2);');
$b16->appendLineTo('out', '    printf("%d\n", r);');
$b16->appendLineTo('out', '    return 0;');
$b16->appendLineTo('out', '}');

// 6. 清理代码
$b16->addCleanup('atexit(tphp_global_cleanup);');

$fullC = $b16->render();

// 验证完整 C 代码包含所有部分
check(str_contains($fullC, '#include <stdio.h>'), '测试16: 完整代码包含 #include <stdio.h>');
check(str_contains($fullC, '#include <stdlib.h>'), '测试16: 完整代码包含 #include <stdlib.h>');
check(str_contains($fullC, 'typedef int t_int;'), '测试16: 完整代码包含 typedef int t_int;');
check(str_contains($fullC, 't_int tphp_fn_add(t_int a, t_int b);'), '测试16: 完整代码包含前向声明');
check(str_contains($fullC, 'char* tphp_str_dup(const char* s) { return strdup(s); }'),
    '测试16: 完整代码包含自动函数');
check(str_contains($fullC, 't_int tphp_fn_add(t_int a, t_int b) {'), '测试16: 完整代码包含函数实现');
check(str_contains($fullC, 'int main(void) {'), '测试16: 完整代码包含 main 函数');
check(str_contains($fullC, 'printf("%d\n", r);'), '测试16: 完整代码包含 printf 调用');
check(str_contains($fullC, 'atexit(tphp_global_cleanup);'), '测试16: 完整代码包含清理代码');

// 验证顺序：cheaders 在 typedefs 前，typedefs 在 definitions 前，
//         definitions 在 auto_funcs 前，auto_funcs 在 out 前，out 在 cleanups 前
$posStdio  = strpos($fullC, '#include <stdio.h>');
$posTypedef = strpos($fullC, 'typedef int t_int;');
$posFwdDecl = strpos($fullC, 't_int tphp_fn_add(t_int a, t_int b);');
$posAutoFn  = strpos($fullC, 'char* tphp_str_dup');
$posMain    = strpos($fullC, 'int main(void)');
$posAtexit  = strpos($fullC, 'atexit(tphp_global_cleanup);');
check($posStdio < $posTypedef, '测试16: cheaders 在 typedefs 前');
check($posTypedef < $posFwdDecl, '测试16: typedefs 在 definitions 前');
check($posFwdDecl < $posAutoFn, '测试16: definitions 在 auto_funcs 前');
check($posAutoFn < $posMain, '测试16: auto_funcs 在 out 前');
check($posMain < $posAtexit, '测试16: out 在 cleanups 前');

// 验证 renderWithSeparators 输出可读性
$withSep = $b16->renderWithSeparators(true);
$sepLines = explode("\n", $withSep);
check($sepLines[0] === '/* === CHEADERS === */', '测试16: renderWithSeparators 首行 = CHEADERS 注释');
check(str_contains($withSep, '/* === CLEANUPS === */'), '测试16: renderWithSeparators 包含 CLEANUPS 注释');

// 打印完整 C 代码示例（便于人工审查）
echo "\n--- 测试16: 完整 C 代码示例 (renderWithSeparators) ---\n";
echo $b16->renderWithSeparators(true);
echo "--- end ---\n";

// ─────────────────────────────────────────────────────────────
// 测试 17: 异常情况 — 未知缓冲区名
// ─────────────────────────────────────────────────────────────
$b17 = new MultiBuffer();
$threw = false;
try {
    $b17->select('unknown_buf');
} catch (InvalidArgumentException $e) {
    $threw = true;
}
check($threw, '测试17: select 未知缓冲区抛 InvalidArgumentException');

$threw2 = false;
try {
    $b17->appendTo('no_such', 'x');
} catch (InvalidArgumentException $e) {
    $threw2 = true;
}
check($threw2, '测试17: appendTo 未知缓冲区抛 InvalidArgumentException');

$threw3 = false;
try {
    $b17->getBuffer('missing');
} catch (InvalidArgumentException $e) {
    $threw3 = true;
}
check($threw3, '测试17: getBuffer 未知缓冲区抛 InvalidArgumentException');

// ─────────────────────────────────────────────────────────────
// 测试 18: 多次实例化独立
// ─────────────────────────────────────────────────────────────
$bx = new MultiBuffer();
$by = new MultiBuffer();
$bx->appendTo('out', 'from X');
$by->appendTo('out', 'from Y');
check($bx->getBuffer('out') === 'from X', '测试18: 实例 X 内容独立');
check($by->getBuffer('out') === 'from Y', '测试18: 实例 Y 内容独立');
check($bx->render() === 'from X', '测试18: 实例 X render 不受 Y 影响');

// ─────────────────────────────────────────────────────────────
// 汇总
// ─────────────────────────────────────────────────────────────
echo "\n";
echo "====================================\n";
echo "MultiBuffer 单元测试结果\n";
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
