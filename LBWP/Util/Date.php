<?php

namespace LBWP\Util;

/**
 * Klasse die verschiedene Datumsoperationen zur Verfügung stellt.
 * Dazu gehören Check und EU / SQL Konvertierungsfunktionen sowie
 * Konstanten für Formatierungen und Reguläre ausdrücke
 * @author Michael Sebel <michael@comotive.ch>
 */
class Date
{
	/**
	 * Formattyp SQL Datetime
	 * @var string
	 */
	const SQL_DATETIME 	= 'Y-m-d H:i:s';
	/**
	 * Formattyp SQL Date
	 * @var string
	 */
	const SQL_DATE  	= 'Y-m-d';
	/**
	 * Formattyp EU Datetime
	 * @var string
	 */
	const EU_DATETIME 	= 'd.m.Y H:i:s';
	/**
	 * Formattyp EU Date
	 * @var string
	 */
	const EU_DATE		= 'd.m.Y';
	/**
	 * Formattyp EU Zeit (inkl. Sekunden)
	 * @var string
	 */
	const EU_TIME		= 'H:i:s';
	/**
	 * Formattyp EU Weckeranzeige (exkl. Sekunden)
	 * @var string
	 */
	const EU_CLOCK		= 'H:i';
	/**
	 * Formattyp für Verwendung als ICS Kalender Datei
	 * @var string
	 */
	const ICS_DATE		= 'Ymd\THis';
	/**
	 * Formattyp für ein korrektes Datum nach RFC 822
	 * @var string
	 */
	const RFC822_DATE		= 'D, d M Y H:i:s e';
	/**
	 * Regulärer Ausdruck für Checks auf Datum (dd.mm.yyyy)
	 * @var string
	 */
	const EU_FORMAT_DATE		= '/^(([012][0-9])|(3[01])).((0[1-9])|(1[0-2])).(((19[0-9])|(20[0-9]))[0-9])$/';
	/**
	 * Regulärer Ausdruck für Checks auf Zeit (hh:mm:ss)
	 * @var string
	 */
	const EU_FORMAT_TIME		= '/^(([0-1]{1}[0-9]{1}|[2]{1}[0-3]{1})[:]{1}[0-5]{1}[0-9]{1}[:]{1}[0-5]{1}[0-9]{1})$/';
  /**
	 * Regulärer Ausdruck für Checks auf Zeit (hh:mm:ss)
	 * @var string
	 */
	const EU_FORMAT_CLOCK		= '/^(([0-1]{1}[0-9]{1}|[2]{1}[0-3]{1})[:]{1}[0-5]{1}[0-9]{1})$/';
	/**
	 * Regulärer Ausdruck für Checks auf Datum (dd.mm.yyyy hh:mm:ss)
	 * @var string
	 */
	const EU_FORMAT_DATETIME	= '/^(([012][0-9])|(3[01])).((0[1-9])|(1[0-2])).(((19[0-9])|(20[0-9]))[0-9]) (([0-1]{1}[0-9]{1}|[2]{1}[0-3]{1})[:]{1}[0-5]{1}[0-9]{1}[:]{1}[0-5]{1}[0-9]{1})$/';
	/**
	 * Regulärer Ausdruck für Checks auf SQL Datum (dd-mm-yyyy)
	 * @var string
	 */
	const SQL_FORMAT_DATE		= '/^(((19[0-9])|(20[0-9]))[0-9])-((0[1-9])|(1[0-2]))-(([012][0-9])|(3[01]))$/';
	/**
	 * Regulärer Ausdruck für Checks auf SQL Zeit (hh:mm:ss)
	 * @var string
	 */
	const SQL_FORMAT_TIME		= '/^(([0-1]{1}[0-9]{1}|[2]{1}[0-3]{1})[:]{1}[0-5]{1}[0-9]{1}[:]{1}[0-5]{1}[0-9]{1})$/';
	/**
	 * Regulärer Ausdruck für Checks auf SQL Datum (dd-mm-yyyy hh:mm:ss)
	 * @var string
	 */
	const SQL_FORMAT_DATETIME 	= '/^(((19[0-9])|(20[0-9]))[0-9])-((0[1-9])|(1[0-2]))-(([012][0-9])|(3[01])) (([0-1]{1}[0-9]{1}|[2]{1}[0-3]{1})[:]{1}[0-5]{1}[0-9]{1}[:]{1}[0-5]{1}[0-9]{1})$/';
  /**
   * @var int user in microtime debug functions
   */
	protected static $timer = 0;
	
