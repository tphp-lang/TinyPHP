<?php // @multi @with const_data.php
#debug ===== 1. global scope =====
#debug string(6) "global"
#debug int(999)
#debug bool(true)
#debug
#debug ===== 2. Class::CONST external =====
#debug float(3.14)
#debug float(314)
#debug
#debug ===== 3. self:: internal =====
#debug   [LOG]: started
#debug   [WARN]: disk low
#debug
#debug ===== 4. Main self:: =====
#debug TITLE   = Const Access Test
#debug int(5)
#debug
#debug === ALL scope tests done ===

use Calculator;
use Logger;

class Main
{
    const string TITLE = "Const Access Test";
    private const int RETRIES = 5;

    public function main(): void
    {
        // ============================================================
        // 1. 全局常量 — 全局可用
        // ============================================================
        echo "===== 1. global scope =====\n";
        var_dump(G_STR);
        var_dump(G_INT);
        var_dump(G_BOOL);

        // ============================================================
        // 2. 类常量 — ClassName::CONST 外部公共访问
        // ============================================================
        echo "\n===== 2. Class::CONST external =====\n";
        var_dump(Calculator::PI);      // public — 允许
        $calc = new Calculator();
        var_dump($calc->area(10.0));   // self::PI * 100

        // ============================================================
        // 3. 类常量 — self:: 内部访问
        // ============================================================
        echo "\n===== 3. self:: internal =====\n";
        $log = new Logger();
        $log->log("started");          // self::PREFIX
        $log->warn("disk low");        // self::WARN

        // ============================================================
        // 4. Main 自身常量
        // ============================================================
        echo "\n===== 4. Main self:: =====\n";
        echo "TITLE   = " . self::TITLE . "\n";
        var_dump(self::RETRIES);

        echo "\n=== ALL scope tests done ===\n";
    }
}
