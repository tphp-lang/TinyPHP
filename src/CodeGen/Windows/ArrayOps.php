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
trait ArrayOps
{

    /**
     * Generate string range access: $c[start][end] → bytes from start to end (exclusive).
     * Result: RAX = ptr + start, RDX = end - start (length)
     */
    private function genStringRange(StringRangeNode $e): void
    {
        // Load target string ptr → RAX, len → RDX
        if ($e->target instanceof VarRefNode) {
            $off = $this->vars[$e->target->name]['offset']
                ?? throw new \RuntimeException("Undefined: \${$e->target->name}");
            $this->b->movRM64(X64Builder::RAX, X64Builder::RBP, $off);      // rax = string ptr
        } else {
            $this->genExpr($e->target);
        }

        // Save original ptr
        $this->b->pushReg(X64Builder::RAX);

        // Evaluate start → RAX, save it
        $this->genExpr($e->start);
        $this->b->pushReg(X64Builder::RAX);

        // Evaluate end → RAX
        $this->genExpr($e->end);

        // pop rcx (start index)
        $this->b->popReg(X64Builder::RCX);

        // rax = end - start = length
        $this->b->subRR(X64Builder::RAX, X64Builder::RCX);
        $this->b->movRR(X64Builder::RDX, X64Builder::RAX);   // rdx = length

        // pop rax (original ptr), add start offset
        $this->b->popReg(X64Builder::RAX);
        $this->b->addRR(X64Builder::RAX, X64Builder::RCX);   // rax = ptr + start
        // Result: RAX = ptr+start, RDX = length
    }

    private function printStringRange(StringRangeNode $e): void
    {
        $this->genStringRange($e);  // RAX=ptr, RDX=len
        $this->b->movRR(X64Builder::R8,  X64Builder::RDX);  // R8 = len
        $this->b->movRR(X64Builder::RDX, X64Builder::RAX);  // RDX = ptr
        $this->doWriteFileRaw();
    }

    // ==================== Array ====================

    /**
     * Initialize array variable: store len, cap, and element data on stack.
     * Array layout at [rbp+offset]:
     *   +0:  len (int64)
     *   +8:  cap (int64)
     *   +16: data[0..MAX_CAP-1] (elementSize each)
     */
    private function genArrayInit(string $name, ExprNode $init): void
    {
        $info = $this->vars[$name]
            ?? throw new \RuntimeException("Array var \${$name} not found");
        $offset = $info['offset'];
        $elemType = $info['elemType'];
        $elemSize = $this->typeAllocSize($elemType);

        if (!$init instanceof ArrayLiteralNode) {
            throw new \RuntimeException("Expected array literal for \${$name}");
        }

        $count = count($init->elements);

        // Store len
        $this->b->movRI64(X64Builder::RAX, $count);
        $this->b->movMR64(X64Builder::RBP, $offset, X64Builder::RAX);

        // Store cap
        $this->b->movRI64(X64Builder::RAX, self::MAX_ARRAY_CAP);
        $this->b->movMR64(X64Builder::RBP, $offset + 8, X64Builder::RAX);

        // Store each element at offset + 16 + i * elemSize
        foreach ($init->elements as $i => $elem) {
            $elemOff = $offset + 16 + $i * $elemSize;
            $this->genExpr($elem);
            match ($elemType) {
                TphpType::String => $this->storeString($elemOff),
                TphpType::Float  => $this->storeFloat($elemOff),
                TphpType::Callable_ => $this->b->movMR64(X64Builder::RBP, $elemOff, X64Builder::RAX),
                default          => $this->b->movMR64(X64Builder::RBP, $elemOff, X64Builder::RAX),
            };
        }
    }

