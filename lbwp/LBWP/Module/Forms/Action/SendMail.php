<?php

namespace LBWP\Module\Forms\Action;

use LBWP\Module\Forms\Item\Hiddenfield;
use LBWP\Module\Forms\Item\Textfield;
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
   * @var string the default template key
   */
  const DEFAULT_TEMPLATE_KEY = 'default';
  /**
   * @var string the minimum template var
   */
  const MINIMUM_TEMPLATE_VAR = 'email-content';

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
      'email' => array(
        'name' => 'Empfänger-Adresse',
        'type' => 'textfield'
      ),
      'cc' => array(
        'name' => 'Weiterer Empfänger (CC)',
        'type' => 'textfield'
      ),
      'bcc' => array(
        'name' => 'Versteckter Empfänger (BCC)',
        'type' => 'textfield'
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
      'content' => array(
        'name' => 'E-Mail Inhalt überschreiben (Optional)',
        'type' => 'textarea',
        'help' => 'Hier können Sie Ihren eigenen E-Mail Inhalt definieren. Bleibt das Feld leer, wird eine Tabelle der Formulardaten in der E-Mail angezegit. Wenn Sie die Formulardaten in Ihrem Inhalt verwenden möchten, setzen Sie den Platzhalter {lbwp:formContent} ein. Sie können auch Formularfelder verwenden indem Sie die Feld-ID z.B. wie folgt verwenden: {email_adresse}.'
      ),
    ));

    // Define a basic single template for emails and let developers extend the array
    $this->htmlTemplates = apply_filters('lbwp_email_action_templates', array(
      self::DEFAULT_TEMPLATE_KEY => array(
        'name' => 'Standard-Vorlage',
        'file' => File::getResourcePath() . '/newsletter/zurb-emails-v2/lbwp-email/inlined.html'
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
    $this->params['email'] = 'Ihre E-Mail-Adresse';
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

    // Create the mail base data
    $mail = External::PhpMailer();
    $mail->Subject = $this->getFieldContent($data, $this->params['betreff']);
    $mail->AddAddress($this->getFieldContent($data, $this->params['email']));

    // If isset, use CC and BCC
    if (isset($this->params['cc']) && strlen($this->params['cc']) > 0) {
      $mail->addCC($this->getFieldContent($data, $this->params['cc']));
    }
    if (isset($this->params['bcc']) && strlen($this->params['bcc']) > 0) {
      $mail->addBCC($this->getFieldContent($data, $this->params['bcc']));
    }

    // Try to find a reply to address
    $replyTo = '';
    if (isset($this->params['replyto']) && strlen($this->params['replyto']) > 0) {
      $replyTo = $this->getFieldContent($data, $this->params['replyto']);
    } else {
      // Try to find reply to address in email field
      foreach ($data as $field) {
        /** @var Textfield $field['item'] is it an email field? */
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

    // Append email body with file html template
    $this->appendMailBody($data, $mail, $replyTo);

    // Add attachment, if needed
    if (isset($this->params['anhang']) && Strings::checkURL($this->params['anhang'])) {
      $tempPath = tempnam(sys_get_temp_dir(), 'Sma');
      $fileUrl = $this->params['anhang'];
      $binaryData = file_get_contents($fileUrl);
      $hd = fopen($tempPath, 'w+b');
      fwrite($hd, $binaryData);
      fclose($hd);
      // Get the original name, as attachment name
      $fileName = substr($fileUrl, strripos($fileUrl, '/') + 1);
      // Save the file temporary
      $mail->AddAttachment($tempPath, $fileName);
    }

    // If local development write some debug infos
    if (defined('LOCAL_DEVELOPMENT')) {
      $subject = str_replace(' ', '-', $mail->Subject);
      $filename = $subject . '_' . $this->params['email'];
      $filename = get_temp_dir() . Strings::validateField($filename) . '.html';
      file_put_contents($filename, $mail->Body);
      return true;
    }

    // Check for mass mail signature
    WordPress::checkSignature('massmail', 30, 25, 1800);

    // Send and assume it worked
    $mail->Send();
    return true;
  }

  /**
   * @param array $data field data
   * @param \PHPMailer $mail the email object by reference
   * @param string $replyTo a reply to address
   */
  protected function appendMailBody($data, &$mail, $replyTo)
  {
    $table = self::getDataHtmlTable($data);

    $html = '
      <p>' . __('Ein Formular auf Ihrer Webseite wurde ausgefüllt', 'lbwp') . ':</p>
      <br />
      ' . $table . '
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
      'meta' => __('Diese E-Mail wurde generiert am', 'lbwp') . ' ' . date_i18n('d.m.Y H:i:s'),
      'content' => ''
    );

    // Create the email content block (Table only or content with table inside, eventually)
    if (strlen(trim($this->content)) == 0) {
      $email['content'] = $html;
    } else {
      // Add content and form table, if needed
      $this->content = wpautop(strip_tags(trim($this->content)));
      $this->content = str_replace(self::FORM_CONTENT_VARIABLE, $table, $this->content);
      // Add all fields, if given seperately
      foreach ($data as $field) {
        $this->content = str_replace('{' . $field['id'] . '}', $field['value'], $this->content);
      }
      $email['content'] = $this->content;
    }

    $mail->Body = $this->getEmailTemplateBody($email);
  }

  /**
   * Create a very simple html table from input data
   * @param array $data list of data fields
   * @return string html table
   */
  public static function getDataHtmlTable($data)
  {
    $table = '<table width="100%" cellpadding="5" cellspacing="0" border="0">';

    // Loop trought the fields data
    foreach ($data as $field) {
      // There are some field id's that can be skipped
      if (
        $field['name'] == 'tsid' ||
        $field['name'] == 'user-ip-adresse' ||
        $field['name'] == 'zeitstempel' ||
        $field['name'] == 'ursprungsformular' ||
        $field['item'] instanceof Hiddenfield
      ) {
        continue;
      }

      // Check the value
      if (!isset($field['value']) || strlen($field['value']) == 0) {
        $field['value'] = __('Nicht ausgefüllt', 'lbwp');
      }

      // Print the field
      $table .= '
        <tr>
          <td width="25%">' . $field['name'] . ':</td>
          <td width="75%">' . $field['value'] . '</td>
        </tr>
      ';
    }

    // Close the table and return
    $table .= '</table>';
    return $table;
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

    // Get the actual file content and fallback to content only, if the template is missing
    $template = file_get_contents($template['file']);
    if (stristr($template, '{' . self::MINIMUM_TEMPLATE_VAR . '}') !== false) {
      foreach ($variables as $key => $value) {
        $template = str_replace('{email-' . $key . '}', $value, $template);
      }
    } else {
      return $variables[self::MINIMUM_TEMPLATE_VAR];
    }

    return $template;
  }
} 