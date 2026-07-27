<?php

declare(strict_types=1);

// ============================================================
// UsedFeatures 单元测试
//
// 运行方式：
//   cd c:\project\php\TinyPHP
//   php tools/AST/used_features_test.php
//
// 测试范围：
//   1.  初始化所有字段为 false
//   2.  markClosure 后 closure=true
//   3.  markArrowFunction 后 arrowFunction=true
//   4.  每个特性标记后状态正确（≥10 个特性逐一验证）
//   5.  hasClosureOrArrow 只有 closure 时返回 true
//   6.  hasClosureOrArrow 只有 arrowFunction 时返回 true
//   7.  hasClosureOrArrow 两者都 false 时返回 false
//   8.  hasAnyDirective 测试
//   9.  hasAnyCInterop 测试
//  10.  hasAnyOop 测试
//  11.  count() 统计正确
//  12.  reset() 重置所有
//  13.  markFromNodeKind 对几个常见节点类型正确标记
//  14.  toArray() 返回完整字段列表
// ============================================================

require_once __DIR__ . '/../../src/UsedFeatures.php';

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
// 测试 1: 初始化所有字段为 false
// ─────────────────────────────────────────────────────────────
$uf = new UsedFeatures();
check($uf->closure === false, '初始 closure=false');
check($uf->arrowFunction === false, '初始 arrowFunction=false');
check($uf->attribute === false, '初始 attribute=false');
check($uf->match === false, '初始 match=false');
check($uf->namedArgs === false, '初始 namedArgs=false');
check($uf->spread === false, '初始 spread=false');
check($uf->firstClassCallable === false, '初始 firstClassCallable=false');
check($uf->propertyHook === false, '初始 propertyHook=false');
check($uf->readonlyClass === false, '初始 readonlyClass=false');
check($uf->pipeOperator === false, '初始 pipeOperator=false');
check($uf->throwExpression === false, '初始 throwExpression=false');
check($uf->orBlock === false, '初始 orBlock=false');
check($uf->flagDirective === false, '初始 flagDirective=false');
check($uf->importDirective === false, '初始 importDirective=false');
check($uf->includeDirective === false, '初始 includeDirective=false');
check($uf->callbackDirective === false, '初始 callbackDirective=false');
check($uf->debugDirective === false, '初始 debugDirective=false');
check($uf->conditionalCompilation === false, '初始 conditionalCompilation=false');
check($uf->cStructDecl === false, '初始 cStructDecl=false');
check($uf->cFunctionDecl === false, '初始 cFunctionDecl=false');
check($uf->generator === false, '初始 generator=false');
check($uf->tryCatch === false, '初始 tryCatch=false');
check($uf->nullableType === false, '初始 nullableType=false');
check($uf->unionType === false, '初始 unionType=false');
check($uf->enum === false, '初始 enum=false');
check($uf->trait === false, '初始 trait=false');
check($uf->interface === false, '初始 interface=false');
check($uf->abstractClass === false, '初始 abstractClass=false');
check($uf->staticMethod === false, '初始 staticMethod=false');
check($uf->staticProperty === false, '初始 staticProperty=false');
check($uf->destructor === false, '初始 destructor=false');
check($uf->count() === 0, '初始 count()=0');

// ─────────────────────────────────────────────────────────────
// 测试 2 & 3: markClosure / markArrowFunction
// ─────────────────────────────────────────────────────────────
$uf->markClosure();
check($uf->closure === true, 'markClosure 后 closure=true');
check($uf->arrowFunction === false, 'markClosure 不影响 arrowFunction');

$uf->markArrowFunction();
check($uf->arrowFunction === true, 'markArrowFunction 后 arrowFunction=true');

// ─────────────────────────────────────────────────────────────
// 测试 4: 每个特性标记后状态正确（逐一验证 ≥10 个特性）
//   使用全新的实例以避免与上面累积
// ─────────────────────────────────────────────────────────────
$u = new UsedFeatures();

$u->markAttribute();
check($u->attribute === true, 'markAttribute 后 attribute=true');

$u->markMatch();
check($u->match === true, 'markMatch 后 match=true');

$u->markNamedArgs();
check($u->namedArgs === true, 'markNamedArgs 后 namedArgs=true');

$u->markSpread();
check($u->spread === true, 'markSpread 后 spread=true');

$u->markFirstClassCallable();
check($u->firstClassCallable === true, 'markFirstClassCallable 后 firstClassCallable=true');

