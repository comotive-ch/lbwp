<?php

namespace LBWP\Aboon\AgenticCommerce;

use LBWP\Aboon\AgenticCommerce\Enum\Message;
use WC_Order;
use WC_Order_Item_Product;

/**
 * A checkout session on top of a WooCommerce checkout-draft order. Holds the protocol neutral
 * state (buyer, fulfillment, selected option, coupons) in order meta and runs all shop logic:
 * item resolution, pricing, shipping enumeration and readiness checks.
 * @package LBWP\Aboon\AgenticCommerce
 * @author Mirko Baffa <mirko@comotive.ch>
 */
class Session
{
  public const string STATUS_INCOMPLETE = 'incomplete';
  public const string STATUS_READY = 'ready';
  public const string STATUS_COMPLETING = 'completing';
  public const string STATUS_COMPLETED = 'completed';
  public const string STATUS_CANCELED = 'canceled';

  public const string META_PROTOCOL = '_agentic_protocol';
  public const string META_SESSION_ID = '_agentic_session_id';
  public const string META_STATE = '_agentic_session_state';
  public const string META_PLATFORM = '_agentic_platform';
  public const string META_PAYMENT_TXN = '_agentic_payment_txn';
  public const string META_ITEM_ID = '_agentic_item_id';

  /**
   * @var WC_Order the underlying order
   */
  protected WC_Order $order;
  /**
   * @var array the state
   */
  protected array $state;
  /**
   * @var array neutral messages from the last recalculation
   */
  protected array $messages = [];

  /**
   * @param WC_Order $order the draft order
   */
  public function __construct(WC_Order $order)
  {
    $this->order = $order;
    $state = json_decode((string) $order->get_meta(self::META_STATE), true);
    $this->state = is_array($state) ? $state : [];
  }

  /**
   * @return string public session id
   */
  public function getId(): string
  {
    return (string) $this->order->get_meta(self::META_SESSION_ID);
  }

  /**
   * @return string acp|ucp
   */
  public function getProtocol(): string
  {
    return (string) $this->order->get_meta(self::META_PROTOCOL);
  }

  /**
   * @return WC_Order the order
   */
  public function getOrder(): WC_Order
  {
    return $this->order;
  }

  /**
   * @return array the state
   */
  public function getState(): array
  {
    return $this->state;
  }

  /**
   * Reads a state value.
   * @param string $key key
   * @param mixed $default default
   * @return mixed value
   */
  public function get(string $key, mixed $default = null): mixed
  {
    return $this->state[$key] ?? $default;
  }

  /**
   * Writes a state value (not persisted until save()).
   * @param string $key key
   * @param mixed $value value
   * @return void
   */
  public function set(string $key, mixed $value): void
  {
    $this->state[$key] = $value;
  }

  /**
   * Replaces the whole state.
   * @param array $state new state
   * @return void
   */
  public function setState(array $state): void
  {
    $this->state = $state;
  }

  /**
   * @return string neutral status
   */
  public function getStatus(): string
  {
    return (string) $this->get('status', self::STATUS_INCOMPLETE);
  }

  /**
   * @return array messages of the last recalculate()
   */
  public function getMessages(): array
  {
    return $this->messages;
  }

  /**
   * Adds a message to the current list.
   * @param array $message neutral message
   * @return void
   */
  public function addMessage(array $message): void
  {
    $this->messages[] = $message;
  }

  /**
   * Persists state and order.
   * @return void
   */
  public function save(): void
  {
    $this->order->update_meta_data(self::META_STATE, wp_json_encode($this->state));
    $this->order->save();
  }

