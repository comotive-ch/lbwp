<?php

namespace LBWP\Module\General\Cms\DeadLinkChecker;

use LBWP\Module\General\Cms\SystemLog;

/**
 * Runs the daily check: rebuilds the index if due, then checks the urls that are due
 * within a time budget. Classifies results and schedules the next check per url.
 * @package LBWP\Module\General\Cms\DeadLinkChecker
 * @author Mirko Baffa <mirko@comotive.ch>
 */
class CheckRunner
{
  /**
   * @var int max urls checked per run (a site runs several times per night)
   */
  const MAX_URLS_PER_RUN = 200;
  /**
   * @var int urls handed to the http checker at once
   */
  const CHUNK_SIZE = 40;
  /**
   * @var int seconds a run may spend checking before it stops
   */
  const TIME_BUDGET = 120;
  /**
   * @var int consecutive problem results needed before a problem status is shown
   */
  const CONFIRM_FAILURES = 2;
  /**
   * @var int[] codes that mean the target is really gone
   */
  const DEAD_CODES = [400, 404, 410];
  /**
   * @var array days until the next check per status
   */
  const RECHECK_DAYS = [
    LinkTable::STATUS_OK => 30,
    LinkTable::STATUS_REDIRECT => 30,
    LinkTable::STATUS_FAILED => 14,
    LinkTable::STATUS_BLOCKED => 7,
    LinkTable::STATUS_UNREACHABLE => 7,
  ];
  /**
   * @var int random days added or removed from ok rechecks to spread the load
   */
  const RECHECK_JITTER_DAYS = 5;

  /**
   * @var LinkTable
   */
  protected $table;
  /**
   * @var LinkIndexer
   */
  protected $indexer;

  /**
   * @param LinkTable $table the link table
   * @param LinkIndexer $indexer the indexer
   */
  public function __construct(LinkTable $table, LinkIndexer $indexer)
  {
    $this->table = $table;
    $this->indexer = $indexer;
  }

  /**
   * Daily cron entry: rebuild the index if due and check due urls
   *
   * @return void
   */
  public function run()
  {
    set_time_limit(self::TIME_BUDGET + 300);
    $start = microtime(true);
    $this->table->install();

    $rebuildSeconds = 0;
    if ($this->indexer->isRebuildDue()) {
      $this->indexer->rebuild();
      $rebuildSeconds = microtime(true) - $start;
    }

    $deadline = time() + self::TIME_BUDGET;
    $due = $this->table->getDue(self::MAX_URLS_PER_RUN);
    $checked = 0;
    foreach (array_chunk($due, self::CHUNK_SIZE) as $chunk) {
      if (time() >= $deadline) {
        break;
      }
      $this->checkRows($chunk);
      $checked += count($chunk);
    }

    $this->logRun(microtime(true) - $start, $rebuildSeconds, count($due), $checked);
  }

  /**
   * Write a system log entry with the duration and numbers of a run
   *
   * @param float $seconds total duration of the run
   * @param float $rebuildSeconds duration of the index rebuild, 0 if none was done
   * @param int $due number of urls that were due
   * @param int $checked number of urls actually checked
   * @return void
   */
  protected function logRun($seconds, $rebuildSeconds, $due, $checked)
  {
    SystemLog::add('DeadLinkChecker', 'info', 'Link check done in ' . round($seconds, 1) . 's', [
      'duration' => round($seconds, 1),
      'rebuild_duration' => round($rebuildSeconds, 1),
      'urls_due' => $due,
      'urls_checked' => $checked,
      'stopped_by_time_budget' => $checked < $due,
    ]);
  }

  /**
   * Check a single url immediately, e.g. from the admin page
   *
   * @param string $hash url hash
   * @return void
   */
  public function checkNow($hash)
  {
    $row = $this->table->getByHash($hash);
    if (is_array($row)) {
      $this->checkRows([$row]);
    }
  }

  /**
   * Check due rows: internal links locally, the rest via http
   *
   * @param array $rows rows from LinkTable::getDue
   * @return void
   */
  protected function checkRows(array $rows)
  {
    $resolver = new InternalLinkResolver();
    $byHash = array_column($rows, null, 'url_hash');
    $external = [];

    foreach ($rows as $row) {
      $code = $resolver->isInternal($row['url']) ? $resolver->resolve($row['url']) : null;
      if ($code === null) {
        $external[$row['url_hash']] = $row['url'];
        continue;
      }
      $this->store($row, ['code' => $code, 'errno' => 0, 'error' => '', 'final_url' => $row['url'], 'redirects' => 0]);
    }

    if (count($external) > 0) {
      $checker = new UrlChecker();
      foreach ($checker->checkAll($external) as $hash => $result) {
        $this->store($byHash[$hash], $result);
      }
    }
  }

  /**
   * Classify a result and store it with the next check date
   *
   * @param array $row the checked row (url_hash, url, fail_count, status)
   * @param array $result result of the check (code, errno, error, final_url, redirects)
   * @return void
   */
  protected function store(array $row, array $result)
  {
    $status = $this->classify($result);
    $failCount = 0;
    $nextCheck = $this->getNextCheck($status);

    // Problems only show after being confirmed on another day, transient errors vanish
    if (!in_array($status, [LinkTable::STATUS_OK, LinkTable::STATUS_REDIRECT])) {
      $failCount = intval($row['fail_count']) + 1;
      if ($failCount < self::CONFIRM_FAILURES) {
        $status = $row['status'];
        $nextCheck = time() + DAY_IN_SECONDS;
      }
    }

    $this->table->saveResult($row['url_hash'], [
      'status' => $status,
      'code' => $result['code'],
      'error' => $result['error'],
      'final_url' => $result['redirects'] > 0 ? $result['final_url'] : null,
      'fail_count' => $failCount,
      'next_check_at' => $nextCheck,
    ]);
  }

  /**
   * Map a check result to a status
   *
   * @param array $result result of the check
   * @return string one of the LinkTable::STATUS_* constants
   */
  protected function classify(array $result)
  {
    $code = intval($result['code']);

    if ($code === 0 || $code >= 500 || ($code >= 300 && $code < 400)) {
      return LinkTable::STATUS_UNREACHABLE;
    }
    if ($code < 300) {
      return $result['redirects'] > 0 ? LinkTable::STATUS_REDIRECT : LinkTable::STATUS_OK;
    }
    if (in_array($code, self::DEAD_CODES)) {
      return LinkTable::STATUS_FAILED;
    }

    return LinkTable::STATUS_BLOCKED;
  }

  /**
   * Calculate the next check timestamp for a status
   *
   * @param string $status the status
   * @return int timestamp
   */
  protected function getNextCheck($status)
  {
    $days = self::RECHECK_DAYS[$status] ?? 7;
    if ($status === LinkTable::STATUS_OK) {
      $days += mt_rand(-self::RECHECK_JITTER_DAYS, self::RECHECK_JITTER_DAYS);
    }

    return time() + $days * DAY_IN_SECONDS;
  }
}
