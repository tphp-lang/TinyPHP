<?php

declare(strict_types=1);

namespace Tphp;

use Tphp\AST\{
    ASTNode, ProgramNode, FunctionDeclNode, ParamNode,
    StmtNode, VarDeclNode, ExprStmtNode, PrintStmtNode, ReturnStmtNode,
    IfStmtNode, WhileStmtNode, ForStmtNode, SwitchStmtNode, SwitchCaseNode,
    BreakStmtNode, UnsetStmtNode, ArrayAppendStmtNode,
    ExprNode, IntegerLiteralNode, FloatLiteralNode, StringLiteralNode,
    BoolLiteralNode, NullLiteralNode, VarRefNode, ConstRefNode, BinaryOpNode,
    FuncCallNode, IndexAccessNode, StringRangeNode, ArrayLiteralNode,
    ClosureNode, ExprCallNode, PostIncrementNode,
    TphpType, UseImportNode, ConstDeclNode, CastExprNode,
    ExternFuncNode, CFuncCallNode,
    ClassDeclNode, MethodDeclNode, ThisExprNode, MethodCallNode, NewExprNode,
    EnumDeclNode, EnumCaseNode, EnumAccessNode,
};

final class Parser
{
    private Lexer $lexer;

    /** @var array<string, FunctionDeclNode> */
    private array $functionDecls = [];
    private int $foreachCounter = 0;

    /** @var array<string, ClosureNode> */
    private array $closureDecls = [];

    /** @var string[] */
    private array $errors = [];

    public function __construct(Lexer $lexer)
    {
        $this->lexer = $lexer;
    }

    /** @return string[] */
    public function getErrors(): array
    {
        return $this->errors;
    }

    public function parse(): ProgramNode
    {
        // Expect <?php
        if (!$this->lexer->match(TokenType::OpenTag)) {
            throw new \RuntimeException('File must start with <?php');
        }

        // Optional namespace
        $nsName = null;
        if ($this->lexer->match(TokenType::Namespace)) {
            $nsName = $this->parseQualifiedName();
            $this->lexer->expect(TokenType::Semicolon);
        }

        // Parse use statements
        $imports = [];
        while ($this->lexer->current()->isType(TokenType::Use)) {
            $result = $this->parseUseStatements();
            if (is_array($result)) {
                $imports = array_merge($imports, $result);
            } else {
                $imports[] = $result;
            }
        }

        // Parse const declarations (before functions/classes)
        $consts = [];
        while ($this->lexer->current()->isType(TokenType::Const)) {
            $consts[] = $this->parseConst();
        }

        // Parse #extern declarations
        $externs = [];
        while ($this->lexer->current()->isType(TokenType::Extrn)) {
            $externs[] = $this->parseExtern();
        }

        // Parse enum declarations (before functions/classes)
        $enums = [];
        while ($this->lexer->current()->isType(TokenType::Enum)) {
            $enums[] = $this->parseEnumDeclaration();
        }

        $functions = [];
        $classes = [];
        while (!$this->lexer->isEof()) {
            if ($this->lexer->match(TokenType::Const)) {
                $consts[] = $this->parseConst();
            } elseif ($this->lexer->match(TokenType::Extrn)) {
                $externs[] = $this->parseExtern();
            } elseif ($this->lexer->match(TokenType::Enum)) {
                $enums[] = $this->parseEnumDeclaration();
            } elseif ($this->lexer->match(TokenType::Function)) {
                $fn = $this->parseFunctionDeclaration();
                $functions[] = $fn;
            } elseif ($this->lexer->match(TokenType::Class_)) {
                $classes[] = $this->parseClassDeclaration();
            } else {
                $tok = $this->lexer->current();
                throw new \RuntimeException(sprintf(
                    "No free-standing code allowed at line %d. Got: %s",
                    $tok->line, $tok->__toString()
                ));
            }
        }

        return new ProgramNode(1, $nsName, $imports, $consts, $enums, $externs, $functions, $classes);
    }

    /**
     * Parse a qualified name like 'Identifier' or 'Identifier\Identifier\...'
     */
    private function parseQualifiedName(): string
    {
        $name = $this->lexer->expect(TokenType::Identifier)->value;
        while ($this->lexer->match(TokenType::Backslash)) {
            $name .= '\\' . $this->lexer->expect(TokenType::Identifier)->value;
        }
        return $name;
    }

    /**
     * Parse 'use function Namespace\name;' or 'use Namespace\ClassName;'
     */
    /**
     * Parse 'use' statement. Returns single UseImportNode or array for group imports.
     * @return UseImportNode|UseImportNode[]
     */
    private function parseUseStatements(): UseImportNode|array
    {
        $line = $this->lexer->current()->line;
        $this->lexer->next(); // consume 'use'

        if ($this->lexer->match(TokenType::Function)) {
            $fullName = $this->parseQualifiedName();
            $parts = explode('\\', $fullName);
            $alias = end($parts);
            $this->lexer->expect(TokenType::Semicolon);
            return new UseImportNode($line, 'function', $fullName, $alias);
        }

        // use Namespace\ClassName; or use Namespace\{A, B, C};
        $nsTok = $this->lexer->expect(TokenType::Identifier);

        if ($this->lexer->match(TokenType::Backslash)) {
            // Check for group import: use Namespace\{A, B, C}
            if ($this->lexer->current()->isType(TokenType::LBrace)) {
                return $this->parseGroupUse($line, $nsTok->value);
            }
            // Normal qualified name: Namespace\ClassName
            $rest = $this->lexer->expect(TokenType::Identifier)->value;
            $fullName = $nsTok->value . '\\' . $rest;
        } else {
            $fullName = $nsTok->value;
        }

        $parts = explode('\\', $fullName);
        $alias = end($parts);
        $this->lexer->expect(TokenType::Semicolon);
        return new UseImportNode($line, 'class', $fullName, $alias);
    }

