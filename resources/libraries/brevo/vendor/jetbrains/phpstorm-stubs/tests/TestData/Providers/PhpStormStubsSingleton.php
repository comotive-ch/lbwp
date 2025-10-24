<?php

declare (strict_types=1);
namespace BrevoScoped\StubTests\TestData\Providers;

use BrevoScoped\StubTests\Model\StubsContainer;
use BrevoScoped\StubTests\Parsers\StubParser;
class PhpStormStubsSingleton
{
    private static ?StubsContainer $phpstormStubs = null;
    public static function getPhpStormStubs(): StubsContainer
    {
        if (self::$phpstormStubs === null) {
            self::$phpstormStubs = StubParser::getPhpStormStubs();
        }
        return self::$phpstormStubs;
    }
}
