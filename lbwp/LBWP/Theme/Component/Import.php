<?php

namespace LBWP\Theme\Component;

use LBWP\Theme\Base\Component;
use LBWP\Util\Strings;
use LBWP\Util\WordPress;

/**
 * This is a sole single language full import base class
 * Handles the import from WPML tables
 * @package LBWP\Theme\Component
 * @author Michael Sebel <michael@comotive.ch>
 */
class Import extends Component
{
  /**
   * DB import tables prefix
   */
  protected $exportPrefix = '_DEFINE_IN_OVERRIDE';
  protected $importPrefix = '_DEFINE_IN_OVERRIDE';
  /**
   * Various configurations
   */
  protected $onlyImportAuthorsWithPosts = true;
  protected $resolveThumbnailsInPostMetaImport = false;
  protected $defaultAuthorId = 0;

  /**
   * The import modes
   */
  const MODES = array(
    'tags',         // NOT_IMPLEMENTED Simple import of tags, setting correct language, but not connect
    'authors',      // DONE Simple import of authors by username slug, defaults: only import authors with at least one post
    'posts',        // DONEImport posts without any meta data, try assignig authors and default category only
    'postmetas',    // DONE Assign post meta, override, if already existing
    'attachments',  // NOT_IMPLEMENTED Import *all* attachments depending on configuration. Flushes *all* attachments before importing, beware!
    'thumbnails',   // TODO Mode to import only thumbnail attachments and assign them to their post (only run once as it doesnt flush!)
    'galleries',    // NOT_IMPLEMENTED Convert gallery shortcodes (must be done after "attachments" -> needs *all* attachments config)
    'comments',     // DONE Flush all and re-import comments to posts by post_name
    'assignments',  // NOT_IMPLEMENTED Attach all category/tag assignments by slug to post_name (can use existing categories/tags by same slug)
    'cleanup'       // DONE Cleanup mode, remove unresolved post metas and empty post meta
  );
  /**
   * @var array list of log entries
   */
  protected $log = array();
  /**
   * @var array of old ID > new ID
   */
  protected $authorMap = array();
  /**
   * @var array of old ID > new ID
   */
  protected $postMap = array();

  /**
   * Initialize the backend component, which is nice
   */
  public function init()
  {
    // Preload some indizes for importing
    $this->beforeLoadingMaps();
    $this->loadAuthorMap();
    $this->loadPostMap();
    $this->afterLoadingMaps();

    // Get the import mode
    $this->mode = Strings::forceSlugString($_GET['lbwpImport']);
    // Check if valid mode
    if (isset($_GET['lbwpImport']) && in_array($this->mode, self::MODES)) {
      $method = 'runMode' . ucfirst($this->mode);
      set_time_limit(900);
      if (method_exists($this, $method)) {
        $this->beforeRunMode();
        $this->$method();
        $this->log('finished import');
        $this->printLog();
        $this->afterPrintLog();
        exit;
      }
    }
  }

  /**
   * Various overrideable hooker functions
   */
  protected function beforeLoadingMaps() {}
  protected function afterLoadingMaps() {}
  protected function beforeRunMode() {}
  protected function afterPrintLog() {}
  protected function changeGuidOnAttachmentImport($guid) { return $guid; }

  /** TODO
   * Simple import of tags, setting correct language, but not connect them
   */
  protected function runModeTags()
  {
    $this->log('starting tag import mode');
    // Get a list of tags to be imported from the original tables
    $importableTags = $this->getImportableTags();
    // Create all terms, testing if they exist already
    foreach ($importableTags as $slug => $name) {
      if (!term_exists($slug, 'post_tag')) {
        // Get the wpml term language by slug from original
        $language = $this->getWpmlElementLanguage(
          $this->getWpmlTermIdBySlug($slug),
          'tax_post_tag'
        );
        // Insert the term to our database
        $result = wp_insert_term($name, 'post_tag', array(
          'slug' => $slug
        ));

        if (is_array($result) && count($result) == 2) {
          $this->log('Created new tag: ' . $name . ' in ' . $language);
          // Set the language with polylang
          pll_set_term_language($result['term_id'], $language);
        } else {
          $this->log('ERROR: Unable to create tag: ' . $name);
          $this->log('ERROR RESULT: ' . Strings::getVarDump($result));
        }
      }
    }
  }

