<?php

declare(strict_types=1);

// ============================================================
// MIR 中间表示层单元测试（Task 14）
//
// 运行方式：
//   cd c:\project\php\TinyPHP
//   php tools/MIR/mir_test.php
//
// 测试范围：
//   1.  Target 描述正确性（x86_64 / aarch64 各字段）
//   2.  ABI 计算：3 个整数参数（x86_64 全部走寄存器）
//   3.  ABI 计算：10 个整数参数（前 6 走寄存器，后 4 走栈）
//   4.  ABI 计算：浮点参数（x86_64 用 xmm0-xmm7）
//   5.  ABI 计算：混合参数（int + float + int，寄存器分配独立）
//   6.  ABI 计算：aarch64 平台参数分配
//   7.  ABI 计算：返回值 ABI（int / float / void）
//   8.  SSA → MIR 降低：基本块结构保留
//   9.  SSA → MIR 降低：OpCode 映射正确（ADD / CMP / RET 等）
//   10. SSA → MIR 降低：函数级降低（多块、控制流）
//   11. SSA → MIR 降低：参数 MOV 指令插入
//   12. MIR dump 输出（dumpMIRFunction）
//
// 断言总数：>= 30
// ============================================================

require_once __DIR__ . '/../../src/MIR/MIR.php';
require_once __DIR__ . '/../../src/MIR/MIRBuilder.php';
require_once __DIR__ . '/../../src/SSA/SSA.php';

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

/** 在 MIR 函数的所有块中查找第一条匹配 op 的指令 */
function findMIRInst(MIRFunction $func, MIROpCode $op): ?MIRInstruction
{
    foreach ($func->blocks as $block) {
        foreach ($block->instructions as $inst) {
            if ($inst->op === $op) {
                return $inst;
            }
        }
    }
    return null;
}

/** 统计 MIR 函数中匹配 op 的指令数 */
function countMIRInst(MIRFunction $func, MIROpCode $op): int
{
    $n = 0;
    foreach ($func->blocks as $block) {
        foreach ($block->instructions as $inst) {
            if ($inst->op === $op) {
                $n++;
            }
        }
    }
    return $n;
}

// ═══════════════════════════════════════════════════════════════
// 测试 1: Target 描述正确性 — x86_64
// ═══════════════════════════════════════════════════════════════
$x86 = TargetDesc::x86_64();
check($x86->arch === Arch::X86_64, 'x86_64 arch === X86_64');
check($x86->pointerSize === 8, 'x86_64 pointerSize = 8');
check($x86->intArgRegs === 6, 'x86_64 intArgRegs = 6');
check($x86->floatArgRegs === 8, 'x86_64 floatArgRegs = 8');
check($x86->stackAlign === 16, 'x86_64 stackAlign = 16');
check($x86->intReturnRegs === 2, 'x86_64 intReturnRegs = 2');
check(count($x86->calleeSavedRegs) === 6, 'x86_64 calleeSavedRegs count = 6');
check($x86->arch->label() === 'x86_64', 'x86_64 arch label = "x86_64"');

// ═══════════════════════════════════════════════════════════════
// 测试 2: Target 描述正确性 — aarch64
// ═══════════════════════════════════════════════════════════════
$arm = TargetDesc::aarch64();
check($arm->arch === Arch::AARCH64, 'aarch64 arch === AARCH64');
check($arm->pointerSize === 8, 'aarch64 pointerSize = 8');
check($arm->intArgRegs === 8, 'aarch64 intArgRegs = 8');
check($arm->floatArgRegs === 8, 'aarch64 floatArgRegs = 8');
check($arm->stackAlign === 16, 'aarch64 stackAlign = 16');
check($arm->intReturnRegs === 4, 'aarch64 intReturnRegs = 4');
check(count($arm->calleeSavedRegs) === 11, 'aarch64 calleeSavedRegs count = 11');
check($arm->arch->label() === 'aarch64', 'aarch64 arch label = "aarch64"');

// ARM64 是 AARCH64 的别名
check(Arch::ARM64 === Arch::AARCH64, 'ARM64 is alias for AARCH64');

