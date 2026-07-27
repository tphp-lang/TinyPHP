<?php

declare(strict_types=1);

// ============================================================
// MIRBuilder — 从 SSA 降低到 MIR（Task 14）
//
// 输入：SSAModule / SSAFunction（已构建并可选优化过的 SSA）
// 输出：MIRModule / MIRFunction（含目标 ABI 元数据）
//
// 降低流程：
//   1. lowerModule   : 对每个 SSAFunction 调用 lowerFunction
//   2. lowerFunction : 创建 MIRFunction，计算参数 ABI，逐块降低
//   3. lowerBlock    : 对块内每条 SSA 指令调用 lowerInst
//   4. lowerInst     : OpCode → MIROpCode 的 1:1 映射
//   5. build_function_abi : 根据目标 ABI 计算参数/返回值位置
//   6. computeStackFrame : 计算栈帧大小（被调用者保存 + 出栈参数 + 局部变量）
//
// ABI 规则：
//   - SystemV x86_64 : 整数前 6 个用 rdi/rsi/rdx/rcx/r8/r9
//                      浮点前 8 个用 xmm0-xmm7
//                      返回值：rax/rdx（int）、xmm0/xmm1（float）
//   - AArch64 AAPCS64: 整数前 8 个用 x0-x7
//                      浮点前 8 个用 v0-v7
//                      返回值：x0（int）、v0（float）
//   - 栈参数按 8 字节对齐，整体栈帧按 16 字节对齐
//   - 超大结构体（>16 字节）通过 INDIRECT（隐藏指针）传递
//
// 依赖：MIR.php（数据结构 + dumpMIRFunction）、SSA.php（上游数据结构）
// ============================================================

require_once __DIR__ . '/MIR.php';

class MIRBuilder
{
    private TargetDesc $target;

    /** SSA value id => MIRValue 映射（当前函数内） */
    private array $valueMap = [];

    public function __construct(TargetDesc $target)
    {
        $this->target = $target;
    }

    // ═══════════════════════════════════════════════════════════════
    // lowerModule — 降低整个 SSA 模块
    // ═══════════════════════════════════════════════════════════════

    /**
     * 将 SSAModule 降低为 MIRModule。
     *
     * @param SSAModule $ssaModule
     * @return MIRModule
     */
    public function lowerModule(SSAModule $ssaModule): MIRModule
    {
        $mirModule = new MIRModule($this->target, '');

        foreach ($ssaModule->functions as $ssaFunc) {
            $mirModule->functions[] = $this->lowerFunction($ssaFunc);
        }

        return $mirModule;
    }

    // ═══════════════════════════════════════════════════════════════
    // lowerFunction — 降低单个函数
    // ═══════════════════════════════════════════════════════════════

    /**
     * 将 SSAFunction 降低为 MIRFunction。
     *
     * 步骤：
     *   1. 创建 MIRFunction，复制函数名、返回类型
     *   2. 调用 build_function_abi 计算参数/返回值 ABI 位置
     *   3. 为所有 SSA value 创建对应的 MIRValue
     *   4. 创建基本块（复制 CFG 结构）
     *   5. 在入口块开头插入参数加载 MOV 指令
     *   6. 对每个 SSA 块调用 lowerBlock 降低指令
     *   7. 调用 computeStackFrame 计算栈帧大小
     *
     * @param SSAFunction $ssaFunc
     * @return MIRFunction
     */
    public function lowerFunction(SSAFunction $ssaFunc): MIRFunction
    {
        $mirFunc = new MIRFunction(
            $ssaFunc->name,
            $ssaFunc->retType,
            $ssaFunc->entryBlockId,
            0,
            $this->target,
        );

        // 计算 ABI
        $abi = $this->build_function_abi($ssaFunc->retType, $ssaFunc->paramTypes, $this->target);
        $mirFunc->paramAbi = $abi['params'];
        $mirFunc->returnAbi = $abi['ret'];

        // 构建 SSA value id → MIRValue 映射
        $this->valueMap = [];
        foreach ($ssaFunc->values as $id => $ssaValue) {
            $this->valueMap[$id] = new MIRValue($ssaValue->type, "%v{$id}");
        }

        // 创建参数 MIRValue（SSA 中前 N 个 value id 对应参数）
        $paramCount = count($ssaFunc->paramTypes);
        for ($i = 0; $i < $paramCount; $i++) {
            if (isset($this->valueMap[$i])) {
                $mirFunc->params[] = $this->valueMap[$i];
            } else {
                $mirFunc->params[] = new MIRValue($ssaFunc->paramTypes[$i], "%v{$i}");
            }
        }

        // 创建基本块（复制 CFG 结构：label、predecessors、successors）
        foreach ($ssaFunc->blocks as $bid => $ssaBlock) {
            $mirFunc->blocks[$bid] = new MIRBasicBlock(
                $bid,
                $ssaBlock->label,
                [],
                $ssaBlock->predecessors,
                $ssaBlock->successors,
                $bid === $ssaFunc->entryBlockId,
            );
        }

        // 在入口块开头插入参数加载 MOV 指令
        if ($mirFunc->entryBlockId >= 0 && isset($mirFunc->blocks[$mirFunc->entryBlockId])) {
            $entryBlock = $mirFunc->blocks[$mirFunc->entryBlockId];
            for ($i = 0; $i < $paramCount; $i++) {
                $movInst = new MIRInstruction(
                    MIROpCode::MOV,
                    $mirFunc->params[$i],
                    [],
                    [
                        'abi'        => $mirFunc->paramAbi[$i],
                        'paramIndex' => $i,
                        'isParam'    => true,
                    ],
                    $entryBlock->id,
                );
                $entryBlock->instructions[] = $movInst;
            }
        }

        // 降低所有块的指令
        foreach ($ssaFunc->blocks as $bid => $_) {
            $this->lowerBlock($ssaFunc, $bid, $mirFunc);
        }

        // 计算栈帧大小
        $mirFunc->stackFrameSize = $this->computeStackFrame($mirFunc);

        return $mirFunc;
    }

