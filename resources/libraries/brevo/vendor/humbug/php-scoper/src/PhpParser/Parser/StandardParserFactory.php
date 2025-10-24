<?php

declare (strict_types=1);
/*
 * This file is part of the humbug/php-scoper package.
 *
 * Copyright (c) 2017 Théo FIDRY <theo.fidry@gmail.com>,
 *                    Pádraic Brady <padraic.brady@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace BrevoScoped\Humbug\PhpScoper\PhpParser\Parser;

use BrevoScoped\PhpParser\Lexer;
use BrevoScoped\PhpParser\Lexer\Emulative;
use BrevoScoped\PhpParser\Parser;
use BrevoScoped\PhpParser\Parser\Php7;
use BrevoScoped\PhpParser\Parser\Php8;
use BrevoScoped\PhpParser\PhpVersion;
final class StandardParserFactory implements ParserFactory
{
    public function createParser(?PhpVersion $phpVersion = null): Parser
    {
        $version = $phpVersion ?? PhpVersion::getHostVersion();
        $lexer = $version->isHostVersion() ? new Lexer() : new Emulative($version);
        return $version->id >= 80000 ? new Php8($lexer, $version) : new Php7($lexer, $version);
    }
}