  /** TODO
   * @return array key/value array of slug->name
   */
  protected function getImportableTags()
  {
    $db = WordPress::getDb();
    $tags = array();

    // Get all tags from original DB
    $result = $db->get_results('
      SELECT * FROM asterms INNER JOIN asterm_taxonomy
      ON asterms.term_id = asterm_taxonomy.term_id
      WHERE asterm_taxonomy.taxonomy = "post_tag" AND asterm_taxonomy.count > 0
    ');

    foreach ($result as $record) {
      $tags[$record->slug] = $record->name;
    }

    return $tags;
  }

  /**
   * Simple import of authors by username slug
   */
  protected function runModeAuthors()
  {
    $this->log('starting author import mode');
    $authors = $this->getImportableAuthors();
    // Get all author meta in a mapping table
    $meta = $this->getAuthorMetaList();

    // Import all users, that are not mapped yet
    foreach ($authors as $author) {
      // If not already mapped
      $oldId = intval($author['ID']);
      if (isset($this->authorMap[$oldId]) && $this->authorMap[$oldId] == 0) {
        $this->log('Importing new user and mapping: ' . $author['user_nicename']);
        // Create user (and set role)
        $newId = intval(wp_insert_user(array(
          'user_pass' => Strings::getRandom(30),
          'user_login' => $author['user_login'],
          'user_nicename' => $author['user_nicename'],
          'user_email' => $author['user_email'],
          'user_url' => $author['user_url'],
          'display_name' => $author['display_name'],
          'role' => $author['user_role']
        )));
        // Map the author to our array of old => new id
        $this->authorMap[$oldId] = $newId;
        // Flush and re-assign author meta data
        $this->updateAuthorPostMeta($meta, $oldId);
      } else {
        $this->log('Skipping existing author: ' . $author['user_nicename']);
        // Flush and re-assign author meta data
        $this->updateAuthorPostMeta($meta, $oldId);
      }
    }
  }

  /**
   * Flush and re-assign author meta data
   * @param $meta
   * @param $oldId
   */
  protected function updateAuthorPostMeta(&$meta, $oldId)
  {
    $db =  WordPress::getDb();
    $newId = $this->authorMap[$oldId];
    // Set the mapping of old to new key
    $mapping = array(
      'first_name' => 'first_name',
      'last_name' => 'last_name',
      'twitter' => 'twitter',
      'facebook' => 'facebook',
      'description' => 'description'
    );

    // First, flush all existing meta, matching the imported keys
    $flushKeys = array_values($mapping);
    $db->query('
      DELETE FROM ' . $this->importPrefix . 'usermeta WHERE
      meta_key IN("' . implode('","', $flushKeys) . '")
      AND user_id = ' . $newId . '
    ');

    // Now primitively add the meta data
    $authormeta = $meta[$oldId];
    if (is_array($authormeta)) {
      foreach ($authormeta as $key => $data) {
        $db->insert($this->importPrefix . 'usermeta', array(
          'user_id' => $newId,
          'meta_key' => $mapping[$key],
          'meta_value' => $data[0]
        ));
      }
    }
  }

  /**
   * @return array list of importable meta infos
   */
  protected function getAuthorMetaList()
  {
    $raw = WordPress::getDb()->get_results('
      SELECT user_id, meta_key, meta_value FROM ' . $this->exportPrefix . '
      WHERE meta_key IN(
        "first_name", "last_name", "twitter", "facebook",  "description"
      )
    ', ARRAY_A);

    // Put them into arrays by post id
    $metadata = array();
    foreach ($raw as $record) {
      $id = $record['user_id'];
      if (!isset($metadata[$id])) {
        $metadata[$id] = array();
      }
      // Add the taxonomy and an array of slugs for it
      $metadata[$id][$record['meta_key']][] = $record['meta_value'];
    }
    return $metadata;
  }


  /**
   * Load a map of old to new id of authors matched by user_nicename
   */
  protected function loadAuthorMap()
  {
    $this->log('loading the author map');
    $oldAuthors = $this->getImportableAuthors();
    $currentauthors = get_users(array(
      'number' => 999,
      'fields' => array('ID', 'user_nicename')
    ));

    foreach ($oldAuthors as $oldAuthor) {
      $id = intval($oldAuthor['ID']);
      $this->authorMap[$id] = 0;
      foreach ($currentauthors as $current) {
        if ($oldAuthor['user_nicename'] == $current->user_nicename) {
          $this->authorMap[$id] = intval($current->ID);
          break;
        }
      }
    }
  }

  /**
   * @return array of authors to be imported
   */
  protected function getImportableAuthors()
  {
    $authors = WordPress::getDb()->get_results('
      SELECT ID, user_login, user_nicename, user_email, user_url, display_name FROM ' . $this->exportPrefix . 'users
    ', ARRAY_A);

    // Map the main role to each user
    foreach ($authors as $key => $author) {
      $capabilities = WordPress::getDb()->get_var('
        SELECT meta_value FROM ' . $this->exportPrefix . 'usermeta
        WHERE user_id = ' . $author['ID'] . '
        AND meta_key = "' . $this->exportPrefix . 'capabilities"
      ');
      $capabilities = unserialize($capabilities);
      if (is_array($capabilities) && count($capabilities) > 0) {
        $authors[$key]['user_role'] = array_keys($capabilities)[0];
        $authors[$key]['user_role'] = $this->maybeConvertOldSmkRoles($authors[$key]['user_role']);
      } else {
        // Do not even consider importing him
        unset($authors[$key]);
      }
    }

    // Minimize the author to only authors who have posts
    if ($this->onlyImportAuthorsWithPosts) {
      $authors = array_filter($authors, array($this, 'filterPublishedUsers'));
    }

    return $authors;
  }

  /**
   * Filter out users that don't have posts assigned
   * @param $author
   * @return bool
   */
  public function filterPublishedUsers($author)
  {
    return intval(WordPress::getDb()->get_var('
      SELECT COUNT(ID) FROM ' . $this->exportPrefix . 'posts
      WHERE post_author = ' . $author['ID'] . '
    ')) > 0;
  }

  /**
   * Translate smk user roles to "normal" default roles
   * @param $role
   * @return string
   */
  protected function maybeConvertOldSmkRoles($role)
  {
    switch ($role) {
      case 'smk1editor':
      case 'smk4publisher':
        return 'editor';
      case 'smk2author':
        return 'author';
      case 'smk10guestpublisher':
      case 'smk13newsletterpublisher':
        return 'contributor';
      case 'smk12frontend':
      case 'smk6controller':
      case 'smk7smpublisher':
      case 'smk8reader':
      case 'smk9deleted':
        return 'subscriber';
    }

    return $role;
  }

  /**
   * Import posts (and their attachments), then connected them by language
   */
  protected function runModePosts()
  {
    $this->log('starting post import mode');
    $posts = $this->getImportablePosts();

    // Go trough them to implement one group at a time
    foreach ($posts as $post) {
      // Skip the post, if already imported
      if ($this->postMap[$post['ID']] > 0) {
        $this->log('Skipping already present post: ' . $post['post_name']);
        continue;
      }

      // Unset a few variables from post object to be accepted by WP API
      $this->log('Importing new post: ' . $post['post_name']);
      unset($post['ID']);
      // Override post author by map
      $post['post_author'] = intval($this->authorMap[$post['post_author']]);
      if ($post['post_author'] == 0 && $this->defaultAuthorId > 0) {
        $post['post_author'] = $this->defaultAuthorId;
      }
      // Create the new object
      $newId = wp_insert_post($post);
    }
  }

  /**
   * Load a map of old to new id of posts matched by post_name
   */
  protected function loadPostMap()
  {
    $this->log('loading the post map');
    $oldPosts = $this->getImportablePosts();
    $currentPosts = get_posts(array(
      'posts_per_page' => -1,
      'post_status' => 'any'
    ));

    foreach ($oldPosts as $oldPost) {
      $id = intval($oldPost['ID']);
      $this->postMap[$id] = 0;
      foreach ($currentPosts as $current) {
        if ($oldPost['post_name'] == $current->post_name) {
          $this->postMap[$id] = intval($current->ID);
          break;
        }
      }
    }
  }

  /**
   * Get a list of all importable posts, ungrouped
   */
  protected function getImportablePosts()
  {
    return WordPress::getDb()->get_results('
      SELECT * FROM ' . $this->exportPrefix . 'posts
      WHERE LENGTH(post_name) > 0 AND post_type = "post"
    ', ARRAY_A);
  }

  /**
   * Assign needed post meta, override if already given
   */
  protected function runModePostmetas()
  {
    $this->log('starting post meta import mode');
    $metadata = $this->getPostMetaList();

    // Define a few translations to make this more standardized to WP
    // This is yet empty in defaults, but implemented to be used as old=>new value array
    $translations = array();

    // Loop trough post map, to find actual posts that need assignments
    foreach ($this->postMap as $oldId => $newId) {
      $metas = $metadata[$oldId];
      if (is_array($metas) && count($metas) > 0) {
        // Flush all current meta info
        WordPress::getDb()->query('DELETE FROM ' . $this->importPrefix . 'postmeta WHERE post_id = ' . $newId);
        // Import by key
        foreach ($metas as $key => $value) {
          // Translate the key, if needed
          if (isset($translations[$key])) {
            $key = $translations[$key];
          }
          // Resolve attachment ids
          if ($key == '_thumbnail_id' && $this->resolveThumbnailsInPostMetaImport) {
            $value[0] = $this->resolveAttachment($value[0]);
          }
          // Save meta data to new post directly to DB to prevent "problems" with yoast
          WordPress::getDb()->insert($this->importPrefix . 'postmeta', array(
            'post_id' => $newId,
            'meta_key' => $key,
            'meta_value' => $value[0]
          ));
        }
      }
    }

    $this->log('SUCCESS: Please completely flush the cache now');
  }

  /**
   * Directly import old attachments and fix the _thumbnail_id for all posts
   * WARNING: Can only run once if you don't want a million thumbnail duplicates in your db
   */
  protected function runModeThumbnails()
  {
    $this->log('starting post thumbnails import mode');
    $metadata = $this->getAttachmentMetaTable();

    // Loop trough post map, to find actual posts that need assignments
    foreach ($this->postMap as $newId) {
      // Get the possibly old thumbnail id
      $oldAttachmentId = get_post_meta($newId, '_thumbnail_id', true);

      // See if there is something we can import
      $oldAttachment = $this->getImportableAttachmentInfo($oldAttachmentId);
      $oldMeta = $metadata[$oldAttachmentId];

      // Import the attachment and re-assign with the newly generated info
      if (is_array($oldAttachment) && is_array($oldMeta) && count($oldMeta) > 0) {
        // Only unset the id, then use direct database import
        unset($oldAttachment['ID']);

        // See if we need to change all data because of strange chars
        $guidAfter = Strings::replaceCommonFileChars($oldAttachment['guid']);
        $guidAfter = $this->changeGuidOnAttachmentImport($guidAfter);

        // If the file changed, also change meta data and do a log output
        if ($guidAfter != $oldAttachment['guid']) {
          $oldAttachment['guid'] = $guidAfter;
          $oldMeta['_wp_attached_file'] = Strings::replaceCommonFileChars($oldMeta['_wp_attached_file']);
          $meta = unserialize($oldMeta['_wp_attachment_metadata']);
          if (is_array($meta)) {
            $meta['file'] = Strings::replaceCommonFileChars($meta['file']);
            $this->log('changed filename to ' . $meta['file']);
            foreach ($meta['sizes'] as $key => $size) {
              $meta['sizes'][$key]['file'] = Strings::replaceCommonFileChars($size['file']);
            }
            // Deserialize it back to native string
            $oldMeta['_wp_attachment_metadata'] = serialize($meta);
          }
        }

        // Fix the parent and the author
        $oldAttachment['post_author'] = $this->authorMap[$oldAttachment['post_author']];
        if (isset($this->postMap[$oldAttachment['post_parent']])) {
          $oldAttachment['post_parent'] = $this->postMap[$oldAttachment['post_parent']];
        } else {
          // No parent, as we can't find it anymore (maybe a custom type or page, that is not imported)
          $oldAttachment['post_parent'] = 0;
        }

        // Insert the attachment
        WordPress::getDb()->insert($this->importPrefix. 'posts', $oldAttachment);
        $newAttachmentId = intval(WordPress::getDb()->insert_id);
        // Now add metadata by accessing old ID
        foreach ($oldMeta as $key => $value) {
          WordPress::getDb()->insert($this->importPrefix . 'postmeta', array(
            'post_id' => $newAttachmentId,
            'meta_key' => $key,
            'meta_value' => $value
          ));
        }
        // Replace the thumbnail id directly in DB with the new imported one
        update_post_meta($newId, '_thumbnail_id', $newAttachmentId);
      } else {
        // Remove post meta for thumbnail to that post
        delete_post_meta($newId, '_thumbnail_id');
      }
    }

    $this->log('SUCCESS: Please completely flush the cache now');
  }

  /**
   * @param int $oldId
   * @return \wpdb
   */
  protected function getImportableAttachmentInfo($oldId)
  {
    return WordPress::getDb()->get_row('
      SELECT * FROM ' . $this->exportPrefix . 'posts
      WHERE ID = ' . $oldId . ' AND post_type = "attachment"
    ', ARRAY_A);
  }

  /** TODO
   * @param int $oldId the old attachment id
   * @return int the new attachment id
   */
  protected function resolveAttachment($oldId)
  {
    // Get post_name of old attachment
    $oldName = WordPress::getDb()->get_var('SELECT post_name FROM asposts WHERE ID = ' . $oldId);
    // Get new Id by post name
    $newId = intval(WordPress::getDb()->get_var('SELECT ID FROM ' . self::PREFIX . 'posts WHERE post_name = "' . $oldName . '"'));
    // Log if not attached
    if ($newId == 0) {
      $this->log('ERROR: Cannot find attachment for old ID ' . $oldId);
    }

    return $newId;
  }

  /**
   * @return array list of importable meta infos
   */
  protected function getPostMetaList()
  {
    $raw = WordPress::getDb()->get_results('
      SELECT post_id, meta_key, meta_value FROM ' . $this->exportPrefix . 'postmeta
      WHERE meta_key IN(
        "_yoast_wpseo_opengraph-title", "_yoast_wpseo_opengraph-description", "_yoast_wpseo_opengraph-image",
        "_yoast_wpseo_twitter-title", "_yoast_wpseo_twitter-description", "_yoast_wpseo_twitter-image",
        "_yoast_wpseo_google-plus-title", "_yoast_wpseo_google-plus-description", "_yoast_wpseo_google-plus-image",
        "_yoast_wpseo_focuskw", "_yoast_wpseo_title", "_yoast_wpseo_metadesc","_thumbnail_id"
      )
    ', ARRAY_A);

    // Put them into arrays by post id
    $metadata = array();
    foreach ($raw as $record) {
      $id = $record['post_id'];
      if (!isset($metadata[$id])) {
        $metadata[$id] = $this->getInitialPostMetas();
      }
      // Add the taxonomy and an array of slugs for it
      $metadata[$id][$record['meta_key']][] = $record['meta_value'];
    }
    return $metadata;
  }

  /**
   * @return array
   */
  protected function getInitialPostMetas()
  {
    return array();
  }

  /** TODO big time
   * Flush all and re-import attachments with the postMap
   */
  protected function runModeAttachments()
  {
    $this->log('starting attachment import mode');
    WordPress::getDb()->query('DELETE FROM ' . self::PREFIX . 'posts WHERE post_type = "attachment"');
    $attachments = $this->getImportableAttachments();
    $attachmentMap = array();
    $metadata = $this->getAttachmentMetaTable();

    // Tell the user how many attachments are imported
    $this->log('flushed, now importing ' . count($attachments) . ' attachments');

    // Add each attachment and its meta data directly to DB
    foreach ($attachments as $attachment) {
      // Only unset the id, then use direct database import
      $oldId = $attachment['ID'];
      $lang = (strlen($attachment['lang']) > 0) ? $attachment['lang'] : 'de';
      unset($attachment['ID'], $attachment['lang'], $attachment['trid']);

      // See if we need to change all data because of strange chars
      $guidAfter = Strings::replaceCommonFileChars($attachment['guid']);
      // If the file changed, also change meta data and do a log output
      if ($guidAfter != $attachment['guid']) {
        $attachment['guid'] = $guidAfter;
        $metadata[$oldId]['_wp_attached_file'] = Strings::replaceCommonFileChars($metadata[$oldId]['_wp_attached_file']);
        $meta = unserialize($metadata[$oldId]['_wp_attachment_metadata']);
        if (is_array($meta)) {
          $meta['file'] = Strings::replaceCommonFileChars($meta['file']);
          $this->log('changed filename to ' . $meta['file']);
          foreach ($meta['sizes'] as $key => $size) {
            $meta['sizes'][$key]['file'] = Strings::replaceCommonFileChars($size['file']);
          }
          // Deserialize it back to native string
          $metadata[$oldId]['_wp_attachment_metadata'] = serialize($meta);
        }
      }

      // Fix the parent and the author
      $attachment['post_author'] = $this->authorMap[$attachment['post_author']];
      if (isset($this->postMap[$attachment['post_parent']])) {
        $attachment['post_parent'] = $this->postMap[$attachment['post_parent']];
      } else {
        // No parent, as we can't find it anymore (maybe a custom type or page, that is not imported)
        $attachment['post_parent'] = 0;
      }

      // Insert the attachment
      WordPress::getDb()->insert(self::PREFIX . 'posts', $attachment);
      $newId = intval(WordPress::getDb()->insert_id);
      $attachmentMap[$oldId] = $newId;
      // Set the language of our attachment
      pll_set_post_language($newId, $lang);
      // Now add metadata by accessing old ID
      if (isset($metadata[$oldId])) {
        foreach ($metadata[$oldId] as $key => $value) {
          WordPress::getDb()->insert(self::PREFIX . 'postmeta', array(
            'post_id' => $newId,
            'meta_key' => $key,
            'meta_value' => $value
          ));
        }
      }
    }

    // Save attachment map for gallery shortcode conversion
    $_SESSION['lastAttachmentMap'] = $attachmentMap;

    $this->log('SUCCESS: Please completely flush the cache ONLY AFTER recovering galleries.');
  }

  /** TODO
   * Convert gallery shortcodes by replacing all their old ids with new ones
   */
  public function runModeGalleries()
  {
    if (isset($_SESSION['lastAttachmentMap']) && count($_SESSION['lastAttachmentMap']) > 0) {
      $this->convertGalleryShortcodes($_SESSION['lastAttachmentMap']);
    } else {
      $this->log('FAIL: There is no attachment map to use.');
      if (isset($_SESSION['lastAttachmentMap']) && count($_SESSION['lastAttachmentMap']) == 0) {
        $this->log('FAIL: Attachment map was there, but empty.');
      }
    }
  }

  /** TODO
   * Takes all gallery shortcodes and maps old to new ids
   * @param array $map an array of old => new ID of attachments
   */
  protected function convertGalleryShortcodes($map)
  {
    // Get all contents with at least a gallery in them
    $posts = WordPress::getDb()->get_results('
      SELECT * FROM ' . self::PREFIX . 'posts WHERE post_content LIKE "%[gallery%"
    ', ARRAY_A);

    // Loop trough the content elements
    foreach ($posts as $post) {
      $pattern = get_shortcode_regex(array('gallery'));

      // Fix the post content
      $post['post_content'] = preg_replace_callback('/'. $pattern .'/s', function($match) use($map) {
        $shortcode = $match[0];
        $attributes = $original = shortcode_parse_atts($match[3]);
        // Make size and link attribute fixed, if not given
        $attributes['size'] = 'large';
        $attributes['link'] = 'none';

        // Try fixing the ids in ids parameter, if given
        if (isset($attributes['ids'])) {
          $oldIds = $attributes['ids'];
          $newIds = array();
          foreach (explode(',', $oldIds) as $oldId) {
            if (isset($map[$oldId])) {
              $newIds[] = $map[$oldId];
            }
          }
          // Replace old id string with a new one
          $shortcode = str_replace($oldIds, implode(',', $newIds), $shortcode);
        }

        // Replace all altered values in original shortcode
        foreach ($attributes as $key => $value) {
          $search = $key . '="' . $original[$key] . '"';
          $replace = $key . '="' . $value . '"';
          $shortcode = str_replace($search, $replace, $shortcode);
        }

        // Add new fields, if not yet given in shortcode
        if (stristr($shortcode, 'size=') === false) {
          $shortcode = str_replace('[gallery', '[gallery size="large"', $shortcode);
        }
        if (stristr($shortcode, 'link=') === false) {
          $shortcode = str_replace('[gallery', '[gallery link="none"', $shortcode);
        }

        $this->log('Replace old shortcode: ' . $match[0]);
        $this->log('With new one: ' . $shortcode);
        return $shortcode;
      }, $post['post_content']);

      // Update the post content
      WordPress::getDb()->update(
        self::PREFIX . 'posts',
        array('post_content' => $post['post_content']),
        array('ID' => $post['ID'])
      );
    }
  }

  /**
   * @return array list of meta records
   */
  protected function getAttachmentMetaTable()
  {
    $meta = array();
    $raw = WordPress::getDb()->get_results('
      SELECT post_id, meta_key, meta_value FROM ' . $this->exportPrefix . 'postmeta
      WHERE meta_key IN("_wp_attachment_metadata", "_wp_attached_file")
      AND LENGTH(meta_value) > 0
    ', ARRAY_A);

    // Map into meaning full directly accessible arrays
    foreach ($raw as $record) {
      if (!isset($meta[$record['post_id']])) {
        $meta[$record['post_id']] = array();
      }
      $meta[$record['post_id']][$record['meta_key']] = $record['meta_value'];
    }

    return $meta;
  }

  /** TODO
   * @return array list of importable attachments
   */
  protected function getImportableAttachments()
  {
    $attachments = WordPress::getDb()->get_results('
      SELECT asposts.*,asicl_translations.language_code AS lang, asicl_translations.trid
      FROM asposts LEFT JOIN asicl_translations ON (
        asicl_translations.element_id = asposts.ID AND
        asicl_translations.element_type = "post_attachment"
      ) WHERE post_type = "attachment"
    ', ARRAY_A);

    return $attachments;
  }

  /**
   * Flush all and re-import comments to posts with the postMap
   */
  protected function runModeComments()
  {
    $this->log('starting comment import mode');
    WordPress::getDb()->query('DELETE FROM ' . $this->importPrefix. 'comments');
    WordPress::getDb()->query('UPDATE ' . $this->importPrefix . 'posts SET comment_count = 0');
    $comments = $this->getImportableComments();
    $commentMap = array();

    // Tell the user how many comments are imported
    $this->log('flushed, now importing ' . count($comments) . ' comments');

    // Go trough each comment and re-import them
    foreach ($comments as $comment) {
      // Unset the ID, as we create a new one
      $oldId = intval($comment['comment_ID']);
      unset($comment['comment_ID']);
      // Map the author if possible
      if ($comment['user_id'] > 0) {
        $comment['user_id'] = $this->authorMap[$comment['user_id']];
      }
      // Map the parent, if already in map
      if ($comment['comment_parent'] > 0) {
        $comment['comment_parent'] = $commentMap[$comment['comment_parent']];
      }
      // Map the assigned post by id
      $comment['comment_post_ID'] = $this->postMap[$comment['comment_post_ID']];

      // Insert into db and save in local map
      WordPress::getDb()->insert($this->importPrefix . 'comments', $comment);
      $commentMap[$oldId] = intval(WordPress::getDb()->insert_id);
      // Increase comment count on that post
      $this->increaseCommentCount($comment['comment_post_ID']);
    }
  }

  /**
   * @param int $postId the post whose comment count should be increased
   */
  protected function increaseCommentCount($postId)
  {
    WordPress::getDb()->query('
      UPDATE ' . $this->importPrefix. 'posts
      SET comment_count = (comment_count+1)
      WHERE ID = ' . intval($postId) . '
    ');
  }

  /**
   * @return array list of approved comments
   */
  protected function getImportableComments()
  {
    return WordPress::getDb()->get_results('
      SELECT * FROM ' . $this->exportPrefix . 'comments WHERE comment_approved = 1
      ORDER BY comment_ID ASC
    ', ARRAY_A);
  }

  /** TODO
   * Flush all and re-import all category/tag assignments by slug the postMap
   */
  protected function runModeAssignments()
  {
    $this->log('starting tag/category assignment import mode');
    $assignments = $this->getTermAssignmentList();

    // Loop trough post map, to find actual posts that need assignments
    foreach ($this->postMap as $oldId => $newId) {
      $terms = $assignments[$oldId];
      if (is_array($terms) && (isset($terms['category']) || isset($terms['post_tag']))) {
        foreach ($terms as $taxonomy => $slugs) {
          wp_set_object_terms($newId, $slugs, $taxonomy, false);
        }
      }
    }
  }

  /** TODO
   * @return array list of term assignments by post
   */
  protected function getTermAssignmentList()
  {
    $raw = WordPress::getDb()->get_results('
      SELECT object_id, taxonomy, slug FROM asterm_relationships
      INNER JOIN asterm_taxonomy ON asterm_taxonomy.term_taxonomy_id = asterm_relationships.term_taxonomy_id
      INNER JOIN asterms ON asterm_taxonomy.term_id = asterms.term_id
      WHERE asterm_taxonomy.taxonomy IN("post_tag", "category")
    ', ARRAY_A);

    // Put them into arrays by post id
    $assignments = array();
    foreach ($raw as $record) {
      $id = $record['object_id'];
      if (!isset($assignments[$id])) {
        $assignments[$id] = array();
      }
      // Add the taxonomy and an array of slugs for it
      $assignments[$id][$record['taxonomy']][] = $record['slug'];
    }

    return $assignments;
  }

  /**
   * Removes unresolved post meta and empty post meta
   */
  protected function runModeCleanup()
  {
    $this->log('Starting clean up of unused data');
    $db = WordPress::getDb();
    // Find all empty post meta data sets
    $emptyMetas = $db->get_results('
      SELECT meta_id,meta_key FROM ' . $this->importPrefix . 'postmeta
      WHERE LENGTH(meta_value) = 0
    ', ARRAY_A);

    $whitelist = array('_yoast_wpseo_primary_category');
    foreach ($emptyMetas as $meta) {
      if (in_array($meta['meta_key'], $whitelist)) {
        $this->log('Deleting meta "' . $meta['meta_key'] . '"" with id: ' . $meta['meta_id']);
        $db->query('DELETE FROM ' . $this->importPrefix . 'postmeta WHERE meta_id = ' . $meta['meta_id']);
      }
    }

    // Find unresolved meta
    $unresolvedMeta = $db->get_results('
      SELECT ' . $this->importPrefix . 'postmeta.meta_id, ' . $this->importPrefix . 'posts.ID FROM ' . $this->importPrefix . 'postmeta
      LEFT JOIN ' . $this->importPrefix . 'posts ON ' . $this->importPrefix . 'postmeta.post_id = ' . $this->importPrefix . 'posts.ID
      WHERE ' . $this->importPrefix . 'posts.ID IS NULL
    ', ARRAY_A);

    foreach ($unresolvedMeta as $meta) {
      $this->log('Deleting unresolved meta with id: ' . $meta['meta_id']);
      $db->query('DELETE FROM ' . $this->importPrefix . 'postmeta WHERE meta_id = ' . $meta['meta_id']);
    }
  }

  /**
   * @param string $message
   */
  protected function log($message)
  {
    $this->log[] = date('H:i:s', current_time('timestamp')) . ': ' . $message;
  }

  /**
   * Prints the log
   */
  protected function printLog()
  {
    echo '<pre>';
    echo implode(PHP_EOL, $this->log);
    echo '</pre>';
  }
}
