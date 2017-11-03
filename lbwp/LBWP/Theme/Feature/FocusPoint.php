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
    'autoRegisterLibrary' => true,
    'functionSelector' => '.lbwp-focuspoint'
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
    // load the library if needed
    if ($this->config['autoRegisterLibrary']) {
      wp_enqueue_script('lbwp-focuspoint', File::getResourceUri() . '/js/focuspoint/jquery.focuspoint.min.js', array('jquery'), LbwpCore::REVISION, false);
      add_action('wp_head', array($this, 'printJsonConfiguration'));
    }

    // In admin, load the specific scripts and filters
    if (is_admin()) {
      add_filter('attachment_fields_to_edit', array($this, 'addAttachmentField'), 10, 2);
      add_action('admin_footer', array($this, 'printBasicModalTemplate'));
      add_action('wp_ajax_saveFocuspointMeta', array($this, 'saveFocuspoint'));
      add_action('admin_enqueue_scripts', array($this, 'enqueueBackendAssets'));
    }
  }

  /**
   * Enqueue assets, if they are needed in context
   */
  public function enqueueBackendAssets()
  {
    $screen = get_current_screen();
    // Only on upload and post detail screen
    if ($screen->base == 'upload' || $screen->base == 'post') {
      wp_enqueue_script('lbwp-focuspoint-be', File::getResourceUri() . '/js/focuspoint/focuspoint.backend.js', array('jquery'), LbwpCore::REVISION, true);
      wp_enqueue_script('jquery-ui-dialog');
      wp_enqueue_style('jquery-ui-theme-lbwp');
    }
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
   * Save the focus point to attachment meta
   */
  public function saveFocuspoint()
  {
    // Get the current meta
    $attachmentId = intval($_POST['attachmentId']);
    $meta = wp_get_attachment_metadata($attachmentId);

    // Extend/Override with focuspoint data
    $meta['focusPoint'] = array(
      'x' => (float) $_POST['focusX'],
      'y' => (float) $_POST['focusY'],
    );

    // Save back into db, also return success for the ajax caller
    wp_update_attachment_metadata($attachmentId, $meta);

    WordPress::sendJsonResponse(array(
      'success' => true
    ));
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
   * Print the json config, and do the registration inline as well.
   * We do two echoes here, so that we're easly able to move the inline script to a file in the future.
   */
  public function printJsonConfiguration()
  {
    // The configurartion
    echo '<script type="text/javascript">var focusPointConfig = ' . json_encode($this->config) . ';</script>';
    // Simple auto init script, for the moment inline
    echo '
      <script type="text/javascript">
        jQuery(function() {
          jQuery(focusPointConfig.functionSelector).focusPoint();
        });
      </script>
    ';
  }

  /**
   * Get a featured image from a post as focus point html image
   * @param int $postId the post id
   * @param string $size file size
   * @param string $containerClasses optional container class around the figure
   * @param bool $wrapOriginal wraps a link to the original file around the image
   * @param string $attr for get_attachment_image function
   * @return string html or empty string
   */
  public static function getFeaturedImage($postId, $size = 'large', $containerClasses = '', $wrapOriginal = false, $attr = '')
  {
    $attachmentId = intval(get_post_thumbnail_id($postId));
    return self::getImage($attachmentId, $size, $containerClasses, $wrapOriginal, $attr);
  }

  /**
   * Get a featured image from a post as focus point html image
   * @param int $attachmentId the attachment file id
   * @param string $size file size
   * @param string $containerClasses optional container class around the figure
   * @param bool $wrapOriginal wraps a link to the original file around the image
   * @param string $attr for get_attachment_image function
   * @return string html or empty string
   */
  public static function getImage($attachmentId, $size = 'large', $containerClasses = '', $wrapOriginal = false, $attr = '')
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

      // Wrap original image url around the block, if desired
      if ($wrapOriginal) {
        $html = '<a href="' . WordPress::getImageUrl($attachmentId, 'original') . '" class="auto-fancybox">' . $html . '</a>';
      }
    }

    return $html;
  }
}



