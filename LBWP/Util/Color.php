<?php

namespace LBWP\Util;

/**
 * Helper for color generation in php
 * @package LBWP\Util
 * @author Michael Sebel <michael@comotive.ch>
 */
class Color
{
  /**
   * @param string $hex a hex code color
   * @param $by by how much percent it should be darkened
   */
  public static function darken($hex, $by)
  {
    list($r, $g, $b) = sscanf($hex, "#%02x%02x%02x");
    $hsl = self::rgbToHsl($r, $g, $b);
    // Take the index 2 (L) of the color and darken it
    $percentValue =  ($hsl[2] / 100) * $by;
    $hsl[2] -= $percentValue;
    // Get it back to rgb
    $rbg = self::hslToRgb($hsl[0], $hsl[1], $hsl[2]);
    // Convert that back into hex
    return sprintf("#%02x%02x%02x", $rbg[0], $rbg[1], $rbg[2]);
  }

  /**
   * @param string $hex a hex code color
   * @param $by by how much percent it should be darkened
   */
  public static function lighten($hex, $by)
  {
    list($r, $g, $b) = sscanf($hex, "#%02x%02x%02x");
    $hsl = self::rgbToHsl($r, $g, $b);
    // Take the index 2 (L) of the color and darken it
    $percentValue =  ($hsl[2] / 100) * $by;
    $hsl[2] += $percentValue;
    // Get it back to rgb
    $rbg = self::hslToRgb($hsl[0], $hsl[1], $hsl[2]);
    // Convert that back into hex
    return sprintf("#%02x%02x%02x", $rbg[0], $rbg[1], $rbg[2]);
  }

  /**
   * @param $r
   * @param $g
   * @param $b
   * @return array
   */
  public static function rgbToHsl($r, $g, $b)
  {
    $oldR = $r;
    $oldG = $g;
    $oldB = $b;

    $r /= 255;
    $g /= 255;
    $b /= 255;

    $max = max($r, $g, $b);
    $min = min($r, $g, $b);

    $h;
    $s;
    $l = ($max + $min) / 2;
    $d = $max - $min;

    if ($d == 0) {
      $h = $s = 0; // achromatic
    } else {
      $s = $d / (1 - abs(2 * $l - 1));

      switch ($max) {
        case $r:
          $h = 60 * fmod((($g - $b) / $d), 6);
          if ($b > $g) {
            $h += 360;
          }
          break;

        case $g:
          $h = 60 * (($b - $r) / $d + 2);
          break;

        case $b:
          $h = 60 * (($r - $g) / $d + 4);
          break;
      }
    }

    return array(round($h, 2), round($s, 2), round($l, 2));
  }

  /**
   * @param $h
   * @param $s
   * @param $l
   * @return array
   */
  public static function hslToRgb($h, $s, $l)
  {
    $r;
    $g;
    $b;

    $c = (1 - abs(2 * $l - 1)) * $s;
    $x = $c * (1 - abs(fmod(($h / 60), 2) - 1));
    $m = $l - ($c / 2);

    if ($h < 60) {
      $r = $c;
      $g = $x;
      $b = 0;
    } else if ($h < 120) {
      $r = $x;
      $g = $c;
      $b = 0;
    } else if ($h < 180) {
      $r = 0;
      $g = $c;
      $b = $x;
    } else if ($h < 240) {
      $r = 0;
      $g = $x;
      $b = $c;
    } else if ($h < 300) {
      $r = $x;
      $g = 0;
      $b = $c;
    } else {
      $r = $c;
      $g = 0;
      $b = $x;
    }

    $r = ($r + $m) * 255;
    $g = ($g + $m) * 255;
    $b = ($b + $m) * 255;

    return array(floor($r), floor($g), floor($b));
  }
}