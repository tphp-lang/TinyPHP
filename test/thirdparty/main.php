<?php
// minicoro integration test — verifies coroutine yield/resume works
// across TinyPHP's AOT compilation pipeline on all CI platforms.
//
// Platform auto-detection by minicoro.h:
//   Windows + GCC/Clang → ASM  |  Windows + TCC → Win32 Fiber (fallback)
//   Linux   + GCC/Clang → ASM  |  Linux   + TCC → ucontext (fallback)
//   macOS   + Clang     → ASM

#include "minicoro_test.h"

#debug int(15)
#debug int(30)
#debug int(330)

class Main
{
    public function main(): void
    {
        // Test 1: basic yield — coroutine yields 1,2,3,4,5, sum = 15
        $sum1 = php_int(C->mco_test_basic());
        var_dump($sum1);

        // Test 2: bidirectional — send [1,2,3,4,5], receive doubled [2,4,6,8,10], sum = 30
        $sum2 = php_int(C->mco_test_bidirectional());
        var_dump($sum2);

        // Test 3: state transitions — create(3) + yield(3) + dead(0) → 330
        $states = php_int(C->mco_test_states());
        var_dump($states);
    }
}
