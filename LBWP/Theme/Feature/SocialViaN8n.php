<?php

namespace LBWP\Theme\Feature;

use LBWP\Helper\Cronjob;
use LBWP\Util\ArrayManipulation;
use LBWP\Util\WordPress;

/**
 * Provides a post type for socials and auto triggering to n8n
 * @author Michael Sebel <michael@comotive.ch>
 * @package LBWP\Theme\Feature
 */
class SocialViaN8n
{
  /**
   * @var FocusPoint the instance
   */
  protected static $instance = NULL;
  /**
   * @var bool
   */
  protected static $initialized = false;
  /**
   * For compat reasons from SocialViaZapier, same type slug
   * @var string
   */
  const TYPE_SLUG = 'lbwp-zap-social';
  /**
   * @var array configuration defaults
   */
  protected $config = array(
    'n8nWebhookUrl' => '',
    'channels' => array(
      'facebook_page' => false, 
      'linkedin_company' => false,
      'linkedin_personal' => false,
      'google_business' => false,
      'instagram' => false,
      'threads' => false,
      'mastodon' => false,
      'bluesky' => false,
      'xcom' => false,
      'youtube' => false,
      'pinterest' => false
    )
  );

  /**
   * @var string[] the channel map
   */
  protected $channelMap = array(
    'facebook_page' => 'Facebook Page',
    'linkedin_company' => 'Linkedin Firmenprofil',
    'linkedin_personal' => 'Linkedin persönliches Profil',
    'google_business' => 'Google Business Profil',
    'instagram' => 'Instagram',
    'threads' => 'Threads',
    'mastodon' => 'Mastodon',
    'bluesky' => 'Bluesky',
    'xcom' => 'X.com',
    'youtube' => 'YouTube',
    'pinterest' => 'Pinterest'
  );

  /**
   * Can only be called within init
   */
  protected function __construct($options)
  {
    $this->config = ArrayManipulation::deepMerge($this->config, $options);
  }

  /**
   * @return SocialViaN8n the mail service instance
   */
  public static function getInstance()
  {
    return self::$instance;
  }

  /**
   * @param array $options load the given options
   */
  public static function init($options = array())
  {
    self::$instance = new SocialViaN8n($options);
    self::$instance->initialize();
  }

  /**
   * Called inline on loading, registers needed hooks / js
   */
  public function initialize()
  {
    // Only run and register filters once
    if (self::$initialized) {
      return;
    }

    // Set as initialized
    self::$initialized = true;

    $this->addPostType();
    // Add fields to post type
    add_action('acf/include_fields', array($this, 'addCustomFields'));
    add_action('transition_post_status', array($this, 'planN8nTriggers'), 10, 3);
    add_action('cron_job_lbwp_n8n_social_send', array($this, 'sendSocialPost'), 10, 2);
  }

  /**
   * @param string $new new status (only do something if future)
   * @param string $old old status (ignored)
   * @param \WP_Post $post the post object beeing transitioned
   */
  public function planN8nTriggers($new, $old, $post)
  {
    // If the article is in future and is allowed
    if ($post->post_type == self::TYPE_SLUG && ($new == 'future' || ($new == 'publish' && $old != 'publish'))) {
      // Get the configuration when social posts should be sent
      $crons = array();
      $fallback = current_time('timestamp');
      $channels = get_field('n8n-social-channels', $post->ID);
      foreach ($channels as $channel) {
        $channelName = $channel['channel'];
        $time = $channel['timed'] ? $channel['datetime'] + (mt_rand(1,30)) : $fallback;
        // Schedule the cronjob
        $crons[$time] = 'lbwp_n8n_social_send::' . $post->ID . '-' . $channelName;
      }

      if (count($crons) > 0) {
        Cronjob::register($crons);
      }
    }
  }

