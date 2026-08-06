<?php

declare(strict_types=1);

// ============================================================
// CodeGenCallTrait — 函数/方法调用相关方法
// 从 CodeGenerator.php 提取
// ============================================================
trait CodeGenCallTrait {

    private function emitMethodCallableConvert(CallableConvertExpr $node): array
    {
        $callee = $node->callee;
        // 推导对象类型和类 C 名
        $objCode = $callee->accept($this);
        $objType = $this->inferType($callee);

        // 静态方法引用 Class::method(...)
        //   callee 是 VariableExpr('ClassName')，但类型不是对象指针
        $isStaticRef = ($callee instanceof VariableExpr
            && !str_starts_with($callee->name, '$')
            && $objType !== 'null'
            && !str_ends_with($objType, '*'));

        if ($isStaticRef) {
            // 静态方法：直接用 ClassName_method，env=NULL
            $className = $callee->name;
            $cn = $this->symbols->resolveClass($className) ?? ('tphp_class_' . $className);
            $methodCName = "{$cn}_{$node->name}";
            // 注册签名
            $mInfo = $this->symbols->getClassMethod($cn, $node->name);
            if ($mInfo !== null) {
                $this->symbols->addClosureSig($methodCName, [
                    'ret'    => $mInfo->retType,
                    'params' => implode(', ', $mInfo->paramTypes),
                ]);
            }
            return ["(t_callback){ .func = (void*){$methodCName}, .env = NULL }", $methodCName];
        }

        // 实例方法：$obj->method(...) — 需要 thunk 包装
        // 推导类 C 名
        $cn = '';
        if (str_ends_with($objType, '*') && str_starts_with($objType, 'tphp_class_')) {
            $cn = rtrim($objType, '*');
        } elseif (str_starts_with($objType, 'tphp_class_')) {
            $cn = $objType;
        }
        if ($cn === '') {
            throw new \RuntimeException(
                "First-class callable on method requires known object type, got '{$objType}'"
            );
        }

        // 查询方法签名
        $mInfo = $this->symbols->getClassMethod($cn, $node->name);
        $retType = $mInfo?->retType ?? 't_int';
        $paramTypes = $mInfo?->paramTypes ?? [];

        // 生成 thunk 函数：包装 ClassName_method(self, args) 调用
        $id = ++$this->closureCounter;
        $thunkName = "_method_thunk_{$id}";
        $capName = "_cap_{$id}";

        // thunk 参数列表：原方法参数 + void* _env
        $thunkParams = [];
        foreach ($paramTypes as $pt) {
            $thunkParams[] = "{$pt} p" . count($thunkParams);
        }
        $thunkParams[] = "void* _env";
        $thunkParamStr = implode(', ', $thunkParams);

        // thunk body: 从 env 取 self，调用 ClassName_method(self, args)
        $argNames = [];
        for ($i = 0; $i < count($paramTypes); $i++) {
            $argNames[] = "p{$i}";
        }
        $callArgs = implode(', ', array_merge(['_e->self'], $argNames));
        $methodCName = "{$cn}_{$node->name}";

        $thunkLines = [
            "static {$retType} {$thunkName}({$thunkParamStr}) {",
            "    {$capName}* _e = ({$capName}*)_env;",
            "    return {$methodCName}({$callArgs});",
            "}",
        ];

        // 文件作用域前置声明：使 thunk 在 statement expression 中可见
        //   GCC/Clang 要求 static 函数声明与定义 storage class 一致；
        //   而函数原型不允许在 statement expression 内部声明为 static，
        //   因此放在文件作用域（SEC_FWDDECLS），三编译器都兼容。
        $this->sectionLine(self::SEC_FWDDECLS, "static {$retType} {$thunkName}({$thunkParamStr});");

        // 注册捕获 struct（仅含 self 指针 + dtor）
        $capFields = [
            "    void (*dtor)(void*);",
            "    {$cn}* self;",
        ];

        // 生成 dtor（self 是对象指针，需要 release）
        $dtorName = "_env_dtor_{$id}";
        $this->sectionLine(self::SEC_FWDDECLS, "static void {$dtorName}(void* env);");
        $dtorLines = [
            "static void {$dtorName}(void* env) {",
            "    {$capName}* e = ({$capName}*)env;",
            "    if (e->self) tp_obj_release((void*)e->self);",
            "}",
        ];

        $this->sectionBlock(self::SEC_CLOSURES, implode("\n", $thunkLines) . "\n" . implode("\n", $dtorLines));

        // 注册捕获 struct 定义
        $capDef = "typedef struct {\n" . implode("\n", $capFields) . "\n} {$capName};";
        $this->sectionBlock(self::SEC_CAPTYPES, $capDef);

        // 注册闭包签名（thunk 的签名）
        $this->symbols->addClosureSig($thunkName, [
            'ret'    => $retType,
            'params' => implode(', ', $paramTypes),
        ]);

        // 生成 GNU 复合表达式：分配 env，捕获 self，构造 t_callback
        $envDecl = "    {$capName}* _env_{$id} = ({$capName}*)calloc(1, sizeof({$capName}));\n"
            . "    if (_env_{$id} != NULL) {\n"
            . "        _env_{$id}->dtor = {$dtorName};\n"
            . "        _env_{$id}->self = {$objCode}; tp_obj_retain((void*){$objCode});\n"
            . "    }\n"
            . "    tphp_rt_register((void*)_env_{$id}, 5);\n"
            . "    (t_callback){ .func = (void*){$thunkName}, .env = _env_{$id} };";

        // 注意：statement expression 内不声明原型（已在文件作用域前置声明为 static）
        //   GCC/Clang 不允许在 statement expression 内声明 static 函数原型；
        //   TCC 对此规则宽松，但为了三编译器兼容统一在文件作用域前置声明。
        return ["({\n{$envDecl}\n  })", $thunkName];
    }

