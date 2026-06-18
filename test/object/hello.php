<?php

namespace Other;

const IS_OTHER = "other"; // 这个是作用域在 命名空间的 Other 中的常量

class Other
{
    public function hello(): self
    {
        echo "hello world\n";
        var_dump(IS_OTHER);
        return $this; // 可返回 this 来给 链式调用
    }

    public function world(): void
    {
        echo "world\n";
    }
}
