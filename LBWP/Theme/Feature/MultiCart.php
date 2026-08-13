<?php

namespace LBWP\Theme\Feature;

use LBWP\Util\File;

/**
 * Allows logged-in WooCommerce users to manage multiple named carts,
 * switching between them via AJAX-powered UI on the cart page.
 */
class MultiCart {

  /**
   * @var bool whether the switcher UI has already been printed in this request
   */
  protected bool $switcherRendered = false;

  /**
   * Register the init hook.
   */
  public function __construct() {
    add_action('init', [$this, 'init']);
  }

  /**
   * Register WooCommerce hooks and AJAX handlers if the user is logged in.
   */
  public function init() {
    if (!is_user_logged_in() || !function_exists('WC')) {
      return;
    }

    add_action('woocommerce_before_cart', [$this, 'renderCartSwitcher']);
    add_action('woocommerce_cart_is_empty', [$this, 'renderCartSwitcher']);
    add_action('wp_ajax_multicart_create', [$this, 'ajaxCreateCart']);
    add_action('wp_ajax_multicart_switch', [$this, 'ajaxSwitchCart']);
    add_action('wp_ajax_multicart_rename', [$this, 'ajaxRenameCart']);
    add_action('wp_ajax_multicart_delete', [$this, 'ajaxDeleteCart']);
    add_action('wp_enqueue_scripts', [$this, 'enqueueAssets']);
  }

  /**
   * Retrieve all saved carts for a user from user meta.
   */
  protected function getSavedCarts(int $userId): array {
    $carts = get_user_meta($userId, 'lbwp_multi_carts', true);
    return is_array($carts) ? $carts : [];
  }

  /**
   * Persist the carts array to user meta.
   */
  protected function saveCarts(int $userId, array $carts): void {
    update_user_meta($userId, 'lbwp_multi_carts', $carts);
  }

  /**
   * Snapshot the current WooCommerce session cart into a serializable array.
   */
  protected function captureCurrentCart(): array {
    $items = [];
    foreach (WC()->cart->get_cart() as $cartItem) {
      $items[] = [
        'product_id'     => $cartItem['product_id'],
        'variation_id'   => $cartItem['variation_id'],
        'quantity'       => $cartItem['quantity'],
        'variation'      => $cartItem['variation'],
        'cart_item_data' => [],
      ];
    }
    return $items;
  }

  /**
   * Replace the WooCommerce session cart with the given items.
   */
  protected function loadCartIntoSession(array $items): void {
    WC()->cart->empty_cart();
    foreach ($items as $item) {
      WC()->cart->add_to_cart(
        $item['product_id'],
        $item['quantity'],
        $item['variation_id'],
        $item['variation'],
        $item['cart_item_data']
      );
    }
  }

  /**
   * Get the ID of the user's currently active cart.
   */
  protected function getActiveCartId(int $userId): string {
    return (string) get_user_meta($userId, 'lbwp_active_cart_id', true);
  }

  /**
   * Store the ID of the user's currently active cart.
   */
  protected function setActiveCartId(int $userId, string $cartId): void {
    update_user_meta($userId, 'lbwp_active_cart_id', $cartId);
  }

  /**
   * Generate a unique cart identifier based on the current timestamp.
   */
  protected function generateCartId(): string {
    return 'cart_' . time();
  }

  /**
   * Ensure the user has at least one saved cart. If none exist yet, create a
   * default one that captures whatever is currently in the WC session.
   */
  protected function ensureDefaultCart(int $userId): array {
    $carts = $this->getSavedCarts($userId);

    if (empty($carts)) {
      $cartId = $this->generateCartId();
      $carts  = [
        [
          'id'    => $cartId,
          'name'  => __('Warenkorb 1', 'lbwp'),
          'items' => $this->captureCurrentCart(),
        ],
      ];
      $this->saveCarts($userId, $carts);
      $this->setActiveCartId($userId, $cartId);
    }

    return $carts;
  }

  /**
   * Output the cart switcher UI with buttons for each saved cart. Both
   * woocommerce_before_cart and woocommerce_cart_is_empty can fire in the same
   * request (empty cart in some themes), so only the first call prints.
   */
  public function renderCartSwitcher(): void {
    if ($this->switcherRendered) {
      return;
    }
    $this->switcherRendered = true;

    $userId   = get_current_user_id();
    $carts    = $this->ensureDefaultCart($userId);
    $activeId = $this->getActiveCartId($userId);

    echo '<div class="multicart-switcher">';

    foreach ($carts as $cart) {
      $isActive  = ($cart['id'] === $activeId);
      $btnClass  = 'multicart-btn' . ($isActive ? ' multicart-btn--active' : '');
      $cartId    = esc_attr($cart['id']);
      $cartName  = esc_html($cart['name']);

      echo '<button class="' . $btnClass . '" data-cart-id="' . $cartId . '">';
      echo '<span class="multicart-btn__label">' . $cartName . '</span>';
      echo '<span class="multicart-btn__rename">&#9998;</span>';
      echo '<span class="multicart-btn__delete">&#x2715;</span>';
      echo '</button>';
    }

    echo '<button class="multicart-new">' . esc_html__('+ Neuer Warenkorb erstellen', 'lbwp') . '</button>';
    echo '</div>';
  }

