<?php

declare(strict_types=1);

// ============================================================
// FlatAstConverter + Parser::parseToFlatAst 集成测试
//
// 运行方式：
//   cd c:\project\php\TinyPHP
//   php tools/AST/flat_parser_test.php
//
// 测试范围：
//   1. Parser::parseToFlatAst() 入口能正常返回 FlatAst
//   2. 简单函数定义转换为 FlatAst 后结构正确
//   3. 表达式嵌套（$a + $b → BinaryExpr with VariableExpr children）
//   4. 语句序列（函数体 return 语句）
//   5. 类声明、方法、属性等复杂结构
//   6. 控制流（if 语句）
//   7. 函数调用表达式
// ============================================================

require_once __DIR__ . '/../../src/TokenType.php';
require_once __DIR__ . '/../../src/Token.php';
require_once __DIR__ . '/../../src/AST/Node.php';
require_once __DIR__ . '/../../src/AST/FlatAst.php';
require_once __DIR__ . '/../../src/AST/FlatAstConverter.php';
require_once __DIR__ . '/../../src/Lexer.php';
require_once __DIR__ . '/../../src/Parser.php';

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

/** 解析 PHP 源码并返回 FlatAst */
function parseToFlatAst(string $source): FlatAst
{
    $lexer = new Lexer($source);
    $tokens = $lexer->tokenize();
    $parser = new Parser($tokens);
    return $parser->parseToFlatAst();
}

// ─────────────────────────────────────────────────────────────
// 测试 1: 简单函数定义 — function add(int $a, int $b): int { return $a + $b; }
//   验证：
//   - root 节点 kind = ProgramNode
//   - ProgramNode 子节点包含 FunctionNode
//   - FunctionNode 字段（name/returnType/paramCount）正确
// ─────────────────────────────────────────────────────────────
$src1 = '<?php function add(int $a, int $b): int { return $a + $b; }';
$ast1 = parseToFlatAst($src1);

check($ast1->root >= 0, '测试1: root 索引有效（>= 0）');
check($ast1->nodeCount > 0, '测试1: AST 包含节点');
$root1 = $ast1->nodes[$ast1->root];
check($root1['kind'] === NodeKind::ProgramNode, '测试1: root kind = ProgramNode');
check($root1['extra']['hasMainClass'] === false, '测试1: 无 main class');
check($root1['extra']['functionCount'] === 1, '测试1: functionCount = 1');

// ProgramNode 的第 0 个子节点应为 FunctionNode
$fnIdx = $ast1->child($ast1->root, 0);
check($ast1->nodes[$fnIdx]['kind'] === NodeKind::FunctionNode, '测试1: child[0] = FunctionNode');
check($ast1->nodes[$fnIdx]['value'] === 'add', '测试1: 函数名 = add');
check($ast1->nodes[$fnIdx]['extra']['returnType'] === 'int', '测试1: 返回类型 = int');
check($ast1->nodes[$fnIdx]['extra']['paramCount'] === 2, '测试1: 参数数量 = 2');

// ─────────────────────────────────────────────────────────────
// 测试 2: 表达式嵌套 — return $a + $b;
//   验证：
//   - FunctionNode 子节点顺序：[ParamNode $a, ParamNode $b, ReturnStmtNode]
//   - ReturnStmt 的子节点是 BinaryExpr(+)
//   - BinaryExpr 的左右子节点都是 VariableExpr
// ─────────────────────────────────────────────────────────────
check($ast1->childCount($fnIdx) === 3, '测试2: FunctionNode 有 3 个子节点（2 params + 1 return）');

$paramAIdx = $ast1->child($fnIdx, 0);
$paramBIdx = $ast1->child($fnIdx, 1);
$retIdx    = $ast1->child($fnIdx, 2);

check($ast1->nodes[$paramAIdx]['kind'] === NodeKind::ParamNode, '测试2: param[0] = ParamNode');
check($ast1->nodes[$paramAIdx]['value'] === '$a', '测试2: param[0] name = $a');
check($ast1->nodes[$paramAIdx]['extra']['type'] === 'int', '测试2: param[0] type = int');
check($ast1->nodes[$paramBIdx]['value'] === '$b', '测试2: param[1] name = $b');

// ReturnStmt → BinaryExpr
check($ast1->nodes[$retIdx]['kind'] === NodeKind::ReturnStmtNode, '测试2: child[2] = ReturnStmt');
check($ast1->nodes[$retIdx]['extra']['hasExpr'] === true, '测试2: return 有表达式');

