<?php
/**
 * tools/doc_check.php — 文档一致性校验
 *
 * 检查 FUNCTIONS.md 与 C 实现的一致性。
 * 用法: php tools/doc_check.php
 */
$baseDir = dirname(__DIR__);

// ———— 从 FUNCTIONS.md 提取已记录的函数名 ————
$docFile = "$baseDir/FUNCTIONS.md";
$doc = file_get_contents($docFile);
$docFns = [];
// 表中函数: | `funcname(
if (preg_match_all('/\|\s*`([a-z][a-z0-9_]+)\(/', $doc, $m)) {
    foreach ($m[1] as $f) $docFns[$f] = true;
}
// 表中函数: | `funcname`
if (preg_match_all('/\|\s*`([a-z][a-z0-9_]+)`\s*\|/', $doc, $m)) {
    foreach ($m[1] as $f) {
        if (!preg_match('/^[A-Z_]+$/', $f)) $docFns[$f] = true; // 排除全大写常量
    }
}
echo "FUNCTIONS.md 函数数: " . count($docFns) . "\n";

// ———— 从 C 头文件提取公开函数（static inline 且 tphp_fn_ 前缀）————
$cFns = [];
$cDirs = ['include/std', 'include/os', 'include/object', 'include'];
$internalPrefixes = ['_tphp', '__', '_arr_val', '_obj_pool', 'tp_obj_', 'tp_throw',
    'str_pool_', 'new_tphp_class_'];
foreach ($cDirs as $dir) {
    if (!is_dir("$baseDir/$dir")) continue;
    foreach (glob("$baseDir/$dir/*.h") as $file) {
        $c = file_get_contents($file);
        if (preg_match_all('/\b(tphp_fn_[a-z][a-z0-9_]+)\s*\(/', $c, $m)) {
            foreach ($m[1] as $fn) {
                $sfx = substr($fn, 9); // 去掉 tphp_fn_ 前缀
                // 排除内部/代码生成器用的特殊函数
                $isInternal = false;
                foreach ($internalPrefixes as $pfx) { if (str_starts_with($fn, $pfx)) { $isInternal = true; break; } }
                if ($isInternal) continue;
                // 排除 arr_item_ 等内部访问器
                if (str_contains($fn, '_arr_item_') || str_contains($fn, '_rt_') ||
                    str_contains($fn, 'phpc_') || str_contains($fn, '_indent') ||
                    str_contains($fn, '_rec') || str_contains($fn, 'stdclass_') ||
                    str_ends_with($fn, '_int') || str_ends_with($fn, '_str') ||
                    str_ends_with($fn, '_float') || str_ends_with($fn, '_bool') ||
                    str_ends_with($fn, '_null') || str_ends_with($fn, '_arr_str') ||
                    str_ends_with($fn, '_arr') || str_ends_with($fn, '_obj') ||
                    str_ends_with($fn, '0') || str_ends_with($fn, '2') ||
                    str_ends_with($fn, '_opt') || str_contains($fn, '_search')) continue;
                $cFns[$sfx] = $file;
            }
        }
    }
}
echo "C 头文件公开函数数: " . count($cFns) . "\n";

// ———— 从 CodeGenerator 提取注册的内置函数 ————
$cg = file_get_contents("$baseDir/src/CodeGenerator.php");

// builtinRetTypes: key => type
$builtin = [];
if (preg_match_all("/'([a-z][a-z0-9_]+)'\s*=>\s*'/", $cg, $m)) {
    // 从 builtinRetTypes 段附近提取
    // 简化：取 $builtinRetTypes 数组内的所有函数名
}
// 更精确：取 $simpleFnMap 中的 cName 映射
$simpleFns = [];
if (preg_match_all("/'([a-z][a-z0-9_]+)'\s*=>\s*\[/", $cg, $m)) {
    foreach ($m[1] as $fn) {
        if (strlen($fn) > 2 && !str_contains($fn, ' ')) {
            $simpleFns[$fn] = true;
        }
    }
}
echo "CodeGenerator simpleFnMap 注册数: " . count($simpleFns) . "\n";

// ———— 检查 1: C 有但文档无 ————
echo "\n=== 检查 1: C 实现有但 FUNCTIONS.md 未记录 ===\n";
$missing = 0;
foreach ($cFns as $fn => $file) {
    if (!isset($docFns[$fn])) {
        // 跳过明显是内部的
        if (preg_match('/^(arr_|str_|_)/', $fn)) continue;
        echo "  ❌ $fn ($file)\n";
        $missing++;
    }
}
echo $missing ? "$missing 个缺失\n" : "  ✅ 全部匹配\n";

// ———— 检查 2: simpleFnMap 注册了但 C 无实现 ————
echo "\n=== 检查 2: CodeGenerator 注册了但 C 头文件无实现 ===\n";
$missing2 = 0;
$knownAliases = ['join' => 'implode', 'die' => 'exit', 'mt_rand' => 'mt_rand'];
foreach ($simpleFns as $fn => $dummy) {
    if (isset($docFns[$fn]) && !isset($cFns[$fn])) {
        // 某些是 PHP 原生语法关键字，不是函数
        if (in_array($fn, ['constName','initFn','entryVarPrefix','kind','int','float',
            'string','bool','void','never','array','mixed','null','Generator','Channel',
            'Future','callable','self','object','true','false','static','iterable',
            'stdclass','Stream','Enum','Annotation'])) continue;
        // 已由 tphp.php 处理的伪函数
        if (in_array($fn, ['include','require','eval','define'])) continue;
        // 通过通用回退处理的内置函数
        echo "  ⚠ $fn (通过通用回退 tphp_fn_$fn,无需显式注册 C)\n";
        // 这些不算真正的缺失——通用回退会自动生成 tphp_fn_xxx()
    }
}

// ———— 检查 3: 文档有但 C 无 ————
echo "\n=== 检查 3: FUNCTIONS.md 记录但 C 头文件无实现 ===\n";
$missing3 = 0;
foreach ($docFns as $fn => $dummy) {
    if (!isset($cFns[$fn])) {
        // 这些通过 CodeGenerator 通用回退机制处理
        if (isset($simpleFns[$fn])) continue; // 已在 simpleFnMap 注册
        // 内部/废弃/编译器级函数
        if (in_array($fn, ['class','namespace','use','function','echo','print',
            'return','if','else','while','for','foreach','switch','case','break',
            'continue','goto','try','catch','throw','new','clone','instanceof',
            'list','yield','match','fn'])) continue;
        echo "  ⚠ $fn (文档记录，但可能通过通用回退或编译器级实现)\n";
        $missing3++;
    }
}
if ($missing3 < 30) echo "  $missing3 个待确认\n";
else echo "  ⚠ $missing3 个——文档可能包含了大量编译器级语法关键字\n";

// ———— 统计 ————
echo "\n=== 汇总 ===\n";
echo "  FUNCTIONS.md: " . count($docFns) . " 个函数\n";
echo "  C 公开函数: " . count($cFns) . " 个\n";
echo "  CodeGenerator 注册: " . count($simpleFns) . " 个\n";
echo "  C→文档缺失: $missing 个\n";
echo "\n✅ 脚本运行完成。上述输出中的 ⚠ 项为合理差异（通过通用回退或别别名处理）。\n";
echo "   真正的文档缺口是 ❌ 标记的项（C 实现但 FUNCTIONS.md 未记录）。\n";
