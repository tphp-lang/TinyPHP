<?php

declare(strict_types=1);

/**
 * 递归下降解析器
 *
 * 语法（简化）:
 *   program     → PHP_OPEN class_decl EOF
 *   class_decl  → CLASS_KW IDENTIFIER LBRACE method* RBRACE
 *   method      → visibility FUNCTION (IDENTIFIER | CONSTRUCT | DESTRUCT)
 *                    LPAREN params? RPAREN COLON type LBRACE stmt* RBRACE
 *   params      → param (COMMA param)*
 *   param       → type DOLLAR IDENTIFIER
 *   stmt        → echo_stmt | return_stmt | assign_stmt | expr_stmt
 *   echo_stmt   → ECHO_KW expr (COMMA expr)* SEMICOLON
 *   return_stmt → RETURN_KW expr? SEMICOLON
 *   assign_stmt → IDENTIFIER EQUALS expr SEMICOLON
 *   expr_stmt   → expr SEMICOLON
 *   expr        → primary (ARROW call | LPAREN args RPAREN)?
 *   primary     → STRING_LIT | INT_LIT | FLOAT_LIT | TRUE_KW | FALSE_KW
 *                | NULL_KW | IDENTIFIER | NEW_KW IDENTIFIER LPAREN args? RPAREN
 *                | LPAREN type RPAREN expr
 *   args        → expr (COMMA expr)*
 *   type        → TYPE_INT | TYPE_FLOAT | TYPE_STRING | TYPE_BOOL
 *                | TYPE_VOID | TYPE_ARRAY | IDENTIFIER
 *   visibility  → PUBLIC_KW | PRIVATE_KW
 */
class Parser
{
    /** 标识符类 token — 可作为变量名/函数名/类名引用（替代 parsePrimary 中的巨型 || 链） */
    private static array $identifierLikeTokens = [
        TokenType::IDENTIFIER, TokenType::SELF_KW, TokenType::PARENT_KW,
        TokenType::VAR_DUMP, TokenType::COUNT, TokenType::EXIT, TokenType::DIE,
        TokenType::ISSET, TokenType::EMPTY_KW, TokenType::UNSET,
        TokenType::ERROR, TokenType::TIME, TokenType::DATE,
        TokenType::SLEEP, TokenType::USLEEP, TokenType::HRTIME,
        TokenType::IS_INT, TokenType::IS_FLOAT, TokenType::IS_STRING,
        TokenType::IS_BOOL, TokenType::IS_ARRAY, TokenType::IS_OBJECT,
        TokenType::IS_NULL, TokenType::IS_CALLABLE,
    ];

    /** @var Token[] */
    private array $tokens;
    private int $current = 0;

    /** 当前文件的命名空间（空字符串表示全局） */
    private string $currentNamespace = '';
    /** use 类导入: 短名 → 完全限定名 (如 Demo → Demo\Demo) */
    private array $classImports = [];
    /** use 函数导入: 短名 → 完全限定名 (如 myDemoFn → Demo\myDemoFn) */
    private array $functionImports = [];
    /** use const 导入: 短名 → 完全限定名 (如 NS_FOO → Lib\NS_FOO) */
    private array $constImports = [];
    /** 生成器检测栈：解析函数/方法/闭包体时压入 bool，遇到 yield 设为 true */
    private array $genStack = [];
    /** use 枚举导入: 短名 → 完全限定名 (如 Color → Enums\Color) */
    private array $enumImports = [];
    /** 当前文件声明的常量名集合（用于跨命名空间引用检测） */
    private array $declaredConsts = [];
    /** 当前文件声明的枚举名集合（完全限定名 → true，用于 Color::RED 识别） */
    private array $enumNames = [];

    private bool $debugMode;

    /** 编译上下文（条件编译求值用） */
    private string $targetOS  = '';
    private string $targetArch = '';
    private string $ccClass   = 'TCC';

    /** @var array<array{active: bool, matched: bool, parentActive: bool}> 条件编译状态栈 */
    private array $ctStack = [];

    /** @param Token[] $tokens */
    public function __construct(
        array $tokens,
        bool $debugMode = false,
        string $targetOS = '',
        string $targetArch = '',
        string $ccClass = 'TCC'
    ) {
        $this->tokens = $tokens;
        $this->debugMode = $debugMode;
        // 空值回退到宿主环境（与 tphp.php 交叉编译参数处理一致）
        $this->targetOS   = $targetOS   !== '' ? $targetOS   : PHP_OS_FAMILY;
        $this->targetArch = $targetArch !== '' ? $targetArch : php_uname('m');
        $this->ccClass    = $ccClass;
    }

    /** 注入其他文件已声明的枚举名（完全限定名 → true），用于跨文件枚举引用 */
    public function setKnownEnums(array $known): void
    {
        $this->enumNames = $known;
    }

    public function parse(): ProgramNode
    {
        $this->current = 0;
        $program = $this->parseProgram();

        if (!$this->check(TokenType::EOF)) {
            $this->error('Expected end of file, got ' . $this->peek()->lexeme);
        }

        return $program;
    }

    // ============================================================
    // program → PHP_OPEN namespace_decl? (use_decl | free_code?)* decl* EOF
    //   free_code: 不在任何声明内的 echo/赋值等（不支持，直接报错）
    // ============================================================
    private function parseProgram(): ProgramNode
    {
        $this->currentNamespace = '';
        $this->classImports = [];
        $this->functionImports = [];
        $this->enumImports = [];
        $this->declaredConsts = [];
        // enumNames 已由 setKnownEnums 注入，不清空（追加模式）

        $this->consume(TokenType::PHP_OPEN, 'Expected <?php at start');

        // 可选 namespace 声明
        if ($this->match(TokenType::NAMESPACE)) {
            $this->currentNamespace = $this->parseQualifiedName();
            $this->consume(TokenType::SEMICOLON, 'Expected ;');
        }

        // 预处理指令（任意顺序：include/flag/callback/cstruct/debug 可混合出现）
        // 支持 #if/#elseif/#else/#endif 条件编译包裹
        $includes = [];
        $ccFlags = [];
        $callbacks = [];
        $cstructs = [];
        $debugs  = [];
        while (true) {
            // 条件编译指令优先处理
            if ($this->tryCtDirective()) continue;
            if (!$this->check(TokenType::HASH_INCLUDE) && !$this->check(TokenType::HASH_IMPORT)
                && !$this->check(TokenType::CC_FLAG) && !$this->check(TokenType::HASH_CALLBACK)
                && !$this->check(TokenType::HASH_CSTRUCT) && !$this->check(TokenType::HASH_DEBUG)) {
                break;
            }
            // 非命中分支内：消费指令但不收集
            if (!$this->ctActive()) {
                $this->advance();
                continue;
            }
            if ($this->match(TokenType::HASH_INCLUDE)) {
                $includes[] = $this->previous()->literal;
            } elseif ($this->match(TokenType::HASH_IMPORT)) {
                // #import 已在 tphp.php 预扫描阶段处理，此处仅消费 token
            } elseif ($this->match(TokenType::CC_FLAG)) {
                $ccFlags[] = $this->previous()->literal;
            } elseif ($this->match(TokenType::HASH_CALLBACK)) {
                $callbacks[] = $this->previous()->literal;
            } elseif ($this->match(TokenType::HASH_CSTRUCT)) {
                $cstructs[] = $this->previous()->literal;
            } elseif ($this->match(TokenType::HASH_DEBUG)) {
                if ($this->debugMode) {
                    $debugs[] = $this->previous()->lexeme;
                }
            }
        }

        // 连续 use 声明
        while ($this->match(TokenType::USE)) {
            $this->parseUseDecl();
        }

        // const 声明（可能有 #[Attribute(...)] 前缀声明注解类型）
        $constants = [];
        while ($this->check(TokenType::CONST_KW)
            || ($this->check(TokenType::HASH_ATTRIBUTE)
                && $this->peek(1)->type === TokenType::IDENTIFIER
                && $this->peek(1)->lexeme === 'Attribute')) {
            $constants[] = $this->parseConstDecl();
        }

        // enum 声明
        $enums = [];
        while ($this->match(TokenType::ENUM_KW)) {
            $enums[] = $this->parseEnumDecl();
        }

        // 第一个类 = Main（入口）
        $mainClass = null;
        $extraClasses = [];
        $functions = [];

        while (!$this->check(TokenType::EOF)) {
            // 条件编译指令优先处理（#if/#elseif/#else/#endif）
            if ($this->tryCtDirective()) continue;
            // 解析 #[...] 属性前缀（注解使用）
            $prefixAttrs = $this->parseAttributeUses();
            if (!empty($prefixAttrs)) {
                // 属性后必须跟 class/function/enum 声明
                if (!$this->check(TokenType::ABSTRACT_KW) && !$this->check(TokenType::READONLY_KW)
                    && !$this->check(TokenType::FINAL_KW) && !$this->check(TokenType::CLASS_KW)
                    && !$this->check(TokenType::FUNCTION) && !$this->check(TokenType::ENUM_KW)) {
                    $this->error('Attributes must be followed by class/function/enum declaration, got ' . $this->peek()->lexeme);
                }
            }

            if ($this->match(TokenType::ABSTRACT_KW)) {
                // abstract class（可选 readonly 前缀，PHP 中 abstract+readonly 合法）
                $isReadOnly = $this->match(TokenType::READONLY_KW);
                $this->consume(TokenType::CLASS_KW, 'Expected class keyword');
                $cls = $this->parseClassDeclBody(true, $isReadOnly, $prefixAttrs);
                $extraClasses[] = $cls;
            } elseif ($this->check(TokenType::READONLY_KW) || $this->check(TokenType::FINAL_KW) || $this->check(TokenType::CLASS_KW)) {
                $cls = $this->parseClassDecl($prefixAttrs);
                if ($mainClass === null) {
                    $mainClass = $cls;
                } else {
                    $extraClasses[] = $cls;
                }
            } elseif ($this->check(TokenType::INTERFACE_KW)) {
                $cls = $this->parseInterfaceDecl();
                $extraClasses[] = $cls;
            } elseif ($this->check(TokenType::TRAIT_KW)) {
                $cls = $this->parseTraitDecl();
                $extraClasses[] = $cls;
            } elseif ($this->check(TokenType::ENUM_KW)) {
                // 允许 enum 在主声明循环中（与 class/interface 交错声明）
                $this->advance();
                $enums[] = $this->parseEnumDecl();
            } elseif ($this->check(TokenType::CONST_KW)) {
                // const 可在主循环中（被 #if 条件编译包裹时）
                $constants[] = $this->parseConstDecl();
            } elseif ($this->check(TokenType::FUNCTION)) {
                $functions[] = $this->parseFunction($prefixAttrs);
            } elseif ($this->check(TokenType::ECHO_KW) || $this->check(TokenType::IDENTIFIER) || $this->check(TokenType::VAR_DUMP)) {
                $tok = $this->peek();
                $this->error("Unsupported top-level code '{$tok->lexeme}' (multi-file compilation only accepts namespace/use/class/function/const/enum declarations)");
            } elseif ($this->check(TokenType::HASH_INCLUDE)) {
                $this->error('#include must be placed at the top of the file (before namespace/use/class/function declarations)');
            } elseif ($this->check(TokenType::CC_FLAG)) {
                $this->error('#flag must be placed at the top of the file (before namespace/use/class/function declarations)');
            } elseif ($this->check(TokenType::HASH_CALLBACK)) {
                $this->error('#callback must be placed at the top of the file (before namespace/use/class/function declarations)');
            } elseif ($this->check(TokenType::HASH_CSTRUCT)) {
                $this->error('#cstruct must be placed at the top of the file (before namespace/use/class/function declarations)');
            } elseif ($this->check(TokenType::HASH_IMPORT)) {
                $this->error('#import must be placed at the top of the file (before namespace/use/class/function declarations)');
            } elseif ($this->check(TokenType::HASH_DEBUG)) {
                $this->error('#debug must be placed at the top of the file (before namespace/use/class/function declarations)');
            } elseif ($this->check(TokenType::HASH_ATTRIBUTE)) {
                $this->error('#[Attribute(...)] must be placed before const to declare annotation types');
            } else {
                $this->error('Expected namespace/use/class/function/const/enum, got ' . $this->peek()->lexeme);
            }
        }

        return new ProgramNode($mainClass, $extraClasses, $functions, $constants, $enums, $includes, $ccFlags, $callbacks, $debugs, $cstructs);
    }

    // ============================================================
    // const → CONST_KW [TYPE] IDENTIFIER '=' literal SEMICOLON
    // 类型标记可选：const int NAME = 42  或  const NAME = 42
    // ============================================================
    private function parseConstDecl(): ConstNode
    {
        // 可选 #[Attribute(...)] 注解类型声明前缀
        $attrDecl = null;
        if ($this->check(TokenType::HASH_ATTRIBUTE)) {
            $attrDecl = $this->parseAttributeDecl();
        }
        $this->consume(TokenType::CONST_KW, 'Expected const');
        // 可选类型标记探测
        $type = null;
        $t1 = $this->peek(0);
        $typeStartTokens = [
            TokenType::TYPE_INT, TokenType::TYPE_FLOAT, TokenType::TYPE_STRING,
            TokenType::TYPE_BOOL, TokenType::TYPE_ARRAY, TokenType::TYPE_MIXED,
            TokenType::IDENTIFIER, // 类名/C 类型
        ];
        if (in_array($t1->type, $typeStartTokens, true)) {
            $hasType = false;
            if ($t1->type === TokenType::IDENTIFIER) {
                $t2 = $this->peek(1);
                if ($t2->type === TokenType::DOT) {
                    // C.Type NAME = value
                    $hasType = true;
                } elseif ($t2->type === TokenType::IDENTIFIER) {
                    // ClassName NAME = value
                    $hasType = true;
                }
                // else: const NAME = value (NAME 即常量名)
            } else {
                // TYPE_INT/TYPE_FLOAT/... NAME = value
                $hasType = $this->peek(1)->type === TokenType::IDENTIFIER;
            }
            if ($hasType) {
                $type = $this->parseType();
            }
        }

        $name = $this->consume(TokenType::IDENTIFIER, 'Expected constant name')->lexeme;
        $this->declaredConsts[$name] = $this->currentNamespace;
        $this->consume(TokenType::EQUALS, 'Expected =');
        $value = $this->parsePrimary(); // 只接受字面量
        $this->consume(TokenType::SEMICOLON, 'Expected ;');
        return new ConstNode($name, $value, $this->currentNamespace, $type, null, null, $attrDecl);
    }

    // ============================================================
    // enum Name: int { case A = 1; case B = 2; public function label(): string {...} const MAX = 9; }
    // ============================================================
    private function parseEnumDecl(): EnumNode
    {
        $name = $this->consume(TokenType::IDENTIFIER, 'Expected enum name')->lexeme;
        $fqName = ($this->currentNamespace !== '')
            ? $this->currentNamespace . '\\' . $name
            : $name;
        $this->enumNames[$fqName] = true;
        $this->consume(TokenType::COLON, 'Expected :');

        // backing type: int or string
        if ($this->match(TokenType::TYPE_INT)) {
            $bt = 'int';
        } elseif ($this->match(TokenType::TYPE_STRING)) {
            $bt = 'string';
        } else {
            $this->error("Enum only supports int or string type, got '{$this->peek()->lexeme}'");
        }

        // implements Interface1, Interface2 (recorded, not vtable-enforced)
        $implements = [];
        if ($this->match(TokenType::IMPLEMENTS_KW)) {
            do {
                $implements[] = $this->parseQualifiedName();
            } while ($this->match(TokenType::COMMA));
        }

        $this->consume(TokenType::LBRACE, 'Expected {');
        $cases = [];
        $methods = [];
        $classConsts = [];
        while (!$this->check(TokenType::RBRACE) && !$this->check(TokenType::EOF)) {
            if ($this->check(TokenType::CASE_KW)) {
                $this->consume(TokenType::CASE_KW, 'Expected case');
                $caseName = $this->consume(TokenType::IDENTIFIER, 'Expected case name')->lexeme;
                $this->consume(TokenType::EQUALS, 'Expected =');
                $caseValue = $this->parsePrimary(); // 字面量
                $this->consume(TokenType::SEMICOLON, 'Expected ;');
                $cases[] = new EnumCaseNode($caseName, $caseValue);
            } elseif ($this->isClassConstStart()) {
                $classConsts[] = $this->parseClassConstDecl($name);
            } elseif ($this->check(TokenType::CONST_KW)) {
                $this->error("Enum constants must have an explicit type declaration (e.g., 'public const int MAX = 9')");
            } else {
                $methods[] = $this->parseMethod();
            }
        }
        $this->consume(TokenType::RBRACE, 'Expected }');

        return new EnumNode($name, $bt, $cases, $this->currentNamespace, $methods, $classConsts, $implements);
    }