  /**
   * Replaces all line items with the requested ones.
   * @param array $items [['id' => string, 'quantity' => int], …]
   * @return array neutral messages for rejected items
   */
  public function setLineItems(array $items): array
  {
    $messages = [];
    // remove_order_items() is deferred since WooCommerce 11 and would also wipe the items added below
    foreach ($this->order->get_items('line_item') as $existingId => $existing) {
      $this->order->remove_item($existingId);
    }
    $lang = (string) $this->get('lang', '');
    foreach (array_values($items) as $index => $item) {
      $itemId = sanitize_text_field((string) ($item['id'] ?? ''));
      $quantity = max(0, (int) ($item['quantity'] ?? 0));
      $path = '$.line_items[' . $index . ']';
      $product = Catalog::resolveItem($itemId, $lang);
      if ($product === null || !Catalog::isEligible($product)) {
        $messages[] = Message::error(Message::INVALID, sprintf(__('Artikel %s ist nicht verfügbar.', 'lbwp'), $itemId), $path . '.id');
        continue;
      }
      if ($quantity < 1) {
        $messages[] = Message::error(Message::INVALID, __('Ungültige Menge.', 'lbwp'), $path . '.quantity');
        continue;
      }
      $availability = Catalog::availabilityStatus($product);
      $available = Catalog::availableQuantity($product);
      if ($availability === Catalog::OUT_OF_STOCK || ($available !== null && $available < $quantity && !$product->backorders_allowed())) {
        $messages[] = Message::error(Message::OUT_OF_STOCK, sprintf(__('%s ist nicht in der gewünschten Menge verfügbar.', 'lbwp'), $product->get_name()), $path . '.quantity');
        continue;
      }
      $max = Catalog::maxQuantity($product);
      if ($quantity > $max) {
        $messages[] = Message::error(Message::QUANTITY_EXCEEDED, sprintf(__('Maximal %d Stück pro Bestellung.', 'lbwp'), $max), $path . '.quantity');
        continue;
      }
      $step = Catalog::quantityStep($product);
      if ($step > 1 && $quantity % $step !== 0) {
        $messages[] = Message::error(Message::INVALID, sprintf(__('Nur in Verpackungseinheiten von %d bestellbar.', 'lbwp'), $step), $path . '.quantity');
        continue;
      }
      $lineId = $this->order->add_product($product, $quantity);
      if ($lineId > 0) {
        wc_update_order_item_meta($lineId, self::META_ITEM_ID, $itemId);
      }
      if ($availability === Catalog::LOW_STOCK) {
        $messages[] = Message::warning(Message::LOW_STOCK, sprintf(__('%s ist nur noch in geringer Stückzahl verfügbar.', 'lbwp'), $product->get_name()), $path);
      }
    }
    $this->messages = array_merge($this->messages, $messages);
    return $messages;
  }

  /**
   * @return bool whether the order has at least one line item
   */
  public function hasItems(): bool
  {
    return count($this->order->get_items('line_item')) > 0;
  }

  /**
   * Stores buyer data (first_name, last_name, email, phone) and sets the billing contact.
   * @param array $buyer buyer data
   * @return void
   */
  public function setBuyer(array $buyer): void
  {
    $current = (array) $this->get('buyer', []);
    foreach (['first_name', 'last_name', 'email', 'phone'] as $key) {
      if (array_key_exists($key, $buyer)) {
        $current[$key] = $key === 'email' ? sanitize_email((string) $buyer[$key]) : sanitize_text_field((string) $buyer[$key]);
      }
    }
    $this->set('buyer', $current);
    if (!empty($current['email']) && is_email($current['email'])) {
      $this->order->set_billing_email($current['email']);
    }
    if (!empty($current['phone'])) {
      $this->order->set_billing_phone($current['phone']);
    }
    if (!empty($current['first_name'])) {
      $this->order->set_billing_first_name($current['first_name']);
    }
    if (!empty($current['last_name'])) {
      $this->order->set_billing_last_name($current['last_name']);
    }
  }

