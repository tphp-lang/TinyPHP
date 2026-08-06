<?php

declare(strict_types=1);

// ============================================================
// MIR — 机器级中间表示层（Task 14）
//
// @unwired: 全仓库无生产引用，仅 tools/MIR/mir_test.php 单元测试使用。
//   MIR 设计为 SSA→C 之间的中间层，当前主编译路径直接从 AST/SSA 生成 C。
//   若未来启用多后端（如 LLVM IR）则保留，否则评估去留。
//
// 设计目标：
//   - 从 SSA 降低而来，引入目标 ABI 元数据（参数寄存器、栈布局、对齐）
//   - 为指令选择与寄存器分配做准备，但尚不分配物理寄存器
//   - 保留 SSA 的 CFG 结构与 phi 节点（phi 在 C 降低阶段消除）
//   - 单文件实现，与 SSA 层解耦
//
// 主要组成：
//   - Arch / TargetDesc        : 目标平台描述（arch、pointer_size、寄存器配额等）
//   - ABIArgKind / ABIArg      : 参数 ABI 位置（register/stack/indirect）
//   - MIROpCode                : 机器级操作码枚举（从 SSA OpCode 映射）
//   - MIRValue                 : 值引用（类型 + 名称 + 标志位）
//   - MIRInstruction           : 机器指令（op + dst + operands + extra）
//   - MIRBasicBlock            : 基本块（CFG 节点）
//   - MIRFunction              : 函数（含 ABI 信息与栈帧大小）
//   - MIRModule                : 模块（函数集合 + 目标平台）
//   - dumpMIRFunction()        : 文本输出（便于调试）
//
// 依赖：SSA.php（复用 SSAType 类型系统）
// ============================================================

require_once __DIR__ . '/../SSA/SSA.php';

// ═══════════════════════════════════════════════════════════════
// Arch — 目标架构枚举
//
//   X86_64  : System V AMD64 ABI
//   AARCH64 : AAPCS64 ABI
//   ARM64   : AARCH64 的别名（常量）
// ═══════════════════════════════════════════════════════════════
enum Arch: int
{
    case X86_64  = 1;
    case AARCH64 = 2;

    // ARM64 是 AARCH64 的别名
    public const ARM64 = self::AARCH64;

    /**
     * 文本标签（用于 dump）。
     */
    public function label(): string
    {
        return match ($this) {
            self::X86_64  => 'x86_64',
            self::AARCH64 => 'aarch64',
        };
    }
}

// ═══════════════════════════════════════════════════════════════
// ABIArgKind — 参数 ABI 传递方式枚举
//
//   REGISTER  : 通过寄存器传递
//   STACK     : 通过栈传递
//   INDIRECT  : 间接传递（超大结构体，通过隐藏指针参数）
// ═══════════════════════════════════════════════════════════════
enum ABIArgKind: int
{
    case REGISTER = 1;
    case STACK    = 2;
    case INDIRECT = 3;
}

// ═══════════════════════════════════════════════════════════════
// ABIArg — 单个参数/返回值的 ABI 位置描述
//
//   - kind          : 传递方式（REGISTER/STACK/INDIRECT）
//   - registerIndex : 寄存器索引（REGISTER 时有效，如 0=rdi/x0）
//   - stackOffset   : 栈偏移（STACK 时有效，字节为单位）
//   - size          : 参数大小（字节）
//   - alignment     : 参数对齐（字节）
//   - isIndirect    : 是否间接传递（超大结构体通过指针）
//   - regClass      : 寄存器类别（'int'=整数寄存器，'float'=浮点寄存器）
// ═══════════════════════════════════════════════════════════════
class ABIArg
{
    public function __construct(
        public ABIArgKind $kind,
        public ?int $registerIndex = null,
        public ?int $stackOffset = null,
        public int $size = 0,
        public int $alignment = 1,
        public bool $isIndirect = false,
        public string $regClass = 'int', // 'int' | 'float'
    ) {}