    /**
     * 注册 callback 变量到 varClosureMap
     *   使后续 $cb(...) 调用能通过 generateClosureCall 查到签名
     *   使用唯一的虚拟变量名（避免与真实变量冲突）
     */

    private function generateSimpleForward(CallExpr $node, array $info): string
    {
        // 0-arg 变体（如 uniqid → uniqid0）
        if (count($node->args) === 0 && isset($info['cNameNoArgs'])) {
            return $info['cNameNoArgs'] . '()';
        }

        // microtime(true) — C 函数 tphp_fn_microtime(void) 始终返回 float
        //   忽略参数（PHP 语义：仅当 $as_float=true 时返回 float，此处等价实现）
        if ($node->name === 'microtime') {
            return $info['cName'] . '()';
        }

        // array_unshift 是原地修改函数，拒绝 array<T>（转换会丢失修改）
        if ($node->name === 'array_unshift' && $this->isGenericArrayVar($node->args[0])) {
            throw new \RuntimeException(
                "array_unshift() cannot modify a typed array<T> variable in-place (conversion would lose changes). "
                . "Use '\$arr = [\$val, ...\$arr]' or declare as array<mixed>."
            );
        }

        // sort/rsort/shuffle 对 array<T> 原地修改：直接调用特化函数
        //   tphp_fn_arr_{suffix}_sort / _rsort / _shuffle 直接操作 t_arr_int/t_arr_str/...
        //   避免 arrayArgCode 协变转换为临时 t_array* 后修改丢失。
        //   仅处理标量元素（int/str/float/bool）；t_arr_ptr* 走通用路径。
        $typedInplaceFns = ['sort' => 'sort', 'rsort' => 'rsort', 'shuffle' => 'shuffle'];
        if (isset($typedInplaceFns[$node->name])
            && isset($node->args[0]) && $node->args[0] instanceof VariableExpr) {
            $vn = self::varName($node->args[0]->name);
            $vt = $this->varTypes[$vn] ?? '';
            if (preg_match('/^t_arr_(int|str|float|bool)\*$/', $vt, $m)) {
                $argCode = $node->args[0]->accept($this);
                $fn = $typedInplaceFns[$node->name];
                return "tphp_fn_arr_{$m[1]}_{$fn}({$argCode})";
            }
        }

        // count($arr, $mode) — 第二参数为 COUNT_RECURSIVE 时切换到递归版本
        if (($info['dispatch'] ?? null) === 'count') {
            $arrCode = $this->arrayArgCode($node->args[0], $node->args[0]->accept($this));
            if (isset($node->args[1])) {
                $modeCode = $node->args[1]->accept($this);
                return "(($modeCode) == 1 ? tphp_fn_arr_count_recursive($arrCode) : tphp_fn_arr_count($arrCode))";
            }
            return "tphp_fn_arr_count($arrCode)";
        }

        // array_keys($arr, $search) — 有第二参数时切换到 search 版本
        if (($info['dispatch'] ?? null) === 'array_keys') {
            $arrCode = $this->arrayArgCode($node->args[0], $node->args[0]->accept($this));
            if (isset($node->args[1])) {
                $searchCode = $this->wrapVar($node->args[1]);
                return "tphp_fn_array_keys_search($arrCode, $searchCode)";
            }
            return "tphp_fn_array_keys($arrCode)";
        }

        // array_diff/array_intersect 变参支持（variadic_n dispatch）：
        //   PHP 原生签名：array_diff(array $base, array ...$others): array
        //   2 参数：走原 direct 路径 tphp_fn_arr_diff(a1, a2)（零开销）
        //   3+ 参数：第 1 参数单独传，第 2+ 参数打包为 t_array*（元素为 VAR_ARRAY）
        //   调用 tphp_fn_arr_diff_n(base, packed_others) — 单次分配、单次遍历，符合 PHP 语义
        //   元素类型追踪由 visitAssign 的 case 'array_diff'/'array_intersect' 从第一个源数组推导。
        if (isset($info['variadic_n']) && count($node->args) > 2) {
            $nName = $info['variadic_n'];
            // 第 1 参数：协变转换为 t_array*
            $baseCode = $this->arrayArgCode($node->args[0], $node->args[0]->accept($this));
            // 第 2+ 参数：每个协变转换为 t_array* 后包装为 VAR_ARRAY，打包成一个 t_array*
            $nPack = count($node->args) - 1;
            $tmpArr = '_vn_' . (++$this->tmpVarCounter);
            $code = "({ t_array* {$tmpArr} = tphp_fn_arr_create({$nPack}); tphp_rt_register((void*){$tmpArr}, 1);";
            for ($i = 1; $i < count($node->args); $i++) {
                $argArr = $this->arrayArgCode($node->args[$i], $node->args[$i]->accept($this));
                $code .= " {$tmpArr} = tphp_fn_arr_push({$tmpArr}, VAR_ARRAY({$argArr}));";
            }
            $code .= " {$nName}({$baseCode}, {$tmpArr}); })";
            return $code;
        }

        // max/min variadic 形式：多参数时打包成数组调用 tphp_fn_max/min(arr)
        // max(1, 2, 3) → ({ t_array* _t = arr_create(3); push(_t, 1); push(_t, 2); push(_t, 3); tphp_fn_max(_t); })
        if (($info['dispatch'] ?? null) === 'variadic_pack') {
            $nArgs = count($node->args);
            $cName = $info['cName'];
            if ($nArgs <= 1) {
                $arrCode = $this->arrayArgCode($node->args[0], $node->args[0]->accept($this));
                return "{$cName}({$arrCode})";
            }
            // 多参数：打包成数组
            $tmpArr = '_vp_' . (++$this->tmpVarCounter);
            $code = "({ t_array* {$tmpArr} = tphp_fn_arr_create({$nArgs}); tphp_rt_register((void*){$tmpArr}, 1);";
            foreach ($node->args as $arg) {
                $v = $this->wrapVar($arg);
                $code .= " {$tmpArr} = tphp_fn_arr_push({$tmpArr}, {$v});";
            }
            $code .= " {$cName}({$tmpArr}); })";
            return $code;
        }

        $modes    = $info['modes'] ?? [];
        $defaults = $info['defaults'] ?? [];
        $order    = $info['order'] ?? null;
        $nArgs    = count($node->args);
        // modes 非空时限制最大输出参数数；为空表示变长（不限制）
        $maxArgs  = !empty($modes) ? count($modes) : PHP_INT_MAX;

        // 输出位置数 = min(实参数, maxArgs)，再用 defaults 延伸至填满默认值
        $nPositions = min($nArgs, $maxArgs);
        if (!empty($defaults)) {
            $maxDefaultPos = max(array_keys($defaults)) + 1;
            $nPositions = max($nPositions, min($maxDefaultPos, $maxArgs));
        }

        $processed = [];
        for ($i = 0; $i < $nPositions; $i++) {
            if (isset($node->args[$i])) {
                $arg  = $node->args[$i];
                $mode = $modes[$i] ?? 'direct';
                $processed[$i] = match ($mode) {
                    // direct: 标量/字符串原样传递；array<T> 变量自动协变转换为 t_array*
                    'direct'    => $this->arrayArgCode($arg, $arg->accept($this)),
                    'data'      => $arg->accept($this) . '.data',
                    'floatcast' => '(t_float)(' . $arg->accept($this) . ')',
                    'wrapvar'   => $this->wrapVar($arg),
                    'wraparr'   => $this->wrapArrayElement($arg, $arg->accept($this)),
                    default     => $arg->accept($this),
                };
            } elseif (isset($defaults[$i])) {
                $processed[$i] = $defaults[$i];
            }
        }

        // 参数重排（如 array_search: PHP(needle,arr) → C(arr,needle)）
        if ($order !== null) {
            $ordered = [];
            foreach ($order as $pos) {
                if (isset($processed[$pos])) $ordered[] = $processed[$pos];
            }
            $processed = $ordered;
        }

        return $info['cName'] . '(' . implode(', ', $processed) . ')';
    }

