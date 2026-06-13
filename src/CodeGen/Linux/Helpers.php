<?php

declare(strict_types=1);

namespace Tphp\CodeGen\Linux;

use Tphp\X64Builder;

/**
 * Linux ELF helper routines: itoa, ftoa, atoi.
 * Used as a trait by CodeGenerator.
 */
trait Helpers
{
    /**
     * itoa helper: converts signed 64-bit integer to ASCII decimal.
     *
     * Input:  rax = int64 value
     * Output: rsi = pointer to ASCII string (on our stack buffer)
     *         rdx = string length
     * Clobbers: rax, rcx, rdi, r8, r9, r10, r11
     * Stack: uses 32 bytes below current rsp
     */
    private function emitItoaHelper(): void
    {
        $this->b->defineLabel($this->itoaLabel);

        $this->b->emit("\x55");                         // push rbp
        $this->b->emit("\x48\x89\xE5");                 // mov rbp,rsp
        $this->b->emit("\x48\x83\xEC\x30");             // sub rsp,0x30

        $zeroLabel = $this->newLabel('.L.itoa.zero');
        $positiveLabel = $this->newLabel('.L.itoa.pos');
        $divLoopLabel = $this->newLabel('.L.itoa.div');
        $noSignLabel = $this->newLabel('.L.itoa.nosign');
        $itoaExitLabel = $this->newLabel('.L.itoa.x');
        $itoaRetLabel = $this->newLabel('.L.itoa.r');

        $this->b->emit("\x48\x85\xC0");                 // test rax,rax
        $this->b->jeLabel($zeroLabel);

        $this->b->emit("\x45\x31\xC0");                 // xor r8d,r8d
        $this->b->emit("\x48\x85\xC0");                 // test rax,rax
        $this->b->emit("\x79\x07");                     // jns +7
        $this->b->emit("\x41\xB8\x01\x00\x00\x00");    // mov r8d,1
        $this->b->emit("\x48\xF7\xD8");                 // neg rax

        // .positive:
        $this->b->defineLabel($positiveLabel);
        $this->b->emit("\x48\x8D\x7C\x24\x1F");         // lea rdi,[rsp+0x1f]
        $this->b->emit("\xC6\x07\x00");                 // mov byte[rdi],0
        $this->b->emit("\x48\xFF\xCF");                 // dec rdi

        // .div_loop:
        $this->b->defineLabel($divLoopLabel);
        $this->b->emit("\x48\x31\xD2");                 // xor rdx,rdx
        $this->b->emit("\x48\xC7\xC1\x0A\x00\x00\x00"); // mov rcx,10
        $this->b->emit("\x48\xF7\xF1");                 // div rcx
        $this->b->emit("\x80\xC2\x30");                 // add dl,'0'
        $this->b->emit("\x88\x17");                     // mov [rdi],dl
        $this->b->emit("\x48\xFF\xCF");                 // dec rdi
        $this->b->emit("\x48\x85\xC0");                 // test rax,rax
        $this->b->emit("\x75\xEC");                     // jne -20 (back to div_loop)

        $this->b->emit("\x45\x85\xC0");                 // test r8d,r8d
        $this->b->emit("\x74\x06");                     // jz +6 (skip sign)
        $this->b->emit("\xC6\x07\x2D");                 // mov byte[rdi],'-'
        $this->b->emit("\x48\xFF\xCF");                 // dec rdi

        // .no_sign:
        $this->b->defineLabel($noSignLabel);
        $this->b->emit("\x48\xFF\xC7");                 // inc rdi
        $this->b->emit("\x48\x89\xFE");                 // mov rsi,rdi
        $this->b->emit("\x48\x8D\x54\x24\x20");         // lea rdx,[rsp+0x20]
        $this->b->emit("\x48\x29\xFA");                 // sub rdx,rdi
        $this->b->jmpLabel($itoaExitLabel);             // jmp to common exit

        // .zero:
        $this->b->defineLabel($zeroLabel);
        $this->b->emit("\x48\x8D\x74\x24\x10");         // lea rsi,[rsp+0x10]
        $this->b->emit("\xC6\x06\x30");                 // mov byte[rsi],'0'
        $this->b->emit("\xBA\x01\x00\x00\x00");         // mov edx,1

        $this->b->defineLabel($itoaExitLabel);
        $this->b->defineLabel($itoaRetLabel);
        $this->b->emit("\xC9");                         // leave
        $this->b->emit("\xC3");                         // ret
    }

