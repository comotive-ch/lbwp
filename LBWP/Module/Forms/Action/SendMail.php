<?php

namespace LBWP\Module\Forms\Action;

use LBWP\Core as LbwpCore;
use LBWP\Helper\Converter;
use LBWP\Module\Forms\Item\PageBreak;
use LBWP\Module\Forms\Core;
use LBWP\Module\Forms\Item\Base as BaseItem;
use LBWP\Module\Forms\Item\Hiddenfield;
use LBWP\Module\Forms\Item\Textfield;
use LBWP\Module\Forms\Item\HtmlItem;
use LBWP\Module\General\Cms\SystemLog;
use LBWP\Theme\Feature\LbwpFormSettings;
use LBWP\Util\External;
use LBWP\Util\File;
use LBWP\Util\Strings;
use LBWP\Util\WordPress;
use LBWP\Util\ArrayManipulation;

/**
 * This will send an email on a sent form
 * @package LBWP\Module\Forms\Action
 * @author Michael Sebel <michael@comotive.ch>
 */
class SendMail extends Base
{
  /**
   * @var string action name
   */
  protected $name = 'E-Mail senden';
  /**
   * @var string content variable to provide mail table in custom content
   */
  const FORM_CONTENT_VARIABLE = '{lbwp:formContent}';
  /**
   * @var array set the field config for this field
   */
  protected $actionConfig = array(
    'name' => 'E-Mail senden',
    'help' => 'Sendet eine E-Mail mit den Formulardaten',
    'group' => 'Häufig verwendet'
  );
  /**
   * @var array list of email templates
   */
  protected $htmlTemplates = array();
  /**
   * @var int
   */
  protected static $currentFormId = 0;
  /**
   * @var string
   */
  protected static $currentFormSubject = '';
  /**
   * @var string the default template key
   */
  const DEFAULT_TEMPLATE_KEY = 'default';
  /**
   * @var string the minimum template var
   */
  const MINIMUM_TEMPLATE_VAR = 'email-content';
  /**
   * @var string default background color used in the email template
   */
  const DEFAULT_BACKGROUND_COLOR = '#f6f6f6';
  /**
   * @var string default primary color used in the email template
   */
  const DEFAULT_PRIMARY_COLOR = '#2d2d2d';

