<?php

namespace LBWP\Module\General\Cms;

use LBWP\Core;
use LBWP\Module\BaseSingleton;
use WP_REST_Request;
use WP_REST_Response;
use WP_Post;

/**
 * Converts uploaded jpg/png images to webp, for the media library (admin-ajax) and block editor (REST) uploads
 * @package LBWP\Module\General\Cms
 * @author Michael Sebel <michael@comotive.ch>
 */
class WebpConversion extends BaseSingleton
{
  /**
   * Mime types that are converted to webp on upload
   */
  const array CONVERTIBLE_TYPES = ['image/jpeg', 'image/png'];
  /**
   * The REST route the block editor uploads new media to
   */
  const string MEDIA_ROUTE = '/wp/v2/media';
  /**
   * Color profile used to normalize the converted image
   */
  const string SRGB_PROFILE = '/usr/share/color/icc/sRGB.icc';
  /**
   * @var bool true, if the current REST request had its upload converted
   */
  protected bool $convertedRestUpload = false;

  /**
   * Registers the REST hooks, if conversion is enabled
   * @return void
   */
  public function run(): void
  {
    if (!self::isEnabled()) {
      return;
    }

    add_filter('rest_dispatch_request', [$this, 'convertRestUpload'], 10, 2);
    add_filter('rest_prepare_attachment', [$this, 'requestWebpSubSizes'], 10, 3);
  }

  /**
   * @return bool true, if uploads should be converted to webp
   */
  public static function isEnabled(): bool
  {
    $config = Core::getInstance()->getConfig();
    $disabled = isset($config['Various:DisableWebpConversion']) && $config['Various:DisableWebpConversion'] == 1;
    return !defined('LBWP_DISABLE_AUTO_WEBP_CONVERSION') && !$disabled;
  }

  /**
   * @param array $file an entry of $_FILES
   * @return bool true, if the file is an image that should be converted
   */
  public static function isConvertible(array $file): bool
  {
    return isset($file['type'], $file['tmp_name'])
      && in_array($file['type'], self::CONVERTIBLE_TYPES)
      && file_exists($file['tmp_name']);
  }

  /**
   * Converts the uploaded temp file to webp in place and adapts name, type and size accordingly
   * @param array $file an entry of $_FILES
   * @return array the changed entry, so WordPress thinks a webp was uploaded
   */
  public static function convertUploadedFile(array $file): array
  {
    $sourceName = str_contains($file['type'], '/jp') ? $file['tmp_name'] . '.jpg' : $file['tmp_name'] . '.png';
    $convertedName = $file['tmp_name'] . '.webp';
    copy($file['tmp_name'], $sourceName);
    $command = sprintf(
      'convert %s -black-point-compensation -intent relative -profile %s -quality 85 -define webp:method=6 %s 2>&1',
      escapeshellarg($sourceName),
      self::SRGB_PROFILE,
      escapeshellarg($convertedName)
    );
    exec($command, $output, $returnCode);
    unlink($sourceName);

    // Keep the original upload untouched if imagemagick failed, so the upload still works
    if ($returnCode !== 0 || !file_exists($convertedName)) {
      SystemLog::add('WebpConversion', 'error', 'Conversion failed', ['output' => implode(PHP_EOL, $output)]);
      return $file;
    }

    rename($convertedName, $file['tmp_name']);
    $file['type'] = 'image/webp';
    $file['name'] = preg_replace('/\.(jpg|jpeg|png)$/i', '.webp', $file['name']);
    if (isset($file['full_path'])) {
      $file['full_path'] = preg_replace('/\.(jpg|jpeg|png)$/i', '.webp', $file['full_path']);
    }
    $file['size'] = filesize($file['tmp_name']);
    return $file;
  }

  /**
   * Converts the original image of a block editor upload, after the permission check has passed
   * @param mixed $result null to continue dispatching, as given
   * @param WP_REST_Request $request the current request
   * @return mixed $result unchanged
   */
  public function convertRestUpload(mixed $result, WP_REST_Request $request): mixed
  {
    if ($result !== null || $request->get_method() !== 'POST' || $request->get_route() !== self::MEDIA_ROUTE) {
      return $result;
    }

    $files = $request->get_file_params();
    if (!isset($files['file']) || !self::isConvertible($files['file'])) {
      return $result;
    }

    $files['file'] = self::convertUploadedFile($files['file']);
    $request->set_file_params($files);
    $this->convertedRestUpload = $files['file']['type'] === 'image/webp';
    return $result;
  }

  /**
   * Tells the client side media processing to encode all sub sizes as webp. As the original is already webp,
   * core computes no output format, and the client would encode the sizes in the format of its local jpg/png copy.
   * @param WP_REST_Response $response the attachment response
   * @param WP_Post $_post the attachment
   * @param WP_REST_Request $request the current request
   * @return WP_REST_Response the response with the output format set
   */
  public function requestWebpSubSizes(WP_REST_Response $response, WP_Post $_post, WP_REST_Request $request): WP_REST_Response
  {
    $data = $response->get_data();
    if (!$this->convertedRestUpload || $request->get_route() !== self::MEDIA_ROUTE || !array_key_exists('image_output_format', $data)) {
      return $response;
    }

    $data['image_output_format'] = 'image/webp';
    $response->set_data($data);
    return $response;
  }
}
