<?php

namespace LBWP\Newsletter\Component;

use LBWP\Util\External;
use WP_Post;
use LBWP\Core;
use LBWP\Util\WordPress;
use LBWP\Util\Date;
use LBWP\Helper\Metabox;
use LBWP\Module\General\CronHandler;
use LBWP\Newsletter\Component\Base;
use LBWP\Newsletter\Service\Base as ServiceBase;
use LBWP\Newsletter\Template\Base as TemplateBase;

/**
 * This class handles the newsletter post type
 * @package LBWP\Newsletter\Component
 * @author Michael Sebel <michael@comotive.ch>
 */
class TypeNewsletter extends Base
{
  /**
   * @var array the types allowed to use as newsletter sources
   */
  protected $sourceTypes = array(
    'post',
    'lbwp-nl-item'
  );

  /**
   * Called after component construction
   */
  public function load() { }

  /**
   * Called at init(50)
   */
  public function initialize()
  {
    // Only add the type, if there is a working service
    $service = $this->core->getService();

    // Check if the service is valid and working
    if ($service instanceof ServiceBase && $service->isWorking()) {
      // Add post type and meta fields
      $this->addPostType();
      // Add the subscribe and unsubscribe actions
      add_action('cron_hourly', array($this, 'scheduleNewsletter'));

      // Those hooks are only for the backend
      if (is_admin()) {
        add_action('add_meta_boxes', array($this, 'addDynamicMetaFields'), 10, 2);
        add_action('wp_ajax_newsletterSendPreview', array($this, 'sendTestMail'));
        add_action('admin_init', array($this, 'addMetaFields'));
        add_action('save_post', array($this, 'addSaveableFields'), 10, 2);
        add_action('post_submitbox_misc_actions', array($this, 'changeSubmitBox'));
        add_filter('post_row_actions', array($this, 'removeQuickEdit'));
      }
    }
  }

  /**
   * @param array $actions the post row actions
   * @return array the altered actions
   */
  public function removeQuickEdit($actions)
  {
    $post = WordPress::getPost();

    if ($post->post_type == 'lbwp-nl') {
      unset($actions['inline hide-if-no-js']);
    }

    return $actions;
  }

