<?php

namespace LBWP\Util;

/**
 * This class allows mass import and update of wp_post and wp_postmeta with direct efficient database access.
 * Uses native mysqli with prepared statements and transactions for maximum speed.
 * Be aware you need to flush caches manually after import.
 *
 * - Constructor — opens mysqli connection with utf8mb4, derives table names from prefix
 * - Transaction management — beginTransaction(), commitTransaction(), rollbackTransaction()
 * - setAutoCommitThreshold() — auto-commits every N update() calls to prevent InnoDB undo log bloat
 * - update(array $post, array $meta) — main entry point:
 * - Without ID: inserts post + batch inserts all meta in a single multi-row INSERT
 * - With ID: updates post + syncs meta (via get all/php compare)
 * - filterPostColumns() — whitelist validation against POSTS_COLUMNS constant
 * - Destructor — commits pending transaction and closes connection
 *
 * @package LBWP\Util
 * @author Michael Sebel <michael@comotive.ch>
 */
class NativeWpImport
{
  /**
   * Whitelist of valid wp_posts columns
   */
  const POSTS_COLUMNS = array(
    'post_author', 'post_date', 'post_date_gmt', 'post_content', 'post_title',
    'post_excerpt', 'post_status', 'comment_status', 'ping_status', 'post_password',
    'post_name', 'to_ping', 'pinged', 'post_modified', 'post_modified_gmt',
    'post_content_filtered', 'post_parent', 'guid', 'menu_order', 'post_type',
    'post_mime_type', 'comment_count'
  );

  /** @var \mysqli */
  protected $db;
  /** @var string */
  protected $prefix;
  /** @var string */
  protected $postsTable;
  /** @var string */
  protected $metaTable;
  /** @var int */
  protected $opCount = 0;
  /** @var int */
  protected $autoCommitThreshold = 0;
  /** @var bool */
  protected $inTransaction = false;

  /**
   * @param string $host database host
   * @param string $user database user
   * @param string $password database password
   * @param string $database database name
   * @param string $prefix table prefix
   */
  public function __construct($host, $user, $password, $database, $prefix = 'wp_')
  {
    $this->db = new \mysqli($host, $user, $password, $database);
    if ($this->db->connect_errno) {
      throw new \RuntimeException('mysqli connect failed: ' . $this->db->connect_error);
    }
    $this->db->set_charset('utf8mb4');
    $this->prefix = $prefix;
    $this->postsTable = $prefix . 'posts';
    $this->metaTable = $prefix . 'postmeta';
  }

  /**
   * @return \mysqli the raw connection
   */
  public function getDb()
  {
    return $this->db;
  }

  /**
   * @return string the table prefix
   */
  public function getPrefix()
  {
    return $this->prefix;
  }

  /**
   * Begin a transaction
   */
  public function beginTransaction()
  {
    if (!$this->inTransaction) {
      $this->db->begin_transaction();
      $this->inTransaction = true;
    }
  }

  /**
   * Commit the current transaction
   */
  public function commitTransaction()
  {
    if ($this->inTransaction) {
      $this->db->commit();
      $this->inTransaction = false;
    }
  }

  /**
   * Rollback the current transaction
   */
  public function rollbackTransaction()
  {
    if ($this->inTransaction) {
      $this->db->rollback();
      $this->inTransaction = false;
    }
  }

  /**
   * Set auto-commit threshold to avoid InnoDB undo log bloat on large imports.
   * Set to 0 to disable auto-commit (default).
   * @param int $threshold commit every N update() calls
   */
  public function setAutoCommitThreshold($threshold)
  {
    $this->autoCommitThreshold = (int)$threshold;
  }

  /**
   * Insert or update a post and its meta data.
   * If $post contains 'ID', the post is updated. Otherwise a new post is inserted.
   * @param array $post post data (wp_posts columns)
   * @param array $meta key => value pairs for postmeta
   * @return int the post ID
   */
  public function update(array $post, array $meta = array())
  {
    if (isset($post['ID'])) {
      $postId = (int)$post['ID'];
      unset($post['ID']);
      if (!empty($post)) {
        $this->updatePost($postId, $post);
      }
      if (!empty($meta)) {
        $this->syncMeta($postId, $meta);
      }
    } else {
      $postId = $this->insertPost($post);
      if (!empty($meta)) {
        $this->batchInsertMeta($postId, $meta);
      }
    }

    $this->tickOperation();
    return $postId;
  }

  /**
   * Insert a new post row
   * @param array $data post columns
   * @return int inserted post ID
   */
  protected function insertPost(array $data)
  {
    $data = $this->filterPostColumns($data);
    if (empty($data)) {
      throw new \InvalidArgumentException('No valid post columns provided');
    }

    $columns = implode(', ', array_keys($data));
    $placeholders = implode(', ', array_fill(0, count($data), '?'));
    $sql = "INSERT INTO `{$this->postsTable}` ({$columns}) VALUES ({$placeholders})";

    $stmt = $this->db->prepare($sql);
    if (!$stmt) {
      throw new \RuntimeException('Prepare failed: ' . $this->db->error);
    }

    $types = '';
    $values = array();
    foreach ($data as $value) {
      $types .= is_int($value) ? 'i' : 's';
      $values[] = $value;
    }

    $stmt->bind_param($types, ...$values);
    $stmt->execute();

    if ($stmt->errno) {
      $error = $stmt->error;
      $stmt->close();
      throw new \RuntimeException('Insert post failed: ' . $error);
    }

    $id = $stmt->insert_id;
    $stmt->close();
    return $id;
  }