    /** @return UseImportNode[] */
    private function parseGroupUse(int $line, string $nsName): array
    {
        $this->lexer->next(); // consume {
        $imports = [];
        while (!$this->lexer->current()->isType(TokenType::RBrace) && !$this->lexer->isEof()) {
            $item = $this->lexer->expect(TokenType::Identifier)->value;
            $fqn = $nsName . '\\' . $item;
            $imports[] = new UseImportNode($line, 'class', $fqn, $item);
            $this->lexer->match(TokenType::Comma);
        }
        $this->lexer->expect(TokenType::RBrace);
        $this->lexer->expect(TokenType::Semicolon);
        return $imports;
    }

    // ==================== Const ====================

    /**
     * Parse 'const NAME = value;'
     */
    private function parseConst(): ConstDeclNode
    {
        $line = $this->lexer->current()->line;
        $this->lexer->next(); // consume 'const'
        $nameTok = $this->lexer->expect(TokenType::Identifier);
        $this->lexer->expect(TokenType::Assign);
        $init = $this->parseExpression();
        $this->lexer->expect(TokenType::Semicolon);
        return new ConstDeclNode($line, $nameTok->value, $init);
    }

    // ==================== Enum ====================

    /** Parse 'enum Name: type { case X = val; ... }' */
    private function parseEnumDeclaration(): EnumDeclNode
    {
        $line = $this->lexer->current()->line;
        $this->lexer->next(); // consume 'enum'

        $nameTok = $this->lexer->expect(TokenType::Identifier);
        $this->lexer->expect(TokenType::Colon);
        $backingType = $this->parseType(); // int or string

        $this->lexer->expect(TokenType::LBrace);
        $cases = [];
        while (!$this->lexer->current()->isType(TokenType::RBrace) && !$this->lexer->isEof()) {
            $this->lexer->expect(TokenType::Case_);
            $caseNameTok = $this->lexer->expect(TokenType::Identifier);
            $this->lexer->expect(TokenType::Assign);

            $value = null;
            if ($backingType === TphpType::Int) {
                $value = (int)$this->lexer->expect(TokenType::IntegerLiteral)->value;
            } else {
                $value = $this->lexer->expect(TokenType::StringLiteral)->value;
            }
            $this->lexer->expect(TokenType::Semicolon);
            $cases[] = new EnumCaseNode($caseNameTok->line, $caseNameTok->value, $value);
        }
        $this->lexer->expect(TokenType::RBrace);

        return new EnumDeclNode($line, $nameTok->value, $backingType, $cases);
    }

    // ==================== Extern ====================

    /**
     * Parse '#extern returnType name(paramTypes...);'
     * Handles C types: const char* → string, int → int, etc.
     */
    private function parseExtern(): ExternFuncNode
    {
        $line = $this->lexer->current()->line;
        $this->lexer->next(); // consume '#extern'

        // Return type (void, int, etc. — skip const/pointer for now)
        $returnType = $this->parseCType();

        // Function name
        $nameTok = $this->lexer->expect(TokenType::Identifier);

        // Param list
        $this->lexer->expect(TokenType::LParen);
        $params = [];
        if (!$this->lexer->current()->isType(TokenType::RParen)) {
            do {
                $type = $this->parseCType();
                $pNameTok = $this->lexer->expect(TokenType::Identifier);
                $params[] = new ParamNode($line, $pNameTok->value, $type);
            } while ($this->lexer->match(TokenType::Comma));
        }
        $this->lexer->expect(TokenType::RParen);
        $this->lexer->expect(TokenType::Semicolon);
        return new ExternFuncNode($line, $nameTok->value, $returnType, $params);
    }

    /** Parse a C type spec like 'const char*' → string, 'int' → int, 'void' → void */
    private function parseCType(): TphpType
    {
        // Skip 'const' qualifier if present
        $this->lexer->match(TokenType::Const);

        $type = TphpType::Int; // default
        $cur = $this->lexer->current();

        if ($cur->isType(TokenType::Void_)) {
            $type = TphpType::Void;
            $this->lexer->next();
        } elseif ($cur->isType(TokenType::Int)) {
            $type = TphpType::Int;
            $this->lexer->next();
        } elseif ($cur->isType(TokenType::Float)) {
            $type = TphpType::Float;
            $this->lexer->next();
        } elseif ($cur->isType(TokenType::Identifier)) {
            // char, bool, or other C type → map to appropriate TPHP type
            $name = $cur->value;
            $this->lexer->next();
            $type = match ($name) {
                'char'   => TphpType::String,
                'bool'   => TphpType::Bool,
                'double' => TphpType::Float,
                default  => TphpType::Int,
            };
        }

        // Skip pointer '*' if present (pointer types → treat as int/handle in FFI)
        if ($this->lexer->current()->isType(TokenType::Star)) {
            $this->lexer->next();
            // char* → string; other* → int (raw pointer)
            if ($type === TphpType::String) {
                $type = TphpType::String; // stays string for char*
            } else {
                $type = TphpType::Int;    // raw pointer → int
            }
        }

        return $type;
    }

    private function parseClassDeclaration(): ClassDeclNode
    {
        $nameTok = $this->lexer->expect(TokenType::Identifier);
        $this->lexer->expect(TokenType::LBrace);

        $methods = [];
        while (!$this->lexer->current()->isType(TokenType::RBrace) && !$this->lexer->isEof()) {
            $methods[] = $this->parseMethodDeclaration();
        }
        $this->lexer->expect(TokenType::RBrace);

        return new ClassDeclNode($nameTok->line, $nameTok->value, $methods);
    }

