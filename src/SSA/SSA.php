<?php

declare(strict_types=1);

// ============================================================
// SSA — 静态单赋值中间表示层
//
// 设计目标：
//   - 位于 FlatAst 与 C 代码生成之间，提供显式 CFG + SSA 形式
//   - 独立于源语言类型，自带 SSAType 类型系统
//   - SoA 风格：value 用全局递增 int id 引用，结构体存对象数组
//   - 单文件实现，简化结构（SSABuilder 单独一个文件）
//
// 主要组成：
//   - SSATypeKind / SSAType     : SSA 类型系统
//   - OpCode / SSAInstruction   : 指令枚举与指令结构
//   - SSAValue                  : 值引用（id + type + name）
//   - SSABasicBlock             : 基本块（CFG 节点）
//   - SSAFunction               : 函数（基本块集合 + 值表）
//   - SSAModule                 : 模块（函数集合 + 全局变量）
//   - dumpSSAFunction()         : 文本输出（便于调试）
//
// 依赖：无（纯数据结构），与 FlatAst 解耦
// ============================================================

// ═══════════════════════════════════════════════════════════════
// SSATypeKind — SSA 类型种类枚举
//
// 独立于源语言类型，覆盖 SSA 层所需的基本类型分类。
// ═══════════════════════════════════════════════════════════════
enum SSATypeKind: int
{
    case VOID   = 0;
    case INT    = 1;
    case FLOAT  = 2;
    case BOOL   = 3;
    case PTR    = 4;
    case ARRAY  = 5;
    case STRUCT = 6;
    case FUNC   = 7;
}

// ═══════════════════════════════════════════════════════════════
// SSAType — SSA 类型表示
//
//   - elemType    : 数组元素类型（仅 ARRAY 时使用）
//   - pointeeType : 指针指向类型（仅 PTR 时使用）
//   - 不可变值对象；equals 做结构递归比较
// ═══════════════════════════════════════════════════════════════
class SSAType
{
    public function __construct(
        public SSATypeKind $kind,
        public ?SSAType $elemType = null,
        public ?SSAType $pointeeType = null,
    ) {}

    public static function void(): self
    {
        return new self(SSATypeKind::VOID);
    }

    public static function int(): self
    {
        return new self(SSATypeKind::INT);
    }

    public static function float(): self
    {
        return new self(SSATypeKind::FLOAT);
    }

    public static function bool(): self
    {
        return new self(SSATypeKind::BOOL);
    }

    public static function ptr(SSAType $pointee): self
    {
        return new self(SSATypeKind::PTR, null, $pointee);
    }

    public static function array(SSAType $elem): self
    {
        return new self(SSATypeKind::ARRAY, $elem);
    }

    /**
     * 结构化相等比较（递归比较 elemType / pointeeType）。
     */
    public function equals(SSAType $other): bool
    {
        if ($this->kind !== $other->kind) {
            return false;
        }
        // elemType 比较（双方需同时存在或同时缺失，且递归相等）
        if (($this->elemType === null) !== ($other->elemType === null)) {
            return false;
        }
        if ($this->elemType !== null && !$this->elemType->equals($other->elemType)) {
            return false;
        }
        // pointeeType 比较
        if (($this->pointeeType === null) !== ($other->pointeeType === null)) {
            return false;
        }
        if ($this->pointeeType !== null && !$this->pointeeType->equals($other->pointeeType)) {
            return false;
        }
        return true;
    }

    /**
     * 简短文本标签（用于 dump 与函数签名）。
     */
    public function label(): string
    {
        return match ($this->kind) {
            SSATypeKind::VOID   => 'void',
            SSATypeKind::INT    => 'int',
            SSATypeKind::FLOAT  => 'float',
            SSATypeKind::BOOL   => 'bool',
            SSATypeKind::PTR    => 'ptr<' . ($this->pointeeType?->label() ?? '?') . '>',
            SSATypeKind::ARRAY  => 'array<' . ($this->elemType?->label() ?? '?') . '>',
            SSATypeKind::STRUCT => 'struct',
            SSATypeKind::FUNC   => 'func',
        };
    }
}

