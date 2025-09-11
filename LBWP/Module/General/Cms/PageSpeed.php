<?php

namespace LBWP\Module\General\Cms;

use LBWP\Module\BaseSingleton;
use LBWP\Util\File;
use LBWP\Helper\Brunsli;
use LBWP\Core as LbwpCore;

/**
 * Simple rewrite/reformat module to fit front performance requirements
 * @package LBWP\Module\General\Cms
 * @author Michael Sebel <michael@comotive.ch>
 */
class PageSpeed extends BaseSingleton
{
  /**
   * @var array config for which modules to load
   */
  protected $settings = array(
    'image_auto_lazyload' => false,
    'lazyload_runtime_disable' => false,
    'brunslify_images' => false,
    'brunslify_original' => false,
    // The sizes of images being converted (latter three are wp internal sizes used for responsive images)
    'brunsilfy_sizes' => array('medium', 'large', 'medium_large', '1536x1536', '2048x2048'),
    'rewrite_cookieless_domain' => false,
    'rewrite_cookieless_only_assets' => false,
    'cookieless_domain' => 'assets01.sdd1.ch',
    'rewrite_asset_versions' => false,
    'rewrite_asset_extension' => '',
    'force_local_development' => false,
    'link_regex' => '~[a-z]+://\S+~'
  );

  /**
   * Called at init, doesn't yet to anything there, waits for wp to run
   */
  public function run()
  {
    add_action('wp', array($this, 'addOutputFilters'), 20);
    //add_filter('wp_generate_attachment_metadata', array($this, 'brunslifyImages'), 100);
  }

  /**
   * Can be called until 20 to configure which filters to run or not
   * @param array $config
   */
  public function setConfig($config)
  {
    $this->settings = array_merge($this->settings, $config);
  }

  /**
   * Allows to disable lazy loaded images during runtime of the page
   */
  public static function disableLazyImages()
  {
    self::$instance[__CLASS__]->changeSetting('lazyload_runtime_disable', true);
  }

  /**
   * @param $key
   * @param $value
   */
  public function changeSetting($key, $value)
  {
    $this->settings[$key] = $value;
  }

  /**
   * Register all output filters, depending on config
   */
  public function addOutputFilters()
  {
    $admin = is_admin();
    if ($this->settings['image_auto_lazyload']) {
      if (!$admin && !isset($_GET['wc-ajax'])) {
        wp_enqueue_script('lazysizes', File::getResourceUri() . '/js/lazysizes.min.js', array(), '1.0', false);
        add_filter('output_buffer', array($this, 'rewriteLazyLoadImages'), 8530);
      }
      add_action('wp_footer', array($this, 'prepareLazyImages'));
    }

    if (defined('LOCAL_DEVELOPMENT') && !$this->settings['force_local_development']) {
      return;
    }

    if ($this->settings['rewrite_asset_versions'] && !$admin) {
      add_filter('output_buffer', array($this, 'rewriteAssetVersions'), 8510);
    }
    if ($this->settings['rewrite_cookieless_domain'] && !$admin) {
      add_filter('wp_head', array($this, 'addCookielessDnsPrefetch'), 5);
      if ($this->settings['rewrite_cookieless_only_assets']) {
        add_filter('output_buffer', array($this, 'rewriteCookielessDomainOnlyAssets'), 8520);
      } else {
        add_filter('output_buffer', array($this, 'rewriteCookielessDomain'), 8520);
      }
    }
  }

  /**
   * Add a prefetch info for the cookieless domain
   */
  public function addCookielessDnsPrefetch()
  {
    echo '<link rel="preconnect" href="https://' . $this->settings['cookieless_domain'] . '" />' . PHP_EOL;
  }

  /**
   * @param string $html original
   * @return string rewritten all assets from cdn only, not includes, or wp stuff
   */
  public function rewriteCookielessDomainOnlyAssets($html)
  {
    return preg_replace_callback($this->settings['link_regex'], function($match) {
      if (
        fnmatch('*' . LBWP_HOST . '/assets/lbwp-cdn/*.*', $match[0])
      ) {
        return str_replace(LBWP_HOST, $this->settings['cookieless_domain'], $match[0]);
      }
      return $match[0];
    }, $html);
  }