  /**
   * Enqueue the multicart JavaScript and localize AJAX data on the cart page.
   */
  public function enqueueAssets(): void {
    if (!is_cart()) {
      return;
    }

    $base = File::getResourceUri();
    wp_enqueue_script('multicart', $base . '/js/multicart/multicart.js', ['jquery'], false, true);
    wp_localize_script('multicart', 'multicartData', [
      'nonce'   => wp_create_nonce('multicart_nonce'),
      'ajaxUrl' => admin_url('admin-ajax.php'),
      'i18n'    => [
        'promptRename'  => __('Neuer Name:', 'lbwp'),
        'confirmDelete' => __('Warenkorb löschen?', 'lbwp'),
        'errorDelete'   => __('Fehler beim Löschen.', 'lbwp'),
        'promptNew'     => __('Name des neuen Warenkorbs:', 'lbwp'),
        'defaultName'   => __('Warenkorb', 'lbwp'),
      ],
    ]);
  }

  /**
   * AJAX handler: create a new empty cart and switch to it.
   */
  public function ajaxCreateCart(): void {
    check_ajax_referer('multicart_nonce', 'nonce');

    if (!is_user_logged_in()) {
      wp_send_json_error(__('Nicht eingeloggt.', 'lbwp'));
    }

    $userId = get_current_user_id();
    $name   = sanitize_text_field($_POST['name'] ?? '');
    $carts  = $this->ensureDefaultCart($userId);

    // Save current WC cart into the currently active slot
    $activeId = $this->getActiveCartId($userId);
    foreach ($carts as &$cart) {
      if ($cart['id'] === $activeId) {
        $cart['items'] = $this->captureCurrentCart();
        break;
      }
    }
    unset($cart);

    if (!$name) {
      $name = sprintf(__('Warenkorb %d', 'lbwp'), count($carts) + 1);
    }

    $newId   = $this->generateCartId();
    $carts[] = [
      'id'    => $newId,
      'name'  => $name,
      'items' => [],
    ];

    $this->saveCarts($userId, $carts);
    $this->setActiveCartId($userId, $newId);
    WC()->cart->empty_cart();

    wc_add_notice(sprintf(__('Neuer Warenkorb &ldquo;%s&rdquo; wurde erstellt.', 'lbwp'), esc_html($name)), 'success');

    wp_send_json_success(['id' => $newId, 'name' => $name]);
  }

  /**
   * AJAX handler: persist the current cart and switch to a different one.
   */
  public function ajaxSwitchCart(): void {
    check_ajax_referer('multicart_nonce', 'nonce');

    if (!is_user_logged_in()) {
      wp_send_json_error(__('Nicht eingeloggt.', 'lbwp'));
    }

    $userId    = get_current_user_id();
    $targetId  = sanitize_text_field($_POST['cart_id'] ?? '');
    $carts     = $this->ensureDefaultCart($userId);
    $activeId  = $this->getActiveCartId($userId);
    $targetCart = null;

    // Persist current cart contents
    foreach ($carts as &$cart) {
      if ($cart['id'] === $activeId) {
        $cart['items'] = $this->captureCurrentCart();
      }
      if ($cart['id'] === $targetId) {
        $targetCart = &$cart;
      }
    }
    unset($cart);

    if ($targetCart === null) {
      wp_send_json_error(__('Warenkorb nicht gefunden.', 'lbwp'));
    }

    $this->saveCarts($userId, $carts);
    $this->setActiveCartId($userId, $targetId);
    $this->loadCartIntoSession($targetCart['items']);

    wc_add_notice(__('Warenkorb wurde gewechselt.', 'lbwp'), 'success');

    wp_send_json_success();
  }

  /**
   * AJAX handler: rename an existing cart.
   */
  public function ajaxRenameCart(): void {
    check_ajax_referer('multicart_nonce', 'nonce');

    if (!is_user_logged_in()) {
      wp_send_json_error(__('Nicht eingeloggt.', 'lbwp'));
    }

    $userId   = get_current_user_id();
    $cartId   = sanitize_text_field($_POST['cart_id'] ?? '');
    $newName  = sanitize_text_field($_POST['name'] ?? '');
    $carts    = $this->getSavedCarts($userId);
    $found    = false;

    foreach ($carts as &$cart) {
      if ($cart['id'] === $cartId) {
        $cart['name'] = $newName;
        $found = true;
        break;
      }
    }
    unset($cart);

    if (!$found) {
      wp_send_json_error(__('Warenkorb nicht gefunden.', 'lbwp'));
    }

    $this->saveCarts($userId, $carts);
    wp_send_json_success();
  }

  /**
   * AJAX handler: delete a cart and switch to the first remaining one if needed.
   */
  public function ajaxDeleteCart(): void {
    check_ajax_referer('multicart_nonce', 'nonce');

    if (!is_user_logged_in()) {
      wp_send_json_error(__('Nicht eingeloggt.', 'lbwp'));
    }

    $userId   = get_current_user_id();
    $cartId   = sanitize_text_field($_POST['cart_id'] ?? '');
    $carts    = $this->getSavedCarts($userId);

    if (count($carts) <= 1) {
      wp_send_json_error(__('Der letzte Warenkorb kann nicht gelöscht werden.', 'lbwp'));
    }

    $activeId      = $this->getActiveCartId($userId);
    $deletedActive = ($cartId === $activeId);
    $carts         = array_values(array_filter($carts, fn($c) => $c['id'] !== $cartId));

    $reloaded = false;

    if ($deletedActive) {
      $firstCart = $carts[0];
      $this->setActiveCartId($userId, $firstCart['id']);
      $this->loadCartIntoSession($firstCart['items']);
      $reloaded = true;
    }

    $this->saveCarts($userId, $carts);
    wp_send_json_success(['reloaded' => $reloaded]);
  }
}
