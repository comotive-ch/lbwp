<?php

namespace LBWP\Module\General;

use LBWP\Util\Strings;

/**
 * This module provides favicon upload and frontend output
 * @package LBWP\Module\General
 * @author Michael Sebel <michael@comotive.ch>
 */
class Favicon extends \LBWP\Module\Base
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
    // Register the output method, if the favicon url is given
    if (Strings::checkURL($this->config['HeaderFooterFilter:FaviconPngUrl'])) {
      if (is_admin()) {
        add_action('admin_head', array($this, 'printFavicons'));
      } else {
        add_action('wp_head', array($this, 'printFavicons'));
      }
    }
  }

  /**
   * Print all the favicons, if given
   */
  public function printFavicons()
  {
    // Get icon and quit function, if no favicon is present
    $faviconUrl = $this->config['HeaderFooterFilter:FaviconPngUrl'];

    $type = 'image/png';
    if (Strings::endsWith($faviconUrl, '.ico')) {
      $type = 'image/x-icon';
    }

    echo '
      <link rel="apple-touch-icon" sizes="57x57" href="' . $faviconUrl . '" />
      <link rel="apple-touch-icon" sizes="60x60" href="' . $faviconUrl . '" />
      <link rel="apple-touch-icon" sizes="72x72" href="' . $faviconUrl . '" />
      <link rel="apple-touch-icon" sizes="76x76" href="' . $faviconUrl . '" />
      <link rel="apple-touch-icon" sizes="114x114" href="' . $faviconUrl . '" />
      <link rel="apple-touch-icon" sizes="120x120" href="' . $faviconUrl . '" />
      <link rel="apple-touch-icon" sizes="144x144" href="' . $faviconUrl . '" />
      <link rel="apple-touch-icon" sizes="152x152" href="' . $faviconUrl . '" />
      <link rel="shortcut icon" href="' . $faviconUrl . '" />
      <link rel="shortcut icon" type="' . $type . '" href="' . $faviconUrl . '" />
      <link rel="icon" sizes="32x32" type="' . $type . '" href="' . $faviconUrl . '" />
      <link rel="icon" sizes="194x194" type="' . $type . '" href="' . $faviconUrl . '" />
      <link rel="icon" sizes="96x96" type="' . $type . '" href="' . $faviconUrl . '" />
      <link rel="icon" sizes="192x192" type="' . $type . '" href="' . $faviconUrl . '" />
      <link rel="icon" sizes="16x16" type="' . $type . '" href="' . $faviconUrl . '" />
      <meta name="msapplication-TileImage" content="' . $faviconUrl . '" />
    ';
  }
}
