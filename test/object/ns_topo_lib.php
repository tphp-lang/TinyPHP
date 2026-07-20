<?php // @skip — companion file, no class Main

// ns_topo_lib.php — 命名空间下多层继承 + 拓扑排序测试辅助文件
//
// 故意将子类定义在父类之前（PHP 语义上需要父类先存在，但 TinyPHP 解析器
// 只记录 parentName 不检查存在性，AOT 阶段统一收集所有类后再生成 C 代码）。
//
// Bug 触发条件（拓扑排序失效时）：
//   1. 类在命名空间下（C 名为 tphp_na_NS_tphp_class_X）
//   2. $byRefName 用 classRefName($c->name) 生成 key = tphp_class_X（全局类格式）
//   3. 父类查找用 classRefName(parentName) 生成 key = tphp_na_NS_tphp_class_X（命名空间格式）
//   4. key 不匹配 → isset 失败 → 拓扑排序退化为原始顺序
//   5. 原始顺序是子类在前 → C struct 中 _parent 字段引用未定义的父类 struct
//   6. C 编译错误：field '_parent' has incomplete type
//
// 拓扑排序正确时（修复后）：
//   - $byRefName 用 classCName($c) 生成 key = tphp_na_NS_tphp_class_X（命名空间格式）
//   - 父类查找用 classRefName(parentName) 生成 key = tphp_na_NS_tphp_class_X
//   - key 匹配 → 递归将父类先加入 $sorted → 父类 struct 在前 → C 编译通过

namespace TopoNS;

// ── 继承链 1：GrandChild → Child → ParentBase（子类在前）──
class GrandChild extends Child
{
    public function grandMethod(): int
    {
        return $this->val * 3;
    }
}

class Child extends ParentBase
{
    public function childMethod(): int
    {
        return $this->val * 2;
    }
}

// ── 继承链 2：OtherChild → OtherParent（子类在前）──
class OtherChild extends OtherParent
{
    public function greeting(): string
    {
        return 'Hello, ' . $this->name . '!';
    }
}

// ── 父类定义在最后 ──
class OtherParent
{
    public string $name;

    public function setName(string $n): void
    {
        $this->name = $n;
    }
}

class ParentBase
{
    public int $val;

    public function setVal(int $v): void
    {
        $this->val = $v;
    }

    public function baseMethod(): int
    {
        return $this->val;
    }
}
