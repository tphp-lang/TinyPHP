<?php
#debug === Uncaught Exception Error Message Test ===
#debug
#debug before throw
#debug
#debug Fatal error: Uncaught exception: test uncaught message
#debug
#debug (process should exit with code 1, not crash with ACCESS_VIOLATION)

// This test verifies that uncaught exceptions print the error message
// to stderr BEFORE tphp_rt_free_all() cleanup, instead of crashing
// silently with STATUS_ACCESS_VIOLATION (use-after-free bug).
//
// Before fix: tphp_rt_free_all() freed the exception object, then
//   accessed _e->message → use-after-free → crash (exit code 3221225477)
// After fix: message is extracted to malloc'd buffer, printed, then
//   tphp_rt_free_all() runs → exit(1) with error message visible

class Main
{
    public function main(): void
    {
        echo "=== Uncaught Exception Error Message Test ===\n\n";
        echo "before throw\n";
        // No try/catch — this should print the error message and exit(1),
        // not crash silently with ACCESS_VIOLATION
        throw new Exception("test uncaught message");
    }
}
