<?php

declare(strict_types=1);

namespace Tphp\CodeGen\Windows;

use Tphp\X64Builder;

/**
 * Windows PE helper routines: heap cleanup, itoa, ftoa, atoi.
 * Used as a trait by CodeGeneratorWindows.
 */
trait Helpers
{
    /**
     * Emit HeapFree calls for all tracked heap-allocated string variables
     * at function/closure exit. Uses R12 to cache the heap handle across
     * multiple calls (R12 is callee-saved, restored by epilogue pop).
     */
    private function emitHeapFreeEpilogue(): void
    {
        if (empty($this->heapVarOffsets)) {
            return;
        }

        // GetProcessHeap() → R12 (callee-saved, survives HeapFree calls)
        $this->b->emit("\x48\x83\xEC\x28");
        $this->b->callIat(self::IAT_GETPROCESSHEAP);
        $this->b->emit("\x48\x83\xC4\x28");
        $this->b->movRR(X64Builder::R12, X64Builder::RAX);

        foreach ($this->heapVarOffsets as $offset) {
            // Skip if ptr is null (zero-initialized / never assigned)
            $skipLabel = $this->newLabel('.L.hfskip');
            $this->b->movRM64(X64Builder::RAX, X64Builder::RBP, $offset);
            $this->b->testRR(X64Builder::RAX, X64Builder::RAX);
            $this->b->jeLabel($skipLabel);

            // HeapFree(hHeap=R12, dwFlags=0, lpMem=[rbp+offset])
            $this->b->movRR(X64Builder::RCX, X64Builder::R12);
            $this->b->xorRR(X64Builder::RDX, X64Builder::RDX);
            $this->b->movRM64(X64Builder::R8, X64Builder::RBP, $offset);
            $this->b->emit("\x48\x83\xEC\x28");
            $this->b->callIat(self::IAT_HEAPFREE);
            $this->b->emit("\x48\x83\xC4\x28");

            $this->b->defineLabel($skipLabel);
        }
    }

