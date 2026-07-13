<?php // @multi @with child/child.php

#debug ===== Export Functions =====
#debug int(3)
#debug string(13) "Hello, World!"
#debug float(3.14)
#debug bool(true)
#debug bool(false)
#debug Exported void function
#debug ===== Annotation ROUTE =====
#debug array(5) {
#debug   [0]=>
#debug   object(AnnotationEntry)#1
#debug   [1]=>
#debug   object(AnnotationEntry)#1
#debug   [2]=>
#debug   object(AnnotationEntry)#1
#debug   [3]=>
#debug   object(AnnotationEntry)#1
#debug   [4]=>
#debug   object(AnnotationEntry)#1
#debug }
#debug --- ROUTE foreach entries ---
#debug [0] Main->test method
#debug [1] Main->multiTest method
#debug [2] Child\MyChild class
#debug [3] multiFn function
#debug [4] multiAttrFunc function
#debug --- ROUTE foreach call/newInstance ---
#debug Hello, World test->42!
#debug Multi test method!
#debug Hello, MyChild!
#debug Multi fn 99!
#debug Multi attr fn 1!
#debug ===== Annotation GET_NAME =====
#debug array(3) {
#debug   [0]=>
#debug   object(AnnotationEntry)#1
#debug   [1]=>
#debug   object(AnnotationEntry)#1
#debug   [2]=>
#debug   object(AnnotationEntry)#1
#debug }
#debug --- GET_NAME foreach entries ---
#debug [0] Main->multiTest method
#debug [1] Child\MyChild->hello method
#debug [2] multiAttrFunc function
#debug --- GET_NAME foreach call ---
#debug Multi test method!
#debug Hello, MyChild!
#debug Multi attr fn 1!

use const Child\GET_NAME;

// 注解类型声明（Parser const 循环先于 function 循环，故置顶）

#[Attribute(path: string)]
const ROUTE = [];

// #[Export("name")] — 标记独立函数导出为 C 函数（-shared 模式生效）
// 非 -shared 模式下被静默忽略，函数仍可正常调用

#[Export("add")]
function add(int $a, int $b): int {
    return $a + $b;
}

#[Export("greet")]
function greet(string $name): string {
    return "Hello, $name!";
}

#[Export("pi_value")]
function pi_value(): float {
    return 3.14;
}

#[Export("is_positive")]
function is_positive(int $n): bool {
    return $n > 0;
}

#[Export("print_msg")]
function print_msg(string $msg): void {
    echo "$msg\n";
}

// 函数同时带 #[Export] 和 #[ROUTE] —— 两个注解系统互不干扰
// 非共享模式下 #[Export] 被忽略，#[ROUTE] 正常收集到注解数组

#[Export("multi_fn")]
#[ROUTE("/fn")]
function multiFn(int $n): void {
    echo "Multi fn $n!\n";
}

// 多层不同注解 on 函数：#[ROUTE] + #[GET_NAME] 共存
// 同名注解重复使用会报语法错误（如 #[ROUTE("/a")] #[ROUTE("/b")] 不允许）

#[ROUTE("/fn/one")]
#[GET_NAME("multiAttrFn")]
function multiAttrFunc(int $n): void {
    echo "Multi attr fn $n!\n";
}

class Main
{
    public function main(): void {
        echo "===== Export Functions =====\n";
        var_dump(add(1, 2));
        var_dump(greet("World"));
        var_dump(pi_value());
        var_dump(is_positive(5));
        var_dump(is_positive(-3));
        print_msg("Exported void function");

        echo "===== Annotation ROUTE =====\n";
        var_dump(ROUTE);
        echo "--- ROUTE foreach entries ---\n";
        // foreach 遍历注解数组，$v 为 tphp_class_AnnotationEntry*
        $i = 0;
        foreach (ROUTE as $v) {
            echo "[$i] {$v->name} {$v->type}\n";
            $i++;
        }
        echo "--- ROUTE foreach call/newInstance ---\n";
        // foreach 中 $v->call() / $v->newInstance() 通过运行时分发
        foreach (ROUTE as $v) {
            if ($v->type === 'class') {
                $obj = $v->newInstance();
                $obj->hello();
            } elseif ($v->name === 'Main->test') {
                $v->call(42);
            } elseif ($v->name === 'multiFn') {
                $v->call(99);
            } elseif ($v->name === 'multiAttrFunc') {
                $v->call(1);
            } else {
                $v->call();
            }
        }

        echo "===== Annotation GET_NAME =====\n";
        var_dump(GET_NAME);
        echo "--- GET_NAME foreach entries ---\n";
        $i = 0;
        foreach (GET_NAME as $v) {
            echo "[$i] {$v->name} {$v->type}\n";
            $i++;
        }
        echo "--- GET_NAME foreach call ---\n";
        foreach (GET_NAME as $v) {
            if ($v->name === 'multiAttrFunc') {
                $v->call(1);
            } else {
                $v->call();
            }
        }
    }

    #[ROUTE("/test")]
    public function test(int $id): void {
        echo "Hello, World test->$id!\n";
    }

    // 多层不同注解：同一方法同时标记 #[ROUTE] 和 #[GET_NAME]
    #[ROUTE("/multi")]
    #[GET_NAME("multiMethod")]
    public function multiTest(): void {
        echo "Multi test method!\n";
    }
}
