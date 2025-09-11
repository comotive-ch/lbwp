<?php

namespace LBWP\Module\Backend;

use LBWP\Module\BaseSingleton;
use LBWP\Util\Strings;
use LBWP\Helper\PageSettings;
use ComotiveNL\Standard\ContentSource\RssFeedContentSource;
use ComotiveNL\Newsletter\Editor\EditorDynamicSection;
use ComotiveNL\Newsletter\Editor\Editor;
use ComotiveNL\Newsletter\LayoutElement\LayoutElementAbstract;
use \CMNL;

/**
 * Adds feed configurations as a newsletter content source
 * @package LBWP\Module\Backend
 * @author Michael Sebel <michael@comotive.ch>
 */
class FeedNewsletterSource extends BaseSingleton
{
  /**
   * @var FeedNewsletterSource
   */
  protected static $instance = NULL;

  /**
   * runs, when the object is first initialized
   */
  public function run()
  {
    add_action('init', array($this, 'addSection'));
    add_action('admin_init', array($this, 'addFeedSources'));
  }

  /**
   * This adds the actual feed sources to the newsletter tool
   */
  public function addFeedSources()
  {
    $feeds = get_option('newsletterFeedSources');

    // Only add them, if they are needed
    if (is_array($feeds) && count($feeds) > 0) {
      // Get the core
      $core = CMNL::getNewsletterCore();

      // Add the sources
      foreach ($feeds as $key => $feed) {
        $rssSource = new RssFeedContentSource($feed);
        $core->registerContentSource($rssSource);
        // And add a section
        $section = new EditorDynamicSection(
          'feed-import-' . $feed['slug'],
          Editor::SIDE_LEFT,
          'Feed: ' . $feed['name'],
          'content-source-feed-' . $feed['slug'],
          ($key + 20) // make sure to be below all other sections
        );
        $core->addEditorSection($section);
      }
    }
  }

  /**
   * This adds a section to the newsletter settings
   */
  public function addSection()
  {
    if (is_admin()) {
      PageSettings::initialize();
      PageSettings::addPage(
        'additional-settings',
        'Newsletter'
      );
      PageSettings::addSection(
        'feed-sources',
        'additional-settings',
        'RSS Feeds',
        'Fügen sie hier RSS-Feeds als Quellen für Ihre Newsletter hinzu.'
      );

      // Now we add an own form type, because we need it to be somewhat complex
      PageSettings::addCallback(
        'additional-settings',
        'feed-sources',
        'newsletterFeedSources',
        'Feed-Konfiguration',
        array($this, 'displayFeedSources'),
        array($this, 'saveFeedSources')
      );
    }
  }

  /**
   * @param array $config field configuration
   * @param array $feeds the current value (an array of feeds)
   * @param string $template the html template
   * @return string html code (and some inline js, hihi)
   */
  public function displayFeedSources($config, $feeds, $template)
  {
    $html = '';

    // Make sure the value is an array, if never saved
    if (!is_array($feeds) || count($feeds) == 0) {
      // Create one empty feed
      $feeds = array(
        array('url' => '', 'name' => '', 'slug' => ''),
      );
    }

    // First, we need a hidden field, that will trigger saving
    $html .= '<input type="hidden" name="newsletterFeedSources" value="1" />';

    // Get the table that lists all feeds
    $html .= $this->getFeedsTable($feeds);

    // Add a Button and some JS to add new empty rows
    $html .= $this->getAddFeedButtonJs();

    $template = str_replace('{fieldId}', $config['fieldId'], $template);
    $template = str_replace('{input}', $html, $template);

    return $template;
  }

  /**
   * @param array $feeds the feeds to display
   * @return string html code
   */
  protected function getFeedsTable($feeds)
  {
    // Table head
    $html = '
      <table width="675" cellspacing="0" cellpadding="0" id="newsletterFeedSourcesTable">
      <tr>
        <td style="width:30%">&nbsp;<strong>Name</strong></td>
        <td style="width:65%">&nbsp;<strong>Feed-Link</strong></td>
        <td style="width:5%">&nbsp;</td>
      </tr>
    ';

    // Display the feeds
    foreach ($feeds as $feed) {
      $html .= $this->getTableRowTemplate($feed['name'], $feed['url'], $feed['slug']);
    }

    // Close the table and return
    return $html . '</table>';
  }