    /**
     * ftoa: double → decimal string with up to 6 fractional digits.
     * Input:  xmm0 = double value
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

        // ---- Prologue ----
        $this->b->emit("\x55");                             // push rbp
        $this->b->emit("\x48\x89\xE5");                     // mov rbp, rsp
        $this->b->emit("\x48\x83\xEC\x30");                 // sub rsp, 0x30
        $this->b->emit("\x41\x56");                          // push r14
        $this->b->emit("\x41\x57");                          // push r15

        $this->b->emit("\x49\x89\xEE");                      // mov r14, rbp
        $this->b->emit("\x49\x83\xEE\x08");                  // sub r14, 8 (buffer_end)

        // ---- Sign: R15 = 1 if negative ----
        $this->b->emit("\x45\x31\xFF");                      // xor r15d, r15d
        $this->b->emit("\x0F\x57\xC9");                      // xorps xmm1, xmm1
        $this->b->emit("\x66\x0F\x2E\xC1");                  // ucomisd xmm0, xmm1
        $this->b->jaeLabel($nonNeg);
        $this->b->emit("\x48\xB9\x00\x00\x00\x00\x00\x00\x00\x80");
        $this->b->emit("\x66\x48\x0F\x6E\xD1");              // movq xmm2, rcx
        $this->b->emit("\x66\x0F\x57\xC2");                  // xorpd xmm0, xmm2
        $this->b->emit("\x41\xBF\x01\x00\x00\x00");          // mov r15d, 1

        // ---- Multiply by 10^6, round, truncate ----
        $this->b->defineLabel($nonNeg);
        $this->b->emit("\x48\xB9\x00\x00\x00\x00\x80\x84\x2E\x41");
        $this->b->emit("\x66\x48\x0F\x6E\xC9");              // movq xmm1, rcx
        $this->b->emit("\xF2\x0F\x59\xC1");                  // mulsd xmm0, xmm1
        $this->b->emit("\x48\xB9\x00\x00\x00\x00\x00\x00\xE0\x3F");
        $this->b->emit("\x66\x48\x0F\x6E\xD1");              // movq xmm2, rcx
        $this->b->emit("\xF2\x0F\x58\xC2");                  // addsd xmm0, xmm2
        $this->b->emit("\xF2\x48\x0F\x2C\xC0");              // cvttsd2si rax, xmm0

        // ---- Write fractional digits backwards (6 digits) ----
        $this->b->emit("\x4C\x89\xF7");                      // mov rdi, r14
        $this->b->emit("\x41\xB8\x06\x00\x00\x00");          // mov r8d, 6

        $this->b->defineLabel($fracLoop);
        $this->b->emit("\x48\x31\xD2");                      // xor rdx, rdx
        $this->b->emit("\x48\xC7\xC1\x0A\x00\x00\x00");     // mov rcx, 10
        $this->b->emit("\x48\xF7\xF1");                      // div rcx
        $this->b->emit("\x80\xC2\x30");                      // add dl, '0'
        $this->b->emit("\x48\xFF\xCF");                      // dec rdi
        $this->b->emit("\x88\x17");                          // mov [rdi], dl
        $this->b->emit("\x49\xFF\xC8");                      // dec r8
        $this->b->jneLabel($fracLoop);

        // ---- Write decimal point ----
        $this->b->emit("\x48\xFF\xCF");                      // dec rdi
        $this->b->emit("\xC6\x07\x2E");                      // mov byte [rdi], '.'

        // ---- Write integer part digits backwards ----
        $this->b->emit("\x48\x85\xC0");                      // test rax, rax
        $this->b->jneLabel($intLoop);
        $this->b->emit("\x48\xFF\xCF");                      // dec rdi
        $this->b->emit("\xC6\x07\x30");                      // mov byte [rdi], '0'
        $this->b->jmpLabel($noSign);

        $this->b->defineLabel($intLoop);
        $this->b->emit("\x48\x31\xD2");                      // xor rdx, rdx
        $this->b->emit("\x48\xC7\xC1\x0A\x00\x00\x00");     // mov rcx, 10
        $this->b->emit("\x48\xF7\xF1");                      // div rcx
        $this->b->emit("\x80\xC2\x30");                      // add dl, '0'
        $this->b->emit("\x48\xFF\xCF");                      // dec rdi
        $this->b->emit("\x88\x17");                          // mov [rdi], dl
        $this->b->emit("\x48\x85\xC0");                      // test rax, rax
        $this->b->jneLabel($intLoop);

        // ---- Write sign ----
        $this->b->defineLabel($noSign);
        $this->b->emit("\x45\x85\xFF");                      // test r15d, r15d
        $this->b->jeLabel($stripZ);
        $this->b->emit("\x48\xFF\xCF");                      // dec rdi
        $this->b->emit("\xC6\x07\x2D");                      // mov byte [rdi], '-'

        // ---- Strip trailing zeros after decimal point ----
        $this->b->defineLabel($stripZ);
        $this->b->emit("\x48\x89\xFE");                      // mov rsi, rdi
        $this->b->emit("\x4C\x89\xF2");                      // mov rdx, r14
        $this->b->emit("\x48\x29\xF2");                      // sub rdx, rsi

        $stripLoop = $this->newLabel('.L.ftoa.stl');
        $stripDone = $this->newLabel('.L.ftoa.std');
        $this->b->defineLabel($stripLoop);
        $this->b->emit("\x48\x85\xD2");                      // test rdx, rdx
        $this->b->jeLabel($stripDone);
        $this->b->emit("\x80\x7C\x16\xFF\x30");              // cmp byte [rsi+rdx-1], '0'
        $this->b->jneLabel($stripDone);
        $this->b->emit("\x48\xFF\xCA");                      // dec rdx
        $this->b->jmpLabel($stripLoop);

        $this->b->defineLabel($stripDone);
        $this->b->emit("\x48\x85\xD2");                      // test rdx, rdx
        $this->b->jeLabel($ftoaRet);
        $this->b->emit("\x80\x7C\x16\xFF\x2E");              // cmp byte [rsi+rdx-1], '.'
        $this->b->jneLabel($ftoaRet);
        $this->b->emit("\x48\xFF\xCA");                      // dec rdx

        $this->b->defineLabel($ftoaRet);
        $this->b->emit("\x41\x5F");                          // pop r15
        $this->b->emit("\x41\x5E");                          // pop r14
        $this->b->emit("\xC9");                              // leave
        $this->b->ret();
    }

    /**
     * atoi: string → int64, handles optional sign and leading digits.
     * Input:  RDI = string ptr, RSI = string len
     * Output: RAX = parsed int
     */
    private function emitAtoiHelper(): void
    {
        $this->b->defineLabel($this->atoiLabel);

        $doneL    = $this->newLabel('.L.atoi.d');
        $chkSignL = $this->newLabel('.L.atoi.s');
        $loopL    = $this->newLabel('.L.atoi.l');
        $applyL   = $this->newLabel('.L.atoi.a');

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
        $this->b->ret();
    }
}