    /**
     * 命名参数映射：将命名参数按函数/方法签名重排到正确位置
     *
     * PHP 8.0+ 命名参数语义：
     *   foo(b: 2, a: 1)            → foo(1, 2)         （用户函数，按签名顺序）
     *   $obj->m(y: 2, x: 1)        → $obj->m(1, 2)     （方法，按签名顺序）
     *   str_replace(subject: $s, search: 'a', replace: 'b') → str_replace('a', 'b', $s)
     *
     * 规则：
     *   1. 位置参数在前，命名参数在后（PHP 语义强制）
     *   2. 命名参数按签名 paramNames 中的位置填入对应槽位
     *   3. 未提供的参数（有默认值）保持缺失，重载选择会处理
     *   4. 找不到签名时不动（保守处理，让后续逻辑按位置生成）
     *
     * 直接修改 $node->args/$argNames（已移除 readonly），避免破坏 visitCall 内部对 $node->args 的所有引用。
     */
    private function reorderNamedArgs(CallExpr $node): void
    {
        // 没有命名参数 → 直接返回
        $hasNamed = false;
        foreach ($node->argNames as $n) {
            if ($n !== '') { $hasNamed = true; break; }
        }
        if (!$hasNamed) return;

        // 查找参数名列表（来自函数/方法签名）
        $paramNames = $this->resolveCallParamNames($node);
        if (empty($paramNames)) return;  // 未知签名，保守不动

        // 构建重排后的 args 和 argNames
        //   $positional[i] 表示第 i 个位置参数（按出现顺序）
        //   $named[name] => argExpr 表示命名参数
        $positional = [];
        $named = [];
        foreach ($node->args as $i => $arg) {
            $name = $node->argNames[$i] ?? '';
            if ($name === '') {
                $positional[] = $arg;
            } else {
                $named[$name] = $arg;
            }
        }

        // 按签名 paramNames 顺序填入参数：
        //   - 位置参数按顺序占用前 N 个槽位
        //   - 命名参数按名字填入对应槽位
        //   - 未提供且未填入的槽位跳过（让后续重载选择处理）
        $nameToIdx = array_flip($paramNames);
        $reordered = [];
        $posIdx = 0;
        $totalSlots = count($paramNames);
        for ($slot = 0; $slot < $totalSlots; $slot++) {
            $slotName = $paramNames[$slot];
            if (isset($named[$slotName])) {
                // 命名参数填入此槽位
                $reordered[] = $named[$slotName];
                unset($named[$slotName]);
            } elseif ($posIdx < count($positional)) {
                // 位置参数按顺序填入
                $reordered[] = $positional[$posIdx++];
            }
            // else: 未提供的参数（有默认值），跳过
        }

        // 未匹配的命名参数：抛错（PHP 语义：Unknown named parameter）
        if (!empty($named)) {
            $unknown = implode(', ', array_keys($named));
            throw new \RuntimeException(sprintf(
                "[%d:%d] Unknown named parameter \$%s for %s()",
                $node->line, $node->column, $unknown, $node->name
            ));
        }

        // 剩余位置参数追加到末尾（变参场景）
        while ($posIdx < count($positional)) {
            $reordered[] = $positional[$posIdx++];
        }

        // 直接赋值（CallExpr::$args/$argNames 已移除 readonly，支持命名参数重排）
        $node->args = $reordered;
        $node->argNames = array_fill(0, count($reordered), '');
    }

