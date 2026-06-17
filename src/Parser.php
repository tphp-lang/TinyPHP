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

    /** @param Token[] $tokens */
    public function __construct(array $tokens)
    {
        $this->tokens = $tokens;
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
                // 文件中有游离代码——拒绝（多文件场景仍保持严格）
                $tok = $this->peek();
                $this->error("不支持的全局游离代码 '{$tok->lexeme}'（多文件编译只接受 namespace/use/class/function 声明）");
            } else {
                $this->error('期望 namespace/use/class/function，得到 ' . $this->peek()->lexeme);
            }
        }

        // 允许只有函数没有类的情况（多文件编译时类可能在别的文件）
        return new ProgramNode($mainClass, $extraClasses, $functions);
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
    // class_decl → CLASS_KW IDENTIFIER LBRACE method* RBRACE
    // ============================================================
    private function parseClassDecl(): ClassNode
    {
        $this->consume(TokenType::CLASS_KW, '期望 class 关键字');
        $name = $this->consume(TokenType::IDENTIFIER, '期望类名')->lexeme;
        $this->consume(TokenType::LBRACE, '期望 {');

        $methods = [];
        while (!$this->check(TokenType::RBRACE) && !$this->check(TokenType::EOF)) {
            $methods[] = $this->parseMethod();
        }

        $this->consume(TokenType::RBRACE, '期望 }');
        return new ClassNode($name, $methods, $this->currentNamespace);
    }

    // ============================================================
    // method → visibility FUNCTION name LPAREN params? RPAREN COLON type LBRACE stmt* RBRACE
    // ============================================================
    private function parseMethod(): MethodNode
    {
        $visibility = $this->parseVisibility();

        $this->consume(TokenType::FUNCTION, '期望 function 关键字');

        // name: IDENTIFIER | CONSTRUCT | DESTRUCT
        $nameToken = $this->advance();
        if (!in_array($nameToken->type, [TokenType::IDENTIFIER, TokenType::CONSTRUCT, TokenType::DESTRUCT], true)) {
            $this->error("期望方法名，得到 '{$nameToken->lexeme}'");
        }
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
        if ($this->match(TokenType::ECHO_KW)) {
            return $this->parseEchoStmt();
        }
        if ($this->match(TokenType::RETURN_KW)) {
            return $this->parseReturnStmt();
        }
        // 赋值: $var = expr;
        if ($this->check(TokenType::IDENTIFIER) && $this->checkNext(TokenType::EQUALS)) {
            return $this->parseAssignStmt();
        }
        // 表达式语句
        return $this->parseExprStmt();
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

    private function parseExprStmt(): ExprStmtNode
    {
        $expr = $this->parseExpr();
        $this->consume(TokenType::SEMICOLON, '期望 ;');
        return new ExprStmtNode($expr);
    }

    private function parseExpr(): ExprNode
    {
        return $this->parseAdditive();
    }

    // additive → multiplicative ((PLUS|MINUS) multiplicative)*
    private function parseAdditive(): ExprNode
    {
        $left = $this->parseMultiplicative();
        while ($this->match(TokenType::PLUS) || $this->match(TokenType::MINUS)) {
            $op = $this->previous()->lexeme;
            $right = $this->parseMultiplicative();
            $left = new BinaryExpr($left, $op, $right);
        }
        return $left;
    }

    // multiplicative → primary ((STAR|SLASH) primary)*
    private function parseMultiplicative(): ExprNode
    {
        $left = $this->parsePrimary();
        while ($this->match(TokenType::STAR) || $this->match(TokenType::SLASH)) {
            $op = $this->previous()->lexeme;
            $right = $this->parsePrimary();
            $left = new BinaryExpr($left, $op, $right);
        }
        return $left;
    }

    private function parsePrimary(): ExprNode
    {
        // 字符串
        if ($this->match(TokenType::STRING_LIT)) {
            return new StringLiteralExpr((string)$this->previous()->literal);
        }
        // 数字
        if ($this->match(TokenType::INT_LIT)) {
            return new IntLiteralExpr((int)$this->previous()->literal);
        }
        if ($this->match(TokenType::FLOAT_LIT)) {
            return new FloatLiteralExpr((float)$this->previous()->literal);
        }
        // 布尔 / null
        if ($this->match(TokenType::TRUE_KW)) {
            return new BoolLiteralExpr(true);
        }
        if ($this->match(TokenType::FALSE_KW)) {
            return new BoolLiteralExpr(false);
        }
        if ($this->match(TokenType::NULL_KW)) {
            return new NullLiteralExpr();
        }
        // 匿名函数 / 闭包: function(): type { ... }
        if ($this->match(TokenType::FUNCTION)) {
            return $this->parseClosure();
        }
        // 数组字面量: [ expr, expr, ... ]
        if ($this->match(TokenType::LBRACKET)) {
            return $this->parseArrayLiteral();
        }
        // new ClassName(args)
        if ($this->match(TokenType::NEW_KW)) {
            return $this->parseNewExpr();
        }
        // 类型转换: (type) expr
        if ($this->check(TokenType::LPAREN)) {
            $nextType = $this->peek(1);
            $typeTokens = [
                TokenType::TYPE_INT, TokenType::TYPE_FLOAT, TokenType::TYPE_STRING,
                TokenType::TYPE_BOOL, TokenType::TYPE_ARRAY,
            ];
            if (in_array($nextType->type, $typeTokens, true)) {
                return $this->parseCastExpr();
            }
        }
        // 变量
        if ($this->check(TokenType::IDENTIFIER) || $this->check(TokenType::VAR_DUMP)) {
            $name = $this->advance()->lexeme;

            // 方法调用: $obj->method(args) 或 Class::method(args)
            if ($this->match(TokenType::ARROW) || $this->match(TokenType::DOUBLE_COLON)) {
                $methodName = $this->consume(TokenType::IDENTIFIER, '期望方法名')->lexeme;
                $this->consume(TokenType::LPAREN, '期望 (');
                $args = $this->parseArgs();
                $this->consume(TokenType::RPAREN, '期望 )');
                return new CallExpr(new VariableExpr($name), $methodName, $args);
            }

            // 函数调用（含 var_dump、闭包调用 $var()）
            if ($this->match(TokenType::LPAREN)) {
                $args = $this->parseArgs();
                $this->consume(TokenType::RPAREN, '期望 )');
                // $var() → 闭包调用
                if (str_starts_with($name, '$')) {
                    return new CallExpr(new VariableExpr($name), '__invoke', $args);
                }
                // 内置函数不解析命名空间
                if ($name !== 'var_dump') {
                    $name = $this->resolveFunctionName($name);
                }
                return new CallExpr(null, $name, $args);
            }

            return new VariableExpr($name);
        }

        $this->error("期望表达式，得到 '{$this->peek()->lexeme}'");
    }

    /** 数组字面量: [ expr (, expr)* ] */
    private function parseArrayLiteral(): ArrayLiteralExpr
    {
        $elements = [];
        if (!$this->check(TokenType::RBRACKET)) {
            do {
                $elements[] = $this->parseExpr();
            } while ($this->match(TokenType::COMMA));
        }
        $this->consume(TokenType::RBRACKET, '期望 ]');
        return new ArrayLiteralExpr($elements);
    }

    /** 匿名函数: function ( params? ) : type? { body } */
    private function parseClosure(): ClosureExpr
    {
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

        return new ClosureExpr($params, $returnType, $body);
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
        $this->consume(TokenType::LPAREN, '期望 (');
        $typeToken = $this->advance();
        $castType = $typeToken->lexeme; // int, float, string, bool, array
        $this->consume(TokenType::RPAREN, '期望 )');
        $expr = $this->parseExpr();
        return new CastExpr($castType, $expr);
    }

    // ============================================================
    // 辅助方法
    // ============================================================

    private function parseVisibility(): string
    {
        if ($this->match(TokenType::PUBLIC_KW)) return 'public';
        if ($this->match(TokenType::PRIVATE_KW)) return 'private';
        $this->error('期望 public 或 private');
    }

    private function parseType(): string
    {
        $typeToken = $this->advance();
        $validTypes = [
            TokenType::TYPE_INT, TokenType::TYPE_FLOAT, TokenType::TYPE_STRING,
            TokenType::TYPE_BOOL, TokenType::TYPE_VOID, TokenType::TYPE_ARRAY,
            TokenType::IDENTIFIER, // 类名作为类型
        ];
        if (!in_array($typeToken->type, $validTypes, true)) {
            $this->error("期望类型声明，得到 '{$typeToken->lexeme}'");
        }
        return $typeToken->lexeme;
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

    private function error(string $msg): never
    {
        $tok = $this->peek();
        throw new RuntimeException(
            sprintf("Parser 错误 [%d:%d]: %s", $tok->line, $tok->column, $msg)
        );
    }
}
