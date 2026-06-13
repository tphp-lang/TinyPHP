<?php

declare(strict_types=1);

namespace Tphp\CodeGen;

use Tphp\X64Builder;
use Tphp\AST\{
    TphpType, ExprNode, BinaryOpNode, IndexAccessNode,
    VarRefNode, StmtNode, ArrayLiteralNode,
    VarDeclNode, IfStmtNode, WhileStmtNode, ForStmtNode, SwitchStmtNode,
    ProgramNode, FunctionDeclNode, ClosureNode,
    ConstRefNode, ConstDeclNode, EnumAccessNode,
};

/**
 * Shared state and methods for both Linux and Windows code generators.
 *
 * Used as a trait by CodeGenerator and CodeGeneratorWindows to eliminate
 * ~300+ lines of duplicated type-inference, variable management, and
 * helper method code.
 */
trait BaseGenerator
{
    // ==================== Shared state ====================

    private X64Builder $b;

    /** @var array<string, array{offset:int, type:TphpType, elemType?:TphpType, returnType?:TphpType, className?:string}> */
    private array $vars = [];

    /** @var array<string, array{offset:int, type:TphpType, elemType?:TphpType, returnType?:TphpType}> */
    private array $calleeVars = [];

    private const int MAX_ARRAY_CAP = 64;

    private int $calleeStackOffset = 0;
    private int $labelCounter = 0;
    private int $frameSize = 0;
    private bool $isMain = false;

    // Helper labels
    private string $itoaLabel;
    private string $ftoaLabel;
    private string $atoiLabel;

    // ---- Multi-function / multi-file support ----
    private ?ProgramNode $program = null;
    /** @var array<string, string> function name → label */
    private array $functionLabels = [];
    /** @var array<string, FunctionDeclNode> */
    private array $functionNodes = [];
    /** @var array<string, string> short alias → FQN (from use function) */
    private array $importMap = [];
    /** @var array<string, string> class alias → FQN (from use ClassName) */
    private array $classImportMap = [];
    private string $currentNamespace = 'Main';

    /** @var array<string, AST\ConstDeclNode> constant name → declaration */
    private array $consts = [];
    /** @var array<string, array<string, int|string>> enumName → [caseName → value] */
    private array $enums = [];

    // ---- OOP support ----
    private string $currentClassName = '';
    /** @var string[] class names to call __destruct on */
    private array $pendingDestructors = [];
    private string $currentEpilogueLabel = '';

    /** @var string[] Stack of break target labels (for for/switch) */
    private array $breakLabels = [];

    // ==================== Helpers ====================

    private function newLabel(string $pfx = '.L'): string
    {
        return $pfx . ($this->labelCounter++);
    }

    // ==================== Memory layout ====================

    private function typeAllocSize(TphpType $type, ?TphpType $elemType = null): int
    {
        return match ($type) {
            TphpType::Int    => 8,
            TphpType::Float  => 8,
            TphpType::String => 16,  // ptr(8) + len(8)
            TphpType::Bool   => 8,   // 64-bit for stack alignment
            TphpType::Null   => 8,
            TphpType::Callable_ => 8,
            TphpType::Array_ => 16 + self::MAX_ARRAY_CAP * $this->typeAllocSize($elemType ?? TphpType::Int),
            default          => 8,
        };
    }

    // ==================== Variable collection ====================

    /** @param StmtNode[] $stmts */
    private function collectVariables(array $stmts): array
    {
        $vars = [];
        foreach ($stmts as $stmt) {
            if ($stmt instanceof VarDeclNode) {
                $typeInfo = $this->inferTypeInfo($stmt->init);
                $vars[$stmt->name] = $typeInfo;
            } elseif ($stmt instanceof IfStmtNode) {
                $vars = array_merge($vars, $this->collectVariables($stmt->thenBody));
                $vars = array_merge($vars, $this->collectVariables($stmt->elseBody));
            } elseif ($stmt instanceof WhileStmtNode) {
                $vars = array_merge($vars, $this->collectVariables($stmt->body));
            } elseif ($stmt instanceof ForStmtNode) {
                if ($stmt->init instanceof VarDeclNode) {
                    $typeInfo = $this->inferTypeInfo($stmt->init->init);
                    $vars[$stmt->init->name] = $typeInfo;
                }
                $vars = array_merge($vars, $this->collectVariables($stmt->body));
            } elseif ($stmt instanceof SwitchStmtNode) {
                foreach ($stmt->cases as $case) {
                    $vars = array_merge($vars, $this->collectVariables($case->body));
                }
                $vars = array_merge($vars, $this->collectVariables($stmt->defaultBody));
            }
        }
        return $vars;
    }

