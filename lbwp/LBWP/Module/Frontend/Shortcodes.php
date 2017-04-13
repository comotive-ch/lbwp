<?php

namespace LBWP\Module\Frontend;

use LBWP\Util\Strings;
use LBWP\Util\Date;

/**
 * Various global shortcodes
 * @author Michael Sebel <michael@comotive.ch>
 */
class Shortcodes extends \LBWP\Module\Base
{

	/**
	 * call parent constructor and initialize the module
	 */
	public function __construct()
  {
		parent::__construct();
	}

	/**
	 * Registers all the actions and filters and removes some.
	 */
	public function initialize()
  {
    add_shortcode('forward', array($this, 'forwardShortcode'));
    add_shortcode('lbwp:gss_results', array('\LBWP\Theme\Feature\Search', 'printGoogleSiteSearchResults'));
    add_shortcode('lbwp:gss_api_results', array('\LBWP\Theme\Feature\Search', 'printApiSearchResults'));
	}

  /**
   * Shortcode to provide an easy forward feature: [forward url="/link/to/page" from="20.12.2013 10:00:00"]
   * The "From" is optional, shortcode can also be legacy written [forward "/link/to/page"]
   * @param array $args the arguments given to the shortcode
   */
  public function forwardShortcode($args)
  {
    // See which arguments are given (0|url, 1|from)
    if (isset($args[0])) {
      $url = $args[0];
    }
    if (isset($args['url'])) {
      $url = $args['url'];
    }
    if (isset($args[1])) {
      $fromDate = $args[1];
    }
    if (isset($args['from'])) {
      $fromDate = $args['from'];
    }

    // Declare the redirect as ready to executed
    $doRedirect = true;
    // Prove otherwise, if the "from" param is set
    if (strlen($fromDate) > 0 && Strings::checkDate($fromDate, Date::EU_FORMAT_DATETIME)) {
      $tsFromDate = Date::getStamp(Date::EU_DATETIME, $fromDate);
      $tsNow = time();
      if ($tsFromDate > $tsNow) {
        $doRedirect = false;
      }
    }

    // Check if we need to execute the shortcode
    if (strlen($url) > 0 && $doRedirect && (!is_feed()) && (is_single() || is_page()) && in_the_loop()) {
      $url = str_replace('&amp;', '&', $url);
      $url = str_replace('&#038;', '&', $url);

      header('Location: ' . $url);
      exit;
    }
  }
}