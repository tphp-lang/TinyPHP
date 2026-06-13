<?php

declare(strict_types=1);

namespace Tphp;

final class Lexer
{
    private string $source;
    private int $pos = 0;
    private int $line = 1;
    private int $column = 1;
    private int $length;

    private const array KEYWORDS = [
        'namespace' => TokenType::Namespace, 'function' => TokenType::Function,
        'return' => TokenType::Return, 'print' => TokenType::Print,
        'echo' => TokenType::Echo_,
        'var_dump' => TokenType::VarDump,
        'count' => TokenType::Count, 'array' => TokenType::Array,
        'unset' => TokenType::Unset,
        'if' => TokenType::If, 'else' => TokenType::Else,
        'while' => TokenType::While, 'for' => TokenType::For,
        'foreach' => TokenType::Foreach, 'as' => TokenType::As,
        'switch' => TokenType::Switch_, 'case' => TokenType::Case_,
        'default' => TokenType::Default_, 'break' => TokenType::Break_,
        'use' => TokenType::Use, 'class' => TokenType::Class_,
        'enum' => TokenType::Enum,
        'public' => TokenType::Public, 'private' => TokenType::Private,
        'new' => TokenType::New, 'const' => TokenType::Const,
        'int' => TokenType::Int, 'float' => TokenType::Float,
        'string' => TokenType::String_, 'bool' => TokenType::Bool_,
        'void' => TokenType::Void_, 'null' => TokenType::Null_,
        'true' => TokenType::True_, 'false' => TokenType::False_,
    ];

    /** @var Token[] */
    private array $tokens = [];
    private int $tokenPos = 0;

    public function __construct(string $source)
    {
        $this->source = $source;
        $this->length = strlen($source);
    }

    /** @return Token[] */
    public function tokenize(): array
    {
        $this->tokens = [];
        $this->pos = 0;
        $this->line = 1;
        $this->column = 1;
        $this->tokenPos = 0;
        $this->scanLoop();
        $this->tokens[] = new Token(TokenType::Eof, '', $this->line, $this->column);
        return $this->tokens;
    }

    private function scanLoop(): void
    {
        while ($this->pos < $this->length) {
            $ch = $this->source[$this->pos];

            if ($ch === ' ' || $ch === "\t" || $ch === "\r") {
                $this->advance();
                continue;
            }
            if ($ch === "\n") {
                $this->line++;
                $this->column = 1;
                $this->pos++;
                continue;
            }
            if ($this->tryExtern($ch)) continue;
            if ($this->tryComment($ch)) continue;
            if ($this->tryOpenTag($ch)) continue;
            if ($this->tryCloseTag($ch)) continue;
            if ($this->tryDot($ch)) continue;
            if ($this->tryVariable($ch)) continue;
            if ($this->tryStringLiteral($ch)) continue;
            if ($this->tryNumber($ch)) continue;
            if ($this->tryIdentifier($ch)) continue;
            if ($this->tryDoubleChar($ch)) continue;
            if ($this->trySingleChar($ch)) continue;

            throw new \RuntimeException(
                sprintf("Unexpected character '%s' at line %d, column %d", $ch, $this->line, $this->column)
            );
        }
    }

    private function advance(): void { $this->pos++; $this->column++; }
    private function peekAhead(): string { return ($this->pos + 1 < $this->length) ? $this->source[$this->pos + 1] : "\0"; }

    private function addToken(TokenType $type, string $value): void
    {
        $this->tokens[] = new Token($type, $value, $this->line, $this->column);
    }

    private function tryExtern(string $ch): bool
    {
        // Check for #extern keyword
        if ($ch === '#' && $this->pos + 6 < $this->length
            && $this->source[$this->pos + 1] === 'e'
            && $this->source[$this->pos + 2] === 'x'
            && $this->source[$this->pos + 3] === 't'
            && $this->source[$this->pos + 4] === 'e'
            && $this->source[$this->pos + 5] === 'r'
            && $this->source[$this->pos + 6] === 'n'
        ) {
            $this->addToken(TokenType::Extrn, '#extern');
            $this->pos += 7; $this->column += 7;
            return true;
        }
        return false;
    }

    private function tryComment(string $ch): bool
    {
        if (($ch === '/' && $this->peekAhead() === '/') || $ch === '#') {
            while ($this->pos < $this->length && $this->source[$this->pos] !== "\n") {
                $this->pos++; $this->column++;
            }
            return true;
        }
        if ($ch === '/' && $this->peekAhead() === '*') {
            $this->pos += 2; $this->column += 2;
            while ($this->pos < $this->length) {
                if ($this->source[$this->pos] === '*' && $this->peekAhead() === '/') {
                    $this->pos += 2; $this->column += 2;
                    return true;
                }
                if ($this->source[$this->pos] === "\n") { $this->line++; $this->column = 1; }
                else { $this->column++; }
                $this->pos++;
            }
            return true;
        }
        return false;
    }