    // ═══════════════════════════════════════════════════════════════
    // lowerBlock — 降低单个基本块
    // ═══════════════════════════════════════════════════════════════

    /**
     * 降低指定块内的所有 SSA 指令。
     *
     * @param SSAFunction $ssaFunc
     * @param int         $bid     块 id
     * @param MIRFunction $mirFunc 目标 MIR 函数
     * @return MIRBasicBlock
     */
    public function lowerBlock(SSAFunction $ssaFunc, int $bid, MIRFunction $mirFunc): MIRBasicBlock
    {
        $mirBlock = $mirFunc->blocks[$bid];
        $ssaBlock = $ssaFunc->blocks[$bid];

        foreach ($ssaBlock->instructions as $inst) {
            $mirBlock->instructions[] = $this->lowerInst($inst, $mirBlock);
        }

        return $mirBlock;
    }

    // ═══════════════════════════════════════════════════════════════
    // lowerInst — 降低单条指令
    // ═══════════════════════════════════════════════════════════════

    /**
     * 将 SSAInstruction 降低为 MIRInstruction（OpCode 1:1 映射）。
     *
     * 映射规则：
     *   SSA JMP → MIR BR（无条件跳转）
     *   SSA BR  → MIR CBR（条件跳转）
     *   SSA COPY → MIR MOV
     *   SSA EQ/NE/LT/LE/GT/GE → MIR CMP_EQ/CMP_NE/CMP_LT/CMP_LE/CMP_GT/CMP_GE
     *   其余同名映射
     *
     * @param SSAInstruction $inst
     * @param MIRBasicBlock  $block
     * @return MIRInstruction
     */
    public function lowerInst(SSAInstruction $inst, MIRBasicBlock $block): MIRInstruction
    {
        $mirOp = $this->mapOpCode($inst->op);

        // 转换 dst
        $dst = null;
        if ($inst->dst !== null && isset($this->valueMap[$inst->dst])) {
            $dst = $this->valueMap[$inst->dst];
        }

        // 转换 operands（SSA value id → MIRValue）
        $operands = [];
        foreach ($inst->operands as $operandId) {
            if (isset($this->valueMap[$operandId])) {
                $operands[] = $this->valueMap[$operandId];
            } else {
                // 未找到映射的 value：创建占位 MIRValue（容错）
                $operands[] = new MIRValue(SSAType::int(), "%v{$operandId}");
            }
        }

        return new MIRInstruction($mirOp, $dst, $operands, $inst->extra, $block->id);
    }

    // ═══════════════════════════════════════════════════════════════
    // mapOpCode — SSA OpCode → MIR MIROpCode 映射表
    // ═══════════════════════════════════════════════════════════════