  /**
   * Extend the parameter configuration for the editor
   */
  protected function setParamConfig()
  {
    $this->paramConfig = ArrayManipulation::deepMerge($this->paramConfig, array(
      'betreff' => array(
        'name' => 'Betreffzeile',
        'type' => 'textfield'
      ),
      'betreffzusatz' => array(
        'name' => 'Betreffzusatz (Optional)',
        'type' => 'textfield',
        'help' => 'Eine zusätzliche Zeile die nach dem Betreff angezeigt wird (nur im E-Mail Inhalt).'
      ),
      'email' => array(
        'name' => 'Empfänger-Adresse',
        'type' => 'textfield',
        'help' => 'Sie können mehrere Empfänger definieren. Mehrere E-Mail-Adressen müssen mit einem Semikolon abgetrennt werden.'
      ),
      'cc' => array(
        'name' => 'Weiterer Empfänger (CC)',
        'type' => 'textfield',
        'help' => 'Sie können mehrere Empfänger definieren. Mehrere E-Mail-Adressen müssen mit einem Semikolon abgetrennt werden.'
      ),
      'bcc' => array(
        'name' => 'Versteckter Empfänger (BCC)',
        'type' => 'textfield',
        'help' => 'Sie können mehrere Empfänger definieren. Mehrere E-Mail-Adressen müssen mit einem Semikolon abgetrennt werden.'
      ),
      'replyto' => array(
        'name' => 'Antwort-Adresse (Optional)',
        'type' => 'textfield',
        'help' => 'An wen sollen Antworten auf diese E-Mail gesendet werden? Bleibt das Feld leer, wird die Empfänger Adresse verwendet.'
      ),
      'anhang' => array(
        'name' => 'Link zu einem Anhang (URL)',
        'type' => 'textfield',
        'help' => 'Die verlinkte Datei wird als E-Mail Anhang versendet.'
      ),
      'hideempty' => array(
        'name' => 'Verhalten bei leeren Formular-Feldern',
        'type' => 'dropdown',
        'values' => array(
          'show' => 'Feld in der Liste als nicht ausgefüllt anzeigen',
          'hide' => 'Feld in der Liste nicht anzeigen'
        )
      ),
      'content' => array(
        'name' => 'E-Mail Inhalt überschreiben (Optional)',
        'type' => 'textarea',
        'help' => 'Hier können Sie Ihren eigenen E-Mail Inhalt definieren. Bleibt das Feld leer, wird eine Tabelle der Formulardaten in der E-Mail angezeigt. Wenn Sie die Formulardaten in Ihrem Inhalt verwenden möchten, setzen Sie den Platzhalter {lbwp:formContent} ein. Sie können auch Formularfelder verwenden indem Sie die Feld-ID z.B. wie folgt verwenden: {email_adresse}.'
      ),
      'footer' => array(
        'name' => 'E-Mail Fusszeile (Optional)',
        'type' => 'textfield',
        'help' => 'In der Fusszeile können eigene angaben stehen wie z.B. Email-Adresse, Telefonnummer, Adresse usw.'
      ),
    ));

    // Define a basic single template for emails and let developers extend the array
    $this->htmlTemplates = apply_filters('lbwp_email_action_templates', array(
      self::DEFAULT_TEMPLATE_KEY => array(
        'name' => 'Standard-Vorlage',
        'file' => File::getResourcePath() . '/newsletter/zurb-emails-v2/lbwp-email-2/dynamic.html'
      )
    ));

    // Create the dropdown config from it
    $templateConfig = array(
      'name' => 'E-Mail Vorlage',
      'type' => 'dropdown',
      'help' => 'Die Design-Vorlage für die E-Mail. Gerne entwickeln wir Vorlagen nach individuelle Wünschen. <a href="mailto:support@comotive.ch">Melden Sie sich bei uns</a>!',
      'values' => array()
    );
    foreach ($this->htmlTemplates as $key => $template) {
      $templateConfig['values'][$key] = $template['name'];
    }

    $this->paramConfig['template'] = $templateConfig;
    // Email type, changed phpmailser isHTML to true or false
    $this->paramConfig['email_type'] = array(
      'name' => 'E-Mail Typ',
      'type' => 'dropdown',
      'values' => array(
        'multibody' => 'HTML und Text',
        'text' => 'Nur Text'
      )
    );

    // Allow the use of alternating from email domains
    if (LbwpFormSettings::get('allowAlternateFromEmail')) {
      $config = array(
        'name' => 'E-Mail Absender',
        'type' => 'dropdown',
        'help' => 'Hiermit kann der standardmässige technische Absender der E-Mail überschrieben werden.',
        'values' => array(
          LBWP_CUSTOM_FROM_EMAIL => LBWP_CUSTOM_FROM_EMAIL
        )
      );
      // Additional values from config
      $config['values'] = array_merge($config['values'], LbwpFormSettings::get('alternateFromEmails'));
      $this->paramConfig['fromemail'] = $config;
      // Also allow an alternate from name in that case
      $this->paramConfig['fromname'] = array(
        'name' => 'E-Mail Absendername',
        'type' => 'textfield',
        'help' => 'Dieser Absendername überschreibt den Standard Absendernamen, wenn definiert.'
      );
    }
  }

  /**
   * @param string $key the key of the item
   */
  public function load($key)
  {
    // Set the id
    $this->params['key'] = $key;
    // Set the defaults (will be overridden with current data on execute)
    $this->params['betreff'] = 'Kontaktformular ausgefüllt';
    $this->params['email'] = '';
  }

