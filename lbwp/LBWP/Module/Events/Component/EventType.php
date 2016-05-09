<?php

namespace LBWP\Module\Events\Component;

use LBWP\Core as LbwpCore;
use LBWP\Helper\Metabox;
use LBWP\Util\WordPress;
use LBWP\Module\Forms\Component\Posttype as FormType;
use DateTime;
use DateInterval;

/**
 * This class handles the event post type
 * @package LBWP\Module\Events\Component
 * @author Michael Sebel <michael@comotive.ch>
 */
class EventType extends Base
{
  /**
   * Various slug constants
   */
  const EVENT_TYPE = 'lbwp-event';
  const EVENT_TAXONOMY = 'lbwp-event-category';
  /**
   * Called at init(50)
   */
  public function initialize()
  {
    $this->registerCustomType();
    // Add metaboxes
    add_action('admin_init', array($this, 'addMetaboxes'));

    // Register clean cron, if set
    if ($this->core->isEventCleanupActive()) {
      add_action('cron_monthly_14', array($this, 'cleanUpEvents'));
    }
  }

  /**
   * Register the type and the taxonomy defaults
   */
  public function registerCustomType()
  {
    // Event base type
    WordPress::registerType(self::EVENT_TYPE, 'Event', 'Events', array(
      'menu_position' => 22,
      'menu_icon' => 'dashicons-calendar-alt',
      'supports' => array('title', 'thumbnail'),
      'rewrite' => array('slug' => 'event')
    ), 'n');

    // Event category
    WordPress::registerTaxonomy(self::EVENT_TAXONOMY, 'Kategorie', 'Kategorien', '', array(
      'rewrite' => array('slug' => 'event-category')
    ), array(self::EVENT_TYPE));
  }

  /**
   * Add the metabox fields for an event
   */
  public function addMetaboxes()
  {
    $helper = Metabox::get(self::EVENT_TYPE);

    // Add section for the main event information
    $helper->addMetabox('event-main', 'Informationen zur Veranstaltung', 'normal');
    $helper->addEditor('post_content', 'event-main', 'Beschreibung', 10);
    $helper->addDateTime('event-start', 'event-main', 'Beginn');
    $helper->addDateTime('event-end', 'event-main', 'Ende (optional)');
    // Add optional fields
    $helper->addTextarea('post_excerpt', 'event-main', 'Kurzbeschreibung', 65);
    $helper->addInputText('event-location', 'event-main', 'Adresse / Ort');

    // Subscription features
    $helper->addMetabox('event-subscribe', 'Anmeldung zur Veranstaltung', 'normal');
    $helper->addCheckbox('subscribe-active', 'event-subscribe', 'Anmeldung per Formular ermöglichen');
    $helper->addDateTime('subscribe-end', 'event-subscribe', 'Anmeldeschluss');
    $helper->addInputText('subscribe-email', 'event-subscribe', 'E-Mail-Empfänger der Anmeldungen');
    // Create a dropdown of all forms that can be used as template
    $helper->addDropdown('subscribe-form-id', 'event-subscribe', 'Formular', array(
      'items' => $this->getFormDropdownItems(),
      'description' => 'Anmeldeforumlare können unter "Forumlare" erstellt und konfiguriert werden.'
    ));
  }

  /**
   * @return array list of forms
   */
  protected function getFormDropdownItems()
  {
    $dropdownItems = array();
    $forms = get_posts(array(
      'post_type' => FormType::FORM_SLUG,
      'orderby' => 'title',
      'order' => 'ASC',
      'posts_per_page' => -1
    ));

    // Display the forms
    foreach ($forms as $form) {
      $dropdownItems[$form->ID] = $form->post_title;
    }

    return $dropdownItems;
  }

  /**
   * Cleans up old events gracefully (incl. cache, meta, connections)
   */
  public function cleanUpEvents()
  {
    // Create an object that is the treshold for deletion
    $config = LbwpCore::getInstance()->getConfig();
    $threshold = new DateTime('now');
    $subtractor = new DateInterval('P' . $config['Events:CleanupMonths'] . 'M');
    $threshold->sub($subtractor);

    // Get events by meta date query
    $events = get_posts(array(
      'post_type' => EventType::EVENT_TYPE,
      'post_status' => 'publish',
      'posts_per_page' => -1,
      'meta_key' => 'event-start',
      'meta_query' => array(array(
        'key' => 'event-start',
        'value' => array(0, $threshold->getTimestamp()),
        'compare' => 'BETWEEN'
      ))
    ));

    // Delete found events, slower, but makes filtering possible and flushes cache
    foreach ($events as $event) {
      wp_delete_post($event->ID, true);
    }
  }
} 