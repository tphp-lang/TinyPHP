/*
 * mco_arm64_macos.s — minicoro context switch for macOS ARM64
 *
 * Provides both single-underscore (_mco_switch) and double-underscore
 * (__mco_switch) symbols to satisfy both:
 *   - My minicoro-lite code (single underscore, extern declarations)
 *   - minicoro.h inline ASM references (double underscore, macOS C ABI)
 */

.text

/* int _mco_switch / __mco_switch (_mco_ctxbuf* from, _mco_ctxbuf* to) */
.globl _mco_switch
.globl __mco_switch
_mco_switch:
__mco_switch:
    mov  x10, sp
    mov  x11, x30
    stp  x19, x20, [x0, #(0*16)]
    stp  x21, x22, [x0, #(1*16)]
    stp  x23, x24, [x0, #(2*16)]
    stp  x25, x26, [x0, #(3*16)]
    stp  x27, x28, [x0, #(4*16)]
    stp  x29, x30, [x0, #(5*16)]
    stp  x10, x11, [x0, #(6*16)]
    stp  d8,  d9,  [x0, #(7*16)]
    stp  d10, d11, [x0, #(8*16)]
    stp  d12, d13, [x0, #(9*16)]
    stp  d14, d15, [x0, #(10*16)]
    ldp  x19, x20, [x1, #(0*16)]
    ldp  x21, x22, [x1, #(1*16)]
    ldp  x23, x24, [x1, #(2*16)]
    ldp  x25, x26, [x1, #(3*16)]
    ldp  x27, x28, [x1, #(4*16)]
    ldp  x29, x30, [x1, #(5*16)]
    ldp  x10, x11, [x1, #(6*16)]
    ldp  d8,  d9,  [x1, #(7*16)]
    ldp  d10, d11, [x1, #(8*16)]
    ldp  d12, d13, [x1, #(9*16)]
    ldp  d14, d15, [x1, #(10*16)]
    mov  sp, x10
    br   x11

/* void _mco_wrap_main / __mco_wrap_main (void) */
.globl _mco_wrap_main
.globl __mco_wrap_main
_mco_wrap_main:
__mco_wrap_main:
    mov  x0, x19
    mov  x30, x21
    br   x20
