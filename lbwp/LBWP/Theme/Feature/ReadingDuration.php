<?php

namespace LBWP\Theme\Feature;

use LBWP\Util\ArrayManipulation;
use LBWP\Util\Number;

/**
 * Provides functions to calculation reading time of an article
 * @author Michael Sebel <michael@comotive.ch>
 * @package LBWP\Theme\Feature
 */
class ReadingDuration
{
  /**
   * @var array Since this is migrated, the config var is not used, but needed
   */
  protected $config = array();
  /**
   * @var array Contains all elements of the breadcrumb
   */
  protected $elements = array();
  /**
   * @var ReadingDuration the instance
   */
  protected static $instance = NULL;

  /**
   * Can only be called within init
   */
  protected function __construct($options)
  {
    $this->options = $options;
  }

  /**
   * @param array $options load the given options
   */
  public static function init($options = array())
  {
    $defaults = array(
      'fallback_time' => 5, // Fallback number of minutes if getMinutes fails for some reason
      'words_per_minute' => 200,
      'round_time' => true, // rounds to the next X minutes
      'round_value' => 5, // The X in above sentence
    );

    $settings = ArrayManipulation::deepMerge($defaults, $options);

    self::$instance = new ReadingDuration($settings);
  }

  /**
   * @return int the number of minutes it takes to read the current global $post
   */
  public static function getMinutes()
  {
    $minutes = self::$instance->options['fallback_time'];
    $wordsPerMinute = self::$instance->options['words_per_minute'];
    $roundTime = self::$instance->options['round_time'];
    $roundValue = self::$instance->options['round_value'];

    // Get the number of words
    global $post;
    if (isset($post->post_content)) {
      $words = count(explode(' ', strip_tags($post->post_content)));
      $minutes = round($words / $wordsPerMinute);

      // use fallback time in case of zero calculation
      if ($minutes == 0) {
        $minutes = self::$instance->options['fallback_time'];
      }

      // Always round to five minutes, if rounding is needed
      if ($roundTime && $minutes < $roundValue) {
        $minutes = $roundValue;
      }

      // Now round mathematically, if needed
      if ($roundTime) {
        $minutes = Number::roundNearest($minutes, $roundValue);
      }
    }

    return $minutes;
  }
}
