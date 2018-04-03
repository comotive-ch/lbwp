<?php

namespace LBWP\Module\Events\Component;

use LBWP\Module\Forms\Core as FormCore;
use LBWP\Module\Forms\Component\FormHandler;
use LBWP\Util\Date;
use LBWP\Util\Strings;

/**
 * This provides various helper functions for the frontend, as
 * templating, queries and string helpers
 * @package LBWP\Module\Events\Component
 * @author Michael Sebel <michael@comotive.ch>
 */
class Frontend extends Base
{
  /**
   * @var array true/false, if value is given
   */
  protected $cachedHasFutureEvents = array();

  /**
   * Called at init(50)
   */
  public function initialize()
  {
    // Register JSON LD output for posts
    add_action('wp_head_single_' . EventType::EVENT_TYPE, array('\LBWP\Helper\Tracking\MicroData', 'printEventData'));
    // Download ICS file, if needed
    if (isset($_GET['download']) && $_GET['download'] == 'ics') {
      add_action('wp', array($this, 'downloadCalendarFile'));
    }
  }

  /**
   * Use a configuration from Shortcode or your own.
   * The query needs at least from/to timestamps and optionally
   * an array of taxonomies and according term slugs
   * @param array $config from Shortcode class
   * @return \WP_Post[] list of events
   */
  public function queryEvents($config)
  {
    $query = array(
      'post_type' => EventType::EVENT_TYPE,
      'post_status' => 'publish',
      'posts_per_page' => $config['max_events'],
      'orderby' => 'meta_value_num',
      'order' => 'ASC',
      'meta_key' => 'event-start'
    );

    // Add a meta query for from/to queriny
    $query['meta_query'] = array(array(
      'key' => 'event-start',
      'value' => array($config['from'], $config['to']),
      'compare' => 'BETWEEN'
    ));

    // Add taxonomy/term queries, if needed
    if (
      is_array($config['taxonomies']) && count($config['taxonomies']) > 0 &&
      is_array($config['terms']) && count($config['terms']) > 0
    ) {
      // Initialize, that there is a tax query
      $query['tax_query'] = array();

      // Now add all the taxonomy queries with the configured slugs
      foreach ($config['taxonomies'] as $taxonomy) {
        if (count($config['terms'][$taxonomy]) > 0) {
          $query['tax_query'][] = array(
            'taxonomy' => $taxonomy,
            'field' => 'slug',
            'terms' => $config['terms'][$taxonomy]
          );
        }
      }

      // If there is more than one query, add the relation
      if (count($query['tax_query']) > 1) {
        $query['tax_query']['relation'] = $config['taxonomy_relation'];
      }
    }

    // Fire up the sql
    return get_posts($query);
  }

  /**
   * @return array list of untimed events
   */
  public function getUntimedEvents()
  {
    $query = array(
      'post_type' => EventType::EVENT_TYPE,
      'post_status' => 'publish',
      'posts_per_page' => -1,
      'order' => 'ASC',
      'meta_query' => array(array(
        'key' => 'event-start',
        'compare' => 'NOT EXISTS'
      ))
    );

    // Add a meta query for from/to queriny
    return get_posts($query);
  }

  /**
   * @param array $config the query config
   * @param int $year the year to look at (only that is supported right now
   * @return bool true, if there are future events (only year+1 supported)
   */
  public function hasFutureEvents($config, $year)
  {
    if (!isset($this->cachedHasFutureEvents[$year])) {
      // Calculate new from/to with the year given
      $config['from'] = mktime(0, 0, 0, 1, 1, $year);
      $config['to'] = mktime(23, 59, 59, 12, 31, $year);
      $this->cachedHasFutureEvents[$year] = count($this->queryEvents($config)) > 0;
    }

    return $this->cachedHasFutureEvents[$year];
  }

