<?php

namespace LBWP\Aboon\Component;

use LBWP\Module\Frontend\HTMLCache;
use LBWP\Theme\Base\Component;
use LBWP\Util\ArrayManipulation;
use LBWP\Util\WordPress;
use LBWP\Aboon\Helper\WaitingAnimation;

/**
 * Allows to sell addons
 * @package LBWP\Aboon\Component
 * @author Michael Sebel <michael@comotive.ch>
 */
class Addons extends Component
{
  public function setup()
  {
    parent::setup();
    // Add actions that need to be added early on
    add_action('acf/init', array($this, 'addConfigFields'));
    // We use waiting animations here
    WaitingAnimation::getInstance();
  }

  /**
   * Add aboon general backend library
   */
  public function adminAssets()
  {
    wp_enqueue_script('lbwp-aboon-backend');
  }

  /**
   * Initialize the component
   */
  public function init()
  {
    // Add output on product detail on 21, that's after excerpt (20)
    add_action('woocommerce_single_product_summary', array($this, 'maybeDisplayAddonInfo'), 25);
    add_action('save_post_product', array($this, 'flushCachesOnProductSave'));
    add_action('woocommerce_review_order_before_payment', array($this, 'maybeCheckoutUpsell'));
    add_action('wp_ajax_changeAddonInCheckout', array($this, 'changeAddonInCheckout'));
    add_action('wp_ajax_nopriv_changeAddonInCheckout', array($this, 'changeAddonInCheckout'));

    // Handle adding of new products, to add addons as well afterwards, but before redirect
    if (isset($_POST['add-to-cart']) && isset($_POST['selectableAddons']) && strlen($_POST['selectableAddons']) > 0) {
      add_filter('woocommerce_add_to_cart_redirect', array($this, 'addAddonsToCart'));
    }
  }

  /**
   * Flushes relevant caches on saving a product in backend
   */
  public function flushCachesOnProductSave()
  {
    wp_cache_delete('getAddonByType', 'Aboon');
  }

  /**
   * Decide if the box of an addon or an addon upsell for a product will be displayed
   */
  public function maybeDisplayAddonInfo()
  {
    $addons = $this->getAddonsByType();
    // Our shown products basics are in global $post
    global $post;
    if ($this->isAddonProduct($post->ID, $addons)) {
      echo $this->displayProductDetailAddonInfo($addons[$post->ID]);
    } else if ($this->hasAddonUpselling($post->ID, $addons)) {
      echo $this->displayProductDetailAddonUpsell($post->ID, $addons);
    }
  }

  /**
   * Maybe show some options to purchase addons on checkout
   */
  public function maybeCheckoutUpsell()
  {
    // Get product ids in the cart and addons
    $addons = $this->getAddonsByType();
    $upsellable = array();
    $products = array_keys(WC()->cart->get_cart_item_quantities());
    // See if something is upsellable
    foreach ($products as $id) {
      foreach ($addons as $addon) {
        // If it is a matching addon and the addon isn't already in cart
        if ($addon['addon-in-checkout'] && in_array($id, $addon['products']) && !in_array($addon['id'], $products)) {
          $upsellable[$addon['id']] = $addon;
        }
      }
    }

    // Continue, if there are products to purchase
    if (count($upsellable) > 0) {
      $html = $this->getAddonListHtml($upsellable, __('Zu den Produkten im Warenkorb empfehlen wir folgendes:', 'lbwp'));
      echo '<div class="addon-upsell addon-upsell-checkout">' . $html . '</div>' . $this->getAddonCheckoutScript();
    }
  }

