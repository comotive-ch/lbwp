<?php

declare (strict_types=1);
namespace BrevoScoped\StubTests\Parsers;

use BrevoScoped\PhpParser\Error;
use BrevoScoped\PhpParser\ErrorHandler;
class StubsParserErrorHandler implements ErrorHandler
{
    public function handleError(Error $error): void
    {
        $error->setRawMessage($error->getRawMessage() . "\n" . $error->getFile());
    }
}
