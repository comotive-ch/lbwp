<?php

namespace LBWP\Theme\Component;

use LBWP\Helper\Cronjob;
use LBWP\Module\Backend\MemcachedAdmin;
use LBWP\Module\Frontend\HTMLCache;
use LBWP\Module\General\Cms\UsageBasedBilling;
use LBWP\Util\ElevenLabs;
use LBWP\Util\Strings;
use LBWP\Util\WordPress;

/**
 * Generate audio from post or any content, uses elevenlabs
 * @package LBWP\Theme\Component
 * @author Michael Sebel <michael@comotive.ch>
 */
class AudioGenerator extends ACFBase
{
  /**
   * @var array post types that support audio generation
   */
  protected $contentPostTypes = array('post');
  /**
   * @var array post types that support audio generation
   */
  protected $arrayPostTypes = array();
  /**
   * @var string can be overridden in child class if not given by LBWP_AI_AUDIO_GENERATOR_API_KEY config
   */
  protected $apiKey = '';
  /**
   * @var string the standard german voice id (matilda)
   */
  protected $voiceId = 'XrExE9yKIg1WjnnlVkGX';
  /**
   * @var string[] by default, skip all acf blocks for now
   */
  protected $skipBlocksRegex = array(
    '/<!-- wp:acf\/.*?\/-->/'
  );

  protected $listElementNatural = array(
    1 => 'Erstens: ',
    2 => 'Zweitens: ',
    3 => 'Drittens: ',
    4 => 'Viertens: ',
    5 => 'Fünftens: ',
    6 => 'Sechstens: ',
    7 => 'Siebtens: ',
    8 => 'Achtens: ',
    9 => 'Neuntens: ',
    10 => 'Zehntens: ',
    11 => 'Elftens: ',
    12 => 'Zwölftens: '
  );
  /**
   * @var array Can be overridden or filtered
   */
  protected $voiceSettings = array();

  public function setup()
  {
    if (defined('LBWP_AI_AUDIO_GENERATOR_API_KEY')) {
      $this->apiKey = LBWP_AI_AUDIO_GENERATOR_API_KEY;
    }
    if (defined('LBWP_AI_AUDIO_GENERATOR_VOICE_ID')) {
      $this->voiceId = LBWP_AI_AUDIO_GENERATOR_VOICE_ID;
    }

    parent::setup();
  }

  /**
   * Register all needed types and filters to control access
   */
  public function init()
  {
    // Add a meta box to show the audio generation options
    add_action('add_meta_boxes', array($this, 'addMetaBox'));
    // Register cron and run the actual AI generation in background
    add_action('wp_ajax_register_audio_background_cron', array($this, 'registerAudioBackgroundCron'));
    add_action('wp_ajax_count_real_content_words', array($this, 'getCountRealContentWords'));
    add_action('wp_ajax_check_audio_generation_status', array($this, 'checkAudioGenerationStatus'));
    add_action('cron_job_lbwp_generate_ai_audio', array($this, 'generateAudioFromPost'));
  }

  /**
   * @return void
   */
  public function registerAudioBackgroundCron()
  {
    $postId = intval($_POST['postId']);
    if (isset($_POST['index'])) {
      $postId .= ';' . intval($_POST['index']);
    }
    Cronjob::register(array(
      current_time('timestamp') => 'lbwp_generate_ai_audio::' . $postId
    ));
    WordPress::sendJsonResponse(array('success' => true));
  }

  /**
   * Count real content words to be used for audio to estimate the price
   * @return void
   */
  public function getCountRealContentWords()
  {
    $postId = intval($_POST['postId']);
    $post = get_post($postId);

    if (!$post instanceof \WP_Post) {
      return;
    }

    $content = $this->getReadablePostContent($post);
    if (is_array($content)) {
      $content = implode(' ', $content);
    }

    // Return word count of content
    WordPress::sendJsonResponse(array('success' => true, 'wordCount' => str_word_count($content)));
  }

  /**
   * Check current audio generation status
   * @return void
   */
  public function checkAudioGenerationStatus()
  {
    $postId = intval($_POST['postId']);
    $post = get_post($postId);

    if (!$post instanceof \WP_Post) {
      WordPress::sendJsonResponse(array('success' => false, 'message' => 'Post not found'));
      return;
    }

    $status = get_post_meta($post->ID, 'lbwp_ai_audio_generation_status', true);
    WordPress::sendJsonResponse(array(
      'success' => true, 
      'status' => $status
    ));
  }

