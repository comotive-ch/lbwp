<?php

namespace LBWP\Util;

/**
 * Methoden um Strings zu validieren/verarbeiten.
 * @author Michael Sebel <michael@comotive.ch>
 */
class Strings
{
  /**
   * Regulärer Ausdruck um viele E-Mail Adressen zu validieren.
   * @var string
   */
  const REGEX_EMAIL = '/^[A-Za-z0-9\._\-+]+@[A-Za-z0-9_\-+]+(\.[A-Za-z0-9_\-+]+)+$/';
  /**
   * Regulärer Ausdruck der nur a-z und 0-9 erlaubt.
   * Je nach Anwendung werden A-Z zu a-z konvertiert.
   * @var string
   */
  const ALPHA_NUMERIC = '/[^a-z0-9]/i';
  /**
   * Erlaubt telefon zeichen + und alle Zahlen
   * @var string
   */
  const PHONE_CHARACTERS = '/[^0-9\+]/i';
  /**
   * Regulärer Ausdruck der nur a-z und 0-9 erlaubt.
   * Je nach Anwendung werden A-Z zu a-z konvertiert.
   * Weiterhin sind - und _ erlaubt (Für Files gedacht).
   * @var string
   */
  const ALPHA_FILES = '/[^a-z0-9\-\_]/i';
  /**
   * Regulärer Ausdruck der nur a-z und 0-9 erlaubt.
   * Je nach Anwendung werden A-Z zu a-z konvertiert.
   * Weiterhin sind - und _ erlaubt (Für Files gedacht).
   * @var string
   */
  const ALPHA_PATH = '/[^a-z0-9\-\_\/]/i';
  /**
   * Regulärer Ausdruck der nur a-z und 0-9 erlaubt.
   * Je nach Anwendung werden A-Z zu a-z konvertiert.
   * Weiterhin sind - und _ erlaubt (Für Files gedacht).
   * @var string
   */
  const ALPHA_SLUGS = '/[^a-z0-9\-]/i';
  /**
   * Regulärer Ausdruck der nur a-z und 0-9 erlaubt.
   * Je nach Anwendung werden A-Z zu a-z konvertiert.
   * Weiterhin sind - und _ erlaubt (Für Files gedacht).
   * @var string
   */
  const ALPHA_SLUGS_DOTS = '/[^a-z0-9\-\.]/i';
  /**
   * Regulärer Ausdruck der a-z,A-Z und 0-9 erlaubt.
   * Weiterhin sind - und _ erlaubt (Für Files gedacht).
   * @var string
   */
  const ALPHA_NUMERIC_LOW = '/[^A-Za-z0-9\-\_]/i';
  /**
   * Regulärer Ausdruck der a-z,A-Z und 0-9 erlaubt.
   * Weiterhin sind - und _ erlaubt (Für Files gedacht).
   * @var string
   */
  const ALPHA_NUMERIC_LOW_FILES = '/[^A-Za-z0-9\-\_\.\/]/i';
  /**
   * @var string a html quote
   */
  const HTML_QUOTE = '&quot;';
  /**
   * Array containing the alphabet
   */
  const ALPHABET = array('a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 'j', 'k', 'l', 'm', 'n', 'o', 'p', 'q', 'r', 's', 't', 'u', 'v', 'w', 'x', 'y', 'z');
  /**
   * Used for strip_tags mainly
   */
  const TINYMCE_DEFAULT_TAGS = '<h1><h2><h3><h4><h5><strong><em><a><p><blockquote><img><ul><ol><li><del><ins><br>';

  /**
   * Most common words in some languages
   */
  const MOST_COMMON_WORDS = array(
    'de' => array('der', 'die', 'und', 'in', 'den', 'von', 'zu', 'das', 'mit', 'sich', 'des', 'auf', 'für', 'ist', 'im', 'dem', 'nicht', 'ein', 'eine'),
    'en' => array('the', 'be', 'to', 'of', 'and', 'a', 'in', 'that', 'have', 'I', 'it', 'for', 'not', 'on', 'with', 'he', 'as', 'you', 'do', 'at'),
    'fr' => array('comme', 'que', 'tait', 'pour', 'sur', 'sont', 'avec', 'tre', 'un', 'ce', 'par', 'mais', 'que', 'est', 'il', 'eu', 'la', 'et', 'dans', 'mot'),
    'it' => array('sono', 'io', 'il', 'suo', 'che', 'lui', 'era', 'per', 'su', 'come', 'con', 'loro', 'essere', 'a', 'uno', 'avere', 'questo', 'da', 'di', 'caldo', 'lo', 'ma', 'cosa', 'alcuni', 'è', 'quello', 'voi', 'o', 'aveva', 'la', 'di', 'a', 'e', 'un', 'in', 'noi'),
    'es' => array('que', 'no', 'a', 'la', 'el', 'es', 'y', 'en', 'lo', 'un', 'por', 'qu', 'si', 'una', 'los', 'con', 'para', 'est', 'eso', 'las'),
  );

  /**
   * Mindestlänge eines Strings prüfen
   * @param string $sString zu prüfende Zeichenkette
   * @param integer $nMin minimale Länge
   * @return boolean True, wenn Minimallänge erreicht
   */
  public static function minLength($sString, $nMin)
  {
    $bReturn = false;
    if (mb_strlen($sString) >= $nMin) {
      $bReturn = true;
    }
    return ($bReturn);
  }

  /**
   * Maximale Länge eines Strings prüfen
   * @param string $sString zu prüfende Zeichenkette
   * @param integer $nMax maximale Länge
   * @return boolean True, wenn Maximallänge überschritten
   */
  public static function maxLength($sString, $nMax)
  {
    $bReturn = false;
    if (mb_strlen($sString) >= $nMax) {
      $bReturn = true;
    }
    return ($bReturn);
  }

  /**
   * Maximale und minimale Länge eines Strsings prüfen
   * @param string $sString , zu prüfende Zeichenkette
   * @param integer $nMax maximale Länge
   * @param integer $nMin minimale Länge
   * @return boolean True, wenn String innert max/min ist
   */
  public static function inRange($sString, $nMax, $nMin)
  {
    $bReturn = false;
    // Wenn nicht grösser als Max und kleiner als Min
    if (!self::maxLength($sString, $nMax) && self::minLength($sString, $nMin)) {
      $bReturn = true;
    }
    return ($bReturn);
  }

  /**
   * Max. länge prüfen und abschneiden wenn nötig.
   * @param string $sString zu prüfende Zeichenkette
   * @param integer $nMax maximale Länge des Strings
   * @param boolean $addDots , True für "..." abschneiden
   * @param string $dots the dots to use
   * @return string the chopped string
   */
  public static function chopString($sString, $nMax, $addDots = false, $dots = '...')
  {
    if (mb_strlen($sString) > $nMax) {
      $sString = mb_substr($sString, 0, $nMax);
      if ($addDots == true) {
        $sString .= $dots;
      }
    }
    return ($sString);
  }

  /**
   * @param $theString string the string to chop
   * @param $sLen int the start position
   * @param false $eLen the end position. If false then it cut it to the end
   * @param string $placeholder placeholder to use. DEfault "[...]"
   * @return string the chopped string
   */
  public static function chopStringCenter($theString, $sLen, $eLen = false, $placeholder = '[...]')
  {
    $eLen = $eLen === false ? $sLen : $eLen;

    if (strlen($theString) < $sLen + $eLen) {
      return $theString;
    }

    return substr($theString, 0, $sLen) . $placeholder . substr($theString, -$eLen);
  }

  /**
   * String auf bestimmte Anzahl Wörter kürzen (the simple way, kein regex)
   * @param string $sString Der zu kürzende String
   * @param int $nWords Anzahl Wörter auf die gekürzt wird
   * @param bool $addDots Am Ende drei Punkte (Nie, wenn letztes zeichen ein Punkt ist)
   * @param string $dots the dots to use
   * @param int $maxLength maximum length
   * @param string $eolReplace replacement for EOL chars
   * @return string the chopped string
   */
  public static function chopToWords($sString, $nWords, $addDots = false, $dots = '...', $maxLength = 0, $eolReplace = '')
  {
    // In Wörter Teilen und bis zum maximum oder array Ende wieder zusammenführen
    $sNewString = '';
    $count = 0;
    $chopped = false;
    // Sanitize the string a little
    $sString = str_replace(PHP_EOL, $eolReplace, $sString);
    $sString = str_replace('  ', ' ', $sString);
    $words = explode(' ', $sString);
    foreach ($words as $word) {
      // Test if the length would be overridden
      if ($maxLength > 0 && (mb_strlen($sNewString . $word) + 1) > $maxLength) {
        $chopped = true;
        break;
      }
      // Count number of words and add if word has at least a character
      if (strlen(trim($word)) > 0) {
        $sNewString .= trim($word) . ' ';
        if (++$count == $nWords) {
          $chopped = true;
          break;
        }
      }
    }
    // Raustrimmen vom letzten Space
    $sNewString = trim($sNewString);
    // Wenn gewünscht und am Ende kein Punkt, drei Punkte anhängen
    if ($addDots && $chopped) {
      $sNewString .= $dots;
    }
    return ($sNewString);
  }

  /**
   * Cut a string into sentences
   * @param $str string the string to cut
   * @param $min int where the earliest can should be
   * @param $max int where the cut should (maximal) end
   * @param $addDots bool
   * @param $sentenceEnd array final char to use to cut the sentence
   * @return string the cut string
   */
  public static function chopToSentences($str, $min, $max, $addDots = false, $sentenceEnd = ['.', '?', '!'], $cutSentenceEnd = false)
  {
    $cutSomewhereHere = substr($str, $min, $max - $min);
    $cutStr = $str;
    $cutAnyway = true;

    foreach ($sentenceEnd as $endChar) {
      if (self::contains($cutSomewhereHere, $endChar)) {
        $cutAnyway = false;
        $cutStr = substr($str, 0, $min + strpos($cutSomewhereHere, $endChar) + 1);

        if ($cutSentenceEnd) {
          $cutStr = substr($cutStr, 0, strlen($cutStr) - strlen($endChar));
        }

        break;
      }
    }

    if ($cutAnyway) {
      // Dont chop if sentence is shorten than max
      if (strlen($str) < $max) {
        return $str;
      }

      $cutStr = substr($str, 0, $min + strpos($cutSomewhereHere, ' '));
      if ($cutStr != $str && $addDots) {
        $cutStr .= '...';
      }
    }

    return $cutStr;
  }

  /**
   * @param string $text
   * @return string
   */
  public static function getFirstSentence($text)
  {
    $pos = array_filter(array(
      stripos($text, '.'),
      stripos($text, '!'),
      stripos($text, '?'),
    ));
    // Sort by positions, get the first one
    sort($pos, SORT_NUMERIC);
    $pos = intval(array_shift($pos));

    if ($pos > 0) {
      $text = substr($text, 0, $pos + 1);
    }

    return $text;
  }

  /**
   * @param string $string the string to obfuscate
   * @param int $start how many chars of the beginning should be shown
   * @param int $end how many chars of the end should be shown
   * @param string $obfuscator replacement for obfuscation
   * @param string $middle string in the middle of start and end between obfuscators
   * @return string obfuscated $string
   */
  public static function obfuscate($string, $start, $end, $obfuscator = '**', $middle = '')
  {
    $length = strlen($string);
    // Only obfuscate correctly, if string is long enough
    if ($length > ($start + $end)) {
      // Is there a middle string or not?
      if (strlen($middle) > 0) {
        $string = substr($string, 0, $start) . $obfuscator . $middle . $obfuscator . substr($string, $length - $end);
      } else {
        $string = substr($string, 0, $start) . $obfuscator . substr($string, $length - $end);
      }
    } else {
      $string = substr($string, 0, $start) . $obfuscator;
    }

    return $string;
  }

  /**
   * Datumseingabe anhand eines Regex checken.
   * Die dateOps Klasse bietet diverse Regex-Konstanten
   * an um den sFormat Parameter auszufüllen.
   * @param string $sString zu prüfendes Datum
   * @param string $sFormat Regex zur Prüfung
   * @return boolean True, wenn Datum dem gegebenen Format entspricht
   */
  public static function checkDate($sString, $sFormat)
  {
    return preg_match($sFormat, $sString);
  }

  /**
   * Email Adresse validieren.
   * @param string $sString zu validierende Email Adresse
   * @return boolean True, wenn Email Addresse korrekt ist
   */
  public static function checkEmail($sString)
  {
    return preg_match(self::REGEX_EMAIL, $sString);
  }

  /**
   * Validate email address
   * @param mixed $eString the email
   * @return bool the result of the email check
   */
  public static function isEmail($eString)
  {
    return filter_var($eString, FILTER_VALIDATE_EMAIL) !== false;
  }

  /**
   * Get the domain of the email address, namely everything after @
   * @param $email
   * @return bool
   */
  public static function getDomainFromEmail($email)
  {
    if (self::checkEmail($email)) {
      return substr($email, strripos($email, '@') + 1);
    }

    return false;
  }

  /**
   * URL validieren (http, https, ftp).
   * @param string $url zu prüfende URL
   * @return boolean True, wenn URL ok ist
   */
  public static function checkURL($url)
  {
    return (
      substr($url, 0, 7) == 'http://' ||
      substr($url, 0, 8) == 'https://' ||
      substr($url, 0, 6) == 'ftp://' ||
      substr($url, 0, 7) == 'mailto:' ||
      substr($url, 0, 4) == 'tel:' ||
      substr($url, 0, 1) == '#' ||
      self::startsWith($url, '/')
    );
  }

  /**
   * Lightweight call to read remote file size
   * @param string $url
   * @return int
   */
  public static function getRemoteFileSize($url)
  {
    if (!self::checkURL($url)) {
      return 0;
    }

    foreach (get_headers($url) as $header) {
      if (str_starts_with(strtolower($header), 'content-length:')) {
        $parts = explode(':', $header);
        return intval(trim($parts[1]));
      }
    }

    return 0;
  }

  /**
   * @param $string
   * @param $contains
   * @return bool
   */
  public static function contains($string, $contains)
  {
    return stristr($string, $contains) !== false;
  }

  /**
   * @param string $needle
   * @param array $haystack array of strings
   * @return bool
   */
  public static function containsAny($needle, $haystack)
  {
    foreach ($haystack as $contains) {
      if (stristr($needle, $contains) !== false) {
        return true;
      }
    }

    return false;
  }

  /**
   * Search for one element inside a string from a given array
   * @param string $string the string to be searched
   * @param array $contains the element array
   * @param bool $matchAll if it continues after the first match. Default false.
   * @param bool $returnBoth if it returns the string and the match(es). Default false.
   * @return bool|string|array returns false on failure or a string/array with the results
   */
  public static function containsOne($string, $contains, $matchAll = false, $returnBoth = false)
  {
    $matches = array(
      'strings' => array(),
      'contains' => array()
    );
    $result = false;

    foreach ($contains as $contain) {
      $containString = stristr($string, $contain);
      if ($containString !== false) {
        if ($matchAll) {
          $matches['strings'][] = $string;
          $matches['contains'][] = $contain;
          continue;
        }

        if ($returnBoth) {
          $result = [$containString, $contain];
        } else {
          $result = $containString;
        }

        break;
      }
    }

    return $result;
  }

  /**
   * @param string $url
   * @return bool true, if valid url
   */
  public static function isURL($url)
  {
    return filter_var($url, FILTER_VALIDATE_URL) !== false;
  }

  /**
   * Aus gegebener Variable alle Zeichen entfernen.
   * Entfernt wird alles ausser a-z0-9, während
   * Grossbuchstaben zu Kleinbuchstaben konvertiert werden.
   * @param string $Value Zu bearbeitender String
   */
  public static function alphaNumOnly(&$Value)
  {
    $Value = preg_replace(self::ALPHA_NUMERIC, "", $Value);
  }

  /**
   * @param string $value the string to be validated
   * @return string validated string with only a-z0-9 in it
   */
  public static function validateField($value)
  {
    return preg_replace(self::ALPHA_FILES, '', strtolower($value));
  }

  /**
   * Aus gegebener Variable alle Zeichen entfernen.
   * Entfernt wird alles ausser a-z0-9, während
   * Grossbuchstaben zu Kleinbuchstaben konvertiert werden.
   * @param string $value Zu bearbeitender String
   * @param bool $allowDots allow dots?
   * @return string validated field
   */
  public static function forceSlugString($value, $allowDots = false)
  {
    $value = self::replaceWellKnownChars(strtolower($value));
    if ($allowDots) {
      return preg_replace(self::ALPHA_SLUGS_DOTS, "", $value);
    } else {
      return preg_replace(self::ALPHA_SLUGS, "", $value);
    }
  }

  /**
   * @param string $html html code with breaks in it
   * @return string replaced all breaks with php new lines
   */
  public static function br2nl($html)
  {
    return preg_replace('/\<br(\s*)?\/?\>/i', PHP_EOL, $html);
  }

  /**
   * Diverse bekannte Character mit equivalenten ersetzen die in
   * einer URL angewendet werden können (also mit a-z zeugs)
   * @param string $str Input String
   * @return string geflickter String
   */
  public static function replaceWellKnownChars($str)
  {
    $str = str_replace('ä', 'ae', $str);
    $str = str_replace(array('ö'), 'oe', $str);
    $str = str_replace(array('ü'), 'ue', $str);
    $str = str_replace(array('à', 'â', 'á'), 'a', $str);
    $str = str_replace(array('é', 'ë', 'è', 'ê', '€'), 'e', $str);
    $str = str_replace(array('ï'), 'i', $str);
    $str = str_replace(array('ÿ'), 'y', $str);
    $str = str_replace(array('õ', 'ó', 'ò'), 'o', $str);
    $str = str_replace(array('ñ'), 'n', $str);
    $str = str_replace(array('û', 'ù', 'ú', 'û'), 'u', $str);
    $str = str_replace(array('ç', '¢'), 'c', $str);
    $str = str_replace(array(' ', '_', '+', '&'), '-', $str);
    $str = str_replace(array('\\', '|', '¦'), '/', $str);
    return ($str);
  }

  /**
   * Aus gegebener Variable alle Zeichen entfernen.
   * Entfernt wird alles ausser a-z0-9, während
   * Grossbuchstaben zu Kleinbuchstaben konvertiert werden.
   * Zudem sind "-" und "_" für Files erlaubt. Die
   * Dateiendung wird nicht validiert!
   * @param string $Value Zu bearbeitender Dateiname
   */
  public static function alphaNumFiles(&$Value)
  {
    // Preserven der Dateiendung
    $sExt = strtolower(substr($Value, strripos($Value, '.')));
    $Value = strtolower(substr($Value, 0, strripos($Value, '.')));
    $Value = preg_replace(self::ALPHA_FILES, "", $Value);
    $Value = $Value . $sExt;
  }

  /**
   * Aus gegebener Variable alle Zeichen entfernen.
   * Entfernt wird alles ausser a-zA-Z0-9.
   * Zudem sind "-" und "_" für erlaubt
   * @param string $Value Zu bearbeitender Dateiname
   */
  public static function alphaNumLow(&$Value)
  {
    $Value = preg_replace(self::ALPHA_NUMERIC_LOW, "", $Value);
  }

  /**
   * Aus gegebener Variable alle Zeichen entfernen.
   * Entfernt wird alles ausser a-zA-Z0-9.
   * Zudem sind "-" und "_" für erlaubt
   * @param string $Value Zu bearbeitender Dateiname
   */
  public static function alphaNumLowFiles(&$Value)
  {
    $Value = preg_replace(self::ALPHA_NUMERIC_LOW_FILES, "", $Value);
  }

  /**
   * HTML kodieren, Wert wird für Ausgaben zurückgegeben.
   * @param string $sString Zu kodierender String
   * @return string Kodierter String
   */
  public static function htmlEnt(&$sString)
  {
    $sString = htmlentities($sString);
    return ($sString);
  }

  /**
   * HTML enkodieren und rückkodieren der nur HTML werte.
   * Gedacht für Kodierung von HTML Werten, damit diese
   * direkt in einer View angezeigt werden können.
   * @param string $sString Zu kodierender String
   */
  public static function htmlViewEnt(&$sString)
  {
    $sString = htmlentities($sString);
    $sString = htmlspecialchars_decode($sString);
  }

  /**
   * HTML enkodieren und rückkodieren der nur HTML werte.
   * Gedacht für Kodierung von HTML Werten, damit diese
   * direkt in einer View angezeigt werden können. Liefert
   * den konvertierten Wert noch zurück.
   * @param string $sString Input String
   * @return string Gibt den kodierten String zurück
   */
  public static function htmlViewRet($sString)
  {
    self::htmlViewEnt($sString);
    return ($sString);
  }

  /**
   * HTML Entitäten rückkodieren (z.B. aus dem Editor)
   * @param string $sString zu kodierender String
   */
  public static function htmlEntRev(&$sString)
  {
    $sString = html_entity_decode($sString);
  }

  /**
   * Alles was nach HTML Tags aussieht aus dem String entfernen.
   * @param string $sString zu validierender String
   */
  public static function noHtml(&$sString)
  {
    $sString = strip_tags($sString);
  }

  /**
   * Integer als Boolean validieren (nur 0 / 1).
   * @param mixed $Value Zu validierender Wert
   * @return integer 1 oder 0, je nach Eingabe
   */
  public static function getBoolInt($Value)
  {
    $Value = intval($Value);
    if ($Value > 1 || $Value < 0) {
      $Value = 0;
    }
    return ($Value);
  }

  /**
   * @param mixed $Value Zu validierender Wert
   * @return integer 1 oder 0, je nach Eingabe
   */
  public static function getBoolString($Value)
  {
    return $Value ? 'true' : 'false';
  }

  /**
   * Integer als Boolean validieren (nur 0 / 1).
   * @param mixed $Value Zu validierender Wert
   * @return integer 1 oder alles darunter (auch negative)
   */
  public static function getPosInt($Value)
  {
    $Value = intval($Value);
    if ($Value < 1) {
      $Value = 1;
    }
    return ($Value);
  }

  /**
   * Extension eines Files zurückgeben inklusive . am Anfang
   * @param string $sFile zu bearbeitendes File
   * @return string Dateiendung mit Punkt
   */
  public static function getExtension($sFile)
  {
    return (substr($sFile, strripos($sFile, '.')));
  }

  /**
   * Kodiert alle nicht alphanumerischen Zeichen in einer URL
   * in die mit % angeführte Hex Version (Referenced)
   * @param string $sUrl zu dekodierender String
   */
  public static function urlEncode(&$sUrl)
  {
    $sUrl = rawurlencode($sUrl);
  }

  /**
   * Gibt die aktuelle URL zurück inkl. https/http und Port/URI
   * @return string Komplette aktuelle URL
   */
  public static function currentUrl()
  {
    $sUrl = 'http';
    if ($_SERVER['HTTPS'] == 'on') $sUrl .= 's';
    $sUrl .= '://' . $_SERVER['SERVER_NAME'];
    if ($_SERVER['SERVER_PORT'] != '80') {
      $sUrl .= ':' . $_SERVER["SERVER_PORT"];
    }
    $sUrl .= $_SERVER['REQUEST_URI'];
    return ($sUrl);
  }

  /**
   * Speichert einen Vardump in eine Variable
   * @param mixed $Var zu dumpende Variable
   * @return string Dump der gegebenen Variable
   */
  public static function getVarDump($Var)
  {
    ob_start();
    var_dump($Var);
    $result = ob_get_contents();
    ob_end_clean();
    return $result;
  }

  /**
   * @param string $content the content of the editor
   * @param string $key the key (name field)
   * @param array $args the editors arguments
   * @return string HTML code to display the wp editor
   */
  public static function getWpEditor($content, $key, $args)
  {
    ob_start();
    wp_editor($content, $key, $args);
    $result = ob_get_contents();
    ob_end_clean();
    return $result;
  }

  /**
   * Nimmt einen Tag und gibt einen Attributeinhalt zurück
   * @param string $sTag HTML Tag
   * @param string $sProperty zu findendes Property
   * @return string Inhalt des Attributs
   */
  public static function parseTagProperty($sTag, $sProperty)
  {
    // Suchen des Properties
    $regex = '/' . $sProperty . '= *([\'][^\'>]*[\']|[""][^"">]*[""])/';
    preg_match_all($regex, $sTag, $result);
    // Property Inhalt ohne Anführungszeichen extrahieren
    $sAttribute = '';
    $nLength = strlen($result[0][0]);
    if ($nLength > 0) {
      $nOffset = strlen($sProperty);
      $sAttribute = substr(
        $result[0][0],
        $nOffset + 2,
        $nLength - ($nOffset + 3)
      );
    }
    return ($sAttribute);
  }

  /**
   * Konvertierung von HTML Code in HTML Entitäten
   * @param string $sString Input
   * @return string Kodierter output
   */
  public static function stringToAsciiEntities($sString)
  {
    $sCoded = '';
    for ($i = 0; $i < strlen($sString); $i++) {
      $sCoded .= '&#' . ord($sString[$i]) . ';';
    }
    return ($sCoded);
  }

  /**
   * Gibt zurück, ob dar angegebene Anfang dem Anfang
   * des zu prüfenden Strings entspricht
   * @param string $sString zu prüfender String
   * @param string $sStart Gewünschter Anfang
   * @return bool true/false ob Entsprechen oder nicht
   */
  public static function startsWith($sString, $sStart)
  {
    $nStartLength = strlen($sStart);
    $nLength = strlen($sString);
    // Prüfen ob der String überhaupt so lang ist wie die Prüfung
    if ($nStartLength <= $nLength) {
      // Entsprechender Teil des gegebenen Strings extrahieren
      $sExtract = substr($sString, 0, $nStartLength);
      // Wenn gleich, dann OK!
      if ($sExtract == $sStart) {
        return true;
      }
    }
    return false;
  }

  /**
   * Check string if it start with one of the given starts
   * @param string $sString the string to check
   * @param array $sStarts the startings
   * @return bool true if one of the startings has been found, else false
   */
  public static function startsWithOne($sString, $sStarts)
  {
    foreach ($sStarts as $start) {
      if (self::startsWith($sString, $start)) {
        return true;
      }
    }

    return false;
  }

  /**
   * @param $n
   * @param $x
   * @return float|int
   */
  public static function roundUpToNearestDecimal($n, $x = 5, $y = 100)
  {
    $n *= $y;
    return ((round($n) % $x === 0) ? round($n) : round(($n + $x / 2) / $x) * $x) / $y;
  }

  /**
   * Gibt zurück, ob das angegebene Ende dem Ende
   * des zu prüfenden Strings entspricht
   * @param string $sString zu prüfender String
   * @param string $sEnd Gewünschtes Ende
   * @return bool true/false ob Entsprechen oder nicht
   */
  public static function endsWith($sString, $sEnd)
  {
    $nEndLength = strlen($sEnd);
    $nLength = strlen($sString);
    // Prüfen ob der String überhaupt so lang ist wie die Prüfung
    if ($nEndLength <= $nLength) {
      // Entsprechender Teil des gegebenen Strings extrahieren
      $nStart = $nLength - $nEndLength;
      $sExtract = substr($sString, $nStart);
      // Wenn gleich, dann OK!
      if ($sExtract == $sEnd) {
        return true;
      }
    }
    return false;
  }

  /**
   * Gibt einen Zufalls String zurück
   * @param int $nLength , Gewünschte länge
   * @return string, Zufällige Zeichenkette
   */
  public static function getRandom($nLength)
  {
    // Liste möglicher Zahlen
    $sChars = "abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUFVXYZ23456789";
    //Startwert für den Zufallsgenerator festlegen
    $sToken = '';
    for ($nChars = 0; $nChars < $nLength; $nChars++) {
      $sToken .= $sChars[mt_rand(0, 55)];
    }
    return ($sToken);
  }

  /**
   * @param $length int length of the password
   * @param $excludeSpecialChars array|bool array to exclude special characters. If set to true then all special characters will be excluded
   * @return string
   */
  public static function getRandomPassword($length, $excludeSpecialChars = false)
  {
    $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789' .
      ($excludeSpecialChars === true ? '' : '-=~!@#$%^&*()_+,./<>?;:[]{}\|');

    if (is_array($excludeSpecialChars)) {
      $chars = str_replace($excludeSpecialChars, '', $chars);
    }

    $password = array_merge(str_split($chars), str_split($chars), str_split($chars));
    shuffle($password);

    return implode(array_slice($password, rand(0, count($password) - $length), $length));
  }

  /**
   * Replaces a very reduced set of characters that shouldn't be used in file names.
   * Note that this function doesn't handle a lot of characters
   * @param string $string the input
   * @return string the output, fixed string
   */
  public static function replaceCommonFileChars($string)
  {
    return str_replace(
      array('ä', 'ö', 'ü', 'Ä', 'Ö', 'Ü', 'é', 'è', 'ê', 'É', 'È', 'â', 'á', 'à', 'ç', ' ', '"', '`', '´', 'Ã¼'),
      array('a', 'o', 'u', 'A', 'O', 'U', 'e', 'e', 'e', 'E', 'E', 'a', 'a', 'a', 'c', '', '', '', '', 'u'),
      $string
    );
  }

  /**
   * Wildcard search, where a needle can be *searchterm or searchterm* and searchterm
   * Currently *searchterm* is not supported, but in the future maybe?
   * @param string $needle the needle to search for
   * @param string $haystack
   * @return bool true, if the needle matches the haystack somehow
   */
  public static function wildcardSearch($needle, $haystack)
  {
    if (self::startsWith($needle, '*')) {
      // If there is an asterisk in front, check if haystack starts with needle
      $needle = substr($needle, 1);
      return self::endsWith($haystack, $needle);
    } else if (self::endsWith($needle, '*')) {
      // If there is an asterisk at the end, check if haystack ends with needle
      $needle = substr($needle, 0, strlen($needle) - 1);
      return self::startsWith($haystack, $needle);
    } else {
      // No asterisk, only absolute identical strings will match
      return $needle === $haystack;
    }
  }

  /**
   * @param string $str the fully unencoded string
   * @return string the result
   */
  public static function convertToEntities($str)
  {
    $str = mb_convert_encoding($str, 'UTF-32', 'UTF-8');
    $t = unpack("N*", $str);
    $t = array_map(function ($n) {
      return "&#$n;";
    }, $t);
    return implode("", $t);
  }

  /**
   * Prüft zwei Passwörter auf deren Länge und gleichheit und gibt
   * ein Array von Fehlermeldungen zurück (Sofern nicht OK)
   * @param string $sPwd1 Erstes Passwort
   * @param string $sPwd2 Wiederholtes Passwort
   * @param int $nLength Minimale Länge des Passwortes
   * @param string $textdomain the domain
   * @return string Array aller Fehlermeldungen (0 = Alles OK)
   */
  public static function checkPasswords($sPwd1, $sPwd2, $nLength, $textdomain = 'lbwp')
  {
    // Stringlänge der Passwörter cachen
    $nLen1 = strlen($sPwd1);
    $nLen2 = strlen($sPwd2);
    // Meldungsarray initialisieren
    $message = '';
    // Prüfen ob beide Passwörter vorhanden sind
    if ($nLen1 > 0 && $nLen2 > 0) {
      // Prüfen auf minimale Länge
      if ($nLen1 < $nLength || $nLen2 < $nLength) {
        $message = __('Ihr Passwort muss mindestens ' . $nLength . ' Zeichen lang sein', $textdomain);
      } else if ($sPwd1 !== $sPwd2) {
        // Prüfen auf Gleichheit nicht OK, Fehler
        $message = __('Die Passwörter stimmen nicht überein', $textdomain);
      }
    } else {
      // Meldung, dass keine neuen Passwörter eingegeben wurden
      $message = __('Sie haben kein Passwort eingegeben', $textdomain);
    }
    // Fehler Array zurückgeben
    return $message;
  }

  /**
   * Performs simple templating tasks typically used for SQL Statements.
   * Such a template will have placeholders with curly braces. Theses placeholders will be replaced by the values from the parameters array,
   * where the key in the parameters array corresponds to the placeholder. The order of the placeholder does not matter,
   * even when nested (i.e. a placeholder in a parameter value, where the nested placeholder can be defined in the parameters array)
   *
   * See test/PrepareSqlTemplateTest.php for PHPUnit tests.
   *
   * @param string $sqlTemplate SQL Template: SQL String with {placeholder}'s, which will be replaced by the corresponding value from the associative $parameters array
   * @param array $parameters associative array where the key is the parameter name and the value is the value for that parameter (string, int, array of strings, array of ints)
   * @return string prepared SQL
   */
  public static function prepareSql($sqlTemplate, array $parameters, $stripEmptyParameters = false)
  {
    $sql = $sqlTemplate;
    $foundPlaceholders = array();

    // trick to get all placeholders from the template and the $parameters in one string ($parameters can hold values with placeholders)
    $placeholderHaystack = $sqlTemplate . var_export($parameters, true);

    $placeholderHaystackLength = strlen($placeholderHaystack);

    // loop through the haystack and extract {placeholder}'s
    for ($pos = strpos($placeholderHaystack, '{'); $pos !== false && $pos < $placeholderHaystackLength; $pos = strpos($placeholderHaystack, '{', $pos + 1)) {
      if ($placeholderHaystack[$pos] == '{') {
        $placeholderStart = ++$pos;
        $placeholderEnd = strpos($placeholderHaystack, '}', $pos);
        if ($placeholderEnd !== false) {
          $foundPlaceholders[] = substr($placeholderHaystack, $placeholderStart, $placeholderEnd - $placeholderStart);
        }
      }
    }

    // loop through all found placeholders from the template and try to replace them with values from $parameters
    foreach (array_unique($foundPlaceholders) as $foundPlaceholder) {

      // determine if a modifier is used
      if (strpos($foundPlaceholder, ':') !== false) {
        list($parameterName, $modifier) = array_reverse(explode(':', $foundPlaceholder));
      } else {
        $parameterName = $foundPlaceholder;
        $modifier = false;
      }

      // only do something if the key is defined in $parameters (i.e. isset, instead of array_key_exists, returns false with a null value...)
      if (array_key_exists($parameterName, $parameters)) {
        $modifiedParameterValue = $parameters[$parameterName];
        // apply the modifiers
        switch ($modifier) {
          case 'sql':
          case 'raw':
            break;
          case 'quote':
          case 'escape':
          default:
            $modifiedParameterValue = self::escapeVar($modifiedParameterValue);
            break;
        }

        // implode if the parameter value is an array
        if (is_array($modifiedParameterValue)) {
          $modifiedParameterValue = implode(',', $modifiedParameterValue);
        }

        // actual replacement
        $sql = str_replace('{' . $foundPlaceholder . '}', $modifiedParameterValue, $sql);
      } else if ($stripEmptyParameters) {

        // if the placeholder does not exist in the parameter array, it will be stripped out, but only with $stripEmptyParameters=true (default is false)
        $sql = str_replace('{' . $foundPlaceholder . '}', '', $sql);
      }
    }

    // ltrim all lines (not required, but nice for phpunit)
    $sqlLines = explode(PHP_EOL, $sql);
    $sqlLines = array_map('ltrim', $sqlLines);
    $sql = implode(PHP_EOL, $sqlLines);
    return $sql;
  }

  /**
   * Escapes and wraps a $variable (string|array of strings)
   * @param array|string $var variable which will be wrapped in double-quotes and escaped
   * @return array|string
   */
  public static function escapeVar($var)
  {
    if (is_array($var)) {
      foreach ($var as $varKey => $varField) {

        // recursive escaping
        $var[$varKey] = self::escapeVar($varField);
      }
    } elseif (is_string($var)) {
      $db = WordPress::getDb();
      $var = '"' . $db->_escape($var) . '"';
    } else {
      // what else ??
    }
    return $var;
  }

  /**
   * @param int|float $value the value to format
   * @param int $decimals number of decimals
   * @return string like 12'989.50
   */
  public static function numberFormat($value, $decimals = 0)
  {
    return number_format($value, $decimals, '.', "'");
  }

  /**
   * Helper function: selects HTML Nodes from HTML string, based on XPath Query
   * @param string $ml any html/xml
   * @param string $query valid XPath Query
   * @param boolean $nodelist
   * @param boolean $forceXml
   * @return \DOMNodeList|string  resulting nodes, or html of the resulting nodes if $nodelist=true
   */
  public static function xpath($ml, $query, $nodelist = true, $forceXml = false)
  {
    if ($nodelist) {
      $result = array();
    } else {
      $result = '';
    }
    if ($ml != '' && $query != '') {
      $container = 'container';
      //loadHTML does not like html5 stuff
      $previousLibXmlInternalErrors = libxml_use_internal_errors(TRUE);
      $doc = new \DOMDocument();
      if ($forceXml) {
        $doc->loadXML($ml);
      } else {
        $doc->loadHTML('<?xml encoding="UTF-8"><' . $container . '>' . $ml . '</' . $container . '>');
      }
      //restore previous setting
      libxml_clear_errors();
      libxml_use_internal_errors($previousLibXmlInternalErrors);
      $xpath = new \DOMXPath($doc);
      $nodes = $xpath->query($query);
      if ($nodelist) {
        $result = $nodes;
      } else {
        foreach ($nodes as $node) {
          if ($forceXml) {
            $result .= $doc->saveXML($node);
          } else {
            $result .= $doc->saveHTML($node);
          }
        }
      }
    }
    return $result;
  }

  /**
   * This can be used to for example look for a UL and replace the default content in $html with $replacement
   * Example case: in themes/kapo-be/src/KapoBe/Component/Frontend.php : getRecentCommentsHtml
   * @param string $xpathSelector selector to find a certain container
   * @param string $html the html code to search in
   * @param string $replacement the html with which we should replace the selected dom elements content
   * @return string same $html, but the searched selectors have their content replaced with $replacement
   */
  public static function replaceContainerContentByXPath($xpathSelector, $html, $replacement)
  {
    /**
     * Get the whole widget with the ul content replaceable
     * @var \DOMDocument $doc The document initialized with $html
     * @var \DOMElement $tag A node of the result set
     * @var \DOMDocumentFragment $fragment Empty fragment node, add content by $fragment->appendXML('something');
     */
    $html = Strings::replaceByXPath($html, $xpathSelector, function ($doc, $tag, $fragment) {
      // Remove nodes of the found container
      while ($tag->hasChildNodes()) {
        $tag->removeChild($tag->childNodes->item(0));
      }
      // Make a replaceble variable that is them remplace
      $tag->nodeValue = '{XPathPlaceholder}';
      $fragment->appendXML($doc->saveXML($tag));
      return $tag;
    });

    return str_replace('{XPathPlaceholder}', $replacement, $html);
  }

  /**
   * Helper function, replaces DOMNodes of a HTML Fragment with replacment Nodes, selected by a XPath query and return the new HTML
   * @param string $ml markup language
   * @param string $xpathSelector select a set of nodes with a XPath query
   * @param callable $callback Called on each node found by xpath. expects 3 parameters: DOMDocument: parsed HTML (encapsuled in a root element), DOMNode: found node, DOMDocumentFragment: replacment node (empty)
   * @param string $resultSelector instead of returning the modified document, select a subset of nodes (output as string...). the default '//container/node()' also selects the text node when $ml does not contains any tags
   * @param boolean $forceXml forcing XML works, when the source is true xml (like rss)
   * @return string resulting document (as string)
   */
  public static function replaceByXPath($ml, $xpathSelector, $callback, $resultSelector = '//container/node()', $forceXml = false)
  {
    $container = 'container';

    //hack for DOMDocument loadHTML (which defaults to latin1 encoding, not like loadXML): force utf-8, need a container (root) element
    $encapsuledMl = '<?xml encoding="UTF-8">' . '<' . $container . '>' . $ml . '</' . $container . '>';

    $doc = new \DOMDocument();
    $doc->validateOnParse = false;

    //loadHTML does not like html5 stuff
    $previousLibXmlInternalErrors = libxml_use_internal_errors(TRUE);
    if ($forceXml) {
      $doc->loadXML($ml);
    } else {
      $doc->loadHTML($encapsuledMl);
    }
    //restore previous setting
    libxml_clear_errors();
    libxml_use_internal_errors($previousLibXmlInternalErrors);

    $xpath = new \DOMXpath($doc);
    $nodes = $xpath->query($xpathSelector);

    //process all nodes found by xpath query
    foreach ($nodes as $node) {
      //prepare replacement node
      $fragment = $doc->createDocumentFragment();
      /**
       * @param \DOMDocument $doc The document initialized with $html
       * @param \DOMNode $node A node of the result set
       * @param \DOMDocumentFragment $fragment Empty fragment node, add content by $fragment->appendXML('something');
       * @return \DOMNode Target node, which will be replaced by the fragment node
       */
      $targetNode = call_user_func_array($callback, array($doc, $node, $fragment));

      //DOMDocumentFragment is also a DOMNode, so replacing it will work
      if ($targetNode instanceof \DOMNode && $targetNode->parentNode instanceof \DOMNode) {
        $targetNode->parentNode->replaceChild($fragment, $targetNode);
      }
    }

    //get rid of previously used root element
    $newHtml = '';
    $children = $xpath->query($resultSelector);

    foreach ($children as $child) {
      if ($forceXml) {
        $newHtml .= $doc->saveXML($child);
      } else {
        $newHtml .= $doc->saveHTML($child);
      }
    }
    return $newHtml;
  }

  /**
   * @param $string
   * @param $needle
   * @return false|string
   */
  public static function removeUntil($string, $needle)
  {
    return substr($string, stripos($string, $needle) + 1);
  }

  /**
   * @param $string
   * @param $needle
   * @return false|string
   */
  public static function removeUntilIf($string, $needle)
  {
    if (stristr($string, $needle) !== false) {
      return substr($string, stripos($string, $needle) + 1);
    }
    return $string;
  }

  /**
   * @return string current url without any params
   */
  public static function getUrlWithoutParameters()
  {
    return str_replace(
      '?' . $_SERVER['QUERY_STRING'],
      '',
      $_SERVER['REQUEST_URI']
    );
  }

  /**
   * @param string $term
   * @param array $dictionary strings to fuzzy compare to
   * @param int $max
   * @param int $distance default 2 is very exact and useable for typing correction
   * @return array
   */
  public static function fuzzySearch($term, &$dictionary, $firstchar = true, $min = 1, $max = 5, $distance = 2)
  {
    $result = array();

    // Try correcting word by word, if more then one word in $term
    if (strpos($term, ' ') !== false) {
      $words = explode(' ', $term);
      $wordCount = count($words);

      // At this point this only works meaningfully with upto three words, else fail
      if ($wordCount > 3) {
        return $result;
      }

      // Get fuzzy results for each word
      $fuzzies = array();
      foreach ($words as $word) {
        // Build index with matchinf words by relevancy
        $index = array();
        foreach ($dictionary as $id => $dictWord) {
          $sameChars = count_chars($dictWord, 1) === count_chars($word, 1);
          $sameFirst = substr($word, 0, 1) == substr($dictWord, 0, 1);
          $wDist = levenshtein($dictWord, $word);
          if ($sameChars || ($wDist <= $distance && $sameFirst)) {
            $index[$dictWord] = $sameChars ? 1 : $wDist;
          }
        }

        // Order by relevancy (by the value, which is 1 or the levenshtein distance)
        asort($index);
        // if the first is an almost exact match, take it
        if (reset($index) == 1) {
          $fuzzies[$word] = array(key($index));
        } else {
          // if not, use the first three
          $max = $wordCount == 2 ? 3 : 2;
          $fuzzies[$word] = array_slice($index, 0, $max);
        }
      }

      $combinations = self::generateCartesianCombinations($fuzzies);
      foreach ($combinations as $combination) {
        $result[] = implode(' ', $combination);
      }

      return $result;
    }

    // This branch is optimal for a single word
    if ($firstchar) {
      // Try finding a match, but the first char must match as well
      $percent = 0;
      foreach ($dictionary as $word) {
        $wDist = levenshtein($word, $term);
        $sameFirst = substr($word, 0, 1) == substr($term, 0, 1);
        if ($wDist <= $distance && $sameFirst) {
          $matches = similar_text($word, $term, $percent);
          $rank = $percent + $matches - $wDist;
          $result[$word] = $rank;
        }
      }
    }

    // Calculate the similarty average of the results
    $average = 0;
    if (count($result) > 0) {
      $average = array_sum($result) / count($result);
    }

    // Search for more, if no good similarity results
    if ($average < 65) {
      foreach ($dictionary as $word) {
        $wDist = levenshtein($word, $term);
        if ($wDist <= $distance) {
          $matches = similar_text($word, $term, $percent);
          $rank = $percent + $matches - $wDist;
          $result[$word] = $rank;
        }
      }
    }

    // Sort by similarity and convert back to simple word array
    if (count($result) > 0) {
      arsort($result);
      $result = array_keys($result);
    }

    return array_slice($result, 0, $max);
  }

  /**
   * Makes all combinations for corrected strings from
   * $candidates = [
   *   "tellr" => ["teller"],
   *   "rto" => ["rot", "tor"],
   *   "klnei" => ["klein"],
   * ];
   * it creates for example
   * "teller rot klein"
   * "teller tor klein"
   * For autocompletion suggestions with typos
   * An array like this can be retrieved from fuzzySearch
   * @param $candidates
   * @return array|array[]
   */
  public static function generateCartesianCombinations($candidates) {
    // Start with an array containing an empty array
    $combinations = [[]];

    foreach ($candidates as $word => $alternatives) {
      $newCombinations = [];

      foreach ($combinations as $combination) {
        foreach ($alternatives as $alternative) {
          // Append the new alternative to each existing combination
          $newCombinations[] = array_merge($combination, [$alternative]);
        }
      }

      // Update combinations with the new set
      $combinations = $newCombinations;
    }

    return $combinations;
  }

  /**
   * @param string $needle the string to search for
   * @param array $haystack the array of strings to search in
   * @return string the nearest string in the haystack
   */
  public static function getNearestString($needle, $haystack)
  {
    $distances = array();
    foreach ($haystack as $word) {
      $distances[$word] = levenshtein($word, $needle, 2, 1, 2);
    }
    asort($distances);
    // Get key of first entry of the array
    return key($distances);
  }

  /**
   * @param $needle
   * @param $haystack
   * @return int|string|null
   */
  public static function getMostSimilarString($needle, $haystack, $beginning = false)
  {
    $percent = 0.0;
    $similarity = array();
    foreach ($haystack as $word) {
      if ($word == $needle) {
        continue;
      }
      similar_text($word, $needle, $percent);
      $similarity[$word] = $percent;
      // Add three percent if they begin with the same letter
      if ($beginning && substr($word, 0, 1) == substr($needle, 0, 1)) {
        $similarity[$word] += 3;
      }
    }
    arsort($similarity);
    return key($similarity);
  }

  /**
   * @param $needle
   * @param $haystack
   * @return int[]|string[]
   */
  public static function orderStringsBySimilarity($needle, $haystack)
  {
    $percent = 0.0;
    $similarity = array();
    foreach ($haystack as $word) {
      similar_text($word, $needle, $percent);
      $similarity[$word] = $percent;
    }
    arsort($similarity);
    return array_keys($similarity);
  }

  /**
   * @param string $value the original string
   * @param int $start start position to remove
   * @param int $end end position to remove
   * @param string $replace defaults to empty string, this removing start-end area
   * @return string the $value, but with start to end replaced or removed
   */
  public static function removeInString($value, $start, $end, $replace = '')
  {
    // Only do it, if possible from start/end values, return unchanged if not matching
    if ($start >= 0 && $end > 0 && $end > $start) {
      return substr_replace($value, $replace, $start, $end - $start);
    }

    return $value;
  }

  public static function convertToUUID($hash)
  {
    return strtolower(substr($hash, 0, 8) . '-' . substr($hash, 8, 4) . '-' . substr($hash, 12, 4) . '-' . substr($hash, 16, 4) . '-' . substr($hash, 20));
  }

  /**
   * @param $value
   * @return mixed|string
   */
  public static function convertToString($value)
  {
    if (is_float($value)) {
      $result = str_replace(',', '.', (string)$value);
    } else {
      $result = (string)$value;
    }

    return $result;
  }

  /**
   * stripslashes on string values in strings, arrays and objects recursively
   * @param mixed $something anything with escaped slashes
   * @return mixed the same object with stripslashed values/members
   */
  public static function deepStripSlashes($something)
  {
    // test different types
    if (is_string($something)) {
      return stripslashes($something);
    } else if (is_array($something)) {
      // recursively map values of the array
      return array_map(array(__CLASS__, 'deepStripSlashes'), $something);
    } else if (is_object($something)) {
      // get the object keys
      $vars = get_object_vars($something);
      foreach ($vars as $key => $value) {
        //recursively map object values
        $something[$key] = self::deepStripSlashes($value);
      }
      return $something;
    } else {
      // something else. maybe an number, float, double.
      return $something;
    }
  }

  /**
   * @param $html
   * @return string
   */
  public static function getAltMailBody($html)
  {
    return trim(nl2br(strip_tags($html)));
  }

  /**
   * @param string $url the url
   * @param bool $removeWWW removed www. if needed
   * @return string the hostname of the url
   */
  public static function getHostFromUrl($url, $removeWWW = true)
  {
    $host = $url;

    // Remove possible beginnings
    $host = substr($host, strpos($host, '://') + 3);
    // Remove everythign after the first slash
    $slashPos = strpos($host, '/');
    if ($slashPos > 0) {
      $host = substr($host, 0, $slashPos);
    }

    if ($removeWWW && self::startsWith($host, 'www.')) {
      $host = substr($host, 4);
    }

    return $host;
  }

  /**
   * Builds a new url from parse_url data
   * @param array $parts
   * @return string a full url
   */
  public static function buildUrl(array $parts)
  {
    return
      (isset($parts['scheme']) ? "{$parts['scheme']}:" : '') .
      ((isset($parts['user']) || isset($parts['host'])) ? '//' : '') .
      (isset($parts['user']) ? "{$parts['user']}" : '') .
      (isset($parts['pass']) ? ":{$parts['pass']}" : '') .
      (isset($parts['user']) ? '@' : '') .
      (isset($parts['host']) ? "{$parts['host']}" : '') .
      (isset($parts['port']) ? ":{$parts['port']}" : '') .
      (isset($parts['path']) ? "{$parts['path']}" : '') .
      (isset($parts['query']) ? "?{$parts['query']}" : '') .
      (isset($parts['fragment']) ? "#{$parts['fragment']}" : '');
  }

  /**
   * Closes unclosed HTML Tags and removes unopened closing tags
   * @param string $html
   * @return string
   */
  public static function fixInvalidHtml($html)
  {
    return self::xpath($html, '//container/node()', false);
  }

  /**
   * Replace the last occurence of a string in a string
   * @param string $search the searched occurence
   * @param string $replace the replacement text
   * @param string $subject the searched text
   * @return string the subject, with the text replaced eventually
   */
  public static function replaceLastOccurence($search, $replace, $subject)
  {
    $pos = strripos($subject, $search);
    // Replace if found
    if ($pos !== false) {
      $subject = substr_replace($subject, $replace, $pos, strlen($search));
    }

    return $subject;
  }

  /**
   * Replace the last occurence of a string in a string
   * @param string $search the searched occurence
   * @param string $replace the replacement text
   * @param string $subject the searched text
   * @return string the subject, with the text replaced eventually
   */
  public static function replaceFirstOccurence($search, $replace, $subject)
  {
    $pos = stripos($subject, $search);
    // Replace if found
    if ($pos !== false) {
      $subject = substr_replace($subject, $replace, $pos, strlen($search));
    }

    return $subject;
  }

  /**
   * @return bool true, if the user agent is a legitimate search engine crawler
   */
  public static function isSearchEngineUserAgent()
  {
    $agent = strtolower($_SERVER['HTTP_USER_AGENT']);
    return (
      stristr($agent, 'bot/') !== false ||
      stristr($agent, 'spider') !== false ||
      stristr($agent, 'mediapartners') !== false ||
      stristr($agent, 'adsbot') !== false ||
      stristr($agent, 'dotbot') !== false ||
      stristr($agent, 'semrushbot') !== false ||
      stristr($agent, 'petalbot') !== false ||
      stristr($agent, 'google') !== false ||
      stristr($agent, 'ysearch/slurp') !== false ||
      stristr($agent, 'turnitin') !== false ||
      stristr($agent, '/applebot') !== false
    );
  }

  /**
   * @return bool true if a scraper is on the page
   */
  public static function isScraperUserAgent()
  {
    $agent = strtolower($_SERVER['HTTP_USER_AGENT']);
    return (
      stristr($agent, 'gpt') !== false ||
      stristr($agent, 'facebookexternalhit') !== false ||
      stristr($agent, 'linkedinbot') !== false ||
      stristr($agent, 'twitterbot') !== false ||
      stristr($agent, 'iframely') !== false ||
      stristr($agent, 'contenttabreceiver') !== false ||
      stristr($agent, 'java/1') !== false
    );
  }

  /**
   * @param string $key the key
   * @param string $value the value
   * @param string $url the url to attach params
   * @return string new url with attached param
   */
  public static function attachParam($key, $value, $url)
  {
    if (stristr($url, '?') === false) {
      $url .= '?' . $key . '=' . urlencode($value);
    } else {
      $url .= '&' . $key . '=' . urlencode($value);
    }

    return $url;
  }

  /**
   * @param string $key
   * @param string $url
   * @return string
   */
  public static function removeParam($key, $url)
  {
    $parts = parse_url($url);
    parse_str($parts['query'], $params);
    unset($params[$key]);
    $parts['query'] = http_build_query($params);
    return self::buildUrlString($parts);
  }

  /**
   * @param $urlData
   * @return string
   */
  public static function buildUrlString($urlData)
  {
    $scheme = isset($urlData['scheme']) ? $urlData['scheme'] . '://' : '';
    $host = isset($urlData['host']) ? $urlData['host'] : '';
    $port = isset($urlData['port']) ? ':' . $urlData['port'] : '';
    $user = isset($urlData['user']) ? $urlData['user'] : '';
    $pass = isset($urlData['pass']) ? ':' . $urlData['pass'] : '';
    $pass = ($user || $pass) ? "$pass@" : '';
    $path = isset($urlData['path']) ? $urlData['path'] : '';
    $query = isset($urlData['query']) ? '?' . $urlData['query'] : '';
    $fragment = isset($urlData['fragment']) ? '#' . $urlData['fragment'] : '';
    return "$scheme$user$pass$host$port$path$query$fragment";
  }

  /**
   * @param $string
   * @return bool
   */
  public static function dedectUtf8($string)
  {
    return mb_detect_encoding($string, 'UTF-8', true) !== false;
  }

  /**
   * @param $url
   * @param $data
   * @param string $type
   * @param bool $json
   * @param bool $proxy
   * @param string $baUser
   * @param string $baPwd
   * @return mixed
   */
  public static function genericRequest($url, $data, $type = 'POST', $json = false, $proxy = false, $baUser = '', $baPwd = '', $headers = false)
  {
    // The URL is set, try to get the contents with curl so we get HTTP Status too
    $options = array(
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_SSLVERSION => 6,
      CURLOPT_SSL_VERIFYPEER => false, // do not verify ssl certificates (fails if they are self-signed)
      CURLOPT_SSL_VERIFYHOST => false,
      CURLOPT_HEADER => $headers,
      CURLOPT_FOLLOWLOCATION => true,
      CURLOPT_ENCODING => '',
      CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 6.1; WOW64) AppleWebKit/537.31 (KHTML, like Gecko) Chrome/26.0.1410.43 Safari/537.31 Comotive-Fetch-1.0',
      CURLOPT_AUTOREFERER => true,
      CURLOPT_CONNECTTIMEOUT => 30,
      CURLOPT_TIMEOUT => 30,
      CURLOPT_MAXREDIRS => 10,
      CURLOPT_COOKIEJAR => 'tempCookie',
      CURLOPT_POSTFIELDS => $data,
      CURLOPT_CUSTOMREQUEST => $type,
      CURLOPT_HTTPHEADER => array(
        'Content-Type: application/x-www-form-urlencoded'
      )
    );

    // Make a json post, if needed
    if ($json && ($type == 'POST' || $type == 'PUT')) {
      $string = json_encode($data);
      $options[CURLOPT_POSTFIELDS] = $string;
      $options[CURLOPT_HTTPHEADER] = array(
        'Content-Type: application/json',
        'Content-Length: ' . strlen($string)
      );
    }
    // Special handling for put, no headers!
    if (!$json && $type == 'PUT') {
      $options[CURLOPT_POSTFIELDS] = http_build_query($data);
      $options[CURLOPT_HTTPHEADER] = array();
    }

    // If required, go via the comotive proxy
    if ($proxy) {
      $options[CURLOPT_PROXY] = 'http://194.182.165.126';
      $options[CURLOPT_PROXYPORT] = '3128';
      $options[CURLOPT_PROXYUSERPWD] = 'comotive:Kv8gnr9qd5erSquid';
    }

    // Authenticate with basic auth, if needed
    if (strlen($baUser) > 0 && strlen($baPwd) > 0) {
      $options[CURLOPT_USERPWD] = $baUser . ':' . $baPwd;
    }

    $res = curl_init($url);
    curl_setopt_array($res, $options);
    $result = curl_exec($res);

    // If receiving headers and there is no result, add it
    if ($headers) {
      $headerStrings = explode(PHP_EOL, $result);
      $result = array();
      foreach ($headerStrings as $string) {
        list($key, $value) = explode(':', $string);
        if (strlen($key) > 0 && strlen($value) > 0) {
          $result[strtolower($key)] = trim($value);
        }
      }
      // Convert back to json, to be interpreted
      $result = json_encode($result);
    }

    curl_close($res);

    return $result;
  }

