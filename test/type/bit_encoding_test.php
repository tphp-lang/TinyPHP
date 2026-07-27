<?php

declare(strict_types=1);

// ============================================================
// Type 位编码单元测试 — 多级指针 / 位运算查询 / 容量 / 性能
//
// 运行方式：
//   cd c:\project\php\TinyPHP
//   php test/type/bit_encoding_test.php
//
// 测试范围：
//   1.  内置类型 idx 常量正确性
//   2.  类型比较性能：整数比较 vs 字符串比较（benchmark）
//   3.  多级指针：ref()->ref()->ref() → 3 级
//   4.  deref：3 级指针 deref 3 次回到 0 级
//   5.  isPointer() 对多级指针的正确性
//   6.  equals() 对不同 pointerLevel 的正确性
//   7.  位运算查询：isOption/isResult/isArray/isNullable/isReadOnly
//   8.  类型容量：idx 支持 65536+ 种类型
//   9.  指针容量：pointerLevel 支持 0-255
//   10. __toString() 对多级指针的输出
// ============================================================

require_once __DIR__ . '/../../src/Type.php';

$pass = 0;
$fail = 0;
$failures = [];

function check(bool $cond, string $label): void
{
    global $pass, $fail, $failures;
    if ($cond) {
        $pass++;
    } else {
        $fail++;
        $failures[] = $label;
        echo "[FAIL] $label\n";
    }
}

// 初始化内置类型
Type::init();

// ─────────────────────────────────────────────────────────────
// 测试 1: 内置类型 idx 常量正确
// ─────────────────────────────────────────────────────────────
check(Type::IDX_VOID === 0,       '测试1: IDX_VOID = 0');
check(Type::IDX_INT === 1,        '测试1: IDX_INT = 1');
check(Type::IDX_FLOAT === 2,      '测试1: IDX_FLOAT = 2');
check(Type::IDX_STRING === 3,     '测试1: IDX_STRING = 3');
check(Type::IDX_BOOL === 4,       '测试1: IDX_BOOL = 4');
check(Type::IDX_NULL === 5,       '测试1: IDX_NULL = 5');
check(Type::IDX_ARRAY === 6,      '测试1: IDX_ARRAY = 6');
check(Type::IDX_MIXED === 7,      '测试1: IDX_MIXED = 7');
check(Type::IDX_OBJECT === 8,     '测试1: IDX_OBJECT = 8');
check(Type::IDX_NEVER === 11,     '测试1: IDX_NEVER = 11');
check(Type::IDX_C_FILE === 17,    '测试1: IDX_C_FILE = 17');

// 内置实例 idx 一致性
check(Type::$int->idx() === Type::IDX_INT,    '测试1: Type::$int.idx = IDX_INT');
check(Type::$string->idx() === Type::IDX_STRING, '测试1: Type::$string.idx = IDX_STRING');
check(Type::$void->idx() === Type::IDX_VOID,  '测试1: Type::$void.idx = IDX_VOID');
check(Type::$never->idx() === Type::IDX_NEVER, '测试1: Type::$never.idx = IDX_NEVER');

// ─────────────────────────────────────────────────────────────
// 测试 2: 类型比较性能 — 整数比较 vs 字符串比较
// ─────────────────────────────────────────────────────────────
$N = 500000;

// 整数比较（Type::equals 内部用 === 比较 3 个 int）
$t1 = Type::$int;
$t2 = Type::$int;
$start = hrtime(true);
for ($i = 0; $i < $N; $i++) {
    $r = $t1->equals($t2);
}
$intTime = (hrtime(true) - $start) / 1e6; // ms

// 字符串比较（模拟旧式字符串类型比较）
$s1 = 'int';
$s2 = 'int';
$start = hrtime(true);
for ($i = 0; $i < $N; $i++) {
    $r = $s1 === $s2;
}
$strTime = (hrtime(true) - $start) / 1e6;

