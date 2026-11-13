<?php

namespace LBWP\Theme\Component;

use LBWP\Core as LbwpCore;
use LBWP\Helper\PostCopy;
use LBWP\Theme\Base\Component;
use LBWP\Util\File;
use LBWP\Util\WordPress;
use WP_Post;

/**
 * Lets editors revise published posts in a draft working copy, which is merged back onto the original
 * @package LBWP\Theme\Component
 * @author Michael Sebel <michael@comotive.ch>
 */
class PostWorkingCopy extends Component
{
  /**
   * Meta key on the copy holding the original post id
   */
  const string META_COPY_OF = '_lbwp_working_copy_of';
  /**
   * Meta key on the copy holding the original's post_modified_gmt at copy creation
   */
  const string META_BASE = '_lbwp_working_copy_base';
  /**
   * Meta key on the original holding the id of its open working copy
   */
  const string META_COPY = '_lbwp_working_copy';
  /**
   * Request parameter holding the requested action
   */
  const string REQUEST_KEY = 'lbwp-working-copy';
  /**
   * Form field telling the classic editor to continue with an action after saving the copy
   */
  const string AFTER_SAVE_KEY = 'lbwp-working-copy-after-save';
  /**
   * Request parameter for the notice shown after a redirect
   */
  const string NOTICE_KEY = 'lbwp-working-copy-notice';
  /**
   * Statuses that are never allowed on a working copy
   */
  const array BLOCKED_STATUSES = ['publish', 'future', 'private'];
  /**
   * Post fields that are written from the copy onto the original on merge
   */
  const array MERGED_FIELDS = ['post_title', 'post_excerpt', 'post_content'];
  /**
   * Meta keys WordPress keeps per post to redirect old permalinks, they must stay with the original
   */
  const array OLD_PERMALINK_META = ['_wp_old_slug', '_wp_old_date'];
  /**
   * Key used to lock saving of published originals in the block editor
   */
  const string SAVE_LOCK_KEY = 'lbwp-working-copy';

  /**
   * @var array post types supporting working copies
   */
  protected array $postTypes = ['post'];
  /**
   * @var array roles that may not change published posts directly but only through a working copy
   */
  protected array $workingCopyOnlyRoles = [];
  /**
   * @var bool regenerate the original's permalink on merge, if the copy changed the title
   */
  protected bool $slugFollowsTitle = true;
  /**
   * @var bool set the original's publish date to the merge time, so lists showing the post date reflect the merge
   */
  protected bool $mergeUpdatesPostDate = false;
  /**
   * @var bool true while a copy is written onto its original, which bypasses the direct update protection
   */
  protected bool $merging = false;

  /**
   * Register all hooks
   * @return void
   */
  public function init(): void
  {
    add_filter('wp_insert_post_data', [$this, 'preventPublishing'], 100, 2);
    add_filter('wp_insert_post_data', [$this, 'preventDirectUpdate'], 110, 2);
    add_action('wp_trash_post', [$this, 'onPostRemoval']);
    add_action('before_delete_post', [$this, 'onPostRemoval']);
    add_filter('duplicate_post_meta_key_blacklist', [$this, 'addBlacklistedMeta']);

    if (is_admin()) {
      add_filter('post_row_actions', [$this, 'addRowAction'], 10, 2);
      add_filter('page_row_actions', [$this, 'addRowAction'], 10, 2);
      add_filter('display_post_states', [$this, 'addPostState'], 10, 2);
      add_action('add_meta_boxes', [$this, 'addMetabox'], 10, 2);
      add_action('admin_notices', [$this, 'printAdminNotice']);
      add_action('admin_head-post.php', [$this, 'hideClassicPublishButton']);
      add_action('admin_footer-post.php', [$this, 'disableClassicPublishBox']);
      add_filter('redirect_post_location', [$this, 'continueAfterSave'], 10, 2);
      add_action('enqueue_block_editor_assets', [$this, 'enqueueBlockEditorAssets']);
    }
  }

