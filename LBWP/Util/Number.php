<?php

namespace LBWP\Util;

/**
 * Number utility functions
 * @package LBWP\Util
 * @author Michael Sebel <michael@comotive.ch>
 */
class Number
{
  /**
   * @param int $number
   * @param int $factor
   * @return int rounded up to factor number
   */
  public static function roundUpTo($number, $factor)
  {
    return round(($number + $factor / 2) / $factor) * $factor;
  }

  /**
   * @param int $number
   * @param int $factor
   * @return int rounded to nearest factor number
   */
  public static function roundNearest($number, $factor)
  {
    return (round($number) % $factor === 0) ? round($number) : round($number / $factor) * $factor;
  }
}