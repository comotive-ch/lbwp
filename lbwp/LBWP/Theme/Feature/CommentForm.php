<?php

namespace LBWP\Theme\Feature;

use LBWP\Core as LbwpCore;
use LBWP\Module\Forms\Core as FormsCore;

/**
 * Providing a comment form with the possibilites of lbwp form tool
 * @package LBWP\Theme\Feature
 * @author Michael Sebel <michael@comotive.ch
 */
class CommentForm
{
  /**
   * @var string text domain slug for translations
   */
  protected $textDomain = 'lbwp';
  /**
   * @var CommentForm
   */
  protected static $instance = null;

  /**
   * Lock construction from outside
   */
  protected function __construct() {}

  /**
   * Initialize the object
   */
  public static function init()
  {
    if (self::$instance == null) {
      self::$instance = new CommentForm();
    }
  }

  /**
   * Set another text domain (from theme)
   */
  public static function setTextDomain($domain)
  {
    self::init();
    self::$instance->textDomain = $domain;
  }

  /**
   * @param array $args
   * @param null $postId
   */
  public static function show($args = array(), $postId = null)
  {
    self::init();
    self::$instance->displayForm($args, $postId);
  }

  /**
   * Displays the form3 comment form
   * $args accepts the same array as we can use for the default
   * WordPress comment form function. Some array keys have no function
   * with this form generation function.
   *
   * @param array $args
   * @param integer $postId
   */
  protected function displayForm($args, $postId = NULL)
  {
    if ($postId === null) {
      $postId = get_the_ID();
    }

    // If there are no comments allowed
    if (!comments_open($postId)) {
      return;
    }

    // Search the values
    $fields = array();
    $user = wp_get_current_user();
    $userIdentity = $user->exists() ? $user->display_name : '';
    $requireNameAndEmail = get_option('require_name_email');

    // Define the default values
    $requiredText = sprintf(' ' . __('Required fields are marked %s'), '<span class="required">*</span>');
    $defaults = array(
      'must_log_in' => '<p class="must-log-in">' . sprintf(__('You must be <a href="%s">logged in</a> to post a comment.'), wp_login_url(apply_filters('the_permalink', get_permalink($postId)))) . '</p>',
      'logged_in_as' => '<p class="logged-in-as">' . sprintf(__('Logged in as <a href="%1$s">%2$s</a>. <a href="%3$s" title="Log out of this account">Log out?</a>'), get_edit_user_link(), $userIdentity, wp_logout_url(apply_filters('the_permalink', get_permalink($postId)))) . '</p>',
      'comment_notes_before' => '<p class="comment-notes">' . __('Your email address will not be published.') . ($requireNameAndEmail ? $requiredText : '') . '</p>',
      'comment_notes_after' => '<p class="form-allowed-tags">' . sprintf(__('You may use these <abbr title="HyperText Markup Language">HTML</abbr> tags and attributes: %s'), ' <code>' . allowed_tags() . '</code>') . '</p>',
      'id_form' => 'commentform',
      'id_submit' => 'submit',

      'title_reply' => __('Leave a Reply'),
      'title_reply_to' => __('Leave a Reply to %s'),
      'cancel_reply_link' => __('Cancel reply'),
      'label_submit' => __('Post Comment'),
      'author' =>  __('Name', $this->textDomain),
      'email' =>  __('E-Mail-Adresse', $this->textDomain),
      'url' =>  __('Website', $this->textDomain),
      'comment' => __('Kommentar', $this->textDomain),
      // Our own settings
      'disableField_Website' => false,
      'addRequiredField' => false
    );

    $args = wp_parse_args($args, apply_filters('comment_form_defaults', $defaults));
    $args = apply_filters('comment_form_fix_fields', $args);

    // Display the title and text above form
    echo $args['title'];
    echo $args['comment_notes_before'];

    // Add required not field, if needed
    if ($args['addRequiredField'] !== false && strlen($args['addRequiredField']) > 0) {
      $fields[] = '[lbwp:formItem key="required-note" pflichtfeld="nein" text="' . $args['addRequiredField'] . '"]';
    }

    // Create the form
    if (is_user_logged_in()) {
      echo $args['logged_in_as'];
    } else {
      // Add the fields for a guest
      $fields[] = '[lbwp:formItem key="textfield" pflichtfeld="ja" type="text" id="author" feldname="' . $args['author'] . '"]';
      $fields[] = '[lbwp:formItem key="textfield" pflichtfeld="ja" type="email" id="email" feldname="' . $args['email'] . '"]';
      if (!$args['disableField_Website']) {
        $fields[] = '[lbwp:formItem key="textfield" pflichtfeld="nein" type="url" id="url" feldname="' . $args['url'] . '"]';
      }
    }

    // The comment field itself
    $fields[] = '[lbwp:formItem key="textarea" id="comment" pflichtfeld="ja" feldname="' . $args['comment'] . '" rows="8"]';
    // The calculation (not working at the moment
    //$fields[] = '[lbwp:formItem key="calculation" pflichtfeld="ja" feldname="Rechnung"]';

    // Generate form with all additional items
    $formHtml = '
      [lbwp:form button="' . $args['label_submit'] . '" id="comment" action="' . get_bloginfo('url') . '/wp-comments-post.php"]
        ' . implode(PHP_EOL, $fields) . '
        {additionalHtml}
      [/lbwp:form]
    ';

    $additionalHtml = apply_filters('lbwp_comment_form_html', '
      ' . get_comment_id_fields($postId) . '
    ');

    // Display the form
    $formHtml = do_shortcode($formHtml);
    $formHtml = str_replace('{additionalHtml}', $additionalHtml ,$formHtml);

    /** @var FormsCore $forms */
    $forms = LbwpCore::getModule('Forms');
    $forms->getFormHandler()->addFormAssets();

    echo $formHtml;
  }
}
