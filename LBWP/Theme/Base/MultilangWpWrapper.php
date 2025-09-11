<?php

namespace LBWP\Theme\Base;

use LBWP\Util\Multilang;
use LBWP\Util\WordPress;

/**
 * Class MultilangWpWrapper
 *
 * This wrapper adds Multilang methods (implemented with Polylang) to the WpWrapper.
 *
 * @package LBWP\Theme\Base
 * @author Tom Forrer <tom.forrer@blogwerk.com>
 * @author Michael Sebel <michael@comotive.ch>
 */
class MultilangWpWrapper extends WpWrapper
{
  /**
   * @var string default language
   */
  protected $defaultLanguage = 'de';

  /**
   * Returns true if the Multilanguage plugin polylang is active
   * @return bool true if multilang is active
   */
  public function isMultilanguage()
  {
    return Multilang::isActive();
  }

  /**
   * @return string current language (iso code)
   */
  public function getCurrentLanguage()
  {
    if (!$this->isMultilanguage()) {
      return $this->defaultLanguage;
    }
    return Multilang::getCurrentLang();
  }

  /**
   * @return array all languages
   */
  public function getAllLanguages()
  {
    if (!$this->isMultilanguage()) {
      return array($this->defaultLanguage);
    }
    return Multilang::getAllLanguages();
  }

  /**
   * @return array language switcher urls
   */
  public function getLanguageSwitcherUrls()
  {
    $result = array();
    if (!$this->isMultilanguage()) {
      return $result;
    }
    $translations = Multilang::languageQuery(array(
      'hide_current' => 1,
      'raw' => 1
    ));
    foreach ($translations as $translation) {
      $result[$translation['slug']] = $translation['url'];
    }

    return $result;
  }
}
