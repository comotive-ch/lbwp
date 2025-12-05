<?php

namespace LBWP\Helper;

class SpamCheck
{
  /**
   * Check if the text content is spam
   * @param string $text The text to check
   * @return bool True if spam detected, false otherwise
   */
  public static function evaluate($text)
  {
    // It's most likely spam if very short
    // TODO maybe remove this check once this method is more reliable or used more often
    if (strlen($text) <= 20) {
      return false;
    }

    $spamScore = 0;
    // Analyze the combined text for gibberish
    $quality = self::analyzeTextQuality($text);
    $patternScore = self::analyzeRandomPatterns($text);
    $gibberishScore = self::analyzeGibberishPatterns($text);

    // Scoring: Higher = more suspicious
    if ($quality['quality'] === 'suspicious') {
      $spamScore += 2;
    }
    // Additional scoring for extremely low/high vowel ratios
    $vowelRatio = $quality['vowel_ratio'] ?? 0;
    if ($vowelRatio < 0.20 || $vowelRatio > 0.60) {
      $spamScore += 1;
    }
    // Random patterns also raise the spam score significantly
    if ($patternScore >= 2) {
      $spamScore += 3;
    }
    // Gibberish patterns (long words with consonant clusters, etc.)
    if ($gibberishScore >= 3) {
      $spamScore += 3;
    } elseif ($gibberishScore >= 2) {
      $spamScore += 2;
    }

    // If spam score is high enough mark as spam
    return $spamScore >= 4;
  }

  /**
   * Analyze text quality based on vowel/consonant ratio
   * @param string $text
   * @return array Array with quality assessment and vowel ratio
   */
  public static function analyzeTextQuality($text)
  {
    $text = strtolower(preg_replace('/[^a-zA-Z\s]/', '', $text));
    if (strlen($text) < 3) return ['quality' => 'too_short'];

    $vowels = preg_match_all('/[aeiou]/', $text);
    $consonants = preg_match_all('/[bcdfghjklmnpqrstvwxyz]/', $text);
    $total = $vowels + $consonants;

    if ($total == 0) return ['quality' => 'no_letters'];

    $vowel_ratio = $vowels / $total;
    // Normal German/English text has ~35-45% vowels
    $is_natural = ($vowel_ratio >= 0.25 && $vowel_ratio <= 0.55);

    return [
      'quality' => $is_natural ? 'natural' : 'suspicious',
      'vowel_ratio' => $vowel_ratio,
      'total_letters' => $total
    ];
  }

  /**
   * Analyze random patterns in text
   * @param string $text
   * @return int Number of suspicious patterns found
   */
  public static function analyzeRandomPatterns($text)
  {
    $suspicious_patterns = [
      'repeated_chars' => preg_match('/(.)\1{3,}/', $text), // aaaa, bbbb
      'keyboard_mashing' => preg_match('/[qwertyuiopasdfghjklzxcvbnm]{8,}/', strtolower($text)),
      'alternating_case' =>  preg_match('/([A-Z][a-z]){3,}|([a-z][A-Z]){3,}/', $text),
      'excessive_numbers' => (preg_match_all('/\d/', $text) / strlen($text)) > 0.5,
      'no_spaces_long' => strlen(preg_replace('/\s/', '', $text)) > 20 && !preg_match('/\s/', $text)
    ];

    return array_sum($suspicious_patterns);
  }

  /**
   * Detect gibberish patterns like "QYYwIjlqOFlsDZZoEn KEawkLRyOnFBOaEdqo"
   * @param string $text
   * @return int Score indicating likelihood of gibberish (higher = more suspicious)
   */
  public static function analyzeGibberishPatterns($text)
  {
    $score = 0;
    $words = preg_split('/\s+/', trim($text));

    if (count($words) < 2) {
      return 0; // Not enough words to analyze
    }

    $longWords = 0;
    $wordsWithConsonantClusters = 0;
    $wordsWithCapitalPattern = 0;
    $totalWords = count($words);

    foreach ($words as $word) {
      $cleanWord = preg_replace('/[^a-zA-Z]/', '', $word);
      $wordLength = strlen($cleanWord);

      if ($wordLength < 3) continue;

      // Check for unusually long words (>15 chars is suspicious)
      if ($wordLength > 15) {
        $longWords++;
      }

      // Check for excessive consonant clusters (3+ consonants in a row)
      // Real words rarely have ZZZ, DDS, etc.
      if (preg_match('/[bcdfghjklmnpqrstvwxyzBCDFGHJKLMNPQRSTVWXYZ]{3,}/', $cleanWord)) {
        $wordsWithConsonantClusters++;
      }

      // Check for capital letter followed by lowercase in gibberish pattern
      // Example: "QYYwIjlq" - capital followed by multiple lowercase without clear word structure
      if (preg_match('/^[A-Z][a-z]+[A-Z]/', $cleanWord) || preg_match('/[A-Z]{2,}[a-z]/', $cleanWord)) {
        $wordsWithCapitalPattern++;
      }
    }

    // Calculate ratios
    $longWordRatio = $totalWords > 0 ? $longWords / $totalWords : 0;
    $consonantClusterRatio = $totalWords > 0 ? $wordsWithConsonantClusters / $totalWords : 0;
    $capitalPatternRatio = $totalWords > 0 ? $wordsWithCapitalPattern / $totalWords : 0;

    // Scoring based on ratios
    if ($longWordRatio > 0.5) {
      $score += 2; // More than half the words are unusually long
    }

    if ($consonantClusterRatio > 0.4) {
      $score += 3; // Many words have excessive consonant clusters
    }

    if ($capitalPatternRatio > 0.3) {
      $score += 1; // Unusual capitalization patterns
    }

    // Check average word length - gibberish tends to have very long words
    $totalLength = array_sum(array_map(function($w) {
      return strlen(preg_replace('/[^a-zA-Z]/', '', $w));
    }, $words));
    $avgWordLength = $totalWords > 0 ? $totalLength / $totalWords : 0;

    if ($avgWordLength > 12) {
      $score += 2; // Average word length is suspiciously high
    }

    return $score;
  }
}