  /**
   * Dispatch start, merge and discard requests
   * @return void
   */
  public function admin(): void
  {
    $action = sanitize_key($_GET[self::REQUEST_KEY] ?? '');
    $postId = intval($_GET['post'] ?? 0);
    if ($action === '' || $postId === 0) {
      return;
    }

    if (!wp_verify_nonce($_GET['_wpnonce'] ?? '', $this->getNonceAction($action, $postId))) {
      wp_die(__('Der Link ist abgelaufen. Bitte versuche es erneut.', 'lbwp'), '', ['response' => 403, 'back_link' => true]);
    }

    match ($action) {
      'start' => $this->startWorkingCopy($postId),
      'merge' => $this->mergeWorkingCopy($postId, isset($_GET['force'])),
      'discard' => $this->discardWorkingCopy($postId),
      default => null
    };
  }

  /**
   * Check if the current user may write a working copy onto its original and thus take it live
   * @param int $originalId the original post
   * @return bool true if merging is allowed
   */
  public function canMerge(int $originalId): bool
  {
    $allowed = current_user_can('edit_post', $originalId) && current_user_can('publish_post', $originalId);
    return apply_filters('lbwp_working_copy_can_merge', $allowed, $originalId);
  }

  /**
   * Check if the current user may change a published post only through a working copy
   * @param WP_Post $post the post to change
   * @return bool true if direct changes are not allowed
   */
  public function mustUseWorkingCopy(WP_Post $post): bool
  {
    if ($this->merging || !$this->supportsWorkingCopy($post) || !is_user_logged_in()) {
      return false;
    }

    $restricted = count(array_intersect($this->workingCopyOnlyRoles, wp_get_current_user()->roles)) > 0;
    return apply_filters('lbwp_working_copy_only', $restricted, $post);
  }

  /**
   * Get the post types supporting working copies
   * @return array post type slugs
   */
  public function getPostTypes(): array
  {
    return apply_filters('lbwp_working_copy_post_types', $this->postTypes);
  }

  /**
   * Get the meta keys that are never copied between original and copy
   * @return array meta keys
   */
  protected function getMetaBlacklist(): array
  {
    return array_merge(PostCopy::getMetaBlacklist(), [self::META_COPY_OF, self::META_BASE, self::META_COPY], self::OLD_PERMALINK_META);
  }

  /**
   * Make sure duplicating an original or a copy never takes the link meta along
   * @param array $blacklist meta keys
   * @return array extended meta keys
   */
  public function addBlacklistedMeta(array $blacklist): array
  {
    return array_merge($blacklist, [self::META_COPY_OF, self::META_BASE, self::META_COPY]);
  }

  /**
   * Get the original post id of a working copy
   * @param int $postId the potential copy
   * @return int the original's id or 0 if the post is no working copy
   */
  public function getOriginalId(int $postId): int
  {
    return intval(get_post_meta($postId, self::META_COPY_OF, true));
  }

  /**
   * Get the open working copy of an original post
   * @param int $postId the original post
   * @return int the copy's id or 0 if there is no open copy
   */
  public function getCopyId(int $postId): int
  {
    $copyId = intval(get_post_meta($postId, self::META_COPY, true));
    if ($copyId === 0) {
      return 0;
    }

    $copy = get_post($copyId);
    if (!$copy instanceof WP_Post || $copy->post_status === 'trash' || $this->getOriginalId($copyId) !== $postId) {
      return 0;
    }

    return $copyId;
  }

  /**
   * Check if a post can get a working copy
   * @param WP_Post $post the post
   * @return bool true if supported
   */
  protected function supportsWorkingCopy(WP_Post $post): bool
  {
    return $post->post_status === 'publish' && in_array($post->post_type, $this->getPostTypes(), true);
  }

  /**
   * Get the nonce action for a request on a post
   * @param string $action start, merge or discard
   * @param int $postId the post the request acts on
   * @return string the nonce action
   */
  protected function getNonceAction(string $action, int $postId): string
  {
    return self::REQUEST_KEY . '-' . $action . '-' . $postId;
  }

  /**
   * Get a nonced admin url for an action
   * @param string $action start, merge or discard
   * @param int $postId the post the request acts on
   * @param array $args additional query args
   * @return string the url (not escaped)
   */
  public function getActionUrl(string $action, int $postId, array $args = []): string
  {
    $args = array_merge([self::REQUEST_KEY => $action, 'post' => $postId], $args, ['_wpnonce' => wp_create_nonce($this->getNonceAction($action, $postId))]);
    return add_query_arg($args, admin_url('index.php'));
  }