    // ============================================================
    // 注解属性解析
    //   #[Attribute(path: string, method: array)] — 注解类型声明（仅 const 前）
    //   #[ROUTE("/test", ["GET"])]               — 注解使用（class/method/function 前）
    //   不支持命名参数（使用命名参数时报语法错误）
    // ============================================================

    /** 解析 #[Attribute(name: type, ...)] 注解类型声明 */
    private function parseAttributeDecl(): AttributeDeclNode
    {
        $this->consume(TokenType::HASH_ATTRIBUTE, 'Expected #[');
        $name = $this->consume(TokenType::IDENTIFIER, 'Expected Attribute')->lexeme;
        if ($name !== 'Attribute') {
            $this->error("Expected 'Attribute' declaration, got '{$name}' (use #[Attribute(...)] to declare annotation types)");
        }
        $this->consume(TokenType::LPAREN, 'Expected (');
        $params = [];
        if (!$this->check(TokenType::RPAREN)) {
            do {
                $pname = $this->consume(TokenType::IDENTIFIER, 'Expected parameter name')->lexeme;
                $this->consume(TokenType::COLON, 'Expected : after parameter name');
                $ptype = $this->parseType();
                $default = null;
                if ($this->match(TokenType::EQUALS)) {
                    $default = $this->parsePrimary();
                }
                $params[] = ['name' => $pname, 'type' => $ptype, 'default' => $default];
            } while ($this->match(TokenType::COMMA));
        }
        $this->consume(TokenType::RPAREN, 'Expected )');
        $this->consume(TokenType::RBRACKET, 'Expected ]');
        return new AttributeDeclNode($params);
    }

    /** 解析 #[NAME(args), ...] 注解使用（可连续多个 #[...]），返回 AttributeUseNode[]
     *  注解名按常量作用域规则解析（与普通常量一致）:
     *    - 已限定名（含 \）→ 保持 FQ 名
     *    - 短名 → 查 use const 导入表；命中则替换为 FQ 名；否则保持短名
     *      （CodeGenerator 负责短名匹配：同命名空间常量 + 全局常量回退） */
    private function parseAttributeUses(): array
    {
        $uses = [];
        while ($this->check(TokenType::HASH_ATTRIBUTE)) {
            $this->consume(TokenType::HASH_ATTRIBUTE, 'Expected #[');
            // 注解名：IDENTIFIER (NS_SEP IDENTIFIER)* — 支持命名空间限定
            $name = $this->consume(TokenType::IDENTIFIER, 'Expected attribute name')->lexeme;
            $qualified = false;
            while ($this->match(TokenType::NS_SEP)) {
                $name .= '\\' . $this->consume(TokenType::IDENTIFIER, 'Expected attribute name')->lexeme;
                $qualified = true;
            }
            // 作用域解析：短名 → 仅查 use const 导入表（全局常量回退由 CodeGenerator 处理）
            if (!$qualified && isset($this->constImports[$name])) {
                $name = $this->constImports[$name];
            }
            // 可选参数列表 (仅位置参数，命名参数报错)
            $args = [];
            if ($this->match(TokenType::LPAREN)) {
                if (!$this->check(TokenType::RPAREN)) {
                    do {
                        // 命名参数检测：IDENTIFIER ':' → 报错
                        if ($this->check(TokenType::IDENTIFIER) && $this->peek(1)->type === TokenType::COLON) {
                            $namedName = $this->peek()->lexeme;
                            $this->error("Named arguments are not supported in attributes ( '{$namedName}: ...' ), use positional arguments only");
                        }
                        $args[] = $this->parseExpr();
                    } while ($this->match(TokenType::COMMA));
                }
                $this->consume(TokenType::RPAREN, 'Expected )');
            }
            $this->consume(TokenType::RBRACKET, 'Expected ]');
            // 同名注解重复检测：同一目标上不允许多次使用同名 #[Attribute] 注解
            foreach ($uses as $prev) {
                if ($prev->name === $name) {
                    $this->error("Duplicate attribute '#[{$name}]' on the same target — multiple different attributes are allowed, but the same attribute cannot be used more than once");
                }
            }
            $uses[] = new AttributeUseNode($name, $args);
        }
        return $uses;
    }

    // ============================================================
    // 命名空间 & use
    // ============================================================

    /** 解析完全限定名: IDENTIFIER (NS_SEP IDENTIFIER)* → "A\B\C" */
    private function parseQualifiedName(): string
    {
        $name = $this->consume(TokenType::IDENTIFIER, 'Expected identifier')->lexeme;
        while ($this->match(TokenType::NS_SEP)) {
            $name .= '\\' . $this->consume(TokenType::IDENTIFIER, 'Expected identifier')->lexeme;
        }
        return $name;
    }

    /** use X\Y\Z; | use function X\Y; | use const X\Y; | use X\{ A, B, function F }; | use function X\{ f1, f2 }; */
    private function parseUseDecl(): void
    {
        // use function ... ?
        if ($this->match(TokenType::FUNCTION)) {
            // 读取命名空间前缀（至少一个 segment，遇到 { 前停止）
            $base = $this->consume(TokenType::IDENTIFIER, 'Expected identifier')->lexeme;
            while ($this->check(TokenType::NS_SEP) && $this->peek(1)->type === TokenType::IDENTIFIER) {
                $this->advance(); // skip \
                $base .= '\\' . $this->consume(TokenType::IDENTIFIER, 'Expected identifier')->lexeme;
            }
            // use function X\{ f1, f2, f3 }; → 组合式函数导入
            if ($this->check(TokenType::NS_SEP) && $this->peek(1)->type === TokenType::LBRACE) {
                $this->advance(); // skip \
                $this->advance(); // skip {
                do {
                    // 允许尾部逗号 (PHP 7.3+)
                    if ($this->check(TokenType::RBRACE)) break;
                    $fnName = $this->consume(TokenType::IDENTIFIER, 'Expected function name')->lexeme;
                    $this->parseUseAlias(function: true, fqName: $base . '\\' . $fnName, alias: $fnName);
                } while ($this->match(TokenType::COMMA));
                $this->consume(TokenType::RBRACE, 'Expected }');
                $this->consume(TokenType::SEMICOLON, 'Expected ;');
                return;
            }
            // use function X\Y; | use function X\Y as Z; → 单函数导入
            $this->parseUseAlias(function: true, fqName: $base);
            $this->consume(TokenType::SEMICOLON, 'Expected ;');
            return;
        }

        // use const ... ?
        if ($this->match(TokenType::CONST_KW)) {
            $base = $this->consume(TokenType::IDENTIFIER, 'Expected identifier')->lexeme;
            while ($this->check(TokenType::NS_SEP) && $this->peek(1)->type === TokenType::IDENTIFIER) {
                $this->advance(); // skip \
                $base .= '\\' . $this->consume(TokenType::IDENTIFIER, 'Expected identifier')->lexeme;
            }
            // use const X\{ A, B }; → 组合式常量导入
            if ($this->check(TokenType::NS_SEP) && $this->peek(1)->type === TokenType::LBRACE) {
                $this->advance(); // skip \
                $this->advance(); // skip {
                do {
                    if ($this->check(TokenType::RBRACE)) break;
                    $cName = $this->consume(TokenType::IDENTIFIER, 'Expected constant name')->lexeme;
                    $this->parseUseAlias(function: false, fqName: $base . '\\' . $cName, alias: $cName, isConst: true);
                } while ($this->match(TokenType::COMMA));
                $this->consume(TokenType::RBRACE, 'Expected }');
                $this->consume(TokenType::SEMICOLON, 'Expected ;');
                return;
            }
            // use const X\Y; | use const X\Y as Z; → 单常量导入
            $this->parseUseAlias(function: false, fqName: $base, isConst: true);
            $this->consume(TokenType::SEMICOLON, 'Expected ;');
            return;
        }

        // 读取第一部分标识符
        $base = $this->consume(TokenType::IDENTIFIER, 'Expected identifier')->lexeme;

        // use Demo\{ ... } → 组合语法（提前探测，不消耗 token）
        if ($this->check(TokenType::NS_SEP) && $this->peek(1)->type === TokenType::LBRACE) {
            $this->advance(); // skip \
            $this->advance(); // skip {
            do {
                // 允许尾部逗号 (PHP 7.3+)
                if ($this->check(TokenType::RBRACE)) break;
                if ($this->match(TokenType::FUNCTION)) {
                    $fnName = $this->consume(TokenType::IDENTIFIER, 'Expected function name')->lexeme;
                    $this->parseUseAlias(function: true, fqName: $base . '\\' . $fnName, alias: $fnName);
                } elseif ($this->match(TokenType::CONST_KW)) {
                    $cName = $this->consume(TokenType::IDENTIFIER, 'Expected constant name')->lexeme;
                    $this->parseUseAlias(function: false, fqName: $base . '\\' . $cName, alias: $cName, isConst: true);
                } else {
                    $itemName = $this->consume(TokenType::IDENTIFIER, 'Expected identifier')->lexeme;
                    $this->parseUseAlias(function: false, fqName: $base . '\\' . $itemName, alias: $itemName);
                }
            } while ($this->match(TokenType::COMMA));
            $this->consume(TokenType::RBRACE, 'Expected }');
            $this->consume(TokenType::SEMICOLON, 'Expected ;');
            return;
        }

        // 继续读取完整限定名: base\next\...
        $full = $base;
        while ($this->match(TokenType::NS_SEP)) {
            $full .= '\\' . $this->consume(TokenType::IDENTIFIER, 'Expected identifier')->lexeme;
        }
        $baseName = $full;

        // use X as Alias;
        if ($this->match(TokenType::AS_KW)) {
            $alias = $this->consume(TokenType::IDENTIFIER, 'Expected alias')->lexeme;
            $this->classImports[$alias] = $baseName;
            $this->consume(TokenType::SEMICOLON, 'Expected ;');
            return;
        }

        // use X\Y; (alias = 最后一段)
        $short = substr(strrchr($baseName, '\\') ?: ('\\' . $baseName), 1);
        // 全大写/下划线名称 → 常量导入，TinyPHP 不支持跨命名空间常量
        if (self::isConstantName($short)) {
            $this->error("Cross-namespace constant import not supported: 'use {$baseName}' (constants can only be used within their defining namespace)");
        }
        $this->classImports[$short] = $baseName;
        $this->consume(TokenType::SEMICOLON, 'Expected ;');
    }

    /** 注册 use 别名 */
    private function parseUseAlias(bool $function, string $fqName, string $alias = '', bool $isConst = false): void
    {
        if ($this->match(TokenType::AS_KW)) {
            $alias = $this->consume(TokenType::IDENTIFIER, 'Expected alias')->lexeme;
        }
        $key = ($alias !== '') ? $alias : substr(strrchr($fqName, '\\') ?: ('\\' . $fqName), 1);
        // 全大写/下划线名称 → 常量导入检测（仅对非 use const 路径生效）
        if (!$function && !$isConst && self::isConstantName($key)) {
            $this->error("Cross-namespace constant import not supported: 'use {$fqName}' (use 'use const {$fqName}' instead)");
        }
        if ($function) {
            $this->functionImports[$key] = $fqName;
        } elseif ($isConst) {
            $this->constImports[$key] = $fqName;
        } else {
            $this->classImports[$key] = $fqName;
        }
    }

    /** 解析类名引用：先查 use 导入表，找不到则假定在当前命名空间
     *  含命名空间分隔符的名字（FQ 名/部分限定名）直接返回 */
    private function resolveClassName(string $name): string
    {
        if (str_contains($name, '\\')) {
            return $name;
        }
        if (isset($this->classImports[$name])) {
            return $this->classImports[$name];
        }
        return ($this->currentNamespace !== '')
            ? $this->currentNamespace . '\\' . $name
            : $name;
    }

    /** 解析枚举名引用：先查 use 导入表，再查 classImports（use 语句可能把枚举当类导入），
     *  找不到则假定在当前命名空间 */
    private function resolveEnumName(string $name): ?string
    {
        // 1. enumImports 优先
        if (isset($this->enumImports[$name])) {
            return $this->enumImports[$name];
        }
        // 2. classImports（use 枚举时跟 class 走同一路径）
        if (isset($this->classImports[$name])) {
            $fq = $this->classImports[$name];
            if (isset($this->enumNames[$fq])) return $fq;
        }
        // 3. 当前命名空间
        $fq = ($this->currentNamespace !== '')
            ? $this->currentNamespace . '\\' . $name
            : $name;
        if (isset($this->enumNames[$fq])) return $fq;
        // 4. 全局回退（name 本身是 FQN）
        if (isset($this->enumNames[$name])) return $name;

        return null; // 不是枚举
    }

    /** 判断名称是否像 PHP 常量（全大写+下划线+数字，至少一个大写字母） */
    private static function isConstantName(string $name): bool
    {
        return preg_match('/^[A-Z_][A-Z0-9_]*$/', $name) === 1 && preg_match('/[A-Z]/', $name) === 1;
    }

    /** 解析函数名引用 */
    private function resolveFunctionName(string $name): string
    {
        if (isset($this->functionImports[$name])) {
            return $this->functionImports[$name];
        }
        return ($this->currentNamespace !== '')
            ? $this->currentNamespace . '\\' . $name
            : $name;
    }

    // ============================================================
    // function_decl → FUNCTION IDENTIFIER LPAREN params? RPAREN COLON? type? LBRACE stmt* RBRACE
    // ============================================================
    private function parseFunction(array $attributes = []): FunctionNode
    {
        $this->consume(TokenType::FUNCTION, 'Expected function keyword');

        $name = $this->consume(TokenType::IDENTIFIER, 'Expected function name')->lexeme;

        $this->consume(TokenType::LPAREN, 'Expected (');
        $params = [];
        if (!$this->check(TokenType::RPAREN)) {
            $params = $this->parseParams();
        }
        $this->consume(TokenType::RPAREN, 'Expected )');

        $returnType = 'void';
        if ($this->match(TokenType::COLON)) {
            $returnType = $this->parseType();
        }

        $this->consume(TokenType::LBRACE, 'Expected {');
        $this->genStack[] = false;
        $body = [];
        while (!$this->check(TokenType::RBRACE) && !$this->check(TokenType::EOF)) {
            $body[] = $this->parseStmt();
        }
        $isGen = array_pop($this->genStack);
        $this->consume(TokenType::RBRACE, 'Expected }');

        return new FunctionNode($name, $params, $returnType, $body, $this->currentNamespace, $isGen, $attributes);
    }

    // ============================================================
    // class_decl → (final)? (readonly)? CLASS_KW IDENTIFIER (extends NAME)? (implements NAME,...)?
    //   readonly 与 final 可互换顺序: final readonly class / readonly final class
    // ============================================================
    private function parseClassDecl(array $attributes = []): ClassNode
    {
        $this->match(TokenType::FINAL_KW);
        $isReadOnly = $this->match(TokenType::READONLY_KW);
        // final 可出现在 readonly 之后: readonly final class
        if (!$isReadOnly) {
            $this->match(TokenType::FINAL_KW);
        }
        $this->consume(TokenType::CLASS_KW, 'Expected class keyword');
        return $this->parseClassDeclBody(false, $isReadOnly, $attributes);
    }

