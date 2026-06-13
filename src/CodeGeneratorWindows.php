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
    TphpType, ConstDeclNode, CastExprNode, ExternFuncNode, CFuncCallNode,
    ClassDeclNode, MethodDeclNode, MethodCallNode, ThisExprNode, NewExprNode, EnumAccessNode,
};
use Tphp\CodeGen\BaseGenerator;
use Tphp\CodeGen\Windows\Output;
use Tphp\CodeGen\Windows\ControlFlow;
use Tphp\CodeGen\Windows\ArrayOps;
use Tphp\CodeGen\Windows\Helpers;
use Tphp\CodeGen\Windows\FFI;

/**
 * AST → x86-64 machine code generator for **Windows PE**.
 *
 * Uses Windows x64 calling convention with kernel32.dll IAT calls:
 *   - GetStdHandle(STD_OUTPUT_HANDLE)  → get stdout handle
 *   - WriteFile(h, buf, len, &n, NULL) → write to console
 *   - ExitProcess(0)                   → clean exit
 *
 * Memory safety:
 *   - All stack operations are 16-byte aligned
 *   - Shadow space (32 bytes) allocated before each API call
 *   - String buffers are null-terminated and length-checked
 *   - Integer arithmetic bounds-checked (64-bit)
 */
final class CodeGeneratorWindows
{
    use BaseGenerator;
    use Output;
    use ControlFlow;
    use ArrayOps;
    use Helpers;
    use FFI;

    /** IAT RVAs (fixed layout, MUST match PEWriter::IAT_RVA) */
    public const int IAT_BASE_RVA          = 0x3070;
    public const int IAT_LOADLIBRARYA      = 0x3070;
    public const int IAT_GETPROCADDRESS    = 0x3078;
    public const int IAT_GETSTDHANDLE      = 0x3080;
    public const int IAT_WRITEFILE         = 0x3088;
    public const int IAT_EXITPROCESS       = 0x3090;
    public const int IAT_SETCONSOLEOUTPUTCP = 0x3098;
    public const int IAT_HEAPALLOC         = 0x30A0;
    public const int IAT_GETPROCESSHEAP    = 0x30A8;
    public const int IAT_HEAPFREE         = 0x30B0;

    /** Handle to stdout (cached in a stack variable for main) */
    private int $stdoutStackOffset = 0;
    /** Offset of 32-byte itoa buffer on the main frame (avoids shadow-space clobber) */
    private int $itoaBufOffset = 0;
    private bool $stdoutAcquired = false;

    private function isStringEnum(string $fqn): bool
    {
        $cases = $this->enums[$fqn] ?? [];
        if (empty($cases)) return false;
        $first = reset($cases);
        return is_string($first);
    }
    /** @var array<string, ExternFuncNode> extern function name → declaration */
    private array $externDecls = [];
    /** @var array<string, string> extern function name → function pointer variable label */
    private array $externPtrLabels = [];
    /** @var string[] DLL paths from -lib option */
    private array $libPaths = [];

    /** @var int[] RBP-relative offsets of heap-allocated string variables to free at epilogue */
    private array $heapVarOffsets = [];

    public function __construct()
    {
        $this->b = new X64Builder();
        $this->b->setWindowsMode(true);
        $this->itoaLabel = '';
        $this->ftoaLabel = '';
        $this->atoiLabel = '';
    }

    /** @param string[] $paths */
    public function setLibPaths(array $paths): void { $this->libPaths = $paths; }

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

