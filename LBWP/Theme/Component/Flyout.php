<?php

namespace LBWP\Theme\Component;

use LBWP\Helper\PageSettings;
use LBWP\Theme\Base\Component as BaseComponent;
use LBWP\Util\Date;
use LBWP\Util\File;
use LBWP\Core as LbwpCore;

/**
 * A simple flyout configurator that is cookie based
 * @package LBWP\Theme\Component
 * @author Michael Sebel <michael@comotive.ch>
 */
abstract class Flyout extends BaseComponent
{
  /**
   * @var string various overrideable config strings
   */
  protected $confOptionPage = 'options-general.php';
  protected $confPageCapability = 'administrator';

  /**
   * Nothing to do here
   */
  public function init()
  {
    add_action('wp_footer', array($this, 'printTemplate'));
    add_action('wp_footer', array($this, 'printConfiguration'));
    add_action('admin_menu', array($this, 'addConfigurationPage'));
  }

  /**
   * Add the needed core css and js assets to make it work
   */
  public function assets()
  {
    $url = File::getResourceUri();
    wp_enqueue_script('lbwp-flyout-js', $url . '/js/components/lbwp-flyout.js', array('jquery'), LbwpCore::REVISION, true);
    wp_enqueue_script('jquery-cookie');
  }

  /**
   * Adds a configuration page for the flyout
   */
  public function addConfigurationPage()
  {
    PageSettings::initialize();
    PageSettings::addPage('flyout-settings', 'Flyout / Teaser', $this->confOptionPage, $this->confPageCapability);
    PageSettings::addSection('timeframe', 'flyout-settings', 'Aktivierung und Zeitraum', '');
    PageSettings::addCheckbox('flyout-settings', 'timeframe', 'flyoutActive', 'Flyout anzeigen, sofern Zeitrahmen zutrifft', 'Aktivierung', false, '', array(
      'saveCallback' => array($this, 'saveActivationCheckbox')
    ));
    PageSettings::addCheckbox('flyout-settings', 'timeframe', 'flyoutSessionOnly', 'Teaser nach Ablauf der Sitzung erneut anzeigen', 'Gültigkeit', false, '');
    PageSettings::addTextInput('flyout-settings', 'timeframe', 'flyoutFromDate', 'Anzeigen ab', false, 'Datum im Format dd.mm.yyyy angeben.');
    PageSettings::addTextInput('flyout-settings', 'timeframe', 'flyoutToDate', 'Anzeigen bis', false, 'Datum im Format dd.mm.yyyy angeben. Leer = Kein Enddatum.');
    PageSettings::addSection('content', 'flyout-settings', 'Inhalt des Flyouts', '');
    PageSettings::addEditor('flyout-settings', 'content', 'flyoutContent', 'Inhalt');
    PageSettings::addTextInput('flyout-settings', 'content', 'flyoutCloseText', 'Text des "Schliessen" Links', false, 'Je nach Design-Umsetzung wird der Text nicht oder nur für Screen-Reader angezeigt und mit einem Icon repräsentiert');
  }

  /**
   * @param array $item the saved item
   */
  public function saveActivationCheckbox($item)
  {
    $value = intval($_POST[$item['id']]);
    if ($value !== 1) {
      $value = 0;
    }

    // If the feature is activated, save new cookie identifier
    if ($value == 1 && get_option($item['id']) != 1) {
      update_option('flyoutCookieId', uniqid('lfo'));
    }

    // The value can be saved now
    update_option($item['id'], $value);
  }

  /**
   * Prints the flyout config for easy use
   */
  public function printConfiguration()
  {
    $config = array(
      'isActive' => get_option('flyoutActive') == 1,
      'forSessionOnly' => get_option('flyoutSessionOnly') == 1,
      'showFrom' => Date::getStamp(Date::EU_DATE, get_option('flyoutFromDate')),
      'showUntil' => Date::getStamp(Date::EU_DATE, get_option('flyoutToDate')),
      'cookieId' => get_option('flyoutCookieId')
    );

    echo '
      <script type="text/javascript">
        lbwpFlyoutConfig = ' . json_encode($config) . ';
      </script>
    ';
  }

  /**
   * Prints the flyout invisible to the footer
   */
  public function printTemplate()
  {
    echo '
      <div class="lbwp-flyout" style="display:none;">
        <a href="#" class="lbwp-close-flyout">
          <span>' . get_option('flyoutCloseText') . '</span>
        </a>
        <div class="flyout-content">
          ' . apply_filters('the_content', get_option('flyoutContent')) . '
        </div>
      </div>
    ';
  }
} 