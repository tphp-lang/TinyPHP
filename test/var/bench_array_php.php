<?php // @skip — native PHP benchmark, not a TPHP entry point

echo "=== PHP 原生数组性能 ===\n\n";

$loops = 100000;

// === 测试1: 创建数组 ===
$start = hrtime(true);
for ($r = 0; $r < $loops; $r++) {
    $a = [1, 2, 3, 4, 5];
}
$e1 = hrtime(true) - $start;
echo "创建5元素数组 x{$loops}: " . ($e1 / 1e6) . " ms\n";

// === 测试2: 数组读写 ===
$b = [];
for ($i = 0; $i < 1000; $i++) {
    $b[$i] = $i;
}
$start = hrtime(true);
$s = 0;
for ($r = 0; $r < $loops; $r++) {
    for ($i = 0; $i < 1000; $i++) {
        $s += $b[$i];
    }
}
$e2 = hrtime(true) - $start;
echo "读写1000元素 x{$loops}: " . ($e2 / 1e6) . " ms\n";

// === 测试3: 数组创建+读写 ===
$start = hrtime(true);
for ($r = 0; $r < $loops; $r++) {
    $m = [0 => "test", 1 => 25, 2 => "sz"];
    $x = $m[0];
    $y = $m[1];
}
$e3 = hrtime(true) - $start;
echo "数组3元素读写 x{$loops}: " . ($e3 / 1e6) . " ms\n";

// === 测试4: count + 遍历 ===
$c = [];
for ($i = 0; $i < 100; $i++) {
    $c[$i] = $i * 2;
}
$start = hrtime(true);
$t = 0;
for ($r = 0; $r < $loops; $r++) {
    $n = count($c);
    for ($i = 0; $i < $n; $i++) {
        $t += $c[$i];
    }
}
$e4 = hrtime(true) - $start;
echo "count+遍历100元素 x{$loops}: " . ($e4 / 1e6) . " ms\n";

// === 测试5: 嵌套数组 ===
$start = hrtime(true);
for ($r = 0; $r < 1000; $r++) {
    $nest = [[1, 2], [3, 4]];
    $z = $nest[0][0] + $nest[1][1];
}
$e5 = hrtime(true) - $start;
echo "嵌套数组 x1000: " . ($e5 / 1e6) . " ms\n";

$total = ($e1 + $e2 + $e3 + $e4 + $e5) / 1e6;
echo "\n  总时间: " . round($total, 1) . " ms\n";
echo "=== done ===\n";