    private function parseClassDeclBody(bool $isAbstract, bool $isReadonly = false, array $attributes = []): ClassNode
    {
        $name = $this->consume(TokenType::IDENTIFIER, 'Expected class name')->lexeme;
        $parentName = null;
        if ($this->match(TokenType::EXTENDS_KW)) {
            $parentName = $this->resolveClassName($this->parseQualifiedName());
        }
        $implements = [];
        if ($this->match(TokenType::IMPLEMENTS_KW)) {
            do {
                $implements[] = $this->resolveClassName($this->parseQualifiedName());
            } while ($this->match(TokenType::COMMA));
        }

        $this->consume(TokenType::LBRACE, 'Expected {');

        $methods = [];
        $properties = [];
        $classConsts = [];
        $traits = [];
        while (!$this->check(TokenType::RBRACE) && !$this->check(TokenType::EOF)) {
            // 解析方法前的 #[...] 属性前缀
            $methodAttrs = $this->parseAttributeUses();
            if (!empty($methodAttrs)) {
                $m = $this->parseMethod($methodAttrs);
                if (!empty($m->promoted)) {
                    foreach ($m->promoted as $pp) {
                        $properties[] = $pp;
                    }
                }
                $methods[] = $m;
                continue;
            }
            // use TraitName; → compile-time flattening
            if ($this->match(TokenType::USE)) {
                do {
                    $tname = $this->parseQualifiedName();
                    $traits[] = $tname;
                } while ($this->match(TokenType::COMMA));
                $this->consume(TokenType::SEMICOLON, 'Expected ;');
                continue;
            }
            // 属性声明: visibility type $name (= default)? ;
            if ($this->isPropertyStart()) {
                $properties[] = $this->parsePropertyDecl();
            } elseif ($this->isClassConstStart()) {
                $classConsts[] = $this->parseClassConstDecl($name);
            } elseif ($this->check(TokenType::CONST_KW)) {
                $this->error("Class constants must have an explicit type declaration (e.g., 'public const string MAX = 100')");
            } elseif (($this->check(TokenType::PUBLIC_KW) || $this->check(TokenType::PRIVATE_KW) || $this->check(TokenType::STATIC_KW) || $this->check(TokenType::READONLY_KW))
                       && $this->peek(1)->type === TokenType::IDENTIFIER && str_starts_with($this->peek(1)->lexeme, '$')) {
                $this->error("Properties must have an explicit type declaration (e.g., 'public int \$count')");
            } else {
                $m = $this->parseMethod();
                // Collect promoted properties from constructor
                if (!empty($m->promoted)) {
                    foreach ($m->promoted as $pp) {
                        $properties[] = $pp;
                    }
                }
                $methods[] = $m;
            }
        }

        $this->consume(TokenType::RBRACE, 'Expected }');
        return new ClassNode($name, $methods, $this->currentNamespace, $properties, $classConsts, $parentName, $isAbstract, $implements, $traits, $isReadonly, $attributes);
    }

    // interface → INTERFACE_KW IDENTIFIER LBRACE method* RBRACE
    private function parseInterfaceDecl(): ClassNode
    {
        $this->consume(TokenType::INTERFACE_KW, 'Expected interface keyword');
        $name = $this->consume(TokenType::IDENTIFIER, 'Expected interface name')->lexeme;
        $extends = [];
        if ($this->match(TokenType::EXTENDS_KW)) {
            do { $extends[] = $this->resolveClassName($this->parseQualifiedName()); } while ($this->match(TokenType::COMMA));
        }
        $this->consume(TokenType::LBRACE, 'Expected {');
        $methods = [];
        while (!$this->check(TokenType::RBRACE) && !$this->check(TokenType::EOF)) {
            $methods[] = $this->parseMethod();
        }
        $this->consume(TokenType::RBRACE, 'Expected }');
        // Interface = abstract class with only method signatures
        return new ClassNode($name, $methods, $this->currentNamespace, [], [], null, true, $extends);
    }

    // trait → TRAIT_KW IDENTIFIER LBRACE method* RBRACE
    private function parseTraitDecl(): ClassNode
    {
        $this->consume(TokenType::TRAIT_KW, 'Expected trait keyword');
        $name = $this->consume(TokenType::IDENTIFIER, 'Expected trait name')->lexeme;
        $this->consume(TokenType::LBRACE, 'Expected {');
        $methods = [];
        $properties = [];
        while (!$this->check(TokenType::RBRACE) && !$this->check(TokenType::EOF)) {
            if ($this->isPropertyStart()) {
                $properties[] = $this->parsePropertyDecl();
            } else {
                $methods[] = $this->parseMethod();
            }
        }
        $this->consume(TokenType::RBRACE, 'Expected }');
        return new ClassNode($name, $methods, $this->currentNamespace, $properties);
    }

    /** 判断当前 token 是否为类常量声明开头：const T name 或 visibility const T name */
    private function isClassConstStart(): bool
    {
        $t1 = $this->peek(0);
        $isVis = ($t1->type === TokenType::PUBLIC_KW || $t1->type === TokenType::PRIVATE_KW);
        $i = $isVis ? 1 : 0;
        if ($this->peek($i)->type !== TokenType::CONST_KW) return false;
        if ($isVis && $this->peek(1)->type !== TokenType::CONST_KW) return false;
        // Class constants require explicit type: visibility const TYPE name =
        $t2 = $this->peek($i + 1);
        $typeTokens = [TokenType::TYPE_INT, TokenType::TYPE_FLOAT, TokenType::TYPE_STRING,
                       TokenType::TYPE_BOOL, TokenType::TYPE_ARRAY, TokenType::TYPE_MIXED, TokenType::IDENTIFIER];
        if (in_array($t2->type, $typeTokens, true)) {
            $t3 = $this->peek($i + 2);
            return $t3->type === TokenType::IDENTIFIER;
        }
        return false;
    }

    /** Class constant: [visibility] const TYPE name = expr ; (type required) */
    private function parseClassConstDecl(string $className): ConstNode
    {
        // visibility
        $vis = 'public';
        if ($this->check(TokenType::PUBLIC_KW) || $this->check(TokenType::PRIVATE_KW)) {
            [$vis] = $this->parseVisibility(); // const 不支持 readonly，忽略第二个返回值
        }
        $this->consume(TokenType::CONST_KW, 'Expected const');

        // type (required for class constants — no auto-deduction)
        $type = $this->parseType();

        // name
        $cName = $this->consume(TokenType::IDENTIFIER, 'Expected constant name')->lexeme;

        // = value
        $this->consume(TokenType::EQUALS, 'Expected =');
        $value = $this->parseExpr();

        $this->consume(TokenType::SEMICOLON, 'Expected ;');
        return new ConstNode(
            name: $cName,
            value: $value,
            namespace: $this->currentNamespace,
            type: $type,
            visibility: $vis,
            className: $className,
        );
    }

    /** 判断当前 token 序列是否为属性声明开头
     *  支持顺序: [visibility] [readonly] [static] type $name  |  static [visibility] [readonly] type $name
     *  |  readonly [visibility] [static] type $name */
    private function isPropertyStart(): bool
    {
        $typeTokens = [TokenType::TYPE_INT, TokenType::TYPE_FLOAT, TokenType::TYPE_STRING,
                       TokenType::TYPE_BOOL, TokenType::TYPE_ARRAY, TokenType::TYPE_MIXED, TokenType::TYPE_NEVER, TokenType::IDENTIFIER];
        // 检查从 $off 开始是否为: type $name (;|=|{)
        //   type 可为: builtin/类名（单 token）, 或 C.TypeName[*]* （phpc C 类型）
        //   返回 (offset_after_type => 匹配, false => 不匹配)
        $checkPropPattern = function(int $off) use ($typeTokens): bool {
            $t = $this->peek($off);
            if (!in_array($t->type, $typeTokens, true)) return false;
            $next = $off + 1;
            // C.TypeName[*]* — C 类型前缀（C.IDENTIFIER 后可跟若干 * 或 **）
            // 对齐 parseType() 的 C 类型解析逻辑
            if ($t->type === TokenType::IDENTIFIER && $t->lexeme === 'C' && $this->peek($next)->type === TokenType::DOT) {
                $next++; // 消费 '.'
                $memberTok = $this->peek($next);
                $validCTypeTokens = [
                    TokenType::IDENTIFIER, TokenType::TYPE_INT, TokenType::TYPE_FLOAT,
                    TokenType::TYPE_STRING, TokenType::TYPE_BOOL, TokenType::TYPE_VOID,
                ];
                if (!in_array($memberTok->type, $validCTypeTokens, true)) return false;
                $next++; // 消费 type member token
                while ($this->peek($next)->type === TokenType::STAR || $this->peek($next)->type === TokenType::STAR_STAR) {
                    $next++; // 消费 * 或 **
                }
            }
            $tn = $this->peek($next);
            if ($tn->type !== TokenType::IDENTIFIER || !str_starts_with($tn->lexeme, '$')) return false;
            $tt = $this->peek($next + 1);
            return in_array($tt->type, [TokenType::SEMICOLON, TokenType::EQUALS, TokenType::LBRACE], true);
        };
        // static [visibility] [readonly] type $name
        if ($this->check(TokenType::STATIC_KW)) {
            // static function ... → 方法，不是属性
            $n1 = $this->peek(1)->type;
            if ($n1 === TokenType::FUNCTION) return false;
            // static public/private [readonly] type $name
            if ($n1 === TokenType::PUBLIC_KW || $n1 === TokenType::PRIVATE_KW) {
                $off = 2;
                if ($this->peek($off)->type === TokenType::READONLY_KW) $off++;
                return $checkPropPattern($off);
            }
            // static readonly type $name
            if ($n1 === TokenType::READONLY_KW) {
                $off = 2;
                if ($this->peek($off)->type === TokenType::PUBLIC_KW || $this->peek($off)->type === TokenType::PRIVATE_KW) $off++;
                return $checkPropPattern($off);
            }
            // static type $name
            return $checkPropPattern(1);
        }
        // readonly [visibility] [static] type $name
        if ($this->check(TokenType::READONLY_KW)) {
            $off = 1;
            if ($this->peek($off)->type === TokenType::PUBLIC_KW || $this->peek($off)->type === TokenType::PRIVATE_KW) $off++;
            if ($this->peek($off)->type === TokenType::STATIC_KW) $off++;
            return $checkPropPattern($off);
        }
        // [visibility] [readonly] [static] type $name
        if (!$this->check(TokenType::PUBLIC_KW) && !$this->check(TokenType::PRIVATE_KW)) return false;
        $off = 1;
        if ($this->peek($off)->type === TokenType::READONLY_KW) $off++;
        $hasStatic = $this->peek($off)->type === TokenType::STATIC_KW;
        if ($hasStatic) $off++;
        return $checkPropPattern($off);
    }

    /** 属性声明: [visibility] [readonly] [static] type $name (= expr)? ;  或带 hook: ... { get => expr; set => expr; }
     *  readonly 属性必须有类型声明（parseType 已强制要求），不支持默认值（PHP 语义） */
    private function parsePropertyDecl(): PropertyDeclNode
    {
        $mods = $this->parseModifiers();
        $type = $this->parseType();
        $name = $this->consume(TokenType::IDENTIFIER, 'Expected property name')->lexeme;
        $default = null;
        if ($this->match(TokenType::EQUALS)) {
            if ($mods['isReadonly']) {
                $this->error("Readonly property '\${$name}' cannot have a default value (PHP 8.2+ semantics)");
            }
            $default = $this->parseExpr();
        }

        // Property Hook: { get => expr; set => expr; }
        $hooks = [];
        if ($this->check(TokenType::LBRACE)) {
            $hooks = $this->parsePropertyHooks();
        } else {
            $this->consume(TokenType::SEMICOLON, 'Expected ;');
        }

        return new PropertyDeclNode($name, $type, $mods['vis'], $default, $hooks, $mods['isStatic'], $mods['isReadonly']);
    }

    /** 解析 Property Hook 体: { get => expr; | get { stmts } set => expr; | set { stmts } } */
    private function parsePropertyHooks(): array
    {
        $this->consume(TokenType::LBRACE, 'Expected {');
        $hooks = [];
        while (!$this->check(TokenType::RBRACE) && !$this->check(TokenType::EOF)) {
            $kindTok = $this->consume(TokenType::IDENTIFIER, 'Expected get or set in property hook');
            $kind = strtolower($kindTok->lexeme);
            if ($kind !== 'get' && $kind !== 'set') {
                $this->error("Expected 'get' or 'set' in property hook, got '{$kindTok->lexeme}'");
            }

            if ($this->match(TokenType::DOUBLE_ARROW)) {
                // 短形式: get => expr;
                $expr = $this->parseExpr();
                $this->consume(TokenType::SEMICOLON, 'Expected ; after hook expression');
                $hooks[] = new PropertyHook($kind, $expr, []);
            } else {
                // 块形式: get { stmts }
                $this->consume(TokenType::LBRACE, 'Expected { for hook body');
                $body = $this->parseBlock();
                $this->consume(TokenType::RBRACE, 'Expected } after hook body');
                $hooks[] = new PropertyHook($kind, null, $body);
            }
        }
        $this->consume(TokenType::RBRACE, 'Expected } after property hooks');
        return $hooks;
    }

    // ============================================================
    // method → visibility FUNCTION name LPAREN params? RPAREN COLON type LBRACE stmt* RBRACE
    // ============================================================
    private function parseMethod(array $attributes = []): MethodNode
    {
        $mods = $this->parseModifiers();
        if ($mods['isReadonly']) {
            $this->error("Methods cannot be marked readonly (readonly only applies to properties and classes)");
        }

        $this->consume(TokenType::FUNCTION, 'Expected function keyword');

        // name: IDENTIFIER | CONSTRUCT | DESTRUCT | 类型关键字（可用作方法名）
        $nameToken = $this->parseMethodName();
        $name = $nameToken->lexeme;

        // 参数 (构造函数用 parseConstructorParams 支持属性提升)
        $this->consume(TokenType::LPAREN, 'Expected (');
        $params = [];
        $promoted = [];
        $isCtor = ($name === '__construct');
        if (!$this->check(TokenType::RPAREN)) {
            if ($isCtor) {
                [$params, $promoted] = $this->parseConstructorParams();
            } else {
                $params = $this->parseParams();
            }
        }
        $this->consume(TokenType::RPAREN, 'Expected )');

        // 返回类型（__construct/__destruct 禁止声明返回类型）
        $returnType = 'void';
        if ($this->match(TokenType::COLON)) {
            if ($name === '__construct' || $name === '__destruct') {
                $this->error("{$name}() cannot declare a return type");
            }
            $returnType = $this->parseType();
        }

        // 方法体 (abstract methods end with ; instead of {})
        $body = [];
        $isGen = false;
        if ($this->match(TokenType::SEMICOLON)) {
            $body = null; // abstract method
        } else {
            $this->consume(TokenType::LBRACE, 'Expected {');
            $this->genStack[] = false;
            // 构造函数属性提升：注入 $this->prop = $prop 到方法体开头
            foreach ($promoted as $pp) {
                $pname = ltrim($pp->name, '$');
                $body[] = new AssignPropStmtNode(
                    new PropertyAccessExpr(new VariableExpr('$this'), $pname),
                    new VariableExpr($pp->name)
                );
            }
            while (!$this->check(TokenType::RBRACE) && !$this->check(TokenType::EOF)) {
                $body[] = $this->parseStmt();
            }
            $isGen = array_pop($this->genStack);
            $this->consume(TokenType::RBRACE, 'Expected }');
        }

        return new MethodNode($name, $mods['vis'], $params, $returnType, $body, $promoted, $isGen, $mods['isStatic'], $attributes);
    }

    // ============================================================
    // 参数, 语句, 表达式...
    // ============================================================

    /** @return ParamNode[] */
    private function parseParams(): array
    {
        $params = [];
        $seenDefault = false;
        do {
            $param = $this->parseParam();
            // 检查：默认值参数后面不能有非默认值参数
            if ($seenDefault && $param->default === null) {
                $this->error('Default value parameters must be at the end of parameter list');
            }
            if ($param->default !== null) {
                $seenDefault = true;
            }
            $params[] = $param;
        } while ($this->match(TokenType::COMMA));
        return $params;
    }

    /** @return array{0: ParamNode[], 1: PropertyDeclNode[]} [params, promoted props] */
    private function parseConstructorParams(): array
    {
        $params = [];
        $promoted = [];
        do {
            if ($this->check(TokenType::RPAREN)) break; // trailing comma
            // Constructor property promotion: [readonly] (public|private) [readonly] type $x
            //   readonly 可在 visibility 前或后
            if ($this->check(TokenType::READONLY_KW)
                || $this->check(TokenType::PUBLIC_KW)
                || $this->check(TokenType::PRIVATE_KW)) {
                [$vis, $isReadonly] = $this->parseVisibility();
                $type = $this->parseType();
                $pName = $this->consume(TokenType::IDENTIFIER, 'Expected property name')->lexeme;
                $promoted[] = new PropertyDeclNode($pName, $type, $vis, null, [], false, $isReadonly);
                $params[] = new ParamNode($type, $pName, false, null, $isReadonly);
            } else {
                $params[] = $this->parseParam();
            }
        } while ($this->match(TokenType::COMMA));
        return [$params, $promoted];
    }

