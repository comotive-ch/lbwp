<?php

namespace LBWP\Module\Frontend;

use LBWP\Core;
use LBWP\Theme\Feature\InformationBanner;
use LBWP\Util\Multilang;
use LBWP\Util\Strings;
use LBWP\Util\WordPress;

/**
 * This module contains various frontend filters and output buffers
 * @author Michael Sebel <michael@comotive.ch>
 */
class OutputFilter extends \LBWP\Module\Base
{
  /**
   * @var array All assets (js/css) file found in the output
   */
  protected $assets = array(
    'js' => array(),
    'css' => array()
  );
  /**
   * @var array Remote domains that we're able to parse and load inline
   */
  protected $remoteDomains = array(
    'js' => array(),
    'css' => array(
      'http://fonts.googleapis.com/'
    )
  );
  /**
   * @var array cache default thumbnails to reduce queries
   */
  protected $thumbnailCache = array();
  /**
   * The cloudfront domain
   */
  const CLOUDFRONT_DOMAIN = 'd26bapchbjra74.cloudfront.net';
  /**
   * The late head conten variable
   */
  const LATE_HEAD_CONTENT_VARIABLE = '<!--HEAD-CONTENT-VAR-->';

  /**
   * call parent constructor and initialize the module
   */
  public function __construct()
  {
    parent::__construct();
  }

  /**
   * initializes all the features provided by this module
   */
  public function initialize()
  {
    $yoast = get_option('wpseo_social');
    $hasYoastOgTags = (is_array($yoast) && !isset($yoast['opengraph'])) || $yoast['opengraph'] == true;

    // Filter to add og:tags on single pages if activated and no yoast
    if ($this->features['OutputFilterFeatures']['SingleOgTags'] == 1 && !$hasYoastOgTags) {
      add_filter('wp_head', array($this, 'addSingleOgTags'), 20);
    }

    // Adds an output buffer in frontend to compress css/js
    if ($this->features['OutputFilterFeatures']['CompressCssJs'] == 1 && !is_admin() && !is_login()) {
      add_filter('output_buffer', array($this, 'parseAndReplaceCss'), 5000);
      add_filter('output_buffer', array($this, 'compressCssJs'), 8000);
    }
    if ($this->features['OutputFilterFeatures']['CloudFrontFilter'] == 1) {
      add_filter('output_buffer', array($this, 'replaceDeliveryDomain'), 8100);
    }

    // Filter for header/footer information
    if ($this->features['OutputFilterFeatures']['HeaderFooterFilter'] == 1) {
      add_action('wp_head', array($this, 'addFeedMetaInfo'), 20);
      add_action('wp_head', array($this, 'headerFilter'), 20);
      add_action('wp_footer', array($this, 'footerFilter'), 20);
      // Google search engine (and possibly other) thumbnail info
      add_action('wp_head', array($this, 'addPageThumbnail'), 20);
    }

    // Set a thumbnail id to a post, if not given
    if ($this->config['HeaderFooterFilter:DefaultThumbnailId'] > 0) {
      add_filter('get_post_metadata', array($this, 'getDefaultThumbnailId'), 10, 4);
      add_filter('wpseo_add_opengraph_images', array($this, 'getOgImageDefaultThumbnailId'), 10, 2);
    }

    // Add informational banner feature automatically, if configured in settings
    if ($this->config['Privacy:InformationalBannerActive'] == 1) {
      add_action('wp', array($this, 'initInformationBanner'));
    }

    // Various multilang filters
    if (Multilang::isActive()) {
      add_filter('body_class', array($this, 'addLanguageClass'));
    }

    // Disable emoji css/js crap, unless explicitly activated by theme
    if (apply_filters('disable_wp_emojis_completely', true)) {
      $this->disableEmojiAssets();
    }

    // Allow to print output on post type single headers and do various stuff in header
    // This has 0 priority so that late css/js is loaded before everything else
    add_action('wp_head', array($this, 'addHeaderFiltersEarly'), 0);
    // Register JSON LD output for posts
    add_action('wp_head_single_post', array('\LBWP\Helper\Tracking\MicroData', 'printArticleData'));
    add_action('wp_head_single_page', array('\LBWP\Helper\Tracking\MicroData', 'printPageData'));
    // Remove CSS identifiers
    add_filter('output_buffer', array($this, 'removeCssIdentifiers'), 8200);
    // Replace some super global template variables
    add_filter('output_buffer', array($this, 'replaceTemplateVariables'), 8300);
    // Print late head content by using filters
    add_filter('output_buffer', array($this, 'printLateHeadContent'), 8400);
  }

