<?php

namespace LBWP\Module\Forms\Item;

/**
 * This will display a calculation
 * @package LBWP\Module\Forms\Item
 * @author Michael Sebel <michael@comotive.ch>
 */
class Calculation extends Base
{
  /**
   * @var int internal counter to use this field multiple times
   */
  protected static $internalCounter = 0;
  /**
   * @var array set the field config for this field
   */
  protected $fieldConfig = array(
    'name' => 'Spamschutz (Rechnung)',
    'help' => 'Einfache Rechnung um Spam zu verhindern',
    'group' => 'Spezial-Felder'
  );

  /**
   * Extend the parameter configuration for the editor
   */
  protected function setParamConfig()
  {

  }

  /**
   * @param string $key the key of the item
   */
  public function load($key)
  {
    // Set the id
    $this->params['id'] = $key . '_' . $this->formHandler->getNextId();
    $this->params['key'] = $key;
    $this->params['pflichtfeld'] = 'ja';
    $this->params['description'] = 'Rechnung - Schutz vor Spam';
    $this->params['feldname'] = 'Spamschutz';
  }

  /**
   * @param array $args the shortcode params
   * @param string $content the shortcode content
   * @return string HTML code to display
   */
  public function getElement($args, $content)
  {
    $attr = $this->getDefaultAttributes($args);
    $internalId = (++self::$internalCounter);

    // Make the field (hidden field has a distraction name)
    $field =
      '<span class="lbwp-form-calc-label">' .
      __('Tragen Sie das Ergebnis folgender Rechnung ein:', 'lbwp') . '</span> <span class="lbwpFormCalc" id="lfci_' . $internalId . '"></span>
      <input type="text" value=""' . $attr . '/>
      <input type="hidden" value="" id="lfll_' . $internalId . '" name="lbwpFormLiegeLever" />
    ';

    // Display a send button
    $html = Base::$template;
    $html = str_replace('{id}', $this->get('id'), $html);
    $html = str_replace('{label}', $this->params['feldname'], $html);
    $html = str_replace('{class}', trim('number-field ' . $this->params['class']), $html);
    $html = str_replace('{field}', $field, $html);

    // Add the various possible calculations that are selected randomly
    $html .= $this->addCalculatorScript($internalId);

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
      $value = $_POST[$this->get('id')];
      // If the form is being executed, check if the calculation is correct
      if ($this->formHandler->executingForm) {
        if ($_POST['lbwpFormLiegeLever'] != md5($value . LOGGED_IN_KEY)) {
          $this->formHandler->fieldError = true;
        }
      }

      return $value;
    }

    // No value means error
    $this->formHandler->fieldError = true;
    return false;
  }

  /**
   * This adds a few possible random calculations and it will select one of
   * them randomly as the result that must be given to the backend.
   * @param int $id internal id to make this work with multiple uses
   * @return string
   */
  protected function addCalculatorScript($id)
  {
    // First, get calculations
    $calculations = array();
    for ($i = 0; $i < 100; $i++) {
      list($key, $value) = $this->getCalculation();
      $calculations[$key] = $value;
    }

    // The script code
    return '
      <script type="text/javascript">
        jQuery(function() {
          var calcObject = ' . json_encode($calculations) . ';
          var calcKeys = ' . json_encode(array_keys($calculations)) . ';
          var randomIndex = Math.floor(Math.random() * (calcKeys.length-1 - 0)) + 0;
          var calcRandomKey = calcKeys[randomIndex];
          jQuery("#lfll_' . $id . '").val(calcRandomKey);
          jQuery("#lfci_' . $id . '").html(calcObject[calcRandomKey]);
        });
      </script>
    ';
  }

  /**
   * @return array 0=key 1=calculation
   */
  protected function getCalculation()
  {
     // Generate a calculation
    $first = mt_rand(5,10);
    $second = mt_rand(1,5);
    switch (mt_rand(1,3)) {
      case 1:
        $operator = '-';
        $result = $first - $second;
        break;
      case 2:
        $operator = 'x';
        $result = $first * $second;
        break;
      case 3:
      default:
        $operator = '+';
        $result = $first + $second;
        break;
    }

    return array(
      md5($result . LOGGED_IN_KEY),
      $first . '&nbsp;' . $operator . '&nbsp;' . $second
    );
  }
} 