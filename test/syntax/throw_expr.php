<?php
#debug === Throw Expression (PHP 8.0+) ===
#debug
#debug 1. val=42
#debug 2. caught: need positive number
#debug 3. caught: value is zero
#debug 4. result=100
#debug 5. caught: negative not allowed
#debug 6. done
#debug
#debug === All Throw Expression tests passed ===

class Main
{
    // throw 表达式在 return 语句中
    public function requirePositive(int $n): int|Exception
    {
        if ($n > 0) {
            return $n;
        }
        return throw new Exception('need positive number');
    }

    // throw 表达式在三元运算符 else 分支
    public function nonZero(int $n): int|Exception
    {
        return $n !== 0 ? $n : throw new Exception('value is zero');
    }

    // throw 表达式在赋值右侧
    public function fail(string $msg): int|Exception
    {
        $x = throw new Exception($msg);
    }

    public function main(): void
    {
        echo "=== Throw Expression (PHP 8.0+) ===\n\n";

        // 1. 正常返回
        try {
            $val = $this->requirePositive(42);
            echo "1. val=" . $val . "\n";
        } catch (Exception $e) {
            echo "1. caught: " . $e->getMessage() . "\n";
        }

        // 2. throw 表达式在 return 中
        try {
            $this->requirePositive(-1);
        } catch (Exception $e) {
            echo "2. caught: " . $e->getMessage() . "\n";
        }

        // 3. throw 表达式在三元中
        try {
            $this->nonZero(0);
        } catch (Exception $e) {
            echo "3. caught: " . $e->getMessage() . "\n";
        }

        // 4. 三元正常分支
        try {
            $result = $this->nonZero(100);
            echo "4. result=" . $result . "\n";
        } catch (Exception $e) {
            echo "4. caught: " . $e->getMessage() . "\n";
        }

        // 5. throw 表达式在赋值右侧
        try {
            $this->fail('negative not allowed');
        } catch (Exception $e) {
            echo "5. caught: " . $e->getMessage() . "\n";
        }

        echo "6. done\n";
        echo "\n=== All Throw Expression tests passed ===\n";
    }
}
