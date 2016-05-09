<?php

namespace LBWP\Theme\Base;
use LBWP\Theme\Feature\Fetch;

/**
 * TODO testing of this class
 * Base class for fetch components: when deriving from this class, you should
 * - register the url during 'init' array in the form of
 *   $this->urls = array(
 *     $sectionSlug => array(
 *       $language => $url
 *     )
 *   )
 *   $sectionSlug can be anything you like, $language: 'de', 'fr', ...
 *
 * - register fetch filters for each $sectionSlug during 'init': fetch filters are callbacks which will be able to modify the fetched content
 *   $this->addFetchFilter($sectionSlug, array($this, 'fetchFilterCallback'));
 *   with:
 *   public function fetchFilterCallback($fetchContent, $language, $section, $url)
 *   and return the modified $fetchContent or false if it is invalid.
 *   an invalid fetch filter result (false) will abort the whole fetch for the current section
 *
 * normal fetch inclusion:
 *   Fetch::includeContent($sectionSlug);
 *
 *
 *
 * @package LBWP\Theme\Base
 * @author Tom Forrer <tom.forrer@blogwerk.com>
 * @author Michael Sebel <michael@comotive.ch>
 */
abstract class FetchComponent extends Component
{
  /**
   * @var array $urls
   */
  protected $urls = array();
  /**
   * @var string the url to replace links
   */
  protected $replaceUrl = '';

  /**
   * @var array $fetchFilters
   */
  protected $fetchFilters = array();

  /**
   * execute the fetch with all registered fetch filters
   */
  public function execute()
  {
    // fetch all languages
    $languages = $this->getTheme()->getDependencyWrapper()->getAllLanguages();

    // loop through the configured sections
    foreach ($this->urls as $section => $urls) {

      // each section is expected to be an array with url values identified by a language code key
      foreach ($languages as $language) {

        if (isset($urls[$language])) {
          // fetch the html of the section
          $sectionContent = Fetch::getContent($urls[$language], false);

          // do not abort, unless a fetch filter returns false
          $abort = false;

          // only try to apply fetch filters if any are registered for the current section
          if (isset($this->fetchFilters[$section])) {

            // apply the fetch filters configured for this section
            foreach ($this->fetchFilters[$section] as $priority) {

              // ordered by priority: lower values get executed last
              foreach ($priority as $callback) {
                $newSectionContent = callUserFunctionWithSafeArguments($callback, array($sectionContent, $language, $section, $urls[$language]));

                // only take the new section html if it is something
                if ($newSectionContent) {
                  $sectionContent = $newSectionContent;
                } elseif ($newSectionContent === false) {
                  $abort = true;
                  // break out of two foreaches
                  break 2;
                }
              }
            }
          }

          // only save the fetch if it wasn't aborted
          if (!$abort) {
            Fetch::saveContent($section, $sectionContent, $language);
          }
        }
      }
    }
  }

  /**
   * Adds a fetch filter (callback) for a given section
   *
   * @param string $section 'header' or 'footer'
   * @param callable $callback
   * @param int $priority
   */
  public function addFetchFilter($section, $callback, $priority = 10)
  {
    if (!isset($this->fetchFilters[$section]) || !is_array($this->fetchFilters[$section])) {
      $this->fetchFilters[$section] = array();
    }
    if (!isset($this->fetchFilters[$section][$priority]) || !is_array($this->fetchFilters[$section][$priority])) {
      $this->fetchFilters[$section][$priority] = array();
    }
    if (is_callable($callback)) {
      $this->fetchFilters[$section][$priority][] = $callback;
    }

    ksort($this->fetchFilters[$section]);
  }

  /**
   * Helper function for adding unopened tags in front of the html
   *
   * @param string $html
   * @param string $tag i.e '<div>'
   * @param int $number number of tags to add
   * @return string resulting html
   */
  protected function prefixTags($html, $tag, $number)
  {
    for ($index = 0; $index < $number; $index++) {
      $html = $tag . $html;
    }
    return $html;
  }

  /**
   * Helper function for closing opened tags at the end of the html
   *
   * @param string $html
   * @param string $tag i.e '</div>'
   * @param int $number number of tags to add
   * @return string resulting html
   */
  protected function suffixTags($html, $tag, $number)
  {
    for ($index = 0; $index < $number; $index++) {
      $html = $html . $tag;
    }
    return $html;
  }

  /**
   * Helper function for removing opening tags at the beginning of the html
   *
   * @param string $html
   * @param string $tag i.e '<div>'
   * @param int $number number of tags to add
   * @return string resulting html
   */
  protected function removePrefixTags($html, $tag, $number)
  {
    $pos = 0;
    for ($index = 0; $index < $number; $index++) {
      $newPos = strpos($html, $tag, $pos);
      if ($newPos !== false) {
        $pos = $newPos + strlen($tag);
      } else {
        $pos += strlen($tag);
      }
    }
    $html = substr($html, $pos);
    return $html;
  }

  /**
   * Replace generic links to make them absolute to the fetched domain
   */
  public function replaceLinks($html)
  {
    $replacements = apply_filters('fetchComponent_replaceLinkTemplate', array(
      '="//' => '="$$$',
      'href="/' => 'href="' . $this->replaceUrl,
      'src="/' => 'src="' . $this->replaceUrl,
      'action="/' => 'action="' . $this->replaceUrl,
      'href=\'/' => 'href=\'' . $this->replaceUrl,
      'src=\'/' => 'src=\'' . $this->replaceUrl,
      'action=\'/' => 'action=\'' . $this->replaceUrl,
      '="$$$' => '="//',
    ));

    foreach ($replacements as $search => $replace) {
      $html = str_replace($search, $replace, $html);
    }

    return $html;
  }

  /**
   * Remove core meta tags that will be generated by wordpress
   * Some meta name=, title and most og:* tags
   * @param string $html the input html
   * @return string the fixed html
   */
  public function removeCoreMetaTags($html)
  {
    // Remove the title (the easy one first)
    $html = preg_replace('/<title>([^>]*)<\/title>/si', '', $html);

    // Define tags to remove, let user filter it
    $removedMetaTags = apply_filters('fetchComponent_removeCoreMetaTags', array(
      'generator', 'title', 'description', 'keywords', 'og:(.+?)'
    ));

    // Replace all found elements with "nothing"
    foreach ($removedMetaTags as $metaName) {
      $html = preg_replace('/<[\s]*meta[\s]*name="' . $metaName . '"?[\s](.+?)?[\s]*[\/]?[\s]*>/si', '', $html);
    }

    // Remove resulting blank lines
    $html = preg_replace('/(^[\r\n]*|[\r\n]+)[\s\t]*[\r\n]+/', PHP_EOL, $html);

    return $html;
  }

  /**
   * Helper function for removing closing tags at the end of the html
   *
   * @param string $html
   * @param string $tag i.e '</div>'
   * @param int $number number of tags to add
   * @return string resulting html
   */
  protected function removeSuffixTags($html, $tag, $number)
  {
    $pos = -1;

    for ($index = 0; $index < $number; $index++) {
      $newPos = strripos($html, $tag, $pos);
      if ($newPos !== false) {
        $pos = $newPos - strlen($html) - 1;
      } else {
        $pos -= strlen($tag);
      }
    }
    $html = substr($html, 0, $pos);
    return $html;
  }
}