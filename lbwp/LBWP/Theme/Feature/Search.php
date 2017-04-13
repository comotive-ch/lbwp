<?php

namespace LBWP\Theme\Feature;

use LBWP\Core as LbwpCore;
use LBWP\Module\Frontend\HTMLCache;
use LBWP\Util\ArrayManipulation;
use LBWP\Util\File;
use LBWP\Util\Multilang;
use LBWP\Util\Strings;
use LBWP\Util\Templating;
use LBWP\Util\WordPress;

/**
 * Various helpers for searching in the frontend
 * @package LBWP\Module\Frontend
 * @author Michael Sebel <michael@comotive.ch>
 */
class Search
{
  /**
   * @var array configuration of the api search
   */
  protected static $apiConf = array(
    // Template configuration
    'containerTemplate' => '<div class="{containerClasses}">{items}</div>',
    'imageTemplate' => '<div><a href="{url}">{image}</a></div>',
    'metaTemplate' => '<em>{meta}</em> &ndash; ',
    'itemTemplate' => '
      <article class="{classes}">
        {imageTemplate}
        <div>
          <h2><a href="{url}">{title}</a></h2>
          <p>{metaTemplate} {description}</p>
        </div>
      </article>
    ',
    // Settings for search API
    'errorMessage' => '',                         // The error message if there are no search results
    'apiKey' => '',                               // The API key for google search api
    'containerClasses' => 'lbwp-gss-results',     // Container class, can be multiple (sep. by space), if needed
    'filterResults' => false,                     // true filters the results (if post) for search term existens
    'displayImages' => true,                      // Display images, if available
    'displayFiles' => false,                      // Skip file search results completely
    'rawResultFallback' => false,                 // Fallback to a raw result, if postType matching didn't work
    'imageFallback' => '<div></div>',             // Fallback html, if no image can be displayed
    'postTypes' => array('post', 'page'),         // Types that can be actually found and are not filtered
    'textFieldType' => 'text',                    // Type of the text field for search input
    'textFieldId' => 'gss_query',                // ID of the search input field
    'textFieldPlaceHolder' => '',                 // Search placeholder for input field
  );
  /**
   * The endpoint for xml requests
   */
  const JSON_ENDPOINT = 'https://www.googleapis.com/customsearch/v1?cx={searchEngineId}&key={apiKey}&q={searchTerm}';
  /**
   * Maximum results via XML search
   */
  const RESULTS_PER_PAGE = 10;
  /**
   * Maximum number of pages to show in navigation
   */
  const RESULTS_PAGES_IN_NAV = 10;

  /**
   * @param array $config override API config
   */
  public static function setApiConfig($config)
  {
    self::$apiConf = array_merge(self::$apiConf, $config);
  }

  /**
   * Prints the search results with a template and by using xml results
   */
  public static function printApiSearchResults()
  {
    // First off, prevent caching as of now
    HTMLCache::avoidCache();
    // Get the config and init the result array
    $config = LbwpCore::getInstance()->getConfig();
    $results = array();
    $terms = array_map('trim', explode(' ', $_GET['q']));

    // Prepare the url to be called
    $url = self::JSON_ENDPOINT;
    $url = str_replace('{searchEngineId}', $config['Various:GoogleEngineId'], $url);
    $url = str_replace('{searchTerm}', urlencode($_GET['q']), $url);
    $url = str_replace('{apiKey}', self::$apiConf['apiKey'], $url);

    // Add start / num parameters
    $page = (intval($_GET['rp']) > 0) ? intval($_GET['rp']) : 1;
    $start = ($page > 1) ? ($page - 1) * self::RESULTS_PER_PAGE : 1;
    $url .= '&start=' . $start . '&num=' . self::RESULTS_PER_PAGE . '';
    // Make a simple call and convert the xml doc to an array
    $data = json_decode(file_get_contents($url), true);

    // See if we need to generate a paging output
    $pagingHtml = '';
    $totalResults = intval($data['searchInformation']['totalResults']);
    if ($totalResults > self::RESULTS_PER_PAGE) {
      $pagingHtml = self::getApiResultPaging($totalResults, $page, self::RESULTS_PER_PAGE, self::RESULTS_PAGES_IN_NAV);
    }

    // Filter the results as of config
    $results = self::prepareAndFilterResults($data, $terms);

    // Show the results or print the error message
    if (count($results) > 0) {
      $itemHtml = '';
      foreach ($results as $result) {
        // Replace all the variables for the item
        $itemHtml .= Templating::getBlock(self::$apiConf['itemTemplate'], array(
          '{url}' => $result['url'],
          '{classes}' => $result['classes'],
          '{imageTemplate}' => (self::$apiConf['displayImages']) ? $result['imageHtml'] : '',
          '{title}' => $result['title'],
          '{metaTemplate}' => self::getMetaTemplate($result),
          '{description}' => $result['description']
        ));
      }

      // Wrap the items into the template
      echo Templating::getBlock(self::$apiConf['containerTemplate'], array(
        '{items}' => $itemHtml,
        '{containerClasses}' => self::$apiConf['containerClasses']
      ));

      // Display the paging html, if available
      echo $pagingHtml;
    } else {
      echo '
        <div class="' . self::$apiConf['containerClasses'] . ' search-error">
          <p>' . self::$apiConf['errorMessage'] . '</p>
        </div>
      ';
    }
  }

  protected static function getMetaTemplate($result)
  {
    if (isset($result['meta'])) {
      return Templating::getBlock(self::$apiConf['metaTemplate'], array(
        '{meta}' => $result['meta']
      ));
    }

    return '';
  }

