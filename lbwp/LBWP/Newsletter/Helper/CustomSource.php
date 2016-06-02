<?php

namespace LBWP\Newsletter\Helper;

use ComotiveNL\Standard\ContentSource\WordPressContentSource;
use ComotiveNL\Newsletter\Editor\EditorDynamicSection;
use ComotiveNL\Newsletter\Editor\Editor;
use ComotiveNL\Starter\Newsletter\LayoutElement\StarterContentLayoutElement;
use ComotiveNL\Starter\Newsletter\LayoutElement\StarterMultiColumnsContentLayoutElement;

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
   * @param bool $addStarterLayouts adds layouts automatically if true
   */
  public static function addPostTypeSource($type, $title, $order, $addStarterLayouts = true)
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

    if ($addStarterLayouts) {
      $layoutElement = new StarterContentLayoutElement();
      $core->registerLayoutElement($layoutElement);
      $core->addLayoutDefinition($layoutElement->getKey(), $customSourceKey);
      $core->addLayoutDefinition($layoutElement->getKey(), 'free-article');

      $layoutElement = new StarterMultiColumnsContentLayoutElement();
      $core->registerLayoutElement($layoutElement);
      $core->addLayoutDefinition($layoutElement->getKey(), 'two-columns ' . $customSourceKey);
      $core->addLayoutDefinition($layoutElement->getKey(), 'three-columns ' . $customSourceKey);
    }
  }
} 