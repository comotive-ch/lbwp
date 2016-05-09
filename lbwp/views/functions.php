<?php

use LBWP\Core;

/**
 * checks if the current page is the login page
 */
function is_login()
{
  if (stristr($_SERVER['REQUEST_URI'],'wp-login.php') !== false) {
    return true;
  }
  return false;
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