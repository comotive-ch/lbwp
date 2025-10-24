<?php

namespace LBWP\Theme\Feature;

use LBWP\Helper\Cronjob;
use LBWP\Helper\MasterApi;
use LBWP\Module\General\Cms\SystemLog;
use LBWP\Util\ArrayManipulation;
use LBWP\Util\File;
use PhpParser\Error;

/**
 * Analize an image an get the mood (average) color and some related colors
 * @author Mirko Baffa <mirko@comotive.ch>
 * @package LBWP\Theme\Feature
 */
class MoodOwl
{
  /**
   * @var MoodOwl instance of the class
   */
  protected static $instance;

  /**
   * The name of the cron job duh
   */
  const CRON_JOB_NAME = 'save_image_color_data';

  /**
   * Initilize MoodOwl, register some things, the good stuff you know
   */
  public function __construct()
  {
    add_filter('wp_generate_attachment_metadata', array($this, 'queueCronJob'), 10, 2);
    add_action('cron_job_' . self::CRON_JOB_NAME, array($this, 'saveImageColorData'));
    add_filter('attachment_fields_to_edit', array($this, 'addMediaColorLink'), 10, 2);
  }

  /**
   * @return MoodOwl returns the instance
   */
  public static function getInstance()
  {
    return self::$instance;
  }

  /**
   * Enqueue cron job to analyze image and save the colors
   * @param $metadata
   * @param $attachementId
   * @return void
   */
  public function queueCronJob($metadata, $attachementId)
  {
    // Maybe use CronJob::register so that the jobs are queued
    Cronjob::register([
      current_time('timestamp') => self::CRON_JOB_NAME . '::' . $attachementId
    ]);

    return $metadata;
  }

  public function addMediaColorLink($fields, $post)
  {
    $user = get_user_by('id', get_current_user_id());
    if ($user->user_login === 'comotive' || $user->user_login === 'wesign') {
      $mimeType = get_post_mime_type($post->ID);
      if ($mimeType === 'image/jpeg' || $mimeType === 'image/png' || $mimeType === 'image/webp') {
        $imageMeta = wp_get_attachment_metadata($post->ID);
        $text = isset($imageMeta['colors']) ? 'Farben ansehen' : 'Farben generieren';
        $fields['moodowl_link'] = array(
          'label' => 'Bildfarben',
          'input' => 'html',
          'html' => '<a href="' . get_bloginfo('url') . '/wp-content/plugins/lbwp/views/cron/job.php?identifier=save_image_color_data&data=' . $post->ID . '" target="_blank">' . $text . '</a>'
        );
      }
    }

    return $fields;
  }

  /**
   * Cron job to save the colors of the image in its meta
   * @return void
   */
  public function saveImageColorData()
  {
    $imageId = intval($_GET['data']);
    set_time_limit(120);

    if ($imageId <= 0) {
      return;
    }

    $data = wp_get_attachment_metadata($imageId);
    if (!isset($data['colors']) || isset($_GET['regenerate'])) {
      //ini_set('memory_limit', '512M'); After optimization (maybe) not needed
      $data['colors'] = self::calculateColors(wp_get_attachment_image_url($imageId, 'full'));

      if ($data['colors'] !== false) {
        wp_update_attachment_metadata($imageId, $data);
      }
    }

    $this->showPreview($imageId, $data['colors']);
  }

