<?php

declare(strict_types=1);

// ============================================================
// CodeGenArrayTrait — 数组操作相关方法
// 从 CodeGenerator.php 提取
// ============================================================
trait CodeGenArrayTrait {

    private function generateArrayMap(CallExpr $node): string
    {
        $cbCode  = $node->args[0]->accept($this);
        // array<T> 源数组自动协变转换为 t_array*（arrayArgCode 处理 t_arr_int*/t_arr_str* 等）
        $arrCode = $this->arrayArgCode($node->args[1], $node->args[1]->accept($this));
        $sig = $this->inferCallbackSig($node->args[0]);
        $retType   = $sig['ret'] ?? 't_int';
        $paramType = $sig['params'][0] ?? 't_int';
        $getter  = $this->arrGetterForType($paramType);
        $varWrap = $this->arrVarWrapForType($retType);
        $tn = '_am_' . (++$this->tmpVarCounter);
        $paramCast = $paramType;
        return "({ t_callback {$tn}_cb = {$cbCode};"
             . " t_array* {$tn}_r = tphp_fn_arr_create(0);"
             . " t_array* {$tn}_a = {$arrCode};"
             . " for (int {$tn}_i = 0; {$tn}_a && {$tn}_i < {$tn}_a->length; {$tn}_i++) {"
             . " {$paramType} {$tn}_v = {$getter}({$tn}_a, {$tn}_i);"
             . " {$retType} {$tn}_m = (({$retType}(*)({$paramCast}, void*)){$tn}_cb.func)({$tn}_v, {$tn}_cb.env);"
             . " {$tn}_r = tphp_fn_arr_push({$tn}_r, {$varWrap}({$tn}_m));"
             . " } {$tn}_r; })";
    }

    /** array_filter($arr, $callback) → 类型特化内联循环 */
    private function generateArrayFilter(CallExpr $node): string
    {
        // array<T> 源数组自动协变转换
        $arrCode = $this->arrayArgCode($node->args[0], $node->args[0]->accept($this));
        $cbCode  = $node->args[1]->accept($this);
        $sig = $this->inferCallbackSig($node->args[1]);
        $paramType = $sig['params'][0] ?? 't_int';
        $retType   = $sig['ret'] ?? 't_bool';
        $getter  = $this->arrGetterForType($paramType);
        $varWrap = $this->arrVarWrapForType($paramType);
        $tn = '_af_' . (++$this->tmpVarCounter);
        return "({ t_callback {$tn}_cb = {$cbCode};"
             . " t_array* {$tn}_r = tphp_fn_arr_create(0);"
             . " t_array* {$tn}_a = {$arrCode};"
             . " for (int {$tn}_i = 0; {$tn}_a && {$tn}_i < {$tn}_a->length; {$tn}_i++) {"
             . " {$paramType} {$tn}_v = {$getter}({$tn}_a, {$tn}_i);"
             . " if ((({$retType}(*)({$paramType}, void*)){$tn}_cb.func)({$tn}_v, {$tn}_cb.env)) {"
             . " {$tn}_r = tphp_fn_arr_push({$tn}_r, {$varWrap}({$tn}_v));"
             . " } } {$tn}_r; })";
    }

    /** array_reduce($arr, $callback, $initial) → 类型特化内联循环 */
    private function generateArrayReduce(CallExpr $node): string
    {
        // array<T> 源数组自动协变转换
        $arrCode  = $this->arrayArgCode($node->args[0], $node->args[0]->accept($this));
        $cbCode   = $node->args[1]->accept($this);
        $initCode = $node->args[2]->accept($this);
        $sig = $this->inferCallbackSig($node->args[1]);
        $retType   = $sig['ret'] ?? 't_int';
        $accType   = $sig['params'][0] ?? 't_int';
        $elemType  = $sig['params'][1] ?? 't_int';
        $getter  = $this->arrGetterForType($elemType);
        $tn = '_ar_' . (++$this->tmpVarCounter);
        return "({ t_callback {$tn}_cb = {$cbCode};"
             . " {$retType} {$tn}_acc = {$initCode};"
             . " t_array* {$tn}_a = {$arrCode};"
             . " for (int {$tn}_i = 0; {$tn}_a && {$tn}_i < {$tn}_a->length; {$tn}_i++) {"
             . " {$elemType} {$tn}_v = {$getter}({$tn}_a, {$tn}_i);"
             . " {$tn}_acc = (({$retType}(*)({$accType}, {$elemType}, void*)){$tn}_cb.func)({$tn}_acc, {$tn}_v, {$tn}_cb.env);"
             . " } {$tn}_acc; })";
    }

    /** is_int / is_float / is_string / is_bool / is_array / is_null / is_object / is_callable
     *  静态类型在编译期直接返回 true/false 常量；t_var 类型调用运行时 tphp_fn_is_* */

