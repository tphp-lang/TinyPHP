<?php

declare(strict_types=1);

// ============================================================
// CodeGenVarWrapTrait — 变量包装与表达式包裹方法
// 从 CodeGenerator.php 提取
// ============================================================
trait CodeGenVarWrapTrait {

    private function arrGetterForType(string $type): string {
        return match($type) {
            't_int'      => 'tphp_fn_arr_item_int',
            't_float'    => 'tphp_fn_arr_item_float',
            't_string'   => 'tphp_fn_arr_item_str',
            't_bool'     => 'tphp_fn_arr_item_bool',
            't_array*'   => 'tphp_fn_arr_item_array',
            't_callback' => 'tphp_fn_arr_item_callback',
            default      => 'tphp_fn_arr_item_object',
        };
    }

    /** 类型 → VAR_ 包装宏 */

    private function arrVarWrapForType(string $type): string {
        return match($type) {
            't_int'      => 'VAR_INT',
            't_float'    => 'VAR_FLOAT',
            't_string'   => 'VAR_STRING',
            't_bool'     => 'VAR_BOOL',
            't_array*'   => 'VAR_ARRAY',
            't_callback' => 'VAR_CALLBACK',
            default      => 'VAR_OBJ',
        };
    }


    private function generateIsCheck(string $fnName, string $argCode, string $argType): string
    {
        $checkType = substr($fnName, 3); // is_int → int, is_float → float, ...

        // t_var (mixed/union) 类型统一走运行时函数
        if ($argType === 't_var') {
            return "tphp_fn_{$fnName}({$argCode})";
        }

        // null 检测：静态 null → true，其他静态类型 → false
        if ($checkType === 'null') {
            return ($argType === 'null') ? 'true' : 'false';
        }

        // is_object: 类名类型 → true，基本类型 → false
        if ($checkType === 'object') {
            $primitives = ['t_int', 't_float', 't_string', 't_bool', 't_array*', 't_callback', 'null', 'void*'];
            return in_array($argType, $primitives, true) ? 'false' : 'true';
        }

        // is_resource: Resource/File 等类型 → true，其他 → false
        if ($checkType === 'resource') {
            // t_var (mixed/union) 已在上方处理
            // 静态类型：以 tphp_class_ 开头且继承自 Resource → true
            if (self::isClassCType($argType)) {
                return 'true';
            }
            return 'false';
        }

        // 其他 is_*：精确匹配
        $typeMap = [
            'int'      => 't_int',
            'float'    => 't_float',
            'string'   => 't_string',
            'bool'     => 't_bool',
            'array'    => 't_array*',
            'callable' => 't_callback',
        ];
        $expectedType = $typeMap[$checkType] ?? '';

        if ($expectedType !== '' && $argType === $expectedType) return 'true';

        return 'false';
    }