  /**
   * @param array $events the events array
   * @param array $config the event query config
   * @param int $currentTime the current time (to simulate something else, in case)
   */
  public function populateEventData(&$events, $config, $currentTime)
  {
    $removePastEvents = ($config['display_past_events'] == 0);
    // Go through all events and populate their basic data
    foreach ($events as $id => $event) {
      $eventStart = intval(get_post_meta($event->ID, 'event-start', true));
      $eventEnd = intval(get_post_meta($event->ID, 'event-end', true));

      // Remove a past event, if past events should be removed
      if ($removePastEvents && $eventEnd > 0 && $eventEnd <= $currentTime) {
        unset($events[$id]);
        continue;
      } else if ($removePastEvents && $eventEnd == 0 && $eventStart <= $currentTime) {
        unset($events[$id]);
        continue;
      }

      // From here on, only visible events are populated
      $event->startTime = $eventStart;
      $event->endTime = $eventEnd;
      $event->location = get_post_meta($event->ID, 'event-location', true);
      $event->address = get_post_meta($event->ID, 'event-address', true);
      $event->subscribeActive = get_post_meta($event->ID, 'subscribe-active', true) == 'on';
      $event->subscribeAltText = get_post_meta($event->ID, 'subscribe-end-alternate-text', true);

      // Add subscribe data, if active
      if ($event->subscribeActive) {
        $event->subscribeEnd = intval(get_post_meta($event->ID, 'subscribe-end', true));
        $event->subscribeEmail = get_post_meta($event->ID, 'subscribe-email', true);
        $event->subscribeFormId = intval(get_post_meta($event->ID, 'subscribe-form-id', true));
        // If there is no subscribe end, use start date as end
        if ($event->subscribeEnd == 0) {
          $event->subscribeEnd = $event->startTime;
        }
      }

      // Let developers add their own data or override data
      $event = apply_filters('lbwpEvents_populate_event_data', $event, $config, $currentTime);

      // See if the developer decided to remove the event, and remove it, if so
      if ($event == NULL || $event == false) {
        unset($events[$id]);
      } else {
        $events[$id] = $event;
      }
    }
  }

  /**
   * @return \stdClass the current event with populated data
   */
  public function getQueriedEvent()
  {
    global $post;
    // Populate them datas, yo
    $events = array($post);
    $this->populateEventData($events, array('display_past_events' => 1), current_time('timestamp'));
    return $events[0];
  }

