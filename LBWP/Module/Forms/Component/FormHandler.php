<?php

namespace LBWP\Module\Forms\Component;

use LBWP\Core as LbwpCore;
use LBWP\Helper\DnsQuery;
use LBWP\Module\Forms\Component\ActionBackend\DataTable;
use LBWP\Module\Forms\Core;
use LBWP\Module\Forms\Item\PageBreak;
use LBWP\Module\General\Cms\SystemLog;
use LBWP\Theme\Feature\LbwpFormSettings;
use LBWP\Util\ArrayManipulation;
use LBWP\Util\Cookie;
use LBWP\Util\File;
use LBWP\Module\Frontend\HTMLCache;
use LBWP\Module\Forms\Item\Base as BaseItem;
use LBWP\Module\Forms\Action\Base as BaseAction;
use LBWP\Util\Strings;
use LBWP\Util\WordPress;

/**
 * This class provides the shortcodes that will create the actual form elements with sub components.
 * It also handles the whole item handling with adding and replacing form items.
 * @package LBWP\Module\Forms\Component
 * @author Michael Sebel <michael@comotive.ch>
 */
class FormHandler extends Base
{
  /**
   * @var array defaults for displaying the example form code
   */
  protected $formDefaults = array(
    'button' => 'Absenden',
    'meldung' => 'Das Formular wurde erfolgreich abgeschickt'
  );
  /**
   * @var array list of default items (key/id and classname)
   */
  protected $items = array(
    'textfield' => '\\LBWP\\Module\\Forms\\Item\\Textfield',
    'textarea' => '\\LBWP\\Module\\Forms\\Item\\Textarea',
    'zipcity' => '\\LBWP\\Module\\Forms\\Item\\ZipCity',
    'radio' => '\\LBWP\\Module\\Forms\\Item\\Radio',
    'checkbox' => '\\LBWP\\Module\\Forms\\Item\\Checkbox',
    'dropdown' => '\\LBWP\\Module\\Forms\\Item\\Dropdown',
    'required-note' => '\\LBWP\\Module\\Forms\\Item\\HtmlItem',
    'calculation' => '\\LBWP\\Module\\Forms\\Item\\Calculation',
    'upload' => '\\LBWP\\Module\\Forms\\Item\\Upload',
    'payrexx' => '\\LBWP\\Module\\Forms\\Item\\Payrexx',
    'hiddenfield' => '\\LBWP\\Module\\Forms\\Item\\Hiddenfield',
    'pagebreak' => '\\LBWP\\Module\\Forms\\Item\\PageBreak'
  );
  /**
   * @var array possible actions
   */
  protected $actions = array(
    'sendmail' => '\\LBWP\\Module\\Forms\\Action\\SendMail',
    'datatable' => '\\LBWP\\Module\\Forms\\Action\\DataTable',
    'web2lead' => '\\LBWP\\Module\\Forms\\Action\\Salesforce',
    'zapier' => '\\LBWP\\Module\\Forms\\Action\\Zapier',
    'brevo' => '\\LBWP\\Module\\Forms\\Action\\Brevo',
    'auto-close' => '\\LBWP\\Module\\Forms\\Action\\AutoClose',
    'savesession' => '\\LBWP\\Module\\Forms\\Action\\SaveSession',
    'savedynamic' => '\\LBWP\\Module\\Forms\\Action\\SaveDynamic',
  );
  /**
   * @var int provides ids trhought all created items
   */
  protected $idProvider = 0;
  /**
   * @var int the generated field ids counter
   */
  protected $generatedFieldIds = 0;
  /**
   * @var int the generated action ids counter
   */
  protected $generatedActionIds = 0;
  /**
   * @var BaseItem[] This array is filled upon generation of the actual form
   */
  protected $currentItems = array();
  /**
   * @var BaseAction[] This array is filled upon generation of the actual form
   */
  protected $currentActions = array();
  /**
   * @var \WP_Post currently executed form (if any is executed)
   */
  protected $currentForm = NULL;
  /**
   * @var array the current arguments of the executed form
   */
  protected $currentArgs = array();
  /**
   * @var string the form error message. Can be overriden by fields or actions
   */
  protected $formErrorMessage = '';
  /**
   * @var string content to add at the bottom of a form
   */
  protected $bottomContent = '';
  /**
   * @var bool tells if the resources were printed once
   */
  protected $addedAssets = false;
  /**
   * @var array eventual additional data that can be given from loadForm trough form display
   */
  protected $additionalArgs = array();
  /**
   * @var array the form field conditions for the current form
   */
  protected $conditions = array();
  /**
   * @var array contains the executed id's so forms don't get submit multiple times when added to a page multiple times
   */
  protected $executedFormIds = array();
  /**
   * @var int additional up-counted id to have distinguished ids even if a form is added twice in the same page
   */
  protected $idDistinguisher = 0;
  /**
   * @var bool tells if there was an error upon execution of the form
   */
  protected $executionError = false;
  /**
   * @var bool tells if the form is executing itself
   */
  public $executingForm = false;
  /**
   * @var bool tells if the form has a field error
   */
  public $fieldError = false;
  /**
   * @var bool show the back link
   */
  public $showBackLink = true;
  /**
   * @var bool provides outside information if the backend form is generated
   */
  public static $isBackendForm = false;
  /**
   * @var string name of the form shortcode
   */
  const SHORTCODE_FORM = 'lbwp:form';
  /**
   * @var string name of the item shortcode
   */
  const SHORTCODE_FORM_ITEM = 'lbwp:formItem';
  /**
   * @var string name of the item shortcode
   */
  const SHORTCODE_FORM_CONTENT_ITEM = 'lbwp:formContentItem';
  /**
   * @var string name of the item shortcode
   */
  const SHORTCODE_ACTION = 'lbwp:formAction';
  /**
   * @var string name of the display form shortcode
   */
  const SHORTCODE_DISPLAY_FORM = 'lbwp:formular';
  /**
   * @var string allowed tags in shortcode (for strip_tags)
   */
  const ALLOWED_TAGS = '<h1><h2><h3><h4><h5><p><div><span><section><strong><em><a><img><ul><ol><li><hr><br>';
  /**
   * @var string after submit cookie prefix
   */
  const AFTER_SUBMIT_COOKIE_PREFIX = 'lbwp_form_after_submit_';