  /**
   * Get the edit url of a post with a notice to display
   * @param int $postId the post
   * @param string $notice the notice key
   * @return string the url (not escaped)
   */
  protected function getEditUrl(int $postId, string $notice): string
  {
    return add_query_arg([self::NOTICE_KEY => $notice], admin_url('post.php?action=edit&post=' . $postId));
  }

  /**
   * Create the working copy of a published post, or open the existing one
   * @param int $postId the original post
   * @return void
   */
  protected function startWorkingCopy(int $postId): void
  {
    $post = get_post($postId);
    if (!$post instanceof WP_Post || !$this->supportsWorkingCopy($post) || !current_user_can('edit_post', $postId)) {
      wp_die(__('Für diesen Beitrag kann keine Überarbeitung erstellt werden.', 'lbwp'), '', ['response' => 403, 'back_link' => true]);
    }

    $copyId = $this->getCopyId($postId);
    if ($copyId === 0) {
      $copyId = $this->createWorkingCopy($post);
    }

    wp_safe_redirect(admin_url('post.php?action=edit&post=' . $copyId));
    exit;
  }

  /**
   * Create a draft copy of the original and link both posts
   * @param WP_Post $post the original post
   * @return int the copy's id
   */
  protected function createWorkingCopy(WP_Post $post): int
  {
    $copyId = PostCopy::createCopy($post->ID, ['post_status' => 'draft'], $this->getMetaBlacklist());
    if ($copyId === 0) {
      wp_die(__('Die Überarbeitung konnte nicht erstellt werden.', 'lbwp'), '', ['response' => 500, 'back_link' => true]);
    }

    update_post_meta($copyId, self::META_COPY_OF, $post->ID);
    update_post_meta($copyId, self::META_BASE, $post->post_modified_gmt);
    update_post_meta($post->ID, self::META_COPY, $copyId);

    do_action('lbwp_working_copy_created', $post->ID, $copyId);
    return $copyId;
  }

  /**
   * Write the working copy onto its original and delete the copy
   * @param int $copyId the working copy
   * @param bool $force merge even if the original changed since the copy was created
   * @return void
   */
  protected function mergeWorkingCopy(int $copyId, bool $force): void
  {
    $originalId = $this->getOriginalId($copyId);
    $original = get_post($originalId);
    if (!$original instanceof WP_Post || $original->post_status === 'trash' || $this->getCopyId($originalId) !== $copyId) {
      wp_die(__('Das Original dieser Überarbeitung existiert nicht mehr.', 'lbwp'), '', ['response' => 404, 'back_link' => true]);
    }

    if (!$this->canMerge($originalId)) {
      wp_die(__('Du darfst die Änderungen an diesem Beitrag nicht veröffentlichen.', 'lbwp'), '', ['response' => 403, 'back_link' => true]);
    }

    if (!$force && $this->originalChangedSinceCopy($original, $copyId)) {
      $this->printConflictScreen($original, $copyId);
    }

    $this->writeCopyOntoOriginal($copyId, $originalId);
    do_action('lbwp_working_copy_merged', $originalId, $copyId);
    $this->deleteWorkingCopy($copyId, $originalId);

    wp_safe_redirect($this->getEditUrl($originalId, 'merged'));
    exit;
  }

  /**
   * Check if the original was saved after the working copy was created
   * @param WP_Post $original the original post
   * @param int $copyId the working copy
   * @return bool true if the original changed meanwhile
   */
  protected function originalChangedSinceCopy(WP_Post $original, int $copyId): bool
  {
    $base = strval(get_post_meta($copyId, self::META_BASE, true));
    return $base !== '' && strtotime($original->post_modified_gmt) > strtotime($base);
  }

