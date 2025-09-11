<?php

namespace LBWP\Theme\Component\Selection\Import;

use LBWP\Core as LbwpCore;
use LBWP\Module\Backend\S3Upload;
use LBWP\Theme\Component\Selection\Core;
use LBWP\Util\Date;
use LBWP\Util\File;
use LBWP\Util\Strings;
use LBWP\Util\WordPress;

/**
 * Base class for importing features
 * @package LBWP\Theme\Component\Selection\Import
 * @author Michael Sebel <michael@comotive.ch>
 */
abstract class Base
{
  /**
   * @var array the importer configuration
   */
  protected $config = array();
  /**
   * @var array the full selections config
   */
  protected $selection = array();

  /**
   * Base constructor setting configs
   * @param array $config main selection config object
   */
  public function __construct($config)
  {
    $this->config = $config['importerConfig'];
    $this->selections = $config;
    unset($this->selections['importerConfig']);
  }

  /**
   * @return string the selection dropdown
   */
  public function getSelectionDropdown()
  {
    $html = '';
    $selections = $this->getSelectionList();

    // Generate a dropdown of the list
    $html .= '<select name="import-selection">';
    foreach ($selections as $key => $value) {
      $html .= '<option value="' . $key . '">' . $value . '</option>';
    }
    $html .= '</select>';

    return $html;
  }

  /**
   * @param $id
   * @return string a message from the import
   */
  public function run($id)
  {
    $selection = $this->getSelectionData($id);
    $title = Date::getTime(Date::EU_DATE, $selection['selection']['timestamp']);


    // Create the new selection holder object
    $selectionId = wp_insert_post(array(
      'post_type' => Core::TYPE_SELECTION,
      'post_title' => $title,
      'post_name' => Strings::forceSlugString($title) . '-' . $selection['selection']['id'],
      'post_content' => $selection['selection']['comment'],
      'post_excerpt' => strip_Tags($selection['selection']['comment']),
      'post_status' => $this->config['importPostStatus']
    ));

    // Create and attach the news items
    foreach ($selection['articles'] as $article) {
      $this->insertArticleToSelection($article, $selectionId);
    }

    return 'Selektion für ' . $title . ' wurde importiert.';
  }

  /**
   * @param $article
   * @param $selectionId
   */
  public function insertArticleToSelection($article, $selectionId)
  {
    /** @var S3Upload $upload for up/sideloading images */
    require_once( ABSPATH . 'wp-admin/includes/image.php' );
    $upload = LbwpCore::getModule('S3Upload');

    // Create the article with selection as parent
    $articleId = wp_insert_post(array(
      'post_type' => Core::TYPE_NEWS_ITEM,
      'post_status' => 'publish',
      'post_date' => $article['date'], // possibly NULL
      'post_title' => $article['title'],
      'post_content' => $article['content'],
      'post_parent' => $selectionId
    ));

    // Add terms, if given
    foreach ($article['terms'] as $taxonomy => $list) {
      if (count($list) > 0 && strlen($taxonomy) > 0) {
        $terms = array();
        foreach ($list as $tag) {
          $terms[] = get_term_by('name', $tag, $taxonomy)->term_id;
        }
        wp_set_post_terms($articleId, $terms, $taxonomy);
      }
    }
    if (count($article['tags']) > 0) {
      $terms = array();
      foreach ($article['tags'] as $tag) {
        $terms[] = get_term_by('name', $tag, Core::TAX_ITEM_CATEGORY)->term_id;
      }
      wp_set_post_terms($articleId, $terms, Core::TAX_ITEM_CATEGORY);
    }

    // Also add source and url as metadata for the article
    update_post_meta($articleId, 'source', $article['source']);
    update_post_meta($articleId, 'url', $article['url']);

    // Attach to the selection with meta item adding
    add_post_meta($selectionId, 'news', $articleId);

    // Download the file locally
    if (Strings::checkURL($article['image'])) {
      $url = $upload->importFileFromUri($article['image'], $articleId . '.jpg');
      // Create the according attachment
      $attachmentId = wp_insert_attachment(array(
        'guid'           => $url,
        'post_mime_type' => 'image/jpg',
        'post_title'     => 'imported-image-' . $articleId,
        'post_content'   => '',
        'post_status'    => 'inherit',
      ));

      // Meta for the file to know the exact url
      $relative = substr($url, strpos($url, '/files/') + 7);
      update_post_meta($attachmentId, '_wp_attached_file', $relative);
      $data = wp_generate_attachment_metadata($attachmentId, $url);
      wp_update_attachment_metadata($attachmentId, $data);
      // Attach that new image to the article after local import
      set_post_thumbnail($articleId, $attachmentId);
    }
  }

  /**
   * @return string the importer name
   */
  public function getName()
  {
    return $this->config['name'];
  }

  /**
   * @return array key/value pair of selections and their id from the importing service
   */
  abstract protected function getSelectionList();

  /**
   * @param mixed $id the services selection id
   * @return array the selections abstracted data
   */
  abstract protected function getSelectionData($id);
}