  /**
   * @param array $data the form data key/value pairs
   * @return bool true if successfully executed
   */
  public function execute($data)
  {
    // If there is no config in email field, return with error
    if (strlen($this->params['email']) == 0) {
      return false;
    }

    // Set static variables that may eventually be used in filters
    self::$currentFormId = Core::getInstance()->getFormHandler()->getCurrentForm()->ID;
    $this->params = apply_filters('lbwp_forms_email_action_execute_params', $this->params, self::$currentFormId);

    // Create the mail base data
    $mail = External::PhpMailer();
    self::$currentFormSubject = $mail->Subject;
    $mail->Subject = $this->getFieldContent($data, $this->params['betreff']);
    $this->addAddresses($mail, 'AddAddress', $this->getFieldContent($data, $this->params['email']));

    // If isset, use CC and BCC
    if (isset($this->params['cc']) && strlen($this->params['cc']) > 0) {
      $this->addAddresses($mail, 'addCC', $this->getFieldContent($data, $this->params['cc']));
    }
    if (isset($this->params['bcc']) && strlen($this->params['bcc']) > 0) {
      $this->addAddresses($mail, 'addBCC', $this->getFieldContent($data, $this->params['bcc']));
    }

    // Override from and fromname, if given
    if (isset($this->params['fromemail']) && Strings::checkEmail($this->params['fromemail'])) {
      $mail->From = $this->params['fromemail'];
    }
    if (isset($this->params['fromname']) && strlen($this->params['fromname']) > 0) {
      $mail->FromName = $this->getFieldContent($data, $this->params['fromname']);
    }

    // Try to find a reply to address
    $replyTo = '';
    if (isset($this->params['replyto']) && strlen($this->params['replyto']) > 0) {
      $replyTo = $this->getFieldContent($data, $this->params['replyto']);
    } else {
      // Try to find reply to address in email field
      foreach ($data as $field) {
        /** @var Textfield $field ['item'] is it an email field? */
        if (isset($field['item']) && $field['item'] instanceof Textfield && $field['item']->get('type') == 'email') {
          $replyTo = $field['item']->getValue();
          break;
        }
      }
    }

    // If reply to still empty, set admin email
    if (strlen($replyTo) == 0) {
      $replyTo = get_option('admin_email');
    }

    $skipEmpty = false;
    if (isset($this->params['hideempty']) && $this->params['hideempty'] == 'hide') {
      $skipEmpty = true;
    }
    // Append email body with file html template
    $this->appendMailBody($data, $mail, $replyTo, $skipEmpty);

    // Maybe the anhang is available trough another field
    $this->params['anhang'] = $this->getFieldContent($data, $this->params['anhang']);
    // Add attachment, if needed
    if (isset($this->params['anhang']) && Strings::checkURL($this->params['anhang'])) {
      $tempPath = tempnam(sys_get_temp_dir(), 'Sma');
      if (Strings::contains($this->params['anhang'], '/wp-file-proxy.php?key=')) {
        /** @var LBWP\Module\Backend\S3Upload $s3 */
        $s3 = LbwpCore::getModule('S3Upload');
        // Extract the key and get binary directly from s3 component
        $pos = strrpos($this->params['anhang'], '?key=') + 5;
        $key = ASSET_KEY . '/files/' . urldecode(substr($this->params['anhang'], $pos));
        $rawObject = $s3->getRawObject($key);
        $binary = $rawObject->get('Body');
        $fileName = substr($key, strripos($key, '/') + 1);
      } else {
        // Classic open to public file, just get it via standard tools
        $binary = file_get_contents($this->params['anhang']);
        $fileName = substr($this->params['anhang'], strripos($this->params['anhang'], '/') + 1);
      }

      $hd = fopen($tempPath, 'w+b');
      fwrite($hd, $binary);
      fclose($hd);
      // Save the file temporary
      $mail->AddAttachment($tempPath, $fileName);
    }

    // If local development write some debug infos
    if (SystemLog::logMailLocally($mail->Subject, $this->params['email'], $mail->Body)) {
      return true;
    }

    // Check for mass mail signature
    WordPress::checkSignature('massmail', 30, 25, 1800);
    // Send and assume it worked
    if (!$mail->Send()) {
      $data = array_merge($this->params, array(
        'mail_error' => $mail->isError(),
        'mail_error_msg' => $mail->ErrorInfo,
        'mail_to' => $this->getFieldContent($data, $this->params['email'])
      ));
      SystemLog::add('Action/SendMail', 'error', 'unable to send email in action', $data);
    }
    return true;
  }

