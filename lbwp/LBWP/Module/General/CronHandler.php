<?php

namespace LBWP\Module\General;

use LBWP\Helper\MasterApi;
use LBWP\Util\Date;

/**
 * Handles all the daily and hourly cron tasks
 * @author Michael Sebel <michael@comotive.ch>
 */
class CronHandler extends \LBWP\Module\Base
{
	/**
	 * Call parent constructor only if a cron running constant is set
	 */
	public function __construct()
  {
    parent::__construct();
	}

  /**
   * Registers all the daily and hourly jobs that the jobserver should do.
   * Only executed if DOING_LBWP_CRON is set, hence only in actual crons.
   */
  public function initialize()
  {
    if (defined('DOING_LBWP_CRON')) {
      // deleting auto-saves and revisions older than 60 days
      if ($this->features['Crons']['CleanRevisions'] == 1) {
        add_action('cron_daily',array($this,'cleanRevisions'));
      }

      // deleting comment spam older than 30 days (antispam-bee doesn't work...)
      if ($this->features['Crons']['CleanCommentSpam'] == 1) {
        add_action('cron_daily',array($this,'cleanCommentSpam'));
      }

      // Calculate daily/hourly total of requests per type/server
      add_action('cron_hourly', array($this, 'aggregatePersistentRequestsHourly'));
      add_action('cron_daily', array($this, 'aggregatePersistentRequestsDaily'));
    }
  }

  /**
   * This one aggregates the total of requests per server/type into the db
   */
  public function aggregatePersistentRequestsHourly()
  {
    global $lbwpNodes;

    $data = get_option('lbwpPersistentCountToday');

    foreach ($lbwpNodes[INFRASTRUCTURE_KEY] as $nodeId => $node) {
      // Go trough all types of requests
      foreach (array('cached', 'uncached') as $type) {
        $current = intval($data[$nodeId][$type]);
        $new = getPersistentCount($type, $nodeId);
        // Save data and reset the counter
        $data[$nodeId][$type] = $current + $new;
        resetPersistentCount($type, $nodeId);
      }
    }

    // Save the option back to DB
    update_option('lbwpPersistentCountToday', $data);
  }

  /**
   * This takes the daily requests and pastes it into a date record for this month
   */
  public function aggregatePersistentRequestsDaily()
  {
    $dayId = date('j', current_time('timestamp') + 3600);
    $dailyData = get_option('lbwpPersistentCountToday');
    $monthlyData = get_option('lbwpPersistentCountMonth');

    if (!is_array($monthlyData)) {
      $monthlyData = array();
    }
    if (!is_array($dailyData)) {
      $dailyData = array(
        'uncached' => 0,
        'cached' => 0
      );
    }

    // On the first of the month, add all requests to the grand total of this page and reset
    if ($dayId == 1) {
      $this->addPersistentRequestsTotal($monthlyData);
      $monthlyData = array();
    }

    // Add to the monthly total and reset the daily data
    $monthlyData[$dayId] = $dailyData;
    update_option('lbwpPersistentCountMonth', $monthlyData);
    update_option('lbwpPersistentCountToday', array());

    // Also, send the data to the master
    $this->sendToMaster($dailyData);
  }

  /**
   * This adds $data into the grand total of the website
   * @param array $data the data to add to the total
   */
  protected function addPersistentRequestsTotal($data)
  {
    $totalData = get_option('lbwpPersistentCountTotal');

    // First initializing
    if (!is_array($totalData)) {
      $totalData = array(
        'cached' => 0,
        'uncached' => 0
      );
    }

    // Add all servers requests to a grand total
    foreach ($data as $dailyData) {
      foreach ($dailyData as $nodeId => $nodeData) {
        $totalData['cached'] += intval($nodeData['cached']);
        $totalData['uncached'] += intval($nodeData['uncached']);
      }
    }

    update_option('lbwpPersistentCountTotal', $totalData);
  }

  /**
   * This sends the daily data to the Master API
   * @param array $data the persistent tracker data
   */
  protected function sendToMaster($data)
  {
    MasterApi::post(MasterApi::REQUEST_PUSH, array(
      'dailyData' => $data,
      'host' => getLbwpHost()
    ));
  }

  /**
   * This executes all globally registered crons
   * @param string $type hourly or daily
   */
  public function executeCron($type)
  {
    do_action('cron_' . $type);
  }

  /**
   * Cleans auto-saves and revision older than 60 days from the database
   */
  public function cleanRevisions()
  {
    // get all revisions where post_modified > 60 days
    $treshold = Date::getTime(Date::SQL_DATETIME,time() - (60 * 86400));
    $posts = $this->wpdb->get_results($this->wpdb->prepare('
      SELECT ID FROM '.$this->wpdb->posts.' WHERE
      post_type = "revision" AND post_modified < %s LIMIT 0,500
    ',$treshold));
    // Safely delete the found posts
    foreach ($posts as $post) {
      wp_delete_post($post->ID,true);
    }
  }

  /**
   * Cleans comment spam older than 14 days from the database
   */
  public function cleanCommentSpam()
  {
    // get all spam comments, created more than 14 days ago
    $treshold = Date::getTime(Date::SQL_DATETIME,time() - (14 * 86400));
    $comments = $this->wpdb->get_results($this->wpdb->prepare('
      SELECT comment_ID FROM '.$this->wpdb->comments.' WHERE
      comment_approved = "spam" AND comment_date < %s LIMIT 0,1000
    ',$treshold));
    foreach ($comments as $comment) {
      wp_delete_comment($comment->comment_ID,true);
    }
  }
}