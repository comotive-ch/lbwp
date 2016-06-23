<?php

namespace LBWP\Theme\Feature;

use LBWP\Core as LbwpCore;
use LBWP\Module\Frontend\HTMLCache;
use LBWP\Util\ArrayManipulation;
use LBWP\Util\Multilang;
use LBWP\Util\Strings;
use LBWP\Util\WordPress;

/**
 * Various helpers for searching in the frontend
 * @package LBWP\Module\Frontend
 * @author Michael Sebel <michael@comotive.ch>
 */
class Search
{
  /**
   * @var string the xml to html template for xml results
   */
  protected static $template = '
    <article class="{classes}">
      <div><a href="{url}">{image}</a></div>
      <div>
        <h2><a href="{url}">{title}</a></h2>
        <p><em>{date}</em> &ndash; {description}</p>
      </div>
    </article>
  ';
  /**
   * @var bool filter results with our own logic
   */
  protected static $filterResults = false;
  /**
   * @var string the error message
   */
  protected static $errorMessage = '';
  /**
   * The endpoint for xml requests
   */
  const XML_ENDPOINT = 'https://cse.google.com/cse?cx={searchEngineId}&q={searchTerm}&output=xml';
  /**
   * Maximum results via XML search
   */
  const XML_RESULTS_PER_PAGE = 10;
  /**
   * Maximum number of pages to show in navigation
   */
  const XML_RESULTS_PAGES_IN_NAV = 10;

  /**
   * @param string $html your own template
   */
  public static function setXmlTemplate($html)
  {
    self::$template = $html;
  }

  /**
   * @param bool $active true = google results are filtered
   */
  public static function setFilterResults($active)
  {
    self::$filterResults = $active;
  }

  /**
   * @param string $message error message if no results
   */
  public static function setErrorMessage($message)
  {
    self::$errorMessage = $message;
  }

  /**
   * Get a frontend Google Site Search Input
   * @param string $engineId google search engine ID
   * @param array $config this is merged with defaults
   * @return string the google search input
   */
  public static function getGoogleSearch($engineId, $config = array())
  {
    // Merge with defaults
    $defaults = array(
      'textFieldId' => 'gss_query',
      'formId' => 'search_form',
      'textFieldType' => 'text',
      'languages' => 'de'
    );

    // Set Multilang languages, if given
    if (Multilang::isActive()) {
      $defaults['languages'] = implode(',', Multilang::getAllLanguages());
    }

    // Merge config
    $config = array_merge($defaults, $config);

    // Set a few configurations
    $class = '';
    $tabindex = '';
    $placeholder = '';
    if (isset($config['textFieldClass'])) {
      $class = ' class="' . $config['textFieldClass'] . '"';
    }
    if (isset($config['textFieldTabIndex'])) {
      $tabindex = ' tabindex="' . $config['textFieldTabIndex'] . '"';
    }
    if (isset($config['textFieldPlaceholder'])) {
      $placeholder = ' placeholder="' . $config['textFieldPlaceholder'] . '"';
    }

    // Print the script and form input code
    return '
      ' . self::getGoogleSiteSearchApi() . '
      <!--[if gt IE 8]>
      <script type="text/javascript">
        google.setOnLoadCallback(function () {
          google.search.CustomSearchControl.attachAutoCompletionWithOptions(
            "' . $engineId . '",
            document.getElementById("' . $config['textFieldId'] . '"),
            document.getElementById("' . $config['formId'] . '"),
            {
              "maxCompletions": 5,
              "validLanguages": "' . $config['languages'] . '"
            }
          );
        });
      </script>
      <![endif]-->

      <input
        type="' . $config['textFieldType'] . '"' . $class . $tabindex . $placeholder . '
        name="q" id="' . $config['textFieldId'] . '"
        value="' . htmlspecialchars(strip_tags(stripslashes($_REQUEST['q']))) . '"
      />
    ';
  }