  /**
   * @param \PHPMailer\PHPMailer\PHPMailer $mail
   * @param $function
   * @param $adresses
   */
  protected function addAddresses(&$mail, $function, $adresses)
  {
    $addresses = array_map('trim', explode(';', $adresses));
    foreach ($addresses as $address) {
      call_user_func(array($mail, $function), $address);
    }
  }

  /**
   * @return string[]
   */
  public static function getTemplateHtml()
  {
    return apply_filters('lbwpForms_Action_SendMail_template', array(
      'container-open' => '',
      'container-close' => '',
      'rows' => '
          <!--[if mso]>
          <table width="100%" cellpadding="0" cellspacing="0" border="0">
            <tr>
              <td style="padding-right: 50px; padding-left: 50px; padding-top: 5px; padding-bottom: 10px; font-family: Arial, sans-serif">
          <![endif]-->
          <div style="color:#555555;font-family:Arial, Helvetica Neue, Helvetica, sans-serif;line-height:1.5;padding-top:5px;padding-right:50px;padding-bottom:10px;padding-left:50px;">
            <div style="line-height: 1.5; font-size: 12px; color: #555555; font-family: Arial, Helvetica Neue, Helvetica, sans-serif; mso-line-height-alt: 18px;">
              <p style="font-size: 12px; line-height: 1.5; word-break: break-word; mso-line-height-alt: 17px; margin: 0;">
                <span style="font-size: 12px;">
                  <strong>
                    {name}
                  </strong>
                </span>
              </p>
              <p style="font-size: 14px; line-height: 1.5; word-break: break-word; mso-line-height-alt: 21px; margin: 0;">
                  {value}
              </p>
            </div>
          </div>
          <!--[if mso]>
              </td>
            </tr>
          </table>
          <![endif]-->',
    ));
  }

  /**
   * @param array $data field data
   * @param \PHPMailer $mail the email object by reference
   * @param string $replyTo a reply to address
   * @param bool $skipEmpty skips empty fields
   */
  protected function appendMailBody($data, &$mail, $replyTo, $skipEmpty)
  {
    $template = self::getTemplateHtml();
    $table = self::getDataHtmlTable($data, $skipEmpty, $template);
    $type = strlen($this->params['email_type']) > 0 ? $this->params['email_type'] : 'multibody';

    $html = '
      <p>' . __('Ein Formular wurde ausgefüllt', 'lbwp') . ':</p>
      <br />
      ' . self::FORM_CONTENT_VARIABLE . '
    ';

    // Add reply to address if valid
    if (strlen($replyTo) > 0 && Strings::checkEmail($replyTo)) {
      $mail->addReplyTo($replyTo);
    } else {
      // Add info Text, if there is no reply to defined
      $html .= '<p>' . __('Zum Beantworten dieser E-Mail nutzen sie bitte die Weiterleiten Funktion und kopieren die E-Mail Adresse aus den Formulardaten, sofern der Besucher eine E-Mail Adresse angegeben hat.', 'lbwp') . '</p>';
    }

    // Prepare the content variable
    $email = array(
      'subject' => $mail->Subject,
      'addition-subject' => $this->getFieldContent($data, $this->params['betreffzusatz']),
      'logo' => get_bloginfo('name'),
      'heading' => '',
      'content' => '',
      'greeting' => '',
      'footer' => $this->params['footer'],
      'meta' => __('Diese E-Mail wurde generiert am', 'lbwp') . ' ' . date_i18n('d.m.Y H:i:s'),
    );

    // Create the email content block (Table only or content with table inside, eventually)
    if (strlen(trim($this->content)) == 0) {
      // Explode the content to set heading and greeting
      $stripContent = explode(self::FORM_CONTENT_VARIABLE, $html);
      $email['heading'] = isset($stripContent[0]) ? wpautop(strip_tags(trim($stripContent[0]))) : '';
      $email['greeting'] = isset($stripContent[1]) ? wpautop(strip_tags(trim($stripContent[1]))) : '';
      $email['content'] = $table;
    } else {
      // Explode the content to set heading and greeting
      $stripContent = explode(self::FORM_CONTENT_VARIABLE, $this->content);
      $email['heading'] = isset($stripContent[0]) ? wpautop(strip_tags(trim($stripContent[0]))) : '';
      $email['greeting'] = isset($stripContent[1]) ? wpautop(strip_tags(trim($stripContent[1]))) : '';
      // Add content and form table, if needed

      if (strpos($this->content, self::FORM_CONTENT_VARIABLE) !== false) {
        $this->content = $table;
      } else if ($type !== 'text') {
        $this->content = '';
      }

      // Add all fields, if given seperately
      foreach ($data as $field) {
        $email['heading'] = str_replace('{' . $field['id'] . '}', $field['value'], $email['heading']);
        $this->content = str_replace('{' . $field['id'] . '}', $field['value'], $this->content);
        $email['greeting'] = str_replace('{' . $field['id'] . '}', $field['value'], $email['greeting']);
      }
      $email['content'] = $this->content;
    }

    // Depending on type to an HTML mail or sole text email
    if ($type == 'text') {
      $mail->isHTML(false);
      if (strlen($this->content) > 0) {
        $mail->Body = $this->maybeFixCustomContent($email['content']);
      } else {
        $mail->Body = self::getDataTextTable($data, $skipEmpty);
      }
    } else {
      $mail->Body = $this->getEmailTemplateBody($email);
    }
  }

