<?php

namespace LBWP\Helper;

use LBWP\Theme\Component\Crm\Core;
use LBWP\Util\WordPress;

/**
 * Copies posts including raw content, meta and terms (used by duplication and working copies)
 * @package LBWP\Helper
 * @author Michael Sebel <michael@comotive.ch>
 */
class PostCopy
{
  /**
   * Meta keys that are never copied between posts
   */
  const array META_BLACKLIST = [
    '_edit_lock',
    '_edit_last',
    'snap_isAutoPosted',
    'subscribeInfo',
    Core::LIST_HISTORY_META
  ];
  /**
   * Taxonomies that are never copied between posts
   */
  const array TAXONOMY_BLACKLIST = ['post_translations'];

  /**
   * Get the filtered global meta blacklist
   * @return array list of meta keys
   */
  public static function getMetaBlacklist(): array
  {
    return apply_filters('duplicate_post_meta_key_blacklist', self::META_BLACKLIST);
  }

  /**
   * Insert a copy of the source post and copy its content, meta and terms onto it
   * @param int $sourceId the post to copy
   * @param array $overrides post fields to set on the copy (e.g. post_status, post_title)
   * @param array|null $metaBlacklist meta keys to skip, defaults to the global blacklist
   * @return int the new post id or 0 on failure
   */
  public static function createCopy(int $sourceId, array $overrides = [], ?array $metaBlacklist = null): int
  {
    $post = get_post($sourceId, ARRAY_A);
    if (!is_array($post)) {
      return 0;
    }

    $metaBlacklist ??= self::getMetaBlacklist();
    unset($post['ID']);
    $post = array_merge($post, $overrides);

    // Insert with a surely unique slug, the real one is calculated after insert
    $postNameOriginal = $post['post_name'];
    $post['post_name'] = $post['post_name'] . '-' . md5(time());
    // Content is written natively afterwards, as wp_insert_post breaks ACF / Gutenberg blocks
    $post['post_content'] = '';

    if (apply_filters('duplicate_post_' . $post['post_type'] . '_has_callback', false)) {
      $meta = array_map(fn($values) => count($values) === 1 ? $values[0] : $values, self::getMetaValues($sourceId, $metaBlacklist));
      $newPostId = apply_filters('duplicate_post_' . $post['post_type'], $post, $meta);
    } else {
      $newPostId = wp_insert_post(wp_slash($post));
    }

    $newPostId = intval($newPostId);
    if ($newPostId === 0) {
      return 0;
    }

    $postName = wp_unique_post_slug($postNameOriginal, $newPostId, 'publish', $post['post_type'], $post['post_parent']);
    $content = apply_filters('lbwp_duplicate_post_content_before_save', get_post_field('post_content', $sourceId, 'raw'));
    $db = WordPress::getDb();
    $db->update($db->posts, ['post_name' => $postName, 'post_content' => $content], ['ID' => $newPostId]);

    self::copyTerms($sourceId, $newPostId);
    self::copyMeta($sourceId, $newPostId, $metaBlacklist);
    clean_post_cache($newPostId);

    return $newPostId;
  }

  /**
   * Copy the raw post content natively, bypassing all wp_insert_post filters
   * @param int $fromId the source post
   * @param int $toId the target post
   * @return void
   */
  public static function copyContent(int $fromId, int $toId): void
  {
    $db = WordPress::getDb();
    $db->update(
      $db->posts,
      ['post_content' => get_post_field('post_content', $fromId, 'raw')],
      ['ID' => $toId]
    );

    clean_post_cache($toId);
  }

  /**
   * Copy all non blacklisted meta data from one post to another
   * @param int $fromId the source post
   * @param int $toId the target post
   * @param array $blacklist meta keys to skip (and to keep on the target when replacing)
   * @param bool $replace delete the target's non blacklisted meta first, so cleared fields are cleared on the target too
   * @return void
   */
  public static function copyMeta(int $fromId, int $toId, array $blacklist, bool $replace = false): void
  {
    if ($replace) {
      foreach (array_keys(self::getMetaValues($toId, $blacklist)) as $key) {
        delete_post_meta($toId, $key);
      }
    }

    foreach (self::getMetaValues($fromId, $blacklist) as $key => $values) {
      if (count($values) === 1) {
        update_post_meta($toId, $key, wp_slash(maybe_unserialize($values[0])));
        continue;
      }

      foreach ($values as $value) {
        add_post_meta($toId, $key, wp_slash(maybe_unserialize($value)));
      }
    }
  }

  /**
   * Copy all terms of all taxonomies (except blacklisted ones), replacing the target's terms
   * @param int $fromId the source post
   * @param int $toId the target post
   * @return void
   */
  public static function copyTerms(int $fromId, int $toId): void
  {
    $taxonomies = array_diff(get_object_taxonomies(get_post_type($fromId)), self::TAXONOMY_BLACKLIST);

    foreach ($taxonomies as $taxonomy) {
      $termIds = wp_get_object_terms($fromId, $taxonomy, ['fields' => 'ids']);
      if (is_array($termIds)) {
        wp_set_object_terms($toId, array_map('intval', $termIds), $taxonomy);
      }
    }
  }

  /**
   * Get all raw meta values of a post, keyed by meta key, without blacklisted keys
   * @param int $postId the post
   * @param array $blacklist meta keys to skip
   * @return array meta key => list of raw (serialized) values
   */
  protected static function getMetaValues(int $postId, array $blacklist): array
  {
    $meta = get_post_custom($postId);
    if (!is_array($meta)) {
      return [];
    }

    return array_diff_key($meta, array_flip($blacklist));
  }
}
