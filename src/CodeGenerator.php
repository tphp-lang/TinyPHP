<?php

declare(strict_types=1);

class CodeGenerator implements ASTVisitor
{
    private string $className = '';
    private int $indent = 0;
    private int $scopeDepth = 0; // 嵌套块深度（for/while/if/foreach 体内为 1+）
    private string $phpFile = '';

    /** 变量类型追踪：varName → className（对象）或 C 类型（基础类型） */
    private array $varTypes = [];
    /** 当前方法名字（用于 __METHOD__） */
    private string $currentMethodName = '';
    /** 数组元素类型追踪：varName → C 类型（int key 的默认类型） */
    private array $arrElementTypes = [];
    /** 数组 per-key 类型追踪：arrVarName → [strKey → CType]（字符串键专用） */
    private array $arrValueTypes = [];
    /** 嵌套数组元素类型追踪：arrVarName → CType（当数组元素是数组时，记录子数组的元素类型） */
    private array $arrNestedTypes = [];
    /** 已声明变量集合 */
    private array $declaredVars = [];
    /** for 循环提升到函数作用域的变量声明：varName => cType */
    private array $funcScopeDecls = [];

    // ── 统一符号表 ──────────────────────────────────────────
    // 替代了 13 个散落的类型追踪数组
    private SymbolTable $symbols;

    // 过渡期：旧数组仍用作 write-back，读取均走 SymbolTable
    // 待全部 READ 迁移完成后删除
    private array $classPropTypes = [];
    private array $classOwnProps = [];
    private array $classParentName = [];
    private array $classMethodRetTypes = [];
    private array $classNames = [];
    private array $constTypes = [];
    private array $constVis = [];
    private array $enumBackingTypes = [];
    private array $enumCTypes = [];
    private array $methodParamTypes = [];
    private array $funcRetTypes = [];
    private array $funcParamTypes = [];
    private array $funcDefaultCounts = [];  // 函数默认值参数数量
    private array $funcIsGenerator = [];    // funcCName → true（生成器函数标记）
    private bool $inGenerator = false;     // 当前是否在生成器入口函数体内
    private array $closureSigs = [];
    private array $varClosureMap = [];

    /** 字面量 → C 类型的映射 */
    private static array $litTypeMap = [
        IntLiteralExpr::class    => 't_int',
        FloatLiteralExpr::class  => 't_float',
        StringLiteralExpr::class => 't_string',
        BoolLiteralExpr::class   => 't_bool',
        MagicConstExpr::class    => 't_string',
    ];

    private static array $typeMap = [
        'int' => 't_int', 'float' => 't_float', 'string' => 't_string',
        'bool' => 't_bool', 'void' => 'void', 'never' => 'void', 'array' => 't_array*',
        'mixed' => 't_var', 'null' => 'void*',
        'Generator' => 'tphp_class_Generator*',
    ];

    /** 内置函数返回类型注册表（替代 inferCallReturnType 中的 140+ if-else） */
    private static array $builtinRetTypes = [
        // ── t_int ──
        'time' => 't_int', 'hrtime' => 't_int', 'count' => 't_int', 'sleep' => 't_int', 'usleep' => 't_int',
        'array_push' => 't_int', 'array_unshift' => 't_int', 'mb_strlen' => 't_int', 'filter_id' => 't_int',
        'strlen' => 't_int', 'strpos' => 't_int', 'abs' => 't_int', 'array_search' => 't_int',
        'intval' => 't_int', 'rand' => 't_int', 'mt_rand' => 't_int', 'random_int' => 't_int',
        'intdiv' => 't_int', 'ord' => 't_int', 'bindec' => 't_int', 'hexdec' => 't_int', 'octdec' => 't_int',
        'array_key_first' => 't_int', 'array_key_last' => 't_int', 'strtotime' => 't_int', 'mktime' => 't_int',
        'substr_count' => 't_int', 'crc32' => 't_int', 'preg_last_error' => 't_int',
        'iconv_strlen' => 't_int', 'iconv_strpos' => 't_int',
        // ── t_string ──
        'date' => 't_string', 'implode' => 't_string', 'join' => 't_string', 'json_encode' => 't_string',
        'htmlspecialchars' => 't_string', 'nl2br' => 't_string', 'base64_encode' => 't_string',
        'base64_decode' => 't_string', 'http_build_query' => 't_string', 'sha256' => 't_string', 'sha512' => 't_string',
        'password_hash' => 't_string', 'base_convert' => 't_string', 'mb_substr' => 't_string',
        'sprintf' => 't_string', 'str_replace' => 't_string', 'strtolower' => 't_string', 'strtoupper' => 't_string',
        'trim' => 't_string', 'ltrim' => 't_string', 'rtrim' => 't_string', 'substr' => 't_string',
        'file_get_contents' => 't_string', 'strval' => 't_string', 'chr' => 't_string', 'getenv' => 't_string',
        'decbin' => 't_string', 'decoct' => 't_string', 'dechex' => 't_string', 'number_format' => 't_string',
        'uniqid' => 't_string', 'ucfirst' => 't_string', 'lcfirst' => 't_string', 'strrev' => 't_string',
        'str_repeat' => 't_string', 'str_pad' => 't_string', 'str_shuffle' => 't_string',
        'addslashes' => 't_string', 'stripslashes' => 't_string', 'bin2hex' => 't_string', 'hex2bin' => 't_string',
        'urlencode' => 't_string', 'urldecode' => 't_string', 'md5' => 't_string', 'sha1' => 't_string',
        'strtr' => 't_string', 'preg_replace' => 't_string', 'preg_quote' => 't_string',
        'preg_last_error_msg' => 't_string', 'php_str' => 't_string', 'php_str_clone' => 't_string',
        'random_bytes' => 't_string', 'gettype' => 't_string',
        // ── iconv (内置) ──
        'iconv' => 't_string', 'iconv_substr' => 't_string',
        'iconv_mime_encode' => 't_string', 'iconv_mime_decode' => 't_string',
        // ── t_bool ──
        'shuffle' => 't_bool', 'json_validate' => 't_bool', 'password_verify' => 't_bool',
        'in_array' => 't_bool', 'array_key_exists' => 't_bool', 'str_contains' => 't_bool',
        'boolval' => 't_bool', 'str_starts_with' => 't_bool', 'str_ends_with' => 't_bool',
        'array_is_list' => 't_bool', 'file_put_contents' => 't_bool',
        'iconv_set_encoding' => 't_bool',
        // ── t_float ──
        'sin' => 't_float', 'cos' => 't_float', 'tan' => 't_float', 'asin' => 't_float', 'acos' => 't_float',
        'atan' => 't_float', 'exp' => 't_float', 'log' => 't_float', 'log10' => 't_float', 'fmod' => 't_float',
        'microtime' => 't_float', 'pi' => 't_float', 'deg2rad' => 't_float', 'rad2deg' => 't_float',
        'round' => 't_float', 'ceil' => 't_float', 'floor' => 't_float', 'sqrt' => 't_float', 'floatval' => 't_float',
        // ── t_array* ──
        'array_keys' => 't_array*', 'array_values' => 't_array*', 'array_merge' => 't_array*',
        'array_map' => 't_array*', 'array_filter' => 't_array*', 'array_reverse' => 't_array*',
        'array_slice' => 't_array*', 'array_unique' => 't_array*', 'range' => 't_array*',
        'array_fill' => 't_array*', 'explode' => 't_array*', 'array_diff' => 't_array*',
        'array_intersect' => 't_array*', 'array_column' => 't_array*', 'array_flip' => 't_array*',
        'array_chunk' => 't_array*', 'array_combine' => 't_array*', 'array_count_values' => 't_array*',
        'filter_list' => 't_array*', 'str_split' => 't_array*', 'parse_url' => 't_array*',
        'parse_str' => 't_array*', 'preg_match' => 't_array*', 'preg_match_all' => 't_array*',
        'preg_split' => 't_array*', 'preg_grep' => 't_array*',
        'iconv_get_encoding' => 't_array*',
        'phpc_new_arr_int' => 't_array*', 'phpc_new_arr_dbl' => 't_array*',
        'phpc_new_arr_str' => 't_array*', 'phpc_new_arr' => 't_array*',
        // ── t_var ──
        'array_pop' => 't_var', 'array_shift' => 't_var', 'array_sum' => 't_var', 'array_product' => 't_var',
        'max' => 't_var', 'min' => 't_var', 'json_decode' => 't_var', 'array_rand' => 't_var',
        'current' => 't_var', 'key' => 't_var', 'next' => 't_var', 'prev' => 't_var',
        'end' => 't_var', 'reset' => 't_var', 'pow' => 't_var', 'filter_var' => 't_var',
        // ── void ──
        'print_r' => 'void', 'var_dump' => 'void', 'sort' => 'void', 'rsort' => 'void',
        'asort' => 'void', 'arsort' => 'void', 'ksort' => 'void', 'krsort' => 'void',
        'putenv' => 'void', 'phpc_unregister_obj' => 'void', 'phpc_free' => 'void', 'phpc_free_str_arr' => 'void',
        'phpc_obj_steal' => 'void', 'phpc_env_unpin' => 'void',
        // ── t_object / t_callback / null (指针/无返回) ──
        'phpc_new_obj' => 't_object',
        'phpc_new_fn' => 't_callback', 'phpc_new_fn_env' => 't_callback',
        'phpc_arr_int' => 'null', 'phpc_arr_dbl' => 'null', 'phpc_arr_str' => 'null', 'phpc_obj' => 'null',
        'phpc_fn' => 'null', 'phpc_env' => 'null', 'phpc_fn_i32' => 'null', 'phpc_fn_i64' => 'null', 'phpc_fn_f64' => 'null',
        'phpc_thunk' => 'null',
        'phpc_assert_ptr' => 'null', 'phpc_env_pin' => 'null',
        // ── phpc 互操作 ──
        'c_int' => 't_int', 'php_int' => 't_int', 'c_float' => 't_float', 'php_float' => 't_float',
        'c_str' => 't_string', 'php_str' => 't_string',
    ];

    /** 内置函数返回数组的元素类型注册表（替代 visitAssign 中的 switch-case） */
    private static array $builtinArrElemTypes = [
        'array_keys' => 't_int', 'array_values' => 't_int', 'array_merge' => 't_int',
        'explode' => 't_string', 'preg_match' => 't_string', 'preg_split' => 't_string',
        'preg_grep' => 't_string', 'filter_list' => 't_string',
    ];

    /** 临时变量计数器，用于数组字面量的复合表达式 */
    private int $tmpVarCounter = 0;

    /** 闭包函数计数器 */
    private int $closureCounter = 0;
    /** 捕获类型计数器（用于预扫描阶段的唯一 ID 分配） */
    private int $capTypeCounter = 0;

    // ── Thunk 生成（phpc_thunk_i32 / phpc_thunk('name')）─
    private int $thunkCounter = 0;
    /** #callback 声明的回调签名: name → ['ret'=>'int32_t','params_str'=>'int32_t a, double b'] */
    private array $phpcCallbackSigs = [];

    // ── 多段输出 (V-style multi-section codegen) ──────────
    // 所有代码生成写入命名段，最后由 renderSections() 按序组装
    // 替代了原来的 $p = [] + 5 个 deferred 数组 + 字符串插入 hack
    private const SEC_HEADER    = 'header';     // 文件注释
    private const SEC_INCLUDES  = 'includes';   // #include 行
    private const SEC_CAPTYPES  = 'captypes';   // 闭包捕获类型 struct 定义
    private const SEC_FWDDECLS  = 'fwddecls';   // 函数前置声明
    private const SEC_THUNKVARS = 'thunkvars';  // Thunk 静态回调副本
    private const SEC_CONSTS    = 'consts';     // 全局常量
    private const SEC_ENUMS     = 'enums';      // 枚举定义
    private const SEC_CLSFWDS   = 'clsfwds';    // 类 struct + 方法前置声明
    private const SEC_FUNCFWDS  = 'funcfwds';   // 独立函数前置声明
    private const SEC_CLSIMPL   = 'clsimpl';    // 类方法实现 + allocator
    private const SEC_FUNCIMPL  = 'funcimpl';   // 独立函数实现
    private const SEC_CLOSURES  = 'closures';   // 闭包函数实现
    private const SEC_THUNKS    = 'thunks';     // Thunk 函数实现
    private const SEC_MAIN      = 'main';       // C entry main()

    private array $sections = [];

    // ── 类型/作用域 ──────────────────────────────────────
    /** 当前方法/函数的返回类型（用于 return 语句的 t_var 包裹） */
    private string $currentRetType = '';

    // ============================================================
    public function generate(ProgramNode $program, string $phpFile, string $outputDir): string
    {
        $this->phpFile = $phpFile;
        $this->className = $program->mainClass ? self::classCName($program->mainClass) : '';
        $this->resetState();
        $outPath = $outputDir . '/' . pathinfo($phpFile, PATHINFO_FILENAME) . '.c';
        $code = $program->accept($this);
        file_put_contents($outPath, $code);
        return $outPath;
    }

    // ============================================================
    public function visitProgram(ProgramNode $node): string
    {
        $this->indent = 0;
        $this->resetState();
        $this->preScanGenerators($node);

        // 收集 #callback 声明
        foreach ($node->callbacks as $cb) {
            $this->phpcCallbackSigs[$cb['name']] = $cb;
        }

        // 预扫描源码：是否用到了 Phase1/2 函数（需要 builtin_extra.h）
        $needExtra = false;
        $src = @file_get_contents($this->phpFile);
        if ($src !== false) {
            $extraFuncs = [
                'htmlspecialchars', 'nl2br', 'base64_encode', 'base64_decode', 'http_build_query',
                'array_flip', 'array_diff', 'array_intersect', 'array_column',
                'array_chunk', 'array_combine', 'array_count_values',
                'mb_strlen', 'mb_substr', 'mb_strpos',
            ];
            foreach ($extraFuncs as $fn) {
                if (str_contains($src, $fn . '(')) {
                    $needExtra = true;
                    break;
                }
            }
        }
        // 检测是否使用了 bcrypt (password_hash/verify)
        $needBcrypt = ($src !== false) && (str_contains($src, 'password_hash(') || str_contains($src, 'password_verify('));

        // ── SEC_HEADER ──
        $this->sectionLine(self::SEC_HEADER, "/* Generated by TinyPHP — PHP → C (TCC) */");
        $this->sectionLine(self::SEC_HEADER, '');

        // ── SEC_INCLUDES ──
        $this->sectionLine(self::SEC_INCLUDES, '#include "common.h"');
        if ($needExtra) {
            $this->sectionLine(self::SEC_INCLUDES, '#include "builtin_extra.h"');
        }
        foreach ($node->includes as $inc) {
            if (is_array($inc)) {
                $delim = ($inc['quoted'] ?? true) ? '"' : '<';
                $end   = ($inc['quoted'] ?? true) ? '"' : '>';
                $this->sectionLine(self::SEC_INCLUDES, '#include ' . $delim . $inc['file'] . $end);
            } else {
                $this->sectionLine(self::SEC_INCLUDES, '#include "' . $inc . '"');
            }
        }

        // ── SEC_CONSTS ──
        if ($needBcrypt) {
            $this->sectionLine(self::SEC_CONSTS, '#define TPHP_CONST_PASSWORD_BCRYPT 1');
            $this->sectionLine(self::SEC_CONSTS, '#define TPHP_CONST_PASSWORD_BCRYPT_DEFAULT_COST 10');
        }
        foreach ($node->constants as $c) {
            $this->sectionLine(self::SEC_CONSTS, $c->accept($this));
        }

        // ── SEC_ENUMS ──
        foreach ($node->enums as $e) {
            $this->sectionBlock(self::SEC_ENUMS, $e->accept($this));
        }

        $allClasses = array_merge(
            $node->mainClass ? [$node->mainClass] : [],
            $node->extraClasses
        );
        // Topological sort: parent classes before children
        $sorted = [];
        $seen = [];
        $byRefName = [];
        foreach ($allClasses as $c) { $byRefName[self::classRefName($c->name)] = $c; }
        $addClass = function ($cn) use (&$addClass, &$seen, &$sorted, $byRefName) {
            if (isset($seen[$cn])) return;
            $seen[$cn] = true;
            if (isset($byRefName[$cn]) && $byRefName[$cn]->parentName !== null) {
                $pcn = self::classRefName($byRefName[$cn]->parentName);
                if (isset($byRefName[$pcn])) $addClass($pcn);
            }
            if (isset($byRefName[$cn])) $sorted[] = $byRefName[$cn];
        };
        foreach ($byRefName as $cn => $_) $addClass($cn);
        $allClasses = $sorted;
        $mainClassName = $node->mainClass ? self::classCName($node->mainClass) : '';

        // ── SEC_CLSFWDS: Phase 1 — 所有类的 struct + 前置声明 ──
        foreach ($allClasses as $class) {
            $this->className = self::classCName($class);
            $isMain = (self::classCName($class) === $mainClassName);
            $this->sectionBlock(self::SEC_CLSFWDS, $this->emitClassForward($class, $isMain));
        }

        // ── SEC_FUNCFWDS: 独立函数前置声明 ──
        foreach ($node->functions as $fn) {
            $fnCName = self::funcCName($fn);
            if (!empty($this->funcIsGenerator[$fnCName])) {
                $ret = 'tphp_class_Generator*';
            } else {
                $ret = self::mapType($fn->returnType);
            }
            $this->funcRetTypes[$fnCName] = $ret;
            $this->funcParamTypes[$fnCName] = array_map(fn($p) => self::paramCType($p), $fn->params);
            // 计算默认值参数数量
            $defaultCount = 0;
            $totalParams = count($fn->params);
            for ($i = $totalParams - 1; $i >= 0; $i--) {
                if ($fn->params[$i]->default !== null) {
                    $defaultCount++;
                } else {
                    break;
                }
            }
            $this->funcDefaultCounts[$fnCName] = $defaultCount;
            $params = array_map(fn($p) => $this->visitParam($p), $fn->params);
            $this->sectionLine(self::SEC_FUNCFWDS,
                'static ' . $ret . ' ' . $fnCName . '(' . implode(', ', $params) . ');');
            // 为有默认值的函数生成重载函数前置声明
            if ($defaultCount > 0) {
                for ($cutIdx = $totalParams - $defaultCount; $cutIdx < $totalParams; $cutIdx++) {
                    $overloadName = $fnCName . '_' . ($totalParams - $cutIdx);
                    $cutParams = array_slice($fn->params, 0, $cutIdx);
                    $overloadParams = array_map(fn($p) => $this->visitParam($p), $cutParams);
                    $this->sectionLine(self::SEC_FUNCFWDS,
                        'static ' . $ret . ' ' . $overloadName . '(' . implode(', ', $overloadParams) . ');');
                }
            }
        }

        // ── SEC_CLSIMPL: Phase 2 — 所有类的方法实现 + allocator ──
        // 前向声明所有类描述符（catch 子句引用 _class_tphp_class_* 时需要）
        $clsFwdDecls = [];
        foreach ($allClasses as $class) {
            $cn = self::classCName($class);
            if ($class->isAbstract && $class->parentName === null && empty($class->properties)) continue;
            $clsFwdDecls[] = "static const t_class _class_{$cn};";
        }
        // Exception 内置类
        $clsFwdDecls[] = "static const t_class _class_tphp_class_Exception;";
        if (!empty($clsFwdDecls)) {
            $this->sectionBlock(self::SEC_CLSIMPL, "/* ── Class descriptor forward declarations ── */\n" . implode("\n", $clsFwdDecls) . "\n");
        }
        foreach ($allClasses as $class) {
            $this->className = self::classCName($class);
            $isMain = (self::classCName($class) === $mainClassName);
            $this->sectionBlock(self::SEC_CLSIMPL, $this->emitClassImpl($class, $isMain));
        }

        // ── SEC_FUNCIMPL: 独立函数实现 ──
        foreach ($node->functions as $fn) {
            $this->sectionBlock(self::SEC_FUNCIMPL, $fn->accept($this));
        }

        // ── SEC_MAIN: C 入口 ──
        if ($node->mainClass !== null) {
            $this->className = self::classCName($node->mainClass);
            $this->sectionBlock(self::SEC_MAIN, $this->generateCEntry());
        }

        return $this->renderSections();
    }

    /** 预扫描生成器函数：填充 funcIsGenerator + 预填 funcRetTypes */
    private function preScanGenerators(ProgramNode $node): void
    {
        foreach ($node->functions as $fn) {
            if ($fn->isGenerator) {
                $cn = self::funcCName($fn);
                $this->funcIsGenerator[$cn] = true;
                $this->funcRetTypes[$cn] = 'tphp_class_Generator*';
            }
        }
    }

    /** 重置状态（每次 generate 调用时） */
    private function resetState(): void
    {
        $this->varTypes = [];
        $this->declaredVars = [];
        $this->tmpVarCounter = 0;
        $this->closureCounter = 0;
        $this->capTypeCounter = 0;
        $this->thunkCounter = 0;
        $this->sections = [];
        $this->funcDefaultCounts = [];
        $this->funcIsGenerator = [];
        $this->inGenerator = false;
        $this->symbols = new SymbolTable();
        // 内置 Exception 类
        $this->symbols->addClass('tphp_class_Exception');
        $this->symbols->addClassName('Exception', 'tphp_class_Exception');
        $this->symbols->getClass('tphp_class_Exception')->methods['getMessage']    = new MethodInfo('t_string');
        $this->symbols->getClass('tphp_class_Exception')->methods['__construct'] = new MethodInfo('void');
        $this->symbols->getClass('tphp_class_Exception')->methods['__destruct']  = new MethodInfo('void');
        $this->classOwnProps['tphp_class_Exception']['message'] = true;
        $this->classParentName['tphp_class_Exception'] = '';
        $this->classPropTypes['tphp_class_Exception']['message'] = 't_string';
        $this->classMethodRetTypes['tphp_class_Exception'] = [
            'getMessage'   => 't_string',
            '__construct'  => 'void',
            '__destruct'   => 'void',
        ];

        // 内置 Generator 类（基于 minicoro 协程）
        $this->symbols->addClass('tphp_class_Generator');
        $this->symbols->addClassName('Generator', 'tphp_class_Generator');
        $this->symbols->getClass('tphp_class_Generator')->methods['current']   = new MethodInfo('t_var');
        $this->symbols->getClass('tphp_class_Generator')->methods['key']       = new MethodInfo('t_var');
        $this->symbols->getClass('tphp_class_Generator')->methods['next']      = new MethodInfo('t_var');
        $this->symbols->getClass('tphp_class_Generator')->methods['send']      = new MethodInfo('t_var', ['t_var']);
        $this->symbols->getClass('tphp_class_Generator')->methods['valid']    = new MethodInfo('t_int');
        $this->symbols->getClass('tphp_class_Generator')->methods['getReturn'] = new MethodInfo('t_var');
        $this->symbols->getClass('tphp_class_Generator')->methods['rewind']    = new MethodInfo('void');
        $this->classMethodRetTypes['tphp_class_Generator'] = [
            'current' => 't_var', 'key' => 't_var', 'next' => 't_var',
            'send' => 't_var', 'valid' => 't_int', 'getReturn' => 't_var', 'rewind' => 'void',
        ];
        $this->methodParamTypes['tphp_class_Generator'] = ['send' => ['t_var']];
        $this->classParentName['tphp_class_Generator'] = '';

        // 内置 Resource 类（资源对象化根，用户可 extends Resource）
        $this->symbols->addClass('tphp_class_Resource');
        $this->symbols->addClassName('Resource', 'tphp_class_Resource');
        $this->symbols->getClass('tphp_class_Resource')->methods['__construct'] = new MethodInfo('void');
        $this->symbols->getClass('tphp_class_Resource')->methods['__destruct']  = new MethodInfo('void');
        $this->symbols->getClass('tphp_class_Resource')->methods['getType']     = new MethodInfo('t_int');
        $this->classMethodRetTypes['tphp_class_Resource'] = ['__construct' => 'void', '__destruct' => 'void', 'getType' => 't_int'];
        $this->classParentName['tphp_class_Resource'] = '';

        // 内置 File 类（Resource 子类，替代 fopen resource）
        $this->symbols->addClass('tphp_class_File', 'tphp_class_Resource');
        $this->symbols->addClassName('File', 'tphp_class_File');
        $this->symbols->getClass('tphp_class_File')->methods['__construct'] = new MethodInfo('void', ['t_string', 't_string']);
        $this->symbols->getClass('tphp_class_File')->methods['__destruct']  = new MethodInfo('void');
        $this->symbols->getClass('tphp_class_File')->methods['getType']     = new MethodInfo('t_int');
        $this->symbols->getClass('tphp_class_File')->methods['read']        = new MethodInfo('t_string', ['t_int']);
        $this->symbols->getClass('tphp_class_File')->methods['write']       = new MethodInfo('t_int', ['t_string']);
        $this->symbols->getClass('tphp_class_File')->methods['eof']         = new MethodInfo('t_bool');
        $this->symbols->getClass('tphp_class_File')->methods['close']       = new MethodInfo('void');
        $this->symbols->getClass('tphp_class_File')->methods['isOpen']      = new MethodInfo('t_bool');
        $this->classMethodRetTypes['tphp_class_File'] = [
            '__construct' => 'void', '__destruct' => 'void', 'getType' => 't_int',
            'read' => 't_string', 'write' => 't_int', 'eof' => 't_bool',
            'close' => 'void', 'isOpen' => 't_bool'
        ];
        $this->classParentName['tphp_class_File'] = 'tphp_class_Resource';
    }

