<?php

namespace LBWP\Module\General\Cms;

use LBWP\Helper\Import\Csv;
use LBWP\Module\BaseSingleton;
use LBWP\Util\ArrayManipulation;
use LBWP\Util\External;
use LBWP\Util\File;

/**
 * Implements usage based billing
 * @package LBWP\Module\General\Cms
 * @author Michael Sebel <michael@comotive.ch>
 */
class UsageBasedBilling extends BaseSingleton
{
  protected static $tariffs = array(
    'ai_audio' => array(
      'words' => 125,
      'per_chunk' => 0.25
    )
  );

  /**
   * Called at admin menu, allows us to add a submenu for admins
   */
  public function run()
  {
    add_action('admin_menu', array($this, 'addBillingInfoMenu'));
    // Quarterly billing
    add_action('cron_monthly_m3_d31', array($this, 'sendBillingInformation'));
    add_action('cron_monthly_m6_d30', array($this, 'sendBillingInformation'));
    add_action('cron_monthly_m9_d30', array($this, 'sendBillingInformation'));
    add_action('cron_monthly_m12_d31', array($this, 'sendBillingInformation'));
    add_action('cron_job_manual_run_usage_based_billing', array($this, 'sendBillingInformation'));
  }

  /**
   * @return void
   */
  public function sendBillingInformation()
  {
    $usageBasedBilling = ArrayManipulation::forceArray(get_option('lbwp_usage_based_billing'));
    // Skip if empty
    if (count($usageBasedBilling) == 0) {
      return;
    }

    // Rework data a little bit, first add column headings
    $data = array(array(
      'Bezeichnung',
      'Typ',
      'Kosten (CHF)',
      'Datum',
      'Zeitpunkt'
    ));

    $totalCosts = 0;
    foreach ($usageBasedBilling as $billing) {
      $data[] = array(
        $billing['title'],
        $billing['type'],
        number_format($billing['cost'], 2, ',', ''),
        date('d.m.Y', $billing['date']),
        date('H:i:s', $billing['date'])
      );
      $totalCosts += $billing['cost'];
    }

    // Add a line with the total of all costs
    $data[] = array(
      'Gesamtkosten',
      '',
      number_format($totalCosts, 2, ',', ''),
      '',
      ''
    );

    // Create CSV file from array
    $file = File::getNewUploadFolder() . 'usage-based-billing.csv';
    Csv::write($data, $file);
    // Send it via email to ourselves to create bills (as not used by many customers)
    $mail = External::PhpMailer();
    $mail->addAddress('it+usagebasedbilling@comotive.ch');
    $mail->Subject = '[' . LBWP_HOST . '] Nutzungsbasierte Abrechnung, CSV Beleg';
    $mail->Body = 'Beleg im Anhang';
    $mail->AltBody = $mail->Body;
    $mail->addAttachment($file);
    $mail->send();
    // Reset the counter, as info is sent and will be billed
    update_option('lbwp_usage_based_billing', array());
  }

  /**
   * @return void
   */
  public function addBillingInfoMenu()
  {
    $usageBasedBilling = ArrayManipulation::forceArray(get_option('lbwp_usage_based_billing'));
    if (count($usageBasedBilling) > 0) {
      add_submenu_page(
          'tools.php',
        'Dynamische Kosten',
        'Dynamische Kosten',
          'manage_options',
          'lbwp_billing_info',
          array($this, 'showBillingInfo')
      );
    }
  }

  /**
   * @return void
   */
  public function showBillingInfo()
  {
    $usageBasedBilling = ArrayManipulation::forceArray(get_option('lbwp_usage_based_billing'));
    $totalOpenCosts = 0;
    ?>
    <div class="wrap">
      <h1>Angefallene dynamische Kosten</h1>
      <p>Nicht verrechnete Kosten für Dienste die nach je nach Nutzung angefallen sind.</p>
      <table class="wp-list-table widefat fixed striped">
        <thead>
          <tr>
            <th>Titel</th>
            <th>Typ</th>
            <th>Kosten</th>
            <th>Datum / Uhrzeit</th>
          </tr>
        </thead>
        <tbody>
          <?php
          foreach ($usageBasedBilling as $usage) {
            $totalOpenCosts += $usage['cost'];
            echo '<tr>';
            echo '<td>' . $usage['title'] . '</td>';
            echo '<td>' . $usage['type'] . '</td>';
            echo '<td>' . number_format($usage['cost'],2) . ' CHF</td>';
            echo '<td>' . date('Y-m-d H:i:s', $usage['date']) . '</td>';
            echo '</tr>';
          }
          ?>
        </tbody>
        <tfoot>
        <tr>
          <th>&nbsp;</th>
          <th>Total</th>
          <th colspan="2"><?php echo number_format($totalOpenCosts,2); ?> CHF</th>
        </tr>
        </tfoot>
      </table>
    </div>
    <?php
  }

  /**
   * @param string $postTitle title of the audio generated
   * @param string $content actual content to calculate costs
   * @param int $words amount of words in the content
   * @return void
   */
  public static function addAiAudioUsage($postTitle, $content, $words = 0)
  {
    if ($words === 0) {
      $words = str_word_count($content);
    }
    $chunks = ceil($words / static::$tariffs['ai_audio']['words']);
    $cost = round($chunks * static::$tariffs['ai_audio']['per_chunk'], 2);
    $cost = number_format($cost, 2, '.', '');
    // Add usage to the billing list
    self::addUsage($postTitle, $cost, 'ai_audio');
  }

  /**
   * @param string $title of the usage
   * @param float $cost in CHF
   * @param string $type like "ai_audio"
   * @param string $date any date that is strtotime-able
   * @return void
   */
  public static function addUsage($title, $cost, $type, $date = '')
  {
    $usageBasedBilling = ArrayManipulation::forceArray(get_option('lbwp_usage_based_billing'));
    $usageBasedBilling[] = array(
      'title' => $title,
      'cost' => floatval($cost),
      'type' => $type,
      'date' => strlen($date) > 0 ? strtotime($date) : current_time('timestamp')
    );
    update_option('lbwp_usage_based_billing', $usageBasedBilling);
  }

  /**
   * @param $id
   * @return array|null
   */
  public static function getTariff($id)
  {
    if (isset(static::$tariffs[$id])) {
      return static::$tariffs[$id];
    }
    return null;
  }
}