    /**
     * Read array element at index. Result matches the element type:
     * - Int/Bool/Null/Callable_: RAX = value
     * - Float: RAX = bit pattern (also in xmm0)
     * - String: RAX = ptr, RDX = len
     */
    private function genArrayIndexRead(array $info, ExprNode $index): void
    {
        $offset = $info['offset'];
        $elemType = $info['elemType'];
        $elemSize = $this->typeAllocSize($elemType);

        // Evaluate index → RAX
        $this->genExpr($index);

        // Bounds check: index < len; if out-of-bounds, clamp index to 0
        $this->b->movRR(X64Builder::RCX, X64Builder::RAX);        // rcx = index
        $this->b->movRM64(X64Builder::RDX, X64Builder::RBP, $offset); // rdx = len
        $this->b->cmpRR(X64Builder::RCX, X64Builder::RDX);
        $idxOkLabel = $this->newLabel('.L.idxok');
        $this->b->jlLabel($idxOkLabel);                           // if index < len, OK
        $this->b->xorRR(X64Builder::RCX, X64Builder::RCX);        // else clamp to 0
        $this->b->defineLabel($idxOkLabel);

        // Compute element address: [rbp + offset + 16 + index * elemSize]
        // Address = rbp + offset + 16 + rcx * elemSize
        $this->b->movRI64(X64Builder::RAX, $elemSize);
        $this->b->emit("\x48\x0F\xAF\xC1");                      // imul rax, rcx  (rax = index * elemSize)
        $this->b->emitAddRI32(X64Builder::RAX, $offset + 16);    // rax += offset + 16
        $this->b->addRR(X64Builder::RAX, X64Builder::RBP);       // rax += rbp (absolute address)

        // Load element based on type
        match ($elemType) {
            TphpType::String => $this->loadStringAtAddr(X64Builder::RAX),
            TphpType::Float  => $this->loadFloatAtAddr(X64Builder::RAX),
            default          => $this->b->movRM64(X64Builder::RAX, X64Builder::RAX, 0), // RAX = [RAX]
        };
    }



    /** count($a) → return array length */
    private function genCount(FuncCallNode $e): void
    {
        $arg = $e->args[0] ?? throw new \RuntimeException("count requires 1 arg, line {$e->line}");
        if (!$arg instanceof VarRefNode) {
            throw new \RuntimeException("count() argument must be a variable, line {$e->line}");
        }
        $info = $this->vars[$arg->name] ?? throw new \RuntimeException("Undefined: \${$arg->name}");
        if (!isset($info['elemType'])) {
            throw new \RuntimeException("count() argument must be an array, line {$e->line}");
        }
        // Load len from [rbp + offset]
        $this->b->movRM64(X64Builder::RAX, X64Builder::RBP, $info['offset']);
    }

    /** $a[] = value → append to array */
    private function genArrayAppend(ArrayAppendStmtNode $s): void
    {
        $info = $this->vars[$s->name] ?? throw new \RuntimeException("Undefined array: \${$s->name}");
        $offset = $info['offset'];
        $elemType = $info['elemType'];
        $elemSize = $this->typeAllocSize($elemType);

        // Load len → RCX
        $this->b->movRM64(X64Builder::RCX, X64Builder::RBP, $offset);

        // Capacity check: if len >= MAX_ARRAY_CAP, skip append to prevent buffer overflow
        $capOkLabel = $this->newLabel('.L.arrcapok');
        $skipLabel  = $this->newLabel('.L.arrapp.skip');
        $this->b->cmpRI32(X64Builder::RCX, self::MAX_ARRAY_CAP);
        $this->b->jlLabel($capOkLabel);
        $this->b->jmpLabel($skipLabel);
        $this->b->defineLabel($capOkLabel);

        // Compute address = rbp + offset + 16 + len * elemSize
        $this->b->movRR(X64Builder::RAX, X64Builder::RCX);
        $this->b->movRI64(X64Builder::RDX, $elemSize);
        $this->b->emit("\x48\x0F\xAF\xC2");                    // imul rax, rdx
        $this->b->emitAddRI32(X64Builder::RAX, $offset + 16);  // rax += offset + 16
        $this->b->addRR(X64Builder::RAX, X64Builder::RBP);     // rax = absolute address

        $this->b->pushReg(X64Builder::RAX);                     // save address

        // Evaluate value
        $this->genExpr($s->value);

        // Pop address → RCX, store value
        $this->b->popReg(X64Builder::RCX);
        match ($elemType) {
            TphpType::String => $this->storeStringAt(X64Builder::RCX),
            TphpType::Float  => $this->storeFloatAt(X64Builder::RCX),
            default          => $this->b->movMR64(X64Builder::RCX, 0, X64Builder::RAX),
        };

        // Increment len: add qword [rbp+offset], 1
        $this->b->emitAddMemImm64(X64Builder::RBP, $offset, 1);

        $this->b->defineLabel($skipLabel);
    }