    // ── 多段输出方法 ─────────────────────────────────────────

    /** 向指定段追加一行 */
    private function sectionLine(string $section, string $line): void
    {
        $this->sections[$section][] = $line;
    }

    /** 向指定段追加多行 */
    private function sectionLines(string $section, array $lines): void
    {
        if (empty($lines)) return;
        if (!isset($this->sections[$section])) $this->sections[$section] = [];
        array_push($this->sections[$section], ...$lines);
    }

    /** 向指定段追加字符串块（含换行） */
    private function sectionBlock(string $section, string $block): void
    {
        $block = rtrim($block);
        if ($block === '') return;
        $this->sections[$section][] = $block;
    }

    /** 按固定顺序渲染所有段 → 最终 C 代码字符串 */
    private function renderSections(): string
    {
        $order = [
            self::SEC_HEADER,
            self::SEC_INCLUDES,
            self::SEC_CAPTYPES,
            self::SEC_FWDDECLS,
            self::SEC_THUNKVARS,
            self::SEC_CONSTS,
            self::SEC_ENUMS,
            self::SEC_CLSFWDS,
            self::SEC_FUNCFWDS,
            self::SEC_CLSIMPL,
            self::SEC_FUNCIMPL,
            self::SEC_CLOSURES,
            self::SEC_THUNKS,
            self::SEC_MAIN,
        ];
        // 段注释头（仅在段有内容时输出）
        $labels = [
            self::SEC_CAPTYPES  => "/* ── 闭包捕获类型 ──────────────────────────── */",
            self::SEC_FWDDECLS  => "/* ── 前置声明 ────────────────────────────────── */",
            self::SEC_THUNKVARS => "/* ── 闭包 Thunk 静态副本 ──────────────────── */",
            self::SEC_CLOSURES  => "/* ── 闭包函数实现 ──────────────────────────── */",
            self::SEC_THUNKS    => "/* ── 闭包 Thunk（C 回调适配） ──────────────────── */",
        ];
        $lines = [];
        foreach ($order as $sec) {
            if (empty($this->sections[$sec])) continue;
            // 段间空行
            if (!empty($lines)) $lines[] = '';
            // 段注释头
            if (isset($labels[$sec])) {
                $lines[] = $labels[$sec];
            }
            $lines[] = implode("\n", $this->sections[$sec]);
        }
        return implode("\n", $lines) . "\n";
    }

    /** Phase 1: struct + 前置声明 */
    private function emitClassForward(ClassNode $class, bool $isMain): string
    {
        // Skip interface-only classes (abstract + no parent + no properties)
        if ($class->isAbstract && $class->parentName === null && empty($class->properties)) {
            return "/* interface {$class->name} — compile-time only */\n";
        }
        $cn = self::classCName($class);
        $ctor = $dtor = null;
        $methods = [];
        foreach ($class->methods as $m) {
            if ($m->name === '__construct') $ctor = $m;
            elseif ($m->name === '__destruct') $dtor = $m;
            else $methods[] = $m;
        }

        $o = [];
        $o[] = "/* ── Struct: {$cn} ──────────────────────────── */";
        $o[] = 'typedef struct {';
        $o[] = $this->ind('t_object _obj;');   // COS-style header (cls ptr + refcount)
        // Parent struct (COS inheritance: struct nesting)
        $parentCN = '';
        if ($class->parentName !== null) {
            $parentCN = self::classRefName($class->parentName);
            $o[] = $this->ind($parentCN . ' _parent;');
        }
        // 属性字段 + 记录类型
        $propTypes = [];
        foreach ($class->properties as $prop) {
            $ptype = self::mapType($prop->type);
            $pname = ltrim($prop->name, '$');
            $o[] = $this->ind("{$ptype} {$pname};");
            $propTypes[$pname] = $ptype;
        }
        // 数组类常量字段（每个实例持有，简单可靠）
        foreach ($class->classConsts as $cc) {
            if ($cc->value instanceof ArrayLiteralExpr) {
                $fname = '_const_' . $cc->name;
                $o[] = $this->ind("t_array* {$fname};");
            }
        }
        // ── 注册到统一符号表 ──
        $this->symbols->addClass($cn, $parentCN, $class->isAbstract, $class->implements);
        $this->symbols->addClassName($class->name, $cn);
        foreach ($propTypes as $pn => $pt) {
            $this->symbols->addClassProp($cn, $pn, $pt);
            // 同步到旧数组（过渡期）
            $this->classPropTypes[$cn][$pn] = $pt;
            $this->classOwnProps[$cn][$pn] = true;
        }
        if ($parentCN !== '') {
            $this->classParentName[$cn] = $parentCN;
        }
        // 方法返回类型
        $this->symbols->getClass($cn)->methods['__construct'] = new MethodInfo('void');
        $this->symbols->getClass($cn)->methods['__destruct']  = new MethodInfo('void');
        $this->classMethodRetTypes[$cn] = ['__construct' => 'void', '__destruct' => 'void'];
        foreach ($methods as $m) {
            $mr = $this->mapType($m->returnType);
            $this->symbols->getClass($cn)->methods[$m->name] = new MethodInfo($mr);
            $this->classMethodRetTypes[$cn][$m->name] = $mr;
        }
        $o[] = "} {$cn};";
        $o[] = '';
        // 类常量 → #define（简单类型）或 static 变量（array）
        $this->classNames[$class->name] = $cn;
        foreach ($class->classConsts as $cc) {
            $cname = 'TPHP_CONST_' . strtoupper($cn . '_' . $cc->name);
            $fullName = $cn . '_' . $cc->name;
            $vis = $cc->visibility ?? 'public';
            $this->constVis[$fullName] = $vis;
            $this->constVis[$cname] = $vis;
            // 声明类型 vs 字面量类型一致性校验，并以声明类型注册
            $declCType = self::mapType($cc->type);
            $litCType  = self::$litTypeMap[$cc->value::class] ?? null;
            if ($litCType !== null && $declCType !== $litCType) {
                throw new \RuntimeException(
                    "Class constant {$cn}::{$cc->name} type mismatch: "
                    . "declared '{$cc->type}' ({$declCType}) but value is {$litCType}"
                );
            }
            if ($cc->value instanceof StringLiteralExpr) {
                $this->symbols->addConst($fullName, $declCType, $vis);
                $this->constTypes[$fullName] = $declCType;
                $this->constTypes[$cname] = $declCType;
                $val = str_replace('"', '\\"', $cc->value->value);
                $o[] = "#define {$cname} STR_LIT(\"{$val}\")";
            } elseif ($cc->value instanceof IntLiteralExpr) {
                $this->symbols->addConst($fullName, $declCType, $vis);
                $this->constTypes[$fullName] = $declCType;
                $this->constTypes[$cname] = $declCType;
                $o[] = "#define {$cname} {$cc->value->value}";
            } elseif ($cc->value instanceof FloatLiteralExpr) {
                $this->symbols->addConst($fullName, $declCType, $vis);
                $this->constTypes[$fullName] = $declCType;
                $this->constTypes[$cname] = $declCType;
                $fv = $cc->value->value;
                $o[] = '#define ' . $cname . ' ' .
                    (($fv == (float)(int)$fv) ? sprintf('%.1f', $fv) : rtrim(rtrim(sprintf('%.15g', $fv), '0'), '.'));
            } elseif ($cc->value instanceof BoolLiteralExpr) {
                $this->symbols->addConst($fullName, $declCType, $vis);
                $this->constTypes[$fullName] = $declCType;
                $this->constTypes[$cname] = $declCType;
                $o[] = "#define {$cname} " . ($cc->value->value ? 'true' : 'false');
            } elseif ($cc->value instanceof ArrayLiteralExpr) {
                // 数组常量：static 变量（不注册 constTypes，访问走独立路径）
                $o[] = "static t_array* {$cname} = NULL;";
                $o[] = "/* initialized on first access via {$cn} class */";
            }
        }
        if (!empty($class->classConsts)) $o[] = '';

        // __construct 声明
        if ($isMain) {
            $o[] = "void {$cn}___construct({$cn}* self, t_int argc, t_array* argv);";
        } else {
            $ctorParams = $this->ctorParamStr($ctor);
            $o[] = "void {$cn}___construct({$cn}* self" . ($ctorParams ? ', ' . $ctorParams : '') . ");";
        }
        $o[] = "void {$cn}___destruct({$cn}* self);";
        foreach ($methods as $m) {
            $o[] = $this->methodDecl($m) . ';';
        }
        if ($isMain) {
            $o[] = "{$cn}* new_{$cn}(t_int argc, t_array* argv);";
        } else {
            $ctorParams = $this->ctorParamStr($ctor);
            $o[] = "{$cn}* new_{$cn}(" . ($ctorParams ? $ctorParams : 'void') . ");";
        }
        $o[] = '';

        return implode("\n", $o);
    }

    /** 生成构造参数声明字符串（不含 self），如 "t_string bb" */
    private function ctorParamStr(?MethodNode $ctor): string
    {
        if ($ctor === null) return '';
        return implode(', ', array_map(fn($p) => $this->visitParam($p), $ctor->params));
    }

    /** Phase 2: VTable + 方法实现 + allocator */
    private function emitClassImpl(ClassNode $class, bool $isMain): string
    {
        // Skip interface-only classes
        if ($class->isAbstract && $class->parentName === null && empty($class->properties)) {
            return '';
        }
        $cn = self::classCName($class);
        $ctor = $dtor = null;
        $methods = [];
        foreach ($class->methods as $m) {
            if ($m->name === '__construct') $ctor = $m;
            elseif ($m->name === '__destruct') $dtor = $m;
            else $methods[] = $m;
            // 记录方法参数类型（用于 visitCall 中 t_var 参数包裹）
            $pts = array_map(fn($p) => $this->mapType($p->type), $m->params);
            $this->methodParamTypes[$cn][$m->name] = $pts;
            if ($mi = $this->symbols->getClassMethod($cn, $m->name)) {
                $mi = new MethodInfo($mi->retType, $pts);
            }
            $this->symbols->getClass($cn)->methods[$m->name] = $mi ?? new MethodInfo('void', $pts);
        }

        $o = [];

        // Class descriptor (COS-style)
        $parentPtr = ($class->parentName !== null)
            ? '&_class_' . self::classRefName($class->parentName)
            : 'NULL';
        $o[] = "/* ── Class: {$cn} ──────────────────────────── */";
        $o[] = "static void* _vtable_{$cn}[1] = { NULL };";
        $o[] = "static const t_class _class_{$cn} = {";
        $o[] = $this->ind("    .name          = \"{$cn}\",");
        $o[] = $this->ind("    .parent        = {$parentPtr},");
        $o[] = $this->ind("    .instance_size = sizeof({$cn}),");
        $o[] = $this->ind("    .exception_offset = " . $this->computeExceptionOffset($cn) . ",");
        $o[] = $this->ind("    .dtor          = (void*){$cn}___destruct,");
        $o[] = $this->ind("    .vtable        = _vtable_{$cn},");
        $o[] = $this->ind("    .vtable_len    = 0,");
        $o[] = $this->ind("};");
        $o[] = '';

        // __construct — 注入参数类型到 varTypes
        $this->declaredVars = ['self' => true];
        $this->varTypes = [];
        $this->symbols->clearScopeObjects();

        if ($isMain) {
            $this->declaredVars['argc'] = true;
            $this->declaredVars['argv'] = true;
            $this->varTypes['argc'] = 't_int';
            $this->varTypes['argv'] = 't_array*';
        } else if ($ctor) {
            foreach ($ctor->params as $p) {
                $vn = self::varName($p->name);
                $this->declaredVars[$vn] = true;
                $this->varTypes[$vn] = self::mapType($p->type);
            }
        }

        $ctorSig = $isMain
            ? "void {$cn}___construct({$cn}* self, t_int argc, t_array* argv) {"
            : "void {$cn}___construct({$cn}* self" . ($this->ctorParamStr($ctor) ? ', ' . $this->ctorParamStr($ctor) : '') . ") {";
        $o[] = $ctorSig;
        $o[] = $this->ind('if (self == NULL) return;');

        // 属性默认值初始化 — 字符串用深拷贝
        foreach ($class->properties as $prop) {
            if ($prop->default !== null) {
                $pname = ltrim($prop->name, '$');
                $def = $prop->default->accept($this);
                if ($prop->type === 'string' && $prop->default instanceof StringLiteralExpr) {
                    // 字符串默认值：深拷贝到堆
                    $o[] = $this->ind("self->{$pname} = tphp_rt_str_dup({$def});");
                } else {
                    $o[] = $this->ind("self->{$pname} = {$def};");
                }
            }
        }

        // 数组类常量初始化（每个实例持有一份拷贝）
        foreach ($class->classConsts as $cc) {
            if ($cc->value instanceof ArrayLiteralExpr) {
                $fname = '_const_' . $cc->name;
                $tn = '_c_' . $cc->name;
                $o[] = $this->ind("self->{$fname} = ({ {$this->genArrayLiteralInline($cc->value, $tn)} {$tn}; });");
            }
        }

        // 构造函数参数中的字符串属性：深拷贝到堆（防止栈/临时内存悬空）
        if (!$isMain && $ctor) {
            foreach ($ctor->params as $p) {
                $pname = ltrim($p->name, '$');
                // 检查是否为字符串属性参数
                $isStrProp = false;
                foreach ($class->properties as $prop) {
                    if (ltrim($prop->name, '$') === $pname && $prop->type === 'string') {
                        $isStrProp = true;
                        break;
                    }
                }
                if ($isStrProp) {
                    $o[] = $this->ind("self->{$pname} = tphp_rt_str_dup({$pname});");
                }
            }
        }

        if ($ctor && !empty($ctor->body)) {
            foreach ($ctor->body as $s) $o[] = $this->ind($s->accept($this));
        } else if ($isMain) {
            $o[] = $this->ind('(void)argc;');
            $o[] = $this->ind('(void)argv;');
        }
        $o[] = '}';
        $o[] = '';

        // __destruct — 先跑用户代码，再释放字符串属性
        $o[] = "void {$cn}___destruct({$cn}* self) {";
        $o[] = $this->ind('if (self == NULL) return;');
        if ($dtor && !empty($dtor->body)) {
            foreach ($dtor->body as $s) $o[] = $this->ind($s->accept($this));
        }
        // 自动释放所有 t_string 属性的堆内存
        foreach ($class->properties as $prop) {
            if ($prop->type === 'string') {
                $pname = ltrim($prop->name, '$');
                $o[] = $this->ind("tphp_rt_str_free(&self->{$pname});");
            }
        }
        $o[] = '}';
        $o[] = '';

        // 用户方法
        $this->indent = 1;  // reset for scope tracking
        foreach ($methods as $m) {
            $o[] = $m->accept($this);
            $o[] = '';
        }

        // Allocator — skip for abstract classes
        if (!$class->isAbstract) {
            $o[] = "/* ── Allocator: new_{$cn} ──────────────── */";
            $ctorParams = $isMain ? 't_int argc, t_array* argv' : ($this->ctorParamStr($ctor) ?: 'void');
            $o[] = "{$cn}* new_{$cn}({$ctorParams}) {";
            $o[] = $this->ind("{$cn}* self = ({$cn}*)tp_obj_alloc(&_class_{$cn});");
            $o[] = $this->ind('if (self == NULL) return NULL;');
            if ($isMain) {
                $o[] = $this->ind("{$cn}___construct(self, argc, argv);");
            } else {
                $ctorArgs = $ctor ? implode(', ', array_map(fn($p) => self::varName($p->name), $ctor->params)) : '';
                $o[] = $this->ind("{$cn}___construct(self" . ($ctorArgs ? ', ' . $ctorArgs : '') . ");");
            }
            $o[] = $this->ind('return self;');
            $o[] = '}';
            $o[] = '';
        }

        return implode("\n", $o);
    }

    // ============================================================
    public function visitClass(ClassNode $node): string { return ''; }

    /** 独立函数返回类型追踪：funcCName → C 类型 */

    public function visitFunction(FunctionNode $node): string
    {
        if ($node->isGenerator) {
            return $this->emitGeneratorFunction($node);
        }
        $this->declaredVars = [];
        $this->varTypes = [];
        $this->symbols->clearScopeObjects();
        $this->symbols->clearScopeVars();
        $this->funcScopeDecls = [];
        $ret = self::mapType($node->returnType);
        $this->currentRetType = $ret;
        // 注册返回类型，供 inferCallReturnType 使用
        $this->funcRetTypes[self::funcCName($node)] = $ret;

        // 检查是否有默认值参数
        $hasDefaults = false;
        foreach ($node->params as $p) {
            if ($p->default !== null) {
                $hasDefaults = true;
                break;
            }
        }

        $parts = [];

        // 生成重载函数（如果有默认值参数）
        if ($hasDefaults) {
            $parts[] = $this->generateFunctionOverloads($node, $ret);
        }

        // 生成主函数（完整参数版本）
        $this->declaredVars = [];
        $this->varTypes = [];
        $this->symbols->clearScopeObjects();
        $this->symbols->clearScopeVars();
        $this->funcScopeDecls = [];

        $params = array_map(fn($p) => self::paramDecl($p), $node->params);
        $paramVars = [];
        foreach ($node->params as $p) {
            $vn = self::varName($p->name);
            $this->declaredVars[$vn] = true;
            $this->varTypes[$vn] = self::paramCType($p);
            $paramVars[$vn] = true;
        }
        $header = [];
        $header[] = 'static ' . $ret . ' ' . self::funcCName($node) . '(' . implode(', ', $params) . ') {';

        $bodyLines = [];
        foreach ($node->body as $s) $bodyLines[] = $this->ind($s->accept($this));

        // 注入 for 循环提升声明
        $declLines = [];
        foreach ($this->funcScopeDecls as $vn => $ct) {
            $declLines[] = $this->ind("{$ct} {$vn};");
        }

        // 自动生成作用域结束时的释放代码
        $tail = [];
        $tail = array_merge($tail, $this->generateScopeCleanup($paramVars));
        foreach ($this->symbols->scopeObjects() as $ov) {
            $tail[] = $this->ind("tp_obj_release({$ov});");
        }
        $tail[] = '}';
        $parts[] = implode("\n", array_merge($header, $declLines, $bodyLines, $tail));

        return implode("\n\n", $parts);
    }

    /**
     * 生成器函数变换：PHP function gen(): Generator { yield ...; }
     * 编译为两个 C 函数：
     *   1) 协程入口 static void tphp_gen_<name>_entry(mco_coro* co) { 函数体 }
     *   2) 包装函数   tphp_class_Generator* tphp_fn_<name>(params) { 创建协程 }
     */
    private function emitGeneratorFunction(FunctionNode $node): string
    {
        $fnCName = self::funcCName($node);
        $entryName = 'tphp_gen_' . $fnCName . '_entry';
        $paramsStruct = '_gen_params_' . $fnCName;

        // 保存状态
        $savedDeclaredVars = $this->declaredVars;
        $savedVarTypes = $this->varTypes;
        $savedCurrentRetType = $this->currentRetType;
        $savedInGenerator = $this->inGenerator;

        // 重置作用域
        $this->declaredVars = [];
        $this->varTypes = [];
        $this->symbols->clearScopeObjects();
        $this->symbols->clearScopeVars();
        $this->funcScopeDecls = [];
        $this->currentRetType = 't_var';
        $this->inGenerator = true;

        // 注册参数到局部变量表（与 visitFunction 一致）
        $paramVars = [];
        $paramFields = [];
        $paramLocalDecls = [];
        foreach ($node->params as $p) {
            $vn = self::varName($p->name);
            $ct = self::paramCType($p);
            $this->declaredVars[$vn] = true;
            $this->varTypes[$vn] = $ct;
            $paramVars[$vn] = true;
            $paramFields[] = "    {$ct} {$vn};";
            $paramLocalDecls[] = "    {$ct} {$vn};";
        }

        // 解包参数：从 user_data 复制到局部变量
        $unpackLines = [];
        $unpackLines[] = "    {$paramsStruct}* _p = ({$paramsStruct}*)mco_get_user_data(co);";
        foreach ($node->params as $p) {
            $vn = self::varName($p->name);
            $unpackLines[] = "    {$vn} = _p->{$vn};";
        }
        $unpackLines[] = '    free(_p);';
        $unpackLines[] = '    int _auto_key = 0;';

        // 生成函数体（yield→visitYieldExpr, return→visitReturnStmt 生成器分支）
        $bodyLines = [];
        foreach ($node->body as $s) {
            $bodyLines[] = $this->ind($s->accept($this));
        }

        // for 循环提升声明
        $declLines = [];
        foreach ($this->funcScopeDecls as $vn => $ct) {
            $declLines[] = $this->ind("{$ct} {$vn};");
        }

        // 末尾释放（局部字符串/数组/对象）
        $tailLines = [];
        foreach ($this->generateScopeCleanup($paramVars) as $l) {
            $tailLines[] = $l;
        }
        foreach ($this->symbols->scopeObjects() as $ov) {
            $tailLines[] = $this->ind("tp_obj_release({$ov});");
        }

        // 恢复状态
        $this->declaredVars = $savedDeclaredVars;
        $this->varTypes = $savedVarTypes;
        $this->currentRetType = $savedCurrentRetType;
        $this->inGenerator = $savedInGenerator;

        // 参数结构体 typedef → SEC_FWDDECLS
        $typedef = "typedef struct {\n" . implode("\n", $paramFields) . "\n} {$paramsStruct};";
        $this->sectionLine(self::SEC_FWDDECLS, $typedef);

        // 协程入口函数
        $entryLines = array_merge(
            ["static void {$entryName}(mco_coro* co) {"],
            $paramLocalDecls,
            $unpackLines,
            $declLines,
            $bodyLines,
            $tailLines,
            ["}"]
        );
        $entryFn = implode("\n", $entryLines);

        // 包装函数
        $paramDecls = array_map(fn($p) => self::paramDecl($p), $node->params);
        $paramAssigns = [];
        foreach ($node->params as $p) {
            $vn = self::varName($p->name);
            $paramAssigns[] = "    _p->{$vn} = {$vn};";
        }
        $wrapperLines = array_merge(
            ["tphp_class_Generator* {$fnCName}(" . implode(', ', $paramDecls) . ") {"],
            ["    {$paramsStruct}* _p = ({$paramsStruct}*)calloc(1, sizeof({$paramsStruct}));"],
            $paramAssigns,
            ["    mco_desc desc = mco_desc_init({$entryName}, 0);"],
            ["    desc.user_data = _p;"],
            ["    mco_coro* co;"],
            ["    if (mco_create(&co, &desc) != MCO_SUCCESS) { free(_p); return NULL; }"],
            ["    return new_tphp_class_Generator(co);"],
            ["}"]
        );
        $wrapperFn = implode("\n", $wrapperLines);

        return $entryFn . "\n\n" . $wrapperFn;
    }

    /**
     * 为有默认值的函数生成重载版本
     * 例如: function foo(int $a, int $b = 10, int $c = 20)
     * 生成: foo_2($a) → foo($a, 10, 20)
     *        foo_1($a, $b) → foo($a, $b, 20)
     */
    private function generateFunctionOverloads(FunctionNode $node, string $ret): string
    {
        $parts = [];
        $funcName = self::funcCName($node);

        // 找到第一个有默认值的参数位置
        $firstDefaultIdx = count($node->params);
        for ($i = 0; $i < count($node->params); $i++) {
            if ($node->params[$i]->default !== null) {
                $firstDefaultIdx = $i;
                break;
            }
        }

        // 生成从 firstDefaultIdx 到 count-1 的重载版本
        for ($cutIdx = $firstDefaultIdx; $cutIdx < count($node->params); $cutIdx++) {
            $overloadName = $funcName . '_' . (count($node->params) - $cutIdx);
            $cutParams = array_slice($node->params, 0, $cutIdx);

            // 重载函数参数列表
            $overloadParams = array_map(fn($p) => self::paramDecl($p), $cutParams);

            // 调用完整参数版本时传递的参数
            $callArgs = [];
            for ($i = 0; $i < count($node->params); $i++) {
                if ($i < $cutIdx) {
                    // 直接传递参数
                    $callArgs[] = self::varName($node->params[$i]->name);
                } else {
                    // 使用默认值
                    $callArgs[] = $node->params[$i]->default->accept($this);
                }
            }

            $overloadBody = "    return {$funcName}(" . implode(', ', $callArgs) . ");";
            $parts[] = "static {$ret} {$overloadName}(" . implode(', ', $overloadParams) . ") {\n{$overloadBody}\n}";
        }

        return implode("\n\n", $parts);
    }

