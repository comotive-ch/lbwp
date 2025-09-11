<?php

namespace LBWP\Theme\Component\Selection;


use LBWP\Helper\Cronjob;
use LBWP\Helper\MetaItem\PostTypeDropdown;
use LBWP\Module\Backend\MemcachedAdmin;
use LBWP\Newsletter\Helper\CustomSource;
use LBWP\Theme\Base\Component;
use LBWP\Theme\Component\Selection\Import\Base;
use LBWP\Theme\Component\Selection\Import\Scope;
use LBWP\Theme\Feature\SocialShare\Buttons;
use LBWP\Util\Date;
use LBWP\Util\External;
use LBWP\Util\Strings;
use LBWP\Util\WordPress;
use LBWP\Helper\Metabox;
use \CMNL;
use \ComotiveNL\Newsletter\Item\Item;
use \ComotiveNL\Newsletter\Newsletter\Newsletter;
use \ComotiveNL\Newsletter\Actions\NewsletterActions;
use \ComotiveNL\Newsletter\Actions\ItemActions;

/**
 * Component to provide news selection features
 * @package LBWP\Theme\Component\Crm
 * @author Michael Sebel <michael@comotive.ch>
 */
class Core extends Component
{
  /**
   * @var string slug for selections
   */
  const TYPE_SELECTION = 'lbwp-selection';
  /**
   * @var string slug for items within a selection
   */
  const TYPE_NEWS_ITEM = 'lbwp-selection-item';
  /**
   * @var string slug for ads within a selection type
   */
  const TYPE_AD = 'lbwp-selection-ad';
  /**
   * @var string the category for ads
   */
  const TAX_AD_CATEGORY = 'lbwp-selection-ad-category';
  /**
   * @var string slug for custom fields
   */
  const TAX_ITEM_CATEGORY = 'lbwp-item-category';
  /**
   * @var array will be overridden by the implementing class
   */
  protected $config = array();
  /**
   * @var Base the importer, if given
   */
  protected $importer = NULL;
  /**
   * @var bool tells if there is an importer
   */
  protected $hasImporter = false;
  /**
   * @var bool ist post type searchable with basic wordpress search
   */
  protected $searchable = false;
  /**
   * @var string eventual import message when importer runs
   */
  protected $importMessage = '';
  /**
   * @var string eventual generate message when generator runs
   */
  protected $generateMessage = '';

  /**
   * Initialize the component
   */
  public function init()
  {
    // Make in instance of the importer if given
    $this->createImportInstance();
    // Register types and taxonomies
    $this->addCustomTypes();
    // And the according meta boxes and menus
    add_action('admin_init', array($this, 'addMetaboxes'));
    add_action('admin_menu', array($this, 'addMenus'));
    // Handle manual processing of events
    add_action('admin_menu', array($this, 'handleManualProcesses'));
    add_action('admin_footer', array($this, 'printScriptHacks'));
    // Shortcode to redirect to the newest selection
    add_shortcode('lbwpnews:selection', array($this, 'redirectNewestSelection'));
    // Publish news upon publishing a selection
    add_action('publish_' . self::TYPE_SELECTION, array($this, 'forceNewsPublishing'));
    add_filter('the_title_rss', array($this, 'includeTermInTitle'));
    // Reimport date for news item
    add_action('wp_ajax_reImportItemDate', array($this, 'reImportItemDate'));
    add_action('rest_api_init', array($this, 'registerApiEndpoints'));
    // Add template for selection single sites
    $this->getTheme()->registerViews(array(
      'archive:' . self::TYPE_NEWS_ITEM => 'views/component/selection/news.php',
      'single:' . self::TYPE_SELECTION => 'views/component/selection/index.php',
      'single:' . self::TYPE_NEWS_ITEM => 'views/component/selection/item.php'
    ));
    // Endpoint to run manual correction of news items that have no living parents
    add_action('cron_job_remove_orphan_news', array($this, 'removeOrphanNews'));

    // If ads are active, prepare the according woocomm hooks
    if ($this->config['useAds']) {
      add_action('woocommerce_checkout_order_processed', array($this, 'createAdInstance'),20, 1);
      add_action('woocommerce_after_account_bookings_pagination', array($this, 'extendBookingsUI'));
      add_action('woocommerce_account_anzeige-editor_endpoint', array($this, 'getAdEditorUI'));
      add_filter('woocommerce_billing_fields', array($this, 'alterBillingFieldConfig'), 10, 1);
      add_action('save_post_wc_booking', array($this, 'createMissingAd'));
      // Add metaboxes for twitter content in ads
      add_action('admin_init', array($this, 'addAdMetaboxes'));
      // Daily notifications crons
      add_action('cron_daily_20', array($this, 'sendReviewableAdsNotification'));
      add_action('cron_daily_8', array($this, 'sendUnfinishedAdNotification'));
    }
  }

  /**
   * Register API routes
   */
  public function registerApiEndpoints()
  {
    register_rest_route('news/scope', 'push', array(
      'methods' => \WP_REST_Server::CREATABLE,
      'callback' => array($this, 'receiveScopeArticle')
    ));
  }

  /**
   * @return array response
   */
  public function receiveScopeArticle()
  {
    // Get article from post body
    $inputJSON = file_get_contents('php://input');
    $article = json_decode($inputJSON, true);
    // Fire up a scope importer and import to default container
    $scope = new Scope($this->config);
    $scope->insertArticleToSelection(
      $scope->normalizeArticle($article),
      $this->config['importerConfig']['oneClickPublishContainerId']
    );
    // Flush frontend cache when new article arrives
    MemcachedAdmin::flushFrontendCacheHelper();
    return array('success' => true);
  }

  /**
   * Creates an importer instance if given
   */
  protected function createImportInstance()
  {
    if ($this->config['useImporter']) {
      $class = $this->config['importerConfig']['class'];
      $this->importer = new $class($this->config);
      $this->hasImporter = true;
    }
  }

  /**
   * Include the main term in title
   */
  public function includeTermInTitle($title)
  {
    global $post;
    if ($post->post_type == self::TYPE_NEWS_ITEM) {
      return WordPress::getFirstTermName($post->ID, self::TAX_ITEM_CATEGORY) . ': ' . $post->post_title;
    }
    return $title;
  }

