<?php

namespace LBWP\ERP\PIM\Data;

use LBWP\Core as LbwpCore;
use LBWP\Helper\Cronjob;
use LBWP\Theme\Component\ACFBase;
use LBWP\Util\File;
use LBWP\Util\WordPress;

/**
 * PIM Image Import by SKU
 * @package LBWP\ERP\Data
 * @author Michael Sebel <michael@comotive.ch>
 */
class ImageImport extends ACFBase
{
  const PIM_TYPE_SLUG = 'lbwp-pid';
  const OPTION_KEY = 'current_pimport_images';
  const CRON_IDENTIFIER = 'pimport_images_run';
  const ALLOWED_EXTENSIONS = array('jpg', 'jpeg', 'png', 'gif', 'webp');
  const BATCH_SIZE = 5;

  /**
   * Initialize the backend component
   */
  public function init()
  {
    add_action('admin_menu', array($this, 'registerMenu'));
    add_action('cron_job_' . self::CRON_IDENTIFIER, array($this, 'runImport'));
  }

  /**
   * No blocks needed here
   */
  public function blocks()
  {
  }

  /**
   * Adds field settings
   */
  public function fields()
  {
  }

  /**
   * Register the PIMport admin menu and submenu
   */
  public function registerMenu()
  {
    add_submenu_page(
      'pimport',
      'Bilder',
      'Bilder',
      'edit_pages',
      'pimport-image',
      array($this, 'renderImportPage')
    );
  }

  /**
   * Render the import admin page
   */
  public function renderImportPage()
  {
    $message = '';

    if (isset($_POST['pimport_image_upload']) && isset($_FILES['pimport_images'])) {
      $message = $this->handleUpload();
    } elseif (isset($_POST['pimport_image_reset'])) {
      $message = $this->handleReset();
    }

    $current = get_option(self::OPTION_KEY, array());

    echo '<div class="wrap">';
    echo '<h1>PIMport – Bilder</h1>';

    if (!empty($message)) {
      echo $message;
    }

    // Show current import status if running or completed
    if (!empty($current) && !empty($current['status'])) {
      $this->renderStatus($current);
    }

    // Only show upload form when no import is active
    if (empty($current) || !in_array($current['status'], array('pending', 'running'))) {
      $this->renderUploadForm();
    }

    echo '</div>';
  }

  /**
   * Render the import status table with auto-refresh while running
   * @param array $current import state from option
   */
  protected function renderStatus($current)
  {
    $isRunning = in_array($current['status'], array('pending', 'running'));

    $statusLabels = array(
      'pending' => 'Wartend',
      'running' => 'Läuft',
      'completed' => 'Abgeschlossen',
      'error' => 'Fehler'
    );
    $statusLabel = isset($statusLabels[$current['status']]) ? $statusLabels[$current['status']] : $current['status'];

    echo '<h2>Aktueller Import</h2>';
    echo '<table class="widefat fixed" style="max-width: 600px;">';
    echo '<tr><th>Hochgeladen</th><td>' . esc_html($current['uploaded']) . '</td></tr>';
    echo '<tr><th>Status</th><td>' . esc_html($statusLabel) . '</td></tr>';
    echo '<tr><th>SKUs</th><td>' . intval($current['skus_imported']) . ' / ' . intval($current['skus_total']) . '</td></tr>';
    echo '<tr><th>Bilder</th><td>' . intval($current['images_imported']) . '</td></tr>';
    echo '<tr><th>Zuletzt aktualisiert</th><td>' . esc_html($current['last_updated']) . '</td></tr>';

    if (!empty($current['log'])) {
      echo '<tr><th>Log</th><td><ul><li>' . implode('</li><li>', array_map('esc_html', array_slice($current['log'], -20))) . '</li></ul></td></tr>';
    }

    echo '</table>';

    if ($isRunning) {
      echo '<p><em>Seite aktualisiert sich automatisch alle 15 Sekunden</em></p>';
      echo '<script>setTimeout(function(){ location.reload(); }, 15000);</script>';
    }

    // Reset button
    echo '<form method="post" style="margin-top: 10px;">';
    echo '<input type="submit" name="pimport_image_reset" class="button-secondary" value="Import zurücksetzen" onclick="return confirm(\'Sind Sie sicher?\');" />';
    echo '</form>';
    echo '<br>';
  }