  /**
   * Prints the search results
   */
  public static function printGoogleSiteSearchResults()
  {
    // Get the config
    $config = LbwpCore::getInstance()->getConfig();

    // Display all the needed mambo jambo
    echo self::getGoogleSiteSearchApi();
    ?>
    <div id="cse"></div>
    <script type="text/javascript">
      google.setOnLoadCallback(function () {
        var customSearchOptions = {};
        customSearchOptions[google.search.Search.RESTRICT_EXTENDED_ARGS] = {
          'lr': '<?php echo substr(get_locale(),0,2); ?>'
        };
        var customSearchControl = new google.search.CustomSearchControl('<?php echo $config['Various:GoogleEngineId']; ?>', customSearchOptions);
        var options = new google.search.DrawOptions();
        customSearchControl.setResultSetSize(google.search.Search.FILTERED_CSE_RESULTSET);
        options.enableSearchResultsOnly();
        customSearchControl.draw('cse', options);
        customSearchControl.execute(sitesearch_getURLParameter('q'));
      }, true);
    </script>
    <noscript>
      <?php
      /* if Javascript is disabled, provide a link:
       * <a href="//www.google.com/search?q=term&q=site:blog.com&sa=Search">Suche nach "term" auf Google</a>
       */
      $href = '//www.google.com/search?q=' .
        $_REQUEST['q'] . '&q=' .
        urlencode('site:' . str_replace(array('http://', 'https://'), '', get_bloginfo('url'))) .
        '&sa=Search';
      ?>
      <a href="<?php echo $href; ?>" target="_blank">
        <?php _e('Suche nach', 'lbwp'); ?> &quot;<?php echo $_REQUEST['q']; ?>&quot; <?php _e('auf', 'lbwp'); ?> Google
      </a>
    </noscript>
    <style>
      #cse .gsc-result-info { padding-left: 0; }
      #cse table { padding: 0; margin: 0; background: transparent; }
      #cse table td { border: 0; padding: 0; margin: 0; background: transparent; }
      #cse .gcsc-branding, #cse .gs-visibleUrl-short { display: none; }
      #cse .gs-visibleUrl-long { display: block; }
    </style>
    <?php
  }

  /**
   * @param int $total results
   * @param int $page the current page
   */
  public static function getXmlResultPaging($total, $page)
  {
    $html = '';
    $pageList = array();
    $pages = ceil($total / self::XML_RESULTS_PER_PAGE);
    for ($i = 1; $i < $pages; ++$i) {
      if ($i+1 >= $page) $pageList[] = $i;
    }
    // Cut that array after max shown pages
    $pageList = array_slice($pageList, 0, self::XML_RESULTS_PAGES_IN_NAV);
    if (count($pageList) < self::XML_RESULTS_PAGES_IN_NAV) {
      // Fill backwards, if smaller
      for ($i = $pageList[0] - 1; $i >= 1 && count($pageList) < self::XML_RESULTS_PAGES_IN_NAV; $i--) {
        array_unshift($pageList, $i);
      }
    }

    // Generate HTML output
    if (count($pageList) > 0) {
      // Define base url
      unset($_GET['rp']);
      $query = http_build_query($_GET);

      $html .= '<div id="pagenav"><ul class="nav page" role="contentinfo">';
      foreach ($pageList as $pageNr) {
        if ($pageNr == $page) {
        $html .= '
          <li class="pagenr pagenronly current" role="menuitem">
            <span class="pagecur">' . $pageNr . '</span>
          </li>
        ';
        } else {
          $html .= '
          <li class="pagenr pagenronly" role="menuitem">
            <a href="?' . $query . '&rp=' . $pageNr . '">' . $pageNr . '</a>
          </li>
        ';
        }
      }
      $html .= '</ul></div>';
    }

    return $html;
  }

