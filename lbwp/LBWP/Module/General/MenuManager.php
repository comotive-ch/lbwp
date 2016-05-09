<?php

namespace LBWP\Module\General;

use LBWP\Util\Multilang;
use LBWP\Util\String;

/**
 * Class MenuManager v2
 * @package LBWP\Module\General
 * @author Michael Sebel <michael@comotive.ch>
 */
class MenuManager extends \LBWP\Module\Base
{
  /**
   * @var boolean
   */
  protected $inSavePost;

  /**
   * Initliazes the modules
   */
  public function initialize()
  {
    if (!Multilang::isActive()) {
      if (is_admin()) {
        add_action('save_post',array($this, 'savePost'),100, 2);
        add_action('add_meta_boxes',array($this, 'addMetaBoxes'));
        add_action('wp_ajax_change_page_parent', array($this, 'ajaxChangePageParent'));
        add_action('deleted_post', array($this, 'actionDeletePost'));
        add_action('trashed_post', array($this, 'actionDeletePost'));
        add_action('publish_page', array($this, 'publishMenuEntry'));
      }

      add_filter('wp_page_menu_args', array($this, 'filterWpMenuArgs'));
    }
  }

  /**
   * Filters the wordpress page menu arguments
   * @param array $args
   * @return array $args
   */
  public function filterWpMenuArgs($args)
  {
    $args['show_home'] = true;

    return $args;
  }

  /**
   * Executed on deleted_post and trashed_post
   * @param int $postId
   */
  public function actionDeletePost($postId)
  {
    $items = wp_get_associated_nav_menu_items($postId, 'post_type');
    foreach($items as $i) {
      wp_delete_post($i, true);
    }
  }

  /**
   * Adds the meta boxes
   */
  public function addMetaBoxes()
  {
    remove_meta_box('pageparentdiv', 'page', 'side');
    add_meta_box('template_box', 'Seitenvorlage', array($this, 'metaBoxTemplate'), 'page', 'side', 'high');
    add_meta_box('nav_menu_box', 'Organisation', array($this, 'metaBoxNavMenu'), 'page', 'side', 'high');
  }

  /**
   * Displays the meta box for the page template dropdown
   * @global $post
   */
  public function metaBoxTemplate()
  {
    global $post;
    $template = !empty($post->page_template) ? $post->page_template : false;
    ?>
    <label class="screen-reader-text" for="page_template"><?php _e('Page Template') ?></label>
    <select name="page_template" id="page_template">
      <option value='default'><?php _e('Default Template'); ?></option>
      <?php page_template_dropdown($template); ?>
    </select>
    <?php
  }