  public function initInformationBanner()
  {
    InformationBanner::init(array(
      'infoBannerContent' => $this->config['Privacy:InformationalBannerContent'],
      'infoBannerOptout' => $this->config['Privacy:InformationalBannerOptout'],
      'infoBannerButton' => $this->config['Privacy:InformationalBannerButton'],
      'infoBannerButtonOptout' => $this->config['Privacy:InformationalBannerButtonOptout'],
      'infoBannerVersion' => $this->config['Privacy:InformationalBannerVersion']
    ));
  }

  /**
   * @param array $classes body classes
   * @return array list
   */
  public function addLanguageClass($classes)
  {
    $classes[] = 'lang-' . Multilang::getCurrentLang();
    return $classes;
  }

  /**
   * Adds default feed meta info
   */
  public function addFeedMetaInfo()
  {
    echo '
      <link rel="alternate" type="text/xml" title="' . LBWP_HOST . ' - RSS Feed" href="'.  get_bloginfo('rss_url') .'" />
      <link rel="alternate" type="application/atom+xml" title="' . LBWP_HOST . ' - Atom Feed" href="'.  get_bloginfo('atom_url') .'" />
      <link rel="alternate" type="application/rss+xml" title="' . LBWP_HOST . ' - RSS Feed" href="'.  get_bloginfo('rss2_url') .'" />
    ';
  }

  /**
   * @param $html
   * @return mixed
   */
  public function printLateHeadContent($html)
  {
    return str_replace(
      self::LATE_HEAD_CONTENT_VARIABLE,
      apply_filters('add_late_head_content', ''),
      $html
    );
  }

  /**
   * @param string $html output
   * @return string maybe changed output
   */
  public function replaceTemplateVariables($html)
  {
    $variables = array(
      '{year}' => date('Y', current_time('timestamp'))
    );

    foreach ($variables as $search => $replace) {
      $html = str_replace($search, $replace, $html);
    }

    return $html;
  }

  /**
   * @param string $html HTML content
   * @return string compressed html code
   */
  public function parseAndReplaceCss($html)
  {
    $html = preg_replace_callback('/<script\s[^>]*src=([\"\']??)([^\" >]*?)\\1[^>]*>(.*)<\/script>/siU', array($this, 'jsReplaceCallback'), $html);
    $html = preg_replace_callback('/<link\s[^>]*href=([\"\']??)([^\" >]*?)\\1[^>]*>(.*)/siU', array($this, 'cssReplaceCallback'), $html);
    return $html;
  }

  /**
   * @param string $html HTML content
   * @return string compressed html code
   */
  public function compressCssJs($html)
  {
    // Print all css assets inline
    foreach ($this->assets['css'] as $file) {
      $code = $this->getFileFromCache($file['source']);
      $html = str_replace(
        $file['replace'],
        '<style type="text/css">' . $code . '</style>',
        $html
      );
    }

    // Print all js assets inline
    foreach ($this->assets['js'] as $file) {
      $code = $this->getFileFromCache($file['source']);
      $html = str_replace(
        $file['replace'],
        '<script type="text/javascript">' . $code . '</script>',
        $html
      );
    }
    return $html;
  }

  /**
   * @param string $html the html content to be output to the browser
   * @return string the changed html code
   */
  public function replaceDeliveryDomain($html)
  {
    // Slashes are so s3 https links don't break with cloudfront domain in them
    // Replace all lbwp-cdn links with cloudfront.net CDN links
    return str_replace('//' . Core::getCdnName(), '//' . self::CLOUDFRONT_DOMAIN, $html);
  }

  /**
   * @param $match
   * @return string
   */
  protected function jsReplaceCallback($match)
  {
    return $this->replaceFile($match, 'js');
  }

  /**
   * @param $match
   * @return string
   */
  protected function cssReplaceCallback($match)
  {
    return $this->replaceFile($match, 'css');
  }

