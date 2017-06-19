<?php

namespace LBWP\Module\General\Cms;

use LBWP\Module\BaseSingleton;
use LBWP\Util\File;

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
    'rewrite_cookieless_domain' => false,
    'cookieless_domain' => 'assets01.sdd1.ch',
    'rewrite_asset_versions' => false,
    'force_local_development' => false,
    'link_regex' => '/(?:(?:https?|ftp|file):\/\/|www\.|ftp\.)(?:\([-A-Z0-9+&@#\/%=~_|$?!:,.]*\)|[-A-Z0-9+&@#\/%=~_|$?!:,.])*(?:\([-A-Z0-9+&@#\/%=~_|$?!:,.]*\)|[A-Z0-9+&@#\/%=~_|$])/im'
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
   * Register all output filters, depending on config
   */
  public function addOutputFilters()
  {
    if (defined('LOCAL_DEVELOPMENT') && !$this->settings['force_local_development']) {
      return;
    }

    if ($this->settings['rewrite_asset_versions']) {
      add_filter('output_buffer', array($this, 'rewriteAssetVersions'), 8050);
    }

    if ($this->settings['rewrite_cookieless_domain']) {
      add_filter('wp_head', array($this, 'addCookielessDnsPrefetch'), 5);
      add_filter('output_buffer', array($this, 'rewriteCookielessDomain'), 8060);
    }
  }

  /**
   * Add a prefetch info for the cookieless domain
   */
  public function addCookielessDnsPrefetch()
  {
    echo '<link rel="dns-prefetch" href="//' . $this->settings['cookieless_domain'] . '" />' . PHP_EOL;
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
   * @param string $html original
   * @return string rewritten all asset versions into the file name
   */
  public function rewriteAssetVersions($html)
  {
    return preg_replace_callback($this->settings['link_regex'], function($match) {
      if (
        fnmatch('*' . LBWP_HOST . '*.css?ver=*', $match[0]) ||
        fnmatch('*' . LBWP_HOST . '*.js?ver=*', $match[0])
      ) {
        $verpos = strrpos($match[0], '?ver=');
        $version = str_replace('.', '', substr($match[0], $verpos + 5));
        $link = substr($match[0], 0, $verpos);
        $extension = File::getExtension($link);
        $link = str_replace($extension, '__' . intval($version) . $extension, $link);
        return $link;
      }
      return $match[0];
    }, $html);
  }
}