  /**
   * @param \WP_Post $event the event object to be displayed
   * @param string $textdomain the text domain for labels
   * @param array $display display configuration
   * @param array $override for the $config array
   * @return string html to represent the event information
   */
  public function getDataListHtml($event, $textdomain = 'lbwp', $display = array(), $override = array())
  {
    $config = $this->getFilteredConfiguration();
    $config = array_merge($config, $override);
    $currentTime = current_time('timestamp');
    // Merge display configuration with defaults
    $display = array_merge(array(
      'showDates' => true,
      'showSubscriptionInfo' => true,
      'showLocation' => true,
      'showAddressAfterLocation' => true,
      'showCalendarDownload' => false
    ), $display);

    // Initialize data list and let developers add data
    $html = '<div class="event-data-list">';
    $html .= apply_filters('lbwpEvents_detail_data_list_prepend', '', $event);

    // Handle the various date/time from/to combinations
    $event->endTime = intval($event->endTime);
    if ($event->startTime > 0 && $event->endTime > 0 && $display['showDates']) {
      // Start and end given, first, get all the parts
      $startDate = date_i18n($config['date_format'], $event->startTime);
      $endDate = date_i18n($config['date_format'], $event->endTime);


      // If same day, display like below, but with time from/to
      if ($startDate == $endDate) {
        $html .= '
          <dl>
            <dt>' . __('Datum', 'lbwp') . '</dt>
            <dd>' . $startDate . '</dd>
          </dl>
        ';

        // Show time if given
        $hasStartTime = $this->hasTime($event->startTime);
        $hasEndTime = $this->hasTime($event->endTime);
        if ($hasStartTime && $hasEndTime) {
          $startTime = date_i18n($config['time_format'], $event->startTime);
          $endTime = date_i18n($config['time_format'], $event->endTime);
          $html .= '
            <dl>
              <dt>' . __('Uhrzeit', 'lbwp') . '</dt>
              <dd>' . sprintf(__('Von %s bis %s', 'lbwp'), $startTime, $endTime) . '</dd>
            </dl>
          ';
        } else if ($hasStartTime && !$hasEndTime) {
          $html .= '
            <dl>
              <dt>' . __('Uhrzeit', 'lbwp') . '</dt>
              <dd>' . sprintf(__('%s', $textdomain), date_i18n($config['time_format'], $event->startTime)) . '</dd>
            </dl>
          ';
        }
        //
      } else {
        // Not equal, display start and end with possible time
        $html .= '
          <dl>
            <dt>' . __('Beginn', 'lbwp') . '</dt>
            <dd>' . $this->getDateTimeString($event->startTime, $config, $textdomain) . '</dd>
          </dl>
          <dl>
            <dt>' . __('Ende', 'lbwp') . '</dt>
            <dd>' . $this->getDateTimeString($event->endTime, $config, $textdomain) . '</dd>
          </dl>
        ';
      }

    } else if ($event->startTime > 0 && $event->endTime == 0 && $display['showDates']) {
      // Only a start date is given, show the date
      $html .= '
        <dl>
          <dt>' . __('Datum', 'lbwp') . '</dt>
          <dd>' . date_i18n($config['date_format'], $event->startTime) . '</dd>
        </dl>
      ';

      // If time given, show the time
      if ($this->hasTime($event->startTime)) {
        $html .= '
          <dl>
            <dt>' . __('Uhrzeit', 'lbwp') . '</dt>
            <dd>' . sprintf(__('%s', $textdomain), date_i18n($config['time_format'], $event->startTime)) . '</dd>
          </dl>
        ';
      }
    }

    // Add event subcribe info, if available
    if ($this->hasEventSubscription($event, $currentTime) && $display['showSubscriptionInfo']) {
      $html .= '
        <dl>
          <dt>' . __('Anmeldung bis', 'lbwp') . '</dt>
          <dd>' . $this->getDateTimeString($event->subscribeEnd, $config, $textdomain) . '</dd>
        </dl>
      ';
    } else if ($event->subscribeActive && strlen($event->subscribeAltText) > 0) {
      $html .= '
        <dl>
          <dt>' . __('Anmeldeinformation', 'lbwp') . '</dt>
          <dd>' . $event->subscribeAltText . '</dd>
        </dl>
      ';
    }

    // Add location, if available
    if ($display['showLocation']) {
      if ($display['showAddressAfterLocation'] && is_array($event->address)) {
        $html .= '
          <dl>
            <dt>' . __('Ort', 'lbwp') . '</dt>
            <dd>' . self::getCombinedLocationAndAddress($event) . '</dd>
          </dl>
        ';
      } else if (strlen($event->location) > 0) {
        $html .= '
          <dl>
            <dt>' . __('Ort', 'lbwp') . '</dt>
            <dd>' . $event->location . '</dd>
          </dl>
        ';
      }
    }

    // Display a calendar file download for outlook etc.
    if ($display['showCalendarDownload'] && $event->startTime > 0) {
      $html .= '
        <dl>
          <dt>' . __('Download', 'lbwp') . '</dt>
          <dd>' . $this->getCalendarFileDownloadLink($event->ID, __('Termin im Kalender speichern', 'lbwp')) . '</dd>
        </dl>
      ';
    }

    // Once again, let developer add data, close list and return
    $html .= apply_filters('lbwpEvents_detail_data_list_append', '', $event);
    $html .= '</div>';

    return $html;
  }

