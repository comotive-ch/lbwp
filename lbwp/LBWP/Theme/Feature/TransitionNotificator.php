<?php

namespace LBWP\Theme\Feature;

use LBWP\Core as LbwpCore;
use LBWP\Util\External;
use LBWP\Util\File;

/**
 * Has the ability to notify users upon the change of a status
 * @author Michael Sebel <michael@comotive.ch>
 * @package LBWP\Theme\Feature
 */
class TransitionNotificator
{
  /**
   * This defines an example, that is always completely overridden on init
   * ---
   * Variables for templates:
   * {postTitle} = The post title that has been changed
   * {authorName} = The name of the author who made the change
   * {postLink} =  Full <a> tag with link to the article in backend
   * ---
   * @var array Contains all configurations
   */
  protected $options = array(
    'use_ui' => false,
    'allow_same_state_notifications' => false,
    'send_type' => self::SEND_TYPE_ALL,
    'base_configuration' => array(),
  );
  /**
   * @var array the settings array
   */
  protected $settings = array();
  /**
   * @var ResponsiveWidgets the instance
   */
  protected static $instance = NULL;
  /**
   * Sending types (Random is not implemented yet)
   */
  const SEND_TYPE_ALL = 1;
  const SEND_TYPE_RANDOM_SINGLE = 2;

  /**
   * Can only be called within init
   */
  protected function __construct($options)
  {
    $this->options = array_merge($this->options, $options);
    // Use UI config or fallback to code config, if given
    $this->settings = get_option('lbwpTransitionNotificator', $this->options['base_configuration']);

    // Everythign happens in admin only here
    if (is_admin() && count($this->settings) > 0) {
      add_filter('transition_post_status', array($this, 'sendNotifications'), 10, 3);
    }
  }

  /**
   * @param string $new status after change
   * @param string $old status before
   * @param \WP_Post $post the post object being modified
   * @return bool always true
   */
  public function sendNotifications($new, $old, $post)
  {
    // If same and no same state notifications are wanted, skip and exit function
    if (!$this->options['allow_same_state_notifications'] && $new == $old) {
      return true;
    }

    foreach ($this->settings as $notification) {
      // Test if the notification has to take place
      if (
        ($notification['from'] == $old || $notification['from'] == 'any') &&
        ($notification['to'] == $new || $notification['to'] == 'any')
      ) {
        // Get the users we need to send to
        $addresses = $this->getNotificationAddresses($notification['users']);

        // Prepare the template for the mailing
        $subject = $notification['subject'];
        $body = $this->prepareTemplate($notification['template'], $post);
        // Send the mail
        $this->sendEmail($subject, $body, $addresses);
      }
    }

    return true;
  }

  /**
   * @param string $subject
   * @param string $body
   * @param array $emails
   * @return bool
   */
  protected function sendEmail($subject, $body, $emails)
  {
    $mail = External::PhpMailer();
    $mail->Subject = $subject;
    $mail->Body = $body;
    // Add the emails
    foreach ($emails as $email) $mail->addBCC($email);
    // Send the mail
    return $mail->send();
  }

  /**
   * @param array $users a list of user ids
   * @return array a list of email addresses
   */
  protected function getNotificationAddresses($users)
  {
    switch ($this->options['send_type']) {
      case self::SEND_TYPE_ALL:
        break; // Nothing to do, as we send to all
      case self::SEND_TYPE_RANDOM_SINGLE:
        $users = array($users[mt_rand(0, count($users) - 1)]);
        break;
    }

    // Get the email addresses of the given users
    $addresses = array();
    foreach ($users as $userId) {
      $addresses[] = get_user_by('ID', $userId)->user_email;
    }

    return $addresses;
  }

  /**
   * @param string $template the template
   * @param \WP_Post $post infos about the post
   * @return string a finished mailing template
   */
  protected function prepareTemplate($template, $post)
  {
    $link = get_edit_post_link($post->ID, '&');
    $template = str_replace('{postTitle}', $post->post_title, $template);
    $template = str_replace('{postLink}', '<a href="' . $link . '">' . $link . '</a>', $template);
    $template = str_replace('{authorName}', get_the_author_meta('display_name', $post->post_author), $template);

    return $template;
  }

  /**
   * @param array $options load the given options
   */
  public static function init($options = array())
  {
    self::$instance = new TransitionNotificator($options);
  }
}