    /**
     * itoa: int64 → ASCII decimal, writes to caller-provided buffer (safe from shadow space).
     * Input:  rax = int64 value, rdi = buffer_end (last byte of caller's 32-byte buffer)
     * Output: rsi = pointer to first digit, rdx = length
     * Modifies: rax, rcx, rdx, rsi, rdi, r8, r14 (saved/restored)
     */
    private function emitItoaHelper(): void
    {
        $this->b->defineLabel($this->itoaLabel);

        $zeroLabel     = $this->newLabel('.L.itoa.z');
        $positiveLabel = $this->newLabel('.L.itoa.p');
        $divLoopLabel  = $this->newLabel('.L.itoa.d');
        $noSignLabel   = $this->newLabel('.L.itoa.n');
        $itoaExitLabel = $this->newLabel('.L.itoa.x');
        $itoaRetLabel  = $this->newLabel('.L.itoa.r');

        $this->b->emit("\x55");                          // push rbp
        $this->b->emit("\x48\x89\xE5");                  // mov rbp, rsp
        $this->b->emit("\x48\x83\xEC\x30");              // sub rsp, 0x30
        $this->b->emit("\x41\x56");                      // push r14
        $this->b->emit("\x49\x89\xFE");                  // mov r14, rdi

        $this->b->testRR(X64Builder::RAX, X64Builder::RAX);
        $this->b->jeLabel($zeroLabel);

        $this->b->emit("\x45\x31\xC0");
        $this->b->testRR(X64Builder::RAX, X64Builder::RAX);
        $this->b->jnsLabel($positiveLabel);
        $this->b->emit("\x41\xB8\x01\x00\x00\x00");     // mov r8d, 1
        $this->b->negReg(X64Builder::RAX);

        $this->b->defineLabel($positiveLabel);
        $this->b->emit("\xC6\x07\x00");                  // mov byte [rdi], 0
        $this->b->emit("\x48\xFF\xCF");                  // dec rdi

        $this->b->defineLabel($divLoopLabel);
        $this->b->emit("\x48\x31\xD2");                  // xor rdx, rdx
        $this->b->emit("\x48\xC7\xC1\x0A\x00\x00\x00"); // mov rcx, 10
        $this->b->emit("\x48\xF7\xF1");                  // div rcx
        $this->b->emit("\x80\xC2\x30");                  // add dl, '0'
        $this->b->emit("\x88\x17");                      // mov [rdi], dl
        $this->b->emit("\x48\xFF\xCF");                  // dec rdi
        $this->b->testRR(X64Builder::RAX, X64Builder::RAX);
        $this->b->jneLabel($divLoopLabel);

        $this->b->emit("\x45\x85\xC0");
        $this->b->jeLabel($noSignLabel);
        $this->b->emit("\xC6\x07\x2D");                  // mov byte [rdi], '-'
        $this->b->emit("\x48\xFF\xCF");                  // dec rdi

        $this->b->defineLabel($noSignLabel);
        $this->b->emit("\x48\xFF\xC7");                  // inc rdi
        $this->b->emit("\x48\x89\xFE");                  // mov rsi, rdi
        $this->b->movRR(X64Builder::RDX, X64Builder::R14);
        $this->b->subRR(X64Builder::RDX, X64Builder::RDI);
        $this->b->jmpLabel($itoaExitLabel);

        $this->b->defineLabel($zeroLabel);
        // Write '0' at buffer_end and set RSI to point there
        $this->b->emit("\x48\x89\xFE");                  // mov rsi, rdi (buffer_end)
        $this->b->emit("\xC6\x06\x30");                  // mov byte [rsi], '0'
        $this->b->emit("\xBA\x01\x00\x00\x00");          // mov edx, 1

        $this->b->defineLabel($itoaExitLabel);
        $this->b->emit("\x45\x85\xC9");                  // test r9d, r9d
        $this->b->jeLabel($itoaRetLabel);
        $this->b->emit("\xC6\x04\x16\x2E");              // mov byte [rsi+rdx], '.'
        $this->b->emit("\xC6\x44\x16\x01\x30");          // mov byte [rsi+rdx+1], '0'
        $this->b->emit("\x48\x83\xC2\x02");              // add rdx, 2

        $this->b->defineLabel($itoaRetLabel);
        $this->b->emit("\x41\x5E");                      // pop r14
        $this->b->emit("\xC9");                          // leave
        $this->b->ret();
    }