$u->markPropertyHook();
check($u->propertyHook === true, 'markPropertyHook 后 propertyHook=true');

$u->markReadonlyClass();
check($u->readonlyClass === true, 'markReadonlyClass 后 readonlyClass=true');

$u->markPipeOperator();
check($u->pipeOperator === true, 'markPipeOperator 后 pipeOperator=true');

$u->markThrowExpression();
check($u->throwExpression === true, 'markThrowExpression 后 throwExpression=true');

$u->markOrBlock();
check($u->orBlock === true, 'markOrBlock 后 orBlock=true');

$u->markFlagDirective();
check($u->flagDirective === true, 'markFlagDirective 后 flagDirective=true');

$u->markImportDirective();
check($u->importDirective === true, 'markImportDirective 后 importDirective=true');

$u->markIncludeDirective();
check($u->includeDirective === true, 'markIncludeDirective 后 includeDirective=true');

$u->markCallbackDirective();
check($u->callbackDirective === true, 'markCallbackDirective 后 callbackDirective=true');

$u->markDebugDirective();
check($u->debugDirective === true, 'markDebugDirective 后 debugDirective=true');

$u->markConditionalCompilation();
check($u->conditionalCompilation === true, 'markConditionalCompilation 后 conditionalCompilation=true');

$u->markCStructDecl();
check($u->cStructDecl === true, 'markCStructDecl 后 cStructDecl=true');

$u->markCFunctionDecl();
check($u->cFunctionDecl === true, 'markCFunctionDecl 后 cFunctionDecl=true');

$u->markGenerator();
check($u->generator === true, 'markGenerator 后 generator=true');

$u->markTryCatch();
check($u->tryCatch === true, 'markTryCatch 后 tryCatch=true');

$u->markNullableType();
check($u->nullableType === true, 'markNullableType 后 nullableType=true');

$u->markUnionType();
check($u->unionType === true, 'markUnionType 后 unionType=true');

$u->markEnum();
check($u->enum === true, 'markEnum 后 enum=true');

$u->markTrait();
check($u->trait === true, 'markTrait 后 trait=true');

$u->markInterface();
check($u->interface === true, 'markInterface 后 interface=true');

$u->markAbstractClass();
check($u->abstractClass === true, 'markAbstractClass 后 abstractClass=true');

$u->markStaticMethod();
check($u->staticMethod === true, 'markStaticMethod 后 staticMethod=true');

$u->markStaticProperty();
check($u->staticProperty === true, 'markStaticProperty 后 staticProperty=true');

$u->markDestructor();
check($u->destructor === true, 'markDestructor 后 destructor=true');

// ─────────────────────────────────────────────────────────────
// 测试 4b: mark(string) 按名标记
// ─────────────────────────────────────────────────────────────
$u2 = new UsedFeatures();
$u2->mark('closure');
$u2->mark('match');
$u2->mark('enum');
$u2->mark('unknownFeature'); // 未知特性名应静默忽略
check($u2->closure === true, "mark('closure') 后 closure=true");
check($u2->match === true, "mark('match') 后 match=true");
check($u2->enum === true, "mark('enum') 后 enum=true");
check($u2->arrowFunction === false, "mark('unknownFeature') 不影响其它字段");
check($u2->count() === 3, "mark(string) 后 count()=3");

// ─────────────────────────────────────────────────────────────
// 测试 5/6/7: hasClosureOrArrow
// ─────────────────────────────────────────────────────────────
$onlyClosure = new UsedFeatures();
$onlyClosure->markClosure();
check($onlyClosure->hasClosureOrArrow() === true, 'hasClosureOrArrow: 只有 closure → true');

$onlyArrow = new UsedFeatures();
$onlyArrow->markArrowFunction();
check($onlyArrow->hasClosureOrArrow() === true, 'hasClosureOrArrow: 只有 arrowFunction → true');

$neither = new UsedFeatures();
check($neither->hasClosureOrArrow() === false, 'hasClosureOrArrow: 两者都 false → false');

$both = new UsedFeatures();
$both->markClosure();
$both->markArrowFunction();
check($both->hasClosureOrArrow() === true, 'hasClosureOrArrow: 两者都 true → true');

// ─────────────────────────────────────────────────────────────
// 测试 8: hasAnyDirective
// ─────────────────────────────────────────────────────────────
$noDir = new UsedFeatures();
check($noDir->hasAnyDirective() === false, 'hasAnyDirective: 无指令 → false');