    private function parseParam(): ParamNode
    {
        $type = $this->parseType();
        // & — pass-by-reference (int &$x → C: int *x)
        $byRef = false;
        if ($this->match(TokenType::AMP)) {
            $byRef = true;
        }
        $this->consume(TokenType::IDENTIFIER, 'Expected parameter name');
        $varName = $this->previous()->lexeme;
        // 默认值: = expr（支持任意表达式，PHP 8.1+ 语义）
        // 使用 parseExpr 而非 parsePrimary，允许 `= 1 + 2`、`= CONST`、`= $a ?? 'x'` 等
        $default = null;
        if ($this->match(TokenType::EQUALS)) {
            // PHP 8.4+ 弃用隐式 nullable，TinyPHP 不支持 ?callable，故 callable 不允许默认值
            if ($type === 'callable') {
                $this->error("callable parameter '\${$varName}' cannot have a default value (PHP 8.4+ deprecated implicit nullable; TinyPHP does not support ?callable)");
            }
            $default = $this->parseExpr();
        }
        return new ParamNode($type, $varName, $byRef, $default);
    }

    private function parseStmt(): StmtNode
    {
        // 条件编译指令在函数体内也可出现（#if/#elseif/#else/#endif）
        //   非命中分支已由 skipCtBranch() 跳过，不会到达此处
        //   到达此处的 #if 必为命中分支的边界，消费后继续解析下一条语句
        if ($this->tryCtDirective()) {
            // 条件编译指令本身不产生 AST 节点
            //   返回一个空表达式语句占位（实际上 parseBlock 会继续循环）
            //   但 parseStmt 要求返回 StmtNode — 用空的 echo 占位
            //   更好的做法：让 parseBlock 跳过条件编译指令
            //   为保持简单，这里返回一个 NopStmtNode
            return new NopStmtNode();
        }
        if ($this->match(TokenType::ECHO_KW))       return $this->parseEchoStmt();
        if ($this->match(TokenType::RETURN_KW))     return $this->parseReturnStmt();
        if ($this->match(TokenType::IF_KW))         return $this->parseIfStmt();
        if ($this->match(TokenType::WHILE_KW))      return $this->parseWhileStmt();
        if ($this->match(TokenType::FOR_KW))        return $this->parseForStmt();
        if ($this->match(TokenType::FOREACH_KW))    return $this->parseForeachStmt();
        if ($this->match(TokenType::DO_KW))         return $this->parseDoWhileStmt();
        if ($this->match(TokenType::LIST_KW))       return $this->parseListStmt();
        // 函数内 static 变量: static [type] $var = expr;
        //   注意: 类成员的 static 已在 parsePropertyDecl/parseMethod 中处理，
        //   到达 parseStmt 的 STATIC_KW 必为函数内 static 局部变量
        if ($this->check(TokenType::STATIC_KW) && $this->isStaticLocalStart()) {
            return $this->parseStaticStmt();
        }
        // 函数内 const: const [type] NAME = value;
        //   PHP 8.3+ 函数内常量，等价于 C 函数内 static const 变量
        if ($this->check(TokenType::CONST_KW)) {
            return $this->parseConstStmt();
        }
        // 短语法: [$a, $b] = expr
        if ($this->check(TokenType::LBRACKET) && $this->isShortList()) {
            $this->advance(); // consume LBRACKET
            return $this->parseListStmt();
        }
        if ($this->match(TokenType::SWITCH_KW))     return $this->parseSwitchStmt();
        if ($this->match(TokenType::GOTO))          return $this->parseGotoStmt();
        if ($this->match(TokenType::TRY_KW))        return $this->parseTryStmt();
        if ($this->match(TokenType::THROW_KW))      return $this->parseThrowStmt();
        if ($this->match(TokenType::DEFER_KW))      return $this->parseDeferStmt();
        if ($this->match(TokenType::BREAK_KW))      { $lvl = $this->parseBreakLevel(); $this->consume(TokenType::SEMICOLON, 'Expected ;'); return new BreakStmtNode($lvl); }
        if ($this->match(TokenType::CONTINUE_KW))   { $lvl = $this->parseBreakLevel(); $this->consume(TokenType::SEMICOLON, 'Expected ;'); return new ContinueStmtNode($lvl); }
        // 标签: IDENTIFIER COLON
        if ($this->check(TokenType::IDENTIFIER) && $this->checkNext(TokenType::COLON)) {
            $label = $this->advance()->lexeme;
            $this->advance(); // skip :
            return new LabelStmtNode($label);
        }
        // 赋值: $var = expr;  或  TYPE $var = expr; (可选类型标记)
        if ($this->check(TokenType::IDENTIFIER) && $this->checkNext(TokenType::EQUALS)) {
            return $this->parseAssignStmt();
        }
        // 带类型标记的赋值: int $x = ...;  ClassName $x = ...;  C.Type $x = ...;
        if ($this->isTypedAssignStart()) {
            return $this->parseAssignStmt(type: $this->parseType());
        }
        // 表达式语句
        return $this->parseExprStmt();
    }

    // if (cond) body (elseif (cond) body)* (else body)?
    //   body 可以是 { block } 或单语句（无大括号）
    private function parseIfStmt(): IfStmtNode
    {
        $this->consume(TokenType::LPAREN, 'Expected (' );
        $cond = $this->parseExpr();
        $this->consume(TokenType::RPAREN, 'Expected )');
        $thenBody = $this->parseIfBody();

        $elseifs = [];
        $elseBody = [];

        while (true) {
            if ($this->match(TokenType::ELSEIF_KW)) {
                $this->consume(TokenType::LPAREN, 'Expected (' );
                $eCond = $this->parseExpr();
                $this->consume(TokenType::RPAREN, 'Expected )');
                $elseifs[] = new ElseIfBranch($eCond, $this->parseIfBody());
            } elseif ($this->check(TokenType::ELSE_KW) && $this->peek(1)->type === TokenType::IF_KW) {
                // else if (...) → 转为 elseif
                $this->advance(); // else
                $this->advance(); // if
                $this->consume(TokenType::LPAREN, 'Expected (' );
                $eCond = $this->parseExpr();
                $this->consume(TokenType::RPAREN, 'Expected )');
                $elseifs[] = new ElseIfBranch($eCond, $this->parseIfBody());
            } elseif ($this->match(TokenType::ELSE_KW)) {
                if (!empty($elseBody)) {
                    $this->error('Multiple else blocks');
                }
                $elseBody = $this->parseIfBody();
            } else {
                break;
            }
        }

        return new IfStmtNode($cond, $thenBody, $elseifs, $elseBody);
    }

    /** 解析 if/elseif/else 体: { block } 或单语句（无大括号） */
    private function parseIfBody(): array
    {
        if ($this->check(TokenType::LBRACE)) {
            $this->advance();
            $body = $this->parseBlock();
            $this->consume(TokenType::RBRACE, 'Expected }');
            return $body;
        }
        // 单语句体: if (cond) $stmt;
        return [$this->parseStmt()];
    }

    // while (cond) { body }
    private function parseWhileStmt(): WhileStmtNode
    {
        $this->consume(TokenType::LPAREN, 'Expected (' );
        $cond = $this->parseExpr();
        $this->consume(TokenType::RPAREN, 'Expected )');
        $this->consume(TokenType::LBRACE, 'Expected {');
        $body = $this->parseBlock();
        $this->consume(TokenType::RBRACE, 'Expected }');
        return new WhileStmtNode($cond, $body);
    }

    // goto LABEL;
    private function parseGotoStmt(): GotoStmtNode
    {
        $label = $this->consume(TokenType::IDENTIFIER, 'Expected label name')->lexeme;
        $this->consume(TokenType::SEMICOLON, 'Expected ;');
        return new GotoStmtNode($label);
    }

    // try { body } catch (Type $e) { body }* finally { body }?
    private function parseTryStmt(): TryStmtNode
    {
        $this->consume(TokenType::LBRACE, 'Expected {');
        $tryBody = $this->parseBlock();
        $this->consume(TokenType::RBRACE, 'Expected }');

        $catchClauses = [];
        $finallyBody = [];

        // 支持多 catch 子句
        while ($this->match(TokenType::CATCH_KW)) {
            $this->consume(TokenType::LPAREN, 'Expected (');
            $catchType = $this->parseType();
            $t = $this->consume(TokenType::IDENTIFIER, 'Expected catch variable')->lexeme;
            $catchVar = ltrim($t, '$');
            $this->consume(TokenType::RPAREN, 'Expected )');
            $this->consume(TokenType::LBRACE, 'Expected {');
            $catchBody = $this->parseBlock();
            $this->consume(TokenType::RBRACE, 'Expected }');
            $catchClauses[] = ['type' => $catchType, 'var' => $catchVar, 'body' => $catchBody];
        }

        // finally { ... }
        if ($this->match(TokenType::FINALLY_KW)) {
            $this->consume(TokenType::LBRACE, 'Expected {');
            $finallyBody = $this->parseBlock();
            $this->consume(TokenType::RBRACE, 'Expected }');
        }

        return new TryStmtNode($tryBody, $catchClauses, $finallyBody);
    }

    // throw expr;
    private function parseThrowStmt(): ThrowStmtNode
    {
        $expr = $this->parseExpr();
        $this->consume(TokenType::SEMICOLON, 'Expected ;');
        return new ThrowStmtNode($expr);
    }

    // defer EXPR;  或  defer { block }  或  defer echo ...;
    //   defer 注册清理代码，在作用域正常退出时 LIFO 执行
    private function parseDeferStmt(): DeferStmtNode
    {
        if ($this->check(TokenType::LBRACE)) {
            $this->advance();
            $body = $this->parseBlock();
            $this->consume(TokenType::RBRACE, 'Expected }');
            return new DeferStmtNode($body);
        }
        // defer echo expr; → echo 语句
        if ($this->match(TokenType::ECHO_KW)) {
            $stmt = $this->parseEchoStmt();
            return new DeferStmtNode([$stmt]);
        }
        // defer EXPR; → 包装为表达式语句
        $expr = $this->parseExpr();
        $this->consume(TokenType::SEMICOLON, 'Expected ;');
        return new DeferStmtNode([new ExprStmtNode($expr)]);
    }

    // do { body } while (cond);
    private function parseDoWhileStmt(): DoWhileStmtNode
    {
        $this->consume(TokenType::LBRACE, 'Expected {');
        $body = $this->parseBlock();
        $this->consume(TokenType::RBRACE, 'Expected }');
        $this->consume(TokenType::WHILE_KW, 'Expected while');
        $this->consume(TokenType::LPAREN, 'Expected (');
        $cond = $this->parseExpr();
        $this->consume(TokenType::RPAREN, 'Expected )');
        $this->consume(TokenType::SEMICOLON, 'Expected ;');
        return new DoWhileStmtNode($cond, $body);
    }

    /** 解析 break/continue 的可选层级参数（break 2; / continue 2;） */
    private function parseBreakLevel(): int
    {
        if ($this->check(TokenType::INT_LIT)) {
            $lvl = (int)$this->advance()->lexeme;
            if ($lvl < 1) {
                $this->error("break/continue level must be >= 1, got {$lvl}");
            }
            return $lvl;
        }
        return 1;
    }

    // list($a, $b) = expr;
    private function parseListStmt(bool $nested = false): ListStmtNode
    {
        $short = $this->previous()->type === TokenType::LBRACKET;
        if (!$short) {
            $this->consume(TokenType::LPAREN, 'Expected (');
        }
        [$vars, $keyedEntries] = $this->parseListVars();
        if ($short) {
            $this->consume(TokenType::RBRACKET, 'Expected ]');
        } else {
            $this->consume(TokenType::RPAREN, 'Expected )');
        }
        if ($nested) {
            // Nested list: no = expr
            return new ListStmtNode($vars, new NullLiteralExpr(), $short, $keyedEntries);
        }
        $this->consume(TokenType::EQUALS, 'Expected =');
        $expr = $this->parseExpr();
        $this->consume(TokenType::SEMICOLON, 'Expected ;');
        return new ListStmtNode($vars, $expr, $short, $keyedEntries);
    }

    /**
     * @return array{0: array, 1: array}  [$vars, $keyedEntries]
     *   $vars:         (null|string|ListStmtNode)[]
     *   $keyedEntries: {key:string, var:string}[]
     */
    private function parseListVars(): array
    {
        $vars = [];
        $keyed = [];
        while (true) {
            // 空位：COMMA
            if ($this->check(TokenType::COMMA)) {
                $vars[] = null;
                $this->advance();
                continue;
            }
            // 闭合括号：结束解析
            if ($this->check(TokenType::RPAREN) || $this->check(TokenType::RBRACKET)) {
                break;
            }
            // 键名解构: STRING_LIT => $var
            if ($this->check(TokenType::STRING_LIT)) {
                $keyLit = (string)$this->peek()->literal;
                $this->advance();
                $this->consume(TokenType::DOUBLE_ARROW, 'Expected =>');
                $varName = $this->consume(TokenType::IDENTIFIER, 'Expected variable name')->lexeme;
                $keyed[] = ['key' => $keyLit, 'var' => ltrim($varName, '$')];
                // 逗号继续，否则结束
                if (!$this->check(TokenType::COMMA)) break;
                $this->advance();
                continue;
            }
            // 嵌套 list() 或 []
            if ($this->check(TokenType::LIST_KW) || $this->check(TokenType::LBRACKET)) {
                $this->advance();
                $vars[] = $this->parseListStmt(true);
                // 跳过后续逗号
                if ($this->check(TokenType::COMMA)) $this->advance();
                continue;
            }
            // 普通变量
            $varName = $this->consume(TokenType::IDENTIFIER, 'Expected variable name')->lexeme;
            $vars[] = ltrim($varName, '$');

            // 逗号继续，否则结束
            if (!$this->check(TokenType::COMMA)) break;
            $this->advance();
        }
        return [$vars, $keyed];
    }

    // for (init; cond; step) { body }
    private function parseForStmt(): ForStmtNode
    {
        $this->consume(TokenType::LPAREN, 'Expected (' );
        $init = !$this->check(TokenType::SEMICOLON) ? $this->parseForInitExpr() : null;
        $this->consume(TokenType::SEMICOLON, 'Expected ;');
        $cond = !$this->check(TokenType::SEMICOLON) ? $this->parseExpr() : null;
        $this->consume(TokenType::SEMICOLON, 'Expected ;');
        $step = !$this->check(TokenType::RPAREN) ? $this->parseForInitExpr() : null;
        $this->consume(TokenType::RPAREN, 'Expected )');
        $this->consume(TokenType::LBRACE, 'Expected {');
        $body = $this->parseBlock();
        $this->consume(TokenType::RBRACE, 'Expected }');
        return new ForStmtNode($init, $cond, $step, $body);
    }

    // for init: [type] $var = expr 或 expr_stmt
    //   支持 PHP 8.0+ 的 for (int $i = 0; ...) 带类型标记的循环变量
    private function parseForInitExpr(): ExprNode
    {
        // 可选类型标记: int $i = 0 / string $s = "" 等（for-init 忽略类型，变量已在作用域中）
        if ($this->isTypedAssignStart()) {
            $this->parseType();
        }
        if ($this->check(TokenType::IDENTIFIER) && $this->checkNext(TokenType::EQUALS)) {
            $varName = $this->advance()->lexeme;
            $this->advance(); // skip =
            $val = $this->parseExpr();
            // Return hidden assignment via BinaryExpr with special marker
            $v = new VariableExpr($varName);
            $v->line = $this->previous()->line;
            $v->column = $this->previous()->column;
            return $this->setPos(new BinaryExpr($v, '=', $val), $v->line, $v->column);
        }
        return $this->parseExpr();
    }

    // foreach ($arr as $val) / foreach ($arr as $key => $val)
    private function parseForeachStmt(): ForeachStmtNode
    {
        $this->consume(TokenType::LPAREN, 'Expected (' );
        $arr = $this->parseExpr();
        $this->consume(TokenType::AS_KW, 'Expected as');
        $keyVar = null;
        $firstVar = $this->advance()->lexeme; // could be $val or $key
        if ($this->match(TokenType::DOUBLE_ARROW)) {
            $keyVar = $firstVar;
            $valVar = $this->advance()->lexeme;
        } else {
            $valVar = $firstVar;
        }
        $this->consume(TokenType::RPAREN, 'Expected )');
        $this->consume(TokenType::LBRACE, 'Expected {');
        $body = $this->parseBlock();
        $this->consume(TokenType::RBRACE, 'Expected }');
        return new ForeachStmtNode($arr, $valVar, $keyVar, $body);
    }