    /**
     * 文本描述（用于 dump）。
     */
    public function label(): string
    {
        return match ($this->kind) {
            ABIArgKind::REGISTER => "reg{$this->registerIndex}",
            ABIArgKind::STACK    => "stack{$this->stackOffset}",
            ABIArgKind::INDIRECT => "indirect",
        };
    }
}

// ═══════════════════════════════════════════════════════════════
// TargetDesc — 目标平台描述
//
//   - arch           : 架构
//   - pointerSize    : 指针大小（x86_64=8, aarch64=8）
//   - intArgRegs     : 整数参数寄存器数（x86_64=6, aarch64=8）
//   - floatArgRegs   : 浮点参数寄存器数（x86_64=8, aarch64=8）
//   - stackAlign     : 栈帧对齐（16 字节）
//   - intReturnRegs  : 整数返回值寄存器数（x86_64=2, aarch64=4）
//   - calleeSavedRegs: 被调用者保存的寄存器索引列表
// ═══════════════════════════════════════════════════════════════
class TargetDesc
{
    public function __construct(
        public Arch $arch,
        public int $pointerSize,
        public int $intArgRegs,
        public int $floatArgRegs,
        public int $stackAlign,
        public int $intReturnRegs,
        public array $calleeSavedRegs = [],
    ) {}

    /**
     * x86_64 (System V ABI) 平台描述。
     *
     * 整数参数寄存器：rdi, rsi, rdx, rcx, r8, r9（6 个）
     * 浮点参数寄存器：xmm0-xmm7（8 个）
     * 返回值寄存器：rax, rdx（2 个）
     * 被调用者保存：rbx, rbp, r12, r13, r14, r15（6 个）
     */
    public static function x86_64(): self
    {
        return new self(
            arch: Arch::X86_64,
            pointerSize: 8,
            intArgRegs: 6,
            floatArgRegs: 8,
            stackAlign: 16,
            intReturnRegs: 2,
            calleeSavedRegs: [3, 5, 12, 13, 14, 15], // rbx=3, rbp=5, r12-r15
        );
    }

    /**
     * AArch64 (AAPCS64 ABI) 平台描述。
     *
     * 整数参数寄存器：x0-x7（8 个）
     * 浮点参数寄存器：v0-v7（8 个）
     * 返回值寄存器：x0（1 个，intReturnRegs=4 表示可扩展到 x0-x3）
     * 被调用者保存：x19-x29（11 个）
     */
    public static function aarch64(): self
    {
        return new self(
            arch: Arch::AARCH64,
            pointerSize: 8,
            intArgRegs: 8,
            floatArgRegs: 8,
            stackAlign: 16,
            intReturnRegs: 4,
            calleeSavedRegs: [19, 20, 21, 22, 23, 24, 25, 26, 27, 28, 29],
        );
    }
}

// ═══════════════════════════════════════════════════════════════
// MIROpCode — 机器级操作码枚举
//
// 从 SSA OpCode 映射而来，但更接近机器语义：
//   - SSA JMP（无条件跳）→ MIR BR
//   - SSA BR（条件跳）  → MIR CBR
//   - SSA COPY          → MIR MOV
//   - SSA EQ/NE/LT/...  → MIR CMP_EQ/CMP_NE/...
//
// 分组：
//   终结指令 (1-3)   : RET / BR / CBR
//   调用 (4)         : CALL
//   数据移动 (5)     : MOV
//   算术 (10-15)     : ADD / SUB / MUL / DIV / MOD / NEG
//   比较 (20-25)     : CMP_EQ / CMP_NE / CMP_LT / CMP_LE / CMP_GT / CMP_GE
//   逻辑 (30-32)     : AND / OR / NOT
//   内存 (40-41, 80) : LOAD / STORE / ALLOCA
//   常量 (50-53)     : CONST_INT / CONST_FLOAT / CONST_BOOL / CONST_NULL
//   Phi (60)         : PHI（MIR 阶段保留，C 降低时消除）
//   转换 (70-71)     : CAST / BITCAST
// ═══════════════════════════════════════════════════════════════
enum MIROpCode: int
{
    // === 终结指令 ===
    case RET = 1;   // return [value]
    case BR  = 2;   // 无条件跳转   extra: {'target_block': int}
    case CBR = 3;   // 条件跳转     extra: {'then_block': int, 'else_block': int}

