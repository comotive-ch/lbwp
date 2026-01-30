<?php
if (!defined('LOCAL_DEVELOPMENT')) {
  return;
}

use LBWP\Util\Strings;

require_once '../../../../../../wp-load.php';

set_time_limit(900);

$api = 'https://spitex-report.ch/wp-json/wp/v2/posts/';
$filesPath = '/var/www/lbwp/wp-content/themes/spitex-report/assets/import/files.json';
$config = array(
  'per_page' => $_GET['count'],
  'page' => $_GET['page']
);

foreach ($config as $key => $value) {
  $api = Strings::attachParam($key, $value, $api);
}

// Get the files to be replaced
$files = json_decode(file_get_contents($filesPath), true);

// Get the file data
$data = json_decode(Strings::genericRequest($api, array(), 'GET'), true);

foreach ($data as $post) {
  // Get base content, replace all file urls
  $content = $post['content']['rendered'];
  foreach ($files as $file) {
    $content = str_replace($file['before'], $file['after'], $content);
  }
  // Create the post in DB
  $postId = wp_insert_post(array(
    'post_type' => 'post',
    'post_status' => 'publish',
    'post_date' => $post['date'],
    'post_modified' => $post['modified'],
    'post_name' => $post['slug'],
    'post_title' => $post['title']['rendered'],
    'post_content' => $content,
    'post_excerpt' => $post['excerpt']['rendered'],
    'post_author' => get_mapped_author($post['author']),
    'comment_status' => $post['comment_status'],
    'ping_status' => $post['ping_status']
  ));

  // Set the correct terms, make another call for that
  if ($postId > 0) {
    foreach ($post['_links']['wp:term'] as $config) {
      $terms = json_decode(Strings::genericRequest($config['href'], array(), 'GET'), true);
      $locals = array();
      foreach ($terms as $term)  {
        $local = get_term_by('slug', $term['slug'], $config['taxonomy']);
        if ($local === false) {
          // Term doesnt exist, create it
          $termId = wp_insert_term($term['name'], $config['taxonomy'], array(
            'slug' => $term['slug']
          ));
          $local = get_term_by('term_id', $termId, $config['taxonomy']);
        }
        $locals[] = $local;
      }

      $ids = array();
      foreach ($locals as $term) $ids[] = $term->term_id;
      wp_set_post_terms($postId, $ids, $config['taxonomy'], true);
    }
  }

  sleep(1);
}


function get_mapped_author($id)
{
  switch ($id) {
    case 11: return 7;
    case 2: return 4;
    case 4: return 25;
    case 10: return 10;
    case 8: return 13;
    case 9: return 16;
    case 7: return 19;
    case 6: return 22;
  }
  return 1;
}