	/**
	 * Validiert Zeit eingaben.
	 * Nimmt einen Wert und gibt Ihn zurück. Es kommt die aktuelle
	 * Zeit, wenn ein ungültiger Wert (NULL) daher kommt.
	 * @param integer vTime, eingegebene Zeit
	 * @return integer Aktuelle Zeit, wenn gegebene ungültig
	 */
	private static function setTime($vTime)
  {
		$nTime = time();
		// Gegebenen Stamp nutzen wenn vorhanden
		if ($vTime != NULL) $nTime = $vTime;
		return($nTime);
	}
	
	/**
	 * Formatiert einen Timestamp oder aktuelle Zeit anhand $sFormat
	 * @param string sFormat, Formatierung für date Funktion
	 * @param integer vTime, Timestamp darf auch NULL sein für aktuelle Zeit
	 * @return string Formatiertes Datum
	 */
	public static function getTime($sFormat,$vTime = NULL)
  {
		$nTime = self::setTime($vTime);
		$sDate = date($sFormat,$nTime);
		return($sDate);
	}
	
	/**
	 * Formatiert das Datum $sDate von $sFrom nach $sTo.
	 * @param string sFrom, Eingangsformat
	 * @param string sTo, Ausgangsformat
	 * @param string sDate, zu konvertierendes Datum im Eingangsformat
	 * @return string Konvertiertes Datum im Ausgangsformat
	 */
	public static function convertDate($sFrom,$sTo,$sDate)
  {
		// Mit From einen Stempfel holen
		$nStamp = self::getStamp($sFrom,$sDate);
		// Stempfel in To konvertieren
		$sNewDate = self::getTime($sTo,$nStamp);
		return($sNewDate);
	}
	
	/**
	 * Gibt anhand der Formatierung den Timestamp eines Datums zurück
	 * @param string sFormat, Formatierung des eingegebenen Datum
	 * @param string sDate, Zu konvertierendes Datum
	 * @return integer Timestamp des gegebenen Datums
	 */
	public static function getStamp($sFormat,$sDate)
  {
		$nStamp = 0;
		switch ($sFormat) {
			// SQL Datumsstring yyyy-mm-dd
			case (self::SQL_DATE):
				// Datumseinheiten extrahieren
				$nYear = intval(substr($sDate,0,4));
				$nMonth = intval(substr($sDate,5,2));
				$nDay = intval(substr($sDate,8,2));
				// Stamp generieren
				$nStamp = mktime(0,0,0,$nMonth,$nDay,$nYear);
				break;
			// SQL Datum/Zeit String yyyy-mm-dd hh:mm:ss
			case (self::SQL_DATETIME):
				// Datumseinheiten extrahieren
				$nYear = intval(substr($sDate,0,4));
				$nMonth = intval(substr($sDate,5,2));
				$nDay = intval(substr($sDate,8,2));
				$nHour = intval(substr($sDate,11,2));
				$nMinute = intval(substr($sDate,14,2));
				$nSecond = intval(substr($sDate,17,2));
				// Stamp generieren
				$nStamp = mktime($nHour,$nMinute,$nSecond,$nMonth,$nDay,$nYear);
				break;
			// Europäischer Datumsstring dd.mm.yyyy
			case (self::EU_DATE):
				$nDay = intval(substr($sDate,0,2));
				$nMonth = intval(substr($sDate,3,2));
				$nYear = intval(substr($sDate,6,4));
				// Stamp generieren
				$nStamp = mktime(0,0,0,$nMonth,$nDay,$nYear);
				break;
			// Europäisches Datum/Zeit dd.mm.yyyy, hh:mm:ss
			case (self::EU_DATETIME):
				$nDay = intval(substr($sDate,0,2));
				$nMonth = intval(substr($sDate,3,2));
				$nYear = intval(substr($sDate,6,4));
				$nHour = intval(substr($sDate,11,2));
				$nMinute = intval(substr($sDate,14,2));
				$nSecond = intval(substr($sDate,17,2));
				// Stamp generieren
				$nStamp = mktime($nHour,$nMinute,$nSecond,$nMonth,$nDay,$nYear);
				break;
		}
		// Resultat zurückgeben
		return($nStamp);
	}
	
