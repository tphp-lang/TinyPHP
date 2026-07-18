<?php
// 对应 PHP ext/standard/tests/strings/implode_basic.phpt
// 对应 PHP ext/standard/tests/strings/join_basic.phpt
// 测试 implode() / join()（别名）基本功能
// tphp 差异： 仅支持 string/int/float 元素；空数组返回空串
#debug string(0) ""
#debug string(0) ""
#debug string(9) "foobarbaz"
#debug string(11) "foo:bar:baz"
#debug string(9) "1-2-3-4-5"
#debug string(9) "foobarbaz"
#debug string(11) "foo:bar:baz"

class Main
{
    public function main(): void
    {
        // 空数组 + 默认粘合（空串）
        var_dump(implode("", []));

        // 空数组 + 自定义粘合
        var_dump(implode("nothing", []));

        // 字符串数组 + 空粘合
        var_dump(implode("", ["foo", "bar", "baz"]));

        // 字符串数组 + 自定义粘合
        var_dump(implode(":", ["foo", "bar", "baz"]));

        // 整数数组
        var_dump(implode("-", [1, 2, 3, 4, 5]));

        // join 是 implode 的别名
        var_dump(join("", ["foo", "bar", "baz"]));
        var_dump(join(":", ["foo", "bar", "baz"]));
    }
}
