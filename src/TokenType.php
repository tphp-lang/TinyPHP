<?php

declare(strict_types=1);

enum TokenType: string
{
    // 特殊
    case PHP_OPEN   = '<?php';
    case EOF        = 'EOF';

    // 关键字
    case CLASS_KW   = 'class';
    case ENUM_KW    = 'enum';
    case PUBLIC_KW  = 'public';
    case PRIVATE_KW = 'private';
    case FUNCTION   = 'function';
    case RETURN_KW  = 'return';
    case YIELD_KW   = 'yield';
    case ECHO_KW    = 'echo';
    case NEW_KW     = 'new';
    case NULL_KW    = 'null';
    case TRUE_KW    = 'true';
    case FALSE_KW   = 'false';

    // 类型关键字
    case TYPE_INT    = 'int';
    case TYPE_FLOAT  = 'float';
    case TYPE_STRING = 'string';
    case TYPE_BOOL   = 'bool';
    case TYPE_VOID   = 'void';
    case TYPE_ARRAY  = 'array';
    case TYPE_MIXED  = 'mixed';
    case TYPE_NEVER  = 'never';
    case FINAL_KW    = 'final';
    case READONLY_KW = 'readonly';
    case STATIC_KW   = 'static';
    case FN_KW       = 'fn';
    case MAGIC_LINE  = '__LINE__';
    case MAGIC_FILE  = '__FILE__';
    case MAGIC_DIR   = '__DIR__';
    case DIR_SEP     = 'DIRECTORY_SEPARATOR';
    case HASH_INCLUDE  = '#include';
    case HASH_IMPORT   = '#import';
    case CC_FLAG       = '#flag';
    case HASH_CALLBACK = '#callback';
    case HASH_CSTRUCT  = '#cstruct';
    case HASH_DEBUG    = '#debug';
    case HASH_ATTRIBUTE = '#[';  // PHP 8 attribute syntax #[...]
    case TRY_KW       = 'try';
    case CATCH_KW     = 'catch';
    case FINALLY_KW   = 'finally';
    case THROW_KW     = 'throw';
    case ABSTRACT_KW  = 'abstract';
    case EXTENDS_KW   = 'extends';
    case IMPLEMENTS_KW = 'implements';
    case INTERFACE_KW  = 'interface';
    case TRAIT_KW      = 'trait';
    case INSTANCEOF_KW  = 'instanceof';
    case PARENT_KW      = 'parent';
    case MAGIC_CLASS    = '__CLASS__';
    case MAGIC_METHOD   = '__METHOD__';
    case MAGIC_FUNCTION = '__FUNCTION__';
    case MAGIC_NAMESPACE = '__NAMESPACE__';

    // 魔术方法
    case CONSTRUCT = '__construct';
    case DESTRUCT  = '__destruct';

    // 字面量
    case IDENTIFIER = 'IDENTIFIER';
    case INT_LIT    = 'INT_LIT';
    case FLOAT_LIT  = 'FLOAT_LIT';
    case STRING_LIT = 'STRING_LIT';

    // 控制流关键字
    case IF_KW      = 'if';
    case ELSE_KW    = 'else';
    case ELSEIF_KW  = 'elseif';
    case DO_KW      = 'do';
    case SWITCH_KW  = 'switch';
    case CASE_KW    = 'case';
    case DEFAULT_KW = 'default';
    case FOR_KW     = 'for';
    case WHILE_KW   = 'while';
    case FOREACH_KW = 'foreach';
    case BREAK_KW   = 'break';
    case CONTINUE_KW= 'continue';
    case GOTO       = 'goto';
    case MATCH      = 'match';

    // 关键字 (继续)
    case VAR_DUMP   = 'var_dump';
    case COUNT      = 'count';
    case EXIT       = 'exit';
    case DIE        = 'die';
    case ISSET      = 'isset';
    case EMPTY_KW   = 'empty';
    case UNSET      = 'unset';
    case IS_INT     = 'is_int';
    case IS_FLOAT   = 'is_float';
    case IS_STRING  = 'is_string';
    case IS_BOOL    = 'is_bool';
    case IS_ARRAY   = 'is_array';
    case IS_OBJECT  = 'is_object';
    case IS_NULL    = 'is_null';
    case IS_CALLABLE= 'is_callable';
    case LIST_KW    = 'list';
    case TIME       = 'time';
    case DATE       = 'date';
    case SLEEP      = 'sleep';
    case USLEEP     = 'usleep';
    case HRTIME     = 'hrtime';
    case ERROR      = 'error';
    case NAMESPACE  = 'namespace';
    case USE        = 'use';
    case AS_KW      = 'as';
    case CONST_KW   = 'const';
    case SELF_KW    = 'self';

    // 符号
    case PLUS         = '+';
    case MINUS        = '-';
    case STAR         = '*';
    case SLASH        = '/';
    case MOD          = '%';
    case BANG         = '!';
    case LT           = '<';
    case GT           = '>';
    case LE           = '<=';
    case GE           = '>=';
    case EQ           = '==';
    case NE           = '!=';
    case IDENTICAL    = '===';
    case NOT_IDENTICAL = '!==';
    case AND_AND      = '&&';
    case OR_OR        = '||';
    case AMP          = '&';
    case PIPE         = '|';
    case PIPE_GT      = '|>';
    case CARET        = '^';
    case TILDE        = '~';
    case LT_LT        = '<<';
    case GT_GT        = '>>';
    case QUEST        = '?';
    case QUEST_QUEST  = '??';
    case DOLLAR       = '$';
    case LPAREN       = '(';
    case RPAREN       = ')';
    case LBRACE       = '{';
    case RBRACE       = '}';
    case LBRACKET     = '[';
    case RBRACKET     = ']';
    case COLON        = ':';
    case SEMICOLON    = ';';
    case COMMA        = ',';
    case EQUALS       = '=';
    case ARROW        = '->';
    case NULLSAFE_ARROW = '?->';
    case DOUBLE_ARROW = '=>';
    case DOUBLE_COLON = '::';
    case NS_SEP       = '\\';
    case INC          = '++';
    case DEC          = '--';
    case PLUS_EQ      = '+=';
    case MINUS_EQ     = '-=';
    case STAR_EQ      = '*=';
    case SLASH_EQ     = '/=';
    case DOT_EQ       = '.=';
    case STAR_STAR    = '**';
    case SPACESHIP    = '<=>';
    case DOT          = '.';
}
