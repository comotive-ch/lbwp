<?php

namespace LBWP\Module\General\Multilang;

use LBWP\Module\BaseSingleton;
use LBWP\Util\Multilang;
use LBWP\Util\Strings;

/**
 * Option bridge for multilang module
 * @package LBWP\Module\General\Multilang
 * @author Michael Sebel <michael@comotive.ch>
 */
class OptionBridge extends BaseSingleton
{
  /**
   * @var OptionBridge the instance reference for performance
   */
  public static $bridge = NULL;
  /**
   * Executed on loading of the instance, for initialization / registration of filters
   */
  protected function run()
  {
    self::$bridge = parent::getInstance();
  }

  /**
   * Make the default LBWP and WordPress options multilanguage capable
   */
  public function addDefaultOptions()
  {
    self::add('blogname');
    self::add('blogdescription');
    self::add('LbwpConfig');
    self::add('automaticNewsletter');
  }

  /**
   * Set an option to be multilang capable
   * @param string $optionKey the
   */
  public static function add($optionKey)
  {
    // Filter to get the option correctly (default or language option
    add_filter('option_' . $optionKey, function ($optionValue) use ($optionKey) {
      return OptionBridge::$bridge->getOption($optionKey, $optionValue);
    });

    // Filter to set the option properly
    add_filter('pre_update_option_' . $optionKey, function ($optionValue) use ($optionKey) {
      return OptionBridge::$bridge->updateOption($optionKey, $optionValue);
    });
  }

  /**
   * Filter callback to save the multilanguage option with the correct key
   * @param string $optionKey
   * @param mixed $optionValue
   * @return mixed option value
   */
  protected function updateOption($optionKey, $optionValue)
  {
    $lang = Multilang::getCurrentLang();
    // Save the language, if a language param is set
    if (strlen($lang) >= 2) {
      update_option($optionKey . '_' . $lang, $optionValue);
    }

    // return the option value untouched, otherwise update_option/add_option will store null in the non-multilingual optionkey
    return $optionValue;
  }

  /**
   * Filter callback to load the correct multilanguage option
   * @param string $optionKey
   * @param mixed $optionValue
   * @return mixed
   */
  protected function getOption($optionKey, $optionValue)
  {
    $lang = Multilang::getCurrentLang();

    // sometimes we need this filter to work during plugins_loaded hook, i.e. early option loading like wp-seo yoast
    if ($lang === false || strlen($lang) == 0) {
      $lang = Multilang::getDefaultLang();
    }

    // Try to get the main version
    $multilangValue = get_option($optionKey . '_' . $lang, null);
    if ($multilangValue !== null) {
      return Strings::deepStripSlashes($multilangValue);
    }

    // Return the default, if set
    return $optionValue;
  }
} 