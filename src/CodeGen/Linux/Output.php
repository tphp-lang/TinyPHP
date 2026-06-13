<?php

declare(strict_types=1);

namespace Tphp\CodeGen\Linux;

use Tphp\X64Builder;
use Tphp\AST\{
    TphpType, ExprNode, StmtNode,
    VarDeclNode, ExprStmtNode, PrintStmtNode, ReturnStmtNode,
    IfStmtNode, WhileStmtNode, ForStmtNode, SwitchStmtNode, SwitchCaseNode,
    BreakStmtNode, ArrayAppendStmtNode, UnsetStmtNode,
    IntegerLiteralNode, FloatLiteralNode, StringLiteralNode,
    BoolLiteralNode, NullLiteralNode, VarRefNode, ConstRefNode,
    BinaryOpNode, FuncCallNode, IndexAccessNode, StringRangeNode,
    ArrayLiteralNode, ClosureNode, ExprCallNode, PostIncrementNode,
    CastExprNode, MethodCallNode, ThisExprNode, NewExprNode, EnumAccessNode,
    FunctionDeclNode, ParamNode, ClassDeclNode, MethodDeclNode,
    ExternFuncNode, CFuncCallNode, ConstDeclNode,
};

/**
 * Extracted from CodeGenerator
 */
trait Output
{

    private function genPrint(PrintStmtNode $s): void
    {
        foreach ($s->args as $arg) {
            $type = $this->inferType($arg);
            $this->printByType($arg, $type, $s->isVarDump);
        }
    }

    private function printByType(ExprNode $e, TphpType $type, bool $isVarDump): void
    {
        if ($isVarDump) {
            $prefix = match ($type) {
                TphpType::Int    => 'int',
                TphpType::Float  => 'float',
                TphpType::String => 'string',
                TphpType::Bool   => 'bool',
                TphpType::Null   => 'null',
                default          => null,
            };
            if ($prefix !== null) {
                $this->writeTypePrefix($prefix);
            }
        }
        match ($type) {
            TphpType::Int    => $this->printInt($e),
            TphpType::Float  => $this->printFloat($e),
            TphpType::String => $this->printString($e),
            TphpType::Bool   => $this->printBool($e, $isVarDump),
            TphpType::Null   => $this->printNull($isVarDump),
            TphpType::Array_ => $this->printArray($e),
            default => null,
        };
        if ($isVarDump) {
            $this->emitNewline();
        }
    }

    // ===== Print: Int =====
    private function printInt(ExprNode $e): void
    {
        $this->genExpr($e);         // rax = int value
        // Clear R9 flag (R9=0 → no ".0" suffix for regular ints)
        $this->b->emit("\x45\x31\xC9"); // xor r9d, r9d
        // call itoa helper: rax → rsi=ptr, rdx=len (uses stack buffer)
        $this->b->emit("\xE8");     // call rel32
        $this->b->rel32($this->itoaLabel);
        $this->doWrite(X64Builder::RSI, X64Builder::RDX);
    }

    // ===== Print: Float =====
    private function printFloat(ExprNode $e): void
    {
        $this->genExpr($e);
        // Move the double bit pattern to xmm0 for ftoa
        $this->b->movqToXmm(0, X64Builder::RAX);
        $this->b->emit("\xE8");
        $this->b->rel32($this->ftoaLabel);
        // After ftoa: rsi=ptr, rdx=len on stack buffer
        $this->doWrite(X64Builder::RSI, X64Builder::RDX);
    }

