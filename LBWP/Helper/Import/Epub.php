<?php

namespace LBWP\Helper\Import;

use LBWP\Core;
use LBWP\Module\Backend\S3Upload;
use LBWP\Util\File;
use LBWP\Util\Strings;

/**
 * Wrapper to unzip and work with epub files
 * @package LBWP\Helper\Import
 * @author Michael Sebel <michael@comotive.ch>
 */
class Epub {
  /**
   * @var array the images
   */
  protected static $images = array();

  /**
   * @param $file
   * @param $folder
   * @return bool
   */
  public static function extract($file, $folder)
  {
    // Extract the zip in into that folder
    $zip = new \ZipArchive();
    $resource = $zip->open($file);
    if ($resource === true) {
      $zip->extractTo($folder);
      $zip->close();
      unlink($file);
      return true;
    }

    return false;
  }

  /**
   * @param $path
   */
  public static function sideLoadImages($path)
  {
    $files = scandir($path . 'image');
    /** @var S3Upload $s3 */
    $s3 = Core::getModule('S3Upload');
    // See if there are files, then upload to block storage
    foreach ($files as $file) {
      $fullPath = $path . 'image/' . $file;
      if (file_exists($fullPath) && is_file($fullPath)) {
        $ext = Strings::forceSlugString(File::getExtension($file));
        $url = $s3->uploadDiskFile($fullPath, 'image/' . $ext, false);
        self::$images['image/' . $file] = $url;
      }
    }
  }

  /**
   * @param $path
   * @return array $data
   */
  public static function read($path)
  {
    $data = array();
    $toc = simplexml_load_file($path . 'toc.ncx');

    foreach ($toc->navMap->navPoint as $navPoint) {
      // See if the content even exists
      $label = (string) $navPoint->navLabel->text;
      $content = (string) $navPoint->content->attributes()->src;
      // Remove hash if given (as it throws file not exists)
      if (Strings::contains($content, '#')) {
        $content = substr($content, 0, strpos($content, '#'));
      }
      $sectionFile = $path . $content;

      // Only continue, if the content for this main section exists
      if (file_exists($sectionFile)) {
        $data[$content] = array(
          'content' => self::getSection($sectionFile),
          'label' => $label,
          'sections' => array()
        );

        // See if there are more navpoints / subsections
        if (count($navPoint->navPoint) > 0) {
          foreach ($navPoint->navPoint as $subNav) {
            $subSource = (string) $subNav->content->attributes()->src;
            $subSource = str_replace($content, '', $subSource);
            $subLabel = (string) $subNav->navLabel->text;
            $data[$content]['sections'][$subSource] = $subLabel;
          }
        }
      }
    }

    return $data;
  }

  /**
   * @param string $path
   * @return array of tags in this section
   */
  public static function getSection($path)
  {
    $html = file_get_contents($path);
    $html = self::replaceImages($html);
    $parser = new \DOMDocument();
    $parser->loadHTML($html);
    // Get everything in body into an array
    $lines = array();
    foreach ($parser->getElementsByTagName('body')[0]->childNodes as $node) {
      $line = trim($node->ownerDocument->saveHTML($node));
      if (strlen($line) > 0) $lines[] = $line;
    }

    return $lines;
  }

  /**
   * @param string $html
   * @return string
   */
  public static function replaceImages($html)
  {
    foreach (self::$images as $search => $replace) {
      $html = str_replace($search, $replace, $html);
    }

    return $html;
  }

  /**
   * @param string $path to one of the meta files (most likely content.opf)
   * @return array of various meta informations
   */
  public static function getMeta($path)
  {
    $meta = array();
    $parser = new \DOMDocument();
    $parser->loadXml(file_get_contents($path));
    foreach ($parser->getElementsByTagName('metadata')[0]->childNodes as $node) {
      if (Strings::startsWith($node->nodeName, 'dc:')) {
        $id = str_replace('dc:', '', $node->nodeName);
        $meta[$id] = $node->textContent;
      }
    }

    return $meta;
  }

  /**
   * @param array $lines of html docs
   * @param string $tag
   * @return array grouped by content from tag to tag (or end)
   */
  public static function groupByTag($lines, $tag)
  {
    $autoIndex = 0;
    $groups = array();
    foreach ($lines as $key => $line) {
      if (Strings::startsWith($line, '<' . $tag)) {
        $id = Strings::parseTagProperty($line, 'id');
        // If the tag has no id to use as group, add one by ourselfs and alter the lines tag
        if (strlen($id) == 0) {
          $id = $autoIndex++;
          $line = str_replace('<' . $tag, '<' . $tag .' id="' . $id . '"', $line);
        }
      }

      // Add the line to the current $id group (changes only when the given tag is a new section
      $groups[$id][] = $line;
    }

    return $groups;
  }
} 