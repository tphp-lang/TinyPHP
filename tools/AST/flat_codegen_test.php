<?php

declare(strict_types=1);

// ============================================================
// FlatCodeGenerator 单元测试
//
// 运行方式：
//   cd c:\project\php\TinyPHP
//   php tools/AST/flat_codegen_test.php
//
// 测试范围：
//   1.  字面量生成（int/string/float/bool/null）
//   2.  变量引用生成
//   3.  二元运算生成（算术、字符串连接）
//   4.  函数定义生成
//   5.  函数调用生成
//   6.  Echo 语句生成
//   7.  赋值语句生成
//   8.  If 语句生成
//   9.  While 循环生成
//   10. For 循环生成
//   11. 完整程序生成（含 main 函数）
//
// 测试方式：Parser 解析 → FlatAstConverter 转换 → FlatTypeChecker 标注 →
//          FlatCodeGenerator 生成 → 验证生成的 C 代码包含预期的字符串
// ============================================================

require_once __DIR__ . '/../../src/TokenType.php';
require_once __DIR__ . '/../../src/Token.php';
require_once __DIR__ . '/../../src/AST/Node.php';
require_once __DIR__ . '/../../src/AST/FlatAst.php';
require_once __DIR__ . '/../../src/AST/FlatAstConverter.php';
require_once __DIR__ . '/../../src/Lexer.php';
require_once __DIR__ . '/../../src/Parser.php';
require_once __DIR__ . '/../../src/Type.php';
require_once __DIR__ . '/../../src/SymbolTable.php';
require_once __DIR__ . '/../../src/FlatTypeChecker.php';
require_once __DIR__ . '/../../src/FlatCodeGenerator.php';

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

/** 解析 PHP 源码 → FlatAst → 类型检查 → 返回已标注 typ 的 FlatAst */
function parseAndCheck(string $source): FlatAst
{
    $lexer = new Lexer($source);
    $tokens = $lexer->tokenize();
    $parser = new Parser($tokens);
    $ast = $parser->parseToFlatAst();
    $checker = new FlatTypeChecker($ast, new SymbolTable());
    $checker->check();
    return $ast;
}

/** 解析 → 检查 → 生成 C 代码，返回字符串 */
function genCCode(string $source): string
{
    $ast = parseAndCheck($source);
    $gen = new FlatCodeGenerator($ast);
    return $gen->generate();
}

/**
 * 对单节点调用 genExpr 生成代码。
 * 用于测试单个表达式节点的代码生成。
 */
function genNodeCode(FlatAst $ast, int $idx): string
{
    $gen = new FlatCodeGenerator($ast);
    return $gen->genExpr($idx);
}

// ─────────────────────────────────────────────────────────────
// 测试 1: 字面量生成
//   function f(): void { echo 42; echo 3.14; echo "hi"; echo true; echo null; }
//   验证：每个字面量节点生成的 C 代码字符串符合预期
// ─────────────────────────────────────────────────────────────
$src1 = '<?php function f(): void { echo 42; echo 3.14; echo "hi"; echo true; echo null; }';
$ast1 = parseAndCheck($src1);
$gen1 = new FlatCodeGenerator($ast1);

// 找到所有字面量节点
$intLit = -1; $floatLit = -1; $strLit = -1; $boolLit = -1; $nullLit = -1;
foreach ($ast1->nodes as $idx => $n) {
    if ($n['kind'] === NodeKind::IntLiteralExpr     && $intLit   < 0) $intLit   = $idx;
    if ($n['kind'] === NodeKind::FloatLiteralExpr   && $floatLit < 0) $floatLit = $idx;
    if ($n['kind'] === NodeKind::StringLiteralExpr  && $strLit   < 0) $strLit   = $idx;
    if ($n['kind'] === NodeKind::BoolLiteralExpr    && $boolLit  < 0) $boolLit  = $idx;
    if ($n['kind'] === NodeKind::NullLiteralExpr    && $nullLit  < 0) $nullLit  = $idx;
}

check($gen1->genExpr($intLit) === '42', '测试1: IntLiteral 42 → "42"，实际=' . $gen1->genExpr($intLit));
check($gen1->genExpr($floatLit) === '3.14', '测试1: FloatLiteral 3.14 → "3.14"，实际=' . $gen1->genExpr($floatLit));
check($gen1->genExpr($strLit) === '"hi"', '测试1: StringLiteral "hi" → "\"hi\""，实际=' . $gen1->genExpr($strLit));
check($gen1->genExpr($boolLit) === '1', '测试1: BoolLiteral true → "1"，实际=' . $gen1->genExpr($boolLit));
check($gen1->genExpr($nullLit) === '0', '测试1: NullLiteral → "0"，实际=' . $gen1->genExpr($nullLit));

