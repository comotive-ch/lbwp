<?php

namespace BrevoScoped;

/**
 * @since 8.4
 */
final class ReflectionConstant implements \Reflector
{
    public string $name;
    public function __construct(string $name)
    {
    }
    public function getName(): string
    {
    }
    public function getNamespaceName(): string
    {
    }
    public function getShortName(): string
    {
    }
    public function getValue(): mixed
    {
    }
    public function isDeprecated(): bool
    {
    }
    public function __toString(): string
    {
    }
}
/**
 * @since 8.4
 */
\class_alias('BrevoScoped\ReflectionConstant', 'ReflectionConstant', \false);
