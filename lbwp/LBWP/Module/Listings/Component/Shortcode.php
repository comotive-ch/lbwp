<?php

namespace LBWP\Module\Listings\Component;

use LBWP\Util\Strings;
use LBWP\Util\WordPress;
use LBWP\Module\Listings\Core;
use LBWP\Util\ArrayManipulation;
use LBWP\Helper\MetaItem\CrossReference;
use LBWP\Module\Listings\Component\Posttype;
use LBWP\Util\File;

/**
 * This class handles shortcode interpretion and html generation
 * @package LBWP\Module\Listings\Component
 * @author Michael Sebel <michael@comotive.ch>
 */
class Shortcode extends Base
{
  /**
   * @var string the metabox id prefix
   */
  const LIST_CODE = 'lbwp:listing';
  /**
   * Called after component construction
   */
  public function load() { }

  /**
   * Called at init(50)
   */
  public function initialize()
  {
    add_shortcode(self::LIST_CODE, array($this, 'display'));
    add_action('media_buttons', array($this, 'addListingButton'), 20);
  }

  /**
   * Adds the link to add a form to the page
   */
  public function addListingButton()
  {
    global $current_screen;

    if (
      ($current_screen->base != 'post' && $current_screen->base != 'widgets') ||
      (wp_count_posts(Posttype::TYPE_LIST)->publish == 0)
    ) {
      return;
    }

    $config = '&name=Auflistung&plural=Auflistungen&posttype=lbwp-list&code=lbwp:listing';
    $link = File::getViewsUri() . '/module/general/add.php?body_id=media-upload' . $config . '&TB_iframe=1';

    echo '
      <a href="' . $link . '" id="add-listing" title="Auflistung einfügen"
        class="thickbox button dashicons-before dashicon-big dashicons-format-aside"></a>
    ';
  }

  /**
   * @param int $id listing id
   * @return string html code
   */
  public static function displayList($id)
  {
    $listHtml = '';
    if (Core::isActive()) {
      $listHtml = Core::getInstance()->getShortcode()->display(array('id' => $id));
    }
    return $listHtml;
  }

  /**
   * @param array $args shortcode arguments
   * @return string list html output
   */
  public function display($args)
  {
    $list = get_post($args['id']);

    if ($list->post_type == Posttype::TYPE_LIST) {
      $items = $this->getListItems($list->ID);
      if (count($items) > 0) {
        return $this->printListHtml($list, $items);
      }
    }

    return '';
  }

  /**
   * @param int $listId id of the list
   * @return \WP_Post[] post elements assigned to the list
   */
  public function getListItems($listId)
  {
    $metaKey = CrossReference::getKey(Posttype::TYPE_LIST, Posttype::TYPE_ITEM);
    return CrossReference::getListItems($listId, $metaKey);
  }

  /**
   * @param \WP_Post $list the list object
   * @param \WP_Post[] $items post elements assigned to the list
   * @return string the produced html code
   */
  protected function printListHtml($list, $items)
  {
    // Find out the template that should be applied
    $templateId = get_post_meta($list->ID, 'template-id', true);
    $template = $this->core->getConfigurator()->getTemplate($templateId);
    $hasPath = (strlen($template['path']) > 0 && is_readable($template['path']));
    // Replace the optional additional classes parameter
    $template['container'] = str_replace(
      '{additional-class}',
      get_post_meta($list->ID, 'additional-class', true),
      $template['container']
    );
    // Split into start and end part
    list($start, $end) = explode('{listing}', $template['container']);

    // Start a buffer, because we're using includes here to generate html
    ob_start();

    // Start the container
    echo $start;
    // Now display all the items, depending on view type (file|html)
    $ordering = 0;
    foreach ($items as $item) {
      // Make it globally accessible
      $item->order = ++$ordering;
      Core::setCurrentListElementItem($item);
      // Display depending on view type
      if ($hasPath) {
        include $template['path'];
      } else {
        echo $this->printItemHtml($template, $item);
      }
    }

    // End the container
    echo $end;

    // End the buffer to get the content
    $content = ob_get_contents();
    ob_end_clean();

    return $content;
  }

  /**
   * Print the item html by replacing meta/basic strings in html
   * @param array $template the template object
   * @param \WP_Post $item the item to be displayed
   */
  protected function printItemHtml($template, $item)
  {
    $html = $template['html'];

    // Replace basics
    $html = str_replace('{post_title}', $item->post_title, $html);
    $html = str_replace('{post_content}', $item->post_content, $html);
    $html = str_replace('{id}', $item->guid, $html);

    // Actually get meta data from database
    $sql = 'SELECT meta_key, meta_value FROM {sql:postMeta} WHERE post_id = {itemId}';
    $fields = WordPress::getDb()->get_results(Strings::prepareSql($sql, array(
      'postMeta' => WordPress::getDb()->postmeta,
      'itemId' => $item->ID
    )));

    // Blindly try to replace all registered fields by key
    foreach ($fields as $field) {
      $html = str_replace('{' . $field->meta_key . '}', $field->meta_value, $html);
    }

    // Print the html block
    echo $html;
  }
}
