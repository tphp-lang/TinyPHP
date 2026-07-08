<?php // @multi @with const_global.php,const_lib.php
#debug ===== Self Constants =====
#debug string(17) "Const Access Test"
#debug int(5)
#debug
#debug ===== Global Constants =====
#debug string(12) "global hello"
#debug int(42)
#debug float(3.14159)
#debug bool(true)
#debug
#debug ===== Namespace & Class Constants =====
#debug string(10) "lib-config"
#debug int(30)
#debug string(10) "production"
#debug int(8080)
#debug
#debug ===== Lib Internal Namespace Constants =====
#debug string(5) "2.0.0"
#debug int(100)
#debug int(100)
#debug   Config constants defined OK
#debug string(5) "2.0.0"
#debug Hello, Function Lib!
#debug
#debug === ALL const tests done ===

use Lib\Config;
use Lib\App;
use function Lib\myLibFn;
use const Lib\NS_VERSION;
use const Lib\NS_MAX;

class Main
{
    private const string TITLE = "Const Access Test";
    private const int RETRIES = 5;

    public function main(): void
    {
        echo "===== Self Constants =====\n";
        var_dump(self::TITLE);
        var_dump(self::RETRIES);
        echo "\n";

        echo "===== Global Constants =====\n";
        var_dump(GLOBAL_STR);
        var_dump(GLOBAL_INT);
        var_dump(GLOBAL_FLOAT);
        var_dump(GLOBAL_BOOL);
        echo "\n";

        echo "===== Namespace & Class Constants =====\n";
        var_dump(Config::NAME);
        var_dump(Config::TIMEOUT);
        var_dump(App::ENV);
        var_dump(App::PORT);
        echo "\n";

        echo "===== Lib Internal Namespace Constants =====\n";
        // NS_VERSION / NS_MAX 是 Lib 命名空间常量，需通过 use const 导入后才能跨命名空间访问
        var_dump(NS_VERSION);
        var_dump(NS_MAX);
        $config = new Config();
        $config->dump();
        myLibFn();
        echo "\n";

        echo "=== ALL const tests done ===\n";
    }
}
