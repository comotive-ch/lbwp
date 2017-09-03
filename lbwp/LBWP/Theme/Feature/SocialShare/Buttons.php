<?php

namespace LBWP\Theme\Feature\SocialShare;

use LBWP\Util\ArrayManipulation;
use LBWP\Util\Strings;
use LBWP\Util\WordPress;

/**
 * Social share buttons feature
 * @package LBWP\Theme\Feature\SocialShare
 * @author Michael Sebel <michael@comotive.ch>
 */
class Buttons
{
  /**
   * @var Buttons the core class
   */
  protected static $instance = null;
  /**
   * @var array configuration defaults for buttons
   */
  protected $config = array(
    // Button configurations
    'buttons' => array(
      SocialApis::FACEBOOK => array(
        'class' => '\LBWP\Theme\Feature\SocialShare\Button\Facebook',
        'action' => 'like', // or recommend
        'layout' => 'button_count', // or button
        'share' => false // or true
      ),
      SocialApis::TWITTER => array(
        'class' => '\LBWP\Theme\Feature\SocialShare\Button\Twitter',
        'fallback' => 'Twittern'
      ),
      SocialApis::GOOGLE_PLUS => array(
        'class' => '\LBWP\Theme\Feature\SocialShare\Button\GooglePlus'
      ),
      SocialApis::LINKED_IN => array(
        'class' => '\LBWP\Theme\Feature\SocialShare\Button\LinkedIn'
      ),
      SocialApis::XING => array(
        'class' => '\LBWP\Theme\Feature\SocialShare\Button\Xing'
      ),
      SocialApis::PRINTBUTTON => array(
        'class' => '\LBWP\Theme\Feature\SocialShare\Button\PrintButton'
      ),
      SocialApis::EMAIL => array(
        'class' => '\LBWP\Theme\Feature\SocialShare\Button\Email'
      )
    ),
    // Button order (likely to be overridden)
    'order' => array(
      SocialApis::FACEBOOK,
      SocialApis::TWITTER,
      SocialApis::LINKED_IN,
      SocialApis::GOOGLE_PLUS
    ),
    'type' => 'code' // or: top/bottom
  );
  /**
   * @var array the nice names of services for settings
   */
  protected $niceNames = array(
    SocialApis::FACEBOOK => 'Facebook',
    SocialApis::TWITTER => 'Twitter',
    SocialApis::LINKED_IN => 'LinkedIn',
    SocialApis::GOOGLE_PLUS => 'Google+',
    SocialApis::XING => 'XING',
    SocialApis::PRINTBUTTON => 'Drucken',
    SocialApis::EMAIL => 'E-Mail',
  );
  /**
   * @var string option name for settings
   */
  const SETTING_NAME = 'LbwpSocialShareSettings';

  /**
   * Lock construction from outside
   */
  protected function __construct() {}

  /**
   * Initialize the object
   */
  public static function init($config = array())
  {
    if (self::$instance == null) {
      self::$instance = new Buttons();
      self::$instance->load($config);
    }
  }

  /**
   * Echoes the full button list html
   */
  public static function show()
  {
    echo self::$instance->getHtml();
  }

  /**
   * Registers the needed actions
   */
  public function load($config)
  {
    // In admin, register a page that will save the settings
    if (is_admin()) {
      add_action('admin_menu', array($this, 'manageSettingsPage'));
      if (isset($_GET['page']) && $_GET['page'] == 'social-buttons') {
        wp_enqueue_script('jquery-ui-sortable');
      }
    }

    // Merge developer config
    $this->config = ArrayManipulation::deepMerge($this->config, $config);

    // If given, merge with user config
    $userConfig = get_option(self::SETTING_NAME, array());
    if (!empty($userConfig)) {
      unset($this->config['order']);
      $this->config = ArrayManipulation::deepMerge($this->config, $userConfig);
    }

    // Auto include the buttons in content, if desired
    if ($this->config['type'] != 'code') {
      add_filter('the_content', array($this, 'autoInsert'));
    }
  }

  /**
   * Displays and and saves the social button page
   */
  public function manageSettingsPage()
  {
    // See if there is a save action
    if (isset($_GET['action']) && $_GET['action'] == 'save') {
      $this->saveSettingsPage();
    }

    // Register the option page
    add_options_page(
      'Einstellungen › Social Buttons',
      'Social Buttons',
      'manage_options',
      'social-buttons',
      array($this, 'displaySettingsPage')
    );
  }

