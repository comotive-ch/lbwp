<?php

namespace LBWP\Aboon\AgenticCommerce;

/**
 * Registers the ACF field groups of the agentic commerce feature: general options, product
 * fields, and the protocol specific OpenAI ACP / Google UCP groups. Field keys are hand written
 * and must never change (ACF references them in the "_options_*" rows).
 * @package LBWP\Aboon\AgenticCommerce
 * @author Mirko Baffa <mirko@comotive.ch>
 */
class Fields
{
  /**
   * @var string options page slug all groups are located on
   */
  public const string PAGE = 'aboon-agentic-commerce';

  /**
   * Registers the general group (shared settings) on the options page.
   * @return void
   */
  public function registerGeneral(): void
  {
    acf_add_local_field_group([
      'key' => 'group_agentic_general',
      'title' => 'Agentic Commerce – Allgemein',
      'fields' => [
        $this->checkbox('field_agentic_acp_active', 'agentic-acp-active', 'OpenAI ACP (ChatGPT Checkout)', 'Aktiviert die ACP-Endpunkte unter ' . rest_url('acp/v1') . '. Nach dem Speichern erscheint die Gruppe "OpenAI ACP" mit den Zugangsdaten.'),
        $this->checkbox('field_agentic_ucp_active', 'agentic-ucp-active', 'Google UCP (Universal Commerce Protocol)', 'Aktiviert das Profil unter ' . get_bloginfo('url') . '/.well-known/ucp und die Endpunkte unter ' . rest_url('ucp/v1') . '.'),
        $this->checkbox('field_agentic_test_mode', 'agentic-test-mode', 'Testmodus', 'Kein Zahlungsanbieter wird aufgerufen, Bestellungen landen "In Wartestellung" mit dem Hinweis TESTMODUS.'),
        $this->select('field_agentic_item_id_source', 'agentic-item-id-source', 'Artikel-ID in Feeds und Anfragen', ['id' => 'Produkt-/Varianten-ID', 'sku' => 'Artikelnummer (SKU)'], 'id'),
        [
          'key' => 'field_agentic_eligible_categories',
          'label' => 'Freigegebene Kategorien',
          'name' => 'agentic-eligible-categories',
          'type' => 'taxonomy',
          'instructions' => 'Leer = alle Kategorien. Produkte ausserhalb dieser Kategorien werden nicht in Feeds aufgenommen und in Sessions abgelehnt.',
          'taxonomy' => 'product_cat',
          'field_type' => 'multi_select',
          'add_term' => 0,
          'save_terms' => 0,
          'load_terms' => 0,
          'return_format' => 'id',
          'multiple' => 1,
          'allow_null' => 1,
        ],
        $this->select('field_agentic_allowed_countries', 'agentic-allowed-countries', 'Erlaubte Lieferländer', $this->countryChoices(), '', true, 'Leer = alle WooCommerce-Lieferländer.'),
        $this->number('field_agentic_max_order_total', 'agentic-max-order-total', 'Maximaler Bestellwert', 0, '0 = keine Grenze. In Shop-Währung inkl. Versand.'),
        $this->select('field_agentic_status_without_capture', 'agentic-status-without-capture', 'Status ohne Zahlungseinzug', ['on-hold' => 'In Wartestellung', 'pending' => 'Zahlung ausstehend', 'processing' => 'In Bearbeitung'], 'on-hold', false, 'Status für Bestellungen, die ohne PSP-Einzug abgeschlossen werden (Testmodus, Rechnung).'),
        $this->number('field_agentic_delivery_days_min', 'agentic-delivery-days-min', 'Lieferzeit min. (Tage)', 2, '', 50),
        $this->number('field_agentic_delivery_days_max', 'agentic-delivery-days-max', 'Lieferzeit max. (Tage)', 5, '', 50),
        $this->text('field_agentic_carrier_name', 'agentic-carrier-name', 'Versanddienstleister', 'z.B. "Post". Wird bei Versandoptionen als Carrier ausgegeben.'),
        $this->text('field_agentic_shipping_fallback_title', 'agentic-shipping-fallback-title', 'Fallback-Versandoption', 'Wird angeboten, wenn keine Versandzone passt. Leer = Session ohne passende Zone wird abgelehnt (region_restricted).', 50),
        $this->number('field_agentic_shipping_fallback_amount', 'agentic-shipping-fallback-amount', 'Fallback-Versandkosten', 0, '', 50),
        [
          'key' => 'field_agentic_links',
          'label' => 'Rechtliche Seiten',
          'name' => 'agentic-links',
          'type' => 'group',
          'instructions' => 'Werden dem Agenten als Links (AGB, Datenschutz, Rückgabe, Versand, Kontakt) übergeben.',
          'layout' => 'table',
          'sub_fields' => [
            $this->pageLink('field_agentic_links_terms', 'terms', 'AGB'),
            $this->pageLink('field_agentic_links_privacy', 'privacy', 'Datenschutz'),
            $this->pageLink('field_agentic_links_returns', 'returns', 'Rückgabe'),
            $this->pageLink('field_agentic_links_shipping', 'shipping', 'Versand'),
            $this->pageLink('field_agentic_links_contact', 'contact', 'Kontakt'),
          ],
        ],
        $this->text('field_agentic_seller_name', 'agentic-seller-name', 'Verkäufername', 'Leer = Name der Website. Wird in Feeds, Profil und als Präfix des ACP-Webhook-Signatur-Headers verwendet.'),
        $this->checkbox('field_agentic_log_requests', 'agentic-log-requests', 'Alle Anfragen protokollieren', 'Schreibt jede Anfrage (Methode, Pfad, Session, Dauer) ins System-Log. Fehler werden immer protokolliert.'),
      ],
      'location' => [[['param' => 'options_page', 'operator' => '==', 'value' => self::PAGE]]],
      'menu_order' => 0,
      'position' => 'normal',
      'style' => 'default',
      'label_placement' => 'top',
      'instruction_placement' => 'label',
      'active' => true,
    ]);
  }

