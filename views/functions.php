<?php

use LBWP\Core;

/**
 * checks if the current page is the login page
 */
if (!function_exists('is_login')) {
  function is_login()
  {
    if (stristr($_SERVER['REQUEST_URI'], 'wp-login.php') !== false) {
      return true;
    }
    return false;
  }
}

if (!function_exists('idn_to_ascii')) {
  function idn_to_ascii($domain) {
    return preg_replace_callback('/[^\x00-\x7F]/', function ($matches) {
      return 'xn--' . bin2hex(iconv('UTF-8', 'ASCII//TRANSLIT', $matches[0]));
    }, $domain);
  }
}
/**
 * instead of checking and slicing the arguments a callable can accept before calling it,
 * provide helper function
 *
 * @param callable $callable
 * @param array $arguments
 * @return mixed|null
 */
function callUserFunctionWithSafeArguments($callable, $arguments = array())
{
  $result = null;
  if (is_callable($callable)) {
    $safeArguments = array_slice($arguments, 0, numberOfCallableArguments($callable));
    // callback for media container
    $result = call_user_func_array($callable, $safeArguments);
  }
  return $result;
}

/**
 * return the number of arguments of a callable
 * needs at least php v5.3.2
 *
 * @url http://lfhck.com/question/208948/how-to-get-the-number-of-parameters-of-a-run-time-determined-callable
 *
 * @param callable $callable
 * @return int
 */
function numberOfCallableArguments($callable)
{
  $number = 0;
  if (is_callable($callable)) {
    try {
      if (is_array($callable)) {
        $reflector = new ReflectionMethod($callable[0], $callable[1]);
      } elseif (is_string($callable)) {
        $reflector = new ReflectionFunction($callable);
      } elseif (is_a($callable, 'Closure')) {
        $objReflector = new ReflectionObject($callable);
        $reflector = $objReflector->getMethod('__invoke');
      }
      $parameters = $reflector->getParameters();
      $number = count($parameters);
    } catch (Exception $e) {
    }
  }
  return $number;
}

/**
 * Basically ++, but as a callback
 */
function __return_plus_one($value) {
  return ++$value;
}

/**
 * Basically --, but as a callback
 */
function __return_minus_one($value) {
  return --$value;
}

/**
 * @param $checkers
 * @return array
 */
function lbwp_addWeglotDomCheckers($checkers)
{
  class wglt_select_placeholder extends \Weglot\Parser\Check\Dom\AbstractDomChecker {
    const DOM       = 'select';
    const PROPERTY  = 'data-placeholder';
    const WORD_TYPE = \Weglot\Client\Api\Enum\WordType::TEXT;
  }
  class wglt_form_multi_message extends \Weglot\Parser\Check\Dom\AbstractDomChecker {
    const DOM       = 'form';
    const PROPERTY  = 'data-message-multi';
    const WORD_TYPE = \Weglot\Client\Api\Enum\WordType::TEXT;
  }
  class wglt_form_single_message extends \Weglot\Parser\Check\Dom\AbstractDomChecker {
    const DOM       = 'form';
    const PROPERTY  = 'data-message-single';
    const WORD_TYPE = \Weglot\Client\Api\Enum\WordType::TEXT;
  }
  class wglt_input_errormsg extends \Weglot\Parser\Check\Dom\AbstractDomChecker {
    const DOM       = 'input';
    const PROPERTY  = 'data-errormsg';
    const WORD_TYPE = \Weglot\Client\Api\Enum\WordType::TEXT;
  }
  class wglt_input_warningmsg extends \Weglot\Parser\Check\Dom\AbstractDomChecker {
    const DOM       = 'input';
    const PROPERTY  = 'data-warningmsg';
    const WORD_TYPE = \Weglot\Client\Api\Enum\WordType::TEXT;
  }
  class wglt_textarea_errormsg extends \Weglot\Parser\Check\Dom\AbstractDomChecker {
    const DOM       = 'textarea';
    const PROPERTY  = 'data-errormsg';
    const WORD_TYPE = \Weglot\Client\Api\Enum\WordType::TEXT;
  }
  class wglt_textarea_warningmsg extends \Weglot\Parser\Check\Dom\AbstractDomChecker {
    const DOM       = 'textarea';
    const PROPERTY  = 'data-warningmsg';
    const WORD_TYPE = \Weglot\Client\Api\Enum\WordType::TEXT;
  }
  $checkers[] = 'wglt_select_placeholder';
  $checkers[] = 'wglt_form_multi_message';
  $checkers[] = 'wglt_form_single_message';
  $checkers[] = 'wglt_input_errormsg';
  $checkers[] = 'wglt_input_warningmsg';
  $checkers[] = 'wglt_textarea_errormsg';
  $checkers[] = 'wglt_textarea_warningmsg';
  return $checkers ;
}

if (!function_exists('getLbwpHost')) {
  function getLbwpHost()
  {
    return $_SERVER['HTTP_HOST'];
  }
}

if (!function_exists('getHtmlCacheGroup')) {
  function getHtmlCacheGroup()
  {
    if ($_SERVER['HTTPS']) {
      return 'htmlCacheHttps';
    } else {
      return 'htmlCache';
    }
  }
}