    /**
     * SSA OpCode 到 MIR MIROpCode 的映射。
     *
     * @param OpCode $ssaOp
     * @return MIROpCode
     */
    private function mapOpCode(OpCode $ssaOp): MIROpCode
    {
        return match ($ssaOp) {
            // 终结指令：SSA BR(条件) → CBR, SSA JMP(无条件) → BR
            OpCode::RET     => MIROpCode::RET,
            OpCode::BR      => MIROpCode::CBR,
            OpCode::JMP     => MIROpCode::BR,

            // Phi 节点保留
            OpCode::PHI     => MIROpCode::PHI,

            // 内存操作
            OpCode::ALLOCA  => MIROpCode::ALLOCA,
            OpCode::LOAD    => MIROpCode::LOAD,
            OpCode::STORE   => MIROpCode::STORE,

            // 调用
            OpCode::CALL    => MIROpCode::CALL,

            // 算术
            OpCode::ADD     => MIROpCode::ADD,
            OpCode::SUB     => MIROpCode::SUB,
            OpCode::MUL     => MIROpCode::MUL,
            OpCode::DIV     => MIROpCode::DIV,
            OpCode::MOD     => MIROpCode::MOD,
            OpCode::NEG     => MIROpCode::NEG,

            // 比较（SSA EQ → MIR CMP_EQ，...）
            OpCode::EQ      => MIROpCode::CMP_EQ,
            OpCode::NE      => MIROpCode::CMP_NE,
            OpCode::LT      => MIROpCode::CMP_LT,
            OpCode::LE      => MIROpCode::CMP_LE,
            OpCode::GT      => MIROpCode::CMP_GT,
            OpCode::GE      => MIROpCode::CMP_GE,

            // 逻辑
            OpCode::AND     => MIROpCode::AND,
            OpCode::OR      => MIROpCode::OR,
            OpCode::NOT     => MIROpCode::NOT,

            // 转换
            OpCode::CAST    => MIROpCode::CAST,
            OpCode::BITCAST => MIROpCode::BITCAST,

            // 常量
            OpCode::CONST_INT   => MIROpCode::CONST_INT,
            OpCode::CONST_FLOAT => MIROpCode::CONST_FLOAT,
            OpCode::CONST_BOOL  => MIROpCode::CONST_BOOL,
            OpCode::CONST_NULL  => MIROpCode::CONST_NULL,

            // 复制 → MOV
            OpCode::COPY    => MIROpCode::MOV,
        };
    }

    // ═══════════════════════════════════════════════════════════════
    // build_function_abi — 计算函数参数与返回值的 ABI 位置
    // ═══════════════════════════════════════════════════════════════