// ═══════════════════════════════════════════════════════════════
// 测试 3: ABI 计算 — 3 个整数参数（x86_64 全部走寄存器）
//   function f(int, int, int) -> int
//   预期：3 个参数全部 REGISTER，regIndex = 0, 1, 2，regClass = 'int'
// ═══════════════════════════════════════════════════════════════
$builder = new MIRBuilder(TargetDesc::x86_64());
$abi3 = $builder->build_function_abi(
    SSAType::int(),
    [SSAType::int(), SSAType::int(), SSAType::int()],
    $x86,
);
check(count($abi3['params']) === 3, '3 int params: count = 3');
check($abi3['params'][0]->kind === ABIArgKind::REGISTER, '3 int params: param 0 REGISTER');
check($abi3['params'][1]->kind === ABIArgKind::REGISTER, '3 int params: param 1 REGISTER');
check($abi3['params'][2]->kind === ABIArgKind::REGISTER, '3 int params: param 2 REGISTER');
check($abi3['params'][0]->registerIndex === 0, '3 int params: param 0 regIndex = 0 (rdi)');
check($abi3['params'][1]->registerIndex === 1, '3 int params: param 1 regIndex = 1 (rsi)');
check($abi3['params'][2]->registerIndex === 2, '3 int params: param 2 regIndex = 2 (rdx)');
check($abi3['params'][0]->regClass === 'int', '3 int params: param 0 regClass = int');

// ═══════════════════════════════════════════════════════════════
// 测试 4: ABI 计算 — 10 个整数参数（x86_64 前 6 走寄存器，后 4 走栈）
// ═══════════════════════════════════════════════════════════════
$tenInts = array_fill(0, 10, SSAType::int());
$abi10 = $builder->build_function_abi(SSAType::int(), $tenInts, $x86);
check(count($abi10['params']) === 10, '10 int params: count = 10');
// 前 6 个走寄存器
for ($i = 0; $i < 6; $i++) {
    check(
        $abi10['params'][$i]->kind === ABIArgKind::REGISTER,
        "10 int params: param {$i} REGISTER (regIndex={$abi10['params'][$i]->registerIndex})",
    );
}
// 后 4 个走栈，stackOffset 递增 0, 8, 16, 24
for ($i = 6; $i < 10; $i++) {
    check(
        $abi10['params'][$i]->kind === ABIArgKind::STACK,
        "10 int params: param {$i} STACK",
    );
}
check($abi10['params'][6]->stackOffset === 0, '10 int params: param 6 stackOffset = 0');
check($abi10['params'][7]->stackOffset === 8, '10 int params: param 7 stackOffset = 8');
check($abi10['params'][8]->stackOffset === 16, '10 int params: param 8 stackOffset = 16');
check($abi10['params'][9]->stackOffset === 24, '10 int params: param 9 stackOffset = 24');

// ═══════════════════════════════════════════════════════════════
// 测试 5: ABI 计算 — 浮点参数（x86_64 用 xmm0-xmm7）
//   function f(float, float, float) -> float
//   预期：3 个参数全部 REGISTER，regIndex = 0, 1, 2，regClass = 'float'
// ═══════════════════════════════════════════════════════════════
$abiF = $builder->build_function_abi(
    SSAType::float(),
    [SSAType::float(), SSAType::float(), SSAType::float()],
    $x86,
);
check($abiF['params'][0]->kind === ABIArgKind::REGISTER, '3 float params: param 0 REGISTER');
check($abiF['params'][0]->registerIndex === 0, '3 float params: param 0 regIndex = 0 (xmm0)');
check($abiF['params'][1]->registerIndex === 1, '3 float params: param 1 regIndex = 1 (xmm1)');
check($abiF['params'][2]->registerIndex === 2, '3 float params: param 2 regIndex = 2 (xmm2)');
check($abiF['params'][0]->regClass === 'float', '3 float params: param 0 regClass = float');

// 8 个浮点参数全部走寄存器
$eightFloats = array_fill(0, 8, SSAType::float());
$abi8F = $builder->build_function_abi(SSAType::float(), $eightFloats, $x86);
$allReg = true;
for ($i = 0; $i < 8; $i++) {
    if ($abi8F['params'][$i]->kind !== ABIArgKind::REGISTER) {
        $allReg = false;
        break;
    }
}
check($allReg, '8 float params: all REGISTER on x86_64');

// 第 9 个浮点参数走栈
$nineFloats = array_fill(0, 9, SSAType::float());
$abi9F = $builder->build_function_abi(SSAType::float(), $nineFloats, $x86);
check($abi9F['params'][8]->kind === ABIArgKind::STACK, '9 float params: param 8 STACK (xmm exhausted)');