    /**
     * ftoa: double → decimal string with up to 6 fractional digits.
     * Input:  xmm0 = double value, rdi = buffer_end (48-byte buffer provided by caller)
     * Output: rsi = pointer to first char, rdx = length
     */
    private function emitFtoaHelper(): void
    {
        $this->b->defineLabel($this->ftoaLabel);

        $nonNeg   = $this->newLabel('.L.ftoa.nn');
        $fracLoop = $this->newLabel('.L.ftoa.fl');
        $intLoop  = $this->newLabel('.L.ftoa.il');
        $noSign   = $this->newLabel('.L.ftoa.ns');
        $stripZ   = $this->newLabel('.L.ftoa.sz');
        $ftoaRet  = $this->newLabel('.L.ftoa.r');

        $this->b->emit("\x41\x56");                      // push r14
        $this->b->emit("\x41\x57");                      // push r15
        $this->b->emit("\x49\x89\xFE");                  // mov r14, rdi (buffer_end)

        $this->b->emit("\x45\x31\xFF");                  // xor r15d, r15d
        $this->b->emit("\x0F\x57\xC9");                  // xorps xmm1, xmm1
        $this->b->emit("\x66\x0F\x2E\xC1");              // ucomisd xmm0, xmm1
        $this->b->jaeLabel($nonNeg);
        $this->b->emit("\x48\xB9\x00\x00\x00\x00\x00\x00\x00\x80");
        $this->b->emit("\x66\x48\x0F\x6E\xD1");          // movq xmm2, rcx
        $this->b->emit("\x66\x0F\x57\xC2");              // xorpd xmm0, xmm2
        $this->b->emit("\x41\xBF\x01\x00\x00\x00");      // mov r15d, 1

        $this->b->defineLabel($nonNeg);
        $this->b->emit("\x48\xB9\x00\x00\x00\x00\x80\x84\x2E\x41");
        $this->b->emit("\x66\x48\x0F\x6E\xC9");          // movq xmm1, rcx
        $this->b->emit("\xF2\x0F\x59\xC1");              // mulsd xmm0, xmm1
        $this->b->emit("\x48\xB9\x00\x00\x00\x00\x00\x00\xE0\x3F");
        $this->b->emit("\x66\x48\x0F\x6E\xD1");          // movq xmm2, rcx
        $this->b->emit("\xF2\x0F\x58\xC2");              // addsd xmm0, xmm2
        $this->b->emit("\xF2\x48\x0F\x2C\xC0");          // cvttsd2si rax, xmm0

        $this->b->emit("\x4C\x89\xF7");                  // mov rdi, r14
        $this->b->emit("\x41\xB8\x06\x00\x00\x00");      // mov r8d, 6

        $this->b->defineLabel($fracLoop);
        $this->b->emit("\x48\x31\xD2");                  // xor rdx, rdx
        $this->b->emit("\x48\xC7\xC1\x0A\x00\x00\x00"); // mov rcx, 10
        $this->b->emit("\x48\xF7\xF1");                  // div rcx
        $this->b->emit("\x80\xC2\x30");                  // add dl, '0'
        $this->b->emit("\x48\xFF\xCF");                  // dec rdi
        $this->b->emit("\x88\x17");                      // mov [rdi], dl
        $this->b->emit("\x49\xFF\xC8");                  // dec r8
        $this->b->jneLabel($fracLoop);

        $this->b->emit("\x48\xFF\xCF");                  // dec rdi
        $this->b->emit("\xC6\x07\x2E");                  // mov byte [rdi], '.'

        $this->b->emit("\x48\x85\xC0");                  // test rax, rax
        $this->b->jneLabel($intLoop);
        $this->b->emit("\x48\xFF\xCF");                  // dec rdi
        $this->b->emit("\xC6\x07\x30");                  // mov byte [rdi], '0'
        $this->b->jmpLabel($noSign);

        $this->b->defineLabel($intLoop);
        $this->b->emit("\x48\x31\xD2");                  // xor rdx, rdx
        $this->b->emit("\x48\xC7\xC1\x0A\x00\x00\x00"); // mov rcx, 10
        $this->b->emit("\x48\xF7\xF1");                  // div rcx
        $this->b->emit("\x80\xC2\x30");                  // add dl, '0'
        $this->b->emit("\x48\xFF\xCF");                  // dec rdi
        $this->b->emit("\x88\x17");                      // mov [rdi], dl
        $this->b->emit("\x48\x85\xC0");                  // test rax, rax
        $this->b->jneLabel($intLoop);

        $this->b->defineLabel($noSign);
        $this->b->emit("\x45\x85\xFF");                  // test r15d, r15d
        $this->b->jeLabel($stripZ);
        $this->b->emit("\x48\xFF\xCF");                  // dec rdi
        $this->b->emit("\xC6\x07\x2D");                  // mov byte [rdi], '-'

        $this->b->defineLabel($stripZ);
        $this->b->emit("\x48\x89\xFE");                  // mov rsi, rdi
        $this->b->emit("\x4C\x89\xF2");                  // mov rdx, r14
        $this->b->emit("\x48\x29\xF2");                  // sub rdx, rsi

        $stripLoop = $this->newLabel('.L.ftoa.stl');
        $stripDone = $this->newLabel('.L.ftoa.std');
        $this->b->defineLabel($stripLoop);
        $this->b->emit("\x48\x85\xD2");                  // test rdx, rdx
        $this->b->jeLabel($stripDone);
        $this->b->emit("\x80\x7C\x16\xFF\x30");          // cmp byte [rsi+rdx-1], '0'
        $this->b->jneLabel($stripDone);
        $this->b->emit("\x48\xFF\xCA");                  // dec rdx
        $this->b->jmpLabel($stripLoop);

        $this->b->defineLabel($stripDone);
        $this->b->emit("\x48\x85\xD2");                  // test rdx, rdx
        $this->b->jeLabel($ftoaRet);
        $this->b->emit("\x80\x7C\x16\xFF\x2E");          // cmp byte [rsi+rdx-1], '.'
        $this->b->jneLabel($ftoaRet);
        $this->b->emit("\x48\xFF\xCA");                  // dec rdx

        $this->b->defineLabel($ftoaRet);
        $this->b->emit("\x41\x5F");                      // pop r15
        $this->b->emit("\x41\x5E");                      // pop r14
        $this->b->ret();
    }