  /**
   * @param \WP_Post $post result object
   * @return string the image html or empty string
   */
  protected static function getPostImageHtml($post)
  {
    $imageHtml = '';
    $thumbnailId = get_post_thumbnail_id($post->ID);
    if ($thumbnailId > 0) {
      $url = WordPress::getImageUrl($thumbnailId, 'medium');
      if (Strings::isURL($url)) {
        return Templating::getBlock(self::$apiConf['imageTemplate'], array(
          '{url}' => get_permalink($post->ID),
          '{image}' => '<img src="' . $url . '" alt="' . $post->post_title . '">'
        ));
      }
    }

    return self::$apiConf['imageFallback'];
  }

  /**
   * @param \WP_Post $post result object
   * @return string a description for the object
   */
  protected static function getPostDescription($post)
  {
    $description = get_post_meta($post->ID, '_yoast_wpseo_metadesc', true);
    if (strlen($description) == 0)
      $description = get_post_meta($post->ID, '_wpseo_edit_description', true);
    if (strlen($description) == 0)
      $description = strip_tags($post->post_excerpt);
    if (strlen($description) == 0)
      $description = Strings::chopToWords(strip_tags(strip_shortcodes($post->post_content)), 40, true);

    return $description;
  }

  /**
   * @param array $data full google search item results
   * @param array $terms the search terms given in
   * @return array $results
   */
  protected static function prepareAndFilterResults($data, $terms)
  {
    $results = array();

    // If there are results, show them
    if (isset($data['items']) && count($data['items']) > 0) {
      foreach ($data['items'] as $item) {
        // Extract the post name from the url
        $url = substr($item['link'], 0, -1);
        $postName = substr($url, strrpos($url, '/') + 1);
        $postId = WordPress::getPostIdByName($postName, self::$apiConf['postTypes']);
        $postObject = get_post($postId);
        if ($postId > 0 && $postObject instanceof \WP_Post && $postObject->post_status == 'publish') {
          $results[] = array(
            'url' => get_permalink($postObject->ID),
            'classes' => implode(' ', get_post_class('result-item', $postObject->ID)),
            'imageHtml' => self::getPostImageHtml($postObject),
            'title' => $postObject->post_title,
            'meta' => date_i18n(get_option('date_format'), strtotime($postObject->post_date)),
            'description' =>self::getPostDescription($postObject),
            'type' => 'content'
          );
        } else if (self::$apiConf['displayFiles'] && isset($item['fileFormat'])) {
          // Insert file data into our structure
          $results[] = array(
            'url' => $item['link'],
            'classes' => 'result-file ext-' . substr(File::getExtension($item['link']), 1),
            'imageHtml' => self::$apiConf['imageFallback'],
            'title' => $item['title'],
            'meta' => $item['fileFormat'],
            'description' => $item['snippet'],
            'type' => 'file'
          );
        } else if (self::$apiConf['rawResultFallback']) {
          // Insert file data into our structure
          $results[] = array(
            'url' => $item['link'],
            'classes' => 'result-raw',
            'imageHtml' => self::$apiConf['imageFallback'],
            'title' => $item['title'],
            'description' => $item['snippet'],
            'type' => 'raw'
          );
        }
      }
    }

    // If wanted, filter out elements not matching
    if (self::$apiConf['filterResults']) {
      $backupResults = $results;
      $results = array_filter($results, function($result) use ($terms) {
        foreach ($terms as $term) {
          // If it is a file, it is allowed anyway
          if ($result['type'] == 'file') {
            return true;
          }
          if (stristr($result['title'], $term) !== false) {
            return true;
          }
          if (stristr($result['description'], $term) !== false) {
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

    return $results;
  }

  /**
   * @param int $total results
   * @param int $page the current page
   * @param int $perPage results per page
   * @param int $maxNav max nav menu entries
   * @return string html to represent the navigation
   */
  public static function getApiResultPaging($total, $page, $perPage = 10, $maxNav = 10)
  {
    $html = '';
    $pageList = array();
    $pages = ceil($total / $perPage);
    for ($i = 1; $i < $pages; ++$i) {
      if ($i+1 >= $page) $pageList[] = $i;
    }
    // Cut that array after max shown pages
    $pageList = array_slice($pageList, 0, $maxNav);
    if (count($pageList) < $maxNav) {
      // Fill backwards, if smaller
      for ($i = $pageList[0] - 1; $i >= 1 && count($pageList) < $maxNav; $i--) {
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
   * Get a frontend API search input (remember to always use GET)
   * @return string the google search input
   */
  public static function getApiSearchInput()
  {
    // Set a placeholder if given
    $placeholder = '';
    if (strlen(self::$apiConf['textFieldPlaceHolder']) > 0) {
      $placeholder = ' placeholder="' . self::$apiConf['textFieldPlaceHolder'] . '"';
    }

    // Print the script and form input code
    return '
      <input
        type="' . self::$apiConf['textFieldType'] . '"' . $placeholder . '
        name="q" id="' . self::$apiConf['textFieldId'] . '"
        value="' . htmlspecialchars(strip_tags(stripslashes($_REQUEST['q']))) . '"
      />
    ';
  }

  /**
   * -----------------------------------------------------------------------------------
   * BELOW: DEPRECATED JAVASCRIPT GOOGLE SEARCH (GSS / CSE), STILL NEEDED FOR SOME
   * -----------------------------------------------------------------------------------
   */

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
  public static function printGoogleSiteSearchResults($args)
  {
    // Get the config
    $config = LbwpCore::getInstance()->getConfig();
    // Use shortcode config, if given
    $targetSelf = (isset($args['results_in_new_tab']) && $args['results_in_new_tab'] == 1);

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
        <?php if ($targetSelf) : ?>
          customSearchControl.setLinkTarget(google.search.Search.LINK_TARGET_SELF);
        <?php endif ?>
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