    // switch (expr) { case val: stmt* break; case val: stmt* break; default: stmt* }
    private function parseSwitchStmt(): SwitchStmtNode
    {
        $this->consume(TokenType::LPAREN, 'Expected (');
        $cond = $this->parseExpr();
        $this->consume(TokenType::RPAREN, 'Expected )');
        $this->consume(TokenType::LBRACE, 'Expected {');

        $cases = [];
        $hasDefault = false;
        while (!$this->check(TokenType::RBRACE) && !$this->check(TokenType::EOF)) {
            if ($this->match(TokenType::CASE_KW)) {
                $val = $this->parseExpr();
                $this->consume(TokenType::COLON, 'Expected :');
                $body = $this->parseCaseBody();
                $cases[] = new CaseBranch($val, $body);
            } elseif ($this->match(TokenType::DEFAULT_KW)) {
                if ($hasDefault) {
                    $this->error('Switch can only have one default');
                }
                $hasDefault = true;
                $this->consume(TokenType::COLON, 'Expected :');
                $body = $this->parseCaseBody();
                $cases[] = new CaseBranch(null, $body);
            } else {
                $this->error('Expected case or default, got ' . $this->peek()->lexeme);
            }
        }
        $this->consume(TokenType::RBRACE, 'Expected }');

        return new SwitchStmtNode($cond, $cases);
    }

    /** 解析 case/default 体：stmt* 直到遇到下一个 case/default 或 } */
    /** @return StmtNode[] */
    private function parseCaseBody(): array
    {
        $stmts = [];
        while (!$this->check(TokenType::RBRACE) && !$this->check(TokenType::EOF) &&
               !$this->check(TokenType::CASE_KW) && !$this->check(TokenType::DEFAULT_KW)) {
            $stmts[] = $this->parseStmt();
        }
        return $stmts;
    }

    /** @return StmtNode[] */
    private function parseBlock(): array
    {
        $stmts = [];
        while (!$this->check(TokenType::RBRACE) && !$this->check(TokenType::EOF)) {
            $stmts[] = $this->parseStmt();
        }
        return $stmts;
    }

    private function parseEchoStmt(): EchoStmtNode
    {
        $exprs = [];
        do {
            $exprs[] = $this->parseExpr();
        } while ($this->match(TokenType::COMMA));
        $this->consume(TokenType::SEMICOLON, 'Expected ;');
        return new EchoStmtNode($exprs);
    }

    private function parseReturnStmt(): ReturnStmtNode
    {
        $expr = null;
        if (!$this->check(TokenType::SEMICOLON)) {
            $expr = $this->parseExpr();
        }
        $this->consume(TokenType::SEMICOLON, 'Expected ;');
        return new ReturnStmtNode($expr);
    }

    /** yield | yield expr | yield key => expr
     *  标记当前函数为生成器（genStack 栈顶置 true），返回 YieldExpr。
     *  注意：不消费末尾分号——由调用方（parseExprStmt 或赋值语境）处理 */
    private function parseYieldExpr(): ExprNode
    {
        if (!empty($this->genStack)) {
            $this->genStack[array_key_last($this->genStack)] = true;
        }
        $line = $this->previous()->line;
        $col  = $this->previous()->column;

        // yield from expr  — 委托子生成器/可迭代对象
        if ($this->check(TokenType::IDENTIFIER) && $this->peek()->lexeme === 'from') {
            $this->advance();
            $inner = $this->parseExpr();
            return $this->setPos(new YieldFromExpr($inner), $line, $col);
        }

        // yield;  或  yield)  →  yield NULL
        if ($this->check(TokenType::SEMICOLON) || $this->check(TokenType::RPAREN)
            || $this->check(TokenType::COMMA)  || $this->check(TokenType::RBRACKET)) {
            return $this->setPos(new YieldExpr(null, null), $line, $col);
        }

        $value = $this->parseExpr();
        $key = null;
        if ($this->match(TokenType::DOUBLE_ARROW)) {
            $key = $value;
            $value = $this->parseExpr();
        }
        return $this->setPos(new YieldExpr($key, $value), $line, $col);
    }

    private function parseAssignStmt(?string $type = null): StmtNode
    {
        $varName = $this->advance()->lexeme;
        $this->consume(TokenType::EQUALS, 'Expected =');

        // 链式赋值: $a = $b = 1 → 展开为 $b = 1; $a = $b;
        if ($this->check(TokenType::IDENTIFIER)
            && str_starts_with($this->peek()->lexeme, '$')
            && $this->checkNext(TokenType::EQUALS)) {
            $innerVarName = $this->peek()->lexeme;
            $innerStmt = $this->parseAssignStmt();
            $outerStmt = new AssignStmtNode($varName, new VariableExpr($innerVarName), $type);
            $stmts = $innerStmt instanceof BlockStmtNode ? $innerStmt->stmts : [$innerStmt];
            $stmts[] = $outerStmt;
            return new BlockStmtNode($stmts);
        }

        $expr = $this->parseExpr();
        $this->consume(TokenType::SEMICOLON, 'Expected ;');
        return new AssignStmtNode($varName, $expr, $type);
    }

    /**
     * 检测 STATIC_KW 是否为函数内 static 局部变量（而非其他用途）。
     *   static $var = ...;        → true
     *   static type $var = ...;   → true (TinyPHP 扩展: 允许带类型标记)
     *   static function ...       → false (静态方法，由 parseMethod 处理)
     *   static public int $x ...  → false (类成员，由 parsePropertyDecl 处理)
     */
    private function isStaticLocalStart(): bool
    {
        // static 后必须跟 $var 或 类型 $var
        $n1 = $this->peek(1)->type;
        // static $var
        if ($n1 === TokenType::IDENTIFIER && str_starts_with($this->peek(1)->lexeme, '$')) {
            return true;
        }
        // static type $var — 类型标记后跟 $var
        $nativeTypeTokens = [
            TokenType::TYPE_INT, TokenType::TYPE_FLOAT, TokenType::TYPE_STRING,
            TokenType::TYPE_BOOL, TokenType::TYPE_ARRAY, TokenType::TYPE_MIXED,
        ];
        if (in_array($n1, $nativeTypeTokens, true)) {
            $n2 = $this->peek(2);
            return $n2->type === TokenType::IDENTIFIER && str_starts_with($n2->lexeme, '$');
        }
        return false;
    }

    /** 解析函数内 static 变量: static [type] $var = expr; */
    private function parseStaticStmt(): StmtNode
    {
        $this->consume(TokenType::STATIC_KW, 'Expected static');
        // 可选类型标记
        $type = null;
        $nativeTypeTokens = [
            TokenType::TYPE_INT, TokenType::TYPE_FLOAT, TokenType::TYPE_STRING,
            TokenType::TYPE_BOOL, TokenType::TYPE_ARRAY, TokenType::TYPE_MIXED,
        ];
        if (in_array($this->peek()->type, $nativeTypeTokens, true)
            && $this->peek(1)->type === TokenType::IDENTIFIER
            && str_starts_with($this->peek(1)->lexeme, '$')) {
            $type = $this->parseType();
        }
        $varName = $this->consume(TokenType::IDENTIFIER, 'Expected variable name')->lexeme;
        // PHP 语义: static $var 必须有初始化值（否则默认 null）
        $init = null;
        if ($this->match(TokenType::EQUALS)) {
            $init = $this->parseExpr();
        }
        $this->consume(TokenType::SEMICOLON, 'Expected ;');
        return new StaticStmtNode($varName, $type, $init);
    }

    /**
     * 解析函数内 const: const [type] NAME = value;
     *   类型标记可选（与顶层 const 一致）
     *   值必须是字面量（与顶层 const 一致）
     */
    private function parseConstStmt(): StmtNode
    {
        $this->consume(TokenType::CONST_KW, 'Expected const');
        // 可选类型标记探测（与 parseConstDecl 相同逻辑）
        $type = null;
        $t1 = $this->peek(0);
        $typeStartTokens = [
            TokenType::TYPE_INT, TokenType::TYPE_FLOAT, TokenType::TYPE_STRING,
            TokenType::TYPE_BOOL, TokenType::TYPE_ARRAY, TokenType::TYPE_MIXED,
            TokenType::IDENTIFIER, // 类名/C 类型
        ];
        if (in_array($t1->type, $typeStartTokens, true)) {
            $hasType = false;
            if ($t1->type === TokenType::IDENTIFIER) {
                $t2 = $this->peek(1);
                if ($t2->type === TokenType::DOT) {
                    $hasType = true;
                } elseif ($t2->type === TokenType::IDENTIFIER) {
                    $hasType = true;
                }
            } else {
                $hasType = $this->peek(1)->type === TokenType::IDENTIFIER;
            }
            if ($hasType) {
                $type = $this->parseType();
            }
        }

        $name = $this->consume(TokenType::IDENTIFIER, 'Expected constant name')->lexeme;
        $this->consume(TokenType::EQUALS, 'Expected =');
        $value = $this->parsePrimary(); // 只接受字面量
        $this->consume(TokenType::SEMICOLON, 'Expected ;');
        return new ConstStmtNode($name, $value, $type);
    }

    /** 检测当前是否为带类型标记的赋值开头：TYPE $var = ... */
    private function isTypedAssignStart(): bool
    {
        $t1 = $this->peek(0);
        // 原生类型关键字: int $x = ... / string $x = ... 等
        $nativeTypeTokens = [
            TokenType::TYPE_INT, TokenType::TYPE_FLOAT, TokenType::TYPE_STRING,
            TokenType::TYPE_BOOL, TokenType::TYPE_ARRAY, TokenType::TYPE_MIXED,
        ];
        if (in_array($t1->type, $nativeTypeTokens, true)) {
            return $this->peek(1)->type === TokenType::IDENTIFIER
                && str_starts_with($this->peek(1)->lexeme, '$');
        }
        // \Namespace\Class $x = ... (全局命名空间前缀)
        if ($t1->type === TokenType::NS_SEP) {
            $t2 = $this->peek(1);
            if ($t2->type !== TokenType::IDENTIFIER || str_starts_with($t2->lexeme, '$')) return false;
            // 向后查找第一个 $var 标记
            $i = 2;
            while ($this->peek($i)->type === TokenType::NS_SEP
                && $this->peek($i + 1)->type === TokenType::IDENTIFIER) {
                $i += 2;
            }
            return $this->peek($i)->type === TokenType::IDENTIFIER
                && str_starts_with($this->peek($i)->lexeme, '$');
        }
        // IDENTIFIER 开头: ClassName $x = ... 或 C.Type $x = ...
        if ($t1->type === TokenType::IDENTIFIER && !str_starts_with($t1->lexeme, '$')) {
            $t2 = $this->peek(1);
            // C.Type $x = ... （支持 C.int* $x, C.void* $x 等指针后缀）
            // 注意: C.int/C.float 等的 int/float 是 TYPE_* 关键字，不是 IDENTIFIER
            if ($t2->type === TokenType::DOT) {
                $t3 = $this->peek(2);
                $validCTypeTokens = [
                    TokenType::IDENTIFIER,
                    TokenType::TYPE_INT, TokenType::TYPE_FLOAT,
                    TokenType::TYPE_STRING, TokenType::TYPE_BOOL,
                    TokenType::TYPE_VOID,
                ];
                if (!in_array($t3->type, $validCTypeTokens, true)) return false;
                // 跳过指针后缀 *: C.int* $x, C.char** $x, C.char*** $x
                // 注意: ** 被 Lexer 识别为 STAR_STAR（幂运算符），需同时处理
                $i = 3;
                while ($this->peek($i)->type === TokenType::STAR
                    || $this->peek($i)->type === TokenType::STAR_STAR) {
                    $i++;
                }
                return $this->peek($i)->type === TokenType::IDENTIFIER
                    && str_starts_with($this->peek($i)->lexeme, '$');
            }
            // ClassName $x = ...
            if ($t2->type === TokenType::IDENTIFIER && str_starts_with($t2->lexeme, '$')) {
                return true;
            }
            // Namespace\Class $x = ...
            if ($t2->type === TokenType::NS_SEP) {
                $i = 1;
                while ($this->peek($i)->type === TokenType::NS_SEP
                    && $this->peek($i + 1)->type === TokenType::IDENTIFIER) {
                    $i += 2;
                }
                return $this->peek($i)->type === TokenType::IDENTIFIER
                    && str_starts_with($this->peek($i)->lexeme, '$');
            }
        }
        return false;
    }

    private function parseExprStmt(): StmtNode
    {
        // yield expr;  → 表达式语句
        if ($this->match(TokenType::YIELD_KW)) {
            $expr = $this->parseYieldExpr();
            $this->consume(TokenType::SEMICOLON, 'Expected ;');
            return new ExprStmtNode($expr);
        }
        $expr = $this->parseExpr();
        // 数组追加赋值: $arr[] = value 或 $obj->prop[] = value
        if ($expr instanceof ArrayAppendExpr && $this->check(TokenType::EQUALS)) {
            $this->advance();
            $val = $this->parseExpr();
            $this->consume(TokenType::SEMICOLON, 'Expected ;');
            return new AssignArrayPushStmtNode($expr->target, $val);
        }
        // 数组元素赋值: $arr[$i] = value
        if ($expr instanceof ArrayAccessExpr && $this->check(TokenType::EQUALS)) {
            $this->advance();
            $val = $this->parseExpr();
            $this->consume(TokenType::SEMICOLON, 'Expected ;');
            return new AssignArrayStmtNode($expr, $val);
        }
        // 属性赋值: $this->prop = value
        if ($expr instanceof PropertyAccessExpr && $this->check(TokenType::EQUALS)) {
            $this->advance();
            $val = $this->parseExpr();
            $this->consume(TokenType::SEMICOLON, 'Expected ;');
            return new AssignPropStmtNode($expr, $val);
        }
        // 复合赋值: $var += expr 或 $this->prop += expr
        $compOps = [TokenType::PLUS_EQ, TokenType::MINUS_EQ, TokenType::STAR_EQ, TokenType::SLASH_EQ, TokenType::DOT_EQ];
        foreach ($compOps as $op) {
            if ($this->check($op)) {
                $this->advance();
                $val = $this->parseExpr();
                $this->consume(TokenType::SEMICOLON, 'Expected ;');
                return new ExprStmtNode($this->setPos(new CompoundAssignExpr($expr, $op->value, $val), $expr->line, $expr->column));
            }
        }
        $this->consume(TokenType::SEMICOLON, 'Expected ;');
        return new ExprStmtNode($expr);
    }

    private function parseExpr(): ExprNode
    {
        return $this->parsePipe();
    }

    // pipe operator: left |> right （优先级低于 ?:，左结合）
    private function parsePipe(): ExprNode
    {
        $left = $this->parseTernary();
        while ($this->match(TokenType::PIPE_GT)) {
            $right = $this->parseTernary();
            $left = $this->setPos(new PipeExpr($left, $right), $left->line, $left->column);
        }
        return $left;
    }

    // ?: ternary （优先级最低，右结合）
    private function parseTernary(): ExprNode
    {
        $cond = $this->parseNullCoalesce();
        // ?-> is nullsafe, not ternary
        if ($this->check(TokenType::QUEST) && $this->peek(1)->type === TokenType::ARROW) {
            return $cond; // let parsePostfix handle nullsafe chain
        }
        if ($this->match(TokenType::QUEST)) {
            $then = $this->parseExpr();
            $this->consume(TokenType::COLON, 'Expected :');
            $else = $this->parseExpr();
            return $this->setPos(new TernaryExpr($cond, $then, $else), $cond->line, $cond->column);
        }
        return $cond;
    }

    // ?? （右结合，优先级高于 ?:，低于 ||）
    private function parseNullCoalesce(): ExprNode
    {
        $left = $this->parseLogicalOr();
        while ($this->match(TokenType::QUEST_QUEST)) {
            // 右结合：右侧递归调用自身，使 $a ?? $b ?? $c 解析为 $a ?? ($b ?? $c)
            $right = $this->parseNullCoalesce();
            $left = $this->setPos(new NullCoalesceExpr($left, $right), $left->line, $left->column);
        }
        return $left;
    }

