<?php

namespace LBWP\Theme\Feature;
use LBWP\Util\Strings;

/**
 * Adds a dynamic paged navigation
 * @package LBWP\Theme\Feature
 * @author Michael Sebel <michael.sebel@blogwerk.com>
 *
 * Result:
 *  <div id="pagenav">
 *    <ul class="nav page" role="contentinfo">
 *      <li class="before">{before_html}</li>
 *      <li class="prev" role="menuitem"><a href="" title="{prev_page_title|html}">{prev_page_html}</a></li>
 *      <li class="pagenr" role="menuitem"><a href="" title="{page_text}">1</a></li>
 *      <li class="pagenr extend" role="menuitem">{extend_html}</li>
 *      <li class="pagenr current" role="menuitem"><span class="pagecur">3</span></li>
 *      <li class="pagenr" role="menuitem"><a href="" title="4">4</a></li>
 *      <li class="pagenr" role="menuitem"><a href="" title="5">5</a></li>
 *      <li class="next" role="menuitem"><a href="" title="{next_page_title|html}">{next_page_html}</a></li>
 *      <li class="after">{after_html}</li>
 *    </ul>
 *  </div>
 */
class PagedNavigation
{

  /**
   * Default Settings for the pagenavi
   * @var array
   */
  protected $settings = array(
    'always_show' => false,
    'always_show_first_last' => false,
    'always_show_prev_next' => false,
    'remove_unmeaningful_nav_links' => false,
    'switch_prev_next' => false,
    'before_html' => 'Seite: ',
    'prev_page_html' => 'vorherige',
    'prev_page_title' => 'Vorherige Seite',
    'extend_html' => '...',
    'next_page_html' => 'nächste',
    'next_page_title' => 'Nächste Seite',
    'after_html' => '',
    'num_pages' => 5,
    'first_text' => '1',
    'last_text' => '%TOTAL_PAGES%',
    'current_text' => '%PAGE_NUMBER%',
    'page_text' => '%PAGE_NUMBER%',
    'page_title' => 'Seite %PAGE_NUMBER%',
    'loading_zeros' => false
  );
  /**
   * @var PagedNavigation the navigation object
   */
  protected static $instance = NULL;

  /**
   * Can only be instantiated by calling init method
   * @param array|null $settings overriding defaults
   */
  protected function __construct($settings = NULL)
  {
    if (is_array($settings)) {
      $this->settings = array_merge($this->settings, $settings);
    }
  }

  /**
   * Initialise while overriding settings defaults
   * @param array|null $settings overrides defaults as new default
   */
  public static function init($settings = NULL)
  {
    self::$instance = new PagedNavigation($settings);
  }

  /**
   * Show the paging navigation
   * @param array $settings
   */
  public static function show($settings = NULL)
  {
    // Create without overriding defaults
    if (!self::$instance instanceof PagedNavigation) {
      self::init();
    }

    self::$instance->displayNavigation($settings);
  }

