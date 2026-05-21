<?php

declare(strict_types=1);

namespace Polidog\Tehilim\Schema;

enum TokenType
{
    case Ident;
    case String;
    case Number;
    case True;
    case False;
    case Null;
    case LBrace;
    case RBrace;
    case LBracket;
    case RBracket;
    case LParen;
    case RParen;
    case At;
    case DoubleAt;
    case Question;
    case Comma;
    case Colon;
    case Equals;
    case Newline;
    case Eof;
}
