<?php

declare (strict_types=1);
namespace BrevoScoped\PhpParser\ErrorHandler;

use BrevoScoped\PhpParser\Error;
use BrevoScoped\PhpParser\ErrorHandler;
/**
 * Error handler that handles all errors by throwing them.
 *
 * This is the default strategy used by all components.
 */
class Throwing implements ErrorHandler
{
    public function handleError(Error $error): void
    {
        throw $error;
    }
}
