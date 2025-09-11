<?php

namespace LBWP\Theme\Feature;

use LBWP\Module\General\Cms\SystemLog;
use LBWP\Util\File;
use LBWP\Util\Multilang;
use LBWP\Util\Strings;
use LBWP\Util\WordPress;
use Sprain\SwissQrBill as QrBill;
use Sprain\SwissQrBill\PaymentPart\Output\HtmlOutput\HtmlOutput;

/**
 * Provides QR billing parts for pdf billing in woocommerce
 * @package LBWP\Theme\Feature
 * @author Michael Sebel <michael@comotive.ch>
 */
class SwissQrIban
{
  /**
   * Default empty settings (not working)
   * @var array
   */
  protected $settings = array(
    'qrIban' => '',
    'qrRefno' => '',
  );
  /**
   * The smallest iban number of charactares for minimal validation (norway)
   */
  const SMALLEST_IBAN = 15;
  /**
   * @var SwissQrIban the sticky post config object
   */
  protected static $instance = NULL;

  /**
   * Can only be instantiated by calling init method
   * @param array|null $settings overriding defaults
   */
  protected function __construct($settings = NULL)
  {
    $this->settings = $settings;
    // Special handling if refNo is empty, explicitly set to null to be api compatible
    if ($this->settings['qrRefno'] == '') {
      $this->settings['qrRefno'] = NULL;
    }
  }

  /**
   * Initialise while overriding settings defaults
   * @param array|null $settings overrides defaults as new default
   */
  public static function init($settings = NULL)
  {
    self::$instance = new SwissQrIban($settings);
    self::$instance->load();
  }

  /**
   * @return SwissQrIban
   */
  public static function getInstance()
  {
    return self::$instance;
  }

  /**
   * Loads/runs the needed actions and functions
   */
  protected function load()
  {
    // Check if iban is given (refno can be null for postfinance)
    if (strlen($this->settings['qrIban']) >= self::SMALLEST_IBAN) {
      // Hook into the pdf plugin to include our qr iban slip
      add_action('wpo_wcpdf_after_order_details', array($this, 'addQrPaymentSlip'), 100, 2);
    }
  }

  /**
   * @param array $receiver of four entries: name, streetm, zipcity, country
   * @param array $payer of four entries: name, streetm, zipcity, country
   * @param string $currency an iso currency code
   * @param float $amount final amount to be paid
   * @param string $text additional shown text
   * @param string $reference internal reference number
   * @return QrBill\QrBill
   */
  public function getQrBillInstance($receiver, $payer, $currency, $amount, $text, $reference)
  {
    // Load the library to be used within filters
    if (Strings::startsWith(phpversion(), '8')) {
      require_once File::getResourcePath() . '/libraries/swiss-qr-bill-v4/vendor/autoload.php';
    } else {
      require_once File::getResourcePath() . '/libraries/swiss-qr-bill/vendor/autoload.php';
    }
    $qrBill = QrBill\QrBill::create();

    // Who will receive the payment
    $qrBill->setCreditor(
      QrBill\DataGroup\Element\CombinedAddress::create(
        $receiver[0],
        $receiver[1],
        $receiver[2],
        $receiver[3]
      ));

    // Set QR Iban
    $qrBill->setCreditorInformation(
      QrBill\DataGroup\Element\CreditorInformation::create(
        $this->settings['qrIban']
      ));


    // Set the payer info
    $qrBill->setUltimateDebtor(
      QrBill\DataGroup\Element\CombinedAddress::create(
        $payer[0],
        $payer[1],
        $payer[2],
        $payer[3]
      ));

    // What amount is to be paid?
    $qrBill->setPaymentAmountInformation(
      QrBill\DataGroup\Element\PaymentAmountInformation::create(
        $currency,
        $amount
      ));

    // Create reference object and add it
    $referenceNumber = QrBill\Reference\QrPaymentReferenceGenerator::generate(
      $this->settings['qrRefno'],
      $reference
    );
    $qrBill->setPaymentReference(
      QrBill\DataGroup\Element\PaymentReference::create(
        QrBill\DataGroup\Element\PaymentReference::TYPE_QR,
        $referenceNumber
      ));

    // Add additional text
    $qrBill->setAdditionalInformation(
      QrBill\DataGroup\Element\AdditionalInformation::create($text)
    );

    return $qrBill;
  }

  /**
   * @param string $type
   * @param \WC_Order $order
   */
  public function addQrPaymentSlip($type, $order)
  {
    if($type != 'invoice'){
      return;
    }

    // Create the arrays for $receiver/payer
    $shopCountry = substr(get_option('woocommerce_default_country'), 0, 2);
    $receiver = array(
      get_option('woocommerce_store_address'),
      get_option('woocommerce_store_address_2'),
      get_option('woocommerce_store_postcode') . ' ' . get_option('woocommerce_store_city'),
      $shopCountry
    );

    // Get the payer data from $orders user
    $name = $order->get_billing_company();
    if (strlen($name) == 0) {
      $name = trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());
    }

    $userCountry = $order->get_billing_country();
    $payer = array(
      $name,
      $order->get_billing_address_1(),
      $order->get_billing_postcode() . ' ' . $order->get_billing_city(),
      strlen($userCountry) > 0 ? $userCountry : $shopCountry
    );

    // Get the full QR object to create the slip
    $qrBill = $this->getQrBillInstance(
      $receiver,
      $payer,
      $order->get_currency(),
      $order->get_total(),
      __('Bestellung', 'lbwp') . ' ' . $order->get_id() . ' / ' . getLbwpHost(),
      $order->get_id()
    );

    // Print a html version right here
    $output = new HtmlOutput($qrBill, Multilang::getCurrentLang('slug', 'de'));
    try {
      $html = $output->setPrintable(false)->setQrCodeImageFormat('png')->getPaymentPart();
    } catch (\Exception $e) {
      $html = '';
      /** @var \Symfony\Component\Validator\ConstraintViolation $violation */
      foreach ($qrBill->getViolations() as $violation) {
        $html .= $violation->getPropertyPath() . ': ' . $violation->getMessage() . '<br>';
      }
    }

    // Add some minimal additional styles to fit into our own pdf
    $html = '
      ' . $html . '
      <style>
        #qr-bill-separate-info-text, #qr-bill-acceptance-point {
          display: none !important;
        }
        #qr-bill-receipt {
          padding-left: 0px !important;
        }
        #qr-bill-swiss-qr-image {
          margin: 3mm !important;
          margin-left: 0 !important;
        }
        #qr-bill-amount-area div {
          float: none !important;
        }
        #qr-bill-payment-part{
          display: flex !important;
          width:100mm !important;
        }
        #qr-bill-payment-part p {
          font-size: 8pt !important;
          line-height: 9pt !important;
        }
        #qr-bill-payment-part-left{
          float: left !important;
        }
        #qr-bill, #qr-bill tr, #qr-bill tr td, #qr-bill tr th{
          page-break-inside: avoid !important;
        }
      </style>
    ';

    echo $html;
  }
}