  /**
   * @param int $eventId the event id to download
   * @param string $text the text for the link
   * @return string
   */
  protected function getCalendarFileDownloadLink($eventId, $text)
  {
    return '
      <a href="' . get_permalink($eventId) . '?download=ics" target="_blank">' . $text . '</a>
    ';
  }

  /**
   * Returns a combined strong of location and the address array
   * @param \WP_Post $event the event object and its data
   * @return string a human readable string of the location
   */
  public static function getCombinedLocationAndAddress($event)
  {
    // Only return location, if address isn't given at all
    if (!isset($event->address) || !is_array($event->address)) {
      return $event->location;
    }

    $parts = array($event->location);
    if (strlen($event->address['street']) > 0) {
      $parts[] = $event->address['street'];
    }
    if (strlen($event->address['zip']) > 0 || strlen($event->address['city']) > 0) {
      $parts[] = trim($event->address['zip'] . ' ' . $event->address['city']);
    }
    if (strlen($event->address['addition']) > 0) {
      $parts[] = $event->address['addition'];
    }

    return implode(', ', array_filter($parts));
  }

  /**
   * Downloads an ICS calendar file suiteable for all apps or outlook
   */
  public function downloadCalendarFile()
  {
    $event = $this->getQueriedEvent();
    // Continue, if there is valid data
    if ($event->ID > 0 && isset($event->startTime) && $event->startTime > 0) {
      // Print the needed mime header
      header('Content-Type: text/calendar');
      // Print the calendar minimal output
      echo 'BEGIN:VCALENDAR' . PHP_EOL;
      echo 'VERSION:2.0' . PHP_EOL;
      echo 'PRODID:' . get_bloginfo('url') . PHP_EOL;
      echo 'METHOD:PUBLISH' . PHP_EOL;
      echo 'BEGIN:VEVENT' . PHP_EOL;
      // TODO This actually sends a invitation which we don't want until it's configurable
      //echo 'UID:' . $email . PHP_EOL;
      //echo 'ORGANIZER;CN="' . get_bloginfo('name') . '":MAILTO:' . $email . PHP_EOL;
      echo 'LOCATION:' . self::getCombinedLocationAndAddress($event) . PHP_EOL;
      echo 'SUMMARY:' . trim(preg_replace('/\s\s+/', ' ', $event->post_title)) . PHP_EOL;
      echo 'DESCRIPTION:' . strip_tags(trim(preg_replace('/\s\s+/', ' ', $event->post_content))) . PHP_EOL;
      echo 'CLASS:PUBLIC' . PHP_EOL;
      echo 'DTSTART:' . date(Date::ICS_DATE, $event->startTime) . PHP_EOL;
      if (isset($event->endTime) && $event->endTime > 0) {
        echo 'DTEND:' . date(Date::ICS_DATE, $event->endTime) . PHP_EOL;
      }
      echo 'END:VEVENT' . PHP_EOL;
      echo 'END:VCALENDAR';
      exit;
    }
  }

