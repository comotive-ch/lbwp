<?php

namespace LBWP\Helper;

use LBWP\Util\Strings;
use LBWP\Util\Multilang;
use LBWP\Core as LbwpCore;

/**
 * Allows to add different common rewriting rules
 * @package LBWP\Helper
 * @author Michael Sebel <michael@comotive.ch>
 */
class Rewrite
{
  /**
   * @param string $templateId pages to apply this rewrite rule
   * @param array $params the parameter names to match to the url parts
   */
  public static function addPageTemplateRewrite($templateId, $params = array())
  {
    // Add the rules to be filters
    add_filter('rewrite_rules_array', function ($rules) use ($templateId, $params) {
      $newRules = array();
      // Get pages with that template id
      $pages = get_posts(array(
        'suppress_filters' => true,
        'post_type' => 'page',
        'meta_key' => '_wp_page_template',
        'meta_value' => $templateId
      ));

      foreach ($pages as $page) {
        // Define the base of the rewrite, which is the page uri
        $regex = $page->post_name . '/';
        $query = 'index.php?page_id=' . $page->ID;

        // Add params, as needed
        foreach ($params as $index => $name) {
          $regex .= '(.+?)/';
          $query .= '&' . $name . '=$matches[' . ($index + 1) . ']';
          // Add the new rule for every made hierarchy to allow missing params
          $newRules[$regex . '?$'] = $query;
        }
      }

      // Reverse the rules so it searches for "easier" to match last
      $newRules = array_reverse($newRules);

      return $newRules + $rules;
    });

    // Add the query variable(s)
    add_filter('query_vars', function ($vars) use ($params) {
      return array_merge($vars, $params);
    });
  }

  /**
   * Let's you freely translate the slugs for a post type archive. We're using the filter
   * priority 100 here because polylang would override those additional rules with prefixes.
   * These rewrites are not meant to have prefixes and are added after polylang hooks.
   * TODO: This doesn't yet support the single links, but paging etc. works
   * @param string $type the original archive slug
   * @param array $translations lang=>translation array
   */
  public static function addPostTypeSlugTranslation($type, $translations)
  {
    // Add the new custom lang rules
    add_filter('rewrite_rules_array', function ($rules) use ($type, $translations) {
      $newRules = array();
      foreach ($translations as $lang => $rewrite) {
        $newRules[$rewrite . '/?$'] = 'index.php?post_type=' . $type . '&lang=' . $lang;
        $newRules[$rewrite . '/feed/(feed|rdf|rss|rss2|atom)/?$'] = 'index.php?post_type=' . $type . '&feed=$matches[1]&lang=' . $lang;
        $newRules[$rewrite . '/(feed|rdf|rss|rss2|atom)/?$'] = 'index.php?post_type=' . $type . '&feed=$matches[1]&lang=' . $lang;
        $newRules[$rewrite . '/page/([0-9]{1,})/?$'] = 'index.php?post_type=' . $type . '&paged=$matches[1]&lang=' . $lang;
      }

      return $newRules + $rules;
    }, 100);

    // Use correct urls on language switching
    add_filter(Multilang::$translationUrlFilter, function ($url, $lang) use ($type, $translations) {
      // Remove the language slug that was added for the current languages link
      $url = str_replace($lang . '/' . $translations[$lang], $translations[$lang], $url);

      // If not the current language, replace the rewritten part
      $current = Multilang::getCurrentLang();
      if ($current != $lang) {
        $url = str_replace($lang . '/' . $translations[$current], $translations[$lang], $url);
      }

      return $url;
    }, 100, 2);
  }

  /**
   * Can be used in filters to change links from cached loadbalancer assets to native exoscale urls
   * @param string $html an html document
   * @return string fixed html
   */
  public static function rewriteVideoAssetsToExoscaleNativeUrl($html)
  {
    return Strings::replaceByXPath($html, '//source', function ($doc, $tag, $fragment) {
      /**
       * @var \DOMDocument $doc The document initialized with $html
       * @var \DOMNode $tag A node of the result set
       * @var \DOMDocumentFragment $fragment Empty fragment node, add content by $fragment->appendXML('something');
       */
      $originalUrl = $tag->attributes->getNamedItem('src')->nodeValue;
      $parts = parse_url($originalUrl);

      // Only change our own rewritten urls
      if (Strings::endsWith($parts['path'], '.mp4') || Strings::endsWith($parts['path'], '.webm')) {
        $cdnFullUri = LbwpCore::getCdnFileUri();
        $searchUrl = substr($cdnFullUri, 0, strpos($cdnFullUri, '/lbwp-cdn/'));
        // Change to the new attribute
        $tag->setAttribute('src', str_replace(
          $searchUrl,
          'https://sos.exo.io',
          $originalUrl
        ));
      }

      $fragment->appendXML($doc->saveXML($tag));
      return $tag;
    });
  }

  /**
   * Usage in a function, to debug what wordpress actually does:
   * add_action('parse_request', array('\LBWP\Helper\Rewrite', 'debugRewriteRules'));
   * @param \WP_Query $wp
   */
  public static function debugRewriteRules(&$wp)
  {
    /** @var \WP_Rewrite $wp_rewrite */
    global $wp_rewrite;

    echo '<h2>rewrite rules</h2>';
    echo var_export($wp_rewrite->wp_rewrite_rules(), true);

    echo '<h2>permalink structure</h2>';
    echo var_export($wp_rewrite->permalink_structure, true);

    echo '<h2>page permastruct</h2>';
    echo var_export($wp_rewrite->get_page_permastruct(), true);

    echo '<h2>matched rule and query</h2>';
    echo var_export($wp->matched_rule, true);

    echo '<h2>matched query</h2>';
    echo var_export($wp->matched_query, true);

    echo '<h2>request</h2>';
    echo var_export($wp->request, true);

    global $wp_the_query;
    echo '<h2>the query</h2>';
    echo var_export($wp_the_query, true);
  }
}