  /**
   * @param $bookingId
   */
  public function createMissingAd($bookingId)
  {
    // Only do this if an admin saves
    if (!current_user_can('administrator') || !is_admin()) {
      return;
    }
    $adId = intval(get_post_meta($bookingId, '_ad_id', true));
    $resourceId = intval(get_post_meta($bookingId, '_booking_resource_id', true));

    if ($adId == 0 && $resourceId > 0) {
      $position = str_replace('adposition-', '', get_post($resourceId)->post_name);
      // Convert the date from booking to something human readable
      $rawDateInt = get_post_meta($bookingId, '_booking_start', true);
      $date = substr($rawDateInt, 6, 2) . '.' . substr($rawDateInt, 4, 2) . '.' . substr($rawDateInt, 0, 4);
      // Create the ad in draft and add position and date meta
      $adId = wp_insert_post(array(
        'post_type' => self::TYPE_AD,
        'post_title' => 'Anzeige, ' . $date . ', ' . $position,
        'post_author' => intval(get_post($bookingId)->post_author),
        'post_date' => Date::convertDate(Date::EU_DATE, Date::SQL_DATETIME, $date)
      ));
      update_post_meta($adId, 'position', $position);
      add_post_meta($adId, 'dates', $date);
      // Add the ad ID as meta on the booking
      update_post_meta($bookingId, '_ad_id', $adId);
    }
  }

  /**
   * Creats the ad once the booking order is completed
   */
  public function createAdInstance($orderId)
  {
    $order = new \WC_Order($orderId);
    $userId = get_current_user_id();

    // Get the bookings of that order
    $bookings = get_posts(array(
      'post_type' => 'wc_booking',
      'post_status' => array('unpaid', 'paid'),
      'post_parent' => $orderId
    ));

    // Create instances of ads from the items bought
    foreach ($bookings as $booking) {
      // Get position from the resources post name
      $resourceId = get_post_meta($booking->ID, '_booking_resource_id', true);
      $position = str_replace('adposition-', '', get_post($resourceId)->post_name);
      // Convert the date from booking to something human readable
      $rawDateInt = get_post_meta($booking->ID, '_booking_start', true);
      $date = substr($rawDateInt, 6, 2) . '.' . substr($rawDateInt, 4, 2) . '.' . substr($rawDateInt, 0, 4);
      // Create the ad in draft and add position and date meta
      $adId = wp_insert_post(array(
        'post_type' => self::TYPE_AD,
        'post_title' => 'Anzeige, ' . $date . ', ' . $position,
        'post_author' => $userId,
        'post_date' => Date::convertDate(Date::EU_DATE, Date::SQL_DATETIME, $date)
      ));
      update_post_meta($adId, 'position', $position);
      add_post_meta($adId, 'dates', $date);
      // Add the ad ID as meta on the booking
      update_post_meta($booking->ID, '_ad_id', $adId);
    }
  }

  /**
   * @param $postId
   */
  public function forceNewsPublishing($postId)
  {
    global $wpdb;
    $itemIds = array_filter(get_post_meta($postId, 'news'));
    // Publish all the news items by id
    foreach ($itemIds as $itemId) {
      $post = get_post($itemId);
      wp_update_post(array(
        'ID' => $post->ID,
        'post_status' => 'publish',
        'post_name' => $post->post_title
      ));
    }
  }

  /**
   * Adds the custom types and categories
   */
  protected function addCustomTypes()
  {
    $type = $this->config['typeConfig'];
    WordPress::registerType(self::TYPE_SELECTION, $type['singular'], $type['plural'], array(
      'menu_icon' => 'dashicons-images-alt',
      'exclude_from_search' => false,
      'publicly_queryable' => true,
      'show_in_nav_menus' => false,
      'has_archive' => true,
      'supports' => array('title', 'editor', 'thumbnail'),
      'rewrite' => array(
        'slug' => $type['rewrite']
      )
    ), $type['letter']);

    WordPress::registerType(self::TYPE_NEWS_ITEM, 'News', 'News', array(
      'show_in_menu' => 'edit.php?post_type=' . self::TYPE_SELECTION,
      'exclude_from_search' => !$this->searchable,
      'publicly_queryable' => $this->searchable,
      'show_in_nav_menus' => false,
      'has_archive' => true,
      'supports' => array('title', 'editor', 'thumbnail'),
      'rewrite' => array(
        'slug' => 'news'
      )
    ), '');

    WordPress::registerTaxonomy(
      self::TAX_ITEM_CATEGORY,
      'Kategorie',
      'Kategorien',
      '',
      array(),
      array(self::TYPE_SELECTION, self::TYPE_NEWS_ITEM)
    );

    // Make the news elements usable from newsletter
    CustomSource::addPostTypeSource(self::TYPE_NEWS_ITEM, 'News aus Selektionen', 10, false, true);

    // Only use ads if configured
    if ($this->config['useAds']) {
      WordPress::registerType(self::TYPE_AD, 'Anzeige', 'Anzeigen', array(
        'show_in_menu' => 'edit.php?post_type=' . self::TYPE_SELECTION,
        'exclude_from_search' => true,
        'publicly_queryable' => true,
        'show_in_nav_menus' => false,
        'has_archive' => false,
        'supports' => array('title', 'editor'),
        'rewrite' => false
      ), '');

      WordPress::registerTaxonomy(
        self::TAX_AD_CATEGORY,
        'Anzeigekategorie',
        'Anzeigekategorien',
        '',
        array(),
        array(self::TYPE_AD)
      );

      add_rewrite_endpoint('anzeige-editor', EP_PAGES);
    }
  }

  /**
   * Let admins change twitter ad content in backend
   */
  public function addAdMetaboxes()
  {
    $helper = Metabox::get(self::TYPE_AD);
    $helper->addMetabox('twitter', 'Content für Twitter');
    $helper->addInputText('twitter-url', 'twitter', 'Geteilter Link');
    $helper->addTextarea('twitter-text', 'twitter', 'Text', 45);
  }

  /**
   * Adds the menus to to importing and sending
   */
  public function addMenus()
  {
    add_submenu_page(
      'edit.php?post_type=lbwp-selection',
      $this->config['importMenu'],
      $this->config['importMenu'],
      'edit_posts',
      'selection-send',
      array($this, 'printSendAndImportUI')
    );
  }

