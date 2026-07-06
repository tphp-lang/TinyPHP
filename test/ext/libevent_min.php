<?php
// 最小测试：验证 libevent 扩展编译链接 + EventBase 基本功能
#debug ok

#import libevent

class Main
{
    public function main(): void {
        $base = new EventBase();
        $method = $base->getMethod();
        echo "method=" . $method . "\n";
        $base->free();
    }
}