  /**
   * removes variables that were not replaces and removes unnecessary line breaks resulting from that
   * @param string $content
   * @return string
   */
  protected function maybeFixCustomContent($content)
  {
    // Remove leftover {xxxx} variables if given
    $content = preg_replace('/\{.*?\}/', '', $content);
    // Replace all triple line breaks until there are no more
    while (strpos($content, "\n\n\n") !== false) {
      $content = preg_replace('/\n\n\n/', "\n\n", $content);
    }
    // Also try remove a special combination in html mails
    $removeHtml = '<p><br />' . PHP_EOL . '<br />' . PHP_EOL . '</p>';
    $content = str_replace($removeHtml, '', $content);

    return $content;
  }

  /**
   * Create a very simple html table from input data
   * @param array $data list of data fields
   * @param bool $skipempty skips empty entries
   * @param array $template altenative html to use for the table.
   *   'container-open'     =>      the container element for the table (opening tag)
   *   'container-close'    =>      the container element for the table (closing tag)
   *   'rows'               =>      The html for the rows, Use {name} for the label and {value} for the content
   * @return string html table
   */
  public static function getDataHtmlTable($data, $skipempty, $template)
  {
    $table = $template['container-open'];
    // Let developers add their own rows to the table
    $table .= apply_filters('lbwpForms_Action_SendMail_beforeTableRows', '');

    // Loop trought the fields data
    foreach ($data as $field) {
      // Pagebreaks can be skipped
      if ($field['item'] instanceof PageBreak) {
        continue;
      }
      // There are some field id's that can be skipped
      if (!isset($field['item']) || ($field['item'] instanceof BaseItem && $field['item']->get('show_in_mail_action') != 1 && !($field['item'] instanceof HtmlItem))) {
        $name = strtolower($field['name']);
        if (
          $name == 'tsid' ||
          $name == 'user-ip-adresse' ||
          $name == 'zeitstempel' ||
          $name == 'ursprungsformular' ||
          $field['item'] instanceof Hiddenfield
        ) {
          continue;
        }
      } else if ($field['item'] instanceof HtmlItem) {
        if ($field['item']->get('show_anyway') == 'ja') {
          $field['name'] = '';
        } else {
          continue;
        }
      }

      // Check the value
      if (!isset($field['value']) || strlen($field['value']) == 0 && !$skipempty) {
        $field['value'] = __('Nicht ausgefüllt', 'lbwp');
      }

      // Display the actual table row
      if (isset($field['value']) && strlen($field['value']) > 0) {
        $value = $field['value'];
        // Get the fields possible params and see if we need to translate == operators
        $displayValues = $field['item']->getContent();
        if (Strings::contains($displayValues, BaseItem::MULTI_KEY_VALUE_SEPARATOR) && Strings::contains($displayValues, BaseItem::MULTI_ITEM_VALUES_SEPARATOR)) {
          $displayValues = explode(BaseItem::MULTI_ITEM_VALUES_SEPARATOR, $displayValues);
          foreach ($displayValues as $candidate) {
            if (Strings::startsWith($candidate, $value . BaseItem::MULTI_KEY_VALUE_SEPARATOR)) {
              $value = str_replace($value . BaseItem::MULTI_KEY_VALUE_SEPARATOR, '', $candidate);
            }
          }
        }

        // Depending on template, fill in the variables
        if (strlen($field['name']) == 0 && isset($template['rows_no_name'])) {
          $row = str_replace('{value}', $value, $template['rows_no_name']);
        } else {
          $row = str_replace('{name}', $field['name'], $template['rows']);
          $row = str_replace('{value}', $value, $row);
        }
        $table .= $row;
      }
    }

    // Let developers add their own rows to the table
    $table .= apply_filters('lbwpForms_Action_SendMail_afterTableRows', '', $template['rows'], self::$currentFormId, self::$currentFormSubject);

    // Close the table and return
    $table .= $template['container-close'];
    $table = apply_filters('lbwpForms_Action_SendMail_Html', $table);
    return $table;
  }

