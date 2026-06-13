<?php


namespace Main;

function main(): void
{
    // ========== 字符串 + 控制流 ==========

    // 不用 === 比较符 毕竟是强类型了，用 == 就行了，希望两种都兼容 

    // --- 1.1 字符串比较与 if/else ---
    $username = "admin";

    if ($username === "admin") {
        print("欢迎管理员\n");
    } else if ($username == "guest") { // 不使用 elseif 要 else if
        print("欢迎访客\n");
    } else {
        print("未知用户\n");
    }
    // 预期输出: 欢迎管理员

    // --- 1.2 字符串长度判断 ---
    $password = "Hello12345";

    if (strlen($password) >= 12) {
        print("密码强度: 强\n");
    } else if (strlen($password) >= 8) { // 不使用 elseif 要 else if
        print("密码强度: 中\n");
    } else {
        print("密码强度: 弱\n");
    }
    // 预期输出: 密码强度: 中

    // --- 1.3 字符串搜索与 switch ---
    $command = "start";

    switch ($command) {
        case "start":
            print("启动服务\n");
            break;
        case "stop":
            print("停止服务\n");
            break;
        case "restart":
            print("重启服务\n");
            break;
        default:
            print("未知命令: $command\n");
    }
    // 预期输出: 启动服务

    // --- 1.4 字符串遍历与 for 循环 ---
    $email = "user@example.com";

    for ($i = 0; $i < strlen($email); $i++) {
        if ($email[$i] == "@") {
            print("{$email[$i]}\n");
            break;
        }
        print("不是");
    }

    // 预期输出: @
}
