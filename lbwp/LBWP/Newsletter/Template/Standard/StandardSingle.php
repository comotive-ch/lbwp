<?php

namespace LBWP\Newsletter\Template\Standard;

use LBWP\Newsletter\Template\Base;
use LBWP\Newsletter\Template\Item;

/**
 * This class implements a standard theme
 * @package LBWP\Newsletter\Template
 * @author Michael Sebel <michael@comotive.ch>
 */
class StandardSingle extends Base
{
  /**
   * @var string the name of the template
   */
  protected $name = 'Standard Einspaltig 1';
  /**
   * @var string the screenshot
   */
  protected $screenshot = '/wp-content/plugins/lbwp/resources/newsletter/standard-single/screenshot.png';
  /**
   * @var array configurable defaults
   */
  protected $optionDefaults = array(
    // These are generated from others and not changeable by the user
    'buttonBorder' => '1px solid {option:buttonColorHover}',
    // The following are overridable by the StandardSettings component
    'logoWidth' => '180',
    'logoHeight' => '50',
    'logoUrl' => 'http://placekitten.com/180/50',
    'salutation' => 'Guten Tag {lbwp:firstname} {lbwp:lastname}',
    'bodyColor' => '#8FC657',
    'bodyDarkColor' => '#597A35',
    'buttonColor' => '#85B54F',
    'buttonColorHover' => '#7C9C57',
    'buttonText' => 'Weiterlesen',
    'buttonTextColor' => '#FFFFFF',
    'fontColor' => '#222222',
    'linkColor' => '#375813',
    'headerColor' => '#222222',
    'innerBackground' => '#FFFFFF',
    // The following are not overrideable by the user (yet)
    'fontFace' => "'Helvetica', 'Arial', sans-serif",
    'imageWidth' => '180',
    'imageHeight' => '180',
  );

  /**
   * @param \stdClass $newsletter the newsletter post class
   * @return string the newsletter html code
   */
  public function renderNewsletter($newsletter)
  {
    $html = '';
    
    // Starting with the head
    $html .= $this->getHeaderPart($newsletter);

    // Output the items
    foreach ($this->getItems($newsletter) as $item) {
      $html .= $this->renderItem($item);
    }

    // And end with the footer
    $html .= $this->getFooterPart($newsletter);

    // Replace configurations
    $html = $this->replaceOptions($html);

    return $html;
  }

  /**
   * @param string $html the newsletter html
   * @return string the html filled with options
   */
  protected function replaceOptions($html)
  {
    foreach ($this->optionDefaults as $key => $default) {
      // Get it from the option, if possible
      $value = get_option('standardNewsletter_' . $key);
      if (strlen($value) == 0) {
        $value = $default;
      }

      // Find the option and replace it
      $html = str_replace('{option:' . $key . '}', $value, $html);
    }

    return $html;
  }

