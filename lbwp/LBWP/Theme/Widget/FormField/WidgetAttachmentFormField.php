<?php
/**
 * Class WidgetAttachmentFormField
 *
 * @category Blogwerk
 * @package Blogwerk_Widget
 * @subpackage FormField
 * @author Tom Forrer <tom.forrer@blogwerk.com
 * @copyright Copyright (c) 2014 Blogwerk AG (http://blogwerk.com)
 */

namespace LBWP\Theme\Widget\FormField;

use LBWP\Helper\Metabox;

/**
 * Class WidgetAttachmentFormField
 *
 * @category Blogwerk
 * @package Blogwerk_Widget
 * @subpackage FormField
 * @author Tom Forrer <tom.forrer@blogwerk.com
 * @copyright Copyright (c) 2014 Blogwerk AG (http://blogwerk.com)
 */
class WidgetAttachmentFormField extends AbstractFormField
{
  public function __construct($fieldSlug, $widget, $options = array())
  {
    parent::__construct($fieldSlug, $widget, $options);
    add_action('wp_ajax_query-attachments', array($this, 'prepareAttachmentImageSize'), 0);
    add_action('add_attachment', array($this, 'prepareAttachmentImageSize'), 0);
  }

  /**
   *
   */
  public function setup()
  {
    $this->enqueueMediaUploaderAssets();
  }

  /**
   *
   */
  public function prepareAttachmentImageSize()
  {
    add_filter('image_size_names_choose', array($this, 'addImageSize'));

  }

  /**
   * @param $sizes
   * @return mixed
   */
  public function addImageSize($sizes)
  {
    $imageSize = $this->getOption('size');
    if ($imageSize) {
      $sizes[$imageSize] = $imageSize;
    }
    return $sizes;
  }

  /**
   * @param $value
   * @param array $instance
   * @return mixed|string
   */
  public function display($value, $instance)
  {
    $styleAttribute = '';
    $imageHtml = '';
    $valueAttribute = '';
    $attachment = get_post(intval($value));
    $mediaUploaderClass = '';
    $imageSize = 'thumbnail';
    if ($this->getOption('size')) {
      $imageSize = $this->getOption('size');
    }
    if ($attachment) {
      list($url, $width, $height, $crop) = wp_get_attachment_image_src($attachment->ID, $imageSize);
      $styleAttribute = 'style="padding: ' . number_format(100 * ($height / $width), 2, '.', '') . '% 0 0 0; width: ' . $width . 'px;"';
      $imageHtml = '<img src="' . $url . '" />';
      $valueAttribute = 'value="' . $attachment->ID . '"';
      $mediaUploaderClass = 'has-attachment';
    }
    $attachmentFieldId = $this->getWidget()->get_field_id($this->getSlug());
    $attachmentFieldName = $this->getWidget()->get_field_name($this->getSlug());
    $html = sprintf('<div class="media-uploader attachment-field-%s %s">
        <ul class="media-uploader-attachments clearfix">
          <li class="media-uploader-attachment">
            <a href="#" class="remove-attachment"></a>
            <div class="image-media-container media-container" %s>
              %s
            </div>
            <input type="hidden" class="field-attachment-id" id="%s" name="%s" %s />
          </li>
        </ul>

        <input type="button" class="button" id="' . $this->getWidget()->get_field_id('upload') . '" name="' . $this->getWidget()->get_field_name('upload') . '" value="' . $this->getLabel() . '"  />
      </div>', $attachmentFieldId, $mediaUploaderClass, $styleAttribute, $imageHtml, $attachmentFieldId, $attachmentFieldName, $valueAttribute) .
            sprintf('
    <script>
      (function ($) {
        $(document).ready(function(){
          var attachmentFieldId = "%s";
          if (attachmentFieldId.indexOf("__i__") !== -1) {
            // if it is a template, register the attachment functionality when we know the concrete widget id
            $(document).on("click", ".widget-title, .widget-action", function(){
              var widgetId = $(this).closest(".widget").find(".widget-id").val();
              var idBase = $(this).closest(".widget").find(".id_base").val();
              if (widgetId.indexOf("__i__") === -1) {
                attachmentFieldId = attachmentFieldId.replace(idBase + "-__i__", widgetId);
                $(".widget .media-uploader.attachment-field-" + attachmentFieldId).wordpressAttachment({
                  attachmentIdFieldName: "%s",
                  attachmentSize: "%s"
                });
              }
            });
          } else {
            $(".widget .media-uploader.attachment-field-%s").wordpressAttachment({
              attachmentIdFieldName: "%s",
                  attachmentSize: "%s"
            });
          }
        });
      }(jQuery));
    </script>', $attachmentFieldId, $attachmentFieldName, $imageSize, $attachmentFieldId, $attachmentFieldName, $imageSize);

    return $html;
  }

  /**
   *
   */
  public function enqueueMediaUploaderAssets()
  {
    // necessary for wp.media to work (already handled in post edit screen, but not for widgets.php)
    wp_enqueue_media();
    wp_enqueue_script(
      'media-uploader-js',
      plugins_url('resources/js/jquery-wpattachment.js', 'lbwp/lbwp.php'),
      array('jquery', 'media-upload', 'media-views'),
      Metabox::VERSION
    );

    wp_enqueue_style(
      'media-uploader-css',
      plugins_url('resources/css/media-uploader.css', 'lbwp/lbwp.php'),
      array(),
      Metabox::VERSION
    );
  }
} 