// 对象 === 比较（interning 后同一实例）
$start = hrtime(true);
for ($i = 0; $i < $N; $i++) {
    $r = $t1 === $t2;
}
$objTime = (hrtime(true) - $start) / 1e6;

printf("测试2: 性能 benchmark (N=%d)\n", $N);
printf("  整数比较 (Type::equals):  %.2f ms\n", $intTime);
printf("  字符串比较 (===):         %.2f ms\n", $strTime);
printf("  对象同一性 (===):         %.2f ms\n", $objTime);
// equals() 有方法调用开销，但内部是 O(1) 整数比较；interning 后 === 可用
$overheadRatio = $intTime > 0 ? $intTime / max($strTime, 0.001) : 0;
check($overheadRatio < 15, sprintf('测试2: equals() 方法调用开销可接受 (比值 %.1fx)', $overheadRatio));
check($r === true, '测试2: equals 结果正确');

// ─────────────────────────────────────────────────────────────
// 测试 3: 多级指针 — ref()->ref()->ref() 得到 3 级
// ─────────────────────────────────────────────────────────────
$p0 = Type::$int;
$p1 = $p0->ref();
$p2 = $p1->ref();
$p3 = $p2->ref();

check($p0->pointerLevel() === 0, '测试3: 基类型 pointerLevel = 0');
check($p1->pointerLevel() === 1, '测试3: ref() 1 次 pointerLevel = 1');
check($p2->pointerLevel() === 2, '测试3: ref() 2 次 pointerLevel = 2');
check($p3->pointerLevel() === 3, '测试3: ref() 3 次 pointerLevel = 3');

// 不可变性：原对象不被修改
check($p0->pointerLevel() === 0, '测试3: ref() 不修改原对象（不可变）');

// idx 和 flags 保持不变
check($p3->idx() === Type::IDX_INT, '测试3: 3 级指针 idx 仍为 IDX_INT');
check($p3->flags() === 0, '测试3: 3 级指针 flags 仍为 0');

// ─────────────────────────────────────────────────────────────
// 测试 4: deref — 3 级指针 deref 3 次回到 0 级
// ─────────────────────────────────────────────────────────────
$d1 = $p3->deref();
$d2 = $d1->deref();
$d3 = $d2->deref();

check($d1->pointerLevel() === 2, '测试4: deref 1 次 pointerLevel = 2');
check($d2->pointerLevel() === 1, '测试4: deref 2 次 pointerLevel = 1');
check($d3->pointerLevel() === 0, '测试4: deref 3 次 pointerLevel = 0');

// 0 级 deref 返回原对象
$d0 = Type::$int->deref();
check($d0 === Type::$int, '测试4: 0 级指针 deref 返回原对象');

// ─────────────────────────────────────────────────────────────
// 测试 5: isPointer() 对多级指针的正确性
// ─────────────────────────────────────────────────────────────
check(Type::$int->isPointer() === false, '测试5: int 不是指针');
check($p1->isPointer() === true,  '测试5: 1 级指针 isPointer = true');
check($p2->isPointer() === true,  '测试5: 2 级指针 isPointer = true');
check($p3->isPointer() === true,  '测试5: 3 级指针 isPointer = true');
check($d3->isPointer() === false, '测试5: deref 到 0 级 isPointer = false');

// 旧式 FLAG_POINTER 兼容
$legacyPtr = new Type(Type::IDX_INT, Type::FLAG_POINTER);
check($legacyPtr->isPointer() === true, '测试5: 旧式 FLAG_POINTER isPointer = true');
check($legacyPtr->pointerLevel() === 0, '测试5: 旧式 FLAG_POINTER pointerLevel = 0');

