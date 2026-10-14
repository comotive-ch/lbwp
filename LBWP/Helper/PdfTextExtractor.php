<?php

namespace LBWP\Helper;

/**
 * Extracts the plain text layer of PDF files using the poppler "pdftotext" binary
 * @package LBWP\Helper
 * @author Mirko Baffa <mirko@comotive.ch>
 */
class PdfTextExtractor
{
  /**
   * @var int maximum number of characters returned, keeps LIKE searches on post_content fast
   */
  const MAX_LENGTH = 100000;

  /**
   * Checks if the pdftotext binary is available on this server
   * @return bool true if pdftotext can be called
   */
  public static function isAvailable()
  {
    $path = trim((string) shell_exec('command -v pdftotext 2>/dev/null'));
    return strlen($path) > 0;
  }

  /**
   * Extracts the text of a local PDF file
   * @param string $localFile absolute path to the pdf file
   * @return string the normalized text, empty if the pdf has no text layer or extraction failed
   */
  public static function extractText($localFile)
  {
    if (!is_readable($localFile)) {
      return '';
    }

    $command = 'pdftotext -enc UTF-8 -nopgbrk ' . escapeshellarg($localFile) . ' - 2>/dev/null';
    return self::normalizeText((string) shell_exec($command));
  }

  /**
   * Strips invalid utf-8, collapses whitespace and caps the length of the text
   * @param string $text the raw extracted text
   * @return string the cleaned text
   */
  protected static function normalizeText($text)
  {
    $text = wp_check_invalid_utf8($text, true);
    $text = preg_replace('/\s+/u', ' ', $text);
    return mb_substr(trim($text), 0, self::MAX_LENGTH);
  }
}
