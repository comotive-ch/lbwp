<?php

namespace BrevoScoped\StubTests\TestData\Providers;

use BrevoScoped\StubTests\Model\StubsContainer;
use BrevoScoped\StubTests\Parsers\PHPReflectionParser;
class ReflectionStubsSingleton
{
    /**
     * @var StubsContainer|null
     */
    private static $reflectionStubs;
    /**
     * @return StubsContainer
     */
    public static function getReflectionStubs()
    {
        if (self::$reflectionStubs === null) {
            self::$reflectionStubs = PHPReflectionParser::getStubs();
        }
        return self::$reflectionStubs;
    }
}
