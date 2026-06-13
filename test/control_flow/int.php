<?php
// ========== 整型 + 控制流 ==========（未测试）

namespace Main;

function main(): void
{
    // --- 2.1 整数范围判断与 if/else if ---
    $score = 85;

    if ($score >= 90) {
        $grade = "A";
    } else if ($score >= 80) {
        $grade = "B";
    } else if ($score >= 70) {
        $grade = "C";
    } else if ($score >= 60) {
        $grade = "D";
    } else {
        $grade = "F";
    }
    echo "成绩: $score, 等级: $grade\n";
    // 预期输出: 成绩: 85, 等级: B

    // --- 2.2 取模运算与 switch ---
    $dayOfWeek = 5;

    switch ($dayOfWeek) {
        case 1:
            echo "星期一\n";
            break;
        case 2:
            echo "星期二\n";
            break;
        case 3:
            echo "星期三\n";
            break;
        case 4:
            echo "星期四\n";
            break;
        case 5:
            echo "星期五\n";
            break;
        case 6:
        case 7:
            echo "周末\n";
            break;
        default:
            echo "无效日期\n";
    }
    // 预期输出: 星期五

    // --- 2.3 for 循环 + 条件判断 (质数检测) ---
    $number = 29;
    $isPrime = true;

    if ($number < 2) {
        $isPrime = false;
    } else {
        for ($i = 2; $i <= (int)sqrt($number); $i++) {
            if ($number % $i === 0) {
                $isPrime = false;
                break;
            }
        }
    }

    echo "$number " . ($isPrime ? "是" : "不是") . "质数\n";
    // 预期输出: 29 是质数

    // --- 2.4 while 循环 (斐波那契数列) ---
    $limit = 100;
    $a = 0;
    $b = 1;
    $fibSequence = [];

    while ($a <= $limit) {
        $fibSequence[] = $a;
        $temp = $a + $b;
        $a = $b;
        $b = $temp;
    }
    echo "斐波那契数列(<= $limit): " . implode(", ", $fibSequence) . "\n";
    // 预期输出: 斐波那契数列(<= 100): 0, 1, 1, 2, 3, 5, 8, 13, 21, 34, 55, 89

    // --- 2.5 整数位运算与 if ---
    $permissions = 0b1101; // 读=1, 写=2, 执行=4, 管理=8
    $userRequest = 2;       // 请求写权限

    if ($permissions & $userRequest) {
        echo "权限授予: 写操作允许\n";
    } else {
        echo "权限拒绝: 写操作禁止\n";

        // 尝试逐级提升
        $fallback = 1; // 回退到读权限
        if ($permissions & $fallback) {
            echo "降级为读操作\n";
        }
    }
    // 预期输出: 权限授予: 写操作允许

    // --- 2.6 嵌套循环 + break/continue (九九乘法表局部) ---
    echo "=== 乘法表 (5-7行) ===\n";
    for ($i = 5; $i <= 7; $i++) {
        for ($j = 1; $j <= 9; $j++) {
            $product = $i * $j;
            // 跳过结果大于50的项
            if ($product > 50) {
                continue;
            }
            echo "$i x $j = $product\t";
        }
        echo "\n";
    }

    // --- 2.7 do-while + 整数运算 (辗转相除法求最大公约数) ---
    $a = 48;
    $b = 36;
    $originalA = $a;
    $originalB = $b;

    do {
        $remainder = $a % $b;
        $a = $b;
        $b = $remainder;
    } while ($remainder !== 0);

    echo "GCD($originalA, $originalB) = $a\n";
    // 预期输出: GCD(48, 36) = 12
}