    /**
     * 构造函数命名参数重排（NewExpr）
     * 与 reorderNamedArgs 类似，但从 __construct 方法签名解析 paramNames
     */

    private function resolveCallParamNames(CallExpr $node): array
    {
        // 全局函数（含命名空间）
        if ($node->callee === null && !$node->isRawC) {
            $fnCName = self::funcCNameFromCall($node);
            $fn = $this->symbols->getFunc($fnCName);
            if ($fn !== null && !empty($fn->paramNames)) return $fn->paramNames;
            // 命名空间 fallback
            if (($pos = strrpos($node->name, '\\')) !== false) {
                $baseName = substr($node->name, $pos + 1);
                $fn = $this->symbols->getFunc('tphp_fn_' . $baseName);
                if ($fn !== null && !empty($fn->paramNames)) return $fn->paramNames;
            }
            // 内置函数参数名映射表
            $baseName = ($pos = strrpos($node->name, '\\')) !== false
                ? substr($node->name, $pos + 1) : $node->name;
            return self::$builtinFnParamNames[$baseName] ?? [];
        }

        // C 函数（C->func()）：使用声明的 C 函数签名
        if ($node->isRawC) {
            $cFn = $this->symbols->getCFunction($node->name);
            if ($cFn !== null) return $cFn->paramNames;
            return [];
        }

        // 闭包调用 $var(...)：暂不支持命名参数
        if ($node->callee instanceof VariableExpr
            && str_starts_with($node->callee->name, '$')
            && $node->name === '__invoke') {
            return [];
        }

        // 方法调用 $obj->method() / Class::method()
        if ($node->callee !== null) {
            // 推导 callee 类型对应的类 C 名
            $cn = '';
            if ($node->callee instanceof VariableExpr) {
                $vn = self::varName($node->callee->name);
                if (in_array($node->callee->name, ['self', 'static'], true)) {
                    $cn = $this->className;
                } elseif ($node->callee->name === 'parent') {
                    $parentPhp = $this->lookupParentClass($this->phpClassName);
                    $cn = $parentPhp !== null ? self::classRefName($parentPhp) : $this->className;
                } else {
                    $raw = $this->varTypes[$vn] ?? $vn;
                    $cn = str_contains($raw, '\\') ? self::classRefName($raw) : $raw;
                }
            } elseif ($node->callee instanceof CallExpr) {
                $cn = rtrim($this->inferCallChainClass($node->callee), '*');
            } elseif ($node->callee instanceof ArrayAccessExpr
                || $node->callee instanceof PropertyAccessExpr) {
                $cn = rtrim($this->inferType($node->callee), '*');
            } elseif ($node->callee instanceof EnumAccessExpr) {
                $cn = $this->symbols->getEnumCName($node->callee->enumName) ?? '';
            } else {
                $cn = (string)$node->callee->accept($this);
            }

            // 静态类名调用 Class::method() — callee 是 VariableExpr('ClassName')
            if ($node->callee instanceof VariableExpr
                && !str_starts_with($node->callee->name, '$')
                && !in_array($node->callee->name, ['self', 'static', 'parent'], true)) {
                $cnClean = rtrim($cn, '*');
                $resolved = $this->symbols->resolveClass($cnClean);
                if ($resolved !== null) $cnClean = $resolved;
                $cn = $cnClean;
            }

            $cnClean = rtrim($cn, '*');
            if ($cnClean === '') return [];

            // 解析方法签名（含父类继承）
            $m = $this->symbols->getClassMethod($cnClean, $node->name);
            if ($m === null) {
                $parentCN = $this->resolveMethodClass($cnClean, $node->name);
                if ($parentCN !== '') {
                    $m = $this->symbols->getClassMethod($parentCN, $node->name);
                }
            }
            // 枚举方法
            if ($m === null) {
                $enumCName = $this->symbols->resolveEnumCName($cnClean);
                if ($enumCName !== null) {
                    $m = $this->symbols->getEnumMethodByCName($enumCName, $node->name);
                }
            }
            if ($m !== null) return $m->paramNames;
            return [];
        }

        return [];
    }