// ═══════════════════════════════════════════════════════════════
// 测试 6: ABI 计算 — 混合参数（int + float + int，寄存器分配独立）
//   function f(int, float, int) -> int
//   预期：int[0] regIndex=0（rdi），float[1] regIndex=0（xmm0），int[2] regIndex=1（rsi）
//   整数与浮点寄存器独立计数
// ═══════════════════════════════════════════════════════════════
$abiMix = $builder->build_function_abi(
    SSAType::int(),
    [SSAType::int(), SSAType::float(), SSAType::int()],
    $x86,
);
check($abiMix['params'][0]->kind === ABIArgKind::REGISTER && $abiMix['params'][0]->registerIndex === 0 && $abiMix['params'][0]->regClass === 'int', 'mixed: param 0 = reg0/int (rdi)');
check($abiMix['params'][1]->kind === ABIArgKind::REGISTER && $abiMix['params'][1]->registerIndex === 0 && $abiMix['params'][1]->regClass === 'float', 'mixed: param 1 = reg0/float (xmm0)');
check($abiMix['params'][2]->kind === ABIArgKind::REGISTER && $abiMix['params'][2]->registerIndex === 1 && $abiMix['params'][2]->regClass === 'int', 'mixed: param 2 = reg1/int (rsi)');

// ═══════════════════════════════════════════════════════════════
// 测试 7: ABI 计算 — aarch64 平台参数分配
//   8 个整数参数全部走寄存器（x0-x7）
//   10 个整数参数：前 8 走寄存器，后 2 走栈
// ═══════════════════════════════════════════════════════════════
$armBuilder = new MIRBuilder(TargetDesc::aarch64());
$eightIntsArm = array_fill(0, 8, SSAType::int());
$abiArm8 = $armBuilder->build_function_abi(SSAType::int(), $eightIntsArm, $arm);
$allRegArm = true;
for ($i = 0; $i < 8; $i++) {
    if ($abiArm8['params'][$i]->kind !== ABIArgKind::REGISTER || $abiArm8['params'][$i]->registerIndex !== $i) {
        $allRegArm = false;
        break;
    }
}
check($allRegArm, 'aarch64: 8 int params all REGISTER (x0-x7)');

$tenIntsArm = array_fill(0, 10, SSAType::int());
$abiArm10 = $armBuilder->build_function_abi(SSAType::int(), $tenIntsArm, $arm);
check($abiArm10['params'][7]->kind === ABIArgKind::REGISTER, 'aarch64: 10 int params, param 7 still REGISTER');
check($abiArm10['params'][8]->kind === ABIArgKind::STACK, 'aarch64: 10 int params, param 8 STACK');
check($abiArm10['params'][9]->kind === ABIArgKind::STACK, 'aarch64: 10 int params, param 9 STACK');
check($abiArm10['params'][8]->stackOffset === 0, 'aarch64: 10 int params, param 8 stackOffset = 0');
check($abiArm10['params'][9]->stackOffset === 8, 'aarch64: 10 int params, param 9 stackOffset = 8');

// ═══════════════════════════════════════════════════════════════
// 测试 8: ABI 计算 — 返回值 ABI
//   int 返回 → REGISTER, regIndex=0, regClass='int' (rax/x0)
//   float 返回 → REGISTER, regIndex=0, regClass='float' (xmm0/v0)
//   void 返回 → size=0
// ═══════════════════════════════════════════════════════════════
$abiRetInt = $builder->build_function_abi(SSAType::int(), [], $x86);
check($abiRetInt['ret']->kind === ABIArgKind::REGISTER, 'ret int: REGISTER');
check($abiRetInt['ret']->registerIndex === 0, 'ret int: regIndex = 0 (rax)');
check($abiRetInt['ret']->regClass === 'int', 'ret int: regClass = int');

$abiRetFloat = $builder->build_function_abi(SSAType::float(), [], $x86);
check($abiRetFloat['ret']->kind === ABIArgKind::REGISTER, 'ret float: REGISTER');
check($abiRetFloat['ret']->registerIndex === 0, 'ret float: regIndex = 0 (xmm0)');
check($abiRetFloat['ret']->regClass === 'float', 'ret float: regClass = float');

$abiRetVoid = $builder->build_function_abi(SSAType::void(), [], $x86);
check($abiRetVoid['ret']->size === 0, 'ret void: size = 0');

// ═══════════════════════════════════════════════════════════════
// 测试 9: SSA → MIR 降低 — 基本块结构保留
//   构建一个简单的 SSA 函数（单块 + RET），验证 MIR 块结构
// ═══════════════════════════════════════════════════════════════
$ssaFunc = new SSAFunction('simple', SSAType::int(), [SSAType::int(), SSAType::int()]);
$entryId = $ssaFunc->newBlock('entry');
$ssaFunc->entryBlockId = $entryId;