  /**
   * Called at init(50)
   */
  public function initialize()
  {
    if (is_admin()) {
      // Execute actions while saving
      add_action('save_post_' . Posttype::FORM_SLUG, array($this, 'saveForm'));
    } else {
      // Add a global context variable for form field conditions
      add_action('wp_head', function () {
        echo '<script>var lbwpFormFieldConditions = [];</script>';
      });
    }

    // Shortcodes need to be executed in backend too for saving purposes
    add_shortcode(self::SHORTCODE_FORM, array($this, 'displayForm'));
    add_shortcode(self::SHORTCODE_FORM_ITEM, array($this, 'displayItem'));
    add_shortcode(self::SHORTCODE_FORM_CONTENT_ITEM, array($this, 'displayItem'));
    add_shortcode(self::SHORTCODE_ACTION, array($this, 'loadAction'));
    add_shortcode(self::SHORTCODE_DISPLAY_FORM, array($this, 'loadForm'));

    // Preset the error message
    $this->formErrorMessage = __('Fehler beim Absenden des Formulars', 'lbwp');

    // Filter the items, to allow adding of items
    $this->items = apply_filters('lbwpFormItems', $this->items);
    $this->actions = apply_filters('lbwpFormActions', $this->actions);

    // Add ajax
    add_action('wp_ajax_payrexx_payment', array('LBWP\Module\Forms\Item\Payrexx', 'setupPaymentLink'));
    add_action('wp_ajax_nopriv_payrexx_payment', array('LBWP\Module\Forms\Item\Payrexx', 'setupPaymentLink'));
    add_action('rest_api_init', array($this, 'registerApiEndpoint'));

		// Register cron to automatically delete upload files
		add_action('cron_daily_22', array($this, 'autoDeleteUploads'));
  }

  /**
   * Rest endpoints for nonce generation
   */
  public function registerApiEndpoint()
  {
    register_rest_route('lbwp/form', 'nonce', array(
      'methods' => \WP_REST_Server::CREATABLE,
      'callback' => array($this, 'getFormNonce')
    ));
    register_rest_route('lbwp/form', 'emailcheck', array(
      'methods' => \WP_REST_Server::CREATABLE,
      'callback' => array($this, 'getEmailCheck')
    ));
    register_rest_route('lbwp/form', 'survey', array(
      'methods' => \WP_REST_Server::CREATABLE,
      'callback' => array($this, 'getSurveyResult')
    ));
  }

  /**
   * Nonce is valid 45min, but is updated from frontend every 30min
   * @return array Retrieve a nonce code for form completion
   */
  public function getFormNonce()
  {
    $nonce = Strings::getRandom(32);
    wp_cache_set($nonce, 1, 'LbwpForm', 45*60);
    return array('nonce' => $nonce);
  }

  /**
   * @return void
   */
  public function getEmailCheck()
  {
    // Basically assume success
    $email = strtolower(trim($_POST['email']));
    $response = array('success' => 1, 'message' => '');
    // Get domain from email address
    $domain = explode('@', $email)[1];
    if (strlen($domain) > 0) {
      if (!DnsQuery::domainExists($domain)) {
        $response['success'] = 0;
        $response['message'] = 'domain does not exist';
      } else if (!DnsQuery::domainHasMxRecord($domain)) {
        $response['success'] = 0;
        $response['message'] = 'domain has no mx records';
      }
    } else {
      $response['success'] = 0;
      $response['message'] = 'domain not valid';
    }

    return $response;
  }

  /**
   * Shows survey results for a specific form
   * @return string[]
   */
  public function getSurveyResult()
  {
    $html = '';
    list ($key, $formId) = explode('-', $_REQUEST['id']);
    // Load the actual form
    $form = get_post($formId);
    // See if the setting for survey results is enabled (by parsing the shortcode...)
    $isEnabled = str_contains($form->post_content, 'show_survey_results="1"');

    // Only gather results if enabled and valid
    if ($form->post_type == Posttype::FORM_SLUG && $isEnabled && $key == 'lbwpForm') {
      // From the form, find out which fields are survey fields (checkbox, radio, dropdown)
      $core = Core::getInstance();
      $surveyFields = array();
      do_shortcode(strip_tags($form->post_content, self::ALLOWED_TAGS));
      $items = $core->getFormHandler()->getCurrentItems();
      foreach ($items as $item) {
        // Only survey items
        if (is_a($item, '\\LBWP\\Module\\Forms\\Item\\Checkbox') || is_a($item, '\\LBWP\\Module\\Forms\\Item\\Radio') || is_a($item, '\\LBWP\\Module\\Forms\\Item\\Dropdown')) {
          $params = $item->getAllParams();
          $slug = Strings::forceSlugString($params['feldname']);
          $surveyFields[$slug] = array(
            'question' => $params['feldname'],
            'type' => $params['key'],
            'answers' => array_filter(explode('$$', $item->getContent())),
            'total' => 0,
            'countByAnswer' => array()
          );
          // Add empty countByAnswer for each answer
          foreach ($surveyFields[$slug]['answers'] as $answer) {
            $surveyFields[$slug]['countByAnswer'][$answer] = 0;
          }
        }
      }

      // Load the data table with the same id as the form
      $dataTable = new DataTable($core);
      $rawData = $dataTable->getTable($formId);

      foreach ($rawData['data'] as $row) {
        // For each row, count the actual answers to each question
        foreach ($surveyFields as $slug => $field) {
          // Skip if no data is given in that record
          if (!isset($row[$slug])) {
            continue;
          }

          if ($field['type'] == 'checkbox') {
            $answers = array_map('trim', explode(',', $row[$slug]));
          } else {
            $answers = array($row[$slug]);
          }

          foreach ($answers as $answer) {
            $surveyFields[$slug]['total']++;
            $surveyFields[$slug]['countByAnswer'][$answer]++;
          }
        }
      }

      // Define templates
      $tplQuestion = '
        <div class="lbwp-form-survey-question lbwp-form-survey-question__container">
          <span class="lbwp-form-survey-question__text">{question}</span>
          <div class="lbwp-form-survey-answers"> 
            {answers}
          </div>
        </div>
      ';
      $tplAnswer = '
        <div class="lbwp-form-survey-answer">
          <span class="lbwp-form-survey-answer__text">{answer}</span>
          <div class="lbwp-form-survey-answer__bar-wrapper"> 
            <div class="lbwp-form-survey-answer__bar">
              <div class="lbwp-form-survey-answer__bar-inner" style="width:{percent}%"></div>
            </div>
            <div class="lbwp-form-survey-answer__bar-appendix">{percent}%</div>
          </div>          
        </div>
      ';

      // We have the final results and can display them now
      foreach ($surveyFields as $field) {
        // If there are no answers, skip
        if ($field['total'] == 0) {
          continue;
        }

        // Prepare the answers
        $answersHtml = '';
        foreach ($field['countByAnswer'] as $answer => $count) {
          $percent = round(($count / $field['total']) * 100, 0);
          $answersHtml .= str_replace(
            array('{answer}', '{percent}'),
            array($answer, $percent),
            $tplAnswer
          );
        }

        // Add the question with answers
        $html .= str_replace(
          array('{question}','{answers}'),
          array($field['question'], $answersHtml),
          $tplQuestion
        );
      }

      if ($field['total'] > 0 && strlen($html) > 0) {
        $html .= '
          <div class="lbwp-form-survey-totals-container">
            <span class="lbwp-form-survey-totals-text">' . sprintf(__('Teilnehmer: %s', 'lbwp'), $field['total']) . '</span>
          </div>
        ';
      }
    }

    return array(
      'html' => $html,
      'status' => 'success'
    );
  }

