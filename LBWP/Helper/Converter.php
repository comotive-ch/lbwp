<?php

namespace LBWP\Helper;

use LBWP\Core;
use LBWP\Core as LbwpCore;
use LBWP\Module\Backend\S3Upload;
use LBWP\Module\General\Cms\SystemLog;
use LBWP\Util\ArrayManipulation;
use LBWP\Util\File;
use CloudConvert\CloudConvert;
use CloudConvert\Models\Job;
use CloudConvert\Models\Task;
use CloudConvert\Models\ImportUploadTask;
use function GuzzleHttp\Psr7\str;

/**
 * Utilizes Cloud Convert to convert copious amounts of file types
 * @package LBWP\Helper
 */
class Converter
{
  /**
   * @var CloudConvert the api object
   */
  protected static $api = null;

  /**
   * Initializes the process
   */
  public static function initialize()
  {
    require_once File::getResourcePath() . '/libraries/cloudconvert/v2/vendor/autoload.php';
    self::$api = new CloudConvert(array(
      'api_key' => 'eyJ0eXAiOiJKV1QiLCJhbGciOiJSUzI1NiJ9.eyJhdWQiOiIxIiwianRpIjoiYzA1OWY0NDY5YmNlNjM2YjM4MDU2NmZkYmVmMmFiN2M2MzI5NWY2ZmNjYTViMjhiN2RhOTJhYzcwYzUyOTNlM2ZlMmQyMTM2OGNiZDBmOWUiLCJpYXQiOjE2NDE4MDQ5NzYuMzg1ODk5LCJuYmYiOjE2NDE4MDQ5NzYuMzg1OTAzLCJleHAiOjQ3OTc0Nzg1NzYuMzcxMTcxLCJzdWIiOiIzOTM1Mzc5MyIsInNjb3BlcyI6WyJ1c2VyLnJlYWQiLCJ0YXNrLnJlYWQiLCJ1c2VyLndyaXRlIiwidGFzay53cml0ZSJdfQ.X8PrIbRRA1pGOdyklavR0fKfACcvtdbEm-6vHK6lLn02KlancYnyNLWvsOj-eUECbcISEvJ3oksoN0KCKF2-iizQScyLPJfexs8bLRqISA0lBIJunvpL1jft-qGHJXU47Snnl2G22ilAUg7_cbpaiNtS7Jrxo6LDpC8fWWKL-5Byim3Vv4j2w_1Ewgebhfr-Jt4K4oqncozxrPwh-gJLtc73Fr0IURzhNG9zrRKEREihmxDlickuuUAlgpRpav-D6GdSh8e5ZLzncDc4c0D8BfxTnVWwJPks3gMruEeODVEwihjr7T5-AmJeU9OGcNSFx3PnChJSKC_PFSnBMw6cgpIDO3pS1QMYZCLyosAr3o3PJMrLCsSjIdoxH6hwsd3Q6iCflGwZc1yHLq4qagngSIzMKCxmjJCGh5zR25vzOHQfYqAdkfj9oNuBnMRiCvQ3ZGH6Iykg1EaYgY40iiKU_PIrmRHBY7z3AdSf73VQmhiRjscTXSa2wqXIOLZUqeZfZ1sflqftRwVK1cYVzvVbLg4__ioTiSCss1LZ9CQZbGgMCWAC-r6C08tRwzPj-CxtokBliI8wUjr8lLOq0dgIlg6o2mpQuNcYoTenPI3Es1y6QyZDDDZBzM5RMLBNjl_CXxa1XVSDgsIgfLwrbxTNJmk46ajLggFGgWHUWQQqNEo',
      'sandbox' => false
    ));
  }

  /**
   * @param $file
   * @param $from
   * @param $to
   */
  public static function convert($file, $from, $to)
  {
    $job = (new Job())
      ->addTask(
        new Task('import/upload','upload-my-file')
      )
      ->addTask(
        (new Task('convert', 'convert-my-file'))
          ->set('input', 'upload-my-file')
          ->set('output_format', $to)
      )
      ->addTask(
        (new Task('export/url', 'export-my-file'))
          ->set('input', 'convert-my-file')
      );

    self::$api->jobs()->create($job);
    $uploadTask = $job->getTasks()->whereName('upload-my-file')[0];
    $fileName = File::getFileOnly($file);
    self::$api->tasks()->upload($uploadTask, fopen($file, 'r'), $fileName);
    self::$api->jobs()->wait($job);

    // Download the file to $output
    $output = false;
    foreach ($job->getExportUrls() as $fileHandle) {
      $output = str_replace('.' . $from, '.' . $to, $file);
      $source = self::$api->getHttpTransport()->download($fileHandle->url)->detach();
      $dest = fopen($output, 'w');
      stream_copy_to_stream($source, $dest);
    }

    return $output;
  }

