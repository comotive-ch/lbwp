<?php

namespace LBWP\Aboon\Component;

use LBWP\Newsletter\Core as NLCore;
use LBWP\Theme\Component\ACFBase;
use LBWP\Theme\Feature\LocalMailService;
use LBWP\Theme\Feature\SwissQrIban;
use LBWP\Util\Strings;

/**
 * Provide general checkout functions
 * @package LbwpSubscriptions\Component
 * @author Michael Sebel <michael@comotive.ch>
 */
class Checkout extends ACFBase
{

  /**
   * Initialize the backend component, which is nice
   */
  public function init()
  {
    $this->registerQrIban();
    // Change some various settings with filters
    add_filter('woocommerce_billing_fields', array($this, 'alterBillingFieldConfig'), 10, 1);
    add_filter('woocommerce_shipping_fields', array($this, 'alterShippingFieldConfig'), 10, 1);
    add_filter('woocommerce_get_country_locale', array($this, 'setStateRequired'), 10, 1);
    add_filter('woocommerce_default_address_fields', array($this, 'alterAddressFields'), 10, 1);
    add_action('wpo_wcpdf_after_order_details', array($this, 'addPaidInfoToBilling'), 10, 2);
    add_action('wpo_wcpdf_before_document_label', array($this, 'addHeaderPaidInfo'), 10, 2);
    add_action('woocommerce_checkout_after_customer_details', array($this, 'showNewsletterCheckbox'));
    add_action('woocommerce_before_order_object_save', array($this, 'maybeAddNewsletterSubscriber'));
  }

  /**
   * Adds field settings
   */
  public function fields()
  {
    // Hook into main settings page
    add_action('aboon_general_settings_page', array($this, 'addSettingsFields'));
  }

  /**
   * Registers no own blocks
   */
  public function blocks() {}

  /**
   * @param array $fields
   * @return array
   */
  public function alterBillingFieldConfig($fields)
  {
    $this->configCheckoutField($fields, 'phone', 'phone', 'billing');
    $this->configCheckoutField($fields, 'company', 'company', 'billing');
    $this->configCheckoutField($fields, 'state', 'canton', 'billing');

    return $fields;
  }

  /**
   * @param array $fields
   * @return array
   */
  public function alterShippingFieldConfig($fields)
  {
    $this->configCheckoutField($fields, 'company', 'company', 'shipping');
    $this->configCheckoutField($fields, 'state', 'canton', 'shipping');

    return $fields;
  }

  /**
   * @param array $locale
   * @return array locales maybe changed
   */
  public function setStateRequired($locale)
  {
    if ($this->getCheckoutField('canton') === 'required') {
      foreach ($locale as $key => $sub) {
        $locale[$key]['state']['required'] = true;
      }
    }

    return $locale;
  }

  /**
   * @param array $fields
   * @param string $field
   * @param string $option
   */
  protected function configCheckoutField(&$fields, $field, $option, $type)
  {
    $value = $this->getCheckoutField($option);
    if ($value === 'not-required') {
      $fields[$type . '_' . $field]['required'] = false;
    } else if ($value === 'required') {
      $fields[$type . '_' . $field]['required'] = true;
    } else if ($value === 'disabled') {
      unset($fields[$type . '_' . $field]);
    }
  }

  /**
   * @param string $field one of address, canton or phone
   * @return string required, not-required or disabled
   */
  protected function getCheckoutField($field)
  {
    $value = get_option('options_checkout-fields_' . $field);
    if (empty($value) || $value === false) {
      $value = 'required';
    }

    return $value;
  }