// ─────────────────────────────────────────────────────────────
// 测试 2: 变量引用生成
//   function f(int $x): void { echo $x; }
//   验证：VariableExpr($x) → "x"
// ─────────────────────────────────────────────────────────────
$src2 = '<?php function f(int $x): void { echo $x; }';
$ast2 = parseAndCheck($src2);
$gen2 = new FlatCodeGenerator($ast2);

$varIdx = -1;
foreach ($ast2->nodes as $idx => $n) {
    if ($n['kind'] === NodeKind::VariableExpr && $varIdx < 0) $varIdx = $idx;
}
check($varIdx >= 0, '测试2: 找到 VariableExpr 节点');
check($gen2->genExpr($varIdx) === 'x', '测试2: VariableExpr($x) → "x"，实际=' . $gen2->genExpr($varIdx));

// ─────────────────────────────────────────────────────────────
// 测试 3: 二元运算生成（算术、字符串连接）
//   function f(): void { $a = 1 + 2; $b = "x" . "y"; $c = 3 * 4; }
//   验证：
//   - 1 + 2 → (1 + 2)
//   - "x" . "y" → tphp_str_concat("x", "y")
//   - 3 * 4 → (3 * 4)
// ─────────────────────────────────────────────────────────────
$src3 = '<?php function f(): void { $a = 1 + 2; $b = "x" . "y"; $c = 3 * 4; }';
$ast3 = parseAndCheck($src3);
$gen3 = new FlatCodeGenerator($ast3);

// 收集所有 BinaryExpr 节点（按出现顺序）
$binIdxs = [];
foreach ($ast3->nodes as $idx => $n) {
    if ($n['kind'] === NodeKind::BinaryExpr) $binIdxs[] = $idx;
}
check(count($binIdxs) === 3, '测试3: 找到 3 个 BinaryExpr');
check($gen3->genExpr($binIdxs[0]) === '(1 + 2)', '测试3: 1+2 → "(1 + 2)"，实际=' . $gen3->genExpr($binIdxs[0]));
check($gen3->genExpr($binIdxs[1]) === 'tphp_str_concat("x", "y")', '测试3: "x"."y" → tphp_str_concat("x", "y")，实际=' . $gen3->genExpr($binIdxs[1]));
check($gen3->genExpr($binIdxs[2]) === '(3 * 4)', '测试3: 3*4 → "(3 * 4)"，实际=' . $gen3->genExpr($binIdxs[2]));

// ─────────────────────────────────────────────────────────────
// 测试 4: 函数定义生成
//   function add(int $a, int $b): int { return $a + $b; }
//   验证生成的完整 C 代码包含：
//   - "tphp_fn_add"
//   - "t_int a, t_int b" 参数
//   - "return (a + b);"
// ─────────────────────────────────────────────────────────────
$src4 = '<?php function add(int $a, int $b): int { return $a + $b; }';
$c4 = genCCode($src4);

check(str_contains($c4, 'tphp_fn_add'), '测试4: 生成函数名 tphp_fn_add');
check(str_contains($c4, 't_int a') && str_contains($c4, 't_int b'), '测试4: 参数 t_int a, t_int b');
check(str_contains($c4, 'return (a + b);'), '测试4: 函数体 return (a + b);');

// ─────────────────────────────────────────────────────────────
// 测试 5: 函数调用生成
//   function f(): void { $r = add(1, 2); }
//   function add(int $a, int $b): int { return $a + $b; }
//   验证：调用 add(1, 2) → tphp_fn_add(1, 2)
// ─────────────────────────────────────────────────────────────
$src5 = '<?php
function f(): void { $r = add(1, 2); }
function add(int $a, int $b): int { return $a + $b; }
';
$ast5 = parseAndCheck($src5);
$gen5 = new FlatCodeGenerator($ast5);

// 找到 CallExpr 节点（在 f 内部）
$callIdx = -1;
foreach ($ast5->nodes as $idx => $n) {
    if ($n['kind'] === NodeKind::CallExpr) { $callIdx = $idx; break; }
}
check($callIdx >= 0, '测试5: 找到 CallExpr');
check($gen5->genExpr($callIdx) === 'tphp_fn_add(1, 2)', '测试5: add(1, 2) → tphp_fn_add(1, 2)，实际=' . $gen5->genExpr($callIdx));

