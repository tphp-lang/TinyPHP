<?php
#debug 5000000000
#debug 9223372036854775807
#debug -9223372036854775808
#debug -5000000000
#debug 0
#debug -1

class Main
{
    public function main(): void
    {
        // 测试大整数 json_encode（大于 2^32）
        echo json_encode(5000000000) . "\n";        // 5000000000
        echo json_encode(PHP_INT_MAX) . "\n";       // 9223372036854775807
        echo json_encode(PHP_INT_MIN) . "\n";       // -9223372036854775808
        echo json_encode(-5000000000) . "\n";        // -5000000000
        echo json_encode(0) . "\n";                  // 0
        echo json_encode(-1) . "\n";                 // -1
    }
}