  /**
   * @param string $html original
   * @return string rewritten all assets to cookieless domain
   */
  public function rewriteCookielessDomain($html)
  {
    return preg_replace_callback($this->settings['link_regex'], function($match) {
      if (
        // Only use this on know asset links
        fnmatch('*' . LBWP_HOST . '/wp-content/*/*.*', $match[0]) ||
        fnmatch('*' . LBWP_HOST . '/wp-includes/*.*', $match[0]) ||
        fnmatch('*' . LBWP_HOST . '/assets/lbwp-cdn/*.*', $match[0])
      ) {
        return str_replace(LBWP_HOST, $this->settings['cookieless_domain'], $match[0]);
      }
      return $match[0];
    }, $html);
  }

  /**
   * @param $html
   * @return mixed
   */
  public function rewriteLazyLoadImages($html)
  {
    // Do this with some simple replaces
    if (!$this->settings['lazyload_runtime_disable']) {
      $html = str_replace('<img src=', '<img data-lazyload="1" data-src=', $html);
      $html = str_replace(' srcset=', ' data-srcset=', $html);
      $html = str_replace(' sizes="(', ' data-sizes="(', $html);
    }
    return $html;
  }

  /**
   * Add the lazyload class to all lazyloaded images, thus preserving images with existing classes
   */
  public function prepareLazyImages()
  {
    echo '
      <script type="text/javascript">
        jQuery(function() {
          // Switch sources immediately for images that should skip 
          jQuery(".disable-lazy-loading img[data-lazyload=1]").each(function() {
            var img = jQuery(this);
            img
              .attr("src", img.data("src"))
              .attr("srcset", img.data("srcset"))
              .attr("sizes", img.data("sizes"))
              .removeAttr("data-lazyload");
          });
          // Set actual lazy load class for every image left
          jQuery("[data-lazyload=1]").addClass("lazyload").removeAttr("data-lazyload");
        });
      </script>
    ';
  }

  /**
   * @param string $html original
   * @return string rewritten all asset versions into the file name
   */
  public function rewriteAssetVersions($html)
  {
    return preg_replace_callback($this->settings['link_regex'], function ($match) {
      if (
        fnmatch('*' . LBWP_HOST . '*.css?ver=*', $match[0]) ||
        fnmatch('*' . LBWP_HOST . '*.js?ver=*', $match[0])
      ) {
        $lastChar = mb_substr($match[0], -1);
        $verpos = strrpos($match[0], '?ver=');
        $version = str_replace('.', '', substr($match[0], $verpos + 5));
        $link = substr($match[0], 0, $verpos);
        $extension = substr($link,strripos($link,'.'));
        return str_replace($extension, '__' . intval($version) . $this->settings['rewrite_asset_extension'] . $extension, $link) . $lastChar;
      }
      return $match[0];
    }, $html);
  }

  /**
   * @param $meta
   * @param $attachmentId
   * @return mixed
   */
  public function brunslifyImages($meta)
  {
    if ($this->settings['brunslify_images'] && !defined('LOCAL_DEVELOPMENT') && isset($meta['file']) && $meta['sizes']['thumbnail']['mime-type'] == 'image/jpeg') {
      $images = array();
      list($folder, $original) = explode('/', $meta['file']);
      if ($this->settings['brunslify_original']) {
        $images[] = $original;
      }
      foreach ($this->settings['brunsilfy_sizes'] as $size) {
        if (isset($meta['sizes'][$size])) {
          $images[] = $meta['sizes'][$size]['file'];
        }
      }

      // Make brunsli callbacks
      $base = LbwpCore::getCdnFileUri();
      $domain = getLbwpHost();

      foreach ($images as $image) {
        Brunsli::convert(array(
          'url' => $base . '/' . $folder . '/' . $image,
          'domain' => $domain,
          'reference' => $folder . '/' . $image
        ));
      }
    }

    return $meta;
  }
}