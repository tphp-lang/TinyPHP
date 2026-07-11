<?php
// 多 catch 子句 + 按类型捕获验证
#debug 1. caught My: [my error] len=8
#debug 2. caught Other: other error
#debug 3. caught base: base error
#debug 4. caught via base: my error
#debug 5. caught: my error
#debug finally
#debug === multi-catch verify done ===

// 自定义异常子类
class MyException extends Exception
{
    public function __construct(string $msg)
    {
        $this->message = $msg;
    }
}

class OtherException extends Exception
{
    public function __construct(string $msg)
    {
        $this->message = $msg;
    }
}

class Main
{
    public function throwMy(): void
    {
        throw new MyException("my error");
    }

    public function throwOther(): void
    {
        throw new OtherException("other error");
    }

    public function throwBase(): void
    {
        throw new Exception("base error");
    }

    public function main(): void
    {
        // 1. 按类型捕获 MyException
        echo "1. ";
        try {
            $this->throwMy();
        } catch (OtherException $e) {
            echo "should not match\n";
        } catch (MyException $e) {
            $msg = $e->getMessage();
            echo "caught My: [" . $msg . "] len=" . strlen($msg) . "\n";
        } catch (Exception $e) {
            echo "should not match either\n";
        }

        // 2. 按类型捕获 OtherException
        echo "2. ";
        try {
            $this->throwOther();
        } catch (MyException $e) {
            echo "should not match\n";
        } catch (OtherException $e) {
            echo "caught Other: " . $e . "\n";
        } catch (Exception $e) {
            echo "should not match either\n";
        }

        // 3. fallback 到 Exception
        echo "3. ";
        try {
            $this->throwBase();
        } catch (MyException $e) {
            echo "should not match\n";
        } catch (OtherException $e) {
            echo "should not match\n";
        } catch (Exception $e) {
            echo "caught base: " . $e . "\n";
        }

        // 4. 子类异常被父类 catch
        echo "4. ";
        try {
            $this->throwMy();
        } catch (Exception $e) {
            echo "caught via base: " . $e . "\n";
        }

        // 5. catch 后 finally
        echo "5. ";
        try {
            $this->throwMy();
        } catch (MyException $e) {
            echo "caught: " . $e . "\n";
        } finally {
            echo "finally\n";
        }

        echo "=== multi-catch verify done ===\n";
    }
}
