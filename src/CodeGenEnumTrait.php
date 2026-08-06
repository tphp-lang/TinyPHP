<?php

declare(strict_types=1);

// ============================================================
// CodeGenEnumTrait — 枚举与注解相关方法
// 从 CodeGenerator.php 提取
// ============================================================
trait CodeGenEnumTrait {

    private function emitEnumMethodCall(CallExpr $node, string $enumCName, string $calleeCode, array $args): string
    {
        $method = $node->name;
        $mInfo = $this->symbols->getEnumMethodByCName($enumCName, $method);
        if ($mInfo === null) {
            throw new \RuntimeException(sprintf(
                "[%d:%d] Call to undefined enum method %s::%s()",
                $node->line, $node->column, $enumCName, $method
            ));
        }
        $methodCName = "{$enumCName}_{$method}";
        $argCount = count($node->args);
        // 静态自动方法（cases/from/tryFrom）：无 self 参数
        if ($mInfo->isStatic) {
            // 重载版本选择（自动方法无默认值，但用户静态方法理论上可走此分支——目前用户方法都是实例）
            if ($mInfo->defaultCount > 0 && $argCount < $mInfo->totalParams) {
                $missing = $mInfo->totalParams - $argCount;
                $methodCName = $methodCName . '_' . $missing;
            }
            return "{$methodCName}(" . implode(', ', $args) . ')';
        }
        // 实例方法：self 作为第一个参数
        // calleeCode 对于 EnumAccessExpr 已是 "&_e_<prefix>_<case>"
        $allArgs = array_merge([$calleeCode], $args);
        if ($mInfo->defaultCount > 0 && $argCount < $mInfo->totalParams) {
            $missing = $mInfo->totalParams - $argCount;
            $methodCName = $methodCName . '_' . $missing;
        }
        $call = "{$methodCName}(" . implode(', ', $allArgs) . ')';
        // nullsafe 包装（实例方法才可能）
        if ($node->isNullsafe) {
            $ret = $mInfo->retType;
            if ($ret === 'void') {
                return "({ if ((void*){$calleeCode} != NULL) {{ {$call}; }} })";
            }
            $tmp = '_nsr_' . (++$this->tmpVarCounter);
            $zero = match ($ret) { 't_float' => '0.0', 't_string' => '(t_string){NULL,0}', default => '0' };
            return "({ {$ret} {$tmp} = {$zero}; if ((void*){$calleeCode} != NULL) {{ $tmp = {$call}; }} {$tmp}; })";
        }
        return $call;
    }

    /** 推断链式调用的返回类名 */

