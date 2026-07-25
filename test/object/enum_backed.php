<?php
// enum backed 增强（Task 4.10）— 验证 from/tryFrom/cases 类型推导
//   覆盖场景：
//   - from($v)->name 链式调用（TypeChecker 推导 from 返回 enum 实例）
//   - tryFrom($v)?->name nullsafe 链式（TypeChecker 推导 tryFrom 返回 ?enum）
//   - tryFrom($v) ?? default null 合并（TypeChecker 推导 ?enum）
//   - cases()[0]->name 数组索引访问（TypeChecker 推导 cases 返回 array<enum>）
//   - foreach (cases() as $c) 迭代（TypeChecker 推导元素类型为 enum）
//   - tryFrom 命名参数 value: $v
//   - from 命名参数 value: $v

#debug === enum backed type inference ===
#debug
#debug [1] from chain:
#debug from_chain=Color#1
#debug
#debug [2] tryFrom nullsafe chain (valid):
#debug try_chain_valid=Color#3
#debug
#debug [3] tryFrom nullsafe chain (invalid → null):
#debug try_chain_invalid=null
#debug
#debug [4] tryFrom null coalesce:
#debug coalesce=Color#2
#debug
#debug [5] cases array index:
#debug cases_idx0=RED
#debug cases_idx2=BLUE
#debug
#debug [6] foreach over cases:
#debug foreach_names=RED,GREEN,BLUE
#debug
#debug [7] from with named arg:
#debug from_named=GREEN
#debug
#debug [8] tryFrom with named arg:
#debug try_named=RED
#debug try_named_null=null
#debug
#debug [9] string backing from chain:
#debug str_from_chain=N
#debug
#debug [10] string backing tryFrom ?? default:
#debug str_coalesce=S
#debug
#debug === all enum backed tests passed ===

enum Color: int
{
    case RED = 1;
    case GREEN = 2;
    case BLUE = 3;

    public function label(): string
    {
        return "Color#" . $this->value;
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
        echo "=== enum backed type inference ===\n\n";

        // [1] from() 链式调用 — TypeChecker 推导 from(int) 返回 enum 实例
        echo "[1] from chain:\n";
        $from_chain = Color::from(1)->label();
        echo "from_chain=" . $from_chain . "\n\n";

        // [2] tryFrom()?->name nullsafe 链式 — TypeChecker 推导 tryFrom 返回 ?enum
        echo "[2] tryFrom nullsafe chain (valid):\n";
        $try_valid = Color::tryFrom(3)?->label();
        echo "try_chain_valid=" . ($try_valid ?? "null") . "\n\n";

        // [3] tryFrom()?->name 无效值 → null
        echo "[3] tryFrom nullsafe chain (invalid → null):\n";
        $try_invalid = Color::tryFrom(99)?->label();
        echo "try_chain_invalid=" . ($try_invalid ?? "null") . "\n\n";

        // [4] tryFrom() ?? default — null 合并
        echo "[4] tryFrom null coalesce:\n";
        $coalesce = Color::tryFrom(99) ?? Color::GREEN;
        echo "coalesce=" . $coalesce->label() . "\n\n";

        // [5] cases() 数组索引访问 — TypeChecker 推导 cases 返回 array<enum>
        echo "[5] cases array index:\n";
        $all = Color::cases();
        echo "cases_idx0=" . $all[0]->name . "\n";
        echo "cases_idx2=" . $all[2]->name . "\n\n";

        // [6] foreach 迭代 cases() — TypeChecker 推导元素类型为 enum
        echo "[6] foreach over cases:\n";
        $names = [];
        foreach (Color::cases() as $c) {
            $names[] = $c->name;
        }
        echo "foreach_names=" . implode(",", $names) . "\n\n";

        // [7] from() 命名参数
        echo "[7] from with named arg:\n";
        $from_named = Color::from(value: 2);
        echo "from_named=" . $from_named->name . "\n\n";

        // [8] tryFrom() 命名参数
        echo "[8] tryFrom with named arg:\n";
        $try_named = Color::tryFrom(value: 1);
        echo "try_named=" . ($try_named?->name ?? "null") . "\n";
        $try_named_null = Color::tryFrom(value: 999);
        echo "try_named_null=" . ($try_named_null?->name ?? "null") . "\n\n";

        // [9] string backing from() 链式
        echo "[9] string backing from chain:\n";
        $str_from_chain = Direction::from("N")->value;
        echo "str_from_chain=" . $str_from_chain . "\n\n";

        // [10] string backing tryFrom() ?? default
        echo "[10] string backing tryFrom ?? default:\n";
        $str_coalesce = Direction::tryFrom("X") ?? Direction::SOUTH;
        echo "str_coalesce=" . $str_coalesce->value . "\n\n";

        echo "=== all enum backed tests passed ===\n";
    }
}
