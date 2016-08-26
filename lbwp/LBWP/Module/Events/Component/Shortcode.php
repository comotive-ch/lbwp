<?php

namespace LBWP\Module\Events\Component;

use LBWP\Util\ArrayManipulation;
use LBWP\Util\Strings;

/**
 * This class contains all the logic for the event listing shortocde.
 * Most of the actual queries are in the Frontend framework class.
 * @package LBWP\Module\Events\Component
 * @author Michael Sebel <michael@comotive.ch>
 */
class Shortcode extends Base
{
  /**
   * @var string the shortcode definition
   */
  const SHORTCODE_SLUG = 'lbwp:eventlist';
  /**
   * @var Frontend reference to the frontend component
   */
  protected $frontend = NULL;
  /**
   * @var int the current time of the request
   */
  protected $currentTime = 0;
  /**
   * @var int the current year
   */
  protected $currentYear = 0;
  /**
   * @var string if used, will default to "lbwp"
   */
  protected $textdomain = '';
  /**
   * @var array makes some shortcode params overrideable by post/get
   */
  protected $getParamMatch = array(
    'to' => 'to',
    'from' => 'from',
    'y' => 'year',
    'm' => 'month',
    'dpe' => 'display_past_events'
  );
  /**
   * @var array configs that need to be integers
   */
  protected $integerConfigs = array(
    'display_navigation',
    'display_taxonomy_filters',
    'display_past_events',
    'display_past_events_filter',
    'display_past_years',
    'year',
    'month'
  );
  /**
   * @var array the templates used for the shortcode
   */
  protected $templates = array();

  /**
   * Called at init(50)
   */
  public function initialize()
  {
    $this->frontend = $this->core->getFrontendComponent();
    $this->currentTime = current_time('timestamp');
    $this->currentYear = date('Y', $this->currentTime);
    add_shortcode(self::SHORTCODE_SLUG, array($this, 'getEventListHtml'));
    add_action('wp', array($this, 'enqueueAssets'));
  }

  /**
   * @param array $args the shortcode arguments
   * @return array filtered arguments with defaults
   */
  protected function mergeConfigurationDefaults($args)
  {
    // Merge defaults with given parameters and let them be filterable
    return shortcode_atts(
      array(
        // Basic settings of showing/not showing events
        'display_navigation' => 1,
        'display_taxonomy_filters' => 1,
        'display_past_events' => 0,
        'display_past_events_filter' => 0,
        'display_past_years' => 0,
        'max_events' => -1,
        'date_format' => get_option('date_format'),
        'time_format' => get_option('time_format'),
        // Initialize taxonomy query defaults
        'taxonomy_relation' => 'AND',
        'taxonomies' => array(EventType::EVENT_TAXONOMY),
        'terms' => array(),
        // Query configurations, defaults to current year, if not otherwise overriden later
        'from' => '',
        'to' => '',
        'year' => $this->currentYear,
        // 0 means, no specific month in the year
        'month' => 0
      ),
      $args,
      self::SHORTCODE_SLUG
    );
  }

  /**
   * Get Html of an event list by arguments. Can be used as a shortcode, but
   * also as a developer function, since it's publicly accessible
   * @param array $args the shortcode arguments
   * @param string $html html content to be on to, if given
   * @return string html code to display the events
   */
  public function getEventListHtml($args, $html = '')
  {
    // First get the configuration for displaying
    $config = $this->getListConfiguration($args);
    // Now actually get the events with that
    $events = $this->frontend->queryEvents($config);
    $this->frontend->populateEventData($events, $config, $this->currentTime);

    // If there are no events, have alook at the future, and redirect there if it has events
    if (count($events) == 0 && $config['year'] == $this->currentYear && $this->frontend->hasFutureEvents($config, $this->currentYear + 1)) {
      $link = $this->getListingLink($config, array('year' => $this->currentYear + 1));
      header('Location: ' . $link, null, 302);
      exit;
    }

    // Generate templates and let developers override them
    $this->textdomain = apply_filters('lbwpEvents_template_text_domain', 'lbwp');
    $this->generateBasicTemplates($config);
    $this->templates = apply_filters('lbwpEvents_default_templates', $this->templates, $config);

    // Generate output, if the developer doesn't do their own thing
    if (!apply_filters('lbwpEvents_list_shortcode_override', false)) {
      // Add container and replace filters and navigation
      $html .= $this->templates['container'];
      $html = $this->handleFilters($html, $config);
      $html = $this->addNavigation($html, $config);
      // Finally, ddd the events
      $html = $this->addEvents($html, $events, $config);
    }

    // For the last time, let the developer do their own thing
    return apply_filters('lbwpEvents_list_shortcode_html', $html, $config, $events, $this->templates);
  }