    private function generateKeyedAssign(array &$lines, string $arrName, array $entries, string $elemType = 't_int', ?ArrayLiteralExpr $srcLiteral = null, string $srcVarName = ''): void
    {
        foreach ($entries as $e) {
            $key = $e['key'];
            $keyIsInt = $e['keyIsInt'] ?? false;
            $var = self::varName('$' . $e['var']);
            // per-key 类型推断：优先从源数组字面量或 arrValueTypes 查找精确类型
            $entryType = $elemType;
            if ($srcLiteral !== null) {
                foreach ($srcLiteral->entries as $entry) {
                    $keyMatches = $keyIsInt
                        ? ($entry->key instanceof IntegerLiteralExpr && $entry->key->value === $key)
                        : ($entry->key instanceof StringLiteralExpr && $entry->key->value === $key);
                    if ($keyMatches) {
                        $val = $entry->value ?? $entry;
                        if ($val !== null) {
                            $inferred = $this->inferType($val);
                            if ($inferred !== 'null') {
                                $entryType = $inferred;
                                if (str_contains($entryType, 'tphp_class_') && !str_ends_with($entryType, '*')) $entryType .= '*';
                            }
                        }
                        break;
                    }
                }
            } elseif ($srcVarName !== '' && !$keyIsInt && isset($this->arrValueTypes[$srcVarName][$key])) {
                $entryType = $this->arrValueTypes[$srcVarName][$key];
            }
            // 元素类型 → keyed getter 后缀
            $getterSuffix = match ($entryType) {
                't_float'    => 'float',
                't_string'   => 'str',
                't_bool'     => 'int',  // tphp 无 arr_get_str_bool，用 int
                't_array*'   => 'arr',
                't_callback' => 'callback',
                default      => 'int',
            };
            $cType = $this->typeToCType($entryType);
            $isDeclared = isset($this->declaredVars[$var]);
            // 已声明的标量/数组/回调变量保持原类型（list 解构复用已有变量时不改类型）
            //   场景：$default = 0; ["missing" => $default] = $src2; — $default 仍是 t_int
            //   elemType 为 t_var 时，用 typed getter (arr_get_str_int) 而非 *arr_get_str
            //   避免将 t_var 赋给已声明为 t_int 的变量导致类型错误
            if ($isDeclared
                && in_array($this->varTypes[$var] ?? '', ['t_int', 't_float', 't_string', 't_bool', 't_array*', 't_callback'], true)) {
                $existingType = $this->varTypes[$var];
                $entryType = $existingType;
                $getterSuffix = match ($existingType) {
                    't_float'    => 'float',
                    't_string'   => 'str',
                    't_bool'     => 'int',
                    't_array*'   => 'arr',
                    't_callback' => 'callback',
                    default      => 'int',
                };
                $cType = $this->typeToCType($existingType);
            }
            $this->declaredVars[$var] = true;
            $this->varTypes[$var] = $entryType;
            // 数组类型：传播源数组的 per-key 类型和字面量 AST，支持后续 $sub["k"] 访问类型推断
            //   如 ["outer" => $sub] = ["outer" => ["a"=>"first"]] → $sub["a"] 应为 string
            if ($entryType === 't_array*' && !$keyIsInt) {
                if ($srcLiteral !== null) {
                    foreach ($srcLiteral->entries as $entry) {
                        if ($entry->key instanceof StringLiteralExpr && $entry->key->value === $key) {
                            $val = $entry->value ?? $entry;
                            if ($val instanceof ArrayLiteralExpr) {
                                $this->arrLiteralAST[$var] = $val;
                                $this->arrElementTypes[$var] = $this->inferArrayDeepElementType($val);
                                // 传播 per-key 类型
                                foreach ($val->entries as $subEntry) {
                                    if ($subEntry->key instanceof StringLiteralExpr) {
                                        $subVal = $subEntry->value ?? $subEntry;
                                        if ($subVal !== null) {
                                            $subValType = $this->inferType($subVal);
                                            if ($subValType !== 'null') {
                                                $this->arrValueTypes[$var] ??= [];
                                                $this->arrValueTypes[$var][$subEntry->key->value] = $subValType;
                                            }
                                        }
                                    }
                                }
                            }
                            break;
                        }
                    }
                }
            }
            $prefix = $isDeclared ? '' : ($cType . ' ');
            // t_var 元素：直接取 t_var* 解引用（array<mixed> 元素本身就是 t_var）
            if ($entryType === 't_var') {
                if ($keyIsInt) {
                    $lines[] = "{$prefix}{$var} = *tphp_fn_arr_get_int({$arrName}, (t_int){$key});";
                } else {
                    $klen = strlen((string)$key);
                    $lines[] = "{$prefix}{$var} = *tphp_fn_arr_get_str({$arrName}, (t_string){.data=\"{$key}\", .length={$klen}});";
                }
                continue;
            }
            // 整数键用 tphp_fn_arr_get_int_*，字符串键用 tphp_fn_arr_get_str_*
            if ($keyIsInt) {
                $lines[] = "{$prefix}{$var} = tphp_fn_arr_get_int_{$getterSuffix}({$arrName}, (t_int){$key});";
            } else {
                $klen = strlen((string)$key);
                $lines[] = "{$prefix}{$var} = tphp_fn_arr_get_str_{$getterSuffix}({$arrName}, (t_string){.data=\"{$key}\", .length={$klen}});";
            }
        }
    }

