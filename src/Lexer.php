<?php

declare(strict_types=1);

class Lexer
{
    private string $source;
    private int $pos = 0;
    private int $line = 1;
    private int $column = 1;

    /** @var Token[] */
    private array $tokens = [];
    private bool $debugMode;

    private static array $keywords = [
        'class'       => TokenType::CLASS_KW,
        'enum'        => TokenType::ENUM_KW,
        'public'      => TokenType::PUBLIC_KW,
        'private'     => TokenType::PRIVATE_KW,
        'final'       => TokenType::FINAL_KW,
        'readonly'    => TokenType::READONLY_KW,
        'static'      => TokenType::STATIC_KW,
        'fn'          => TokenType::FN_KW,
        '__LINE__'    => TokenType::MAGIC_LINE,
        '__FILE__'    => TokenType::MAGIC_FILE,
        '__DIR__'     => TokenType::MAGIC_DIR,
        'DIRECTORY_SEPARATOR' => TokenType::DIR_SEP,
        'function'    => TokenType::FUNCTION,
        'return'      => TokenType::RETURN_KW,
        'echo'        => TokenType::ECHO_KW,
        'new'         => TokenType::NEW_KW,
        'null'        => TokenType::NULL_KW,
        'true'        => TokenType::TRUE_KW,
        'false'       => TokenType::FALSE_KW,
        'int'         => TokenType::TYPE_INT,
        'float'       => TokenType::TYPE_FLOAT,
        'string'      => TokenType::TYPE_STRING,
        'bool'        => TokenType::TYPE_BOOL,
        'void'        => TokenType::TYPE_VOID,
        'never'       => TokenType::TYPE_NEVER,
        'try'         => TokenType::TRY_KW,
        'catch'       => TokenType::CATCH_KW,
        'finally'     => TokenType::FINALLY_KW,
        'throw'       => TokenType::THROW_KW,
        'abstract'    => TokenType::ABSTRACT_KW,
        'extends'     => TokenType::EXTENDS_KW,
        'implements'  => TokenType::IMPLEMENTS_KW,
        'interface'   => TokenType::INTERFACE_KW,
        'trait'       => TokenType::TRAIT_KW,
        'instanceof'  => TokenType::INSTANCEOF_KW,
        'parent'      => TokenType::PARENT_KW,
        '__CLASS__'   => TokenType::MAGIC_CLASS,
        '__METHOD__'  => TokenType::MAGIC_METHOD,
        'array'       => TokenType::TYPE_ARRAY,
        'mixed'       => TokenType::TYPE_MIXED,
        '__construct' => TokenType::CONSTRUCT,
        '__destruct'  => TokenType::DESTRUCT,
        'var_dump'    => TokenType::VAR_DUMP,
        'count'       => TokenType::COUNT,
        'exit'        => TokenType::EXIT,
        'die'         => TokenType::DIE,
        'isset'       => TokenType::ISSET,
        'empty'       => TokenType::EMPTY_KW,
        'unset'       => TokenType::UNSET,
        'is_int'      => TokenType::IS_INT,
        'is_float'    => TokenType::IS_FLOAT,
        'is_string'   => TokenType::IS_STRING,
        'is_bool'     => TokenType::IS_BOOL,
        'is_array'    => TokenType::IS_ARRAY,
        'is_object'   => TokenType::IS_OBJECT,
        'is_null'     => TokenType::IS_NULL,
        'is_callable' => TokenType::IS_CALLABLE,
        'error'       => TokenType::ERROR,
        'time'        => TokenType::TIME,
        'date'        => TokenType::DATE,
        'sleep'       => TokenType::SLEEP,
        'usleep'      => TokenType::USLEEP,
        'hrtime'      => TokenType::HRTIME,
        'list'        => TokenType::LIST_KW,
        'namespace'   => TokenType::NAMESPACE,
        'use'         => TokenType::USE,
        'as'          => TokenType::AS_KW,
        'const'       => TokenType::CONST_KW,
        'self'        => TokenType::SELF_KW,
        'if'          => TokenType::IF_KW,
        'else'        => TokenType::ELSE_KW,
        'elseif'      => TokenType::ELSEIF_KW,
        'do'          => TokenType::DO_KW,
        'switch'      => TokenType::SWITCH_KW,
        'case'        => TokenType::CASE_KW,
        'default'     => TokenType::DEFAULT_KW,
        'for'         => TokenType::FOR_KW,
        'while'       => TokenType::WHILE_KW,
        'foreach'     => TokenType::FOREACH_KW,
        'yield'       => TokenType::YIELD_KW,
        'break'       => TokenType::BREAK_KW,
        'continue'    => TokenType::CONTINUE_KW,
        'goto'        => TokenType::GOTO,
        'match'       => TokenType::MATCH,
    ];