    private function parseMethodDeclaration(): MethodDeclNode
    {
        $visibility = 'public';
        $cur = $this->lexer->current();
        if ($cur->isType(TokenType::Public) || $cur->isType(TokenType::Private)) {
            $visibility = $cur->type->value; // 'public' or 'private'
            $this->lexer->next();
        }

        $line = $this->lexer->current()->line;
        $this->lexer->expect(TokenType::Function);

        $nameTok = $this->lexer->expect(TokenType::Identifier);
        $name = $nameTok->value;

        $this->lexer->expect(TokenType::LParen);
        $params = $this->parseParamList();
        $this->lexer->expect(TokenType::RParen);

        // return type (optional for __construct and __destruct)
        $returnType = TphpType::Void;
        if ($this->lexer->match(TokenType::Colon)) {
            $returnType = $this->parseType();
            // __construct/__destruct 默认 void，不允许写其他返回类型
            if (($name === '__construct' || $name === '__destruct')
                && $returnType !== TphpType::Void
            ) {
                $this->errors[] = sprintf(
                    "%s() 返回类型默认是 void，不需要显式声明",
                    $name
                );
            }
        }

        $this->lexer->expect(TokenType::LBrace);
        $body = $this->parseStatementList();
        $this->lexer->expect(TokenType::RBrace);

        return new MethodDeclNode($line, $name, $visibility, $returnType, $params, $body);
    }

    private function parseFunctionDeclaration(): FunctionDeclNode
    {
        $nameTok = $this->lexer->expect(TokenType::Identifier);
        $name = $nameTok->value;

        $this->lexer->expect(TokenType::LParen);
        $params = $this->parseParamList();
        $this->lexer->expect(TokenType::RParen);

        // return type
        $returnType = TphpType::Void;
        if ($this->lexer->match(TokenType::Colon)) {
            $returnType = $this->parseType();
        }

        $this->lexer->expect(TokenType::LBrace);
        $body = $this->parseStatementList();
        $this->lexer->expect(TokenType::RBrace);

        $fn = new FunctionDeclNode($nameTok->line, $name, $returnType, $params, $body);
        $this->functionDecls[$name] = $fn;

        // If it's main, verify it returns void
        if ($name === 'main' && $returnType !== TphpType::Void) {
            $this->errors[] = sprintf(
                "Entry function 'main' must return void, got '%s' at line %d",
                $returnType->value, $nameTok->line
            );
        }

        return $fn;
    }

    /** @return ParamNode[] */
    private function parseParamList(): array
    {
        $params = [];
        if ($this->lexer->current()->isType(TokenType::RParen)) {
            return $params;
        }
        do {
            $typeTok = $this->lexer->current();
            $typeName = null;
            if ($typeTok->isType(TokenType::Identifier)) {
                $typeName = $typeTok->value;
            }
            $type = $this->parseType();
            $varTok = $this->lexer->expect(TokenType::Variable);
            $params[] = new ParamNode($typeTok->line, $varTok->value, $type, $typeName);
        } while ($this->lexer->match(TokenType::Comma));
        return $params;
    }

    private function parseType(): TphpType
    {
        $cur = $this->lexer->current();
        $t = match (true) {
            $cur->isType(TokenType::Int)     => TphpType::Int,
            $cur->isType(TokenType::Float)   => TphpType::Float,
            $cur->isType(TokenType::String_) => TphpType::String,
            $cur->isType(TokenType::Bool_)   => TphpType::Bool,
            $cur->isType(TokenType::Void_)   => TphpType::Void,
            $cur->isType(TokenType::Identifier) => TphpType::Int, // class/enum name as type hint
            default => throw new \RuntimeException(
                sprintf("Expected type but got %s at line %d", $cur->__toString(), $cur->line)
            ),
        };
        $this->lexer->next();
        return $t;
    }

    /** @return StmtNode[] */
    private function parseStatementList(): array
    {
        $stmts = [];
        while (!$this->lexer->isEof() && !$this->lexer->current()->isType(TokenType::RBrace)) {
            // Handle foreach specially — it returns multiple statements
            if ($this->lexer->current()->isType(TokenType::Foreach)) {
                foreach ($this->parseForeachStmts() as $s) {
                    $stmts[] = $s;
                }
                continue;
            }
            $stmt = $this->parseStatement();
            if ($stmt !== null) {
                $stmts[] = $stmt;
            }
        }
        return $stmts;
    }

