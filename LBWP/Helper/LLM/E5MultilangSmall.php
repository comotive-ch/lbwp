<?php

namespace LBWP\Helper\LLM;

/**
 * Client for Embedding Service uses LLM E5-Multilang-Small model
 * Converts text to embeddings via HTTP API very fast, runs on CPU/RAM on assist locally
 * @package LBWP\Helper\LLM
 * @author Michael Sebel <michael@comotive.ch>
 */
class E5MultilangSmall
{
  private string $baseUrl;
  private int $timeout;

  /**
   * @param string $baseUrl
   * @param int $timeout
   */
  public function __construct(string $baseUrl = LBWP_MINILM_L6V2_EMBEDDING_SERVICE_URL, int $timeout = 10)
  {
    $this->baseUrl = rtrim($baseUrl, '/');
    $this->timeout = $timeout;
  }

  /**
   * Check if ChromaDB is working by calling heartbeat endpoint
   * Uses WordPress cache for 5 minutes to avoid frequent calls
   * @return bool True if ChromaDB is responding, false otherwise
   */
  public function isWorking(): bool
  {
    $cacheKey = 'E5MultilangSmall_health_' . md5($this->baseUrl);
    $isWorking = wp_cache_get($cacheKey);

    if ($isWorking !== false) {
      return $isWorking;
    }

    try {
      $this->request('GET', '/health');
      $isWorking = true;
    } catch (\RuntimeException $e) {
      $isWorking = false;
    }

    wp_cache_set($cacheKey, $isWorking, '', 300); // Cache for 5 minutes (300 seconds)
    return $isWorking;
  }

  /**
   * Generate embedding for single text
   *
   * @param string $text Text to embed
   * @param bool $queryMode True for search queries, false for documents (default)
   * @return array Embedding vector (384 dimensions)
   */
  public function embed(string $text, bool $queryMode = false): array
  {
    $response = $this->request('POST', '/embed', [
      'text' => $text,
      'query_mode' => $queryMode
    ]);

    return $response['embedding'] ?? [];
  }

  /**
   * Generate embeddings for multiple texts
   *
   * @param array $texts Array of texts to embed
   * @return array Array of embedding vectors
   */
  public function embedMultiple(array $texts): array
  {
    if (empty($texts)) {
      return [];
    }

    $response = $this->request('POST', '/embed', [
      'texts' => $texts
    ]);

    return $response['embeddings'] ?? [];
  }

  /**
   * Generate embeddings in batch (optimized for large amounts)
   *
   * @param array $texts Array of texts to embed
   * @param int $batchSize Batch size for processing (default: 32)
   * @return array Array of embedding vectors
   */
  public function batchEmbed(array $texts, int $batchSize = 32): array
  {
    if (empty($texts)) {
      return [];
    }

    $response = $this->request('POST', '/batch-embed', [
      'texts' => $texts,
      'batch_size' => $batchSize
    ]);

    return $response['embeddings'] ?? [];
  }

  /**
   * Make HTTP request to embedding service
   */
  private function request(string $method, string $path, ?array $data = null): array
  {
    $url = $this->baseUrl . $path;

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $this->timeout);
    $headers = ['Content-Type: application/json'];
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    // On local dev go trough our orpxy
    if (defined('LOCAL_DEVELOPMENT')) {
      curl_setopt($ch, CURLOPT_PROXY, 'http://194.182.165.126');
      curl_setopt($ch, CURLOPT_PROXYPORT, '3128');
      curl_setopt($ch, CURLOPT_PROXYUSERPWD, 'comotive:Kv8gnr9qd5erSquid');
    }

    if ($data !== null) {
      curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    }

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
      throw new \RuntimeException("Embedding service request failed: {$error}");
    }

    if ($httpCode >= 400) {
      throw new \RuntimeException("Embedding service returned error {$httpCode}: {$response}");
    }

    $decoded = json_decode($response, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
      throw new \RuntimeException("Failed to decode embedding service response: " . json_last_error_msg());
    }

    return $decoded ?? [];
  }
}