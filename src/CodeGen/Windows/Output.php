<?php

declare(strict_types=1);

namespace Tphp\CodeGen\Windows;

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
 * Extracted from CodeGeneratorWindows
 */
trait Output
{

    private function genPrint(PrintStmtNode $s): void
    {
        foreach ($s->args as $arg) {
            // Check for enum var_dump: (enum) MyInt::A
            if ($s->isVarDump && $arg instanceof VarRefNode) {
                $varInfo = $this->calleeVars[$arg->name] ?? $this->vars[$arg->name] ?? null;
                if ($varInfo && isset($varInfo['enumName'])) {
                    $this->printEnumCase($arg, $varInfo);
                    $this->emitNewline();
                    continue;
                }
            }
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

    /** Output (enum) MyInt::A format */
    private function printEnumCase(VarRefNode $e, array $varInfo): void
    {
        $enumFqn = $varInfo['enumName'];
        $cases = $this->enums[$enumFqn] ?? [];
        $shortName = substr(strrchr('\\' . $enumFqn, '\\'), 1);

        // Load the value
        $this->genVarRead($e);

        if (is_int(reset($cases))) {
            // Int enum: value in RAX, compare and print case name
            $doneLabel = $this->newLabel('.L.ec.done');
            foreach ($cases as $caseName => $caseValue) {
                $nextLabel = $this->newLabel('.L.ec.next');
                $this->b->cmpRI32(X64Builder::RAX, $caseValue);
                $this->b->jneLabel($nextLabel);
                $output = "(enum) " . $shortName . '::' . $caseName;
                $outLab = $this->b->addString($output);
                $this->b->emit("\x48\x8D\x15"); $this->b->rel32($outLab);
                $this->b->movRI32(X64Builder::R8, strlen($output));
                $this->doWriteFileRaw();
                $this->b->jmpLabel($doneLabel);
                $this->b->defineLabel($nextLabel);
            }
            // Fallback: output raw int value
            $this->b->emit("\x48\x8D\xBD"); $this->b->emit32($this->itoaBufOffset + 31);
            $this->b->emit("\x45\x31\xC9");
            $this->b->emit("\xE8"); $this->b->rel32($this->itoaLabel);
            $this->doWriteFile(X64Builder::RSI, X64Builder::RDX);
            $this->b->defineLabel($doneLabel);
        } elseif (is_string(reset($cases))) {
            // String enum: print enum name + case via value lookup
            $doneLabel = $this->newLabel('.L.ec.sdone');
            foreach ($cases as $caseName => $caseValue) {
                $nextLabel = $this->newLabel('.L.ec.snext');
                // Write case string to itoa buffer for comparison
                $caseStr = (string)$caseValue;
                $buf = $this->itoaBufOffset;
                for ($i = 0; $i < strlen($caseStr); $i++) {
                    $this->b->emit("\xC6\x85"); $this->b->emit32($buf + $i); $this->b->emit8(ord($caseStr[$i]));
                }
                // Compare using rep cmpsb (simple approach: compare len then bytes)
                $this->b->movRM64(X64Builder::R8, X64Builder::RBP, $varInfo['offset'] + 8); // len
                $this->b->cmpRI32(X64Builder::R8, strlen($caseStr));
                $this->b->jneLabel($nextLabel);
                $this->b->movRM64(X64Builder::RSI, X64Builder::RBP, $varInfo['offset']); // ptr
                $this->b->emit("\x48\x8D\xBD"); $this->b->emit32($buf); // lea rdi, [rbp+buf]
                $this->b->movRI32(X64Builder::RCX, strlen($caseStr));
                $this->b->emit("\xFC\xF3\xA6"); // cld; repe cmpsb
                $this->b->jneLabel($nextLabel);
                // Match! Output
                $output = "(enum) " . $shortName . '::' . $caseName;
                $outLab = $this->b->addString($output);
                $this->b->emit("\x48\x8D\x15"); $this->b->rel32($outLab);
                $this->b->movRI32(X64Builder::R8, strlen($output));
                $this->doWriteFileRaw();
                $this->b->jmpLabel($doneLabel);
                $this->b->defineLabel($nextLabel);
            }
            // Fallback: output raw bytes
            $this->b->movRM64(X64Builder::RDX, X64Builder::RBP, $varInfo['offset']);
            $this->b->movRM64(X64Builder::R8, X64Builder::RBP, $varInfo['offset'] + 8);
            $this->doWriteFileRaw();
            $this->b->defineLabel($doneLabel);
        }
    }

    private function emitNewline(): void
    {
        $lab = $this->b->addString("\n");
        $this->b->emit("\x48\x8D\x15");          // lea rdx, [rip+disp32]
        $this->b->rel32($lab);
        $this->b->movRI32(X64Builder::R8, 1);
        $this->doWriteFileRaw();
    }

    // ---- Print: Int ----
    private function printInt(ExprNode $e): void
    {
        // Pass itoa buffer pointer in RDI (buffer_end = buf_start + 31 for reverse fill)
        $this->b->emit("\x48\x8D\xBD");             // lea rdi, [rbp+disp32]
        $this->b->emit32($this->itoaBufOffset + 31);
        $this->genExpr($e);            // rax = int value
        // Clear R9 flag (R9=0 → no ".0" suffix for regular ints)
        $this->b->emit("\x45\x31\xC9");             // xor r9d, r9d
        // call itoa(rax) → writes to buffer at RDI, returns rsi=buf_start, rdx=len
        $this->b->emit("\xE8");
        $this->b->rel32($this->itoaLabel);
        $this->doWriteFile(X64Builder::RSI, X64Builder::RDX);
    }

    // ---- Print: Float ----
    private function printFloat(ExprNode $e): void
    {
        // Pass itoa buffer pointer in RDI
        $this->b->emit("\x48\x8D\xBD");             // lea rdi, [rbp+disp32]
        $this->b->emit32($this->itoaBufOffset + 31);
        $this->genExpr($e);
        $this->b->movqToXmm(0, X64Builder::RAX);
        $this->b->emit("\xE8");
        $this->b->rel32($this->ftoaLabel);
        $this->doWriteFile(X64Builder::RSI, X64Builder::RDX);
    }

    // ---- Print: String ----
    private function printString(ExprNode $e): void
    {
        // Handle method calls, function calls, and enum access by evaluating first
        if ($e instanceof MethodCallNode || $e instanceof FuncCallNode) {
            $returnType = TphpType::Int;
            if ($e instanceof MethodCallNode) {
                $clsName = '';
                if ($e->object instanceof VarRefNode) {
                    $varInfo = $this->vars[$e->object->name] ?? [];
                    $clsName = $varInfo['className'] ?? '';
                } elseif ($e->object instanceof ThisExprNode) {
                    $clsName = $this->currentClassName;
                }
                if ($clsName) {
                    $mangled = $clsName . '::' . $e->methodName;
                    $fn = $this->functionNodes[$mangled] ?? null;
                    $returnType = $fn?->returnType ?? TphpType::Int;
                }
            } elseif ($e instanceof FuncCallNode) {
                $name = $this->importMap[$e->name] ?? $e->name;
                $fn = $this->functionNodes[$name] ?? null;
                $returnType = $fn?->returnType ?? TphpType::Int;
            }
            $this->genExpr($e);
            if ($returnType === TphpType::String) {
                $this->b->movRR(X64Builder::R8,  X64Builder::RDX);
                $this->b->movRR(X64Builder::RDX, X64Builder::RAX);
                $this->doWriteFileRaw();
            } else {
                $this->b->emit("\x48\x8D\xBD"); $this->b->emit32($this->itoaBufOffset + 31);
                $this->b->emit("\x45\x31\xC9");
                $this->b->emit("\xE8"); $this->b->rel32($this->itoaLabel);
                $this->doWriteFile(X64Builder::RSI, X64Builder::RDX);
            }
            return;
        }
        if ($e instanceof EnumAccessNode) {
            $this->genExpr($e);
            // For int enums: RAX has the value, use itoa
            // For string enums: RAX has ptr, RDX has len
            $enumFqn = $this->classImportMap[$e->enumName] ?? $e->enumName;
            $cases = $this->enums[$enumFqn] ?? [];
            $value = $cases[$e->caseName] ?? 0;
            if (is_int($value)) {
                $this->b->emit("\x48\x8D\xBD"); $this->b->emit32($this->itoaBufOffset + 31);
                $this->b->emit("\x45\x31\xC9");
                $this->b->emit("\xE8"); $this->b->rel32($this->itoaLabel);
                $this->doWriteFile(X64Builder::RSI, X64Builder::RDX);
            } else {
                $this->b->movRR(X64Builder::R8,  X64Builder::RDX);
                $this->b->movRR(X64Builder::RDX, X64Builder::RAX);
                $this->doWriteFileRaw();
            }
            return;
        }
        if ($e instanceof StringLiteralNode) {
            $label = $this->b->addString($e->value);
            // lea rdx, [rip+disp32]
            $this->b->emit("\x48\x8D\x15");
            $this->b->rel32($label);
            $this->b->movRI32(X64Builder::R8, strlen($e->value)); // nBytes in r8
            $this->doWriteFileRaw(); // rdx=buf, r8=len
            return;
        }
        if ($e instanceof VarRefNode) {
            $info = $this->calleeVars[$e->name] ?? $this->vars[$e->name] ?? null;
            if ($info === null) {
                throw new \RuntimeException("Undefined: \${$e->name}");
            }
            $varType = $info['type'];
            if ($varType === TphpType::String) {
                // Load string ptr → rdx, len → r8
                $this->b->movRM64(X64Builder::RDX, X64Builder::RBP, $info['offset']);
                $this->b->movRM64(X64Builder::R8,  X64Builder::RBP, $info['offset'] + 8);
                $this->doWriteFileRaw();
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
                $this->b->emit("\x48\x8D\x15");
                $this->b->rel32($label);
                $this->b->movRI32(X64Builder::R8, strlen($folded));
                $this->doWriteFileRaw();
                return;
            }
            // Runtime concat: emit left then right
            $this->printString($e->left);
            $this->printString($e->right);
            return;
        }
        if ($e instanceof FuncCallNode) {
            if ($e->name === 'substr') {
                // For now, treat as empty
            }
            $this->genExpr($e);
            $this->b->movRR(X64Builder::R8,  X64Builder::RDX);  // R8 = len (must do this first!)
            $this->b->movRR(X64Builder::RDX, X64Builder::RAX); // RDX = ptr
            $this->doWriteFileRaw();
            return;
        }
        if ($e instanceof ExprCallNode) {
            // Closure call: genExpr executes the call, returns RAX=ptr, RDX=len
            $this->genExpr($e);
            $this->b->movRR(X64Builder::R8, X64Builder::RDX);  // R8 = len (must do this first!)
            $this->b->movRR(X64Builder::RDX, X64Builder::RAX); // RDX = ptr
            $this->doWriteFileRaw();
            return;
        }
        if ($e instanceof IndexAccessNode) {
            // Check if this is an array element access (vs string char access)
            $isArrayElem = false;
            $elemType = null;
            if ($e->target instanceof VarRefNode) {
                $info = $this->vars[$e->target->name] ?? $this->calleeVars[$e->target->name] ?? null;
                if ($info !== null && isset($info['elemType'])) {
                    $isArrayElem = true;
                    $elemType = $info['elemType'];
                }
            }
            if ($isArrayElem && $elemType === TphpType::String) {
                // genArrayIndexRead for String type returns RAX=ptr, RDX=len
                $this->genExpr($e);
                $this->b->movRR(X64Builder::R8, X64Builder::RDX);  // R8 = len (save before overwriting RDX)
                $this->b->movRR(X64Builder::RDX, X64Builder::RAX);  // RDX = ptr
                $this->doWriteFileRaw();
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
        if ($e instanceof ExprCallNode) {
            $this->genExpr($e);                       // RAX=ptr, RDX=len
            $this->b->movRR(X64Builder::R8,  X64Builder::RDX);  // R8 = len
            $this->b->movRR(X64Builder::RDX, X64Builder::RAX);  // RDX = ptr
            $this->doWriteFileRaw();
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
            // Array → int fast path: emit inline to bypass itoa
            if ($e->targetType === TphpType::Int && $e->operand instanceof VarRefNode) {
                $info = $this->calleeVars[$e->operand->name] ?? $this->vars[$e->operand->name] ?? null;
                if ($info !== null && isset($info['elemType'])) { $this->genArrayIntPrint($info['offset']); return; }
            }
            // Let genCast do all conversion; print result directly
            $this->genExpr($e);
            if ($e->targetType === TphpType::String) { $this->b->movRR(X64Builder::RSI, X64Builder::RAX); $this->doWriteFile(X64Builder::RSI, X64Builder::RDX); }
            elseif ($e->targetType === TphpType::Float) { $this->b->emit("\x48\x8D\xBD"); $this->b->emit32($this->itoaBufOffset + 31); $this->b->movqToXmm(0, X64Builder::RAX); $this->b->emit("\xE8"); $this->b->rel32($this->ftoaLabel); $this->doWriteFile(X64Builder::RSI, X64Builder::RDX); }
            else { $this->b->emit("\x48\x8D\xBD"); $this->b->emit32($this->itoaBufOffset + 31); $this->b->emit("\x45\x31\xC9"); $this->b->emit("\xE8"); $this->b->rel32($this->itoaLabel); $this->doWriteFile(X64Builder::RSI, X64Builder::RDX); }
            return;
        }
    }

    private function printIndexAccess(IndexAccessNode $e): void
    {
        $this->genExpr($e); // result: al = character
        // Use itoa buffer as temp storage (safe: not used during printIndexAccess)
        $this->b->emit("\x88\x85");                     // mov [rbp+disp32], al
        $this->b->emit32($this->itoaBufOffset);
        $this->b->emit("\x48\x8D\x95");                 // lea rdx, [rbp+disp32]
        $this->b->emit32($this->itoaBufOffset);
        $this->b->movRI32(X64Builder::R8, 1);           // nBytes = 1
        $this->doWriteFileRaw();
    }

    // ---- Print: Bool ----
    private function printBool(ExprNode $e, bool $isVarDump): void
    {
        $this->genExpr($e);
        $falseL = $this->newLabel('.L.bool0');
        $endL   = $this->newLabel('.L.bool1');

        $this->b->testRR(X64Builder::RAX, X64Builder::RAX);
        $this->b->jeLabel($falseL);

        // True
        if ($isVarDump) {
            $labT = $this->b->addString('true');
            $this->b->emit("\x48\x8D\x15"); $this->b->rel32($labT);
            $this->b->movRI32(X64Builder::R8, 4);
        } else {
            $labT = $this->b->addString('1');
            $this->b->emit("\x48\x8D\x15"); $this->b->rel32($labT);
            $this->b->movRI32(X64Builder::R8, 1);
        }
        $this->doWriteFileRaw();
        $this->b->jmpLabel($endL);

        // False
        $this->b->defineLabel($falseL);
        if ($isVarDump) {
            $labF = $this->b->addString('false');
            $this->b->emit("\x48\x8D\x15"); $this->b->rel32($labF);
            $this->b->movRI32(X64Builder::R8, 5);
            $this->doWriteFileRaw();
        }
        // echo false: output nothing (skip write)

        $this->b->defineLabel($endL);
    }

    // ---- Print: Null ----
    private function printNull(bool $isVarDump = true): void
    {
        if ($isVarDump) {
            $lab = $this->b->addString('');
            $this->b->emit("\x48\x8D\x15"); $this->b->rel32($lab);
            $this->b->xorRR(X64Builder::R8, X64Builder::R8);
            $this->doWriteFileRaw();
        }
        // echo null: output nothing
    }

}
