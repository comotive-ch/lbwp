<?php

namespace LBWP\Util;

/**
 * Multilanguage wrapper and helper class
 * @package LBWP\Util
 * @author Michael Sebel <michael@comotive.ch>
 */
class Multilang
{
  /**
   * @var bool tells if multilang functionality is in use
   */
  protected static $active = NULL;
  /**
   * @var string filter name to change translation urls
   */
  public static $translationUrlFilter = 'pll_translation_url';

  /**
   * @return bool true, if multilang plugin is active
   */
  public static function isActive()
  {
    if (self::$active == NULL) {
      self::$active = WordPress::isPluginActive('polylang/polylang.php');
    }

    return self::$active;
  }

  /**
   * @param string $field the field to get
   * @return string current language code
   */
  public static function getCurrentLang($field = 'slug')
  {
    if (self::isActive()) {
      if (!pll_current_language($field)) {
        return pll_default_language($field);
      } else {
        return pll_current_language($field);
      }
    }

    return false;
  }

  /**
   * @param string $field the field to get
   * @return string|bool current language code
   */
  public static function getBackendLang($field = 'slug')
  {
    if (self::isActive()) {
      return pll_current_language($field);
    }

    return false;
  }

  /**
   * @return string the default language
   */
  public static function getDefaultLang()
  {
    if (self::isActive()) {
      return pll_default_language();
    }

    return false;
  }

  /**
   * @param string $slug the language slug (if not given, take current)
   * @return string the corresponding home url of the language
   */
  public static function getHomeUrl($slug = '')
  {
    if (self::isActive()) {
      if (strlen($slug) == 0) {
        $slug = self::getCurrentLang();
      }
      return pll_home_url($slug);
    }

    return get_home_url();
  }

  /**
   * @param int $postId the post id of any language
   * @param string $lang the language rep of postID you want
   * @return int the id of the post
   */
  public static function getPostIdInLang($postId, $lang)
  {
    if (self::isActive()) {
      return pll_get_post($postId, $lang);
    }

    return $postId;
  }

  /**
   * @return string current posts language code
   */
  public static function getCurrentPostLang()
  {
    if (self::isActive()) {
      $post = WordPress::guessCurrentPost();
      return pll_get_post_language($post->ID);
    }

    return false;
  }

  /**
   * @param int $postId the post
   * @return string post language code
   */
  public static function getPostLang($postId)
  {
    if (self::isActive()) {
      return pll_get_post_language($postId);
    }

    return false;
  }

  /**
   * @return array all languages
   */
  public static function getAllLanguages()
  {
    if (self::isActive()) {
      return pll_languages_list();
    }

    return array();
  }

  /**
   * @return array key value pair of language code and name
   */
  public static function getLanguagesKeyValue()
  {
    if (self::isActive()) {
      $list = array();
      foreach (pll_languages_list() as $language) {
        $list[$language] = self::getLanguageName($language);
      }
      return $list;
    }

    return array();
  }

  /**
   * @param string $slug a language slug
   * @return string the actual language name
   */
  public static function getLanguageName($slug)
  {
    if (self::isActive()) {
      global $polylang;
      foreach ($polylang->get_languages_list() as $language) {
        if ($language->slug == $slug) {
          return $language->name;
        }
      }
    }

    return '';
  }

  /**
   * @param int $termId the term id
   * @param string $language the language desired
   * @return array|null the term in the other language
   */
  public static function getTerm($termId, $language)
  {
    if (self::isActive()) {
      return pll_get_term($termId, $language);
    }

    return array();
  }

  /**
   * @param array $args query arguments
   * @return array language query results
   */
  public static function languageQuery($args)
  {
    if (self::isActive()) {
      return pll_the_languages($args);
    }

    return array();
  }

  /**
   * @param array $args the args you would give to pll_the_languages
   * @return array list of elements to show
   */
  public static function getLanguageSwitcherData($args = array('raw' => 1))
  {
    if (self::isActive()) {
      return pll_the_languages($args);
    }

    return false;
  }

  /**
   * @param string $lang
   * @param array $args
   * @return int
   */
  public static function countPosts($lang, $args = array())
  {
    if (self::isActive()) {
      return pll_count_posts($lang, $args);
    }

    return 0;
  }

  /**
   * @param string $location base name
   * @param string $lang translation that is needed
   * @return string location name in requested language
   */
  public static function getMenuLocationTranslation($location, $lang)
  {
    $locations = get_nav_menu_locations();
    $guessLocation = $location . '___' . $lang;

    if (isset($locations[$guessLocation])) {
      return $guessLocation;
    }

    // If there is no language equiv, it is the standard and not renamed by polylang
    return $location;
  }

  /**
   * Like register_sidebar für multilang sidebars
   * @param string $sidebar the sidebar object
   */
  public static function registerSidebar($sidebar)
  {
    if (self::isActive()) {
      foreach (self::getAllLanguages() as $language) {
        $muSidebar = $sidebar;
        $muSidebar['name'] .= ' (' . strtoupper($language) . ')';
        $muSidebar['id'] .= '-' . $language;
        register_sidebar($muSidebar);
      }
    } else {
      // Normal sidebar
      register_sidebar($sidebar);
    }
  }

  /**
   * Like dynamic_sidebar for multilang sidebars
   * @param string $sidebarId the sidebar id
   */
  public static function showSidebar($sidebarId)
  {
    if (self::isActive()) {
      dynamic_sidebar($sidebarId . '-' . self::getCurrentLang());
    } else {
      dynamic_sidebar($sidebarId);
    }
  }

  /**
   * Like is_active_sidebar for multilang sidebars
   * @param string $sidebarId the sidebar id
   * @return bool true, if the sidebar is active
   */
  public static function isActiveSidebar($sidebarId)
  {
    if (self::isActive()) {
      return is_active_sidebar($sidebarId . '-' . self::getCurrentLang());
    }

    return is_active_sidebar($sidebarId);
  }

  /**
   * This can be utilized for fields that need to me multilang capable but
   * are also available in our default (german).
   * @param array $fields override the default if german is not desired
   * @return array key/value pair of languagecode > description (lang name)
   */
  public static function getConfigureableFields($fields = array('de' => 'Deutsch'))
  {
    if (Multilang::isActive()) {
      $fields = array();
      $languages = pll_languages_list(array('fields' => ''));
      foreach ($languages as $language) {
        $fields[$language->slug] = $language->name;
      }
    }

    return $fields;
  }

  /**
   * @param string $default overrideable default if multilang not active
   * @return string
   */
  public static function getConfureableFieldLang($default = 'de')
  {
    return Multilang::isActive() ? self::getCurrentLang() : $default;
  }
} 