    /** 根据 C 返回类型生成零值 return（兼容 GCC/Clang -Wreturn-mismatch） */
    private function zeroReturn(string $cType): string
    {
        return match ($cType) {
            'void'    => 'return;',
            't_int'   => 'return 0;',
            't_float' => 'return 0.0;',
            't_bool'  => 'return false;',
            't_string'=> 'return (t_string){NULL, 0};',
            't_array*'=> 'return NULL;',
            't_var'   => 'return (t_var){0};',
            't_callback' => 'return (t_callback){NULL, NULL};',
            'void*'   => 'return NULL;',
            default   => str_ends_with($cType, '*')
                ? 'return NULL;'
                : 'return 0;',
        };
    }

    public function visitMethod(MethodNode $node): string
    {
        if ($node->isGenerator) {
            throw new \RuntimeException("Generator methods not yet supported (in method {$node->name})");
        }
        $this->currentMethodName = $node->name;
        $this->declaredVars = ['self' => true];
        $this->varTypes = [];
        $this->symbols->clearScopeObjects();
        $this->symbols->clearScopeVars();
        $this->funcScopeDecls = [];
        $this->currentRetType = $this->mapType($node->returnType);

        // 检查是否有默认值参数
        $hasDefaults = false;
        foreach ($node->params as $p) {
            if ($p->default !== null) {
                $hasDefaults = true;
                break;
            }
        }

        $parts = [];

        // 生成重载函数（如果有默认值参数）
        if ($hasDefaults) {
            $parts[] = $this->generateMethodOverloads($node);
        }

        // 生成主方法（完整参数版本）
        $this->currentMethodName = $node->name;
        $this->declaredVars = ['self' => true];
        $this->varTypes = [];
        $this->symbols->clearScopeObjects();
        $this->symbols->clearScopeVars();
        $this->funcScopeDecls = [];
        $this->currentRetType = $this->mapType($node->returnType);

        $paramVars = ['self' => true];
        foreach ($node->params as $p) {
            $vn = self::varName($p->name);
            $this->declaredVars[$vn] = true;
            $this->varTypes[$vn] = self::paramCType($p);
            $paramVars[$vn] = true;
        }
        // Phase 1: header
        $header = [];
        $header[] = $this->methodImpl($node) . ' {';
        $header[] = $this->ind('if (self == NULL) ' . $this->zeroReturn($this->currentRetType));

        // Phase 2: body (侧作用: 填充 funcScopeDecls)
        $bodyLines = [];
        if ($node->body === null) {
            // abstract method — forward declaration only, no implementation
            return '';
        }
        if (empty($node->body)) {
            foreach ($node->params as $p) $bodyLines[] = $this->ind("(void)" . self::varName($p->name) . ";");
        } else {
            foreach ($node->body as $s) $bodyLines[] = $this->ind($s->accept($this));
        }

        // Phase 3: 注入 for 循环提升到函数作用域的变量声明（在 body 之前）
        $declLines = [];
        foreach ($this->funcScopeDecls as $vn => $ct) {
            $declLines[] = $this->ind("{$ct} {$vn};");
        }

        // 自动生成作用域结束时的释放代码
        $tail = [];
        $tail = array_merge($tail, $this->generateScopeCleanup($paramVars));
        foreach ($this->symbols->scopeObjects() as $ov) {
            $tail[] = $this->ind("tp_obj_release({$ov});");
        }
        $tail[] = '}';

        $parts[] = implode("\n", array_merge($header, $declLines, $bodyLines, $tail));

        return implode("\n\n", $parts);
    }

    /**
     * 为有默认值的方法生成重载版本
     */
    private function generateMethodOverloads(MethodNode $node): string
    {
        $parts = [];
        $ret = $this->mapType($node->returnType);
        $methodImpl = $this->methodImpl($node);
        // 获取类名（从 methodImpl 中提取）
        $cn = $this->className;

        // 找到第一个有默认值的参数位置
        $firstDefaultIdx = count($node->params);
        for ($i = 0; $i < count($node->params); $i++) {
            if ($node->params[$i]->default !== null) {
                $firstDefaultIdx = $i;
                break;
            }
        }

        // 生成从 firstDefaultIdx 到 count-1 的重载版本
        for ($cutIdx = $firstDefaultIdx; $cutIdx < count($node->params); $cutIdx++) {
            $overloadName = $cn . '_' . $node->name . '_' . (count($node->params) - $cutIdx);
            $cutParams = array_slice($node->params, 0, $cutIdx);

            // 重载函数参数列表（包含 self）
            // 注意：$cn 已是 classCName() 返回值（含 tphp_class_ 前缀），不再重复添加
            $overloadParams = [$cn . '* self'];
            foreach ($cutParams as $p) {
                $overloadParams[] = self::paramDecl($p);
            }

            // 调用完整参数版本时传递的参数
            $callArgs = ['self'];
            for ($i = 0; $i < count($node->params); $i++) {
                if ($i < $cutIdx) {
                    $callArgs[] = self::varName($node->params[$i]->name);
                } else {
                    $callArgs[] = $node->params[$i]->default->accept($this);
                }
            }

            $overloadBody = "    return {$cn}_{$node->name}(" . implode(', ', $callArgs) . ");";
            $parts[] = "static {$ret} {$overloadName}(" . implode(', ', $overloadParams) . ") {\n{$overloadBody}\n}";
        }

        return implode("\n\n", $parts);
    }

    public function visitParam(ParamNode $node): string
    {
        $ct = self::mapType($node->type);
        return $node->byRef ? "{$ct} *" . self::varName($node->name) : "{$ct} " . self::varName($node->name);
    }

    /**
     * 生成作用域结束时的自动释放代码
     * @param array $paramVars 参数变量名集合（排除在自动释放之外）
     * @return string[] 释放代码行
     */
    private function generateScopeCleanup(array $paramVars): array
    {
        $lines = [];
        $released = [];
        $returnedVars = $this->symbols->returnedVars();

        // 释放字符串变量
        foreach ($this->symbols->scopeStrings() as $vn => $ct) {
            if (isset($paramVars[$vn]) || isset($released[$vn]) || isset($returnedVars[$vn])) continue;
            $lines[] = $this->ind("tphp_fn_unset_str(&{$vn});");
            $released[$vn] = true;
        }

        // 释放数组变量
        foreach ($this->symbols->scopeArrays() as $vn => $ct) {
            if (isset($paramVars[$vn]) || isset($released[$vn]) || isset($returnedVars[$vn])) continue;
            $lines[] = $this->ind("tphp_fn_unset_arr(&{$vn});");
            $released[$vn] = true;
        }

        return $lines;
    }

    // ============================================================
    public function visitEchoStmt(EchoStmtNode $node): string
    {
        $parts = [];
        foreach ($node->exprs as $e) {
            $code = $e->accept($this);
            // 如果表达式不是字符串字面量/变量引用，可能需要转换
            if ($e instanceof StringLiteralExpr) {
                $parts[] = "tphp_fn_echo({$code});";
            } elseif ($e instanceof VariableExpr) {
                // 变量：推导类型决定是否需要转换
                $vn = self::varName($e->name);
                $vt = $this->varTypes[$vn] ?? '';
                if ($vt === 't_var') {
                    // t_var 变量用 var_dump 输出
                    $parts[] = 'tphp_fn_var_dump(' . $this->wrapVar($e) . ');';
                } elseif ($vt === 't_string' || $vt === 't_int' || $vt === 't_float' || $vt === 't_bool') {
                    $parts[] = $this->echoWrap($vt, $code);
                } elseif ($vt === 'tphp_class_Exception*') {
                    // Exception 对象：echo $e 等价 echo $e->getMessage()
                    $parts[] = "tphp_fn_echo(tphp_class_Exception_getMessage({$code}));";
                } else {
                    $parts[] = "tphp_fn_echo({$code});";
                }
            } elseif ($e instanceof EnumAccessExpr) {
                // 枚举访问 → 输出 ->value
                $bt = $this->enumBackingType($e->enumName);
                if ($bt === 'string') {
                    $parts[] = "tphp_fn_echo(({$code})->value);";
                } else {
                    $parts[] = "tphp_fn_echo(tphp_rt_str_from_int(({$code})->value));";
                }
            } elseif ($e instanceof PropertyAccessExpr) {
                // 属性访问：查找属性类型（含 enum 属性 ->value/->name）
                $pt = $this->getPropType($e);
                if ($pt !== '') {
                    $parts[] = $this->echoWrap($pt, $code);
                } else {
                    $parts[] = "tphp_fn_echo({$code});";
                }
            } elseif ($e instanceof CastExpr) {
                // 类型转换：根据目标类型包装
                $ct = $e->castType;
                $parts[] = $ct === 'string' ? "tphp_fn_echo({$code});"
                         : $this->echoWrap(self::$typeMap[$ct] ?? 't_int', $code);
            } elseif ($e instanceof CallExpr) {
                $parts[] = $this->echoWrap('t_int', $code);
            } else {
                $parts[] = "tphp_fn_echo({$code});";
            }
        }
        return implode("\n" . $this->indentStr(), $parts);
    }

    private function echoWrap(string $type, string $code): string
    {
        return match ($type) {
            't_string' => "tphp_fn_echo({$code});",
            't_int'    => "tphp_fn_echo(tphp_rt_str_from_int({$code}));",
            't_float'  => "tphp_fn_echo(tphp_rt_str_from_float({$code}));",
            't_bool'   => "tphp_fn_echo(tphp_rt_str_from_bool({$code}));",
            default    => "tphp_fn_echo({$code});",
        };
    }

    public function visitReturnStmt(ReturnStmtNode $node): string
    {
        if ($this->inGenerator) {
            // 生成器内：push 返回值（t_var），然后裸 return
            if ($node->expr !== null) {
                if ($node->expr instanceof VariableExpr) {
                    $vn = self::varName($node->expr->name);
                    $this->symbols->addReturnedVar($vn);
                }
                $code = $node->expr->accept($this);
                $valVar = $this->wrapTvarAssign($node->expr, $code);
                return "{ t_var _gen_ret = {$valVar}; mco_push(mco_running(), &_gen_ret, sizeof(t_var)); return; }";
            }
            return "{ t_var _gen_ret = VAR_NULL(); mco_push(mco_running(), &_gen_ret, sizeof(t_var)); return; }";
        }
        if ($node->expr) {
            // 追踪返回的变量名（用于排除自动释放）
            if ($node->expr instanceof VariableExpr) {
                $vn = self::varName($node->expr->name);
                $this->symbols->addReturnedVar($vn);
            }
            $code = $node->expr->accept($this);
            if ($this->currentRetType === 't_var') {
                $code = $this->wrapTvarAssign($node->expr, $code);
            }
            return 'return ' . $code . ';';
        }
        return 'return;';
    }

    /**
     * yield 表达式 → GCC statement expression
     * 推送 {key, value} 到协程存储，mco_yield 挂起，恢复后弹出 send 值作为表达式结果
     */
    public function visitYieldExpr(YieldExpr $node): string
    {
        // 计算 value（转 t_var）
        if ($node->value !== null) {
            $valCode = $node->value->accept($this);
            $valVar = $this->wrapTvarAssign($node->value, $valCode);
        } else {
            $valVar = 'VAR_NULL()';
        }

        // 计算 key
        if ($node->key !== null) {
            $keyCode = $node->key->accept($this);
            $keyExpr = $this->wrapTvarAssign($node->key, $keyCode);
        } else {
            $keyExpr = '((t_var){.type = TYPE_INT, .value._int = _auto_key++})';
        }

        // statement expression：push yield pair → yield → pop sent → 返回 t_var
        return "({ _gen_yield_pair _yp; _yp.key = {$keyExpr}; _yp.value = {$valVar}; " .
               "mco_push(mco_running(), &_yp, sizeof(_yp)); mco_yield(mco_running()); " .
               "t_var _sent; if (mco_pop(mco_running(), &_sent, sizeof(t_var)) != MCO_SUCCESS) { _sent = VAR_NULL(); } _sent; })";
    }

    public function visitAssignStmt(AssignStmtNode $node): string
    {
        $var = self::varName($node->varName);
        $isDeclared = isset($this->declaredVars[$var]);
        $prevType = $this->varTypes[$var] ?? '';
        $isTVar = ($prevType === 't_var');

        // 对 t_var 变量，值需包装为 VAR_XXX 宏
        if ($isTVar) {
            $valCode = $node->expr->accept($this);
            $wrap = $this->wrapTvarAssign($node->expr, $valCode);
            $this->declaredVars[$var] = true;
            return "{$var} = {$wrap};";
        }

        $expr = $node->expr->accept($this);
        $this->declaredVars[$var] = true;

        // new ClassName(...) → tphp_ClassName* var = expr; + 注册到全局资源表
        if ($node->expr instanceof NewExpr) {
            $cn = self::classRefName($node->expr->className);
            // 有声明类型时校验一致性
            if ($node->type !== null) {
                $declCType = self::mapType($node->type);
                if ($declCType !== $cn . '*') {
                    throw new \RuntimeException(
                        "Variable \${$var} type mismatch: declared '{$node->type}' ({$declCType}) "
                        . "but assigned new {$node->expr->className} ({$cn}*)"
                    );
                }
            }
            $this->varTypes[$var] = $cn;
            if ($this->indent == 1) {
                $this->symbols->addScopeObject($var);  // 仅顶层作用域自动析构
            }
            if ($isDeclared) {
                return "{$var} = {$expr}; tphp_rt_register((void*){$var}, 0);";
            }
            if ($this->scopeDepth > 0) {
                $this->funcScopeDecls[$var] = "{$cn}*";
                return "{$var} = {$expr}; tphp_rt_register((void*){$var}, 0);";
            }
            return "{$cn}* {$var} = {$expr}; tphp_rt_register((void*){$var}, 0);";
        }

        // (array)xxx → 标量转单元素数组
        if ($node->expr instanceof CastExpr && $node->expr->castType === 'array') {
            $this->varTypes[$var] = 't_array*';
            // 推导 cast 源类型作为数组元素类型
            $srcType = $this->inferType($node->expr->expr);
            $this->arrElementTypes[$var] = ($srcType === 'null' || $srcType === 'void*') ? 't_int' : $srcType;
            if (!$isDeclared) {
                if ($this->scopeDepth > 0) {
                    $this->funcScopeDecls[$var] = 't_array*';
                    return "{$var} = {$expr};";
                }
                return "t_array* {$var} = {$expr};";
            }
            return "{$var} = {$expr};";
        }

        // null 赋值 → PHP 类型为 null，C 类型用 void* 占位
        if ($node->expr instanceof NullLiteralExpr) {
            $this->varTypes[$var] = 'null';
            if (!$isDeclared) {
                if ($this->scopeDepth > 0) {
                    $this->funcScopeDecls[$var] = 'void*';
                    return "{$var} = null;";
                }
                return "void* {$var} = null;";
            }
            return "{$var} = null;";
        }

        // 首次赋值 → 推导类型并声明
        if (!$isDeclared) {
            $inferredType = $this->inferType($node->expr);
            // 有声明类型时校验一致性并优先使用
            if ($node->type !== null) {
                $cType = self::mapType($node->type);
                if ($inferredType !== 'null' && $inferredType !== $cType) {
                    throw new \RuntimeException(
                        "Variable \${$var} type mismatch: declared '{$node->type}' ({$cType}) "
                        . "but inferred {$inferredType}"
                    );
                }
            } else {
                $cType = $inferredType;
            }
            $this->varTypes[$var] = $cType;
            $declType = ($cType === 'null') ? 'void*' : $cType;
            $w = $this->varWrite($var, $cType);
            // 追踪需要自动释放的局部变量（仅在函数/方法作用域内）
            if ($this->indent >= 1 && $cType === 't_string') {
                $this->symbols->addScopeString($var);
            } elseif ($this->indent >= 1 && $cType === 't_array*') {
                $this->symbols->addScopeArray($var);
            }
            if ($this->scopeDepth > 0) {
                $this->funcScopeDecls[$var] = $declType;
                $code = "{$w} = {$expr};";
            } else {
                $code = "{$declType} {$var} = {$expr};";
            }
        } else {
            // 自动释放：对象/t_string 重赋值时先求值再释放（防止 $var=$var->method() 的 use-after-free）
            $w = $this->varWrite($var, $prevType);
            if (str_starts_with($prevType, 'tphp_class_') || str_starts_with($prevType, 'tphp_enum_')) {
                $tmp = '_tmp_' . (++$this->tmpVarCounter);
                $code = "{$prevType} {$tmp} = {$expr}; tp_obj_release((void*){$var}); {$var} = {$tmp};";
            } elseif ($prevType === 't_string') {
                $code = "tphp_rt_str_free(&{$var}); {$w} = {$expr};";
            } else {
                $code = "{$w} = {$expr};";
            }
        }

        // 数组赋值 → 推导元素类型（支持对象/回调/嵌套数组）
        if ($node->expr instanceof ArrayLiteralExpr) {
            $elemType = $this->inferArrayElementType($node->expr);
            if (str_contains($elemType, 'tphp_class_')) $elemType .= '*';
            $this->arrElementTypes[$var] = $elemType;
            // 若元素是数组，记录嵌套级别元素类型（含 t_int）
            if ($elemType === 't_array*') {
                $nested = $this->inferArrayDeepElementType($node->expr);
                $this->arrNestedTypes[$var] = $nested;
            }
            // 追踪字符串键的 per-key 值类型（用于 foreach string key 检测）
            foreach ($node->expr->entries as $entry) {
                if ($entry->key instanceof StringLiteralExpr) {
                    $valType = $this->inferType($entry->value);
                    if ($valType !== 'null') {
                        $this->arrValueTypes[$var] ??= [];
                        $this->arrValueTypes[$var][$entry->key->value] = $valType;
                    }
                }
            }
        } elseif ($node->expr instanceof NewExpr) {
            $this->arrElementTypes[$var] = self::classRefName($node->expr->className) . '*';
        }

        // 传播数组嵌套类型：$sub = $arr[0] 时，把 $arr 的 arrNestedTypes 传给 $sub
        if ($node->expr instanceof ArrayAccessExpr) {
            [$rootArr] = $this->resolveRootArray($node->expr);
            if ($rootArr !== '') {
                // 传播 arrElementTypes（子数组的元素类型来自于父数组的嵌套类型）
                if (isset($this->arrNestedTypes[$rootArr])) {
                    $this->arrElementTypes[$var] = $this->arrNestedTypes[$rootArr];
                }
            }
        }
        // $sub = $arr 时，传播 arrElementTypes 和 arrNestedTypes
        if ($node->expr instanceof VariableExpr) {
            $srcVar = self::varName($node->expr->name);
            if (isset($this->arrElementTypes[$srcVar])) {
                $this->arrElementTypes[$var] = $this->arrElementTypes[$srcVar];
            }
            if (isset($this->arrNestedTypes[$srcVar])) {
                $this->arrNestedTypes[$var] = $this->arrNestedTypes[$srcVar];
            }
        }

        // $x = array_keys/values/explode/merge(...) → 追踪返回数组的元素类型
        if ($node->expr instanceof CallExpr && $node->expr->callee === null) {
            $fnName = $node->expr->name;
            // 查元素类型注册表
            if (isset(self::$builtinArrElemTypes[$fnName])) {
                $this->arrElementTypes[$var] = self::$builtinArrElemTypes[$fnName];
            }
            // 特殊处理：需要运行时分析的函数
            switch ($fnName) {
                case 'array_map':
                    // 元素类型 = callback 返回类型
                    $sig = $this->inferCallbackSig($node->expr->args[0] ?? null);
                    $this->arrElementTypes[$var] = $sig['ret'] ?? 't_int';
                    break;
                case 'array_filter':
                    // 元素类型 = 输入数组元素类型（从源数组变量推导）
                    if (isset($node->expr->args[0]) && $node->expr->args[0] instanceof VariableExpr) {
                        $srcVar = self::varName($node->expr->args[0]->name);
                        if (isset($this->arrElementTypes[$srcVar])) {
                            $this->arrElementTypes[$var] = $this->arrElementTypes[$srcVar];
                        }
                    }
                    break;
                case 'preg_match_all':
                    $this->arrElementTypes[$var] = 't_array*';
                    $this->arrNestedTypes[$var] = 't_string';
                    break;
            }
        }

        // 记录闭包变量名→函数名映射
        if ($node->expr instanceof ClosureExpr) {
            $this->varClosureMap[$var] = "_closure_{$this->closureCounter}";
        }

        return $code;
    }

    /** 从 AST 表达式推导 C 类型 */
    private function inferType(ExprNode $expr): string
    {
        $class = get_class($expr);
        if (isset(self::$litTypeMap[$class])) {
            return self::$litTypeMap[$class];
        }
        if ($expr instanceof NullLiteralExpr) {
            return 'null';
        }
        if ($expr instanceof UnaryExpr) {
            if ($expr->operator === '!') return 't_bool';
            return $this->inferType($expr->expr);
        }
        if ($expr instanceof BinaryExpr) {
            if ($expr->operator === '.') return 't_string';
            if ($expr->operator === '<=>') return 't_int';
            if ($expr->operator === '**') return $this->inferType($expr->left);
            // 比较/逻辑运算符返回 bool
            if (in_array($expr->operator, ['<', '>', '<=', '>=', '==', '!=', '===', '!==', '&&', '||', 'instanceof'], true)) {
                return 't_bool';
            }
            // 位运算/算术：取左操作数类型（int/float 保持）
            return $this->inferType($expr->left);
        }
        if ($expr instanceof PostfixExpr) {
            return $this->inferType($expr->expr);
        }
        if ($expr instanceof CompoundAssignExpr) {
            return $this->inferType($expr->target);
        }
        if ($expr instanceof ArrayLiteralExpr) {
            return 't_array*';
        }
        if ($expr instanceof ClosureExpr) {
            return 't_callback';
        }
        if ($expr instanceof VariableExpr) {
            $vn = self::varName($expr->name);
            $t = $this->varTypes[$vn] ?? 't_int';
            // byRef 变量：推导类型去掉一级指针（t_int*→t_int, t_array**→t_array*）
            if ($this->isByRefType($t)) return substr($t, 0, -1);
            return $t;
        }
        if ($expr instanceof EnumAccessExpr) {
            return $this->enumCTypes[$expr->enumName] ?? 't_int';
        }
        if ($expr instanceof PropertyAccessExpr) {
            // C->CONST — C constant/enum/macro, default to t_int
            if ($expr->object instanceof VariableExpr && $expr->object->name === 'C') {
                return 't_int';
            }
            $objKey = ($expr->object instanceof VariableExpr) ? self::varName($expr->object->name) : '';
            $objType = $this->varTypes[$objKey] ?? '';
            // 链式数组访问: $catalog[0][0]->prop — 用 inferType 推导对象类型
            if ($objType === '' && $expr->object instanceof ArrayAccessExpr) {
                $objType = rtrim($this->inferType($expr->object), '*');
            }
            // EnumName::CASE->value → 直接取 backing 类型
            if ($objType === '' && $expr->object instanceof EnumAccessExpr) {
                $objType = $this->enumCTypes[$expr->object->enumName] ?? '';
            }
            // 枚举属性访问 → enum->value 返回 backing 类型, enum->name 返回 t_string
            if ($objType !== '' && str_starts_with($objType, 'tphp_enum_')) {
                if ($expr->property === 'name') return 't_string';
                if ($expr->property === 'value') {
                    $base = rtrim($objType, '*');
                    foreach ($this->enumCTypes as $name => $ct) {
                        if (rtrim($ct, '*') === $base) {
                            return ($this->enumBackingTypes[$name] ?? 'int') === 'string' ? 't_string' : 't_int';
                        }
                    }
                    return 't_int';
                }
            }
            // 尝试从 classPropTypes 查找
            if ($objType !== '' && isset($this->classPropTypes[$objType])) {
                return $this->classPropTypes[$objType][$expr->property] ?? 't_int';
            }
        }
        if ($expr instanceof ArrayAccessExpr) {
            // per-key 类型追踪（字符串字面量键）
            if ($expr->index instanceof StringLiteralExpr && $expr->array instanceof VariableExpr) {
                $arrName = self::varName($expr->array->name);
                $keyStr  = $expr->index->value;
                if (isset($this->arrValueTypes[$arrName][$keyStr])) {
                    $et = $this->arrValueTypes[$arrName][$keyStr];
                    if (str_contains($et, 'tphp_class_') && !str_ends_with($et, '*')) $et .= '*';
                    return $et;
                }
                // 未知字符串键：全局查找是否在其他数组中有该键的类型信息
                foreach ($this->arrValueTypes as $vKeys) {
                    if (isset($vKeys[$keyStr])) return $vKeys[$keyStr];
                }
                return 't_string';  // 未知字符串键默认 string
            }
            // 先查数组变量的元素类型（支持对象/回调/数组）
            if ($expr->array instanceof VariableExpr) {
                $arrName = self::varName($expr->array->name);
                if (isset($this->arrElementTypes[$arrName])) {
                    $et = $this->arrElementTypes[$arrName];
                    if (str_contains($et, 'tphp_class_') && !str_ends_with($et, '*')) $et .= '*';
                    return $et;
                }
            }
            // 链式访问 $arr[0][0]：向上查找根数组的嵌套类型
            if ($expr->array instanceof ArrayAccessExpr) {
                [$rootArr, $depth] = $this->resolveRootArray($expr->array);
                if ($rootArr !== '' && $depth > 0 && isset($this->arrNestedTypes[$rootArr])) {
                    return $this->arrNestedTypes[$rootArr];
                }
            }
            // 整数键默认 int
            return 't_int';
        }
        if ($expr instanceof NewExpr) {
            return self::classRefName($expr->className) . '*';
        }
        if ($expr instanceof CastExpr) {
            return self::$typeMap[$expr->castType] ?? 't_int';
        }
        if ($expr instanceof CallExpr) {
            return $this->inferCallReturnType($expr);
        }
        if ($expr instanceof TernaryExpr) {
            return $this->inferType($expr->thenExpr);
        }
        if ($expr instanceof NullCoalesceExpr) {
            $lt = $this->inferType($expr->left);
            return ($lt === 'null') ? $this->inferType($expr->right) : $lt;
        }
        if ($expr instanceof MatchExpr) {
            // 返回第一个非 default arm 的 body 类型
            foreach ($expr->arms as $arm) {
                if (!empty($arm->values)) return $this->inferType($arm->body);
            }
            return 't_int';
        }
        return 't_int'; // fallback
    }