// 参数 $a (id=0), $b (id=1), ADD 结果 %2
$ssaFunc->newValue(SSAType::int(), 'a');
$ssaFunc->newValue(SSAType::int(), 'b');
$ssaFunc->newValue(SSAType::int(), 'add_result');

// %2 = ADD %0, %1
$ssaFunc->appendInst($entryId, new SSAInstruction(OpCode::ADD, 2, [0, 1]));
// RET %2
$ssaFunc->appendInst($entryId, new SSAInstruction(OpCode::RET, null, [2]));

$mirFunc = $builder->lowerFunction($ssaFunc);
check($mirFunc->name === 'simple', 'lower: function name = simple');
check($mirFunc->returnType->equals(SSAType::int()), 'lower: returnType = int');
check(count($mirFunc->blocks) === 1, 'lower: 1 block preserved');
check($mirFunc->entryBlockId === 0, 'lower: entryBlockId = 0');
check($mirFunc->blocks[0]->isEntry === true, 'lower: entry block isEntry = true');
check($mirFunc->blocks[0]->label === 'entry', 'lower: entry block label = entry');

// ═══════════════════════════════════════════════════════════════
// 测试 10: SSA → MIR 降低 — OpCode 映射正确
//   SSA ADD → MIR ADD
//   SSA RET → MIR RET
//   SSA COPY → MIR MOV
//   SSA JMP → MIR BR
//   SSA BR → MIR CBR
// ═══════════════════════════════════════════════════════════════
$addInst = findMIRInst($mirFunc, MIROpCode::ADD);
check($addInst !== null, 'lower: SSA ADD → MIR ADD');
check($addInst !== null && count($addInst->operands) === 2, 'lower: ADD has 2 operands');
check($addInst !== null && $addInst->operands[0]->name === '%v0', 'lower: ADD operand[0] = %v0');
check($addInst !== null && $addInst->operands[1]->name === '%v1', 'lower: ADD operand[1] = %v1');
check($addInst !== null && $addInst->dst !== null && $addInst->dst->name === '%v2', 'lower: ADD dst = %v2');

$retInst = findMIRInst($mirFunc, MIROpCode::RET);
check($retInst !== null, 'lower: SSA RET → MIR RET');
check($retInst !== null && count($retInst->operands) === 1, 'lower: RET has 1 operand');
check($retInst !== null && $retInst->operands[0]->name === '%v2', 'lower: RET operand = %v2');

// 验证 SSA OpCode 到 MIROpCode 的映射完备性（所有 SSA OpCode 都能映射）
$allMapped = true;
foreach (OpCode::cases() as $ssaOp) {
    $mir = (new MIRBuilder(TargetDesc::x86_64()))->lowerInst(
        new SSAInstruction($ssaOp, 0, [0, 1]),
        new MIRBasicBlock(0),
    );
    // 只要不抛异常即视为映射成功（dst/operands 可能因 valueMap 为空而回退占位）
    if ($mir === null) {
        $allMapped = false;
        break;
    }
}
check($allMapped, 'lower: all SSA OpCode mapped to MIROpCode');

// ═══════════════════════════════════════════════════════════════
// 测试 11: SSA → MIR 降低 — 函数级降低（多块、控制流）
//   构建含 if/else 的 SSA 函数，验证多块结构与跳转映射
//
//   function f(bool $c, int $a, int $b): int {
//     if ($c) { return $a; } else { return $b; }
//   }
//   块结构: entry --CBR--> then / else --> merge(RET)
// ═══════════════════════════════════════════════════════════════
$ssaFunc2 = new SSAFunction('f', SSAType::int(), [SSAType::bool(), SSAType::int(), SSAType::int()]);
$eId = $ssaFunc2->newBlock('entry');
$tId = $ssaFunc2->newBlock('if.then');
$elId = $ssaFunc2->newBlock('if.else');
$mId = $ssaFunc2->newBlock('if.merge');
$ssaFunc2->entryBlockId = $eId;

// 参数 $c(0), $a(1), $b(2)
$ssaFunc2->newValue(SSAType::bool(), 'c');
$ssaFunc2->newValue(SSAType::int(), 'a');
$ssaFunc2->newValue(SSAType::int(), 'b');

// entry: BR $c, then, else
$ssaFunc2->appendInst($eId, new SSAInstruction(OpCode::BR, null, [0], ['then_block' => $tId, 'else_block' => $elId]));
$ssaFunc2->addEdge($eId, $tId);
$ssaFunc2->addEdge($eId, $elId);