  /**
   * @return void
   */
  public function generateAudioFromPost()
  {
    list($postId, $singleIndex) = explode(';', $_GET['data']);
    $postId = intval($postId);
    $singleIndex = ($singleIndex !== null) ? intval($singleIndex) : false;
    $post = get_post($postId);
    if (!$post instanceof \WP_Post || get_post_meta($postId, 'lbwp_ai_audio_generation_status', true) === 'running') {
      return;
    }

    // Get the content to generate audio from
    $content = $this->getReadablePostContent($post);
    $isArrayType = is_array($content);
    $wordCount = $this->getWordCountFromContent($content);
    // On single index, only get the word count of that part
    if ($isArrayType && $singleIndex !== false && $singleIndex >= 0) {
      $wordCount = str_word_count($content[$singleIndex]);
    }
    // Set the timelimit so we have 0.5s per word
    set_time_limit($wordCount / 2);
    ini_set('memory_limit', '1024M');

    // Track the costs for the audio generation
    UsageBasedBilling::addAiAudioUsage($post->post_title, $content, $wordCount);
    // Actually generate the audio
    $api = new ElevenLabs($this->apiKey);
    $api->setVoiceSettings($this->voiceSettings);
    update_post_meta($postId, 'lbwp_ai_audio_generation_status', 'running');

    if ($isArrayType) {
      if ($singleIndex !== false && $singleIndex >= 0) {
        // Only update one part of the array, first get current data
        $currentFiles = get_post_meta($postId, 'lbwp_ai_audio_file_url', true);
        $currentWhisper = get_post_meta($postId, 'lbwp_ai_audio_whisper', true);
        $currentTimestamps = get_post_meta($postId, 'lbwp_ai_audio_timestap', true);
        // Now only generate the $content with that index
        $part = $content[$singleIndex];
        $api->generateAudio($part, $this->voiceId, true);
        $currentFiles[$singleIndex] = $api->moveAudioToBlockStorage();
        $currentTimestamps[$singleIndex] = $api->getTimestamps();
        // Technically we're doing array, but the whisper is a single dimensional array
        $api->convertToWhisperTimestamps(false);
        $currentWhisper[$singleIndex] = $api->getTimestamps();
        // Now update the current data
        update_post_meta($postId, 'lbwp_ai_audio_file_url', $currentFiles);
        update_post_meta($postId, 'lbwp_ai_audio_whisper', $currentWhisper);
        update_post_meta($postId, 'lbwp_ai_audio_timestap', $currentTimestamps);
      } else {
        $files = $timestamps = $whisper = array();
        foreach ($content as $part) {
          $api->generateAudio($part, $this->voiceId, true);
          $files[] = $api->moveAudioToBlockStorage();
          $timestamps[] = $api->getTimestamps();
          // Technically we're doing array, but the whisper is a single dimensional array
          $api->convertToWhisperTimestamps(false);
          $whisper[] = $api->getTimestamps();
        }
        // Save all data to the post
        update_post_meta($postId, 'lbwp_ai_audio_file_url', $files);
        update_post_meta($postId, 'lbwp_ai_audio_timestap', $timestamps);
        update_post_meta($postId, 'lbwp_ai_audio_whisper', $whisper);
      }
    } else {
      // Single dimensional content
      $api->generateAudio($content, $this->voiceId, true);
      // Save all data to the post
      update_post_meta($postId, 'lbwp_ai_audio_file_url', $api->moveAudioToBlockStorage());
      update_post_meta($postId, 'lbwp_ai_audio_timestap', $api->getTimestamps());
      $api->convertToWhisperTimestamps(false);
      update_post_meta($postId, 'lbwp_ai_audio_whisper', $api->getTimestamps());
    }

    // Set as finished
    update_post_meta($postId, 'lbwp_ai_audio_generation_status', 'finished');
    // Flush frontend cache of that post
    HTMLCache::cleanPostHtmlCache($postId);
  }

