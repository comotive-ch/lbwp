<?php

declare (strict_types=1);
namespace BrevoScoped\StubTests\Parsers\Visitors;

use BrevoScoped\StubTests\Model\StubsContainer;
class CoreStubASTVisitor extends ASTVisitor
{
    public function __construct(StubsContainer $stubs, array &$entitiesToUpdate)
    {
        parent::__construct($stubs, $entitiesToUpdate);
        $this->isStubCore = \true;
    }
}
