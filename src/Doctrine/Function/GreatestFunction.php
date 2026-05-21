<?php

declare(strict_types=1);

namespace App\Doctrine\Function;

use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\AST\Node;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\TokenType;

final class GreatestFunction extends FunctionNode
{
    public Node|string $firstArg;
    public Node|string $secondArg;

    public function getSql(SqlWalker $sqlWalker): string
    {
        return sprintf(
            'GREATEST(%s, %s)',
            $sqlWalker->walkSimpleArithmeticExpression($this->firstArg),
            $sqlWalker->walkSimpleArithmeticExpression($this->secondArg),
        );
    }

    public function parse(Parser $parser): void
    {
        $parser->match(TokenType::T_IDENTIFIER);
        $parser->match(TokenType::T_OPEN_PARENTHESIS);
        $this->firstArg = $parser->SimpleArithmeticExpression();
        $parser->match(TokenType::T_COMMA);
        $this->secondArg = $parser->SimpleArithmeticExpression();
        $parser->match(TokenType::T_CLOSE_PARENTHESIS);
    }
}