    /** 递归生成 list 赋值代码
     * @param array $vars (null|string|ListStmtNode)[]
     * @param ArrayLiteralExpr|null $srcLiteral 源数组字面量（用于 per-index 元素类型推断，处理混合类型数组）
     */
    private function generateListAssign(array &$lines, string $arrName, int $baseIdx, array $vars, string $elemType = 't_int', ?ArrayLiteralExpr $srcLiteral = null): void
    {
        $cType = $this->typeToCType($elemType);
        $idx = $baseIdx;
        $entryIdx = 0;
        foreach ($vars as $item) {
            if ($item === null) {
                $idx++;
                $entryIdx++;
                continue;
            }
            // per-index 元素类型推断：从源数组字面量对应位置推断具体元素类型
            // （处理 [10, [20, [30]]] 等混合类型数组：index 0 是 int，index 1 是 array）
            $itemElemType = $elemType;
            $itemSrcLiteral = null;
            if ($srcLiteral !== null && isset($srcLiteral->entries[$entryIdx])) {
                $srcEntry = $srcLiteral->entries[$entryIdx];
                $srcVal = $srcEntry->value ?? $srcEntry;
                if ($srcVal !== null) {
                    $inferred = $this->inferType($srcVal);
                    if ($inferred !== 'null') {
                        $itemElemType = $inferred;
                        if (str_contains($itemElemType, 'tphp_class_') && !str_ends_with($itemElemType, '*')) $itemElemType .= '*';
                    }
                    if ($srcVal instanceof ArrayLiteralExpr) {
                        $itemSrcLiteral = $srcVal;
                    }
                }
            }
            // 元素类型 → item getter 后缀（注意：arr_item_* 系列用 'array'）
            $getterSuffix = match ($itemElemType) {
                't_float'    => 'float',
                't_string'   => 'str',
                't_bool'     => 'int',
                't_array*'   => 'array',
                default      => 'int',
            };
            $itemCType = $this->typeToCType($itemElemType);
            if ($item instanceof ListStmtNode) {
                // 嵌套 list：先取 t_var*，再取 .value._array
                $subArr = '_sublst_' . (++$this->tmpVarCounter);
                $tv     = '_tv_' . (++$this->tmpVarCounter);
                $lines[] = "t_var* {$tv} = ({$arrName} && {$arrName}->length > {$idx}) ? tphp_fn_arr_get_int({$arrName}, {$idx}) : NULL;";
                $lines[] = "t_array* {$subArr} = ({$tv} && {$tv}->type == TYPE_ARRAY) ? {$tv}->value._array : NULL;";
                // 递归：传入子数组的字面量 AST（若有），elemType 用子数组自身的元素类型
                $subElemType = $itemSrcLiteral !== null
                    ? $this->inferArrayDeepElementType($itemSrcLiteral)
                    : $itemElemType;
                $this->generateListAssign($lines, $subArr, 0, $item->vars, $subElemType, $itemSrcLiteral);
                $idx++;
                $entryIdx++;
                continue;
            }
            // 普通变量（varName 转义 C 关键字，如 $default → _default）
            $var = self::varName('$' . $item);
            $isDeclared = isset($this->declaredVars[$var]);
            $this->declaredVars[$var] = true;
            $this->varTypes[$var] = $itemElemType;
            $prefix = $isDeclared ? '' : ($itemCType . ' ');
            $zeroVal = match ($itemElemType) {
                't_string'   => '(t_string){0}',
                't_float'    => '0.0',
                't_array*'   => 'NULL',
                't_callback' => 'NULL',
                't_var'      => 'VAR_NULL()',
                default      => '0',  // t_int, t_bool
            };
            // t_var 元素：直接解引用 t_var*（array<mixed> 元素本身就是 t_var）
            if ($itemElemType === 't_var') {
                $lines[] = "{$prefix}{$var} = ({$arrName} && {$arrName}->length > {$idx}) ? *tphp_fn_arr_index({$arrName}, {$idx}) : {$zeroVal};";
            } else {
                $lines[] = "{$prefix}{$var} = ({$arrName} && {$arrName}->length > {$idx}) ? tphp_fn_arr_item_{$getterSuffix}({$arrName}, {$idx}) : {$zeroVal};";
            }
            $idx++;
            $entryIdx++;
        }
    }

    /** tphp 类型 → C 类型名（用于变量声明） */

