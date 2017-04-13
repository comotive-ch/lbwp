<?php

namespace LBWP\Theme\Feature;
use LBWP\Util\File;
use LBWP\Util\Strings;
use LBWP\Core as LbwpCore;
use LBWP\Util\WordPress;

/**
 * Provides possibility to set a focus point on feature images and
 * gives helper functions to generate html code for such images.
 * Auto registers and loads needed JS/CSS libraries.
 * @author Michael Sebel <michael@comotive.ch>
 * @package LBWP\Theme\Feature
 */
class FocusPoint
{
  /**
   * @var FocusPoint the instance
   */
  protected static $instance = NULL;
  /**
   * @var array configuration defaults
   */
  protected $config = array(
    'autoRegisterLibrary' => true
  );

  /**
   * Can only be called within init
   */
  protected function __construct($options)
  {
    $this->config = array_merge($this->config, $options);
  }

  /**
   * @return FocusPoint the mail service instance
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
    self::$instance = new FocusPoint($options);
    self::$instance->initialize();
  }

  /**
   * Called inline on loading, registers needed hooks / js
   */
  public function initialize()
  {
    $path = File::getResourceUri();
    // load the library if needed
    if ($this->config['autoRegisterLibrary']) {
      wp_enqueue_script('lbwp-focuspoint', $path . '/js/focuspoint/jquery.focuspoint.min.js', array('jquery'), LbwpCore::REVISION, true);
    }

    // In admin, load the specific scripts and filters
    /*
    if (is_admin()) {
      add_filter('attachment_fields_to_edit', array($this, 'addAttachmentField'), 10, 2);
      add_action('admin_footer', array($this, 'printBasicModalTemplate'));
      // Add a script to handle the backend
      wp_enqueue_script('lbwp-focuspoint-be', $path . '/js/focuspoint/focuspoint.backend.js', array('jquery'), LbwpCore::REVISION, true);
      wp_enqueue_script('jquery-ui-dialog');
      wp_enqueue_style('jquery-ui');
      wp_enqueue_style('jquery-ui-dialog');
    }*/
  }

  /**
   * The basic modal template that is initially invisible
   */
  public function printBasicModalTemplate()
  {
    echo '
      <div id="focuspoint-dialog" title="Fokus bearbeiten">
        <p>
          Sie können mit einem Klick auf das Bild dessen Fokus definieren. Das Bild wird auf der Website
          in Auflistungen und als automatisches Artikelbild auf diesen Punkt zentriert.
        </p>
        <div id="focuspoint-image"></div>
        <img class="focuspoint-pointer pointer-template" src="' . File::getResourceUri() . '/js/focuspoint/target.png" />
        <input type="hidden" id="focuspointAttachmentId" value="0" />
        <input type="hidden" id="focuspointX" value="0" />
        <input type="hidden" id="focuspointY" value="0" />
      </div>
    ';
  }

  /**
   * @param array $fields the fields
   * @param \WP_Post $attachment the attachment object
   * @return array $fields
   */
  public function addAttachmentField($fields, $attachment)
  {
    if (stristr($attachment->post_mime_type, 'image') !== false) {
      $meta = wp_get_attachment_metadata($attachment->ID);
      $src =  WordPress::getImageUrl($attachment->ID, 'large');
      $meta['focusPoint'] = array(
        'x' => mt_rand(-10, 10) / 10,
        'y' => mt_rand(-10, 10) / 10,
      );
      $html = '
        <a href="#" class="focus-point-frame"
          data-x="' . (float) $meta['focusPoint']['x'] . '"
          data-y="' . (float) $meta['focusPoint']['y'] . '">Fokus bearbeiten</a>
        <img src="' . $src . '" class="focuspoint-image-template" />
      ';
      // Add the item to our fields array to be displayed
      $fields['focusPoint'] = array(
        'input' => 'html',
        'label' => 'Bildfokus',
        'html' => $html
      );
    }

    return $fields;
  }

  /**
   * Get a featured image from a post as focus point html image
   * @param int $postId the post id
   * @param string $size file size
   * @param string $containerClasses optional container class around the figure
   * @param string $attr for get_attachment_image function
   * @return string html or empty string
   */
  public static function getFeaturedImage($postId, $size = 'large', $containerClasses = '', $attr = '')
  {
    $attachmentId = intval(get_post_thumbnail_id($postId));
    return self::getImage($attachmentId, $size, $containerClasses, $attr);
  }

  /**
   * Get a featured image from a post as focus point html image
   * @param int $attachmentId the attachment file id
   * @param string $size file size
   * @param string $containerClasses optional container class around the figure
   * @param string $attr for get_attachment_image function
   * @return string html or empty string
   */
  public static function getImage($attachmentId, $size = 'large', $containerClasses = '', $attr = '')
  {
    $html = '';

    // Get the image tag first, to see if there is an image
    $image = wp_get_attachment_image($attachmentId, $size, false, $attr);

    // If there is actually an image
    if (Strings::startsWith($image, '<img')) {
      $meta = wp_get_attachment_metadata($attachmentId);
      $imgsize = $meta['sizes'][$size];
      // If empty, use the main array (original image)
      if (empty($imgsize)) {
        $imgsize = $meta;
      }

      $html = '
        <div class="lbwp-focuspoint-container ' . $containerClasses . '">
          <figure class="lbwp-focuspoint" 
            data-focus-x="' . (float) $meta['focusPoint']['x'] . '" data-focus-y="' . (float) $meta['focusPoint']['y'] . '"
            data-focus-w="' . intval($imgsize['width']) . '" data-focus-h="' . intval($imgsize['height']) . '">
            ' . $image . '
          </figure>
        </div>
      ';
    }

    return $html;
  }
}