// ═══════════════════════════════════════════════════════════════
// OpCode — SSA 指令操作码枚举
//
// 分组：
//   终结指令 (1-3)   : RET / BR / JMP
//   Phi 节点 (4)     : PHI
//   内存操作 (5-7)   : ALLOCA / LOAD / STORE
//   调用 (8)         : CALL
//   算术 (10-15)     : ADD / SUB / MUL / DIV / MOD / NEG
//   比较 (20-25)     : EQ / NE / LT / LE / GT / GE
//   逻辑 (30-32)     : AND / OR / NOT
//   转换 (40-41)     : CAST / BITCAST
//   常量 (50-53)     : CONST_INT / CONST_FLOAT / CONST_BOOL / CONST_NULL
//   其他 (60)        : COPY
// ═══════════════════════════════════════════════════════════════
enum OpCode: int
{
    // === 终结指令 ===
    case RET = 1;   // return [value]       extra: 无；operands: [value]? 或 []
    case BR  = 2;   // 条件跳转             operands: [cond]; extra: {'then_block': int, 'else_block': int}
    case JMP = 3;   // 无条件跳转           extra: {'target_block': int}

    // === Phi 节点 ===
    case PHI = 4;   // phi 合并             operands: [v1, v2, ...]; extra: {'blocks': [b1, b2, ...]}

    // === 内存操作 ===
    case ALLOCA = 5; // 栈分配              extra: {'elem_type': SSAType, 'count': int}
    case LOAD   = 6; // 加载                operands: [ptr]
    case STORE  = 7; // 存储                operands: [ptr, value]

    // === 调用 ===
    case CALL = 8;   // 函数调用            operands: [args...]; extra: {'func': string}

    // === 算术运算 ===
    case ADD = 10;
    case SUB = 11;
    case MUL = 12;
    case DIV = 13;
    case MOD = 14;
    case NEG = 15;   // 一元负              operands: [v]

    // === 比较运算（结果类型 BOOL）===
    case EQ = 20;
    case NE = 21;
    case LT = 22;
    case LE = 23;
    case GT = 24;
    case GE = 25;

    // === 逻辑运算 ===
    case AND = 30;
    case OR  = 31;
    case NOT = 32;   // 一元非              operands: [v]

    // === 转换 ===
    case CAST    = 40; // 类型转换          extra: {'to_type': SSAType}
    case BITCAST = 41; // 位转换            extra: {'to_type': SSAType}

    // === 常量 ===
    case CONST_INT   = 50; // extra: {'value': int}
    case CONST_FLOAT = 51; // extra: {'value': float}
    case CONST_BOOL  = 52; // extra: {'value': bool}
    case CONST_NULL  = 53;

    // === 其他 ===
    case COPY = 60;  // 复制（用于显式赋值） operands: [src]

    /**
     * 是否为终结指令（必须是基本块最后一条）。
     */
    public function isTerminator(): bool
    {
        return $this === self::RET || $this === self::BR || $this === self::JMP;
    }