  public static function forceNonWebpImageUrl($url)
  {
    // Check if the url is even on our block storage, don't convert then
    if (stripos($url, '/lbwp-cdn/' . ASSET_KEY) === false){
      return $url;
    }
    // Check if it even is an webp image
    if (str_ends_with($url, '.webp') === false){
      return $url;
    }

    // Small chars as its our own url, thats always lowercase
    $url = strtolower($url);
    // get substring of the url to after /files/
    $path = strtolower(substr($url, strpos($url, '/files/') + 7));
    $jpgKey = str_replace('.webp', '.jpg', $path);

    // Get global option of already force converted files
    $convertedFiles = ArrayManipulation::forceArray(get_option('lbwp_force_non_webp_images'));
    // If already converted, just return the converted url
    if (isset($convertedFiles[$jpgKey])) {
      return str_replace('.webp', '.jpg', $url);
    }

    // If not, download the file locally and convert it
    /** @var S3Upload $s3 */
    $s3 = LbwpCore::getModule('S3Upload');
    $key = $s3->getKeyFromUrl($url);
    $rawObject = $s3->getRawObject($key);
    $localJpgPath = File::getNewUploadFolder() . str_replace('/', '-', $jpgKey);
    $localWebpPath = str_replace('.jpg', '.webp', $localJpgPath);
    file_put_contents($localWebpPath, $rawObject->get('Body'));
    // Convert the file on console
    shell_exec('convert ' . $localWebpPath . ' ' . $localJpgPath);
    // Upload the file to the block storage
    $jpgUrl = $s3->uploadDiskFileFixedPath($localJpgPath, '/' . $jpgKey);
    $convertedFiles[$jpgKey] = true;
    update_option('lbwp_force_non_webp_images', $convertedFiles);

    return $jpgUrl;
  }

  /**
   * Generate a docx file from html
   * @return void|string
   */
  public static function htmlToDoc($content, $name = 'doc', $lang = 'de-CH', $return = false){
    // Save html content as file
    $htmlFile = File::getNewUploadFolder() . $name . '.html';
    $docxFile = File::getNewUploadFolder() . $name . '.docx';
    file_put_contents($htmlFile, $content);

    $config = Core::getInstance()->getConfig();
    $refDocxId = $config['Various:PandocReferenceDocument'];
    $useRefDocx = '';

    if(intval($refDocxId) > 0){
      $refDocxUrl = wp_get_attachment_url($config['Various:PandocReferenceDocument']);

      /** @var LBWP\Module\Backend\S3Upload $s3 */
      $s3 = LbwpCore::getModule('S3Upload');
      $key = $s3->getKeyFromUrl($refDocxUrl);
      $rawObject = $s3->getRawObject($key);

      $refDocx = '/tmp/pandoc-reference.docx';

      file_put_contents($refDocx, $rawObject->get('Body'));

      $useRefDocx = ' --reference-doc ' . $refDocx;
    }

    // Convert html to docx
    shell_exec('pandoc -s -f html -t docx ' . $useRefDocx . ' -V lang=' . $lang . ' ' . $htmlFile . ' -o ' . $docxFile);

    if($return){
      return $docxFile;
    }

    // Download docx
    ob_end_clean();
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachement;filename="' . $name . '.docx"');
    echo file_get_contents($docxFile);
    exit;
  }

  /**
   * Conver HTML to PDF
   * @param $content string html content
   * @param $name string name of the file
   * @return void
   */
  public static function htmlToPdf($content, $name = 'doc', $styles = false)
  {
    // Save html content as file
    $folder = File::getNewUploadFolder();
    $htmlFile = $folder . $name . '.html';
    $pdfFile = $folder . $name . '.pdf';
    file_put_contents($htmlFile, $content);

    if($styles !== false){
      $cssFile = $folder . $name . '.css';
      file_put_contents($cssFile, $styles);
    }

    // Convert html to docx (available variables: https://pandoc.org/chunkedhtml-demo/6.2-variables.html#variables-for-latex)
    shell_exec('cd ' . $folder . ' && pandoc ' . $htmlFile . ' -o ' . $pdfFile . ' --pdf-engine=weasyprint' . ($styles !== false ? ' --css=' . $cssFile : ''));

    // Download docx
    ob_end_clean();
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachement;filename="' . $name . '.pdf"');
    echo file_get_contents($pdfFile);
    exit;
  }

  /**
   * Convert a docx file to pdf (note: styles from the docx file are not applied in the pdf)
   * @param $doc string docx file
   * @param $name string name of the file
   * @return void|string
   */
  public static function docxToPdf($doc, $name = 'doc', $return = false){
    $folder = File::getNewUploadFolder();
    $docxFile = $folder . $name . '.docx';
    $pdfFile = $folder . $name . '.pdf';
    file_put_contents($docxFile, file_get_contents($doc));

    // Convert html to docx (available variables: https://pandoc.org/chunkedhtml-demo/6.2-variables.html#variables-for-latex)
    shell_exec('cd ' . $folder . ' && pandoc ' . $docxFile . ' -o ' . $pdfFile . ' --pdf-engine=weasyprint');

    if($return){
      return $pdfFile;
    }

    // Download pdf
    ob_end_clean();
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachement;filename="' . $name . '.pdf"');
    echo file_get_contents($pdfFile);
    exit;
  }

  /**
   * @param $file
   * @return array
   */
  public static function excelToArray($file)
  {
    require_once ABSPATH . '/wp-content/plugins/lbwp/resources/libraries/phpspreadsheet/vendor/autoload.php';
    $excel = new \PhpOffice\PhpSpreadsheet\Reader\Xlsx();
    $spreadsheet = $excel->load($file);
    $worksheet = $spreadsheet->getActiveSheet();
    $raw = $worksheet->toArray();
    $data = array();
    // filter empty cells and rows out
    foreach ($raw as $row) {
      $filtered = array_filter($row, function($cell){
        return $cell !== null && $cell !== '';
      });
      if(count($filtered) > 0){
        $data[] = $filtered;
      }
    }

    return $data;
  }
}