  /**
   * This actually displays the forms and the items. It also sends
   * the form, if the user has filled it out. This method also adds
   * the necessary CSS / Javascipt inline from the according files.
   * @param array $args the shortcode arguments
   * @param string $content the short codes inline content
   * @return string the form html code
   */
  public function displayForm($args, $content)
  {
    // get the form inner html by executing shortcodes in content
    $formHtml = do_shortcode(strip_tags($content, self::ALLOWED_TAGS));
    $displayForm = true;
    $html = '';
    $formclass = '';

    // Merge eventual arguments given
    $args = array_merge($args, $this->additionalArgs);
    $this->currentArgs = $args;


    // Override the id, if needed
    $formDisplayId = $this->currentForm->ID . '-' . (++$this->idDistinguisher);
    if (isset($args['id']) && strlen($args['id']) > 0) {
      $formDisplayId = $args['id'];
      // Set a correct id so it doesn't get mixed up with "normal" forms
      $this->currentForm = new \stdClass();
      $this->currentForm->ID = $formDisplayId;
    }

    // See if the form has been sent, and execute the actions
    $message = $messageHtml = '';
    if (isset($_POST['lbwpFormSend']) && $this->currentForm->ID == $_POST['sentForm']) {
      if ($this->formIsSecure()) {
        // Don't execute twice
        if (!isset($this->executedFormIds[$formDisplayId])) {
          $message = $this->executeForm($args, $formDisplayId);
          $this->executedFormIds[$formDisplayId]++;
        }
        $this->idProvider = 0;

        // Do it again, because output could have been reset
        $formHtml = do_shortcode(strip_tags($content, self::ALLOWED_TAGS));
        $formclass .= ' submitted';
      } else {
        $message = $this->formErrorMessage;
      }
    }

    // Test actions if they throw an error
    if (strlen($message) == 0) {
      foreach ($this->currentActions as $action) {
        $message = $action->onDisplay($this->currentForm);
        if (strlen($message) > 0) {
          $displayForm = false;
          break;
        }
      }
    }

    // Force conditions to be an array, then print them to DOM as json object
    if (!is_admin()) {
      $this->conditions = ArrayManipulation::forceArray($this->conditions);
      $formHtml .= '
        <script type="text/javascript">
          lbwpFormFieldConditions["lbwpForm-' . $formDisplayId . '"] = ' . json_encode($this->conditions) . ';
        </script>
      ';
      // Flush conditions after printing for "the next" form
      $this->conditions = array();
    }

    // Set a custom action, if requested
    $customAction = 'action="' . esc_attr($_SERVER['REQUEST_URI'] . '#message-' . $formDisplayId) . '"';
    if (isset($args['action']) && strlen($args['action']) > 0) {
      $customAction = ' action="' . $args['action'] . '"';
    }
    if (isset($args['external_action_url']) && strlen($args['external_action_url']) > 0 && Strings::isURL($args['external_action_url'])) {
      $customAction = ' action="' . esc_attr($this->attachUrlParameters($args['external_action_url'])) . '"';
    }

    // If there is an after_submit, close the form after one submission, but only if the user is not editing
    if (!isset($_GET['tsid']) || strlen($_GET['tsid']) == 0) {
      if (isset($args['after_submit']) && strlen($args['after_submit']) > 0 && $this->hasSubmissionCookie($formDisplayId)) {
        // Don't cache the page, as this is user specific
        HTMLCache::avoidCache();
        $message = $args['after_submit'];
        $displayForm = false;
      }
    }

    // Always insert the anchor
    $messageHtml = '<a class="lbwp-form-anchor" id="message-' . $formDisplayId . '"></a>';

    // Add a message, if available
    if (strlen($message) > 0) {
      $class = ($this->executionError) ? 'error' : 'success';
      $formclass .= ' ' . $class;

      // Add hiding class, only if success
      if (!$this->executionError && $args['hide_after_success'] == 1) {
        $formclass .= ' lbwp-form-hide';
        // And add a backlink to the message parameter, if given
        $text = (strlen($args['back_link_text']) > 0) ? $args['back_link_text'] : __('Zurück zum Formular', 'lbwp');
        if ($this->showBackLink) {
          $message .= ' <a class="lbwp-form-back-link" href="' . get_permalink() . '">' . $text . '</a>';
        }
      }

      // Finally, append the message html
      $messageHtml .= '<p class="lbwp-form-message ' . $class . '">' . $message . '</p>';
      // Allow changing of messageHtml if needed
      $messageHtml = apply_filters('lbwp_form_message_html', $messageHtml, $this->currentForm->ID, $this->executionError);
    }

    // If there are additional classes
    if (isset($args['css_classes']) && strlen($args['css_classes']) > 0 && !self::$isBackendForm) {
      $formclass .= ' ' . strip_tags(($args['css_classes']));
    }

    $enctype = 'enctype="multipart/form-data"';
    if (isset($args['disable_enctype']) && $args['disable_enctype'] == 1) {
      $enctype = '';
    }

    // Override the central messages if override is given
    $errorMsgMulti = LbwpFormSettings::get('overrideFormErrorMsgMulti');
    if (strlen($errorMsgMulti) == 0) $errorMsgMulti = __('Es sind {number} Fehler aufgetreten.', 'lbwp');
    $errorMsgSingle = LbwpFormSettings::get('overrideFormErrorMsgSingle');
    if (strlen($errorMsgSingle) == 0) $errorMsgSingle = __('Es ist ein Fehler aufgetreten.', 'lbwp');
    // Add additional classes to formclass
    $formclass .= ' ' . LbwpFormSettings::get('additionalFormClass');
    $isMultisite = isset($this->currentArgs['enable_multisite']) && $this->currentArgs['enable_multisite'];
    $multisiteNav = '';

    if($isMultisite && !FormHandler::$isBackendForm){
      $formclass .= ' ' . 'lbwp-form__multisite';

      $useProgressBar = isset($this->currentArgs['use_progress_bar']) && $this->currentArgs['use_progress_bar'];
      if($useProgressBar) {
        $multisiteNav .= '<div class="lbwp-form-progress"><div class="lbwp-form-progress__bar"></div></div>';
      }

      $multisiteNav .= '<div class="lbwp-form-steps">';

      for($i = 1; $i <= PageBreak::$pageNum; $i++){
        $firstStep = $i === 1;
        $multisiteNav .= '<div class="lbwp-form-step' . ($firstStep ? ' current' : '') . '" data-page="' . $i . '">Schritt ' . $i . '</div>';
      }

      $multisiteNav .= '</div>';
    }

    // Create the form and display an eventual message
    $html .= '
      <div class="wp-block-lbwp-form' . ($isMultisite ? ' is-multisite' : '') . '">
        <div class="wp-block-lbwp-form-inner-container">
          <div class="lbwp-form-override">
            ' . (isset($_POST['lbwpFormSend']) && $args['hide_after_success'] == 1 ? '' : $multisiteNav) . '
            ' . $messageHtml . '
            <form id="lbwpForm-' . $formDisplayId . '" class="lbwp-form' . $formclass . '" method="POST"
              data-message-multi="' . $errorMsgMulti . '" data-message-single="' . $errorMsgSingle . '"
              data-use-botcheck="' . (isset($this->currentArgs['use_spambotcheck']) && $this->currentArgs['use_spambotcheck'] ? '1' : '0') . '"
              data-show-survey-results="' . (isset($this->currentArgs['show_survey_results']) && $this->currentArgs['show_survey_results'] ? '1' : '0') . '"
              data-hide-send-button="' . (isset($this->currentArgs['hide_send_button']) && $this->currentArgs['hide_send_button'] ? '1' : '0') . '"
              data-multisite="' . ($isMultisite ? '1' : '0') . '"
              ' . $enctype . ' ' . $customAction . ($isMultisite ? ' style="width: ' . (PageBreak::$pageNum * 100) . '%"' : '') . '>
              <input type="hidden" name="sentForm" value="' . $this->currentForm->ID . '" />
    ';

    if($isMultisite && !FormHandler::$isBackendForm){
      $html .= '<span class="lbwp-form-page page-1 current" data-page="1" data-page-name="' . $this->currentArgs['first_step_name'] . '">';
    }

    // Display a send button
    if (isset($args['button']) && strlen($args['button']) > 0) {
      $buttonHtml = BaseItem::$sendButtonTemplate;
      $buttonHtml = str_replace('{class}', 'send-button', $buttonHtml);
      $buttonHtml = str_replace('{field}', '<input type="submit" class="' . LbwpFormSettings::get('sendButtonClass') . '" value="' . $args['button'] . '" name="lbwpFormSend" />', $buttonHtml);
    }

    // Add a honeypot field and a token
    $security = '<input type="hidden" name="form-token" value="' . base64_encode(md5(AUTH_KEY)) . '" />' . PHP_EOL;
    $security .= '<input type="hidden" name="form-nonce" value="x' . Strings::getRandom(30) . '" />' . PHP_EOL;
    $security .= '<input type="hidden" name="lbwpHiddenFormFields" id="lbwpHiddenFormFields" value="" />' . PHP_EOL;
    $security .= '<input type="hidden" name="lbwp-bt-prob" id="lbwpBtProbField" value="1" />' . PHP_EOL;
    $security .= '<input type="text" name="second_nonce_' . md5(NONCE_KEY) . '"  value="' . base64_encode(md5(SECURE_AUTH_KEY)) . '" class="field_email_to" autocomplete="do-not-autofill" />' . PHP_EOL;
    $security .= '<input type="text" name="to_recept_' . md5(NONCE_KEY) . '" class="field_email_to" autocomplete="do-not-autofill" />' . PHP_EOL;

    // Add the form and button code and close the form
    if ($displayForm) {
      $html .= $formHtml . $buttonHtml;
    }

    // Fire up the validation
    $proto = (defined('WP_FORCE_SSL')) ? 'https' : 'http';
    $html .= '
      <script type="text/javascript">
        var lbwpFormNonceRetrievalUrl = "' . $proto . '://' . LBWP_HOST . '";
        jQuery(function() {
          if (jQuery && jQuery.fn.validate) {
            jQuery("#lbwpForm-' . $formDisplayId . '").validate({});
          }
        });
      </script>
    ';

    // Close the html tags
    $html .= $this->bottomContent . $security;

    if($isMultisite && !FormHandler::$isBackendForm && !isset($_POST['lbwpFormSend'])){
      $html .= '</span></form><div class="lbwp-form-navigation">
        <button class="btn btn-secondary prev">' . $args['back_button_title'] . '</button>
        <button class="btn btn-primary next" value="' . $args['next_button_title'] . '">' . $args['next_button_title'] . '</button>
        </div>';
    }else{
      $html .= '</form>';
    }

    $html .= '</div></div></div>';

    // allow general modification
    $html = apply_filters('lbwpForm', $html, $formDisplayId);

    // Reset the page number for multisite forms
    if ($isMultisite) {
      PageBreak::$pageNum = 1;
    }

    return $html;
  }