  /**
   * @return void
   */
  public function sendSocialPost()
  {
    list($postId, $channelId) = explode('-', $_GET['data']);
    // Get the url and eventually add utm parameters
    $url = get_field('url', $postId);
    if (get_field('utm_parameters', $postId)) {
      $url .= (strpos($url, '?') === false ? '?' : '&') . 'utm_source=' . $channelId . '&utm_medium=social&utm_campaign=post_' . $postId;
    }

    $data = array(
      'id' => $postId . '-' . $channelId,
      'channel' => $channelId,
      'text_short' => get_field('text-short', $postId),
      'text_long' => get_field('text-long', $postId),
      'url' => $url,
      'image_url' => wp_get_attachment_image_url(get_field('image-id', $postId), 'full'),
      'video_url' => wp_get_attachment_url(get_field('video-file-id', $postId)),
    );

    var_dump($data, $this->config['n8nWebhookUrl']);

    $curl = curl_init();
    curl_setopt_array($curl, array(
      CURLOPT_URL => $this->config['n8nWebhookUrl'],
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_ENCODING => '',
      CURLOPT_MAXREDIRS => 10,
      CURLOPT_TIMEOUT => 0,
      CURLOPT_FOLLOWLOCATION => true,
      CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
      CURLOPT_CUSTOMREQUEST => 'POST',
      CURLOPT_POSTFIELDS => $data,
    ));

    $response = json_decode(curl_exec($curl), true);
    var_dump($response);
    curl_close($curl);
  }

  /**
   * @return void
   */
  public function addPostType()
  {
    WordPress::registerType(self::TYPE_SLUG, 'Social', 'Social', array(
      'menu_icon' => 'dashicons-networking',
      'menu_position' => 66,
      'exclude_from_search' => true,
      'publicly_queryable' => false,
      'show_in_nav_menus' => false,
      'has_archive' => false,
      'supports' => array('title'),
      'rewrite' => false
    ));
  }

  /**
   * @return array
   */
  protected function getAvailableChannels()
  {
    $channels = array();
    foreach ($this->config['channels'] as $key => $value) {
      if ($value) {
        $channels[$key] = $this->channelMap[$key];
      }
    }
    return $channels;
  }

  /**
   * @return void
   */
  public function getPlannedPostsMessage()
  {
    $message = '';
    if (current_user_can('edit_posts') && isset($_GET['post']) && isset($_GET['action']) && $_GET['action'] == 'edit') {
      $post = get_post(intval($_GET['post']));
      if ($post->post_type == self::TYPE_SLUG) {
        $list = defined('LOCAL_DEVELOPMENT') ? array() : Cronjob::list();
        foreach ($list['list'] as $job) {
          list($postId, $channelId) = explode('-', $job['job_data']);
          if ($job['job_identifier'] == 'lbwp_zapier_social_send' && $postId == $post->ID) {
            $time = $job['job_time'] + get_option('gmt_offset') * 3600;
            $message .= '<li>Post auf ' . $this->channelMap[$channelId] . '<br>am ' . date('d.m.Y', $time) . ' um ' . (date('H:i', $time)) .  ' Uhr</li>';
          }
        }
      }

      if ($message != '') {
        $message = '<ol>' . $message . '</ol>';
      }
    }

    if (strlen($message) == 0) {
      $message = 'Keine Social Posts eingeplant.';
    }

    return $message;
  }

