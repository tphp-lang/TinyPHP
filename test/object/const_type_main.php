<?php // @multi @with const_type_lib.php
#debug ===== Global Constants =====
#debug string(12) "global-const"
#debug int(7)
#debug float(1.5)
#debug
#debug ===== Class Constants =====
#debug string(15) "Const Type Test"
#debug int(100)
#debug float(0.5)
#debug bool(true)
#debug
#debug ===== Interface Constants =====
#debug string(10) "production"
#debug int(30)
#debug
#debug ===== Enum Case Access =====
#debug string(6) "hearts"
#debug string(6) "HEARTS"
#debug string(6) "spades"
#debug
#debug ===== Enum Constants =====
#debug string(6) "hearts"
#debug int(2)
#debug
#debug ===== use const Import =====
#debug string(13) "Hello from NS"
#debug int(42)
#debug float(3.14)
#debug
#debug ===== self::CONST =====
#debug string(10) "self-const"
#debug int(99)
#debug
#debug ===== Interface Const via Implementor =====
#debug string(10) "production"
#debug int(30)
#debug
#debug === ALL const type tests done ===

use ConstLib\Config;
use ConstLib\Settings;
use ConstLib\Suit;
use ConstLib\AppSettings;
use const ConstLib\NS_GREETING;
use const ConstLib\NS_LIMIT;
use const ConstLib\NS_PI;

// 全局常量（带类型标注）
const string GLOBAL_STR = "global-const";
const int GLOBAL_INT = 7;
const float GLOBAL_FLOAT = 1.5;

class Main
{
    private const string SELF_TITLE = "self-const";
    private const int SELF_COUNT = 99;

    public function main(): void
    {
        echo "===== Global Constants =====\n";
        var_dump(GLOBAL_STR);
        var_dump(GLOBAL_INT);
        var_dump(GLOBAL_FLOAT);
        echo "\n";

        echo "===== Class Constants =====\n";
        var_dump(Config::TITLE);
        var_dump(Config::MAX_CONN);
        var_dump(Config::RATE);
        var_dump(Config::ENABLED);
        echo "\n";

        echo "===== Interface Constants =====\n";
        var_dump(Settings::MODE);
        var_dump(Settings::TIMEOUT);
        echo "\n";

        echo "===== Enum Case Access =====\n";
        var_dump(Suit::HEARTS->value);
        var_dump(Suit::HEARTS->name);
        var_dump(Suit::SPADES->value);
        echo "\n";

        echo "===== Enum Constants =====\n";
        var_dump(Suit::DEFAULT);
        var_dump(Suit::CASE_COUNT);
        echo "\n";

        echo "===== use const Import =====\n";
        var_dump(NS_GREETING);
        var_dump(NS_LIMIT);
        var_dump(NS_PI);
        echo "\n";

        echo "===== self::CONST =====\n";
        var_dump(self::SELF_TITLE);
        var_dump(self::SELF_COUNT);
        echo "\n";

        echo "===== Interface Const via Implementor =====\n";
        $app = new AppSettings();
        var_dump($app->getMode());
        var_dump($app->getTimeout());
        echo "\n";

        echo "=== ALL const type tests done ===\n";
    }
}