  /**
   * @param array $args the form shortcode args
   * @param string $formDisplayId the displayed id in html
   * @return string a success or error message
   */
  protected function executeForm($args, $formDisplayId)
  {
    // No matter what happens, don't cache the current requesst
    if (LbwpCore::hasFeature('FrontendModules', 'HTMLCache')) {
      HTMLCache::avoidCache();
    }

    // Skip execution on preview sites with empty POST data (8 are just the base honeypot fields, but not other)
    if (is_singular(Posttype::FORM_SLUG) && count($_POST) <= 8) {
      $args['skip_execution'] = 1;
    }

    // Skip execution if needed, return no message at all
    if (isset($args['skip_execution']) && $args['skip_execution'] == 1) {
      return '';
    }

    // Load form data from fields
    $data = $this->getFormData();

    // Check form data / required fields, end execution if required fields are empty
    if (!$this->checkRequiredFields($data)) {
      SystemLog::add('FormHandler/executeForm', 'error', 'Failed the checkRequiredFields method');
      $this->executionError = true;
    }

    // Check if there are actions to execute
    if (!$this->executionError && is_array($this->currentActions) && count($this->currentActions) > 0) {
      // Have a firewall break after a few tries within five minutes
      WordPress::checkSignature('form_' . md5($formDisplayId), 300, 5, 633);
      // Execute. Error can be true from a field that does spam checks
      $this->executionError = $this->fieldError;
      $data = $this->filterNonDataFields($data);

      if (!$this->executionError) {
        /** @var $action BaseAction */
        foreach ($this->currentActions as $action) {
          if ($action->isExecuteable($data)) {
            if (!$action->execute($data)) {
              $this->executionError = true;
            }
          } else {
            SystemLog::add('FormHandler/isExecuteable', 'debug', 'Failed executing action in form '.$formDisplayId);
          }
        }
      }

      // Success, if no errors happened
      if (!$this->executionError) {
        // Reset post values for this form
        foreach ($this->currentItems as $item) {
          $item->removeValue();
        }

        // Maybe set a submission cookie, if there were no errors and avoid cache for that user
        if (isset($args['after_submit']) && strlen($args['after_submit']) > 0) {
          $this->setSubmissionCookie($formDisplayId);
          setcookie('avoidCache', 1, null, '/', LBWP_HOST, defined('WP_FORCE_SSL'), true);
        }

        // New kind of postID redirect?
        if (isset($args['redirect']) && intval($args['redirect']) > 0) {
          header('Location: ' . $this->attachUrlParameters(get_permalink($args['redirect'])));
          exit;
        }

        // Old style link redirect?
        if (isset($args['weiterleitung']) && strlen($args['weiterleitung']) > 0) {
          header('Location: ' . $this->attachUrlParameters($args['weiterleitung']));
          exit;
        }

        // Message, if no redirect
        return $args['meldung'];
      }
    }

    // If we come here, something didn't work
    SystemLog::add('FormHandler/executeForm', 'debug', 'Reached end of method ' . $formDisplayId, $_REQUEST);
    return $this->formErrorMessage;
  }