    /**
     * 生成可变参数调用的实参列表
     *
     *   规则（参考 vlang 设计：编译期已知元素类型，零运行时类型擦除）：
     *   - 固定参数部分：正常生成 C 代码
     *   - 可变参数部分（最后一个形参位置）：
     *     * 若实参为 `...$arr` 展开形式 → 直接透传数组（零开销）
     *     * 若实参为多个独立值（1, 2, 3）→ 编译期打包为 t_array*（复用 variadic_pack 逻辑）
     *     * 若无可变实参 → 传空数组
     *
     * @param CallExpr $node 调用节点（含 spreads 标记）
     * @param int $variadicPos 可变参数在形参列表中的位置（即 count($fixedParams)）
     * @param array $pTypes 形参 C 类型列表（仅固定参数部分）
     * @return array{0:string[], 1:string} [0]=固定参数 C 代码列表, [1]=可变参数 C 代码（含打包逻辑）
     */
    private function generateVariadicArgs(CallExpr $node, int $variadicPos, array $pTypes): array
    {
        $fixedArgs = [];
        $variadicArgs = [];
        $spreads = $node->spreads;
        // 确保 spreads 数组长度与 args 对齐（旧 AST 可能无 spreads）
        while (count($spreads) < count($node->args)) $spreads[] = false;

        foreach ($node->args as $i => $arg) {
            if ($i < $variadicPos) {
                $fixedArgs[] = $arg;
            } else {
                $variadicArgs[] = ['expr' => $arg, 'spread' => $spreads[$i]];
            }
        }

        // 生成固定参数 C 代码
        $fixedCodes = [];
        foreach ($fixedArgs as $i => $arg) {
            $ct = $pTypes[$i] ?? '';
            $isParamByRef = $this->isByRefType($ct);
            if ($isParamByRef && $arg instanceof VariableExpr) {
                $avn = self::varName($arg->name);
                if ($this->isByRefType($this->varTypes[$avn] ?? '')) {
                    $fixedCodes[] = $avn;
                } else {
                    $fixedCodes[] = '&' . self::varName($arg->name);
                }
            } else {
                $aCode = $arg->accept($this);
                $fixedCodes[] = $isParamByRef ? '&' . $aCode : $aCode;
            }
        }

        // 生成可变参数 C 代码
        if (empty($variadicArgs)) {
            // 无可变实参：传空数组
            $variadicCode = 'tphp_fn_arr_create(0)';
        } else {
            // 检测 spread 形式：若最后一个实参是 spread 且只有一个可变实参 → 直接透传
            if (count($variadicArgs) === 1 && $variadicArgs[0]['spread']) {
                $variadicCode = $variadicArgs[0]['expr']->accept($this);
            } else {
                // 编译期打包为 t_array*（与 max/min variadic_pack 逻辑一致）
                $nArgs = count($variadicArgs);
                $tmpArr = '_vp_' . (++$this->tmpVarCounter);
                $code = "({ t_array* {$tmpArr} = tphp_fn_arr_create({$nArgs}); tphp_rt_register((void*){$tmpArr}, 1);";
                foreach ($variadicArgs as $va) {
                    if ($va['spread']) {
                        // spread 在多实参中：合并数组元素（编译期循环展开）
                        //   $arr 的元素逐个 push 到 _vp_N
                        $arrCode = $va['expr']->accept($this);
                        $iterVar = '_vpi_' . (++$this->tmpVarCounter);
                        $code .= " for (t_int {$iterVar} = 0; {$iterVar} < tphp_fn_count({$arrCode}); {$iterVar}++) {{ {$tmpArr} = tphp_fn_arr_push({$tmpArr}, tphp_fn_arr_get_int({$arrCode}, {$iterVar})); }}";
                    } else {
                        $v = $this->wrapVar($va['expr']);
                        $code .= " {$tmpArr} = tphp_fn_arr_push({$tmpArr}, {$v});";
                    }
                }
                $code .= " {$tmpArr}; })";
                $variadicCode = $code;
            }
        }

        return [$fixedCodes, $variadicCode];
    }