  /**
   * Ask for confirmation before overwriting changes made to the original meanwhile
   * @param WP_Post $original the original post
   * @param int $copyId the working copy
   * @return never
   */
  protected function printConflictScreen(WP_Post $original, int $copyId): never
  {
    $message = sprintf(
      __('Das Original „%s“ wurde am %s geändert, nachdem diese Überarbeitung erstellt wurde. Beim Veröffentlichen werden diese Änderungen überschrieben.', 'lbwp'),
      esc_html($original->post_title),
      esc_html(mysql2date(get_option('date_format') . ' H:i', $original->post_modified))
    );

    $html = '
      <h1>' . esc_html__('Original wurde inzwischen geändert', 'lbwp') . '</h1>
      <p>' . $message . '</p>
      <p>
        <a class="button button-primary" href="' . esc_url($this->getActionUrl('merge', $copyId, ['force' => 1])) . '">' . esc_html__('Trotzdem veröffentlichen', 'lbwp') . '</a>
        <a class="button" href="' . esc_url(get_edit_post_link($original->ID, 'raw')) . '" target="_blank">' . esc_html__('Original ansehen', 'lbwp') . '</a>
        <a class="button" href="' . esc_url(get_edit_post_link($copyId, 'raw')) . '">' . esc_html__('Zurück zur Überarbeitung', 'lbwp') . '</a>
      </p>
    ';

    wp_die($html, esc_html__('Original wurde inzwischen geändert', 'lbwp'), ['response' => 409]);
    exit;
  }

  /**
   * Write fields, content, meta, terms and attachments of the copy onto the original.
   * The original's state before and after the merge are both kept as revisions, including field values.
   * @param int $copyId the working copy
   * @param int $originalId the original post
   * @return void
   */
  protected function writeCopyOntoOriginal(int $copyId, int $originalId): void
  {
    $this->saveRevision($originalId, false);
    $this->updateOriginal($copyId, $originalId);

    PostCopy::copyContent($copyId, $originalId);
    PostCopy::copyMeta($copyId, $originalId, $this->getMetaBlacklist(), true);
    PostCopy::copyTerms($copyId, $originalId);
    $this->moveAttachments($copyId, $originalId);
    clean_post_cache($originalId);

    $this->saveRevision($originalId, true);
  }

  /**
   * Write the post fields of the copy onto the original, without the automatic revision, as content
   * and meta are only written afterwards and the revision would miss them
   * @param int $copyId the working copy
   * @param int $originalId the original post
   * @return void
   */
  protected function updateOriginal(int $copyId, int $originalId): void
  {
    $copy = get_post($copyId, ARRAY_A);
    $data = ['ID' => $originalId];
    foreach (self::MERGED_FIELDS as $field) {
      $data[$field] = $copy[$field];
    }

    if ($this->mergeUpdatesPostDate) {
      $data['post_date'] = current_time('mysql');
      $data['post_date_gmt'] = current_time('mysql', true);
    }

    if ($this->slugFollowsTitle && $copy['post_title'] !== get_post_field('post_title', $originalId, 'raw')) {
      $data['post_name'] = sanitize_title($copy['post_title']);
    }

    // A normal update sets the modified date, keeps the old permalink redirecting and flushes caches through post_updated
    $this->merging = true;
    add_filter('wp_save_post_revision_post_has_changed', '__return_false', 100);
    $result = wp_update_post(wp_slash($data), true);
    remove_filter('wp_save_post_revision_post_has_changed', '__return_false', 100);
    $this->merging = false;

    if (is_wp_error($result)) {
      wp_die(esc_html($result->get_error_message()), '', ['response' => 500, 'back_link' => true]);
    }
  }

  /**
   * Store the current state of a post including its field values as revision, so it can be compared and restored
   * @param int $postId the post
   * @param bool $force create a new revision even if the latest one already holds the current state
   * @return void
   */
  protected function saveRevision(int $postId, bool $force): void
  {
    $post = get_post($postId);
    if (!$post instanceof WP_Post || !wp_revisions_enabled($post)) {
      return;
    }

    $latest = wp_get_post_revisions($postId, ['posts_per_page' => 1]);
    $latest = reset($latest);
    if (!$force && $latest instanceof WP_Post && $latest->post_date_gmt === $post->post_modified_gmt) {
      $revisionId = $latest->ID;
    } else {
      $revisionId = _wp_put_post_revision($post);
      $revisionId = is_wp_error($revisionId) ? 0 : intval($revisionId);
    }

    if ($revisionId > 0 && function_exists('acf_copy_postmeta')) {
      acf_copy_postmeta($postId, $revisionId);
    }
  }

