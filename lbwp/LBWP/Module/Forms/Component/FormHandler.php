<?php

namespace LBWP\Module\Forms\Component;

use LBWP\Core as LbwpCore;
use LBWP\Util\Cookie;
use LBWP\Util\File;
use LBWP\Module\Frontend\HTMLCache;
use LBWP\Module\Forms\Item\Base as BaseItem;
use LBWP\Module\Forms\Action\Base as BaseAction;
use LBWP\Util\Strings;

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
    'required-note' => '\\LBWP\\Module\\Forms\\Item\\RequiredNote',
    'calculation' => '\\LBWP\\Module\\Forms\\Item\\Calculation',
    'upload' => '\\LBWP\\Module\\Forms\\Item\\Upload',
    'hiddenfield' => '\\LBWP\\Module\\Forms\\Item\\Hiddenfield'
  );
  /**
   * @var array possible actions
   */
  protected $actions = array(
    'sendmail' => '\\LBWP\\Module\\Forms\\Action\\SendMail',
    'datatable' => '\\LBWP\\Module\\Forms\\Action\\DataTable',
    'auto-close' => '\\LBWP\\Module\\Forms\\Action\\AutoClose',
    'savesession' => '\\LBWP\\Module\\Forms\\Action\\SaveSession',
  );
  /**
   * @var array blacklist of items that should provide infos to actions
   */
  protected $blacklistGetdata = array('required-note');
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
  const ALLOWED_TAGS = '<h1><h2><h3><h4><h5><p><div><span><strong><em><a>';
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
    if (isset($_POST['lbwpFormSend']) && $this->currentForm->ID == $_POST['sentForm'] && $this->formIsSecure()) {
      $message = $this->executeForm($args, $formDisplayId);
      $this->idProvider = 0;

      // Do it again, because output could have been reset
      $formHtml = do_shortcode(strip_tags($content, self::ALLOWED_TAGS));
      $formclass .= ' submitted';
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

    // Set a custom action, if requested
    $customAction = 'action="' . $_SERVER['REQUEST_URI'] . '#message-' . $formDisplayId . '"';
    if (isset($args['action']) && strlen($args['action']) > 0) {
      $customAction = ' action="' . $args['action'] . '"';
    }
    if (isset($args['external_action_url']) && strlen($args['external_action_url']) > 0 && Strings::isURL($args['external_action_url'])) {
      $customAction = ' action="' . $args['external_action_url'] . '"';
    }

    // If there is an after_submit, close the form after one submission
    if (isset($args['after_submit']) && strlen($args['after_submit']) > 0 && $this->hasSubmissionCookie($formDisplayId)) {
      // Don't cache the page, as this is user specific
      HTMLCache::avoidCache();
      $message = $args['after_submit'];
      $displayForm = false;
    }

    // Add a message, if available
    if (strlen($message) > 0) {
      $class = ($this->executionError) ? 'error' : 'success';
      $formclass .= ' ' . $class;

      // Add hiding class, only if success
      if (!$this->executionError && $args['hide_after_success'] == 1) {
        $formclass .= ' lbwp-form-hide';
        // And add a backlink to the message parameter, if given
        $text = (strlen($args['back_link_text']) > 0) ? $args['back_link_text'] : __('Zurück zum Formular', 'lbwp');
        $message .= ' <a href="' . get_permalink() . '">' . $text . '</a>';
      }

      // Finally, create the message html
      $messageHtml = '
        <a class="lbwp-form-anchor" id="message-' . $formDisplayId . '"></a>
        <p class="lbwp-form-message ' . $class . '">' . $message . '</p>
      ';
    }

    // If there are additional classes
    if (isset($args['css_classes']) && strlen($args['css_classes']) && !self::$isBackendForm) {
      $formclass .= ' ' . strip_tags(($args['css_classes']));
    }

    $enctype = 'enctype="multipart/form-data"';
    if (isset($args['disable_enctype']) &&$args['disable_enctype'] == 1) {
      $enctype = '';
    }

    // Create the form and display an eventual message
    $html .= '
      <div class="lbwp-form-override">
        ' . $messageHtml . '
        <form id="lbwpForm-' . $formDisplayId . '" class="lbwp-form' . $formclass . '" method="POST" ' . $enctype . ' ' . $customAction . '>
          <input type="hidden" name="sentForm" value="' . $this->currentForm->ID . '" />
    ';

    // Display a send button
    if (isset($args['button']) && strlen($args['button']) > 0) {
      $buttonHtml = BaseItem::$sendButtonTemplate;
      $buttonHtml = str_replace('{class}', 'send-button', $buttonHtml);
      $buttonHtml = str_replace('{field}', '<input type="submit" value="' . $args['button'] . '" name="lbwpFormSend" />', $buttonHtml);
    }

    // Add a honeypot field and a token
    $security = '<input type="hidden" name="form-token" value="' . base64_encode(md5(AUTH_KEY)) . '" />' . PHP_EOL;
    $security.= '<input type="text" name="email_to_' . md5(NONCE_KEY) . '" class="field_email_to" />' . PHP_EOL;

    // Add the form and button code and close the form
    if ($displayForm) {
      $html .= $formHtml . $buttonHtml;
    }

    // Fire up the validation
    $html .= '
      <script type="text/javascript">
        jQuery(function() {
          if (jQuery && jQuery.fn.validate) {
            jQuery("#lbwpForm-' . $formDisplayId. '").validate({});
          }
        });
      </script>
    ';

    // Close the html tags
    $html .= $this->bottomContent . $security . '</form></div>';

    // allow general modification
    $html = apply_filters('lbwpForm', $html);

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

    // Load form data from fields
    $data = $this->getFormData();
    // Check if there are actions to execute
    if (is_array($this->currentActions) && count($this->currentActions) > 0) {
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
          $_SESSION['avoidCache'] = true;
        }

        // New kind of postID redirect?
        if (isset($args['redirect']) && intval($args['redirect']) > 0) {
          header('Location: ' . get_permalink($args['redirect']));
          exit;
        }

        // Old style link redirect?
        if (isset($args['weiterleitung']) && strlen($args['weiterleitung']) > 0) {
          header('Location: ' . $args['weiterleitung']);
          exit;
        }

        // Message, if no redirect
        return $args['meldung'];
      }
    }

    // If we come here, something didn't work
    return $this->formErrorMessage;
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

    // Is the honeypot filled out?
    if (strlen($_POST['email_to_' . md5(NONCE_KEY)]) > 0) {
      $secure = false;
    }

    // Check the token
    if ($_POST['form-token'] != base64_encode(md5(AUTH_KEY))) {
      $secure = false;
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

    foreach ($this->currentItems as $item) {
      if (!in_array($item->get('key'), $this->blacklistGetdata)) {
        $data[] = array(
          'id' => $item->get('id'),
          'item' => $item,
          'name' => $item->get('feldname'),
          'value' => $item->getValue()
        );
      }
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
      return $item->getElement($args, $content);
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
    add_filter('add_late_head_content', function($html) use ($uri) {
      return $html . '<link rel="stylesheet" href="' . $uri . '/css/lbwp-form-frontend.css?ver=' . LbwpCore::REVISION . '" />' . PHP_EOL;
    });

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
      $field['id'] =  $field['key'] . '_' . (++$this->generatedFieldIds);
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
      $field['id'] =  'action_' . (++$this->generatedActionIds);
    }

    $shortcode = '[' . self::SHORTCODE_ACTION . ' ';
    foreach ($action as $key => $value) {
      $shortcode .= $key . '="' . esc_attr($value) . '" ';
    }
    $shortcode = trim($shortcode) . '][/' . self::SHORTCODE_ACTION . ']';

    return $shortcode;
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
} 