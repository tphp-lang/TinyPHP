<?php
// or {} 错误处理块测试（vlang 风格，TinyPHP 扩展）
// 验证 or {} 块在各场景下的行为：
//   1. 赋值语句 + 异常路径（echo $err）
//   2. 赋值语句 + 成功路径（后续使用变量）
//   3. 表达式语句 + 异常路径
//   4. or {} 内 return 传播错误
//   5. or {} 内 throw 新异常
//   6. try/catch 与 or {} 共存
//   7. 赋值语句 + 成功路径（int 类型）
#debug === or {} Block (vlang style) ===
#debug
#debug 1. caught: file not found: x.txt
#debug 2. data=content of y.txt
#debug 3. caught: file not found: z.txt
#debug 4. result=empty path
#debug 5. caught: rethrown: negative: -5
#debug 6a. try/catch: file not found: a.txt
#debug 6b. or block: file not found: b.txt
#debug 7. success: 20
#debug 8. done
#debug
#debug === All or {} Block tests passed ===

class Main
{
    // 模拟可能失败的读取函数（总是抛出异常）
    private function readFile(string $path): string|Exception
    {
        throw new Exception("file not found: " . $path);
    }

    // 模拟成功的读取函数
    private function readFileOk(string $path): string|Exception
    {
        return "content of " . $path;
    }

    // 用于测试 return 传播
    private function loadOrFail(string $path): string|Exception
    {
        if ($path === "") {
            throw new Exception("empty path");
        }
        return "ok";
    }

    // or {} 内 return 传播错误：调用者通过 or {} 捕获并 return
    private function caller(): string|Exception
    {
        $data = $this->loadOrFail("") or {
            return $err->getMessage();
        };
        return $data;
    }

    // 用于测试 throw 新异常
    private function risky(int $n): int|Exception
    {
        if ($n < 0) {
            throw new Exception("negative: " . $n);
        }
        return $n * 2;
    }

    public function main(): void
    {
        echo "=== or {} Block (vlang style) ===\n\n";

        // 1. 赋值语句中的 or {} 块（异常路径，仅 echo 不 return）
        $data = $this->readFile("x.txt") or {
            echo "1. caught: " . $err->getMessage() . "\n";
        };

        // 2. 赋值语句中的 or {} 块（成功路径，后续使用变量）
        $data = $this->readFileOk("y.txt") or {
            echo "2. should not catch\n";
            return;
        };
        echo "2. data=" . $data . "\n";

        // 3. 表达式语句中的 or {} 块（异常路径，无赋值目标）
        $this->readFile("z.txt") or {
            echo "3. caught: " . $err->getMessage() . "\n";
        };

        // 4. or {} 块内 return 传播错误
        $result = $this->caller();
        echo "4. result=" . $result . "\n";

        // 5. or {} 块内 throw 新异常（外层 try/catch 捕获）
        try {
            $this->risky(-5) or {
                throw new Exception("rethrown: " . $err->getMessage());
            };
        } catch (Exception $e) {
            echo "5. caught: " . $e->getMessage() . "\n";
        }

        // 6. 与 try/catch 共存（同一函数两种错误处理方式）
        // 6a. 使用 try/catch
        try {
            $this->readFile("a.txt");
        } catch (Exception $e) {
            echo "6a. try/catch: " . $e->getMessage() . "\n";
        }

        // 6b. 使用 or {} 处理同一函数
        $this->readFile("b.txt") or {
            echo "6b. or block: " . $err->getMessage() . "\n";
        };

        // 7. 赋值语句 + 成功路径（int 类型）
        $val = $this->risky(10) or {
            echo "7. should not catch\n";
            return;
        };
        echo "7. success: " . $val . "\n";

        echo "8. done\n";
        echo "\n=== All or {} Block tests passed ===\n";
    }
}