  /**
   * Actually display the navigation in object context
   * @global array $_wp_theme_features Settings for the Themefeatures
   * @param array $override Overrides the global settings, if set.
   */
  protected function displayNavigation($override = NULL)
  {
    global $wp_query;

    // Static Settings
    if (is_array($override)) {
      $this->settings = array_merge($this->settings, $override);
    }

    if (is_single()) {
      return;
    }

    $paged = intval(get_query_var('paged'));
    $maxPage = $wp_query->max_num_pages;
    if (empty($paged) || $paged == 0) {
      $paged = 1;
    }

    // Calculate Pagedata, last, first and total
    $pagesToShow = intval($this->settings['num_pages']);
    $pagesToShowMinusOne = $pagesToShow - 1;
    $halfPageStart = floor($pagesToShowMinusOne / 2);
    $halfPageEnd = ceil($pagesToShowMinusOne / 2);
    $startPage = $paged - $halfPageStart;

    if ($startPage <= 0) {
      $startPage = 1;
    }

    $endPage = $paged + $halfPageEnd;
    if (($endPage - $startPage) != $pagesToShowMinusOne) {
      $endPage = $startPage + $pagesToShowMinusOne;
    }

    if ($endPage > $maxPage) {
      $startPage = $maxPage - $pagesToShowMinusOne;
      $endPage = $maxPage;
    }

    if ($startPage <= 0) {
      $startPage = 1;
    }

    // if we want to always show the page navi, we need to fix the calculated limits
    if ($this->settings['always_show'] && $maxPage < $startPage) {
      $maxPage = $startPage;
      $endPage = $startPage;
    }

    // either we have multiple pages, or the always_show parameter is set
    if ($maxPage > 1 || $this->settings['always_show']) {
      $pagesText = str_replace("%CURRENT_PAGE%", $paged, $this->settings['before_html']);
      $pagesText = str_replace("%TOTAL_PAGES%", $maxPage, $pagesText);

      $navClass = 'nav page';
      if (isset($this->settings['override-nav-class'])) {
        $navClass = $this->settings['override-nav-class'];
      }

      echo PHP_EOL;
      echo '<div id="pagenav">' . PHP_EOL;
      echo '<ul class="' . $navClass . '" role="contentinfo">' . PHP_EOL;

      if (!empty($pagesText)) {
        echo '<li class="before">' . $pagesText . '</li>' . PHP_EOL;
      }

      if (!$this->settings['switch_prev_next']) {
        add_filter('previous_posts_link_attributes', array($this, 'getLinkTitlePrev'));
        $code = get_previous_posts_link($this->settings['prev_page_html']);

        // If there is no previous link, set current link if forced
        if ($this->settings['always_show_prev_next'] && strlen($code) == 0) {
          $code = '<a href="' . $_SERVER['REQUEST_URI'] . '">' . $this->settings['prev_page_html'] . '</a>';
        }

        if (strlen($code) > 0) {
          echo '<li class="prev" role="menuitem">' . PHP_EOL . $this->removeSamePageLink($code) . PHP_EOL . '</li>' . PHP_EOL;
        }
      }

      // Previous page link
      if (($startPage >= 2 && $pagesToShow < $maxPage) || $this->settings['always_show_first_last']) {

        if (is_integer((int)$this->settings['first_text'])) {
          $firstText = $this->leadingZeros($this->settings['first_text']);
        }

        $firstPageText = str_replace("%TOTAL_PAGES%", $this->leadingZeros($maxPage), $firstText);
        $pageTitle = str_replace("%PAGE_NUMBER%", $this->leadingZeros(1), $this->settings['page_title']);

        echo '<li class="pagenr prev_first" role="menuitem">' . PHP_EOL;
        echo $this->removeSamePageLink('<a href="' . esc_url(get_pagenum_link()) . '" title="' . $pageTitle . '">' . $firstPageText . '</a>') . PHP_EOL;
        echo '</li>' . PHP_EOL;

        // Text for "extend" on the left side, usually dots
        if (!empty($this->settings['extend_html'])) {
          echo '<li class="pagenr extend" role="menuitem">' . PHP_EOL;
          echo '<span class="extend-html">' . $this->settings['extend_html'] . '</span>' . PHP_EOL;
          echo '</li>' . PHP_EOL;
        }
      }

      if ($this->settings['switch_prev_next']) {
        add_filter('previous_posts_link_attributes', array($this, 'getLinkTitlePrev'));
        $code = get_previous_posts_link($this->settings['prev_page_html']);

        // If there is no previous link, set current link if forced
        if ($this->settings['always_show_prev_next'] && strlen($code) == 0) {
          $code = '<a href="' . $_SERVER['REQUEST_URI'] . '">' . $this->settings['prev_page_html'] . '</a>';
        }

        if (strlen($code) > 0) {
          echo '<li class="prev" role="menuitem">' . PHP_EOL . $this->removeSamePageLink($code) . PHP_EOL . '</li>' . PHP_EOL;
        }
      }

      // Show page numbers
      for ($i = $startPage; $i <= $endPage; $i++) {
        if ($i == $paged) {
          $currentPageText = str_replace("%PAGE_NUMBER%", $this->leadingZeros($i), $this->settings['current_text']);
          echo '<li class="pagenr pagenronly current" role="menuitem">' . PHP_EOL;
          echo '<span class="pagecur">' . $currentPageText . '</span>' . PHP_EOL;
          echo '</li>' . PHP_EOL;
        } else {
          $pageText = str_replace("%PAGE_NUMBER%", $this->leadingZeros($i), $this->settings['page_text']);
          $pageTitle = str_replace("%PAGE_NUMBER%", $this->leadingZeros($i), $this->settings['page_title']);
          echo '<li class="pagenr pagenronly" role="menuitem">' . PHP_EOL;
          echo '<a href="' . esc_url(get_pagenum_link($i)) . '" title="' . $pageTitle . '">' . $pageText . '</a>' . PHP_EOL;
          echo '</li>' . PHP_EOL;
        }
      }

      // Link to next page
      if ($this->settings['switch_prev_next']) {
        add_filter('next_posts_link_attributes', array($this, 'getLinkTitleNext'));
        $code = get_next_posts_link($this->settings['next_page_html']);

        // If there is no previous link, set current link if forced
        if ($this->settings['always_show_prev_next'] && strlen($code) == 0) {
          $code = '<a href="' . $_SERVER['REQUEST_URI'] . '">' . $this->settings['next_page_html'] . '</a>';
        }

        if (strlen($code) > 0) {
          echo '<li class="next" role="menuitem">' . PHP_EOL . $this->removeSamePageLink($code) . PHP_EOL . '</li>' . PHP_EOL;
        }
      }

      if ($endPage < $maxPage || $this->settings['always_show_first_last']) {

        // Text for "extend" on the right side, usually dots
        if (!empty($this->settings['extend_html'])) {
          echo '<li class="pagenr extend" role="menuitem">' . PHP_EOL;
          echo '<span class="extend-html">' . $this->settings['extend_html'] . '</span>' . PHP_EOL;
          echo '</li>' . PHP_EOL;
        }

        // Last item, respectively the page number
        $lastPageText = str_replace("%TOTAL_PAGES%", $this->leadingZeros($maxPage), $this->settings['last_text']);
        $pageTitle = str_replace("%PAGE_NUMBER%", $this->leadingZeros($maxPage), $this->settings['page_title']);
        echo '<li class="pagenr next_last" role="menuitem">' . PHP_EOL;
        echo $this->removeSamePageLink('<a href="' . esc_url(get_pagenum_link($maxPage)) . '" title="' . $pageTitle . '">' . $lastPageText . '</a>') . PHP_EOL;
        echo '</li>' . PHP_EOL;
      }

      // Link to next page
      if (!$this->settings['switch_prev_next']) {
        add_filter('next_posts_link_attributes', array($this, 'getLinkTitleNext'));
        $code = get_next_posts_link($this->settings['next_page_html']);

        // If there is no previous link, set current link if forced
        if ($this->settings['always_show_prev_next'] && strlen($code) == 0) {
          $code = '<a href="' . $_SERVER['REQUEST_URI'] . '">' . $this->settings['next_page_html'] . '</a>';
        }

        if (strlen($code) > 0) {
          echo '<li class="next" role="menuitem">' . PHP_EOL . $this->removeSamePageLink($code) . PHP_EOL . '</li>' . PHP_EOL;
        }
      }

      $pagesText = str_replace("%CURRENT_PAGE%", $paged, $this->settings['after_html']);
      $pagesText = str_replace("%TOTAL_PAGES%", $maxPage, $pagesText);

      if (!empty($pagesText)) {
        echo '<li class="after">' . $pagesText . '</li>' . PHP_EOL;
      }

      echo '</ul>' . PHP_EOL;
      echo '</div>' . PHP_EOL;
    }
  }

