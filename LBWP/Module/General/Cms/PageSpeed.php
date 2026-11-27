<?php

namespace LBWP\Module\General\Cms;

use LBWP\Module\BaseSingleton;
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
    'brunslify_images' => false,
    'brunslify_original' => false,
    // The sizes of images being converted (latter three are wp internal sizes used for responsive images)
    'brunsilfy_sizes' => array('medium', 'large', 'medium_large', '1536x1536', '2048x2048'),
    'rewrite_cookieless_domain' => false,
    'rewrite_cookieless_only_assets' => false,
    'cookieless_domain' => 'assets01.sdd1.ch',
    'rewrite_asset_versions' => false,
    'rewrite_asset_extension' => '',
    'force_local_development' => false
  );

  /**
   * Called at init, doesn't yet to anything there, waits for wp to run
   */
  public function run()
  {
    add_action('wp', array($this, 'addOutputFilters'), 20);
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
  public function rewriteCookielessDomainOnlyAssets($html): string
  {
    return $this->replaceOwnHostInAssetUrls($html, 'assets/lbwp-cdn/');
  }

  /**
   * @param string $html original
   * @return string rewritten all assets to cookieless domain
   */
  public function rewriteCookielessDomain($html): string
  {
    return $this->replaceOwnHostInAssetUrls($html, 'wp-content/[^/\s"\'<>]+/|wp-includes/|assets/lbwp-cdn/');
  }

  /**
   * Replaces the own host with the cookieless domain in absolute urls whose path starts with one of the given prefixes.
   * A single regex on candidate urls only, instead of a callback per link in the page (~30x faster on real pages)
   * @param string $html original
   * @param string $pathPrefixes regex alternation of path prefixes after the host
   * @return string the html with rewritten asset urls
   */
  protected function replaceOwnHostInAssetUrls(string $html, string $pathPrefixes): string
  {
    $pattern = '~(?<=[a-z]://)' . preg_quote(LBWP_HOST, '~') . '(?=/(?:' . $pathPrefixes . ')[^\s"\'<>]*\.)~';
    return preg_replace($pattern, $this->settings['cookieless_domain'], $html) ?? $html;
  }

  /**
   * Moves the ?ver= of own css/js urls into the file name (style.css?ver=1.2 => style__12.css)
   * @param string $html original
   * @return string rewritten all asset versions into the file name
   */
  public function rewriteAssetVersions($html): string
  {
    $pattern = '~(?<=[a-z]:)(//' . preg_quote(LBWP_HOST, '~') . '[^\s"\'<>?]*)(\.(?:css|js))\?ver=([^\s"\'<>&,);]*)~';
    return preg_replace_callback($pattern, function ($match) {
      $version = intval(str_replace('.', '', $match[3]));
      return $match[1] . '__' . $version . $this->settings['rewrite_asset_extension'] . $match[2];
    }, $html) ?? $html;
  }
}