    // ||
    private function parseLogicalOr(): ExprNode
    {
        $left = $this->parseLogicalAnd();
        while ($this->match(TokenType::OR_OR)) {
            $right = $this->parseLogicalAnd();
            $left = $this->setPos(new BinaryExpr($left, '||', $right), $left->line, $left->column);
        }
        return $left;
    }

    // &&
    private function parseLogicalAnd(): ExprNode
    {
        $left = $this->parseBitwiseOr();
        while ($this->match(TokenType::AND_AND)) {
            $right = $this->parseBitwiseOr();
            $left = $this->setPos(new BinaryExpr($left, '&&', $right), $left->line, $left->column);
        }
        return $left;
    }

    // |
    private function parseBitwiseOr(): ExprNode
    {
        $left = $this->parseBitwiseXor();
        while ($this->match(TokenType::PIPE)) {
            $right = $this->parseBitwiseXor();
            $left = $this->setPos(new BinaryExpr($left, '|', $right), $left->line, $left->column);
        }
        return $left;
    }

    // ^
    private function parseBitwiseXor(): ExprNode
    {
        $left = $this->parseBitwiseAnd();
        while ($this->match(TokenType::CARET)) {
            $right = $this->parseBitwiseAnd();
            $left = $this->setPos(new BinaryExpr($left, '^', $right), $left->line, $left->column);
        }
        return $left;
    }

    // &
    private function parseBitwiseAnd(): ExprNode
    {
        $left = $this->parseEquality();
        while ($this->match(TokenType::AMP)) {
            $right = $this->parseEquality();
            $left = $this->setPos(new BinaryExpr($left, '&', $right), $left->line, $left->column);
        }
        return $left;
    }

    // == != <=>
    private function parseEquality(): ExprNode
    {
        $left = $this->parseComparison();
        while ($this->match(TokenType::EQ) || $this->match(TokenType::NE) || $this->match(TokenType::IDENTICAL) || $this->match(TokenType::NOT_IDENTICAL) || $this->match(TokenType::SPACESHIP)) {
            $op = $this->previous()->lexeme;
            $right = $this->parseComparison();
            $left = $this->setPos(new BinaryExpr($left, $op, $right), $left->line, $left->column);
        }
        return $left;
    }

    // < > <= >=   << >>
    private function parseComparison(): ExprNode
    {
        $left = $this->parseShift();
        while ($this->match(TokenType::LT) || $this->match(TokenType::GT) ||
               $this->match(TokenType::LE) || $this->match(TokenType::GE)) {
            $op = $this->previous()->lexeme;
            $right = $this->parseShift();
            $left = $this->setPos(new BinaryExpr($left, $op, $right), $left->line, $left->column);
        }
        return $left;
    }

    // << >>
    private function parseShift(): ExprNode
    {
        $left = $this->parseAdditive();
        while ($this->match(TokenType::LT_LT) || $this->match(TokenType::GT_GT)) {
            $op = $this->previous()->lexeme;
            $right = $this->parseAdditive();
            $left = $this->setPos(new BinaryExpr($left, $op, $right), $left->line, $left->column);
        }
        return $left;
    }

    // + - .
    private function parseAdditive(): ExprNode
    {
        $left = $this->parseMultiplicative();
        while ($this->match(TokenType::PLUS) || $this->match(TokenType::MINUS) || $this->match(TokenType::DOT)) {
            $op = $this->previous()->lexeme;
            $right = $this->parseMultiplicative();
            $left = $this->setPos(new BinaryExpr($left, $op, $right), $left->line, $left->column);
        }
        return $left;
    }

    // * / %
    private function parseMultiplicative(): ExprNode
    {
        $left = $this->parsePower();
        while ($this->match(TokenType::STAR) || $this->match(TokenType::SLASH) || $this->match(TokenType::MOD)) {
            $op = $this->previous()->lexeme;
            $right = $this->parsePower();
            $left = $this->setPos(new BinaryExpr($left, $op, $right), $left->line, $left->column);
        }
        return $left;
    }

    // ** （右结合，幂运算）
    private function parsePower(): ExprNode
    {
        $left = $this->parseUnary();
        if ($this->match(TokenType::STAR_STAR)) {
            $right = $this->parsePower();
            $left = $this->setPos(new BinaryExpr($left, '**', $right), $left->line, $left->column);
        }
        return $left;
    }

    // ! - ++ -- ~ (一元)
    private function parseUnary(): ExprNode
    {
        $line = $this->peek()->line;
        $col  = $this->peek()->column;

        if ($this->match(TokenType::BANG)) {
            return $this->setPos(new UnaryExpr('!', $this->parseUnary()), $line, $col);
        }
        if ($this->match(TokenType::MINUS)) {
            return $this->setPos(new UnaryExpr('-', $this->parseUnary()), $line, $col);
        }
        if ($this->match(TokenType::INC)) {
            return $this->setPos(new UnaryExpr('++', $this->parseUnary()), $line, $col);
        }
        if ($this->match(TokenType::DEC)) {
            return $this->setPos(new UnaryExpr('--', $this->parseUnary()), $line, $col);
        }
        if ($this->match(TokenType::TILDE)) {
            return $this->setPos(new UnaryExpr('~', $this->parseUnary()), $line, $col);
        }
        return $this->parseInstanceof();
    }

    // instanceof （优先级高于 !，低于算术；右操作数为类名/变量/后缀表达式）
    private function parseInstanceof(): ExprNode
    {
        $left = $this->parsePostfix();
        while ($this->match(TokenType::INSTANCEOF_KW)) {
            $right = $this->parsePostfix();
            $left = $this->setPos(new BinaryExpr($left, 'instanceof', $right), $left->line, $left->column);
        }
        return $left;
    }

    // 后缀链: primary (->method(args) | ->prop | [index] | ++ | --)*
    private function parsePostfix(): ExprNode
    {
        $expr = $this->parsePrimary();
        while (true) {
            if ($this->match(TokenType::ARROW) || $this->match(TokenType::NULLSAFE_ARROW)) {
                $nullsafe = $this->previous()->type === TokenType::NULLSAFE_ARROW;
                $member = $this->parseMethodName()->lexeme;
                if ($this->match(TokenType::LPAREN)) {
                    $args = $this->parseArgs();
                    $this->consume(TokenType::RPAREN, 'Expected )');
                    $expr = $this->setPos(new CallExpr($expr, $member, $args, $nullsafe), $expr->line, $expr->column);
                } else {
                    $expr = $this->setPos(new PropertyAccessExpr($expr, $member, $nullsafe), $expr->line, $expr->column);
                }
            } elseif ($this->match(TokenType::LBRACKET)) {
                // $expr[] 空下标 → ArrayAppendExpr（仅用于 $expr[] = value 赋值上下文）
                if ($this->check(TokenType::RBRACKET)) {
                    $this->advance(); // consume ]
                    $expr = $this->setPos(new ArrayAppendExpr($expr), $expr->line, $expr->column);
                } else {
                    $index = $this->parseExpr();
                    $this->consume(TokenType::RBRACKET, 'Expected ]');
                    $expr = $this->setPos(new ArrayAccessExpr($expr, $index), $expr->line, $expr->column);
                }
            } elseif ($this->match(TokenType::INC) || $this->match(TokenType::DEC)) {
                $expr = $this->setPos(new PostfixExpr($expr, $this->previous()->lexeme), $expr->line, $expr->column);
            } else {
                break;
            }
        }
        return $expr;
    }

    private function parsePrimary(): ExprNode
    {
        $line = $this->peek()->line;
        $col  = $this->peek()->column;
        $tok  = $this->peek()->type;

        // ── 简单字面量 / 魔术常量 / 委托：match 分发 ──
        // 各 arm 内 advance + 构造节点；default=null 表示复杂情况需后续 if-else 处理
        // （advance() 返回 Token 恒为 truthy，三元 false 分支永不可达，仅为满足 match 类型一致性）
        $simple = match ($tok) {
            // 字面量（需要 token 的 literal 属性）
            TokenType::STRING_LIT => $this->setPos(new StringLiteralExpr((string)$this->advance()->literal), $line, $col),
            TokenType::INT_LIT    => $this->setPos(new IntLiteralExpr((int)$this->advance()->literal), $line, $col),
            TokenType::FLOAT_LIT  => $this->setPos(new FloatLiteralExpr((float)$this->advance()->literal), $line, $col),
            // 关键字字面量（不需要 token 数据）
            TokenType::TRUE_KW    => $this->advance() ? $this->setPos(new BoolLiteralExpr(true), $line, $col) : null,
            TokenType::FALSE_KW   => $this->advance() ? $this->setPos(new BoolLiteralExpr(false), $line, $col) : null,
            TokenType::NULL_KW    => $this->advance() ? $this->setPos(new NullLiteralExpr(), $line, $col) : null,
            // 魔术常量（需要 token 的 lexeme 和 line）
            TokenType::MAGIC_LINE, TokenType::MAGIC_FILE, TokenType::MAGIC_DIR,
            TokenType::DIR_SEP, TokenType::MAGIC_CLASS, TokenType::MAGIC_METHOD,
            TokenType::MAGIC_FUNCTION, TokenType::MAGIC_NAMESPACE
                => $this->setPos(new MagicConstExpr($this->advance()->lexeme, $this->previous()->line), $line, $col),
            // 委托（advance 后调用专用解析器，parseYieldExpr 内部自处理 setPos）
            TokenType::YIELD_KW  => $this->advance() ? $this->parseYieldExpr() : null,
            // throw 表达式（PHP 8.0+）：throw 出现在表达式位置
            TokenType::THROW_KW  => $this->advance() ? $this->setPos(new ThrowExprNode($this->parseExpr()), $line, $col) : null,
            TokenType::FN_KW     => $this->advance() ? $this->setPos($this->parseArrowFunction(), $line, $col) : null,
            TokenType::FUNCTION  => $this->advance() ? $this->setPos($this->parseClosure(), $line, $col) : null,
            TokenType::LBRACKET  => $this->advance() ? $this->setPos($this->parseArrayLiteral(), $line, $col) : null,
            TokenType::NEW_KW    => $this->advance() ? $this->setPos($this->parseNewExpr(), $line, $col) : null,
            TokenType::MATCH     => $this->advance() ? $this->setPos($this->parseMatchExpr(), $line, $col) : null,
            default => null,
        };
        if ($simple !== null) return $simple;

        // 类型转换: (type) expr  |  括号分组: ( expr )
        if ($tok === TokenType::LPAREN) {
            $nextType = $this->peek(1);
            $typeTokens = [
                TokenType::TYPE_INT, TokenType::TYPE_FLOAT, TokenType::TYPE_STRING,
                TokenType::TYPE_BOOL, TokenType::TYPE_ARRAY, TokenType::TYPE_MIXED,
            ];
            if (in_array($nextType->type, $typeTokens, true)) {
                return $this->setPos($this->parseCastExpr(), $line, $col);
            }
            // (C.XXX) cast — C 类型转换: (C.void*)$x, (C.char*)$buf, (C.int)$n
            if ($nextType->type === TokenType::IDENTIFIER && $nextType->lexeme === 'C'
                && $this->peek(2)->type === TokenType::DOT) {
                return $this->setPos($this->parseCastExpr(), $line, $col);
            }
            // 括号分组: ( expr )
            if ($this->match(TokenType::LPAREN)) {
                $inner = $this->parseExpr();
                $this->consume(TokenType::RPAREN, 'Expected )');
                return $inner; // 括号不产生额外 AST 节点，直接返回内部表达式
            }
        }
        // 变量 / self 关键字 / 标识符类函数名（静态集替代原巨型 || 链）
        if (in_array($tok, self::$identifierLikeTokens, true)) {
            $name = $this->advance()->lexeme;

            // $obj->xxx / $obj?->xxx / Class::xxx
            if ($this->match(TokenType::ARROW) || $this->match(TokenType::NULLSAFE_ARROW) || $this->match(TokenType::DOUBLE_COLON)) {
                $opType = $this->previous()->type;
                $nullsafe = ($opType === TokenType::NULLSAFE_ARROW);
                $memberName = $this->parseMethodName()->lexeme;

                // EnumName::CASE → 枚举访问（需解析命名空间 + use 导入）
                //   但 EnumName::method(...) → 静态方法调用，走 CallExpr 路径
                if ($opType === TokenType::DOUBLE_COLON) {
                    $enumFq = $this->resolveEnumName($name);
                    if ($enumFq !== null && isset($this->enumNames[$enumFq])
                        && !$this->check(TokenType::LPAREN)) {
                        return $this->setPos(new EnumAccessExpr($enumFq, $memberName), $line, $col);
                    }
                }

                // 方法调用: $obj->method(args) 或 C->func(args)
                if ($this->match(TokenType::LPAREN)) {
                    $args = $this->parseArgs();
                    $this->consume(TokenType::RPAREN, 'Expected )');
                    $isRawC = ($name === 'C' && $opType !== TokenType::DOUBLE_COLON);
                    return $this->setPos(new CallExpr(new VariableExpr($name), $memberName, $args, $nullsafe, $isRawC), $line, $col);
                }

                // 属性访问: $obj->prop / $obj?->prop
                return $this->setPos(new PropertyAccessExpr(new VariableExpr($name), $memberName, $nullsafe), $line, $col);
            }

            // 函数调用（含 var_dump、闭包调用 $var()）
            if ($this->match(TokenType::LPAREN)) {
                $args = $this->parseArgs();
                $this->consume(TokenType::RPAREN, 'Expected )');
                // $var() → 闭包调用
                if (str_starts_with($name, '$')) {
                    return $this->setPos(new CallExpr(new VariableExpr($name), '__invoke', $args), $line, $col);
                }
                // Friendly errors for unsupported PHP functions
                $unsupportedFns = [
                    'eval'    => "eval() is not supported in AOT mode. Use match() or a callback dispatch map instead.",
                    'include' => "include/require is not supported in AOT mode. Use #include for C headers, or multi-file compilation.",
                    'require' => "include/require is not supported in AOT mode. Use #include for C headers, or multi-file compilation.",
                ];
                if (isset($unsupportedFns[$name])) {
                    $this->error($unsupportedFns[$name]);
                }
                // 内置函数和桥接函数不解析命名空间
                $globalFns = ['var_dump','count','exit','die','isset','empty','unset',
                    'error','time','date','sleep','usleep','hrtime',
                    // phpc 互操作（全部全局，不解析命名空间）
                    'c_int','c_str','php_int','php_str','php_str_clone',
                    'phpc_arr_int','phpc_arr_dbl','phpc_arr_str',
                    'phpc_new_arr_int','phpc_new_arr_dbl','phpc_new_arr_str','phpc_new_arr',
                    'phpc_obj','phpc_new_obj','phpc_unregister_obj','phpc_obj_steal',
                    'phpc_fn','phpc_env','phpc_new_fn','phpc_new_fn_env',
                    'phpc_fn_i32','phpc_fn_i64','phpc_fn_f64','phpc_thunk',
                    'phpc_free','phpc_free_str_arr',
                    'phpc_assert_ptr','phpc_env_pin','phpc_env_unpin',
                    'count','strlen','trim','ltrim','rtrim','substr','strpos',
                    'str_contains','str_replace','sprintf','implode','explode','join',
                    'array_push','array_pop','array_shift','array_unshift','in_array',
                    'array_key_exists','array_keys','array_values','array_merge',
                    'array_unique','array_reverse','array_slice','array_sum',
                    'array_product','array_fill','array_map','array_filter','array_reduce','sort','rsort',
                    'intval','floatval','strval','boolval',
                    'max','min','range','rand','mt_rand',
                    'random_int','random_bytes',
                    'abs','round','ceil','floor','sqrt',
                    'strtolower','strtoupper','shuffle','array_search','microtime',
                    'json_encode','json_decode','print_r','var_dump'];
                if (!in_array($name, $globalFns, true) && !str_starts_with($name, 'is_') && !str_starts_with($name, 'ctype_')
                    && !str_starts_with($name, 'c_') && !str_starts_with($name, 'php_') && !str_starts_with($name, 'phpc_')) {
                    $name = $this->resolveFunctionName($name);
                }
                return $this->setPos(new CallExpr(null, $name, $args), $line, $col);
            }

            // 数组访问: $var[expr] 或 $var[] 空下标（赋值上下文）
            if ($this->match(TokenType::LBRACKET)) {
                if ($this->check(TokenType::RBRACKET)) {
                    $this->advance(); // consume ]
                    return $this->setPos(new ArrayAppendExpr(new VariableExpr($name)), $line, $col);
                }
                $index = $this->parseExpr();
                $this->consume(TokenType::RBRACKET, 'Expected ]');
                return $this->setPos(new ArrayAccessExpr(new VariableExpr($name), $index), $line, $col);
            }

            // 常量引用：全大写名称 → 标记为常量引用（跨文件编译可行）
            // 不再 block 跨文件/跨命名空间引用——CodeGen 统一用 TPHP_CONST_ 前缀
            if (!str_starts_with($name, '$') && self::isConstantName($name)
                && $this->resolveEnumName($name) === null) {
                // 静默允许——生成的 C 代码通过 #define 解析
            }

            return $this->setPos(new VariableExpr($name), $line, $col);
        }

        $this->error("Expected expression, got '{$this->peek()->lexeme}'");
    }

