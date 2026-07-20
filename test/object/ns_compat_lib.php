<?php // @skip — companion file, no class Main

// ns_compat_lib.php — 命名空间兼容性测试辅助文件（NSCompat\Core 命名空间）
//   覆盖以下问题的修复：
//   问题2: 命名空间内 extends 短类名解析
//   问题3: 类型注解中的短类名（属性/参数/返回类型）

namespace NSCompat\Core;

// 基类：被 extends（问题2）+ 作为类型注解（问题3）
class Base
{
    public int $val;

    public function __construct(int $v)
    {
        $this->val = $v;
    }

    public function doubled(): int
    {
        return $this->val * 2;
    }
}

// 问题2: 命名空间内 extends 短类名（Derived 在 NSCompat\Core 命名空间内 extends Base）
class Derived extends Base
{
    public function __construct(int $v)
    {
        parent::__construct($v);
    }

    public function tripled(): int
    {
        return $this->val * 3;
    }
}

// 问题3: 类型注解中的短类名
//   - 属性类型: public Base $b
//   - 参数类型: __construct(Base $b)
//   - 返回类型: get(): Base
class Holder
{
    public Base $b;

    public function __construct(Base $b)
    {
        $this->b = $b;
    }

    public function get(): Base
    {
        return $this->b;
    }

    // 接收 Derived（Base 子类）作为参数 — 测试短类名 + 继承
    public function wrap(Derived $d): int
    {
        return $d->doubled() + $d->tripled();
    }
}