  /**
   * Provides settings for each appliance
   */
  public function addMetaboxes()
  {
    $helper = Metabox::get(self::TYPE_SELECTION);
    $helper->addMetabox('news-items', 'Newseinträge');
    $helper->addPostTypeDropdown('news', 'news-items', 'Einträge', self::TYPE_NEWS_ITEM, array(
      'description' => 'Bitte ' . $this->config['typeConfig']['singular'] . ' einmal Speichern, bevor News hinzugefügt werden.',
      'parent' => $this->getCurrentPostId(),
      'auto_sort_save' => true,
      'itemHtmlCallback' => array($this, 'getNewsItemBackendHtml'),
      'multiple' => true,
      'sortable' => true
    ));

    $helper = Metabox::get(self::TYPE_NEWS_ITEM);
    $helper->addMetabox('news-data', 'Einstellungen');
    $helper->addInputText('source', 'news-data', 'Quelle', array(
      'description' => 'Wenn leer, wird als Quelle die Domain des Ziel-Links verwendet'
    ));
    $helper->addInputText('url', 'news-data', 'Ziel-Link');

    $helper = Metabox::get(self::TYPE_AD);
    $helper->addMetabox('ad-data', 'Einstellungen');
    $helper->addInputText('position', 'ad-data', 'Position', array(
      'description' => 'Syntax z.B. schweiz-3 um in der Kategorie "Schweiz" die Anzeige an dritter Position zu zeigen.'
    ));
    $helper->addInputText('dates', 'ad-data', 'Anzeigedatum', array(
      'description' => 'Bitte Datum in der Syntax mm.dd.yyyy hinzufügen.'
    ));
  }

  /**
   * Overrideables
   */
  public function getCustomCssHacks() {}
  public function getCustomJsHacks() {}