    private function setPos(ExprNode $node, int $line, int $col): ExprNode
    {
        $node->line = $line;
        $node->column = $col;
        return $node;
    }

    /** 数组字面量: [ entry (, entry)* ]
     *  entry → expr (=> expr)?
     *  entry → '...' expr  (spread 展开，不允许 key) */
    private function parseArrayLiteral(): ArrayLiteralExpr
    {
        $entries = [];
        if (!$this->check(TokenType::RBRACKET)) {
            do {
                // ...$arr 展开元素（三个 DOT token）
                $isSpread = false;
                if ($this->check(TokenType::DOT) && $this->peek(1)->type === TokenType::DOT && $this->peek(2)->type === TokenType::DOT) {
                    $this->advance(); $this->advance(); $this->advance();
                    $isSpread = true;
                }
                $first = $this->parseExpr();
                if ($isSpread) {
                    // spread 不允许 key => value
                    if ($this->match(TokenType::DOUBLE_ARROW)) {
                        throw $this->error('Cannot specify key in spread element');
                    }
                    $entries[] = new ArrayEntryNode(null, $first, true);
                } else {
                    // 检查是否 key => value
                    if ($this->match(TokenType::DOUBLE_ARROW)) {
                        $val = $this->parseExpr();
                        $entries[] = new ArrayEntryNode($first, $val);
                    } else {
                        $entries[] = new ArrayEntryNode(null, $first);
                    }
                }
            } while ($this->match(TokenType::COMMA) && !$this->check(TokenType::RBRACKET));
        }
        $this->consume(TokenType::RBRACKET, 'Expected ]');
        return new ArrayLiteralExpr($entries);
    }

    /** match(expr) { val1, val2 => result, default => fallback } */
    private function parseMatchExpr(): MatchExpr
    {
        $this->consume(TokenType::LPAREN, 'Expected (');
        $cond = $this->parseExpr();
        $this->consume(TokenType::RPAREN, 'Expected )');
        $this->consume(TokenType::LBRACE, 'Expected {');

        $arms = [];
        while (!$this->check(TokenType::RBRACE) && !$this->check(TokenType::EOF)) {
            if ($this->match(TokenType::DEFAULT_KW)) {
                $this->consume(TokenType::DOUBLE_ARROW, 'Expected =>');
                $body = $this->parseExpr();
                $arms[] = new MatchArm([], $body); // empty values = default
            } else {
                $values = [];
                do {
                    $values[] = $this->parseExpr();
                } while ($this->match(TokenType::COMMA) && !$this->check(TokenType::DOUBLE_ARROW));
                $this->consume(TokenType::DOUBLE_ARROW, 'Expected =>');
                $body = $this->parseExpr();
                $arms[] = new MatchArm($values, $body);
            }
            // 可选尾随逗号
            if ($this->check(TokenType::COMMA)) $this->advance();
        }
        $this->consume(TokenType::RBRACE, 'Expected }');

        return new MatchExpr($cond, $arms);
    }

    /** 匿名函数: function ( params? ) use (vars?) : type? { body } */
    /** fn($x, $y): int => expr  → 闭包语法糖（单表达式） */
    /** fn($x, $y): int => { stmts; return expr; }  → 闭包语法糖（块体，须 return） */
    private function parseArrowFunction(): ClosureExpr
    {
        $this->consume(TokenType::LPAREN, 'Expected (' );
        $params = [];
        if (!$this->check(TokenType::RPAREN)) {
            do {
                // Arrow params: mandatory type, then $name
                if (!$this->checkTypeStart()) {
                    $this->error($this->peek(), 'Arrow function parameter requires type declaration');
                }
                $type = $this->parseType();
                $this->consume(TokenType::IDENTIFIER, 'Expected parameter name');
                $params[] = new ParamNode($type, $this->previous()->lexeme);
            } while ($this->match(TokenType::COMMA));
        }
        $this->consume(TokenType::RPAREN, 'Expected )');
        // 强制返回类型: fn(...): type =>
        $this->consume(TokenType::COLON, 'Arrow function requires return type declaration');
        $retType = $this->parseType();
        $this->consume(TokenType::DOUBLE_ARROW, 'Expected =>');
        // 块体语法: fn(...): type => { stmts; return expr; }
        if ($this->check(TokenType::LBRACE)) {
            $this->advance(); // 消费 '{'
            $this->genStack[] = false;
            $body = $this->parseBlock();
            array_pop($this->genStack);
            $this->consume(TokenType::RBRACE, 'Expected }');
            // 校验块体必须包含 return 语句（void 返回类型除外）
            if ($retType !== 'void' && !$this->blockHasReturn($body)) {
                $this->error("Arrow function block body with non-void return type must contain a return statement");
            }
            return new ClosureExpr($params, $retType, $body, []);
        }
        // 单表达式语法: fn(...): type => expr
        $bodyExpr = $this->parseExpr();
        $body = [new ReturnStmtNode($bodyExpr)];
        return new ClosureExpr($params, $retType, $body, []);
    }

    /**
     * 检查语句块是否包含 return 语句（递归搜索嵌套控制结构）
     *
     * 递归进入 if/else/for/while/foreach/switch/try/block 的函数体查找 ReturnStmtNode。
     * 语义为"包含至少一个 return"（非"所有路径都 return"），匹配错误消息
     * "must contain a return statement"。
     */
    private function blockHasReturn(array $body): bool
    {
        foreach ($body as $stmt) {
            if ($stmt instanceof ReturnStmtNode) return true;
            // 递归搜索嵌套控制结构的函数体
            if ($stmt instanceof IfStmtNode) {
                if ($this->blockHasReturn($stmt->thenBody)) return true;
                foreach ($stmt->elseifs as $elif) {
                    if ($this->blockHasReturn($elif->body)) return true;
                }
                if ($this->blockHasReturn($stmt->elseBody)) return true;
            } elseif ($stmt instanceof WhileStmtNode || $stmt instanceof DoWhileStmtNode) {
                if ($this->blockHasReturn($stmt->body)) return true;
            } elseif ($stmt instanceof ForStmtNode || $stmt instanceof ForeachStmtNode) {
                if ($this->blockHasReturn($stmt->body)) return true;
            } elseif ($stmt instanceof SwitchStmtNode) {
                foreach ($stmt->cases as $case) {
                    if ($this->blockHasReturn($case->body)) return true;
                }
            } elseif ($stmt instanceof TryStmtNode) {
                if ($this->blockHasReturn($stmt->tryBody)) return true;
                foreach ($stmt->catchClauses as $catch) {
                    if ($this->blockHasReturn($catch['body'])) return true;
                }
                if ($this->blockHasReturn($stmt->finallyBody)) return true;
            } elseif ($stmt instanceof BlockStmtNode) {
                if ($this->blockHasReturn($stmt->stmts)) return true;
            }
        }
        return false;
    }

    /** 检查当前 token 是否为类型关键字（排除 $var） */
    private function checkTypeStart(): bool
    {
        $typeTokens = [TokenType::TYPE_INT, TokenType::TYPE_FLOAT, TokenType::TYPE_STRING,
                       TokenType::TYPE_BOOL, TokenType::TYPE_ARRAY, TokenType::TYPE_MIXED,
                       TokenType::TYPE_NEVER];
        if (in_array($this->peek()->type, $typeTokens, true)) return true;
        // IDENTIFIER is a type only if not $variable
        return $this->peek()->type === TokenType::IDENTIFIER
            && !str_starts_with($this->peek()->lexeme, '$');
    }

    private function parseClosure(): ClosureExpr
    {
        $this->consume(TokenType::LPAREN, 'Expected (');
        $params = [];
        if (!$this->check(TokenType::RPAREN)) {
            $params = $this->parseParams();
        }
        $this->consume(TokenType::RPAREN, 'Expected )');

        // use ($var1, $var2) 闭包变量捕获
        $useVars = [];
        if ($this->match(TokenType::USE)) {
            $this->consume(TokenType::LPAREN, 'Expected (');
            if (!$this->check(TokenType::RPAREN)) {
                do {
                    $varName = $this->consume(TokenType::IDENTIFIER, 'Expected variable name')->lexeme;
                    $vn = ltrim($varName, '$');
                    $useVars[] = [$vn, '']; // type 暂时留空，CodeGenerator 会从 varTypes 查
                } while ($this->match(TokenType::COMMA));
            }
            $this->consume(TokenType::RPAREN, 'Expected )');
        }

        $returnType = 'void';
        if ($this->match(TokenType::COLON)) {
            $returnType = $this->parseType();
        }

        $this->consume(TokenType::LBRACE, 'Expected {');
        $this->genStack[] = false;
        $body = [];
        while (!$this->check(TokenType::RBRACE) && !$this->check(TokenType::EOF)) {
            $body[] = $this->parseStmt();
        }
        $isGen = array_pop($this->genStack);
        $this->consume(TokenType::RBRACE, 'Expected }');

        return new ClosureExpr($params, $returnType, $body, $useVars, $isGen);
    }

    /** @return ExprNode[] */
    private function parseArgs(): array
    {
        $args = [];
        if (!$this->check(TokenType::RPAREN)) {
            do {
                // `...` 参数占位符（pipe operator 上下文使用）
                if ($this->check(TokenType::DOT) && $this->peek(1)->type === TokenType::DOT && $this->peek(2)->type === TokenType::DOT) {
                    $this->advance(); $this->advance(); $this->advance();
                    $args[] = $this->setPos(new PlaceholderExpr(), $this->peek()->line, $this->peek()->column);
                } else {
                    $args[] = $this->parseExpr();
                }
            } while ($this->match(TokenType::COMMA) && !$this->check(TokenType::RPAREN));
        }
        return $args;
    }

    private function parseNewExpr(): NewExpr
    {
        $className = $this->consume(TokenType::IDENTIFIER, 'Expected class name')->lexeme;
        $this->consume(TokenType::LPAREN, 'Expected (');
        $args = $this->parseArgs();
        $this->consume(TokenType::RPAREN, 'Expected )');
        return new NewExpr($this->resolveClassName($className), $args);
    }

    private function parseCastExpr(): CastExpr
    {
        $line = $this->peek()->line;
        $col  = $this->peek()->column;
        $this->consume(TokenType::LPAREN, 'Expected (');
        $typeToken = $this->advance();
        $castType = $typeToken->lexeme; // int, float, string, bool, array
        // C.XXX cast: C.void*, C.char*, C.int, C.FILE 等（支持 * 指针后缀）
        if ($typeToken->type === TokenType::IDENTIFIER && $typeToken->lexeme === 'C'
            && $this->check(TokenType::DOT)) {
            $this->advance(); // 消费 .
            $subType = $this->advance()->lexeme;
            $castType = 'C.' . $subType;
            // 支持指针后缀: (C.void*), (C.char**), (C.char***)
            // 注意: ** 被 Lexer 识别为 STAR_STAR（幂运算符），需同时处理
            while ($this->check(TokenType::STAR) || $this->check(TokenType::STAR_STAR)) {
                $t = $this->advance();
                $castType .= ($t->type === TokenType::STAR_STAR) ? '**' : '*';
            }
        }
        $this->consume(TokenType::RPAREN, 'Expected )');
        $expr = $this->parseUnary(); // parseUnary 支持 (int)-2 这种负数字面量
        return $this->setPos(new CastExpr($castType, $expr), $line, $col);
    }

    // ============================================================
    // 辅助方法
    // ============================================================

    /** 解析方法名（IDENTIFIER | 类型关键字 | CONSTRUCT | DESTRUCT | 内置函数名 | 保留字） */
    private function parseMethodName(): Token
    {
        $tok = $this->advance();
        $valid = [
            TokenType::IDENTIFIER, TokenType::CONSTRUCT, TokenType::DESTRUCT,
            TokenType::TYPE_INT, TokenType::TYPE_FLOAT, TokenType::TYPE_STRING,
            TokenType::TYPE_BOOL, TokenType::TYPE_VOID, TokenType::TYPE_ARRAY, TokenType::TYPE_MIXED,
            TokenType::COUNT, TokenType::VAR_DUMP, TokenType::ECHO_KW,
            TokenType::DIE, TokenType::ISSET, TokenType::EMPTY_KW, TokenType::LIST_KW, TokenType::EXIT, TokenType::UNSET,
            // PHP 保留字也可作为方法名
            TokenType::NULL_KW, TokenType::TRUE_KW, TokenType::FALSE_KW,
            TokenType::YIELD_KW, // Thread::yield()
            TokenType::SLEEP,    // Thread::sleep()
            TokenType::FOR_KW,   // Parallel::for()
        ];
        if (!in_array($tok->type, $valid, true)) {
            $this->error("Expected method name, got '{$tok->lexeme}'");
        }
        return $tok;
    }

    /** 解析可见性修饰符（public/private），不处理 static — 用于 const 和属性提升
     *  属性提升时可带 readonly: readonly public / public readonly / readonly private / private readonly
     *  @return array{0:string, 1:bool} [visibility, isReadonly] */
    private function parseVisibility(): array
    {
        $this->match(TokenType::ABSTRACT_KW); // skip abstract
        $isReadonly = false;
        // readonly 可在前: readonly public
        if ($this->match(TokenType::READONLY_KW)) {
            $isReadonly = true;
            if ($this->match(TokenType::PUBLIC_KW))  return ['public', $isReadonly];
            if ($this->match(TokenType::PRIVATE_KW)) return ['private', $isReadonly];
            $this->error('Expected public or private after readonly');
        }
        if ($this->match(TokenType::PUBLIC_KW)) {
            // public readonly
            if ($this->match(TokenType::READONLY_KW)) $isReadonly = true;
            return ['public', $isReadonly];
        }
        if ($this->match(TokenType::PRIVATE_KW)) {
            // private readonly
            if ($this->match(TokenType::READONLY_KW)) $isReadonly = true;
            return ['private', $isReadonly];
        }
        $this->error('Expected public or private');
    }

    /** 解析成员修饰符组合 (visibility? readonly? static?) — 用于属性和方法
     *  PHP 修饰符顺序灵活: readonly public / public readonly / static public / public static
     *  但 readonly + static 不兼容（PHP 8.2 禁止静态 readonly 属性）
     *  返回 ['vis' => string, 'isStatic' => bool, 'isReadonly' => bool] */
    private function parseModifiers(): array
    {
        $this->match(TokenType::ABSTRACT_KW); // skip abstract
        $vis = 'public';
        $isStatic = false;
        $isReadonly = false;

        // 第一轮: 可能的 leading static / readonly
        if ($this->match(TokenType::STATIC_KW)) {
            $isStatic = true;
            // static public/private [readonly]  或  static readonly public/private
            if ($this->match(TokenType::READONLY_KW)) {
                $this->error("Readonly property cannot be static (PHP 8.2+ forbids static readonly properties)");
            }
            if ($this->match(TokenType::PUBLIC_KW))       $vis = 'public';
            elseif ($this->match(TokenType::PRIVATE_KW))  $vis = 'private';
            // static readonly — 不允许
            if ($this->match(TokenType::READONLY_KW)) {
                $this->error("Readonly property cannot be static (PHP 8.2+ forbids static readonly properties)");
            }
        } elseif ($this->match(TokenType::READONLY_KW)) {
            $isReadonly = true;
            // readonly [public/private] [static] — static 不允许
            if ($this->match(TokenType::PUBLIC_KW))       $vis = 'public';
            elseif ($this->match(TokenType::PRIVATE_KW))  $vis = 'private';
            if ($this->match(TokenType::STATIC_KW)) {
                $this->error("Readonly property cannot be static (PHP 8.2+ forbids static readonly properties)");
            }
        } elseif ($this->match(TokenType::PUBLIC_KW)) {
            $vis = 'public';
            if ($this->match(TokenType::READONLY_KW)) {
                $isReadonly = true;
                if ($this->match(TokenType::STATIC_KW)) {
                    $this->error("Readonly property cannot be static (PHP 8.2+ forbids static readonly properties)");
                }
            } elseif ($this->match(TokenType::STATIC_KW)) {
                $isStatic = true;
            }
        } elseif ($this->match(TokenType::PRIVATE_KW)) {
            $vis = 'private';
            if ($this->match(TokenType::READONLY_KW)) {
                $isReadonly = true;
                if ($this->match(TokenType::STATIC_KW)) {
                    $this->error("Readonly property cannot be static (PHP 8.2+ forbids static readonly properties)");
                }
            } elseif ($this->match(TokenType::STATIC_KW)) {
                $isStatic = true;
            }
        } else {
            $this->error('Expected public, private, readonly, or static');
        }
        return ['vis' => $vis, 'isStatic' => $isStatic, 'isReadonly' => $isReadonly];
    }

