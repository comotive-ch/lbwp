<?php

declare (strict_types=1);
namespace BrevoScoped\PhpParser\Node\Expr\Cast;

use BrevoScoped\PhpParser\Node\Expr\Cast;
class String_ extends Cast
{
    public function getType(): string
    {
        return 'Expr_Cast_String';
    }
}