    // ===== Print: String =====
    private function printString(ExprNode $e): void
    {
        if ($e instanceof StringLiteralNode) {
            $label = $this->b->addString($e->value);
            $this->b->emit("\x48\x8D\x35"); // lea rsi, [rip+disp32]
            $this->b->rel32($label);
            $this->b->movRI32(X64Builder::RDX, strlen($e->value));
            $this->doWrite(X64Builder::RSI, X64Builder::RDX);
            return;
        }
        if ($e instanceof VarRefNode) {
            $offset = $this->vars[$e->name]['offset'] ?? throw new \RuntimeException("Undefined: {$e->name}");
            $info = $this->vars[$e->name];
            $varType = $info['type'];
            if ($varType === TphpType::String) {
                // Load string struct: ptr→rsi, len→rdx
                $this->b->movRM64(X64Builder::RSI, X64Builder::RBP, $offset);
                $this->b->movRM64(X64Builder::RDX, X64Builder::RBP, $offset + 8);
                $this->doWrite(X64Builder::RSI, X64Builder::RDX);
            } else {
                // Non-string variable in string context: print by its actual type
                $this->printByType($e, $varType, false);
            }
            return;
        }
        if ($e instanceof BinaryOpNode && $e->op === '.') {
            $folded = $this->tryFold($e);
            if ($folded !== null) {
                $label = $this->b->addString($folded);
                $this->b->emit("\x48\x8D\x35"); $this->b->rel32($label);
                $this->b->movRI32(X64Builder::RDX, strlen($folded));
                $this->doWrite(X64Builder::RSI, X64Builder::RDX);
                return;
            }
            // Runtime concat: emit left then right
            $this->printString($e->left);
            $this->printString($e->right);
            return;
        }
        if ($e instanceof IndexAccessNode) {
            // Check if this is an array element access (vs string char access)
            $isArrayElem = false;
            $elemType = null;
            if ($e->target instanceof VarRefNode) {
                $info = $this->vars[$e->target->name] ?? null;
                if ($info !== null && isset($info['elemType'])) {
                    $isArrayElem = true;
                    $elemType = $info['elemType'];
                }
            }
            if ($isArrayElem && $elemType === TphpType::String) {
                // Array element - genExpr produces the full value (RAX=ptr, RDX=len for strings)
                $this->genExpr($e);
                $this->b->movRR(X64Builder::RSI, X64Builder::RAX);  // RSI = ptr
                // RDX already has len from genArrayIndexRead
                $this->doWrite(X64Builder::RSI, X64Builder::RDX);
            } elseif ($isArrayElem) {
                // Non-string array element: print by its actual type
                $this->printByType($e, $elemType, false);
            } else {
                $this->printIndexAccess($e);
            }
            return;
        }
        if ($e instanceof StringRangeNode) {
            $this->printStringRange($e);
            return;
        }
        if ($e instanceof FuncCallNode) {
            $this->genExpr($e);
            // If it's strlen, the result is in rax as int
            $this->b->emit("\xE8"); $this->b->rel32($this->itoaLabel);
            $this->doWrite(X64Builder::RSI, X64Builder::RDX);
            return;
        }
        if ($e instanceof ExprCallNode) {
            $this->genExpr($e);                       // RAX=ptr, RDX=len (string return)
            $this->b->movRR(X64Builder::RSI, X64Builder::RAX);  // RSI = buf
            // RDX already = len
            $this->doWrite(X64Builder::RSI, X64Builder::RDX);
            return;
        }
        if ($e instanceof ConstRefNode) {
            $c = $this->consts[$e->name] ?? null;
            if ($c !== null) {
                $type = $this->inferConstType($e);
                $this->printByType($c->init, $type, false);
            }
            return;
        }
        if ($e instanceof CastExprNode) {
            if ($e->targetType === TphpType::Int) {
                $val = $this->evalCastToZero($e);
                if ($val === 0) { $lab = $this->b->addString('0'); $this->b->emit("\x48\x8D\x35"); $this->b->rel32($lab); $this->b->movRI32(X64Builder::RDX, 1); $this->doWrite(X64Builder::RSI, X64Builder::RDX); return; }
                if ($e->operand instanceof VarRefNode) { $info = $this->vars[$e->operand->name] ?? $this->calleeVars[$e->operand->name] ?? null; if ($info !== null && isset($info['elemType'])) { $this->genArrayIntPrint($info['offset']); return; } }
            }
            $this->genExpr($e);
            if ($e->targetType === TphpType::String) { $this->b->movRR(X64Builder::RSI, X64Builder::RAX); $this->doWrite(X64Builder::RSI, X64Builder::RDX); }
            elseif ($e->targetType === TphpType::Float) { $this->b->movqToXmm(0, X64Builder::RAX); $this->b->emit("\xE8"); $this->b->rel32($this->ftoaLabel); $this->doWrite(X64Builder::RSI, X64Builder::RDX); }
            else { $this->b->emit("\x45\x31\xC9"); $this->b->emit("\xE8"); $this->b->rel32($this->itoaLabel); $this->doWrite(X64Builder::RSI, X64Builder::RDX); }
            return;
        }
    }