  /**
   * Stores the fulfillment address (neutral keys: name, line_one, line_two, city, state, country,
   * postal_code) and applies it to the shipping address; billing falls back to it.
   * @param array $address neutral address
   * @return void
   */
  public function setFulfillmentAddress(array $address): void
  {
    $clean = [];
    foreach (['id', 'name', 'line_one', 'line_two', 'city', 'state', 'country', 'postal_code', 'email', 'phone'] as $key) {
      $clean[$key] = sanitize_text_field((string) ($address[$key] ?? ''));
    }
    $clean['country'] = strtoupper($clean['country']);
    $this->set('fulfillment', $clean);
    [$first, $last] = self::splitName($clean['name']);
    $wc = [
      'first_name' => $first,
      'last_name' => $last,
      'address_1' => $clean['line_one'],
      'address_2' => $clean['line_two'],
      'city' => $clean['city'],
      'state' => $clean['state'],
      'postcode' => $clean['postal_code'],
      'country' => $clean['country'],
    ];
    $this->order->set_shipping_address($wc);
    if ($this->order->get_billing_address_1() === '') {
      $billing = $wc;
      if ($this->order->get_billing_first_name() !== '') {
        unset($billing['first_name'], $billing['last_name']);
      }
      $this->order->set_billing_address($billing);
    }
    if ($clean['email'] !== '' && is_email($clean['email']) && $this->order->get_billing_email() === '') {
      $this->order->set_billing_email($clean['email']);
    }
    if ($clean['phone'] !== '' && $this->order->get_billing_phone() === '') {
      $this->order->set_billing_phone($clean['phone']);
    }
    $this->set('selected_option', $this->get('selected_option'));
  }

  /**
   * Stores an explicit billing address (neutral keys).
   * @param array $address neutral address
   * @return void
   */
  public function setBillingAddress(array $address): void
  {
    if (count($address) === 0) {
      return;
    }
    [$first, $last] = self::splitName(sanitize_text_field((string) ($address['name'] ?? '')));
    $wc = [
      'address_1' => sanitize_text_field((string) ($address['line_one'] ?? '')),
      'address_2' => sanitize_text_field((string) ($address['line_two'] ?? '')),
      'city' => sanitize_text_field((string) ($address['city'] ?? '')),
      'state' => sanitize_text_field((string) ($address['state'] ?? '')),
      'postcode' => sanitize_text_field((string) ($address['postal_code'] ?? '')),
      'country' => strtoupper(sanitize_text_field((string) ($address['country'] ?? ''))),
    ];
    if ($first !== '') {
      $wc['first_name'] = $first;
      $wc['last_name'] = $last;
    }
    $this->order->set_billing_address($wc);
    $this->set('billing_address', $address);
  }

  /**
   * Selects a fulfillment option by id (validated during recalculate()).
   * @param string $optionId option id
   * @return void
   */
  public function selectFulfillmentOption(string $optionId): void
  {
    $this->set('selected_option', sanitize_text_field($optionId));
  }

  /**
   * Stores coupon codes (applied during recalculate()).
   * @param array $codes coupon codes
   * @return void
   */
  public function setCoupons(array $codes): void
  {
    $this->set('coupons', array_values(array_filter(array_map(fn($c) => sanitize_text_field((string) $c), $codes), 'strlen')));
  }

  /**
   * Recalculates coupons, taxes, fulfillment options, the selected option and totals, and
   * rebuilds the message list.
   * @return void
   */
  public function recalculate(): void
  {
    $messages = array_values(array_filter($this->messages, fn($m) => ($m['code'] ?? '') !== Message::MISSING && ($m['code'] ?? '') !== Message::REGION_RESTRICTED && ($m['code'] ?? '') !== Message::MAXIMUM_EXCEEDED && !str_starts_with((string) ($m['path'] ?? ''), '$.coupons')));
    if (!$this->hasItems()) {
      $this->messages = $messages;
      $this->set('fulfillment_options', []);
      return;
    }
    foreach (Discounts::apply($this->order, (array) $this->get('coupons', [])) as $rejected) {
      $messages[] = $rejected['message'];
    }
    Pricing::refresh($this->order);
    $options = Shipping::options($this->order, $messages);
    $this->set('fulfillment_options', $options);
    $selected = (string) $this->get('selected_option', '');
    $option = $selected !== '' ? Shipping::find($options, $selected) : null;
    if ($option === null && count($options) === 1 && ($options[0]['type'] ?? '') === Shipping::TYPE_DIGITAL) {
      $option = $options[0];
    }
    if ($option === null) {
      foreach ($this->order->get_items('shipping') as $itemId => $item) {
        $this->order->remove_item($itemId);
      }
      $this->set('selected_option', '');
      if (count($options) > 0) {
        $messages[] = Message::error(Message::MISSING, __('Bitte eine Versandoption wählen.', 'lbwp'), '$.selected_fulfillment_options');
      }
    } else {
      Shipping::apply($this->order, $option);
      $this->set('selected_option', $option['id']);
    }
    Pricing::refresh($this->order);
    $max = Settings::maxOrderTotal();
    if ($max > 0 && (float) $this->order->get_total() > $max) {
      $messages[] = Message::error(Message::MAXIMUM_EXCEEDED, sprintf(__('Der maximale Bestellwert von %s ist überschritten.', 'lbwp'), wc_price($max)), '$.line_items');
    }
    $this->messages = $messages;
  }