    private function parseStatement(): ?StmtNode
    {
        $cur = $this->lexer->current();

        // variable declaration, array append, or expression starting with variable
        if ($cur->isType(TokenType::Variable)) {
            $next = $this->lexer->peek();

            // $a[] = expr → ArrayAppendStmtNode
            // Token pattern: Variable, LBracket, RBracket, Assign
            if ($next->isType(TokenType::LBracket) &&
                $this->lexer->peekAt(2)->isType(TokenType::RBracket) &&
                $this->lexer->peekAt(3)->isType(TokenType::Assign)) {
                $varTok = $this->lexer->next(); // consume $a
                $this->lexer->next(); // consume [
                $this->lexer->next(); // consume ]
                $this->lexer->expect(TokenType::Assign);
                $expr = $this->parseExpression();
                $this->lexer->expect(TokenType::Semicolon);
                return new ArrayAppendStmtNode($varTok->line, $varTok->value, $expr);
            }

            // $x = expr → VarDeclNode
            if ($next->isType(TokenType::Assign)) {
                $varTok = $this->lexer->next(); // consume $x
                $this->lexer->expect(TokenType::Assign);
                $expr = $this->parseExpression();
                $this->lexer->expect(TokenType::Semicolon);
                return new VarDeclNode($varTok->line, $varTok->value, $expr);
            }

            // Compound assignment: $x += expr → VarDeclNode($x = $x + expr)
            // $x -= expr → VarDeclNode($x = $x - expr)
            // $x .= expr → VarDeclNode($x = $x . expr)
            if ($next->isType(TokenType::PlusAssign) || $next->isType(TokenType::MinusAssign) || $next->isType(TokenType::ConcatAssign)) {
                $varTok = $this->lexer->next(); // consume $x
                $opTok = $this->lexer->next();  // consume += or -= or .=
                $expr = $this->parseExpression();
                $this->lexer->expect(TokenType::Semicolon);
                $varRef = new VarRefNode($varTok->line, $varTok->value);
                $op = match ($opTok->type) {
                    TokenType::PlusAssign  => '+',
                    TokenType::MinusAssign => '-',
                    TokenType::ConcatAssign => '.',
                    default => '+',
                };
                return new VarDeclNode($varTok->line, $varTok->value,
                    new BinaryOpNode($varTok->line, $varRef, $op, $expr)
                );
            }

            // Otherwise: $b[2](), $b[0] + 1, etc. → expression statement
            // Don't consume the variable here; let parseExpression handle it
            $expr = $this->parseExpression();
            $this->lexer->expect(TokenType::Semicolon);
            return new ExprStmtNode($cur->line, $expr);
        }

        // print
        if ($cur->isType(TokenType::Print)) {
            return $this->parsePrint();
        }

        // echo (same as print but always without parens)
        if ($cur->isType(TokenType::Echo_)) {
            return $this->parseEcho();
        }

        // var_dump(...)
        if ($cur->isType(TokenType::VarDump)) {
            return $this->parseVarDump();
        }

        // return
        if ($cur->isType(TokenType::Return)) {
            return $this->parseReturn();
        }

        // if
        if ($cur->isType(TokenType::If)) {
            return $this->parseIf();
        }

        // while
        if ($cur->isType(TokenType::While)) {
            return $this->parseWhile();
        }

        // for
        if ($cur->isType(TokenType::For)) {
            return $this->parseFor();
        }

        // switch
        if ($cur->isType(TokenType::Switch_)) {
            return $this->parseSwitch();
        }

        // break
        if ($cur->isType(TokenType::Break_)) {
            return $this->parseBreak();
        }

        // unset($name[expr])
        if ($cur->isType(TokenType::Unset)) {
            return $this->parseUnset();
        }

        // expression statement (function calls, etc.)
        if ($cur->isType(TokenType::Identifier) || $cur->isType(TokenType::LParen)) {
            $expr = $this->parseExpression();
            $this->lexer->expect(TokenType::Semicolon);
            return new ExprStmtNode($cur->line, $expr);
        }

        throw new \RuntimeException(
            sprintf("Unexpected token %s at line %d", $cur->__toString(), $cur->line)
        );
    }

    private function parsePrint(): PrintStmtNode
    {
        $line = $this->lexer->current()->line;
        $this->lexer->next(); // consume print

        // print supports: print expr; or print(expr);
        // Check for parentheses
        $hasParen = $this->lexer->current()->isType(TokenType::LParen);
        if ($hasParen) {
            $this->lexer->next(); // consume (
        }

        $expr = $this->parseExpression();

        if ($hasParen) {
            $this->lexer->expect(TokenType::RParen);
        }

        $this->lexer->expect(TokenType::Semicolon);

        return new PrintStmtNode($line, [$expr], false);
    }

    /** echo expr; — identical to print expr; */
    private function parseEcho(): PrintStmtNode
    {
        $line = $this->lexer->current()->line;
        $this->lexer->next(); // consume 'echo'
        $expr = $this->parseExpression();
        $this->lexer->expect(TokenType::Semicolon);
        return new PrintStmtNode($line, [$expr], false);
    }

    private function parseVarDump(): PrintStmtNode
    {
        $line = $this->lexer->current()->line;
        $this->lexer->next(); // consume var_dump

        $this->lexer->expect(TokenType::LParen);
        $args = $this->parseArgList();
        $this->lexer->expect(TokenType::RParen);
        $this->lexer->expect(TokenType::Semicolon);

        return new PrintStmtNode($line, $args, true);
    }

    private function parseReturn(): ReturnStmtNode
    {
        $line = $this->lexer->current()->line;
        $this->lexer->next();

        $expr = null;
        if (!$this->lexer->current()->isType(TokenType::Semicolon)) {
            $expr = $this->parseExpression();
        }
        $this->lexer->expect(TokenType::Semicolon);

        return new ReturnStmtNode($line, $expr);
    }

    private function parseIf(): IfStmtNode
    {
        $line = $this->lexer->current()->line;
        $this->lexer->next();
        $this->lexer->expect(TokenType::LParen);
        $condition = $this->parseExpression();
        $this->lexer->expect(TokenType::RParen);
        $this->lexer->expect(TokenType::LBrace);
        $thenBody = $this->parseStatementList();
        $this->lexer->expect(TokenType::RBrace);

        $elseBody = [];
        if ($this->lexer->match(TokenType::Else)) {
            if ($this->lexer->current()->isType(TokenType::LBrace)) {
                $this->lexer->next();
                $elseBody = $this->parseStatementList();
                $this->lexer->expect(TokenType::RBrace);
            } elseif ($this->lexer->current()->isType(TokenType::If)) {
                $elseBody = [$this->parseIf()];
            }
        }

        return new IfStmtNode($line, $condition, $thenBody, $elseBody);
    }

    private function parseWhile(): WhileStmtNode
    {
        $line = $this->lexer->current()->line;
        $this->lexer->next();
        $this->lexer->expect(TokenType::LParen);
        $condition = $this->parseExpression();
        $this->lexer->expect(TokenType::RParen);
        $this->lexer->expect(TokenType::LBrace);
        $body = $this->parseStatementList();
        $this->lexer->expect(TokenType::RBrace);
        return new WhileStmtNode($line, $condition, $body);
    }