    public function __construct(string $source, bool $debugMode = false)
    {
        $this->source = $source;
        $this->debugMode = $debugMode;
    }

    /** @return Token[] */
    public function tokenize(): array
    {
        $this->pos = 0;
        $this->line = 1;
        $this->column = 1;
        $this->tokens = [];

        // <?php 可选（开头有则跳过，没有也正常解析）
        if (str_starts_with($this->source, '<?php')) {
            $this->pos = 5; // skip <?php
            $this->column = 6;
        }
        $this->addToken(TokenType::PHP_OPEN, '<?php');

        while ($this->pos < strlen($this->source)) {
            $this->scanToken();
        }

        $this->addToken(TokenType::EOF, '');
        return $this->tokens;
    }

    private function scanToken(): void
    {
        $ch = $this->peek();

        // 空白 / 换行（CRLF → 一次 line++，\r 单独视为换行）
        if ($ch === ' ' || $ch === "\t") {
            $this->advance();
            return;
        }
        if ($ch === "\r") {
            if ($this->peek(1) === "\n") $this->advance(); // skip \n of CRLF
            $this->line++;
            $this->column = 1;
            $this->pos++;
            return;
        }
        if ($ch === "\n") {
            $this->line++;
            $this->column = 1;
            $this->pos++;
            return;
        }

        // 注释 / 除号 / 复合赋值
        if ($ch === '/') {
            if ($this->peek(1) === '/') {
                $this->skipLineComment();
                return;
            }
            if ($this->peek(1) === '*') {
                $this->skipBlockComment();
                return;
            }
            if ($this->peek(1) === '=') {
                $this->addToken(TokenType::SLASH_EQ, '/=');
                $this->advance(2);
                return;
            }
            $this->addToken(TokenType::SLASH, '/');
            $this->advance();
            return;
        }

        // #include / #flag → 特殊预处理器指令
        if ($ch === '#') {
            $rest = substr($this->source, $this->pos);
            // #include [OS/CC] "path" or <path> — optional platform/compiler filter
            if (preg_match('/^#include\s+(?:(\w+)\s+)?(")(.+?)\2/', $rest, $m)) {
                $ctx = $m[1] ?: '';
                $file = $m[3];
                $quoted = ($m[2] === '"');
                $this->addToken(TokenType::HASH_INCLUDE, $file, [
                    'file' => $file, 'quoted' => $quoted, 'ctx' => $ctx,
                ]);
                $this->advance(strlen($m[0]));
                return;
            }
            if (preg_match('/^#include\s+(?:(\w+)\s+)?<(.+?)>/', $rest, $m)) {
                $ctx = $m[1] ?: '';
                $file = $m[2];
                $this->addToken(TokenType::HASH_INCLUDE, $file, [
                    'file' => $file, 'quoted' => false, 'ctx' => $ctx,
                ]);
                $this->advance(strlen($m[0]));
                return;
            }
            // #import name — 引入 ext/name/ 扩展（自动加载 ext/name/src/*.php + *.c）
            if (preg_match('/^#import\s+(\w[\w\-]*)/', $rest, $m)) {
                $this->addToken(TokenType::HASH_IMPORT, $m[1]);
                $this->advance(strlen($m[0]));
                return;
            }
            // #flag [GCC|Clang|TCC] [Windows|Linux|MacOS] [-D...] [-l...]
            // 最多两个前缀：编译器 + 平台，顺序不限
            // 注意：无前缀时 \s* 而非 \s+ — 允许 #flag -DNDEBUG 形式
            if (preg_match('/^#flag\s+(GCC|Clang|TCC|Windows|Linux|MacOS|Darwin)?\s*(GCC|Clang|TCC|Windows|Linux|MacOS|Darwin)?\s*(.+?)(?=\n|$)/', $rest, $m)) {
                $pf1 = $m[1] ?: '';
                $pf2 = $m[2] ?: '';
                $flags = trim($m[3]);
                $platform = '';  $compiler = '';
                $allPf = array_filter([$pf1, $pf2]);
                foreach ($allPf as $p) {
                    if (in_array($p, ['Windows','Linux','MacOS','Darwin'], true)) $platform = $p;
                    else $compiler = $p;
                }
                $this->addToken(TokenType::CC_FLAG, $compiler . ':' . $platform . ':' . $flags, [
                    'platform' => $platform, 'compiler' => $compiler, 'flags' => $flags,
                ]);
                $this->advance(strlen($m[0]));
                return;
            }
            // #debug [text] — 仅 --debug 模式产生 token
            //   "#debug text" → 捕获 text
            //   "#debug" 或 "#debug " → 捕获空串（表示空输出行）
            if (preg_match('/^#debug[ \t]([^\r\n]*)/', $rest, $m)) {
                if ($this->debugMode) {
                    $this->addToken(TokenType::HASH_DEBUG, $m[1]);
                }
                $this->advance(strlen($m[0]));
                return;
            }
            // 裸 #debug 行（无双文）→ 空行
            if (preg_match('/^#debug(?=[\s]*(\r?\n|$))/', $rest, $m)) {
                if ($this->debugMode) {
                    $this->addToken(TokenType::HASH_DEBUG, '');
                }
                $this->advance(strlen($m[0]));
                return;
            }
            // #callback ret_type name(params) — 声明 C 回调签名
            if (preg_match('/^#callback\s+(\S+)\s+(\w+)\s*\((.+?)\)/', $rest, $m)) {
                $this->addToken(TokenType::HASH_CALLBACK, $m[2], [
                    'name' => $m[2],
                    'ret'  => $m[1],
                    'params_str' => trim($m[3]),
                ]);
                $this->advance(strlen($m[0]));
                return;
            }
            // 普通 # 注释 — 跳过整行
            $this->skipLineComment();
            return;
        }

        // heredoc / nowdoc: <<<ID ... ID
        if ($ch === '<' && $this->peek(1) === '<' && $this->peek(2) === '<') {
            $this->scanHeredoc();
            return;
        }

        // <=> 太空船（3 字符，必须在 <= 之前检测）
        if ($ch === '<' && $this->peek(1) === '=' && $this->peek(2) === '>') {
            $this->addToken(TokenType::SPACESHIP, '<=>');
            $this->advance(3);
            return;
        }

        // 多字符运算符 (必须在单字符之前检查)
        $multiOps = [
            '->' => TokenType::ARROW,
            '=>' => TokenType::DOUBLE_ARROW,
            '==' => TokenType::EQ,
            '!=' => TokenType::NE,
            '<=' => TokenType::LE,
            '>=' => TokenType::GE,
            '**' => TokenType::STAR_STAR,
            '&&' => TokenType::AND_AND,
            '||' => TokenType::OR_OR,
            '++' => TokenType::INC,
            '--' => TokenType::DEC,
            '+=' => TokenType::PLUS_EQ,
            '-=' => TokenType::MINUS_EQ,
            '*=' => TokenType::STAR_EQ,
            '/=' => TokenType::SLASH_EQ,
            '.=' => TokenType::DOT_EQ,
            '??' => TokenType::QUEST_QUEST,
            '<<' => TokenType::LT_LT,
            '>>' => TokenType::GT_GT,
        ];
        // 三字符运算符 (必须在两字符之前检查): === !== ?->
        $three = $ch . $this->peek(1) . $this->peek(2);
        $threeOps = ['===' => TokenType::IDENTICAL, '!==' => TokenType::NOT_IDENTICAL, '?->' => TokenType::NULLSAFE_ARROW];
        if (isset($threeOps[$three])) {
            $this->addToken($threeOps[$three], $three);
            $this->advance(3);
            return;
        }

        $two = $ch . $this->peek(1);
        if (isset($multiOps[$two])) {
            $this->addToken($multiOps[$two], $two);
            $this->advance(2);
            return;
        }

        // 单字符运算符
        $opChars = [
            '+' => TokenType::PLUS,
            '-' => TokenType::MINUS,
            '*' => TokenType::STAR,
            '/' => TokenType::SLASH,
            '%' => TokenType::MOD,
            '<' => TokenType::LT,
            '>' => TokenType::GT,
            '!' => TokenType::BANG,
            '&' => TokenType::AMP,
            '|' => TokenType::PIPE,
            '^' => TokenType::CARET,
            '~' => TokenType::TILDE,
            '?' => TokenType::QUEST,
        ];
        if (isset($opChars[$ch])) {
            $this->addToken($opChars[$ch], $ch);
            $this->advance();
            return;
        }

        // 字符串
        if ($ch === '"' || $ch === "'") {
            $this->scanString();
            return;
        }

        // 符号
        $singleChars = [
            '(' => TokenType::LPAREN,
            ')' => TokenType::RPAREN,
            '{' => TokenType::LBRACE,
            '}' => TokenType::RBRACE,
            '[' => TokenType::LBRACKET,
            ']' => TokenType::RBRACKET,
            ';' => TokenType::SEMICOLON,
            ',' => TokenType::COMMA,
            '=' => TokenType::EQUALS,
            '.' => TokenType::DOT,
        ];
        // \ 命名空间分隔符
        if ($ch === '\\') {
            $this->addToken(TokenType::NS_SEP, '\\');
            $this->advance();
            return;
        }
        // :: 必须在单字符 : 之前
        if ($ch === ':' && $this->peek(1) === ':') {
            $this->addToken(TokenType::DOUBLE_COLON, '::');
            $this->advance(2);
            return;
        }
        // : 单字符
        if ($ch === ':') {
            $this->addToken(TokenType::COLON, ':');
            $this->advance();
            return;
        }
        if (isset($singleChars[$ch])) {
            $this->addToken($singleChars[$ch], $ch);
            $this->advance();
            return;
        }

        // $ 变量
        if ($ch === '$') {
            $this->scanVariable();
            return;
        }

        // 数字
        if (ctype_digit($ch)) {
            $this->scanNumber();
            return;
        }

        // 标识符/关键字
        if (ctype_alpha($ch) || $ch === '_') {
            $this->scanIdentifier();
            return;
        }

        $this->error("Unexpected character: '{$ch}'");
    }