$flagOnly = new UsedFeatures();
$flagOnly->markFlagDirective();
check($flagOnly->hasAnyDirective() === true, 'hasAnyDirective: flagDirective → true');

$ccOnly = new UsedFeatures();
$ccOnly->markConditionalCompilation();
check($ccOnly->hasAnyDirective() === true, 'hasAnyDirective: conditionalCompilation → true');

$multiDir = new UsedFeatures();
$multiDir->markImportDirective();
$multiDir->markIncludeDirective();
$multiDir->markCallbackDirective();
$multiDir->markDebugDirective();
check($multiDir->hasAnyDirective() === true, 'hasAnyDirective: import/include/callback/debug → true');

// 非指令特性不应触发 hasAnyDirective
$nonDir = new UsedFeatures();
$nonDir->markClosure();
$nonDir->markEnum();
$nonDir->markTryCatch();
check($nonDir->hasAnyDirective() === false, 'hasAnyDirective: 仅有非指令特性 → false');

// ─────────────────────────────────────────────────────────────
// 测试 9: hasAnyCInterop
// ─────────────────────────────────────────────────────────────
$noC = new UsedFeatures();
check($noC->hasAnyCInterop() === false, 'hasAnyCInterop: 无 C 互操作 → false');

$structOnly = new UsedFeatures();
$structOnly->markCStructDecl();
check($structOnly->hasAnyCInterop() === true, 'hasAnyCInterop: cStructDecl → true');

$funcOnly = new UsedFeatures();
$funcOnly->markCFunctionDecl();
check($funcOnly->hasAnyCInterop() === true, 'hasAnyCInterop: cFunctionDecl → true');

$nonC = new UsedFeatures();
$nonC->markClosure();
$nonC->markEnum();
check($nonC->hasAnyCInterop() === false, 'hasAnyCInterop: 仅有非 C 互操作特性 → false');

// ─────────────────────────────────────────────────────────────
// 测试 10: hasAnyOop
// ─────────────────────────────────────────────────────────────
$noOop = new UsedFeatures();
check($noOop->hasAnyOop() === false, 'hasAnyOop: 无 OOP → false');

$enumOnly = new UsedFeatures();
$enumOnly->markEnum();
check($enumOnly->hasAnyOop() === true, 'hasAnyOop: enum → true');

$traitOnly = new UsedFeatures();
$traitOnly->markTrait();
check($traitOnly->hasAnyOop() === true, 'hasAnyOop: trait → true');

$ifaceOnly = new UsedFeatures();
$ifaceOnly->markInterface();
check($ifaceOnly->hasAnyOop() === true, 'hasAnyOop: interface → true');

$absOnly = new UsedFeatures();
$absOnly->markAbstractClass();
check($absOnly->hasAnyOop() === true, 'hasAnyOop: abstractClass → true');

$nonOop = new UsedFeatures();
$nonOop->markClosure();
$nonOop->markMatch();
check($nonOop->hasAnyOop() === false, 'hasAnyOop: 仅有非 OOP 特性 → false');

// ─────────────────────────────────────────────────────────────
// 测试 10b: hasAnyAdvancedType
// ─────────────────────────────────────────────────────────────
$noAdv = new UsedFeatures();
check($noAdv->hasAnyAdvancedType() === false, 'hasAnyAdvancedType: 无 → false');

$nullOnly = new UsedFeatures();
$nullOnly->markNullableType();
check($nullOnly->hasAnyAdvancedType() === true, 'hasAnyAdvancedType: nullableType → true');

$unionOnly = new UsedFeatures();
$unionOnly->markUnionType();
check($unionOnly->hasAnyAdvancedType() === true, 'hasAnyAdvancedType: unionType → true');

// ─────────────────────────────────────────────────────────────
// 测试 11: count() 统计正确
// ─────────────────────────────────────────────────────────────
$cnt = new UsedFeatures();
check($cnt->count() === 0, 'count(): 初始 0');

$cnt->markClosure();
check($cnt->count() === 1, 'count(): 标 1 个 → 1');

$cnt->markMatch();
$cnt->markEnum();
check($cnt->count() === 3, 'count(): 标 3 个 → 3');

// 重复标记同一特性不应增加计数
$cnt->markClosure();
check($cnt->count() === 3, 'count(): 重复标记 closure 不增加 → 仍 3');

// 标记多个不同特性
$cnt->markTryCatch();
$cnt->markGenerator();
$cnt->markNullableType();
$cnt->markUnionType();
$cnt->markTrait();
$cnt->markInterface();
$cnt->markAbstractClass();
check($cnt->count() === 10, 'count(): 标 10 个 → 10');