    private function tryOpenTag(string $ch): bool
    {
        if ($ch === '<' && $this->pos + 4 < $this->length
            && $this->source[$this->pos + 1] === '?'
            && $this->source[$this->pos + 2] === 'p'
            && $this->source[$this->pos + 3] === 'h'
            && $this->source[$this->pos + 4] === 'p'
        ) {
            $this->addToken(TokenType::OpenTag, '<?php');
            $this->pos += 5; $this->column += 5;
            return true;
        }
        return false;
    }

    private function tryCloseTag(string $ch): bool
    {
        if ($ch === '?' && $this->peekAhead() === '>') {
            $this->addToken(TokenType::Eof, '?>');
            $this->pos += 2; $this->column += 2;
            return true;
        }
        return false;
    }

    private function tryDot(string $ch): bool
    {
        if ($ch === '.') {
            if ($this->pos + 1 < $this->length && ctype_digit($this->source[$this->pos + 1])) {
                $this->readNumber();
                return true;
            }
            $this->addToken(TokenType::Concat, '.');
            $this->advance();
            return true;
        }
        return false;
    }

    private function tryVariable(string $ch): bool
    {
        if ($ch !== '$') return false;
        $startCol = $this->column;
        $this->advance();
        $start = $this->pos;
        while ($this->pos < $this->length && (ctype_alnum($this->source[$this->pos]) || $this->source[$this->pos] === '_')) {
            $this->advance();
        }
        $name = substr($this->source, $start, $this->pos - $start);
        $this->tokens[] = new Token(TokenType::Variable, '$' . $name, $this->line, $startCol);
        return true;
    }

