<?php

namespace LBWP\Module\General;

use LBWP\Util\Strings;
use LBWP\Util\WordPress;
use WP_Screen;
use LBWP\Core;
use LBWP\Util\Cookie;
use LBWP\Module\Backend\MemcachedAdmin;
use LBWP\Module\Listings\Component\Posttype as ListingTypes;
use LBWP\Module\Forms\Component\Posttype as FormTypes;

/**
 * Clean up module to remove all the stuff your customers shouldn't see.
 * That's for example the plugin installation which is not yet useable on
 * multiple loadbalanced servers since it would only install a plugin on
 * one server. Also, updates of plugins and WordPress are yet unavailable.
 * @author Michael Sebel <michael@comotive.ch>
 */
class CleanUp extends \LBWP\Module\Base
{
  /**
   * @var array overrides for the yoast wpseo_titles option
   */
  protected $noYoastTypes = array(
    'lbwp-form' => true,
    'lbwp-table' => true,
    'lbwp-list' => true,
    'lbwp-listitem' => true,
    'lbwp-snippet' => true,
    'lbwp-user-group' => true,
    'onepager-item' => true, // yes, lbwp missing
    'lbwp-mailing-list' => true
  );
  /**
   * call parent constructor and initialize the module
   */
  public function __construct()
  {
    parent::__construct();
  }

  /**
   * Registers all the actions and filters and removes some.
   */
  public function initialize()
  {
    if (is_admin()) {
      // Change footers, metaboxes, widgets etc.
      //add_action('in_admin_header',array($this,'adminMaintenanceMessage'));
      //add_action('admin_menu',array($this,'adminMaintenanceMenu'));
      remove_action('welcome_panel', 'wp_welcome_panel');
      remove_action('try_gutenberg_panel', 'wp_try_gutenberg_panel');
      add_action('admin_menu', array($this, 'registerSuperlogin'));
      add_filter('wp_prepare_themes_for_js', array($this, 'removeCustomerThemes'));
      add_action('admin_init', array($this, 'trySuperlogin'));
      add_action('admin_init', array($this, 'updateUserMeta'));
      add_action('admin_footer_text', array($this, 'footer'));
      add_action('wp_dashboard_setup', array($this, 'dashboard'));
      add_action('admin_menu', array($this, 'menu'), 5000);
      add_action('admin_notices', array($this, 'removeUpdateNag'), 1);
      add_filter('user_has_cap', array($this, 'preventCaps'), 1, 10);
      add_action('admin_head', array($this, 'removeBackendThemes'), 100);
      add_action('wpseo_submenu_pages', array($this, 'removeYoastPages'));
      add_filter('wpseo_metabox_prio', array($this, 'getLowPriority'));
      add_action('do_meta_boxes', array($this, 'removeMetaboxesFromTypes'), 5);
      add_action('admin_enqueue_scripts', array($this, 'removePluginAssets'), 50);
      remove_filter('pre_user_description', 'wp_filter_kses');
      $this->fixCustomDateTimeFormat();

      // Also, set some update filters for yoast
      if (isset($_GET['wpseo_reset_defaults'])) {
        $this->fixYoastOptions();
      }
    }

    // Remove things for non superusers
    if (!Core::isSuperlogin()) {
      add_action('customize_register', array($this, 'removeAdditionalCssEditor'), 15);
    }

    // Disable vc frontend editor completely
    if (function_exists('vc_disable_frontend')) {
      vc_disable_frontend();
    }

    // do those actions on every page
    add_action('wp_before_admin_bar_render', array($this, 'adminBar'), 1000);
    add_filter('login_headerurl', array($this, 'headerUrl'));
    add_filter('login_errors', array($this, 'obfuscateLoginError'));
    add_filter('next_post_rel_link', array($this, 'void'));
    add_filter('previous_post_rel_link', array($this, 'void'));
    add_filter('login_head', array($this, 'prepareLocalDb'));
    add_filter('comment_moderation_text', array($this, 'getSpamlessCommentNotification'), 10, 2);
    add_action('phpmailer_init', array($this, 'preventMassMail'));
    add_action('wp', array($this, 'preventMass404'), 50);
    add_Filter('the_privacy_policy_link', '__return_empty_string');
    remove_action('wp_head', 'wp_generator');
  }

  /**
   * Remove menus that are non-sense and don't work
   * @param array $submenus all submenus
   * @return array the needed submenus
   */
  public function removeYoastPages($submenus)
  {
    $disallowed = array(
      'wpseo_tools',
      'wpseo_permalinks',
      'wpseo_internal-links',
      'wpseo_files',
      'wpseo_licenses',
    );

    foreach ($submenus as $index => $submenu) {
      if (in_array($submenu[4], $disallowed)) {
        unset($submenus[$index]);
      }
    }

    return $submenus;
  }

