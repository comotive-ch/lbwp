<?php

namespace LBWP\Theme\Feature;

use LBWP\Util\ArrayManipulation;
use LBWP\Util\File;
use LBWP\Util\Multilang;
use LBWP\Util\Strings;
use LBWP\Core as LbwpCore;
use LBWP\Util\Templating;
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
   * @var bool
   */
  protected static $initialized = false;
  /**
   * @var array configuration defaults
   */
  protected $config = array(
    'functionSelector' => '.lbwp-focuspoint',
    'autoRegisterLibrary' => true,
    'overrideWpGalleries' => false,
    'overrideWpImageBlock' => true,
    'overrideWpGalleryBlock' => true,
    'gallerySettings' => array(
      'imageSize' => 'large',
      'linkTo' => false,
      'imageCrop' => true,
      'columns' => 3,
      'printCaptions' => false,
      'dataAttributes' => array(),
      'container' => '
        <div class="gallery gallery-focuspoint gallery-size-{imageSize} {classes}">
          {content}
        </div>
        {blockCaption}
      ',
      'element' => '
        <figure class="gallery-item"{attr}>
          {image}
          <figcaption>{caption}</figcaption>
        </figure>
      '
    )
  );

  /**
   * Can only be called within init
   */
  protected function __construct($options)
  {
    $this->config = ArrayManipulation::deepMerge($this->config, $options);
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
    // Only run and register filters once
    if (self::$initialized) {
      return;
    }

    // Set as initialized
    self::$initialized = true;
    // load the library if needed
    if ($this->config['autoRegisterLibrary']) {
      wp_enqueue_script('lbwp-focuspoint', File::getResourceUri() . '/js/focuspoint/jquery.focuspoint.min.js', array('jquery'), LbwpCore::REVISION, false);
      add_action('wp_head', array($this, 'printJsonConfiguration'));
    }
    if ($this->config['overrideWpGalleries']) {
      add_filter('post_gallery', array($this, 'getFocusPointGallery'), 10, 2);
    }

    // In admin, load the specific scripts and filters
    if (is_admin()) {
      add_filter('attachment_fields_to_edit', array($this, 'addAttachmentField'), 10, 2);
      add_action('admin_footer', array($this, 'printBasicModalTemplate'));
      add_action('customize_controls_print_footer_scripts', array($this, 'printBasicModalTemplate'));
      add_action('wp_ajax_saveFocuspointMeta', array($this, 'saveFocuspoint'));
      add_action('admin_enqueue_scripts', array($this, 'enqueueBackendAssets'));
    } else {
      if ($this->config['overrideWpImageBlock']) {
        add_filter('render_block_core_image', array($this, 'overrideImageBlock'), 10, 2);
      }
      if ($this->config['overrideWpGalleryBlock']) {
        add_filter('render_block_core_gallery', array($this, 'overrideGalleryBlock'), 10, 2);
      }
    }
  }

  /**
   * Enqueue assets, if they are needed in context
   */
  public function enqueueBackendAssets()
  {
    $screen = get_current_screen();
    // Only on upload and post detail screen
    if ($screen->base == 'upload' || $screen->base == 'customize' || $screen->base == 'post' || $screen->base == 'term') {
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
      'x' => (float)$_POST['focusX'],
      'y' => (float)$_POST['focusY'],
    );

    // Save back into db, also return success for the ajax caller
    wp_update_attachment_metadata($attachmentId, $meta);

    WordPress::sendJsonResponse(array(
      'attachment' => $attachmentId,
      'meta' => $meta,
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
      $src = WordPress::getImageUrl($attachment->ID, 'large');
      $html = '
        <a href="#" class="focus-point-frame"
          data-x="' . (float)$meta['focusPoint']['x'] . '"
          data-y="' . (float)$meta['focusPoint']['y'] . '">Fokus bearbeiten</a>
        <img src="' . $src . '" class="focuspoint-image-template" data-id="' . $attachment->ID . '" />
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
    // Simple auto init script, for the moment inline
    echo '
      <script type="text/javascript">
        var focusPointConfig = ' . json_encode($this->config) . ';
        jQuery(function() {
          jQuery(focusPointConfig.functionSelector).focusPoint();
        });
        var lbwpReRunTrigger = 0;
        function lbwpReRunFocusPoint() {
          if (lbwpReRunTrigger > 0) clearTimeout(lbwpReRunTrigger);
          lbwpReRunTrigger = setTimeout(function() {
            jQuery(focusPointConfig.functionSelector).focusPoint();
          }, 50);
        }
        function lbwpFixCloneFocusPoint(event, slick) {
          var slider = jQuery(this);
          setTimeout(function() {
            var clones = slider.find(".slick-cloned");
            var items = slider.find(".slick-slide:not(.slick-cloned)").length;
            clones.each(function() {
              var clone = jQuery(this);
              var index = clone.data("slick-index");
              if (index < 0) {
                var originalIndex = (index === -1) ? (items-1) : (items-2);
              } else if (index > 0) {
                var originalIndex = (((index+1) - items) === 1) ? 0 : 1;
              }
              var original = slider.find("[data-slick-index=" + originalIndex  + "] img");
              clone.find("img").attr("style", original.attr("style"));
            });
          }, 350);
	      }
	      document.addEventListener("lazybeforeunveil", function(e){
          lbwpReRunFocusPoint();
        });
      </script>
    ';
  }

  /**
   * @param $imageIds
   * @return string
   */
  public static function getFocusPointGalleryByIds($imageIds)
  {
    return self::$instance->getFocusPointGallery(
      array('from_block' => true),
      array('ids' => implode(',', $imageIds))
    );
  }

  /**
   * @param string $original not used at all
   * @param array $block
   * @return string html
   */
  public function overrideGalleryBlock($original, $block)
  {
    if ($block['attrs'] !== null) {
      foreach ($block['attrs'] as $attrKey => $attr) {
        $block[$attrKey] = $attr;
      }
    }

    $blockSettings = array(
      'columns' => isset($block['columns']) ? $block['columns'] : $this->config['gallerySettings']['columns'],
      'imageCrop' => isset($block['imageCrop']) ? $block['imageCrop'] : $this->config['gallerySettings']['imageCrop'],
      'imageSize' => isset($block['sizeSlug']) ? $block['sizeSlug'] : $this->config['gallerySettings']['imageSize'],
      'linkTo' => isset($block['linkTo']) ? $block['linkTo'] : $this->config['gallerySettings']['linkTo'],
    );

    $domDoc = new \DOMDocument();
    @$domDoc->loadHTML('<?xml encoding="utf-8" ?>' . $original);
    $getFigures = $domDoc->getElementsByTagName('figure');
    $xpath = new \DOMXPath($domDoc);
    $getMainCaption = $xpath->query('//figcaption[@class="blocks-gallery-caption"]');
    $mainCaption = $getMainCaption->length > 0 ? $getMainCaption->item(0)->nodeValue : '';
    $captions = array();

    foreach($getFigures as $figure){
      if(strpos($figure->getAttribute('class'), 'wp-block-gallery') !== false){
        continue;
      }

      $theCaption = $figure->getElementsByTagName('figcaption');
      // Get the class of the image in the figure
      $attachmentId = intval(str_replace('wp-image-', '', $figure->getElementsByTagName('img')->item(0)->getAttribute('class')));

      if($theCaption->length > 0){
        $captions[$attachmentId] = $theCaption->item(0)->nodeValue;
      }else{
        $captions[$attachmentId] = '';
      }
    }

    // Fix for the new wordpress gallery block
    if(!isset($block['ids']) || count($block['ids']) == 0 && isset($block['innerBlocks'])){
      $block['ids'] = array_map(function ($item){
        return $item['attrs']['id'];
      }, $block['innerBlocks']);
    }

    // Randomize the images ids when block setting is configured
    if(isset($block['randomOrder']) && $block['randomOrder'] === true){
      shuffle($block['ids']);
    }

    $classes = isset($block['className']) ? ' ' . $block['className'] : '';
    return self::$instance->getFocusPointGalleryBlock(
      array(
        'from_block' => true,
        'wrap_block' => '<div class="wp-block-gallery' . $classes . '">{inner}</div>',
        'settings' => $blockSettings,
        'captions' => $captions,
        'blockCaption' => $mainCaption
      ),
      array('ids' => implode(',', is_array($block['ids']) ? $block['ids'] : array()))
    );
  }

  /**
   * @param $args
   * @param $instance
   * @return string
   */
  public function getFocusPointGallery($args, $instance)
  {
    $html = '';
    // Get the actual attachment ids
    $isMultilang = Multilang::isActive();
    $currentLang = Multilang::getCurrentLang();
    $attachments = explode(',', $instance['ids']);
    $galleryId = md5(serialize($instance['ids']));

    // Set if the original image should be linked
    $wrapOriginal = $this->config['gallerySettings']['linkOriginal'];
    if ($wrapOriginal === 'auto') {
      $wrapOriginal = ($instance['link'] === 'file') ? true : false;
    }

    // Set image size if automatic from config
    $imageSize = $this->config['gallerySettings']['imageSize'];
    $imageSize = ($imageSize === 'auto') ? $instance['size'] : $imageSize;

    // Build all the images
    foreach ($attachments as $id) {
      $caption = '';
      if ($this->config['gallerySettings']['printCaptions']) {
        $caption = get_post($id)->post_excerpt;
        // Maybe override with different language
        if ($isMultilang && $currentLang != Multilang::getPostLang($id)) {
          $translatedId = Multilang::getPostIdInLang($id, $currentLang);
          if ($translatedId !== false) {
            $caption = get_post($translatedId)->post_excerpt;
          }
        }
      }
      // Set eventual meta attributes if needed
      $attributes = '';
      foreach ($this->config['gallerySettings']['dataAttributes'] as $metaKey => $dataKey) {
        $attributes .= ' data-' . $dataKey . '="' . esc_attr(get_post_meta($id, $metaKey, true)) . '"';
      }
      // Create image and caption into element
      $html .= Templating::getBlock($this->config['gallerySettings']['element'], array(
        '{attr}' => $attributes,
        '{classes}' => '',
        '{image}' => FocusPoint::getImage($id, $imageSize, '', $wrapOriginal, '', $galleryId),
        '{caption}' => $caption
      ));
    }


    // Wrap in to the default container
    $html = Templating::getBlock($this->config['gallerySettings']['container'], array(
      '{imageSize}' => $imageSize,
      '{content}' => $html,
      '{blockCaption}' => ''
    ));

    // Wrap html as a block if needed
    if (isset($args['wrap_block'])) {
      $html = str_replace('{inner}', $html, $args['wrap_block']);
    }

    return $html;
  }

  /**
   * @param $args
   * @param $instance
   * @return string
   */
  public function getFocusPointGalleryBlock($args, $instance)
  {
    $html = '';
    // Get the actual attachment ids
    $isMultilang = Multilang::isActive();
    $currentLang = Multilang::getCurrentLang();
    $attachments = explode(',', $instance['ids']);
    $galleryId = md5(serialize($instance['ids']));

    // the block settings
    $settings = $args['settings'];
    $classes = array('columns-' . $settings['columns']);

    // Set if the original image should be linked
    $wrapOriginal = ($settings['linkTo'] === 'media' || $settings['linkTo'] === 'file') ? true : false;

    // Set image size if automatic from config
    $imageSize = $settings['imageSize'];
    $imageSize = ($imageSize === 'auto') ? $instance['size'] : $imageSize;

    // Build all the images
    $index = 0;
    foreach ($attachments as $id) {
      $caption = '';
      if ($this->config['gallerySettings']['printCaptions']) {
        $caption = $args['captions'][$id];
      }
      // Set eventual meta attributes if needed
      $attributes = '';
      foreach ($this->config['gallerySettings']['dataAttributes'] as $metaKey => $dataKey) {
        $attributes .= ' data-' . $dataKey . '="' . esc_attr(get_post_meta($id, $metaKey, true)) . '"';
      }

      $nonCroppedVariant = wp_get_attachment_image($id, $imageSize);
      if ($wrapOriginal) {
        $nonCroppedVariant = '<a href="' . WordPress::getImageUrl($id, 'original') . '" class="auto-fancybox" rel="gallery-' . $galleryId . '">
          '. $nonCroppedVariant . '
        </a>';
      }

      // Create image and caption into element
      $html .= Templating::getBlock($this->config['gallerySettings']['element'], array(
        '{attr}' => $attributes,
        '{imageSize}' => $imageSize,
        '{image}' => ($settings['imageCrop'] ?
          FocusPoint::getImage($id, $imageSize, '', $wrapOriginal, '', $galleryId) :
          $nonCroppedVariant
        ),
        '{caption}' => $caption,
      ));

      $index++;
    }

    // Wrap in to the default container
    $containerClasses = array(
      'gallery-focuspoint',
      'gallery-size-' . $settings['imageSize'],
      'columns-' . $settings['columns']
    );
    $html = Templating::getBlock($this->config['gallerySettings']['container'], array(
      '{classes}' => implode(' ', $containerClasses),
      '{content}' => $html,
      '{imageSize}' => $settings['imageSize'],
      '{blockCaption}' => $args['blockCaption'] === '' || $args['blockCaption'] === null ?
        '' :
        '<p class="gallery-block-main-caption">' . $args['blockCaption'] . '</p>'
    ));

    // Wrap html as a block if needed
    if (isset($args['wrap_block'])) {
      $html = str_replace('{inner}', $html, $args['wrap_block']);
    }

    return $html;
  }

  /**
   * Overrides the wordpress image block on front end
   * @param string $original
   * @param array $block
   * @return string html
   */
  public function overrideImageBlock($original, $block)
  {
    if ($block['attrs'] !== null) {
      foreach ($block['attrs'] as $attrKey => $attr) {
        $block[$attrKey] = $attr;
      }
    }

    $classes = 'size-' . $block['sizeSlug'];
    if (isset($block['className'])) {
      $classes .= ' ' . $block['className'];
    }
    // Try getting the ID from html if not given
    if (!isset($block['id'])) {
      $block['id'] = $this->getIdFromHtml($original);
    }
    // Now basically get the image
    $image = FocusPoint::getImage($block['id'], $block['sizeSlug']);
    // See if the original is link wrapped and wrap it
    if ($pos = strpos($original, '<a ')) {
      $end = strpos($original, '">', $pos);
      $link = substr($original, $pos, $end - $pos + 2);
      $image = $link . $image . '</a>';
    }

    $caption = '';
    if (strpos($original, '<figcaption>')) {
      Strings::replaceByXPath($original, '//figcaption', function ($doc, $tag, $fragment) use (&$caption) {
        $caption = $doc->saveHTML($tag);
        return $tag;
      });
    }

    return '
      <figure class="wp-block-image ' . $classes . '">
        ' . $image . '
        ' . $caption . '
      </figure>
    ';
  }

  /**
   * @param string $original
   * @return int an attachment id
   */
  protected function getIdFromHtml($original)
  {
    $imageUrl = Strings::parseTagProperty($original, 'src');
    // See if its a block storage file
    $pos = strpos($imageUrl, ASSET_KEY . '/files/');
    if ($pos > 0) {
      $db = WordPress::getDb();
      list($path, $file) = explode('/', substr($imageUrl, $pos + strlen(ASSET_KEY . '/files/')));
      return intval($db->get_var('SELECT post_id FROM ' . $db->postmeta . ' WHERE meta_value LIKE "%' . $path . '%" AND meta_value LIKE "%' . $file . '%"'));
    }

    return 0;
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
  public static function getImage($attachmentId, $size = 'large', $containerClasses = '', $wrapOriginal = false, $attr = '', $galleryId = '')
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

      // Transform the image to have the hwstring put at the end to ensure lazyloading compatibility
      $hwstring = ' width="' . $imgsize['width'] . '" height="' . $imgsize['height'] . '"';
      $image = str_replace($hwstring, '', $image);
      $image = str_replace(' />', $hwstring . ' />', $image);

      $html = '
        <div class="lbwp-focuspoint-container ' . $containerClasses . '">
          <figure class="lbwp-focuspoint" 
            data-focus-x="' . (float)$meta['focusPoint']['x'] . '" data-focus-y="' . (float)$meta['focusPoint']['y'] . '"
            data-focus-w="' . intval($imgsize['width']) . '" data-focus-h="' . intval($imgsize['height']) . '">
            ' . $image . '
          </figure>
        </div>
      ';

      // Wrap original image url around the block, if desired
      if ($wrapOriginal) {
        $rel = (strlen($galleryId) > 0) ? ' rel="gallery-' . $galleryId . '"' : '';
        $html = '<a href="' . WordPress::getImageUrl($attachmentId, 'original') . '" class="auto-fancybox"' . $rel . '>' . $html . '</a>';
      }
    }

    return $html;
  }
}



