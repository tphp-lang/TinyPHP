<?php

namespace MyEmun;

// 枚举类型 , 类似C的枚举差不多。但是只有int和string两种类型。

// int 类型枚举
enum MyInt: int
{
    case A = 1;
    case B = 2;
}

// str 类型枚举
enum MyStr: string
{
    case A = "a";
    case B = "b";
}


class IsMyEmun
{

    public function isMyInt(MyInt $i): int
    {
        var_dump($i); // 输出 (enum) MyInt::X
        return $i->value;
    }

    public function isMyStr(MyStr $s): string
    {
        var_dump($s); // 输出 (enum) MyStr::X
        return $s->value;
    }
}
