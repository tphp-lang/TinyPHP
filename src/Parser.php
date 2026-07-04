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
    /** @var Token[] */
    private array $tokens;
    private int $current = 0;

    /** 当前文件的命名空间（空字符串表示全局） */
    private string $currentNamespace = '';
    /** use 类导入: 短名 → 完全限定名 (如 Demo → Demo\Demo) */
    private array $classImports = [];
    /** use 函数导入: 短名 → 完全限定名 (如 myDemoFn → Demo\myDemoFn) */
    private array $functionImports = [];
    /** use 枚举导入: 短名 → 完全限定名 (如 Color → Enums\Color) */
    private array $enumImports = [];
    /** 当前文件声明的常量名集合（用于跨命名空间引用检测） */
    private array $declaredConsts = [];
    /** 当前文件声明的枚举名集合（完全限定名 → true，用于 Color::RED 识别） */
    private array $enumNames = [];

    private bool $debugMode;

    /** @param Token[] $tokens */
    public function __construct(array $tokens, bool $debugMode = false)
    {
        $this->tokens = $tokens;
        $this->debugMode = $debugMode;
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

        // 预处理指令（任意顺序：include/flag/callback/debug 可混合出现）
        $includes = [];
        $ccFlags = [];
        $callbacks = [];
        $debugs  = [];
        while ($this->check(TokenType::HASH_INCLUDE) || $this->check(TokenType::HASH_IMPORT) || $this->check(TokenType::CC_FLAG) || $this->check(TokenType::HASH_CALLBACK) || $this->check(TokenType::HASH_DEBUG)) {
            if ($this->match(TokenType::HASH_INCLUDE)) {
                $includes[] = $this->previous()->literal;
            } elseif ($this->match(TokenType::HASH_IMPORT)) {
                // #import 已在 tphp.php 预扫描阶段处理，此处仅消费 token
            } elseif ($this->match(TokenType::CC_FLAG)) {
                $ccFlags[] = $this->previous()->literal;
            } elseif ($this->match(TokenType::HASH_CALLBACK)) {
                $callbacks[] = $this->previous()->literal;
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

        // const 声明
        $constants = [];
        while ($this->match(TokenType::CONST_KW)) {
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
            if ($this->match(TokenType::ABSTRACT_KW)) {
                // abstract class
                $this->consume(TokenType::CLASS_KW, 'Expected class keyword');
                $cls = $this->parseClassDeclBody(true);
                $extraClasses[] = $cls;
            } elseif ($this->check(TokenType::CLASS_KW)) {
                $cls = $this->parseClassDecl();
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
            } elseif ($this->check(TokenType::FUNCTION)) {
                $functions[] = $this->parseFunction();
            } elseif ($this->check(TokenType::ECHO_KW) || $this->check(TokenType::IDENTIFIER) || $this->check(TokenType::VAR_DUMP)) {
                $tok = $this->peek();
                $this->error("Unsupported top-level code '{$tok->lexeme}' (multi-file compilation only accepts namespace/use/class/function/const/enum declarations)");
            } elseif ($this->check(TokenType::HASH_INCLUDE)) {
                $this->error('#include must be placed at the top of the file (before namespace/use/class/function declarations)');
            } elseif ($this->check(TokenType::CC_FLAG)) {
                $this->error('#flag must be placed at the top of the file (before namespace/use/class/function declarations)');
            } elseif ($this->check(TokenType::HASH_CALLBACK)) {
                $this->error('#callback must be placed at the top of the file (before namespace/use/class/function declarations)');
            } elseif ($this->check(TokenType::HASH_IMPORT)) {
                $this->error('#import must be placed at the top of the file (before namespace/use/class/function declarations)');
            } elseif ($this->check(TokenType::HASH_DEBUG)) {
                $this->error('#debug must be placed at the top of the file (before namespace/use/class/function declarations)');
            } else {
                $this->error('Expected namespace/use/class/function/const/enum, got ' . $this->peek()->lexeme);
            }
        }

        return new ProgramNode($mainClass, $extraClasses, $functions, $constants, $enums, $includes, $ccFlags, $callbacks, $debugs);
    }

    // ============================================================
    // const → CONST_KW IDENTIFIER '=' literal SEMICOLON
    // ============================================================
    private function parseConstDecl(): ConstNode
    {
        $name = $this->consume(TokenType::IDENTIFIER, 'Expected constant name')->lexeme;
        $this->declaredConsts[$name] = $this->currentNamespace;
        $this->consume(TokenType::EQUALS, 'Expected =');
        $value = $this->parsePrimary(); // 只接受字面量
        $this->consume(TokenType::SEMICOLON, 'Expected ;');
        return new ConstNode($name, $value, $this->currentNamespace);
    }

    // ============================================================
    // enum Name: int { case A = 1; case B = 2; }
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

        $this->consume(TokenType::LBRACE, 'Expected {');
        $cases = [];
        while (!$this->check(TokenType::RBRACE) && !$this->check(TokenType::EOF)) {
            $this->consume(TokenType::CASE_KW, 'Expected case');
            $caseName = $this->consume(TokenType::IDENTIFIER, 'Expected case name')->lexeme;
            $this->consume(TokenType::EQUALS, 'Expected =');
            $caseValue = $this->parsePrimary(); // 字面量
            $this->consume(TokenType::SEMICOLON, 'Expected ;');
            $cases[] = new EnumCaseNode($caseName, $caseValue);
        }
        $this->consume(TokenType::RBRACE, 'Expected }');

        return new EnumNode($name, $bt, $cases, $this->currentNamespace);
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

    /** use X\Y\Z; | use function X\Y; | use X\{ A, B, function F }; */
    private function parseUseDecl(): void
    {
        // use function ... ?
        if ($this->match(TokenType::FUNCTION)) {
            $fqName = $this->parseQualifiedName();
            $this->parseUseAlias(function: true, fqName: $fqName);
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
    private function parseUseAlias(bool $function, string $fqName, string $alias = ''): void
    {
        if ($this->match(TokenType::AS_KW)) {
            $alias = $this->consume(TokenType::IDENTIFIER, 'Expected alias')->lexeme;
        }
        $key = ($alias !== '') ? $alias : substr(strrchr($fqName, '\\') ?: ('\\' . $fqName), 1);
        // 全大写/下划线名称 → 常量导入检测
        if (!$function && self::isConstantName($key)) {
            $this->error("Cross-namespace constant import not supported: 'use {$fqName}' (constants can only be used within their defining namespace)");
        }
        if ($function) {
            $this->functionImports[$key] = $fqName;
        } else {
            $this->classImports[$key] = $fqName;
        }
    }

    /** 解析类名引用：先查 use 导入表，找不到则假定在当前命名空间 */
    private function resolveClassName(string $name): string
    {
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
    private function parseFunction(): FunctionNode
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
        $body = [];
        while (!$this->check(TokenType::RBRACE) && !$this->check(TokenType::EOF)) {
            $body[] = $this->parseStmt();
        }
        $this->consume(TokenType::RBRACE, 'Expected }');

        return new FunctionNode($name, $params, $returnType, $body, $this->currentNamespace);
    }

    // ============================================================
    // class_decl → (final)? CLASS_KW IDENTIFIER (extends NAME)? (implements NAME,...)?
    // ============================================================
    private function parseClassDecl(): ClassNode
    {
        $this->match(TokenType::FINAL_KW);
        $this->consume(TokenType::CLASS_KW, 'Expected class keyword');
        return $this->parseClassDeclBody(false);
    }

    private function parseClassDeclBody(bool $isAbstract): ClassNode
    {
        $name = $this->consume(TokenType::IDENTIFIER, 'Expected class name')->lexeme;
        $parentName = null;
        if ($this->match(TokenType::EXTENDS_KW)) {
            $parentName = $this->parseQualifiedName();
        }
        $implements = [];
        if ($this->match(TokenType::IMPLEMENTS_KW)) {
            do {
                $implements[] = $this->parseQualifiedName();
            } while ($this->match(TokenType::COMMA));
        }

        $this->consume(TokenType::LBRACE, 'Expected {');

        $methods = [];
        $properties = [];
        $classConsts = [];
        $traits = [];
        while (!$this->check(TokenType::RBRACE) && !$this->check(TokenType::EOF)) {
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
            } elseif (($this->check(TokenType::PUBLIC_KW) || $this->check(TokenType::PRIVATE_KW) || $this->check(TokenType::STATIC_KW))
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
        return new ClassNode($name, $methods, $this->currentNamespace, $properties, $classConsts, $parentName, $isAbstract, $implements, $traits);
    }

    // interface → INTERFACE_KW IDENTIFIER LBRACE method* RBRACE
    private function parseInterfaceDecl(): ClassNode
    {
        $this->consume(TokenType::INTERFACE_KW, 'Expected interface keyword');
        $name = $this->consume(TokenType::IDENTIFIER, 'Expected interface name')->lexeme;
        $extends = [];
        if ($this->match(TokenType::EXTENDS_KW)) {
            do { $extends[] = $this->parseQualifiedName(); } while ($this->match(TokenType::COMMA));
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
            $vis = $this->parseVisibility();
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

    /** 判断当前 token 序列是否为属性声明开头 */
    private function isPropertyStart(): bool
    {
        // static type $name → property
        if ($this->check(TokenType::STATIC_KW)) return true;
        // visibility static? type $name → property
        if (!$this->check(TokenType::PUBLIC_KW) && !$this->check(TokenType::PRIVATE_KW)) return false;
        $hasStatic = $this->peek(1)->type === TokenType::STATIC_KW;
        $off = $hasStatic ? 2 : 1; // skip static keyword if present
        $t2 = $this->peek($off);
        $typeTokens = [TokenType::TYPE_INT, TokenType::TYPE_FLOAT, TokenType::TYPE_STRING,
                       TokenType::TYPE_BOOL, TokenType::TYPE_ARRAY, TokenType::TYPE_MIXED, TokenType::TYPE_NEVER, TokenType::IDENTIFIER];
        if (!in_array($t2->type, $typeTokens, true)) return false;
        $t3 = $this->peek($off + 1);
        if ($t3->type !== TokenType::IDENTIFIER) return false;
        if (!str_starts_with($t3->lexeme, '$')) return false;
        $t4 = $this->peek($off + 2);
        return in_array($t4->type, [TokenType::SEMICOLON, TokenType::EQUALS], true);
    }

    /** 属性声明: visibility type $name (= expr)? ; */
    private function parsePropertyDecl(): PropertyDeclNode
    {
        $vis = $this->parseVisibility();
        $type = $this->parseType();
        $name = $this->consume(TokenType::IDENTIFIER, 'Expected property name')->lexeme;
        $default = null;
        if ($this->match(TokenType::EQUALS)) {
            $default = $this->parsePrimary();
        }
        $this->consume(TokenType::SEMICOLON, 'Expected ;');
        return new PropertyDeclNode($name, $type, $vis, $default);
    }

    // ============================================================
    // method → visibility FUNCTION name LPAREN params? RPAREN COLON type LBRACE stmt* RBRACE
    // ============================================================
    private function parseMethod(): MethodNode
    {
        $visibility = $this->parseVisibility();

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
        if ($this->match(TokenType::SEMICOLON)) {
            $body = null; // abstract method
        } else {
            $this->consume(TokenType::LBRACE, 'Expected {');
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
            $this->consume(TokenType::RBRACE, 'Expected }');
        }

        return new MethodNode($name, $visibility, $params, $returnType, $body, $promoted);
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
            // Constructor property promotion: public int $x
            if ($this->check(TokenType::PUBLIC_KW) || $this->check(TokenType::PRIVATE_KW)) {
                $vis = $this->parseVisibility();
                $type = $this->parseType();
                $pName = $this->consume(TokenType::IDENTIFIER, 'Expected property name')->lexeme;
                $promoted[] = new PropertyDeclNode($pName, $type, $vis, null);
                $params[] = new ParamNode($type, $pName);
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
        // 默认值: = expr
        $default = null;
        if ($this->match(TokenType::EQUALS)) {
            $default = $this->parsePrimary();
        }
        return new ParamNode($type, $varName, $byRef, $default);
    }

    private function parseStmt(): StmtNode
    {
        if ($this->match(TokenType::ECHO_KW))       return $this->parseEchoStmt();
        if ($this->match(TokenType::RETURN_KW))     return $this->parseReturnStmt();
        if ($this->match(TokenType::IF_KW))         return $this->parseIfStmt();
        if ($this->match(TokenType::WHILE_KW))      return $this->parseWhileStmt();
        if ($this->match(TokenType::FOR_KW))        return $this->parseForStmt();
        if ($this->match(TokenType::FOREACH_KW))    return $this->parseForeachStmt();
        if ($this->match(TokenType::DO_KW))         return $this->parseDoWhileStmt();
        if ($this->match(TokenType::LIST_KW))       return $this->parseListStmt();
        // 短语法: [$a, $b] = expr
        if ($this->check(TokenType::LBRACKET) && $this->isShortList()) {
            $this->advance(); // consume LBRACKET
            return $this->parseListStmt();
        }
        if ($this->match(TokenType::SWITCH_KW))     return $this->parseSwitchStmt();
        if ($this->match(TokenType::GOTO))          return $this->parseGotoStmt();
        if ($this->match(TokenType::TRY_KW))        return $this->parseTryStmt();
        if ($this->match(TokenType::THROW_KW))      return $this->parseThrowStmt();
        if ($this->match(TokenType::BREAK_KW))      { $this->consume(TokenType::SEMICOLON, 'Expected ;'); return new BreakStmtNode(); }
        if ($this->match(TokenType::CONTINUE_KW))   { $this->consume(TokenType::SEMICOLON, 'Expected ;'); return new ContinueStmtNode(); }
        // 标签: IDENTIFIER COLON
        if ($this->check(TokenType::IDENTIFIER) && $this->checkNext(TokenType::COLON)) {
            $label = $this->advance()->lexeme;
            $this->advance(); // skip :
            return new LabelStmtNode($label);
        }
        // 数组 push: $a[] = expr;
        if ($this->check(TokenType::IDENTIFIER) && $this->checkNext(TokenType::LBRACKET)) {
            $save = $this->current;
            $this->advance(); // IDENTIFIER
            $this->advance(); // LBRACKET
            if ($this->check(TokenType::RBRACKET)) {
                $varName = $this->tokens[$save]->lexeme;
                $this->advance(); // RBRACKET
                $this->consume(TokenType::EQUALS, 'Expected =');
                $expr = $this->parseExpr();
                $this->consume(TokenType::SEMICOLON, 'Expected ;');
                return new AssignArrayPushStmtNode($varName, $expr);
            }
            $this->current = $save; // 回退
        }
        // 赋值: $var = expr;
        if ($this->check(TokenType::IDENTIFIER) && $this->checkNext(TokenType::EQUALS)) {
            return $this->parseAssignStmt();
        }
        // 表达式语句
        return $this->parseExprStmt();
    }

    // if (cond) { body } (elseif (cond) { body })* (else { body })?
    private function parseIfStmt(): IfStmtNode
    {
        $this->consume(TokenType::LPAREN, 'Expected (' );
        $cond = $this->parseExpr();
        $this->consume(TokenType::RPAREN, 'Expected )');
        $this->consume(TokenType::LBRACE, 'Expected {');
        $thenBody = $this->parseBlock();
        $this->consume(TokenType::RBRACE, 'Expected }');

        $elseifs = [];
        while ($this->match(TokenType::ELSEIF_KW)) {
            $this->consume(TokenType::LPAREN, 'Expected (' );
            $eCond = $this->parseExpr();
            $this->consume(TokenType::RPAREN, 'Expected )');
            $this->consume(TokenType::LBRACE, 'Expected {');
            $eBody = $this->parseBlock();
            $this->consume(TokenType::RBRACE, 'Expected }');
            $elseifs[] = new ElseIfBranch($eCond, $eBody);
        }

        $elseBody = [];
        if ($this->match(TokenType::ELSE_KW)) {
            // else if (...) → 转为 elseif
            if ($this->match(TokenType::IF_KW)) {
                $this->consume(TokenType::LPAREN, 'Expected (' );
                $eCond = $this->parseExpr();
                $this->consume(TokenType::RPAREN, 'Expected )');
                $this->consume(TokenType::LBRACE, 'Expected {');
                $eBody = $this->parseBlock();
                $this->consume(TokenType::RBRACE, 'Expected }');
                $elseifs[] = new ElseIfBranch($eCond, $eBody);
            } else {
                $this->consume(TokenType::LBRACE, 'Expected {');
                $elseBody = $this->parseBlock();
                $this->consume(TokenType::RBRACE, 'Expected }');
            }
        }

        // 继续检测 else if 链
        while ($this->match(TokenType::ELSE_KW)) {
            if ($this->match(TokenType::IF_KW)) {
                $this->consume(TokenType::LPAREN, 'Expected (' );
                $eCond = $this->parseExpr();
                $this->consume(TokenType::RPAREN, 'Expected )');
                $this->consume(TokenType::LBRACE, 'Expected {');
                $eBody = $this->parseBlock();
                $this->consume(TokenType::RBRACE, 'Expected }');
                $elseifs[] = new ElseIfBranch($eCond, $eBody);
            } elseif (!empty($elseBody)) {
                $this->error('Multiple else blocks');
            } else {
                $this->consume(TokenType::LBRACE, 'Expected {');
                $elseBody = $this->parseBlock();
                $this->consume(TokenType::RBRACE, 'Expected }');
            }
        }

        return new IfStmtNode($cond, $thenBody, $elseifs, $elseBody);
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

    // try { body } catch (Var $e) { body } finally { body }
    private function parseTryStmt(): TryStmtNode
    {
        $this->consume(TokenType::LBRACE, 'Expected {');
        $tryBody = $this->parseBlock();
        $this->consume(TokenType::RBRACE, 'Expected }');

        $catchBody = [];
        $catchVar  = 'e';
        $finallyBody = [];

        // catch (TYPE $var) { ... }
        if ($this->match(TokenType::CATCH_KW)) {
            $this->consume(TokenType::LPAREN, 'Expected (' );
            $catchType = $this->parseType();
            $t = $this->consume(TokenType::IDENTIFIER, 'Expected catch variable')->lexeme;
            $catchVar = ltrim($t, '$');
            $this->consume(TokenType::RPAREN, 'Expected )');
            $this->consume(TokenType::LBRACE, 'Expected {');
            $catchBody = $this->parseBlock();
            $this->consume(TokenType::RBRACE, 'Expected }');
        }

        // finally { ... }
        if ($this->match(TokenType::FINALLY_KW)) {
            $this->consume(TokenType::LBRACE, 'Expected {');
            $finallyBody = $this->parseBlock();
            $this->consume(TokenType::RBRACE, 'Expected }');
        }

        return new TryStmtNode($tryBody, $catchBody, $finallyBody, $catchVar);
    }

    // throw expr;
    private function parseThrowStmt(): ThrowStmtNode
    {
        $expr = $this->parseExpr();
        $this->consume(TokenType::SEMICOLON, 'Expected ;');
        return new ThrowStmtNode($expr);
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

    // for init: $var = expr 或 expr_stmt
    private function parseForInitExpr(): ExprNode
    {
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

    private function parseAssignStmt(): AssignStmtNode
    {
        $varName = $this->advance()->lexeme;
        $this->consume(TokenType::EQUALS, 'Expected =');
        $expr = $this->parseExpr();
        $this->consume(TokenType::SEMICOLON, 'Expected ;');
        return new AssignStmtNode($varName, $expr);
    }

    private function parseExprStmt(): StmtNode
    {
        $expr = $this->parseExpr();
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
        return $this->parseNullCoalesce();
    }

    // ??  （优先级最低）
    private function parseNullCoalesce(): ExprNode
    {
        $left = $this->parseTernary();
        while ($this->match(TokenType::QUEST_QUEST)) {
            $right = $this->parseTernary();
            $left = $this->setPos(new NullCoalesceExpr($left, $right), $left->line, $left->column);
        }
        return $left;
    }

    // ?: ternary
    private function parseTernary(): ExprNode
    {
        $cond = $this->parseLogicalOr();
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
        while ($this->match(TokenType::EQ) || $this->match(TokenType::NE) || $this->match(TokenType::IDENTICAL) || $this->match(TokenType::NOT_IDENTICAL) || $this->match(TokenType::SPACESHIP) || $this->match(TokenType::INSTANCEOF_KW)) {
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
        return $this->parsePostfix();
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
                $index = $this->parseExpr();
                $this->consume(TokenType::RBRACKET, 'Expected ]');
                $expr = $this->setPos(new ArrayAccessExpr($expr, $index), $expr->line, $expr->column);
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

        // 字符串
        if ($this->match(TokenType::STRING_LIT)) {
            return $this->setPos(new StringLiteralExpr((string)$this->previous()->literal), $line, $col);
        }
        // 数字
        if ($this->match(TokenType::INT_LIT)) {
            return $this->setPos(new IntLiteralExpr((int)$this->previous()->literal), $line, $col);
        }
        if ($this->match(TokenType::FLOAT_LIT)) {
            return $this->setPos(new FloatLiteralExpr((float)$this->previous()->literal), $line, $col);
        }
        // 布尔 / null
        if ($this->match(TokenType::TRUE_KW)) {
            return $this->setPos(new BoolLiteralExpr(true), $line, $col);
        }
        if ($this->match(TokenType::FALSE_KW)) {
            return $this->setPos(new BoolLiteralExpr(false), $line, $col);
        }
        if ($this->match(TokenType::NULL_KW)) {
            return $this->setPos(new NullLiteralExpr(), $line, $col);
        }
        // __LINE__ / __FILE__ / __DIR__ / DIRECTORY_SEPARATOR / __CLASS__ / __METHOD__
        $magicTokens = [TokenType::MAGIC_LINE, TokenType::MAGIC_FILE, TokenType::MAGIC_DIR, TokenType::DIR_SEP, TokenType::MAGIC_CLASS, TokenType::MAGIC_METHOD];
        foreach ($magicTokens as $mt) {
            if ($this->match($mt)) {
                return $this->setPos(new MagicConstExpr($this->previous()->lexeme, $this->previous()->line), $line, $col);
            }
        }
        // parent:: → special variable
        if ($this->match(TokenType::PARENT_KW)) {
            return $this->setPos(new VariableExpr('parent'), $line, $col);
        }
        // fn($x) => expr  箭头函数
        if ($this->match(TokenType::FN_KW)) {
            return $this->setPos($this->parseArrowFunction(), $line, $col);
        }
        // 匿名函数 / 闭包: function(): type { ... }
        if ($this->match(TokenType::FUNCTION)) {
            return $this->setPos($this->parseClosure(), $line, $col);
        }
        // 数组字面量: [ expr, expr, ... ]
        if ($this->match(TokenType::LBRACKET)) {
            return $this->setPos($this->parseArrayLiteral(), $line, $col);
        }
        // new ClassName(args)
        if ($this->match(TokenType::NEW_KW)) {
            return $this->setPos($this->parseNewExpr(), $line, $col);
        }
        // 类型转换: (type) expr  |  括号分组: ( expr )
        if ($this->check(TokenType::LPAREN)) {
            $nextType = $this->peek(1);
            $typeTokens = [
                TokenType::TYPE_INT, TokenType::TYPE_FLOAT, TokenType::TYPE_STRING,
                TokenType::TYPE_BOOL, TokenType::TYPE_ARRAY, TokenType::TYPE_MIXED,
            ];
            if (in_array($nextType->type, $typeTokens, true)) {
                return $this->setPos($this->parseCastExpr(), $line, $col);
            }
            // 括号分组: ( expr )
            if ($this->match(TokenType::LPAREN)) {
                $inner = $this->parseExpr();
                $this->consume(TokenType::RPAREN, 'Expected )');
                return $inner; // 括号不产生额外 AST 节点，直接返回内部表达式
            }
        }
        // match 表达式: match(expr) { arm, arm, ... }
        if ($this->match(TokenType::MATCH)) {
            return $this->setPos($this->parseMatchExpr(), $line, $col);
        }
        // 变量 / self 关键字
        if ($this->check(TokenType::IDENTIFIER) || $this->check(TokenType::SELF_KW) || $this->check(TokenType::VAR_DUMP) || $this->check(TokenType::COUNT) || $this->check(TokenType::EXIT) || $this->check(TokenType::DIE) || $this->check(TokenType::ISSET) || $this->check(TokenType::EMPTY_KW) || $this->check(TokenType::UNSET) || $this->check(TokenType::ERROR) || $this->check(TokenType::TIME) || $this->check(TokenType::DATE) || $this->check(TokenType::SLEEP) || $this->check(TokenType::USLEEP) || $this->check(TokenType::HRTIME) || $this->check(TokenType::IS_INT) || $this->check(TokenType::IS_FLOAT) || $this->check(TokenType::IS_STRING) || $this->check(TokenType::IS_BOOL) || $this->check(TokenType::IS_ARRAY) || $this->check(TokenType::IS_OBJECT) || $this->check(TokenType::IS_NULL) || $this->check(TokenType::IS_CALLABLE)) {
            $name = $this->advance()->lexeme;

            // $obj->xxx / $obj?->xxx / Class::xxx
            if ($this->match(TokenType::ARROW) || $this->match(TokenType::NULLSAFE_ARROW) || $this->match(TokenType::DOUBLE_COLON)) {
                $opType = $this->previous()->type;
                $nullsafe = ($opType === TokenType::NULLSAFE_ARROW);
                $memberName = $this->parseMethodName()->lexeme;

                // EnumName::CASE → 枚举访问（需解析命名空间 + use 导入）
                if ($opType === TokenType::DOUBLE_COLON) {
                    $enumFq = $this->resolveEnumName($name);
                    if ($enumFq !== null && isset($this->enumNames[$enumFq])) {
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
                    'yield'   => "yield/generators are not supported. Collect results into an array and return it, or use a callback iterator.",
                ];
                if (isset($unsupportedFns[$name])) {
                    $this->error($unsupportedFns[$name]);
                }
                // 内置函数和桥接函数不解析命名空间
                $globalFns = ['var_dump','count','exit','die','isset','empty','unset',
                    'error','time','date','sleep','usleep','hrtime',
                    // phpc 互操作（全部全局，不解析命名空间）
                    'c_int','c_float','c_str','php_int','php_float','php_str',
                    'phpc_arr_int','phpc_arr_dbl','phpc_arr_str',
                    'phpc_new_arr_int','phpc_new_arr_dbl','phpc_new_arr_str','phpc_new_arr',
                    'phpc_obj','phpc_new_obj',
                    'phpc_fn','phpc_env','phpc_new_fn','phpc_new_fn_env',
                    'phpc_free','phpc_free_str_arr',
                    'count','strlen','trim','ltrim','rtrim','substr','strpos',
                    'str_contains','str_replace','sprintf','implode','explode','join',
                    'array_push','array_pop','array_shift','array_unshift','in_array',
                    'array_key_exists','array_keys','array_values','array_merge',
                    'array_unique','array_reverse','array_slice','array_sum',
                    'array_product','array_fill','sort','rsort',
                    'intval','floatval','strval','boolval',
                    'max','min','range','rand','mt_rand',
                    'random_int','random_bytes',
                    'abs','round','ceil','floor','sqrt',
                    'strtolower','strtoupper','shuffle','array_search','microtime',
                    'json_encode','json_decode'];
                if (!in_array($name, $globalFns, true) && !str_starts_with($name, 'is_') && !str_starts_with($name, 'ctype_')
                    && !str_starts_with($name, 'c_') && !str_starts_with($name, 'php_') && !str_starts_with($name, 'phpc_')) {
                    $name = $this->resolveFunctionName($name);
                }
                return $this->setPos(new CallExpr(null, $name, $args), $line, $col);
            }

            // 数组访问: $var[expr]
            if ($this->match(TokenType::LBRACKET)) {
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
     *  entry → expr (=> expr)?  */
    private function parseArrayLiteral(): ArrayLiteralExpr
    {
        $entries = [];
        if (!$this->check(TokenType::RBRACKET)) {
            do {
                $first = $this->parseExpr();
                // 检查是否 key => value
                if ($this->match(TokenType::DOUBLE_ARROW)) {
                    $val = $this->parseExpr();
                    $entries[] = new ArrayEntryNode($first, $val);
                } else {
                    $entries[] = new ArrayEntryNode(null, $first);
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
    /** fn($x, $y) => expr  → 闭包语法糖 */
    private function parseArrowFunction(): ClosureExpr
    {
        $this->consume(TokenType::LPAREN, 'Expected (' );
        $params = [];
        if (!$this->check(TokenType::RPAREN)) {
            do {
                // Arrow params: optional type, then $name
                $type = $this->checkTypeStart() ? $this->parseType() : 'int';
                $this->consume(TokenType::IDENTIFIER, 'Expected parameter name');
                $params[] = new ParamNode($type, $this->previous()->lexeme);
            } while ($this->match(TokenType::COMMA));
        }
        $this->consume(TokenType::RPAREN, 'Expected )');
        $this->consume(TokenType::DOUBLE_ARROW, 'Expected =>');
        $bodyExpr = $this->parseExpr();
        $body = [new ReturnStmtNode($bodyExpr)];
        return new ClosureExpr($params, 'int', $body, []); // default return type: int
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
        $body = [];
        while (!$this->check(TokenType::RBRACE) && !$this->check(TokenType::EOF)) {
            $body[] = $this->parseStmt();
        }
        $this->consume(TokenType::RBRACE, 'Expected }');

        return new ClosureExpr($params, $returnType, $body, $useVars);
    }

    /** @return ExprNode[] */
    private function parseArgs(): array
    {
        $args = [];
        if (!$this->check(TokenType::RPAREN)) {
            do {
                $args[] = $this->parseExpr();
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
        ];
        if (!in_array($tok->type, $valid, true)) {
            $this->error("Expected method name, got '{$tok->lexeme}'");
        }
        return $tok;
    }

    private function parseVisibility(): string
    {
        $this->match(TokenType::ABSTRACT_KW); // skip abstract
        if ($this->match(TokenType::STATIC_KW)) return 'public';
        if ($this->match(TokenType::PUBLIC_KW)) {
            $this->match(TokenType::STATIC_KW);
            return 'public';
        }
        if ($this->match(TokenType::PRIVATE_KW)) {
            $this->match(TokenType::STATIC_KW);
            return 'private';
        }
        $this->error('Expected public, private, or static');
    }

    private function parseType(): string
    {
        // self 关键字
        if ($this->match(TokenType::SELF_KW)) {
            return 'self';
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

        // union type: int|string|bool
        while ($this->match(TokenType::PIPE)) {
            $next = $this->advance();
            $ut = [TokenType::TYPE_INT, TokenType::TYPE_FLOAT, TokenType::TYPE_STRING, TokenType::TYPE_BOOL, TokenType::TYPE_ARRAY, TokenType::TYPE_MIXED, TokenType::IDENTIFIER];
            if (!in_array($next->type, $ut, true)) {
                $this->error("Unsupported type in union: '{$next->lexeme}'");
            }
            $result .= '|' . $next->lexeme;
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
}