  /**
   * This needs to be fixed, as we remove wp magic quotes
   */
  protected function fixCustomDateTimeFormat()
  {
    if (isset($_POST['date_format']) && $_POST['date_format'] == '\c\u\s\t\o\m') {
      $_POST['date_format'] = "\\\\c\\\\u\\\\s\\\\t\\\\o\\\\m";
    }
    if (isset($_POST['time_format']) && $_POST['time_format'] == '\c\u\s\t\o\m') {
      $_POST['time_format'] = "\\\\c\\\\u\\\\s\\\\t\\\\o\\\\m";
    }
  }

  /**
   * Updates usermeta on lbwp core revision raise
   */
  public function updateUserMeta()
  {
    $userId = get_current_user_id();
    $currentRev = intval(get_user_meta($userId, 'lbwp_user_meta_revision', true));

    // If revision has been raised, change user meta on certain condition
    if ($currentRev < Core::REVISION) {
      // Updates for revision 107
      if ($currentRev < 107 && Core::REVISION >= 107) {
        // Set some variables for yoast
        update_user_meta($userId, 'wpseo_seen_about_version', '3.0.7');
        update_user_meta($userId, 'wpseo_ignore_tour', 1);
      }
      if ($currentRev < 192 && Core::REVISION >= 192) {
        // Set some variables for yoast
        $this->dismissWpPointer('wpmudcs1', $userId);
      }

      // Save new revision after doing all upgrades
      update_user_meta($userId, 'lbwp_user_meta_revision', Core::REVISION);
    }
  }

  /**
   * @param string $pointer the pointer to be dismissed
   * @param int $id the user id
   */
  protected function dismissWpPointer($pointer, $id)
  {
    $pointers = get_user_meta($id, 'dismissed_wp_pointers', true);
    if (strlen($pointers) > 0) {
      $pointers .= ',' . $pointer;
    } else {
      $pointers = $pointer;
    }
    update_user_meta($id, 'dismissed_wp_pointers', $pointers);
  }

  /**
   * @param string $error the original error
   * @return string the error message more obfuscated
   */
  public function obfuscateLoginError($error)
  {
    // Handle some of our custom error messages
    if (Strings::contains($error, '<!--authentication-prevented-->')) {
      return $error;
    }
    return sprintf(
      __('Ihre Anmeldung ist fehlgeschlagen. Geben Sie Ihren Benutzernamen und das Passwort erneut ein. <a href="%s">Passwort vergessen?</a>'),
      get_bloginfo('url') . '/wp-login.php?action=lostpassword'
    );
  }

  /**
   * Remove certain metaboxes from certain internal types
   * @param string $type the post type
   */
  public function removeMetaboxesFromTypes($type)
  {
    if (in_array($type, $this->noYoastTypes)) {
      remove_meta_box('NS_SNAP_AddPostMetaTags', $type, 'advanced');
    }
  }

  /**
   * Remove assets from plugins that are breaking the site due to bad js code
   */
  public function removePluginAssets()
  {
    global $current_screen;

    // Remove the global jquery ui dialog if enhanced media library
    if ($this->features['Plugins']['EnhancedMediaLibrary'] == 1) {
      wp_dequeue_style('wp-jquery-ui-dialog');
    }

    if (in_array($current_screen->post_type, $this->noYoastTypes)) {
      wp_dequeue_style('wp-seo-metabox');
      wp_dequeue_style('wp-seo-scoring');
      wp_dequeue_style('wp-seo-snippet');
      wp_dequeue_style('yoast-seo');
      // Remove all friggin yoast js
      wp_dequeue_script('yoast-seo');
      wp_dequeue_script('wp-seo-metabox');
      wp_dequeue_script('wpseo-admin-media');
    }
  }

  /**
   * Make some options unchooseable
   */
  protected function fixYoastOptions()
  {
    // Do not activate the opengraph stuff (functionality done in services)
    add_filter('pre_update_option_wpseo_social', function ($option) {
      if (isset($option['opengraph'])) {
        unset($option['opengraph']);
      }
      return $option;
    });

    // Do not append strings on feed output by default
    add_filter('pre_update_option_wpseo_rss', function ($option) {
      if (isset($_GET['wpseo_reset_defaults']) && isset($option['rssafter'])) {
        $option['rssafter'] = '';
      }
      return $option;
    });

    // Deactivate this option, as we remove the permalink submenu
    add_filter('pre_update_option_wpseo_permalinks', function ($option) {
      if (isset($_GET['wpseo_reset_defaults']) && isset($option['cleanslugs'])) {
        unset($option['cleanslugs']);
      }
      return $option;
    });
  }

