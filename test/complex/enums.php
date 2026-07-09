<?php // @skip — companion file, no class Main


namespace Complex\Enums;

// 命名空间内的 int 枚举
enum Status: int
{
    case ACTIVE = 1;
    case INACTIVE = 2;
    case BANNED = 3;
}

// 命名空间内的 string 枚举
enum Role: string
{
    case ADMIN = "admin";
    case USER = "user";
    case GUEST = "guest";
}
