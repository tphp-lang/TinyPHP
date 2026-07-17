#include "../demo.h"

// 纯phpc是不需要tphp任何内部封装前缀的函数，就是常规的 c 语法

// 无tphp内部封装前缀的函数
void demo_hello(){
    printf("hello world\n");
}

// 无tphp内部封装前缀的函数
int create_class_a(int a, int b){
    return a + b;
}

// 无tphp内部封装前缀的函数
int class_a_add(int c, int d){
    return c + d;
}