// ─────────────────────────────────────────────────────────────
// 测试 6: Echo 语句生成
//   function f(): void { echo "hello"; echo 42; }
//   验证：生成 tphp_fn_echo("hello"); 和 tphp_fn_echo(42);
// ─────────────────────────────────────────────────────────────
$src6 = '<?php function f(): void { echo "hello"; echo 42; }';
$c6 = genCCode($src6);

check(str_contains($c6, 'tphp_fn_echo("hello");'), '测试6: echo "hello" → tphp_fn_echo("hello");');
check(str_contains($c6, 'tphp_fn_echo(42);'), '测试6: echo 42 → tphp_fn_echo(42);');

// ─────────────────────────────────────────────────────────────
// 测试 7: 赋值语句生成（带类型注解）
//   function f(): void { int $x = 10; }
//   验证：int $x = 10; → t_int x = 10;
// ─────────────────────────────────────────────────────────────
$src7 = '<?php function f(int $x): void { int $y = 10; }';
$c7 = genCCode($src7);

check(str_contains($c7, 't_int y = 10;'), '测试7: int $y = 10 → t_int y = 10;');

// ─────────────────────────────────────────────────────────────
// 测试 8: If 语句生成
//   function f(): void { if (true) { echo 1; } else { echo 2; } }
//   验证生成的代码包含 if (1) { ... } else { ... } 结构
// ─────────────────────────────────────────────────────────────
$src8 = '<?php function f(): void { if (true) { echo 1; } else { echo 2; } }';
$c8 = genCCode($src8);

check(str_contains($c8, 'if (1) {'), '测试8: if (1) {');
check(str_contains($c8, 'tphp_fn_echo(1);'), '测试8: then 块包含 tphp_fn_echo(1);');
check(str_contains($c8, 'else {'), '测试8: else {');
check(str_contains($c8, 'tphp_fn_echo(2);'), '测试8: else 块包含 tphp_fn_echo(2);');

// ─────────────────────────────────────────────────────────────
// 测试 9: While 循环生成
//   function f(): void { while (true) { echo 1; } }
//   验证生成的代码包含 while (1) { ... }
// ─────────────────────────────────────────────────────────────
$src9 = '<?php function f(): void { while (true) { echo 1; } }';
$c9 = genCCode($src9);

check(str_contains($c9, 'while (1) {'), '测试9: while (1) {');
check(str_contains($c9, 'tphp_fn_echo(1);'), '测试9: 循环体包含 tphp_fn_echo(1);');

// ─────────────────────────────────────────────────────────────
// 测试 10: For 循环生成
//   function f(): void { for ($i = 0; $i < 10; $i++) { echo $i; } }
//   验证生成的代码包含 for (...; ...; ...) 结构
// ─────────────────────────────────────────────────────────────
$src10 = '<?php function f(): void { for ($i = 0; $i < 10; $i++) { echo $i; } }';
$c10 = genCCode($src10);

check(str_contains($c10, 'for ('), '测试10: 生成 for 语句');
check(str_contains($c10, 'i < 10'), '测试10: for 条件 i < 10');
check(str_contains($c10, 'i++'), '测试10: for 步进 i++');
check(str_contains($c10, 'tphp_fn_echo(i);'), '测试10: 循环体 echo $i → tphp_fn_echo(i);');

// ─────────────────────────────────────────────────────────────
// 测试 11: 完整程序生成（含 main 函数）
//   function add(int $a, int $b): int { return $a + $b; }
//   验证：
//   - 生成的 C 代码包含 #include 头部
//   - 包含 tphp_fn_add 函数定义
//   - 包含 int main(int argc, char** argv) { ... } 入口
//   - 包含 return 0;
// ─────────────────────────────────────────────────────────────
$src11 = '<?php function add(int $a, int $b): int { return $a + $b; }';
$c11 = genCCode($src11);