    private function printIndexAccess(IndexAccessNode $e): void
    {
        $this->genExpr($e); // result: al = character
        // Use stack as temp buffer (avoids hardcoding [rbp-8] which may conflict with local vars)
        $this->b->pushReg(X64Builder::RAX);        // push character (al is low byte)
        $this->b->emit("\x48\x8D\x34\x24");        // lea rsi, [rsp]
        $this->b->movRI32(X64Builder::RDX, 1);
        $this->doWrite(X64Builder::RSI, X64Builder::RDX);
        $this->b->emit("\x48\x83\xC4\x08");        // add rsp, 8 (clean up push)
    }

    // ===== Echo: Bool =====
    private function printBool(ExprNode $e, bool $isVarDump): void
    {
        $this->genExpr($e);
        $falseL = $this->newLabel('.L.bool0');
        $endL = $this->newLabel('.L.bool1');

        $this->b->emit("\x48\x85\xC0"); // test rax,rax
        $this->b->jeLabel($falseL);

        // True
        if ($isVarDump) {
            $tlab = $this->b->addString('true');
            $this->b->emit("\x48\x8D\x35"); $this->b->rel32($tlab);
            $this->b->movRI32(X64Builder::RDX, 4);
        } else {
            $tlab = $this->b->addString('1');
            $this->b->emit("\x48\x8D\x35"); $this->b->rel32($tlab);
            $this->b->movRI32(X64Builder::RDX, 1);
        }
        $this->doWrite(X64Builder::RSI, X64Builder::RDX);
        $this->b->jmpLabel($endL);

        // False
        $this->b->defineLabel($falseL);
        if ($isVarDump) {
            $flab = $this->b->addString('false');
            $this->b->emit("\x48\x8D\x35"); $this->b->rel32($flab);
            $this->b->movRI32(X64Builder::RDX, 5);
            $this->doWrite(X64Builder::RSI, X64Builder::RDX);
        }
        // echo false: output nothing

        $this->b->defineLabel($endL);
    }

    // ===== Print: Null =====
    private function printNull(bool $isVarDump = true): void
    {
        if ($isVarDump) {
            $lab = $this->b->addString('');
            $this->b->emit("\x48\x8D\x35"); $this->b->rel32($lab);
            $this->b->xorRR(X64Builder::RDX, X64Builder::RDX);
            $this->doWrite(X64Builder::RSI, X64Builder::RDX);
        }
        // echo null: output nothing
    }

    // ===== sys_write wrapper =====

    private function writeTypePrefix(string $typeName): void
    {
        $prefix = "($typeName) ";
        $lab = $this->b->addString($prefix);
        $this->b->emit("\x48\x8D\x35"); // lea rsi, [rip+disp32]
        $this->b->rel32($lab);
        $this->b->movRI32(X64Builder::RDX, strlen($prefix));
        $this->doWrite(X64Builder::RSI, X64Builder::RDX);
    }

    private function emitNewline(): void
    {
        $lab = $this->b->addString("\n");
        $this->b->emit("\x48\x8D\x35"); // lea rsi, [rip+disp32]
        $this->b->rel32($lab);
        $this->b->movRI32(X64Builder::RDX, 1);
        $this->doWrite(X64Builder::RSI, X64Builder::RDX);
    }
    private function doWrite(int $ptrReg, int $lenReg): void
    {
        // Ensure ptr in rsi, len in rdx
        if ($ptrReg !== X64Builder::RSI) $this->b->movRR(X64Builder::RSI, $ptrReg);
        if ($lenReg !== X64Builder::RDX) $this->b->movRR(X64Builder::RDX, $lenReg);
        // fd = 1 (stdout)
        $this->b->movRI32(X64Builder::RDI, 1);
        // syscall number = 1 (write)
        $this->b->movRI32(X64Builder::RAX, 1);
        $this->b->syscall();
    }

    // ===== String fold =====
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

}