  /**
   * Render the upload form
   */
  protected function renderUploadForm()
  {
    echo '<h2>Bilder hochladen</h2>';
    echo '<p>Einzelne Bilder, mehrere Bilder oder ein ZIP-Archiv mit Bildern hochladen.<br>';
    echo 'Dateinamen müssen die SKU enthalten, z.B. <code>345611.jpg</code>, <code>345611-1.jpg</code>, <code>345611_1.jpg</code>.</p>';
    echo '<form method="post" enctype="multipart/form-data">';
    echo '<table class="form-table"><tr>';
    echo '<th><label for="pimport_images">Bilder / ZIP</label></th>';
    echo '<td><input type="file" name="pimport_images[]" id="pimport_images" multiple accept=".jpg,.jpeg,.png,.gif,.webp,.zip" /></td>';
    echo '</tr></table>';
    echo '<p><input type="submit" name="pimport_image_upload" class="button-primary" value="Bilder importieren" /></p>';
    echo '</form>';
  }

  /**
   * Handle the uploaded files: collect images, validate SKUs, zip, upload to S3, register cron
   * @return string HTML message
   */
  protected function handleUpload()
  {
    ini_set('memory_limit', '2G');
    set_time_limit(300);

    $files = $_FILES['pimport_images'];
    if (empty($files['name'][0])) {
      return '<div class="notice notice-error"><p>Keine Dateien ausgewählt.</p></div>';
    }

    // Collect all image files into a temp directory
    $tempDir = File::getNewUploadFolder();
    $imageFiles = array();

    for ($i = 0; $i < count($files['name']); $i++) {
      if ($files['error'][$i] !== UPLOAD_ERR_OK) {
        continue;
      }

      $ext = strtolower(pathinfo($files['name'][$i], PATHINFO_EXTENSION));

      if ($ext === 'zip') {
        $extracted = $this->extractZip($files['tmp_name'][$i], $tempDir);
        $imageFiles = array_merge($imageFiles, $extracted);
      } elseif (in_array($ext, self::ALLOWED_EXTENSIONS)) {
        $dest = $tempDir . $files['name'][$i];
        move_uploaded_file($files['tmp_name'][$i], $dest);
        $imageFiles[] = $dest;
      }
    }

    if (empty($imageFiles)) {
      $this->cleanupTempDir($tempDir);
      return '<div class="notice notice-error"><p>Keine gültigen Bilddateien gefunden.</p></div>';
    }

    // Build SKU => post_id map and group files
    $skuMap = $this->buildSkuMap();
    $grouped = $this->groupFilesBySku($imageFiles, $skuMap);

    if (empty($grouped)) {
      $this->cleanupTempDir($tempDir);
      return '<div class="notice notice-error"><p>Keine Bilder konnten einer SKU zugeordnet werden.</p></div>';
    }

    // Normalize file names on disk and collect flat file list for zipping
    $normalizedFiles = array();
    foreach ($grouped as $sku => &$data) {
      $data['files'] = $this->normalizeFileNames($data['files'], $sku);
      $normalizedFiles = array_merge($normalizedFiles, $data['files']);
    }
    unset($data);

    // Create a zip of all normalized images for S3 transfer
    $zipPath = $tempDir . 'pimport_images.zip';
    $zip = new \ZipArchive();
    if ($zip->open($zipPath, \ZipArchive::CREATE) !== true) {
      $this->cleanupTempDir($tempDir);
      return '<div class="notice notice-error"><p>ZIP-Archiv konnte nicht erstellt werden.</p></div>';
    }

    foreach ($normalizedFiles as $filePath) {
      $zip->addFile($filePath, basename($filePath));
    }
    $zip->close();

    // Upload zip to S3
    $s3 = LbwpCore::getModule('S3Upload');
    $s3Url = $s3->uploadDiskFile($zipPath, 'application/zip');

    // Clean up local temp files
    $this->cleanupTempDir($tempDir);

    if (empty($s3Url)) {
      return '<div class="notice notice-error"><p>S3-Upload fehlgeschlagen.</p></div>';
    }

    // Build queue with only SKU => post_id + file basenames (no local paths)
    $queue = array();
    foreach ($grouped as $sku => $data) {
      $queue[$sku] = array(
        'post_id' => $data['post_id'],
        'files' => array_map('basename', $data['files'])
      );
    }

    // Store import state
    update_option(self::OPTION_KEY, array(
      'status' => 'pending',
      'uploaded' => date('Y-m-d H:i:s'),
      'last_updated' => date('Y-m-d H:i:s'),
      'url' => $s3Url,
      'queue' => $queue,
      'skus_total' => count($queue),
      'skus_imported' => 0,
      'images_imported' => 0,
      'log' => array()
    ), false);

    // Register immediate background cron
    Cronjob::register(array(
      time() => self::CRON_IDENTIFIER
    ));

    return '<div class="notice notice-success"><p>Dateien hochgeladen, Import wird im Hintergrund gestartet (' . count($queue) . ' SKUs).</p></div>';
  }