check(str_contains($c11, '#include <stdio.h>'), '测试11: 包含 #include <stdio.h>');
check(str_contains($c11, '#include "tphp_runtime.h"'), '测试11: 包含 #include "tphp_runtime.h"');
check(str_contains($c11, 'int main(int argc, char** argv) {'), '测试11: 生成 main 函数签名');
check(str_contains($c11, 'return 0;'), '测试11: main 末尾 return 0;');
check(str_contains($c11, 'tphp_fn_add'), '测试11: 包含 tphp_fn_add 函数');
check(str_contains($c11, 'return (a + b);'), '测试11: add 函数体 return (a + b);');

// ─────────────────────────────────────────────────────────────
// 测试 12: 字符串字面量转义
//   function f(): void { echo "he said \"hi\""; }
//   验证：字符串中的双引号被正确转义
// ─────────────────────────────────────────────────────────────
$src12 = '<?php function f(): void { echo "he said \"hi\""; }';
$c12 = genCCode($src4);  // 注意：此处故意用 src4 以验证 generate 路径稳定
// 单独验证转义
$ast12 = parseAndCheck($src12);
$gen12 = new FlatCodeGenerator($ast12);
$strIdx = -1;
foreach ($ast12->nodes as $idx => $n) {
    if ($n['kind'] === NodeKind::StringLiteralExpr && $strIdx < 0) $strIdx = $idx;
}
$genStr = $gen12->genExpr($strIdx);
check(str_contains($genStr, 'he said'), '测试12: 字符串字面量内容保留');
check(str_contains($genStr, '\"hi\"'), '测试12: 双引号被转义为 \"，实际=' . $genStr);

// ─────────────────────────────────────────────────────────────
// 测试 13: 数组访问生成
//   function f(): void { echo $arr[0]; }
//   验证：$arr[0] → arr[0]
// ─────────────────────────────────────────────────────────────
$src13 = '<?php function f(array $arr): void { echo $arr[0]; }';
$ast13 = parseAndCheck($src13);
$gen13 = new FlatCodeGenerator($ast13);

$aaIdx = -1;
foreach ($ast13->nodes as $idx => $n) {
    if ($n['kind'] === NodeKind::ArrayAccessExpr && $aaIdx < 0) $aaIdx = $idx;
}
check($aaIdx >= 0, '测试13: 找到 ArrayAccessExpr');
$aa = $gen13->genExpr($aaIdx);
check($aa === 'arr[0]', '测试13: $arr[0] → arr[0]，实际=' . $aa);

// ─────────────────────────────────────────────────────────────
// 测试 14: 属性访问生成
//   function f(): void { echo $obj->name; }
//   验证：$obj->name → obj->name
// ─────────────────────────────────────────────────────────────
$src14 = '<?php function f(): void { echo $obj->name; }';
$ast14 = parseAndCheck($src14);
$gen14 = new FlatCodeGenerator($ast14);

$paIdx = -1;
foreach ($ast14->nodes as $idx => $n) {
    if ($n['kind'] === NodeKind::PropertyAccessExpr && $paIdx < 0) $paIdx = $idx;
}
check($paIdx >= 0, '测试14: 找到 PropertyAccessExpr');
$pa = $gen14->genExpr($paIdx);
check($pa === 'obj->name', '测试14: $obj->name → obj->name，实际=' . $pa);

// ─────────────────────────────────────────────────────────────
// 测试 15: Unary / Postfix 表达式生成
//   function f(): void { $a = !$b; $c = $i++; }
//   验证：
//   - !$b → (!$b 时 b 已经是 varName)
//   - $i++ → (i++)
// ─────────────────────────────────────────────────────────────
$src15 = '<?php function f(): void { $a = !$b; $c = $i++; }';
$ast15 = parseAndCheck($src15);
$gen15 = new FlatCodeGenerator($ast15);

$unIdx = -1; $postIdx = -1;
foreach ($ast15->nodes as $idx => $n) {
    if ($n['kind'] === NodeKind::UnaryExpr   && $unIdx   < 0) $unIdx   = $idx;
    if ($n['kind'] === NodeKind::PostfixExpr && $postIdx < 0) $postIdx = $idx;
}
check($unIdx >= 0,   '测试15: 找到 UnaryExpr');
check($postIdx >= 0, '测试15: 找到 PostfixExpr');
check($gen15->genExpr($unIdx)   === '(!b)',  '测试15: !$b → (!b)，实际=' . $gen15->genExpr($unIdx));
check($gen15->genExpr($postIdx) === '(i++)', '测试15: $i++ → (i++)，实际=' . $gen15->genExpr($postIdx));