    private function peek(int $offset = 0): string
    {
        $idx = $this->pos + $offset;
        return ($idx < strlen($this->source)) ? $this->source[$idx] : "\0";
    }

    private function advance(int $n = 1): void
    {
        for ($i = 0; $i < $n; $i++) {
            if ($this->pos < strlen($this->source) && $this->source[$this->pos] === "\n") {
                $this->line++;
                $this->column = 1;
            } else {
                $this->column++;
            }
            $this->pos++;
        }
    }

    private function addToken(TokenType $type, string $lexeme, mixed $literal = null): void
    {
        $this->tokens[] = new Token($type, $lexeme, $this->line, $this->column, $literal);
    }

    private function skipLineComment(): void
    {
        while ($this->pos < strlen($this->source) && $this->peek() !== "\n") {
            $this->advance();
        }
    }

    private function skipBlockComment(): void
    {
        $this->advance(2); // skip /*
        while ($this->pos < strlen($this->source)) {
            if ($this->peek() === '*' && $this->peek(1) === '/') {
                $this->advance(2);
                return;
            }
            $this->advance();
        }
        $this->error('Unterminated block comment');
    }

    private function scanString(): void
    {
        $quote = $this->peek();
        $this->advance(); // skip opening quote

        // 单引号：简单扫描，无插值
        if ($quote === "'") {
            $escaped = '';
            while ($this->pos < strlen($this->source) && $this->peek() !== $quote) {
                $ch = $this->peek();
                if ($ch === '\\' && $this->peek(1) !== "\0") {
                    $this->advance();
                    $next = $this->peek();
                    $escaped .= '\\' . $next;
                    $this->advance();
                } else {
                    $escaped .= $ch;
                    $this->advance();
                }
            }
            if ($this->peek() !== $quote) $this->error('Unterminated string');
            $this->advance();
            $this->addToken(TokenType::STRING_LIT, $escaped, $escaped);
            return;
        }

        // 双引号：支持 $var / {$var} 插值，各段之间自动插入 DOT
        $buf = '';
        $needDot = false; // 是否需要在下一段前插入 DOT

        while ($this->pos < strlen($this->source) && $this->peek() !== $quote) {
            $ch = $this->peek();

            if ($ch === '\\' && $this->peek(1) !== "\0") {
                $this->advance();
                $next = $this->peek();
                $buf .= '\\' . $next;
                $this->advance();
                continue;
            }

            // 插值：$var 或 {$var} 或 {$var->prop}
            if ($ch === '$') {
                $this->advance(); // skip $

                $inBrace = false;
                // {$var} 语法：去掉 buf 末尾的 {
                if ($buf !== '' && $buf[strlen($buf) - 1] === '{') {
                    $buf = substr($buf, 0, -1);
                    $inBrace = true;
                }
                // 先输出累积的文本段
                if ($buf !== '') {
                    if ($needDot) $this->addToken(TokenType::DOT, '.');
                    $this->addToken(TokenType::STRING_LIT, $buf, $buf);
                    $buf = '';
                    $needDot = true;
                }

                if ($needDot) $this->addToken(TokenType::DOT, '.');

                // {$var} 语法
                if ($this->peek() === '{') {
                    $this->advance();
                    $inBrace = true;
                }

                $varName = '';
                while ($this->pos < strlen($this->source) && (ctype_alnum($this->peek()) || $this->peek() === '_')) {
                    $varName .= $this->peek();
                    $this->advance();
                }
                $this->addToken(TokenType::IDENTIFIER, '$' . $varName);

                // {$var->prop->prop} 语法：花括号内支持链式属性访问
                while ($inBrace && $this->peek() === '-' && $this->peek(1) === '>') {
                    $this->advance(2);
                    $propName = '';
                    while ($this->pos < strlen($this->source) && (ctype_alnum($this->peek()) || $this->peek() === '_')) {
                        $propName .= $this->peek();
                        $this->advance();
                    }
                    $this->addToken(TokenType::ARROW, '->');
                    $this->addToken(TokenType::IDENTIFIER, $propName);
                }

                if ($inBrace && $this->peek() === '}') $this->advance(); // skip }

                $needDot = true;
                continue;
            }

            $buf .= $ch;
            $this->advance();
        }

        if ($this->peek() !== $quote) $this->error('Unterminated string');
        $this->advance(); // skip closing quote

        if ($buf !== '') {
            if ($needDot) $this->addToken(TokenType::DOT, '.');
            $this->addToken(TokenType::STRING_LIT, $buf, $buf);
        } elseif (!$needDot) {
            // 空字符串 "" — 至少输出一个空 STRING_LIT
            $this->addToken(TokenType::STRING_LIT, '', '');
        }
    }