// ─────────────────────────────────────────────────────────────
// 测试 6: equals() 对不同 pointerLevel 的正确性
// ─────────────────────────────────────────────────────────────
check($p0->equals(Type::$int),         '测试6: int === int (0 级)');
check(!$p0->equals($p1),               '测试6: int(0) != int*(1)');
check(!$p1->equals($p2),               '测试6: int*(1) != int**(2)');
check(!$p2->equals($p3),               '测试6: int**(2) != int***(3)');
check($p3->equals($p3),                '测试6: int***(3) === int***(3)');
check($d3->equals(Type::$int),         '测试6: deref 到 0 级 === int');

// pointer() 工厂与 ref() 等价
$ptrViaFactory = Type::pointer(Type::$int);
check($ptrViaFactory->equals($p1),     '测试6: Type::pointer(int) === int.ref()');
check($ptrViaFactory->pointerLevel() === 1, '测试6: Type::pointer(int) pointerLevel = 1');

// ─────────────────────────────────────────────────────────────
// 测试 7: 位运算查询 — isOption/isResult/isArray/isNullable/isReadOnly
// ─────────────────────────────────────────────────────────────
$optInt = Type::option(Type::$int);
$resStr = Type::result(Type::$string);
$arrFloat = Type::array(Type::$float);
$readOnly = new Type(Type::IDX_INT, Type::FLAG_READONLY);

check($optInt->isOption(),             '测试7: option(int) isOption = true');
check(!$optInt->isResult(),            '测试7: option(int) isResult = false');
check($optInt->isNullable(),           '测试7: isNullable 是 isOption 的别名');
check(Type::$int->isNullable() === false, '测试7: int isNullable = false');

check($resStr->isResult(),             '测试7: result(string) isResult = true');
check(!$resStr->isOption(),            '测试7: result(string) isOption = false');

check($arrFloat->isArray(),            '测试7: array<float> isArray = true');
check(Type::$array->isArray(),         '测试7: 通用 array isArray = true');
check(!Type::$int->isArray(),          '测试7: int isArray = false');

check($readOnly->isReadOnly(),         '测试7: FLAG_READONLY isReadOnly = true');
check(!Type::$int->isReadOnly(),       '测试7: int isReadOnly = false');

// isVoid / isNever / isScalar / isMixed / isCLang
check(Type::$void->isVoid(),           '测试7: void isVoid = true');
check(Type::$never->isNever(),         '测试7: never isNever = true');
check(Type::$int->isScalar(),          '测试7: int isScalar = true');
check(!$p1->isScalar(),                '测试7: int* isScalar = false（有指针）');
check(Type::$mixed->isMixed(),         '测试7: mixed isMixed = true');

// ─────────────────────────────────────────────────────────────
// 测试 8: 类型容量 — idx 支持 65536+ 种类型
// ─────────────────────────────────────────────────────────────
// 直接构造高 idx 值，验证 24-bit 容量
$highIdx = 0xFFFF;       // 65535
$higherIdx = 0x10000;    // 65536
$maxIdx = 0xFFFFFF;      // 16777215 (24-bit 上限)

$tHigh = new Type($highIdx);
$tHigher = new Type($higherIdx);
$tMax = new Type($maxIdx);

check($tHigh->idx() === 65535,         '测试8: idx=65535 正确');
check($tHigher->idx() === 65536,       '测试8: idx=65536 正确（超过 spec 65536）');
check($tMax->idx() === 0xFFFFFF,       '测试8: idx=0xFFFFFF (16M) 正确');
check(!$tHigh->equals($tHigher),       '测试8: 不同 idx 不相等');

// 通过 TypeTable 注册大量用户类型，验证 idx 增长
$tt = new TypeTable();
$lastIdx = 0;
$regCount = 1000;
for ($i = 0; $i < $regCount; $i++) {
    $t = $tt->register("UserType{$i}", 'void*');
    $lastIdx = $t->idx();
}
check($lastIdx >= 256 + $regCount - 1, '测试8: 注册 1000 个类型后 idx >= 1255');
check($lastIdx > 256,                  '测试8: 用户类型 idx > 256');