  /**
   * Re-parent attachments uploaded while editing the copy to the original
   * @param int $copyId the working copy
   * @param int $originalId the original post
   * @return void
   */
  protected function moveAttachments(int $copyId, int $originalId): void
  {
    $db = WordPress::getDb();
    $attachmentIds = $db->get_col($db->prepare(
      'SELECT ID FROM ' . $db->posts . ' WHERE post_parent = %d AND post_type = "attachment"',
      $copyId
    ));

    if (count($attachmentIds) === 0) {
      return;
    }

    $db->update($db->posts, ['post_parent' => $originalId], ['post_parent' => $copyId, 'post_type' => 'attachment']);
    foreach ($attachmentIds as $attachmentId) {
      clean_post_cache(intval($attachmentId));
    }
  }

  /**
   * Delete the working copy without merging it
   * @param int $copyId the working copy
   * @return void
   */
  protected function discardWorkingCopy(int $copyId): void
  {
    $originalId = $this->getOriginalId($copyId);
    if ($originalId === 0 || !current_user_can('delete_post', $copyId)) {
      wp_die(__('Du darfst diese Überarbeitung nicht verwerfen.', 'lbwp'), '', ['response' => 403, 'back_link' => true]);
    }

    $this->deleteWorkingCopy($copyId, $originalId);

    wp_safe_redirect($this->getEditUrl($originalId, 'discarded'));
    exit;
  }

  /**
   * Permanently delete a working copy and unlink it from its original
   * @param int $copyId the working copy
   * @param int $originalId the original post
   * @return void
   */
  protected function deleteWorkingCopy(int $copyId, int $originalId): void
  {
    delete_post_meta($originalId, self::META_COPY, $copyId);
    wp_delete_post($copyId, true);
  }

  /**
   * Never let a working copy go live, as that would create a public duplicate of the original.
   * Users who may not merge submit the copy for review instead (also explicitly from the classic metabox,
   * as the classic status select is not available to every role).
   * @param array $data sanitized post data to be saved
   * @param array $postarr raw post data including the ID
   * @return array the possibly changed post data
   */
  public function preventPublishing(array $data, array $postarr): array
  {
    $postId = intval($postarr['ID'] ?? 0);
    $originalId = $postId > 0 ? $this->getOriginalId($postId) : 0;
    if ($originalId === 0) {
      return $data;
    }

    if (($_POST[self::AFTER_SAVE_KEY] ?? '') === 'submit') {
      $data['post_status'] = 'pending';
    } elseif (in_array($data['post_status'], self::BLOCKED_STATUSES, true)) {
      $data['post_status'] = $this->canMerge($originalId) ? 'draft' : 'pending';
    }

    return $data;
  }

  /**
   * Block direct changes to published originals by users who must go through a working copy.
   * Moving the post to the trash stays possible, it deletes the copy along with it.
   * @param array $data sanitized post data to be saved
   * @param array $postarr raw post data including the ID
   * @return array the unchanged post data
   */
  public function preventDirectUpdate(array $data, array $postarr): array
  {
    $post = get_post(intval($postarr['ID'] ?? 0));
    if (!$post instanceof WP_Post || $data['post_status'] === 'trash' || !$this->mustUseWorkingCopy($post)) {
      return $data;
    }

    wp_die(
      __('Veröffentlichte Inhalte können nur über eine Überarbeitung geändert werden.', 'lbwp'),
      '',
      ['response' => 403, 'back_link' => true]
    );
  }

  /**
   * Redirect to the merge after the classic editor saved the copy, so no unsaved changes get lost
   * @param string $location the redirect url after saving
   * @param int $postId the saved post
   * @return string the possibly changed redirect url
   */
  public function continueAfterSave(string $location, int $postId): string
  {
    if (($_POST[self::AFTER_SAVE_KEY] ?? '') !== 'merge' || $this->getOriginalId($postId) === 0) {
      return $location;
    }

    return $this->getActionUrl('merge', $postId);
  }

  /**
   * Delete the working copy along with its original, or unlink a removed copy from its original
   * @param int $postId the trashed or deleted post
   * @return void
   */
  public function onPostRemoval(int $postId): void
  {
    $originalId = $this->getOriginalId($postId);
    if ($originalId > 0) {
      delete_post_meta($originalId, self::META_COPY, $postId);
      return;
    }

    $copyId = $this->getCopyId($postId);
    if ($copyId > 0) {
      $this->deleteWorkingCopy($copyId, $postId);
    }
  }

