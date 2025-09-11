<?php

namespace LBWP\Module\Forms\Item;

use LBWP\Module\Frontend\HTMLCache;
use LBWP\Util\ArrayManipulation;
use LBWP\Module\Forms\Component\FormHandler;
use LBWP\Util\Strings;
use LBWP\Util\WordPress;
use Payrexx\Communicator;
use Payrexx\Models\Response\Invoice;
use Payrexx\Models\Response\Transaction;
use function Symfony\Component\String\s;


/**
 * This will display payment button
 * @package LBWP\Module\Forms\Item
 * @author Michael Sebel <michael@comotive.ch>
 */
class Payrexx extends Base
{
  /**
   * @var array set the field config for this field
   */
  protected $fieldConfig = array(
    'name' => 'Bezahlung anfordern',
    'help' => 'Veraltet, bitte nicht mehr verwenden',
    'group' => 'Spezial-Felder'
  );

  /**
   * @var bool set to true ig payment has been done
   */
  protected $paymentStatus = false;

  /**
   * Extend the parameter configuration for the editor
   */
  protected function setParamConfig()
  {
    $this->paramConfig = ArrayManipulation::deepMerge($this->paramConfig, array(
      'instance_name' => array(
        'name' => 'Payrexx Hostname ID',
        'help' => 'Die Hostname ist z.b. IHRE_INSTANZ.payrexx.com oder IHRE_INSTANZ.payrexx.aboon.ch',
        'type' => 'textfield'
      ),
      'instance_api_secret' => array(
        'name' => 'Payrexx API Secret',
        'help' => 'API Zugriffschlüssel, der in der Payrexx Administrations-Oberfläche erstellt werden kann',
        'type' => 'textfield'
      ),
      'payment_page_title' => array(
        'name' => 'Titel Bezahlseite',
        'type' => 'textfield'
      ),
      'payment_page_description' => array(
        'name' => 'Beschreibung Bezahlseite',
        'type' => 'textfield'
      ),
      'payment_purpose' => array(
        'name' => 'Zahlungsgrund',
        'help' => 'Internes Bezeichnungsfeld, damit die Zahlungen in Payrexx unterschieden werden können',
        'type' => 'textfield'
      ),
      'payment_sku' => array(
        'name' => 'Artikelnummer',
        'help' => 'Internes Artikelnummern Feld, damit die Zahlungen in Payrexx *noch eindeutiger* unterschieden werden können',
        'type' => 'textfield'
      ),
      'payment_amount' => array(
        'name' => 'Betrag (z.B. 9.90)',
        'help' => 'Der Betrag ist fix als Standard-Wert hinterlegt, kann mit der folgenden Option aber  geändert werden.',
        'type' => 'textfield'
      ),
      'payment_changeable' => array(
        'name' => 'Betrag kann geändert werden',
        'help' => 'Der Betrag ist fix als Standard-Wert hinterlegt, kann mit der folgenden Option aber geändert werden.',
        'type' => 'radio',
        'values' => array(
          '1' => 'Ja',
          '0' => 'Nein'
        )
      ),
      'fields_amount' => array(
        'name' => 'Feld für dynamischen Betrag',
        'help' => 'Bitte verknüpfen Sie aus dem Formular das Feld mit dem Betrag. Dies kann ein Auswahl- oder Textfeld sein.',
        'type' => 'textfield'
      ),
      'payment_cta' => array(
        'name' => 'Call-to-Action nach Betrag',
        'help' => 'Standardmässig "jetzt bezahlen", kann mit diesem Feld überschrieben werden.',
        'type' => 'textfield'
      ),
      'redirect_url' => array(
        'name' => 'Weiterleitung nach Zahlung',
        'help' => 'URL an die nach erfolgreicher Zahlung weitergeleitet wird.',
        'type' => 'textfield'
      ),
      'payment_currency' => array(
        'name' => 'Währung',
        'type' => 'radio',
        'values' => array(
          'CHF' => 'CHF',
          'EUR' => 'EUR'
        )
      ),
      'payment_vat' => array(
        'name' => 'Mehrwertsteuer (z.b 7.7)',
        'type' => 'textfield'
      ),
      'payment_vat_included' => array(
        'name' => 'Mehrwertsteuer im Preis inbegriffen?',
        'type' => 'radio',
        'values' => array(
          '1' => 'Ja',
          '0' => 'Nein'
        )
      ),
      'fields_email' => array(
        'name' => 'E-Mail Feld',
        'help' => 'Bitte verknüpfen Sie aus dem Formular das Feld mit der E-Mail des Zahlers',
        'type' => 'textfield'
      ),
      'fields_firstname' => array(
        'name' => 'Vorname Feld',
        'help' => 'Bitte verknüpfen Sie aus dem Formular das Feld mit dem Vornamen des Zahlers',
        'type' => 'textfield'
      ),
      'fields_lastname' => array(
        'name' => 'Nachname Feld',
        'help' => 'Bitte verknüpfen Sie aus dem Formular das Feld mit dem Nachnamen des Zahlers',
        'type' => 'textfield'
      ),
      'fields_company' => array(
        'name' => 'Firma Feld',
        'help' => 'Bitte verknüpfen Sie aus dem Formular das Feld mit der Firma des Zahlers',
        'type' => 'textfield'
      ),
      'test_modus' => array(
        'name' => 'Test-Modus aktiv (Nur Zahlungen mit Testkarten möglich)',
        'type' => 'radio',
        'values' => array(
          '1' => 'Ja',
          '0' => 'Nein'
        )
      ),
    ));

    // Don't let visiblity be configured
    unset($this->paramConfig['visible']);
  }

