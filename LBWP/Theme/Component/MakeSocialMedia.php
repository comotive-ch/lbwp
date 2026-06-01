<?php

namespace LBWP\Theme\Component;

use LBWP\Util\WordPress;
use WP_Error;
use WP_Post;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Social channel management with make.com webhooks.
 * Registers the lbwp-social-post type, ACF settings/automation pages,
 * webhook dispatch on publish, an external REST API, and automation rules
 * that auto-create social posts when other post types are published.
 *
 * @package LBWP\Theme\Component
 * @author Michael Sebel <michael@comotive.ch>
 */
class MakeSocialMedia extends ACFBase
{
  /** Custom post type slug */
  const string POST_TYPE = 'lbwp-social-post';

  /** REST namespace */
  const string API_NAMESPACE = 'lbwp-social/v1';

  /** @var array<string,string> Human-readable labels for every supported content type */
  const array CONTENT_TYPES = [
    'text-short'  => 'Text kurz (bis 500 Zeichen)',
    'text-long'   => 'Text lang (bis 3000 Zeichen)',
    'image-short' => 'Bild mit kurzem Text (bis 500 Zeichen)',
    'image-long'  => 'Bild mit langem Text (bis 3000 Zeichen)',
  ];

  /** @var array<string,string> Supported channel slugs mapped to display names */
  const array CHANNELS = [
    'threads'         => 'Threads',
    'mastodon'        => 'Mastodon',
    'linkedin'        => 'LinkedIn',
    'facebook'        => 'Facebook',
    'bluesky'         => 'Bluesky',
    'instagram'       => 'Instagram',
    'google-business' => 'Google Business Profile',
  ];

  /**
   * Registers WordPress hooks. Called by Component::__construct on `init`.
   */
  public function init(): void
  {
    $this->initPostType();
    add_action('rest_api_init', [$this, 'registerApiRoutes']);
    add_action('transition_post_status', [$this, 'onSocialPostPublish'], 10, 3);
    add_action('transition_post_status', [$this, 'onSourcePostPublish'], 10, 3);
    add_action('admin_menu', [$this, 'registerChannelsPage']);
    add_action('admin_footer', [$this, 'adminFooterScripts']);
  }

  /**
   * Registers the private social-post post type.
   * Called directly from init() since Component already hooks init() to `init`.
   */
  protected function initPostType(): void
  {
    register_post_type(self::POST_TYPE, [
      'label'  => __('Social Media Posts', 'lbwp'),
      'labels' => [
        'name'               => __('Social Media Posts', 'lbwp'),
        'singular_name'      => __('Social Media Post', 'lbwp'),
        'add_new'            => __('Neuer Post', 'lbwp'),
        'add_new_item'       => __('Neuen Post erstellen', 'lbwp'),
        'edit_item'          => __('Social Media Post bearbeiten', 'lbwp'),
        'new_item'           => __('Neuer Social Media Post', 'lbwp'),
        'search_items'       => __('Posts durchsuchen', 'lbwp'),
        'not_found'          => __('Keine Posts gefunden', 'lbwp'),
        'not_found_in_trash' => __('Keine Posts im Papierkorb', 'lbwp'),
        'menu_name'          => __('Social Media', 'lbwp'),
      ],
      'public'              => false,
      'publicly_queryable'  => false,
      'show_ui'             => true,
      'show_in_menu'        => true,
      'show_in_nav_menus'   => false,
      'show_in_rest'        => false,
      'exclude_from_search' => true,
      'has_archive'         => false,
      'rewrite'             => false,
      'query_var'           => false,
      'supports'            => ['title', 'thumbnail'],
      'menu_icon'           => 'dashicons-share',
    ]);
  }

  /**
   * Registers ACF options pages and all field groups. Called on `acf/init`.
   */
  public function fields(): void
  {
    $this->addOptionsPage(
      __('Einstellungen', 'lbwp'),
      'lbwp-social-settings',
      'edit.php?post_type=' . self::POST_TYPE,
      ['capability' => 'administrator']
    );

    $this->addOptionsPage(
      __('Automatisierung', 'lbwp'),
      'lbwp-social-automation',
      'edit.php?post_type=' . self::POST_TYPE,
      ['capability' => 'administrator']
    );

    $this->registerSettingsFields();
    $this->registerAgentApiFields();
    $this->registerPostTypeFields();
    $this->registerAutomationFields();
  }

  /**
   * Dynamically populates the channel checkbox choices from the configured webhooks.
   * Each choice key encodes title and type so the JS filter can show/hide by type.
   *
   * @param array $field ACF field config array
   * @return array Modified field config
   */
  public function loadChannelChoices(array $field): array
  {
    $webhooks = get_field('soc_webhooks', 'option');
    if (!is_array($webhooks) || empty($webhooks)) {
      $field['instructions'] = __('Keine Webhooks konfiguriert. Bitte zuerst unter «Einstellungen» Webhooks erfassen.', 'lbwp');
      return $field;
    }

    $typeLabels = [
      'text-short'  => 'Text kurz',
      'text-long'   => 'Text lang',
      'image-short' => 'Bild kurz',
      'image-long'  => 'Bild lang',
    ];

    $choices = [];
    foreach ($webhooks as $webhook) {
      if (empty($webhook['soc_wh_url'])) continue;
      $typeLabel = $typeLabels[$webhook['soc_wh_type']] ?? $webhook['soc_wh_type'];
      $choices[$this->webhookKey($webhook)] = $webhook['soc_wh_title'] . ' (' . $typeLabel . ')';
    }

    $field['choices'] = $choices;
    return $field;
  }

