<?php

namespace LBWP\Util\Parser;

use LBWP\Util\File;
use LBWP\Util\Strings;

/**
 * Class Scaper
 * @package LBWP\Util\Parser
 * @author Michael Sebel <michael@comotive.ch>
 */
class Scraper
{
  private $url;
  private $html;
  private $minParagraphLength = 30;
  private $hostname = '';
  private $scheme = '';
  private $currentMainUrl = '';
  private $videoUrls = array();
  private $encodings = array(
    'UTF-8',
    'ISO-8859-1',
    'CP1252',
    'ASCII',
  );

  /**
   * Sets the URL of the remote website to be scraped.
   * @param string $url The URL of the remote website.
   */
  public function setUrl($url)
  {
    $this->url = $url;
    $this->fetchHtml();
    // Try finding problems with charset, if so, convert to utf-8
    // Convert to utf-8 encoding if not already in utf8
    $encoding = mb_detect_encoding($this->html, $this->encodings, true);
    if ($encoding != 'UTF-8') {
      $this->html = mb_convert_encoding($this->html, 'UTF-8', $encoding);
    }
    // Save a few infos about the url to be eventually used
    $info = parse_url($url);
    $this->hostname = $info['host'] ?? '';
    $this->scheme = $info['scheme'] ?? '';
    $this->currentMainUrl = $this->scheme . '://' . $this->hostname;
  }

  /**
   * Fetches the HTML content of the remote website.
   */
  private function fetchHtml()
  {
    // Do this with curl and use a common user agent
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $this->url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (X11; Ubuntu; Linux x86_64; rv:134.0) Gecko/20100101 Firefox/134.0');
    $this->html = curl_exec($ch);
    curl_close($ch);

    // If curl fails, try file_get_contents
    if (empty($this->html)) {
      $this->html = file_get_contents($this->url);
    }
  }

  /**
   * @param $url
   * @return bool
   */
  public function isYoutube($url)
  {
    return str_contains($url, 'youtube.com') || str_contains($url, 'youtu.be');
  }

  /**
   * @param $url
   * @return bool
   */
  public function isVimeo($url)
  {
    return str_contains($url, 'vimeo.com');
  }

  /**
   * @return false
   */
  public function loadYoutubeUrls()
  {
    // Look for iframes wich youtube.com/embed in the src
    $foundVideos = false;
    $dom = new \DOMDocument();
    @$dom->loadHTML($this->html);
    $xpath = new \DOMXPath($dom);
    $nodes = $xpath->query('//iframe');
    foreach ($nodes as $node) {
      $src = $node->getAttribute('src');
      if ($this->isYoutube($src)) {
        // Extract the video id from the last / to the first ?
        $videoId = explode('/', $src);
        $videoId = explode('?', end($videoId))[0];
        // Add to the video links
        $this->videoUrls['youtube'][] = 'https://www.youtube.com/watch?v=' . $videoId;
        $foundVideos = true;
      }
    }

    return $foundVideos;
  }

  public function loadVimeoUrls()
  {
    // https://player.vimeo.com/video/483786674?dnt=1&amp;app_id=122963
    // Look for iframes wich youtube.com/embed in the src
    $foundVideos = false;
    $dom = new \DOMDocument();
    @$dom->loadHTML($this->html);
    $xpath = new \DOMXPath($dom);
    $nodes = $xpath->query('//iframe');
    foreach ($nodes as $node) {
      $src = $node->getAttribute('src');
      if ($this->isVimeo($src)) {
        // Extract the video id from the last / to the first ?
        $videoId = explode('/', $src);
        $videoId = explode('?', end($videoId))[0];
        // Add to the video links
        $this->videoUrls['vimeo'][] = 'https://vimeo.com/' . $videoId;
        $foundVideos = true;
      }
    }

    return $foundVideos;
  }

  /**
   * @return array|mixed
   */
  public function getYoutubeUrls()
  {
    return $this->videoUrls['youtube'] ?? [];
  }

  /**
   * @return array|mixed
   */
  public function getVimeoUrls()
  {
    return $this->videoUrls['vimeo'] ?? [];
  }

  /**
   * Retrieves the content of the 'og:image' meta tag.
   * @return string|null The URL of the 'og:image' or null if not found.
   */
  public function getOgImage($fallback = false)
  {
    $imageUrl = $this->getMetaTagContent('og:image');
    // Remove get parameters eventually
    if (strlen($imageUrl) > 0 && str_contains($imageUrl, '?')) {
      $imageUrl = explode('?', $imageUrl)[0];
    }
    // fallback if allowed to one of the images
    if ($fallback && !Strings::checkURL($imageUrl)) {
      $imageUrl = $this->getAllImageUrls()[0] ?? '';
    }

    return $this->forceCleanUrl($imageUrl);
  }