  /**
   * @param string $key the key of the item
   */
  public function load($key)
  {
    // Set the id
    $this->params['id'] = $key . '_' . $this->formHandler->getNextId();
    $this->params['key'] = $key;
    $this->params['description'] = 'Bezahlung mit Payrexx';
  }

  /**
   * @param array $args the shortcode params
   * @param string $content the shortcode content
   * @return string HTML code to display
   */
  public function getElement($args, $content)
  {
    // Forms with that element cannot be cached
    HTMLCache::avoidCache();
    // Always set invisible
    $attr = $this->getDefaultAttributes($args);
    $attr .= ' data-amount-field="' . $this->params['fields_amount'] . '"';

    $key = Strings::getRandom(60);
    // Make the field(s)
    $text = '<span class="payment_value">' . $this->params['payment_amount'] . '</span>' . ($this->params['payment_vat_included'] == 0 ? ' (exkl. MwSt.) ' : '');
    // Extend with payment cta if given
    if (strlen($this->params['payment_cta']) > 0) {
      $text .= ' ' . $this->params['payment_cta'];
    } else {
      $text .= ' jetzt bezahlen';
    }
    $fields = '
      <a href="" id="payrexx-button" target="_blank">' . $this->params['payment_currency'] . ' ' . $text . '</a>
      <span></span>
      <input type="hidden" id="payrexx-data" name="dataKey" value="' . $key . '" ' . $attr . ' />
      <input type="hidden" id="payrexx-result" ' . $attr . '>
    ';

    // Save payment information to cache to be able to read it in ajax requests
    wp_cache_set($key, $this->params, 'Payrexx', 1800);

    $html = Base::$template;
    $html = str_replace('{id}', $this->get('id'), $html);
    $html = str_replace('{label}', $args['feldname'], $html);
    $html = str_replace('{class}', trim('payrexx-payment' . $this->params['class']), $html);
    $html = str_replace('{field}', $fields, $html);

    return $html;
  }

  /**
   * @param array $args the shortcode params
   * @return string the user value, if the form has been sent
   */
  public function getValue($args = array())
  {
    // Get the value from post, if set
    if (isset($_POST[$this->get('id')])) {
      return $_POST[$this->get('id')];
    }

    return '';
  }

  /**
   * @param $data array the data for the payment link
   */
  private function setPaymentData($data){
    $this->paymentData = $data;
  }