  /**
   * @return bool whether the session may proceed to payment
   */
  public function isReadyForPayment(): bool
  {
    if (!$this->hasItems() || Message::hasBlocking($this->messages)) {
      return false;
    }
    if (in_array($this->getStatus(), [self::STATUS_COMPLETED, self::STATUS_CANCELED, self::STATUS_COMPLETING], true)) {
      return false;
    }
    $needsShipping = !Shipping::isDigitalOnly($this->order);
    if ($needsShipping && ($this->order->get_shipping_country() === '' || (string) $this->get('selected_option', '') === '')) {
      return false;
    }
    return true;
  }

  /**
   * Recomputes and stores the neutral status (does not override completed/canceled/completing).
   * @return string the status
   */
  public function refreshStatus(): string
  {
    $status = $this->getStatus();
    if (in_array($status, [self::STATUS_COMPLETED, self::STATUS_CANCELED, self::STATUS_COMPLETING], true)) {
      return $status;
    }
    $status = $this->isReadyForPayment() ? self::STATUS_READY : self::STATUS_INCOMPLETE;
    $this->set('status', $status);
    return $status;
  }

  /**
   * Marks the session as completing (payment in progress).
   * @return void
   */
  public function markCompleting(): void
  {
    $this->set('status', self::STATUS_COMPLETING);
    $this->save();
  }

  /**
   * Reverts a completing session to ready (payment failed).
   * @return void
   */
  public function markReady(): void
  {
    $this->set('status', self::STATUS_READY);
    $this->save();
  }

  /**
   * Marks the session as completed and re-binds the (now finalised) order.
   * @param WC_Order $order the finalised order
   * @return void
   */
  public function markCompleted(WC_Order $order): void
  {
    $this->order = $order;
    $this->set('status', self::STATUS_COMPLETED);
    $this->set('fulfillment_options', []);
    $this->save();
  }

  /**
   * Cancels the session (draft order becomes cancelled).
   * @param string $reason optional reason for the order note
   * @return void
   */
  public function cancel(string $reason = ''): void
  {
    $this->set('status', self::STATUS_CANCELED);
    $this->save();
    $this->order->update_status('cancelled', $reason !== '' ? 'Agentic: ' . $reason : 'Agentic: Session abgebrochen');
  }

  /**
   * Returns the line items with product, requested item id and quantity.
   * @return array [['item' => WC_Order_Item_Product, 'product' => WC_Product|null, 'item_id' => string], …]
   */
  public function getLineItems(): array
  {
    $lines = [];
    foreach ($this->order->get_items('line_item') as $item) {
      if (!$item instanceof WC_Order_Item_Product) {
        continue;
      }
      $lines[] = [
        'item' => $item,
        'product' => $item->get_product() ?: null,
        'item_id' => (string) ($item->get_meta(self::META_ITEM_ID) ?: $item->get_variation_id() ?: $item->get_product_id()),
      ];
    }
    return $lines;
  }

  /**
   * Returns the selected option array or null.
   * @return array|null option
   */
  public function getSelectedOption(): ?array
  {
    $selected = (string) $this->get('selected_option', '');
    return $selected === '' ? null : Shipping::find((array) $this->get('fulfillment_options', []), $selected);
  }

  /**
   * Splits a full name into first and last name.
   * @param string $name full name
   * @return array [first, last]
   */
  public static function splitName(string $name): array
  {
    $name = trim($name);
    if ($name === '') {
      return ['', ''];
    }
    $parts = explode(' ', $name, 2);
    return [$parts[0], $parts[1] ?? ''];
  }
}
