<?php

declare(strict_types=1);

// ============================================================
// NameResolver — C 符号名解析器（共享于 CodeGenerator 和 TypeChecker）
//
// 消除 classCName / funcCName / funcCNameFromCall / mangleCName
// 在 CodeGenerator 和 TypeChecker 中的重复实现。
// ============================================================

class NameResolver
{
    /** 命名空间或标识符 → C 兼容名（\ → _） */
    public static function mangleCName(string $name): string
    {
        return str_replace('\\', '_', $name);
    }

    /** 类名 → C struct 名
     *  @param AST\ClassNode $class */
    public static function classCName($class): string
    {
        if ($class->namespace === '') {
            return 'tphp_class_' . $class->name;
        }
        return 'tphp_na_' . self::mangleCName($class->namespace) . '_tphp_class_' . $class->name;
    }

    /** 函数名 → C 函数名
     *  @param AST\FunctionNode $fn */
    public static function funcCName($fn): string
    {
        if ($fn->namespace === '') {
            return 'tphp_fn_' . $fn->name;
        }
        return 'tphp_na_' . self::mangleCName($fn->namespace) . '_tphp_fn_' . $fn->name;
    }

    /** 从 CallExpr 推导 C 函数名
     *  @param AST\CallExpr $expr */
    public static function funcCNameFromCall($expr): string
    {
        if ($expr->callee !== null) {
            return '';
        }
        $pos = strrpos($expr->name, '\\');
        if ($pos !== false) {
            $ns = substr($expr->name, 0, $pos);
            $fn = substr($expr->name, $pos + 1);
            return 'tphp_na_' . self::mangleCName($ns) . '_tphp_fn_' . $fn;
        }
        return 'tphp_fn_' . $expr->name;
    }
}