  /**
   * Registers the product sidebar group.
   * @return void
   */
  public function registerProduct(): void
  {
    acf_add_local_field_group([
      'key' => 'group_agentic_product',
      'title' => 'Agentic Commerce',
      'fields' => [
        [
          'key' => 'field_agentic_product_exclude',
          'label' => 'KI-Checkout',
          'name' => 'agentic-commerce-exclude',
          'type' => 'checkbox',
          'instructions' => '',
          'choices' => [1 => 'Nicht für KI-Checkout freigeben'],
          'default_value' => [],
          'return_format' => 'value',
          'layout' => 'vertical',
        ],
        $this->text('field_agentic_product_note', 'agentic-commerce-note', 'Hinweis für den Agenten', 'Optionaler Hinweis, der dem Agenten zur Position angezeigt wird (z.B. "Nur mit Altersnachweis").'),
      ],
      'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => 'product']]],
      'menu_order' => 20,
      'position' => 'side',
      'style' => 'default',
      'label_placement' => 'top',
      'instruction_placement' => 'label',
      'active' => true,
    ]);
  }

  /**
   * Registers the OpenAI ACP group, shown when ACP is active.
   * @return void
   */
  public function registerAcp(): void
  {
    acf_add_local_field_group([
      'key' => 'group_agentic_acp',
      'title' => 'OpenAI ACP',
      'fields' => [
        $this->message('field_agentic_acp_info', 'Endpunkte', 'Basis-URL für OpenAI: <code>' . esc_html(rest_url('acp/v1')) . '</code><br>Feed-URL: <code>' . esc_html(get_bloginfo('url') . '/assets/lbwp-cdn/' . ASSET_KEY . '/files/shop/acp-feed.tsv') . '</code>'),
        $this->text('field_agentic_acp_api_key', 'acp-api-key', 'API-Key (Bearer)', 'Diesen Schlüssel muss OpenAI im Authorization-Header senden.', 70, true),
        $this->message('field_agentic_acp_api_key_button', '', $this->actionButton('agentic_generate_api_key', 'API-Key neu generieren'), 30),
        $this->text('field_agentic_acp_signature_secret', 'acp-signature-secret', 'Signatur-Secret (eingehend)', 'Optional. Wenn gesetzt, werden Signature/Timestamp-Header von OpenAI per HMAC geprüft.'),
        $this->text('field_agentic_acp_api_version', 'acp-api-version', 'API-Version', 'Neueste unterstützte Version.', 30, false, Settings::ACP_VERSION),
        $this->text('field_agentic_acp_webhook_url', 'acp-webhook-url', 'Webhook-URL (OpenAI)', 'Endpunkt von OpenAI für order_create / order_update Events.', 70),
        $this->text('field_agentic_acp_webhook_secret', 'acp-webhook-secret', 'Webhook-Secret (ausgehend)', 'HMAC-Secret für die Signatur unserer Webhooks.', 30),
        $this->select('field_agentic_acp_payment_handler', 'acp-payment-handler', 'Zahlungs-Handler', ['deferred' => 'Ohne Einzug (Rechnung / Testmodus)', 'stripe' => 'Stripe (Shared Payment Token)'], 'deferred', false, 'Payrexx-Shops können nur "Ohne Einzug" nutzen, siehe SETUP.md.'),
        $this->password('field_agentic_acp_stripe_secret_key', 'acp-stripe-secret-key', 'Stripe Secret Key', 'Leer = Schlüssel aus dem WooCommerce-Stripe-Plugin verwenden.'),
        $this->text('field_agentic_acp_stripe_account_id', 'acp-stripe-account-id', 'Stripe Account-ID', 'acct_… – wird als merchant_id im Handler ausgegeben.', 50),
        $this->select('field_agentic_acp_capture_method', 'acp-capture-method', 'Capture-Methode', ['automatic' => 'Automatisch', 'manual' => 'Manuell (Autorisierung)'], 'automatic', false, '', 50),
        $this->checkbox('field_agentic_acp_feed_active', 'acp-feed-active', 'Produkt-Feed (TSV) nächtlich erzeugen', 'Wird um 1 Uhr erzeugt und auf dem CDN abgelegt.'),
        $this->text('field_agentic_acp_feed_target_countries', 'acp-feed-target-countries', 'Zielländer im Feed', 'Kommagetrennte ISO-Codes, z.B. CH,LI', 50, false, 'CH'),
      ],
      'location' => [[['param' => 'options_page', 'operator' => '==', 'value' => self::PAGE]]],
      'menu_order' => 10,
      'position' => 'normal',
      'style' => 'default',
      'label_placement' => 'top',
      'instruction_placement' => 'label',
      'active' => true,
    ]);
  }

