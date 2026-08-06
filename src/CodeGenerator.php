<?php

declare(strict_types=1);

require_once __DIR__ . '/CodeGenDataTrait.php';

class CodeGenerator implements ASTVisitor
{
    use CodeGenDataTrait;
    use CodeGenTypeCastTrait;
    use CodeGenVarWrapTrait;
    use CodeGenArrayTrait;
    use CodeGenEnumTrait;
    use CodeGenCallTrait;
    use CodeGenTypes;
    use CodeGenEnumTrait;
    use CodeGenCallTrait;
    private string $className = '';
    /** 当前类的 PHP 名（带命名空间，用于 __CLASS__/__METHOD__ 输出原始类名） */
    private string $phpClassName = '';
    private int $indent = 0;
    private int $scopeDepth = 0; // 嵌套块深度（for/while/if/foreach 体内为 1+）
    private string $phpFile = '';

    /** 变量类型追踪：varName → className（对象）或 C 类型（基础类型） */
    private array $varTypes = [];
    /** 变量可空性追踪：varName → true 表示可能为 null（来自 nullsafe ?-> 或 tryFrom()） */
    private array $varNullable = [];
    /** 类型窄化追踪：varName → tphp_class_X（来自 if ($v instanceof X) 分支内）
     *  用于 t_var 变量在 instanceof 分支内访问属性时生成正确的 C 类型转换 */
    private array $narrowedTypes = [];
    /** 当前方法名字（用于 __METHOD__） */
    private string $currentMethodName = '';
    /** P2-6: 当前 PHP 函数名（全局函数用，方法用 currentMethodName） */
    private string $currentFuncName = '';
    /** P2-6: 当前是否在类方法内（区分 __FUNCTION__/__METHOD__ 语义） */
    private bool $inMethod = false;
    /** P2-6: 当前 PHP 命名空间名（用于 __NAMESPACE__） */
    private string $currentNamespace = '';
    /** 数组元素类型追踪：varName → C 类型（int key 的默认类型） */
    private array $arrElementTypes = [];
    /** 实例属性数组元素类型追踪："cn::prop" → CType
     *  用于 $this->prop[$key] 和 $obj->prop[$key] 的元素类型推断
     *  （arrElementTypes 仅追踪局部变量，不覆盖属性访问） */
    private array $propArrElementTypes = [];
    /** 数组 per-key 类型追踪：arrVarName → [strKey → CType]（字符串键专用） */
    private array $arrValueTypes = [];
    /** 嵌套数组元素类型追踪：arrVarName → CType（当数组元素是数组时，记录子数组的元素类型） */
    private array $arrNestedTypes = [];
    /** 多层嵌套数组深度追踪：arrVarName → ['depth' => N, 'leafType' => CType]
     *  用于正确推断 $arr[0][1][2] 等深层访问的元素类型 */
    private array $arrNestedDepth = [];
    /** 数组字面量 AST 追踪：arrVarName → ArrayLiteralExpr
     *  用于精确追踪嵌套访问 $m["items"][0]["id"] 中特定键的值类型
     *  （当叶子层为混合类型关联数组时，inferArrayElementType 只能返回单一类型，无法区分 "id"=>int 与 "name"=>string） */
    private array $arrLiteralAST = [];
    /** 函数返回数组的 per-key 类型追踪：fnCName → [strKey → CType]
     *  当函数 return ["key" => $val, ...] 时记录，供调用者 $var = func() 后 $var["key"] 类型推断 */
    private array $fnReturnArrKeyTypes = [];
    /** 当前函数/方法的 C 名（用于 fnReturnArrKeyTypes 注册） */
    private string $currentFuncCName = '';
    /** 当前函数/方法的可变参数名（不含 $），空字符串表示当前不在可变参数函数内 */
    private string $currentVariadicParamName = '';
    /** 当前可变参数的元素 PHP 类型（如 'int'/'string'/'float'/''=mixed），用于 func_get_arg 类型化访问 */
    private string $currentVariadicElementType = '';
    /** 已声明变量集合 */
    private array $declaredVars = [];
    /** for 循环提升到函数作用域的变量声明：varName => cType */
    private array $funcScopeDecls = [];
    /** defer 栈：当前函数内已注册的 defer 清理代码（LIFO 执行） */
    private array $deferStack = [];
    /** C 指针所有权追踪：varName => ['type' => cType, 'cleaned' => bool, 'line' => int]
     *  用于编译期泄漏提醒：函数末尾扫描未清理的 transfer 指针 */
    private array $cPtrOwnership = [];
    /** 函数内 const 常量名集合：name => true（用于 visitVariable 区分局部 const 与全局 const） */
    private array $localConsts = [];
    /** 当前 __construct 内已赋值的 readonly 属性集合: "className::propName" => true */
    private array $assignedReadonlyProps = [];

    // ── 统一符号表 ──────────────────────────────────────────
    // 替代了 13 个散落的类型追踪数组
    private SymbolTable $symbols;

    private bool $inGenerator = false;     // 当前是否在生成器入口函数体内

    /** Property Hook 追踪：className → [propName → ['get' => bool, 'set' => bool]] */
    private array $hookedProps = [];
    /** 当前是否在 hook 体内（hook 体内 $this->prop 直接访问 backing field） */
    private bool $inHookBody = false;

    /** ProgramNode 引用（visitConst 扫描注解使用） */
    private ?ProgramNode $program = null;
    /** 注解常量注册表：shortName/FQName → [
     *      'fqName' => string, 'shortName' => string,
     *      'constName' => 'TPHP_CONST_XXX', 'initFn' => '_annot_XXX_init',
     *      'entryVarPrefix' => '_annot_XXX_',
     *      'entries' => [ ['kind'=>'method'|'static_method'|'class'|'function',
     *                       'class'=>string,'method'=>string,'function'=>string,
     *                       'namespace'=>string,'name'=>string,'args'=>ExprNode[]], ... ]
     *  ] */
    private array $annotationRegistry = [];
    /** 注解初始化函数列表（generateCEntry 中调用） */
    private array $annotationInitFns = [];
    /** 已导入的 PDO 驱动 C init 函数列表（在 main() 入口自动调用，类似 PHP MINIT） */
    private array $pdoDriverInits = [];
    /** 变量 → 注解常量名追踪（foreach 遍历注解数组时，记录 $v 来自哪个注解常量） */
    private array $varAnnotSource = [];
    /** resolveMethodClass 缓存: "cn\0method" → resolvedClassName */
    private array $methodClassCache = [];
    /** inferType 缓存: key → CType string */
    private array $inferTypeCache = [];

    /** 循环/switch end label 栈（支持 break N; / continue N;） */
    private array $loopEndLabelStack = [];
    /** 循环 start label 栈（支持 continue N;） */
    private array $loopStartLabelStack = [];
    /** 循环 continue label 栈（continue N; 跳到第 N 层外层的 step 前） */
    private array $loopContLabelStack = [];

    /** 是否 -shared 共享库模式（生成导出 trampoline + 库自动初始化） */
    public bool $isShared = false;

    /** 目标 OS（null = 宿主平台，'android' 时生成 tphp_android_main 而非 main） */
    public ?string $targetOS = null;

    /** 字面量 → C 类型的映射 */
    private static array $litTypeMap = [
        IntLiteralExpr::class    => 't_int',
        FloatLiteralExpr::class  => 't_float',
        StringLiteralExpr::class => 't_string',
        BoolLiteralExpr::class   => 't_bool',
        MagicConstExpr::class    => 't_string',
    ];

    /**
     * 内置 Exception 子类列表（与 TypeChecker::registerBuiltinClasses 保持一致）
     *   这些类无额外字段，继承 Exception 的 message；CodeGenerator 自动生成 C 结构体和分配器
     *   用途：throw new RuntimeException(...) / match 未匹配抛 UnhandledMatchError
     */
    public const BUILTIN_EXCEPTION_SUBCLASSES = [
        'RuntimeException', 'LogicException', 'InvalidArgumentException',
        'TypeError', 'ValueError', 'Error', 'UnhandledMatchError',
        'OutOfRangeException', 'RangeException', 'DomainException',
        'LengthException', 'OverflowException', 'UnderflowException',
        'UnexpectedValueException',
    ];

    private static array $typeMap = [
        'int' => 't_int', 'float' => 't_float', 'string' => 't_string',
        'bool' => 't_bool', 'void' => 'void', 'never' => 'void', 'array' => 't_array*',
        'mixed' => 't_var', 'null' => 'void*',
        'Generator' => 'tphp_class_Generator*',
        'Channel' => 'tphp_class_Channel*',
        'Future'  => 'tphp_class_Future*',
    ];

    /** 内置函数返回类型注册表（替代 inferCallReturnType 中的 140+ if-else） */
    private static array $builtinArrElemTypes = [
        'array_keys' => 't_int', 'array_values' => 't_int', 'array_merge' => 't_int',
        'explode' => 't_string', 'preg_match' => 't_string', 'preg_split' => 't_string',
        'preg_grep' => 't_string', 'filter_list' => 't_string',
        'gzfile' => 't_string',
        'stream_socket_pair' => 't_int',
        // ── 返回 array<mixed>（元素统一为 t_var）的函数 ──
        //   array_fill: value 通过 wraparr 包装为 VAR_* 存入数组，元素统一 t_var
        //   array_column: 列值通过 tphp_fn_arr_push(out, val) 存入，元素统一 t_var
        //   不注册则默认 t_int，访问字符串/浮点值会调用 typed getter 返回 0
        'array_fill' => 't_var',
        'array_column' => 't_var',
        // array_count_values: 值是出现次数（int），键是原值（int 或 string）
        'array_count_values' => 't_int',
        // ── 字符串数组函数（元素统一为 VAR_STRING 存储）──
        //   str_split/parse_str/parse_url/iconv_get_encoding 内部用 VAR_STRING 包装
        //   存入数组，访问元素应走 t_string 路径（tphp_fn_arr_get_int_str 会检查
        //   v->type == TYPE_STRING 并返回 v->value._string）。
        //   未注册则默认 t_int，var_dump($arr[0]) 输出 int(0) 而非 string(N) "..."
        'str_split' => 't_string',
        'parse_str' => 't_string',
        'parse_url' => 't_string',
        'iconv_get_encoding' => 't_string',
        // ── PDO 方法返回数组的元素类型（方法 C 名作为键）──
        //   fetch() 返回 array<string>（所有列值统一转为字符串）
        'tphp_class_PDOStatement_fetch' => 't_string',
        //   fetchAll() 返回 array<array<string>>（外层元素是数组，内层元素是字符串）
        'tphp_class_PDOStatement_fetchAll' => 't_array*',
        'tphp_class_PDOStatement_fetchAll[]' => 't_string',
        //   errorInfo() 返回 array<string|int>（混合类型，默认按 int 访问）
        'tphp_class_PDO_errorInfo' => 't_string',
        'tphp_class_PDOStatement_errorInfo' => 't_string',
        'tphp_class_PDOStatement_getColumnMeta' => 't_string',
        //   getAvailableDrivers() 返回 array<string>（驱动名列表）
        'tphp_class_PDO_getAvailableDrivers' => 't_string',
        // ── sqlite3 函数返回数组的元素类型 ──
        //   sqlite_query() 返回 array<array<string>>（外层是行数组，内层是列值字符串）
        'sqlite_query' => 't_array*',
        'sqlite_query[]' => 't_string',
        //   sqlite_query_single() 返回 array<string>（单行，元素是列值字符串）
        'sqlite_query_single' => 't_string',
        // ── zip 函数返回数组的元素类型 ──
        //   zip_stat() 返回 array<mixed>（name=string, size/comp_method=int, 全部以 t_var 存储）
        'zip_stat' => 't_var',
        //   zip_read() 返回 array<array<mixed>>（外层是 t_var 持有 VAR_ARRAY，内层同 zip_stat）
        'zip_read' => 't_var',
        // ── pgsql 函数返回数组的元素类型 ──
        //   pg_fetch_row/assoc/array() 返回 array<string>（单行列值，C 层以 VAR_STRING 存储）
        'pg_fetch_row'    => 't_string',
        'pg_fetch_assoc'  => 't_string',
        'pg_fetch_array'  => 't_string',
        'pg_fetch_all_columns' => 't_string',
        //   pg_fetch_all() 返回 array<array<string>>（外层是行数组，内层是列值字符串）
        'pg_fetch_all'    => 't_array*',
        'pg_fetch_all[]'  => 't_string',
        //   pg_version() 返回 array<string>（client/protocol/server 版本字符串）
        'pg_version'      => 't_string',
        //   pg_copy_to() 返回 array<string>（每行一个字符串）
        'pg_copy_to'      => 't_string',
        //   pg_meta_data() 返回 array<array<mixed>>（外层是字段数组，内层是字段属性）
        'pg_meta_data'    => 't_array*',
        'pg_meta_data[]'  => 't_var',
        //   pg_convert() 返回 array<mixed>（转换后的字段值，int/string 混合）
        'pg_convert'      => 't_var',
        //   pg_select() 返回 array<array<mixed>>（外层是行数组，内层是字段值）
        'pg_select'       => 't_array*',
        'pg_select[]'     => 't_var',
        // ── pdo_pgsql 函数返回数组的元素类型 ──
        //   pdo_pgsql_get_notify() 返回 array<int|string>（pid/channel/message，混合类型）
        'pdo_pgsql_get_notify' => 't_var',
    ];

    /**
     * 简单转发函数映射表（visitCall 第二步拆分）
     *
     * 每个条目字段：
     *  - cName:       C 函数名（必填）
     *  - modes:       按参数位置的 argMode 数组（缺省='direct'）；不设置=变长全 direct
     *  - defaults:    缺省参数默认值 [位置 => C 字面量]
     *  - order:       输出参数重排顺序（如 [1,0] 表示先 arg1 再 arg0）
     *  - cNameNoArgs: 0 参时的 C 函数名（如 uniqid → uniqid0）
     *
     * argMode 取值：direct | data | floatcast | wrapvar | wraparr
     */
    private static array $builtinFnParamCtypes = [
        // string → int
        'strlen'    => ['t_string'],
        'strpos'    => ['t_string', 't_string'],
        'strrpos'   => ['t_string', 't_string'],
        'ord'       => ['t_string'],
        'str_word_count' => ['t_string'],
        // string → string
        'trim'      => ['t_string'],
        'ltrim'     => ['t_string'],
        'rtrim'     => ['t_string'],
        'strtolower'=> ['t_string'],
        'strtoupper'=> ['t_string'],
        'ucfirst'   => ['t_string'],
        'lcfirst'   => ['t_string'],
        'strrev'    => ['t_string'],
        'substr'    => ['t_string', 't_int', 't_int'],
        // int → int
        'abs'       => ['t_int'],
        // int → float / float → float
        'sqrt'      => ['t_float'],
        'floor'     => ['t_float'],
        'ceil'      => ['t_float'],
        'round'     => ['t_float'],
        // 文件路径（内置）
        'dirname'   => ['t_string', 't_int'],
        'basename'  => ['t_string', 't_string'],
        // pcntl 扩展（C 函数，参数类型需显式注册以支持 byRef 和 t_var 解包）
        'pcntl_fork'           => [],
        'pcntl_wait'           => ['t_int*'],
        'pcntl_waitpid'        => ['t_int', 't_int*', 't_int'],
        'pcntl_exec'           => ['t_string'],
        'pcntl_alarm'          => ['t_int'],
        'pcntl_get_last_error' => [],
        'pcntl_strerror'       => ['t_int'],
        // posix 扩展（C 函数，参数类型需显式注册以支持 t_var 解包）
        'posix_getpid'         => [],
        'posix_getppid'        => [],
        'posix_getuid'         => [],
        'posix_geteuid'        => [],
        'posix_getgid'         => [],
        'posix_getegid'        => [],
        'posix_isatty'         => ['t_int'],
        'posix_kill'           => ['t_int', 't_int'],
        'posix_get_last_error' => [],
        'posix_getcwd'         => [],
        'posix_strerror'       => ['t_int'],
        'posix_ttyname'        => ['t_int'],
    ];