    private function scanNumber(): void
    {
        $ch = $this->peek();

        // 前缀字面量: 0x / 0b / 0o（仅当首字符为 '0' 时检测）
        if ($ch === '0' && $this->pos + 1 < strlen($this->source)) {
            $next = $this->peek(1);
            if ($next === 'x' || $next === 'X') {
                $this->scanHexNumber();
                return;
            }
            if ($next === 'b' || $next === 'B') {
                $this->scanBinaryNumber();
                return;
            }
            if ($next === 'o' || $next === 'O') {
                $this->scanOctalNumber();
                return;
            }
        }

        $this->scanDecimalNumber();
    }

    /** 十六进制: 0x[0-9a-fA-F_]+ */
    private function scanHexNumber(): void
    {
        $lexeme = '0x';
        $this->advance(2);

        $digits = '';
        while ($this->pos < strlen($this->source)) {
            $c = $this->peek();
            if (ctype_xdigit($c)) {
                $digits .= $c;
                $lexeme .= $c;
                $this->advance();
            } elseif ($c === '_') {
                if ($digits === '') {
                    $this->error("Invalid hex literal: underscore cannot follow prefix '0x'");
                }
                $next = $this->peek(1);
                if (!ctype_xdigit($next)) {
                    $this->error("Invalid hex literal: underscore must be followed by a hex digit");
                }
                $lexeme .= '_';
                $this->advance();
            } else {
                break;
            }
        }

        if ($digits === '') {
            $this->error("Invalid hex literal: missing digits after '0x'");
        }

        $value = intval(str_replace('_', '', $digits), 16);
        $this->addToken(TokenType::INT_LIT, $lexeme, $value);
    }

