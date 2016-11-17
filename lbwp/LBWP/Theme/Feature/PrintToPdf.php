<?php

namespace LBWP\Theme\Feature;

use LBWP\Module\Frontend\HTMLCache;
use LBWP\Util\ArrayManipulation;
use LBWP\Util\File;
use LBWP\Util\Strings;
use LBWP\Core as LbwpCore;
use LBWP\Module\Backend\S3Upload;

/**
 * Allow to make multi column layouts with specified break html tags
 * Defaults to use a <hr> Tag for spanning
 * @author Michael Sebel <michael@comotive.ch>
 * @package LBWP\Theme\Feature
 */
class PrintToPdf
{
  /**
   * @var array Contains all options for the breadcrumb
   */
  protected $options = array(
    'apiKey' => 'H3CM2Yff0XwryukWJdB',
    'libraryVersion' => '1.0.0',
    'useJavascript' => true
  );
  /**
   * @var PrinttoPdf the instance
   */
  protected static $instance = NULL;
  /**
   * @var string the meta param for the presaved pdf
   */
  const META_PDF_URL = 'print-to-pdf-url';
  /**
   * @var string the meta param for the attachment id
   */
  const META_PDF_ATTACHMENT_ID = 'print-to-pdf-attachment-id';

  /**
   * Can only be called within init
   */
  protected function __construct($options)
  {
    $this->options = ArrayManipulation::deepMerge($this->options, $options);
    $version = $this->options['libraryVersion'];

    // Include the doc raptor autoloader to gain access to the classes
    require_once ABSPATH . '/wp-content/plugins/lbwp/resources/libraries/docraptor/' . $version . '/autoload.php';

    // Add the filters to delete the pdf on save with published posts that have a pdf
    add_action('save_post', array($this, 'onSaveFlushPdfData'));
    add_action('wp', array($this, 'listenForPdfParam'));
  }

  /**
   * Listen for the pdf generation parameter. We need to to this on "wp" action, because
   * earlier, the permalinks of custom types might not be generated correctly
   */
  public function listenForPdfParam()
  {
    if (isset($_GET['print-to-pdf']) && intval($_GET['print-to-pdf']) > 0) {
      $this->generatePostPdf(intval($_GET['print-to-pdf']));
    }
  }

  /**
   * On save of an object, flush PDF data (delete actual attachment) and meta
   */
  public function onSaveFlushPdfData($postId)
  {
    $url = get_post_meta($postId, self::META_PDF_URL, true);
    $attachmentId = get_post_meta($postId, self::META_PDF_ATTACHMENT_ID, true);
    // If either of both if valid, delete all for next generation
    if (Strings::isURL($url) || $attachmentId > 0) {
      delete_post_meta($postId, self::META_PDF_URL);
      delete_post_meta($postId, self::META_PDF_ATTACHMENT_ID);
      wp_delete_attachment($attachmentId, true);
      /** @var S3Upload $upload to the upload */
      $upload = LbwpCore::getModule('S3Upload');
      $upload->deleteFile($url);
    }
  }

  /**
   * Generates a pdf, saves it as attachment to the post and saves a meta param
   * @param int $postId the post we should generate a pdf of
   */
  protected function generatePostPdf($postId)
  {
    $printedPost = get_post($postId);
    // Get the pdf data and save as temporary file (That will be dismissed by the server)
    $data = $this->getPdfDataStream($printedPost);
    $fileUrl = $this->uploadToCdn($data, $printedPost);

    // Save the PDF as an attachment to the post
    $attachment = array(
      'post_mime_type' => 'application/pdf',
      'post_title' => $printedPost->post_name . '.pdf',
      'post_content' => '',
      'post_status' => 'inherit',
      'guid' => $fileUrl
    );

    // Insert the attachment (Which automatically uploads to CDN)
    $attachmentId = wp_insert_attachment($attachment, false, $postId);

    // Save a meta field with the actual URL
    update_post_meta($printedPost->ID, self::META_PDF_URL, $fileUrl);
    update_post_meta($printedPost->ID, self::META_PDF_ATTACHMENT_ID, $attachmentId);

    // Flush the cache for this page (to regenerate the page using the meta link)
    HTMLCache::cleanPostHtmlCache($printedPost->ID);

    // Directly output the file for the generating user
    header('Content-Type: application/pdf');
    header('Cache-Control: private, must-revalidate, post-check=0, pre-check=0, max-age=1');
    header('Pragma: public');
    header('Expires: Sat, 26 Jul 1997 05:00:00 GMT'); // Date in the past
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s') . ' GMT');
    header('Content-Disposition: inline; filename="' . $attachment['post_title'] . '"');
    echo $data;
    exit;
  }

  /**
   * @param string $data file stream
   * @param \WP_Post $printedPost
   * @return string the uploaded file url
   */
  protected function uploadToCdn($data, $printedPost)
  {
    // Save the file on hard disk first
    $filePath = File::getNewUploadFolder() . $printedPost->post_name . '.pdf';
    file_put_contents($filePath, $data);
    /** @var S3Upload $upload to the upload */
    $upload = LbwpCore::getModule('S3Upload');
    return $upload->uploadDiskFile($filePath);
  }

  /**
   * @param \WP_Post $printedPost
   * @return string the pdf data stream
   */
  protected function getPdfDataStream($printedPost)
  {
    // Get the PDF stream from doc raptor
    $config = \DocRaptor\Configuration::getDefaultConfiguration();
    $config->setUsername($this->options['apiKey']);
    $docraptor = new \DocRaptor\DocApi();
    $document = new \DocRaptor\Doc();

    // Configure the document
    $document->setTest(defined('LOCAL_DEVELOPMENT'));
    $document->setJavascript($this->options['useJavascript']);
    $document->setName($printedPost->post_name . '.pdf');
    $document->setDocumentType('pdf');
    $document->setStrict('none');

    // Set the document url
    $url = get_permalink($printedPost->ID);
    $document->setDocumentUrl($url);
    return $docraptor->createDoc($document);
  }

  /**
   * @param array $options load the given options
   */
  public static function init($options = array())
  {
    self::$instance = new PrintToPdf($options);
  }

  /**
   * @param int $postId the post that needs to be made a PDF of
   * @return string url to generator or directly load the post PDF
   */
  public static function getSinglePdfLink($postId)
  {
    $url = get_post_meta($postId, self::META_PDF_URL, true);
    if (!Strings::isURL($url)) {
      $url = Strings::attachParam('print-to-pdf', $postId, get_permalink($postId));
    }

    return $url;
  }
}
