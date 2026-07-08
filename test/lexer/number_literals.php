<?php
// 数字字面量格式测试：十六进制 / 二进制 / 八进制 / 科学计数 / 下划线分隔
#debug ===== hex =====
#debug int(31)
#debug int(255)
#debug int(3405691582)
#debug ===== binary =====
#debug int(10)
#debug int(170)
#debug ===== octal =====
#debug int(15)
#debug int(511)
#debug ===== scientific =====
#debug float(1000)
#debug float(1500)
#debug float(0.001)
#debug float(1500)
#debug ===== underscores =====
#debug int(1000000)
#debug float(1000.5)
#debug ===== done =====

class Main
{
    public function main(): void
    {
        // ========== 十六进制 ==========
        echo "===== hex =====\n";
        $h1 = 0x1F;
        var_dump($h1);            // expect: int(31)

        $h2 = 0xFF;
        var_dump($h2);            // expect: int(255)

        $h3 = 0xCAFE_BABE;
        var_dump($h3);            // expect: int(3405691582)

        // ========== 二进制 ==========
        echo "===== binary =====\n";
        $b1 = 0b1010;
        var_dump($b1);            // expect: int(10)

        $b2 = 0b1010_1010;
        var_dump($b2);            // expect: int(170)

        // ========== 八进制（0o 前缀） ==========
        echo "===== octal =====\n";
        $o1 = 0o17;
        var_dump($o1);            // expect: int(15)

        $o2 = 0o777;
        var_dump($o2);            // expect: int(511)

        // ========== 科学计数 ==========
        echo "===== scientific =====\n";
        $s1 = 1e3;
        var_dump($s1);            // expect: float(1000)

        $s2 = 1.5e3;
        var_dump($s2);            // expect: float(1500)

        $s3 = 1e-3;
        var_dump($s3);            // expect: float(0.001)

        $s4 = 1.5E+3;
        var_dump($s4);            // expect: float(1500)

        // ========== 下划线分隔 ==========
        echo "===== underscores =====\n";
        $u1 = 1_000_000;
        var_dump($u1);            // expect: int(1000000)

        $u2 = 1_000.5;
        var_dump($u2);            // expect: float(1000.5)

        echo "===== done =====\n";
    }
}
