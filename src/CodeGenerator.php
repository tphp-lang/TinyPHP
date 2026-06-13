<?php

declare(strict_types=1);

namespace Tphp;

use Tphp\AST\{
    ProgramNode, FunctionDeclNode, ParamNode,
    StmtNode, VarDeclNode, ExprStmtNode, PrintStmtNode, ReturnStmtNode,
    IfStmtNode, WhileStmtNode, ForStmtNode, SwitchStmtNode, SwitchCaseNode,
    BreakStmtNode, UnsetStmtNode, ArrayAppendStmtNode,
    ExprNode, IntegerLiteralNode, FloatLiteralNode, StringLiteralNode,
    BoolLiteralNode, NullLiteralNode, VarRefNode, ConstRefNode, BinaryOpNode,
    FuncCallNode, IndexAccessNode, StringRangeNode, ArrayLiteralNode,
    ClosureNode, ExprCallNode, PostIncrementNode,
    TphpType, ConstDeclNode, CastExprNode,
    ClassDeclNode, MethodDeclNode, MethodCallNode, ThisExprNode, NewExprNode, EnumAccessNode,
};
use Tphp\CodeGen\BaseGenerator;
use Tphp\CodeGen\Linux\Output;
use Tphp\CodeGen\Linux\ControlFlow;
use Tphp\CodeGen\Linux\ArrayOps;
use Tphp\CodeGen\Linux\Helpers;

/**
 * AST → x86-64 machine code generator for Linux ELF (via direct syscalls).
 *
 * The generated code embeds string constants right after the main function body,
 * and uses RIP-relative addressing to access them.
 *
 * Helper routines (itoa, ftoa) are placed after the data section and jumped over.
 */
final class CodeGenerator
{
    use BaseGenerator;
    use Output;
    use ControlFlow;
    use ArrayOps;
    use Helpers;

    // ---- Linux-specific state (shared state in BaseGenerator trait) ----
    private int $varStackBase = 0;
    private string $itoaEndLabel;

    /** Built-in functions resolved directly */
    private const array BUILTINS = ['strlen', 'strpos', 'substr'];

    public function __construct()
    {
        $this->b = new X64Builder();
        $this->itoaLabel = '';
        $this->ftoaLabel = '';
        $this->atoiLabel = '';
        $this->itoaEndLabel = '';
    }

    public function getBuilder(): X64Builder { return $this->b; }

    // ==================== Entry ====================

    public function generate(ProgramNode $program): void
    {
        $this->program = $program;
        $this->functionLabels = [];
        $this->functionNodes = [];
        $this->importMap = [];
        $this->classImportMap = [];
        $this->consts = [];
        $this->currentClassName = '';
        $this->pendingDestructors = [];

        // Build const map
        foreach ($program->consts as $c) {
            $this->consts[$c->name] = $c;
        }

        // Build enum map
        $this->enums = [];
        foreach ($program->enums as $e) {
            $cases = [];
            foreach ($e->cases as $case) {
                $cases[$case->name] = $case->value;
            }
            $this->enums[$e->name] = $cases;
        }

        // Build function lookup map
        foreach ($program->functions as $fn) {
            $this->functionNodes[$fn->name] = $fn;
        }

        // Extract class methods as functions (mangled names like ClassName::method)
        // Reverse order so callee is generated before caller
        foreach ($program->classes as $cls) {
            $reversedMethods = array_reverse($cls->methods);
            foreach ($reversedMethods as $method) {
                $mangledName = $cls->name . '::' . $method->name;
                $fn = new FunctionDeclNode(
                    $method->line,
                    $mangledName,
                    $method->returnType,
                    $method->params,
                    $method->body,
                );
                $this->functionNodes[$mangledName] = $fn;
            }
        }

        // Build import map from 'use function' and 'use ClassName' statements
        foreach ($program->imports as $import) {
            if ($import->importType === 'function') {
                $this->importMap[$import->alias] = $import->fullName;
            } elseif ($import->importType === 'class') {
                $this->classImportMap[$import->alias] = $import->fullName;
            }
        }

        $mainFn = $this->functionNodes['main'] ?? $this->functionNodes['Main\\main'] ?? null;
        if ($mainFn === null) {
            throw new \RuntimeException("No main() function in namespace Main");
        }
        if ($mainFn->returnType !== TphpType::Void) {
            throw new \RuntimeException("main() must return void");
        }

        $this->itoaLabel = $this->newLabel('.L.itoa_helper');
        $this->ftoaLabel = $this->newLabel('.L.ftoa_helper');
        $this->atoiLabel = $this->newLabel('.L.atoi_helper');
        $this->itoaEndLabel = $this->newLabel('.L.helpers_end');

        // Jump over helpers + user functions to main
        $mainEntryLabel = $this->newLabel('.L.main_start');
        $this->b->jmpLabel($mainEntryLabel);

        // ---- Emit helper routines first ----
        $this->emitItoaHelper();
        $this->emitFtoaHelper();
        $this->emitAtoiHelper();

        // ---- Generate class methods first (before user functions that call them) ----
        // Phase 1: register all function labels (forward reference support)
        $generatedMethods = [];
        foreach ($this->functionNodes as $mangledName => $fn) {
            if (str_contains($mangledName, '::')) {
                $this->registerFunctionLabel($fn);
                $generatedMethods[] = $mangledName;
            }
        }
        foreach ($program->functions as $fn) {
            if ($fn->name === 'main') continue;
            $this->registerFunctionLabel($fn);
        }

        // Phase 2: generate function bodies
        foreach ($this->functionNodes as $mangledName => $fn) {
            if (str_contains($mangledName, '::')) {
                $this->generateFunctionBody2($fn);
            }
        }
        foreach ($program->functions as $fn) {
            if ($fn->name === 'main') continue;
            $this->generateFunctionBody2($fn);
        }

        // ---- Main entry ----
        $this->b->defineLabel($mainEntryLabel);
        $this->vars = [];
        $this->frameSize = 0;
        $this->isMain = true;
        $this->calleeVars = [];
        $this->calleeStackOffset = 0;
        $this->varStackBase = 0;
        $this->currentEpilogueLabel = '';
        $this->generateFunctionBody($mainFn);

        // Resolve all patches
        $this->b->resolvePatches();
    }

    // ==================== User-defined function generation (two-pass) ====================