// then: JMP merge
$ssaFunc2->appendInst($tId, new SSAInstruction(OpCode::JMP, null, [], ['target_block' => $mId]));
$ssaFunc2->addEdge($tId, $mId);

// else: JMP merge
$ssaFunc2->appendInst($elId, new SSAInstruction(OpCode::JMP, null, [], ['target_block' => $mId]));
$ssaFunc2->addEdge($elId, $mId);

// merge: RET $a
$ssaFunc2->appendInst($mId, new SSAInstruction(OpCode::RET, null, [1]));

$mirFunc2 = $builder->lowerFunction($ssaFunc2);
check(count($mirFunc2->blocks) === 4, 'lower multi-block: 4 blocks preserved');
check($mirFunc2->blocks[$eId]->isEntry === true, 'lower multi-block: entry isEntry = true');

// SSA BR(条件) → MIR CBR
$cbrInst = findMIRInst($mirFunc2, MIROpCode::CBR);
check($cbrInst !== null, 'lower multi-block: SSA BR → MIR CBR');
check($cbrInst !== null && $cbrInst->operands[0]->name === '%v0', 'lower multi-block: CBR cond = %v0 ($c)');
check($cbrInst !== null && $cbrInst->extra['then_block'] === $tId, 'lower multi-block: CBR then_block preserved');
check($cbrInst !== null && $cbrInst->extra['else_block'] === $elId, 'lower multi-block: CBR else_block preserved');

// SSA JMP(无条件) → MIR BR
$brInsts = [];
foreach ($mirFunc2->blocks as $b) {
    foreach ($b->instructions as $inst) {
        if ($inst->op === MIROpCode::BR) {
            $brInsts[] = $inst;
        }
    }
}
check(count($brInsts) === 2, 'lower multi-block: 2 MIR BR (from SSA JMP) in then/else');

// 前驱/后继保留
check(in_array($tId, $mirFunc2->blocks[$eId]->successors), 'lower multi-block: entry successors include then');
check(in_array($elId, $mirFunc2->blocks[$eId]->successors), 'lower multi-block: entry successors include else');
check(in_array($eId, $mirFunc2->blocks[$tId]->predecessors), 'lower multi-block: then preds include entry');
check(in_array($tId, $mirFunc2->blocks[$mId]->predecessors), 'lower multi-block: merge preds include then');
check(in_array($elId, $mirFunc2->blocks[$mId]->predecessors), 'lower multi-block: merge preds include else');

// ═══════════════════════════════════════════════════════════════
// 测试 12: SSA → MIR 降低 — 参数 MOV 指令插入
//   入口块开头应为每个参数插入 MOV 指令（含 ABI 信息）
// ═══════════════════════════════════════════════════════════════
$entryBlock = $mirFunc2->blocks[$eId];
$paramMovCount = 0;
foreach ($entryBlock->instructions as $inst) {
    if ($inst->op === MIROpCode::MOV && ($inst->extra['isParam'] ?? false)) {
        $paramMovCount++;
    }
}
check($paramMovCount === 3, 'lower: 3 param MOV instructions inserted at entry');

// 验证第一个参数 MOV 的 ABI 信息
$firstParamMov = null;
foreach ($entryBlock->instructions as $inst) {
    if ($inst->op === MIROpCode::MOV && ($inst->extra['isParam'] ?? false)) {
        $firstParamMov = $inst;
        break;
    }
}
check($firstParamMov !== null && $firstParamMov->extra['paramIndex'] === 0, 'lower: first param MOV paramIndex = 0');
check($firstParamMov !== null && $firstParamMov->extra['abi'] instanceof ABIArg, 'lower: param MOV has ABIArg');
check($firstParamMov !== null && $firstParamMov->extra['abi']->kind === ABIArgKind::REGISTER, 'lower: first param ($c bool) → REGISTER');
check($firstParamMov !== null && $firstParamMov->dst !== null && $firstParamMov->dst->name === '%v0', 'lower: first param MOV dst = %v0');

// 参数 MOV 应在入口块最前面（在 BR 指令之前）
check($entryBlock->instructions[0]->op === MIROpCode::MOV, 'lower: param MOV is first instruction in entry');
check($entryBlock->instructions[count($entryBlock->instructions) - 1]->op === MIROpCode::CBR, 'lower: CBR is last instruction in entry');