    /** 二进制: 0b[01_]+ */
    private function scanBinaryNumber(): void
    {
        $lexeme = '0b';
        $this->advance(2);

        $digits = '';
        while ($this->pos < strlen($this->source)) {
            $c = $this->peek();
            if ($c === '0' || $c === '1') {
                $digits .= $c;
                $lexeme .= $c;
                $this->advance();
            } elseif ($c === '_') {
                if ($digits === '') {
                    $this->error("Invalid binary literal: underscore cannot follow prefix '0b'");
                }
                $next = $this->peek(1);
                if ($next !== '0' && $next !== '1') {
                    $this->error("Invalid binary literal: underscore must be followed by a binary digit");
                }
                $lexeme .= '_';
                $this->advance();
            } else {
                break;
            }
        }

        if ($digits === '') {
            $this->error("Invalid binary literal: missing digits after '0b'");
        }

        $value = intval(str_replace('_', '', $digits), 2);
        $this->addToken(TokenType::INT_LIT, $lexeme, $value);
    }

    /** 八进制: 0o[0-7_]+ */
    private function scanOctalNumber(): void
    {
        $lexeme = '0o';
        $this->advance(2);

        $digits = '';
        while ($this->pos < strlen($this->source)) {
            $c = $this->peek();
            if ($c >= '0' && $c <= '7') {
                $digits .= $c;
                $lexeme .= $c;
                $this->advance();
            } elseif ($c === '_') {
                if ($digits === '') {
                    $this->error("Invalid octal literal: underscore cannot follow prefix '0o'");
                }
                $next = $this->peek(1);
                if ($next < '0' || $next > '7') {
                    $this->error("Invalid octal literal: underscore must be followed by an octal digit");
                }
                $lexeme .= '_';
                $this->advance();
            } else {
                break;
            }
        }

        if ($digits === '') {
            $this->error("Invalid octal literal: missing digits after '0o'");
        }

        $value = intval(str_replace('_', '', $digits), 8);
        $this->addToken(TokenType::INT_LIT, $lexeme, $value);
    }

