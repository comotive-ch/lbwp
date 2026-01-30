<?php
header('Content-Type: text/plain');
define('SKIP_WP_STACK', true);
define('BENCHMARK_ELEMENTS', 5000);
define('CACHE_TEST_VALUE', '1qay2wsx3edc4rfv5tgb6zhn7ujm8ik9ol0p1qay2wsx3edc4rfv5tgb6zhn7ujm8ik9ol0p1qay2wsx3edc4rfv5tgb6zhn7ujm8ik9ol0p1qay2wsx3edc4rfv5tgb6zhn7ujm8ik9ol0p1qay2wsx3edc4rfv5tgb6zhn7ujm8ik9ol0p1qay2wsx3edc4rfv5tgb6zhn7ujm8ik9ol0p1qay2wsx3edc4rfv5tgb6zhn7ujm8ik9ol0p1qay2wsx3edc4rfv5tgb6zhn7ujm8ik9ol0p1qay2wsx3edc4rfv5tgb6zhn7ujm8ik9ol0p1qay2wsx3edc4rfv5tgb6zhn7ujm8ik9ol0p1qay2wsx3edc4rfv5tgb6zhn7ujm8ik9ol0p1qay2wsx3edc4rfv5tgb6zhn7ujm8ik9ol0p1qay2wsx3edc4rfv5tgb6zhn7ujm8ik9ol0p1qay2wsx3edc4rfv5tgb6zhn7ujm8ik9ol0p1qay2wsx3edc4rfv5tgb6zhn7ujm8ik9ol0p1qay2wsx3edc4rfv5tgb6zhn7ujm8ik9ol0p1qay2wsx3edc4rfv5tgb6zhn7ujm8ik9ol0p1qay2wsx3edc4rfv5tgb6zhn7ujm8ik9ol0p1qay2wsx3edc4rfv5tgb6zhn7ujm8ik9ol0p1qay2wsx3edc4rfv5tgb6zhn7ujm8ik9ol0p1qay2wsx3edc4rfv5tgb6zhn7ujm8ik9ol0p1qay2wsx3edc4rfv5tgb6zhn7ujm8ik9ol0p1qay2wsx3edc4rfv5tgb6zhn7ujm8ik9ol0p1qay2wsx3edc4rfv5tgb6zhn7ujm8ik9ol0p1qay2wsx3edc4rfv5tgb6zhn7ujm8ik9ol0p1qay2wsx3edc4rfv5tgb6zhn7ujm8ik9ol0p1qay2wsx3edc4rfv5tgb6zhn7ujm8ik9ol0p1qay2wsx3edc4rfv5tgb6zhn7ujm8ik9ol0p1qay2wsx3edc4rfv5tgb6zhn7ujm8ik9ol0p1qay2wsx3edc4rfv5tgb6zhn7ujm8ik9ol0p1qay2wsx3edc4rfv5tgb6zhn7ujm8ik9ol0p1qay2wsx3edc4rfv5tgb6zhn7ujm8ik9ol0p1qay2wsx3edc4rfv5tgb6zhn7ujm8ik9ol0p1qay2wsx3edc4rfv5tgb6zhn7ujm8ik9ol0p1qay2wsx3edc4rfv5tgb6zhn7ujm8ik9ol0p1qay2wsx3edc4rfv5tgb6zhn7ujm8ik9ol0p1qay2wsx3edc4rfv5tgb6zhn7ujm8ik9ol0p');

require_once '../../../../../wp-config.php';
require_once 'object-cache_replicate-lbwp.php';

global $wp_object_cache;

$wp_object_cache = new WP_Object_Cache();

echo 'Writing ' . BENCHMARK_ELEMENTS . ' elements to cache' . PHP_EOL;
$start = microtime(true);

for ($i = 0; $i < BENCHMARK_ELEMENTS; ++$i) {
  wp_cache_set('test_' . $i, CACHE_TEST_VALUE, 'default', 600);
}

echo 'Finished writing to cache' . PHP_EOL;
echo 'Result: ' . (microtime(true) - $start) . ' seconds' . PHP_EOL;


echo 'Reading 10x' . BENCHMARK_ELEMENTS . ' elements from cache' . PHP_EOL;
$start = microtime(true);

for ($j = 0; $j < 10; ++$j) {
  for ($i = 0; $i < BENCHMARK_ELEMENTS; ++$i) {
    $value = wp_cache_get('test_' . $i, 'default');
  }
}

echo 'Finished reading from cache' . PHP_EOL;
echo 'Result: ' . (microtime(true) - $start) . ' seconds' . PHP_EOL;