$plusIdx = $ast1->child($retIdx, 0);
check($ast1->nodes[$plusIdx]['kind'] === NodeKind::BinaryExpr, '测试2: return expr = BinaryExpr');
check($ast1->nodes[$plusIdx]['value'] === '+', '测试2: 二元运算符 = +');
check($ast1->childCount($plusIdx) === 2, '测试2: BinaryExpr 有 2 个子节点');

$leftIdx  = $ast1->child($plusIdx, 0);
$rightIdx = $ast1->child($plusIdx, 1);
check($ast1->nodes[$leftIdx]['kind'] === NodeKind::VariableExpr, '测试2: 左操作数 = VariableExpr');
check($ast1->nodes[$leftIdx]['value'] === '$a', '测试2: 左操作数 value = $a');
check($ast1->nodes[$rightIdx]['kind'] === NodeKind::VariableExpr, '测试2: 右操作数 = VariableExpr');
check($ast1->nodes[$rightIdx]['value'] === '$b', '测试2: 右操作数 value = $b');

// ─────────────────────────────────────────────────────────────
// 测试 3: 类声明 + 属性 + 方法
//   class Foo { public int $x = 10; public function get(): int { return $this->x; } }
//   验证：
//   - ProgramNode.hasMainClass = true
//   - ClassNode 是 ProgramNode 的第 0 个子节点
//   - ClassNode 的子节点包含 PropertyDeclNode 和 MethodNode
//   - PropertyDeclNode 有默认值子节点（IntLiteralExpr 10）
//   - MethodNode 体包含 return $this->x（PropertyAccessExpr）
// ─────────────────────────────────────────────────────────────
$src3 = '<?php class Foo { public int $x = 10; public function get(): int { return $this->x; } }';
$ast3 = parseToFlatAst($src3);

$root3 = $ast3->nodes[$ast3->root];
check($root3['extra']['hasMainClass'] === true, '测试3: hasMainClass = true');

$classIdx = $ast3->child($ast3->root, 0);
check($ast3->nodes[$classIdx]['kind'] === NodeKind::ClassNode, '测试3: child[0] = ClassNode');
check($ast3->nodes[$classIdx]['value'] === 'Foo', '测试3: 类名 = Foo');

$classExtra = $ast3->nodes[$classIdx]['extra'];
check($classExtra['propertyCount'] === 1, '测试3: 属性数量 = 1');
check($classExtra['methodCount'] === 1, '测试3: 方法数量 = 1');

// ClassNode children 顺序：[属性, 方法]
$propIdx   = $ast3->child($classIdx, 0);
$methodIdx = $ast3->child($classIdx, 1);

check($ast3->nodes[$propIdx]['kind'] === NodeKind::PropertyDeclNode, '测试3: class child[0] = PropertyDecl');
check($ast3->nodes[$propIdx]['value'] === '$x', '测试3: 属性名 = $x');
check($ast3->nodes[$propIdx]['extra']['type'] === 'int', '测试3: 属性类型 = int');
check($ast3->nodes[$propIdx]['extra']['visibility'] === 'public', '测试3: 属性可见性 = public');
check($ast3->nodes[$propIdx]['extra']['hasDefault'] === true, '测试3: 属性有默认值');

// 属性默认值 = IntLiteralExpr 10
$defaultIdx = $ast3->child($propIdx, 0);
check($ast3->nodes[$defaultIdx]['kind'] === NodeKind::IntLiteralExpr, '测试3: 属性默认值 = IntLiteral');
check($ast3->nodes[$defaultIdx]['value'] === 10, '测试3: 属性默认值 = 10');

// 方法
check($ast3->nodes[$methodIdx]['kind'] === NodeKind::MethodNode, '测试3: class child[1] = MethodNode');
check($ast3->nodes[$methodIdx]['value'] === 'get', '测试3: 方法名 = get');
check($ast3->nodes[$methodIdx]['extra']['returnType'] === 'int', '测试3: 方法返回类型 = int');
check($ast3->nodes[$methodIdx]['extra']['visibility'] === 'public', '测试3: 方法可见性 = public');
check($ast3->nodes[$methodIdx]['extra']['hasBody'] === true, '测试3: 方法有函数体');

// MethodNode children = [return $this->x]
// 方法无属性/参数/promoted，所以 children[0] 直接是 body 的第一条语句
check($ast3->childCount($methodIdx) === 1, '测试3: 方法有 1 个子节点（return 语句）');
$retIdx3 = $ast3->child($methodIdx, 0);
check($ast3->nodes[$retIdx3]['kind'] === NodeKind::ReturnStmtNode, '测试3: 方法体[0] = ReturnStmt');

