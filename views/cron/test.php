<?php
require '../../../../../wp-load.php';

use LBWP\Helper\LLM\ChromaDB;
use LBWP\Helper\LLM\E5MultilangSmall;

// Check security parameter containing the first 6 chars of auth_key
if (substr($_GET['sec'],0,6) !== substr(AUTH_KEY,0,6)) {
  echo 'Not allowed';
  exit;
}

$chroma = ChromaDB::getInstance();

// Needs only to be called once
if (isset($_GET['init_tenant_database'])) {
  $chroma->createTenant();
  $chroma->createDatabase();
}

if ($chroma->isWorking()) {
  echo 'ChromaDB is working';
}

if (!isset($_GET['collection'])) {
  echo 'please set collection to work with';
}

// test_data_posts_pages
// test_data_posts_contentarena
// test_data_banholzer_products

$collection = $_GET['collection'];
$chroma->setCollectionName($collection, true);

if (isset($_GET['add_comotive'])) {
  // Get all posts from database
  $posts = get_posts(array(
    'post_type' => array('page', 'post'),
    'posts_per_page' => 100
  ));

  $embedder = new E5MultilangSmall();
  foreach ($posts as $post) {
    $text = strip_tags($post->post_title . ' ' . $post->post_excerpt);
    var_dump(microtime(true));
    $embeddings = $embedder->embed($text);
    $chroma->syncItem(array(
      'id' => $post->ID,
      'embeddings' => $embeddings,
      'meta' => array(
        'title' => $post->post_title,
        'date' => date('Y-m-d', strtotime($post->post_date))
      )
    ));
  }
}

if (isset($_GET['add_contentarena'])) {
  // Get all posts from database
  $posts = get_posts(array(
    'post_type' => array('post'),
    'posts_per_page' => 100,
    'paged' => $_GET['page'] ?? 1
  ));

  $embedder = new E5MultilangSmall();
  foreach ($posts as $post) {
    $text = strip_tags($post->post_title . ' ' . $post->post_excerpt);
    var_dump(microtime(true));
    $embeddings = $embedder->embed($text);
    $chroma->syncItem(array(
      'id' => $post->ID,
      'embeddings' => $embeddings,
      'meta' => array(
        'title' => $post->post_title,
        'date' => date('Y-m-d', strtotime($post->post_date))
      )
    ));
  }
}

if (isset($_GET['add_banholzer'])) {
  $perBatch = 500;
  $page = intval($_GET['page']);
  $start = $page > 0 ? ($page - 1) * $perBatch : $perBatch;
  // Get all posts from database
  global $wpdb;
  $raw = $wpdb->get_results('
    SELECT id, title, excerpt, meta FROM '.$wpdb->prefix.'lbwp_text_index
    WHERE post_type = "product" AND (meta LIKE "%porzellan%")
    LIMIT ' . $start . ',' . $perBatch . '
  ');

  $embedder = new E5MultilangSmall();
  var_dump(microtime(true));
  $batch = array();
  foreach ($raw as $product) {
    $text = strip_tags($product->title . ' ' . $product->excerpt . ' ' . $product->meta);
    $embeddings = $embedder->embed($text);
    $batch[] = array(
      'id' => $product->id,
      'embeddings' => $embeddings,
      'meta' => array(
        'title' => $product->title
      )
    );
  }
  $chroma->syncBatch($batch);
  var_dump(microtime(true));
}

if (isset($_GET['query'])) {
  $embedder = new E5MultilangSmall();
  echo '<br><br>Anfrage: <strong>' . $_GET['query'] . '</strong><hr>Resultate<br><br>';
  $start = microtime(true);
  $embeddings = $embedder->embed($_GET['query'], true);
  echo 'Create embedding time: ' . (microtime(true) - $start) * 1000 . ' ms<br>';

  // Use distance threshold to filter out irrelevant results
  $start = microtime(true);
  $results = $chroma->searchCollection($embeddings, $_GET['num'], true);
  echo 'Query time: ' . (microtime(true) - $start) * 1000 . ' ms<br>';

  if (empty($results['metadatas'][0])) {
    echo 'Keine relevanten Resultate gefunden (alle zu weit entfernt)<br>';
  } else {
    foreach ($results['metadatas'][0] as $index => $result) {
      $distance = $results['distances'][0][$index] ?? 'N/A';
      echo '<strong>' . $result['title'] . '</strong> (Distance: ' . number_format($distance, 3) . ')<br>';
    }
  }
}

if (isset($_GET['delete_collection'])) {
  $chroma->deleteCollection();
}