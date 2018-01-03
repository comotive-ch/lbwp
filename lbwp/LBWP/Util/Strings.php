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
    if (self::maxLength($sString, $nMax) == true) {
      $sString = mb_substr($sString, 0, $nMax);
      if ($addDots == true) {
        $sString .= $dots;
      }
    }
    return ($sString);
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
    $bReturn = false;
    if (preg_match($sFormat, $sString)) {
      $bReturn = true;
    }
    return ($bReturn);
  }

  /**
   * Email Adresse validieren.
   * @param string $sString zu validierende Email Adresse
   * @return boolean True, wenn Email Addresse korrekt ist
   */
  public static function checkEmail($sString)
  {
    $bReturn = false;
    if (preg_match(self::REGEX_EMAIL, $sString)) {
      $bReturn = true;
    }
    return ($bReturn);
  }

  /**
   * URL validieren (http, https, ftp).
   * @deprecated please use isURL as it actually uses meaningful regex
   * @param string $url zu prüfende URL
   * @return boolean True, wenn URL ok ist
   */
  public static function checkURL($url)
  {
    return (
      substr($url, 0, 7) == 'http://' ||
      substr($url, 0, 8) == 'https://' ||
      substr($url, 0, 6) == 'ftp://' ||
      self::startsWith($url, '/')
    );
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
    $bStartsWith = false;
    $nStartLength = strlen($sStart);
    $nLength = strlen($sString);
    // Prüfen ob der String überhaupt so lang ist wie die Prüfung
    if ($nStartLength <= $nLength) {
      // Entsprechender Teil des gegebenen Strings extrahieren
      $sExtract = substr($sString, 0, $nStartLength);
      // Wenn gleich, dann OK!
      if ($sExtract == $sStart) {
        $bStartsWith = true;
      }
    }
    return ($bStartsWith);
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
    $bEndsWith = false;
    $nEndLength = strlen($sEnd);
    $nLength = strlen($sString);
    // Prüfen ob der String überhaupt so lang ist wie die Prüfung
    if ($nEndLength <= $nLength) {
      // Entsprechender Teil des gegebenen Strings extrahieren
      $nStart = $nLength - $nEndLength;
      $sExtract = substr($sString, $nStart);
      // Wenn gleich, dann OK!
      if ($sExtract == $sEnd) {
        $bEndsWith = true;
      }
    }
    return ($bEndsWith);
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
   * Replaces a very reduced set of characters that shouldn't be used in file names.
   * Note that this function doesn't handle a lot of characters
   * @param string $string the input
   * @return string the output, fixed string
   */
  public static function replaceCommonFileChars($string)
  {
    return str_replace(
      array('ä', 'ö', 'ü', 'Ä', 'Ö', 'Ü', 'é', 'è', 'ê', 'É', 'È', 'â', 'á', 'à', 'ç', ' ', '"', '`', '´', 'Ã¼'),
      array('a', 'o', 'u', 'A', 'O', 'U', 'e', 'e', 'e', 'E', 'E', 'a', 'a', 'a', 'c', '',  '',  '',  '',  'u'),
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
   * @return array Array aller Fehlermeldungen (0 = Alles OK)
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
      stristr($agent, 'googlebot/2') !== false ||
      stristr($agent, 'googlebot-image') !== false ||
      stristr($agent, 'bingbot/2') !== false ||
      stristr($agent, 'ysearch/slurp') !== false
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
   * @param $url
   * @param $data
   * @param $type
   * @param bool $proxy
   * @return mixed
   */
  public static function genericRequest($url, $data, $type = 'POST', $json = false, $proxy = false)
  {
    // The URL is set, try to get the contents with curl so we get HTTP Status too
    $options = array(
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_SSL_VERIFYPEER => false, // do not verify ssl certificates (fails if they are self-signed)
      CURLOPT_HEADER => false,
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
    if ($json && $type == 'POST') {
      $string = json_encode($data);
      $options[CURLOPT_POSTFIELDS] = $string;
      $options[CURLOPT_HTTPHEADER] = array(
        'Content-Type: application/json',
        'Content-Length: ' . strlen($string)
      );
    }

    // If required, go via the comotive proxy
    if ($proxy) {
      $options[CURLOPT_PROXY] = 'http://46.101.12.125';
      $options[CURLOPT_PROXYPORT] = '3128';
      $options[CURLOPT_PROXYUSERPWD] = 'comotive:Kv8gnr9qd5erSquid';
    }

    $res = curl_init($url);
    curl_setopt_array($res, $options);
    $result = curl_exec($res);
    curl_close($res);

    return $result;
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
}