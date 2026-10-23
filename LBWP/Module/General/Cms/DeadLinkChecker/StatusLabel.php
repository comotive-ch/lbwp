<?php

namespace LBWP\Module\General\Cms\DeadLinkChecker;

/**
 * Human readable labels for link check results
 * @package LBWP\Module\General\Cms\DeadLinkChecker
 * @author Mirko Baffa <mirko@comotive.ch>
 */
class StatusLabel
{
  /**
   * @var array labels for the http codes, others are shown as plain code
   */
  const CODE_LABELS = [
    200 => 'OK',
    400 => 'Ungültige Anfrage',
    401 => 'Passwort benötigt',
    402 => 'Payment Required',
    403 => 'Zugriff blockiert',
    404 => 'Nicht gefunden',
    405 => 'Method Not Allowed',
    406 => 'Not Acceptable',
    407 => 'Proxy Authentication Required',
    408 => 'Request Timeout',
    410 => 'Gelöscht',
    429 => 'Zu viele Anfragen',
    451 => 'Aus rechtlichen Gründen gesperrt',
    500 => 'Server Fehler',
    501 => 'Not Implemented',
    502 => 'Server Fehler',
    503 => 'Server nicht verfügbar',
    504 => 'Server Timeout',
    999 => 'Bot blockiert',
  ];

  /**
   * Get the label of a link row
   *
   * @param array $row link row with code and error
   * @return string label like "404 Nicht gefunden" or the connection error
   */
  public static function get(array $row)
  {
    $code = intval($row['code']);
    if ($code === 0) {
      return strlen($row['error']) > 0 ? 'Nicht erreichbar: ' . $row['error'] : 'Nicht erreichbar';
    }

    return trim($code . ' ' . (self::CODE_LABELS[$code] ?? ''));
  }
}
