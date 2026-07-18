<?php
// 对应 PHP tests/func/003.phpt
#debug int(120)
#debug int(720)
#debug int(5040)
#debug int(840)
#debug int(1)

// 递归阶乘
function factorial(int $n): int {
    if ($n <= 1) return 1;
    return $n * factorial($n - 1);
}

// 递归带起点: factorial2(start, n) = start * (start+1) * ... * n
function factorial2(int $start, int $n): int {
    if ($n <= $start) {
        return $start;
    }
    return factorial2($start, $n - 1) * $n;
}

class Main
{
    public function main(): void
    {
        // 基本递归
        var_dump(factorial(5));     // int(120) = 5*4*3*2*1
        var_dump(factorial(6));     // int(720) = 6*5*4*3*2*1
        var_dump(factorial(7));     // int(5040) = 7*720
        // 带起点递归: 4*5*6*7 = 840
        var_dump(factorial2(4, 7)); // int(840)
        // 边界条件: 0! = 1
        var_dump(factorial(0));     // int(1)
    }
}