  /**
   * Update an existing post row
   * @param int $postId the post ID
   * @param array $data columns to update
   */
  protected function updatePost($postId, array $data)
  {
    $data = $this->filterPostColumns($data);
    if (empty($data)) {
      return;
    }

    $setParts = array();
    foreach (array_keys($data) as $col) {
      $setParts[] = "{$col} = ?";
    }
    $setClause = implode(', ', $setParts);
    $sql = "UPDATE `{$this->postsTable}` SET {$setClause} WHERE ID = ?";

    $stmt = $this->db->prepare($sql);
    if (!$stmt) {
      throw new \RuntimeException('Prepare failed: ' . $this->db->error);
    }

    $types = '';
    $values = array();
    foreach ($data as $value) {
      $types .= is_int($value) ? 'i' : 's';
      $values[] = $value;
    }
    $types .= 'i';
    $values[] = $postId;

    $stmt->bind_param($types, ...$values);
    $stmt->execute();

    if ($stmt->errno) {
      $error = $stmt->error;
      $stmt->close();
      throw new \RuntimeException('Update post failed: ' . $error);
    }

    $stmt->close();
  }

  /**
   * Batch insert meta rows for a new post (no existence checks needed)
   * @param int $postId the post ID
   * @param array $meta key => value pairs
   */
  protected function batchInsertMeta($postId, array $meta)
  {
    if (empty($meta)) {
      return;
    }

    $placeholders = array();
    $types = '';
    $values = array();
    foreach ($meta as $key => $value) {
      $placeholders[] = '(?, ?, ?)';
      $types .= 'iss';
      $values[] = $postId;
      $values[] = (string)$key;
      $values[] = (string)$value;
    }

    $sql = "INSERT INTO `{$this->metaTable}` (post_id, meta_key, meta_value) VALUES "
      . implode(', ', $placeholders);

    $stmt = $this->db->prepare($sql);
    if (!$stmt) {
      throw new \RuntimeException('Prepare failed: ' . $this->db->error);
    }

    $stmt->bind_param($types, ...$values);
    $stmt->execute();

    if ($stmt->errno) {
      $error = $stmt->error;
      $stmt->close();
      throw new \RuntimeException('Batch insert meta failed: ' . $error);
    }

    $stmt->close();
  }

  /**
   * Sync meta for an existing post: fetch all existing, diff in PHP,
   * batch insert new keys, update changed values, skip unchanged.
   * @param int $postId the post ID
   * @param array $meta key => value pairs
   */
  protected function syncMeta($postId, array $meta)
  {
    // Fetch all existing meta for this post in one query
    $stmt = $this->db->prepare(
      "SELECT meta_id, meta_key, meta_value FROM `{$this->metaTable}` WHERE post_id = ?"
    );
    if (!$stmt) {
      throw new \RuntimeException('Prepare failed: ' . $this->db->error);
    }

    $stmt->bind_param('i', $postId);
    $stmt->execute();
    $result = $stmt->get_result();

    $existing = array();
    while ($row = $result->fetch_assoc()) {
      $existing[$row['meta_key']] = array(
        'meta_id' => (int)$row['meta_id'],
        'meta_value' => $row['meta_value']
      );
    }
    $stmt->close();

    // Classify into insert and update buckets
    $toInsert = array();
    $toUpdate = array();
    foreach ($meta as $key => $value) {
      $key = (string)$key;
      $value = (string)$value;
      if (!isset($existing[$key])) {
        $toInsert[$key] = $value;
      } elseif ($existing[$key]['meta_value'] !== $value) {
        $toUpdate[] = array(
          'meta_id' => $existing[$key]['meta_id'],
          'meta_value' => $value
        );
      }
      // unchanged keys are skipped
    }

    // Batch insert new keys
    if (!empty($toInsert)) {
      $this->batchInsertMeta($postId, $toInsert);
    }

    // Update changed values with a reused prepared statement
    if (!empty($toUpdate)) {
      $updateStmt = $this->db->prepare(
        "UPDATE `{$this->metaTable}` SET meta_value = ? WHERE meta_id = ?"
      );
      if (!$updateStmt) {
        throw new \RuntimeException('Prepare failed: ' . $this->db->error);
      }

      foreach ($toUpdate as $item) {
        $updateStmt->bind_param('si', $item['meta_value'], $item['meta_id']);
        $updateStmt->execute();
        if ($updateStmt->errno) {
          $error = $updateStmt->error;
          $updateStmt->close();
          throw new \RuntimeException('Update meta failed: ' . $error);
        }
      }
      $updateStmt->close();
    }
  }

  /**
   * Filter post data to only contain valid wp_posts columns
   * @param array $data raw post data
   * @return array filtered data with only whitelisted columns
   */
  protected function filterPostColumns(array $data)
  {
    $allowed = array_flip(self::POSTS_COLUMNS);
    return array_intersect_key($data, $allowed);
  }

  /**
   * Tick the operation counter and auto-commit if threshold is reached
   */
  protected function tickOperation()
  {
    if ($this->autoCommitThreshold <= 0) {
      return;
    }

    $this->opCount++;
    if ($this->opCount >= $this->autoCommitThreshold) {
      $this->commitTransaction();
      $this->beginTransaction();
      $this->opCount = 0;
    }
  }

  /**
   * Commits pending transaction and closes the connection
   */
  public function __destruct()
  {
    $this->commitTransaction();
    if ($this->db) {
      $this->db->close();
    }
  }
}
