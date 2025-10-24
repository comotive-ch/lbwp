<?php

declare (strict_types=1);
/*
 * This file is part of the humbug/php-scoper package.
 *
 * Copyright (c) 2017 Théo FIDRY <theo.fidry@gmail.com>,
 *                    Pádraic Brady <padraic.brady@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace BrevoScoped\Humbug\PhpScoper\PhpParser\NodeVisitor;

use BrevoScoped\Humbug\PhpScoper\PhpParser\Node\FullyQualifiedFactory;
use BrevoScoped\Humbug\PhpScoper\PhpParser\NodeVisitor\AttributeAppender\ParentNodeAppender;
use BrevoScoped\Humbug\PhpScoper\PhpParser\NodeVisitor\Resolver\IdentifierResolver;
use BrevoScoped\Humbug\PhpScoper\PhpParser\UnexpectedParsingScenario;
use BrevoScoped\Humbug\PhpScoper\Symbol\EnrichedReflector;
use BrevoScoped\Humbug\PhpScoper\Symbol\SymbolsRegistry;
use BrevoScoped\PhpParser\Node;
use BrevoScoped\PhpParser\Node\Arg;
use BrevoScoped\PhpParser\Node\Expr\FuncCall;
use BrevoScoped\PhpParser\Node\Identifier;
use BrevoScoped\PhpParser\Node\Name;
use BrevoScoped\PhpParser\Node\Name\FullyQualified;
use BrevoScoped\PhpParser\Node\Scalar\String_;
use BrevoScoped\PhpParser\Node\Stmt\Function_;
use BrevoScoped\PhpParser\NodeVisitorAbstract;
/**
 * Records the functions that need to be aliased.
 *
 * @private
 */
final class FunctionIdentifierRecorder extends NodeVisitorAbstract
{
    public function __construct(private readonly string $prefix, private readonly IdentifierResolver $identifierResolver, private readonly SymbolsRegistry $symbolsRegistry, private readonly EnrichedReflector $enrichedReflector)
    {
    }
    public function enterNode(Node $node): Node
    {
        if (!($node instanceof Identifier || $node instanceof Name || $node instanceof String_) || !ParentNodeAppender::hasParent($node)) {
            return $node;
        }
        $resolvedName = $this->retrieveResolvedName($node);
        if (null !== $resolvedName && $this->shouldBeAliased($node, $resolvedName)) {
            $this->symbolsRegistry->recordFunction($resolvedName, FullyQualifiedFactory::concat($this->prefix, $resolvedName));
        }
        return $node;
    }
    private function shouldBeAliased(Node $node, FullyQualified $resolvedName): bool
    {
        if ($this->enrichedReflector->isExposedFunction($resolvedName->toString())) {
            return \true;
        }
        // If is a function declaration, excluded global functions need to be
        // aliased since otherwise any usage without the FQCN in a namespace
        // will break. Indeed, previously it would work thanks to the function
        // PHP autoloading fallback mechanism, but now that the declaration is
        // namespaced because of the prefix, an alias is needed.
        return self::isFunctionDeclaration($node) && $this->enrichedReflector->belongsToGlobalNamespace($resolvedName->toString()) && $this->enrichedReflector->isFunctionExcluded($resolvedName->toString());
    }
    private function retrieveResolvedName(Node $node): ?FullyQualified
    {
        if ($node instanceof Identifier) {
            return $this->retrieveResolvedNameForIdentifier($node);
        }
        if ($node instanceof Name) {
            return $this->retrieveResolvedNameForFuncCall($node);
        }
        if ($node instanceof String_) {
            return $this->retrieveResolvedNameForString($node);
        }
        throw UnexpectedParsingScenario::create();
    }
    private function retrieveResolvedNameForIdentifier(Identifier $identifier): ?FullyQualified
    {
        $parent = ParentNodeAppender::getParent($identifier);
        if (!$parent instanceof Function_ || $identifier === $parent->returnType) {
            return null;
        }
        $resolvedName = $this->identifierResolver->resolveIdentifier($identifier);
        return $resolvedName instanceof FullyQualified ? $resolvedName : null;
    }
    private function retrieveResolvedNameForFuncCall(Name $name): ?FullyQualified
    {
        $parent = ParentNodeAppender::getParent($name);
        if (!$parent instanceof FuncCall) {
            return null;
        }
        return $name instanceof FullyQualified ? $name : null;
    }
    private function retrieveResolvedNameForString(String_ $string): ?FullyQualified
    {
        $stringParent = ParentNodeAppender::getParent($string);
        if (!$stringParent instanceof Arg) {
            return null;
        }
        $argParent = ParentNodeAppender::getParent($stringParent);
        if (!$argParent instanceof FuncCall) {
            return null;
        }
        if (!self::isFunctionExistsCall($argParent)) {
            return null;
        }
        $resolvedName = $this->identifierResolver->resolveString($string);
        return $resolvedName instanceof FullyQualified ? $resolvedName : null;
    }
    private static function isFunctionExistsCall(FuncCall $node): bool
    {
        $name = $node->name;
        return $name instanceof Name && $name->toString() === 'function_exists';
    }
    private static function isFunctionDeclaration(Node $node): bool
    {
        if (!$node instanceof Identifier) {
            return \false;
        }
        $parentNode = ParentNodeAppender::getParent($node);
        return $parentNode instanceof Function_;
    }
}
