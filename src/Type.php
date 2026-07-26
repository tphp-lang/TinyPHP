<?php

declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
// Type — 类型表示层
//   参考 vlang 的 Type = u32 设计：
//   - 低 24 位 (0 ~ 0xFFFFFF) 为类型索引 (idx)
//   - 高 8 位 (bits 24-31) 为标志位 (flags)
//   不可变值对象，通过静态工厂方法创建复合类型
// ═══════════════════════════════════════════════════════════════

class Type
{
    // ═══ 标志位常量（高 8 位，位掩码） ═══
    public const FLAG_NONE    = 0;
    public const FLAG_POINTER = 1 << 24;  // 指针类型
    public const FLAG_OPTION  = 1 << 25;  // ?T 可选类型
    public const FLAG_RESULT  = 1 << 26;  // T|Exception 结果类型
    public const FLAG_ARRAY   = 1 << 27;  // 数组类型（带元素类型）
    public const FLAG_MIXED   = 1 << 28;  // mixed 类型
    public const FLAG_C_LANG  = 1 << 29;  // C 语言类型（C.int, C.char* 等）

    // ═══ 预定义类型索引常量（低 24 位，0 ~ 255 为内置类型） ═══
    public const IDX_VOID      = 0;
    public const IDX_INT       = 1;
    public const IDX_FLOAT     = 2;
    public const IDX_STRING    = 3;
    public const IDX_BOOL      = 4;
    public const IDX_NULL      = 5;
    public const IDX_ARRAY     = 6;
    public const IDX_MIXED     = 7;
    public const IDX_OBJECT    = 8;   // 通用对象类型
    public const IDX_CALLBACK  = 9;   // t_callback
    public const IDX_RESOURCE  = 10;
    public const IDX_NEVER     = 11;  // never 类型（throw 表达式等）
    public const IDX_C_INT     = 12;  // C.int
    public const IDX_C_DOUBLE  = 13;  // C.double
    public const IDX_C_CHAR    = 14;  // C.char
    public const IDX_C_VOID    = 15;  // C.void
    public const IDX_C_VOIDPTR = 16;  // C.voidptr (void*)
    public const IDX_C_FILE    = 17;  // C.FILE

    // ═══ 预定义类型实例（静态属性，init() 中初始化） ═══
    public static Type $void;
    public static Type $int;
    public static Type $float;
    public static Type $string;
    public static Type $bool;
    public static Type $null;
    public static Type $array;     // 通用数组（无元素类型信息）
    public static Type $mixed;
    public static Type $object;
    public static Type $callback;
    public static Type $never;

    // ═══ 实例字段（不可变） ═══
    private int $idx;
    private int $flags;

    /** @var array<int,string> idx => name（内置类型名映射，用于 __toString） */
    private static array $builtinNames = [
        self::IDX_VOID      => 'void',
        self::IDX_INT       => 'int',
        self::IDX_FLOAT     => 'float',
        self::IDX_STRING    => 'string',
        self::IDX_BOOL      => 'bool',
        self::IDX_NULL      => 'null',
        self::IDX_ARRAY     => 'array',
        self::IDX_MIXED     => 'mixed',
        self::IDX_OBJECT    => 'object',
        self::IDX_CALLBACK  => 'callback',
        self::IDX_RESOURCE  => 'resource',
        self::IDX_NEVER     => 'never',
        self::IDX_C_INT     => 'C.int',
        self::IDX_C_DOUBLE  => 'C.double',
        self::IDX_C_CHAR    => 'C.char',
        self::IDX_C_VOID    => 'C.void',
        self::IDX_C_VOIDPTR => 'C.voidptr',
        self::IDX_C_FILE    => 'C.FILE',
    ];

    /** 复合数组类型 idx 计数器（从 0x10000 起，避开用户类型 256+ 区间） */
    private static int $nextArrayIdx = 0x10000;

    /** @var array<int,Type> idx => 元素类型（仅 FLAG_ARRAY 类型） */
    private static array $arrayElemMap = [];