    /**
     * 内置函数参数名映射表（PHP 官方参数名）
     *
     * 用于命名参数解析：str_replace(search: 'a', replace: 'b', subject: $s)
     *   → str_replace('a', 'b', $s)
     *
     * 仅包含最常用的内置函数；未列入的内置函数不支持命名参数（用户需用位置参数）。
     * 用户函数和方法签名始终支持命名参数。
     */
    private static array $builtinFnParamNames = [
        // 字符串函数
        'substr'         => ['string', 'offset', 'length'],
        'strpos'         => ['haystack', 'needle', 'offset'],
        'strrpos'        => ['haystack', 'needle', 'offset'],
        'str_contains'   => ['haystack', 'needle'],
        'str_starts_with'=> ['haystack', 'needle'],
        'str_ends_with'  => ['haystack', 'needle'],
        'str_replace'    => ['search', 'replace', 'subject'],
        'str_repeat'     => ['string', 'times'],
        'str_pad'        => ['string', 'length', 'pad_string', 'pad_type'],
        'str_split'      => ['string', 'length'],
        'strrev'         => ['string'],
        'strtolower'     => ['string'],
        'strtoupper'     => ['string'],
        'ucfirst'        => ['string'],
        'lcfirst'        => ['string'],
        'trim'           => ['string', 'characters'],
        'ltrim'          => ['string', 'characters'],
        'rtrim'          => ['string', 'characters'],
        'strlen'         => ['string'],
        'explode'        => ['separator', 'string', 'limit'],
        'implode'        => ['separator', 'array'],
        'join'           => ['separator', 'array'],
        'sprintf'        => ['format'],
        'vsprintf'       => ['format', 'args'],
        'printf'         => ['format'],
        'htmlspecialchars' => ['string', 'flags', 'encoding', 'double_encode'],
        'htmlentities'   => ['string', 'flags', 'encoding', 'double_encode'],
        'html_entity_decode' => ['string', 'flags', 'encoding'],
        'number_format'  => ['num', 'decimals', 'decimal_separator', 'thousands_separator'],
        'wordwrap'       => ['string', 'width', 'break', 'cut_long_words'],
        'nl2br'          => ['string', 'use_xhtml'],
        'addslashes'     => ['string'],
        'stripslashes'   => ['string'],
        'urlencode'      => ['string'],
        'urldecode'      => ['string'],
        'rawurlencode'   => ['string'],
        'rawurldecode'   => ['string'],
        'base64_encode'  => ['string'],
        'base64_decode'  => ['string', 'strict'],
        'md5'            => ['string'],
        'sha1'           => ['string'],
        'crc32'          => ['string'],
        'levenshtein'    => ['string1', 'string2'],
        'similar_text'   => ['string1', 'string2'],
        'soundex'        => ['string'],
        'metaphone'      => ['string'],
        'chunk_split'    => ['string', 'length', 'separator'],
        'wordwrap'       => ['string', 'width', 'break', 'cut_long_words'],
        // 数学
        'abs'            => ['num'],
        'ceil'           => ['num'],
        'floor'          => ['num'],
        'round'          => ['num', 'precision', 'mode'],
        'sqrt'           => ['num'],
        'pow'            => ['base', 'exp'],
        'log'            => ['num', 'base'],
        'log10'          => ['num'],
        'log2'           => ['num'],
        'exp'            => ['num'],
        'intdiv'         => ['num1', 'num2'],
        'fmod'           => ['num1', 'num2'],
        'max'            => ['value1'],
        'min'            => ['value1'],
        'rand'           => ['min', 'max'],
        'mt_rand'        => ['min', 'max'],
        'random_int'     => ['min', 'max'],
        'base_convert'   => ['num', 'from_base', 'to_base'],
        'bindec'         => ['binary_string'],
        'octdec'         => ['octal_string'],
        'hexdec'         => ['hex_string'],
        'decbin'         => ['num'],
        'decoct'         => ['num'],
        'dechex'         => ['num'],
        'deg2rad'        => ['num'],
        'rad2deg'        => ['num'],
        'pi'             => [],
        'fmod'           => ['num1', 'num2'],
        // 数组
        'count'              => ['array', 'mode'],
        'array_keys'         => ['array', 'search_value', 'strict'],
        'array_values'       => ['array'],
        'array_key_exists'   => ['key', 'array'],
        'array_search'       => ['needle', 'array', 'strict'],
        'in_array'           => ['needle', 'array', 'strict'],
        'array_push'         => ['array'],
        'array_pop'          => ['array'],
        'array_shift'        => ['array'],
        'array_unshift'      => ['array'],
        'array_slice'        => ['array', 'offset', 'length', 'preserve_keys'],
        'array_splice'       => ['array', 'offset', 'length', 'replacement'],
        'array_merge'        => ['array1'],
        'array_combine'      => ['keys', 'values'],
        'array_flip'         => ['array'],
        'array_reverse'      => ['array', 'preserve_keys'],
        'array_unique'       => ['array', 'flags'],
        'array_filter'       => ['array', 'callback'],
        'array_map'          => ['callback', 'array1'],
        'array_reduce'       => ['array', 'callback', 'initial'],
        'array_fill'         => ['start_index', 'count', 'value'],
        'array_fill_keys'    => ['keys', 'value'],
        'array_pad'          => ['array', 'size', 'value'],
        'array_chunk'        => ['array', 'size', 'preserve_keys'],
        'array_count_values' => ['array'],
        'array_sum'          => ['array'],
        'array_product'      => ['array'],
        'array_column'       => ['array', 'column_key', 'index_key'],
        'range'              => ['start', 'end', 'step'],
        'sort'               => ['array', 'flags'],
        'rsort'              => ['array', 'flags'],
        'asort'              => ['array', 'flags'],
        'arsort'             => ['array', 'flags'],
        'ksort'              => ['array', 'flags'],
        'krsort'             => ['array', 'flags'],
        'usort'              => ['array', 'callback'],
        'uasort'             => ['array', 'callback'],
        'uksort'             => ['array', 'callback'],
        'shuffle'            => ['array'],
        // 类型转换
        'intval'         => ['value', 'base'],
        'floatval'       => ['value'],
        'doubleval'      => ['value'],
        'strval'         => ['value'],
        'boolval'        => ['value'],
        'settype'        => ['var', 'type'],
        'is_int'         => ['value'],
        'is_float'       => ['value'],
        'is_string'      => ['value'],
        'is_bool'        => ['value'],
        'is_array'       => ['value'],
        'is_object'      => ['value'],
        'is_null'        => ['value'],
        'is_callable'    => ['value'],
        'is_resource'    => ['value'],
        'is_numeric'     => ['value'],
        'is_iterable'    => ['value'],
        'is_countable'   => ['value'],
        'gettype'        => ['var'],
        'get_debug_type' => ['value'],
        // JSON
        'json_encode'    => ['value', 'flags', 'depth'],
        'json_decode'    => ['json', 'assoc', 'depth', 'flags'],
        // 加密
        'password_hash'  => ['password', 'algorithm', 'options'],
        'password_verify'=> ['password', 'hash'],
        'hash'           => ['algo', 'data', 'binary'],
        'hash_hmac'      => ['algo', 'data', 'key', 'binary'],
        'hash_pbkdf2'    => ['algo', 'password', 'salt', 'iterations', 'length', 'binary'],
        // 文件
        'file_get_contents' => ['filename', 'use_include_path', 'context', 'offset', 'length'],
        'file_put_contents' => ['filename', 'data', 'flags', 'context'],
        'file_exists'    => ['filename'],
        'is_file'        => ['filename'],
        'is_dir'         => ['filename'],
        'is_readable'    => ['filename'],
        'is_writable'    => ['filename'],
        'filesize'       => ['filename'],
        'filemtime'      => ['filename'],
        'fileatime'      => ['filename'],
        'filectime'      => ['filename'],
        'realpath'       => ['path'],
        'dirname'        => ['path', 'levels'],
        'basename'       => ['path', 'suffix'],
        'pathinfo'       => ['path', 'flags'],
        'unlink'         => ['filename'],
        'rename'         => ['from', 'to'],
        'copy'           => ['from', 'to'],
        'mkdir'          => ['directory', 'permissions', 'recursive'],
        'rmdir'          => ['directory'],
        'tempnam'        => ['directory', 'prefix'],
        'sys_get_temp_dir' => [],
        // 输出
        'echo'           => [],
        'print'          => [],
        'print_r'        => ['value', 'return'],
        'var_dump'       => ['value'],
        'var_export'     => ['value', 'return'],
        'debug_zval_refcount' => ['value'],
        // 时间
        'time'           => [],
        'microtime'      => ['as_float'],
        'hrtime'         => ['as_number'],
        'date'           => ['format', 'timestamp'],
        'mktime'         => ['hour', 'minute', 'second', 'month', 'day', 'year'],
        'strtotime'      => ['datetime', 'baseTimestamp'],
        'sleep'          => ['seconds'],
        'usleep'         => ['micro_seconds'],
        'checkdate'      => ['month', 'day', 'year'],
        // 网络
        'ip2long'        => ['ip'],
        'long2ip'        => ['ip'],
        'inet_pton'      => ['ip'],
        'inet_ntop'      => ['addr'],
        'gethostbyname'  => ['hostname'],
        'gethostbyaddr'  => ['ip_address'],
    ];

    /**
     * 获取内置函数签名（ret + params），用于注册 closureSigs
     *   优先使用 $builtinFnParamCtypes 精确映射，回退到 t_var 占位
     */
    private function getBuiltinFnSig(string $name): ?array
    {
        $info = self::$simpleFnMap[$name] ?? null;
        if ($info === null) return null;

        // 优先使用精确参数类型映射；回退到 t_var（按 modes 数量）
        if (isset(self::$builtinFnParamCtypes[$name])) {
            $paramTypes = self::$builtinFnParamCtypes[$name];
        } else {
            $modes = $info['modes'] ?? [];
            $paramTypes = [];
            for ($i = 0; $i < count($modes); $i++) {
                $paramTypes[] = 't_var';
            }
        }

        // 返回类型：从 TypeChecker::$builtinRetTypes 查询
        $retType = 't_int';  // 默认
        if (isset(TypeChecker::$builtinRetTypes[$name])) {
            $phpRet = TypeChecker::$builtinRetTypes[$name];
            $retType = self::phpToCT($phpRet) ?? 't_int';
        }

        return [
            'ret'    => $retType,
            'params' => implode(', ', $paramTypes),
        ];
    }

    /**
     * 为方法引用 $obj->method(...) 生成 t_callback 包装器
     *   通过 thunk 函数包装实例方法调用，env 捕获 self 指针
     *
     *   生成代码模式：
     *   ({
     *     _cap_N* _env_N = ...; _env_N->self = $obj;
     *     tphp_rt_register(_env_N, 5);
     *     (t_callback){ .func = (void*)_thunk_N, .env = _env_N };
     *   })
     *
     * @return array{0:string,1:string} [code, sigName]
     */
    private function registerCallbackVar(string $fnName): void
    {
        // 生成唯一虚拟变量名，关联到闭包签名
        $virtualVar = '_cb_' . $fnName;
        $this->symbols->addVarClosure($virtualVar, $fnName);
    }

    public function visitAttributeDecl(AttributeDeclNode $node): string
    {
        return '';  // 注解类型声明不生成代码，由 visitConst 收集
    }

    public function visitAttributeUse(AttributeUseNode $node): string
    {
        return '';  // 注解使用不生成独立代码，由注解收集器处理
    }

    /**
     * 简单转发通用处理器：按 $simpleFnMap 配置生成 tphp_fn_xxx(args) 代码。
     *
     * 支持的 argMode：direct | data | floatcast | wrapvar | wraparr
     * 支持 defaults（缺省参数填充）、order（参数重排）、cNameNoArgs（0 参变体）。
     */
    private function reorderNewArgs(NewExpr $node): void
    {
        $hasNamed = false;
        foreach ($node->argNames as $n) {
            if ($n !== '') { $hasNamed = true; break; }
        }
        if (!$hasNamed) return;

        $cn = self::classRefName($node->className);
        $resolved = $this->symbols->resolveClass($cn) ?? $cn;
        $ctor = $this->symbols->getClassMethod($resolved, '__construct');
        // 父类继承的构造函数
        if ($ctor === null) {
            $parentCN = $this->resolveMethodClass($resolved, '__construct');
            if ($parentCN !== '') {
                $ctor = $this->symbols->getClassMethod($parentCN, '__construct');
            }
        }
        if ($ctor === null || empty($ctor->paramNames)) return;

        $paramNames = $ctor->paramNames;
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

        $reordered = [];
        $posIdx = 0;
        $totalSlots = count($paramNames);
        for ($slot = 0; $slot < $totalSlots; $slot++) {
            $slotName = $paramNames[$slot];
            if (isset($named[$slotName])) {
                $reordered[] = $named[$slotName];
                unset($named[$slotName]);
            } elseif ($posIdx < count($positional)) {
                $reordered[] = $positional[$posIdx++];
            }
        }

        if (!empty($named)) {
            $unknown = implode(', ', array_keys($named));
            throw new \RuntimeException(sprintf(
                "[%d:%d] Unknown named parameter \$%s for %s::__construct()",
                $node->line, $node->column, $unknown, $node->className
            ));
        }

        while ($posIdx < count($positional)) {
            $reordered[] = $positional[$posIdx++];
        }

        $node->args = $reordered;
        $node->argNames = array_fill(0, count($reordered), '');
    }