  /**
   * Reset the import: delete S3 file and clear option
   * @return string HTML message
   */
  protected function handleReset()
  {
    $current = get_option(self::OPTION_KEY, array());

    if (!empty($current['url'])) {
      $this->deleteS3File($current['url']);
    }

    delete_option(self::OPTION_KEY);
    return '<div class="notice notice-success"><p>Import wurde zurückgesetzt.</p></div>';
  }

  /**
   * Background cron handler — downloads zip from S3 on first run,
   * processes a batch of SKUs, then re-registers if more remain
   */
  public function runImport()
  {
    ini_set('memory_limit', '2G');
    set_time_limit(1200);

    $current = get_option(self::OPTION_KEY, array());
    if (empty($current) || empty($current['queue']) || empty($current['url'])) {
      return;
    }

    $current['status'] = 'running';
    update_option(self::OPTION_KEY, $current, false);

    // Download zip from S3 and extract to local temp directory
    $tempDir = File::getNewUploadFolder();
    $zipPath = $tempDir . 'pimport_images.zip';
    file_put_contents($zipPath, file_get_contents($current['url']));
    $this->extractZip($zipPath, $tempDir);
    @unlink($zipPath);

    $processed = 0;

    while (!empty($current['queue']) && $processed < self::BATCH_SIZE) {
      // Take the first SKU from the queue
      $sku = key($current['queue']);
      $data = array_shift($current['queue']);
      $postId = $data['post_id'];
      $fileNames = $data['files'];

      // Resolve basenames to local paths
      $filePaths = array();
      foreach ($fileNames as $fileName) {
        $filePaths[] = $tempDir . $fileName;
      }

      $this->removeExistingImages($postId, count($filePaths));
      $this->attachImages($postId, $filePaths);

      $current['skus_imported']++;
      $current['images_imported'] += count($filePaths);
      $current['log'][] = date('H:i:s') . ' – ' . $sku . ': ' . count($filePaths) . ' Bild(er)';
      $current['last_updated'] = date('Y-m-d H:i:s');
      $processed++;

      // Persist progress after each SKU
      update_option(self::OPTION_KEY, $current, false);
    }

    // Clean up local temp directory
    $this->cleanupTempDir($tempDir);

    if (!empty($current['queue'])) {
      // More SKUs remain — register next cron run
      Cronjob::register(array(
        time() => self::CRON_IDENTIFIER
      ));
    } else {
      // All done — delete zip from S3
      $this->deleteS3File($current['url']);
      $current['status'] = 'completed';
      $current['url'] = '';
      $current['last_updated'] = date('Y-m-d H:i:s');
      update_option(self::OPTION_KEY, $current, false);
    }
  }