  /**
   * Dispatches make.com webhooks when a social post is first published.
   * Guarded by _soc_webhook_sent meta to prevent duplicate calls on re-saves.
   *
   * @param string $new_status
   * @param string $_old_status Unused — we rely on _soc_webhook_sent instead of old-status checks
   * @param WP_Post $post
   */
  public function onSocialPostPublish(string $new_status, string $_old_status, WP_Post $post): void
  {
    if ($new_status !== 'publish' || $post->post_type !== self::POST_TYPE)
      return;
    if (get_post_meta($post->ID, '_soc_webhook_sent', true))
      return;

    $contentType      = get_post_meta($post->ID, 'soc_content_type', true);
    $selectedChannels = get_post_meta($post->ID, 'soc_channels', true);

    if (empty($selectedChannels) || !is_array($selectedChannels))
      return;

    $isShort   = in_array($contentType, ['text-short', 'image-short']);
    $text      = get_post_meta($post->ID, $isShort ? 'soc_text_short' : 'soc_text_long', true);
    $linkUrl   = get_post_meta($post->ID, 'soc_link_url', true);
    $thumbnail = $this->getThumbnailUrl($post->ID);

    $webhooks = get_field('soc_webhooks', 'option');
    if (!is_array($webhooks))
      return;

    $dispatched = 0;
    foreach ($selectedChannels as $channelKey) {
      foreach ($webhooks as $webhook) {
        if ($this->webhookKey($webhook) !== $channelKey) continue;
        if ($webhook['soc_wh_type'] !== $contentType) continue;
        if (empty($webhook['soc_wh_url'])) continue;
        $this->sendWebhook($webhook['soc_wh_url'], [
          'title'     => $post->post_title,
          'text'      => $text,
          'type'      => $contentType,
          'url'       => $linkUrl,
          'thumbnail' => $thumbnail,
          'secret'    => $webhook['soc_wh_secret'],
        ]);
        $dispatched++;
        break;
      }
    }

    if ($dispatched > 0) {
      update_post_meta($post->ID, '_soc_webhook_sent', 1);
    }
  }

  /**
   * Sends a non-blocking JSON POST request to a make.com webhook URL.
   *
   * @param string $url
   * @param array $payload
   */
  protected function sendWebhook(string $url, array $payload): void
  {
    wp_remote_post($url, [
      'body'     => wp_json_encode($payload),
      'headers'  => ['Content-Type' => 'application/json'],
      'timeout'  => 15,
      'blocking' => false,
    ]);
  }

  /**
   * Returns the original-size URL of the post's featured image, or empty string.
   *
   * @param int $postId
   * @return string
   */
  protected function getThumbnailUrl(int $postId): string
  {
    $thumbnailId = get_post_thumbnail_id($postId);
    if (!$thumbnailId)
      return '';
    $img = wp_get_attachment_image_src($thumbnailId, 'full');
    return $img ? $img[0] : '';
  }

  /**
   * Outputs admin-only JS for char counters and channel type filtering.
   * Only active on the social post edit screen.
   */
  public function adminFooterScripts(): void
  {
    $screen = get_current_screen();
    if (!$screen || $screen->post_type !== self::POST_TYPE)
      return;
    ?>
    <script>
    (function($) {
      function getContentType() {
        return $('[data-key="field_soc_content_type"]').find('select').val() || '';
      }

      function filterChannels() {
        const type = getContentType();
        $('[data-key="field_soc_channels"] .acf-checkbox-list li').each(function() {
          const $li     = $(this);
          const val     = $li.find('input[type="checkbox"]').val() || '';
          const parts   = val.split('::');
          const itemType = parts.length === 2 ? parts[1] : '';
          if (!type || !itemType || itemType === type) {
            $li.show();
          } else {
            $li.hide();
            $li.find('input[type="checkbox"]').prop('checked', false);
          }
        });
      }

      function addCharCounter(fieldKey, maxLen) {
        const $field    = $('[data-key="' + fieldKey + '"]');
        const $textarea = $field.find('textarea');
        if (!$textarea.length || $field.find('.soc-char-counter').length) return;
        const $counter = $('<p class="description soc-char-counter" style="margin-top:6px"></p>');
        $field.find('.acf-input').append($counter);
        function update() {
          const len   = $textarea.val().length;
          const color = len > maxLen ? '#cc0000' : (len > maxLen * 0.85 ? '#b45309' : '#555d66');
          $counter.css('color', color).text(len + ' / ' + maxLen + ' Zeichen');
        }
        $textarea.on('input', update);
        update();
      }

      if (typeof acf !== 'undefined') {
        acf.addAction('ready', function() {
          addCharCounter('field_soc_text_short', 500);
          addCharCounter('field_soc_text_long', 3000);
          filterChannels();
        });

        acf.addAction('change', function($el) {
          if ($el && $el.data('key') === 'field_soc_content_type') {
            setTimeout(filterChannels, 50);
          }
        });

        acf.addAction('show', function($el) {
          if ($el.data('key') === 'field_soc_text_short') addCharCounter('field_soc_text_short', 500);
          if ($el.data('key') === 'field_soc_text_long')  addCharCounter('field_soc_text_long', 3000);
        });
      }
    })(jQuery);
    </script>
    <?php
  }

