<?php
/**
 * wpSEO to Yoast Meta Migration Script
 * Please flush the cache after running it, it shouldn't be necessary, but do it.
 */
define('YOAST_OUTPUT_KEY', 'jhfgda6134uhgre98zu4q3tnorge9834qtonia');
define('YOAST_OUTPUT_SECRET', 'u34joirg9u83698uerzjohi9z3ijotzepjorejreg9z87439z43ouheroi');

if (!isset($_REQUEST[YOAST_OUTPUT_KEY]) || $_REQUEST[YOAST_OUTPUT_KEY] != YOAST_OUTPUT_SECRET) {
  exit;
}

require_once '../../../../../wp-load.php';
header('Content-Type: text/plain');

// Require some framework libraries
use LBWP\Util\WordPress;
use LBWP\Module\Frontend\HTMLCache;

// Do not cache this request
HTMLCache::avoidCache();

// Mapping table for meta informations
$map = array(
  '_wpseo_edit_title' => '_yoast_wpseo_title',
  '_wpseo_edit_description' => '_yoast_wpseo_metadesc',
);

// WordPress database layer
$db = WordPress::getDb();

// Loop trough the whole map and replace the meta infos
foreach ($map as $old => $new) {
  logr('Start migrating keys of ' . $old . ' to ' . $new);
  // Get all meta items with the old key directly from the DB
  $meta = $db->get_results('
    SELECT post_id, meta_value FROM ' . $db->prefix . 'postmeta
    WHERE meta_key = "' . $old . '"
  ', ARRAY_A);

  // Create new meta, then delete the old
  foreach ($meta as $row) {
    logr('Migrating meta data of post id ' . $row['post_id']);
    update_post_meta($row['post_id'], $new, $row['meta_value']);
    delete_post_meta($row['post_id'], $old);
  }

  logr('Finished migration of ' . $old . ' keys');
}

// Simple log function
function logr($message)
{
  echo $message . PHP_EOL;
}