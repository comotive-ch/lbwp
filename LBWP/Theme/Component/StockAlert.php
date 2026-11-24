<?php

namespace LBWP\Theme\Component;

use LBWP\Module\Frontend\HTMLCache;
use LBWP\Util\WordPress;
use WC_Product;
use WP_Post;
use WP_Query;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Back in stock alerts for WooCommerce products (port of the stock part of addify "Back in Stock and Price Alert").
 * Visitors subscribe with their email through a REST route (works on HTML cached pages), the subscription list and
 * the settings live in the backend. Mails are only sent through notify(), called by the shop's inventory import.
 * Reuses the plugin's post type "bis_stock_alert" (content = email, parent = product, meta bis_status) and its
 * unsubscribe shortcode, its settings are copied once into the ACF options of this component.
 * @package LBWP\Theme\Component
 * @author Michael Sebel <michael@comotive.ch>
 */
class StockAlert extends ACFBase
{
  /**
   * Post type of the subscriptions (from the plugin)
   */
  const string POST_TYPE = 'bis_stock_alert';
  /**
   * Title of every subscription post (from the plugin)
   */
  const string POST_TITLE = 'restocked';
  /**
   * Meta key of the subscription status
   */
  const string META_STATUS = 'bis_status';
  /**
   * Meta key of the time the back in stock mail was sent
   */
  const string META_NOTIFIED = 'bis_notified';
  /**
   * Status values, as stored by the plugin and the legacy inventory import
   */
  const string STATUS_SUBSCRIBED = 'subscribed';
  const string STATUS_UNSUBSCRIBED = 'Unsubscribed';
  const string STATUS_SENT = 'mailsent';
  /**
   * Slug of the settings page
   */
  const string SETTINGS_SLUG = 'lbwp-stock-alert';
  /**
   * Prefix of the ACF option names
   */
  const string OPTION_PREFIX = 'stock_alert_';
  /**
   * Option that marks the one time copy of the plugin settings
   */
  const string MIGRATION_OPTION = 'lbwp_stock_alert_migrated';
  /**
   * REST namespace and route of the subscription
   */
  const string REST_NAMESPACE = 'lbwp/stock-alert';
  const string REST_ROUTE = 'subscribe';
  /**
   * Shortcode of the unsubscribe page (from the plugin, its page "bis-unsub" is reused)
   */
  const string UNSUBSCRIBE_SHORTCODE = 'bis_unsub_page';
  /**
   * Query parameter of the unsubscribe link (from the plugin)
   */
  const string UNSUBSCRIBE_PARAM = 'subscription_id';
  /**
   * Name of the honeypot field of the form, filled only by bots
   */
  const string HONEYPOT_FIELD = 'website';
  /**
   * Mail types
   */
  const string MAIL_CONFIRM = 'confirm';
  const string MAIL_RESTOCK = 'restock';
  /**
   * Plain plugin options => option name of this component
   */
  const array LEGACY_OPTIONS = [
    'bis_restocked_alert_checkbox' => 'active',
    'bis_subscription_backorders_checkbox' => 'backorders',
    'bis_input_title' => 'title',
    'bis_input_placeholder' => 'placeholder',
    'bis_button_text' => 'button',
    'bis_success_message' => 'success',
    'bis_error_message' => 'already',
    'bis_unsub_message' => 'unsubscribed',
    'bis-unsub-page-id' => 'unsubscribe_page',
    'bis_admin_message_price' => 'confirm_body',
    'bis_message_cus_back_in_stock' => 'restock_body'
  ];
  /**
   * WooCommerce email settings of the plugin => mail type of this component
   */
  const array LEGACY_MAILS = [
    'woocommerce_addify_bis_stock_subscribe_email_settings' => self::MAIL_CONFIRM,
    'woocommerce_addify_bis_restocked_alert_settings' => self::MAIL_RESTOCK
  ];
  /**
   * Options that hold a yes/no checkbox in the plugin
   */
  const array LEGACY_CHECKBOXES = ['active', 'backorders', 'confirm_active'];

  /**
   * Registers the post type, the hooks and copies the plugin settings once
   * @return void
   */
  public function init(): void
  {
    $this->migrateLegacySettings();
    $this->initPostType();
    add_action('rest_api_init', [$this, 'registerRoutes']);
    add_shortcode(self::UNSUBSCRIBE_SHORTCODE, [$this, 'renderUnsubscribe']);

    if (is_admin()) {
      $this->initAdminList();
    }
  }