  /**
   * Create a very simple html table from input data
   * @param array $data list of data fields
   * @param bool $skipempty skips empty entries
   * @return string html table
   */
  public static function getDataTextTable($data, $skipempty)
  {
    $table = '';

    // Loop trought the fields data
    foreach ($data as $field) {
      // Pagebreaks can be skipped
      if ($field['item'] instanceof PageBreak) {
        continue;
      }
      // There are some field id's that can be skipped
      if (!isset($field['item']) || ($field['item'] instanceof BaseItem && $field['item']->get('show_in_mail_action') != 1 && !($field['item'] instanceof HtmlItem))) {
        $name = strtolower($field['name']);
        if (
          $name == 'tsid' ||
          $name == 'user-ip-adresse' ||
          $name == 'zeitstempel' ||
          $name == 'ursprungsformular' ||
          $field['item'] instanceof Hiddenfield
        ) {
          continue;
        }
      } else if ($field['item'] instanceof HtmlItem) {
        if ($field['item']->get('show_anyway') == 'ja') {
          $field['name'] = '';
        } else {
          continue;
        }
      }

      // Check the value
      if (!isset($field['value']) || strlen($field['value']) == 0 && !$skipempty) {
        $field['value'] = __('Nicht ausgefüllt', 'lbwp');
      }

      // Display the actual table row
      if (isset($field['value']) && strlen($field['value']) > 0) {
        $value = $field['value'];
        // Get the fields possible params and see if we need to translate == operators
        $displayValues = $field['item']->getContent();
        if (Strings::contains($displayValues, BaseItem::MULTI_KEY_VALUE_SEPARATOR) && Strings::contains($displayValues, BaseItem::MULTI_ITEM_VALUES_SEPARATOR)) {
          $displayValues = explode(BaseItem::MULTI_ITEM_VALUES_SEPARATOR, $displayValues);
          foreach ($displayValues as $candidate) {
            if (Strings::startsWith($candidate, $value . BaseItem::MULTI_KEY_VALUE_SEPARATOR)) {
              $value = str_replace($value . BaseItem::MULTI_KEY_VALUE_SEPARATOR, '', $candidate);
            }
          }
        }

        // Depending on template, fill in the variables
        if (strlen($field['name']) == 0 && isset($template['rows_no_name'])) {
          $row = $value . PHP_EOL;
        } else {
          $row = $field['name'] . ': ' . $value . PHP_EOL;
        }
        $table .= $row;
      }
    }

    return $table;
  }

