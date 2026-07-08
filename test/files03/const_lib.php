<?php // @skip — companion file, no class Main


namespace Lib;

// 命名空间常量，只能同一个命名空间使用，并非全局常量
const NS_VERSION = "2.0.0";
const NS_MAX     = 100;

class Config
{
    const string NAME = "lib-config";
    public const int TIMEOUT = 30;
    private const float RATE = 0.75;
    private const bool DEBUG = false;
    private const string MODE = "production";

    public function dump(): void
    {
        var_dump(NS_MAX);
        echo "  Config constants defined OK\n";
    }
}

class App
{
    public const string ENV = "production";
    public const int PORT = 8080;
    private const array TAGS = ["web", "api"];
}

function myLibFn(): void
{
    var_dump(NS_VERSION);
    echo "Hello, Function Lib!\n";
}