  /**
   * Add the "revise" row action to published posts
   * @param array $actions row actions
   * @param WP_Post $post the listed post
   * @return array extended row actions
   */
  public function addRowAction(array $actions, WP_Post $post): array
  {
    if (!$this->supportsWorkingCopy($post) || !current_user_can('edit_post', $post->ID)) {
      return $actions;
    }

    if ($this->mustUseWorkingCopy($post)) {
      unset($actions['inline hide-if-no-js']);
    }

    $label = $this->getCopyId($post->ID) > 0 ? __('Überarbeitung fortsetzen', 'lbwp') : __('Überarbeiten', 'lbwp');
    $actions['lbwp_working_copy'] = '<a href="' . esc_url($this->getActionUrl('start', $post->ID)) . '">' . esc_html($label) . '</a>';
    return $actions;
  }

  /**
   * Show a post state on working copies in the list table
   * @param array $states post states
   * @param WP_Post $post the listed post
   * @return array extended post states
   */
  public function addPostState(array $states, WP_Post $post): array
  {
    if ($this->getOriginalId($post->ID) > 0) {
      $states['lbwp_working_copy'] = __('Überarbeitung', 'lbwp');
    } elseif ($this->getCopyId($post->ID) > 0) {
      $states['lbwp_working_copy'] = __('In Überarbeitung', 'lbwp');
    }

    return $states;
  }

  /**
   * Add the side metabox on originals and copies (copies in the block editor get a status panel instead)
   * @param string $postType the edited post's type
   * @param WP_Post|mixed $post the edited post
   * @return void
   */
  public function addMetabox(string $postType, $post): void
  {
    if (!$post instanceof WP_Post || !in_array($postType, $this->getPostTypes(), true)) {
      return;
    }

    if ($this->getOriginalId($post->ID) > 0 && !use_block_editor_for_post($post)) {
      add_meta_box('lbwp-working-copy', __('Überarbeitung', 'lbwp'), [$this, 'displayCopyMetabox'], $postType, 'side', 'high');
    } elseif ($post->post_status === 'publish') {
      add_meta_box('lbwp-working-copy', __('Überarbeitung', 'lbwp'), [$this, 'displayOriginalMetabox'], $postType, 'side', 'high');
    }
  }

  /**
   * Display the metabox on the original with a link to start or continue the revision
   * @param WP_Post $post the original post
   * @return void
   */
  public function displayOriginalMetabox(WP_Post $post): void
  {
    if (!current_user_can('edit_post', $post->ID)) {
      return;
    }

    $copyId = $this->getCopyId($post->ID);
    $description = $copyId > 0
      ? __('Für diesen Inhalt ist eine Überarbeitung in Gange.', 'lbwp')
      : __('Änderungen in einer Kopie vorbereiten, während dieser Inhalt online bleibt.', 'lbwp');
    $label = $copyId > 0 ? __('Überarbeitung fortsetzen', 'lbwp') : __('Überarbeiten starten', 'lbwp');
    $restriction = $this->mustUseWorkingCopy($post) ? '<p><strong>' . esc_html($this->getWorkingCopyOnlyMessage()) . '</strong></p>' : '';

    echo '
      ' . $restriction . '
      <p>' . esc_html($description) . '</p>
      <p><a class="button" href="' . esc_url($this->getActionUrl('start', $post->ID)) . '">' . esc_html($label) . '</a></p>
    ';
  }

