<?php

namespace LBWP\Theme\Component\ExternalTranslation;

use LBWP\Theme\Base\Component as BaseComponent;
use LBWP\Theme\Component\ExternalTranslation\Service\Base as TranslationService;
use LBWP\Util\External;
use LBWP\Util\File;
use LBWP\Core as LbwpCore;
use LBWP\Util\Multilang;

/**
 * The base class for creating meaningful onepager modules
 * @package LBWP\Theme\Component\Onepager
 * @author Michael Sebel <michael@comotive.ch>
 */
abstract class Core extends BaseComponent
{
  /**
   * This is an example configuration with documentation / variants to each variable
   * It must be overridden within the extended component of the customer
   * @var array example configuration
   */
  protected $config = array(
    /**
     * The basic service communication configuration
     */
    'service' => array(
      // This is the only service at the moment
      'id' => 'telelingua',
      'name' => 'Telelingua',
      // The API user name and password to the api
      'username' => 'email@organisation.com',
      'password' => '*****************',
      // The endpoint URL to use (Example is the actual sandbox api of telelingua)
      'endpoint' => 'https://extradev.telelingua.fr'
    ),
    /**
     * Configurates how translations are integrated into the instance
     */
    'integration' => array(
      // Importing translations by cron is the only method as of now
      'type' => 'cron',
      // The import cycle, can be daily or hourly or daily_multiple for the desperate people
      'cycle' => 'daily',
      // Can be a wordpress post status, be cautions with "publish"
      'translation_status' => 'pending',
      // Author gets a notification, whenever a translation is imported
      'notify_author' => true,
      // Additional fixed notifications, whenever a translation is imported
      'notify_emails' => array()
    ),
    /**
     * Configuration of various settings
     */
    'settings' => array(
      // Allow deletion of pending translations that are already in the works
      'allow_pending_deletion' => true
    )
  );

  /**
   * @var array the services that can be used at the moment
   */
  protected $services = array(
    'telelingua' => 'LBWP\Theme\Component\ExternalTranslation\Service\Telelingua'
  );

  /**
   * @var TranslationService instance of a translation service
   */
  protected $instance = NULL;

  /**
   * Actually loads the instance of the translation class
   */
  public function setup()
  {
    // Create a service instance
    $class = $this->services[$this->config['service']['id']];
    $this->instance = new $class($this->config);
    $this->instance->load();
    // Set the texts depending on language of the backend
    $this->setInterfaceTexts();
    // Run a basic controller method to react on params
    $this->controller();
    // Maybe start imports depending on configuration
    $this->handleImports();
  }

  /**
   * Add the actual interface texts (also prepared for JS output
   */
  protected function setInterfaceTexts()
  {
    $this->config['texts'] = array(
      'createTranslation' => __('Übersetzung beauftragen', 'lbwp'),
      'confirmTranslation' => sprintf(__('Sind Sie sicher, dass Sie die Übersetzung durch %s in Auftrag geben wollen?', 'lbwp'), $this->config['service']['name']),
      'translationInProgress' => sprintf(__('Dieser Inhalt wird im Moment durch %s übersetzt. Es sind keine Änderungen möglich.', 'lbwp'), $this->config['service']['name']),
      'translationAvailableForReview' => sprintf(__('Beitrags-Übersetzung von %s steht zur Review bereit', 'lbwp'), $this->config['service']['name']),
      'errorLanguageNotSupported' => sprintf(__('Fehler: %s bietet keine Übersetzungen in diese Sprache an.', 'lbwp'), $this->config['service']['name']),
      'articleAvailableForReview' => __('Die Übersetzung des Artikels "%s" wurde importiert:', 'lbwp'),
      'translationIsRequested' => __('Die Übersetzung wurde erfolgreich in Auftrag gegeben.', 'lbwp'),
      'currentUrl' => $_SERVER['REQUEST_URI'],
    );
  }

  /**
   * Print the config as a global json object
   */
  public function printConfigJsonObject()
  {
    echo '<script type="text/javascript">
      var LbwpExternalTranslationConfig = ' . json_encode($this->config) . ';
    </script>';
  }

