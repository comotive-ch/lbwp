<?php

namespace LBWP\Module\General\Cms\DeadLinkChecker;

use LBWP\Util\Strings;

/**
 * Extracts checkable, normalized absolute urls from post content and meta values
 * @package LBWP\Module\General\Cms\DeadLinkChecker
 * @author Mirko Baffa <mirko@comotive.ch>
 */
class LinkExtractor
{
  /**
   * @var string[] file endings that are not checked
   */
  const EXCLUDED_EXTENSIONS = [
    'jpg', 'jpeg', 'gif', 'png', 'svg', 'webp', 'avif', 'eps', 'csv'
  ];
  /**
   * @var string[] paths of the own site that are not checked
   */
  const EXCLUDED_PATHS = [
    '/wp-admin', '/wp-login.php', '/wp-json', '/xmlrpc.php'
  ];
  /**
   * @var string[] hosts that block bots anyway, extend with the lbwp_dlc_ignored_hosts filter
   */
  const IGNORED_HOSTS = [
    'linkedin.com', 'instagram.com', 'facebook.com', 'x.com', 'twitter.com'
  ];
  /**
   * @var string[] characters that indicate a template placeholder instead of a real url
   */
  const PLACEHOLDER_CHARS = ['{', '}', '[', ']', '<', '>', '$'];

  /**
   * Origin (scheme://host[:port]) of the site, used for root relative links
   * @var string
   */
  protected $origin = '';
  /**
   * Hosts to skip
   * @var string[]
   */
  protected $ignoredHosts = [];

  /**
   * Prepare site specific values
   */
  public function __construct()
  {
    $home = parse_url(home_url());
    $this->origin = $home['scheme'] . '://' . $home['host'] . (isset($home['port']) ? ':' . $home['port'] : '');
    $this->ignoredHosts = apply_filters('lbwp_dlc_ignored_hosts', self::IGNORED_HOSTS);
  }

  /**
   * Extract all checkable urls of a text (html, gutenberg block json or a plain url)
   *
   * @param string $text the content to parse
   * @return string[] unique normalized urls
   */
  public function extract($text)
  {
    $urls = [];
    foreach ($this->findCandidates((string) $text) as $candidate) {
      $url = $this->normalize($candidate);
      if ($url !== null) {
        $urls[$url] = true;
      }
    }

    return array_keys($urls);
  }

  /**
   * Find raw url candidates in a text
   *
   * @param string $text the content to parse
   * @return string[] raw candidates, not yet normalized
   */
  protected function findCandidates($text)
  {
    $text = trim($text);
    if (filter_var($text, FILTER_VALIDATE_URL) !== false) {
      return [$text];
    }

    $candidates = [];
    if (preg_match_all('/\bhref\s*=\s*(["\'])(.*?)\1/is', $text, $matches)) {
      $candidates = $matches[2];
    }

    // Urls in json like gutenberg block attributes, unescaped by json_decode
    if (preg_match_all('/:\s*"(https?:(?:[^"\\\\]|\\\\.)*)"/i', $text, $matches)) {
      foreach ($matches[1] as $escaped) {
        $decoded = json_decode('"' . $escaped . '"');
        $candidates[] = is_string($decoded) ? $decoded : stripslashes($escaped);
      }
    }

    return $candidates;
  }

  /**
   * Turn a raw candidate into a checkable absolute url
   *
   * @param string $raw the raw href value
   * @return string|null the normalized url or null if it should not be checked
   */
  protected function normalize($raw)
  {
    $url = trim(html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    $url = str_replace(' ', '%20', explode('#', $url, 2)[0]);

    if (strlen($url) === 0 || $this->isPlaceholder($url)) {
      return null;
    }

    if (Strings::startsWith($url, '//')) {
      $url = parse_url($this->origin, PHP_URL_SCHEME) . ':' . $url;
    } else if (Strings::startsWith($url, '/')) {
      $url = $this->origin . $url;
    }

    // Anything not absolute http(s) by now (mailto:, tel:, javascript:, relative paths) is skipped
    if (!Strings::startsWithOne(strtolower($url), ['http://', 'https://'])) {
      return null;
    }

    $parts = parse_url($url);
    if (!isset($parts['host']) || $this->isExcluded($parts)) {
      return null;
    }

    return $url;
  }

  /**
   * Check if an url looks like a template placeholder
   *
   * @param string $url the url
   * @return bool true if it contains placeholder characters
   */
  protected function isPlaceholder($url)
  {
    foreach (self::PLACEHOLDER_CHARS as $char) {
      if (strpos($url, $char) !== false) {
        return true;
      }
    }

    return false;
  }

  /**
   * Check if parsed url parts match an exclusion rule
   *
   * @param array $parts result of parse_url
   * @return bool true if the url must not be checked
   */
  protected function isExcluded(array $parts)
  {
    $host = strtolower($parts['host']);
    $path = $parts['path'] ?? '';
    $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

    if (in_array($extension, self::EXCLUDED_EXTENSIONS)) {
      return true;
    }

    if ($host === strtolower(parse_url($this->origin, PHP_URL_HOST)) && Strings::startsWithOne($path, self::EXCLUDED_PATHS)) {
      return true;
    }

    return $this->isIgnoredHost($host);
  }

  /**
   * Check if a host or one of its parent domains is ignored
   *
   * @param string $host lowercase host
   * @return bool true if ignored
   */
  protected function isIgnoredHost($host)
  {
    foreach ($this->ignoredHosts as $ignored) {
      if ($host === $ignored || Strings::endsWith($host, '.' . $ignored)) {
        return true;
      }
    }

    return false;
  }
}