// ─────────────────────────────────────────────────────────────
// 测试 9: 指针容量 — pointerLevel 支持 0-255
// ─────────────────────────────────────────────────────────────
// 通过循环 ref 到 255 级
$deep = Type::$int;
for ($i = 0; $i < 255; $i++) {
    $deep = $deep->ref();
}
check($deep->pointerLevel() === 255,   '测试9: ref 255 次 pointerLevel = 255');

// 超过 255 不溢出（clamp 到 255）
$clamped = $deep->ref();
check($clamped->pointerLevel() === 255, '测试9: ref 256 次 clamp 到 255');

// deref 回到 0
$back = $deep;
for ($i = 0; $i < 255; $i++) {
    $back = $back->deref();
}
check($back->pointerLevel() === 0,     '测试9: deref 255 次回到 0');
check($back->equals(Type::$int),       '测试9: 255 级 deref 255 次后 === int');

// 直接构造 pointerLevel=255
$direct255 = new Type(Type::IDX_INT, 0, 255);
check($direct255->pointerLevel() === 255, '测试9: 直接构造 pointerLevel=255');

// ─────────────────────────────────────────────────────────────
// 测试 10: __toString() 对多级指针的输出
// ─────────────────────────────────────────────────────────────
check((string)Type::$int === 'int',           '测试10: int → "int"');
check((string)$p1 === 'int*',                 '测试10: int* → "int*"');
check((string)$p2 === 'int**',                '测试10: int** → "int**"');
check((string)$p3 === 'int***',               '测试10: int*** → "int***"');
check((string)$deep === str_repeat('int', 1) . str_repeat('*', 255), '测试10: 255 级 → int + 255 个 *');

// option + 多级指针
$optPtr = Type::option($p2);
check((string)$optPtr === '?int**',           '测试10: ?int** → "?int**"');

// result + 指针（__toString 顺序：option → result → pointer，故 * 在末尾）
$resPtr = Type::result($p1);
check((string)$resPtr === 'int|Exception*',   '测试10: result(int*) → "int|Exception*"');

// 旧式 FLAG_POINTER 的 __toString
check((string)$legacyPtr === 'int*',          '测试10: 旧式 FLAG_POINTER → "int*"');

// array 元素类型 toString
$arrInt = Type::array(Type::$int);
check((string)$arrInt === 'array<int>',       '测试10: array<int> → "array<int>"');

// ─────────────────────────────────────────────────────────────
// 测试 11: ref/deref 保持 flags
// ─────────────────────────────────────────────────────────────
$optInt = Type::option(Type::$int);
$optPtr = $optInt->ref();
check($optPtr->isOption(),              '测试11: ref 后保持 isOption');
check($optPtr->pointerLevel() === 1,    '测试11: ref 后 pointerLevel = 1');
check($optPtr->deref()->equals($optInt), '测试11: deref 回到原 option(int)');

// ─────────────────────────────────────────────────────────────
// 测试 12: TypeTable::getCType 多级指针
// ─────────────────────────────────────────────────────────────
$tt2 = new TypeTable();
$intType = $tt2->lookup('int');
$ptr1 = $intType->ref();
$ptr3 = $intType->ref()->ref()->ref();

check($tt2->getCType($intType) === 'int64_t',  '测试12: int → int64_t');
check($tt2->getCType($ptr1) === 'int64_t*',    '测试12: int* → int64_t*');
check($tt2->getCType($ptr3) === 'int64_t***',  '测试12: int*** → int64_t***');

// ─────────────────────────────────────────────────────────────
// 汇总
// ─────────────────────────────────────────────────────────────
echo "\n";
echo "====================================\n";
echo "Type 位编码单元测试结果\n";
echo "====================================\n";
echo "通过: {$pass}\n";
echo "失败: {$fail}\n";
echo "====================================\n";

if ($fail > 0) {
    echo "\n失败用例：\n";
    foreach ($failures as $f) {
        echo "  - {$f}\n";
    }
    exit(1);
}

echo "\n[OK] 全部测试通过\n";
exit(0);
