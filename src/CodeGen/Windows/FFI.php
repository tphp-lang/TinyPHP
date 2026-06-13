<?php

declare(strict_types=1);

namespace Tphp\CodeGen\Windows;

use Tphp\X64Builder;
use Tphp\AST\{TphpType, FuncCallNode, CFuncCallNode};

/**
 * Windows PE FFI (Foreign Function Interface) support.
 *
 * Loads DLLs at runtime via LoadLibraryA/GetProcAddress,
 * converts between TPHP and C types, and calls extern C functions.
 * Used as a trait by CodeGeneratorWindows.
 */
trait FFI
{
    /** Resolve function pointers from DLL at runtime — stores in main's stack frame */
    private function emitExternInit(): void
    {
        if (empty($this->externDecls)) return;

        if (empty($this->libPaths)) {
            throw new \RuntimeException("#extern requires -lib <path>");
        }
        $dllPath = str_replace('/', '\\', $this->libPaths[0]);
        $dllBytes = $dllPath . "\0";
        $dllLen = strlen($dllBytes);
        $dllAligned = (($dllLen + 7) & ~7);
        $totalAlloc = 32 + $dllAligned;

        $this->b->emit("\x48\x83\xEC"); $this->b->emit8($totalAlloc);
        for ($i = 0; $i < $dllLen; $i++) {
            $this->b->emit("\xC6\x44\x24");
            $this->b->emit8(32 + $i);
            $this->b->emit8(ord($dllBytes[$i]));
        }
        $this->b->emit("\x48\x8D\x4C\x24\x20"); // lea rcx, [rsp + 32]
        $this->b->callIat(self::IAT_LOADLIBRARYA);
        $this->b->emit("\x48\x83\xC4"); $this->b->emit8($totalAlloc);
        $this->b->movRR(X64Builder::R12, X64Builder::RAX);

        $loadOk = $this->newLabel('.L.ffi.ok');
        $this->b->testRR(X64Builder::R12, X64Builder::R12);
        $this->b->jneLabel($loadOk);
        $this->b->emit("\x48\x83\xEC\x20");
        $this->b->movRI32(X64Builder::RCX, 1);
        $this->b->callIat(self::IAT_EXITPROCESS);
        $this->b->defineLabel($loadOk);

        $slot = 0;
        $ffiCount = count($this->externDecls);
        foreach ($this->externDecls as $name => $_) {
            $fnBytes = $name . "\0";
            $fnLen = strlen($fnBytes);
            $fnAligned = (($fnLen + 7) & ~7);
            $fnTotal = 32 + $fnAligned;

            $this->b->emit("\x48\x83\xEC"); $this->b->emit8($fnTotal);
            for ($i = 0; $i < $fnLen; $i++) {
                $this->b->emit("\xC6\x44\x24");
                $this->b->emit8(32 + $i);
                $this->b->emit8(ord($fnBytes[$i]));
            }
            $this->b->emit("\x48\x8D\x54\x24\x20");             // lea rdx, [rsp + 32]
            $this->b->movRR(X64Builder::RCX, X64Builder::R12);
            $this->b->callIat(self::IAT_GETPROCADDRESS);
            $this->b->emit("\x48\x83\xC4"); $this->b->emit8($fnTotal);

            $off = $this->stdoutStackOffset - 8 * ($ffiCount - $slot);
            $this->b->movMR64(X64Builder::RBP, $off, X64Builder::RAX);
            $slot++;
        }
    }

    /** No longer needed — storage uses writable stack memory */
    private function emitExternStorage(): void {}

    /** CStr($str): convert TPHP string to null-terminated C string (heap-allocated) */
    private function genCStr(FuncCallNode $e): void
    {
        $arg = $e->args[0] ?? throw new \RuntimeException("CStr requires 1 arg");
        $this->genExpr($arg); // RAX=ptr, RDX=len

        $this->b->movRR(X64Builder::R12, X64Builder::RAX);
        $this->b->movRR(X64Builder::R13, X64Builder::RDX);

        $this->b->emit("\x48\x83\xEC\x28");
        $this->b->callIat(self::IAT_GETPROCESSHEAP);
        $this->b->emit("\x48\x83\xC4\x28");
        $this->b->movRR(X64Builder::RCX, X64Builder::RAX);

        $this->b->xorRR(X64Builder::RDX, X64Builder::RDX);
        $this->b->movRR(X64Builder::R8, X64Builder::R13);
        $this->b->emitAddRI32(X64Builder::R8, 1);
        $this->b->emit("\x48\x83\xEC\x28");
        $this->b->callIat(self::IAT_HEAPALLOC);
        $this->b->emit("\x48\x83\xC4\x28");
        $this->b->movRR(X64Builder::R14, X64Builder::RAX);

        $this->b->emit("\xFC");
        $this->b->movRR(X64Builder::RDI, X64Builder::R14);
        $this->b->movRR(X64Builder::RSI, X64Builder::R12);
        $this->b->movRR(X64Builder::RCX, X64Builder::R13);
        $this->b->emit("\xF3\xA4");
        $this->b->emit("\xC6\x07\x00"); // null terminator

        $this->b->movRR(X64Builder::RAX, X64Builder::R14);
    }