    // === 调用 ===
    case CALL = 4;  // 函数调用     extra: {'func': string}

    // === 数据移动 ===
    case MOV = 5;   // 寄存器/内存移动（含参数加载）

    // === 算术运算 ===
    case ADD = 10;
    case SUB = 11;
    case MUL = 12;
    case DIV = 13;
    case MOD = 14;
    case NEG = 15;  // 一元负

    // === 比较运算（结果类型 BOOL）===
    case CMP_EQ = 20;
    case CMP_NE = 21;
    case CMP_LT = 22;
    case CMP_LE = 23;
    case CMP_GT = 24;
    case CMP_GE = 25;

    // === 逻辑运算 ===
    case AND = 30;
    case OR  = 31;
    case NOT = 32;  // 一元非

    // === 内存操作 ===
    case LOAD   = 40;
    case STORE  = 41;
    case ALLOCA = 80; // 栈分配（从 SSA 直接映射）

    // === 常量 ===
    case CONST_INT   = 50;
    case CONST_FLOAT = 51;
    case CONST_BOOL  = 52;
    case CONST_NULL  = 53;

    // === Phi 节点（MIR 阶段保留）===
    case PHI = 60;

    // === 类型转换 ===
    case CAST    = 70;
    case BITCAST = 71;

    /**
     * 是否为终结指令（必须是基本块最后一条）。
     */
    public function isTerminator(): bool
    {
        return $this === self::RET || $this === self::BR || $this === self::CBR;
    }

    /**
     * 是否产生结果值（有 dst）。
     */
    public function hasResult(): bool
    {
        return match ($this) {
            self::RET, self::BR, self::CBR, self::STORE => false,
            default => true,
        };
    }

    /**
     * 文本名称（用于 dump）。
     */
    public function label(): string
    {
        return $this->name;
    }
}

// ═══════════════════════════════════════════════════════════════
// MIRValue — MIR 值引用
//
//   - type        : SSA 类型引用（复用 SSAType）
//   - name        : 值名称（如 "%v1" 表示虚拟寄存器）
//   - isRegister  : 是否已分配到物理寄存器（MIR 阶段通常为 false）
//   - isStackSlot : 是否分配到栈槽（MIR 阶段通常为 false）
//   - isConst     : 是否常量值
// ═══════════════════════════════════════════════════════════════
class MIRValue
{
    public function __construct(
        public SSAType $type,
        public string $name,
        public bool $isRegister = false,
        public bool $isStackSlot = false,
        public bool $isConst = false,
    ) {}
}

// ═══════════════════════════════════════════════════════════════
// MIRInstruction — MIR 指令
//
//   - op           : 操作码
//   - dst          : 结果值（?MIRValue，终结指令/STORE 为 null）
//   - operands     : 操作数 MIRValue 数组
//   - extra        : 附加数据（ABI 信息、跳转目标、调用目标等）
//   - parentBlock  : 所属基本块 id
// ═══════════════════════════════════════════════════════════════
class MIRInstruction
{
    public function __construct(
        public MIROpCode $op,
        public ?MIRValue $dst = null,
        public array $operands = [],
        public array $extra = [],
        public int $parentBlock = -1,
    ) {}
}

// ═══════════════════════════════════════════════════════════════
// MIRBasicBlock — MIR 基本块（CFG 节点）
//
//   - id           : 块 id
//   - label        : 文本标签（从 SSA 继承，如 'entry', 'if.then'）
//   - instructions : 指令列表（终结指令应为最后一条）
//   - predecessors : 前驱 block id 列表
//   - successors   : 后继 block id 列表
//   - isEntry      : 是否为入口块
// ═══════════════════════════════════════════════════════════════
class MIRBasicBlock
{
    public function __construct(
        public int $id,
        public string $label = '',
        public array $instructions = [],
        public array $predecessors = [],
        public array $successors = [],
        public bool $isEntry = false,
    ) {}
}

