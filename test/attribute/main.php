<?php // @multi @with child/child.php

#debug ===== ROUTE =====
#debug array(4) {
#debug   [0]=>
#debug   object(AnnotationEntry)#3
#debug   [1]=>
#debug   object(AnnotationEntry)#4
#debug   [2]=>
#debug   object(AnnotationEntry)#5
#debug   [3]=>
#debug   object(AnnotationEntry)#6
#debug }
#debug string(10) "Main->test"
#debug string(6) "method"
#debug array(1) {
#debug   [0]=>
#debug   string(5) "/test"
#debug }
#debug Hello, World test->12!
#debug Hello, World test2!
#debug string(13) "Child\MyChild"
#debug string(5) "class"
#debug Hello, MyChild!
#debug string(4) "Demo"
#debug string(5) "class"
#debug Demo Constructor TinyPHP!
#debug Hello, World Demo!
#debug ===== GET_NAME =====
#debug array(2) {
#debug   [0]=>
#debug   object(AnnotationEntry)#1
#debug   [1]=>
#debug   object(AnnotationEntry)#2
#debug }
#debug string(11) "Main->test3"
#debug string(6) "method"
#debug Hello, World test3!
#debug string(20) "Child\MyChild->hello"
#debug string(6) "method"
#debug Hello, MyChild!

use const Child\GET_NAME;

// 注解声明：#[Attribute(param: type, ...)] const NAME = [];
// 注解使用：#[NAME(arg1, arg2, ...)] — 仅位置参数
// AnnotationEntry: $data(位置参数数组), $type(method/static_method/class/function), $name(限定名)
//   call(...$args)        — 调用方法/静态方法/函数（编译期展开为零开销直接调用）
//   newInstance(...$args) — 实例化类（编译期展开为 new_tphp_class_X(args)）
//
// 注解常量遵循常量作用域规则（与普通常量一致）:
//   短名匹配（同命名空间 + 全局回退）；use const 导入解析为 FQ 名精确匹配
//   全局常量（如 ROUTE）无需 use const 导入即可跨命名空间使用
// 扫描顺序: mainClass(Main) → extraClasses(按辅助文件优先顺序) → functions
//   ROUTE[0]=Main->test  ROUTE[1]=Main->test2  ROUTE[2]=Child\MyChild  ROUTE[3]=Demo
//   GET_NAME[0]=Main->test3  GET_NAME[1]=Child\MyChild->hello

#[Attribute(path: string)]
const ROUTE = [];

class Main
{
    public function main(): void {
        echo "===== ROUTE =====\n";
        var_dump(ROUTE);
        var_dump(ROUTE[0]->name);
        var_dump(ROUTE[0]->type);
        var_dump(ROUTE[0]->data);
        ROUTE[0]->call(12);
        ROUTE[1]->call();
        var_dump(ROUTE[2]->name);
        var_dump(ROUTE[2]->type);
        $mc = ROUTE[2]->newInstance();
        $mc->hello();
        var_dump(ROUTE[3]->name);
        var_dump(ROUTE[3]->type);
        $demo = ROUTE[3]->newInstance("TinyPHP");
        $demo->hello();

        echo "===== GET_NAME =====\n";
        var_dump(GET_NAME);
        var_dump(GET_NAME[0]->name);
        var_dump(GET_NAME[0]->type);
        GET_NAME[0]->call();
        var_dump(GET_NAME[1]->name);
        var_dump(GET_NAME[1]->type);
        GET_NAME[1]->call();
    }

    #[ROUTE("/test")]
    public function test(int $id): void {
        echo "Hello, World test->$id!\n";
    }

    #[ROUTE("/test2")]
    public function test2(): void {
        echo "Hello, World test2!\n";
    }

    #[GET_NAME("/test3")]
    public function test3(): void {
        echo "Hello, World test3!\n";
    }
}

#[ROUTE("/Demo")]
class Demo {
    public function __construct(string $name) {
        echo "Demo Constructor $name!\n";
    }

    public function hello(): void {
        echo "Hello, World Demo!\n";
    }
}