    private function inferCallChainClass(CallExpr $expr): string
    {
        if ($expr->callee === null) return '';
        if ($expr->callee instanceof VariableExpr) {
            $key = self::varName($expr->callee->name);
            // 枚举静态调用链：Color::from($v)->label() → callee=VariableExpr(Color)
            //   返回枚举 C 结构体名，供后续 emitEnumMethodCall 识别
            $enumCName = $this->symbols->resolveEnumCName($key);
            if ($enumCName !== null) return $enumCName;
            // 枚举名（FQN 或短名）→ C 结构体名
            $enumCName = $this->symbols->getEnumCName($expr->callee->name);
            if ($enumCName !== null) return $enumCName;
            // 静态方法调用链：Color::green()->toUint() → callee=VariableExpr(Color)
            //   VariableExpr 的 name 是类名（非 $var），查 resolveClass 获取 C 类名
            $resolved = $this->symbols->resolveClass($expr->callee->name);
            if ($resolved !== null) return rtrim($resolved, '*');
            return $this->varTypes[$key] ?? '';
        }
        if ($expr->callee instanceof CallExpr) {
            // 嵌套调用：用返回类型推导
            return rtrim($this->inferCallReturnType($expr->callee), '*');
        }
        if ($expr->callee instanceof EnumAccessExpr) {
            // Color::Red->method()->chain() → enum 实例类型
            return $this->symbols->getEnumCName($expr->callee->enumName) ?? '';
        }
        return '';
    }