  /**
   * Whether a product can be subscribed right now (alerts active, stock managed and not in stock)
   * @param WC_Product|null $product the product
   * @return bool
   */
  public static function isAvailableFor(?WC_Product $product): bool
  {
    if (!$product instanceof WC_Product || !self::isActive() || !$product->managing_stock()) {
      return false;
    }

    $status = $product->get_stock_status();
    return $status === 'outofstock' || ($status === 'onbackorder' && (bool) self::get('backorders'));
  }

  /**
   * Whether the alerts are switched on
   * @return bool
   */
  public static function isActive(): bool
  {
    return (bool) self::get('active');
  }

  /**
   * Texts and url a theme needs to render the subscription form
   * @return array title, placeholder, button, url, honeypot (field name)
   */
  public static function getFormConfig(): array
  {
    return [
      'title' => (string) self::get('title'),
      'placeholder' => (string) self::get('placeholder'),
      'button' => (string) self::get('button'),
      'url' => rest_url(self::REST_NAMESPACE . '/' . self::REST_ROUTE),
      'honeypot' => self::HONEYPOT_FIELD
    ];
  }

  /**
   * Sends the back in stock mail to all subscribers of a product and marks them as notified. Call it after the
   * product was saved as restocked, it doesn't check the stock itself. Always purges the product's HTML cache,
   * so the page doesn't show the subscription form anymore.
   * @param int $productId the restocked product (or variation)
   * @return int number of mails sent
   */
  public static function notify(int $productId): int
  {
    HTMLCache::cleanPostHtmlCache(wp_get_post_parent_id($productId) ?: $productId);
    if (!self::isActive()) {
      return 0;
    }

    $subscribers = self::getSubscribers($productId);
    $product = wc_get_product($productId);
    if (count($subscribers) === 0 || !$product instanceof WC_Product) {
      return 0;
    }

    $sent = 0;
    foreach ($subscribers as $subscriptionId => $email) {
      $sent += self::sendMail(self::MAIL_RESTOCK, $subscriptionId, $email, $product) ? 1 : 0;
      update_post_meta($subscriptionId, self::META_STATUS, self::STATUS_SENT);
      update_post_meta($subscriptionId, self::META_NOTIFIED, current_time('mysql'));
    }
    return $sent;
  }

  /**
   * Subscribes an email to a product, or renews an unsubscribed / notified subscription, and sends the confirmation
   * @param int $productId the product
   * @param string $email sanitized email address
   * @return bool false if the email is already subscribed to the product
   */
  public static function subscribe(int $productId, string $email): bool
  {
    [$subscriptionId, $status] = self::findSubscription($productId, $email);
    if ($status === self::STATUS_SUBSCRIBED) {
      return false;
    }

    if ($subscriptionId > 0) {
      update_post_meta($subscriptionId, self::META_STATUS, self::STATUS_SUBSCRIBED);
      delete_post_meta($subscriptionId, self::META_NOTIFIED);
      wp_update_post(['ID' => $subscriptionId, 'post_date' => current_time('mysql'), 'post_date_gmt' => current_time('mysql', true)]);
    } else {
      $subscriptionId = self::createSubscription($productId, $email);
    }

    if ($subscriptionId > 0 && (bool) self::get('confirm_active')) {
      self::sendMail(self::MAIL_CONFIRM, $subscriptionId, $email, wc_get_product($productId));
    }
    return true;
  }

  /**
   * Registers the subscription route
   * @return void
   */
  public function registerRoutes(): void
  {
    register_rest_route(self::REST_NAMESPACE, '/' . self::REST_ROUTE, [
      'methods' => 'POST',
      'callback' => [$this, 'handleSubscribe'],
      'permission_callback' => '__return_true',
      'args' => [
        'product' => ['type' => 'integer', 'required' => true],
        'email' => ['type' => 'string', 'required' => true]
      ]
    ]);
  }