  /**
   * @param array $addon simple addon that needs to display its info on detail screen
   * @return string needed html to display the addon info
   */
  protected function displayProductDetailAddonInfo($addon)
  {
    $html = '';

    // Only show message if there are restrictions
    if ($addon['type'] == 'any-product' || $addon['type'] == 'specified-products') {
      HTMLCache::avoidCache();
      $productsInCart = array_keys(WC()->cart->get_cart_item_quantities());
      // Restrict, if needed
      if (
        ($addon['type'] == 'any-product' && count($productsInCart) == 0) ||
        ($addon['type'] == 'specified-products') && !ArrayManipulation::anyValueMatch($addon['products'], $productsInCart)
      ) {
        // Define the message added
        switch ($addon['type']) {
          case 'any-product':
            $html .= '<p>' . __('Dieses Produkt kann nur gekauft werden, wenn sich mindestens ein anderes Produkt im Warenkorb befindet.', 'lbwp') . '</p>';
            break;
          case 'specified-products':
            $products = $this->getConnectedProductList($addon['products']);
            $html .= '<p>' . sprintf(__('Dieses Produkt kann nur zusammen mit %s gekauft werden.', 'lbwp'), $products) . '</p>';
            break;
        }
        // Add some css that the product can't be bought at this moment
        $html .= '<style type="text/css">form.cart { display: none; } </style>';
      }

    }

    return '<div class="addon-upsell addon-detail-info">' . $html . '</div>';
  }

  /**
   * @param array $ids list of products ids
   * @return string html
   */
  protected function getConnectedProductList($ids)
  {
    $products = array();
    foreach ($ids as $productId) {
      $products[] = '<a href="' . get_permalink($productId) . '">' . get_the_title($productId) . '</a>';
    }

    return ArrayManipulation::humanSentenceImplode(', ', __('oder', 'lbwp'), $products);
  }

  /**
   * @param int $id the product that is shown and maybe has addons to upsell
   * @param array $addons list of simple addons with info when to show
   * @return string needed html to display the addon upselling form
   */
  protected function displayProductDetailAddonUpsell($id, $addons)
  {
    $html = '';
    // Reduce to applicable addons
    $applicable = array();
    foreach ($addons as $key => $addon) {
      if (in_array($id, $addon['products'])) {
        $applicable[$key] = $addon;
      }
    }

    // Only display something if there are options to choose
    if (count($applicable)) {
      $html .= $this->getAddonListHtml($applicable, __('Dazu passende Produkte', 'lbwp'));
      return '<div class="addon-upsell addon-upsell-info">' . $html . '</div>' . $this->getAddonDetailScript();
    }

    return '';
  }

  /**
   * @param array $addons the addons being displayed
   * @param string $title above the elements
   * @return string html
   */
  protected function getAddonListHtml($addons, $title)
  {
    $html = '<h4>' . $title . '</h4>';
    $html .= '<ul>';
    foreach ($addons as $id => $addon) {
      $imgDiv = '';
      $thumbnailId = get_post_thumbnail_id($id);
      if ($thumbnailId > 0) {
        $imgDiv = '<div class="addon-image">' . get_the_post_thumbnail($id, 'post-thumbnail') . '</div>';
      }
      $html .= '
          <li>
            <label>
              <div class="addon-checkbox"><input type="checkbox" class="selectable-addons" value="' . $id . '"></div>              
              ' . $imgDiv . '    
              <div class="addon-title">' . $addon['title'] . '</div>
              <div class="addon-permalink"><a href="' . get_permalink($id) . '" target="_blank">' . __('Details', 'lbwp') . '</a></div>
              <div class="addon-price">' . $addon['price'] . ' </div>
            </label>
          </li>
        ';
    }
    $html .= '</ul>';

    return $html;
  }

  /**
   * @return string micro script that adds addons to the current cart
   */
  protected function getAddonDetailScript()
  {
    return '
      <script type="text/javascript">
        jQuery(function() {
          jQuery("form.cart").append("<input type=\'hidden\' name=\'selectableAddons\' />");
          jQuery(".selectable-addons").on("change", function() {
            var values = [];
            jQuery(".selectable-addons:checked").each(function() {
              values.push(jQuery(this).val());
            });
            jQuery("input[name=selectableAddons]").val(values);
          });
        });
      </script>
    ';
  }