  /**
   * @param array $match the found match
   * @param string $extension the extension to handle (and save the found file into)
   * @return string the min version of the file, if it exists
   */
  protected function replaceFile($match, $extension)
  {
    $domain = 'http://' . LBWP_HOST . '/';

    // is it the current domain
    if (stristr($match[2], $domain) !== false) {

      // Delete everything after the ?, if available
      $pos = intval(strpos($match[2], '?'));
      if ($pos > 0) {
        $version = substr($match[2], $pos);
        $match[2] = substr($match[2], 0, strpos($match[2], '?'));
      }

      // Do the extensions match?
      if (substr(Strings::getExtension($match[2]), 1) != $extension) {
        return $match[0];
      }

      // Make local path
      $filepath = ABSPATH . str_replace($domain, '', $match[2]);
      // Save normal file path as asset source
      $this->assets[$extension][] = array(
        'source' => $filepath,
        'replace' => $match[0]
      );
    } else {
      foreach ($this->remoteDomains[$extension] as $domain) {
        if (stristr($match[2], $domain)) {
          $this->assets[$extension][] = array(
            'source' => $match[2],
            'replace' => $match[0]
          );
        }
      }
    }

    // Just return as it was
    return $match[0];
  }

  /**
   * @param string $file the file to load the content from
   * @return string the file content or empty string
   */
  protected function getFileFromCache($file)
  {
    // try to get from cache
    $content = wp_cache_get('file_' . md5($file), 'CssJsCompression');
    if ($content == false) {
      $content = file_get_contents($file);
      // save to cache
      wp_cache_set('file_' . md5($file), $content, 'CssJsCompression');
    }
    return $content;
  }

  /**
   * Containts all frontend header outputs that are configureable
   */
  public function headerFilter()
  {
    $lines = array();
    // Facebook page id, for better statistics / insights
    if (strlen($this->config['HeaderFooterFilter:FbPageId']) > 0) {
      $lines[] = '<meta property="fb:page_id" content="' . $this->config['HeaderFooterFilter:FbPageId'] . '" />';
    }

    // Google Site Verification (for webmaster tools and other fancy google stuff)
    if (strlen($this->config['HeaderFooterFilter:GoogleSiteVerification']) > 0) {
      $lines[] = '<meta name="google-site-verification" content="' . $this->config['HeaderFooterFilter:GoogleSiteVerification'] . '" />';
    }

    // Set browser color if possible
    if (strlen($this->config['Various:BrowserColor']) > 0) {
      $lines[] = '<meta name="msapplication-TileColor" content="' . $this->config['Various:BrowserColor'] . '">';
      $lines[] = '<meta name="theme-color" content="' . $this->config['Various:BrowserColor'] . '">';
    }

    // Other unchecked html/css/js stuff
    if (strlen($this->config['HeaderFooterFilter:HeaderHtml']) > 0) {
      $lines[] = $this->config['HeaderFooterFilter:HeaderHtml'];
    }

    // If Google Analytics is configured, add the code
    if (strlen($this->config['HeaderFooterFilter:GoogleAnalyticsId']) > 0 || strlen($this->config['HeaderFooterFilter:GoogleTagmanagerId']) > 0) {
      $lines[] = $this->getGoogleAnalyticsCode();
    }

    // Output with line breaks between
    echo implode(PHP_EOL, $lines);
  }

  /**
   * Containts all frontend footer outputs that are configureable
   */
  public function footerFilter()
  {
    $lines = array();

    // Other unchecked html/css/js stuff
    if (strlen($this->config['HeaderFooterFilter:FooterHtml']) > 0) {
      $lines[] = $this->config['HeaderFooterFilter:FooterHtml'];
    }


    // Output with line breaks between
    echo implode(PHP_EOL, $lines);
  }

  /**
   * Removes the id string from CSS in frontend to allow pagespeed to concatenate them
   * @param string $content the output buffered content
   * @return string altered string
   */
  public function removeCssIdentifiers($content)
  {
    global $wp_styles;
    if (is_object($wp_styles) && is_array($wp_styles->registered)) {
      foreach ($wp_styles->registered as $style) {
        $idString = " id='$style->handle-css'";
        $content = str_replace($idString, '', $content);
      }
    }

    return $content;
  }