    /** unset($a[index]) → remove element at index, shift elements down */
    private function genUnset(UnsetStmtNode $s): void
    {
        $info = $this->vars[$s->name] ?? throw new \RuntimeException("Undefined array: \${$s->name}");
        $offset = $info['offset'];
        $elemType = $info['elemType'];
        $elemSize = $this->typeAllocSize($elemType);

        // Evaluate index → RAX
        $this->genExpr($s->index);
        $this->b->movRR(X64Builder::RCX, X64Builder::RAX);     // rcx = index

        // Load len → RDX
        $this->b->movRM64(X64Builder::RDX, X64Builder::RBP, $offset);

        // If index >= len, skip
        $skipLabel = $this->newLabel('.L.unset.skip');
        $this->b->cmpRR(X64Builder::RCX, X64Builder::RDX);
        $this->b->jgeLabel($skipLabel);

        // Number of elements to shift = len - index - 1
        $this->b->movRR(X64Builder::R8, X64Builder::RDX);
        $this->b->subRR(X64Builder::R8, X64Builder::RCX);
        $this->b->subRI32(X64Builder::R8, 1);

        $noShiftLabel = $this->newLabel('.L.unset.noshift');
        $this->b->cmpRI32(X64Builder::R8, 0);
        $this->b->jleLabel($noShiftLabel);

        // Src address = rbp + offset + 16 + (index+1) * elemSize
        $this->b->movRR(X64Builder::RAX, X64Builder::RCX);
        $this->b->emitAddRI32(X64Builder::RAX, 1);              // rax = index + 1
        $this->b->pushReg(X64Builder::RCX);                      // save index
        $this->b->movRI64(X64Builder::RCX, $elemSize);
        $this->b->emit("\x48\x0F\xAF\xC1");                     // imul rax, rcx
        $this->b->popReg(X64Builder::RCX);
        $this->b->emitAddRI32(X64Builder::RAX, $offset + 16);
        $this->b->addRR(X64Builder::RAX, X64Builder::RBP);      // rax = src addr

        // Dest = src - elemSize
        $this->b->movRR(X64Builder::RDI, X64Builder::RAX);
        $this->b->subRI32(X64Builder::RDI, $elemSize);          // rdi = dest addr
        $this->b->movRR(X64Builder::RSI, X64Builder::RAX);      // rsi = src addr

        // Shift loop: for each element, copy from src to dest, advance both
        $shiftLoop = $this->newLabel('.L.unset.shift');
        $this->b->defineLabel($shiftLoop);

        // Copy elemSize bytes: use a small loop per byte or just copy the whole element at once
        // For simplicity: treat element as raw bytes and use mov+advance
        if ($elemSize === 8) {
            $this->b->movRM64(X64Builder::RAX, X64Builder::RSI, 0);  // load from src
            $this->b->movMR64(X64Builder::RDI, 0, X64Builder::RAX);  // store to dest
        } elseif ($elemSize === 16) {
            // Copy 16 bytes (string struct)
            $this->b->movRM64(X64Builder::RAX, X64Builder::RSI, 0);
            $this->b->movMR64(X64Builder::RDI, 0, X64Builder::RAX);
            $this->b->movRM64(X64Builder::RAX, X64Builder::RSI, 8);
            $this->b->movMR64(X64Builder::RDI, 8, X64Builder::RAX);
        } else {
            // 1 byte (bool): mov al, [rsi]; stosb
            $this->b->emit("\x8A\x06");                          // mov al, [rsi]
            $this->b->emit("\x88\x07");                          // mov [rdi], al
        }

        // Advance src and dest
        $this->b->emitAddRI32(X64Builder::RSI, $elemSize);
        $this->b->emitAddRI32(X64Builder::RDI, $elemSize);

        // Dec count, loop if > 0
        $this->b->subRI32(X64Builder::R8, 1);
        $this->b->cmpRI32(X64Builder::R8, 0);
        $this->b->jgLabel($shiftLoop);

        $this->b->defineLabel($noShiftLabel);

        // Decrement len: sub qword [rbp+offset], 1
        $this->b->emitSubMemImm64(X64Builder::RBP, $offset, 1);

        $this->b->defineLabel($skipLabel);
    }

