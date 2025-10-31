<?php
define('CSV_OUTPUT_KEY', 'Nskw8çmEwnakd734ghfhreWw92');
define('CSV_OUTPUT_SECRET', 'nsh38dhr74jhqjalehndhr647jrhduejrls9765slkj942he9823j5kj');

if (!isset($_REQUEST[CSV_OUTPUT_KEY]) || $_REQUEST[CSV_OUTPUT_KEY] != CSV_OUTPUT_SECRET) {
  exit;
}

require_once '../../../../../wp-load.php';

header('Content-Description: File Transfer');
header('Content-Disposition: attachment; filename=output.csv');
header('Content-Type: application/octet-stream; charset=' . get_option('blog_charset'), true);

// Print a line of identifiers
echo '"ID";"TSID";"Type";"Datum";"Titel";"Sprache";"Permalink";"Kategorien";' . PHP_EOL;

$total = 1;
$page = 1;
while ($page < 50000) {
  $posts = get_posts(array(
    'post_type' => array('post', 'page'),
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
    $translations = array_filter(\LBWP\Util\Multilang::getPostTranslations($post->ID, true));
    // If german or has no translations, display it
    if ($language == 'de' || count($translations) == 1) {
      // Print the actual post
      printPost($post, $total, $language);
      // And the translations
      /*
      foreach($translations as $lang => $id) {
        printPost(get_post($id), $total, $lang);
      }
      */
    }
    ++$total;
  }
  ++$page;
}

function printPost($post, $total, $language)
{
  if (empty($post)) {
    return;
  }
  $date = substr($post->post_date, 0, 10);
  $title = str_replace(array('"', "'"), '', $post->post_title);
  echo '"' . $post->ID . '";"' . $total . '";"' . $post->post_type . '";"' . $date . '";"' . $title . '";"' . $language . '";"' . get_permalink($post->ID) . '";';
  // Now echo the categories of the post
  if (isset($_GET['terms'])) {
    foreach (wp_get_post_terms($post->ID, 'category') as $term) {
      echo '"' . $term->name . '";';
    }
  }
  // And print a line end for the next post
  echo PHP_EOL;
}