    /** CInt($val): int → int (identity) */
    private function genCInt(FuncCallNode $e): void
    {
        $this->genExpr($e->args[0] ?? throw new \RuntimeException("CInt requires 1 arg"));
    }

    /** CFloat($val): float → double (identity) */
    private function genCFloat(FuncCallNode $e): void
    {
        $this->genExpr($e->args[0] ?? throw new \RuntimeException("CFloat requires 1 arg"));
    }

    /** CBool($val): TPHP bool → C bool (0 or 1) */
    private function genCBool(FuncCallNode $e): void
    {
        $this->genExpr($e->args[0] ?? throw new \RuntimeException("CBool requires 1 arg"));
    }

    /** TInt($val): C int → TPHP int (identity) */
    private function genTInt(FuncCallNode $e): void
    {
        $this->genExpr($e->args[0] ?? throw new \RuntimeException("TInt requires 1 arg"));
    }

    /** TFloat($val): C double → TPHP float (identity) */
    private function genTFloat(FuncCallNode $e): void
    {
        if (isset($e->args[0])) $this->genExpr($e->args[0]);
    }

    /** TBool($val): C bool → TPHP int (0 or 1) */
    private function genTBool(FuncCallNode $e): void
    {
        if (isset($e->args[0])) $this->genExpr($e->args[0]);
    }

    /** TStr($ptr): C char* → TPHP string (copy from C string to heap) */
    private function genTStr(FuncCallNode $e): void
    {
        $arg = $e->args[0] ?? throw new \RuntimeException("TStr requires 1 arg");
        $this->genExpr($arg);

        $this->b->movRR(X64Builder::R12, X64Builder::RAX);

        $loopL  = $this->newLabel('.L.tstr.l');
        $doneL  = $this->newLabel('.L.tstr.d');
        $this->b->movRR(X64Builder::RDI, X64Builder::R12);
        $this->b->xorRR(X64Builder::RCX, X64Builder::RCX);
        $this->b->defineLabel($loopL);
        $this->b->emit("\x80\x3C\x0F\x00");    // cmp byte [rdi+rcx], 0
        $this->b->jeLabel($doneL);
        $this->b->emit("\x48\xFF\xC1");        // inc rcx
        $this->b->jmpLabel($loopL);
        $this->b->defineLabel($doneL);

        $this->b->movRR(X64Builder::R13, X64Builder::RCX);

        $this->b->emit("\x48\x83\xEC\x28");
        $this->b->callIat(self::IAT_GETPROCESSHEAP);
        $this->b->emit("\x48\x83\xC4\x28");
        $this->b->movRR(X64Builder::RCX, X64Builder::RAX);
        $this->b->xorRR(X64Builder::RDX, X64Builder::RDX);
        $this->b->movRR(X64Builder::R8, X64Builder::R13);
        $this->b->emit("\x48\x83\xEC\x28");
        $this->b->callIat(self::IAT_HEAPALLOC);
        $this->b->emit("\x48\x83\xC4\x28");
        $this->b->movRR(X64Builder::R14, X64Builder::RAX);

        $this->b->emit("\xFC");
        $this->b->movRR(X64Builder::RDI, X64Builder::R14);
        $this->b->movRR(X64Builder::RSI, X64Builder::R12);
        $this->b->movRR(X64Builder::RCX, X64Builder::R13);
        $this->b->emit("\xF3\xA4");

        $this->b->movRR(X64Builder::RAX, X64Builder::R14);
        $this->b->movRR(X64Builder::RDX, X64Builder::R13);
    }

    /** Call extern C function through stored function pointer */
    private function genCFuncCall(CFuncCallNode $e): void
    {
        if (!isset($this->externDecls[$e->funcName])) {
            throw new \RuntimeException("Undefined extern function: {$e->funcName}");
        }

        $names = array_keys($this->externDecls);
        $slot = array_search($e->funcName, $names, true);
        $ffiCount = count($this->externDecls);
        $ptrOff = $this->stdoutStackOffset - 8 * ($ffiCount - $slot);

        $paramRegs = [X64Builder::RCX, X64Builder::RDX, X64Builder::R8, X64Builder::R9];
        $ri = 0;
        foreach ($e->args as $arg) {
            $this->genExpr($arg);
            if ($ri < 4) {
                if ($paramRegs[$ri] !== X64Builder::RAX) {
                    $this->b->movRR($paramRegs[$ri], X64Builder::RAX);
                }
                $ri++;
            }
        }

        $this->b->movRM64(X64Builder::R11, X64Builder::RBP, $ptrOff);
        $skipLabel = $this->newLabel('.L.ffi.skipcall');
        $this->b->testRR(X64Builder::R11, X64Builder::R11);
        $this->b->jeLabel($skipLabel);
        $this->b->emit("\x48\x83\xEC\x20"); // sub rsp, 32
        $this->b->emit("\x41\xFF\xD3");     // call r11
        $this->b->emit("\x48\x83\xC4\x20"); // add rsp, 32
        $this->b->defineLabel($skipLabel);
    }

    /** Infer return type of an extern C function call */
    private function inferCFuncType(CFuncCallNode $e): TphpType
    {
        $ext = $this->externDecls[$e->funcName] ?? null;
        return $ext ? $ext->returnType : TphpType::Void;
    }
}
