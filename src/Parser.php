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

    /** @param Token[] $tokens */
    public function __construct(array $tokens)
    {
        $this->tokens = $tokens;
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
            $this->error('期望文件结束，得到 ' . $this->peek()->lexeme);
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

        $this->consume(TokenType::PHP_OPEN, '期望 <?php 开头');

        // 可选 namespace 声明
        if ($this->match(TokenType::NAMESPACE)) {
            $this->currentNamespace = $this->parseQualifiedName();
            $this->consume(TokenType::SEMICOLON, '期望 ;');
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
            if ($this->check(TokenType::CLASS_KW)) {
                $cls = $this->parseClassDecl();
                if ($mainClass === null) {
                    $mainClass = $cls;
                } else {
                    $extraClasses[] = $cls;
                }
            } elseif ($this->check(TokenType::FUNCTION)) {
                $functions[] = $this->parseFunction();
            } elseif ($this->check(TokenType::ECHO_KW) || $this->check(TokenType::IDENTIFIER) || $this->check(TokenType::VAR_DUMP)) {
                $tok = $this->peek();
                $this->error("不支持的全局游离代码 '{$tok->lexeme}'（多文件编译只接受 namespace/use/class/function/const/enum 声明）");
            } else {
                $this->error('期望 namespace/use/class/function/const/enum，得到 ' . $this->peek()->lexeme);
            }
        }

        return new ProgramNode($mainClass, $extraClasses, $functions, $constants, $enums);
    }

    // ============================================================
    // const → CONST_KW IDENTIFIER '=' literal SEMICOLON
    // ============================================================
    private function parseConstDecl(): ConstNode
    {
        $name = $this->consume(TokenType::IDENTIFIER, '期望常量名')->lexeme;
        $this->declaredConsts[$name] = $this->currentNamespace;
        $this->consume(TokenType::EQUALS, '期望 =');
        $value = $this->parsePrimary(); // 只接受字面量
        $this->consume(TokenType::SEMICOLON, '期望 ;');
        return new ConstNode($name, $value, $this->currentNamespace);
    }

    // ============================================================
    // enum Name: int { case A = 1; case B = 2; }
    // ============================================================
    private function parseEnumDecl(): EnumNode
    {
        $name = $this->consume(TokenType::IDENTIFIER, '期望枚举名')->lexeme;
        $fqName = ($this->currentNamespace !== '')
            ? $this->currentNamespace . '\\' . $name
            : $name;
        $this->enumNames[$fqName] = true;
        $this->consume(TokenType::COLON, '期望 :');

        // backing type: int or string
        if ($this->match(TokenType::TYPE_INT)) {
            $bt = 'int';
        } elseif ($this->match(TokenType::TYPE_STRING)) {
            $bt = 'string';
        } else {
            $this->error("枚举只支持 int 或 string 类型，得到 '{$this->peek()->lexeme}'");
        }

        $this->consume(TokenType::LBRACE, '期望 {');
        $cases = [];
        while (!$this->check(TokenType::RBRACE) && !$this->check(TokenType::EOF)) {
            $this->consume(TokenType::CASE_KW, '期望 case');
            $caseName = $this->consume(TokenType::IDENTIFIER, '期望 case 名')->lexeme;
            $this->consume(TokenType::EQUALS, '期望 =');
            $caseValue = $this->parsePrimary(); // 字面量
            $this->consume(TokenType::SEMICOLON, '期望 ;');
            $cases[] = new EnumCaseNode($caseName, $caseValue);
        }
        $this->consume(TokenType::RBRACE, '期望 }');

        return new EnumNode($name, $bt, $cases, $this->currentNamespace);
    }

    // ============================================================
    // 命名空间 & use
    // ============================================================

    /** 解析完全限定名: IDENTIFIER (NS_SEP IDENTIFIER)* → "A\B\C" */
    private function parseQualifiedName(): string
    {
        $name = $this->consume(TokenType::IDENTIFIER, '期望标识符')->lexeme;
        while ($this->match(TokenType::NS_SEP)) {
            $name .= '\\' . $this->consume(TokenType::IDENTIFIER, '期望标识符')->lexeme;
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
            $this->consume(TokenType::SEMICOLON, '期望 ;');
            return;
        }

        // 读取第一部分标识符
        $base = $this->consume(TokenType::IDENTIFIER, '期望标识符')->lexeme;

        // use Demo\{ ... } → 组合语法（提前探测，不消耗 token）
        if ($this->check(TokenType::NS_SEP) && $this->peek(1)->type === TokenType::LBRACE) {
            $this->advance(); // skip \
            $this->advance(); // skip {
            do {
                // 允许尾部逗号 (PHP 7.3+)
                if ($this->check(TokenType::RBRACE)) break;
                if ($this->match(TokenType::FUNCTION)) {
                    $fnName = $this->consume(TokenType::IDENTIFIER, '期望函数名')->lexeme;
                    $this->parseUseAlias(function: true, fqName: $base . '\\' . $fnName, alias: $fnName);
                } else {
                    $itemName = $this->consume(TokenType::IDENTIFIER, '期望标识符')->lexeme;
                    $this->parseUseAlias(function: false, fqName: $base . '\\' . $itemName, alias: $itemName);
                }
            } while ($this->match(TokenType::COMMA));
            $this->consume(TokenType::RBRACE, '期望 }');
            $this->consume(TokenType::SEMICOLON, '期望 ;');
            return;
        }

        // 继续读取完整限定名: base\next\...
        $full = $base;
        while ($this->match(TokenType::NS_SEP)) {
            $full .= '\\' . $this->consume(TokenType::IDENTIFIER, '期望标识符')->lexeme;
        }
        $baseName = $full;

        // use X as Alias;
        if ($this->match(TokenType::AS_KW)) {
            $alias = $this->consume(TokenType::IDENTIFIER, '期望别名')->lexeme;
            $this->classImports[$alias] = $baseName;
            $this->consume(TokenType::SEMICOLON, '期望 ;');
            return;
        }

        // use X\Y; (alias = 最后一段)
        $short = substr(strrchr($baseName, '\\') ?: ('\\' . $baseName), 1);
        // 全大写/下划线名称 → 常量导入，TinyPHP 不支持跨命名空间常量
        if (self::isConstantName($short)) {
            $this->error("不支持跨命名空间常量导入 'use {$baseName}'（常量只能在定义命名空间内使用）");
        }
        $this->classImports[$short] = $baseName;
        $this->consume(TokenType::SEMICOLON, '期望 ;');
    }

    /** 注册 use 别名 */
    private function parseUseAlias(bool $function, string $fqName, string $alias = ''): void
    {
        if ($this->match(TokenType::AS_KW)) {
            $alias = $this->consume(TokenType::IDENTIFIER, '期望别名')->lexeme;
        }
        $key = ($alias !== '') ? $alias : substr(strrchr($fqName, '\\') ?: ('\\' . $fqName), 1);
        // 全大写/下划线名称 → 常量导入检测
        if (!$function && self::isConstantName($key)) {
            $this->error("不支持跨命名空间常量导入 'use {$fqName}'（常量只能在定义命名空间内使用）");
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
        $this->consume(TokenType::FUNCTION, '期望 function 关键字');

        $name = $this->consume(TokenType::IDENTIFIER, '期望函数名')->lexeme;

        $this->consume(TokenType::LPAREN, '期望 (');
        $params = [];
        if (!$this->check(TokenType::RPAREN)) {
            $params = $this->parseParams();
        }
        $this->consume(TokenType::RPAREN, '期望 )');

        $returnType = 'void';
        if ($this->match(TokenType::COLON)) {
            $returnType = $this->parseType();
        }

        $this->consume(TokenType::LBRACE, '期望 {');
        $body = [];
        while (!$this->check(TokenType::RBRACE) && !$this->check(TokenType::EOF)) {
            $body[] = $this->parseStmt();
        }
        $this->consume(TokenType::RBRACE, '期望 }');

        return new FunctionNode($name, $params, $returnType, $body, $this->currentNamespace);
    }

    // ============================================================
    // class_decl → CLASS_KW IDENTIFIER LBRACE (property|method)* RBRACE
    // ============================================================
    private function parseClassDecl(): ClassNode
    {
        $this->consume(TokenType::CLASS_KW, '期望 class 关键字');
        $name = $this->consume(TokenType::IDENTIFIER, '期望类名')->lexeme;
        $this->consume(TokenType::LBRACE, '期望 {');

        $methods = [];
        $properties = [];
        while (!$this->check(TokenType::RBRACE) && !$this->check(TokenType::EOF)) {
            // 属性声明: visibility type $name (= default)? ;
            if ($this->isPropertyStart()) {
                $properties[] = $this->parsePropertyDecl();
            } else {
                $methods[] = $this->parseMethod();
            }
        }

        $this->consume(TokenType::RBRACE, '期望 }');
        return new ClassNode($name, $methods, $this->currentNamespace, $properties);
    }

    /** 判断当前 token 序列是否为属性声明开头 */
    private function isPropertyStart(): bool
    {
        // visibility type $name ...
        if (!$this->check(TokenType::PUBLIC_KW) && !$this->check(TokenType::PRIVATE_KW)) return false;
        $t2 = $this->peek(1);
        $typeTokens = [TokenType::TYPE_INT, TokenType::TYPE_FLOAT, TokenType::TYPE_STRING,
                       TokenType::TYPE_BOOL, TokenType::TYPE_ARRAY, TokenType::TYPE_MIXED, TokenType::IDENTIFIER];
        if (!in_array($t2->type, $typeTokens, true)) return false;
        // peek(2) should be $variable (= IDENTIFIER with $)
        $t3 = $this->peek(2);
        if ($t3->type !== TokenType::IDENTIFIER) return false;
        if (!str_starts_with($t3->lexeme, '$')) return false;
        // peek(3) should be ; or =
        $t4 = $this->peek(3);
        return in_array($t4->type, [TokenType::SEMICOLON, TokenType::EQUALS], true);
    }

    /** 属性声明: visibility type $name (= expr)? ; */
    private function parsePropertyDecl(): PropertyDeclNode
    {
        $vis = $this->parseVisibility();
        $type = $this->parseType();
        $name = $this->consume(TokenType::IDENTIFIER, '期望属性名')->lexeme;
        $default = null;
        if ($this->match(TokenType::EQUALS)) {
            $default = $this->parsePrimary();
        }
        $this->consume(TokenType::SEMICOLON, '期望 ;');
        return new PropertyDeclNode($name, $type, $vis, $default);
    }

    // ============================================================
    // method → visibility FUNCTION name LPAREN params? RPAREN COLON type LBRACE stmt* RBRACE
    // ============================================================
    private function parseMethod(): MethodNode
    {
        $visibility = $this->parseVisibility();

        $this->consume(TokenType::FUNCTION, '期望 function 关键字');

        // name: IDENTIFIER | CONSTRUCT | DESTRUCT | 类型关键字（可用作方法名）
        $nameToken = $this->parseMethodName();
        $name = $nameToken->lexeme;

        // 参数
        $this->consume(TokenType::LPAREN, '期望 (');
        $params = [];
        if (!$this->check(TokenType::RPAREN)) {
            $params = $this->parseParams();
        }
        $this->consume(TokenType::RPAREN, '期望 )');

        // 返回类型
        $returnType = 'void';
        if ($this->match(TokenType::COLON)) {
            $returnType = $this->parseType();
        }

        // 方法体
        $this->consume(TokenType::LBRACE, '期望 {');
        $body = [];
        while (!$this->check(TokenType::RBRACE) && !$this->check(TokenType::EOF)) {
            $body[] = $this->parseStmt();
        }
        $this->consume(TokenType::RBRACE, '期望 }');

        return new MethodNode($name, $visibility, $params, $returnType, $body);
    }

    // ============================================================
    // 参数, 语句, 表达式...
    // ============================================================

    /** @return ParamNode[] */
    private function parseParams(): array
    {
        $params = [];
        do {
            $params[] = $this->parseParam();
        } while ($this->match(TokenType::COMMA));
        return $params;
    }

    private function parseParam(): ParamNode
    {
        $type = $this->parseType();
        $this->consume(TokenType::IDENTIFIER, '期望参数名'); // Lexer 已将 $name 作为 IDENTIFIER
        // 获取上一个 token
        $varName = $this->previous()->lexeme; // e.g. '$argc'
        return new ParamNode($type, $varName);
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
        if ($this->match(TokenType::BREAK_KW))      { $this->consume(TokenType::SEMICOLON, '期望 ;'); return new BreakStmtNode(); }
        if ($this->match(TokenType::CONTINUE_KW))   { $this->consume(TokenType::SEMICOLON, '期望 ;'); return new ContinueStmtNode(); }
        // 标签: IDENTIFIER COLON
        if ($this->check(TokenType::IDENTIFIER) && $this->checkNext(TokenType::COLON)) {
            $label = $this->advance()->lexeme;
            $this->advance(); // skip :
            return new LabelStmtNode($label);
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
        $this->consume(TokenType::LPAREN, '期望 (' );
        $cond = $this->parseExpr();
        $this->consume(TokenType::RPAREN, '期望 )');
        $this->consume(TokenType::LBRACE, '期望 {');
        $thenBody = $this->parseBlock();
        $this->consume(TokenType::RBRACE, '期望 }');

        $elseifs = [];
        while ($this->match(TokenType::ELSEIF_KW)) {
            $this->consume(TokenType::LPAREN, '期望 (' );
            $eCond = $this->parseExpr();
            $this->consume(TokenType::RPAREN, '期望 )');
            $this->consume(TokenType::LBRACE, '期望 {');
            $eBody = $this->parseBlock();
            $this->consume(TokenType::RBRACE, '期望 }');
            $elseifs[] = new ElseIfBranch($eCond, $eBody);
        }

        $elseBody = [];
        if ($this->match(TokenType::ELSE_KW)) {
            // else if (...) → 转为 elseif
            if ($this->match(TokenType::IF_KW)) {
                $this->consume(TokenType::LPAREN, '期望 (' );
                $eCond = $this->parseExpr();
                $this->consume(TokenType::RPAREN, '期望 )');
                $this->consume(TokenType::LBRACE, '期望 {');
                $eBody = $this->parseBlock();
                $this->consume(TokenType::RBRACE, '期望 }');
                $elseifs[] = new ElseIfBranch($eCond, $eBody);
            } else {
                $this->consume(TokenType::LBRACE, '期望 {');
                $elseBody = $this->parseBlock();
                $this->consume(TokenType::RBRACE, '期望 }');
            }
        }

        // 继续检测 else if 链
        while ($this->match(TokenType::ELSE_KW)) {
            if ($this->match(TokenType::IF_KW)) {
                $this->consume(TokenType::LPAREN, '期望 (' );
                $eCond = $this->parseExpr();
                $this->consume(TokenType::RPAREN, '期望 )');
                $this->consume(TokenType::LBRACE, '期望 {');
                $eBody = $this->parseBlock();
                $this->consume(TokenType::RBRACE, '期望 }');
                $elseifs[] = new ElseIfBranch($eCond, $eBody);
            } elseif (!empty($elseBody)) {
                $this->error('多个 else 块');
            } else {
                $this->consume(TokenType::LBRACE, '期望 {');
                $elseBody = $this->parseBlock();
                $this->consume(TokenType::RBRACE, '期望 }');
            }
        }

        return new IfStmtNode($cond, $thenBody, $elseifs, $elseBody);
    }

    // while (cond) { body }
    private function parseWhileStmt(): WhileStmtNode
    {
        $this->consume(TokenType::LPAREN, '期望 (' );
        $cond = $this->parseExpr();
        $this->consume(TokenType::RPAREN, '期望 )');
        $this->consume(TokenType::LBRACE, '期望 {');
        $body = $this->parseBlock();
        $this->consume(TokenType::RBRACE, '期望 }');
        return new WhileStmtNode($cond, $body);
    }

    // goto LABEL;
    private function parseGotoStmt(): GotoStmtNode
    {
        $label = $this->consume(TokenType::IDENTIFIER, '期望标签名')->lexeme;
        $this->consume(TokenType::SEMICOLON, '期望 ;');
        return new GotoStmtNode($label);
    }

    // do { body } while (cond);
    private function parseDoWhileStmt(): DoWhileStmtNode
    {
        $this->consume(TokenType::LBRACE, '期望 {');
        $body = $this->parseBlock();
        $this->consume(TokenType::RBRACE, '期望 }');
        $this->consume(TokenType::WHILE_KW, '期望 while');
        $this->consume(TokenType::LPAREN, '期望 (');
        $cond = $this->parseExpr();
        $this->consume(TokenType::RPAREN, '期望 )');
        $this->consume(TokenType::SEMICOLON, '期望 ;');
        return new DoWhileStmtNode($cond, $body);
    }

    // list($a, $b) = expr;
    private function parseListStmt(bool $nested = false): ListStmtNode
    {
        $short = $this->previous()->type === TokenType::LBRACKET;
        if (!$short) {
            $this->consume(TokenType::LPAREN, '期望 (');
        }
        $vars = $this->parseListVars();
        if ($short) {
            $this->consume(TokenType::RBRACKET, '期望 ]');
        } else {
            $this->consume(TokenType::RPAREN, '期望 )');
        }
        if ($nested) {
            // 嵌套 list 无 = expr
            return new ListStmtNode($vars, new NullLiteralExpr(), $short);
        }
        $this->consume(TokenType::EQUALS, '期望 =');
        $expr = $this->parseExpr();
        $this->consume(TokenType::SEMICOLON, '期望 ;');
        return new ListStmtNode($vars, $expr, $short);
    }

    /** @return array (null|string|ListStmtNode)[] */
    private function parseListVars(): array
    {
        $vars = [];
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
            // 嵌套 list() 或 []
            if ($this->check(TokenType::LIST_KW) || $this->check(TokenType::LBRACKET)) {
                $this->advance();
                $vars[] = $this->parseListStmt(true);
                // 跳过后续逗号
                if ($this->check(TokenType::COMMA)) $this->advance();
                continue;
            }
            // 普通变量
            $varName = $this->consume(TokenType::IDENTIFIER, '期望变量名')->lexeme;
            $vars[] = ltrim($varName, '$');

            // 逗号继续，否则结束
            if (!$this->check(TokenType::COMMA)) break;
            $this->advance();
        }
        return $vars;
    }

    // for (init; cond; step) { body }
    private function parseForStmt(): ForStmtNode
    {
        $this->consume(TokenType::LPAREN, '期望 (' );
        $init = !$this->check(TokenType::SEMICOLON) ? $this->parseForInitExpr() : null;
        $this->consume(TokenType::SEMICOLON, '期望 ;');
        $cond = !$this->check(TokenType::SEMICOLON) ? $this->parseExpr() : null;
        $this->consume(TokenType::SEMICOLON, '期望 ;');
        $step = !$this->check(TokenType::RPAREN) ? $this->parseForInitExpr() : null;
        $this->consume(TokenType::RPAREN, '期望 )');
        $this->consume(TokenType::LBRACE, '期望 {');
        $body = $this->parseBlock();
        $this->consume(TokenType::RBRACE, '期望 }');
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
        $this->consume(TokenType::LPAREN, '期望 (' );
        $arr = $this->parseExpr();
        $this->consume(TokenType::AS_KW, '期望 as');
        $keyVar = null;
        $firstVar = $this->advance()->lexeme; // could be $val or $key
        if ($this->match(TokenType::DOUBLE_ARROW)) {
            $keyVar = $firstVar;
            $valVar = $this->advance()->lexeme;
        } else {
            $valVar = $firstVar;
        }
        $this->consume(TokenType::RPAREN, '期望 )');
        $this->consume(TokenType::LBRACE, '期望 {');
        $body = $this->parseBlock();
        $this->consume(TokenType::RBRACE, '期望 }');
        return new ForeachStmtNode($arr, $valVar, $keyVar, $body);
    }

    // switch (expr) { case val: stmt* break; case val: stmt* break; default: stmt* }
    private function parseSwitchStmt(): SwitchStmtNode
    {
        $this->consume(TokenType::LPAREN, '期望 (');
        $cond = $this->parseExpr();
        $this->consume(TokenType::RPAREN, '期望 )');
        $this->consume(TokenType::LBRACE, '期望 {');

        $cases = [];
        $hasDefault = false;
        while (!$this->check(TokenType::RBRACE) && !$this->check(TokenType::EOF)) {
            if ($this->match(TokenType::CASE_KW)) {
                $val = $this->parseExpr();
                $this->consume(TokenType::COLON, '期望 :');
                $body = $this->parseCaseBody();
                $cases[] = new CaseBranch($val, $body);
            } elseif ($this->match(TokenType::DEFAULT_KW)) {
                if ($hasDefault) {
                    $this->error('switch 中只能有一个 default');
                }
                $hasDefault = true;
                $this->consume(TokenType::COLON, '期望 :');
                $body = $this->parseCaseBody();
                $cases[] = new CaseBranch(null, $body);
            } else {
                $this->error('期望 case 或 default，得到 ' . $this->peek()->lexeme);
            }
        }
        $this->consume(TokenType::RBRACE, '期望 }');

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
        $this->consume(TokenType::SEMICOLON, '期望 ;');
        return new EchoStmtNode($exprs);
    }

    private function parseReturnStmt(): ReturnStmtNode
    {
        $expr = null;
        if (!$this->check(TokenType::SEMICOLON)) {
            $expr = $this->parseExpr();
        }
        $this->consume(TokenType::SEMICOLON, '期望 ;');
        return new ReturnStmtNode($expr);
    }

    private function parseAssignStmt(): AssignStmtNode
    {
        $varName = $this->advance()->lexeme;
        $this->consume(TokenType::EQUALS, '期望 =');
        $expr = $this->parseExpr();
        $this->consume(TokenType::SEMICOLON, '期望 ;');
        return new AssignStmtNode($varName, $expr);
    }

    private function parseExprStmt(): StmtNode
    {
        $expr = $this->parseExpr();
        // 数组元素赋值: $arr[$i] = value
        if ($expr instanceof ArrayAccessExpr && $this->check(TokenType::EQUALS)) {
            $this->advance();
            $val = $this->parseExpr();
            $this->consume(TokenType::SEMICOLON, '期望 ;');
            return new AssignArrayStmtNode($expr, $val);
        }
        // 属性赋值: $this->prop = value
        if ($expr instanceof PropertyAccessExpr && $this->check(TokenType::EQUALS)) {
            $this->advance();
            $val = $this->parseExpr();
            $this->consume(TokenType::SEMICOLON, '期望 ;');
            return new AssignPropStmtNode($expr, $val);
        }
        // 复合赋值: $var += expr 或 $this->prop += expr
        $compOps = [TokenType::PLUS_EQ, TokenType::MINUS_EQ, TokenType::STAR_EQ, TokenType::SLASH_EQ, TokenType::DOT_EQ];
        foreach ($compOps as $op) {
            if ($this->check($op)) {
                $this->advance();
                $val = $this->parseExpr();
                $this->consume(TokenType::SEMICOLON, '期望 ;');
                return new ExprStmtNode($this->setPos(new CompoundAssignExpr($expr, $op->value, $val), $expr->line, $expr->column));
            }
        }
        $this->consume(TokenType::SEMICOLON, '期望 ;');
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
        if ($this->match(TokenType::QUEST)) {
            $then = $this->parseExpr();
            $this->consume(TokenType::COLON, '期望 :');
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
        while ($this->match(TokenType::EQ) || $this->match(TokenType::NE) || $this->match(TokenType::SPACESHIP)) {
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
            if ($this->match(TokenType::ARROW)) {
                $member = $this->parseMethodName()->lexeme;
                if ($this->match(TokenType::LPAREN)) {
                    $args = $this->parseArgs();
                    $this->consume(TokenType::RPAREN, '期望 )');
                    $expr = $this->setPos(new CallExpr($expr, $member, $args), $expr->line, $expr->column);
                } else {
                    $expr = $this->setPos(new PropertyAccessExpr($expr, $member), $expr->line, $expr->column);
                }
            } elseif ($this->match(TokenType::LBRACKET)) {
                $index = $this->parseExpr();
                $this->consume(TokenType::RBRACKET, '期望 ]');
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
                $this->consume(TokenType::RPAREN, '期望 )');
                return $inner; // 括号不产生额外 AST 节点，直接返回内部表达式
            }
        }
        // match 表达式: match(expr) { arm, arm, ... }
        if ($this->match(TokenType::MATCH)) {
            return $this->setPos($this->parseMatchExpr(), $line, $col);
        }
        // 变量
        if ($this->check(TokenType::IDENTIFIER) || $this->check(TokenType::VAR_DUMP) || $this->check(TokenType::COUNT) || $this->check(TokenType::EXIT) || $this->check(TokenType::DIE) || $this->check(TokenType::ISSET) || $this->check(TokenType::EMPTY_KW) || $this->check(TokenType::UNSET) || $this->check(TokenType::ERROR) || $this->check(TokenType::TIME) || $this->check(TokenType::DATE) || $this->check(TokenType::SLEEP) || $this->check(TokenType::USLEEP) || $this->check(TokenType::HRTIME) || $this->check(TokenType::IS_INT) || $this->check(TokenType::IS_FLOAT) || $this->check(TokenType::IS_STRING) || $this->check(TokenType::IS_BOOL) || $this->check(TokenType::IS_ARRAY) || $this->check(TokenType::IS_OBJECT) || $this->check(TokenType::IS_NULL) || $this->check(TokenType::IS_CALLABLE)) {
            $name = $this->advance()->lexeme;

            // $obj->xxx 或 Class::xxx
            if ($this->match(TokenType::ARROW) || $this->match(TokenType::DOUBLE_COLON)) {
                $opType = $this->previous()->type; // ARROW or DOUBLE_COLON
                $memberName = $this->parseMethodName()->lexeme;

                // EnumName::CASE → 枚举访问（需解析命名空间 + use 导入）
                if ($opType === TokenType::DOUBLE_COLON) {
                    $enumFq = $this->resolveEnumName($name);
                    if ($enumFq !== null && isset($this->enumNames[$enumFq])) {
                        return $this->setPos(new EnumAccessExpr($enumFq, $memberName), $line, $col);
                    }
                }

                // 方法调用: $obj->method(args)
                if ($this->match(TokenType::LPAREN)) {
                    $args = $this->parseArgs();
                    $this->consume(TokenType::RPAREN, '期望 )');
                    return $this->setPos(new CallExpr(new VariableExpr($name), $memberName, $args), $line, $col);
                }

                // 属性访问: $obj->prop
                return $this->setPos(new PropertyAccessExpr(new VariableExpr($name), $memberName), $line, $col);
            }

            // 函数调用（含 var_dump、闭包调用 $var()）
            if ($this->match(TokenType::LPAREN)) {
                $args = $this->parseArgs();
                $this->consume(TokenType::RPAREN, '期望 )');
                // $var() → 闭包调用
                if (str_starts_with($name, '$')) {
                    return $this->setPos(new CallExpr(new VariableExpr($name), '__invoke', $args), $line, $col);
                }
                // 内置函数不解析命名空间
                if ($name !== 'var_dump' && $name !== 'count' && $name !== 'exit' && $name !== 'die' && $name !== 'isset' && $name !== 'empty' && $name !== 'unset' && $name !== 'error' && $name !== 'time' && $name !== 'date' && $name !== 'sleep' && $name !== 'usleep' && $name !== 'hrtime' && !str_starts_with($name, 'is_')) {
                    $name = $this->resolveFunctionName($name);
                }
                return $this->setPos(new CallExpr(null, $name, $args), $line, $col);
            }

            // 数组访问: $var[expr]
            if ($this->match(TokenType::LBRACKET)) {
                $index = $this->parseExpr();
                $this->consume(TokenType::RBRACKET, '期望 ]');
                return $this->setPos(new ArrayAccessExpr(new VariableExpr($name), $index), $line, $col);
            }

            // 常量引用检测：全大写名称 → 检查是否跨命名空间
            // 但跳过枚举名（枚举通过 :: 访问，单独的全大写名可能是枚举的 use 导入）
            if (!str_starts_with($name, '$') && self::isConstantName($name)
                && $this->resolveEnumName($name) === null) {
                if (isset($this->declaredConsts[$name])) {
                    $ns = $this->declaredConsts[$name];
                    if ($ns !== $this->currentNamespace) {
                        $this->error("常量 '{$name}' 定义在命名空间 '{$ns}'，不能在当前命名空间直接引用");
                    }
                } else {
                    $this->error("未定义的常量 '{$name}'（可能定义在其他命名空间，不支持跨命名空间常量引用）");
                }
            }

            return $this->setPos(new VariableExpr($name), $line, $col);
        }

        $this->error("期望表达式，得到 '{$this->peek()->lexeme}'");
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
            } while ($this->match(TokenType::COMMA));
        }
        $this->consume(TokenType::RBRACKET, '期望 ]');
        return new ArrayLiteralExpr($entries);
    }

    /** match(expr) { val1, val2 => result, default => fallback } */
    private function parseMatchExpr(): MatchExpr
    {
        $this->consume(TokenType::LPAREN, '期望 (');
        $cond = $this->parseExpr();
        $this->consume(TokenType::RPAREN, '期望 )');
        $this->consume(TokenType::LBRACE, '期望 {');

        $arms = [];
        while (!$this->check(TokenType::RBRACE) && !$this->check(TokenType::EOF)) {
            if ($this->match(TokenType::DEFAULT_KW)) {
                $this->consume(TokenType::DOUBLE_ARROW, '期望 =>');
                $body = $this->parseExpr();
                $arms[] = new MatchArm([], $body); // empty values = default
            } else {
                $values = [];
                do {
                    $values[] = $this->parseExpr();
                } while ($this->match(TokenType::COMMA) && !$this->check(TokenType::DOUBLE_ARROW));
                $this->consume(TokenType::DOUBLE_ARROW, '期望 =>');
                $body = $this->parseExpr();
                $arms[] = new MatchArm($values, $body);
            }
            // 可选尾随逗号
            if ($this->check(TokenType::COMMA)) $this->advance();
        }
        $this->consume(TokenType::RBRACE, '期望 }');

        return new MatchExpr($cond, $arms);
    }

    /** 匿名函数: function ( params? ) use (vars?) : type? { body } */
    private function parseClosure(): ClosureExpr
    {
        $this->consume(TokenType::LPAREN, '期望 (');
        $params = [];
        if (!$this->check(TokenType::RPAREN)) {
            $params = $this->parseParams();
        }
        $this->consume(TokenType::RPAREN, '期望 )');

        // use ($var1, $var2) 闭包变量捕获
        $useVars = [];
        if ($this->match(TokenType::USE)) {
            $this->consume(TokenType::LPAREN, '期望 (');
            if (!$this->check(TokenType::RPAREN)) {
                do {
                    $varName = $this->consume(TokenType::IDENTIFIER, '期望变量名')->lexeme;
                    $vn = ltrim($varName, '$');
                    $useVars[] = [$vn, '']; // type 暂时留空，CodeGenerator 会从 varTypes 查
                } while ($this->match(TokenType::COMMA));
            }
            $this->consume(TokenType::RPAREN, '期望 )');
        }

        $returnType = 'void';
        if ($this->match(TokenType::COLON)) {
            $returnType = $this->parseType();
        }

        $this->consume(TokenType::LBRACE, '期望 {');
        $body = [];
        while (!$this->check(TokenType::RBRACE) && !$this->check(TokenType::EOF)) {
            $body[] = $this->parseStmt();
        }
        $this->consume(TokenType::RBRACE, '期望 }');

        return new ClosureExpr($params, $returnType, $body, $useVars);
    }

    /** @return ExprNode[] */
    private function parseArgs(): array
    {
        $args = [];
        if (!$this->check(TokenType::RPAREN)) {
            do {
                $args[] = $this->parseExpr();
            } while ($this->match(TokenType::COMMA));
        }
        return $args;
    }

    private function parseNewExpr(): NewExpr
    {
        $className = $this->consume(TokenType::IDENTIFIER, '期望类名')->lexeme;
        $this->consume(TokenType::LPAREN, '期望 (');
        $args = $this->parseArgs();
        $this->consume(TokenType::RPAREN, '期望 )');
        return new NewExpr($this->resolveClassName($className), $args);
    }

    private function parseCastExpr(): CastExpr
    {
        $line = $this->peek()->line;
        $col  = $this->peek()->column;
        $this->consume(TokenType::LPAREN, '期望 (');
        $typeToken = $this->advance();
        $castType = $typeToken->lexeme; // int, float, string, bool, array
        $this->consume(TokenType::RPAREN, '期望 )');
        $expr = $this->parsePrimary(); // 只转换紧邻表达式，不吞掉 . 运算符
        return $this->setPos(new CastExpr($castType, $expr), $line, $col);
    }

    // ============================================================
    // 辅助方法
    // ============================================================

    /** 解析方法名（IDENTIFIER | 类型关键字 | CONSTRUCT | DESTRUCT | 内置函数名） */
    private function parseMethodName(): Token
    {
        $tok = $this->advance();
        $valid = [
            TokenType::IDENTIFIER, TokenType::CONSTRUCT, TokenType::DESTRUCT,
            TokenType::TYPE_INT, TokenType::TYPE_FLOAT, TokenType::TYPE_STRING,
            TokenType::TYPE_BOOL, TokenType::TYPE_VOID, TokenType::TYPE_ARRAY, TokenType::TYPE_MIXED,
            TokenType::COUNT, TokenType::VAR_DUMP, TokenType::ECHO_KW,
            TokenType::DIE, TokenType::ISSET, TokenType::EMPTY_KW, TokenType::LIST_KW, TokenType::EXIT, TokenType::UNSET,
        ];
        if (!in_array($tok->type, $valid, true)) {
            $this->error("期望方法名，得到 '{$tok->lexeme}'");
        }
        return $tok;
    }

    private function parseVisibility(): string
    {
        if ($this->match(TokenType::PUBLIC_KW)) return 'public';
        if ($this->match(TokenType::PRIVATE_KW)) return 'private';
        $this->error('期望 public 或 private');
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
            TokenType::TYPE_MIXED,
            TokenType::IDENTIFIER, // 类名/枚举名作为类型
        ];
        if (!in_array($typeToken->type, $validTypes, true)) {
            $this->error("期望类型声明，得到 '{$typeToken->lexeme}'");
        }
        $result = $typeToken->lexeme;

        // union type: int|string|bool
        while ($this->match(TokenType::PIPE)) {
            $next = $this->advance();
            $ut = [TokenType::TYPE_INT, TokenType::TYPE_FLOAT, TokenType::TYPE_STRING, TokenType::TYPE_BOOL, TokenType::TYPE_ARRAY, TokenType::TYPE_MIXED, TokenType::IDENTIFIER];
            if (!in_array($next->type, $ut, true)) {
                $this->error("联合类型中不支持 '{$next->lexeme}'");
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
        $this->error($errMsg . "，得到 '{$this->peek()->lexeme}'");
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
                // 顶层 ] 闭合后，下一个 token 如果是 = 则是 list
                if ($depth === 0 && $this->current < count($this->tokens)) {
                    $n = $this->peek();
                    if ($n->type === TokenType::EQUALS) { $isList = true; }
                }
                continue;
            }
            if (in_array($t->type, [
                TokenType::INT_LIT, TokenType::FLOAT_LIT, TokenType::STRING_LIT,
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
            sprintf("Parser 错误 [%d:%d]: %s", $tok->line, $tok->column, $msg)
        );
    }
}
