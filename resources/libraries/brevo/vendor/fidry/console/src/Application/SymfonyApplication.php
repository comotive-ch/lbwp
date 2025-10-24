<?php

/*
 * This file is part of the Fidry\Console package.
 *
 * (c) Théo FIDRY <theo.fidry@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
declare (strict_types=1);
namespace BrevoScoped\Fidry\Console\Application;

use BrevoScoped\Fidry\Console\Application\SymfonyApplication as PreviousSymfonyApplication;
use BrevoScoped\Fidry\Console\Bridge\Application\SymfonyApplication as BridgeSymfonyApplication;
use BrevoScoped\Fidry\Console\Deprecation;
use function class_alias;
class_alias(BridgeSymfonyApplication::class, PreviousSymfonyApplication::class);
Deprecation::classRenamed(PreviousSymfonyApplication::class, BridgeSymfonyApplication::class, '0.5');
