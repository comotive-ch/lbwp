<?php

namespace LBWP\Theme\Feature;

use LBWP\Util\File;

class MultiCart {

  public function __construct() {
    add_action('init', [$this, 'init']);
  }

  public function init() {
    if (!is_user_logged_in() || !function_exists('WC')) {
      return;
    }

    add_action('woocommerce_before_cart', [$this, 'renderCartSwitcher']);
    add_action('wp_ajax_multicart_create', [$this, 'ajaxCreateCart']);
    add_action('wp_ajax_multicart_switch', [$this, 'ajaxSwitchCart']);
    add_action('wp_ajax_multicart_rename', [$this, 'ajaxRenameCart']);
    add_action('wp_ajax_multicart_delete', [$this, 'ajaxDeleteCart']);
    add_action('wp_enqueue_scripts', [$this, 'enqueueAssets']);
  }

  // -------------------------------------------------------------------------
  // Core helpers
  // -------------------------------------------------------------------------

  protected function getSavedCarts(int $userId): array {
    $carts = get_user_meta($userId, 'lbwp_multi_carts', true);
    return is_array($carts) ? $carts : [];
  }

  protected function saveCarts(int $userId, array $carts): void {
    update_user_meta($userId, 'lbwp_multi_carts', $carts);
  }

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

  protected function getActiveCartId(int $userId): string {
    return (string) get_user_meta($userId, 'lbwp_active_cart_id', true);
  }

  protected function setActiveCartId(int $userId, string $cartId): void {
    update_user_meta($userId, 'lbwp_active_cart_id', $cartId);
  }

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

  // -------------------------------------------------------------------------
  // UI
  // -------------------------------------------------------------------------

  public function renderCartSwitcher(): void {
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

    echo '<button class="multicart-new">+ Neuer Warenkorb erstellen</button>';
    echo '</div>';
  }

  // -------------------------------------------------------------------------
  // Assets
  // -------------------------------------------------------------------------

  public function enqueueAssets(): void {
    if (!is_cart()) {
      return;
    }

    $base = File::getResourceUri();
    wp_enqueue_script('multicart', $base . '/js/multicart/multicart.js', [], false, true);
    wp_localize_script('multicart', 'multicartData', [
      'nonce'   => wp_create_nonce('multicart_nonce'),
      'ajaxUrl' => admin_url('admin-ajax.php'),
    ]);
  }

  // -------------------------------------------------------------------------
  // AJAX handlers
  // -------------------------------------------------------------------------

  public function ajaxCreateCart(): void {
    check_ajax_referer('multicart_nonce', 'nonce');

    if (!is_user_logged_in()) {
      wp_send_json_error('Nicht eingeloggt.');
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
      $name = 'Warenkorb ' . (count($carts) + 1);
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

    wp_send_json_success(['id' => $newId, 'name' => $name]);
  }

  public function ajaxSwitchCart(): void {
    check_ajax_referer('multicart_nonce', 'nonce');

    if (!is_user_logged_in()) {
      wp_send_json_error('Nicht eingeloggt.');
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
      wp_send_json_error('Warenkorb nicht gefunden.');
    }

    $this->saveCarts($userId, $carts);
    $this->setActiveCartId($userId, $targetId);
    $this->loadCartIntoSession($targetCart['items']);

    wp_send_json_success();
  }

  public function ajaxRenameCart(): void {
    check_ajax_referer('multicart_nonce', 'nonce');

    if (!is_user_logged_in()) {
      wp_send_json_error('Nicht eingeloggt.');
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
      wp_send_json_error('Warenkorb nicht gefunden.');
    }

    $this->saveCarts($userId, $carts);
    wp_send_json_success();
  }

  public function ajaxDeleteCart(): void {
    check_ajax_referer('multicart_nonce', 'nonce');

    if (!is_user_logged_in()) {
      wp_send_json_error('Nicht eingeloggt.');
    }

    $userId   = get_current_user_id();
    $cartId   = sanitize_text_field($_POST['cart_id'] ?? '');
    $carts    = $this->getSavedCarts($userId);

    if (count($carts) <= 1) {
      wp_send_json_error('Der letzte Warenkorb kann nicht gelöscht werden.');
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