    private function emitAnnotationConstant(ConstNode $node): string
    {
        $shortName = $node->name;
        $fqName = $node->namespace !== '' ? $node->namespace . '\\' . $node->name : $node->name;
        $constName = 'TPHP_CONST_' . strtoupper($node->name);
        $initFn = '_annot_' . $node->name . '_init';
        $entryPrefix = '_annot_' . $node->name . '_';

        // 收集所有 AttributeUseNode 匹配此注解
        $entries = [];
        $declParams = $node->attributeDecl->params;

        $allClasses = array_merge(
            $this->program->mainClass ? [$this->program->mainClass] : [],
            $this->program->extraClasses
        );
        // trait 自身不参与查找（编译期已扁平化到使用方）
        $allClasses = array_filter($allClasses, fn($c) => !$c->isTrait);
        foreach ($allClasses as $class) {
            $classFq = $class->namespace !== '' ? $class->namespace . '\\' . $class->name : $class->name;
            // 类级注解
            foreach ($class->attributes as $attr) {
                if ($this->attrNameMatches($attr->name, $shortName, $fqName)) {
                    $this->validateAttrArgs($node->name, $declParams, $attr->args, "class {$classFq}");
                    $entries[] = [
                        'kind' => 'class',
                        'class' => $classFq,
                        'namespace' => $class->namespace,
                        'className' => $class->name,
                        'method' => null,
                        'function' => null,
                        'name' => $classFq,
                        'args' => $attr->args,
                    ];
                }
            }
            // 方法级注解
            foreach ($class->methods as $m) {
                foreach ($m->attributes as $attr) {
                    if ($this->attrNameMatches($attr->name, $shortName, $fqName)) {
                        $kind = $m->isStatic ? 'static_method' : 'method';
                        $qualified = $classFq . ($m->isStatic ? '::' : '->') . $m->name;
                        $this->validateAttrArgs($node->name, $declParams, $attr->args, "method {$qualified}");
                        $entries[] = [
                            'kind' => $kind,
                            'class' => $classFq,
                            'namespace' => $class->namespace,
                            'className' => $class->name,
                            'method' => $m->name,
                            'isStatic' => $m->isStatic,
                            'function' => null,
                            'name' => $qualified,
                            'args' => $attr->args,
                        ];
                    }
                }
            }
        }
        // 函数级注解
        foreach ($this->program->functions as $fn) {
            // C 函数签名声明不参与注解注册
            if ($fn->isCDeclaration) continue;
            foreach ($fn->attributes as $attr) {
                if ($this->attrNameMatches($attr->name, $shortName, $fqName)) {
                    $fnFq = $fn->namespace !== '' ? $fn->namespace . '\\' . $fn->name : $fn->name;
                    $this->validateAttrArgs($node->name, $declParams, $attr->args, "function {$fnFq}");
                    $entries[] = [
                        'kind' => 'function',
                        'class' => null,
                        'namespace' => $fn->namespace,
                        'className' => null,
                        'method' => null,
                        'function' => $fn->name,
                        'name' => $fnFq,
                        'args' => $attr->args,
                    ];
                }
            }
        }

        // 注册到符号表 — 注解常量为 t_array* 类型
        $this->symbols->addConst($node->name, 't_array*');

        // 注册到 annotationRegistry（短名 + FQ 名均可查）
        $reg = [
            'fqName' => $fqName,
            'shortName' => $shortName,
            'constName' => $constName,
            'initFn' => $initFn,
            'entryVarPrefix' => $entryPrefix,
            'entries' => $entries,
        ];
        $this->annotationRegistry[$shortName] = $reg;
        if ($fqName !== $shortName) {
            $this->annotationRegistry[$fqName] = $reg;
        }
        $this->annotationInitFns[] = $initFn;

        // ── 生成 C 代码 ──
        $declLines = [];
        $declLines[] = "/* ── Annotation Constant: {$fqName} ──────────── */";
        // 每条 entry 的静态指针变量（供静态索引编译期展开使用）
        foreach ($entries as $i => $e) {
            $declLines[] = "static tphp_class_AnnotationEntry* {$entryPrefix}{$i} = NULL;";
        }
        $declLines[] = "static t_array* {$constName} = NULL;";
        $declLines[] = '';

        // init 函数实现
        $implLines = [];
        $implLines[] = "static void {$initFn}(void) {";
        $implLines[] = "    {$constName} = tphp_fn_arr_create(" . count($entries) . ");";
        foreach ($entries as $i => $e) {
            // 构建 data 数组（位置参数）
            $dataVar = "{$entryPrefix}{$i}_data";
            $implLines[] = "    t_array* {$dataVar} = tphp_fn_arr_create(" . count($e['args']) . ");";
            foreach ($e['args'] as $ai => $arg) {
                $implLines[] = "    tphp_fn_arr_set_int({$dataVar}, {$ai}, " . $this->wrapVar($arg) . ");";
            }
            // 构建 AnnotationEntry
            $typeStr = $e['kind'];
            $nameStr = $e['name'];
            $typeC = 'STR_LIT("' . $typeStr . '")';
            $nameC = 'STR_LIT("' . str_replace('\\', '\\\\', $nameStr) . '")';
            $implLines[] = "    {$entryPrefix}{$i} = new_tphp_class_AnnotationEntry({$dataVar}, {$typeC}, {$nameC});";
            $implLines[] = "    tphp_fn_arr_set_int({$constName}, {$i}, VAR_OBJ({$entryPrefix}{$i}));";
        }
        $implLines[] = "}";

        // ── 生成运行时 dispatch 函数（供 foreach 中 $v->call() / $v->newInstance() 使用） ──
        $dispatchLines = $this->emitAnnotationDispatch($node->name, $entries);
        $dispatchBlock = implode("\n", $dispatchLines);

        // 将声明 + init 函数实现 + dispatch 函数加入 SEC_CLSIMPL（在 main 之前执行）
        $this->sectionBlock(self::SEC_CLSIMPL, implode("\n", $declLines) . implode("\n", $implLines) . $dispatchBlock);
        // 前向声明 init 函数（main 中调用）
        $this->sectionLine(self::SEC_FUNCFWDS, "static void {$initFn}(void);");

        // 注解常量本身不输出 #define（已是 static 变量，由 visitVariable 解析）
        return "/* const {$fqName} — annotation constant, see _annot_*  */";
    }

