<?php

namespace LBWP\Module\General;

use LBWP\Aboon\Base\Shop;
use LBWP\Helper\Cronjob;
use LBWP\Helper\MasterApi;
use LBWP\Module\General\Cms\SystemLog;
use LBWP\Theme\Feature\LbwpFormSettings;
use LBWP\Util\Date;
use LBWP\Util\External;
use LBWP\Util\Multilang;
use LBWP\Util\Strings;
use LBWP\Util\WordPress;

/**
 * Handles all the daily and hourly cron tasks
 * @author Michael Sebel <michael@comotive.ch>
 */
class CronHandler extends \LBWP\Module\Base
{
  /**
   * @var int maximum number of wp crons allowed
   */
  const MAX_WP_CRONS = 100;

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
        add_action('cron_daily_4', array($this, 'cleanRevisions'));
      }

      // deleting comment spam older than 30 days (antispam-bee doesn't work...)
      if ($this->features['Crons']['CleanCommentSpam'] == 1) {
        add_action('cron_daily_10', array($this, 'cleanCommentSpam'));
      }

      // anyonmizing comments after specified number of days
      if (intval($this->config['Privacy:CommentAnonymizeDays']) > 0) {
        add_action('cron_weekday_5', array($this, 'anonymizeComments'));
      }

      // Run actual wp crons, if requested
      add_action('cron_job_run_wp_cron', array($this, 'runWpCron'));
      // Calculate daily/hourly total of requests per type/server
      add_action('cron_daily_5', array($this, 'cleanLbwpDataTables'));
      add_action('cron_daily_21', array($this, 'checkOwnSslCertificate'));
      add_action('cron_daily_8', array($this, 'searchForHacks'));
      add_action('cron_daily_18', array($this, 'truncateYoastLinkIndexTable'));
      add_action('cron_daily_10', array($this, 'checkWpCronCount'));
      add_action('cron_daily_0', array($this, 'registerWpCronSchedules'));
      add_action('cron_monthly_4', array($this, 'resetUsersPassword'));
      // Only run on first jan by midnight when needed
      //add_action('cron_monthly_1_1', array($this, 'forceNewTaxRates'));
      // Register crons that are run later in theme context
      add_action('after_setup_theme', array($this, 'addLaterCrons'), 1000);

      add_action('cron_daily_4', array($this, 'setSearchContent'));
    }

    // Add some crons here as they must be able to be called manually
    add_action('cron_job_recalculate_subscription_totals', array($this, 'recalculateSubscriptionTotals'));
    add_action('cron_job_remove_empty_attachment_images', array($this, 'removeEmptyAttachmentImages'));
  }

  /**
   * @return void
   */
  public function recalculateSubscriptionTotals()
  {
    set_time_limit(1800);
    ini_set('memory_limit', '2048M');
    $full = intval($_GET['full']) == 1;
    $limit = intval($_GET['limit']);
    $changePrice = intval($_GET['changeprice']) == 1;
    $noVat = intval($_GET['novat']) == 1;
    $limit = ($limit > 0) ? $limit : 1;
    $checked_subscriptions = get_option('wcs_subscriptions_with_totals_updated_9_0', array());
    $subscriptions_to_check = wcs_get_subscriptions(array(
      'orderby' => 'ID',
      'order' => 'DESC',
      'post_type' => 'shop_subscription',
      'subscriptions_per_page' => $limit,
      'post__not_in' => $checked_subscriptions,
      'subscription_status' => array(
        'active', 'on-hold'
      ),
    ));

    $logInfo = '';

    foreach ($subscriptions_to_check as $subscription) {

      $subscription_id = $subscription->get_id();
      $logInfo .= '------ Checking subscription with ID = ' . var_export($subscription_id, true) . PHP_EOL;

      if ($subscription) {
        if ($full) {
          foreach ($subscription->get_items('line_item') as $item) {
            $lineTotal = round($item->get_subtotal() + $item->get_subtotal_tax(), 2);
            // Use new product price as base, if needed
            if ($changePrice) {
              /** @var \WC_Product $product */
              $product = $item->get_product();
              $lineTotal = floatval($product->get_price());
            }
            if ($noVat) {
              $qty = $item->get_quantity();
              $item->set_subtotal($lineTotal);
              $item->set_total($lineTotal * $qty);
            } else {
              $vatFactor = 1.081;
              if ($item->get_tax_class() == 'ermaessigter-steuersatz') {
                $vatFactor = 1.026;
              }
              $lineBeforeTax = round($lineTotal / $vatFactor, 5);
              $taxPart = $lineTotal - $lineBeforeTax;
              $qty = $item->get_quantity();
              $item->set_subtotal($lineBeforeTax);
              $item->set_subtotal_tax($taxPart);
              $item->set_total($lineBeforeTax * $qty);
              $item->set_total_tax($taxPart * $qty);
            }
            $item->save();
          }
          // Update totals and add an order note
          $subscription->update_taxes();
          $subscription->calculate_totals();
        } else {
          // Can be used for "excl mwst" orders, just recalc the tax, no new items
          $logInfo .= "recalculated exkl mwst totals of " . $subscription_id . PHP_EOL;
          $subscription->calculate_totals();
        }
        // Save the changes
        $subscription->save();
      }

      $checked_subscriptions[] = $subscription_id;

      // Update the record on each iteration in case we can't make it through 50 subscriptions in one request
      update_option('wcs_subscriptions_with_totals_updated_9_0', $checked_subscriptions, false);
    }

    echo $logInfo;
  }

  /**
   * @return void
   */
  public function forceNewTaxRates()
  {
    $mapping = array(
      '7.7000' => '8.1000',
      '2.5000' => '2.6000',
      '3.7000' => '3.8000'
    );
    $text = array(
      '7.7' => '8.1',
      '7,7' => '8,1',
      '2.5' => '2.6',
      '2,5' => '2,6',
      '3.7' => '3.8',
      '3,7' => '3,8',
    );
    $taxRates = Shop::getStandardTaxes();
    // If not a shop or no rates are defined, leave early
    if (count($taxRates) == 0 || $taxRates === false) {
      return;
    }

    // Change the tax rates according to mapping
    foreach ($taxRates as $id => $rate) {
      foreach ($mapping as $old => $new) {
        if ($rate['tax_rate'] == $old) {
          $taxRates[$id]['tax_rate'] = $new;
        }
      }
      foreach ($text as $old => $new) {
        if (str_contains($rate['tax_rate_name'], $old)) {
          $taxRates[$id]['tax_rate_name'] = str_replace($old, $new, $rate['tax_rate_name']);
        }
      }
    }

    Shop::setStandardTaxes($taxRates);
  }

  /**
   * Truncate links daily, as they shouldn't even be made
   * according yoast feature is disabled but still makes links sometimes
   * @return void
   */
  public function truncateYoastLinkIndexTable()
  {
    $db = WordPress::getDb();
    $db->query('TRUNCATE ' . $db->prefix . 'yoast_seo_links');
  }

  /**
   * Checks of SSL certificate and does a fatal log (thus sending email), if invalid or close to invalid
   */
  public function checkOwnSslCertificate()
  {
    if (defined('LOCAL_DEVELOPMENT')) {
      return;
    }

    $url = 'https://' . LBWP_HOST;
    $orignal_parse = parse_url($url, PHP_URL_HOST);
    $get = stream_context_create(array("ssl" => array("capture_peer_cert" => TRUE)));
    $read = stream_socket_client("ssl://" . $orignal_parse . ":443", $errno, $errstr, 30, STREAM_CLIENT_CONNECT, $get);
    $cert = stream_context_get_params($read);
    $certinfo = openssl_x509_parse($cert['options']['ssl']['peer_certificate']);

    // If cert expires in the next 5 days, send info
    if (($certinfo['validTo_time_t'] - (5 * 86400)) < current_time('timestamp')) {
      SystemLog::add('CronHandler', 'critical', LBWP_HOST . ': certificate close to expire date', array(
        'expireTs' => $certinfo['validTo_time_t'],
        'expireDate' => date('d.m.Y', $certinfo['validTo_time_t'])
      ));
    }
  }

  /**
   * @return void
   */
  public function removeEmptyAttachmentImages()
  {
    if (!current_user_can('administrator')) {
      return;
    }

    $db = WordPress::getDb();
    // First get all potential attachments that are relevant
    $candidateIds = $db->get_col('
      SELECT post_id, meta_value FROM ' . $db->postmeta . '
      WHERE meta_key = "_wp_attached_file"
      AND (meta_value LIKE "%.jpg" OR meta_value LIKE "%.png")
    ');

    // Also get all postmeta of attachment metadata, which are most likely valid
    $attachmentMetaIds = $db->get_col('
      SELECT post_id FROM ' . $db->postmeta . '
      WHERE meta_key = "_wp_attachment_metadata"
    ');

    // Remove all candidates that already have metadata, leaving the wrong ones
    $diff = array_diff($candidateIds, $attachmentMetaIds);
    // Remove all those attachments cleanly
    foreach ($diff as $attachmentId) {
      wp_delete_attachment($attachmentId, true);
    }
  }

  /**
   * Later crons that have full theme context
   */
  public function addLaterCrons()
  {
    if (LbwpFormSettings::get('privacyAutoDeleteDataTable')) {
      add_action('cron_daily_6', array($this, 'autoDeleteOverdueDataTableEntries'));
    }
  }

  /**
   * Checks if there are potentially to many wp crons registered
   */
  public function checkWpCronCount()
  {
    $crons = get_option('cron');
    if (is_array($crons) && count($crons) > self::MAX_WP_CRONS) {
      mail(
        MONITORING_EMAIL,
        'More than ' . self::MAX_WP_CRONS . ' wp_crons registered',
        'Host: ' . LBWP_HOST . ', Count: ' . count($crons),
        'From: ' . SERVER_EMAIL
      );
    }
  }

  /**
   * Registers wp crons scheduled over the whole day, run by our jobsystem
   */
  public function registerWpCronSchedules()
  {
    $start = current_time('timestamp') + mt_rand(0, 3600);

    $jobs = array(
      $start + mt_rand(10001, 20000) => 'run_wp_cron',
      $start + mt_rand(20001, 30000) => 'run_wp_cron',
      $start + mt_rand(30001, 40000) => 'run_wp_cron',
      $start + mt_rand(50001, 70000) => 'run_wp_cron',
      $start + mt_rand(60001, 80000) => 'run_wp_cron'
    );

    Cronjob::register($jobs);
  }

  /**
   * Runs the actual wp cron by including it
   */
  public function runWpCron()
  {
    set_time_limit(300);
    $_GET['doing_wp_cron'] = '';
    require_once ABSPATH . '/wp-cron.php';
  }

  /**
   * Search for hacks within the modifications of the last 24 hours in the database
   */
  public function searchForHacks()
  {
    $searches = array(
      '<iframe',
      '<script'
    );

    // Important: the blankspace are needed for finding that exact word
    $balcklist = array(
      ' app',
      '$',
      '€',
      ' chf ',
      ' sex',
      ' dating',
      ' jackpot ',
      ' bet ',
      'download',
      'hooking',
      'hookup',
      ' xxx ',
      'trading',
      'flirt',
      "online",
      "free",
      "internet",
      "credit",
      "tinder",
      "sites",
      "web",
      "relationship",
      "payday",
      "loans",
      "money",
      " best ",
      " pay ",
      "possess",
      "matchmaking",
      "financial",
      "lady",
      "website",
      "relationships",
      "cash",
      "financing",
      "software",
    );

    $whiteListSrcs = array(
      'youtube.com/embed',
      'cdnapisec.kaltura.com/p/',
      'platform.twitter.com/widgets.js',
      'player.vimeo.com/',
      'google.com/maps/embed',
      'interaktiv.tagesanzeiger.ch/homegate',
    );

    $postTypes = apply_filters('lbwp_hacksearch_post_types', array('"post"', '"page"'));

    // Get modified content via sql, which is much faster in this case
    $db = WordPress::getDb();
    $threshold = Date::getTime(Date::SQL_DATETIME, current_time('timestamp') - 186400);
    $sql = 'SELECT ID, post_content, post_modified, post_author FROM ' . $db->posts . ' WHERE post_modified > "' . $threshold . '" AND post_type IN (' . implode(',', $postTypes) . ')';
    $results = $db->get_results($sql);
    $isHack = false;
    $spamCheck = !defined('LBWP_SPAM_CHECK_DISABLED');

    // Gather all problematic cases
    $problematic = array();
    foreach ($results as $result) {
      foreach ($searches as $search) {
        $pos = stripos($result->post_content, $search);
        if ($pos !== false) {
          $result->snippet = '...' . htmlentities(substr($result->post_content, $pos, 100)) . '...';
          $result->search = htmlentities($search);
          unset($result->post_content);

          $srcStart = strpos($result->snippet, 'src=') + 4;
          $srcEnd = strpos($result->snippet, ' ', $srcStart) - $srcStart;
          $src = substr($result->snippet, $srcStart, $srcEnd);
          $allowedSrc = false;

          $src = str_replace(array('"', '\'', '&quot;', '&apos;'), '', $src);
          $src = preg_replace("/<!--.*?-->/", '', $src);

          foreach ($whiteListSrcs as $wSrc) {
            if (Strings::contains($src, $wSrc)) {
              $allowedSrc = true;
              break;
            }
          }

          if (!$allowedSrc) {
            $problematic[] = $result;
            $isHack = true;
            break;
          }
        }
      }

      // If not hack, then check also for spam
      if (!$isHack && $spamCheck) {
        $spamWords = 0;
        $spamScore = 0;
        $externaLinks = 0;
        $unknownLang = false;
        $text = strtolower(strip_tags($result->post_content));

        $textLang = Strings::guessLanguage($text);
        if ($textLang !== false) {
          if (!isset($result->snippet)) {
            $result->snippet = 'Sprache: ' . strtoupper($textLang) . ' | ';
          }

          if (Multilang::isActive()) {
            $languages = Multilang::getAllLanguages();

            if (!in_array($textLang, $languages)) {
              $unknownLang = true;
            }
          } else {
            $defaultLang = explode('_', get_locale())[0];

            if ($textLang !== $defaultLang) {
              $unknownLang = true;
            }
          }
        }

        // If unknown langugage no need to check the blacklist
        if (!$unknownLang) {
          $found = '';
          foreach ($balcklist as $word) {
            if (empty($text)) {
              continue;
            }

            if (strpos($text, $word) !== false || strpos(strtolower($result->post_title), $word) !== false) {
              if ($found === '') {
                $found = 'Enthält: ';
              }

              $found .= $word . ' ';
              $spamWords++;
            }
          }
          $result->snippet .= $found;

          if (Strings::containsOne(strtolower($result->post_content), array('www.', 'http', 'https')) !== false) {
            if (preg_match_all("/\b(http\:\/\/[^\s]+)\b/", strtolower($result->post_content), $links, PREG_PATTERN_ORDER)) {
              foreach ($links[1] as $link) {
                $externUrl = parse_url($link)['host'];
                $localUrl = parse_url(get_site_url())['host'];

                if ($localUrl !== $externUrl) {
                  $externaLinks++;
                }
              }
            }
          }

          if ($externaLinks > 0) {
            $result->snippet .= ' | Externe Links: ' . $externaLinks;
          }
        }

        // If unknown language then it's most certainly spam, otherwise do some mathematical magic to evaluate the spaminess
        $spamScore = $unknownLang ? 100 : pow($spamWords + $externaLinks, 2) * 2;

        // TBD: adjust this score for accurateness
        if ($spamScore >= 70) {
          $result->search = 'Spam Erkennung';
          unset($result->post_content);

          $problematic[] = $result;
        }
      }
    }

    // Create a mail if there are cases that might be problematic
    if (count($problematic) > 0) {
      $url = get_bloginfo('url');
      $content = '
        Möglicher Hack-Code' . ($spamCheck ? ' oder Spam' : '') . ' entdeckt:<br><br>
        <table>
          <tr>
            <td>ID / Link</td>
            <td>Bearbeitet am</td>
            <td>Bearbeitet von</td>
            <td>Ausschnitt</td>
            <td>Matcher</td>
          </tr>
      ';
      foreach ($problematic as $problem) {
        $content .= '
          <tr>
            <td><a href="' . $url . '/wp-admin/post.php?post=' . $problem->ID . '&action=edit">' . $problem->ID . '</a></td>
            <td>' . $problem->post_modified . '</td>
            <td>' . get_user_by('id', $problem->post_author)->display_name . '</td>
            <td>' . $problem->snippet . '</td>
            <td>' . $problem->search . '</td>
          </tr>
        ';
      }
      $content .= '</table>';

      // Create mail
      $mail = External::PhpMailer();
      $mail->addAddress(MONITORING_EMAIL);
      $mail->Subject = 'Mögliches problematisches HTML' . ($spamCheck ? ' oder Spam' : '') . ' bei ' . $url;
      $mail->Body = $content;
      $mail->send();
    }
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
   * Cleans auto-saves and revision older than 180 days from the database
   */
  public function cleanRevisions()
  {
    // get all revisions where post_modified > 180 days
    $treshold = Date::getTime(Date::SQL_DATETIME, time() - (180 * 86400));
    $posts = $this->wpdb->get_results($this->wpdb->prepare('
      SELECT ID FROM ' . $this->wpdb->posts . ' WHERE
      post_type = "revision" AND post_modified < %s LIMIT 0,500
    ', $treshold));
    // Safely delete the found posts
    foreach ($posts as $post) {
      wp_delete_post($post->ID, true);
    }
  }

  /**
   * Cleans comment spam older than 14 days from the database
   */
  public function cleanCommentSpam()
  {
    // get all spam comments, created more than 14 days ago
    $treshold = Date::getTime(Date::SQL_DATETIME, time() - (14 * 86400));
    $comments = $this->wpdb->get_results($this->wpdb->prepare('
      SELECT comment_ID FROM ' . $this->wpdb->comments . ' WHERE
      comment_approved = "spam" AND comment_date < %s LIMIT 0,1000
    ', $treshold));
    foreach ($comments as $comment) {
      wp_delete_comment($comment->comment_ID, true);
    }
  }

  /**
   * Clean out old records of certain types
   */
  public function cleanLbwpDataTables()
  {
    // For now its just newsletter stats
    $config = array('localmail_stats_' => 270);

    foreach ($config as $field => $days) {
      $threshold = Date::getTime(Date::SQL_DATETIME, current_time('timestamp') - (86400 * $days));
      $this->wpdb->query('
        DELETE FROM ' . $this->wpdb->prefix . 'lbwp_data WHERE
        row_key LIKE "' . $field . '%" AND
        row_modified < "' . $threshold . '"
      ');
    }
  }

  /**
   * Automatically deletes overdue data table entries if configred for data privacy
   */
  public function autoDeleteOverdueDataTableEntries()
  {
    $optionsIds = $this->wpdb->get_col('
      SELECT option_name FROM ' . $this->wpdb->options . ' WHERE
      option_name LIKE "LbwpForm_DataTable_%" AND
      option_value LIKE "%privacy-delete-after%"
    ');

    foreach ($optionsIds as $optionId) {
      // Get the table and determine the delete timestamp
      $table = WordPress::getJsonOption($optionId);
      $days = intval($table['privacy-delete-after']);
      // Skip if invalid number of days
      if ($days === 0) {
        continue;
      }
      // Dfine threshold
      $threshold = current_time('timestamp') - (86400 * $days);
      // Delete every record meeting the threshold
      foreach ($table['data'] as $key => $row) {
        if (strtotime($row['zeitstempel']) < $threshold) {
          unset($table['data'][$key]);
        }
      }
      // Save back to the option
      WordPress::updateJsonOption($optionId, $table);
    }
  }

  /**
   * Reset user password if it didn't login for six months
   * @return void
   */
  public function resetUsersPassword()
  {
    $minUnloggedTime = 60 * 60 * 24 * 30 * 6; // Approximately 6 months
    $skippable = array('comotive', 'wesign');

    $users = get_users(array(
      'role__in' => array('administrator', 'author', 'editor')
    ));

    foreach ($users as $user) {
      if (in_array($user->user_login, $skippable)) {
        return;
      }

      $lastLogin = intval(get_user_meta($user->data->ID, 'lbwp_last_login_date', true));
      if ($lastLogin === 0 || $lastLogin + $minUnloggedTime < current_time('timestamp')) {
        wp_set_password(Strings::getRandomPassword(32), $user->data->ID);
        update_user_meta($user->data->ID, 'lbwp_last_login_date', current_time('timestamp'));
        SystemLog::add('CronHandler', 'debug', 'Password reset for User ' . $user->data->ID, 'Last Login: ' . date('d.m.Y', $lastLogin));
      }
    }
  }

  public function anonymizeComments()
  {
    $minDate = date('Y-m-d', strtotime('-' . $this->config['Privacy:CommentAnonymizeDays'] . ' days'));
    $db = WordPress::getDb();
    $update = $db->query(
      "UPDATE " . $db->prefix . "comments 
      SET comment_author = '******', comment_author_email= '******' 
      WHERE comment_author != '******' AND comment_date < '" . $minDate . "' AND user_id = 0");

    if ($update !== false) {
      echo 'Updated ' . $update . ' rows';
    }
  }

  /**
   * Set search content for all posts (published in the last 24 hours)
   */
  public function setSearchContent(){
    $postType = apply_filters('lbwp_search_content_post_types', array('post', 'page', 'product', 'lbwp-event'));
    $allowedTags = apply_filters('lbwp_search_content_html_tags', '<h1><h2><h3><h4><h5><h6><p><ul><ol><li><img><a><figcaption>');

    if(isset($_GET['index_all_posts'])){
      $start = microtime(true);
      $this->setSearchContentForAllPosts($postType, $allowedTags);
      $end = microtime(true);
      SystemLog::mDebug('Post index search content', 'All posts indexed in ' . ($end - $start) . ' seconds');
      return;
    }

    $time = current_time('timestamp') - 86400;
    $posts = $this->wpdb->get_results('SELECT ID, post_title, post_content FROM ' . $this->wpdb->posts . ' WHERE post_type IN ("' . implode('","', $postType) . '") AND post_modified > "' . date('Y-m-d H:i:s', $time) . '"');

    foreach($posts as $post){
      $content = $post->post_title . ' ' . strip_tags(do_blocks($post->post_content), $allowedTags);
      update_post_meta($post->ID, 'lbwp_index_search_content', $content);
    }
  }

  /**
   * Index content for all posts at once
   */
  public function setSearchContentForAllPosts($postType, $allowedTags){
    if(!is_user_logged_in() && current_user_can('manage_options') !== true){
      return;
    }

    $postsPerBatch = 500;  // Adjust this number based on server configuration
    $offset = 0;

    while (true) {
      $posts = $this->wpdb->get_results($this->wpdb->prepare(
        'SELECT ID, post_title, post_content FROM ' . $this->wpdb->posts . ' WHERE post_type IN ("' . implode('","', $postType) . '") LIMIT %d OFFSET %d',
        $postsPerBatch,
        $offset
      ));

      if (empty($posts)) {
        break;  // No more posts to process
      }

      foreach($posts as $post){
        $content = $post->post_title . ' ' . strip_tags(do_blocks($post->post_content), $allowedTags);
        $postMetaTable = $this->wpdb->prefix . 'postmeta';
        $metaKey = 'lbwp_index_search_content';

        $this->wpdb->query($this->wpdb->prepare(
          "INSERT INTO $postMetaTable (post_id, meta_key, meta_value)
          VALUES (%d, %s, %s)
          ON DUPLICATE KEY UPDATE meta_value = %s",
          $post->ID,
          $metaKey,
          $content,
          $content
        ));
      }

      $offset += $postsPerBatch;
    }
  }
}