    /**
     * Parse foreach. Supports:
     *   foreach ($arr as $val) { body }
     *   foreach ($arr as $key => $val) { body }
     *
     * Desugaring (per user suggestion):
     *   for ($i = 0; $i < count($arr); $i++) { $key = $i; $val = $arr[$i]; body }
     */
    private function parseForeachStmts(): array
    {
        $line = $this->lexer->current()->line;
        $this->lexer->next(); // consume foreach
        $this->lexer->expect(TokenType::LParen);
        $arrayExpr = $this->parseExpression();
        $this->lexer->expect(TokenType::As);

        // Check for key => value syntax
        $keyVarTok = null;
        $firstTok = $this->lexer->expect(TokenType::Variable); // $val or $key
        if ($this->lexer->current()->isType(TokenType::Arrow)) {
            $keyVarTok = $firstTok;       // this was actually $key
            $this->lexer->next();          // consume =>
            $firstTok = $this->lexer->expect(TokenType::Variable); // $val
        }
        $valueVarTok = $firstTok;

        $this->lexer->expect(TokenType::RParen);
        $this->lexer->expect(TokenType::LBrace);
        $body = $this->parseStatementList();
        $this->lexer->expect(TokenType::RBrace);

        $this->foreachCounter++;
        $valueName = $valueVarTok->value;
        $this->foreachCounter++;
        $iterName = '$i' . $this->foreachCounter;

        // for ($i = 0; $i < count($arr); $i++) { ... }
        $init = new VarDeclNode($line, $iterName, new IntegerLiteralNode($line, 0));
        $cond = new BinaryOpNode($line, new VarRefNode($line, $iterName), '<',
            new FuncCallNode($line, 'count', [$arrayExpr]));
        $incr = new PostIncrementNode($line, $iterName); // isDecrement=false → increment

        // body statements
        $bodyStmts = [];
        if ($keyVarTok !== null) {
            $bodyStmts[] = new VarDeclNode($line, $keyVarTok->value,
                new VarRefNode($line, $iterName)); // $key = $i
        }
        $bodyStmts[] = new VarDeclNode($line, $valueName,
            new IndexAccessNode($line, $arrayExpr, new VarRefNode($line, $iterName))); // $val = $arr[$i]
        $bodyStmts = array_merge($bodyStmts, $body);

        $forStmt = new ForStmtNode($line, $init, $cond, $incr, $bodyStmts);

        return [$forStmt];
    }

    // ---- Expression Parsing (Pratt parser style) ----

    private function parseExpression(): ExprNode
    {
        return $this->parseConcat();
    }

    private function parseConcat(): ExprNode
    {
        $left = $this->parseLogicalOr();

        while ($this->lexer->current()->isType(TokenType::Concat)) {
            $opTok = $this->lexer->next();
            $right = $this->parseLogicalOr();
            $left = new BinaryOpNode($opTok->line, $left, '.', $right);
        }

        return $left;
    }

    private function parseLogicalOr(): ExprNode
    {
        $left = $this->parseLogicalAnd();

        while ($this->lexer->current()->isType(TokenType::Or_)) {
            $opTok = $this->lexer->next();
            $right = $this->parseLogicalAnd();
            $left = new BinaryOpNode($opTok->line, $left, '||', $right);
        }

        return $left;
    }

    private function parseLogicalAnd(): ExprNode
    {
        $left = $this->parseComparison();

        while ($this->lexer->current()->isType(TokenType::And_)) {
            $opTok = $this->lexer->next();
            $right = $this->parseComparison();
            $left = new BinaryOpNode($opTok->line, $left, '&&', $right);
        }

        return $left;
    }

    private function parseComparison(): ExprNode
    {
        $left = $this->parseTerm();

        $cmpOps = [TokenType::Eq, TokenType::StrictEq, TokenType::Neq, TokenType::StrictNeq, TokenType::Lt, TokenType::Gt, TokenType::Lte, TokenType::Gte];
        while (in_array($this->lexer->current()->type, $cmpOps, true)) {
            $opTok = $this->lexer->next();
            $right = $this->parseTerm();
            $left = new BinaryOpNode($opTok->line, $left, $opTok->value, $right);
        }

        return $left;
    }

    private function parseTerm(): ExprNode
    {
        $left = $this->parseFactor();

        while ($this->lexer->current()->isType(TokenType::Plus) || $this->lexer->current()->isType(TokenType::Minus)) {
            $opTok = $this->lexer->next();
            $right = $this->parseFactor();
            $left = new BinaryOpNode($opTok->line, $left, $opTok->value, $right);
        }

        return $left;
    }

    private function parseFactor(): ExprNode
    {
        $left = $this->parseUnary();

        while (
            $this->lexer->current()->isType(TokenType::Star) ||
            $this->lexer->current()->isType(TokenType::Slash) ||
            $this->lexer->current()->isType(TokenType::Percent)
        ) {
            $opTok = $this->lexer->next();
            $right = $this->parseUnary();
            $left = new BinaryOpNode($opTok->line, $left, $opTok->value, $right);
        }

        return $left;
    }

