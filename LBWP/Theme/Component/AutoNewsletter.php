<?php

namespace LBWP\Theme\Component;

use CMNL;
use ComotiveNL\Newsletter\Actions\ItemActions;
use ComotiveNL\Newsletter\Actions\NewsletterActions;
use ComotiveNL\Newsletter\Newsletter\Newsletter;
use LBWP\Helper\Cronjob;
use LBWP\Helper\Import\Rss2;
use LBWP\Module\General\Cms\SystemLog;
use LBWP\Util\ArrayManipulation;
use LBWP\Util\Strings;

/**
 * Generate and send automatic newsletters from RSS Feeds
 * @package LBWP\Theme\Component
 * @author Michael Sebel <michael@comotive.ch>
 */
class AutoNewsletter extends ACFBase
{

  /**
   * Register all needed types and filters to control access
   */
  public function init()
  {
    add_action('cron_daily_1', array($this, 'generateAutomaticNewsletter'));
  }

  /**
   * @return void
   */
  public function generateAutomaticNewsletter()
  {
    $config = get_field('lbwp-auto-newsletter', 'option');
    // Go trough the config and run every valid config
    foreach ($config as $newsletter) {
      if ($newsletter['send-rule'] == 'daily') {
        $this->generateNewsletter($newsletter);
      }
    }
  }

  /**
   * @param array $config
   * @return void
   */
  protected function generateNewsletter($config)
  {
    // First, get sendable articles for the newsletter
    $articles = $this->getSendableArticles($config);
    // Check if that passes our min value. if not, skip for today
    if (count($articles) < $config['min-articles']) {
      return;
    }
    // Check if it passes max, this is an error most likely, mark all as sent and inform
    if (count($articles) > $config['max-articles']) {
      SystemLog::add('autoNewsletter', 'critical', 'Too many auto-newsletter articles', $config);
      $this->markArticlesAsSent($config['rss-feed'], $articles);
      return;
    }

    // Create the actual newsletter object and time it
    $this->createNewsletterObject($config, $articles);
    // At the end, mark the articles as sent
    $this->markArticlesAsSent($config['rss-feed'], $articles);
  }

  /**
   * @param array $config
   * @param array $articles
   * @param string $language
   */
  protected function createNewsletterObject($config, $articles, $language = '')
  {
    // Create the core and backend objects
    $core = CMNL::getNewsletterCore();
    $newsletterBackend = $core->getNewsletterBackend();
    $senderBackend = $core->getNewsletterSenderBackend();
    $senderId = intval($config['sender-id']);

    // Validate an unknown language as "de" for the moment
    if (strlen($language) != 2) {
      $language = 'de';
    }

    // Create the newsletter object
    $newsletter = new Newsletter(0, '', $config['template-id'], $config['subject'], 'draft', $language, 0, array());
    $newsletter->setGuid(CMNL::generateGuid());

    // Verifiy that the sender id exists
    if (!$senderBackend->hasSender($senderId)) {
      return;
    }

    // Set the sender
    $sender = $senderBackend->getSender($senderId);
    $newsletter->setSenderId($senderId);
    $newsletter->setSenderName($sender->getName());
    $newsletter->setSenderAddress($sender->getAddress());

    $newsletterBackend->saveNewsletter($newsletter);

    // Generates the newsletter items
    $this->generateNewsletterItems($newsletter, $articles, $config);
    $newsletter = $newsletterBackend->getNewsletterByGuid($newsletter->getGuid());

    // Set the target
    $newsletter->setTarget($config['list-ids']);

    // Schedule the newsletter
    $newsletter->setSchedulingMode('future');
    $newsletter->setStatus('readytosend');
    switch ($config['send-rule']) {
      case 'daily':
      default:
        $date = date('Y-m-d', current_time('timestamp')) . ' ' . $config['daily-hour'] . ':00';
        $newsletter->setScheduledTimestamp(strtotime($date));
        break;
    }

    // Save the newsletter with the scheduling settings
    $newsletterBackend->saveNewsletter($newsletter);
    // Add a cronjob to actually execute sending
    Cronjob::register(array(
      $newsletter->getScheduledTimestamp() => 'newsletter_send',
    ));
  }