  /**
   * Handles a subscription of the form
   * @param WP_REST_Request $request product, email and the honeypot field
   * @return WP_REST_Response success and message to show
   */
  public function handleSubscribe(WP_REST_Request $request): WP_REST_Response
  {
    HTMLCache::avoidCache();
    $email = sanitize_email((string) $request->get_param('email'));
    $product = wc_get_product(absint($request->get_param('product')));

    if (strlen((string) $request->get_param(self::HONEYPOT_FIELD)) > 0) {
      return self::response(true, (string) self::get('success'));
    }
    if (!is_email($email)) {
      return self::response(false, __('Bitte geben Sie eine gültige E-Mail-Adresse ein.', 'lbwp'), 400);
    }
    if (!$product instanceof WC_Product || $product->get_status() !== 'publish' || !self::isAvailableFor($product)) {
      return self::response(false, __('Für diesen Artikel ist keine Benachrichtigung möglich.', 'lbwp'), 400);
    }

    $subscribed = self::subscribe($product->get_id(), $email);
    return self::response($subscribed, (string) self::get($subscribed ? 'success' : 'already'));
  }

  /**
   * Shortcode of the unsubscribe page: unsubscribes the subscription of the link
   * @return string message html
   */
  public function renderUnsubscribe(): string
  {
    HTMLCache::avoidCache();
    $token = sanitize_text_field(wp_unslash($_GET[self::UNSUBSCRIBE_PARAM] ?? ''));
    $subscriptionId = self::resolveUnsubscribeToken($token);
    if ($subscriptionId === 0) {
      return '<p class="stock-alert-message is-error">' . esc_html__('Dieser Abmeldelink ist ungültig.', 'lbwp') . '</p>';
    }

    update_post_meta($subscriptionId, self::META_STATUS, self::STATUS_UNSUBSCRIBED);
    return '<p class="stock-alert-message is-success">' . esc_html((string) self::get('unsubscribed')) . '</p>';
  }

  /**
   * Unsubscribe url of a subscription, empty if no unsubscribe page is set
   * @param int $subscriptionId the subscription
   * @return string
   */
  public static function getUnsubscribeUrl(int $subscriptionId): string
  {
    $pageId = (int) self::get('unsubscribe_page');
    $url = $pageId > 0 ? get_permalink($pageId) : false;
    if (!is_string($url)) {
      return '';
    }

    return add_query_arg(self::UNSUBSCRIBE_PARAM, $subscriptionId . '-' . self::getTokenHash($subscriptionId), $url);
  }

  /**
   * Registers the options page and its fields
   * @return void
   */
  public function fields(): void
  {
    $this->addOptionsPage(__('Einstellungen', 'lbwp'), self::SETTINGS_SLUG, 'edit.php?post_type=' . self::POST_TYPE, [
      'capability' => 'manage_woocommerce'
    ]);

    acf_add_local_field_group([
      'key' => 'group_lbwp_stock_alert',
      'title' => __('Lagerbenachrichtigung', 'lbwp'),
      'fields' => array_merge($this->getGeneralFields(), $this->getMailFields(self::MAIL_CONFIRM), $this->getMailFields(self::MAIL_RESTOCK)),
      'location' => [[['param' => 'options_page', 'operator' => '==', 'value' => self::SETTINGS_SLUG]]],
      'style' => 'seamless'
    ]);
  }

  /**
   * No blocks
   * @return void
   */
  public function blocks(): void
  {
  }

  /**
   * Registers the subscription post type, listed in the backend only
   * @return void
   */
  protected function initPostType(): void
  {
    WordPress::registerType(self::POST_TYPE, __('Lagerbenachrichtigung', 'lbwp'), __('Lagerbenachrichtigungen', 'lbwp'), [
      'labels' => [
        'menu_name' => __('Lagerbenachrichtigung', 'lbwp'),
        'all_items' => __('Abonnenten', 'lbwp'),
        'search_items' => __('E-Mail suchen', 'lbwp'),
        'not_found' => __('Keine Abonnenten gefunden', 'lbwp')
      ],
      'public' => false,
      'publicly_queryable' => false,
      'exclude_from_search' => true,
      'has_archive' => false,
      'rewrite' => false,
      'query_var' => false,
      'show_in_rest' => false,
      'show_ui' => true,
      'show_in_menu' => true,
      'show_in_nav_menus' => false,
      'menu_icon' => 'dashicons-email-alt',
      'menu_position' => 57,
      'supports' => false,
      'capability_type' => 'product',
      'capabilities' => ['create_posts' => 'do_not_allow'],
      'map_meta_cap' => true
    ], '');
  }