    private function parseUnary(): ExprNode
    {
        // Type cast: (type)expr
        if ($this->lexer->current()->isType(TokenType::LParen)) {
            $peek1 = $this->lexer->peek();
            if ($this->isTypeKeyword($peek1) && $this->lexer->peekAt(2)->isType(TokenType::RParen)) {
                $line = $this->lexer->current()->line;
                $this->lexer->next(); // consume (
                $castType = $this->parseType();
                $this->lexer->expect(TokenType::RParen);
                $operand = $this->parseUnary(); // may chain (int)(float)$x
                return new CastExprNode($line, $castType, $operand);
            }
        }

        // logical not: !expr
        if ($this->lexer->match(TokenType::Not_)) {
            $operand = $this->parseUnary();
            return new BinaryOpNode($operand->line,
                $operand,
                '!',
                new IntegerLiteralNode($operand->line, 0)
            );
        }

        // negative numbers
        if ($this->lexer->match(TokenType::Minus)) {
            $operand = $this->parsePrimary();
            if ($operand instanceof IntegerLiteralNode) {
                return new IntegerLiteralNode($operand->line, -$operand->value);
            }
            if ($operand instanceof FloatLiteralNode) {
                return new FloatLiteralNode($operand->line, -$operand->value);
            }
            return new BinaryOpNode($operand->line,
                new IntegerLiteralNode($operand->line, 0),
                '-',
                $operand
            );
        }

        return $this->parsePrimary();
    }

    /** Check if a token is a type keyword (int, float, string, bool, void). */
    private function isTypeKeyword(Token $tok): bool
    {
        return $tok->isType(TokenType::Int) ||
               $tok->isType(TokenType::Float) ||
               $tok->isType(TokenType::String_) ||
               $tok->isType(TokenType::Bool_) ||
               $tok->isType(TokenType::Void_);
    }

    private function parsePrimary(): ExprNode
    {
        $cur = $this->lexer->current();

        // integer literal
        if ($cur->isType(TokenType::IntegerLiteral)) {
            $this->lexer->next();
            return new IntegerLiteralNode($cur->line, (int)$cur->value);
        }

        // float literal
        if ($cur->isType(TokenType::FloatLiteral)) {
            $this->lexer->next();
            return new FloatLiteralNode($cur->line, (float)$cur->value);
        }

        // string literal
        if ($cur->isType(TokenType::StringLiteral)) {
            $this->lexer->next();
            return new StringLiteralNode($cur->line, $cur->value);
        }

        // true/false
        if ($cur->isType(TokenType::True_) || $cur->isType(TokenType::False_)) {
            $this->lexer->next();
            return new BoolLiteralNode($cur->line, $cur->value === 'true');
        }

        // null
        if ($cur->isType(TokenType::Null_)) {
            $this->lexer->next();
            return new NullLiteralNode($cur->line);
        }

        // new ClassName()
        if ($cur->isType(TokenType::New)) {
            $line = $cur->line;
            $this->lexer->next(); // consume 'new'
            $className = $this->parseQualifiedName();
            $this->lexer->expect(TokenType::LParen);
            $this->lexer->expect(TokenType::RParen);
            return new NewExprNode($line, $className);
        }

        // variable
        if ($cur->isType(TokenType::Variable)) {
            $varTok = $this->lexer->next();

            // $this → special expression
            if ($varTok->value === '$this') {
                $node = new ThisExprNode($varTok->line);
            } else {
                $node = new VarRefNode($varTok->line, $varTok->value);
            }

            // check for index access: $c[0] or string range: $c[11][15]
            if ($this->lexer->current()->isType(TokenType::LBracket)) {
                $this->lexer->next();
                $first = $this->parseExpression();
                $this->lexer->expect(TokenType::RBracket);

                // Check if there's a second bracket → string range access
                if ($this->lexer->current()->isType(TokenType::LBracket)) {
                    $this->lexer->next();
                    $second = $this->parseExpression();
                    $this->lexer->expect(TokenType::RBracket);
                    $node = new StringRangeNode($varTok->line, $node, $first, $second);
                } else {
                    $node = new IndexAccessNode($varTok->line, $node, $first);
                }
            }

            // check for post-increment: $i++ or $i--
            if ($this->lexer->current()->isType(TokenType::Increment) ||
                $this->lexer->current()->isType(TokenType::Decrement)) {
                $isDec = $this->lexer->current()->isType(TokenType::Decrement);
                $this->lexer->next();
                if ($node instanceof VarRefNode) {
                    return new PostIncrementNode($varTok->line, $varTok->value, $isDec);
                }
                // For index-accessed variables (like $arr[0]++), return the node as-is
                return $node;
            }

            // check for closure invocation: $g(1, 2) or $b[0](1, 2)
            if ($this->lexer->current()->isType(TokenType::LParen)) {
                $this->lexer->next();
                $args = $this->parseArgList();
                $this->lexer->expect(TokenType::RParen);
                // $b[0](1, 2) — call the result of an index expression
                if ($node instanceof IndexAccessNode || $node instanceof StringRangeNode) {
                    return new ExprCallNode($varTok->line, $node, $args);
                }
                // $g(1, 2) — call a variable directly
                return new ExprCallNode($varTok->line, $node, $args);
            }

            // check for method call: $obj->method() or $this->method()
            if ($this->lexer->current()->isType(TokenType::ObjectArrow)) {
                $this->lexer->next(); // consume ->
                $methodNameTok = $this->lexer->expect(TokenType::Identifier);

                if ($this->lexer->current()->isType(TokenType::LParen)) {
                    // method call
                    $this->lexer->next();
                    $args = $this->parseArgList();
                    $this->lexer->expect(TokenType::RParen);
                    return new MethodCallNode($varTok->line, $node, $methodNameTok->value, $args);
                }
                // property access ($obj->prop, $i->value) — return the variable itself (typedef)
                return $node;
            }

            return $node;
        }

        // array literal: array("int", [1, 2, 3])
        if ($cur->isType(TokenType::Array)) {
            return $this->parseArrayLiteral();
        }

        // array shorthand: [elem1, elem2, ...]  or  []
        if ($cur->isType(TokenType::LBracket)) {
            return $this->parseArrayShorthand();
        }

        // count() builtin
        if ($cur->isType(TokenType::Count)) {
            $this->lexer->next();
            $this->lexer->expect(TokenType::LParen);
            $args = $this->parseArgList();
            $this->lexer->expect(TokenType::RParen);
            return new FuncCallNode($cur->line, 'count', $args);
        }

        // function call, C->ffi call, enum access, or constant reference
        if ($cur->isType(TokenType::Identifier)) {
            $nameTok = $this->lexer->next();

            // C->funcName(args)
            if ($nameTok->value === 'C' && $this->lexer->current()->isType(TokenType::ObjectArrow)) {
                $this->lexer->next();
                $fnName = $this->lexer->expect(TokenType::Identifier);
                $this->lexer->expect(TokenType::LParen);
                $args = $this->parseArgList();
                $this->lexer->expect(TokenType::RParen);
                return new CFuncCallNode($nameTok->line, $fnName->value, $args);
            }

            // EnumName::Case — enum case access
            if ($this->lexer->current()->isType(TokenType::DoubleColon)) {
                $this->lexer->next(); // consume ::
                $caseName = $this->lexer->expect(TokenType::Identifier)->value;
                $node = new EnumAccessNode($nameTok->line, $nameTok->value, $caseName);

                // Check for ->value chain
                if ($this->lexer->current()->isType(TokenType::ObjectArrow)) {
                    $this->lexer->next();
                    $prop = $this->lexer->expect(TokenType::Identifier);
                    if ($prop->value === 'value') {
                        return $node; // ->value is a no-op on enum
                    }
                    throw new \RuntimeException("Enum only supports ->value, got ->{$prop->value}");
                }
                return $node;
            }

            if ($this->lexer->current()->isType(TokenType::LParen)) {
                // function call
                $this->lexer->next();
                $args = $this->parseArgList();
                $this->lexer->expect(TokenType::RParen);
                return new FuncCallNode($nameTok->line, $nameTok->value, $args);
            }
            // Standalone identifier → const reference
            return new ConstRefNode($nameTok->line, $nameTok->value);
        }

        // closure: function (params): returnType { body }
        if ($cur->isType(TokenType::Function)) {
            return $this->parseClosure();
        }

        // parenthesized expression
        if ($cur->isType(TokenType::LParen)) {
            $this->lexer->next();
            $expr = $this->parseExpression();
            $this->lexer->expect(TokenType::RParen);
            return $expr;
        }

        throw new \RuntimeException(
            sprintf("Unexpected token %s in expression at line %d", $cur->__toString(), $cur->line)
        );
    }