  /**
   * Handles the various filters and displays their default
   * @param string $html html template
   * @param array $config the event list config
   * @return string the changed html template
   */
  protected function handleFilters($html, $config)
  {
    // Taxonomy filter
    if ($config['display_taxonomy_filters'] == 1) {
      $template = $this->templates['taxonomy-filter'];
      // Create dropdowns that are preselected for every taxonomy
      foreach ($config['taxonomies'] as $taxonomy) {
        $template = str_replace(
          '{dropdown:' . $taxonomy . '}',
          $this->getTaxonomyDropdownHtml($taxonomy, $config['terms'][$taxonomy]),
          $template
        );
      }

      // Put the template into the html
      $html = str_replace('{taxonomy-filter}', $template, $html);
    } else {
      $html = str_replace('{taxonomy-filter}', '', $html);
    }

    // Past events filter
    if ($config['display_past_events_filter'] == 1) {
      $checked = checked(1, $config['display_past_events'], false);
      $checkbox = '<input type="checkbox" value="1" name="dpe" class="event-autosubmit" id="dpe-filter"' . $checked . ' />';
      $template = str_replace('{checkbox}', $checkbox, $this->templates['past-events-filter']);
      // Directly put the template into the html
      $html = str_replace('{past-events-filter}', $template, $html);
    } else {
      $template = '<div class="past-events-filter empty"></div>';
      $html = str_replace('{past-events-filter}', $template, $html);
    }

    return $html;
  }

  /**
   * @param string $taxonomy the taxonomy
   * @param array $terms the selected terms
   * @return string html code for the dropdown
   */
  protected function getTaxonomyDropdownHtml($taxonomy, $terms)
  {
    $html = '<select name="terms[' . $taxonomy . '][]" class="event-autosubmit filter-' . $taxonomy . '">';
    $terms = ArrayManipulation::forceArray($terms);
    $allTerms = get_terms($taxonomy, array());

    // Add the first item as an empty one
    $html .= '<option value="">' . __('Alle anzeigen', 'lbwp') . '</option>' . PHP_EOL;

    // Add all the options, and preselect with current terms
    foreach ($allTerms as $term) {
      $selected = (in_array($term->slug, $terms)) ? ' selected="selected"' : '';
      $html .= '<option value="' . $term->slug . '"' . $selected . '>' . $term->name . '</option>' . PHP_EOL;
    }

    // Close the select and return
    $html .= '</select>';
    return $html;
  }

  /**
   * Adds the year navigation
   * @param string $html html template
   * @param array $config the event list config
   * @return string the changed html template
   */
  protected function addNavigation($html, $config)
  {
    $navHtml = '';

    // Create the container array, with the current year
    $navigation = array(
      $this->currentYear => array(
        'label' => $this->currentYear,
        'link' => $this->getListingLink($config, array('year' => $this->currentYear))
      )
    );

    // See if there is a tab to the future that we could display
    $nextYear = $this->currentYear + 1;
    if ($this->frontend->hasFutureEvents($config, $nextYear)) {
      $navigation[$nextYear] = array(
        'label' => $nextYear,
        'link' => $this->getListingLink($config, array('year' => $nextYear))
      );
    }

    // See if we need archive tabs
    if ($config['display_past_years'] > 0) {
      for ($i = 1; $i <= $config['display_past_years']; $i ++) {
        $pastYear = $this->currentYear - $i;
        $navigation[$pastYear] = array(
          'label' => $pastYear,
          'link' => $this->getListingLink($config, array('year' => $pastYear))
        );
      }
    }

    // Sort descending by numerical key (faster)
    krsort($navigation, SORT_NUMERIC);

    // Generate html from the $navigation variable
    foreach ($navigation as $year => $element) {
      $classes = 'navigation-item';
      if ($year == $config['year']) {
        $classes .= ' current-item';
      }
      $navHtml .= '
        <li class="' . $classes . '"><a href="' . $element['link'] . '">' . $element['label'] . '</a></li>
      ';
    }

    // Enclose in an ul
    $navHtml = '<ul class="event-navigation">' . $navHtml . '</ul>';

    // Let developers override the output completely
    $navHtml = apply_filters('lbwpEvents_list_navigation_html', $navHtml, $navigation, $config);
    return str_replace('{navigation}', $navHtml, $html);
  }

