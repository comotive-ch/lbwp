<?php

namespace LBWP\Util\Imap;

use ValueError;

/**
 * Stateless MIME helpers (RFC 2045/2047/2231/5322) used by the IMAP client.
 * Replaces imap_utf8() and the structure decoding formerly done by ext-imap.
 * @package LBWP\Util\Imap
 */
class Mime
{
  /**
   * Charsets that need no conversion to UTF-8
   */
  const array UTF8_COMPATIBLE_CHARSETS = ['', 'utf-8', 'utf8', 'us-ascii', 'ascii'];

  /**
   * A single RFC 2047 encoded-word: charset (with optional RFC 2231 language), encoding, text
   */
  const string ENCODED_WORD_PATTERN = '=\?([^?*]+)(?:\*[^?]*)?\?([BbQq])\?([^?]*)\?=';

  /**
   * Decodes RFC 2047 encoded-words ("=?UTF-8?B?…?=") of a header value to UTF-8. Replaces imap_utf8().
   * iconv_mime_decode() is not used as it drops raw 8-bit characters (already decoded or RFC 6532 headers).
   * @param string|null $value the raw header value
   * @return string decoded UTF-8 value, trimmed
   */
  public static function decodeHeader(?string $value): string
  {
    if ($value === null || $value === '') {
      return '';
    }

    if (!mb_check_encoding($value, 'UTF-8')) {
      $value = self::toUtf8($value, 'windows-1252');
    }

    $runPattern = '/' . self::ENCODED_WORD_PATTERN . '(?:\s*' . self::ENCODED_WORD_PATTERN . ')*/';
    return trim(preg_replace_callback($runPattern, fn(array $run) => self::decodeEncodedWordRun($run[0]), $value));
  }

  /**
   * Splits a raw message or part into its header block and body
   * @param string $raw the raw message or part
   * @return array{0: string, 1: string} header block and body
   */
  public static function splitHeaderAndBody(string $raw): array
  {
    if (preg_match('/^\r?\n/', $raw, $match)) {
      return ['', substr($raw, strlen($match[0]))];
    }

    if (!preg_match('/\r?\n\r?\n/', $raw, $match, PREG_OFFSET_CAPTURE)) {
      return [$raw, ''];
    }

    $offset = $match[0][1];
    return [substr($raw, 0, $offset), substr($raw, $offset + strlen($match[0][0]))];
  }

  /**
   * Parses an unfolded header block into lowercase names with all their values
   * @param string $block the raw header block
   * @return array<string, string[]> header values by lowercase name, in order of appearance
   */
  public static function parseHeaders(string $block): array
  {
    $headers = [];
    $block = preg_replace('/\r?\n[ \t]+/', ' ', $block);

    foreach (preg_split('/\r?\n/', $block) as $line) {
      $parts = explode(':', $line, 2);
      if (count($parts) < 2 || trim($parts[0]) === '') {
        continue;
      }
      $headers[strtolower(trim($parts[0]))][] = trim($parts[1]);
    }

    return $headers;
  }

  /**
   * Parses a structured header value like Content-Type or Content-Disposition
   * @param string $value e.g. 'text/plain; charset="utf-8"'
   * @return array{0: string, 1: array<string, string>} lowercase main value and decoded parameters by lowercase name
   */
  public static function parseValueWithParameters(string $value): array
  {
    $segments = self::splitOutsideQuotes($value, ';');
    $main = strtolower(trim(array_shift($segments)));
    $plain = [];
    $extended = [];

    foreach ($segments as $segment) {
      $pair = explode('=', $segment, 2);
      if (count($pair) < 2) {
        continue;
      }

      $name = strtolower(trim($pair[0]));
      $raw = trim($pair[1]);
      if (preg_match('/^([^*]+)\*(\d+)?(\*)?$/', $name, $match)) {
        $extended[$match[1]][intval($match[2] ?? 0)] = [self::unquote($raw), isset($match[3]) || !isset($match[2]) || $match[2] === ''];
        continue;
      }

      $plain[$name] = self::decodeHeader(self::unquote($raw));
    }

    foreach ($extended as $name => $sections) {
      $plain[$name] = self::joinExtendedParameter($sections);
    }

    return [$main, $plain];
  }

  /**
   * Parses an address list header (From, To, Cc, Reply-To) into addresses
   * @param string|null $value the raw header value
   * @return Address[] the parsed addresses, invalid entries are skipped
   */
  public static function parseAddressList(?string $value): array
  {
    $addresses = [];
    if ($value === null || trim($value) === '') {
      return $addresses;
    }

    foreach (self::splitOutsideQuotes($value, ',') as $entry) {
      $address = self::parseAddress($entry);
      if ($address !== null) {
        $addresses[] = $address;
      }
    }

    return $addresses;
  }

  /**
   * Decodes a body according to its Content-Transfer-Encoding
   * @param string $data the encoded body
   * @param string $encoding the lowercase transfer encoding, e.g. "base64"
   * @return string the decoded bytes
   */
  public static function decodeTransferEncoding(string $data, string $encoding): string
  {
    return match ($encoding) {
      'base64' => (string) base64_decode($data),
      'quoted-printable' => quoted_printable_decode($data),
      default => $data,
    };
  }

