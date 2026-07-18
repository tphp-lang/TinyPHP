<?php
// 对应 PHP 原生 tests/lang/operators/coalesce.phpt — ?? 运算符
// 过滤 AOT 不兼容部分：$nonexistent_variable（未声明变量）、对象属性访问、
// 函数调用链 foobar()[0] 等。保留变量、数组键、嵌套数组键的 ?? 用法
// 注：?? 左右类型必须一致（AOT 静态类型约束）
#debug int(7)
#debug string(7) "default"
#debug string(3) "bar"
#debug string(4) "bang"
#debug string(4) "deep"
#debug string(7) "default"
#debug int(0)
#debug int(42)

class Main {
    public function main(): void {
        // 变量非 null → 返回自身
        $var = 7;
        var_dump($var ?? 3);

        // 数组键不存在 → 返回默认值（同类型）
        $arr = ["foo" => "bar", "bing" => "bang"];
        var_dump($arr["missing"] ?? "default");

        // 数组键存在 → 返回值
        var_dump($arr["foo"] ?? "default");
        var_dump($arr["bing"] ?? "default");

        // 嵌套数组
        $nested = ["a" => ["b" => "deep"]];
        var_dump($nested["a"]["b"] ?? "default");

        // 嵌套键不存在 → 返回默认值
        var_dump($nested["a"]["missing"] ?? "default");

        // 整数数组
        $nums = ["x" => 10, "y" => 42];
        var_dump($nums["missing"] ?? 0);
        var_dump($nums["y"] ?? 0);
    }
}