    /** Phase 1: Register label only (for forward reference support) */
    private function registerFunctionLabel(FunctionDeclNode $fn): void
    {
        $label = $this->newLabel('.L.func.' . $fn->name);
        $this->functionLabels[$fn->name] = $label;
    }

    /** Phase 2: Generate prologue, body, and epilogue */
    private function generateFunctionBody2(FunctionDeclNode $fn): void
    {
        $label = $this->functionLabels[$fn->name];
        $this->b->defineLabel($label);

        // Track current namespace for call resolution
        $savedNamespace = $this->currentNamespace;
        $this->currentNamespace = $this->extractNamespace($fn->name);

        // Track current class if this is a class method (name like 'Demo\MyDemo::hello')
        $savedClassName = $this->currentClassName;
        if (str_contains($fn->name, '::')) {
            [$this->currentClassName] = explode('::', $fn->name, 2);
        }

        // Compute param offsets on stack (calleeVars holds param infos)
        $this->calleeVars = [];
        $paramOff = 0;
        foreach ($fn->params as $p) {
            $size = $this->typeAllocSize($p->type);
            $paramOff -= $size;
            $this->calleeVars[$p->name] = ['offset' => $paramOff, 'type' => $p->type];
        }
        $paramSpace = -$paramOff;

        // Collect body variables (below params)
        $bodyVars = $this->collectVariables($fn->body);
        $oldVars = $this->vars;
        $this->vars = [];
        $offset = $paramOff; // start below params

        foreach ($bodyVars as $name => $info) {
            $type = $info['type'];
            $elemType = $info['elemType'] ?? null;
            $size = $this->typeAllocSize($type, $elemType);
            $offset -= $size;
            $entry = ['offset' => $offset, 'type' => $type, 'elemType' => $elemType];
            if (isset($info['elemReturnTypes'])) {
                $entry['elemReturnTypes'] = $info['elemReturnTypes'];
            }
            $this->vars[$name] = $entry;
        }

        // Align frame
        $rawSize = -$offset;
        $frameSize = ($rawSize + 15) & ~15;
        if ($frameSize === 0 && $rawSize > 0) $frameSize = 16;

        $oldIsMain = $this->isMain;
        $oldFrameSize = $this->frameSize;
        $this->isMain = false;
        $this->frameSize = $frameSize;

        // ---- Prologue ----
        $this->b->pushReg(X64Builder::RBP);
        $this->b->emit("\x48\x89\xE5"); // mov rbp, rsp

        if ($frameSize > 0) {
            if ($frameSize < 128) {
                $this->b->emit("\x48\x83\xEC"); $this->b->emit8($frameSize);
            } else {
                $this->b->emit("\x48\x81\xEC"); $this->b->emit32($frameSize);
            }
        }

        // Store params from SysV registers (RDI, RSI, RDX, RCX, R8, R9)
        $paramRegs = [X64Builder::RDI, X64Builder::RSI, X64Builder::RDX, X64Builder::RCX, X64Builder::R8, X64Builder::R9];
        $ri = 0;
        foreach ($fn->params as $p) {
            $pOff = $this->calleeVars[$p->name]['offset'];
            if ($p->type === TphpType::String) {
                if ($ri + 1 >= 6) break;
                $this->b->movMR64(X64Builder::RBP, $pOff, $paramRegs[$ri]);
                $this->b->movMR64(X64Builder::RBP, $pOff + 8, $paramRegs[$ri + 1]);
                $ri += 2;
            } else {
                if ($ri >= 6) break;
                $this->b->movMR64(X64Builder::RBP, $pOff, $paramRegs[$ri]);
                $ri++;
            }
        }

        // Epilogue label (for return statements to jump to)
        $epilogueLabel = $this->newLabel('.L.epilogue.' . $fn->name);
        $this->currentEpilogueLabel = $epilogueLabel;

        // Generate body
        foreach ($fn->body as $stmt) {
            $this->genStmt($stmt);
        }

        // ---- Epilogue ----
        $this->b->defineLabel($epilogueLabel);
        if ($frameSize > 0) {
            if ($frameSize < 128) {
                $this->b->emit("\x48\x83\xC4"); $this->b->emit8($frameSize);
            } else {
                $this->b->emit("\x48\x81\xC4"); $this->b->emit32($frameSize);
            }
        }
        $this->b->emit("\x48\x89\xEC");   // mov rsp, rbp
        $this->b->popReg(X64Builder::RBP);
        $this->b->ret();

        // Restore state
        $this->isMain = $oldIsMain;
        $this->frameSize = $oldFrameSize;
        $this->vars = $oldVars;
        $this->calleeVars = [];
        $this->currentEpilogueLabel = '';
        $this->currentClassName = $savedClassName;
        $this->currentNamespace = $savedNamespace;
    }

    // ==================== Function body generator ====================

    private function generateFunctionBody(FunctionDeclNode $fn): void
    {
        // Collect variables & compute frame layout
        $bodyVars = $this->collectVariables($fn->body);
        $this->vars = [];
        $offset = 0;

        foreach ($bodyVars as $name => $info) {
            $type = $info['type'];
            $elemType = $info['elemType'] ?? null;
            $size = $this->typeAllocSize($type, $elemType);
            $offset -= $size;
            $entry = ['offset' => $offset, 'type' => $type, 'elemType' => $elemType];
            if (isset($info['elemReturnTypes'])) {
                $entry['elemReturnTypes'] = $info['elemReturnTypes'];
            }
            $this->vars[$name] = $entry;
        }

        // Align to 16 bytes
        $rawSize = -$offset;
        $this->frameSize = ($rawSize + 15) & ~15;
        if ($this->frameSize === 0 && $rawSize > 0) $this->frameSize = 16;

        // Emit prologue
        $this->b->pushReg(X64Builder::RBP);                 // 55
        $this->b->emit("\x48\x89\xE5");                      // 48 89 E5  mov rbp,rsp

        if ($this->frameSize > 0) {
            if ($this->frameSize < 128) {
                $this->b->emit("\x48\x83\xEC");              // 48 83 EC
                $this->b->emit8($this->frameSize);           // imm8
            } else {
                $this->b->emit("\x48\x81\xEC");              // 48 81 EC
                $this->b->emit32($this->frameSize);          // imm32
            }
        }

        // Generate body
        foreach ($fn->body as $stmt) {
            $this->genStmt($stmt);
        }

        // Call destructors for objects created in this function (main)
        foreach ($this->pendingDestructors as $clsName) {
            $dtorName = $clsName . '::__destruct';
            if (isset($this->functionNodes[$dtorName])) {
                $dtorCall = new FuncCallNode(0, $dtorName, []);
                $this->genUserFuncCall($dtorCall);
            }
        }
        $this->pendingDestructors = [];

        // Epilogue
        if ($this->frameSize > 0) {
            if ($this->frameSize < 128) {
                $this->b->emit("\x48\x83\xC4"); $this->b->emit8($this->frameSize);
            } else {
                $this->b->emit("\x48\x81\xC4"); $this->b->emit32($this->frameSize);
            }
        }
        $this->b->emit("\x48\x89\xEC");   // mov rsp, rbp
        $this->b->popReg(X64Builder::RBP); // pop rbp

        // Exit for main: sys_exit(0)
        if ($this->isMain) {
            // xor edi, edi ; mov eax, 60 ; syscall
            $this->b->xorRR(X64Builder::RDI, X64Builder::RDI);
            $this->b->movRI32(X64Builder::RAX, 60);
            $this->b->syscall();
        } else {
            $this->b->ret();
        }
    }

