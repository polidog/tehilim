<?php

declare(strict_types=1);

namespace Polidog\Tehilim\Schema;

use Polidog\Tehilim\Schema\Ast\Attribute;
use Polidog\Tehilim\Schema\Ast\BlockAttribute;
use Polidog\Tehilim\Schema\Ast\Datasource;
use Polidog\Tehilim\Schema\Ast\Field;
use Polidog\Tehilim\Schema\Ast\FieldType;
use Polidog\Tehilim\Schema\Ast\Generator;
use Polidog\Tehilim\Schema\Ast\Invocation;
use Polidog\Tehilim\Schema\Ast\Model;
use Polidog\Tehilim\Schema\Ast\Schema;

final class Parser
{
    /** @var non-empty-list<Token> */
    private array $tokens;
    private int $pos = 0;

    public static function parseString(string $source): Schema
    {
        return (new self())->doParse($source);
    }

    public static function parseFile(string $path): Schema
    {
        $src = @file_get_contents($path);
        if ($src === false) {
            throw new ParseException("Cannot read schema file: {$path}");
        }
        return (new self())->doParse($src);
    }

    private function doParse(string $source): Schema
    {
        $this->tokens = (new Lexer($source))->tokenize();
        $this->pos = 0;

        $models = [];
        $datasources = [];
        $generators = [];

        while (!$this->atEnd()) {
            $kw = $this->expect(TokenType::Ident);
            switch ($kw->value) {
                case 'model':
                    $models[] = $this->parseModel();
                    break;
                case 'datasource':
                    $datasources[] = $this->parseDatasource();
                    break;
                case 'generator':
                    $generators[] = $this->parseGenerator();
                    break;
                default:
                    throw new ParseException("Unknown top-level keyword '{$kw->value}'", $kw->line);
            }
        }

        return new Schema($models, $datasources, $generators);
    }

    private function parseModel(): Model
    {
        $name = $this->expect(TokenType::Ident)->value;
        $this->expect(TokenType::LBrace);

        $fields = [];
        $blockAttrs = [];

        while (!$this->check(TokenType::RBrace)) {
            if ($this->match(TokenType::DoubleAt)) {
                $attrName = $this->expect(TokenType::Ident)->value;
                $args = [];
                if ($this->match(TokenType::LParen)) {
                    $args = $this->parseArgList();
                    $this->expect(TokenType::RParen);
                }
                $blockAttrs[] = new BlockAttribute($attrName, $args);
                continue;
            }
            $fields[] = $this->parseField();
        }

        $this->expect(TokenType::RBrace);

        return new Model($name, $fields, $blockAttrs);
    }

    private function parseField(): Field
    {
        $name = $this->expect(TokenType::Ident)->value;
        $typeName = $this->expect(TokenType::Ident)->value;

        $nullable = false;
        $list = false;

        if ($this->match(TokenType::LBracket)) {
            $this->expect(TokenType::RBracket);
            $list = true;
        }
        if ($this->match(TokenType::Question)) {
            $nullable = true;
        }

        $attributes = [];
        while ($this->check(TokenType::At)) {
            $this->advance();
            $attrName = $this->expect(TokenType::Ident)->value;
            $args = [];
            if ($this->match(TokenType::LParen)) {
                $args = $this->parseArgList();
                $this->expect(TokenType::RParen);
            }
            $attributes[] = new Attribute($attrName, $args);
        }

        return new Field($name, new FieldType($typeName), $nullable, $list, $attributes);
    }

    private function parseDatasource(): Datasource
    {
        $name = $this->expect(TokenType::Ident)->value;
        $this->expect(TokenType::LBrace);
        $options = $this->parseAssignments();
        $this->expect(TokenType::RBrace);
        return new Datasource($name, $options);
    }

    private function parseGenerator(): Generator
    {
        $name = $this->expect(TokenType::Ident)->value;
        $this->expect(TokenType::LBrace);
        $options = $this->parseAssignments();
        $this->expect(TokenType::RBrace);
        return new Generator($name, $options);
    }

    /** @return array<string,mixed> */
    private function parseAssignments(): array
    {
        $out = [];
        while (!$this->check(TokenType::RBrace)) {
            $key = $this->expect(TokenType::Ident)->value;
            $this->expect(TokenType::Equals);
            $out[$key] = $this->parseValue();
        }
        return $out;
    }

    /** @return array<int|string,mixed> */
    private function parseArgList(): array
    {
        $args = [];
        $idx = 0;
        if ($this->check(TokenType::RParen)) {
            return $args;
        }
        while (true) {
            if ($this->check(TokenType::Ident) && $this->peekType(1) === TokenType::Colon) {
                $key = $this->advance()->value;
                $this->advance();
                $args[$key] = $this->parseValue();
            } else {
                $args[$idx++] = $this->parseValue();
            }
            if (!$this->match(TokenType::Comma)) {
                break;
            }
        }
        return $args;
    }

    private function parseValue(): mixed
    {
        $tok = $this->advance();
        switch ($tok->type) {
            case TokenType::String:
                return $tok->value;
            case TokenType::Number:
                return str_contains($tok->value, '.') ? (float) $tok->value : (int) $tok->value;
            case TokenType::True:
                return true;
            case TokenType::False:
                return false;
            case TokenType::Null:
                return null;
            case TokenType::LBracket:
                $items = [];
                if (!$this->check(TokenType::RBracket)) {
                    while (true) {
                        $items[] = $this->parseValue();
                        if (!$this->match(TokenType::Comma)) {
                            break;
                        }
                    }
                }
                $this->expect(TokenType::RBracket);
                return $items;
            case TokenType::Ident:
                if ($this->match(TokenType::LParen)) {
                    $args = [];
                    if (!$this->check(TokenType::RParen)) {
                        while (true) {
                            $args[] = $this->parseValue();
                            if (!$this->match(TokenType::Comma)) {
                                break;
                            }
                        }
                    }
                    $this->expect(TokenType::RParen);
                    return new Invocation($tok->value, $args);
                }
                return $tok->value;
            default:
                throw new ParseException("Unexpected value token '{$tok->value}'", $tok->line);
        }
    }

    private function expect(TokenType $t): Token
    {
        $tok = $this->advance();
        if ($tok->type !== $t) {
            throw new ParseException("Expected {$t->name} but got {$tok->type->name} '{$tok->value}'", $tok->line);
        }
        return $tok;
    }

    private function match(TokenType $t): bool
    {
        if ($this->check($t)) {
            $this->advance();
            return true;
        }
        return false;
    }

    private function check(TokenType $t): bool
    {
        return $this->tokens[$this->pos]->type === $t;
    }

    private function peekType(int $offset): TokenType
    {
        return ($this->tokens[$this->pos + $offset] ?? array_last($this->tokens))->type;
    }

    private function advance(): Token
    {
        return $this->tokens[$this->pos++];
    }

    private function atEnd(): bool
    {
        return $this->tokens[$this->pos]->type === TokenType::Eof;
    }
}
