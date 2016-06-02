<?php

namespace LBWP\Helper\MetaItem;
use LBWP\Util\ArrayManipulation;

/**
 * Helper method to display a full set of address fields
 * and also get long/lat coordinates via google maps api
 * @package LBWP\Helper\MetaItem
 * @author Michael Sebel <michael@comotive.ch>
 */
class AddressLocation
{
  /**
   * @param array $args the arguments given to display the field
   * @return string html code to represent the files
   */
  public static function displayFormFields($args)
  {
    $html = '';
    $data = ArrayManipulation::forceArray(get_post_meta($args['post']->ID, $args['key'], true));
    // Make a set of address fields
    $html .= SimpleField::displayInputText(
      array('default' => $data['street']),
      $args['key'] . '-street',
      self::getFieldTemplate($args['template'], 'Strasse / Nr.', $args['key'] . '-street')
    );
    $html .= SimpleField::displayInputText(
      array('default' => $data['zip']),
      $args['key'] . '-zip',
      self::getFieldTemplate($args['template'], 'PLZ', $args['key'] . '-zip')
    );
    $html .= SimpleField::displayInputText(
      array('default' => $data['city']),
      $args['key'] . '-city',
      self::getFieldTemplate($args['template'], 'Ort', $args['key'] . '-city')
    );
    $html .= SimpleField::displayInputText(
      array('default' => $data['addition']),
      $args['key'] . '-addition',
      self::getFieldTemplate($args['template'], 'Zusatz', $args['key'] . '-addition')
    );

    return $html;
  }

  /**
   * @param $template
   * @param $title
   * @param $id
   * @return mixed
   */
  public static function getFieldTemplate($template, $title, $id)
  {
    $template = str_replace('{title}', $title, $template);
    $template = str_replace('{fieldId}', $id, $template);

    return $template;
  }

  /**
   * @param int $postId the id of the post to save to
   * @param array $field all the fields information
   * @param string $boxId the metabox id
   */
  public static function saveFormFields($postId, $field, $boxId)
  {
    $address = array(
      'street' => $_POST[$field['key'] . '-street'],
      'zip' => $_POST[$field['key'] . '-zip'],
      'city' => $_POST[$field['key'] . '-city'],
      'addition' => $_POST[$field['key'] . '-addition'],
    );

    // Get long/lat info from address data
    $url = 'https://maps.googleapis.com/maps/api/geocode/json';
    $url.= '?key=' . GOOGLE_GEOLOCATION_API_KEY;
    $url.= '&address=' . urlencode(self::getAddressString($address));

    // Get json object
    $data = json_decode(file_get_contents($url), JSON_OBJECT_AS_ARRAY);
    $location = $data['results'][0]['geometry'];

    // If valid information, save it
    if (is_array($location['location']) && count($location['location']) > 0) {
      $address['location'] = $location['location'];
    }

    // Save address data array
    update_post_meta($postId, $field['key'], $address);
  }

  /**
   * @param array $address the address array
   * @return string the address string
   */
  public static function getAddressString($address)
  {
    return implode(', ', $address);
  }
} 