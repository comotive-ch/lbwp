<?php

namespace LBWP\Newsletter\Template;
use LBWP\Util\Strings;

/**
 * This class represents a content item for the newsletter
 * @package LBWP\Newsletter\Template
 * @author Michael Sebel <michael@comotive.ch>
 */
class Item
{
  /**
   * @var int the internal id of the element
   */
  protected $id = 0;
  /**
   * @var string the title
   */
  protected $title = '';
  /**
   * @var string the text
   */
  protected $text = '';
  /**
   * @var string the link
   */
  protected $link = '';
  /**
   * @var string the used image url
   */
  protected $image = '';
  /**
   * @var int if once set, the attachment can be reset
   */
  protected $attachmentId = 0;

  /**
   * Creates a new content item
   * @param int $id the original id (post id if a post type)
   * @param string $title the title of the item
   * @param string $text the text of the item
   * @param string $link the link to the original article
   */
  public function __construct($id, $title, $text, $link)
  {
    $this->id = $id;
    $this->title = $title;
    $this->text = $text;
    $this->link = $link;

    // Make title empty, if :: is at the beginning
    if (Strings::startsWith($this->title, '::')) {
      $this->title = '';
    }
  }

  /**
   * @param string $url the url to set as image
   */
  public function setImageByUrl($url)
  {
    $this->image = $url;
  }

  /**
   * @param int $attachmentId the attachment to use
   * @param string $size the image size
   */
  public function setImageByAttachment($attachmentId, $size = 'thumbnail')
  {
    $this->attachmentId = $attachmentId;
    $imageData = wp_get_attachment_image_src($attachmentId, $size);
    $this->image = $imageData[0];
  }

  /**
   * Can only be used in renderItem, since the attachment is set already
   * @param string $size the new image size
   */
  public function changeAttachmentSize($size)
  {
    $this->setImageByAttachment($this->attachmentId, $size);
  }

  /**
   * @param int $postId the post item
   * @return Item the configured item
   */
  public static function createItem($postId)
  {
    // Create empty item and get the post
    $item = new Item(0, '', '', '');
    $post = get_post($postId);

    switch($post->post_type) {
      case 'lbwp-nl-item':
        $item = self::createTypeItem($post);
        break;
      case 'post':
        $item = self::createPostItem($post);
        break;
    }

    return apply_filters('newsletterItemFilter', $item);
  }

  /**
   * @param \stdClass $post post item
   * @return Item the configured item
   */
  public static function createTypeItem($post)
  {
    $item = new Item(
      $post->ID,
      $post->post_title,
      get_post_meta($post->ID, 'newsletterText', true),
      get_post_meta($post->ID, 'newsletterLink', true)
    );

    // Configure by attachment id
    $attachmentId = get_post_thumbnail_id($post->ID);
    $item->setImageByAttachment($attachmentId);

    return $item;
  }

  /**
   * @param \stdClass $post post item
   * @return Item the configured item
   */
  public static function createPostItem($post)
  {
    $title = $post->post_title;
    $text = $post->post_excerpt;

    $titleCustom = get_post_meta($post->ID, 'newsletterTitle', true);
    $textCustom = get_post_meta($post->ID, 'newsletterText', true);

    // If the custom texts are set, use them
    if (strlen(trim($titleCustom)) > 0) {
      $title = $titleCustom;
    }

    if (strlen(trim($textCustom)) > 0) {
      $text = $textCustom;
    }

    // Create the item
    $item = new Item(
      $post->ID,
      $title,
      $text,
      get_permalink($post->ID)
    );

    // Configure by attachment id
    $attachmentId = get_post_thumbnail_id($post->ID);
    $item->setImageByAttachment($attachmentId);

    return $item;
  }

  /**
   * @return string
   */
  public function getTitle()
  {
    return $this->title;
  }

  /**
   * @return int
   */
  public function getId()
  {
    return $this->id;
  }

  /**
   * @return string
   */
  public function getImage()
  {
    return $this->image;
  }

  /**
   * @return string
   */
  public function getLink()
  {
    return $this->link;
  }

  /**
   * @return string
   */
  public function getText()
  {
    return $this->text;
  }
} 