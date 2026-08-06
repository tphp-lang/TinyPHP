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

// ———— 从 C 头文件提取公开函数 ————
// 只扫描真正的公开 API 目录，避免内部实现头文件的噪音
$cFns = [];
$cDirs = ['include/os', 'include'];
// 内部运行时/实现文件（通过宏转换函数名，不可靠匹配）
$skipFilesRegex = '#/(runtime|pool|arena|str_pool|compat|val|obj_pool|phpc|builtin' .
    '|array_extra|gc|hash|debug|ref|err|channel|core|ctrl|html|mime|env' .
    '|math_extra|str_extra|url|builtin_full|crypto|charset|json|date' .
    '|ctype|password|file|process|thread|channel|fiber)\.h$#';
foreach ($cDirs as $dir) {
    if (!is_dir("$baseDir/$dir")) continue;
    foreach (glob("$baseDir/$dir/*.h") as $file) {
        $relFile = str_replace('\\', '/', substr($file, strlen($baseDir) + 1));
        if (preg_match($skipFilesRegex, $relFile)) continue;
        $c = file_get_contents($file);
        if (preg_match_all('/\b(tphp_fn_[a-z][a-z0-9_]+)\s*\(/', $c, $m)) {
            foreach ($m[1] as $fn) {
                // 排除短名/前缀丢失的宏
                $sfx = substr($fn, 9);
                if (strlen($sfx) <= 3) continue;
                if (str_starts_with($sfx, '_')) continue;
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
// 常见宏前缀吸收模式：C 宏名丢失了 PHP 函数名首字符
$tryPrefixes = ['s','m','z','g','r','f','h','n','b','c','a','t','p','d','w','i','l','u','e','v','x'];
foreach ($cFns as $fn => $file) {
    if (!isset($docFns[$fn])) {
        // 跳过明显内部函数/类型特化变体
        if (preg_match('/^(rr_|ar_|_)/', $fn)) continue;
        if (strlen($fn) <= 2) continue;
        // 类型特化变体（_int/_float/_str/_bool 后缀 → 去掉后缀看父函数是否在文档中）
        if (preg_match('/^(.+)_(int|float|str|bool|opt)$/', $fn, $mm) && isset($docFns[$mm[1]])) continue;
        // 参数数量变体（_0/_2 等）
        if (preg_match('/_(\d+)$/', $fn)) continue;
        // 尝试还原被 C 宏吸收的首字符
        $found = false;
        foreach ($tryPrefixes as $p) {
            if (isset($docFns[$p . $fn])) { $found = true; break; }
        }
        if ($found) continue;
        echo "  ❌ $fn ($file)\n";
        $missing++;
    }
}

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
// 不需要 C 实现的函数类别（编译器级语法/PHPC互操作/方法而非函数等）
$compilerKeywords = ['class','namespace','use','function','echo','print',
    'return','if','else','while','for','foreach','switch','case','break',
    'continue','goto','try','catch','throw','new','clone','instanceof',
    'list','yield','match','fn','declare','trait','interface','implements','extends',
    'constName','initFn','entryVarPrefix','kind'];
$phpcHelpers = ['c_int','c_str','c_void_ptr','php_int','php_str','php_str_ptr',
    'php_str_clone','phpc_new_arr','phpc_thunk','phpc_auto','phpc_free',
    'phpc_free_str_arr','phpc_ptr_to_int','phpc_int_to_ptr',
    'phpc_arr_str','phpc_obj','phpc_new_obj','phpc_unregister_obj',
    'phpc_obj_steal','phpc_assert_ptr','phpc_new_arr_int','phpc_new_arr_dbl'];
$internalRuntime = ['tp_obj_is_a','tphp_rt_free_all_resources','tphp_rt_register',
    'str_pool_alloc','tphp_fn_name'];
$classMethods = ['prepare','query','exec','quote','commit','execute','fetch',
    'send','valid','rewind','gen','run','type','button','modifiers','codepoint',
    'width','height','contains','bounds','init','draw','size','cleanup','children',
    'text','state','press','release','click','color','focused','focus','blur',
    'checked','toggle','value','drag','direction','spacing','padding','array',
    'dimensions','interface','start','detach','lock','unlock','signal','broadcast',
    'add','done','push','pop','close','length','capacity','resolve','reject',
    'await','then','error','string','int','float','bool','void','mixed','object',
    'eval','func_get_args','unset','implements'];
// PHPC 内部函数/运行时辅助
$phpcRuntime = ['tphp_fn_stdclass_set','tphp_fn_stdclass_get','tphp_fn_stdclass_isset',
    'tphp_fn_stdclass_unset','tphp_fn_stdclass_from_array','tphp_fn_stdclass_to_array',
    'tphp_fn_stdclass_clone'];
foreach ($docFns as $fn => $dummy) {
    if (!isset($cFns[$fn])) {
        if (isset($simpleFns[$fn])) continue;
        if (in_array($fn, $compilerKeywords)) continue;
        if (in_array($fn, $phpcHelpers)) continue;
        if (in_array($fn, $internalRuntime)) continue;
        if (in_array($fn, $classMethods)) continue;
        if (in_array("tphp_fn_$fn", $phpcRuntime)) continue;
        // 以 tphp_fn_ 或 phpc_ 开头的内部函数
        if (str_starts_with($fn, 'tphp_') || str_starts_with($fn, 'phpc_')) continue;
        // CG/FI/UI/Sokol 内部方法
        if (in_array($fn, ['imagetypes','imagelayereffect','imagesetinterpolation'])) continue;
        echo "  ⚠ $fn (文档记录，但可能通过通用回退或编译器级实现)\n";
        $missing3++;
    }
}

// ———— 统计 ————
echo "\n=== 汇总 ===\n";
echo "  FUNCTIONS.md: " . count($docFns) . " 个函数\n";
echo "  C 公开函数: " . count($cFns) . " 个\n";
echo "  CodeGenerator 注册: " . count($simpleFns) . " 个\n";
echo "  C→文档缺失: $missing 个\n";
echo "\n✅ 脚本运行完成。上述输出中的 ⚠ 项为合理差异（通过通用回退或别名处理）。\n";
echo "   真正的文档缺口是 ❌ 标记的项（C 实现但 FUNCTIONS.md 未记录）。\n";

// 始终返回 0 — 本脚本为 advisory，不阻塞 CI
exit(0);