  /**
   * Displays the meta box for the menu management
   * @global $post
   */
  public function metaBoxNavMenu()
  {
    global $post;

    if ($post->ID > 0) {
      $item = $this->getMenuItem($post->ID);
    }

    echo '<div class="parent_page"><input type="hidden" name="promo_menu_save" value="1">';
    echo 'Elternseite:<br />';
    $dropdown = wp_dropdown_pages(array(
      'post_type' => $post->post_type,
      'exclude_tree' => $post->ID,
      'selected' => $post->post_parent,
      'name' => 'parent_id',
      'show_option_none' => __('(no parent)'),
      'sort_column' => 'menu_order, post_title',
      'echo' => false
    ));

    if ($dropdown === '') {
      echo '<span style="font-weight: normal;">Es sind noch keine Seiten vorhanden.</span>';
    } else {
      echo $dropdown;
    }

    echo '</div>';

    echo '<div class="show_in_menu">';
    echo '<label for="show_in_menu_0"><input type="radio" name="show_in_menu" value="0" id="show_in_menu_0" ' . checked(($item == false), true, false) . '> Nicht im Menü zeigen</label><br />';
    echo '<label for="show_in_menu_1"><input type="radio" name="show_in_menu" value="1" id="show_in_menu_1" ' . checked(($item == false), false, false) . '> Im Menü zeigen</label>';
    echo '<input type="hidden" name="changed_menu_order" id="changed_menu_order" value="0" />';
    echo '</div>';

    if ($item == false) {
      echo '<div class="sibling_list" style="display: none"><ul></ul></div>';
    } else {
      echo '<div class="sibling_list"><ul id="menu-to-edit" class="menu">';
      $siblings = $this->getSiblingItems($item);
      foreach ($siblings as $siblingItem) {
        if ($siblingItem->db_id != $item->db_id) {
          echo '
            <li class="menu-item menu-item-edit-inactive">
              <input type="hidden" name="sibling_order[]" value="' . $siblingItem->db_id . '">
              <dl class="menu-item-bar">
                <dt class="menu-item-handle">
                  <span class="item-title">' . $siblingItem->title . '</span>
                  <span class="item-controls">
                    <span class="item-type">' . $siblingItem->type_label . '</span>
                  </span>
                </dt>
              </dl>
            </li>
          ';
        } else {
          echo '
            <li class="menu-item menu-item-edit-inactive">
              <input type="hidden" name="sibling_order[]" value="' . $siblingItem->db_id . '">
              <dl class="menu-item-bar">
                <dt class="menu-item-handle">
                  <span class="item-title"><input type="text" value="' . $siblingItem->title . '" name="menu_title" class="widefat edit-menu-item-title" id="menu_title" style="width: 190px"></span>
                </dt>
              </dl>
            </li>
          ';
        }
      }

      echo '</ul>';
      echo '</div>';
    }
    ?>
      <script>
        function updateSiblingList()
        {
          var $request = {};

          // only update the sibling_list atomically
          if (!jQuery('.sibling_list').hasClass('updating')) {

            $request['action'] = 'change_page_parent';
            $request['show_in_menu'] = jQuery('input[name=show_in_menu]:checked').val();
            $request['parent_id'] = jQuery('#parent_id').val();
            $request['post_ID'] = jQuery('input[name=post_ID]').val();

            if (jQuery('input[name=show_in_menu]:checked').val() == '0') {
              jQuery('.sibling_list').hide();
            }

            // add control switch
            jQuery('.sibling_list').addClass('updating');
            jQuery('input[name=show_in_menu]').attr('disabled', 'disabled');
            jQuery('.sibling_list').append(jQuery('<div class="block-ui"></div>').hide());
            jQuery('.show_in_menu').prepend(jQuery('<span class="spinner"></span>').css('display', 'block'));
            jQuery('.sibling_list .block-ui').fadeIn();

            jQuery.post(ajaxurl, $request, function($response) {
              jQuery('.sibling_list').height(jQuery('.sibling_list').height());
              jQuery('.sibling_list ul').fadeOut('normal', function() {
                jQuery('.sibling_list ul li').remove();

                $data = jQuery.parseJSON($response);

                for (var $i=0; $i < $data.length; ++$i) {
                  if ($data[$i].object_id == jQuery('input[name=post_ID]').val()) {
                    jQuery('.sibling_list ul').append('<li class="menu-item menu-item-edit-inactive"><input type="hidden" name="sibling_order[]" value="'+$data[$i].id+'"><dl class="menu-item-bar"><dt class="menu-item-handle"><span class="item-title"><input type="text" value="'+$data[$i].title+'" name="menu_title" class="widefat edit-menu-item-title" id="menu_title" style="width: 190px"></span></dt></dl></li>');
                  } else {
                    jQuery('.sibling_list ul').append('<li class="menu-item menu-item-edit-inactive"><input type="hidden" name="sibling_order[]" value="'+$data[$i].id+'"><dl class="menu-item-bar"><dt class="menu-item-handle"><span class="item-title">'+$data[$i].title+'</span><span class="item-controls"><span class="item-type">'+$data[$i].type_label+'</span></span></dt></dl></li>');
                  }
                }

                // remove control switch
                jQuery('.sibling_list').css('height', 'auto').removeClass('updating');
                jQuery('input[name=show_in_menu]').removeAttr('disabled');
                jQuery('.show_in_menu .spinner').remove();
                jQuery('.sibling_list .block-ui').fadeOut('normal', function() {
                  jQuery(this).remove();
                });

                if (jQuery('input[name=show_in_menu]:checked').val() == '1') {
                  jQuery('.sibling_list').show();
                  jQuery('.sibling_list ul').show();
                }

              });

            });
          }
        };

        jQuery(function() {
          jQuery('.sibling_list ul').sortable({
            update : function(event, ui) {
              jQuery('#changed_menu_order').val('1');
            }
          });

          jQuery('#show_in_menu_0').click(updateSiblingList);
          jQuery('#show_in_menu_1').click(updateSiblingList);

          jQuery('#parent_id').change(updateSiblingList);
        });
      </script>
    <?php
  }

