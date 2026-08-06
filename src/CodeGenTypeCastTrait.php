<?php

declare(strict_types=1);

// ============================================================
// CodeGenTypeCastTrait — 类型转换方法
// 从 CodeGenerator.php 提取：castToInt/castToFloat/castToBool/castToArray/castToObject/castToStr
// ============================================================
trait CodeGenTypeCastTrait {

    private function castToInt(ExprNode $expr): string
    {
        if ($expr instanceof IntLiteralExpr) return $expr->accept($this);

        if ($expr instanceof FloatLiteralExpr) {
            return '(t_int)(' . $expr->accept($this) . ')';
        }
        if ($expr instanceof BoolLiteralExpr) {
            return $expr->value ? '1' : '0';
        }
        if ($expr instanceof NullLiteralExpr) {
            return '0';
        }
        if ($expr instanceof StringLiteralExpr) {
            return 'tphp_rt_parse_int(' . $expr->accept($this) . ')';
        }
        if ($expr instanceof ArrayLiteralExpr) {
            return empty($expr->entries) ? '0' : '1';
        }
        if ($expr instanceof NewExpr) {
            throw new RuntimeException(
                sprintf("[%d:%d] Object cannot be converted to int", $expr->line, $expr->column)
            );
        }
        if ($expr instanceof UnaryExpr) {
            return $expr->accept($this); // -(inner) already correct int
        }
        if ($expr instanceof BinaryExpr && $expr->operator === '.') {
            return 'tphp_rt_parse_int(' . $expr->accept($this) . ')';
        }

        // 变量：根据类型推导
        $code = $expr->accept($this);
        if ($expr instanceof VariableExpr) {
            $vn = self::varName($expr->name);
            $vt = $this->varTypes[$vn] ?? 't_int';
            return match ($vt) {
                't_int'    => $code,
                't_float'  => "(t_int)({$code})",
                't_bool'   => $code,
                't_string' => "tphp_rt_parse_int({$code})",
                'null'     => '0',
                't_array*' => "(({$vn} && tphp_fn_arr_count({$vn}) > 0) ? 1 : 0)",
                default    => throw new RuntimeException(
                    sprintf("[%d:%d] Object cannot be converted to int", $expr->line, $expr->column)
                ),
            };
        }
        if ($expr instanceof EnumAccessExpr) {
            $bt = $this->enumBackingType($expr->enumName);
            return ($bt === 'string') ? "tphp_rt_parse_int(({$code})->value)" : "({$code})->value";
        }

        // 兜底：根据推断类型选择转换方式
        //   t_string 是 struct，直接 (t_int) 强转会取指针地址而非解析字符串内容
        $inferredType = $this->inferType($expr);
        if ($inferredType === 't_string') {
            return "tphp_rt_parse_int({$code})";
        }
        // t_var（array<mixed> 元素等）→ VAR_AS_INT 解包
        // 仅对实际为 t_var 的表达式（varTypes/arrElementTypes 标记）应用，避免误伤 mixed 推断的标量
        if ($inferredType === 't_var' && $this->isActualTVarExpr($expr)) {
            return "VAR_AS_INT({$code})";
        }
        return "(t_int)({$code})";
    }

    /** 将任意表达式转为 t_float（用于 (float) 转换） */