  /**
   * Registers the Kanäle sub-page under the social media post type admin menu.
   */
  public function registerChannelsPage(): void
  {
    add_submenu_page(
      'edit.php?post_type=' . self::POST_TYPE,
      __('Kanäle', 'lbwp'),
      __('Kanäle', 'lbwp'),
      'administrator',
      'lbwp-social-channels',
      [$this, 'renderChannelsPage']
    );
  }

  /**
   * Renders the Kanäle admin page: channel overview table followed by the webhook test form.
   */
  public function renderChannelsPage(): void
  {
    $testResult = $this->handleWebhookTestPost();
    $webhooks   = get_field('soc_webhooks', 'option') ?: [];

    $channels = [];
    foreach ($webhooks as $webhook) {
      if (strlen($webhook['soc_wh_channel_url']) > 0) {
        $channels[] = [
          'name' => $webhook['soc_wh_title'],
          'url' => $webhook['soc_wh_channel_url']
        ];
      }
    }
    ?>
    <div class="wrap">
      <h1><?php echo esc_html(__('Kanäle', 'lbwp')); ?></h1>
      <?php if (empty($channels)): ?>
        <p><?php echo esc_html(__('Keine Kanäle konfiguriert. Bitte zuerst unter «Einstellungen» Webhooks erfassen.', 'lbwp')); ?></p>
      <?php else: ?>
      <table class="widefat fixed" style="max-width:950px;margin-top:1rem">
        <thead>
          <tr>
            <th><?php echo esc_html(__('Kanal', 'lbwp')); ?></th>
            <th><?php echo esc_html(__('URL', 'lbwp')); ?></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($channels as $channel): ?>
          <tr>
            <td><?php echo esc_html($channel['name']); ?></td>
            <td>
              <?php if ($channel['url']): ?>
                <a href="<?php echo esc_url($channel['url']); ?>" target="_blank" rel="noopener noreferrer">
                  <?php echo esc_html($channel['url']); ?>
                </a>
              <?php else: ?>
                <span style="color:#999">—</span>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>

      <h2><?php echo esc_html(__('Webhook Test', 'lbwp')); ?></h2>
      <?php if ($testResult !== null): ?>
        <div class="notice notice-<?php echo $testResult['success'] ? 'success' : 'error'; ?> is-dismissible">
          <p><?php echo esc_html($testResult['message']); ?></p>
        </div>
      <?php endif; ?>
      <?php if (empty($webhooks)): ?>
        <p><?php echo esc_html(__('Keine Webhooks konfiguriert. Bitte zuerst unter «Einstellungen» Webhooks erfassen.', 'lbwp')); ?></p>
      <?php else: ?>
      <form method="post" style="max-width:600px"
            onsubmit="return confirm(<?php echo esc_attr(wp_json_encode(__('Webhook wirklich senden?', 'lbwp'))); ?>)">
        <?php wp_nonce_field('soc_webhook_test', 'soc_test_nonce'); ?>
        <table class="form-table" role="presentation">
          <tr>
            <th scope="row">
              <label for="soc_test_webhook"><?php echo esc_html(__('Webhook', 'lbwp')); ?></label>
            </th>
            <td>
              <select id="soc_test_webhook" name="soc_test_webhook" class="regular-text">
                <?php foreach ($webhooks as $webhook):
                  $key   = $this->webhookKey($webhook);
                  $label = $webhook['soc_wh_title'] . ' (' . (self::CHANNELS[$webhook['soc_wh_channel']] ?? $webhook['soc_wh_channel']) . ')';
                ?>
                  <option value="<?php echo esc_attr($key); ?>"
                    <?php selected($_POST['soc_test_webhook'] ?? '', $key); ?>>
                    <?php echo esc_html($label); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </td>
          </tr>
          <tr>
            <th scope="row">
              <label for="soc_test_text"><?php echo esc_html(__('Text', 'lbwp')); ?></label>
            </th>
            <td>
              <textarea id="soc_test_text" name="soc_test_text" rows="4" class="large-text"><?php
                echo esc_textarea($_POST['soc_test_text'] ?? '');
              ?></textarea>
            </td>
          </tr>
          <tr>
            <th scope="row">
              <label for="soc_test_url"><?php echo esc_html(__('URL', 'lbwp')); ?></label>
            </th>
            <td>
              <input type="url" id="soc_test_url" name="soc_test_url" class="large-text"
                     value="<?php echo esc_attr($_POST['soc_test_url'] ?? ''); ?>"
                     placeholder="https://" />
            </td>
          </tr>
        </table>
        <p class="submit">
          <button type="submit" class="button button-primary">
            <?php echo esc_html(__('Webhook senden', 'lbwp')); ?>
          </button>
        </p>
      </form>
      <?php endif; ?>
    </div>
    <?php
  }