  /**
   * @param Item $item
   * @return string html this should render a specific item
   */
  protected function renderItem(Item $item)
  {
    // Fix the item image size
    $item->changeAttachmentSize('standard-nl-thumb');

    // Make the link, if possible
    $link = '';
    if (strlen($item->getLink()) > 0) {
      $link = '
        <table class="button" style="border-spacing: 0; border-collapse: collapse; vertical-align: top; text-align: left; width: 100%; overflow: hidden; padding: 0;">
          <tr style="vertical-align: top; text-align: left; padding: 0;" align="left">
            <td style="word-break: break-word; -webkit-hyphens: auto; -moz-hyphens: auto; hyphens: auto; border-collapse: collapse !important; vertical-align: top; text-align: center; color: {option:buttonTextColor}; font-family: {option:fontFace}; font-weight: normal; line-height: 22px; font-size: 17px; display: block; width: auto !important; background: {option:buttonColor}; margin: 0; padding: 8px 0; border: {option:buttonBorder};" align="center" bgcolor="{option:buttonColor}" valign="top">
              <a href="' . $item->getLink() . '" style="color: {option:buttonTextColor}; text-decoration: none; font-weight: bold; font-family: Helvetica, Arial, sans-serif; font-size: 18px;">
                <font color="{option:buttonTextColor}">{option:buttonText}</font>
              </a>
            </td>
          </tr>
        </table>
      ';
    }

    // Show title, if set
    $title = '';
    if (strlen($item->getTitle()) > 0) {
      $title = '
        <p class="lead" style="color: {option:fontColor}; font-family: {option:fontFace}; font-weight: normal; text-align: left; line-height: 23px; font-size: 20px; margin: 0 0 10px; padding: 0;" align="left">
          ' . $item->getTitle() . '
        </p>
      ';
    }

    return '
      <table class="twelve columns" style="border-spacing: 0; border-collapse: collapse; vertical-align: top; text-align: left; width: 580px; margin: 0 auto; padding: 0;">
        <tr style="vertical-align: top; text-align: left; padding: 0;" align="left">
          <td class="wrapper" style="word-break: break-word; -webkit-hyphens: auto; -moz-hyphens: auto; hyphens: auto; border-collapse: collapse !important; vertical-align: top; text-align: left; position: relative; color: {option:fontColor}; font-family: {option:fontFace}; font-weight: normal; line-height: 19px; font-size: 14px; margin: 0; padding: 0px 0px 10px;" align="left" valign="top">

            <table class="four columns" style="border-spacing: 0; border-collapse: collapse; vertical-align: top; text-align: left; width: 180px; margin: 0 auto; padding: 0;">
              <tr style="vertical-align: top; text-align: left; padding: 0;" align="left">
                <td style="word-break: break-word; -webkit-hyphens: auto; -moz-hyphens: auto; hyphens: auto; border-collapse: collapse !important; vertical-align: top; text-align: left; color: {option:fontColor}; font-family: {option:fontFace}; font-weight: normal; line-height: 19px; font-size: 14px; margin: 0; padding: 0px 0px 10px;" align="left" valign="top">
                  <a href="#" style="color: {option:buttonColorHover}; text-decoration: none;">
                    <img src="' . $item->getImage() . '" width="{option:imageWidth}" height="{option:imageHeight}" style="outline: none; text-decoration: none; -ms-interpolation-mode: bicubic; width: auto; max-width: 100%; float: left; clear: both; display: block; border: none;" align="left" />
                  </a>
                </td>
                <td class="expander" style="word-break: break-word; -webkit-hyphens: auto; -moz-hyphens: auto; hyphens: auto; border-collapse: collapse !important; vertical-align: top; text-align: left; visibility: hidden; width: 0px; color: {option:fontColor}; font-family: {option:fontFace}; font-weight: normal; line-height: 19px; font-size: 14px; margin: 0; padding: 0;" align="left" valign="top"></td>
              </tr>
            </table>
          </td>
          <td class="wrapper" style="word-break: break-word; -webkit-hyphens: auto; -moz-hyphens: auto; hyphens: auto; border-collapse: collapse !important; vertical-align: top; text-align: left; position: relative; color: {option:fontColor}; font-family: {option:fontFace}; font-weight: normal; line-height: 19px; font-size: 14px; margin: 0; padding: 0px 0px 10px;" align="left" valign="top">

            <table class="eight columns" style="border-spacing: 0; border-collapse: collapse; vertical-align: top; text-align: left; width: 380px; margin: 0 auto; padding: 0;">
              <tr style="vertical-align: top; text-align: left; padding: 0;" align="left">
                <td style="word-break: break-word; -webkit-hyphens: auto; -moz-hyphens: auto; hyphens: auto; border-collapse: collapse !important; vertical-align: top; text-align: left; color: {option:fontColor}; font-family: {option:fontFace}; font-weight: normal; line-height: 19px; font-size: 14px; margin: 0; padding: 0px 0px 10px;" align="left" valign="top">
                  ' . $title . '
                  <p style="color: {option:fontColor}; font-family: {option:fontFace}; font-weight: normal; text-align: left; line-height: 22px; font-size: 17px; margin: 0 0 10px; padding: 0;" align="left">
                    ' . $item->getText() . '
                  </p>
                  ' . $link . '
                </td>
                <td class="expander" style="word-break: break-word; -webkit-hyphens: auto; -moz-hyphens: auto; hyphens: auto; border-collapse: collapse !important; vertical-align: top; text-align: left; visibility: hidden; width: 0px; color: {option:fontColor}; font-family: {option:fontFace}; font-weight: normal; line-height: 19px; font-size: 14px; margin: 0; padding: 0;" align="left" valign="top"></td>
              </tr>
            </table>
          </td>
        </tr>
      </table>
      <br />
    ';
  }

