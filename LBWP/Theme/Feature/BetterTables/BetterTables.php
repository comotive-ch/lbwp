<?php
namespace LBWP\Theme\Feature\BetterTables;

use LBWP\Core;
use LBWP\Util\File;

/**
 * Make WordPress Tables great again
 * @author Mirko Baffa <mirko@comotive.ch>
 */
abstract class BetterTables{
  const BETTER_TABLES_PAGES = array(
    'users.php',
    // TODO: Add more pages
  );

  protected $settings = array(
    'useFlatTable' => false, //true,
    'crmComponent' => null
  );


  public function __construct($settings = array()){
    $this->replaceWPPage();

    add_action('admin_enqueue_scripts', array($this, 'enqueueScripts'));
    add_filter( 'script_loader_tag', (function($tag, $handle, $src) {
      if($handle !== 'lbwp-better-tables') {
        return $tag;
      }

      return str_replace( 'src=', 'type="module" src=', $tag );
    }), 10, 3 );

    add_action('rest_api_init', array($this, 'registerApiEndpoints'));

    $this->settings = array_merge($this->settings, $settings);
  }

  public function enqueueScripts(){
    wp_enqueue_style('lbwp-better-tables', File::getResourceUri() . '/css/lbwp-better-tables.css', array(), time());

    wp_enqueue_script('lbwp-better-tables', File::getResourceUri() . '/js/PIM/Main.js', array(), time(), true);

    wp_localize_script('lbwp-better-tables', 'lbwpBetterTables', array(
      'user_id' => get_current_user_id(),
      'ajax_url' => get_bloginfo('url') . '/wp-json/lbwp/bettertables/',
      'nonce' => wp_create_nonce('wp_rest')
    ));
  }

  abstract function replaceWPPage();

  abstract function registerApiEndpoints();
}