    // ==================== Type inference (shared) ====================

    /** @return array{type:TphpType, elemType?:TphpType, elemReturnTypes?:TphpType[], returnType?:TphpType} */
    private function inferTypeInfo(ExprNode $e): array
    {
        if ($e instanceof ArrayLiteralNode) {
            $result = ['type' => TphpType::Array_, 'elemType' => $e->elementType];
            if ($e->elementType === TphpType::Callable_) {
                $returnTypes = [];
                foreach ($e->elements as $elem) {
                    $returnTypes[] = ($elem instanceof ClosureNode) ? $elem->returnType : TphpType::Int;
                }
                $result['elemReturnTypes'] = $returnTypes;
            }
            return $result;
        }
        // For single closure, track its return type (used by Windows generator)
        if ($e instanceof ClosureNode) {
            return ['type' => TphpType::Callable_, 'returnType' => $e->returnType];
        }
        return ['type' => $this->inferType($e)];
    }

    private function inferIndexType(IndexAccessNode $e): TphpType
    {
        if ($e->target instanceof VarRefNode) {
            $info = $this->vars[$e->target->name] ?? null;
            if ($info !== null && isset($info['elemType'])) {
                return $info['elemType'];
            }
        }
        return TphpType::String;
    }

    private function inferBinType(BinaryOpNode $e): TphpType
    {
        if ($e->op === '.') return TphpType::String;
        $lt = $this->inferType($e->left);
        $rt = $this->inferType($e->right);
        if ($lt === TphpType::Float || $rt === TphpType::Float) return TphpType::Float;
        if (in_array($e->op, ['==', '===', '!=', '!==', '<', '>', '<=', '>='], true)) return TphpType::Bool;
        return TphpType::Int;
    }

    // ==================== Memory helpers ====================

    /** Load string (ptr+len) from memory at [addrReg] into rax:rdx */
    private function loadStringAtAddr(int $addrReg): void
    {
        $this->b->movRM64(self::b::RAX, $addrReg, 0);
        $this->b->movRM64(self::b::RDX, $addrReg, 8);
    }

    /** Load float from memory at [addrReg] into xmm0 */
    private function loadFloatAtAddr(int $addrReg): void
    {
        $this->b->movsdLoad(0, $addrReg, 0);
    }

    /** Store string (rax:rdx) to memory at [addrReg] */
    private function storeStringAt(int $addrReg): void
    {
        $this->b->movMR64($addrReg, 0, self::b::RAX);
        $this->b->movMR64($addrReg, 8, self::b::RDX);
    }

    /** Store float (xmm0) to memory at [addrReg] */
    private function storeFloatAt(int $addrReg): void
    {
        $this->b->movsdStore(0, $addrReg, 0);
    }

    // ==================== Const / Enum helpers ====================

    /** Generate code to read a constant's value */
    private function genConstRef(ConstRefNode $e): void
    {
        $c = $this->consts[$e->name] ?? null;
        if ($c === null) {
            throw new \RuntimeException("Undefined constant: {$e->name}, line {$e->line}");
        }
        $this->genExpr($c->init);
    }

    /** Infer type of a constant reference */
    private function inferConstType(ConstRefNode $e): TphpType
    {
        $c = $this->consts[$e->name] ?? null;
        if ($c === null) return TphpType::Int;
        return $this->inferType($c->init);
    }

    /** Infer type of enum access */
    private function inferEnumType(EnumAccessNode $e): TphpType
    {
        $enumFqn = $this->classImportMap[$e->enumName] ?? $e->enumName;
        $cases = $this->enums[$enumFqn] ?? [];
        $value = $cases[$e->caseName] ?? 0;
        return is_int($value) ? TphpType::Int : TphpType::String;
    }
}