  /**
   * Initialize the component after everything else has loaded
   */
  public function init()
  {
    // Print the interface texts and config, for when they are used in JS
    add_action('admin_head', array($this, 'printConfigJsonObject'));
    add_action('admin_head', array($this, 'checkForPostLock'));
    add_action('admin_notices', array($this, 'showMessages'));
    add_action('pre_trash_post', array($this, 'cancelTranslationRequest'), 10, 2);
  }

  /**
   * Executes actions based on url parameters
   */
  protected function controller()
  {
    // On requesting a translation to a specific language
    if (isset($_GET['extReqTranslation']) && strlen($_GET['extReqTranslation']) == 2 && $_GET['post'] > 0) {
      $this->createPostTranslation(intval($_GET['post']), $_GET['extReqTranslation']);
    }
  }

  /**
   * Handle the import of new translations
   */
  protected function handleImports()
  {
    if ($this->config['integration']['type'] == 'cron') {
      switch ($this->config['integration']['cycle']) {
        // Once daily at six in the morning
        case 'daily':
          add_action('cron_daily_6', array($this, 'importFinishedTranslations'));
          break;
        // Multiple times daily, but not too many times
        case 'daily_multiple':
          add_action('cron_daily_4', array($this, 'importFinishedTranslations'));
          add_action('cron_daily_10', array($this, 'importFinishedTranslations'));
          add_action('cron_daily_16', array($this, 'importFinishedTranslations'));
          break;
         // Every effing hour
        case 'hourly':
          add_action('cron_hourly', array($this, 'importFinishedTranslations'));
          break;
      }
    }
  }

  /**
   * @param int $postId the post to be translated
   * @param string $dest the destination language
   */
  protected function createPostTranslation($postId, $dest)
  {
    // Check if the language is valid and translatable by the service

    $source = Multilang::getPostLang($postId);
    if (!$this->instance->isSupportedLanguage($source) || !$this->instance->isSupportedLanguage($dest)) {
      // Redirect with an error message that the translation language is not supported
      set_transient(get_current_user_id() . '_ext_translate_error',
        $this->config['texts']['errorLanguageNotSupported']
      );
      header('Location: ' . get_edit_post_link($postId, 'raw'));
      exit;
    }

    // Get the source post
    $sourcePost = get_post($postId);

    // All is well, first, create an empty post for the translation
    $newPostId = wp_insert_post(array(
      'post_title' => 'translation in progress: ' . $sourcePost->post_title,
      'post_name' => 'ext-translation-' . md5($sourcePost->post_title),
      'post_status' => 'draft',
      'post_author' => get_current_user_id(),
      'post_type' => $sourcePost->post_type
    ));

    // Now assign the destination language and merge the post with the source
    Multilang::setPostLanguage($newPostId, $dest);
    Multilang::addPostTranslation($postId, $newPostId, $dest);

    // Set a post lock, so the post won't be editable
    $this->setPostLock($newPostId);

    // Request a translation and save the reference ID
    $requestId = $this->instance->requestTranslation($sourcePost, $source, $dest);
    update_post_meta($newPostId, 'external_translation_id', $requestId);

    // Set the success message
    set_transient(get_current_user_id() . '_ext_translate_success',
      $this->config['texts']['translationIsRequested']
    );
    header('Location: ' . get_edit_post_link($postId, 'raw'));
    exit;
  }

  /**
   * @param int $postId the post that should be locked
   */
  protected function setPostLock($postId)
  {
    update_post_meta($postId, '_external_translation_lock', 1);
  }

  /**
   * @param int $postId the post that should be unlocked
   */
  protected function raisePostLock($postId)
  {
    delete_post_meta($postId, '_external_translation_lock');
  }

  /**
   * Check if the post needs to be locked
   */
  public function checkForPostLock()
  {
    // If on backend edit page of a post, maybe add locking
    if (is_admin() && isset($_GET['post']) && $_GET['action'] == 'edit') {
      $postId = intval($_GET['post']);

      // See if we need to call the ambulance
      if (get_post_meta($postId, '_external_translation_lock', true) == 1) {
        echo '
          <script type="text/javascript">
            jQuery(function() { LbwpExternalTranslation.lockPost(); });
          </script>
        ';
      }
    }
  }