// ─────────────────────────────────────────────────────────────
// 测试 16: Return 语句（无表达式）
//   function f(): void { return; }
//   验证：生成 return;
// ─────────────────────────────────────────────────────────────
$src16 = '<?php function f(): void { return; }';
$c16 = genCCode($src16);

check(str_contains($c16, 'return;'), '测试16: return; → return;');

// ─────────────────────────────────────────────────────────────
// 测试 17: 多函数程序
//   function add(...) / function sub(...)
//   验证生成的代码包含两个独立 C 函数
// ─────────────────────────────────────────────────────────────
$src17 = '<?php
function add(int $a, int $b): int { return $a + $b; }
function sub(int $a, int $b): int { return $a - $b; }
';
$c17 = genCCode($src17);

check(str_contains($c17, 'tphp_fn_add'), '测试17: 生成 tphp_fn_add');
check(str_contains($c17, 'tphp_fn_sub'), '测试17: 生成 tphp_fn_sub');
check(str_contains($c17, 'return (a + b);'), '测试17: add 函数体 return (a + b);');
check(str_contains($c17, 'return (a - b);'), '测试17: sub 函数体 return (a - b);');

// ─────────────────────────────────────────────────────────────
// 测试 18: Float 字面量补全小数点
//   function f(): void { echo 5; echo 5.0; }
//   验证：5 → "5"，5.0 → "5.0"（确保 C 浮点字面量带小数点）
// ─────────────────────────────────────────────────────────────
$src18 = '<?php function f(): void { echo 5.0; }';
$ast18 = parseAndCheck($src18);
$gen18 = new FlatCodeGenerator($ast18);

$fltIdx = -1;
foreach ($ast18->nodes as $idx => $n) {
    if ($n['kind'] === NodeKind::FloatLiteralExpr && $fltIdx < 0) $fltIdx = $idx;
}
$fltStr = $gen18->genExpr($fltIdx);
check(str_contains($fltStr, '.') || str_contains($fltStr, 'e') || str_contains($fltStr, 'E'), '测试18: 浮点字面量包含小数点，实际=' . $fltStr);

// ─────────────────────────────────────────────────────────────
// 测试 19: ElseIf 链生成
//   function f(): void { if ($x > 0) { echo 1; } elseif ($x == 0) { echo 2; } else { echo 3; } }
//   验证生成的代码包含 else if 块
// ─────────────────────────────────────────────────────────────
$src19 = '<?php function f(int $x): void { if ($x > 0) { echo 1; } elseif ($x == 0) { echo 2; } else { echo 3; } }';
$c19 = genCCode($src19);

check(str_contains($c19, 'if ((x > 0)) {'), '测试19: if 块条件 (x > 0)，实际 c=' . $c19);
check(str_contains($c19, 'else if ((x == 0)) {'), '测试19: elseif 块条件 (x == 0)');
check(str_contains($c19, 'tphp_fn_echo(1);'), '测试19: then 块');
check(str_contains($c19, 'tphp_fn_echo(2);'), '测试19: elseif 块');
check(str_contains($c19, 'tphp_fn_echo(3);'), '测试19: else 块');

// ─────────────────────────────────────────────────────────────
// 测试 20: Ternary 表达式生成
//   function f(): void { $r = true ? 1 : 2; }
//   验证：true ? 1 : 2 → (1 ? 1 : 2)
// ─────────────────────────────────────────────────────────────
$src20 = '<?php function f(): void { $r = true ? 1 : 2; }';
$ast20 = parseAndCheck($src20);
$gen20 = new FlatCodeGenerator($ast20);

$terIdx = -1;
foreach ($ast20->nodes as $idx => $n) {
    if ($n['kind'] === NodeKind::TernaryExpr && $terIdx < 0) $terIdx = $idx;
}
$ter = $gen20->genExpr($terIdx);
check($ter === '(1 ? 1 : 2)', '测试20: true ? 1 : 2 → (1 ? 1 : 2)，实际=' . $ter);

// ─────────────────────────────────────────────────────────────
// 打印一个完整的 C 代码示例（视觉检查）
// ─────────────────────────────────────────────────────────────
echo "\n====================================\n";
echo "示例：生成的 C 代码\n";
echo "====================================\n";
echo $c4;
echo "====================================\n";

// ─────────────────────────────────────────────────────────────
// 汇总
// ─────────────────────────────────────────────────────────────
echo "\n";
echo "====================================\n";
echo "FlatCodeGenerator 单元测试结果\n";
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