  /**
   * @return string HTML code for google analytics integration
   */
  protected function getGoogleAnalyticsCode()
  {
    $config = Core::getInstance()->getConfig();
    $isOptout = isset($config['Privacy:InformationalBannerOptout']) && $config['Privacy:InformationalBannerOptout'] == 1;
    $cookieVersion = intval($config['Privacy:InformationalBannerVersion']);

    $script =
      "<script>
        // Provide Opt Out function and actual opt out via documented window method
        var lbwpGaProperty = '" . $this->config['HeaderFooterFilter:GoogleAnalyticsId'] . "';
        var lbwpGtProperty = '" . $this->config['HeaderFooterFilter:GoogleTagmanagerId'] . "';
        var lbwpTrackingDisabler = 'ga-disable-v$cookieVersion-' + lbwpGaProperty;
        var trackingActive = document.cookie.indexOf(lbwpTrackingDisabler) !== -1;
        var lbwpTrackingDisableMsg = trackingActive ? 
          '" . esc_js(__('Die Aufzeichnung des Nutzungsverhaltens wurde aktiviert.', 'lbwp')) . "' : 
          '" . esc_js(__('Die Aufzeichnung des Nutzungsverhaltens wurde deaktiviert.', 'lbwp')) . "';
        
        if(!trackingActive){
          document.cookie = lbwpTrackingDisabler + '=" . ($isOptout ? 'true' : 'false') . "; expires=Thu, 31 Dec 2099 23:59:59 UTC; path=/';
        }
        
        jQuery(function() {
          jQuery('.lbwp-tracking-opt-in a').click(function() {
            document.cookie = lbwpTrackingDisabler + '=' + 
              (trackingActive ? 'false' : 'true') + 
              '; expires=Thu, 31 Dec 2099 23:59:59 UTC; path=/';
            alert(lbwpTrackingDisableMsg);
          });
          
          jQuery('.lbwp-close-info-banner.optin').click(function() {
            document.cookie = lbwpTrackingDisabler + '=false; expires=Thu, 31 Dec 2099 23:59:59 UTC; path=/';
          });
        });
        
        if (document.cookie.indexOf(lbwpTrackingDisabler + '=true') == -1) {
          if (lbwpGtProperty.length > 0) {
            let gaScript = document.createElement('script');
            gaScript.src = 'https://www.googletagmanager.com/gtag/js?id=' + lbwpGtProperty;
            gaScript.setAttribute('async', 'true');
            document.getElementsByTagName('head')[0].appendChild(gaScript);

            window.dataLayer = window.dataLayer || [];
            function gtag(){dataLayer.push(arguments);}
            gtag('js', new Date());

            gtag('config', lbwpGtProperty);
          } else {
            (function(i,s,o,g,r,a,m){i['GoogleAnalyticsObject']=r;i[r]=i[r]||function(){
            (i[r].q=i[r].q||[]).push(arguments)},i[r].l=1*new Date();a=s.createElement(o),
            m=s.getElementsByTagName(o)[0];a.async=1;a.src=g;m.parentNode.insertBefore(a,m)
            })(window,document,'script','https://www.google-analytics.com/analytics.js','ga');
            ga('create', lbwpGaProperty, 'auto');
            ga('set', 'anonymizeIp', true);
            ga('send', 'pageview');
          }
        }
      </script>
    ";

    return $script;
  }

  /**
   * Add some og tags for single pages
   */
  public function addSingleOgTags()
  {
    if (is_single()) {
      global $post;
      echo '<meta property="og:type" content="article" />' . PHP_EOL;
      // the title, article title or page title
      echo '<meta property="og:title" content="' . single_post_title('', false) . '" />' . PHP_EOL;
      // description, get the excerpt or first 200 chars of content
      $description = strip_tags(get_the_excerpt());
      if (strlen($description) > 0) {
        echo '<meta property="og:description" content="' . $description . '" />' . PHP_EOL;
      } else {
        $description = apply_filters('the_content', $post->post_content);
        $description = Strings::chopString(strip_tags($description), 200, true);
        echo '<meta property="og:description" content="' . $description . '" />' . PHP_EOL;
      }
      // get the post thumbnail if possible
      $attId = get_post_thumbnail_id($post->ID);
      $attachmentUrl = wp_get_attachment_image_src($attId, 'large');
      if (Strings::isURL($attachmentUrl[0])) {
        echo '<meta property="og:image" content="' . $attachmentUrl[0] . '" />' . PHP_EOL;
      }
      // the permalink
      echo '<meta property="og:url" content="' . get_permalink($post->ID) . '" />' . PHP_EOL;
    }
  }

