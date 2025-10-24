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

use BrevoScoped\Fidry\Console\Bridge\Command\ReversedSymfonyCommand as BridgeReversedSymfonyCommand;
use BrevoScoped\Fidry\Console\Command\ReversedSymfonyCommand as PreviousReversedSymfonyCommand;
use BrevoScoped\Fidry\Console\Deprecation;
use function class_alias;
class_alias(BridgeReversedSymfonyCommand::class, PreviousReversedSymfonyCommand::class);
Deprecation::classRenamed(PreviousReversedSymfonyCommand::class, BridgeReversedSymfonyCommand::class, '0.5');