  /**
   * Makes the a tag disabled and removed the href, if it links to the current uri
   * @param string $linkTag the link tag
   * @return string the fixed link tag
   */
  protected function removeSamePageLink($linkTag)
  {
    if ($this->settings['remove_unmeaningful_nav_links']) {
      $href = Strings::parseTagProperty($linkTag, 'href');
      $href = str_replace(get_bloginfo('url'), '', $href);
      if ($href == $_SERVER['REQUEST_URI']) {
        $linkTag = str_replace(get_bloginfo('url'), '', $linkTag);
        $linkTag = str_replace($_SERVER['REQUEST_URI'], '', $linkTag);
        $linkTag = str_replace('href=""', 'class="nav-disabled"', $linkTag);
      }
    }

    return $linkTag;
  }

  /**
   * Appends leading zeros
   *
   * @param int $int Pagenumber
   * @return string Given pagenumber with a leading zero.
   */
  protected function leadingZeros($int)
  {
    if ($this->settings['leading_zeros'] && $int < 10 && $int > 0) {
      return '0' . $int;
    }
    return $int;
  }

  /**
   * Returns the title attribute for the "next" link
   *
   * @return string Title attribute
   */
  public function getLinkTitleNext()
  {
    if (isset($this->settings['next_page_title'])) {
      return ('title="' . $this->settings['next_page_title'] . '"');
    } else {
      return ('title="' . $this->settings['next_page_html'] . '"');
    }
  }

  /**
   * Returns the title attribute for the "previous" link
   * @return string Title attribute
   */
  public function getLinkTitlePrev()
  {
    if (isset($this->settings['next_page_title'])) {
      return ('title="' . $this->settings['prev_page_title'] . '"');
    } else {
      return ('title="' . $this->settings['prev_page_html'] . '"');
    }
  }
}