  /**
   * @param $url
   * @param $data
   * @param string $type
   * @param bool $json
   * @param bool $proxy
   * @param string $baUser
   * @param string $baPwd
   * @return mixed
   */
  public static function genericRequestGetJson($url, $data)
  {
    // The URL is set, try to get the contents with curl so we get HTTP Status too
    $options = array(
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_SSLVERSION => 6,
      CURLOPT_SSL_VERIFYPEER => false, // do not verify ssl certificates (fails if they are self-signed)
      CURLOPT_SSL_VERIFYHOST => false,
      CURLOPT_FOLLOWLOCATION => true,
      CURLOPT_ENCODING => '',
      CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 6.1; WOW64) AppleWebKit/537.31 (KHTML, like Gecko) Chrome/26.0.1410.43 Safari/537.31 Comotive-Fetch-1.0',
      CURLOPT_AUTOREFERER => true,
      CURLOPT_CONNECTTIMEOUT => 30,
      CURLOPT_TIMEOUT => 30,
      CURLOPT_MAXREDIRS => 10,
      CURLOPT_COOKIEJAR => 'tempCookie',
      CURLOPT_HTTPHEADER => array(
        'Content-Type: application/x-www-form-urlencoded'
      )
    );

    $res = curl_init($url . '?' . http_build_query($data));
    curl_setopt_array($res, $options);
    $result = curl_exec($res);
    curl_close($res);