  /**
   * Saves a post
   * @param integer $postId
   * @param \WP_Post $post
   */
  public function savePost($postId, $post)
  {
    if (($_POST['promo_menu_save'] == '1') && ($post->post_type == "page") && ($this->inSavePost == false)) {
      $this->inSavePost = true;

      $n = 1;

      if ($_POST['show_in_menu'] == 1) {
        $this->updateMenuItemStatus($post);
        $this->changeMenuItemTitle($post, trim(stripslashes($_POST['menu_title'])));
      } else {
        $this->removeMenuItem($postId);
      }

      if ($_POST['changed_menu_order'] == 1) {
        $this->reorderMenu($_POST['sibling_order']);
        $this->reorderAllSubpages($post->post_parent);
      }

      $this->inSavePost = false;
    } else if (($post->post_type == "page") && ($this->inSavePost == false)) {
      // This might happen if quick edit takes place. Only change the publish state, if needed
      $this->inSavePost = true;
      $this->updateMenuItemStatus($post);
      $this->inSavePost = false;
    }
  }

  /**
   * Executes the ajax action if somebody changes the
   * page parent or activates the menu manager
   */
  public function ajaxChangePageParent()
  {
    $result = array();

    $post = get_post($_POST['post_ID']);
    $updateParent = false;

    // Update the post parent
    if ($post->post_parent != $_POST['parent_id'] && $_POST['parent_id'] != '') {
      wp_update_post(array(
        'ID' => $_POST['post_ID'],
        'post_parent' => $_POST['parent_id']
      ));
      do_action('menu_manager_update_nav_menu', $_POST['post_ID']);

      $updateParent = true;
    }

    // Show in menu
    if ($_POST['show_in_menu'] == '1') {
      $item = $this->getMenuItem($_POST['post_ID']);
      if ($item === false) {
        $item = $this->createMenuItem($_POST['post_ID']);
      } else {
        $item = $this->updateMenuItem($_POST['post_ID'], $item, $updateParent);
      }

      // Result
      $items = $this->getSiblingItems($item);
      foreach($items as $item) {
        $result[] = array(
          'id' => $item->db_id,
          'object_id' => $item->object_id,
          'title' => $item->title,
          'type_label' => $item->type_label,
        );
      }
    } else {
      $this->removeMenuItem($_POST['post_ID']);
    }

    echo json_encode($result);
    die;
  }

  /**
   * Returns the menu for the given location
   * @param string $location
   * @return \WP_Term
   */
  public function getMenu($location = '')
  {
    $locations = get_nav_menu_locations();

    // If no location given, try to guess it
    if (strlen($location) == 0) {
      // Search from guesses and leave loops if something is found
      $guesses = array('primary', 'main');
      foreach ($locations as $key => $location) {
        foreach ($guesses as $guess) {
          if (String::startsWith($key, $guess)) {
            $location = $key;
            break 2;
          }
        }
      }
    }

    return wp_get_nav_menu_object($locations[$location]);
  }