  /**
   * Generates the items for the newsletter
   *
   * @param Newsletter $newsletter
   * @param array $articles
   */
  protected function generateNewsletterItems(Newsletter $newsletter, $articles, $config)
  {
    // Create the core and backend objects
    $core = CMNL::getNewsletterCore();
    $newsletterBackend = $core->getNewsletterBackend();

    // Create the action objects
    $itemActions = new ItemActions($core, $core->getNewsletterBackend(), $core->getItemBackend(), $core->getItemDataGenerator(), $core->getItemHelper());
    $newsletterActions = new NewsletterActions($core, $core->getNewsletterBackend(), $core->getItemBackend(), $itemActions);
    $includeCategories = isset($config['auto-nl-settings']) && is_array($config['auto-nl-settings']) && in_array('include-category', $config['auto-nl-settings']);

    // Add a filter to change the intro text of the newsletter
    add_filter('standard-theme-modify-data', function($data) use ($config) {
      // First reset to no intro text
      $data['langs']['defaultIntro'] = '';
      // If text is available and date matches, add the intro textv
      if (strlen($config['entry-text']) > 0 && current_time('timestamp') > $config['entry-text-date']) {
        $data['langs']['defaultIntro'] = $config['entry-text'];
      }
      if (strlen($config['transition-text']) > 0) {
        if (strlen($data['langs']['defaultIntro']) > 0) {
          $data['langs']['defaultIntro'] .= '<br>'; // Will result in two <br> due to filters
        }
        $data['langs']['defaultIntro'] .= $config['transition-text'];
      }
      return $data;
    });

    // Create the predefined items
    $newsletterActions->addPredefinedItemsToNewsletter($newsletter);
    // Get the template
    $template = $core->getTemplate($newsletter->getTemplateKey());
    // Create and add the posts
    $completeOrder = $cleanOrder = array();
    $parentGuid = $template->getDefaultParentGuid();
    $sortableGuid = $template->getDefaultSortableGuid();

    foreach ($articles as $key => $article) {
      $guid = CMNL::generateGuid();
      $completeOrder[$parentGuid][$sortableGuid][] = $guid;
      $cleanOrder[] = $guid;
      $itemActions->createItem(
        $newsletter->getGuid(),
        'free-article',
        $article['guidmd5'],
        $guid,
        $parentGuid,
        $sortableGuid,
        false,
        array(
          'title' => $article['title'],
          'content' => $this->prepareDescription($article['description']),
          'link' => $article['link'],
          'category-label' => $includeCategories ? $article['category'] : '',
          'link-text' => 'weiterlesen',
          'image' => array('original' => array(
            'url' => $article['image']
          ))
        )
      );
    }

    // Load the newsletter
    $newsletter = $newsletterBackend->getNewsletterByGuid($newsletter->getGuid());
    // Generate the order
    $newsletter->setItemOrder($completeOrder);
    $newsletter->setCleanItemOrder($cleanOrder);
    $newsletterBackend->saveNewsletter($newsletter);
  }

  /**
   * @param $desc
   * @return string
   */
  protected function prepareDescription($desc)
  {
    // Cut on EOL, so we have at most one paragraph
    if (Strings::contains($desc, PHP_EOL)) {
      $pos = stripos($desc, PHP_EOL);
      $desc = substr($desc, 0, $pos);
    }

    return Strings::chopToSentences($desc, 175, 205, true);
  }

  /**
   * Get sendable articles that are not already sent or too old
   * @param array $config
   * @return array a list of articles to be sent
   */
  protected function getSendableArticles($config)
  {
    $sent = ArrayManipulation::forceArray(get_option('autonl_rss_sent_' . md5($config['rss-feed'])));
    $threshold = current_time('timestamp') - ($config['max-age'] * 86400);
    $raw = new Rss2($config['rss-feed']);
    $raw->set_category_concat_char(' ');
    $raw->read();
    $articles = $raw->data;

    // Skip items already sent nd too old
    foreach ($articles as $key => $article) {
      if (isset($sent[$article['guidmd5']]) || $article['date'] < $threshold) {
        unset($articles[$key]);
      }
    }

    // Sort articles before returning them
    $this->sortArticles($articles, $config);

    return $articles;
  }

