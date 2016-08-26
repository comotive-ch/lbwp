<?php

namespace LBWP\Theme\Feature;

use LBWP\Module\Listings\Core as ListingCore;

/**
 * Allows a developer to add predefined listing items to their theme
 * @package LBWP\Theme\Feature
 * @author Michael Sebel <michael@comotive.ch>
 */
class ListingTemplate
{
  /**
   * @var string template ids
   */
  const TEMPLATE_ID_PEOPLE = 'people';
  const TEMPLATE_ID_CONTENT_IMAGE = 'content-image';
  const TEMPLATE_ID_LOGO = 'logo';
  /**
   * @var string the core view path (default)
   */
  const CORE_VIEWS_PATH = '/wp-content/plugins/lbwp/views/module/listings/items/';
  /**
   * Enqueues core styles to have a working default design
   * @see not implemented yet
   */
  public static function enqueueCoreStyles() { }

  /**
   * Enqueues core scripts to have a working default logic
   * @see not implemented yet
   */
  public static function enqueueCoreScripts() { }

  /**
   * Enqueues core styles and scripts
   */
  public static function enqueueCoreAssets()
  {
    self::enqueueCoreScripts();
    self::enqueueCoreStyles();
  }

  /**
   * Add a simple image/content template with configuration if image is left/right
   * @return string $templateKey the key to use as box for metabox helper
   */
  public static function addContentWithImage()
  {
    if (ListingCore::isActive()) {
      // Get configurator and add the template
      $config = ListingCore::getInstance()->getConfigurator();
      $container = '<div class="lbwp-content-list {additional-class}">{listing}</div>';
      $boxId = $config->addTemplate(self::TEMPLATE_ID_CONTENT_IMAGE, __('Liste von Inhalten (Text und Bild)', 'lbwp'), $container);
      $config->setTemplateRootPath(self::TEMPLATE_ID_CONTENT_IMAGE, self::CORE_VIEWS_PATH . 'content-image.php');

      // Add the box and fields, but only in admin
      add_action('admin_init', function() use($boxId) {
        $helper = ListingCore::getInstance()->getHelper();
        $helper->addMetabox($boxId, __(sprintf('Einstellungen "%s"', 'Inhalt mit Bild'), 'lbwp'));
        $helper->addInputText('content-title', $boxId, __('Titel', 'lbwp'));
        $helper->addEditor('content-text', $boxId, __('Inhalt', 'lbwp'), 10);
        $helper->addMediaUploadField('content-image', $boxId, __('Bild', 'lbwp'));
        //todo add "alignright" to template
        $helper->addDropdown('image-position', $boxId, __('Bild-Position', 'lbwp'), array(
          'multiple' => false,
          'sortable' => false,
          'items' => array(
            'alignleft' => __('Links', 'lbwp')//,
            //'alignright' => __('Rechts', 'lbwp')
          )
        ));
        $helper->addInputText('link', $boxId, __('Link'));
        $helper->addInputText('link-text', $boxId, __('Linktext'),array('description' => 'Wenn du kein Linktext eingibst, wird «mehr» verwendet.'));
      });

      // Return the metabox id for further use
      return $boxId;
    }

    return false;
  }

  /**
   * Add a template that will show a list of people with corresponding configurations
   * @return string $templateKey the key to use as box for metabox helper
   */
  public static function addPeopleList()
  {
    if (ListingCore::isActive()) {
      // Get configurator and add the template
      $config = ListingCore::getInstance()->getConfigurator();
      $container = '<section class="lbwp-people-list {additional-class}">{listing}</section>';
      $boxId = $config->addTemplate(self::TEMPLATE_ID_PEOPLE, __('Personen-Auflistung', 'lbwp'), $container);
      $config->setTemplateRootPath(self::TEMPLATE_ID_PEOPLE, self::CORE_VIEWS_PATH . 'people.php');

      // Add the fields
      add_action('admin_init', function() use($boxId) {
        $helper = ListingCore::getInstance()->getHelper();
        $helper->addMetabox($boxId, __(sprintf('Einstellungen "%s"', 'Person'), 'lbwp'));
        $helper->addInputText('firstname', $boxId, __('Vorname', 'lbwp'));
        $helper->addInputText('lastname', $boxId, __('Nachname', 'lbwp'));
        $helper->addInputText('salutation', $boxId, __('Anrede', 'lbwp'));
        $helper->addInputText('role', $boxId, __('Funktion/Rolle', 'lbwp'));
        $helper->addInputText('email', $boxId, __('E-Mail-Adresse', 'lbwp'));
        $helper->addEditor('description', $boxId, __('Beschreibung', 'lbwp'), 10);
        $helper->addMediaUploadField('avatar', $boxId, __('Bild', 'lbwp'));
      });

      // Return the metabox id for further use
      return $boxId;
    }

    return false;
  }

  /**
   * Add a hybrid link or logo list with link, title, image field
   * @return string $templateKey the key to use as box for metabox helper
   */
  public static function addLogoList()
  {
    if (ListingCore::isActive()) {
      // Get configurator and add the template
      $config = ListingCore::getInstance()->getConfigurator();
      $container = '<ul class="lbwp-logo-and-link-list {additional-class}">{listing}</ul>';
      $boxId = $config->addTemplate(self::TEMPLATE_ID_LOGO, __('Logo- oder Link-Auflistung', 'lbwp'), $container);
      $config->setTemplateRootPath(self::TEMPLATE_ID_LOGO, self::CORE_VIEWS_PATH . 'logo.php');

      // Add the fields
      add_action('admin_init', function() use($boxId) {
        $helper = ListingCore::getInstance()->getHelper();
        $helper->addMetabox($boxId, __(sprintf('Einstellungen "%s"', 'Logo'), 'lbwp'));
        $helper->addInputText('logo-title', $boxId, __('Logo Titel', 'lbwp'));
        $helper->addMediaUploadField('logo-image', $boxId, __('Logo Bild', 'lbwp'));
        $helper->addInputText('logo-link', $boxId, __('Ziel-Link', 'lbwp'), array(
          'placeholder' => 'http://'
        ));
        $helper->addDropdown('logo-link-target', $boxId, __('Link öffnen in'), array(
          'multiple' => false,
          'sortable' => false,
          'items' => array(
            '_self' => __('Im selben Tab/Fenster', 'lbwp'),
            '_blank' => __('In neuem Tab/Fenster', 'lbwp')
          )
        ));
      });

      // Return the metabox id for further use
      return $boxId;
    }

    return false;
  }
} 