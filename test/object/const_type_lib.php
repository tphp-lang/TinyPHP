<?php // @skip — companion file, no class Main

// const_type_lib.php — Task 2.12 常量类型推导测试辅助文件
//   覆盖场景：
//     - 命名空间常量（use const 导入）
//     - 类常量（带类型标注）
//     - 接口常量
//     - 枚举 case 与枚举常量

namespace ConstLib;

// 命名空间常量（供 use const 跨命名空间导入）
const string NS_GREETING = "Hello from NS";
const int NS_LIMIT = 42;
const float NS_PI = 3.14;

// 带类型标注的类常量
class Config
{
    public const string TITLE = "Const Type Test";
    public const int MAX_CONN = 100;
    public const float RATE = 0.5;
    public const bool ENABLED = true;
}

// 接口常量（接口被解析为 isInterface=true 的 ClassNode）
interface Settings
{
    public const string MODE = "production";
    public const int TIMEOUT = 30;
}

// 枚举：case + 常量
enum Suit: string
{
    case HEARTS = "hearts";
    case SPADES = "spades";

    const string DEFAULT = "hearts";
    const int CASE_COUNT = 2;
}

// 实现接口的类（验证接口常量可通过 InterfaceName::CONST 访问）
class AppSettings implements Settings
{
    public function getMode(): string
    {
        return Settings::MODE;
    }

    public function getTimeout(): int
    {
        return Settings::TIMEOUT;
    }
}
