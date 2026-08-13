<?php

namespace LBWP\Aboon\Subscription\Gateway;

use LBWP\Aboon\Subscription\Order\OrderLinker;
use LBWP\Module\General\Cms\SystemLog;

/**
 * WooCommerce payment gateway that sells subscription products through Payrexx's own
 * subscription feature. Unlike LBWP\Aboon\Gateway\CashBase, this gateway does not complete the
 * order instantly: the customer is redirected to a Payrexx hosted page, and the order is only
 * marked paid once the webhook confirms the first charge.
 * @package LBWP\Aboon\Subscription\Gateway
 * @author Michael Sebel <michael@comotive.ch>
 */
class PayrexxSubscriptionGateway extends \WC_Payment_Gateway
{
  /**
   * @var string the WooCommerce gateway id
   */
  public const string GATEWAY_ID = 'lbwp_payrexx_subscription';

  /**
   * Sets up the gateway's settings and admin form fields.
   */
  public function __construct()
  {
    $this->id = self::GATEWAY_ID;
    $this->icon = apply_filters('lbwp_payrexx_subscription_icon', '');
    $this->has_fields = false;
    $this->method_title = __('Payrexx Abonnement', 'lbwp');
    $this->method_description = __('Bezahlmethode für wiederkehrende Zahlungen (Abonnemente) über Payrexx.', 'lbwp');
    $this->supports = ['products'];

    $this->init_form_fields();
    $this->init_settings();

    $this->title = $this->get_option('title');
    $this->description = $this->get_option('description');

    add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
  }

  /**
   * Initialises the gateway's settings screen fields.
   * @return void
   */
  public function init_form_fields(): void
  {
    $this->form_fields = [
      'enabled' => [
        'title' => __('Aktivieren/Deaktivieren', 'lbwp'),
        'type' => 'checkbox',
        'label' => __('Payrexx Abonnement-Zahlung aktivieren', 'lbwp'),
        'default' => 'no',
      ],
      'title' => [
        'title' => __('Titel', 'lbwp'),
        'type' => 'text',
        'description' => __('Wird dem Kunden an der Kasse angezeigt.', 'lbwp'),
        'default' => __('Abonnement (wiederkehrende Zahlung)', 'lbwp'),
        'desc_tip' => true,
      ],
      'description' => [
        'title' => __('Beschreibung', 'lbwp'),
        'type' => 'textarea',
        'description' => __('Wird dem Kunden an der Kasse angezeigt.', 'lbwp'),
        'default' => __('Du schliesst ein Abonnement ab. Die Zahlung wird automatisch wiederkehrend über Payrexx abgewickelt.', 'lbwp'),
        'desc_tip' => true,
      ],
      'instance_name' => [
        'title' => __('Payrexx Instanz', 'lbwp'),
        'type' => 'text',
        'description' => __('Der Instanz-/Subdomainname deiner Payrexx-Installation.', 'lbwp'),
        'desc_tip' => true,
      ],
      'api_secret' => [
        'title' => __('API Secret', 'lbwp'),
        'type' => 'password',
        'description' => __('API-Schlüssel aus der Payrexx-Administration.', 'lbwp'),
        'desc_tip' => true,
      ],
      'api_base_domain' => [
        'title' => __('Plattform-Domain', 'lbwp'),
        'type' => 'text',
        'description' => __('Basis-Domain der (ggf. whitelabelled) Payrexx-Plattform, z.B. payrexx.com.', 'lbwp'),
        'default' => 'payrexx.com',
        'desc_tip' => true,
      ],
      'look_and_feel_profile' => [
        'title' => __('Look&Feel Profil-ID', 'lbwp'),
        'type' => 'text',
        'description' => __('Optional: ID eines in Payrexx hinterlegten Look&Feel-Profils für die Checkout-Seite.', 'lbwp'),
        'desc_tip' => true,
      ],
    ];
  }

  /**
   * Redirects the customer to a Payrexx hosted page that both charges the first payment and
   * sets up the recurring subscription.
   * @param int $orderId the WooCommerce order id
   * @return array{result:string,redirect:string} the WooCommerce gateway result
   */
  public function process_payment($orderId): array
  {
    $order = wc_get_order($orderId);
    if (!$order instanceof \WC_Order) {
      wc_add_notice(__('Die Zahlung konnte nicht gestartet werden. Bitte versuche es erneut.', 'lbwp'), 'error');
      return ['result' => 'failure'];
    }

    try {
      $link = (new OrderLinker())->startCheckout($order);
    } catch (\Throwable $throwable) {
      SystemLog::add('AboonSubscription', 'error', 'process_payment(): uncaught ' . get_class($throwable) . ' for order ' . $orderId . ': ' . $throwable->getMessage());
      wc_add_notice(__('Die Zahlung konnte nicht gestartet werden. Bitte versuche es erneut.', 'lbwp'), 'error');
      return ['result' => 'failure'];
    }

    if ($link === null) {
      wc_add_notice(__('Die Zahlung konnte nicht gestartet werden. Bitte versuche es erneut.', 'lbwp'), 'error');
      return ['result' => 'failure'];
    }

    return [
      'result' => 'success',
      'redirect' => $link,
    ];
  }
}
