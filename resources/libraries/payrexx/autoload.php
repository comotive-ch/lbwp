<?php
// Basically just run an auto loader to be able to access classes
spl_autoload_register(function($class) {
  $root = __DIR__;
  $classFile = $root . '/lib/' . str_replace('\\', '/', $class) . '.php';
  if (file_exists($classFile)) require_once $classFile;
});