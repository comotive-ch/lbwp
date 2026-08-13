<?php

namespace LBWP\Helper;

/**
 * Heuristic spam detection for user generated text, mainly form submissions
 * @package LBWP\Helper
 * @author Michael Sebel <michael@comotive.ch>
 */
class SpamCheck
{
  /**
   * @var int the score from which on a text is considered spam
   */
  const SPAM_THRESHOLD = 4;
  /**
   * @var int texts shorter than this are not evaluated at all
   */
  const MIN_TEXT_LENGTH = 10;
  /**
   * @var int words shorter than this are skipped in the word randomness analysis
   */
  const MIN_WORD_LENGTH = 8;
  /**
   * @var int score from which on a single word is considered random gibberish
   */
  const WORD_GIBBERISH_THRESHOLD = 2;

  /**
   * Tells if the reinforced spam checks are switched on. As long as the constant is
   * not defined, the detection behaves exactly as before, so the additional checks
   * can be enabled per site while they are being evaluated on production.
   * @return bool true if the reinforced checks should be executed
   */
  public static function isReinforcedActive()
  {
    return defined('REINFORCED_SPAMCHECK_ACTIVE') && REINFORCED_SPAMCHECK_ACTIVE;
  }

  /**
   * Check if the text content is spam
   * @param string $text The text to check
   * @return bool True if spam detected, false otherwise
   */
  public static function evaluate($text)
  {
    // Too short to be evaluated reliably, never mark as spam
    if (strlen($text) < self::MIN_TEXT_LENGTH) {
      return false;
    }

    return self::getScore($text) >= self::SPAM_THRESHOLD;
  }

  /**
   * Evaluate a set of individually submitted values. Looking at every value on its
   * own is much more reliable than looking at the concatenated text of a whole form,
   * because a few random tokens get diluted by legitimate boilerplate like the text
   * of a privacy checkbox.
   * @param array $values list of submitted values
   * @return array with keys isSpam, spamFields, maxScore and combinedScore
   */
  public static function evaluateFields($values)
  {
    $spamFields = 0;
    $maxScore = 0;

    foreach ($values as $value) {
      if (!is_scalar($value) || strlen($value) < self::MIN_TEXT_LENGTH) {
        continue;
      }

      $score = self::getScore($value);
      $maxScore = max($maxScore, $score);
      if ($score >= self::SPAM_THRESHOLD) {
        ++$spamFields;
      }
    }

    $combinedScore = self::getScore(implode(' ', array_filter($values, 'is_scalar')));

    return [
      // Either multiple fields are random on their own, or the whole text is spammy
      'isSpam' => $spamFields >= 2 || $combinedScore >= self::SPAM_THRESHOLD,
      'spamFields' => $spamFields,
      'maxScore' => $maxScore,
      'combinedScore' => $combinedScore
    ];
  }

  /**
   * Calculate the full spam score of a text, higher means more suspicious
   * @param string $text The text to check
   * @return int the accumulated spam score
   */
  public static function getScore($text)
  {
    $spamScore = 0;
    // Empty or very short texts can not be analyzed reliably and never score
    if (strlen(trim($text)) < self::MIN_TEXT_LENGTH) {
      return 0;
    }

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
    // Per word randomness, this also catches very short submissions
    if (self::isReinforcedActive()) {
      $spamScore += self::getWordRandomnessScore(self::analyzeWordRandomness($text));
    }

    return $spamScore;
  }