  /**
   * @param \stdClass $event the event object
   * @return string html to represent the form
   */
  public function getEventSubscriptionHtml($event)
  {
    $html = apply_filters('lbwpEvents_detail_event_form_initial', '');
    /** @var FormHandler $formHandler */
    $formHandler = FormCore::getInstance()->getFormHandler();
    $shortcode = get_post($event->subscribeFormId)->post_content;

    // Bolster up the shortcode, on loading so everything is correctly handled
    add_filter('lbwpForms_load_form_shortcode', function ($shortcode, $form) use ($event) {
      // Check if there is a skipping var, to omit the automatic email
      if (isset($event->skipAutomaticSubscribeEmail) && $event->skipAutomaticSubscribeEmail) {
        return $shortcode;
      }
      // Check if there is a subscribe email and the form is actually from the event
      if (!Strings::checkEmail($event->subscribeEmail) || $form->ID != $event->subscribeFormId) {
        return $shortcode;
      }

      /** @var FormHandler $formHandler */
      $formHandler = FormCore::getInstance()->getFormHandler();

      // Generate hidden field shortcodes and add them to the shortcode
      $elements = array();
      foreach ($this->getAdditionalFieldConfig($event) as $field) {
        $elements[] = $formHandler->generateFieldItem($field);
      }

      // Add the mail action to inform the admins
      $elements[] = $formHandler->generateActionItem(array(
        'key' => 'sendmail',
        'email' => $event->subscribeEmail,
        'betreff' => sprintf(
          __('Anmeldung: %s / %s', 'lbwp'),
          $event->post_title,
          Date::getTime(Date::EU_DATETIME, $event->startTime)
        )
      ));

      // Add the generated fields and execute form generation
      return $formHandler->addElementsToShortcode($shortcode, $elements);
    }, 10, 2);

    // Check if the form is valid and return $html at this point, if not
    if (!$formHandler->isValidForm($shortcode)) {
      return $html;
    }

    // Append the form to html
    $html .= $formHandler->loadForm(array('id' => $event->subscribeFormId));

    return $html;
  }

  /**
   * @param \stdClass $event the event object
   * @return array the field config to use in form handler
   */
  protected function getAdditionalFieldConfig($event)
  {
    $fieldKey = 'hiddenfield';
    $fields = array();

    // First, add the events name
    $fields[] = array(
      'key' => $fieldKey,
      'feldname' => __('Veranstaltung', 'lbwp'),
      'vorgabewert' => $event->post_title
    );

    // Add the start time
    $fields[] = array(
      'key' => $fieldKey,
      'feldname' => __('Datum/Zeit', 'lbwp'),
      'vorgabewert' => Date::getTime(Date::EU_DATETIME, $event->startTime)
    );

    // Add the location if given
    if (strlen($event->location) > 0) {
      $fields[] = array(
        'key' => $fieldKey,
        'feldname' => __('Ort', 'lbwp'),
        'vorgabewert' => $event->location
      );
    }

    return apply_filters('lbwpEvents_detail_event_form_additional_fields', $fields);
  }

  /**
   * @param \WP_Post $event the event object
   * @param int $currentTime the current time
   * @return bool true, if the event can be subscribed to
   */
  public function hasEventSubscription($event, $currentTime = 0)
  {
    if ($currentTime == 0) {
      $currentTime = current_time('timestamp');
    }

    return apply_filters(
      'lbwpEvents_has_event_subscription',
      $event->subscribeActive && $event->subscribeEnd > 0 && $event->subscribeEnd > $currentTime,
      $event
    );
  }

  /**
   * @param int $timestamp the timestamp
   * @param array $config the config with format options
   * @param string $textdomain the textdomain to be used if date time string
   * @return string human readable date or datetime, if time is given (not 00:00)
   */
  public function getDateTimeString($timestamp, $config, $textdomain)
  {
    if (!$this->hasTime($timestamp)) {
      // No time, just the date
      return date_i18n($config['date_format'], $timestamp);
    } else {
      // Time and date
      return sprintf(
        __('%s, %s', 'lbwp'),
        date_i18n($config['date_format'], $timestamp),
        date_i18n($config['time_format'], $timestamp)
      );
    }
  }

  /**
   * @param int $timestamp the analyzed timestamp
   * @return bool true, if there is a time in this timestamp (instead of 00:00)
   */
  public function hasTime($timestamp)
  {
    return !($timestamp % 86400 == 0);
  }

  /**
   * @return array the filtered configuration
   */
  public function getFilteredConfiguration()
  {
    // Merge defaults with given parameters and let them be filterable
    return shortcode_atts(
      array(
        'date_format' => get_option('date_format'),
        'time_format' => get_option('time_format'),
      ),
      array(),
      Shortcode::SHORTCODE_SLUG
    );
  }
} 