    private function wrapVar(ExprNode $expr): string
    {
        if ($expr instanceof StringLiteralExpr) {
            return 'VAR_STRING(' . $expr->accept($this) . ')';
        }
        if ($expr instanceof IntLiteralExpr) {
            return 'VAR_INT(' . $expr->accept($this) . ')';
        }
        if ($expr instanceof FloatLiteralExpr) {
            return 'VAR_FLOAT(' . $expr->accept($this) . ')';
        }
        if ($expr instanceof BoolLiteralExpr) {
            return 'VAR_BOOL(' . $expr->accept($this) . ')';
        }
        if ($expr instanceof NullLiteralExpr) {
            return 'VAR_NULL()';
        }
        if ($expr instanceof PropertyAccessExpr) {
            $code = $expr->accept($this);
            // ── 注解 entry 属性访问: ROUTE[0]->data/type/name ──
            // _annot_ROUTE_0->data → VAR_ARRAY, ->type/->name → VAR_STRING
            if ($expr->object instanceof ArrayAccessExpr
                && $expr->object->array instanceof VariableExpr
                && !str_starts_with($expr->object->array->name, '$')
                && $expr->object->index instanceof IntLiteralExpr
                && isset($this->annotationRegistry[$expr->object->array->name])) {
                $prop = ltrim($expr->property, '$');
                return match ($prop) {
                    'data'  => "VAR_ARRAY({$code})",
                    'type'  => "VAR_STRING({$code})",
                    'name'  => "VAR_STRING({$code})",
                    default => "VAR_INT({$code})",
                };
            }
            // 类常量访问 → 查 SymbolTable
            if (str_starts_with($code, 'TPHP_CONST_')) {
                $ct = $this->symbols->getConstType($code) ?? $this->symbols->getConstType(strtoupper(substr($code, 12))) ?? 't_int';
                return match ($ct) {
                    't_string' => "VAR_STRING({$code})",
                    't_float'  => "VAR_FLOAT({$code})",
                    't_bool'   => "VAR_BOOL({$code})",
                    default    => "VAR_INT({$code})",
                };
            }
            // 用 getPropType 查类型（含 enum 属性）
            $propType = $this->getPropType($expr);
            if ($propType === '') $propType = 't_int';
            // t_array* → VAR_ARRAY（必须在通用指针分支前处理）
            if ($propType === 't_array*') {
                return "VAR_ARRAY({$code})";
            }
            // Object type → VAR_OBJ
            if (str_contains($propType, '_class_') || str_ends_with($propType, '*')) {
                return "VAR_OBJ({$code})";
            }
            return match ($propType) {
                't_int'      => "VAR_INT({$code})",
                't_float'    => "VAR_FLOAT({$code})",
                't_string'   => "VAR_STRING({$code})",
                't_bool'     => "VAR_BOOL({$code})",
                't_array*'   => "VAR_ARRAY({$code})",
                't_callback' => "VAR_CALLBACK({$code})",
                default      => "VAR_INT({$code})",
            };
        }
        if ($expr instanceof EnumAccessExpr) {
            $code = $expr->accept($this);
            // 枚举常量访问（非 case）→ 按声明类型包装
            if (!$this->symbols->hasEnumCase($expr->enumName, $expr->caseName)) {
                $ct = $this->symbols->getEnumConstType($expr->enumName, $expr->caseName) ?? 't_string';
                return match ($ct) {
                    't_int'    => "VAR_INT({$code})",
                    't_float'  => "VAR_FLOAT({$code})",
                    't_bool'   => "VAR_BOOL({$code})",
                    default    => "VAR_STRING({$code})",
                };
            }
            // case 访问 → 用 ->value 取值
            $bt = $this->enumBackingType($expr->enumName);
            return ($bt === 'string') ? "VAR_STRING(({$code})->value)" : "VAR_INT(({$code})->value)";
        }
        if ($expr instanceof VariableExpr) {
            // 常量引用（原始名字不以 $ 开头）—— 根据类型选择 VAR_*
            if (!str_starts_with($expr->name, '$')) {
                $cname = 'TPHP_CONST_' . strtoupper($expr->name);
                $ct = $this->symbols->getConstType($expr->name) ?? 't_string';
                return match ($ct) {
                    't_int'    => "VAR_INT({$cname})",
                    't_float'  => "VAR_FLOAT({$cname})",
                    't_bool'   => "VAR_BOOL({$cname})",
                    't_array*' => "VAR_ARRAY({$cname})",
                    default    => "VAR_STRING({$cname})",
                };
            }
            $vn = self::varName($expr->name);
            $vt = $this->varTypes[$vn] ?? 't_int';
            // byRef 变量：解引用到值类型
            if ($this->isByRefType($vt)) {
                $vn = '(*' . $vn . ')';
                $vt = substr($vt, 0, -1);
            }
            return match (true) {
                $vt === 't_int'      => "VAR_INT({$vn})",
                $vt === 't_float'    => "VAR_FLOAT({$vn})",
                $vt === 't_string'   => "VAR_STRING({$vn})",
                $vt === 't_bool'     => "VAR_BOOL({$vn})",
                $vt === 't_array*'   => "VAR_ARRAY({$vn})",
                $vt === 't_callback' => "VAR_CALLBACK({$vn})",
                $vt === 't_var'      => $vn,
                $vt === 'null'       => "VAR_NULL()",
                str_contains($vt, 'tphp_class_') || str_contains($vt, 'tphp_enum_') => "VAR_OBJ({$vn})",
                default               => "VAR_NULL()",
            };
        }
        if ($expr instanceof ArrayLiteralExpr) {
            // GNU 复合表达式: VAR_ARRAY(({ t_array* _a = ...; ...; _a; }))
            $tmpName = "_vd_arr_" . (++$this->tmpVarCounter);
            $arrCode = $this->genArrayLiteralInline($expr, $tmpName);
            return "VAR_ARRAY(({ {$arrCode} {$tmpName}; }))";
        }
        if ($expr instanceof BinaryExpr && $expr->operator === '.') {
            return 'VAR_STRING(' . $expr->accept($this) . ')';
        }
        if ($expr instanceof NewExpr) {
            $code = $expr->accept($this);
            return "VAR_OBJ({$code})";
        }
        if ($expr instanceof BinaryExpr) {
            $code = $expr->accept($this);
            $type = $this->inferType($expr);
            return match ($type) {
                't_string' => "VAR_STRING({$code})",
                't_float'  => "VAR_FLOAT({$code})",
                't_bool'   => "VAR_BOOL({$code})",
                default    => "VAR_INT({$code})",
            };
        }
        if ($expr instanceof UnaryExpr) {
            $code = $expr->accept($this);
            $type = $this->inferType($expr);
            return match ($type) {
                't_string' => "VAR_STRING({$code})",
                't_float'  => "VAR_FLOAT({$code})",
                't_bool'   => "VAR_BOOL({$code})",
                default    => "VAR_INT({$code})",
            };
        }
        if ($expr instanceof TernaryExpr) {
            $code = $expr->accept($this);
            $type = $this->inferType($expr);
            return match ($type) {
                't_string' => "VAR_STRING({$code})",
                't_float'  => "VAR_FLOAT({$code})",
                't_bool'   => "VAR_BOOL({$code})",
                default    => "VAR_INT({$code})",
            };
        }
        if ($expr instanceof CastExpr) {
            $code = $expr->accept($this);
            return match ($expr->castType) {
                'bool'   => "VAR_BOOL({$code})",
                'string' => "VAR_STRING({$code})",
                'int'    => "VAR_INT({$code})",
                'float'  => "VAR_FLOAT({$code})",
                default  => "VAR_INT({$code})",
            };
        }
        if ($expr instanceof CallExpr) {
            $code = $expr->accept($this);
            // 使用 inferType 推导返回类型（涵盖 is_*, count, date 等内置函数）
            $retType = $this->inferType($expr);
            if ($retType === 't_int' && $expr->callee === null) {
                // inferType 回退到 t_int，手动补查内置函数
                if ($expr->name === 'date') $retType = 't_string';
                elseif (str_starts_with($expr->name, 'is_') || str_starts_with($expr->name, 'ctype_')) $retType = 't_bool';
            }
            return match ($retType) {
                't_string'   => "VAR_STRING({$code})",
                't_float'    => "VAR_FLOAT({$code})",
                't_bool'     => "VAR_BOOL({$code})",
                't_array*'   => "VAR_ARRAY({$code})",
                't_callback' => "VAR_CALLBACK({$code})",
                't_var'      => $code,
                'null'       => "VAR_NULL()",
                default      => (str_contains($retType, 'tphp_class_') || str_contains($retType, 'tphp_enum_'))
                    ? "VAR_OBJ({$code})"
                    : "VAR_INT({$code})",
            };
        }
        if ($expr instanceof ArrayAccessExpr) {
            // array<mixed> 元素已经是 t_var，visitArrayAccess 返回 t_var 值，无需 VAR_* 包装
            if ($expr->array instanceof VariableExpr) {
                $vn = self::varName($expr->array->name);
                if (($this->arrElementTypes[$vn] ?? null) === 't_var') {
                    return $expr->accept($this);
                }
                // t_var 变量持有数组（$sub = $arr[0]; $sub[1]）：
                //   visitArrayAccess 走 t_var 路径，返回 *tphp_fn_arr_index(...) 即 t_var 值，无需 VAR_* 包装
                if (($this->varTypes[$vn] ?? '') === 't_var') {
                    return $expr->accept($this);
                }
            }
            // 链式访问 $arr[0][1]：若内层为 t_var，外层也是 t_var，无需 VAR_* 包装
            if ($expr->array instanceof ArrayAccessExpr) {
                $innerType = $this->inferArrayAccessInnerType($expr->array);
                if ($innerType === 't_var') {
                    return $expr->accept($this);
                }
            }
            $code = $expr->accept($this);
            // 字符串键：优先 AST 嵌套追踪；否则 per-key 追踪；默认 VAR_STRING
            // （保持兼容性：未追踪的字符串键默认视为 string，避免误判为 int 返回 0）
            if ($this->hasStrKey($expr)) {
                // 嵌套访问优先用 AST 精确追踪（处理 $m["items"][0]["id"] 混合类型）
                if ($expr->array instanceof ArrayAccessExpr) {
                    $traced = $this->traceNestedAccessType($expr);
                    if ($traced === 't_int' || $traced === 't_bool') return "VAR_INT({$code})";
                    if ($traced === 't_float')    return "VAR_FLOAT({$code})";
                    if ($traced === 't_array*')   return "VAR_ARRAY({$code})";
                    if ($traced === 't_callback') return "VAR_CALLBACK({$code})";
                    if ($traced === 'null')       return "VAR_NULL()";
                    if ($traced !== null && (str_contains($traced, 'tphp_class_') || str_contains($traced, 'tphp_enum_')))
                        return "VAR_OBJ({$code})";
                    if ($traced === 't_string')   return "VAR_STRING({$code})";
                }
                // 非嵌套或追踪失败：per-key 类型追踪
                if ($expr->index instanceof StringLiteralExpr && $expr->array instanceof VariableExpr) {
                    $at = self::varName($expr->array->name);
                    $kt = $this->arrValueTypes[$at][$expr->index->value] ?? '';
                    if ($kt === 't_int' || $kt === 't_bool')   return "VAR_INT({$code})";
                    if ($kt === 't_float')    return "VAR_FLOAT({$code})";
                    if ($kt === 't_array*')   return "VAR_ARRAY({$code})";
                    if ($kt === 't_callback') return "VAR_CALLBACK({$code})";
                    if ($kt === 'null')       return "VAR_NULL()";
                    if ($kt && (str_contains($kt, 'tphp_class_') || str_contains($kt, 'tphp_enum_')))
                        return "VAR_OBJ({$code})";
                }
                // arrElementTypes 追踪（动态字符串键 $a[$key]）：visitArrayAccess 生成 typed getter
                //   如 $dict[$key] = $freeEnt（int）→ arrElementTypes['dict']='t_int'
                //   visitArrayAccess 生成 tphp_fn_arr_get_str_int 返回 t_int，需 VAR_INT 包装
                //   仅对动态键生效：字面量键已有 arrValueTypes 精确追踪，arrElementTypes 可能与之冲突
                if (!($expr->index instanceof StringLiteralExpr) && $expr->array instanceof VariableExpr) {
                    $at = self::varName($expr->array->name);
                    $et = $this->arrElementTypes[$at] ?? '';
                    if ($et === 't_int' || $et === 't_bool')   return "VAR_INT({$code})";
                    if ($et === 't_float')    return "VAR_FLOAT({$code})";
                    if ($et === 't_array*')   return "VAR_ARRAY({$code})";
                    if ($et === 't_callback') return "VAR_CALLBACK({$code})";
                    if ($et === 'null')       return "VAR_NULL()";
                    if ($et && (str_contains($et, 'tphp_class_') || str_contains($et, 'tphp_enum_')))
                        return "VAR_OBJ({$code})";
                    if ($et === 't_string')   return "VAR_STRING({$code})";
                }
                return "VAR_STRING({$code})";
            }
            // int 键：优先用 inferArrayAccessActualType（与 visitArrayAccess 的 et 推导一致），
            //   回退到 inferType（含 TypeChecker inferredType）
            //   场景：$keys = array_keys($arr); $keys[0] — arrElementTypes='t_int'，
            //         visitArrayAccess 生成 typed getter 返回 t_int，需 VAR_INT 包装
            $type = $this->inferArrayAccessActualType($expr) ?? $this->inferType($expr);
            return match ($type) {
                't_string'   => "VAR_STRING({$code})",
                't_float'    => "VAR_FLOAT({$code})",
                't_bool'     => "VAR_BOOL({$code})",
                't_array*'   => "VAR_ARRAY({$code})",
                't_callback' => "VAR_CALLBACK({$code})",
                't_var'      => $code,  // array<mixed> 元素已是 t_var，无需包装
                'null'       => "VAR_NULL()",
                default      => (str_contains($type, 'tphp_class_') || str_contains($type, 'tphp_enum_'))
                    ? "VAR_OBJ({$code})"
                    : "VAR_INT({$code})",
            };
        }
        // 默认：使用 inferType 动态判断表达式类型
        $code = $expr->accept($this);
        $type = $this->inferType($expr);
        return match ($type) {
            't_string'   => "VAR_STRING({$code})",
            't_float'    => "VAR_FLOAT({$code})",
            't_bool'     => "VAR_BOOL({$code})",
            't_array*'   => "VAR_ARRAY({$code})",
            't_callback' => "VAR_CALLBACK({$code})",
            't_var'      => $code,  // 已是 t_var，无需包装
            'null'       => "VAR_NULL()",
            default      => (str_contains($type, 'tphp_class_') || str_contains($type, 'tphp_enum_'))
                ? "VAR_OBJ({$code})"
                : "VAR_INT({$code})",
        };
    }