    private function parseType(): string
    {
        // self 关键字
        if ($this->match(TokenType::SELF_KW)) {
            return 'self';
        }
        // 跳过全局命名空间前缀 \（如 \Throwable \Exception）
        if ($this->check(TokenType::NS_SEP)) {
            $this->advance();
        }
        $typeToken = $this->advance();
        $validTypes = [
            TokenType::TYPE_INT, TokenType::TYPE_FLOAT, TokenType::TYPE_STRING,
            TokenType::TYPE_BOOL, TokenType::TYPE_VOID, TokenType::TYPE_ARRAY,
            TokenType::TYPE_MIXED, TokenType::TYPE_NEVER,
            TokenType::IDENTIFIER, // 类名/枚举名作为类型
        ];
        if (!in_array($typeToken->type, $validTypes, true)) {
            $this->error("Expected type declaration, got '{$typeToken->lexeme}'");
        }
        $result = $typeToken->lexeme;

        // C 类型: C.IDENTIFIER（如 C.FILE, C.int, C.int*, C.void*）
        // 借鉴 vlang 的 C.TypeName 命名空间前缀设计
        // 注意: C.int/C.float/C.string/C.bool 中的 int/float/string/bool 是 TYPE_* 关键字，
        // 不是 IDENTIFIER，需要单独接受
        // 支持 C.int* => int*, C.char* => char* 等指针类型（* 可多个）
        if ($result === 'C' && $this->check(TokenType::DOT)) {
            $this->advance(); // 消费 '.'
            $memberToken = $this->advance();
            $validCTypeTokens = [
                TokenType::IDENTIFIER,
                TokenType::TYPE_INT, TokenType::TYPE_FLOAT,
                TokenType::TYPE_STRING, TokenType::TYPE_BOOL,
                TokenType::TYPE_VOID,
            ];
            if (!in_array($memberToken->type, $validCTypeTokens, true)) {
                $this->error("Expected C type name after 'C.', got '{$memberToken->lexeme}'");
            }
            $result = 'C.' . $memberToken->lexeme;
            // 支持 C.int* => int*, C.char** => char**, C.char*** => char*** 等指针后缀
            // 注意: ** 被 Lexer 识别为 STAR_STAR（幂运算符），需同时处理
            while ($this->check(TokenType::STAR) || $this->check(TokenType::STAR_STAR)) {
                $t = $this->advance();
                $result .= ($t->type === TokenType::STAR_STAR) ? '**' : '*';
            }
            return $result;
        }

        // 类名类型注解：IDENTIFIER 且非特殊类型名（callable/iterable/object/static）
        // 走 resolveClassName 解析 use 导入 + 命名空间前缀
        if ($typeToken->type === TokenType::IDENTIFIER
            && !in_array($result, ['callable', 'iterable', 'object', 'static'], true)) {
            $result = $this->resolveClassName($result);
        }

        // union type: int|string|bool
        while ($this->match(TokenType::PIPE)) {
            $next = $this->advance();
            $ut = [TokenType::TYPE_INT, TokenType::TYPE_FLOAT, TokenType::TYPE_STRING, TokenType::TYPE_BOOL, TokenType::TYPE_ARRAY, TokenType::TYPE_MIXED, TokenType::IDENTIFIER];
            if (!in_array($next->type, $ut, true)) {
                $this->error("Unsupported type in union: '{$next->lexeme}'");
            }
            $seg = $next->lexeme;
            if ($next->type === TokenType::IDENTIFIER
                && !in_array($seg, ['callable', 'iterable', 'object', 'static'], true)) {
                $seg = $this->resolveClassName($seg);
            }
            $result .= '|' . $seg;
        }

        return $result;
    }

    // === Token 操作 ===

    private function peek(int $offset = 0): Token
    {
        return $this->tokens[$this->current + $offset] ?? $this->tokens[count($this->tokens) - 1];
    }

    private function advance(): Token
    {
        return $this->tokens[$this->current++];
    }

    private function previous(): Token
    {
        return $this->tokens[$this->current - 1];
    }

    private function check(TokenType $type): bool
    {
        return $this->peek()->type === $type;
    }

    private function checkNext(TokenType $type): bool
    {
        return $this->peek(1)->type === $type;
    }

    private function match(TokenType $type): bool
    {
        if ($this->check($type)) {
            $this->advance();
            return true;
        }
        return false;
    }

    private function consume(TokenType $type, string $errMsg): Token
    {
        if ($this->check($type)) {
            return $this->advance();
        }
        $this->error($errMsg . ", got '{$this->peek()->lexeme}'");
    }

    /** 检测 [...] 是否是短语法 list 而非数组字面量 */
    private function isShortList(): bool
    {
        $save = $this->current;
        $this->advance(); // skip LBRACKET
        $isList = false;
        $depth = 1;
        while ($depth > 0 && $this->current < count($this->tokens)) {
            $t = $this->advance();
            if ($t->type === TokenType::LBRACKET) { $depth++; continue; }
            if ($t->type === TokenType::RBRACKET) {
                $depth--;
                // Top-level ] closed, check if next token is = → list
                if ($depth === 0 && $this->current < count($this->tokens)) {
                    $n = $this->peek();
                    if ($n->type === TokenType::EQUALS) { $isList = true; }
                }
                continue;
            }
            // Keyed list: STRING_LIT => ... → keep scanning (PHP 7.1+)
            if ($t->type === TokenType::STRING_LIT) {
                $next = $this->peek();
                if ($next->type === TokenType::DOUBLE_ARROW) {
                    $this->advance(); // skip =>
                    continue;
                }
                break; // array value literal
            }
            if (in_array($t->type, [
                TokenType::INT_LIT, TokenType::FLOAT_LIT,
                TokenType::TRUE_KW, TokenType::FALSE_KW, TokenType::NULL_KW,
                TokenType::ARROW,
            ])) break;
        }
        $this->current = $save;
        return $isList;
    }

    private function error(string $msg): never
    {
        $tok = $this->peek();
        throw new RuntimeException(
            sprintf("Parser error [%d:%d]: %s", $tok->line, $tok->column, $msg)
        );
    }

    // ============================================================
    // 条件编译 #if / #elseif / #else / #endif
    //   解析期求值：非命中分支的 token 直接跳过（不解析、不类型检查、不生成 C 代码）
    //   可出现在顶层（包裹 #include/#flag/#callback/#cstruct/class/function/const/enum）
    //   和函数体内（包裹任意语句）
    // ============================================================

    /** 当前是否在命中分支内（栈为空或栈顶 active=true） */
    private function ctActive(): bool
    {
        return empty($this->ctStack) || $this->ctStack[count($this->ctStack) - 1]['active'];
    }

    /**
     * 求值条件表达式: 支持 ! && || () 和标识符
     *   标识符: Windows/Linux/MacOS/Darwin/TCC/GCC/Clang/x86_64/aarch64/arm64/debug/prod
     *   未知标识符 → false（前向兼容）
     */
    private function evalCtCond(string $expr): bool
    {
        $tokens = $this->tokenizeCtExpr($expr);
        $pos = 0;
        return $this->parseCtOr($tokens, $pos);
    }

    /** 简单词法切分: 识别 ! && || ( ) 和标识符 */
    private function tokenizeCtExpr(string $expr): array
    {
        $tokens = [];
        $len = strlen($expr);
        $i = 0;
        while ($i < $len) {
            $c = $expr[$i];
            if (ctype_space($c)) { $i++; continue; }
            if ($c === '!') {
                if ($i + 1 < $len && $expr[$i + 1] === '=') {
                    // != 不支持（条件编译只接受布尔组合）
                    $this->error("条件编译不支持 '!=' 运算符，请用 !(a == b) 形式");
                }
                $tokens[] = ['!', 'op'];
                $i++;
                continue;
            }
            if ($c === '&' && $i + 1 < $len && $expr[$i + 1] === '&') {
                $tokens[] = ['&&', 'op'];
                $i += 2;
                continue;
            }
            if ($c === '|' && $i + 1 < $len && $expr[$i + 1] === '|') {
                $tokens[] = ['||', 'op'];
                $i += 2;
                continue;
            }
            if ($c === '(') { $tokens[] = ['(', 'lparen']; $i++; continue; }
            if ($c === ')') { $tokens[] = [')', 'rparen']; $i++; continue; }
            // 标识符
            $j = $i;
            while ($j < $len && (ctype_alnum($expr[$j]) || $expr[$j] === '_')) $j++;
            if ($j === $i) {
                $this->error("条件表达式含非法字符 '{$c}': {$expr}");
            }
            $tokens[] = [substr($expr, $i, $j - $i), 'ident'];
            $i = $j;
        }
        return $tokens;
    }

    /** || 优先级最低 */
    private function parseCtOr(array $tokens, int &$pos): bool
    {
        $result = $this->parseCtAnd($tokens, $pos);
        while (isset($tokens[$pos]) && $tokens[$pos][1] === 'op' && $tokens[$pos][0] === '||') {
            $pos++;
            $rhs = $this->parseCtAnd($tokens, $pos);
            $result = $result || $rhs;
        }
        return $result;
    }

    private function parseCtAnd(array $tokens, int &$pos): bool
    {
        $result = $this->parseCtNot($tokens, $pos);
        while (isset($tokens[$pos]) && $tokens[$pos][1] === 'op' && $tokens[$pos][0] === '&&') {
            $pos++;
            $rhs = $this->parseCtNot($tokens, $pos);
            $result = $result && $rhs;
        }
        return $result;
    }

    private function parseCtNot(array $tokens, int &$pos): bool
    {
        if (isset($tokens[$pos]) && $tokens[$pos][1] === 'op' && $tokens[$pos][0] === '!') {
            $pos++;
            return !$this->parseCtNot($tokens, $pos);
        }
        return $this->parseCtPrimary($tokens, $pos);
    }

    private function parseCtPrimary(array $tokens, int &$pos): bool
    {
        if (!isset($tokens[$pos])) {
            $this->error('条件表达式不完整');
        }
        $tok = $tokens[$pos];
        if ($tok[1] === 'lparen') {
            $pos++; // (
            $result = $this->parseCtOr($tokens, $pos);
            if (!isset($tokens[$pos]) || $tokens[$pos][1] !== 'rparen') {
                $this->error('条件表达式缺少右括号 )');
            }
            $pos++; // )
            return $result;
        }
        if ($tok[1] === 'ident') {
            $pos++;
            return $this->evalCtIdent($tok[0]);
        }
        $this->error("条件表达式非法 token: {$tok[0]}");
    }

    /** 标识符求值 */
    private function evalCtIdent(string $name): bool
    {
        // 大小写不敏感规范化
        $lower = strtolower($name);
        // OS — 全部用小写比较
        $osMap = [
            'windows' => 'windows', 'win' => 'windows',
            'linux' => 'linux',
            'macos' => 'darwin', 'darwin' => 'darwin', 'mac' => 'darwin',
        ];
        if (isset($osMap[$lower])) {
            return strtolower($this->targetOS) === $osMap[$lower];
        }
        // 编译器
        $ccMap = ['tcc' => 'TCC', 'tinyc' => 'TCC',
                  'gcc' => 'GCC', 'clang' => 'Clang'];
        if (isset($ccMap[$lower])) {
            return $this->ccClass === $ccMap[$lower];
        }
        // 架构
        $archMap = [
            'x86_64' => 'x86_64', 'amd64' => 'x86_64', 'x64' => 'x86_64',
            'aarch64' => 'aarch64', 'arm64' => 'aarch64',
        ];
        if (isset($archMap[$lower])) {
            $expected = $archMap[$lower];
            $current = strtolower($this->targetArch);
            return $current === $expected
                || $current === ($expected === 'x86_64' ? 'amd64' : 'aarch64');
        }
        // 模式
        if ($lower === 'debug') return $this->debugMode;
        if ($lower === 'prod') return !$this->debugMode;
        // 未知标识符 → false（前向兼容，不报错）
        return false;
    }

    /**
     * 跳过到同层下一个 #elseif/#else/#endif
     *   处理嵌套 #if: 深度计数，内层 #endif 不结束外层跳过
     */
    private function skipCtBranch(): void
    {
        $depth = 1;
        while (!$this->isAtEnd()) {
            if ($this->check(TokenType::HASH_IF)) {
                $depth++;
                $this->advance();
            } elseif ($this->check(TokenType::HASH_ENDIF)) {
                $depth--;
                $this->advance();
                if ($depth === 0) {
                    // 消费了配对的 #endif，弹出栈顶
                    array_pop($this->ctStack);
                    return;
                }
            } elseif ($depth === 1 && ($this->check(TokenType::HASH_ELSEIF) || $this->check(TokenType::HASH_ELSE))) {
                // 同层分支边界，交给调用者处理
                return;
            } else {
                $this->advance();
            }
        }
        $this->error('未闭合的 #if 指令');
    }

    private function isAtEnd(): bool
    {
        return $this->peek()->type === TokenType::EOF;
    }

    /**
     * 处理 #if 指令（顶层和函数体内共用）
     *   返回 true 表示已处理（调用方应 continue 循环）
     */
    private function handleCtIf(): bool
    {
        if (!$this->match(TokenType::HASH_IF)) return false;
        $cond = $this->previous()->lexeme;
        $parentActive = $this->ctActive();
        $result = $parentActive ? $this->evalCtCond($cond) : false;
        $this->ctStack[] = ['active' => $result, 'matched' => $result, 'parentActive' => $parentActive];
        if (!$result) $this->skipCtBranch();
        return true;
    }

    /** 处理 #elseif */
    private function handleCtElseif(): bool
    {
        if (!$this->match(TokenType::HASH_ELSEIF)) return false;
        if (empty($this->ctStack)) $this->error('#elseif 缺少对应的 #if');
        $cond = $this->previous()->lexeme;
        $top = &$this->ctStack[count($this->ctStack) - 1];
        if (!$top['parentActive']) {
            // 父分支未命中，本分支也必不命中
            $top['active'] = false;
            $this->skipCtBranch();
            return true;
        }
        if ($top['matched']) {
            // 前面分支已命中，本分支跳过
            $top['active'] = false;
            $this->skipCtBranch();
            return true;
        }
        $result = $this->evalCtCond($cond);
        $top['active'] = $result;
        $top['matched'] = $result;
        if (!$result) $this->skipCtBranch();
        return true;
    }

    /** 处理 #else */
    private function handleCtElse(): bool
    {
        if (!$this->match(TokenType::HASH_ELSE)) return false;
        if (empty($this->ctStack)) $this->error('#else 缺少对应的 #if');
        $top = &$this->ctStack[count($this->ctStack) - 1];
        if (!$top['parentActive']) {
            $top['active'] = false;
            $this->skipCtBranch();
            return true;
        }
        $top['active'] = !$top['matched'];
        $top['matched'] = true;
        if (!$top['active']) $this->skipCtBranch();
        return true;
    }

    /** 处理 #endif */
    private function handleCtEndif(): bool
    {
        if (!$this->match(TokenType::HASH_ENDIF)) return false;
        if (empty($this->ctStack)) $this->error('#endif 缺少对应的 #if');
        array_pop($this->ctStack);
        return true;
    }

    /** 尝试处理任意条件编译指令，返回 true 表示已处理 */
    private function tryCtDirective(): bool
    {
        if ($this->handleCtIf())      return true;
        if ($this->handleCtElseif())  return true;
        if ($this->handleCtElse())    return true;
        if ($this->handleCtEndif())   return true;
        return false;
    }
}