// ═══════════════════════════════════════════════════════════════
// MIRFunction — MIR 函数
//
//   - name           : 函数名
//   - returnType     : 返回类型（SSAType）
//   - params         : 参数 MIRValue 列表
//   - paramAbi       : 参数 ABI 位置列表（与 params 一一对应）
//   - returnAbi      : 返回值 ABI 位置
//   - blocks         : 基本块列表（id => MIRBasicBlock）
//   - entryBlockId   : 入口块 id
//   - stackFrameSize : 栈帧大小（字节）
//   - target         : 目标平台描述
// ═══════════════════════════════════════════════════════════════
class MIRFunction
{
    /** @var MIRValue[] */
    public array $params = [];

    /** @var ABIArg[] */
    public array $paramAbi = [];

    public ?ABIArg $returnAbi = null;

    /** @var array<int, MIRBasicBlock> */
    public array $blocks = [];

    public function __construct(
        public string $name,
        public SSAType $returnType,
        public int $entryBlockId = -1,
        public int $stackFrameSize = 0,
        public ?TargetDesc $target = null,
    ) {}
}

// ═══════════════════════════════════════════════════════════════
// MIRModule — MIR 模块
//
//   - functions  : 函数列表
//   - target     : 目标平台描述
//   - sourceFile : 源文件路径
// ═══════════════════════════════════════════════════════════════
class MIRModule
{
    /** @var MIRFunction[] */
    public array $functions = [];

    public function __construct(
        public ?TargetDesc $target = null,
        public string $sourceFile = '',
    ) {}
}

// ═══════════════════════════════════════════════════════════════
// dumpMIRFunction — 文本输出（便于调试与测试断言）
//
// 格式示例：
//   function foo(int, int) -> int [target=x86_64, stack=48] {
//     block_0 (entry) [entry]; preds=[]
//       %v0 = MOV abi=reg0  ; param 0
//       %v1 = MOV abi=reg1  ; param 1
//       %v2 = ADD %v0, %v1
//       RET %v2
//   }
// ═══════════════════════════════════════════════════════════════
function dumpMIRFunction(MIRFunction $f): string
{
    $out = '';

    // 函数签名：参数类型列表 + 返回类型 + 目标平台 + 栈帧大小
    $paramTypes = array_map(fn(MIRValue $v) => $v->type, $f->params);
    $paramStr = implode(', ', array_map(fn(SSAType $t) => $t->label(), $paramTypes));
    $archStr = $f->target !== null ? $f->target->arch->label() : '?';
    $out .= "function {$f->name}({$paramStr}) -> {$f->returnType->label()} [target={$archStr}, stack={$f->stackFrameSize}] {\n";

    // 按 block id 顺序输出，entry 块优先
    $blockIds = array_keys($f->blocks);
    usort($blockIds, function (int $a, int $b) use ($f) {
        if ($a === $f->entryBlockId) return -1;
        if ($b === $f->entryBlockId) return 1;
        return $a <=> $b;
    });

    foreach ($blockIds as $bid) {
        $block = $f->blocks[$bid];
        $entryTag = $block->isEntry ? ' [entry]' : '';
        $preds = implode(', ', $block->predecessors);
        $out .= "  block_{$bid} ({$block->label}){$entryTag}; preds=[{$preds}]\n";
        foreach ($block->instructions as $inst) {
            $out .= '    ' . formatMIRInstruction($inst) . "\n";
        }
    }

    $out .= "}\n";
    return $out;
}

/**
 * 格式化单条 MIR 指令为文本。
 */