  /**
   * Get everything the copy's editor UI needs, shared by the classic metabox and the block editor panel
   * @param WP_Post $copy the working copy
   * @return array ui configuration
   */
  protected function getCopyUiConfig(WP_Post $copy): array
  {
    $originalId = $this->getOriginalId($copy->ID);
    $canMerge = $this->canMerge($originalId);
    $isPending = $copy->post_status === 'pending';

    return [
      'originalTitle' => get_the_title($originalId),
      'originalUrl' => get_edit_post_link($originalId, 'raw'),
      'canMerge' => $canMerge,
      'canSubmit' => !$canMerge,
      'canDiscard' => current_user_can('delete_post', $copy->ID),
      'mergeUrl' => $this->getActionUrl('merge', $copy->ID),
      'discardUrl' => $this->getActionUrl('discard', $copy->ID),
      'labels' => [
        'copyOf' => __('Überarbeitung von:', 'lbwp'),
        'info' => __('Das Original bleibt unverändert online, bis die Änderungen veröffentlicht werden.', 'lbwp'),
        'pending' => $canMerge
          ? __('Diese Überarbeitung wurde zur Prüfung eingereicht.', 'lbwp')
          : __('Zur Prüfung eingereicht. Die Redaktion veröffentlicht die Änderungen nach der Freigabe.', 'lbwp'),
        'merge' => __('Änderungen veröffentlichen', 'lbwp'),
        'submit' => __('Zur Prüfung einreichen', 'lbwp'),
        'submitted' => __('Die Überarbeitung wurde zur Prüfung eingereicht.', 'lbwp'),
        'discard' => __('Verwerfen', 'lbwp'),
        'confirmDiscard' => __('Überarbeitung wirklich verwerfen? Alle Änderungen gehen verloren.', 'lbwp'),
      ],
      'isPending' => $isPending,
    ];
  }

  /**
   * Display the classic editor metabox on the copy with the merge, submit and discard actions
   * @param WP_Post $post the working copy
   * @return void
   */
  public function displayCopyMetabox(WP_Post $post): void
  {
    if (!get_post($this->getOriginalId($post->ID)) instanceof WP_Post) {
      echo '<p>' . esc_html__('Das Original dieser Überarbeitung existiert nicht mehr.', 'lbwp') . '</p>';
      return;
    }

    $config = $this->getCopyUiConfig($post);
    $labels = $config['labels'];
    echo '
      <p>' . esc_html($labels['copyOf']) . ' <a href="' . esc_url($config['originalUrl']) . '">' . esc_html($config['originalTitle']) . '</a></p>
      <p>' . esc_html($labels['info']) . '</p>
      ' . ($config['isPending'] ? '<p><strong>' . esc_html($labels['pending']) . '</strong></p>' : '') . '
      <input type="hidden" name="' . esc_attr(self::AFTER_SAVE_KEY) . '" id="lbwp-working-copy-after-save" value="">
    ';

    if ($config['canMerge']) {
      echo '<p><button type="button" class="button button-primary" data-working-copy="merge">' . esc_html($labels['merge']) . '</button></p>';
    } elseif (!$config['isPending']) {
      echo '<p><button type="button" class="button button-primary" data-working-copy="submit">' . esc_html($labels['submit']) . '</button></p>';
    }

    if ($config['canDiscard']) {
      echo '<p><a class="button" href="' . esc_url($config['discardUrl']) . '" data-working-copy="discard" data-confirm="' . esc_attr($labels['confirmDiscard']) . '">' . esc_html($labels['discard']) . '</a></p>';
    }

    $this->printMetaboxScript();
  }

  /**
   * Save the classic editor form before merging or submitting, so no unsaved changes get lost
   * @return void
   */
  protected function printMetaboxScript(): void
  {
    echo '
      <script>
        document.querySelectorAll("[data-working-copy]").forEach((element) => {
          element.addEventListener("click", (event) => {
            const action = element.dataset.workingCopy;
            if (action === "discard") {
              if (!window.confirm(element.dataset.confirm)) {
                event.preventDefault();
              }
              return;
            }
            document.getElementById("lbwp-working-copy-after-save").value = action;
            document.getElementById("save-post").click();
          });
        });
      </script>
    ';
  }

  /**
   * Hide the native publish button on copies in the classic editor, the metabox offers the right action
   * @return void
   */
  public function hideClassicPublishButton(): void
  {
    $post = get_post();
    if ($post instanceof WP_Post && $this->getOriginalId($post->ID) > 0) {
      echo '<style>#publishing-action { display: none; }</style>';
    }
  }

  /**
   * Get the hint for users who may change published posts only through a working copy
   * @return string the translated message
   */
  protected function getWorkingCopyOnlyMessage(): string
  {
    return __('Veröffentlichte Inhalte können nur über eine Überarbeitung geändert werden.', 'lbwp');
  }