    /** echo array: print "(array(type)) [elem1, elem2, ...]" */
    private function printArray(ExprNode $e): void
    {
        if (!$e instanceof VarRefNode) {
            // Array literal in echo - not common, skip for now
            return;
        }
        $info = $this->vars[$e->name] ?? throw new \RuntimeException("Undefined: \${$e->name}");
        if (!isset($info['elemType'])) {
            throw new \RuntimeException("printArray: not an array: \${$e->name}, line {$e->line}");
        }
        $offset = $info['offset'];
        $elemType = $info['elemType'];
        $elemSize = $this->typeAllocSize($elemType);

        // Print "(array(" + type + ")) ["
        $prefix = "(array(" . $elemType->value . ")) [";
        $prefixLab = $this->b->addString($prefix);
        $this->b->emit("\x48\x8D\x15"); $this->b->rel32($prefixLab);
        $this->b->movRI32(X64Builder::R8, strlen($prefix));
        $this->doWriteFileRaw();

        // Load len
        $this->b->movRM64(X64Builder::RCX, X64Builder::RBP, $offset); // rcx = len

        // If empty, skip elements
        $emptyLabel = $this->newLabel('.L.arr.empty');
        $this->b->testRR(X64Builder::RCX, X64Builder::RCX);
        $this->b->jeLabel($emptyLabel);

        // Loop variable: i (R12) from 0 to len-1
        $loopStart  = $this->newLabel('.L.arr.loop');
        $loopEnd    = $this->newLabel('.L.arr.end');

        $this->b->emit("\x45\x31\xE4");                          // xor r12d, r12d  (i = 0)
        $this->b->movRR(X64Builder::R13, X64Builder::RCX);       // r13 = len

        // Save R12, R13 (we'll use them across echo calls)
        $this->b->pushReg(X64Builder::R12);
        $this->b->pushReg(X64Builder::R13);

        $this->b->defineLabel($loopStart);
        $this->b->cmpRR(X64Builder::R12, X64Builder::R13);
        $this->b->jgeLabel($loopEnd);

        // Print comma separator if i > 0
        $commaLabel = $this->newLabel('.L.arr.comma');
        $this->b->testRR(X64Builder::R12, X64Builder::R12);
        $this->b->jeLabel($commaLabel);
        $sep = $this->b->addString(', ');
        $this->b->emit("\x48\x8D\x15"); $this->b->rel32($sep);
        $this->b->movRI32(X64Builder::R8, 2);
        $this->doWriteFileRaw();
        $this->b->defineLabel($commaLabel);

        // Compute element address: rbp + offset + 16 + i * elemSize
        $this->b->movRR(X64Builder::RAX, X64Builder::R12);
        $this->b->movRI64(X64Builder::RCX, $elemSize);
        $this->b->emit("\x48\x0F\xAF\xC1");                     // imul rax, rcx
        $this->b->emitAddRI32(X64Builder::RAX, $offset + 16);
        $this->b->addRR(X64Builder::RAX, X64Builder::RBP);      // rax = elem address

        // Print element based on type
        $this->printArrayElement($elemType, X64Builder::RAX);

        // i++
        $this->b->emit("\x49\xFF\xC4");                          // inc r12
        $this->b->jmpLabel($loopStart);

        $this->b->defineLabel($loopEnd);
        $this->b->popReg(X64Builder::R13);
        $this->b->popReg(X64Builder::R12);

        $this->b->defineLabel($emptyLabel);

        // Print "]"
        $endLab = $this->b->addString(']');
        $this->b->emit("\x48\x8D\x15"); $this->b->rel32($endLab);
        $this->b->movRI32(X64Builder::R8, 1);
        $this->doWriteFileRaw();
    }