  /**
   * Returns the menu items on the same navigation level
   * @param \WP_Post $item
   * @return array
   */
  protected function getSiblingItems($item)
  {
    $result = array();
    $menu = $this->getMenu();

    $items = get_posts(array(
      'meta_key' => '_menu_item_menu_item_parent',
      'meta_value' => (string) $item->menu_item_parent,
      'post_type' => 'nav_menu_item',
      'orderby' => 'menu_order',
      'order' => 'asc',
      'numberposts' => '-1',
      'post_status' => 'any'
    ));

    foreach ($items as $siblingItem) {
      $id = $siblingItem->ID;

      if (is_object_in_term($siblingItem->ID, 'nav_menu', $menu->term_id)) {
        $result[] = wp_setup_nav_menu_item($siblingItem);
      }
    }

    return $result;
  }

  /**
   * Returns the menu item for the given post id
   * @param integer $postId
   * @param integer $itemId
   * @return \WP_Post
   */
  public function getMenuItem($postId, $itemId = null)
  {
    $navId = get_post_meta($postId, '_menuManagerNavItemId', true);
    $item = null;

    // no menu item was found with the new menu item structure, search for "old/original-structured" menu items
    if ($item === null && $postId > 0) {
      $menuObject = $this->getMenu();

      $items = wp_get_associated_nav_menu_items($postId, 'post_type');
      foreach ($items as $i) {
        if (is_object_in_term($i, 'nav_menu', array($menuObject->term_id))) {
          $i = wp_setup_nav_menu_item(get_post($i));
          if ((($i->post_parent == 0) && ($i->menu_item_parent == 0)) || (in_array($i->menu_item_parent, wp_get_associated_nav_menu_items($i->post_parent,'post_type')))) {
            if ($itemId === null || $itemId == $i->db_id) {
              $item = $i;
            }
          }
        }
      }

      // set up new nav menu item structure for found "old/original-structured" menu item
      if ($navId == false && $item !== null) {
        // Save some additional data
        update_post_meta($item->db_id, '_menuManagerIsManagerItem', true);
        update_post_meta($item->db_id, '_menuManagerOriginPostId', $postId);
        update_post_meta($postId, '_menuManagerNavItemId', $item->db_id);
      }
    }

    if ($item === null) {
      return false;
    }

    return $item;
  }

  /**
   * Creates a new menu item for the given post id
   * @param integer $postId
   * @return \WP_Post
   */
  protected function createMenuItem($postId)
  {
    // Load the target post
    $post = get_post($postId);
    $menu = $this->getMenu();

    // Define the item data
    $itemData = array(
      'menu-item-title' => $post->post_title,
      'menu-item-object-id' => $post->ID,
      'menu-item-object' => $post->post_type,
      'menu-item-type' => 'post_type',
      'menu-item-status' => $post->post_status
    );

    // Predefine the counter arguments
    $countArgs = array(
      'meta_key' => '_menu_item_menu_item_parent',
      'post_type' => 'nav_menu_item',
      'orderby' => 'menu_order',
      'order' => 'asc',
      'numberposts' => -1,
      'post_status' => 'any'
    );

    // Set the parent data
    if ($post->post_parent > 0) {
      $parentItem = $this->getMenuItem($post->post_parent);
      if ($parentItem === false) {
        $parentItem = $this->createMenuItem($post->post_parent);
      }
      $itemData['menu-item-parent-id'] = $parentItem->db_id;

      $countArgs['meta_value'] = (string) $parentItem->db_id;
      $itemData['menu-item-position'] = count(get_posts($countArgs)) + 1;
    } else {
      $countArgs['meta_value'] = '0';
      $itemData['menu-item-position'] = count(get_posts($countArgs)) + 1;
    }

    // Save the new menu item
    $id = wp_update_nav_menu_item($menu->term_id, 0, $itemData);

    // Set the correct status and publish date
    wp_update_post(array(
      'ID' => $id,
      'post_title' => $post->post_title,
      'post_status' => $post->post_status,
      'post_date' => $post->post_date,
      'post_date_gmt' => $post->post_date_gmt
    ));

    do_action('menu_manager_update_nav_menu', $id);

    // Save some additional datas
    update_post_meta($id, '_menuManagerIsManagerItem', true);
    update_post_meta($id, '_menuManagerOriginPostId', $postId);
    update_post_meta($postId, '_menuManagerNavItemId', $id);

    // Load the whole item and return it
    $item = wp_setup_nav_menu_item(get_post($id));
    return $item;
  }

