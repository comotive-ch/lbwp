<?php

namespace LBWP\Module\General\Cms\DeadLinkChecker;

use LBWP\Util\Multilang;
use LBWP\Util\Strings;

/**
 * Resolves links to the own site without an http request, so results don't depend on
 * caching or password protection. Returns null for urls it can't decide on.
 * @package LBWP\Module\General\Cms\DeadLinkChecker
 * @author Mirko Baffa <mirko@comotive.ch>
 */
class InternalLinkResolver
{
  /**
   * Lowercase hosts of the own site
   * @var string[]
   */
  protected $hosts = [];
  /**
   * Upload base url and directory
   * @var array keys baseurl and basedir
   */
  protected $uploads = [];

  /**
   * Collect the hosts and upload paths of the site
   */
  public function __construct()
  {
    $urls = [home_url(), site_url()];
    foreach (Multilang::getAllLanguages() as $language) {
      $urls[] = Multilang::getHomeUrl($language);
    }

    foreach ($urls as $url) {
      $this->hosts[] = strtolower((string) parse_url($url, PHP_URL_HOST));
    }

    $this->hosts = array_unique(array_filter($this->hosts));
    $this->uploads = wp_get_upload_dir();
  }

  /**
   * Check if an url points to the own site
   *
   * @param string $url the url
   * @return bool true if internal
   */
  public function isInternal($url)
  {
    return in_array(strtolower((string) parse_url($url, PHP_URL_HOST)), $this->hosts);
  }

  /**
   * Resolve an internal url to an http like code
   *
   * @param string $url an internal url
   * @return int|null 200 or 404, null if it can't be resolved locally
   */
  public function resolve($url)
  {
    $upload = $this->resolveUpload($url);
    if ($upload !== null) {
      return $upload;
    }

    $path = trim((string) parse_url($url, PHP_URL_PATH), '/');
    $query = (string) parse_url($url, PHP_URL_QUERY);
    if ($path === '' && $query === '') {
      return 200;
    }

    // url_to_postid returns ?p= ids unchecked, so verify the status. Anything else falls back to http
    $postId = url_to_postid($url);
    return $postId > 0 && get_post_status($postId) === 'publish' ? 200 : null;
  }

  /**
   * Resolve an url to the uploads folder by checking the file
   *
   * @param string $url an internal url
   * @return int|null 200 or 404 for uploads, null for other urls
   */
  protected function resolveUpload($url)
  {
    // With a CDN the files are not on the local disk, let the http check handle them
    if (defined('CDN_TYPE') && CDN_TYPE != CDN_TYPE_NONE) {
      return null;
    }

    $baseUrl = set_url_scheme($this->uploads['baseurl'], parse_url($url, PHP_URL_SCHEME));
    if (!Strings::startsWith($url, $baseUrl . '/')) {
      return null;
    }

    $relative = rawurldecode(explode('?', substr($url, strlen($baseUrl)), 2)[0]);
    if (strpos($relative, '..') !== false) {
      return null;
    }

    return file_exists($this->uploads['basedir'] . $relative) ? 200 : 404;
  }
}
