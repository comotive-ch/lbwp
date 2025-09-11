<?php

namespace LBWP\Theme\Component\ExternalTranslation\Service;
use LBWP\Util\Strings;

/**
 * The basic service class for implementation of translations
 * @package LBWP\Theme\Component\ExternalTranslation\Service
 */
class Telelingua extends Base
{
  /**
   * @var array cached supported languages for translation
   */
  protected $supportedLanguages = array();
  /**
   * @var array the configuration of the service
   */
  protected $config = array();
  /**
   * @var int string offset to get the request id from their response header
   */
  const REQUEST_ID_STRING_OFFSET = 25;
  /**
   * @var int the translation status, when an object is translated
   */
  const TRANSLATION_STATUS_FINISHED = 2;

  /**
   * Initializes the telelingua API class
   */
  public function load()
  {

  }

  /**
   * @return bool tells if the language given is supported
   */
  public function isSupportedLanguage($language)
  {
    // Generate the language list of not given
    $this->generateLanguageList();

    return in_array($language, $this->supportedLanguages);
  }

  /**
   * Generates the local supported language list
   */
  protected function generateLanguageList()
  {
    if (count($this->supportedLanguages) == 0) {
      $result = $this->requestApi('languages', array(), 'GET');
      foreach ($result as $languagePack) {
        $language = substr($languagePack['Code'], 0, 2);
        if (!in_array($language, $this->supportedLanguages) && strlen($language) == 2) {
          $this->supportedLanguages[] = $language;
        }
      }
    }
  }

  /**
   * @param \WP_Post $original the original post
   * @param string $source the source language
   * @param string $destination the destination language
   * @return int the result / translation request id
   */
  public function requestTranslation($original, $source, $destination)
  {
    // Fidget the request as telelingua needs it
    $request = array(
      'RequestName' => $original->post_title . ' (ID ' . $original->ID . ')',
      'DeadlineDate' => date('c', current_time('timestamp') + (7 * 86400)),
      'RequestStatus' => 'Submitted',
      'CustomFields' => array(),
      'RequestDetails' => array(array(
        'ContentItems' => array(
          array(
            'Key' => 'PostTitle',
            'Content' => $original->post_title
          ),
          array(
            'Key' => 'PostContent',
            'Content' => $original->post_content
          ),
          array(
            'Key' => 'PostExcerpt',
            'Content' => $original->post_excerpt
          ),
        ),
        'SourceLanguage' => array(
          'Code' => $this->translateLanguageCode($source),
        ),
        'TranslationTargets' => array(
          array(
            'TargetLanguage' => array(
              'Code' => $this->translateLanguageCode($destination)
            )
          )
        )
      ))
    );

    // Remove every field that has no content
    foreach (array('PostTitle', 'PostContent', 'PostExcerpt') as $field) {
      foreach ($request['RequestDetails'][0]['ContentItems'] as $index => $object) {
        if ($object['Key'] == $field && strlen($object['Content']) == 0) {
          unset($request['RequestDetails'][0]['ContentItems'][$index]);
        }
      }
    }

    // Send it to the api and get a result
    $result = $this->requestApi('TranslationRequests', $request, 'POST', true);
    return intval(substr($result['location'], self::REQUEST_ID_STRING_OFFSET));
  }

  /**
   * Receive and import translation into the obvious fields
   * @param int $postId the post id
   * @param int $translationId the translation id
   * @return bool true, if the translation actually worked
   */
  public function receiveTranslation($postId, $translationId)
  {
    $data = $this->requestApi('TranslationRequests/' . $translationId, array(), 'GET');
    $translations = $data['RequestDetails'][0]['TranslationTargets'][0];

    // Only import, if the translation is finished
    if ($translations['TranslationStatus'] ==  self::TRANSLATION_STATUS_FINISHED) {
      // Create an assoc array to easily work with the data
      $updatedPost = array(
        'ID' => $postId,
        'post_status' => $this->config['integration']['translation_status']
      );
      foreach ($translations['ContentItems'] as $item) {
        $key = $this->translateNormalizedKey($item['Key']);
        $updatedPost[$key] = $item['Content'];
      }

      // Now also override the post name with the new title if given
      if (isset($updatedPost['post_title'])) {
        $updatedPost['post_name'] = Strings::forceSlugString($updatedPost['post_title']);
      }

      // Save the post that way
      wp_update_post($updatedPost);
      return true;
    }

    // Not yet translated if we reach this point
    return false;
  }

  /**
   * @param string $key the normalized key from the api
   * @return string the local db key
   */
  protected function translateNormalizedKey($key)
  {
    switch ($key) {
      case 'PostTitle': return 'post_title';
      case 'PostContent': return 'post_content';
      case 'PostExcerpt': return 'post_excerpt';
    }

    return false;
  }

  /**
   * @param int $postId the post id
   * @param int $translationId the translation id
   * @return bool true|false depending on cancellation state
   */
  public function cancelTranslationRequest($postId, $translationId)
  {
    // TODO actually interpret the response, if given by telelingua
    // When developing this, the API doesn't actually give an answer, just http 200 ok
    $this->requestApi('TranslationRequests/' . $translationId, array(), 'DELETE');
    return true;
  }

  /**
   * @param string $code two char code
   * @return string telelingua iso code
   */
  protected function translateLanguageCode($code)
  {
    // If we have a mapping, translate the language code
    foreach ($this->config['service']['codeMapping'] as $source => $target) {
      if ($source == $code) {
        return $target;
      }
    }

    // If nothing was returned, assume same language and region code
    return $code . '-' . strtoupper($code);
  }

  /**
   * @param string $function the api function
   * @param array $data the api data to send
   * @param string $type http method
   * @param bool $headers receive headers
   * @return array result of the api call
   */
  protected function requestApi($function, $data, $type, $headers = false)
  {
    return json_decode(Strings::genericRequest(
      $this->config['service']['endpoint'] . '/api/' . $function,
      $data,
      $type,
      true,  // User json output
      false, // Don't use proxy
      $this->config['service']['username'],
      $this->config['service']['password'],
      $headers
    ), true);
  }
}