  /**
   * Shows messages that have been registerd with a transient
   */
  public function showMessages()
  {
    foreach (array('error', 'success') as $type) {
      $message = get_transient(get_current_user_id() . '_ext_translate_' . $type);
      if (strlen($message) > 0) {
        delete_transient(get_current_user_id() . '_ext_translate_' . $type);
        break;
      }
    }

    // Print a message, if given
    if (strlen($message) > 0 && strlen($type)) {
      printf('<div class="%1$s"><p>%2$s</p></div>',
        'notice notice-' . $type . ' is-dismissible',
        $message
      );
    }
  }

  /**
   * Imports finished translations and sends info mails to author (or multiple other people)
   */
  public function importFinishedTranslations()
  {
    // Get all posts that have an external translation id and are in the expected draft mode
    $checkablePosts = get_posts(array(
      'post_type' => 'any',
      'post_status' => 'draft',
      'lang' => 'all',
      'posts_per_page' => -1,
      'meta_query' => array(
        array(
          'key' => 'external_translation_id',
          'compare' => 'EXISTS'
        )
      )
    ));

    // Import them all one by one
    foreach ($checkablePosts as $post) {
      $this->importPostTranslation($post);
    }
  }

  /**
   * Import translation of a post, if available
   * @param \WP_Post $post the post to be imported
   */
  protected function importPostTranslation($post)
  {
    $translationId = intval(get_post_meta($post->ID, 'external_translation_id', true));

    // Try receiving the importable data from the service
    if ($this->instance->receiveTranslation($post->ID, $translationId)) {
      delete_post_meta($post->ID, '_external_translation_lock');
      $this->sendImportCompletedNotifications($post->ID);
      delete_post_meta($post->ID, 'external_translation_id');
    }
  }

  /**
   * Sends import notifications in the configured way
   * @param int $postId the post to inform of
   */
  protected function sendImportCompletedNotifications($postId)
  {
    // First get the email addresses that need to be notificed
    $mail = External::PhpMailer();
    $translatedPost = get_post($postId);

    // Basic, fixed emails, if given
    foreach ($this->config['integration']['notify_emails'] as $email) {
      $mail->addAddress($email);
    }

    // If author needs to get an email as well, add it
    if ($this->config['integration']['notify_author']) {
      $authorId = intval($translatedPost->post_author);
      if ($authorId > 0) {
        $user = get_user_by('id', $authorId);
        $mail->addCC($user->get('user_email'));
      }
    }

    // Send the email as simple html email, if there are recipients
    if (count($mail->getAllRecipientAddresses()) > 0) {
      $url = get_edit_post_link($translatedPost->ID, 'raw');
      $mail->Subject = $this->config['texts']['translationAvailableForReview'];
      $mail->Body = '
        ' . sprintf($this->config['texts']['articleAvailableForReview'], $translatedPost->post_title) . '<br />
        <br />
        <a href="' . $url . '">' . $url . '</a>
      ';
      // Send the mail
      $mail->send();
    }
  }

  /**
   * Checks if the post has a pending translation and tries to cancel it
   * @param bool $check can be changed to false in order to not trash the post
   * @param \WP_Post $post the post to be trashed
   * @return bool the translation id
   */
  public function cancelTranslationRequest($check, $post)
  {
    $translationId = intval(get_post_meta($post->ID, 'external_translation_id', true));
    // Only do something, if there is a translation id
    if ($translationId > 0) {
      // If cancellation fails, prevent trashing the item
      if (!$this->instance->cancelTranslationRequest($post->ID, $translationId)) {
        $check = false;
      }
    }

    return $check;
  }

  /**
   * Enqueue some admin only assets (few css and js)
   */
  public function adminAssets()
  {
    $base = File::getResourceUri();
    wp_enqueue_style('external-translation', $base . '/css/external-translation/backend.css', array(), LbwpCore::REVISION);
    wp_enqueue_script('external-translation', $base . '/js/external-translation/backend.js', array('jquery'), LbwpCore::REVISION, true);
  }
}