  /**
   * @param $url
   * @return mixed|string
   */
  protected function attachUrlParameters($url)
  {
    if (count($_GET) == 0) {
      // If there are no GET params, return the url as is
      return $url;
    }

    // Attach all GET params that aren't in the url yet
    foreach ($_GET as $key => $value) {
      // Skip certain parameters
      if ($key == 'page_id' || $key == 'form_id') {
        continue;
      }

      // If the key is not in the url, add it
      if (!str_contains($url, $key . '=')) {
        $url = Strings::attachParam($key, $value, $url);
      }
    }

    return $url;
  }

  /**
   * FIXME: Removed to hotfix, as it checked hidden fields that are not required when hidden, but PHP side doesn't know that
   * @param array $data the form fields and data
   * @return bool true if valid, false if required fields are missing
   */
  protected function checkRequiredFields($data)
  {
    return true;
  }

  /**
   * Remove all fields from the list that provide unmeaningful data
   * @param array $fields the field list
   * @return array maybe changed field list
   */
  protected function filterNonDataFields($fields)
  {
    // Define the fields not containing any meaningful action data
    $nonDataFields = array(
      'LBWP\Module\Forms\Item\Calculation',
      'LBWP\Module\Forms\Item\RequiredNote'
    );

    // Remove non data fields from the list
    foreach ($fields as $key => $field) {
      $fields[$key]['name'] = str_replace(BaseItem::ASTERISK_HTML, '', $field['name']);
      // If it is one of the non data fields classes, remove it
      foreach ($nonDataFields as $className) {
        if (is_a($field['item'], $className)) {
          unset($fields[$key]);
          break;
        }
      }
    }

    return $fields;
  }

  /**
   * @param string $content the bottom content
   */
  public function addBottomContent($content)
  {
    $this->bottomContent .= $content;
  }

  /**
   * Set a submission cookie for a certain form
   * @param string $formDisplayId the form id
   */
  protected function setSubmissionCookie($formDisplayId)
  {
    Cookie::set(self::AFTER_SUBMIT_COOKIE_PREFIX . $formDisplayId, 1);
  }

  /**
   * Checks if there is a submission cookie for a certain form
   * @param string $formDisplayId the form id
   * @return bool true if the cookie is set
   */
  protected function hasSubmissionCookie($formDisplayId)
  {
    return Cookie::get(self::AFTER_SUBMIT_COOKIE_PREFIX . $formDisplayId) == 1;
  }

  /**
   * Can be utilized by fields and actions
   * @param string $message custom error message
   */
  public function setCustomError($message)
  {
    $this->formErrorMessage = $message;
  }

  /**
   * Executed on saving a form
   * @param int $formId id of the post (form)
   */
  public function saveForm($formId)
  {
    $form = get_post($formId);
    do_shortcode(strip_tags($form->post_content, '<h1><h2><h3><h4><h5>'));

    // now the actions are loaded, save them
    foreach ($this->currentActions as $action) {
      $action->onSave($form);
    }
  }

  /**
   * @return \WP_Post the form object
   */
  public function getCurrentForm()
  {
    return $this->currentForm;
  }