    private function typeAllocSize(TphpType $type, ?TphpType $elemType = null): int
    {
        return match ($type) {
            TphpType::Int    => 8,
            TphpType::Float  => 8,
            TphpType::String => 16,  // ptr(8) + len(8)
            TphpType::Bool   => 8,   // stored as 64-bit for stack alignment
            TphpType::Null   => 8,
            TphpType::Callable_ => 8,
            TphpType::Array_ => 16 + self::MAX_ARRAY_CAP * $this->typeAllocSize($elemType ?? TphpType::Int),
            default          => 8,
        };
    }

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
                // Collect for-init variable declaration
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

    /** @return array{type:TphpType, elemType?:TphpType, elemReturnTypes?:TphpType[]} */
    private function inferTypeInfo(ExprNode $e): array
    {
        if ($e instanceof ArrayLiteralNode) {
            $result = ['type' => TphpType::Array_, 'elemType' => $e->elementType];
            // For callable arrays, also track per-index closure return types
            if ($e->elementType === TphpType::Callable_) {
                $returnTypes = [];
                foreach ($e->elements as $elem) {
                    $returnTypes[] = ($elem instanceof ClosureNode) ? $elem->returnType : TphpType::Int;
                }
                $result['elemReturnTypes'] = $returnTypes;
            }
            return $result;
        }
        return ['type' => $this->inferType($e)];
    }

    // ==================== Type inference ====================

    private function inferType(ExprNode $e): TphpType
    {
        return match (true) {
            $e instanceof IntegerLiteralNode => TphpType::Int,
            $e instanceof FloatLiteralNode   => TphpType::Float,
            $e instanceof StringLiteralNode  => TphpType::String,
            $e instanceof BoolLiteralNode    => TphpType::Bool,
            $e instanceof NullLiteralNode    => TphpType::Null,
            $e instanceof NewExprNode        => TphpType::Int, // object handle
            $e instanceof EnumAccessNode    => $this->inferEnumType($e),
            $e instanceof ClosureNode        => TphpType::Callable_,
            $e instanceof ConstRefNode       => $this->inferConstType($e),
            $e instanceof CastExprNode       => $e->targetType,
            $e instanceof ArrayLiteralNode   => TphpType::Array_,
            $e instanceof BinaryOpNode       => $this->inferBinType($e),
            $e instanceof FuncCallNode       => $this->inferCallType($e),
            $e instanceof VarRefNode         => (
                $this->vars[$e->name]['type'] ??
                $this->calleeVars[$e->name]['type'] ??
                TphpType::Int
            ),
            $e instanceof IndexAccessNode    => $this->inferIndexType($e),
            $e instanceof StringRangeNode   => TphpType::String,
            $e instanceof ExprCallNode      => $this->inferExprCallType($e),
            default => TphpType::Int,
        };
    }

