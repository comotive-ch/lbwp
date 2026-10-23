<?php

namespace LBWP\Module\General\Cms\DeadLinkChecker;

use LBWP\Util\WordPress;

/**
 * Owns the dead link checker table. One row per link and source post, so the same url
 * found in several posts is listed per post but checked only once (grouped by url_hash).
 * @package LBWP\Module\General\Cms\DeadLinkChecker
 * @author Mirko Baffa <mirko@comotive.ch>
 */
class LinkTable
{
  /**
   * @var string link has been indexed but not checked yet
   */
  const STATUS_NEW = 'new';
  /**
   * @var string link answered with 2xx
   */
  const STATUS_OK = 'ok';
  /**
   * @var string link answered with 2xx after one or more redirects
   */
  const STATUS_REDIRECT = 'redirect';
  /**
   * @var string link is confirmed dead (404/410/400)
   */
  const STATUS_FAILED = 'failed';
  /**
   * @var string remote denies automated access (401/403/429/999...)
   */
  const STATUS_BLOCKED = 'blocked';
  /**
   * @var string remote could not be reached (timeout, dns, ssl, 5xx)
   */
  const STATUS_UNREACHABLE = 'unreachable';
  /**
   * @var string table name without prefix
   */
  const TABLE = 'lbwp_deadlinks';
  /**
   * @var string option key storing the installed table schema version
   */
  const SCHEMA_VERSION_OPTION = 'lbwp_deadlinks_schema_version';
  /**
   * @var int bump this to trigger a dbDelta() re-run
   */
  const SCHEMA_VERSION = 1;
  /**
   * @var string option of the legacy json index, removed on install
   */
  const LEGACY_OPTION = 'deadlink_checker_index';