    private function printArrayElement(TphpType $elemType, int $addrReg): void
    {
        // Preserve addrReg (RAX usually) on stack before operations that may clobber it
        $prefix = match ($elemType) {
            TphpType::Int    => 'int',
            TphpType::Float  => 'float',
            TphpType::String => 'string',
            TphpType::Bool   => 'bool',
            TphpType::Null   => 'null',
            TphpType::Callable_ => 'callback',
            default => '?',
        };
        // For brevity, skip type prefix per element (just print the value)
        // WriteTypePrefix would add too much noise

        match ($elemType) {
            TphpType::Int => $this->printArrayIntElem($addrReg),
            TphpType::Float => $this->printArrayFloatElem($addrReg),
            TphpType::String => $this->printArrayStringElem($addrReg),
            TphpType::Bool => $this->printArrayBoolElem($addrReg),
            TphpType::Null => $this->printNullString(),
            TphpType::Callable_ => $this->printCallableString(),
            default => null,
        };
    }

    private function printArrayIntElem(int $addrReg): void
    {
        $this->b->movRM64(X64Builder::RAX, $addrReg, 0);       // load int value
        // Use itoa
        $this->b->emit("\x48\x8D\xBD");                          // lea rdi, [rbp+disp32]
        $this->b->emit32($this->itoaBufOffset + 31);
        $this->b->emit("\x45\x31\xC9");                          // xor r9d, r9d
        $this->b->emit("\xE8");
        $this->b->rel32($this->itoaLabel);
        $this->doWriteFile(X64Builder::RSI, X64Builder::RDX);
    }

    private function printArrayFloatElem(int $addrReg): void
    {
        $this->b->movsdLoad(0, $addrReg, 0);
        $this->b->emit("\x48\x8D\xBD");                          // lea rdi, [rbp+disp32]
        $this->b->emit32($this->itoaBufOffset + 31);
        $this->b->emit("\xE8");
        $this->b->rel32($this->ftoaLabel);
        $this->doWriteFile(X64Builder::RSI, X64Builder::RDX);
    }

    private function printArrayStringElem(int $addrReg): void
    {
        // String is 16 bytes at [addrReg]: ptr(8) + len(8)
        $this->b->movRM64(X64Builder::RDX, $addrReg, 0);       // rdx = ptr
        $this->b->movRM64(X64Builder::R8,  $addrReg, 8);       // r8  = len
        $this->doWriteFileRaw();
    }

    private function printArrayBoolElem(int $addrReg): void
    {
        $this->b->movRM64(X64Builder::RAX, $addrReg, 0);
        $falseL = $this->newLabel('.L.ab0');
        $endL   = $this->newLabel('.L.ab1');
        $this->b->testRR(X64Builder::RAX, X64Builder::RAX);
        $this->b->jeLabel($falseL);
        $labT = $this->b->addString('true');
        $this->b->emit("\x48\x8D\x15"); $this->b->rel32($labT);
        $this->b->movRI32(X64Builder::R8, 4);
        $this->doWriteFileRaw();
        $this->b->jmpLabel($endL);
        $this->b->defineLabel($falseL);
        $labF = $this->b->addString('false');
        $this->b->emit("\x48\x8D\x15"); $this->b->rel32($labF);
        $this->b->movRI32(X64Builder::R8, 5);
        $this->doWriteFileRaw();
        $this->b->defineLabel($endL);
    }

    private function printNullString(): void
    {
        // print nothing for null
    }

    private function printCallableString(): void
    {
        $lab = $this->b->addString('(callback)');
        $this->b->emit("\x48\x8D\x15"); $this->b->rel32($lab);
        $this->b->movRI32(X64Builder::R8, 10);
        $this->doWriteFileRaw();
    }

}
