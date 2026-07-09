<?php
// enum 方法/常量/implements 测试 — 验证 P2-2
//   - 用户实例方法（访问 $this->value / $this->name）
//   - 枚举常量（const TYPE = ...）
//   - 自动静态方法 cases() / from() / tryFrom()
//   - implements 接口（记录用，不做 vtable 强制）
//   - 链式调用 Color::from($v)->label()

#debug === enum method ===
#debug red_label=Color#1
#debug blue_label=Color#3
#debug red_primary=1
#debug
#debug === enum const ===
#debug pattern=100
#debug
#debug === cases() ===
#debug cases_count=3
#debug
#debug === from() ===
#debug from2=GREEN
#debug
#debug === tryFrom() ===
#debug try3=BLUE
#debug try99=null
#debug
#debug === chain call ===
#debug chain=Color#1
#debug
#debug === string backing ===
#debug north=N
#debug fromS=SOUTH
#debug
#debug === done ===

interface HasLabel
{
    public function label(): string;
}

enum Color: int implements HasLabel
{
    case RED = 1;
    case GREEN = 2;
    case BLUE = 3;

    public const int PATTERN = 100;

    public function label(): string
    {
        $v = $this->value;
        return "Color#" . $v;
    }

    public function isPrimary(): bool
    {
        $v = $this->value;
        return $v <= 3;
    }
}

enum Direction: string
{
    case NORTH = "N";
    case SOUTH = "S";
}

class Main
{
    public function main(): void
    {
        echo "=== enum method ===\n";
        echo "red_label=" . Color::RED->label() . "\n";
        echo "blue_label=" . Color::BLUE->label() . "\n";
        echo "red_primary=" . (Color::RED->isPrimary() ? 1 : 0) . "\n";

        echo "\n=== enum const ===\n";
        echo "pattern=" . Color::PATTERN . "\n";

        echo "\n=== cases() ===\n";
        $all = Color::cases();
        echo "cases_count=" . count($all) . "\n";

        echo "\n=== from() ===\n";
        $g = Color::from(2);
        echo "from2=" . $g->name . "\n";

        echo "\n=== tryFrom() ===\n";
        $ok = Color::tryFrom(3);
        if ($ok === null) {
            echo "try3=null\n";
        } else {
            echo "try3=" . $ok->name . "\n";
        }
        $bad = Color::tryFrom(99);
        if ($bad === null) {
            echo "try99=null\n";
        } else {
            echo "try99=" . $bad->name . "\n";
        }

        echo "\n=== chain call ===\n";
        echo "chain=" . Color::from(1)->label() . "\n";

        echo "\n=== string backing ===\n";
        $n = Direction::NORTH;
        echo "north=" . $n->value . "\n";
        $fromS = Direction::from("S");
        echo "fromS=" . $fromS->name . "\n";

        echo "\n=== done ===\n";
    }
}
