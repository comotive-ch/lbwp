<?php

declare (strict_types=1);
namespace BrevoScoped\PhpParser\Node\Scalar\MagicConst;

use BrevoScoped\PhpParser\Node\Scalar\MagicConst;
class Line extends MagicConst
{
    public function getName(): string
    {
        return '__LINE__';
    }
    public function getType(): string
    {
        return 'Scalar_MagicConst_Line';
    }
}
