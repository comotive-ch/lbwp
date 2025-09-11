<?php

namespace LBWP\Util;

/**
 * Utility functions to work with arrays or modify or sort an array
 * @author Michael Sebel <michael@comotive.ch>
 */
class ArrayManipulation
{
  /**
   * Sorts an associative array by its "count" field
   * @param array $a the first author to compare
   * @param array $b the second author to compare
   * @return int usort return value 0, 1 or -1
   */
  public static function sortByCount($a, $b)
  {
    if ($a['count'] == $b['count']) {
      return 0;
    } else {
      if ($a['count'] > $b['count']) {
        return -1;
      } else {
        return 1;
      }
    }
  }

  /**
   * Explodes by delimiter, but only the first delimiter counts
   * @param $delimiter
   * @param $string
   * @return array
   */
  public static function explodeFirst($delimiter, $string)
  {
    $results = explode($delimiter, $string);
    $first = array_shift($results);
    return array($first, implode($delimiter, $results));
  }

  /**
   * @param $array
   * @return array
   */
  public static function convertToKeys($array)
  {
    $keys = array();
    foreach ($array as $value) {
      $keys[$value] = true;
    }

    return $keys;
  }

  /**
   * Orders array by a subkey that must be numeric (DESC)
   * @param $arr
   * @param $field
   */
  public static function sortByNumericField(&$arr, $field)
  {
    usort($arr, function ($a, $b) use ($field) {
      if ($a[$field] > $b[$field]) {
        return 1;
      } else if ($a[$field] < $b[$field]) {
        return -1;
      }
      return 0;
    });
  }

  /**
   * Orders array by a subkey that must be numeric (ASC)
   * @param $arr
   * @param $field
   */
  public static function sortByNumericFieldAsc(&$arr, $field)
  {
    usort($arr, function ($a, $b) use ($field) {
      if ($a[$field] > $b[$field]) {
        return -1;
      } else if ($a[$field] < $b[$field]) {
        return 1;
      }
      return 0;
    });
  }

  /**
   * @param \WC_Meta_data $meta
   * @return array
   */
  public static function forceWcMetaArray($meta)
  {
    $return = array();
    foreach ($meta as $item) {
      $return[] = $item->get_data()['value'];
    }

    return $return;
  }

  /**
   * Orders array by a subkey that must be numeric
   * @param $arr
   * @param $field
   */
  public static function sortByNumericFieldPreserveKeys(&$arr, $field)
  {
    uasort($arr, function ($a, $b) use ($field) {
      if ($a[$field] > $b[$field]) {
        return 1;
      } else if ($a[$field] < $b[$field]) {
        return -1;
      }
      return 0;
    });
  }

  /**
   * Orders array by a subkey that must be a string
   * @param $arr
   * @param $field
   */
  public static function sortByStringField(&$arr, $field)
  {
    usort($arr, function ($a, $b) use ($field) {
      return strcmp($a[$field], $b[$field]);
    });
  }

  /**
   * Orders array by a subkey that must be a string
   * @param $arr
   * @param $field
   */
  public static function sortByStringFieldPreserveKeys(&$arr, $field)
  {
    uasort($arr, function ($a, $b) use ($field) {
      return strcmp($a[$field], $b[$field]);
    });
  }

  /**
   * Create a new array with unmaintained primitive indexes, intval'ing every object
   * @param array $values
   * @return array the new values array
   */
  public static function getIntArray($values)
  {
    $newValues = array();
    foreach ($values as $value) {
      $newValues[] = intval($value);
    }

    return $newValues;
  }

  /**
   * Tells if any value of a matches any value of b
   * @param array $a
   * @param array $b
   * @return true if the arrays have at least one common value
   */
  public static function anyValueMatch($a, $b)
  {
    foreach ($a as $ac) {
      foreach ($b as $bc) {
        if ($ac == $bc) {
          return true;
        }
      }
    }

    return false;
  }

  /**
   * Tells if all value of a matches values in b
   * @param array $a
   * @param array $b
   * @return true if a is completely available in b
   */
  public static function allValueMatch($a, $b)
  {
    $matches = 0;
    foreach ($a as $ac) {
      foreach ($b as $bc) {
        if ($ac == $bc) {
          $matches++;
        }
      }
    }

    return $matches == count($a);
  }

  /**
   * @param $a
   * @param $b
   * @return bool
   */
  public static function isIdentical($a, $b)
  {
    return md5(json_encode($a)) == md5(json_encode($b));
  }

  /**
   * @param array $values
   * @return true if all values are non empty strings
   */
  public static function valuesNonEmptyStrings($values)
  {
    foreach ($values as $value) {
      if (strlen($value) == 0) {
        return false;
      }
    }

    return true;
  }

