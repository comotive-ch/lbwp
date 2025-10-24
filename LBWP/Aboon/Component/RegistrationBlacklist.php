<?php

namespace LBWP\Aboon\Component;

use LBWP\Module\General\Cms\SystemLog;
use LBWP\Theme\Base\Component;
use LBWP\Util\Strings;


/**
 * Validate registration data against a blacklist
 * @package LBWP\Aboon\Component
 * @author Mirko Baffa <mirko@comotive.ch>
 */
class RegistrationBlacklist extends Component{

  private $domainBlacklist = array();

  static $instance;

  public function init(){
    self::$instance = $this;

    // Eventually add domains to the blacklist
    $this->domainBlacklist = apply_filters('aboon_registration_blacklist', array());

    // Hook into the registration process
    add_action('woocommerce_register_post', array($this, 'validateRegistrationEmail'), 10, 3);
  }

  public function validateRegistrationEmail($userLogin, $email, $errors){
    // Get string until @
    $emailName = substr($email, 0, strpos($email, "@"));

    // Check if there are more than 4 dots in the email name
    if(substr_count($emailName, ".") >= 4){
      $errors->add('unallowed_email', __('Diese Email-Adresse ist nicht erlaubt.', 'lbwp'));
      return;
    }

    // Check blacklist
    foreach($this->domainBlacklist as $blacklistedDomain){
      if(Strings::endsWith($email, $blacklistedDomain)){
        $errors->add('unallowed_email', __('Diese Email-Adresse ist nicht erlaubt.', 'lbwp'));
        return;
      }
    }
  }
}

?>