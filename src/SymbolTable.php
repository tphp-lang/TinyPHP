<?php

declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
// SymbolTable — 统一符号表
//   替代 CodeGenerator 中 13 个散落的类型追踪数组
//   提供单一入口查询类/枚举/常量/函数/闭包的元信息
// ═══════════════════════════════════════════════════════════════

class MethodInfo
{
    public function __construct(
        public readonly string $retType,
        /** @var string[] C type per param index */
        public readonly array  $paramTypes = [],
        public readonly bool   $isStatic = false,
        public readonly string $visibility = 'public',
        public readonly int $defaultCount = 0,
        public readonly int $totalParams = 0,
        /** final 方法：子类不可覆盖 */
        public readonly bool   $isFinal = false,
        /** abstract 方法（body = null）：子类必须实现 */
        public readonly bool   $isAbstract = false,
        /** @var string[] 参数名（不含 $），与 $paramTypes 一一对应；用于命名参数映射 */
        public readonly array  $paramNames = [],
        /** 可变参数：最后一个参数是否为 `...$args`（C 类型固定为 t_array*） */
        public readonly bool   $isVariadic = false,
        /** 静态分发标记：final class 的方法为 true（直接生成 ClassName_method 调用，绕过 vtable） */
        public readonly bool   $isStaticDispatch = false,
    ) {}
}

class ClassInfo
{
    public function __construct(
        public readonly string $cName,
        public readonly string $parent = '',
        /** @var array<string,string> propName => C type */
        public array $props = [],
        /** @var array<string,MethodInfo> */
        public array $methods = [],
        /** @var array<string,bool> own props set (not inherited) */
        public array $ownProps = [],
        public bool  $isAbstract = false,
        /** @var string[] */
        public array $implements = [],
        /** @var array<string,string> static propName => C type */
        public array $staticProps = [],
        /** @var array<string,bool> readonly propName => true (本类声明的 readonly 属性) */
        public array $readonlyProps = [],
        /** @var array<string,bool> private(set) propName => true (本类声明的非对称可见性属性) */
        public array $privateSetProps = [],
        /** readonly class: 所有属性自动 readonly */
        public bool  $isReadonlyClass = false,
        /** final class: 不可继承，方法可静态分发 */
        public bool  $isFinal = false,
        /** interface 声明（区别于 abstract class） */
        public bool  $isInterface = false,
    ) {}
}

class FunctionInfo
{
    public function __construct(
        public readonly string $retType,
        /** @var string[] C type per param index */
        public readonly array  $paramTypes = [],
        /** @var int 默认值参数数量（从最后一个非默认值参数之后开始计数） */
        public readonly int $defaultCount = 0,
        /** @var int 总参数数量 */
        public readonly int $totalParams = 0,
        public readonly bool $isGenerator = false,
        /** @var string[] 参数名（不含 $），与 $paramTypes 一一对应；用于命名参数映射 */
        public readonly array  $paramNames = [],
        /** 可变参数：最后一个参数是否为 `...$args`（C 类型固定为 t_array*） */
        public readonly bool   $isVariadic = false,
    ) {}
}

class SymbolTable
{
    // ═══ 类注册 ═══
    /** @var array<string,ClassInfo> cName => ClassInfo */
    private array $classes = [];

    /** @var array<string,string> originalName => cName */
    private array $nameMap = [];

    // ═══ 枚举 ═══
    /** @var array<string,array{backing:string,cType:string}> */
    private array $enums = [];

    // ═══ 常量 ═══
    /** @var array<string,array{type:string,vis:string}> fqn => info */
    private array $consts = [];

    // ═══ 全局函数 ═══
    /** @var array<string,FunctionInfo> cName => FunctionInfo */
    private array $funcs = [];

    // ═══ C 函数签名声明（vlang 风格 function C.foo(): C.ret;）═══
    /** @var array<string,FunctionInfo> C 函数名（不含 C. 前缀）=> FunctionInfo
     *  TypeChecker 注册时使用 PHP 等价类型（t_int / t_string 等），
     *  CodeGenerator 注册时使用原始 C 类型字符串（int / char* 等）。
     *  两个阶段使用各自的 SymbolTable 实例，互不冲突。 */
    private array $cFunctions = [];

    // ═══ C 结构体声明（#cstruct 指令 + struct C.Foo{} 声明）═══
    /** @var array<string,array<string,string>> structName => [fieldName => C type]
     *  TypeChecker 据此推导 C 结构体字段访问的类型 */
    private array $cStructs = [];