    private function generateAssignWithOrBlock(AssignStmtNode $node): string
    {
        $orBlock = $node->expr;
        $innerExpr = $orBlock->expr;
        $var = self::varName($node->varName);
        $isDeclared = isset($this->declaredVars[$var]);
        $prevType = $this->varTypes[$var] ?? '';
        $isTVar = ($prevType === 't_var');

        // 推导内部表达式类型 + 生成内部代码
        $innerType = $this->inferType($innerExpr);
        $innerCode = $innerExpr->accept($this);
        $this->declaredVars[$var] = true;

        $lines = [];

        // 变量声明必须在 TP_TRY 之前 — TP_TRY 宏展开为 do { if(setjmp()==0){...} ... } while(0)，
        // 声明在 if 块内部时作用域不延伸到 TP_END_TRY 之后，导致后续无法访问变量。
        // 首次声明：在 TP_TRY 之前零初始化声明；TP_TRY 内部仅做赋值。
        if (!$isDeclared) {
            if ($node->type !== null) {
                $cType = self::mapType($node->type);
            } else {
                $cType = $innerType;
            }
            $this->varTypes[$var] = $cType;

            // t_var 变量：值需包装为 VAR_XXX 宏
            if ($cType === 't_var') {
                if ($this->scopeDepth > 0) {
                    $this->funcScopeDecls[$var] = $cType;
                } else {
                    $lines[] = $this->ind("{$cType} {$var} = {0};");
                }
                $wrap = $this->wrapTvarAssign($innerExpr, $innerCode);
                $assignLine = "{$var} = {$wrap};";
            } elseif (str_starts_with($cType, 'tphp_class_') && str_ends_with($cType, '*')) {
                // 对象指针类型：注册到全局资源表（自动析构）
                if ($this->indent == 1) {
                    $this->symbols->addScopeObject($var);
                }
                if ($this->scopeDepth > 0) {
                    $this->funcScopeDecls[$var] = $cType;
                } else {
                    $lines[] = $this->ind("{$cType} {$var} = NULL;");
                }
                $assignLine = "{$var} = {$innerCode}; tphp_rt_register((void*){$var}, 0);";
            } else {
                // 标量 / t_string / t_array* 等
                if ($this->scopeDepth > 0) {
                    $this->funcScopeDecls[$var] = $cType;
                } else {
                    $zeroInit = str_ends_with($cType, '*') ? 'NULL' : '{0}';
                    $lines[] = $this->ind("{$cType} {$var} = {$zeroInit};");
                }
                $assignLine = "{$var} = {$innerCode};";
            }
        } elseif ($isTVar) {
            // 已声明的 t_var 变量：仅赋值
            $wrap = $this->wrapTvarAssign($innerExpr, $innerCode);
            $assignLine = "{$var} = {$wrap};";
        } else {
            // 已声明变量：仅赋值
            $assignLine = "{$var} = {$innerCode};";
        }

        // 注册 $err 为 Exception* 类型（供 or 块内 $err->getMessage() 等访问）
        $this->declaredVars['err'] = true;
        $this->varTypes['err'] = 'tphp_class_Exception*';

        // 生成 or 块内语句
        $this->scopeDepth++;
        $orBodyLines = [];
        foreach ($orBlock->orBody as $stmt) {
            $orBodyLines[] = $this->ind($stmt->accept($this));
        }
        $this->scopeDepth--;

        // 拼装 TP_TRY/TP_CATCH_EX/TP_END_TRY
        $lines[] = $this->ind('TP_TRY');
        $this->scopeDepth++;
        $lines[] = $this->ind($assignLine);
        $this->scopeDepth--;
        $lines[] = $this->ind('TP_CATCH_EX(err, Exception)');
        $this->scopeDepth++;
        foreach ($orBodyLines as $line) {
            $lines[] = $line;
        }
        $this->scopeDepth--;
        $lines[] = $this->ind('TP_END_TRY');
        return implode("\n", $lines);
    }

    /**
     * 生成 (expr) or { ... }; 的 C 代码（语句级，无赋值目标）
     *
     * 等价于：
     *   TP_TRY {
     *       (void)<inner_expr>;
     *   }
     *   TP_CATCH_EX(err, Exception) {
     *       <or_body>
     *   }
     *   TP_END_TRY
     */