    return json_decode($result, true);
  }

  /**
   * @param string $hash the hash to attach or replace
   * @param string $url the url to attach the hash to
   * @return string url with hash
   */
  public static function attachHash($hash, $url)
  {
    // Only add a hash, if theree isn't one in the url
    if (stristr($url, '#') === false) {
      // Save to add new hash
      $url .= '#' . $hash;
    }

    return $url;
  }

  /**
   * @param string $url
   * @param string $protocol
   * @return string
   */
  public static function getSafeUrl($url, $protocol = 'https')
  {
    if (!self::checkURL($url)) {
      $url = $protocol . '://' . $url;
    }

    return $url;
  }

  /**
   * @param $needle
   * @param $haystack
   * @return array
   */
  public static function getStringPositions($needle, $haystack)
  {
    $lastPos = 0;
    $positions = array();
    while (($lastPos = strpos($haystack, $needle, $lastPos)) !== false) {
      $positions[] = $lastPos;
      $lastPos = $lastPos + strlen($needle);
    }

    return $positions;
  }

  /**
   * @param string $string the string that should be proofed
   * @return bool if it $string is an empty string returns false. If it's not an empty string or not a string at all, returns false
   */
  public static function isEmpty($string)
  {
    if (gettype($string) === 'string') {
      if (strlen(trim($string)) == 0) {
        return true;
      } else {
        return false;
      }
    } else {
      return false;
    }
  }

  /**
   * @return a string of terms gathered from all params given to the function
   */
  public static function termize()
  {
    // Get all args, make them a slug and split into words
    $data = func_get_args();
    return self::termizeWithArray($data);
  }

  /**
   * @return a string of terms gathered from all params given to the function
   */
  public static function termizeWithArray($data)
  {
    // Get all args, make them a slug and split into words
    $data = implode(' ', $data);
    $data = self::forceSlugString($data);
    $data = explode('-', $data);
    // Build array of terms, having every word only once
    $terms = array();
    foreach ($data as $word) {
      $terms[$word] = true;
    }
    // Return as string with spaces in between
    return implode(' ', array_keys($terms));
  }

  /**
   * Get a phone number that can safely be used in tel:// links
   * @param string $number
   * @param string $prefix
   * @return string normalized phone number
   */
  public static function normalizePhoneNumber($number, $prefix = '')
  {
    if (strlen($prefix) == 0 && Strings::startsWith($number, '(0)')) {
      $number = str_replace('(0)', '0', $number);
    }
    $number = str_replace('(0)', '', $number);
    if (Strings::startsWith($number, '00') || Strings::startsWith($number, '++')) {
      $number = str_replace(array('00', '++'), '+', $number);
    }
    $number = preg_replace(self::PHONE_CHARACTERS, '', $number);
    // Prefix it, if not prefixed already
    if (strlen($prefix) > 0 && !Strings::startsWith($number, '+')) {
      // Remove leading zero if still given
      if (Strings::startsWith($number, '0')) {
        $number = substr($number, 1);
      }
      $number = $prefix . $number;
    }
    return $number;
  }

  /**
   * Formats the swiss way. Works best with normalizePhoneNumber in advance
   * @param string $number normalized phone number
   * @return string a formatted phone number for displaying
   */
  public static function formatPhoneNumber($number, $spacesAt = array(2, 4, 7))
  {
    $chars = str_split($number, 1);
    $number = '';
    foreach ($chars as $index => $char) {
      $number .= $char;
      if (in_array($index, $spacesAt)) {
        $number .= ' ';
      }
    }

    return $number;
  }

  /**
   * Formats a link to an website specific url
   * @param string $type the website name
   * @param string $url the url to format
   * @return string the formated url
   */
  public static function formatLink($type, $url)
  {
    $formatedUrl = '';
    switch ($type) {
      case 'youtube':
        $channelId = substr($url, strrpos($url, '/') + 1);
        $formatedUrl .= 'https://www.youtube.com/feeds/videos.xml?channel_id=' . $channelId;
        break;
    }

    return $formatedUrl;
  }

  /**
   * @param $string string the whole string
   * @param $wrapThis string the word or string part to wrap
   * @param $wrapStart string the opening of the wrap (e.g. an opening HTML tag)
   * @param $wrapEnd string the closing of the wrao (e.g. an closing HTML tag)
   * @return string returns the string with te wrapped part
   */
  public static function wrap($string, $wrapThis, $wrapStart, $wrapEnd, $caseSensitive = false, $strict = false)
  {
    $regexPattern = $strict ? '/(\b' . $wrapThis . '\b)/' : '/(' . $wrapThis . ')/';
    $regexPattern = $caseSensitive ? $regexPattern : $regexPattern .= 'i';
    $regex = preg_match_all($regexPattern, $string, $matches, PREG_OFFSET_CAPTURE);

    foreach ($matches[0] as $matchNum => $match) {
      // Skip match if already replacent in first loop
      if ($matchNum !== 0 && $match[0] === $matches[0][0][0]) {
        continue;
      }

      $string = str_replace($match[0], $wrapStart . $match[0] . $wrapEnd, $string);
    }

    return $string;
  }

  /**
   * @param string $html
   * @return string
   */
  public static function forceTargetBlankInHtml($html)
  {
    // Get all a tags with href and no target setting with regex
    $pattern = '/<a(.*?)href="(.*?)"(.*?)>/';
    preg_match_all($pattern, $html, $matches, PREG_OFFSET_CAPTURE);
    foreach ($matches[0] as $match) {
      $tag = $match[0];
      $href = $matches[2][0][0];
      $parts = parse_url($href);
      $setTargetBlank = $parts['host'] != LBWP_HOST || Strings::endsWith($href, '.pdf');
      if ($setTargetBlank && strpos($tag, 'target=') === false) {
        $tag = str_replace('>', ' target="_blank">', $tag);
        $html = str_replace($match[0], $tag, $html);
      }
    }

    return $html;
  }

  /**
   * @param string $value the string to be fixed like UPPERCASE ME
   * @return string the fixed string with capitals like "Uppercase Me"
   */
  public static function maybeFixCapitals($value)
  {
    if ($value === mb_strtoupper($value)) {
      // split up into array on delimiters
      $parts = preg_split("/([\.\-\ ])/", $value, -1, PREG_SPLIT_DELIM_CAPTURE);
      $new = '';
      foreach ($parts as $part) {
        $new .= ucfirst(mb_strtolower($part));
      }
      return $new;
    }

    return $value;
  }

  /**
   * @param string $email
   * @return string
   */
  public static function maybeFixEmailAddressTypos($email)
  {
    $typos = array(
      'hotmail.com' => array('@homail.com', '@hotail.com', '@hitmail.com'),
      'outlook.com' => array('@outlok.com', '@ouloook.com'),
      'bluewin.ch' => array('@bluwin.ch', '@bluewein.ch', '@bluewiin.ch', '@buewin.ch', '@bluewiin.ch', '@bluewim.ch', '@bluwein.ch'),
      'gmail.com' => array('@gmail.cm', '@gmail.de', '@gmail.ch'),
      't-online.de' => array('@tonline.de', '@t-onlien.de')
    );

    foreach ($typos as $fix => $search) {
      $email = str_replace($search, '@' . $fix, $email);
    }

    return $email;
  }

  /**
   * creates a helper array to numerate things by a-z, aa-zz, aaa-zzz etc.
   * @param int $limit number of entries to be enumerated
   * @return array
   */
  public static function getGuggenheimianNumerationArray($limit)
  {
    $list = array();
    $numerations = $runs = 0;
    while (++$runs) {
      foreach (self::ALPHABET as $character) {
        $list[] = str_repeat($character, $runs);
        if (++$numerations >= $limit + 1) {
          break 2;
        }
      }
    }

    return $list;
  }

  /**
   *  Regex-based Markdown parser. Source: /plugins/advanced-custom-fields-pro/includes/api/api-helpers.php:acf_parse_markdown().
   *
   * @param $text string the text to be parsed
   * @param false $trim if the text should be trimmed
   * @param false $autop if wpautop should be uset on the text
   * @return string|string[]|null the parsed string
   */
  public static function parseMarkdown($text, $trim = false, $autop = false)
  {
    // trim
    if ($trim) {
      $text = trim($text);
    }

    // rules
    $rules = array(
      '/=== (.+?) ===/' => '<h2>$1</h2>',          // headings
      '/== (.+?) ==/' => '<h3>$1</h3>',          // headings
      '/= (.+?) =/' => '<h4>$1</h4>',          // headings
      '/\[([^\[]+)\]\(([^\)]+)\)/' => '<a href="$2">$1</a>',      // links
      '/(\*\*)(.*?)\1/' => '<strong>$2</strong>',      // bold
      '/(\*)(.*?)\1/' => '<em>$2</em>',          // intalic
      '/`(.*?)`/' => '<code>$1</code>',        // inline code
      '/\n\*(.*)/' => "\n<ul>\n\t<li>$1</li>\n</ul>",  // ul lists
      '/\n[0-9]+\.(.*)/' => "\n<ol>\n\t<li>$1</li>\n</ol>",  // ol lists
      '/<\/ul>\s?<ul>/' => '',                // fix extra ul
      '/<\/ol>\s?<ol>/' => '',                // fix extra ol
    );
    foreach ($rules as $k => $v) {
      $text = preg_replace($k, $v, $text);
    }

    // autop
    if ($autop) {
      $text = wpautop($text);
    }

    // return
    return $text;
  }

  /**
   * @param $text
   * @return false|string
   */
  public static function guessLanguage($text)
  {
    $guessedLang = array();
    // Chop string into words
    $words = explode('-', preg_replace('/[^A-Za-z0-9\-]/', '', str_replace(' ', '-', $text)));

    // Don't guess if not enough words
    if (!is_array($words) || count($words) < 20) {
      return false;
    }

    foreach (self::MOST_COMMON_WORDS as $lang => $wordList) {
      // Subtract the not found words from the original list so to get the number of found words
      $guessedLang[$lang] = count($wordList) - count(array_diff($wordList, $words));
    }

    arsort($guessedLang);

    // return false if all language have the same number (mostly for the case that all have zero)
    if (count(array_unique(array_values($guessedLang))) === 1) {
      return false;
    }

    // Return only the language with the most words
    return array_keys($guessedLang)[0];
  }
}