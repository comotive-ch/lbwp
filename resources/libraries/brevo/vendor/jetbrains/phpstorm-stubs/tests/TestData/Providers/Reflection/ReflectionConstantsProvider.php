<?php

declare (strict_types=1);
namespace BrevoScoped\StubTests\TestData\Providers\Reflection;

use Generator;
use BrevoScoped\StubTests\Model\PHPClass;
use BrevoScoped\StubTests\Model\PHPConstant;
use BrevoScoped\StubTests\Model\PHPEnum;
use BrevoScoped\StubTests\Model\PHPInterface;
use BrevoScoped\StubTests\Model\StubProblemType;
use BrevoScoped\StubTests\TestData\Providers\EntitiesFilter;
use BrevoScoped\StubTests\TestData\Providers\ReflectionStubsSingleton;
class ReflectionConstantsProvider
{
    public static function constantProvider(): ?Generator
    {
        $filteredConstants = EntitiesFilter::getFiltered(ReflectionStubsSingleton::getReflectionStubs()->getConstants());
        if (empty($filteredConstants)) {
            yield [null];
        } else {
            foreach ($filteredConstants as $constant) {
                yield "constant {$constant->id}" => [$constant->id];
            }
        }
    }
    public static function constantValuesProvider(): ?Generator
    {
        $filteredConstants = self::getFilteredConstants();
        if (empty($filteredConstants)) {
            yield [null];
        } else {
            foreach ($filteredConstants as $constant) {
                yield "constant {$constant->name}" => [$constant];
            }
        }
    }
    public static function classConstantProvider(): ?Generator
    {
        $classes = ReflectionStubsSingleton::getReflectionStubs()->getClasses();
        $filteredClasses = EntitiesFilter::getFiltered($classes);
        $array = array_filter(array_map(fn(PHPClass $class) => EntitiesFilter::getFiltered($class->constants), $filteredClasses), fn($arr) => !empty($arr));
        if (empty($array)) {
            yield [null, null];
        } else {
            foreach ($array as $constants) {
                foreach ($constants as $constant) {
                    yield "constant {$constant->parentId}::{$constant->name}" => [$constant->parentId, $constant->name];
                }
            }
        }
    }
    public static function interfaceConstantProvider(): ?Generator
    {
        $interfaces = ReflectionStubsSingleton::getReflectionStubs()->getInterfaces();
        $filteredClasses = EntitiesFilter::getFiltered($interfaces);
        $array = array_filter(array_map(fn(PHPInterface $class) => EntitiesFilter::getFiltered($class->constants), $filteredClasses), fn($arr) => !empty($arr));
        if (empty($array)) {
            yield [null, null];
        } else {
            foreach ($array as $constants) {
                foreach ($constants as $constant) {
                    yield "constant {$constant->parentId}::{$constant->name}" => [$constant->parentId, $constant->name];
                }
            }
        }
    }
    public static function enumConstantProvider(): ?Generator
    {
        $enums = ReflectionStubsSingleton::getReflectionStubs()->getEnums();
        $filteredClasses = EntitiesFilter::getFiltered($enums);
        $array = array_filter(array_map(fn(PHPEnum $class) => EntitiesFilter::getFiltered($class->constants), $filteredClasses), fn($arr) => !empty($arr));
        if (empty($array)) {
            yield [null, null];
        } else {
            foreach ($array as $constants) {
                foreach ($constants as $constant) {
                    yield "constant {$constant->parentId}::{$constant->name}" => [$constant->parentId, $constant->name];
                }
            }
        }
    }
    public static function enumCaseProvider(): ?Generator
    {
        $enums = ReflectionStubsSingleton::getReflectionStubs()->getEnums();
        $filteredClasses = EntitiesFilter::getFiltered($enums);
        $array = array_filter(array_map(fn(PHPEnum $class) => EntitiesFilter::getFiltered($class->enumCases), $filteredClasses), fn($arr) => !empty($arr));
        if (empty($array)) {
            yield [null, null];
        } else {
            foreach ($array as $constants) {
                foreach ($constants as $constant) {
                    yield "constant {$constant->parentId}::{$constant->name}" => [$constant->parentId, $constant->name];
                }
            }
        }
    }
    public static function classConstantValuesProvider(): ?Generator
    {
        $classesAndInterfaces = ReflectionStubsSingleton::getReflectionStubs()->getClasses() + ReflectionStubsSingleton::getReflectionStubs()->getInterfaces();
        foreach (EntitiesFilter::getFiltered($classesAndInterfaces) as $class) {
            foreach (self::getFilteredConstants($class) as $constant) {
                yield "constant {$class->name}::{$constant->name}" => [$class, $constant];
            }
        }
    }
    public static function getFilteredConstants(PHPInterface|PHPClass|null $class = null): array
    {
        if ($class === null) {
            $allConstants = ReflectionStubsSingleton::getReflectionStubs()->getConstants();
        } else {
            $allConstants = $class->constants;
        }
        /** @var PHPConstant[] $resultArray */
        $resultArray = [];
        foreach (EntitiesFilter::getFiltered($allConstants, null, StubProblemType::WRONG_CONSTANT_VALUE) as $constant) {
            $resultArray[] = $constant;
        }
        return $resultArray;
    }
}