    public function visitArrayAccess(ArrayAccessExpr $node): string
    {
        // ── 注解常量静态索引编译期展开 ──
        // ROUTE[0] → _annot_ROUTE_0（AnnotationEntry* 指针，零开销）
        if ($node->array instanceof VariableExpr
            && !str_starts_with($node->array->name, '$')
            && $node->index instanceof IntLiteralExpr
            && isset($this->annotationRegistry[$node->array->name])) {
            $reg = $this->annotationRegistry[$node->array->name];
            $idx = (int)$node->index->value;
            if (isset($reg['entries'][$idx])) {
                return $reg['entryVarPrefix'] . $idx;
            }
        }

        // ── 注解常量动态索引：ROUTE[$i] → 运行时从 t_var* 解包 AnnotationEntry* ──
        if ($node->array instanceof VariableExpr
            && !str_starts_with($node->array->name, '$')
            && isset($this->annotationRegistry[$node->array->name])) {
            $reg = $this->annotationRegistry[$node->array->name];
            $arrCode = $reg['constName'];
            $idxCode = $node->index->accept($this);
            return "((tphp_class_AnnotationEntry*)tphp_fn_arr_get_int_object({$arrCode}, (t_int)({$idxCode})))";
        }

        // ── 泛型数组快速路径：t_arr_int*/t_arr_str*/t_arr_float*/t_arr_bool*/t_arr_ptr* ──
        //   直接调用类型特化的 getter，避免 t_var 解包
        if ($node->array instanceof VariableExpr) {
            $vn = self::varName($node->array->name);
            $arrCType = $this->varTypes[$vn] ?? '';
            $genElemCType = self::genericArrayElemCType($arrCType);
            if ($genElemCType !== null) {
                $arr = $node->array->accept($this);
                $idx = $node->index->accept($this);
                // 从 t_arr_int* 提取 suffix: 'int'
                $suffix = substr($arrCType, 6, -1);  // t_arr_{suffix}*
                // 字符串键 → get_str，整数键 → get
                if ($node->index instanceof StringLiteralExpr) {
                    return "tphp_fn_arr_{$suffix}_get_str({$arr}, {$idx})";
                }
                $idxType = $this->inferType($node->index);
                if ($idxType === 't_string') {
                    return "tphp_fn_arr_{$suffix}_get_str({$arr}, {$idx})";
                }
                return "tphp_fn_arr_{$suffix}_get({$arr}, (t_int)({$idx}))";
            }
        }

        // 字符串字符访问 $str[$i]：变量类型为 t_string 时，用 substr 取单字符
        //   不能走数组 getter（tphp_fn_arr_get_int_str 期望 t_array*）
        if ($node->array instanceof VariableExpr
            && ($this->varTypes[self::varName($node->array->name)] ?? '') === 't_string'
            && !($node->index instanceof StringLiteralExpr)) {
            $arr = $node->array->accept($this);
            $idx = $node->index->accept($this);
            return "tphp_fn_substr({$arr}, (t_int)({$idx}), 1)";
        }

        $arr  = $node->array->accept($this);
        $idx  = $node->index->accept($this);
        $vn   = $node->array instanceof VariableExpr ? self::varName($node->array->name) : '';
        $vt   = $this->varTypes[$vn] ?? 't_int';
        // t_var 数组：元素类型为 t_var（保持 mixed 语义）
        //   - 直接变量 $sub（t_var）→ $sub[0] 元素为 t_var
        //   - 链式 $arr[0][1] 中内层为 t_var → 外层元素也为 t_var
        $isTVarArray = ($vt === 't_var');

        // t_var 变量持有数组：从 .value._array 提取指针
        //   场景：$sub = $mixed_arr[2]; $sub[0] — $sub 是 t_var，内含 TYPE_ARRAY
        //   运行时检查 type 标签；非数组时 getter 返回 0/NULL
        if ($isTVarArray) {
            $arr = "(({$arr}).value._array)";
        }

        // 链式访问：内部表达式可能返回非指针类型（如 t_int/t_var，源于混合数组的类型追踪局限），
        // 但 getter 函数期望 t_array* 参数。
        //   - t_var：从 .value._array 提取数组指针（运行时检查 type 标签），元素也是 t_var
        //   - 其他标量：添加显式 cast 满足 Clang 类型检查，运行时返回 0/NULL
        if ($node->array instanceof ArrayAccessExpr) {
            $innerType = $this->inferArrayAccessInnerType($node->array);
            // 检查内部访问的返回类型：若根数组是 array<mixed>，内层访问返回 t_var（持有数组）
            //   即使 innerType 是类指针（如 tphp_class_Item*），内部访问 $catalog[0] 仍返回 t_var
            //   需提取 .value._array 才能传给 typed getter
            [$rootArr] = $this->resolveRootArray($node->array);
            $rootElemIsTVar = ($rootArr !== '' && ($this->arrElementTypes[$rootArr] ?? null) === 't_var');
            if ($innerType === 't_var') {
                $arr = "(({$arr}).value._array)";
                $isTVarArray = true;
            } elseif ($rootElemIsTVar && str_contains($innerType, 'tphp_class_')) {
                // 嵌套对象数组的叶子层：内部访问返回 t_var，需提取 .value._array
                //   但不用设置 isTVarArray（元素是对象，用 typed object getter）
                $arr = "(({$arr}).value._array)";
            } elseif ($innerType !== 't_array*' && !str_contains($innerType, '*')) {
                $arr = "(t_array*)(intptr_t)({$arr})";
            }
        }

        // 字符串键：per-key 类型 → get_str_int/str；无记录用 get_str_str
        $idxType = $this->inferType($node->index);
        if ($idxType === 't_string' || $node->index instanceof StringLiteralExpr) {
            // per-key 类型追踪
            $keyType = $vt;
            // array<mixed>：直接走 t_var 路径，忽略 per-key 追踪
            //   适用于任意 string key（字面量或变量），保持 mixed 语义一致
            if ($node->array instanceof VariableExpr
                && ($this->arrElementTypes[self::varName($node->array->name)] ?? null) === 't_var') {
                $keyType = 't_var';
            } elseif ($node->array instanceof ArrayAccessExpr) {
                // array<mixed> 根数组：链式访问的所有层级元素统一为 t_var
                //   运行时元素存储为 t_var，typed getter 会返回标量导致 var_dump 等期望 t_var 的调用方报错
                [$rootArr, $depth] = $this->resolveRootArray($node->array);
                if ($rootArr !== '' && ($this->arrElementTypes[$rootArr] ?? null) === 't_var') {
                    $keyType = 't_var';
                } else {
                    // 链式访问 $arr[0]["key"]：优先用 AST 精确追踪，回退到嵌套类型
                    // 优先：通过数组字面量 AST 精确追踪嵌套访问的叶子值类型
                    // （处理混合类型关联数组：["id"=>42, "name"=>"foo"] 的 per-key 类型）
                    $traced = $this->traceNestedAccessType($node);
                    if ($traced !== null) {
                        $keyType = $traced;
                    } elseif ($rootArr !== '' && $depth > 0 && isset($this->arrNestedTypes[$rootArr])) {
                        $keyType = $this->arrNestedTypes[$rootArr];
                    }
                }
            }
            if ($node->index instanceof StringLiteralExpr && $node->array instanceof VariableExpr
                && $keyType !== 't_var') {
                $arrName = self::varName($node->array->name);
                $keyStr  = $node->index->value;
                // array<mixed>：直接走 t_var 路径，忽略 per-key 追踪
                //   （per-key 类型可能与 t_var 语义冲突：如 $d["vers"] = 2 后 arrValueTypes 记录 t_int，
                //    但 $d 是 array<mixed>，元素统一为 t_var，避免 wrapVar 误用 VAR_INT 包装 t_int）
                if (($this->arrElementTypes[$arrName] ?? null) === 't_var') {
                    $keyType = 't_var';
                } else {
                    $keyType = $this->arrValueTypes[$arrName][$keyStr] ?? null;
                    // 全局查找（如 $users = $db["users"] 后，$users["alice"] 跨变量查 alice 键类型）
                    if ($keyType === null) {
                        foreach ($this->arrValueTypes as $vKeys) {
                            if (isset($vKeys[$keyStr])) { $keyType = $vKeys[$keyStr]; break; }
                        }
                    }
                    // 未知字符串键：先查数组元素类型，再默认 string
                    // （arrElementTypes 比 varType 更精确：varType 可能是 t_array*）
                    $keyType ??= $this->arrElementTypes[$arrName] ?? 't_string';
                }
            } elseif ($node->array instanceof VariableExpr
                && !($node->index instanceof StringLiteralExpr)
                && $keyType !== 't_var') {
                // 动态字符串键（如 $a["key" . $i]）：arrValueTypes 无法追踪，但 arrElementTypes
                //   由 visitAssignArrayStmt 在赋值时记录（如 $a["key".$i] = $i → arrElementTypes['a']='t_int'）
                //   优先于 varType（t_array*）以生成正确的 typed getter（get_str_int 而非 get_str_arr）
                $arrName = self::varName($node->array->name);
                if (isset($this->arrElementTypes[$arrName])) {
                    $keyType = $this->arrElementTypes[$arrName];
                    // 标准化类/枚举类型（补 * 指针后缀）
                    if ((str_contains($keyType, 'tphp_class_') || str_contains($keyType, 'tphp_enum_'))
                        && !str_ends_with($keyType, '*')) {
                        $keyType .= '*';
                    }
                }
            } elseif ($node->array instanceof PropertyAccessExpr) {
                // 属性数组字符串键访问：$this->prop["key"] 或 $obj->prop["key"]
                //   查 propArrElementTypes 获取元素类型（与整数键分支 8395 行一致）
                //   未注册时默认 t_string（而非 t_int），因为关联数组通常存字符串值
                $propKey = $this->propArrElemKey($node->array);
                if ($propKey !== null && isset($this->propArrElementTypes[$propKey])) {
                    $keyType = $this->propArrElementTypes[$propKey];
                    if ((str_contains($keyType, 'tphp_class_') || str_contains($keyType, 'tphp_enum_')) && !str_ends_with($keyType, '*')) {
                        $keyType .= '*';
                    }
                } else {
                    $keyType = 't_string';
                }
            }
            return match ($keyType) {
                't_int'   => "tphp_fn_arr_get_str_int({$arr}, {$idx})",
                't_float' => "((t_float)tphp_fn_arr_get_str_int({$arr}, {$idx}))",
                't_bool'  => "(tphp_fn_arr_get_str_int({$arr}, {$idx}) != 0)",
                't_array*' => "tphp_fn_arr_get_str_arr({$arr}, {$idx})",
                't_var'   => "(*tphp_fn_arr_get_str({$arr}, {$idx}))",  // array<mixed> 字符串键 → t_var
                default   => "tphp_fn_arr_get_str_str({$arr}, {$idx})",
            };
        }

        // 整数键：先查 arrElementTypes（对象/回调），若未记录且 vt 是基本类型则用 vt，否则默认 int
        $et = 't_int';
        if ($node->array instanceof VariableExpr) {
            $an = self::varName($node->array->name);
            if (isset($this->arrElementTypes[$an])) {
                $et = $this->arrElementTypes[$an];
                // 标准化类/枚举类型（补 *）
                if ((str_contains($et, 'tphp_class_') || str_contains($et, 'tphp_enum_')) && !str_ends_with($et, '*')) {
                    $et .= '*';
                }
            } elseif (!in_array($vt, ['t_array*', 't_int', 'null'], true)) {
                // $vt 可能是 per-key 追踪的类型（如 t_string）/ 直接 varType
                $et = $vt;
            }
        } elseif ($node->array instanceof ArrayAccessExpr) {
            // 链式访问 $arr[0][0]：向上查找根数组的嵌套类型
            [$rootArr, $depth] = $this->resolveRootArray($node->array);
            if ($rootArr !== '' && $depth > 0 && isset($this->arrNestedTypes[$rootArr])) {
                // 多层嵌套：用 arrNestedDepth 判断当前深度是否到达叶子层
                if (isset($this->arrNestedDepth[$rootArr])) {
                    $nd = $this->arrNestedDepth[$rootArr];
                    // depth = 链式访问的层数（$arr[0] depth=1, $arr[0][1] depth=2, ...）
                    // nd['depth'] = 数组总深度（[1,2,3] depth=1, [[1,2]] depth=2, ...）
                    // 当 depth == nd['depth']-1 时，到达叶子层
                    if ($depth >= $nd['depth'] - 1) {
                        $et = $nd['leafType'];
                    } else {
                        $et = 't_array*';  // 中间层仍是数组
                    }
                } else {
                    $et = $this->arrNestedTypes[$rootArr];
                }
                // 标准化类/枚举类型（补 * 指针后缀）
                if ((str_contains($et, 'tphp_class_') || str_contains($et, 'tphp_enum_')) && !str_ends_with($et, '*')) {
                    $et .= '*';
                }
            }
        } elseif ($node->array instanceof PropertyAccessExpr) {
            // 实例属性数组访问：$this->prop[$key] 或 $obj->prop[$key]
            //   查 propArrElementTypes 注册表获取元素类型
            $key = $this->propArrElemKey($node->array);
            if ($key !== null && isset($this->propArrElementTypes[$key])) {
                $et = $this->propArrElementTypes[$key];
                if ((str_contains($et, 'tphp_class_') || str_contains($et, 'tphp_enum_')) && !str_ends_with($et, '*')) {
                    $et .= '*';
                }
            } else {
                // 未注册元素类型的 array 属性（如 public array $nums = []）：
                //   回退到 TypeChecker 的 inferredType，若为 mixed 则元素是 t_var
                //   避免 inferType 推导 t_var 但生成 typed getter 导致类型不匹配
                $inferred = $node->inferredType !== null ? $this->inferredTypeToCType($node->inferredType) : null;
                if ($inferred === 't_var') {
                    $et = 't_var';
                } elseif ($inferred === 'void*') {
                    // TypeChecker 推导为 object（IDX_OBJECT → void*），使用 object getter
                    // 避免 defaulted 到 tphp_fn_arr_get_int_int 返回 0（NULL）导致崩溃
                    $et = 'void*';
                }
            }
        }
        // array<mixed> 通过 t_var 提取的数组：元素统一为 t_var，覆盖所有 typed getter 优化
        //   场景：$sub = $mixed_arr[0]; $sub[1]  或  $a[0][1]（$a[0] 是 t_var）
        //   原因：从 t_var.value._array 提取的是万能数组，元素类型为 t_var。
        //   若用 typed getter（返回 t_int/t_string 等），调用方（var_dump 等）期望 t_var 会报错。
        //   例外：t_var 持有对象/回调数组（arrElementTypes 记录类指针/t_callback）时，保留类型，
        //   使 visitArrayAccess 用 typed getter 提取对象/回调
        if ($isTVarArray && !str_contains($et, 'tphp_class_') && !str_contains($et, 'tphp_enum_') && $et !== 't_callback') {
            $et = 't_var';
        }
        return match ($et) {
            't_int'      => "tphp_fn_arr_get_int_int({$arr}, (t_int)({$idx}))",
            't_float'    => "tphp_fn_arr_get_int_float({$arr}, (t_int)({$idx}))",
            't_string'   => "tphp_fn_arr_get_int_str({$arr}, (t_int)({$idx}))",
            't_bool'     => "tphp_fn_arr_get_int_bool({$arr}, (t_int)({$idx}))",
            't_array*'   => "tphp_fn_arr_get_int_arr({$arr}, (t_int)({$idx}))",
            't_callback' => "tphp_fn_arr_get_int_callback({$arr}, (t_int)({$idx}))",
            't_var'      => "(*tphp_fn_arr_get_int({$arr}, (t_int)({$idx})))",  // array<mixed> 整数键 → t_var（key-based 支持稀疏键）
            'void*'      => "tphp_fn_arr_get_int_object({$arr}, (t_int)({$idx}))",  // TypeChecker 推导为 object 但未知具体类
            default      => (str_contains($et, 'tphp_class_') || str_contains($et, 'tphp_enum_'))
                ? "((" . $et . ")tphp_fn_arr_get_int_object({$arr}, (t_int)({$idx})))"
                : "tphp_fn_arr_get_int_int({$arr}, (t_int)({$idx}))",
        };
    }