	/**
	 * Tag des Jahres als Zahl, nicht nullbasiert.
	 * @param integer nStamp, Zeitstempfel dessen Tag herausgefunden werden muss
	 * @return integer Tag des Jahres 1 - 365 (366)
	 */
	public static function getDayOfYear($nStamp)
  {
		$nStamp = intval($nStamp);
		$nRet = (date("z",$nStamp))+1;
		return($nRet);
	}
	
	/**
	 * Tag der Woche als Zahl, nicht nullbasiert.
	 * @param integer nStamp, Zeitstempfel dessen Tag herausgefunden werden muss
	 * @return integer Tag der Woche, 1 = Montag, 7 = Sonntag
	 */
	public static function getDayOfWeekNumeric($nStamp)
  {
		$nStamp = intval($nStamp);
		$nRet = date("w",$nStamp);
		if ($nRet == 0) $nRet = 7;
		return($nRet);
	}

  /**
   * @param string $language the iso language code, not used yet
   * @param array $options additional options
   * @return string json string representing json datepicker config
   */
  public static function getDatePickerJson($language = 'de', $options = array())
  {
    $config = array(
      'dateFormat' => 'dd.mm.yy',
      'dayNames' => array('Sonntag', 'Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag'),
      'dayNamesMin' => array('So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa'),
      'dayNamesShort' => array('So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa'),
      'monthNames' => array('Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'),
      'monthNamesShort' => array('Jan', 'Feb', 'Mär', 'Apr', 'Mai', 'Jun', 'Jul', 'Aug', 'Sep', 'Okt', 'Nov', 'Dez'),
      'firstDay' => 1
    );

    // Merge, if needed
    if (count($options) > 0) {
      $config = array_merge($config, $options);
    }

    return json_encode($config);
  }

  /**
   * @param $string
   * @return string|string[]
   */
  public static function translateMonthString($string)
  {
    $string = strtolower($string);
    $string = str_replace('januar', 'jan', $string);
    $string = str_replace('februar', 'feb', $string);
    $string = str_replace('märz', 'mar', $string);
    $string = str_replace('april', 'apr', $string);
    $string = str_replace('mai', 'may', $string);
    $string = str_replace('juni', 'jun', $string);
    $string = str_replace('juli', 'jul', $string);
    $string = str_replace('august', 'aug', $string);
    $string = str_replace('september', 'sep', $string);
    $string = str_replace('oktober', 'oct', $string);
    $string = str_replace('november', 'nov', $string);
    $string = str_replace('dezember', 'dec', $string);
    return $string;
  }

	/**
	 * Verwandelt ein SQL Date in eine Human Readable Date
	 * in der Form "13.03.2010 / 10:00 Uhr"
	 * @param string $sSqlDate SQL Datetime String
	 * @return string Human Readable Datum/Zeit
	 */
	public static function toHumanReadable($sSqlDate)
  {
		$sDate = Date::convertDate(
			self::SQL_DATETIME,
			self::EU_DATE,
			$sSqlDate
		);
		$sTime = Date::convertDate(
			self::SQL_DATETIME,
			self::EU_TIME,
			$sSqlDate
		);
		$sClock = __('Uhr', 'lbwp');
		// So zusammenführen
		return($sDate.' / '.$sTime.' '.$sClock);
	}