    /** 十进制（含小数、科学计数、下划线分隔） */
    private function scanDecimalNumber(): void
    {
        $num = '';
        $isFloat = false;

        // 整数部分
        while ($this->pos < strlen($this->source)) {
            $c = $this->peek();
            if (ctype_digit($c)) {
                $num .= $c;
                $this->advance();
            } elseif ($c === '_' && $num !== '' && ctype_digit($num[-1]) && ctype_digit($this->peek(1))) {
                $num .= '_';
                $this->advance();
            } else {
                break;
            }
        }

        // 小数部分（保留原有行为：紧邻数字的 '.' 作为小数点）
        if ($this->peek() === '.') {
            $isFloat = true;
            $num .= '.';
            $this->advance();
            while ($this->pos < strlen($this->source)) {
                $c = $this->peek();
                if (ctype_digit($c)) {
                    $num .= $c;
                    $this->advance();
                } elseif ($c === '_' && $num !== '' && ctype_digit($num[-1]) && ctype_digit($this->peek(1))) {
                    $num .= '_';
                    $this->advance();
                } else {
                    break;
                }
            }
        }

        // 指数部分: e/E [+-]? [0-9]+
        if ($this->peek() === 'e' || $this->peek() === 'E') {
            $sign = '';
            $offset = 1;
            if ($this->peek(1) === '+' || $this->peek(1) === '-') {
                $sign = $this->peek(1);
                $offset = 2;
            }
            if (ctype_digit($this->peek($offset))) {
                $isFloat = true;
                $num .= $this->peek(); // e/E
                $this->advance();
                if ($sign !== '') {
                    $num .= $sign;
                    $this->advance();
                }
                while ($this->pos < strlen($this->source) && ctype_digit($this->peek())) {
                    $num .= $this->peek();
                    $this->advance();
                }
            }
        }

        $cleaned = str_replace('_', '', $num);
        if ($isFloat) {
            $this->addToken(TokenType::FLOAT_LIT, $num, (float)$cleaned);
        } else {
            $this->addToken(TokenType::INT_LIT, $num, (int)$cleaned);
        }
    }