// ═══════════════════════════════════════════════════════════════
// 测试 13: SSA → MIR 降低 — 参数 ABI 信息正确性
//   f(bool $c, int $a, int $b) on x86_64
//   $c(0) → reg0/int (rdi), $a(1) → reg1/int (rsi), $b(2) → reg2/int (rdx)
// ═══════════════════════════════════════════════════════════════
check(count($mirFunc2->paramAbi) === 3, 'lower: 3 paramAbi entries');
check($mirFunc2->paramAbi[0]->kind === ABIArgKind::REGISTER && $mirFunc2->paramAbi[0]->registerIndex === 0, 'lower: param 0 ($c) → reg0');
check($mirFunc2->paramAbi[1]->kind === ABIArgKind::REGISTER && $mirFunc2->paramAbi[1]->registerIndex === 1, 'lower: param 1 ($a) → reg1');
check($mirFunc2->paramAbi[2]->kind === ABIArgKind::REGISTER && $mirFunc2->paramAbi[2]->registerIndex === 2, 'lower: param 2 ($b) → reg2');
check($mirFunc2->returnAbi !== null && $mirFunc2->returnAbi->registerIndex === 0, 'lower: returnAbi regIndex = 0 (rax)');

// ═══════════════════════════════════════════════════════════════
// 测试 14: SSA → MIR 降低 — 栈帧大小计算
//   栈帧 = calleeSavedSize + outgoingArgs + locals，按 16 对齐
//   x86_64: 6 calleeSaved × 8 = 48，无调用无 alloca → 48 对齐 16 = 48
// ═══════════════════════════════════════════════════════════════
check($mirFunc->stackFrameSize > 0, 'computeStackFrame: size > 0');
check($mirFunc->stackFrameSize % 16 === 0, 'computeStackFrame: size aligned to 16');
check($mirFunc->stackFrameSize === 48, 'computeStackFrame: x86_64 no-call no-alloca = 48 (6 calleeSaved × 8)');

// aarch64: 11 calleeSaved × 8 = 88，对齐 16 = 96
$mirFuncArm = $armBuilder->lowerFunction($ssaFunc);
check($mirFuncArm->stackFrameSize === 96, 'computeStackFrame: aarch64 no-call no-alloca = 96 (11 calleeSaved × 8, aligned)');

// ═══════════════════════════════════════════════════════════════
// 测试 15: SSA → MIR 降低 — lowerModule（多函数模块）
// ═══════════════════════════════════════════════════════════════
$ssaMod = new SSAModule();
$fid1 = $ssaMod->newFunction('f1', [SSAType::int()], SSAType::int());
$fid2 = $ssaMod->newFunction('f2', [SSAType::int(), SSAType::int()], SSAType::int());

// 为 f1 创建入口块与 RET
$ssaMod->functions[$fid1]->newValue(SSAType::int(), 'a');
$bid1 = $ssaMod->functions[$fid1]->newBlock('entry');
$ssaMod->functions[$fid1]->entryBlockId = $bid1;
$ssaMod->functions[$fid1]->appendInst($bid1, new SSAInstruction(OpCode::RET, null, [0]));

// 为 f2 创建入口块与 ADD + RET
$ssaMod->functions[$fid2]->newValue(SSAType::int(), 'a');
$ssaMod->functions[$fid2]->newValue(SSAType::int(), 'b');
$ssaMod->functions[$fid2]->newValue(SSAType::int(), 'add_result');
$bid2 = $ssaMod->functions[$fid2]->newBlock('entry');
$ssaMod->functions[$fid2]->entryBlockId = $bid2;
$ssaMod->functions[$fid2]->appendInst($bid2, new SSAInstruction(OpCode::ADD, 2, [0, 1]));
$ssaMod->functions[$fid2]->appendInst($bid2, new SSAInstruction(OpCode::RET, null, [2]));

$mirMod = $builder->lowerModule($ssaMod);
check(count($mirMod->functions) === 2, 'lowerModule: 2 functions lowered');
check($mirMod->functions[0]->name === 'f1', 'lowerModule: function 0 name = f1');
check($mirMod->functions[1]->name === 'f2', 'lowerModule: function 1 name = f2');
check($mirMod->target->arch === Arch::X86_64, 'lowerModule: target = x86_64');

