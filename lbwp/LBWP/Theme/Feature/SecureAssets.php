<?php

namespace LBWP\Theme\Feature;

use LBWP\Core as LbwpCore;
use LBWP\Module\Backend\S3Upload;
use LBWP\Util\ArrayManipulation;
use LBWP\Util\Strings;
use LBWP\Util\WordPress;

/**
 * This provides a setting for assets to be secured on the block storage
 * The files get a private ACL and can only be downloaded trough wp-file-proxy.php
 * @author Michael Sebel <michael@comotive.ch>
 * @package LBWP\Theme\Feature
 */
class SecureAssets
{
  /**
   * @var SecureAssets the instance
   */
  protected static $instance = NULL;
  /**
   * @var array configuration defaults
   */
  protected $config = array(

  );

  /**
   * Can only be called within init
   */
  protected function __construct($options)
  {
    $this->config = array_merge($this->config, $options);
  }

  /**
   * @return SecureAssets the mail service instance
   */
  public static function getInstance()
  {
    return self::$instance;
  }

  /**
   * @param array $options load the given options
   */
  public static function init($options = array())
  {
    self::$instance = new SecureAssets($options);
    self::$instance->initialize();
  }

  /**
   * Called inline on loading, registers needed hooks / js
   */
  public function initialize()
  {
    // In admin, load the specific scripts and filters
    if (is_admin()) {
      add_filter('attachment_fields_to_edit', array($this, 'addSecureAssetCheckbox'), 20, 2);
      add_filter('edit_attachment', array($this, 'changeAssetSecurityState'));
    } else {
      // If not in admin, return rewrite assets to proxy urls
      add_filter('the_content', array($this, 'rewriteProxyAttachmentUrl'));
    }
  }

  /**
   * @param array $fields the fields
   * @param \WP_Post $attachment the attachment object
   * @return array $fields
   */
  public function addSecureAssetCheckbox($fields, $attachment)
  {
    if (!Strings::contains($attachment->post_mime_type, 'image')) {
      $meta = ArrayManipulation::forceArray(wp_get_attachment_metadata($attachment->ID));
      $checked = checked(1, $meta['secured_asset'], false);
      $html = '
        <input type="checkbox" name="secured_asset" id="secured_asset" value="1"' . $checked . ' />
        <label for="secured_asset" style="height:13px;display:inline-block;">Nur für eingeloggte</label>
      ';
      // Add the item to our fields array to be displayed
      $fields['secureAsset'] = array(
        'input' => 'html',
        'label' => 'Sichtbarkeit',
        'html' => $html
      );

      // Put the new element to start
      $fields = array_reverse($fields, true);
    }

    return $fields;
  }

  /**
   * @param int $attachmentId the attachment id
   */
  public function changeAssetSecurityState($attachmentId)
  {
    if ($attachmentId > 0) {
      // Get current meta data to be update
      $meta = ArrayManipulation::forceArray(wp_get_attachment_metadata($attachmentId));
      $url = wp_get_attachment_url($attachmentId);
      /** @var S3Upload $s3 */
      $s3 = LbwpCore::getModule('S3Upload');
      // Check if we need to make the asset private or public
      if (isset($_REQUEST['secured_asset']) && $_REQUEST['secured_asset'] == 1) {
        $meta['secured_asset'] = 1;
        // Make the file private with ACL
        $s3->setAccessControl($url, S3Upload::ACL_PRIVATE);
      } else {
        $meta['secured_asset'] = 0;
        // Make the file public again with ACL
        $s3->setAccessControl($url, S3Upload::ACL_PUBLIC);
      }

      // Save the attachment meta data with the security state
      wp_update_attachment_metadata($attachmentId, $meta);
    }
  }

  /**
   * @param string $html the original content
   * @return string the changed content
   */
  public function rewriteProxyAttachmentUrl($html)
  {
    return Strings::replaceByXPath($html, '//a', function ($doc, $tag, $fragment) {
      /**
       * @var \DOMDocument $doc The document initialized with $html
       * @var \DOMNode $tag A node of the result set
       * @var \DOMDocumentFragment $fragment Empty fragment node, add content by $fragment->appendXML('something');
       */
      $href = $tag->attributes->getNamedItem('href')->nodeValue;
      $attachmentId = WordPress::getAttachmentIdFromUrl($href);
      $meta = ArrayManipulation::forceArray(wp_get_attachment_metadata($attachmentId));
      // Only rewrite, if the asset is secured
      if (isset($meta['secured_asset']) && $meta['secured_asset'] == 1) {
        $tag->setAttribute('href', $this->getProxyPath($attachmentId));
      }

      $fragment->appendXML($doc->saveXML($tag));
      return $tag;
    });
  }

  /**
   * @param int $attachmentId the attachment id
   * @return string the url to be used for downloading
   */
  public function getProxyPath($attachmentId)
  {
    $key = get_post_meta($attachmentId, '_wp_attached_file', true);
    return get_bloginfo('url') . '/wp-file-proxy.php?key=' . urlencode($key);
  }
}