  /**
   * Tells if the form is secure, which means if every security check has been passed
   */
  protected function formIsSecure()
  {
    $secure = true;
    $errors = [];

    // Check if the second nonce is given and correct
    $nonce = 'second_nonce_' . md5(NONCE_KEY);

    // Check if nonces are transferred as array, this is shenanigans
    if (isset($_POST[$nonce]) && is_array($_POST[$nonce])) {
      $secure = false;
      $errors[] = 'nonce is transferred as array not string';
    }
    // Check if nonces are transferred as array, this is shenanigans
    if (isset($_POST['to_recept_' . md5(NONCE_KEY)]) && is_array($_POST['to_recept_' . md5(NONCE_KEY)])) {
      $secure = false;
      $errors[] = 'honeypot is transferred as array not string';
    }

    // Continue checks only if still secure
    if ($secure && md5(SECURE_AUTH_KEY) === base64_decode(substr($_POST[$nonce], 3))) {
      // Empty the recept field, if same value as nonce
      if ($_POST['to_recept_' . md5(NONCE_KEY)] === $_POST[$nonce]) {
        $_POST['to_recept_' . md5(NONCE_KEY)] = '';
      }
    }

    // Is the honeypot filled out?
    if ($secure && strlen($_POST['to_recept_' . md5(NONCE_KEY)]) > 0) {
      $secure = false;
      $errors[] = 'honeypot filled out with: ' . $_POST['to_recept_' . md5(NONCE_KEY)] .' / expected: ' . $_POST[$nonce];
    }

    // Check the token
    if ($secure && $_POST['form-token'] != base64_encode(md5(AUTH_KEY))) {
      $secure = false;
      $errors[] = 'token mismatch, received: ' . $_POST['form-token'] . ' / expected: ' . base64_encode(md5(AUTH_KEY));
    }

    // Check for the nonce and reset it right after
    $nonce = substr($_POST['form-nonce'], 0);
    if (wp_cache_get($nonce, 'LbwpForm') != 1 && !is_singular(Posttype::FORM_SLUG)) {
      $secure = false;
      $errors[] = 'nonce mismatch, cached nonce: key=' . $nonce . ' / value=' . wp_cache_get($nonce, 'LbwpForm');
    }

    if (!$secure) {
      // Activate for debugging when needed
      //SystemLog::add('FormHandler/formIsSecure', 'error', '$secure is false before extended checks', array_merge($errors, $_REQUEST));
    }

    // Bot check, but only of the preceding checks didn't already mark as insecure
    if ($secure && isset($this->currentArgs['use_spambotcheck']) && $this->currentArgs['use_spambotcheck'] == 1) {
      // If we use those checks, basically assume it is not secure
      $secure = false;
      if ($_POST['lbwp-bt-prob'] != 1) {
        $botString = strtolower($_POST['lbwp-bt-prob']);
        // order from c to a, in case a category has the same amount
        $counts = array(
          substr_count($botString, 'c'),
          substr_count($botString, 'b'),
          substr_count($botString, 'a'),
          substr_count($botString, 'f'),
          substr_count($botString, 'd')
        );

        $maxVal = max($counts);
        // Get the character that was used most
        $maxKey = array_search($maxVal, $counts);

        switch ($maxKey) {
          case 0:
            $result = 'c = pretty much a bot';
            break;
          case 1:
            $result = 'b = maybe a bot, marked as secure';
            $secure = true;
            break;
          case 2:
            $result = 'a = most certainly not a bot, marked as secure';
            $secure = true;
            break;
          case 3:
            $result = 'f = maybe someone using autofill for all fields';
            $secure = true;
            break;
        }

        // Another check, test if c is smaller than a (good)+b(maybe bot, hence only 0.75)
        if ($maxKey == 0 && $counts[0] <= ($counts[2]*1.25) + ($counts[1]*0.75)) {
          $result = 'c = minimal chance for a bot due to possible hidden fields';
          $secure = true;
        }

        // If the "d" is here, we have most likely a bot, but only if there are no f (autofills)
        if ($counts[4] > 0) {
          $result = 'd = most certainly a bot';
          $secure = false;
          if ($counts[3] > 3) {
            $result .= ', but let trough, because of many f = autofills';
            $secure = true;
          }
        }

        // Add a filter to change the result
        $secure = apply_filters('lbwp_is_form_secure_bot_check', $secure, $counts, $botString);

        // Log the information for the moment, when it was marked as secure (as D and C seem to work)
        // With the log we want to learn how many of the users are in A and how many in B to maybe optimize algorithm
        $info = $secure ? 'secure' : 'insecure';
        SystemLog::add('use_spambotcheck marked ' . $info, 'debug', 'bot evaluation: ' . $result, $_POST);
      }
    }

    return $secure;
  }

  /**
   * @return array $data array containting the item, its name and the user value
   */
  public function getFormData()
  {
    $data = array();
    $this->executingForm = true;
    $hiddenFields = explode(',', $_POST['lbwpHiddenFormFields']);

    foreach ($this->currentItems as $item) {
      $values = array(
        'id' => $item->get('id'),
        'item' => $item,
        'name' => $item->get('feldname'),
        'value' => $item->getValue()
      );

      // Check if the field was visible, if not, don't add it at all
      if (in_array($values['id'], $hiddenFields)) {
        continue;
      }

      // Check if the value is an array and implode it while preserving the array
      // We made it this way for backwards compat on all actions that dont implement valueArray
      if (is_array($values['value'])) {
        $values['valueArray'] = $values['value'];
        // Reset value to empty string and add up with keys and values
        $values['value'] = array();
        foreach ($values['valueArray'] as $key => $value) {
          if (strlen($value['value']) > 0) {
            $values['value'][] = $value['colname'];
          }
        }
        if (count($values['value']) > 0) {
          $values['value'] = implode(', ', $values['value']);
        } else {
          $values['value'] = '';
        }
      }

      // Add the fieldset to our data array
      $data[] = $values;
    }

    $this->executingForm = false;
    return $data;
  }

  /**
   * @return array $data array containting the item, its name and the user value
   */
  public function getRawFormData()
  {
    $data = array();
    foreach ($this->getFormData() as $item) {
      unset($item['item']);
      $data[] = $item;
    }

    return $data;
  }

  /**
   * Display the single form item
   * @param array $args the shortcode arguments
   * @param string $content shortcode content
   * @return string HTML code to display the form element
   */
  public function displayItem($args, $content)
  {
    $item = $this->getItem($args['key']);

    if ($item instanceof BaseItem) {
      $item->setParams($args);
      $item->setContent($content);
      $this->currentItems[] = $item;
      return apply_filters('lbwp_forms_filter_display_item_html', $item->getElement($args, $content), $item, $this->currentForm->ID);
    }
  }

  /**
   * This is the loader shortcode, it loads the form (containing more shortcodes, and runs them)
   * @param array $args the shortcode arguments
   * @return string finished html code for the form and resources
   */
  public function loadForm($args)
  {
    $html = '';
    $formId = intval($args['id']);
    $this->currentForm = get_post($formId);
    $this->currentItems = array();
    $this->currentActions = array();
    // Save arguments for later, but delete the id
    $this->additionalArgs = $args;
    unset($this->additionalArgs['id']);

    // Check if the form is actually valid
    if ($this->currentForm->ID == $formId) {
      $shortcode = apply_filters(
        'lbwpForms_load_form_shortcode',
        $this->currentForm->post_content,
        $this->currentForm
      );
      // Run the form shortcodes
      $html = do_shortcode($shortcode);
      // Add css resources from file or cache
      $this->addFormAssets();
    }

    return $html;
  }

  /**
   * @param array $args action parameters
   * @param string $content the content in shortcode
   */
  public function loadAction($args, $content)
  {
    $action = $this->getAction($args['key']);

    if ($action instanceof BaseAction) {
      $action->setParams($args);
      $action->setContent($content);
      $this->currentActions[] = $action;
    }
  }

