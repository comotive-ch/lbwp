<?php
/**
 * PathSelector parser to parse a layout selector
 * 
 * @package Blogwerk_Newsletter
 * @subpackage Parser
 * @author Matthias Zobrist <matthias.zobrist@blogwerk.com>
 * @copyright Copyright (c) 2013 Blogwerk
 */

namespace LBWP\Util\Parser;

/**
 * PathSelector parser to parse a layout selector
 * 
 * @author Matthias Zobrist <matthias.zobrist@blogwerk.com>
 * @copyright Copyright (c) 2013 Blogwerk
 */
class PathSelector
{
  /**
   * Compares the selector with the pattern and return true
   * if the pattern is matched in the selector
   * 
   * @param string $selector
   * @param string $pattern
   * @return float
   */
  public function compare($selector, $pattern)
  {
    $cleanPattern = $this->cleanPattern($pattern);
    
    $result = $this->compareCleanStrings($selector, $cleanPattern);
    
    return $result;
  }
  
  /**
   * Cleans the pattern. Removes unused spaces and insert the 
   * correct wildcard tags
   * 
   * @param string $pattern
   * @return string
   */
  public function cleanPattern($pattern)
  {
    $pattern = $this->removeArguments($pattern);
    
    $pattern = trim($pattern);
    
    $pattern = preg_replace('/\s{2,}/', ' ', $pattern);
    $pattern = str_replace(' > ', '>', $pattern);
    $pattern = str_replace(' ', '>[?]>', $pattern);
    
    return $pattern;
  }
  
  /**
   * Compars two cleaned strings, the selector with the pattern
   * 
   * @param string $selector
   * @param string $pattern
   * @return float
   */
  public function compareCleanStrings($selector, $pattern)
  {
    $selectorParts = array_reverse(explode('>', $selector));
    $patternParts = array_reverse(explode('>', $pattern));
    
    $patternRelevance = 0;
    $patternIndex = 0;
    foreach ($selectorParts as $selectorIndex => $selectorPart) {
      $patternPart = $patternParts[$patternIndex];
      $baseRelevance = $this->comparePart($selectorPart, $patternPart);
      
      if ($patternPart === '*' || $patternPart === '[?]') {
        // Multi-item wildcard
        if ($patternPart === '[?]') {
          $nextPatternPart = '';
          if ($patternIndex + 1 <= count($patternParts) - 1) {
            $nextPatternPart = $patternParts[$patternIndex + 1];
          }
          
          // Count the rest of parts for the selector and the pattern
          $selectorRest = count($selectorParts) - $selectorIndex;
          $patternRest = count($patternParts) - $patternIndex;

          // Check the next pattern part
          if ($this->comparePart($selectorPart, $nextPatternPart)) {
            // The selector is one step before the pattern so we increment
            // the index with 2
            $patternIndex += 2;
          } else if ($selectorRest < $patternRest && $nextPatternPart !== '*') {
            // If we haven't enough selector parts to make it to the end of the 
            // pattern parts we return false, because the pattern does not match
            return false;
          }
        } else {
          // One-item wildcard
          $patternIndex++;
        }
      } else if ($baseRelevance !== false) {
        // the parts are equal, get the next one!
        $patternIndex++;
        
        $patternRelevance += $baseRelevance;
      } else {
        // The first parts aren't equal, so we break here
        return false;
      }
      
      // If we are at the end of the pattern, we break the loop
      if ($patternIndex === count($patternParts)) {
        break;
      }
    }
    
    return $this->calculateRelevance($pattern, $patternRelevance);
  }

  /**
   * Returns the revelance for the given pattern based on the 
   * number of parts, one-item and multi-item wildcards
   * 
   * @param string $pattern
   * @return float
   */
  public function calculateRelevance($pattern, $patternRelevance)
  {
    $relevance = $patternRelevance;
    
    if (strpos($pattern, '>') !== false) {
      // Count the parts
      $relevance += substr_count($pattern, '>') * 0.5;
      
      // Count the one-item wildcards
      $relevance -= substr_count($pattern, '*') * 0.1;
      
      // Count the multi-item wildcards
      $relevance -= substr_count($pattern, '[?]') * 0.3;
    } else {
      if ($pattern === '*') {
        $relevance += 0.1;
      } else {
        $relevance += 0.5;
      }
    }

    if (strpos($pattern, '#') !== false) {
      $relevance += substr_count($pattern, '#') * 0.7;
    }

    return $relevance;
  }

  /**
   * Extracts the ids of the two parts and compare the items and
   * the ids seperatly
   * 
   * @param string $selectorPart
   * @param string $patternPart
   * @return boolean
   */
  public function comparePart($selectorPart, $patternPart)
  {
    if (strpos($selectorPart, '#') !== false) {
      $selectorItem = substr($selectorPart, 0, strpos($selectorPart, '#'));
      $selectorId = substr($selectorPart, strpos($selectorPart, '#'));
    } else {
      $selectorItem = $selectorPart;
      $selectorId = '';
    }
    
    if (strpos($patternPart, '#') !== false) {
      $patternItem = substr($patternPart, 0, strpos($patternPart, '#'));
      $patternId = substr($patternPart, strpos($patternPart, '#'));
    } else {
      $patternItem = $patternPart;
      $patternId = '';
    }
    
    if (($selectorItem === $patternItem && $selectorId === $patternId) 
        || ($selectorItem === $patternItem && $patternId === '')
        || ($patternItem === '' && $selectorId === $patternId)
    ) {
      if ($selectorId === $patternId) {
        return 1.5;
      } else {
        return 1;
      }
    }
    
    return false;
  }
  
  /**
   * Removes the arguments from a pattern
   * 
   * @param string $pattern
   * @return string
   */
  public function removeArguments($pattern)
  {
    $pattern = preg_replace('/\[([a-zA-Z0-9\-\.\s]*)*\]/is', '', $pattern);
    
    return $pattern;
  }
  
  /**
   * Returns all arguments of the pattern
   * 
   * @param string $pattern
   * @return array
   */
  public function parseArguments($pattern)
  {
    $arguments = array();
    preg_match_all('/\[([a-zA-Z0-9\-\.\s]*)\]/is', $pattern, $argumentsRaw, PREG_SET_ORDER);

    foreach ($argumentsRaw as $argumentRaw) {
      $argument = trim($argumentRaw[1]);
      $arguments[$argument] = true;
    }
    
    return $arguments;
  }
}