    /**
     * 根据目标平台 ABI 计算参数与返回值的存储位置。
     *
     * SystemV x86_64 规则：
     *   - 整数/指针参数：前 6 个用寄存器（rdi/rsi/rdx/rcx/r8/r9），超出用栈
     *   - 浮点参数：前 8 个用 xmm0-xmm7，超出用栈
     *   - 结构体 > 16 字节：INDIRECT（通过指针传递）
     *   - 返回值：整数/指针前 2 个用 rax/rdx，浮点前 2 个用 xmm0/xmm1
     *
     * AArch64 AAPCS64 规则：
     *   - 整数/指针参数：前 8 个用 x0-x7
     *   - 浮点参数：前 8 个用 v0-v7
     *   - 返回值：x0（int）/ v0（float）
     *
     * 栈参数按 8 字节对齐，整体栈帧按 16 字节对齐。
     *
     * @param SSAType     $retType    返回类型
     * @param SSAType[]   $paramTypes 参数类型列表
     * @param TargetDesc  $target     目标平台
     * @return array{ret: ABIArg, params: ABIArg[]}
     */
    public function build_function_abi(SSAType $retType, array $paramTypes, TargetDesc $target): array
    {
        $params = [];
        $intRegUsed = 0;       // 已使用的整数寄存器数
        $floatRegUsed = 0;     // 已使用的浮点寄存器数
        $stackOffset = 0;      // 当前栈偏移（字节）

        foreach ($paramTypes as $type) {
            $size = $this->typeSize($type, $target);
            $alignment = $this->typeAlignment($type, $target);

            // 超大结构体（>16 字节）：间接传递
            if ($type->kind === SSATypeKind::STRUCT && $size > 16) {
                // INDIRECT：通过隐藏指针参数传递
                // 占用一个整数寄存器（存放指向结构体的指针）
                if ($intRegUsed < $target->intArgRegs) {
                    $params[] = new ABIArg(
                        kind: ABIArgKind::INDIRECT,
                        registerIndex: $intRegUsed,
                        size: $target->pointerSize,
                        alignment: $target->pointerSize,
                        isIndirect: true,
                        regClass: 'int',
                    );
                    $intRegUsed++;
                } else {
                    // 寄存器用尽，指针放栈上
                    $stackOffset = $this->alignTo($stackOffset, $target->pointerSize);
                    $params[] = new ABIArg(
                        kind: ABIArgKind::INDIRECT,
                        stackOffset: $stackOffset,
                        size: $target->pointerSize,
                        alignment: $target->pointerSize,
                        isIndirect: true,
                        regClass: 'int',
                    );
                    $stackOffset += $target->pointerSize;
                }
                continue;
            }

            $isFloat = $type->kind === SSATypeKind::FLOAT;

            if ($isFloat) {
                // 浮点参数：优先用浮点寄存器
                if ($floatRegUsed < $target->floatArgRegs) {
                    $params[] = new ABIArg(
                        kind: ABIArgKind::REGISTER,
                        registerIndex: $floatRegUsed,
                        size: $size,
                        alignment: $alignment,
                        regClass: 'float',
                    );
                    $floatRegUsed++;
                } else {
                    // 超出寄存器数：走栈
                    $stackOffset = $this->alignTo($stackOffset, max($alignment, $target->pointerSize));
                    $params[] = new ABIArg(
                        kind: ABIArgKind::STACK,
                        stackOffset: $stackOffset,
                        size: $size,
                        alignment: $alignment,
                        regClass: 'float',
                    );
                    $stackOffset += max($size, $target->pointerSize);
                }
            } else {
                // 整数/指针/布尔参数：优先用整数寄存器
                if ($intRegUsed < $target->intArgRegs) {
                    $params[] = new ABIArg(
                        kind: ABIArgKind::REGISTER,
                        registerIndex: $intRegUsed,
                        size: $size,
                        alignment: $alignment,
                        regClass: 'int',
                    );
                    $intRegUsed++;
                } else {
                    // 超出寄存器数：走栈
                    $stackOffset = $this->alignTo($stackOffset, max($alignment, $target->pointerSize));
                    $params[] = new ABIArg(
                        kind: ABIArgKind::STACK,
                        stackOffset: $stackOffset,
                        size: $size,
                        alignment: $alignment,
                        regClass: 'int',
                    );
                    $stackOffset += max($size, $target->pointerSize);
                }
            }
        }

        // 计算返回值 ABI
        $retAbi = $this->buildReturnAbi($retType, $target);

        return ['ret' => $retAbi, 'params' => $params];
    }

    /**
     * 计算返回值的 ABI 位置。
     *
     * @param SSAType    $retType
     * @param TargetDesc $target
     * @return ABIArg
     */
    private function buildReturnAbi(SSAType $retType, TargetDesc $target): ABIArg
    {
        $size = $this->typeSize($retType, $target);
        $alignment = $this->typeAlignment($retType, $target);

        // void 返回：无意义位置
        if ($retType->kind === SSATypeKind::VOID || $size === 0) {
            return new ABIArg(
                kind: ABIArgKind::REGISTER,
                registerIndex: 0,
                size: 0,
                alignment: 1,
                regClass: 'int',
            );
        }

        // 超大结构体返回：通过 rax 传指针（间接）
        if ($retType->kind === SSATypeKind::STRUCT && $size > 16) {
            return new ABIArg(
                kind: ABIArgKind::INDIRECT,
                registerIndex: 0,
                size: $target->pointerSize,
                alignment: $target->pointerSize,
                isIndirect: true,
                regClass: 'int',
            );
        }

        // 浮点返回：xmm0（x86_64）/ v0（aarch64）
        if ($retType->kind === SSATypeKind::FLOAT) {
            return new ABIArg(
                kind: ABIArgKind::REGISTER,
                registerIndex: 0,
                size: $size,
                alignment: $alignment,
                regClass: 'float',
            );
        }

        // 整数/指针/布尔返回：rax（x86_64）/ x0（aarch64）
        return new ABIArg(
            kind: ABIArgKind::REGISTER,
            registerIndex: 0,
            size: $size,
            alignment: $alignment,
            regClass: 'int',
        );
    }

    // ═══════════════════════════════════════════════════════════════
    // computeStackFrame — 计算栈帧大小
    // ═══════════════════════════════════════════════════════════════

