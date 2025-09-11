<?php

namespace BrevoScoped\StubTests\Model;

use Exception;
use BrevoScoped\phpDocumentor\Reflection\DocBlock\Tags\Deprecated;
use BrevoScoped\phpDocumentor\Reflection\DocBlock\Tags\Link;
use BrevoScoped\phpDocumentor\Reflection\DocBlock\Tags\Param;
use BrevoScoped\phpDocumentor\Reflection\DocBlock\Tags\Return_;
use BrevoScoped\phpDocumentor\Reflection\DocBlock\Tags\See;
use BrevoScoped\phpDocumentor\Reflection\DocBlock\Tags\Since;
use BrevoScoped\phpDocumentor\Reflection\DocBlock\Tags\Template;
use BrevoScoped\phpDocumentor\Reflection\DocBlock\Tags\Var_;
use BrevoScoped\PhpParser\Node;
use BrevoScoped\StubTests\Model\Tags\RemovedTag;
use BrevoScoped\StubTests\Parsers\DocFactoryProvider;
trait PHPDocElement
{
    /**
     * @var Link[]
     */
    public $links = [];
    /**
     * @var string
     */
    public $phpdoc = '';
    /**
     * @var See[]
     */
    public $see = [];
    /**
     * @var Since[]
     */
    public $sinceTags = [];
    /**
     * @var Deprecated[]
     */
    public $deprecatedTags = [];
    /**
     * @var RemovedTag[]
     */
    public $removedTags = [];
    /**
     * @var Param[]
     */
    public $paramTags = [];
    /**
     * @var Return_[]
     */
    public $returnTags = [];
    /**
     * @var Var_[]
     */
    public $varTags = [];
    /**
     * @var string[]
     */
    public $tagNames = [];
    /**
     * @var bool
     */
    public $hasInheritDocTag = \false;
    /**
     * @var bool
     */
    public $hasInternalMetaTag = \false;
    /**
     * @var list<Template>
     */
    public $templateTypes = [];
    protected function collectTags(Node $node)
    {
        if ($node->getDocComment() !== null) {
            try {
                $text = $node->getDocComment()->getText();
                $text = preg_replace("/int\\<\\w+,\\s*\\w+\\>/", "int", $text);
                $text = preg_replace("/callable\\(\\w+(,\\s*\\w+)*\\)(:\\s*\\w*)?/", "callable", $text);
                $this->phpdoc = $text;
                $phpDoc = DocFactoryProvider::getDocFactory()->create($text);
                $tags = $phpDoc->getTags();
                foreach ($tags as $tag) {
                    $this->tagNames[] = $tag->getName();
                }
                $this->paramTags = $phpDoc->getTagsByName('param');
                $this->returnTags = $phpDoc->getTagsByName('return');
                $this->varTags = $phpDoc->getTagsByName('var');
                $this->links = $phpDoc->getTagsByName('link');
                $this->see = $phpDoc->getTagsByName('see');
                $this->sinceTags = $phpDoc->getTagsByName('since');
                $this->deprecatedTags = $phpDoc->getTagsByName('deprecated');
                $this->removedTags = $phpDoc->getTagsByName('removed');
                $this->hasInternalMetaTag = $phpDoc->hasTag('meta');
                $this->hasInheritDocTag = $phpDoc->hasTag('inheritdoc') || $phpDoc->hasTag('inheritDoc') || stripos($phpDoc->getSummary(), 'inheritdoc') > 0;
                $this->templateTypes += $phpDoc->getTagsByName('template');
            } catch (Exception $e) {
                $this->parseError = $e;
            }
        }
    }
}