    /** @var array<string,int> intern key => idx（相同元素类型复用同一 idx） */
    private static array $arrayIntern = [];

    public function __construct(int $idx, int $flags = 0)
    {
        $this->idx = $idx;
        $this->flags = $flags;
    }

    public function idx(): int
    {
        return $this->idx;
    }

    public function flags(): int
    {
        return $this->flags;
    }

    public function hasFlag(int $flag): bool
    {
        return ($this->flags & $flag) !== 0;
    }

    public function isPointer(): bool
    {
        return $this->hasFlag(self::FLAG_POINTER);
    }

    public function isOption(): bool
    {
        return $this->hasFlag(self::FLAG_OPTION);
    }

    public function isResult(): bool
    {
        return $this->hasFlag(self::FLAG_RESULT);
    }

    public function isMixed(): bool
    {
        return $this->hasFlag(self::FLAG_MIXED);
    }

    public function isCLang(): bool
    {
        return $this->hasFlag(self::FLAG_C_LANG);
    }

    public function isArray(): bool
    {
        return $this->idx === self::IDX_ARRAY || $this->hasFlag(self::FLAG_ARRAY);
    }

    public function isVoid(): bool
    {
        return $this->idx === self::IDX_VOID && $this->flags === 0;
    }

    public function isNever(): bool
    {
        return $this->idx === self::IDX_NEVER && $this->flags === 0;
    }

    public function isScalar(): bool
    {
        if ($this->flags !== 0) return false;
        return in_array($this->idx, [self::IDX_INT, self::IDX_FLOAT, self::IDX_BOOL, self::IDX_NULL], true);
    }

    public function equals(Type $other): bool
    {
        return $this->idx === $other->idx && $this->flags === $other->flags;
    }

    // ──────────────────────────────────────────────────────────
    // 静态工厂方法
    // ──────────────────────────────────────────────────────────

    public static function pointer(Type $base): Type
    {
        return new self($base->idx, $base->flags | self::FLAG_POINTER);
    }

    public static function option(Type $base): Type
    {
        return new self($base->idx, $base->flags | self::FLAG_OPTION);
    }

    public static function result(Type $base): Type
    {
        return new self($base->idx, $base->flags | self::FLAG_RESULT);
    }

    public static function array(?Type $elem = null): Type
    {
        if ($elem === null) {
            return self::$array;
        }
        // intern: 相同元素类型复用同一 idx，保证 array<int> === array<int>
        $key = $elem->idx() . ':' . $elem->flags();
        if (isset(self::$arrayIntern[$key])) {
            $idx = self::$arrayIntern[$key];
        } else {
            $idx = self::$nextArrayIdx++;
            self::$arrayElemMap[$idx] = $elem;
            self::$arrayIntern[$key] = $idx;
        }
        return new self($idx, self::FLAG_ARRAY);
    }

    /**
     * 返回 array<T> 对应的 C 类型名（不含指针 *）。
     * - array<int>    → t_arr_int
     * - array<string> → t_arr_str
     * - array<float>  → t_arr_float
     * - array<bool>   → t_arr_bool
     * - array<mixed>  → t_arr_var (= t_array)
     * - array<array<T>> / array<Foo> → t_arr_ptr (元素为 void*)
     * - 通用 array（无元素类型）→ t_arr_var
     */
    public static function arrayCType(?Type $elem): string
    {
        if ($elem === null) {
            return 't_arr_var';
        }
        if ($elem->isMixed()) {
            return 't_arr_var';
        }
        if ($elem->flags() !== 0) {
            // 带修饰符（option/result/pointer）的元素用 ptr 数组承载
            return 't_arr_ptr';
        }
        return match ($elem->idx()) {
            self::IDX_INT    => 't_arr_int',
            self::IDX_STRING => 't_arr_str',
            self::IDX_FLOAT  => 't_arr_float',
            self::IDX_BOOL   => 't_arr_bool',
            default          => 't_arr_ptr', // 嵌套数组、对象数组等
        };
    }