  /**
   * Turn the word randomness analysis into a spam score contribution
   * @param array $randomness result of analyzeWordRandomness
   * @return int the score contribution
   */
  protected static function getWordRandomnessScore($randomness)
  {
    // Not enough analyzable words, no statement possible
    if ($randomness['checkedWords'] == 0 || $randomness['gibberishWords'] == 0) {
      return 0;
    }

    // Multiple random tokens making up at least half of the text
    if ($randomness['gibberishWords'] >= 2 && $randomness['ratio'] >= 0.5) {
      return 4;
    }

    // Very short submissions consisting solely of random tokens
    if ($randomness['checkedWords'] <= 2 && $randomness['ratio'] == 1) {
      return 4;
    }

    // Elevated but not conclusive amount of random tokens
    if ($randomness['ratio'] >= 0.4) {
      return 2;
    }

    return 0;
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
    $length = strlen($text);
    if ($length == 0) {
      return 0;
    }

    $suspicious_patterns = [
      'repeated_chars' => preg_match('/(.)\1{3,}/', $text), // aaaa, bbbb
      'keyboard_mashing' => preg_match('/[qwertyuiopasdfghjklzxcvbnm]{8,}/', strtolower($text)),
      'alternating_case' =>  preg_match('/([A-Z][a-z]){3,}|([a-z][A-Z]){3,}/', $text),
      'excessive_numbers' => (preg_match_all('/\d/', $text) / $length) > 0.5,
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

  /**
   * Analyze how many of the words look like randomly generated tokens.
   * Unlike analyzeGibberishPatterns this works on single words as well.
   * @param string $text the text to analyze
   * @return array with keys gibberishWords, checkedWords, ratio and words
   */
  public static function analyzeWordRandomness($text)
  {
    $gibberishWords = [];
    $checkedWords = 0;

    // Split on everything that is not a letter, so email addresses, phone numbers
    // and punctuation break up into harmless short tokens instead of long ones
    $words = preg_split('/[^a-zA-Z]+/', self::normalizeText($text), -1, PREG_SPLIT_NO_EMPTY);

    foreach ($words as $word) {
      if (strlen($word) < self::MIN_WORD_LENGTH) {
        continue;
      }

      ++$checkedWords;
      if (self::scoreWord($word) >= self::WORD_GIBBERISH_THRESHOLD) {
        $gibberishWords[] = $word;
      }
    }

    return [
      'gibberishWords' => count($gibberishWords),
      'checkedWords' => $checkedWords,
      'ratio' => $checkedWords > 0 ? count($gibberishWords) / $checkedWords : 0,
      'words' => $gibberishWords
    ];
  }

  /**
   * Score a single word on how likely it is a randomly generated token
   * @param string $word the raw word, may contain umlauts and punctuation
   * @return int the score, higher means more likely random
   */
  public static function scoreWord($word)
  {
    $word = self::normalizeWord($word);
    $length = strlen($word);

    if ($length < self::MIN_WORD_LENGTH) {
      return 0;
    }

    $score = 0;
    // Case switches inside the word are the strongest signal for random tokens.
    // The leading capital is ignored, so "Anfrage" doesn't count as a switch.
    $caseSwitches = preg_match_all('/(?<=[a-z])[A-Z]|(?<=[A-Z])[a-z]/', substr($word, 1));
    if ($caseSwitches >= 3) {
      $score += 2;
    } elseif ($caseSwitches == 2) {
      $score += 1;
    }

    // Natural language keeps a fairly stable vowel ratio, random tokens don't
    $vowelRatio = preg_match_all('/[aeiouy]/i', $word) / $length;
    if ($vowelRatio < 0.25) {
      $score += 1;
    }
    if ($vowelRatio < 0.18) {
      $score += 1;
    }

    // Consonant clusters only count when the word already looks unnatural,
    // otherwise german compounds like "Betriebshaftpflicht" would be flagged
    if ($caseSwitches > 0 || $vowelRatio < 0.25) {
      if (preg_match('/[bcdfghjklmnpqrstvwxz]{5,}/i', $word)) {
        $score += 1;
      }
      if (preg_match_all('/[bcdfghjklmnpqrstvwxz]{4,}/i', $word) >= 2) {
        $score += 1;
      }
    }

    return $score;
  }

  /**
   * Reduce a word to plain latin letters, transliterating umlauts and accents
   * so they don't produce artificial consonant clusters
   * @param string $word the raw word
   * @return string the normalized word
   */
  public static function normalizeWord($word)
  {
    return preg_replace('/[^a-zA-Z]/', '', self::normalizeText($word));
  }

  /**
   * Transliterate umlauts and accents of a text to plain latin letters, keeping
   * all other characters intact so the text can still be tokenized afterwards
   * @param string $text the raw text
   * @return string the transliterated text
   */
  public static function normalizeText($text)
  {
    return strtr($text, [
      'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'Ä' => 'Ae', 'Ö' => 'Oe', 'Ü' => 'Ue', 'ß' => 'ss',
      'à' => 'a', 'á' => 'a', 'â' => 'a', 'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
      'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i', 'ò' => 'o', 'ó' => 'o', 'ô' => 'o',
      'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ç' => 'c', 'ñ' => 'n'
    ]);
  }
}