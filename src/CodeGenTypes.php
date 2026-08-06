<?php

declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
// CodeGenTypes — C 类型字符串常量与映射（消除 700+ 硬编码）
//
// 集中定义所有 TPHP C 类型名，供 CodeGenerator 和 CodeGenDataTrait
// 通过 trait 混入使用。
// ═══════════════════════════════════════════════════════════════

trait CodeGenTypes
{
    // ═══ 基础 C 类型常量 ═══
    public const CT_INT      = 't_int';
    public const CT_FLOAT    = 't_float';
    public const CT_STRING   = 't_string';
    public const CT_BOOL     = 't_bool';
    public const CT_VAR      = 't_var';       // mixed / 万能类型
    public const CT_ARRAY    = 't_array*';    // 通用数组
    public const CT_CALLBACK = 't_callback';
    public const CT_VOID     = 'void';
    public const CT_VOID_PTR = 'void*';
    public const CT_NULL_PTR = 'null';

    /** 基础类型集合（标量 + 基础复合类型） */
    protected static array $CT_SIMPLE_TYPES;
    /** 所有可自动推导类型 */
    protected static array $CT_TPHP_TYPES;

    /**
     * 初始化静态数组（PHP 不支持 trait 中的静态属性初始化器）
     * 在 CodeGenerator 构造或首次访问时调用。
     */
    protected static function initCodeGenTypes(): void
    {
        if (isset(self::$CT_SIMPLE_TYPES)) return;
        self::$CT_SIMPLE_TYPES = [
            self::CT_INT, self::CT_FLOAT, self::CT_STRING, self::CT_BOOL,
        ];
        self::$CT_TPHP_TYPES = [
            self::CT_INT, self::CT_FLOAT, self::CT_STRING, self::CT_BOOL,
            self::CT_ARRAY, self::CT_VAR, self::CT_CALLBACK,
        ];
    }

    // ═══ 统一点：PHP 类型名 → C 类型 ═══
    // 消除 mapType/resolveType/getBuiltinFnSig/cToTpType 的重复

    /**
     * PHP 基本类型名 → C 类型字符串（$typeMap 的增强替代）
     */
    public static function phpToCT(string $phpType): ?string
    {
        return match ($phpType) {
            'int'    => self::CT_INT,
            'float'  => self::CT_FLOAT,
            'string' => self::CT_STRING,
            'bool'   => self::CT_BOOL,
            'void'   => self::CT_VOID,
            'never'  => self::CT_VOID,
            'array'  => self::CT_ARRAY,
            'mixed'  => self::CT_VAR,
            'callable' => self::CT_CALLBACK,
            'null'   => self::CT_VOID_PTR,
            'resource' => self::CT_VOID_PTR,
            default  => null,
        };
    }

    /**
     * Type IDX → C 类型字符串
     * 集中 inferredTypeToCType 的 match 块
     */
    public static function idxToCT(int $idx): ?string
    {
        return match ($idx) {
            Type::IDX_VOID      => self::CT_VOID,
            Type::IDX_INT       => self::CT_INT,
            Type::IDX_FLOAT     => self::CT_FLOAT,
            Type::IDX_STRING    => self::CT_STRING,
            Type::IDX_BOOL      => self::CT_BOOL,
            Type::IDX_NULL      => self::CT_NULL_PTR,
            Type::IDX_ARRAY     => self::CT_ARRAY,
            Type::IDX_OBJECT    => self::CT_VOID_PTR,
            Type::IDX_CALLBACK  => self::CT_CALLBACK,
            Type::IDX_NEVER     => self::CT_VOID,
            Type::IDX_MIXED     => self::CT_VAR,
            default             => null,
        };
    }

    /**
     * C 类型名 → Type IDX 常量（反向映射）
     * 消除 cToTpType / genericArrayElemCType 等分散映射
     */
    public static function cToIdx(string $cType): int
    {
        return match ($cType) {
            self::CT_INT    => Type::IDX_INT,
            self::CT_FLOAT  => Type::IDX_FLOAT,
            self::CT_STRING => Type::IDX_STRING,
            self::CT_BOOL   => Type::IDX_BOOL,
            self::CT_VOID   => Type::IDX_VOID,
            default         => Type::IDX_MIXED,
        };
    }

    // ═══ 辅助方法 ═══

    /** 是否为基本标量类型 */
    public static function isSimpleType(string $ct): bool
    {
        self::initCodeGenTypes();
        return in_array($ct, self::$CT_SIMPLE_TYPES, true);
    }

    /** 是否为 TPHP 可推导类型 */
    public static function isTphpType(string $ct): bool
    {
        self::initCodeGenTypes();
        return in_array($ct, self::$CT_TPHP_TYPES, true);
    }

    /** 从泛型数组 C 类型提取元素类型：t_arr_int* → t_int */
    public static function arrElemCType(string $arrCType): ?string
    {
        return match ($arrCType) {
            't_arr_int*'   => self::CT_INT,
            't_arr_str*'   => self::CT_STRING,
            't_arr_float*' => self::CT_FLOAT,
            't_arr_bool*'  => self::CT_BOOL,
            't_arr_var*'   => self::CT_VAR,
            't_arr_ptr*'   => null, // 对象数组无统一元素类型
            default        => null,
        };
    }
}