  /**
   * Updates a new menu item for the given post id
   * @param integer $postId
   * @param \WP_Post $item
   * @param boolean $updateParent
   * @return \WP_Post
   */
  protected function updateMenuItem($postId, $item, $updateParent)
  {
    // Load the target post
    $post = get_post($postId);
    $menu = $this->getMenu();
    $title = $item->post_title;

    // Define the item data
    $itemData = array(
      'menu-item-object-id' => $post->ID,
      'menu-item-object' => $post->post_type,
      'menu-item-type' => 'post_type',
      'menu-item-status' => $post->post_status
    );

    // Predefine the counter arguments
    $countArgs = array(
      'meta_key' => '_menu_item_menu_item_parent',
      'post_type' => 'nav_menu_item',
      'orderby' => 'menu_order',
      'order' => 'asc',
      'numberposts' => -1,
      'post_status' => 'any'
    );

    // Set the parent data
    if ($updateParent) {
      if ($post->post_parent > 0) {
        $parentItem = $this->getMenuItem($post->post_parent);
        if ($parentItem === false) {
          $parentItem = $this->createMenuItem($post->post_parent);
        }
        $itemData['menu-item-parent-id'] = $parentItem->db_id;

        $countArgs['meta_value'] = (string) $parentItem->db_id;
        $itemData['menu-item-position'] = count(get_posts($countArgs)) + 1;
      } else {
        $countArgs['meta_value'] = '0';
        $itemData['menu-item-position'] = count(get_posts($countArgs)) + 1;
      }
    } else {
      $itemData['menu-item-parent-id'] = $item->menu_item_parent;
      $itemData['menu-item-position'] = $item->menu_order;
    }

    // Save the new menu item
    $id = wp_update_nav_menu_item($menu->term_id, $item->ID, $itemData);

    // Set the correct status and publish date
    wp_update_post(array(
      'ID' => $id,
      'post_title' => $title,
      'post_status' => $post->post_status,
      'post_date' => $post->post_date,
      'post_date_gmt' => $post->post_date_gmt
    ));

    do_action('menu_manager_update_nav_menu', $id);

    // Load the whole item and return it
    $item = wp_setup_nav_menu_item(get_post($id));
    return $item;
  }

  /**
   * Sortiert das Menü neu anhand der Daten im Array. Das Array muss alle IDs
   * der Kinder beinhalten damit alles sortiert wird
   * @param array $new_order IDs der Kind-menü-item-elemente
   */
  protected function reorderMenu($new_order)
  {
    $n = 1;
    foreach ($new_order as $item_id) {
      $post = get_post($item_id);

      if ($post != false) {
        $item = wp_setup_nav_menu_item($post);

        wp_update_post(array(
          'ID' => $item_id,
          'menu_order' => $n
        ));
        $n++;
      }
    }

    do_action('menu_manager_update_nav_menu', 0);
  }

  /**
   * Reorders all subpages
   * @param integer $postId
   */
  protected function reorderAllSubpages($postId)
  {
    $done_pages = array();
    $n = 1;
    $max = 0;

    $pages = get_posts(array(
      'post_type' => 'page',
      'numberposts' => '-1',
      'post_parent' => $postId
    ));

    foreach ($pages as $page) {
      $item = $this->getMenuItem($page->ID);
      if ($item != false) {
        $done_pages[] = $page->ID;
        wp_update_post(array(
          'ID' => $page->ID,
          'menu_order' => $item->menu_order
        ));
        if ($item->menu_order > $max)
          $max = $item->menu_order;
      }
    }

    $n = $max + 1;
    foreach ($pages as $page) {
      if (in_array($page->ID, $done_pages) == false) {
        wp_update_post(array(
          'ID' => $page->ID,
          'menu_order' => $n
        ));
      }
    }

    do_action('menu_manager_update_nav_menu', 0);
  }

