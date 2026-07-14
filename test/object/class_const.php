<?php
#debug ===== class const test =====
#debug Hello, Class Demo!
#debug
#debug === done ===

class ConstDemo
{
    public const string GREETING = 'Hello, Class Demo!';
}

class Main
{
    public function main(): void
    {
        echo "===== class const test =====\n";
        echo ConstDemo::GREETING . "\n";
        echo "\n=== done ===\n";
    }
}
