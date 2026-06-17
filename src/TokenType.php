<?php

declare(strict_types=1);

enum TokenType: string
{
    // 特殊
    case PHP_OPEN   = '<?php';
    case EOF        = 'EOF';

    // 关键字
    case CLASS_KW   = 'class';
    case PUBLIC_KW  = 'public';
    case PRIVATE_KW = 'private';
    case FUNCTION   = 'function';
    case RETURN_KW  = 'return';
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

    // 魔术方法
    case CONSTRUCT = '__construct';
    case DESTRUCT  = '__destruct';

    // 字面量
    case IDENTIFIER = 'IDENTIFIER';
    case INT_LIT    = 'INT_LIT';
    case FLOAT_LIT  = 'FLOAT_LIT';
    case STRING_LIT = 'STRING_LIT';

    // 关键字 (继续)
    case VAR_DUMP   = 'var_dump';
    case NAMESPACE  = 'namespace';
    case USE        = 'use';
    case AS_KW      = 'as';

    // 符号
    case PLUS         = '+';
    case MINUS        = '-';
    case STAR         = '*';
    case SLASH        = '/';
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
    case DOUBLE_COLON = '::';
    case NS_SEP       = '\\';
}