    /** 生成 var_dump 调用：将参数包装为 t_var */
    private function generateVarDump(array $args): string
    {
        $wrapped = [];
        foreach ($args as $arg) {
            $wrapped[] = $this->wrapVar($arg);
        }
        return 'tphp_fn_var_dump(' . implode(', ', $wrapped) . ')';
    }

    /** 生成 var_export 调用：将表达式转为可读字符串并 echo */
    private function generateVarExport(array $args): string
    {
        $parts = [];
        foreach ($args as $arg) {
            if ($arg instanceof BoolLiteralExpr) {
                $parts[] = 'tphp_fn_echo(' . ($arg->value ? 'STR_LIT("true")' : 'STR_LIT("false")') . ')';
            } elseif ($arg instanceof CastExpr && $arg->castType === 'bool') {
                $code = $arg->accept($this);
                $parts[] = 'tphp_fn_echo(' . $code . ' ? STR_LIT("true") : STR_LIT("false"))';
            } else {
                // 默认 var_dump 行为
                $parts[] = 'tphp_fn_var_dump(' . $this->wrapVar($arg) . ')';
            }
        }
        return implode('; ', $parts);
    }

    /** 生成闭包调用: ((t_int(*)(t_int,...))var.func)(args) */
    private function generateClosureCall(ExprNode $callee, array $args): string
    {
        $calleeCode = $callee->accept($this);
        $argCodes = array_map(fn($a) => $a->accept($this), $args);
        $argStr = implode(', ', $argCodes);

        // mixed (t_var) 回调通过 value._callback 访问 func/env 字段；
        // t_callback 直接访问 .func/.env
        // 场景：$cb = $this->onClick; $cb(); — onClick 是 mixed 属性，存储为 t_var
        $calleeType = $this->inferType($callee);
        if ($calleeType === 't_var') {
            $cbFunc = "{$calleeCode}.value._callback.func";
            $cbEnv = "{$calleeCode}.value._callback.env";
        } else {
            $cbFunc = "{$calleeCode}.func";
            $cbEnv = "{$calleeCode}.env";
        }
        $callArgs = ($argStr !== '' ? $argStr . ', ' : '') . $cbEnv;

        // 查找闭包签名
        $retType = 't_int';
        // 默认：从实参推导参数类型 + void* env（callable 参数等无签名场景）
        $inferred = [];
        foreach ($args as $a) {
            $inferred[] = $this->inferType($a);
        }
        $paramTypes = (empty($inferred) ? '' : implode(', ', $inferred) . ', ') . 'void*';
        if ($callee instanceof VariableExpr) {
            $varName = self::varName($callee->name);
            $fnName = $this->symbols->getVarClosure($varName) ?? '';
            if ($fnName && $this->symbols->getClosureSig($fnName) !== null) {
                $sig = $this->symbols->getClosureSig($fnName);
                $retType = $sig['ret'];
                $paramTypes = $sig['params'] ? $sig['params'] . ', void*' : 'void*';
            }
        }

        return "(($retType(*)({$paramTypes})){$cbFunc})({$callArgs})";
    }