    /** 展平 . 链为叶子节点数组，用于 ROPE 多片段拼接
     *  "a" . "b" . "c" → [StringLit("a"), StringLit("b"), StringLit("c")] */

    private function inferArrayAccessInnerType(ArrayAccessExpr $expr): string
    {
        // 优先：t_var 变量持有数组 → 元素统一为 t_var（运行时通过 VAR_ARRAY 包装）
        //   arrElementTypes 可能记录实际值类型（如 t_array*），但 t_var 数组的
        //   元素在 C 层都是 t_var，必须返回 t_var 才能正确生成 .value._array 提取
        if ($expr->array instanceof VariableExpr) {
            $arrName = self::varName($expr->array->name);
            if (($this->varTypes[$arrName] ?? '') === 't_var') {
                return 't_var';
            }
            if (isset($this->arrElementTypes[$arrName])) {
                $et = $this->arrElementTypes[$arrName];
                // array<mixed> 根数组（arrElementTypes='t_var'）但嵌套对象数组的叶子层：
                //   $catalog = [[$i1, $i2], [$i3]]; $catalog[0][0]->title
                //   $catalog[0] 的元素是 Item 对象，返回 tphp_class_Item* 使 visitArrayAccess
                //   用 typed object getter 提取对象指针
                if ($et === 't_var'
                    && isset($this->arrNestedTypes[$arrName])
                    && str_contains($this->arrNestedTypes[$arrName], 'tphp_class_')
                    && isset($this->arrNestedDepth[$arrName])) {
                    // $expr 深度：$catalog[0] depth=1, $catalog[0][1] depth=2
                    [, $exprDepth] = $this->resolveRootArray($expr);
                    $nd = $this->arrNestedDepth[$arrName];
                    // 元素深度 = exprDepth + 1，叶子层判断：exprDepth + 1 >= nd['depth']
                    if ($exprDepth + 1 >= $nd['depth']) {
                        $leafType = $this->arrNestedTypes[$arrName];
                        if (!str_ends_with($leafType, '*')) $leafType .= '*';
                        return $leafType;
                    }
                    // 中间层：返回 t_var（运行时持有子数组）
                    return 't_var';
                }
                return $et;
            }
        }
        // 链式访问 $arr[0][1]：优先用 traceNestedAccessType 精确追踪叶子类型
        //   TypeChecker 的 inferredType 字段对 array<mixed> 元素统一标记为 mixed，
        //   但实际叶子可能是 t_array*（中间层）或 t_int（叶子层），
        //   需从数组字面量 AST 精确推导，否则会误加 .value._array
        if ($expr->array instanceof ArrayAccessExpr) {
            // array<mixed> 根数组：所有层级元素统一为 t_var（运行时持有数组/标量）
            //   中间层虽含数组，但 C 代码生成走 *tphp_fn_arr_index 路径返回 t_var，
            //   不能返回 t_array*（否则 visitArrayAccess 不会提取 .value._array）
            //   例外：若 arrNestedTypes 记录了类对象叶子类型（如 [[$obj1],[$obj2]]），
            //   返回类指针类型，使 visitArrayAccess 用 typed object getter 提取对象
            [$rootArr, $depth] = $this->resolveRootArray($expr->array);
            if ($rootArr !== '' && ($this->arrElementTypes[$rootArr] ?? null) === 't_var') {
                // 检查是否为嵌套对象数组的叶子层
                if (isset($this->arrNestedTypes[$rootArr])
                    && str_contains($this->arrNestedTypes[$rootArr], 'tphp_class_')) {
                    $nd = $this->arrNestedDepth[$rootArr] ?? null;
                    // depth 是 $expr->array 的深度（$arr[0] depth=1, $arr[0][1] depth=2）
                    // 调用方传入的 $expr 是 $arr[0][1]，$expr->array 是 $arr[0]（depth=1）
                    // 叶子层判断：depth >= nd['depth'] - 1
                    if ($nd !== null && $depth >= $nd['depth'] - 1) {
                        $leafType = $this->arrNestedTypes[$rootArr];
                        if (!str_ends_with($leafType, '*')) $leafType .= '*';
                        return $leafType;
                    }
                }
                return 't_var';
            }
            $traced = $this->traceNestedAccessType($expr);
            if ($traced !== null) return $traced;
            // 回退：arrNestedDepth 判断中间层/叶子层
            if ($rootArr !== '' && $depth > 0 && isset($this->arrNestedDepth[$rootArr])) {
                $nd = $this->arrNestedDepth[$rootArr];
                if ($depth >= $nd['depth'] - 1) {
                    return $nd['leafType'];
                }
                return 't_array*';  // 中间层仍是数组
            }
        }
        // 回退：inferType（含 TypeChecker inferredType + 其他追踪逻辑）
        return $this->inferType($expr);
    }

    /**
     * 生成数组函数参数的 C 代码，统一输出 t_array*。
     *
     * 三种来源的处理：
     *   1. t_var 变量（array<mixed> 元素）：从 .value._array 提取 t_array*
     *   2. 显式 array<T> 变量（t_arr_int, t_arr_str, t_arr_float, t_arr_bool 指针）：
     *      调用 tphp_fn_arr_{T}_to_var() 协变转换为 t_array*（O(n) 重新包装元素为 t_var）
     *   3. t_array* 变量：直接使用，无需转换
     *
     * 设计权衡：内置数组函数（count/array_push/array_map/...）统一接收 t_array*，
     *   避免为每种 T 特化函数。转换仅在函数调用边界发生，热循环内的直接访问
     *   （$arr[$i]）仍走特化快路径，不受影响。
     *
     * @param ExprNode $expr 参数表达式
     * @param string   $code 参数表达式的 C 代码（$expr->accept($this)）
     * @return string 转换后的 C 代码（输出 t_array*）
     */

}