  /**
   * Delete a file from S3
   * @param string $url the S3 URL
   */
  protected function deleteS3File($url)
  {
    if (!empty($url)) {
      $s3 = LbwpCore::getModule('S3Upload');
      $s3->deleteFile($url);
    }
  }

  /**
   * Extract images from a ZIP archive
   * @param string $zipPath path to the zip file
   * @param string $destDir destination directory
   * @return array list of extracted image file paths
   */
  protected function extractZip($zipPath, $destDir)
  {
    $images = array();
    $zip = new \ZipArchive();

    if ($zip->open($zipPath) !== true) {
      return $images;
    }

    for ($i = 0; $i < $zip->numFiles; $i++) {
      $name = $zip->getNameIndex($i);
      $basename = basename($name);

      // Skip directories and hidden/system files
      if (substr($basename, 0, 1) === '.' || substr($basename, 0, 2) === '__') {
        continue;
      }

      $ext = strtolower(pathinfo($basename, PATHINFO_EXTENSION));
      if (!in_array($ext, self::ALLOWED_EXTENSIONS)) {
        continue;
      }

      $zip->extractTo($destDir, $name);
      $extractedPath = $destDir . $name;

      // If file was in a subdirectory inside the zip, move it to destDir root
      if ($basename !== $name) {
        $flatPath = $destDir . $basename;
        rename($extractedPath, $flatPath);
        $extractedPath = $flatPath;
      }

      $images[] = $extractedPath;
    }

    $zip->close();
    return $images;
  }

  /**
   * Build SKU => post_id map from postmeta
   * @return array
   */
  protected function buildSkuMap()
  {
    global $wpdb;

    $results = $wpdb->get_results($wpdb->prepare(
      "SELECT pm.meta_value AS sku, pm.post_id
       FROM {$wpdb->postmeta} pm
       INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
       WHERE pm.meta_key = 'sku' AND p.post_type = %s",
      self::PIM_TYPE_SLUG
    ));

    $map = array();
    if ($results) {
      foreach ($results as $row) {
        $map[$row->sku] = (int)$row->post_id;
      }
    }

    return $map;
  }

  /**
   * Parse a filename to extract SKU and optional index.
   * Supports: sku.ext, sku-N.ext, sku_N.ext
   * @param string $filename basename of the file
   * @param array $skuMap known SKUs
   * @return array|false array with 'sku' and 'index' keys, or false
   */
  protected function parseFileName($filename, $skuMap)
  {
    $name = pathinfo($filename, PATHINFO_FILENAME);

    // Try exact SKU match first (no index suffix)
    if (isset($skuMap[$name])) {
      return array('sku' => $name, 'index' => 0);
    }

    // Try sku-N or sku_N pattern
    if (preg_match('/^(.+)[_-](\d+)$/', $name, $matches)) {
      $sku = $matches[1];
      $index = (int)$matches[2];
      if (isset($skuMap[$sku])) {
        return array('sku' => $sku, 'index' => $index);
      }
    }

    return false;
  }

  /**
   * Group image files by their SKU
   * @param array $files list of file paths
   * @param array $skuMap SKU => post_id map
   * @return array grouped as sku => ['post_id' => int, 'files' => [['path' => string, 'index' => int], ...]]
   */
  protected function groupFilesBySku($files, $skuMap)
  {
    $grouped = array();

    foreach ($files as $filePath) {
      $basename = basename($filePath);
      $parsed = $this->parseFileName($basename, $skuMap);

      if ($parsed === false) {
        continue;
      }

      $sku = $parsed['sku'];
      if (!isset($grouped[$sku])) {
        $grouped[$sku] = array(
          'post_id' => $skuMap[$sku],
          'files' => array()
        );
      }

      $grouped[$sku]['files'][] = array(
        'path' => $filePath,
        'index' => $parsed['index']
      );
    }

    // Sort files within each group by index
    foreach ($grouped as &$data) {
      usort($data['files'], function ($a, $b) {
        return $a['index'] - $b['index'];
      });
    }

    return $grouped;
  }