    private function tryStringLiteral(string $ch): bool
    {
        if ($ch !== '\'' && $ch !== '"') return false;
        $quote = $ch;
        $startCol = $this->column;
        $this->advance();

        // Single-quoted strings: no interpolation, keep as-is
        if ($quote === '\'') {
            $value = '';
            while ($this->pos < $this->length) {
                $c = $this->source[$this->pos];
                if ($c === $quote) { $this->advance(); break; }
                if ($c === '\\') {
                    $this->advance();
                    if ($this->pos < $this->length) {
                        $esc = $this->source[$this->pos];
                        if ($esc === 'n') $value .= "\n";
                        elseif ($esc === 't') $value .= "\t";
                        elseif ($esc === 'r') $value .= "\r";
                        elseif ($esc === '\\') $value .= "\\";
                        elseif ($esc === '\'') $value .= "'";
                        else $value .= '\\' . $esc;
                        $this->advance();
                    }
                    continue;
                }
                $value .= $c;
                $this->advance();
            }
            $this->tokens[] = new Token(TokenType::StringLiteral, $value, $this->line, $startCol);
            return true;
        }

        // Double-quoted strings: support $var and {$expr} interpolation
        // Split into StringLiteral + Concat + Variable + Concat + ... tokens
        $value = '';
        $hasInterpolation = false;

        while ($this->pos < $this->length) {
            $c = $this->source[$this->pos];
            if ($c === $quote) { $this->advance(); break; }
            if ($c === '\\') {
                $this->advance();
                if ($this->pos < $this->length) {
                    $esc = $this->source[$this->pos];
                    if ($esc === 'n') $value .= "\n";
                    elseif ($esc === 't') $value .= "\t";
                    elseif ($esc === 'r') $value .= "\r";
                    elseif ($esc === '\\') $value .= "\\";
                    elseif ($esc === '"') $value .= '"';
                    elseif ($esc === '$') $value .= '$';
                    else $value .= '\\' . $esc;
                    $this->advance();
                }
                continue;
            }
            // {$...} complex interpolation: {$var}, {$var[idx]}
            if ($c === '{' && $this->pos + 1 < $this->length && $this->source[$this->pos + 1] === '$') {
                $hasInterpolation = true;
                // Emit the accumulated string part before {$...}
                if ($value !== '') {
                    $this->tokens[] = new Token(TokenType::StringLiteral, $value, $this->line, $startCol);
                    $this->tokens[] = new Token(TokenType::Concat, '.', $this->line, $this->column);
                    $value = '';
                }
                // Skip '{'
                $this->advance();
                // Parse $var (same as simple interpolation)
                $this->advance(); // skip $
                $vs = $this->pos;
                while ($this->pos < $this->length && (ctype_alnum($this->source[$this->pos]) || $this->source[$this->pos] === '_')) {
                    $this->advance();
                }
                $varName = '$' . substr($this->source, $vs, $this->pos - $vs);
                $this->tokens[] = new Token(TokenType::Variable, $varName, $this->line, $startCol);
                // Parse optional [idx]
                if ($this->pos < $this->length && $this->source[$this->pos] === '[') {
                    $this->tokens[] = new Token(TokenType::LBracket, '[', $this->line, $this->column);
                    $this->advance(); // skip [
                    // Parse index expression (number or variable)
                    if ($this->pos < $this->length && $this->source[$this->pos] === '$') {
                        $this->advance(); // skip $
                        $ivs = $this->pos;
                        while ($this->pos < $this->length && (ctype_alnum($this->source[$this->pos]) || $this->source[$this->pos] === '_')) {
                            $this->advance();
                        }
                        $idxName = '$' . substr($this->source, $ivs, $this->pos - $ivs);
                        $this->tokens[] = new Token(TokenType::Variable, $idxName, $this->line, $this->column);
                    } else {
                        // Numeric index
                        $numStart = $this->pos;
                        while ($this->pos < $this->length && ctype_digit($this->source[$this->pos])) {
                            $this->advance();
                        }
                        $num = substr($this->source, $numStart, $this->pos - $numStart);
                        $this->tokens[] = new Token(TokenType::IntegerLiteral, $num, $this->line, $this->column);
                    }
                    if ($this->pos < $this->length && $this->source[$this->pos] === ']') {
                        $this->tokens[] = new Token(TokenType::RBracket, ']', $this->line, $this->column);
                        $this->advance(); // skip ]
                    }
                }
                // Skip closing '}'
                if ($this->pos < $this->length && $this->source[$this->pos] === '}') {
                    $this->advance();
                }
                // If more string content follows, emit concat for the next part
                if ($this->pos < $this->length && $this->source[$this->pos] !== $quote) {
                    $this->tokens[] = new Token(TokenType::Concat, '.', $this->line, $this->column);
                }
                continue;
            }
            if ($c === '$') {
                $hasInterpolation = true;
                // Always emit, even empty, to preserve concat AST
                $this->tokens[] = new Token(TokenType::StringLiteral, $value, $this->line, $startCol);
                $this->tokens[] = new Token(TokenType::Concat, '.', $this->line, $this->column);
                $value = '';
                // Parse variable name
                $this->advance();
                $vs = $this->pos;
                while ($this->pos < $this->length && (ctype_alnum($this->source[$this->pos]) || $this->source[$this->pos] === '_')) {
                    $this->advance();
                }
                $varName = '$' . substr($this->source, $vs, $this->pos - $vs);
                $this->tokens[] = new Token(TokenType::Variable, $varName, $this->line, $startCol);

                // If more string content follows (not end quote), emit concat for the next part
                if ($this->pos < $this->length && $this->source[$this->pos] !== $quote) {
                    $this->tokens[] = new Token(TokenType::Concat, '.', $this->line, $this->column);
                }
                continue;
            }
            $value .= $c;
            $this->advance();
        }

        if ($hasInterpolation) {
            // Emit remaining accumulated value (if any) after the last $var
            if ($value !== '') {
                $this->tokens[] = new Token(TokenType::StringLiteral, $value, $this->line, $startCol);
            }
        } else {
            // No interpolation: emit as single string literal (may be empty)
            $this->tokens[] = new Token(TokenType::StringLiteral, $value, $this->line, $startCol);
        }
        return true;
    }

    private function tryNumber(string $ch): bool
    {
        if (!ctype_digit($ch)) return false;
        $this->readNumber();
        return true;
    }

    private function readNumber(): void
    {
        $start = $this->pos;
        $startCol = $this->column;
        $isFloat = false;
        while ($this->pos < $this->length && ctype_digit($this->source[$this->pos])) {
            $this->advance();
        }
        if ($this->pos < $this->length && $this->source[$this->pos] === '.') {
            $isFloat = true;
            $this->advance();
            while ($this->pos < $this->length && ctype_digit($this->source[$this->pos])) {
                $this->advance();
            }
        }
        $value = substr($this->source, $start, $this->pos - $start);
        $this->tokens[] = new Token(
            $isFloat ? TokenType::FloatLiteral : TokenType::IntegerLiteral,
            $value, $this->line, $startCol
        );
    }

    private function tryIdentifier(string $ch): bool
    {
        if (!ctype_alpha($ch) && $ch !== '_') return false;
        $start = $this->pos;
        $startCol = $this->column;
        while ($this->pos < $this->length && (ctype_alnum($this->source[$this->pos]) || $this->source[$this->pos] === '_')) {
            $this->advance();
        }
        $value = substr($this->source, $start, $this->pos - $start);
        $type = self::KEYWORDS[$value] ?? TokenType::Identifier;
        $this->tokens[] = new Token($type, $value, $this->line, $startCol);
        return true;
    }