  /**
   * This returns an example shortcode with all options
   */
  public function getFormExample()
  {
    $code = '[' . self::SHORTCODE_FORM;
    // Add configuration
    foreach ($this->formDefaults as $key => $value) {
      $code .= ' ' . $key . '="' . $value . '"';
    }

    // Two empty lines and the ending
    $code .= ']' . PHP_EOL;

    // for now, add the mail action statically
    $action = $this->getAction('sendmail');
    $code .= '  ' . $action->getExampleCode();

    // Close the form tag
    $code .= PHP_EOL . PHP_EOL . '[/' . self::SHORTCODE_FORM . ']';

    return $code;
  }

  /**
   * Get a data object containing:
   * - hasFormContent -> true/false
   * - formHtml -> the form html
   * - formJsonObject -> the json equivalent of the form
   * @param string $shortcode the form shortcode
   * @return array of data
   */
  public function getFormEditData($shortcode)
  {
    // Initialize empty
    $data = array(
      'hasFormData' => false,
      'hasFormItems' => false,
      'formHtml' => '',
      'formJsonObject' => array()
    );

    // Execute the form and populate current vars
    FormHandler::$isBackendForm = true;
    $data['formHtml'] = trim(do_shortcode($shortcode));
    FormHandler::$isBackendForm = false;

    // Set data available, if there are actions or items
    if (count($this->currentActions) > 0 || count($this->currentItems) > 0) {
      $data['hasFormData'] = true;
    }

    // See if there are form items explicitly, to attach a template
    if (count($this->currentItems) > 0) {
      $data['hasFormItems'] = true;
    }

    // Fill the json object with settings, items and action data
    $json = array(
      'Items' => array(),
      'Actions' => array(),
      'Settings' => $this->currentArgs,
    );

    // Fill items information
    foreach ($this->currentItems as $item) {
      $id = $item->get('id');

      // Add all parameters and their default or current value
      foreach ($item->getParamConfig() as $paramKey => $paramConfig) {
        // Remove certain information from json
        $paramConfig = $this->modifyItemForJson($paramKey, $paramConfig);
        // Now input into the main json object
        $json['Items'][$id]['key'] = $item->get('key');
        $json['Items'][$id]['params'][] = array_merge(
          $paramConfig,
          $this->modifyItemForJson($paramKey, array(
            'key' => $paramKey,
            'value' => ($paramKey == 'content') ? $item->getContent() : $item->get($paramKey)
          ))
        );
      }
    }

    // Fill actions information
    $actionCount = 0;
    foreach ($this->currentActions as $action) {
      $id = 'action_' . (++$actionCount);

      // Add all parameters and their default or current value
      foreach ($action->getParamConfig() as $paramKey => $paramConfig) {
        $json['Actions'][$id]['key'] = $action->get('key');
        $json['Actions'][$id]['params'][] = array_merge(
          $paramConfig,
          array(
            'key' => $paramKey,
            'value' => ($paramKey == 'content') ? $action->getContent() : $action->get($paramKey)
          )
        );
      }
    }

    // Set the form json object
    $data['formJsonObject'] = $json;

    return $data;
  }

  /**
   * Modify certain params to not have display information in them
   * @param string $paramKey
   * @param array $paramConfig
   * @return array the param config (does nothing at the moment
   */
  protected function modifyItemForJson($paramKey, $paramConfig)
  {
    // Remove the display asterisk from json so it doesn't get duplicated
    if ($paramKey == 'placeholder' && isset($paramConfig['value'])) {
      $paramConfig['value'] = str_replace(' *', '', $paramConfig['value']);
    }

    // Remove the asterisk html from json so it doesn't show up broken
    if ($paramKey == 'feldname' && isset($paramConfig['value'])) {
      $paramConfig['value'] = str_replace(BaseItem::ASTERISK_HTML, '', $paramConfig['value']);
    }

    return $paramConfig;
  }

  /**
   * Converts a json object changed by the frontend back into a shortcode
   * @param string $json the json string from the frontend
   * @return string a new shortcode representing the json
   */
  public function convertFormJsonToShortcode($json)
  {
    // Initialze variables
    $formObject = json_decode($json, true);
    $shortcode = $innerElements = '';

    // Create the actions and items
    foreach (array('Actions', 'Items') as $objectKey) {
      foreach ($formObject[$objectKey] as $itemKey => $itemData) {
        // Gather the params, but not content (Special handling)
        $content = '';
        $paramString = ' id="' . $itemKey . '" key="' . $itemData['key'] . '"';
        $hasContentParam = false;

        foreach ($itemData['params'] as $param) {
          if ($param['key'] != 'content') {
            // En- and decode, so that we only have double quotes html encoded -> Don't double enquote though
            $value = html_entity_decode(htmlentities($param['value'], ENT_COMPAT, 'UTF-8', false), ENT_NOQUOTES, 'UTF-8');
            $paramString .= ' ' . $param['key'] . '="' . $value . '"';
          } else {
            $hasContentParam = true;
            $content = trim(html_entity_decode($param['value'], ENT_QUOTES, 'UTF-8'));
          }
        }

        // Set the shortcode to be used
        switch ($objectKey) {
          case 'Actions':
            $shortcodeId = self::SHORTCODE_ACTION;
            break;
          case 'Items' :
            $shortcodeId = self::SHORTCODE_FORM_ITEM;
            if ($hasContentParam) {
              $shortcodeId = self::SHORTCODE_FORM_CONTENT_ITEM;
            }
            break;
        }

        // Create the shortcode
        $innerElements .= '[' . $shortcodeId . $paramString . ']' . $content . '[/' . $shortcodeId . ']' . PHP_EOL;
      }
    }

    // If there is a redirect, purge message and legacy "redirect"
    if (intval($formObject['Settings']['redirect']) > 0) {
      unset($formObject['Settings']['meldung']);
      unset($formObject['Settings']['weiterleitung']);
    } else {
      unset($formObject['Settings']['redirect']);
    }

    // Now, create the main element with params
    $shortcode = '[' . self::SHORTCODE_FORM;
    foreach ($formObject['Settings'] as $key => $value) {
      $shortcode .= ' ' . $key . '="' . $value . '"';
    }
    $shortcode .= ']';

    // Add the actions, items and close the shortcode
    $shortcode .= '
      ' . $innerElements . '
    [/' . self::SHORTCODE_FORM . ']';

    return $shortcode;
  }

  /**
   * Save a form coming from the ditor
   * @param array $data the form id
   * @return array changed data array
   */
  public function saveEditorForm($data)
  {
    // Only handle lbwp forms
    if ($data['post_type'] != Posttype::FORM_SLUG || !isset($_POST['formJson'])) {
      return $data;
    }

    // save the new shortcode to post_content and let wordpress save that
    $data['post_content'] = $this->convertFormJsonToShortcode($_POST['formJson']);
    // Make sure that the form is published
    $data['post_status'] = 'publish';
    return $data;
  }