  /**
   * @param array $config the base config with all params
   * @param array $override the params that need to be overridden in the link
   * @return string an url on the same listing page with changed parameters
   */
  protected function getListingLink($config, $override = array())
  {
    $linkData = array();
    $linkConfig = array_merge($config, $override);

    // Only get the params needed
    foreach ($this->getParamMatch as $param => $key) {
      $linkData[$param] = $linkConfig[$key];
    }

    // Remove to/from if y/m are given
    if (is_int($linkData['y']) && is_int($linkData['m'])) {
      unset($linkData['from']);
      unset($linkData['to']);
    }

    // Add terms, if there are
    if (isset($linkConfig['terms']) && is_array($linkConfig['terms'])) {
      $linkData['terms'] = $linkConfig['terms'];
    }

    // Get the permalink of current page and append data
    return get_permalink() . '?' . http_build_query($linkData);
  }

  /**
   * Adds the events to the template
   * @param string $html html template
   * @param array $events the events to be displayed
   * @param array $config the event list config
   * @return string the changed html template
   */
  protected function addEvents($html, $events, $config)
  {
    $eventsHtml = '';
    $template = $this->templates['event-item'];

    foreach ($events as $event) {
      $replaces = array(
        '{start-date}' => date_i18n($config['date_format'], $event->startTime),
        '{start-time}' => date_i18n($config['time_format'], $event->startTime),
        '{end-date}' => date_i18n($config['date_format'], $event->endTime),
        '{end-time}' => date_i18n($config['time_format'], $event->endTime),
        '{event-link}' => get_permalink($event->ID),
        '{event-title}' => apply_filters('the_title', $event->post_title),
        '{event-short-description}' => wpautop($event->post_excerpt),
        '{event-location}' => strlen($event->location) > 0 ? '<p class="location">' . $event->location . '</p>' : ''
      );

      // Remove times, if midnight (means that no time was set)
      foreach (array('{start-time}', '{end-time}') as $field) {
        if ($replaces[$field] == '0:00' || $replaces[$field] == '00:00') {
          $replaces[$field] = '';
        }
      }

      // Let developers add their own event replaces
      $replaces = apply_filters('lbwpEvents_list_event_item_replaces', $replaces, $event, $config);

      // Replace the values in the template and add to $eventsHtml
      $eventHtml = $template;
      foreach ($replaces as $search => $replace) {
        $eventHtml = str_replace($search, $replace, $eventHtml);
      }

      // Add to the list
      $eventsHtml .= $eventHtml;
    }

    // If not events give a message
    if (strlen($eventsHtml) == 0) {
      $eventsHtml = '<p>' . __('Es wurden keine Veranstaltungen gefunden', 'lbwp') . '</p>';
    }

    // Add the list to the main template
    return str_replace('{event-item-list}', $eventsHtml, $html);
  }

  /**
   * This is public so it can be used as a framework function
   * @param array $args the shortcode arguments
   * @return array the listing configuration
   */
  public function getListConfiguration($args)
  {
    // Get the base merged configuration
    $config = $this->mergeConfigurationDefaults($args);

    // Now, make it possible for some range/config arguments to be overridden
    foreach ($this->getParamMatch as $param => $argument) {
      if (isset($_REQUEST[$param]) && strlen($_REQUEST[$param]) > 0) {
        $config[$argument] = $_REQUEST[$param];
      }
    }

    // Since shortcode atts makes strings from integers, convert back
    foreach ($this->integerConfigs as $key) {
      $config[$key] = intval($config[$key]);
    }

    // Get the taxonomy and term configuration
    $config = $this->mergeTermQueryConfiguration($config);

    // Calculate the "width" of the query with from/to or year/month
    $config = $this->calculateQueryWidth($config);

    // If the whole query is in the past, implicitly set display_past_events
    if ($config['from'] < $this->currentTime && $config['to'] < $this->currentTime) {
      $config['display_past_events'] = 1;
    }

    // If the year is set and not the current one, always hide past events filter
    if (isset($config['year']) && $config['year'] != $this->currentYear) {
      $config['display_past_events_filter'] = 0;
    }

    return $config;
  }