    /**
     * 计算函数的总栈帧大小。
     *
     * 组成：
     *   1. 被调用者保存寄存器区域（calleeSavedRegs × pointerSize）
     *   2. 出栈参数区域（函数内 CALL 指令的最大栈参数数 × pointerSize）
     *   3. 局部变量区域（ALLOCA 指令产生的栈分配）
     *   4. 对齐填充（整体按 stackAlign 对齐）
     *
     * @param MIRFunction $func
     * @return int 栈帧大小（字节）
     */
    public function computeStackFrame(MIRFunction $func): int
    {
        $target = $func->target ?? $this->target;

        // 1. 被调用者保存寄存器区域
        $calleeSavedSize = count($target->calleeSavedRegs) * $target->pointerSize;

        // 2. 出栈参数区域：扫描所有 CALL 指令，取最大栈参数数
        $maxOutgoingArgs = 0;
        $regLimit = $target->intArgRegs + $target->floatArgRegs;
        foreach ($func->blocks as $block) {
            foreach ($block->instructions as $inst) {
                if ($inst->op === MIROpCode::CALL) {
                    $argCount = count($inst->operands);
                    $stackArgs = max(0, $argCount - $regLimit);
                    $maxOutgoingArgs = max($maxOutgoingArgs, $stackArgs);
                }
            }
        }
        $outgoingSize = $maxOutgoingArgs * $target->pointerSize;

        // 3. 局部变量区域：ALLOCA 指令产生的栈分配
        $localsSize = 0;
        foreach ($func->blocks as $block) {
            foreach ($block->instructions as $inst) {
                if ($inst->op === MIROpCode::ALLOCA) {
                    // 简化：每个 ALLOCA 占用 pointerSize 字节（指针槽位）
                    $localsSize += $target->pointerSize;
                }
            }
        }

        // 4. 总大小 + 对齐填充
        $total = $calleeSavedSize + $outgoingSize + $localsSize;
        return $this->alignTo($total, $target->stackAlign);
    }

    // ═══════════════════════════════════════════════════════════════
    // 辅助方法
    // ═══════════════════════════════════════════════════════════════

    /**
     * 计算 SSAType 在目标平台上的大小（字节）。
     *
     * 简化规则（SSAType 不携带精确的数组长度/结构体字段信息）：
     *   - VOID  : 0
     *   - INT   : 8（64 位）
     *   - FLOAT : 8（double）
     *   - BOOL  : 1
     *   - PTR   : pointerSize
     *   - ARRAY : 16（保守估计，视为小数组可通过寄存器传递）
     *   - STRUCT: 16（保守估计，视为小结构体可通过寄存器传递）
     *   - FUNC  : pointerSize（函数指针）
     *
     * @param SSAType    $type
     * @param TargetDesc $target
     * @return int
     */
    private function typeSize(SSAType $type, TargetDesc $target): int
    {
        return match ($type->kind) {
            SSATypeKind::VOID   => 0,
            SSATypeKind::INT    => 8,
            SSATypeKind::FLOAT  => 8,
            SSATypeKind::BOOL   => 1,
            SSATypeKind::PTR    => $target->pointerSize,
            SSATypeKind::ARRAY  => 16,   // 保守估计
            SSATypeKind::STRUCT => 16,   // 保守估计（小结构体）
            SSATypeKind::FUNC   => $target->pointerSize,
        };
    }

    /**
     * 计算 SSAType 在目标平台上的对齐（字节）。
     *
     * @param SSAType    $type
     * @param TargetDesc $target
     * @return int
     */
    private function typeAlignment(SSAType $type, TargetDesc $target): int
    {
        return match ($type->kind) {
            SSATypeKind::VOID   => 1,
            SSATypeKind::INT    => 8,
            SSATypeKind::FLOAT  => 8,
            SSATypeKind::BOOL   => 1,
            SSATypeKind::PTR    => $target->pointerSize,
            SSATypeKind::ARRAY  => $target->pointerSize,
            SSATypeKind::STRUCT => $target->pointerSize,
            SSATypeKind::FUNC   => $target->pointerSize,
        };
    }

    /**
     * 将 value 向上对齐到 alignment 的倍数。
     *
     * @param int $value
     * @param int $alignment
     * @return int
     */
    private function alignTo(int $value, int $alignment): int
    {
        if ($alignment <= 1) {
            return $value;
        }
        return ($value + $alignment - 1) & ~($alignment - 1);
    }
}