  /**
   * @param \stdClass $newsletter the newsletter
   * @return string html code representing the header part
   */
  protected function getHeaderPart($newsletter)
  {
    return '
      <!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Strict//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd">
      <html xmlns="http://www.w3.org/1999/xhtml" xmlns="http://www.w3.org/1999/xhtml">
        <head>
          <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
          <meta name="viewport" content="width=device-width" />
        </head>
        <body style="width: 100% !important; min-width: 100%; -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%; color: {option:fontColor}; font-family: {option:fontFace}; font-weight: normal; text-align: left; line-height: 19px; font-size: 14px; background: {option:bodyColor}; margin: 0; padding: 0;" bgcolor="{option:bodyColor}">
        ' . $this->getCss() . '
        <table class="body" style="border-spacing: 0; border-collapse: collapse; vertical-align: top; text-align: left; height: 100%; width: 100%; color: {option:fontColor}; font-family: {option:fontFace}; font-weight: normal; line-height: 19px; font-size: 14px; margin: 0; padding: 0;"><tr style="vertical-align: top; text-align: left; padding: 0;" align="left"><td class="center" align="center" valign="top" style="word-break: break-word; -webkit-hyphens: auto; -moz-hyphens: auto; hyphens: auto; border-collapse: collapse !important; vertical-align: top; text-align: center; color: {option:fontColor}; font-family: {option:fontFace}; font-weight: normal; line-height: 19px; font-size: 14px; margin: 0; padding: 0;">
          <center style="width: 100%; min-width: 580px;">
      
            <table class="row header" style="border-spacing: 0; border-collapse: collapse; vertical-align: top; text-align: left; width: 100%; position: relative; background: {option:bodyDarkColor}; padding: 0px;" bgcolor="{option:bodyDarkColor}">
              <tr style="vertical-align: top; text-align: left; padding: 0;" align="left">
                <td class="center" align="center" style="word-break: break-word; -webkit-hyphens: auto; -moz-hyphens: auto; hyphens: auto; border-collapse: collapse !important; vertical-align: top; text-align: center; color: {option:fontColor}; font-family: {option:fontFace}; font-weight: normal; line-height: 19px; font-size: 14px; margin: 0; padding: 0;" valign="top">
                  <center style="width: 100%; min-width: 580px;">

                    <table class="container" style="border-spacing: 0; border-collapse: collapse; vertical-align: top; text-align: inherit; width: 580px; background: {option:innerBackground}; margin: 0 auto; padding: 0;" bgcolor="{option:innerBackground}">
                      <tr style="vertical-align: top; text-align: left; padding: 0;" align="left">
                        <td class="wrapper" style="word-break: break-word; -webkit-hyphens: auto; -moz-hyphens: auto; hyphens: auto; border-collapse: collapse !important; vertical-align: top; text-align: left; position: relative; color: {option:fontColor}; font-family: {option:fontFace}; font-weight: normal; line-height: 19px; font-size: 14px; margin: 0; padding: 10px 20px 0px;" align="left" valign="top">

                          <table class="twelve columns" style="border-spacing: 0; border-collapse: collapse; vertical-align: top; text-align: left; width: 580px; margin: 0 auto; padding: 0;">
                            <tr style="vertical-align: top; text-align: left; padding: 0;" align="left">
                              <td class="eight sub-columns" style="vertical-align: middle; word-break: break-word; -webkit-hyphens: auto; -moz-hyphens: auto; hyphens: auto; border-collapse: collapse !important; text-align: left; min-width: 0px; width: 66.666666%; color: {option:fontColor}; font-family: {option:fontFace}; font-weight: normal; line-height: 19px; font-size: 14px; margin: 0; padding: 0px 10px 10px 0px;" align="left" valign="middle">
                                <span class="template-label" style="color: {option:headerColor}; font-weight: bold; font-size: 13px;">' . $newsletter->mailSubject . '</span>
                              </td>
                              <td class="four sub-columns" style="word-break: break-word; -webkit-hyphens: auto; -moz-hyphens: auto; hyphens: auto; border-collapse: collapse !important; vertical-align: top; text-align: left; min-width: 0px; width: 33.333333%; color: {option:fontColor}; font-family: {option:fontFace}; font-weight: normal; line-height: 19px; font-size: 14px; margin: 0; padding: 0px 10px 10px 0px;" align="left" valign="top">
                                <img src="{option:logoUrl}" width="{option:logoWidth}" height="{option:logoHeight}" style="outline: none; text-decoration: none; -ms-interpolation-mode: bicubic; width: auto; max-width: 100%; float: left; clear: both; display: block;" align="left" /></td>
                              <td class="expander" style="word-break: break-word; -webkit-hyphens: auto; -moz-hyphens: auto; hyphens: auto; border-collapse: collapse !important; vertical-align: top; text-align: left; visibility: hidden; width: 0px; color: {option:fontColor}; font-family: {option:fontFace}; font-weight: normal; line-height: 19px; font-size: 14px; margin: 0; padding: 0;" align="left" valign="top"></td>
                            </tr>
                          </table>

                        </td>
                      </tr>
                    </table>

                  </center>
                </td>
              </tr>
            </table>

            <br />

            <table class="row" style="border-spacing: 0; border-collapse: collapse; vertical-align: top; text-align: left; width: 100%; position: relative; padding: 0px;">
              <tr style="vertical-align: top; text-align: left; padding: 0;" align="left">
                <td class="center" align="center" style="word-break: break-word; -webkit-hyphens: auto; -moz-hyphens: auto; hyphens: auto; border-collapse: collapse !important; vertical-align: top; text-align: center; color: {option:fontColor}; font-family: {option:fontFace}; font-weight: normal; line-height: 19px; font-size: 14px; margin: 0; padding: 0;" valign="top">
                  <center style="width: 100%; min-width: 580px;">

                    <table class="container" style="border-spacing: 0; border-collapse: collapse; vertical-align: top; text-align: inherit; width: 580px; background: {option:innerBackground}; margin: 0 auto; padding: 0;" bgcolor="{option:innerBackground}">
                      <tr style="vertical-align: top; text-align: left; padding: 0;" align="left">
                        <td class="wrapper" style="word-break: break-word; -webkit-hyphens: auto; -moz-hyphens: auto; hyphens: auto; border-collapse: collapse !important; vertical-align: top; text-align: left; position: relative; color: {option:fontColor}; font-family: {option:fontFace}; font-weight: normal; line-height: 19px; font-size: 14px; margin: 0; padding: 10px 20px 0px;" align="left" valign="top">

                          <table class="twelve columns" style="border-spacing: 0; border-collapse: collapse; vertical-align: top; text-align: left; width: 580px; margin: 0 auto; padding: 0;">
                            <tr style="vertical-align: top; text-align: left; padding: 0;" align="left">
                              <td style="word-break: break-word; -webkit-hyphens: auto; -moz-hyphens: auto; hyphens: auto; border-collapse: collapse !important; vertical-align: top; text-align: left; color: {option:fontColor}; font-family: {option:fontFace}; font-weight: normal; line-height: 19px; font-size: 14px; margin: 0; padding: 0px 0px 10px;" align="left" valign="top">
                                <h6 style="color: {option:fontColor}; font-family: {option:fontFace}; font-weight: normal; text-align: left; line-height: 1.3; word-break: normal; font-size: 20px; margin: 0; padding: 0;" align="left">{option:salutation}</h6>
                              </td>
                              <td class="expander" style="word-break: break-word; -webkit-hyphens: auto; -moz-hyphens: auto; hyphens: auto; border-collapse: collapse !important; vertical-align: top; text-align: left; visibility: hidden; width: 0px; color: {option:fontColor}; font-family: {option:fontFace}; font-weight: normal; line-height: 19px; font-size: 14px; margin: 0; padding: 0;" align="left" valign="top"></td>
                            </tr>
                          </table>

                        </td>
                      </tr>
                    </table>

                  </center>
                </td>
              </tr>
            </table>

            <table class="row" style="border-spacing: 0; border-collapse: collapse; vertical-align: top; text-align: left; width: 100%; position: relative; padding: 0px;">
              <tr style="vertical-align: top; text-align: left; padding: 0;" align="left">
                <td class="center" align="center" style="word-break: break-word; -webkit-hyphens: auto; -moz-hyphens: auto; hyphens: auto; border-collapse: collapse !important; vertical-align: top; text-align: center; color: {option:fontColor}; font-family: {option:fontFace}; font-weight: normal; line-height: 19px; font-size: 14px; margin: 0; padding: 0;" valign="top">
                  <center style="width: 100%; min-width: 580px;">
                    <table class="container" style="border-spacing: 0; border-collapse: collapse; vertical-align: top; text-align: inherit; width: 580px; background: {option:innerBackground}; margin: 0 auto; padding: 0;" bgcolor="{option:innerBackground}">
                      <tr style="vertical-align: top; text-align: left; padding: 0;" align="left">
                        <td class="wrapper" style="word-break: break-word; -webkit-hyphens: auto; -moz-hyphens: auto; hyphens: auto; border-collapse: collapse !important; vertical-align: top; text-align: left; position: relative; color: {option:fontColor}; font-family: {option:fontFace}; font-weight: normal; line-height: 19px; font-size: 14px; margin: 0; padding: 10px 20px 0px;" align="left" valign="top">
    ';
  }