// 全部标记
$all = new UsedFeatures();
$all->markClosure();
$all->markArrowFunction();
$all->markAttribute();
$all->markMatch();
$all->markNamedArgs();
$all->markSpread();
$all->markFirstClassCallable();
$all->markPropertyHook();
$all->markReadonlyClass();
$all->markPipeOperator();
$all->markThrowExpression();
$all->markOrBlock();
$all->markFlagDirective();
$all->markImportDirective();
$all->markIncludeDirective();
$all->markCallbackDirective();
$all->markDebugDirective();
$all->markConditionalCompilation();
$all->markCStructDecl();
$all->markCFunctionDecl();
$all->markGenerator();
$all->markTryCatch();
$all->markNullableType();
$all->markUnionType();
$all->markEnum();
$all->markTrait();
$all->markInterface();
$all->markAbstractClass();
$all->markStaticMethod();
$all->markStaticProperty();
$all->markDestructor();
check($all->count() === 31, 'count(): 全部 31 个特性 → 31');

// ─────────────────────────────────────────────────────────────
// 测试 12: reset() 重置所有
// ─────────────────────────────────────────────────────────────
$toReset = new UsedFeatures();
$toReset->markClosure();
$toReset->markMatch();
$toReset->markEnum();
$toReset->markTryCatch();
$toReset->markFlagDirective();
check($toReset->count() === 5, 'reset 前有 5 个特性');

$toReset->reset();
check($toReset->closure === false, 'reset 后 closure=false');
check($toReset->match === false, 'reset 后 match=false');
check($toReset->enum === false, 'reset 后 enum=false');
check($toReset->tryCatch === false, 'reset 后 tryCatch=false');
check($toReset->flagDirective === false, 'reset 后 flagDirective=false');
check($toReset->count() === 0, 'reset 后 count()=0');
check($toReset->hasClosureOrArrow() === false, 'reset 后 hasClosureOrArrow=false');
check($toReset->hasAnyDirective() === false, 'reset 后 hasAnyDirective=false');
check($toReset->hasAnyOop() === false, 'reset 后 hasAnyOop=false');

// reset 后重新标记仍然有效
$toReset->markArrowFunction();
check($toReset->arrowFunction === true, 'reset 后重新 markArrowFunction 有效');
check($toReset->count() === 1, 'reset 后重新标记 1 个 → count()=1');

// ─────────────────────────────────────────────────────────────
// 测试 13: markFromNodeKind 对几个常见节点类型正确标记
//   ClosureExpr → closure
//   MatchExpr → match
//   YieldExpr / YieldFromExpr → generator
//   TryStmtNode → tryCatch
//   ThrowStmtNode / ThrowExprNode → throwExpression
//   EnumNode → enum
//   AttributeDeclNode / AttributeUseNode → attribute
//   PipeExpr → pipeOperator
//   CallableConvertExpr → firstClassCallable
//   OrBlockExpr → orBlock
//   PropertyHook → propertyHook
// ─────────────────────────────────────────────────────────────
$nk = new UsedFeatures();

$nk->markFromNodeKind(NodeKind::ClosureExpr->value);
check($nk->closure === true, 'markFromNodeKind(ClosureExpr) → closure=true');

$nk->markFromNodeKind(NodeKind::MatchExpr->value);
check($nk->match === true, 'markFromNodeKind(MatchExpr) → match=true');

$nk->markFromNodeKind(NodeKind::YieldExpr->value);
check($nk->generator === true, 'markFromNodeKind(YieldExpr) → generator=true');

$nk->markFromNodeKind(NodeKind::YieldFromExpr->value);
check($nk->generator === true, 'markFromNodeKind(YieldFromExpr) → generator=true (仍 true)');

$nk->markFromNodeKind(NodeKind::TryStmtNode->value);
check($nk->tryCatch === true, 'markFromNodeKind(TryStmtNode) → tryCatch=true');

$nk->markFromNodeKind(NodeKind::ThrowStmtNode->value);
check($nk->throwExpression === true, 'markFromNodeKind(ThrowStmtNode) → throwExpression=true');

$nk->markFromNodeKind(NodeKind::ThrowExprNode->value);
check($nk->throwExpression === true, 'markFromNodeKind(ThrowExprNode) → throwExpression=true (仍 true)');

$nk->markFromNodeKind(NodeKind::EnumNode->value);
check($nk->enum === true, 'markFromNodeKind(EnumNode) → enum=true');