  /**
   * Some simple css and js hacks in admin footer
   */
  public function printScriptHacks()
  {
    echo '
      <style type="text/css">
        .wp-admin.post-type-lbwp-selection #lbwp-item-categorydiv {
          display:none;
        }
        .wp-admin.post-type-lbwp-selection-item #major-publishing-actions {
          display:none;
        }
        ' . $this->getCustomCssHacks() . '
      </style>
      <script type="text/javascript">
        jQuery(function() {
          if (jQuery(".wp-admin.post-type-lbwp-selection-item").length == 1) {
            if (jQuery("#save-action #save-post").length == 1) {
              jQuery("#major-publishing-actions").remove();
            } else {
              jQuery("#major-publishing-actions").css("display", "block");
            }
            // Register handler on saving, when no categories are set
            jQuery("#save-post").on("click", function() {
              var categories = jQuery("#lbwp-item-categorychecklist").find("input:checked").length;
              if (categories == 0) {
                alert("Bitte wählen Sie eine Kategorie für die Newsmeldung aus. Die Daten können erst danach gespeichert werden.");
                return false;
              }
              return true;
            })
          }
          
          if (jQuery(".wp-admin.post-type-lbwp-selection").length == 1) {
            jQuery("#publish").on("click", function() {
              return confirm("Möchten Sie wirklich veröffentlichen? Dadurch geht hier richtig die Post ab.");
            })
          }
          ' . $this->getCustomJsHacks() . '
        });
      </script>
    ';
  }

  /**
   * @param \WP_Post $item the post item
   * @param array $typeMap a post type mapping
   * @return string html code to represent the item
   */
  public function getNewsItemBackendHtml($item, $typeMap, $delete = true)
  {
    $image = '';
    if (has_post_thumbnail($item->ID)) {
      $image = '<img src="' . WordPress::getImageUrl(get_post_thumbnail_id($item->ID), 'thumbnail') . '">';
    }

    $parentId = intval($_GET['post']);
    if ($parentId == 0 && isset($_POST['postId'])) {
      $parentId = intval($_POST['postId']);
    }

    // Edit link for modals
    $editLink = admin_url('post.php?post=' . $item->ID . '&action=edit&ui=show-as-modal&parent=' . $parentId);

    return '
      <div class="mbh-chosen-inline-element">
        ' . $image . '
        <h2><a href="' . $editLink . '" class="open-modal">' . PostTypeDropdown::getPostElementName($item, $typeMap) . '</a></h2>
        <ul class="mbh-item-actions">
          <li><a href="' . $editLink . '" class="open-modal">' . __('Bearbeiten', 'lbwp') . '</a></li>
          <li><a href="#" data-id="' . $item->ID . '" class="trash-element trash">' . __('Löschen', 'lbwp') . '</a></li>
        </ul>
        <p class="mbh-post-info">Kategorie: ' . WordPress::getFirstTermName($item->ID, self::TAX_ITEM_CATEGORY) . '</p>
      </div>
    ';
  }

  /**
   * @return int the current post id or 0 if not available
   */
  protected function getCurrentPostId()
  {
    // Get a post id (depending on get or post, context)
    $postId = intval($_GET['post']);
    if ($postId == 0) {
      $postId = intval($_POST['post_ID']);
    }

    return $postId;
  }

  /**
   * Handle the POSTs of manual processes
   */
  public function handleManualProcesses()
  {
    if (isset($_POST['runImporter'])) {
      $this->importMessage = $this->importer->run($_POST['import-selection']);
    }
    if (isset($_POST['runDateReSort'])) {
      $this->importMessage = $this->runDateReSort($_POST['resort-id']);
    }
    if (isset($_POST['generateAndWait']) || isset($_POST['generateAndSend'])) {
      $this->generateMessage = $this->generateNewsletter($_POST['selectionId'], isset($_POST['generateAndSend']));
    }
  }

  /**
   * @param $id
   */
  protected function runDateReSort($id)
  {
    // Get all news ordered by date with ID as parent
    $items = get_posts(array(
      'post_type' => self::TYPE_NEWS_ITEM,
      'post_parent' => $id,
      'posts_per_page' => -1,
      'orderby' => 'date',
      'order' => 'DESC'
    ));

    // First, flush all post meta
    delete_post_meta($id, 'news');
    foreach ($items as $item) {
      add_post_meta($id, 'news', $item->ID);
    }

    return 'Die Einträge wurden neu geordnet.';
  }

  /**
   * @param int $selectionId the selection id
   * @param bool $sendImmediately
   * @return string a message
   */
  protected function generateNewsletter($selectionId, $sendImmediately)
  {
    $language = 'de';
    $selectionId = intval($selectionId);
    $selection = $this->getFullSelectionObject($selectionId);

    // Create the core and backend objects
    $core = CMNL::getNewsletterCore();
    $newsletterBackend = $core->getNewsletterBackend();
    $senderBackend = $core->getNewsletterSenderBackend();

    // Get all the data needed
    $senderId = $this->config['newsletter']['senderId'];
    $templateKey = $this->config['newsletter']['templateKey'];
    $target = $this->config['newsletter']['listId'];

    $subject = $_POST['newsletterSubject'];
    if (strlen($subject) == 0) {
      $subject = $selection['title'];
    }

    // Create the newsletter object
    $newsletter = new Newsletter(0, '', $templateKey, $subject, 'draft', $language, 0, array());
    $newsletter->setGuid(CMNL::generateGuid());

    // Verifiy that the sender id exists
    if (!$senderBackend->hasSender($senderId)) {
      return __('Fehler: Absender unbekannt.', 'lbwp');;
    }

    // Set the sender
    $sender = $senderBackend->getSender($senderId);
    $newsletter->setSenderId($senderId);
    $newsletter->setSenderName($sender->getName());
    $newsletter->setSenderAddress($sender->getAddress());

    $newsletterBackend->saveNewsletter($newsletter);

    // Generates the newsletter items
    $this->generateNewsletterItems($newsletter, $selection);
    $newsletter = $newsletterBackend->getNewsletterByGuid($newsletter->getGuid());

    // Set the target
    $newsletter->setTarget($target);

    // Schedule the newsletter
    $newsletter->setSchedulingMode('now');
    $newsletter->setScheduledTimestamp(current_time('timestamp'));

    // Save the newsletter
    $newsletterBackend->saveNewsletter($newsletter);

    // Verify and send only if given
    if ($sendImmediately) {
      $this->verifyAndSendNewsletter($newsletter);
    }

    return __('Der Newsletter wurde generiert.', 'lbwp');
  }

  /**
   * Generates the items for the newsletter
   * @param Newsletter $newsletter
   * @param array $selection
   */
  protected function generateNewsletterItems(Newsletter $newsletter, $selection)
  {
    // Create the core and backend objects
    $core = CMNL::getNewsletterCore();
    $newsletterBackend = $core->getNewsletterBackend();
    $itemBackend = $core->getItemBackend();
    // Create the action objects
    $itemActions = new ItemActions($core, $core->getNewsletterBackend(), $itemBackend, $core->getItemDataGenerator(), $core->getItemHelper());
    $newsletterActions = new NewsletterActions($core, $core->getNewsletterBackend(), $core->getItemBackend(), $itemActions);
    // Create the predefined items
    $newsletterActions->addPredefinedItemsToNewsletter($newsletter);
    $newsletter = $newsletterBackend->getNewsletterByGuid($newsletter->getGuid());
    // Get the template
    $template = $core->getTemplate($newsletter->getTemplateKey());
    // Override creating of the read more label
    $lang = $newsletter->getLanguage();

    /** @var Item $item override the entry item with the selection comment */
    foreach ($newsletter->getItems() as $item) {
      if ($item->getOriginKey() == 'free-text') {
        $item->setData('content', wpautop($selection['content']));
        $itemBackend->saveItem($item);
      }
    }

    // Generate main guids
    $parentGuid = $template->getDefaultParentGuid();
    $sortableGuid = $template->getDefaultParentGuid();
    $completeOrder = array();
    $cleanOrder = array();

    // Create the actual items from the selection
    foreach ($selection['categories'] as $category) {
      // Create the label object
      $data = array(
        'title' => $category['name'],
        'divider-type-top' => 'background',
        'divider-type-bottom' => 'none',
        'display-type' => 'label'
      );
      $item = $itemActions->createItem(
        $newsletter->getGuid(),
        'free-title',
        '',
        CMNL::generateGuid(),
        $parentGuid,
        $sortableGuid,
        false,
        $data
      );
      $this->cleanSortOrder($item, $completeOrder, $cleanOrder);

      // Generate all article elements
      $current = 0;
      $articles = count($category['articles']);
      foreach ($category['articles'] as $article) {
        // Set another bottom when current is the last
        $bottomDividerKey = 'line';
        if (++$current == $articles) {
          $bottomDividerKey = 'none';
        }

        // Preset the data
        switch ($article['type']) {
          case 'type-item':
            $type = 'free-article';
            $data = array(
              'content' => $article['content'],
              'link-text' => $article['source'],
              'link' => $article['redirect'],
              'divider-type-top' => 'none',
              'divider-type-bottom' => $bottomDividerKey
            );
            if ($this->config['newsletter']['useTitles']) {
              $data['title'] = $article['title'];
            }
            break;
          case 'type-sponsor':
            $type = 'free-text';
            $data = array(
              'content' => $article['content'],
              'display-type' => 'highlighted',
              'divider-type-top' => 'none',
              'divider-type-bottom' => $bottomDividerKey
            );
            break;
        }

        // Add the newsletter item
        $item = $itemActions->createItem(
          $newsletter->getGuid(),
          $type,
          '',
          CMNL::generateGuid(),
          $parentGuid,
          $sortableGuid,
          false,
          $data
        );
        $this->cleanSortOrder($item, $completeOrder, $cleanOrder);
      }
    }

    // Let developers add custom items somewhere
    $items = apply_filters('lbwpnews_additional_generated_nl_items', array(), $newsletter, $itemActions, $parentGuid, $sortableGuid);
    foreach ($items as $item) {
      $this->cleanSortOrder($item, $completeOrder, $cleanOrder);
    }

    // Load the newsletter again
    $newsletter = $newsletterBackend->getNewsletterByGuid($newsletter->getGuid());

    // Save the order
    $newsletter->setItemOrder($completeOrder);
    $newsletter->setCleanItemOrder($cleanOrder);
    $newsletterBackend->saveNewsletter($newsletter);
  }

  /**
   * @param $item
   * @param $completeOrder
   * @param $cleanOrder
   */
  protected function cleanSortOrder($item, &$completeOrder, &$cleanOrder)
  {
    $itemGuid = $item->getGuid();

    $parentGuid = 'root';
    if ($item->hasParentGuid()) {
      $parentGuid = $item->getParentGuid();
    }

    $sortableGuid = 'root';
    if ($item->hasSortableGuid()) {
      $sortableGuid = $item->getSortableGuid();
    }

    if (!isset($completeOrder[$parentGuid]) || !is_array($completeOrder[$parentGuid])) {
      $completeOrder[$parentGuid] = array();
    }

    if (!isset($completeOrder[$parentGuid][$sortableGuid]) || !is_array($completeOrder[$parentGuid][$sortableGuid])) {
      $completeOrder[$parentGuid][$sortableGuid] = array();
    }

    if ($itemGuid != null) {
      $cleanOrder[] = $itemGuid;
      $completeOrder[$parentGuid][$sortableGuid][] = $itemGuid;
    }
  }

  /**
   * Verifies the newsletter and sends it if there are no critical
   * results.
   *
   * @param Newsletter $newsletter
   */
  protected function verifyAndSendNewsletter(Newsletter $newsletter)
  {
    // Create the core and backend objects
    $core = CMNL::getNewsletterCore();
    $newsletterBackend = $core->getNewsletterBackend();

    $newsletterVerifier = $core->getNewsletterVerifier();
    $newsletterVerifier->verifyNewsletter($newsletter);

    if (!$newsletterVerifier->hasCriticalResults()) {
      $newsletter->setStatus('readytosend');
      $newsletter->setPublishDate(date_i18n("Y-m-d H:i:s", $newsletter->getScheduledTimestamp()));
    }

    // Save the newsletter
    $newsletterBackend->saveNewsletter($newsletter);
    // Add a cronjob to actually execute sending
    Cronjob::register(array(
      $newsletter->getScheduledTimestamp() => 'newsletter_send',
    ));
  }

  /**
   * Re imports the date of an item by meta infos
   */
  public function reImportItemDate()
  {
    $id = intval($_REQUEST['id']);
    $url = get_post_meta($id, 'url', true);
    // Try getting the post date of that url
    $date = Date::getRemoteUrlPublishDate($url);
    // Save the date if given, error if not
    if ($date !== false) {
      $converted = Date::getTime(Date::SQL_DATETIME, $date);
      wp_update_post(array(
        'ID' => $id,
        'post_date' => $converted
      ));
      $info = 'Setze ' . $converted . ' für ' . $url;
    } else {
      $info = 'Datum nicht auffindbar. <a href="/wp-admin/post.php?post=' . $id . '&action=edit" target="_blank">Manuell bearbeiten</a>';
    }

    WordPress::sendJsonResponse(array(
      'info' => $info,
      'id' => $id,
      'url' => $url
    ));
  }

  /**
   * Print the send an import UI html
   */
  public function printSendAndImportUI()
  {
    $html = '';
    // See if there is an import component
    if ($this->hasImporter) {
      $html .= '<h2>Import aus ' . $this->importer->getName() . '</h2>';
      if (strlen($this->importMessage) > 0) {
        $html .= '<div class="updated notice notice-success"><p>' . $this->importMessage . '</p></div>';
      }
      $html .= '<p>' . $this->importer->getSelectionDropdown() . '</p>';
      $html .= '<input type="submit" class="button-primary" name="runImporter" value="' . $this->config['typeConfig']['singular'] . ' importieren" />';
    }

    if ($this->config['useDateImport'] || $this->config['useDateReorder']) {
      // Get dropdown options for recent selection
      $selections = get_posts(array(
        'post_type' => self::TYPE_SELECTION,
        'post_status' => 'any',
        'posts_per_page' => 5
      ));

      $options = '';
      foreach ($selections as $selection) {
        $items = array_map('intval', get_post_meta($selection->ID, 'news'));
        $options .= '<option value="' . $selection->ID . '" data-items="' . json_encode($items) . '">' . $selection->post_title . ' (' . $selection->post_date . ')</option>';
      }
    }

    if ($this->config['useDateImport']) {
      // HTML and some JS to handle the results
      $html .= '
        <h2>Datum der Einträge neu generieren</h2>
        <select name="reimport-id">' . $options . '</select>
        <input type="button" class="button-primary" name="run-date-reimport" value="Re-Import starten" />
        <ul class="reimport-results-container"></ul>
        <script type="text/javascript">
          jQuery(function() {
            var nextIndex = -1;
            jQuery("[name=run-date-reimport]").on("click", function() {
              runNextDateConversion();
            });
            
            function runNextDateConversion() {
              nextIndex++;
              var items = jQuery("[name=reimport-id] option:selected").data("items");
              if (items.length >= nextIndex) {
                jQuery.post("/wp-admin/admin-ajax.php?action=reImportItemDate&id=" + items[nextIndex], function(response) {
                  jQuery(".reimport-results-container").append("<li>" + response.info + "</li>");
                  runNextDateConversion();
                });
              }
            }
          });
        </script>
      ';
    }

    if ($this->config['useDateReorder']) {
      // Classic reload form, to sort by date accordingly
      $html .= '
        <h2>' . $this->config['typeConfig']['singular'] . '-Einträge nach Datum neu sortieren</h2>
        <select name="resort-id">' . $options . '</select>
        <input type="submit" class="button-primary" name="runDateReSort" value="Sortierung starten" />
      ';
    }

    if (is_array($this->config['newsletter'])) {
      // Also print sending options
      $html .= '<h2>Newsletter versenden</h2>';
      if (strlen($this->generateMessage) > 0) {
        $html .= '<div class="updated notice notice-success"><p>' . $this->generateMessage . '</p></div>';
      }
      $html .= '
        <p><input type="text" name="newsletterSubject" placeholder="Betreffzeile hier eingeben (Leer lassen, wenn der Titel der ' . $this->config['typeConfig']['singular'] . ' verwendet werden soll)" style="width:70%;" /></p>
        <p>' . $this->getPublishedSelectionDropdown() . '</p>
        <input type="submit" class="button" name="generateAndWait" value="Generieren und auf Entwurf belassen" />
        <input type="submit" class="button-primary" name="generateAndSend" value="Generieren und Versenden" />
      ';
    }

    // Let developers add their own shit
    $html = apply_filters('LbwpSelection_import_ui_html_bottom', $html);

    echo '
      <div class="wrap">
        <h1 class="wp-heading-inline">Import & Versand</h1>
        <form action="" method="post">
          ' . $html . '
        </form>
        <br class="clear">
      </div>
    ';
  }

  /**
   * @return string html dropdown
   */
  protected function getPublishedSelectionDropdown()
  {
    $html = '<select name="selectionId">';
    $selections = get_posts(array(
      'post_type' => self::TYPE_SELECTION,
      'post_status' => array('publish', 'future')
    ));

    foreach ($selections as $selection) {
      $html .= '<option value="' . $selection->ID . '">' . $selection->post_title . ' (' . $selection->post_date . ')</option>';
    }

    $html .= '</select>';

    return $html;
  }

  /**
   * @param int $ts a timestamps
   * @return \WP_Post[]
   */
  protected function getRawAdsByTimestamp($ts, $status = 'publish')
  {
    // Get the natural ads of the day
    $ads = get_posts(array(
      'post_type' => self::TYPE_AD,
      'post_status' => $status,
      'meta_query' => array(
        array(
          'key' => 'dates',
          'value' => date('d.m.Y', $ts)
        )
      )
    ));

    // Get dynamic ads
    foreach ($this->getDynamicAds($ts, $status) as $ad) {
      $ads[] = $ad;
    }

    return $ads;
  }

  /**
   * Generate dynamic ads
   * @param $ts
   * @param $status
   * @return array
   */
  protected function getDynamicAds($ts, $status)
  {
    $ads = array();
    $candidates = get_posts(array(
      'post_type' => self::TYPE_AD,
      'post_status' => $status,
      'meta_query' => array(
        array(
          'key' => 'dates',
          'value' => 'syntax:',
          'compare' => 'LIKE'
        )
      )
    ));

    // Check the syntax of every candidate to decide if it needs to be shown
    foreach ($candidates as $candidate) {
      $syntax = get_post_meta($candidate->ID, 'dates', true);
      $type = substr($syntax, strpos($syntax, ':') + 1);
      $params = explode(',', substr($type, strpos($type, '(') + 1, -1));
      $type = substr($type, 0, strpos($type, '('));

      // Check for daily ads
      if ($type == 'daily') {
        $from = strtotime($params[0] . ' 00:00:00');
        if ($params[1] == 'now') {
          $to = strtotime(date('d.m.Y') . ' 23:59:59');
        } else {
          $to = strtotime($params[1] . ' 23:59:59');
        }
        // If $ts is between those, add the ad
        if ($from <= $ts && $to >= $ts) {
          $ads[] = $candidate;
        }
      }
    }

    return $ads;
  }

  /**
   * Full structured selection object
   * @param int $selectionId
   * @return array selection object with categorization
   */
  protected function getFullSelectionObject($selectionId)
  {
    $post = get_post($selectionId);
    // If it's in preview state, assume today as post date
    if ($post->post_status == 'draft') {
      $post->post_date = current_time('mysql');
    }
    // Previous and next selection links
    $prevUrl = get_permalink(get_adjacent_post(false, '', true)->ID);
    $nextUrl = get_permalink(get_adjacent_post(false, '', false)->ID);

    // Get todays ads
    $ads = array();
    $rawAds = $this->getRawAdsByTimestamp(strtotime($post->post_date), 'publish');

    // Convert them to a meaningful array
    foreach ($rawAds as $raw) {
      $position = get_post_meta($raw->ID, 'position', true);
      $term = substr($position, 0, strrpos($position, '-'));
      $position = intval(substr($position, strrpos($position, '-') + 1));
      $ads[$raw->ID] = array(
        'type' => 'type-sponsor',
        'content' => '<strong>Textanzeige:</strong> ' . $raw->post_content,
        'term' => $term,
        'position' => $position,
      );
    }

    // See if they are the current, if yes, reset the variable
    $parts = parse_url($prevUrl);
    if ($parts['path'] == $_SERVER['REQUEST_URI']) {
      $prevUrl = '';
    }
    $parts = parse_url($nextUrl);
    if ($parts['path'] == $_SERVER['REQUEST_URI']) {
      $nextUrl = '';
    }

    // Initiate the actual result
    $selection = array(
      'title' => date_i18n('l, j. F Y', strtotime($post->post_date)),
      'content' => $post->post_content,
      'prevUrl' => $prevUrl,
      'nextUrl' => $nextUrl,
      'categories' => array()
    );

    // Fill the categories in their order
    $categories = get_terms(array(
      'taxonomy' => self::TAX_ITEM_CATEGORY,
      'orderby' => 'description',
      'hide_empty' => false,
      'order' => 'ASC'
    ));

    // Get all items including their categories
    $items = array();
    $itemIds = get_post_meta($selectionId, 'news');
    foreach ($itemIds as $itemId) {
      $item = get_post($itemId);
      // Set source from url if not given
      $url = get_post_meta($itemId, 'url', true);
      $source = get_post_meta($itemId, 'source', true);
      if (Strlen($source) == 0) {
        $source = parse_url($url)['host'];
        $source = str_replace('www.', '', $source);
      }
      // Create the readable item object
      $items[$itemId] = array(
        'title' => $item->post_title,
        'type' => 'type-item',
        'content' => $item->post_content,
        'attachment' => get_the_post_thumbnail_url($itemId),
        'source' => $source,
        'redirect' => strlen($url) > 0 && !$this->config['useDirectLinks'] ? get_permalink($itemId) : $url,
        'term' => WordPress::getFirstTermSlug($itemId, self::TAX_ITEM_CATEGORY),
        'item' => $item
      );
    }

    // Fill the category objects
    foreach ($categories as $category) {
      // Create the initial element
      $selection['categories'][$category->slug] = array(
        'name' => $category->name,
        'articles' => array()
      );
      // Now add the items to the categories
      $count = 1;
      foreach ($items as $id => $item) {
        if ($category->slug == $item['term']) {
          $selection['categories'][$category->slug]['articles'][$id] = $item;
          ++$count;
        }
        // Also check the ads to be added
        foreach ($ads as $adId => $ad) {
          if ($count == $ad['position'] && $category->slug == $ad['term']) {
            $selection['categories'][$category->slug]['articles'][$adId] = $ad;
            unset($ads[$adId]);
            ++$count;
          }
        }

      }

      // Check if there are unpositioned ads for this category
      foreach ($ads as $adId => $ad) {
        if ($category->slug == $ad['term']) {
          $selection['categories'][$category->slug]['articles'][$adId] = $ad;
          unset($ads[$adId]);
        }
      }

      // If nothing has been added, remove the category for that selection
      if (count($selection['categories'][$category->slug]['articles']) == 0) {
        unset($selection['categories'][$category->slug]);
      }
    }

    return $selection;
  }

  /**
   * Display an icon of a certain type
   * @param $name
   * @return string
   */
  public static function icon($name)
  {
    return '<svg class="feather"><use xlink:href="' . get_template_directory_uri() . '/assets/_newsportals/assets/images/feather-sprite.svg#' . $name . '"/></svg>';
  }

  /**
   * @param int $selectionId internal selection id
   * @return string html template
   */
  public function getSelectionHtml($selectionId)
  {
    global $post;
    $preserve = $post;
    $html = '<div id="mfred-main-content" class="news-selection-container"><article id="content-start">';
    // Get a full features selection object to display
    $selection = $this->getFullSelectionObject($selectionId);

    // Print the header of our selection
    $html .= '
      <header>
        <h1>' . $this->icon('calendar') . ' ' . $selection['title'] . '</h1>
        ' . wpautop($selection['content']) . '
      </header>
    ';

    $html .= '<div class="categories-wrapper">';
    // Now create a section for each category with its articles
    foreach ($selection['categories'] as $term => $category) {
      $html .= '<section class="category-container category-' . $term . '">';
      $html .= '<div class="container-inner">';
      $html .= '<h2 class="category-label">' . $category['name'] . '</h2>';
      // And now print the articles
      foreach ($category['articles'] as $article) {
        // Make sure the global post is replaced with the item for share buttons
        $post = $article['item'];
        // Does it have an image, if yes, add container
        $image = '';
        if (isset($article['attachment']) && strlen($article['attachment']) > 0) {
          $image = '
            <div class="news-image-container">
              <a href="' . $article['redirect'] . '"><img src="' . $article['attachment'] . '" /></a>
            </div>
          ';
        }

        $footer = '';
        if ($article['type'] == 'type-item' && strlen($article['redirect']) > 0) {
          $footer = '
            <footer>
              <a class="source-link" href="' . $article['redirect'] . '" target="_blank">' . $this->icon('link') . ' ' . $article['source'] . '</a>
              ' . Buttons::get() . '
            </footer>
          ';
        }

        $html .= '
          <article class="news-item ' . $article['type'] . '">
            <div class="news-header-container">
            ' . $image . '
            <h3 class="eb-dev-v"><a href="' . $article['redirect'] . '" target="_blank">' . $article['title'] . '</a></h3>
            </div>
            <div class="news-content-container">
              <h3 class="eb-dev-nv"><a href="' . $article['redirect'] . '" target="_blank">' . $article['title'] . '</a></h3>
              ' . wpautop($article['content']) . '
              ' . $footer . '            
            </div>
          </article>
        ';
      }
      $html .= '</div></section>';
    }
    $html .= '</div>';

    // Print back and forward links to older or newer selections
    $html .= '<nav class="selection-nav">';
    if (strlen($selection['prevUrl']) > 0) {
      $html .= '<a class="prev-link" href="' . $selection['prevUrl'] . '">' . $this->icon('chevrons-left') . ' ' . $this->config['prevLinkText'] . '</a>';
    }
    if (strlen($selection['nextUrl']) > 0) {
      $html .= '<a class="next-link" href="' . $selection['nextUrl'] . '">' . $this->config['nextLinkText'] . ' ' . $this->icon('chevrons-right') . '</a>';
    }

    $post = $preserve;
    $html .= '</nav></article></div>';
    return $html;
  }

  /**
   * Extend the bookings UI with JS/Data
   */
  public function extendBookingsUI()
  {
    $db = WordPress::getDb();
    $map = array();
    $bookingIds = $db->get_col('SELECT ID FROM ' . $db->posts . ' WHERE post_type = "wc_booking" and post_author = ' . get_current_user_id());
    foreach ($bookingIds as $bookingId) {
      $map[$bookingId] = intval(get_post_meta($bookingId, '_ad_id', true));
    }

    echo '
      <script type="text/javascript">
        var adBookingMap = ' . json_encode($map) . ';
        // Handle the UI changes
        jQuery(function() {
          jQuery("th.booked-product").text("Anzeigebuchung (Klick zum Bearbeiten)");
          jQuery("th.booking-start-date").text("Datum");
          jQuery(".booking-end-date").remove();
          // Go trough each edit link
          jQuery("td.booked-product a").each(function() {
            var link = jQuery(this);
            // Get the booking id from the same row
            var bookingId = link.closest("tr").find(".booking-id").text();
            link.attr("href", "/mein-konto/anzeige-editor/?id=" + adBookingMap[bookingId]);
          })
        });
      </script>
    ';
  }

  /**
   * Get the editor for a single ad with back link
   */
  public function getAdEditorUI()
  {
    $ad = get_post(intval($_GET['id']));
    $ad->meta = WordPress::getAccessiblePostMeta($ad->ID);
    $date = get_post_meta($ad->ID, 'dates')[0];
    // Check for authorship, return if not owner
    if ($ad->post_author != get_current_user_id() && !current_user_can('administrator')) {
      return;
    }

    // Save the ad and override the content if given
    $message = '';
    if (isset($_POST['saveOnly'])) {
      $content = strip_tags($_POST['ad-editor'], '<strong><a><br>');
      $ad->post_content = $content;

      $errors = array();
      if (substr_count($content, '<strong') > 1) {
        $errors[] = 'Es wurde mehr als ein Bereich Fett markiert.';
      }
      if (substr_count($content, '<a ') > 1) {
        $errors[] = 'Der Text beinhaltet mehr als einen Link.';
      }
      if (str_word_count(strip_tags($content)) > 70) {
        $errors[] = 'Ihre Textanzeige ist zu lang.';
      }

      if (count($errors) > 0) {
        $message = 'Die Anzeige wurde aufgrund folgender Fehler nicht gespeichert:';
        foreach ($errors as $error) {
          $message .= '<br>- ' . $error;
        }
        $message = '<p class="lbwp-form-message error" style="background-color:#FF492D">' . $message . '</p>';
      } else {
        wp_update_post(array(
          'ID' => $ad->ID,
          'post_content' => $content,
          'post_status' => 'pending',
          'post_date' => Date::convertDate(Date::EU_DATE, Date::SQL_DATETIME, $date)
        ));
        // Also save post meta info
        update_post_meta($ad->ID, 'twitter-text', strip_tags($_POST['twitter-text']));
        update_post_meta($ad->ID, 'twitter-url', strip_tags($_POST['twitter-url']));

        // Set or reload data for display
        $ad->meta = WordPress::getAccessiblePostMeta($ad->ID);
        // Set a success message
        $message = '<p class="lbwp-form-message">Die Anzeige wurde gespeichert.</p>';
      }
    }

    echo '
      <a href="/mein-konto/bookings/" class="top-link">Zurück zu den Anzeigen</a>
      <h2>Anzeige für ' . $date . ' bearbeiten</h2>
      ' . $message . '
      <p>
        Mit diesem Formular können Sie Ihre Textanzeige selbst erfassen. Sie erscheint im Email-Newsletter und auf der Webseite.
        Änderungen sind bis 18 Uhr am Vortag der Veröffentlichung jederzeit möglich. 
        Bitte beachten Sie, dass wir die Anzeige gegenlesen und editieren oder nicht veröffentlichen, wenn sie nicht den Regeln entspricht.
      <p>
      <p><strong>Bitte beachten sie:</strong></p>
      <ul>
        <li>Ihre Anzeige darf maximal drei Zeilen einnehmen</li>
        <li>Es sind nur die HTML-Codes &lt;strong&gt; (fett) und &lt;a&gt; (Link) nutzbar</li>
        <li>Es darf nur ein Begriff, zum Beispiel der Produkt- oder Firmenname, fett markiert werden</li>
        <li>Die Anzeige kann nur einen Link enthalten</li>
      </ul>
      <form method="post">
        <textarea id="ad-editor" name="ad-editor">' . $ad->post_content . '</textarea>
        <label>
          <span>Text für Twitter (max. 280 Zeichen)</span>
          <textarea name="twitter-text">' . $ad->meta['twitter-text'] . '</textarea>
        </label>
        <label>
          <span>Ziel-Link für Twitter</span>
          <input type="text" name="twitter-url" value="' . esc_attr($ad->meta['twitter-url']) . '" />
        </label>
        <br><br>
        <input type="submit" class="button-primary" name="saveOnly" value="Speichern">
      </form>
      <script type="text/javascript" src="/wp-content/plugins/lbwp/resources/libraries/tinymce/5/tinymce.min.js"></script>
      <script type="text/javascript">
        tinymce.init({
          selector: \'textarea#ad-editor\',
          height: 150,
          menubar: false,
          plugins: [\'link preview searchreplace help wordcount\'],
          toolbar: \'undo redo | bold link removeformat | help\',
          forced_root_block : false,
          content_css: [
            \'//fonts.googleapis.com/css?family=Lato:300,300i,400,400i\',
          ]
        });
      </script>
    ';
  }

  /**
   * @param array $fields
   * @return array
   */
  public function alterBillingFieldConfig($fields)
  {
    $fields['billing_phone']['required'] = false; // make it unrequired
    return $fields;
  }

  /**
   * Send a notification to users whose ads are not yet created
   */
  public function sendUnfinishedAdNotification()
  {
    // Get a few base variables
    $from = current_time('timestamp') - (1 * 86400);
    $to = current_time('timestamp') + (4 * 86400);
    $name = get_bloginfo('name');
    $url = get_bloginfo('url');

    // Get all ads that are still in draft mode
    $drafts = get_posts(array(
      'post_type' => self::TYPE_AD,
      'posts_per_page' => -1,
      'post_status' => 'draft',
      'date_query' => array(
        'after' => array(
          'year' => date('Y', $from),
          'month' => date('n', $from),
          'day' => date('j', $from)
        ),
        'before' => array(
          'year' => date('Y', $to),
          'month' => date('n', $to),
          'day' => date('j', $to)
        )
      )
    ));

    $mails = array();

    // Loop and find the owner to send him an email
    foreach ($drafts as $ad) {
      $user = get_user_by('id', $ad->post_author);
      $mails[$user->user_email][] = $ad;
    }

    // Send a mail per user with all the infos
    foreach ($mails as $email => $ads) {
      $html = 'Guten Tag<br>
        <br>
        Folgende Anzeigen wurden noch nicht ausgefüllt und werden bald veröffentlicht:<br>
        <ul>
      ';

      foreach ($ads as $ad) {
        $html .= '
          <li><a href="' . $url . '/mein-konto/anzeige-editor/?id=' . $ad->ID . '" target="_blank">' . $ad->post_title . '</a></li>
        ';
      }

      $html .= '
        </ul>
        Werden die Anzeigen nicht rechtzeitig befüllt, werden diese nicht veröffentlicht.<br>
        <br>
        Freundliche Grüsse<br>
        ' . $name . '
      ';

      // Send the email
      $mail = External::PhpMailer();
      $mail->addAddress($email);
      $mail->Subject = $name . ' - Ausstehende Textanzeigen';
      $mail->Body = $html;
      $mail->AltBody = Strings::getAltMailBody($html);
      $mail->send();
    }
  }

  /**
   * Sends a notification with all reviewable ads
   */
  public function sendReviewableAdsNotification()
  {
    // Get all ads that are in review state
    $reviewables = get_posts(array(
      'post_type' => self::TYPE_AD,
      'posts_per_page' => -1,
      'post_status' => 'pending'
    ));

    // Skip if there's nothing to send
    if (count($reviewables) == 0) {
      return;
    }

    // initial parts
    $html = '
      Guten Tag<br>
      <br>
      Folgende Anzeigen wurden auf Review gestellt:<br>
      <ul>
    ';

    // Edit link and title for each ad
    $url = get_admin_url();
    foreach ($reviewables as $ad) {
      $html .= '
        <li><a href="' . $url . '/post.php?post=' . $ad->ID . '&action=edit" target="_blank">' . $ad->post_title . '</a></li>
      ';
    }

    // End parts
    $html .= '
      </ul>
      Bitte stellen Sie alle Anzeigen die in Ordnung sind auf "Planen" bzw. "Veröffentlichen" wenn am gleichen Tag,
      damit diese an Ihrem geplanten Datum live gehen können.<br>
      Sofern der Anzeigenkäufer noch einmal etwas ändert, bekommen Sie ein erneute Benachrichtigung zur Review.
    ';

    // Create the email
    $mail = External::PhpMailer();
    $mail->addAddress(get_option('admin_email'));
    $mail->Subject = get_bloginfo('name') . ' - Textanzeigen zur Review';
    $mail->Body = $html;
    $mail->AltBody = Strings::getAltMailBody($html);
    $mail->send();
  }

  /**
   * @param int $id a news element id
   */
  public function printNewsItemHtml($id)
  {
    $url = get_post_meta($id, 'url', true);
    // Attach our tracking parameters
    if (isset($this->config['utmSource']))
      $url = Strings::attachParam('utm_source', $this->config['utmSource'], $url);
    if (isset($this->config['utmCampaign']))
    $url = Strings::attachParam('utm_campaign', $this->config['utmCampaign'], $url);

    // For now, just redirect to the source with those params
    header('Location: ' . $url, null,302);
    exit;
  }

  /**
   * Redirect to the newest selection
   */
  public function redirectNewestSelection()
  {
    if ($_SERVER['REQUEST_METHOD'] == 'GET'  &&!is_admin()) {
      $selection = get_posts(array(
        'post_type' => self::TYPE_SELECTION,
        'posts_per_page' => 1
      ));

      header('Location: ' . get_permalink($selection[0]->ID), null, 303);
      exit;
    }
  }

  /**
   * Removes all orphaned news that have no parents anymore
   */
  public function removeOrphanNews()
  {
    // First, get all IDs of selections
    $db = WordPress::getDb();
    $selections = $db->get_col('SELECT ID FROM ' . $db->posts . ' WHERE post_type = "' . self::TYPE_SELECTION . '"');
    // Aggregate all the connected legitimate news ids
    $newsIds = array();
    foreach ($selections as $id) {
      $newsIds = array_merge($newsIds, get_post_meta($id, 'news'));
    }

    // Get all news items alphabetically to create a meaningful control table
    $news = $db->get_results('SELECT ID, post_title FROM ' . $db->posts . ' WHERE post_type = "' . self::TYPE_NEWS_ITEM . '" ORDER BY post_title ASC', ARRAY_A);
    // Display the table for controlling
    echo '<table width="100%">';
    foreach ($news as $item) {
      $state = 'parented, stay alive.';
      if (!in_array($item['ID'], $newsIds)) {
        $state = 'orphaned, kill.';
        wp_delete_post($item['ID'], true);
      }
      echo '
        <tr>
          <td>' . $item['ID'] . '</td>
          <td>' . $item['post_title'] . '</td>
          <td>' . $state . '</td>
        </tr>
      ';
    }
    echo '</table>';
    exit;
  }
}