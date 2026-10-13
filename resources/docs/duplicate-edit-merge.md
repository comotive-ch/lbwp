# Duplicate → Edit → Merge (working copies for published posts)

Status: implemented 2026-10-12 (`LBWP\Helper\PostCopy`, `LBWP\Theme\Component\PostWorkingCopy`, `resources/js/components/lbwp-working-copy.js`). Enabled in content-arena-s03 for `post` and `news` via `ContentArena\Component\PostWorkingCopy`: only editors/administrators merge (`canMerge()`), everyone else submits the copy for review (publish on a copy becomes `pending`). Written 2026-10-09 after a request from content-arena-s03.

## Goal

An editor can change a published post over several days while the live version stays visible and unchanged.
When they are done, one click writes the changes onto the original post. The post keeps its ID, URL, comments
and author. Similar to TYPO3 workspaces, but per post and as simple as possible.

Keep the UX to three actions:

1. **"Überarbeiten"** on a published post (row action plus a button on the edit screen) creates or opens the working copy.
2. Edit and preview the copy like any draft.
3. **"Änderungen veröffentlichen"** merges the copy into the original and deletes the copy, or **"Verwerfen"** deletes the copy.

## Building on `PostDuplicate`

`LBWP/Module/Backend/PostDuplicate.php` already solves the hard part of copying a post:

- copying content via `$wpdb->update`, because `wp_insert_post` breaks ACF/Gutenberg block content
- copying meta with a filterable blacklist (`duplicate_post_meta_key_blacklist`)
- copying terms, skipping `post_translations`
- running the `after_post_duplicate` hooks

Right now all of that sits in one protected `duplicatePost()` method that reads from `$_GET`, redirects and
exits. The component can't call it.

**Step 1 – extract the copy logic** into a reusable helper `LBWP\Helper\PostCopy`, without changing behaviour:

- `createCopy(int $sourceId, array $overrides = []): int`: insert the copy, then copy content, meta and terms
- `copyContent(int $fromId, int $toId): void`
- `copyMeta(int $fromId, int $toId, array $blacklist, bool $replace = false): void`. With `$replace`, the target's
  non-blacklisted meta is deleted first, so fields cleared on the copy are also cleared on the original.
- `copyTerms(int $fromId, int $toId): void`

After that, `PostDuplicate::duplicatePost()` only handles the nonce, capability check and redirect, then calls
`PostCopy::createCopy()`. The existing filters and actions stay where they are.

## New component: `LBWP\Theme\Component\PostWorkingCopy`

A concrete `Component` (it needs no ACF). Themes register it in `registerComponents()`, or register a subclass
of it for theme-specific behaviour.

### Data model

| Where | Meta key | Value |
|---|---|---|
| copy | `_lbwp_working_copy_of` | original post ID |
| copy | `_lbwp_working_copy_base` | original `post_modified_gmt` at copy creation (conflict check) |
| original | `_lbwp_working_copy` | copy post ID (one open copy per post) |

The copy is a normal post of the same type with status `draft`. It isn't public, previews work natively, and no
custom status is needed. All three keys go into the meta blacklist so they are never copied.

### Flow

- **Start** (`admin_init`, GET with nonce):
  - Require `edit_post` on the original and status `publish`.
  - If `_lbwp_working_copy` points to an existing copy, redirect to it.
  - Otherwise call `PostCopy::createCopy($id, ['post_status' => 'draft'])`. Keep the original title (no
    "Kopie von"), author and date. Set the link meta and redirect to the copy's edit screen.
- **Block accidental publishing** (`wp_insert_post_data`): if the post is a working copy and the requested status is
  `publish` or `future`, force `draft`. Otherwise the native publish button would create a live duplicate.
  Phase 2 could relabel or replace that button through a small block editor plugin (`PluginPostStatusInfo`).
- **Merge** (GET with nonce, from the copy's side metabox):
  1. Require `edit_post` on the original and `publish_posts` (checked against the **original**).
  2. If the original's `post_modified_gmt` is newer than `_lbwp_working_copy_base`, show a confirmation screen
     ("Das Original wurde inzwischen geändert …") before overwriting.
  3. `wp_update_post` on the original with the copy's `post_title`, `post_excerpt` and `post_content`, then
     `copyContent()` to restore the exact block content. Using `wp_update_post` creates a normal revision of the
     original, so the merge can be rolled back, and it fires `post_updated`, which flushes the HTML cache through
     `MemcachedAdmin::onPostSavedFlush`.
  4. `copyMeta(copy, original, blacklist, replace: true)` and `copyTerms(copy, original)`. This covers the featured
     image, since that is `_thumbnail_id`.
  5. Move attachments uploaded while editing the copy (`post_parent = copy`) to the original.
  6. Fire `do_action('lbwp_working_copy_merged', $originalId, $copyId)`, delete the copy (`wp_delete_post($copyId, true)`)
     and the `_lbwp_working_copy` meta, then redirect to the original with a success notice.
- **Discard**: delete the copy permanently and remove the link meta.
- **Cleanup**: when the original is trashed or deleted (`before_delete_post` / `wp_trash_post`), delete its working copy.

Never copied back onto the original: `ID`, `post_name`, `post_author`, `post_date`, `post_status`, `post_parent`,
`menu_order` and the `post_translations` terms.

### UI

- Row action **"Überarbeiten"** on published posts, or **"Überarbeitung fortsetzen"** if a copy already exists.
- Side metabox on the original: the "Überarbeiten" button, or a link to the open copy. Classic metaboxes also work
  in the block editor, so no JS build is needed for v1.
- Side metabox on the copy: "Überarbeitung von: [Original-Titel]", plus buttons **Änderungen veröffentlichen** and
  **Verwerfen**.
- `display_post_states` shows "Überarbeitung" next to copies in the list table.

### Extension points (for themes)

- `protected array $postTypes = ['post']`, overridable in a subclass or through the
  `lbwp_working_copy_post_types` filter
- `protected function getMetaBlacklist(): array`, which starts from `PostDuplicate`'s global blacklist
- actions `lbwp_working_copy_created($originalId, $copyId)` and `lbwp_working_copy_merged($originalId, $copyId)`

## Things to verify per theme (content-arena-s03)

- `Content::beforeSavePost` (`wp_insert_post_data`) and `Content::saveAuthorNameMeta` (`save_post`) run on the copy
  and on the merge. Make sure they don't rewrite author or derived meta wrongly.
- `Backend.php` grants `publish_posts` dynamically per author group. Merge rights have to resolve against the original.
- `ACF::clearHiddenFields` (`acf/save_post`) must not wipe fields on the copy before the merge.
- `GroupNotification` and any other "post published" listeners: the copy must never trigger them. The merge is an
  update of an already published post, so it should not re-notify either. Confirm this.

## Implementation order

1. Extract `PostCopy` from `PostDuplicate`, and check that duplication still behaves the same.
2. `PostWorkingCopy`: start, publish blocking, discard, merge (without the conflict check), side metaboxes.
3. Conflict check, attachment re-parenting, cleanup on trash, post states.
4. Enable it in content-arena-s03 and test against the theme hooks above.
5. Optional: block editor plugin to replace the publish button on copies.