  /**
   * Calculates the colors of the image
   * @param $image string path to the image
   * @param $accuracy int the number of subdivision of the color spectrum in a 3-dimensional space
   * @return array|bool the mood and related colors
   */
  public static function calculateColors($image, $accuracy = 3)
  {
    switch (File::getExtension($image)) {
      case '.png':
        $image = imagecreatefrompng($image);
        break;

      case '.jpg':
      case '.jpeg':
        $image = imagecreatefromjpeg($image);
        break;

      case '.webp':
        $image = imagecreatefromwebp($image);
        break;

      default:
        return false;
    }

    // Error handling if image can'n be generated
    if ($image === false) {
      SystemLog::mDebug('Image could not be generated', 'ID: ' . $_GET['data']);
      return false;
    }

    $color = ['related' => [], 'brightness' => 0];

    // Set the average color and initialize spectrum and other parts
    $avColor = ['r' => 0, 'g' => 0, 'b' => 0];
    $spectrum = array_fill(0, $accuracy, array_fill(0, $accuracy, array_fill(0, $accuracy, ['r' => 0, 'g' => 0, 'b' => 0, 'count' => 0])));
    $pixelCount = 0;

    for ($x = 0; $x < imagesx($image); $x++) {
      for ($y = 0; $y < imagesy($image); $y++) {
        $pixel = imagecolorat($image, $x, $y);

        $r = ($pixel >> 16) & 0xFF;
        $g = ($pixel >> 8) & 0xFF;
        $b = $pixel & 0xFF;

        $avColor['r'] += $r ** 2;
        $avColor['g'] += $g ** 2;
        $avColor['b'] += $b ** 2;

        $cPos = [
          (int)floor($r / (255 / $accuracy)),
          (int)floor($g / (255 / $accuracy)),
          (int)floor($b / (255 / $accuracy)),
        ];

        $spectrum[$cPos[0]][$cPos[1]][$cPos[2]]['r'] += $r;
        $spectrum[$cPos[0]][$cPos[1]][$cPos[2]]['g'] += $g;
        $spectrum[$cPos[0]][$cPos[1]][$cPos[2]]['b'] += $b;
        $spectrum[$cPos[0]][$cPos[1]][$cPos[2]]['count'] += 1;

        $pixelCount++;
        $color['brightness'] += (max($r, $g, $b) + min($r, $g, $b)) / 2;
      }
    }

    $color['brightness'] = ($color['brightness'] / (imagesx($image) * imagesy($image))) / 2.55;

    imagedestroy($image);

    // Calculate the mood color
    $color['mood'] = [
      'r' => round(sqrt($avColor['r'] / $pixelCount)),
      'g' => round(sqrt($avColor['g'] / $pixelCount)),
      'b' => round(sqrt($avColor['b'] / $pixelCount)),
    ];

    // Calculate the average of the spectrum parts
    foreach ($spectrum as $x => $xValues) {
      foreach ($xValues as $y => $yValues) {
        foreach ($yValues as $z => $zValues) {
          $size = $zValues['count'];
          if ($size > 0) {
            $color['related'][] = [
              'r' => round($zValues['r'] / $size),
              'g' => round($zValues['g'] / $size),
              'b' => round($zValues['b'] / $size),
              'size' => $size,
            ];
          }
        }
      }
    }

    unset($spectrum);

    // Sort by relevance
    ArrayManipulation::sortByNumericFieldAsc($color['related'], 'size');

    // Calculate some other colors :)
    $hsv = self::rgbToHsv($color['mood']['r'], $color['mood']['g'], $color['mood']['b']);

    $color['complementary'] = self::hsvToRgb($hsv['h'] + 180 / 360, $hsv['s'], $hsv['v']);

    $color['monochromatic'] = [
      self::hsvToRgb($hsv['h'], $hsv['s'] * 0.8, $hsv['v']),
      self::hsvToRgb($hsv['h'], $hsv['s'] * 0.6, $hsv['v']),
      self::hsvToRgb($hsv['h'], $hsv['s'] * 0.4, $hsv['v']),
      self::hsvToRgb($hsv['h'], $hsv['s'] * 0.2, $hsv['v']),
    ];

    $color['analougous'] = [
      self::hsvToRgb($hsv['h'] - 30 / 360, $hsv['s'], $hsv['v']),
      self::hsvToRgb($hsv['h'] + 30 / 360, $hsv['s'], $hsv['v']),
    ];

    $color['triadic'] = [
      self::hsvToRgb($hsv['h'] + 120 / 360, $hsv['s'], $hsv['v']),
      self::hsvToRgb($hsv['h'] + 240 / 360, $hsv['s'], $hsv['v']),
    ];

    $color['tetradic'] = [
      self::hsvToRgb($hsv['h'] + 90 / 360, $hsv['s'], $hsv['v']),
      self::hsvToRgb($hsv['h'] + 180 / 360, $hsv['s'], $hsv['v']),
      self::hsvToRgb($hsv['h'] + 270 / 360, $hsv['s'], $hsv['v']),
    ];

    return $color;
  }

