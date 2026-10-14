<?php

namespace LBWP\Theme\Feature;

use LBWP\Core as LbwpCore;
use LBWP\Helper\Cronjob;
use LBWP\Helper\PdfTextExtractor;
use LBWP\Module\General\Cms\SystemLog;
use LBWP\Util\WordPress;

/**
 * Indexes the text content of PDF attachments into their post_content, so the search can find them.
 * Runs daily for new uploads and can be triggered manually to (re)index all PDFs.
 * Enabled by defining LBWP_SEARCH_INDEX_PDFS in the instance config.
 * @package LBWP\Theme\Feature
 * @author Mirko Baffa <mirko@comotive.ch>
 */
class PdfSearchIndex
{
  /**
   * @var string meta key holding the index status of an attachment
   */
  const STATUS_META_KEY = 'lbwp_pdf_index_status';
  /**
   * @var string status for a pdf whose text has been saved
   */
  const STATUS_INDEXED = 'indexed';
  /**
   * @var string status for a pdf without text layer (e.g. scanned documents)
   */
  const STATUS_EMPTY = 'empty';
  /**
   * @var string status for a pdf that could not be fetched
   */
  const STATUS_FAILED = 'failed';
  /**
   * @var int number of pdfs processed per cron run
   */
  const BATCH_SIZE = 50;
  /**
   * @var int seconds after which a batch stops, cron jobs are killed after 55 seconds
   */
  const BATCH_TIME_LIMIT = 45;
  /**
   * @var string job identifier to process the next batch
   */
  const BATCH_JOB = 'index_pdf_batch';
  /**
   * @var string job identifier to manually reindex all pdfs
   */
  const MANUAL_JOB = 'manual_index_all_pdfs';

  /**
   * Registers the cron hooks
   */
  public function __construct()
  {
    add_action('cron_daily_3', [$this, 'runBatch']);
    add_action('cron_job_' . self::BATCH_JOB, [$this, 'runBatch']);
    add_action('cron_job_' . self::MANUAL_JOB, [$this, 'reindexAll']);
  }

  /**
   * Resets the index status of all pdfs and starts indexing them from scratch
   * @return void
   */
  public function reindexAll()
  {
    delete_metadata('post', 0, self::STATUS_META_KEY, '', true);
    $this->runBatch();
  }

  /**
   * Indexes the next batch of unindexed pdfs and schedules a follow-up batch if more are left
   * @return void
   */
  public function runBatch()
  {
    if (!PdfTextExtractor::isAvailable()) {
      SystemLog::add('PdfSearchIndex', 'error', 'pdftotext binary is not available, pdfs can not be indexed');
      return;
    }

    $start = time();
    foreach ($this->getUnindexedIds(self::BATCH_SIZE) as $attachmentId) {
      $this->indexAttachment($attachmentId);
      if (time() - $start > self::BATCH_TIME_LIMIT) {
        break;
      }
    }

    if (count($this->getUnindexedIds(1)) > 0) {
      $this->scheduleNextBatch();
    }
  }

  /**
   * Extracts the text of one pdf attachment and saves it as its post_content
   * @param int $attachmentId the attachment id
   * @return void
   */
  protected function indexAttachment($attachmentId)
  {
    $localFile = $this->getLocalCopy($attachmentId);
    if ($localFile === false) {
      update_post_meta($attachmentId, self::STATUS_META_KEY, self::STATUS_FAILED);
      return;
    }

    $text = PdfTextExtractor::extractText($localFile);
    unlink($localFile);

    $this->saveContent($attachmentId, $text);
    update_post_meta($attachmentId, self::STATUS_META_KEY, strlen($text) > 0 ? self::STATUS_INDEXED : self::STATUS_EMPTY);
  }

  /**
   * Writes the text directly to post_content to avoid save_post hooks and kses filtering
   * @param int $attachmentId the attachment id
   * @param string $text the extracted text
   * @return void
   */
  protected function saveContent($attachmentId, $text)
  {
    $db = WordPress::getDb();
    $db->update($db->posts, ['post_content' => $text], ['ID' => $attachmentId]);
    clean_post_cache($attachmentId);
  }

  /**
   * Downloads the pdf into a temporary file, from the storage if available, else from the local disk
   * @param int $attachmentId the attachment id
   * @return string|false path to the temporary copy or false on failure
   */
  protected function getLocalCopy($attachmentId)
  {
    $tempFile = tempnam(sys_get_temp_dir(), 'lbwp-pdf-');
    try {
      $content = $this->getFileContent($attachmentId);
    } catch (\Exception $e) {
      SystemLog::add('PdfSearchIndex', 'error', 'Could not fetch pdf', [
        'attachmentId' => $attachmentId,
        'error' => $e->getMessage()
      ]);
      $content = false;
    }

    if ($content === false || file_put_contents($tempFile, $content) === false) {
      unlink($tempFile);
      return false;
    }

    return $tempFile;
  }

  /**
   * Gets the raw binary content of an attachment file
   * @param int $attachmentId the attachment id
   * @return string|false the file content or false if not found
   * @throws \Exception if the storage request fails
   */
  protected function getFileContent($attachmentId)
  {
    if (LbwpCore::isModuleActive('S3Upload')) {
      $s3 = LbwpCore::getModule('S3Upload');
      $key = $s3->getKeyFromUrl(wp_get_attachment_url($attachmentId));
      return (string) $s3->getRawObject($key)->get('Body');
    }

    $path = get_attached_file($attachmentId);
    return is_readable($path) ? file_get_contents($path) : false;
  }

  /**
   * Gets ids of pdf attachments that have not been processed yet
   * @param int $limit maximum number of ids
   * @return int[] the attachment ids
   */
  protected function getUnindexedIds($limit)
  {
    $db = WordPress::getDb();
    $query = $db->prepare('
      SELECT p.ID FROM ' . $db->posts . ' p
      LEFT JOIN ' . $db->postmeta . ' m ON m.post_id = p.ID AND m.meta_key = %s
      WHERE p.post_type = "attachment" AND p.post_mime_type = "application/pdf" AND m.meta_id IS NULL
      ORDER BY p.ID ASC
      LIMIT %d
    ', self::STATUS_META_KEY, $limit);

    return array_map('intval', $db->get_col($query));
  }

  /**
   * Registers a single job to process the next batch in a minute
   * @return void
   */
  protected function scheduleNextBatch()
  {
    Cronjob::register([
      current_time('timestamp') + 60 => self::BATCH_JOB
    ], 1);
  }
}