// return $this->x → PropertyAccessExpr
$paIdx = $ast3->child($retIdx3, 0);
check($ast3->nodes[$paIdx]['kind'] === NodeKind::PropertyAccessExpr, '测试3: return expr = PropertyAccess');
check($ast3->nodes[$paIdx]['value'] === 'x', '测试3: 属性访问名 = x');

// PropertyAccessExpr 的子节点 = [$this 变量]
$objIdx = $ast3->child($paIdx, 0);
check($ast3->nodes[$objIdx]['kind'] === NodeKind::VariableExpr, '测试3: 属性访问对象 = VariableExpr');
check($ast3->nodes[$objIdx]['value'] === '$this', '测试3: 对象 = $this');

// ─────────────────────────────────────────────────────────────
// 测试 4: 控制流 — if 语句
//   function f(int $x): int { if ($x > 0) { return $x; } return 0; }
//   验证：
//   - FunctionNode 子节点顺序：[ParamNode $x, IfStmtNode, ReturnStmtNode]
//   - IfStmt 第一个子节点是 condition（BinaryExpr >）
//   - IfStmt 第二个子节点是 thenBody 的第一条语句（ReturnStmt）
// ─────────────────────────────────────────────────────────────
$src4 = '<?php function f(int $x): int { if ($x > 0) { return $x; } return 0; }';
$ast4 = parseToFlatAst($src4);

$fnIdx4 = $ast4->child($ast4->root, 0);
// children: [param $x, ifStmt, returnStmt]
check($ast4->childCount($fnIdx4) === 3, '测试4: 函数 f 有 3 个子节点（param + if + return）');

$ifIdx4 = $ast4->child($fnIdx4, 1);
check($ast4->nodes[$ifIdx4]['kind'] === NodeKind::IfStmtNode, '测试4: child[1] = IfStmt');
check($ast4->nodes[$ifIdx4]['extra']['thenBodyCount'] === 1, '测试4: thenBodyCount = 1');
check($ast4->nodes[$ifIdx4]['extra']['elseifCount'] === 0, '测试4: elseifCount = 0');
check($ast4->nodes[$ifIdx4]['extra']['elseBodyCount'] === 0, '测试4: elseBodyCount = 0');

// IfStmt children = [condition, ...thenBody]
check($ast4->childCount($ifIdx4) === 2, '测试4: IfStmt 有 2 个子节点（cond + 1 then stmt）');
$condIdx4 = $ast4->child($ifIdx4, 0);
check($ast4->nodes[$condIdx4]['kind'] === NodeKind::BinaryExpr, '测试4: if 条件 = BinaryExpr');
check($ast4->nodes[$condIdx4]['value'] === '>', '测试4: if 条件运算符 = >');

$thenIdx4 = $ast4->child($ifIdx4, 1);
check($ast4->nodes[$thenIdx4]['kind'] === NodeKind::ReturnStmtNode, '测试4: then body = ReturnStmt');

// ─────────────────────────────────────────────────────────────
// 测试 5: 函数调用表达式
//   function f(): int { return add(1, 2); }
//   验证：
//   - CallExpr 节点 value = 被调用函数名
//   - CallExpr 子节点为参数列表
//   - 全局函数调用 callee=null，hasCallee=false
// ─────────────────────────────────────────────────────────────
$src5 = '<?php function f(): int { return add(1, 2); }';
$ast5 = parseToFlatAst($src5);

$fnIdx5 = $ast5->child($ast5->root, 0);
check($ast5->childCount($fnIdx5) === 1, '测试5: 函数 f 有 1 个子节点（return）');
$retIdx5 = $ast5->child($fnIdx5, 0);
$callIdx = $ast5->child($retIdx5, 0);

check($ast5->nodes[$callIdx]['kind'] === NodeKind::CallExpr, '测试5: return expr = CallExpr');
check($ast5->nodes[$callIdx]['value'] === 'add', '测试5: 调用函数名 = add');
check($ast5->nodes[$callIdx]['extra']['argCount'] === 2, '测试5: 参数数量 = 2');
check($ast5->nodes[$callIdx]['extra']['hasCallee'] === false, '测试5: 全局函数调用 hasCallee = false');

// CallExpr children = [arg1, arg2]（callee=null 不产生子节点）
check($ast5->childCount($callIdx) === 2, '测试5: CallExpr 有 2 个子节点（参数）');
$arg1Idx = $ast5->child($callIdx, 0);
$arg2Idx = $ast5->child($callIdx, 1);
check($ast5->nodes[$arg1Idx]['kind'] === NodeKind::IntLiteralExpr, '测试5: arg[0] = IntLiteral');
check($ast5->nodes[$arg1Idx]['value'] === 1, '测试5: arg[0] value = 1');
check($ast5->nodes[$arg2Idx]['kind'] === NodeKind::IntLiteralExpr, '测试5: arg[1] = IntLiteral');
check($ast5->nodes[$arg2Idx]['value'] === 2, '测试5: arg[1] value = 2');

