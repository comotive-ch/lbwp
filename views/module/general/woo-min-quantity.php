<?php

add_action('add_meta_boxes', 'wc_mmax_meta_box_create');
add_action('save_post', 'wc_mmax_save_meta_box');
function wc_mmax_meta_box_create()
{
  add_meta_box('wc_mmax_enable', __('Mindestbestellmenge', 'wcmmax'), 'wc_mmax_meta_box', 'product', 'side');
}

function wc_mmax_meta_box($post)
{
  wp_nonce_field('wc_mmax_cst_prd_nonce', 'wc_mmax_cst_prd_nonce');
  echo '<p>';
  echo '<label for="_wc_mmax_prd_opt_enable" style="float:left; width:75px;">' . __('Aktivieren', 'wcmmax') . '</label>';
  echo '<input type="hidden" name="_wc_mmax_prd_opt_enable" value="0" />';
  echo '<input type="checkbox" id="_wc_mmax_prd_opt_enable" class="checkbox" name="_wc_mmax_prd_opt_enable" value="1" ' . checked(get_post_meta($post->ID, '_wc_mmax_prd_opt_enable', true), 1, false) . ' />';
  echo '</p>';
  echo '<p>';
  $max = get_post_meta($post->ID, '_wc_mmax_max', true);
  $min = get_post_meta($post->ID, '_wc_mmax_min', true);
  echo '<label for="_wc_mmax_min" style="float:left; width:75px;">' . __('Minimum', 'wcmmax') . '</label>';
  echo '<input type="number" id="_wc_mmax_min" class="short" name="_wc_mmax_min" value="' . $min . '" />';
  echo '</p>';
  echo '<p>';
  echo '<label for="_wc_mmax_max" style="float:left; width:75px;">' . __('Maximum', 'wcmmax') . '</label>';
  echo '<input type="number" id="_wc_mmax_max" class="short" name="_wc_mmax_max" value="' . $max . '" />';
  echo '</p>';

}

function wc_mmax_save_meta_box($post_id)
{
  if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)
    return;
  if (!isset($_POST['_wc_mmax_prd_opt_enable']) || !wp_verify_nonce($_POST['wc_mmax_cst_prd_nonce'], 'wc_mmax_cst_prd_nonce'))
    return;
  update_post_meta($post_id, '_wc_mmax_prd_opt_enable', (int)$_POST['_wc_mmax_prd_opt_enable']);
  update_post_meta($post_id, '_wc_mmax_max', (int)$_POST['_wc_mmax_max']);
  update_post_meta($post_id, '_wc_mmax_min', (int)$_POST['_wc_mmax_min']);
}


/*
 * Function & it's hook to disable / enable add to cart buttons in the shop and category pages
 */

add_action('woocommerce_after_shop_loop_item', 'wc_mmax_woocommerce_template_loop_add_to_cart', 1);

function wc_mmax_woocommerce_template_loop_add_to_cart()
{
  global $product;
  $prodid = $product->get_id();
  $mmaxEnable = get_post_meta($prodid, '_wc_mmax_prd_opt_enable', true);
  $minQty = get_post_meta($prodid, '_wc_mmax_min', true);
  if (isset($mmaxEnable) && $mmaxEnable == 1 && $product->is_type('simple')) {
    remove_action('woocommerce_after_shop_loop_item', 'woocommerce_template_loop_add_to_cart');
    echo apply_filters('woocommerce_loop_add_to_cart_link',
      sprintf('<a href="%s" rel="nofollow" data-product_id="%s" data-product_sku="%s" data-quantity="%s" class="button %s product_type_%s ">%s</a>',
        esc_url($product->add_to_cart_url() . '&quantity=' . $minQty),
        esc_attr($product->id),
        esc_attr($product->get_sku()),
        esc_attr(isset($minQty) ? $minQty : 1),
        $product->is_purchasable() && $product->is_in_stock() ? 'add_to_cart_button' : '',
        esc_attr($product->product_type),
        esc_html($product->add_to_cart_text())
      ), $product);
  } else {
    add_action('woocommerce_after_shop_loop_item', 'woocommerce_template_loop_add_to_cart');
  }
}

/*Function to manipulate custom minimum and maximum purchase*/
add_filter('woocommerce_quantity_input_args', 'wc_mmax_quantity_input_args', 10, 2);
function wc_mmax_quantity_input_args($args, $product)
{
  if (function_exists('icl_object_id')) {
    $default_language = wpml_get_default_language();
    $prodid = icl_object_id($product->get_id(), 'product', true, $default_language);
  } else {
    $prodid = $product->get_id();
  }
  $mmaxEnable = get_post_meta($prodid, '_wc_mmax_prd_opt_enable', true);
  $minQty = get_post_meta($prodid, '_wc_mmax_min', true);
  $maxQty = get_post_meta($prodid, '_wc_mmax_max', true);
  if ($minQty > 0 && $maxQty > 0 && $mmaxEnable == 1) {
    $args['min_value'] = $minQty; // Starting value
    $args['max_value'] = $maxQty; // Ending value
  }
  return $args;
}

add_filter( 'woocommerce_update_cart_validation', function($valid, $key, $product, $quantity)  {
  $mmaxEnable = get_post_meta($product['product_id'], '_wc_mmax_prd_opt_enable', true);
  $minQty = get_post_meta($product['product_id'], '_wc_mmax_min', true);
  $maxQty = get_post_meta($product['product_id'], '_wc_mmax_max', true);
  if ($maxQty > 0 && $maxQty < $quantity && $mmaxEnable == 1) {
    wc_add_notice('Sie haben mehr als die Zugelassene Menge im Warenkorb.', 'error');
    return false;
  }
  if ($minQty > 0 && $quantity < $minQty && $mmaxEnable == 1) {
    wc_add_notice('Sie wollten ' . $quantity . ' Stück bestellen, dies liegt jedoch unterhalb der Mindestbestellmenge von ' . $minQty . ' Stück', 'error');
    return false;
  }

  return $valid;
}, 10, 4);

/*Function to check weather the maximum quantity is already existing in the cart*/
add_action('woocommerce_add_to_cart', function ($args, $product) {
  $mmaxEnable = get_post_meta($product, '_wc_mmax_prd_opt_enable', true);
  $minQty = get_post_meta($product, '_wc_mmax_min', true);
  $maxQty = get_post_meta($product, '_wc_mmax_max', true);
  $cartQty = wc_mmax_woo_in_cart($product);
  if ($maxQty > 0 && $maxQty < $cartQty && $mmaxEnable == 1) {
    wc_add_notice('Sie haben mehr als die Zugelassene Menge im Warenkorb.', 'error');
    exit(wp_redirect(get_permalink($product)));
  }
  if ($minQty > 0 && $cartQty < $minQty && $mmaxEnable == 1) {
    wc_add_notice('Sie wollten ' . $cartQty . ' Stück bestellen, dies liegt jedoch unterhalb der Mindestbestellmenge von ' . $minQty . ' Stück', 'error');
    exit(wp_redirect(get_permalink($product)));
  }
}, 10, 2);

function wc_mmax_woo_in_cart($product_id)
{
  global $woocommerce;
  foreach ($woocommerce->cart->get_cart() as $key => $val) {
    $_product = $val['data'];
    if ($product_id == $_product->get_id()) {
      return $val['quantity'];
    }
  }

  return 0;
}