  /**
   * @param $fields
   * @return mixed
   */
  public function alterAddressFields($fields)
  {
    $fields['address_1']['label'] = 'Strasse';
    $fields['address_1']['placeholder'] = 'Strasse und Hausnummer';
    $fields['address_2']['placeholder'] = 'Adresszusatz, wenn vorhanden';
    // Check to unrequire or completely disable the fields
    $setting = $this->getCheckoutField('address');
    if ($setting === 'not-required') {
      $fields['address_1']['required'] = false;
      $fields['address_2']['required'] = false;
      $fields['postcode']['required'] = false;
      $fields['city']['required'] = false;
    } else if ($setting === 'disabled') {
      unset($fields['address_1']);
      unset($fields['address_2']);
      unset($fields['postcode']);
      unset($fields['city']);
    }
    // Remove address_2 if requred so
    if ($this->getCheckoutField('address2') == 'disabled') {
      unset($fields['address_2']);
    }

    return $fields;
  }

  /**
   * @param string $type
   * @param \WC_Order $order
   */
  public function addPaidInfoToBilling($type, $order)
  {
    if ($type == 'invoice' && ($order->get_status() == 'completed' && $order->get_payment_method() == 'payrexx')) {
      echo '
        <span style="display:inline-block; background-color:#000; font-weight:bold; color:#fff; padding: 5px">
          ' . sprintf(__('Bereits bezahlt via %s', 'lbwp'), $order->get_payment_method_title()) . '
        </span>
      ';
    }
  }

  public function addHeaderPaidInfo($type, $order){
    if(apply_filters('aboon_show_paid_label', true)) {
      $orderStatus = $order->get_status();
      if ($orderStatus == 'completed' || $orderStatus == 'processing') {
        echo '<div class="order-paid-info"><i>' . __('Bezahlt', 'lbwp') . '</i></div>';
      }
    }
  }

  /**
   * If configured, register ibanc ode
   */
  protected function registerQrIban()
  {
    $qr = get_option('options_qr-billing-active');
    if (is_array($qr) && $qr[0] == 1) {
      SwissQrIban::init(array(
        'qrIban' => get_option('options_qr-billing-iban'),
        'qrRefno' => get_option('options_qr-billing-refno'),
      ));
    }
  }

  /**
   * Choices for the dynamic list id ACF field
   */
  protected function getNewsletterSubscriptionChoices()
  {
    $choices = array();

    // See if we have actual post type lists from newsletter lists
    $lists = get_posts(array(
      'post_type' => LocalMailService::LIST_TYPE_NAME,
      'posts_per_page' => -1,
      'orderby' => 'title',
      'order' => 'ASC'
    ));

    foreach ($lists as $list) {
      $type = get_post_meta($list->ID, 'list-type', true);
      if ($type == 'static') {
        $choices[$list->ID] = $list->post_title;
      }
    }

    return $choices;
  }

  /**
   * Show newsletter checkbox eventually
   */
  public function showNewsletterCheckbox()
  {
    $value = get_option('options_show-newsletter-subscription');

    // Return if setting not set or off
    if ($value == 'off' || empty($value)) {
      return;
    }

    // Get actual detail settings
    list($on, $default) = explode('-', $value);

    // See if there is a valid list id and label set
    $listId = intval(get_option('options_newsletter-subscription-list-id'));
    $label = get_option('options_newsletter-subscription-checkbox-text');
    $title = get_option('options_newsletter-subscription-title-text');
    if ($listId == 0 || strlen($label) == 0) {
      return;
    }

    // It should cleary work to call localmailservice now
    $lms = LocalMailService::getInstance();
    $list = $lms->getListData($listId);
    // Wrap a h3 if we have a title
    if (strlen($title) > 0) {
      $title = '<h3>' . $title . '</h3>';
    }

    // Is the user logged in and already subscibed, return as well
    if (is_user_logged_in() && is_array($list)) {
      $user = wp_get_current_user();
      foreach ($list as $entry) {
        if ($entry['email'] == $user->user_email) {
          return;
        }
      }
    }

    // Display the field for subscription
    echo '
      <div class="form-row crm-checkout-field form-row-wide" id="newsletter-subscribe-field">
        ' . $title . '
        <span class="woocommerce-input-wrapper">
          <label for="newsletter-subscribe">
            <input type="checkbox" class="input-checkbox" name="newsletter-subscribe" id="newsletter-subscribe" value="' . $listId . '" ' . checked('on', $default, false) . '> ' . $label . '
          </label>
        </span>
      </div>
    ';
  }