    /** 推导 CallExpr 的返回类型 */
    private function inferCallReturnType(CallExpr $expr): string
    {
        // 内置函数返回类型 — 查注册表
        if ($expr->callee === null) {
            $name = $expr->name;
            // 前缀规则：is_* / ctype_* → t_bool
            if (str_starts_with($name, 'is_')) return 't_bool';
            if (str_starts_with($name, 'ctype_')) return 't_bool';
            // 后缀规则：\phpc_thunk → null
            if (str_ends_with($name, '\\phpc_thunk')) return 'null';
            // array_reduce 返回类型 = callback 返回类型
            if ($name === 'array_reduce') {
                $sig = $this->inferCallbackSig($expr->args[1] ?? null);
                return $sig['ret'] ?? 't_int';
            }
            // 查注册表
            if (isset(self::$builtinRetTypes[$name])) {
                return self::$builtinRetTypes[$name];
            }
        }
        // 闭包调用 → 查 closureSigs
        if ($expr->name === '__invoke' && $expr->callee instanceof VariableExpr) {
            $varName = self::varName($expr->callee->name);
            $fnName = $this->varClosureMap[$varName] ?? '';
            if ($fnName && isset($this->closureSigs[$fnName])) {
                $sig = $this->closureSigs[$fnName];
                return $sig['ret'];
            }
        }
        // Raw C call → 分配/映射函数返回指针，用 void*；否则 t_int（值类型）
        if ($expr->isRawC) {
            $rcName = $expr->name;
            $ptrFns = ['map_ints','map_ints_ne','map_dbls','copy_ints','transform_ints',
                'point_create','str_dup','malloc','calloc'];
            if (in_array($rcName, $ptrFns, true)) return 'null';
            return 't_int';
        }
        // 方法调用 → 查 classMethodRetTypes
        if ($expr->callee !== null) {
            $objKey = '';
            if ($expr->callee instanceof VariableExpr) {
                $objKey = self::varName($expr->callee->name);
                $objType = ($objKey === '$this' || $objKey === 'self')
                    ? $this->className
                    : ($this->varTypes[$objKey] ?? '');
            } elseif ($expr->callee instanceof CallExpr) {
                // 链式调用：递归推导
                $objType = $this->inferCallChainClass($expr->callee);
            } else {
                return 't_int';
            }
            $objClean = rtrim($objType, '*');
            if ($objClean !== '' && isset($this->classMethodRetTypes[$objClean])) {
                $retType = $this->classMethodRetTypes[$objClean][$expr->name] ?? null;
                if ($retType !== null) { if ($retType === 'void') return 't_int'; return $retType; }
            }
            // Inherited method
            $parentCN = $this->resolveMethodClass($objClean, $expr->name);
            if ($parentCN !== '' && isset($this->classMethodRetTypes[$parentCN][$expr->name])) {
                $retType = $this->classMethodRetTypes[$parentCN][$expr->name];
                if ($retType !== 'void') return $retType;
            }
        }
        // 原始 C 调用 → 可能返回指针，用 void* 安全存储
        if ($expr->isRawC) return 'null';
        // 独立函数 → 查 funcRetTypes 注册表，否则默认 t_int
        $fnCName = self::funcCNameFromCall($expr);
        if ($fnCName && isset($this->funcRetTypes[$fnCName])) {
            return $this->funcRetTypes[$fnCName];
        }
        return 't_int';
    }

    public function visitAssignPropStmt(AssignPropStmtNode $node): string
    {
        $target = $node->target->accept($this);
        $val = $node->value->accept($this);
        $propType = $this->getPropType($node->target);
        if ($propType === 't_string') {
            return "tphp_rt_str_free(&{$target}); {$target} = tphp_rt_str_dup({$val});";
        }
        return "{$target} = {$val};";
    }

    public function visitAssignArrayPushStmt(AssignArrayPushStmtNode $node): string
    {
        $var    = self::varName($node->varName);
        $varT   = $this->varTypes[$var] ?? '';
        $isByRef = $this->isByRefType($varT);
        // byRef 数组：变量已是 t_array**，直接传；非 byRef：取地址
        $arrCode = $isByRef ? $var : ('&' . $var);
        $vCode   = $node->value->accept($this);
        $val     = $this->wrapArrayElement($node->value, $vCode);
        return 'tphp_fn_array_push(' . $arrCode . ', ' . $val . ');';
    }

    public function visitAssignArrayStmt(AssignArrayStmtNode $node): string
    {
        $arr   = $node->target->array->accept($this);
        $idx   = $node->target->index->accept($this);
        $vCode = $node->value->accept($this);
        $val   = $this->wrapArrayElement($node->value, $vCode);

        // per-key 类型追踪：记录每个字符串键的值类型
        if ($node->target->index instanceof StringLiteralExpr && $node->target->array instanceof VariableExpr) {
            $arrName = self::varName($node->target->array->name);
            $valType = $this->inferType($node->value);
            if ($valType !== 'null') {
                $this->arrValueTypes[$arrName] ??= [];
                $this->arrValueTypes[$arrName][$node->target->index->value] = $valType;
            }
        }

        $idxType = $this->inferType($node->target->index);
        // 跟踪 int key 元素类型（仅非默认类型）
        if (($idxType !== 't_string' && !($node->target->index instanceof StringLiteralExpr)) && $node->target->array instanceof VariableExpr) {
            $arrName = self::varName($node->target->array->name);
            $elemType = $this->inferType($node->value);
            if ($elemType !== 'null' && $elemType !== 't_int' && $elemType !== 't_float' && $elemType !== 't_bool') {
                $this->arrElementTypes[$arrName] = $elemType;
                // 若赋的值是数组字面量，记录嵌套类型
                if ($elemType === 't_array*' && $node->value instanceof ArrayLiteralExpr) {
                    $nested = $this->inferArrayDeepElementType($node->value);
                    $this->arrNestedTypes[$arrName] = $nested;
                }
            }
        }
        if ($idxType === 't_string' || $node->target->index instanceof StringLiteralExpr) {
            return "{$arr} = tphp_fn_arr_set_str({$arr}, {$idx}, {$val});";
        }
        return "{$arr} = tphp_fn_arr_set_int({$arr}, {$idx}, {$val});";
    }

    /** 从数组字面量推导元素类型（取第一个非空元素的类型） */
    private function inferArrayElementType(ArrayLiteralExpr $expr): string
    {
        foreach ($expr->entries as $entry) {
            $val = $entry->value ?? $entry;
            if ($val === null) continue;
            $cType = $this->inferType($val);
            if ($cType !== 'null' && $cType !== 't_int') return $cType;
        }
        return 't_int';
    }

    /** 解析链式数组访问的根变量名 + 嵌套层数
     *  如 $arr[0][1] → ['arr', 1]（嵌套了1层 ArrayAccessExpr）
     *  如 $arr → ['arr', 0] */
    private function resolveRootArray(ExprNode $expr): array
    {
        $depth = 0;
        while ($expr instanceof ArrayAccessExpr) {
            $expr = $expr->array;
            $depth++;
        }
        if ($expr instanceof VariableExpr) {
            return [self::varName($expr->name), $depth];
        }
        return ['', $depth];
    }

    /** 从数组字面量推导深一层嵌套数组的元素类型 */
    private function inferArrayDeepElementType(ArrayLiteralExpr $expr): string
    {
        foreach ($expr->entries as $entry) {
            $val = $entry->value ?? $entry;
            if ($val instanceof ArrayLiteralExpr) {
                return $this->inferArrayElementType($val);
            }
        }
        return 't_int';
    }

    /** 检测 ArrayAccess 是否用字符串键 */
    private function hasStrKey(ArrayAccessExpr $expr): bool
    {
        if ($expr->index instanceof StringLiteralExpr) return true;
        return $this->inferType($expr->index) === 't_string';
    }

    /** 获取属性类型（通过 classPropTypes 查找） */
    private function getPropType(PropertyAccessExpr $pa): string
    {
        // C->CONST — C constant/enum/macro, default to t_int
        if ($pa->object instanceof VariableExpr && $pa->object->name === 'C') {
            return 't_int';
        }
        $objKey = ($pa->object instanceof VariableExpr) ? self::varName($pa->object->name) : '';
        $objType = ($objKey === '$this' || $objKey === 'self')
            ? $this->className
            : ($this->varTypes[$objKey] ?? '');
        // 去掉尾部 *（指针类型）以匹配 classPropTypes key
        $objType = rtrim($objType, '*');
        // 链式数组访问: $catalog[0][0]->prop — 用 inferType 推导对象类型
        if ($objType === '' && $pa->object instanceof ArrayAccessExpr) {
            $inferred = $this->inferType($pa->object);
            $objType = rtrim($inferred, '*');
        }
        // EnumName::CASE->value → 直接取 backing 类型
        if ($objType === '' && $pa->object instanceof EnumAccessExpr) {
            $objType = rtrim($this->enumCTypes[$pa->object->enumName] ?? '', '*');
        }
        // 枚举属性 → enum->value 返回 backing 类型, enum->name 返回 t_string
        if ($objType !== '' && str_starts_with($objType, 'tphp_enum_')) {
            if ($pa->property === 'name') return 't_string';
            if ($pa->property === 'value') {
                $base = rtrim($objType, '*');
                foreach ($this->enumCTypes as $name => $ct) {
                    if (rtrim($ct, '*') === $base) {
                        return ($this->enumBackingTypes[$name] ?? 'int') === 'string' ? 't_string' : 't_int';
                    }
                }
                return 't_int';
            }
        }
        if ($objType !== '' && isset($this->classPropTypes[$objType])) {
            $pt = $this->classPropTypes[$objType][$pa->property] ?? null;
            if ($pt !== null) return $pt;
        }
        // Search parent chain for inherited properties
        $cur = $objType;
        while (isset($this->classParentName[$cur]) && $this->classParentName[$cur] !== '') {
            $cur = $this->classParentName[$cur];
            if (isset($this->classPropTypes[$cur][$pa->property])) {
                return $this->classPropTypes[$cur][$pa->property];
            }
        }
        return '';
    }

    public function visitExprStmt(ExprStmtNode $node): string
    {
        return $node->expr->accept($this) . ';';
    }

    // ============================================================
    public function visitStringLiteral(StringLiteralExpr $node): string
    {
        // Escape chars invalid in C string literals
        $val = $node->value;
        $val = str_replace('"', '\\"', $val);     // "  → \"
        $val = str_replace("\n", '\\n', $val);    // LF → \n
        $val = str_replace("\r", '\\r', $val);    // CR → \r
        $val = str_replace("\t", '\\t', $val);    // TAB → \t
        // Escape backslashes not part of recognized C escape sequences
        // (e.g. \w, \d, \s in regex patterns → \\w, \\d, \\s to survive C compilation)
        $result = '';
        $len = strlen($val);
        for ($i = 0; $i < $len; $i++) {
            if ($val[$i] === '\\' && $i + 1 < $len) {
                $next = $val[$i + 1];
                if (strpos('"\\ntrabefvx?\'01234567', $next) !== false) {
                    $result .= '\\' . $next;
                } else {
                    $result .= '\\\\' . $next;
                }
                $i++;
            } elseif ($val[$i] === '\\') {
                $result .= '\\\\';
            } else {
                $result .= $val[$i];
            }
        }
        return "STR_LIT(\"{$result}\")";
    }

    public function visitIntLiteral(IntLiteralExpr $node): string    { return (string)$node->value; }
    public function visitFloatLiteral(FloatLiteralExpr $node): string {
        // 确保 C 侧保留浮点语义：整数部分后面加 .0
        $val = $node->value;
        return ($val == (float)(int)$val) ? sprintf('%.1f', $val) : rtrim(rtrim(sprintf('%.15g', $val), '0'), '.');
    }
    public function visitBoolLiteral(BoolLiteralExpr $node): string   { return $node->value ? 'true' : 'false'; }
    public function visitNullLiteral(NullLiteralExpr $node): string   { return 'null'; }

    public function visitMagicConst(MagicConstExpr $node): string
    {
        $this->varTypes['__magic_tmp__'] = 't_string';
        if ($node->name === '__LINE__') return 'tphp_rt_str_from_int(' . $node->line . ')';
        if ($node->name === '__FILE__') return 'tphp_rt_str_dup((t_string){.data="' . str_replace('\\', '\\\\', $this->phpFile) . '", .length=' . strlen($this->phpFile) . ', .is_lit=true})';
        if ($node->name === '__DIR__')  return 'tphp_rt_str_dup((t_string){.data="' . str_replace('\\', '\\\\', dirname($this->phpFile)) . '", .length=' . strlen(dirname($this->phpFile)) . ', .is_lit=true})';
        if ($node->name === 'DIRECTORY_SEPARATOR') return PHP_OS_FAMILY === 'Windows' ? 'STR_LIT("\\\\")' : 'STR_LIT("/")';
        if ($node->name === '__CLASS__')  return 'STR_LIT("' . $this->className . '")';
        if ($node->name === '__METHOD__') return 'STR_LIT("' . $this->className . '::' . ($this->currentMethodName ?? '') . '")';
        return 'STR_LIT("")';
    }

    public function visitArrayLiteral(ArrayLiteralExpr $node): string
    {
        // 生成复合语句表达式，创建 t_array* 并填充
        $tmpName = "_arr_" . (++$this->tmpVarCounter);
        return "({ " . $this->genArrayLiteralInline($node, $tmpName) . " " . $tmpName . "; })";
    }

    /** 生成数组字面量的声明+填充代码（不含外层 ({})） */
    private function genArrayLiteralInline(ArrayLiteralExpr $node, string $varName): string
    {
        $count = count($node->entries);
        $parts = [];
        // 预分配容量（至少 4，避免大数组逐次 realloc）
        $cap = max(4, $count);
        $parts[] = "t_array* {$varName} = tphp_fn_arr_create({$cap}); tphp_rt_register((void*){$varName}, 1);";
        $parts[] = "if ({$varName} != NULL) {";
        foreach ($node->entries as $entry) {
            $valCode = $entry->value->accept($this);
            $wrap = $this->wrapArrayElement($entry->value, $valCode);

            if ($entry->key !== null) {
                $keyExpr = $entry->key;
                if ($keyExpr instanceof StringLiteralExpr) {
                    $kc = $keyExpr->accept($this);
                    $parts[] = "{$varName} = tphp_fn_arr_set_str({$varName}, {$kc}, {$wrap});";
                } else {
                    $kc = $keyExpr->accept($this);
                    $parts[] = "{$varName} = tphp_fn_arr_set_int({$varName}, {$kc}, {$wrap});";
                }
            } else {
                $parts[] = "{$varName} = tphp_fn_arr_push({$varName}, {$wrap});";
            }
        }
        $parts[] = '}';
        return implode(' ', $parts);
    }