    /**
     * 是否产生结果值（有 dst）。
     */
    public function hasResult(): bool
    {
        return match ($this) {
            self::RET, self::BR, self::JMP, self::STORE => false,
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
// SSAValue — SSA 值引用
//
//   每个值有全局唯一 id（函数内递增），通过 int id 被指令引用。
//   SoA 布局：值表存于 SSAFunction::$values，指令只持 id。
// ═══════════════════════════════════════════════════════════════
class SSAValue
{
    public function __construct(
        public int $id,
        public SSAType $type,
        public ?string $name = null,
    ) {}
}

// ═══════════════════════════════════════════════════════════════
// SSAInstruction — SSA 指令
//
//   - op       : 操作码
//   - dst      : 结果 value id（?int，终结指令/STORE 为 null）
//   - operands : 操作数 value id 数组
//   - extra    : 附加数据（跳转目标 block id、函数名、常量值等）
// ═══════════════════════════════════════════════════════════════
class SSAInstruction
{
    public function __construct(
        public OpCode $op,
        public ?int $dst = null,
        public array $operands = [],
        public array $extra = [],
    ) {}
}

// ═══════════════════════════════════════════════════════════════
// SSABasicBlock — 基本块（CFG 节点）
//
//   - id           : 块 id（函数内递增）
//   - label        : 文本标签（如 'entry', 'then', 'merge'）
//   - instructions : 指令列表（终结指令应为最后一条）
//   - predecessors : 前驱 block id 列表
//   - successors   : 后继 block id 列表
// ═══════════════════════════════════════════════════════════════
class SSABasicBlock
{
    public function __construct(
        public int $id,
        public string $label,
        public array $instructions = [],
        public array $predecessors = [],
        public array $successors = [],
    ) {}

    /**
     * 是否为合并点（多个前驱）—— phi 插入的关键判据。
     */
    public function isMergePoint(): bool
    {
        return count($this->predecessors) >= 2;
    }

    /**
     * 在指令列表开头插入一条指令（用于 phi 插入）。
     */
    public function prependInstruction(SSAInstruction $inst): void
    {
        array_unshift($this->instructions, $inst);
    }
}

// ═══════════════════════════════════════════════════════════════
// SSAFunction — SSA 函数
//
//   - values       : value id => SSAValue（值表，SoA 风格集中存储）
//   - blocks       : block id => SSABasicBlock
//   - nextValueId  : 下一个 value id（递增计数器）
//   - nextBlockId  : 下一个 block id（递增计数器）
//   - entryBlockId : 入口块 id
// ═══════════════════════════════════════════════════════════════
class SSAFunction
{
    /** @var array<int, SSAValue> 值表（id => SSAValue） */
    public array $values = [];

    /** @var array<int, SSABasicBlock> 块表（id => SSABasicBlock） */
    public array $blocks = [];

    public int $nextBlockId = 0;

    public function __construct(
        public string $name,
        public SSAType $retType,
        public array $paramTypes = [],
        public int $entryBlockId = -1,
        public int $nextValueId = 0,
    ) {}

    /**
     * 创建一个新 SSA value，返回其 id。
     */
    public function newValue(SSAType $type, ?string $name = null): int
    {
        $id = $this->nextValueId++;
        $this->values[$id] = new SSAValue($id, $type, $name);
        return $id;
    }

    /**
     * 创建一个新基本块，返回其 id。
     */
    public function newBlock(string $label): int
    {
        $id = $this->nextBlockId++;
        $this->blocks[$id] = new SSABasicBlock($id, $label);
        return $id;
    }

    /**
     * 向指定块追加一条指令。
     */
    public function appendInst(int $blockId, SSAInstruction $inst): void
    {
        $this->blocks[$blockId]->instructions[] = $inst;
    }

    /**
     * 添加 CFG 边：from -> to（同步维护前驱/后继列表）。
     */
    public function addEdge(int $from, int $to): void
    {
        $this->blocks[$from]->successors[] = $to;
        $this->blocks[$to]->predecessors[] = $from;
    }

    /**
     * 按 label 查找块 id（找不到返回 -1）。
     */
    public function findBlockByLabel(string $label): int
    {
        foreach ($this->blocks as $id => $block) {
            if ($block->label === $label) {
                return $id;
            }
        }
        return -1;
    }
}

// ═══════════════════════════════════════════════════════════════
// SSAModule — SSA 模块
//
//   - functions : 函数 id => SSAFunction
//   - globals    : 全局变量（名 => 元数据）
// ═══════════════════════════════════════════════════════════════
class SSAModule
{
    /** @var array<int, SSAFunction> */
    public array $functions = [];

    public array $globals = [];

    public int $nextFunctionId = 0;

    /**
     * 创建一个新函数，返回其 id。
     *
     * @param string        $name
     * @param SSAType[]     $paramTypes
     * @param SSAType       $retType
     */
    public function newFunction(string $name, array $paramTypes, SSAType $retType): int
    {
        $id = $this->nextFunctionId++;
        $this->functions[$id] = new SSAFunction($name, $retType, $paramTypes);
        return $id;
    }
}

// ═══════════════════════════════════════════════════════════════
// dumpSSAFunction — 文本输出（便于调试与测试断言）
//
// 格式示例：
//   function add(int, int) -> int {
//     block_0 [entry]:
//       %0 = CONST_INT 1
//       %1 = COPY %0
//       %2 = ADD %0, %1
//       RET %2
//   }
// ═══════════════════════════════════════════════════════════════
function dumpSSAFunction(SSAFunction $func): string
{
    $out = '';
    // 函数签名
    $params = implode(', ', array_map(fn(SSAType $t) => $t->label(), $func->paramTypes));
    $out .= "function {$func->name}({$params}) -> {$func->retType->label()} {\n";

    // 按 block id 顺序输出，entry 块优先
    $blockIds = array_keys($func->blocks);
    // 将 entry 块排到最前
    usort($blockIds, function (int $a, int $b) use ($func) {
        if ($a === $func->entryBlockId) return -1;
        if ($b === $func->entryBlockId) return 1;
        return $a <=> $b;
    });

    foreach ($blockIds as $bid) {
        $block = $func->blocks[$bid];
        $entryTag = ($bid === $func->entryBlockId) ? ' [entry]' : '';
        $preds = implode(', ', $block->predecessors);
        $out .= "  block_{$bid} ({$block->label}){$entryTag}; preds=[{$preds}]\n";
        foreach ($block->instructions as $inst) {
            $out .= '    ' . formatSSAInstruction($inst, $func) . "\n";
        }
    }

    $out .= "}\n";
    return $out;
}

/**
 * 格式化单条 SSA 指令为文本。
 */
function formatSSAInstruction(SSAInstruction $inst, SSAFunction $func): string
{
    $dstStr = $inst->dst !== null ? "%{$inst->dst}" : '';
    $opName = $inst->op->label();

    switch ($inst->op) {
        case OpCode::RET:
            return $inst->operands === []
                ? 'RET'
                : "RET %" . $inst->operands[0];

        case OpCode::BR:
            $cond = '%' . $inst->operands[0];
            $thenB = $inst->extra['then_block'] ?? -1;
            $elseB = $inst->extra['else_block'] ?? -1;
            return "BR {$cond}, block_{$thenB}, block_{$elseB}";

        case OpCode::JMP:
            $tgt = $inst->extra['target_block'] ?? -1;
            return "JMP block_{$tgt}";

        case OpCode::PHI:
            $parts = [];
            $blocks = $inst->extra['blocks'] ?? [];
            foreach ($inst->operands as $i => $v) {
                $b = $blocks[$i] ?? -1;
                $parts[] = "[%{$v} from block_{$b}]";
            }
            return "{$dstStr} = PHI " . implode(', ', $parts);

        case OpCode::CALL:
            $fn = $inst->extra['func'] ?? '?';
            $args = implode(', ', array_map(fn($v) => "%{$v}", $inst->operands));
            return $inst->dst !== null
                ? "{$dstStr} = CALL @{$fn}({$args})"
                : "CALL @{$fn}({$args})";

        case OpCode::CONST_INT:
            $v = $inst->extra['value'] ?? 0;
            return "{$dstStr} = CONST_INT {$v}";

        case OpCode::CONST_FLOAT:
            $v = $inst->extra['value'] ?? 0.0;
            return "{$dstStr} = CONST_FLOAT {$v}";

        case OpCode::CONST_BOOL:
            $v = $inst->extra['value'] ?? false;
            $vStr = $v ? 'true' : 'false';
            return "{$dstStr} = CONST_BOOL {$vStr}";

        case OpCode::CONST_NULL:
            return "{$dstStr} = CONST_NULL";

        case OpCode::COPY:
            return "{$dstStr} = COPY %" . $inst->operands[0];

        case OpCode::NEG:
        case OpCode::NOT:
            return "{$dstStr} = {$opName} %" . $inst->operands[0];

        case OpCode::LOAD:
            return "{$dstStr} = LOAD %" . $inst->operands[0];

        case OpCode::STORE:
            return "STORE %" . $inst->operands[0] . ", %" . $inst->operands[1];

        case OpCode::ALLOCA:
            $et = ($inst->extra['elem_type'] ?? null)?->label() ?? '?';
            $cnt = $inst->extra['count'] ?? 1;
            return "{$dstStr} = ALLOCA {$et}[{$cnt}]";

        case OpCode::CAST:
        case OpCode::BITCAST:
            $to = ($inst->extra['to_type'] ?? null)?->label() ?? '?';
            return "{$dstStr} = {$opName} %" . $inst->operands[0] . " to {$to}";

        // 二元运算
        case OpCode::ADD:
        case OpCode::SUB:
        case OpCode::MUL:
        case OpCode::DIV:
        case OpCode::MOD:
        case OpCode::AND:
        case OpCode::OR:
        case OpCode::EQ:
        case OpCode::NE:
        case OpCode::LT:
        case OpCode::LE:
        case OpCode::GT:
        case OpCode::GE:
            $l = '%' . $inst->operands[0];
            $r = '%' . $inst->operands[1];
            return "{$dstStr} = {$opName} {$l}, {$r}";

        default:
            return "{$dstStr} = {$opName}";
    }
}