    /** 生成运行时分发调用代码（$v->call() / $v->newInstance()） */
    private function emitAnnotationRuntimeCall(string $annotName, string $method, string $calleeCode, array $args): string
    {
        $argCodes = array_map(fn($a) => $a->accept($this), $args);
        $argc = count($argCodes);
        $dispatchFn = '_annot_' . $annotName . '_dispatch_' . ($method === 'call' ? 'call' : 'new');

        if ($argc === 0) {
            $argv = 'NULL';
        } else {
            // 参数包装为 t_var 数组
            $wrapped = [];
            for ($i = 0; $i < $argc; $i++) {
                $wrapped[] = $this->wrapVarExpr($argCodes[$i], $args[$i]);
            }
            $argv = '(t_var[]){' . implode(', ', $wrapped) . '}';
        }

        $callExpr = "{$dispatchFn}({$calleeCode}, {$argc}, {$argv})";

        if ($method === 'newInstance') {
            // newInstance 返回 void*，需要 cast 到目标类型
            $reg = $this->annotationRegistry[$annotName] ?? null;
            if ($reg !== null) {
                $classEntries = array_filter($reg['entries'], fn($e) => $e['kind'] === 'class');
                if (count($classEntries) === 1) {
                    $entry = reset($classEntries);
                    $classCName = self::classRefName($entry['class']);
                    return "(({$classCName}*){$callExpr})";
                }
                $commonBase = $this->findCommonBaseClass($classEntries);
                if ($commonBase !== '') {
                    $classCName = self::classRefName($commonBase);
                    return "(({$classCName}*){$callExpr})";
                }
            }
        }

        return $callExpr;
    }

    /** 将表达式代码包装为 t_var（用于运行时分发参数传递） */

    private function emitAnnotationDispatch(string $annotName, array $entries): array
    {
        $lines = [];
        $callFn = '_annot_' . $annotName . '_dispatch_call';
        $newInstFn = '_annot_' . $annotName . '_dispatch_new';

        // ── call() dispatch ──
        $hasCallable = false;
        foreach ($entries as $e) {
            if ($e['kind'] !== 'class') { $hasCallable = true; break; }
        }
        if ($hasCallable) {
            $lines[] = '';
            $lines[] = "/* {$annotName} call() 运行时分发 */";
            $lines[] = "static void {$callFn}(tphp_class_AnnotationEntry* _entry, int _argc, t_var* _argv) {";
            $first = true;
            foreach ($entries as $e) {
                if ($e['kind'] === 'class') continue;
                $nameC = 'STR_LIT("' . str_replace('\\', '\\\\', $e['name']) . '")';
                $kw = $first ? 'if' : 'else if';
                $lines[] = "    {$kw} (tphp_rt_str_eq(_entry->name, {$nameC})) {";
                // 生成目标调用
                $callExpr = $this->buildEntryCallExpr($e, '_argv');
                $lines[] = "        {$callExpr};";
                $lines[] = "    }";
                $first = false;
            }
            $lines[] = "}";
        }

        // ── newInstance() dispatch ──
        $hasClass = false;
        foreach ($entries as $e) {
            if ($e['kind'] === 'class') { $hasClass = true; break; }
        }
        if ($hasClass) {
            $lines[] = '';
            $lines[] = "/* {$annotName} newInstance() 运行时分发 */";
            $lines[] = "static void* {$newInstFn}(tphp_class_AnnotationEntry* _entry, int _argc, t_var* _argv) {";
            $first = true;
            foreach ($entries as $e) {
                if ($e['kind'] !== 'class') continue;
                $nameC = 'STR_LIT("' . str_replace('\\', '\\\\', $e['name']) . '")';
                $kw = $first ? 'if' : 'else if';
                $lines[] = "    {$kw} (tphp_rt_str_eq(_entry->name, {$nameC})) {";
                $classCName = self::classRefName($e['class']);
                if ($this->isMainClassCName($classCName)) {
                    $lines[] = "        return (void*)new_{$classCName}((t_int)0, (t_array*)NULL);";
                } else {
                    // 查找构造器参数类型，从 _argv 提取
                    $ctorParams = $this->lookupMethodParams($e['class'], '__construct');
                    $args = $this->buildRuntimeArgs($ctorParams, '_argv');
                    $lines[] = "        return (void*)new_{$classCName}(" . implode(', ', $args) . ");";
                }
                $lines[] = "    }";
                $first = false;
            }
            $lines[] = "    return NULL;";
            $lines[] = "}";
        }

        return $lines;
    }

