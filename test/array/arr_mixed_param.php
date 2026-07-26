<?php
#debug sum=60
#debug name=Alice
#debug age=30
#debug 1+2=3
#debug mix=hello
#debug mix=42
#debug cnt=3
#debug === done ===

function sumArray(array $arr): int
{
    $sum = 0;
    $n = count($arr);
    for ($i = 0; $i < $n; $i++) {
        $sum = $sum + $arr[$i];
    }
    return $sum;
}

function getKey(array $arr, string $key): string
{
    return $arr[$key];
}

function getAge(array $user): int
{
    return $user["age"];
}

class Main
{
    public function main(): void
    {
        // 整数数组 → array<mixed> 参数
        $nums = [10, 20, 30];
        echo "sum=" . sumArray($nums) . "\n";

        // 字符串键访问
        $user = ["name" => "Alice", "age" => 30];
        echo "name=" . getKey($user, "name") . "\n";
        echo "age=" . getAge($user) . "\n";

        // 元素参与算术
        $pair = [1, 2];
        echo "1+2=" . ($pair[0] + $pair[1]) . "\n";

        // 混合类型数组
        $mix = ["hello", 42, 3.14];
        echo "mix=" . $mix[0] . "\n";
        echo "mix=" . $mix[1] . "\n";
        echo "cnt=" . count($mix) . "\n";

        echo "=== done ===\n";
    }
}
