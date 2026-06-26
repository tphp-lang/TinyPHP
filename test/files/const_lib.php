<?php // @skip — companion file, no class Main


namespace Lib;

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
    public const array PORTS = [8080, 8443];
    private const array TAGS = ["web", "api"];

    public function info(): void
    {
        echo "  App constants defined OK\n";
    }
}