  /**
   * Registers the hooks of the subscriber list
   * @return void
   */
  protected function initAdminList(): void
  {
    add_filter('manage_' . self::POST_TYPE . '_posts_columns', [$this, 'getListColumns']);
    add_action('manage_' . self::POST_TYPE . '_posts_custom_column', [$this, 'renderListColumn'], 10, 2);
    add_filter('list_table_primary_column', [$this, 'getPrimaryColumn'], 10, 2);
    add_filter('post_row_actions', [$this, 'filterRowActions'], 10, 2);
    add_filter('bulk_actions-edit-' . self::POST_TYPE, [$this, 'filterBulkActions']);
    add_action('restrict_manage_posts', [$this, 'renderStatusFilter']);
    add_action('pre_get_posts', [$this, 'filterListQuery']);
    add_filter('the_posts', [$this, 'primeProducts'], 10, 2);
  }

  /**
   * Columns of the subscriber list
   * @param array $_columns default columns
   * @return array
   */
  public function getListColumns(array $_columns): array
  {
    return [
      'cb' => '<input type="checkbox">',
      'email' => __('E-Mail', 'lbwp'),
      'product' => __('Produkt', 'lbwp'),
      'status' => __('Status', 'lbwp'),
      'date' => __('Angemeldet', 'lbwp')
    ];
  }

  /**
   * Prints a column of the subscriber list
   * @param string $column column name
   * @param int $postId the subscription
   * @return void
   */
  public function renderListColumn(string $column, int $postId): void
  {
    $post = get_post($postId);
    match ($column) {
      'email' => printf('<strong>%s</strong>', esc_html($post->post_content)),
      'product' => $this->renderProductColumn((int) $post->post_parent),
      'status' => $this->renderStatusColumn($postId),
      default => null
    };
  }

  /**
   * Prints the product of a subscription with its sku, linked to the product
   * @param int $productId the product
   * @return void
   */
  protected function renderProductColumn(int $productId): void
  {
    $product = wc_get_product($productId);
    if (!$product instanceof WC_Product) {
      echo esc_html(sprintf(__('Gelöschtes Produkt #%d', 'lbwp'), $productId));
      return;
    }

    $status = $product->get_stock_status();
    printf(
      '<a href="%s">%s</a><br><small>%s · %s</small>',
      esc_url((string) get_edit_post_link($product->get_parent_id() ?: $productId)),
      esc_html($product->get_name()),
      esc_html($product->get_sku()),
      esc_html(wc_get_product_stock_status_options()[$status] ?? $status)
    );
  }

  /**
   * Prints the status of a subscription, with the time of the back in stock mail
   * @param int $postId the subscription
   * @return void
   */
  protected function renderStatusColumn(int $postId): void
  {
    $status = (string) get_post_meta($postId, self::META_STATUS, true);
    echo esc_html(self::getStatusLabels()[$status] ?? $status);

    $notified = (string) get_post_meta($postId, self::META_NOTIFIED, true);
    if ($status === self::STATUS_SENT && $notified !== '') {
      echo '<br><small>' . esc_html(mysql2date(get_option('date_format') . ' ' . get_option('time_format'), $notified)) . '</small>';
    }
  }

  /**
   * Makes the email the primary column (gets the row actions)
   * @param string $default default primary column
   * @param string $screenId current screen
   * @return string
   */
  public function getPrimaryColumn(string $default, string $screenId): string
  {
    return $screenId === 'edit-' . self::POST_TYPE ? 'email' : $default;
  }

  /**
   * Subscriptions can only be trashed / deleted, not edited
   * @param array $actions row actions
   * @param WP_Post $post the row's post
   * @return array
   */
  public function filterRowActions(array $actions, WP_Post $post): array
  {
    if ($post->post_type !== self::POST_TYPE) {
      return $actions;
    }
    return array_intersect_key($actions, array_flip(['trash', 'untrash', 'delete']));
  }

  /**
   * Removes the edit bulk action
   * @param array $actions bulk actions
   * @return array
   */
  public function filterBulkActions(array $actions): array
  {
    unset($actions['edit']);
    return $actions;
  }