  /**
   * Registers the Google UCP group, shown when UCP is active.
   * @return void
   */
  public function registerUcp(): void
  {
    acf_add_local_field_group([
      'key' => 'group_agentic_ucp',
      'title' => 'Google UCP',
      'fields' => [
        $this->message('field_agentic_ucp_info', 'Endpunkte', 'Profil: <code>' . esc_html(get_bloginfo('url') . '/.well-known/ucp') . '</code><br>Endpunkt: <code>' . esc_html(rest_url('ucp/v1')) . '</code>'),
        $this->text('field_agentic_ucp_version', 'ucp-version', 'UCP-Version', '', 30, false, Settings::UCP_VERSION),
        $this->text('field_agentic_ucp_partner_id', 'ucp-partner-id', 'Google Partner-ID', 'Von Google vergeben, Teil der Order-Event-URL.', 35),
        $this->password('field_agentic_ucp_events_api_key', 'ucp-events-api-key', 'Google API-Key (Order Events)', '', 35),
        [
          'key' => 'field_agentic_ucp_allowed_callers',
          'label' => 'Erlaubte Aufrufer',
          'name' => 'ucp-allowed-callers',
          'type' => 'textarea',
          'instructions' => 'Service-Account-E-Mails und/oder aud-Werte, eine pro Zeile. Anfragen mit anderem Token werden mit 401 abgelehnt.',
          'rows' => 3,
        ],
        $this->checkbox('field_agentic_ucp_require_signature', 'ucp-require-signature', 'RFC 9421 Signatur erzwingen', 'Empfohlen im Betrieb. Fehler werden immer protokolliert.'),
        $this->text('field_agentic_ucp_signing_kid', 'ucp-signing-kid', 'Signaturschlüssel-ID (kid)', 'Wird im Profil veröffentlicht.', 50, true),
        $this->message('field_agentic_ucp_key_button', '', $this->actionButton('agentic_generate_keys', 'EC-Schlüsselpaar neu generieren', 'Der öffentliche Schlüssel im Profil ändert sich. Bereits registrierte Plattformen müssen das Profil neu laden.'), 50),
        [
          'key' => 'field_agentic_ucp_signing_key',
          'label' => 'Privater Schlüssel (PEM)',
          'name' => 'ucp-signing-key',
          'type' => 'textarea',
          'instructions' => 'EC P-256. Nur lesen, nicht bearbeiten.',
          'rows' => 4,
          'readonly' => 1,
        ],
        $this->text('field_agentic_ucp_google_pay_merchant_id', 'ucp-google-pay-merchant-id', 'Google Pay Merchant-ID', '', 50),
        $this->text('field_agentic_ucp_gateway_merchant_id', 'ucp-gateway-merchant-id', 'Gateway Merchant-ID (Stripe acct_…)', 'Gateway ist immer Stripe – Payrexx kann keine Google-Pay-Tokens entgegennehmen.', 50),
        $this->password('field_agentic_ucp_stripe_secret_key', 'ucp-stripe-secret-key', 'Stripe Secret Key', 'Leer = Schlüssel aus dem WooCommerce-Stripe-Plugin verwenden.'),
        [
          'key' => 'field_agentic_ucp_card_networks',
          'label' => 'Erlaubte Kartennetzwerke',
          'name' => 'ucp-card-networks',
          'type' => 'checkbox',
          'choices' => ['VISA' => 'Visa', 'MASTERCARD' => 'Mastercard', 'AMEX' => 'American Express', 'DISCOVER' => 'Discover', 'JCB' => 'JCB'],
          'default_value' => ['VISA', 'MASTERCARD'],
          'return_format' => 'value',
          'layout' => 'horizontal',
        ],
      ],
      'location' => [[['param' => 'options_page', 'operator' => '==', 'value' => self::PAGE]]],
      'menu_order' => 20,
      'position' => 'normal',
      'style' => 'default',
      'label_placement' => 'top',
      'instruction_placement' => 'label',
      'active' => true,
    ]);
  }