    /** 为 dispatch 分支构建目标调用表达式（参数从 _argv 运行时提取） */
    private function buildEntryCallExpr(array $entry, string $argvVar): string
    {
        $kind = $entry['kind'];
        if ($kind === 'function') {
            $fnCName = $entry['namespace'] !== ''
                ? 'tphp_na_' . self::mangleCName($entry['namespace']) . '_tphp_fn_' . $entry['function']
                : 'tphp_fn_' . $entry['function'];
            $params = $this->lookupFunctionParams($entry['function'], $entry['namespace']);
            $args = $this->buildRuntimeArgs($params, $argvVar);
            return "{$fnCName}(" . implode(', ', $args) . ")";
        }
        // method / static_method
        $classCName = self::classRefName($entry['class']);
        $methodCName = $classCName . '_' . $entry['method'];
        $params = $this->lookupMethodParams($entry['class'], $entry['method']);
        $args = $this->buildRuntimeArgs($params, $argvVar);
        if ($kind === 'static_method') {
            return "{$methodCName}(" . implode(', ', $args) . ")";
        }
        // 实例方法：先 new 再调
        $newExpr = $this->isMainClassCName($classCName)
            ? "new_{$classCName}((t_int)0, (t_array*)NULL)"
            : "new_{$classCName}()";
        return "(" . $methodCName . "(" . $newExpr . (empty($args) ? "" : ", " . implode(', ', $args)) . "))";
    }

    /** 从 t_var* _argv 提取参数，按目标函数参数类型转换 */
    private function buildRuntimeArgs(array $paramTypes, string $argvVar): array
    {
        $args = [];
        foreach ($paramTypes as $i => $type) {
            $cType = self::mapType($type);
            $args[] = match ($cType) {
                't_int'    => "(_argc > {$i} && _argv[{$i}].type == TYPE_INT) ? (t_int)_argv[{$i}].value._int : 0",
                't_float'  => "(_argc > {$i} && _argv[{$i}].type == TYPE_FLOAT) ? (t_float)_argv[{$i}].value._float : 0.0",
                't_bool'   => "(_argc > {$i} && _argv[{$i}].type == TYPE_BOOL) ? (t_bool)_argv[{$i}].value._bool : false",
                't_string' => "(_argc > {$i} && _argv[{$i}].type == TYPE_STRING) ? _argv[{$i}].value._string : ((t_string){NULL, 0})",
                default    => "0",
            };
        }
        return $args;
    }

    /** 查找独立函数的参数类型列表 */

    private function findCommonBaseClass(array $classEntries): string
    {
        $classLists = [];
        foreach ($classEntries as $e) {
            $chain = [];
            $current = $e['class'];
            while ($current !== null && $current !== '') {
                $chain[] = $current;
                $current = $this->lookupParentClass($current);
            }
            $classLists[] = $chain;
        }
        if (empty($classLists)) return '';
        // 取所有类链的交集
        $common = $classLists[0];
        for ($i = 1; $i < count($classLists); $i++) {
            $common = array_intersect($common, $classLists[$i]);
        }
        return !empty($common) ? reset($common) : '';
    }

    /** 查找类的父类名 */