    // ═══ 闭包 ═══
    /** @var array<string,array{ret:string,params:string}> */
    private array $closureSigs = [];

    /** @var array<string,string> varName => closureName */
    private array $varClosureMap = [];

    // ═══ 作用域对象追踪 ═══
    /** @var string[] 当前作用域内的对象变量名 */
    private array $scopeObjects = [];

    // ═══ 作用域字符串/数组追踪（用于自动释放） ═══
    /** @var array<string,string> varName => cType ('t_string' 或 't_array*') */
    private array $scopeStrings = [];
    /** @var array<string,string> varName => cType */
    private array $scopeArrays = [];
    /** @var array<string,bool> 返回语句中使用的变量名（排除在自动释放之外） */
    private array $returnedVars = [];

    // ═══ Type 表示层 ═══
    private ?TypeTable $typeTable = null;

    // ──────────────────────────────────────────────────────────
    // Class
    // ──────────────────────────────────────────────────────────

    public function addClass(string $cName, string $parent = '', bool $isAbstract = false, array $implements = [], bool $isReadonly = false, bool $isFinal = false, bool $isInterface = false): void
    {
        $this->classes[$cName] = new ClassInfo($cName, $parent, [], [], [], $isAbstract, $implements, [], [], [], $isReadonly, $isFinal, $isInterface);
    }

    public function addClassName(string $original, string $cName): void
    {
        $this->nameMap[$original] = $cName;
    }

    public function resolveClass(string $name): ?string
    {
        // 规范化：去掉前导反斜杠（\Lib\Point → Lib\Point）
        $name = ltrim($name, '\\');
        return $this->nameMap[$name] ?? null;
    }

    public function getClass(string $cName): ?ClassInfo
    {
        return $this->classes[$cName] ?? null;
    }

    public function addClassProp(string $cName, string $prop, string $type, bool $own = true, bool $isStatic = false): void
    {
        $c = $this->classes[$cName] ?? null;
        if ($c === null) return;
        if ($isStatic) {
            $c->staticProps[$prop] = $type;
        } else {
            $c->props[$prop] = $type;
            if ($own) $c->ownProps[$prop] = true;
        }
    }

    /** 注册 readonly 属性（本类声明的） */
    public function addClassReadonlyProp(string $cName, string $prop): void
    {
        $c = $this->classes[$cName] ?? null;
        if ($c === null) return;
        $c->readonlyProps[$prop] = true;
    }

    /** 注册非对称可见性属性 private(set)（本类声明的） */
    public function addClassPrivateSetProp(string $cName, string $prop): void
    {
        $c = $this->classes[$cName] ?? null;
        if ($c === null) return;
        $c->privateSetProps[$prop] = true;
    }

    /** 查询属性是否为 private(set)（沿父链查找）
     *  @return bool 属性是否只能在声明类内写入 */
    public function isPropPrivateSet(string $cName, string $prop): bool
    {
        $cur = $cName;
        while ($cur !== '') {
            $c = $this->classes[$cur] ?? null;
            if ($c === null) return false;
            if (isset($c->privateSetProps[$prop])) return true;
            $cur = $c->parentClass;
        }
        return false;
    }

    /** 查询声明 private(set) 属性的类（沿父链查找）
     *  @return string 声明该 private(set) 属性的 cName，未找到返回 '' */
    public function getPrivateSetPropDeclaringClass(string $cName, string $prop): string
    {
        $cur = $cName;
        while ($cur !== '') {
            $c = $this->classes[$cur] ?? null;
            if ($c === null) return '';
            if (isset($c->privateSetProps[$prop])) return $cur;
            $cur = $c->parentClass;
        }
        return '';
    }

    /** 查询属性是否 readonly（沿父链查找）
     *  @return bool 属性是否为 readonly */
    public function isPropReadonly(string $cName, string $prop): bool
    {
        $cur = $cName;
        while ($cur !== '') {
            $c = $this->classes[$cur] ?? null;
            if ($c === null) return false;
            // readonly class: 所有属性自动 readonly
            if ($c->isReadonlyClass && isset($c->props[$prop])) {
                return true;
            }
            if (isset($c->readonlyProps[$prop])) {
                return true;
            }
            $cur = $c->parent;
        }
        return false;
    }

