<?php
#debug === Long Exception Messages (P3-8) ===
#debug
#debug 1. long-ex: len=300
#debug 2. short-ex: msg=short
#debug 3. long-str: len=300
#debug 4. rethrow: len=300
#debug
#debug === All passed ===

// P3-8: try.h msg_buf[256] → char* msg (malloc dynamic)
// 验证长异常消息（>255 字节）不再被截断

class Main
{
    public function main(): void
    {
        echo "=== Long Exception Messages (P3-8) ===\n\n";

        // 1. Exception with >255 byte message
        //    路径: tp_throw_ex → malloc msg → TP_CATCH_EX(free msg, use ex_obj) → getMessage
        try {
            throw new Exception(str_repeat("X", 300));
        } catch (Exception $e) {
            echo "1. long-ex: len=" . strlen($e->getMessage()) . "\n";
        }

        // 2. Short message regression
        try {
            throw new Exception("short");
        } catch (Exception $e) {
            echo "2. short-ex: msg=" . $e->getMessage() . "\n";
        }

        // 3. Plain string throw >255 bytes
        //    路径: tp_throw → malloc msg → TP_CATCH_ANY(read msg, free msg)
        $longY = str_repeat("Y", 300);
        try {
            throw $longY;
        } catch (\Throwable $e) {
            echo "3. long-str: len=" . strlen($e) . "\n";
        }

        // 4. Re-throw of long plain string through nested try
        //    路径: tp_throw → TP_END_TRY re-throw (dup msg to parent, free current) → TP_CATCH_ANY
        $longZ = str_repeat("Z", 300);
        try {
            try {
                throw $longZ;
            } finally {
                $marker = 1;
            }
        } catch (\Throwable $e) {
            echo "4. rethrow: len=" . strlen($e) . "\n";
        }

        echo "\n=== All passed ===\n";
    }
}