  /**
   * Converts text from the given charset to UTF-8, leaving it untouched if the charset is unknown
   * @param string $data the text in its original charset
   * @param string $charset the declared charset
   * @return string the text as UTF-8
   */
  public static function toUtf8(string $data, string $charset): string
  {
    $charset = strtolower(trim($charset));
    if (in_array($charset, self::UTF8_COMPATIBLE_CHARSETS, true)) {
      return $data;
    }

    try {
      return mb_convert_encoding($data, 'UTF-8', $charset);
    } catch (ValueError) {
      $converted = @iconv($charset, 'UTF-8//IGNORE', $data);
      return $converted !== false ? $converted : $data;
    }
  }

  /**
   * Decodes a run of adjacent encoded-words. Whitespace between them is dropped and the bytes of
   * consecutive words with the same charset are joined before conversion, since senders may split
   * a multibyte character across two words.
   * @param string $run one or more encoded-words separated by whitespace
   * @return string the decoded UTF-8 text
   */
  protected static function decodeEncodedWordRun(string $run): string
  {
    preg_match_all('/' . self::ENCODED_WORD_PATTERN . '/', $run, $words, PREG_SET_ORDER);
    $result = '';
    $bytes = '';
    $charset = '';

    foreach ($words as [, $wordCharset, $encoding, $text]) {
      if (strcasecmp($wordCharset, $charset) !== 0) {
        $result .= self::toUtf8($bytes, $charset);
        $bytes = '';
        $charset = $wordCharset;
      }
      $bytes .= strtoupper($encoding) === 'B' ? (string) base64_decode($text) : quoted_printable_decode(str_replace('_', ' ', $text));
    }

    return $result . self::toUtf8($bytes, $charset);
  }

  /**
   * Parses a single address like '"Doe, John" <john@example.com>' or 'john@example.com (John)'
   * @param string $entry one entry of an address list
   * @return Address|null the address or null if the entry contains none
   */
  protected static function parseAddress(string $entry): ?Address
  {
    $entry = trim($entry);
    if (preg_match('/^(.*)<([^>]*)>\s*$/s', $entry, $match)) {
      $email = trim($match[2]);
      $name = self::decodeHeader(self::unquote(trim($match[1])));
    } else {
      $name = preg_match('/\(([^)]*)\)/', $entry, $comment) ? self::decodeHeader($comment[1]) : '';
      $email = trim(preg_replace('/\([^)]*\)/', '', $entry));
    }

    if (!str_contains($email, '@')) {
      return null;
    }

    return new Address($email, $name);
  }

  /**
   * Joins RFC 2231 parameter sections (name*0*=, name*1*=) and decodes them to UTF-8
   * @param array<int, array{0: string, 1: bool}> $sections value and "is percent-encoded" flag by section index
   * @return string the decoded parameter value
   */
  protected static function joinExtendedParameter(array $sections): string
  {
    ksort($sections);
    $charset = '';
    $value = '';

    foreach ($sections as $index => [$section, $encoded]) {
      if ($encoded && $index === array_key_first($sections) && preg_match("/^([^']*)'[^']*'(.*)$/s", $section, $match)) {
        $charset = $match[1];
        $section = $match[2];
      }
      $value .= $encoded ? rawurldecode($section) : $section;
    }

    return self::toUtf8($value, $charset);
  }

  /**
   * Splits a header value on a delimiter that is not inside quotes, angle brackets or comments
   * @param string $value the header value
   * @param string $delimiter a single character delimiter
   * @return string[] the segments, untrimmed
   */
  protected static function splitOutsideQuotes(string $value, string $delimiter): array
  {
    $segments = [];
    $current = '';
    $inQuotes = false;
    $depth = 0;
    $length = strlen($value);

    for ($i = 0; $i < $length; $i++) {
      $char = $value[$i];
      if ($char === '\\' && $inQuotes && $i + 1 < $length) {
        $current .= $char . $value[++$i];
        continue;
      }
      if ($char === '"') {
        $inQuotes = !$inQuotes;
      } elseif (!$inQuotes && ($char === '<' || $char === '(')) {
        $depth++;
      } elseif (!$inQuotes && ($char === '>' || $char === ')')) {
        $depth = max(0, $depth - 1);
      } elseif ($char === $delimiter && !$inQuotes && $depth === 0) {
        $segments[] = $current;
        $current = '';
        continue;
      }
      $current .= $char;
    }

    $segments[] = $current;
    return $segments;
  }

  /**
   * Removes surrounding double quotes and backslash escapes from a value
   * @param string $value a possibly quoted string
   * @return string the unquoted value
   */
  protected static function unquote(string $value): string
  {
    if (strlen($value) < 2 || $value[0] !== '"' || !str_ends_with($value, '"')) {
      return $value;
    }

    return preg_replace('/\\\\(.)/s', '$1', substr($value, 1, -1));
  }
}
