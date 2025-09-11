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
try {
  session_start();
} catch (Exception $e) {
  // This actually works as a failover as it catches possible redis exceptions if redis is down
}

// Include some global helper function
require_once __DIR__ . '/views/functions.php';