  /**
   * Subscribe to newsletter, if given
   */
  public function maybeAddNewsletterSubscriber()
  {
    $listId = intval($_POST['newsletter-subscribe']);
    $email = strtolower($_POST['billing_email']);

    // Leave, if no list is given (hence check was not set) or invalid email
    if ($listId == 0 || !Strings::checkEmail($email) || isset($_SESSION['Checkout_NL_Subscribe'])) {
      return;
    }

    // Get the newsletter service object, to run a subscription
    $_SESSION['Checkout_NL_Subscribe'] = 1;
    $newsletter = NLCore::getInstance();
    $service = $newsletter->getService();
    $service->subscribe(array(
      'email' => $email,
      'firstname' => $_POST['billing_first_name'],
      'lastname' => $_POST['billing_last_name']
    ), $listId);
  }

  /**
   * Adds settings for the given features
   */
  public function addSettingsFields()
  {
    acf_add_local_field_group(array(
      'key' => 'group_5feaeda083732',
      'title' => 'Einstellungen Kasse',
      'fields' => array(
        array(
          'key' => 'field_5feaede8f16a8',
          'label' => 'Einstellungen für Felder',
          'name' => 'checkout-fields',
          'type' => 'group',
          'instructions' => '',
          'required' => 0,
          'conditional_logic' => 0,
          'wrapper' => array(
            'width' => '',
            'class' => '',
            'id' => '',
          ),
          'layout' => 'row',
          'sub_fields' => array(
            array(
              'key' => 'field_5feaee0af16a9',
              'label' => 'Adresse',
              'name' => 'address',
              'type' => 'select',
              'instructions' => '',
              'required' => 0,
              'conditional_logic' => 0,
              'wrapper' => array(
                'width' => '',
                'class' => '',
                'id' => '',
              ),
              'choices' => array(
                'required' => 'Adressdaten sind Pflichtfelder',
                'not-required' => 'Adressdaten sind nicht Pflicht',
                'disabled' => 'Adressdaten werden nicht abgefragt',
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
              'key' => 'field_5feaee0af1610',
              'label' => 'Zweite Adresszeile',
              'name' => 'address2',
              'type' => 'select',
              'instructions' => '',
              'required' => 0,
              'conditional_logic' => 0,
              'wrapper' => array(
                'width' => '',
                'class' => '',
                'id' => '',
              ),
              'choices' => array(
                'enabled' => 'Zeile anzeigen als Optional',
                'disabled' => 'Zeile nicht anzeigen',
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
              'key' => 'field_5feaee6af16aa',
              'label' => 'Kanton',
              'name' => 'canton',
              'type' => 'select',
              'instructions' => '',
              'required' => 0,
              'conditional_logic' => 0,
              'wrapper' => array(
                'width' => '',
                'class' => '',
                'id' => '',
              ),
              'choices' => array(
                'required' => 'Kanton ist Pflichtfeld',
                'not-required' => 'Kanton ist nicht Pflicht',
                'disabled' => 'Kanton wird nicht abgefragt',
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
              'key' => 'field_5feaee94f16ab',
              'label' => 'Telefon',
              'name' => 'phone',
              'type' => 'select',
              'instructions' => '',
              'required' => 0,
              'conditional_logic' => 0,
              'wrapper' => array(
                'width' => '',
                'class' => '',
                'id' => '',
              ),
              'choices' => array(
                'required' => 'Telefon ist Pflichtfeld',
                'not-required' => 'Telefon ist nicht Pflicht',
                'disabled' => 'Telefon wird nicht abgefragt',
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
              'key' => 'field_3feaee94f16ab',
              'label' => 'Firma',
              'name' => 'company',
              'type' => 'select',
              'instructions' => '',
              'required' => 0,
              'conditional_logic' => 0,
              'wrapper' => array(
                'width' => '',
                'class' => '',
                'id' => '',
              ),
              'choices' => array(
                'required' => 'Firma ist Pflichtfeld',
                'not-required' => 'Firma ist nicht Pflicht',
                'disabled' => 'Firma wird nicht abgefragt',
              ),
              'default_value' => false,
              'allow_null' => 0,
              'multiple' => 0,
              'ui' => 0,
              'return_format' => 'value',
              'ajax' => 0,
              'placeholder' => '',
            ),
          ),
        ),
        array(
          'key' => 'field_5fcf6908d3117',
          'label' => 'Erzwinge wiederkehrende Zahlungen',
          'name' => 'force-recurring-payments',
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
            1 => 'Abonnemente können nur gekauft werden, wenn ein wiederkehrend Belastbares Zahlungsmittel ausgewählt wird',
          ),
          'allow_custom' => 0,
          'default_value' => array(),
          'layout' => 'vertical',
          'toggle' => 0,
          'return_format' => 'value',
          'save_custom' => 0,
        ),
        array(
          'key' => 'field_5fcf6909d3117',
          'label' => 'Erlaube es, gekündigte Abonnemente wieder zu aktivieren',
          'name' => 'allow-cancel-reactivation',
          'type' => 'checkbox',
          'instructions' => 'Ist diese Checkbox aktiv, können Administratoren und Shop-Manager gekündigte Abonnemente wieder aktivieren. Standardmässig ist das nicht möglich, da dies gegen den Kundenwillen geschehen könnte und um so Missbrauch zu verhindern. Bitte verwenden Sie diese Funktion nur mit Einwilligung des Kunden.',
          'required' => 0,
          'conditional_logic' => 0,
          'wrapper' => array(
            'width' => '',
            'class' => '',
            'id' => '',
          ),
          'choices' => array(
            1 => 'Administratoren und Shop-Manager dürfen gekündigte Abonnemente wieder aktivieren',
          ),
          'allow_custom' => 0,
          'default_value' => array(),
          'layout' => 'vertical',
          'toggle' => 0,
          'return_format' => 'value',
          'save_custom' => 0,
        ),
        array(
          'key' => 'field_6197720ad36ce',
          'label' => 'Newsletter Anmeldung',
          'name' => 'show-newsletter-subscription',
          'type' => 'radio',
          'instructions' => '',
          'required' => 0,
          'conditional_logic' => 0,
          'wrapper' => array(
            'width' => '',
            'class' => '',
            'id' => '',
          ),
          'choices' => array(
            'off' => 'Keine Newsletter Anmeldung anzeigen',
            'on-off' => 'Möglichkeit zur Anmeldung anzeigen, standardmässig nicht ausgewählt',
            'on-on' => 'Möglichkeit zur Anmeldung anzeigen, standardmässig ausgewählt',
          ),
          'allow_null' => 0,
          'other_choice' => 0,
          'default_value' => '',
          'layout' => 'vertical',
          'return_format' => 'value',
          'save_other_choice' => 0,
        ),
        array(
          'key' => 'field_619772a25ceee',
          'label' => 'Titel über der Checkbox (Optional)',
          'name' => 'newsletter-subscription-title-text',
          'type' => 'text',
          'instructions' => 'Dieser Text wird, sofern ausgefüllt oberhalb der Checkbox zur Anmeldung gezeigt',
          'required' => 0,
          'conditional_logic' => array(
            array(
              array(
                'field' => 'field_6197720ad36ce',
                'operator' => '!=',
                'value' => 'off',
              ),
            ),
          ),
          'wrapper' => array(
            'width' => '',
            'class' => '',
            'id' => '',
          ),
          'default_value' => '',
          'placeholder' => '',
          'prepend' => '',
          'append' => '',
          'maxlength' => '',
        ),
        array(
          'key' => 'field_619772a25c59f',
          'label' => 'Text neben Checkbox',
          'name' => 'newsletter-subscription-checkbox-text',
          'type' => 'text',
          'instructions' => 'Dieser Text wird neben der Checkbox zur Anmeldung gezeigt z.b. "Newsletter anmelden" oder eine ähnliche Aufforderung',
          'required' => 0,
          'conditional_logic' => array(
            array(
              array(
                'field' => 'field_6197720ad36ce',
                'operator' => '!=',
                'value' => 'off',
              ),
            ),
          ),
          'wrapper' => array(
            'width' => '',
            'class' => '',
            'id' => '',
          ),
          'default_value' => '',
          'placeholder' => '',
          'prepend' => '',
          'append' => '',
          'maxlength' => '',
        ),
        array(
          'key' => 'field_619772e85c5a0',
          'label' => 'Liste wählen',
          'name' => 'newsletter-subscription-list-id',
          'type' => 'select',
          'instructions' => 'Käufer werden nach Optin/Double-Optin auf dieser Liste eingetragen',
          'required' => 0,
          'conditional_logic' => array(
            array(
              array(
                'field' => 'field_6197720ad36ce',
                'operator' => '!=',
                'value' => 'off',
              ),
            ),
          ),
          'wrapper' => array(
            'width' => '',
            'class' => '',
            'id' => '',
          ),
          'choices' => (isset($_GET['page']) && $_GET['page'] == 'aboon-display') ? $this->getNewsletterSubscriptionChoices() : array(),
          'default_value' => false,
          'allow_null' => 0,
          'multiple' => 0,
          'ui' => 0,
          'return_format' => 'value',
          'ajax' => 0,
          'placeholder' => '',
        ),
      ),
      'location' => array(
        array(
          array(
            'param' => 'options_page',
            'operator' => '==',
            'value' => 'aboon-display',
          ),
        ),
      ),
      'menu_order' => 0,
      'position' => 'normal',
      'style' => 'default',
      'label_placement' => 'left',
      'instruction_placement' => 'field',
      'hide_on_screen' => '',
      'active' => true,
      'description' => '',
    ));

    acf_add_local_field_group(array(
      'key' => 'group_5fcf68fc30207',
      'title' => 'QR Einzahlungsscheine (Schweiz)',
      'fields' => array(
        array(
          'key' => 'field_5fcf6908c3117',
          'label' => 'Funktion aktivieren',
          'name' => 'qr-billing-active',
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
            1 => 'QR-Einzahlungsscheine in Rechnungen aktivieren',
          ),
          'allow_custom' => 0,
          'default_value' => array(),
          'layout' => 'vertical',
          'toggle' => 0,
          'return_format' => 'value',
          'save_custom' => 0,
        ),
        array(
          'key' => 'field_5fcf697b275ef',
          'label' => 'QR-IBAN',
          'name' => 'qr-billing-iban',
          'type' => 'text',
          'instructions' => 'Die QR-IBAN ist eine von deiner IBAN abweichende Nummer die dir deine Bank auf Anfrage mitteilt und aktiviert.',
          'required' => 0,
          'conditional_logic' => 0,
          'wrapper' => array(
            'width' => '',
            'class' => '',
            'id' => '',
          ),
          'default_value' => '',
          'placeholder' => '',
          'prepend' => '',
          'append' => '',
          'maxlength' => '',
        ),
        array(
          'key' => 'field_5fcf699f275f0',
          'label' => 'Referenznummer',
          'name' => 'qr-billing-refno',
          'type' => 'text',
          'instructions' => 'Die Referenznummer bekommst du zusammen mit der QR-IBAN von der Bank. PostFinance Kunden können dieses Feld leer lassen.',
          'required' => 0,
          'conditional_logic' => 0,
          'wrapper' => array(
            'width' => '',
            'class' => '',
            'id' => '',
          ),
          'default_value' => '',
          'placeholder' => '',
          'prepend' => '',
          'append' => '',
          'maxlength' => '',
        ),
      ),
      'location' => array(
        array(
          array(
            'param' => 'options_page',
            'operator' => '==',
            'value' => 'aboon-display',
          ),
        ),
      ),
      'menu_order' => 0,
      'position' => 'normal',
      'style' => 'default',
      'label_placement' => 'left',
      'instruction_placement' => 'label',
      'hide_on_screen' => '',
      'active' => true,
      'description' => '',
    ));
  }
} 