// ═══════════════════════════════════════════════════════════════
// 测试 16: MIR dump 输出（dumpMIRFunction）
// ═══════════════════════════════════════════════════════════════
$dump = dumpMIRFunction($mirFunc);
check(str_contains($dump, 'function simple(int, int) -> int'), 'dump: function signature');
check(str_contains($dump, '[target=x86_64'), 'dump: target info');
check(str_contains($dump, 'stack='), 'dump: stack size');
check(str_contains($dump, 'block_0 (entry) [entry]'), 'dump: entry block');
check(str_contains($dump, 'MOV abi=reg0  ; param 0'), 'dump: param 0 MOV with ABI');
check(str_contains($dump, 'MOV abi=reg1  ; param 1'), 'dump: param 1 MOV with ABI');
check(str_contains($dump, 'ADD %v0, %v1'), 'dump: ADD instruction');
check(str_contains($dump, 'RET %v2'), 'dump: RET instruction');

// dump 多块函数
$dump2 = dumpMIRFunction($mirFunc2);
check(str_contains($dump2, 'function f(bool, int, int) -> int'), 'dump multi: function signature');
check(str_contains($dump2, 'CBR %v0'), 'dump multi: CBR instruction');
check(str_contains($dump2, 'BR block_'), 'dump multi: BR (unconditional) instruction');
check(str_contains($dump2, 'preds=[0]'), 'dump multi: predecessors shown');

// ═══════════════════════════════════════════════════════════════
// 测试 17: MIROpCode 枚举完备性
// ═══════════════════════════════════════════════════════════════
check(MIROpCode::RET->isTerminator(), 'MIROpCode: RET is terminator');
check(MIROpCode::BR->isTerminator(), 'MIROpCode: BR is terminator');
check(MIROpCode::CBR->isTerminator(), 'MIROpCode: CBR is terminator');
check(!MIROpCode::ADD->isTerminator(), 'MIROpCode: ADD is not terminator');
check(MIROpCode::ADD->hasResult(), 'MIROpCode: ADD has result');
check(!MIROpCode::RET->hasResult(), 'MIROpCode: RET has no result');
check(!MIROpCode::STORE->hasResult(), 'MIROpCode: STORE has no result');
check(MIROpCode::PHI->hasResult(), 'MIROpCode: PHI has result');

// ═══════════════════════════════════════════════════════════════
// 测试 18: ABI 计算 — 指针参数
//   function f(void*, void*) -> void*
//   指针参数走整数寄存器（regClass='int'）
// ═══════════════════════════════════════════════════════════════
$ptrType = SSAType::ptr(SSAType::void());
$abiPtr = $builder->build_function_abi(
    $ptrType,
    [$ptrType, $ptrType],
    $x86,
);
check($abiPtr['params'][0]->kind === ABIArgKind::REGISTER, 'ptr params: param 0 REGISTER');
check($abiPtr['params'][0]->regClass === 'int', 'ptr params: param 0 regClass = int');
check($abiPtr['params'][0]->size === 8, 'ptr params: param 0 size = 8 (pointerSize)');
check($abiPtr['ret']->kind === ABIArgKind::REGISTER, 'ptr ret: REGISTER');
check($abiPtr['ret']->regClass === 'int', 'ptr ret: regClass = int');

// ═══════════════════════════════════════════════════════════════
// 测试 19: ABI 计算 — bool 参数（小类型）
//   bool 走整数寄存器，size=1
// ═══════════════════════════════════════════════════════════════
$abiBool = $builder->build_function_abi(
    SSAType::bool(),
    [SSAType::bool(), SSAType::bool()],
    $x86,
);
check($abiBool['params'][0]->kind === ABIArgKind::REGISTER, 'bool params: param 0 REGISTER');
check($abiBool['params'][0]->regClass === 'int', 'bool params: param 0 regClass = int');
check($abiBool['params'][0]->size === 1, 'bool params: param 0 size = 1');

// ═══════════════════════════════════════════════════════════════
// 测试 20: MIRValue 标志位
// ═══════════════════════════════════════════════════════════════
$v = new MIRValue(SSAType::int(), '%v0');
check($v->isRegister === false, 'MIRValue: default isRegister = false');
check($v->isStackSlot === false, 'MIRValue: default isStackSlot = false');
check($v->isConst === false, 'MIRValue: default isConst = false');

$vReg = new MIRValue(SSAType::int(), 'r0', isRegister: true);
check($vReg->isRegister === true, 'MIRValue: isRegister = true when set');

// ═══════════════════════════════════════════════════════════════
// 测试 21: ABIArg label() 文本输出
// ═══════════════════════════════════════════════════════════════
$regArg = new ABIArg(ABIArgKind::REGISTER, 0, null, 8, 8, false, 'int');
check($regArg->label() === 'reg0', 'ABIArg label: reg0');

