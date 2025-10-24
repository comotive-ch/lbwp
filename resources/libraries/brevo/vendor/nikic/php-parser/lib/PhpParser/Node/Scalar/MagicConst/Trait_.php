<?php

declare (strict_types=1);
namespace BrevoScoped\PhpParser\Node\Scalar\MagicConst;

use BrevoScoped\PhpParser\Node\Scalar\MagicConst;
class Trait_ extends MagicConst
{
    public function getName(): string
    {
        return '__TRAIT__';
    }
    public function getType(): string
    {
        return 'Scalar_MagicConst_Trait';
    }
}
