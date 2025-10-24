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
namespace BrevoScoped\Humbug\PhpScoper\Scoper\Factory;

use BrevoScoped\Humbug\PhpScoper\Configuration\Configuration;
use BrevoScoped\Humbug\PhpScoper\Scoper\Scoper;
use BrevoScoped\Humbug\PhpScoper\Symbol\SymbolsRegistry;
use BrevoScoped\PhpParser\PhpVersion;
interface ScoperFactory
{
    public function createScoper(Configuration $configuration, SymbolsRegistry $symbolsRegistry, ?PhpVersion $phpVersion = null): Scoper;
}