  /**
   * Prints the search results with a template and by using xml results
   */
  public static function printGoogleXmlResults()
  {
    // First off, prevent caching as of now
    HTMLCache::avoidCache();
    // Get the config and init the result array
    $config = LbwpCore::getInstance()->getConfig();
    $results = array();
    $terms = array_map('trim', explode(' ', $_GET['q']));
    // Prepare the url to be called
    $url = self::XML_ENDPOINT;
    $url = str_replace('{searchEngineId}', $config['Various:GoogleEngineId'], $url);
    $url = str_replace('{searchTerm}', $_GET['q'], $url);
    // Add start / num parameters
    $page = (intval($_GET['rp']) > 0) ? intval($_GET['rp']) : 1;
    $start = ($page > 1) ? ($page - 1) * self::XML_RESULTS_PER_PAGE : 0;
    $url .= '&start=' . $start . '&num=' . self::XML_RESULTS_PER_PAGE;
    // Make a simple call and convert the xml doc to an array
    $xml = new \SimpleXMLElement($url, 0, true);
    $xml = ArrayManipulation::convertSimpleXmlElement($xml);
    $htmlContainerClass = 'lbwp-gss-xml-results';

    // See if we need to generate a paging output
    $pagingHtml = '';
    $totalResults = intval($xml['RES']['M']);
    if ($totalResults > self::XML_RESULTS_PER_PAGE) {
      $pagingHtml = self::getXmlResultPaging($totalResults, $page);
    }

    // Fix single result xml
    if (isset($xml['RES']['R']['U'])) {
      $xml['RES']['R'] = array($xml['RES']['R']);
    }

    // If there are results, show them
    if (isset($xml['RES']['R']) && count($xml['RES']['R']) > 0) {
      foreach ($xml['RES']['R'] as $item) {
        // Extract the post name from the url
        $url = substr($item['U'], 0, -1);
        $postName = substr($url, strrpos($url, '/') + 1);
        $postId = WordPress::getPostIdByName($postName, 'post');
        $result = get_post($postId);
        if ($postId > 0 && $result instanceof \WP_Post) {
          $results[] = $result;
        }
      }
    }

    // If wanted, filter out elements not matching
    if (self::$filterResults) {
      $backupResults = $results;
      $results = array_filter($results, function($result) use ($terms) {
        foreach ($terms as $term) {
          if (stristr($result->post_title, $term) !== false) {
            return true;
          }
          if (stristr($result->post_content, $term) !== false) {
            return true;
          }
        }
        return false;
      });

      // If all was filtered out, still show something
      if (count($results) == 0) {
        $results = $backupResults;
      }
    }

    // Show the results or print the error message
    if (count($results) > 0) {
      echo '<div class="' . $htmlContainerClass . '">';
      foreach ($results as $result) {
        // Try getting the image as variable
        $image = '';
        $thumbnailId = get_post_thumbnail_id($result->ID);
        if ($thumbnailId > 0) {
          $url = WordPress::getImageUrl($thumbnailId, 'medium');
          if (Strings::isURL($url)) {
            $image = '<img src="' . $url . '" alt="' . $result->post_title . '">';
          }
        }

        // Try to get the perfect fit for description
        $description = get_post_meta($result->ID, '_wpseo_edit_description', true);
        if (strlen($description) == 0) {
          $description = strip_tags($result->post_excerpt);
        }

        // If still no description, use post content
        if (strlen($description) == 0) {
          $description = Strings::chopToWords(strip_tags($result->post_content), 30, true);
        }

        $template = self::$template;
        // Replace all the variables
        $template = str_replace('{url}', get_permalink($result->ID), $template);
        $template = str_replace('{classes}', implode(' ', get_post_class('result-item', $result->ID)), $template);
        $template = str_replace('{image}', $image, $template);
        $template = str_replace('{title}', $result->post_title, $template);
        $template = str_replace('{date}', date_i18n(get_option('date_format'), strtotime($result->post_date)), $template);
        $template = str_replace('{description}', $description, $template);
        // Print the result item
        echo $template;
      }
      echo $pagingHtml;
      echo '</div>';
    } else {
      echo '
        <div class="' . $htmlContainerClass . ' search-error">
          <p>' . self::$errorMessage . '</p>
        </div>';
    }
  }

  /**
   * Adds the google site search api
   */
  public static function getGoogleSiteSearchApi()
  {
    return '
      <script src="//www.google.com/jsapi" type="text/javascript"></script>
      <script type="text/javascript">
        google.load("search", "1", {language: "' . get_locale() . '"});
        function sitesearch_getURLParameter(name) {
          return decodeURIComponent((RegExp(name+"=(.+?)(&|$)").exec(location.search)||[,null])[1]);
        }
      </script>
    ';
  }
}