  /**
   * Sets the basic templates to $this->templates
   */
  protected function generateBasicTemplates($config)
  {
    $this->templates = array(
      // The main container
      'container' => '
        <div class="event-page">
          <form method="get" class="event-list-form">
            <input type="hidden" name="y" value="' . $config['year'] . '" />
            <input type="hidden" name="m" value="' . $config['month'] . '" />
            <div class="event-filter">
              {taxonomy-filter}
              {past-events-filter}
            </div>
            {navigation}
            <section class="event-list">
              {event-item-list}
            </section>
          </form>
        </div>
      ',

      // Inner part of the taxonomy filter, if activated
      'taxonomy-filter' => '
        <div class="taxonomy-filter">
          <label>' . __('Kategorie', 'lbwp') . '</label>
          {dropdown:' . EventType::EVENT_TAXONOMY . '}
        </div>
      ',

      // Inner part of the past events filter, if activated
      'past-events-filter' => '
        <div class="past-events-filter">
          {checkbox}
          <label for="dpe-filter">' . __('Vergangene Veranstaltungen anzeigen', 'lbwp') . '</label>
        </div>
      ',

      // The navigation template (only a ul output by default)
      'navigation' => '{navigation-list}',

      // A single event in the list
      'event-item' => '
        <article>
          <div class="date-time">
            <time class="event-date">
              {start-date}
            </time>
            <time class="event-time">
              {start-time}
            </time>
          </div>
          <div>
            <h3><a href="{event-link}">{event-title}</a></h3>
            {event-short-description}
            {event-location}
            <a class="more" href="{event-link}">' . __('mehr', 'lbwp') . '</a>
          </div>
        </article>
      '
    );
  }

  /**
   * Enqueue event module frontend assets
   */
  public function enqueueAssets()
  {
    // Add assets, if the developer doesn't provide their own
    if (!apply_filters('lbwpEvents_list_assets_override', false)) {
      wp_enqueue_style('lbwp-events-fe-css');
      wp_enqueue_script('lbwp-events-fe-js');
    }
  }

  /**
   * Calculate and set a timestamp from/to into the config array
   * @param $config
   */
  protected function calculateQueryWidth($config)
  {
    $config['from'] = strtotime($config['from']);
    $config['to'] = strtotime($config['to']);

    // If one of them is false, use the year/month query
    if (!$config['from'] || !$config['to']) {
      // Querying a month, or the whole year?
      if ($config['month'] >= 1 && $config['month'] <= 12) {
        // Only the given month
        $toDays = cal_days_in_month(CAL_GREGORIAN, $config['month'], $config['year']);
        $config['from'] = mktime(0, 0, 0, $config['month'], 1, $config['year']);
        $config['to'] = mktime(0, 0, 0, $config['month'], $toDays, $config['year']);
      } else {
        // The whole year
        $config['from'] = mktime(0, 0, 0, 1, 1, $config['year']);
        $config['to'] = mktime(23, 59, 59, 12, 31, $config['year']);
      }
    }

    return $config;
  }

  /**
   * @param array $config current config
   * @return array config with added term/taxonomy
   */
  protected function mergeTermQueryConfiguration($config)
  {
    // Check for taxonomies, if string, make an array out of it
    if (is_string($config['taxonomies'])) {
      $config['taxonomies'] = explode(';', $config['taxonomies']);
    }

    // Also explode the terms as configured
    if (is_string($config['terms'])) {
      $queryCombination = explode(';', $config['terms']);
      $config['terms'] = array();
      // Do a lot of explosions here
      foreach ($queryCombination as $combination) {
        list($taxonomy, $terms) = explode(':', $combination);
        $terms = explode(',', $terms);
        $config['terms'][$taxonomy] = $terms;
      }
    }

    // Handle the taxonomy and terms arguments from request params
    foreach ($config['taxonomies'] as $taxonomy) {
      $terms = array();
      if (isset($_REQUEST['terms'][$taxonomy]) && is_array($_REQUEST['terms'][$taxonomy])) {
        // Reset and validate what's coming on
        foreach ($_REQUEST['terms'][$taxonomy] as $slug) {
          $slug = Strings::validateField($slug);
          if (strlen($slug) > 0) {
            $terms[] = $slug;
          }
        }
        $config['terms'][$taxonomy] = $terms;
      }
    }

    return $config;
  }
} 