    public function visitEnum(EnumNode $node): string
    {
        // 注册枚举类型（FQN + 短名均可查）
        $fqName = ($node->namespace !== '')
            ? $node->namespace . '\\' . $node->name
            : $node->name;
        // 全局枚举: tphp_enum_Name, 命名空间枚举: tphp_na_Ns_tphp_enum_Name
        if ($node->namespace !== '') {
            $cName = 'tphp_na_' . self::mangleCName($node->namespace) . '_tphp_enum_' . $node->name;
        } else {
            $cName = 'tphp_enum_' . $node->name;
        }
        $cValueType = ($node->backingType === 'int') ? 't_int' : 't_string';

        $this->symbols->addEnum($fqName, $node->backingType, $cName . '*');
        $this->symbols->addEnum($node->name, $node->backingType, $cName . '*');

        // 将命名空间分隔符转为 C 标识符前缀
        $prefix = self::mangleCName($fqName);

        $lines = [];
        $lines[] = "/* ── Enum: {$fqName} ({$node->backingType}) ──────────────── */";

        // Struct 定义
        $lines[] = "typedef struct {";
        $lines[] = "    t_string name;";
        $lines[] = "    {$cValueType} value;";
        $lines[] = "} {$cName};";

        // Static 单例实例（const → .rodata，零内存泄漏）
        foreach ($node->cases as $case) {
            $valCode = $case->value->accept($this);
            $lines[] = "static {$cName} _e_{$prefix}_{$case->name} = {";
            $lines[] = "    .name = STR_LIT(\"{$case->name}\"),";
            $lines[] = "    .value = {$valCode},";
            $lines[] = "};";
            // 注册 case（FQN + 短名）
            $this->symbols->addEnumCase($fqName, $case->name);
            $this->symbols->addEnumCase($node->name, $case->name);
        }
        $lines[] = '';

        // 枚举常量 → #define（与类常量命名一致：TPHP_CONST_<CN>_<NAME>）
        foreach ($node->classConsts as $cc) {
            $cname = 'TPHP_CONST_' . strtoupper($cName . '_' . $cc->name);
            $vis = $cc->visibility ?? 'public';
            $declCType = self::mapType($cc->type);
            $this->symbols->addEnumConst($fqName, $cc->name, $declCType);
            $this->symbols->addEnumConst($node->name, $cc->name, $declCType);
            $this->symbols->addConst($cName . '_' . $cc->name, $declCType, $vis);
            $this->symbols->addConst($cname, $declCType, $vis);
            if ($cc->value instanceof StringLiteralExpr) {
                $val = str_replace('"', '\\"', $cc->value->value);
                $lines[] = "#define {$cname} STR_LIT(\"{$val}\")";
            } elseif ($cc->value instanceof IntLiteralExpr) {
                $lines[] = "#define {$cname} {$cc->value->value}";
            } elseif ($cc->value instanceof FloatLiteralExpr) {
                $fv = $cc->value->value;
                $lines[] = '#define ' . $cname . ' ' .
                    (($fv == (float)(int)$fv) ? sprintf('%.1f', $fv) : rtrim(rtrim(sprintf('%.15g', $fv), '0'), '.'));
            } elseif ($cc->value instanceof BoolLiteralExpr) {
                $lines[] = "#define {$cname} " . ($cc->value->value ? 'true' : 'false');
            }
        }
        if (!empty($node->classConsts)) $lines[] = '';

        // 注册用户方法 + 自动 cases()/from()/tryFrom() 到 SymbolTable
        $autoStatic = [
            'cases'    => 't_array*',
            'from'     => $cName . '*',
            'tryFrom'  => $cName . '*',
        ];
        foreach ($node->methods as $m) {
            $mr = $m->isGenerator ? 'tphp_class_Generator*' : self::mapType($m->returnType);
            $pts = array_map(fn($p) => $this->mapType($p->type), $m->params);
            $pns = array_map(fn($p) => ltrim($p->name, '$'), $m->params);
            $tp = count($m->params);
            $dc = 0;
            for ($i = $tp - 1; $i >= 0; $i--) {
                if ($m->params[$i]->default !== null) { $dc++; } else { break; }
            }
            $mi = new MethodInfo($mr, $pts, false, 'public', $dc, $tp, false, false, $pns);
            $this->symbols->addEnumMethod($fqName, $m->name, $mi);
            $this->symbols->addEnumMethod($node->name, $m->name, $mi);
        }
        // 自动方法注册（静态）
        $paramCType = ($node->backingType === 'int') ? 't_int' : 't_string';
        foreach ($autoStatic as $mname => $mret) {
            $mi = new MethodInfo($mret, [$paramCType], true, 'public', 0, 1, false, false, ['value']);
            // cases() 无参数
            if ($mname === 'cases') {
                $mi = new MethodInfo($mret, [], true, 'public', 0, 0);
            }
            $this->symbols->addEnumMethod($fqName, $mname, $mi);
            $this->symbols->addEnumMethod($node->name, $mname, $mi);
        }

        // 方法前置声明（用户实例方法 + 自动静态方法）
        $fwd = [];
        foreach ($node->methods as $m) {
            $ret = $m->isGenerator ? 'tphp_class_Generator*' : self::mapType($m->returnType);
            $params = array_map(fn($p) => $this->visitParam($p), $m->params);
            $fwd[] = "{$ret} {$cName}_{$m->name}({$cName}* self" .
                (empty($params) ? '' : ', ' . implode(', ', $params)) . ');';
        }
        // 自动方法前置声明（静态，无 self）
        $fwd[] = "t_array* {$cName}_cases();";
        $fwd[] = "{$cName}* {$cName}_from({$paramCType} value);";
        $fwd[] = "{$cName}* {$cName}_tryFrom({$paramCType} value);";
        $lines = array_merge($lines, $fwd);
        $lines[] = '';

        return implode("\n", $lines);
    }