    /** @return ExprNode[] */
    private function parseArgList(): array
    {
        $args = [];
        if ($this->lexer->current()->isType(TokenType::RParen)) {
            return $args;
        }
        do {
            $args[] = $this->parseExpression();
        } while ($this->lexer->match(TokenType::Comma));
        return $args;
    }

    private function parseArrayLiteral(): ArrayLiteralNode
    {
        $line = $this->lexer->current()->line;
        $this->lexer->next(); // consume 'array'
        $this->lexer->expect(TokenType::LParen);

        $typeTok = $this->lexer->expect(TokenType::StringLiteral);
        $elementType = $this->parseTypeFromString($typeTok->value);

        $this->lexer->expect(TokenType::Comma);
        $this->lexer->expect(TokenType::LBracket);

        $elements = [];
        if (!$this->lexer->current()->isType(TokenType::RBracket)) {
            do {
                $elements[] = $this->parseExpression();
            } while ($this->lexer->match(TokenType::Comma));
        }

        $this->lexer->expect(TokenType::RBracket);
        $this->lexer->expect(TokenType::RParen);

        return new ArrayLiteralNode($line, $elementType, $elements);
    }

    /**
     * Parse array shorthand: [elem1, elem2, ...]  or  []
     * Element type is inferred from the literal elements.
     * Equivalent to array("type", [...])
     */
    private function parseArrayShorthand(): ArrayLiteralNode
    {
        $line = $this->lexer->current()->line;
        $this->lexer->next(); // consume [

        $elements = [];
        if (!$this->lexer->current()->isType(TokenType::RBracket)) {
            do {
                $elements[] = $this->parseExpression();
            } while ($this->lexer->match(TokenType::Comma));
        }

        $this->lexer->expect(TokenType::RBracket);

        $elementType = $this->inferElementType($elements);

        return new ArrayLiteralNode($line, $elementType, $elements);
    }

    /**
     * Infer the array element type from the literal elements.
     * Empty arrays default to int. Non-literal elements (variables, expressions)
     * cannot be typed at parse time, default to int.
     */
    private function inferElementType(array $elements): TphpType
    {
        if (empty($elements)) {
            return TphpType::Int;
        }

        $types = [];
        foreach ($elements as $e) {
            if ($e instanceof IntegerLiteralNode) {
                $types[] = 'int';
            } elseif ($e instanceof FloatLiteralNode) {
                $types[] = 'float';
            } elseif ($e instanceof StringLiteralNode) {
                $types[] = 'string';
            } elseif ($e instanceof BoolLiteralNode) {
                $types[] = 'bool';
            } elseif ($e instanceof ClosureNode) {
                $types[] = 'callback';
            } else {
                // Variables, expressions, etc. — cannot type at parse time, default to int
                return TphpType::Int;
            }
        }

        $uniqueTypes = array_unique($types);
        if (count($uniqueTypes) === 1) {
            return $this->parseTypeFromString($uniqueTypes[0]);
        }

        throw new \RuntimeException(
            'Array shorthand requires uniform element types. ' .
            'Mixed types not supported in tphp. Use array("type", [...]) syntax instead.'
        );
    }