        // Build extern map and allocate function pointer slots
        foreach ($program->externs as $ext) {
            $this->externDecls[$ext->name] = $ext;
            $this->externPtrLabels[$ext->name] = $this->newLabel('.L.extfn.' . $ext->name);
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

        $this->itoaLabel = $this->newLabel('.L.itoa_helper');
        $this->ftoaLabel = $this->newLabel('.L.ftoa_helper');
        $this->atoiLabel = $this->newLabel('.L.atoi_helper');
        $mainEntryLabel  = $this->newLabel('.L.main_start');

        // Jump over helpers placed before main code
        $this->b->jmpLabel($mainEntryLabel);

        // ---- Emit helper routines ----
        $this->emitItoaHelper();
        $this->emitFtoaHelper();
        $this->emitAtoiHelper();
        $this->emitExternStorage(); // Reserve FFI function pointer slots (before user functions)

        // ---- Generate class methods first (before user functions that call them) ----
        // Phase 1: register all function labels (forward reference support)
        foreach ($this->functionNodes as $mangledName => $fn) {
            if (str_contains($mangledName, '::')) {
                $this->registerFunctionLabel($fn);
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
        $this->heapVarOffsets = [];
        $this->frameSize = 0;
        $this->isMain = true;
        $this->stdoutAcquired = false;
        $this->stdoutStackOffset = 0;
        $this->currentEpilogueLabel = '';
        $this->generateFunctionBody($mainFn);

        // Resolve label patches (but NOT string embedding - PEWriter handles that)
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

        // Track current class if this is a class method
        $savedClassName = $this->currentClassName;
        if (str_contains($fn->name, '::')) {
            [$this->currentClassName] = explode('::', $fn->name, 2);
        }

        // Compute param space
        $paramSpace = 0;
        foreach ($fn->params as $p) {
            $paramSpace += $this->typeAllocSize($p->type);
        }

        // Collect body variables
        $bodyVars = $this->collectVariables($fn->body);
        $bodyVarSize = 0;
        foreach ($bodyVars as $name => $info) {
            $type = $info['type'];
            $elemType = $info['elemType'] ?? null;
            $bodyVarSize += $this->typeAllocSize($type, $elemType);
        }

        // Save outer state
        $oldVars = $this->vars;
        $oldCalleeVars = $this->calleeVars;
        $oldIsMain = $this->isMain;
        $savedStdoutOff = $this->stdoutStackOffset;
        $savedItBufOff = $this->itoaBufOffset;
        $oldHeapVarOffsets = $this->heapVarOffsets;

        $this->isMain = false;
        $this->heapVarOffsets = [];

        // ---- Prologue ----
        $this->b->pushReg(X64Builder::RBP);
        $this->b->emit("\x48\x89\xE5");   // mov rbp, rsp

        // Save non-volatile registers R12-R15
        $this->b->pushReg(X64Builder::R12);
        $this->b->pushReg(X64Builder::R13);
        $this->b->pushReg(X64Builder::R14);
        $this->b->pushReg(X64Builder::R15);

        // Allocate stack space: itoa(48) + stdout(8) + written(8) + params + local vars
        $closureFrame = $paramSpace + $bodyVarSize + 64; // 48 itoa + 8 stdout + 8 written
        $closureFrame = (($closureFrame + 15) & ~15);

        if ($closureFrame > 0) {
            if ($closureFrame < 128) {
                $this->b->emit("\x48\x83\xEC"); $this->b->emit8($closureFrame);
            } else {
                $this->b->emit("\x48\x81\xEC"); $this->b->emit32($closureFrame);
            }
        }

        // Compute offsets from rbp (40 = 8+32 from push rbp + r12-r15)
        $this->itoaBufOffset     = -$closureFrame - 40;       // itoa buffer
        $this->stdoutStackOffset = -$closureFrame - 40 + 48;  // stdout handle

        // Initialize stdout handle for this sub-function
        $this->b->movRI32(X64Builder::RCX, -11);              // STD_OUTPUT_HANDLE
        $this->b->emit("\x48\x83\xEC\x28"); $this->b->callIat(self::IAT_GETSTDHANDLE); $this->b->emit("\x48\x83\xC4\x28");
        $this->b->movMR64(X64Builder::RBP, $this->stdoutStackOffset, X64Builder::RAX);

        // Set up calleeVars for params
        $this->calleeVars = [];
        $paramRegs = [X64Builder::RCX, X64Builder::RDX, X64Builder::R8, X64Builder::R9];
        $ri = 0;
        $paramOffset = -$closureFrame - 40 + 64; // params start after itoa(48)+stdout(8)+written(8)

        foreach ($fn->params as $p) {
            // Check if this is an enum type parameter
            $enumFqn = null;
            if ($p->typeName) {
                $enumFqn = $this->classImportMap[$p->typeName] ?? $p->typeName;
                if (!isset($this->enums[$enumFqn])) $enumFqn = null;
            }

            // String-backed enum: use String type (ptr+len, 16 bytes)
            if ($p->type === TphpType::String || ($enumFqn && $this->isStringEnum($enumFqn))) {
                if ($ri + 1 >= 4) break;
                $this->b->movMR64(X64Builder::RBP, $paramOffset, $paramRegs[$ri]);
                $this->b->movMR64(X64Builder::RBP, $paramOffset + 8, $paramRegs[$ri + 1]);
                $entry = ['offset' => $paramOffset, 'type' => TphpType::String];
                if ($enumFqn) $entry['enumName'] = $enumFqn;
                $this->calleeVars[$p->name] = $entry;
                $paramOffset += 16;
                $ri += 2;
            } else {
                if ($ri >= 4) break;
                $this->b->movMR64(X64Builder::RBP, $paramOffset, $paramRegs[$ri]);
                $entry = ['offset' => $paramOffset, 'type' => $p->type];
                if ($enumFqn) $entry['enumName'] = $enumFqn;
                $this->calleeVars[$p->name] = $entry;
                $paramOffset += 8;
                $ri++;
            }
        }

        // Set up vars for body variables
        $this->vars = [];
        $offset = -$closureFrame - 40 + 64 + $paramSpace;
        foreach ($bodyVars as $name => $info) {
            $type = $info['type'];
            $elemType = $info['elemType'] ?? null;
            $size = $this->typeAllocSize($type, $elemType);
            $entry = ['offset' => $offset, 'type' => $type, 'elemType' => $elemType];
            if (isset($info['elemReturnTypes'])) $entry['elemReturnTypes'] = $info['elemReturnTypes'];
            if (isset($info['returnType'])) $entry['returnType'] = $info['returnType'];
            $this->vars[$name] = $entry;
            $offset += $size;
        }

        // Acquire stdout handle for this user function
        $this->b->movRI32(X64Builder::RCX, -11);          // STD_OUTPUT_HANDLE
        $this->b->emit("\x48\x83\xEC\x28");
        $this->b->callIat(self::IAT_GETSTDHANDLE);
        $this->b->emit("\x48\x83\xC4\x28");
        $this->b->movMR64(X64Builder::RBP, $this->stdoutStackOffset, X64Builder::RAX);

        // Zero out lpNumberOfBytesWritten
        $writtenOff = $this->stdoutStackOffset + 8;
        $this->b->movRI64(X64Builder::RAX, 0);
        $this->b->movMR64(X64Builder::RBP, $writtenOff, X64Builder::RAX);

        // Epilogue label
        $epilogueLabel = $this->newLabel('.L.epilogue.' . $fn->name);
        $this->currentEpilogueLabel = $epilogueLabel;

        // Generate body
        foreach ($fn->body as $stmt) {
            if ($stmt instanceof ReturnStmtNode && $stmt->expr !== null) {
                $this->genExpr($stmt->expr);
                $this->b->jmpLabel($epilogueLabel);
            } else {
                $this->genStmt($stmt);
            }
        }

        // ---- Epilogue ----
        $this->b->defineLabel($epilogueLabel);
        $this->emitHeapFreeEpilogue();
        if ($closureFrame > 0) {
            if ($closureFrame < 128) {
                $this->b->emit("\x48\x83\xC4"); $this->b->emit8($closureFrame);
            } else {
                $this->b->emit("\x48\x81\xC4"); $this->b->emit32($closureFrame);
            }
        }
        $this->b->popReg(X64Builder::R15);
        $this->b->popReg(X64Builder::R14);
        $this->b->popReg(X64Builder::R13);
        $this->b->popReg(X64Builder::R12);
        $this->b->emit("\x48\x89\xEC");   // mov rsp, rbp
        $this->b->popReg(X64Builder::RBP);
        $this->b->ret();

        // Restore outer state
        $this->stdoutStackOffset = $savedStdoutOff;
        $this->itoaBufOffset     = $savedItBufOff;
        $this->vars = $oldVars;
        $this->calleeVars = $oldCalleeVars;
        $this->isMain = $oldIsMain;
        $this->heapVarOffsets = $oldHeapVarOffsets;
        $this->currentEpilogueLabel = '';
        $this->currentClassName = $savedClassName;
        $this->currentNamespace = $savedNamespace;
    }

    // ==================== Function body ====================

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
            if (isset($info['returnType'])) {
                $entry['returnType'] = $info['returnType'];
            }
            $this->vars[$name] = $entry;
        }

        // Reserve extra 8 bytes for &lpNumberOfBytesWritten (used by WriteFile)
        // and 8 bytes for stdout handle cache
        // and 48 bytes for itoa buffer (32 digits + null + ".0" suffix, safe from shadow-space clobbering)
        $offset -= 8;  // lpNumberOfBytesWritten
        $offset -= 8;  // stdout handle
        $offset -= 48; // itoa output buffer (generous for ftoa ".0" suffix)

        // Reserve space for FFI extern function pointers (8 bytes each)
        $ffiCount = count($this->externDecls);
        if ($ffiCount > 0) {
            $offset -= 8 * $ffiCount;
        }

        // Store the actual frame offsets (before alignment padding)
        // Layout (from RBP downward):
        //   [rbp-8]  = lpNumberOfBytesWritten
        //   [rbp-16] = stdout handle
        //   [rbp-64..rbp-17] = itoa buffer (48 bytes)
        $this->stdoutStackOffset = $offset + 48 + 8 * $ffiCount; // stdout handle at buffer + 48 + ffi space
        $this->itoaBufOffset     = $offset + 8 * $ffiCount;       // itoa buffer start

        // Align frame to 16 bytes
        $rawSize = -$offset;
        $this->frameSize = (($rawSize + 15) & ~15) + 16; // +16 for safety margin

        // Prologue
        $this->b->pushReg(X64Builder::RBP);
        $this->b->emit("\x48\x89\xE5");           // mov rbp, rsp

        if ($this->frameSize < 128) {
            $this->b->emit("\x48\x83\xEC");       // sub rsp, imm8
            $this->b->emit8($this->frameSize);
        } else {
            $this->b->emit("\x48\x81\xEC");       // sub rsp, imm32
            $this->b->emit32($this->frameSize);
        }

        // Cache stdout handle BEFORE any body statements
        // (avoids clobbering RDX/R8 which the caller may have set up)
        $this->ensureStdout();

        // Load DLLs and resolve extern function pointers
        if (!empty($this->externDecls)) {
            $this->emitExternInit();
        }

        // Generate body
        foreach ($fn->body as $stmt) {
            $this->genStmt($stmt);
        }

        // Call destructors for objects created in main()
        foreach ($this->pendingDestructors as $clsName) {
            $dtorName = $clsName . '::__destruct';
            if (isset($this->functionNodes[$dtorName])) {
                $dtorCall = new FuncCallNode(0, $dtorName, []);
                $this->genUserFuncCall($dtorCall);
            }
        }
        $this->pendingDestructors = [];

        // Epilogue (only reached if no explicit exit)
        $this->emitWin32Exit(0);
    }