    private function castToFloat(ExprNode $expr): string
    {
        if ($expr instanceof FloatLiteralExpr) return $expr->accept($this);
        if ($expr instanceof IntLiteralExpr) return '(t_float)(' . $expr->accept($this) . ')';
        if ($expr instanceof BoolLiteralExpr) return $expr->value ? '1.0' : '0.0';
        if ($expr instanceof NullLiteralExpr) return '0.0';
        if ($expr instanceof StringLiteralExpr) {
            return 'tphp_rt_parse_float(' . $expr->accept($this) . ')';
        }
        if ($expr instanceof ArrayLiteralExpr) {
            return empty($expr->entries) ? '0.0' : '1.0';
        }
        if ($expr instanceof NewExpr) {
            throw new RuntimeException(
                sprintf("[%d:%d] Object cannot be converted to float", $expr->line, $expr->column)
            );
        }
        if ($expr instanceof UnaryExpr) {
            return $expr->accept($this);
        }
        if ($expr instanceof BinaryExpr && $expr->operator === '.') {
            return 'tphp_rt_parse_float(' . $expr->accept($this) . ')';
        }

        $code = $expr->accept($this);
        if ($expr instanceof VariableExpr) {
            $vn = self::varName($expr->name);
            $vt = $this->varTypes[$vn] ?? 't_int';
            return match ($vt) {
                't_float'  => $code,
                't_int'    => "(t_float)({$code})",
                't_bool'   => "(t_float)({$code})",
                't_string' => "tphp_rt_parse_float({$code})",
                'null'     => '0.0',
                't_array*' => "(({$vn} && tphp_fn_arr_count({$vn}) > 0) ? 1.0 : 0.0)",
                't_var'    => "VAR_AS_FLOAT({$code})",
                default    => throw new RuntimeException(
                    sprintf("[%d:%d] Object cannot be converted to float", $expr->line, $expr->column)
                ),
            };
        }

        // t_var（array<mixed> 元素等）→ VAR_AS_FLOAT 解包
        $inferredType = $this->inferType($expr);
        if ($inferredType === 't_var' && $this->isActualTVarExpr($expr)) {
            return "VAR_AS_FLOAT({$code})";
        }
        if ($inferredType === 't_string') {
            return "tphp_rt_parse_float({$code})";
        }
        return "(t_float)({$code})";
    }

    /** 将任意表达式转为 t_bool（用于 (bool) 转换） */

    private function castToBool(ExprNode $expr): string
    {
        if ($expr instanceof BoolLiteralExpr) return $expr->accept($this);
        if ($expr instanceof IntLiteralExpr) return $expr->value ? 'true' : 'false';
        if ($expr instanceof FloatLiteralExpr) return $expr->value != 0.0 ? 'true' : 'false';
        if ($expr instanceof NullLiteralExpr) return 'false';
        if ($expr instanceof StringLiteralExpr) {
            $v = $expr->value;
            return ($v === '' || $v === '0') ? 'false' : 'true';
        }
        if ($expr instanceof ArrayLiteralExpr) {
            return empty($expr->entries) ? 'false' : 'true';
        }
        if ($expr instanceof NewExpr) {
            return 'true'; // 任何对象转 bool 为 true
        }
        if ($expr instanceof UnaryExpr) {
            $code = $expr->accept($this);
            return "((bool)({$code}))";
        }

        $code = $expr->accept($this);
        if ($expr instanceof VariableExpr) {
            $vn = self::varName($expr->name);
            $vt = $this->varTypes[$vn] ?? 't_int';
            return match ($vt) {
                't_bool'   => $code,
                't_int'    => "({$code} != 0)",
                't_float'  => "({$code} != 0.0)",
                't_string' => "!tphp_rt_str_is_falsy({$code})",
                'null'     => 'false',
                't_array*' => "({$vn} != NULL && tphp_fn_arr_count({$vn}) > 0)",
                't_var'    => "VAR_AS_BOOL({$code})",
                default    => 'true', // 对象
            };
        }

        // t_var（array<mixed> 元素等）→ VAR_AS_BOOL 解包
        $inferredType = $this->inferType($expr);
        if ($inferredType === 't_var' && $this->isActualTVarExpr($expr)) {
            return "VAR_AS_BOOL({$code})";
        }
        return "((bool)({$code}))";
    }

    /** 将标量/对象转为单元素数组 */

    private function castToArray(ExprNode $expr): string
    {
        if ($expr instanceof NullLiteralExpr) return 'tphp_fn_arr_create(0)';
        // (array) $stdClass → 提取属性表为关联数组
        $srcType = $this->inferType($expr);
        if (str_contains($srcType, 'tphp_class_stdClass')) {
            $code = $expr->accept($this);
            return "tphp_fn_stdclass_to_array({$code})";
        }
        return 'tphp_fn_arr_from_val(' . $this->wrapVar($expr) . ')';
    }