    private function wrapNullsafeAccess(string $obj, string $access, string $retType): string
    {
        $tmp = '_nsr_' . (++$this->tmpVarCounter);
        $zero = match ($retType) {
            't_float' => '0.0',
            't_string' => '(t_string){NULL,0}',
            't_bool' => 'false',
            default => str_ends_with($retType, '*') ? 'NULL' : '0',
        };
        return "({ {$retType} {$tmp} = {$zero}; if ((void*){$obj} != NULL) {{ {$tmp} = {$access}; }} {$tmp}; })";
    }

    private function wrapVarExpr(string $code, ?ExprNode $expr): string
    {
        if ($expr === null) return "VAR_INT({$code})";
        $type = $this->inferType($expr);
        return match ($type) {
            't_int'    => "VAR_INT({$code})",
            't_float'  => "VAR_FLOAT({$code})",
            't_bool'   => "VAR_BOOL({$code})",
            't_string' => "VAR_STRING({$code})",
            default    => "VAR_INT({$code})",
        };
    }

    /** 检查属性使用名是否匹配注解常量
     *  规则（与普通常量作用域一致）:
     *    - FQ 名（含 \ 或经 use const 导入）→ 精确匹配 FQ 名
     *    - 短名 → 匹配短名（同命名空间常量 + 全局常量回退） */

