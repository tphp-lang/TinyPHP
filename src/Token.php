<?php

declare(strict_types=1);

namespace Tphp;

enum TokenType: string
{
    case Eof            = 'EOF';
    case Extrn           = '#extern';
    case OpenTag        = '<?php';
    case Namespace      = 'namespace';
    case Function       = 'function';
    case Return         = 'return';
    case Print          = 'print';
    case Echo_           = 'echo';
    case VarDump        = 'var_dump';
    case Count          = 'count';
    case Array          = 'array';
    case Unset          = 'unset';
    case If             = 'if';
    case Else           = 'else';
    case While          = 'while';
    case For            = 'for';
    case Foreach        = 'foreach';
    case Switch_        = 'switch';
    case Case_          = 'case';
    case Default_       = 'default';
    case Break_         = 'break';
    case As             = 'as';
    case Use            = 'use';
    case Class_         = 'class';
    case Enum           = 'enum';
    case Public         = 'public';
    case Private        = 'private';
    case New            = 'new';
    case Var            = 'var';
    case Const          = 'const';

    case Int            = 'int';
    case Float          = 'float';
    case String_        = 'string';
    case Bool_          = 'bool';
    case Void_          = 'void';
    case Null_          = 'null';
    case True_          = 'true';
    case False_         = 'false';

    case Identifier     = 'identifier';
    case Variable       = 'variable';
    case IntegerLiteral = 'integer_literal';
    case FloatLiteral   = 'float_literal';
    case StringLiteral  = 'string_literal';

    case Assign         = '=';
    case PlusAssign     = '+=';
    case MinusAssign    = '-=';
    case ConcatAssign   = '.=';
    case Concat         = '.';
    case Comma          = ',';
    case Semicolon      = ';';
    case Colon          = ':';
    case DoubleColon    = '::';
    case Arrow          = '=>';

    case LParen         = '(';
    case RParen         = ')';
    case LBrace         = '{';
    case RBrace         = '}';
    case LBracket       = '[';
    case RBracket       = ']';

    case Plus           = '+';
    case Minus          = '-';
    case Star           = '*';
    case Slash          = '/';
    case Percent        = '%';
    case Eq             = '==';
    case StrictEq       = '===';
    case Neq            = '!=';
    case StrictNeq      = '!==';
    case Increment      = '++';
    case Decrement      = '--';
    case Lt             = '<';
    case Gt             = '>';
    case Lte            = '<=';
    case Gte            = '>=';

    case DollarString   = 'dollar_string';

    // Logical operators
    case And_           = '&&';
    case Or_            = '||';
    case Not_           = '!';

    case Backslash      = '\\';
    case ObjectArrow    = '->';
}

final readonly class Token
{
    public function __construct(
        public TokenType $type,
        public string $value,
        public int $line,
        public int $column,
    ) {}

    public function isType(TokenType $type): bool
    {
        return $this->type === $type;
    }

    public function __toString(): string
    {
        return sprintf('%s("%s")', $this->type->value, $this->value);
    }
}