    /** (object) $expr 转换：数组 → stdClass，其他 → stdClass（空或包装） */

    private function castToObject(ExprNode $expr): string
    {
        // (object) $array → stdClass from array
        $srcType = $this->inferType($expr);
        if ($srcType === 't_array*' || $srcType === 't_var') {
            $code = $expr->accept($this);
            if ($srcType === 't_var') {
                // t_var 持有数组时，先提取 .value._array
                $code = "(({$code}).value._array)";
            }
            return "tphp_fn_stdclass_from_array({$code})";
        }
        // (object) null → 空 stdClass
        if ($expr instanceof NullLiteralExpr) {
            return 'new_stdClass()';
        }
        // 其他标量转 stdClass：PHP 中 (object)42 会创建 stdClass{"scalar": 42}
        //   此场景较少见，简化处理为空 stdClass
        return 'new_stdClass()';
    }

    public function visitArrayAppend(ArrayAppendExpr $node): string
    {
        // $expr[] 在非赋值上下文无意义（PHP 中 $arr[] 单独使用会抛 Notice: Undefined variable）
        // TinyPHP 中 ArrayAppendExpr 仅用于 $expr[] = value 赋值，由 visitAssignArrayPushStmt 处理
        // 若到达此处说明语法误用
        throw new \Exception('$expr[] in expression context (expected assignment $expr[] = value)');
    }

    /**
     * 解析实例属性数组访问的元素类型注册键。
     * 支持 $this->prop（class = currentClassName）和 $obj->prop（class = $obj 的类型）。
     * 返回 "cn::prop" 或 null（无法解析时）。
     */
    private function propArrElemKey(PropertyAccessExpr $node): ?string
    {
        if (!$node->object instanceof VariableExpr) return null;
        $objName = $node->object->name;
        $prop = ltrim($node->property, '$');
        if ($objName === '$this' || $objName === 'self') {
            return $this->className . '::' . $prop;
        }
        if (str_starts_with($objName, '$')) {
            $vn = self::varName($objName);
            $objType = $this->varTypes[$vn] ?? '';
            // 对象类型形如 "tphp_class_Xxx*" → 去掉尾部 *
            $cn = rtrim($objType, '*');
            if ($cn !== '' && $this->symbols->hasClass($cn)) {
                return $cn . '::' . $prop;
            }
        }
        return null;
    }


