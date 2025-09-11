<?php

namespace LBWP\Util;

/**
 * Allows to change texts from translation files
 * @package LBWP\Util
 * @author Michael Sebel <michael@comotive.ch>
 */
class TranslationOverride
{
  /**
   * @var array
   */
  protected static $translations = array();

  public static function init($args)
  {
    self::$translations = $args['fixed'];
    // TODO someday we load additional translations from DB here

    // Add the filter to change texts
    add_filter('gettext', array('\LBWP\Util\TranslationOverride', 'translate'), 10, 3);
  }

  /**
   * @param $translation
   * @param $original
   * @param $domain
   * @return mixed
   */
  public static function translate($translation, $original, $domain)
  {
    if (isset(self::$translations[$domain])) {
      foreach (self::$translations[$domain] as $search => $override) {
        if ($search === $translation) {
          return $override;
        }
      }
    }

    return $translation;
  }
}