  /**
   * @param string $imageUrl
   * @return string
   */
  protected function forceCleanUrl($imageUrl)
  {
    if (str_starts_with($imageUrl, '../')) {
      // First remove all ../
      $imageUrl = str_replace('../', '', $imageUrl);
      // Then add the main url
      return $this->currentMainUrl . '/' . $imageUrl;
    }
    if (str_starts_with($imageUrl, '/')) {
      return $this->currentMainUrl . $imageUrl;
    }

    return $imageUrl;
  }

  /**
   * Retrieves the content of the 'og:description' meta tag.
   * @return string|null The content of the 'og:description' or null if not found.
   */
  public function getOgDescription($fallback = false)
  {
    $description = $this->getMetaTagContent('og:description');
    // fallback if allowed to the first paragraph
    if ($fallback && strlen($description) < 50) {
      // Get the full text and reduce to sentences
      $description = str_replace(PHP_EOL, ' ', $this->getParagraphTextContent());
      if (strlen($description) > 10) {
        $description = Strings::chopToSentences($description, 200, 400);
      } else {
        $description = str_replace(PHP_EOL, '. ', $this->getAllText());
        $description = Strings::chopToSentences($description, 200, 400);
      }
    }

    // Maybe fix broken utf8 encoding
    if (str_contains($description, 'Ã¼') || str_contains($description, 'Ã¤') || str_contains($description, 'Ã¶')) {
      $description = utf8_decode($description);
    }

    return $description;
  }

  /**
   * Tries getting an AI summery from a given url
   * @param string $url
   * @param int $min minimum number of words
   * @param int $max maximum number of words
   * @return string
   */
  public function getAiSummary($url, $min, $max)
  {
    require_once ABSPATH . 'wp-content/plugins/lbwp/resources/libraries/openai-php/vendor/autoload.php';
    $client = \OpenAI::client(LBWP_AI_SEARCH_TEXT_INDEX_CHATGPT_SECRET);
    $prompt = '
      Ich konnte aus den Opengraph Daten einer Website keine sinnvolle Zusammenfassung des Inhaltes rauslesen.
      Bitte sieh dir folgendes HTML einer Website an und schreibe darüber eine Zusammenfassung in ' . $min .' bis ' . $max . ' Wörtern,
      alles am Stück in plain Text als Rückgabe. Schreibe es so, als ob es der Leadtext der Seite wäre: 
      ' . substr($this->html, 0, 180000)
    ;

    $response = $client->chat()->create([
      'model' => 'gpt-5-mini',
      'messages' => [
        ['role' => 'user', 'content' => $prompt],
      ],
    ]);

    return trim(strip_tags($response->choices[0]->message->content));
  }

  /**
   * Retrieves the content of the 'og:title' meta tag or the <title> tag if 'og:title' is not found
   * @return string|null The content of the 'og:title' or <title> tag, or null if not found.
   */
  public function getOgTitle()
  {
    $ogTitle = $this->getMetaTagContent('og:title');
    if ($ogTitle) {
      return $ogTitle;
    }
    return $this->getTitle();
  }

  /**
   * @return string
   */
  public function getParagraphText()
  {
    return $this->getParagraphTextContent();
  }

  /**
   * @param $url
   * @return void
   */
  public function setUrlSkipParse($url)
  {
    $this->url = $url;
  }

  /**
   * @param $html
   * @return void
   */
  public function setHtml($html)
  {
    $this->html = $html;
  }

  /**
   * @return string
   */
  public function getHtml()
  {
    return trim($this->html);
  }

  /**
   * Retrieves all the required data from the website.
   *
   * @return array An associative array containing the title, description, main image URL, all text, and all image URLs.
   */
  public function getAllData()
  {
    return [
      'url' => $this->url,
      'title' => $this->getWebsiteTitle(true),
      'description' => $this->getOgDescription(true),
      'mainimage' => $this->getOgImage(true),
      'text' => $this->getAllText(),
      'images' => $this->getAllImageUrls()
    ];
  }

  /**
   * Goes trough the content and tries finding all urls of files with given extension
   * @param string $extension for example ".pdf"
   * @return void
   */
  public function getFilesInContent($extension)
  {
    $dom = new \DOMDocument();
    @$dom->loadHTML($this->html);
    $xpath = new \DOMXPath($dom);
    $nodes = $xpath->query('//a');
    $files = [];
    foreach ($nodes as $node) {
      // Get href attribute of the a
      $url = $node->getAttribute('href');
      $title = $node->getAttribute('title');
      // Remove eventual url parameters from url if it contains the extension
      if (str_contains($url, $extension) && str_contains($url, '?')) {
        $url = explode('?', $url)[0];
      }
      // Also get the text content and trim
      $text = trim($node->textContent);
      if (str_ends_with($url, $extension)) {
        // USe title if given as text for the file
        if (strlen($title) > 0) {
          $text = $title;
        }
        // If it contains "", reduce to content within "", assuming only on
        if (str_contains($text, '"')) {
          $text = explode('"', $text)[1];
        }
        if (strlen($text) == 0) {
          $text = File::getFileOnly($url);
        }
        $files[$text] = $this->forceCleanUrl($url);
      }
    }

    return $files;
  }

