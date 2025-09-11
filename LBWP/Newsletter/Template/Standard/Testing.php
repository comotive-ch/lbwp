<?php

namespace LBWP\Newsletter\Template\Standard;

use LBWP\Newsletter\Template\Base;
use LBWP\Newsletter\Template\Item;

/**
 * This class implements a simple testing template
 * @package LBWP\Newsletter\Template
 * @author Michael Sebel <michael@comotive.ch>
 */
class Testing extends Base
{
  /**
   * @var string the name of the template
   */
  protected $name = 'Testing Template';
  /**
   * @var string the screenshot
   */
  protected $screenshot = '/wp-content/plugins/lbwp/resources/newsletter/testing/screenshot.png';
  /**
   * @var array the configuration data
   */
  protected $config = array(
    'spacer' => 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7'
  );

  /**
   * @param \stdClass $newsletter the newsletter post class
   * @return string the newsletter html code
   */
  public function renderNewsletter($newsletter)
  {
    // Start the newsletter with basic html/body stuff
    $html = '
      <!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
      <html>
        <head>
          <meta name="viewport" content="width=device-width" />
          <meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
          <title>' . $this->mailSubject . '</title>
        </head>
        <body bgcolor="#EEEEEE" topmargin="0" leftmargin="0" marginheight="0" marginwidth="0" style="width: 100%!important; height: 100%;">
        <table width="700" cellspacing="0" cellpadding="0">
          <tr>
            <td><strong>{lbwp:salutation} {lbwp:firstname} {lbwp:lastname}</strong></td>
          </tr>
        </table>
        <table width="700" cellspacing="0" cellpadding="0" class="newsletter-items">
    ';

    // Output the items
    foreach ($this->getItems($newsletter) as $item) {
      $html .= $this->renderItem($item);
    }

    // Close html and return
    $html .= '
          </table>
          <table width="700" cellspacing="0" cellpadding="0">
            <tr>
              <td>Hier können Sie sich mit der Adresse {lbwp:email} vom Newsletter abmelden: {lbwp:unsubscribe}</strong></td>
            </tr>
          </table>
        </body>
      </html>
    ';

    return $html;
  }

  /**
   * @param Item $item
   * @return string html this should render a specific item
   */
  protected function renderItem(Item $item)
  {
    $html = '
      <tr>
        <td>
          <table width="100%" cellpadding="0" cellspacing="">
            <tr>
              <td width="10"><img src="' . $this->config['spacer'] . '" width="10" /></td>
              {image}
              <td>
                <strong>' . $item->getTitle() . '</strong><br />
                <br />
                ' . $item->getText() . ' &raquo;
                <a href="' . $item->getLink() . '" target="_blank">Weiterlesen</a>
              </td>
              <td width="10"><img src="' . $this->config['spacer'] . '" width="10" /></td>
            </tr>
          </table>
        </td>
      </tr>
    ';

    // Different output if there is an image or not
    if (strlen($item->getImage()) > 0) {
      $html = str_replace('{image}', '<td width="160"><img src="' . $item->getImage() . '" width="160" /></td>', $html);
    } else {
      $html = str_replace('{image}', '', $html);
    }

    return $html;
  }
} 