$nk->markFromNodeKind(NodeKind::AttributeDeclNode->value);
check($nk->attribute === true, 'markFromNodeKind(AttributeDeclNode) → attribute=true');

$nk->markFromNodeKind(NodeKind::AttributeUseNode->value);
check($nk->attribute === true, 'markFromNodeKind(AttributeUseNode) → attribute=true (仍 true)');

$nk->markFromNodeKind(NodeKind::PipeExpr->value);
check($nk->pipeOperator === true, 'markFromNodeKind(PipeExpr) → pipeOperator=true');

$nk->markFromNodeKind(NodeKind::CallableConvertExpr->value);
check($nk->firstClassCallable === true, 'markFromNodeKind(CallableConvertExpr) → firstClassCallable=true');

$nk->markFromNodeKind(NodeKind::OrBlockExpr->value);
check($nk->orBlock === true, 'markFromNodeKind(OrBlockExpr) → orBlock=true');

$nk->markFromNodeKind(NodeKind::PropertyHook->value);
check($nk->propertyHook === true, 'markFromNodeKind(PropertyHook) → propertyHook=true');

// 未映射的节点类型不应误标记任何特性
$nk2 = new UsedFeatures();
$nk2->markFromNodeKind(NodeKind::IntLiteralExpr->value);
$nk2->markFromNodeKind(NodeKind::BinaryExpr->value);
$nk2->markFromNodeKind(NodeKind::ProgramNode->value);
check($nk2->count() === 0, 'markFromNodeKind(未映射类型) 不标记任何特性');

// ─────────────────────────────────────────────────────────────
// 测试 14: toArray() 返回完整字段列表
// ─────────────────────────────────────────────────────────────
$empty = new UsedFeatures();
$arr = $empty->toArray();
check(is_array($arr), 'toArray() 返回数组');
check(count($arr) === 31, 'toArray() 包含 31 个字段');

// 所有值默认为 false
$allFalse = true;
foreach ($arr as $k => $v) {
    if ($v !== false) { $allFalse = false; break; }
}
check($allFalse, 'toArray(): 初始所有值为 false');

// 验证字段名集合完整
$expectedKeys = [
    'closure', 'arrowFunction', 'attribute', 'match', 'namedArgs', 'spread',
    'firstClassCallable', 'propertyHook', 'readonlyClass', 'pipeOperator',
    'throwExpression', 'orBlock', 'flagDirective', 'importDirective',
    'includeDirective', 'callbackDirective', 'debugDirective',
    'conditionalCompilation', 'cStructDecl', 'cFunctionDecl', 'generator',
    'tryCatch', 'nullableType', 'unionType', 'enum', 'trait', 'interface',
    'abstractClass', 'staticMethod', 'staticProperty', 'destructor',
];
$keysMatch = true;
foreach ($expectedKeys as $k) {
    if (!array_key_exists($k, $arr)) { $keysMatch = false; break; }
}
check($keysMatch, 'toArray(): 包含所有预期字段名');

// 标记后 toArray() 反映新状态
$marked = new UsedFeatures();
$marked->markClosure();
$marked->markEnum();
$marked->markTryCatch();
$arr2 = $marked->toArray();
check($arr2['closure'] === true, 'toArray(): closure=true 反映正确');
check($arr2['enum'] === true, 'toArray(): enum=true 反映正确');
check($arr2['tryCatch'] === true, 'toArray(): tryCatch=true 反映正确');
check($arr2['match'] === false, 'toArray(): match=false 反映正确');
check($arr2['arrowFunction'] === false, 'toArray(): arrowFunction=false 反映正确');

// toArray() 与 count() 一致性
$cntArr = 0;
foreach ($arr2 as $v) { if ($v) $cntArr++; }
check($cntArr === $marked->count(), 'toArray() true 数与 count() 一致');

// ─────────────────────────────────────────────────────────────
// 汇总
// ─────────────────────────────────────────────────────────────
echo "\n";
echo "====================================\n";
echo "UsedFeatures 单元测试结果\n";
echo "====================================\n";
echo "通过: {$pass}\n";
echo "失败: {$fail}\n";
echo "字段数: 31\n";
echo "特性 mark 方法数: 31\n";
echo "====================================\n";

if ($fail > 0) {
    echo "\n失败用例：\n";
    foreach ($failures as $f) {
        echo "  - {$f}\n";
    }
    exit(1);
}

echo "\n[OK] 全部测试通过\n";