    private function scanIdentifier(): void
    {
        $name = '';
        while ($this->pos < strlen($this->source) && (ctype_alnum($this->peek()) || $this->peek() === '_')) {
            $name .= $this->peek();
            $this->advance();
        }

        // 检查是否为关键字
        $type = self::$keywords[$name] ?? TokenType::IDENTIFIER;
        $literal = null;
        if ($type === TokenType::TRUE_KW) {
            $literal = true;
        } elseif ($type === TokenType::FALSE_KW) {
            $literal = false;
        } elseif ($type === TokenType::NULL_KW) {
            $literal = null;
        }

        $this->addToken($type, $name, $literal);
    }

    private function scanVariable(): void
    {
        $this->advance(); // skip $
        // Check for $$var (variable variables) — unsupported in AOT
        if ($this->pos < strlen($this->source) && $this->peek() === '$') {
            $this->error('Variable variables ($$var) are not supported in AOT mode. Use an array map instead: $map[$key] replaces $$key.');
        }
        $name = '';
        while ($this->pos < strlen($this->source) && (ctype_alnum($this->peek()) || $this->peek() === '_')) {
            $name .= $this->peek();
            $this->advance();
        }
        if ($name === 'this') {
            $this->addToken(TokenType::IDENTIFIER, '$this');
        } else {
            $this->addToken(TokenType::IDENTIFIER, '$' . $name);
        }
    }

    private function error(string $msg): never
    {
        throw new RuntimeException(
            sprintf("Lexer error [%d:%d]: %s", $this->line, $this->column, $msg)
        );
    }

    /** heredoc / nowdoc: <<<ID\n...\nID */
    private function scanHeredoc(): void
    {
        $this->advance(3); // skip <<<

        // nowdoc: <<<'ID' → 单引号标识符，无插值
        $quote = '';
        if ($this->peek() === "'") {
            $quote = "'";
            $this->advance();
        }

        // 读取标识符
        $id = '';
        while ($this->pos < strlen($this->source) && (ctype_alnum($this->peek()) || $this->peek() === '_')) {
            $id .= $this->peek();
            $this->advance();
        }

        if ($quote !== '') {
            if ($this->peek() === "'") $this->advance();
        }

        if ($id === '') {
            $this->error('heredoc missing identifier');
        }

        // 跳过标识符后的空白到换行（包括空格/tab）
        while ($this->pos < strlen($this->source) && $this->peek() !== "\n" && $this->peek() !== "\r") {
            $ch = $this->peek();
            if ($ch !== ' ' && $ch !== "\t") {
                $this->error("heredoc identifier '{$id}' cannot be followed by '{$ch}'");
            }
            $this->advance();
        }
        // 跳过换行
        if ($this->peek() === "\r") { $this->advance(); $this->line++; $this->column = 1; }
        if ($this->peek() === "\n") { $this->advance(); $this->line++; $this->column = 1; }

        // 读取内容直到单独一行的结束标识符
        $body = '';
        while ($this->pos < strlen($this->source)) {
            // 检测行首的结束标识符（column==1 说明刚换行）
            if ($this->column === 1 && $this->peek() === $id[0]) {
                $match = true;
                $idLen = strlen($id);
                for ($j = 0; $j < $idLen; $j++) {
                    if ($this->peek($j) !== $id[$j]) { $match = false; break; }
                }
                if ($match) {
                    $after = $this->peek($idLen);
                    // 标识符后必须是 ; 或换行 或 EOF
                    if ($after === ';' || $after === "\n" || $after === "\r" || $after === '') {
                        $this->advance($idLen);
                        // ; 是语句终止符，必须保留给 Parser
                        if ($this->peek() === ';') {
                            // 不 advance，留给下一次 scanToken 生成 SEMICOLON
                        }
                        while ($this->pos < strlen($this->source) && ($this->peek() === ' ' || $this->peek() === "\t")) $this->advance();
                        break;
                    }
                }
            }

            $c = $this->peek();
            if ($c === "\r") {
                $body .= "\n";
                $this->advance();
                if ($this->peek() === "\n") $this->advance();
                $this->line++; $this->column = 1;
            } elseif ($c === "\n") {
                $body .= "\n";
                $this->advance();
                $this->line++; $this->column = 1;
            } else {
                $body .= $c;
                $this->advance();
            }
        }

        if ($quote === "'") {
            // nowdoc: 无插值，直接输出
            $this->addToken(TokenType::STRING_LIT, $body, $body);
        } else {
            // heredoc: 处理转义序列 + 插值
            $this->emitHeredocString($body);
        }
    }