$stackArg = new ABIArg(ABIArgKind::STACK, null, 16, 8, 8, false, 'int');
check($stackArg->label() === 'stack16', 'ABIArg label: stack16');

$indirectArg = new ABIArg(ABIArgKind::INDIRECT, null, null, 8, 8, true, 'int');
check($indirectArg->label() === 'indirect', 'ABIArg label: indirect');

// ═══════════════════════════════════════════════════════════════
// 测试 22: PHI 节点降低（保留到 MIR）
// ═══════════════════════════════════════════════════════════════
$ssaFuncPhi = new SSAFunction('phi_test', SSAType::int(), [SSAType::int()]);
$eIdP = $ssaFuncPhi->newBlock('entry');
$tIdP = $ssaFuncPhi->newBlock('then');
$mIdP = $ssaFuncPhi->newBlock('merge');
$ssaFuncPhi->entryBlockId = $eIdP;

$ssaFuncPhi->newValue(SSAType::int(), 'a'); // id=0 (param)
$ssaFuncPhi->newValue(SSAType::int(), 'const1'); // id=1 (CONST_INT 结果)
$ssaFuncPhi->newValue(SSAType::int(), 'phi'); // id=2 (PHI 结果)

// entry: BR %0, then, merge
$ssaFuncPhi->appendInst($eIdP, new SSAInstruction(OpCode::BR, null, [0], ['then_block' => $tIdP, 'else_block' => $mIdP]));
$ssaFuncPhi->addEdge($eIdP, $tIdP);
$ssaFuncPhi->addEdge($eIdP, $mIdP);

// then: %1 = CONST_INT 1; JMP merge
$ssaFuncPhi->appendInst($tIdP, new SSAInstruction(OpCode::CONST_INT, 1, [], ['value' => 1]));
$ssaFuncPhi->appendInst($tIdP, new SSAInstruction(OpCode::JMP, null, [], ['target_block' => $mIdP]));
$ssaFuncPhi->addEdge($tIdP, $mIdP);

// merge: %2 = PHI [%0 from entry, %1 from then]; RET %2
$ssaFuncPhi->appendInst($mIdP, new SSAInstruction(OpCode::PHI, 2, [0, 1], ['blocks' => [$eIdP, $tIdP]]));
$ssaFuncPhi->appendInst($mIdP, new SSAInstruction(OpCode::RET, null, [2]));

$mirFuncPhi = $builder->lowerFunction($ssaFuncPhi);
$phiInst = findMIRInst($mirFuncPhi, MIROpCode::PHI);
check($phiInst !== null, 'lower PHI: SSA PHI → MIR PHI preserved');
check($phiInst !== null && count($phiInst->operands) === 2, 'lower PHI: 2 incoming values');
check($phiInst !== null && $phiInst->operands[0]->name === '%v0', 'lower PHI: incoming[0] = %v0');
check($phiInst !== null && $phiInst->operands[1]->name === '%v1', 'lower PHI: incoming[1] = %v1');
check($phiInst !== null && count($phiInst->extra['blocks']) === 2, 'lower PHI: 2 source blocks');
check($phiInst !== null && $phiInst->dst !== null && $phiInst->dst->name === '%v2', 'lower PHI: dst = %v2');

// CONST_INT 也应正确降低
$constInst = findMIRInst($mirFuncPhi, MIROpCode::CONST_INT);
check($constInst !== null, 'lower PHI: CONST_INT mapped');
check($constInst !== null && $constInst->extra['value'] === 1, 'lower PHI: CONST_INT value = 1');

// ═══════════════════════════════════════════════════════════════
// 测试 23: dumpMIRFunction 含 PHI 输出
// ═══════════════════════════════════════════════════════════════
$dumpPhi = dumpMIRFunction($mirFuncPhi);
check(str_contains($dumpPhi, 'PHI'), 'dump PHI: contains PHI');
check(str_contains($dumpPhi, '[%v0 from block_'), 'dump PHI: contains incoming value with block');
check(str_contains($dumpPhi, 'CONST_INT 1'), 'dump PHI: contains CONST_INT 1');

// ═══════════════════════════════════════════════════════════════
// 输出测试结果
// ═══════════════════════════════════════════════════════════════
echo "\n═══════════════════════════════════════════════════\n";
echo "MIR 测试结果：通过 {$pass}，失败 {$fail}\n";
if ($fail > 0) {
    echo "失败用例：\n";
    foreach ($failures as $f) {
        echo "  - {$f}\n";
    }
}
echo "═══════════════════════════════════════════════════\n";

if ($fail > 0) {
    exit(1);
}