  protected function sortArticles(&$articles, $config)
  {
    switch ($config['sort']) {
      case 'date-asc':
        ArrayManipulation::sortByNumericField($articles, 'date');
        break;
      case 'date-desc':
      default:
        ArrayManipulation::sortByNumericFieldAsc($articles, 'date');
        break;
    }
  }

  /**
   * Marks the given articles as sent in our rss-specific option
   * Remembers the last 500 sent articles (which should be largely enought with RRS providing only 10-20)
   * @param string $rss
   * @param array $articles
   */
  protected function markArticlesAsSent($rss, $articles)
  {
    $key = 'autonl_rss_sent_' . md5($rss);
    $sent = ArrayManipulation::forceArray(get_option($key));
    foreach ($articles as $article) {
      $sent[$article['guidmd5']] = $article['date'];
    }
    update_option($key, array_slice($sent, -500));
  }

  /**
   * Adds field settings
   */
  public function fields()
  {
    acf_add_options_page(array(
      'page_title' => 'Automatischer Newsletter Versand',
      'menu_title' => 'Auto. Newsletter',
      'capability' => 'administrator',
      'menu_slug' => 'lbwp-auto-newsletter',
      'parent_slug' => 'options-general.php'
    ));

    // Get various dropdown data for later
    $senders = $templates = $lists = array();
    // Get newsletter core
    $core = \CMNL::getNewsletterCore();
    /** @var \ComotiveNL\Starter2\Newsletter\Template\StarterTemplateAbstract $template */
    foreach ($core->getTemplates() as $key => $template) {
      $templates[$key] = $template->getName();
    }
    /** @var \ComotiveNL\Newsletter\Newsletter\NewsletterSender $sender */
    foreach ($core->getNewsletterSenderBackend()->getSenders() as $sender) {
      $senders[$sender->getId()] = $sender->getAddress();
    }

    // Only supports local mail at the moment
    $args = array(
      'post_type' => 'lbwp-mailing-list',
      'post_status' => 'publish',
      'posts_per_page' => -1
    );
    foreach (get_posts($args) as $list) {
      $lists[$list->ID . '$$' . $list->post_title] = $list->post_title;
    }

    acf_add_local_field_group(array(
      'key' => 'group_62bbecdd7c600',
      'title' => 'Automatische Newsletter konfigurieren',
      'fields' => array(
        array(
          'key' => 'field_62bbededa10b0',
          'label' => 'Newsletter hinzufügen',
          'name' => 'lbwp-auto-newsletter',
          'type' => 'repeater',
          'instructions' => '',
          'required' => 0,
          'conditional_logic' => 0,
          'wrapper' => array(
            'width' => '',
            'class' => '',
            'id' => '',
          ),
          'collapsed' => '',
          'min' => 0,
          'max' => 0,
          'layout' => 'row',
          'button_label' => 'Newsletter hinzufügen',
          'sub_fields' => array(
            array(
              'key' => 'field_62bbee02a10b1',
              'label' => 'Betreff',
              'name' => 'subject',
              'type' => 'text',
              'instructions' => '',
              'required' => 1,
              'conditional_logic' => 0,
              'wrapper' => array(
                'width' => '',
                'class' => '',
                'id' => '',
              ),
              'default_value' => '',
              'placeholder' => '',
              'prepend' => '',
              'append' => '',
              'maxlength' => '',
            ),
            array(
              'key' => 'field_62bbee0ea10b2',
              'label' => 'RSS URL',
              'name' => 'rss-feed',
              'type' => 'url',
              'instructions' => '',
              'required' => 1,
              'conditional_logic' => 0,
              'wrapper' => array(
                'width' => '',
                'class' => '',
                'id' => '',
              ),
              'default_value' => '',
              'placeholder' => '',
            ),
            array(
              'key' => 'field_62bbee22a10b3',
              'label' => 'Absender',
              'name' => 'sender-id',
              'type' => 'select',
              'instructions' => '',
              'required' => 1,
              'conditional_logic' => 0,
              'wrapper' => array(
                'width' => '',
                'class' => '',
                'id' => '',
              ),
              'choices' => $senders,
              'default_value' => false,
              'allow_null' => 0,
              'multiple' => 0,
              'ui' => 0,
              'return_format' => 'value',
              'ajax' => 0,
              'placeholder' => '',
            ),
            array(
              'key' => 'field_62bbee59a10b4',
              'label' => 'Template',
              'name' => 'template-id',
              'type' => 'select',
              'instructions' => '',
              'required' => 1,
              'conditional_logic' => 0,
              'wrapper' => array(
                'width' => '',
                'class' => '',
                'id' => '',
              ),
              'choices' => $templates,
              'default_value' => false,
              'allow_null' => 0,
              'multiple' => 0,
              'ui' => 0,
              'return_format' => 'value',
              'ajax' => 0,
              'placeholder' => '',
            ),
            array(
              'key' => 'field_62bbee68a10b5',
              'label' => 'Versandliste(n)',
              'name' => 'list-ids',
              'type' => 'select',
              'instructions' => '',
              'required' => 1,
              'conditional_logic' => 0,
              'wrapper' => array(
                'width' => '',
                'class' => '',
                'id' => '',
              ),
              'choices' => $lists,
              'default_value' => array(
              ),
              'allow_null' => 0,
              'multiple' => 1,
              'ui' => 1,
              'return_format' => 'value',
              'ajax' => 0,
              'placeholder' => '',
            ),
            array(
              'key' => 'field_62bbee99a10b6',
              'label' => 'Min. Artikel',
              'name' => 'min-articles',
              'type' => 'number',
              'instructions' => 'Minimale Anzahl zu versendender Artikel',
              'required' => 1,
              'conditional_logic' => 0,
              'wrapper' => array(
                'width' => '',
                'class' => '',
                'id' => '',
              ),
              'default_value' => 3,
              'placeholder' => '',
              'prepend' => '',
              'append' => '',
              'min' => '',
              'max' => '',
              'step' => '',
            ),
            array(
              'key' => 'field_62bbeeefa10b7',
              'label' => 'Max. Artikel',
              'name' => 'max-articles',
              'type' => 'number',
              'instructions' => 'Maximale Anzahl zu versendender Artikel',
              'required' => 1,
              'conditional_logic' => 0,
              'wrapper' => array(
                'width' => '',
                'class' => '',
                'id' => '',
              ),
              'default_value' => 20,
              'placeholder' => '',
              'prepend' => '',
              'append' => '',
              'min' => '',
              'max' => '',
              'step' => '',
            ),
            array(
              'key' => 'field_62bbef1aa10b8',
              'label' => 'Max. Alter',
              'name' => 'max-age',
              'type' => 'number',
              'instructions' => 'Maximales alter in Tage der Artikel (ältere nicht versendete, werden übersprungen)',
              'required' => 1,
              'conditional_logic' => 0,
              'wrapper' => array(
                'width' => '',
                'class' => '',
                'id' => '',
              ),
              'default_value' => 3,
              'placeholder' => '',
              'prepend' => '',
              'append' => '',
              'min' => '',
              'max' => '',
              'step' => '',
            ),
            array(
              'key' => 'field_62bbef52967ea',
              'label' => 'Reihenfolge',
              'name' => 'sort',
              'type' => 'select',
              'instructions' => '',
              'required' => 1,
              'conditional_logic' => 0,
              'wrapper' => array(
                'width' => '',
                'class' => '',
                'id' => '',
              ),
              'choices' => array(
                'date-desc' => 'Nach Datum, neueste zuerst',
                'date-asc' => 'Nach Datum, neueste zuletzt',
              ),
              'default_value' => false,
              'allow_null' => 0,
              'multiple' => 0,
              'ui' => 0,
              'return_format' => 'value',
              'ajax' => 0,
              'placeholder' => '',
            ),
            array(
              'key' => 'field_62bbefa2967eb',
              'label' => 'Versandregel',
              'name' => 'send-rule',
              'type' => 'select',
              'instructions' => '',
              'required' => 1,
              'conditional_logic' => 0,
              'wrapper' => array(
                'width' => '',
                'class' => '',
                'id' => '',
              ),
              'choices' => array(
                'daily' => 'Täglich',
              ),
              'default_value' => false,
              'allow_null' => 0,
              'multiple' => 0,
              'ui' => 0,
              'return_format' => 'value',
              'ajax' => 0,
              'placeholder' => '',
            ),
            array(
              'key' => 'field_62bbefb8967ec',
              'label' => 'Versand Uhrzeit',
              'name' => 'daily-hour',
              'type' => 'text',
              'instructions' => 'Bitte hh:mm als Format verwenden',
              'required' => 1,
              'conditional_logic' => array(
                array(
                  array(
                    'field' => 'field_62bbefa2967eb',
                    'operator' => '==',
                    'value' => 'daily',
                  ),
                ),
              ),
              'wrapper' => array(
                'width' => '',
                'class' => '',
                'id' => '',
              ),
              'default_value' => '',
              'placeholder' => '',
              'prepend' => '',
              'append' => '',
              'maxlength' => '',
            ),
            array(
              'key' => 'field_62de7b2315588',
              'label' => 'Einleitungstext',
              'name' => 'entry-text',
              'type' => 'wysiwyg',
              'instructions' => 'Wird versendet ab dem Datum welches unten angegeben ist',
              'required' => 0,
              'conditional_logic' => 0,
              'wrapper' => array(
                'width' => '',
                'class' => '',
                'id' => '',
              ),
              'default_value' => '',
              'tabs' => 'all',
              'toolbar' => 'basic',
              'media_upload' => 0,
              'delay' => 1,
            ),
            array(
              'key' => 'field_62de7b2c15589',
              'label' => 'Einleitung versenden ab',
              'name' => 'entry-text-date',
              'type' => 'date_picker',
              'instructions' => '',
              'required' => 0,
              'conditional_logic' => 0,
              'wrapper' => array(
                'width' => '',
                'class' => '',
                'id' => '',
              ),
              'display_format' => 'd.m.Y',
              'return_format' => 'U',
              'first_day' => 1,
            ),
            array(
              'key' => 'field_99de7b2315588',
              'label' => 'Überleitungstext',
              'name' => 'transition-text',
              'type' => 'wysiwyg',
              'instructions' => 'Wird vor den Artikel angezeigt, wenn definiert',
              'required' => 0,
              'conditional_logic' => 0,
              'wrapper' => array(
                'width' => '',
                'class' => '',
                'id' => '',
              ),
              'default_value' => '',
              'tabs' => 'all',
              'toolbar' => 'basic',
              'media_upload' => 0,
              'delay' => 1,
            ),
            array(
              'key' => 'field_659bb736f7356',
              'label' => 'Weitere Eintellungen',
              'name' => 'auto-nl-settings',
              'aria-label' => '',
              'type' => 'checkbox',
              'instructions' => '',
              'required' => 0,
              'conditional_logic' => 0,
              'wrapper' => array(
                'width' => '',
                'class' => '',
                'id' => '',
              ),
              'choices' => array(
                'include-category' => 'Kategorie aus RSS Feed anzeigen je Artikel',
              ),
              'default_value' => array(
              ),
              'return_format' => 'value',
              'allow_custom' => 0,
              'layout' => 'vertical',
              'toggle' => 0,
              'save_custom' => 0,
              'custom_choice_button_text' => 'Eine neue Auswahlmöglichkeit hinzufügen',
            ),
          ),
        ),
      ),
      'location' => array(
        array(
          array(
            'param' => 'options_page',
            'operator' => '==',
            'value' => 'lbwp-auto-newsletter',
          ),
        ),
      ),
      'menu_order' => 0,
      'position' => 'normal',
      'style' => 'default',
      'label_placement' => 'top',
      'instruction_placement' => 'label',
      'hide_on_screen' => '',
      'active' => true,
      'description' => '',
      'show_in_rest' => 0,
    ));
  }

  /**
   * Registers no own blocks
   */
  public function blocks() {}
} 