  /**
   * @return string an html template
   */
  public function getHtmlTemplate($template = self::DEFAULT_TEMPLATE_KEY)
  {
    $this->setParamConfig();
    return $this->htmlTemplates[$template];
  }

  /**
   * @param array $variables the replace vars for the template
   * @return string the finished html template
   */
  protected function getEmailTemplateBody($variables)
  {
    $template = $this->htmlTemplates[self::DEFAULT_TEMPLATE_KEY];
    if (strlen($this->params['template']) > 0 && isset($this->htmlTemplates[$this->params['template']])) {
      $template = $this->htmlTemplates[$this->params['template']];
    }

    // Get settings for colors and logo
    $config = LbwpCore::getInstance()->getConfig();

    $templateSettings = array(
      'background-color' => isset($config['EmailTemplates:BackgroundColor']) && !Strings::isEmpty($config['EmailTemplates:BackgroundColor']) ?
        $config['EmailTemplates:BackgroundColor'] :
        self::DEFAULT_BACKGROUND_COLOR,
      'primary-color' => isset($config['EmailTemplates:PrimaryColor']) && !Strings::isEmpty($config['EmailTemplates:PrimaryColor']) ?
        $config['EmailTemplates:PrimaryColor'] :
        self::DEFAULT_PRIMARY_COLOR,
    );

    $variables = array_merge($variables, $templateSettings);

    $logoSize = 112;
    if (isset($config['EmailTemplates:LogoSize']) &&
      !Strings::isEmpty($config['EmailTemplates:LogoSize']) &&
      intval($config['EmailTemplates:LogoSize']) !== 0) {
      $logoSize = $config['EmailTemplates:LogoSize'];
    }

    if (isset($config['EmailTemplates:LogoImageUrl']) && !Strings::isEmpty($config['EmailTemplates:LogoImageUrl'])) {
      $variables['logo'] = '
          <img
            align="center"
            alt="' . $variables['logo'] . '"
            border="0"
            class="center fixedwidth"
            src="' . Converter::forceNonWebpImageUrl($config['EmailTemplates:LogoImageUrl']) . '"
            style="text-decoration: none; -ms-interpolation-mode: bicubic; height: auto; border: 0; width: 100%; max-width: ' . $logoSize . 'px; display: block;"
            title="' . $variables['logo'] . '"
            width="' . $logoSize . '"
          />
      ';
    }
    $variables['logo'] = '
      <a href="' . get_bloginfo('url') . '" style="line-height: 1.5; font-size: 18px; color: #555555; font-weight: bold; font-family: Arial, Helvetica Neue, Helvetica, sans-serif; mso-line-height-alt: 27px; text-decoration: none; ">
        ' . $variables['logo'] . '
      </a>
    ';

    // Get the actual file content and fallback to content only, if the template is missing
    $template = file_get_contents($template['file']);

    // Put heading and greeting back together with the content if there is no variable in the tamplate
    if (stristr($template, '{email-heading}') === false) {
      $variables['content'] = $variables['heading'] . $variables['content'];
    }
    if (stristr($template, '{email-greeting}') === false) {
      $variables['content'] = $variables['content'] . $variables['greeting'];
    }

    // Vertical centering the header (subject and additional subject)
    $template = str_replace('{header-centering}', (Strings::isEmpty($variables['addition-subject']) ? '0' : '15'), $template);

    if (stristr($template, '{' . self::MINIMUM_TEMPLATE_VAR . '}') !== false) {
      foreach ($variables as $key => $value) {
        $template = str_replace('{email-' . $key . '}', $value, $template);
      }
    } else {
      return $variables[self::MINIMUM_TEMPLATE_VAR];
    }

    return $this->maybeFixCustomContent($template);
  }
}