  /**
   * Retrieves the title of the website.
   *
   * @return string|null The title of the website or null if not found.
   */
  public function getWebsiteTitle($corrections = false)
  {
    // Try getting to og:title
    $ogTitle = $this->getMetaTagContent('og:title');
    // if empty get the title of the document
    if (empty($ogTitle)) {
      $ogTitle = $this->getTitle();
    }
    // if still empty use the first found h1
    if (empty($ogTitle)) {
      $dom = new \DOMDocument();
      @$dom->loadHTML($this->html);
      $h1 = $dom->getElementsByTagName('h1')->item(0);
      $ogTitle = $h1?->textContent;
    }
    // Do some corrections if needed
    if ($corrections) {
      // If a » is to be found, cut at that point and trim
      if (str_contains($ogTitle, '»') && !str_ends_with($ogTitle, '»')) {
        $ogTitle = explode('»', $ogTitle)[0];
      }
      // Same with a pipe
      if (str_contains($ogTitle, '|')) {
        $ogTitle = explode('|', $ogTitle)[0];
      }
      // If title is longer than 20 chars, then also do it with a longdash
      if (strlen($ogTitle) > 20 && str_contains($ogTitle, '–')) {
        $ogTitle = explode('–', $ogTitle)[0];
      }
      // Or same with a short dash but 30 chars
      if (strlen($ogTitle) > 30 && str_contains($ogTitle, '-')) {
        $ogTitle = explode('-', $ogTitle)[0];
      }
    }
    return $ogTitle;
  }

  /**
   * Retrieves the content of a specified meta tag.
   *
   * @param string $property The property attribute of the meta tag.
   * @return string|null The content of the meta tag or null if not found.
   */
  private function getMetaTagContent($property)
  {
    $dom = new \DOMDocument();
    @$dom->loadHTML($this->html);
    $xpath = new \DOMXPath($dom);
    $metaTag = $xpath->query("//meta[@property='$property']")->item(0);
    return $metaTag ? $metaTag->getAttribute('content') : null;
  }

  /**
   * Retrieves the content of the <title> tag.
   *
   * @return string|null The content of the <title> tag or null if not found.
   */
  private function getTitle()
  {
    $dom = new \DOMDocument();
    @$dom->loadHTML($this->html);
    $titleTag = $dom->getElementsByTagName('title')->item(0);
    return $titleTag ? $titleTag->textContent : null;
  }

  /**
   * Retrieves all meaningful text from the website.
   *
   * @return string The concatenated text from h elements and paragraphs
   */
  public function getAllText()
  {
    $dom = new \DOMDocument();
    @$dom->loadHTML($this->html);
    $xpath = new \DOMXPath($dom);
    // Get all <h1>, <h2>, <h3>, <h4>, <h5>, <h6> and <p> elements
    $nodes = $xpath->query('//body//h1 | //body//h2 | //body//h3 | //body//h4 | //body//h5 | //body//h6 | //body//p');
    $text = '';
    foreach ($nodes as $node) {
      $text .= PHP_EOL . trim($node->textContent);
    }
    return trim($text);
  }

  /**
   * Retrieves all meaningful text from the website.
   *
   * @return string The concatenated text from h elements and paragraphs
   */
  private function getParagraphTextContent()
  {
    $dom = new \DOMDocument();
    @$dom->loadHTML($this->html);
    $xpath = new \DOMXPath($dom);
    // Get all <p> elements
    $nodes = $xpath->query('//body//p');
    $text = '';
    foreach ($nodes as $node) {
      if (strlen(trim($node->textContent)) > $this->minParagraphLength) {
        $text .= PHP_EOL . trim($node->textContent);
      }
    }
    return trim($text);
  }

  /**
   * Retrieves the URLs of all images on the website.
   *
   * @return array An array of image URLs.
   */
  public function getAllImageUrls()
  {
    $dom = new \DOMDocument();
    @$dom->loadHTML($this->html);
    $xpath = new \DOMXPath($dom);
    $removedImages = array();
    $imageNodes = $xpath->query('//img[@src]');
    $imageUrls = [];
    foreach ($imageNodes as $imageNode) {
      $candidate = $imageNode->getAttribute('src');
      $disallowedImage = apply_filters('lbwp_scraper_disallowed_image',
          str_ends_with($candidate, '.svg') ||
          str_contains($candidate, 'data:image') ||
          str_contains($candidate, 'typo3conf') ||
          str_contains($candidate, 'typo3temp') ||
          str_contains(strtolower($candidate), 'logo'),
        $candidate,
        $imageNode
      );
      if ($disallowedImage) {
        $removedImages[] = $candidate;
        continue;
      }
      $imageUrls[] = $this->forceCleanUrl($candidate);
    }
    // If no images are left, tage randomly one of the removed ones if given
    if (empty($imageUrls) && !empty($removedImages)) {
      $imageUrls[] = $this->forceCleanUrl($removedImages[array_rand($removedImages)]);
    }

    return $imageUrls;
  }
}