  /**
   * Builds an "Aktivieren" checkbox field.
   * @param string $key field key
   * @param string $name field name
   * @param string $label label
   * @param string $instructions instructions
   * @return array field config
   */
  protected function checkbox(string $key, string $name, string $label, string $instructions = ''): array
  {
    return [
      'key' => $key,
      'label' => $label,
      'name' => $name,
      'type' => 'checkbox',
      'instructions' => $instructions,
      'choices' => [1 => 'Aktivieren'],
      'default_value' => [],
      'return_format' => 'value',
      'layout' => 'vertical',
    ];
  }

  /**
   * Builds a text field.
   * @param string $key field key
   * @param string $name field name
   * @param string $label label
   * @param string $instructions instructions
   * @param int $width wrapper width in percent (0 = full)
   * @param bool $readonly readonly flag
   * @param string $default default value
   * @return array field config
   */
  protected function text(string $key, string $name, string $label, string $instructions = '', int $width = 0, bool $readonly = false, string $default = ''): array
  {
    return [
      'key' => $key,
      'label' => $label,
      'name' => $name,
      'type' => 'text',
      'instructions' => $instructions,
      'wrapper' => ['width' => $width > 0 ? (string) $width : '', 'class' => '', 'id' => ''],
      'default_value' => $default,
      'readonly' => $readonly ? 1 : 0,
    ];
  }