  /**
   * @return string micro script that adds and removes addons in checkout
   */
  protected function getAddonCheckoutScript()
  {
    return '
      <script type="text/javascript">
        jQuery(function() {
          jQuery(".selectable-addons").on("change", function() {
            var data = {
              action : "changeAddonInCheckout",
              id : jQuery(this).val(),
              add : jQuery(this).is(":checked")
            };
            aboon_waitingAnimationStart(".addon-upsell-checkout");
            jQuery.post("/wp-admin/admin-ajax.php", data, function(response) {
              if (response.success) {
                jQuery(document.body).trigger("update_checkout");
              }
              aboon_waitingAnimationStop(".addon-upsell-checkout");
            });
          });
        });
      </script>
    ';
  }

  /**
   * @throws \Exception
   */
  public function changeAddonInCheckout()
  {
    $response = array('success' => false);
    $productId = intval($_POST['id']);
    $add = ($_POST['add'] == 'true');

    if ($productId > 0) {
      $instance = WC()->cart;
      if ($add) {
        $instance->add_to_cart($productId, 1);
      } else {
        // Removal isn't so easy as we need the item key
        foreach ($instance->get_cart() as $itemKey => $item) {
          if ($item['product_id'] == $productId || $item['variation_id'] == $productId) {
            $instance->remove_cart_item($itemKey);
          }
        }
      }
      // Assume it worked correctly to be able to reload checkout
      $response['success'] = true;
    }

    WordPress::sendJsonResponse($response);
  }

  /**
   * @param string $url eventual redirect url
   * @return string unchanged $url as we need this to run logic
   */
  public function addAddonsToCart($url)
  {
    // Make sure that the function is only called once
    if(!isset($_POST['selectableAddons'])){
      return $url;
    }

    $products = array_map('intval', explode(',', $_POST['selectableAddons']));
    // Add those products with qty=1 to cart
    $instance = WC()->cart;
    if ($instance instanceof \WC_Cart) {
      foreach ($products as $productId) {
        $instance->add_to_cart($productId, 1);
      }
    }

    unset($_POST['selectableAddons']);
    return $url;
  }

  /**
   * @param int $id the id of the checked product
   * @param array $addons list of simple available addons
   * @return true if the product is an addon
   */
  protected function isAddonProduct($id, $addons)
  {
    return isset($addons[$id]);
  }

  /**
   * @param int $id the id of the checked product
   * @param array $addons list of simple available addons
   * @return true if the product has an addon that needs to be displayed
   */
  protected function hasAddonUpselling($id, $addons)
  {
    foreach ($addons as $addon) {
      if (in_array($id, $addon['products'])) {
        return true;
      }
    }

    return false;
  }

  /**
   * Cached list with less info on every available addons
   */
  protected function getAddonsByType()
  {
    $addons = wp_cache_get('getAddonByType', 'Aboon');
    if (!is_array($addons)) {
      $addons = array();
      // We need get posts as wc_get_products doesn't support meta query
      $raw = get_posts(array(
        'post_type' => 'product',
        'posts_per_page' => -1,
        'post_status' => 'publish',
        'meta_query' => array(
          array(
            'key' => 'is-addon',
            'value' => '"1"', // unfortunately a serialized string by acf
            'compare' => 'LIKE'
          )
        ),
        'order' => 'ASC',
        'orderby' => 'title'
      ));

      foreach ($raw as $item) {
        $product = wc_get_product($item->ID);
        $addons[$item->ID] = array(
          'id' => $item->ID,
          'title' => $product->get_title(),
          'price' => wc_price($product->get_price()),
          'type' => get_post_meta($item->ID, 'addon-type', true),
          'addon-in-checkout' => get_post_meta($item->ID, 'addon-in-checkout', true)[0] == 1,
          'products' => ArrayManipulation::forceArray(
            get_post_meta($item->ID, 'products-for-addon', true)
          )
        );
      }

      // Save those addons for another call
      wp_cache_set('getAddonByType', $addons, 'Aboon', 86400);
    }

    return $addons;
  }