    private function trySingleChar(string $ch): bool
    {
        $map = [
            '(' => TokenType::LParen,   ')' => TokenType::RParen,
            '{' => TokenType::LBrace,   '}' => TokenType::RBrace,
            '[' => TokenType::LBracket, ']' => TokenType::RBracket,
            ',' => TokenType::Comma,    ';' => TokenType::Semicolon,
            '+' => TokenType::Plus,     '*' => TokenType::Star,
            '/' => TokenType::Slash,    '%' => TokenType::Percent,
            '\\' => TokenType::Backslash,
        ];
        if (isset($map[$ch])) {
            $this->addToken($map[$ch], $ch);
            $this->advance();
            return true;
        }
        return false;
    }

    private function tryDoubleChar(string $ch): bool
    {
        if ($ch === '=') {
            // Check for ===
            if ($this->peekAhead() === '=') {
                if ($this->pos + 2 < $this->length && $this->source[$this->pos + 2] === '=') {
                    $this->addToken(TokenType::StrictEq, '===');
                    $this->pos += 3; $this->column += 3;
                    return true;
                }
                $this->addToken(TokenType::Eq, '==');
                $this->pos += 2; $this->column += 2;
                return true;
            }
            if ($this->peekAhead() === '>') {
                $this->addToken(TokenType::Arrow, '=>');
                $this->pos += 2; $this->column += 2;
                return true;
            }
            $this->addToken(TokenType::Assign, '=');
            $this->advance();
            return true;
        }
        if ($ch === '!' && $this->peekAhead() === '=') {
            // Check for !==
            if ($this->pos + 2 < $this->length && $this->source[$this->pos + 2] === '=') {
                $this->addToken(TokenType::StrictNeq, '!==');
                $this->pos += 3; $this->column += 3;
                return true;
            }
            $this->addToken(TokenType::Neq, '!=');
            $this->pos += 2; $this->column += 2;
            return true;
        }
        if ($ch === '<') {
            if ($this->peekAhead() === '=') {
                $this->addToken(TokenType::Lte, '<=');
                $this->pos += 2; $this->column += 2;
                return true;
            }
            $this->addToken(TokenType::Lt, '<');
            $this->advance();
            return true;
        }
        if ($ch === '>') {
            if ($this->peekAhead() === '=') {
                $this->addToken(TokenType::Gte, '>=');
                $this->pos += 2; $this->column += 2;
                return true;
            }
            $this->addToken(TokenType::Gt, '>');
            $this->advance();
            return true;
        }
        if ($ch === '-') {
            if ($this->peekAhead() === '>') {
                $this->addToken(TokenType::ObjectArrow, '->');
                $this->pos += 2; $this->column += 2;
                return true;
            }
            $this->addToken(TokenType::Minus, '-');
            $this->advance();
            return true;
        }
        if ($ch === '+' && $this->peekAhead() === '+') {
            $this->addToken(TokenType::Increment, '++');
            $this->pos += 2; $this->column += 2;
            return true;
        }
        if ($ch === '-' && $this->peekAhead() === '-') {
            $this->addToken(TokenType::Decrement, '--');
            $this->pos += 2; $this->column += 2;
            return true;
        }
        if ($ch === ':') {
            if ($this->peekAhead() === ':') {
                $this->addToken(TokenType::DoubleColon, '::');
                $this->pos += 2; $this->column += 2;
                return true;
            }
            $this->addToken(TokenType::Colon, ':');
            $this->advance();
            return true;
        }
        return false;
    }

    // ---- Stream interface ----

    public function current(): Token
    {
        return $this->tokens[$this->tokenPos] ?? end($this->tokens);
    }

    public function peek(): Token
    {
        $idx = $this->tokenPos + 1;
        return $this->tokens[$idx] ?? end($this->tokens);
    }

    /** Peek N tokens ahead (1 = next token) */
    public function peekAt(int $n): Token
    {
        $idx = $this->tokenPos + $n;
        return $this->tokens[$idx] ?? end($this->tokens);
    }

    public function next(): Token
    {
        $tok = $this->current();
        if (!$tok->isType(TokenType::Eof)) $this->tokenPos++;
        return $tok;
    }

    public function expect(TokenType $type): Token
    {
        $tok = $this->current();
        if (!$tok->isType($type)) {
            throw new \RuntimeException(sprintf(
                "Expected %s but got %s at line %d",
                $type->value, $tok->__toString(), $tok->line
            ));
        }
        return $this->next();
    }

    public function match(TokenType $type): bool
    {
        if ($this->current()->isType($type)) { $this->next(); return true; }
        return false;
    }

    public function isEof(): bool
    {
        return $this->current()->isType(TokenType::Eof);
    }
}
