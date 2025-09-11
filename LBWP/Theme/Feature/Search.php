<?php

namespace LBWP\Theme\Feature;

use LBWP\Core as LbwpCore;
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
    'containerTemplate' => '
      <div class="{containerClasses}">{loadingText}</div>
      <input type="submit" id="getApiResults" class="{buttonClasses}" value="{buttonText}" />
    ',
    'imageTemplate' => '<div class="img"><a href="{url}" target="{target}">{image}</a></div>',
    'metaTemplate' => '<em>{meta}</em> &ndash; ',
    'itemTemplate' => '
      <article class="{classes}">
        {imageTemplate}
        <h2><a href="{url}" target="{target}">{title}</a></h2>
        <p>{metaTemplate} {description}</p>
      </article>
    ',
    // Settings for search API
    'errorMessage' => '',                         // The error message if there are no search results
    'loadingText' => '',                          // The text that is displayed when loading
    'buttonText' => '',                           // The text that is displayed in the "more results" button
    'buttonClasses' => 'lbwp-button',             // "more results" button class, can be multiple (sep. by space), if needed
    'apiKey' => 'AIzaSyDcRBXI37rJlgSUCx1X0qy0cL_XVKKKFUE',  // Defaults to our main key
    'containerClasses' => 'lbwp-gss-results',     // Container class, can be multiple (sep. by space), if needed
    'filterResults' => false,                     // true filters the results (if post) for search term existence
    'forceFilterResults' => false,                // Forces no results if filterResults removed every result
    'addLocalResultsFirstPage' => array(),        // adds additional db query results to the first page for desired posttypes
    'filterLanguageByUrl' => false,               // true filters the results by url (looking for starting language tag in url)
    'filterLanguageByUrl_langsWithNoPrefix' => array(), // list of languages that have no prefix, used in filterLanguageByUrl
    'filterByBlacklist' => array(),               // Filters the results by wildcard blacklist
    'displayImages' => true,                      // Display images, if available
    'displayFiles' => false,                      // Skip file search results completely
    'fileBlackList' => array('xml', 'css', 'js', 'json'), // XML and various assets files are not allowed to show
    'rawResultFallback' => false,                 // Fallback to a raw result, if postType matching didn't work
    'rawResultFallbackUseImages' => true,         // Wheter to use google defined images for raw result fallback
    'imageFallback' => '<div></div>',             // Fallback html, if no image can be displayed
    'postTypes' => array('post', 'page'),         // Types that can be actually found and are not filtered
    'postTypesImgFallback' => array('page'),      // Types where a fallback to googles image is allowed
    'postTypesShowDate' => array('post'),         // Types where the date is showed as meta
    'textFieldType' => 'text',                    // Type of the text field for search input
    'textFieldClass' => '',                       // A class to be set onto the text field
    'textFieldId' => 'gss_query',                 // ID of the search input field
    'textFieldPlaceHolder' => ''                 // Search placeholder for input field
  );
  /**
   * The endpoint for xml requests
   */
  const JSON_ENDPOINT = 'https://www.googleapis.com/customsearch/v1?cx={searchEngineId}&key={apiKey}&q={searchTerm}&gl={language}';
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
  public static function setApiConfig($config = array())
  {
    self::$apiConf = array_merge(self::$apiConf, self::getDefaultTexts(), $config);
    // Register the api function to be called from printed scripts
    add_action('rest_api_init', array('\LBWP\Theme\Feature\Search', 'registerApiEndpoint'));
  }

  /**
   * API Endpoint to load search results
   */
  public static function registerApiEndpoint()
  {
    register_rest_route('lbwp/search', 'result', array(
      'methods' => \WP_REST_Server::CREATABLE,
      'callback' => array('\LBWP\Theme\Feature\Search', 'getApiSearchResults')
    ));
  }

  /**
   * @return array default texts array
   */
  public static function getDefaultTexts()
  {
    return array(
      'errorMessage' => __('Ihre Suche ergab keine Ergebnisse.', 'lbwp'),
      'buttonText' => __('Weitere Suchergebnisse', 'lbwp'),
      'loadingText' => __('Suchergebnisse werden geladen...', 'lbwp'),
    );
  }

  /**
   * Print HTML container and add JS to footer to actually get results by xhr
   */
  public static function printApiSearchResults()
  {
    // Add a JS that does the actual search request
    wp_enqueue_script('lbwp-search-api', File::getResourceUri() . '/js/lbwp-search-api.js', array('jquery'), LbwpCore::REVISION, true);

    // Print the container that will be filled (it also contains the button
    return Templating::getBlock(self::$apiConf['containerTemplate'], array(
      '{containerClasses}' => self::$apiConf['containerClasses'],
      '{buttonText}' => self::$apiConf['buttonText'],
      '{buttonClasses}' => self::$apiConf['buttonClasses'],
      '{loadingText}' => self::$apiConf['loadingText']
    ));
  }

  /**
   * Prints the search results with a template and by using xml results
   */
  public static function getApiSearchResults()
  {
    // Get the config and init the result array
    $config = LbwpCore::getInstance()->getConfig();
    $terms = array_map('trim', explode(' ', $_POST['search']));
    if (count($terms) == 1) {
      $terms = array_map('trim', explode('+', $terms[0]));
    }

    // Prepare the url to be called
    $url = self::JSON_ENDPOINT;
    $url = str_replace('{searchEngineId}', $config['Various:GoogleEngineId'], $url);
    $url = str_replace('{searchTerm}', urlencode($_POST['search']), $url);
    $url = str_replace('{apiKey}', self::$apiConf['apiKey'], $url);
    // Set the language, defaulting to wordpress locale
    $language = substr(get_locale(), 0, 2);
    if (Multilang::isActive()) {
      $language = isset($_POST['lang']) ? $_POST['lang'] : Multilang::getCurrentLang();
      // Reload text domain in rest context, as polylang cant
      Multilang::resetTextDomainOnRest('lbwp', 'lbwp', $language);
      self::$apiConf = array_merge(self::$apiConf, self::getDefaultTexts(), $config);
    } else if (Multilang::isWeGlot()) {
      $language = Multilang::getWeGlotLanguage();
    }
    $url = str_replace('{language}', $language, $url);

    // Add start / num parameters
    $page = (intval($_POST['page']) > 0) ? intval($_POST['page']) : 1;
    $start = ($page > 1) ? (($page - 1) * self::RESULTS_PER_PAGE)+1 : 1;
    $url .= '&start=' . $start . '&num=' . self::RESULTS_PER_PAGE . '';

    // Make a simple call and convert the xml doc to an array
    $key = md5($url);
    $cachedResult = wp_cache_get($key, 'SearchApi');

    // If not in cache, make the request and cache the ajax resonse
    if (!is_array($cachedResult)) {
      $data = json_decode(file_get_contents($url), true);
      $nativeResultCount = is_array($data['items']) ? count($data['items']) : 0;
      // Filter the results as of config
      $results = self::prepareAndFilterResults($data, $terms, $language);
      // Add more results on first page if configured to also query locally
      if ($page == 1 && count(self::$apiConf['addLocalResultsFirstPage']) > 0) {
        self::addLocalDbQueryResults($results, $terms);
      }

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
            '{target}' => $result['target'],
            '{metaTemplate}' => self::getMetaTemplate($result),
            '{description}' => $result['description']
          ));
        }

        $cachedResult = array(
          'resultCount' => count($results),
          'nativeCount' => $nativeResultCount,
          'html' => $itemHtml,
          'call' => $url,
          'cached' => false
        );
      } else {
        $cachedResult = array(
          'resultCount' => 0,
          'nativeCount' => $nativeResultCount,
          'html' => '<p class="gss-no-results">' . self::$apiConf['errorMessage'] . '</p>',
          'call' => $url,
          'cached' => false
        );
      }

      // Set the result to cache
      wp_cache_set($key, $cachedResult, 'SearchApi', 86400);
    } else {
      $cachedResult['cached'] = true;
    }

    return $cachedResult;
  }

  /**
   * @param $results
   * @param $terms
   * @param $language
   * @return void
   */
  protected static function addLocalDbQueryResults(&$results, $terms)
  {
    // Query post_content and post_title and post_excerpt for the $terms natively
    $db = WordPress::getDb();
    $query = '
      SELECT ID, post_name, post_title, post_content, post_excerpt, post_date FROM ' . $db->posts . '
      WHERE post_status = "publish" AND post_type IN ("' . implode('","', self::$apiConf['addLocalResultsFirstPage']) . '") AND (
    ';
    $query .= "post_title LIKE '%" . implode(' ', $terms) . "%' OR post_content LIKE '%" . implode(' ', $terms) . "%' OR post_excerpt LIKE '%" . implode(' ', $terms) . "%' OR ";
    $query = substr($query, 0, -4) . ')';
    $raw = $db->get_results($query, ARRAY_A);

    // Do a more open search if nothing was found
    if (count($raw) == 0) {
      $query = '
        SELECT ID, post_name, post_title, post_content, post_excerpt, post_date FROM ' . $db->posts . '
        WHERE post_status = "publish" AND post_type IN ("' . implode('","', self::$apiConf['addLocalResultsFirstPage']) . '") AND (
      ';
      foreach ($terms as $term) {
        $query .= "post_title LIKE '%" . $term . "%' OR post_content LIKE '%" . $term . "%' OR post_excerpt LIKE '%" . $term . "%' OR ";
      }
      $query = substr($query, 0, -4) . ')';
      $raw = $db->get_results($query, ARRAY_A);
    }

    // add the $results but only if post_name doesn't match existing urls in it
    foreach ($raw as $item) {
      $existing = false;
      foreach ($results as $result) {
        if (Strings::contains($result['url'], $item['post_name'])) {
          $existing = true;
        }
      }

      if (!$existing) {
        $results[] = array(
          'url' => get_permalink($item['ID']),
          'classes' => 'result-item',
          'imageHtml' => self::$apiConf['imageFallback'],
          'title' => $item['post_title'],
          'meta' => date_i18n(get_option('date_format'), strtotime($item['post_date'])),
          'description' => self::getPostDescription($item, array('snippet' => $item['post_excerpt'])),
          'target' => '_self',
          'type' => 'content'
        );
      }
    }
  }

  /**
   * @param array $result a search result
   * @return string the meta template, if meta is given and valid
   */
  protected static function getMetaTemplate($result)
  {
    if (isset($result['meta']) && strlen($result['meta']) > 0) {
      return Templating::getBlock(self::$apiConf['metaTemplate'], array(
        '{meta}' => $result['meta']
      ));
    }

    return '';
  }

  /**
   * @param \WP_Post $post result object
   * @param array $raw the engine result
   * @return string the image html or empty string
   */
  protected static function getPostImageHtml($post, $raw)
  {
    $imageHtml = '';
    $thumbnailId = get_post_thumbnail_id($post->ID);
    if ($thumbnailId > 0) {
      $url = WordPress::getImageUrl($thumbnailId, 'medium');
      if (Strings::isURL($url)) {
        return Templating::getBlock(self::$apiConf['imageTemplate'], array(
          '{url}' => get_permalink($post->ID),
          '{target}' => '_self',
          '{image}' => '<img src="' . $url . '" alt="' . $post->post_title . '">'
        ));
      }
    }

    // Use the engine fallback, if allowed and available
    if (in_array($post->post_type, self::$apiConf['postTypesImgFallback'])) {
      if (isset($raw['pagemap']['cse_image'][0]) && Strings::checkURL($raw['pagemap']['cse_image'][0]['src'])) {
        $imageHtml = Templating::getBlock(self::$apiConf['imageTemplate'], array(
          '{url}' => $raw['link'],
          '{target}' => '_self',
          '{image}' => '<img src="' . $raw['pagemap']['cse_image'][0]['src'] . '" alt="' . $raw['title'] . '">'
        ));
      }
    }

    return self::$apiConf['imageFallback'];
  }

  /**
   * @param \WP_Post $post result object
   * @param array $raw the engine result, used for fallbacks
   * @return string a description for the object
   */
  protected static function getPostDescription($post, $raw)
  {
    $description = get_post_meta($post->ID, '_yoast_wpseo_metadesc', true);
    $alternate = get_post_meta($post->ID, 'lbwp_index_search_content', true);
    // Fallback to something else if yoast variables are unresolved for some reason
    if (Strings::contains($description, '%%'))
      $description = '';
    if (strlen($description) == 0)
      $description = strip_tags($post->post_excerpt);
    if (strlen($description) == 0 && strlen($alternate) > 0)
      $description = Strings::chopToWords(strip_tags($alternate), 40, true);;
    if (strlen($description) == 0 && !self::containsShortcode($post->post_content) && ($post->post_type == 'post' || $post->post_type == 'page'))
      $description = Strings::chopToWords(strip_tags(strip_shortcodes($post->post_content)), 40, true);
    if (strlen($description) == 0)
      $description = $raw['snippet'];

    // Remove everything that looks like a shortcode
    $description = preg_replace('/\[[^\]]+\]/', '', $description);

    return $description;
  }

  /**
   * Checks if the content contains a shortcode and is hence in-usable for displaying a search result
   * @param string $html from post_content or similar
   * @return bool true|false if the $html contains a shortcode
   */
  protected static function containsShortcode($html)
  {
    return Strings::contains($html, '[') && Strings::contains($html, ']');
  }

  /**
   * @param array $data full engine search item results
   * @param array $terms the search terms given in
   * @param string $language the language tag
   * @return array $results
   */
  protected static function prepareAndFilterResults($data, $terms, $language)
  {
    $results = array();
    $languages = Multilang::getLanguagesKeyValue();
    if (Multilang::isWeGlot()) {
      $languages = Multilang::getWeGlotLanguages();
    }
    // If there are results, show them
    if (isset($data['items']) && count($data['items']) > 0) {
      foreach ($data['items'] as $item) {
        // Skip the item, if on blacklist
        $isBlacklisted = false;
        foreach (self::$apiConf['filterByBlacklist'] as $pattern) {
          if (fnmatch($pattern, $item['link'])) {
            $isBlacklisted = true;
          }
        }
        // If blacklisted, skip
        if ($isBlacklisted) continue;

        // CHeck the language if needed
        if (self::$apiConf['filterLanguageByUrl']) {
          $parts = parse_url($item['link']);
          // Does the URI start with the language tag
          if (Strings::startsWith($parts['path'], '/' . $language . '/')) {
            $allowed = true;
          } else {
            // If not, it's only allowed if it doesn't start with any other langage tag
            $matches = 0;
            $hasLanguageTag = false;
            $copy = $languages;
            unset($copy[$language]);
            foreach ($copy as $lang => $name) {
              if (Strings::startsWith($parts['path'], '/' . $lang . '/')) {
                $matches++;
                $hasLanguageTag = true;
              }
            }
            // If it doesn't have a language tag its disallowed if the current language isnt a non prefixed one
            if (!$hasLanguageTag && count(self::$apiConf['filterLanguageByUrl_langsWithNoPrefix']) > 0) {
              if (!in_array($language, self::$apiConf['filterLanguageByUrl_langsWithNoPrefix'])) {
                $matches = 1;
              }
            }
            // If no matches with other langs, the url is allowed
            $allowed = ($matches == 0);
          }

          // If not allowed by above rules, skip the result
          if (!$allowed) continue;
        }

        // Extract the post name from the url
        $url = substr($item['link'], 0, -1);
        $postName = substr($url, strrpos($url, '/') + 1);
        $postId = WordPress::getPostIdByName($postName, self::$apiConf['postTypes']);
        $postObject = get_post($postId);
        if ($postId > 0 && $postObject instanceof \WP_Post && $postObject->post_status == 'publish') {
          $results[] = array(
            'url' => get_permalink($postObject->ID),
            'classes' => implode(' ', get_post_class('result-item', $postObject->ID)),
            'imageHtml' => self::getPostImageHtml($postObject, $item),
            'title' => $postObject->post_title,
            'meta' => (in_array($postObject->post_type, self::$apiConf['postTypesShowDate']))
              ? date_i18n(get_option('date_format'), strtotime($postObject->post_date))
              : '',
            'description' => self::getPostDescription($postObject, $item),
            'target' => '_self',
            'type' => 'content'
          );
        } else if (self::$apiConf['displayFiles'] && isset($item['fileFormat'])) {
          // Insert file data into our structure
          $extension = substr(File::getExtension($item['link']), 1);
          if (!in_array($extension, self::$apiConf['fileBlackList'])) {
            $results[] = array(
              'url' => $item['link'],
              'classes' => 'result-file ext-' . $extension,
              'extension' => $extension,
              'imageHtml' => self::$apiConf['imageFallback'],
              'title' => $item['title'],
              'meta' => $item['fileFormat'],
              'description' => $item['snippet'],
              'target' => '_blank',
              'type' => 'file'
            );
          }
        } else if (self::$apiConf['rawResultFallback']) {
          // Get an image, if possible, else fallback
          $imageHtml = self::$apiConf['imageFallback'];
          if (self::$apiConf['rawResultFallbackUseImages'] && isset($item['pagemap']['cse_image'][0]) && Strings::checkURL($item['pagemap']['cse_image'][0]['src'])) {
            $imageHtml = Templating::getBlock(self::$apiConf['imageTemplate'], array(
              '{url}' => $item['link'],
              '{target}' => '_self',
              '{image}' => '<img src="' . $item['pagemap']['cse_image'][0]['src'] . '" alt="' . $item['title'] . '">'
            ));
          }

          // Insert data into our structure, but check for file blacklist as well
          $extension = trim(substr(File::getExtension($item['link']), 1));
          if (strlen($extension) == 0 || !in_array($extension, self::$apiConf['fileBlackList'])) {
            $results[] = array(
              'url' => $item['link'],
              'classes' => 'result-raw',
              'imageHtml' => $imageHtml,
              'title' => $item['title'],
              'description' => $item['snippet'],
              'target' => '_self',
              'type' => 'raw'
            );
          }
        }
      }
    }

    // Let developers filter the results
    $results = apply_filters('google_api_search_results', $results, self::$apiConf);

    // If wanted, filter out elements not matching
    if (self::$apiConf['filterResults']) {
      $backupResults = $results;
      $results = array_filter($results, function($result) use ($terms) {
        foreach ($terms as $term) {
          // If it is a file, it is allowed
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
      if (count($results) == 0 && !self::$apiConf['forceFilterResults']) {
        $results = $backupResults;
      }
    }

    return $results;
  }

  /**
   * Get a frontend API search input (remember to always use GET)
   * @return string the engine search input
   */
  public static function getApiSearchInput()
  {
    $attributes = '';

    // Set a placeholder if given
    if (strlen(self::$apiConf['textFieldPlaceHolder']) > 0) {
      $attributes .= ' placeholder="' . self::$apiConf['textFieldPlaceHolder'] . '"';
    }

    // Set a class if given
    if (strlen(self::$apiConf['textFieldClass']) > 0) {
      $attributes .= ' class="' . self::$apiConf['textFieldClass'] . '"';
    }

    // Print the script and form input code
    return '
      <input
        type="' . self::$apiConf['textFieldType'] . '"' . $attributes . '
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
        <?php _e('Suche nach', 'lbwp'); ?> &quot;<?php echo strip_tags($_REQUEST['q']); ?>&quot; <?php _e('auf', 'lbwp'); ?> Google
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