  /**
   * @param \stdClass $newsletter the newsletter
   * @return string html code representing the footer part
   */
  protected function getFooterPart($newsletter)
  {
    return '
                          </td>
                        </tr>
                      </table>
                    </center>
                  </td>
                </tr>
              </table>

              <table class="container" style="border-spacing: 0; border-collapse: collapse; vertical-align: top; text-align: inherit; width: 580px; background: {option:innerBackground}; margin: 0 auto; padding: 0;" bgcolor="{option:innerBackground}">
                <tr style="vertical-align: top; text-align: left; padding: 0;" align="left">
                  <td style="word-break: break-word; -webkit-hyphens: auto; -moz-hyphens: auto; hyphens: auto; border-collapse: collapse !important; vertical-align: top; text-align: left; color: {option:fontColor}; font-family: {option:fontFace}; font-weight: normal; line-height: 19px; font-size: 14px; margin: 0; padding: 0;" align="left" valign="top">
                    <br />

                    <table class="row" style="border-spacing: 0; border-collapse: collapse; vertical-align: top; text-align: left; width: 100%; position: relative; display: block; padding: 0px;">
                      <tr style="vertical-align: top; text-align: left; padding: 0;" align="left">
                        <td class="wrapper" style="word-break: break-word; -webkit-hyphens: auto; -moz-hyphens: auto; hyphens: auto; border-collapse: collapse !important; vertical-align: top; text-align: left; position: relative; color: {option:fontColor}; font-family: {option:fontFace}; font-weight: normal; line-height: 19px; font-size: 14px; margin: 0; padding: 10px 20px 0px;" align="left" valign="top">

                            <table class="twelve columns" style="border-spacing: 0; border-collapse: collapse; vertical-align: top; text-align: left; width: 580px; margin: 0 auto; padding: 0;">
                              <tr style="vertical-align: top; text-align: left; padding: 0;" align="left">
                                <td align="center" style="word-break: break-word; -webkit-hyphens: auto; -moz-hyphens: auto; hyphens: auto; border-collapse: collapse !important; vertical-align: top; text-align: left; color: {option:fontColor}; font-family: {option:fontFace}; font-weight: normal; line-height: 19px; font-size: 14px; margin: 0; padding: 0px 0px 10px;" valign="top">
                                  <center style="width: 100%; min-width: 580px;">
                                    <p style="text-align: center; color: {option:fontColor}; font-family: {option:fontFace}; font-weight: normal; line-height: 22px; font-size: 17px; margin: 0 0 10px; padding: 0;" align="center">
                                      Sie erhalten diesen Newsletter auf die Adresse {lbwp:email}.
                                      <br />Sie können sich hier {lbwp:unsubscribe}.
                                    </p>
                                  </center>
                                </td>
                                <td class="expander" style="word-break: break-word; -webkit-hyphens: auto; -moz-hyphens: auto; hyphens: auto; border-collapse: collapse !important; vertical-align: top; text-align: left; visibility: hidden; width: 0px; color: {option:fontColor}; font-family: {option:fontFace}; font-weight: normal; line-height: 19px; font-size: 14px; margin: 0; padding: 0;" align="left" valign="top"></td>
                              </tr>
                            </table>

                          </td>
                        </tr>
                      </table>

                    </td>
                  </tr>
                </table>
              </center>
            </td>
          </tr>
		    </table>
		  </body>
    </html>' . '
    ';
  }

