<?php

namespace BrevoScoped\StubTests\Tools;

require_once 'ModelAutoloader.php';
ModelAutoloader::register();
use BrevoScoped\StubTests\TestData\Providers\ReflectionStubsSingleton;
$reflectionFileName = $argv[1];
if (file_exists($reflectionFileName)) {
    unlink($reflectionFileName);
}
file_put_contents(__DIR__ . "/../../{$reflectionFileName}", serialize(ReflectionStubsSingleton::getReflectionStubs()));