  /**
   * Changes the submit box after a newsletter is sent to the service
   */
  public function changeSubmitBox()
  {
    $post = WordPress::getPost();

    if ($post->post_type == 'lbwp-nl') {
      // Check if the newsletters sent flag needs to be reset
      $this->checkSendReset($post);

      // Check if the hourly cron must be executed immediately
      $this->checkSendImmediately($post);

      // Get the newsletter (whose data ight be altered previously)
      $newsletter = $this->getNewsletter($post->ID);

      // If the newsletter is already sent, add some css/js do alter the box
      if ($newsletter->sent == 1) {
        echo '
          <script type="text/javascript">
            jQuery(function() {
              jQuery("#submitpost a").remove();
              jQuery("#major-publishing-actions").remove();
              // Create info
              var info = jQuery("<div/>");
              info.addClass("misc-pub-section");
              info.css("font-weight", "bold");
              info.text("Der Newsletter wurde bereits an den Dienst gesendet und eingeplant.");
              var link = jQuery("<div/>");
              link.addClass("misc-pub-section");
              link.html(\'<a href="\' + document.location.href + \'&resetSent">Zurücksetzen und erneut einplanen</a>\');
              jQuery("#misc-publishing-actions").after(link).after(info);
            });
          </script>
        ';
      }
    }
  }

  /**
   * This will execute the hourly cron if needed and redirect back
   * @param \WP_Post $post the post
   */
  protected function checkSendImmediately($post)
  {
    if (isset($_GET['sendImmediately'])) {
      /** @var CronHandler $cronHandler */
      $cronHandler = new CronHandler();
      $cronHandler->executeCron('hourly');
      // And redirect back to the page
      header('Location: /wp-admin/post.php?post=' .$post->ID  . '&action=edit');
      exit;
    }
  }

  /**
   * Checks if the sent flag needs to be resetted
   * @param \WP_Post $post the post
   */
  protected function checkSendReset($post)
  {
    if (isset($_GET['resetSent'])) {
      delete_post_meta($post->ID, 'sent');
      // Save manually to not call save_post
      $this->wpdb->update(
        $this->wpdb->posts,
        array(
          'post_status' => 'draft',
          'post_date_gmt' => '',
          'post_date' => current_time('mysql')
        ),
        array(
          'ID' => $post->ID
        )
      );

      // And make sure to flush the cache
      clean_post_cache($post->ID);

      // And redirect back to the page
      header('Location: /wp-admin/post.php?post=' .$post->ID  . '&action=edit');
      exit;
    }
  }

  /**
   * Adds the post type
   */
  protected function addPostType()
  {
    WordPress::registerType('lbwp-nl', 'Newsletter', 'Newsletter', array(
      'show_in_menu' => 'newsletter',
      'publicly_queryable' => false,
      'exclude_from_search' => true,
      'supports' => array('title')
    ), 'n');
  }

  /**
   * Adding meta fields to the type
   */
  public function addMetaFields()
  {
    // Get some help :-)
    $helper = Metabox::get('lbwp-nl');

    // Metabox for settings
    $boxId = 'newsletter-settings';
    $helper->addMetabox($boxId, 'Einstellungen');
    $helper->addInputText('mailSubject', $boxId, 'Betreff', array('required' => true));
    $helper->addInputText('mailSender', $boxId, 'E-Mail Absender', array('required' => true));
    $helper->addInputText('mailSenderName', $boxId, 'Absender-Name', array('required' => true));

    // Template selection
    $boxId = 'newsletter-template';
    $helper->addMetabox($boxId, 'Newsletter Design');
    $helper->addField(
      'templateId',
      $boxId,
      array(),
      array($this, 'displayNewsletterTemplates'),
      array($this, 'saveNewsletterTemplate')
    );

    // Metabox for post assignation
    $boxId = 'newsletter-items';
    $helper->addMetabox($boxId, 'Beiträge auswählen');
    $helper->addAssignPostsField('newsletterItems', $boxId, $this->sourceTypes);

    // Info box because we schedule sendings at xx:31 with cron
    $boxId = 'newsletter-sendinfo';
    $helper->addMetabox($boxId, 'Versand Information', 'side');

    $helper->addField(
      'sendInfo',
      $boxId,
      array(),
      array($this, 'displaySendInfo'),
      '__return_false'
    );

    $boxId = 'newsletter-sendtest';
    $helper->addMetabox($boxId, 'Test-Versand', 'side');
    // Box to mail the current newsletter as a test to an email
    $helper->addField(
      'sendTest',
      $boxId,
      array(),
      array($this, 'displayTestForm'),
      '__return_false'
    );
  }

  /**
   * Adds metaboxes and fields
   * @param string $type the post type
   * @param WP_Post $post the post
   */
  public function addDynamicMetaFields($type, $post)
  {
    // Add the fields
    $this->addItemFields($post, true);
  }

  /**
   * This adds the metabox field so that they're saved
   * @param int $postId the saved posts id
   * @param WP_Post $post the post
   */
  public function addSaveableFields($postId, $post)
  {
    $this->addItemFields($post, false);
  }

  /**
   * @param WP_Post $post the post
   * @param bool $setDefaults set the defaults
   */
  protected function addItemFields($post, $setDefaults)
  {
    // For each type, add metaboxes to overwrite text/title
    foreach ($this->sourceTypes as $postType) {
      // Skip the NL item, which has it's own fields
      if ($postType == 'lbwp-nl-item') {
        continue;
      }

      // Get the helper for the post type
      $helper = Metabox::get($postType);

      // Defaults
      $defaults = array(
        'title' => array('default' => ''),
        'text' => array('default' => '')
      );

      if ($setDefaults) {
        // Overwrite with title, if available
        if (strlen($post->post_title) > 0) {
          $defaults['title']['default'] = $post->post_title;
        }

        // Overwrite the text with the excerpt
        if (strlen($post->post_excerpt) > 0) {
          $defaults['text']['default'] = $post->post_excerpt;
        }
      }

      $boxId = 'newsletter-item-' . $postType;
      $helper->addMetabox($boxId, 'Texte für Newsletter', 'side');
      $helper->addInputText('newsletterTitle', $boxId, 'Titel', $defaults['title']);
      $helper->addTextarea('newsletterText', $boxId, 'Text', 150, $defaults['text']);
    }
  }

  /**
   * @return string html code with the sending information
   */
  public function displaySendInfo()
  {
    $post = WordPress::getPost();
    return '
      <p>
        Versände werden immer etwa zu jeder halben Stunde (zum Beispiel 9:30 Uhr oder 10:30 Uhr) verarbeitet. Wenn
        Sie sofort veröffentlichen, oder in unter einer halben Stunde einplanen, wird der Newsletter unter Umständen
        bis zu einer Stunde später versandt als geplant.
      </p>
      <p><em>
        Um sicher zu gehen, dass der Versand pünktlich erfolgt, planen Sie den Newsletter am besten ungefähr
        einen Tag oder mindestens zwei Stunden vorher ein.
      </em></p>
      <p>
        <a href="' . get_edit_post_link($post->ID) . '&sendImmediately">Geplante Newsletter jetzt versenden</a>
      </p>
    ';
  }

  /**
   * This will at the unsubscribe and subscribe actions to the form tool
   * @param array $actions list of current actions
   * @return array altered $actions array with new actions
   */
  public function addFormActions($actions)
  {
    // Add the two actions and return
    $actions['newsletter-subscribe'] = '\LBWP\Module\Forms\Action\Newsletter\Subscribe';
    $actions['newsletter-unsubscribe'] = '\LBWP\Module\Forms\Action\Newsletter\Unsubscribe';

    return $actions;
  }

  /**
   * @param array $args the arguments of the field
   * @return string html code to represent the newsletter templates
   */
  public function displayNewsletterTemplates($args)
  {
    $html = '<p>Ihnen stehen folgende Designs zur Auswahl. Zur Vorschau bitte speichern und dann Vorschau ansehen.</p>';

    // Get the currently selected template, to make a preselection later
    $postId = intval($args['post']->ID);
    $templateId = $this->get($postId, 'templateId');

    // Get all the registered templates
    $templates = $this->core->getTemplating()->getTemplates();
    foreach ($templates as $id => $template) {
      // Check if it's the current one
      $checked = checked($id, $templateId, false);

      // Create HTML box
      $html .= '
        <div class="image-chooser">
          <input type="radio" name="templateId" value="' . $id . '" id="tpl_' . $id . '"' . $checked . ' />
          <label for="tpl_' . $id . '">' . $template->getName() . '</label>
          <div class="option-name">

          </div>
          <div class="selector">

          </div>
          <div class="image-option">
            <label for="tpl_' . $id . '">
              <img src="' . $template->getScreenshot() . '" title="' . $template->getName() . '" width="150" height="150" />
            </label>
          </div>
        </div>
      ';
    }

    // Add a little javascript
    $html .= '
      <script type="text/javascript">
        jQuery(function() {
          jQuery(".image-chooser").parent().css("overflow", "auto");
        });
      </script>
    ';

    return $html;
  }

  /**
   * Callback to save the template
   * @param int $postId the id of the post to save to
   * @param array $field all the fields information
   * @param string $boxId the metabox id
   */
  public function saveNewsletterTemplate($postId, $field, $boxId)
  {
    $templateId = $_POST['templateId'];

    // Save it empty, if it doesn't exist
    if (!$this->core->getTemplating()->templateExists($templateId)) {
      $templateId = '';
    }

    // Save the template
    $this->set($postId, $field['key'], $templateId);
  }

  /**
   * This is called in hourly cron and schedules planned newsletters by sending them
   * to the service and scheduling them there.
   */
  public function scheduleNewsletter()
  {
    $newsletters = $this->getScheduleableNewsletters();

    foreach ($newsletters as $newsletter) {
      // Get the template and the schedule time
      $type = $this->core->getTypeNewsletter();
      $newsletter = $type->getNewsletter($newsletter->ID);
      $time = strtotime($newsletter->post_date_gmt);

      // Load the template engine and enerate html/text version
      $templating = $this->core->getTemplating();
      $template = $templating->getTemplate($newsletter->templateId);
      $html = $type->renderNewsletter($template, $newsletter, 'html');
      $text = $type->renderNewsletter($template, $newsletter, 'text');

      // Create and/or schedule the mailing
      $service = $this->core->getService();
      $service->createMailing($html, $text, $newsletter, $time);

      // Mark the newsletter as sent and ave the mailing id
      update_post_meta($newsletter->ID, 'sent', 1);
    }
  }

  /**
   * Gets all unsent newsletters planned in the next two hours
   * @return array of scheduleable newsletters
   */
  protected function getScheduleableNewsletters()
  {
    // Calculate the search time
    $stampAfter = current_time('timestamp') - (14 * 24 * 3600);
    $stampBefore = current_time('timestamp') + (2 * 3600);

    // Do the query
    $newsletters = get_posts(array(
      'post_type' => 'lbwp-nl',
      'post_status' => array('publish', 'future'),
      'meta_query' => array(
        array(
          'key' => 'sent',
          'compare' => 'NOT EXISTS', // works!
          'value' => 'bogus' // Ignored, but is necessary.
        ),
      ),
      'date_query' => array(
        array(
          'after' => Date::getTime(Date::SQL_DATETIME, $stampAfter),
          'before' => Date::getTime(Date::SQL_DATETIME, $stampBefore),
          'inclusive' => true,
        ),
      )
    ));

    return $newsletters;
  }

  /**
   * @param TemplateBase $template the template
   * @param \stdClass $newsletter the newsletter
   * @param string $type html or text
   * @return string the newsletter html code
   */
  public function renderNewsletter($template, $newsletter, $type)
  {
    // Render the base HTML and return it
    $result = '';
    switch ($type) {
      case 'html':
        $result = $template->renderNewsletter($newsletter);
        $result = $template->convertServiceVars($result);
        break;
      case 'text':
        $result = $template->renderText($newsletter);
        break;
    }

    // If there is a service, translate general to service vars
    return $result;
  }

  /**
   * @param int $newsletterId the newsletter id
   * @return \WP_Post the newsletter including metadata
   */
  public function getNewsletter($newsletterId)
  {
    $newsletter = get_post($newsletterId);

    // Add all metadata
    $newsletter->mailSubject = $this->get($newsletterId, 'mailSubject');
    $newsletter->mailSender = $this->get($newsletterId, 'mailSender');
    $newsletter->mailSenderName = $this->get($newsletterId, 'mailSenderName');
    $newsletter->templateId = $this->get($newsletterId, 'templateId');
    $newsletter->newsletterItems = $this->get($newsletterId, 'newsletterItems');
    $newsletter->sent = intval($this->get($newsletterId, 'sent'));

    return $newsletter;
  }

  /**
   * Sending a testmail to emailAdress for the given newsletter
   */
  public function sendTestMail()
  {
    $newsletterId = intval($_POST['newsletterId']);
    $email = $_POST['emailAddress'];

    // Get the preview to generate the mail
    $html = $this->core->getPreviewComponent()->renderPreview($newsletterId);
    $mail = External::PhpMailer();
    $mail->Subject = '[Test] ' . $this->get($newsletterId, 'mailSubject');
    $mail->Body = $html;
    $mail->AddAddress($email);

    // Send a simple mail
    WordPress::sendJsonResponse(array(
      'success' => $mail->Send()
    ));
  }

  /**
   * @return string html code to display a form to send a mail
   */
  public function displayTestForm()
  {
    $html = '
      <div id="testSending">
        <p>E-Mail Adresse für den Testversand:</p>
        <input type="text" id="testSendEmail"> <br />
        <input type="button" class="button-primary" id="sendTest" value="Test senden" />
      </div>
      <script type="text/javascript">
        jQuery(function() {
          jQuery("#sendTest").click(function() {
            var data = {
              action : "newsletterSendPreview",
              newsletterId : ' . intval($_GET['post']) . ',
              emailAddress : jQuery("#testSendEmail").val()
            };

            jQuery.post(ajaxurl, data, function(response) {
              if (response.success) {
                jQuery("#sendTest").val("Mail versendet");
              } else {
                jQuery("#sendTest").val("Da ging was schief");
              }
            });
          });
        });
      </script>
      <style type="text/css">
        #testSendEmail {
          margin:0px 0px 10px 0px;
          padding:5px;
          width:100%;
        }
      </style>
    ';

    return $html;
  }

  /**
   * @param int $newsletterId id of the newsletter
   * @param string $field the field name
   * @return mixed whatever is saved in the field
   */
  public function get($newsletterId, $field)
  {
    return get_post_meta($newsletterId, $field, true);
  }

  /**
   * @param int $newsletterId the newsletter id
   * @param string $field the field name
   * @param mixed $value whatevery primitive data you need to store
   */
  public function set($newsletterId, $field, $value)
  {
    update_post_meta($newsletterId, $field, $value);
  }
} 