    /** 查询 readonly 属性的声明类 C 名（沿父链查找）
     *  @return string|null 声明该 readonly 属性的类的 C 名，null 表示非 readonly */
    public function getReadonlyPropDeclaringClass(string $cName, string $prop): ?string
    {
        $cur = $cName;
        while ($cur !== '') {
            $c = $this->classes[$cur] ?? null;
            if ($c === null) return null;
            if (isset($c->readonlyProps[$prop]) || ($c->isReadonlyClass && isset($c->props[$prop]))) {
                return $cur;
            }
            $cur = $c->parent;
        }
        return null;
    }

    public function getClassPropType(string $cName, string $prop): ?string
    {
        $c = $this->classes[$cName] ?? null;
        if ($c === null) return null;
        return $c->props[$prop] ?? $c->staticProps[$prop] ?? null;
    }

    public function isStaticProp(string $cName, string $prop): bool
    {
        return isset($this->classes[$cName]->staticProps[$prop]);
    }

    public function getStaticPropType(string $cName, string $prop): ?string
    {
        return $this->classes[$cName]->staticProps[$prop] ?? null;
    }

    public function hasClassOwnProp(string $cName, string $prop): bool
    {
        return isset($this->classes[$cName]->ownProps[$prop]);
    }

    public function getClassParent(string $cName): string
    {
        return $this->classes[$cName]->parent ?? '';
    }

    public function addClassMethod(string $cName, string $mn, MethodInfo $m): void
    {
        $c = $this->classes[$cName] ?? null;
        if ($c === null) return;
        $c->methods[$mn] = $m;
    }

    public function getClassMethod(string $cName, string $mn): ?MethodInfo
    {
        return $this->classes[$cName]->methods[$mn] ?? null;
    }

    public function hasClass(string $cName): bool
    {
        return isset($this->classes[$cName]);
    }

    /** 检查 PHP 类型名是否为 Exception 子类（含 Exception 本身）
     *  用于 Type|Exception 语法：|Exception 部分为文档提示，C 代码仅生成 Type */
    public function isExceptionSubclass(string $phpName): bool
    {
        $cName = $this->resolveClass($phpName);
        if ($cName === null) return false;
        if ($cName === 'tphp_class_Exception') return true;
        $cur = $cName;
        $visited = [];
        while ($cur !== '' && !isset($visited[$cur])) {
            $visited[$cur] = true;
            if ($cur === 'tphp_class_Exception') return true;
            $cur = $this->getClassParent($cur);
        }
        return false;
    }

    // ──────────────────────────────────────────────────────────
    // Enum
    // ──────────────────────────────────────────────────────────

    public function addEnum(string $name, string $backing, string $cType): void
    {
        $this->enums[$name] = [
            'backing' => $backing,
            'cType'   => $cType,
            'cName'   => rtrim($cType, '*'),
            'cases'   => [],
            'consts'  => [],
        ];
    }

    public function getEnumBacking(string $name): string
    {
        return $this->enums[$name]['backing'] ?? 'int';
    }

    public function getEnumCType(string $name): ?string
    {
        return $this->enums[$name]['cType'] ?? null;
    }

    /** 枚举 C 结构体名（无 *），如 tphp_enum_Color */
    public function getEnumCName(string $name): ?string
    {
        return $this->enums[$name]['cName'] ?? null;
    }

    /** @return array<string,string> name => cType */
    public function allEnums(): array
    {
        return array_map(fn($i) => $i['cType'], $this->enums);
    }

    public function addEnumCase(string $name, string $caseName): void
    {
        if (!isset($this->enums[$name])) return;
        $this->enums[$name]['cases'][] = $caseName;
    }

    public function hasEnumCase(string $name, string $caseName): bool
    {
        return isset($this->enums[$name]) && in_array($caseName, $this->enums[$name]['cases'], true);
    }

    /** @return string[] case names */
    public function getEnumCases(string $name): array
    {
        return $this->enums[$name]['cases'] ?? [];
    }

    public function addEnumConst(string $name, string $constName, string $type): void
    {
        if (!isset($this->enums[$name])) return;
        $this->enums[$name]['consts'][$constName] = $type;
    }

    public function getEnumConstType(string $name, string $constName): ?string
    {
        return $this->enums[$name]['consts'][$constName] ?? null;
    }

    public function addEnumMethod(string $name, string $methodName, MethodInfo $m): void
    {
        if (!isset($this->enums[$name])) return;
        $this->enums[$name]['methods'][$methodName] = $m;
    }