    /**
     * atoi (Windows): string → int64, handles optional sign and leading digits.
     * Input:  RCX = string ptr, RDX = string len  (both preserved)
     * Output: RAX = parsed int
     */
    private function emitAtoiHelper(): void
    {
        $this->b->defineLabel($this->atoiLabel);

        $doneL    = $this->newLabel('.L.atoi.d');
        $chkSignL = $this->newLabel('.L.atoi.s');
        $loopL    = $this->newLabel('.L.atoi.l');
        $applyL   = $this->newLabel('.L.atoi.a');

        // Save RDI/RSI (non-volatile), use RDI=ptr, RSI=len
        $this->b->pushReg(X64Builder::RDI);              // 57
        $this->b->pushReg(X64Builder::RSI);              // 56
        $this->b->emit("\x48\x89\xCF");                  // mov rdi, rcx (ptr)
        $this->b->emit("\x48\x89\xD6");                  // mov rsi, rdx (len)

        $this->b->emit("\x31\xC0");                      // xor eax, eax
        $this->b->emit("\x45\x31\xC0");                  // xor r8d, r8d

        $this->b->testRR(X64Builder::RSI, X64Builder::RSI);
        $this->b->jeLabel($doneL);

        $this->b->emit("\x8A\x0F");                      // mov cl, [rdi]
        $this->b->emit("\x80\xF9\x2D");                  // cmp cl, '-'
        $this->b->jneLabel($chkSignL);
        $this->b->emit("\x41\xB8\x01\x00\x00\x00");     // mov r8d, 1
        $this->b->emit("\x48\xFF\xC7");                  // inc rdi
        $this->b->emit("\x48\xFF\xCE");                  // dec rsi
        $this->b->jmpLabel($loopL);

        $this->b->defineLabel($chkSignL);
        $this->b->emit("\x80\xF9\x2B");                  // cmp cl, '+'
        $this->b->jneLabel($loopL);
        $this->b->emit("\x48\xFF\xC7");                  // inc rdi
        $this->b->emit("\x48\xFF\xCE");                  // dec rsi

        $this->b->defineLabel($loopL);
        $this->b->testRR(X64Builder::RSI, X64Builder::RSI);
        $this->b->jeLabel($applyL);

        $this->b->emit("\x0F\xB6\x0F");                  // movzx ecx, byte [rdi]
        $this->b->emit("\x80\xF9\x30");                  // cmp cl, '0'
        $this->b->jlLabel($applyL);
        $this->b->emit("\x80\xF9\x39");                  // cmp cl, '9'
        $this->b->jgLabel($applyL);
        $this->b->emit("\x80\xE9\x30");                  // sub cl, '0'

        $this->b->emit("\x48\x6B\xC0\x0A");              // imul rax, 10
        $this->b->emit("\x48\x01\xC8");                  // add rax, rcx

        $this->b->emit("\x48\xFF\xC7");                  // inc rdi
        $this->b->emit("\x48\xFF\xCE");                  // dec rsi
        $this->b->jmpLabel($loopL);

        $this->b->defineLabel($applyL);
        $this->b->emit("\x45\x85\xC0");                  // test r8d, r8d
        $this->b->jeLabel($doneL);
        $this->b->emit("\x48\xF7\xD8");                  // neg rax

        $this->b->defineLabel($doneL);
        $this->b->popReg(X64Builder::RSI);
        $this->b->popReg(X64Builder::RDI);
        $this->b->ret();
    }
}