  /**
   * A-Z array, can be used to stuff objects in to A-Z containers
   * @param bool $lowerCase tells if the assoc keys should be lower case chars
   * @return array an assoc array with A-Z as keys. contains an empty array for every letter
   */
  public static function getAtoZArray($lowerCase = false)
  {
    $result = array();
    $letters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    if ($lowerCase) {
      $letters = strtolower($letters);
    }

    for ($i = 0; $i < strlen($letters); ++$i) {
      $result[$letters[$i]] = array();
    }

    return $result;
  }

  /**
   * @param array $items
   * @param string $sortField the sort field
   * @return array a-z array with items in corresponding sub array
   */
  public static function createAtoZArray($items, $sortField)
  {
    $result = self::getAtoZArray();

    foreach ($items as $item) {
      // Convert to object, if needed
      if (is_array($item)) {
        $item = json_decode(json_encode($item));
      }

      // Get first char of the item
      $char = strtoupper($item->{$sortField}[0]);
      $result[$char][] = $item;
    }

    return $result;
  }

  /**
   * forces the $value to be an array, if not it returns an empty array
   * @param mixed $value input value
   * @return array $value if array, or an empty array
   */
  public static function forceArray($value)
  {
    if (!is_array($value)) {
      $value = array();
    }

    return $value;
  }

  /**
   * forces the $value to be an array, if not it returns an empty array
   * @param mixed $value input value
   * @return array $value if array, or an empty array
   */
  public static function forceArrayAndInclude($value)
  {
    if (!is_array($value)) {
      $value = array($value);
    }

    return $value;
  }

  /**
   * @param mixed $value can be an array or any other type
   * @return mixed always the value or the first element in the array of value
   */
  public static function forceSingleValue($value)
  {
    if (is_array($value) && count($value) > 0) {
      return $value[0];
    }

    return $value;
  }

  /**
   * @param $array
   * @param array $params
   * @param callable $method
   * @return mixed
   */
  public static function mapRecursive($array, $params = array(), callable $method = null)
  {
    array_walk_recursive($array, function (&$v) use ($method, $params) {
      $v = $method($v, $params);
    });
    return $array;
  }

  /**
   * @param $array
   * @param $search
   * @param $replace
   * @return array deep replaced array
   */
  public static function deepReplace($search, $replace, $array)
  {
    $params = array('s' => $search, 'r' => $replace);
    return self::mapRecursive($array, $params, function ($value, $params) {
      if (is_string($value)) {
        return str_replace($params['s'], $params['r'], $value);
      } else {
        return $value;
      }
    });
  }

  /**
   * Merges an array and its subarrays
   * @return array
   */
  public static function deepMerge()
  {
    switch (func_num_args()) {
      case 0: // Nothing to merge
        return array();
        break;

      case 1: //Only one array to merge, so we return it
        return func_get_arg(0);
        break;

      case 2:
        //Here starts the magic
        $result = func_get_arg(0);
        foreach (func_get_arg(1) as $key => $value) { //Go through every key of the second array
          if (is_array($value)) { // If the value is an array, that make a recursive call
            $result[$key] = self::deepMerge($result[$key], $value);
          } else { //Overwrite the value
            $result[$key] = $value;
          }
        }
        return $result;
        break;

      default: //There are more than two arrays so we call that function recursivly
        $result = func_get_arg(0);
        $max = func_num_args();
        for ($i = 1; $i < $max; $i++) {
          $arg = func_get_arg($i);
          $result = self::deepMerge($result, $arg);
        }
        return $result;
        break;
    }
  }

  /**
   * @param array $array1
   * @param null $array2
   * @return array
   */
  public static function &mergeRecursiveDistinct(array $array1, $array2 = null)
  {
    $merged = $array1;

    if (is_array($array2)) {
      foreach ($array2 as $key => $val) {
        if (is_array($array2[$key])) {
          $merged[$key] = is_array($merged[$key]) ? self::mergeRecursiveDistinct($merged[$key], $array2[$key]) : $array2[$key];
        } else {
          $merged[$key] = $val;
        }
      }
    }
    return $merged;
  }

  /**
   * @param $sxe
   * @return array
   */
  public static function convertSimpleXmlElement($sxe)
  {
    $returnArray = array();
    foreach ((array)$sxe as $key => $value) {
      if (is_array($value) && !(bool)count(array_filter(array_keys($value), 'is_string'))) {
        $indies = array();
        foreach ($value as $secondkey => $secondvalue)
          $indies[$secondkey] = self::convertSimpleXmlElement($secondvalue);
        $returnArray[$key] = $indies;
      } else {
        if (is_object($value)) {
          $returnArray[$key] = self::convertSimpleXmlElement($value);
        } else {
          $returnArray[$key] = $value;
        }
      }
    }
    return $returnArray;
  }