  /**
   * Display the social buttons settings
   */
  public function displaySettingsPage()
  {
    // Display the settings page
    echo '
      <div class="wrap">
        <h2>Einstellungen › Social Buttons</h2>
        <form action="options-general.php?page=social-buttons&action=save" method="post">
          <p>Sie können mit der Checkbox entscheiden, welche Buttons angezeigt werden. Mit der Maus können sie die Buttons durch ziehen sortieren.</p>
          ' . $this->getOrderSetting() . '
          <p>Entscheiden Sie hier, wie und wo die Buttons eingebunden werden.</p>
          ' . $this->getTypeSetting() . '
          <p class="submit">
            <input type="submit" name="submit" id="submit" class="button button-primary" value="Speichern">
          </p>
        </form>
      </div>
    ';
  }

  /**
   * @return string html code for the setting
   */
  protected function getOrderSetting()
  {
    $html = '<ul class="simple-table-list generic-sortable">';

    // First, add the currently configured buttons
    $usedButtons = array();
    foreach ($this->config['order'] as $key) {
      $usedButtons[] = $key;
      $html .= '
        <li>
          <label>
            <input type="checkbox" name="order[' . $key . ']" value="1" checked="checked">
            ' . $this->niceNames[$key] . '
          </label>
        </li>
      ';
    }

    // Fill up with the ones not used yet (At the end)
    foreach ($this->config['buttons'] as $key => $config) {
      if (!in_array($key, $usedButtons)) {
        $html .= '
        <li>
          <label>
            <input type="checkbox" name="order[' . $key . ']" value="1">
            ' . $this->niceNames[$key] . '
          </label>
        </li>
      ';
      }
    }

    // Add the sortable script
    $html .= '
      <script type="text/javascript">
        jQuery(function() {
          jQuery(".simple-table-list.generic-sortable").sortable();
        });
      </script>
    ';

    // Close the container and return
    return $html . '</ul>';
  }

  /**
   * @return string html code for the setting
   */
  protected function getTypeSetting()
  {
    $html = '';
    $types = array(
      'code' => 'Im Theme-Code hinterlegt',
      'top' => 'Automatisch am Anfang des Artikels',
      'bottom' => 'Automatisch am Ende des Artikels'
    );

    // Create the options
    $html .= '<select name="settingType">';
    foreach ($types as $key => $value) {
      $selected = selected($this->config['type'], $key, false);
      $html .= '<option value="' . $key . '"' . $selected . '>' . $value . '</option>';
    }
    $html .= '</select>';

    return $html;
  }

  /**
   * Save the newly created settings
   */
  protected function saveSettingsPage()
  {
    // Rebuild the order array
    $config = array();
    unset($this->config['order']);
    foreach ($_POST['order'] as $key => $value) {
      if ($value == 1) {
        $config['order'][] = $key;
      }
    }

    // Save the type
    $config['type'] = Strings::validateField($_POST['settingType']);
    // Save the config
    $this->config = ArrayManipulation::deepMerge($this->config, $config);
    update_option(self::SETTING_NAME, $this->config);
    // Go back to main page
    wp_redirect(get_admin_url() . 'options-general.php?page=social-buttons&success');
    exit;
  }

  /**
   * Auto insert into content, if configured
   * @param string $content the post content html
   * @return string $content with added share buttons html
   */
  public function autoInsert($content)
  {
    if (!is_singular()) {
      return $content;
    }

    switch ($this->config['type']) {
      case 'top':
        $content = $this->getHtml() . $content;
        break;
      case 'bottom':
        $content = $content . $this->getHtml();
        break;
    }
    return $content;
  }

  /**
   * @return string the buttons html
   */
  public function getHtml()
  {
    $post = WordPress::getPost();
    $link = get_permalink($post->ID);
    $html = '<div class="lbwp-share-buttons"><ul>';

    // Add the buttons
    foreach ($this->config['order'] as $key) {
      $config = $this->config['buttons'][$key];
      /** @var BaseButton $button */
      $button = new $config['class'];
      $html .= '
        <li class="social-button-' . $key . '">
          ' . $button->getHtml($config, $link, $post) . '
        </li>
      ';
    }

    // Close list and return
    return $html . '</ul></div>';
  }
} 