    /** 将数组元素值包装为 t_var 宏 */
    private function wrapArrayElement(ExprNode $el, string $code): string
    {
        if ($el instanceof StringLiteralExpr)  return "VAR_STRING({$code})";
        if ($el instanceof IntLiteralExpr)     return "VAR_INT({$code})";
        if ($el instanceof FloatLiteralExpr)   return "VAR_FLOAT({$code})";
        if ($el instanceof BoolLiteralExpr)    return "VAR_BOOL({$code})";
        if ($el instanceof NullLiteralExpr)    return "VAR_NULL()";
        if ($el instanceof ArrayLiteralExpr)   return "VAR_ARRAY({$code})";
        if ($el instanceof ClosureExpr)        return "VAR_CALLBACK({$code})";
        if ($el instanceof VariableExpr) {
            $vn = self::varName($el->name);
            // 常量引用（不以 $ 开头）→ 加 TPHP_CONST_ 前缀
            $isConst = !str_starts_with($el->name, '$');
            $ref = $isConst ? ('TPHP_CONST_' . strtoupper($vn)) : $vn;
            $vt = $this->varTypes[$vn] ?? 't_int';
            return match ($vt) {
                't_int' => "VAR_INT({$ref})", 't_float' => "VAR_FLOAT({$ref})",
                't_string' => "VAR_STRING({$ref})", 't_bool' => "VAR_BOOL({$ref})",
                't_array*' => "VAR_ARRAY({$ref})", 't_callback' => "VAR_CALLBACK({$ref})",
                default => (str_contains($vt, 'tphp_class_') || str_contains($vt, 'tphp_enum_'))
                    ? "VAR_OBJ({$ref})" : "VAR_NULL()",
            };
        }
        if ($el instanceof NewExpr) return "VAR_OBJ({$code})";
        // 复杂表达式：用 inferType 动态推导类型
        $type = $this->inferType($el);
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

    public function visitClosure(ClosureExpr $node): string
    {
        if ($node->isGenerator) {
            throw new \RuntimeException("Generator closures not yet supported");
        }
        $id = ++$this->closureCounter;
        $name  = "_closure_{$id}";
        $capName = "_cap_{$id}";
        $hasCapture = !empty($node->useVars);
        $ret = self::mapType($node->returnType);

        // 查询捕获变量的类型（外层作用域）
        $capFields = [];
        $capInits  = [];
        $capDecls  = [];
        $capAssigns = []; // heap allocation assignments: _env_N->var = var;
        foreach ($node->useVars as [$vn, $_]) {
            $ct = $this->varTypes[$vn] ?? 't_int';
            // null 类型 → void*，t_var 保持原样，对象类型加 *
            if ($ct === 'null') {
                $ct = 'void*';
            } elseif (str_contains($ct, 'tphp_class_') && !str_ends_with($ct, '*')) {
                $ct .= '*';
            }
            $capFields[]  = "    {$ct} {$vn};";
            $capInits[]   = "    .{$vn} = {$vn}";
            $capDecls[]   = "    {$ct} {$vn} = _e->{$vn};";
            $capAssigns[] = "    _env_{$id}->{$vn} = {$vn};";
        }

        $paramDecls = array_map(fn($p) => $this->visitParam($p), $node->params);
        $paramDecls[] = "void* _env";  // 统一签名，无捕获时 env=NULL
        $paramStr = implode(', ', $paramDecls);

        // 构建闭包函数 C 实现
        $savedDeclared = $this->declaredVars;
        $savedObjs = $this->symbols->scopeObjects();
        $savedTypes    = $this->varTypes;
        $savedIndent   = $this->indent;
        $savedRetType  = $this->currentRetType;

        $this->declaredVars = [];
        $this->symbols->clearScopeObjects();
        $this->varTypes     = [];
        $this->indent       = 0;
        $this->currentRetType = $ret;
        foreach ($node->params as $p) {
            $vn = self::varName($p->name);
            $this->declaredVars[$vn] = true;
            $this->varTypes[$vn] = self::mapType($p->type);
        }

        $implLines = [];
        $implLines[] = "{$ret} {$name}({$paramStr}) {";
        if ($hasCapture) {
            // 从 void* env 转回捕获 struct，声明局部引用
            $implLines[] = "    {$capName}* _e = ({$capName}*)_env;";
            foreach ($capDecls as $d) { $implLines[] = $d; }
            foreach ($node->useVars as [$vn, $_]) {
                $this->declaredVars[$vn] = true;
                $ct = $savedTypes[$vn] ?? 't_int';
                $this->varTypes[$vn] = ($ct === 'null') ? 'void*' : $ct;
            }
        } else {
            $implLines[] = '    (void)_env;';
        }
        if (empty($node->body)) {
            foreach ($node->params as $p) {
                $implLines[] = '    (void)' . self::varName($p->name) . ';';
            }
        } else {
            foreach ($node->body as $s) {
                $implLines[] = '    ' . $s->accept($this);
            }
        }
        foreach ($this->symbols->scopeObjects() as $ov) {
            $implLines[] = '    ' . "tp_obj_release({$ov});";
        }
        $implLines[] = '}';

        $this->sectionBlock(self::SEC_CLOSURES, implode("\n", $implLines));

        // 记录闭包签名：用于 generateClosureCall 生成正确的函数指针转换
        $this->closureSigs[$name] = [
            'ret'    => $ret,
            'params' => implode(', ', array_map(fn($p) => self::mapType($p->type), $node->params)),
        ];

        // 恢复外层作用域
        $this->declaredVars = $savedDeclared;
        $this->symbols->clearScopeObjects(); foreach($savedObjs as $so) $this->symbols->addScopeObject($so);
        $this->varTypes     = $savedTypes;
        $this->indent       = $savedIndent;
        $this->currentRetType = $savedRetType;

        // 注册捕获 struct 定义（后处理时插入文件顶部）
        if ($hasCapture) {
            $capDef = "typedef struct {\n" . implode("\n", $capFields) . "\n} {$capName};";
            $this->sectionBlock(self::SEC_CAPTYPES, $capDef);
        }

        // 生成 GNU 复合表达式
        $fwdParams = implode(', ', array_map(fn($p) => $this->visitParam($p), $node->params));
        $fwdParams = ($fwdParams ? $fwdParams . ', ' : '') . "void* _env";
        $envDecl = $hasCapture
            ? "    {$capName}* _env_{$id} = ({$capName}*)calloc(1, sizeof({$capName}));\n"
              . "    if (_env_{$id} != NULL) {\n"
              . implode("\n", $capAssigns) . "\n"
              . "    }\n"
              . "    tphp_rt_register((void*)_env_{$id}, 3);\n"
              . "    (t_callback){ .func = (void*){$name}, .env = _env_{$id} };"
            : "    (t_callback){ .func = (void*){$name}, .env = NULL };";


        return "({ {$ret} {$name}({$fwdParams});\n{$envDecl}\n  })";
    }

    /** 生成 C thunk：包装 TinyPHP 闭包为无 env 的 C 回调
     *  @param string $cType  C 回调类型 (int32_t / int64_t / double)
     *  @param ExprNode $expr  闭包表达式（inline ClosureExpr 或 VariableExpr）
     */
    /** 按 #callback 声明的签名生成 thunk
     *  @param string $cbName   #callback 声明的名称
     *  @param ExprNode $expr   闭包表达式
     */
    private function generateThunk(string $cbName, ExprNode $expr): string
    {
        $sig    = $this->phpcCallbackSigs[$cbName];
        $cRet   = $sig['ret'];
        $params = array_map('trim', array_filter(explode(',', $sig['params_str'])));
        if (empty($params) || $params[0] === '') $params = [];

        // 解析每个参数: "type name"
        $cParams = [];
        $cParamTypes = [];
        $tpTypes = [];  // TinyPHP 类型（函数指针 cast 用）
        $casts = [];    // C → TinyPHP cast
        foreach ($params as $p) {
            $parts = preg_split('/\s+/', trim($p), 2);
            $cParams[] = trim($p);           // e.g., "int32_t idx"
            $cParamTypes[] = $parts[0];      // e.g., "int32_t"
            $tpTypes[]    = $this->cToTpType($parts[0]);  // e.g., "t_int"
            $casts[]      = $this->cToCast($parts[0]);     // e.g., "(t_int)"
        }

        $tid = ++$this->thunkCounter;
        $thunkName = "_phpc_thunk_{$tid}";
        $cbStatic  = "_phpc_cb_{$tid}";
        $this->sectionLine(self::SEC_THUNKVARS, "static t_callback {$cbStatic};");

        // 函数指针类型（用于 cast 表达式）
        $castType = ($cRet === 'void' ? 'void' : $cRet)
                  . ' (*)(' . implode(', ', $cParamTypes)
                  . (empty($cParamTypes) ? '' : ', ')
                  . 'void*)';

        // Thunk 签名
        $sigStr = implode(', ', $cParams);
        $retCast = $this->cToReturnCast($cRet);

        $thunkImpl  = "static {$cRet} {$thunkName}({$sigStr}) {\n";
        $thunkImpl .= "    {$cRet} (*_raw)(" . implode(', ', $cParamTypes) . (empty($cParamTypes) ? '' : ', ') . "void*) = ({$castType}){$cbStatic}.func;\n";
        $argList = [];
        foreach ($casts as $i => $cast) {
            $pname = explode(' ', $cParams[$i])[1] ?? "_{$i}";
            $argList[] = "{$cast}{$pname}";
        }
        $argStr = implode(', ', $argList);
        $envStr = (empty($argStr) ? '' : ', ') . "{$cbStatic}.env";
        if ($cRet === 'void') {
            $thunkImpl .= "    _raw({$argStr}{$envStr});\n";
        } else {
            $thunkImpl .= "    return {$retCast}_raw({$argStr}{$envStr});\n";
        }
        $thunkImpl .= '}';
        $this->sectionBlock(self::SEC_THUNKS, $thunkImpl);
        $this->sectionLine(self::SEC_FWDDECLS, "static {$cRet} {$thunkName}({$sigStr});");

        $cbCode = $expr->accept($this);
        return "({$cbStatic} = {$cbCode}, {$thunkName})";
    }

    /** C 类型 → TinyPHP 类型（函数指针 cast） */
    private function cToTpType(string $cType): string {
        // 规范化: 去除所有空格并转小写，避免 "const char *" vs "const char*" 不一致
        $n = strtolower(preg_replace('/\s+/', '', $cType));
        return match ($n) {
            'int32_t','int64_t','int','long','longlong','uint32_t','uint64_t','unsignedint','unsignedlong' => 't_int',
            'double','float' => 't_float',
            'constchar*','char*','constchar','char' => 't_string',
            'bool','_bool' => 't_bool',
            'void' => 'void',
            default => 'void*',
        };
    }

    /** C 类型 → cast 表达式（参数转换） */
    private function cToCast(string $cType): string {
        $n = strtolower(preg_replace('/\s+/', '', $cType));
        return match ($n) {
            'int32_t','int64_t','int','long','longlong','uint32_t','uint64_t','unsignedint','unsignedlong' => '(t_int)',
            'double','float' => '(t_float)',
            'constchar*','char*','constchar','char' => '',
            'bool','_bool' => '(t_bool)',
            default => '(void*)',
        };
    }

    /** C 返回类型 → return cast */
    private function cToReturnCast(string $cRet): string {
        if ($cRet === 'void') return '';
        $n = strtolower(preg_replace('/\s+/', '', $cRet));
        return match ($n) {
            'int32_t'   => '(int32_t)',
            'int64_t'   => '(int64_t)',
            'int'       => '(int)',
            'double'    => '(double)',
            'float'     => '(float)',
            'void*'     => '(void*)',
            'bool','_bool' => '(bool)',
            default     => "({$cRet})",
        };
    }

    public function visitVariable(VariableExpr $node): string
    {
        // 'self' 是关键字，不是常量名
        if ($node->name === 'self') return 'self';
        // 原始名字判断是否常量
        if (!str_starts_with($node->name, '$')) {
            return 'TPHP_CONST_' . strtoupper($node->name);
        }
        $n = self::varName($node->name);
        if ($n === '$this') return 'self';
        // byRef 参数：统一解引用一次（int*→(*x), t_array**→(*arr), tphp_class_X**→(*obj)）
        if ($this->isByRefType($this->varTypes[$n] ?? '')) {
            return "(*{$n})";
        }
        return $n;
    }

    public function visitUnary(UnaryExpr $node): string
    {
        return $node->operator . '(' . $node->expr->accept($this) . ')';
    }

    public function visitBinary(BinaryExpr $node): string
    {
        if ($node->operator === '=') {
            // 用于 for-init 中的赋值
            return $node->left->accept($this) . ' = ' . $node->right->accept($this);
        }
        if ($node->operator === '.') {
            // ROPE 优化：展平 ". . ." 链为多片段拼接，一次分配
            $parts = $this->flattenConcat($node);
            if (count($parts) >= 3) {
                $partCodes = array_map(fn($p) => $this->castToStr($p), $parts);
                $count = count($parts);
                // 生成: tphp_rt_str_concat_multi(N, (t_string[]){a, b, c, ...})
                return "tphp_rt_str_concat_multi({$count}, (t_string[]){"
                    . implode(', ', $partCodes) . '})';
            }
            // 2 片段：保持原有 pair-wise
            $left  = $this->castToStr($node->left);
            $right = $this->castToStr($node->right);
            return 'tphp_rt_str_concat(' . $left . ', ' . $right . ')';
        }

        // <=> 太空船: (a < b) ? -1 : ((a > b) ? 1 : 0)
        if ($node->operator === '<=>') {
            $l = $node->left->accept($this);
            $r = $node->right->accept($this);
            $lt = $this->inferType($node->left);
            $rt = $this->inferType($node->right);
            if ($lt === 't_string' || $rt === 't_string') {
                return '(tphp_rt_str_lt(' . $this->castToStr($node->left) . ', ' . $this->castToStr($node->right) . ') ? -1 : (tphp_rt_str_gt(' . $this->castToStr($node->left) . ', ' . $this->castToStr($node->right) . ') ? 1 : 0))';
            }
            return '((' . $l . ') < (' . $r . ') ? -1 : ((' . $l . ') > (' . $r . ') ? 1 : 0))';
        }

        // ** 幂运算
        if ($node->operator === '**') {
            $l = $node->left->accept($this);
            $r = $node->right->accept($this);
            $lt = $this->inferType($node->left);
            if ($lt === 't_float') {
                return 'tphp_rt_pow_float(' . $l . ', ' . $r . ')';
            }
            return 'tphp_rt_pow_int(' . $l . ', ' . $r . ')';
        }

        // null 比较: null == null → true, null == x → false
        $cmpOps = ['==', '!='];
        if (in_array($node->operator, $cmpOps, true)) {
            $lNull = $node->left instanceof NullLiteralExpr;
            $rNull = $node->right instanceof NullLiteralExpr;
            if ($lNull || $rNull) {
                if ($lNull && $rNull) {
                    return $node->operator === '==' ? 'true' : 'false';
                }
                $otherNode = $lNull ? $node->right : $node->left;
                $otype = $this->inferType($otherNode);
                $other = $otherNode->accept($this);
                // struct 类型用成员判空
                if ($otype === 't_string') {
                    return $node->operator === '=='
                        ? "({$other}.data == NULL && {$other}.length == 0)"
                        : "({$other}.data != NULL || {$other}.length > 0)";
                }
                return $node->operator === '==' ? "({$other} == null)" : "({$other} != null)";
            }
        }

        // 字符串比较 → 运行时函数（=== / !== 等同于 == / != 在 AOT 固定类型下）
        $cmpAllOps = ['==', '!=', '===', '!==', '<', '>', '<=', '>='];
        if (in_array($node->operator, $cmpAllOps, true)) {
            $lt = $this->inferType($node->left);
            $rt = $this->inferType($node->right);
            if ($lt === 't_string' || $rt === 't_string') {
                $l = $this->castToStr($node->left);
                $r = $this->castToStr($node->right);
                return match ($node->operator) {
                    '==' => 'tphp_rt_str_eq(' . $l . ', ' . $r . ')',
                    '!=' => 'tphp_rt_str_ne(' . $l . ', ' . $r . ')',
                    '===' => 'tphp_rt_str_eq(' . $l . ', ' . $r . ')',
                    '!==' => 'tphp_rt_str_ne(' . $l . ', ' . $r . ')',
                    '<'  => 'tphp_rt_str_lt(' . $l . ', ' . $r . ')',
                    '>'  => 'tphp_rt_str_gt(' . $l . ', ' . $r . ')',
                    '<=' => 'tphp_rt_str_le(' . $l . ', ' . $r . ')',
                    '>=' => 'tphp_rt_str_ge(' . $l . ', ' . $r . ')',
                };
            }
        }

        $lCode = $node->left->accept($this);
        $rCode = $node->right->accept($this);
        $lt = $this->inferType($node->left);
        $rt = $this->inferType($node->right);
        // 对 t_var 操作数解包
        if ($lt === 't_var') $lCode = $this->unwrapIfMixed($node->left, $lCode, $rt);
        if ($rt === 't_var') $rCode = $this->unwrapIfMixed($node->right, $rCode, $lt);
        // 对数组字符串键读取(get_str_str返回t_string) vs 标量，转 int 比较
        if (str_contains($lCode, 'tphp_fn_arr_get_str_str') && in_array($rt, ['t_int', 't_float', 't_bool'], true)) {
            $lCode = 'tphp_rt_parse_int(' . $lCode . ')';
        }
        if (str_contains($rCode, 'tphp_fn_arr_get_str_str') && in_array($lt, ['t_int', 't_float', 't_bool'], true)) {
            $rCode = 'tphp_rt_parse_int(' . $rCode . ')';
        }

        // instanceof → tp_obj_is_a check
        if ($node->operator === 'instanceof') {
            $rCN = $node->right instanceof VariableExpr ? rtrim($this->varTypes[self::varName($node->right->name)] ?? '', '*') : '';
            $rCN = ($rCN === '' && $node->right instanceof StringLiteralExpr) ? $node->right->value : $rCN;
            // If right is a class name identifier (not variable), look up in classRefName
            if ($node->right instanceof AST\IdentifierExpr ?? null) {
                // Actually in PHP $obj instanceof ClassName, ClassName is parsed as a class reference
            }
            return 'tp_obj_is_a(' . $lCode . ', &_class_' . $rCode . ')';
        }
        // Map PHP === / !== to C == / !=
        $cOp = match ($node->operator) {
            '==='  => '==',
            '!=='  => '!=',
            default => $node->operator,
        };
        return '(' . $lCode . ' ' . $cOp . ' ' . $rCode . ')';
    }

    public function visitTernary(TernaryExpr $node): string
    {
        $cond = $node->condition->accept($this);
        $then = $node->thenExpr->accept($this);
        $else = $node->elseExpr->accept($this);
        return '(' . $cond . ' ? ' . $then . ' : ' . $else . ')';
    }

    public function visitNullCoalesce(NullCoalesceExpr $node): string
    {
        $lt = $this->inferType($node->left);
        // AOT 类型固定：值类型（int/float/bool/string/array*/object*）永不为 null，直接返回 left
        if ($lt === 'null') return $node->right->accept($this);
        $left  = $node->left->accept($this);
        // 只有 t_var（可空联合体）才需要运行时 TYPE_NULL 检查
        if ($lt === 't_var') {
            $right = $node->right->accept($this);
            return '(' . $left . '.type != TYPE_NULL ? ' . $left . ' : ' . $right . ')';
        }
        // void*（nullable 对象指针）需要运行时 null 检查
        if ($lt === 'void*' || $lt === 'null') {
            $right = $node->right->accept($this);
            return '(' . $left . ' != null ? ' . $left . ' : ' . $right . ')';
        }
        // 其他值类型：编译期已知非 null，直接返回 left
        return $left;
    }

    public function visitMatchExpr(MatchExpr $node): string
    {
        $tmp = '_match_' . (++$this->tmpVarCounter);
        $condCode = $node->condition->accept($this);
        $condType = $this->inferType($node->condition);
        $resultType = $this->inferType($node->arms[0]->body ?? null) ?: 't_int';

        $lines = [];
        $lines[] = "({ {$resultType} {$tmp};";
        // 检测是否有 default arm（values 为空即为 default）
        $hasDefault = false;
        foreach ($node->arms as $arm) {
            if (empty($arm->values)) { $hasDefault = true; break; }
        }
        if (!$hasDefault) {
            // 无 default：初始化为零值，防止未初始化使用
            $zeroVal = match ($resultType) {
                't_string' => '(t_string){NULL, 0}',
                't_float' => '0.0',
                't_bool' => 'false',
                't_array*' => 'NULL',
                't_callback' => '(t_callback){NULL, NULL}',
                default => '0',
            };
            $lines[] = "    {$tmp} = {$zeroVal};";
        }
        $first = true;
        foreach ($node->arms as $arm) {
            if (empty($arm->values)) {
                // default arm
                $bodyCode = $arm->body->accept($this);
                $lines[] = "    else { {$tmp} = {$bodyCode}; }";
            } else {
                $prefix = $first ? '    if (' : '    else if (';
                $first = false;
                $conds = [];
                foreach ($arm->values as $v) {
                    $vCode = $v->accept($this);
                    if ($condType === 't_string') {
                        $conds[] = "tphp_rt_str_eq({$condCode}, {$vCode})";
                    } else {
                        $conds[] = "({$condCode} == {$vCode})";
                    }
                }
                $bodyCode = $arm->body->accept($this);
                $lines[] = $prefix . implode(' || ', $conds) . ") { {$tmp} = {$bodyCode}; }";
            }
        }
        $lines[] = "    {$tmp}; })";
        return implode("\n", $lines);
    }

    public function visitCall(CallExpr $node): string
    {
        // var_dump 内置函数 —— 包装参数为 t_var 并调用 tphp_var_dump
        if ($node->callee === null && $node->name === 'var_dump') {
            return $this->generateVarDump($node->args);
        }

        // print_r($x) — 包装参数为 t_var 调用 tphp_fn_print_r
        if ($node->callee === null && $node->name === 'print_r') {
            $wrapped = $this->wrapVar($node->args[0]);
            return 'tphp_fn_print_r(' . $wrapped . ')';
        }

        // count 内置函数 → tphp_arr_count
        if ($node->callee === null && $node->name === 'count') {
            $args = array_map(fn($a) => $a->accept($this), $node->args);
            return 'tphp_fn_arr_count(' . implode(', ', $args) . ')';
        }

        // array_push($arr, $val) → 尾部追加，返回新长度
        if ($node->callee === null && $node->name === 'array_push') {
            $arrCode = $node->args[0]->accept($this);
            $valCode = $this->wrapArrayElement($node->args[1], $node->args[1]->accept($this));
            return 'tphp_fn_array_push(&' . $arrCode . ', ' . $valCode . ')';
        }

        // array_pop($arr) → 弹出尾部元素，返回 t_var（mixed 类型）
        if ($node->callee === null && $node->name === 'array_pop') {
            $arrCode = $node->args[0]->accept($this);
            return 'tphp_fn_array_pop(&' . $arrCode . ')';
        }

        // in_array($needle, $haystack) → 值是否存在
        if ($node->callee === null && $node->name === 'in_array') {
            $needleCode = $this->wrapVar($node->args[0]);
            $arrCode    = $node->args[1]->accept($this);
            return 'tphp_fn_in_array(' . $needleCode . ', ' . $arrCode . ')';
        }

        // array_key_exists($key, $arr) → 键是否存在
        if ($node->callee === null && $node->name === 'array_key_exists') {
            $keyType = $this->inferType($node->args[0]);
            $keyCode = $node->args[0]->accept($this);
            $arrCode = $node->args[1]->accept($this);
            if ($keyType === 't_string') {
                return 'tphp_fn_array_key_exists_str(' . $keyCode . ', ' . $arrCode . ')';
            }
            return 'tphp_fn_array_key_exists_int(' . $keyCode . ', ' . $arrCode . ')';
        }

        // array_keys($arr) → 返回所有 key 组成的新数组
        if ($node->callee === null && $node->name === 'array_keys') {
            $arrCode = $node->args[0]->accept($this);
            return 'tphp_fn_array_keys(' . $arrCode . ')';
        }

        // array_values($arr) → 返回所有 value 组成的新数组
        if ($node->callee === null && $node->name === 'array_values') {
            $arrCode = $node->args[0]->accept($this);
            return 'tphp_fn_array_values(' . $arrCode . ')';
        }

        // array_merge($arr1, $arr2) → 合并两个数组
        if ($node->callee === null && $node->name === 'array_merge') {
            $a1 = $node->args[0]->accept($this);
            $a2 = $node->args[1]->accept($this);
            return 'tphp_fn_array_merge(' . $a1 . ', ' . $a2 . ')';
        }

        // Phase 2 array functions — map to tphp_fn_arr_* convention
        if ($node->callee === null && in_array($node->name, ['array_chunk','array_combine','array_count_values'], true)) {
            $fnMap = ['array_chunk'=>'tphp_fn_arr_chunk','array_combine'=>'tphp_fn_arr_combine','array_count_values'=>'tphp_fn_arr_count_values'];
            $args = array_map(fn($a) => $a->accept($this), $node->args);
            return $fnMap[$node->name] . '(' . implode(', ', $args) . ')';
        }

        // array_shift($arr) → 移除头部元素，返回 t_var
        if ($node->callee === null && $node->name === 'array_shift') {
            $arrCode = $node->args[0]->accept($this);
            $tv = '_ts_' . (++$this->tmpVarCounter);
            return "({ t_var {$tv} = VAR_NULL(); tphp_fn_arr_shift({$arrCode}, &{$tv}); {$tv}; })";
        }

        // array_unshift($arr, $val) → 头部追加，返回新长度
        if ($node->callee === null && $node->name === 'array_unshift') {
            $arrCode = $node->args[0]->accept($this);
            $valCode = $this->wrapArrayElement($node->args[1], $node->args[1]->accept($this));
            return 'tphp_fn_arr_unshift(' . $arrCode . ', ' . $valCode . ')';
        }

        // array_sum($arr)
        if ($node->callee === null && $node->name === 'array_sum') {
            $arrCode = $node->args[0]->accept($this);
            return 'tphp_fn_arr_sum(' . $arrCode . ')';
        }

        // array_product($arr)
        if ($node->callee === null && $node->name === 'array_product') {
            $arrCode = $node->args[0]->accept($this);
            return 'tphp_fn_arr_product(' . $arrCode . ')';
        }

        // array_reverse($arr, $preserve_keys=false)
        if ($node->callee === null && $node->name === 'array_reverse') {
            $arrCode = $node->args[0]->accept($this);
            $pk = isset($node->args[1]) ? $node->args[1]->accept($this) : 'false';
            return 'tphp_fn_arr_reverse(' . $arrCode . ', ' . $pk . ')';
        }

        // array_slice($arr, $offset, $length=0, $preserve_keys=false)
        if ($node->callee === null && $node->name === 'array_slice') {
            $arrCode = $node->args[0]->accept($this);
            $offset  = $node->args[1]->accept($this);
            $len     = isset($node->args[2]) ? $node->args[2]->accept($this) : '0';
            $pk      = isset($node->args[3]) ? $node->args[3]->accept($this) : 'false';
            return 'tphp_fn_arr_slice(' . $arrCode . ', ' . $offset . ', ' . $len . ', ' . $pk . ')';
        }

        // max($arr)
        if ($node->callee === null && $node->name === 'max') {
            $arrCode = $node->args[0]->accept($this);
            return 'tphp_fn_max(' . $arrCode . ')';
        }

        // min($arr)
        if ($node->callee === null && $node->name === 'min') {
            $arrCode = $node->args[0]->accept($this);
            return 'tphp_fn_min(' . $arrCode . ')';
        }

        // strlen($str)
        if ($node->callee === null && $node->name === 'strlen') {
            return 'tphp_fn_strlen(' . $node->args[0]->accept($this) . ')';
        }

        // trim($str) / ltrim($str) / rtrim($str)
        if ($node->callee === null && in_array($node->name, ['trim', 'ltrim', 'rtrim'], true)) {
            return 'tphp_fn_' . $node->name . '(' . $node->args[0]->accept($this) . ')';
        }

        // substr($str, $offset, $length=0)
        if ($node->callee === null && $node->name === 'substr') {
            $s   = $node->args[0]->accept($this);
            $off = $node->args[1]->accept($this);
            $len = isset($node->args[2]) ? $node->args[2]->accept($this) : '0';
            return 'tphp_fn_substr(' . $s . ', ' . $off . ', ' . $len . ')';
        }

        // strpos($haystack, $needle)
        if ($node->callee === null && $node->name === 'strpos') {
            return 'tphp_fn_strpos(' . $node->args[0]->accept($this) . ', ' . $node->args[1]->accept($this) . ')';
        }

        // str_contains($haystack, $needle)
        if ($node->callee === null && $node->name === 'str_contains') {
            return 'tphp_fn_str_contains(' . $node->args[0]->accept($this) . ', ' . $node->args[1]->accept($this) . ')';
        }

        // str_replace($search, $replace, $subject) — 支持数组参数
        if ($node->callee === null && $node->name === 'str_replace') {
            $sType = $this->inferType($node->args[0]);
            $rType = $this->inferType($node->args[1]);
            $sCode = $node->args[0]->accept($this);
            $rCode = $node->args[1]->accept($this);
            $subjCode = $node->args[2]->accept($this);
            // 两个都是数组 → 数组变体
            if ($sType === 't_array*' && $rType === 't_array*') {
                return "tphp_fn_str_replace_arr({$sCode}, {$rCode}, {$subjCode})";
            }
            // search 是数组，replace 是字符串 → 同一替换串
            if ($sType === 't_array*') {
                return "tphp_fn_str_replace_arr_str({$sCode}, {$rCode}, {$subjCode})";
            }
            // search 是字符串，replace 是数组 → PHP 对每个 replace 应用同一 search（不常见，按字符串处理）
            return "tphp_fn_str_replace({$sCode}, {$rCode}, {$subjCode})";
        }

        // sprintf($fmt, ...$args) → 动态测量 + str_pool_alloc（无上限，完整 C 格式支持）
        if ($node->callee === null && $node->name === 'sprintf') {
            $tn = '_sf_' . (++$this->tmpVarCounter);
            $fmtCode = $node->args[0]->accept($this);
            $fmtArgs = '';
            for ($i = 1; isset($node->args[$i]); $i++) {
                $arg = $node->args[$i]->accept($this);
                $type = $this->inferType($node->args[$i]);
                if ($type === 't_string')      $fmtArgs .= ', ' . $arg . '.data';
                elseif ($type === 't_float')   $fmtArgs .= ', (double)' . $arg;
                else                           $fmtArgs .= ', (int)' . $arg;
            }
            return "({ int {$tn}_len = snprintf(NULL, 0, {$fmtCode}.data{$fmtArgs});"
                 . " char* {$tn}_buf = str_pool_alloc({$tn}_len + 1);"
                 . " snprintf({$tn}_buf, {$tn}_len + 1, {$fmtCode}.data{$fmtArgs});"
                 . " tphp_rt_str_dup((t_string){{$tn}_buf, {$tn}_len}); })";
        }

        // array_map($callback, $arr) — 编译期内联展开，类型特化
        if ($node->callee === null && $node->name === 'array_map') {
            return $this->generateArrayMap($node);
        }

        // array_filter($arr, $callback) — 编译期内联展开
        if ($node->callee === null && $node->name === 'array_filter') {
            return $this->generateArrayFilter($node);
        }

        // array_reduce($arr, $callback, $initial) — 编译期内联展开
        if ($node->callee === null && $node->name === 'array_reduce') {
            return $this->generateArrayReduce($node);
        }

        // 类型转换: intval/floatval/strval/boolval
        if ($node->callee === null && in_array($node->name, ['intval', 'floatval', 'strval', 'boolval'], true)) {
            $valCode = $this->wrapVar($node->args[0]);
            return 'tphp_fn_' . $node->name . '(' . $valCode . ')';
        }

        // rand($min, $max) / mt_rand($min, $max) / random_int($min, $max)
        if ($node->callee === null && in_array($node->name, ['rand', 'mt_rand', 'random_int'], true)) {
            $min = $node->args[0]->accept($this);
            $max = $node->args[1]->accept($this);
            return 'tphp_fn_' . $node->name . '(' . $min . ', ' . $max . ')';
        }

        // random_bytes($length)
        if ($node->callee === null && $node->name === 'random_bytes') {
            return 'tphp_fn_random_bytes(' . $node->args[0]->accept($this) . ')';
        }

        // sort($arr) / rsort($arr) — in-place sort
        if ($node->callee === null && ($node->name === 'sort' || $node->name === 'rsort')) {
            $arrCode = $node->args[0]->accept($this);
            return 'tphp_fn_' . $node->name . '(' . $arrCode . ')';
        }

        // shuffle($arr) — Fisher-Yates in-place
        if ($node->callee === null && $node->name === 'shuffle') {
            $arrCode = $node->args[0]->accept($this);
            return 'tphp_fn_shuffle(' . $arrCode . ')';
        }

        // file_get_contents($path) — 路径为 t_string，C 端需 const char*
        if ($node->callee === null && $node->name === 'file_get_contents') {
            $pathCode = $node->args[0]->accept($this);
            return 'tphp_fn_file_get_contents(' . $pathCode . '.data)';
        }

        // file_put_contents($path, $data)
        if ($node->callee === null && $node->name === 'file_put_contents') {
            $pathCode = $node->args[0]->accept($this);
            $dataCode = $node->args[1]->accept($this);
            return 'tphp_fn_file_put_contents(' . $pathCode . '.data, ' . $dataCode . ')';
        }

        // array_search($needle, $haystack)
        if ($node->callee === null && $node->name === 'array_search') {
            $needle = $this->wrapVar($node->args[0]);
            $arr    = $node->args[1]->accept($this);
            return 'tphp_fn_arr_search(' . $arr . ', ' . $needle . ')';
        }

        // array_unique($arr)
        if ($node->callee === null && $node->name === 'array_unique') {
            $arrCode = $node->args[0]->accept($this);
            return 'tphp_fn_arr_unique(' . $arrCode . ')';
        }

        // range($start, $end, $step=1)
        if ($node->callee === null && $node->name === 'range') {
            $start = $node->args[0]->accept($this);
            $end   = $node->args[1]->accept($this);
            $step  = isset($node->args[2]) ? $node->args[2]->accept($this) : '1';
            return 'tphp_fn_range(' . $start . ', ' . $end . ', ' . $step . ')';
        }

        // array_fill($start_index, $count, $value)
        if ($node->callee === null && $node->name === 'array_fill') {
            $start = $node->args[0]->accept($this);
            $count = $node->args[1]->accept($this);
            $val   = $this->wrapArrayElement($node->args[2], $node->args[2]->accept($this));
            return 'tphp_fn_arr_fill(' . $start . ', ' . $count . ', ' . $val . ')';
        }

        // implode($glue, $arr) → 用分隔符连接数组元素为字符串
        if ($node->callee === null && ($node->name === 'implode' || $node->name === 'join')) {
            $glue = $node->args[0]->accept($this);
            $arr  = $node->args[1]->accept($this);
            return 'tphp_fn_implode(' . $glue . ', ' . $arr . ')';
        }

        // explode($delim, $str) → 按分隔符切分字符串为数组
        if ($node->callee === null && $node->name === 'explode') {
            $delim = $node->args[0]->accept($this);
            $str   = $node->args[1]->accept($this);
            return 'tphp_fn_explode(' . $delim . ', ' . $str . ')';
        }

        // json_encode($val) → JSON 字符串
        if ($node->callee === null && $node->name === 'json_encode') {
            $valCode = $this->wrapVar($node->args[0]);
            return 'tphp_fn_json_encode(' . $valCode . ')';
        }

        // json_decode($str) → mixed (t_var)
        if ($node->callee === null && $node->name === 'json_decode') {
            $strCode = $node->args[0]->accept($this);
            return 'tphp_fn_json_decode(' . $strCode . ')';
        }

        // var_export 内置函数 —— 转换为可读字符串输出
        if ($node->callee === null && $node->name === 'var_export') {
            return $this->generateVarExport($node->args);
        }

        // exit / die 内置函数 → tphp_fn_exit(code)
        if ($node->callee === null && ($node->name === 'exit' || $node->name === 'die')) {
            $args = array_map(fn($a) => $a->accept($this), $node->args);
            $code = !empty($args) ? $args[0] : '0';
            return 'tphp_fn_exit(' . $code . ')';
        }

        // error($msg) → 报错 + 清理所有资源 + 退出
        if ($node->callee === null && $node->name === 'error') {
            $msg  = !empty($node->args) ? $this->castToStr($node->args[0]) : 'STR_LIT("")';
            $line = !empty($node->args) ? ($node->args[0]->line ?? 0) : 0;
            $file = '"' . str_replace(['\\', '"'], ['/', '\"'], $this->phpFile) . '"';
            return 'tphp_fn_error(' . $msg . ', ' . $file . ', ' . $line . ')';
        }

        // time() → Unix 时间戳
        if ($node->callee === null && $node->name === 'time') {
            return 'tphp_fn_time()';
        }

        // date($format, $ts?) → 格式化时间
        if ($node->callee === null && $node->name === 'date') {
            $args = array_map(fn($a) => $a->accept($this), $node->args);
            $fmt  = $args[0] ?? 'STR_LIT("%c")';
            $ts   = $args[1] ?? '-1';  // -1 = not passed, use current time
            return 'tphp_fn_date(' . $fmt . ', ' . $ts . ')';
        }

        // sleep($seconds)
        if ($node->callee === null && $node->name === 'sleep') {
            $args = array_map(fn($a) => $a->accept($this), $node->args);
            $sec  = $args[0] ?? '0';
            return 'tphp_fn_sleep(' . $sec . ')';
        }

        // usleep($microseconds)
        if ($node->callee === null && $node->name === 'usleep') {
            $args = array_map(fn($a) => $a->accept($this), $node->args);
            $us = $args[0] ?? '0';
            return 'tphp_fn_usleep(' . $us . ')';
        }

        // password_hash($pw, $algo, $options=[]) → options 默认 NULL
        if ($node->callee === null && $node->name === 'password_hash') {
            $args = array_map(fn($a) => $a->accept($this), $node->args);
            $pw = $args[0] ?? 'STR_LIT("")';
            $algo = $args[1] ?? '1';
            $opts = $args[2] ?? 'NULL';
            return "tphp_fn_password_hash({$pw}, {$algo}, {$opts})";
        }

        // hrtime() → 高分辨率时间（纳秒）
        if ($node->callee === null && $node->name === 'hrtime') {
            return 'tphp_fn_hrtime()';
        }

        // isset($var) → 非 null 检测（非指针类型始终 true）
        if ($node->callee === null && $node->name === 'isset') {
            $args = array_map(fn($a) => $a->accept($this), $node->args);
            $code = !empty($args) ? $args[0] : 'null';
            $type = !empty($node->args) ? $this->inferType($node->args[0]) : 'null';
            // 非指针类型（int/float/bool/string栈值）不可能为 null，始终 true
            if (in_array($type, ['t_int', 't_float', 't_bool', 't_string'], true)) return 'true';
            return 'tphp_fn_isset((void*)' . $code . ')';
        }

        // empty($var) → PHP falsy 检测，按类型分发到 C 函数
        if ($node->callee === null && $node->name === 'empty') {
            $args = array_map(fn($a) => $a->accept($this), $node->args);
            $code = !empty($args) ? $args[0] : 'true';
            $type = !empty($node->args) ? $this->inferType($node->args[0]) : 't_int';
            return match ($type) {
                't_int'    => 'tphp_fn_empty_int(' . $code . ')',
                't_float'  => 'tphp_fn_empty_float(' . $code . ')',
                't_bool'   => 'tphp_fn_empty_bool(' . $code . ')',
                't_string' => 'tphp_fn_empty_str(' . $code . ')',
                'null'     => 'tphp_fn_empty_null(' . $code . ')',
                default    => 'tphp_fn_empty_int(' . $code . ')',
            };
        }

        // unset($var) → 按类型释放/重置（不改变变量声明状态）
        if ($node->callee === null && $node->name === 'unset') {
            $lines = [];
            foreach ($node->args as $arg) {
                if (!$arg instanceof VariableExpr) continue;
                $code = $arg->accept($this);
                $type = $this->inferType($arg);
                $lines[] = match ($type) {
                    't_string'   => "{$code} = (t_string){.data = NULL, .length = 0, .is_local = false};",
                    't_array*'   => "tphp_rt_unregister((void*){$code}); if ({$code} != NULL) { tphp_fn_arr_free({$code}); {$code} = NULL; }",
                    't_callback' => "if (({$code}).env != NULL) { tphp_rt_unregister(({$code}).env); free(({$code}).env); ({$code}).env = NULL; } ({$code}).func = NULL;",
                    'null'       => "{$code} = NULL;",
                    default      => "{$code} = 0;",
                };
                if (str_starts_with($type, 'tphp_class_') || str_starts_with($type, 'tphp_enum_')) {
                    $lines[count($lines)-1] = "tphp_rt_unregister((void*){$code}); tphp_fn_unset_obj((void**)&{$code});";
                    $vn = self::varName($arg->name);
                    $this->symbols->removeScopeObjects([$vn]);
                }
            }
            return implode('; ', $lines) . ';';
        }

        // is_numeric — 必须在通用 is_* 前处理（它不是类型检测）
        if ($node->callee === null && $node->name === 'is_numeric') {
            if (empty($node->args)) return 'false';
            $argCode = $node->args[0]->accept($this);
            return 'tphp_fn_is_numeric_str(' . $argCode . ')';
        }

        // ctype_* functions (string → bool, direct C mapping)
        if ($node->callee === null && str_starts_with($node->name, 'ctype_')) {
            return 'tphp_fn_' . $node->name . '(' . $node->args[0]->accept($this) . ')';
        }

        // is_int / is_string / is_float / is_bool / is_array / is_object / is_null / is_callable
        if ($node->callee === null && str_starts_with($node->name, 'is_')) {
            $args = array_map(fn($a) => $a->accept($this), $node->args);
            $code = !empty($args) ? $args[0] : 'false';
            $type = !empty($node->args) ? $this->inferType($node->args[0]) : 't_int';
            return $this->generateIsCheck($node->name, $code, $type);
        }

        // ── 第一梯队新增 ────────────────────────────────────
        if ($node->callee === null) {
            $n = $node->name;
            $a = array_map(fn($a) => $a->accept($this), $node->args);
            $c = count($a) > 0 ? $a[0] : '';

            // 特殊：需要类型转换或非标准 C 名
            if ($n === 'uniqid' && empty($a)) return 'tphp_fn_uniqid0()';
            if ($n === 'uniqid' && $c)        return "tphp_fn_uniqid({$c})";
            if ($n === 'deg2rad' && $c)       return "tphp_fn_deg2rad((t_float)({$c}))";
            if ($n === 'rad2deg' && $c)       return "tphp_fn_rad2deg((t_float)({$c}))";
            if ($n === 'gettype') {
                $t0 = $this->inferType($node->args[0]);
                $w = match ($t0) {
                    't_int' => "VAR_INT({$c})", 't_float' => "VAR_FLOAT((t_float)({$c}))",
                    't_bool' => "VAR_BOOL({$c})", 't_string' => "VAR_STRING({$c})",
                    default => "VAR_NULL",
                };
                return "tphp_fn_gettype({$w})";
            }
            if ($n === 'number_format') {
                if (count($a) >= 2) return "tphp_fn_number_format2((t_float)({$a[0]}), {$a[1]})";
                return "tphp_fn_number_format((t_float)({$a[0]}))";
            }
            if ($n === 'pow') {
                $ta = ($this->inferType($node->args[0]) === 't_int') ? "VAR_INT({$a[0]})" : "VAR_FLOAT((t_float)({$a[0]}))";
                $tb = ($this->inferType($node->args[1]) === 't_int') ? "VAR_INT({$a[1]})" : "VAR_FLOAT((t_float)({$a[1]}))";
                return "tphp_fn_pow({$ta}, {$tb})";
            }
            if ($n === 'mktime')   return "tphp_fn_mktime({$a[0]},{$a[1]},{$a[2]},{$a[3]},{$a[4]},{$a[5]})";
            // 默认参数
            if ($n === 'str_split') {
                $ck = count($a) >= 2 ? $a[1] : '1';
                return "tphp_fn_str_split({$c}, {$ck})";
            }
            if ($n === 'str_pad') {
                $pad = count($a) >= 3 ? $a[2] : '(t_string){NULL,0}';
                $ty  = count($a) >= 4 ? $a[3] : '0';
                return "tphp_fn_str_pad({$c}, {$a[1]}, {$pad}, {$ty})";
            }
            // 非标准 C 名
            if ($n === 'is_numeric')   return "tphp_fn_is_numeric_str({$c})";
            if ($n === 'array_is_list') return "tphp_fn_array_is_list_int({$c})";
            if ($n === 'crc32')        return "tphp_fn_crc32_str({$c})";
            if ($n === 'array_column') return "tphp_fn_array_column_str({$a[0]}, {$a[1]})";
            if ($n === 'strtr') {
                if (count($a) >= 3) return "tphp_fn_strtr2({$a[0]}, {$a[1]}, {$a[2]})";
                return $c;
            }

            // PHPC 互操作函数：加 tphp_fn_ 前缀
            $phpcFns = ['c_int','c_float','c_str','php_int','php_float','php_str','php_str_clone',
                        'phpc_arr_int','phpc_arr_dbl','phpc_arr_str','phpc_new_arr_int',
                        'phpc_new_arr_dbl','phpc_new_arr_str','phpc_new_arr',
                        'phpc_obj','phpc_new_obj','phpc_unregister_obj','phpc_free','phpc_free_str_arr',
                        'phpc_fn','phpc_env','phpc_fn_i32','phpc_fn_i64','phpc_fn_f64',
                        'phpc_new_fn','phpc_new_fn_env','phpc_thunk',
                        'phpc_assert_ptr','phpc_obj_steal','phpc_env_pin','phpc_env_unpin'];
            $shortN = strrchr($n, '\\') !== false ? substr(strrchr($n, '\\'), 1) : $n;
            if (in_array($shortN, $phpcFns, true)) {
                // phpc_thunk 特殊处理：按 #callback 声明生成 thunk
                if ($shortN === 'phpc_thunk' && count($a) >= 2 && $node->args[0] instanceof StringLiteralExpr) {
                    $cbName = $node->args[0]->value;
                    if (isset($this->phpcCallbackSigs[$cbName])) {
                        return $this->generateThunk($cbName, $node->args[1]);
                    }
                    throw new \RuntimeException("Unknown callback: #callback {$cbName} not declared");
                }
                // phpc_free / phpc_free_str_arr: 释放后自动置零变量，防 use-after-free
                // 仅当第一参数是简单变量时置零（避免对表达式置零）
                if ($shortN === 'phpc_free' && count($node->args) >= 1
                    && $node->args[0] instanceof VariableExpr) {
                    $varName = $this->visitVariable($node->args[0]);
                    return '(tphp_fn_phpc_free(' . $varName . '), (' . $varName . ' = NULL))';
                }
                if ($shortN === 'phpc_free_str_arr' && count($node->args) >= 2
                    && $node->args[0] instanceof VariableExpr) {
                    $varName = $this->visitVariable($node->args[0]);
                    $lenArg = $a[1];
                    return '(tphp_fn_phpc_free_str_arr(' . $varName . ', (int)(' . $lenArg . ')), (' . $varName . ' = NULL))';
                }
                return 'tphp_fn_' . $shortN . '(' . implode(', ', $a) . ')';
            }

            // filter_var(mixed $value, int $filter, array|int $options = 0): mixed
            // - 第一参数 mixed → wrapVar 包成 t_var
            // - 第三参数 array|int 联合：array → tphp_fn_filter_var_opt；int/省略 → tphp_fn_filter_var
            if ($shortN === 'filter_var') {
                $valVar = $this->wrapVar($node->args[0]);
                $filterCode = $a[1] ?? '0';
                if (isset($node->args[2])) {
                    $optType = $this->inferType($node->args[2]);
                    if ($optType === 't_array*' || $node->args[2] instanceof ArrayLiteralExpr) {
                        $optCode = $a[2];
                        return "tphp_fn_filter_var_opt({$valVar}, {$filterCode}, {$optCode})";
                    }
                }
                $optCode = $a[2] ?? '0';
                return "tphp_fn_filter_var({$valVar}, {$filterCode}, {$optCode})";
            }
            // filter_list(): array → tphp_fn_filter_list()
            if ($shortN === 'filter_list') {
                return 'tphp_fn_filter_list()';
            }
            // filter_id(string $name): int → tphp_fn_filter_id(name)
            if ($shortN === 'filter_id') {
                return 'tphp_fn_filter_id(' . implode(', ', $a) . ')';
            }

            // ── iconv 系列内置函数 (含可选参数 → C 函数不支持默认值，需分发) ──
            // iconv_strlen($str, $charset="UTF-8")
            if ($shortN === 'iconv_strlen') {
                $args = [ $a[0], $a[1] ?? 'STR_LIT("UTF-8")' ];
                return 'tphp_fn_iconv_strlen(' . implode(', ', $args) . ')';
            }
            // iconv_strpos($h, $n, $offset=0, $charset="UTF-8")
            if ($shortN === 'iconv_strpos') {
                $args = [ $a[0], $a[1], $a[2] ?? '0', $a[3] ?? 'STR_LIT("UTF-8")' ];
                return 'tphp_fn_iconv_strpos(' . implode(', ', $args) . ')';
            }
            // iconv_substr($str, $offset, $length=0, $charset="UTF-8")
            if ($shortN === 'iconv_substr') {
                $args = [ $a[0], $a[1], $a[2] ?? '0', $a[3] ?? 'STR_LIT("UTF-8")' ];
                return 'tphp_fn_iconv_substr(' . implode(', ', $args) . ')';
            }
            // iconv_get_encoding($type="all") — 始终返回完整数组，type 参数被忽略
            if ($shortN === 'iconv_get_encoding') {
                return 'tphp_fn_iconv_get_encoding(' . ($a[0] ?? 'STR_LIT("all")') . ')';
            }
            // iconv_set_encoding($type, $encoding)
            if ($shortN === 'iconv_set_encoding') {
                return 'tphp_fn_iconv_set_encoding(' . implode(', ', $a) . ')';
            }
            // iconv($from, $to, $str)
            if ($shortN === 'iconv') {
                return 'tphp_fn_iconv(' . implode(', ', $a) . ')';
            }
            // iconv_mime_encode($field_name, $field_value, $prefs=[])
            if ($shortN === 'iconv_mime_encode') {
                $args = [ $a[0], $a[1], $a[2] ?? 'NULL' ];
                return 'tphp_fn_iconv_mime_encode(' . implode(', ', $args) . ')';
            }
            // iconv_mime_decode($str, $mode=0, $charset="UTF-8")
            if ($shortN === 'iconv_mime_decode') {
                $args = [ $a[0], $a[1] ?? '0', $a[2] ?? 'STR_LIT("UTF-8")' ];
                return 'tphp_fn_iconv_mime_decode(' . implode(', ', $args) . ')';
            }

            // 通用回退：tphp_fn_函数名(参数) — C 编译器兜底
            // 全局函数: tphp_fn_name, 命名空间函数: tphp_na_Ns_tphp_fn_name
            $fnPos = strrpos($n, '\\');
            if ($fnPos !== false) {
                $fnName = 'tphp_na_' . str_replace('\\', '_', substr($n, 0, $fnPos)) . '_tphp_fn_' . substr($n, $fnPos + 1);
            } else {
                $fnName = 'tphp_fn_' . $n;
            }
            // 检查是否有默认值参数，选择正确的重载版本
            $argCount = count($node->args);
            $defaultCount = $this->funcDefaultCounts[$fnName] ?? 0;
            if ($defaultCount > 0) {
                // 获取总参数数量
                $totalParams = count($this->funcParamTypes[$fnName] ?? []);
                if ($totalParams > 0 && $argCount < $totalParams) {
                    // 使用重载版本：fnName_缺失参数数量
                    $missingCount = $totalParams - $argCount;
                    $fnName = $fnName . '_' . $missingCount;
                    // 更新参数类型列表（重载版本只有前 argCount 个参数）
                    $pTypes = array_slice($this->funcParamTypes[$fnName] ?? [], 0, $argCount);
                } else {
                    $pTypes = $this->funcParamTypes[$fnName] ?? [];
                }
            } else {
                $pTypes = $this->funcParamTypes[$fnName] ?? [];
            }
            if (empty($a)) return "{$fnName}()";
            // byRef 参数：形参是指针时要正确传参
            $callArgs = [];
            foreach ($node->args as $i => $arg) {
                $ct = $pTypes[$i] ?? '';
                $isParamByRef = $this->isByRefType($ct);
                if ($isParamByRef && $arg instanceof VariableExpr) {
                    $avn = self::varName($arg->name);
                    if ($this->isByRefType($this->varTypes[$avn] ?? '')) {
                        // byRef 实参 → byRef 形参：直接传指针（visitVariable 已解引用，必须用原始名）
                        $callArgs[] = $avn;
                    } else {
                        // 普通实参 → byRef 形参：取地址
                        $callArgs[] = '&' . self::varName($arg->name);
                    }
                } else {
                    $aCode = $arg->accept($this);
                    $callArgs[] = $isParamByRef ? '&' . $aCode : $aCode;
                }
            }
            return "{$fnName}(" . implode(', ', $callArgs) . ")";
        }

        // ── 第二/三梯队（已全部移入第一块，此处保留空壳以防后续扩展）──

        // 闭包调用: $h() → ((t_int(*)(...))h.func)(args)
        if ($node->callee !== null && $node->name === '__invoke') {
            return $this->generateClosureCall($node->callee, $node->args);
        }

        // 对 t_var 参数自动包裹 VAR_XXX
        $args = [];
        foreach ($node->args as $i => $a) {
            $code = $a->accept($this);
            // 查找该方法参数类型
            $pt = $this->getMethodParamType($node, $i);
            if ($pt === 't_var') {
                $code = $this->wrapTvarAssign($a, $code);
            }
            $args[] = $code;
        }
        if ($node->callee === null) {
            // phpc 桥接函数 → 直接 C 调用（无 tphp_fn_ 前缀，无命名空间 mangle）
            $baseName = ($pos = strrpos($node->name, '\\')) !== false
                ? substr($node->name, $pos + 1) : $node->name;

            // phpc_thunk('name', $fn) → 按 #callback 声明的签名生成 thunk
            if (($baseName === 'phpc_thunk' || str_ends_with($baseName, '\\phpc_thunk'))
                && count($node->args) >= 2
                && $node->args[0] instanceof StringLiteralExpr) {
                $cbName = $node->args[0]->value;
                if (isset($this->phpcCallbackSigs[$cbName])) {
                    return $this->generateThunk($cbName, $node->args[1]);
                }
                throw new \RuntimeException("Unknown callback: #callback {$cbName} not declared");
            }

            $phpcFns = ['c_int','c_float','c_str','php_int','php_float','php_str','php_str_clone',
                'phpc_arr_int','phpc_arr_dbl','phpc_arr_str',
                'phpc_new_arr_int','phpc_new_arr_dbl','phpc_new_arr_str','phpc_new_arr',
                'phpc_obj','phpc_new_obj','phpc_unregister_obj',
                'phpc_fn','phpc_env','phpc_new_fn','phpc_new_fn_env',
                'phpc_fn_i32','phpc_fn_i64','phpc_fn_f64',
                'phpc_free','phpc_free_str_arr',
                'phpc_assert_ptr','phpc_obj_steal','phpc_env_pin','phpc_env_unpin'];
            if (in_array($baseName, $phpcFns, true)) {
                return $baseName . '(' . implode(', ', $args) . ')';
            }
            // 独立函数：tphp_fn_ 前缀，命名空间名已 mangled
            $fnName = self::mangleCName($node->name);
            return 'tphp_fn_' . $fnName . '(' . implode(', ', $args) . ')';
        }
        $callee = $node->callee->accept($this);
        // Raw C call: C->function() → direct C function, no name mangling
        if ($node->isRawC) {
            return $node->name . '(' . implode(', ', $args) . ')';
        }
        // 方法调用：self 作为第一个参数
        $allArgs = array_merge([$callee], $args);
        // 类名推导
        if ($callee === 'self') {
            $cn = $this->className;
        } elseif ($node->callee instanceof VariableExpr) {
            $key = self::varName($node->callee->name);
            $raw = $this->varTypes[$key] ?? $key;
            $cn = str_contains($raw, '\\') ? self::classRefName($raw) : $raw;
        } elseif ($node->callee instanceof CallExpr) {
            // 链式调用：从上一个调用的返回类型推导
            $cn = $this->inferCallChainClass($node->callee);
        } else {
            $cn = $callee;
        }
        // nullsafe on null-typed variable → no-op
        if ($node->isNullsafe && ($cn === 'null' || $cn === '' || $cn === 'void*')) {
            return '0'; // nullsafe no-op
        }
        // Strip trailing * + resolve parent class for inherited methods
        $cnClean = rtrim($cn, '*');
        $useParent = false;
        if ($cnClean !== '' && !isset($this->classMethodRetTypes[$cnClean][$node->name])) {
            $parentCN = $this->resolveMethodClass($cnClean, $node->name);
            if ($parentCN !== '') { $cnClean = $parentCN; $useParent = true; }
        }
        // 校验方法存在性：未定义的方法直接报错，不生成无效 C 代码
        if ($cnClean !== '' && !isset($this->classMethodRetTypes[$cnClean][$node->name])
            && $node->name !== '__construct' && $node->name !== '__destruct') {
            throw new \RuntimeException(sprintf(
                "[%d:%d] Call to undefined method %s::%s()",
                $node->line, $node->column, $cnClean, $node->name
            ));
        }
        // If method is inherited, pass &obj->_parent as self
        $selfArg = $useParent ? ('&' . $callee . '->_parent') : $callee;
        $allArgs[0] = $selfArg;
        $call = "{$cnClean}_{$node->name}(" . implode(', ', $allArgs) . ')';
        // nullsafe ?-> : wrap in NULL check with temp variable
        if ($node->isNullsafe) {
            $ret = $this->classMethodRetTypes[$cnClean][$node->name] ?? 't_int';
            if ($ret === 'void') {
                return "({ if ((void*){$callee} != NULL) {{ {$call}; }} })";
            }
            $tmp = '_nsr_' . (++$this->tmpVarCounter);
            $zero = match ($ret) { 't_float' => '0.0', 't_string' => '(t_string){NULL,0}', default => '0' };
            return "({ {$ret} {$tmp} = {$zero}; if ((void*){$callee} != NULL) {{ $tmp = {$call}; }} {$tmp}; })";
        }
        return $call;
    }

    /** 推断链式调用的返回类名 */
    private function inferCallChainClass(CallExpr $expr): string
    {
        if ($expr->callee === null) return '';
        if ($expr->callee instanceof VariableExpr) {
            $key = self::varName($expr->callee->name);
            return $this->varTypes[$key] ?? '';
        }
        if ($expr->callee instanceof CallExpr) {
            return $this->inferCallChainClass($expr->callee);
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
        $callArgs = ($argStr ? $argStr . ', ' : '') . "{$calleeCode}.env";

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
            $fnName = $this->varClosureMap[$varName] ?? '';
            if ($fnName && isset($this->closureSigs[$fnName])) {
                $sig = $this->closureSigs[$fnName];
                $retType = $sig['ret'];
                $paramTypes = $sig['params'] ? $sig['params'] . ', void*' : 'void*';
            }
        }

        return "(($retType(*)({$paramTypes})){$calleeCode}.func)({$callArgs})";
    }

    // ── array_map / array_filter / array_reduce 编译期内联展开 ──

    /** 从 AST 推断闭包签名（无需先 visit） */
    private function inferCallbackSig(ExprNode $expr): ?array
    {
        if ($expr instanceof ClosureExpr) {
            $ret = self::mapType($expr->returnType);
            $params = array_map(fn($p) => self::mapType($p->type), $expr->params);
            return ['ret' => $ret, 'params' => $params];
        }
        if ($expr instanceof VariableExpr) {
            $varName = self::varName($expr->name);
            $fnName = $this->varClosureMap[$varName] ?? '';
            if ($fnName && isset($this->closureSigs[$fnName])) {
                $sig = $this->closureSigs[$fnName];
                $params = $sig['params'] !== '' ? array_map('trim', explode(',', $sig['params'])) : [];
                return ['ret' => $sig['ret'], 'params' => $params];
            }
        }
        return null;
    }

    /** 类型 → 数组元素 getter 函数名 */
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

    /** array_map($callback, $arr) → 类型特化内联循环 */
    private function generateArrayMap(CallExpr $node): string
    {
        $cbCode  = $node->args[0]->accept($this);
        $arrCode = $node->args[1]->accept($this);
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
        $arrCode = $node->args[0]->accept($this);
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
        $arrCode  = $node->args[0]->accept($this);
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
            if (str_starts_with($argType, 'tphp_class_')) {
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

    /** 将表达式包装为 t_var */
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
            // 类常量访问 → 查 constTypes
            if (str_starts_with($code, 'TPHP_CONST_')) {
                $ct = $this->constTypes[$code] ?? $this->constTypes[strtoupper(substr($code, 12))] ?? 't_int';
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
            $code = $expr->accept($this); // &_e_X_Y (指针)
            $bt = $this->enumBackingType($expr->enumName);
            return ($bt === 'string') ? "VAR_STRING(({$code})->value)" : "VAR_INT(({$code})->value)";
        }
        if ($expr instanceof VariableExpr) {
            // 常量引用（原始名字不以 $ 开头）—— 根据类型选择 VAR_*
            if (!str_starts_with($expr->name, '$')) {
                $cname = 'TPHP_CONST_' . strtoupper($expr->name);
                $ct = $this->constTypes[$expr->name] ?? 't_string';
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
            $code = $expr->accept($this);
            if ($this->hasStrKey($expr)) {
                // 检查 per-key 类型追踪
                if ($expr->index instanceof StringLiteralExpr && $expr->array instanceof VariableExpr) {
                    $at = self::varName($expr->array->name);
                    $kt = $this->arrValueTypes[$at][$expr->index->value] ?? 't_string';
                    if ($kt === 't_int') return "VAR_INT({$code})";
                }
                return "VAR_STRING({$code})";
            }
            // int 键：使用 inferType 判断实际元素类型
            $type = $this->inferType($expr);
            return match ($type) {
                't_string'   => "VAR_STRING({$code})",
                't_float'    => "VAR_FLOAT({$code})",
                't_bool'     => "VAR_BOOL({$code})",
                't_array*'   => "VAR_ARRAY({$code})",
                't_callback' => "VAR_CALLBACK({$code})",
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
            'null'       => "VAR_NULL()",
            default      => (str_contains($type, 'tphp_class_') || str_contains($type, 'tphp_enum_'))
                ? "VAR_OBJ({$code})"
                : "VAR_INT({$code})",
        };
    }

    public function visitCast(CastExpr $node): string
    {
        if ($node->castType === 'string') {
            return $this->castToStr($node->expr, strict: true);
        }
        if ($node->castType === 'int') {
            return $this->castToInt($node->expr);
        }
        if ($node->castType === 'float') {
            return $this->castToFloat($node->expr);
        }
        if ($node->castType === 'bool') {
            return $this->castToBool($node->expr);
        }
        if ($node->castType === 'array') {
            return $this->castToArray($node->expr);
        }
        return '((' . self::mapType($node->castType) . ')(' . $node->expr->accept($this) . '))';
    }

    public function visitNew(NewExpr $node): string
    {
        $cn = self::classRefName($node->className);
        $args = array_map(fn($a) => $a->accept($this), $node->args);
        if (empty($args) && $cn !== $this->className) {
            return "new_{$cn}()";
        }
        return "new_{$cn}(" . implode(', ', $args) . ')';
    }

    public function visitPropertyAccess(PropertyAccessExpr $node): string
    {
        // C->CONST — direct C constant/enum/macro access (no parentheses)
        if ($node->object instanceof VariableExpr && $node->object->name === 'C') {
            return $node->property;
        }
        $obj = $node->object->accept($this);
        $prop = ltrim($node->property, '$');
        // COS inheritance: resolve property through _parent chain
        $objCN = '';
        if ($obj === 'self') {
            $objCN = $this->className;
        } elseif ($node->object instanceof VariableExpr && !str_starts_with($node->object->name, '$')) {
            // static property — skip
        } elseif ($node->object instanceof VariableExpr) {
            $objType = $this->varTypes[self::varName($node->object->name)] ?? '';
            // tphp_class_Dog* → tphp_class_Dog
            $objCN = rtrim($objType, '*');
        }
        if ($objCN !== '' && !isset($this->classOwnProps[$objCN][$prop])) {
            // 枚举类型直接访问字段（无 COS _parent 包装）
            if (str_starts_with($objCN, 'tphp_enum_')) {
                return $obj . '->' . $prop;
            }
            // 类常量（大写开头）→ 不经过 _parent，由下方 const 逻辑处理
            if ($prop !== '' && ctype_upper($prop[0])) {
                // 交由下方类常量访问逻辑 → TPHP_CONST_ 引用
            } else {
                $prefix = $this->resolvePropPrefix($objCN, $prop);
                return $obj . '->_parent.' . $prefix . $prop;
            }
        }
        // 类常量访问: self::CONST 或 ClassName::CONST → TPHP_CONST_ 引用
        if (ctype_upper($prop[0] ?? '')) {
            $rawObjName = ($node->object instanceof VariableExpr) ? $node->object->name : '';
            // self::CONST
            if ($rawObjName === 'self' || $obj === 'self') {
                $cn = strtoupper($this->className);
                return 'TPHP_CONST_' . $cn . '_' . strtoupper($prop);
            }
            // ClassName::CONST — 解析类名，检查可见性
            $cname = $this->classNames[$rawObjName] ?? null;
            if ($cname !== null) {
                $fullCName = 'TPHP_CONST_' . strtoupper($cname . '_' . $prop);
                $vis = $this->constVis[$cname . '_' . $prop] ?? 'public';
                if ($vis !== 'public' && $vis !== null) {
                    throw new \RuntimeException(
                        "Cannot access {$vis} const {$rawObjName}::{$prop}"
                    );
                }
                return $fullCName;
            }
        }
        // 加括号防止 &enum->field 被 C 误解析为 &(enum->field)
        if (str_starts_with($obj, '&')) {
            return "({$obj})->{$prop}";
        }
        return "{$obj}->{$prop}";
    }

    public function visitPropertyDecl(PropertyDeclNode $node): string
    {
        return '';
    }

    public function visitConst(ConstNode $node): string
    {
        $name = 'TPHP_CONST_' . strtoupper($node->name);
        // 有声明类型时校验一致性，并以声明类型注册；无则按字面量推导
        $litCType = self::$litTypeMap[$node->value::class] ?? null;
        if ($node->type !== null) {
            $declCType = self::mapType($node->type);
            if ($litCType !== null && $declCType !== $litCType) {
                throw new \RuntimeException(
                    "Constant {$node->name} type mismatch: "
                    . "declared '{$node->type}' ({$declCType}) but value is {$litCType}"
                );
            }
            $ct = $declCType;
        } else {
            $ct = $litCType ?? 't_int';
        }
        $this->constTypes[$node->name] = $ct;
        if ($node->value instanceof StringLiteralExpr) {
            $val = str_replace('"', '\\"', $node->value->value);
            return '#define ' . $name . ' STR_LIT("' . $val . '")';
        }
        if ($node->value instanceof IntLiteralExpr) {
            return '#define ' . $name . ' ' . $node->value->value;
        }
        if ($node->value instanceof FloatLiteralExpr) {
            $fv = $node->value->value;
            return '#define ' . $name . ' ' .
                (($fv == (float)(int)$fv) ? sprintf('%.1f', $fv) : rtrim(rtrim(sprintf('%.15g', $fv), '0'), '.'));
        }
        if ($node->value instanceof BoolLiteralExpr) {
            return '#define ' . $name . ' ' . ($node->value->value ? 'true' : 'false');
        }
        return '/* const ' . $node->name . ' */';
    }

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

        $this->enumBackingTypes[$fqName] = $node->backingType;
        $this->enumBackingTypes[$node->name] = $node->backingType;
        $this->enumCTypes[$fqName] = $cName . '*';
        $this->enumCTypes[$node->name] = $cName . '*';

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
        }
        $lines[] = '';

        return implode("\n", $lines);
    }

    // EnumAccessExpr → 返回 static 实例指针
    public function visitEnumAccess(EnumAccessExpr $node): string
    {
        $prefix = self::mangleCName($node->enumName);
        return "&_e_{$prefix}_{$node->caseName}";
    }

    // ============================================================
    // 控制流
    // ============================================================

    public function visitIfStmt(IfStmtNode $node): string
    {
        $cond = $node->condition->accept($this);
        $this->scopeDepth++;
        $lines = [];
        $lines[] = "if ({$cond}) {";
        foreach ($node->thenBody as $s) $lines[] = $this->ind($s->accept($this));
        $lines[] = '}';
        foreach ($node->elseifs as $eif) {
            $econd = $eif->condition->accept($this);
            $lines[] = "else if ({$econd}) {";
            foreach ($eif->body as $s) $lines[] = $this->ind($s->accept($this));
            $lines[] = '}';
        }
        if (!empty($node->elseBody)) {
            $lines[] = 'else {';
            foreach ($node->elseBody as $s) $lines[] = $this->ind($s->accept($this));
            $lines[] = '}';
        }
        $this->scopeDepth--;
        return implode("\n", $lines);
    }

    public function visitWhileStmt(WhileStmtNode $node): string
    {
        $cond = $node->condition->accept($this);
        $this->scopeDepth++;
        $lines = [];
        $lines[] = "while ({$cond}) {";
        foreach ($node->body as $s) $lines[] = $this->ind($s->accept($this));
        $lines[] = '}';
        $this->scopeDepth--;
        return implode("\n", $lines);
    }

    public function visitDoWhileStmt(DoWhileStmtNode $node): string
    {
        $cond = $node->condition->accept($this);
        $this->scopeDepth++;
        $lines = [];
        $lines[] = 'do {';
        foreach ($node->body as $s) $lines[] = $this->ind($s->accept($this));
        $lines[] = "} while ({$cond});";
        $this->scopeDepth--;
        return implode("\n", $lines);
    }

    public function visitListStmt(ListStmtNode $node): string
    {
        $lines = [];
        $arrName = '_lst_' . (++$this->tmpVarCounter);
        $expr = $node->expr->accept($this);
        $lines[] = "t_array* {$arrName} = {$expr};";
        $this->generateListAssign($lines, $arrName, 0, $node->vars);
        // Keyed destructuring: ['key' => $var, ...] = $arr
        if (!empty($node->keyedEntries)) {
            $this->generateKeyedAssign($lines, $arrName, $node->keyedEntries);
        }
        return implode("\n", $lines);
    }

    /** Generate assignments for keyed list destructuring:
     *  ['key' => $var] = $arr  →  $var = tphp_fn_arr_get_str_int($arr, STR_LIT("key"));
     */
    private function generateKeyedAssign(array &$lines, string $arrName, array $entries): void
    {
        foreach ($entries as $e) {
            $key = $e['key'];
            $var = $e['var'];
            $klen = strlen($key);
            $isDeclared = isset($this->declaredVars[$var]);
            $this->declaredVars[$var] = true;
            $this->varTypes[$var] = 't_int';
            $prefix = $isDeclared ? '' : 't_int ';
            $lines[] = "{$prefix}{$var} = tphp_fn_arr_get_str_int({$arrName}, (t_string){.data=\"{$key}\", .length={$klen}});";
        }
    }

    /** 递归生成 list 赋值代码
     * @param array $vars (null|string|ListStmtNode)[]
     */
    private function generateListAssign(array &$lines, string $arrName, int $baseIdx, array $vars): void
    {
        $idx = $baseIdx;
        foreach ($vars as $item) {
            if ($item === null) {
                // 跳过当前元素
                $idx++;
                continue;
            }
            if ($item instanceof ListStmtNode) {
                // 嵌套 list：先取 t_var*，再取 .value._array
                $subArr = '_sublst_' . (++$this->tmpVarCounter);
                $tv     = '_tv_' . (++$this->tmpVarCounter);
                $lines[] = "t_var* {$tv} = ({$arrName} && {$arrName}->length > {$idx}) ? tphp_fn_arr_get_int({$arrName}, {$idx}) : NULL;";
                $lines[] = "t_array* {$subArr} = ({$tv} && {$tv}->type == TYPE_ARRAY) ? {$tv}->value._array : NULL;";
                $this->generateListAssign($lines, $subArr, 0, $item->vars);
                $idx++;
                continue;
            }
            // 普通变量
            $var = $item;
            $isDeclared = isset($this->declaredVars[$var]);
            $this->declaredVars[$var] = true;
            $this->varTypes[$var] = 't_int';
            $prefix = $isDeclared ? '' : 't_int ';
            $lines[] = "{$prefix}{$var} = ({$arrName} && {$arrName}->length > {$idx}) ? tphp_fn_arr_item_int({$arrName}, {$idx}) : 0;";
            $idx++;
        }
    }

    public function visitForStmt(ForStmtNode $node): string
    {
        $init = '';
        if ($node->init) {
            if ($node->init instanceof BinaryExpr && $node->init->operator === '=') {
                $v = $node->init->left->accept($this);
                $e = $node->init->right->accept($this);
                $vn = ($node->init->left instanceof VariableExpr) ? self::varName($node->init->left->name) : '';
                $isDeclared = isset($this->declaredVars[$vn]);
                $this->declaredVars[$vn] = true;
                // 推断初始化表达式的类型（不仅仅是 t_int）
                $initType = $this->inferType($node->init->right);
                $this->varTypes[$vn] = $initType;
                if ($isDeclared) {
                    $init = "{$v} = {$e}";
                } else {
                    // 未声明变量：提升到函数作用域
                    $this->funcScopeDecls[$vn] = $initType;
                    $init = "{$v} = {$e}";
                }
            } else {
                $init = $node->init->accept($this);
            }
        }
        $cond = $node->condition ? $node->condition->accept($this) : '';
        $step = $node->step ? $node->step->accept($this) : '';
        $this->scopeDepth++;
        $lines = [];
        $lines[] = "for ({$init}; {$cond}; {$step}) {";
        foreach ($node->body as $s) $lines[] = $this->ind($s->accept($this));
        $lines[] = '}';
        $this->scopeDepth--;
        return implode("\n", $lines);
    }

    public function visitForeachStmt(ForeachStmtNode $node): string
    {
        // 生成器迭代分支：iterable 类型含 tphp_class_Generator
        $iterType = $this->inferType($node->array);
        if (str_contains($iterType, 'tphp_class_Generator')) {
            return $this->emitGeneratorForeach($node);
        }

        $arr  = $node->array->accept($this);
        $cnt  = '_fc_' . (++$this->tmpVarCounter);
        $idx  = '_fi_' . (++$this->tmpVarCounter);
        $valVar = ltrim($node->valueVar, '$');
        $keyVar = $node->keyVar ? ltrim($node->keyVar, '$') : '';

        // 推断数组元素类型
        $elemType = 't_int';
        if ($node->array instanceof VariableExpr) {
            $arrVarName = self::varName($node->array->name);
            $elemType = $this->arrElementTypes[$arrVarName] ?? 't_int';
            // 若数组无 int-key 元素类型追踪，尝试用 per-key 追踪默认值
            if ($elemType === 't_int' && isset($this->arrValueTypes[$arrVarName])) {
                $values = $this->arrValueTypes[$arrVarName];
                if (!empty($values)) $elemType = reset($values);
            }
        }
        // 规范化元素类型名
        if (str_contains($elemType, 'tphp_class_') && !str_ends_with($elemType, '*')) {
            $elemType .= '*';
        }

        // Mark vars as declared (after declaration check)
        $needKeyDecl = ($keyVar && !isset($this->declaredVars[$keyVar]));
        $needValDecl = !isset($this->declaredVars[$valVar]);

        $this->declaredVars[$valVar] = true;
        $this->varTypes[$valVar] = $elemType;
        // 传播嵌套类型：foreach($rows as $row) 中 $row 是数组时，记录其元素类型
        if ($elemType === 't_array*' && $node->array instanceof VariableExpr) {
            $arrVarName = self::varName($node->array->name);
            if (isset($this->arrNestedTypes[$arrVarName])) {
                $this->arrElementTypes[$valVar] = $this->arrNestedTypes[$arrVarName];
            } elseif (isset($this->arrElementTypes[$arrVarName]) && $this->arrElementTypes[$arrVarName] === 't_array*') {
                // 若源数组元素是数组但无嵌套类型追踪，设为 t_int
                $this->arrElementTypes[$valVar] = 't_int';
            }
        }
        if ($keyVar) {
            $this->declaredVars[$keyVar] = true;
            // 检测数组是否包含字符串 key
            $hasStrKey = false;
            if ($node->array instanceof VariableExpr) {
                $arrVarName = self::varName($node->array->name);
                $hasStrKey = isset($this->arrValueTypes[$arrVarName]) && !empty($this->arrValueTypes[$arrVarName]);
            }
            $keyType = $hasStrKey ? 't_string' : 't_int';
            $this->varTypes[$keyVar] = $keyType;
        }

        // 根据元素类型生成值读取代码
        $valRead = match ($elemType) {
            't_float'    => "(_eval->type == TYPE_FLOAT) ? (t_float)_eval->value._float : 0.0",
            't_string'   => "(_eval->type == TYPE_STRING) ? _eval->value._string : ((t_string){NULL, 0})",
            't_bool'     => "(_eval->type == TYPE_BOOL) ? (t_bool)_eval->value._bool : false",
            't_array*'   => "(_eval->type == TYPE_ARRAY) ? _eval->value._array : NULL",
            't_callback' => "(_eval->type == TYPE_CALLBACK) ? _eval->value._callback : ((t_callback){NULL, NULL})",
            default      => (str_contains($elemType, 'tphp_class_')
                ? "(_eval->type == TYPE_OBJECT) ? (({$elemType})_eval->value._ptr) : NULL"
                : "(_eval->type == TYPE_INT) ? (t_int)_eval->value._int : 0"),
        };

        $valDecl = match ($elemType) {
            't_float'  => 't_float',
            't_string' => 't_string',
            't_bool'   => 't_bool',
            't_array*' => 't_array*',
            't_callback' => 't_callback',
            default    => (str_contains($elemType, 'tphp_class_') ? $elemType : 't_int'),
        };

        $keyType = $keyVar ? ($this->varTypes[$keyVar] ?? 't_int') : '';
        $lines = [];
        if ($needKeyDecl) {
            if ($keyType === 't_string') {
                $lines[] = "t_string {$keyVar};";
            } else {
                $lines[] = "t_int {$keyVar};";
            }
        }
        if ($needValDecl) {
            $lines[] = "{$valDecl} {$valVar};";
        }
        $lines[] = "for (int {$idx} = 0; {$idx} < tphp_fn_arr_count({$arr}); {$idx}++) {";
        if ($keyVar) {
            $lines[] = $this->ind("if ({$arr} == NULL) break;");
            $lines[] = $this->ind("const t_arr_entry* _ent = &{$arr}->entries[{$idx}];");
            $lines[] = $this->ind("const t_var* _ekey = &_ent->key;");
            if ($keyType === 't_string') {
                $lines[] = $this->ind("{ {$keyVar} = (_ekey->type == TYPE_STRING) ? _ekey->value._string : ((t_string){NULL, 0}); }");
            } else {
                $lines[] = $this->ind("{ {$keyVar} = (_ekey->type == TYPE_INT) ? (t_int)_ekey->value._int : 0; }");
            }
            $lines[] = $this->ind("const t_var* _eval = &_ent->val;");
        } else {
            $lines[] = $this->ind("t_var* _eval = tphp_fn_arr_index({$arr}, {$idx});");
        }
        $lines[] = $this->ind("if (_eval == NULL) continue;");
        $lines[] = $this->ind("{$valVar} = {$valRead};");
        $this->scopeDepth++;
        foreach ($node->body as $s) $lines[] = $this->ind($s->accept($this));
        $this->scopeDepth--;
        $lines[] = '}';
        return implode("\n", $lines);
    }

    /** 生成器 foreach：while (valid) { key/current; body; next; } */
    private function emitGeneratorForeach(ForeachStmtNode $node): string
    {
        $gExpr = $node->array->accept($this);
        $gTmp = '_gen_iter_' . (++$this->tmpVarCounter);
        $valVar = ltrim($node->valueVar, '$');
        $keyVar = $node->keyVar ? ltrim($node->keyVar, '$') : '';

        $needValDecl = !isset($this->declaredVars[$valVar]);
        $needKeyDecl = ($keyVar && !isset($this->declaredVars[$keyVar]));

        $this->declaredVars[$valVar] = true;
        $this->varTypes[$valVar] = 't_var';
        if ($keyVar) {
            $this->declaredVars[$keyVar] = true;
            $this->varTypes[$keyVar] = 't_var';
        }

        $lines = [];
        if ($needKeyDecl) $lines[] = "t_var {$keyVar};";
        if ($needValDecl) $lines[] = "t_var {$valVar};";
        $lines[] = "tphp_class_Generator* {$gTmp} = {$gExpr};";
        $lines[] = "while (tphp_class_Generator_valid({$gTmp})) {";
        if ($keyVar) {
            $lines[] = $this->ind("{$keyVar} = tphp_class_Generator_key({$gTmp});");
        }
        $lines[] = $this->ind("{$valVar} = tphp_class_Generator_current({$gTmp});");
        $this->scopeDepth++;
        foreach ($node->body as $s) $lines[] = $this->ind($s->accept($this));
        $this->scopeDepth--;
        $lines[] = $this->ind("tphp_class_Generator_next({$gTmp});");
        $lines[] = '}';
        return implode("\n", $lines);
    }

    public function visitSwitchStmt(SwitchStmtNode $node): string
    {
        $condCode = $node->condition->accept($this);
        $condType = $this->inferType($node->condition);

        // 字符串 switch → 生成 if-elseif 链（C switch 不支持字符串）
        if ($condType === 't_string') {
            return $this->generateStringSwitch($condCode, $node->cases);
        }

        // int/bool switch → 直接 C switch
        $lines = [];
        $lines[] = "switch ({$condCode}) {";
        foreach ($node->cases as $case) {
            if ($case->value !== null) {
                $valCode = $case->value->accept($this);
                $lines[] = "case {$valCode}:";
            } else {
                $lines[] = 'default:';
            }
            foreach ($case->body as $s) {
                $lines[] = $this->ind($s->accept($this));
            }
        }
        $lines[] = '}';
        return implode("\n", $lines);
    }

    /** 将 switch 字符串转为 if-elseif 链（break 在 if-else 中无效，自动跳过） */
    private function generateStringSwitch(string $condCode, array $cases): string
    {
        $lines = [];
        $first = true;
        foreach ($cases as $case) {
            if ($case->value !== null) {
                $valCode = $case->value->accept($this);
                $prefix = $first ? 'if' : 'else if';
                $lines[] = "{$prefix} (tphp_rt_str_eq({$condCode}, {$valCode})) {";
                $first = false;
            } else {
                // default case
                $lines[] = 'else {';
            }
            foreach ($case->body as $s) {
                // if-elseif 天然不穿透，break 无意义且会导致 C 编译错误
                if ($s instanceof BreakStmtNode) continue;
                $lines[] = $this->ind($s->accept($this));
            }
            $lines[] = '}';
        }
        return implode("\n", $lines);
    }

    public function visitBreakStmt(BreakStmtNode $node): string { return 'break;'; }
    public function visitGotoStmt(GotoStmtNode $node): string { return 'goto ' . $node->label . ';'; }

    public function visitTryStmt(TryStmtNode $node): string
    {
        $lines = [];
        $lines[] = 'TP_TRY';
        $this->scopeDepth++;
        foreach ($node->tryBody as $s) {
            $lines[] = '    ' . $s->accept($this);
        }
        $this->scopeDepth--;

        // 多 catch 子句：每个生成 TP_CATCH_EX(var, Type)
        // 最后无类型兜底用 TP_CATCH_ANY（捕获非对象异常如 tp_throw("str")）
        $hasCatch = !empty($node->catchClauses);
        $hasObjCatch = false;
        foreach ($node->catchClauses as $clause) {
            $cv = $clause['var'];
            $ct = $clause['type'];
            $this->declaredVars[$cv] = true;
            // catch 类型为已声明 class 或 Exception → 生成 TP_CATCH_EX；否则按字符串消息兜底
            $resolvedCn = $this->symbols->resolveClass($ct);
            $isClass = $resolvedCn !== null || $ct === 'Exception' || $this->symbols->hasClass('tphp_class_' . $ct);
            if ($isClass) {
                // catch 变量统一用 Exception* 类型（tp_throw_ex 已强转为 Exception*）
                // 子类特有方法暂不支持（PHP 语义中 catch 块内 $e 视为声明的类型，但 AOT 简化为基类）
                $this->varTypes[$cv] = 'tphp_class_Exception*';
                $lines[] = 'TP_CATCH_EX(' . $cv . ', ' . $ct . ')';
                $hasObjCatch = true;
            } else {
                // 未知类型 → 兜底字符串消息
                $this->varTypes[$cv] = 't_string';
                $lines[] = 'TP_CATCH_ANY(' . $cv . ')';
            }
            $this->scopeDepth++;
            foreach ($clause['body'] as $s) {
                $lines[] = '    ' . $s->accept($this);
            }
            $this->scopeDepth--;
        }

        // 有 catch 但全是对象类型：补 ANY 兜底捕获 tp_throw 的字符串异常
        if ($hasCatch && $hasObjCatch && empty($node->finallyBody)) {
            $lines[] = 'TP_CATCH_ANY(_tp_unused_msg) { (void)_tp_unused_msg; }';
        }

        if (!empty($node->finallyBody)) {
            $lines[] = 'TP_FINALLY';
            $this->scopeDepth++;
            foreach ($node->finallyBody as $s) {
                $lines[] = '    ' . $s->accept($this);
            }
            $this->scopeDepth--;
        }
        $lines[] = 'TP_END_TRY';
        return implode("\n", $lines);
    }

    public function visitThrowStmt(ThrowStmtNode $node): string
    {
        $code = $node->expr->accept($this);
        // throw new Exception(...) 或 throw new Exception子类(...) → tp_throw_ex()
        // tp_throw_ex 接收原始对象指针，内部通过 cls->exception_offset 计算 Exception*
        if ($node->expr instanceof NewExpr) {
            return "tp_throw_ex({$code});";
        }
        // throw $exceptionVar (Exception 子类类型) → tp_throw_ex
        $type = $this->inferType($node->expr);
        if (str_starts_with($type, 'tphp_class_') && str_ends_with($type, '*')) {
            return "tp_throw_ex({$code});";
        }
        // throw "string" → tp_throw(msg.data)
        if ($type === 't_string') {
            return "tp_throw({$code}.data);";
        }
        return "tp_throw((char*)(uintptr_t)(" . $code . "));";
    }

    /**
     * 计算类的 exception_offset（Exception 子类专属）
     * @param string $cName 类的 C 名（如 tphp_class_MyException）
     * 返回 offsetof 表达式字符串（如 "offsetof(tphp_class_MyException, _parent)"），
     * 或 "0"（非 Exception 子类）
     */
    private function computeExceptionOffset(string $cName): string
    {
        if ($cName === 'tphp_class_Exception') return '0';
        // 沿继承链查找 Exception，构建 _parent._parent... 链
        $chain = [];
        $curCn = $cName;
        while ($curCn !== null && $curCn !== '') {
            $class = $this->symbols->getClass($curCn);
            if ($class === null) break;
            $parentName = $class->parent;
            if ($parentName === 'tphp_class_Exception') {
                $chain[] = '_parent';
                return 'offsetof(' . $cName . ', ' . implode('.', $chain) . ')';
            }
            if ($parentName === '' || $parentName === null) break;
            $chain[] = '_parent';
            $curCn = $parentName;
        }
        return '0';
    }

    public function visitLabelStmt(LabelStmtNode $node): string { return $node->name . ':;'; }
    public function visitContinueStmt(ContinueStmtNode $node): string { return 'continue;'; }

    // ============================================================
    // 运算符
    // ============================================================

    public function visitPostfix(PostfixExpr $node): string
    {
        $e = $node->expr->accept($this);
        return "{$e}{$node->operator}";
    }

    public function visitCompoundAssign(CompoundAssignExpr $node): string
    {
        $t = $node->target->accept($this);
        $v = $node->value->accept($this);
        return "{$t} {$node->operator} {$v}";
    }

    // ============================================================
    private function generateCEntry(): string
    {
        return implode("\n", [
            "/* ── C entry: main() ─────────────────────────── */",
            "int main(int argc, char* argv[]) {",
            $this->ind("tphp_rt_init();"),
            $this->ind("t_array* _argv = tphp_rt_build_argv(argc, argv);"),
            $this->ind("{$this->className}* _main = new_{$this->className}((t_int)argc, _argv);"),
            $this->ind("if (_main == NULL) { tphp_fn_arr_free(_argv); return 1; }"),
            $this->ind("{$this->className}_main(_main);"),
            $this->ind("tp_obj_release(_main);"),
            $this->ind("tphp_fn_arr_free(_argv);"),
            $this->ind("return 0;"),
            "}",
        ]);
    }

    // ============================================================
    private function methodDecl(MethodNode $m): string
    {
        $ret = self::mapType($m->returnType);
        $params = array_map(fn($p) => $this->visitParam($p), $m->params);
        return "{$ret} {$this->className}_{$m->name}({$this->className}* self" .
            (empty($params) ? '' : ', ' . implode(', ', $params)) . ')';
    }

    private function methodImpl(MethodNode $m): string { return $this->methodDecl($m); }

    /** 将任意表达式转为 t_int（用于 (int) 转换） */
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
                default    => throw new RuntimeException(
                    sprintf("[%d:%d] Object cannot be converted to float", $expr->line, $expr->column)
                ),
            };
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
                default    => 'true', // 对象
            };
        }

        return "((bool)({$code}))";
    }

    /** 将标量/对象转为单元素数组 */
    private function castToArray(ExprNode $expr): string
    {
        if ($expr instanceof NullLiteralExpr) return 'tphp_fn_arr_create(0)';
        return 'tphp_fn_arr_from_val(' . $this->wrapVar($expr) . ')';
    }

    public function visitArrayAccess(ArrayAccessExpr $node): string
    {
        $arr  = $node->array->accept($this);
        $idx  = $node->index->accept($this);
        $vn   = $node->array instanceof VariableExpr ? self::varName($node->array->name) : '';
        $vt   = $this->varTypes[$vn] ?? 't_int';

        // 字符串键：per-key 类型 → get_str_int/str；无记录用 get_str_str
        $idxType = $this->inferType($node->index);
        if ($idxType === 't_string' || $node->index instanceof StringLiteralExpr) {
            // per-key 类型追踪
            $keyType = $vt;
            if ($node->index instanceof StringLiteralExpr && $node->array instanceof VariableExpr) {
                $arrName = self::varName($node->array->name);
                $keyStr  = $node->index->value;
                $keyType = $this->arrValueTypes[$arrName][$keyStr] ?? null;
                // 全局查找（如 $users = $db["users"] 后，$users["alice"] 跨变量查 alice 键类型）
                if ($keyType === null) {
                    foreach ($this->arrValueTypes as $vKeys) {
                        if (isset($vKeys[$keyStr])) { $keyType = $vKeys[$keyStr]; break; }
                    }
                }
                $keyType ??= $vt;
            }
            return match ($keyType) {
                't_int'   => "tphp_fn_arr_get_str_int({$arr}, {$idx})",
                't_float' => "((t_float)tphp_fn_arr_get_str_int({$arr}, {$idx}))",
                't_bool'  => "(tphp_fn_arr_get_str_int({$arr}, {$idx}) != 0)",
                't_array*' => "tphp_fn_arr_get_str_arr({$arr}, {$idx})",
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
                $et = $this->arrNestedTypes[$rootArr];
                // 标准化类/枚举类型（补 * 指针后缀）
                if ((str_contains($et, 'tphp_class_') || str_contains($et, 'tphp_enum_')) && !str_ends_with($et, '*')) {
                    $et .= '*';
                }
            }
        }
        return match ($et) {
            't_int'      => "tphp_fn_arr_item_int({$arr}, (int)({$idx}))",
            't_float'    => "tphp_fn_arr_item_float({$arr}, (int)({$idx}))",
            't_string'   => "tphp_fn_arr_item_str({$arr}, (int)({$idx}))",
            't_bool'     => "tphp_fn_arr_item_bool({$arr}, (int)({$idx}))",
            't_array*'   => "tphp_fn_arr_item_array({$arr}, (int)({$idx}))",
            't_callback' => "tphp_fn_arr_item_callback({$arr}, (int)({$idx}))",
            default      => (str_contains($et, 'tphp_class_') || str_contains($et, 'tphp_enum_'))
                ? "((" . $et . ")tphp_fn_arr_item_object({$arr}, (int)({$idx})))"
                : "tphp_fn_arr_item_int({$arr}, (int)({$idx}))",
        };
    }

    /** 展平 . 链为叶子节点数组，用于 ROPE 多片段拼接
     *  "a" . "b" . "c" → [StringLit("a"), StringLit("b"), StringLit("c")] */
    private function flattenConcat(BinaryExpr $node): array
    {
        $parts = [];
        $this->flattenConcatRec($node, $parts);
        return $parts;
    }

    private function flattenConcatRec(ExprNode $node, array &$parts): void
    {
        if ($node instanceof BinaryExpr && $node->operator === '.') {
            $this->flattenConcatRec($node->left, $parts);
            $this->flattenConcatRec($node->right, $parts);
        } else {
            $parts[] = $node;
        }
    }

    /** 将任意表达式转为 t_string（用于 (string) 转换和 . 拼接）
     *  @param bool $strict true=显式转换时数组/对象报错，false=.拼接时静默转 "Array"/"Object" */
    private function castToStr(ExprNode $expr, bool $strict = false): string
    {
        if ($expr instanceof StringLiteralExpr) return $expr->accept($this);
        if ($expr instanceof ArrayAccessExpr) {
            $code = $expr->accept($this);
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
                't_string' => $code,
                't_float'  => "tphp_rt_str_from_float({$code})",
                default    => "tphp_rt_str_from_int({$code})",
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
        }

        // CallExpr：查找返回类型（内置函数 + 方法调用）
        if ($expr instanceof CallExpr) {
            // 内置函数返回 t_string（date 等）
            if ($expr->callee === null) {
                $rt = $this->inferCallReturnType($expr);
                if ($rt === 't_string') return $code;
                if ($rt === 't_float') return "tphp_rt_str_from_float({$code})";
            }
            // 方法调用
            if ($expr->callee !== null) {
                $objKey = ($expr->callee instanceof VariableExpr) ? self::varName($expr->callee->name) : '';
                $objType = ($objKey === '$this' || $objKey === 'self')
                    ? $this->className
                    : ($this->varTypes[$objKey] ?? '');
                $objClean = rtrim($objType, '*'); // COS objects always have *
                if ($objClean !== '' && isset($this->classMethodRetTypes[$objClean])) {
                    $retType = $this->classMethodRetTypes[$objClean][$expr->name] ?? '';
                    if ($retType === 't_string') return $code;
                    if ($retType === 't_float') return "tphp_rt_str_from_float({$code})";
                }
            }
        }

        // EnumAccessExpr → 用 ->value 取值后转字符串
        if ($expr instanceof EnumAccessExpr) {
            $bt = $this->enumBackingType($expr->enumName);
            return ($bt === 'string') ? "({$code})->value" : "tphp_rt_str_from_int(({$code})->value)";
        }

        // MatchExpr → 查 inferType 决定如何转字符串
        if ($expr instanceof MatchExpr) {
            $bt = $this->inferType($expr);
            return ($bt === 't_string') ? $code : "tphp_rt_str_from_int({$code})";
        }

        // ArrayAccessExpr：字符串键读取返回 t_string
        if ($expr instanceof ArrayAccessExpr) {
            $idxType = $this->inferType($expr->index);
            if ($idxType === 't_string' || $expr->index instanceof StringLiteralExpr) {
                return $code;  // tphp_fn_arr_get_str_str 已返回 t_string
            }
        }

        // TPHP_CONST_ 常量引用 → #define 可能展开为 STR_LIT，直接返回
        if (str_starts_with($code, 'TPHP_CONST_')) {
            return $code;
        }

        // 其他表达式（CallExpr 等）默认假设返回 int
        return "tphp_rt_str_from_int({$code})";
    }

    /** 将表达式值包装为 VAR_XXX 宏（用于 mixed/union 变量赋值） */
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

    /** 读取 t_var 变量的值，按预期类型提取 */
    private function readVar(string $var, string $expectType): string
    {
        return match ($expectType) {
            't_int'    => "VAR_AS_INT({$var})",
            't_float'  => "VAR_AS_FLOAT({$var})",
            't_string' => "VAR_AS_STRING({$var})",
            't_bool'   => "VAR_AS_BOOL({$var})",
            default    => $var, // fallback: raw t_var
        };
    }

    /** 如果表达式是 t_var 变量，按期望类型解包 */
    private function unwrapIfMixed(ExprNode $expr, string $code, string $expectType): string
    {
        if ($expr instanceof VariableExpr) {
            $vn = self::varName($expr->name);
            if (($this->varTypes[$vn] ?? '') === 't_var') {
                return $this->readVar($vn, $expectType);
            }
        }
        return $code;
    }

    /** 查询方法第 $idx 个参数的 C 类型 */
    private function getMethodParamType(CallExpr $call, int $idx): string
    {
        if ($call->callee === null) return '';
        // 查找方法所属类
        $cn = '';
        if ($call->callee instanceof VariableExpr) {
            $key = self::varName($call->callee->name);
            $raw = $this->varTypes[$key] ?? '';
            if (str_starts_with($raw, 'tphp_class_')) $cn = rtrim($raw, '*');
        } elseif ($call->callee instanceof CallExpr) {
            // 链式调用递归
            return '';
        }
        if ($cn === '' && $call->callee instanceof VariableExpr && self::varName($call->callee->name) === 'self') {
            $cn = $this->className;
        }
        if ($cn !== '' && isset($this->methodParamTypes[$cn][$call->name])) {
            $params = $this->methodParamTypes[$cn][$call->name];
            return $params[$idx] ?? '';
        }
        return '';
    }

    public function mapType(string $t): string {
        if ($t === 'self') return $this->className . '*';
        if ($t === 'mixed') return 't_var';
        if ($t === 'callable') return 't_callback';
        // 联合类型 → t_var
        if (str_contains($t, '|')) return 't_var';
        // C 类型: C.IDENTIFIER — 借鉴 vlang 的 C 命名空间设计
        //   C.int → int, C.float → double, C.double → double, C.char → char, C.void → void
        //   C.void_ptr → void*, C.char_ptr → char*, C.int_ptr → int*, C.float_ptr → double*
        //   C.XXX → XXX*（默认：结构体指针，如 C.FILE → FILE*）
        if (str_starts_with($t, 'C.')) {
            $ct = substr($t, 2);
            return match ($ct) {
                'int', 'int32', 'int64', 'uint32', 'uint64' => $ct === 'int' ? 'int' : $ct . '_t',
                'float', 'double' => 'double',
                'char' => 'char',
                'void' => 'void',
                'void_ptr' => 'void*',
                'char_ptr' => 'char*',
                'int_ptr' => 'int*',
                'float_ptr' => 'double*',
                'bool' => 'bool',
                default => $ct . '*',  // 结构体指针: C.FILE → FILE*
            };
        }
        // 枚举类型 → 返回 C struct 指针类型
        if (isset($this->enumCTypes[$t])) {
            return $this->enumCTypes[$t];
        }
        // 用户定义的类名 → tphp_class_XXX*
        if (isset($this->classNames[$t])) {
            return $this->classNames[$t] . '*';
        }
        return self::$typeMap[$t] ?? "{$t}*";
    }
    public static function varName(string $v): string { return $v === '$this' ? 'self' : ltrim($v, '$'); }

    /** 解析类型到 C 类型（处理联合类型 | → t_var） */
    private static function resolveType(string $type): string {
        if (str_contains($type, '|')) return 't_var';
        if ($type === 'callable') return 't_callback';
        // C 类型: C.IDENTIFIER — 直接映射为对应 C 类型
        if (str_starts_with($type, 'C.')) {
            $ct = substr($type, 2);
            return match ($ct) {
                'int' => 'int',
                'int32' => 'int32_t', 'int64' => 'int64_t',
                'uint32' => 'uint32_t', 'uint64' => 'uint64_t',
                'float', 'double' => 'double',
                'char' => 'char',
                'void' => 'void',
                'void_ptr' => 'void*',
                'char_ptr' => 'char*',
                'int_ptr' => 'int*',
                'float_ptr' => 'double*',
                'bool' => 'bool',
                default => $ct . '*',  // 结构体指针
            };
        }
        return self::$typeMap[$type] ?? ('tphp_class_' . $type . '*');
    }

    /** 生成参数声明的 C 类型 + 变量名（byRef → 加一级指针：int→int*, t_array*→t_array**） */
    public static function paramDecl(ParamNode $p): string {
        $ct = self::resolveType($p->type);
        return $p->byRef ? "{$ct} *" . self::varName($p->name) : "{$ct} " . self::varName($p->name);
    }

    /** 参数在 varTypes 中的 C 类型（byRef → 加一级指针：int→int*, t_array*→t_array**） */
    public static function paramCType(ParamNode $p): string {
        $ct = self::resolveType($p->type);
        return $p->byRef ? "{$ct}*" : $ct;
    }

    /** 如果变量是 byRef 类型，生成写目标（*var） */
    private function varWrite(string $var, string $type): string {
        if ($this->isByRefType($type)) return "(*{$var})";
        return $var;
    }

    // 是否 byRef 指针类型（int* / t_string* / t_array** / tphp_class_X** 等）
    private function isByRefType(string $type): bool {
        if ($type === 'void*') return false;
        // C 类型指针（Point*, char*, FILE* 等）不是 byRef，直接传递
        // 只有 TinyPHP 内部值类型的指针才是 byRef
        if (!str_starts_with($type, 't_') && !str_starts_with($type, 'tphp_')) return false;
        // 值类型的指针：t_int*, t_float*, t_string*, t_bool* → byRef
        // 指针类型的双指针：t_array**, tphp_class_X**, tphp_enum_X** → byRef
        if (str_ends_with($type, '**')) return true;
        if (str_starts_with($type, 't_array') && str_ends_with($type, '*')) return false;
        if (str_starts_with($type, 'tphp_class_') && str_ends_with($type, '*')) return false;
        if (str_starts_with($type, 'tphp_enum_') && str_ends_with($type, '*')) return false;
        return str_ends_with($type, '*');
    }

    /** 预扫描递归收集闭包的 capDefs（不生成代码，只注册类型） */
    private function collectCapDefs(StmtNode $stmt): void
    {
        if ($stmt instanceof IfStmtNode) {
            foreach ($stmt->thenBody as $s) $this->collectCapDefs($s);
            foreach ($stmt->elseifs as $eif) {
                foreach ($eif->body as $s) $this->collectCapDefs($s);
            }
            foreach ($stmt->elseBody as $s) $this->collectCapDefs($s);
        } elseif ($stmt instanceof WhileStmtNode || $stmt instanceof ForStmtNode || $stmt instanceof ForeachStmtNode) {
            foreach ($stmt->body as $s) $this->collectCapDefs($s);
        } elseif ($stmt instanceof DoWhileStmtNode) {
            foreach ($stmt->body as $s) $this->collectCapDefs($s);
        } elseif ($stmt instanceof SwitchStmtNode) {
            foreach ($stmt->cases as $c) {
                foreach ($c->body as $s) $this->collectCapDefs($s);
            }
        } elseif ($stmt instanceof ExprStmtNode || $stmt instanceof AssignStmtNode || $stmt instanceof EchoStmtNode) {
            $this->collectCapDefsExpr($stmt);
        }
    }

    private function collectCapDefsExpr(StmtNode $stmt): void
    {
        $expr = null;
        if ($stmt instanceof ExprStmtNode) $expr = $stmt->expr;
        elseif ($stmt instanceof AssignStmtNode) $expr = $stmt->expr;
        elseif ($stmt instanceof EchoStmtNode && !empty($stmt->exprs)) $expr = $stmt->exprs[0];

        if ($expr instanceof ClosureExpr && !empty($expr->useVars)) {
            $id = ++$this->capTypeCounter;
            $capFields = [];
            foreach ($expr->useVars as [$vn, $_]) {
                $ct = $this->varTypes[$vn] ?? 't_int';
                $ct = ($ct === 'null') ? 'void*' : $ct;
                $capFields[] = "    {$ct} {$vn};";
            }
            $this->sectionBlock(self::SEC_CAPTYPES,
                "typedef struct {\n" . implode("\n", $capFields) . "\n} _cap_{$id};");
        }
    }

    /** 查询枚举名对应的 backing 类型 ('int'|'string') */
    private function enumBackingType(string $name): string {
        return $this->enumBackingTypes[$name] ?? 'int';
    }

    /** 将 PHP 命名空间名转为 C 标识符: Demo\Foo → Demo_Foo */
    public static function mangleCName(string $name): string {
        return str_replace('\\', '_', $name);
    }

    /** 从类节点获取 C 标识符
     *  全局类: tphp_class_ClassName
     *  命名空间类: tphp_na_Namespace_tphp_class_ClassName */
    private static function classCName(ClassNode $class): string {
        if ($class->namespace === '') {
            return 'tphp_class_' . $class->name;
        }
        return 'tphp_na_' . self::mangleCName($class->namespace) . '_tphp_class_' . $class->name;
    }

    /** 从已解析类名生成 C 引用名（visitNew/Call 等非 ClassNode 上下文中使用） */
    /** Resolve which class owns a method (for COS inheritance) */
    private function resolveMethodClass(string $cn, string $method): string
    {
        $cur = $cn;
        while (isset($this->classParentName[$cur]) && $this->classParentName[$cur] !== '') {
            $cur = $this->classParentName[$cur];
            if (isset($this->classMethodRetTypes[$cur][$method])) return $cur;
        }
        return '';
    }

    /** Resolve property prefix for COS inheritance: _parent._parent. */
    private function resolvePropPrefix(string $cn, string $prop): string
    {
        $prefix = '';
        $cur = $cn;
        while (isset($this->classParentName[$cur]) && $this->classParentName[$cur] !== '') {
            $cur = $this->classParentName[$cur];
            if (isset($this->classOwnProps[$cur][$prop])) {
                return $prefix;
            }
            $prefix .= '_parent.';
        }
        return $prefix; // fallback: try outermost parent
    }

    /** 从已解析类名生成 C 引用名
     *  全局类: tphp_class_ClassName
     *  命名空间类: tphp_na_Namespace_tphp_class_ClassName */
    private static function classRefName(string $resolvedName): string {
        $pos = strrpos($resolvedName, '\\');
        if ($pos === false) {
            return 'tphp_class_' . $resolvedName;
        }
        $ns = substr($resolvedName, 0, $pos);
        $cls = substr($resolvedName, $pos + 1);
        return 'tphp_na_' . self::mangleCName($ns) . '_tphp_class_' . $cls;
    }

    /** 从函数节点获取 C 标识符
     *  全局函数: tphp_fn_functionName
     *  命名空间函数: tphp_na_Namespace_tphp_fn_functionName */
    private static function funcCName(FunctionNode $fn): string {
        if ($fn->namespace === '') {
            return 'tphp_fn_' . $fn->name;
        }
        return 'tphp_na_' . self::mangleCName($fn->namespace) . '_tphp_fn_' . $fn->name;
    }

    /** 从 CallExpr 推导 C 函数名（与 funcCName 格式一致）
     *  全局函数: tphp_fn_functionName
     *  命名空间函数: tphp_na_Namespace_tphp_fn_functionName */
    private static function funcCNameFromCall(CallExpr $expr): string {
        if ($expr->callee !== null) return '';  // 方法调用不在此
        // $expr->name 是 FQ 名（如 "Phpc\map_with_closure"）
        $pos = strrpos($expr->name, '\\');
        if ($pos !== false) {
            $ns = substr($expr->name, 0, $pos);
            $fn = substr($expr->name, $pos + 1);
            return 'tphp_na_' . self::mangleCName($ns) . '_tphp_fn_' . $fn;
        }
        return 'tphp_fn_' . $expr->name;
    }

    private function indentStr(): string { return str_repeat('    ', $this->indent); }
    private function ind(string $l): string { return $this->indentStr() . $l; }
}