  /**
   * Removes the action that shows the wordpress update message
   */
  public function removeUpdateNag()
  {
    remove_action('admin_notices', 'update_nag', 3);
  }

  /**
   * Is only activated if badly needed. makes the backend unavailable.
   */
  public function adminMaintenanceMessage()
  {
    echo '
      <div id="wphead">
        <div id="wphead-info">
          <div class="updated"><p><strong>Diese Webseite wird momentan durch die Administratoren gewartet. Wir bitten Sie um Geduld.</strong></p></div>
          <style type="text/css">
            #dashboard-widgets-wrap { display:none; }
          </style>
          <script type="text/javascript">
            jQuery(function($) {
              $(".wrap h2").html("Wartungsarbeiten");
              $("#dashboard-widgets-wrap").remove();
            });
          </script>
        </div>
      </div>
    ';
  }

  /**
   * @param \PHPMailer $phpMailer the instance that should send a mail
   */
  public function preventMassMail($phpMailer)
  {
    WordPress::checkSignature('massmail', 60, 40, 3600);
  }

  /**
   * Prevent mass 404ing by bots and block them
   */
  public function preventMass404()
  {
    if (is_404() && !is_user_logged_in()) {
      WordPress::checkSignature('mass404', 30, 20, 3600);
    }
  }

  /**
   * Is only activated if badly needed. makes the backend unavailable.
   * Only leaves the dashboard menu.
   */
  public function adminMaintenanceMenu()
  {
    global $menu;
    foreach ($menu as $key => $item) {
      if ($key !== 2) {
        unset($menu[$key]);
      }
    }
  }

  /**
   * Removing customer themes in the backend theme selection
   * @param array $themes the list of displayable themes
   * @return mixed
   */
  public function removeCustomerThemes($themes)
  {
    // Superlogin can skip this
    if (!Core::isSuperlogin()) {
      foreach ($themes as $slug => $theme) {
        // Remove all themes but the current one
        if ($theme['active'] !== true) {
          unset($themes[$slug]);
        }
      }
    }

    return $themes;
  }

  /**
   * Leaves only the fresh (Default) and midnight theme.
   */
  public function removeBackendThemes()
  {
    global $_wp_admin_css_colors;
    $_wp_admin_css_colors = array(
      'midnight' => $_wp_admin_css_colors['midnight'],
      'fresh' => $_wp_admin_css_colors['fresh'],
    );
  }

  /**
   * @param \WP_Customize_Manager $customizer
   */
  public function removeAdditionalCssEditor($customizer)
  {
    $customizer->remove_section('custom_css');
  }

  /**
   * Register the superlogin menu page
   */
  public function registerSuperlogin()
  {
    add_submenu_page(
      'tools.php',
      'Administrator Login',
      'Administrator Login',
      'administrator',
      'superlogin',
      array($this, 'loginForm')
    );
  }

  /**
   * If parameters are given do the superlogin or logout from it
   */
  public function trySuperlogin()
  {
    // maybe login
    if (isset($_POST['trylogin'])) {
      if (Core::USER_KEY == $_POST['userName'] && Core::USER_PASS == $_POST['userPass']) {
        Cookie::set('lbwp-superlogin', md5(Core::USER_PASS) . md5(Core::USER_KEY));
        $this->reloadSuperLogin();
      }
      if ($_POST['userName'] == 'comotive' && wp_get_current_user()->user_login == 'comotive') {
        Cookie::set('lbwp-superlogin', md5(Core::USER_PASS) . md5(Core::USER_KEY));
        $this->reloadSuperLogin();
      }
    }

    // and maybe logout
    if ($_GET['page'] == 'superlogin' && isset($_GET['logout'])) {
      Cookie::set('lbwp-superlogin', false);
      Core::preventSuperlogin();
      $this->reloadSuperLogin();
    }
  }

  /**
   * Loads the super login page
   */
  public function reloadSuperLogin()
  {
    header('Location: ' . get_admin_url() . 'tools.php?page=superlogin');
    exit;
  }