  /**
   * @param $post
   * @return string|array
   */
  protected function getReadablePostContent($post)
  {
    $isArrayType = in_array($post->post_type, $this->arrayPostTypes);

    if ($isArrayType) {
      // Get the content array via a filter, a dev always has to specify it
      $contents = apply_filters('lbwp_ai_audio_array_content_start_type_' . $post->post_type, $post);
      // Run the start filter for each content
      foreach ($contents as $index => $content) {
        $contents[$index] = apply_filters('lbwp_ai_audio_content_start_type_' . $post->post_type, $content, $post);
      }
    } else {
      $contents = array(apply_filters('lbwp_ai_audio_content_start_type_' . $post->post_type, $post->post_content, $post));
    }

    foreach ($contents as $index => $content) {
      // Skip blocks that shouldnt be read aloud (basically all acf at the moment)
      foreach ($this->skipBlocksRegex as $skipRegex) {
        $content = preg_replace($skipRegex, '', $content);
      }
      // Use the real time content, which is full of html
      $content = apply_filters('the_content', $content);
      // Add title in front (do it after the_content, as that filter can change the title)
      if (!$isArrayType) {
        $content = apply_filters('the_title', $post->post_title) . '.' . PHP_EOL . $content;
      }
      // Make sure to read ol/li as actual numbered lists
      $content = $this->handleNumberedLists($content);
      // Remove <figcaption> and its contents from the html
      $content = preg_replace('/<figcaption[^>]*>.*?<\/figcaption>/s', '', $content);
      // Remove <code> block and its contents from the html
      $content = preg_replace('/<code[^>]*>.*?<\/code>/s', '', $content);
      // Remove all other html tags
      $content = strip_tags($content);
      // Also make sure to replace some abbreviations
      $content = str_replace(array('z.&#8239;B.', 'z.b.', 'z.B.', 'zB.','z. B.','z. b.'), 'zum Beispiel', $content);
      $content = str_replace(array('d.&#8239;h.', 'd.h.', 'd. h.'), 'das heisst', $content);
      $content = str_replace(array('= ', '== '), 'gleich ', $content);
      // Bring it back into the array
      $contents[$index] = apply_filters('lbwp_ai_audio_content_end_type_' . $post->post_type, $content, $post);;
    }

    if ($isArrayType) {
      return $contents;
    } else {
      return $contents[0];
    }
  }

  /**
   * @param $post
   * @return array|int|string[]
   */
  protected function getWordCountFromArrayType($post)
  {
    $contents = $this->getReadablePostContent($post);
    // Get total word count of all contents
    $wordCount = 0;
    foreach ($contents as $content) {
      $wordCount += str_word_count($content);
    }

    return $wordCount;
  }

  /**
   * @param string|string[] $content
   * @return int
   */
  protected function getWordCountFromContent($content)
  {
    if (!is_array($content)) {
      $content = array($content);
    }

    $wordCount = 0;
    foreach ($content as $part) {
      $wordCount += str_word_count($part);
    }

    return $wordCount;
  }

  /**
   * @param $content
   * @return string
   */
  public function handleNumberedLists($content)
  {
    // Find all <ol>
    preg_match_all('/<ol[^>]*>.*?<\/ol>/s', $content, $matches);
    if (is_array($matches[0])) {
      foreach ($matches[0] as $match) {
        // Find all <li>
        preg_match_all('/<li[^>]*>.*?<\/li>/s', $match, $liMatches);
        if (is_array($liMatches[0])) {
          $counter = 0;
          foreach ($liMatches[0] as $liMatch) {
            // Replace <li> with a number
            $content = str_replace($liMatch, $this->listElementNatural[++$counter] . strip_tags($liMatch), $content);
          }
        }
      }
    }

    return $content;
  }

  /**
   * Add the meta box to the post edit screen
   */
  public function addMetaBox()
  {
    $types = array_merge($this->contentPostTypes, $this->arrayPostTypes);
    foreach ($types as $postType) {
      add_meta_box(
        'lbwp-audio-generator',
        __('Audio Generator', 'lbwp'),
        array($this, 'renderMetaBox'),
        $postType,
        'side',
        'high'
      );
    }
  }