  /**
   * @param int $days number of days to get
   * @param string $format format of the dates in result array
   * @return array the result array of work days
   */
  public static function getNextWorkDays($days, $format)
  {
    $todayStamp = Date::getStamp(Date::SQL_DATE, current_time('mysql'));
    $nextDays = array();
    for ($i = 1; $i < ($days * 2) + 2; $i++) {
      $nextDays[] = $todayStamp + ($i * 86400);
    }

    $workDays = array();
    foreach ($nextDays as $dayStamp) {
      // Add to array if it's a workdays
      if (date('N', $dayStamp) <= 5) {
        $workDays[] = $dayStamp;
      }
      // Break loop, if needed dates are reached
      if (count($workDays) >= $days) {
        break;
      }
    }

    // Reformat the output
    $formattedDays = array();
    foreach($workDays as $dayStamp) {
      $formattedDays[] = date($format, $dayStamp);
    }

    return $formattedDays;
  }

  /**
   * @param string $url remote url to get the publish date
   * @return int a timestamp or false, if not able to retrieve url
   */
  public static function getRemoteUrlPublishDate($url)
  {
    $ts = false;
    // Get remote HTML
    $html = Strings::genericRequest($url, array(), 'GET');
    // Try with json+ld first
    Strings::replaceByXPath($html, '//script', function($doc, $tag, $fragment) use(&$ts) {
      if ($tag->attributes->getNamedItem('type')->value == 'application/ld+json') {
        $json = json_decode($tag->textContent, true);
        if ($json['@type'] == 'NewsArticle') {
          $ts = strtotime($json['datePublished']);
        }
      }
    });

    // now try with various meta if no date was found
    if ($ts === false) {
      Strings::replaceByXPath($html, '//meta', function($doc, $tag, $fragment) use(&$ts) {
        if ($ts === false) {
          if (
            $tag->attributes->getNamedItem('name')->value == 'date' ||
            $tag->attributes->getNamedItem('property')->value == 'og:updated_time' ||
            $tag->attributes->getNamedItem('property')->value == 'article:published_time'
          ) {
            $ts = strtotime($tag->attributes->getNamedItem('content')->value);
          }
        }
      });
    }

    // Now try with very specific cases
    if ($ts === false) {
      Strings::replaceByXPath($html, '//time', function($doc, $tag, $fragment) use(&$ts) {
        if ($tag->attributes->getNamedItem('datetime')->value != '') {
          $ts = strtotime($tag->attributes->getNamedItem('datetime')->value);
        }
      });
    }

    if ($ts === false) {
      Strings::replaceByXPath($html, '//span', function($doc, $tag, $fragment) use(&$ts) {
        if ($tag->attributes->getNamedItem('class')->value == 'timestamp' || $tag->attributes->getNamedItem('class')->value == 'date') {
          $ts = strtotime(html_entity_decode(strip_tags($tag->nodeValue)));
        }
      });
    }

    if ($ts === false) {
      Strings::replaceByXPath($html, '//div', function($doc, $tag, $fragment) use(&$ts) {
        if (
          $tag->attributes->getNamedItem('class')->value == 'timestamp' ||
          $tag->attributes->getNamedItem('class')->value == 'tags node-location' ||
          $tag->attributes->getNamedItem('class')->value == 'news_date' ||
          $tag->attributes->getNamedItem('class')->value == 'post-prev-date' ||
          $tag->attributes->getNamedItem('class')->value == 'date') {
          $ts = strtotime(html_entity_decode(strip_tags($tag->nodeValue)));
        }
      });
    }

    return $ts;
  }

  /**
   * Timer debug function
   */
  public static function timer()
  {
    if (self::$timer === 0) {
      self::$timer = microtime(true);
      echo 'starting at: ' . self::$timer . PHP_EOL;
    } else {
      $now = microtime(true);
      $diff = $now - self::$timer;
      echo 'elapsed: ' . $diff . PHP_EOL;
      self::$timer = $now;
    }
  }
}