// ─────────────────────────────────────────────────────────────
// 测试 6: 多语句函数体 — 验证语句序列顺序
//   function g(int $x): void { echo $x; $x = 10; }
//   验证：
//   - FunctionNode 子节点顺序：[ParamNode, EchoStmt, AssignStmt]
//   - EchoStmt 子节点为表达式列表
//   - AssignStmt value = 变量名
// ─────────────────────────────────────────────────────────────
$src6 = '<?php function g(int $x): void { echo $x; $x = 10; }';
$ast6 = parseToFlatAst($src6);

$fnIdx6 = $ast6->child($ast6->root, 0);
// children: [param $x, echoStmt, assignStmt]
check($ast6->childCount($fnIdx6) === 3, '测试6: 函数 g 有 3 个子节点（param + echo + assign）');

$echoIdx = $ast6->child($fnIdx6, 1);
check($ast6->nodes[$echoIdx]['kind'] === NodeKind::EchoStmtNode, '测试6: child[1] = EchoStmt');
check($ast6->nodes[$echoIdx]['extra']['exprCount'] === 1, '测试6: echo 表达式数量 = 1');
$echoVarIdx = $ast6->child($echoIdx, 0);
check($ast6->nodes[$echoVarIdx]['kind'] === NodeKind::VariableExpr, '测试6: echo 参数 = VariableExpr');
check($ast6->nodes[$echoVarIdx]['value'] === '$x', '测试6: echo 参数 value = $x');

$assignIdx = $ast6->child($fnIdx6, 2);
check($ast6->nodes[$assignIdx]['kind'] === NodeKind::AssignStmtNode, '测试6: child[2] = AssignStmt');
check($ast6->nodes[$assignIdx]['value'] === '$x', '测试6: 赋值变量名 = $x');
$assignRhsIdx = $ast6->child($assignIdx, 0);
check($ast6->nodes[$assignRhsIdx]['kind'] === NodeKind::IntLiteralExpr, '测试6: 赋值右式 = IntLiteral');
check($ast6->nodes[$assignRhsIdx]['value'] === 10, '测试6: 赋值右式 value = 10');

// ─────────────────────────────────────────────────────────────
// 测试 7: 节点位置信息 — ExprNode 有 line/column
//   验证 BinaryExpr 的 pos 字段非 [0,0]（因为表达式节点有位置信息）
// ─────────────────────────────────────────────────────────────
$plusPos = $ast1->nodes[$plusIdx]['pos'];
check(is_array($plusPos) && count($plusPos) === 2, '测试7: BinaryExpr pos 是 [line, col] 数组');
check($plusPos[0] > 0, '测试7: BinaryExpr pos.line > 0（表达式有位置信息）');

// 语句节点（非 ExprNode 子类）pos 应为 [0,0]
$retPos = $ast1->nodes[$retIdx]['pos'];
check($retPos === [0, 0], '测试7: ReturnStmt pos = [0,0]（语句节点无位置信息）');

// ─────────────────────────────────────────────────────────────
// 测试 8: FlatAst 完整性 — traverse 能访问到所有节点
//   验证从 root 开始 traverse 能访问到 nodeCount 个节点
// ─────────────────────────────────────────────────────────────
$visitedCount = 0;
$ast1->traverse($ast1->root, function () use (&$visitedCount) {
    $visitedCount++;
});
check($visitedCount === $ast1->nodeCount, '测试8: traverse 访问节点数 = nodeCount');

// 验证所有节点 kind 都是 NodeKind 枚举值
$allKindsValid = true;
foreach ($ast1->nodes as $n) {
    if (!$n['kind'] instanceof NodeKind) {
        $allKindsValid = false;
        break;
    }
}
check($allKindsValid, '测试8: 所有节点 kind 都是 NodeKind 实例');

// ─────────────────────────────────────────────────────────────
// 汇总
// ─────────────────────────────────────────────────────────────
echo "\n";
echo "====================================\n";
echo "FlatAstConverter + parseToFlatAst 测试结果\n";
echo "====================================\n";
echo "通过: {$pass}\n";
echo "失败: {$fail}\n";
echo "测试 1 AST 节点数: {$ast1->nodeCount}\n";
echo "测试 3 AST 节点数（类）: {$ast3->nodeCount}\n";
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