function formatMIRInstruction(MIRInstruction $inst): string
{
    $dstStr = $inst->dst !== null ? $inst->dst->name : '';
    $opName = $inst->op->label();

    // 参数 MOV 指令特殊处理：显示 ABI 位置与参数索引
    if ($inst->op === MIROpCode::MOV && ($inst->extra['isParam'] ?? false)) {
        $abi = $inst->extra['abi'] ?? null;
        $abiStr = $abi !== null ? "abi={$abi->label()}" : 'abi=?';
        $paramIdx = $inst->extra['paramIndex'] ?? 0;
        return "{$dstStr} = MOV {$abiStr}  ; param {$paramIdx}";
    }

    switch ($inst->op) {
        case MIROpCode::RET:
            return count($inst->operands) === 0
                ? 'RET'
                : 'RET ' . $inst->operands[0]->name;

        case MIROpCode::BR:
            $tgt = $inst->extra['target_block'] ?? -1;
            return "BR block_{$tgt}";

        case MIROpCode::CBR:
            $cond = $inst->operands[0]->name;
            $thenB = $inst->extra['then_block'] ?? -1;
            $elseB = $inst->extra['else_block'] ?? -1;
            return "CBR {$cond}, block_{$thenB}, block_{$elseB}";

        case MIROpCode::PHI:
            $parts = [];
            $blocks = $inst->extra['blocks'] ?? [];
            foreach ($inst->operands as $i => $v) {
                $b = $blocks[$i] ?? -1;
                $parts[] = "[{$v->name} from block_{$b}]";
            }
            return "{$dstStr} = PHI " . implode(', ', $parts);

        case MIROpCode::CALL:
            $fn = $inst->extra['func'] ?? '?';
            $args = implode(', ', array_map(fn(MIRValue $v) => $v->name, $inst->operands));
            return $inst->dst !== null
                ? "{$dstStr} = CALL @{$fn}({$args})"
                : "CALL @{$fn}({$args})";

        case MIROpCode::CONST_INT:
            $v = $inst->extra['value'] ?? 0;
            return "{$dstStr} = CONST_INT {$v}";

        case MIROpCode::CONST_FLOAT:
            $v = $inst->extra['value'] ?? 0.0;
            return "{$dstStr} = CONST_FLOAT {$v}";

        case MIROpCode::CONST_BOOL:
            $v = $inst->extra['value'] ?? false;
            $vStr = $v ? 'true' : 'false';
            return "{$dstStr} = CONST_BOOL {$vStr}";

        case MIROpCode::CONST_NULL:
            return "{$dstStr} = CONST_NULL";

        case MIROpCode::MOV:
            // 普通 MOV（非参数加载）
            return "{$dstStr} = MOV " . $inst->operands[0]->name;

        case MIROpCode::NEG:
        case MIROpCode::NOT:
            return "{$dstStr} = {$opName} " . $inst->operands[0]->name;

        case MIROpCode::LOAD:
            return "{$dstStr} = LOAD " . $inst->operands[0]->name;

        case MIROpCode::STORE:
            return "STORE " . $inst->operands[0]->name . ", " . $inst->operands[1]->name;

        case MIROpCode::ALLOCA:
            $et = ($inst->extra['elem_type'] ?? null)?->label() ?? '?';
            $cnt = $inst->extra['count'] ?? 1;
            return "{$dstStr} = ALLOCA {$et}[{$cnt}]";

        case MIROpCode::CAST:
        case MIROpCode::BITCAST:
            $to = ($inst->extra['to_type'] ?? null)?->label() ?? '?';
            return "{$dstStr} = {$opName} " . $inst->operands[0]->name . " to {$to}";

        // 二元运算
        case MIROpCode::ADD:
        case MIROpCode::SUB:
        case MIROpCode::MUL:
        case MIROpCode::DIV:
        case MIROpCode::MOD:
        case MIROpCode::AND:
        case MIROpCode::OR:
        case MIROpCode::CMP_EQ:
        case MIROpCode::CMP_NE:
        case MIROpCode::CMP_LT:
        case MIROpCode::CMP_LE:
        case MIROpCode::CMP_GT:
        case MIROpCode::CMP_GE:
            $l = $inst->operands[0]->name;
            $r = $inst->operands[1]->name;
            return "{$dstStr} = {$opName} {$l}, {$r}";

        default:
            return "{$dstStr} = {$opName}";
    }
}
