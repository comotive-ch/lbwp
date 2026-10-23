<?php

namespace LBWP\Module\General\Cms\DeadLinkChecker;

use LBWP\Util\WordPress;

/**
 * Keeps the link table in sync with published content: per post on save and as a
 * periodic full rebuild that also catches changes made directly in the database.
 * @package LBWP\Module\General\Cms\DeadLinkChecker
 * @author Mirko Baffa <mirko@comotive.ch>
 */
class LinkIndexer
{
  /**
   * @var string option holding the timestamp of the last full rebuild
   */
  const LAST_REBUILD_OPTION = 'lbwp_deadlinks_last_rebuild';
  /**
   * @var string option holding the timestamp the next full rebuild is due
   */
  const NEXT_REBUILD_OPTION = 'lbwp_deadlinks_next_rebuild';
  /**
   * @var int days between full rebuilds
   */
  const REBUILD_INTERVAL_DAYS = 30;
  /**
   * @var int max random extra days per rebuild, so rebuilds of many sites drift apart
   */
  const REBUILD_SPREAD_DAYS = 7;
  /**
   * @var int posts loaded per batch during a full rebuild
   */
  const BATCH_SIZE = 200;
  /**
   * @var string[] internal post types never scanned for links
   */
  const EXCLUDED_POST_TYPES = [
    'attachment', 'revision', 'nav_menu_item', 'customize_changeset', 'oembed_cache',
    'user_request', 'wp_template', 'wp_template_part', 'wp_global_styles', 'wp_navigation',
    'wp_font_family', 'wp_font_face', 'acf-field', 'acf-field-group', 'acf-post-type',
    'acf-taxonomy', 'acf-ui-options-page'
  ];

  /**
   * @var LinkTable
   */
  protected $table;
  /**
   * @var LinkExtractor
   */
  protected $extractor = null;

  /**
   * @param LinkTable $table the link table
   */
  public function __construct(LinkTable $table)
  {
    $this->table = $table;
  }

  /**
   * Register the hooks keeping the index in sync
   *
   * @return void
   */
  public function setup()
  {
    // Late, so meta saved by ACF on save_post is already stored
    add_action('save_post', [$this, 'onSavePost'], 50, 2);
    add_action('deleted_post', [$this, 'onDeletedPost']);
  }

  /**
   * Re-index a post when saved, or remove its links when it's no longer published
   *
   * @param int $postId the post id
   * @param \WP_Post $post the post
   * @return void
   */
  public function onSavePost($postId, $post)
  {
    if (wp_is_post_revision($postId) || wp_is_post_autosave($postId)) {
      return;
    }

    if ($post->post_status !== 'publish' || !in_array($post->post_type, $this->getPostTypes())) {
      $this->table->deleteSource($postId);
      return;
    }

    $this->indexPost($postId, $post->post_content, $this->getMetaValues([$postId])[$postId] ?? []);
  }

  /**
   * Remove links of a deleted post
   *
   * @param int $postId the post id
   * @return void
   */
  public function onDeletedPost($postId)
  {
    $this->table->deleteSource($postId);
  }

  /**
   * Check if the last full rebuild is too old
   *
   * @return bool true if a rebuild is needed
   */
  public function isRebuildDue()
  {
    $fallback = $this->getLastRebuild() + self::REBUILD_INTERVAL_DAYS * DAY_IN_SECONDS;
    return intval(get_option(self::NEXT_REBUILD_OPTION, $fallback)) < time();
  }

  /**
   * Get the timestamp of the last full rebuild
   *
   * @return int timestamp or 0 if never rebuilt
   */
  public function getLastRebuild()
  {
    return intval(get_option(self::LAST_REBUILD_OPTION, 0));
  }

  /**
   * Re-index all published posts in batches and drop links of vanished sources
   *
   * @return void
   */
  public function rebuild()
  {
    $db = WordPress::getDb();
    $postTypes = $this->getPostTypes();
    $types = implode(',', array_fill(0, count($postTypes), '%s'));
    $lastId = 0;

    do {
      $posts = $db->get_results($db->prepare("
        SELECT ID, post_content FROM {$db->posts}
        WHERE post_status = 'publish' AND post_type IN ({$types}) AND ID > %d
        ORDER BY ID ASC LIMIT %d
      ", array_merge($postTypes, [$lastId, self::BATCH_SIZE])), ARRAY_A);

      $meta = $this->getMetaValues(array_column($posts, 'ID'));
      foreach ($posts as $post) {
        $this->indexPost($post['ID'], $post['post_content'], $meta[$post['ID']] ?? []);
        $lastId = intval($post['ID']);
      }
    } while (count($posts) === self::BATCH_SIZE);

    $this->table->deleteOrphans($postTypes);
    $days = self::REBUILD_INTERVAL_DAYS + mt_rand(0, self::REBUILD_SPREAD_DAYS);
    update_option(self::LAST_REBUILD_OPTION, time(), false);
    update_option(self::NEXT_REBUILD_OPTION, time() + $days * DAY_IN_SECONDS, false);
  }

  /**
   * Public post types plus synced patterns, filterable with lbwp_dlc_post_types.
   * Internal types like orders are skipped, they are not shown on the site.
   *
   * @return string[] post types
   */
  public function getPostTypes()
  {
    $types = array_merge(array_values(get_post_types(['public' => true])), ['wp_block']);
    $types = array_diff($types, self::EXCLUDED_POST_TYPES);
    return array_values(apply_filters('lbwp_dlc_post_types', $types));
  }

  /**
   * Extract the links of a post and store them
   *
   * @param int $postId the post id
   * @param string $content the post content
   * @param string[] $metaValues meta values of the post
   * @return void
   */
  protected function indexPost($postId, $content, array $metaValues)
  {
    $urls = $this->getExtractor()->extract($content);
    foreach ($metaValues as $value) {
      $urls = array_merge($urls, $this->getExtractor()->extract($value));
    }

    $this->table->syncSource($postId, array_unique($urls));
  }

  /**
   * Load meta values that could contain links, skipping private (underscore) meta keys.
   * Can be disabled with the lbwp_dlc_scan_meta filter.
   *
   * @param int[] $postIds post ids
   * @return array post id => list of meta values
   */
  protected function getMetaValues(array $postIds)
  {
    if (count($postIds) === 0 || !apply_filters('lbwp_dlc_scan_meta', true)) {
      return [];
    }

    $db = WordPress::getDb();
    $ids = implode(',', array_map('intval', $postIds));
    $rows = $db->get_results("
      SELECT post_id, meta_value FROM {$db->postmeta}
      WHERE post_id IN ({$ids}) AND meta_key NOT LIKE '\\_%'
        AND (meta_value LIKE '%http%' OR meta_value LIKE '%href%')
    ", ARRAY_A);

    $values = [];
    foreach ($rows as $row) {
      $values[$row['post_id']][] = $row['meta_value'];
    }

    return $values;
  }

  /**
   * Lazy load the extractor, as it is only needed when content is indexed
   *
   * @return LinkExtractor the extractor
   */
  protected function getExtractor()
  {
    if ($this->extractor === null) {
      $this->extractor = new LinkExtractor();
    }

    return $this->extractor;
  }
}