  /**
   * Setup payrexx payment link
   * @throws \Payrexx\PayrexxException
   */
  public static function setupPaymentLink()
  {
    require_once ABSPATH . '/wp-content/plugins/lbwp/resources/libraries/payrexx/autoload.php';

    $prxData = wp_cache_get($_POST['dataKey'], 'Payrexx');
    if ($prxData === false || strlen($prxData['instance_name']) == 0 || strlen($prxData['instance_api_secret']) == 0) {
      WordPress::sendJsonResponse('cancelled');
    }

    // $instanceName is a part of the url where you access your payrexx installation.
    $apiDomain = Communicator::API_URL_BASE_DOMAIN;
    if (str_contains($prxData['instance_name'], '.')) {
      $instanceName = explode('.', $prxData['instance_name'])[0];
      $apiDomain = str_replace($instanceName . '.', '', $prxData['instance_name']);
    } else {
      $instanceName = $prxData['instance_name'];
    }

    // $secret is the payrexx secret for the communication between the applications
    // if you think someone got your secret, just regenerate it in the payrexx administration
    $secret = $prxData['instance_api_secret'];
    $payrexx = new \Payrexx\Payrexx($instanceName, $secret, '', $apiDomain);

    $paymentId = ($_POST['invoiceId'] == 'false') ? false : $_POST['invoiceId'];

    $getPrxAmount = json_decode($_POST['fields']);
    $prxAmount = floatval($getPrxAmount[array_search($_POST['variableAmountId'], array_column($getPrxAmount, 0))][1]);
    $prxData['payment_amount'] = $prxAmount !== 0 ? $prxAmount : $prxData['payment_amount'];

    if($paymentId !== false) {
      $invoice = new Invoice();
      $invoice->setId($paymentId);
      $getInvoice = $payrexx->getOne($invoice);
      $status = $getInvoice->getStatus();

      WordPress::sendJsonResponse($status);

    } else {
      // init empty request object
      $invoice = new Invoice();

      // info for payment link (reference id)
      $invoice->setReferenceId($prxData['payment_purpose']);

      // info for payment page (title, description)
      $invoice->setTitle($prxData['payment_page_title']);
      $invoice->setDescription($prxData['payment_page_description']);

      // administrative information, which provider to use (psp)
      // psp #1 = Payrexx' test mode, see http://developers.payrexx.com/en/REST-API/Miscellaneous
      if ($prxData['test_modus'] == 1) {
        $invoice->setPsp(1); // set test psp
      }

      // internal data only displayed to administrator
      $invoice->setName($prxData['payment_purpose']);

      // VAT rate percentage (nullable)
      $vatRate = ($prxData['payment_vat'] == '') ? 7.70 : $prxData['payment_vat'];
      $amountWithVat = false;
      if ($prxData['payment_vat_included'] == 0) {
        $amountWithVat = $prxData['payment_amount'] + $prxData['payment_amount'] * ($vatRate/100);
      }
      $invoice->setVatRate($vatRate);

      // payment information
      $invoice->setPurpose($prxData['payment_purpose']);
      $amount = (!$amountWithVat) ? $prxData['payment_amount'] : $amountWithVat;
      // don't forget to multiply by 100
      $invoice->setAmount($amount * 100);

      //Product SKU
      $invoice->setSku($prxData['payment_sku']);

      // ISO code of currency, list of alternatives can be found here
      // http://developers.payrexx.com/en/REST-API/Miscellaneous
      $invoice->setCurrency($prxData['payment_currency']);

      // whether charge payment manually at a later date (type authorization)
      $invoice->setPreAuthorization(false);

      // whether charge payment manually at a later date (type reservation)
      $invoice->setReservation(false);
      if (Strings::checkURL($prxData['redirect_url'])) {
        $invoice->setSuccessRedirectUrl($prxData['redirect_url']);
      }

      // get input field value from JS
      $formInputs = json_decode($_POST['fields']);
      $fieldKeys = array(
        array_search($prxData['fields_email'], array_column($formInputs, 0)),
        array_search($prxData['fields_firstname'], array_column($formInputs, 0)),
        array_search($prxData['fields_lastname'], array_column($formInputs, 0)),
        array_search($prxData['fields_company'], array_column($formInputs, 0))
      );

      $fieldValues = array(
        'email' => $fieldKeys[0] !== false ? $formInputs[$fieldKeys[0]][1] : '',
        'forename' => $fieldKeys[1] !== false ? $formInputs[$fieldKeys[1]][1] : '',
        'surname' => $fieldKeys[2] !== false ? $formInputs[$fieldKeys[2]][1] : '',
        'company' => $fieldKeys[3] !== false ? $formInputs[$fieldKeys[3]][1] : ''
      );

      $invoice->addField($type = 'email', $mandatory = true, $defaultValue = $fieldValues['email']);
      $invoice->addField($type = 'forename', $mandatory = true, $defaultValue = $fieldValues['forename']);
      $invoice->addField($type = 'surname', $mandatory = false, $defaultValue = $fieldValues['surname']);
      $invoice->addField($type = 'company', $mandatory = false, $defaultValue = $fieldValues['company']);

      // fire request with created and filled link request-object.
      try {
        $prxResponse = $payrexx->create($invoice);
        $prxUrl = $prxResponse->getLink();
        $prxId = $prxResponse->getId();

        $jsonData = [
          'url' => $prxUrl,
          'id' => $prxId,
        ];

        WordPress::sendJsonResponse($jsonData);

      } catch (\Payrexx\PayrexxException $e) {
        print $e->getMessage();
      }
    }
  }
} 