    /** 将 heredoc 字符串按插值分割为 TOKEN 序列（字符串+变量+DOT）
     *  输出为 C 字符串字面量形式（STR_LIT 宏会再次 C-escape） */
    private function emitHeredocString(string $str): void
    {
        // key=PHP转义字符, value=C字面量输出
        static $escapes = ['n' => "\n", 't' => "\t", 'r' => "\r", '\\' => "\\\\", '$' => '$'];

        $len = strlen($str);
        $buf = '';
        $needDot = false;
        $i = 0;

        while ($i < $len) {
            $c = $str[$i];
            // 转义序列：\n \t \\ \$ \r
            if ($c === '\\' && $i + 1 < $len) {
                $next = $str[$i + 1];
                if (isset($escapes[$next])) {
                    $buf .= $escapes[$next];
                    $i += 2;
                    continue;
                }
                // 其他 \x → 保持原样（如 \0, \" 等）
                $buf .= '\\' . $next;
                $i += 2;
                continue;
            }
            if ($c === '$') {
                // 检测变量名
                $j = $i + 1;
                if ($j < $len && $str[$j] === '{') {
                    // {$var} 或 {$var->prop} 语法
                    $varEnd = strpos($str, '}', $j + 1);
                    if ($varEnd !== false) {
                        if ($buf !== '') {
                            if ($needDot) $this->addToken(TokenType::DOT, '.');
                            $this->addToken(TokenType::STRING_LIT, $buf, $buf);
                            $buf = '';
                            $needDot = true;
                        }
                        if ($needDot) $this->addToken(TokenType::DOT, '.');
                        $inner = substr($str, $j + 1, $varEnd - $j - 1);
                        // 支持 $var->prop->prop 链
                        $parts = explode('->', $inner);
                        $this->addToken(TokenType::IDENTIFIER, '$' . $parts[0]);
                        for ($p = 1; $p < count($parts); $p++) {
                            $this->addToken(TokenType::ARROW, '->');
                            $this->addToken(TokenType::IDENTIFIER, $parts[$p]);
                        }
                        $needDot = true;
                        $i = $varEnd + 1;
                        continue;
                    }
                } elseif ($j < $len && (ctype_alpha($str[$j]) || $str[$j] === '_')) {
                    // $var 语法（可能包含 ->prop）
                    $k = $j;
                    while ($k < $len && (ctype_alnum($str[$k]) || $str[$k] === '_')) $k++;
                    if ($buf !== '') {
                        if ($needDot) $this->addToken(TokenType::DOT, '.');
                        $this->addToken(TokenType::STRING_LIT, $buf, $buf);
                        $buf = '';
                        $needDot = true;
                    }
                    if ($needDot) $this->addToken(TokenType::DOT, '.');
                    $varName = substr($str, $j, $k - $j);
                    $this->addToken(TokenType::IDENTIFIER, '$' . $varName);
                    // 支持 $var->prop->prop 链
                    while ($k + 1 < $len && $str[$k] === '-' && $str[$k + 1] === '>') {
                        $this->addToken(TokenType::ARROW, '->');
                        $k += 2;
                        $propName = '';
                        while ($k < $len && (ctype_alnum($str[$k]) || $str[$k] === '_')) {
                            $propName .= $str[$k];
                            $k++;
                        }
                        $this->addToken(TokenType::IDENTIFIER, $propName);
                    }
                    $needDot = true;
                    $i = $k;
                    continue;
                }
            }
            $buf .= $c;
            $i++;
        }

        if ($buf !== '') {
            if ($needDot) $this->addToken(TokenType::DOT, '.');
            $this->addToken(TokenType::STRING_LIT, $buf, $buf);
        }
    }
}