  /**
   * Changes the item title for a post
   * @param \WP_Post $post
   * @param string $newTitle
   * @return false, if it didn't work
   */
  protected function changeMenuItemTitle($post, $newTitle)
  {
    $item = $this->getMenuItem($post->ID);

    if ($item->db_id == 0) {
      return false;
    }

    $newData = array(
      'ID' => $item->db_id,
      'post_title' => stripslashes($_POST['menu_title']),
      'post_status' => $post->post_status,
      'post_date' => $post->post_date,
      'post_date_gmt' => $post->post_date_gmt
    );

    wp_update_post($newData);
    do_action('menu_manager_update_nav_menu', $item->db_id);
  }

  /**
   * Removes a menu item from the menu
   * @param integer $postId
   */
  protected function removeMenuItem($postId)
  {
    $item = $this->getMenuItem($postId);

    if ($item != false) {
      wp_delete_post($item->ID, true);
    }
  }

  /**
   * Updates in every menu the menu entry for the given post. If the page
   * in two different menus is linked this function updates the menu entry
   * for the page in every of the two menues and set the same status for
   * the menu entry as the post has.
   * @param mixed $post Post data
   */
  protected function updateMenuItemStatus($post)
  {
    $items = wp_get_associated_nav_menu_items($post->ID, 'post_type');
    $first = true;

    foreach ($items as $menu_id) {
      /**
       * Check if the menu entries has already the same status as the page.
       * If the menu entries has the same status as the page we should'nt
       * update the menu entries (to safe some performance).
       */
      if ($first) {
        $menu = get_post($menu_id);

        if ($post->post_status == $menu->post_status) {
          break;
        }

        $first = false;
      }

      // Set the status and the publish dates of the post for each menu entry.
      wp_update_post(array(
        'ID' => $menu_id,
        'post_status' => $post->post_status,
        'post_date' => $post->post_date,
        'post_date_gmt' => $post->post_date_gmt
      ));

      do_action('menu_manager_update_nav_menu', $menu_id);
    }
  }

  /**
   * Loads the post by the post id and updates the menu entries for this post.
   * This method will be executed by the action "publish_page" when the page
   * will be published.
   * @param integer $postId
   */
  public function publishMenuEntry($postId)
  {
    /**
     * Return if the post id isn't valid
     */
    if ($postId < 1) {
      return;
    }

    $post = get_post($postId);

    /**
     * Return if the post isn't a page
     */
    if ($post->post_type !== 'page') {
      return;
    }

    $this->updateMenuItemStatus($post);
  }

  /**
   * Updates the parent id of the menu item object post id
   * @param \WP_Post $item
   * @param int $postId
   * @param int $newParentId
   */
  public function updatePostParent($item, $postId, $newParentId)
  {
    $useMenuManager = get_post_meta($item->db_id, '_menuManagerIsManagerItem', true);
    $postNavMenuItem = get_post_meta($item->object_id, '_menuManagerNavItemId', true);

    if ($useMenuManager && $item->menu_item_parent != $newParentId && $postNavMenuItem == $item->db_id) {
      $parentNav = wp_setup_nav_menu_item(get_post($newParentId));

      $parentId = 0;
      if ($parentNav->type == 'post_type') {
        $parentId = $parentNav->object_id;

        wp_update_post(array(
          'ID' => $item->object_id,
          'post_parent' => $parentId
        ));
        do_action('menu_manager_update_nav_menu', $item->object_id);
      }
    }
  }
} 