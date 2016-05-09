<?php

namespace LBWP\Module\General\Cms;

use LBWP\Module\Frontend\SimpleFancybox;

/**
 * Static functions to display customer backend dashboard items
 * @package LBWP\Module\General
 * @autor Michael Sebel <michael@comotive.ch>
 */
class AdminDashboard
{
  /**
   * @var string the lbwp news feed
   */
  const LBWP_NEWS_FEED = 'http://www.comotive.ch/kategorie/lbwp-news/feed/';
  /**
   * @var int max number of lbwp news items
   */
  const NEWS_ITEMS = 3;
  /**
   * @var int the time a new item appears with a label
   */
  const NEW_ITEM_DAYS = 10;

  /**
   * @return string html to display usage stats
   */
  public static function getUsageStatistics()
  {
    // Try getting it from cache
    $html = wp_cache_get('getUsageStatistics', 'AdminDashboard');
    if (strlen($html) > 0) {
      echo $html;
      return;
    }

    $data = get_option('lbwpPersistentCountMonth');

    // Prepare the result set
    $monthlyTotal = array(
      'uncached' => 0,
      'cached' => 0,
    );

    if (is_array($data)) {
      foreach ($data as $day => $dailyData) {
        // Skip, if there is no data
        if (!is_array($dailyData)) {
          continue;
        }

        // Get server data and add up totals
        foreach ($dailyData as $nodeId => $serverData) {
          $monthlyTotal['uncached'] += intval($serverData['uncached']);
          $monthlyTotal['cached'] += intval($serverData['cached']);
        }
      }
    }

    $html .= '
      <p><strong>Ressourcen Nutzung im ' . date_i18n('F') . ':</strong></p>
      <ul>
        <li>Direkte Server Anfragen: <strong>' . number_format($monthlyTotal['uncached']) . '</strong></li>
        <li>Zwischengespeicherte Seiten (Cache): <strong>' . number_format($monthlyTotal['cached']) . '</strong></li>
      </ul>
    ';

    // Totals (that are not calculated yet)
    $totalData = get_option('lbwpPersistentCountTotal');

    // First initializing, if the array is empty
    if (!is_array($totalData)) {
      $totalData = $monthlyTotal;
    } else {
      // Add monthly data to total
      $totalData['uncached'] += $monthlyTotal['uncached'];
      $totalData['cached'] += $monthlyTotal['cached'];
    }

    $html .= '
      <p><strong>Ressourcen Nutzung insgesamt:</strong></p>
      <ul>
        <li>Direkte Server Anfragen: <strong>' . number_format($totalData['uncached']) . '</strong></li>
        <li>Zwischengespeicherte Seiten (Cache): <strong>' . number_format($totalData['cached']) . '</strong></li>
      </ul>
    ';

    // Cache this
    wp_cache_set('getUsageStatistics', $html, 'AdminDashboard', 43600);

    echo $html;
  }

  /**
   * Display the news feed for lbwp stuff
   */
  public static function getNewsFeed()
  {
    // Get RSS classes and add thickbox for images
    include_once(ABSPATH . WPINC . '/feed.php');
    // Add fancybox
    $fancybox = new SimpleFancybox();
    $fancybox->initialize(true);

    $html = '';
    $rss = fetch_feed(self::LBWP_NEWS_FEED);

    // Display the feed if it was available
    if ($rss instanceof \SimplePie) {
      /** @var \SimplePie_Item $item Display at most X items */
      foreach ($rss->get_items(0, self::NEWS_ITEMS) as $item) {
        $html .= '
          <li>
            <h4>' . $item->get_title() . self::getNewItemFlag($item) . '</h4>

            <p>
              ' . $item->get_date('d.m.Y') . ' - ' . strip_tags($item->get_content(true), '<a><strong><em>') . '
              <!--&raquo; <a href="' . $item->get_permalink() . '">Weitere Informationen</a>-->
            </p>
          </li>
        ';
      }
    }

    // Output html
    echo '<ul>' . $html . '</ul>';
  }

  /**
   * @param \SimplePie_Item $item the rss item
   * @return string new item label
   */
  public static function getNewItemFlag($item)
  {
    $treshold = current_time('timestamp') - (self::NEW_ITEM_DAYS * 86400);
    if ($item->get_date('U') > $treshold) {
      return '<div class="new-item">Neu</div>';
    }

    return '';
  }
} 