  /**
   * Adds fields to config a product as addon for other products
   */
  public function addConfigFields()
  {
    acf_add_local_field_group(array(
      'key' => 'group_6023d9f510933',
      'title' => 'Als Addon-Produkt konfigurieren',
      'priority' => 'low',
      'fields' => array(
        array(
          'key' => 'field_6023da37f5006',
          'label' => 'Dieses Produkt als Addon verkaufen',
          'name' => 'is-addon',
          'type' => 'checkbox',
          'instructions' => '',
          'required' => 0,
          'conditional_logic' => 0,
          'wrapper' => array(
            'width' => '',
            'class' => '',
            'id' => '',
          ),
          'choices' => array(
            1 => 'Aktivieren',
          ),
          'allow_custom' => 0,
          'default_value' => array(
          ),
          'layout' => 'vertical',
          'toggle' => 0,
          'return_format' => 'value',
          'save_custom' => 0,
        ),
        array(
          'key' => 'field_6023da57a89a4',
          'label' => 'Verhaltensweise beim Kauf',
          'name' => 'addon-type',
          'type' => 'select',
          'instructions' => '',
          'required' => 0,
          'conditional_logic' => array(
            array(
              array(
                'field' => 'field_6023da37f5006',
                'operator' => '==',
                'value' => '1'
              ),
            ),
          ),
          'wrapper' => array(
            'width' => '',
            'class' => '',
            'id' => '',
          ),
          'choices' => array(
            'buyable' => 'Kann immer gekauft werden (auch ohne zusätzliches Produkt)',
            'any-product' => 'Kann zu jedem Produkt gekauft werden',
            'specified-products' => 'Kann nur mit verknüpften Produkten gekauft werden',
          ),
          'default_value' => false,
          'allow_null' => 0,
          'multiple' => 0,
          'ui' => 0,
          'return_format' => 'value',
          'ajax' => 0,
          'placeholder' => '',
        ),
        array(
          'key' => 'field_6023daecc560e',
          'label' => 'Verknüpfte Produkte',
          'name' => 'products-for-addon',
          'type' => 'relationship',
          'instructions' => 'Das Produkt wir mit diesen Produkten als Addon angeboten. Sowohl auf der Detailseite als auch, wenn so eingestellt vor dem Kaufabschluss.',
          'required' => 0,
          'conditional_logic' => array(
            array(
              array(
                'field' => 'field_6023da37f5006',
                'operator' => '==',
                'value' => '1'
              ),
            ),
          ),
          'wrapper' => array(
            'width' => '',
            'class' => '',
            'id' => '',
          ),
          'post_type' => array(
            0 => 'product',
          ),
          'taxonomy' => '',
          'filters' => array(
            0 => 'search',
          ),
          'elements' => array(
            0 => 'featured_image',
          ),
          'min' => '',
          'max' => '',
          'return_format' => 'id',
        ),
        array(
          'key' => 'field_6023daa1b39d2',
          'label' => 'Auf Kassen-Seite anbieten',
          'name' => 'addon-in-checkout',
          'type' => 'checkbox',
          'instructions' => 'Wenn die Regel zutrifft und das Addon nicht schon im Warenkorb ist, wird es unmittelbar vor dem Zahlen noch einmal angeboten.',
          'required' => 0,
          'conditional_logic' => array(
            array(
              array(
                'field' => 'field_6023da37f5006',
                'operator' => '==',
                'value' => '1'
              ),
            ),
          ),
          'wrapper' => array(
            'width' => '',
            'class' => '',
            'id' => '',
          ),
          'choices' => array(
            1 => 'Aktivieren',
          ),
          'allow_custom' => 0,
          'default_value' => array(
          ),
          'layout' => 'vertical',
          'toggle' => 0,
          'return_format' => 'value',
          'save_custom' => 0,
        ),
      ),
      'location' => array(
        array(
          array(
            'param' => 'post_type',
            'operator' => '==',
            'value' => 'product',
          ),
        ),
      ),
      'menu_order' => 100,
      'position' => 'normal',
      'style' => 'default',
      'label_placement' => 'left',
      'instruction_placement' => 'field',
      'hide_on_screen' => '',
      'active' => true,
      'description' => '',
    ));
  }
}