  /**
   * Add a page thumbnail of possible for google search results (and possibly other tools)
   */
  public function addPageThumbnail()
  {
    if (is_single()) {
      global $post;
      // get the post thumbnail if possible
      $attId = get_post_thumbnail_id($post->ID);
      $attachmentUrl = wp_get_attachment_image_src($attId, 'thumbnail');
      if (Strings::isURL($attachmentUrl[0])) {
        echo '<meta name="thumbnail" content="' . $attachmentUrl[0] . '" />' . PHP_EOL;
      }
    }
  }

  /**
   * Disable the emoji backwards compat bloatware
   */
  public function disableEmojiAssets()
  {
    // Remove scripts, assets and filters
    remove_action('wp_head', 'print_emoji_detection_script', 7);
    remove_action('admin_print_scripts', 'print_emoji_detection_script');
    remove_action('wp_print_styles', 'print_emoji_styles');
    remove_action('admin_print_styles', 'print_emoji_styles');
    remove_filter('the_content_feed', 'wp_staticize_emoji');
    remove_filter('comment_text_rss', 'wp_staticize_emoji');
    remove_filter('wp_mail', 'wp_staticize_emoji_for_email');
    // Remove the emojis from editor
    add_filter('tiny_mce_plugins', array($this, 'disableEditorEmojis'));
  }

  /**
   * @param array $plugins tinymce plugins
   * @return array same array with emoji plugin removed
   */
  public function disableEditorEmojis($plugins)
  {
    if (is_array($plugins)) {
      return array_diff($plugins, array('wpemoji'));
    } else {
      return array();
    }
  }

  /**
   * @param mixed $value initially null
   * @param int $postId the post id
   * @param string $key the meta key
   * @param bool $single return a single or array value
   * @return int|int[] the thumbnail id
   */
  public function getDefaultThumbnailId($value, $postId, $key, $single)
  {
    // Skip on wp rest request, because gutenberg fails to load metadata in that case
    if (defined('REST_REQUEST') && REST_REQUEST) {
      return $value;
    }
    if ($key == '_thumbnail_id') {
      if (!isset($this->thumbnailCache[$postId])) {
        $this->fillThumbnailCache($postId);
      }

      // We always have a value cached, or directly set to cache, so return this
      return ($single) ? $this->thumbnailCache[$postId] : array($this->thumbnailCache[$postId]);
    }

    return $value;
  }

  /**
   * @param \Yoast\WP\SEO\Values\Open_Graph\Images $imageContainer
   * @return mixed
   */
  public function getOgImageDefaultThumbnailId($imageContainer)
  {
    $postId = WordPress::getPostId();
    // Fill eventual cache if not cached yet
    $this->fillThumbnailCache($postId);
    // If given, provide this image to OG presenter of yoast
    if (isset($this->thumbnailCache[$postId])) {
      $imageUrl = wp_get_attachment_image_url($this->thumbnailCache[$postId], 'medium');
      $imageContainer->add_image($imageUrl);
    }
  }

  /**
   * @param $postId
   */
  protected function fillThumbnailCache($postId)
  {
    $this->thumbnailCache[$postId] = intval($this->wpdb->get_var('
      SELECT meta_value FROM ' . $this->wpdb->postmeta . '
      WHERE meta_key = "_thumbnail_id" AND post_id = ' . intval($postId)
    ));
    if ($this->thumbnailCache[$postId] == 0) {
      $this->thumbnailCache[$postId] = $this->config['HeaderFooterFilter:DefaultThumbnailId'];
    }
  }

  /**
   * Run various wp_head start
   */
  public function addHeaderFiltersEarly()
  {
    $this->runSingularHeadFilters();
    // Add a template comment that is much later replaced in an output buffer
    echo self::LATE_HEAD_CONTENT_VARIABLE;
  }

  /**
   * Runs various hookable filters in head area for singular pages of post types
   */
  public function runSingularHeadFilters()
  {
    if (is_singular()) {
      global $post;
      do_action('wp_head_single', $post);
      do_action('wp_head_single_' . $post->post_type, $post);
    }
  }
}