  /**
   * @return string html code and js to add new feeds
   */
  protected function getAddFeedButtonJs()
  {
    // First, we add the button
    $html = '<p><input type="button" class="button" value="Neuen Feed hinzufügen" id="addNewFeedSource" /></p>';

    // And the JS to handle it
    $html .= '
      <script type="text/javascript">
        var FeedSource = {
          RowTemplate : ' . json_encode($this->getTableRowTemplate('', '', '')) . ',

          // assigns delete events to the buttons on each row
          assignDeleteEvents : function()
          {
            var buttons = jQuery(".delete-feed-row");
            // Unassign previous events
            buttons.unbind("click");
            // Assign deletion event
            buttons.click(function() {
              jQuery(this).parent().parent().remove();
            });
          },

          // Adds a new source
          addNewSource : function()
          {
            var table = jQuery("#newsletterFeedSourcesTable");
            table.append(FeedSource.RowTemplate);
            FeedSource.assignDeleteEvents();
          }
        };

        jQuery(function() {
          jQuery("#addNewFeedSource").click(function() {
            FeedSource.addNewSource();
          });

          FeedSource.assignDeleteEvents();
        });
      </script>
    ';

    return $html;
  }

  /**
   * @param string $name the name to be filled, if empty, the variable {name} will be used
   * @param string $url the name to be filled, if empty, the variable {url} will be used
   * @param string $slug the name to be filled, if empty, the variable {slug} will be used
   * @return string a table row template. {name} and {url} need to be filled
   */
  protected function getTableRowTemplate($name = '{name}', $url = '{url}', $slug = '{slug}')
  {
    return '
      <tr>
        <td style="width:30%">
          <input type="text" style="width:97%;" value="' . $name . '" name="newsletterFeedSourcesNames[]" />
        </td>
        <td style="width:60%">
          <input type="text" style="width:100%;" value="' . $url . '" name="newsletterFeedSourcesUrls[]" />
        </td>
        <td style="width:5%">
          &nbsp;<a href="javascript:void(0);" class="delete-feed-row" title="Feed löschen">Löschen</a>
          <input type="hidden" value="' . $slug . '" name="newsletterFeedSourcesSlugs[]" />
        </td>
      </tr>
    ';
  }

  /**
   * @param array $item the full item (not used right here..)
   */
  public function saveFeedSources($item)
  {
    $feeds = array();
    $items = count($_POST['newsletterFeedSourcesNames']);

    for ($i = 0; $i < $items; $i++) {
      if (strlen($_POST['newsletterFeedSourcesUrls'][$i]) > 0 && strlen($_POST['newsletterFeedSourcesNames'][$i]) > 0) {
        $feeds[] = array(
          'name' => $_POST['newsletterFeedSourcesNames'][$i],
          'url' => $_POST['newsletterFeedSourcesUrls'][$i],
          'slug' => $this->createFeedSlug(
            $_POST['newsletterFeedSourcesSlugs'][$i],
            $_POST['newsletterFeedSourcesNames'][$i]
          ),
        );
      }
    }

    update_option('newsletterFeedSources', $feeds);
  }

  /**
   * @param string $slug the slug to set (can be empty, if it is, it will be generated from $name)
   * @param string $name the name to create the slug from if empty
   * @return string the new slug
   */
  public function createFeedSlug($slug, $name)
  {
    if (strlen($slug) == 0) {
      $slug = Strings::validateField($name);
    }

    return $slug;
  }

  /**
   * Adds the layout element to all feeds
   *
   * @param LayoutElementAbstract $layoutElement
   * @param string $parentKey
   */
  public function attachLayout($layoutElement, $parentKey = '')
  {
    if ($parentKey != '') {
      $parentKey .= ' ';
    }

    // Get the core and the feeds
    $newsletterCore = CMNL::getNewsletterCore();
    $feeds = get_option('newsletterFeedSources');

    // Only add them, if they are needed
    if (is_array($feeds) && count($feeds) > 0) {
      foreach ($feeds as $feed) {
        $newsletterCore->addLayoutDefinition($layoutElement->getKey(), $parentKey . 'content-source-feed-' . $feed['slug']);
      }
    }
  }
}