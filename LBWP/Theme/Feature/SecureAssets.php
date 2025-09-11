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
   * @var array attachment url cache
   */
  protected $attUrlCache = array();
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
      if (!defined('LOCAL_DEVELOPMENT')) {
        add_filter('the_content', array($this, 'rewriteProxyAttachmentUrl'));
      }
    }

    // If the attachment url is requested, rewrite to proxy path, but only if not in save mode
    if (!isset($_REQUEST['action']) || $_REQUEST['action'] != 'save-attachment') {
      add_filter('wp_get_attachment_url', array($this, 'rewriteAttachmentUrl'), 20, 2);
    }
  }

  /**
   * Load the attachment url cache
   */
  protected function loadAttachmentUrlCache()
  {
    if (is_array($this->attUrlCache)) {
      return; // Already loaded
    }

    $this->attUrlCache = wp_cache_get('attUrlCache', 'SecureAssets');

    if ($this->attUrlCache == false) {
      $db = WordPress::getDb();
      $sql = 'SELECT ID, guid FROM ' . $db->posts . ' WHERE post_type = "attachment" AND post_mime_type NOT LIKE "image%"';
      foreach ($db->get_results($sql) as $result) {
        $this->attUrlCache[md5($result->guid)] = $result->ID;
      }
      wp_cache_set('attUrlCache', $this->attUrlCache, 'SecureAssets', 86400);
    }
  }

  /**
   * @param string $url the current url
   * @param int $id attachment id
   * @return string $url
   */
  public function rewriteAttachmentUrl($url, $id)
  {
    if ($this->isSecureAsset($id) && $_POST['action'] != 'save-attachment-compat') {
      return self::getProxyPath($id);
    }

    return $url;
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
      $checked1 = checked(1, $meta['secured_asset'], false);
      $checked2 = checked(1, $meta['secured_only_admin'], false);
      $html = '
        <input type="checkbox" name="secured_asset" id="secured_asset" value="1"' . $checked1 . ' />
        <label for="secured_asset" style="height:13px;display:inline-block;">Nur für Eingeloggte</label><br>
        <input type="checkbox" name="secured_only_admin" id="secured_only_admin" value="1"' . $checked2 . ' />
        <label for="secured_only_admin" style="height:13px;display:inline-block;">Mit Administratoren-Rolle</label>
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
    if ($attachmentId > 0 && $_POST['action'] == 'save-attachment-compat') {
      // Get current meta data to be update
      $meta = ArrayManipulation::forceArray(wp_get_attachment_metadata($attachmentId));
      $url = wp_get_attachment_url($attachmentId);
      /** @var S3Upload $s3 */
      $s3 = LbwpCore::getModule('S3Upload');
      // See if admin flag is set (this implicitly sets secure flag)
      $meta['secured_only_admin'] = intval($_REQUEST['secured_only_admin']);
      if ($meta['secured_only_admin'] === 1) {
        wp_cache_delete('securedAdminOnly', 'SecureAssets');
        $_REQUEST['secured_asset'] = 1;
      }

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
    $this->loadAttachmentUrlCache();
    return Strings::replaceByXPath($html, '//a', function ($doc, $tag, $fragment) {
      /**
       * @var \DOMDocument $doc The document initialized with $html
       * @var \DOMNode $tag A node of the result set
       * @var \DOMDocumentFragment $fragment Empty fragment node, add content by $fragment->appendXML('something');
       */
      $href = $tag->attributes->getNamedItem('href')->nodeValue;
      $href = str_replace('test.', 'www.', $href);
      $guidHash = md5($href);
      $attachmentId = isset($this->attUrlCache[$guidHash]) ? $this->attUrlCache[$guidHash] : 0;
      // Only rewrite, if the asset is secured
      if ($this->isSecureAsset($attachmentId)) {
        $tag->setAttribute('href', self::getProxyPath($attachmentId));
      }

      $fragment->appendXML($doc->saveXML($tag));
      return $tag;
    });
  }

  /**
   * @param int $id the attachment id
   * @return bool true, if the asset is secured
   */
  public function isSecureAsset($id)
  {
    $meta = ArrayManipulation::forceArray(wp_get_attachment_metadata($id));
    return isset($meta['secured_asset']) && $meta['secured_asset'] == 1;
  }

  /**
   * @param $key
   * @return bool
   */
  public static function isAdminSecureAsset($key)
  {
    $list = wp_cache_get('securedAdminOnly', 'SecureAssets');
    if (!is_array($list)) {
      $db = WordPress::getDb();
      $attachmentIds = $db->get_col('
        SELECT post_id FROM ' . $db->postmeta . '
        WHERE meta_key = "_wp_attachment_metadata" AND meta_value LIKE "%\"secured_only_admin\";i:1;%"
      ');
      $list = $db->get_col('
        SELECT meta_value FROM ' . $db->postmeta . '
        WHERE meta_key = "_wp_attached_file"
        AND post_id IN(' . implode(',', $attachmentIds) . ')
      ');
      wp_cache_set('securedAdminOnly', $list, 'SecureAssets', 2*86400);
    }

    return in_array($key, $list);
  }

  /**
   * @param int $attachmentId the attachment id
   * @return string the url to be used for downloading
   */
  public static function getProxyPath($attachmentId)
  {
    $key = get_post_meta($attachmentId, '_wp_attached_file', true);
    return get_bloginfo('url') . '/wp-file-proxy.php?key=' . urlencode($key);
  }

  /**
   * @param $key
   * @return string
   */
  public static function getProxyPathWithKey($key)
  {
    $key = str_replace(ASSET_KEY . '/files/', '', $key);
    return get_bloginfo('url') . '/wp-file-proxy.php?key=' . urlencode($key);
  }

  /**
   * @param $key
   * @return string
   */
  public static function forceSecureKey($key)
  {
    return str_replace(ASSET_KEY . '/files/', '', $key);
  }

  /**
   * @param $url
   * @return string
   */
  public static function extractKeyFromUrl($url)
  {
    $key = substr($url, stripos($url, '?key=') + 5);
    return ASSET_KEY . '/files/' . urldecode($key);
  }
}