    /**
     * Phase 2: 枚举方法实现 + 自动 cases()/from()/tryFrom() 实现
     * 输出到 SEC_CLSIMPL（在 SEC_ENUMS 前置声明之后）
     */
    private function emitEnumImpl(EnumNode $node): string
    {
        $fqName = ($node->namespace !== '') ? $node->namespace . '\\' . $node->name : $node->name;
        if ($node->namespace !== '') {
            $cName = 'tphp_na_' . self::mangleCName($node->namespace) . '_tphp_enum_' . $node->name;
        } else {
            $cName = 'tphp_enum_' . $node->name;
        }
        $prefix = self::mangleCName($fqName);
        $savedClassName = $this->className;
        $savedPhpClassName = $this->phpClassName;
        $savedNamespace = $this->currentNamespace;
        $savedInMethod = $this->inMethod;
        $this->className = $cName;
        $this->phpClassName = $fqName;
        $this->currentNamespace = $node->namespace;
        $this->inMethod = true;

        $parts = [];
        $parts[] = "/* ── Enum impl: {$fqName} ──────────────────── */";

        // 用户实例方法实现
        foreach ($node->methods as $m) {
            $parts[] = $this->visitMethod($m);
        }

        // 自动 cases(): 返回 t_array*，元素为 enum 实例指针（VAR_OBJ 包裹）
        $casesImpl = [];
        $casesImpl[] = "t_array* {$cName}_cases() {";
        $casesImpl[] = $this->ind("t_array* a = tphp_fn_arr_create(" . count($node->cases) . ");");
        $casesImpl[] = $this->ind("tphp_rt_register((void*)a, 1);");
        foreach ($node->cases as $case) {
            $casesImpl[] = $this->ind("a = tphp_fn_arr_push(a, VAR_OBJ(&_e_{$prefix}_{$case->name}));");
        }
        $casesImpl[] = $this->ind("return a;");
        $casesImpl[] = "}";
        $parts[] = implode("\n", $casesImpl);

        // 自动 from(): 找不到抛 tp_throw
        $paramCType = ($node->backingType === 'int') ? 't_int' : 't_string';
        $fromImpl = [];
        $fromImpl[] = "{$cName}* {$cName}_from({$paramCType} value) {";
        foreach ($node->cases as $case) {
            if ($node->backingType === 'int') {
                $fromImpl[] = $this->ind("if (value == _e_{$prefix}_{$case->name}.value) return &_e_{$prefix}_{$case->name};");
            } else {
                $fromImpl[] = $this->ind("if (tphp_rt_str_eq(value, _e_{$prefix}_{$case->name}.value)) return &_e_{$prefix}_{$case->name};");
            }
        }
        $fromImpl[] = $this->ind("tp_throw(\"{$node->name}::from(): value not found in enum cases\");");
        $fromImpl[] = $this->ind("return NULL;");
        $fromImpl[] = "}";
        $parts[] = implode("\n", $fromImpl);

        // 自动 tryFrom(): 找不到返回 NULL
        $tryImpl = [];
        $tryImpl[] = "{$cName}* {$cName}_tryFrom({$paramCType} value) {";
        foreach ($node->cases as $case) {
            if ($node->backingType === 'int') {
                $tryImpl[] = $this->ind("if (value == _e_{$prefix}_{$case->name}.value) return &_e_{$prefix}_{$case->name};");
            } else {
                $tryImpl[] = $this->ind("if (tphp_rt_str_eq(value, _e_{$prefix}_{$case->name}.value)) return &_e_{$prefix}_{$case->name};");
            }
        }
        $tryImpl[] = $this->ind("return NULL;");
        $tryImpl[] = "}";
        $parts[] = implode("\n", $tryImpl);

        $this->className = $savedClassName;
        $this->phpClassName = $savedPhpClassName;
        $this->currentNamespace = $savedNamespace;
        $this->inMethod = $savedInMethod;

        return implode("\n\n", $parts);
    }

    // EnumAccessExpr → 返回 static 实例指针（case 访问）或常量引用（const 访问）

}