  /**
   * Normalize file names to sku-0.ext, sku-1.ext, ...
   * Renames files on disk and returns ordered list of paths.
   * @param array $files array of ['path' => string, 'index' => int]
   * @param string $sku the product SKU
   * @return array ordered list of new file paths
   */
  protected function normalizeFileNames($files, $sku)
  {
    $normalized = array();

    foreach ($files as $i => $file) {
      $ext = strtolower(pathinfo($file['path'], PATHINFO_EXTENSION));
      $dir = dirname($file['path']) . '/';
      $newName = $sku . '-' . $i . '.' . $ext;
      $newPath = $dir . $newName;

      if ($file['path'] !== $newPath) {
        rename($file['path'], $newPath);
      }

      $normalized[] = $newPath;
    }

    return $normalized;
  }

  /**
   * Remove existing images (thumbnail + gallery) from a product post
   * @param int $postId the product post ID
   * @param int $newImageCount number of new images being imported
   */
  protected function removeExistingImages($postId, $newImageCount)
  {
    $fieldMap = $this->getGalleryFieldMap($newImageCount);

    // Remove existing thumbnail
    $thumbnailId = get_post_meta($postId, '_thumbnail_id', true);
    if ($thumbnailId) {
      wp_delete_attachment((int)$thumbnailId, true);
      delete_post_meta($postId, '_thumbnail_id');
    }

    // Remove existing gallery images
    foreach ($fieldMap as $fieldName) {
      $attachmentId = get_post_meta($postId, $fieldName, true);
      if ($attachmentId) {
        wp_delete_attachment((int)$attachmentId, true);
        delete_post_meta($postId, $fieldName);
      }
    }
  }

  /**
   * Attach images to a product post (thumbnail + gallery fields)
   * @param int $postId the product post ID
   * @param array $files ordered list of normalized file paths (index 0 = thumbnail)
   */
  protected function attachImages($postId, $files)
  {
    $fieldMap = $this->getGalleryFieldMap(count($files));

    foreach ($files as $index => $filePath) {
      // createAttachmentImageFromFile deletes the containing folder,
      // so each file must be moved to its own isolated folder first
      $isolatedDir = File::getNewUploadFolder();
      $isolatedPath = $isolatedDir . basename($filePath);
      copy($filePath, $isolatedPath);

      $attachmentId = WordPress::createAttachmentImageFromFile($isolatedPath, $postId);

      if (!$attachmentId) {
        continue;
      }

      if ($index === 0) {
        update_post_meta($postId, '_thumbnail_id', $attachmentId);
      } else {
        $fieldName = $fieldMap[$index - 1];
        update_post_meta($postId, $fieldName, $attachmentId);
      }
    }
  }

  /**
   * Get the gallery field name map. Filterable for developers.
   * Default: gallery-1, gallery-2, gallery-3, ...
   * @param int $imageCount total number of images (including thumbnail)
   * @return array field names for gallery images (index 0 = first gallery image, i.e. sku-1)
   */
  protected function getGalleryFieldMap($imageCount)
  {
    $galleryCount = max(0, $imageCount - 1);
    $fields = array();

    for ($i = 1; $i <= $galleryCount; $i++) {
      $fields[] = 'gallery-' . $i;
    }

    return apply_filters('lbwp_pim_image_gallery_fields', $fields, $imageCount);
  }

  /**
   * Remove a temporary directory and its contents
   * @param string $dir directory path
   */
  protected function cleanupTempDir($dir)
  {
    if (!is_dir($dir)) {
      return;
    }

    $iterator = new \RecursiveIteratorIterator(
      new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
      \RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($iterator as $item) {
      if ($item->isDir()) {
        rmdir($item->getRealPath());
      } else {
        unlink($item->getRealPath());
      }
    }

    rmdir($dir);
  }
}