    /** 按枚举名查方法 */
    public function getEnumMethod(string $name, string $methodName): ?MethodInfo
    {
        return $this->enums[$name]['methods'][$methodName] ?? null;
    }

    /** 按枚举 C 结构体名（tphp_enum_X）查方法 */
    public function getEnumMethodByCName(string $cName, string $methodName): ?MethodInfo
    {
        foreach ($this->enums as $info) {
            if ($info['cName'] === $cName) {
                return $info['methods'][$methodName] ?? null;
            }
        }
        return null;
    }

    /** 判断 $x 是枚举名还是枚举 C 结构体名，返回 C 结构体名或 null */
    public function resolveEnumCName(string $x): ?string
    {
        if (isset($this->enums[$x])) return $this->enums[$x]['cName'];
        foreach ($this->enums as $info) {
            if ($info['cName'] === $x) return $x;
        }
        return null;
    }

    // ──────────────────────────────────────────────────────────
    // Const
    // ──────────────────────────────────────────────────────────

    public function addConst(string $fqn, string $type, string $vis = 'public'): void
    {
        $this->consts[$fqn] = ['type' => $type, 'vis' => $vis];
    }

    public function getConstType(string $fqn): ?string
    {
        return $this->consts[$fqn]['type'] ?? null;
    }

    public function getConstVis(string $fqn): string
    {
        return $this->consts[$fqn]['vis'] ?? 'public';
    }

    /** 查询常量完整信息（type + vis），返回 null 表示未注册 */
    public function getConst(string $fqn): ?array
    {
        return $this->consts[$fqn] ?? null;
    }

    // ──────────────────────────────────────────────────────────
    // Function
    // ──────────────────────────────────────────────────────────

    public function addFunc(string $cName, FunctionInfo $fn): void
    {
        $this->funcs[$cName] = $fn;
    }

    public function getFunc(string $cName): ?FunctionInfo
    {
        return $this->funcs[$cName] ?? null;
    }

    public function getFuncRet(string $cName): ?string
    {
        return $this->funcs[$cName]->retType ?? null;
    }

    /** @return string[] */
    public function getFuncParams(string $cName): array
    {
        return $this->funcs[$cName]->paramTypes ?? [];
    }

    // ──────────────────────────────────────────────────────────
    // C Function declarations (vlang-style function C.foo(...): C.ret;)
    // ──────────────────────────────────────────────────────────

    /** 注册 C 函数签名声明
     *  @param string   $name        C 函数名（不含 C. 前缀）
     *  @param string   $retType     返回类型（TypeChecker: PHP 等价类型；CodeGenerator: 原始 C 类型）
     *  @param string[] $paramTypes  参数类型列表（同上），变参用 '...' 表示 */
    public function addCFunction(string $name, string $retType, array $paramTypes): void
    {
        $this->cFunctions[$name] = new FunctionInfo($retType, $paramTypes);
    }

    /** 查询 C 函数签名声明
     *  @return FunctionInfo|null null 表示未声明（回退到旧有硬编码逻辑） */
    public function getCFunction(string $name): ?FunctionInfo
    {
        return $this->cFunctions[$name] ?? null;
    }

    // ──────────────────────────────────────────────────────────
    // C Struct declarations (#cstruct directive + struct C.Foo{} declaration)
    // ──────────────────────────────────────────────────────────

    /** 注册 C 结构体字段布局
     *  @param string $name   结构体名（不含 C. 前缀，如 'Point'）
     *  @param array  $fields 字段列表 [['type'=>'C.double','name'=>'x'], ...] */
    public function addCStruct(string $name, array $fields): void
    {
        $fieldMap = [];
        foreach ($fields as $f) {
            $fieldMap[$f['name']] = $f['type'];
        }
        $this->cStructs[$name] = $fieldMap;
    }

    /** 查询 C 结构体字段类型
     *  @return string|null C 类型字符串（如 'C.double'），null 表示未注册或字段不存在 */
    public function getCStructField(string $structName, string $fieldName): ?string
    {
        return $this->cStructs[$structName][$fieldName] ?? null;
    }

    /** 查询 C 结构体是否已注册 */
    public function hasCStruct(string $structName): bool
    {
        return isset($this->cStructs[$structName]);
    }

    // ──────────────────────────────────────────────────────────
    // Closure
    // ──────────────────────────────────────────────────────────