    /**
     * 解析 CallExpr 的目标函数/方法参数名列表
     * @return string[] 参数名数组（不含 $），空数组表示未知签名
     */
    public function visitCall(CallExpr $node): string
    {
        // ── func_get_args / func_num_args / func_get_arg 特殊处理 ──
        //   仅在可变参数函数/方法内有效（参 GRAMMAR.md:894-896）
        //   定参函数内调用 → 抛异常（user_profile: 禁止静默错误处理）
        //   可变参数函数内：func_get_args() → 返回 args 形参（t_array*）
        if ($node->callee === null && !$node->isRawC) {
            $fn = $node->name;
            // 命名空间 fallback：剥掉命名空间前缀
            if (($pos = strrpos($fn, '\\')) !== false) $fn = substr($fn, $pos + 1);
            if ($fn === 'func_get_args') {
                if ($this->currentVariadicParamName === '') {
                    throw new RuntimeException(sprintf(
                        "[%d:%d] func_get_args() is only available in variadic functions (those declared with ...\$args). ".
                        "Fixed-parameter functions have no unified argument container in AOT mode.",
                        $node->line, $node->column
                    ));
                }
                return $this->currentVariadicParamName;
            }
            if ($fn === 'func_num_args') {
                if ($this->currentVariadicParamName === '') {
                    throw new RuntimeException(sprintf(
                        "[%d:%d] func_num_args() is only available in variadic functions (those declared with ...\$args). ".
                        "Fixed-parameter functions have no unified argument container in AOT mode.",
                        $node->line, $node->column
                    ));
                }
                return "tphp_fn_arr_count({$this->currentVariadicParamName})";
            }
            if ($fn === 'func_get_arg') {
                if ($this->currentVariadicParamName === '') {
                    throw new RuntimeException(sprintf(
                        "[%d:%d] func_get_arg() is only available in variadic functions (those declared with ...\$args). ".
                        "Fixed-parameter functions have no unified argument container in AOT mode.",
                        $node->line, $node->column
                    ));
                }
                $idx = $node->args[0]->accept($this);
                // 根据可变参数元素类型选择类型化访问器（避免 t_var* → 栈值 类型不匹配）
                $elemType = $this->currentVariadicElementType;
                $accessor = match ($elemType) {
                    'int', 't_int'     => "tphp_fn_arr_get_int_int({$this->currentVariadicParamName}, {$idx})",
                    'float', 't_float' => "tphp_fn_arr_get_int_float({$this->currentVariadicParamName}, {$idx})",
                    'string', 't_string' => "tphp_fn_arr_get_int_str({$this->currentVariadicParamName}, {$idx})",
                    default             => "(*tphp_fn_arr_get_int({$this->currentVariadicParamName}, {$idx}))",
                };
                return $accessor;
            }
        }

        // 命名参数映射：将命名参数按函数/方法签名重排到正确位置
        //   foo(b: 2, a: 1) → foo(1, 2)（按 a, b 顺序排列）
        //   仅在存在命名参数时触发，纯位置参数透传
        $this->reorderNamedArgs($node);

        // 命名空间 fallback：NS\func() 调用，若 NS 下未定义则 fallback 到全局 func()
        // 符合 PHP 语义：命名空间下未定义的函数调用查全局
        if ($node->callee === null && ($pos = strrpos($node->name, '\\')) !== false) {
            $nsFnCName = self::funcCNameFromCall($node);
            if ($this->symbols->getFuncRet($nsFnCName) === null) {
                $baseName = substr($node->name, $pos + 1);
                $globalNode = new CallExpr($node->callee, $baseName, $node->args, $node->isNullsafe, $node->isRawC);
                $globalNode->line = $node->line;
                $globalNode->column = $node->column;
                return $this->visitCall($globalNode);
            }
        }

        // ── 注解常量静态索引 call() / newInstance() 编译期展开 ──
        // ROUTE[0]->call(12)        → 直接调用目标方法/函数
        // ROUTE[0]->newInstance(...) → new_tphp_class_X(args)
        // AST: CallExpr { callee: ArrayAccessExpr { array: VariableExpr, index: IntLiteral }, name: 'call'|'newInstance' }
        if ($node->callee instanceof ArrayAccessExpr
            && $node->callee->array instanceof VariableExpr
            && !str_starts_with($node->callee->array->name, '$')
            && $node->callee->index instanceof IntLiteralExpr
            && isset($this->annotationRegistry[$node->callee->array->name])
            && ($node->name === 'call' || $node->name === 'newInstance')) {
            $reg = $this->annotationRegistry[$node->callee->array->name];
            $idx = (int)$node->callee->index->value;
            if (isset($reg['entries'][$idx])) {
                return $this->emitAnnotationCall($reg['entries'][$idx], $node->name, $node->args);
            }
        }

        // ── 注解常量动态索引 call() / newInstance() 运行时分发 ──
        // ROUTE[$i]->call(12) → _annot_ROUTE_dispatch_call(ROUTE[$i], 1, (t_var[]){VAR_INT(12)})
        if ($node->callee instanceof ArrayAccessExpr
            && $node->callee->array instanceof VariableExpr
            && !str_starts_with($node->callee->array->name, '$')
            && isset($this->annotationRegistry[$node->callee->array->name])
            && ($node->name === 'call' || $node->name === 'newInstance')) {
            $annotName = $node->callee->array->name;
            $calleeCode = $node->callee->accept($this);  // 动态索引 → 运行时 AnnotationEntry*
            return $this->emitAnnotationRuntimeCall($annotName, $node->name, $calleeCode, $node->args);
        }

        // ── foreach 变量 $v->call() / $v->newInstance() 运行时分发 ──
        // $v 来自 foreach(ROUTE as $v)，通过 varAnnotSource 追踪来源
        if ($node->callee instanceof VariableExpr
            && str_starts_with($node->callee->name, '$')
            && ($node->name === 'call' || $node->name === 'newInstance')) {
            $valVar = self::varName($node->callee->name);
            if (isset($this->varAnnotSource[$valVar])) {
                $annotName = $this->varAnnotSource[$valVar];
                $calleeCode = $node->callee->accept($this);
                return $this->emitAnnotationRuntimeCall($annotName, $node->name, $calleeCode, $node->args);
            }
        }

        // PHPC 互操作函数名集合（B 段、C 段共享，避免重复定义）
        static $phpcFns = ['c_int','c_str','php_int','php_str','php_str_clone','php_str_ptr','c_void_ptr','cstr_to_string',
            'phpc_arr_int','phpc_arr_dbl','phpc_arr_str','phpc_new_arr_int',
            'phpc_new_arr_dbl','phpc_new_arr_str','phpc_new_arr',
            'phpc_obj','phpc_new_obj','phpc_unregister_obj','phpc_free','phpc_free_str_arr',
            'phpc_fn','phpc_env','phpc_fn_i32','phpc_fn_i64','phpc_fn_f64',
            'phpc_new_fn','phpc_new_fn_env','phpc_thunk',
            'phpc_assert_ptr','phpc_obj_steal','phpc_env_pin','phpc_env_unpin','phpc_auto',
            'phpc_ptr_to_int','phpc_int_to_ptr'];

        // 简单转发函数：查 $simpleFnMap 命中则交给通用处理器
        if ($node->callee === null && isset(self::$simpleFnMap[$node->name])) {
            return $this->generateSimpleForward($node, self::$simpleFnMap[$node->name]);
        }

        // var_dump 内置函数 —— 包装参数为 t_var 并调用 tphp_var_dump
        if ($node->callee === null && $node->name === 'var_dump') {
            return $this->generateVarDump($node->args);
        }

        // array_push($arr, $val) → 尾部追加，返回新长度
        if ($node->callee === null && $node->name === 'array_push') {
            if ($this->isGenericArrayVar($node->args[0])) {
                throw new \RuntimeException(
                    "array_push() cannot modify a typed array<T> variable in-place (conversion would lose changes). "
                    . "Use '\$arr[] = \$val' syntax instead, or declare the variable as array<mixed>."
                );
            }
            $arrCode = $this->arrayArgCode($node->args[0], $node->args[0]->accept($this));
            $valCode = $this->wrapArrayElement($node->args[1], $node->args[1]->accept($this));
            return 'tphp_fn_array_push(&' . $arrCode . ', ' . $valCode . ')';
        }

        // array_pop($arr) → 弹出尾部元素，返回 t_var（mixed 类型）
        if ($node->callee === null && $node->name === 'array_pop') {
            if ($this->isGenericArrayVar($node->args[0])) {
                throw new \RuntimeException(
                    "array_pop() cannot modify a typed array<T> variable in-place (conversion would lose changes). "
                    . "Use '\$v = \$arr[count(\$arr)-1]; \$arr = array_slice(\$arr, 0, -1);' or declare as array<mixed>."
                );
            }
            $arrCode = $this->arrayArgCode($node->args[0], $node->args[0]->accept($this));
            return 'tphp_fn_array_pop(&' . $arrCode . ')';
        }

        // array_key_exists($key, $arr) → 键是否存在
        if ($node->callee === null && $node->name === 'array_key_exists') {
            $keyType = $this->inferType($node->args[0]);
            $keyCode = $node->args[0]->accept($this);
            $arrCode = $this->arrayArgCode($node->args[1], $node->args[1]->accept($this));
            if ($keyType === 't_string') {
                return 'tphp_fn_array_key_exists_str(' . $keyCode . ', ' . $arrCode . ')';
            }
            return 'tphp_fn_array_key_exists_int(' . $keyCode . ', ' . $arrCode . ')';
        }

        // array_shift($arr) → 移除头部元素，返回 t_var
        if ($node->callee === null && $node->name === 'array_shift') {
            if ($this->isGenericArrayVar($node->args[0])) {
                throw new \RuntimeException(
                    "array_shift() cannot modify a typed array<T> variable in-place (conversion would lose changes). "
                    . "Use '\$v = \$arr[0]; \$arr = array_slice(\$arr, 1);' or declare as array<mixed>."
                );
            }
            $arrCode = $this->arrayArgCode($node->args[0], $node->args[0]->accept($this));
            $tv = '_ts_' . (++$this->tmpVarCounter);
            return "({ t_var {$tv} = VAR_NULL(); tphp_fn_arr_shift({$arrCode}, &{$tv}); {$tv}; })";
        }

        // asort/arsort/ksort/krsort/uasort/usort 保留 key-value 关联（或回调比较），
        //   语义不适用于 array<T>（有序列表，元素无独立 key 关联）。拒绝并提示用户
        //   改用 sort/rsort（值排序）或显式声明 array<mixed>。
        //   这些函数未在 $simpleFnMap 中注册，走 fallback 直接调用 tphp_fn_{name}，
        //   会在 array<T> 上导致类型不兼容或协变转换丢失修改。
        if ($node->callee === null
            && in_array($node->name, ['asort', 'arsort', 'ksort', 'krsort', 'uasort', 'usort'], true)
            && isset($node->args[0]) && $this->isGenericArrayVar($node->args[0])) {
            throw new \RuntimeException(
                "{$node->name}() preserves key-value association, which is not applicable to typed array<T>. "
                . "Use sort/rsort (value sort) or declare as array<mixed>."
            );
        }

        // array_slice($arr, $offset, $length=0, $preserve_keys=false)
        if ($node->callee === null && $node->name === 'array_slice') {
            $arrCode = $this->arrayArgCode($node->args[0], $node->args[0]->accept($this));
            $offset  = $node->args[1]->accept($this);
            $len     = isset($node->args[2]) ? $node->args[2]->accept($this) : '0';
            $pk      = isset($node->args[3]) ? $node->args[3]->accept($this) : 'false';
            return 'tphp_fn_arr_slice(' . $arrCode . ', ' . $offset . ', ' . $len . ', ' . $pk . ')';
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

        // var_export 内置函数 —— 转换为可读字符串输出
        if ($node->callee === null && $node->name === 'var_export') {
            return $this->generateVarExport($node->args);
        }

        // error($msg) → 抛出异常（tp_throw），可被 try-catch 捕获
        // 无 try-catch 时 tp_throw 内部仍会 Fatal error + exit(1)
        if ($node->callee === null && $node->name === 'error') {
            $this->checkExceptionReturnType();
            $msg  = !empty($node->args) ? $this->castToStr($node->args[0]) : 'STR_LIT("")';
            return 'tp_throw(STR_PTR_V(' . $msg . '))';
        }

        // isset($var) → 非 null 检测（非指针类型始终 true）
        if ($node->callee === null && $node->name === 'isset') {
            // Special case: isset($arr['key']) → 混合数组键存在性检查
            //   t_array* (mixed): tphp_fn_arr_get_str/int(arr, idx) != NULL
            //   t_var (holding array): extract .value._array first
            //   避免 *tphp_fn_arr_get_str(...) 返回 t_var 结构体无法转为 void*
            if (!empty($node->args) && $node->args[0] instanceof ArrayAccessExpr) {
                $arg = $node->args[0];
                $arrExpr = $arg->array;
                $vt = '';
                if ($arrExpr instanceof VariableExpr) {
                    $vt = $this->varTypes[self::varName($arrExpr->name)] ?? '';
                } elseif ($arrExpr instanceof PropertyAccessExpr) {
                    $vt = $this->inferType($arrExpr);
                }
                // Only handle t_array* (mixed array) and t_var (holding array)
                // Typed arrays (t_arr_int* etc.) fall through to existing logic
                if ($vt === 't_array*' || $vt === 't_var') {
                    $arrCode = $arrExpr->accept($this);
                    if ($vt === 't_var') {
                        $arrCode = "(({$arrCode}).value._array)";
                    }
                    $idxCode = $arg->index->accept($this);
                    $idxType = $this->inferType($arg->index);
                    if ($idxType === 't_string' || $arg->index instanceof StringLiteralExpr) {
                        return "(tphp_fn_arr_get_str({$arrCode}, {$idxCode}) != NULL)";
                    }
                    return "(tphp_fn_arr_get_int({$arrCode}, (t_int)({$idxCode})) != NULL)";
                }
            }
            // isset($obj->prop) — stdClass 动态属性存在性检查
            if (!empty($node->args) && $node->args[0] instanceof PropertyAccessExpr) {
                $pa = $node->args[0];
                $objCN = '';
                if ($pa->object instanceof VariableExpr) {
                    $vn = self::varName($pa->object->name);
                    $objType = $this->varTypes[$vn] ?? '';
                    $objCN = rtrim($objType, '*');
                }
                if ($objCN === 'tphp_class_stdClass') {
                    $obj = $pa->object->accept($this);
                    $prop = ltrim($pa->property, '$');
                    return "(tphp_fn_stdclass_isset({$obj}, STR_LIT(\"{$prop}\")))";
                }
            }
            $args = array_map(fn($a) => $a->accept($this), $node->args);
            $code = !empty($args) ? $args[0] : 'null';
            $type = !empty($node->args) ? $this->inferType($node->args[0]) : 'null';
            // 非指针类型（int/float/bool/string栈值）不可能为 null，始终 true
            if (self::isSimpleType($type)) return 'true';
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
                // unset($obj->prop) — stdClass 动态属性删除
                if ($arg instanceof PropertyAccessExpr) {
                    $objCN = '';
                    if ($arg->object instanceof VariableExpr) {
                        $vn = self::varName($arg->object->name);
                        $objType = $this->varTypes[$vn] ?? '';
                        $objCN = rtrim($objType, '*');
                    }
                    if ($objCN === 'tphp_class_stdClass') {
                        $obj = $arg->object->accept($this);
                        $prop = ltrim($arg->property, '$');
                        $lines[] = "tphp_fn_stdclass_unset({$obj}, STR_LIT(\"{$prop}\"))";
                        continue;
                    }
                }
                // 数组元素 unset: unset($arr[$key]) → 调用 C 运行时删除函数
                if ($arg instanceof ArrayAccessExpr) {
                    $arrCode = $arg->array->accept($this);
                    $idxCode = $arg->index->accept($this);
                    $idxType = $this->inferType($arg->index);
                    if ($idxType === 't_string' || $arg->index instanceof StringLiteralExpr) {
                        $lines[] = "tphp_fn_arr_unset_str({$arrCode}, {$idxCode})";
                    } elseif ($idxType === 't_int' || $idxType === 't_bool' || $idxType === 't_float') {
                        $lines[] = "tphp_fn_arr_unset_int({$arrCode}, (t_int)({$idxCode}))";
                    } else {
                        // key 类型不确定（t_var 等），运行时统一分发
                        $lines[] = "tphp_fn_arr_unset_var({$arrCode}, {$idxCode})";
                    }
                    continue;
                }
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
                if (self::isClassCType($type) || self::isEnumCType($type)) {
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

        // is_int / is_string / is_float / is_bool / is_array / is_object / is_null / is_callable / is_resource
        // 仅拦截内置类型检测函数，避免误吞用户自定义的 is_* 函数（如 is_positive）
        static $builtinIsFns = [
            'is_int' => 1, 'is_float' => 1, 'is_string' => 1, 'is_bool' => 1,
            'is_array' => 1, 'is_null' => 1, 'is_object' => 1, 'is_callable' => 1,
            'is_resource' => 1,
        ];
        if ($node->callee === null && isset($builtinIsFns[$node->name])) {
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
            if ($n === 'gettype') {
                $t0 = $this->inferType($node->args[0]);
                $w = match ($t0) {
                    't_int' => "VAR_INT({$c})", 't_float' => "VAR_FLOAT((t_float)({$c}))",
                    't_bool' => "VAR_BOOL({$c})", 't_string' => "VAR_STRING({$c})",
                    default => "VAR_NULL",
                };
                return "tphp_fn_gettype({$w})";
            }
            if ($n === 'get_object_vars') {
                $argType = $this->inferType($node->args[0]);
                $argCode = $node->args[0]->accept($this);
                // stdClass → 直接提取属性表
                if (str_contains($argType, 'tphp_class_stdClass')) {
                    return "tphp_fn_stdclass_to_array({$argCode})";
                }
                // 其他对象类型：暂不支持（需遍历 public 属性，复杂度高）
                throw new \RuntimeException(
                    sprintf("[%d:%d] get_object_vars() currently only supports stdClass, got %s",
                        $node->line, $node->column, $argType)
                );
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
            if ($n === 'strtr') {
                if (count($a) >= 3) return "tphp_fn_strtr2({$a[0]}, {$a[1]}, {$a[2]})";
                return $c;
            }

            // PHPC 互操作函数：加 tphp_fn_ 前缀
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
                    $this->markCPtrCleaned($varName);
                    return '(tphp_fn_phpc_free(' . $varName . '), (' . $varName . ' = NULL))';
                }
                if ($shortN === 'phpc_free_str_arr' && count($node->args) >= 2
                    && $node->args[0] instanceof VariableExpr) {
                    $varName = $this->visitVariable($node->args[0]);
                    $this->markCPtrCleaned($varName);
                    $lenArg = $a[1];
                    return '(tphp_fn_phpc_free_str_arr(' . $varName . ', (int)(' . $lenArg . ')), (' . $varName . ' = NULL))';
                }
                // phpc_unregister_obj / phpc_obj_steal：标记已清理
                if (($shortN === 'phpc_unregister_obj' || $shortN === 'phpc_obj_steal')
                    && count($node->args) >= 1 && $node->args[0] instanceof VariableExpr) {
                    $this->markCPtrCleaned($this->visitVariable($node->args[0]));
                }
                // phpc_auto($ptr)：接管 $ptr 所有权（注册自动释放），标记已清理
                if ($shortN === 'phpc_auto'
                    && count($node->args) >= 1 && $node->args[0] instanceof VariableExpr) {
                    $this->markCPtrCleaned($this->visitVariable($node->args[0]));
                }
                // t_var 参数解包：php_int/c_int 期望标量，php_str/c_str 等期望字符串
                //   场景：foreach ($options as $k => $v) { $flags = php_int($v); } — $v 是 t_var
                //   php_int(v) 展开为 ((t_int)(v))，对 struct t_var 编译失败
                $a = $this->unwrapPhpcArgs($shortN, $node, $a);
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

            // abs(int|float) → 按参数类型分发 int/float 重载
            if ($shortN === 'abs' && count($node->args) >= 1) {
                $argType = $this->inferType($node->args[0]);
                $argCode = $a[0];
                if ($argType === 't_float') {
                    return "tphp_fn_abs_float({$argCode})";
                }
                return "tphp_fn_abs_int({$argCode})";
            }

            // 通用回退：tphp_fn_函数名(参数) — C 编译器兜底
            // 全局函数: tphp_fn_name, 命名空间函数: tphp_na_Ns_tphp_fn_name
            $fnPos = strrpos($n, '\\');
            if ($fnPos !== false) {
                $fnName = 'tphp_na_' . str_replace('\\', '_', substr($n, 0, $fnPos)) . '_tphp_fn_' . substr($n, $fnPos + 1);
            } elseif (isset(self::$builtinRetTypes[$n]) && (str_starts_with($n, 'tphp_fn_') || str_starts_with($n, '_'))) {
                // 已注册的 C 函数（名称以 tphp_fn_ 或 _ 开头并在 $builtinRetTypes 中登记）：
                //   直接使用原名，避免 tphp_fn_tphp_fn_xxx 或 tphp_fn__pg_xxx 双前缀
                //   tphp_fn_ 前缀：stream/openssl 等扩展的 C API（tphp_fn_stream_init 等）
                //   _ 前缀：pgsql/pdo_pgsql 等扩展的内部 C 实现函数（_pg_connect/_pgpdo_init 等），
                //   不占用 tphp_fn_ 命名空间，避免与 PHP 层函数生成的 tphp_fn_<name> 冲突
                $fnName = $n;
            } else {
                $fnName = 'tphp_fn_' . $n;
            }
            // 检查是否有默认值参数，选择正确的重载版本
            $argCount = count($node->args);
            $fnInfo = $this->symbols->getFunc($fnName);
            $defaultCount = $fnInfo !== null ? $fnInfo->defaultCount : 0;
            // 检测可变参数函数：最后一个形参是 ...$args
            $isVariadic = $fnInfo !== null && $fnInfo->isVariadic;
            if ($isVariadic) {
                // 可变参数函数调用：固定参数 + 可变参数打包
                //   可变参数位置 = 总参数 - 1（最后一个形参是 t_array*）
                $variadicPos = $fnInfo->totalParams - 1;
                $fixedPTypes = array_slice($fnInfo->paramTypes, 0, $variadicPos);
                [$fixedCodes, $variadicCode] = $this->generateVariadicArgs($node, $variadicPos, $fixedPTypes);
                $allCodes = array_merge($fixedCodes, [$variadicCode]);
                return "{$fnName}(" . implode(', ', $allCodes) . ")";
            }
            if ($defaultCount > 0) {
                // 获取总参数数量
                $totalParams = $fnInfo !== null ? count($fnInfo->paramTypes) : 0;
                if ($totalParams > 0 && $argCount < $totalParams) {
                    // 使用重载版本：fnName_缺失参数数量
                    $missingCount = $totalParams - $argCount;
                    $fnName = $fnName . '_' . $missingCount;
                    // 更新参数类型列表（重载版本只有前 argCount 个参数）
                    $pTypes = array_slice($this->symbols->getFuncParams($fnName), 0, $argCount);
                } else {
                    $pTypes = $fnInfo !== null ? $fnInfo->paramTypes : [];
                }
            } else {
                $pTypes = $fnInfo !== null ? $fnInfo->paramTypes : [];
            }
            // 回退：内置函数参数类型映射（dirname/basename 等未注册到 SymbolTable 的内置函数）
            if (empty($pTypes) && isset(self::$builtinFnParamCtypes[$n])) {
                $pTypes = self::$builtinFnParamCtypes[$n];
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
                    // t_var (mixed) 参数：自动包裹 VAR_XXX
                    if ($ct === 't_var') {
                        $aCode = $this->wrapTvarAssign($arg, $aCode);
                    } elseif (self::isSimpleType($ct)) {
                        // 标量参数：实参为 t_var（mixed）时，按参数类型解包
                        //   场景：dirname($urlInfo["path"]) — $urlInfo["path"] 为 t_var，参数声明 string
                        $argType = $this->inferType($arg);
                        if ($argType === 't_var') {
                            $aCode = $this->unwrapTVarToType($aCode, $ct);
                        }
                    }
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

        // 对 t_var 参数自动包裹 VAR_XXX；父子类参数自动 upcast；array<T> 协变转换为 array<mixed>
        $args = [];
        foreach ($node->args as $i => $a) {
            $code = $a->accept($this);
            // 查找该方法参数类型
            $pt = $this->getMethodParamType($node, $i);
            if ($pt === 't_var') {
                $code = $this->wrapTvarAssign($a, $code);
            } elseif ($pt === 't_array*' && $a instanceof VariableExpr) {
                // 参数声明 array (即 array<mixed>/t_array*)，实参是 array<T>（t_arr_int* 等）
                //   自动协变转换为 t_array*（O(n) 复制包装为 t_var 元素）
                $code = $this->arrayArgCode($a, $code);
            } elseif ($pt !== '' && self::isClassCType($pt) && $a instanceof VariableExpr) {
                // 父子类 upcast：实参是子类，参数声明是父类时生成 (ParentType*)arg
                $argVarKey = self::varName($a->name);
                $argType = $this->varTypes[$argVarKey] ?? '';
                if (self::isClassCType($argType)) {
                    $argCn = rtrim($argType, '*');
                    $ptCn  = rtrim($pt, '*');
                    if ($argCn !== $ptCn && $this->isSubclassOf($argCn, $ptCn)) {
                        $code = "({$pt}){$code}";
                    }
                } elseif ($argType === 't_var') {
                    // t_var 变量（mixed 持有对象）→ 对象指针：提取 .value._object 并转型
                    $code = "(({$pt})({$code}).value._object)";
                }
            } elseif ($pt !== '' && self::isClassCType($pt) && $this->inferType($a) === 't_var') {
                // t_var 表达式（如可变参数数组元素 $children[$i]）→ 对象指针提取
                //   场景：Stack::column(...$children) 中 $children[$i] 为 t_var，传给 Widget 参数
                $code = "(({$pt})({$code}).value._object)";
            } elseif (self::isSimpleType($pt)) {
                // 标量参数：实参为 t_var（mixed）时，按参数类型解包
                //   场景：dirname($urlInfo["path"]) — $urlInfo["path"] 为 t_var，参数声明 string
                $argType = $this->inferType($a);
                if ($argType === 't_var') {
                    $code = $this->unwrapTVarToType($code, $pt);
                }
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
            // 清理函数启发式识别：函数名以 free/destroy/release/close/delete 结尾时，
            // 标记第一参数（变量）为已释放，避免误报泄漏警告。
            // 覆盖 point_free / rect_destroy / fclose / SDL_FreeSurface 等常见命名约定。
            // 纯编译期分析，零运行时开销；遗漏不会漏报（仅多/少一条提醒，不阻断编译）。
            if (count($node->args) >= 1 && $node->args[0] instanceof VariableExpr) {
                $lowerName = strtolower($node->name);
                if (str_ends_with($lowerName, 'free')
                    || str_ends_with($lowerName, 'destroy')
                    || str_ends_with($lowerName, 'release')
                    || str_ends_with($lowerName, 'close')
                    || str_ends_with($lowerName, 'delete')) {
                    $this->markCPtrCleaned(self::varName($node->args[0]->name));
                }
            }
            // 参数类型自动转换：使用声明的 C 函数签名（function C.foo(...): C.ret;）
            //   - char* 参数 + 字符串字面量 → 直接传 C 字符串字面量（剥去 STR_LIT(...) 包装）
            //   - char* 参数 + t_string 表达式 → 访问 .data 字段获取 char*
            //   - 变参 `...` 之后的参数不做转换
            $cFuncInfo = $this->symbols->getCFunction($node->name);
            if ($cFuncInfo !== null) {
                $newArgs = [];
                $argCount = count($node->args);
                foreach ($node->args as $i => $a) {
                    $paramType = $cFuncInfo->paramTypes[$i] ?? '';
                    // 变参标记：之后所有参数按原样传递
                    if ($paramType === '...') {
                        for (; $i < $argCount; $i++) {
                            $newArgs[] = $args[$i];
                        }
                        break;
                    }
                    $code = $args[$i];
                    if ($paramType === 'char*') {
                        if ($a instanceof StringLiteralExpr) {
                            // 字符串字面量：剥去 STR_LIT(...) 包装，直接传 C 字符串字面量
                            $generated = $args[$i];
                            if (str_starts_with($generated, 'STR_LIT(') && str_ends_with($generated, ')')) {
                                $code = substr($generated, strlen('STR_LIT('), -1);
                            }
                        } else {
                            // t_string 变量/表达式：访问 .data 字段获取 char*
                            $argType = $this->inferType($a);
                            if ($argType === 't_string') {
                                $code = $args[$i] . '.data';
                            }
                        }
                    }
                    $newArgs[] = $code;
                }
                $args = $newArgs;
            }
            return $node->name . '(' . implode(', ', $args) . ')';
        }
        // 方法调用：类名推导
        if ($callee === 'self') {
            $cn = $this->className;
        } elseif ($callee === 'parent') {
            // parent::method() → 查找当前类的父类
            $parentPhp = $this->lookupParentClass($this->phpClassName);
            $cn = $parentPhp !== null ? self::classRefName($parentPhp) : $this->className;
        } elseif ($node->callee instanceof VariableExpr) {
            $key = self::varName($node->callee->name);
            $raw = $this->varTypes[$key] ?? $key;
            $cn = str_contains($raw, '\\') ? self::classRefName($raw) : $raw;
        } elseif ($node->callee instanceof CallExpr) {
            // 链式调用：从上一个调用的返回类型推导
            $cn = $this->inferCallChainClass($node->callee);
        } elseif ($node->callee instanceof EnumAccessExpr) {
            // Color::Red->method() — 实例方法，cn 取枚举 C 结构体名
            $cn = $this->symbols->getEnumCName($node->callee->enumName) ?? $callee;
        } elseif ($node->callee instanceof ArrayAccessExpr) {
            // 数组元素方法调用：$this->connections[$fd]->close()
            //   通过 inferType() 解析数组元素类型，剥离 * 得到类 C 名
            $cn = rtrim($this->inferType($node->callee), '*');
        } elseif ($node->callee instanceof PropertyAccessExpr) {
            // 属性方法调用：$this->protocol->input(...) 或 $obj->prop->method(...)
            //   通过 inferType() 解析属性类型，剥离 * 得到类 C 名
            $cn = rtrim($this->inferType($node->callee), '*');
        } else {
            $cn = $callee;
        }
        // nullsafe on null-typed variable → no-op
        if ($node->isNullsafe && ($cn === 'null' || $cn === '' || $cn === 'void*')) {
            return '0'; // nullsafe no-op
        }
        // Strip trailing * + resolve parent class for inherited methods
        $cnClean = rtrim($cn, '*');
        // 静态方法调用：PHP 类名 → C 类名解析（如 Thread → tphp_class_Thread）
        if ($cnClean !== '' && !$this->symbols->hasClass($cnClean)
            && $this->symbols->resolveEnumCName($cnClean) === null) {
            $resolved = $this->symbols->resolveClass($cnClean);
            if ($resolved !== null) $cnClean = $resolved;
        }
        // ── 枚举方法调用（静态 Color::method() 或实例 Color::Red->method()）──
        $enumCName = $this->symbols->resolveEnumCName($cnClean);
        if ($enumCName !== null) {
            return $this->emitEnumMethodCall($node, $enumCName, $callee, $args);
        }
        $useParent = false;
        $isParentCall = ($callee === 'parent');
        if ($isParentCall) {
            $useParent = true;  // parent::method() 总是通过 _parent 访问
        } elseif ($cnClean !== '' && $this->symbols->getClassMethod($cnClean, $node->name) === null) {
            $parentCN = $this->resolveMethodClass($cnClean, $node->name);
            if ($parentCN !== '') { $cnClean = $parentCN; $useParent = true; }
        }
        // 校验方法存在性：未定义的方法直接报错，不生成无效 C 代码
        if ($cnClean !== '' && $this->symbols->getClassMethod($cnClean, $node->name) === null
            && $node->name !== '__construct' && $node->name !== '__destruct') {
            throw new \RuntimeException(sprintf(
                "[%d:%d] Call to undefined method %s::%s()",
                $node->line, $node->column, $cnClean, $node->name
            ));
        }
        // 静态方法不传 self，实例方法 self 作为第一个参数
        $mInfoForDefault = $this->symbols->getClassMethod($cnClean, $node->name);
        $isStatic = $mInfoForDefault !== null && $mInfoForDefault->isStatic;
        // 可变参数方法：最后一个形参是 ...$args
        if ($mInfoForDefault !== null && $mInfoForDefault->isVariadic) {
            $variadicPos = $mInfoForDefault->totalParams - 1;
            $fixedPTypes = array_slice($mInfoForDefault->paramTypes, 0, $variadicPos);
            [$fixedCodes, $variadicCode] = $this->generateVariadicArgs($node, $variadicPos, $fixedPTypes);
            // self 参数（非静态方法）
            if ($isStatic) {
                $allCodes = $fixedCodes;
            } else {
                $selfArg = $useParent
                    ? ($isParentCall ? '&self->_parent' : ('&' . $callee . '->_parent'))
                    : $callee;
                $allCodes = array_merge([$selfArg], $fixedCodes);
            }
            $allCodes[] = $variadicCode;
            $methodCName = "{$cnClean}_{$node->name}";
            $call = "{$methodCName}(" . implode(', ', $allCodes) . ')';
            // nullsafe ?-> : wrap in NULL check with temp variable
            if ($node->isNullsafe) {
                $ret = $mInfoForDefault->retType;
                if ($ret === 'void') {
                    return "({ if ((void*){$callee} != NULL) {{ {$call}; }} })";
                }
                $tmp = '_nsr_' . (++$this->tmpVarCounter);
                $zero = match ($ret) { 't_float' => '0.0', 't_string' => '(t_string){NULL,0}', default => '0' };
                return "({ {$ret} {$tmp} = {$zero}; if ((void*){$callee} != NULL) {{ $tmp = {$call}; }} {$tmp}; })";
            }
            return $call;
        }
        if ($isStatic) {
            $allArgs = $args;
        } else {
            // parent::method() 用 &self->_parent；继承方法用 &callee->_parent；否则用 callee
            $selfArg = $useParent
                ? ($isParentCall ? '&self->_parent' : ('&' . $callee . '->_parent'))
                : $callee;
            $allArgs = array_merge([$selfArg], $args);
        }
        // 选择重载版本：有默认值参数且实参数量 < 总参数时，使用 fnName_缺失数 重载
        $methodCName = "{$cnClean}_{$node->name}";
        $argCount = count($node->args);
        if ($mInfoForDefault !== null && $mInfoForDefault->defaultCount > 0
            && $argCount < $mInfoForDefault->totalParams) {
            $missingCount = $mInfoForDefault->totalParams - $argCount;
            $methodCName = $methodCName . '_' . $missingCount;
        }
        $call = "{$methodCName}(" . implode(', ', $allArgs) . ')';
        // nullsafe ?-> : wrap in NULL check with temp variable
        if ($node->isNullsafe) {
            $mInfo = $this->symbols->getClassMethod($cnClean, $node->name);
            $ret = $mInfo !== null ? $mInfo->retType : 't_int';
            if ($ret === 'void') {
                return "({ if ((void*){$callee} != NULL) {{ {$call}; }} })";
            }
            $tmp = '_nsr_' . (++$this->tmpVarCounter);
            $zero = match ($ret) { 't_float' => '0.0', 't_string' => '(t_string){NULL,0}', default => '0' };
            return "({ {$ret} {$tmp} = {$zero}; if ((void*){$callee} != NULL) {{ $tmp = {$call}; }} {$tmp}; })";
        }
        return $call;
    }

    /**
     * 枚举方法调用发射：
     *   - 静态: Color::cases() / Color::from($v) / Color::tryFrom($v) → Color_cases(), ...
     *   - 实例: Color::Red->label() → Color_label(&$e_..._Red, ...)
     * 自动方法（cases/from/tryFrom）为静态无 self；用户方法为实例方法带 self。
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
        if ($node->castType === 'object') {
            return $this->castToObject($node->expr);
        }
        return '((' . self::mapType($node->castType) . ')(' . $node->expr->accept($this) . '))';
    }

    public function visitNew(NewExpr $node): string
    {
        // 构造函数命名参数重排
        $this->reorderNewArgs($node);

        $cn = self::classRefName($node->className);
        if ($cn === 'tphp_class_stdClass') {
            if (!empty($node->args)) {
                throw new \RuntimeException(
                    sprintf("[%d:%d] stdClass does not accept constructor arguments", $node->line, $node->column)
                );
            }
            return 'new_stdClass()';
        }
        $args = array_map(fn($a) => $a->accept($this), $node->args);
        // 默认参数重载：构造函数有默认值参数且实参数量 < 总参数时，使用 new_cn_<missing> 重载
        $ctorInfo = $this->symbols->getClassMethod($cn, '__construct');
        $allocName = "new_{$cn}";
        if ($ctorInfo !== null && $ctorInfo->defaultCount > 0
            && count($args) < $ctorInfo->totalParams) {
            $missing = $ctorInfo->totalParams - count($args);
            $allocName = "new_{$cn}_{$missing}";
        }
        if (empty($args) && $cn !== $this->className && $allocName === "new_{$cn}") {
            return "new_{$cn}()";
        }
        return "{$allocName}(" . implode(', ', $args) . ')';
    }

    public function visitPropertyAccess(PropertyAccessExpr $node): string
    {
        // ── 注解常量静态索引属性访问编译期展开 ──
        // ROUTE[0]->data / ->type / ->name → _annot_ROUTE_0->data / ->type / ->name
        if ($node->object instanceof ArrayAccessExpr
            && $node->object->array instanceof VariableExpr
            && !str_starts_with($node->object->array->name, '$')
            && $node->object->index instanceof IntLiteralExpr
            && isset($this->annotationRegistry[$node->object->array->name])) {
            $reg = $this->annotationRegistry[$node->object->array->name];
            $idx = (int)$node->object->index->value;
            if (isset($reg['entries'][$idx])) {
                $entryVar = $reg['entryVarPrefix'] . $idx;
                $prop = ltrim($node->property, '$');
                if (in_array($prop, ['data', 'type', 'name'], true)) {
                    return "{$entryVar}->{$prop}";
                }
            }
        }

        // C->CONST — direct C constant/enum/macro access (no parentheses)
        if ($node->object instanceof VariableExpr && $node->object->name === 'C') {
            return $node->property;
        }
        $obj = $node->object->accept($this);
        $prop = ltrim($node->property, '$');
        // t_var 对象属性访问：需提取对象指针 .value._object
        //   场景1：foreach ($handle->postFields as $v) — $v 是 t_var，含 CURLFile 对象
        //          $v->postname → $v.value._object->_parent.postname（未知具体类型，走默认路径）
        //   场景2：if ($v instanceof CURLFile) { $v->postname } — 类型窄化后
        //          $v->postname → ((tphp_class_CURLFile*)($v).value._object)->postname
        if ($node->object instanceof VariableExpr && str_starts_with($node->object->name, '$')) {
            $vn = self::varName($node->object->name);
            if (($this->varTypes[$vn] ?? '') === 't_var') {
                if (isset($this->narrowedTypes[$vn])) {
                    // 类型窄化：instanceof 分支内，cast 到具体类指针
                    $cn = $this->narrowedTypes[$vn];
                    $obj = "(({$cn}*)({$obj}).value._object)";
                } else {
                    $obj = "({$obj}).value._object";
                }
            }
        }
        // #cstruct 原生字段访问：$p->x → ((Point*)$p)->x
        //   当对象类型为已声明的 C 结构体指针时，直接 cast 访问字段
        if ($node->object instanceof VariableExpr && str_starts_with($node->object->name, '$')) {
            $vn = self::varName($node->object->name);
            $objType = $this->varTypes[$vn] ?? '';
            // objType 形如 "Point*" — 去掉尾部 * 得到结构体名
            $structName = rtrim($objType, '*');
            if (isset($this->cstructFields[$structName])) {
                // 验证字段存在
                foreach ($this->cstructFields[$structName] as $f) {
                    if ($f['name'] === $prop) {
                        return "(({$structName}*){$obj})->{$prop}";
                    }
                }
                throw new \RuntimeException(
                    sprintf("[%d:%d] C struct %s has no field '%s'", $node->line, $node->column, $structName, $prop)
                );
            }
        }
        // 静态属性访问: Class::$prop / self::$prop → 文件作用域变量 <cn>_<prop>
        //   (property 名以 $ 开头标识静态属性，object 名无 $ 前缀标识类名/self)
        if ($node->object instanceof VariableExpr
            && !str_starts_with($node->object->name, '$')
            && str_starts_with($node->property, '$')) {
            $rawName = $node->object->name;
            $cn = ($rawName === 'self')
                ? $this->className
                : ($this->symbols->resolveClass($rawName) ?? $rawName);
            if ($this->symbols->isStaticProp($cn, $prop)) {
                return "{$cn}_{$prop}";
            }
            throw new \RuntimeException(
                sprintf("[%d:%d] Access to undeclared static property %s::$%s", $node->line, $node->column, $rawName, $prop)
            );
        }
        // COS inheritance: resolve property through _parent chain
        $objCN = '';
        if ($obj === 'self') {
            $objCN = $this->className;
        } elseif ($node->object instanceof VariableExpr) {
            $vn = self::varName($node->object->name);
            // 优先使用类型窄化（instanceof 分支内）
            if (isset($this->narrowedTypes[$vn])) {
                $objCN = $this->narrowedTypes[$vn];
            } else {
                $objType = $this->varTypes[$vn] ?? '';
                // tphp_class_Dog* → tphp_class_Dog
                $objCN = rtrim($objType, '*');
            }
        }
        // stdClass 动态属性访问：$obj->prop → tphp_fn_stdclass_get(obj, c_str("prop"))
        if ($objCN === 'tphp_class_stdClass' && !ctype_upper($prop[0] ?? '')) {
            $access = "tphp_fn_stdclass_get({$obj}, STR_LIT(\"{$prop}\"))";
            if ($node->isNullsafe) {
                return $this->wrapNullsafeAccess($obj, $access, 't_var');
            }
            return $access;
        }
        // Property Hook: get 拦截 — 不在 hook 体内时调用 getter
        if (!$this->inHookBody && $objCN !== '' && !ctype_upper($prop[0] ?? '')) {
            $hookInfo = $this->resolveHookInfo($objCN, $prop);
            if ($hookInfo !== null && $hookInfo['get']) {
                return $hookInfo['cn'] . '_get_' . $prop . '(' . $obj . ')';
            }
        }
        if ($objCN !== '' && !$this->symbols->hasClassOwnProp($objCN, $prop)) {
            // 枚举类型直接访问字段（无 COS _parent 包装）
            //   全局: tphp_enum_Color；命名空间: tphp_na_Ns_tphp_enum_Status
            if (self::isEnumCType($objCN)) {
                $access = $obj . '->' . $prop;
                if ($node->isNullsafe) {
                    return $this->wrapNullsafeAccess($obj, $access, $this->inferType($node));
                }
                return $access;
            }
            // 类常量（大写开头）→ 不经过 _parent，由下方 const 逻辑处理
            if ($prop !== '' && ctype_upper($prop[0])) {
                // 交由下方类常量访问逻辑 → TPHP_CONST_ 引用
            } else {
                $prefix = $this->resolvePropPrefix($objCN, $prop);
                $access = $obj . '->_parent.' . $prefix . $prop;
                if ($node->isNullsafe) {
                    return $this->wrapNullsafeAccess($obj, $access, $this->inferType($node));
                }
                return $access;
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
            $cname = $this->symbols->resolveClass($rawObjName);
            if ($cname !== null) {
                $fullCName = 'TPHP_CONST_' . strtoupper($cname . '_' . $prop);
                $vis = $this->symbols->getConstVis($cname . '_' . $prop);
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
            $access = "({$obj})->{$prop}";
            if ($node->isNullsafe) {
                return $this->wrapNullsafeAccess($obj, $access, $this->inferType($node));
            }
            return $access;
        }
        $access = "{$obj}->{$prop}";
        if ($node->isNullsafe) {
            return $this->wrapNullsafeAccess($obj, $access, $this->inferType($node));
        }
        return $access;
    }

    /**
     * 包装属性访问为 nullsafe 表达式：对象为 NULL 时返回零值，否则返回属性值
     *   生成: ({ ret_type tmp = zero; if (obj != NULL) { tmp = access; } tmp; })
     */
    public function visitPropertyDecl(PropertyDeclNode $node): string
    {
        return '';
    }

    public function visitConst(ConstNode $node): string
    {
        // 注解类型声明: #[Attribute(...)] const NAME = [];
        //   扫描整个 ProgramNode 收集 #[NAME(...)] 使用，生成 AnnotationEntry 数组
        if ($node->attributeDecl !== null) {
            return $this->emitAnnotationConstant($node);
        }
        $name = 'TPHP_CONST_' . strtoupper($node->name);
        // 有声明类型时校验一致性，并以声明类型注册；无则按字面量推导
        // UnaryExpr（如 -1、~1）按操作数字面量类型推导
        $isUnary = $node->value instanceof UnaryExpr;
        $litCType = self::$litTypeMap[$node->value::class] ?? null;
        if ($isUnary && $litCType === null) {
            $litCType = self::$litTypeMap[$node->value->expr::class] ?? null;
        }
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
        $this->symbols->addConst($node->name, $ct);
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
        // UnaryExpr（如 -1、~1）：编译期求值为字面量
        if ($isUnary) {
            $operand = $node->value->expr;
            $op = $node->value->operator;
            if ($operand instanceof IntLiteralExpr) {
                $iv = (int)$operand->value;
                if ($op === '-') {
                    return '#define ' . $name . ' ' . (-$iv);
                }
                if ($op === '~') {
                    return '#define ' . $name . ' ' . (~$iv);
                }
            }
            if ($operand instanceof FloatLiteralExpr) {
                $fv = (float)$operand->value;
                if ($op === '-') {
                    $rv = -$fv;
                    return '#define ' . $name . ' ' .
                        (($rv == (float)(int)$rv) ? sprintf('%.1f', $rv) : rtrim(rtrim(sprintf('%.15g', $rv), '0'), '.'));
                }
            }
        }
        return '/* const ' . $node->name . ' */';
    }

    /**
     * 注解常量发射：扫描 ProgramNode 收集 #[NAME(...)] 使用，生成 AnnotationEntry 数组
     *
     * 生成结构:
     *   static tphp_class_AnnotationEntry* _annot_NAME_0 = NULL;  // 静态索引编译期展开
     *   static t_array* TPHP_CONST_NAME = NULL;
     *   static void _annot_NAME_init(void) { ... 填充数组 ... }
     *
     * 注册到 $annotationRegistry，供 visitArrayAccess/visitCall/visitPropertyAccess 静态展开使用
    /** 将表达式代码包装为 t_var（用于运行时分发参数传递） */
    private function attrNameMatches(string $attrName, string $shortName, string $fqName): bool
    {
        return $attrName === $fqName || $attrName === $shortName;
    }

    /** 生成注解运行时 dispatch 函数（call / newInstance）
    /** 查找独立函数的参数类型列表 */
    private function lookupFunctionParams(string $fnName, string $namespace): array
    {
        foreach ($this->program->functions as $fn) {
            if ($fn->name === $fnName && $fn->namespace === $namespace) {
                return array_map(fn($p) => $p->type, $fn->params);
            }
        }
        return [];
    }

    /** 查找类方法的参数类型列表 */
    private function lookupMethodParams(string $className, string $methodName): array
    {
        $allClasses = array_merge(
            $this->program->mainClass ? [$this->program->mainClass] : [],
            $this->program->extraClasses
        );
        // trait 自身不参与查找（编译期已扁平化到使用方）
        $allClasses = array_filter($allClasses, fn($c) => !$c->isTrait);
        foreach ($allClasses as $class) {
            $classFq = $class->namespace !== '' ? $class->namespace . '\\' . $class->name : $class->name;
            if ($classFq === $className || $class->name === $className) {
                foreach ($class->methods as $m) {
                    if ($m->name === $methodName) {
                        return array_map(fn($p) => $p->type, $m->params);
                    }
                }
            }
        }
        return [];
    }

    /** 查找类的父类名 */
    private function lookupParentClass(string $className): ?string
    {
        $allClasses = array_merge(
            $this->program->mainClass ? [$this->program->mainClass] : [],
            $this->program->extraClasses
        );
        // trait 自身不参与查找（编译期已扁平化到使用方）
        $allClasses = array_filter($allClasses, fn($c) => !$c->isTrait);
        foreach ($allClasses as $class) {
            $classFq = $class->namespace !== '' ? $class->namespace . '\\' . $class->name : $class->name;
            if ($classFq === $className || $class->name === $className) {
                return $class->parentName;
            }
        }
        return null;
    }

    /** 编译期校验注解参数（数量、类型） */
    private function validateAttrArgs(string $annotName, array $declParams, array $args, string $context): void
    {
        $total = count($declParams);
        $required = 0;
        foreach ($declParams as $p) {
            if ($p['default'] === null) $required++;
        }
        if (count($args) < $required || count($args) > $total) {
            throw new \RuntimeException(sprintf(
                "Annotation #[%s(...)] on %s expects %d-%d args, got %d",
                $annotName, $context, $required, $total, count($args)
            ));
        }
    }

    /** 检查 C 类名是否为 Main 入口类（构造器签名为 (t_int argc, t_array* argv)） */
    private function isMainClassCName(string $classCName): bool
    {
        return $this->program !== null
            && $this->program->mainClass !== null
            && self::classCName($this->program->mainClass) === $classCName;
    }

    /**
     * 注解 entry 的 call() / newInstance() 编译期展开
     *
     * call(...$args) — 调用目标方法/函数:
     *   - method:        (tphp_class_Main_test(new_tphp_class_Main(), args))
     *   - static_method: (tphp_class_Main_staticMethod(args))
     *   - function:      (tphp_fn_func(args))
     *   - class:         错误（class 目标不支持 call）
     *
     * newInstance(...$args) — 实例化目标类:
     *   - class:         new_tphp_class_Demo(args)
     *   - 其他:          错误
     */
    private function emitAnnotationCall(array $entry, string $method, array $args): string
    {
        $argCodes = array_map(fn($a) => $a->accept($this), $args);

        if ($method === 'call') {
            $kind = $entry['kind'];
            if ($kind === 'class') {
                throw new \RuntimeException(sprintf(
                    "Annotation entry '%s' is a class target, use newInstance() instead of call()",
                    $entry['name']
                ));
            }
            if ($kind === 'function') {
                // 函数调用: tphp_fn_X(args) or tphp_na_Ns_tphp_fn_X(args)
                $fnCName = $entry['namespace'] !== ''
                    ? 'tphp_na_' . self::mangleCName($entry['namespace']) . '_tphp_fn_' . $entry['function']
                    : 'tphp_fn_' . $entry['function'];
                return "{$fnCName}(" . implode(', ', $argCodes) . ")";
            }
            // 方法调用
            $classCName = self::classRefName($entry['class']);
            $methodCName = $classCName . '_' . $entry['method'];
            if ($kind === 'static_method') {
                return "{$methodCName}(" . implode(', ', $argCodes) . ")";
            }
            // 实例方法: 需要先 new 实例再调用
            // Main 入口类构造器签名 (t_int argc, t_array* argv)，传 dummy 参数
            $newExpr = $this->isMainClassCName($classCName)
                ? "new_{$classCName}((t_int)0, (t_array*)NULL)"
                : "new_{$classCName}()";
            return "(" . $methodCName . "(" . $newExpr . (empty($argCodes) ? "" : ", " . implode(', ', $argCodes)) . "))";
        }

        // newInstance
        if ($entry['kind'] !== 'class') {
            throw new \RuntimeException(sprintf(
                "Annotation entry '%s' is a %s target, use call() instead of newInstance()",
                $entry['name'], $entry['kind']
            ));
        }
        $classCName = self::classRefName($entry['class']);
        // Main 入口类构造器签名 (t_int argc, t_array* argv)
        if ($this->isMainClassCName($classCName)) {
            return empty($argCodes)
                ? "new_{$classCName}((t_int)0, (t_array*)NULL)"
                : "new_{$classCName}((t_int)0, (t_array*)NULL)";
        }
        if (empty($argCodes)) {
            return "new_{$classCName}()";
        }
        return "new_{$classCName}(" . implode(', ', $argCodes) . ")";
    }
    // EnumAccessExpr → 返回 static 实例指针（case 访问）或常量引用（const 访问）
    public function visitEnumAccess(EnumAccessExpr $node): string
    {
        // case 访问 → static 实例指针
        if ($this->symbols->hasEnumCase($node->enumName, $node->caseName)) {
            $prefix = self::mangleCName($node->enumName);
            return "&_e_{$prefix}_{$node->caseName}";
        }
        // 枚举常量访问 → #define 引用
        $cName = $this->symbols->getEnumCName($node->enumName);
        if ($cName !== null) {
            return 'TPHP_CONST_' . strtoupper($cName . '_' . $node->caseName);
        }
        // 兜底：当作 case（向后兼容旧路径）
        $prefix = self::mangleCName($node->enumName);
        return "&_e_{$prefix}_{$node->caseName}";
    }

    // ============================================================
    // 控制流
    // ============================================================

    public function visitIfStmt(IfStmtNode $node): string
    {
        $cond = $node->condition->accept($this);
        // 条件上下文：解包 t_var 为 bool（array<mixed> 元素在 if 中使用）
        $cond = $this->unwrapIfMixed($node->condition, $cond, 't_bool');
        $this->scopeDepth++;
        $lines = [];
        $lines[] = "if ({$cond}) {";
        // 类型窄化：if ($v instanceof X) 分支内，$v 视为 tphp_class_X* 类型
        $narrowed = $this->applyTypeNarrowing($node->condition);
        // 保存 declaredVars 快照，使兄弟分支（elseif/else）各自独立声明变量
        //   场景：if (hasFile) { foreach($k=>$v) } else { foreach($k=>$v) }
        //   无快照恢复时，$k 在 then 分支声明后，else 分支不会重复声明 → C 编译报 undeclared
        $savedDeclared = $this->declaredVars;
        foreach ($node->thenBody as $s) $lines[] = $this->ind($s->accept($this));
        $this->restoreTypeNarrowing($narrowed);
        $thenDeclared = $this->declaredVars;
        $lines[] = '}';
        foreach ($node->elseifs as $eif) {
            $econd = $eif->condition->accept($this);
            $econd = $this->unwrapIfMixed($eif->condition, $econd, 't_bool');
            $lines[] = "else if ({$econd}) {";
            $this->declaredVars = $savedDeclared;
            $narrowed = $this->applyTypeNarrowing($eif->condition);
            foreach ($eif->body as $s) $lines[] = $this->ind($s->accept($this));
            $this->restoreTypeNarrowing($narrowed);
            $thenDeclared = array_merge($thenDeclared, $this->declaredVars);
            $lines[] = '}';
        }
        if (!empty($node->elseBody)) {
            $lines[] = 'else {';
            $this->declaredVars = $savedDeclared;
            foreach ($node->elseBody as $s) $lines[] = $this->ind($s->accept($this));
            $thenDeclared = array_merge($thenDeclared, $this->declaredVars);
            $lines[] = '}';
        }
        // 合并所有分支的声明（PHP 函数级作用域，C 块作用域变量在 if 后仍可见）
        $this->declaredVars = array_merge($savedDeclared, $thenDeclared);
        $this->scopeDepth--;
        return implode("\n", $lines);
    }

    /**
     * 检测 if 条件中的 instanceof 模式，应用类型窄化
     *   场景：if ($v instanceof CURLFile) { ... $v->postname ... }
     *   效果：在分支内将 $v 的窄化类型设为 tphp_class_CURLFile
     *   仅处理简单 instanceof 表达式（非 || 联合），&& 链中各 instanceof 也生效
     * @return array 保存的旧窄化值，供 restoreTypeNarrowing 恢复
     */
    private function applyTypeNarrowing(ExprNode $cond): array
    {
        // 递归找 && 链中的 instanceof 子表达式（&& 左右侧均可窄化）
        $allSaved = [];
        $expr = $cond;
        while ($expr instanceof BinaryExpr && $expr->operator === '&&') {
            $allSaved = array_merge($allSaved, $this->applyTypeNarrowingSingle($expr->left));
            $expr = $expr->right;
        }
        $allSaved = array_merge($allSaved, $this->applyTypeNarrowingSingle($expr));
        return $allSaved;
    }

    /**
     * 单个 instanceof 表达式的类型窄化
     * @return array [varName => [old => oldVal, new => newVal]]
     */
    private function applyTypeNarrowingSingle(ExprNode $expr): array
    {
        if (!($expr instanceof BinaryExpr) || $expr->operator !== 'instanceof') {
            return [];
        }
        if (!($expr->left instanceof VariableExpr) || !str_starts_with($expr->left->name, '$')) {
            return [];
        }
        // 右操作数为类名（裸标识符，非 $variable）
        if (!($expr->right instanceof VariableExpr) || str_starts_with($expr->right->name, '$')) {
            return [];
        }
        $className = $expr->right->name;
        $cn = 'tphp_class_' . $className;
        $vn = self::varName($expr->left->name);
        $saved = [$vn => ['old' => $this->narrowedTypes[$vn] ?? null, 'new' => $cn]];
        $this->narrowedTypes[$vn] = $cn;
        return $saved;
    }

    /**
     * 恢复类型窄化到应用前的状态
     */
    private function restoreTypeNarrowing(array $saved): void
    {
        foreach ($saved as $vn => $info) {
            if ($info['old'] === null) {
                unset($this->narrowedTypes[$vn]);
            } else {
                $this->narrowedTypes[$vn] = $info['old'];
            }
        }
    }

    public function visitWhileStmt(WhileStmtNode $node): string
    {
        $cond = $node->condition->accept($this);
        $cond = $this->unwrapIfMixed($node->condition, $cond, 't_bool');
        $this->scopeDepth++;
        $endLabel = '_lp_end_' . (++$this->tmpVarCounter);
        $startLabel = '_lp_start_' . $this->tmpVarCounter;
        $this->loopEndLabelStack[] = $endLabel;
        $this->loopStartLabelStack[] = $startLabel;
        $this->loopContLabelStack[] = $startLabel;  // while 无 step，continue N 跳到 cond 检查
        $lines = [];
        $lines[] = "{$startLabel}:;";
        $lines[] = "while ({$cond}) {";
        foreach ($node->body as $s) $lines[] = $this->ind($s->accept($this));
        $lines[] = '}';
        $lines[] = "{$endLabel}:;";
        $this->scopeDepth--;
        array_pop($this->loopEndLabelStack);
        array_pop($this->loopStartLabelStack);
        array_pop($this->loopContLabelStack);
        return implode("\n", $lines);
    }

    public function visitDoWhileStmt(DoWhileStmtNode $node): string
    {
        $cond = $node->condition->accept($this);
        $this->scopeDepth++;
        $endLabel = '_lp_end_' . (++$this->tmpVarCounter);
        $startLabel = '_lp_start_' . $this->tmpVarCounter;
        $contLabel = '_lp_cont_' . $this->tmpVarCounter;
        $this->loopEndLabelStack[] = $endLabel;
        $this->loopStartLabelStack[] = $startLabel;
        $this->loopContLabelStack[] = $contLabel;  // do-while continue N 跳到 cond 检查前
        $lines = [];
        $lines[] = "{$startLabel}:;";
        $lines[] = 'do {';
        foreach ($node->body as $s) $lines[] = $this->ind($s->accept($this));
        $lines[] = $this->ind("{$contLabel}:;");
        $lines[] = "} while ({$cond});";
        $lines[] = "{$endLabel}:;";
        $this->scopeDepth--;
        array_pop($this->loopEndLabelStack);
        array_pop($this->loopStartLabelStack);
        array_pop($this->loopContLabelStack);
        return implode("\n", $lines);
    }

    public function visitListStmt(ListStmtNode $node): string
    {
        $lines = [];
        $arrName = '_lst_' . (++$this->tmpVarCounter);
        $expr = $node->expr->accept($this);
        // 源是 t_var 变量（如 foreach 值）：从 .value._array 提取 t_array*
        //   场景：foreach ($users as $u) { list("id" => $uid) = $u; }
        //   $u 是 t_var，持有 TYPE_ARRAY，需提取 .value._array
        if ($node->expr instanceof VariableExpr) {
            $vn = self::varName($node->expr->name);
            $vt = $this->varTypes[$vn] ?? '';
            if ($vt === 't_var') {
                $expr = "(({$expr}).value._array)";
            }
        }
        $lines[] = "t_array* {$arrName} = {$expr};";
        // 推断源数组元素类型，用于 list 解构变量类型
        $elemType = 't_int';
        $srcLiteral = null;
        // 源变量名（用于 per-key 类型查询 arrValueTypes）
        $srcVarName = '';
        if ($node->expr instanceof VariableExpr) {
            $vn = self::varName($node->expr->name);
            $elemType = $this->arrElementTypes[$vn] ?? 't_int';
            $srcVarName = $vn;
            // 变量源数组的字面量 AST（若有）— 用于 per-key 精确类型推断
            if (isset($this->arrLiteralAST[$vn])) {
                $srcLiteral = $this->arrLiteralAST[$vn];
            }
            // 源是 t_var (array<mixed>)：元素类型为 t_var，避免错误地按 t_int 解包
            //   场景：foreach ($users as $u) { ["id" => $uid, "name" => $uname] = $u; }
            //   $u 是 t_var，$uid/$uname 应保持 t_var 由后续运算自动转换
            if (($this->varTypes[$vn] ?? '') === 't_var') {
                $elemType = 't_var';
            }
        } elseif ($node->expr instanceof ArrayLiteralExpr) {
            $elemType = $this->inferArrayDeepElementType($node->expr);
            $srcLiteral = $node->expr;
        }
        $this->generateListAssign($lines, $arrName, 0, $node->vars, $elemType, $srcLiteral);
        // Keyed destructuring: ['key' => $var, ...] = $arr
        if (!empty($node->keyedEntries)) {
            $this->generateKeyedAssign($lines, $arrName, $node->keyedEntries, $elemType, $srcLiteral, $srcVarName);
        }
        return implode("\n", $lines);
    }

    /** Generate assignments for keyed list destructuring:
     *  ['key' => $var] = $arr  →  $var = tphp_fn_arr_get_str_int($arr, STR_LIT("key"));
     *  [0 => $var] = $arr      →  $var = tphp_fn_arr_get_int_int($arr, 0);  (整数键名)
     *  支持 per-key 类型推断：混合类型数组（如 ["age"=>30, "name"=>"Alice"]）按 key 单独推断元素类型
     *  @param ArrayLiteralExpr|null $srcLiteral  源数组字面量 AST（用于按 key 查找 entry 推断类型）
     *  @param string                $srcVarName  源变量名（用于查 arrValueTypes[var][key] per-key 类型追踪）
     */
    private function typeToCType(string $tphpType): string
    {
        return match ($tphpType) {
            't_int'      => 't_int',
            't_float'    => 't_float',
            't_string'   => 't_string',
            't_bool'     => 't_bool',
            't_array*'   => 't_array*',
            't_callback' => 't_callback',
            't_var'      => 't_var',
            default      => (str_contains($tphpType, 'tphp_class_') ? $tphpType : 't_int'),
        };
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
        $endLabel = '_lp_end_' . (++$this->tmpVarCounter);
        $startLabel = '_lp_start_' . $this->tmpVarCounter;
        $contLabel = '_lp_cont_' . $this->tmpVarCounter;
        $this->loopEndLabelStack[] = $endLabel;
        $this->loopStartLabelStack[] = $startLabel;
        $this->loopContLabelStack[] = $contLabel;
        $lines = [];
        $lines[] = "{$startLabel}:;";
        $lines[] = "for ({$init}; {$cond}; {$step}) {";
        foreach ($node->body as $s) $lines[] = $this->ind($s->accept($this));
        $lines[] = $this->ind("{$contLabel}:;");
        $lines[] = '}';
        $lines[] = "{$endLabel}:;";
        $this->scopeDepth--;
        array_pop($this->loopEndLabelStack);
        array_pop($this->loopStartLabelStack);
        array_pop($this->loopContLabelStack);
        return implode("\n", $lines);
    }

    public function visitForeachStmt(ForeachStmtNode $node): string
    {
        // 生成器迭代分支：iterable 类型含 tphp_class_Generator
        $iterType = $this->inferType($node->array);
        if (str_contains($iterType, 'tphp_class_Generator')) {
            return $this->emitGeneratorForeach($node);
        }

        // stdClass foreach: 遍历内部 t_array 的动态属性
        if (str_contains($iterType, 'tphp_class_stdClass')) {
            return $this->emitStdClassForeach($node);
        }

        // 泛型数组分支：t_arr_int*/t_arr_str*/t_arr_float*/t_arr_bool*/t_arr_ptr*
        //   直接访问 entries[i].val，无需 t_var 包装和类型检查
        $genElemCType = self::genericArrayElemCType($iterType);
        if ($genElemCType !== null) {
            return $this->emitGenericArrayForeach($node, $iterType, $genElemCType);
        }

        $arr  = $this->arrayArgCode($node->array, $node->array->accept($this));
        $cnt  = '_fc_' . (++$this->tmpVarCounter);
        $idx  = '_fi_' . (++$this->tmpVarCounter);
        $valVar = ltrim($node->valueVar, '$');
        $keyVar = $node->keyVar ? ltrim($node->keyVar, '$') : '';

        // 推断数组元素类型
        $elemType = 't_int';
        if ($node->array instanceof VariableExpr) {
            $arrVarName = self::varName($node->array->name);
            $elemType = $this->arrElementTypes[$arrVarName] ?? 't_int';
            // t_var 变量持有数组：元素统一为 t_var（array<mixed> 语义）
            //   arrElementTypes 可能记录实际值类型（如 t_array*），但 t_var 数组
            //   的元素在 C 层都是 t_var，foreach 取值需用 *_eval 直接取 t_var
            if (($this->varTypes[$arrVarName] ?? '') === 't_var') {
                $elemType = 't_var';
            }
            // 若数组无 int-key 元素类型追踪，尝试用 per-key 追踪默认值
            if ($elemType === 't_int' && isset($this->arrValueTypes[$arrVarName])) {
                $values = $this->arrValueTypes[$arrVarName];
                if (!empty($values)) $elemType = reset($values);
            }
            // 注解常量数组：元素类型为 tphp_class_AnnotationEntry*
            if ($elemType === 't_int' && isset($this->annotationRegistry[$arrVarName])) {
                $elemType = 'tphp_class_AnnotationEntry*';
            }
        } elseif ($node->array instanceof PropertyAccessExpr) {
            // foreach 实例属性数组：foreach ($this->prop as $v) 或 foreach ($obj->prop as $v)
            $key = $this->propArrElemKey($node->array);
            if ($key !== null && isset($this->propArrElementTypes[$key])) {
                $elemType = $this->propArrElementTypes[$key];
            } else {
                // 属性数组未注册元素类型时，默认 t_var（mixed 语义）
                //   PHP 数组为动态类型，默认 t_int 会导致对象/字符串元素被错误解包
                //   场景：$handle->postFields 含 CURLFile/CURLStringFile/string
                $elemType = 't_var';
            }
        } elseif ($node->array instanceof CallExpr
            && $node->array->name === 'cases'
            && $node->array->callee instanceof VariableExpr) {
            // foreach (Color::cases() as $c) — 枚举 cases() 返回 array<enum>
            $enumCName = $this->symbols->resolveEnumCName($node->array->callee->name);
            if ($enumCName !== null) {
                $elemType = $enumCName . '*';
            }
        }
        // 规范化元素类型名
        if (str_contains($elemType, 'tphp_class_') && !str_ends_with($elemType, '*')) {
            $elemType .= '*';
        }
        if (str_contains($elemType, 'tphp_enum_') && !str_ends_with($elemType, '*')) {
            $elemType .= '*';
        }

        // 混合类型数组检测：[1, "foo", 2.5] → 元素为 t_var
        //   inferArrayElementType 对混合数组会锁定为某个具体类型（如 t_string），
        //   导致 int/float 元素被错误解包为空字符串。
        //   仅当数组字面量包含多种不同类型时，才覆盖为 t_var（万能数组语义）。
        if ($node->array instanceof VariableExpr) {
            $arrVarName2 = self::varName($node->array->name);
            if (isset($this->arrLiteralAST[$arrVarName2])
                && $this->isMixedArrayLiteral($this->arrLiteralAST[$arrVarName2])) {
                $elemType = 't_var';
            }
        }

        // Mark vars as declared (after declaration check)
        $needKeyDecl = ($keyVar && !isset($this->declaredVars[$keyVar]));
        $needValDecl = !isset($this->declaredVars[$valVar]);

        $this->declaredVars[$valVar] = true;
        // 已声明的标量/数组/回调变量保持原类型（foreach 复用已有变量时不改类型）
        //   场景：$v = 42; foreach ($arr as $v) — $v 仍是 t_int，valRead 用 t_int 解包
        $existingValT = $this->varTypes[$valVar] ?? null;
        if ($existingValT !== null
            && in_array($existingValT, ['t_int', 't_float', 't_string', 't_bool', 't_array*', 't_callback'], true)) {
            // 保持已有类型，不覆盖为 $elemType
        } else {
            $this->varTypes[$valVar] = $elemType;
        }
        // 注解常量数组：记录 $v 的来源注解名（供 $v->call() 运行时调度使用）
        if ($elemType === 'tphp_class_AnnotationEntry*' && $node->array instanceof VariableExpr) {
            $arrVarName = self::varName($node->array->name);
            if (isset($this->annotationRegistry[$arrVarName])) {
                $this->varAnnotSource[$valVar] = $arrVarName;
            }
        }
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
                // array<mixed> 不追踪 arrValueTypes，回退到字面量 AST 检测字符串键
                //   场景：$map = ["name" => "Alice"]; foreach ($map as $k => $v)
                //   $map 是 array<mixed>（t_array*），但键是字符串，需用 t_string 提取
                if (!$hasStrKey && isset($this->arrLiteralAST[$arrVarName])) {
                    foreach ($this->arrLiteralAST[$arrVarName]->entries as $entry) {
                        if ($entry->key instanceof StringLiteralExpr) {
                            $hasStrKey = true;
                            break;
                        }
                    }
                }
            }
            $keyType = $hasStrKey ? 't_string' : 't_int';
            // 已声明的 key 变量保持原类型（foreach 复用已有 key 变量时不改类型）
            if (isset($this->varTypes[$keyVar])
                && in_array($this->varTypes[$keyVar], ['t_int', 't_string'], true)) {
                $keyType = $this->varTypes[$keyVar];
            } else {
                $this->varTypes[$keyVar] = $keyType;
            }
        }

        // 根据元素类型生成值读取代码
        //   若 $valVar 已声明为具体类型（如 t_int），用该类型从 t_var 元素中提取值
        //   场景：$v = 42; foreach ($arr as $v) — $v 已是 t_int，需 VAR_AS_INT 解包
        //   否则用 $elemType（数组元素推导类型）生成 valRead
        $effectiveElemType = $elemType;
        if (isset($this->varTypes[$valVar]) && $this->varTypes[$valVar] !== 't_var') {
            $existingType = $this->varTypes[$valVar];
            // 仅当已有类型是标量/数组/回调时覆盖（避免类类型与 elemType 冲突）
            if (in_array($existingType, ['t_int', 't_float', 't_string', 't_bool', 't_array*', 't_callback'], true)) {
                $effectiveElemType = $existingType;
            }
        }
        $valRead = match ($effectiveElemType) {
            't_float'    => "(_eval->type == TYPE_FLOAT) ? (t_float)_eval->value._float : 0.0",
            't_string'   => "(_eval->type == TYPE_STRING) ? _eval->value._string : ((t_string){NULL, 0})",
            't_bool'     => "(_eval->type == TYPE_BOOL) ? (t_bool)_eval->value._bool : false",
            't_array*'   => "(_eval->type == TYPE_ARRAY) ? _eval->value._array : NULL",
            't_callback' => "(_eval->type == TYPE_CALLBACK) ? _eval->value._callback : ((t_callback){NULL, NULL})",
            't_var'      => "*_eval",  // array<mixed>：直接取 t_var，由 var_dump 等按 type 标签分发
            default      => (str_contains($effectiveElemType, 'tphp_class_') || str_contains($effectiveElemType, 'tphp_enum_')
                ? "(_eval->type == TYPE_OBJECT) ? (({$effectiveElemType})_eval->value._ptr) : NULL"
                : "(_eval->type == TYPE_INT) ? (t_int)_eval->value._int : 0"),
        };

        $valDecl = match ($elemType) {
            't_float'  => 't_float',
            't_string' => 't_string',
            't_bool'   => 't_bool',
            't_array*' => 't_array*',
            't_callback' => 't_callback',
            't_var'    => 't_var',  // array<mixed> 元素类型
            default    => (str_contains($elemType, 'tphp_class_') || str_contains($elemType, 'tphp_enum_') ? $elemType : 't_int'),
        };

        $keyType = $keyVar ? ($this->varTypes[$keyVar] ?? 't_int') : '';
        $endLabel = '_lp_end_' . (++$this->tmpVarCounter);
        $startLabel = '_lp_start_' . $this->tmpVarCounter;
        $contLabel = '_lp_cont_' . $this->tmpVarCounter;
        $this->loopEndLabelStack[] = $endLabel;
        $this->loopStartLabelStack[] = $startLabel;
        $this->loopContLabelStack[] = $contLabel;
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
        $lines[] = "{$startLabel}:;";
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
        $lines[] = $this->ind("{$contLabel}:;");
        $lines[] = '}';
        $lines[] = "{$endLabel}:;";
        array_pop($this->loopEndLabelStack);
        array_pop($this->loopStartLabelStack);
        array_pop($this->loopContLabelStack);
        return implode("\n", $lines);
    }

    /** 生成 stdClass foreach 代码：遍历内部 t_array 的动态属性 */
    private function emitStdClassForeach(ForeachStmtNode $node): string
    {
        $objCode = $node->array->accept($this);
        // 提取 props 指针（stdClass 结构体的动态属性表）
        $propsCode = "((tphp_class_stdClass*)({$objCode}))->props";

        $valVar = ltrim($node->valueVar, '$');
        $keyVar = $node->keyVar ? ltrim($node->keyVar, '$') : '';

        $needValDecl = !isset($this->declaredVars[$valVar]);
        $needKeyDecl = ($keyVar && !isset($this->declaredVars[$keyVar]));

        $this->declaredVars[$valVar] = true;
        $this->varTypes[$valVar] = 't_var';
        if ($keyVar) {
            $this->declaredVars[$keyVar] = true;
            $this->varTypes[$keyVar] = 't_string';
        }

        $idx = '_fi_' . (++$this->tmpVarCounter);
        $endLabel = '_lp_end_' . (++$this->tmpVarCounter);
        $startLabel = '_lp_start_' . $this->tmpVarCounter;
        $contLabel = '_lp_cont_' . $this->tmpVarCounter;
        $this->loopEndLabelStack[] = $endLabel;
        $this->loopStartLabelStack[] = $startLabel;
        $this->loopContLabelStack[] = $contLabel;

        $lines = [];
        if ($needKeyDecl) {
            $lines[] = "t_string {$keyVar};";
        }
        if ($needValDecl) {
            $lines[] = "t_var {$valVar};";
        }
        $lines[] = "{$startLabel}:;";
        $lines[] = "for (int {$idx} = 0; {$idx} < tphp_fn_arr_count({$propsCode}); {$idx}++) {";
        $lines[] = $this->ind("if ({$propsCode} == NULL) break;");
        $lines[] = $this->ind("const t_arr_entry* _se = &{$propsCode}->entries[{$idx}];");
        if ($keyVar) {
            $lines[] = $this->ind("{$keyVar} = (_se->key.type == TYPE_STRING) ? _se->key.value._string : ((t_string){NULL, 0});");
        }
        $lines[] = $this->ind("{$valVar} = _se->val;");
        $this->scopeDepth++;
        foreach ($node->body as $s) $lines[] = $this->ind($s->accept($this));
        $this->scopeDepth--;
        $lines[] = $this->ind("{$contLabel}:;");
        $lines[] = '}';
        $lines[] = "{$endLabel}:;";

        array_pop($this->loopEndLabelStack);
        array_pop($this->loopStartLabelStack);
        array_pop($this->loopContLabelStack);
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

    /**
     * 泛型数组 foreach：直接访问 entries[i].val，无需 t_var 包装。
     *
     * @param ForeachStmtNode $node     foreach 节点
     * @param string          $arrCType 数组 C 类型（如 t_arr_int*）
     * @param string          $elemCType 元素 C 类型（如 t_int）
     */
    private function emitGenericArrayForeach(ForeachStmtNode $node, string $arrCType, string $elemCType): string
    {
        $arr    = $node->array->accept($this);
        $idx    = '_fi_' . (++$this->tmpVarCounter);
        $valVar = ltrim($node->valueVar, '$');
        $keyVar = $node->keyVar ? ltrim($node->keyVar, '$') : '';

        // 值变量类型与声明
        $valDecl = $elemCType;
        // void* 元素（array<array<T>>/array<Foo>）→ 保持原指针类型
        if ($elemCType === 'void*') {
            // 嵌套数组或对象数组：元素类型需要从上下文推断
            // 暂时回退到 t_array* 或对象指针类型
            $valDecl = 't_array*';  // 简化：嵌套数组元素当作 t_array*
        }

        $needValDecl = !isset($this->declaredVars[$valVar]);
        $needKeyDecl = ($keyVar && !isset($this->declaredVars[$keyVar]));

        $this->declaredVars[$valVar] = true;
        $this->varTypes[$valVar] = $valDecl;
        if ($keyVar) {
            $this->declaredVars[$keyVar] = true;
            $this->varTypes[$keyVar] = 't_int';  // 泛型数组 key 统一为 int（连续下标）
        }

        $endLabel   = '_lp_end_' . (++$this->tmpVarCounter);
        $startLabel = '_lp_start_' . $this->tmpVarCounter;
        $contLabel  = '_lp_cont_' . $this->tmpVarCounter;
        $this->loopEndLabelStack[] = $endLabel;
        $this->loopStartLabelStack[] = $startLabel;
        $this->loopContLabelStack[] = $contLabel;

        $lines = [];
        if ($needKeyDecl) {
            $lines[] = "t_int {$keyVar};";
        }
        if ($needValDecl) {
            $lines[] = "{$valDecl} {$valVar};";
        }
        $lines[] = "{$startLabel}:;";
        $lines[] = "for (int {$idx} = 0; {$idx} < {$arr}->length; {$idx}++) {";
        if ($keyVar) {
            $lines[] = $this->ind("{$keyVar} = (t_int){$idx};");
        }
        // 直接读取 entries[idx].val，无需类型检查（泛型数组类型安全）
        $lines[] = $this->ind("{$valVar} = {$arr}->entries[{$idx}].val;");
        $this->scopeDepth++;
        foreach ($node->body as $s) $lines[] = $this->ind($s->accept($this));
        $this->scopeDepth--;
        $lines[] = $this->ind("{$contLabel}:;");
        $lines[] = '}';
        $lines[] = "{$endLabel}:;";
        array_pop($this->loopEndLabelStack);
        array_pop($this->loopStartLabelStack);
        array_pop($this->loopContLabelStack);
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

        // int/bool switch：若含动态 case 值（变量/表达式），退化为 if-goto 链以支持 PHP 语义
        if ($this->hasDynamicCases($node->cases)) {
            return $this->generateDynamicSwitch($condCode, $node->cases);
        }

        // int/bool switch → 直接 C switch（所有 case 均为常量）
        $endLabel = '_sw_end_' . (++$this->tmpVarCounter);
        $this->loopEndLabelStack[] = $endLabel;  // switch 也压栈，支持 break N; 跳出
        $this->loopStartLabelStack[] = $endLabel;  // switch 中 continue 等价于 break
        $this->loopContLabelStack[] = $endLabel;
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
        $lines[] = "{$endLabel}:;";
        array_pop($this->loopEndLabelStack);
        array_pop($this->loopStartLabelStack);
        array_pop($this->loopContLabelStack);
        return implode("\n", $lines);
    }

    /**
     * 检测 int/bool switch 中是否含动态 case 值（非字面量）。
     * PHP 允许 case $var: / case foo(): 等动态值；C switch 的 case 必须是常量表达式。
     */
    private function hasDynamicCases(array $cases): bool
    {
        foreach ($cases as $case) {
            if ($case->value === null) continue;
            $v = $case->value;
            // 常量 case：整数/布尔字面量、枚举 case 访问
            if ($v instanceof IntLiteralExpr
                || $v instanceof BoolLiteralExpr
                || $v instanceof EnumAccessExpr) {
                continue;
            }
            return true;
        }
        return false;
    }

    /**
     * 将 int/bool switch 转为 if-goto 链，支持动态 case 值并保留 fall-through 语义。
     * 每个 case 对应一个 label；匹配则跳入，无 break 则顺序执行到下一个 case label。
     * default 单独处理：无 case 匹配时跳到 default label。
     */
    private function generateDynamicSwitch(string $condCode, array $cases): string
    {
        $lines = [];
        $swId = (++$this->tmpVarCounter);
        $endLabel = '_sw_end_' . $swId;
        $defaultLabel = '_sw_default_' . $swId;
        $this->loopEndLabelStack[] = $endLabel;
        $this->loopStartLabelStack[] = $endLabel;  // switch 中 continue 等价于 break
        $this->loopContLabelStack[] = $endLabel;

        $hasDefault = false;
        // 1. 匹配检测：if ((cond) == (val)) goto case_label;
        foreach ($cases as $i => $case) {
            if ($case->value !== null) {
                $valCode = $case->value->accept($this);
                $label = '_sw_case_' . $swId . '_' . $i;
                $lines[] = "if (({$condCode}) == ({$valCode})) goto {$label};";
            } else {
                $hasDefault = true;
            }
        }
        // 无匹配 → default 或跳到结尾
        $lines[] = $hasDefault ? "goto {$defaultLabel};" : "goto {$endLabel};";

        // 2. case body：label + stmts（break → goto end，无 break 则 fall-through 到下一 case）
        foreach ($cases as $i => $case) {
            if ($case->value !== null) {
                $label = '_sw_case_' . $swId . '_' . $i;
                $lines[] = "{$label}:;";
            } else {
                $lines[] = "{$defaultLabel}:;";
            }
            foreach ($case->body as $s) {
                if ($s instanceof BreakStmtNode) {
                    $lines[] = $this->ind("goto {$endLabel};");
                } else {
                    $lines[] = $this->ind($s->accept($this));
                }
            }
        }
        $lines[] = "{$endLabel}:;";
        array_pop($this->loopEndLabelStack);
        array_pop($this->loopStartLabelStack);
        array_pop($this->loopContLabelStack);
        return implode("\n", $lines);
    }

    /**
     * 将 switch 字符串转为 if-goto 链，保留 C switch 的 fall-through 语义。
     * 每个 case 对应一个 label；匹配则跳入，无 break 则顺序执行到下一个 case label。
     * default 单独处理：无 case 匹配时跳到 default label（default 可在任意位置）。
     */
    private function generateStringSwitch(string $condCode, array $cases): string
    {
        $lines = [];
        $swId = (++$this->tmpVarCounter);
        $endLabel = '_sw_end_' . $swId;
        $defaultLabel = '_sw_default_' . $swId;
        $this->loopEndLabelStack[] = $endLabel;
        $this->loopStartLabelStack[] = $endLabel;  // switch 中 continue 等价于 break
        $this->loopContLabelStack[] = $endLabel;

        $hasDefault = false;
        // 1. 匹配检测：if (str_eq(cond, val)) goto case_label;
        foreach ($cases as $i => $case) {
            if ($case->value !== null) {
                $valCode = $case->value->accept($this);
                $label = '_sw_case_' . $swId . '_' . $i;
                $lines[] = "if (tphp_rt_str_eq({$condCode}, {$valCode})) goto {$label};";
            } else {
                $hasDefault = true;
            }
        }
        // 无匹配 → default 或跳到结尾
        $lines[] = $hasDefault ? "goto {$defaultLabel};" : "goto {$endLabel};";

        // 2. case body：label + stmts（break → goto end，无 break 则 fall-through 到下一 case）
        foreach ($cases as $i => $case) {
            if ($case->value !== null) {
                $label = '_sw_case_' . $swId . '_' . $i;
                $lines[] = "{$label}:;";
            } else {
                $lines[] = "{$defaultLabel}:;";
            }
            foreach ($case->body as $s) {
                if ($s instanceof BreakStmtNode) {
                    $lines[] = $this->ind("goto {$endLabel};");
                } else {
                    $lines[] = $this->ind($s->accept($this));
                }
            }
        }
        $lines[] = "{$endLabel}:;";
        array_pop($this->loopEndLabelStack);
        array_pop($this->loopStartLabelStack);
        array_pop($this->loopContLabelStack);
        return implode("\n", $lines);
    }

    public function visitBreakStmt(BreakStmtNode $node): string
    {
        if ($node->level <= 1) return 'break;';
        // break N; → goto 第 N 层外层结构的 end label
        $idx = count($this->loopEndLabelStack) - $node->level;
        if ($idx < 0 || !isset($this->loopEndLabelStack[$idx])) {
            return 'break;';  // 栈不足时退化为 break（防御性）
        }
        return 'goto ' . $this->loopEndLabelStack[$idx] . ';';
    }

    public function visitContinueStmt(ContinueStmtNode $node): string
    {
        if ($node->level <= 1) return 'continue;';
        // continue N; → goto 第 N 层外层循环的 continue label（step 之前）
        $idx = count($this->loopContLabelStack) - $node->level;
        if ($idx < 0 || !isset($this->loopContLabelStack[$idx])) {
            return 'continue;';  // 栈不足时退化为 continue（防御性）
        }
        return 'goto ' . $this->loopContLabelStack[$idx] . ';';
    }

    public function visitTryStmt(TryStmtNode $node): string
    {
        $lines = [];
        $lines[] = 'TP_TRY';
        $this->scopeDepth++;
        foreach ($node->tryBody as $s) {
            $lines[] = '    ' . $s->accept($this);
        }
        $this->scopeDepth--;

        // 多 catch 子句：每个生成 TP_CATCH_EX(var, Type) 或 TP_CATCH_EX_MULTI(var, cond)
        // 最后无类型兜底用 TP_CATCH_ANY（捕获非对象异常如 tp_throw("str")）
        $hasCatch = !empty($node->catchClauses);
        $hasObjCatch = false;
        foreach ($node->catchClauses as $clause) {
            $cv = $clause['var'];
            $ct = $clause['type'];
            $this->declaredVars[$cv] = true;
            // 多异常 catch (A | B $e)：所有类型必须为已声明的 class
            if (is_array($ct)) {
                $types = $ct;
                $conds = [];
                $allClass = true;
                foreach ($types as $t) {
                    $resolvedCn = $this->symbols->resolveClass($t);
                    $isClass = $resolvedCn !== null || $t === 'Exception' || $this->symbols->hasClass('tphp_class_' . $t);
                    if (!$isClass) { $allClass = false; break; }
                    $conds[] = 'tp_obj_is_a(_tp_ex_top->ex_obj, &_class_tphp_class_' . $t . ')';
                }
                if ($allClass && !empty($conds)) {
                    $this->varTypes[$cv] = 'tphp_class_Exception*';
                    $cond = implode(' || ', $conds);
                    $lines[] = 'TP_CATCH_EX_MULTI(' . $cv . ', (' . $cond . '))';
                    $hasObjCatch = true;
                } else {
                    // 含未知类型 → 兜底字符串消息
                    $this->varTypes[$cv] = 't_string';
                    $lines[] = 'TP_CATCH_ANY(' . $cv . ')';
                }
            } else {
                // 单异常 catch (Type $e)
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
        $this->checkExceptionReturnType();
        return $this->genThrowCode($node->expr) . ';';
    }

    /** 检查当前函数/方法的返回类型是否声明了 |Exception */
    private function checkExceptionReturnType(): void
    {
        $rt = $this->currentPhpRetType;
        if ($rt === '') return; // 全局作用域或未追踪的上下文，跳过
        // Main::main 是程序入口，try/catch 可捕获异常，无需 |Exception 声明
        if ($this->phpClassName === 'Main' && $this->currentMethodName === 'main') return;
        if (!str_contains($rt, '|')) {
            $fn = $this->currentFuncName !== '' ? $this->currentFuncName : ($this->currentMethodName !== '' ? $this->currentMethodName : '<anonymous>');
            throw new \LogicException(
                "Function/method '{$fn}' contains throw/error() but return type does not declare |Exception. "
                . "Expected: {$rt}|Exception, got: {$rt}"
            );
        }
        $parts = explode('|', $rt);
        $hasExc = false;
        foreach ($parts as $p) {
            if ($this->symbols->isExceptionSubclass($p)) { $hasExc = true; break; }
        }
        if (!$hasExc) {
            $fn = $this->currentFuncName !== '' ? $this->currentFuncName : ($this->currentMethodName !== '' ? $this->currentMethodName : '<anonymous>');
            throw new \LogicException(
                "Function/method '{$fn}' contains throw/error() but return type does not declare |Exception. "
                . "Expected: {$rt}|Exception, got: {$rt}"
            );
        }
    }

    /** 生成 throw 的 C 宏调用（不带分号），供 visitThrowStmt 和 visitThrowExpr 复用 */
    private function genThrowCode(ExprNode $expr): string
    {
        $code = $expr->accept($this);
        // throw new Exception(...) 或 throw new Exception子类(...) → tp_throw_ex()
        if ($expr instanceof NewExpr) {
            return "tp_throw_ex({$code})";
        }
        // throw $exceptionVar (Exception 子类类型) → tp_throw_ex
        $type = $this->inferType($expr);
        if (self::isClassCType($type) && str_ends_with($type, '*')) {
            return "tp_throw_ex({$code})";
        }
        // throw "string" → tp_throw(STR_PTR_V(msg))
        if ($type === 't_string') {
            return "tp_throw(STR_PTR_V({$code}))";
        }
        return "tp_throw((char*)(uintptr_t)(" . $code . "))";
    }

    /** throw 表达式（PHP 8.0+）：出现在表达式位置的 throw
     *  tp_throw_ex/tp_throw 是 do-while 宏，不能直接嵌入表达式位置
     *  利用 TCC 的 GNU 语句表达式扩展包装：({ throw_code; 0; })
     *  throw 永不返回，0 是死代码占位值 */
    public function visitThrowExpr(ThrowExprNode $node): string
    {
        $this->checkExceptionReturnType();
        $throwCode = $this->genThrowCode($node->expr);
        return "({ {$throwCode}; 0; })";
    }

    /**
     * or {} 错误处理块 — 直接调用时报错
     *
     * OrBlockExpr 仅在语句级上下文（AssignStmt 右式 / ExprStmt 表达式）有效，
     * 由 visitAssignStmt / visitExprStmt 检测并委托到 generateAssignWithOrBlock /
     * generateExprWithOrBlock 处理。直接调用 visitOrBlock 表示 OrBlockExpr 出现在
     * 非法位置（如嵌套表达式内），编译期捕获。
     */
    public function visitOrBlock(OrBlockExpr $node): string
    {
        throw new \RuntimeException(
            "or {} block can only appear at statement level (e.g., \$x = foo() or { ... }; or foo() or { ... };). "
            . "Cannot nest or {} block inside other expressions."
        );
    }

    /**
     * 生成 $var = (expr) or { ... }; 的 C 代码
     *
     * 等价于：
     *   TP_TRY {
     *       <var_decl> = <inner_expr>;
     *   }
     *   TP_CATCH_EX(err, Exception) {
     *       <or_body>
     *   }
     *   TP_END_TRY
     *
     * 注意：若 or 块未 return/throw，var 在 catch 路径下未赋值（C 编译器会警告）
     */
    private function generateExprWithOrBlock(OrBlockExpr $orBlock): string
    {
        $innerExpr = $orBlock->expr;
        $innerCode = $innerExpr->accept($this);

        // 注册 $err 为 Exception* 类型
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
        $lines = [];
        $lines[] = $this->ind('TP_TRY');
        $this->scopeDepth++;
        $lines[] = $this->ind("(void){$innerCode};");
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
    public function visitGotoStmt(GotoStmtNode $node): string { return 'goto ' . $node->label . ';'; }

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
        // .= 是 PHP 字符串拼接赋值，C 无对应操作符，转译为 tphp_rt_str_concat
        if ($node->operator === '.=') {
            $vs = $this->castToStr($node->value);
            // t_var 目标：需先解包为 t_string 参与 concat，再用 VAR_STRING 包装回去
            //   场景：$path = $urlInfo["path"]; $path .= "?"; — $path 是 t_var
            $tt = $this->inferType($node->target);
            if ($tt === 't_var') {
                return "{$t} = VAR_STRING(tphp_rt_str_concat(tphp_fn_strval({$t}), {$vs}))";
            }
            return "{$t} = tphp_rt_str_concat({$t}, {$vs})";
        }
        // ??= 是 null 合并赋值：仅在目标为 null 时才赋值（惰性求值右式）
        if ($node->operator === '??=') {
            return $this->generateCoalesceAssign($node);
        }
        // t_var 解包：array<mixed> 元素（t_var）参与算术复合赋值时，
        //   需按目标类型解包为标量，避免 struct 直接参与算术运算导致编译错误
        //   场景：$s += $b[$i]; — $b 是 array<mixed>，$b[$i] 是 t_var，$s 是 t_int
        $vt = $this->inferType($node->value);
        if ($vt === 't_var') {
            $expect = $this->inferType($node->target);
            if ($expect === 't_var' || $expect === '') {
                $expect = 't_int';  // 两侧均为 t_var 时按 int 解包（PHP 数值语义）
            }
            $v = $this->unwrapIfMixed($node->value, $v, $expect);
        }
        return "{$t} {$node->operator} {$v}";
    }

    /**
     * 生成 ??= 复合赋值代码：仅当目标为 null 时赋值
     *   - t_var:        if (t.type == TYPE_NULL) t = v;
     *   - void* (obj):  if (t == NULL) t = v;
     *   - array access: if (!array_key_exists(...)) arr_set(...);
     *   - 其他值类型:   AOT 已知非 null，no-op
     */
    private function generateCoalesceAssign(CompoundAssignExpr $node): string
    {
        $lt = $this->inferType($node->target);
        // 数组键访问：需要运行时检查键是否存在
        if ($node->target instanceof ArrayAccessExpr) {
            $aa = $node->target;
            $arrCode = $aa->array->accept($this);
            $idxCode = $aa->index->accept($this);
            $idxType = $this->inferType($aa->index);
            $v = $node->value->accept($this);
            if ($idxType === 't_string' || $aa->index instanceof StringLiteralExpr) {
                $existsCheck = "tphp_fn_array_key_exists_str({$idxCode}, {$arrCode})";
                // 键不存在时设置：转译为 if (!exists) arr_set_str(arr, idx, v)
                $valCode = $this->wrapArrVal($node->value, $v);
                return "if (!{$existsCheck}) { tphp_fn_arr_set_str({$arrCode}, {$idxCode}, {$valCode}); }";
            }
            $existsCheck = "tphp_fn_array_key_exists_int((t_int)({$idxCode}), {$arrCode})";
            $valCode = $this->wrapArrVal($node->value, $v);
            return "if (!{$existsCheck}) { tphp_fn_arr_set_int({$arrCode}, (t_int)({$idxCode}), {$valCode}); }";
        }
        // t_var: 运行时 TYPE_NULL 检查
        if ($lt === 't_var') {
            $t = $node->target->accept($this);
            $v = $this->wrapTvarAssign($node->value, $node->value->accept($this));
            return "if ({$t}.type == TYPE_NULL) { {$t} = {$v}; }";
        }
        // void* (nullable 对象指针): NULL 检查
        if ($lt === 'void*') {
            $t = $node->target->accept($this);
            $v = $node->value->accept($this);
            return "if ({$t} == NULL) { {$t} = {$v}; }";
        }
        // 其他值类型: AOT 已知非 null，no-op（返回空语句）
        return "/* ??= on non-nullable type: no-op */";
    }

    /** 将表达式包装为 t_var 联合体值（用于数组赋值） */
    private function generateCEntry(): string
    {
        $isAndroid = ($this->targetOS === 'android');
        $entryName = $isAndroid ? 'tphp_android_main' : 'main';
        $lines = [
            "/* ── C entry: {$entryName}() ─────────────────────────── */",
            "int {$entryName}(int argc, char* argv[]) {",
            $this->ind("tphp_rt_init();"),
        ];
        // PDO 驱动自动注册（类似 PHP MINIT，在用户代码之前）
        foreach ($this->pdoDriverInits as $initFn) {
            $lines[] = $this->ind("{$initFn}();");
        }
        // 注解常量初始化（在用户代码之前填充）
        foreach ($this->annotationInitFns as $initFn) {
            $lines[] = $this->ind("{$initFn}();");
        }
        $lines[] = $this->ind("t_array* _argv = tphp_rt_build_argv(argc, argv);");
        $lines[] = $this->ind("{$this->className}* _main = new_{$this->className}((t_int)argc, _argv);");
        $lines[] = $this->ind("if (_main == NULL) { tphp_fn_arr_free(_argv); return 1; }");
        $lines[] = $this->ind("{$this->className}_main(_main);");
        if ($isAndroid) {
            // Android NativeActivity 模式：sokol_main 返回后事件循环才启动，
            // 用户闭包可能通过 $this 捕获 Main 对象，不能在此释放。
            // 应用退出时进程直接终止，无需手动清理。
            // _argv 也保持存活（闭包可能通过 $argv 访问）
            $lines[] = $this->ind("/* Android: keep _main/_argv alive — closures capture \$this */");
        } else {
            $lines[] = $this->ind("tp_obj_release(_main);");
            $lines[] = $this->ind("tphp_fn_arr_free(_argv);");
        }
        $lines[] = $this->ind("return 0;");
        $lines[] = "}";
        return implode("\n", $lines);
    }

    /**
     * 静态属性文件作用域初始化器
     *   - 标量: ` = 42` / ` = 3.14` / ` = true`
     *   - 字符串字面量: ` = STR_LIT("hello")`
     *   - 无默认值: ``（零初始化由 C 文件作用域 static 保证）
     *   - 数组/对象: 不支持文件作用域初始化（需运行时），返回空串
     */
    private function staticPropInitializer(string $cType, PropertyDeclNode $prop): string
    {
        if ($prop->default === null) return '';
        $def = $prop->default;
        if ($cType === 't_string' && $def instanceof StringLiteralExpr) {
            $val = str_replace('"', '\\"', $def->value);
            return " = STR_LIT(\"{$val}\")";
        }
        if ($def instanceof IntLiteralExpr) {
            return " = {$def->value}";
        }
        if ($def instanceof FloatLiteralExpr) {
            $fv = $def->value;
            return ' = ' . (($fv == (float)(int)$fv) ? sprintf('%.1f', $fv) : rtrim(rtrim(sprintf('%.15g', $fv), '0'), '.'));
        }
        if ($def instanceof BoolLiteralExpr) {
            return ' = ' . ($def->value ? 'true' : 'false');
        }
        // 数组/对象/复杂表达式默认值：文件作用域无法初始化，留空（零初始化）
        //   用户可在构造函数或静态 init 方法中手动赋值
        return '';
    }

    // ============================================================
    private function methodDecl(MethodNode $m): string
    {
        $ret = self::mapType($m->returnType);
        $params = array_map(fn($p) => $this->visitParam($p), $m->params);
        // 静态方法签名省略 self 参数（AOT: 编译期已知，无 this 指针）
        if ($m->isStatic) {
            return "{$ret} {$this->className}_{$m->name}(" . (empty($params) ? 'void' : implode(', ', $params)) . ')';
        }
        return "{$ret} {$this->className}_{$m->name}({$this->className}* self" .
            (empty($params) ? '' : ', ' . implode(', ', $params)) . ')';
    }

    private function methodImpl(MethodNode $m): string { return $this->methodDecl($m); }

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
    private function inferArrayAccessActualType(ArrayAccessExpr $expr): ?string
    {
        // 字符串键：查 arrValueTypes / arrElementTypes
        if ($expr->index instanceof StringLiteralExpr && $expr->array instanceof VariableExpr) {
            $arrName = self::varName($expr->array->name);
            // array<mixed>（t_var 变量持有数组）：元素统一为 t_var
            //   arrElementTypes 可能记录实际值类型（如 t_array*），但 t_var 数组
            //   的元素在 C 层都是 t_var（运行时通过 VAR_ARRAY 包装）
            if (($this->varTypes[$arrName] ?? '') === 't_var'
                || ($this->arrElementTypes[$arrName] ?? null) === 't_var') {
                return 't_var';
            }
            $keyStr  = $expr->index->value;
            if (isset($this->arrValueTypes[$arrName][$keyStr])) {
                return $this->arrValueTypes[$arrName][$keyStr];
            }
            return $this->arrElementTypes[$arrName] ?? null;
        }
        // 整数键：查 arrElementTypes
        if ($expr->array instanceof VariableExpr) {
            $arrName = self::varName($expr->array->name);
            // t_var 变量持有数组：元素统一为 t_var（与 inferArrayAccessInnerType 一致）
            if (($this->varTypes[$arrName] ?? '') === 't_var') {
                return 't_var';
            }
            return $this->arrElementTypes[$arrName] ?? null;
        }
        // 链式访问 $arr[0][1]：用 inferArrayAccessInnerType 推导（含 array<mixed> 根数组检测）
        if ($expr->array instanceof ArrayAccessExpr) {
            return $this->inferArrayAccessInnerType($expr->array);
        }
        return null;
    }

    /**
     * 推导链式访问中内层 ArrayAccessExpr 的实际 C 返回类型。
     *
     * 与 inferType 的区别：优先查 arrElementTypes/arrNestedTypes（CodeGenerator 的
     * 精确追踪），而非 AST inferredType 字段（TypeChecker 的静态推导）。
     *
     * 场景：$b = []; $b[] = [1,2,3]; $b[0][0]
     *   - TypeChecker 标记 $b[0] 的 inferredType 为 mixed（因 $b 是 array<mixed>）
     *   - 但 CodeGenerator 的 arrElementTypes['b'] = 't_array*'（push 追踪）
     *   - 链式访问 $b[0][0] 需知道 $b[0] 返回 t_array* 才能正确生成 get_int_int
     */
    private function arrayArgCode(ExprNode $expr, string $code): string
    {
        // t_var 变量：提取 .value._array
        if ($expr instanceof VariableExpr) {
            $vn = self::varName($expr->name);
            $vt = $this->varTypes[$vn] ?? '';
            if ($vt === 't_var') {
                return "(({$code}).value._array)";
            }
            // 显式 array<T>（t_arr_int*/t_arr_str*/t_arr_float*/t_arr_bool*）：
            //   自动协变转换为 array<mixed>（t_array*），调用对应 tphp_fn_arr_{suffix}_to_var()。
            //   O(n) 重新分配并包装元素为 t_var，使内置数组函数（count/array_push/...）
            //   能统一接收 t_array* 参数，无需为每种 T 特化函数。
            if (preg_match('/^t_arr_(int|str|float|bool)\*$/', $vt, $m)) {
                return "tphp_fn_arr_{$m[1]}_to_var({$code})";
            }
            // t_arr_ptr*（array<array<T>>/array<Foo>）：元素为 void*，无法安全包装为 t_var。
            //   需用户显式声明为 array<mixed> 或手动转换。
        }
        // ArrayAccessExpr：若返回 t_var（array<mixed> 元素持有数组），提取 .value._array
        //   场景：count($b[0]) 其中 $b 是 array<mixed>，$b[0] 返回 t_var（持有 TYPE_ARRAY）
        //   优先用 inferArrayAccessActualType（CodeGenerator 精确追踪），避免 TypeChecker
        //   inferredType=mixed 误判 typed getter（返回 t_array*）的返回类型
        //   例外：per-key 类型追踪已知为标量（string/int/etc.）时按标量类型解包，
        //   避免 explode(",", $result["headers"]) 误将 string 当 array 提取
        if ($expr instanceof ArrayAccessExpr) {
            $t = $this->inferArrayAccessActualType($expr) ?? $this->inferType($expr);
            if ($t === 't_var') {
                // 检查 per-key 类型：已知标量类型时按标量解包
                if ($expr->array instanceof VariableExpr
                    && $expr->index instanceof StringLiteralExpr) {
                    $arrName = self::varName($expr->array->name);
                    $keyStr  = $expr->index->value;
                    $keyType = $this->arrValueTypes[$arrName][$keyStr] ?? null;
                    if ($keyType !== null && $keyType !== 't_array*' && $keyType !== 't_var') {
                        return match ($keyType) {
                            't_string' => "(({$code}).value._string)",
                            't_int'    => "VAR_AS_INT({$code})",
                            't_float'  => "VAR_AS_FLOAT({$code})",
                            't_bool'   => "VAR_AS_BOOL({$code})",
                            default    => $code,
                        };
                    }
                }
                return "(({$code}).value._array)";
            }
        }
        return $code;
    }

    /**
     * 检测表达式是否为显式 array<T> 变量（t_arr_int/t_arr_str/t_arr_float/t_arr_bool 指针）。
     *
     * 用于原地修改函数（array_push/pop/shift/unshift）拒绝自动转换：
     *   - to_var() 创建新数组，修改不会反映回原变量，语义错误
     *   - 应提示用户使用 $arr[] = $val 或显式声明 array<mixed>
     */
    private function isGenericArrayVar(ExprNode $expr): bool
    {
        if (!$expr instanceof VariableExpr) return false;
        $vn = self::varName($expr->name);
        $vt = $this->varTypes[$vn] ?? '';
        return (bool)preg_match('/^t_arr_(int|str|float|bool|ptr)\*$/', $vt);
    }

    /** 读取 t_var 变量的值，按预期类型提取 */
    private function readVar(string $var, string $expectType): string
    {
        return match ($expectType) {
            't_int'    => "VAR_AS_INT({$var})",
            't_float'  => "VAR_AS_FLOAT({$var})",
            't_string' => "VAR_AS_STRING({$var})",
            't_bool'   => "VAR_AS_BOOL({$var})",
            't_array*' => "(({$var}).value._array)",
            default    => $var, // fallback: raw t_var
        };
    }

    /**
     * 解包 phpc 互操作函数参数中的 t_var 值。
     *
     * phpc 函数如 php_int(v)/c_int(v) 展开为 ((t_int)(v))，对 struct t_var 编译失败。
     * 本方法检测 t_var 参数并按函数期望类型解包：
     *   - php_int/c_int/phpc_ptr_to_int → VAR_AS_INT(v)
     *   - php_str/c_str/php_str_clone/php_str_ptr/cstr_to_string → VAR_AS_STRING(v)
     *
     * @param string  $shortN 函数短名（如 'php_int'）
     * @param CallExpr $node   调用节点（用于检查参数 AST）
     * @param array   $args   已生成的参数 C 代码数组
     * @return array 解包后的参数 C 代码数组
     */
    private function unwrapPhpcArgs(string $shortN, CallExpr $node, array $args): array
    {
        $intFns = ['php_int', 'c_int', 'phpc_ptr_to_int'];
        $strFns = ['php_str', 'c_str', 'php_str_clone', 'php_str_ptr', 'cstr_to_string'];
        $expectType = '';
        if (in_array($shortN, $intFns, true)) {
            $expectType = 't_int';
        } elseif (in_array($shortN, $strFns, true)) {
            $expectType = 't_string';
        }
        if ($expectType === '') {
            return $args;
        }
        foreach ($args as $i => $code) {
            if (!isset($node->args[$i])) continue;
            $argExpr = $node->args[$i];
            // 跳过非首参数（这些函数只对第一参数解包）
            if ($i > 0) continue;
            $args[$i] = $this->unwrapIfMixed($argExpr, $code, $expectType);
        }
        return $args;
    }

    /** 如果表达式是 t_var 变量，按期望类型解包 */
    private function unwrapIfMixed(ExprNode $expr, string $code, string $expectType): string
    {
        if ($expr instanceof VariableExpr) {
            $vn = self::varName($expr->name);
            if (($this->varTypes[$vn] ?? '') === 't_var') {
                return $this->readVar($vn, $expectType);
            }
            return $code;
        }
        // array<mixed> 元素访问（$arr[$i]）：arrElementTypes 标记为 t_var 时才解包
        // 避免误伤 TypeChecker 推断为 mixed 但实际为 t_int/t_string 的变量
        if ($this->isActualTVarExpr($expr)) {
            return match ($expectType) {
                't_int'    => "VAR_AS_INT({$code})",
                't_float'  => "VAR_AS_FLOAT({$code})",
                't_string' => "VAR_AS_STRING({$code})",
                't_bool'   => "VAR_AS_BOOL({$code})",
                't_array*' => "(({$code}).value._array)",
                default    => $code,
            };
        }
        return $code;
    }

    /**
     * 判断表达式生成的 C 代码是否实际为 t_var 类型。
     *
     * 用于区分 TypeChecker 推断为 mixed（可能实际是 t_int/t_string）
     * 与运行时真正为 t_var 的表达式（如 array<mixed> 元素访问、t_var 变量）。
     */
    private function isActualTVarExpr(ExprNode $expr): bool
    {
        if ($expr instanceof VariableExpr) {
            $vn = self::varName($expr->name);
            return ($this->varTypes[$vn] ?? '') === 't_var';
        }
        if ($expr instanceof ArrayAccessExpr) {
            // array<mixed> 元素访问：arrElementTypes 标记为 t_var
            if ($expr->array instanceof VariableExpr) {
                $arrName = self::varName($expr->array->name);
                if (($this->arrElementTypes[$arrName] ?? null) === 't_var') {
                    return true;
                }
                // t_var 变量持有数组（$sub = $arr[0]; $sub[1]）：
                //   $sub 是 t_var，其 .value._array 是万能数组，元素也是 t_var
                if (($this->varTypes[$arrName] ?? '') === 't_var') {
                    return true;
                }
                // 万能数组（t_array*）且 arrElementTypes 未追踪但 inferredType 为 mixed：
                //   visitArrayAccess 对整数键生成 typed getter（tphp_fn_arr_get_int_int 返回 t_int）
                //   字符串键默认用 typed getter（tphp_fn_arr_get_str_str 返回 t_string），不是 t_var
                //   因此不视为 t_var（保持原始 t_int 默认行为）
                //   场景：$r = array_reverse($a); $r[0] — r 是 t_array*，inferredType=mixed，元素按 t_int 处理
            }
            // 链式访问 $arr[0][1]：若内层为 t_var，外层也是 t_var
            if ($expr->array instanceof ArrayAccessExpr) {
                $innerType = $this->inferArrayAccessInnerType($expr->array);
                if ($innerType === 't_var') {
                    return true;
                }
            }
            // 实例属性数组访问：$obj->prop[$key] 或 $this->prop[$key]
            //   propArrElementTypes 标记为 t_var，或未注册但 inferredType 为 mixed 时，元素为 t_var
            //   场景：public array $nums = []; $v = $w4->nums[1];
            //   visitArrayAccess 对未注册的 array 属性且 inferredType=mixed 时生成 t_var getter
            if ($expr->array instanceof PropertyAccessExpr) {
                $key = $this->propArrElemKey($expr->array);
                if ($key !== null) {
                    $et = $this->propArrElementTypes[$key] ?? null;
                    if ($et === 't_var') {
                        return true;
                    }
                    if ($et === null && $expr->inferredType !== null
                        && $this->inferredTypeToCType($expr->inferredType) === 't_var') {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    /** 查询方法第 $idx 个参数的 C 类型 */
    private function getMethodParamType(CallExpr $call, int $idx): string
    {
        // 独立函数调用：查 SymbolTable 的函数参数类型
        if ($call->callee === null) {
            $fnName = self::mangleCName($call->name);
            $fnInfo = $this->symbols->getFunc('tphp_fn_' . $fnName);
            if ($fnInfo === null) {
                $fnInfo = $this->symbols->getFunc('tphp_fn_' . $call->name);
            }
            if ($fnInfo !== null) {
                return $fnInfo->paramTypes[$idx] ?? '';
            }
            // 回退：内置函数参数类型映射（dirname/basename 等未注册到 SymbolTable 的内置函数）
            if (isset(self::$builtinFnParamCtypes[$call->name][$idx])) {
                return self::$builtinFnParamCtypes[$call->name][$idx];
            }
            return '';
        }
        // 查找方法所属类
        $cn = '';
        if ($call->callee instanceof VariableExpr) {
            $key = self::varName($call->callee->name);
            $raw = $this->varTypes[$key] ?? '';
            if (self::isClassCType($raw)) $cn = rtrim($raw, '*');
        } elseif ($call->callee instanceof CallExpr) {
            // 链式调用递归
            return '';
        }
        if ($cn === '' && $call->callee instanceof VariableExpr && self::varName($call->callee->name) === 'self') {
            $cn = $this->className;
        }
        // 静态方法调用：PHP 类名 → C 类名解析（如 Thread → tphp_class_Thread）
        if ($cn === '' && $call->callee instanceof VariableExpr) {
            $resolved = $this->symbols->resolveClass($call->callee->name);
            if ($resolved !== null) $cn = $resolved;
        }
        if ($cn !== '') {
            $mInfo = $this->symbols->getClassMethod($cn, $call->name);
            // 继承方法：本类无定义时上溯父类链查找参数类型
            if ($mInfo === null) {
                $parentCN = $this->resolveMethodClass($cn, $call->name);
                if ($parentCN !== '') {
                    $mInfo = $this->symbols->getClassMethod($parentCN, $call->name);
                }
            }
            if ($mInfo !== null) {
                return $mInfo->paramTypes[$idx] ?? '';
            }
        }
        return '';
    }

    /**
     * 将 array<T> 中的元素类型字符串解析为 Type 对象。
     * 用于 mapType 中 array<T> 的 C 类型推导。
     *
     * 支持的元素类型：
     *   - 基本类型: int/string/float/bool → 对应 Type 静态实例
     *   - mixed     → Type::$mixed（array<mixed> 等价于万能数组）
     *   - 嵌套数组: array<T> → 递归解析（C 类型为 t_arr_ptr）
     *   - 其他（对象/C 类型等）→ Type::$object（C 类型为 t_arr_ptr）
     */
    private function resolveArrayElemType(string $elemStr): Type
    {
        $elemStr = trim($elemStr);
        // 基本类型
        return match ($elemStr) {
            'int'        => Type::$int,
            'string'     => Type::$string,
            'float'      => Type::$float,
            'bool'       => Type::$bool,
            'mixed', ''  => Type::$mixed,
            'null'       => Type::$null,
            // 嵌套数组 / 对象 / C 类型 / 未知类型 → 用 t_arr_ptr 承载（flags 非 0）
            //   嵌套 array<array<T>> 递归解析元素类型，但 C 层统一用 t_arr_ptr
            default      => Type::pointer(Type::$object),
        };
    }

    public function mapType(string $t): string {
        if ($t === 'self') return $this->className . '*';
        if ($t === 'mixed') return 't_var';
        if ($t === 'callable') return 't_callback';
        // 泛型数组 array<T> → 返回对应的泛型 C 类型
        //   array<int>    → t_arr_int*
        //   array<string> → t_arr_str*
        //   array<float>  → t_arr_float*
        //   array<bool>   → t_arr_bool*
        //   array<mixed>  → t_array*（等价于 t_arr_var*，兼容现有代码）
        //   array<array<T>> / array<Foo> → t_arr_ptr*
        if (str_starts_with($t, 'array<') && str_ends_with($t, '>')) {
            $elemStr = substr($t, 6, -1);
            // array<mixed> → t_array*（兼容现有代码，t_arr_var 是 t_array 的别名）
            if ($elemStr === 'mixed') {
                return 't_array*';
            }
            $elemType = $this->resolveArrayElemType($elemStr);
            return Type::arrayCType($elemType) . '*';
        }
        // Type|Exception 语法：|Exception 为文档提示，C 仅生成 Type
        if (str_contains($t, '|')) {
            $parts = explode('|', $t);
            $nonExc = array_filter($parts, fn($p) => !$this->symbols->isExceptionSubclass($p));
            if (count($nonExc) === 1) {
                return $this->mapType(reset($nonExc));
            }
            return 't_var'; // 纯联合类型 → t_var
        }
        // C 类型: C.IDENTIFIER — 借鉴 vlang 的 C 命名空间设计
        //   C.X 直接直译为 C 类型 X: C.int→int, C.float→double, C.char→char, C.void→void
        //   指针用 * 后缀: C.void*→void*, C.char*→char*, C.int*→int*, C.Point*→Point*
        //   不再使用 _ptr 别名（C. 前缀就是 C 的类型）
        if (str_starts_with($t, 'C.')) {
            $ct = substr($t, 2);
            // 解析指针后缀: C.void* => void*, C.char** => char**
            $stars = '';
            while (str_ends_with($ct, '*')) {
                $stars .= '*';
                $ct = substr($ct, 0, -1);
            }
            $base = match ($ct) {
                'int' => 'int',
                'int32' => 'int32_t', 'int64' => 'int64_t',
                'uint32' => 'uint32_t', 'uint64' => 'uint64_t',
                'float', 'double' => 'double',
                'char' => 'char',
                'void' => 'void',
                'bool' => 'bool',
                default => $ct,  // 结构体名: C.Point => Point
            };
            return $base . $stars;
        }
        // 枚举类型 → 返回 C struct 指针类型
        $enumCType = $this->symbols->getEnumCType($t);
        if ($enumCType !== null) {
            return $enumCType;
        }
        // 用户定义的类名 → tphp_class_XXX*
        $resolved = $this->symbols->resolveClass($t);
        if ($resolved !== null) {
            return $resolved . '*';
        }
        return self::$typeMap[$t] ?? "{$t}*";
    }
    /** C 保留关键字集合 — 与 C 标识符冲突的 PHP 变量名需加 _ 前缀避免编译错误
     *  （C89 + C99 + C11 + C23 常用关键字，含 tcc 扩展） */
    private static array $cKeywords = [
        'auto','break','case','char','const','continue','default','do','double',
        'else','enum','extern','float','for','goto','if','inline','int','long',
        'register','restrict','return','short','signed','sizeof','static','struct',
        'switch','typedef','union','unsigned','void','volatile','while',
        '_Bool','_Complex','_Imaginary','_Alignas','_Alignof','_Atomic','_Generic',
        '_Noreturn','_Static_assert','_Thread_local',
        // tcc 内置/常见宏
        'asm','__asm','__attribute__','__inline','__inline__','__restrict',
    ];

    public static function varName(string $v): string {
        if ($v === '$this') return 'self';
        $n = ltrim($v, '$');
        // C 关键字转义：避免 PHP 变量名 $default / $case / $register 等生成非法 C 标识符
        if (in_array($n, self::$cKeywords, true)) {
            return '_' . $n;
        }
        return $n;
    }

    /** 解析类型到 C 类型（参数类型用；联合类型 | → t_var） */
    private static function resolveType(string $type): string {
        if (str_contains($type, '|')) return 't_var';
        if ($type === 'callable') return 't_callback';
        // Task 5.4: array<T> 泛型数组 — C 类型为 t_array*
        if (str_starts_with($type, 'array<') && str_ends_with($type, '>')) {
            return 't_array*';
        }
        // C 类型: C.IDENTIFIER — 直接映射为对应 C 类型（C. 前缀就是 C 的类型）
        //   C.int→int, C.void*→void*, C.Point→Point, C.Point*→Point*
        if (str_starts_with($type, 'C.')) {
            $ct = substr($type, 2);
            // 解析指针后缀
            $stars = '';
            while (str_ends_with($ct, '*')) {
                $stars .= '*';
                $ct = substr($ct, 0, -1);
            }
            $base = match ($ct) {
                'int' => 'int',
                'int32' => 'int32_t', 'int64' => 'int64_t',
                'uint32' => 'uint32_t', 'uint64' => 'uint64_t',
                'float', 'double' => 'double',
                'char' => 'char',
                'void' => 'void',
                'bool' => 'bool',
                default => $ct,  // 结构体名: C.Point => Point
            };
            return $base . $stars;
        }
        if (isset(self::$typeMap[$type])) {
            return self::$typeMap[$type];
        }
        // 命名空间类（含 \）：用 classRefName 生成正确的 C 标识符
        //   UI\Widget → tphp_na_UI_tphp_class_Widget*（而非 tphp_class_UI\Widget*）
        if (str_contains($type, '\\')) {
            return self::classRefName($type) . '*';
        }
        return 'tphp_class_' . $type . '*';
    }

    /** 生成参数声明的 C 类型 + 变量名（byRef → 加一级指针：int→int*, t_array*→t_array**） */
    public static function paramDecl(ParamNode $p): string {
        // 可变参数：C 类型固定为 t_array*（编译期打包所有剩余实参）
        if ($p->isVariadic) {
            return 't_array* ' . self::varName($p->name);
        }
        $ct = self::resolveType($p->type);
        return $p->byRef ? "{$ct} *" . self::varName($p->name) : "{$ct} " . self::varName($p->name);
    }

    /** 生成默认参数值的 C 代码 — 对 null 字面量按目标 C 类型转换为合适的零值 */
    private function defaultExprCode(ParamNode $p): string {
        $ct = self::resolveType($p->type);
        if ($p->default instanceof NullLiteralExpr) {
            return match ($ct) {
                't_callback' => '(t_callback){NULL, NULL}',
                't_string'   => '(t_string){NULL, 0}',
                default      => 'NULL',
            };
        }
        return $p->default->accept($this);
    }

    /** 参数在 varTypes 中的 C 类型（byRef → 加一级指针：int→int*, t_array*→t_array**） */
    public static function paramCType(ParamNode $p): string {
        $ct = self::resolveType($p->type);
        return $p->byRef ? "{$ct}*" : $ct;
    }

    /** 参数 C 类型（实例方法版：通过 mapType 解析命名空间类名）
     *  resolveType 是静态方法，无法解析 use 导入的命名空间类（如 User → tphp_na_NS_tphp_class_User），
     *  varTypes 和参数 struct 字段必须用此方法才能正确解析跨命名空间类引用 */
    private function paramCTypeResolved(ParamNode $p): string {
        // 可变参数：C 类型固定为 t_array*（与 paramDecl/visitParam 一致）
        if ($p->isVariadic) {
            return 't_array*';
        }
        $ct = $this->mapType($p->type);
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
        // 泛型数组 t_arr_int*/t_arr_str*/t_arr_float*/t_arr_bool*/t_arr_ptr* — 不是 byRef
        if (str_starts_with($type, 't_arr_') && str_ends_with($type, '*')) return false;
        // str_contains 而非 str_starts_with：命名空间类名 tphp_na_NS_tphp_class_X* 也需排除
        if (str_contains($type, 'tphp_class_') && str_ends_with($type, '*')) return false;
        if (str_contains($type, 'tphp_enum_') && str_ends_with($type, '*')) return false;
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
        return $this->symbols->getEnumBacking($name);
    }

    /** 将 PHP 命名空间名转为 C 标识符: Demo\Foo → Demo_Foo
     *  @deprecated 使用 NameResolver::mangleCName() 替代 */
    public static function mangleCName(string $name): string {
        return NameResolver::mangleCName($name);
    }

    /** 从类节点获取 C 标识符
     *  全局类: tphp_class_ClassName
     *  命名空间类: tphp_na_Namespace_tphp_class_ClassName
     *  @deprecated 使用 NameResolver::classCName() 替代 */
    private static function classCName(ClassNode $class): string {
        return NameResolver::classCName($class);
    }

    /** 从已解析类名生成 C 引用名（visitNew/Call 等非 ClassNode 上下文中使用） */
    /** Resolve which class owns a method (for COS inheritance) */
    private function resolveMethodClass(string $cn, string $method): string
    {
        // P3-3: 编译期缓存，避免重复线性扫描父类链
        $cacheKey = $cn . "\0" . $method;
        if (isset($this->methodClassCache[$cacheKey])) {
            return $this->methodClassCache[$cacheKey];
        }
        $cur = $cn;
        while ($this->symbols->hasClass($cur) && $this->symbols->getClassParent($cur) !== '') {
            $cur = $this->symbols->getClassParent($cur);
            if ($this->symbols->getClassMethod($cur, $method) !== null) {
                $this->methodClassCache[$cacheKey] = $cur;
                return $cur;
            }
        }
        $this->methodClassCache[$cacheKey] = '';
        return '';
    }

    /** 判断 childCName 是否是 parentCName 的子类（含多层继承） */
    private function isSubclassOf(string $childCName, string $parentCName): bool
    {
        $cur = $childCName;
        while ($cur !== '' && $this->symbols->hasClass($cur)) {
            $parent = $this->symbols->getClassParent($cur);
            if ($parent === '') break;
            if ($parent === $parentCName) return true;
            $cur = $parent;
        }
        return false;
    }

    /** Resolve property prefix for COS inheritance: _parent._parent. */
    private function resolvePropPrefix(string $cn, string $prop): string
    {
        $prefix = '';
        $cur = $cn;
        while ($this->symbols->hasClass($cur) && $this->symbols->getClassParent($cur) !== '') {
            $cur = $this->symbols->getClassParent($cur);
            if ($this->symbols->hasClassOwnProp($cur, $prop)) {
                return $prefix;
            }
            $prefix .= '_parent.';
        }
        return $prefix; // fallback: try outermost parent
    }

    /** 查找属性的 hook 信息（遍历父类链）
     *  返回 ['cn' => 声明类CName, 'get' => bool, 'set' => bool, 'type' => C类型] 或 null */
    private function resolveHookInfo(string $cn, string $prop): ?array
    {
        $cur = $cn;
        while ($cur !== '') {
            if (isset($this->hookedProps[$cur][$prop])) {
                $info = $this->hookedProps[$cur][$prop];
                $info['cn'] = $cur;
                return $info;
            }
            $cur = $this->symbols->hasClass($cur) ? $this->symbols->getClassParent($cur) : '';
        }
        return null;
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
     *  命名空间函数: tphp_na_Namespace_tphp_fn_functionName
     *  @deprecated 使用 NameResolver::funcCName() 替代 */
    private static function funcCName(FunctionNode $fn): string {
        return NameResolver::funcCName($fn);
    }

    /** 从 CallExpr 推导 C 函数名（与 funcCName 格式一致）
     *  全局函数: tphp_fn_functionName
     *  命名空间函数: tphp_na_Namespace_tphp_fn_functionName */
    /** @deprecated 使用 NameResolver::funcCNameFromCall() 替代 */
    private static function funcCNameFromCall(CallExpr $expr): string {
        return NameResolver::funcCNameFromCall($expr);
    }

    /**
     * 生成导出函数 trampoline + 共享库自动初始化（-shared 模式）
     *
     * #[Export("name")] function fn(params): ret { ... }
     * → TPHP_EXPORT ret name(params) { return tphp_fn_fn(params); }
     *
     * 验证:
     *   - 仅独立函数可导出（方法上报错）
     *   - 参数/返回值不能是 array
     *   - 导出名必须是合法 C 标识符且全局唯一
     */
    private function emitExports(ProgramNode $node): string
    {
        if (!$this->isShared) return '';

        $exports = [];
        $seenNames = [];

        // 1. 检查方法上的 #[Export] — 报错（仅独立函数可导出）
        $allClasses = array_merge(
            $node->mainClass ? [$node->mainClass] : [],
            $node->extraClasses
        );
        $allClasses = array_filter($allClasses, fn($c) => !$c->isTrait);
        foreach ($allClasses as $class) {
            foreach ($class->methods as $m) {
                foreach ($m->attributes as $attr) {
                    if ($this->isExportAttr($attr)) {
                        $classFq = $class->namespace !== '' ? $class->namespace . '\\' . $class->name : $class->name;
                        throw new \RuntimeException(
                            "#[Export] can only be used on standalone functions, not method {$classFq}::{$m->name}"
                        );
                    }
                }
            }
        }

        // 2. 收集独立函数上的 #[Export]
        foreach ($node->functions as $fn) {
            // C 函数签名声明不参与 #[Export] 导出
            if ($fn->isCDeclaration) continue;
            foreach ($fn->attributes as $attr) {
                if (!$this->isExportAttr($attr)) continue;

                if (empty($attr->args)) {
                    throw new \RuntimeException("#[Export] requires a string argument: #[Export(\"name\")]");
                }
                $arg = $attr->args[0];
                if (!($arg instanceof StringLiteralExpr)) {
                    throw new \RuntimeException("#[Export] argument must be a string literal");
                }
                $exportName = $arg->value;

                if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $exportName)) {
                    throw new \RuntimeException(
                        "Invalid export name '{$exportName}': must be a valid C identifier"
                    );
                }
                if (isset($seenNames[$exportName])) {
                    throw new \RuntimeException("Duplicate export name '{$exportName}'");
                }
                $seenNames[$exportName] = true;

                if ($fn->returnType === 'array') {
                    throw new \RuntimeException(
                        "#[Export] function {$fn->name} return type cannot be array"
                    );
                }
                foreach ($fn->params as $p) {
                    if ($p->type === 'array') {
                        throw new \RuntimeException(
                            "#[Export] function {$fn->name} parameter {$p->name} type cannot be array"
                        );
                    }
                }

                $exports[] = ['fn' => $fn, 'exportName' => $exportName];
            }
        }

        if (empty($exports)) return '';

        // 生成 C 代码
        $lines = [];
        $lines[] = "/* ── Exported functions (-shared mode) ──────────── */";
        $lines[] = '';
        $lines[] = '#if defined(_WIN32)';
        $lines[] = '  #define TPHP_EXPORT __declspec(dllexport)';
        $lines[] = '#else';
        $lines[] = '  #define TPHP_EXPORT __attribute__((visibility("default")))';
        $lines[] = '#endif';
        $lines[] = '';

        // Trampoline 函数
        foreach ($exports as $e) {
            $fn = $e['fn'];
            $exportName = $e['exportName'];
            $fnCName = self::funcCName($fn);
            $retCType = self::mapType($fn->returnType);

            $params = [];
            $args = [];
            foreach ($fn->params as $p) {
                $cType = $this->paramCTypeResolved($p);
                $varName = ltrim($p->name, '$');
                $params[] = "{$cType} {$varName}";
                $args[] = $varName;
            }
            $paramStr = empty($params) ? 'void' : implode(', ', $params);

            $lines[] = "TPHP_EXPORT {$retCType} {$exportName}({$paramStr}) {";
            if ($fn->returnType === 'void') {
                $lines[] = "    {$fnCName}(" . implode(', ', $args) . ");";
            } else {
                $lines[] = "    return {$fnCName}(" . implode(', ', $args) . ");";
            }
            $lines[] = "}";
            $lines[] = '';
        }

        // 共享库 runtime 自动初始化
        $lines[] = '/* ── Shared library runtime auto-init ── */';
        $lines[] = '#if defined(_WIN32)';
        $lines[] = '#include <windows.h>';
        $lines[] = 'BOOL WINAPI DllMain(HINSTANCE _hinst, DWORD _fdwReason, LPVOID _lpvReserved) {';
        $lines[] = '    if (_fdwReason == DLL_PROCESS_ATTACH) {';
        $lines[] = '        tphp_rt_init();';
        foreach ($this->annotationInitFns as $initFn) {
            $lines[] = "        {$initFn}();";
        }
        $lines[] = '    }';
        $lines[] = '    return TRUE;';
        $lines[] = '}';
        $lines[] = '#else';
        $lines[] = '__attribute__((constructor))';
        $lines[] = 'static void _tphp_shared_init(void) {';
        $lines[] = '    tphp_rt_init();';
        foreach ($this->annotationInitFns as $initFn) {
            $lines[] = "    {$initFn}();";
        }
        $lines[] = '}';
        $lines[] = '#endif';

        return implode("\n", $lines);
    }

    /** 检查是否为 #[Export] 注解 */
    private function isExportAttr(AttributeUseNode $attr): bool
    {
        return $attr->name === 'Export' || $attr->name === '\\Export';
    }

    private function indentStr(): string { return str_repeat('    ', $this->indent); }
    private function ind(string $l): string { return $this->indentStr() . $l; }
}
