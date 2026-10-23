<?php

namespace LBWP\Module\General\Cms;

use LBWP\Helper\Cronjob;
use LBWP\Module\BaseSingleton;
use LBWP\Module\General\Cms\DeadLinkChecker\AdminPage;
use LBWP\Module\General\Cms\DeadLinkChecker\CheckRunner;
use LBWP\Module\General\Cms\DeadLinkChecker\DashboardWidget;
use LBWP\Module\General\Cms\DeadLinkChecker\LinkIndexer;
use LBWP\Module\General\Cms\DeadLinkChecker\LinkTable;

/**
 * Entry point of the dead link checker. Links are indexed on post save and checked in a
 * daily batch, the results are shown on a tools page and a dashboard widget.
 * @package LBWP\Module\General\Cms
 * @author Michael Sebel <michael@comotive.ch>
 */
class DeadLinkChecker extends BaseSingleton
{
  /**
   * @var int check runs per site and night
   */
  const int RUNS_PER_NIGHT = 2;
  /**
   * @var int minutes after cron_daily_2 within which the runs are randomly spread
   */
  const int RUN_WINDOW_MINUTES = 120;
  /**
   * @var string job identifier of a single check run
   */
  const string JOB_IDENTIFIER = 'lbwp_dead_link_check';

  /**
   * @var LinkTable
   */
  protected $table;
  /**
   * @var LinkIndexer
   */
  protected $indexer;

  /**
   * Enable the dead link checker for this site. Call it in the theme setup (after_setup_theme),
   * before init, so all hooks are registered in time.
   *
   * Options:
   * - post_types: string[] post types to scan, replaces the default (public types + wp_block)
   * - scan_meta: bool scan post meta for links too (default true)
   * - ignored_hosts: string[] hosts to skip in addition to the defaults (linkedin.com etc.)
   *
   * @param array $options see above
   * @return DeadLinkChecker the instance
   */
  public static function init($options = [])
  {
    $instance = self::getInstance();
    $instance->applyOptions($options);
    return $instance;
  }

  /**
   * Wire up all parts of the checker
   *
   * @return void
   */
  public function run()
  {
    $this->table = new LinkTable();
    $this->indexer = new LinkIndexer($this->table);

    add_action('init', [$this->table, 'install']);
    add_action('cron_daily_2', [$this, 'scheduleRuns']);
    add_action('cron_job_' . self::JOB_IDENTIFIER, [$this, 'runCheck']);
    $this->indexer->setup();

    if (is_admin()) {
      (new AdminPage($this->table, $this->indexer, $this->getRunner()))->setup();
      (new DashboardWidget($this->table))->setup();
    }
  }

  /**
   * Register the check runs of this night at random times, so not all sites on a server check at once
   *
   * @return void
   */
  public function scheduleRuns(): void
  {
    $now = current_time('timestamp');
    $jobs = [];
    while (count($jobs) < self::RUNS_PER_NIGHT) {
      $jobs[$now + mt_rand(60, self::RUN_WINDOW_MINUTES * 60)] = self::JOB_IDENTIFIER;
    }

    Cronjob::register($jobs);
  }

  /**
   * Job callback running one check
   *
   * @return void
   */
  public function runCheck(): void
  {
    // Confirm before the long run, job.php would only confirm after it and the job could be picked up twice
    Cronjob::confirm(intval($_GET['jobId'] ?? 0));
    $this->getRunner()->run();
  }

  /**
   * Apply init options by hooking the filters the checker parts read
   *
   * @param array $options options as documented in init()
   * @return void
   */
  protected function applyOptions(array $options)
  {
    if (isset($options['post_types']) && is_array($options['post_types'])) {
      add_filter('lbwp_dlc_post_types', function () use ($options) {
        return $options['post_types'];
      });
    }

    if (isset($options['scan_meta']) && !$options['scan_meta']) {
      add_filter('lbwp_dlc_scan_meta', '__return_false');
    }

    if (isset($options['ignored_hosts']) && is_array($options['ignored_hosts'])) {
      add_filter('lbwp_dlc_ignored_hosts', function ($hosts) use ($options) {
        return array_merge($hosts, $options['ignored_hosts']);
      });
    }
  }

  /**
   * Create the check runner
   *
   * @return CheckRunner the runner
   */
  protected function getRunner()
  {
    return new CheckRunner($this->table, $this->indexer);
  }
}