    /**
     * Internal: 为指定 idx 注册数组元素类型。
     * 由 TypeTable::register() 在注册带元素类型的命名数组时调用。
     */
    public static function setArrayElem(int $idx, Type $elem): void
    {
        self::$arrayElemMap[$idx] = $elem;
    }

    public function elemType(): ?Type
    {
        if (!$this->hasFlag(self::FLAG_ARRAY)) {
            return null;
        }
        return self::$arrayElemMap[$this->idx] ?? null;
    }

    public static function mixed(): Type
    {
        return self::$mixed;
    }

    // ──────────────────────────────────────────────────────────
    // 初始化
    // ──────────────────────────────────────────────────────────

    public static function init(): void
    {
        if (isset(self::$int)) return;

        self::$void     = new self(self::IDX_VOID);
        self::$int      = new self(self::IDX_INT);
        self::$float    = new self(self::IDX_FLOAT);
        self::$string   = new self(self::IDX_STRING);
        self::$bool     = new self(self::IDX_BOOL);
        self::$null     = new self(self::IDX_NULL);
        self::$array    = new self(self::IDX_ARRAY);
        self::$mixed    = new self(self::IDX_MIXED, self::FLAG_MIXED);
        self::$object   = new self(self::IDX_OBJECT);
        self::$callback = new self(self::IDX_CALLBACK);
        self::$never    = new self(self::IDX_NEVER);
    }

    // ──────────────────────────────────────────────────────────
    // 调试
    // ──────────────────────────────────────────────────────────

    public function __toString(): string
    {
        // 基础名称
        if ($this->hasFlag(self::FLAG_ARRAY)) {
            $elem = $this->elemType();
            $base = $elem !== null ? "array<{$elem}>" : 'array';
        } elseif ($this->isMixed()) {
            $base = 'mixed';
        } else {
            $base = self::$builtinNames[$this->idx] ?? "type#{$this->idx}";
        }

        // 修饰符
        if ($this->hasFlag(self::FLAG_OPTION)) {
            $base = "?{$base}";
        }
        if ($this->hasFlag(self::FLAG_RESULT)) {
            $base = "{$base}|Exception";
        }
        if ($this->hasFlag(self::FLAG_POINTER)) {
            $base = "{$base}*";
        }

        return $base;
    }
}

// ═══════════════════════════════════════════════════════════════
// TypeSymbol — 类型符号
//   存储类型的元信息（名称、C 类型、是否 C 语言类型、元素类型）
// ═══════════════════════════════════════════════════════════════

class TypeSymbol
{
    public function __construct(
        public readonly string $name,          // 类型名（如 'int', 'string', 'Foo', 'C.FILE'）
        public readonly int $idx,              // 类型索引
        public readonly string $cType,         // 对应的 C 类型（如 'int64_t', 't_string', 't_array*'）
        public readonly bool $isCLang = false, // 是否为 C 语言类型
        public readonly ?Type $elemType = null, // 数组元素类型（仅数组类型）
    ) {}
}

// ═══════════════════════════════════════════════════════════════
// TypeTable — 类型表
//   管理所有已注册的类型符号，提供注册和查询功能
// ═══════════════════════════════════════════════════════════════

class TypeTable
{
    /** @var array<int,TypeSymbol> idx => TypeSymbol */
    private array $symbols = [];

    /** @var array<string,int> name => idx */
    private array $nameMap = [];

    /** 用户类型从 256 开始 */
    private int $nextIdx = 256;

    /** @var array<string,int> 内置类型名 => idx */
    private static array $builtinNameToIdx = [
        'void'      => Type::IDX_VOID,
        'int'       => Type::IDX_INT,
        'float'     => Type::IDX_FLOAT,
        'string'    => Type::IDX_STRING,
        'bool'      => Type::IDX_BOOL,
        'null'      => Type::IDX_NULL,
        'array'     => Type::IDX_ARRAY,
        'mixed'     => Type::IDX_MIXED,
        'object'    => Type::IDX_OBJECT,
        'callback'  => Type::IDX_CALLBACK,
        'never'     => Type::IDX_NEVER,
        'C.int'     => Type::IDX_C_INT,
        'C.double'  => Type::IDX_C_DOUBLE,
        'C.char'    => Type::IDX_C_CHAR,
        'C.void'    => Type::IDX_C_VOID,
        'C.voidptr' => Type::IDX_C_VOIDPTR,
        'C.FILE'    => Type::IDX_C_FILE,
    ];