  /**
   * Better than convertSimpleXmlElement which is just there for compatibility
   * @param \SimpleXMLElement $xml
   * @return array
   */
  public static function xmlToArray(\SimpleXMLElement $xml)
  {
    $parser = function (\SimpleXMLElement $xml, array $collection = []) use (&$parser) {
      $nodes = $xml->children();
      $attributes = $xml->attributes();

      if (0 !== count($attributes)) {
        foreach ($attributes as $attrName => $attrValue) {
          $collection['attributes'][$attrName] = strval($attrValue);
        }
      }

      if (0 === $nodes->count()) {
        $collection['value'] = strval($xml);
        return $collection;
      }

      foreach ($nodes as $nodeName => $nodeValue) {
        if (count($nodeValue->xpath('../' . $nodeName)) < 2) {
          $collection[$nodeName] = $parser($nodeValue);
          continue;
        }

        $collection[$nodeName][] = $parser($nodeValue);
      }

      return $collection;
    };

    return [
      $xml->getName() => $parser($xml)
    ];
  }

  /**
   * Can be used in usort and uksort, orders posts array by their publish date
   * @param \WP_Post $p1 the left post
   * @param \WP_Post $p2 the right post
   */
  public static function sortByPostDateDesc($p1, $p2)
  {
    // Get the times first, most efficient way with out library
    $pd1 = Date::getStamp(Date::SQL_DATETIME, $p1->post_date);
    $pd2 = Date::getStamp(Date::SQL_DATETIME, $p2->post_date);
    // Do the comparison
    if ($pd1 > $pd2) {
      return -1;
    } else if ($pd1 < $pd2) {
      return 1;
    }
    return 0;
  }

  /**
   * Can be used in usort and uksort, orders posts array by their publish date
   * @param \WP_Post $p1 the left post
   * @param \WP_Post $p2 the right post
   */
  public static function sortByPostDateAsc($p1, $p2)
  {
    // Get the times first, most efficient way with out library
    $pd1 = Date::getStamp(Date::SQL_DATETIME, $p1->post_date);
    $pd2 = Date::getStamp(Date::SQL_DATETIME, $p2->post_date);
    // Do the comparison
    if ($pd1 > $pd2) {
      return -1;
    } else if ($pd1 < $pd2) {
      return 1;
    }
    return 0;
  }

  /**
   * @param \stdClass[] $terms list of terms
   * @param string $field name, slug, term_id
   * @return array of single strings fromt he $field param
   */
  public static function getSimpleTermList($terms, $field)
  {
    $list = array();
    if (is_array($terms)) {
      foreach ($terms as $term) {
        $list[] = $term->{$field};
      }
    }

    return $list;
  }

  /**
   * @param $terms
   * @return array
   */
  public static function reorderParentChildTerns($terms)
  {
    // if terms is not array or its empty don't proceed
    if (!is_array($terms) || empty($terms)) {
      return false;
    }

    $new = array();
    foreach ($terms as $key => $term) {
      if ($term->parent == 0) {
        $new[] = $term;
        // See if we need to bring in children
        foreach ($terms as $childkey => $child) {
          if ($term->term_id == $child->parent) {
            $new[] = $child;
            unset($terms[$childkey]);
          }
        }
      }
    }

    return $new;
  }


  /**
   * @param $string
   * @param $array
   * @param string $regex
   * @return mixed|null
   */
  public static function stringToIndex($string, $array, $regex = '\[([\w\s\d-]+)\]'){
    // remove any quotation marks
    $string = str_replace(array('"', '\''), '', $string);

    // search for indexes
    $checkMatches = preg_match_all('/' . $regex . '/', $string, $matches);

    if (!$checkMatches) {
      return null;
    }

    $curData = $array;
    foreach($matches[1] as $index){
      if(key_exists($index, $curData)){
        $curData = $curData[$index];
      }else{
        return null;
      }
    }

    return $curData;
  }

  /**
   * @param \WP_Post[] $posts
   * @return array of integers
   */
  public static function getPostIds($posts)
  {
    $postIds = array();
    foreach ($posts as $post) {
      $postIds[] = $post->ID;
    }
    return $postIds;
  }

  /**
   * @param \WC_Product[] $products
   * @return array of integers
   */
  public static function getProductIds($products)
  {
    $postIds = array();
    foreach ($products as $product) {
      $postIds[] = $product->get_id();
    }
    return $postIds;
  }

  /**
   * @param $separator
   * @param $word
   * @param $list
   * @return string
   */
  public static function humanSentenceImplode($separator, $word, $list)
  {
    // Get the last element to preserve
    $last = array_pop($list);
    $string = implode($separator, $list);
    return $string . ' ' . $word . ' ' . $last;
  }

  /**
   * @param $list
   * @param $key
   * @return array
   */
  public static function getSpecifiedKeyArray($list, $key)
  {
    $result = array();
    foreach ($list as $entry) {
      $result[] = $entry[$key];
    }
    return array_filter($result);
  }
} 