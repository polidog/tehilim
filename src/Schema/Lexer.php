<?php

declare(strict_types=1);

namespace Polidog\Tehilim\Schema;

final class Lexer
{
    private int $pos = 0;
    private int $line = 1;
    private readonly int $len;

    public function __construct(private readonly string $source)
    {
        $this->len = strlen($source);
    }

    /** @return non-empty-list<Token> */
    public function tokenize(): array
    {
        $tokens = [];

        while ($this->pos < $this->len) {
            $c = $this->source[$this->pos];

            if ($c === "\n") {
                ++$this->line;
                ++$this->pos;

                continue;
            }
            if (ctype_space($c)) {
                ++$this->pos;

                continue;
            }
            if ($c === '/' && $this->peek(1) === '/') {
                $this->skipLineComment();

                continue;
            }
            if ($c === '/' && $this->peek(1) === '*') {
                $this->skipBlockComment();

                continue;
            }

            $tokens[] = $this->nextToken();
        }

        $tokens[] = new Token(TokenType::Eof, '', $this->line);

        return $tokens;
    }

    private function nextToken(): Token
    {
        $c = $this->source[$this->pos];
        $line = $this->line;

        return match (true) {
            $c === '{' => $this->single(TokenType::LBrace, '{'),
            $c === '}' => $this->single(TokenType::RBrace, '}'),
            $c === '[' => $this->single(TokenType::LBracket, '['),
            $c === ']' => $this->single(TokenType::RBracket, ']'),
            $c === '(' => $this->single(TokenType::LParen, '('),
            $c === ')' => $this->single(TokenType::RParen, ')'),
            $c === '?' => $this->single(TokenType::Question, '?'),
            $c === ',' => $this->single(TokenType::Comma, ','),
            $c === ':' => $this->single(TokenType::Colon, ':'),
            $c === '=' => $this->single(TokenType::Equals, '='),
            $c === '@' && $this->peek(1) === '@' => $this->two(TokenType::DoubleAt, '@@'),
            $c === '@' => $this->single(TokenType::At, '@'),
            $c === '"' => $this->readString($line),
            $c === '-' || ctype_digit($c) => $this->readNumber($line),
            self::isIdentStart($c) => $this->readIdent($line),
            default => throw new ParseException("Unexpected character {$c}", $this->line),
        };
    }

    private function single(TokenType $t, string $v): Token
    {
        $tok = new Token($t, $v, $this->line);
        ++$this->pos;

        return $tok;
    }

    private function two(TokenType $t, string $v): Token
    {
        $tok = new Token($t, $v, $this->line);
        $this->pos += 2;

        return $tok;
    }

    private function readString(int $line): Token
    {
        ++$this->pos; // skip opening "
        $buf = '';
        while ($this->pos < $this->len) {
            $c = $this->source[$this->pos];
            if ($c === '"') {
                ++$this->pos;

                return new Token(TokenType::String, $buf, $line);
            }
            if ($c === '\\') {
                $next = $this->peek(1);
                $buf .= match ($next) {
                    'n' => "\n",
                    't' => "\t",
                    'r' => "\r",
                    '"' => '"',
                    '\\' => '\\',
                    default => throw new ParseException("Unknown escape \\{$next}", $this->line),
                };
                $this->pos += 2;

                continue;
            }
            if ($c === "\n") {
                ++$this->line;
            }
            $buf .= $c;
            ++$this->pos;
        }

        throw new ParseException('Unterminated string', $line);
    }

    private function readNumber(int $line): Token
    {
        $start = $this->pos;
        if ($this->source[$this->pos] === '-') {
            ++$this->pos;
        }
        while ($this->pos < $this->len && (ctype_digit($this->source[$this->pos]) || $this->source[$this->pos] === '.')) {
            ++$this->pos;
        }

        return new Token(TokenType::Number, substr($this->source, $start, $this->pos - $start), $line);
    }

    private function readIdent(int $line): Token
    {
        $start = $this->pos;
        while ($this->pos < $this->len && self::isIdentPart($this->source[$this->pos])) {
            ++$this->pos;
        }
        $word = substr($this->source, $start, $this->pos - $start);

        return match ($word) {
            'true' => new Token(TokenType::True, $word, $line),
            'false' => new Token(TokenType::False, $word, $line),
            'null' => new Token(TokenType::Null, $word, $line),
            default => new Token(TokenType::Ident, $word, $line),
        };
    }

    private function skipLineComment(): void
    {
        while ($this->pos < $this->len && $this->source[$this->pos] !== "\n") {
            ++$this->pos;
        }
    }

    private function skipBlockComment(): void
    {
        $startLine = $this->line;
        $this->pos += 2;
        while ($this->pos < $this->len) {
            if ($this->source[$this->pos] === '*' && $this->peek(1) === '/') {
                $this->pos += 2;

                return;
            }
            if ($this->source[$this->pos] === "\n") {
                ++$this->line;
            }
            ++$this->pos;
        }

        throw new ParseException('Unterminated block comment', $startLine);
    }

    private function peek(int $offset): string
    {
        return $this->source[$this->pos + $offset] ?? '';
    }

    private static function isIdentStart(string $c): bool
    {
        return ctype_alpha($c) || $c === '_';
    }

    private static function isIdentPart(string $c): bool
    {
        return ctype_alnum($c) || $c === '_';
    }
}