    public function __construct()
    {
        Type::init();
        $this->registerBuiltinTypes();
    }

    private function registerBuiltinTypes(): void
    {
        // 基础类型
        $this->register('void', 'void');
        $this->register('int', 'int64_t');
        $this->register('float', 'double');
        $this->register('string', 't_string');
        $this->register('bool', 'bool');
        $this->register('null', 'void*');
        $this->register('array', 't_array*');
        // Task 2.13: mixed 类型在运行时映射为 t_var（万能动态类型容器），
        //   允许赋值任意类型的值，对应 PHP 的动态类型语义
        $this->register('mixed', 't_var');
        $this->register('object', 'void*');
        $this->register('callback', 't_callback*');
        $this->register('never', 'void');

        // C 语言类型
        // Task 2.13: C.voidptr 作为 mixed 的 C 层逃生舱类型（void*），
        //   用于需要绕过类型系统直接操作原始指针的场景
        $this->register('C.int', 'int', true);
        $this->register('C.double', 'double', true);
        $this->register('C.char', 'char', true);
        $this->register('C.void', 'void', true);
        $this->register('C.voidptr', 'void*', true);
        $this->register('C.FILE', 'FILE', true);
    }

    public function register(string $name, string $cType, bool $isCLang = false, ?Type $elemType = null): Type
    {
        if (isset(self::$builtinNameToIdx[$name])) {
            $idx = self::$builtinNameToIdx[$name];
        } else {
            $idx = $this->nextIdx++;
        }

        $flags = 0;
        if ($isCLang) {
            $flags |= Type::FLAG_C_LANG;
        }
        if ($idx === Type::IDX_MIXED) {
            $flags |= Type::FLAG_MIXED;
        }
        if ($elemType !== null) {
            $flags |= Type::FLAG_ARRAY;
            Type::setArrayElem($idx, $elemType);
        }

        $sym = new TypeSymbol($name, $idx, $cType, $isCLang, $elemType);
        $this->symbols[$idx] = $sym;
        $this->nameMap[$name] = $idx;

        return new Type($idx, $flags);
    }

    public function lookup(string $name): ?Type
    {
        $idx = $this->nameMap[$name] ?? null;
        if ($idx === null) {
            return null;
        }
        $sym = $this->symbols[$idx];

        $flags = 0;
        if ($sym->isCLang) {
            $flags |= Type::FLAG_C_LANG;
        }
        if ($idx === Type::IDX_MIXED) {
            $flags |= Type::FLAG_MIXED;
        }
        if ($sym->elemType !== null) {
            $flags |= Type::FLAG_ARRAY;
        }

        return new Type($idx, $flags);
    }

    public function lookupSymbol(string $name): ?TypeSymbol
    {
        $idx = $this->nameMap[$name] ?? null;
        if ($idx === null) {
            return null;
        }
        return $this->symbols[$idx];
    }

    public function getByIdx(int $idx): ?TypeSymbol
    {
        return $this->symbols[$idx] ?? null;
    }

    public function getCType(Type $type): string
    {
        // mixed 类型
        if ($type->isMixed()) {
            return 't_var';
        }

        // 数组类型：array<T> → t_arr_T*，通用 array → t_arr_var* (= t_array*)
        if ($type->isArray()) {
            $elem = $type->elemType();
            return Type::arrayCType($elem) . '*';
        }

        // 查找类型符号
        $sym = $this->getByIdx($type->idx());
        if ($sym === null) {
            return 'void';
        }

        $cType = $sym->cType;

        // 指针类型：在 C 类型后加 '*'
        if ($type->isPointer()) {
            $cType .= '*';
        }

        // Option 和 Result 不改变 C 类型（运行时处理）
        return $cType;
    }
}
