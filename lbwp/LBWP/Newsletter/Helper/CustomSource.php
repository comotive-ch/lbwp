<?php

namespace LBWP\Newsletter\Helper;

use ComotiveNL\Standard\ContentSource\WordPressContentSource;
use ComotiveNL\Newsletter\Editor\EditorDynamicSection;
use ComotiveNL\Newsletter\Editor\Editor;
use ComotiveNL\Starter\Newsletter\LayoutElement\StarterContentLayoutElement;
use ComotiveNL\Starter2\Newsletter\LayoutElement\StarterContentLayoutElement as StarterContentLayoutElement2;
use ComotiveNL\Starter\Newsletter\LayoutElement\StarterMultiColumnsContentLayoutElement;
use ComotiveNL\Starter2\Newsletter\LayoutElement\StarterTwoColumnsLayoutElement;

/**
 * Helpers to add custom sources
 * @package LBWP\Newsletter\Helper
 * @author Michael Sebel <michael@comotive.ch>
 */
class CustomSource {

  /**
   * @param string $type the pos ttype
   * @param string $title title above all items
   * @param int $order ordering
   * @param bool $addStarter1Layouts adds layouts automatically if true
   * @param bool $addStarter2Layouts adds layouts automatically if true
   */
  public static function addPostTypeSource($type, $title, $order, $addStarter1Layouts = true, $addStarter2Layouts = false)
  {
    $core = \CMNL::getNewsletterCore();
    // WordPress Posts
    $customPosts = new WordPressContentSource($type, $title);
    $core->registerContentSource($customPosts);
    $customSourceKey = 'content-source-wordpress-' . $type;

    $section = new EditorDynamicSection(
      'wordpress-type-' . $type,
      Editor::SIDE_LEFT,
      $title,
      $customSourceKey,
      $order
    );
    $core->addEditorSection($section);

    if ($addStarter1Layouts) {
      $layoutElement = new StarterContentLayoutElement();
      $core->registerLayoutElement($layoutElement);
      $core->addLayoutDefinition($layoutElement->getKey(), $customSourceKey);
      $core->addLayoutDefinition($layoutElement->getKey(), 'free-article');

      $layoutElement = new StarterMultiColumnsContentLayoutElement();
      $core->registerLayoutElement($layoutElement);
      $core->addLayoutDefinition($layoutElement->getKey(), 'two-columns ' . $customSourceKey);
      $core->addLayoutDefinition($layoutElement->getKey(), 'three-columns ' . $customSourceKey);
    }

    if ($addStarter2Layouts) {
      $layoutElement = new StarterContentLayoutElement2();
      $core->registerLayoutElement($layoutElement);
      $core->addLayoutDefinition($layoutElement->getKey(), $customSourceKey);
      $core->addLayoutDefinition($layoutElement->getKey(), 'free-article');

      $layoutElement = new StarterTwoColumnsLayoutElement();
      $core->registerLayoutElement($layoutElement);
      $core->addLayoutDefinition($layoutElement->getKey(), 'two-columns ' . $customSourceKey);
    }
  }
} 