  /**
   * Prints the status filter above the subscriber list
   * @param string $postType listed post type
   * @return void
   */
  public function renderStatusFilter(string $postType): void
  {
    if ($postType !== self::POST_TYPE) {
      return;
    }

    $current = sanitize_key($_GET['stock_alert_status'] ?? '');
    echo '<select name="stock_alert_status"><option value="">' . esc_html__('Alle Status', 'lbwp') . '</option>';
    foreach (self::getStatusLabels() as $status => $label) {
      printf('<option value="%s"%s>%s</option>', esc_attr($status), selected(strtolower($status), $current, false), esc_html($label));
    }
    echo '</select>';
  }

  /**
   * Applies the status filter to the subscriber list
   * @param WP_Query $query the query
   * @return void
   */
  public function filterListQuery(WP_Query $query): void
  {
    $status = sanitize_key($_GET['stock_alert_status'] ?? '');
    if (!$query->is_main_query() || $query->get('post_type') !== self::POST_TYPE || $status === '') {
      return;
    }

    $statuses = array_keys(self::getStatusLabels());
    $index = array_search($status, array_map('strtolower', $statuses), true);
    if ($index !== false) {
      $query->set('meta_query', [['key' => self::META_STATUS, 'value' => $statuses[$index]]]);
    }
  }

  /**
   * Loads the listed products with one query
   * @param WP_Post[] $posts listed posts
   * @param WP_Query $query the query
   * @return WP_Post[]
   */
  public function primeProducts(array $posts, WP_Query $query): array
  {
    if ($query->is_main_query() && $query->get('post_type') === self::POST_TYPE) {
      _prime_post_caches(array_unique(array_map(fn(WP_Post $post) => (int) $post->post_parent, $posts)));
    }
    return $posts;
  }

  /**
   * Labels of the subscription statuses
   * @return array status => label
   */
  protected static function getStatusLabels(): array
  {
    return [
      self::STATUS_SUBSCRIBED => __('Angemeldet', 'lbwp'),
      self::STATUS_SENT => __('Benachrichtigt', 'lbwp'),
      self::STATUS_UNSUBSCRIBED => __('Abgemeldet', 'lbwp')
    ];
  }

  /**
   * Fields of the general settings and the form texts
   * @return array
   */
  protected function getGeneralFields(): array
  {
    $defaults = self::getDefaults();
    return [
      self::tabField('general', __('Allgemein', 'lbwp')),
      self::field('active', 'true_false', __('Benachrichtigungen aktiv', 'lbwp'), ['ui' => 1, 'default_value' => 1]),
      self::field('backorders', 'true_false', __('Auch bei «Lieferbar auf Bestellung» anbieten', 'lbwp'), ['ui' => 1, 'default_value' => 1]),
      self::field('unsubscribe_page', 'post_object', __('Abmeldeseite', 'lbwp'), [
        'post_type' => ['page'],
        'return_format' => 'id',
        'allow_null' => 1,
        'instructions' => sprintf(__('Seite mit dem Shortcode [%s], auf die der Abmeldelink führt.', 'lbwp'), self::UNSUBSCRIBE_SHORTCODE)
      ]),
      self::tabField('form', __('Formular', 'lbwp')),
      self::field('title', 'text', __('Titel', 'lbwp'), ['placeholder' => $defaults['title']]),
      self::field('placeholder', 'text', __('Platzhalter E-Mail', 'lbwp'), ['placeholder' => $defaults['placeholder']]),
      self::field('button', 'text', __('Button', 'lbwp'), ['placeholder' => $defaults['button']]),
      self::field('success', 'text', __('Meldung bei erfolgreicher Anmeldung', 'lbwp'), ['placeholder' => $defaults['success']]),
      self::field('already', 'text', __('Meldung wenn bereits angemeldet', 'lbwp'), ['placeholder' => $defaults['already']]),
      self::field('unsubscribed', 'text', __('Meldung nach der Abmeldung', 'lbwp'), ['placeholder' => $defaults['unsubscribed']])
    ];
  }

