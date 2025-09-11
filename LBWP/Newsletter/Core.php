<?php

namespace LBWP\Newsletter;

use LBWP\Core as LbwpCore;
use LBWP\Newsletter\Component\MenuHandler;
use LBWP\Newsletter\Component\Settings;
use LBWP\Newsletter\Service\Definition as ServiceDefinition;
use LBWP\Newsletter\Service\Base as ServiceBase;

/**
 * This is the core file which manages the whole newsletter tool
 * @author Michael Sebel <michael@comotive.ch>
 */
class Core extends \LBWP\Module\Base
{
  /**
   * @var null
   */
  protected $serviceInstance = NULL;
  /**
   * @var bool true/false
   */
  protected $isWorkingServiceAvailable = NULL;
  /**
   * @var MenuHandler this handles the menus
   */
  protected $components = array(
    '\\LBWP\\Newsletter\\Component\\MenuHandler' => NULL,
    '\\LBWP\\Newsletter\\Component\\Settings' => NULL,
  );
  /**
   * @var array list of service classes
   */
  protected $serviceClasses = array(
    '\\LBWP\\Newsletter\\Service\\Mailchimp\\Implementation',
    '\\LBWP\\Newsletter\\Service\\Emarsys\\Implementation',
    '\\LBWP\\Newsletter\\Service\\LocalMail\\Implementation'
  );

  /**
	 * Call parent constructor and initialize the module
	 */
	public function __construct()
  {
		parent::__construct();
	}

  /**
   * Initialize the module, load subclasses etc.
   */
  public function initialize()
  {
    // Load the components
    foreach ($this->components as $class => $null) {
      $this->components[$class] = new $class($this);
      $this->components[$class]->load();
    }
    // Load actions, if a working service is available
    if ($this->isWorkingServiceAvailable()) {
      add_filter('lbwpFormActions', array($this, 'addFormActions'));
    }
  }

  /**
   * @return bool true, if a working service is available
   */
  public function isWorkingServiceAvailable()
  {
    if ($this->isWorkingServiceAvailable == NULL) {
      $service = $this->getService();
      $this->isWorkingServiceAvailable = ($service instanceof ServiceBase && $service->isWorking());
    }

    return $this->isWorkingServiceAvailable;
  }

  /**
   * @return bool true, if editor has been deactivated
   */
  public function isEditorDeactivated()
  {
    return (
      isset($this->features['PublicModules']['NewsletterDeactivated']) &&
      $this->features['PublicModules']['NewsletterDeactivated'] == 1
    );
  }

  /**
   * This will at the unsubscribe and subscribe actions to the form tool
   * @param array $actions list of current actions
   * @return array altered $actions array with new actions
   */
  public function addFormActions($actions)
  {
    // Add the two actions and return
    $actions['newsletter-subscribe'] = '\LBWP\Module\Forms\Action\Newsletter\Subscribe';
    $actions['newsletter-unsubscribe'] = '\LBWP\Module\Forms\Action\Newsletter\Unsubscribe';

    return $actions;
  }

  /**
   * @return ServiceDefinition|ServiceBase the mail service instance
   */
  public function getService($newsletter = NULL)
  {
    // If we have a newsletter and need to use localmail service
    if ($newsletter instanceof \ComotiveNL\Newsletter\Newsletter\Newsletter) {
      if ($newsletter->useLocalMail() == 1) {
        return new Service\LocalMail\Implementation($this, true);
      }
    }

    // If already instantiated, return the main service
    if ($this->serviceInstance != NULL) {
      return $this->serviceInstance;
    }

    // Check if it's loadable
    $className = $this->getSettings()->get('serviceClass');
    if (class_exists($className)) {
      $this->serviceInstance = new $className($this);
      return $this->serviceInstance;
    }

    // No service configured yet
    return false;
  }

  /**
   * Wrapper to get all current services list options
   * @param array $selectedKeys the selected list
   * @return array list of option html strings
   */
  public function getListOptions($selectedKeys = array(), $newsletter = NULL)
  {
    $service = $this->getService($newsletter);
    return $service->getListOptions($selectedKeys);
  }

  /**
   * @return array list of available services
   */
  public function getAvailableServices()
  {
    $services = array();

    foreach ($this->serviceClasses as $class) {
      $services[] = new $class($this);
    }

    return $services;
  }

  /**
   * @param string $id get a service by his signature id
   * @return \LBWP\Newsletter\Service\Definition the desired service
   */
  public function getServiceById($id)
  {
    $services = $this->getAvailableServices();

    foreach ($services as $service) {
      $signature = $service->getSignature();
      if ($signature['id'] == $id) {
        return $service;
      }
    }

    // If the service wasn't found
    return false;
  }

  /**
   * @return MenuHandler the menu handler instance
   */
  public function getMenuHandler()
  {
    return $this->components['\\LBWP\\Newsletter\\Component\\MenuHandler'];
  }

  /**
   * @return Settings the settings instance
   */
  public function getSettings()
  {
    return $this->components['\\LBWP\\Newsletter\\Component\\Settings'];
  }

  /**
   * @return \LBWP\Newsletter\Core the newsletter core
   */
  public static function getInstance()
  {
    return LbwpCore::getModule('NewsletterBase');
  }
}