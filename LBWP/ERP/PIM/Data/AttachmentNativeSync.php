<?php

namespace LBWP\ERP\PIM\Data;

use LBWP\Theme\Base\Component;

/**
 * Sync Attachments from PIM to multiple shops
 *
 * - runSync() — Main orchestrator: gets recent attachments from source, groups by SKU, then syncs to each destination
 * - connect() — Creates native mysqli connections from config
 * - getRecentAttachments() — Finds attachments with -N suffix in post_name modified in the last 60 minutes
 * - groupAttachmentsBySku() — Parses post_name (e.g. 345611-0) into SKU + index, groups by SKU
 * - findProductBySku() — Looks up WooCommerce product by _sku postmeta in destination DB
 * - syncAttachment() — Copies the attachment post row (insert or update by post_name match), returns destination attachment ID
 * - syncAttachmentMeta() — Deletes existing meta and copies all postmeta rows from source attachment
 * - setPostMeta() — Sets _thumbnail_id (for index 0) or _product_image_gallery (comma-separated IDs for index 1+)
 *
 * @package LBWP\ERP\PIM\Data
 * @author Michael Sebel <michael@comotive.ch>
 */
class AttachmentNativeSync extends Component
{
  /**
   * The source DB and destination DB(s) configuration
   */
  protected array $config = LBWP_PIM_NATIVE_DB_SYNC_SETTINGS;
  /**
   * Initialize the backend component, which is nice
   */
  public function init()
  {
    add_action('cron_hourly', [$this, 'runSync']);
  }

  /**
   * Sync recently modified attachments from PIM source to all destination shops
   */
  public function runSync()
  {
    $source = $this->connect($this->config['source']);
    if (!$source) {
      return;
    }

    $attachments = $this->getRecentAttachments($source, $this->config['source']['prefix']);
    if (empty($attachments)) {
      $source->close();
      return;
    }

    // Group attachments by SKU
    $bySku = $this->groupAttachmentsBySku($attachments);

    foreach ($this->config['destinations'] as $destConfig) {
      $dest = $this->connect($destConfig);
      if (!$dest) {
        continue;
      }

      foreach ($bySku as $sku => $skuAttachments) {
        $productId = $this->findProductBySku($dest, $destConfig['prefix'], $sku);
        if (!$productId) {
          continue;
        }

        $galleryIds = [];
        foreach ($skuAttachments as $att) {
          $destAttId = $this->syncAttachment($source, $this->config['source']['prefix'], $dest, $destConfig['prefix'], $att['ID']);
          if (!$destAttId) {
            continue;
          }

          if ($att['index'] === 0) {
            $this->setPostMeta($dest, $destConfig['prefix'], $productId, '_thumbnail_id', $destAttId);
          } else {
            $galleryIds[] = $destAttId;
          }
        }

        if (!empty($galleryIds)) {
          $this->setPostMeta($dest, $destConfig['prefix'], $productId, '_product_image_gallery', implode(',', $galleryIds));
        }
      }

      $dest->close();
    }

    $source->close();
  }

  /**
   * Create a native mysqli connection from config
   * @param array $cfg database config with host, user, password, dbname
   * @return \mysqli|null
   */
  protected function connect(array $cfg): ?\mysqli
  {
    $conn = new \mysqli($cfg['host'], $cfg['user'], $cfg['password'], $cfg['dbname']);
    return $conn->connect_error ? null : $conn;
  }

  /**
   * Find attachments modified within the last 60 minutes that have a -N suffix in post_name
   * @param \mysqli $db source database connection
   * @param string $prefix table prefix
   * @return array list of attachment rows with added 'sku' and 'index' fields
   */
  protected function getRecentAttachments(\mysqli $db, string $prefix): array
  {
    $table = $db->real_escape_string($prefix) . 'posts';
    $since = date('Y-m-d H:i:s', strtotime('-60 minutes'));
    $sql = "SELECT * FROM `{$table}`
            WHERE post_type = 'attachment'
              AND post_name REGEXP '-[0-9]+$'
              AND post_modified >= '{$db->real_escape_string($since)}'
            ORDER BY post_name ASC";

    $result = $db->query($sql);
    if (!$result) {
      return [];
    }

    $rows = [];
    while ($row = $result->fetch_assoc()) {
      $rows[] = $row;
    }
    $result->free();
    return $rows;
  }

  /**
   * Group attachment rows by SKU, extracting sku and image index from post_name
   * @param array $attachments raw attachment rows from getRecentAttachments
   * @return array keyed by SKU, each value is an array of attachments with 'index' field added
   */
  protected function groupAttachmentsBySku(array $attachments): array
  {
    $bySku = [];
    foreach ($attachments as $att) {
      if (preg_match('/^(.+)-(\d+)$/', $att['post_name'], $m)) {
        $att['sku'] = $m[1];
        $att['index'] = (int)$m[2];
        $bySku[$m[1]][] = $att;
      }
    }

    // Sort each group by index
    foreach ($bySku as &$group) {
      usort($group, fn($a, $b) => $a['index'] - $b['index']);
    }

    return $bySku;
  }