  /**
   * Changes the local DB do work easily and without fear of something being sent out
   */
  public function prepareLocalDb()
  {
    if (defined('LOCAL_DEVELOPMENT') && get_option('preparedLocalDb') != '1') {
      // Set "lbwp" as password for all users
      $this->wpdb->query('
        UPDATE ' . $this->wpdb->users . '
        SET user_pass = "' . wp_hash_password('lbwp') . '"
      ');

      $config = get_option('LbwpConfig');
      // Deactivate maintenance mode
      $config['Various:MaintenanceMode'] = 0;
      $config['HTMLCache:CacheTime'] = 300;
      $config['HTMLCache:CacheTimeSingle'] = 300;
      update_option('LbwpConfig', $config);

      // Flush the whole cache with redis asterisk
      $cache = wp_get_cache_bucket();
      $keys = $cache->keys('*' . str_replace('_', '', $this->wpdb->prefix) . '*');
      $cache->delete($keys);

      // Do it only once
      update_option('preparedLocalDb', '1');
    }
  }

  /**
   * Display the superlogin form
   */
  public function loginForm()
  {
    $hash = 'd6g483jd8743zt9ohg2oi4zt93houefhvgkjweho2iz0fvoe54nto2z6o4igou3gv89be40ufh9724hg9';
    // Logged in text
    $loggedIn = $features = '';
    if (Core::isSuperlogin()) {
      $loggedIn = '
				Du wurdest erfolgreich eingeloggt.
				<a href="' . get_admin_url() . 'tools.php?page=superlogin&logout">Logout</a>.
		  ';
      $features = '
        <h3>Admin Funktionen</h3>
        <p>
          <a href="/wp-content/plugins/lbwp/views/cron/passwd.php?hash=' . $hash . '" class="button" target="_blank">Login Token generieren</a>
        </p>
        <h3>Debug Funktionen</h3>
        Session: ' . Strings::getVarDump($_SESSION) . '
        Server: ' . Strings::getVarDump($_SERVER) . '
      ';
      $_SESSION['test-superlogin'] = time();
    }
    // form output and title
    echo '
			<div class="wrap">
				<div id="icon-tools" class="icon32"><br></div>
				<h2>Erweiterte Rechte aktivieren</h2>
				' . $loggedIn . '
				<form action="' . get_admin_url() . 'tools.php?page=superlogin" method="post">
					<table>
						<tr>
							<td width="120">Benutzername</td>
							<td><input type="text" class="input" size="40" name="userName" /></td>
						</tr>
						<tr>
							<td width="120">Passwort</td>
							<td><input type="password" class="input" size="40" name="userPass" /></td>
						</tr>
					</table>
					<p><input type="submit" name="trylogin" value="Login" class="button-primary" /></p>
				</form>
				' . $features . '
			</div>
		';
  }

  /**
   * Deletes menus that are unwanted for everyone to see
   */
  public function menu()
  {
    global $menu, $submenu;

    // remove the "aktualisieren" menu item
    if (isset($submenu['index.php'][10]) && !Core::isSuperlogin()) {
      unset($submenu['index.php'][10]);
    }
    // remove the "editor" menu item possibly activated by themes
    if (isset($submenu['themes.php'][14])) {
      unset($submenu['themes.php'][14]);
    }

    if (isset($submenu['options-general.php'])) {
      foreach ($submenu['options-general.php'] as $key => $item) {
        if ($item[2] == 'privacy.php') {
          unset($submenu['options-general.php'][$key]);
        }
        if ($item[2] == 'eml-settings') {
          unset($submenu['options-general.php'][$key]);
        }
        if ($item[2] == 'NextScripts_SNAP.php') {
          $submenu['options-general.php'][$key][0] = __('Soziale Netzwerke', 'lbwp');
          $submenu['options-general.php'][$key][3] = __('Soziale Netzwerke', 'lbwp');
        }
      }
    }

    // Loop that whole menu to move analytics if found
    foreach ($menu as $key => $item) {
      if ($item[2] == 'yst_ga_dashboard') {
        unset($menu[$key]);
        $menu[95.7645] = $item;
      }
    }

    // If set, remove submenus from yoast analytics
    if (isset($submenu['yst_ga_dashboard'])) {
      foreach ($submenu['yst_ga_dashboard'] as $key => $item) {
        if ($item[2] == 'yst_ga_extensions') {
          unset($submenu['yst_ga_dashboard'][$key]);
        }
      }
    }

    // Remove lingotek from polylang, as this is seemingly bad quality
    if (isset($submenu['mlang'])) {
      foreach ($submenu['mlang'] as $key => $item) {
        if ($item[2] == 'mlang_lingotek') {
          unset($submenu['mlang'][$key]);
        }
      }
    }
  }

  /**
   * This filter removes some capabilities unless you are a superlogin user
   * @param array $capabilites capabilities array of the current user
   * @return array new (maybe changed) capabilities
   */
  public function preventCaps($capabilites)
  {
    // doesn't matter who you are, some things are only allowed with the superlogin
    if (!Core::isSuperlogin()) {
      unset($capabilites['update_core']);
      unset($capabilites['delete_themes']);
      unset($capabilites['update_themes']);
      unset($capabilites['install_themes']);
      unset($capabilites['update_plugins']);
      unset($capabilites['delete_plugins']);
      unset($capabilites['install_plugins']);
      unset($capabilites['activate_plugins']);
      unset($capabilites['edit_plugins']);
      unset($capabilites['edit_themes']);
    }

    // Always prevent editing of themes and plugins
    unset($capabilites['edit_themes']);
    unset($capabilites['edit_plugins']);

    return $capabilites;
  }

  /**
   * @param string $content actual email content
   * @param int $commentId the comment id
   * @return string spamless and partly obfuscated email content
   */
  public function getSpamlessCommentNotification($content, $commentId)
  {
    // Reset content and get all comment information needed
    $content = '';
    $comment = get_comment($commentId);
    $commentContent = strip_tags(str_replace(array('[', ']'), '', $comment->comment_content));
	  $post = get_post($comment->comment_post_ID);

    // Default information, a little simplified
    $content  = sprintf( __('A new comment on the post "%s" is waiting for your approval'), $post->post_title ) . "\r\n";
    $content .= get_permalink($comment->comment_post_ID) . "\r\n\r\n";
    $content .= sprintf( __('Autor: %s', 'lbwp'), Strings::obfuscate($comment->comment_author, 5, 4)) . "\r\n";
    $content .= sprintf( __('E-Mail: %s', 'lbwp'), Strings::obfuscate($comment->comment_author_email, 4, 3, '**', '@')) . "\r\n";
    // Provide some information to users
    $content .= __('Hinweis: Der Kommentar könnte Spam enthalten, daher zeigen wir hier nur ein paar Worte davon an.', 'lbwp') . "\r\n\r\n";
	  $content .= __('Comment: ') . "\r\n";

    // Chop into words and validate each word again
    $words  = explode(' ', Strings::chopToWords($commentContent, 12, true, '...', 150));
    foreach ($words as $key => $word) {
      if (Strings::checkURL(trim($word))) {
        $words[$key] = '*blocked url*';
      }
    }

    // Show user a link to backend
    $content .= implode(' ', $words) . "\r\n\r\n";
    $content .= __('Alle noch nicht genehmigten Kommentare anzeigen:', 'lbwp') . ' ';
    $content .= admin_url("edit-comments.php?comment_status=moderated") . "\r\n";

    // Return that simple mail content
    return $content;
  }

  /**
   * Removes crappy dashboard items
   */
  public function dashboard()
  {
    remove_meta_box('dashboard_quick_press', 'dashboard', 'side');
    remove_meta_box('dashboard_recent_drafts', 'dashboard', 'side');
    remove_meta_box('dashboard_primary', 'dashboard', 'side');
    remove_meta_box('dashboard_secondary', 'dashboard', 'side');
    remove_meta_box('dashboard_plugins', 'dashboard', 'normal');
    remove_meta_box('dashboard_incoming_links', 'dashboard', 'normal');
  }

  /**
   * @return string returns a changed footer info string, gnihihi
   */
  public function footer()
  {
    if (defined('LBWP_ADMIN_FOOTER_WHITELABEL')) {
      return LBWP_ADMIN_FOOTER_WHITELABEL;
    } else {
      return 'Vielen Dank, dass sie die Managed WordPress Lösung der <a href="https://www.comotive.ch" target="_blank">comotive GmbH</a> einsetzen.';
    }
  }

  /**
   * Remove some crap in the admin bar
   */
  public function adminBar()
  {
    global $wp_admin_bar;
    $wp_admin_bar->remove_node('wp-logo');
    $wp_admin_bar->remove_node('updates');
    // remove links and user creation
    $wp_admin_bar->remove_node('new-user');
    $wp_admin_bar->remove_node('customize');
    $wp_admin_bar->remove_node('snap-post');
    // since wp3.4
    if (!Core::isSuperlogin()) {
      $wp_admin_bar->remove_node('themes');
      $wp_admin_bar->remove_node('cs-explain');
    }
  }

  /**
   * @return string change the wordpress.org url at login
   */
  public function headerUrl()
  {
    return 'http://www.comotive.ch/';
  }

  /**
   * Just a void functions that returns an empty string
   * @param mixed $content whatever variable is given...
   * @return string an empty string
   */
  public function void($content)
  {
    return '';
  }

  /**
   * @return string returns low as for metabox priority filters
   */
  public function getLowPriority()
  {
    return 'low';
  }
}