  /**
   * @return string html code representing the css part
   */
  protected function getCss()
  {
    return '
      <style type="text/css">
        a {
        color: {option:linkColor} !important;
        }
        a:hover {
        color: {option:linkColor} !important;
        }
        a:active {
        color: {option:linkColor} !important;
        }
        a:visited {
        color: {option:linkColor} !important;
        }
        h1 a:active {
        color: {option:fontColor} !important;
        }
        h2 a:active {
        color: {option:fontColor} !important;
        }
        h3 a:active {
        color: {option:fontColor} !important;
        }
        h4 a:active {
        color: {option:fontColor} !important;
        }
        h5 a:active {
        color: {option:fontColor} !important;
        }
        h6 a:active {
        color: {option:fontColor} !important;
        }
        h1 a:visited {
        color: {option:fontColor} !important;
        }
        h2 a:visited {
        color: {option:fontColor} !important;
        }
        h3 a:visited {
        color: {option:fontColor} !important;
        }
        h4 a:visited {
        color: {option:fontColor} !important;
        }
        h5 a:visited {
        color: {option:fontColor} !important;
        }
        h6 a:visited {
        color: {option:fontColor} !important;
        }
        table.button:hover td {
        background: {option:buttonColorHover} !important;
        }
        table.button:visited td {
        background: {option:buttonColorHover} !important;
        }
        table.button:active td {
        background: {option:buttonColorHover} !important;
        }
        table.button:hover td a {
        color: {option:buttonTextColor} !important;
        }
        table.button:visited td a {
        color: {option:buttonTextColor} !important;
        }
        table.button:active td a {
        color: {option:buttonTextColor} !important;
        }
        table.button:hover td {
        background: {option:buttonColorHover} !important;
        }
        table.tiny-button:hover td {
        background: {option:buttonColorHover} !important;
        }
        table.small-button:hover td {
        background: {option:buttonColorHover} !important;
        }
        table.medium-button:hover td {
        background: {option:buttonColorHover} !important;
        }
        table.large-button:hover td {
        background: {option:buttonColorHover} !important;
        }
        table.button:hover td a {
        color: {option:buttonTextColor} !important;
        }
        table.button:active td a {
        color: {option:buttonTextColor} !important;
        }
        table.button td a:visited {
        color: {option:buttonTextColor} !important;
        }
        table.tiny-button:hover td a {
        color: {option:buttonTextColor} !important;
        }
        table.tiny-button:active td a {
        color: {option:buttonTextColor} !important;
        }
        table.tiny-button td a:visited {
        color: {option:buttonTextColor} !important;
        }
        table.small-button:hover td a {
        color: {option:buttonTextColor} !important;
        }
        table.small-button:active td a {
        color: {option:buttonTextColor} !important;
        }
        table.small-button td a:visited {
        color: {option:buttonTextColor} !important;
        }
        table.medium-button:hover td a {
        color: {option:buttonTextColor} !important;
        }
        table.medium-button:active td a {
        color: {option:buttonTextColor} !important;
        }
        table.medium-button td a:visited {
        color: {option:buttonTextColor} !important;
        }
        table.large-button:hover td a {
        color: {option:buttonTextColor} !important;
        }
        table.large-button:active td a {
        color: {option:buttonTextColor} !important;
        }
        table.large-button td a:visited {
        color: {option:buttonTextColor} !important;
        }
        table.secondary:hover td {
        background: {option:fontcolor} !important; color: {option:linkColor};
        }
        table.secondary:hover td a {
        color: {option:linkColor} !important;
        }
        table.secondary td a:visited {
        color: {option:linkColor} !important;
        }
        table.secondary:active td a {
        color: {option:linkColor} !important;
        }
        @media only screen and (max-width: 600px) {
          table[class="body"] img {
            width: auto !important; height: auto !important;
          }
          table[class="body"] center {
            min-width: 0 !important;
          }
          table[class="body"] .container {
            width: 95% !important;
          }
          table[class="body"] .row {
            width: 100% !important; display: block !important;
          }
          table[class="body"] .wrapper {
            display: block !important; padding-right: 0 !important;
          }
          table[class="body"] .columns {
            table-layout: fixed !important; float: none !important; width: 95% !important; padding-right: 0px !important; padding-left: 0px !important; display: block !important;
          }
          table[class="body"] .column {
            table-layout: fixed !important; float: none !important; width: 95% !important; padding-right: 0px !important; padding-left: 0px !important; display: block !important;
          }
          table[class="body"] .wrapper.first .columns {
            display: table !important;
          }
          table[class="body"] .wrapper.first .column {
            display: table !important;
          }
          table[class="body"] table.columns td {
            width: 100% !important;
          }
          table[class="body"] table.column td {
            width: 100% !important;
          }
          table[class="body"] .columns td.one {
            width: 8.333333% !important;
          }
          table[class="body"] .column td.one {
            width: 8.333333% !important;
          }
          table[class="body"] .columns td.two {
            width: 16.666666% !important;
          }
          table[class="body"] .column td.two {
            width: 16.666666% !important;
          }
          table[class="body"] .columns td.three {
            width: 25% !important;
          }
          table[class="body"] .column td.three {
            width: 25% !important;
          }
          table[class="body"] .columns td.four {
            width: 33.333333% !important;
          }
          table[class="body"] .column td.four {
            width: 33.333333% !important;
          }
          table[class="body"] .columns td.five {
            width: 41.666666% !important;
          }
          table[class="body"] .column td.five {
            width: 41.666666% !important;
          }
          table[class="body"] .columns td.six {
            width: 50% !important;
          }
          table[class="body"] .column td.six {
            width: 50% !important;
          }
          table[class="body"] .columns td.seven {
            width: 58.333333% !important;
          }
          table[class="body"] .column td.seven {
            width: 58.333333% !important;
          }
          table[class="body"] .columns td.eight {
            width: 66.666666% !important;
          }
          table[class="body"] .column td.eight {
            width: 66.666666% !important;
          }
          table[class="body"] .columns td.nine {
            width: 75% !important;
          }
          table[class="body"] .column td.nine {
            width: 75% !important;
          }
          table[class="body"] .columns td.ten {
            width: 83.333333% !important;
          }
          table[class="body"] .column td.ten {
            width: 83.333333% !important;
          }
          table[class="body"] .columns td.eleven {
            width: 91.666666% !important;
          }
          table[class="body"] .column td.eleven {
            width: 91.666666% !important;
          }
          table[class="body"] .columns td.twelve {
            width: 100% !important;
          }
          table[class="body"] .column td.twelve {
            width: 100% !important;
          }
          table[class="body"] td.offset-by-one {
            padding-left: 0 !important;
          }
          table[class="body"] td.offset-by-two {
            padding-left: 0 !important;
          }
          table[class="body"] td.offset-by-three {
            padding-left: 0 !important;
          }
          table[class="body"] td.offset-by-four {
            padding-left: 0 !important;
          }
          table[class="body"] td.offset-by-five {
            padding-left: 0 !important;
          }
          table[class="body"] td.offset-by-six {
            padding-left: 0 !important;
          }
          table[class="body"] td.offset-by-seven {
            padding-left: 0 !important;
          }
          table[class="body"] td.offset-by-eight {
            padding-left: 0 !important;
          }
          table[class="body"] td.offset-by-nine {
            padding-left: 0 !important;
          }
          table[class="body"] td.offset-by-ten {
            padding-left: 0 !important;
          }
          table[class="body"] td.offset-by-eleven {
            padding-left: 0 !important;
          }
          table[class="body"] table.columns td.expander {
            width: 1px !important;
          }
          table[class="body"] .right-text-pad {
            padding-left: 10px !important;
          }
          table[class="body"] .text-pad-right {
            padding-left: 10px !important;
          }
          table[class="body"] .left-text-pad {
            padding-right: 10px !important;
          }
          table[class="body"] .text-pad-left {
            padding-right: 10px !important;
          }
          table[class="body"] .hide-for-small {
            display: none !important;
          }
          table[class="body"] .show-for-desktop {
            display: none !important;
          }
          table[class="body"] .show-for-small {
            display: inherit !important;
          }
          table[class="body"] .hide-for-desktop {
            display: inherit !important;
          }
          table[class="body"] .right-text-pad {
            padding-left: 10px !important;
          }
          table[class="body"] .left-text-pad {
            padding-right: 10px !important;
          }
        }
      </style>' . '
    ';
  }

  /**
   * @return array the option defaults
   */
  public function getOptionDefaults()
  {
    return $this->optionDefaults;
  }

  /**
   * @return array the option defaults statically accessed
   */
  public static function getDefaults()
  {
    $template = new StandardSingle();
    return $template->getOptionDefaults();
  }
}