  /**
   * Find a WooCommerce product ID by SKU in a destination database
   * @param \mysqli $db destination database connection
   * @param string $prefix table prefix
   * @param string $sku product SKU
   * @return int|null product post ID or null
   */
  protected function findProductBySku(\mysqli $db, string $prefix, string $sku): ?int
  {
    $metaTable = $db->real_escape_string($prefix) . 'postmeta';
    $stmt = $db->prepare("SELECT post_id FROM `{$metaTable}` WHERE meta_key = '_sku' AND meta_value = ? LIMIT 1");
    $stmt->bind_param('s', $sku);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    return $row ? (int)$row['post_id'] : null;
  }

  /**
   * Sync a single attachment from source to destination (insert or update post + all postmeta)
   * @param \mysqli $src source database connection
   * @param string $srcPrefix source table prefix
   * @param \mysqli $dest destination database connection
   * @param string $destPrefix destination table prefix
   * @param int $srcAttId source attachment post ID
   * @return int|null the destination attachment post ID, or null on failure
   */
  protected function syncAttachment(\mysqli $src, string $srcPrefix, \mysqli $dest, string $destPrefix, int $srcAttId): ?int
  {
    // Fetch source attachment post
    $srcTable = $src->real_escape_string($srcPrefix) . 'posts';
    $result = $src->query("SELECT * FROM `{$srcTable}` WHERE ID = {$srcAttId} LIMIT 1");
    $att = $result->fetch_assoc();
    $result->free();
    if (!$att) {
      return null;
    }

    $destTable = $dest->real_escape_string($destPrefix) . 'posts';
    $postName = $att['post_name'];

    // Check if attachment already exists in destination by post_name + post_type
    $stmt = $dest->prepare("SELECT ID FROM `{$destTable}` WHERE post_name = ? AND post_type = 'attachment' LIMIT 1");
    $stmt->bind_param('s', $postName);
    $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    // Build columns for insert/update (exclude ID)
    $columns = [];
    $values = [];
    foreach ($att as $col => $val) {
      if ($col === 'ID') continue;
      $columns[] = "`{$col}`";
      $values[] = $val === null ? 'NULL' : "'" . $dest->real_escape_string($val) . "'";
    }

    if ($existing) {
      // Update existing attachment
      $destAttId = (int)$existing['ID'];
      $setParts = [];
      for ($i = 0; $i < count($columns); $i++) {
        $setParts[] = "{$columns[$i]} = {$values[$i]}";
      }
      $dest->query("UPDATE `{$destTable}` SET " . implode(', ', $setParts) . " WHERE ID = {$destAttId}");
    } else {
      // Insert new attachment
      $dest->query("INSERT INTO `{$destTable}` (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $values) . ")");
      $destAttId = (int)$dest->insert_id;
      if (!$destAttId) {
        return null;
      }
    }

    // Sync all postmeta
    $this->syncAttachmentMeta($src, $srcPrefix, $dest, $destPrefix, $srcAttId, $destAttId);

    return $destAttId;
  }

  /**
   * Sync all postmeta rows for an attachment from source to destination
   * @param \mysqli $src source database connection
   * @param string $srcPrefix source table prefix
   * @param \mysqli $dest destination database connection
   * @param string $destPrefix destination table prefix
   * @param int $srcAttId source attachment post ID
   * @param int $destAttId destination attachment post ID
   */
  protected function syncAttachmentMeta(\mysqli $src, string $srcPrefix, \mysqli $dest, string $destPrefix, int $srcAttId, int $destAttId): void
  {
    $srcMetaTable = $src->real_escape_string($srcPrefix) . 'postmeta';
    $destMetaTable = $dest->real_escape_string($destPrefix) . 'postmeta';

    // Fetch all source meta
    $result = $src->query("SELECT meta_key, meta_value FROM `{$srcMetaTable}` WHERE post_id = {$srcAttId}");
    if (!$result) {
      return;
    }

    // Remove existing meta for this attachment in destination
    $dest->query("DELETE FROM `{$destMetaTable}` WHERE post_id = {$destAttId}");

    // Insert all meta rows
    while ($row = $result->fetch_assoc()) {
      $stmt = $dest->prepare("INSERT INTO `{$destMetaTable}` (post_id, meta_key, meta_value) VALUES (?, ?, ?)");
      $stmt->bind_param('iss', $destAttId, $row['meta_key'], $row['meta_value']);
      $stmt->execute();
      $stmt->close();
    }
    $result->free();
  }

  /**
   * Set or update a single postmeta value in the destination database
   * @param \mysqli $db destination database connection
   * @param string $prefix table prefix
   * @param int $postId post ID
   * @param string $key meta key
   * @param mixed $value meta value
   */
  protected function setPostMeta(\mysqli $db, string $prefix, int $postId, string $key, $value): void
  {
    $table = $db->real_escape_string($prefix) . 'postmeta';
    $stmt = $db->prepare("SELECT meta_id FROM `{$table}` WHERE post_id = ? AND meta_key = ? LIMIT 1");
    $stmt->bind_param('is', $postId, $key);
    $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($existing) {
      $stmt = $db->prepare("UPDATE `{$table}` SET meta_value = ? WHERE meta_id = ?");
      $metaId = (int)$existing['meta_id'];
      $stmt->bind_param('si', $value, $metaId);
    } else {
      $stmt = $db->prepare("INSERT INTO `{$table}` (post_id, meta_key, meta_value) VALUES (?, ?, ?)");
      $stmt->bind_param('iss', $postId, $key, $value);
    }

    $stmt->execute();
    $stmt->close();
  }
}