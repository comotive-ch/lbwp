<?php

namespace LBWP\Helper;

use LBWP\Helper\Import\Csv;

/**
 * Lets a developer get distance between swiss postcodes
 * @package LBWP\Helper
 * @author Michael Sebel <michael@comotive.ch>
 */
class ZipDistance
{
  protected static $data = array();

  protected static $zipCantonMap = array();

  /**
   * @param $lat
   * @param $lng
   * @param int $count
   * @return array
   */
  public static function getNearestByLatLng($lat, $lng, $count = 0)
  {
    if (count(self::$data) == 0) {
      self::loadZipData();
    }

    $list = array();
    foreach (self::$data as $code => $to) {
      $list[$code] = self::calculateDistance(
        $lat,
        $lng,
        $to['lat'],
        $to['lng']
      );
    }

    // Sort by distance maintaining the keys, also remove itself as a result that is always zero
    asort($list, SORT_NUMERIC);
    // Cut the array right there if needed
    if ($count > 0) {
      $list = array_slice($list, 0, $count, true);
    }

    return $list;
  }
  /**
   * List of nearest zips to the given zip
   * @param string $zip the zip code
   * @param int $count number of results
   * @return array list of nearest postcodes
   */
  public static function getNearest($zip, $count = 0)
  {
    if (count(self::$data) == 0) {
      self::loadZipData();
    }
    // Empty list, if the given zip code doesn't exist
    if (!isset(self::$data[$zip])) {
      return array();
    }

    $from = self::$data[$zip];
    $list = self::getNearestByLatLng($from['lat'], $from['lng'], $count);
    unset($list[$zip]);
    return $list;
  }

  /**
   * @param string $from from zipcode A
   * @param string $to to zipcode B
   * @return int distance in meters between the zip codes
   */
  public static function getDistance($from, $to)
  {
    if (count(self::$data) == 0) {
      self::loadZipData();
    }

    return self::calculateDistance(
      self::$data[$from]['lat'],
      self::$data[$from]['lng'],
      self::$data[$to]['lat'],
      self::$data[$to]['lng']
    );
  }

  /**
   * @param $latFrom
   * @param $lngFrom
   * @param $latTo
   * @param $lngTo
   * @param int $earthRadius
   * @return float|int
   */
  public static function calculateDistance($latFrom, $lonFrom, $latTo, $lonTo, $earthRadius = 6371000)
  {
    // convert from degrees to radians
    $latFrom = deg2rad($latFrom);
    $lonFrom = deg2rad($lonFrom);
    $latTo = deg2rad($latTo);
    $lonTo = deg2rad($lonTo);

    $latDelta = $latTo - $latFrom;
    $lonDelta = $lonTo - $lonFrom;

    $angle = 2 * asin(sqrt(pow(sin($latDelta / 2), 2) +
        cos($latFrom) * cos($latTo) * pow(sin($lonDelta / 2), 2)));
    return $angle * $earthRadius;
  }

  /**
   * Get a list to further usage
   * @param string $field
   * @return array
   */
  public static function getZipList($field = 'city')
  {
    if (count(self::$data) == 0) {
      self::loadZipData();
    }
    $list = array();
    foreach (self::$data as $zip => $entry) {
      $list[$zip] = $entry[$field];
    }

    return $list;
  }

  /**
   * @return void
   */
  public static function getZipCantonMap()
  {
    if (count(self::$zipCantonMap) > 0) {
      return self::$zipCantonMap;
    }

    $file = ABSPATH . '/wp-content/plugins/lbwp/resources/libraries/ch-latlng/zip-canton-map.csv';
    $raw = Csv::getArray($file, ',', '"', true, true);

    foreach ($raw as $city) {
      self::$zipCantonMap[intval($city[0])] = $city[1];
    }

    return self::$zipCantonMap;
  }

  /**
   * Returns an assotiative array canton => zip
   * @return array
   */
  public static function getCantonZipMap() : array{
    $zipMap = self::getZipCantonMap();
    $cantonMap = array();

    foreach($zipMap as $zip => $canton){
      if(!is_array($cantonMap[$canton])){
        $cantonMap[$canton] = array();
      }

      $cantonMap[$canton][] = $zip;
    }

    return $cantonMap;
  }

  /**
   * @return mixed
   */
  public static function getCantonList()
  {
    $map = self::getZipCantonMap();
    $cantons = array();
    foreach ($map as $zip => $canton) {
      $cantons[$canton] = true;
    }
    return array_keys($cantons);
  }

  /**
   * Loads the needed data
   */
  protected static function loadZipData()
  {
    $file = ABSPATH . '/wp-content/plugins/lbwp/resources/libraries/ch-latlng/ch-latlng.json';
    self::$data = json_decode(file_get_contents($file), true);
  }
}