    private function parseUnset(): UnsetStmtNode
    {
        $line = $this->lexer->current()->line;
        $this->lexer->next(); // consume 'unset'
        $this->lexer->expect(TokenType::LParen);
        $nameTok = $this->lexer->expect(TokenType::Variable);
        $this->lexer->expect(TokenType::LBracket);
        $index = $this->parseExpression();
        $this->lexer->expect(TokenType::RBracket);
        $this->lexer->expect(TokenType::RParen);
        $this->lexer->expect(TokenType::Semicolon);
        return new UnsetStmtNode($line, $nameTok->value, $index);
    }

    private function parseTypeFromString(string $typeStr): TphpType
    {
        return match ($typeStr) {
            'int'      => TphpType::Int,
            'float'    => TphpType::Float,
            'string'   => TphpType::String,
            'bool'     => TphpType::Bool,
            'null'     => TphpType::Null,
            'callback' => TphpType::Callable_,
            default    => throw new \RuntimeException(
                sprintf("Unknown array element type '%s'", $typeStr)
            ),
        };
    }

    private function parseClosure(): ClosureNode
    {
        $line = $this->lexer->current()->line;
        $this->lexer->next(); // 'function' already consumed
        $this->lexer->expect(TokenType::LParen);
        $params = $this->parseParamList();
        $this->lexer->expect(TokenType::RParen);

        $returnType = TphpType::Void;
        if ($this->lexer->match(TokenType::Colon)) {
            $returnType = $this->parseType();
        }

        $this->lexer->expect(TokenType::LBrace);
        $body = $this->parseStatementList();
        $this->lexer->expect(TokenType::RBrace);

        return new ClosureNode($line, $params, $returnType, $body);
    }

    // ==================== For loop ====================

    private function parseFor(): ForStmtNode
    {
        $line = $this->lexer->current()->line;
        $this->lexer->next(); // consume 'for'
        $this->lexer->expect(TokenType::LParen);

        // Init: variable declaration ($i = 0) or empty
        $init = null;
        if (!$this->lexer->current()->isType(TokenType::Semicolon)) {
            if ($this->lexer->current()->isType(TokenType::Variable) &&
                $this->lexer->peek()->isType(TokenType::Assign)) {
                // $x = expr → VarDeclNode
                $varTok = $this->lexer->next();
                $this->lexer->expect(TokenType::Assign);
                $initExpr = $this->parseExpression();
                $init = new VarDeclNode($varTok->line, $varTok->value, $initExpr);
            } else {
                // expression statement
                $initExpr = $this->parseExpression();
                $init = new ExprStmtNode($line, $initExpr);
            }
        }
        $this->lexer->expect(TokenType::Semicolon);

        // Condition
        $condition = null;
        if (!$this->lexer->current()->isType(TokenType::Semicolon)) {
            $condition = $this->parseExpression();
        }
        $this->lexer->expect(TokenType::Semicolon);

        // Increment
        $increment = null;
        if (!$this->lexer->current()->isType(TokenType::RParen)) {
            $increment = $this->parseExpression();
        }
        $this->lexer->expect(TokenType::RParen);

        // Body
        $this->lexer->expect(TokenType::LBrace);
        $body = $this->parseStatementList();
        $this->lexer->expect(TokenType::RBrace);

        return new ForStmtNode($line, $init, $condition, $increment, $body);
    }

    // ==================== Switch ====================

    private function parseSwitch(): SwitchStmtNode
    {
        $line = $this->lexer->current()->line;
        $this->lexer->next(); // consume 'switch'
        $this->lexer->expect(TokenType::LParen);
        $subject = $this->parseExpression();
        $this->lexer->expect(TokenType::RParen);
        $this->lexer->expect(TokenType::LBrace);

        $cases = [];
        $defaultBody = [];

        while (!$this->lexer->isEof() && !$this->lexer->current()->isType(TokenType::RBrace)) {
            $cur = $this->lexer->current();

            if ($cur->isType(TokenType::Case_)) {
                $caseLine = $cur->line;
                $this->lexer->next(); // consume 'case'
                $caseValue = $this->parseExpression();
                $this->lexer->expect(TokenType::Colon);
                $caseBody = $this->parseSwitchCaseBody();
                $cases[] = new SwitchCaseNode($caseLine, $caseValue, $caseBody);
            } elseif ($cur->isType(TokenType::Default_)) {
                $this->lexer->next(); // consume 'default'
                $this->lexer->expect(TokenType::Colon);
                $defaultBody = $this->parseSwitchCaseBody();
            } else {
                throw new \RuntimeException(
                    sprintf("Expected 'case' or 'default' at line %d, got %s", $cur->line, $cur->__toString())
                );
            }
        }

        $this->lexer->expect(TokenType::RBrace);

        return new SwitchStmtNode($line, $subject, $cases, $defaultBody);
    }

    /**
     * Parse statements inside a switch case body.
     * Stops at 'case', 'default', or '}'.
     * @return StmtNode[]
     */
    private function parseSwitchCaseBody(): array
    {
        $stmts = [];
        while (!$this->lexer->isEof()) {
            $cur = $this->lexer->current();
            if ($cur->isType(TokenType::Case_) ||
                $cur->isType(TokenType::Default_) ||
                $cur->isType(TokenType::RBrace)) {
                break;
            }
            $stmt = $this->parseStatement();
            if ($stmt !== null) {
                $stmts[] = $stmt;
            }
        }
        return $stmts;
    }

    // ==================== Break ====================

    private function parseBreak(): BreakStmtNode
    {
        $line = $this->lexer->current()->line;
        $this->lexer->next(); // consume 'break'
        $this->lexer->expect(TokenType::Semicolon);
        return new BreakStmtNode($line);
    }
}