  /**
   * Loads and prints the forms css/js from file or cache
   * @return string the css inline code for displaying the form
   */
  public function addFormAssets()
  {
    // Exit function, if already printed once
    if ($this->addedAssets) {
      return '';
    }

    // Add the frontend JS in footer
    $uri = File::getResourceUri();
    wp_enqueue_script('lbwp-form-frontend', $uri . '/js/lbwp-form-frontend.js', array('jquery'), LbwpCore::REVISION, true);
    wp_enqueue_script('lbwp-form-validate', $uri . '/js/lbwp-form-validate.js', array('jquery'), LbwpCore::REVISION, true);
    // Add the CSS to top
    if (!LbwpFormSettings::get('removeCoreFrontendCss')) {
      add_filter('add_late_head_content', function ($html) use ($uri) {
        return $html . '<link rel="stylesheet" href="' . $uri . '/css/lbwp-form-frontend.css?ver=' . LbwpCore::REVISION . '" />' . PHP_EOL;
      });
    }

    // Set as printed once
    $this->addedAssets = true;
    return '';
  }

  /**
   * @param array $field the field config
   * @return string shortcode item tag
   */
  public function generateFieldItem($field)
  {
    // Set an id, if not given
    if (!isset($field['id'])) {
      $field['id'] = $field['key'] . '_' . (++$this->generatedFieldIds);
    }

    $shortcode = '[' . self::SHORTCODE_FORM_ITEM . ' ';
    foreach ($field as $key => $value) {
      $shortcode .= $key . '="' . esc_attr($value) . '" ';
    }
    $shortcode = trim($shortcode) . '][/' . self::SHORTCODE_FORM_ITEM . ']';

    return $shortcode;
  }

  /**
   * @param array $action the action config
   * @return string shortcode item tag
   */
  public function generateActionItem($action)
  {
    // Set an id, if not given
    if (!isset($field['id'])) {
      $field['id'] = 'action_' . (++$this->generatedActionIds);
    }

    $shortcode = '[' . self::SHORTCODE_ACTION . ' ';
    foreach ($action as $key => $value) {
      $shortcode .= $key . '="' . esc_attr($value) . '" ';
    }
    $shortcode = trim($shortcode) . '][/' . self::SHORTCODE_ACTION . ']';

    return $shortcode;
  }

  /**
   * @param array $conditions adds form field display/effect conditions
   * @param string $direction in or out
   */
  public function addConditions($conditions)
  {
    if (is_array($conditions) && count($conditions) > 0) {
      foreach ($conditions as $condition) {
        $this->conditions[$condition['target']][] = $condition;
      }
    }
  }

  /**
   * This function does very basic checks for the moment
   * @param string $shortcode a form shortcode
   * @return bool true, if the shortcode will generate a valid form
   */
  public function isValidForm($shortcode)
  {
    return stristr($shortcode, '[/' . self::SHORTCODE_FORM . ']') !== false;
  }

  /**
   * @param string $shortcode the shortcode
   * @param array $fields of field shortcodes
   * @return string the changed shortcode with new fields
   */
  public function addElementsToShortcode($shortcode, $fields)
  {
    return str_replace(
      '[/' . self::SHORTCODE_FORM . ']',
      implode(PHP_EOL, $fields) . '[/' . self::SHORTCODE_FORM . ']',
      $shortcode
    );
  }

  /**
   * @param string $key an item key
   * @return BaseItem a from item
   */
  public function getItem($key)
  {
    return $this->createItem($key);
  }

  /**
   * This creates a valid item
   * @param string $key the id of the element to be created
   * @return BaseItem an instantiated item or false
   */
  public function createItem($key)
  {
    $className = $this->items[$key];
    if (class_exists($className)) {
      $item = new $className($this->core);
      $item->loadItem($key);

      return $item;
    }

    return false;
  }

  /**
   * @param string $key an action key
   * @return BaseAction an action
   */
  public function getAction($key)
  {
    return $this->createAction($key);
  }

  /**
   * This creates a valid action
   * @param string $key the id of the element to be created
   * @return BaseAction an instantiated item or false
   */
  public function createAction($key)
  {
    $className = $this->actions[$key];
    if (class_exists($className)) {
      $item = new $className($this->core);
      $item->loadAction($key);

      return $item;
    }

    return false;
  }

  /**
   * @param int $formId the form to filter
   * @param string $type action type (class name)
   * @return BaseAction[]|BaseAction action array or single action, if reduce=true
   */
  public function getActionsOfType($formId, $type, $reduce)
  {
    $actions = array();
    $this->loadForm(array('id' => $formId));
    // Loop current actions from loading the form
    foreach ($this->currentActions as $action) {
      if (Strings::endsWith(get_class($action), '\\' . $type)) {
        $actions[] = $action;
      }
    }

    // Redurect to single action if desired
    if ($reduce) {
      $actions = $actions[0];
    }

    return $actions;
  }

  /**
   * @return int the next new form item id
   */
  public function getNextId()
  {
    return ++$this->idProvider;
  }

  /**
   * @return BaseItem[] the currently executing items (only set after a form is loaded)
   */
  public function getCurrentItems()
  {
    return $this->currentItems;
  }

  /**
   * @return BaseItem[] the list of items (already filtered, if called after init)
   */
  public function getItems()
  {
    return $this->items;
  }

  /**
   * @return array the list of actions (already filtered, if called after init)
   */
  public function getActions()
  {
    return $this->actions;
  }
	
	/**
	 * Automatically deletes uploaded files (if setting isset in the backend)
	*/
	public function autoDeleteUploads(){
		$getForms = get_posts(array(
			'post_type' => Posttype::FORM_SLUG,
			'numberposts' => -1
		));

		foreach($getForms as $form){
			$cutStr = strpos($form->post_content, 'auto_delete_file="');
			if($cutStr === false){
				continue;
			}
			
			preg_match_all('/(auto_delete_file=")(.*?)(")/', $form->post_content, $matches);
			$deleteFilesDay = intval(max($matches[2]));

			if($deleteFilesDay === 0){
				continue;
			}

			$upladedFiles = get_post_meta($form->ID, 'uploaded-files', true);
			$uploader = LbwpCore::getModule('S3Upload');

			foreach($upladedFiles as $timestamp => $file){
				if(current_time('timestamp') > $timestamp + $deleteFilesDay * 86400){
					$uploader->deleteFileByKey($file);
					unset($upladedFiles[$timestamp]);
				}
			}
			
			update_post_meta($form->ID, 'uploaded-files', $upladedFiles);
		}
	}
} 