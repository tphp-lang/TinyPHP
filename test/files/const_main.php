<?php // @multi @with const_global.php,const_lib.php

use Lib\Config;
use Lib\App;

class Main
{
    const string APP_NAME = "TinyPHP Const Test";
    const int    MAX_RETRY = 3;
    private const bool DEBUG_MODE = true;

    public function main(): void
    {
        echo "===== Global Constants =====\n";
        var_dump(GLOBAL_STR);
        var_dump(GLOBAL_INT);
        var_dump(GLOBAL_FLOAT);
        var_dump(GLOBAL_BOOL);

        echo "\n===== Namespace & Class Constants =====\n";

        // Lib\Config: 验证构造函数触发常量初始化
        $cfg = new Config();
        $cfg->dump();

        // Lib\App: 验证数组常量初始化（struct 字段 + 构造函数）
        $app = new App();
        $app->info();

        // Main: 自身类常量声明验证 — 编译通过即 OK
        // (:: 访问待后续实现)
        echo "\n=== ALL const tests done ===\n";
    }
}
