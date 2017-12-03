<?php

namespace LBWP\Module\General;

use LBWP\Util\File;
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
    $extension = substr(File::getExtension($faviconUrl), 1);

    $type = 'image/' . $extension;
    if (Strings::endsWith($faviconUrl, '.ico')) {
      $type = 'image/x-icon';
    }

    echo '
      <link rel="apple-touch-icon"  href="' . $faviconUrl . '" />
      <link rel="icon" type="' . $type . '" href="' . $faviconUrl . '" />
      <link rel="shortcut icon" href="' . $faviconUrl . '" />
      <link rel="shortcut icon" type="' . $type . '" href="' . $faviconUrl . '" />
      <meta name="msapplication-TileImage" content="' . $faviconUrl . '" />
    ';
  }
}
