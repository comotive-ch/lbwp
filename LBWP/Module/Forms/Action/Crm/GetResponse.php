<?php

namespace LBWP\Module\Forms\Action\Crm;

use LBWP\Module\Forms\Action\Base;
use LBWP\Module\General\Cms\SystemLog;
use LBWP\Util\ArrayManipulation;
use LBWP\Util\External;
use LBWP\Util\Strings;

/**
 * This will put data from the form to crm fields
 * @package LBWP\Module\Forms\Action\Newsletter
 * @author Michael Sebel <michael@comotive.ch>
 */
class GetResponse extends Base
{
  /**
   * @var string action name
   */
  protected $name = 'GetResponse';
  /**
   * @var \LBWP\Theme\Component\Crm\Core the crm core component
   */
  protected static $crm = NULL;
  /**
   * @var array set the field config for this field
   */
  protected $actionConfig = array(
    'name' => 'GetResponse',
    'help' => 'E-Mail Lead in GetResponse erstellen',
    'group' => 'CRM'
  );
  /**
   * @var array allowed types to be used for input
   */
  protected $allowedTypes = array(
    'textfield',
    'textarea'
  );

  /**
   * Extend the parameter configuration for the editor
   */
  protected function setParamConfig()
  {
    // Add internal title field
    $this->paramConfig = ArrayManipulation::deepMerge($this->paramConfig, array(
      'lead_email' => array(
        'name' => 'Lead E-Mail',
        'type' => 'textfield',
        'help' => 'Die E-Mail Adresse des hinzuzufügenden Leads.'
      ),
      'lead_name' => array(
        'name' => 'Lead Name (Optional)',
        'type' => 'textfield',
        'help' => 'Vor- und/oder Nachname des Leads.'
      ),
      'campaign_id' => array(
        'name' => 'Listen-Token',
        'type' => 'textfield',
        'help' => 'Liste zu der dieser Lead hinzugefügt werden soll. In GetResponse bei den Einstellungen der Liste zu finden.'
      ),
      'confirm_url' => array(
        'name' => 'Zielseite',
        'type' => 'textfield',
        'help' => '
          Diese Seite wird in der Opt-In E-Mail verlinkt. Sie sollte einen Bestätigungstext und weitere Infos zur angelaufenen Kampagne enhalten.
          Die URL darf auch Parameter für weiteres Tracking (z.B. utm_*) enthalten.
        '
      ),
      'doi_fromname' => array(
        'name' => 'Absender Name',
        'type' => 'textfield',
        'help' => 'Möglichst kurz halten, wird im E-Mail-Client angezeigt als Absender (nebst der E-Mail Adresse).'
      ),
      'doi_subject' => array(
        'name' => 'E-Mail Betreff',
        'type' => 'textfield',
        'help' => 'Betreff für die Double-Opt-In E-Mail.'
      ),
      'doi_text' => array(
        'name' => 'E-Mail Text',
        'type' => 'textarea',
        'help' => '
          Dieser Text ist für die Double-Opt-In E-Mail in welcher der Empfänger auf einen Bestätigungslink klicken muss um sich
          definitiv für die GetResponse Kampagne anzumelden. Die Variable für den Link lautet {confirmation}.
        '
      ),
    ));
  }


  /**
   * @param string $key the key of the item
   */
  public function load($key)
  {
    // Set the id
    $this->params['key'] = $key;
  }

  /**
   * @param array $data the form data key/value pairs
   * @return bool true if successfully executed
   */
  public function execute($data)
  {
    $email = $this->getFieldContent($data, $this->params['lead_email']);
    $name = $this->getFieldContent($data, $this->params['lead_name']);
    // Create the confirmation url
    $url = $this->params['confirm_url'];
    if (Strings::startsWith($url, '/')) {
      $url = get_bloginfo('url') . $url;
    }

    // Attach parameters as given
    $url = Strings::attachParam('gr_email', base64_encode($email), $url);
    $url = Strings::attachParam('gr_name', base64_encode($name), $url);
    $url = Strings::attachParam('gr_campaign', $this->params['campaign_id'], $url);

    // Create the email body
    $body = nl2br(html_entity_decode($this->params['doi_text']));
    $body = str_replace('{confirmation}', $url, $body);

    // Send the email
    $mail = External::PhpMailer();
    $mail->addAddress($email);
    $mail->Subject = $this->params['doi_subject'];
    $mail->Body = $body;
    $mail->FromName = $this->params['doi_fromname'];
    $mail->AltBody = Strings::getAltMailBody($body);
    return $mail->send();
  }
} 