<?php

namespace BrevoScoped\StubTests\Parsers;

use ReflectionClass;
use ReflectionEnum;
use ReflectionFunction;
use BrevoScoped\StubTests\Model\CommonUtils;
use BrevoScoped\StubTests\Model\PHPClass;
use BrevoScoped\StubTests\Model\PHPConstant;
use BrevoScoped\StubTests\Model\PHPDefineConstant;
use BrevoScoped\StubTests\Model\PHPEnum;
use BrevoScoped\StubTests\Model\PHPFunction;
use BrevoScoped\StubTests\Model\PHPInterface;
use BrevoScoped\StubTests\Model\StubsContainer;
class PHPReflectionParser
{
    /**
     * @return StubsContainer
     * @throws \ReflectionException
     */
    public static function getStubs()
    {
        if (file_exists(__DIR__ . '/../../ReflectionData.json')) {
            $stubs = unserialize(file_get_contents(__DIR__ . '/../../ReflectionData.json'));
        } else {
            $stubs = new StubsContainer();
            $jsonData = json_decode(file_get_contents(__DIR__ . '/../TestData/mutedProblems.json'));
            $const_groups = get_defined_constants(\true);
            unset($const_groups['user']);
            $const_groups = CommonUtils::flattenArray($const_groups, \true);
            foreach ($const_groups as $name => $value) {
                if (class_exists('\ReflectionConstant')) {
                    $constant = (new PHPConstant())->readObjectFromReflection(new \ReflectionConstant($name));
                } else {
                    $constant = (new PHPDefineConstant())->readObjectFromReflection([$name, $value]);
                }
                $constant->readMutedProblems($jsonData->constants);
                $stubs->addConstant($constant);
            }
            foreach (get_defined_functions()['internal'] as $function) {
                $reflectionFunction = new ReflectionFunction($function);
                $phpFunction = (new PHPFunction())->readObjectFromReflection($reflectionFunction);
                $phpFunction->readMutedProblems($jsonData->functions);
                $stubs->addFunction($phpFunction);
            }
            foreach (get_declared_classes() as $clazz) {
                $reflectionClass = new ReflectionClass($clazz);
                if ($reflectionClass->isInternal()) {
                    if (method_exists($reflectionClass, 'isEnum') && $reflectionClass->isEnum()) {
                        $enum = (new PHPEnum())->readObjectFromReflection(new ReflectionEnum($clazz));
                        $enum->readMutedProblems($jsonData->enums);
                        $stubs->addEnum($enum);
                    } else {
                        $class = (new PHPClass())->readObjectFromReflection($reflectionClass);
                        $class->readMutedProblems($jsonData->classes);
                        $stubs->addClass($class);
                    }
                }
            }
            foreach (get_declared_interfaces() as $interface) {
                $reflectionInterface = new ReflectionClass($interface);
                if ($reflectionInterface->isInternal()) {
                    $phpInterface = (new PHPInterface())->readObjectFromReflection($reflectionInterface);
                    $phpInterface->readMutedProblems($jsonData->interfaces);
                    $stubs->addInterface($phpInterface);
                }
            }
        }
        return $stubs;
    }
}