  /**
   * Fields of one mail
   * @param string $type MAIL_CONFIRM or MAIL_RESTOCK
   * @return array
   */
  protected function getMailFields(string $type): array
  {
    $defaults = self::getDefaults();
    $placeholders = '{user_name}, {user_email}, {product_name}, {product_url}, {product_sku}, {site_title}'
      . ($type === self::MAIL_CONFIRM ? ', {unsubscribe_url}' : '');
    $fields = [
      self::tabField($type, $type === self::MAIL_CONFIRM ? __('Mail Anmeldung', 'lbwp') : __('Mail Wieder an Lager', 'lbwp')),
      self::field($type . '_subject', 'text', __('Betreff', 'lbwp'), ['placeholder' => $defaults[$type . '_subject']]),
      self::field($type . '_heading', 'text', __('Titel im Mail', 'lbwp'), ['placeholder' => $defaults[$type . '_heading']]),
      self::field($type . '_body', 'wysiwyg', __('Text', 'lbwp'), [
        'instructions' => sprintf(__('Platzhalter: %s', 'lbwp'), $placeholders),
        'media_upload' => 0,
        'tabs' => 'all'
      ])
    ];

    if ($type === self::MAIL_CONFIRM) {
      array_splice($fields, 1, 0, [self::field('confirm_active', 'true_false', __('Bestätigung nach der Anmeldung senden', 'lbwp'), ['ui' => 1, 'default_value' => 1])]);
    }
    return $fields;
  }

  /**
   * Definition of an ACF field
   * @param string $name name without prefix
   * @param string $type ACF field type
   * @param string $label label
   * @param array $config additional settings
   * @return array
   */
  protected static function field(string $name, string $type, string $label, array $config = []): array
  {
    return array_merge([
      'key' => 'field_lbwp_stock_alert_' . $name,
      'name' => self::OPTION_PREFIX . $name,
      'label' => $label,
      'type' => $type
    ], $config);
  }

  /**
   * Definition of an ACF tab
   * @param string $name unique name
   * @param string $label label
   * @return array
   */
  protected static function tabField(string $name, string $label): array
  {
    return ['key' => 'field_lbwp_stock_alert_tab_' . $name, 'label' => $label, 'type' => 'tab', 'placement' => 'top'];
  }

  /**
   * A setting, or its default if never saved or empty
   * @param string $name option name without prefix
   * @return mixed
   */
  protected static function get(string $name): mixed
  {
    $value = get_option('options_' . self::OPTION_PREFIX . $name, null);
    if ($value === null || $value === '') {
      return self::getDefaults()[$name] ?? '';
    }
    return $value;
  }

  /**
   * Defaults of the settings
   * @return array
   */
  protected static function getDefaults(): array
  {
    return [
      'active' => 1,
      'backorders' => 1,
      'confirm_active' => 1,
      'title' => __('Senden Sie mir eine E-Mail, sobald dieser Artikel wieder an Lager ist.', 'lbwp'),
      'placeholder' => __('Ihre E-Mail-Adresse', 'lbwp'),
      'button' => __('Benachrichtigen', 'lbwp'),
      'success' => __('Vielen Dank, wir benachrichtigen Sie, sobald der Artikel wieder an Lager ist.', 'lbwp'),
      'already' => __('Sie sind für diesen Artikel bereits angemeldet.', 'lbwp'),
      'unsubscribed' => __('Sie haben sich erfolgreich abgemeldet.', 'lbwp'),
      'confirm_subject' => __('Ihre Anmeldung für {product_name}', 'lbwp'),
      'confirm_heading' => __('Anmeldung erfolgreich', 'lbwp'),
      'confirm_body' => __("Hallo {user_name}\n\nWir senden Ihnen eine Nachricht, sobald der folgende Artikel wieder an Lager ist:\n\n{product_name}\n\nZum Abmelden klicken Sie hier: {unsubscribe_url}", 'lbwp'),
      'restock_subject' => __('Wieder an Lager: {product_name}', 'lbwp'),
      'restock_heading' => __('Wieder an Lager', 'lbwp'),
      'restock_body' => __("Hallo {user_name}\n\nDer folgende Artikel ist wieder an Lager:\n\n<a href=\"{product_url}\">{product_name}</a>", 'lbwp')
    ];
  }