  /**
   * Generate html with color values & preview
   * @param $image int image id
   * @param $color array the colors of the image
   * @return void
   */
  private function showPreview($image, $color)
  {
    if (is_user_logged_in()) {
      if ($color === false) {
        echo '<p>Farben konnten für dieses Bild nicht generiert werden...</p>';
        exit;
      }

      echo '
        <style>
          body{
            padding: 1rem;
          }
          
          .preview-container{
            display: flex;
            flex-wrap: wrap;
            justify-content: space-between;
          }
          
          .image{
            width: calc(33.3333% - 2rem);
          }
          
          .image img{
            max-width: 100%;
            max-height: 500px;
            width: auto;
            height: auto;
          }
          
          .colors-container{
            width: 66.6666%;
          }
          
          .colors{
            display: flex;
            flex-wrap: wrap;
            margin-bottom: 1rem;
          }
          
          .colors p{
            width: 100%;
          }
          
          .colors.related{
            max-height: 300px;
            overflow-y: scroll;
          }
          
          .color-preview{
            margin-bottom: 1.3333rem;
          }
          
          .color-preview.mood{
            width: 100%;
            height: 80px;
          }
          
          .color-preview.related{
            position: relative;
            width: calc(25% - 1rem);
            height: 80px;
            margin-right: 1.3333rem;
          }
          
          .color-preview.related span{
            position: absolute;
            bottom: 2px;
            left: 2px;
            font-size: 10px;
            opacity: 0.15;
          }
          
          .color-preview.related:nth-child(4n + 1){
            margin-right: 0;
          }
          
          p{
            font-family: Sans-Serif;
            padding: 5px;
            background-color: rgba(255,255,255,0.7);
            margin: 0;
          }
          
        </style>
        <div class="preview-container">
          <div class="image">
            ' . wp_get_attachment_image($image, 'medium') . '
            <p>Helligkeit: ' . number_format($color['brightness'], 2) . '%</p>
          </div>
          <div class="colors-container">
            <div class="colors mood">
              <p><b>Mood-Farbe</b></p>
              <div class="color-preview mood" style="background:rgb(' . implode(',', $color['mood']) . ')">
                <p>' . implode(',', $color['mood']) . '</p>
              </div>
            </div>
            <div class="colors related">
              <p><b>Related-Farbe</b></p>
            ';

      foreach ($color['related'] as $relCol) {
        $relevance = $relCol['size'];
        unset($relCol['size']);
        echo '<div class="color-preview related" style="background:rgb(' . implode(',', $relCol) . ')">
          <p>' . implode(',', $relCol) . '</p>
          <span>' . $relevance . '</span>
        </div>';
      }

      echo '</div><div class="colors complementary"><p><b>Komplementär</b></p><div class="color-preview related" style="background:rgb(' . implode(',', $color['complementary']) . ')">
        <p>' . implode(',', $color['complementary']) . '</p>
      </div>';

      echo '</div><div class="colors monochromatic"><p><b>Monochrom</b></p>';

      foreach ($color['monochromatic'] as $mono) {
        echo '<div class="color-preview related" style="background:rgb(' . implode(',', $mono) . ')">
          <p>' . implode(',', $mono) . '</p>
        </div>';
      }

      echo '</div><div class="colors analougous"><p><b>Angoulous</b></p>';

      foreach ($color['analougous'] as $analog) {
        echo '<div class="color-preview related" style="background:rgb(' . implode(',', $analog) . ')">
          <p>' . implode(',', $analog) . '</p>
        </div>';
      }

      echo '</div><div class="colors traidic"><p><b>Triadic</b></p>';

      foreach ($color['triadic'] as $tria) {
        echo '<div class="color-preview related" style="background:rgb(' . implode(',', $tria) . ')">
          <p>' . implode(',', $tria) . '</p>
        </div>';
      }

      echo '</div><div class="colors tetradic"><p><b>Tetradic</b></p>';

      foreach ($color['tetradic'] as $tetra) {
        echo '<div class="color-preview related" style="background:rgb(' . implode(',', $tetra) . ')">
          <p>' . implode(',', $tetra) . '</p>
        </div>';
      }

      echo '
          </div>
        </div>
      </div>';
    }
  }

  /**
   * Convert rgb values into hsv
   * @param $r float|int value between 0 and 255
   * @param $g float|int value between 0 and 255
   * @param $b float|int value between 0 and 255
   * @return array with hsv values (keys corresponding to "hsv")
   */
  public static function rgbToHsv($r, $g, $b)
  {
    $r /= 255;
    $g /= 255;
    $b /= 255;

    $min = min($r, $g, $b);
    $max = max($r, $g, $b);
    $delta = floatval($max - $min);

    $v = $max;

    if ($delta === 0.0) {
      $h = 0;
      $s = 0;
    } else {
      $s = $delta / $max;

      $deltaR = ((($max - $r) / 6) + ($delta / 2)) / $delta;
      $deltaG = ((($max - $g) / 6) + ($delta / 2)) / $delta;
      $deltaB = ((($max - $b) / 6) + ($delta / 2)) / $delta;

      if ($r == $max) $h = $deltaB - $deltaG;
      else if ($g == $max) $h = (1 / 3) + $deltaR - $deltaB;
      else if ($b == $max) $h = (2 / 3) + $deltaG - $deltaR;

      if ($h < 0) $h++;
      if ($h > 1) $h--;
    }

    return ['h' => $h, 's' => $s, 'v' => $v];
  }

  /**
   * Convert hsv to rgb values
   * @param $h float|int value between 0 and 1
   * @param $s float|int value between 0 and 1
   * @param $v float|int value between 0 and 1
   * @return float[]|int[]
   */
  public static function hsvToRgb($h, $s, $v)
  {
    if ($s === 0) {
      $v *= 255;
      return ['r' => $v, 'g' => $v, 'b' => $v];
    }

    $h = abs($h);
    $h = ($h > 1 ? $h - floor($h) : $h) * 360;
    $c = $v * $s;
    $tempH = $h / 60;
    while ($tempH >= 2.0) $tempH -= 2.0; // In PHP modulo doesn't work with floats
    $x = $c * (1 - abs($tempH - 1));
    $m = $v - $c;

    if ($h >= 0 && $h < 60) $rgb = [$c, $x, 0];
    elseif ($h >= 60 && $h < 120) $rgb = [$x, $c, 0];
    elseif ($h >= 120 && $h < 180) $rgb = [0, $c, $x];
    elseif ($h >= 180 && $h < 240) $rgb = [0, $x, $c];
    elseif ($h >= 240 && $h < 300) $rgb = [$x, 0, $c];
    elseif ($h >= 300 && $h < 360) $rgb = [$c, 0, $x];


    return ['r' => ($rgb[0] + $m) * 255, 'g' => ($rgb[1] + $m) * 255, 'b' => ($rgb[2] + $m) * 255];
  }
}

?>