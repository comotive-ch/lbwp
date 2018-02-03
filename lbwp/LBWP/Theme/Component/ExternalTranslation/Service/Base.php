<?php

namespace LBWP\Theme\Component\ExternalTranslation\Service;

/**
 * The basic service class for implementation of translations
 * @package LBWP\Theme\Component\ExternalTranslation\Service
 */
abstract class Base
{
  /**
   * @var array the configuration of the service
   */
  protected $config = array();

  /**
   * The constructor receives the main service configuration
   * @param array $config the configuration array
   */
  public function __construct($config)
  {
    $this->config = $config;
  }

  /**
   * Registers functions directly on class loading (after_setup_theme(0))
   */
  abstract public function load();

  /**
   * @param string $language the two char language code
   * @return bool tells if the language given is supported as source/destination
   */
  abstract public function isSupportedLanguage($language);

  /**
   * @param \WP_Post $original the original post
   * @param string $source the source language
   * @param string $destination the destination language
   * @return int the result / translation request id
   */
  abstract public function requestTranslation($original, $source, $destination);

  /**
   * @param int $postId the post id
   * @param int $translationId the translation id
   * @return bool true or false if the cancellation worked
   */
  abstract public function cancelTranslationRequest($postId, $translationId);

  /**
   * @param int $postId the post id
   * @param int $translationId the translation id
   * @return bool true, if the translation actually worked
   */
  abstract public function receiveTranslation($postId, $translationId);
}