<?php
// pcntl_posix_codegen.php — 验证 pcntl/posix C 函数的参数类型转换
//
// 背景：pcntl_wait(t_int*) 需要 byRef 取地址，posix_kill(t_int, t_int) 需要
//       t_var 自动解包为 t_int。这两个函数未注册到 $builtinFnParamCtypes 时，
//       CodeGenerator 不生成 & 取地址和 VAR_AS_INT 解包，导致编译错误。
//
// 关键点：foreach 变量必须是 t_var（未预先定型为 t_int），才能触发
//         posix_kill(t_var) 的类型不匹配硬错误。
//         若 foreach 变量已被赋值为 t_int（如 $pid = pcntl_wait(...)），
//         CodeGenerator 会在 foreach 中解包为 t_int，掩盖 t_var 问题。
//
// 策略：不 @skip，所有平台编译运行。用运行时变量 $enablePcntl 控制不实际
//       调用 pcntl/posix（Windows 上会 fatal exit），但编译期仍生成 C 代码
//       验证 byRef 和 t_var 解包正确。

#import pcntl
#import posix

#debug compile_ok=1

class Main {
    public function main(): void {
        // 运行时控制：Windows 上 pcntl/posix 不可用，设为 false
        // 编译期仍生成 C 代码（验证参数类型转换），运行时跳过实际调用
        $enablePcntl = false;

        if ($enablePcntl) {
            // ── 1. pcntl_wait(t_int* status) byRef 测试 ──
            // $status = 0 推导为 t_int，pcntl_wait 期望 t_int*
            // 正确生成：tphp_fn_pcntl_wait(&status)
            // 错误生成：tphp_fn_pcntl_wait(status) — 整数传指针
            $status = 0;
            $waitRet = pcntl_wait($status);
            echo "wait_pid=" . $waitRet . " status=" . $status . "\n";

            // ── 2. pcntl_waitpid(t_int pid, t_int* status, t_int options) ──
            // 第二参数 byRef，验证 &status
            $childPid = pcntl_fork();
            if ($childPid > 0) {
                $ret = pcntl_waitpid($childPid, $status, 0);
                echo "waitpid_ret=" . $ret . "\n";
            }

            // ── 3. posix_kill(t_int pid, t_int sig) t_var 解包测试 ──
            // $pids 无泛型注解 → array<mixed>，元素为 t_var
            // foreach 取出的 $pidVal 是 t_var（未被预先赋值为 t_int）
            // 正确生成：tphp_fn_posix_kill(VAR_AS_INT(pidVal), 15)
            // 错误生成：tphp_fn_posix_kill(pidVal, 15) — struct 传 long long（硬错误）
            $pids = [1, 2, 3];
            foreach ($pids as $idx => $pidVal) {
                posix_kill($pidVal, 15);
            }

            // ── 4. posix_kill 字面量参数 ──
            // 字面量 15 直接是 t_int，无需解包
            posix_kill(1, 15);
        }

        echo "compile_ok=1\n";
    }
}