  /**
   * Render the meta box
   */
  public function renderMetaBox()
  {
    $post = get_post(intval($_GET['post']));
    $audioUrl = get_post_meta($post->ID, 'lbwp_ai_audio_file_url', true);
    $isGenerated = (is_array($audioUrl) && count($audioUrl) > 0) || Strings::checkURL($audioUrl);
    $status = get_post_meta($post->ID, 'lbwp_ai_audio_generation_status', true);
    $tariff = UsageBasedBilling::getTariff('ai_audio');
    $isArrayType = in_array($post->post_type, $this->arrayPostTypes);
    if ($isArrayType) {
      $wordCount = $this->getWordCountFromArrayType($post);
    }

    // Display status information
    $statusHtml = '<div id="lbwp-audio-status"></div>';
    if ($status === 'running') {
      $statusHtml = '<div id="lbwp-audio-status"><p>Audio wird noch erzeugt ...</p></div>';
    }

    if ($isGenerated) {
      $audioHtml = '';
      if (is_array($audioUrl)) {
        foreach ($audioUrl as $index => $url) {
          $audioHtml .= '
            <audio controls style="width:80%;display:inline-block;margin-right:10px;">
              <source src="' . $url . '" type="audio/mpeg">
            </audio>
            <a href="javascript:void(0);" class="dashicons dashicons-image-rotate lbwp-create-ai-audio" data-index="' . $index . '"></a>
          ';
        }
      } else {
        $audioHtml = '<audio controls><source src="' . $audioUrl . '" type="audio/mpeg"></audio>';
      }

      echo '
        <div id="lbwp-audio-content">
          ' . $audioHtml . '
          <p>Sofern sich der Inhalt wesentlich geändert hat, kannst du das Audio erneut erzeugen. Kostenschätzung <span class="tariff-calculation">0.00</span> CHF,
          auf Basis <span class="tariff-base">' . number_format($tariff['per_chunk'], 2) . '</span> CHF pro <span class="tariff-words">' . $tariff['words'] . '</span> Wörter.</p>
          <a href="javascript:void(0);" class="button button-secondary lbwp-create-ai-audio" data-costs="">Jetzt ' . ($isArrayType ? 'alle ' : '') . 'erneut erzeugen</a>
          ' . $statusHtml .  '
        </div>
      ';
    } else {
      echo '
        <div id="lbwp-audio-content">
          <p>Bisher kein Vorlese-Audio erzeugt. Kostenschätzung <span class="tariff-calculation">0.00</span> CHF,
          auf Basis <span class="tariff-base">' . number_format($tariff['per_chunk'], 2) . '</span> CHF pro <span class="tariff-words">' . $tariff['words'] . '</span> Wörter.</p>
          <a href="javascript:void(0);" class="button button-secondary lbwp-create-ai-audio" data-costs="">Jetzt erzeugen</a>
          ' . $statusHtml .  '
        </div>
      ';
    }

    // Minimal scripts for price calc and audio generation script starter
    echo '
      <script>
        var lbwpAudioGenerationArrayType = ' . ($isArrayType ? 'true' : 'false') . ';
        jQuery(document).ready(function() {
          jQuery(".lbwp-create-ai-audio").click(function() {
            var button = jQuery(this);
            var index = button.attr("data-index");
            var data = {
              action: "register_audio_background_cron",
              postId: ' . $post->ID . '
            };
            if (typeof(index) !== "undefined") {
              data.index = index;
              if (!confirm("Soll die Audio-Datei von Eintrag " + (parseInt(index)+1) + " neu erzeugt werden?")) {
                return;
              }
            }
            // Register the audio generation background cron
            jQuery.post(ajaxurl, data);
            // Start status polling
            if (!lbwpStatusPollingInterval) {
              var statusDiv = jQuery("#lbwp-audio-status");
              statusDiv.html(\'<p>Audio-Erzeugung gestartet!</p>\');
              lbwpStatusPollingInterval = setInterval(lbwpCheckAudioGenerationStatus, 30000);
            }
            // Remove the button
            button.remove();
          });
          
          function lbwpCalculateAudioGenerationPrice() {
            // Use word count ajax call
            jQuery.post(ajaxurl, {
              action: "count_real_content_words",
              postId: ' . $post->ID . '
            }, function(response) {
              if (response.success) {
                lbwpSetAudioGenerationPrice(response.wordCount)
              }
            });
          }
          
          function lbwpSetAudioGenerationPrice(wordCount) {
            // Get basics for our calculation
            const base = parseFloat(jQuery(".tariff-base").text());
            const words = parseInt(jQuery(".tariff-words").text());
            // Calculate price
            const price = (wordCount / words) * base;
            // Put into calculation span, and format to 2 decimals
            jQuery(".tariff-calculation").text(price.toFixed(2));
            jQuery(".lbwp-create-ai-audio").attr("data-costs", price.toFixed(2));
          }
          
          // Status polling functionality
          var lbwpStatusPollingInterval;
          
          function lbwpCheckAudioGenerationStatus() {
            jQuery.post(ajaxurl, {
              action: "check_audio_generation_status",
              postId: ' . $post->ID . '
            }, function(response) {
              if (response.success) {
                var statusDiv = jQuery("#lbwp-audio-status");
                // If status changed from what we initially loaded
                if (response.status === "finished") {
                  statusDiv.html(\'<p>Audio-Erzeugung abgeschlossen!</p>\');
                  // Stop polling and reload page to show new audio
                  clearInterval(lbwpStatusPollingInterval);
                } else {
                  statusDiv.html(\'<p>Audio-Erzeugung läuft noch...</p>\');
                }
              }
            });
          }
          
          // Start polling if status is running
          if ("' . $status . '" === "running") {
            lbwpStatusPollingInterval = setInterval(lbwpCheckAudioGenerationStatus, 30000);
          }
         
          setTimeout(function() {
            lbwpCalculateAudioGenerationPrice();
          }, 2000);
        });
      </script>
      <style>
        #lbwp-audio-status {
          margin-top: 8px;
        }
      </style>
    ';
  }

  /**
   * Adds markers to the html elements containing text, including timestamps when they start in the audio
   * @param string $html
   * @param array $segments
   * @param int $start
   * @return string $html
   */
  public static function addTimedMarkers($html, $segments, $position = 1, $omit = '')
  {
    // Are there segments we need to omit?
    if (strlen($omit) > 0) {
      $segments = self::calculateNearestSegments($segments, $omit);
    }

    // Load the HTML into a DOMDocument
    $dom = new \DOMDocument();
    @$dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
    // Get all elements and iterate over them
    $xpath = new \DOMXPath($dom);
    $nodeList = $xpath->query('//h1 | //h2 | //h3 | //h4 | //h5 | //p | //li');

    foreach ($nodeList as $element) {
      // Skip if the element has an image in it
      if ($element->getElementsByTagName('img')->length > 0) {
        continue;
      }

      // Work trough
      $text = $element->textContent;
      $segments = self::calculateNearestSegments($segments, $text);
      $start = $segments[0]['start'];

      // Add class and data attributes
      $currentClass = $element->getAttribute('class');
      $newClass = (empty($currentClass) ? '' : $currentClass . ' ') . 'markable-segment';
      $element->setAttribute('class', $newClass);
      $element->setAttribute('data-mark-start', $start);
      $element->setAttribute('data-mark-segment', $position++);
      // Remove the segment we just used as to not use it anymore if two segments start identically
      unset($segments[0]);
      $segments = array_values($segments);
    }

    // Extract only the body content
    $bodyContent = '';
    foreach ($dom->getElementsByTagName('body')->item(0)->childNodes as $child) {
      $bodyContent .= $dom->saveHTML($child);
    }
    
    return $bodyContent;
  }

  /**
   * @param $string
   * @return string
   */
  public static function normalizeString($string)
  {
    // Allow only spaces and characters from a-ZA-Z
    $string = preg_replace('/[^a-zA-ZüöäÜÖÄ\s]/', '', $string);
    $string = strtolower($string);
    return $string;
  }

  /**
   * @param $segments
   * @param $string
   * @return array
   */
  public static function calculateNearestSegments($segments, $string)
  {
    $highestSimilarity = $highestSimilarityIndex =0;
    $string = self::normalizeString($string);
    // Find the best matching start segment
    foreach ($segments as $index => $segment) {
      $similarityStart = 0;
      $candidate = self::normalizeString($segment['text']);
      similar_text(substr($string,0,strlen($candidate)), $candidate, $similarityStart);
      // Search no more if very good match
      if ($index == 0 &&$similarityStart >= 97) {
        $highestSimilarityIndex = $index;
        break;
      }
      // If not go for the highest similarity
      if ($similarityStart > $highestSimilarity) {
        $highestSimilarityIndex = $index;
        $highestSimilarity = $similarityStart;
      }
    }

    // Remove all indexes before the start and rebase the array
    for ($i = 0; $i < $highestSimilarityIndex; $i++) {
      unset($segments[$i]);
    }
    // Rebuild the array index so it starts from 0 again
    return array_values($segments);
  }

  /**
   * Adds field settings
   */
  public function fields()  {}

  /**
   * Registers no own blocks
   */
  public function blocks() {}
} 