    private function wrapArrVal(ExprNode $expr, string $code): string
    {
        $et = $this->inferType($expr);
        return match ($et) {
            't_int'    => "VAR_INT({$code})",
            't_float'  => "VAR_FLOAT({$code})",
            't_bool'   => "VAR_BOOL({$code})",
            't_string' => "VAR_STRING({$code})",
            't_array*' => "VAR_ARRAY({$code})",
            default    => "VAR_INT({$code})",
        };
    }

    // ============================================================

    private function wrapTvarAssign(ExprNode $expr, string $code): string
    {
        if ($expr instanceof StringLiteralExpr)  return "VAR_STRING({$code})";
        if ($expr instanceof IntLiteralExpr)     return "VAR_INT({$code})";
        if ($expr instanceof FloatLiteralExpr)   return "VAR_FLOAT({$code})";
        if ($expr instanceof BoolLiteralExpr)    return "VAR_BOOL({$code})";
        if ($expr instanceof NullLiteralExpr)    return "VAR_NULL()";
        if ($expr instanceof ArrayLiteralExpr)   return "VAR_ARRAY({$code})";
        if ($expr instanceof ClosureExpr)        return "VAR_CALLBACK({$code})";
        if ($expr instanceof NewExpr)            return "VAR_OBJ({$code})";
        if ($expr instanceof VariableExpr) {
            $vn = self::varName($expr->name);
            // 常量引用（不以 $ 开头）→ 加 TPHP_CONST_ 前缀
            $isConst = !str_starts_with($expr->name, '$');
            $ref = $isConst ? ('TPHP_CONST_' . strtoupper($vn)) : $vn;
            $vt = $this->varTypes[$vn] ?? 't_int';
            // t_var→t_var 直接赋值，无需包裹
            if ($vt === 't_var') return $code;
            return match ($vt) {
                't_int'      => "VAR_INT({$ref})",
                't_float'    => "VAR_FLOAT({$ref})",
                't_string'   => "VAR_STRING({$ref})",
                't_bool'     => "VAR_BOOL({$ref})",
                't_array*'   => "VAR_ARRAY({$ref})",
                't_callback' => "VAR_CALLBACK({$ref})",
                'null'       => "VAR_NULL()",
                default      => (str_contains($vt, 'tphp_class_') || str_contains($vt, 'tphp_enum_'))
                    ? "VAR_OBJ({$ref})"
                    : "VAR_INT({$code})",
            };
        }
        // PropertyAccessExpr：用 getPropType 查属性类型（含 $this->prop / $obj->prop）
        //   优先于 TypeChecker 的 inferredType（可能误标为 mixed）
        if ($expr instanceof PropertyAccessExpr) {
            $propType = $this->getPropType($expr);
            if ($propType === '') $propType = 't_int';
            if ($propType === 't_var') return $code;
            // 对象类型 → VAR_OBJ
            if (str_contains($propType, '_class_') || str_ends_with($propType, '*')) {
                return "VAR_OBJ({$code})";
            }
            return match ($propType) {
                't_int'      => "VAR_INT({$code})",
                't_float'    => "VAR_FLOAT({$code})",
                't_string'   => "VAR_STRING({$code})",
                't_bool'     => "VAR_BOOL({$code})",
                't_array*'   => "VAR_ARRAY({$code})",
                't_callback' => "VAR_CALLBACK({$code})",
                'null'       => "VAR_NULL()",
                default      => "VAR_INT({$code})",
            };
        }
        // 复杂表达式：用 inferType 动态推导类型
        if ($expr instanceof BinaryExpr && $expr->operator === '.') {
            return "VAR_STRING({$code})";
        }
        if ($expr instanceof CastExpr) {
            return match ($expr->castType) {
                'bool'   => "VAR_BOOL({$code})",
                'string' => "VAR_STRING({$code})",
                'int'    => "VAR_INT({$code})",
                'float'  => "VAR_FLOAT({$code})",
                'array'  => "VAR_ARRAY({$code})",
                default  => "VAR_INT({$code})",
            };
        }
        // BinaryExpr/TernaryExpr/CallExpr/EnumAccessExpr/MatchExpr/UnaryExpr 等
        $type = $this->inferType($expr);
        // ArrayAccessExpr 特殊处理：TypeChecker 标记 inferredType 为 mixed（因数组是 array<mixed>），
        //   但 visitArrayAccess 可能基于 arrElementTypes 生成 typed getter（返回 t_array*/t_int 等）。
        //   此时需按实际 getter 返回类型包装，否则 t_var 变量接收 typed 值会类型不匹配。
        //   优先级：实际元素类型追踪 > TypeChecker 的 mixed 标记
        if ($expr instanceof ArrayAccessExpr && $type === 't_var') {
            $actualType = $this->inferArrayAccessActualType($expr);
            if ($actualType !== null && $actualType !== 't_var') {
                $type = $actualType;
            }
        }
        return match ($type) {
            't_string'   => "VAR_STRING({$code})",
            't_float'    => "VAR_FLOAT({$code})",
            't_bool'     => "VAR_BOOL({$code})",
            't_array*'   => "VAR_ARRAY({$code})",
            't_callback' => "VAR_CALLBACK({$code})",
            't_var'      => $code,
            'null'       => "VAR_NULL()",
            default      => (str_contains($type, 'tphp_class_') || str_contains($type, 'tphp_enum_'))
                ? "VAR_OBJ({$code})"
                : "VAR_INT({$code})",
        };
    }

    /**
     * 推导 ArrayAccessExpr 的实际 C 返回类型（基于 arrElementTypes 追踪）。
     * 用于 wrapTvarAssign：当 TypeChecker 标记 inferredType 为 mixed（array<mixed>），
     *   但 visitArrayAccess 基于 arrElementTypes 生成了 typed getter（返回 t_array* 等），
     *   需要返回实际 getter 的返回类型以便正确包装为 t_var。
     */

}
