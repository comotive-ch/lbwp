<?php

namespace LBWP\Module\Events\Component;

use LBWP\Module\Forms\Core as FormCore;
use LBWP\Module\Forms\Component\FormHandler;
use LBWP\Util\Date;
use LBWP\Util\String;

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
        unset($events[$id]); continue;
      } else if ($removePastEvents && $eventEnd == 0 && $eventStart <= $currentTime) {
        unset($events[$id]); continue;
      }

      // From here on, only visible events are populated
      $event->startTime = $eventStart;
      $event->endTime = $eventEnd;
      $event->location = get_post_meta($event->ID, 'event-location', true);
      $event->subscribeActive = get_post_meta($event->ID, 'subscribe-active', true) == 'on';

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
   * @param \stdClass $event the event object to be displayed
   * @param string $textdomain the text domain for labels
   * @return string html to represent the event information
   */
  public function getDataListHtml($event, $textdomain)
  {
    $config = $this->getFilteredConfiguration();
    $currentTime = current_time('timestamp');

    // Initialize data list and let developers add data
    $html = '<dl class="event-data-list">';
    $html .= apply_filters('lbwpEvents_detail_data_list_prepend', '', $event);

    // Handle the various date/time from/to combinations
    $event->endTime = intval($event->endTime);
    if ($event->startTime > 0 && $event->endTime > 0) {
      // Start and end given, first, get all the parts
      $startDate = date_i18n($config['date_format'], $event->startTime);
      $endDate = date_i18n($config['date_format'], $event->endTime);

      // If same day, display like below, but with time from/to
      if ($startDate == $endDate) {
        $html .= '
          <dt>' . __('Datum', 'lbwp') . '</dt>
          <dd>' . $startDate . '</dd>
        ';

        // Show time if given
        $hasStartTime = $this->hasTime($event->startTime);
        $hasEndTime = $this->hasTime($event->endTime);
        if ($hasStartTime && $hasEndTime) {
          $startTime = date_i18n($config['time_format'], $event->startTime);
          $endTime = date_i18n($config['time_format'], $event->endTime);
          $html .= '
            <dt>' . __('Uhrzeit', 'lbwp') . '</dt>
            <dd>' . sprintf(__('Von %s bis %s', 'lbwp'), $startTime, $endTime). '</dd>
          ';
        } else if ($hasStartTime && !$hasEndTime) {
          $html .= '
            <dt>' . __('Uhrzeit', 'lbwp') . '</dt>
            <dd>' . sprintf(__('%s', $textdomain), date_i18n($config['time_format'], $event->startTime)) . '</dd>
          ';
        }
        //
      } else {
        // Not equal, display start and end with possible time
        $html .= '
          <dt>' . __('Beginn', 'lbwp') . '</dt>
          <dd>' . $this->getDateTimeString($event->startTime, $config, $textdomain) . '</dd>
          <dt>' . __('Ende', 'lbwp') . '</dt>
          <dd>' . $this->getDateTimeString($event->endTime, $config, $textdomain) . '</dd>
        ';
      }

    } else if ($event->startTime > 0 && $event->endTime == 0) {
      // Only a start date is given, show the date
      $html .= '
        <dt>' . __('Datum', 'lbwp') . '</dt>
        <dd>' . date_i18n($config['date_format'], $event->startTime) . '</dd>
      ';

      // If time given, show the time
      if ($this->hasTime($event->startTime)) {
        $html .= '
          <dt>' . __('Uhrzeit', 'lbwp') . '</dt>
          <dd>' . sprintf(__('%s', $textdomain), date_i18n($config['time_format'], $event->startTime)) . '</dd>
        ';
      }
    }

    // Add location, if available
    if (strlen($event->location) > 0) {
      $html .= '
        <dt>' . __('Ort', 'lbwp') . '</dt>
        <dd>' . $event->location . '</dd>
      ';
    }

    // Add event subcribe info, if available
    if ($this->hasEventSubscription($event, $currentTime)) {
      $html .= '
        <dt>' . __('Anmeldung bis', 'lbwp') . '</dt>
        <dd>' . $this->getDateTimeString($event->subscribeEnd, $config, $textdomain) . '</dd>
      ';
    }

    // Once again, let developer add data, close list and return
    $html .= apply_filters('lbwpEvents_detail_data_list_append', '', $event);
    $html .= '</dl>';

    return $html;
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
    add_filter('lbwpForms_load_form_shortcode', function($shortcode, $form) use ($event) {
      if (!String::checkEmail($event->subscribeEmail)) {
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
          __('Anmeldung: %s / %s','lbwp'),
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
   * @param \stdClass $event the event object
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