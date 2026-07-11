<?php
#debug === Try/Catch with Exception ===
#debug
#debug 1. age=25 OK
#debug 2. caught: age cannot be negative
#debug 3. caught: age too large
#debug 4. OK, finally ran
#debug 5. caught=age cannot be negative, finally
#debug 6. try, finally
#debug 7. div=5
#debug 8. div0=division by zero
#debug 9. still alive
#debug
#debug === All Try/Catch tests passed ===

class Main
{
    public function validate(int $age): void|Exception
    {
        if ($age < 0) {
            throw new Exception('age cannot be negative');
        }
        if ($age > 150) {
            throw new Exception('age too large');
        }
    }

    public function safeDivide(int $a, int $b): int|Exception
    {
        if ($b === 0) {
            throw new Exception('division by zero');
        }
        return $a / $b;
    }

    public function main(): void
    {
        echo "=== Try/Catch with Exception ===\n\n";

        // 1. 正常
        echo "1. ";
        try {
            $this->validate(25);
            echo "age=25 OK\n";
        } catch (Exception $e) {
            echo "CAUGHT: " . $e . "\n";
        }

        // 2. throw + catch
        echo "2. ";
        try {
            $this->validate(-1);
            echo "no\n";
        } catch (Exception $e) {
            echo "caught: " . $e . "\n";
        }

        // 3. 另一个异常
        echo "3. ";
        try {
            $this->validate(200);
        } catch (Exception $e) {
            echo "caught: " . $e . "\n";
        }

        // 4. finally
        echo "4. ";
        try {
            $this->validate(30);
            echo "OK, ";
        } catch (Exception $e) {
            echo "caught, ";
        }
        finally {
            echo "finally ran\n";
        }

        // 5. throw + finally
        echo "5. ";
        try {
            $this->validate(-5);
            echo "no, ";
        } catch (Exception $e) {
            echo "caught=" . $e . ", ";
        }
        finally {
            echo "finally\n";
        }

        // 6. 仅 finally
        echo "6. ";
        try {
            echo "try, ";
        }
        finally {
            echo "finally\n";
        }

        // 7. 正常除法
        echo "7. div=" . $this->safeDivide(10, 2) . "\n";

        // 8. 异常后继续
        echo "8. ";
        try {
            $this->safeDivide(5, 0);
        } catch (Exception $e) {
            echo "div0=" . $e . "\n";
        }
        echo "9. still alive\n";

        echo "\n=== All Try/Catch tests passed ===\n";
    }
}