  /**
   * Sends a mail of a subscription in the WooCommerce mail layout
   * @param string $type MAIL_CONFIRM or MAIL_RESTOCK
   * @param int $subscriptionId the subscription
   * @param string $email recipient
   * @param WC_Product|false|null $product the subscribed product
   * @return bool whether the mail was handed over
   */
  protected static function sendMail(string $type, int $subscriptionId, string $email, mixed $product): bool
  {
    if (!$product instanceof WC_Product) {
      return false;
    }

    $mailer = WC()->mailer();
    $values = self::getPlaceholderValues($type, $subscriptionId, $email, $product);
    $html = array_map(fn(string $value) => esc_html($value), $values);
    $html['{unsubscribe_url}'] = self::getUnsubscribeLink($values['{unsubscribe_url}']);
    $html['{product_url}'] = esc_url($values['{product_url}']);

    $subject = strtr((string) self::get($type . '_subject'), $values);
    $heading = strtr((string) self::get($type . '_heading'), $values);
    $body = wpautop(wptexturize(strtr((string) self::get($type . '_body'), $html)));
    return (bool) $mailer->send($email, $subject, $mailer->wrap_message($heading, $body));
  }

  /**
   * Plain text values of the mail placeholders
   * @param string $type mail type
   * @param int $subscriptionId the subscription
   * @param string $email recipient
   * @param WC_Product $product the product
   * @return array placeholder => value
   */
  protected static function getPlaceholderValues(string $type, int $subscriptionId, string $email, WC_Product $product): array
  {
    return [
      '{user_name}' => self::getUserName($email),
      '{user_email}' => $email,
      '{product_name}' => html_entity_decode(wp_strip_all_tags($product->get_name()), ENT_QUOTES),
      '{product_url}' => $product->get_permalink(),
      '{product_sku}' => $product->get_sku(),
      '{site_title}' => html_entity_decode(get_bloginfo('name'), ENT_QUOTES),
      '{unsubscribe_url}' => $type === self::MAIL_CONFIRM ? self::getUnsubscribeUrl($subscriptionId) : ''
    ];
  }

  /**
   * Link html of the unsubscribe url (the plugin's templates use the placeholder as link)
   * @param string $url the unsubscribe url
   * @return string
   */
  protected static function getUnsubscribeLink(string $url): string
  {
    if ($url === '') {
      return '';
    }
    return '<a href="' . esc_url($url) . '">' . esc_html__('Abmelden', 'lbwp') . '</a>';
  }

  /**
   * Name of the customer with this email, empty for guests
   * @param string $email the email
   * @return string
   */
  protected static function getUserName(string $email): string
  {
    $user = get_user_by('email', $email);
    if ($user === false) {
      return '';
    }

    $name = trim($user->first_name . ' ' . $user->last_name);
    return $name !== '' ? $name : $user->display_name;
  }

