<?php

declare (strict_types=1);
namespace BrevoScoped\PhpParser\Node\Expr\BinaryOp;

use BrevoScoped\PhpParser\Node\Expr\BinaryOp;
class Mod extends BinaryOp
{
    public function getOperatorSigil(): string
    {
        return '%';
    }
    public function getType(): string
    {
        return 'Expr_BinaryOp_Mod';
    }
}
