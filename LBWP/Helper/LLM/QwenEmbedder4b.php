<?php

namespace LBWP\Helper\LLM;

use CurlHandle;
use RuntimeException;

/**
 * Client for the OpenAI compatible embedding API on kirkland, running qwen3-embed-4b (2560 dimensions, normalized)
 * on the DGX. Needs LBWP_QWEN_EMBEDDER_4B_SERVICE_URL (e.g. https://embed.comotive.ch:8443/v1) and
 * LBWP_QWEN_EMBEDDER_4B_API_SECRET (the bearer key of the calling server).
 * @package LBWP\Helper\LLM
 * @author Michael Sebel <michael@comotive.ch>
 */
class QwenEmbedder4b implements Embedder
{
  const string MODEL = 'qwen3-embed-4b';
  const string COLLECTION_SUFFIX = '_qwen3_4b';
  /**
   * Qwen3 embeddings are asymmetric: queries need an instruction prefix, documents are embedded as they are
   */
  const string QUERY_INSTRUCTION = 'Given a web search query, retrieve relevant passages that answer the query';
  const int BATCH_SIZE = 16;
  /**
   * Shared over all instances (per endpoint and key), so the TLS connection is kept alive for the whole PHP process
   * @var CurlHandle[]
   */
  private static array $curlHandles = [];
  private string $baseUrl;
  private string $apiSecret;
  private int $timeout;

  /**
   * @param string $baseUrl the API base url without /embeddings
   * @param string $apiSecret the bearer key
   * @param int $timeout request timeout in seconds
   */
  public function __construct(string $baseUrl = '', string $apiSecret = '', int $timeout = 30)
  {
    if ($baseUrl === '' && defined('LBWP_QWEN_EMBEDDER_4B_SERVICE_URL')) {
      $baseUrl = LBWP_QWEN_EMBEDDER_4B_SERVICE_URL;
    }
    if ($apiSecret === '' && defined('LBWP_QWEN_EMBEDDER_4B_API_SECRET')) {
      $apiSecret = LBWP_QWEN_EMBEDDER_4B_API_SECRET;
    }

    $this->baseUrl = rtrim($baseUrl, '/');
    $this->apiSecret = $apiSecret;
    $this->timeout = $timeout;
  }

  /**
   * The API has no health endpoint, so a tiny embedding is requested and the result cached for five minutes
   * @return bool true if the service responds with a vector
   */
  public function isWorking(): bool
  {
    $cacheKey = 'QwenEmbedder4b_health_' . md5($this->baseUrl);
    $isWorking = wp_cache_get($cacheKey);
    if ($isWorking !== false) {
      return (bool) $isWorking;
    }

    try {
      $isWorking = count($this->embed('health')) > 0;
    } catch (RuntimeException $e) {
      $isWorking = false;
    }

    wp_cache_set($cacheKey, $isWorking ? 1 : 0, '', 300);
    return $isWorking;
  }

  /**
   * @param string $text the text to embed
   * @param bool $queryMode true for search queries (adds the instruction prefix), false for documents
   * @return array the 2560 dimensional embedding vector
   */
  public function embed(string $text, bool $queryMode = false): array
  {
    if ($queryMode) {
      $text = 'Instruct: ' . self::QUERY_INSTRUCTION . "\nQuery: " . $text;
    }

    return $this->request([$text])[0] ?? [];
  }

  /**
   * @param array $texts document texts to embed
   * @return array embedding vectors in the same order as the texts
   */
  public function embedMultiple(array $texts): array
  {
    $embeddings = [];
    foreach (array_chunk(array_values($texts), self::BATCH_SIZE) as $chunk) {
      array_push($embeddings, ...$this->request($chunk));
    }

    return $embeddings;
  }

  /**
   * @return string the collection suffix of this embedder
   */
  public function getCollectionSuffix(): string
  {
    return self::COLLECTION_SUFFIX;
  }

  /**
   * Sends texts to the embeddings endpoint
   * @param array $texts list of strings
   * @return array embedding vectors sorted by their input index
   */
  private function request(array $texts): array
  {
    $curl = $this->getCurlHandle();
    curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode([
      'model' => self::MODEL,
      'input' => $texts
    ]));

    $response = curl_exec($curl);
    $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $error = curl_error($curl);

    if ($error) {
      throw new RuntimeException("Qwen embedding service request failed: {$error}");
    }
    if ($httpCode >= 400) {
      throw new RuntimeException("Qwen embedding service returned error {$httpCode}: " . substr($response, 0, 500));
    }

    $decoded = json_decode($response, true);
    if (!is_array($decoded) || !isset($decoded['data'])) {
      throw new RuntimeException('Failed to decode Qwen embedding service response: ' . json_last_error_msg());
    }

    $embeddings = [];
    foreach ($decoded['data'] as $item) {
      $embeddings[intval($item['index'])] = $item['embedding'];
    }
    ksort($embeddings);

    return array_values($embeddings);
  }

  /**
   * Returns the shared curl handle, configured once to reuse the connection
   * @return CurlHandle the handle
   */
  private function getCurlHandle(): CurlHandle
  {
    $handleKey = md5($this->baseUrl . $this->apiSecret);
    if (isset(self::$curlHandles[$handleKey])) {
      return self::$curlHandles[$handleKey];
    }

    $curl = curl_init($this->baseUrl . '/embeddings');
    curl_setopt($curl, CURLOPT_POST, true);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_TIMEOUT, $this->timeout);
    curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($curl, CURLOPT_TCP_KEEPALIVE, 1);
    curl_setopt($curl, CURLOPT_HTTPHEADER, [
      'Content-Type: application/json',
      'Authorization: Bearer ' . $this->apiSecret
    ]);

    if (defined('LOCAL_DEVELOPMENT')) {
      curl_setopt($curl, CURLOPT_PROXY, 'http://194.182.165.126');
      curl_setopt($curl, CURLOPT_PROXYPORT, '3128');
      curl_setopt($curl, CURLOPT_PROXYUSERPWD, LBWP_SQUID_PROXY_AUTH);
    }

    self::$curlHandles[$handleKey] = $curl;
    return $curl;
  }
}