  /**
   * Creates or upgrades the table, version-gated so dbDelta only runs after a bump
   *
   * @return void
   */
  public function install()
  {
    if (intval(get_option(self::SCHEMA_VERSION_OPTION, 0)) === self::SCHEMA_VERSION) {
      return;
    }

    $db = WordPress::getDb();
    $table = $this->getTableName();
    $charset = $db->get_charset_collate();

    $sql = "CREATE TABLE {$table} (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      url TEXT NOT NULL,
      url_hash CHAR(40) NOT NULL,
      host VARCHAR(191) NOT NULL DEFAULT '',
      source_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
      status VARCHAR(16) NOT NULL DEFAULT 'new',
      code SMALLINT UNSIGNED NOT NULL DEFAULT 0,
      error VARCHAR(191) NOT NULL DEFAULT '',
      final_url TEXT NULL,
      fail_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
      checked_at INT UNSIGNED NOT NULL DEFAULT 0,
      next_check_at INT UNSIGNED NOT NULL DEFAULT 0,
      ignored TINYINT(1) NOT NULL DEFAULT 0,
      PRIMARY KEY  (id),
      UNIQUE KEY url_source (url_hash,source_id),
      KEY source_id (source_id),
      KEY next_check_at (next_check_at),
      KEY status (status)
    ) {$charset};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);

    // The legacy index is not migrated, as its results are exactly the false positives we fix
    delete_option(self::LEGACY_OPTION);
    update_option(self::SCHEMA_VERSION_OPTION, self::SCHEMA_VERSION, true);
  }

  /**
   * Get the full table name including the wpdb prefix
   *
   * @return string table name
   */
  public function getTableName()
  {
    return WordPress::getDb()->prefix . self::TABLE;
  }

  /**
   * Hash used to identify an url across sources
   *
   * @param string $url the url
   * @return string sha1 hash
   */
  public static function hash($url)
  {
    return sha1($url);
  }

  /**
   * Replace the links of a source post with the given list. New rows inherit the check
   * state of the same url in other sources, so known urls are not checked again.
   *
   * @param int $sourceId the post id the links were found in
   * @param string[] $urls normalized urls found in the source
   * @return void
   */
  public function syncSource($sourceId, array $urls)
  {
    $db = WordPress::getDb();
    $table = $this->getTableName();
    $wanted = [];
    foreach ($urls as $url) {
      $wanted[self::hash($url)] = $url;
    }

    $existing = $db->get_col($db->prepare("SELECT url_hash FROM {$table} WHERE source_id = %d", $sourceId));
    $this->deleteHashesOfSource($sourceId, array_diff($existing, array_keys($wanted)));

    $missing = array_diff_key($wanted, array_flip($existing));
    if (count($missing) === 0) {
      return;
    }

    $known = $this->getStateByHashes(array_keys($missing));
    foreach ($missing as $hash => $url) {
      $this->insertRow($sourceId, $hash, $url, $known[$hash] ?? null);
    }
  }

  /**
   * Remove all links of a source post
   *
   * @param int $sourceId the post id
   * @return void
   */
  public function deleteSource($sourceId)
  {
    WordPress::getDb()->delete($this->getTableName(), ['source_id' => intval($sourceId)], ['%d']);
  }

  /**
   * Remove links whose source is no longer part of the indexed posts
   *
   * @param string[] $postTypes indexed post types
   * @return void
   */
  public function deleteOrphans(array $postTypes)
  {
    $db = WordPress::getDb();
    $table = $this->getTableName();
    $types = implode(',', array_map(function ($type) use ($db) {
      return $db->prepare('%s', $type);
    }, $postTypes));

    $db->query("
      DELETE links FROM {$table} AS links
      LEFT JOIN {$db->posts} AS posts ON posts.ID = links.source_id
        AND posts.post_status = 'publish' AND posts.post_type IN ({$types})
      WHERE posts.ID IS NULL
    ");
  }

  /**
   * Get distinct urls that are due for a check, oldest first
   *
   * @param int $limit max number of urls
   * @return array list of [url_hash, url, host, fail_count, status]
   */
  public function getDue($limit)
  {
    $db = WordPress::getDb();
    $table = $this->getTableName();

    return $db->get_results($db->prepare("
      SELECT url_hash, MIN(url) AS url, MIN(host) AS host, MAX(fail_count) AS fail_count, MIN(status) AS status
      FROM {$table}
      WHERE ignored = 0 AND next_check_at <= %d
      GROUP BY url_hash
      ORDER BY MIN(next_check_at) ASC
      LIMIT %d
    ", time(), $limit), ARRAY_A);
  }

  /**
   * Get the stored check state of a single url
   *
   * @param string $hash url hash
   * @return array|null row with url_hash, url, host, fail_count, status or null if unknown
   */
  public function getByHash($hash)
  {
    $db = WordPress::getDb();
    $table = $this->getTableName();

    return $db->get_row($db->prepare("
      SELECT url_hash, url, host, fail_count, status FROM {$table} WHERE url_hash = %s LIMIT 1
    ", $hash), ARRAY_A);
  }

  /**
   * Store a check result for all rows of an url
   *
   * @param string $hash url hash
   * @param array $result keys status, code, error, final_url, fail_count, next_check_at
   * @return void
   */
  public function saveResult($hash, array $result)
  {
    WordPress::getDb()->update($this->getTableName(), [
      'status' => $result['status'],
      'code' => intval($result['code']),
      'error' => substr($result['error'], 0, 191),
      'final_url' => $result['final_url'],
      'fail_count' => min(255, intval($result['fail_count'])),
      'checked_at' => time(),
      'next_check_at' => intval($result['next_check_at']),
    ], ['url_hash' => $hash], ['%s', '%d', '%s', '%s', '%d', '%d', '%d'], ['%s']);
  }

  /**
   * Ignore an url permanently, for all sources
   *
   * @param string $hash url hash
   * @return void
   */
  public function ignore($hash)
  {
    WordPress::getDb()->update($this->getTableName(), ['ignored' => 1], ['url_hash' => $hash], ['%d'], ['%s']);
  }

  /**
   * Count distinct urls per status, ignored urls excluded
   *
   * @return array status => count, plus 'total' and 'checked'
   */
  public function getSummary()
  {
    $db = WordPress::getDb();
    $table = $this->getTableName();
    $summary = array_fill_keys([
      self::STATUS_NEW, self::STATUS_OK, self::STATUS_REDIRECT,
      self::STATUS_FAILED, self::STATUS_BLOCKED, self::STATUS_UNREACHABLE
    ], 0);

    $rows = $db->get_results("SELECT status, COUNT(DISTINCT url_hash) AS num FROM {$table} WHERE ignored = 0 GROUP BY status", ARRAY_A);
    foreach ($rows as $row) {
      $summary[$row['status']] = intval($row['num']);
    }

    $summary['total'] = array_sum($summary);
    $summary['checked'] = $summary['total'] - $summary[self::STATUS_NEW];
    return $summary;
  }

  /**
   * Get rows (one per source) with one of the given statuses, most recently checked first
   *
   * @param string[] $statuses statuses to list
   * @param int $limit max rows
   * @param int $offset rows to skip
   * @return array list of rows
   */
  public function getByStatus(array $statuses, $limit, $offset = 0)
  {
    $db = WordPress::getDb();
    $table = $this->getTableName();
    $in = implode(',', array_fill(0, count($statuses), '%s'));
    $args = array_merge($statuses, [$limit, $offset]);

    return $db->get_results($db->prepare("
      SELECT * FROM {$table}
      WHERE ignored = 0 AND status IN ({$in})
      ORDER BY checked_at DESC, id ASC
      LIMIT %d OFFSET %d
    ", $args), ARRAY_A);
  }

  /**
   * Count rows (one per source) with one of the given statuses
   *
   * @param string[] $statuses statuses to count
   * @return int number of rows
   */
  public function countByStatus(array $statuses)
  {
    $db = WordPress::getDb();
    $table = $this->getTableName();
    $in = implode(',', array_fill(0, count($statuses), '%s'));

    return intval($db->get_var($db->prepare("SELECT COUNT(*) FROM {$table} WHERE ignored = 0 AND status IN ({$in})", $statuses)));
  }

  /**
   * Delete the given url hashes of a source
   *
   * @param int $sourceId the post id
   * @param string[] $hashes url hashes to delete
   * @return void
   */
  protected function deleteHashesOfSource($sourceId, array $hashes)
  {
    if (count($hashes) === 0) {
      return;
    }

    $db = WordPress::getDb();
    $table = $this->getTableName();
    $in = implode(',', array_fill(0, count($hashes), '%s'));
    $db->query($db->prepare(
      "DELETE FROM {$table} WHERE source_id = %d AND url_hash IN ({$in})",
      array_merge([$sourceId], array_values($hashes))
    ));
  }

  /**
   * Get the check state of urls already known from other sources
   *
   * @param string[] $hashes url hashes
   * @return array url_hash => row
   */
  protected function getStateByHashes(array $hashes)
  {
    $db = WordPress::getDb();
    $table = $this->getTableName();
    $in = implode(',', array_fill(0, count($hashes), '%s'));
    $rows = $db->get_results($db->prepare("
      SELECT url_hash, status, code, error, final_url, fail_count, checked_at, next_check_at, ignored
      FROM {$table} WHERE url_hash IN ({$in})
    ", $hashes), ARRAY_A);

    $state = [];
    foreach ($rows as $row) {
      $state[$row['url_hash']] = $state[$row['url_hash']] ?? $row;
    }

    return $state;
  }

  /**
   * Insert a single link row
   *
   * @param int $sourceId the post id
   * @param string $hash url hash
   * @param string $url the url
   * @param array|null $state check state to inherit, null for a new url
   * @return void
   */
  protected function insertRow($sourceId, $hash, $url, $state)
  {
    $row = [
      'url' => $url,
      'url_hash' => $hash,
      'host' => substr(strtolower((string) parse_url($url, PHP_URL_HOST)), 0, 191),
      'source_id' => intval($sourceId),
      'status' => self::STATUS_NEW,
      'code' => 0,
      'error' => '',
      'final_url' => null,
      'fail_count' => 0,
      'checked_at' => 0,
      'next_check_at' => 0,
      'ignored' => 0,
    ];

    if (is_array($state)) {
      $row = array_merge($row, $state);
    }

    WordPress::getDb()->insert($this->getTableName(), $row, [
      '%s', '%s', '%s', '%d', '%s', '%d', '%s', '%s', '%d', '%d', '%d', '%d'
    ]);
  }
}
