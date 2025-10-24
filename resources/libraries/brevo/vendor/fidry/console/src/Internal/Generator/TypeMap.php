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
namespace BrevoScoped\Fidry\Console\Internal\Generator;

use BrevoScoped\Fidry\Console\Internal\Type\BooleanType;
use BrevoScoped\Fidry\Console\Internal\Type\FloatType;
use BrevoScoped\Fidry\Console\Internal\Type\InputType;
use BrevoScoped\Fidry\Console\Internal\Type\ListType;
use BrevoScoped\Fidry\Console\Internal\Type\NaturalType;
use BrevoScoped\Fidry\Console\Internal\Type\NonEmptyListType;
use BrevoScoped\Fidry\Console\Internal\Type\NonEmptyStringType;
use BrevoScoped\Fidry\Console\Internal\Type\NullableType;
use BrevoScoped\Fidry\Console\Internal\Type\NullOrNonEmptyStringType;
use BrevoScoped\Fidry\Console\Internal\Type\PositiveIntegerType;
use BrevoScoped\Fidry\Console\Internal\Type\RawType;
use BrevoScoped\Fidry\Console\Internal\Type\StringType;
use BrevoScoped\Fidry\Console\Internal\Type\UntrimmedStringType;
use function array_merge;
/**
 * @private
 */
final class TypeMap
{
    private function __construct()
    {
    }
    /**
     * @psalm-suppress LessSpecificReturnStatement,MoreSpecificReturnType
     *
     * @return non-empty-list<InputType>
     */
    public static function provideTypes(): array
    {
        $baseTypes = [BooleanType::class, NaturalType::class, PositiveIntegerType::class, FloatType::class, StringType::class, NonEmptyStringType::class, UntrimmedStringType::class];
        $types = [self::createTypes(RawType::class, \false, \false)];
        foreach ($baseTypes as $baseType) {
            $types[] = self::createTypes($baseType, \true, \true);
        }
        $types[] = self::createTypes(NullOrNonEmptyStringType::class, \false, \true);
        return array_merge(...$types);
    }
    /**
     * @param class-string<InputType> $typeClassName
     */
    private static function createTypes(string $typeClassName, bool $nullable, bool $list): array
    {
        $types = [$type = new $typeClassName()];
        if ($nullable) {
            $types[] = new NullableType($type);
        }
        if ($list) {
            $types[] = new ListType($type);
            $types[] = new NonEmptyListType($type);
        }
        return $types;
    }
}