  /**
   * Grey out the classic publish box on originals that may only be changed through a working copy
   * @return void
   */
  public function disableClassicPublishBox(): void
  {
    $post = get_post();
    if (!$post instanceof WP_Post || use_block_editor_for_post($post) || !$this->mustUseWorkingCopy($post)) {
      return;
    }

    echo '
      <style>#submitdiv .inside { opacity: .5; pointer-events: none; }</style>
      <script>
        document.querySelectorAll("#submitdiv input, #submitdiv select, #submitdiv button").forEach((element) => element.disabled = true);
        document.getElementById("post").addEventListener("submit", (event) => event.preventDefault());
      </script>
    ';
  }

  /**
   * Lock saving of an original in the block editor and point to the working copy instead
   * @param WP_Post $post the original post
   * @return void
   */
  protected function lockBlockEditorSaving(WP_Post $post): void
  {
    $label = $this->getCopyId($post->ID) > 0 ? __('Überarbeitung fortsetzen', 'lbwp') : __('Überarbeiten starten', 'lbwp');
    $notice = [
      'isDismissible' => false,
      'actions' => [['label' => $label, 'url' => $this->getActionUrl('start', $post->ID)]],
    ];

    wp_add_inline_script('wp-edit-post', '
      wp.domReady(() => {
        wp.data.dispatch("core/editor").lockPostSaving(' . wp_json_encode(self::SAVE_LOCK_KEY) . ');
        wp.data.dispatch("core/notices").createWarningNotice(' . wp_json_encode($this->getWorkingCopyOnlyMessage()) . ', ' . wp_json_encode($notice) . ');
      });
    ');
    wp_add_inline_style('wp-edit-post', '.editor-post-publish-button__button, .editor-post-publish-panel__toggle, .editor-post-switch-to-draft { opacity: .5; pointer-events: none; }');
  }

  /**
   * Add the status panel for copies, the save lock for originals and the result notice to the block editor
   * @return void
   */
  public function enqueueBlockEditorAssets(): void
  {
    $this->addBlockEditorNotice();

    $post = get_post();
    if ($post instanceof WP_Post && $this->mustUseWorkingCopy($post)) {
      $this->lockBlockEditorSaving($post);
      return;
    }

    if (!$post instanceof WP_Post || $this->getOriginalId($post->ID) === 0) {
      return;
    }

    wp_enqueue_script(
      'lbwp-working-copy',
      File::getResourceUri() . '/js/components/lbwp-working-copy.js',
      ['wp-plugins', 'wp-editor', 'wp-edit-post', 'wp-element', 'wp-components', 'wp-data'],
      LbwpCore::REVISION,
      true
    );
    wp_add_inline_script('lbwp-working-copy', 'window.lbwpWorkingCopy = ' . wp_json_encode($this->getCopyUiConfig($post)) . ';', 'before');
    wp_add_inline_style('wp-edit-post', '.editor-post-publish-button__button, .editor-post-publish-panel__toggle { display: none !important; }');
  }

  /**
   * Get the translated text for a notice key
   * @param string $notice the notice key
   * @return string the message or empty string if unknown
   */
  protected function getNoticeMessage(string $notice): string
  {
    return match ($notice) {
      'merged' => __('Die Änderungen der Überarbeitung wurden veröffentlicht.', 'lbwp'),
      'discarded' => __('Die Überarbeitung wurde verworfen.', 'lbwp'),
      default => ''
    };
  }

  /**
   * Print the result notice on classic admin screens
   * @return void
   */
  public function printAdminNotice(): void
  {
    $message = $this->getNoticeMessage(sanitize_key($_GET[self::NOTICE_KEY] ?? ''));
    $post = get_post();
    if ($message === '' || ($post instanceof WP_Post && use_block_editor_for_post($post))) {
      return;
    }

    echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($message) . '</p></div>';
  }

  /**
   * Show the result notice in the block editor, which does not render admin_notices
   * @return void
   */
  protected function addBlockEditorNotice(): void
  {
    $message = $this->getNoticeMessage(sanitize_key($_GET[self::NOTICE_KEY] ?? ''));
    if ($message === '') {
      return;
    }

    wp_add_inline_script(
      'wp-edit-post',
      'wp.domReady(() => wp.data.dispatch("core/notices").createSuccessNotice(' . wp_json_encode($message) . ', { type: "snackbar" }));'
    );
  }
}
