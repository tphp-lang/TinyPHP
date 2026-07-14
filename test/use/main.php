<?php // @multi @with fns.php
#debug string(13) "Hello, World!"
#debug int(7)
#debug int(12)
#debug int(20)
#debug int(18)

// 组合式 use function 导入（多函数批量导入同一命名空间）
use function Demo\Sub\{
    greet,
    add,
    multiply,
    double as twice
};

class Main
{
    public function main(): void {
        // 基础调用
        var_dump(greet("World"));
        var_dump(add(3, 4));
        var_dump(multiply(3, 4));

        // 别名调用（double as twice）
        var_dump(twice(10));

        // 在表达式中使用
        $result = add(multiply(2, 3), multiply(3, 4));
        var_dump($result);
    }
}
