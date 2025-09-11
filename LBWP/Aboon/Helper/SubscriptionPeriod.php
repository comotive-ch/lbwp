<?php


namespace LBWP\Aboon\Helper;

/**
 * Has some functions to calculate or display names fo subscription periods
 * @package LBWP\Aboon\Helper
 * @author Michael Sebel <michael@comotive.ch>
 */
class SubscriptionPeriod
{
  /**
   * @param int $start
   * @param int $number
   * @param string $period
   */
  public static function getEndTimestamp($start, $number, $period)
  {
    // Create a date from the start and add our interval
    $date = new \DateTime();
    $date->setTimestamp($start);
    $date->add(\DateInterval::createFromDateString($number . ' ' . $period . 's'));
    return $date->getTimestamp();
  }

  /**
   * @param int $number any number supported
   * @param string $period month or year supported
   * @return string readable duration of period
   */
  public static function getPeriodNameString($number, $period)
  {
    // Maybe transform month into years if possible
    self::transformToYears($number, $period);
    // Return whatever makes sense
    switch ($period) {
      case 'day':
        return sprintf(_n('%s Tag', '%s Tage', $number, 'lbwp'), $number);
      case 'week':
        return sprintf(_n('%s Woche', '%s Wochen', $number, 'lbwp'), $number);
      case 'month':
        return sprintf(_n('%s Monat', '%s Monate', $number, 'lbwp'), $number);
      case 'year':
        return sprintf(_n('%s Jahr', '%s Jahre', $number, 'lbwp'), $number);
    }
  }

  /**
   * @param string $period any existing
   * @return string readable duration of period
   */
  public static function getPeriodNameSingle($period)
  {
    // Return whatever makes sense
    switch ($period) {
      case 'day':
        return __('pro Tag', 'lbwp');
      case 'week':
        return __('pro Woche', 'lbwp');
      case 'month':
        return __('pro Monat', 'lbwp');
      case 'year':
        return __('pro Jahr', 'lbwp');
    }
  }

  /**
   * @param \DateInterval $diff
   * @param string $period one of the valid periods
   * @param int the number of period cycles in that diff
   */
  public static function getCyclesByDiff($diff, $period)
  {
    switch ($period) {
      case 'day':
        return $diff->days;
      case 'week':
        return floor($diff->days / 7);
      case 'month':
        return ($diff->y * 12) + $diff->m;
      case 'year':
        return ($diff->y > 0) ? $diff->y : 1;
    }

    return false;
  }

  /**
   * Allows to provide number of months and changes it to exact years if possible
   * @param int $number any number supported
   * @param string $period only year or month supported
   */
  public static function transformToYears(&$number, &$period)
  {
    // Do nothing if year is given
    if ($period == 'year') {
      return;
    }

    // Maybe change if month is given
    if ($period == 'month' && $number % 12 == 0) {
      $period = 'year';
      $number /= 12;
    }
  }
}