    // ── array_map / array_filter / array_reduce 编译期内联展开 ──

    /** 从 AST 推断闭包签名（无需先 visit） */
    private function inferCallbackSig(ExprNode $expr): ?array
    {
        if ($expr instanceof ClosureExpr) {
            $ret = $expr->isGenerator ? 'tphp_class_Generator*' : self::mapType($expr->returnType);
            $params = array_map(fn($p) => self::mapType($p->type), $expr->params);
            return ['ret' => $ret, 'params' => $params];
        }
        if ($expr instanceof VariableExpr) {
            $varName = self::varName($expr->name);
            $fnName = $this->symbols->getVarClosure($varName) ?? '';
            if ($fnName && $this->symbols->getClosureSig($fnName) !== null) {
                $sig = $this->symbols->getClosureSig($fnName);
                $params = $sig['params'] !== '' ? array_map('trim', explode(',', $sig['params'])) : [];
                return ['ret' => $sig['ret'], 'params' => $params];
            }
        }
        if ($expr instanceof CallableConvertExpr) {
            // First-class callable: 查询 visitCallableConvert 注册的闭包签名
            $sigName = $this->callableConvertSigName($expr);
            if ($sigName !== null && $this->symbols->getClosureSig($sigName) !== null) {
                $sig = $this->symbols->getClosureSig($sigName);
                $params = $sig['params'] !== '' ? array_map('trim', explode(',', $sig['params'])) : [];
                return ['ret' => $sig['ret'], 'params' => $params];
            }
        }
        return null;
    }

    /**
     * 推导 CallableConvertExpr 对应的闭包签名名（与 visitCallableConvert 一致）
     *   注意：调用前需确保 visitCallableConvert 已运行（注册签名到 SymbolTable）
     * @return string|null 签名名，null 表示无法推导
     */
    private function callableConvertSigName(CallableConvertExpr $node): ?string
    {
        // 1. 全局函数
        if ($node->callee === null) {
            $name = $node->name;
            if (isset(self::$simpleFnMap[$name])) {
                return self::$simpleFnMap[$name]['cName'];
            }
            // 用户函数
            $pos = strrpos($name, '\\');
            if ($pos !== false) {
                $ns = substr($name, 0, $pos);
                $fn = substr($name, $pos + 1);
                return 'tphp_na_' . self::mangleCName($ns) . '_tphp_fn_' . $fn;
            }
            return 'tphp_fn_' . self::mangleCName($name);
        }

        // 2. $var(...) — 闭包变量引用，签名通过 getVarClosure 查询
        if ($node->callee instanceof VariableExpr
            && str_starts_with($node->callee->name, '$')
            && $node->name === '__invoke') {
            $varName = self::varName($node->callee->name);
            return $this->symbols->getVarClosure($varName);
        }

        // 3. C->func(...)
        if ($node->isRawC) {
            return $node->name;
        }

        // 4. Class::method(...) / $obj->method(...)
        $callee = $node->callee;
        if ($callee instanceof VariableExpr && !str_starts_with($callee->name, '$')) {
            // 静态方法
            $cn = $this->symbols->resolveClass($callee->name) ?? ('tphp_class_' . $callee->name);
            return "{$cn}_{$node->name}";
        }
        // 实例方法：签名名为 thunk 名，但 thunk 名是动态生成的（_method_thunk_N）
        //   无法在此静态推导，返回 null 让调用方使用默认类型
        return null;
    }

    /** 类型 → 数组元素 getter 函数名 */

}