  /**
   * Processes the webhook test form POST. Returns a result array on submission, null otherwise.
   * Uses a blocking HTTP request so the response code can be shown to the user.
   *
   * @return array{success: bool, message: string}|null
   */
  protected function handleWebhookTestPost(): ?array
  {
    if (empty($_POST['soc_test_nonce'])) return null;

    if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['soc_test_nonce'])), 'soc_webhook_test')) {
      return ['success' => false, 'message' => __('Ungültige Sicherheitsüberprüfung.', 'lbwp')];
    }
    if (!current_user_can('administrator')) {
      return ['success' => false, 'message' => __('Keine Berechtigung.', 'lbwp')];
    }

    $webhookKey = sanitize_text_field(wp_unslash($_POST['soc_test_webhook'] ?? ''));
    $text       = sanitize_textarea_field(wp_unslash($_POST['soc_test_text'] ?? ''));
    $url        = esc_url_raw(wp_unslash($_POST['soc_test_url'] ?? ''));

    $webhooks = get_field('soc_webhooks', 'option') ?: [];
    $target   = null;
    foreach ($webhooks as $webhook) {
      if ($this->webhookKey($webhook) === $webhookKey) {
        $target = $webhook;
        break;
      }
    }

    if ($target === null) {
      return ['success' => false, 'message' => __('Webhook nicht gefunden.', 'lbwp')];
    }

    $response = wp_remote_post($target['soc_wh_url'], [
      'body'     => wp_json_encode([
        'title'     => 'Webhook Test',
        'text'      => $text,
        'type'      => $target['soc_wh_type'],
        'url'       => $url,
        'thumbnail' => '',
        'secret'    => $target['soc_wh_secret'],
      ]),
      'headers'  => ['Content-Type' => 'application/json'],
      'timeout'  => 15,
      'blocking' => true,
    ]);

    if (is_wp_error($response)) {
      return ['success' => false, 'message' => $response->get_error_message()];
    }

    $code = wp_remote_retrieve_response_code($response);
    if ($code >= 200 && $code < 300) {
      return ['success' => true, 'message' => sprintf(__('Webhook erfolgreich gesendet (HTTP %d).', 'lbwp'), $code)];
    }
    return ['success' => false, 'message' => sprintf(__('Webhook fehlgeschlagen (HTTP %d).', 'lbwp'), $code)];
  }

  /**
   * Registers the REST endpoint for external post creation.
   */
  public function registerApiRoutes(): void
  {
    register_rest_route(self::API_NAMESPACE, '/create', [
      'methods'             => 'POST',
      'callback'            => [$this, 'apiCreatePost'],
      'permission_callback' => [$this, 'apiCheckPermission'],
    ]);

    register_rest_route(self::API_NAMESPACE, '/agent-config', [
      'methods'             => 'GET',
      'callback'            => [$this, 'apiGetAgentConfig'],
      'permission_callback' => [$this, 'apiCheckPermission'],
    ]);
  }

  /**
   * Validates the API secret from X-Social-Secret header or `secret` body param.
   *
   * @param WP_REST_Request $request
   * @return bool
   */
  public function apiCheckPermission(WP_REST_Request $request): bool
  {
    $secret = get_field('soc_api_secret', 'option');
    if (empty($secret)) return false;
    $provided = $request->get_header('X-Social-Secret') ?: $request->get_param('secret');
    return hash_equals($secret, (string) $provided);
  }

  /**
   * Returns the agent configuration: prompt, context, idea count, content URLs, and available channels.
   * URLs are either the last 10 published post permalinks or the manually configured list.
   *
   * @param WP_REST_Request $_request
   * @return WP_REST_Response
   */
  public function apiGetAgentConfig(WP_REST_Request $_request): WP_REST_Response
  {
    $linkMode = get_field('soc_agent_link_mode', 'option') ?: 'latest-post';
    $urls     = $linkMode === 'manual-urls' ? $this->getManualUrls() : $this->getLatestPostUrls();

    return rest_ensure_response([
      'prompt'   => (string) (get_field('soc_agent_prompt', 'option') ?: ''),
      'context'  => (string) (get_field('soc_agent_context', 'option') ?: ''),
      'ideas'    => max(1, (int) (get_field('soc_agent_ideas', 'option') ?: 1)),
      'urls'     => $urls,
      'channels' => $this->getChannelSummary(),
    ]);
  }

  /**
   * Returns permalinks from the manual URL repeater field, skipping empty entries.
   *
   * @return string[]
   */
  protected function getManualUrls(): array
  {
    $rows = get_field('soc_agent_manual_urls', 'option') ?: [];
    return array_values(array_filter(array_map(fn(array $row) => $row['soc_agent_url'] ?? '', $rows)));
  }

  /**
   * Returns permalinks of the 10 most recently published posts.
   *
   * @return string[]
   */
  protected function getLatestPostUrls(): array
  {
    $posts = get_posts([
      'post_type'      => 'post',
      'post_status'    => 'publish',
      'posts_per_page' => 10,
      'orderby'        => 'date',
      'order'          => 'DESC',
      'fields'         => 'ids',
    ]);
    return array_map('get_permalink', $posts);
  }

  /**
   * Summarises all configured webhooks as unique channels with their supported content types.
   * Used by the agent to know which channels and types it can target when creating posts.
   *
   * @return array<int, array{slug: string, name: string, types: string[]}>
   */
  protected function getChannelSummary(): array
  {
    $webhooks = get_field('soc_webhooks', 'option') ?: [];
    $channels = [];
    foreach ($webhooks as $webhook) {
      $slug = $webhook['soc_wh_channel'];
      if (!isset($channels[$slug])) {
        $channels[$slug] = [
          'slug'  => $slug,
          'name'  => self::CHANNELS[$slug] ?? $slug,
          'types' => [],
        ];
      }
      if (!in_array($webhook['soc_wh_type'], $channels[$slug]['types'])) {
        $channels[$slug]['types'][] = $webhook['soc_wh_type'];
      }
    }
    return array_values($channels);
  }

  /**
   * Creates a social post from an external API call.
   * Always inserts as draft first so meta is written before any publish hook fires.
   *
   * @param WP_REST_Request $request
   * @return WP_REST_Response|WP_Error
   */
  public function apiCreatePost(WP_REST_Request $request): WP_REST_Response|WP_Error
  {
    $params = $request->get_json_params() ?: $request->get_params();

    $title        = sanitize_text_field($params['title'] ?? '');
    $contentType  = sanitize_text_field($params['type'] ?? 'text-short');
    $text         = sanitize_textarea_field($params['text'] ?? '');
    $linkUrl      = esc_url_raw($params['url'] ?? '');
    $postStatus   = sanitize_text_field($params['post_status'] ?? 'draft');
    $postDate     = sanitize_text_field($params['post_date'] ?? '');
    $channels     = array_map('sanitize_text_field', (array) ($params['channels'] ?? []));
    $thumbnailUrl = esc_url_raw($params['thumbnail_url'] ?? '');

    if (empty($title)) {
      return new WP_Error('missing_title', __('Titel fehlt.', 'lbwp'), ['status' => 400]);
    }
    if (!array_key_exists($contentType, self::CONTENT_TYPES)) {
      return new WP_Error('invalid_type', __('Ungültiger Inhaltstyp.', 'lbwp'), ['status' => 400]);
    }
    if (!in_array($postStatus, ['draft', 'publish', 'future', 'pending'])) {
      $postStatus = 'draft';
    }

    $channelKeys = $this->resolveChannels($channels, $contentType);

    $postData = [
      'post_title'  => $title,
      'post_type'   => self::POST_TYPE,
      'post_status' => 'draft',
    ];
    if ($postDate) {
      $postData['post_date']     = $postDate;
      $postData['post_date_gmt'] = get_gmt_from_date($postDate);
    }

    $postId = wp_insert_post($postData, true);
    if (is_wp_error($postId)) return $postId;

    $isShort = in_array($contentType, ['text-short', 'image-short']);
    update_post_meta($postId, 'soc_content_type', $contentType);
    update_post_meta($postId, $isShort ? 'soc_text_short' : 'soc_text_long', $text);
    update_post_meta($postId, 'soc_link_url', $linkUrl);
    update_post_meta($postId, 'soc_channels', $channelKeys);

    if ($thumbnailUrl) {
      $this->attachThumbnailFromUrl($postId, $thumbnailUrl);
    }

    // Updating status here triggers onSocialPostPublish with meta already in place
    if ($postStatus !== 'draft') {
      wp_update_post(['ID' => $postId, 'post_status' => $postStatus]);
    }

    return rest_ensure_response([
      'success'           => true,
      'post_id'           => $postId,
      'channels_selected' => count($channelKeys),
      'status'            => get_post_status($postId),
    ]);
  }

  /**
   * Resolves a list of channel slugs + content type to matching webhook keys.
   * If $channelSlugs is empty, all webhooks matching the type are returned.
   *
   * @param string[] $channelSlugs e.g. ['threads', 'instagram']
   * @param string $contentType
   * @return string[]
   */
  protected function resolveChannels(array $channelSlugs, string $contentType): array
  {
    $webhooks = get_field('soc_webhooks', 'option');
    if (!is_array($webhooks)) return [];

    $keys = [];
    foreach ($webhooks as $webhook) {
      if (empty($webhook['soc_wh_url'])) continue;
      if ($webhook['soc_wh_type'] !== $contentType) continue;
      if (!empty($channelSlugs) && !in_array($webhook['soc_wh_channel'], $channelSlugs)) continue;
      $keys[] = $this->webhookKey($webhook);
    }
    return $keys;
  }

  /**
   * Downloads a remote image via WordPress::createAttachmentImageFromUrl,
   * which handles S3 upload on cluster systems, then sets it as featured image.
   *
   * @param int $postId
   * @param string $url
   */
  protected function attachThumbnailFromUrl(int $postId, string $url): void
  {
    $attachId = WordPress::createAttachmentImageFromUrl($url, $postId);
    if ($attachId && !is_wp_error($attachId)) {
      set_post_thumbnail($postId, $attachId);
    }
  }

  /**
   * Creates a social post whenever a configured source post type is first published.
   *
   * @param string $new_status
   * @param string $old_status
   * @param WP_Post $post
   */
  public function onSourcePostPublish(string $new_status, string $old_status, WP_Post $post): void
  {
    if ($new_status !== 'publish' || $old_status === 'publish') return;
    if ($post->post_type === self::POST_TYPE) return;

    $rules = get_field('soc_auto_rules', 'option');
    if (!is_array($rules))
      return;

    foreach ($rules as $rule) {
      if ($rule['soc_auto_post_type'] == $post->post_type) {
        $this->createAutoSocialPost($post, $rule);
      }
    }
  }

  /**
   * Creates and optionally publishes a text-short social post from a source post.
   * Text is limited to 500 characters. All matching text-short channels are auto-selected.
   *
   * @param WP_Post $sourcePost
   * @param array $rule Automation rule config from ACF repeater
   */
  protected function createAutoSocialPost(WP_Post $sourcePost, array $rule): void
  {
    $text = $this->extractSourceText($sourcePost, $rule['soc_auto_text_source']);
    // Bail if there is no text
    if (mb_strlen($text) === 0) {
      return;
    }
    // Cut uf too long
    if (mb_strlen($text) > 500) {
      $text = mb_substr($text, 0, 497) . '...';
    }

    $channelKeys = $this->resolveChannels([], 'text-short');
    $postId = wp_insert_post([
      'post_title'  => $sourcePost->post_title,
      'post_type'   => self::POST_TYPE,
      'post_status' => 'draft',
    ]);

    if (!$postId || is_wp_error($postId))
      return;

    update_post_meta($postId, 'soc_content_type', 'text-short');
    update_post_meta($postId, 'soc_text_short', $text);
    update_post_meta($postId, 'soc_link_url', get_permalink($sourcePost->ID));
    update_post_meta($postId, 'soc_channels', $channelKeys);

    if (has_post_thumbnail($sourcePost->ID)) {
      set_post_thumbnail($postId, get_post_thumbnail_id($sourcePost->ID));
    }

    if ($rule['soc_auto_handle'] === 'publish') {
      wp_update_post(['ID' => $postId, 'post_status' => 'publish']);
    }
  }

  /**
   * Extracts text from a post according to the configured source field.
   * Falls back to a trimmed excerpt from post_content when excerpt is empty.
   *
   * @param WP_Post $post
   * @param string $source post_title | post_excerpt | post_content
   * @return string
   */
  protected function extractSourceText(WP_Post $post, string $source): string
  {
    return match ($source) {
      'post_title'   => $post->post_title,
      'post_excerpt' => $post->post_excerpt
        ?: wp_trim_words(wp_strip_all_tags($post->post_content), 55, '...'),
      default        => wp_trim_words(wp_strip_all_tags($post->post_content), 55, '...'),
    };
  }

  /**
   * Returns a stable key for a webhook config row, encoding title and type.
   * Used as the checkbox value so JS can filter by type suffix after '::'.
   *
   * @param array $webhook
   * @return string
   */
  protected function webhookKey(array $webhook): string
  {
    return urlencode($webhook['soc_wh_title']) . '::' . $webhook['soc_wh_type'];
  }

  /** No ACF blocks registered by this component. */
  public function blocks(): void {}

  /**
   * Registers the ACF field group for the Einstellungen options page.
   * Contains the global API secret and the make.com webhook repeater.
   */
  protected function registerSettingsFields(): void
  {
    acf_add_local_field_group([
      'key'    => 'group_soc_settings',
      'title'  => __('Webhook-Konfigurationen', 'lbwp'),
      'active' => true,
      'fields' => [
        [
          'key'          => 'field_soc_api_secret',
          'label'        => __('API-Schlüssel', 'lbwp'),
          'name'         => 'soc_api_secret',
          'type'         => 'text',
          'instructions' => __('Schlüssel für die Agent API. Wird als HTTP «X-Social-Secret» übermittelt.', 'lbwp'),
          'required'     => 0,
          'wrapper'      => ['width' => '50'],
        ],
        [
          'key'          => 'field_soc_webhooks',
          'label'        => __('Make.com Webhooks', 'lbwp'),
          'name'         => 'soc_webhooks',
          'type'         => 'repeater',
          'instructions' => __('Pro Kanal- und Inhaltstyp-Kombination einen Eintrag erfassen. Für Threads mit Text und Bild z.B. zwei separate Einträge.', 'lbwp'),
          'required'     => 0,
          'collapsed'    => 'field_soc_wh_title',
          'layout'       => 'row',
          'button_label' => __('Webhook hinzufügen', 'lbwp'),
          'sub_fields'   => [
            [
              'key'         => 'field_soc_wh_title',
              'label'       => __('Bezeichnung', 'lbwp'),
              'name'        => 'soc_wh_title',
              'type'        => 'text',
              'required'    => 1,
              'wrapper'     => ['width' => '20'],
            ],
            [
              'key'           => 'field_soc_wh_channel',
              'label'         => __('Kanal', 'lbwp'),
              'name'          => 'soc_wh_channel',
              'type'          => 'select',
              'required'      => 1,
              'choices'       => self::CHANNELS,
              'allow_null'    => 0,
              'multiple'      => 0,
              'ui'            => 1,
              'return_format' => 'value',
              'wrapper'       => ['width' => '13'],
            ],
            [
              'key'           => 'field_soc_wh_type',
              'label'         => __('Inhaltstyp', 'lbwp'),
              'name'          => 'soc_wh_type',
              'type'          => 'select',
              'required'      => 1,
              'choices'       => self::CONTENT_TYPES,
              'allow_null'    => 0,
              'multiple'      => 0,
              'ui'            => 1,
              'return_format' => 'value',
              'wrapper'       => ['width' => '17'],
            ],
            [
              'key'         => 'field_soc_wh_url',
              'label'       => __('Webhook-URL', 'lbwp'),
              'name'        => 'soc_wh_url',
              'type'        => 'url',
              'required'    => 0,
              'placeholder' => 'https://hook.eu1.make.com/...',
              'wrapper'     => ['width' => '25'],
            ],
            [
              'key'          => 'field_soc_wh_channel_url',
              'label'        => __('Kanal-URL', 'lbwp'),
              'name'         => 'soc_wh_channel_url',
              'type'         => 'url',
              'required'     => 0,
              'placeholder'  => 'https://',
              'instructions' => __('Profil Direktlink.', 'lbwp'),
              'wrapper'      => ['width' => '18'],
            ],
            [
              'key'          => 'field_soc_wh_secret',
              'label'        => __('Secret', 'lbwp'),
              'name'         => 'soc_wh_secret',
              'type'         => 'text',
              'required'     => 0,
              'wrapper'      => ['width' => '7'],
            ],
          ],
        ],
      ],
      'location' => [[
        ['param' => 'options_page', 'operator' => '==', 'value' => 'lbwp-social-settings'],
      ]],
    ]);
  }

  /**
   * Registers the ACF field group attached to the social post type.
   * Also hooks acf/load_field to dynamically populate the channel checkbox choices.
   */
  protected function registerPostTypeFields(): void
  {
    acf_add_local_field_group([
      'key'    => 'group_soc_post',
      'title'  => __('Social Media Post', 'lbwp'),
      'active' => true,
      'fields' => [
        [
          'key'           => 'field_soc_content_type',
          'label'         => __('Inhaltstyp', 'lbwp'),
          'name'          => 'soc_content_type',
          'type'          => 'select',
          'required'      => 1,
          'choices'       => self::CONTENT_TYPES,
          'default_value' => 'text-short',
          'allow_null'    => 0,
          'multiple'      => 0,
          'ui'            => 1,
          'return_format' => 'value',
          'instructions'  => __('Legt den Formattyp des Social Media Posts fest.', 'lbwp'),
        ],
        [
          'key'          => 'field_soc_text_short',
          'label'        => __('Text (kurz)', 'lbwp'),
          'name'         => 'soc_text_short',
          'type'         => 'textarea',
          'instructions' => __('Maximal 500 Zeichen. Hinweis: Einige Kanäle unterstützen je nach Konfiguration noch kürzere Texte.', 'lbwp'),
          'required'     => 0,
          'rows'         => 4,
          'maxlength'    => 500,
          'conditional_logic' => [
            [['field' => 'field_soc_content_type', 'operator' => '==', 'value' => 'text-short']],
            [['field' => 'field_soc_content_type', 'operator' => '==', 'value' => 'image-short']],
          ],
        ],
        [
          'key'          => 'field_soc_text_long',
          'label'        => __('Text (lang)', 'lbwp'),
          'name'         => 'soc_text_long',
          'type'         => 'textarea',
          'instructions' => __('Maximal 3000 Zeichen. Hinweis: Viele Kanäle unterstützen nur kürzere Texte. Bitte Einschränkungen des jeweiligen Kanals beachten.', 'lbwp'),
          'required'     => 0,
          'rows'         => 10,
          'maxlength'    => 3000,
          'conditional_logic' => [
            [['field' => 'field_soc_content_type', 'operator' => '==', 'value' => 'text-long']],
            [['field' => 'field_soc_content_type', 'operator' => '==', 'value' => 'image-long']],
          ],
        ],
        [
          'key'          => 'field_soc_link_url',
          'label'        => __('Link-URL', 'lbwp'),
          'name'         => 'soc_link_url',
          'type'         => 'url',
          'instructions' => __('Optionaler Link, der am Ende des Posts angefügt wird. Nur bei Textformaten verfügbar.', 'lbwp'),
          'required'     => 0,
          'placeholder'  => 'https://',
          'conditional_logic' => [
            [['field' => 'field_soc_content_type', 'operator' => '==', 'value' => 'text-short']],
            [['field' => 'field_soc_content_type', 'operator' => '==', 'value' => 'text-long']],
          ],
        ],
        [
          'key'           => 'field_soc_channels',
          'label'         => __('Kanäle', 'lbwp'),
          'name'          => 'soc_channels',
          'type'          => 'checkbox',
          'instructions'  => __('Wählen Sie die Zielkanäle. Nur Einträge passend zum gewählten Inhaltstyp werden angezeigt.', 'lbwp'),
          'required'      => 0,
          'choices'       => [],
          'layout'        => 'vertical',
          'toggle'        => 0,
          'return_format' => 'value',
          'allow_custom'  => 0,
        ],
      ],
      'location' => [[
        ['param' => 'post_type', 'operator' => '==', 'value' => self::POST_TYPE],
      ]],
    ]);

    add_filter('acf/load_field/key=field_soc_channels', [$this, 'loadChannelChoices']);
  }

  /**
   * Registers the ACF field group for the Automatisierung options page.
   * Each rule maps a source post type to a handling mode and text source.
   */
  protected function registerAutomationFields(): void
  {
    $postTypes = [];
    foreach (get_post_types(['public' => true], 'objects') as $type) {
      if ($type->name !== self::POST_TYPE) {
        $postTypes[$type->name] = $type->label;
      }
    }

    acf_add_local_field_group([
      'key'    => 'group_soc_automation',
      'title'  => __('Automatisierungsregeln', 'lbwp'),
      'active' => true,
      'fields' => [
        [
          'key'          => 'field_soc_auto_rules',
          'label'        => __('Regeln', 'lbwp'),
          'name'         => 'soc_auto_rules',
          'type'         => 'repeater',
          'instructions' => __('Bei Veröffentlichung des konfigurierten Beitragstyps wird automatisch ein Social Media Post (text-short, max. 300 Zeichen) erstellt und alle passenden Kanäle ausgewählt.', 'lbwp'),
          'required'     => 0,
          'collapsed'    => 'field_soc_auto_post_type',
          'layout'       => 'row',
          'button_label' => __('Regel hinzufügen', 'lbwp'),
          'sub_fields'   => [
            [
              'key'           => 'field_soc_auto_post_type',
              'label'         => __('Beitragstyp', 'lbwp'),
              'name'          => 'soc_auto_post_type',
              'type'          => 'select',
              'required'      => 1,
              'choices'       => $postTypes,
              'allow_null'    => 0,
              'multiple'      => 0,
              'ui'            => 1,
              'return_format' => 'value',
              'wrapper'       => ['width' => '30'],
            ],
            [
              'key'           => 'field_soc_auto_handle',
              'label'         => __('Verarbeitung', 'lbwp'),
              'name'          => 'soc_auto_handle',
              'type'          => 'select',
              'required'      => 1,
              'choices'       => [
                'draft'   => __('Entwurf erstellen', 'lbwp'),
                'publish' => __('Sofort veröffentlichen', 'lbwp'),
              ],
              'allow_null'    => 0,
              'multiple'      => 0,
              'ui'            => 0,
              'return_format' => 'value',
              'wrapper'       => ['width' => '20'],
            ],
            [
              'key'           => 'field_soc_auto_text_source',
              'label'         => __('Textquelle', 'lbwp'),
              'name'          => 'soc_auto_text_source',
              'type'          => 'select',
              'required'      => 1,
              'choices'       => [
                'post_title'   => __('Titel (post_title)', 'lbwp'),
                'post_excerpt' => __('Auszug (post_excerpt)', 'lbwp'),
                'post_content' => __('Aus Inhalt generiert (post_content)', 'lbwp'),
              ],
              'allow_null'    => 0,
              'multiple'      => 0,
              'ui'            => 0,
              'return_format' => 'value',
              'wrapper'       => ['width' => '30'],
            ],
          ],
        ],
      ],
      'location' => [[
        ['param' => 'options_page', 'operator' => '==', 'value' => 'lbwp-social-automation'],
      ]],
    ]);
  }

  /**
   * Registers the ACF field group for the Agent API section on the Einstellungen page.
   * Provides prompt, context, idea count, and URL sourcing config for the agent endpoint.
   */
  protected function registerAgentApiFields(): void
  {
    acf_add_local_field_group([
      'key'    => 'group_soc_agent',
      'title'  => __('Agent API', 'lbwp'),
      'active' => true,
      'fields' => [
        [
          'key'      => 'field_soc_agent_prompt',
          'label'    => __('Prompt', 'lbwp'),
          'name'     => 'soc_agent_prompt',
          'type'     => 'textarea',
          'required' => 0,
          'rows'     => 4,
        ],
        [
          'key'      => 'field_soc_agent_context',
          'label'    => __('Kontext', 'lbwp'),
          'name'     => 'soc_agent_context',
          'type'     => 'textarea',
          'required' => 0,
          'rows'     => 4,
        ],
        [
          'key'           => 'field_soc_agent_ideas',
          'label'         => __('Ideen pro Durchlauf', 'lbwp'),
          'name'          => 'soc_agent_ideas',
          'type'          => 'number',
          'required'      => 0,
          'default_value' => 1,
          'min'           => 1,
          'wrapper'       => ['width' => '25'],
        ],
        [
          'key'           => 'field_soc_agent_link_mode',
          'label'         => __('Verlinken von Inhalten', 'lbwp'),
          'name'          => 'soc_agent_link_mode',
          'type'          => 'radio',
          'required'      => 0,
          'choices'       => [
            'latest-post' => __('Letzte 10 Beiträge', 'lbwp'),
            'manual-urls' => __('Manuelle Eingabe', 'lbwp'),
          ],
          'default_value' => 'latest-post',
          'layout'        => 'horizontal',
          'return_format' => 'value',
        ],
        [
          'key'               => 'field_soc_agent_manual_urls',
          'label'             => __('URLs', 'lbwp'),
          'name'              => 'soc_agent_manual_urls',
          'type'              => 'repeater',
          'required'          => 0,
          'layout'            => 'table',
          'button_label'      => __('URL hinzufügen', 'lbwp'),
          'conditional_logic' => [
            [['field' => 'field_soc_agent_link_mode', 'operator' => '==', 'value' => 'manual-urls']],
          ],
          'sub_fields' => [
            [
              'key'         => 'field_soc_agent_url',
              'label'       => __('URL', 'lbwp'),
              'name'        => 'soc_agent_url',
              'type'        => 'url',
              'required'    => 1,
              'placeholder' => 'https://',
            ],
          ],
        ],
      ],
      'location' => [[
        ['param' => 'options_page', 'operator' => '==', 'value' => 'lbwp-social-settings'],
      ]],
    ]);
  }
}