  /**
   * Builds a password field.
   * @param string $key field key
   * @param string $name field name
   * @param string $label label
   * @param string $instructions instructions
   * @param int $width wrapper width in percent
   * @return array field config
   */
  protected function password(string $key, string $name, string $label, string $instructions = '', int $width = 0): array
  {
    $field = $this->text($key, $name, $label, $instructions, $width);
    $field['type'] = 'password';
    return $field;
  }

  /**
   * Builds a number field.
   * @param string $key field key
   * @param string $name field name
   * @param string $label label
   * @param float $default default value
   * @param string $instructions instructions
   * @param int $width wrapper width in percent
   * @return array field config
   */
  protected function number(string $key, string $name, string $label, float $default = 0, string $instructions = '', int $width = 0): array
  {
    return [
      'key' => $key,
      'label' => $label,
      'name' => $name,
      'type' => 'number',
      'instructions' => $instructions,
      'wrapper' => ['width' => $width > 0 ? (string) $width : '', 'class' => '', 'id' => ''],
      'default_value' => $default,
      'min' => 0,
      'step' => 'any',
    ];
  }

  /**
   * Builds a select field.
   * @param string $key field key
   * @param string $name field name
   * @param string $label label
   * @param array $choices value => label
   * @param string $default default value
   * @param bool $multiple multi select
   * @param string $instructions instructions
   * @param int $width wrapper width in percent
   * @return array field config
   */
  protected function select(string $key, string $name, string $label, array $choices, string $default = '', bool $multiple = false, string $instructions = '', int $width = 0): array
  {
    return [
      'key' => $key,
      'label' => $label,
      'name' => $name,
      'type' => 'select',
      'instructions' => $instructions,
      'wrapper' => ['width' => $width > 0 ? (string) $width : '', 'class' => '', 'id' => ''],
      'choices' => $choices,
      'default_value' => $multiple ? [] : $default,
      'allow_null' => $multiple ? 1 : 0,
      'multiple' => $multiple ? 1 : 0,
      'ui' => $multiple ? 1 : 0,
      'return_format' => 'value',
    ];
  }

  /**
   * Builds a page link sub field.
   * @param string $key field key
   * @param string $name field name
   * @param string $label label
   * @return array field config
   */
  protected function pageLink(string $key, string $name, string $label): array
  {
    return [
      'key' => $key,
      'label' => $label,
      'name' => $name,
      'type' => 'page_link',
      'post_type' => ['page'],
      'allow_archives' => 0,
      'allow_null' => 1,
      'multiple' => 0,
    ];
  }

  /**
   * Builds a message field.
   * @param string $key field key
   * @param string $label label
   * @param string $html message html
   * @param int $width wrapper width in percent
   * @return array field config
   */
  protected function message(string $key, string $label, string $html, int $width = 0): array
  {
    return [
      'key' => $key,
      'label' => $label,
      'name' => '',
      'type' => 'message',
      'message' => $html,
      'wrapper' => ['width' => $width > 0 ? (string) $width : '', 'class' => '', 'id' => ''],
      'esc_html' => 0,
      'new_lines' => '',
    ];
  }

  /**
   * Builds the html of an admin-post action button.
   * @param string $action admin_post action name
   * @param string $label button label
   * @param string $note optional note shown below the button
   * @return string html
   */
  protected function actionButton(string $action, string $label, string $note = ''): string
  {
    $url = wp_nonce_url(admin_url('admin-post.php?action=' . $action), $action);
    $html = '<a href="' . esc_url($url) . '" class="button button-secondary" onclick="return confirm(\'Wirklich neu generieren?\');">' . esc_html($label) . '</a>';
    if ($note !== '') {
      $html .= '<p class="description">' . esc_html($note) . '</p>';
    }
    return $html;
  }

  /**
   * Returns the WooCommerce shipping country choices.
   * @return array code => name
   */
  protected function countryChoices(): array
  {
    if (!function_exists('WC') || !WC()->countries) {
      return [];
    }
    return (array) WC()->countries->get_shipping_countries();
  }
}