  /**
   * Active subscribers of a product
   * @param int $productId the product
   * @return array subscription id => email
   */
  protected static function getSubscribers(int $productId): array
  {
    $db = WordPress::getDb();
    $rows = $db->get_results($db->prepare('
      SELECT p.ID, p.post_content FROM ' . $db->posts . ' p
      INNER JOIN ' . $db->postmeta . ' m ON m.post_id = p.ID AND m.meta_key = %s AND m.meta_value = %s
      WHERE p.post_type = %s AND p.post_status = "publish" AND p.post_parent = %d
    ', self::META_STATUS, self::STATUS_SUBSCRIBED, self::POST_TYPE, $productId));

    $subscribers = [];
    foreach ($rows as $row) {
      if (is_email($row->post_content)) {
        $subscribers[(int) $row->ID] = $row->post_content;
      }
    }
    return $subscribers;
  }

  /**
   * Existing subscription of an email to a product, an active one first
   * @param int $productId the product
   * @param string $email the email
   * @return array [subscription id (0 if none), status]
   */
  protected static function findSubscription(int $productId, string $email): array
  {
    $db = WordPress::getDb();
    $row = $db->get_row($db->prepare('
      SELECT p.ID, m.meta_value AS status FROM ' . $db->posts . ' p
      LEFT JOIN ' . $db->postmeta . ' m ON m.post_id = p.ID AND m.meta_key = %s
      WHERE p.post_type = %s AND p.post_status = "publish" AND p.post_parent = %d AND p.post_content = %s
      ORDER BY m.meta_value = %s DESC, p.ID DESC LIMIT 1
    ', self::META_STATUS, self::POST_TYPE, $productId, $email, self::STATUS_SUBSCRIBED));

    return $row === null ? [0, ''] : [(int) $row->ID, (string) $row->status];
  }

  /**
   * Creates a subscription: as draft first, so the status meta exists when it's published
   * @param int $productId the product
   * @param string $email the email
   * @return int subscription id, 0 on failure
   */
  protected static function createSubscription(int $productId, string $email): int
  {
    $subscriptionId = wp_insert_post([
      'post_type' => self::POST_TYPE,
      'post_title' => self::POST_TITLE,
      'post_content' => $email,
      'post_status' => 'draft',
      'post_parent' => $productId
    ]);
    if (!is_int($subscriptionId) || $subscriptionId === 0) {
      return 0;
    }

    update_post_meta($subscriptionId, self::META_STATUS, self::STATUS_SUBSCRIBED);
    wp_update_post(['ID' => $subscriptionId, 'post_status' => 'publish']);
    return $subscriptionId;
  }

  /**
   * Subscription of an unsubscribe token: "{id}-{hash}", or md5 of the id in links sent by the plugin
   * @param string $token the token of the link
   * @return int subscription id, 0 if invalid
   */
  protected static function resolveUnsubscribeToken(string $token): int
  {
    if (preg_match('/^(\d+)-([a-f0-9]{16})$/', $token, $match) === 1) {
      $subscriptionId = (int) $match[1];
      $valid = hash_equals(self::getTokenHash($subscriptionId), $match[2]);
      return $valid && get_post_type($subscriptionId) === self::POST_TYPE ? $subscriptionId : 0;
    }

    if (preg_match('/^[a-f0-9]{32}$/', $token) !== 1) {
      return 0;
    }
    $db = WordPress::getDb();
    return (int) $db->get_var($db->prepare(
      'SELECT ID FROM ' . $db->posts . ' WHERE post_type = %s AND MD5(ID) = %s LIMIT 1',
      self::POST_TYPE, $token
    ));
  }

  /**
   * Hash part of the unsubscribe token
   * @param int $subscriptionId the subscription
   * @return string
   */
  protected static function getTokenHash(int $subscriptionId): string
  {
    return substr(wp_hash('lbwp-stock-alert-' . $subscriptionId), 0, 16);
  }

  /**
   * REST response of the subscription
   * @param bool $success whether it succeeded
   * @param string $message message to show
   * @param int $status http status
   * @return WP_REST_Response
   */
  protected static function response(bool $success, string $message, int $status = 200): WP_REST_Response
  {
    $response = new WP_REST_Response(['success' => $success, 'message' => $message], $status);
    $response->header('Cache-Control', 'no-store');
    return $response;
  }

  /**
   * Copies the plugin settings once into the options of this component (DE and FR sites each have their own)
   * @return void
   */
  protected function migrateLegacySettings(): void
  {
    if (get_option(self::MIGRATION_OPTION) !== false) {
      return;
    }

    update_option(self::MIGRATION_OPTION, 1, true);
    $values = [];
    foreach (self::LEGACY_OPTIONS as $legacy => $name) {
      $values[$name] = get_option($legacy, null);
    }
    foreach (self::LEGACY_MAILS as $legacy => $type) {
      $values = array_merge($values, $this->getLegacyMailSettings($legacy, $type));
    }

    foreach ($values as $name => $value) {
      if ($value === null || $value === '' || is_array($value)) {
        continue;
      }
      $value = in_array($name, self::LEGACY_CHECKBOXES, true) ? (int) ($value === 'yes') : $value;
      update_option('options_' . self::OPTION_PREFIX . $name, $value, true);
      update_option('_options_' . self::OPTION_PREFIX . $name, 'field_lbwp_stock_alert_' . $name, false);
    }
  }

  /**
   * Subject, heading and active state of a WooCommerce email of the plugin
   * @param string $option option of the WooCommerce email settings
   * @param string $type mail type of this component
   * @return array option name => value
   */
  protected function getLegacyMailSettings(string $option, string $type): array
  {
    $settings = get_option($option);
    if (!is_array($settings)) {
      return [];
    }

    $values = [
      $type . '_subject' => $settings['subject'] ?? null,
      $type . '_heading' => $settings['heading'] ?? null
    ];
    if ($type === self::MAIL_CONFIRM) {
      $values['confirm_active'] = $settings['enabled'] ?? null;
    }
    return $values;
  }
}