    private function typeAllocSize(TphpType $type, ?TphpType $elemType = null): int
    {
        return match ($type) {
            TphpType::Int    => 8,
            TphpType::Float  => 8,
            TphpType::String => 16,   // ptr(8) + len(8)
            TphpType::Bool   => 8,    // 64-bit for alignment
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

    /** @return array{type:TphpType, elemType?:TphpType, elemReturnTypes?:TphpType[], returnType?:TphpType} */
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
        // For single closure, track its return type
        if ($e instanceof ClosureNode) {
            return ['type' => TphpType::Callable_, 'returnType' => $e->returnType];
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
            $e instanceof CFuncCallNode      => $this->inferCFuncType($e),
            $e instanceof VarRefNode         => (
                $this->calleeVars[$e->name]['type'] ?? $this->vars[$e->name]['type'] ?? TphpType::Int
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
        if (in_array($e->op, ['==', '===', '!=', '!==', '<', '>', '<=', '>='], true)) return TphpType::Bool;
        return TphpType::Int;
    }

    private function inferCallType(FuncCallNode $e): TphpType
    {
        $builtin = match ($e->name) {
            'strlen' => TphpType::Int,
            'strpos' => TphpType::Int,
            'substr' => TphpType::String,
            'count'  => TphpType::Int,
            'var_dump' => $e->args[0] !== null ? $this->inferType($e->args[0]) : TphpType::Int,
            'CStr'  => TphpType::String,  // CStr → char* (stored as int ptr)
            'CInt'  => TphpType::Int,
            'CFloat'=> TphpType::Float,
            'CBool' => TphpType::Int,
            'TInt'  => TphpType::Int,
            'TFloat'=> TphpType::Float,
            'TBool' => TphpType::Int,
            'TStr'  => TphpType::String,
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
        // $b[0](args) → callee is IndexAccessNode, peek at closure return type if known.
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
        // $g(args) → callee is VarRefNode, peek at closure return type if known.
        if ($e->callee instanceof VarRefNode) {
            $varName = $e->callee->name;
            $varInfo = $this->vars[$varName] ?? $this->calleeVars[$varName] ?? null;
            if ($varInfo !== null && isset($varInfo['returnType'])) {
                return $varInfo['returnType'];
            }
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
        $info = $this->vars[$s->name] ?? $this->calleeVars[$s->name] ?? null;
        if ($info === null) {
            throw new \RuntimeException("Var \${$s->name} not allocated, line {$s->line}");
        }

        // Track class name for object variables
        if ($s->init instanceof NewExprNode) {
            $resolvedClass = $this->classImportMap[$s->init->className] ?? $s->init->className;
            $this->vars[$s->name]['className'] = $resolvedClass;
        }

        if ($info['type'] === TphpType::Array_) {
            $this->genArrayInit($s->name, $s->init);
            return;
        }

        // Intercept "$var" interpolation: BinaryOpNode('.', "", VarRef) → CastExprNode(String, VarRef)
        $effectiveInit = $s->init;
        if ($s->init instanceof BinaryOpNode && $s->init->op === '.' && $info['type'] === TphpType::String) {
            $leftEmpty  = $s->init->left instanceof StringLiteralNode && $s->init->left->value === '';
            $rightEmpty = $s->init->right instanceof StringLiteralNode && $s->init->right->value === '';
            $inner = null;
            if ($leftEmpty) $inner = $s->init->right;
            if ($rightEmpty) $inner = $s->init->left;
            if ($inner !== null && $this->inferType($inner) !== TphpType::String) {
                $effectiveInit = new CastExprNode($s->line, TphpType::String, $inner);
            }
        }

        // Check if init expression produces a heap-allocated string
        $isHeapString = ($info['type'] === TphpType::String) && $this->isHeapStringExpr($effectiveInit);

        $this->genExpr($effectiveInit);

        match ($info['type']) {
            TphpType::String => $this->storeString($info['offset']),
            TphpType::Float  => $this->storeFloat($info['offset']),
            default          => $this->b->movMR64(X64Builder::RBP, $info['offset'], X64Builder::RAX),
        };

        if ($isHeapString) {
            // Store heap flag in the correct array (vars or calleeVars)
            if (array_key_exists($s->name, $this->vars)) {
                $this->vars[$s->name]['heap'] = true;
            } elseif (array_key_exists($s->name, $this->calleeVars)) {
                $this->calleeVars[$s->name]['heap'] = true;
            }
            $this->heapVarOffsets[] = $info['offset'];
        }
    }

    /**
     * Check if an expression produces a heap-allocated string pointer
     * (i.e., runtime concatenation or copy from another heap variable).
     */
    private function isHeapStringExpr(ExprNode $e): bool
    {
        if ($e instanceof BinaryOpNode && $e->op === '.' && $this->tryFold($e) === null) {
            return true;
        }
        if ($e instanceof VarRefNode) {
            $sourceInfo = $this->vars[$e->name] ?? $this->calleeVars[$e->name] ?? null;
            return ($sourceInfo['heap'] ?? false) === true;
        }
        return false;
    }

    private function storeString(int $offset): void
    {
        $this->b->movMR64(X64Builder::RBP, $offset,     X64Builder::RAX);
        $this->b->movMR64(X64Builder::RBP, $offset + 8, X64Builder::RDX);
    }

    private function storeFloat(int $offset): void
    {
        $this->b->movsdStore(0, X64Builder::RBP, $offset);
    }

    // Output methods → CodeGen\Windows\Output trait
    // ==================== Windows WriteFile call ====================

    /**
     * Call WriteFile with buf=RSI, len=RDX (moves to RDX/R8 for the API).
     */
    private function doWriteFile(int $ptrReg, int $lenReg): void
    {
        // Move len first to avoid clobbering len when ptrReg overwrites RDX
        if ($lenReg !== X64Builder::R8)  $this->b->movRR(X64Builder::R8,  $lenReg);
        if ($ptrReg !== X64Builder::RDX) $this->b->movRR(X64Builder::RDX, $ptrReg);
        $this->doWriteFileRaw();
    }

    /**
     * Write a type prefix string like "(int)", "(float)", etc.
     * Uses one WriteFile call to emit the prefix before the value.
     */
    private function writeTypePrefix(string $typeName): void
    {
        $prefix = "($typeName) ";
        $lab = $this->b->addString($prefix);
        $this->b->emit("\x48\x8D\x15");          // lea rdx, [rip+disp32]
        $this->b->rel32($lab);
        $this->b->movRI32(X64Builder::R8, strlen($prefix));
        $this->doWriteFileRaw();
    }

    /**
     * Call WriteFile(hStdout, RDX=buf, R8=len, R9=&written, [rsp+0x20]=NULL).
     *
     * stdout handle is already cached at [rbp + stdoutStackOffset] by ensureStdout().
     */
    private function doWriteFileRaw(): void
    {
        // RCX = hFile (cached stdout handle)
        $this->b->movRM64(X64Builder::RCX, X64Builder::RBP, $this->stdoutStackOffset);

        // RDX = lpBuffer (already set by caller)
        // R8  = nNumberOfBytesToWrite (already set by caller)
        // R9  = lpNumberOfBytesWritten → stack slot
        $writtenOffset = $this->stdoutStackOffset + 8;
        $this->b->emit("\x4C\x8D\x8D");               // lea r9, [rbp+disp32]
        $this->b->emit32($writtenOffset);

        // sub rsp, 0x30 (48: 32 shadow + 8 arg5 + 8 alignment pad)
        $this->b->emit("\x48\x83\xEC\x30");
        // NULL for lpOverlapped at [rsp+0x20]
        $this->b->emit("\x48\xC7\x44\x24\x20\x00\x00\x00\x00");

        // call WriteFile
        $this->b->callIat(self::IAT_WRITEFILE);

        // add rsp, 0x30
        $this->b->emit("\x48\x83\xC4\x30");
    }

    /**
     * Acquire stdout handle once and cache it on stack.
     * Also sets console output codepage to UTF-8 (65001) for proper Unicode display.
     * Called during function prologue, before any register setup.
     */
    private function ensureStdout(): void
    {
        if ($this->stdoutAcquired) return;
        $this->stdoutAcquired = true;

        // ---- Step 1: Set console output codepage to UTF-8 (65001) ----
        // This ensures Unicode characters display correctly via WriteFile
        $this->b->movRI32(X64Builder::RCX, 65001);       // CP_UTF8
        $this->b->emit("\x48\x83\xEC\x28");              // sub rsp, 0x28 (32 shadow + 8 alignment)
        $this->b->callIat(self::IAT_SETCONSOLEOUTPUTCP);
        $this->b->emit("\x48\x83\xC4\x28");              // add rsp, 0x28

        // ---- Step 2: Get stdout handle ----
        // stdoutStackOffset is already set during frame layout in generateFunctionBody()
        $this->b->movRI32(X64Builder::RCX, -11);          // STD_OUTPUT_HANDLE
        $this->b->emit("\x48\x83\xEC\x28");              // sub rsp, 0x28 (32 shadow + 8 alignment)
        $this->b->callIat(self::IAT_GETSTDHANDLE);
        $this->b->emit("\x48\x83\xC4\x28");              // add rsp, 0x28

        // Cache handle at [rbp + stdoutStackOffset]
        $this->b->movMR64(X64Builder::RBP, $this->stdoutStackOffset, X64Builder::RAX);

        // Zero out lpNumberOfBytesWritten at [rbp + stdoutStackOffset + 8]
        $writtenOffset = $this->stdoutStackOffset + 8;
        $this->b->emit("\x48\xC7\x85");                 // mov qword [rbp+disp32], imm32
        $this->b->emit32($writtenOffset);
        $this->b->emit32(0);
    }

    // ==================== Exit ====================

    private function emitWin32Exit(int $code): void
    {
        $this->b->movRI32(X64Builder::RCX, $code);
        $this->b->emit("\x48\x83\xEC\x20");              // sub rsp, 0x20 (32 shadow)
        $this->b->callIat(self::IAT_EXITPROCESS);
        // No return from ExitProcess
    }

    // ==================== Expression generation ====================

    private function genExpr(ExprNode $e): void
    {
        switch (true) {
            case $e instanceof IntegerLiteralNode:
                $this->b->movRI64(X64Builder::RAX, $e->value);
                break;

            case $e instanceof FloatLiteralNode:
                $intBits = unpack('Q', pack('d', $e->value))[1];
                $this->b->movRI64(X64Builder::RAX, $intBits);
                $this->b->movqToXmm(0, X64Builder::RAX);
                break;

            case $e instanceof StringLiteralNode:
                $label = $this->b->addString($e->value);
                $this->b->emit("\x48\x8D\x05");          // lea rax, [rip+disp32]
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

            case $e instanceof ArrayLiteralNode:
                // Array literals are handled during genVarDecl via genArrayInit
                break;

            case $e instanceof ClosureNode:
                $this->genClosure($e);
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

            case $e instanceof CFuncCallNode:
                $this->genCFuncCall($e);
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
            // String — build on stack via sub rsp / rep mov approach
            $str = (string)$value;
            $strLen = strlen($str);
            $strPad = (($strLen + 7) & ~7);
            // sub rsp, strPad
            $this->b->emit("\x48\x83\xEC"); $this->b->emit8($strPad);
            // Write bytes at [rsp + i]
            for ($i = 0; $i < $strLen; $i++) {
                $this->b->emit("\xC6\x44\x24"); $this->b->emit8($i); $this->b->emit8(ord($str[$i]));
            }
            // lea rax, [rsp]
            $this->b->emit("\x48\x89\xE0"); // mov rax, rsp
            $this->b->movRI32(X64Builder::RDX, $strLen);
            // DON'T free string here — caller must add rsp after using
            // Actually, need to free it. But RAX has the ptr already.
            // Save ptr to R14, free stack, restore
            $this->b->movRR(X64Builder::R14, X64Builder::RAX);
            $this->b->emit("\x48\x83\xC4"); $this->b->emit8($strPad); // free
            $this->b->movRR(X64Builder::RAX, X64Builder::R14);
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
            $varInfo = $this->vars[$e->object->name] ?? $this->calleeVars[$e->object->name] ?? null;
            $clsName = $varInfo['className'] ?? '';
        } elseif ($e->object instanceof ThisExprNode) {
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

        $this->b->xorRR(X64Builder::RAX, X64Builder::RAX);
    }

    private function genVarRead(VarRefNode $e): void
    {
        $info = $this->calleeVars[$e->name] ?? $this->vars[$e->name] ?? null;
        if ($info === null) {
            throw new \RuntimeException("Undefined variable: \${$e->name}, line {$e->line}");
        }

        if ($info['type'] === TphpType::String) {
            $this->b->movRM64(X64Builder::RAX, X64Builder::RBP, $info['offset']);
            $this->b->movRM64(X64Builder::RDX, X64Builder::RBP, $info['offset'] + 8);
        } elseif ($info['type'] === TphpType::Float) {
            $this->b->movsdLoad(0, X64Builder::RBP, $info['offset']);
            $this->b->movqFromXmm(X64Builder::RAX, 0);
        } else {
            $this->b->movRM64(X64Builder::RAX, X64Builder::RBP, $info['offset']);
        }
    }

    // ==================== Binary ops ====================

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
            // "$var" interpolation: skip empty side, convert non-string to stack string
            $leftEmpty  = $e->left instanceof StringLiteralNode && $e->left->value === '';
            $rightEmpty = $e->right instanceof StringLiteralNode && $e->right->value === '';
            if ($leftEmpty && $this->inferType($e->right) !== TphpType::String) {
                $this->genExpr($e->right);
                $this->b->emit("\x48\x8D\xBD"); $this->b->emit32($this->itoaBufOffset + 31);
                $this->b->emit("\x45\x31\xC9");
                $this->b->emit("\xE8"); $this->b->rel32($this->itoaLabel);
                $this->b->movRR(X64Builder::RAX, X64Builder::RSI);
                return;
            }
            if ($rightEmpty && $this->inferType($e->left) !== TphpType::String) {
                $this->genExpr($e->left);
                $this->b->emit("\x48\x8D\xBD"); $this->b->emit32($this->itoaBufOffset + 31);
                $this->b->emit("\x45\x31\xC9");
                $this->b->emit("\xE8"); $this->b->rel32($this->itoaLabel);
                $this->b->movRR(X64Builder::RAX, X64Builder::RSI);
                return;
            }
            $this->genStringConcat($e->left, $e->right);
            return;
        }

        // Special: string char access vs 1-char literal → int comparison
        if (in_array($e->op, ['==', '===', '!=', '!=='], true)) {
            $handled = $this->tryStrCharCmp($e);
            if ($handled) return;
        }

        $lType = $this->inferType($e->left);
        $rType = $this->inferType($e->right);

        $this->genExpr($e->left);
        $this->b->pushReg(X64Builder::RAX);
        $this->genExpr($e->right);

        if ($lType === TphpType::Float || $rType === TphpType::Float) {
            $this->b->popReg(X64Builder::RCX);
            $this->b->movqToXmm(0, X64Builder::RCX);
            $this->b->movqToXmm(1, X64Builder::RAX);

            match ($e->op) {
                '+' => $this->b->emit("\xF2\x0F\x58\xC1"),     // addsd xmm0, xmm1
                '-' => $this->b->emit("\xF2\x0F\x5C\xC1"),     // subsd xmm0, xmm1
                '*' => $this->b->emit("\xF2\x0F\x59\xC1"),     // mulsd xmm0, xmm1
                '/' => $this->b->emit("\xF2\x0F\x5E\xC1"),     // divsd xmm0, xmm1
                default => null,
            };
            $this->b->movqFromXmm(X64Builder::RAX, 0);
            return;
        }

        // Integer ops
        $this->b->popReg(X64Builder::RCX); // rcx = left

        match ($e->op) {
            '+'  => $this->b->addRR(X64Builder::RAX, X64Builder::RCX),
            '-'  => $this->emitSub(),
            '*'  => $this->b->emit("\x48\x0F\xAF\xC1"),      // imul rax, rcx
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

    private function emitSub(): void
    {
        // rax = rcx - rax = left - right
        $this->b->subRR(X64Builder::RCX, X64Builder::RAX);
        $this->b->movRR(X64Builder::RAX, X64Builder::RCX);
    }

    private function emitDiv(): void
    {
        $this->b->movRR(X64Builder::R8, X64Builder::RAX);  // r8 = divisor
        $this->b->movRR(X64Builder::RAX, X64Builder::RCX); // rax = dividend
        $this->b->cqo();                                    // sign extend
        $this->b->emit("\x49\xF7\xF8");                     // idiv r8
    }

    private function emitMod(): void
    {
        $this->b->movRR(X64Builder::R8, X64Builder::RAX);
        $this->b->movRR(X64Builder::RAX, X64Builder::RCX);
        $this->b->cqo();
        $this->b->emit("\x49\xF7\xF8");                     // idiv r8
        $this->b->movRR(X64Builder::RAX, X64Builder::RDX);
    }

    private function emitICmp(string $cc): void
    {
        $this->b->cmpRR(X64Builder::RCX, X64Builder::RAX);
        $setMap = [
            'e'  => "\x0F\x94\xC0",
            'ne' => "\x0F\x95\xC0",
            'l'  => "\x0F\x9C\xC0",
            'g'  => "\x0F\x9F\xC0",
            'le' => "\x0F\x9E\xC0",
            'ge' => "\x0F\x9D\xC0",
        ];
        if (isset($setMap[$cc])) $this->b->emit($setMap[$cc]);
        $this->b->movzxRR(X64Builder::RAX, X64Builder::RAX);
    }

    /** Check if $e is a non-array string IndexAccessNode ($str[$idx]) that yields a char (int). */
    private function isStrCharAccess(ExprNode $e): bool
    {
        if (!$e instanceof IndexAccessNode) return false;
        if (!$e->target instanceof VarRefNode) return false;
        $info = $this->calleeVars[$e->target->name] ?? $this->vars[$e->target->name] ?? null;
        if ($info === null) return false;
        return !isset($info['elemType']); // string variable, not array
    }

    /**
     * Handle: string[$i] == "X"  →  compare char code with ASCII('X').
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
            $this->genExpr($e->left);                        // RAX = char code
            $this->b->movRR(X64Builder::RCX, X64Builder::RAX);
            $this->b->movRI32(X64Builder::RAX, ord($e->right->value));
        } else {
            $this->b->movRI32(X64Builder::RCX, ord($e->left->value));
            $this->genExpr($e->right);                       // RAX = char code
        }

        $cmpMap = ['==' => 'e', '===' => 'e', '!=' => 'ne', '!==' => 'ne'];
        $cc = $cmpMap[$e->op] ?? 'e';
        $this->emitICmp($cc);
        return true;
    }

    // ControlFlow methods → CodeGen\Windows\ControlFlow trait
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
            $this->genExpr($e->operand);
            match ($srcType) {
                TphpType::Float  => $this->genFloatToInt(),
                TphpType::String => $this->genStringToInt(),
                TphpType::Array_ => $this->genArrayToInt(),
                default => null, // Bool, Null, Int → already int
            };
        }

        if ($e->targetType === TphpType::Float) {
            if ($srcType === TphpType::Array_ && $e->operand instanceof VarRefNode) {
                $info = $this->vars[$e->operand->name] ?? $this->calleeVars[$e->operand->name] ?? null;
                if ($info !== null) {
                    $off = $info['offset']; $oneL = $this->newLabel('.L.fc1'); $doneL = $this->newLabel('.L.fcd');
                    $this->b->movRM64(X64Builder::RAX, X64Builder::RBP, $off); $this->b->testRR(X64Builder::RAX, X64Builder::RAX);
                    $this->b->jneLabel($oneL); $this->b->xorRR(X64Builder::RAX, X64Builder::RAX);
                    $this->b->emit("\xF2\x48\x0F\x2A\xC0"); $this->b->jmpLabel($doneL);
                    $this->b->defineLabel($oneL); $this->b->movRI32(X64Builder::RAX, 1);
                    $this->b->emit("\xF2\x48\x0F\x2A\xC0"); $this->b->defineLabel($doneL); $this->b->movqFromXmm(X64Builder::RAX, 0); return;
                }
            }
            $this->genExpr($e->operand);
            if ($srcType === TphpType::Int || $srcType === TphpType::Bool) { $this->b->emit("\xF2\x48\x0F\x2A\xC0"); $this->b->movqFromXmm(X64Builder::RAX, 0); }
            elseif ($srcType === TphpType::Float) { $this->b->movqFromXmm(X64Builder::RAX, 0); }
            elseif ($srcType === TphpType::String && $e->operand instanceof StringLiteralNode) {
                // Compile-time string→float via PHP (float) cast
                $f = (float)$e->operand->value;
                if ($f == 0.0) {
                    $this->b->emit("\x0F\x57\xC0"); $this->b->movqFromXmm(X64Builder::RAX, 0);
                } else {
                    $bits = unpack('Q', pack('d', $f))[1];
                    $this->b->emit("\x48\xB8"); $this->b->emit64($bits);
                }
            }
            else { $this->b->emit("\x0F\x57\xC0"); $this->b->movqFromXmm(X64Builder::RAX, 0); }
        }

        if ($e->targetType === TphpType::String) {
            if ($srcType === TphpType::Array_) { throw new \RuntimeException("Array cannot be converted to string at line {$e->line}"); }
            if ($srcType === TphpType::String) { $this->genExpr($e->operand); } // already in RAX:RDX
            elseif ($srcType === TphpType::Null) { $this->b->xorRR(X64Builder::RAX, X64Builder::RAX); $this->b->xorRR(X64Builder::RDX, X64Builder::RDX); }
            else { $this->genExprAsHeapString($e->operand, $srcType); }
        }
    }

    private function genFloatToInt(): void
    {
        $this->b->emit("\xF2\x48\x0F\x2C\xC0"); // cvttsd2si rax, xmm0
    }

    /** Call shared atoi helper: RCX=ptr, RDX=len → RAX=int */
    private function genStringToInt(): void
    {
        $this->b->movRR(X64Builder::RCX, X64Builder::RAX);
        $this->b->emit("\x48\x83\xEC\x28");             // sub rsp, 0x28
        $this->b->emit("\xE8");
        $this->b->rel32($this->atoiLabel);
        $this->b->emit("\x48\x83\xC4\x28");             // add rsp, 0x28
    }

    /** Evaluate (int)expr at compile time if result is known to be 0. Returns 0 or null. */
    /** Print (int)$array → '0' or '1' inline, bypassing itoa */
    private function genArrayIntPrint(int $offset): void
    {
        $zeroL = $this->newLabel('.L.aip0');
        $doneL = $this->newLabel('.L.aip1');

        $this->b->movRM64(X64Builder::RAX, X64Builder::RBP, $offset); // load len
        $this->b->testRR(X64Builder::RAX, X64Builder::RAX);
        $this->b->jeLabel($zeroL);

        // Non-zero → '1'
        $lab1 = $this->b->addString('1');
        $this->b->emit("\x48\x8D\x15"); $this->b->rel32($lab1);
        $this->b->movRI32(X64Builder::R8, 1);
        $this->doWriteFileRaw();
        $this->b->jmpLabel($doneL);

        // Zero → '0'
        $this->b->defineLabel($zeroL);
        $lab0 = $this->b->addString('0');
        $this->b->emit("\x48\x8D\x15"); $this->b->rel32($lab0);
        $this->b->movRI32(X64Builder::R8, 1);
        $this->doWriteFileRaw();

        $this->b->defineLabel($doneL);
    }

    /** Check if expression represents an object instance. */
    private function isObjectExpr(ExprNode $e): bool
    {
        if ($e instanceof NewExprNode) {
            return true;
        }
        if ($e instanceof VarRefNode) {
            $info = $this->calleeVars[$e->name] ?? $this->vars[$e->name] ?? null;
            return ($info !== null && isset($info['className']));
        }
        return false;
    }

    private function evalCastToZero(CastExprNode $e): ?int
    {
        if ($e->operand instanceof BoolLiteralNode) {
            return $e->operand->value ? null : 0;
        }
        if ($e->operand instanceof NullLiteralNode) {
            return 0;
        }
        if ($e->operand instanceof IntegerLiteralNode && $e->operand->value === 0) {
            return 0;
        }
        if ($e->operand instanceof StringLiteralNode) {
            $s = $e->operand->value;
            if ($s === '' || ($s[0] !== '-' && $s[0] !== '+' && ($s[0] < '0' || $s[0] > '9'))) {
                return 0;
            }
        }
        if ($e->operand instanceof VarRefNode) {
            $info = $this->calleeVars[$e->operand->name] ?? $this->vars[$e->operand->name] ?? null;
            if ($info !== null && isset($info['elemType'])) {
                // Array variable: check if declared with 0 elements at compile time
                // We need the ArrayLiteralNode from the init expression chain
                // For now: runtime conversion will handle it
            }
        }
        return null;
    }

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

    // ==================== String fold ====================

    private function tryFold(ExprNode $e): ?string
    {
        if ($e instanceof StringLiteralNode) return $e->value;
        if ($e instanceof BinaryOpNode && $e->op === '.') {
            $l = $this->tryFold($e->left);
            $r = $this->tryFold($e->right);
            if ($l !== null && $r !== null) return $l . $r;
        }
        return null;
    }

    /** Convert non-string expr to stable heap string (RAX=ptr, RDX=len). Same pattern as genStringConcat. */
    private function genExprAsHeapString(ExprNode $e, TphpType $type): void
    {
        $this->genExpr($e);
        $this->b->emit("\x48\x8D\xBD"); $this->b->emit32($this->itoaBufOffset + 31);
        if ($type === TphpType::Float) {
            $this->b->movqToXmm(0, X64Builder::RAX);
            $this->b->emit("\xE8"); $this->b->rel32($this->ftoaLabel);
        } else {
            $this->b->emit("\x45\x31\xC9");
            $this->b->emit("\xE8"); $this->b->rel32($this->itoaLabel);
        }
        // Stack buffer only: avoids IAT call crash in sub-functions (pre-existing bug)
        $this->b->movRR(X64Builder::RAX, X64Builder::RSI);
        // RDX already = len from itoa/ftoa
    }

    private function genStringConcat(ExprNode $left, ExprNode $right): void
    {
        // Step 1: Evaluate left string → RAX=ptr, RDX=len
        $this->genExpr($left);
        $this->b->pushReg(X64Builder::RAX);                // save left ptr
        $this->b->pushReg(X64Builder::RDX);                // save left len

        // Step 2: Evaluate right string → RAX=ptr, RDX=len
        $this->genExpr($right);
        $this->b->movRR(X64Builder::R14, X64Builder::RAX); // right ptr → R14
        $this->b->movRR(X64Builder::R15, X64Builder::RDX); // right len → R15

        // Restore left string from stack (push/pop avoids R12/R13 clobber by nested concat)
        $this->b->popReg(X64Builder::R13);                 // left len → R13
        $this->b->popReg(X64Builder::R12);                 // left ptr → R12

        // Step 3: Calculate total length = left_len + right_len
        $this->b->movRR(X64Builder::RAX, X64Builder::R13); // left len
        $this->b->addRR(X64Builder::RAX, X64Builder::R15); // total len

        // Step 4: Call GetProcessHeap() to get the heap handle
        $this->b->emit("\x48\x83\xEC\x28");               // sub rsp, 40 (shadow space + alignment)
        $this->b->callIat(self::IAT_GETPROCESSHEAP);
        $this->b->emit("\x48\x83\xC4\x28");               // add rsp, 40

        // Step 5: Call HeapAlloc(hHeap, HEAP_ZERO_MEMORY, totalLen)
        $this->b->movRR(X64Builder::RCX, X64Builder::RAX); // hHeap from GetProcessHeap
        $this->b->movRI32(X64Builder::RDX, 8);             // dwFlags = HEAP_ZERO_MEMORY
        $this->b->movRR(X64Builder::R8, X64Builder::R13);  // dwBytes = left len
        $this->b->addRR(X64Builder::R8, X64Builder::R15);  // dwBytes += right len = total len

        $this->b->emit("\x48\x83\xEC\x28");               // sub rsp, 40 (shadow space + alignment)
        $this->b->callIat(self::IAT_HEAPALLOC);
        $this->b->emit("\x48\x83\xC4\x28");               // add rsp, 40

        // RAX = allocated buffer pointer

        // Step 6: Copy left string
        $this->b->emit("\xFC");                            // cld
        $this->b->movRR(X64Builder::RDI, X64Builder::RAX); // dest = buffer
        $this->b->movRR(X64Builder::RSI, X64Builder::R12); // src = left ptr
        $this->b->movRR(X64Builder::RCX, X64Builder::R13); // count = left len
        $this->b->emit("\xF3\xA4");                        // rep movsb

        // Step 7: Copy right string
        // RDI already points to end of left string (rep movsb advances it)
        $this->b->movRR(X64Builder::RSI, X64Builder::R14); // src = right ptr
        $this->b->movRR(X64Builder::RCX, X64Builder::R15); // count = right len
        $this->b->emit("\xF3\xA4");                        // rep movsb

        // Step 8: Set return values
        $this->b->movRR(X64Builder::RDX, X64Builder::R13); // left len
        $this->b->addRR(X64Builder::RDX, X64Builder::R15); // total len
    }

    // ==================== FuncCall ====================

    private function genFuncCall(FuncCallNode $e): void
    {
        if ($e->name === 'CStr') { $this->genCStr($e); return; }
        if ($e->name === 'CInt') { $this->genCInt($e); return; }
        if ($e->name === 'CFloat') { $this->genCFloat($e); return; }
        if ($e->name === 'CBool') { $this->genCBool($e); return; }
        if ($e->name === 'TInt') { $this->genTInt($e); return; }
        if ($e->name === 'TFloat') { $this->genTFloat($e); return; }
        if ($e->name === 'TBool') { $this->genTBool($e); return; }
        if ($e->name === 'TStr') { $this->genTStr($e); return; }
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
            $resolvedName = $e->name; // global (Main namespace)
        }

        if ($resolvedName !== null) {
            $resolvedCall = new FuncCallNode($e->line, $resolvedName, $e->args);
            $this->genUserFuncCall($resolvedCall);
            return;
        }
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
        // Class method: "ClassName::methodName" → class context
        if (str_contains($fqn, '::')) {
            return $this->currentNamespace;
        }
        return 'Main';
    }

    /**
     * Emit call to a user-defined function using Windows x64 convention.
     */
    private function genUserFuncCall(FuncCallNode $e): void
    {
        $label = $this->functionLabels[$e->name]
            ?? throw new \RuntimeException("Function '{$e->name}' not found, line {$e->line}");

        // Set up args in Windows x64 convention: RCX, RDX, R8, R9
        $paramRegs = [X64Builder::RCX, X64Builder::RDX, X64Builder::R8, X64Builder::R9];
        $ri = 0;

        foreach ($e->args as $arg) {
            $argType = $this->inferType($arg);

            // For second+ string args, save RDX before genExpr overwrites it
            if ($argType === TphpType::String && $ri > 0) {
                $this->b->movRR(X64Builder::R10, X64Builder::RDX);
            }

            $this->genExpr($arg);

            if ($argType === TphpType::String) {
                if ($ri + 1 >= 4) break;

                if ($ri == 0) {
                    // First string: RCX=ptr, RDX=len (already set by genExpr)
                    $this->b->movRR(X64Builder::RCX, X64Builder::RAX);
                    // RDX already has len
                } else {
                    // Second string: R8=ptr, R9=len
                    $this->b->movRR(X64Builder::R8, X64Builder::RAX);
                    $this->b->movRR(X64Builder::R9, X64Builder::RDX);
                    $this->b->movRR(X64Builder::RDX, X64Builder::R10); // restore first len
                }
                $ri += 2;
            } else {
                if ($ri >= 4) break;
                $this->b->movRR($paramRegs[$ri], X64Builder::RAX);
                $ri++;
            }
        }

        // Call the user function (allocate shadow space + ensure alignment)
        $this->b->emit("\x48\x83\xEC\x20");   // sub rsp, 32
        $this->b->emit("\xE8");               // call rel32
        $this->b->rel32($label);
        $this->b->emit("\x48\x83\xC4\x20");   // add rsp, 32
    }

    private function genClosureCall(FuncCallNode $e): void
    {
        // Load function pointer into R11 (volatile, not clobbered by genExpr)
        $varInfo = $this->vars[$e->name]
            ?? throw new \RuntimeException("Undefined variable: {$e->name}, line {$e->line}");
        $this->b->movRM64(X64Builder::R11, X64Builder::RBP, $varInfo['offset']);

        // Set up args in Windows x64 convention: RCX, RDX, R8, R9
        $this->emitClosureArgs($e->args);

        // Allocate shadow space (32 bytes) and call through function pointer
        $this->b->emit("\x48\x83\xEC\x20");   // sub rsp, 32
        $this->b->emit("\x41\xFF\xD3");       // call r11
        $this->b->emit("\x48\x83\xC4\x20");   // add rsp, 32
    }

    /** Call expression result: $b[0](1, 2) where $b[0] evaluates to a function pointer */
    private function genExprCall(ExprCallNode $e): void
    {
        // Evaluate callee expression → function pointer in RAX
        $this->genExpr($e->callee);
        // Move function pointer to R11 (volatile register, safe)
        $this->b->movRR(X64Builder::R11, X64Builder::RAX);

        // Set up args in Windows x64 convention: RCX, RDX, R8, R9
        $this->emitClosureArgs($e->args);

        // Allocate shadow space (32 bytes) and ensure 16-byte stack alignment
        // After sub rsp, 32, rsp should be 16-byte aligned
        $this->b->emit("\x48\x83\xEC\x20");   // sub rsp, 32
        $this->b->emit("\x41\xFF\xD3");       // call r11
        $this->b->emit("\x48\x83\xC4\x20");   // add rsp, 32
    }

    /**
     * Emit arg setup for closure calls.
     * String args use 2 consecutive registers (ptr + len).
     * Other types use 1 register.
     */
    private function emitClosureArgs(array $args): void
    {
        $paramRegs = [X64Builder::RCX, X64Builder::RDX, X64Builder::R8, X64Builder::R9];
        $ri = 0; // register index

        foreach ($args as $arg) {
            $argType = $this->inferType($arg);

            // For second and subsequent strings, save RDX (previous string len) before genExpr overwrites it
            if ($argType === TphpType::String && $ri > 0) {
                $this->b->movRR(X64Builder::R10, X64Builder::RDX); // save previous len to R10
            }

            $this->genExpr($arg);

            if ($argType === TphpType::String) {
                if ($ri + 1 >= 4) break;

                if ($ri == 0) {
                    // First string: RCX=ptr, RDX=len
                    $this->b->movRR(X64Builder::RCX, X64Builder::RAX); // ptr to RCX
                    // RDX already has len from genExpr, no need to move
                } else {
                    // Second string: R8=ptr, R9=len
                    // Save current string's len (RDX) before restoring first string's len
                    $this->b->movRR(X64Builder::R8, X64Builder::RAX);   // ptr to R8
                    $this->b->movRR(X64Builder::R9, X64Builder::RDX);   // current len to R9
                    $this->b->movRR(X64Builder::RDX, X64Builder::R10);  // restore first len to RDX
                }
                $ri += 2;
            } else {
                if ($ri >= 4) break;
                $this->b->movRR($paramRegs[$ri], X64Builder::RAX);
                $ri++;
            }
        }
    }

    private function genStrlen(FuncCallNode $e): void
    {
        $arg = $e->args[0] ?? throw new \RuntimeException("strlen requires 1 arg, line {$e->line}");
        if ($arg instanceof VarRefNode) {
            $off = $this->vars[$arg->name]['offset']
                ?? throw new \RuntimeException("Undefined: \${$arg->name}");
            $this->b->movRM64(X64Builder::RAX, X64Builder::RBP, $off + 8);
        } elseif ($arg instanceof StringLiteralNode) {
            $this->b->movRI32(X64Builder::RAX, strlen($arg->value));
        } else {
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

        $this->b->movRR(X64Builder::RCX, X64Builder::RAX);
        $this->genExpr($e->index);
        $this->b->addRR(X64Builder::RCX, X64Builder::RAX);
        $this->b->emit("\x8A\x01");                      // mov al, [rcx]
        $this->b->movzxRR(X64Builder::RAX, X64Builder::RAX);
    }

    // ArrayOps methods → CodeGen\Windows\ArrayOps trait
    // ==================== Closure ====================

    private function genClosure(ClosureNode $e): void
    {
        $funcLab  = $this->newLabel('.L.clos');
        $afterLab = $this->newLabel('.L.aclos');

        $this->b->jmpLabel($afterLab);
        $this->b->defineLabel($funcLab);

        // Collect closure body variables
        $bodyVars = $this->collectVariables($e->body);

        // Calculate param space size
        $paramSpace = 0;
        foreach ($e->params as $p) {
            $paramSpace += $this->typeAllocSize($p->type);
        }

        // Calculate frame size based on params + variables + 48 bytes (stdout/written/itoa)
        $frameSize = $paramSpace;
        foreach ($bodyVars as $name => $info) {
            $type = $info['type'];
            $elemType = $info['elemType'] ?? null;
            $frameSize += $this->typeAllocSize($type, $elemType);
        }

        // Prologue
        $this->b->pushReg(X64Builder::RBP);
        $this->b->emit("\x48\x89\xE5");   // mov rbp, rsp

        // Save non-volatile registers R12-R15 (callee-saved in Windows x64)
        $this->b->pushReg(X64Builder::R12);
        $this->b->pushReg(X64Builder::R13);
        $this->b->pushReg(X64Builder::R14);
        $this->b->pushReg(X64Builder::R15);

        // Allocate stack space for itoa buf (48) + stdout handle (8) + written count (8) + params + local vars
        // After push rbp (8) + push r12-r15 (32) = 40 bytes
        $closureFrame = $frameSize + 64; // 48 itoa + 8 stdout + 8 written
        // Align to 16 bytes
        $closureFrame = (($closureFrame + 15) & ~15);

        // First allocate stack space
        if ($closureFrame > 0) {
            if ($closureFrame < 128) {
                $this->b->emit("\x48\x83\xEC");   // sub rsp, imm8
                $this->b->emit8($closureFrame);
            } else {
                $this->b->emit("\x48\x81\xEC");   // sub rsp, imm32
                $this->b->emit32($closureFrame);
            }
        }

        // Stack layout (negative offsets from rbp):
        //   [-closureFrame-40 ... -closureFrame-40+48)                    → itoa buf (48 bytes)
        //   [-closureFrame-40+48 ... -closureFrame-40+56)                 → stdout handle (8 bytes)
        //   [-closureFrame-40+56 ... -closureFrame-40+64)                 → written count (8 bytes)
        //   [-closureFrame-40+64 ... -closureFrame-40+64+paramSpace)      → params
        //   [-closureFrame-40+64+paramSpace ...]                          → local vars

        // Save outer scope and set up calleeVars for closure params
        $oldVars = $this->vars;
        $oldHeapVarOffsets = $this->heapVarOffsets;
        $this->calleeVars = [];
        $this->heapVarOffsets = [];

        // Store params on stack (now that stack space is allocated)
        // Parameter registers in Windows x64 convention
        // String params use 2 consecutive registers (ptr + len)
        // Other types use 1 register
        $paramRegs = [X64Builder::RCX, X64Builder::RDX, X64Builder::R8, X64Builder::R9];
        $ri = 0;
        $paramOffset = -$closureFrame - 48 + 64; // params start after itoa(48)+stdout(8)+written(8)+align(8)
        foreach ($e->params as $p) {
            if ($p->type === TphpType::String) {
                if ($ri + 1 >= 4) break; // need 2 regs
                $this->b->movMR64(X64Builder::RBP, $paramOffset, $paramRegs[$ri]);         // ptr to stack
                $this->b->movMR64(X64Builder::RBP, $paramOffset + 8, $paramRegs[$ri + 1]); // len to stack
                $this->calleeVars[$p->name] = ['offset' => $paramOffset, 'type' => $p->type];
                $paramOffset += 16;
                $ri += 2;
            } else {
                if ($ri >= 4) break;
                $this->b->movMR64(X64Builder::RBP, $paramOffset, $paramRegs[$ri]);
                $this->calleeVars[$p->name] = ['offset' => $paramOffset, 'type' => $p->type];
                $paramOffset += 8;
                $ri++;
            }
        }

        // Allocate closure body variables on stack (after params)
        $varOffset = -$closureFrame - 40 + 64 + $paramSpace;
        foreach ($bodyVars as $name => $info) {
            $type = $info['type'];
            $elemType = $info['elemType'] ?? null;
            $size = $this->typeAllocSize($type, $elemType);
            $entry = ['offset' => $varOffset, 'type' => $type, 'elemType' => $elemType];
            if (isset($info['elemReturnTypes'])) {
                $entry['elemReturnTypes'] = $info['elemReturnTypes'];
            }
            if (isset($info['returnType'])) {
                $entry['returnType'] = $info['returnType'];
            }
            $this->calleeVars[$name] = $entry;
            $varOffset += $size;
        }
        
        // Now set up closure-local stdout handle (after params are safely stored)
        $savedStdoutOff  = $this->stdoutStackOffset;
        $savedItBufOff   = $this->itoaBufOffset;
        // Calculate offsets from rbp: itoa buffer starts at -closureFrame-40, stdout handle after it
        $this->itoaBufOffset     = -$closureFrame - 40;   // itoa buffer at lowest address
        $this->stdoutStackOffset = -$closureFrame - 40 + 48;  // stdout handle after itoa buffer (48 bytes)

        // Re-acquire stdout handle for this closure's rbp
        $this->b->movRI32(X64Builder::RCX, -11);          // STD_OUTPUT_HANDLE
        $this->b->emit("\x48\x83\xEC\x28");              // sub rsp, 0x28 (32 shadow + 8 alignment)
        $this->b->callIat(self::IAT_GETSTDHANDLE);
        $this->b->emit("\x48\x83\xC4\x28");              // add rsp, 0x28
        $this->b->movMR64(X64Builder::RBP, $this->stdoutStackOffset, X64Builder::RAX);

        // Zero out lpNumberOfBytesWritten at [rbp + stdoutOffset + 8]
        $writtenOff = $this->stdoutStackOffset + 8;
        $this->b->movRI64(X64Builder::RAX, 0);
        $this->b->movMR64(X64Builder::RBP, $writtenOff, X64Builder::RAX);

        // Generate body
        foreach ($e->body as $stmt) {
            if ($stmt instanceof ReturnStmtNode && $stmt->expr !== null) {
                $this->genExpr($stmt->expr);
            } else {
                $this->genStmt($stmt);
            }
        }

        // Restore outer stdout offsets
        $this->stdoutStackOffset = $savedStdoutOff;
        $this->itoaBufOffset     = $savedItBufOff;

        // Epilogue
        $this->emitHeapFreeEpilogue();
        if ($closureFrame > 0) {
            if ($closureFrame < 128) {
                $this->b->emit("\x48\x83\xC4");   // add rsp, imm8
                $this->b->emit8($closureFrame);
            } else {
                $this->b->emit("\x48\x81\xC4");   // add rsp, imm32
                $this->b->emit32($closureFrame);
            }
        }
        // Restore non-volatile registers R12-R15
        $this->b->popReg(X64Builder::R15);
        $this->b->popReg(X64Builder::R14);
        $this->b->popReg(X64Builder::R13);
        $this->b->popReg(X64Builder::R12);
        $this->b->emit("\x48\x89\xEC");   // mov rsp, rbp
        $this->b->popReg(X64Builder::RBP);
        $this->b->ret();

        $this->b->defineLabel($afterLab);
        // Store function pointer in RAX
        $this->b->emit("\x48\x8D\x05");   // lea rax, [rip+disp32]
        $this->b->rel32($funcLab);

        // Restore outer scope
        $this->vars = $oldVars;
        $this->calleeVars = [];
        $this->heapVarOffsets = $oldHeapVarOffsets;
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

    // Helpers (heap cleanup, itoa, ftoa, atoi) are in CodeGen\Windows\Helpers trait
}