    private function castToStr(ExprNode $expr, bool $strict = false): string
    {
        if ($expr instanceof StringLiteralExpr) return $expr->accept($this);
        if ($expr instanceof ArrayAccessExpr) {
            $code = $expr->accept($this);
            // array<mixed> 元素（t_var）→ 字符串上下文统一用 tphp_fn_strval
            //   但需检查实际生成的访问器返回类型：arrElementTypes/链式访问可能生成
            //   arr_get_int_str（返回 t_string）/ arr_get_int_int（返回 t_int）等类型化访问器，
            //   此时 tphp_fn_strval(t_string/t_int) 会导致编译错误（strval 只接受 t_var）
            if ($this->inferType($expr) === 't_var') {
                // 检查访问器函数名，确定实际返回类型
                //   类型化访问器返回 t_string/t_int/t_float → 用对应的 str 转换
                //   t_var 访问器（arr_get_str / arr_index）→ 用 tphp_fn_strval
                if (str_contains($code, '_get_str_str(') || str_contains($code, '_get_int_str(')
                    || str_contains($code, '_str_get_str(') || str_contains($code, '_str_get(')) {
                    return $code;  // t_string，无需转换
                }
                if (str_contains($code, '_get_str_int(') || str_contains($code, '_get_int_int(')
                    || str_contains($code, '_int_get_str(') || str_contains($code, '_int_get(')) {
                    return "tphp_rt_str_from_int({$code})";  // t_int → str
                }
                if (str_contains($code, '_get_str_float(') || str_contains($code, '_get_int_float(')
                    || str_contains($code, '_float_get_str(') || str_contains($code, '_float_get(')) {
                    return "tphp_rt_str_from_float({$code})";  // t_float → str
                }
                if (str_contains($code, '_get_str_arr(') || str_contains($code, '_get_int_arr(')) {
                    $fixed = str_replace(['_get_str_arr', '_get_int_arr'], '_get_str_str', $code);
                    return $fixed;  // t_array* → 改用 str 访问器
                }
                // t_var 元素：*tphp_fn_arr_get_str(...) 或 *tphp_fn_arr_index(...)
                return "tphp_fn_strval({$code})";
            }
            // 字符串键：check per-key type
            if ($this->hasStrKey($expr)) {
                // per-key 类型可能为 int，需转换
                if ($expr->index instanceof StringLiteralExpr && $expr->array instanceof VariableExpr) {
                    $at = self::varName($expr->array->name);
                    $kt = $this->arrValueTypes[$at][$expr->index->value] ?? null;
                    // 全局查找 per-key 类型
                    if ($kt === null) {
                        $keyStr = $expr->index->value;
                        foreach ($this->arrValueTypes as $vKeys) {
                            if (isset($vKeys[$keyStr])) { $kt = $vKeys[$keyStr]; break; }
                        }
                    }
                    $kt ??= 't_string';
                    if ($kt === 't_int') return "tphp_rt_str_from_int({$code})";
                    if ($kt === 't_float') return "tphp_rt_str_from_float({$code})";
                }
                // 未知 per-key 类型：检查函数名判断是否需要转字符串
                if (str_contains($code, 'get_str_int') || str_contains($code, 'get_str_float')) {
                    return "tphp_rt_str_from_int({$code})";
                }
                // get_str_arr 返回 t_array*，在字符串上下文需要改用 get_str_str
                if (str_contains($code, 'get_str_arr')) {
                    $fixed = str_replace('get_str_arr', 'get_str_str', $code);
                    return $fixed;
                }
                return $code;
            }
            // 整数键：用 inferType 判断元素转 str 的方式
            $type = $this->inferType($expr);
            return match ($type) {
                't_string'  => $code,
                't_float'   => "tphp_rt_str_from_float({$code})",
                't_array*'  => "tphp_rt_str_from_int((t_int)(intptr_t)({$code}))",
                't_var'     => "tphp_fn_strval({$code})",  // array<mixed> 元素 → t_var → str
                default     => "tphp_rt_str_from_int({$code})",
            };
        }
        if ($expr instanceof CastExpr) {
            $code = $expr->accept($this);
            return match ($expr->castType) {
                'string' => $code,
                'float'  => "tphp_rt_str_from_float({$code})",
                default  => "tphp_rt_str_from_int({$code})",
            };
        }

        if ($expr instanceof IntLiteralExpr) {
            return 'tphp_rt_str_from_int(' . $expr->accept($this) . ')';
        }
        if ($expr instanceof FloatLiteralExpr) {
            return 'tphp_rt_str_from_float(' . $expr->accept($this) . ')';
        }
        if ($expr instanceof BoolLiteralExpr) {
            return $expr->value ? 'STR_LIT("1")' : 'STR_LIT("")';
        }
        if ($expr instanceof NullLiteralExpr) {
            return 'STR_LIT("")';
        }
        if ($expr instanceof MagicConstExpr) {
            return $expr->accept($this); // already t_string
        }
        if ($expr instanceof ArrayLiteralExpr) {
            if ($strict) {
                throw new RuntimeException(
                    sprintf("[%d:%d] Array cannot be converted to string", $expr->line, $expr->column)
                );
            }
            return 'STR_LIT("Array")';
        }
        if ($expr instanceof NewExpr) {
            if ($strict) {
                throw new RuntimeException(
                    sprintf("[%d:%d] Object cannot be converted to string", $expr->line, $expr->column)
                );
            }
            return 'STR_LIT("Object")';
        }

        // 变量 / 表达式：根据推导类型转换
        $code = $expr->accept($this);
        if ($expr instanceof VariableExpr) {
            $vn = self::varName($expr->name);
            $vt = $this->varTypes[$vn] ?? 't_int';
            return match ($vt) {
                't_string'   => $code,
                't_int'      => "tphp_rt_str_from_int({$code})",
                't_float'    => "tphp_rt_str_from_float({$code})",
                't_bool'     => "({$code} ? STR_LIT(\"1\") : STR_LIT(\"\"))",
                't_var'      => "tphp_fn_strval({$code})",
                'null'       => 'STR_LIT("")',
                't_array*'   => $strict
                    ? throw new RuntimeException(sprintf("[%d:%d] Array cannot be converted to string", $expr->line, $expr->column))
                    : 'STR_LIT("Array")',
                'tphp_class_Exception*' => "tphp_class_Exception_getMessage({$code})",
                default      => $strict
                    ? throw new RuntimeException(sprintf("[%d:%d] Object cannot be converted to string", $expr->line, $expr->column))
                    : 'STR_LIT("Object")',
            };
        }

        // BinaryExpr — 根据运算符推导类型
        if ($expr instanceof BinaryExpr) {
            if ($expr->operator === '.') return $code; // 已经是 t_string
            return "tphp_rt_str_from_int({$code})";
        }

        // PropertyAccessExpr：查找属性类型（复用 getPropType）
        if ($expr instanceof PropertyAccessExpr) {
            $pt = $this->getPropType($expr);
            if ($pt === 't_string') return $code;
            if ($pt === 't_float') return "tphp_rt_str_from_float({$code})";
            if ($pt === 't_var') return "tphp_fn_strval({$code})";
        }

        // CallExpr：查找返回类型（内置函数 + 方法调用 + 枚举方法）
        if ($expr instanceof CallExpr) {
            // 内置函数返回 t_string/t_float/t_var（如 array_sum 返回 t_var）
            if ($expr->callee === null) {
                $rt = $this->inferCallReturnType($expr);
                if ($rt === 't_string') return $code;
                if ($rt === 't_float') return "tphp_rt_str_from_float({$code})";
                // t_var (mixed): array_sum/array_product/array_pop 等 → 用 tphp_fn_strval 解包为字符串
                if ($rt === 't_var') return "tphp_fn_strval({$code})";
            }
            // 枚举方法调用（静态 Color::cases() 或实例 Color::Red->label()）
            $enumCName = null;
            if ($expr->callee instanceof EnumAccessExpr) {
                $enumCName = $this->symbols->getEnumCName($expr->callee->enumName);
            } elseif ($expr->callee instanceof VariableExpr) {
                $enumCName = $this->symbols->resolveEnumCName($expr->callee->name);
                // 变量持有枚举实例：$coalesce->label() — 查 varTypes 看是否为枚举 C 类型
                //   $coalesce = Color::tryFrom(99) ?? Color::GREEN; 之后 $coalesce->label()
                if ($enumCName === null && str_starts_with($expr->callee->name, '$')) {
                    $vn = self::varName($expr->callee->name);
                    $vt = $this->varTypes[$vn] ?? '';
                    if ($vt !== '') {
                        $enumCName = $this->symbols->resolveEnumCName(rtrim($vt, '*'));
                    }
                }
            } elseif ($expr->callee instanceof CallExpr) {
                $chain = $this->inferCallChainClass($expr->callee);
                $enumCName = $this->symbols->resolveEnumCName(rtrim($chain, '*'));
            }
            if ($enumCName !== null) {
                $mi = $this->symbols->getEnumMethodByCName($enumCName, $expr->name);
                if ($mi !== null) {
                    if ($mi->retType === 't_string') return $code;
                    if ($mi->retType === 't_float') return "tphp_rt_str_from_float({$code})";
                }
            }
            // 方法调用
            if ($expr->callee !== null) {
                $objKey = ($expr->callee instanceof VariableExpr) ? self::varName($expr->callee->name) : '';
                $objType = ($objKey === '$this' || $objKey === 'self')
                    ? $this->className
                    : ($this->varTypes[$objKey] ?? '');
                $objClean = rtrim($objType, '*'); // COS objects always have *
                // 静态方法调用 ClassName::method() — 解析类名
                if ($objClean === '' && $expr->callee instanceof VariableExpr) {
                    $resolved = $this->symbols->resolveClass($expr->callee->name);
                    if ($resolved !== null) $objClean = $resolved;
                }
                if ($objClean !== '') {
                    $mInfo = $this->symbols->getClassMethod($objClean, $expr->name);
                    if ($mInfo !== null) {
                        $retType = $mInfo->retType;
                        if ($retType === 't_string') return $code;
                        if ($retType === 't_float') return "tphp_rt_str_from_float({$code})";
                    }
                }
            }
        }

        // EnumAccessExpr → case 访问取 ->value 转 str；常量访问按声明类型转换
        if ($expr instanceof EnumAccessExpr) {
            // 常量访问 → 按声明类型转换
            if (!$this->symbols->hasEnumCase($expr->enumName, $expr->caseName)) {
                $ct = $this->symbols->getEnumConstType($expr->enumName, $expr->caseName);
                if ($ct === 't_string') return $code;
                if ($ct === 't_float') return "tphp_rt_str_from_float({$code})";
                return "tphp_rt_str_from_int({$code})";
            }
            // case 访问 → 用 ->value 取值后转字符串
            $bt = $this->enumBackingType($expr->enumName);
            return ($bt === 'string') ? "({$code})->value" : "tphp_rt_str_from_int(({$code})->value)";
        }

        // MatchExpr → 查 inferType 决定如何转字符串
        if ($expr instanceof MatchExpr) {
            $bt = $this->inferType($expr);
            return ($bt === 't_string') ? $code : "tphp_rt_str_from_int({$code})";
        }

        // TernaryExpr → 查 inferType 决定如何转字符串
        if ($expr instanceof TernaryExpr) {
            $bt = $this->inferType($expr);
            if ($bt === 't_string') return $code;
            if ($bt === 't_float') return "tphp_rt_str_from_float({$code})";
            return "tphp_rt_str_from_int({$code})";
        }

        // NullCoalesceExpr → 查 inferType 决定如何转字符串
        //   $x ?? "default" 的公共类型由 TypeChecker 推导，避免误用 str_from_int
        if ($expr instanceof NullCoalesceExpr) {
            $bt = $this->inferType($expr);
            if ($bt === 't_string') return $code;
            if ($bt === 't_float') return "tphp_rt_str_from_float({$code})";
            return "tphp_rt_str_from_int({$code})";
        }

        // ArrayAccessExpr：字符串键读取返回 t_string
        if ($expr instanceof ArrayAccessExpr) {
            $idxType = $this->inferType($expr->index);
            if ($idxType === 't_string' || $expr->index instanceof StringLiteralExpr) {
                return $code;  // tphp_fn_arr_get_str_str 已返回 t_string
            }
        }

        // TPHP_CONST_ 常量引用：根据 SymbolTable 注册的类型决定如何转字符串
        //   场景：self::ITER (const int = 10000) → tphp_rt_str_from_int(TPHP_CONST_...)
        //         PHP_EOL (STR_LIT(...)) → 直接返回
        if (str_starts_with($code, 'TPHP_CONST_')) {
            $ct = $this->symbols->getConstType($code)
                ?? $this->symbols->getConstType(strtoupper($code));
            // 未注册的全局常量（PHP_EOL/PHP_OS 等）默认为 STR_LIT 宏 → t_string
            if ($ct === null) return $code;
            return match ($ct) {
                't_string' => $code,
                't_float'  => "tphp_rt_str_from_float({$code})",
                't_bool'   => "({$code} ? STR_LIT(\"1\") : STR_LIT(\"\"))",
                default    => "tphp_rt_str_from_int({$code})",
            };
        }

        // 其他表达式（CallExpr 等）默认假设返回 int
        return "tphp_rt_str_from_int({$code})";
    }

    /** 将表达式值包装为 VAR_XXX 宏（用于 mixed/union 变量赋值） */

}
