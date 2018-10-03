<?php
/*
Plugin Name: LBWP - Load Balanced WordPress
Plugin URI: http://www.comotive.ch
Description: This plugins serves you with all the LBWP features to host multiple wordpress instances on many servers
Author: Michael Sebel / Martin Ott - comotive GmbH
Version: 1.0
Author URI: http://www.comotive.ch
*/

// Autoload from LBWP namespace
require_once __DIR__ . '/loader.php';
$loader = new SplClassLoader('LBWP', __DIR__);
$loader->register();

// Initialize the plugin
add_action('plugins_loaded', function() {
  global $LBWP;
  $LBWP = new \LBWP\Core(__DIR__);
  $LBWP->initialize();
});

// Register the install- and deinstallation hooks
register_activation_hook(__FILE__, array('\\LBWP\\Core', 'installPlugin'));
register_deactivation_hook(__FILE__, array('\\LBWP\\Core','uninstallPlugin'));

// Always start a session when not in cache mode (for now)
session_start();

// Include some global helper function
require_once __DIR__ . '/views/functions.php';

// Basic configuration for external, non cluster sites
define('LBWP_DISABLE_MEMCACHED', true);
define('LBWP_EXTERNAL', true);
define('LBWP_DISABLE_DASHBOARD_WIDGETS', true);
define('LBWP_ADMIN_FOOTER_WHITELABEL', 'WordPress powered by <a href="https://www.comotive.ch">comotive.ch</a>.');'