    private function inferBinType(BinaryOpNode $e): TphpType
    {
        if ($e->op === '.') return TphpType::String;
        $lt = $this->inferType($e->left);
        $rt = $this->inferType($e->right);
        if ($lt === TphpType::Float || $rt === TphpType::Float) return TphpType::Float;
        // Comparison ops → Bool
        if (in_array($e->op, ['==', '===', '!=', '!==', '<', '>', '<=', '>='], true)) return TphpType::Bool;
        return TphpType::Int;
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

    private function inferExprCallType(ExprCallNode $e): TphpType
    {
        // Try to determine return type by peeking at the callee expression.
        if ($e->callee instanceof IndexAccessNode) {
            $ia = $e->callee;
            if ($ia->target instanceof VarRefNode && $ia->index instanceof IntegerLiteralNode) {
                $varName = $ia->target->name;
                $varInfo = $this->vars[$varName] ?? null;
                if ($varInfo !== null && isset($varInfo['elemReturnTypes'])) {
                    $idx = (int)$ia->index->value;
                    if (isset($varInfo['elemReturnTypes'][$idx])) {
                        return $varInfo['elemReturnTypes'][$idx];
                    }
                }
            }
        }
        return TphpType::Int;
    }

    private function inferCallType(FuncCallNode $e): TphpType
    {
        $builtin = match ($e->name) {
            'strlen' => TphpType::Int,
            'strpos' => TphpType::Int,
            'substr' => TphpType::String,
            'count'  => TphpType::Int,
            default  => null,
        };
        if ($builtin !== null) return $builtin;

        // Resolve through import map
        $resolvedName = $this->importMap[$e->name] ?? $e->name;

        // User-defined function
        if (isset($this->functionNodes[$resolvedName])) {
            return $this->functionNodes[$resolvedName]->returnType;
        }

        return TphpType::Int;
    }

    // ==================== Statement generation ====================

    private function genStmt(StmtNode $s): void
    {
        switch (true) {
            case $s instanceof VarDeclNode:        $this->genVarDecl($s);   break;
            case $s instanceof PrintStmtNode:      $this->genPrint($s);     break;
            case $s instanceof ReturnStmtNode:     $this->genReturn($s);    break;
            case $s instanceof ExprStmtNode:       $this->genExpr($s->expr);break;
            case $s instanceof IfStmtNode:         $this->genIf($s);        break;
            case $s instanceof WhileStmtNode:      $this->genWhile($s);     break;
            case $s instanceof ForStmtNode:        $this->genFor($s);       break;
            case $s instanceof SwitchStmtNode:     $this->genSwitch($s);    break;
            case $s instanceof BreakStmtNode:      $this->genBreak($s);     break;
            case $s instanceof UnsetStmtNode:      $this->genUnset($s);     break;
            case $s instanceof ArrayAppendStmtNode:$this->genArrayAppend($s);break;
        }
    }

    private function genVarDecl(VarDeclNode $s): void
    {
        $info = $this->vars[$s->name] ?? null;
        if ($info === null) throw new \RuntimeException("Var {$s->name} not allocated, line {$s->line}");

        // Track class name for object variables
        if ($s->init instanceof NewExprNode) {
            $resolvedClass = $this->classImportMap[$s->init->className] ?? $s->init->className;
            $this->vars[$s->name]['className'] = $resolvedClass;
        }

        if ($info['type'] === TphpType::Array_) {
            $this->genArrayInit($s->name, $s->init);
            return;
        }

        $this->genExpr($s->init); // result in rax (and rdx for strings)

        match ($info['type']) {
            TphpType::String => $this->storeString($info['offset']),
            TphpType::Float => $this->storeFloat($info['offset']),
            default => $this->b->movMR64(X64Builder::RBP, $info['offset'], X64Builder::RAX),
        };
    }

    private function storeString(int $offset): void
    {
        // rax=ptr, rdx=len
        $this->b->movMR64(X64Builder::RBP, $offset, X64Builder::RAX);
        $this->b->movMR64(X64Builder::RBP, $offset + 8, X64Builder::RDX);
    }

    private function storeFloat(int $offset): void
    {
        // Float stored as IEEE 754 double bit pattern in rax,
        // but we also have xmm0. Store xmm0 to memory.
        $this->b->movsdStore(0, X64Builder::RBP, $offset);
    }

    // Output methods → CodeGen\Linux\Output trait
    // ==================== Expression generation ====================

    private function genExpr(ExprNode $e): void
    {
        switch (true) {
            case $e instanceof IntegerLiteralNode:
                $this->b->movRI64(X64Builder::RAX, $e->value);
                break;

            case $e instanceof FloatLiteralNode:
                // Store IEEE 754 bit pattern into rax
                $intBits = unpack('Q', pack('d', $e->value))[1];
                $this->b->movRI64(X64Builder::RAX, $intBits);
                $this->b->movqToXmm(0, X64Builder::RAX);
                break;

            case $e instanceof StringLiteralNode:
                $label = $this->b->addString($e->value);
                $this->b->emit("\x48\x8D\x05"); // lea rax, [rip+disp32]
                $this->b->rel32($label);
                $this->b->movRI32(X64Builder::RDX, strlen($e->value));
                break;

            case $e instanceof BoolLiteralNode:
                $this->b->movRI32(X64Builder::RAX, $e->value ? 1 : 0);
                break;

            case $e instanceof NullLiteralNode:
                $this->b->xorRR(X64Builder::RAX, X64Builder::RAX);
                break;

            case $e instanceof VarRefNode:
                $this->genVarRead($e);
                break;

            case $e instanceof BinaryOpNode:
                $this->genBinOp($e);
                break;

            case $e instanceof FuncCallNode:
                $this->genFuncCall($e);
                break;

            case $e instanceof IndexAccessNode:
                $this->genIndexAccess($e);
                break;

            case $e instanceof StringRangeNode:
                $this->genStringRange($e);
                break;

            case $e instanceof ClosureNode:
                $this->genClosure($e);
                break;

            case $e instanceof ArrayLiteralNode:
                // Array literals are handled during genVarDecl via genArrayInit
                break;

            case $e instanceof ExprCallNode:
                $this->genExprCall($e);
                break;

            case $e instanceof NewExprNode:
                $this->genNewExpr($e);
                break;

            case $e instanceof EnumAccessNode:
                $this->genEnumAccess($e);
                break;

            case $e instanceof MethodCallNode:
                $this->genMethodCall($e);
                break;

            case $e instanceof ThisExprNode:
                // $this → return 0 as dummy object handle (no fields yet)
                $this->b->xorRR(X64Builder::RAX, X64Builder::RAX);
                break;

            case $e instanceof PostIncrementNode:
                $this->genPostIncrement($e);
                break;

            case $e instanceof ConstRefNode:
                $this->genConstRef($e);
                break;

            case $e instanceof CastExprNode:
                $this->genCast($e);
                break;
        }
    }

    /**
     * Generate 'new ClassName()': call constructor and track for destructor.
     */
    private function genNewExpr(NewExprNode $e): void
    {
        $resolvedClass = $this->classImportMap[$e->className] ?? $e->className;
        $this->pendingDestructors[] = $resolvedClass;

        // Call constructor if it exists
        $ctorName = $resolvedClass . '::__construct';
        if (isset($this->functionNodes[$ctorName])) {
            $ctorCall = new FuncCallNode($e->line, $ctorName, []);
            $this->genUserFuncCall($ctorCall);
        }

        // Return dummy object handle
        $this->b->movRI32(X64Builder::RAX, 1);
    }

    /** Resolve MyInt::A → the case value at compile time */
    private function genEnumAccess(EnumAccessNode $e): void
    {
        $enumFqn = $this->classImportMap[$e->enumName] ?? $e->enumName;
        $cases = $this->enums[$enumFqn] ?? null;
        if ($cases === null || !isset($cases[$e->caseName])) {
            throw new \RuntimeException("Undefined enum case: {$e->enumName}::{$e->caseName}, line {$e->line}");
        }
        $value = $cases[$e->caseName];

        if (is_int($value)) {
            $this->b->movRI32(X64Builder::RAX, $value);
        } else {
            // Linux: strings embedded inline work with resolvePatches
            $label = $this->b->addString((string)$value);
            $this->b->emit("\x48\x8D\x05");
            $this->b->rel32($label);
            $this->b->movRI32(X64Builder::RDX, strlen((string)$value));
        }
    }

    /**
     * Generate method call: $obj->method(args) or $this->method(args).
     */
    private function genMethodCall(MethodCallNode $e): void
    {
        // Determine the class name
        $clsName = '';
        if ($e->object instanceof VarRefNode) {
            // $obj->method()
            $varInfo = $this->vars[$e->object->name] ?? $this->calleeVars[$e->object->name] ?? null;
            $clsName = $varInfo['className'] ?? '';
        } elseif ($e->object instanceof ThisExprNode) {
            // $this->method() — use current class context
            $clsName = $this->currentClassName;
        }

        if ($clsName !== '') {
            $mangledName = $clsName . '::' . $e->methodName;
            if (isset($this->functionNodes[$mangledName])) {
                $methodCall = new FuncCallNode($e->line, $mangledName, $e->args);
                $this->genUserFuncCall($methodCall);
                return;
            }
        }

        // Fallback: return 0 if method not found
        $this->b->xorRR(X64Builder::RAX, X64Builder::RAX);
    }

    private function genVarRead(VarRefNode $e): void
    {
        $info = $this->vars[$e->name] ?? $this->calleeVars[$e->name] ?? null;
        if ($info === null) throw new \RuntimeException("Undefined variable: \${$e->name}, line {$e->line}");

        if ($info['type'] === TphpType::String) {
            $this->b->movRM64(X64Builder::RAX, X64Builder::RBP, $info['offset']);     // ptr
            $this->b->movRM64(X64Builder::RDX, X64Builder::RBP, $info['offset'] + 8); // len
        } elseif ($info['type'] === TphpType::Float) {
            $this->b->movsdLoad(0, X64Builder::RBP, $info['offset']);
            $this->b->movqFromXmm(X64Builder::RAX, 0);
        } else {
            $this->b->movRM64(X64Builder::RAX, X64Builder::RBP, $info['offset']);
        }
    }

    private function genBinOp(BinaryOpNode $e): void
    {
        if ($e->op === '.') {
            $folded = $this->tryFold($e);
            if ($folded !== null) {
                $label = $this->b->addString($folded);
                $this->b->emit("\x48\x8D\x05"); $this->b->rel32($label);
                $this->b->movRI32(X64Builder::RDX, strlen($folded));
                return;
            }
            $leftEmpty  = $e->left instanceof StringLiteralNode && $e->left->value === '';
            $rightEmpty = $e->right instanceof StringLiteralNode && $e->right->value === '';
            if ($leftEmpty && $this->inferType($e->right) !== TphpType::String) {
                $this->genCast(new CastExprNode($e->line, TphpType::String, $e->right)); return;
            }
            if ($rightEmpty && $this->inferType($e->left) !== TphpType::String) {
                $this->genCast(new CastExprNode($e->line, TphpType::String, $e->left)); return;
            }
            $this->genExpr($e->left); $this->genExpr($e->right);
            return;
        }

        // Special: string char access vs 1-char literal → int comparison
        if (in_array($e->op, ['==', '===', '!=', '!=='], true)) {
            $handled = $this->tryStrCharCmp($e);
            if ($handled) return;
        }

        // Numeric: left → rax, right → rcx
        $lType = $this->inferType($e->left);
        $rType = $this->inferType($e->right);

        $this->genExpr($e->left);
        $this->b->pushReg(X64Builder::RAX); // save left
        $this->genExpr($e->right);          // rax = right

        // If float, need FP operations
        if ($lType === TphpType::Float || $rType === TphpType::Float) {
            $this->b->popReg(X64Builder::RCX);
            // rcx = left bit pattern, rax = right bit pattern
            // Move to xmm registers
            $this->b->movqToXmm(0, X64Builder::RCX); // xmm0 = left
            $this->b->movqToXmm(1, X64Builder::RAX); // xmm1 = right

            match ($e->op) {
                '+' => $this->b->emit("\xF2\x0F\x58\xC1"),     // addsd xmm0, xmm1
                '-' => $this->b->emit("\xF2\x0F\x5C\xC1"),     // subsd xmm0, xmm1
                '*' => $this->b->emit("\xF2\x0F\x59\xC1"),     // mulsd xmm0, xmm1
                '/' => $this->b->emit("\xF2\x0F\x5E\xC1"),     // divsd xmm0, xmm1
                '==', '===', '<', '>', '<=', '>=' => $this->genFloatCmp($e->op),
                default => null,
            };
            $this->b->movqFromXmm(X64Builder::RAX, 0);
            return;
        }

        $this->b->popReg(X64Builder::RCX); // rcx = left

        match ($e->op) {
            '+'  => $this->b->addRR(X64Builder::RAX, X64Builder::RCX),
            '-'  => $this->emitSubRCX_RAX(),
            '*'  => $this->b->emit("\x48\x0F\xAF\xC1"),      // imul rax,rcx
            '/'  => $this->emitDiv(),
            '%'  => $this->emitMod(),
            '=='  => $this->emitICmp('e'),
            '===' => $this->emitICmp('e'),
            '!='  => $this->emitICmp('ne'),
            '!==' => $this->emitICmp('ne'),
            '<'   => $this->emitICmp('l'),
            '>'  => $this->emitICmp('g'),
            '<=' => $this->emitICmp('le'),
            '>=' => $this->emitICmp('ge'),
            default => null,
        };
    }

    // rcx = rcx - rax; mov rax, rcx
    private function emitSubRCX_RAX(): void
    {
        $this->b->subRR(X64Builder::RCX, X64Builder::RAX);
        $this->b->movRR(X64Builder::RAX, X64Builder::RCX);
    }

    private function emitDiv(): void
    {
        // rcx = left (dividend), rax = right (divisor)
        // Need: dividend in rdx:rax, divisor somewhere else
        // Swap: mov rax, rcx (dividend); push dividend
        // Actually left is dividend, right is divisor
        // rcx=dividend, rax=divisor
        // We need: rax=dividend, then sign-extend to rdx:rax, then idiv divisor
        // divisor is currently in rax, dividend in rcx
        // Move divisor to r8, dividend to rax
        $this->b->movRR(X64Builder::R8, X64Builder::RAX);  // r8 = divisor
        $this->b->movRR(X64Builder::RAX, X64Builder::RCX); // rax = dividend
        $this->b->emit("\x48\x99");                          // cqo (sign-extend rax→rdx:rax)
        $this->b->emit("\x49\xF7\xF8");                      // idiv r8 (rax=quotient)
    }

    private function emitMod(): void
    {
        $this->b->movRR(X64Builder::R8, X64Builder::RAX);  // r8 = divisor
        $this->b->movRR(X64Builder::RAX, X64Builder::RCX); // rax = dividend
        $this->b->emit("\x48\x99");                          // cqo
        $this->b->emit("\x49\xF7\xF8");                      // idiv r8
        $this->b->movRR(X64Builder::RAX, X64Builder::RDX); // rax = remainder
    }

    private function emitICmp(string $cc): void
    {
        $this->b->cmpRR(X64Builder::RCX, X64Builder::RAX);
        $setMap = [
            'e'  => "\x0F\x94\xC0",  // sete al
            'ne' => "\x0F\x95\xC0",  // setne al
            'l'  => "\x0F\x9C\xC0",  // setl al
            'g'  => "\x0F\x9F\xC0",  // setg al
            'le' => "\x0F\x9E\xC0",  // setle al
            'ge' => "\x0F\x9D\xC0",  // setge al
        ];
        if (isset($setMap[$cc])) $this->b->emit($setMap[$cc]);
        $this->b->movzxRR(X64Builder::RAX, X64Builder::RAX);
    }

    /** Check if $e is a non-array string IndexAccessNode ($str[$idx]) that yields a char (int). */
    private function isStrCharAccess(ExprNode $e): bool
    {
        if (!$e instanceof IndexAccessNode) return false;
        if (!$e->target instanceof VarRefNode) return false;
        $info = $this->vars[$e->target->name] ?? $this->calleeVars[$e->target->name] ?? null;
        if ($info === null) return false;
        return !isset($info['elemType']); // string variable, not array
    }

    /**
     * Handle: string[$i] == "X"  →  compare char code with ASCII('X').
     * Converts the single-char string literal side to its ord value.
     */
    private function tryStrCharCmp(BinaryOpNode $e): bool
    {
        $leftChar  = $this->isStrCharAccess($e->left);
        $rightChar = $this->isStrCharAccess($e->right);
        $leftStr   = $e->left  instanceof StringLiteralNode && strlen($e->left->value) === 1;
        $rightStr  = $e->right instanceof StringLiteralNode && strlen($e->right->value) === 1;

        if (!(($leftChar && $rightStr) || ($rightChar && $leftStr))) {
            return false;
        }

        if ($leftChar && $rightStr) {
            // $str[$i] == "X"
            $this->genExpr($e->left);                        // RAX = char code (int)
            $this->b->movRR(X64Builder::RCX, X64Builder::RAX); // RCX = char code
            $this->b->movRI32(X64Builder::RAX, ord($e->right->value)); // RAX = ASCII
        } else {
            // "X" == $str[$i]
            $this->b->movRI32(X64Builder::RCX, ord($e->left->value)); // RCX = ASCII
            $this->genExpr($e->right);                       // RAX = char code (int)
        }

        $cmpMap = [
            '=='  => 'e',  '===' => 'e',
            '!='  => 'ne', '!==' => 'ne',
        ];
        $cc = $cmpMap[$e->op] ?? 'e';
        $this->emitICmp($cc);
        return true;
    }

    private function genFloatCmp(string $op): void
    {
        // xmm0 = left, xmm1 = right
        // comisd xmm0, xmm1
        $this->b->emit("\x66\x0F\x2F\xC1"); // comisd xmm0, xmm1

        $falseLab = $this->newLabel('.L.fcmp0');
        $endLab   = $this->newLabel('.L.fcmp1');

        match ($op) {
            '==' => $this->b->emit("\x7A\x06\xEB\x04"), // jp false; jz end? No...
            '!=' => null,
            '<'  => null,
            '>'  => null,
            '<=' => null,
            '>=' => null,
            default => null,
        };

        // For float comparison, we need to handle the flags properly
        // Simplified: just set al = 1 or 0
        // comisd sets: ZF, PF, CF
        // PF=1 if unordered (NaN)
        // ZF=1 if equal
        // CF=1 if left < right

        match ($op) {
            '==' => $this->b->emit("\x7A\x04\x0F\x94\xC0\xEB\x06\xB0\x00\xEB\x02\xB0\x01"),
            // Actually let's use a cleaner approach
            default => $this->b->movRI32(X64Builder::RAX, 0),
        };

        $this->b->defineLabel($endLab);
    }

    // ==================== Function calls ====================

    private function genFuncCall(FuncCallNode $e): void
    {
        if ($e->name === 'strlen') {
            $this->genStrlen($e);
            return;
        }
        if ($e->name === 'count') {
            $this->genCount($e);
            return;
        }
        // Closure variable call: $g(1, 2)
        if (str_starts_with($e->name, '$')) {
            $this->genClosureCall($e);
            return;
        }
        // Resolve: 1. import map, 2. same-namespace, 3. global (empty-ns)
        $resolvedName = $this->importMap[$e->name] ?? null;

        if ($resolvedName === null && $this->currentNamespace !== '') {
            $candidate = $this->currentNamespace . '\\' . $e->name;
            if (isset($this->functionNodes[$candidate])) {
                $resolvedName = $candidate;
            }
        }

        if ($resolvedName === null && isset($this->functionNodes[$e->name])) {
            $resolvedName = $e->name; // global (empty-namespace)
        }

        if ($resolvedName !== null) {
            $resolvedCall = new FuncCallNode($e->line, $resolvedName, $e->args);
            $this->genUserFuncCall($resolvedCall);
            return;
        }
        // Other builtins
        $this->b->xorRR(X64Builder::RAX, X64Builder::RAX);
    }

    /**
     * Extract namespace from a function's fully qualified name.
     * "Other\otherFn" → "Other", "main" → "Main"
     */
    private function extractNamespace(string $fqn): string
    {
        $pos = strrpos($fqn, '\\');
        if ($pos !== false) {
            return substr($fqn, 0, $pos);
        }
        if (str_contains($fqn, '::')) {
            return $this->currentNamespace;
        }
        return 'Main';
    }

    /**
     * Emit call to a user-defined function using SysV AMD64 convention.
     */
    private function genUserFuncCall(FuncCallNode $e): void
    {
        $label = $this->functionLabels[$e->name]
            ?? throw new \RuntimeException("Function '{$e->name}' not found, line {$e->line}");

        $fn = $this->functionNodes[$e->name];

        // Set up args in SysV AMD64 convention
        $paramRegs = [X64Builder::RDI, X64Builder::RSI, X64Builder::RDX, X64Builder::RCX, X64Builder::R8, X64Builder::R9];
        $ri = 0;
        foreach ($e->args as $arg) {
            $argType = $this->inferType($arg);
            $this->genExpr($arg);

            if ($argType === TphpType::String) {
                if ($ri >= 5) break; // need 2 regs
                // Save ptr
                if ($paramRegs[$ri] !== X64Builder::RAX) {
                    $this->b->movRR($paramRegs[$ri], X64Builder::RAX);
                }
                // Save len
                if ($paramRegs[$ri + 1] !== X64Builder::RDX) {
                    $this->b->movRR($paramRegs[$ri + 1], X64Builder::RDX);
                }
                $ri += 2;
            } else {
                if ($ri >= 6) break;
                if ($paramRegs[$ri] !== X64Builder::RAX) {
                    $this->b->movRR($paramRegs[$ri], X64Builder::RAX);
                }
                $ri++;
            }
        }

        // call rel32
        $this->b->emit("\xE8");
        $this->b->rel32($label);
    }

    private function genClosureCall(FuncCallNode $e): void
    {
        // Load function pointer into R11 (volatile, not clobbered by genExpr)
        $varInfo = $this->vars[$e->name]
            ?? throw new \RuntimeException("Undefined variable: {$e->name}, line {$e->line}");
        $this->b->movRM64(X64Builder::R11, X64Builder::RBP, $varInfo['offset']);

        // Set up args in System V AMD64 convention (string = 2 regs)
        $this->emitClosureArgs($e->args);

        // Call through function pointer
        $this->b->emit("\x41\xFF\xD3");          // call r11
    }

    private function genStrlen(FuncCallNode $e): void
    {
        $arg = $e->args[0] ?? throw new \RuntimeException("strlen requires 1 arg, line {$e->line}");

        if ($arg instanceof VarRefNode) {
            $off = $this->vars[$arg->name]['offset'] ?? throw new \RuntimeException("Undefined: {$arg->name}");
            // Load string length from struct at offset+8
            $this->b->movRM64(X64Builder::RAX, X64Builder::RBP, $off + 8);
        } elseif ($arg instanceof StringLiteralNode) {
            $this->b->movRI32(X64Builder::RAX, strlen($arg->value));
        } else {
            // General: evaluate expression, result in rdx (len)
            $this->genExpr($arg);
            $this->b->movRR(X64Builder::RAX, X64Builder::RDX);
        }
    }

    // ==================== Index access ====================

    private function genIndexAccess(IndexAccessNode $e): void
    {
        if ($e->target instanceof VarRefNode) {
            $info = $this->vars[$e->target->name]
                ?? throw new \RuntimeException("Undefined: \${$e->target->name}");

            // Check if it's an array access
            if (isset($info['elemType'])) {
                $this->genArrayIndexRead($info, $e->index);
                return;
            }

            // String index access
            $this->b->movRM64(X64Builder::RAX, X64Builder::RBP, $info['offset']);
        } else {
            $this->genExpr($e->target);
        }

        // Save ptr
        $this->b->movRR(X64Builder::RCX, X64Builder::RAX);
        // Evaluate index
        $this->genExpr($e->index); // rax = index
        // rcx = ptr + index
        $this->b->addRR(X64Builder::RCX, X64Builder::RAX);
        // mov al, [rcx]
        $this->b->emit("\x8A\x01");
        $this->b->movzxRR(X64Builder::RAX, X64Builder::RAX);
    }

    // ArrayOps methods → CodeGen\Linux\ArrayOps trait
    // ==================== Closure ====================

    private function genClosure(ClosureNode $e): void
    {
        $funcLab = $this->newLabel('.L.clos');
        $afterLab = $this->newLabel('.L.aclos');

        $this->b->jmpLabel($afterLab);
        $this->b->defineLabel($funcLab);

        // Prologue
        $this->b->pushReg(X64Builder::RBP);
        $this->b->emit("\x48\x89\xE5");   // mov rbp, rsp

        $oldVars = $this->vars;
        $oldCallee = $this->calleeVars;
        $oldStack = $this->calleeStackOffset;

        // Set up calleeVars for closure params
        $this->calleeVars = [];
        $paramOff = 0;
        foreach ($e->params as $p) {
            $size = $this->typeAllocSize($p->type);
            $paramOff -= $size;
            $this->calleeVars[$p->name] = ['offset' => $paramOff, 'type' => $p->type];
        }

        // Allocate stack space for params (16-byte aligned)
        $closureFrame = (-$paramOff + 15) & ~15;
        if ($closureFrame > 0) {
            $this->b->emit("\x48\x83\xEC");   // sub rsp, imm8
            $this->b->emit8($closureFrame);
        }

        // Store incoming params (System V AMD64: RDI, RSI, RDX, RCX, R8, R9)
        // String params consume 2 consecutive registers (ptr + len)
        $paramRegs = [X64Builder::RDI, X64Builder::RSI, X64Builder::RDX, X64Builder::RCX, X64Builder::R8, X64Builder::R9];
        $ri = 0;
        foreach ($e->params as $p) {
            $off = $this->calleeVars[$p->name]['offset'];
            if ($p->type === TphpType::String) {
                if ($ri + 1 >= 6) break;
                $this->b->movMR64(X64Builder::RBP, $off,     $paramRegs[$ri]);     // ptr
                $this->b->movMR64(X64Builder::RBP, $off + 8, $paramRegs[$ri + 1]); // len
                $ri += 2;
            } else {
                if ($ri >= 6) break;
                $this->b->movMR64(X64Builder::RBP, $off, $paramRegs[$ri]);
                $ri++;
            }
        }

        $this->calleeStackOffset = 0;

        foreach ($e->body as $stmt) {
            if ($stmt instanceof ReturnStmtNode && $stmt->expr !== null) {
                $this->genExpr($stmt->expr);
            } else {
                $this->genStmt($stmt);
            }
        }

        $this->calleeVars = $oldCallee;
        $this->calleeStackOffset = $oldStack;
        $this->vars = $oldVars;

        // Epilogue
        if ($closureFrame > 0) {
            $this->b->emit("\x48\x83\xC4");   // add rsp, imm8
            $this->b->emit8($closureFrame);
        }
        $this->b->emit("\x48\x89\xEC");   // mov rsp, rbp
        $this->b->popReg(X64Builder::RBP);
        $this->b->ret();

        $this->b->defineLabel($afterLab);
        // rax = closure function pointer (rip-relative)
        $this->b->emit("\x48\x8D\x05"); $this->b->rel32($funcLab);
    }

    // ControlFlow methods → CodeGen\Linux\ControlFlow trait
    // ==================== ConstRef ====================

    /** Inline a constant's value at the usage site. */
    // ==================== Type Cast ====================

    private function genCast(CastExprNode $e): void
    {
        $srcType = $this->inferType($e->operand);

        // 对象不能转换为 int
        if ($this->isObjectExpr($e->operand)) {
            throw new \RuntimeException(
                "Object cannot be converted to int at line {$e->line}"
            );
        }

        if ($e->targetType === TphpType::Int) {
            // Fast path: string literal non-digit start → result 0
            if ($srcType === TphpType::String && $e->operand instanceof StringLiteralNode) {
                $s = $e->operand->value;
                if ($s === '' || ($s[0] !== '-' && $s[0] !== '+' && ($s[0] < '0' || $s[0] > '9'))) {
                    $this->b->xorRR(X64Builder::RAX, X64Builder::RAX);
                    return;
                }
            }

            $this->genExpr($e->operand); // result in RAX (xmm0 for float)
            match ($srcType) {
                TphpType::Float  => $this->genFloatToInt(),
                TphpType::String => $this->genStringToInt(),
                TphpType::Array_ => $this->genArrayToInt(),
                default => null, // Bool, Null, Int → already int in RAX
            };
        }

        if ($e->targetType === TphpType::Float) {
            $this->genExpr($e->operand);
            if ($srcType === TphpType::Int || $srcType === TphpType::Bool) { $this->b->emit("\xF2\x48\x0F\x2A\xC0"); }
            elseif ($srcType === TphpType::Float) { } else { $this->b->emit("\x0F\x57\xC0"); }
        }

        if ($e->targetType === TphpType::String) {
            if ($srcType === TphpType::Array_) { throw new \RuntimeException("Array cannot be converted to string at line {$e->line}"); }
            $this->genExpr($e->operand);
            if ($srcType === TphpType::Int || $srcType === TphpType::Bool) { $this->b->emit("\x45\x31\xC9"); $this->b->emit("\xE8"); $this->b->rel32($this->itoaLabel); $this->b->movRR(X64Builder::RAX, X64Builder::RSI); }
            elseif ($srcType === TphpType::Float) { $this->b->movqToXmm(0, X64Builder::RAX); $this->b->emit("\xE8"); $this->b->rel32($this->ftoaLabel); $this->b->movRR(X64Builder::RAX, X64Builder::RSI); }
            elseif ($srcType === TphpType::Null) { $this->b->xorRR(X64Builder::RAX, X64Builder::RAX); $this->b->xorRR(X64Builder::RDX, X64Builder::RDX); }
        }
    }

    /** cvttsd2si rax, xmm0 — truncate float to int */
    private function genFloatToInt(): void
    {
        $this->b->emit("\xF2\x48\x0F\x2C\xC0"); // cvttsd2si rax, xmm0
    }

    /** Call shared atoi helper: RDI=ptr, RSI=len → RAX=int */
    private function genStringToInt(): void
    {
        $this->b->movRR(X64Builder::RDI, X64Builder::RAX);
        $this->b->movRR(X64Builder::RSI, X64Builder::RDX);
        $this->b->emit("\xE8");
        $this->b->rel32($this->atoiLabel);
    }

    /** Print (int)$array → '0' or '1' inline, bypassing itoa */
    private function genArrayIntPrint(int $offset): void
    {
        $zeroL = $this->newLabel('.L.aip0');
        $doneL = $this->newLabel('.L.aip1');

        $this->b->movRM64(X64Builder::RAX, X64Builder::RBP, $offset);
        $this->b->emit("\x48\x85\xC0");  // test rax, rax
        $this->b->jeLabel($zeroL);

        $lab1 = $this->b->addString('1');
        $this->b->emit("\x48\x8D\x35"); $this->b->rel32($lab1);
        $this->b->movRI32(X64Builder::RDX, 1);
        $this->doWrite(X64Builder::RSI, X64Builder::RDX);
        $this->b->jmpLabel($doneL);

        $this->b->defineLabel($zeroL);
        $lab0 = $this->b->addString('0');
        $this->b->emit("\x48\x8D\x35"); $this->b->rel32($lab0);
        $this->b->movRI32(X64Builder::RDX, 1);
        $this->doWrite(X64Builder::RSI, X64Builder::RDX);

        $this->b->defineLabel($doneL);
    }

    /** Check if expression represents an object instance. */
    private function isObjectExpr(ExprNode $e): bool
    {
        if ($e instanceof NewExprNode) {
            return true;
        }
        if ($e instanceof VarRefNode) {
            $info = $this->vars[$e->name] ?? $this->calleeVars[$e->name] ?? null;
            return ($info !== null && isset($info['className']));
        }
        return false;
    }

    /** Evaluate (int)expr at compile time if result is known to be 0. Returns 0 or null. */
    private function evalCastToZero(CastExprNode $e): ?int
    {
        if ($e->operand instanceof BoolLiteralNode) {
            return $e->operand->value ? null : 0;
        }
        if ($e->operand instanceof NullLiteralNode) {
            return 0;
        }
        if ($e->operand instanceof StringLiteralNode) {
            $s = $e->operand->value;
            if ($s === '' || ($s[0] !== '-' && $s[0] !== '+' && ($s[0] < '0' || $s[0] > '9'))) {
                return 0;
            }
        }
        return null;
    }

    /** Array len → 0 if empty, 1 if non-empty */
    private function genArrayToInt(): void
    {
        $zeroL = $this->newLabel('.L.cast.arr0');
        $doneL = $this->newLabel('.L.cast.arr1');
        $this->b->testRR(X64Builder::RAX, X64Builder::RAX);
        $this->b->jeLabel($zeroL);
        $this->b->movRI32(X64Builder::RAX, 1);
        $this->b->jmpLabel($doneL);
        $this->b->defineLabel($zeroL);
        $this->b->xorRR(X64Builder::RAX, X64Builder::RAX);
        $this->b->defineLabel($doneL);
    }

    // ==================== Return ====================

    private function genReturn(ReturnStmtNode $s): void
    {
        if ($s->expr !== null) $this->genExpr($s->expr);
        // In user-defined functions, jump to shared epilogue
        if ($this->currentEpilogueLabel !== '') {
            $this->b->jmpLabel($this->currentEpilogueLabel);
        }
    }

    // Helpers (itoa, ftoa, atoi) are in CodeGen\Linux\Helpers trait
}