  /**
   * @return void
   */
  public function addCustomFields()
  {
    if ( ! function_exists( 'acf_add_local_field_group' ) ) {
      return;
    }

    acf_add_local_field_group( array(
      'key' => 'group_671654e1ce87c',
      'title' => 'Geplante Posts',
      'fields' => array(
        array(
          'key' => 'field_671654e28e872',
          'label' => '',
          'name' => '',
          'aria-label' => '',
          'type' => 'message',
          'instructions' => '',
          'required' => 0,
          'conditional_logic' => 0,
          'wrapper' => array(
            'width' => '',
            'class' => '',
            'id' => '',
          ),
          'message' => $this->getPlannedPostsMessage(),
          'new_lines' => 'wpautop',
          'esc_html' => 0,
        ),
      ),
      'location' => array(
        array(
          array(
            'param' => 'post_type',
            'operator' => '==',
            'value' => 'lbwp-zap-social',
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
    ) );

    acf_add_local_field_group( array(
      'key' => 'group_6711185e89bd8',
      'title' => 'Content für die Kanäle',
      'fields' => array(
        array(
          'key' => 'field_6711185e44742',
          'label' => 'Hinweise',
          'name' => '',
          'aria-label' => '',
          'type' => 'message',
          'instructions' => '',
          'required' => 0,
          'conditional_logic' => 0,
          'wrapper' => array(
            'width' => '',
            'class' => '',
            'id' => '',
          ),
          'message' => '- Jeder Kanal nutzt automatisch die Felder, die am am besten geeignet sind.
- Sind Kanäle ausgewählt, die ohne gewisse Daten nicht funktionieren (z.B. Youtube ohne Video), wird nichts veröffentlicht.
- Die Social-Wall ist in deinem Theme möglicherweise noch nicht implementiert.',
          'new_lines' => 'wpautop',
          'esc_html' => 0,
        ),
        array(
          'key' => 'field_671118cb44743',
          'label' => 'Text kurz (max 200 Zeichen)',
          'name' => 'text-short',
          'aria-label' => '',
          'type' => 'textarea',
          'instructions' => '',
          'required' => 0,
          'conditional_logic' => 0,
          'wrapper' => array(
            'width' => '',
            'class' => '',
            'id' => '',
          ),
          'default_value' => '',
          'maxlength' => 200,
          'allow_in_bindings' => 0,
          'rows' => 2,
          'placeholder' => '',
          'new_lines' => '',
        ),
        array(
          'key' => 'field_6711192044745',
          'label' => 'Link',
          'name' => 'url',
          'aria-label' => '',
          'type' => 'url',
          'instructions' => 'Wird ein Link verwendet, kann teilweise das Bild je nach Kanal nicht verwendet werden.',
          'required' => 0,
          'conditional_logic' => 0,
          'wrapper' => array(
            'width' => '',
            'class' => '',
            'id' => '',
          ),
          'default_value' => '',
          'allow_in_bindings' => 0,
          'placeholder' => '',
        ),
        array(
          'key' => 'field_67111a0bd3b8c',
          'label' => 'Kampagnen Parameter',
          'name' => 'utm_parameters',
          'aria-label' => '',
          'type' => 'true_false',
          'instructions' => '',
          'required' => 0,
          'conditional_logic' => 0,
          'wrapper' => array(
            'width' => '',
            'class' => '',
            'id' => '',
          ),
          'message' => 'Automatisch Kampagnen Parameter zum Tracking an den Link anhängen',
          'default_value' => 0,
          'allow_in_bindings' => 0,
          'ui' => 0,
          'ui_on_text' => '',
          'ui_off_text' => '',
        ),
        array(
          'key' => 'field_6711194b44746',
          'label' => 'Bild',
          'name' => 'image-id',
          'aria-label' => '',
          'type' => 'image',
          'instructions' => '',
          'required' => 0,
          'conditional_logic' => 0,
          'wrapper' => array(
            'width' => '',
            'class' => '',
            'id' => '',
          ),
          'return_format' => 'id',
          'library' => 'all',
          'min_width' => '',
          'min_height' => '',
          'min_size' => '',
          'max_width' => '',
          'max_height' => '',
          'max_size' => '',
          'mime_types' => '',
          'allow_in_bindings' => 0,
          'preview_size' => 'medium',
        ),
        array(
          'key' => 'field_6711190444744',
          'label' => 'Text lang (max 3000 Zeichen)',
          'name' => 'text-long',
          'aria-label' => '',
          'type' => 'textarea',
          'instructions' => '',
          'required' => 0,
          'conditional_logic' => 0,
          'wrapper' => array(
            'width' => '',
            'class' => '',
            'id' => '',
          ),
          'default_value' => '',
          'maxlength' => 3000,
          'allow_in_bindings' => 0,
          'rows' => 8,
          'placeholder' => '',
          'new_lines' => '',
        ),
        array(
          'key' => 'field_6711196344747',
          'label' => 'Video-Datei',
          'name' => 'video-file-id',
          'aria-label' => '',
          'type' => 'file',
          'instructions' => 'Verwendbar bei Youtube',
          'required' => 0,
          'conditional_logic' => 0,
          'wrapper' => array(
            'width' => '',
            'class' => '',
            'id' => '',
          ),
          'return_format' => 'id',
          'library' => 'all',
          'min_size' => 5,
          'max_size' => 100,
          'mime_types' => 'mp4',
          'allow_in_bindings' => 0,
        ),
        array(
          'key' => 'field_67111a83e746a',
          'label' => 'Social-Wall',
          'name' => 'social-wall',
          'aria-label' => '',
          'type' => 'true_false',
          'instructions' => '',
          'required' => 0,
          'conditional_logic' => 0,
          'wrapper' => array(
            'width' => '',
            'class' => '',
            'id' => '',
          ),
          'message' => 'Auch auf der eigenen Social-Wall publizieren',
          'default_value' => 0,
          'allow_in_bindings' => 0,
          'ui' => 0,
          'ui_on_text' => '',
          'ui_off_text' => '',
        ),
      ),
      'location' => array(
        array(
          array(
            'param' => 'post_type',
            'operator' => '==',
            'value' => 'lbwp-zap-social',
          ),
        ),
      ),
      'menu_order' => 0,
      'position' => 'normal',
      'style' => 'default',
      'label_placement' => 'left',
      'instruction_placement' => 'label',
      'hide_on_screen' => '',
      'active' => true,
      'description' => '',
      'show_in_rest' => 0,
    ) );

    acf_add_local_field_group( array(
      'key' => 'group_671114940375f',
      'title' => 'Veröffenlichen auf...',
      'fields' => array(
        array(
          'key' => 'field_671117bc142f0',
          'label' => 'Hinweise',
          'name' => '',
          'aria-label' => '',
          'type' => 'message',
          'instructions' => '',
          'required' => 0,
          'conditional_logic' => 0,
          'wrapper' => array(
            'width' => '',
            'class' => '',
            'id' => '',
          ),
          'message' => '- Veröffentlichung auf Diensten erfolgt, sobald der Social rechts veröffentlicht wird oder gemäss Einplanung.
- Pro Dienst kann auch eine abweichende Veröffentlichungszeit angegeben werden.
- Sobald der Social eingeplant/veröffentlciht ist, hat eine Änderung von Datum/Zeit keinen Einfluss mehr.',
          'new_lines' => 'wpautop',
          'esc_html' => 0,
        ),
        array(
          'key' => 'field_6711156813ece',
          'label' => 'Kanäle hinzufügen',
          'name' => 'n8n-social-channels',
          'aria-label' => '',
          'type' => 'repeater',
          'instructions' => '',
          'required' => 0,
          'conditional_logic' => 0,
          'wrapper' => array(
            'width' => '',
            'class' => '',
            'id' => '',
          ),
          'layout' => 'table',
          'pagination' => 0,
          'min' => 0,
          'max' => 0,
          'collapsed' => '',
          'button_label' => 'Eintrag hinzufügen',
          'rows_per_page' => 20,
          'sub_fields' => array(
            array(
              'key' => 'field_6711158613ecf',
              'label' => 'Dienst',
              'name' => 'channel',
              'aria-label' => '',
              'type' => 'select',
              'instructions' => '',
              'required' => 0,
              'conditional_logic' => 0,
              'wrapper' => array(
                'width' => '',
                'class' => '',
                'id' => '',
              ),
              'choices' => $this->getAvailableChannels(),
              'default_value' => false,
              'return_format' => 'value',
              'multiple' => 0,
              'allow_null' => 0,
              'allow_in_bindings' => 1,
              'ui' => 0,
              'ajax' => 0,
              'placeholder' => '',
              'parent_repeater' => 'field_6711156813ece',
            ),
            array(
              'key' => 'field_671115f813ed0',
              'label' => 'Zeitsteuerung',
              'name' => 'timed',
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
                'timed' => 'Nicht sofort, sondern:',
              ),
              'default_value' => array(
              ),
              'return_format' => 'value',
              'allow_custom' => 0,
              'allow_in_bindings' => 1,
              'layout' => 'vertical',
              'toggle' => 0,
              'save_custom' => 0,
              'custom_choice_button_text' => 'Eine neue Auswahlmöglichkeit hinzufügen',
              'parent_repeater' => 'field_6711156813ece',
            ),
            array(
              'key' => 'field_671116b7ef73a',
              'label' => 'Datum / Uhrzeit',
              'name' => 'datetime',
              'aria-label' => '',
              'type' => 'date_time_picker',
              'instructions' => '',
              'required' => 0,
              'conditional_logic' => 0,
              'wrapper' => array(
                'width' => '',
                'class' => '',
                'id' => '',
              ),
              'display_format' => 'd.m.Y H:i:s',
              'return_format' => 'U',
              'first_day' => 1,
              'allow_in_bindings' => 0,
              'parent_repeater' => 'field_6711156813ece',
            ),
          ),
        ),
      ),
      'location' => array(
        array(
          array(
            'param' => 'post_type',
            'operator' => '==',
            'value' => 'lbwp-zap-social',
          ),
        ),
      ),
      'menu_order' => 0,
      'position' => 'normal',
      'style' => 'default',
      'label_placement' => 'left',
      'instruction_placement' => 'label',
      'hide_on_screen' => '',
      'active' => true,
      'description' => '',
      'show_in_rest' => 0,
    ) );
  }
}