    public function addClosureSig(string $name, array $sig): void
    {
        $this->closureSigs[$name] = $sig;
    }

    public function getClosureSig(string $name): ?array
    {
        return $this->closureSigs[$name] ?? null;
    }

    public function addVarClosure(string $var, string $closure): void
    {
        $this->varClosureMap[$var] = $closure;
    }

    public function getVarClosure(string $var): ?string
    {
        return $this->varClosureMap[$var] ?? null;
    }

    // ──────────────────────────────────────────────────────────
    // Scope objects (for auto-destructor)
    // ──────────────────────────────────────────────────────────

    /** @return string[] */
    public function scopeObjects(): array
    {
        return $this->scopeObjects;
    }

    public function addScopeObject(string $vn): void
    {
        $this->scopeObjects[] = $vn;
    }

    public function removeScopeObjects(array $vns): void
    {
        $this->scopeObjects = array_values(array_filter(
            $this->scopeObjects, fn($o) => !in_array($o, $vns, true)));
    }

    public function clearScopeObjects(): void
    {
        $this->scopeObjects = [];
    }

    // ──────────────────────────────────────────────────────────
    // Scope strings/arrays (for auto-free)
    // ──────────────────────────────────────────────────────────

    /** @return array<string,string> varName => cType */
    public function scopeStrings(): array
    {
        return $this->scopeStrings;
    }

    /** @return array<string,string> varName => cType */
    public function scopeArrays(): array
    {
        return $this->scopeArrays;
    }

    public function addScopeString(string $vn): void
    {
        $this->scopeStrings[$vn] = 't_string';
    }

    public function addScopeArray(string $vn): void
    {
        $this->scopeArrays[$vn] = 't_array*';
    }

    public function removeScopeVars(array $vns): void
    {
        foreach ($vns as $vn) {
            unset($this->scopeStrings[$vn], $this->scopeArrays[$vn]);
        }
    }

    public function clearScopeVars(): void
    {
        $this->scopeStrings = [];
        $this->scopeArrays = [];
        $this->returnedVars = [];
    }

    public function addReturnedVar(string $vn): void
    {
        $this->returnedVars[$vn] = true;
    }

    /** @return array<string,bool> */
    public function returnedVars(): array
    {
        return $this->returnedVars;
    }

    // ──────────────────────────────────────────────────────────
    // Reset
    // ──────────────────────────────────────────────────────────

    public function reset(): void
    {
        $this->classes = [];
        $this->nameMap = [];
        $this->enums = [];
        $this->consts = [];
        $this->funcs = [];
        $this->cFunctions = [];
        $this->cStructs = [];
        $this->closureSigs = [];
        $this->varClosureMap = [];
        $this->scopeObjects = [];
        $this->scopeStrings = [];
        $this->scopeArrays = [];
        $this->returnedVars = [];
        $this->typeTable = null;
    }

    // ──────────────────────────────────────────────────────────
    // Type 表示层
    // ──────────────────────────────────────────────────────────

    /** 获取 TypeTable 单例（懒初始化） */
    public function typeTable(): TypeTable
    {
        if ($this->typeTable === null) {
            $this->typeTable = new TypeTable();
        }
        return $this->typeTable;
    }

    /** 注册用户自定义类型（类、枚举等） */
    public function registerType(string $name, string $cType, bool $isCLang = false, ?Type $elemType = null): Type
    {
        return $this->typeTable()->register($name, $cType, $isCLang, $elemType);
    }

    /** 按名称查找类型 */
    public function lookupType(string $name): ?Type
    {
        return $this->typeTable()->lookup($name);
    }

    /** 按名称查找类型符号 */
    public function lookupTypeSymbol(string $name): ?TypeSymbol
    {
        return $this->typeTable()->lookupSymbol($name);
    }

    /** 获取 Type 对应的 C 类型字符串 */
    public function getTypeCType(Type $type): string
    {
        return $this->typeTable()->getCType($type);
    }

    /** 查询类是否为 final */
    public function isClassFinal(string $cName): bool
    {
        return $this->classes[$cName]?->isFinal ?? false;
    }

    /** 查询类是否为 interface */
    public function isClassInterface(string $cName): bool
    {
        return $this->classes[$cName]?->isInterface ?? false;
    }

    /** 查询类是否为 abstract（含 interface） */
    public function isClassAbstract(string $cName): bool
    {
        return $this->classes[$cName]?->isAbstract ?? false;
    }
}
