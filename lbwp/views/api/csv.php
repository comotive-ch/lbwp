<?php
define('CSV_OUTPUT_KEY', 'Nskw8çmEwnakd734ghfhreWw92');
define('CSV_OUTPUT_SECRET', 'nsh38dhr74jhqjalehndhr647jrhduejrls9765slkj942he9823j5kj');

if (!isset($_REQUEST[CSV_OUTPUT_KEY]) || $_REQUEST[CSV_OUTPUT_KEY] != CSV_OUTPUT_SECRET) {
  exit;
}

require_once '../../../../../wp-load.php';

//header('Content-Description: File Transfer');
//header('Content-Disposition: attachment; filename=output.csv');
//header('Content-Type: application/octet-stream; charset=' . get_option('blog_charset'), true);

// Print a line of identifiers
echo '"ID";"Datum";"Titel";"Sprache";"Bearbeitungslink";"Kategorien";' . PHP_EOL;

$url = get_admin_url();
$total = 0;
$page = 1;
while ($page < 50000) {
  $posts = get_posts(array(
    'post_type' => 'post',
    'posts_per_page' => 50,
    'paged' => $page,
    'lang' => ''
  ));

  // Exit if there are no posts anymore
  if (count($posts) == 0) {
    break;
  }

  foreach ($posts as $post) {
    $language = \LBWP\Util\Multilang::getPostLang($post->ID);
    $date = substr($post->post_date, 0, 10);
    $edit = $url . 'post.php?post=' . $post->ID . '&action=edit';
    echo '"' . $post->ID . '";"' . $date . '";"' . $post->post_title . '";"' . $language . '";"' . $edit . '";';
    // Now echo the categories of the post
    foreach (wp_get_post_terms($post->ID, 'category') as $term) {
      echo '"' . $term->name . '";';
    }
    // And print a line end for the next post
    echo PHP_EOL;
    ++$total;
  }

  ++$page;
}
