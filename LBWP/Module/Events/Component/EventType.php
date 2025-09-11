<?php

namespace LBWP\Module\Events\Component;

use LBWP\Core as LbwpCore;
use LBWP\Helper\Metabox;
use LBWP\Helper\WooCommerce\Util;
use LBWP\Util\ArrayManipulation;
use LBWP\Util\Date;
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
   * @var string Overrideable vars for the type itself
   */
  public static $singular = 'Event';
  public static $plural = 'Events';
  public static $letter = 'n';
  public static $rewrite = 'event';
  public static $defaultCategory = true;

  /**
   * Called at init(50)
   */
  public function initialize()
  {
    $this->registerCustomType();
    // Add metaboxes
    add_action('admin_init', array($this, 'addMetaboxes'));
    add_action('manage_' . self::EVENT_TYPE . '_posts_custom_column', array($this, 'showModifiedDate'),	10,	2);
    add_filter('manage_' . self::EVENT_TYPE . '_posts_columns', array($this, 'addModifiedDateField'));
    add_filter('manage_edit-' . self::EVENT_TYPE . '_sortable_columns', array($this, 'addModifiedSortable'));

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
    WordPress::registerType(self::EVENT_TYPE, self::$singular, self::$plural, array(
      'menu_position' => 22,
      'menu_icon' => 'dashicons-calendar-alt',
      'show_in_rest' => true,
      'supports' => array('editor', 'title', 'thumbnail', 'author'),
      'rewrite' => array('slug' => self::$rewrite)
    ), self::$letter);

    // Add more cols
    WordPress::addPostTableColumn(array(
      'post_type' => self::EVENT_TYPE,
      'meta_key' => 'event-start',
      'column_key' => self::EVENT_TYPE . '_event_start',
      'single' => true,
      'sortable' => 'meta:event-start',
      'heading' => 'Beginn',
      'callback' => array($this, 'displayDateColumn')
    ));
    WordPress::addPostTableColumn(array(
      'post_type' => self::EVENT_TYPE,
      'meta_key' => 'event-end',
      'column_key' => self::EVENT_TYPE . '_event_end',
      'single' => true,
      'sortable' => 'meta:event-end',
      'heading' => 'Ende',
      'callback' => array($this, 'displayDateColumn')
    ));

    // Event category
    if (self::$defaultCategory) {
      WordPress::registerTaxonomy(self::EVENT_TAXONOMY, 'Kategorie', 'Kategorien', '', array(
        'rewrite' => array('slug' => 'event-category'),
        'show_in_rest' => true
      ), array(self::EVENT_TYPE));

      // Register filters to be used upon
      WordPress::restrictPostTable(array(
        'type' => self::EVENT_TYPE,
        'taxonomy' => self::EVENT_TAXONOMY,
        'all_label' => __('Alle Kategorien', 'lbwp'),
        'hide_empty' => false,
        'show_count' => true,
        'orderby' => 'name',
      ));
    }
  }

  /**
   * @param string $value
   * @param int $postId
   * @return void
   */
  public function displayDateColumn($value, $postId)
  {
    if ($value > 0) {
      echo Date::getTime('d.m.Y, H:i', $value);
    }
  }

  /**
   * Add the metabox fields for an event
   */
  public function addMetaboxes()
  {
    $helper = Metabox::get(self::EVENT_TYPE);

    // Add section for the main event information
    $helper->addMetabox('event-main', 'Informationen zur Veranstaltung', 'normal');
    //$helper->addEditor('post_content', 'event-main', 'Beschreibung', 10);
    $helper->addDateTime('event-start', 'event-main', 'Beginn');
    $helper->addDateTime('event-end', 'event-main', 'Ende (optional)', Date::EU_DATE, array(
      'default_date_from' => 'event-start'
    ));
    // Add optional fields
    $helper->addTextarea('post_excerpt', 'event-main', 'Kurzbeschreibung', 65);
    $helper->addInputText('event-location', 'event-main', 'Veranstaltungsort');
    $helper->addAddressLocation('event-address', 'event-main');

    // Subscription features
    $helper->addMetabox('event-subscribe', 'Anmeldung zur Veranstaltung', 'normal');
    $helper->addCheckbox('subscribe-active', 'event-subscribe', 'Anmeldeoptionen', array(
      'description' => 'Anmeldung per Formular ermöglichen'
    ));
    $helper->addCheckbox('subscribe-linkonly', 'event-subscribe', '', array(
      'description' => 'Anmeldung nur auf Einladung per E-Mail möglich'
    ));
    $helper->addDateTime('subscribe-end', 'event-subscribe', 'Anmeldeschluss', Date::EU_DATE, array(
      'default_date_from' => 'event-start'
    ));
    $helper->addInputText('subscribe-email', 'event-subscribe', 'E-Mail-Empfänger der Anmeldungen', array(
      'description' => '
        Es wird automatisch eine E-Mail an sie, den Veranstalter geschickt. Die E-Mail enthält alle Daten zum Event sowie die Formular-Daten.<br>
        Wenn Sie eine Bestätigung an den Teilnehmer senden wollen, kann dies als Aktion im verknüpften Anmeldeformular hinterlegt werden.
      '
    ));
    // Create a dropdown of all forms that can be used as template
    $helper->addDropdown('subscribe-form-id', 'event-subscribe', 'Anmeldeformular', array(
      'items' => $this->getFormDropdownItems(),
      'description' => 'Anmeldeforumlare können unter "Forumlare" erstellt und konfiguriert werden.'
    ));
    // Subscribe text, if subscription end is reached
    $helper->addTextarea('subscribe-end-alternate-text', 'event-subscribe', 'Text nach Anmeldeschluss', 65, array(
      'description' => 'Text, der angezeigt wird, wenn der Anmeldeschluss abgelaufen ist (Leer lassen, wenn kein Text angezeigt werden soll).'
    ));

    // Make it possible to connect with woocommerce
    if (Util::isWoocommerceActive()) {
      $helper->addMetabox('event-purchase', 'Kostenpflichtige Anmeldung', 'normal');
      $helper->addCheckbox('ticketing-active', 'event-purchase', 'Kauf eines Tickets aktivieren');
      $helper->addPostTypeDropdown('product-id', 'event-purchase', 'Produkt', 'product', array(
        'hide_new_item_box' => true,
        'items' => array(0 => 'Bitte wählen')
      ));
    }

    $helper->addMetabox('event-links', 'Weiterführende Links', 'normal');
    $helper->addInputText('event-map-url', 'event-links', 'Link 1', array(
      'description' => 'Optionaler Link um z.B. eine Kartenansicht auf Google Maps zu verlinken'
    ));
    $helper->addInputText('event-map-label', 'event-links', 'Link Beschriftung', array(
      'description' => 'Beschriftung des Links (Standardmässig "Karte")'
    ));
    $helper->addInputText('event-map-link-text', 'event-links', 'Link Text', array(
      'description' => 'Verlinkter Text (Standardmässig "Ort auf der Karte anzeigen")'
    ));
    // Additiona links
    foreach (array(1,2,3) as $index) {
      $helper->addLine('event-links');
      $helper->addInputText('event-link-' . $index, 'event-links', 'Link ' . ($index+1));
      $helper->addInputText('event-link-label-' . $index, 'event-links', 'Link Beschriftung');
      $helper->addInputText('event-link-text-' . $index, 'event-links', 'Link Text');
    }

    // Features to deactivate display of elements
    $helper->addMetabox('event-hiders', 'Steuerung der Darstellung', 'normal');
    $helper->addCheckbox('hide-ics-download', 'event-hiders', 'Kalender-Download ausblenden');
    $helper->addCheckbox('hide-map-url', 'event-hiders', 'Weiterführende Links ausblenden');
    $helper->addCheckbox('hide-date', 'event-hiders', 'Datum und Uhrzeit ausblenden');
    $helper->addCheckbox('hide-image', 'event-hiders', 'Beitragsbild nicht anzeigen');
    $helper->addCheckbox('hide-subscribe-title', 'event-hiders', 'Titel "Anmeldung" nicht anzeigen');
    $helper->addCheckbox('hide-subscribe-info', 'event-hiders', 'Anmeldeschluss-Informationen nicht anzeigen');
  }

  /**
   * @param array $columns the columns array
   * @return array $columns with one more field
   */
  public function addModifiedDateField($columns)
  {
    $columns['modified'] = __('Letzte Änderung', 'lbwp');
    return $columns;
  }

  /**
   * @param array $columns the columns array
   * @return array $columns with one more field
   */
  public function addModifiedSortable($columns)
  {
    $columns['modified'] = 'post_modified';
    return $columns;
  }

  /**
   * @param string $column the col name
   * @param int $postId the working post
   */
  public function showModifiedDate($column, $postId)
  {
    switch ($column) {
      case 'modified':
        $mOrig = get_post_field('post_modified', $postId, 'raw');
        echo '
          <p class="mod-date">
            ' . date('d.m.Y, H:i', strtotime($mOrig)) . '
          </p>
        ';
    }
  }

  /**
   * @return array list of forms
   */
  protected function getFormDropdownItems()
  {
    $dropdownItems = array(0 => 'Bitte wählen');
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

  /**
   * @param int $eventId the id of the event
   * @param string $id unique id for the data set
   * @param array $data the data set to write minimum: email, filled, subscribed, subscribers
   * @param bool $override if set to true, override with id is forced
   */
  public static function setSubscribeInfo($eventId, $id, $data, $override = false)
  {
    $info = ArrayManipulation::forceArray(get_post_meta($eventId, 'subscribeInfo', true));

    if (isset($info[$id])) {
      $record = $info[$id];
      // See if not yet filled
      if (!isset($record['filled']) || !$record['filled'] || $override) {
        // Merge and save as same data set, but filled
        $record = array_merge($record, $data);
        // Only set filled, if not given in $data
        if (!$override || !isset($data['filled'])) {
          $record['filled'] = true;
        }
        $info[$id] = $record;
      } else {
        // Set is already existing and filled, create a new dataset and mark as filled
        $id .= '-' . uniqid('subd', true);
        $info[$id] = $data;
        $info[$id]['filled'] = true;
      }
    } else {
      // New data set, take as given
      $info[$id] = $data;
    }

    update_post_meta($eventId, 'subscribeInfo', $info);
  }

  /**
   * More simple function than set: Add info if not existing or discard
   * @param int $eventId the id of the event
   * @param string $id unique id for the data set
   * @param array $data the data set to write minimum: email, filled, subscribed, subscribers
   */
  public static function addSubscribeInfo($eventId, $id, $data)
  {
    $info = ArrayManipulation::forceArray(get_post_meta($eventId, 'subscribeInfo', true));

    if (!isset($info[$id])) {
      $info[$id] = $data;
    }

    update_post_meta($eventId, 'subscribeInfo', $info);
  }

  /**
   * @param int $eventId the id of the event
   * @param string $id subscriber id
   */
  public static function removeSubscribeInfo($eventId, $id)
  {
    $info = ArrayManipulation::forceArray(get_post_meta($eventId, 'subscribeInfo', true));
    unset($info[$id]);
    update_post_meta($eventId, 'subscribeInfo', $info);
  }

  /**
   * @param int $eventId the id of the event
   */
  public static function flushSubscribeInfo($eventId)
  {
    update_post_meta($eventId, 'subscribeInfo', array());
  }

  /**
   * @param int $eventId the id of the event
   * @return array the subscribe infos
   */
  public static function getSubscribeInfo($eventId)
  {
    return ArrayManipulation::forceArray(get_post_meta($eventId, 'subscribeInfo', true));
  }
} 